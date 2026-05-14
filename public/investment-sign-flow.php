<?php

declare(strict_types=1);

/**
 * Investment contract signing (3 steps). Included from index.php when ?invest=1 and module is ready.
 * Expects in scope: $config, $projects, $ndaSigning, $investment, $project, $projectId, $projectToken, $email, $accessToken
 */

Auth::startSession();

$invDraftKey = 'gds_inv_draft_' . $projectId;
$invStepKey = 'gds_inv_step_' . $projectId;

$invSettings = $investment->getSettings($projectId);
$invContract = $investment->getContract($projectId);
$invFieldDefs = $investment->listContractFieldDefs($projectId);

if (((int)($invSettings['enabled'] ?? 0)) !== 1 || !$invContract || $invFieldDefs === []) {
  header('Location: index.php?p=' . urlencode($projectToken) . ($accessToken !== '' ? '&t=' . urlencode($accessToken) : ''));
  exit;
}

$sigRec = $ndaSigning->getSignatureRecord($projectId, $email);
$currency = (string)($invSettings['goal_currency'] ?? 'USD');
$minCommit = $invSettings['min_commitment'];

$invRedirect = static function (string $projectToken, string $accessToken): void {
  $q = ['p' => $projectToken, 'invest' => '1'];
  if ($accessToken !== '') {
    $q['t'] = $accessToken;
  }
  header('Location: index.php?' . http_build_query($q));
  exit;
};

