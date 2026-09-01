<?php

declare(strict_types=1);

namespace nova\plugin\proxy;

/**
 * 代理 URL 重写器
 * 职责：重写 Location、Cookie 等响应头中的 URL
 */
class ProxyUrlRewriter
{
    private array  $targetUrl;
    private array  $proxyUrl;
    private string $proxyPrefix;

    public function __construct(
        string $targetUri,
        string $proxyUri,
        string $proxyPrefix
    ) {
        $this->targetUrl   = $this->parseUrl($targetUri);
        $this->proxyUrl    = $this->parseUrl($proxyUri);
        $this->proxyPrefix = $proxyPrefix;
    }

    private function parseUrl(string $url): array
    {
        $p = parse_url($url);
        return [
            'scheme' => $p['scheme'] ?? 'https',
            'host'   => $p['host'] ?? '',
            'port'   => $p['port'] ?? null,
        ];
    }

    /**
     * 重写 Location 响应头
     */
    public function rewriteLocation(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return $location;
        }

        // 解析 Location URL
        $parsed = $this->parseLocationUrl($location);
        if (!$parsed) {
            return $location; // 无法解析，保持原样
        }

        // 检查是否需要重写（同域检查）
        if (!$this->isSameHost($parsed['host'])) {
            return $location; // 非同域，不重写
        }

        // 重写 URL
        return $this->buildProxyUrl(
            $parsed['path'],
            $parsed['query'],
            $parsed['fragment']
        );
    }

    /**
     * 重写 Set-Cookie 响应头
     */
    public function rewriteCookie(string $header): string
    {
        $targetHost = $this->targetUrl['host'];
        $proxyHost  = $this->proxyUrl['host'];

        if ($targetHost === '' || $proxyHost === '') {
            return $header;
        }

        return preg_replace(
            '#(domain\s*=\s*)' . preg_quote($targetHost, '#') . '(;|$|\s)#i',
            '$1' . $proxyHost . '$2',
            $header
        );
    }

    /**
     * 解析 Location URL（支持绝对、协议相对、根相对、相对路径）
     */
    private function parseLocationUrl(string $location): ?array
    {
        // 绝对 URL
        if (preg_match('#^https?://#i', $location)) {
            $p = parse_url($location);
            return $p ? [
                'host'     => $p['host'] ?? '',
                'path'     => $p['path'] ?? '/',
                'query'    => isset($p['query']) ? '?' . $p['query'] : '',
                'fragment' => isset($p['fragment']) ? '#' . $p['fragment'] : '',
            ] : null;
        }

        // 根相对或相对路径
        $p = parse_url($location);
        return [
            'host'     => $this->targetUrl['host'],
            'path'     => $p['path'] ?? '/',
            'query'    => isset($p['query']) ? '?' . $p['query'] : '',
            'fragment' => isset($p['fragment']) ? '#' . $p['fragment'] : '',
        ];
    }

    private function isSameHost(string $host): bool
    {
        $host = strtolower($host);
        $target = strtolower($this->targetUrl['host']);
        if ($host === $target) {
            return true;
        }

        return $this->stripWww($host) === $this->stripWww($target);
    }

    private function stripWww(string $host): string
    {
        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    /**
     * 构建代理 URL（路径代理模式）
     */
    private function buildProxyUrl(string $path, string $query, string $fragment): string
    {
        $scheme  = $this->proxyUrl['scheme'];
        $host    = $this->proxyUrl['host'];
        $portStr = $this->formatPort($scheme, $this->proxyUrl['port']);
        $path    = '/' . ltrim($path, '/'); // 统一处理：确保单斜杠

        return "{$scheme}://{$host}{$portStr}{$this->proxyPrefix}{$path}{$query}{$fragment}";
    }

    private function formatPort(string $scheme, ?int $port): string
    {
        if ($port === null) {
            return '';
        }

        $defaultPort = ($scheme === 'https') ? 443 : 80;
        return ($port !== $defaultPort) ? ":{$port}" : '';
    }
}
