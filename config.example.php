<?php

return [
  'db' => [
    // PDO MySQL DSN must use host= and dbname= (port optional). Invalid: mysql:localhost:3306;...
    // Example: mysql:host=localhost;port=3306;dbname=my_database;charset=utf8mb4
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
  // XLSX: 'pdf' (default) = high-fidelity preview via Gotenberg/LibreOffice; 'sheet' = in-browser grid.
  'xlsx_preview_mode' => 'pdf',
  'gotenberg_url' => '', // e.g. http://127.0.0.1:3000 — required on server for XLSX→PDF unless LibreOffice CLI is installed.
  'xlsx_pdf_single_page_sheets' => true,
  // When true (default), hidden/veryHidden worksheet tabs are removed before PDF export. LibreOffice
  // "single page sheets" still includes hidden tabs (one PDF page each), so this keeps page count aligned
  // with visible tabs.
  'xlsx_pdf_exclude_hidden_sheets' => true,
  'xlsx_pdf_landscape' => true,
  // Optional: fix LibreOffice CLI under php-fpm (wrapper "cd /root" errors). Usually auto from posix; override if needed.
  // 'libreoffice_home' => '/var/www/vhosts/example.com',
  // 'soffice_path' => '/usr/lib/libreoffice/program/soffice',
];

