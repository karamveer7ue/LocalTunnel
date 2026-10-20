<?php

declare(strict_types=1);

namespace Tunnel;

/** Dashboard files. They go through PHP so tunnels on subdomains never see them. */
final class Assets
{
    private const TYPES = [
        'app.css' => 'text/css; charset=utf-8',
        'app.js' => 'text/javascript; charset=utf-8',
        'icon.svg' => 'image/svg+xml',
    ];

    /** Versioned URL, so browsers can cache the file until it changes. */
    public static function url(Request $request, string $file): string
    {
        return $request->linkBase . '/_tunnel/assets/' . $file . '?v=' . self::version($file);
    }

    public static function serve(string $file): void
    {
        if (!isset(self::TYPES[$file])) {
            Response::page(404, 'Not found', 'There is no such file.');
            return;
        }
        $etag = '"' . self::version($file) . '"';
        header('Content-Type: ' . self::TYPES[$file]);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        $path = self::path($file);
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    private static function version(string $file): string
    {
        $path = self::path($file);
        return substr(md5(App::VERSION . '|' . @filemtime($path) . '|' . @filesize($path)), 0, 12);
    }

    private static function path(string $file): string
    {
        return dirname(__DIR__) . '/assets/' . $file;
    }
}
