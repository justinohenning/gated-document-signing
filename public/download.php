<?php

require_once __DIR__ . '/_bootstrap.php';

$projectToken = isset($_GET['p']) ? (string)$_GET['p'] : '';
if ($projectToken === '') {
  http_response_code(400);
  echo 'Missing project token';
  exit;
}

$project = $projects->getByToken($projectToken);
if (!$project || (int)$project['is_active'] !== 1) {
  http_response_code(404);
  echo 'Not found';
  exit;
}
$projectId = (int)$project['id'];
$allowDownloads = ((int)($project['allow_downloads'] ?? 1)) === 1;
$watermarkEnabled = ((int)($project['watermark_enabled'] ?? 0)) === 1;
$watermarkPath = isset($project['watermark_image_path']) ? (string)$project['watermark_image_path'] : '';
$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : ''; // "view" to allow in-app viewing without download

// Watermark image (same-origin, used by viewer / PDF stamping)
if (isset($_GET['watermark'])) {
  if (!$watermarkEnabled || $watermarkPath === '' || !is_file($watermarkPath)) {
    http_response_code(404);
    echo 'No watermark';
    exit;
  }
  $lower = strtolower($watermarkPath);
  $ct = 'application/octet-stream';
  if (str_ends_with($lower, '.png')) $ct = 'image/png';
  if (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg')) $ct = 'image/jpeg';
  if (str_ends_with($lower, '.webp')) $ct = 'image/webp';
  header('Content-Type: ' . $ct);
  header('Content-Disposition: inline; filename="' . basename($watermarkPath) . '"');
  header('Content-Length: ' . filesize($watermarkPath));
  readfile($watermarkPath);
  exit;
}

// NDA PDF download (allowed even if not signed, since it is needed for signing)
if (isset($_GET['nda'])) {
  $nda = $projects->getNda($projectId);
  if (!$nda) {
    http_response_code(404);
    echo 'NDA not configured';
    exit;
  }
  $path = (string)$nda['stored_path'];
  if (!is_file($path)) {
    http_response_code(404);
    echo 'NDA missing';
    exit;
  }
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . basename((string)$nda['original_name']) . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

// Investment contract PDF (for signing flow; requires verified visitor email or access cookie)
if (isset($_GET['contract'])) {
  $emailGate = Auth::visitorEmail($projectId);
  if ($emailGate === null) {
    $cookieToken = $_COOKIE['gds_access_' . $projectId] ?? '';
    if (is_string($cookieToken) && $cookieToken !== '') {
      $emailFromCookie = $ndaSigning->validateAccessToken($projectId, $cookieToken);
      if ($emailFromCookie) {
        Auth::setVisitorEmail($projectId, $emailFromCookie);
        $emailGate = $emailFromCookie;
      }
    }
  }
  if ($emailGate === null) {
    http_response_code(403);
    echo 'Not authorized';
    exit;
  }
  if (!$ndaSigning->hasSigned($projectId, $emailGate)) {
    http_response_code(403);
    echo 'Not authorized';
    exit;
  }
  $inv = $investment->getSettings($projectId);
  if (((int)($inv['enabled'] ?? 0)) !== 1) {
    http_response_code(404);
    echo 'Investment module not enabled';
    exit;
  }
  $contract = $investment->getContract($projectId);
  if (!$contract) {
    http_response_code(404);
    echo 'Contract not configured';
    exit;
  }
  $path = (string)$contract['stored_path'];
  if (!is_file($path)) {
    http_response_code(404);
    echo 'Contract missing';
    exit;
  }
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . basename((string)$contract['original_name']) . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

$email = Auth::visitorEmail($projectId);

// Signed receipt download (requires signed access)
if (isset($_GET['signed_nda'])) {
  // If visitor has a cookie token, attempt to bind it.
  if ($email === null) {
    $cookieToken = $_COOKIE['gds_access_' . $projectId] ?? '';
    if (is_string($cookieToken) && $cookieToken !== '') {
      $emailFromCookie = $ndaSigning->validateAccessToken($projectId, $cookieToken);
      if ($emailFromCookie) {
        Auth::setVisitorEmail($projectId, $emailFromCookie);
        $email = $emailFromCookie;
      }
    }
  }

  if ($email === null || !$ndaSigning->hasSigned($projectId, $email)) {
    http_response_code(403);
    echo 'Not authorized';
    exit;
  }

  $rec = $ndaSigning->getSignatureRecord($projectId, $email);
  if (!$rec) {
    http_response_code(404);
    echo 'No signed record';
    exit;
  }

  // Prefer the generated signed PDF if present.
  $pdfPath = isset($rec['signed_pdf_path']) ? (string)$rec['signed_pdf_path'] : '';
  if ($pdfPath !== '' && is_file($pdfPath)) {
    // Signed NDA is always available to the signer; allow_downloads only gates project files.
    if ($mode === 'view_src') {
      header('Content-Type: application/pdf');
      header('Content-Disposition: inline; filename="signed.pdf"');
      header('Content-Length: ' . filesize($pdfPath));
      readfile($pdfPath);
      exit;
    }
    if ($watermarkEnabled && $watermarkPath !== '' && is_file($watermarkPath)) {
      $ip = Util::clientIp();
      $srcUrl = 'download.php?p=' . urlencode($projectToken) . '&signed_nda=1&mode=view_src';
      $wmUrl = 'download.php?p=' . urlencode($projectToken) . '&watermark=1';
      $filename = 'Signed_NDA_' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)$project['name']) . '_' . gmdate('Y-m-d') . '.pdf';
      header('Content-Type: text/html; charset=utf-8');
      echo '<!doctype html><meta charset="utf-8"/><title>Preparing download…</title>';
      echo '<div style="font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:18px">Preparing watermarked PDF…</div>';
      echo '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>';
      echo '<script>';
      echo 'const SRC=' . json_encode($srcUrl, JSON_UNESCAPED_SLASHES) . ';';
      echo 'const WM=' . json_encode($wmUrl, JSON_UNESCAPED_SLASHES) . ';';
      echo 'const IP=' . json_encode($ip, JSON_UNESCAPED_SLASHES) . ';';
      echo 'const FNAME=' . json_encode($filename, JSON_UNESCAPED_SLASHES) . ';';
      echo '
(async () => {
  const [pdfBytes, wmBytes] = await Promise.all([
    fetch(SRC).then(r => { if(!r.ok) throw new Error("src " + r.status); return r.arrayBuffer(); }),
    fetch(WM).then(r => { if(!r.ok) throw new Error("wm " + r.status); return r.arrayBuffer(); }),
  ]);
  const pdfDoc = await PDFLib.PDFDocument.load(pdfBytes);
  let wmImg = null;
  try { wmImg = await pdfDoc.embedPng(wmBytes); } catch (e) { wmImg = await pdfDoc.embedJpg(wmBytes); }
  const pages = pdfDoc.getPages();
  for (const p of pages) {
    const { width, height } = p.getSize();
    const margin = 18;
    const maxW = Math.min(140, width * 0.22);
    const scale = maxW / wmImg.width;
    const drawW = wmImg.width * scale;
    const drawH = wmImg.height * scale;
    p.drawImage(wmImg, { x: margin, y: height - margin - drawH, width: drawW, height: drawH, opacity: 0.18 });
    p.drawText("IP: " + (IP || ""), { x: margin, y: height - margin - drawH - 12, size: 10, color: PDFLib.rgb(0.1,0.1,0.1), opacity: 0.55 });
  }
  const out = await pdfDoc.save();
  const blob = new Blob([out], { type: "application/pdf" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = FNAME;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
})();
';
      echo '</script>';
      exit;
    }
    $filename = 'Signed_NDA_' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)$project['name']) . '_' . gmdate('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdfPath));
    readfile($pdfPath);
    exit;
  }

  $path = (string)$rec['signed_receipt_path'];
  if (!is_file($path)) {
    http_response_code(404);
    echo 'Missing signed record';
    exit;
  }

  $filename = 'Signed_NDA_' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)$project['name']) . '_' . gmdate('Y-m-d') . '.html';
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

// Signed investment contract PDF (signer only)
if (isset($_GET['signed_contract'])) {
  $sem = Auth::visitorEmail($projectId);
  if ($sem === null) {
    $cookieToken = $_COOKIE['gds_access_' . $projectId] ?? '';
    if (is_string($cookieToken) && $cookieToken !== '') {
      $emailFromCookie = $ndaSigning->validateAccessToken($projectId, $cookieToken);
      if ($emailFromCookie) {
        Auth::setVisitorEmail($projectId, $emailFromCookie);
        $sem = $emailFromCookie;
      }
    }
  }
  if ($sem === null || !$ndaSigning->hasSigned($projectId, $sem)) {
    http_response_code(403);
    echo 'Not authorized';
    exit;
  }
  $cmt = $investment->getCommitment($projectId, $sem);
  if (!$cmt) {
    http_response_code(404);
    echo 'No commitment on file';
    exit;
  }
  $pdfPath = isset($cmt['signed_pdf_path']) ? (string)$cmt['signed_pdf_path'] : '';
  if ($pdfPath === '' || !is_file($pdfPath)) {
    http_response_code(404);
    echo 'Signed contract not available';
    exit;
  }
  $filename = 'Signed_contract_' . preg_replace('/[^a-z0-9._-]+/i', '_', (string)$project['name']) . '_' . gmdate('Y-m-d') . '.pdf';
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . (string)filesize($pdfPath));
  readfile($pdfPath);
  exit;
}

