<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;
use nova\framework\http\Response;

/**
 * 代理响应处理器。
 *
 * 负责将客户端请求转发到目标站点，读取并处理目标响应，
 * 再将重写后的头部与内容回传给客户端。
 */
class ProxyResponse extends Response
{
    private string $uri;
    private string $fullPath;
    private string $proxyPrefix;
    private string $currentPath = '';

    private array  $socketConfig;
    private int    $timeout = 30;

    private HttpRequestBuilder $requestBuilder;
    private HttpResponseReader $responseReader;
    private ProxyUrlRewriter   $urlRewriter;
    private ContentRewriter    $contentRewriter;

    /** @var callable|null (string $rawRequest, array $urlInfo): array */
    private $requestInterceptor = null;

    /** @var callable|null (string $respBody, array $respHeaders, string $path): string */
    private $responseInjector  = null;

    /** @var callable|null (\Throwable $exception): void */
    private $errorHandler      = null;

    public function __construct(string $uri, string $fullPath, string $proxyPrefix = '')
    {
        parent::__construct();

        $this->uri         = $uri;
        $this->fullPath    = $fullPath;
        $this->proxyPrefix = $proxyPrefix;

        $this->socketConfig = [
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];

        // 初始化协作类
        $this->requestBuilder  = new HttpRequestBuilder();
        $this->responseReader  = new HttpResponseReader();
        $this->urlRewriter     = new ProxyUrlRewriter($uri, $fullPath, $proxyPrefix);
        $this->contentRewriter = new ContentRewriter($proxyPrefix, $uri, $fullPath);
    }

    /* ------------------------------------------------------------------ */
    /*               Public API (chain-style setters)                     */
    /* ------------------------------------------------------------------ */

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setErrorHandler(callable $err): self
    {
        $this->errorHandler = $err;
        return $this;
    }

    /** @param callable (string $requestHeader, array $urlInfo, ?string $requestBody): array $cb — $requestBody 为 null 表示文件上传（body 未读取） */
    public function setRequestInterceptor(callable $cb): self
    {
        $this->requestInterceptor = $cb;
        return $this;
    }

