<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * HTTP 响应读取器
 * 职责：从 socket 读取并解码 HTTP 响应
 */
class HttpResponseReader
{
    private const int READ_BYTES = 8192;

    /**
     * 读取完整的 HTTP 响应（缓冲模式，用于需要重写的内容）
     * @return array [headers: array, body: string]
     */
    public function read($socket): array
    {
        $headers = $this->readHeaders($socket);
        $body    = $this->readBody($socket, $headers);

        return [$headers, $body];
    }

    /**
     * 仅读取响应头（不读取 body），用于决定流式还是缓冲模式
     */
    public function readHeadersOnly($socket): array
    {
        return $this->readHeaders($socket);
    }

    /**
     * 在已读取头部后，读取响应体（缓冲模式）
     * @return array [headers: array, body: string]
     */
    public function readBuffered($socket, array $headers): array
    {
        $body = $this->readBody($socket, $headers);
        return [$headers, $body];
    }

    /**
     * 流式转发响应体到客户端（用于不需要重写的二进制内容）
     * 不缓冲整个 body，直接输出
     */
    public function streamToClient($socket, array $headers): void
    {
        // 发送原始响应头（由调用方处理 Location/Cookie 重写后调用此方法）
        // 对于流式传输，保留原始编码，不解压/重压

        if ($headers['chunked']) {
            // chunked 模式：直接透传 chunk
            header('Transfer-Encoding: chunked');
            while (!feof($socket)) {
                $chunk = fread($socket, self::READ_BYTES);
                if ($chunk !== false && $chunk !== '') {
                    echo $chunk;
                    flush();
                }
            }
        } elseif ($headers['contentLength'] >= 0) {
            // 已知长度：直接透传
            header('Content-Length: ' . $headers['contentLength']);
            $remaining = $headers['contentLength'];
            while ($remaining > 0 && !feof($socket)) {
                $toRead = min($remaining, self::READ_BYTES);
                $chunk = fread($socket, $toRead);
                if ($chunk !== false && $chunk !== '') {
                    echo $chunk;
                    flush();
                    $remaining -= strlen($chunk);
                }
            }
        } else {
            // 未知长度：读到连接关闭
            while (!feof($socket)) {
                $chunk = fread($socket, self::READ_BYTES);
                if ($chunk !== false && $chunk !== '') {
                    echo $chunk;
                    flush();
                }
            }
        }
    }

    /**
     * 读取响应头
     * @return array ['lines' => [...], 'chunked' => bool, 'encoding' => string, 'html' => bool, 'contentLength' => int, 'needsRewrite' => bool]
     */
    private function readHeaders($socket): array
    {
        $lines    = [];
        $chunked  = false;
        $encoding = '';
        $html     = false;
        $needsRewrite = false;
        $contentLength = -1;

        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false || $line === "\r\n") {
                break;
            }

            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            if (stripos($trim, 'Transfer-Encoding:') === 0 &&
                stripos($trim, 'chunked') !== false) {
                $chunked = true;
                continue;
            }

            if (stripos($trim, 'Content-Encoding:') === 0) {
                $value = strtolower(trim(substr($trim, 17)));
                if (str_contains($value, 'gzip')) {
                    $encoding = 'gzip';
                } elseif (str_contains($value, 'deflate')) {
                    $encoding = 'deflate';
                }
                // 不 continue，保留到 lines 中供流式模式使用
            }

            if (stripos($trim, 'Content-Type:') === 0) {
                $ct = strtolower($trim);
                if (preg_match('#\btext/html\b#i', $ct)) {
                    $html = true;
                    $needsRewrite = true;
                } elseif (str_contains($ct, 'text/css') || str_contains($ct, 'application/css')) {
                    $needsRewrite = true;
                }
            }

            if (stripos($trim, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr($trim, 15));
                continue; // 跳过，稍后重新计算（缓冲模式）或透传（流式模式）
            }

            $lines[] = $trim;
        }

        return [
            'lines'         => $lines,
            'chunked'       => $chunked,
            'encoding'      => $encoding,
            'html'          => $html,
            'contentLength' => $contentLength,
            'needsRewrite'  => $needsRewrite,
        ];
    }

    private function readBody($socket, array $headers): string
    {
        $body = '';
        if ($headers['contentLength'] >= 0 && !$headers['chunked']) {
            // 已知长度，精确读取
            $remaining = $headers['contentLength'];
            while ($remaining > 0 && !feof($socket)) {
                $chunk = fread($socket, min($remaining, self::READ_BYTES));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
        } else {
            while (!feof($socket)) {
                $body .= fread($socket, self::READ_BYTES);
            }
        }
        return $body;
    }

    /**
     * 解码 chunked 传输编码
     */
    public function decodeChunked(string $data): string
    {
        $out = '';
        while ($data !== '') {
            $pos = strpos($data, "\r\n");
            if ($pos === false) {
                break;
            }

            $lenHex = trim(substr($data, 0, $pos));
            $len    = hexdec($lenHex);

            if ($len === 0) {
                break;
            }

            $out  .= substr($data, $pos + 2, $len);
            $data  = substr($data, $pos + 2 + $len + 2);
        }
        return $out;
    }

    /**
     * 解压内容（gzip/deflate）
     */
    public function decode(string $data, string $encoding): string
    {
        if ($encoding === 'gzip') {
            $decoded = @gzdecode($data);
            return $decoded !== false ? $decoded : $data;
        }

        if ($encoding === 'deflate') {
            // deflate 可能是 raw deflate 或 zlib wrapped
            $decoded = @gzinflate($data);
            if ($decoded === false) {
                $decoded = @gzuncompress($data);
            }
            return $decoded !== false ? $decoded : $data;
        }

        return $data;
    }

    /**
     * 压缩为 gzip
     */
    public function encodeGzip(string $data): string
    {
        return gzencode($data);
    }
}
