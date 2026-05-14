<?php

declare(strict_types=1);

// Minimal bootstrap (no composer).

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
  http_response_code(500);
  echo "Missing config.php. Copy config.example.php to config.php and edit it.";
  exit;
}

$config = require $configPath;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Util.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/Projects.php';
require_once dirname(__DIR__) . '/src/NdaSigning.php';
require_once dirname(__DIR__) . '/src/Investment.php';
require_once dirname(__DIR__) . '/src/Branding.php';
require_once dirname(__DIR__) . '/src/Startup.php';

try {
  [$db, $projects, $ndaSigning, $emailVerification, $investment] = Startup::connect($config);
} catch (Throwable $e) {
  Startup::failBootstrap($e, $config);
}

function analyticsSessionId(): string {
  Auth::startSession();
  if (!empty($_SESSION['analytics_session_id']) && is_string($_SESSION['analytics_session_id'])) {
    return $_SESSION['analytics_session_id'];
  }
  // RFC4122 v4-ish (good enough for session correlation)
  $hex = bin2hex(random_bytes(16));
  $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
  $_SESSION['analytics_session_id'] = $uuid;
  return $uuid;
}

function renderAnalyticsTracker(string $projectToken, string $pageKey, ?string $signerEmail, ?array $staticViewer = null): void {
  $pt = Util::h($projectToken);
  $pk = Util::h($pageKey);
  $em = $signerEmail ? Util::h($signerEmail) : '';
  $sid = Util::h(analyticsSessionId());
  $svJson = json_encode($staticViewer, JSON_UNESCAPED_SLASHES);
  if ($svJson === false) {
    $svJson = 'null';
  }
  echo <<<HTML
<script>
(() => {
  const projectToken = "{$pt}";
  const pageKey = "{$pk}";
  const signerEmail = "{$em}" || null;
  const sessionId = "{$sid}";
  const staticViewer = {$svJson};
  const endpoint = "track.php";

  const viewId = (crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Math.random()).slice(2) + String(Date.now());
  const startedAt = Date.now();
  let lastSentAt = 0;
  let ended = false;

  function mergeViewer(extra) {
    let dyn = {};
    if (typeof window.__gdsViewerAnalyticsContext === "function") {
      try {
        const o = window.__gdsViewerAnalyticsContext();
        if (o && typeof o === "object") dyn = o;
      } catch (e) {}
    }
    const base = Object.assign({}, staticViewer || {}, dyn);
    if (extra && extra.viewer && typeof extra.viewer === "object") {
      Object.assign(base, extra.viewer);
    }
    return base;
  }

  function send(eventKey, extra) {
    const now = Date.now();
    const payload = Object.assign({
      project_token: projectToken,
      session_id: sessionId,
      view_id: viewId,
      page_key: pageKey,
      path: location.pathname + location.search,
      referrer: document.referrer || "",
      signer_email: signerEmail,
      event_key: eventKey,
      started_at_ms: startedAt,
      now_ms: now,
      duration_ms: Math.max(0, now - startedAt),
      visibility: document.visibilityState || "unknown",
    }, extra || {});
    const mv = mergeViewer(extra);
    if (mv && typeof mv === "object" && Object.keys(mv).length) {
      payload.viewer = mv;
    }

    const blob = new Blob([JSON.stringify(payload)], { type: "application/json" });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, blob);
    } else {
      fetch(endpoint, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload), keepalive: true }).catch(() => {});
    }
    lastSentAt = now;
  }

  // Start
  send("page_start");

  // Heartbeat to improve accuracy on long stays
  setInterval(() => {
    if (ended) return;
    if (document.visibilityState === "hidden") return;
    send("page_heartbeat");
  }, 15000);

  document.addEventListener("visibilitychange", () => {
    if (ended) return;
    if (document.visibilityState === "hidden") {
      send("page_hidden");
    } else {
      send("page_visible");
    }
  });

  function end(reason) {
    if (ended) return;
    ended = true;
    send("page_end", { reason: reason || "unload" });
  }

  window.addEventListener("pagehide", () => end("pagehide"));
  window.addEventListener("beforeunload", () => end("beforeunload"));
})();
</script>
HTML;
}

