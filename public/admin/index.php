<?php

require_once __DIR__ . '/_bootstrap.php';

$view = isset($_GET['view']) ? (string)$_GET['view'] : 'dashboard';

if ($view === 'logout') {
  Auth::logoutAdmin();
  header('Location: index.php?view=login');
  exit;
}

// Login screen
if ($view === 'login') {
  if (Auth::adminId() !== null) {
    header('Location: index.php');
    exit;
  }

  $error = '';
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');

    $row = $db->fetchOne('SELECT * FROM admins WHERE email = :e LIMIT 1', [':e' => $email]);
    if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
      $error = 'Invalid email or password.';
    } else {
      Auth::setAdminId((int)$row['id']);
      header('Location: index.php');
      exit;
    }
  }

  adminHeader('Sign in');
  echo '<div class="card">';
  echo '<h2 class="gds-page-title">Sign in</h2>';
  echo '<p class="gds-lead">Use your administrator email and password.</p>';
  if ($error !== '') echo '<div class="err gds-flash"><strong>' . Util::h($error) . '</strong></div>';
  echo '<form method="post">';
  echo '<div class="gds-field"><label class="gds-label" for="admin_login_email">Email</label>';
  echo '<input id="admin_login_email" name="email" type="email" required autocomplete="username" /></div>';
  echo '<div class="gds-field"><label class="gds-label" for="admin_login_password">Password</label>';
  echo '<input id="admin_login_password" name="password" type="password" required autocomplete="current-password" /></div>';
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Sign in</button></div>';
  echo '</form>';
  echo '</div>';
  adminFooter();
  exit;
}

Auth::requireAdmin();

function requireIntParam(string $key): int {
  return (int)($_REQUEST[$key] ?? 0);
}

function requireJsonBody(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// Create project
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_project') {
  $name = trim((string)($_POST['name'] ?? ''));
  if ($name !== '') {
    $projects->createProject($name);
  }
  header('Location: index.php?toast=1');
  exit;
}

// Add administrator
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $pass = (string)($_POST['password'] ?? '');
  $err = '';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'valid_email';
  } elseif (strlen($pass) < 10) {
    $err = 'short_pass';
  } else {
    $exists = $db->fetchOne('SELECT id FROM admins WHERE email = :e LIMIT 1', [':e' => $email]);
    if ($exists) {
      $err = 'exists';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $db->exec(
        'INSERT INTO admins (email, password_hash, created_at) VALUES (:e, :h, UTC_TIMESTAMP())',
        [':e' => $email, ':h' => $hash],
      );
      header('Location: index.php?view=admins&added=1&toast=1');
      exit;
    }
  }
  header('Location: index.php?view=admins&err=' . urlencode($err));
  exit;
}

// Remove administrator
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_admin') {
  $delId = (int)($_POST['admin_id'] ?? 0);
  if ($delId <= 0) {
    header('Location: index.php?view=admins');
    exit;
  }
  if ($delId === (int)Auth::adminId()) {
    header('Location: index.php?view=admins&err=self');
    exit;
  }
  $cntRow = $db->fetchOne('SELECT COUNT(*) AS c FROM admins');
  $cnt = (int)($cntRow['c'] ?? 0);
  if ($cnt <= 1) {
    header('Location: index.php?view=admins&err=last');
    exit;
  }
  $db->exec('DELETE FROM admins WHERE id = :id', [':id' => $delId]);
  header('Location: index.php?view=admins&deleted=1');
  exit;
}

// Save white-label text
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_branding') {
  Branding::saveText($db, [
    'app_name' => (string)($_POST['app_name'] ?? ''),
    'visitor_tagline' => (string)($_POST['visitor_tagline'] ?? ''),
    'admin_tagline' => (string)($_POST['admin_tagline'] ?? ''),
  ]);
  header('Location: index.php?view=branding&saved=1&toast=1');
  exit;
}

// Upload white-label logo
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_brand_logo') {
  $err = '';
  if (!isset($_FILES['logo']) || !is_uploaded_file($_FILES['logo']['tmp_name'])) {
    $err = 'no_file';
  } else {
    try {
      $path = Branding::saveUploadedLogo($config, $_FILES['logo']['tmp_name'], (string)($_FILES['logo']['name'] ?? 'logo.png'));
      Branding::setLogoPath($db, $path);
      header('Location: index.php?view=branding&logo=1&toast=1');
      exit;
    } catch (\Throwable $e) {
      $err = 'msg:' . $e->getMessage();
    }
  }
  header('Location: index.php?view=branding&err=' . rawurlencode($err !== '' ? $err : 'upload'));
  exit;
}

// Remove white-label logo
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_brand_logo') {
  $b = Branding::get($db, $config);
  Branding::removeLogoFile($config, $b['logo_path']);
  Branding::setLogoPath($db, null);
  header('Location: index.php?view=branding&removed=1');
  exit;
}

// Save NDA field placement (JSON)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['api']) && $_GET['api'] === 'save_nda_fields') {
  $data = requireJsonBody();
  $pid = (int)($data['project_id'] ?? 0);
  $defs = $data['defs'] ?? null;

  header('Content-Type: application/json');
  if ($pid <= 0 || !is_array($defs)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload'], JSON_UNESCAPED_SLASHES);
    exit;
  }

  $clean = [];
  foreach ($defs as $d) {
    if (!is_array($d)) continue;
    $fk = (string)($d['field_key'] ?? '');
    if (!in_array($fk, ['signature', 'signed_date', 'signer_name', 'signer_position', 'signer_address', 'free_text'], true)) continue;
    $clean[] = [
      'field_key' => $fk,
      'field_label' => isset($d['field_label']) ? (string)$d['field_label'] : null,
      'page_num' => (int)($d['page_num'] ?? 1),
      'x' => (float)($d['x'] ?? 0),
      'y' => (float)($d['y'] ?? 0),
      'w' => (float)($d['w'] ?? 0.2),
      'h' => (float)($d['h'] ?? 0.05),
      'required' => (int)($d['required'] ?? 1),
    ];
  }

  $projects->replaceNdaFieldDefs($pid, $clean);

  echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
  exit;
}

// Upload NDA
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_nda') {
  $pid = (int)($_POST['project_id'] ?? 0);
  if ($pid > 0 && isset($_FILES['nda_pdf']) && is_uploaded_file($_FILES['nda_pdf']['tmp_name'])) {
    $dirs = $projects->ensureProjectDirs($pid);
    $orig = (string)$_FILES['nda_pdf']['name'];
    $dest = $dirs['nda'] . '/nda_' . time() . '.pdf';
    move_uploaded_file($_FILES['nda_pdf']['tmp_name'], $dest);
    $projects->attachNda($pid, $orig, $dest);
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=documents&toast=1');
  exit;
}

// Upload file
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_file') {
  $pid = (int)($_POST['project_id'] ?? 0);
  if ($pid > 0 && isset($_FILES['project_file']) && is_uploaded_file($_FILES['project_file']['tmp_name'])) {
    $dirs = $projects->ensureProjectDirs($pid);
    $orig = (string)$_FILES['project_file']['name'];
    $size = (int)$_FILES['project_file']['size'];
    $safeName = preg_replace('/[^a-z0-9._-]+/i', '_', $orig);
    $dest = $dirs['files'] . '/' . time() . '_' . $safeName;
    move_uploaded_file($_FILES['project_file']['tmp_name'], $dest);
    $projects->addFile($pid, $orig, $dest, $size);
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=documents&toast=1');
  exit;
}

// Delete project files (Documents tab)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project_files') {
  $pid = (int)($_POST['project_id'] ?? 0);
  $ids = $_POST['file_ids'] ?? [];
  if ($pid > 0 && is_array($ids)) {
    foreach ($ids as $id) {
      $projects->deleteProjectFile($pid, (int)$id);
    }
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=documents');
  exit;
}

// Reorder project files (Documents tab)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_project_files') {
  $pid = (int)($_POST['project_id'] ?? 0);
  $order = $_POST['file_order'] ?? [];
  if ($pid > 0 && is_array($order)) {
    try {
      $projects->reorderProjectFiles($pid, array_map('intval', $order));
    } catch (InvalidArgumentException $e) {
      header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=documents&reorder_err=1');
      exit;
    }
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=documents&toast=1');
  exit;
}

// Update project settings
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_project_settings') {
  $pid = (int)($_POST['project_id'] ?? 0);
  $allow = isset($_POST['allow_downloads']) && (string)$_POST['allow_downloads'] === '1' ? 1 : 0;
  $wmEnabled = isset($_POST['watermark_enabled']) && (string)$_POST['watermark_enabled'] === '1' ? 1 : 0;
  $welcomeEnabled = isset($_POST['welcome_enabled']) && (string)$_POST['welcome_enabled'] === '1' ? 1 : 0;
  $welcomeMessage = trim((string)($_POST['welcome_message'] ?? ''));
  if ($welcomeMessage === '') $welcomeMessage = null;
  if ($pid > 0) {
    $db->exec(
      'UPDATE projects
         SET allow_downloads = :a,
             watermark_enabled = :w,
             welcome_enabled = :we,
             welcome_message = :wm
       WHERE id = :id',
      [':a' => $allow, ':w' => $wmEnabled, ':we' => $welcomeEnabled, ':wm' => $welcomeMessage, ':id' => $pid],
    );
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=settings&toast=1');
  exit;
}

// Upload watermark image
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_watermark') {
  $pid = (int)($_POST['project_id'] ?? 0);
  if ($pid > 0 && isset($_FILES['watermark_image']) && is_uploaded_file($_FILES['watermark_image']['tmp_name'])) {
    $dirs = $projects->ensureProjectDirs($pid);
    $orig = (string)$_FILES['watermark_image']['name'];
    $safe = preg_replace('/[^a-z0-9._-]+/i', '_', $orig);
    $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
      header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=settings&wm_err=type');
      exit;
    }
    $dest = $dirs['watermark'] . '/wm_' . time() . '_' . $safe;
    move_uploaded_file($_FILES['watermark_image']['tmp_name'], $dest);
    $db->exec(
      'UPDATE projects SET watermark_image_name = :n, watermark_image_path = :p WHERE id = :id',
      [':n' => $orig, ':p' => $dest, ':id' => $pid],
    );
  }
  header('Location: index.php?view=project&project_id=' . urlencode((string)$pid) . '&tab=settings&toast=1');
  exit;
}

