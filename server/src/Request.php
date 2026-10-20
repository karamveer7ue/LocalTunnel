<?php

declare(strict_types=1);

namespace Tunnel;

/** The incoming HTTP request, normalized across Apache, nginx, and PHP's built-in server. */
final class Request
{
    /**
     * @param string $basePath URL path the app is installed under, '' at the root of a host
     * @param string $linkBase prefix for links to this app: the base path, plus /index.php when URL rewriting is off
     * @param string $path     raw (still percent-encoded) path below $linkBase, always starting with '/'
     * @param string $rawPath  the path exactly as the visitor sent it
     * @param list<array{0: string, 1: string}> $headers
     */
    private function __construct(
        public readonly string $method,
        public readonly string $host,
        public readonly string $hostHeader,
        public readonly string $scheme,
        public readonly string $basePath,
        public readonly string $linkBase,
        public readonly string $path,
        public readonly string $rawPath,
        public readonly string $query,
        public readonly string $clientIp,
        public readonly array $headers,
    ) {
    }

    public static function fromGlobals(bool $trustProxy, ?string $basePath): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $queryStart = strpos($uri, '?');
        $rawPath = $queryStart === false ? $uri : substr($uri, 0, $queryStart);
        $query = $queryStart === false ? '' : substr($uri, $queryStart + 1);
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(/.*)?$#i', $rawPath, $m)) {
            $rawPath = $m[1] ?? '/';
        }

        if ($basePath === null) {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $basePath = PHP_SAPI === 'cli-server' ? '' : rtrim(dirname($script), '/');
        } else {
            $basePath = rtrim($basePath, '/');
        }
        if ($basePath !== '' && $rawPath !== $basePath && !str_starts_with($rawPath, $basePath . '/')) {
            $basePath = '';
        }
        $script = $basePath . '/index.php';
        $linkBase = $rawPath === $script || str_starts_with($rawPath, $script . '/') ? $script : $basePath;
        $path = substr($rawPath, strlen($linkBase));
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = strtolower($hostHeader);
        $bracket = strpos($host, ']');
        if (str_starts_with($host, '[') && $bracket !== false) {
            $host = substr($host, 0, $bracket + 1);
        } elseif (!str_starts_with($host, '[') && ($colon = strrpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        $httpsFlag = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $https = ($httpsFlag !== '' && $httpsFlag !== 'off') || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($trustProxy) {
            $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($proto === 'https' || $proto === 'http') {
                $https = $proto === 'https';
            }
            // The proxy appends the address it saw, so the last entry is the one it vouches for.
            $forwarded = array_filter(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
            if ($forwarded !== []) {
                $clientIp = (string)end($forwarded);
            }
        }

        return new self(
            method: strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            host: rtrim($host, '.'),
            hostHeader: $hostHeader,
            scheme: $https ? 'https' : 'http',
            basePath: $basePath,
            linkBase: $linkBase,
            path: $path,
            rawPath: $rawPath,
            query: $query,
            clientIp: $clientIp,
            headers: self::readHeaders(),
        );
    }

    /** Public address of this app as seen by the current request, e.g. https://example.com/tunnel. */
    public function appUrl(): string
    {
        return $this->scheme . '://' . $this->hostHeader . $this->linkBase;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as [$key, $value]) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function readHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string)$name)] = [(string)$name, (string)$value];
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with((string)$key, 'HTTP_')) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[strtolower($name)] = [$name, (string)$value];
                }
            }
        }
        // Apache with PHP-FPM hides Authorization unless .htaccess copies it into an environment
        // variable, and that copy exists (empty) even when the visitor sent no Authorization.
        if (($headers['authorization'][1] ?? '') === '') {
            unset($headers['authorization']);
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if ($auth === '' && isset($_SERVER['PHP_AUTH_USER'])) {
                $auth = 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? ''));
            }
            if ($auth !== '') {
                $headers['authorization'] = ['Authorization', $auth];
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (!isset($headers[strtolower($name)]) && ($_SERVER[$key] ?? '') !== '') {
                $headers[strtolower($name)] = [$name, (string)$_SERVER[$key]];
            }
        }
        return array_values($headers);
    }
}
