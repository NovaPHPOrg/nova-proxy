<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * 检测页面 charset，并在文本重写前归一化为 UTF-8。
 * GBK 页面不经转换直接 preg_replace，会在双字节字符中间截断 → 整页乱码。
 */
final class CharsetNormalizer
{
    public static function detect(string $contentType, string $body): string
    {
        if (preg_match('/;\s*charset\s*=\s*([^\s;]+)/i', $contentType, $m)) {
            return self::canonical($m[1]);
        }

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w-]+)/i', $body, $m)) {
            return self::canonical($m[1]);
        }

        if (preg_match('/content\s*=\s*["\'][^"\']*charset\s*=\s*([\w-]+)/i', $body, $m)) {
            return self::canonical($m[1]);
        }

        return 'UTF-8';
    }

    public static function toUtf8(string $body, string $charset): string
    {
        $charset = self::canonical($charset);
        if ($charset === 'UTF-8') {
            return $body;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
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

    private static function canonical(string $charset): string
    {
        $charset = strtoupper(str_replace([' ', '-'], '', trim($charset)));
        return match ($charset) {
            'GB2312', 'GBK', 'CP936', 'WINDOWS936' => 'GBK',
            'UTF8' => 'UTF-8',
            'BIG5', 'BIG5HKSCS' => 'BIG5',
            default => str_contains($charset, 'GB') ? 'GBK' : $charset,
        };
    }
}
