<?php

declare(strict_types=1);

namespace Tunnel;

use PDO;
use PDOException;

/**
 * Persistent state. Tunnels and in-flight requests live in SQLite; request and response
 * bodies live as files in the spool directory so they are streamed, never held in memory.
 *
 * A request moves queued -> claimed (the client picked it up) -> streaming (the response
 * head arrived) -> done. It ends as failed instead when it times out or the local app is
 * unreachable, and as abandoned when the visitor disconnects first.
 *
 * @phpstan-type TunnelRow array{
 *     name: string, client_id: string, client_name: string, client_ip: string, target: string,
 *     auth: ?string, created_at: float, last_seen: float, closed_reason: ?string,
 *     requests: int, bytes_in: int, bytes_out: int,
 * }
 * @phpstan-type RequestRow array{
 *     id: string, tunnel: string, state: string, method: string, uri: string, headers: string,
 *     body_size: int, client_ip: string, created_at: float, updated_at: float, claimed_at: ?float,
 *     status: ?int, res_headers: ?string, res_chunks: int, res_bytes: int, res_end: int,
 *     sent_bytes: int, error: ?string, duration_ms: ?int,
 * }
 * @phpstan-type RequestSummary array{
 *     id: string, tunnel: string, state: string, method: string, uri: string, client_ip: string,
 *     created_at: float, status: ?int, duration_ms: ?int, sent_bytes: int, body_size: int, error: ?string,
 * }
 */
final class Store
{
    /** A tunnel whose client has not polled for this many seconds is offline. */
    public const OFFLINE_AFTER = 45;

    public const ACTIVE_STATES = ['queued', 'claimed', 'streaming'];
    public const FINAL_STATES = ['done', 'failed', 'abandoned'];

    private const SCHEMA_VERSION = 1;
    private const KEEP_FINISHED_REQUESTS = 1000;
    private const GC_INTERVAL = 60;

    public readonly string $spoolDir;
    private PDO $db;

