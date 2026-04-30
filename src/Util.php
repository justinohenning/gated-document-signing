<?php

final class Util {
  /** @var array<string, mixed>|null */
  private static ?array $projectConfigCache = null;

  /** @return array<string, mixed> */
  private static function projectConfig(): array {
    if (self::$projectConfigCache !== null) {
      return self::$projectConfigCache;
    }
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
      self::$projectConfigCache = [];
      return self::$projectConfigCache;
    }
    $c = require $path;
    self::$projectConfigCache = is_array($c) ? $c : [];
    return self::$projectConfigCache;
  }

  public static function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  public static function baseUrl(array $config): string {
    if (!empty($config['base_url'])) {
      return rtrim($config['base_url'], '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    // If deployed with document root at /public, SCRIPT_NAME ends with /index.php; base is the directory.
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $scheme . '://' . $host . $dir;
  }

  /**
   * URL path (leading slash) to a file under the project's public/ directory.
   * Tries DOCUMENT_ROOT, project/public, and project root (longest match wins).
   *
   * Optional config key `public_assets_base` (e.g. "/assets") overrides auto-detection when your server layout is unusual.
   *
   * @param bool $cacheBust Append ?v=filemtime so CSS updates are not masked by browser cache.
   */
  public static function publicFileWebUrl(string $relativePath, bool $cacheBust = true): string {
    $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
    $projectRoot = dirname(__DIR__);
    $absFile = $projectRoot . '/public/' . $relativePath;

    $cfg = self::projectConfig();
    $override = $cfg['public_assets_base'] ?? '';
    if (is_string($override) && trim($override) !== '') {
      $base = '/' . trim(str_replace('\\', '/', $override), '/');
      $url = $base . '/' . $relativePath;
      return self::appendPublicFileCacheBust($url, $absFile, $cacheBust);
    }

    $targetFs = realpath($absFile);
    if (!$targetFs || !is_file($targetFs)) {
      return self::appendPublicFileCacheBust('/public/' . $relativePath, $absFile, $cacheBust);
    }

    $targetNorm = str_replace('\\', '/', $targetFs);
    $d = isset($_SERVER['DOCUMENT_ROOT']) ? trim((string)$_SERVER['DOCUMENT_ROOT']) : '';
    $docReal = $d !== '' ? realpath($d) : false;
    if ($docReal) {
      $dn = str_replace('\\', '/', rtrim($docReal, '/\\'));
      $docPrefix = $dn . '/';
      if (str_starts_with($targetNorm, $docPrefix) || $targetNorm === $dn) {
        $rest = $targetNorm === $dn ? '' : substr($targetNorm, strlen($docPrefix));
        $url = '/' . ltrim($rest, '/');
        return self::appendPublicFileCacheBust($url !== '/' ? $url : '/', $absFile, $cacheBust);
      }
    }

    $roots = [];
    foreach ([$projectRoot . '/public', $projectRoot] as $dir) {
      $rp = realpath($dir);
      if ($rp) {
        $roots[] = str_replace('\\', '/', $rp);
      }
    }
    $roots = array_values(array_unique($roots));
    usort($roots, static function (string $a, string $b): int {
      return strlen($b) <=> strlen($a);
    });

    foreach ($roots as $root) {
      $root = rtrim($root, '/');
      $prefix = $root . '/';
      if (str_starts_with($targetNorm, $prefix)) {
        $rest = substr($targetNorm, strlen($prefix));
        $url = '/' . ltrim(str_replace('\\', '/', $rest), '/');
        return self::appendPublicFileCacheBust($url, $absFile, $cacheBust);
      }
    }

    return self::appendPublicFileCacheBust('/public/' . $relativePath, $absFile, $cacheBust);
  }

  private static function appendPublicFileCacheBust(string $url, string $absFile, bool $cacheBust): string {
    if (!$cacheBust) {
      return $url;
    }
    $mt = @filemtime($absFile);
    if ($mt === false || $mt <= 0) {
      return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $mt;
  }

  /**
   * Absolute URL to another PHP script in the same public directory as the current request.
   * Use for fetch() from the browser so the origin always matches the page (session cookies),
   * unlike config base_url / public_base_url which may point at a different host.
   *
   * @param array<string, string|int|float|bool|null> $query
   */
  public static function sameRequestScriptUrl(string $script, array $query = []): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($scriptName);
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
      $pathPrefix = '';
    } else {
      $pathPrefix = rtrim($dir, '/');
    }
    $base = $scheme . '://' . $host . $pathPrefix;
    $script = ltrim(str_replace('\\', '/', $script), '/');
    $q = $query !== [] ? ('?' . http_build_query($query)) : '';
    return rtrim($base, '/') . '/' . $script . $q;
  }

  /** JSON for embedding in <script>; never returns false. */
  public static function jsonForJs(mixed $value, string $fallback = 'null'): string {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
      $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $s = json_encode($value, $flags);
    return is_string($s) ? $s : $fallback;
  }

  public static function randomToken(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
  }

  public static function nowIso(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
  }

  public static function requirePost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
      http_response_code(405);
      echo 'Method Not Allowed';
      exit;
    }
  }

  public static function clientIp(): string {
    $x = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($x) && $x !== '') {
      $parts = array_map('trim', explode(',', $x));
      if ($parts && $parts[0] !== '') return $parts[0];
    }
    $x2 = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (is_string($x2) && $x2 !== '') return trim($x2);
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ra) ? $ra : '';
  }

  /**
   * Prefix for shell commands that run LibreOffice CLI. The Debian/Ubuntu
   * /usr/bin/soffice wrapper may "cd" based on HOME/USER; php-fpm often leaves
   * HOME unset so the script tries /root and fails. Set HOME to the pool user
   * (via posix) or config libreoffice_home.
   */
  public static function libreOfficeEnvPrefix(array $config): string {
    $home = trim((string)($config['libreoffice_home'] ?? ''));
    if ($home === '' || !is_dir($home)) {
      if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['dir']) && is_dir($pw['dir'])) {
          $home = (string)$pw['dir'];
        }
      }
    }
    if ($home === '' || !is_dir($home)) {
      $home = sys_get_temp_dir();
    }
    $name = '';
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
      $pw = @posix_getpwuid(posix_geteuid());
      if (is_array($pw) && !empty($pw['name'])) {
        $name = (string)$pw['name'];
      }
    }
    $parts = ['HOME=' . escapeshellarg($home)];
    if ($name !== '') {
      $parts[] = 'USER=' . escapeshellarg($name);
      $parts[] = 'LOGNAME=' . escapeshellarg($name);
    }
    return implode(' ', $parts) . ' ';
  }

  /**
   * --convert-to target for Calc → PDF so each sheet is one continuous page (matches
   * Gotenberg singlePageSheets). Without this, LibreOffice uses print ranges and fragments rows.
   *
   * @return non-empty-string
   */
  public static function libreOfficeCalcPdfConvertFilter(array $config): string {
    if (empty($config['xlsx_pdf_single_page_sheets'])) {
      return 'pdf';
    }
    $data = [
      'SinglePageSheets' => ['type' => 'boolean', 'value' => true],
    ];
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    return 'pdf:calc_pdf_Export:' . $json;
  }

  /**
   * Suffix for cached XLSX→PDF preview files (invalidates when export rules change).
   *
   * @return non-empty-string|''
   */
  public static function xlsxPdfCacheFilenameSuffix(array $config): string {
    $parts = [];
    if (!empty($config['xlsx_pdf_single_page_sheets'])) {
      $parts[] = 'sps';
    }
    $excludeHidden = array_key_exists('xlsx_pdf_exclude_hidden_sheets', $config)
      ? !empty($config['xlsx_pdf_exclude_hidden_sheets'])
      : true;
    if ($excludeHidden) {
      $parts[] = 'xh';
    }
    return $parts === [] ? '' : ('_' . implode('_', $parts));
  }

  /**
   * Whether to drop hidden/veryHidden sheets before LibreOffice/Gotenberg PDF export.
   * SinglePageSheets still emits one page per sheet including hidden tabs; stripping matches
   * the usual “visible tabs only” expectation.
   *
   * @return array{path: string, unlink: ?string} unlink: temp file to remove after conversion
   */
  public static function xlsxPathForPdfConversion(string $sourcePath, string $originalName, array $config): array {
    $excludeHidden = array_key_exists('xlsx_pdf_exclude_hidden_sheets', $config)
      ? !empty($config['xlsx_pdf_exclude_hidden_sheets'])
      : true;
    $lower = strtolower($originalName);
    $ext = pathinfo($lower, PATHINFO_EXTENSION);
    if (!$excludeHidden || ($ext !== 'xlsx' && $ext !== 'xlsm')) {
      return ['path' => $sourcePath, 'unlink' => null];
    }
    $tmp = self::xlsxCopyWithoutHiddenSheets($sourcePath);
    if ($tmp === null) {
      return ['path' => $sourcePath, 'unlink' => null];
    }
    return ['path' => $tmp, 'unlink' => $tmp];
  }

  /**
   * Copies an XLSX/XLSM to a temp file with hidden sheets removed. Returns null if nothing to strip or on failure.
   */
  public static function xlsxCopyWithoutHiddenSheets(string $sourcePath): ?string {
    if (!extension_loaded('zip') || !class_exists('ZipArchive')) {
      return null;
    }
    $probe = new ZipArchive();
    if ($probe->open($sourcePath) !== true) {
      return null;
    }
    $wbXml = $probe->getFromName('xl/workbook.xml');
    $probe->close();
    if ($wbXml === false || $wbXml === '') {
      return null;
    }
    $dom = new \DOMDocument();
    if (@$dom->loadXML($wbXml) !== true) {
      return null;
    }
    $xp = new \DOMXPath($dom);
    $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $hidden = $xp->query('//m:sheets/m:sheet[@state="hidden" or @state="veryHidden"]');
    if ($hidden === false || $hidden->length === 0) {
      return null;
    }
    $visible = $xp->query('//m:sheets/m:sheet[not(@state="hidden") and not(@state="veryHidden")]');
    if ($visible === false || $visible->length < 1) {
      return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gdsxh');
    if ($tmp === false || !@copy($sourcePath, $tmp)) {
      return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
      @unlink($tmp);
      return null;
    }

    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml === false || $relsXml === '') {
      $zip->close();
      @unlink($tmp);
      return null;
    }
    $relsDom = new \DOMDocument();
    if (@$relsDom->loadXML($relsXml) !== true) {
      $zip->close();
      @unlink($tmp);
      return null;
    }
    $relsXp = new \DOMXPath($relsDom);
    $relsXp->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $rIds = [];
    for ($i = 0; $i < $hidden->length; $i++) {
      $el = $hidden->item($i);
      if (!$el instanceof \DOMElement) {
        continue;
      }
      $rid = $el->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
      if ($rid === '') {
        $rid = $el->getAttribute('r:id');
      }
      if ($rid !== '') {
        $rIds[$rid] = true;
      }
    }
    if ($rIds === []) {
      $zip->close();
      @unlink($tmp);
      return null;
    }

    $targets = [];
    $relsNodes = $relsXp->query('//rel:Relationship');
    if ($relsNodes !== false) {
      for ($i = 0; $i < $relsNodes->length; $i++) {
        $rel = $relsNodes->item($i);
        if (!$rel instanceof \DOMElement) {
          continue;
        }
        $id = $rel->getAttribute('Id');
        if ($id === '' || empty($rIds[$id])) {
          continue;
        }
        $type = $rel->getAttribute('Type');
        $target = $rel->getAttribute('Target');
        if ($target === '') {
          continue;
        }
        if (!str_contains($type, 'relationships/worksheet')
            && !str_contains($type, 'relationships/chartsheet')
            && !str_contains($type, 'relationships/dialogsheet')) {
          continue;
        }
        $norm = str_replace('\\', '/', $target);
        if (str_starts_with($norm, '/')) {
          $norm = ltrim($norm, '/');
          $partPath = $norm;
        } else {
          $partPath = 'xl/' . $norm;
        }
        if (!str_starts_with($partPath, 'xl/')) {
          $partPath = 'xl/' . ltrim($partPath, '/');
        }
        $targets[$partPath] = true;
        $base = basename($partPath);
        $dir = dirname($partPath);
        if ($dir !== '.' && $dir !== '') {
          $relsPart = $dir . '/_rels/' . $base . '.rels';
          if ($zip->locateName($relsPart) !== false) {
            $targets[$relsPart] = true;
          }
        }
      }
    }

    foreach (array_keys($targets) as $part) {
      if ($zip->locateName($part) !== false) {
        $zip->deleteName($part);
      }
    }

    $hiddenSnap = [];
    for ($i = 0; $i < $hidden->length; $i++) {
      $hiddenSnap[] = $hidden->item($i);
    }
    foreach ($hiddenSnap as $n) {
      if ($n instanceof \DOMNode && $n->parentNode !== null) {
        $n->parentNode->removeChild($n);
      }
    }

    if ($relsNodes !== false) {
      $relSnap = [];
      for ($i = 0; $i < $relsNodes->length; $i++) {
        $relSnap[] = $relsNodes->item($i);
      }
      foreach ($relSnap as $rel) {
        if (!$rel instanceof \DOMElement) {
          continue;
        }
        $id = $rel->getAttribute('Id');
        if ($id !== '' && !empty($rIds[$id])) {
          $rel->parentNode?->removeChild($rel);
        }
      }
    }

    $newWb = $dom->saveXML();
    if ($newWb === false || $newWb === '') {
      $zip->close();
      @unlink($tmp);
      return null;
    }
    $newRels = $relsDom->saveXML();
    if ($newRels === false || $newRels === '') {
      $zip->close();
      @unlink($tmp);
      return null;
    }
    $zip->addFromString('xl/workbook.xml', $newWb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $newRels);

    $ctPath = '[Content_Types].xml';
    $ct = $zip->getFromName($ctPath);
    if ($ct !== false && $ct !== '') {
      $ctDom = new \DOMDocument();
      if (@$ctDom->loadXML($ct) === true) {
        $ctXp = new \DOMXPath($ctDom);
        $ctXp->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
        $overrides = $ctXp->query('//t:Override');
        if ($overrides !== false) {
          for ($i = $overrides->length - 1; $i >= 0; $i--) {
            $ov = $overrides->item($i);
            if (!$ov instanceof \DOMElement) {
              continue;
            }
            $pn = $ov->getAttribute('PartName');
            if ($pn === '') {
              continue;
            }
            $pnNorm = str_replace('\\', '/', $pn);
            foreach (array_keys($targets) as $tpart) {
              $tNorm = '/' . str_replace('\\', '/', ltrim($tpart, '/'));
              if (strcasecmp($pnNorm, $tNorm) === 0) {
                $ov->parentNode?->removeChild($ov);
                break;
              }
            }
          }
        }
        $ctOut = $ctDom->saveXML();
        if (is_string($ctOut) && $ctOut !== '') {
          $zip->addFromString($ctPath, $ctOut);
        }
      }
    }

    if ($zip->locateName('xl/calcChain.xml') !== false) {
      $zip->deleteName('xl/calcChain.xml');
    }

    if ($zip->close() !== true) {
      @unlink($tmp);
      return null;
    }
    return $tmp;
  }

  /**
   * Path to the soffice binary. Optional config soffice_path skips a broken wrapper.
   *
   * @return non-empty-string|''
   */
  public static function resolveSofficePath(array $config): string {
    $p = trim((string)($config['soffice_path'] ?? ''));
    if ($p !== '' && is_file($p)) {
      return $p;
    }
    $p = trim((string)@shell_exec('command -v soffice 2>/dev/null'));
    if ($p !== '') {
      return $p;
    }
    foreach (['/usr/lib/libreoffice/program/soffice', '/usr/lib64/libreoffice/program/soffice'] as $c) {
      if (is_file($c)) {
        return $c;
      }
    }
    return '';
  }

  /**
   * In-app preview profile for a project file by original filename extension.
   * Returns kind + MIME for inline (mode=view) delivery, or null → download-only in viewer.
   *
   * @return array{kind: string, mime: string}|null
   */
  public static function projectFilePreviewProfile(string $filename): ?array {
    $lower = strtolower(trim($filename));
    $ext = pathinfo($lower, PATHINFO_EXTENSION);
    $map = [
      'pdf' => ['kind' => 'pdf', 'mime' => 'application/pdf'],
      'xlsx' => ['kind' => 'sheet', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
      'xlsm' => ['kind' => 'sheet', 'mime' => 'application/vnd.ms-excel.sheet.macroEnabled.12'],
      'xlsb' => ['kind' => 'sheet', 'mime' => 'application/vnd.ms-excel.sheet.binary'],
      'xls' => ['kind' => 'sheet', 'mime' => 'application/vnd.ms-excel'],
      'ods' => ['kind' => 'sheet', 'mime' => 'application/vnd.oasis.opendocument.spreadsheet'],
      'png' => ['kind' => 'image', 'mime' => 'image/png'],
      'jpg' => ['kind' => 'image', 'mime' => 'image/jpeg'],
      'jpeg' => ['kind' => 'image', 'mime' => 'image/jpeg'],
      'jfif' => ['kind' => 'image', 'mime' => 'image/jpeg'],
      'pjpeg' => ['kind' => 'image', 'mime' => 'image/jpeg'],
      'gif' => ['kind' => 'image', 'mime' => 'image/gif'],
      'webp' => ['kind' => 'image', 'mime' => 'image/webp'],
      'bmp' => ['kind' => 'image', 'mime' => 'image/bmp'],
      'svg' => ['kind' => 'image', 'mime' => 'image/svg+xml'],
      'ico' => ['kind' => 'image', 'mime' => 'image/x-icon'],
      'avif' => ['kind' => 'image', 'mime' => 'image/avif'],
      'heic' => ['kind' => 'image', 'mime' => 'image/heic'],
      'heif' => ['kind' => 'image', 'mime' => 'image/heif'],
      'tif' => ['kind' => 'image', 'mime' => 'image/tiff'],
      'tiff' => ['kind' => 'image', 'mime' => 'image/tiff'],
      'docx' => ['kind' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
      'txt' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'csv' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'tsv' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'md' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'json' => ['kind' => 'text', 'mime' => 'application/json; charset=UTF-8'],
      'xml' => ['kind' => 'text', 'mime' => 'text/xml; charset=UTF-8'],
      'log' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'yaml' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
      'yml' => ['kind' => 'text', 'mime' => 'text/plain; charset=UTF-8'],
    ];
    return $map[$ext] ?? null;
  }

  /**
   * Short-lived HMAC token so admins can open public/viewer.php on a different origin/port than admin
   * (session cookies are not shared across hosts).
   */
  public static function mintAdminFilePreviewToken(int $projectId, int $fileId, string $appSecret): string {
    if ($appSecret === '') {
      return '';
    }
    $exp = time() + 7200;
    $sig = hash_hmac('sha256', "$projectId|$fileId|$exp", $appSecret);
    $payload = json_encode(['pid' => $projectId, 'fid' => $fileId, 'exp' => $exp, 'sig' => $sig]);
    if (!is_string($payload)) {
      return '';
    }
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
  }

  public static function verifyAdminFilePreviewToken(
    string $token,
    string $appSecret,
    int $expectProjectId,
    int $expectFileId,
  ): bool {
    if ($appSecret === '' || $token === '') {
      return false;
    }
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false) {
      return false;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
      return false;
    }
    $pid = (int)($data['pid'] ?? 0);
    $fid = (int)($data['fid'] ?? 0);
    $exp = (int)($data['exp'] ?? 0);
    $sig = (string)($data['sig'] ?? '');
    if ($pid !== $expectProjectId || $fid !== $expectFileId || $pid < 1 || $fid < 1) {
      return false;
    }
    if ($exp < time()) {
      return false;
    }
    $expectedSig = hash_hmac('sha256', "$pid|$fid|$exp", $appSecret);
    return hash_equals($expectedSig, $sig);
  }
}

