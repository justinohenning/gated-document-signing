<?php

/**
 * Minimal diagnostic: no DB, no bootstrap.
 * Visit https://your-host/gds-ping.php — expect plain text "gds-ok" and HTTP 200.
 * If this fails with 500, the problem is PHP/FPM/web server before the app loads.
 * Remove or protect this file in production if you prefer not to expose PHP version.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
echo 'gds-ok php-' . PHP_VERSION;
