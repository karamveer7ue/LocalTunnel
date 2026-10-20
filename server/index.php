<?php

declare(strict_types=1);

// Every request (dashboard, client API, and tunneled traffic) enters here.
require __DIR__ . '/src/bootstrap.php';

Tunnel\App::run();
