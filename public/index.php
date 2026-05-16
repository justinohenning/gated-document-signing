<?php

require_once __DIR__ . '/_bootstrap.php';

$projectToken = isset($_GET['p']) ? (string)$_GET['p'] : '';
$accessToken = isset($_GET['t']) ? (string)$_GET['t'] : '';

if ($projectToken === '') {
  // No project token — send visitors to the admin login instead of an error.
  header('Location: admin/index.php?view=login');
  exit;
}

$project = $projects->getByToken($projectToken);
if (!$project || (int)$project['is_active'] !== 1) {
  renderHeader('Invalid link');
  renderAnalyticsTracker($projectToken, 'invalid_project', null);
  echo '<div class="card"><div class="err"><strong>Invalid or inactive project link.</strong></div></div>';
  renderFooter();
  exit;
}

$projectId = (int)$project['id'];

// Optional welcome message — shown as a modal overlay dismissed client-side via sessionStorage.
// The modal HTML is registered here and injected into the page by the next renderHeader() call.
$welcomeEnabled = ((int)($project['welcome_enabled'] ?? 0)) === 1;
$welcomeMessage = trim((string)($project['welcome_message'] ?? ''));

if ($welcomeEnabled && $welcomeMessage !== '') {
  $GLOBALS['gds_welcome_modal_pending'] = gdsWelcomeModalHtml(
    $projectId,
    Util::h((string)$project['name']),
    nl2br(Util::h($welcomeMessage))
  );
}

// Magic link: verify email before session access (?ev= hex token)
if (isset($_GET['ev']) && is_string($_GET['ev']) && trim($_GET['ev']) !== '') {
  $rawEv = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$_GET['ev']));
  if (strlen($rawEv) === 64) {
    $verifiedEmail = $emailVerification->consumeVerifyToken($projectId, $rawEv);
    if ($verifiedEmail !== null) {
      Auth::setVisitorEmail($projectId, $verifiedEmail);
      Auth::startSession();
      unset($_SESSION['gds_pending_verify_' . $projectId]);
      $qs = ['p' => $projectToken];
      if ($accessToken !== '') {
        $qs['t'] = $accessToken;
      }
      header('Location: index.php?' . http_build_query($qs));
      exit;
    }
  }
  renderHeader('Link invalid');
  $retry = 'index.php?' . http_build_query(['p' => $projectToken]);
  echo '<div class="card"><div class="err"><strong>This sign-in link is invalid or has expired.</strong></div>';
  echo '<p class="gds-lead"><a href="' . Util::h($retry) . '">Enter your email again</a> to receive a new link.</p></div>';
  renderFooter();
  exit;
}

// If access token present, bind it to session email (email-bound access).
if ($accessToken !== '') {
  $emailFromToken = $ndaSigning->validateAccessToken($projectId, $accessToken);
  if ($emailFromToken) {
    Auth::setVisitorEmail($projectId, $emailFromToken);
  }
}

$email = Auth::visitorEmail($projectId);

// Cookie-based return access: only applies when there is no verified session email.
// Must run before the email gate so returning visitors are not asked to re-enter their email,
// but must NOT override a session email that was just set by clicking a magic link.
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

