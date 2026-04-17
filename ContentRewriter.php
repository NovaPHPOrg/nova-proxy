<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * 内容重写器
 *
 * 职责：重写 HTML/CSS 中的所有链接，将目标站点的 URL 替换为代理前缀路径。
 *
 * 重写规则：
 * - 绝对路径（/path）         -> prefix + /path
 * - 相对路径（./path, ../path）-> 解析为绝对路径后加 prefix
 * - 同源完整URL（http://target/path）-> prefix + /path
 * - 同源协议相对URL（//target/path） -> prefix + /path
 * - 跨域完整URL              -> 不重写
 * - data: / javascript: / mailto: / # -> 不重写
 * - JS/JSON                  -> 不重写（通过注入的 hook 脚本在客户端拦截）
 */
class ContentRewriter
{
    /** @var string 代理前缀路径，如 /proxy/https/example.com */
    private string $prefix;

    /** @var string 代理服务器自身的 origin，如 https://proxyhost.com */
    private string $proxyOrigin;

    /** @var string[] 目标站点所有可能的 origin 形式，按长度降序排列以保证最长匹配优先 */
    private array $targetOrigins;

    /** @var string 当前页面所在目录路径，用于解析相对路径 */
    private string $currentDir = '/';

    /**
     * 构造函数
     *
     * @param string $prefix    代理前缀路径，如 /proxy/https/example.com
     * @param string $targetUri 目标站点完整 URL，如 https://example.com:8080/path
     * @param string $proxyUri  代理服务器自身的完整 URL，如 https://proxyhost.com/path
     */
    public function __construct(string $prefix, string $targetUri, string $proxyUri = '')
    {
        $this->prefix = rtrim($prefix, '/');
        $this->proxyOrigin = $this->extractOrigin($proxyUri);
        $this->targetOrigins = $this->generateTargetOrigins($targetUri);
    }

