<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * 检测页面 charset，并在文本重写前归一化为 UTF-8。
 * GBK 页面不经转换直接 preg_replace，会在双字节字符中间截断 → 整页乱码。
 *
 * 微信等站点常出现：Content-Type 写 charset=gbk/gb2312，正文实际是 UTF-8。
 * 若只信 Content-Type 会二次误转；若正文已是 UTF-8 却不改响应头，浏览器按 GBK 解 → 注入脚本一并乱码。
 */
final class CharsetNormalizer
{
    public static function detect(string $contentType, string $body): string
    {
        $headerCharset = null;
        if (preg_match('/;\s*charset\s*=\s*([^\s;]+)/i', $contentType, $m)) {
            $headerCharset = self::canonical($m[1]);
        }

        $metaCharset = null;
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w-]+)/i', $body, $m)) {
            $metaCharset = self::canonical($m[1]);
        } elseif (preg_match('/content\s*=\s*["\'][^"\']*charset\s*=\s*([\w-]+)/i', $body, $m)) {
            $metaCharset = self::canonical($m[1]);
        }

        $looksUtf8 = self::isValidUtf8($body);

        // 正文已是合法 UTF-8：以 UTF-8 为准，忽略错误的 GB* Content-Type
        if ($looksUtf8) {
            if ($metaCharset === 'UTF-8' || $headerCharset === 'UTF-8' || $headerCharset === null) {
                return 'UTF-8';
            }
            if ($metaCharset !== null && $metaCharset !== 'UTF-8') {
                return $metaCharset;
            }
            // header 声称 GBK 但正文是 UTF-8 → 仍按 UTF-8，只纠正响应头
            return 'UTF-8';
        }

        if ($metaCharset !== null) {
            return $metaCharset;
        }
        if ($headerCharset !== null) {
            return $headerCharset;
        }

        return 'UTF-8';
    }

    public static function toUtf8(string $body, string $charset): string
    {
        $charset = self::canonical($charset);
        if ($charset === 'UTF-8') {
            return $body;
        }

        // 已经是 UTF-8 就不要按 GBK 再转一遍
        if (self::isValidUtf8($body)) {
            return $body;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }

        return $body;
    }

    /** @param array<int, string> $headerLines */
    public static function applyUtf8Headers(array &$headerLines): void
    {
        $found = false;
        foreach ($headerLines as $i => $line) {
            if (stripos($line, 'Content-Type:') !== 0) {
                continue;
            }
            $found = true;
            $value = trim(substr($line, strlen('Content-Type:')));
            $value = preg_replace('/;\s*charset\s*=[^;]*/i', '', $value) ?? $value;
            $value = rtrim($value, '; ');
            $headerLines[$i] = 'Content-Type: ' . $value . '; charset=utf-8';
            break;
        }

        if (!$found) {
            $headerLines[] = 'Content-Type: text/html; charset=utf-8';
        }
    }

    public static function applyUtf8Meta(string $body): string
    {
        // 确保 <head> 开头有 charset，避免 title 先被按错误编码解析
        if (!preg_match('/<meta[^>]+charset\s*=/i', $body)) {
            $meta = '<meta charset="utf-8">';
            if (preg_match('#<head[^>]*>#i', $body)) {
                $body = preg_replace('#<head[^>]*>#i', '$0' . $meta, $body, 1) ?? $body;
            } else {
                $body = $meta . $body;
            }
        }

        $body = preg_replace(
            '/(<meta[^>]*charset\s*=\s*)(["\']?)[\w-]+\2/i',
            '${1}${2}utf-8${2}',
            $body,
            1
        ) ?? $body;

        return preg_replace(
            '/(content\s*=\s*["\'][^"\']*charset\s*=\s*)[\w-]+/i',
            '${1}utf-8',
            $body,
            1
        ) ?? $body;
    }

    public static function isValidUtf8(string $body): bool
    {
        if ($body === '') {
            return true;
        }

        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($body, 'UTF-8');
        }

        return preg_match('//u', $body) === 1;
    }

    private static function canonical(string $charset): string
    {
        $charset = strtoupper(str_replace([' ', '-'], '', trim($charset, " \t\"'")));
        return match ($charset) {
            'GB2312', 'GBK', 'CP936', 'WINDOWS936' => 'GBK',
            'UTF8' => 'UTF-8',
            'BIG5', 'BIG5HKSCS' => 'BIG5',
            default => str_contains($charset, 'GB') ? 'GBK' : $charset,
        };
    }
}