// Email capture
if ($email === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_email') {
  if (!Auth::verifyCsrfToken((string)($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Session expired. Please refresh and try again.';
    exit;
  }
  $e = strtolower(trim((string)($_POST['email'] ?? '')));
  if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
    renderHeader('Enter email');
    echo '<div class="card"><div class="err"><strong>Please enter a valid email.</strong></div></div>';
    renderFooter();
    exit;
  }
  $sent = $emailVerification->sendVerificationLink($projectId, $projectToken, $e, (string)$project['name']);
  if (!$sent) {
    header('Location: index.php?' . http_build_query(['p' => $projectToken, 'mail_err' => '1']));
    exit;
  }
  Auth::startSession();
  $_SESSION['gds_pending_verify_' . $projectId] = $e;
  header('Location: index.php?' . http_build_query(['p' => $projectToken, 'sent' => '1']));
  exit;
}

if ($email === null) {
  renderHeader('Enter email');
  renderAnalyticsTracker($projectToken, 'email_gate', null);
  $pn = Util::h((string)$project['name']);
  $csrfField = Auth::csrfFieldHtml();
  $pendingKey = 'gds_pending_verify_' . $projectId;
  Auth::startSession();
  $pendingRaw = $_SESSION[$pendingKey] ?? '';
  $pendingEmail = is_string($pendingRaw) && $pendingRaw !== '' ? Util::h($pendingRaw) : '';

  if (isset($_GET['mail_err'])) {
    echo '<div class="card"><div class="err gds-flash"><strong>Could not send email.</strong> Ask the administrator to set <code>mail_from_address</code> and optional <code>smtp</code> in <code>config.php</code>, then try again.</div></div>';
  }

  if (isset($_GET['sent']) && $pendingEmail === '') {
    header('Location: index.php?' . http_build_query(['p' => $projectToken]));
    exit;
  }

  if (isset($_GET['sent']) && $pendingEmail !== '') {
    echo '<div class="card">';
    echo '<h2 class="gds-page-title">' . $pn . '</h2>';
    echo '<div class="ok gds-flash"><strong>Check your email.</strong> We sent a sign-in link to <strong>' . $pendingEmail . '</strong>. Open it on this device (or any device) to continue.</div>';
    echo '<p class="gds-lead muted">The link expires after a while. If nothing arrives, check spam or request another link.</p>';
    echo '<form method="post" style="margin-top:var(--gds-space-4)">';
    echo $csrfField;
    echo '<input type="hidden" name="action" value="set_email" />';
    echo '<div class="row" style="align-items:flex-end">';
    echo '<div class="gds-field" style="margin-bottom:0">';
    echo '<label class="gds-label" for="gds-email">Email address</label>';
    echo '<input id="gds-email" name="email" type="email" autocomplete="email" placeholder="you@company.com" value="' . $pendingEmail . '" required /></div>';
    echo '<div style="flex:0 0 auto;padding-bottom:2px"><button type="submit" class="btn btn-secondary">Resend link</button></div>';
    echo '</div></form></div>';
    renderFooter();
    exit;
  }

  echo <<<HTML
<div class="card">
  <h2 class="gds-page-title">{$pn}</h2>
  <p class="gds-lead">Enter your email and we’ll send you a link to confirm it and continue. Use the same email if you’ve already signed the NDA.</p>
  <form method="post">
    {$csrfField}
    <input type="hidden" name="action" value="set_email" />
    <div class="row" style="align-items:flex-end">
      <div class="gds-field" style="margin-bottom:0">
        <label class="gds-label" for="gds-email">Email address</label>
        <input id="gds-email" name="email" type="email" autocomplete="email" placeholder="you@company.com" required />
      </div>
      <div style="flex:0 0 auto;padding-bottom:2px">
        <button type="submit" class="btn btn-primary">Send sign-in link</button>
      </div>
    </div>
  </form>
</div>
HTML;
  renderFooter();
  exit;
}

$signed = $ndaSigning->hasSigned($projectId, $email);
$nda = $projects->getNda($projectId);
$fieldDefs = $projects->listNdaFieldDefs($projectId);

$draftSessKey = 'gds_sign_draft_' . $projectId;
$stepSessKey = 'gds_sign_step_' . $projectId;

$signFlowRedirect = function () use ($projectToken, $accessToken): void {
  $u = 'index.php?p=' . urlencode($projectToken);
  if ($accessToken !== '') {
    $u .= '&t=' . urlencode($accessToken);
  }
  header('Location: ' . $u);
  exit;
};

if (!$signed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
  if (!Auth::verifyCsrfToken((string)($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Session expired. Please refresh and try again.';
    exit;
  }
  $act = (string)$_POST['action'];
  if ($act === 'sign_step_back') {
    $to = (int)($_POST['step_to'] ?? 2);
    if ($to !== 1 && $to !== 2) {
      $to = 2;
    }
    $_SESSION[$stepSessKey] = $to;
    $signFlowRedirect();
  }

  if ($act === 'sign_step1') {
    $fn = trim((string)($_POST['signer_first_name'] ?? ''));
    $ln = trim((string)($_POST['signer_last_name'] ?? ''));
    $pos = trim((string)($_POST['signer_position'] ?? ''));
    $l1 = trim((string)($_POST['addr_line1'] ?? ''));
    $l2 = trim((string)($_POST['addr_line2'] ?? ''));
    $city = trim((string)($_POST['addr_city'] ?? ''));
    $region = trim((string)($_POST['addr_region'] ?? ''));
    $postal = trim((string)($_POST['addr_postal'] ?? ''));
    $country = trim((string)($_POST['addr_country'] ?? ''));
    $nm = Util::mergeSignerName($fn, $ln);
    $addr = Util::mergeSignerAddressParts($l1, $l2, $city, $region, $postal, $country);
    $errs = [];
    if ($fn === '' || $ln === '') {
      $errs[] = 'Please enter your first and last name.';
    }
    if ($pos === '') {
      $errs[] = 'Please enter your position or title.';
    }
    if ($l1 === '' || $city === '' || $region === '' || $postal === '') {
      $errs[] = 'Please complete address line 1, city, state/province, and postal code.';
    }
    if ($addr === '') {
      $errs[] = 'Please complete your mailing address.';
    }
    if ($errs) {
      renderHeader('Sign NDA');
      echo '<div class="card"><div class="err"><strong>' . Util::h($errs[0]) . '</strong></div></div>';
      renderFooter();
      exit;
    }
    $prevFt = [];
    if (isset($_SESSION[$draftSessKey]) && is_array($_SESSION[$draftSessKey]) && isset($_SESSION[$draftSessKey]['free_text']) && is_array($_SESSION[$draftSessKey]['free_text'])) {
      $prevFt = $_SESSION[$draftSessKey]['free_text'];
    }
    $_SESSION[$draftSessKey] = [
      'first_name' => $fn,
      'last_name' => $ln,
      'name' => $nm,
      'position' => $pos,
      'addr_line1' => $l1,
      'addr_line2' => $l2,
      'addr_city' => $city,
      'addr_region' => $region,
      'addr_postal' => $postal,
      'addr_country' => $country,
      'address' => $addr,
      'free_text' => $prevFt,
    ];
    $_SESSION[$stepSessKey] = 2;
    $signFlowRedirect();
  }

  if ($act === 'sign_step2') {
    $draft = $_SESSION[$draftSessKey] ?? null;
    if (!is_array($draft) || trim((string)($draft['name'] ?? '')) === '') {
      $_SESSION[$stepSessKey] = 1;
      $signFlowRedirect();
    }
    $ft = [];
    if (isset($_POST['free_text']) && is_array($_POST['free_text'])) {
      foreach ($_POST['free_text'] as $k => $v) {
        $ft[(string)(int)$k] = trim((string)$v);
      }
    }
    $draft['free_text'] = $ft;
    $_SESSION[$draftSessKey] = $draft;
    $_SESSION[$stepSessKey] = 3;
    $signFlowRedirect();
  }
}

// Handle signing submit (step 3)
if (!$signed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_nda') {
  if (!Auth::verifyCsrfToken((string)($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Session expired. Please refresh and try again.';
    exit;
  }
  $sig = (string)($_POST['signature_png'] ?? '');
  $signedPdfB64 = (string)($_POST['signed_pdf_b64'] ?? '');
  $accept = (string)($_POST['accept'] ?? '');

  $draft = $_SESSION[$draftSessKey] ?? null;
  $stepNow = (int)($_SESSION[$stepSessKey] ?? 1);
  if (is_array($draft)) {
    $draft = Util::normalizeSignerDraft($draft);
    $_SESSION[$draftSessKey] = $draft;
  }

  $errors = [];
  if ($nda === null) $errors[] = 'NDA template is not configured for this project yet.';
  if (!is_array($draft) || $stepNow !== 3) {
    $errors[] = 'Please complete all steps before signing.';
  }
  $name = is_array($draft) ? trim((string)($draft['name'] ?? '')) : '';
  $pos = is_array($draft) ? trim((string)($draft['position'] ?? '')) : '';
  $addr = is_array($draft) ? trim((string)($draft['address'] ?? '')) : '';
  if ($name === '') $errors[] = 'Name is required.';
  if ($pos === '') $errors[] = 'Position/Title is required.';
  if ($addr === '') $errors[] = 'Address is required.';
  if ($accept !== 'yes') $errors[] = 'You must accept the NDA.';
  if ($sig === '') $errors[] = 'Signature is required.';
  if ($signedPdfB64 === '') $errors[] = 'Signed PDF could not be generated (please try again).';

  if ($errors) {
    renderHeader('Sign NDA');
    echo '<div class="card"><div class="err"><strong>Unable to submit:</strong><ul class="list">';
    foreach ($errors as $er) {
      echo '<li>' . Util::h($er) . '</li>';
    }
    echo '</ul></div></div>';
    renderFooter();
    exit;
  }

  $dirs = $projects->ensureProjectDirs($projectId);
  $sigFile = $dirs['signatures'] . '/sig_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.png';
  $ndaSigning->saveSignaturePng($sig, $sigFile);

  $signedPdfPath = null;
  $pdfBin = base64_decode($signedPdfB64, true);
  if ($pdfBin !== false && str_starts_with($pdfBin, '%PDF')) {
    $signedPdfPath = $dirs['signed'] . '/signed_nda_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.pdf';
    file_put_contents($signedPdfPath, $pdfBin);
  } else {
    $signedPdfPath = null;
  }

  $receiptPath = $dirs['signed'] . '/receipt_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.html';
  $sigForHtml = Util::h($sig);
  $receiptHtml = '<h1>Signed NDA Receipt</h1>'
    . '<p><strong>Project:</strong> ' . Util::h((string)$project['name']) . '</p>'
    . '<p><strong>Email:</strong> ' . Util::h($email) . '</p>'
    . '<p><strong>Name:</strong> ' . Util::h($name) . '</p>'
    . '<p><strong>Position:</strong> ' . Util::h($pos) . '</p>'
    . '<p><strong>Address:</strong><br>' . nl2br(Util::h($addr), false) . '</p>'
    . '<p><strong>Signed at (UTC):</strong> ' . Util::h(gmdate('c')) . '</p>'
    . '<p><strong>NDA file:</strong> ' . Util::h((string)$nda['original_name']) . '</p>'
    . '<p><strong>Signature image:</strong><br/><img src="' . $sigForHtml . '" alt="Signature" style="max-width:420px;border:1px solid #ddd"/></p>';
  file_put_contents($receiptPath, $receiptHtml);

  $ndaSigning->recordSigning([
    'project_id' => $projectId,
    'signer_email' => $email,
    'signer_name' => $name,
    'signer_position' => $pos,
    'signer_address' => $addr,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'signature_image_path' => $sigFile,
    'signed_receipt_path' => $receiptPath,
    'signed_pdf_path' => $signedPdfPath,
  ]);

  unset($_SESSION[$draftSessKey], $_SESSION[$stepSessKey]);

  $token = $ndaSigning->issueAccessToken($projectId, $email);
  setcookie('gds_access_' . $projectId, $token, [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  header('Location: index.php?p=' . urlencode($projectToken));
  exit;
}

// Sign-out: clear session email and access cookie so the user can re-enter their email.
if (isset($_GET['signout'])) {
  Auth::startSession();
  unset($_SESSION['visitor_email_' . $projectId]);
  setcookie('gds_access_' . $projectId, '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  header('Location: index.php?p=' . urlencode($projectToken));
  exit;
}

if ($signed) {
  $invSet = $investment->getSettings($projectId);
  $invContractRow = $investment->getContract($projectId);
  $invFieldCount = count($investment->listContractFieldDefs($projectId));
  $invReady = ((int)($invSet['enabled'] ?? 0)) === 1 && $invContractRow !== null && $invFieldCount > 0;
  $invClosed = $invReady && $investment->isFundingClosed($projectId);
  $myCommit = null;
  if (((int)($invSet['enabled'] ?? 0)) === 1) {
    $myCommit = $investment->getCommitment($projectId, $email);
  }

  if ($invClosed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'inv_waitlist') {
    if (!Auth::verifyCsrfToken((string)($_POST['_csrf'] ?? ''))) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'Session expired. Please refresh and try again.';
      exit;
    }
    $errs = [];
    $fullName = trim((string)($_POST['waitlist_full_name'] ?? ''));
    $wlEmail = strtolower(trim((string)($_POST['waitlist_email'] ?? '')));
    $phone = trim((string)($_POST['waitlist_phone'] ?? ''));
    $l1 = trim((string)($_POST['wl_addr_line1'] ?? ''));
    $l2 = trim((string)($_POST['wl_addr_line2'] ?? ''));
    $city = trim((string)($_POST['wl_addr_city'] ?? ''));
    $region = trim((string)($_POST['wl_addr_region'] ?? ''));
    $postal = trim((string)($_POST['wl_addr_postal'] ?? ''));
    $country = trim((string)($_POST['wl_addr_country'] ?? ''));
    $damtRaw = trim((string)($_POST['waitlist_desired_amount'] ?? ''));
    $damt = (float)str_replace([',', ' '], '', $damtRaw);
    $goalCur = strtoupper(trim((string)($invSet['goal_currency'] ?? 'USD')));
    if ($goalCur === '') {
      $goalCur = 'USD';
    }

    if ($fullName === '') {
      $errs[] = 'Please enter your name.';
    }
    if (!filter_var($wlEmail, FILTER_VALIDATE_EMAIL)) {
      $errs[] = 'Please enter a valid email address.';
    }
    if (strlen(preg_replace('/\D/', '', $phone)) < 7) {
      $errs[] = 'Please enter a valid phone number.';
    }
    if ($l1 === '' || $city === '' || $region === '' || $postal === '') {
      $errs[] = 'Please complete address line 1, city, state/province, and postal code.';
    }
    if (!is_finite($damt) || $damt <= 0) {
      $errs[] = 'Please enter the amount you hoped to contribute.';
    }

    $addr = Util::mergeSignerAddressParts($l1, $l2, $city, $region, $postal, $country);

    if ($errs) {
      $_SESSION['gds_wl_err_' . $projectId] = $errs[0];
      $_SESSION['gds_wl_old_' . $projectId] = $_POST;
    } else {
      $investment->upsertWaitlistEntry($projectId, [
        'full_name' => $fullName,
        'email' => $wlEmail,
        'phone' => $phone,
        'address' => $addr,
        'desired_amount' => $damt,
        'desired_currency' => $goalCur,
      ]);
      $adminTo = trim((string)($config['waitlist_notify_email'] ?? ''));
      if ($adminTo === '' || !filter_var($adminTo, FILTER_VALIDATE_EMAIL)) {
        $adminTo = $db->getFirstAdminEmail() ?? '';
      }
      if ($adminTo !== '' && filter_var($adminTo, FILTER_VALIDATE_EMAIL)) {
        $pname = (string)$project['name'];
        $subject = 'Put me on the waitlist for this project: ' . $pname;
        $adminUrl = Util::baseUrl($config) . '/admin/index.php?view=project&project_id=' . $projectId;
        $text = "Someone requested to be on the funding waitlist.\r\n\r\n"
          . "Project: {$pname}\r\nAdmin: {$adminUrl}\r\n\r\n"
          . "Name: {$fullName}\r\nEmail: {$wlEmail}\r\nPhone: {$phone}\r\n"
          . "Amount they hoped to contribute: {$goalCur} " . number_format($damt, 2) . "\r\n\r\nMailing address:\r\n"
          . str_replace("\r\n", "\n", $addr) . "\r\n";
        $safe = static fn(string $s): string => Util::h($s);
        $html = '<p><strong>Put me on the waitlist for this project</strong></p>'
          . '<p>Project: <strong>' . $safe($pname) . '</strong></p>'
          . '<p><a href="' . $safe($adminUrl) . '">Open this project in admin</a></p>'
          . '<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;font-size:14px;max-width:560px">'
          . '<tr><th align="left">Name</th><td>' . $safe($fullName) . '</td></tr>'
          . '<tr><th align="left">Email</th><td>' . $safe($wlEmail) . '</td></tr>'
          . '<tr><th align="left">Phone</th><td>' . $safe($phone) . '</td></tr>'
          . '<tr><th align="left">Desired commitment</th><td>' . $safe($goalCur . ' ' . number_format($damt, 2)) . '</td></tr>'
          . '<tr><th align="left">Address</th><td>' . nl2br($safe($addr), false) . '</td></tr>'
          . '</table>';
        if (!Mail::sendOutbound($config, $adminTo, $subject, $text, $html)) {
          error_log('[gds] waitlist: saved to DB but admin email failed for project ' . $projectId);
        }
      } else {
        error_log('[gds] waitlist: set waitlist_notify_email in config or add an admin user to receive email');
      }
      unset($_SESSION['gds_wl_err_' . $projectId], $_SESSION['gds_wl_old_' . $projectId]);
      $_SESSION['gds_wl_ok_' . $projectId] = true;
    }
    $rQ = ['p' => $projectToken];
    if ($accessToken !== '') {
      $rQ['t'] = $accessToken;
    }
    header('Location: index.php?' . http_build_query($rQ));
    exit;
  }

  $invAct = (string)($_POST['action'] ?? '');
  $invPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $invAct !== '' && str_starts_with($invAct, 'inv_') && $invAct !== 'inv_waitlist';
  if ($invReady && !$invClosed && ($invPost || isset($_GET['invest']))) {
    require __DIR__ . '/investment-sign-flow.php';
    exit;
  }

  renderHeader('Files');
  renderAnalyticsTracker($projectToken, 'files', $email);
  $wlFlashOk = !empty($_SESSION['gds_wl_ok_' . $projectId]);
  if ($wlFlashOk) {
    unset($_SESSION['gds_wl_ok_' . $projectId]);
  }
  $wlFlashErr = '';
  $wlOld = [];
  if (!empty($_SESSION['gds_wl_err_' . $projectId])) {
    $wlFlashErr = (string)$_SESSION['gds_wl_err_' . $projectId];
    unset($_SESSION['gds_wl_err_' . $projectId]);
  }
  if (!empty($_SESSION['gds_wl_old_' . $projectId]) && is_array($_SESSION['gds_wl_old_' . $projectId])) {
    $wlOld = $_SESSION['gds_wl_old_' . $projectId];
    unset($_SESSION['gds_wl_old_' . $projectId]);
  }
  $wlv = static function (array $old, string $k): string {
    if (!isset($old[$k])) {
      return '';
    }
    $v = $old[$k];
    return Util::h(is_scalar($v) ? (string)$v : '');
  };
  $pn = Util::h((string)$project['name']);
  $signoutHref = Util::h('index.php?p=' . urlencode($projectToken) . '&signout=1');
  echo '<div class="card">';
  echo '<h2 class="gds-page-title">' . $pn . '</h2>';
  echo '<p class="gds-lead">Signed as <strong>' . Util::h($email) . '</strong>. <a href="' . $signoutHref . '" class="muted" style="font-size:.875em">Not you?</a></p>';
  $signedHref = 'download.php?p=' . urlencode($projectToken) . '&signed_nda=1';
  echo '<div class="gds-card-header" style="margin-top:0">';
  echo '<div class="gds-section-title" style="margin:0">Your files</div>';
  echo '</div>';

  if (((int)($invSet['enabled'] ?? 0)) === 1) {
    if (!$invReady) {
      echo '<div class="muted gds-flash" style="margin-top:var(--gds-space-3)">Investment module is on. Ask your administrator to upload the investment contract PDF and place fields so visitors can pledge here.</div>';
    } else {
      $totalCommitted = $investment->getTotalCommitted($projectId);
      $goalAmt = (float)($invSet['goal_amount'] ?? 0);
      $goalCur = (string)($invSet['goal_currency'] ?? 'USD');
      $pct = ($goalAmt > 0) ? min(100.0, ($totalCommitted / $goalAmt) * 100.0) : 0.0;
      $bq = ['p' => $projectToken, 'invest' => '1'];
      if ($accessToken !== '') {
        $bq['t'] = $accessToken;
      }
      $investHref = 'index.php?' . http_build_query($bq);
      $wlQ = ['p' => $projectToken];
      if ($accessToken !== '') {
        $wlQ['t'] = $accessToken;
      }
      $wlFormAction = 'index.php?' . http_build_query($wlQ);
      $wlEmailInput = isset($wlOld['waitlist_email']) ? $wlv($wlOld, 'waitlist_email') : Util::h($email);
      echo '<div class="gds-investment-card" style="margin-top:var(--gds-space-4);padding-top:var(--gds-space-4);border-top:1px solid var(--gds-border)">';
      echo '<div class="gds-section-title" style="margin-bottom:var(--gds-space-2)">Funding progress</div>';
      echo '<p class="gds-lead" style="margin-top:0"><strong>' . Util::h($goalCur . ' ' . number_format($totalCommitted, 0)) . '</strong> committed';
      if ($goalAmt > 0) {
        echo ' of <strong>' . Util::h($goalCur . ' ' . number_format($goalAmt, 0)) . '</strong> goal';
      }
      echo '</p>';
      if ($goalAmt > 0) {
        $barCls = 'gds-investment-bar' . ($invClosed ? ' gds-investment-bar--complete' : '');
        $wPct = min(100, round($pct, 2));
        $fillStyle = 'width:' . Util::h((string)$wPct) . '%';
        $gbrand = $GLOBALS['gds_branding'] ?? [];
        $fpHex = isset($gbrand['funding_progress_color']) && is_string($gbrand['funding_progress_color'])
          ? trim($gbrand['funding_progress_color'])
          : '';
        if ($fpHex !== '') {
          $fillStyle .= ';background:' . Util::h($fpHex);
        }
        echo '<div class="' . $barCls . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int)round($pct) . '">';
        echo '<div class="gds-investment-bar__fill" style="' . $fillStyle . '"></div></div>';
      }
      $eqOfferedSet = (float)($invSet['equity_offered_pct'] ?? 0);
      if ($goalAmt > 0 && $eqOfferedSet > 0) {
        $myCommittedAmt = $myCommit ? (float)($myCommit['committed_amount'] ?? 0) : 0.0;
        $allocList = $investment->listAllocations($projectId);
        echo Investment::equityPieSvg(
          $eqOfferedSet,
          $goalAmt,
          (float)$totalCommitted,
          $myCommittedAmt,
          $goalCur,
          $fpHex !== '' ? $fpHex : null,
          $allocList,
        );
      }
      if ($invClosed) {
        if ($wlFlashOk) {
          echo '<div class="ok gds-flash" style="margin-top:var(--gds-space-3)"><strong>You’re on the list.</strong> We’ll use your details if a spot opens up.</div>';
        }
        echo '<div class="gds-funded-goal" style="margin-top:var(--gds-space-4)">';
        echo '<div class="gds-funded-goal__icon" aria-hidden="true">✓</div>';
        echo '<div><strong>Goal reached</strong><p class="gds-lead" style="margin:var(--gds-space-2) 0 0">This project is fully funded. New commitments are closed.</p></div>';
        echo '</div>';
        echo '<div class="gds-actions" style="margin-top:var(--gds-space-4)">';
        echo '<button type="button" class="btn btn-primary" id="gdsWlOpenBtn" aria-haspopup="dialog" aria-controls="gdsWlModal">Put me on the waitlist</button>';
        echo '</div>';
      }
      if ($myCommit) {
        $cam = (float)($myCommit['committed_amount'] ?? 0);
        $ccur = (string)($myCommit['currency'] ?? $goalCur);
        $impliedMy = $investment->impliedOwnershipPercent($cam, $invSet);
        echo '<p class="gds-lead" style="margin-top:var(--gds-space-3)">Your commitment: <strong>' . Util::h($ccur . ' ' . number_format($cam, 2)) . '</strong>';
        if ($impliedMy !== null) {
          echo '<br><span class="muted">Implied ownership at full goal:</span> <strong>' . Util::h(number_format($impliedMy, 2)) . '%</strong>';
        }
        echo '</p>';
        echo '<div class="gds-actions gds-actions--funding" style="flex-wrap:wrap">';
        if (!$invClosed) {
          echo '<a class="btn btn-primary gds-btn--compact" href="' . Util::h($investHref) . '">Update commitment</a>';
        }
        echo '</div>';
      } elseif (!$invClosed) {
        echo '<div class="gds-actions" style="margin-top:var(--gds-space-3)">';
        echo '<a class="btn btn-primary" href="' . Util::h($investHref) . '">Commit to this project</a>';
        echo '</div>';
      }
      echo '</div>';
      if ($invClosed) {
        $modalHidden = $wlFlashErr === '' ? ' hidden' : '';
        $rm = Util::requiredMark();
        echo '<div id="gdsWlModal" class="gds-modal"' . $modalHidden . ' role="dialog" aria-modal="true" aria-labelledby="gdsWlTitle" data-gds-wl-autoopen="' . ($wlFlashErr !== '' ? '1' : '0') . '">';
        echo '<div class="gds-modal__backdrop" id="gdsWlBackdrop" tabindex="-1"></div>';
        echo '<div class="gds-modal__panel">';
        echo '<div class="gds-modal__head"><h2 class="gds-modal__title" id="gdsWlTitle">Join the waitlist</h2>';
        echo '<button type="button" class="gds-modal__close" id="gdsWlCloseBtn" aria-label="Close">&times;</button></div>';
        echo '<p class="gds-lead" style="margin-top:0">If space opens, we can reach you using the information below.</p>';
        if ($wlFlashErr !== '') {
          echo '<div class="err gds-flash" style="margin-bottom:var(--gds-space-3)"><strong>' . Util::h($wlFlashErr) . '</strong></div>';
        }
        echo '<form method="post" action="' . Util::h($wlFormAction) . '" class="gds-sign-detail-form" style="margin-top:0">';
        echo Auth::csrfFieldHtml();
        echo '<input type="hidden" name="action" value="inv_waitlist" />';
        echo '<p class="gds-help" style="margin-bottom:var(--gds-space-3)">Fields marked with <span class="gds-req" aria-hidden="true">*</span> are required.</p>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_name">Your name' . $rm . '</label>';
        echo '<input id="wl_name" name="waitlist_full_name" type="text" required autocomplete="name" value="' . $wlv($wlOld, 'waitlist_full_name') . '" /></div>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_email">Email address' . $rm . '</label>';
        echo '<input id="wl_email" name="waitlist_email" type="email" required autocomplete="email" value="' . $wlEmailInput . '" /></div>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_phone">Phone number' . $rm . '</label>';
        echo '<input id="wl_phone" name="waitlist_phone" type="tel" required autocomplete="tel" value="' . $wlv($wlOld, 'waitlist_phone') . '" /></div>';
        echo '<div class="gds-form-section" style="border-bottom:0;padding-bottom:0;margin-bottom:var(--gds-space-3)">';
        echo '<div class="gds-section-title">Mailing address</div>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_a1">Address line 1' . $rm . '</label>';
        echo '<input id="wl_a1" name="wl_addr_line1" type="text" required autocomplete="address-line1" value="' . $wlv($wlOld, 'wl_addr_line1') . '" /></div>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_a2">Address line 2 <span class="muted" style="font-weight:500">(optional)</span></label>';
        echo '<input id="wl_a2" name="wl_addr_line2" type="text" autocomplete="address-line2" value="' . $wlv($wlOld, 'wl_addr_line2') . '" /></div>';
        echo '<div class="row">';
        echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="wl_city">City' . $rm . '</label>';
        echo '<input id="wl_city" name="wl_addr_city" type="text" required autocomplete="address-level2" value="' . $wlv($wlOld, 'wl_addr_city') . '" /></div>';
        echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="wl_reg">State / province' . $rm . '</label>';
        echo '<input id="wl_reg" name="wl_addr_region" type="text" required autocomplete="address-level1" value="' . $wlv($wlOld, 'wl_addr_region') . '" /></div>';
        echo '</div><div class="row">';
        echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="wl_zip">ZIP / postal code' . $rm . '</label>';
        echo '<input id="wl_zip" name="wl_addr_postal" type="text" required autocomplete="postal-code" value="' . $wlv($wlOld, 'wl_addr_postal') . '" /></div>';
        echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="wl_ctry">Country <span class="muted" style="font-weight:500">(optional)</span></label>';
        echo '<input id="wl_ctry" name="wl_addr_country" type="text" autocomplete="country-name" value="' . $wlv($wlOld, 'wl_addr_country') . '" /></div>';
        echo '</div></div>';
        echo '<div class="gds-field"><label class="gds-label" for="wl_amt">Amount you hoped to contribute (' . Util::h($goalCur) . ')' . $rm . '</label>';
        echo '<input id="wl_amt" name="waitlist_desired_amount" type="text" inputmode="decimal" required placeholder="25000" value="' . $wlv($wlOld, 'waitlist_desired_amount') . '" /></div>';
        echo '<div class="gds-actions" style="margin-top:var(--gds-space-4)"><button type="submit" class="btn btn-primary">Notify me if someone drops out</button></div>';
        echo '</form></div></div>';
        echo '<script>(function(){var m=document.getElementById("gdsWlModal");if(!m)return;var open=document.getElementById("gdsWlOpenBtn");var close=document.getElementById("gdsWlCloseBtn");var bd=document.getElementById("gdsWlBackdrop");function show(){m.removeAttribute("hidden");m.setAttribute("aria-hidden","false");document.body.style.overflow="hidden";var f=m.querySelector("input:not([type=hidden])");if(f)f.focus();}function hide(){m.setAttribute("hidden","");m.setAttribute("aria-hidden","true");document.body.style.overflow="";}if(open)open.addEventListener("click",show);if(close)close.addEventListener("click",hide);if(bd)bd.addEventListener("click",hide);if(m.getAttribute("data-gds-wl-autoopen")==="1")show();document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!m.hasAttribute("hidden"))hide();});})();</script>';
      }
    }
  }

  $signedContractHref = 'download.php?p=' . urlencode($projectToken) . '&signed_contract=1' . ($accessToken !== '' ? '&t=' . urlencode($accessToken) : '');
  $hasSignedContractPdf = $myCommit && !empty($myCommit['signed_pdf_path']) && is_file((string)$myCommit['signed_pdf_path']);
  echo '<div class="gds-signed-docs">';
  echo '<div class="gds-signed-docs__label">Signed documents</div>';
  echo '<div class="gds-actions gds-signed-docs__actions">';
  echo '<a class="btn btn-secondary gds-btn--compact" href="' . Util::h($signedHref) . '" target="gds_download_frame" rel="noopener">Download signed NDA</a>';
  if ($hasSignedContractPdf) {
    echo '<a class="btn btn-secondary gds-btn--compact" href="' . Util::h($signedContractHref) . '" target="gds_download_frame" rel="noopener">Download signed contract</a>';
  }
  echo '</div></div>';

  $files = $projects->listFiles($projectId);

  if (!function_exists('gdsFileTypeIcon')) {
    function gdsFileTypeIcon(string $filename): string {
      $ext = strtolower(pathinfo(trim($filename), PATHINFO_EXTENSION));
      $s = ' width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true" focusable="false">';

      // PDF — red
      if ($ext === 'pdf') {
        return '<svg class="fileIcon"' . $s
          . '<rect width="28" height="28" rx="6" fill="#EF4444"/>'
          . '<rect x="7" y="8" width="14" height="2" rx="1" fill="white"/>'
          . '<rect x="7" y="13" width="14" height="2" rx="1" fill="white"/>'
          . '<rect x="7" y="18" width="9" height="2" rx="1" fill="white"/>'
          . '</svg>';
      }
      // Images — purple
      if (in_array($ext, ['jpg','jpeg','jfif','pjpeg','png','gif','webp','bmp','svg','ico','avif','heic','heif','tif','tiff'], true)) {
        return '<svg class="fileIcon"' . $s
          . '<rect width="28" height="28" rx="6" fill="#8B5CF6"/>'
          . '<rect x="6" y="9" width="16" height="11" rx="2" stroke="white" stroke-width="1.5" fill="none"/>'
          . '<circle cx="10" cy="12.5" r="1.5" fill="white"/>'
          . '<path d="M7 19 L11.5 14 L16 19 Z" fill="white" opacity="0.9"/>'
          . '<path d="M15 19 L19 15.5 L21 19 Z" fill="white" opacity="0.75"/>'
          . '</svg>';
      }
      // Spreadsheets — green
      if (in_array($ext, ['xlsx','xlsm','xlsb','xls','ods','csv','tsv'], true)) {
        return '<svg class="fileIcon"' . $s
          . '<rect width="28" height="28" rx="6" fill="#22C55E"/>'
          . '<rect x="6" y="7" width="7" height="6" rx="1.5" fill="white"/>'
          . '<rect x="15" y="7" width="7" height="6" rx="1.5" fill="white" opacity="0.8"/>'
          . '<rect x="6" y="15" width="7" height="6" rx="1.5" fill="white" opacity="0.8"/>'
          . '<rect x="15" y="15" width="7" height="6" rx="1.5" fill="white" opacity="0.65"/>'
          . '</svg>';
      }
      // Word / DOCX — blue
      if (in_array($ext, ['docx','doc','odt','rtf'], true)) {
        return '<svg class="fileIcon"' . $s
          . '<rect width="28" height="28" rx="6" fill="#3B82F6"/>'
          . '<rect x="7" y="8" width="14" height="2.5" rx="1.25" fill="white"/>'
          . '<rect x="7" y="13" width="11" height="2" rx="1" fill="white" opacity="0.85"/>'
          . '<rect x="7" y="18" width="13" height="2" rx="1" fill="white" opacity="0.85"/>'
          . '</svg>';
      }
      // Text / code / markup — amber
      if (in_array($ext, ['txt','md','log','yaml','yml','json','xml'], true)) {
        return '<svg class="fileIcon"' . $s
          . '<rect width="28" height="28" rx="6" fill="#F59E0B"/>'
          . '<rect x="7" y="8" width="14" height="2" rx="1" fill="white"/>'
          . '<rect x="7" y="13" width="14" height="2" rx="1" fill="white" opacity="0.85"/>'
          . '<rect x="7" y="18" width="10" height="2" rx="1" fill="white" opacity="0.85"/>'
          . '</svg>';
      }
      // Generic — slate
      return '<svg class="fileIcon"' . $s
        . '<rect width="28" height="28" rx="6" fill="#6B7280"/>'
        . '<rect x="7" y="8" width="14" height="2" rx="1" fill="white"/>'
        . '<rect x="7" y="13" width="10" height="2" rx="1" fill="white" opacity="0.75"/>'
        . '<rect x="7" y="18" width="12" height="2" rx="1" fill="white" opacity="0.75"/>'
        . '</svg>';
    }
  }

  if (!$files) {
    echo '<div class="gds-empty">No files have been uploaded yet.</div>';
  } else {
    echo '<div class="gds-table-wrap"><table>';
    echo '<thead><tr><th style="width:62%">Name</th><th style="width:18%">Size</th><th style="width:20%">Uploaded</th></tr></thead><tbody>';
    foreach ($files as $f) {
      $id = (int)$f['id'];
      $orig = (string)$f['original_name'];
      $name = Util::h(Projects::displayName($f));
      $inAppView = Util::projectFilePreviewProfile($orig) !== null;
      $href = $inAppView
        ? ('viewer.php?p=' . urlencode($projectToken) . '&file_id=' . urlencode((string)$id))
        : ('download.php?p=' . urlencode($projectToken) . '&file_id=' . urlencode((string)$id));
      $dlTarget = $inAppView ? '' : ' target="gds_download_frame" rel="noopener"';
      $bytes = (int)$f['size_bytes'];
      $size = $bytes >= 1024 * 1024 ? round($bytes / (1024 * 1024), 2) . ' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B');
      $uploaded = Util::h((string)$f['created_at']);
      echo '<tr>';
      echo '<td><div class="fileName">' . gdsFileTypeIcon($orig) . '<a href="' . Util::h($href) . '"' . $dlTarget . '>' . $name . '</a></div></td>';
      echo '<td class="muted">' . Util::h($size) . '</td>';
      echo '<td class="muted">' . $uploaded . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
  // Hidden frame so file downloads do not navigate away from this page.
  echo '<iframe name="gds_download_frame" title="Download" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0);visibility:hidden" aria-hidden="true"></iframe>';
  echo '</div>';
  renderFooter();
  exit;
}

// Not signed yet: 3-step signing flow
renderHeader('Sign NDA');
renderAnalyticsTracker($projectToken, 'nda_sign', $email);
$pn = Util::h((string)$project['name']);

echo '<div class="card">';
echo '<h2 class="gds-page-title">' . $pn . '</h2>';
echo '<p class="gds-lead">Complete the steps below to review and sign the NDA.</p>';

if ($nda === null) {
  echo '<div class="err"><strong>This project is not ready.</strong> The NDA template has not been uploaded yet.</div>';
  echo '</div>';
  renderFooter();
  exit;
}

$ndaViewerHref = 'viewer.php?p=' . urlencode($projectToken) . '&nda=1';

$draft = $_SESSION[$draftSessKey] ?? null;
$step = (int)($_SESSION[$stepSessKey] ?? 1);
if (!is_array($draft)) {
  $step = 1;
  $_SESSION[$stepSessKey] = 1;
} else {
  if ($step < 1) {
    $step = 1;
  }
  if ($step > 3) {
    $step = 3;
  }
  if ($step >= 2 && trim((string)($draft['name'] ?? '')) === '') {
    $step = 1;
    $_SESSION[$stepSessKey] = 1;
  }
}

if (is_array($draft)) {
  $draft = Util::normalizeSignerDraft($draft);
  $_SESSION[$draftSessKey] = $draft;
}

$freeTextDefs = array_values(array_filter($fieldDefs, fn($d) => (string)($d['field_key'] ?? '') === 'free_text'));
$defsJson = json_encode($fieldDefs, JSON_UNESCAPED_SLASHES) ?: '[]';

$renderStepper = static function (int $active): void {
  $steps = [1 => 'Details', 2 => 'Review', 3 => 'Sign'];
  echo '<div class="stepper">';
  foreach ($steps as $n => $lbl) {
    $cls = ($n === $active) ? 'step active' : 'step';
    echo '<span class="' . $cls . '"><span class="num">' . (int)$n . '</span>' . Util::h($lbl) . '</span>';
  }
  echo '</div>';
};

if ($step === 1) {
  $renderStepper(1);
  $dp = is_array($draft) ? Util::h((string)($draft['position'] ?? '')) : '';
  $dFn = is_array($draft) ? Util::h((string)($draft['first_name'] ?? '')) : '';
  $dLn = is_array($draft) ? Util::h((string)($draft['last_name'] ?? '')) : '';
  $dL1 = is_array($draft) ? Util::h((string)($draft['addr_line1'] ?? '')) : '';
  $dL2 = is_array($draft) ? Util::h((string)($draft['addr_line2'] ?? '')) : '';
  $dCity = is_array($draft) ? Util::h((string)($draft['addr_city'] ?? '')) : '';
  $dReg = is_array($draft) ? Util::h((string)($draft['addr_region'] ?? '')) : '';
  $dZip = is_array($draft) ? Util::h((string)($draft['addr_postal'] ?? '')) : '';
  $dCtry = is_array($draft) ? Util::h((string)($draft['addr_country'] ?? '')) : '';
  echo '<form method="post" class="gds-sign-detail-form">';
  echo Auth::csrfFieldHtml();
  echo '<input type="hidden" name="action" value="sign_step1" />';

  $rm = Util::requiredMark();
  echo '<p class="gds-help" style="margin-bottom:var(--gds-space-3)">Fields marked with <span class="gds-req" aria-hidden="true">*</span> are required.</p>';
  echo '<div class="gds-form-section">';
  echo '<div class="gds-section-title">Your name</div>';
  echo '<div class="row">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_fn">First name' . $rm . '</label><input id="nda_fn" name="signer_first_name" type="text" required value="' . $dFn . '" autocomplete="given-name" /></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_ln">Last name' . $rm . '</label><input id="nda_ln" name="signer_last_name" type="text" required value="' . $dLn . '" autocomplete="family-name" /></div>';
  echo '</div>';
  echo '<div class="gds-field"><label class="gds-label" for="signer_position">Position / title' . $rm . '</label><input id="signer_position" name="signer_position" type="text" required value="' . $dp . '" autocomplete="organization-title" /></div>';
  echo '</div>';

  echo '<div class="gds-form-section">';
  echo '<div class="gds-section-title">Mailing address</div>';
  echo '<div class="gds-field"><label class="gds-label" for="nda_a1">Address line 1' . $rm . '</label><input id="nda_a1" name="addr_line1" type="text" required value="' . $dL1 . '" autocomplete="address-line1" placeholder="Street address, P.O. box" /></div>';
  echo '<div class="gds-field"><label class="gds-label" for="nda_a2">Address line 2 <span class="muted" style="font-weight:500">(optional)</span></label><input id="nda_a2" name="addr_line2" type="text" value="' . $dL2 . '" autocomplete="address-line2" placeholder="Apartment, suite, unit" /></div>';
  echo '<div class="row">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_city">City' . $rm . '</label><input id="nda_city" name="addr_city" type="text" required value="' . $dCity . '" autocomplete="address-level2" /></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_reg">State / province' . $rm . '</label><input id="nda_reg" name="addr_region" type="text" required value="' . $dReg . '" autocomplete="address-level1" /></div>';
  echo '</div>';
  echo '<div class="row">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_zip">ZIP / postal code' . $rm . '</label><input id="nda_zip" name="addr_postal" type="text" required value="' . $dZip . '" autocomplete="postal-code" /></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="nda_ctry">Country <span class="muted" style="font-weight:500">(optional)</span></label><input id="nda_ctry" name="addr_country" type="text" value="' . $dCtry . '" autocomplete="country-name" /></div>';
  echo '</div>';
  echo '</div>';

  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Continue</button></div>';
  echo '</form>';
  echo '</div>';
  renderFooter();
  exit;
}

if ($step === 2) {
  $renderStepper(2);
  echo '<div class="gds-sign-toolbar">';
  echo '<form method="post" style="margin:0">' . Auth::csrfFieldHtml() . '<input type="hidden" name="action" value="sign_step_back" /><input type="hidden" name="step_to" value="1" /><button type="submit" class="btn btn-secondary">Back</button></form>';
  echo '<a href="' . Util::h($ndaViewerHref) . '" class="muted">Open full-screen NDA</a>';
  echo '</div>';

  $draftForJs = [
    'name' => is_array($draft) ? (string)($draft['name'] ?? '') : '',
    'position' => is_array($draft) ? (string)($draft['position'] ?? '') : '',
    'address' => is_array($draft) ? (string)($draft['address'] ?? '') : '',
    'free_text' => (is_array($draft) && isset($draft['free_text']) && is_array($draft['free_text'])) ? $draft['free_text'] : [],
  ];
  $draftJson = json_encode($draftForJs, JSON_UNESCAPED_SLASHES) ?: '{}';

  echo '<form method="post">';
  echo Auth::csrfFieldHtml();
  echo '<input type="hidden" name="action" value="sign_step2" />';

  if ($freeTextDefs) {
    echo '<div style="margin:0 0 var(--gds-space-5)">';
    echo '<div class="gds-section-title">Optional fields on the NDA</div>';
    echo '<div class="row">';
    foreach ($freeTextDefs as $d) {
      $fid = (int)($d['id'] ?? 0);
      $label = trim((string)($d['field_label'] ?? ''));
      if ($label === '') {
        $label = 'Text ' . ($fid > 0 ? (string)$fid : '');
      }
      $val = '';
      if (is_array($draft) && isset($draft['free_text'][$fid])) {
        $val = (string)$draft['free_text'][$fid];
      } elseif (is_array($draft) && isset($draft['free_text'][(string)$fid])) {
        $val = (string)$draft['free_text'][(string)$fid];
      }
      echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="ft_' . $fid . '">' . Util::h($label) . '</label><input id="ft_' . $fid . '" name="free_text[' . $fid . ']" value="' . Util::h($val) . '" /></div>';
    }
    echo '</div></div>';
  }

  echo '<div class="gds-section-title">Preview — your details on the NDA</div>';
  echo '<div class="gds-nda-preview">';
  echo '<div class="gds-nda-preview-toolbar">';
  echo '<div class="muted">Page <span id="ndaPageLabel">1</span> / <span id="ndaPageCount">?</span></div>';
  echo '<div class="gds-nda-nav">';
  echo '<button type="button" id="ndaPrevBtn" class="btn btn-secondary">Prev</button>';
  echo '<button type="button" id="ndaNextBtn" class="btn btn-secondary">Next</button>';
  echo '</div></div>';
  echo '<div class="gds-nda-canvas-wrap" style="position:relative">';
  echo '<canvas id="ndaCanvas" style="display:block;width:100%;height:auto"></canvas>';
  echo '<div id="ndaOverlay" style="position:absolute;left:0;top:0"></div>';
  echo '</div>';
  echo '</div>';

  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Continue to signature</button></div>';
  echo '</form>';

  echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
  echo '<script>';
  echo 'const defs = ' . $defsJson . ';';
  echo 'const draft = ' . $draftJson . ';';
  echo 'const ndaUrl = "download.php?p=" + encodeURIComponent(' . json_encode($projectToken, JSON_UNESCAPED_SLASHES) . ') + "&nda=1";';
  echo <<<'JSEOF'
pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
const ndaCanvas = document.getElementById("ndaCanvas");
const ndaCtx = ndaCanvas.getContext("2d");
const overlay = document.getElementById("ndaOverlay");
const pageLabel = document.getElementById("ndaPageLabel");
const pageCountEl = document.getElementById("ndaPageCount");
let pdfDoc = null;
let pageNum = 1;

function labelFor(key) {
  return ({ signature:"Signature", signed_date:"Date", signer_name:"Name", signer_position:"Position", signer_address:"Address", free_text:"Free text" })[key] || key;
}

function formatSignedDate() {
  const d = new Date();
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return String(yyyy) + "-" + mm + "-" + dd;
}

function textForField(def) {
  const k = String(def.field_key || "");
  if (k === "signed_date") return formatSignedDate();
  if (k === "signer_name") return draft.name || "";
  if (k === "signer_position") return draft.position || "";
  if (k === "signer_address") return draft.address || "";
  if (k === "free_text") {
    const id = String(def.id || "");
    const el = document.querySelector('[name="free_text[' + id.replace(/"/g, "") + ']"]');
    return el ? (el.value || "") : ((draft.free_text && draft.free_text[id]) ? String(draft.free_text[id]) : "");
  }
  if (k === "signature") return "Signature";
  return "";
}

function syncOverlaySize() {
  const r = ndaCanvas.getBoundingClientRect();
  overlay.style.width = r.width + "px";
  overlay.style.height = r.height + "px";
  overlay.style.position = "absolute";
  overlay.style.left = "0px";
  overlay.style.top = "0px";
}

function renderOverlay() {
  overlay.innerHTML = "";
  syncOverlaySize();
  const r = overlay.getBoundingClientRect();
  (defs || []).forEach((d) => {
    if (!d || String(d.field_key || "") === "") return;
    if (Number(d.page_num || 1) !== pageNum) return;
    const el = document.createElement("div");
    el.style.position = "absolute";
    el.style.left = (Number(d.x) * r.width) + "px";
    el.style.top = (Number(d.y) * r.height) + "px";
    el.style.width = (Number(d.w) * r.width) + "px";
    el.style.height = (Number(d.h) * r.height) + "px";
    el.style.border = "1px solid rgba(0, 0, 0, 0.22)";
    el.style.background = "rgba(245, 245, 247, 0.96)";
    el.style.borderRadius = "8px";
    el.style.padding = "4px 6px";
    el.style.overflow = "hidden";
    el.style.color = "#1d1d1f";
    el.style.fontSize = "11px";
    el.style.lineHeight = "1.25";
    el.style.whiteSpace = "pre-wrap";
    el.style.wordBreak = "break-word";
    const k = String(d.field_key || "");
    const txt = textForField(d);
    el.textContent = txt || (String(d.field_label || "") || labelFor(k));
    overlay.appendChild(el);
  });
}

async function renderPage() {
  if (!pdfDoc) return;
  const page = await pdfDoc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.35 });
  ndaCanvas.width = viewport.width;
  ndaCanvas.height = viewport.height;
  await page.render({ canvasContext: ndaCtx, viewport }).promise;
  pageLabel.textContent = String(pageNum);
  renderOverlay();
}

document.getElementById("ndaPrevBtn").addEventListener("click", async () => {
  if (pageNum <= 1) return;
  pageNum -= 1;
  await renderPage();
});
document.getElementById("ndaNextBtn").addEventListener("click", async () => {
  if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
  pageNum += 1;
  await renderPage();
});

document.addEventListener("input", (e) => {
  const t = e.target;
  if (t && t.matches && t.matches('input[name^="free_text["]')) renderOverlay();
});

window.addEventListener("resize", () => { renderOverlay(); });

(async () => {
  try {
    const resp = await fetch(ndaUrl);
    if (!resp.ok) throw new Error("PDF fetch failed: " + resp.status);
    const bytes = await resp.arrayBuffer();
    const u8 = new Uint8Array(bytes);
    pdfDoc = await pdfjsLib.getDocument({ data: u8, disableWorker: true }).promise;
    pageCountEl.textContent = String(pdfDoc.numPages);
    await renderPage();
  } catch (e) {
    console.error(e);
    pageCountEl.textContent = "?";
  }
})();
JSEOF;
  echo '</script>';

  echo '</div>';
  renderFooter();
  exit;
}

// Step 3: signature + agreement
$renderStepper(3);
echo '<div class="gds-sign-toolbar">';
echo '<form method="post" style="margin:0">' . Auth::csrfFieldHtml() . '<input type="hidden" name="action" value="sign_step_back" /><input type="hidden" name="step_to" value="2" /><button type="submit" class="btn btn-secondary">Back</button></form>';
echo '<a href="' . Util::h($ndaViewerHref) . '" class="muted">Open full-screen NDA</a>';
echo '</div>';

$dName = is_array($draft) ? Util::h((string)($draft['name'] ?? '')) : '';
$dPos = is_array($draft) ? Util::h((string)($draft['position'] ?? '')) : '';
$dAddr = is_array($draft) ? Util::h((string)($draft['address'] ?? '')) : '';

echo '<form method="post" id="gdsSignForm">';
echo Auth::csrfFieldHtml();
echo <<<HTML
  <input type="hidden" name="action" value="sign_nda" />
  <input type="hidden" name="signer_name" value="{$dName}" />
  <input type="hidden" name="signer_position" value="{$dPos}" />
  <input type="hidden" name="signer_address" value="{$dAddr}" />
  <input type="hidden" name="signed_pdf_b64" id="signed_pdf_b64" />
HTML;

if ($freeTextDefs) {
  foreach ($freeTextDefs as $d) {
    $fid = (int)($d['id'] ?? 0);
    $val = '';
    if (is_array($draft) && isset($draft['free_text'][$fid])) {
      $val = (string)$draft['free_text'][$fid];
    } elseif (is_array($draft) && isset($draft['free_text'][(string)$fid])) {
      $val = (string)$draft['free_text'][(string)$fid];
    }
    echo '<input type="hidden" name="free_text[' . $fid . ']" value="' . Util::h($val) . '" />';
  }
}

echo <<<HTML
  <div class="gds-field gds-sig-field" style="margin-top:var(--gds-space-2)">
    <span class="gds-label">Signature</span>
    <p class="gds-help">Sign with your finger or mouse in the box below.</p>
    <div class="gds-sig-box">
      <canvas id="sig" width="820" height="220" aria-label="Signature drawing area" style="width:100%;height:220px;touch-action:none"></canvas>
      <div class="gds-sig-actions">
        <button type="button" class="btn btn-secondary" onclick="window.__gdsClearSig()">Clear</button>
      </div>
    </div>
    <input type="hidden" name="signature_png" id="signature_png" />
  </div>

  <div style="margin-top:var(--gds-space-5)">
    <div class="toggleRow">
      <div class="label"><strong>I agree</strong><div class="muted">I have read and agree to the NDA.</div></div>
      <label class="toggle" aria-label="I agree to the NDA">
        <input type="checkbox" name="accept" value="yes" />
        <span class="switch" aria-hidden="true"></span>
      </label>
    </div>
  </div>

  <div class="gds-actions">
    <button type="submit" class="btn btn-primary">Sign and continue</button>
  </div>
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script>
(() => {
  const defs = {$defsJson};
  pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  const pdfLib = window.PDFLib;

  const canvas = document.getElementById('sig');
  const ctx = canvas.getContext('2d');
  ctx.lineWidth = 2.5;
  ctx.lineCap = 'round';
  ctx.strokeStyle = '#111827';
  let drawing = false;
  let last = null;

  function pos(ev) {
    const rect = canvas.getBoundingClientRect();
    if (ev.touches && ev.touches[0]) {
      return { x: (ev.touches[0].clientX - rect.left) * (canvas.width / rect.width),
               y: (ev.touches[0].clientY - rect.top) * (canvas.height / rect.height) };
    }
    return { x: (ev.clientX - rect.left) * (canvas.width / rect.width),
             y: (ev.clientY - rect.top) * (canvas.height / rect.height) };
  }

  function down(ev) {
    drawing = true;
    last = pos(ev);
    ev.preventDefault();
  }
  function move(ev) {
    if (!drawing) return;
    const p = pos(ev);
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    last = p;
    ev.preventDefault();
  }
  function up(ev) {
    drawing = false;
    last = null;
    ev.preventDefault();
  }

  canvas.addEventListener('mousedown', down);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', up);

  canvas.addEventListener('touchstart', down, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  window.addEventListener('touchend', up, { passive: false });

  window.__gdsClearSig = () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  };

  const form = document.getElementById("gdsSignForm");
  if (form) {
    form.addEventListener("submit", async (e) => {
      if (form.dataset.gdsAllowSubmit === "1") return;
      e.preventDefault();

      const accept = document.querySelector('input[name="accept"]');
      if (!accept || !accept.checked) {
        alert("Please confirm that you agree to the NDA.");
        return;
      }

      const png = canvas.toDataURL("image/png");
      const empty = document.createElement("canvas");
      empty.width = canvas.width;
      empty.height = canvas.height;
      if (png === empty.toDataURL("image/png")) {
        alert("Please provide a signature.");
        return;
      }
      document.getElementById("signature_png").value = png;

      const btn = form.querySelector('button[type="submit"]');
      const old = btn ? btn.textContent : "";
      try {
        if (btn) {
          btn.disabled = true;
          btn.textContent = "Generating PDF…";
        }

        const bytes = await generateSignedPdf(png);
        let binary = "";
        const chunk = 0x8000;
        const u8 = new Uint8Array(bytes);
        for (let i = 0; i < u8.length; i += chunk) {
          binary += String.fromCharCode.apply(null, u8.subarray(i, i + chunk));
        }
        document.getElementById("signed_pdf_b64").value = btoa(binary);

        form.dataset.gdsAllowSubmit = "1";
        if (btn) {
          btn.textContent = old;
          btn.disabled = false;
        }
        form.submit();
      } catch (err) {
        console.error(err);
        alert("Could not generate the signed PDF. Please try again.");
        if (btn) {
          btn.disabled = false;
          btn.textContent = old || "Sign and continue";
        }
      }
    });
  }

  function formatSignedDate() {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, "0");
    const dd = String(d.getDate()).padStart(2, "0");
    return String(yyyy) + "-" + mm + "-" + dd;
  }

  function toPdfCoords(page, d) {
    const w = page.getWidth();
    const h = page.getHeight();
    const x = Number(d.x) * w;
    const boxW = Number(d.w) * w;
    const boxH = Number(d.h) * h;
    const y = h - (Number(d.y) * h) - boxH;
    return { x, y, w: boxW, h: boxH };
  }

  function drawAddressBlock(page, text, box, font, rgbColor) {
    const raw = String(text || "").replace(/\r/g, "").split("\n").map((s) => s.replace(/\s+/g, " ").trim()).filter((s) => s.length > 0);
    if (raw.length === 0) return;
    let fontSize = Math.max(7, Math.min(12, box.h * 0.2));
    const lineHeight = fontSize * 1.22;
    const maxLines = Math.max(1, Math.floor((box.h - 6) / lineHeight));
    const lines = raw.slice(0, maxLines);
    let baseline = box.y + box.h - 4 - fontSize;
    for (const line of lines) {
      if (baseline < box.y + 2) break;
      page.drawText(line, {
        x: box.x + 3,
        y: baseline,
        size: fontSize,
        font,
        color: rgbColor,
        maxWidth: Math.max(0, box.w - 6),
      });
      baseline -= lineHeight;
    }
  }

  async function generateSignedPdf(signatureDataUrl) {
    const name = (document.querySelector('input[name="signer_name"]') || {}).value || "";
    const position = (document.querySelector('input[name="signer_position"]') || {}).value || "";
    const address = (document.querySelector('input[name="signer_address"]') || {}).value || "";
    const dateStr = formatSignedDate();

    const ndaUrl = "download.php?p=" + encodeURIComponent("{$projectToken}") + "&nda=1";
    const ndaBytes = await fetch(ndaUrl).then(r => r.arrayBuffer());

    const doc = await pdfLib.PDFDocument.load(ndaBytes);
    const pages = doc.getPages();
    const font = await doc.embedFont(pdfLib.StandardFonts.Helvetica);

    const sigPngBytes = await fetch(signatureDataUrl).then(r => r.arrayBuffer());
    const sigImage = await doc.embedPng(sigPngBytes);

    const entries = (defs || []).filter(d => d && d.field_key);
    for (const def of entries) {
      const key = String(def.field_key || "");
      const pg = Math.max(1, Number(def.page_num || 1));
      if (pg > pages.length) continue;
      const page = pages[pg - 1];
      const box = toPdfCoords(page, def);

      if (key === "signature") {
        const imgDims = sigImage.scale(1);
        const scale = Math.min(box.w / imgDims.width, box.h / imgDims.height);
        const drawW = imgDims.width * scale;
        const drawH = imgDims.height * scale;
        page.drawImage(sigImage, {
          x: box.x + (box.w - drawW) / 2,
          y: box.y + (box.h - drawH) / 2,
          width: drawW,
          height: drawH,
        });
      } else {
        let text = "";
        if (key === "signed_date") text = dateStr;
        if (key === "signer_name") text = name;
        if (key === "signer_position") text = position;
        if (key === "signer_address") text = address.replace(/\r\n/g, "\n").trim();
        if (key === "free_text") {
          const id = String(def.id || "");
          const el = document.querySelector('[name="free_text[' + id + ']"]');
          text = el ? (el.value || "") : "";
        }
        if (!text) continue;

        const box = toPdfCoords(page, def);
        const rgbColor = pdfLib.rgb(0.07, 0.09, 0.12);
        if (key === "signer_address") {
          drawAddressBlock(page, text, box, font, rgbColor);
        } else {
          const fontSize = Math.max(9, Math.min(14, box.h * 0.65));
          page.drawText(text.replace(/\s+/g, " ").trim(), {
            x: box.x + 3,
            y: box.y + Math.max(2, (box.h - fontSize) / 2),
            size: fontSize,
            font,
            color: rgbColor,
            maxWidth: Math.max(0, box.w - 6),
          });
        }
      }
    }

    return await doc.save();
  }
})();
</script>
HTML;

echo '</div>';
renderFooter();
