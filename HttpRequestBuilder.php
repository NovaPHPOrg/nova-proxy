<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;
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
    public function buildHeader(array $urlInfo): string
    {
        $headers = $this->buildHeaders($urlInfo);
        $uri     = $urlInfo['path'] . $urlInfo['query'];


        return sprintf(
            "%s %s HTTP/1.1\r\n%sConnection: close\r\n\r\n",
            $_SERVER['REQUEST_METHOD'],
            $uri,
            $headers,
        );
    }

    /**
     * 流式转发请求体到目标 socket
     */
    public function streamBody($socket): void
    {
        $input = fopen('php://input', 'rb');
        Logger::debug("Start streaming request body to target socket...\n");
        if ($input === false) {
            return;
        }
        Logger::debug("Opened php://input for reading request body.\n");
        try {
            while (!feof($input)) {
                Logger::debug("Reading request body chunk from php://input...\n");
                $chunk = fread($input, 8192);
                if ($chunk !== false && $chunk !== '') {
                    Logger::debug("Streaming request body chunk: " . strlen($chunk) . " bytes\n".$chunk);
                    fwrite($socket, $chunk);
                }else{
                    break;
                }
            }
            Logger::debug("Closed streaming request body.\n");
        } catch (\Throwable $e) {
            Logger::error("Error while streaming request body: " . $e->getMessage(),$e->getTrace());
        }finally{
            fclose($input);
            Logger::debug("Finished streaming request body to target socket.\n");
        }
    }

    private function buildHeaders(array $urlInfo): string
    {
        $scheme = strtolower((string)($urlInfo['scheme'] ?? 'http'));
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int)($urlInfo['port'] ?? $defaultPort);
        $host = (string)$urlInfo['host'];
        // 默认端口不要写进 Host，部分 CDN/源站会回奇怪页面
        if ($port !== $defaultPort) {
            $host .= ':' . $port;
        }

        $out = "Host: {$host}\r\n";

        foreach ($_SERVER as $k => $v) {
            if (!str_starts_with($k, 'HTTP_')) {
                continue;
            }

            if ($k === 'HTTP_HOST') {
                continue;
            }

            $headerName = str_replace('_', '-', substr($k, 5));

            // 强制只接受 gzip/deflate，PHP 没有内置 brotli 解码
            if ($headerName === 'ACCEPT-ENCODING') {
                $out .= "Accept-Encoding: gzip, deflate\r\n";
                continue;
            }

            $out .= "$headerName: $v\r\n";
        }
        return $out;
    }

    /**
     * 当 PHP 已经消费了原始请求体（multipart/form-data 情况）时，
     * 从 $_POST/$_FILES 重建 multipart 请求体到一个临时流，并返回流与长度。
     * 返回格式：['stream'=>resource, 'length'=>int, 'content_type'=>string]
     */
    public function buildMultipartBodyFromGlobals(): ?array
    {
        if (empty($_FILES) && empty($_POST)) {
            return null;
        }

        // 尝试从原始 Content-Type 提取 boundary，否则生成一个新的
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $boundary = null;
        if (preg_match('/boundary=(.*)$/', $contentType, $m)) {
            $boundary = trim($m[1], "\"' ");
        }
        if (!$boundary) {
            $boundary = '----WebKitFormBoundary' . bin2hex(random_bytes(8));
        }

        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            return null;
        }

        $write = function(string $s) use ($stream) {
            fwrite($stream, $s);
        };

        // 写入普通字段（支持数组结构）
        $emitField = function($name, $value) use ($write, $boundary) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $subName = $name . "[$k]";
                    $write("--$boundary\r\n");
                    $write("Content-Disposition: form-data; name=\"$subName\"\r\n\r\n");
                    $write((string)$v . "\r\n");
                }
            } else {
                $write("--$boundary\r\n");
                $write("Content-Disposition: form-data; name=\"$name\"\r\n\r\n");
                $write((string)$value . "\r\n");
            }
        };

        foreach ($_POST as $n => $v) {
            $emitField($n, $v);
        }

        // 处理文件（支持多文件 input name[]）
        $normalizeFiles = function(array $files) {
            $out = [];
            foreach ($files as $field => $info) {
                if (!is_array($info['name'])) {
                    $out[] = ['field' => $field, 'name' => $info['name'], 'type' => $info['type'], 'tmp_name' => $info['tmp_name'], 'error' => $info['error']];
                } else {
                    // 多维数组情况，展开索引
                    $names = $info['name'];
                    $types = $info['type'];
                    $tmps  = $info['tmp_name'];
                    $errs  = $info['error'];
                    $flatten = function($base, $names, $types, $tmps, $errs, $path = []) use (&$flatten, &$out) {
                        if (is_array($names)) {
                            foreach ($names as $k => $v) {
                                $p = array_merge($path, [$k]);
                                $flatten($base, $names[$k], $types[$k], $tmps[$k], $errs[$k], $p);
                            }
                        } else {
                            // build field name like field[key][sub]
                            $suffix = '';
                            foreach ($path as $p) {
                                $suffix .= "[$p]";
                            }
                            $out[] = ['field' => $base . $suffix, 'name' => $names, 'type' => $types, 'tmp_name' => $tmps, 'error' => $errs];
                        }
                    };
                    $flatten($field, $names, $types, $tmps, $errs, []);
                }
            }
            return $out;
        };

        $files = $normalizeFiles($_FILES);
        foreach ($files as $f) {
            if (!isset($f['tmp_name']) || $f['error'] !== 0) {
                continue;
            }
            $fname = $f['field'];
            $origName = $f['name'];
            $ctype = $f['type'] ?: 'application/octet-stream';
            $tmp = $f['tmp_name'];

            $write("--$boundary\r\n");
            $write("Content-Disposition: form-data; name=\"$fname\"; filename=\"$origName\"\r\n");
            $write("Content-Type: $ctype\r\n\r\n");

            // 写入文件内容
            $in = fopen($tmp, 'rb');
            if ($in !== false) {
                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        fwrite($stream, $chunk);
                    }
                }
                fclose($in);
            }
            $write("\r\n");
        }

        // 结束 boundary
        $write("--$boundary--\r\n");

        // 计算长度
        $length = ftell($stream);
        rewind($stream);

        return ['stream' => $stream, 'length' => $length, 'content_type' => 'multipart/form-data; boundary=' . $boundary];
    }
}
