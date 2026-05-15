<?php

declare(strict_types=1);

/**
 * Server-side PDF page thumbnails and XLSX-to-PDF preview generation.
 *
 * Extracted from public/admin/media.php so both the live serving path and the
 * batch "rebuild all thumbnails" admin action use the same code.
 *
 * Rendering is attempted with multiple backends in order:
 *   1. PHP Imagick extension (no exec needed)
 *   2. Native pdftoppm (poppler-utils)
 *   3. Native convert (ImageMagick CLI; requires the PDF policy delegate)
 *   4. Native gs (Ghostscript)
 *   5. Docker pdftoppm (elswork/poppler-utils image)
 *
 * The browser-side PDF.js fallback in the analytics tooltip will still render
 * PDFs even when no server-side backend is available, so failures here are
 * non-fatal — but pre-rendering speeds up the popup considerably.
 */
final class Thumbnails {
  private const TARGET_DIM = 440;

  /**
   * Detect which PDF→JPEG backends are usable on this host. Cheap probe results
   * are cached in a static property for the duration of the request.
   *
   * @return list<string> Ordered list of backend identifiers actually available.
   */
  public static function detectBackends(): array {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }
    $found = [];
    if (extension_loaded('imagick') && class_exists('Imagick')) {
      $found[] = 'imagick';
    }
    $execOk = function_exists('exec');
    if ($execOk) {
      $disabled = (string)ini_get('disable_functions');
      if ($disabled !== '' && preg_match('/(^|,)\s*exec\s*(,|$)/i', $disabled) === 1) {
        $execOk = false;
      }
    }
    if ($execOk) {
      $tools = self::cliToolsAvailable(['pdftoppm', 'convert', 'gs', 'docker']);
      if (!empty($tools['pdftoppm'])) {
        $found[] = 'pdftoppm';
      }
      if (!empty($tools['convert'])) {
        $found[] = 'convert';
      }
      if (!empty($tools['gs'])) {
        $found[] = 'gs';
      }
      if (!empty($tools['docker'])) {
        $found[] = 'docker';
      }
    }
    $cache = $found;
    return $cache;
  }

  /**
   * @param list<string> $names
   * @return array<string, bool>
   */
  private static function cliToolsAvailable(array $names): array {
    $out = [];
    foreach ($names as $name) {
      $rc = 1;
      $lines = [];
      @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $lines, $rc);
      if ($rc !== 0 || empty($lines)) {
        // Fallback for shells where `command -v` is missing.
        $rc2 = 1;
        $lines2 = [];
        @exec('which ' . escapeshellarg($name) . ' 2>/dev/null', $lines2, $rc2);
        $out[$name] = ($rc2 === 0 && !empty($lines2) && trim((string)$lines2[0]) !== '');
      } else {
        $out[$name] = trim((string)$lines[0]) !== '';
      }
    }
    return $out;
  }

  /**
   * Generate (or reuse) a JPEG thumbnail for a single PDF page.
   * Returns the absolute path to the JPEG on success, null on failure.
   */
  public static function ensurePdfThumbJpeg(
    array $config,
    Projects $projects,
    int $projectId,
    string $pdfPath,
    string $thumbKey,
    int $page,
    bool $force = false,
  ): ?string {
    $dirs = $projects->ensureProjectDirs($projectId);
    $thumbDir = rtrim((string)$dirs['project'], '/') . '/thumbs/' . $thumbKey;
    if (!is_dir($thumbDir)) {
      @mkdir($thumbDir, 0770, true);
    }
    $out = $thumbDir . '/p' . $page . '.jpg';
    $srcMtime = @filemtime($pdfPath) ?: 0;
    $outMtime = @filemtime($out) ?: 0;
    if (!$force && is_file($out) && $srcMtime > 0 && $outMtime >= $srcMtime) {
      return $out;
    }
    if ($force && is_file($out)) {
      @unlink($out);
    }
    if (!is_file($pdfPath)) {
      return null;
    }
    foreach (self::detectBackends() as $backend) {
      if (self::renderWithBackend($backend, $pdfPath, $out, $page, $thumbDir)) {
        if (is_file($out) && filesize($out) > 500) {
          return $out;
        }
        @unlink($out);
      }
    }
    return null;
  }

  private static function renderWithBackend(
    string $backend,
    string $pdfPath,
    string $outPath,
    int $page,
    string $workDir,
  ): bool {
    switch ($backend) {
      case 'imagick': return self::renderImagick($pdfPath, $outPath, $page);
      case 'pdftoppm': return self::renderPdftoppm($pdfPath, $outPath, $page, $workDir, false);
      case 'convert': return self::renderConvert($pdfPath, $outPath, $page);
      case 'gs': return self::renderGhostscript($pdfPath, $outPath, $page, $workDir);
      case 'docker': return self::renderPdftoppm($pdfPath, $outPath, $page, $workDir, true);
      default: return false;
    }
  }

  private static function renderImagick(string $pdfPath, string $outPath, int $page): bool {
    if (!class_exists('Imagick')) {
      return false;
    }
    try {
      $im = new \Imagick();
      $im->setResolution(150, 150);
      $im->readImage($pdfPath . '[' . ($page - 1) . ']');
      $im->setImageBackgroundColor('white');
      $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
      $im->setImageFormat('jpeg');
      $im->setImageCompressionQuality(82);
      $im->thumbnailImage(self::TARGET_DIM, self::TARGET_DIM, true);
      $ok = $im->writeImage($outPath);
      $im->clear();
      return (bool)$ok && is_file($outPath) && filesize($outPath) > 500;
    } catch (\Throwable $e) {
      return false;
    }
  }

  private static function renderPdftoppm(
    string $pdfPath,
    string $outPath,
    int $page,
    string $workDir,
    bool $viaDocker,
  ): bool {
    $tmpPrefix = $workDir . '/_tmp_' . bin2hex(random_bytes(4));
    if ($viaDocker) {
      $cmd = 'docker run --rm'
        . ' -v ' . escapeshellarg($pdfPath . ':/in.pdf:ro')
        . ' -v ' . escapeshellarg($workDir . ':/out')
        . ' ' . escapeshellarg('elswork/poppler-utils')
        . ' pdftoppm -jpeg -f ' . (int)$page . ' -l ' . (int)$page
        . ' -singlefile -scale-to ' . self::TARGET_DIM
        . ' /in.pdf /out/' . basename($tmpPrefix);
    } else {
      $cmd = 'pdftoppm -jpeg -f ' . (int)$page . ' -l ' . (int)$page
        . ' -singlefile -scale-to ' . self::TARGET_DIM . ' '
        . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmpPrefix);
    }
    $lines = [];
    $rc = 0;
    @exec($cmd . ' 2>&1', $lines, $rc);
    $tmpJpg = $tmpPrefix . '.jpg';
    if ($rc === 0 && is_file($tmpJpg) && filesize($tmpJpg) > 500) {
      @rename($tmpJpg, $outPath);
      return is_file($outPath);
    }
    @unlink($tmpJpg);
    return false;
  }

  private static function renderConvert(string $pdfPath, string $outPath, int $page): bool {
    $cmd = 'convert -density 150 '
      . escapeshellarg($pdfPath . '[' . ($page - 1) . ']')
      . ' -background white -flatten'
      . ' -resize ' . self::TARGET_DIM . 'x' . self::TARGET_DIM
      . ' -quality 82 '
      . escapeshellarg($outPath);
    $lines = [];
    $rc = 0;
    @exec($cmd . ' 2>&1', $lines, $rc);
    if ($rc === 0 && is_file($outPath) && filesize($outPath) > 500) {
      return true;
    }
    @unlink($outPath);
    return false;
  }

  private static function renderGhostscript(
    string $pdfPath,
    string $outPath,
    int $page,
    string $workDir,
  ): bool {
    $tmp = $workDir . '/_gs_' . bin2hex(random_bytes(4)) . '.jpg';
    $cmd = 'gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg'
      . ' -r150'
      . ' -dFirstPage=' . (int)$page . ' -dLastPage=' . (int)$page
      . ' -dJPEGQ=82'
      . ' -sOutputFile=' . escapeshellarg($tmp)
      . ' ' . escapeshellarg($pdfPath);
    $lines = [];
    $rc = 0;
    @exec($cmd . ' 2>&1', $lines, $rc);
    if ($rc !== 0 || !is_file($tmp) || filesize($tmp) < 500) {
      @unlink($tmp);
      return false;
    }
    // Resize via convert if available, otherwise accept full-resolution output.
    $resizedOk = false;
    if (self::cliToolsAvailable(['convert'])['convert'] ?? false) {
      $resizeCmd = 'convert ' . escapeshellarg($tmp)
        . ' -resize ' . self::TARGET_DIM . 'x' . self::TARGET_DIM
        . ' -quality 82 ' . escapeshellarg($outPath);
      $rl = [];
      $rr = 0;
      @exec($resizeCmd . ' 2>&1', $rl, $rr);
      $resizedOk = ($rr === 0 && is_file($outPath) && filesize($outPath) > 500);
    }
    if (!$resizedOk) {
      @rename($tmp, $outPath);
    } else {
      @unlink($tmp);
    }
    return is_file($outPath) && filesize($outPath) > 500;
  }

  /**
   * Convert a spreadsheet (xlsx/xls/ods/etc.) to a cached PDF for preview/thumbnail
   * generation. Returns absolute PDF path on success, null otherwise.
   */
  public static function ensureXlsxPreviewPdf(
    array $config,
    Projects $projects,
    int $projectId,
    int $fileId,
    string $storedPath,
    string $originalName,
  ): ?string {
    $profile = Util::projectFilePreviewProfile($originalName);
    if ($profile === null || $profile['kind'] !== 'sheet') {
      return null;
    }
    $dirs = $projects->ensureProjectDirs($projectId);
    $prevDir = rtrim((string)$dirs['project'], '/') . '/previews';
    if (!is_dir($prevDir)) {
      @mkdir($prevDir, 0770, true);
    }
    $pdfCacheTag = Util::xlsxPdfCacheFilenameSuffix($config);
    $outPdf = $prevDir . '/file_' . (string)$fileId . $pdfCacheTag . '.pdf';
    $srcMtime = @filemtime($storedPath) ?: 0;
    $pdfMtime = @filemtime($outPdf) ?: 0;
    $needsBuild = !is_file($outPdf) || ($srcMtime > 0 && $srcMtime > $pdfMtime);
    if (!$needsBuild) {
      return is_file($outPdf) ? $outPdf : null;
    }
    $tmpPdf = $outPdf . '.tmp.' . bin2hex(random_bytes(6));
    $built = false;
    $prep = Util::xlsxPathForPdfConversion($storedPath, $originalName, $config);
    $convPath = $prep['path'];
    try {
      $gotenbergUrl = trim((string)($config['gotenberg_url'] ?? ''));
      if ($gotenbergUrl !== '') {
        $built = Util::gotenbergLibreofficeConvertToFile($gotenbergUrl, $convPath, $tmpPdf, $config);
        if (!$built) {
          $endpoint = rtrim($gotenbergUrl, '/') . '/forms/libreoffice/convert';
          $landscape = !empty($config['xlsx_pdf_landscape']) ? 'true' : 'false';
          $singlePageSheets = !empty($config['xlsx_pdf_single_page_sheets']) ? 'true' : 'false';
          $cmd = 'curl -fsS'
            . ' -o ' . escapeshellarg($tmpPdf)
            . ' -F ' . escapeshellarg('files=@' . $convPath)
            . ' -F ' . escapeshellarg('landscape=' . $landscape)
            . ' -F ' . escapeshellarg('singlePageSheets=' . $singlePageSheets)
            . ' ' . escapeshellarg($endpoint);
          $outLines = [];
          $rc = 0;
          @exec($cmd, $outLines, $rc);
          $built = ($rc === 0 && is_file($tmpPdf) && filesize($tmpPdf) > 1000);
        }
      }
      if (!$built) {
        $soffice = Util::resolveSofficePath($config);
        if ($soffice !== '') {
          $convertFilter = Util::libreOfficeCalcPdfConvertFilter($config);
          $cmd = Util::libreOfficeEnvPrefix($config)
            . escapeshellarg($soffice)
            . ' --headless --nologo --nofirststartwizard --norestore'
            . ' --convert-to ' . escapeshellarg($convertFilter)
            . ' --outdir ' . escapeshellarg($prevDir)
            . ' ' . escapeshellarg($convPath);
          $outLines = [];
          $rc = 0;
          @exec($cmd, $outLines, $rc);
          $base = pathinfo($convPath, PATHINFO_FILENAME);
          $loPdf = $prevDir . '/' . $base . '.pdf';
          if ($rc === 0 && is_file($loPdf) && filesize($loPdf) > 1000) {
            @rename($loPdf, $tmpPdf);
            $built = is_file($tmpPdf);
          }
        }
      }
    } finally {
      foreach ($prep['unlinks'] as $tmpPath) {
        if (is_file($tmpPath)) {
          @unlink($tmpPath);
        }
      }
    }
    if ($built) {
      @rename($tmpPdf, $outPdf);
      return is_file($outPdf) ? $outPdf : null;
    }
    @unlink($tmpPdf);
    return null;
  }

  /**
   * Best-effort PHP-only PDF page counter. Counts occurrences of "/Type /Page"
   * (not "/Pages") and parses common encrypted/object-stream layouts. Returns 1
   * as a floor so a single-page thumbnail attempt is still made.
   */
  public static function pdfPageCount(string $pdfPath): int {
    $sz = @filesize($pdfPath) ?: 0;
    if ($sz <= 0) {
      return 1;
    }
    $buf = @file_get_contents($pdfPath);
    if (!is_string($buf) || $buf === '') {
      return 1;
    }
    $count = preg_match_all('@/Type\s*/Page(?![s/])@', $buf, $m);
    if ($count >= 1) {
      return $count;
    }
    if (preg_match('@/Count\s+(\d+)@', $buf, $cm)) {
      $n = (int)$cm[1];
      if ($n > 0) {
        return $n;
      }
    }
    return 1;
  }

  /**
   * Walk every NDA, investment contract, and project file in a project and (re)build
   * page-level JPEG thumbnails. Returns a summary suitable for JSON responses.
   *
   * @return array{
   *   files: list<array{kind:string,label:string,pages:int,built:int,reused:int,failed:int,error?:string}>,
   *   total_pages: int,
   *   total_built: int,
   *   total_failed: int,
   *   backends_available: list<string>
   * }
   */
  public static function regenerateForProject(
    array $config,
    Projects $projects,
    Investment $investment,
    int $projectId,
    bool $force = true,
    int $maxPagesPerDoc = 200,
  ): array {
    $files = [];
    $totalPages = 0;
    $totalBuilt = 0;
    $totalFailed = 0;
    $backends = self::detectBackends();

    $addDoc = static function (string $kind, string $label, ?string $pdfPath, string $thumbKey) use (
      &$files, &$totalPages, &$totalBuilt, &$totalFailed, $config, $projects, $projectId, $force, $maxPagesPerDoc
    ): void {
      $entry = [
        'kind' => $kind,
        'label' => $label,
        'pages' => 0,
        'built' => 0,
        'reused' => 0,
        'failed' => 0,
      ];
      if ($pdfPath === null || !is_file($pdfPath)) {
        $entry['error'] = 'Source file missing';
        $files[] = $entry;
        return;
      }
      $pages = min($maxPagesPerDoc, max(1, self::pdfPageCount($pdfPath)));
      $entry['pages'] = $pages;
      for ($p = 1; $p <= $pages; $p++) {
        $thumbDir = rtrim((string)($projects->ensureProjectDirs($projectId)['project']), '/') . '/thumbs/' . $thumbKey;
        $existing = $thumbDir . '/p' . $p . '.jpg';
        $hadExisting = is_file($existing);
        $jpg = self::ensurePdfThumbJpeg($config, $projects, $projectId, $pdfPath, $thumbKey, $p, $force);
        if ($jpg === null) {
          $entry['failed']++;
          $totalFailed++;
          continue;
        }
        if ($hadExisting && !$force) {
          $entry['reused']++;
        } else {
          $entry['built']++;
          $totalBuilt++;
        }
      }
      $totalPages += $pages;
      $files[] = $entry;
    };

    $nda = $projects->getNda($projectId);
    if ($nda) {
      $addDoc('nda', (string)$nda['original_name'], (string)$nda['stored_path'], 'nda');
    }

    $contract = $investment->getContract($projectId);
    if ($contract) {
      $addDoc('contract', (string)$contract['original_name'], (string)$contract['stored_path'], 'contract');
    }

    $projFiles = $projects->listFiles($projectId);
    foreach ($projFiles as $f) {
      $fid = (int)$f['id'];
      $orig = (string)$f['original_name'];
      $stored = (string)$f['stored_path'];
      $profile = Util::projectFilePreviewProfile($orig);
      if ($profile === null) {
        continue;
      }
      $kind = (string)$profile['kind'];
      if ($kind === 'pdf') {
        $addDoc('file', $orig, $stored, 'file_' . $fid);
      } elseif ($kind === 'sheet') {
        $pdf = self::ensureXlsxPreviewPdf($config, $projects, $projectId, $fid, $stored, $orig);
        if ($pdf === null) {
          $files[] = [
            'kind' => 'file',
            'label' => $orig,
            'pages' => 0,
            'built' => 0,
            'reused' => 0,
            'failed' => 0,
            'error' => 'Spreadsheet → PDF conversion unavailable on this server',
          ];
          continue;
        }
        $addDoc('file', $orig, $pdf, 'file_' . $fid);
      }
    }

    return [
      'files' => $files,
      'total_pages' => $totalPages,
      'total_built' => $totalBuilt,
      'total_failed' => $totalFailed,
      'backends_available' => $backends,
    ];
  }
}
