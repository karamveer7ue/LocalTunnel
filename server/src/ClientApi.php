<?php

declare(strict_types=1);

namespace Tunnel;

/**
 * The API that tunnel.py talks to. Every call carries the shared token in X-Tunnel-Token.
 *
 * @phpstan-import-type RequestRow from Store
 */
final class ClientApi
{
    /** How long a poll waits for work before answering empty-handed. */
    private const POLL_SECONDS = 25;
    private const POLL_BATCH = 32;
    /** Request bodies up to this size travel inside the poll answer instead of a separate download. */
    private const INLINE_BODY = 256 * 1024;
    private const INLINE_TOTAL = 2 * 1024 * 1024;
    /** Response data piled up for a slow visitor beyond this pauses the client's uploads. */
    private const BACKLOG_LIMIT = 8 * 1024 * 1024;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Request $request,
        private readonly Links $links,
    ) {
    }

    public function handle(string $action): void
    {
        if (!hash_equals($this->config->token, (string)$this->request->header('x-tunnel-token'))) {
            usleep(300000);
            Response::json(401, ['error' => 'The server rejected the token. Check it with: python3 tunnel.py login <server-url> <token>']);
            return;
        }
        $protocol = (int)$this->request->header('x-tunnel-protocol');
        if ($protocol !== App::PROTOCOL) {
            Response::json(400, ['error' => sprintf(
                'This server speaks tunnel protocol %d but this tunnel.py speaks %d. Use the tunnel.py that comes with server version %s.',
                App::PROTOCOL, $protocol, App::VERSION,
            )]);
            return;
        }
        match ([$this->request->method, $action]) {
            ['GET', 'ping'] => Response::json(200, ['version' => App::VERSION, 'url' => $this->links->app(), 'subdomains' => $this->config->baseDomain]),
            ['POST', 'register'] => $this->register(),
            ['GET', 'poll'] => $this->poll(),
            ['GET', 'body'] => $this->body(),
            ['POST', 'respond'] => $this->respond(),
            ['POST', 'unregister'] => $this->unregister(),
            default => Response::json(404, ['error' => "Unknown call: {$this->request->method} $action"]),
        };
    }

    private function register(): void
    {
        $body = self::jsonBody();
        $clientId = (string)($body['client_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientId)) {
            Response::json(400, ['error' => 'client_id is missing or malformed.']);
            return;
        }
        $clientName = substr(trim(preg_replace('/[^\x20-\x7E]/', '', (string)($body['client_name'] ?? '')) ?? ''), 0, 64) ?: 'unknown';

        $results = [];
        foreach ((array)($body['tunnels'] ?? []) as $spec) {
            $spec = (array)$spec;
            $name = strtolower(trim((string)($spec['name'] ?? '')));
            $target = substr(trim((string)($spec['target'] ?? '')), 0, 200);
            $auth = (string)($spec['auth'] ?? '');
            $error = null;
            $replaced = null;
            if ($name === '') {
                do {
                    $name = Names::random();
                } while ($this->store->tunnel($name) !== null);
            } elseif (!Names::isValid($name)) {
                $error = "\"$name\" is not a valid tunnel name. Use lowercase letters, digits, and dashes (at most 63).";
            } elseif (Names::isReserved($name)) {
                $error = "\"$name\" is reserved for the server itself.";
            }
            if ($error === null) {
                $existing = $this->store->tunnel($name);
                if ($existing !== null && $existing['client_id'] !== $clientId && Store::isOnline($existing)) {
                    $replaced = $existing['client_name'];
                }
                $this->store->registerTunnel($name, $clientId, $clientName, $this->request->clientIp, $target,
                    $auth === '' ? null : $this->config->basicAuthHash($auth));
            }
            $results[] = [
                'name' => $name,
                'urls' => $error === null ? $this->links->tunnel($name) : [],
                'replaced' => $replaced,
                'error' => $error,
            ];
        }
        Response::json(200, ['tunnels' => $results, 'server' => ['version' => App::VERSION]]);
    }

    /**
     * Long poll: answers as soon as requests are waiting for the client's tunnels, when one of
     * them changed on the server's side, or after POLL_SECONDS with nothing to do.
     */
    private function poll(): void
    {
        $clientId = (string)($_GET['client'] ?? '');
        $names = array_values(array_filter(explode(',', (string)($_GET['tunnels'] ?? '')), [Names::class, 'isValid']));
        ignore_user_abort(true);
        @set_time_limit(0);
        $this->store->collectGarbage();

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        // A worker of PHP's built-in server may accept a second connection while it reads the
        // first, and that one then waits until the first finishes. Short polls keep such waits
        // brief there. Production servers (PHP-FPM, Apache) hand each worker one connection.
        $deadline = microtime(true) + (PHP_SAPI === 'cli-server' ? 1 : self::POLL_SECONDS);
        $nextHeartbeat = 0.0;
        $nextCheck = 0.0;
        while (true) {
            $now = microtime(true);
            if ($now >= $nextHeartbeat) {
                // Leading whitespace is valid JSON. It keeps proxies from timing the poll out and
                // reveals a client that hung up, so we stop claiming requests on its behalf.
                echo ' ';
                flush();
                if (connection_aborted()) {
                    return;
                }
                $nextHeartbeat = $now + 1;
            }
            if ($now >= $nextCheck) {
                $this->store->touch($clientId, $this->request->clientIp);
                $status = $this->store->tunnelStatus($clientId, $names);
                if ($status['gone'] !== [] || $status['missing'] !== []) {
                    $this->answerPoll([], $status);
                    return;
                }
                $nextCheck = $now + 5;
            }
            $claimed = $this->store->claim($clientId, self::POLL_BATCH);
            if ($claimed !== [] || $now >= $deadline) {
                $this->answerPoll($claimed, ['gone' => [], 'missing' => []]);
                return;
            }
            usleep(20000);
        }
    }

    /**
     * @param list<RequestRow> $claimed
     * @param array{gone: array<string, string>, missing: list<string>} $status
     */
    private function answerPoll(array $claimed, array $status): void
    {
        $requests = [];
        $inlineBudget = self::INLINE_TOTAL;
        foreach ($claimed as $row) {
            $size = (int)$row['body_size'];
            $item = [
                'id' => $row['id'],
                'tunnel' => $row['tunnel'],
                'method' => $row['method'],
                'uri' => $row['uri'],
                'headers' => json_decode($row['headers'], true),
                'body_size' => $size,
            ];
            if ($size > 0 && $size <= self::INLINE_BODY && $size <= $inlineBudget) {
                $data = @file_get_contents($this->store->bodyPath($row['id']));
                if ($data !== false) {
                    $item['body'] = base64_encode($data);
                    $inlineBudget -= $size;
                }
            }
            $requests[] = $item;
        }
        echo json_encode(
            ['requests' => $requests, 'gone' => (object)$status['gone'], 'missing' => $status['missing']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function body(): void
    {
        $id = (string)($_GET['id'] ?? '');
        $row = self::isId($id) ? $this->store->request($id) : null;
        $path = $this->store->bodyPath($id);
        if ($row === null || $row['state'] !== 'claimed' || !is_file($path)) {
            Response::json(410, ['error' => 'This request is no longer waiting.']);
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    /**
     * Receives the local app's response, in order, one piece per call:
     *   ?id=&seq=N[&head=LEN][&end=1]
     * The first call's body starts with LEN bytes of JSON ({"status", "headers"} or {"error"});
     * the rest of every body is response data. seq numbers the data pieces, so a retried call
     * is recognized and not stored twice.
     */
    private function respond(): void
    {
        $id = (string)($_GET['id'] ?? '');
        $seq = (int)($_GET['seq'] ?? 0);
        $headLength = (int)($_GET['head'] ?? 0);
        $end = ($_GET['end'] ?? '') === '1';
        $row = self::isId($id) ? $this->store->request($id) : null;
        if ($row === null || in_array($row['state'], Store::FINAL_STATES, true)) {
            $this->gone();
            return;
        }

        $in = Store::open('php://input', 'rb');
        if ($headLength > 0) {
            $head = json_decode((string)stream_get_contents($in, $headLength), true);
            if (!is_array($head)) {
                Response::json(400, ['error' => 'Malformed response head.']);
                return;
            }
            if (isset($head['error'])) {
                $this->store->transition($id, ['claimed'], 'failed', substr((string)$head['error'], 0, 500));
                Response::json(200, ['ok' => true]);
                return;
            }
            if ($row['state'] === 'claimed') {
                $headers = json_encode(is_array($head['headers'] ?? null) ? $head['headers'] : [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $this->store->startResponse($id, (int)($head['status'] ?? 502), $headers);
                $row = $this->store->request($id);
            }
        }
        if ($row === null || in_array($row['state'], Store::FINAL_STATES, true)) {
            $this->gone();
            return;
        }
        if ($row['state'] !== 'streaming') {
            Response::json(409, ['error' => 'The response head has to come first.']);
            return;
        }
        if ($seq < (int)$row['res_chunks']) {
            // A retry of a piece that already arrived.
            Response::json(200, ['ok' => true]);
            return;
        }
        if ($seq > (int)$row['res_chunks']) {
            Response::json(409, ['error' => 'Response pieces arrived out of order.']);
            return;
        }

        // Backpressure: let a slow visitor catch up before taking more data.
        $giveUp = microtime(true) + 20;
        while ((int)$row['res_bytes'] - (int)$row['sent_bytes'] > self::BACKLOG_LIMIT) {
            if (microtime(true) > $giveUp) {
                header('Retry-After: 1');
                Response::json(503, ['error' => 'The visitor is downloading slowly; try again shortly.']);
                return;
            }
            usleep(100000);
            $row = $this->store->request($id);
            if ($row === null || in_array($row['state'], Store::FINAL_STATES, true)) {
                $this->gone();
                return;
            }
        }

        $chunk = $this->store->chunkPath($id, $seq);
        $part = $chunk . '.' . bin2hex(random_bytes(4)) . '.part';
        $out = Store::open($part, 'wb');
        $bytes = (int)stream_copy_to_stream($in, $out);
        fclose($out);
        if ($bytes === 0) {
            @unlink($part);
            if ($end) {
                $this->store->endResponse($id);
            }
            Response::json(200, ['ok' => true]);
            return;
        }
        if (!@rename($part, $chunk)) {
            // The request ended while this piece arrived, and its files were cleaned up.
            @unlink($part);
            $this->gone();
            return;
        }
        if (!$this->store->commitChunk($id, $seq, $bytes, $end)) {
            // Either the request ended meanwhile, or a retry of this same piece committed it
            // first. Only in the first case is the file unwanted: in the second it holds the
            // committed (identical) data.
            $row = $this->store->request($id);
            if ($row === null || in_array($row['state'], Store::FINAL_STATES, true)) {
                @unlink($chunk);
                $this->gone();
                return;
            }
        }
        Response::json(200, ['ok' => true]);
    }

    private function unregister(): void
    {
        $body = self::jsonBody();
        $names = array_values(array_filter(array_map('strval', (array)($body['tunnels'] ?? [])), [Names::class, 'isValid']));
        $this->store->unregister((string)($body['client_id'] ?? ''), $names);
        Response::json(200, ['ok' => true]);
    }

    /** The visitor left or the request was settled; the client should stop sending. */
    private function gone(): void
    {
        Response::json(410, ['error' => 'This request is no longer waiting for a response.']);
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $id) === 1;
    }

    /** @return array<mixed> */
    private static function jsonBody(): array
    {
        $data = json_decode((string)file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }
}
