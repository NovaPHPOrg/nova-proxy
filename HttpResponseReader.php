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
    private const int STREAM_BYTES = 65536; // 64KB for streaming large files

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
        // 关闭 PHP 输出缓冲，减少延迟
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        stream_set_timeout($socket, 30);

        if ($headers['chunked']) {
            header('Transfer-Encoding: chunked');
        } elseif ($headers['contentLength'] >= 0) {
            header('Content-Length: ' . $headers['contentLength']);
        }

        $remaining = $headers['contentLength']; // -1 表示未知长度
        $bufferSize = 0;

        while (!feof($socket)) {
            if ($remaining === 0) {
                break;
            }
            $toRead = ($remaining > 0) ? min($remaining, self::STREAM_BYTES) : self::STREAM_BYTES;
            $chunk = fread($socket, $toRead);
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $len = strlen($chunk);
            if ($remaining > 0) {
                $remaining -= $len;
            }
            $bufferSize += $len;
            if ($bufferSize >= 262144) {
                flush();
                $bufferSize = 0;
            }
        }

        if ($bufferSize > 0) {
            flush();
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
     * 解压内容（gzip/deflate）。
     * 失败返回 null，禁止把压缩字节当 HTML 往下传（那就是乱码源头）。
     */
    public function decode(string $data, string $encoding): ?string
    {
        if ($encoding === 'gzip') {
            $decoded = @gzdecode($data);
            return $decoded !== false ? $decoded : null;
        }

        if ($encoding === 'deflate') {
            $decoded = @gzinflate($data);
            if ($decoded === false) {
                $decoded = @gzuncompress($data);
            }
            return $decoded !== false ? $decoded : null;
        }

        return $data;
    }
}