// If visitor has a cookie token, attempt to bind it.
if ($email === null) {
  $cookieToken = $_COOKIE['gds_access_' . $projectId] ?? '';
  if (is_string($cookieToken) && $cookieToken !== '') {
    $emailFromCookie = $ndaSigning->validateAccessToken($projectId, $cookieToken);
    if ($emailFromCookie) {
      Auth::setVisitorEmail($projectId, $emailFromCookie);
      $email = $emailFromCookie;
    }
  }
}

$fileIdForAuth = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$previewTokenIn = (string)($_GET['preview_token'] ?? '');
$secret = (string)($config['app_secret'] ?? '');
$tokenOk = $fileIdForAuth > 0 && $previewTokenIn !== '' && $secret !== ''
  && Util::verifyAdminFilePreviewToken($previewTokenIn, $secret, $projectId, $fileIdForAuth);
$adminPreview = isset($_GET['admin_preview']) && (string)$_GET['admin_preview'] === '1';
$adminOk = $tokenOk || ($adminPreview && Auth::adminId() !== null);

if (!$adminOk && ($email === null || !$ndaSigning->hasSigned($projectId, $email))) {
  http_response_code(403);
  echo 'Not authorized';
  exit;
}

$fileId = $fileIdForAuth;
if ($fileId <= 0) {
  http_response_code(400);
  echo 'Missing file_id';
  exit;
}