// POST: step navigation and final sign
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action'])) {
  if (!Auth::verifyCsrfToken((string)($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Session expired. Please refresh and try again.';
    exit;
  }
  $act = (string)$_POST['action'];

  if ($act === 'inv_step_back') {
    $to = (int)($_POST['step_to'] ?? 2);
    if ($to !== 1 && $to !== 2) {
      $to = 2;
    }
    $_SESSION[$invStepKey] = $to;
    $invRedirect($projectToken, $accessToken);
  }

  if ($act === 'inv_step1') {
    $nm = trim((string)($_POST['signer_name'] ?? ''));
    $pos = trim((string)($_POST['signer_position'] ?? ''));
    $addr = trim((string)($_POST['signer_address'] ?? ''));
    $amtRaw = trim((string)($_POST['commitment_amount'] ?? ''));
    $amt = (float)str_replace([',', ' '], '', $amtRaw);
    $errs = [];
    if ($nm === '' || $pos === '' || $addr === '') {
      $errs[] = 'Please complete name, position, and address.';
    }
    if (!is_finite($amt) || $amt <= 0) {
      $errs[] = 'Enter a valid commitment amount greater than zero.';
    }
    if ($minCommit !== null && $minCommit > 0 && $amt < (float)$minCommit) {
      $errs[] = 'Amount must be at least ' . $currency . ' ' . number_format((float)$minCommit, 2) . '.';
    }
    if ($errs) {
      renderHeader('Commitment');
      echo '<div class="card"><div class="err"><strong>' . Util::h($errs[0]) . '</strong></div>';
      $retryQ = ['p' => $projectToken, 'invest' => '1'];
      if ($accessToken !== '') {
        $retryQ['t'] = $accessToken;
      }
      echo '<p class="gds-lead"><a href="' . Util::h('index.php?' . http_build_query($retryQ)) . '">Try again</a></p></div>';
      renderFooter();
      exit;
    }
    $_SESSION[$invDraftKey] = [
      'name' => $nm,
      'position' => $pos,
      'address' => $addr,
      'commitment_amount' => $amt,
      'currency' => $currency,
      'free_text' => $_SESSION[$invDraftKey]['free_text'] ?? [],
    ];
    $_SESSION[$invStepKey] = 2;
    $invRedirect($projectToken, $accessToken);
  }

  if ($act === 'inv_step2') {
    $draft = $_SESSION[$invDraftKey] ?? null;
    if (!is_array($draft) || trim((string)($draft['name'] ?? '')) === '') {
      $_SESSION[$invStepKey] = 1;
      $invRedirect($projectToken, $accessToken);
    }
    $ft = [];
    if (isset($_POST['free_text']) && is_array($_POST['free_text'])) {
      foreach ($_POST['free_text'] as $k => $v) {
        $ft[(string)(int)$k] = trim((string)$v);
      }
    }
    $draft['free_text'] = $ft;
    $_SESSION[$invDraftKey] = $draft;
    $_SESSION[$invStepKey] = 3;
    $invRedirect($projectToken, $accessToken);
  }

  if ($act === 'inv_sign_contract') {
    $draft = $_SESSION[$invDraftKey] ?? null;
    $stepNow = (int)($_SESSION[$invStepKey] ?? 1);
    $sig = (string)($_POST['signature_png'] ?? '');
    $signedPdfB64 = (string)($_POST['signed_pdf_b64'] ?? '');
    $accept = (string)($_POST['accept'] ?? '');

    $errors = [];
    if (!is_array($draft) || $stepNow !== 3) {
      $errors[] = 'Please complete all steps before signing.';
    }
    $name = is_array($draft) ? trim((string)($draft['name'] ?? '')) : '';
    $pos = is_array($draft) ? trim((string)($draft['position'] ?? '')) : '';
    $addr = is_array($draft) ? trim((string)($draft['address'] ?? '')) : '';
    $camt = is_array($draft) ? (float)($draft['commitment_amount'] ?? 0) : 0.0;
    if ($name === '') {
      $errors[] = 'Name is required.';
    }
    if ($pos === '') {
      $errors[] = 'Position/Title is required.';
    }
    if ($addr === '') {
      $errors[] = 'Address is required.';
    }
    if ($camt <= 0 || !is_finite($camt)) {
      $errors[] = 'Invalid commitment amount.';
    }
    if ($accept !== 'yes') {
      $errors[] = 'You must accept the contract.';
    }
    if ($sig === '') {
      $errors[] = 'Signature is required.';
    }
    if ($signedPdfB64 === '') {
      $errors[] = 'Signed PDF could not be generated (please try again).';
    }

    if ($errors) {
      renderHeader('Sign contract');
      echo '<div class="card"><div class="err"><strong>Unable to submit:</strong><ul class="list">';
      foreach ($errors as $er) {
        echo '<li>' . Util::h($er) . '</li>';
      }
      echo '</ul></div></div>';
      renderFooter();
      exit;
    }

    $dirs = $projects->ensureProjectDirs($projectId);
    $sigFile = $dirs['signatures'] . '/inv_sig_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.png';
    $ndaSigning->saveSignaturePng($sig, $sigFile);

    $signedPdfPath = null;
    $pdfBin = base64_decode($signedPdfB64, true);
    if ($pdfBin !== false && str_starts_with($pdfBin, '%PDF')) {
      $signedPdfPath = $dirs['signed'] . '/signed_contract_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.pdf';
      file_put_contents($signedPdfPath, $pdfBin);
    }

    $receiptPath = $dirs['signed'] . '/inv_receipt_' . preg_replace('/[^a-z0-9]+/i', '_', $email) . '_' . time() . '.html';
    $receiptHtml = '<h1>Signed investment contract</h1>'
      . '<p><strong>Project:</strong> ' . Util::h((string)$project['name']) . '</p>'
      . '<p><strong>Email:</strong> ' . Util::h($email) . '</p>'
      . '<p><strong>Name:</strong> ' . Util::h($name) . '</p>'
      . '<p><strong>Position:</strong> ' . Util::h($pos) . '</p>'
      . '<p><strong>Address:</strong> ' . Util::h($addr) . '</p>'
      . '<p><strong>Commitment:</strong> ' . Util::h($currency) . ' ' . Util::h(number_format($camt, 2)) . '</p>'
      . '<p><strong>Signed at (UTC):</strong> ' . Util::h(gmdate('c')) . '</p>';
    file_put_contents($receiptPath, $receiptHtml);

    $investment->recordCommitment([
      'project_id' => $projectId,
      'signer_email' => $email,
      'signer_name' => $name,
      'signer_position' => $pos,
      'signer_address' => $addr,
      'committed_amount' => $camt,
      'currency' => $currency,
      'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
      'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'signature_image_path' => $sigFile,
      'signed_receipt_path' => $receiptPath,
      'signed_pdf_path' => $signedPdfPath,
    ]);

    unset($_SESSION[$invDraftKey], $_SESSION[$invStepKey]);

    $q = ['p' => $projectToken];
    if ($accessToken !== '') {
      $q['t'] = $accessToken;
    }
    header('Location: index.php?' . http_build_query($q));
    exit;
  }
}

// GET: establish step
if (!isset($_SESSION[$invStepKey]) || (int)$_SESSION[$invStepKey] < 1) {
  $_SESSION[$invStepKey] = 1;
}

$draft = $_SESSION[$invDraftKey] ?? null;
$step = (int)($_SESSION[$invStepKey] ?? 1);
if (!is_array($draft)) {
  $step = 1;
  $_SESSION[$invStepKey] = 1;
  $prev = $sigRec;
  $_SESSION[$invDraftKey] = [
    'name' => $prev ? (string)$prev['signer_name'] : '',
    'position' => $prev ? (string)$prev['signer_position'] : '',
    'address' => $prev ? (string)$prev['signer_address'] : '',
    'commitment_amount' => 0.0,
    'currency' => $currency,
    'free_text' => [],
  ];
  $existingC = $investment->getCommitment($projectId, $email);
  if ($existingC && isset($existingC['committed_amount'])) {
    $_SESSION[$invDraftKey]['commitment_amount'] = (float)$existingC['committed_amount'];
  }
  $draft = $_SESSION[$invDraftKey];
}

if ($step < 1) {
  $step = 1;
}
if ($step > 3) {
  $step = 3;
}
if ($step >= 2 && (!is_array($draft) || trim((string)($draft['name'] ?? '')) === '')) {
  $step = 1;
  $_SESSION[$invStepKey] = 1;
}
$_SESSION[$invStepKey] = $step;

$freeTextDefs = array_values(array_filter($invFieldDefs, static fn($d) => (string)($d['field_key'] ?? '') === 'free_text'));
$defsJson = json_encode($invFieldDefs, JSON_UNESCAPED_SLASHES) ?: '[]';

$cancelHref = 'index.php?p=' . urlencode($projectToken) . ($accessToken !== '' ? '&t=' . urlencode($accessToken) : '');

// ── Step 1 ───────────────────────────────────────────────────────────────
if ($step === 1) {
  renderHeader('Funding commitment');
  renderAnalyticsTracker($projectToken, 'invest_step1', $email);
  $dn = Util::h((string)($draft['name'] ?? ''));
  $dp = Util::h((string)($draft['position'] ?? ''));
  $da = Util::h((string)($draft['address'] ?? ''));
  $dc = Util::h((string)($draft['commitment_amount'] ?? '0'));
  $minHint = ($minCommit !== null && $minCommit > 0)
    ? '<p class="muted" style="font-size:.875em">Minimum: ' . Util::h($currency) . ' ' . Util::h(number_format((float)$minCommit, 2)) . '</p>'
    : '';
  echo '<div class="card">';
  echo '<h2 class="gds-page-title">' . Util::h((string)$project['name']) . '</h2>';
  echo '<p class="gds-lead">Confirm your details and enter the amount you are committing to this opportunity.</p>';
  echo '<p class="gds-lead"><a href="' . Util::h($cancelHref) . '" class="muted">← Back to files</a></p>';
  echo '<div class="stepper"><span class="step active"><span class="num">1</span>Details</span><span class="step"><span class="num">2</span>Review</span><span class="step"><span class="num">3</span>Sign</span></div>';
  echo '<form method="post">';
  echo Auth::csrfFieldHtml();
  echo '<input type="hidden" name="action" value="inv_step1" />';
  echo '<div class="row">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="inv_name">Full name</label><input id="inv_name" name="signer_name" required value="' . $dn . '" autocomplete="name" /></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="inv_pos">Position / title</label><input id="inv_pos" name="signer_position" required value="' . $dp . '" autocomplete="organization-title" /></div>';
  echo '</div>';
  echo '<div class="gds-field"><label class="gds-label" for="inv_addr">Address</label><textarea id="inv_addr" name="signer_address" rows="3" required autocomplete="street-address">' . $da . '</textarea></div>';
  echo '<div class="gds-field"><label class="gds-label" for="inv_amt">Commitment amount (' . Util::h($currency) . ')</label>';
  echo '<input id="inv_amt" name="commitment_amount" type="text" inputmode="decimal" required value="' . $dc . '" placeholder="50000" /></div>';
  echo $minHint;
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Continue</button></div>';
  echo '</form></div>';
  renderFooter();
  exit;
}

// ── Step 2 ───────────────────────────────────────────────────────────────
if ($step === 2) {
  renderHeader('Review contract');
  renderAnalyticsTracker($projectToken, 'invest_step2', $email);
  $contractViewerHref = 'viewer.php?p=' . urlencode($projectToken) . '&contract=1' . ($accessToken !== '' ? '&t=' . urlencode($accessToken) : '');
  $draftForJs = [
    'name' => (string)($draft['name'] ?? ''),
    'position' => (string)($draft['position'] ?? ''),
    'address' => (string)($draft['address'] ?? ''),
    'commitment_amount' => (string)($draft['commitment_amount'] ?? ''),
    'currency' => (string)($draft['currency'] ?? $currency),
    'free_text' => (isset($draft['free_text']) && is_array($draft['free_text'])) ? $draft['free_text'] : [],
  ];
  $draftJson = json_encode($draftForJs, JSON_UNESCAPED_SLASHES) ?: '{}';

  echo '<div class="card">';
  echo '<h2 class="gds-page-title">' . Util::h((string)$project['name']) . '</h2>';
  echo '<p class="gds-lead"><a href="' . Util::h($cancelHref) . '" class="muted">← Back to files</a></p>';
  echo '<div class="stepper"><span class="step"><span class="num">1</span>Details</span><span class="step active"><span class="num">2</span>Review</span><span class="step"><span class="num">3</span>Sign</span></div>';
  echo '<div class="gds-sign-toolbar">';
  echo '<form method="post" style="margin:0">' . Auth::csrfFieldHtml() . '<input type="hidden" name="action" value="inv_step_back" /><input type="hidden" name="step_to" value="1" /><button type="submit" class="btn btn-secondary">Back</button></form>';
  echo '<a href="' . Util::h($contractViewerHref) . '" class="muted" target="_blank" rel="noopener">Open full-screen contract</a>';
  echo '</div>';

  echo '<form method="post">';
  echo Auth::csrfFieldHtml();
  echo '<input type="hidden" name="action" value="inv_step2" />';

  if ($freeTextDefs) {
    echo '<div style="margin:0 0 var(--gds-space-5)">';
    echo '<div class="gds-section-title">Optional fields</div><div class="row">';
    foreach ($freeTextDefs as $d) {
      $fid = (int)($d['id'] ?? 0);
      $label = trim((string)($d['field_label'] ?? ''));
      if ($label === '') {
        $label = 'Text ' . ($fid > 0 ? (string)$fid : '');
      }
      $val = '';
      if (isset($draft['free_text'][$fid])) {
        $val = (string)$draft['free_text'][$fid];
      } elseif (isset($draft['free_text'][(string)$fid])) {
        $val = (string)$draft['free_text'][(string)$fid];
      }
      echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="inv_ft_' . $fid . '">' . Util::h($label) . '</label><input id="inv_ft_' . $fid . '" name="free_text[' . $fid . ']" value="' . Util::h($val) . '" /></div>';
    }
    echo '</div></div>';
  }

  echo '<div class="gds-section-title">Preview — your details on the contract</div>';
  echo '<div class="gds-nda-preview">';
  echo '<div class="gds-nda-preview-toolbar">';
  echo '<div class="muted">Page <span id="invContractPageLabel">1</span> / <span id="invContractPageCount">?</span></div>';
  echo '<div class="gds-nda-nav">';
  echo '<button type="button" id="invContractPrevBtn" class="btn btn-secondary">Prev</button>';
  echo '<button type="button" id="invContractNextBtn" class="btn btn-secondary">Next</button>';
  echo '</div></div>';
  echo '<div class="gds-nda-canvas-wrap" style="position:relative">';
  echo '<canvas id="invContractCanvas" style="display:block;width:100%;height:auto"></canvas>';
  echo '<div id="invContractOverlay" style="position:absolute;left:0;top:0"></div>';
  echo '</div></div>';
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Continue to signature</button></div>';
  echo '</form>';

  echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
  echo '<script>';
  echo 'const invDefs = ' . $defsJson . ';';
  echo 'const invDraft = ' . $draftJson . ';';
  echo 'const invContractPdfUrl = "download.php?p=" + encodeURIComponent(' . json_encode($projectToken, JSON_UNESCAPED_SLASHES) . ') + "&contract=1"';
  if ($accessToken !== '') {
    echo ' + "&t=" + encodeURIComponent(' . json_encode($accessToken, JSON_UNESCAPED_SLASHES) . ')';
  }
  echo ';';
  echo <<<'INVJS'
pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
const invCC = document.getElementById("invContractCanvas");
const invCX = invCC.getContext("2d");
const invCO = document.getElementById("invContractOverlay");
const invPL = document.getElementById("invContractPageLabel");
const invPC = document.getElementById("invContractPageCount");
let invPdf = null;
let invPage = 1;

function invLabelFor(key) {
  return ({ signature:"Signature", signed_date:"Date", signer_name:"Name", signer_position:"Position", signer_address:"Address",
    commitment_amount:"Commitment", free_text:"Free text" })[key] || key;
}
function invFmtDate() {
  const d = new Date();
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return yyyy + "-" + mm + "-" + dd;
}
function invTextForField(def) {
  const k = String(def.field_key || "");
  if (k === "signed_date") return invFmtDate();
  if (k === "signer_name") return invDraft.name || "";
  if (k === "signer_position") return invDraft.position || "";
  if (k === "signer_address") return invDraft.address || "";
  if (k === "commitment_amount") {
    const cur = invDraft.currency || "";
    const a = invDraft.commitment_amount != null ? String(invDraft.commitment_amount) : "";
    return (cur && a) ? (cur + " " + a) : a;
  }
  if (k === "free_text") {
    const id = String(def.id || "");
    const el = document.querySelector('[name="free_text[' + id.replace(/"/g, "") + ']"]');
    return el ? (el.value || "") : ((invDraft.free_text && invDraft.free_text[id]) ? String(invDraft.free_text[id]) : "");
  }
  if (k === "signature") return "Signature";
  return "";
}
function invSyncOverlaySize() {
  const r = invCC.getBoundingClientRect();
  invCO.style.width = r.width + "px";
  invCO.style.height = r.height + "px";
  invCO.style.position = "absolute";
  invCO.style.left = "0px";
  invCO.style.top = "0px";
}
function invRenderOverlay() {
  invCO.innerHTML = "";
  invSyncOverlaySize();
  const r = invCO.getBoundingClientRect();
  (invDefs || []).forEach((d) => {
    if (!d || String(d.field_key || "") === "") return;
    if (Number(d.page_num || 1) !== invPage) return;
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
    const txt = invTextForField(d);
    el.textContent = txt || (String(d.field_label || "") || invLabelFor(k));
    invCO.appendChild(el);
  });
}
async function invRenderContractPage() {
  if (!invPdf) return;
  const page = await invPdf.getPage(invPage);
  const viewport = page.getViewport({ scale: 1.35 });
  invCC.width = viewport.width;
  invCC.height = viewport.height;
  await page.render({ canvasContext: invCX, viewport }).promise;
  invPL.textContent = String(invPage);
  invRenderOverlay();
}
document.getElementById("invContractPrevBtn").addEventListener("click", async () => {
  if (invPage <= 1) return;
  invPage -= 1;
  await invRenderContractPage();
});
document.getElementById("invContractNextBtn").addEventListener("click", async () => {
  if (!invPdf || invPage >= invPdf.numPages) return;
  invPage += 1;
  await invRenderContractPage();
});
document.addEventListener("input", (e) => {
  const t = e.target;
  if (t && t.matches && t.matches('input[name^="free_text["]')) invRenderOverlay();
});
window.addEventListener("resize", () => { invRenderOverlay(); });
(async () => {
  try {
    const resp = await fetch(invContractPdfUrl);
    if (!resp.ok) throw new Error("PDF fetch failed: " + resp.status);
    const bytes = await resp.arrayBuffer();
    invPdf = await pdfjsLib.getDocument({ data: new Uint8Array(bytes), disableWorker: true }).promise;
    invPC.textContent = String(invPdf.numPages);
    await invRenderContractPage();
  } catch (e) {
    console.error(e);
    invPC.textContent = "?";
  }
})();
INVJS;
  echo '</script>';
  echo '</div>';
  renderFooter();
  exit;
}

// ── Step 3 ───────────────────────────────────────────────────────────────
renderHeader('Sign contract');
renderAnalyticsTracker($projectToken, 'invest_step3', $email);
$contractViewerHref = 'viewer.php?p=' . urlencode($projectToken) . '&contract=1' . ($accessToken !== '' ? '&t=' . urlencode($accessToken) : '');

$dName = Util::h((string)($draft['name'] ?? ''));
$dPos = Util::h((string)($draft['position'] ?? ''));
$dAddr = Util::h((string)($draft['address'] ?? ''));
$dAmt = Util::h((string)($draft['commitment_amount'] ?? ''));
$dCur = Util::h((string)($draft['currency'] ?? $currency));

echo '<div class="card">';
echo '<h2 class="gds-page-title">' . Util::h((string)$project['name']) . '</h2>';
echo '<p class="gds-lead"><a href="' . Util::h($cancelHref) . '" class="muted">← Back to files</a></p>';
echo '<div class="stepper"><span class="step"><span class="num">1</span>Details</span><span class="step"><span class="num">2</span>Review</span><span class="step active"><span class="num">3</span>Sign</span></div>';
echo '<div class="gds-sign-toolbar">';
echo '<form method="post" style="margin:0">' . Auth::csrfFieldHtml() . '<input type="hidden" name="action" value="inv_step_back" /><input type="hidden" name="step_to" value="2" /><button type="submit" class="btn btn-secondary">Back</button></form>';
echo '<a href="' . Util::h($contractViewerHref) . '" class="muted" target="_blank" rel="noopener">Open full-screen contract</a>';
echo '</div>';

echo '<form method="post" id="gdsInvSignForm">';
echo Auth::csrfFieldHtml();
echo '<input type="hidden" name="action" value="inv_sign_contract" />';
echo '<input type="hidden" name="signer_name" value="' . $dName . '" />';
echo '<input type="hidden" name="signer_position" value="' . $dPos . '" />';
echo '<input type="hidden" name="signer_address" value="' . $dAddr . '" />';
echo '<input type="hidden" name="commitment_amount" value="' . $dAmt . '" />';
echo '<input type="hidden" name="currency" value="' . $dCur . '" />';
echo '<input type="hidden" name="signed_pdf_b64" id="inv_signed_pdf_b64" />';

if ($freeTextDefs) {
  foreach ($freeTextDefs as $d) {
    $fid = (int)($d['id'] ?? 0);
    $val = '';
    if (isset($draft['free_text'][$fid])) {
      $val = (string)$draft['free_text'][$fid];
    } elseif (isset($draft['free_text'][(string)$fid])) {
      $val = (string)$draft['free_text'][(string)$fid];
    }
    echo '<input type="hidden" name="free_text[' . $fid . ']" value="' . Util::h($val) . '" />';
  }
}

echo '<div class="gds-field gds-sig-field" style="margin-top:var(--gds-space-2)">';
echo '<span class="gds-label">Signature</span>';
echo '<p class="gds-help">Sign with your finger or mouse in the box below.</p>';
echo '<div class="gds-sig-box">';
echo '<canvas id="invSig" width="820" height="220" aria-label="Signature drawing area" style="width:100%;height:220px;touch-action:none"></canvas>';
echo '<div class="gds-sig-actions"><button type="button" class="btn btn-secondary" onclick="window.__gdsInvClearSig()">Clear</button></div>';
echo '</div>';
echo '<input type="hidden" name="signature_png" id="inv_signature_png" />';
echo '</div>';
echo '<div style="margin-top:var(--gds-space-5)"><div class="toggleRow">';
echo '<div class="label"><strong>I agree</strong><div class="muted">I have read and agree to this investment contract.</div></div>';
echo '<label class="toggle" aria-label="I agree"><input type="checkbox" name="accept" value="yes" /><span class="switch" aria-hidden="true"></span></label>';
echo '</div></div>';
echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Sign and save</button></div>';
echo '</form>';

echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>';
echo '<script>';
echo 'const invSignDefs = ' . $defsJson . ';';
echo 'const invProjectToken = ' . json_encode($projectToken, JSON_UNESCAPED_SLASHES) . ';';
echo 'const invAccessToken = ' . json_encode($accessToken, JSON_UNESCAPED_SLASHES) . ';';
echo <<<'INV3'
(() => {
  const defs = invSignDefs;
  pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
  const pdfLib = window.PDFLib;
  const canvas = document.getElementById("invSig");
  const ctx = canvas.getContext("2d");
  ctx.lineWidth = 2.5;
  ctx.lineCap = "round";
  ctx.strokeStyle = "#111827";
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
  function down(ev) { drawing = true; last = pos(ev); ev.preventDefault(); }
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
  function up(ev) { drawing = false; last = null; ev.preventDefault(); }
  canvas.addEventListener("mousedown", down);
  canvas.addEventListener("mousemove", move);
  window.addEventListener("mouseup", up);
  canvas.addEventListener("touchstart", down, { passive: false });
  canvas.addEventListener("touchmove", move, { passive: false });
  window.addEventListener("touchend", up, { passive: false });
  window.__gdsInvClearSig = () => { ctx.clearRect(0, 0, canvas.width, canvas.height); };

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
  async function generateInvSignedPdf(signatureDataUrl) {
    const name = (document.querySelector('input[name="signer_name"]') || {}).value || "";
    const position = (document.querySelector('input[name="signer_position"]') || {}).value || "";
    const address = (document.querySelector('input[name="signer_address"]') || {}).value || "";
    const amt = (document.querySelector('input[name="commitment_amount"]') || {}).value || "";
    const cur = (document.querySelector('input[name="currency"]') || {}).value || "";
    const dateStr = formatSignedDate();
    let url = "download.php?p=" + encodeURIComponent(invProjectToken) + "&contract=1";
    if (invAccessToken) url += "&t=" + encodeURIComponent(invAccessToken);
    const pdfBytes = await fetch(url).then(r => r.arrayBuffer());
    const doc = await pdfLib.PDFDocument.load(pdfBytes);
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
        if (key === "signer_address") text = address.replace(/\s+/g, " ").trim();
        if (key === "commitment_amount") text = (cur && amt) ? (cur + " " + amt) : amt;
        if (key === "free_text") {
          const id = String(def.id || "");
          const el = document.querySelector('[name="free_text[' + id + ']"]');
          text = el ? (el.value || "") : "";
        }
        if (!text) continue;
        const fontSize = Math.max(9, Math.min(14, box.h * 0.65));
        page.drawText(text, {
          x: box.x + 3,
          y: box.y + Math.max(2, (box.h - fontSize) / 2),
          size: fontSize,
          font,
          color: pdfLib.rgb(0.07, 0.09, 0.12),
          maxWidth: Math.max(0, box.w - 6),
        });
      }
    }
    return await doc.save();
  }

  const form = document.getElementById("gdsInvSignForm");
  if (form) {
    form.addEventListener("submit", async (e) => {
      if (form.dataset.gdsAllowSubmit === "1") return;
      e.preventDefault();
      const accept = document.querySelector('input[name="accept"]');
      if (!accept || !accept.checked) {
        alert("Please confirm that you agree to the contract.");
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
      document.getElementById("inv_signature_png").value = png;
      const btn = form.querySelector('button[type="submit"]');
      const old = btn ? btn.textContent : "";
      try {
        if (btn) { btn.disabled = true; btn.textContent = "Generating PDF…"; }
        const bytes = await generateInvSignedPdf(png);
        let binary = "";
        const chunk = 0x8000;
        const u8 = new Uint8Array(bytes);
        for (let i = 0; i < u8.length; i += chunk) {
          binary += String.fromCharCode.apply(null, u8.subarray(i, i + chunk));
        }
        document.getElementById("inv_signed_pdf_b64").value = btoa(binary);
        form.dataset.gdsAllowSubmit = "1";
        if (btn) { btn.disabled = false; btn.textContent = old; }
        form.submit();
      } catch (err) {
        console.error(err);
        alert("Could not generate the signed PDF. Please try again.");
        if (btn) { btn.disabled = false; btn.textContent = old || "Sign and save"; }
      }
    });
  }
})();
INV3;
echo '</script>';
echo '</div>';
renderFooter();
exit;
