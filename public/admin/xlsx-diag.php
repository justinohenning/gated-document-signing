<?php
/**
 * XLSX→PDF diagnostics (admin-only, DELETE after debugging).
 */
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
Auth::requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

$projectId = (int)($_GET['project_id'] ?? 0);
$fileId    = (int)($_GET['file_id'] ?? 0);
if ($projectId < 1 || $fileId < 1) {
    echo "Usage: ?project_id=1&file_id=2\n";
    exit;
}

$file = $projects->getFile($fileId);
if (!$file || (int)$file['project_id'] !== $projectId) {
    echo "File not found.\n";
    exit;
}

$storedPath   = (string)$file['stored_path'];
$originalName = (string)$file['original_name'];

echo "=== FILE ===\n";
echo "Path        : $storedPath\n";
echo "Name        : $originalName\n";
echo "Exists      : " . (is_file($storedPath) ? 'yes' : 'NO') . "\n";
echo "Size        : " . (is_file($storedPath) ? filesize($storedPath) : 'n/a') . " bytes\n\n";

// --- Inspect workbook XML ---
echo "=== WORKBOOK SHEETS ===\n";
if (is_file($storedPath) && extension_loaded('zip')) {
    $zip = new ZipArchive();
    if ($zip->open($storedPath) === true) {
        $wb = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if ($wb !== false) {
            preg_match_all('/<sheet\b[^>]*\/?>/', $wb, $m);
            $sheets = $m[0];
            echo "Total <sheet> elements: " . count($sheets) . "\n";
            foreach ($sheets as $s) {
                preg_match('/name="([^"]*)"/', $s, $nm);
                preg_match('/state="([^"]*)"/', $s, $st);
                $name  = $nm[1] ?? '(no name)';
                $state = $st[1] ?? '(no state attr)';
                echo "  name=$name  state=$state\n";
            }
            $hidden = array_filter($sheets, fn($s) => preg_match('/state\s*=\s*"(hidden|veryHidden)"/i', $s));
            echo "Hidden sheets: " . count($hidden) . "\n";
        } else {
            echo "Could not read xl/workbook.xml from ZIP.\n";
        }
    } else {
        echo "ZipArchive could not open the file.\n";
    }
} else {
    echo "ZipArchive not available or file missing.\n";
}

echo "\n=== CONFIG ===\n";
$gotenbergUrl = trim((string)($config['gotenberg_url'] ?? ''));
echo "gotenberg_url           : " . ($gotenbergUrl !== '' ? $gotenbergUrl : '(empty)') . "\n";
echo "xlsx_pdf_single_page_sheets : " . (!empty($config['xlsx_pdf_single_page_sheets']) ? 'true' : 'false') . "\n";
echo "xlsx_pdf_landscape      : " . (!empty($config['xlsx_pdf_landscape']) ? 'true' : 'false') . "\n";
echo "xlsx_pdf_exclude_hidden_sheets: " . (
    array_key_exists('xlsx_pdf_exclude_hidden_sheets', $config)
        ? (!empty($config['xlsx_pdf_exclude_hidden_sheets']) ? 'true' : 'false')
        : '(default=true)'
) . "\n";
echo "xlsx_pdf_ooxml_fit_to_page: " . (
    array_key_exists('xlsx_pdf_ooxml_fit_to_page', $config)
        ? (!empty($config['xlsx_pdf_ooxml_fit_to_page']) ? 'true' : 'false')
        : '(default: off when gotenberg set)'
) . "\n";

echo "\n=== PHP EXTENSIONS ===\n";
echo "zip extension   : " . (extension_loaded('zip') ? 'yes' : 'NO') . "\n";
echo "curl extension  : " . (extension_loaded('curl') ? 'yes' : 'NO') . "\n";
echo "dom extension   : " . (extension_loaded('dom') ? 'yes' : 'NO') . "\n";
echo "exec() available: " . (function_exists('exec') ? 'yes' : 'NO') . "\n";