// Views
if ($view === 'nda-fields') {
  $pid = (int)($_GET['project_id'] ?? 0);
  $proj = $db->fetchOne('SELECT * FROM projects WHERE id = :id LIMIT 1', [':id' => $pid]);
  if (!$proj) {
    adminHeader('Not found');
    echo '<div class="card"><h2 class="gds-page-title">Not found</h2><div class="err gds-flash"><strong>Project not found.</strong></div></div>';
    adminFooter();
    exit;
  }

  $nda = $projects->getNda($pid);
  if (!$nda) {
    adminHeader('NDA fields');
    echo '<div class="card">';
    echo '<h2 class="gds-page-title">NDA fields</h2>';
    echo '<div class="err gds-flash"><strong>No NDA uploaded yet.</strong> Upload an NDA from the project’s Documents tab first.</div>';
    echo '<p class="gds-lead"><a href="index.php?view=project&project_id=' . (int)$pid . '">← Back to project</a></p>';
    echo '</div>';
    adminFooter();
    exit;
  }

  $shareLink = 'index.php?view=project&project_id=' . urlencode((string)$pid);
  // Serve PDF from admin origin to avoid CORS issues with PDF.js.
  $ndaUrl = 'nda.php?project_id=' . urlencode((string)$pid);

  $existing = $projects->listNdaFieldDefs($pid);
  $existingJson = json_encode($existing, JSON_UNESCAPED_SLASHES);
  $ndaUrlEsc = Util::h($ndaUrl);
  $pidEsc = (int)$pid;

  adminHeader('NDA fields');
  echo '<div class="card">';
  echo '<div class="gds-page-header">';
  echo '<div><h2 class="gds-page-title">Place NDA fields</h2><p class="gds-lead" style="margin-bottom:0">Drag and resize boxes on the PDF. Coordinates are saved per project.</p></div>';
  echo '<div class="gds-toolbar">';
  echo '<a class="gds-link-back" href="' . Util::h($shareLink) . '">← Back to project</a>';
  echo '<button type="button" class="btn btn-danger gds-btn--compact" id="removeFieldBtn" title="Remove selected field">Remove field</button>';
  echo '<button type="button" class="btn btn-primary gds-btn--compact" id="saveBtn">Save</button>';
  echo '</div></div>';

  echo '<hr class="gds-divider" />';

  echo '<div class="gds-nda-field-layout">';
  echo '<div class="gds-nda-field-sidebar">';
  echo '<div class="gds-section-title" style="margin-bottom:var(--gds-space-2)">Fields</div>';
  echo '<div id="fieldsList" class="gds-field-palette">';
  $fieldLabels = [
    'signature' => 'Signature',
    'signed_date' => 'Date',
    'signer_name' => 'Name',
    'signer_position' => 'Position',
    'signer_address' => 'Address',
    'free_text' => 'Free text',
  ];
  foreach ($fieldLabels as $k => $label) {
    echo '<button type="button" class="fieldBtn" draggable="true" data-key="' . Util::h($k) . '">' . Util::h($label) . '</button>';
  }
  echo '</div>';
  echo '<p class="gds-help" style="margin-top:var(--gds-space-4)">Tip: click a field, then click an <strong>empty</strong> area on the PDF to place it. Select a box and press <strong>Delete</strong> or <strong>Remove field</strong> to delete it.</p>';
  echo '</div>';

  echo '<div class="gds-nda-field-main">';
  echo '<div class="gds-nda-preview">';
  echo '<div class="gds-nda-preview-toolbar">';
  echo '<div class="gds-nda-preview-toolbar-inner" style="display:flex;align-items:center;gap:var(--gds-space-3);flex-wrap:wrap">';
  echo '<strong style="font-weight:600;font-size:var(--gds-text-sm);color:var(--gds-text)">Preview</strong>';
  echo '<span class="muted">Page</span> <span id="pageLabel" class="muted">1</span> <span class="muted">/</span> <span id="pageCount" class="muted">?</span>';
  echo '</div>';
  echo '<div class="gds-nda-nav">';
  echo '<button type="button" class="btn btn-secondary gds-btn--compact" id="prevPageBtn">Prev</button>';
  echo '<button type="button" class="btn btn-secondary gds-btn--compact" id="nextPageBtn">Next</button>';
  echo '<a href="' . $ndaUrlEsc . '" target="_blank" rel="noopener">Open PDF</a>';
  echo '</div>';
  echo '</div>';
  echo '<div class="gds-nda-canvas-wrap">';
  echo '<div style="position:relative">';
  echo '<canvas id="pdfCanvas" style="display:block;width:100%;height:auto"></canvas>';
  echo '<div id="overlay" style="position:absolute;inset:0"></div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';

  echo '<div id="status" class="muted" style="margin-top:var(--gds-space-3)"></div>';
  echo '</div>';

  // Use a UMD build that exposes window.pdfjsLib reliably.
  echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
  echo '<script>';
  echo 'const PROJECT_ID = ' . $pidEsc . ';';
  echo 'const NDA_URL = ' . json_encode($ndaUrl, JSON_UNESCAPED_SLASHES) . ';';
  echo 'const existing = ' . ($existingJson ?: '[]') . ';';
  echo '
pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

const overlay = document.getElementById("overlay");
const canvas = document.getElementById("pdfCanvas");
const ctx = canvas.getContext("2d");
const statusEl = document.getElementById("status");
const saveBtn = document.getElementById("saveBtn");
const removeFieldBtn = document.getElementById("removeFieldBtn");

let pdfDoc = null;
let pageNum = 1;
let currentFieldKey = "signature";
let boxes = []; // {id,field_key,field_label,x,y,w,h,page_num,required}
let selectedBoxId = null;
let freeTextCount = 0;

function setStatus(msg, kind = "info") {
  const bg = kind === "ok" ? "var(--okSoft)" : (kind === "err" ? "var(--dangerSoft)" : "transparent");
  const bd = kind === "ok" ? "rgba(4,120,87,.22)" : (kind === "err" ? "rgba(185,28,28,.22)" : "transparent");
  const fg = kind === "ok" ? "var(--ok)" : (kind === "err" ? "var(--danger)" : "var(--muted)");
  statusEl.style.padding = kind === "info" ? "0" : "8px 10px";
  statusEl.style.borderRadius = kind === "info" ? "0" : "12px";
  statusEl.style.border = kind === "info" ? "none" : ("1px solid " + bd);
  statusEl.style.background = bg;
  statusEl.style.color = fg;
  statusEl.textContent = msg;
}

function normalizeBox(el) {
  const r = overlay.getBoundingClientRect();
  const b = el.getBoundingClientRect();
  const x = (b.left - r.left) / r.width;
  const y = (b.top - r.top) / r.height;
  const w = b.width / r.width;
  const h = b.height / r.height;
  return { x, y, w, h };
}

function applyBox(fieldKey, def) {
  const r = overlay.getBoundingClientRect();
  const el = document.querySelector(`[data-box-id="${def.id}"]`);
  if (!el) return;
  const visible = Number(def.page_num || 1) === Number(pageNum);
  el.style.display = visible ? "flex" : "none";
  if (!visible) return;
  el.style.left = (def.x * r.width) + "px";
  el.style.top = (def.y * r.height) + "px";
  el.style.width = (def.w * r.width) + "px";
  el.style.height = (def.h * r.height) + "px";
}

function makeBox(def) {
  let el = document.querySelector(`[data-box-id="${def.id}"]`);
  if (el) return el;
  el = document.createElement("div");
  el.dataset.boxId = String(def.id);
  el.dataset.fieldKey = String(def.field_key);
  el.style.position = "absolute";
  el.style.border = "2px solid rgba(37,99,235,.6)";
  el.style.background = "rgba(37,99,235,.10)";
  el.style.borderRadius = "10px";
  el.style.cursor = "move";
  el.style.minWidth = "40px";
  el.style.minHeight = "24px";
  el.style.display = "flex";
  el.style.alignItems = "center";
  el.style.justifyContent = "center";
  el.style.fontSize = "12px";
  el.style.color = "#111827";
  el.style.userSelect = "none";
  el.textContent = def.field_label || labelFor(def.field_key);
  el.style.touchAction = "none";

  const handle = document.createElement("div");
  handle.style.position = "absolute";
  handle.style.right = "6px";
  handle.style.bottom = "6px";
  handle.style.width = "10px";
  handle.style.height = "10px";
  handle.style.borderRadius = "4px";
  handle.style.background = "rgba(17,24,39,.45)";
  handle.style.cursor = "nwse-resize";
  el.appendChild(handle);

  // drag
  let start = null;
  let mode = "move";
  function onDown(ev) {
    ev.preventDefault();
    ev.stopPropagation();
    selectedBoxId = def.id;
    const target = ev.target === handle ? "resize" : "move";
    mode = target;
    const r = overlay.getBoundingClientRect();
    const b = el.getBoundingClientRect();
    start = {
      mx: ev.clientX,
      my: ev.clientY,
      left: b.left - r.left,
      top: b.top - r.top,
      w: b.width,
      h: b.height,
      rw: r.width,
      rh: r.height
    };
    window.addEventListener("pointermove", onMove, { passive: false });
    window.addEventListener("pointerup", onUp, { passive: false });
  }
  function onMove(ev) {
    if (!start) return;
    ev.preventDefault();
    const dx = ev.clientX - start.mx;
    const dy = ev.clientY - start.my;
    if (mode === "move") {
      el.style.left = Math.max(0, Math.min(start.rw - start.w, start.left + dx)) + "px";
      el.style.top = Math.max(0, Math.min(start.rh - start.h, start.top + dy)) + "px";
    } else {
      const nw = Math.max(40, Math.min(start.rw - start.left, start.w + dx));
      const nh = Math.max(24, Math.min(start.rh - start.top, start.h + dy));
      el.style.width = nw + "px";
      el.style.height = nh + "px";
    }
  }
  function onUp() {
    window.removeEventListener("pointermove", onMove);
    window.removeEventListener("pointerup", onUp);
    start = null;
    const i = boxes.findIndex(b => String(b.id) === String(def.id));
    if (i >= 0) {
      boxes[i] = Object.assign({}, boxes[i], normalizeBox(el), { page_num: pageNum });
    }
    setStatus("Updated.");
  }
  el.addEventListener("pointerdown", onDown);
  el.addEventListener("click", (ev) => { ev.stopPropagation(); selectedBoxId = def.id; });
  el.addEventListener("mousedown", (ev) => { ev.stopPropagation(); });

  overlay.appendChild(el);
  return el;
}

async function render() {
  const page = await pdfDoc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.5 });
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await page.render({ canvasContext: ctx, viewport }).promise;

  document.getElementById("pageLabel").textContent = String(pageNum);

  // show/hide boxes based on page
  overlay.querySelectorAll("[data-box-id]").forEach(el => el.remove());
  for (const def of boxes) {
    makeBox(def);
    applyBox(def.field_key, def);
  }
}

async function init() {
  setStatus("Loading PDF…");
  pdfDoc = await pdfjsLib.getDocument(NDA_URL).promise;
  document.getElementById("pageCount").textContent = String(pdfDoc.numPages);
  setStatus("Click a field button, then click on the PDF to place it. Drag to adjust.");
  boxes = (existing || []).map((d) => ({
    id: d.id || ("tmp_" + Math.random().toString(16).slice(2)),
    field_key: d.field_key,
    field_label: d.field_label || "",
    page_num: Number(d.page_num || 1),
    x: Number(d.x || 0),
    y: Number(d.y || 0),
    w: Number(d.w || 0.25),
    h: Number(d.h || 0.06),
    required: Number(d.required ?? 1),
  }));
  freeTextCount = boxes.filter(b => b.field_key === "free_text").length;
  await render();
}

document.querySelectorAll(".fieldBtn").forEach(btn => {
  btn.addEventListener("click", () => {
    currentFieldKey = btn.dataset.key;
    setStatus("Selected: " + currentFieldKey + " (click on the PDF to place)");
  });
  btn.addEventListener("dragstart", (ev) => {
    try {
      ev.dataTransfer.setData("text/plain", String(btn.dataset.key || ""));
      ev.dataTransfer.effectAllowed = "copy";
    } catch (e) {}
  });
});

overlay.addEventListener("dragover", (ev) => { ev.preventDefault(); });
overlay.addEventListener("drop", (ev) => {
  ev.preventDefault();
  const k = (ev.dataTransfer && ev.dataTransfer.getData("text/plain")) ? ev.dataTransfer.getData("text/plain") : currentFieldKey;
  currentFieldKey = k || currentFieldKey;
  placeNewAt(ev.clientX, ev.clientY);
});

function labelFor(k) {
  return ({ signature:"Signature", signed_date:"Date", signer_name:"Name", signer_position:"Position", signer_address:"Address", free_text:"Free text" })[k] || k;
}

