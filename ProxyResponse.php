<?php
/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

namespace nova\plugin\proxy;

use nova\framework\log\Logger;
use nova\framework\request\Response;
use function nova\framework\dump;

class ProxyResponse extends Response
{
    private string $uri;
    private array $socketConfig;
    /**
     * @var callable $responseBodyHandler
     */
    private $responseBodyHandler = null;
    private array $responseBodyUris = [];
    /**
     * @var callable $responseHandler
     */
    private $responseHandler = null;

    const int READ_BYTES = 4096;
    /**
     * @var callable $errorHandler
     */
    private $errorHandler = null;

    private int $timeout = 30;

    function setTimeout($timeout)
    {
        $this->timeout = $timeout;
        return $this;
    }


    function setErrorHandler(callable $err)
    {
        $this->errorHandler = $err;
        return $this;
    }

    function setResponseBodyHandler(callable $callback,array $uris = [])
    {
        $this->responseBodyHandler = $callback;
        $this->responseBodyUris = $uris;
        return $this;
    }

    function setResponseHandler(callable $handler)
    {
        $this->responseHandler = $handler;
        return $this;
    }

    public function __construct(string $uri)
    {
        $this->uri = $uri;
        $this->socketConfig = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        parent::__construct();
    }

    /**
     * @throws ProxyException
     */
    public function send(): void
    {
        try {
            $this->forwardRequest($this->uri);
        } catch (\Exception $exception) {
            if ($this->errorHandler && is_callable($this->errorHandler)) {
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
        $socket = $this->createConnection($urlInfo);

        try {
            $request = $this->buildRequest($urlInfo);
            Logger::info("request:" . $request);
            $this->sendRequest($socket, $request);
            $this->receiveResponse($socket);
        } finally {
            $this->closeConnection($socket);
        }
    }

    private string $path = "";

    /**
     * @throws ProxyException
     */
    private function parseAndValidateUrl(string $url): array
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            throw new ProxyException("无效的URL格式：$url");
        }

        $this->path = $parsedUrl['path'] ?? '/';

        return [
            'scheme' => strtolower($parsedUrl['scheme']),
            'host' => $parsedUrl['host'],
            'port' => $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80),
            'path' =>  $this->path,
            'query' => isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '',
        ];
    }

    /**
     * @throws ProxyException
     */
    private function createConnection(array $urlInfo)
    {
        $context = stream_context_create($this->socketConfig);
        $connectionString = ($urlInfo['scheme'] === 'https' ? 'ssl://' : 'tcp://') .
            $urlInfo['host'] . ':' . $urlInfo['port'];

        $socket = stream_socket_client(
            $connectionString,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new ProxyException("连接失败: $errstr ($errno)");
        }

        return $socket;
    }

    private function buildRequest(array $urlInfo): string
    {
        $headers = $this->getRequestHeaders($urlInfo['host']);
        $body = file_get_contents('php://input');
        $targetPath = $urlInfo['path'] . $urlInfo['query'];

        return sprintf(
            "%s %s HTTP/1.1\r\n%sConnection: close\r\n\r\n%s",
            $_SERVER['REQUEST_METHOD'],
            $targetPath,
            $headers,
            $body
        );
    }

    private function getRequestHeaders(string $host): string
    {
        $headers = "Host: $host\r\n";
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && $key !== 'HTTP_HOST') {
                $headerKey = str_replace('_', '-', substr($key, 5));
                $headers .= "$headerKey: $value\r\n";
            }
        }
        return $headers;
    }

    private function sendRequest($socket, string $request): void
    {
        fwrite($socket, $request);
    }

    private function receiveResponse($socket): void
    {
        // 读取并处理响应头
        $headerComplete = false;
        $responseHeaders = [];

        while (!$headerComplete && !feof($socket)) {
            $line = fgets($socket);
            if ($line === "\r\n") {
                $headerComplete = true;
            } else {
                if (!empty(trim($line))) {
                    $responseHeaders[] = trim($line);
                    header(trim($line));
                }
            }
        }

        // 调用响应处理器
        if ($this->responseHandler && is_callable($this->responseHandler)) {
            call_user_func($this->responseHandler, $responseHeaders);
        }

        $body = "";

        $isResponseBodyHandler =
            $this->responseBodyHandler &&
            is_callable($this->responseBodyHandler) &&
            $this->responseBodyUris;
        if ($isResponseBodyHandler){
            $isResponseBodyHandler = false;
            foreach ($this->responseBodyUris as $uri){
                if (str_contains($this->path,$uri)){
                    $isResponseBodyHandler = true;
                    break;
                }
            }
        }

        // 直接将响应体输出到浏览器
        while (!feof($socket)) {
            $data = fread($socket, ProxyResponse::READ_BYTES);
            if ($isResponseBodyHandler){
                $body .= $data;
            }else{
                echo $data;
                flush();
            }
        }

        Logger::info($body);

        if ($isResponseBodyHandler){
            echo call_user_func($this->responseBodyHandler, $body,$this->path);
            flush();
        }
    }


    private function closeConnection($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
