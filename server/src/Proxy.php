<?php

declare(strict_types=1);

namespace Tunnel;

/**
 * Serves one public request through a tunnel: queues it for the client, waits while the
 * client fetches the answer from the local app, and streams that answer back as it arrives.
 *
 * @phpstan-import-type TunnelRow from Store
 * @phpstan-import-type RequestRow from Store
 */
final class Proxy
{
    /** Seconds a request may sit in the queue before we conclude the client is gone. */
    private const PICKUP_TIMEOUT = 30;

    /** Headers that describe one connection rather than the message (RFC 9110, section 7.6.1). */
    private const HOP_BY_HOP = [
        'connection' => true, 'keep-alive' => true, 'proxy-connection' => true, 'te' => true,
        'trailer' => true, 'transfer-encoding' => true, 'upgrade' => true,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Request $request,
    ) {
    }

    /**
     * @param string $uri    path and query to ask the local app for
     * @param string $prefix public path the tunnel is mounted under, '' on its own subdomain
     */
    public function handle(string $name, string $uri, string $prefix): void
    {
        $req = $this->request;
        $tunnel = Names::isValid($name) ? $this->store->tunnel($name) : null;
        if ($tunnel === null || $tunnel['closed_reason'] !== null) {
            Response::page(404, 'Tunnel not found', "No tunnel named \"$name\" is open on this server.", "Open it from your computer with `python3 tunnel.py $name=3000`.", 'tunnel_not_found');
            return;
        }
        if (!Store::isOnline($tunnel)) {
            $this->reject($tunnel, $uri, 503, 'Tunnel offline', "The computer serving \"$name\" is not connected right now.",
                'It was last seen ' . self::ago($tunnel['last_seen']) . '. Start tunnel.py on that computer again.', 'tunnel_offline');
            return;
        }
        if ($tunnel['auth'] !== null && !$this->authorized($tunnel['auth'])) {
            $this->reject($tunnel, $uri, 401, 'Sign in required', 'This tunnel is protected with a username and password.', null, 'unauthorized',
                ['WWW-Authenticate' => 'Basic realm="' . $name . '", charset="UTF-8"']);
            return;
        }
        // Other upgrades (like curl's h2c) are optional for the client; the request proceeds without.
        if (stripos((string)$req->header('upgrade'), 'websocket') !== false) {
            $this->reject($tunnel, $uri, 501, 'WebSockets are not supported', 'This tunnel server runs on plain PHP, which cannot hold WebSocket connections open.',
                'Regular requests, uploads, downloads, and streamed responses all work.', 'upgrade_unsupported');
            return;
        }

        $id = bin2hex(random_bytes(16));
        $body = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $this->config->maxUploadBytes ? null : $this->saveBody($id);
        if ($body === null) {
            $this->reject($tunnel, $uri, 413, 'Upload too large', 'This tunnel server accepts request bodies of up to '
                . intdiv($this->config->maxUploadBytes, 1024 * 1024) . ' MB.', null, 'body_too_large');
            return;
        }
        [$bodySize, $contentType] = $body;
        if ($bodySize === 0 && stripos((string)$req->header('transfer-encoding'), 'chunked') !== false) {
            // The web server did not pass the streamed body on to PHP; refuse rather than lose it.
            $this->store->deleteFiles($id);
            $this->reject($tunnel, $uri, 411, 'Length required', 'This server could not read a request body sent without a Content-Length.',
                null, 'length_required');
            return;
        }
        $this->store->enqueue($id, $name, $req->method, $uri, $this->forwardHeaders($tunnel, $prefix, $contentType), $bodySize, $req->clientIp);
        $this->relay($id, $tunnel, $prefix);
    }

    /**
     * Copies the request body into the spool.
     *
     * @return array{0: int, 1: ?string}|null body size, and the new Content-Type if the body was
     *         re-encoded; null when the body is over max_upload_mb
     */
    private function saveBody(string $id): ?array
    {
        $path = $this->store->bodyPath($id);
        $in = Store::open('php://input', 'rb');
        $out = Store::open($path, 'wb');
        // Bodies sent without a Content-Length (chunked) are checked while they are copied.
        $size = (int)stream_copy_to_stream($in, $out, $this->config->maxUploadBytes + 1);
        fclose($in);
        fclose($out);
        if ($size > $this->config->maxUploadBytes) {
            @unlink($path);
            return null;
        }
        // PHP may have consumed a form upload itself (enable_post_data_reading on); rebuild it.
        if ($size === 0 && ($_POST !== [] || $_FILES !== []) && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
            return Multipart::rebuild($path);
        }
        if ($size === 0) {
            @unlink($path);
        }
        return [$size, null];
    }

