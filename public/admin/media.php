<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

Auth::requireAdmin();

$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId < 1) {
  http_response_code(400);
  echo 'Missing project_id';
  exit;
}

$docKind = (string)($_GET['doc_kind'] ?? '');
$mode = (string)($_GET['mode'] ?? '');
if (!in_array($mode, ['pdf', 'image', 'thumb'], true)) {
  http_response_code(400);
  echo 'Invalid mode';
  exit;
}

$path = '';
$filename = 'media';

if ($docKind === 'nda') {
  $nda = $projects->getNda($projectId);
  if (!$nda) {
    http_response_code(404);
    echo 'NDA not found';
    exit;
  }
  $path = (string)$nda['stored_path'];
  $filename = (string)$nda['original_name'];
} elseif ($docKind === 'file') {
  $fileId = (int)($_GET['file_id'] ?? 0);
  if ($fileId < 1) {
    http_response_code(400);
    echo 'Missing file_id';
    exit;
  }
  $file = $projects->getFile($fileId);
  if (!$file || (int)$file['project_id'] !== $projectId) {
    http_response_code(404);
    echo 'File not found';
    exit;
  }
  $path = (string)$file['stored_path'];
  $filename = (string)$file['original_name'];
} else {
  http_response_code(400);
  echo 'Invalid doc_kind';
  exit;
}

if ($path === '' || !is_file($path)) {
  http_response_code(404);
  echo 'Missing file';
  exit;
}

$lower = strtolower($filename);
$isPdf = str_ends_with($lower, '.pdf');
$isImg = preg_match('/\.(png|jpe?g|gif|webp|bmp|svg|ico|tiff?)$/i', $lower) === 1;

function sendBytes(string $path, string $contentType, string $dispName): void {
  header('Content-Type: ' . $contentType);
  header('Content-Disposition: inline; filename="' . $dispName . '"');
  header('Cache-Control: private, no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . (string)filesize($path));
  readfile($path);
  exit;
}

function ensureXlsxPreviewPdf(array $config, Projects $projects, int $projectId, int $fileId, string $storedPath, string $originalName): ?string {
  $profile = Util::projectFilePreviewProfile($originalName);
  if ($profile === null || $profile['kind'] !== 'sheet') {
    return null;
  }
  $dirs = $projects->ensureProjectDirs($projectId);
  $prevDir = rtrim((string)$dirs['project'], '/') . '/previews';
  if (!is_dir($prevDir)) {
    mkdir($prevDir, 0770, true);
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

function ensurePdfThumbJpeg(array $config, Projects $projects, int $projectId, string $pdfPath, string $thumbKey, int $page): ?string {
  $dirs = $projects->ensureProjectDirs($projectId);
  $thumbDir = rtrim((string)$dirs['project'], '/') . '/thumbs/' . $thumbKey;
  if (!is_dir($thumbDir)) {
    mkdir($thumbDir, 0770, true);
  }
  $out = $thumbDir . '/p' . $page . '.jpg';
  $srcMtime = @filemtime($pdfPath) ?: 0;
  $outMtime = @filemtime($out) ?: 0;
  if (is_file($out) && $srcMtime > 0 && $outMtime >= $srcMtime) {
    return $out;
  }
  // Generate with poppler via Docker to avoid system deps.
  $img = 'elswork/poppler-utils';
  $tmpPrefix = $thumbDir . '/_tmp_' . bin2hex(random_bytes(4));
  $cmd = 'docker run --rm'
    . ' -v ' . escapeshellarg($pdfPath . ':/in.pdf:ro')
    . ' -v ' . escapeshellarg($thumbDir . ':/out')
    . ' ' . escapeshellarg($img)
    . ' pdftoppm -jpeg -f ' . (int)$page . ' -l ' . (int)$page . ' -singlefile -scale-to 440 /in.pdf /out/' . basename($tmpPrefix);
  $outLines = [];
  $rc = 0;
  @exec($cmd, $outLines, $rc);
  $tmpJpg = $tmpPrefix . '.jpg';
  if ($rc === 0 && is_file($tmpJpg) && filesize($tmpJpg) > 1000) {
    @rename($tmpJpg, $out);
    return is_file($out) ? $out : null;
  }
  @unlink($tmpJpg);
  return null;
}

if ($mode === 'thumb') {
  $page = (int)($_GET['page'] ?? 1);
  if ($page < 1) $page = 1;
  // Determine PDF source: direct PDF file, or XLSX preview PDF for sheets.
  $pdfPath = null;
  $thumbKey = 'doc';
  if ($docKind === 'nda') {
    if ($isPdf) {
      $pdfPath = $path;
      $thumbKey = 'nda';
    }
  } elseif ($docKind === 'file') {
    $fileId = (int)($_GET['file_id'] ?? 0);
    $thumbKey = 'file_' . $fileId;
    if ($isPdf) {
      $pdfPath = $path;
    } else {
      $pdfPath = ensureXlsxPreviewPdf($config, $projects, $projectId, $fileId, $path, $filename);
    }
  }
  if (!$pdfPath || !is_file($pdfPath)) {
    http_response_code(404);
    echo 'No PDF';
    exit;
  }
  $jpg = ensurePdfThumbJpeg($config, $projects, $projectId, $pdfPath, $thumbKey, $page);
  if (!$jpg || !is_file($jpg)) {
    http_response_code(500);
    echo 'Thumb failed';
    exit;
  }
  sendBytes($jpg, 'image/jpeg', 'thumb.jpg');
}

if ($mode === 'pdf') {
  // Allow PDF and XLSX->PDF preview outputs (PDF bytes).
  if ($isPdf) {
    sendBytes($path, 'application/pdf', basename(pathinfo($filename, PATHINFO_FILENAME)) . '.pdf');
  }
  if ($docKind === 'file') {
    $fileId = (int)($_GET['file_id'] ?? 0);
    if ($fileId > 0) {
      $pdfPath = ensureXlsxPreviewPdf($config, $projects, $projectId, $fileId, $path, $filename);
      if ($pdfPath && is_file($pdfPath)) {
        sendBytes($pdfPath, 'application/pdf', basename(pathinfo($filename, PATHINFO_FILENAME)) . '.pdf');
      }
    }
  }
  http_response_code(415);
  echo 'Not a PDF';
  exit;
} else {
  if (!$isImg) {
    http_response_code(415);
    echo 'Not an image';
    exit;
  }
  $ct = 'application/octet-stream';
  if (preg_match('/\.png$/i', $lower)) $ct = 'image/png';
  elseif (preg_match('/\.jpe?g$/i', $lower)) $ct = 'image/jpeg';
  elseif (preg_match('/\.gif$/i', $lower)) $ct = 'image/gif';
  elseif (preg_match('/\.webp$/i', $lower)) $ct = 'image/webp';
  elseif (preg_match('/\.svg$/i', $lower)) $ct = 'image/svg+xml';
  sendBytes($path, $ct, basename($filename));
}
