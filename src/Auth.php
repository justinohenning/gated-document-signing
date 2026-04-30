<?php

final class Auth {
  private const SESSION_NAME = 'gds_sid';

  /** Prefer HTTPS cookies when the request is served over TLS (or behind a terminating proxy). */
  public static function cookieSecureDefault(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
      return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
      return true;
    }
    $xffProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($xffProto) && strtolower($xffProto) === 'https';
  }

  public static function startSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
      return;
    }
    session_name(self::SESSION_NAME);
    $secure = self::cookieSecureDefault();
    // cookie_path "/" so viewer.php and download.php share the visitor session on the same host.
    if (PHP_VERSION_ID >= 70300) {
      session_start([
        'cookie_lifetime' => 0,
        'cookie_path' => '/',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $secure,
      ]);
    } else {
      session_set_cookie_params(0, '/', '', $secure, true);
      session_start();
    }
  }

  public static function csrfToken(): string {
    self::startSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
  }

  public static function verifyCsrfToken(string $submitted): bool {
    self::startSession();
    $stored = $_SESSION['csrf_token'] ?? '';
    if (!is_string($stored) || $stored === '') {
      return false;
    }
    return hash_equals($stored, $submitted);
  }

  /** Hidden input HTML for POST forms (value is escaped). */
  public static function csrfFieldHtml(): string {
    return '<input type="hidden" name="_csrf" value="' . Util::h(self::csrfToken()) . '" />';
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
