<?php

declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Global white-label settings (single row app_branding) and logo file under storage.
 */
final class Branding {
  private const ROW_ID = 1;

  /**
   * Create app_branding if missing (databases installed before this table existed).
   */
  public static function ensureSchema(Database $db): void {
    $db->exec(
      'CREATE TABLE IF NOT EXISTS app_branding (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
        app_name VARCHAR(255) NOT NULL DEFAULT \'Gated Document Signing\',
        visitor_tagline VARCHAR(255) NOT NULL DEFAULT \'Secure project access\',
        admin_tagline VARCHAR(255) NOT NULL DEFAULT \'Administrator\',
        logo_path TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
      [],
    );
    $db->exec(
      'INSERT INTO app_branding (id, app_name, visitor_tagline, admin_tagline, logo_path, updated_at)
       VALUES (1, \'Gated Document Signing\', \'Secure project access\', \'Administrator\', NULL, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE id = id',
      [],
    );
  }

  /**
   * @return array{app_name: string, visitor_tagline: string, admin_tagline: string, logo_path: ?string, logo_layout: string}
   */
  public static function get(Database $db, array $config): array {
    $defaults = self::defaults();
    try {
      $row = $db->fetchOne(
        'SELECT app_name, visitor_tagline, admin_tagline, logo_path FROM app_branding WHERE id = :id LIMIT 1',
        [':id' => self::ROW_ID],
      );
      if (!$row) {
        return $defaults;
      }
      $storage = (string)($config['storage_dir'] ?? '');
      $logoPath = self::normalizeLogoPath($storage, $row['logo_path'] ?? null);
      return [
        'app_name' => trim((string)($row['app_name'] ?? '')) !== ''
          ? (string)$row['app_name']
          : $defaults['app_name'],
        'visitor_tagline' => (string)($row['visitor_tagline'] ?? $defaults['visitor_tagline']),
        'admin_tagline' => (string)($row['admin_tagline'] ?? $defaults['admin_tagline']),
        'logo_path' => $logoPath,
        'logo_layout' => self::inferLogoLayout($logoPath),
      ];
    } catch (\Throwable $e) {
      return $defaults;
    }
  }

  /** @param array{app_name?: string, visitor_tagline?: string, admin_tagline?: string} $fields */
  public static function saveText(Database $db, array $fields): void {
    $app = isset($fields['app_name']) ? trim((string)$fields['app_name']) : '';
    $vTag = isset($fields['visitor_tagline']) ? trim((string)$fields['visitor_tagline']) : '';
    $aTag = isset($fields['admin_tagline']) ? trim((string)$fields['admin_tagline']) : '';
    if ($app === '') {
      $app = self::defaults()['app_name'];
    }
    $db->exec(
      'INSERT INTO app_branding (id, app_name, visitor_tagline, admin_tagline, updated_at)
       VALUES (1, :a, :v, :ad, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE app_name = VALUES(app_name), visitor_tagline = VALUES(visitor_tagline),
         admin_tagline = VALUES(admin_tagline), updated_at = UTC_TIMESTAMP()',
      [':a' => $app, ':v' => $vTag, ':ad' => $aTag],
    );
  }

  public static function setLogoPath(Database $db, ?string $absolutePath): void {
    $path = $absolutePath !== null && $absolutePath !== '' ? $absolutePath : null;
    $db->exec(
      'INSERT INTO app_branding (id, logo_path, updated_at) VALUES (1, :p, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE logo_path = VALUES(logo_path), updated_at = UTC_TIMESTAMP()',
      [':p' => $path],
    );
  }

  /**
   * Save uploaded logo; returns absolute path or throws on invalid file.
   */
  public static function saveUploadedLogo(array $config, string $tmpPath, string $originalName): string {
    $storage = rtrim((string)($config['storage_dir'] ?? ''), '/');
    $dir = $storage . '/branding';
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \RuntimeException('Could not create branding directory.');
      }
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
    if (!in_array($ext, $allowed, true)) {
      throw new \InvalidArgumentException('Logo must be PNG, JPG, GIF, WebP, or SVG.');
    }
    if ($ext === 'svg') {
      $body = file_get_contents($tmpPath);
      if ($body === false || stripos($body, '<svg') === false) {
        throw new \InvalidArgumentException('Invalid SVG file.');
      }
      $dest = $dir . '/logo.svg';
      if (!move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
        if (!@copy($tmpPath, $dest)) {
          throw new \RuntimeException('Could not save logo.');
        }
        @unlink($tmpPath);
      }
      return $dest;
    }
    $info = @getimagesize($tmpPath);
    if ($info === false) {
      throw new \InvalidArgumentException('Could not read image file.');
    }
    $mime = $info['mime'] ?? '';
    $map = [
      'image/png' => 'png',
      'image/jpeg' => 'jpg',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
      throw new \InvalidArgumentException('Unsupported image type.');
    }
    $dest = $dir . '/logo.' . $map[$mime];
    foreach (glob($dir . '/logo.*') ?: [] as $old) {
      if (is_file($old) && $old !== $dest) {
        @unlink($old);
      }
    }
    if (!move_uploaded_file($tmpPath, $dest) && !@rename($tmpPath, $dest)) {
      if (!@copy($tmpPath, $dest)) {
        throw new \RuntimeException('Could not save logo.');
      }
      @unlink($tmpPath);
    }
    return $dest;
  }

  public static function removeLogoFile(array $config, ?string $absolutePath): void {
    if ($absolutePath === null || $absolutePath === '') {
      return;
    }
    $storage = realpath((string)($config['storage_dir'] ?? ''));
    $real = realpath($absolutePath);
    if ($storage && $real && str_starts_with($real, $storage) && is_file($real)) {
      @unlink($real);
    }
  }

  /**
   * Resolve safe absolute path for serving; returns null if invalid or missing.
   */
  public static function resolveLogoForServe(array $config, ?string $storedPath): ?string {
    if ($storedPath === null || $storedPath === '') {
      return null;
    }
    $storage = realpath((string)($config['storage_dir'] ?? ''));
    if ($storage === false) {
      return null;
    }
    $real = realpath($storedPath);
    if ($real === false || !is_file($real)) {
      return null;
    }
    $brandDir = $storage . DIRECTORY_SEPARATOR . 'branding';
    if (!str_starts_with($real, $brandDir)) {
      return null;
    }
    return $real;
  }

  public static function logoMime(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
      'png' => 'image/png',
      'jpg', 'jpeg' => 'image/jpeg',
      'gif' => 'image/gif',
      'webp' => 'image/webp',
      'svg' => 'image/svg+xml',
      default => 'application/octet-stream',
    };
  }