    public function __construct(string $dataDir)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new StoreError('PHP is missing the pdo_sqlite extension. Enable it in your hosting control panel or php.ini.');
        }
        $this->spoolDir = $dataDir . '/spool';
        foreach ([$dataDir, $this->spoolDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new StoreError("Could not create $dir. Create it and make it writable by PHP.");
            }
            if (!is_writable($dir)) {
                throw new StoreError("PHP cannot write to $dir. Make it writable by the web server user.");
            }
        }
        self::protect($dataDir);
        try {
            $this->db = new PDO('sqlite:' . $dataDir . '/tunnel.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 15,
            ]);
            $this->db->exec('PRAGMA busy_timeout = 15000');
            $this->db->exec('PRAGMA synchronous = NORMAL');
            $this->migrate();
        } catch (PDOException $e) {
            throw new StoreError("Could not open the database in $dataDir: " . $e->getMessage());
        }
    }

    public function journalMode(): string
    {
        return strtolower((string)$this->value('PRAGMA journal_mode'));
    }

    // ----- Tunnels -------------------------------------------------------------------------

    /**
     * @return TunnelRow|null
     * @phpstan-impure other processes change the database between calls
     */
    public function tunnel(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tunnels WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return is_array($row) ? self::tunnelRow($row) : null;
    }

    /** @param TunnelRow $tunnel */
    public static function isOnline(array $tunnel): bool
    {
        return $tunnel['closed_reason'] === null && microtime(true) - (float)$tunnel['last_seen'] < self::OFFLINE_AFTER;
    }

    /** Points $name at the given client. A different client that served the name loses it. */
    public function registerTunnel(string $name, string $clientId, string $clientName, string $clientIp, string $target, ?string $auth): void
    {
        $this->transaction(function () use ($name, $clientId, $clientName, $clientIp, $target, $auth): void {
            $now = microtime(true);
            $existing = $this->tunnel($name);
            if ($existing === null) {
                $this->db->prepare(
                    'INSERT INTO tunnels (name, client_id, client_name, client_ip, target, auth, created_at, last_seen)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$name, $clientId, $clientName, $clientIp, $target, $auth, $now, $now]);
                return;
            }
            // Re-registering after a dropped connection keeps the tunnel's history.
            $same = $existing['client_id'] === $clientId && $existing['closed_reason'] === null;
            $this->db->prepare(
                'UPDATE tunnels SET client_id = ?, client_name = ?, client_ip = ?, target = ?, auth = ?, last_seen = ?,
                    closed_reason = NULL, created_at = ?, requests = ?, bytes_in = ?, bytes_out = ?
                 WHERE name = ?'
            )->execute([
                $clientId, $clientName, $clientIp, $target, $auth, $now,
                $same ? $existing['created_at'] : $now,
                $same ? $existing['requests'] : 0,
                $same ? $existing['bytes_in'] : 0,
                $same ? $existing['bytes_out'] : 0,
                $name,
            ]);
        });
    }

    /** @param list<string> $names */
    public function unregister(string $clientId, array $names): void
    {
        if ($names === []) {
            return;
        }
        $this->transaction(function () use ($clientId, $names): void {
            $in = self::placeholders($names);
            $stmt = $this->db->prepare("SELECT name FROM tunnels WHERE client_id = ? AND name IN ($in)");
            $stmt->execute([$clientId, ...$names]);
            $owned = self::column($stmt);
            if ($owned === []) {
                return;
            }
            $in = self::placeholders($owned);
            $this->db->prepare("DELETE FROM tunnels WHERE name IN ($in)")->execute($owned);
            $this->failQueued($owned, 'The tunnel was closed.');
        });
    }

    /** Closes a tunnel on the server's side; its client learns about it on the next poll. */
    public function close(string $name, string $reason): bool
    {
        return $this->transaction(function () use ($name, $reason): bool {
            $stmt = $this->db->prepare('UPDATE tunnels SET closed_reason = ? WHERE name = ? AND closed_reason IS NULL');
            $stmt->execute([$reason, $name]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $this->failQueued([$name], 'The tunnel was closed.');
            return true;
        });
    }

    /** Marks the client's tunnels as online. */
    public function touch(string $clientId, string $clientIp): void
    {
        $this->db->prepare('UPDATE tunnels SET last_seen = ?, client_ip = ? WHERE client_id = ? AND closed_reason IS NULL')
            ->execute([microtime(true), $clientIp, $clientId]);
    }

    /**
     * Compares the tunnels a client believes it serves with what the server knows.
     *
     * @param list<string> $names
     * @return array{gone: array<string, string>, missing: list<string>} names the client must
     *         stop serving (with the reason), and names the server has forgotten
     */
    public function tunnelStatus(string $clientId, array $names): array
    {
        $gone = [];
        $missing = [];
        if ($names === []) {
            return ['gone' => $gone, 'missing' => $missing];
        }
        $stmt = $this->db->prepare('SELECT name, client_id, client_name, closed_reason FROM tunnels WHERE name IN (' . self::placeholders($names) . ')');
        $stmt->execute($names);
        $rows = [];
        foreach ($stmt as $row) {
            $rows[$row['name']] = $row;
        }
        foreach ($names as $name) {
            $row = $rows[$name] ?? null;
            if ($row === null) {
                $missing[] = $name;
            } elseif ($row['client_id'] !== $clientId) {
                $gone[$name] = 'now served by ' . $row['client_name'];
            } elseif ($row['closed_reason'] !== null) {
                $gone[$name] = $row['closed_reason'];
            }
        }
        return ['gone' => $gone, 'missing' => $missing];
    }

    /**
     * Open tunnels, oldest first.
     *
     * @return list<TunnelRow>
     */
    public function tunnels(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tunnels WHERE closed_reason IS NULL ORDER BY created_at');
        $stmt->execute();
        $tunnels = [];
        foreach ($stmt as $row) {
            $tunnels[] = self::tunnelRow($row);
        }
        return $tunnels;
    }

    /** @return array<string, int> number of requests in flight, by tunnel */
    public function activeCounts(): array
    {
        $stmt = $this->db->prepare("SELECT tunnel, COUNT(*) FROM requests WHERE state IN ('queued', 'claimed', 'streaming') GROUP BY tunnel");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // ----- Requests ------------------------------------------------------------------------

    /** @param list<array{0: string, 1: string}> $headers */
    public function enqueue(string $id, string $tunnel, string $method, string $uri, array $headers, int $bodySize, string $clientIp): void
    {
        $now = microtime(true);
        $this->db->prepare(
            "INSERT INTO requests (id, tunnel, state, method, uri, headers, body_size, client_ip, created_at, updated_at)
             VALUES (?, ?, 'queued', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $tunnel, $method, $uri, json_encode($headers, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $bodySize, $clientIp, $now, $now]);
    }

    /** Records a request that was answered without reaching the client, for the request log. */
    public function logRejected(string $tunnel, string $method, string $uri, string $clientIp, int $status, string $error): void
    {
        $now = microtime(true);
        $this->transaction(function () use ($tunnel, $method, $uri, $clientIp, $status, $error, $now): void {
            $this->db->prepare(
                "INSERT INTO requests (id, tunnel, state, method, uri, headers, body_size, client_ip, created_at, updated_at, status, error, duration_ms)
                 VALUES (?, ?, 'failed', ?, ?, '[]', 0, ?, ?, ?, ?, ?, 0)"
            )->execute([bin2hex(random_bytes(16)), $tunnel, $method, $uri, $clientIp, $now, $now, $status, $error]);
            $this->db->prepare('UPDATE tunnels SET requests = requests + 1 WHERE name = ?')->execute([$tunnel]);
        });
    }

    /**
     * @return RequestRow|null
     * @phpstan-impure other processes change the database between calls
     */
    public function request(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? self::requestRow($row) : null;
    }

    /**
     * Hands up to $limit queued requests for the client's tunnels to the client.
     *
     * @return list<RequestRow>
     */
    public function claim(string $clientId, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.id FROM requests r JOIN tunnels t ON t.name = r.tunnel
             WHERE r.state = 'queued' AND t.client_id = ? AND t.closed_reason IS NULL
             ORDER BY r.created_at LIMIT ?"
        );
        $stmt->bindValue(1, $clientId);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = self::column($stmt);
        if ($ids === []) {
            return [];
        }
        return $this->transaction(function () use ($ids): array {
            $now = microtime(true);
            $update = $this->db->prepare("UPDATE requests SET state = 'claimed', claimed_at = ?, updated_at = ? WHERE id = ? AND state = 'queued'");
            $claimed = [];
            foreach ($ids as $id) {
                $update->execute([$now, $now, $id]);
                if ($update->rowCount() === 1 && ($row = $this->request($id)) !== null) {
                    $claimed[] = $row;
                }
            }
            return $claimed;
        });
    }

    /**
     * Moves a request into state $to if it is currently in one of $from.
     *
     * @param list<string> $from
     */
    public function transition(string $id, array $from, string $to, ?string $error = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE requests SET state = ?, error = COALESCE(?, error), updated_at = ? WHERE id = ? AND state IN (' . self::placeholders($from) . ')'
        );
        $stmt->execute([$to, $error, microtime(true), $id, ...$from]);
        return $stmt->rowCount() === 1;
    }

    public function startResponse(string $id, int $status, string $headersJson): bool
    {
        $stmt = $this->db->prepare("UPDATE requests SET state = 'streaming', status = ?, res_headers = ?, updated_at = ? WHERE id = ? AND state = 'claimed'");
        $stmt->execute([$status, $headersJson, microtime(true), $id]);
        return $stmt->rowCount() === 1;
    }

    /** Publishes response chunk $seq, whose file is already in place. Chunks must arrive in order. */
    public function commitChunk(string $id, int $seq, int $bytes, bool $end): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE requests SET res_chunks = res_chunks + 1, res_bytes = res_bytes + ?, res_end = MAX(res_end, ?), updated_at = ?
             WHERE id = ? AND state = 'streaming' AND res_chunks = ?"
        );
        $stmt->execute([$bytes, $end ? 1 : 0, microtime(true), $id, $seq]);
        return $stmt->rowCount() === 1;
    }

    public function endResponse(string $id): void
    {
        $this->db->prepare("UPDATE requests SET res_end = 1, updated_at = ? WHERE id = ? AND state = 'streaming'")
            ->execute([microtime(true), $id]);
    }

    /** Remembers how much of the response reached the visitor, which drives backpressure. */
    public function recordSent(string $id, int $bytes): void
    {
        $this->db->prepare('UPDATE requests SET sent_bytes = ? WHERE id = ?')->execute([$bytes, $id]);
    }

    /** Settles a request for good and adds it to its tunnel's totals. */
    public function finish(string $id, string $state, int $status, int $sentBytes, ?string $error): void
    {
        $this->transaction(function () use ($id, $state, $status, $sentBytes, $error): void {
            $row = $this->request($id);
            if ($row === null) {
                return;
            }
            $now = microtime(true);
            $this->db->prepare(
                'UPDATE requests SET state = ?, status = ?, sent_bytes = ?, error = COALESCE(?, error), duration_ms = ?, updated_at = ? WHERE id = ?'
            )->execute([$state, $status, $sentBytes, $error, (int)round(($now - (float)$row['created_at']) * 1000), $now, $id]);
            $this->db->prepare('UPDATE tunnels SET requests = requests + 1, bytes_in = bytes_in + ?, bytes_out = bytes_out + ? WHERE name = ?')
                ->execute([(int)$row['body_size'], $sentBytes, $row['tunnel']]);
        });
        $this->deleteFiles($id);
    }

    /** @return list<RequestSummary> */
    public function recentRequests(int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tunnel, state, method, uri, client_ip, created_at, status, duration_ms, sent_bytes, body_size, error
             FROM requests ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $requests = [];
        foreach ($stmt as $row) {
            $requests[] = [
                'id' => (string)$row['id'],
                'tunnel' => (string)$row['tunnel'],
                'state' => (string)$row['state'],
                'method' => (string)$row['method'],
                'uri' => (string)$row['uri'],
                'client_ip' => (string)$row['client_ip'],
                'created_at' => (float)$row['created_at'],
                'status' => $row['status'] === null ? null : (int)$row['status'],
                'duration_ms' => $row['duration_ms'] === null ? null : (int)$row['duration_ms'],
                'sent_bytes' => (int)$row['sent_bytes'],
                'body_size' => (int)$row['body_size'],
                'error' => $row['error'] === null ? null : (string)$row['error'],
            ];
        }
        return $requests;
    }

    // ----- Spool files ---------------------------------------------------------------------

    public function bodyPath(string $id): string
    {
        return $this->spoolDir . '/' . $id . '.body';
    }

    public function chunkPath(string $id, int $seq): string
    {
        return $this->spoolDir . '/' . $id . '.' . $seq;
    }

    /**
     * Opens a spool file, turning failure (a full disk, lost permissions) into a clear error.
     *
     * @return resource
     * @throws StoreError
     */
    public static function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new StoreError("Could not open $path. The disk may be full, or PHP may no longer be allowed to write there.");
        }
        return $handle;
    }

    public function deleteFiles(string $id): void
    {
        foreach (glob($this->spoolDir . '/' . $id . '.*') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ----- Housekeeping --------------------------------------------------------------------

    /** Cleans up after crashed workers and trims history. Cheap to call often; works once a minute. */
    public function collectGarbage(): void
    {
        $now = microtime(true);
        $last = (float)$this->value("SELECT value FROM meta WHERE key = 'gc_at'");
        if ($now - $last < self::GC_INTERVAL) {
            return;
        }
        $claimed = $this->transaction(function () use ($now): bool {
            $last = (float)$this->value("SELECT value FROM meta WHERE key = 'gc_at'");
            if ($now - $last < self::GC_INTERVAL) {
                return false;
            }
            $this->db->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('gc_at', ?)")->execute([(string)$now]);
            return true;
        });
        if (!$claimed) {
            return;
        }

        // A request still open after an hour without progress lost its PHP worker (killed by a timeout).
        $stale = $this->db->prepare("SELECT id FROM requests WHERE state IN ('queued', 'claimed', 'streaming') AND updated_at < ?");
        $stale->execute([$now - 3600]);
        foreach (self::column($stale) as $id) {
            $this->transition($id, self::ACTIVE_STATES, 'failed', 'The server stopped waiting for this request.');
            $this->deleteFiles($id);
        }

        $this->transaction(function () use ($now): void {
            $this->db->prepare(
                "DELETE FROM requests WHERE state IN ('done', 'failed', 'abandoned') AND id NOT IN
                    (SELECT id FROM requests WHERE state IN ('done', 'failed', 'abandoned') ORDER BY created_at DESC LIMIT ?)"
            )->execute([self::KEEP_FINISHED_REQUESTS]);
            // Offline tunnels are forgotten after a day. Closed ones are remembered for a week so a
            // client waking from sleep learns it was closed instead of quietly re-registering.
            $this->db->prepare('DELETE FROM tunnels WHERE (closed_reason IS NULL AND last_seen < ?) OR last_seen < ?')
                ->execute([$now - 86400, $now - 7 * 86400]);
        });

        // Spool files are consumed within moments; old ones were left behind by crashed workers.
        foreach (scandir($this->spoolDir) ?: [] as $file) {
            $path = $this->spoolDir . '/' . $file;
            if ($file[0] !== '.' && is_file($path) && (int)@filemtime($path) < $now - 6 * 3600) {
                @unlink($path);
            }
        }
    }

    // ----- Internals -----------------------------------------------------------------------

    /** @param list<string> $tunnels */
    private function failQueued(array $tunnels, string $error): void
    {
        $this->db->prepare(
            "UPDATE requests SET state = 'failed', error = ?, updated_at = ? WHERE state = 'queued' AND tunnel IN (" . self::placeholders($tunnels) . ')'
        )->execute([$error, microtime(true), ...$tunnels]);
    }

    /**
     * Runs $fn in a write transaction. BEGIN IMMEDIATE takes the write lock up front, so
     * concurrent workers wait their turn (busy_timeout) instead of failing with SQLITE_BUSY.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private function migrate(): void
    {
        if ((int)$this->value('PRAGMA user_version') >= self::SCHEMA_VERSION) {
            return;
        }
        // WAL lets the many waiting requests read while another one writes. Some network file
        // systems cannot do WAL; SQLite then keeps its default journal, which still works.
        try {
            $this->db->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException) {
        }
        $this->transaction(function (): void {
            if ((int)$this->value('PRAGMA user_version') >= self::SCHEMA_VERSION) {
                return;
            }
            $this->db->exec(<<<'SQL'
                CREATE TABLE tunnels (
                    name          TEXT PRIMARY KEY,
                    client_id     TEXT NOT NULL,
                    client_name   TEXT NOT NULL,
                    client_ip     TEXT NOT NULL,
                    target        TEXT NOT NULL,
                    auth          TEXT,
                    created_at    REAL NOT NULL,
                    last_seen     REAL NOT NULL,
                    closed_reason TEXT,
                    requests      INTEGER NOT NULL DEFAULT 0,
                    bytes_in      INTEGER NOT NULL DEFAULT 0,
                    bytes_out     INTEGER NOT NULL DEFAULT 0
                );
                CREATE INDEX tunnels_client ON tunnels (client_id);

                CREATE TABLE requests (
                    id          TEXT PRIMARY KEY,
                    tunnel      TEXT NOT NULL,
                    state       TEXT NOT NULL,
                    method      TEXT NOT NULL,
                    uri         TEXT NOT NULL,
                    headers     TEXT NOT NULL,
                    body_size   INTEGER NOT NULL,
                    client_ip   TEXT NOT NULL,
                    created_at  REAL NOT NULL,
                    updated_at  REAL NOT NULL,
                    claimed_at  REAL,
                    status      INTEGER,
                    res_headers TEXT,
                    res_chunks  INTEGER NOT NULL DEFAULT 0,
                    res_bytes   INTEGER NOT NULL DEFAULT 0,
                    res_end     INTEGER NOT NULL DEFAULT 0,
                    sent_bytes  INTEGER NOT NULL DEFAULT 0,
                    error       TEXT,
                    duration_ms INTEGER
                );
                CREATE INDEX requests_state ON requests (state, tunnel, created_at);
                CREATE INDEX requests_created ON requests (created_at);

                CREATE TABLE meta (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                SQL);
            $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        });
    }

    /** Keeps the web server from serving the data directory if it sits inside the web root. */
    private static function protect(string $dataDir): void
    {
        $htaccess = $dataDir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($dataDir . '/index.html')) {
            @file_put_contents($dataDir . '/index.html', '');
        }
    }

    /**
     * @param array<mixed> $row
     * @return TunnelRow
     */
    private static function tunnelRow(array $row): array
    {
        return [
            'name' => (string)$row['name'],
            'client_id' => (string)$row['client_id'],
            'client_name' => (string)$row['client_name'],
            'client_ip' => (string)$row['client_ip'],
            'target' => (string)$row['target'],
            'auth' => $row['auth'] === null ? null : (string)$row['auth'],
            'created_at' => (float)$row['created_at'],
            'last_seen' => (float)$row['last_seen'],
            'closed_reason' => $row['closed_reason'] === null ? null : (string)$row['closed_reason'],
            'requests' => (int)$row['requests'],
            'bytes_in' => (int)$row['bytes_in'],
            'bytes_out' => (int)$row['bytes_out'],
        ];
    }

    /**
     * @param array<mixed> $row
     * @return RequestRow
     */
    private static function requestRow(array $row): array
    {
        return [
            'id' => (string)$row['id'],
            'tunnel' => (string)$row['tunnel'],
            'state' => (string)$row['state'],
            'method' => (string)$row['method'],
            'uri' => (string)$row['uri'],
            'headers' => (string)$row['headers'],
            'body_size' => (int)$row['body_size'],
            'client_ip' => (string)$row['client_ip'],
            'created_at' => (float)$row['created_at'],
            'updated_at' => (float)$row['updated_at'],
            'claimed_at' => $row['claimed_at'] === null ? null : (float)$row['claimed_at'],
            'status' => $row['status'] === null ? null : (int)$row['status'],
            'res_headers' => $row['res_headers'] === null ? null : (string)$row['res_headers'],
            'res_chunks' => (int)$row['res_chunks'],
            'res_bytes' => (int)$row['res_bytes'],
            'res_end' => (int)$row['res_end'],
            'sent_bytes' => (int)$row['sent_bytes'],
            'error' => $row['error'] === null ? null : (string)$row['error'],
            'duration_ms' => $row['duration_ms'] === null ? null : (int)$row['duration_ms'],
        ];
    }

    /** The first column of the first row. */
    private function value(string $sql): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /** @return list<string> the first column of every row */
    private static function column(\PDOStatement $stmt): array
    {
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<mixed> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
