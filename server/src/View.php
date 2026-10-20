<?php

declare(strict_types=1);

namespace Tunnel;

final class View
{
    /** @param array<string, mixed> $vars */
    public static function render(string $name, array $vars): void
    {
        (static function (string $__file, array $__vars): void {
            extract($__vars);
            require $__file;
        })(dirname(__DIR__) . '/views/' . $name . '.php', $vars);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