    /** @param callable (string $body, array $headers, string $path): string $cb */
    public function setResponseInjector(callable $cb): self
    {
        $this->responseInjector = $cb;
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*                               Entry                                */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ProxyException
     * @throws \Throwable
     */
    public function send(): void
    {
        try {
            if ($this->isWebSocketRequest()) {
                $this->rejectWebSocket();
                return;
            }

            $this->forwardRequest($this->uri);
        } catch (\Throwable $e) {
            if ($this->errorHandler) {
                ($this->errorHandler)($e);
            } else {
                throw $e;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*                          Core forwarding                           */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ProxyException
     */
    private function forwardRequest(string $targetUri): void
    {
        $urlInfo = $this->parseAndValidateUrl($targetUri);
        $socket  = $this->createConnection($urlInfo);

        try {
            $requestHeader = $this->requestBuilder->buildHeader($urlInfo);
            $isFileUpload  = $this->isFileUpload();

            // 请求拦截器
            if ($this->requestInterceptor) {
                // 文件上传时不读取 body，避免 OOM；非文件上传时读取 body 供拦截器判断
                $requestBody = $isFileUpload ? null : file_get_contents('php://input');
                [$requestHeader, $earlyBody] = ($this->requestInterceptor)($requestHeader, $urlInfo, $requestBody);
                if ($earlyBody !== '') {
                    echo $earlyBody;
                    flush();
                    return;
                }
            }

            Logger::debug("-> Proxying Request Header:\n" . $requestHeader);

            fwrite($socket, $requestHeader);

            if (!$isFileUpload && isset($requestBody)) {
                // 非文件上传：body 已读入内存，直接写入
                if ($requestBody !== '') {
                    fwrite($socket, $requestBody);
                }
            } else {
                // 文件上传或无拦截器：流式转发请求体
                $this->requestBuilder->streamBody($socket);
            }

            $this->receiveAndProcessResponse($socket);
        } finally {
            $this->closeConnection($socket);
        }
    }

    /* ------------------------------------------------------------------ */
    /*                        Request building                            */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ProxyException
     */
    private function parseAndValidateUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new ProxyException("Invalid URL: $url");
        }

        $this->currentPath = $p['path'] ?? '/';

        return [
            'scheme' => strtolower($p['scheme']),
            'host'   => $p['host'],
            'port'   => $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80),
            'path'   => $this->currentPath,
            'query'  => isset($p['query']) ? '?' . $p['query'] : '',
        ];
    }

    /**
     * @throws ProxyException
     */
    private function createConnection(array $u)
    {
        $ctx = stream_context_create($this->socketConfig);
        $dsn = ($u['scheme'] === 'https' ? 'ssl://' : 'tcp://')
            . $u['host'] . ':' . $u['port'];

        $sock = stream_socket_client(
            $dsn,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (!$sock) {
            throw new ProxyException("Connection failed: $errstr ($errno)");
        }

        return $sock;
    }

    /* ------------------------------------------------------------------ */
    /*                        Response processing                         */
    /* ------------------------------------------------------------------ */

    private function receiveAndProcessResponse($socket): void
    {
        // 先只读取响应头
        $headersResult = $this->responseReader->readHeadersOnly($socket);
        $headers = $headersResult;

        // 判断是否需要内容重写（仅 text/html、text/css 需要）
        if (!$headers['needsRewrite'] && !$this->responseInjector) {
            // 流式模式：直接转发响应头和响应体，不缓冲
            $this->sendStreamHeaders($headers);
            $this->responseReader->streamToClient($socket, $headers);
            return;
        }

        // 缓冲模式：需要重写内容
        [$headers, $body] = $this->responseReader->readBuffered($socket, $headers);
        $body = $this->processResponseBody($body, $headers);
        $this->sendResponseToClient($headers, $body);
    }

    /**
     * 流式模式下发送响应头（重写 Location 和 Cookie，其他透传）
     */
    private function sendStreamHeaders(array $headers): void
    {
        foreach ($headers['lines'] as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                header('Location: ' . $this->urlRewriter->rewriteLocation($location));
            } elseif (stripos($line, 'Set-Cookie:') === 0) {
                header($this->urlRewriter->rewriteCookie($line), false);
            } else {
                header($line);
            }
        }
    }

    private function processResponseBody(string $body, array $headers): string
    {
        // 解码 chunked
        if ($headers['chunked']) {
            $body = $this->responseReader->decodeChunked($body);
        }

        // 解压 gzip/deflate
        if ($headers['encoding'] !== '') {
            $body = $this->responseReader->decode($body, $headers['encoding']);
        }

        // 重写内容中的链接
        $contentType = $this->getContentType($headers['lines']);
        $this->contentRewriter->setCurrentPath($this->currentPath);
        $body = $this->contentRewriter->rewrite($body, $contentType);

        // 自定义注入
        if ($this->responseInjector) {
            $body = ($this->responseInjector)($body, $headers['lines'], $this->currentPath);
        }

        return $body;
    }

    private function getContentType(array $headerLines): string
    {
        foreach ($headerLines as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                return trim(substr($line, 13));
            }
        }
        return 'text/plain';
    }

    private function sendResponseToClient(array $headers, string $body): void
    {
        // 发送响应头（重写 Location 和 Cookie）
        foreach ($headers['lines'] as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                $newLocation = $this->urlRewriter->rewriteLocation($location);
                header('Location: ' . $newLocation);
            } elseif (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = $this->urlRewriter->rewriteCookie($line);
                header($cookie, false);
            } else {
                header($line);
            }
        }

        // 重新压缩为 gzip（统一输出格式）
        if ($headers['encoding'] !== '') {
            $body = $this->responseReader->encodeGzip($body);
            header('Content-Encoding: gzip', true);
        } else {
            header_remove('Content-Encoding');
        }

        // 发送正确的长度
        header('Content-Length: ' . strlen($body), true);

        // 输出
        echo $body;
        flush();
    }

    private function closeConnection($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    /* ------------------------------------------------------------------ */
    /*                         Utility helpers                            */
    /* ------------------------------------------------------------------ */

    private function isWebSocketRequest(): bool
    {
        $isUpgrade = isset($_SERVER['HTTP_UPGRADE'])
            && strcasecmp($_SERVER['HTTP_UPGRADE'], 'websocket') === 0;
        $hasConn   = isset($_SERVER['HTTP_CONNECTION'])
            && stripos($_SERVER['HTTP_CONNECTION'], 'upgrade') !== false;

        $scheme = strtolower(parse_url($this->uri, PHP_URL_SCHEME) ?: '');
        $isWsScheme = in_array($scheme, ['ws', 'wss'], true);

        return ($isUpgrade && $hasConn) || $isWsScheme;
    }

    /**
     * 检测当前请求是否为文件上传
     * 判断依据：multipart/form-data 或 Content-Length 超过阈值（2MB）
     */
    private function isFileUpload(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'multipart/form-data') !== false) {
            return true;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 2 * 1024 * 1024) {
            return true;
        }

        return false;
    }

    private function rejectWebSocket(): void
    {
        header('HTTP/1.1 501 Not Implemented');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'WebSocket is not supported by this proxy.';
        flush();
    }
}
