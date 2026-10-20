<?php

declare(strict_types=1);

namespace Tunnel;

final class ConfigError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $missing = false)
    {
        parent::__construct($message);
    }
}
