<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;
use nova\framework\http\Response;

class ProxyResponse extends Response
{
    private string $uri;
    private array $socketConfig;
    private string $path = "";

    /** @var callable|null (string $rawRequest, array $urlInfo): array */
    private $requestInterceptor = null;

    /** @var callable|null (string $respBody, array $respHeaders, string $path): string */
    private $responseInjector = null;

    /** @var callable|null (\Exception $exception): void */
    private $errorHandler = null;
    public const int READ_BYTES = 4096;
    private int $timeout = 30;

    public function __construct(string $uri)
    {
        parent::__construct();

        $this->uri = $uri;
        $this->socketConfig = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ];
    }

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

    /**
     * @param callable (string $rawRequest, array $urlInfo): array $callback
     * @return ProxyResponse
     */
    public function setRequestInterceptor(callable $callback): self
    {
        $this->requestInterceptor = $callback;
        return $this;
    }

    /**
     * @param callable (string $respBody, array $respHeaders, string $path): string  $callback
     * @return $this
     */
    public function setResponseInjector(callable $callback): self
    {
        $this->responseInjector = $callback;
        return $this;
    }


    /**
     * @throws ProxyException
     */
    public function send(): void
    {
        try {
            $this->forwardRequest($this->uri);
        } catch (\Throwable $exception) {
            if ($this->errorHandler) {
                call_user_func($this->errorHandler, $exception);
            } else {
                throw $exception;
            }
        }
    }

    /**
     * @throws ProxyException
     */
    private function forwardRequest(string $targetUri): void
    {
        $urlInfo = $this->parseAndValidateUrl($targetUri);
        $socket  = $this->createConnection($urlInfo);

        try {
            // 构建原始请求
            $request = $this->buildRequest($urlInfo);

            // 请求拦截器
            if ($this->requestInterceptor) {
                [$request,$body] = call_user_func($this->requestInterceptor, $request, $urlInfo);
                if(!empty($body)){
                    echo $body;
                    flush();
                    return;
                }
            }

            Logger::info("-> Proxying Request:\n" . $request);
            $this->sendRequest($socket, $request);
            $this->receiveResponse($socket);
        } finally {
            $this->closeConnection($socket);
        }
    }

    /**
     * @throws ProxyException
     */
    private function parseAndValidateUrl(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new ProxyException("Invalid URL: $url");
        }
        $this->path = $parsed['path'] ?? '/';

        return [
            'scheme' => strtolower($parsed['scheme']),
            'host'   => $parsed['host'],
            'port'   => $parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80),
            'path'   => $this->path,
            'query'  => isset($parsed['query']) ? '?' . $parsed['query'] : '',
        ];
    }

    /**
     * @throws ProxyException
     */
    private function createConnection(array $urlInfo)
    {
        $context = stream_context_create($this->socketConfig);
        $connStr = ($urlInfo['scheme'] === 'https' ? 'ssl://' : 'tcp://') .
            $urlInfo['host'] . ':' . $urlInfo['port'];

        $socket = stream_socket_client(
            $connStr,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new ProxyException("Connection failed: $errstr ($errno)");
        }

        return $socket;
    }

    private function buildRequest(array $urlInfo): string
    {
        $headers    = $this->getRequestHeaders($urlInfo['host']);
        $body       = file_get_contents('php://input');
        $requestUri = $urlInfo['path'] . $urlInfo['query'];

        return sprintf(
            "%s %s HTTP/1.1\r\n%sConnection: close\r\n\r\n%s",
            $_SERVER['REQUEST_METHOD'],
            $requestUri,
            $headers,
            $body
        );
    }

    private function getRequestHeaders(string $host): string
    {
        $hdrs = "Host: $host\r\n";
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_HOST') {
                $name = str_replace('_', '-', substr($k, 5));
                $hdrs .= "$name: $v\r\n";
            }
        }
        return $hdrs;
    }

    private function sendRequest($socket, string $request): void
    {
        fwrite($socket, $request);
    }

    private function receiveResponse($socket): void
    {
        // 读取并透传响应头
        $headerDone = false;
        $responseHeaders = [];

        while (!$headerDone && !feof($socket)) {
            $line = fgets($socket);
            if ($line === "\r\n") {
                $headerDone = true;
            } else {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    header($trimmed);
                    $responseHeaders[] = $trimmed;
                }
            }
        }

        // 读取响应体
        $body = '';
        while (!feof($socket)) {
            $body .= fread($socket, self::READ_BYTES);
        }

        // 响应注入
        if ($this->responseInjector) {
            $body = call_user_func(
                $this->responseInjector,
                $body,
                $responseHeaders,
                $this->path
            );
        }

        echo $body;
        flush();
    }

    private function closeConnection($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