    /**
     * @param TunnelRow $tunnel
     * @return list<array{0: string, 1: string}>
     */
    private function forwardHeaders(array $tunnel, string $prefix, ?string $contentType): array
    {
        $req = $this->request;
        $drop = self::HOP_BY_HOP + [
            'content-length' => true, 'expect' => true,
            'x-forwarded-for' => true, 'x-forwarded-host' => true, 'x-forwarded-proto' => true, 'x-forwarded-prefix' => true,
        ];
        if ($tunnel['auth'] !== null) {
            // That password belongs to the tunnel, not to the app behind it.
            $drop['authorization'] = true;
        }
        foreach (explode(',', (string)$req->header('connection')) as $token) {
            if (($token = strtolower(trim($token))) !== '') {
                $drop[$token] = true;
            }
        }

        $headers = [];
        foreach ($req->headers as [$name, $value]) {
            $lower = strtolower($name);
            if (!isset($drop[$lower])) {
                $headers[] = [$name, $lower === 'content-type' && $contentType !== null ? $contentType : $value];
            }
        }
        $forwardedFor = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $prior = (string)$req->header('x-forwarded-for');
        if ($this->config->trustProxy && $prior !== '') {
            $forwardedFor = $prior . ', ' . $forwardedFor;
        }
        $headers[] = ['X-Forwarded-For', $forwardedFor];
        $headers[] = ['X-Forwarded-Host', $req->hostHeader];
        $headers[] = ['X-Forwarded-Proto', $req->scheme];
        if ($prefix !== '') {
            $headers[] = ['X-Forwarded-Prefix', $prefix];
        }
        return $headers;
    }

    /**
     * Waits for the client's answer and streams it to the visitor, then settles the request.
     *
     * @param TunnelRow $tunnel
     */
    private function relay(string $id, array $tunnel, string $prefix): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        $timeout = $this->config->responseTimeout;
        $target = $tunnel['target'];
        $state = 'failed';
        $status = 0;
        $error = null;
        $sentChunks = 0;
        $sentBytes = 0;
        $delay = 1000;

        while (($row = $this->store->request($id)) !== null) {
            $now = microtime(true);
            $progress = false;

            if ($row['state'] === 'queued') {
                if ($now - $row['created_at'] > self::PICKUP_TIMEOUT
                    && $this->store->transition($id, ['queued'], 'failed', 'The tunnel client did not pick up the request.')) {
                    $status = 504;
                    Response::page(504, 'Tunnel not responding', "The computer serving \"{$tunnel['name']}\" did not pick up this request.",
                        'Check that tunnel.py is still running there.', 'pickup_timeout');
                    break;
                }
            } elseif ($row['state'] === 'claimed') {
                if ($now - $row['updated_at'] > $timeout
                    && $this->store->transition($id, ['claimed'], 'failed', "No response within $timeout seconds.")) {
                    $status = 504;
                    Response::page(504, 'Gateway timeout', "$target did not answer within $timeout seconds.", null, 'response_timeout');
                    break;
                }
            } elseif ($row['state'] === 'failed') {
                $current = $this->store->tunnel($tunnel['name']);
                if ($current === null || $current['closed_reason'] !== null) {
                    $status = 503;
                    Response::page(503, 'Tunnel closed', "The tunnel \"{$tunnel['name']}\" was closed while this request waited.", null, 'tunnel_closed');
                } else {
                    $status = 502;
                    Response::page(502, 'Bad gateway', (string)$row['error'], "Make sure your app is running and reachable at $target.", 'local_unreachable');
                }
                break;
            } elseif ($row['state'] === 'streaming') {
                if ($status === 0) {
                    $status = $this->sendHead($row, $tunnel, $prefix);
                }
                while ($sentChunks < $row['res_chunks']) {
                    $file = $this->store->chunkPath($id, $sentChunks);
                    $sentBytes += (int)@readfile($file);
                    @unlink($file);
                    flush();
                    $sentChunks++;
                    $progress = true;
                    if (connection_aborted()) {
                        $state = 'abandoned';
                        $this->store->transition($id, ['streaming'], 'abandoned');
                        break 2;
                    }
                }
                if ($progress) {
                    $this->store->recordSent($id, $sentBytes);
                }
                if ($row['res_end'] === 1 && $sentChunks >= $row['res_chunks']) {
                    $state = 'done';
                    break;
                }
                if (!$progress && $now - $row['updated_at'] > $timeout) {
                    $error = "The response stalled for $timeout seconds.";
                    $this->store->transition($id, ['streaming'], 'failed', $error);
                    break;
                }
            } else {
                break;
            }

            // Poll quickly right after progress, then back off while nothing happens.
            $delay = $progress ? 1000 : min(25000, (int)($delay * 1.5));
            usleep($delay);
        }