$file = $projects->getFile($fileId);
if (!$file || (int)$file['project_id'] !== $projectId) {
  http_response_code(404);
  echo 'Not found';
  exit;
}

$path = (string)$file['stored_path'];
if (!is_file($path)) {
  http_response_code(404);
  echo 'Missing file';
  exit;
}

$originalName = (string)$file['original_name'];
$size = filesize($path);
$mime = 'application/octet-stream';
$lowerName = strtolower($originalName);
$isPdf = str_ends_with($lowerName, '.pdf');
$previewProfile = Util::projectFilePreviewProfile($originalName);

// High-fidelity preview: XLSX -> PDF conversion (preview only; downloads remain original file).
if ($mode === 'view_pdf' && $previewProfile !== null && $previewProfile['kind'] === 'sheet') {
  $dirs = $projects->ensureProjectDirs($projectId);
  $prevDir = rtrim((string)$dirs['project'], '/') . '/previews';
  if (!is_dir($prevDir)) {
    mkdir($prevDir, 0770, true);
  }
  $pdfCacheTag = Util::xlsxPdfCacheFilenameSuffix($config);
  $outPdf = $prevDir . '/file_' . (string)$fileId . $pdfCacheTag . '.pdf';
  $srcMtime = @filemtime($path) ?: 0;
  $pdfMtime = @filemtime($outPdf) ?: 0;

  $needsBuild = !is_file($outPdf) || ($srcMtime > 0 && $srcMtime > $pdfMtime);
  if ($needsBuild) {
    $tmpPdf = $outPdf . '.tmp.' . bin2hex(random_bytes(6));
    $built = false;
    $prep = Util::xlsxPathForPdfConversion($path, $originalName, $config);
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
          // LibreOffice writes <basename>.pdf into $prevDir
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
    } else {
      @unlink($tmpPdf);
      http_response_code(501);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'XLSX PDF preview unavailable (converter not configured).';
      exit;
    }
  }

  if (!is_file($outPdf)) {
    http_response_code(500);
    echo 'Preview build failed';
    exit;
  }
  $ps = filesize($outPdf);
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . basename(pathinfo($originalName, PATHINFO_FILENAME)) . '.pdf"');
  header('Content-Length: ' . $ps);
  header('Cache-Control: private, no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  readfile($outPdf);
  exit;
}

