<?php

declare(strict_types=1);

namespace Tunnel;

/** The data directory or database is unusable; the message tells the operator how to fix it. */
final class StoreError extends \RuntimeException
{
}
