<?php

declare(strict_types=1);

namespace Tunnel;

final class Names
{
    private const ADJECTIVES = [
        'amber', 'brave', 'bright', 'calm', 'clever', 'cosmic', 'crisp', 'daring', 'eager', 'fancy', 'gentle',
        'golden', 'happy', 'jolly', 'keen', 'lively', 'lucky', 'mellow', 'misty', 'noble', 'proud', 'quick',
        'quiet', 'rapid', 'silent', 'silver', 'sunny', 'swift', 'tidy', 'vivid', 'witty', 'young',
    ];

    private const NOUNS = [
        'badger', 'beacon', 'breeze', 'canyon', 'comet', 'coral', 'falcon', 'fern', 'forest', 'harbor', 'heron',
        'island', 'lagoon', 'lantern', 'maple', 'meadow', 'otter', 'panda', 'pebble', 'pine', 'planet', 'rabbit',
        'raven', 'river', 'rocket', 'sparrow', 'summit', 'thunder', 'tiger', 'valley', 'willow', 'zephyr',
    ];

    /** Tunnel names double as DNS labels, so they follow the same rules. */
    public static function isValid(string $name): bool
    {
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $name);
    }

    public static function isReserved(string $name): bool
    {
        return in_array($name, Config::RESERVED_NAMES, true);
    }

    public static function random(): string
    {
        return self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)]
            . '-' . self::NOUNS[random_int(0, count(self::NOUNS) - 1)]
            . '-' . random_int(10, 99);
    }
}
