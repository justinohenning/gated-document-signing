<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

Auth::requireAdmin();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) {
  http_response_code(400);
  echo 'Missing project_id';
  exit;
}

$contract = $investment->getContract($projectId);
if (!$contract) {
  http_response_code(404);
  echo 'Investment contract not configured';
  exit;
}

$path = (string)$contract['stored_path'];
if (!is_file($path)) {
  http_response_code(404);
  echo 'Contract PDF missing';
  exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string)$contract['original_name']) . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
exit;