  /**
   * @param bool $fromPublicUi If true (visitor UI), use a URL under public/. If false (admin), use same-directory branding-logo.php under public/admin/.
   */
  public static function logoHref(bool $fromPublicUi = true): string {
    if ($fromPublicUi) {
      return Util::publicFileWebUrl('branding-logo.php');
    }
    return 'branding-logo.php';
  }

  /** @return array{app_name: string, visitor_tagline: string, admin_tagline: string, logo_path: ?string, logo_layout: string} */
  private static function defaults(): array {
    return [
      'app_name' => 'Gated Document Signing',
      'visitor_tagline' => 'Secure project access',
      'admin_tagline' => 'Administrator',
      'logo_path' => null,
      'logo_layout' => 'square',
    ];
  }

  /**
   * wide = horizontal mark, portrait = taller than wide, square = roughly square.
   *
   * @return 'wide'|'portrait'|'square'
   */
  public static function inferLogoLayout(?string $absolutePath): string {
    if ($absolutePath === null || $absolutePath === '' || !is_readable($absolutePath)) {
      return 'square';
    }
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $w = 0;
    $h = 0;
    if ($ext === 'svg') {
      $dim = self::svgIntrinsicDimensions($absolutePath);
      if ($dim === null) {
        return 'square';
      }
      $w = $dim['w'];
      $h = $dim['h'];
    } else {
      $info = @getimagesize($absolutePath);
      if ($info === false || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
        return 'square';
      }
      $w = (int)$info[0];
      $h = (int)$info[1];
    }
    $ratio = $w / $h;
    if ($ratio >= 1.35) {
      return 'wide';
    }
    if ($ratio <= 0.78) {
      return 'portrait';
    }
    return 'square';
  }

  /**
   * @return array{w: int, h: int}|null
   */
  private static function svgIntrinsicDimensions(string $path): ?array {
    $body = @file_get_contents($path);
    if ($body === false || stripos($body, '<svg') === false) {
      return null;
    }
    if (preg_match('/viewBox\s*=\s*["\']\s*([0-9.\s,+-]+)\s*["\']/i', $body, $m)) {
      $parts = preg_split('/[\s,]+/', trim($m[1]));
      if (count($parts) >= 4) {
        $bw = abs((float)$parts[2]);
        $bh = abs((float)$parts[3]);
        if ($bw > 1 && $bh > 1) {
          return ['w' => max(1, (int)round($bw)), 'h' => max(1, (int)round($bh))];
        }
      }
    }
    $uw = null;
    $uh = null;
    if (preg_match('/<svg[^>]*\bwidth\s*=\s*["\']([^"\']+)["\']/i', $body, $wm)) {
      $uw = self::parseSvgLength($wm[1]);
    }
    if (preg_match('/<svg[^>]*\bheight\s*=\s*["\']([^"\']+)["\']/i', $body, $hm)) {
      $uh = self::parseSvgLength($hm[1]);
    }
    if ($uw !== null && $uh !== null && $uw > 0 && $uh > 0) {
      return ['w' => max(1, (int)round($uw)), 'h' => max(1, (int)round($uh))];
    }
    return null;
  }

  private static function parseSvgLength(string $raw): ?float {
    $raw = trim($raw);
    if ($raw === '' || stripos($raw, '%') !== false) {
      return null;
    }
    if (preg_match('/^([\d.]+)/', $raw, $m)) {
      return (float)$m[1];
    }
    return null;
  }

  private static function normalizeLogoPath(string $storageDir, mixed $path): ?string {
    if ($path === null || $path === '') {
      return null;
    }
    $p = (string)$path;
    if (is_file($p)) {
      return is_readable($p) ? $p : null;
    }
    $storage = rtrim($storageDir, '/');
    if ($storage !== '' && is_file($storage . '/' . ltrim($p, '/'))) {
      $full = $storage . '/' . ltrim($p, '/');
      return is_readable($full) ? $full : null;
    }
    return null;
  }
}