function placeNewAt(clientX, clientY) {
  const r = overlay.getBoundingClientRect();
  const x = (clientX - r.left) / r.width;
  const y = (clientY - r.top) / r.height;
  const base = { w: 0.25, h: 0.06, required: 1 };
  const id = "tmp_" + Date.now().toString(36) + "_" + Math.random().toString(16).slice(2);
  let fieldLabel = "";
  let req = 1;
  if (currentFieldKey === "free_text") {
    freeTextCount += 1;
    fieldLabel = "Text " + freeTextCount;
    req = 0;
  }
  const def = {
    id,
    field_key: currentFieldKey,
    field_label: fieldLabel,
    page_num: pageNum,
    w: base.w,
    h: base.h,
    required: req,
    x: Math.max(0, Math.min(1 - base.w, x - base.w / 2)),
    y: Math.max(0, Math.min(1 - base.h, y - base.h / 2)),
  };
  boxes.push(def);
  makeBox(def);
  applyBox(def.field_key, def);
  selectedBoxId = def.id;
  setStatus("Placed: " + (def.field_label || labelFor(def.field_key)) + " on page " + pageNum);
}

async function deleteSelectedField() {
  if (!selectedBoxId) {
    setStatus("Click a field on the PDF to select it, then remove.");
    return;
  }
  const idx = boxes.findIndex(b => String(b.id) === String(selectedBoxId));
  if (idx < 0) return;
  boxes.splice(idx, 1);
  selectedBoxId = null;
  freeTextCount = boxes.filter(b => b.field_key === "free_text").length;
  await render();
  setStatus("Field removed.");
}

// Only place a new box when clicking empty overlay — not when a click bubbles from a field (avoids duplicates after drag/resize).
overlay.addEventListener("click", (ev) => {
  if (ev.target !== overlay) return;
  placeNewAt(ev.clientX, ev.clientY);
});

document.getElementById("prevPageBtn").addEventListener("click", async () => {
  if (!pdfDoc || pageNum <= 1) return;
  pageNum -= 1;
  await render();
});
document.getElementById("nextPageBtn").addEventListener("click", async () => {
  if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
  pageNum += 1;
  await render();
});

// Delete selected field, or nudge with arrows
window.addEventListener("keydown", (ev) => {
  if (ev.key === "Delete" || ev.key === "Backspace") {
    if (!selectedBoxId) return;
    const el = document.querySelector(`[data-box-id="${selectedBoxId}"]`);
    if (!el || el.style.display === "none") return;
    ev.preventDefault();
    deleteSelectedField();
    return;
  }
  if (!selectedBoxId) return;
  const el = document.querySelector(`[data-box-id="${selectedBoxId}"]`);
  if (!el || el.style.display === "none") return;
  const step = ev.shiftKey ? 10 : 1;
  if (!["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].includes(ev.key)) return;
  ev.preventDefault();
  const left = parseFloat(el.style.left || "0");
  const top = parseFloat(el.style.top || "0");
  if (ev.key === "ArrowLeft") el.style.left = Math.max(0, left - step) + "px";
  if (ev.key === "ArrowRight") el.style.left = Math.max(0, left + step) + "px";
  if (ev.key === "ArrowUp") el.style.top = Math.max(0, top - step) + "px";
  if (ev.key === "ArrowDown") el.style.top = Math.max(0, top + step) + "px";
  const idx = boxes.findIndex(b => String(b.id) === String(selectedBoxId));
  if (idx >= 0) boxes[idx] = Object.assign({}, boxes[idx], normalizeBox(el), { page_num: pageNum });
});

if (removeFieldBtn) {
  removeFieldBtn.addEventListener("click", () => { deleteSelectedField(); });
}

saveBtn.addEventListener("click", async () => {
  const oldText = saveBtn.textContent;
  saveBtn.disabled = true;
  saveBtn.textContent = "Saving…";
  setStatus("Saving…");
  try {
    const defs = boxes.map(d => ({
      field_key: d.field_key,
      field_label: d.field_label || "",
      page_num: d.page_num || 1,
      x: d.x, y: d.y, w: d.w, h: d.h,
      required: d.required ? 1 : 0
    }));
    const res = await fetch("index.php?api=save_nda_fields", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ project_id: PROJECT_ID, defs })
    });
    const j = await res.json().catch(() => null);
    if (!res.ok || !j || !j.ok) {
      setStatus("Save failed.", "err");
      return;
    }
    const t = new Date();
    const hh = String(t.getHours()).padStart(2, "0");
    const mm = String(t.getMinutes()).padStart(2, "0");
    const ss = String(t.getSeconds()).padStart(2, "0");
    setStatus("Saved at " + hh + ":" + mm + ":" + ss, "ok");
    if (typeof window.showGdsToast === "function") window.showGdsToast("Saved");
  } finally {
    saveBtn.disabled = false;
    saveBtn.textContent = oldText;
  }
});

init().catch(err => { console.error(err); setStatus("Failed to load PDF."); });
';
  echo '</script>';

  adminFooter();
  exit;
}

