<?php

declare(strict_types=1);

// Kept free of PHP 8 syntax so older PHP versions reach this message instead of a parse error.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Tunnel needs PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . ".\n";
    exit;
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Tunnel\\')) {
        require __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    }
});
