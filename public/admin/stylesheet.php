<?php

declare(strict_types=1);

/**
 * Serves the shared UI stylesheet from disk. Linked as stylesheet.php (same folder as admin index)
 * so the browser always resolves it correctly — no dependency on DOCUMENT_ROOT or public URL mapping.
 */
$cssPath = dirname(__DIR__) . '/assets/gds-ui.css';
if (!is_readable($cssPath)) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'CSS file not found.';
  exit;
}

$mtime = filemtime($cssPath) ?: time();
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');
readfile($cssPath);

$extra = __DIR__ . '/admin-shell.css';
if (is_readable($extra)) {
  echo "\n\n/* --- admin shell --- */\n";
  readfile($extra);
}
