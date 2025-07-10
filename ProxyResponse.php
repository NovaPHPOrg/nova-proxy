<?php
declare(strict_types=1);

namespace nova\plugin\proxy;

use nova\framework\core\Logger;
use nova\framework\http\Response;

class ProxyResponse extends Response
{
    private string $uri;
    private array  $socketConfig;
    private string $path = '';

    /** @var callable|null (string $rawRequest, array $urlInfo): array */
    private $requestInterceptor = null;

    /** @var callable|null (string $respBody, array $respHeaders, string $path): string */
    private $responseInjector  = null;

    /** @var callable|null (\Throwable $exception): void */
    private $errorHandler      = null;

    public const int READ_BYTES = 4096;
    private int  $timeout = 30;
    private string $domain = '';

    public function __construct(string $uri, string $domain)
    {
        parent::__construct();

        $this->uri   = $uri;
        $this->domain = $domain;
        $this->socketConfig = [
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*               Public helpers (chain-style setters)                 */
    /* ------------------------------------------------------------------ */

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setErrorHandler(callable $err): self
    {
        $this->errorHandler = $err;
        return $this;
    }

    /** @param callable (string $rawRequest, array $urlInfo): array $cb */
    public function setRequestInterceptor(callable $cb): self
    {
        $this->requestInterceptor = $cb;
        return $this;
    }

    /** @param callable (string $body, array $headers, string $path): string $cb */
    public function setResponseInjector(callable $cb): self
    {
        $this->responseInjector = $cb;
        return $this;
    }

    /* ------------------------------------------------------------------ */
    /*                               Entry                                */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ProxyException
     */
    public function send(): void
    {
        try {
            // *** WS GUARD: 拒绝 WebSocket 升级 & ws / wss 目标
            if ($this->isWebSocketRequest()) {
                header('HTTP/1.1 501 Not Implemented');
                header('Content-Type: text/plain; charset=utf-8');
                echo 'WebSocket is not supported by this proxy.';
                flush();
                return;
            }

            $this->forwardRequest($this->uri);
        } catch (\Throwable $e) {
            if ($this->errorHandler) {
                ($this->errorHandler)($e);
            } else {
                throw $e;
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*                          Core forwarding                           */
    /* ------------------------------------------------------------------ */

    /**
     * @throws ProxyException
     */
    private function forwardRequest(string $targetUri): void
    {
        $urlInfo = $this->parseAndValidateUrl($targetUri);
        $socket  = $this->createConnection($urlInfo);

        try {
            $request = $this->buildRequest($urlInfo);

            // 请求拦截器
            if ($this->requestInterceptor) {
                [$request, $earlyBody] = ($this->requestInterceptor)($request, $urlInfo);
                if ($earlyBody !== '') {
                    echo $earlyBody;
                    flush();
                    return;
                }
            }

            Logger::info("-> Proxying Request:\n" . $request);
            $this->sendRequest($socket, $request);
            $this->receiveResponse($socket);
        } finally {
            $this->closeConnection($socket);
        }
    }

    /* ------------------------------------------------------------------ */
    /*                        Building & sending                          */
    /* ------------------------------------------------------------------ */

    private function parseAndValidateUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new ProxyException("Invalid URL: $url");
        }
        $this->path = $p['path'] ?? '/';

        return [
            'scheme' => strtolower($p['scheme']),
            'host'   => $p['host'],
            'port'   => $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80),
            'path'   => $this->path,
            'query'  => isset($p['query']) ? '?' . $p['query'] : '',
        ];
    }

    /**
     * @throws ProxyException
     */
    private function createConnection(array $u)
    {
        $ctx = stream_context_create($this->socketConfig);
        $dsn = ($u['scheme'] === 'https' ? 'ssl://' : 'tcp://')
            . $u['host'] . ':' . $u['port'];

        $sock = stream_socket_client(
            $dsn, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$sock) {
            throw new ProxyException("Connection failed: $errstr ($errno)");
        }
        return $sock;
    }

    private function buildRequest(array $u): string
    {
        $headers = $this->getRequestHeaders($u['host']);
        $body    = file_get_contents('php://input');
        $uri     = $u['path'] . $u['query'];

        return sprintf(
            "%s %s HTTP/1.1\r\n%sConnection: close\r\n\r\n%s",
            $_SERVER['REQUEST_METHOD'], $uri, $headers, $body
        );
    }

    private function getRequestHeaders(string $host): string
    {
        $out = "Host: $host\r\n";
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_') && $k !== 'HTTP_HOST') {
                $out .= str_replace('_', '-', substr($k, 5)) . ": $v\r\n";
            }
        }
        return $out;
    }

