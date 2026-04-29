<?php

require_once __DIR__ . '/_bootstrap.php';

Auth::requireAdmin();

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
if ($projectId <= 0) {
  http_response_code(400);
  echo 'Missing project_id';
  exit;
}

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