/**
 * Build the HTML for the welcome modal overlay.
 * Output is stored in $GLOBALS['gds_welcome_modal_pending'] and injected by renderHeader().
 */
function gdsWelcomeModalHtml(int $projectId, string $projectName, string $message): string {
  $sessionKey    = Util::h('gds_welcome_' . $projectId);
  $sessionKeyJs  = json_encode('gds_welcome_' . $projectId, JSON_UNESCAPED_UNICODE);
  return <<<HTML
<div id="gds-welcome-backdrop" class="gds-welcome-backdrop" role="dialog" aria-modal="true" aria-labelledby="gds-welcome-title" data-session-key="{$sessionKey}">
  <div class="gds-welcome-modal">
    <button class="gds-welcome-close" id="gds-welcome-close" aria-label="Dismiss welcome message" title="Dismiss">&#215;</button>
    <h2 class="gds-page-title" id="gds-welcome-title" style="padding-right:calc(var(--gds-space-8) + var(--gds-space-2))">{$projectName}</h2>
    <div class="gds-welcome-body">{$message}</div>
  </div>
</div>
<script>
(function () {
  var bd  = document.getElementById('gds-welcome-backdrop');
  var key = {$sessionKeyJs};
  if (!bd) return;

  // Already dismissed this session — remove immediately without animation
  try { if (sessionStorage.getItem(key)) { bd.remove(); return; } } catch (e) {}

  function dismiss() {
    bd.classList.add('gds-welcome-out');
    try { sessionStorage.setItem(key, '1'); } catch (e) {}
    // Remove after transition; fall back with a timer in case transitionend misfires
    var removed = false;
    function remove() { if (!removed) { removed = true; bd.remove(); } }
    bd.addEventListener('transitionend', remove, { once: true });
    setTimeout(remove, 600);
  }

  document.getElementById('gds-welcome-close').addEventListener('click', dismiss);
  bd.addEventListener('click', function (e) { if (e.target === bd) dismiss(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dismiss(); }, { once: true });
})();
</script>
HTML;
}

function renderHeader(string $title): void {
  $b = $GLOBALS['gds_branding'] ?? [];
  $appName = Util::h((string)($b['app_name'] ?? 'Gated Document Signing'));
  $vTag = Util::h((string)($b['visitor_tagline'] ?? 'Secure project access'));
  $hasLogo = !empty($b['logo_path']);
  $logoLayout = (string)($b['logo_layout'] ?? 'square');
  if (!in_array($logoLayout, ['wide', 'portrait', 'square'], true)) {
    $logoLayout = 'square';
  }
  $logoSrc = Util::h(Branding::logoHref(true));
  $pageTitle = Util::h($title . ' · ' . (string)($b['app_name'] ?? 'Gated Document Signing'));
  $mark = $hasLogo
    ? '<img src="' . $logoSrc . '" class="gds-logo-img gds-logo-img--' . Util::h($logoLayout) . '" alt="" decoding="async" />'
    : '<div class="gds-logo" aria-hidden="true"></div>';
  $topbarLogoClass = $hasLogo ? (' gds-topbar--logo-' . Util::h($logoLayout)) : '';
  echo '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>' . $pageTitle . '</title>
  <link rel="stylesheet" href="' . Util::h(Util::publicFileWebUrl('assets/gds-ui.css')) . '" />
</head>
<body class="gds-app">
  <div class="gds-wrap">
    <header class="gds-topbar' . $topbarLogoClass . '" role="banner">
      <div class="gds-brand">
        ' . $mark . '
        <div>
          <div class="gds-product-name">' . $appName . '</div>
          <div class="gds-product-tag">' . $vTag . '</div>
        </div>
      </div>
      <div class="gds-pill" aria-hidden="true">Protected link</div>
    </header>
    <main>
';
  // Inject welcome modal overlay if one was registered before this renderHeader() call
  if (!empty($GLOBALS['gds_welcome_modal_pending']) && is_string($GLOBALS['gds_welcome_modal_pending'])) {
    echo $GLOBALS['gds_welcome_modal_pending'];
    $GLOBALS['gds_welcome_modal_pending'] = '';
  }
}

function renderFooter(): void {
  echo <<<HTML
    </main>
  </div>
</body>
</html>
HTML;
}
