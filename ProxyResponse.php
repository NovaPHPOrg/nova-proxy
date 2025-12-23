<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;
use nova\framework\http\Response;

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
        $this->contentRewriter = new ContentRewriter($proxyPrefix, $uri);
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

    /** @param callable (string $rawRequest, array $urlInfo): array $cb */
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
            $request = $this->requestBuilder->build($urlInfo);

            // 请求拦截器
            if ($this->requestInterceptor) {
                [$request, $earlyBody] = ($this->requestInterceptor)($request, $urlInfo);
                if ($earlyBody !== '') {
                    echo $earlyBody;
                    flush();
                    return;
                }
            }

            Logger::debug("-> Proxying Request:\n" . $request);

            $this->sendRequest($socket, $request);
            $this->receiveAndProcessResponse($socket);
        } finally {
            $this->closeConnection($socket);
        }
    }

    /* ------------------------------------------------------------------ */
    /*                        Request building                            */
    /* ------------------------------------------------------------------ */

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

    private function sendRequest($socket, string $req): void
    {
        Logger::debug("-> Sending Request:\n" . $req);
        fwrite($socket, $req);
    }

    /* ------------------------------------------------------------------ */
    /*                        Response processing                         */
    /* ------------------------------------------------------------------ */

    private function receiveAndProcessResponse($socket): void
    {
        [$headers, $body] = $this->responseReader->read($socket);

        $body = $this->processResponseBody($body, $headers);

        $this->sendResponseToClient($headers, $body);
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

    private function rejectWebSocket(): void
    {
        header('HTTP/1.1 501 Not Implemented');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'WebSocket is not supported by this proxy.';
        flush();
    }
}
