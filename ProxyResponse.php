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
        $this->requestBuilder  = new HttpRequestBuilder($proxyPrefix, $this->extractOrigin($fullPath));
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
            $isFileUpload  = $this->isFileUpload();

            // 对于文件上传尝试读取原始 php://input；如果为空且 PHP 已解析了 multipart（$_FILES 存在），
            // 则从 globals 重建 multipart 流并计算长度，以便构造正确的请求头
            $multipart = null;
            $rawInput = null;
            if ($isFileUpload) {
                $rawInput = @file_get_contents('php://input');
                if ($rawInput === false || $rawInput === '') {
                    $multipart = $this->requestBuilder->buildMultipartBodyFromGlobals();
                }
            }

            if ($multipart !== null) {
                // 使用重建后的 Content-Type/Length
                $extra = ['Content-Length' => $multipart['length'], 'Content-Type' => $multipart['content_type']];
                $requestHeader = $this->requestBuilder->buildHeader($urlInfo, $extra);
                $requestBodyForInterceptor = null;
            } else {
                $requestHeader = $this->requestBuilder->buildHeader($urlInfo);
                // 请求拦截器需要原始 body（文件上传传 null）
                $requestBodyForInterceptor = $isFileUpload ? null : ($rawInput ?? @file_get_contents('php://input'));
            }

            // 请求拦截器
            if ($this->requestInterceptor) {
                [$requestHeader, $earlyBody] = ($this->requestInterceptor)($requestHeader, $urlInfo, $requestBodyForInterceptor);
                if ($earlyBody !== '') {
                    echo $earlyBody;
                    flush();
                    return;
                }
            }

            Logger::debug("-> Proxying Request Header:\n" . $requestHeader);

            fwrite($socket, $requestHeader);

            if ($multipart !== null) {
                Logger::debug("-> Proxying Request Body (reconstructed multipart) length=" . $multipart['length']);
                $stream = $multipart['stream'];
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk !== false && $chunk !== '') {
                            fwrite($socket, $chunk);
                        }
                    }
                } finally {
                    fclose($stream);
                }
            } elseif (!$isFileUpload && isset($requestBodyForInterceptor)) {
                Logger::debug("-> Proxying Request Body (non-file upload, read into memory):\n" . $requestBodyForInterceptor);
                // 非文件上传：body 已读入内存，直接写入
                if ($requestBodyForInterceptor !== '') {
                    fwrite($socket, $requestBodyForInterceptor);
                }
            } else {
                Logger::debug("-> Proxying Request Body (file upload or no interceptor, streaming):\n");
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

        stream_set_timeout($sock, $this->timeout);

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

        // 仅 HTML 需要注入（返回按钮 / 调试工具）；勿因 injector 缓冲图片等二进制
        $needBuffer = $headers['needsRewrite']
            || ($this->responseInjector !== null && !empty($headers['html']));
        if (!$needBuffer) {
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
        // 先拆 chunked，再解压。顺序反了会把 gzip 当 HTML，浏览器直接乱码。
        if ($headers['chunked']) {
            $body = $this->responseReader->decodeChunked($body);
        }

        if ($headers['encoding'] !== '') {
            $decoded = $this->responseReader->decode($body, $headers['encoding']);
            if ($decoded === null) {
                throw new ProxyException('Failed to decode Content-Encoding: ' . $headers['encoding']);
            }
            $body = $decoded;
        }

        $contentType = $this->getContentType($headers['lines']);
        if (self::isTextual($contentType)) {
            $charset = CharsetNormalizer::detect($contentType, $body);
            if ($charset !== 'UTF-8') {
                $body = CharsetNormalizer::toUtf8($body, $charset);
                CharsetNormalizer::applyUtf8Headers($headers['lines']);
                if (str_contains(strtolower($contentType), 'text/html')) {
                    $body = CharsetNormalizer::applyUtf8Meta($body);
                }
            }
        }

        $this->contentRewriter->setCurrentPath($this->currentPath);
        $body = $this->contentRewriter->rewrite($body, $contentType);

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

    private static function isTextual(string $contentType): bool
    {
        $ct = strtolower($contentType);
        return str_starts_with($ct, 'text/')
            || str_contains($ct, 'json')
            || str_contains($ct, 'javascript')
            || str_contains($ct, 'xml');
    }

    private function sendResponseToClient(array $headers, string $body): void
    {
        // 禁止 PHP 再压一层；否则 Content-Length/Encoding 全错，页面直接乱码
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', '0');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 重写后的 body 已是明文。禁止再标 Content-Encoding:gzip，
        // 否则叠加 PHP zlib.output_compression 会双重压缩 → 浏览器乱码。
        foreach ($headers['lines'] as $line) {
            if (stripos($line, 'Content-Encoding:') === 0) {
                continue;
            }
            if (stripos($line, 'Content-Length:') === 0) {
                continue;
            }
            if (stripos($line, 'Transfer-Encoding:') === 0) {
                continue;
            }

            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                header('Location: ' . $this->urlRewriter->rewriteLocation($location));
            } elseif (stripos($line, 'Set-Cookie:') === 0) {
                header($this->urlRewriter->rewriteCookie($line), false);
            } else {
                header($line);
            }
        }

        header_remove('Content-Encoding');
        header('Content-Length: ' . strlen($body), true);

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

    private function extractOrigin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = $parts['host'];
        $port = $parts['port'] ?? null;
        $portStr = '';
        if ($port !== null) {
            $defaultPort = $scheme === 'https' ? 443 : 80;
            if ((int)$port !== $defaultPort) {
                $portStr = ':' . $port;
            }
        }

        return $scheme . '://' . $host . $portStr;
    }
}
