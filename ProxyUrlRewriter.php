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
            'scheme' => strtolower($p['scheme'] ?? 'https'),
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

        $parsed = $this->parseLocationUrl($location);
        if (!$parsed) {
            return $location;
        }

        if (!$this->isSameHost($parsed['host'])) {
            return $location;
        }

        return $this->buildProxyUrl($parsed);
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
     *
     * @return array{scheme:string,host:string,port:?int,path:string,query:string,fragment:string}|null
     */
    private function parseLocationUrl(string $location): ?array
    {
        if (preg_match('#^https?://#i', $location)) {
            $p = parse_url($location);
            if ($p === false) {
                return null;
            }

            return [
                'scheme'   => strtolower($p['scheme'] ?? 'https'),
                'host'     => $p['host'] ?? '',
                'port'     => $p['port'] ?? null,
                'path'     => $p['path'] ?? '/',
                'query'    => isset($p['query']) ? '?' . $p['query'] : '',
                'fragment' => isset($p['fragment']) ? '#' . $p['fragment'] : '',
            ];
        }

        if (str_starts_with($location, '//')) {
            $p = parse_url('https:' . $location);
            if ($p === false) {
                return null;
            }

            return [
                'scheme'   => $this->targetUrl['scheme'],
                'host'     => $p['host'] ?? '',
                'port'     => $p['port'] ?? null,
                'path'     => $p['path'] ?? '/',
                'query'    => isset($p['query']) ? '?' . $p['query'] : '',
                'fragment' => isset($p['fragment']) ? '#' . $p['fragment'] : '',
            ];
        }

        $p = parse_url($location);
        return [
            'scheme'   => $this->targetUrl['scheme'],
            'host'     => $this->targetUrl['host'],
            'port'     => $this->targetUrl['port'],
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
     * 用 Location 里真实的 scheme/host 构建 /go/ 路径，避免 www 跳转被压回原 prefix 造成死循环。
     *
     * @param array{scheme:string,host:string,port:?int,path:string,query:string,fragment:string} $parsed
     */
    private function buildProxyUrl(array $parsed): string
    {
        $scheme  = $this->proxyUrl['scheme'];
        $host    = $this->proxyUrl['host'];
        $portStr = $this->formatPort($scheme, $this->proxyUrl['port']);
        $path    = '/' . ltrim($parsed['path'], '/');

        $targetScheme = $parsed['scheme'];
        $targetHost = $parsed['host'];
        if (!empty($parsed['port'])) {
            $defaultPort = $targetScheme === 'https' ? 443 : 80;
            if ((int)$parsed['port'] !== $defaultPort) {
                $targetHost .= ':' . $parsed['port'];
            }
        }

        $prefix = '/go/' . $targetScheme . '/' . $targetHost;

        return "{$scheme}://{$host}{$portStr}{$prefix}{$path}{$parsed['query']}{$parsed['fragment']}";
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