if ($view === 'admins') {
  adminHeader('Administrators');
  $admins = $db->fetchAll('SELECT id, email, created_at FROM admins ORDER BY id ASC');
  if (isset($_GET['added'])) {
    echo '<div class="ok gds-flash"><strong>Administrator added.</strong></div>';
  }
  if (isset($_GET['deleted'])) {
    echo '<div class="ok gds-flash"><strong>Administrator removed.</strong></div>';
  }
  $err = isset($_GET['err']) ? (string)$_GET['err'] : '';
  if ($err !== '') {
    $msg = match ($err) {
      'valid_email' => 'Please enter a valid email address.',
      'short_pass' => 'Password must be at least 10 characters.',
      'exists' => 'That email is already registered.',
      'self' => 'You cannot remove your own account while logged in.',
      'last' => 'At least one administrator must remain.',
      default => 'Could not complete the request.',
    };
    echo '<div class="err gds-flash"><strong>' . Util::h($msg) . '</strong></div>';
  }

  echo '<div class="card">';
  echo '<h2 class="gds-page-title">Administrators</h2>';
  echo '<p class="gds-lead">Add colleagues who can sign in to this admin area. Each user has the same access level.</p>';

  if ($admins) {
    echo '<div class="gds-table-wrap"><table><thead><tr><th>Email</th><th>Created (UTC)</th><th></th></tr></thead><tbody>';
    foreach ($admins as $a) {
      $aid = (int)$a['id'];
      $em = Util::h((string)$a['email']);
      $cr = Util::h((string)$a['created_at']);
      echo '<tr><td>' . $em . '</td><td class="muted">' . $cr . '</td><td class="gds-table-actions">';
      if ((int)Auth::adminId() !== $aid) {
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Remove this administrator?\');">';
        echo '<input type="hidden" name="action" value="delete_admin" />';
        echo '<input type="hidden" name="admin_id" value="' . $aid . '" />';
        echo '<button type="submit" class="btn btn-secondary gds-btn--compact">Remove</button>';
        echo '</form>';
      } else {
        echo '<span class="muted">You</span>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table></div>';
  } else {
    echo '<p class="muted">No administrators found.</p>';
  }

  echo '<hr class="gds-divider" />';
  echo '<h3 class="gds-section-title">Add administrator</h3>';
  echo '<form method="post">';
  echo '<input type="hidden" name="action" value="add_admin" />';
  echo '<div class="row">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="new_admin_email">Email</label>';
  echo '<input id="new_admin_email" name="email" type="email" required autocomplete="email" /></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="new_admin_pass">Password</label>';
  echo '<input id="new_admin_pass" name="password" type="password" required minlength="10" autocomplete="new-password" /></div>';
  echo '</div>';
  echo '<p class="gds-help">Minimum 10 characters. Share credentials through a secure channel.</p>';
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Add administrator</button></div>';
  echo '</form>';
  echo '</div>';
  adminFooter();
  exit;
}

if ($view === 'branding') {
  adminHeader('White label');
  $b = Branding::get($db, $config);
  if (isset($_GET['saved'])) {
    echo '<div class="ok gds-flash"><strong>Saved appearance settings.</strong></div>';
  }
  if (isset($_GET['logo'])) {
    echo '<div class="ok gds-flash"><strong>Logo updated.</strong></div>';
  }
  if (isset($_GET['removed'])) {
    echo '<div class="ok gds-flash"><strong>Logo removed.</strong></div>';
  }
  $berr = isset($_GET['err']) ? (string)$_GET['err'] : '';
  if ($berr !== '') {
    $show = str_starts_with($berr, 'msg:') ? substr($berr, 4) : match ($berr) {
      'no_file' => 'Please choose an image file.',
      'upload' => 'Upload failed.',
      default => 'Could not complete the request.',
    };
    echo '<div class="err gds-flash"><strong>' . Util::h($show) . '</strong></div>';
  }

  echo '<div class="card">';
  echo '<h2 class="gds-page-title">White label</h2>';
  echo '<p class="gds-lead">Rename the product and optionally add a logo. Signers see these on the visitor pages; the admin console uses the same name and logo with a separate subtitle.</p>';

  echo '<form method="post">';
  echo '<input type="hidden" name="action" value="save_branding" />';
  echo '<div class="gds-field"><label class="gds-label" for="app_name">Application name</label>';
  echo '<input id="app_name" name="app_name" type="text" required value="' . Util::h((string)$b['app_name']) . '" maxlength="255" /></div>';
  echo '<div class="gds-field"><label class="gds-label" for="visitor_tagline">Visitor subtitle</label>';
  echo '<input id="visitor_tagline" name="visitor_tagline" type="text" value="' . Util::h((string)$b['visitor_tagline']) . '" maxlength="255" />';
  echo '<span class="gds-help">Shown under the title on signer pages (e.g. secure access line).</span></div>';
  echo '<div class="gds-field"><label class="gds-label" for="admin_tagline">Admin subtitle</label>';
  echo '<input id="admin_tagline" name="admin_tagline" type="text" value="' . Util::h((string)$b['admin_tagline']) . '" maxlength="255" />';
  echo '<span class="gds-help">Second line under the app name in this admin area.</span></div>';
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Save text</button></div>';
  echo '</form>';

  echo '<hr class="gds-divider" />';
  echo '<h3 class="gds-section-title">Logo</h3>';
  echo '<p class="gds-lead" style="margin-bottom:var(--gds-space-3)">PNG, JPG, GIF, WebP, or SVG. Wide wordmarks and tall marks get extra space in the header automatically. Replaces the default mark in the top-left for visitors and admins.</p>';
  if (!empty($b['logo_path'])) {
    echo '<p style="margin:0 0 var(--gds-space-3)"><img src="' . Util::h(Branding::logoHref(false)) . '" alt="Current logo" class="gds-logo-preview" /></p>';
    echo '<form method="post" style="margin-bottom:var(--gds-space-4)" onsubmit="return confirm(\'Remove the custom logo?\');">';
    echo '<input type="hidden" name="action" value="remove_brand_logo" />';
    echo '<button type="submit" class="btn btn-secondary">Remove logo</button>';
    echo '</form>';
  }
  echo '<form method="post" enctype="multipart/form-data">';
  echo '<input type="hidden" name="action" value="upload_brand_logo" />';
  echo '<div class="gds-field"><label class="gds-label" for="brand_logo">Upload logo</label>';
  echo '<input id="brand_logo" name="logo" type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml,.png,.jpg,.jpeg,.gif,.webp,.svg" required /></div>';
  echo '<div class="gds-actions"><button type="submit" class="btn btn-primary">Upload logo</button></div>';
  echo '</form>';

  echo '</div>';
  adminFooter();
  exit;
}

if ($view === 'analytics') {
  require_once dirname(__DIR__, 2) . '/src/AnalyticsReport.php';

  $days = (int)($_GET['days'] ?? 14);
  if ($days < 1) $days = 1;
  if ($days > 90) $days = 90;

  $projectId = (int)($_GET['project_id'] ?? 0);
  $projectsList = $projects->listProjects();
  if ($projectId <= 0 && $projectsList) {
    $projectId = (int)$projectsList[0]['id'];
  }

  adminHeader('Analytics');
  echo '<div class="card">';
  echo '<h2 class="gds-page-title">Analytics</h2>';
  echo '<p class="gds-lead">Time-on-page uses start, heartbeat, and end signals (with a safety cap per view).</p>';

  echo '<form method="get" class="gds-analytics-filters">';
  echo '<input type="hidden" name="view" value="analytics" />';
  echo '<div class="row" style="align-items:flex-end">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="analytics_project">Project</label><select id="analytics_project" name="project_id">';
  foreach ($projectsList as $p) {
    $pid = (int)$p['id'];
    $sel = ($pid === $projectId) ? ' selected' : '';
    echo '<option value="' . $pid . '"' . $sel . '>' . Util::h((string)$p['name']) . '</option>';
  }
  echo '</select></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="analytics_days">Range (days)</label><input id="analytics_days" name="days" type="number" min="1" max="90" value="' . (int)$days . '" /></div>';
  echo '<div class="gds-filter-actions"><button type="submit" class="btn btn-primary">Apply</button></div>';
  echo '</div>';
  echo '</form>';

  if ($projectId <= 0) {
    echo '<div class="err gds-flash"><strong>No projects yet.</strong> Create a project first to see analytics.</div>';
    echo '</div>';
    adminFooter();
    exit;
  }

  // Safety cap: treat any single view as max 30 minutes to avoid inflated tab-left-open time.
  $capMs = 30 * 60 * 1000;
  $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

  $pageKeyLabel = static function (string $k): string {
    return match ($k) {
      'viewer_file' => 'Document viewer',
      'viewer_nda' => 'NDA viewer',
      'files' => 'File list',
      'nda_sign' => 'Sign NDA',
      'email_gate' => 'Email entry',
      'welcome' => 'Welcome',
      'invalid_project' => 'Invalid link',
      'missing_project' => 'Missing link',
      default => $k,
    };
  };

  $overview = $db->fetchOne(
    'SELECT
       COUNT(*) AS views,
       COUNT(DISTINCT session_id) AS sessions,
       COUNT(DISTINCT signer_email) AS users,
       SUM(LEAST(duration_ms, :cap)) AS total_ms
     FROM analytics_page_views
     WHERE project_id = :pid
       AND started_at >= :since',
    [':pid' => $projectId, ':since' => $since, ':cap' => $capMs],
  );

  $views = (int)($overview['views'] ?? 0);
  $sessions = (int)($overview['sessions'] ?? 0);
  $users = (int)($overview['users'] ?? 0);
  $totalMs = (int)($overview['total_ms'] ?? 0);
  $totalMin = $totalMs > 0 ? round($totalMs / 60000, 1) : 0;

  echo '<div class="gds-stat-grid">';
  echo '<div class="gds-stat-card"><div class="gds-stat-label">Views</div><div class="gds-stat-value">' . $views . '</div></div>';
  echo '<div class="gds-stat-card"><div class="gds-stat-label">Sessions</div><div class="gds-stat-value">' . $sessions . '</div></div>';
  echo '<div class="gds-stat-card"><div class="gds-stat-label">Users (signed email)</div><div class="gds-stat-value">' . $users . '</div></div>';
  echo '<div class="gds-stat-card"><div class="gds-stat-label">Total time (min)</div><div class="gds-stat-value">' . Util::h((string)$totalMin) . '</div></div>';
  echo '</div>';

  $byDay = $db->fetchAll(
    'SELECT DATE(started_at) AS d, COUNT(*) AS views, SUM(LEAST(duration_ms, :cap)) AS total_ms
     FROM analytics_page_views
     WHERE project_id = :pid AND started_at >= :since
     GROUP BY DATE(started_at)
     ORDER BY d ASC',
    [':pid' => $projectId, ':since' => $since, ':cap' => $capMs],
  );

  $byPage = $db->fetchAll(
    'SELECT page_key, COUNT(*) AS views, SUM(LEAST(duration_ms, :cap)) AS total_ms
     FROM analytics_page_views
     WHERE project_id = :pid AND started_at >= :since
     GROUP BY page_key
     ORDER BY total_ms DESC
     LIMIT 10',
    [':pid' => $projectId, ':since' => $since, ':cap' => $capMs],
  );

  $byUser = $db->fetchAll(
    'SELECT COALESCE(signer_email, \'(unknown)\') AS email, COUNT(*) AS views, SUM(LEAST(duration_ms, :cap)) AS total_ms
     FROM analytics_page_views
     WHERE project_id = :pid AND started_at >= :since
     GROUP BY COALESCE(signer_email, \'(unknown)\')
     ORDER BY total_ms DESC
     LIMIT 20',
    [':pid' => $projectId, ':since' => $since, ':cap' => $capMs],
  );

  $dayLabels = [];
  $dayViews = [];
  $dayMinutes = [];
  foreach ($byDay as $r) {
    $dayLabels[] = (string)$r['d'];
    $dayViews[] = (int)$r['views'];
    $dayMinutes[] = round(((int)$r['total_ms']) / 60000, 2);
  }

  $pageLabels = [];
  $pageMinutes = [];
  foreach ($byPage as $r) {
    $pageLabels[] = $pageKeyLabel((string)$r['page_key']);
    $pageMinutes[] = round(((int)$r['total_ms']) / 60000, 2);
  }

  $jsonDayLabels = json_encode($dayLabels, JSON_UNESCAPED_SLASHES);
  $jsonDayViews = json_encode($dayViews, JSON_UNESCAPED_SLASHES);
  $jsonDayMinutes = json_encode($dayMinutes, JSON_UNESCAPED_SLASHES);
  $jsonPageLabels = json_encode($pageLabels, JSON_UNESCAPED_SLASHES);
  $jsonPageMinutes = json_encode($pageMinutes, JSON_UNESCAPED_SLASHES);

  echo '<div class="gds-chart-grid">';
  echo '<div class="card gds-chart-card"><h3>Views over time</h3><canvas id="viewsChart" height="120"></canvas></div>';
  echo '<div class="card gds-chart-card"><h3>Time (minutes) over time</h3><canvas id="timeChart" height="120"></canvas></div>';
  echo '</div>';

  echo '<div class="card gds-chart-card" style="margin-top:var(--gds-space-4)">';
  echo '<h3>Top pages by time</h3>';
  echo '<canvas id="pagesChart" height="120"></canvas>';
  echo '</div>';

  echo '<div class="gds-chart-grid" style="margin-top:var(--gds-space-4);align-items:start">';
  echo '<div class="card gds-chart-card">';
  echo '<h3>Top users by time</h3>';
  echo '<div class="gds-table-wrap"><table><thead><tr><th>Email</th><th>Views</th><th>Minutes</th><th></th></tr></thead><tbody>';
  foreach ($byUser as $u) {
    $em = (string)$u['email'];
    $mins = round(((int)$u['total_ms']) / 60000, 2);
    $link = 'index.php?view=analytics_user&project_id=' . urlencode((string)$projectId) . '&days=' . urlencode((string)$days) . '&email=' . urlencode($em);
    echo '<tr>';
    echo '<td>' . Util::h($em) . '</td>';
    echo '<td class="muted">' . (int)$u['views'] . '</td>';
    echo '<td class="muted">' . Util::h((string)$mins) . '</td>';
    echo '<td><a href="' . Util::h($link) . '">Drilldown</a></td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
  echo '</div>';
  echo '</div>';

  echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
  echo '<script>';
  echo 'const dayLabels = ' . ($jsonDayLabels ?: '[]') . ';';
  echo 'const dayViews = ' . ($jsonDayViews ?: '[]') . ';';
  echo 'const dayMinutes = ' . ($jsonDayMinutes ?: '[]') . ';';
  echo 'const pageLabels = ' . ($jsonPageLabels ?: '[]') . ';';
  echo 'const pageMinutes = ' . ($jsonPageMinutes ?: '[]') . ';';
  echo '
  const axisStyle = {
    ticks: { color: "#6e6e73" },
    grid: { color: "rgba(0, 0, 0, 0.06)" },
  };
  const commonOpts = {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      x: axisStyle,
      y: axisStyle,
    }
  };

  new Chart(document.getElementById("viewsChart"), {
    type: "line",
    data: { labels: dayLabels, datasets: [{ label: "Views", data: dayViews, borderColor: "#3a3a3c", backgroundColor: "rgba(58, 58, 60, 0.1)", fill: true, tension: .25 }] },
    options: commonOpts
  });

  new Chart(document.getElementById("timeChart"), {
    type: "line",
    data: { labels: dayLabels, datasets: [{ label: "Minutes", data: dayMinutes, borderColor: "#059669", backgroundColor: "rgba(5, 150, 105, 0.12)", fill: true, tension: .25 }] },
    options: commonOpts
  });

  new Chart(document.getElementById("pagesChart"), {
    type: "bar",
    data: { labels: pageLabels, datasets: [{ label: "Minutes", data: pageMinutes, backgroundColor: "rgba(58, 58, 60, 0.18)", borderColor: "rgba(58, 58, 60, 0.45)", borderWidth: 1 }] },
    options: Object.assign({}, commonOpts, { indexAxis: "y" })
  });
  ';
  echo '</script>';

  echo '</div>';
  adminFooter();
  exit;
}

if ($view === 'analytics_user') {
  require_once dirname(__DIR__, 2) . '/src/AnalyticsReport.php';

  $days = (int)($_GET['days'] ?? 14);
  if ($days < 1) $days = 1;
  if ($days > 90) $days = 90;

  $projectId = (int)($_GET['project_id'] ?? 0);
  $projectsList = $projects->listProjects();
  if ($projectId <= 0 && $projectsList) {
    $projectId = (int)$projectsList[0]['id'];
  }

  $drillEmail = isset($_GET['email']) ? (string)$_GET['email'] : '';
  if ($drillEmail === '') {
    adminHeader('User analytics');
    echo '<div class="card"><h2 class="gds-page-title">User analytics</h2><div class="err gds-flash"><strong>Missing user.</strong> Go back to Analytics and choose Drilldown next to a user.</div></div>';
    adminFooter();
    exit;
  }

  adminHeader('User analytics');
  echo '<div class="card">';
  echo '<h2 class="gds-page-title">User analytics</h2>';

  $backUrl = 'index.php?view=analytics&project_id=' . urlencode((string)$projectId) . '&days=' . urlencode((string)$days);
  echo '<p class="gds-lead" style="margin-bottom:var(--gds-space-3)">Showing activity for <strong>' . Util::h($drillEmail) . '</strong> · <a href="' . Util::h($backUrl) . '">Back to Analytics</a></p>';

  // Filters
  echo '<form method="get" class="gds-analytics-filters" style="margin-bottom:var(--gds-space-4)">';
  echo '<input type="hidden" name="view" value="analytics_user" />';
  echo '<input type="hidden" name="email" value="' . Util::h($drillEmail) . '" />';
  echo '<div class="row" style="align-items:flex-end">';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="analytics_user_project">Project</label><select id="analytics_user_project" name="project_id">';
  foreach ($projectsList as $p) {
    $pid = (int)$p['id'];
    $sel = ($pid === $projectId) ? ' selected' : '';
    echo '<option value="' . $pid . '"' . $sel . '>' . Util::h((string)$p['name']) . '</option>';
  }
  echo '</select></div>';
  echo '<div class="gds-field" style="margin-bottom:0"><label class="gds-label" for="analytics_user_days">Range (days)</label><input id="analytics_user_days" name="days" type="number" min="1" max="90" value="' . (int)$days . '" /></div>';
  echo '<div class="gds-filter-actions"><button type="submit" class="btn btn-primary">Apply</button></div>';
  echo '</div>';
  echo '</form>';

  if ($projectId <= 0) {
    echo '<div class="err gds-flash"><strong>No projects yet.</strong> Create a project first to see analytics.</div>';
    echo '</div>';
    adminFooter();
    exit;
  }

  $capMs = 30 * 60 * 1000;
  $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

  $ndaNameForDrill = 'NDA';
  $ndaRowDrill = $projects->getNda($projectId);
  if ($ndaRowDrill) {
    $ndaNameForDrill = (string)$ndaRowDrill['original_name'];
  }

  // Build doc summary (same as overview drilldown, but here in a dedicated page).
  $docParams = [':pid' => $projectId, ':since' => $since, ':cap' => $capMs];
  $docSql = 'SELECT path, SUM(LEAST(duration_ms, :cap)) AS total_ms, COUNT(*) AS views
     FROM analytics_page_views
     WHERE project_id = :pid AND started_at >= :since';
  if ($drillEmail === '(unknown)') {
    $docSql .= ' AND signer_email IS NULL';
  } else {
    $docSql .= ' AND signer_email = :dem';
    $docParams[':dem'] = strtolower($drillEmail);
  }
  $docSql .= ' GROUP BY path ORDER BY total_ms DESC';
  $docRows = $db->fetchAll($docSql, $docParams);

  $labelFor = static function (array $b) use ($projects, $ndaNameForDrill): string {
    if (!empty($b['is_nda'])) {
      return $ndaNameForDrill;
    }
    if ($b['file_id'] !== null) {
      $f = $projects->getFile((int)$b['file_id']);
      return $f ? (string)$f['original_name'] : ('File #' . (int)$b['file_id']);
    }
    return 'Other / app screen';
  };
  $docMerged = AnalyticsReport::mergeDocumentBuckets($docRows, $labelFor);

  // Events for dwell + session timeline
  $evParams = [':pid' => $projectId, ':since' => $since];
  $evSql = 'SELECT created_at, session_id, event_key, page_key, path, payload_json FROM analytics_events
     WHERE project_id = :pid AND created_at >= :since';
  if ($drillEmail === '(unknown)') {
    $evSql .= ' AND signer_email IS NULL';
  } else {
    $evSql .= ' AND signer_email = :dem';
    $evParams[':dem'] = strtolower($drillEmail);
  }
  $evSql .= ' AND event_key IN (\'page_start\', \'page_heartbeat\', \'page_visible\', \'page_hidden\', \'page_end\')
     ORDER BY created_at ASC';
  $evRows = $db->fetchAll($evSql, $evParams);

  $detailDwell = AnalyticsReport::viewerDetailDwellFromEvents($evRows);
  $timeline = AnalyticsReport::buildViewerSessionTimeline($evRows, $capMs);

  // Timeline UI
  echo '<div class="gds-ua-grid">';
  echo '<div class="card gds-chart-card">';
  echo '<h3 style="margin-bottom:var(--gds-space-2)">Viewer timeline</h3>';
  echo '<p class="muted" style="margin:0 0 var(--gds-space-3);font-size:var(--gds-text-sm)">Sessions are grouped by viewer opens. Segment widths approximate time spent on each PDF page or spreadsheet tab (based on heartbeats).</p>';

  if (!$timeline && !$docMerged && !$detailDwell) {
    $backToAnalyticsForProject = 'index.php?view=analytics&project_id=' . urlencode((string)$projectId) . '&days=' . urlencode((string)$days);
    echo '<div class="gds-empty">';
    echo '<div class="gds-empty__title">No activity for this user in this project/range.</div>';
    echo '<div class="gds-empty__sub muted">Try a different project or range, or go back to the Analytics overview.</div>';
    echo '<div class="gds-actions" style="margin-top:var(--gds-space-3)">';
    echo '<a class="btn btn-secondary" href="' . Util::h($backToAnalyticsForProject) . '">Back to Analytics</a>';
    echo '</div>';
    echo '</div>';
  } elseif (!$timeline) {
    echo '<p class="muted" style="margin:0">No viewer timeline yet for this user in the selected range.</p>';
  } else {
    echo '<div class="gds-session-list">';
    foreach ($timeline as $s) {
      $totalMs = (int)$s['total_ms'];
      if ($totalMs <= 0) continue;
      $startedRaw = (string)$s['started_at'];
      $endedRaw = (string)$s['ended_at'];
      $started = Util::h($startedRaw);
      $ended = Util::h($endedRaw);
      $mins = round($totalMs / 60000, 2);

      // Build session-wide doc timeline (color by doc) and per-doc page timeline.
      $byDoc = [];
      foreach (($s['segments'] ?? []) as $seg) {
        if (!is_array($seg)) continue;
        $doc = (string)($seg['doc_label'] ?? 'Document');
        $byDoc[$doc][] = $seg;
      }
      if (!$byDoc) continue;

      echo '<div class="gds-session-card" data-session-start="' . $started . '" data-session-end="' . $ended . '" data-session-ms="' . (int)$totalMs . '">';
      echo '<div class="gds-session-head">';
      echo '<div><div class="gds-session-title"><strong>' . Util::h((string)$mins) . ' min</strong></div>';
      echo '<div class="muted gds-session-sub" title="UTC">' . $started . ' → ' . $ended . '</div></div>';
      echo '<div class="gds-session-badge muted">Session</div>';
      echo '</div>';

      // Session-wide timeline: each segment represents time on a document (across all its pages).
      echo '<div class="gds-session-track" data-session-ms="' . (int)$totalMs . '">';
      foreach ($byDoc as $docLabel => $segs) {
        $docMs = 0;
        $docKind = '';
        $fileId = 0;
        $vkFirst = '';
        foreach ($segs as $seg) {
          $ms = (int)($seg['ms'] ?? 0);
          if ($ms <= 0) continue;
          $docMs += $ms;
          if ($docKind === '') $docKind = (string)($seg['doc_kind'] ?? '');
          if ($fileId <= 0) $fileId = (int)($seg['file_id'] ?? 0);
          if ($vkFirst === '') $vkFirst = (string)($seg['viewer_kind'] ?? '');
        }
        if ($docMs <= 0) continue;
        $h = hexdec(substr(md5('file|' . $docLabel), 0, 6)) % 360;
        $bg = 'hsla(' . $h . ', 75%, 55%, 0.32)';
        echo '<div class="gds-session-seg"'
          . ' style="flex:' . (int)$docMs . ';background:' . Util::h($bg) . '"'
          . ' data-doc-label="' . Util::h($docLabel) . '"'
          . ' data-doc-kind="' . Util::h($docKind) . '"'
          . ' data-file-id="' . (int)$fileId . '"'
          . ' data-viewer-kind="' . Util::h($vkFirst) . '"'
          . ' data-ms="' . (int)$docMs . '"'
          . '></div>';
      }
      echo '</div>';

      echo '<div class="gds-doc-list">';
      foreach ($byDoc as $docLabel => $segs) {
        // Aggregate per page/tab so we don't show back-and-forth fragmentation.
        $agg = []; // key => ['ms'=>int,'viewer_kind'=>string,'page_number'=>?int,'sheet_tab'=>?string,'doc_kind'=>string,'file_id'=>int]
        $sheetOrder = []; // tab => first index
        $docKind0 = '';
        $fileId0 = 0;
        foreach ($segs as $i0 => $seg0) {
          if (!is_array($seg0)) continue;
          $ms0 = (int)($seg0['ms'] ?? 0);
          if ($ms0 <= 0) continue;
          $vk0 = (string)($seg0['viewer_kind'] ?? '');
          $docKind0 = $docKind0 !== '' ? $docKind0 : (string)($seg0['doc_kind'] ?? '');
          $fileId0 = $fileId0 > 0 ? $fileId0 : (int)($seg0['file_id'] ?? 0);
          $page0 = isset($seg0['page_number']) ? (int)$seg0['page_number'] : 0;
          $tab0 = isset($seg0['sheet_tab']) && is_string($seg0['sheet_tab']) ? $seg0['sheet_tab'] : '';

          $key0 = '';
          if ($vk0 === 'pdf' && $page0 > 0) {
            $key0 = 'pdf:p' . $page0;
          } elseif ($vk0 === 'sheet' && $tab0 !== '') {
            $key0 = 'sheet:' . $tab0;
            if (!isset($sheetOrder[$tab0])) {
              $sheetOrder[$tab0] = (int)$i0;
            }
          } elseif ($vk0 === 'image') {
            $key0 = 'image';
          } else {
            continue;
          }

          if (!isset($agg[$key0])) {
            $agg[$key0] = [
              'ms' => 0,
              'viewer_kind' => $vk0,
              'page_number' => ($vk0 === 'pdf' && $page0 > 0) ? $page0 : null,
              'sheet_tab' => ($vk0 === 'sheet' && $tab0 !== '') ? $tab0 : null,
              'doc_kind' => (string)($seg0['doc_kind'] ?? ''),
              'file_id' => (int)($seg0['file_id'] ?? 0),
            ];
          }
          $agg[$key0]['ms'] += $ms0;
        }

        $items = array_values($agg);
        // Sort: PDF by page number asc; sheet by first-seen order; image single.
        usort($items, static function (array $a, array $b) use ($sheetOrder): int {
          $va = (string)($a['viewer_kind'] ?? '');
          $vb = (string)($b['viewer_kind'] ?? '');
          if ($va === 'pdf' && $vb === 'pdf') {
            return ((int)($a['page_number'] ?? 0)) <=> ((int)($b['page_number'] ?? 0));
          }
          if ($va === 'sheet' && $vb === 'sheet') {
            $ta = (string)($a['sheet_tab'] ?? '');
            $tb = (string)($b['sheet_tab'] ?? '');
            return ($sheetOrder[$ta] ?? 0) <=> ($sheetOrder[$tb] ?? 0);
          }
          if ($va === 'image' && $vb !== 'image') return -1;
          if ($vb === 'image' && $va !== 'image') return 1;
          return strcmp($va, $vb);
        });

        $docTotalMs = 0;
        foreach ($items as $it) {
          $docTotalMs += (int)($it['ms'] ?? 0);
        }
        echo '<div class="gds-doc-row">';
        echo '<div class="gds-doc-name">' . Util::h($docLabel) . '</div>';
        echo '<div class="gds-doc-track" data-doc-ms="' . (int)$docTotalMs . '">';
        foreach ($items as $it) {
          $ms = (int)($it['ms'] ?? 0);
          if ($ms <= 0) continue;
          $vk = (string)($it['viewer_kind'] ?? '');
          $fid = (int)($it['file_id'] ?? 0);
          $docKind = (string)($it['doc_kind'] ?? $docKind0);
          $pageNum = isset($it['page_number']) && $it['page_number'] !== null ? (int)$it['page_number'] : 0;
          $sheetTab = isset($it['sheet_tab']) && $it['sheet_tab'] !== null ? (string)$it['sheet_tab'] : '';

          $lab = '';
          if ($vk === 'pdf' && $pageNum > 0) $lab = 'Page ' . $pageNum;
          elseif ($vk === 'sheet' && $sheetTab !== '') $lab = 'Sheet: ' . $sheetTab;
          elseif ($vk === 'image') $lab = 'Image';
          else $lab = 'View';

          // Same color for all fragments within the same file (docLabel).
          $h = hexdec(substr(md5('file|' . $docLabel), 0, 6)) % 360;
          $bg = 'hsla(' . $h . ', 75%, 55%, 0.32)';

          echo '<div class="gds-doc-seg"'
            . ' style="flex:' . (int)$ms . ';background:' . Util::h($bg) . '"'
            . ' data-doc-kind="' . Util::h($docKind) . '"'
            . ' data-file-id="' . (int)$fid . '"'
            . ' data-viewer-kind="' . Util::h($vk) . '"'
            . ' data-page-number="' . (int)$pageNum . '"'
            . ' data-sheet-tab="' . Util::h($sheetTab) . '"'
            . ' data-doc-label="' . Util::h($docLabel) . '"'
            . ' data-label="' . Util::h($lab) . '"'
            . ' data-ms="' . (int)$ms . '"'
            . '></div>';
        }
        echo '</div>';
        echo '</div>';
      }
      echo '</div>';

      echo '</div>';
    }
    echo '</div>';
  }
  echo '</div>';

  echo '<div class="card gds-chart-card">';
  echo '<h3 style="margin-bottom:var(--gds-space-2)">Documents opened</h3>';
  echo '<p class="muted" style="margin:0 0 var(--gds-space-2);font-size:var(--gds-text-sm)">Total time in the in-app viewer per document (same file opened multiple times is summed).</p>';
  if ($docMerged) {
    echo '<div class="gds-table-wrap"><table><thead><tr><th>Document</th><th>Viewer sessions</th><th>Total time</th></tr></thead><tbody>';
    foreach ($docMerged as $dm) {
      $mins = round(((int)$dm['total_ms']) / 60000, 2);
      echo '<tr><td>' . Util::h((string)$dm['label']) . '</td><td class="muted">' . (int)$dm['views'] . '</td><td class="muted">' . Util::h((string)$mins) . ' min</td></tr>';
    }
    echo '</tbody></table></div>';
  } else {
    echo '<p class="muted" style="margin:0">No document viewer sessions in this range.</p>';
  }
  echo '</div>';
  echo '</div>';

  // Secondary: dwell table
  echo '<div class="card gds-chart-card" style="margin-top:var(--gds-space-4)">';
  echo '<h3 style="margin-bottom:var(--gds-space-2)">Time on PDF pages &amp; spreadsheet tabs</h3>';
  echo '<p class="muted" style="margin:0 0 var(--gds-space-2);font-size:var(--gds-text-sm)">Estimated from time between analytics events while the viewer reported a given page or sheet (15s heartbeats).</p>';
  if ($detailDwell) {
    echo '<div class="gds-table-wrap"><table><thead><tr><th>Location</th><th>Est. time</th></tr></thead><tbody>';
    foreach ($detailDwell as $label => $ms) {
      $mins = round($ms / 60000, 2);
      echo '<tr><td>' . Util::h((string)$label) . '</td><td class="muted">' . Util::h((string)$mins) . ' min</td></tr>';
    }
    echo '</tbody></table></div>';
  } else {
    echo '<p class="muted" style="margin:0">No per-page detail yet for this user in this range.</p>';
  }
  echo '</div>';

  // Hover tooltip + thumbnails (PDF pages + images)
  $pdfJsV = @filemtime(dirname(__DIR__) . '/assets/vendor/pdf.min.js') ?: time();
  $pdfWorkerV = @filemtime(dirname(__DIR__) . '/assets/vendor/pdf.worker.min.js') ?: time();
  echo '<script src="' . Util::h('../public/assets/vendor/pdf.min.js?v=' . $pdfJsV) . '"></script>';
  echo '<script>';
  echo 'window.__gdsPdfWorkerSrc = ' . json_encode('../public/assets/vendor/pdf.worker.min.js?v=' . $pdfWorkerV, JSON_UNESCAPED_SLASHES) . ';';
  echo 'window.__gdsMediaBase = ' . json_encode('media.php?project_id=' . (string)$projectId, JSON_UNESCAPED_SLASHES) . ';';
  echo '</script>';
  echo <<<'HTML'
  <div id="gdsTimelineTip" class="gds-tip" hidden>
    <div class="gds-tip__title"></div>
    <div class="gds-tip__sub muted"></div>
    <div class="gds-tip__thumb"></div>
  </div>
  <script>
  (function () {
    var tip = document.getElementById('gdsTimelineTip');
    if (!tip) return;
    var titleEl = tip.querySelector('.gds-tip__title');
    var subEl = tip.querySelector('.gds-tip__sub');
    var thumbEl = tip.querySelector('.gds-tip__thumb');
    var pdfThumbCache = new Map(); // key -> dataURL
    var pdfBytesCache = new Map(); // doc key -> ArrayBuffer
    var hovering = null;

    function showTip(x, y) {
      tip.hidden = false;
      var pad = 16;
      var vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
      var vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
      tip.style.left = Math.min(vw - pad, x + 14) + 'px';
      tip.style.top = Math.min(vh - pad, y + 14) + 'px';
    }
    function hideTip() {
      tip.hidden = true;
      hovering = null;
    }

    function mediaUrl(params) {
      return window.__gdsMediaBase + '&' + new URLSearchParams(params).toString();
    }

    async function ensurePdfWorker() {
      if (typeof pdfjsLib === 'undefined') return false;
      try {
        pdfjsLib.GlobalWorkerOptions.workerSrc = window.__gdsPdfWorkerSrc || '';
      } catch (e) {}
      return true;
    }

    async function fetchPdfBytes(docKind, fileId) {
      var k = docKind + ':' + String(fileId || '');
      if (pdfBytesCache.has(k)) return pdfBytesCache.get(k);
      var url = mediaUrl({ doc_kind: docKind === 'nda' ? 'nda' : 'file', file_id: fileId || '', mode: 'pdf' });
      var ab = await fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.arrayBuffer();
      });
      pdfBytesCache.set(k, ab);
      return ab;
    }

    async function renderPdfThumb(docKind, fileId, pageNum) {
      var key = docKind + ':' + String(fileId || '') + ':p' + String(pageNum || 1);
      if (pdfThumbCache.has(key)) return pdfThumbCache.get(key);
      if (!(await ensurePdfWorker())) return null;
      var ab = await fetchPdfBytes(docKind, fileId);
      var pdf = await pdfjsLib.getDocument({ data: new Uint8Array(ab), useSystemFonts: true }).promise;
      var page = await pdf.getPage(pageNum || 1);
      var viewport = page.getViewport({ scale: 1.0 });
      var targetW = 220;
      var scale = targetW / Math.max(1, viewport.width);
      var vp = page.getViewport({ scale: scale });
      var canvas = document.createElement('canvas');
      canvas.width = Math.floor(vp.width);
      canvas.height = Math.floor(vp.height);
      var ctx = canvas.getContext('2d');
      await page.render({ canvasContext: ctx, viewport: vp }).promise;
      var dataUrl = canvas.toDataURL('image/png');
      pdfThumbCache.set(key, dataUrl);
      return dataUrl;
    }

    function thumbUrlForPdf(docKind, fileId, pageNum) {
      return mediaUrl({ doc_kind: docKind === 'nda' ? 'nda' : 'file', file_id: fileId || '', mode: 'thumb', page: String(pageNum || 1) });
    }

    function setThumbLoading() {
      thumbEl.innerHTML = '<div class="gds-tip__ph">Loading preview…</div>';
    }

    function setThumbImage(src) {
      thumbEl.innerHTML = '';
      var img = document.createElement('img');
      img.src = src;
      img.alt = '';
      img.decoding = 'async';
      thumbEl.appendChild(img);
    }

    function setThumbNone() {
      thumbEl.innerHTML = '<div class="gds-tip__ph">No preview</div>';
    }

    document.addEventListener('mousemove', function (e) {
      if (!hovering) return;
      showTip(e.clientX, e.clientY);
    }, { passive: true });

    document.addEventListener('mouseout', function (e) {
      var seg = e.target && e.target.closest ? e.target.closest('.gds-doc-seg') : null;
      if (seg) return;
      hideTip();
    });

    document.addEventListener('mouseover', async function (e) {
      var seg = e.target && e.target.closest ? e.target.closest('.gds-doc-seg, .gds-session-seg') : null;
      if (!seg) return;
      hovering = seg;
      var isSessionSeg = seg.classList.contains('gds-session-seg');
      var docLabel = seg.getAttribute('data-doc-label') || 'Document';
      var label = seg.getAttribute('data-label') || '';
      var ms = parseInt(seg.getAttribute('data-ms') || '0', 10) || 0;
      var mins = Math.round((ms / 60000) * 100) / 100;
      var vk = seg.getAttribute('data-viewer-kind') || '';
      var docKind = seg.getAttribute('data-doc-kind') || '';
      var fileId = parseInt(seg.getAttribute('data-file-id') || '0', 10) || 0;
      var pageNum = parseInt(seg.getAttribute('data-page-number') || '0', 10) || 0;
      var tab = seg.getAttribute('data-sheet-tab') || '';
      var sessionCard = seg.closest ? seg.closest('.gds-session-card') : null;
      var sessStart = sessionCard ? (sessionCard.getAttribute('data-session-start') || '') : '';
      var sessEnd = sessionCard ? (sessionCard.getAttribute('data-session-end') || '') : '';
      var sessMs = sessionCard ? (parseInt(sessionCard.getAttribute('data-session-ms') || '0', 10) || 0) : 0;
      var sessMin = sessMs ? (Math.round((sessMs / 60000) * 100) / 100) : 0;

      if (titleEl) titleEl.textContent = isSessionSeg ? 'Session' : docLabel;
      if (subEl) {
        if (isSessionSeg) {
          subEl.textContent = (sessStart && sessEnd ? (sessStart + ' → ' + sessEnd + ' · ') : '') + (sessMin ? (sessMin + ' min total · ') : '') + docLabel + ' · ' + mins + ' min';
        } else {
          var where = '';
          if (vk === 'pdf' && pageNum > 0) where = 'Page ' + pageNum;
          else if (vk === 'sheet' && tab) where = 'Sheet: ' + tab;
          else if (vk === 'image') where = 'Image';
          else where = label || '';
          subEl.textContent = where + ' · ' + mins + ' min';
        }
      }
      setThumbLoading();

      try {
        if (vk === 'image') {
          var imgUrl = mediaUrl({ doc_kind: 'file', file_id: String(fileId), mode: 'image' });
          setThumbImage(imgUrl);
        } else if (vk === 'sheet') {
          // XLSX previews are rendered as PDF in the viewer; use page 1 thumbnail.
          var kindS = (docKind === 'nda') ? 'nda' : 'file';
          var fidS = (docKind === 'nda') ? '' : String(fileId);
          setThumbImage(thumbUrlForPdf(kindS, fidS, 1));
        } else if (vk === 'pdf') {
          var p = isSessionSeg ? 1 : pageNum;
          if (p <= 0) p = 1;
          var kind = (docKind === 'nda') ? 'nda' : 'file';
          var fid = (docKind === 'nda') ? '' : String(fileId);
          try {
            var dataUrl = await renderPdfThumb(kind, fid, p);
            if (dataUrl) {
              setThumbImage(dataUrl);
            } else {
              setThumbImage(thumbUrlForPdf(kind, fid, p));
            }
          } catch (e2) {
            // Fallback to server-side cached JPEG thumbnail if PDF.js rendering fails.
            setThumbImage(thumbUrlForPdf(kind, fid, p));
          }
        } else {
          setThumbNone();
        }
      } catch (err) {
        setThumbNone();
      }

      showTip(e.clientX, e.clientY);
    }, { passive: true });
  })();
  </script>
HTML;

  echo '</div>';
  adminFooter();
  exit;
}

if ($view === 'project') {
  $pid = (int)($_GET['project_id'] ?? 0);
  $proj = $db->fetchOne('SELECT * FROM projects WHERE id = :id LIMIT 1', [':id' => $pid]);
  if (!$proj) {
    adminHeader('Not found');
    echo '<div class="card"><h2 class="gds-page-title">Not found</h2><div class="err gds-flash"><strong>Project not found.</strong></div></div>';
    adminFooter();
    exit;
  }

  $baseUrl = Util::baseUrl($config);
  $publicBase = isset($config['public_base_url']) ? trim((string)$config['public_base_url']) : '';
  if ($publicBase !== '') {
    $visitorBase = rtrim($publicBase, '/');
  } else {
    // Auto-detect: if admin is at /admin, public is at /public (or same folder docroot).
    $visitorBase = preg_replace('~/admin$~', '', $baseUrl);
    // Dev convenience: if admin server is on a different port, map 8010 -> 8008.
    $u = parse_url($baseUrl);
    if (($u['host'] ?? '') && (string)($u['host'] ?? '') === '127.0.0.1' && (int)($u['port'] ?? 0) === 8010) {
      $visitorBase = 'http://127.0.0.1:8008';
    }
  }
  $shareLink = rtrim($visitorBase, '/') . '/index.php?p=' . urlencode((string)$proj['token']);

  $tab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'documents';
  if (!in_array($tab, ['documents', 'settings', 'signed'], true)) {
    $tab = 'documents';
  }
  $tabDocumentsSel = $tab === 'documents' ? 'true' : 'false';
  $tabSettingsSel = $tab === 'settings' ? 'true' : 'false';
  $tabSignedSel = $tab === 'signed' ? 'true' : 'false';
  $panelDocumentsHidden = $tab === 'documents' ? '' : ' hidden';
  $panelSettingsHidden = $tab === 'settings' ? '' : ' hidden';
  $panelSignedHidden = $tab === 'signed' ? '' : ' hidden';

  adminHeader('Project');
  echo '<div class="gds-page-header">';
  echo '<div><h2 class="gds-page-title">' . Util::h((string)$proj['name']) . '</h2><p class="gds-lead" style="margin-bottom:0">Project ID ' . (int)$proj['id'] . '</p></div>';
  echo '<a href="index.php" class="gds-link-back">← Back to dashboard</a>';
  echo '</div>';

  $allowDownloads = ((int)($proj['allow_downloads'] ?? 1)) === 1;
  $wmEnabled = ((int)($proj['watermark_enabled'] ?? 0)) === 1;
  $wmName = (string)($proj['watermark_image_name'] ?? '');
  $wmPath = (string)($proj['watermark_image_path'] ?? '');
  $welcomeEnabled = ((int)($proj['welcome_enabled'] ?? 0)) === 1;
  $welcomeMessage = (string)($proj['welcome_message'] ?? '');

  echo '<div class="card" style="padding:0;overflow:hidden;margin-bottom:var(--gds-space-4)">';
  echo '<div class="gds-share-banner">';
  echo '<div class="gds-section-title">Visitor link</div>';
  echo '<p class="gds-share-banner__hint">Share this address with people who should access the project. They complete the NDA before viewing files.</p>';
  echo '<div class="gds-field" style="margin-bottom:0">';
  echo '<label class="gds-label" for="shareLinkInput">Public URL</label>';
  echo '<div class="gds-share-row">';
  echo '<input type="text" readonly id="shareLinkInput" class="gds-share-input" value="' . Util::h($shareLink) . '" onclick="this.select()" spellcheck="false" autocomplete="off" />';
  echo '<button type="button" class="btn btn-secondary" id="copyShareBtn">Copy</button>';
  echo '</div>';
  echo '<p id="shareStatus" class="gds-share-status muted"></p>';
  echo '</div>';
  echo '</div>';
  echo '<div class="gds-admin-tabbar" role="tablist" aria-label="Project sections">';
  echo '<button type="button" class="gds-admin-tab" role="tab" id="tabBtn-documents" aria-controls="panel-documents" aria-selected="' . $tabDocumentsSel . '" data-tab="documents">Documents</button>';
  echo '<button type="button" class="gds-admin-tab" role="tab" id="tabBtn-settings" aria-controls="panel-settings" aria-selected="' . $tabSettingsSel . '" data-tab="settings">Settings</button>';
  echo '<button type="button" class="gds-admin-tab" role="tab" id="tabBtn-signed" aria-controls="panel-signed" aria-selected="' . $tabSignedSel . '" data-tab="signed">Signed users</button>';
  echo '</div>';

  echo '<div id="panel-documents" class="gds-admin-tab-panel" role="tabpanel" aria-labelledby="tabBtn-documents"' . $panelDocumentsHidden . '>';
  $nda = $projects->getNda((int)$proj['id']);
  echo '<div style="margin-bottom:var(--gds-space-3)">';
  echo '<div class="gds-section-title" style="margin-bottom:var(--gds-space-2)">NDA template</div>';
  if ($nda) {
    echo '<div class="ok gds-flash"><strong>Uploaded:</strong> ' . Util::h((string)$nda['original_name']) . '</div>';
    echo '<div class="subCard" style="margin-bottom:var(--gds-space-3);display:flex;justify-content:space-between;align-items:center;gap:var(--gds-space-3);flex-wrap:wrap">';
    echo '<div style="min-width:220px">';
    echo '<div style="font-weight:600;font-size:var(--gds-text-sm);color:var(--gds-text)">NDA fields</div>';
    echo '<div class="muted" style="margin-top:2px;font-size:var(--gds-text-xs)">Place signature, date, name, position, and free-text boxes.</div>';
    echo '</div>';
    echo '<a class="btn btn-primary" href="index.php?view=nda-fields&project_id=' . (int)$proj['id'] . '">Edit fields</a>';
    echo '</div>';
  } else {
    echo '<div class="err gds-flash"><strong>No NDA uploaded yet.</strong></div>';
  }
  echo '<form method="post" enctype="multipart/form-data" data-auto-upload="1">';
  echo '<input type="hidden" name="action" value="upload_nda" />';
  echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
  echo '<div class="dropzone" data-dz="nda">';
  echo '  <div class="dzText">';
  echo '    <div class="dzTitle">Drop NDA PDF here</div>';
  echo '    <div class="muted dzSub">Or choose a file. Upload starts immediately.</div>';
  echo '  </div>';
  echo '  <div class="dzActions">';
  echo '    <label class="dzBtn"><input type="file" name="nda_pdf" accept="application/pdf" required style="display:none" />Choose file</label>';
  echo '  </div>';
  echo '</div>';
  echo '</form>';
  echo '</div>';

  echo '<hr class="gds-divider" />';
  echo '<div class="gds-section-title" style="margin-bottom:var(--gds-space-2)">Project files</div>';
  echo '<form method="post" enctype="multipart/form-data" data-auto-upload="1">';
  echo '<input type="hidden" name="action" value="upload_file" />';
  echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
  echo '<div class="dropzone" data-dz="file">';
  echo '  <div class="dzText">';
  echo '    <div class="dzTitle">Drop files here</div>';
  echo '    <div class="muted dzSub">Upload starts immediately. PDFs and spreadsheets can be viewed in-app.</div>';
  echo '  </div>';
  echo '  <div class="dzActions">';
  echo '    <label class="dzBtn"><input type="file" name="project_file" required style="display:none" />Choose file</label>';
  echo '  </div>';
  echo '</div>';
  echo '</form>';

  $previewSecret = (string)($config['app_secret'] ?? '');
  $files = $projects->listFiles((int)$proj['id']);
  if ($files) {
    echo '<div class="gds-file-order-toolbar" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:var(--gds-space-2);margin-top:var(--gds-space-3)">';
    echo '<p class="muted" style="margin:0;max-width:48rem">Drag rows by the handle to set the order visitors see (top = first). Then click <strong>Save order</strong>.</p>';
    echo '<button type="button" class="btn btn-secondary" id="gdsSaveFileOrderBtn">Save order</button>';
    echo '</div>';
    echo '<form method="post" id="deleteProjectFilesForm">';
    echo '<input type="hidden" name="action" value="delete_project_files" />';
    echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
    echo '<div class="gds-table-wrap"><table><thead><tr>';
    echo '<th class="gds-th-drag" style="width:32px" title="Drag to reorder" aria-label="Reorder"></th>';
    echo '<th style="width:36px" title="Select"></th><th style="width:48px" title="Preview"></th><th>ID</th><th>Name</th><th>Size</th><th>Uploaded</th>';
    echo '</tr></thead><tbody id="gdsSortableFilesTbody">';
    foreach ($files as $f) {
      $fid = (int)$f['id'];
      $previewTok = $previewSecret !== ''
        ? Util::mintAdminFilePreviewToken((int)$proj['id'], $fid, $previewSecret)
        : '';
      $previewPageUrl = $previewTok !== ''
        ? (rtrim($visitorBase, '/') . '/viewer.php?' . http_build_query([
          'p' => (string)$proj['token'],
          'file_id' => $fid,
          'preview_token' => $previewTok,
        ]))
        : '';
      $previewLabel = 'Preview ' . (string)$f['original_name'];
      echo '<tr class="gds-sortable-file-row" draggable="true" data-file-id="' . $fid . '">';
      echo '<td class="gds-td-drag" title="Drag to reorder"><span class="gds-file-drag-icon" aria-hidden="true">⋮⋮</span></td>';
      echo '<td><input type="checkbox" name="file_ids[]" value="' . $fid . '" aria-label="Select ' . Util::h((string)$f['original_name']) . '" /></td>';
      echo '<td>';
      if ($previewPageUrl !== '') {
        echo '<button type="button" class="btn btn-secondary gds-btn--compact gds-preview-open-btn" style="padding:6px 8px;min-width:40px" title="Preview" aria-label="' . Util::h($previewLabel) . '" data-preview-url="' . Util::h($previewPageUrl) . '">';
        echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        echo '</button>';
      } else {
        echo '<span class="muted" style="display:inline-flex;padding:6px 8px" title="Set app_secret in config.php to enable preview">—</span>';
      }
      echo '</td>';
      echo '<td>' . $fid . '</td>';
      echo '<td>' . Util::h((string)$f['original_name']) . '</td>';
      echo '<td class="muted">' . (int)$f['size_bytes'] . ' bytes</td>';
      echo '<td class="muted">' . Util::h((string)$f['created_at']) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="gds-actions" style="margin-top:var(--gds-space-3)">';
    echo '<button type="submit" class="btn btn-danger gds-btn--compact" title="Delete selected" aria-label="Delete selected files" style="display:inline-flex;align-items:center;gap:6px">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></svg>';
    echo '<span>Delete</span>';
    echo '</button>';
    echo '</div>';
    echo '</form>';
    echo '<form method="post" id="gdsReorderFilesForm" style="display:none" aria-hidden="true">';
    echo '<input type="hidden" name="action" value="reorder_project_files" />';
    echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
    echo '</form>';
    echo <<<'HTML'
<script>
(function () {
  var tbody = document.getElementById('gdsSortableFilesTbody');
  var btn = document.getElementById('gdsSaveFileOrderBtn');
  var form = document.getElementById('gdsReorderFilesForm');
  if (!tbody || !btn || !form) return;
  var dragEl = null;
  tbody.querySelectorAll('tr.gds-sortable-file-row').forEach(function (tr) {
    tr.addEventListener('dragstart', function (e) {
      if (e.target && e.target.closest && e.target.closest('input, button')) {
        e.preventDefault();
        return;
      }
      dragEl = tr;
      tr.classList.add('gds-file-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', tr.getAttribute('data-file-id') || ''); } catch (err) {}
    });
    tr.addEventListener('dragend', function () {
      tr.classList.remove('gds-file-dragging');
      dragEl = null;
    });
    tr.addEventListener('dragover', function (e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (!dragEl || dragEl === tr) return;
      var rect = tr.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      tbody.insertBefore(dragEl, before ? tr : tr.nextSibling);
    });
  });
  btn.addEventListener('click', function () {
    form.querySelectorAll('input[name="file_order[]"]').forEach(function (n) { n.remove(); });
    tbody.querySelectorAll('tr[data-file-id]').forEach(function (row) {
      var id = row.getAttribute('data-file-id');
      if (!id) return;
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'file_order[]';
      inp.value = id;
      form.appendChild(inp);
    });
    form.submit();
  });
})();
</script>
HTML;
  } else {
    echo '<p class="gds-help" style="margin-top:var(--gds-space-3);margin-bottom:0">No files uploaded yet.</p>';
  }
  echo '</div>';

  echo '<div id="panel-settings" class="gds-admin-tab-panel" role="tabpanel" aria-labelledby="tabBtn-settings"' . $panelSettingsHidden . '>';
  echo '<form method="post">';
  echo '<input type="hidden" name="action" value="update_project_settings" />';
  echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
  echo '<div style="display:flex;flex-direction:column;gap:10px">';
  echo '<div class="toggleRow">';
  echo '<div class="label"><strong>Allow downloads</strong><div class="muted">Project files only. PDFs can still be viewed in-app. Signers can always download their signed NDA.</div></div>';
  echo '<label class="toggle" aria-label="Allow downloads"><input type="checkbox" name="allow_downloads" value="1"' . ($allowDownloads ? ' checked' : '') . ' /><span class="switch" aria-hidden="true"></span></label>';
  echo '</div>';
  echo '<div class="toggleRow">';
  echo '<div class="label"><strong>Watermark</strong><div class="muted">Shows watermark + viewer IP in viewer and PDF downloads.</div></div>';
  echo '<label class="toggle" aria-label="Enable watermark"><input type="checkbox" name="watermark_enabled" value="1"' . ($wmEnabled ? ' checked' : '') . ' /><span class="switch" aria-hidden="true"></span></label>';
  echo '</div>';
  echo '<div class="toggleRow">';
  echo '<div class="label"><strong>Welcome message</strong><div class="muted">Shown first on the visitor link, with a single “Continue” button.</div></div>';
  echo '<label class="toggle" aria-label="Enable welcome message"><input type="checkbox" name="welcome_enabled" value="1"' . ($welcomeEnabled ? ' checked' : '') . ' /><span class="switch" aria-hidden="true"></span></label>';
  echo '</div>';
  echo '</div>';
  echo '<div class="gds-field" style="margin-top:var(--gds-space-3)">';
  echo '<label class="gds-label" for="welcome_message">Welcome message content</label>';
  echo '<textarea id="welcome_message" name="welcome_message" rows="4" placeholder="Welcome! Please read this quick note before continuing…">' . Util::h($welcomeMessage) . '</textarea>';
  echo '</div>';
  echo '<div class="gds-actions" style="margin-top:var(--gds-space-3)"><button type="submit" class="btn btn-primary">Save settings</button></div>';
  echo '</form>';

  echo '<hr class="gds-divider" />';
  echo '<div class="cardTitle" style="margin-bottom:var(--gds-space-3)"><h3>Watermark image</h3></div>';
  if ($wmPath !== '' && is_file($wmPath)) {
    echo '<div class="ok" style="margin-bottom:10px"><strong>Uploaded:</strong> ' . Util::h($wmName !== '' ? $wmName : basename($wmPath)) . '</div>';
  } else {
    echo '<div class="muted" style="margin-bottom:10px">No watermark uploaded yet.</div>';
  }
  echo '<form method="post" enctype="multipart/form-data" data-auto-upload="1">';
  echo '<input type="hidden" name="action" value="upload_watermark" />';
  echo '<input type="hidden" name="project_id" value="' . (int)$proj['id'] . '" />';
  echo '<div class="dropzone" data-dz="wm">';
  echo '  <div class="dzText"><div class="dzTitle">Drop watermark image here</div><div class="muted dzSub">PNG, JPG, or WebP. Upload starts immediately.</div></div>';
  echo '  <div class="dzActions"><label class="dzBtn"><input type="file" name="watermark_image" accept="image/png,image/jpeg,image/webp" required style="display:none" />Choose file</label></div>';
  echo '</div>';
  echo '</form>';
  echo '</div>';

  echo '<div id="panel-signed" class="gds-admin-tab-panel" role="tabpanel" aria-labelledby="tabBtn-signed"' . $panelSignedHidden . '>';
  $sigs = $ndaSigning->listSignaturesForProject((int)$proj['id']);
  if ($sigs) {
    echo '<div class="gds-table-wrap"><table><thead><tr><th>Email</th><th>Name</th><th>Position</th><th>Address</th><th>Signed at</th><th>Signed NDA</th></tr></thead><tbody>';
    foreach ($sigs as $s) {
      $pdfPath = isset($s['signed_pdf_path']) ? (string)$s['signed_pdf_path'] : '';
      $hasPdf = $pdfPath !== '' && is_file($pdfPath);
      $dl = 'download-signed-nda.php?project_id=' . (int)$proj['id'] . '&signature_id=' . (int)$s['id'];
      echo '<tr>';
      echo '<td>' . Util::h((string)$s['signer_email']) . '</td>';
      echo '<td>' . Util::h((string)$s['signer_name']) . '</td>';
      echo '<td>' . Util::h((string)$s['signer_position']) . '</td>';
      echo '<td class="muted">' . Util::h((string)($s['signer_address'] ?? '')) . '</td>';
      echo '<td class="muted">' . Util::h((string)$s['signed_at']) . '</td>';
      if ($hasPdf) {
        echo '<td><a href="' . Util::h($dl) . '">Download PDF</a></td>';
      } else {
        echo '<td class="muted">Not stored</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  } else {
    echo '<p class="gds-lead" style="margin-bottom:0">No signatures yet.</p>';
  }
  echo '</div>';

  echo '</div>';

  echo '<div id="gdsPreviewLightbox" class="gds-lightbox" role="dialog" aria-modal="true" aria-label="File preview" hidden>';
  echo '<div class="gds-lightbox-backdrop" id="gdsLightboxBackdrop"></div>';
  echo '<div class="gds-lightbox-modal">';
  echo '<button type="button" class="gds-lightbox-close" id="gdsLightboxClose" aria-label="Close preview">&times;</button>';
  echo '<iframe id="gdsLightboxFrame" src="about:blank" title="File preview" allowfullscreen></iframe>';
  echo '</div>';
  echo '</div>';

  echo '<script>
(() => {
  const copyBtn = document.getElementById("copyShareBtn");
  const shareInput = document.getElementById("shareLinkInput");
  const shareStatus = document.getElementById("shareStatus");
  if (copyBtn && shareInput) {
    copyBtn.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(shareInput.value || "");
        shareStatus.textContent = "Copied.";
        setTimeout(() => { shareStatus.textContent = ""; }, 1200);
      } catch (e) {
        shareInput.focus();
        shareInput.select();
        shareStatus.textContent = "Select and copy manually.";
      }
    });
  }

  (() => {
    const lightbox = document.getElementById("gdsPreviewLightbox");
    const frame    = document.getElementById("gdsLightboxFrame");
    const closeBtn = document.getElementById("gdsLightboxClose");
    const backdrop = document.getElementById("gdsLightboxBackdrop");
    if (!lightbox || !frame || !closeBtn || !backdrop) return;

    function openLightbox(url) {
      frame.src = url;
      lightbox.hidden = false;
      requestAnimationFrame(() => lightbox.classList.add("gds-lightbox--open"));
      document.body.style.overflow = "hidden";
      closeBtn.focus();
    }

    function closeLightbox() {
      lightbox.classList.remove("gds-lightbox--open");
      lightbox.addEventListener("transitionend", function onEnd() {
        lightbox.removeEventListener("transitionend", onEnd);
        lightbox.hidden = true;
        frame.src = "about:blank";
        document.body.style.overflow = "";
      }, { once: true });
    }

    document.querySelectorAll(".gds-preview-open-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const u = btn.getAttribute("data-preview-url");
        if (u) openLightbox(u);
      });
    });

    closeBtn.addEventListener("click", closeLightbox);
    backdrop.addEventListener("click", closeLightbox);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !lightbox.hidden) closeLightbox();
    });
  })();

  const deleteProjectFilesForm = document.getElementById("deleteProjectFilesForm");
  if (deleteProjectFilesForm) {
    deleteProjectFilesForm.addEventListener("submit", function (e) {
      const checked = deleteProjectFilesForm.querySelectorAll("input[name=\"file_ids[]\"]:checked");
      if (checked.length === 0) {
        e.preventDefault();
        return;
      }
      if (!window.confirm("Delete the selected file(s) from this project? This cannot be undone.")) {
        e.preventDefault();
      }
    });
  }

  function activateProjectTab(name) {
    const map = { documents: "panel-documents", settings: "panel-settings", signed: "panel-signed" };
    const sel = map[name] ? name : "documents";
    document.querySelectorAll(".gds-admin-tabbar [role=tab]").forEach((btn) => {
      btn.setAttribute("aria-selected", btn.dataset.tab === sel ? "true" : "false");
    });
    Object.keys(map).forEach((k) => {
      const el = document.getElementById(map[k]);
      if (el) el.hidden = k !== sel;
    });
    try {
      const u = new URL(window.location.href);
      u.searchParams.set("tab", sel);
      window.history.replaceState(null, "", u.toString());
    } catch (e) {}
  }
  document.querySelectorAll(".gds-admin-tabbar [role=tab]").forEach((btn) => {
    btn.addEventListener("click", () => activateProjectTab(btn.dataset.tab || "documents"));
  });

  function wireDropzone(dz) {
    const form = dz.closest("form");
    if (!form) return;
    const input = dz.querySelector("input[type=file]");
    if (!input) return;

    function submitNow() {
      if (!input.files || input.files.length === 0) return;
      dz.classList.add("isBusy");
      try { form.submit(); } catch (e) { dz.classList.remove("isBusy"); }
    }

    input.addEventListener("change", submitNow);
    dz.addEventListener("dragenter", (e) => { e.preventDefault(); dz.classList.add("isOver"); });
    dz.addEventListener("dragover", (e) => { e.preventDefault(); dz.classList.add("isOver"); });
    dz.addEventListener("dragleave", (e) => {
      if (e.target === dz) dz.classList.remove("isOver");
    });
    dz.addEventListener("drop", (e) => {
      e.preventDefault();
      dz.classList.remove("isOver");
      const files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
      if (!files || files.length === 0) return;
      const dt = new DataTransfer();
      dt.items.add(files[0]);
      input.files = dt.files;
      submitNow();
    });
  }
  document.querySelectorAll(".dropzone").forEach(wireDropzone);
})();
  </script>';
  adminFooter();
  exit;
}