if ($mode === 'view' && $previewProfile !== null) {
  $mime = $previewProfile['mime'];
  $kind = $previewProfile['kind'];

  // Video and audio need range-request support so the browser can seek.
  if ($kind === 'video' || $kind === 'audio') {
    $fileSize = $size ?: 0;
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
    $start = 0;
    $end   = max(0, $fileSize - 1);
    $isRange = false;

    if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $rm)) {
      $isRange = true;
      $start = $rm[1] !== '' ? (int)$rm[1] : 0;
      $end   = $rm[2] !== '' ? (int)$rm[2] : max(0, $fileSize - 1);
      $end   = min($end, max(0, $fileSize - 1));
      $start = min($start, $end);
    }

    $length = $end - $start + 1;
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($originalName) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    if ($isRange) {
      http_response_code(206);
      header("Content-Range: bytes $start-$end/$fileSize");
      header('Content-Length: ' . $length);
      $fp = fopen($path, 'rb');
      if ($fp) {
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
          $chunk = fread($fp, min(65536, $remaining));
          if ($chunk === false) break;
          echo $chunk;
          $remaining -= strlen($chunk);
        }
        fclose($fp);
      }
    } else {
      header('Content-Length: ' . $fileSize);
      readfile($path);
    }
    exit;
  }

  header('Content-Type: ' . $mime);
  header('Content-Disposition: inline; filename="' . basename($originalName) . '"');
  header('Content-Length: ' . $size);
  header('Cache-Control: private, no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

if ($mode === 'view_src' && $isPdf) {
  // internal source fetch for client-side PDF stamping during download
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . basename($originalName) . '"');
  header('Content-Length: ' . $size);
  readfile($path);
  exit;
}

if (!$allowDownloads) {
  http_response_code(403);
  echo 'Downloads disabled';
  exit;
}

if ($isPdf && $watermarkEnabled && $watermarkPath !== '' && is_file($watermarkPath)) {
  $ip = Util::clientIp();
  $srcUrl = 'download.php?p=' . urlencode($projectToken) . '&file_id=' . urlencode((string)$fileId) . '&mode=view_src';
  $wmUrl = 'download.php?p=' . urlencode($projectToken) . '&watermark=1';
  $filename = basename($originalName);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"/><title>Preparing download…</title>';
  echo '<div style="font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:18px">Preparing watermarked PDF…</div>';
  echo '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>';
  echo '<script>';
  echo 'const SRC=' . json_encode($srcUrl, JSON_UNESCAPED_SLASHES) . ';';
  echo 'const WM=' . json_encode($wmUrl, JSON_UNESCAPED_SLASHES) . ';';
  echo 'const IP=' . json_encode($ip, JSON_UNESCAPED_SLASHES) . ';';
  echo 'const FNAME=' . json_encode($filename, JSON_UNESCAPED_SLASHES) . ';';
  echo '
(async () => {
  const [pdfBytes, wmBytes] = await Promise.all([
    fetch(SRC).then(r => { if(!r.ok) throw new Error("src " + r.status); return r.arrayBuffer(); }),
    fetch(WM).then(r => { if(!r.ok) throw new Error("wm " + r.status); return r.arrayBuffer(); }),
  ]);
  const pdfDoc = await PDFLib.PDFDocument.load(pdfBytes);
  let wmImg = null;
  try { wmImg = await pdfDoc.embedPng(wmBytes); } catch (e) { wmImg = await pdfDoc.embedJpg(wmBytes); }
  const pages = pdfDoc.getPages();
  for (const p of pages) {
    const { width, height } = p.getSize();
    const margin = 18;
    const maxW = Math.min(140, width * 0.22);
    const scale = maxW / wmImg.width;
    const drawW = wmImg.width * scale;
    const drawH = wmImg.height * scale;
    p.drawImage(wmImg, { x: margin, y: height - margin - drawH, width: drawW, height: drawH, opacity: 0.18 });
    p.drawText("IP: " + (IP || ""), { x: margin, y: height - margin - drawH - 12, size: 10, color: PDFLib.rgb(0.1,0.1,0.1), opacity: 0.55 });
  }
  const out = await pdfDoc.save();
  const blob = new Blob([out], { type: "application/pdf" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = FNAME;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
})();
';
  echo '</script>';
  exit;
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($originalName) . '"');
header('Content-Length: ' . $size);
readfile($path);
exit;

