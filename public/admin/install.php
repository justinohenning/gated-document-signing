<?php

require_once __DIR__ . '/_bootstrap.php';

// One-time installer: creates the first admin if none exist.
// If schema.sql was never imported, the SELECT below used to throw and return HTTP 500.
try {
  $db->ensureAdminsTableExists();
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/html; charset=utf-8');
  $debug = filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
  echo '<!doctype html><meta charset="utf-8"><title>Install</title>';
  echo '<h1>Could not prepare database</h1>';
  echo '<p>The installer could not create the <code>admins</code> table. Import <code>schema.sql</code> in phpMyAdmin, '
    . 'or grant this MySQL user <code>CREATE</code> on your database.</p>';
  if ($debug) {
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  }
  exit;
}

$existing = $db->fetchOne('SELECT id FROM admins ORDER BY id ASC LIMIT 1');

adminHeader('Install');
echo '<div class="card">';
echo '<h2 style="margin:0 0 10px 0">Installer</h2>';

if ($existing) {
  echo '<div class="ok"><strong>Already installed.</strong> Admin account exists. Go to <a href="index.php?view=login">login</a>.</div>';
  echo '</div>';
  adminFooter();
  exit;
}

$error = '';
$ok = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $pass = (string)($_POST['password'] ?? '');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid email.';
  } elseif (strlen($pass) < 10) {
    $error = 'Password must be at least 10 characters.';
  } else {
    try {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $db->exec(
        'INSERT INTO admins (email, password_hash, created_at) VALUES (:e, :h, UTC_TIMESTAMP())',
        [':e' => $email, ':h' => $hash],
      );
      $ok = 'Admin created. You can now log in.';
    } catch (Throwable $e) {
      $error = 'Could not save admin. If the database is read-only or incomplete, import schema.sql.';
      if (filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $error .= ' ' . $e->getMessage();
      }
    }
  }
}

if ($error !== '') echo '<div class="err" style="margin-bottom:12px"><strong>' . Util::h($error) . '</strong></div>';
if ($ok !== '') echo '<div class="ok" style="margin-bottom:12px"><strong>' . Util::h($ok) . '</strong> <a href="index.php?view=login">Go to login</a></div>';

echo '<p class="muted" style="margin:0 0 14px 0">Create your first admin account.</p>';
echo '<form method="post">';
echo '<div class="row"><div><label class="muted">Admin email</label><input name="email" type="email" required></div></div>';
echo '<div class="row" style="margin-top:12px"><div><label class="muted">Password</label><input name="password" type="password" required></div></div>';
echo '<div style="margin-top:14px;display:flex;justify-content:flex-end"><button type="submit">Create admin</button></div>';
echo '</form>';
echo '</div>';
adminFooter();

