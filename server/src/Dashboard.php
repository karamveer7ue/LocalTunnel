<?php

declare(strict_types=1);

namespace Tunnel;

/** The web dashboard: sign-in with the token, a live view of tunnels and requests. */
final class Dashboard
{
    private const COOKIE = 'tunnel_session';
    private const SESSION_DAYS = 30;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Request $request,
        private readonly Links $links,
    ) {
    }

    public function page(): void
    {
        self::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        View::render('dashboard', [
            'request' => $this->request,
            'settings' => [
                'api' => $this->links->internal('api/'),
                'url' => $this->links->app(),
                'version' => App::VERSION,
                'client' => App::clientScript() === null ? null : $this->links->app() . '/_tunnel/tunnel.py',
            ],
        ]);
    }

    public function api(string $action): void
    {
        self::securityHeaders();
        $method = $this->request->method;
        if ($method !== 'GET' && !$this->isSameOrigin()) {
            Response::json(403, ['error' => 'Cross-site request refused.']);
            return;
        }
        match ([$method, $action]) {
            ['GET', 'session'] => Response::json(200, ['authenticated' => $this->isAuthenticated()]),
            ['POST', 'login'] => $this->login(),
            ['POST', 'logout'] => $this->logout(),
            ['GET', 'state'] => $this->isAuthenticated() ? $this->state() : self::unauthenticated(),
            ['POST', 'close'] => $this->isAuthenticated() ? $this->close() : self::unauthenticated(),
            default => Response::json(404, ['error' => 'Not found.']),
        };
    }

    private function login(): void
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $token = is_array($body) ? (string)($body['token'] ?? '') : '';
        if (!hash_equals($this->config->token, $token)) {
            usleep(500000);
            Response::json(401, ['error' => 'That token is not correct.']);
            return;
        }
        $expires = time() + self::SESSION_DAYS * 86400;
        setcookie(self::COOKIE, $expires . '.' . $this->sign($expires), $this->cookieOptions($expires));
        Response::json(200, ['ok' => true]);
    }

    private function logout(): void
    {
        setcookie(self::COOKIE, '', $this->cookieOptions(1));
        Response::json(200, ['ok' => true]);
    }

    private function state(): void
    {
        $active = $this->store->activeCounts();
        $tunnels = array_map(fn (array $t): array => [
            'name' => $t['name'],
            'urls' => $this->links->tunnel($t['name']),
            'target' => $t['target'],
            'client' => $t['client_name'],
            'clientIp' => $t['client_ip'],
            'protected' => $t['auth'] !== null,
            'online' => Store::isOnline($t),
            'createdAt' => (float)$t['created_at'],
            'lastSeen' => (float)$t['last_seen'],
            'requests' => (int)$t['requests'],
            'bytesIn' => (int)$t['bytes_in'],
            'bytesOut' => (int)$t['bytes_out'],
            'active' => $active[$t['name']] ?? 0,
        ], $this->store->tunnels());

        $requests = array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'tunnel' => $r['tunnel'],
            'method' => $r['method'],
            'uri' => $r['uri'],
            'state' => $r['state'],
            'status' => $r['status'] === null ? null : (int)$r['status'],
            'ip' => $r['client_ip'],
            'at' => (float)$r['created_at'],
            'durationMs' => $r['duration_ms'] === null ? null : (int)$r['duration_ms'],
            'bytes' => (int)$r['sent_bytes'],
            'error' => $r['error'],
        ], $this->store->recentRequests(200));

        Response::json(200, [
            'now' => microtime(true),
            'server' => [
                'version' => App::VERSION,
                'url' => $this->links->app(),
                'subdomains' => $this->config->baseDomain,
                'checks' => $this->checks(),
            ],
            'tunnels' => $tunnels,
            'requests' => $requests,
        ]);
    }

    private function close(): void
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $name = is_array($body) ? (string)($body['name'] ?? '') : '';
        if (!$this->store->close($name, 'closed from the dashboard')) {
            Response::json(404, ['error' => "No open tunnel named \"$name\"."]);
            return;
        }
        Response::json(200, ['ok' => true]);
    }

    /** @return list<array{level: string, message: string}> */
    private function checks(): array
    {
        $checks = [];
        $host = $this->request->host;
        $local = $host === 'localhost' || str_ends_with($host, '.localhost') || str_starts_with($host, '127.') || $host === '[::1]';
        if ($this->request->scheme !== 'https' && !$local) {
            $checks[] = ['level' => 'warn', 'message' => 'This server is reached over plain HTTP, so your token and all tunneled traffic travel unencrypted. Serve it over HTTPS.'];
        }
        if (filter_var(ini_get('enable_post_data_reading'), FILTER_VALIDATE_BOOLEAN)) {
            $checks[] = ['level' => 'warn', 'message' => 'The PHP setting enable_post_data_reading is on, so uploaded files are re-encoded and capped at upload_max_filesize ('
                . ini_get('upload_max_filesize') . '). The included .user.ini and .htaccess turn it off; check that your host applies them.'];
        }
        if ($this->store->journalMode() !== 'wal') {
            $checks[] = ['level' => 'info', 'message' => 'SQLite cannot use write-ahead logging here (is the data directory on a network drive?), so busy tunnels respond a little slower.'];
        }
        if ($this->config->baseDomain === null) {
            $checks[] = ['level' => 'info', 'message' => 'Tunnels are served under /t/<name>/. Set base_domain in config.php to give each tunnel its own subdomain, which suits apps that use absolute links.'];
        }
        return $checks;
    }

    private function isAuthenticated(): bool
    {
        $parts = explode('.', (string)($_COOKIE[self::COOKIE] ?? ''), 2);
        if (count($parts) !== 2 || preg_match('/^\d{1,12}$/', $parts[0]) !== 1 || (int)$parts[0] < time()) {
            return false;
        }
        return hash_equals($this->sign((int)$parts[0]), $parts[1]);
    }

    /**
     * State-changing calls must come from the dashboard itself. Browsers cannot attach the
     * custom header across origins without a CORS preflight, which this API never grants.
     */
    private function isSameOrigin(): bool
    {
        if ($this->request->header('x-tunnel-csrf') !== '1') {
            return false;
        }
        $origin = $this->request->header('origin');
        return $origin === null || strcasecmp($origin, $this->request->scheme . '://' . $this->request->hostHeader) === 0;
    }

    private function sign(int $expires): string
    {
        return hash_hmac('sha256', 'session:' . $expires, $this->config->token);
    }

    /** @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: 'Strict'} */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            // Scoped to the API, so tunneled apps under /t/ never receive it.
            'path' => $this->links->internal('api/'),
            'secure' => $this->request->scheme === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private static function unauthenticated(): void
    {
        Response::json(401, ['error' => 'Sign in first.']);
    }

    private static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
}
