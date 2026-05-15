<?php

declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/config.php';
if (!file_exists($configPath)) {
  http_response_code(500);
  echo "Missing config.php. Copy config.example.php to config.php and edit it.";
  exit;
}

$config = require $configPath;

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/Util.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
require_once dirname(__DIR__, 2) . '/src/Projects.php';
require_once dirname(__DIR__, 2) . '/src/NdaSigning.php';
require_once dirname(__DIR__, 2) . '/src/Investment.php';
require_once dirname(__DIR__, 2) . '/src/Branding.php';
require_once dirname(__DIR__, 2) . '/src/Startup.php';

try {
  [$db, $projects, $ndaSigning, $emailVerification, $investment] = Startup::connect($config);
} catch (Throwable $e) {
  Startup::failBootstrap($e, $config);
}

function adminHeader(string $title, bool $bare = false): void {
  $b = $GLOBALS['gds_branding'] ?? [];
  $appName = Util::h((string)($b['app_name'] ?? 'Gated Document Signing'));
  $aTag = Util::h((string)($b['admin_tagline'] ?? 'Administrator'));
  $hasLogo = !empty($b['logo_path']);
  $logoLayout = (string)($b['logo_layout'] ?? 'square');
  if (!in_array($logoLayout, ['wide', 'portrait', 'square'], true)) {
    $logoLayout = 'square';
  }
  $logoSrc = Util::h(Branding::logoHref(false));
  $pageTitle = Util::h($title . ' · ' . (string)($b['app_name'] ?? 'Gated Document Signing'));
  $mark = $hasLogo
    ? '<img src="' . $logoSrc . '" class="gds-logo-img gds-logo-img--' . Util::h($logoLayout) . '" alt="" decoding="async" />'
    : '<div class="gds-logo" aria-hidden="true"></div>';
  $topbarLogoClass = $hasLogo ? (' gds-topbar--logo-' . Util::h($logoLayout)) : '';
  $view = isset($_GET['view']) ? (string)$_GET['view'] : '';
  $navDash = $view === '' || $view === 'project' || $view === 'nda-fields';
  $navAnalytics = $view === 'analytics';
  $navAdmins = $view === 'admins';
  $navBranding = $view === 'branding';
  $navClass = static function (bool $on): string {
    return $on ? ' class="is-active" aria-current="page"' : '';
  };
  $gdsCss = dirname(__DIR__) . '/assets/gds-ui.css';
  $shellCss = __DIR__ . '/admin-shell.css';
  $cssV = max(
    @filemtime($gdsCss) ?: 0,
    @filemtime($shellCss) ?: 0,
    time(),
  );
  $cssHref = Util::h('stylesheet.php?v=' . $cssV);
  $bodyClass = 'gds-app gds-app--admin' . ($bare ? ' gds-app--admin-bare' : '');
  $nav = $bare ? '' : '<nav class="gds-admin-nav" aria-label="Admin navigation">
        <a href="index.php"' . $navClass($navDash) . '>Dashboard</a>
        <a href="index.php?view=analytics"' . $navClass($navAnalytics) . '>Analytics</a>
        <a href="index.php?view=admins"' . $navClass($navAdmins) . '>Admins</a>
        <a href="index.php?view=branding"' . $navClass($navBranding) . '>White label</a>
        <a href="index.php?view=logout">Logout</a>
      </nav>';
  echo '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>' . $pageTitle . '</title>
  <link rel="stylesheet" href="' . $cssHref . '" />
</head>
<body class="' . $bodyClass . '">
  <div class="gds-wrap">
    <header class="gds-topbar' . $topbarLogoClass . '" role="banner">
      <div class="gds-brand">
        ' . $mark . '
        <div>
          <div class="gds-product-name">' . $appName . '</div>
          <div class="gds-product-tag">' . $aTag . '</div>
        </div>
      </div>
      ' . $nav . '
    </header>
    <main>
';
}

function adminFooter(): void {
  echo <<<'HTML'
    </main>
  </div>

  <div id="gds-toast" class="gds-toast is-hidden" role="status" aria-live="polite" aria-atomic="true">
    <svg class="gds-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
    <span class="gds-toast__msg">Saved</span>
  </div>

  <script>
  (function () {
    var toastEl = document.getElementById('gds-toast');
    var toastTimer = null;

    function showGdsToast(msg) {
      if (!toastEl) return;
      var msgEl = toastEl.querySelector('.gds-toast__msg');
      if (msgEl) msgEl.textContent = msg || 'Saved';
      toastEl.classList.remove('is-hidden');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () {
        toastEl.classList.add('is-hidden');
      }, 2500);
      try {
        var u = new URL(window.location.href);
        if (u.searchParams.has('toast')) {
          u.searchParams.delete('toast');
          window.history.replaceState(null, '', u.toString());
        }
      } catch (e) {}
    }

    window.showGdsToast = showGdsToast;

    try {
      var u = new URL(window.location.href);
      if (u.searchParams.get('toast') === '1') {
        showGdsToast('Saved');
      }
    } catch (e) {}
  })();
  </script>
</body>
</html>
HTML;
}
