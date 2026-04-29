<?php

return [
  'db' => [
    'dsn' => 'mysql:host=127.0.0.1;dbname=gated_signing;charset=utf8mb4',
    'user' => 'dbuser',
    'pass' => 'dbpass',
  ],
  // Change this to a long random string in config.php (never commit real secrets).
  'app_secret' => 'change-me',
  // Set true temporarily on a broken install to see startup errors as plain text (disable after fixing).
  'debug' => false,
  'base_url' => '', // e.g. "https://example.com/gated-signing" (no trailing slash). Leave blank to auto-detect.
  // Optional: explicit public URL for generated share links.
  // Example dev: "http://127.0.0.1:8008"
  // Example prod: "https://example.com/gated-signing"
  'public_base_url' => '',
  // Optional: URL path to files inside public/ when auto-detection fails (Apache alias, Docker, etc.).
  // Examples: "/assets" if the web root is the public/ folder; "/public/assets" if the web root is the project root.
  'public_assets_base' => '',
  'storage_dir' => __DIR__ . '/storage',
];