// Dashboard
adminHeader('Dashboard');
echo '<div class="card">';
echo '<h2 class="gds-page-title">Projects</h2>';
echo '<p class="gds-lead">Create a project for each deal or workspace, then upload an NDA and files for signers.</p>';

echo '<form method="post">';
echo '<input type="hidden" name="action" value="create_project" />';
echo '<div class="row" style="align-items:flex-end">';
echo '<div class="gds-field" style="margin-bottom:0;flex:2;min-width:200px"><label class="gds-label" for="new_project_name">New project</label>';
echo '<input id="new_project_name" name="name" type="text" placeholder="e.g. Acme — Q1 NDA" required /></div>';
echo '<div class="gds-filter-actions"><button type="submit" class="btn btn-primary">Create project</button></div>';
echo '</div>';
echo '</form>';

$list = $projects->listProjects();
if ($list) {
  echo '<div class="gds-table-wrap" style="margin-top:var(--gds-space-5)"><table><thead><tr><th>ID</th><th>Name</th><th>Token</th><th></th></tr></thead><tbody>';
  foreach ($list as $p) {
    echo '<tr>';
    echo '<td>' . (int)$p['id'] . '</td>';
    echo '<td>' . Util::h((string)$p['name']) . '</td>';
    echo '<td class="muted">' . Util::h((string)$p['token']) . '</td>';
    echo '<td><a href="index.php?view=project&project_id=' . (int)$p['id'] . '">Manage</a></td>';
    echo '</tr>';
  }
  echo '</tbody></table></div>';
} else {
  echo '<p class="gds-lead" style="margin-top:var(--gds-space-4);margin-bottom:0">No projects yet. Create one above to get started.</p>';
}

echo '</div>';
adminFooter();

