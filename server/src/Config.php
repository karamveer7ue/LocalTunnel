<?php

declare(strict_types=1);

namespace Tunnel;

final class Config
{
    /** Subdomains that never become tunnels, so they keep serving the dashboard. */
    public const RESERVED_NAMES = ['www'];

    private function __construct(
        public readonly string $token,
        public readonly ?string $baseDomain,
        public readonly ?string $publicUrl,
        public readonly ?string $basePath,
        public readonly string $dataDir,
        public readonly bool $trustProxy,
        public readonly int $responseTimeout,
        public readonly int $maxUploadBytes,
    ) {
    }

    /** Fingerprint of a tunnel's "user:password", so the password itself is never stored. */
    public function basicAuthHash(string $credentials): string
    {
        return hash_hmac('sha256', 'basic-auth:' . $credentials, $this->token);
    }

    public static function path(): string
    {
        $fromEnv = getenv('TUNNEL_CONFIG');
        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : dirname(__DIR__) . '/config.php';
    }

    /** @throws ConfigError */
    public static function load(): self
    {
        $path = self::path();
        if (!is_file($path)) {
            throw new ConfigError("There is no configuration file at $path yet.", missing: true);
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new ConfigError("$path must return an array of settings.");
        }

        $token = (string)($values['token'] ?? '');
        if (strlen($token) < 16 || $token === 'replace-with-a-long-random-secret') {
            throw new ConfigError("Set 'token' in $path to a random secret of at least 16 characters.");
        }

        $baseDomain = strtolower(trim((string)($values['base_domain'] ?? ''), " .\t\n"));
        if ($baseDomain !== '' && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $baseDomain)) {
            throw new ConfigError("'base_domain' in $path must be a host name like tunnel.example.com.");
        }

        $publicUrl = rtrim(trim((string)($values['public_url'] ?? '')), '/');
        if ($publicUrl !== '' && !preg_match('#^https?://[^/]+(/.*)?$#i', $publicUrl)) {
            throw new ConfigError("'public_url' in $path must start with http:// or https://.");
        }

        $basePath = $values['base_path'] ?? null;

        return new self(
            token: $token,
            baseDomain: $baseDomain === '' ? null : $baseDomain,
            publicUrl: $publicUrl === '' ? null : $publicUrl,
            basePath: $basePath === null ? null : '/' . trim((string)$basePath, '/'),
            dataDir: rtrim((string)($values['data_dir'] ?? dirname(__DIR__) . '/data'), '/\\'),
            trustProxy: (bool)($values['trust_proxy'] ?? false),
            responseTimeout: max(5, (int)($values['response_timeout'] ?? 50)),
            maxUploadBytes: max(1, (int)($values['max_upload_mb'] ?? 1024)) * 1024 * 1024,
        );
    }
}
