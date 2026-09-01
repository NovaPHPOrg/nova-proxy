<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;

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
 * - JS/JSON                  -> 替换目标 origin 与引号包裹的绝对路径
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
    /** @var string 原始目标 URL 的 path 部分（用于判断请求是否带有尾部斜杠等） */
    private string $targetPath = '/';

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
        // 保存目标 URL 的 path 部分，可能包含尾部斜杠，用于在 setCurrentPath 中做更鲁棒的判断
        $this->targetPath = parse_url($targetUri, PHP_URL_PATH) ?: '/';
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
        // 规范化输入
        if ($path === '') {
            $this->currentDir = '/';
            return;
        }

        // 确保以 / 开头
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // 如果传入路径以 / 结尾，则视为目录，直接使用
        if (str_ends_with($path, '/')) {
            $this->currentDir = preg_replace('#/+#', '/', $path);
            return;
        }

        // 传入路径不以 / 结尾，可能是文件也可能原始请求其实以 / 结尾但在上游被去掉了。
        // 如果 basename 看起来像文件（包含点），默认按文件处理（dirname）;
        // 但为了解决诸如 index.cgi/ 被上游丢失尾部斜杠导致相对路径解析错误的情况，
        // 我们检测原始目标 URL 的 path（$this->targetPath）是否以该 basename 后跟 '/' 的形式存在，
        // 如果是，则说明实际请求是以目录形式访问，应该保留该 basename 作为目录名。

        $base = basename($path);
        if (str_contains($base, '.')) {
            // 检查原始目标 path 是否包含 basename 后跟斜杠（例如原始为 /.../index.cgi/）
            if (str_ends_with($this->targetPath, $base . '/') || str_contains($this->targetPath, '/' . $base . '/')) {
                // 将其视为目录
                $this->currentDir = rtrim($path, '/') . '/';
                return;
            }
            // 否则按文件处理，使用 dirname
            $this->currentDir = rtrim(dirname($path), '/') . '/';
            return;
        }

        // 不包含点，视为目录名
        $this->currentDir = rtrim($path, '/') . '/';
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
        // 检查并处理 <base href="...">：如果存在，则只更新 base 的 href（将其指向代理前缀下的目标），
        // 并且在页面其余位置跳过对相对路径的重写，依赖浏览器通过 base 来解析相对 URL。
        preg_match('#<base[^>]*href=["\']([^"\']+)["\'][^>]*>#i', $html, $m);
        $skipRelative = count($m) > 0;
        if ($skipRelative) {
            $html = preg_replace(
                '#(<base[^>]*href=["\'])([^"\']+)(["\'][^>]*>)#i',
                '$1' . $this->rewriteUrl($m[1]) . '$3',
                $html,
                1
            ) ?? $html;
        }

        // 1. 注入请求 hook 脚本（在 <head> 标签之后立即插入）
        $hookScript = $this->buildHookScript();
        $html = preg_replace('#<head[^>]*>#i', '$0' . $hookScript, $html, 1);

        // 2. 重写 src / href / action / data-src 属性
        $html = preg_replace_callback(
            '#\s(src|href|action|data-src)=["\']([^"\']+)["\']#i',
            function ($m) use ($skipRelative) {
                $attr = $m[1];
                $val = $m[2];
                // 如果开启了跳过相对 URL 且该 URL 为相对路径，则不修改
                if ($skipRelative && $this->isRelativeUrl($val)) {
                    return ' ' . $attr . '="' . $val . '"';
                }
                return ' ' . $attr . '="' . $this->rewriteUrl($val) . '"';
            },
            $html
        );

        // 3. 重写内联样式中的 url()
        $html = preg_replace_callback(
            '#style=["\']([^"\']*url\([^)]+\)[^"\']*)["\']#i',
            function ($m) use ($skipRelative) {
                return 'style="' . $this->rewriteCss($m[1], $skipRelative) . '"';
            },
            $html
        );

        // 4. 重写 <style> 标签内的 CSS 内容
        $html = preg_replace_callback(
            '#<style[^>]*>(.*?)</style>#is',
            function ($m) use ($skipRelative) {
                return '<style' . substr($m[0], 6, strpos($m[0], '>') - 6) . '>' .
                       $this->rewriteCss($m[1], $skipRelative) . '</style>';
            },
            $html
        );

        // 5. meta refresh 跳转
        $html = preg_replace_callback(
            '#<meta\b[^>]*http-equiv=["\']refresh["\'][^>]*content=["\']([^"\']+)["\'][^>]*>#i',
            function ($m) {
                $content = preg_replace_callback(
                    '#url=(.+)$#i',
                    fn ($u) => 'url=' . $this->rewriteUrl(trim($u[1], " \t'\"")),
                    $m[1]
                );
                return preg_replace(
                    '#content=["\'][^"\']+["\']#i',
                    'content="' . $content . '"',
                    $m[0],
                    1
                ) ?? $m[0];
            },
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
        $js = str_replace(
            '{{TARGET_ORIGINS}}',
            json_encode($this->targetOrigins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $js
        );
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
     * @param  bool   $skipRelative 如果为 true，则跳过对相对路径的重写（配合 <base> 使用）
     * @return string 重写后的 CSS
     */
    public function rewriteCss(string $css, bool $skipRelative = false): string
    {
        return preg_replace_callback(
            '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i',
            function ($m) use ($skipRelative) {
                $url = $m[1];
                if ($skipRelative && $this->isRelativeUrl($url)) {
                    return 'url("' . $url . '")';
                }
                return 'url("' . $this->rewriteUrl($url) . '")';
            },
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

        $prefix = $this->prefix;
        $content = preg_replace_callback(
            '#(["\'])(/[^"\']*)\1#',
            function (array $m) use ($prefix): string {
                $path = $m[2];
                if ($path === '/' ||
                    str_starts_with($path, '//') ||
                    str_starts_with($path, $prefix . '/') ||
                    $path === $prefix) {
                    return $m[0];
                }

                return $m[1] . $prefix . $path . $m[1];
            },
            $content
        );

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
            if (str_starts_with($url, $this->prefix . '/') || $url === $this->prefix) {
                return $url;
            }

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
     * 判断一个 URL 是否为相对 URL（不以 /、// 或协议开头）
     *
     * 相对 URL 示例："images/a.png"、"./a.png"、"../a.png"
     * 非相对（绝对）示例："/a.png"、"//example.com/a.png"、"https://..."
     *
     * @param string $url
     * @return bool
     */
    private function isRelativeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // 特殊协议和锚点等视为非相对（这些通常不应被 rewrite）
        if (str_starts_with($url, '#') ||
            str_starts_with($url, 'data:') ||
            str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:')) {
            return false;
        }

        // 如果以 / 或 // 开头，或以 scheme: 开头，则视为非相对
        return !preg_match('#^(?:[a-zA-Z][a-zA-Z0-9+.-]*:|//|/)#', $url);
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
