<?php

require_once __DIR__ . '/_bootstrap.php';

// Accept sendBeacon() payloads
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo 'Method Not Allowed';
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo 'Invalid JSON';
  exit;
}

$projectToken = isset($data['project_token']) ? (string)$data['project_token'] : '';
$project = $projectToken !== '' ? $projects->getByToken($projectToken) : null;
if (!$project) {
  http_response_code(404);
  echo 'Not found';
  exit;
}
$projectId = (int)$project['id'];

$sessionId = isset($data['session_id']) ? (string)$data['session_id'] : '';
$viewId = isset($data['view_id']) ? (string)$data['view_id'] : '';
$pageKey = isset($data['page_key']) ? (string)$data['page_key'] : '';
$path = isset($data['path']) ? (string)$data['path'] : '';
$ref = isset($data['referrer']) ? (string)$data['referrer'] : '';
$eventKey = isset($data['event_key']) ? (string)$data['event_key'] : '';
$durationMs = isset($data['duration_ms']) ? (int)$data['duration_ms'] : 0;
$signerEmail = isset($data['signer_email']) ? (string)$data['signer_email'] : '';

if ($sessionId === '' || $viewId === '' || $pageKey === '' || $path === '' || $eventKey === '') {
  http_response_code(400);
  echo 'Missing fields';
  exit;
}

// Guard lengths (avoid abuse / huge payloads)
$sessionId = substr($sessionId, 0, 36);
$viewId = substr($viewId, 0, 64);
$pageKey = substr($pageKey, 0, 64);
$path = substr($path, 0, 255);
$ref = substr($ref, 0, 255);
$eventKey = substr($eventKey, 0, 64);
if ($signerEmail !== '') $signerEmail = substr(strtolower($signerEmail), 0, 255);

$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2000);
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

// Upsert a page view record by (project_id, session_id, view_id).
$existing = $db->fetchOne(
  'SELECT id, duration_ms FROM analytics_page_views WHERE project_id = :pid AND session_id = :sid AND view_id = :vid LIMIT 1',
  [':pid' => $projectId, ':sid' => $sessionId, ':vid' => $viewId],
);

if ($existing) {
  $newDur = max((int)$existing['duration_ms'], $durationMs);
  $sql = 'UPDATE analytics_page_views
          SET signer_email = :em,
              referrer = :ref,
              user_agent = :ua,
              ip_address = :ip,
              last_heartbeat_at = UTC_TIMESTAMP(),
              duration_ms = :dur,
              ended_at = ' . ($eventKey === 'page_end' ? 'UTC_TIMESTAMP()' : 'ended_at') . '
          WHERE id = :id';
  $db->exec($sql, [
    ':em' => ($signerEmail !== '' ? $signerEmail : null),
    ':ref' => $ref,
    ':ua' => $ua,
    ':ip' => $ip,
    ':dur' => $newDur,
    ':id' => (int)$existing['id'],
  ]);
} else {
  $db->exec(
    'INSERT INTO analytics_page_views (
       project_id, signer_email, session_id, view_id, page_key, path, referrer,
       user_agent, ip_address, started_at, last_heartbeat_at, ended_at, duration_ms
     ) VALUES (
       :pid, :em, :sid, :vid, :pk, :pth, :ref,
       :ua, :ip, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL, :dur
     )',
    [
      ':pid' => $projectId,
      ':em' => ($signerEmail !== '' ? $signerEmail : null),
      ':sid' => $sessionId,
      ':vid' => $viewId,
      ':pk' => $pageKey,
      ':pth' => $path,
      ':ref' => $ref,
      ':ua' => $ua,
      ':ip' => $ip,
      ':dur' => max(0, $durationMs),
    ],
  );
}

// Store event (lightweight)
$payload = $data;
unset($payload['project_token']);
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

$db->exec(
  'INSERT INTO analytics_events (project_id, signer_email, session_id, event_key, page_key, path, created_at, payload_json)
   VALUES (:pid, :em, :sid, :ek, :pk, :pth, UTC_TIMESTAMP(), :pj)',
  [
    ':pid' => $projectId,
    ':em' => ($signerEmail !== '' ? $signerEmail : null),
    ':sid' => $sessionId,
    ':ek' => $eventKey,
    ':pk' => $pageKey,
    ':pth' => $path,
    ':pj' => $payloadJson,
  ],
);

http_response_code(204);

