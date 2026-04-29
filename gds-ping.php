<?php

declare(strict_types=1);

/**
 * Mirror of public/gds-ping.php for hosts whose document root is the project root
 * (not public/). Without mod_rewrite, /gds-ping.php must exist here or you get 404.
 */
require __DIR__ . '/public/gds-ping.php';
