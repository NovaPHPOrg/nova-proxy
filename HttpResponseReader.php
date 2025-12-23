<?php
declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * HTTP 响应读取器
 * 职责：从 socket 读取并解码 HTTP 响应
 */
class HttpResponseReader
{
    private const int READ_BYTES = 4096;

    /**
     * 读取完整的 HTTP 响应
     * @return array [headers: array, body: string]
     */
    public function read($socket): array
    {
        $headers = $this->readHeaders($socket);
        $body    = $this->readBody($socket);
        
        return [$headers, $body];
    }

    /**
     * 读取响应头
     * @return array ['lines' => [...], 'chunked' => bool, 'encoding' => string, 'html' => bool]
     */
    private function readHeaders($socket): array
    {
        $lines    = [];
        $chunked  = false;
        $encoding = '';  // gzip, deflate, 或空
        $html     = false;

        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false || $line === "\r\n") {
                break;
            }

            $trim = trim($line);
            if ($trim === '') continue;

            // 检测传输编码
            if (stripos($trim, 'Transfer-Encoding:') === 0 && 
                stripos($trim, 'chunked') !== false) {
                $chunked = true;
                continue;
            }

            // 检测内容编码（gzip/deflate）
            if (stripos($trim, 'Content-Encoding:') === 0) {
                $value = strtolower(trim(substr($trim, 17)));
                if (str_contains($value, 'gzip')) {
                    $encoding = 'gzip';
                } elseif (str_contains($value, 'deflate')) {
                    $encoding = 'deflate';
                }
                continue;
            }

            // 检测内容类型
            if (stripos($trim, 'Content-Type:') === 0) {
                if (preg_match('#\btext/html\b#i', $trim)) {
                    $html = true;
                }
            }

            // 跳过 Content-Length，稍后重新计算
            if (stripos($trim, 'Content-Length:') === 0) {
                continue;
            }

            $lines[] = $trim;
        }

        return [
            'lines'    => $lines,
            'chunked'  => $chunked,
            'encoding' => $encoding,
            'html'     => $html,
        ];
    }

    private function readBody($socket): string
    {
        $body = '';
        while (!feof($socket)) {
            $body .= fread($socket, self::READ_BYTES);
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
            if ($pos === false) break;
            
            $lenHex = trim(substr($data, 0, $pos));
            $len    = hexdec($lenHex);
            
            if ($len === 0) break;
            
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

