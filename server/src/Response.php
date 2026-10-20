<?php

declare(strict_types=1);

namespace Tunnel;

final class Response
{
    /** @param array<mixed>|object $data */
    public static function json(int $status, array|object $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function redirect(string $location, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $location);
        header('Cache-Control: no-store');
    }

    /**
     * Answers with an error page: HTML for browsers, plain text for everything else. The
     * X-Tunnel-Error header lets programs tell these apart from the tunneled app's own errors.
     *
     * @param array<string, string> $headers
     */
    public static function page(int $status, string $title, string $message, ?string $hint = null, string $kind = 'error', array $headers = []): void
    {
        if (!headers_sent()) {
            header_remove();
            http_response_code($status);
            header('Cache-Control: no-store');
            header('X-Tunnel-Error: ' . $kind);
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
        }
        if (!str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "$title\n\n$message\n", $hint === null ? '' : "\n$hint\n";
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        View::render('error', ['status' => $status, 'title' => $title, 'message' => $message, 'hint' => $hint]);
    }
}
