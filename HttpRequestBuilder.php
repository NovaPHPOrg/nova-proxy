<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * HTTP 请求构建器
 * 职责：将 PHP 请求转换为原始 HTTP 请求字符串
 */
class HttpRequestBuilder
{
    public function build(array $urlInfo): string
    {
        $headers = $this->buildHeaders($urlInfo['host']);
        $body    = file_get_contents('php://input');
        $uri     = $urlInfo['path'] . $urlInfo['query'];

        return sprintf(
            "%s %s HTTP/1.1\r\n%sConnection: close\r\n\r\n%s",
            $_SERVER['REQUEST_METHOD'],
            $uri,
            $headers,
            $body
        );
    }

    private function buildHeaders(string $host): string
    {
        $out = "Host: $host\r\n";

        foreach ($_SERVER as $k => $v) {
            if (!str_starts_with($k, 'HTTP_') || $k === 'HTTP_HOST') {
                continue;
            }

            $headerName = str_replace('_', '-', substr($k, 5));

            // 强制只接受 gzip，PHP 没有内置 brotli 解码
            if ($headerName === 'ACCEPT-ENCODING') {
                $out .= "Accept-Encoding: gzip, deflate\r\n";
                continue;
            }

            $out .= "$headerName: $v\r\n";
        }

        return $out;
    }
}
