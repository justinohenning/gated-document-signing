<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

Auth::requireAdmin();

$commitmentId = (int)($_GET['commitment_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);
if ($commitmentId <= 0 || $projectId <= 0) {
  http_response_code(400);
  echo 'Bad request';
  exit;
}

$row = $db->fetchOne(
  'SELECT * FROM investment_commitments WHERE id = :id AND project_id = :pid LIMIT 1',
  [':id' => $commitmentId, ':pid' => $projectId],
);
if (!$row) {
  http_response_code(404);
  echo 'Commitment not found';
  exit;
}

$pdfPath = isset($row['signed_pdf_path']) ? (string)$row['signed_pdf_path'] : '';
if ($pdfPath === '' || !is_file($pdfPath)) {
  http_response_code(404);
  echo 'Signed contract PDF is not available.';
  exit;
}

$email = (string)$row['signer_email'];
$safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $email);
if ($safe === '') {
  $safe = 'signer';
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="signed_contract_' . $safe . '.pdf"');
header('Content-Length: ' . (string)filesize($pdfPath));
readfile($pdfPath);
exit;
