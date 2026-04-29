<?php

declare(strict_types=1);

/**
 * Fallback entry when the web server's document root is the project root
 * (parent of public/) instead of public/ itself. Plesk sometimes defaults here.
 */
require __DIR__ . '/public/index.php';