    private function sendRequest($socket, string $req): void
    {
        fwrite($socket, $req);
    }

    private function receiveResponse($socket): void
    {
        $rawHost  = $this->getDomain($this->uri);
        $replHost = $this->getDomain($this->domain);

        $isChunked = false;
        $isGzip    = false;
        $hdrSent   = [];

        /* ---------- 读取并处理响应头 ---------- */
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === "\r\n") {
                break;                             // 头结束
            }

            $trim = trim($line);
            if ($trim === '') continue;

            // Transfer-Encoding: chunked  —— 不下发，标记后续解码
            if (stripos($trim, 'Transfer-Encoding:') === 0 &&
                stripos($trim, 'chunked')           !== false) {
                $isChunked = true;
                continue;
            }

            // Content-Encoding: gzip —— 标记，稍后可能换新的
            if (stripos($trim, 'Content-Encoding:') === 0 &&
                stripos($trim, 'gzip')             !== false) {
                $isGzip = true;
                // 先不发送，等主体处理完后再决定
                continue;
            }

            // 其余头：做域名替换后立即透传
            $hdr = str_replace($rawHost, $replHost, $trim);
            header($hdr);
            $hdrSent[] = $hdr;
        }

        /* ---------- 读取主体 ---------- */
        $body = '';
        while (!feof($socket)) {
            $body .= fread($socket, self::READ_BYTES);
        }

        /* ---------- 分块解码 ---------- */
        if ($isChunked) {
            $body = $this->decodeChunked($body);
        }

        /* ---------- gzip 解压，用于文本替换 / 注入 ---------- */
        if ($isGzip) {
            $decoded = @gzdecode($body);
            // 如果解压失败就保留原样
            if ($decoded !== false) {
                $body = $decoded;
            }
        }

        /* ---------- 域名替换 + 自定义注入 ---------- */
        $body = str_replace($rawHost, $replHost, $body);

        if ($this->responseInjector) {
            $body = ($this->responseInjector)(
                $body,
                $hdrSent,
                $this->path
            );
        }

        /* ---------- 如有 gzip 重新压回去 ---------- */
        if ($isGzip) {
            $body = gzencode($body);
            header('Content-Encoding: gzip', true);          // replace/add
        } else {
            // 确保没把上游的 gzip 头遗漏
            header_remove('Content-Encoding');
        }

        /* ---------- 重新发送正确的长度 ---------- */
        header('Content-Length: ' . strlen($body), true);

        /* ---------- 输出 ---------- */
        echo $body;
        flush();
    }


    /**
     * 把 “块长度\r\n数据\r\n” 形式的数据解包成纯正文
     */
    private function decodeChunked(string $data): string
    {
        $out = '';
        while ($data !== '') {
            // 找 CRLF 之前的长度字段
            if (($pos = strpos($data, "\r\n")) === false) break;
            $lenHex = trim(substr($data, 0, $pos));
            $len    = hexdec($lenHex);
            if ($len === 0) break;                       // “0\r\n\r\n” 结束
            $out  .= substr($data, $pos + 2, $len);       // 取出完整块
            // 跳过 “len\r\n……数据……\r\n”
            $data  = substr($data, $pos + 2 + $len + 2);
        }
        return $out;
    }


    private function closeConnection($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    /* ------------------------------------------------------------------ */
    /*                         Utility helpers                            */
    /* ------------------------------------------------------------------ */

    /** 遇到 Upgrade: websocket 或 URL scheme=ws(s) 就视为 WebSocket 请求 */
    private function isWebSocketRequest(): bool
    {
        // header 判断
        $isUpgrade = isset($_SERVER['HTTP_UPGRADE'])
            && strcasecmp($_SERVER['HTTP_UPGRADE'], 'websocket') === 0;
        $hasConn   = isset($_SERVER['HTTP_CONNECTION'])
            && stripos($_SERVER['HTTP_CONNECTION'], 'upgrade') !== false;

        // URL scheme 判断
        $scheme = strtolower(parse_url($this->uri, PHP_URL_SCHEME) ?: '');
        $isWsScheme = in_array($scheme, ['ws', 'wss'], true);

        return ($isUpgrade && $hasConn) || $isWsScheme;
    }

    private function getDomain(string $d): string
    {
        return str_replace(['https://', 'http://'], '', trim($d, '/'));
    }
}