// --- Gotenberg health ---
if ($gotenbergUrl !== '') {
    echo "\n=== GOTENBERG HEALTH ===\n";
    $health = rtrim($gotenbergUrl, '/') . '/health';
    if (extension_loaded('curl')) {
        $ch = curl_init($health);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        echo "HTTP $code — " . ($err !== '' ? "CURL ERROR: $err" : $body) . "\n";
    } else {
        echo "curl extension not available.\n";
    }
}

// --- Test Gotenberg with singlePageSheets=true ---
if ($gotenbergUrl !== '' && is_file($storedPath) && extension_loaded('curl')) {
    echo "\n=== GOTENBERG CONVERSION TEST ===\n";
    $tmpOut = tempnam(sys_get_temp_dir(), 'gdstest_');

    // Test 1: singlePageSheets=true
    $cfile = new CURLFile($storedPath, 'application/octet-stream', basename($storedPath));
    $ch = curl_init(rtrim($gotenbergUrl, '/') . '/forms/libreoffice/convert');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'files'            => $cfile,
            'singlePageSheets' => 'true',
            'landscape'        => 'true',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code === 200 && is_string($body) && strlen($body) > 100) {
        file_put_contents($tmpOut, $body);
        preg_match_all('/\/Count (\d+)/', $body, $mc);
        $counts = array_unique($mc[1]);
        echo "singlePageSheets=true  → HTTP $code, size=" . strlen($body) . " bytes, /Count values: " . implode(', ', $counts) . "\n";
        @unlink($tmpOut);
    } else {
        echo "singlePageSheets=true  → HTTP $code, CURL ERR=$err, body_len=" . strlen((string)$body) . "\n";
    }

    // Test 2: singlePageSheets=false
    $cfile2 = new CURLFile($storedPath, 'application/octet-stream', basename($storedPath));
    $ch2 = curl_init(rtrim($gotenbergUrl, '/') . '/forms/libreoffice/convert');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'files'            => $cfile2,
            'singlePageSheets' => 'false',
            'landscape'        => 'true',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $body2 = curl_exec($ch2);
    $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2  = curl_error($ch2);
    curl_close($ch2);
    if ($code2 === 200 && is_string($body2) && strlen($body2) > 100) {
        preg_match_all('/\/Count (\d+)/', $body2, $mc2);
        $counts2 = array_unique($mc2[1]);
        echo "singlePageSheets=false → HTTP $code2, size=" . strlen($body2) . " bytes, /Count values: " . implode(', ', $counts2) . "\n";
    } else {
        echo "singlePageSheets=false → HTTP $code2, CURL ERR=$err2\n";
    }
}

// --- Test hidden-sheet stripping ---
echo "\n=== HIDDEN SHEET STRIPPING ===\n";
$stripped = Util::xlsxCopyWithoutHiddenSheets($storedPath);
if ($stripped === null) {
    echo "xlsxCopyWithoutHiddenSheets() returned null\n";
    echo "(means: no hidden sheets found OR ZipArchive/DOM unavailable)\n";
} else {
    echo "Stripped file created: $stripped\n";
    $zip2 = new ZipArchive();
    if ($zip2->open($stripped) === true) {
        $wb2 = $zip2->getFromName('xl/workbook.xml');
        $zip2->close();
        preg_match_all('/<sheet\b[^>]*\/?>/', (string)$wb2, $m2);
        echo "Sheets remaining after strip: " . count($m2[0]) . "\n";
        foreach ($m2[0] as $s) {
            preg_match('/name="([^"]*)"/', $s, $nm2);
            echo "  " . ($nm2[1] ?? '(no name)') . "\n";
        }
    }
    @unlink($stripped);
}

// --- soffice path ---
echo "\n=== SOFFICE ===\n";
$soffice = Util::resolveSofficePath($config);
echo "soffice path: " . ($soffice !== '' ? $soffice : '(not found)') . "\n";
if ($soffice !== '') {
    $ver = trim((string)@shell_exec(escapeshellarg($soffice) . ' --version 2>&1'));
    echo "soffice version: $ver\n";
}

echo "\nDone. DELETE this file from the server after debugging.\n";