    /**
     * 从 URL 中提取 origin（scheme://host[:port]）
     */
    private function extractOrigin(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return '';
        }
        $port = $parts['port'] ?? null;
        $portStr = '';
        if ($port !== null) {
            $defaultPort = ($scheme === 'https') ? 443 : 80;
            if ($port !== $defaultPort) {
                $portStr = ':' . $port;
            }
        }
        return "{$scheme}://{$host}{$portStr}";
    }

    /**
     * 设置当前页面路径，用于将相对路径解析为绝对路径
     *
     * @param string $path 当前页面路径，如 /subdir/page.php
     */
    public function setCurrentPath(string $path): void
    {
        // 提取目录部分：/subdir/page.php -> /subdir/
        $this->currentDir = rtrim(dirname($path), '/') . '/';
    }

    /**
     * 根据 Content-Type 自动选择重写策略
     *
     * @param  string $content     响应体内容
     * @param  string $contentType Content-Type 头部值
     * @return string 重写后的内容
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

        if (str_contains($contentType, 'javascript') ||
            str_contains($contentType, 'application/json') ||
            str_contains($contentType, 'text/json')) {
            return $this->rewriteJsOrJson($content);
        }

        return $content;
    }

    /**
     * 重写 HTML 内容
     *
     * 处理以下场景：
     * 1. 在 <head> 开头注入 fetch/XHR hook 脚本，拦截客户端动态请求
     * 2. 重写 src / href / action 属性中的 URL
     * 3. 重写内联 style 属性中的 url() 引用
     * 4. 重写 <style> 标签内的 CSS url() 引用
     *
     * @param  string $html 原始 HTML 内容
     * @return string 重写后的 HTML
     */
    public function rewriteHtml(string $html): string
    {
        // 1. 注入请求 hook 脚本（在 <head> 标签之后立即插入）
        $hookScript = $this->buildHookScript();
        $html = preg_replace('#<head[^>]*>#i', '$0' . $hookScript, $html, 1);

        // 2. 重写 src / href / action 属性
        $html = preg_replace_callback(
            '#\s(src|href|action)=["\']([^"\']+)["\']#i',
            fn ($m) => ' ' . $m[1] . '="' . $this->rewriteUrl($m[2]) . '"',
            $html
        );

        // 3. 重写内联样式中的 url()
        $html = preg_replace_callback(
            '#style=["\']([^"\']*url\([^)]+\)[^"\']*)["\']#i',
            fn ($m) => 'style="' . $this->rewriteCss($m[1]) . '"',
            $html
        );

        // 4. 重写 <style> 标签内的 CSS 内容
        $html = preg_replace_callback(
            '#<style[^>]*>(.*?)</style>#is',
            fn ($m) => '<style' . substr($m[0], 6, strpos($m[0], '>') - 6) . '>' .
                     $this->rewriteCss($m[1]) . '</style>',
            $html
        );

        return $html;
    }

    /**
     * 构建注入到页面的 fetch/XHR hook 脚本
     *
     * 该脚本会在客户端拦截 fetch() 和 XMLHttpRequest，
     * 将同源请求自动转发到代理前缀路径。
     *
     * @return string <script>...</script> 标签
     */
    private function buildHookScript(): string
    {
        $jsFile = __DIR__ . '/hook.js';
        $js = file_get_contents($jsFile);
        $js = str_replace('{{PREFIX}}', $this->prefix, $js);
        return '<script>' . $js . '</script>';
    }

    /**
     * 重写 CSS 内容中的 url() 引用
     *
     * 支持以下格式：
     * - url(/path/to/file)
     * - url('/path/to/file')
     * - url("/path/to/file")
     *
     * @param  string $css 原始 CSS 内容（可以是完整样式表或内联样式片段）
     * @return string 重写后的 CSS
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
     * 重写 JS/JSON 内容中的目标站点 URL
     *
     * 通过字符串替换将所有目标 origin（含协议相对形式和替代协议）替换为代理前缀。
     * 例如：
     * - "https://example.com/api/data" -> "/proxy/https/example.com/api/data"
     * - "//example.com/api/data"       -> "/proxy/https/example.com/api/data"
     *
     * @param  string $content 原始 JS 或 JSON 内容
     * @return string 重写后的内容
     */
    public function rewriteJsOrJson(string $content): string
    {
        $replacement = $this->proxyOrigin . $this->prefix;
        foreach ($this->targetOrigins as $origin) {
            $content = str_replace($origin, $replacement, $content);
        }
        return $content;
    }

    /**
     * 重写单个 URL
     *
     * 判断逻辑（按优先级）：
     * 1. 空URL / 锚点(#) / data: / javascript: / mailto: -> 不重写
     * 2. 完整URL(http(s)://) -> 匹配目标 origin 则替换为 prefix + path，否则不重写
     * 3. 协议相对URL(//) -> 匹配目标 origin 则替换为 prefix + path，否则不重写
     * 4. 绝对路径(/) -> prefix + path
     * 5. 相对路径(path / ./path / ../path) -> 解析为绝对路径后加 prefix
     *
     * @param  string $url 原始 URL
     * @return string 重写后的 URL
     */
    private function rewriteUrl(string $url): string
    {
        $url = trim($url);

        // 1. 特殊协议和空值，不重写
        if ($url === '' ||
            str_starts_with($url, '#') ||
            str_starts_with($url, 'data:') ||
            str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:')) {
            return $url;
        }

        // 2. 完整URL 或 协议相对URL：遍历所有目标 origin 进行匹配
        if (preg_match('#^(?:https?:)?//#i', $url)) {
            foreach ($this->targetOrigins as $origin) {
                if (str_starts_with($url, $origin)) {
                    $path = substr($url, strlen($origin));
                    // 原始是完整 URL，替换后也保持完整 URL 形式
                    return $this->proxyOrigin . $this->prefix . $path;
                }
            }
            // 跨域资源，不重写
            return $url;
        }

        // 3. 绝对路径（/path）
        if (str_starts_with($url, '/')) {
            return $this->prefix . $url;
        }

        // 4. 相对路径（path, ./path, ../path）-> 解析为绝对路径后加 prefix
        $absolutePath = $this->resolveRelativePath($url);
        return $this->prefix . $absolutePath;
    }

    /**
     * 将相对路径解析为绝对路径
     *
     * 基于 currentDir（当前页面所在目录）进行解析：
     * - ./style.css   -> /currentDir/style.css
     * - ../style.css  -> /parentDir/style.css
     * - style.css     -> /currentDir/style.css
     *
     * @param  string $relativePath 相对路径
     * @return string 规范化后的绝对路径（以 / 开头）
     */
    private function resolveRelativePath(string $relativePath): string
    {
        // 移除开头的 ./（等价于当前目录）
        if (str_starts_with($relativePath, './')) {
            $relativePath = substr($relativePath, 2);
        }

        // 拼接当前目录与相对路径
        $path = $this->currentDir . $relativePath;

        // 规范化路径：处理 ".."（上级目录）和多余的 "/"
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

    /**
     * 根据目标 URL 生成所有可能的 origin 变体
     *
     * 用于在 rewriteUrl() 中匹配同源链接。生成的变体包括：
     * 1. 带端口的完整形式：http://domain:8080
     * 2. 带端口的协议相对形式：//domain:8080
     * 3. 不带端口的标准形式：http://domain
     * 4. 不带端口的协议相对形式：//domain
     * 5. 替代协议形式：https->http 或 http->https（后端混用协议的场景）
     *
     * 返回结果按字符串长度降序排列，确保最长前缀优先匹配，
     * 避免短 origin 误匹配导致路径截断。
     *
     * @param  string $url 目标站点 URL
     * @return string[] 去重且按长度降序排列的 origin 列表
     */
    private function generateTargetOrigins(string $url): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? (string)$parts['port'] : '';

        $origins = [];

        // 1. 带端口的完整形式和协议相对形式
        if ($port !== '') {
            $origins[] = "{$scheme}://{$host}:{$port}";
            $origins[] = "//{$host}:{$port}";
        }

        // 2. 不带端口的标准形式和协议相对形式
        $origins[] = "{$scheme}://{$host}";
        $origins[] = "//{$host}";

        // 3. 替代协议：处理后端混用 http/https 的情况
        $altScheme = ($scheme === 'https') ? 'http' : 'https';
        $origins[] = "{$altScheme}://{$host}";

        // 去重后按长度降序排列，保证最长前缀优先匹配
        $origins = array_unique($origins);
        usort($origins, fn ($a, $b) => strlen($b) - strlen($a));

        return $origins;
    }
}
