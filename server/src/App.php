<?php

declare(strict_types=1);

namespace Tunnel;

final class App
{
    public const VERSION = '1.0.0';
    /** Bumped whenever tunnel.py and the server stop understanding each other. */
    public const PROTOCOL = 1;

    private function __construct(
        private readonly Config $config,
        private readonly Request $request,
        private readonly Store $store,
    ) {
    }

    public static function run(): void
    {
        // Responses pass through byte for byte, so PHP's own messages go to the error log only.
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');
        // Responses are streamed; PHP must neither buffer, compress, nor add a default Content-Type.
        @ini_set('zlib.output_compression', '0');
        @ini_set('default_mimetype', '');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('X-Powered-By');

        try {
            $config = Config::load();
        } catch (ConfigError $e) {
            self::setupPage(Request::fromGlobals(false, null), $e);
            return;
        }
        $request = Request::fromGlobals($config->trustProxy, $config->basePath);
        try {
            (new self($config, $request, new Store($config->dataDir)))->dispatch();
        } catch (StoreError $e) {
            Response::page(500, 'Storage unavailable', $e->getMessage(), null, 'storage');
        } catch (\Throwable $e) {
            error_log('tunnel: ' . $e);
            if (!headers_sent()) {
                Response::page(500, 'Server error', 'Something went wrong on the tunnel server. Its PHP error log has the details.', null, 'internal');
            }
        }
    }

    private function dispatch(): void
    {
        $req = $this->request;
        $links = new Links($this->config, $req);
        $query = $req->query === '' ? '' : '?' . $req->query;

        // A tunnel on its own subdomain owns every path on it.
        $subdomain = $this->tunnelSubdomain();
        if ($subdomain !== null) {
            $this->proxy()->handle($subdomain, $req->rawPath . $query, '');
            return;
        }

        $path = $req->path;
        if (str_starts_with($path, '/_tunnel/')) {
            [$area, $action] = explode('/', substr($path, 9), 2) + [1 => ''];
            match ($area) {
                'client' => (new ClientApi($this->config, $this->store, $req, $links))->handle($action),
                'api' => (new Dashboard($this->config, $this->store, $req, $links))->api($action),
                'assets' => Assets::serve($action),
                'tunnel.py' => self::serveClient(),
                default => Response::page(404, 'Not found', 'There is nothing at this address.'),
            };
            return;
        }
        if (preg_match('#^/t/([^/]+)(/.*)?$#', $path, $m)) {
            $name = strtolower($m[1]);
            if (!isset($m[2])) {
                Response::redirect($req->linkBase . '/t/' . $name . '/' . $query, 301);
                return;
            }
            $this->proxy()->handle($name, $m[2] . $query, $req->linkBase . '/t/' . $name);
            return;
        }
        if ($path === '/') {
            (new Dashboard($this->config, $this->store, $req, $links))->page();
            return;
        }
        // An app under /t/<name>/ that links to an absolute path like /assets/app.js lands
        // here. The page it came from tells us which tunnel it belongs to.
        $from = $this->refererTunnel();
        if ($from !== null) {
            Response::redirect($req->linkBase . '/t/' . $from . $path . $query, 307);
            return;
        }
        Response::page(404, 'Not found', 'There is nothing at this address.', 'Tunnels live at ' . $links->app() . '/t/<name>/', 'not_found');
    }

    /** The tunnel name when the request arrived on <name>.<base_domain>. */
    private function tunnelSubdomain(): ?string
    {
        $base = $this->config->baseDomain;
        $host = $this->request->host;
        if ($base === null || !str_ends_with($host, '.' . $base)) {
            return null;
        }
        $label = substr($host, 0, -strlen($base) - 1);
        return str_contains($label, '.') || Names::isReserved($label) ? null : $label;
    }

    private function refererTunnel(): ?string
    {
        $referer = parse_url((string)$this->request->header('referer'));
        if (!is_array($referer) || strtolower($referer['host'] ?? '') !== $this->request->host) {
            return null;
        }
        $prefix = preg_quote($this->request->linkBase, '#');
        return preg_match('#^' . $prefix . '/t/([a-z0-9-]+)/#', $referer['path'] ?? '', $m) ? $m[1] : null;
    }

    /** The Python client, when this server was deployed together with it. */
    public static function clientScript(): ?string
    {
        $path = dirname(__DIR__, 2) . '/client/tunnel.py';
        return is_file($path) && is_readable($path) ? $path : null;
    }

    private static function serveClient(): void
    {
        $path = self::clientScript();
        if ($path === null) {
            Response::page(404, 'Not found', 'The client is not deployed next to this server. Copy client/tunnel.py from the project instead.');
            return;
        }
        header('Content-Type: text/x-python; charset=utf-8');
        header('Content-Disposition: attachment; filename="tunnel.py"');
        header('Cache-Control: no-cache');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    private function proxy(): Proxy
    {
        return new Proxy($this->config, $this->store, $this->request);
    }

    private static function setupPage(Request $request, ConfigError $error): void
    {
        if (str_starts_with($request->path, '/_tunnel/client/')) {
            Response::json(503, ['error' => 'The tunnel server is not configured yet: ' . $error->getMessage()]);
            return;
        }
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        View::render('setup', [
            'error' => $error,
            'configPath' => Config::path(),
            'token' => bin2hex(random_bytes(24)),
        ]);
    }
}
