<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use function nova\framework\dump;

/**
 * HTTP 请求构建器
 * 职责：将 PHP 请求转换为原始 HTTP 请求字符串
 */
class HttpRequestBuilder
{
    /**
     * 构建请求头部分（不含请求体）
     */
    public function buildHeader(array $urlInfo,string $proxyPrefix): string
    {
        $headers = $this->buildHeaders(empty($proxyPrefix)?'':$urlInfo['host']);
        $uri     = $urlInfo['path'] . $urlInfo['query'];

        // 传递原始 Content-Length 和 Content-Type
        $extra = '';
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $extra .= "Content-Type: {$_SERVER['CONTENT_TYPE']}\r\n";
        }
        if (!empty($_SERVER['CONTENT_LENGTH'])) {
            $extra .= "Content-Length: {$_SERVER['CONTENT_LENGTH']}\r\n";
        }


        return sprintf(
            "%s %s HTTP/1.1\r\n%s%sConnection: close\r\n\r\n",
            $_SERVER['REQUEST_METHOD'],
            $uri,
            $headers,
            $extra
        );
    }

    /**
     * 流式转发请求体到目标 socket
     */
    public function streamBody($socket): void
    {
        $input = fopen('php://input', 'rb');
        if ($input === false) {
            return;
        }
        try {
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($socket, $chunk);
                }
            }
        } finally {
            fclose($input);
        }
    }

    private function buildHeaders(string $host): string
    {
        $out = "";

        $useHost = !empty($host);
        if ($useHost) {
            $out .= "Host: {$host}\r\n";
        }

        foreach ($_SERVER as $k => $v) {
            if (!str_starts_with($k, 'HTTP_') ) {
                continue;
            }

            if ($useHost && $k === 'HTTP_HOST') {
                continue; // 已经单独处理 Host 头，避免重复
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