        $this->store->finish($id, $state, $status, $sentBytes, $error);
    }

    /**
     * Sends the status line and headers of the local app's response.
     *
     * @param RequestRow $row
     * @param TunnelRow $tunnel
     */
    private function sendHead(array $row, array $tunnel, string $prefix): int
    {
        $status = $row['status'] ?? 502;
        if ($status < 200 || $status > 999) {
            $status = 502;
        }
        header_remove();
        foreach (json_decode((string)$row['res_headers'], true) ?: [] as $header) {
            if (!is_array($header) || count($header) !== 2) {
                continue;
            }
            $name = (string)$header[0];
            $value = (string)$header[1];
            $lower = strtolower($name);
            // "Status" would override the status line under CGI and FastCGI.
            if (isset(self::HOP_BY_HOP[$lower]) || $lower === 'status' || !self::isSafeHeader($name, $value)) {
                continue;
            }
            if ($lower === 'location') {
                $value = $this->rewriteLocation($value, $tunnel, $prefix);
            } elseif ($lower === 'set-cookie') {
                $value = $this->rewriteCookie($value, $prefix);
            }
            header("$name: $value", false);
        }
        // Keeps nginx from buffering, so streamed responses reach the visitor as they are produced.
        header('X-Accel-Buffering: no');
        // Set last: header('Location: ...') would otherwise turn a 200 into a 302.
        http_response_code($status);
        flush();
        return $status;
    }

    /**
     * Points redirects at the public address instead of the local one.
     *
     * @param TunnelRow $tunnel
     */
    private function rewriteLocation(string $location, array $tunnel, string $prefix): string
    {
        $public = $this->request->scheme . '://' . $this->request->hostHeader . $prefix;
        $url = parse_url($location);
        $target = parse_url($tunnel['target']);
        if (is_array($url) && isset($url['scheme'], $url['host']) && is_array($target) && isset($target['host'])) {
            $port = $url['port'] ?? (strtolower($url['scheme']) === 'https' ? 443 : 80);
            $targetPort = $target['port'] ?? (($target['scheme'] ?? 'http') === 'https' ? 443 : 80);
            $host = strtolower($url['host']);
            $targetHost = strtolower($target['host']);
            if ($port === $targetPort && ($host === $targetHost || (self::isLoopback($host) && self::isLoopback($targetHost)))) {
                return $public . ($url['path'] ?? '/')
                    . (isset($url['query']) ? '?' . $url['query'] : '')
                    . (isset($url['fragment']) ? '#' . $url['fragment'] : '');
            }
        }
        if ($prefix !== '' && str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return $prefix . $location;
        }
        return $location;
    }

    /**
     * Drops a cookie Domain the browser would reject on the public host (localhost, say), and
     * under a path prefix scopes the cookie to its tunnel.
     */
    private function rewriteCookie(string $cookie, string $prefix): string
    {
        $host = $this->request->host;
        $cookie = preg_replace_callback('/;\s*domain\s*=\s*([^;]*)/i', static function (array $m) use ($host): string {
            $domain = strtolower(ltrim(trim($m[1]), '.'));
            return $host === $domain || str_ends_with($host, '.' . $domain) ? $m[0] : '';
        }, $cookie) ?? $cookie;
        if ($prefix !== '') {
            $cookie = preg_replace_callback('/;\s*path\s*=\s*([^;]*)/i', static function (array $m) use ($prefix): string {
                $path = trim($m[1]);
                return '; Path=' . $prefix . (str_starts_with($path, '/') ? $path : '/');
            }, $cookie) ?? $cookie;
        }
        return $cookie;
    }

    private function authorized(string $expected): bool
    {
        if (!preg_match('/^Basic\s+(\S+)/i', (string)$this->request->header('authorization'), $m)) {
            return false;
        }
        $credentials = base64_decode($m[1], true);
        return $credentials !== false && hash_equals($expected, $this->config->basicAuthHash($credentials));
    }

    /**
     * Answers without involving the client, and records the request in the log.
     *
     * @param TunnelRow $tunnel
     * @param array<string, string> $headers
     */
    private function reject(array $tunnel, string $uri, int $status, string $title, string $message, ?string $hint, string $kind, array $headers = []): void
    {
        $this->store->logRejected($tunnel['name'], $this->request->method, $uri, $this->request->clientIp, $status, $title);
        Response::page($status, $title, $message, $hint, $kind, $headers);
    }

    private static function isSafeHeader(string $name, string $value): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) === 1 && strpbrk($value, "\r\n\0") === false;
    }

    private static function isLoopback(string $host): bool
    {
        return $host === 'localhost' || $host === '[::1]' || $host === '::1' || str_starts_with($host, '127.');
    }

    private static function ago(float $time): string
    {
        $seconds = max(0, (int)(microtime(true) - $time));
        return match (true) {
            $seconds < 90 => "$seconds seconds ago",
            $seconds < 5400 => round($seconds / 60) . ' minutes ago',
            $seconds < 129600 => round($seconds / 3600) . ' hours ago',
            default => round($seconds / 86400) . ' days ago',
        };
    }
}
