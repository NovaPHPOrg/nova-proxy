<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * 内容重写器
 * 职责：重写 HTML/CSS/JS 中的所有外部链接，添加 proxy prefix
 *
 * 重写规则：
 * - 绝对路径（/path）-> prefix + /path
 * - 相对路径（./path, ../path）-> 保持不变（浏览器会自动相对于当前页面）
 * - 完整URL（http://...）-> 不重写（跨域资源）
 */
class ContentRewriter
{
    private string $prefix;
    private string $targetOrigin;
    private string $currentDir = '/';  // 当前页面所在目录

    public function __construct(string $prefix, string $targetUri)
    {
        $this->prefix = rtrim($prefix, '/');

        $parsed = parse_url($targetUri);
        $this->targetOrigin = ($parsed['scheme'] ?? 'https') . '://' .
                             ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $this->targetOrigin .= ':' . $parsed['port'];
        }
    }

    /**
     * 设置当前页面路径（用于计算相对路径）
     */
    public function setCurrentPath(string $path): void
    {
        // 提取目录部分：/subdir/page.php -> /subdir/
        $this->currentDir = rtrim(dirname($path), '/') . '/';
    }

    /**
     * 根据 Content-Type 自动选择重写策略
     */
    public function rewrite(string $content, string $contentType): string
    {
        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'text/html')) {
            return $this->rewriteHtml($content);
        }

        if (str_contains($contentType, 'text/css') ||
            str_contains($contentType, 'application/css')) {
            return $this->rewriteCss($content);
        }

        // JS/JSON 不重写，通过 hook 请求类处理
        return $content;
    }

    /**
     * 重写 HTML 内容
     * 处理：<script src>, <link href>, <img src>, <a href>, <form action> 等
     * 并注入 fetch/XHR hook 脚本
     */
    public function rewriteHtml(string $html): string
    {
        // 注入请求 hook 脚本（在 <head> 最前面）
        $hookScript = $this->buildHookScript();
        $html = preg_replace('#<head[^>]*>#i', '$0' . $hookScript, $html, 1);

        // 重写 src 属性
        $html = preg_replace_callback(
            '#\s(src|href|action)=["\']([^"\']+)["\']#i',
            fn ($m) => ' ' . $m[1] . '="' . $this->rewriteUrl($m[2]) . '"',
            $html
        );

        // 重写内联样式中的 url()
        $html = preg_replace_callback(
            '#style=["\']([^"\']*url\([^)]+\)[^"\']*)["\']#i',
            fn ($m) => 'style="' . $this->rewriteCss($m[1]) . '"',
            $html
        );

        // 重写 <style> 标签内容
        $html = preg_replace_callback(
            '#<style[^>]*>(.*?)</style>#is',
            fn ($m) => '<style' . substr($m[0], 6, strpos($m[0], '>') - 6) . '>' .
                     $this->rewriteCss($m[1]) . '</style>',
            $html
        );

        return $html;
    }

    /**
     * 构建 fetch/XHR hook 脚本
     */
    private function buildHookScript(): string
    {
        $jsFile = __DIR__ . '/hook.js';
        $js = file_get_contents($jsFile);
        $js = str_replace('{{PREFIX}}', $this->prefix, $js);
        return '<script>' . $js . '</script>';
    }

    /**
     * 重写 CSS 内容
     * 处理：url(/path), url('/path'), url("/path")
     */
    public function rewriteCss(string $css): string
    {
        return preg_replace_callback(
            '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i',
            fn ($m) => 'url("' . $this->rewriteUrl($m[1]) . '")',
            $css
        );
    }

    /**
     * 重写单个 URL
     * 规则：
     * - 绝对路径（/path）-> prefix + path
     * - 协议相对（//host/path）-> 不重写
     * - 完整URL（http://）-> 只重写同源URL
     * - 相对路径（path, ./path, ../path）-> 解析后加 prefix
     * - Data URI（data:）-> 不重写
     * - 锚点（#）-> 不重写
     */
    private function rewriteUrl(string $url): string
    {
        $url = trim($url);

        // 空URL、锚点、data URI、javascript: 不重写
        if ($url === '' ||
            str_starts_with($url, '#') ||
            str_starts_with($url, 'data:') ||
            str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:')) {
            return $url;
        }

        // 完整URL：只重写同源的
        if (preg_match('#^https?://#i', $url)) {
            if (str_starts_with($url, $this->targetOrigin)) {
                // 同源：替换 origin 为 prefix
                return $this->prefix . substr($url, strlen($this->targetOrigin));
            }
            return $url; // 跨域，不重写
        }

        // 协议相对 URL（//example.com/path）
        if (str_starts_with($url, '//')) {
            return $url; // 不重写
        }

        // 绝对路径（/path）
        if (str_starts_with($url, '/')) {
            return $this->prefix . $url;
        }

        // 相对路径（path, ./path, ../path）-> 解析成绝对路径
        $absolutePath = $this->resolveRelativePath($url);
        return $this->prefix . $absolutePath;
    }

    /**
     * 将相对路径解析为绝对路径
     * ./style.css -> /currentDir/style.css
     * ../style.css -> /parentDir/style.css
     * style.css -> /currentDir/style.css
     */
    private function resolveRelativePath(string $relativePath): string
    {
        // 移除开头的 ./
        if (str_starts_with($relativePath, './')) {
            $relativePath = substr($relativePath, 2);
        }

        // 组合当前目录和相对路径
        $path = $this->currentDir . $relativePath;

        // 规范化路径（处理 .. 和多余的 /）
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($result);
            } else {
                $result[] = $part;
            }
        }

        return '/' . implode('/', $result);
    }
}
