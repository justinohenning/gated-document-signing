<?php

final class Auth {
  public static function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
      // cookie_path "/" so viewer.php and download.php share the visitor session on the same host.
      if (PHP_VERSION_ID >= 70300) {
        session_start([
          'cookie_lifetime' => 0,
          'cookie_path' => '/',
          'cookie_httponly' => true,
          'cookie_samesite' => 'Lax',
        ]);
      } else {
        session_set_cookie_params(0, '/', '', false, true);
        session_start();
      }
    }
  }

  public static function requireAdmin(): void {
    self::startSession();
    if (empty($_SESSION['admin_id'])) {
      header('Location: index.php?view=login');
      exit;
    }
  }

  public static function adminId(): ?int {
    self::startSession();
    $id = $_SESSION['admin_id'] ?? null;
    return is_int($id) ? $id : (is_numeric($id) ? (int)$id : null);
  }

  public static function setAdminId(int $id): void {
    self::startSession();
    $_SESSION['admin_id'] = $id;
  }

  public static function logoutAdmin(): void {
    self::startSession();
    unset($_SESSION['admin_id']);
  }

  public static function setVisitorEmail(int $projectId, string $email): void {
    self::startSession();
    $_SESSION['visitor_email_' . $projectId] = $email;
  }

  public static function visitorEmail(int $projectId): ?string {
    self::startSession();
    $e = $_SESSION['visitor_email_' . $projectId] ?? null;
    return is_string($e) ? $e : null;
  }
}

