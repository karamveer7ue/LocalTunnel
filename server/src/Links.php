<?php

declare(strict_types=1);

namespace Tunnel;

final class Links
{
    public function __construct(
        private readonly Config $config,
        private readonly Request $request,
    ) {
    }

    /** Public address of the app, like https://example.com/tunnel. */
    public function app(): string
    {
        return $this->config->publicUrl ?? $this->request->appUrl();
    }

    /** @return list<string> public addresses of a tunnel, preferred first */
    public function tunnel(string $name): array
    {
        $app = $this->app();
        $urls = [];
        if ($this->config->baseDomain !== null) {
            $parts = parse_url($app);
            $urls[] = ($parts['scheme'] ?? 'https') . '://' . $name . '.' . $this->config->baseDomain
                . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        }
        $urls[] = $app . '/t/' . $name . '/';
        return $urls;
    }

    /** Path of one of the app's own endpoints, like /tunnel/_tunnel/api/state. */
    public function internal(string $path): string
    {
        return $this->request->linkBase . '/_tunnel/' . ltrim($path, '/');
    }
}
