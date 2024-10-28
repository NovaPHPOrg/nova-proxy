<?php

namespace nova\plugin\proxy;

use nova\framework\log\Logger;
use nova\framework\request\Response;

class ProxyResponse extends Response
{
    private string $uri;
    private array $socketConfig;
    
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
        $this->forwardRequest($this->uri);
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
            Logger::warning("request:".$request);
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
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            throw new ProxyException("无效的URL格式");
        }

        return [
            'scheme' => strtolower($parsedUrl['scheme']),
            'host' => $parsedUrl['host'],
            'port' => $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80),
            'path' => $parsedUrl['path'] ?? '/',
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
            30,
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
        $header = '';
        
        while (!$headerComplete && !feof($socket)) {
            $line = fgets($socket);
            if ($line === "\r\n") {
                $headerComplete = true;
            } else {
                $header .= $line;
                if (!empty(trim($line))) {
                    header(trim($line));
                }
            }
        }

        // 直接将响应体输出到浏览器
        while (!feof($socket)) {
            echo fread($socket, 8192);
            flush();
        }
    }

    private function handleResponse(string $response): void
    {
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        foreach (explode("\r\n", $header) as $line) {
            if (!empty($line)) {
                header($line);
            }
        }
        echo $body;
    }

    private function closeConnection($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
