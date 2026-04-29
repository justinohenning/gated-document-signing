<?php

declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
  http_response_code(500);
  exit;
}

$config = require $configPath;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Branding.php';

$db = new Database($config);
$b = Branding::get($db, $config);
$path = Branding::resolveLogoForServe($config, $b['logo_path'] ?? null);
if ($path === null) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Not found';
  exit;
}

header('Content-Type: ' . Branding::logoMime($path));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
