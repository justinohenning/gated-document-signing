<?php

require_once __DIR__ . '/_bootstrap.php';

$projectToken = isset($_GET['p']) ? (string)$_GET['p'] : '';
if ($projectToken === '') {
  renderHeader('Viewer');
  echo '<div class="card"><div class="err"><strong>Missing project link.</strong></div></div>';
  renderFooter();
  exit;
}

$project = $projects->getByToken($projectToken);
if (!$project || (int)$project['is_active'] !== 1) {
  renderHeader('Viewer');
  echo '<div class="card"><div class="err"><strong>Invalid project link.</strong></div></div>';
  renderFooter();
  exit;
}
$projectId = (int)$project['id'];
$isAdminPreview = isset($_GET['admin_preview']) && (string)$_GET['admin_preview'] === '1';
$adminPreviewAuthorized = false;
$allowDownloads = ((int)($project['allow_downloads'] ?? 1)) === 1;
$watermarkEnabled = ((int)($project['watermark_enabled'] ?? 0)) === 1;
$watermarkHasImage = ((string)($project['watermark_image_path'] ?? '')) !== '';
// Relative to viewer.php — avoids wrong host/path from Util::sameRequestScriptUrl behind proxies or in subfolders.
$watermarkUrl = 'download.php?' . http_build_query([
  'p' => $projectToken,
  'watermark' => '1',
]);
$viewerIp = Util::clientIp();

// Determine what to view
$isNda = isset($_GET['nda']);
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;

if (!$isNda && $fileId <= 0) {
  renderHeader('Viewer');
  echo '<div class="card"><div class="err"><strong>Missing file selection.</strong></div></div>';
  renderFooter();
  exit;
}

// Authorization:
// - NDA can be viewed pre-signing (needed to sign)
// - Project files require signed access
$email = Auth::visitorEmail($projectId);
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

$title = $isNda ? 'NDA' : 'Document';
$pdfUrl = '';
$downloadUrl = '';
$viewerKind = 'pdf';
$isExcelPdfPreview = false;

if ($isNda) {
  $nda = $projects->getNda($projectId);
  if (!$nda) {
    renderHeader('Viewer');
    echo '<div class="card"><div class="err"><strong>NDA not configured.</strong></div></div>';
    renderFooter();
    exit;
  }
  $title = (string)$nda['original_name'];
  $pdfUrl = 'download.php?' . http_build_query([
    'p' => $projectToken,
    'nda' => '1',
  ]);
  $downloadUrl = $pdfUrl;
} else {
  $previewTokenIn = (string)($_GET['preview_token'] ?? '');
  $secret = (string)($config['app_secret'] ?? '');
  $tokenOk = $fileId > 0 && $previewTokenIn !== '' && $secret !== ''
    && Util::verifyAdminFilePreviewToken($previewTokenIn, $secret, $projectId, $fileId);
  $sessionOk = $isAdminPreview && Auth::adminId() !== null;
  $adminPreviewAuthorized = $tokenOk || $sessionOk;

  if (!$adminPreviewAuthorized && ($email === null || !$ndaSigning->hasSigned($projectId, $email))) {
    renderHeader('Viewer');
    echo '<div class="card"><div class="err"><strong>Not authorized.</strong> Please complete the NDA first.</div></div>';
    renderFooter();
    exit;
  }
  $file = $projects->getFile($fileId);
  if (!$file || (int)$file['project_id'] !== $projectId) {
    renderHeader('Viewer');
    echo '<div class="card"><div class="err"><strong>File not found.</strong></div></div>';
    renderFooter();
    exit;
  }
  $title = (string)$file['original_name'];
  $adminQs = [];
  if ($adminPreviewAuthorized) {
    if ($previewTokenIn !== '') {
      $adminQs['preview_token'] = $previewTokenIn;
    } else {
      $adminQs['admin_preview'] = '1';
    }
  }
  $downloadUrl = 'download.php?' . http_build_query(array_merge([
    'p' => $projectToken,
    'file_id' => $fileId,
  ], $adminQs));
  $pdfUrl = 'download.php?' . http_build_query(array_merge([
    'p' => $projectToken,
    'file_id' => $fileId,
    'mode' => 'view',
  ], $adminQs));

  $previewProfile = Util::projectFilePreviewProfile($title);
  if ($previewProfile === null) {
    if ($allowDownloads) {
      header('Location: ' . $downloadUrl);
      exit;
    }
    renderHeader('Viewer');
    renderAnalyticsTracker($projectToken, 'viewer_file', $email, [
      'doc_kind' => 'project_file',
      'file_id' => $fileId,
      'file_label' => $title,
      'viewer_kind' => 'unsupported',
    ]);
    echo '<div class="card"><div class="err"><strong>Download disabled.</strong> This file type can’t be viewed in-app.</div></div>';
    renderFooter();
    exit;
  }
  $viewerKind = $previewProfile['kind'];

  // Optional: render XLSX previews as PDF for high-fidelity colors/merges/sizing.
  // Uses download.php?mode=view_pdf (Gotenberg or LibreOffice on the server). Do not gate on
  // converter detection here — without a converter, view_pdf returns 501; gating only caused
  // production hosts without local soffice to fall back to the interactive sheet viewer.
  // Rollback paths:
  // - config.php: set xlsx_preview_mode to 'sheet'
  // - per-link: add ?xlsx_preview=sheet to force the interactive sheet viewer
  if (
    $viewerKind === 'sheet'
    && (($config['xlsx_preview_mode'] ?? 'pdf') === 'pdf')
    && ((string)($_GET['xlsx_preview'] ?? '') !== 'sheet')
  ) {
    $viewerKind = 'pdf';
    $isExcelPdfPreview = true;
    $pdfUrl = 'download.php?' . http_build_query(array_merge([
      'p' => $projectToken,
      'file_id' => $fileId,
      'mode' => 'view_pdf',
    ], $adminQs));
  }
}

$viewerAnalyticsStatic = $isNda
  ? ['doc_kind' => 'nda', 'file_label' => $title, 'viewer_kind' => $viewerKind]
  : ['doc_kind' => 'project_file', 'file_id' => $fileId, 'file_label' => $title, 'viewer_kind' => $viewerKind];

renderHeader('Viewer');
renderAnalyticsTracker($projectToken, $isNda ? 'viewer_nda' : 'viewer_file', $email, $viewerAnalyticsStatic);

$t = Util::h($title);
$titleJs = Util::jsonForJs($title, '"Document"');
$pdfUrlJs = Util::jsonForJs($pdfUrl, '""');
$downloadUrlEsc = Util::h($downloadUrl);
$backUrl = 'index.php?' . http_build_query(['p' => $projectToken]);
$backUrlEsc = Util::h($backUrl);
$backControlHtml = (!$isNda && $adminPreviewAuthorized)
  ? '<button type="button" class="muted" style="white-space:nowrap;font-weight:500;border:0;background:transparent;cursor:pointer;font:inherit;color:inherit;padding:0" onclick="window.close()">Close</button>'
  : '<a href="' . $backUrlEsc . '" class="muted" style="white-space:nowrap;font-weight:500">← Back</a>';
$downloadLinkHtml = $allowDownloads ? ('<a href="' . $downloadUrlEsc . '" class="muted" style="white-space:nowrap" target="gds_download_frame" rel="noopener">Download</a>') : ('<span class="muted" style="white-space:nowrap">Download disabled</span>');
$wmEnabledJs = $watermarkEnabled && $watermarkHasImage ? 'true' : 'false';
$wmUrlJs = Util::jsonForJs($watermarkUrl, '""');
$ipJs = Util::jsonForJs($viewerIp, '""');
$viewerKindJs = Util::jsonForJs($viewerKind, '"pdf"');
$pdfToolbarExtrasDisplay = $viewerKind === 'pdf' ? 'flex' : 'none';
$sheetToolbarExtrasDisplay = 'none';
$htmlPdfAreaDisplay = $viewerKind === 'pdf' ? 'flex' : 'none';
$htmlSheetAreaDisplay = $viewerKind === 'sheet' ? 'block' : 'none';
$htmlImageAreaDisplay = $viewerKind === 'image' ? 'flex' : 'none';
$htmlDocxAreaDisplay = $viewerKind === 'docx' ? 'block' : 'none';
$htmlTextAreaDisplay = $viewerKind === 'text' ? 'block' : 'none';
$pdfScriptTag = $viewerKind === 'pdf'
  ? '<script src="assets/vendor/pdf.min.js"></script>'
  : '';
$sheetScriptTag = $viewerKind === 'sheet'
  ? '<link rel="stylesheet" href="assets/vendor/xspreadsheet.css" />'
    . '<script src="assets/vendor/jszip.min.js"></script>'
    . '<script src="assets/vendor/xlsx.full.min.js"></script>'
    . '<script src="assets/vendor/xspreadsheet.js"></script>'
  : '';
$docxScriptTag = $viewerKind === 'docx'
  ? '<script src="assets/vendor/jszip.min.js"></script><script src="assets/vendor/docx-preview.min.js"></script>'
  : '';
$pdfPad = $isExcelPdfPreview ? '100px' : '18px 26px';

echo <<<HTML
<div class="card gds-viewer-card" style="padding:0;overflow:hidden">
  <div class="gds-viewer-bar">
    <div class="gds-viewer-bar-inner">
      {$backControlHtml}
      <div class="gds-viewer-title">{$t}</div>
    </div>
    <div class="gds-viewer-actions">
      <div id="pdfToolbarExtras" style="display:{$pdfToolbarExtrasDisplay};align-items:center;gap:8px;flex-wrap:wrap">
        <button type="button" id="zoomOutBtn" title="Zoom out">−</button>
        <div class="muted" style="min-width:70px;text-align:center" id="zoomLabel">100%</div>
        <button type="button" id="zoomInBtn" title="Zoom in">+</button>
        <div style="width:1px;height:26px;background:var(--border);margin:0 6px"></div>
        <button type="button" id="prevBtn">Prev</button>
        <div class="muted"><span id="pageNum">1</span> / <span id="pageCount">1</span></div>
        <button type="button" id="nextBtn">Next</button>
        <div style="width:1px;height:26px;background:var(--border);margin:0 6px"></div>
      </div>
      <div id="sheetToolbarExtras" style="display:{$sheetToolbarExtrasDisplay};align-items:center;gap:8px;flex-wrap:wrap"></div>
      {$downloadLinkHtml}
    </div>
  </div>

  <div id="pdfArea" class="gds-viewer-pane" style="display:{$htmlPdfAreaDisplay};justify-content:flex-start;overflow:auto;max-height:min(70vh,900px);cursor:grab">
    <div id="pdfCanvasWrap" style="padding:{$pdfPad};min-width:fit-content;min-height:fit-content;margin:0 auto">
      <canvas id="pdfCanvas" style="display:block;background:var(--gds-surface);border:1px solid var(--gds-border);border-radius:var(--gds-radius-lg);box-shadow:var(--gds-shadow-card)"></canvas>
    </div>
  </div>
  <div id="sheetArea" class="gds-viewer-pane" style="display:{$htmlSheetAreaDisplay}">
    <div id="gdsXsHost" style="height:min(70vh,900px);overflow:hidden;background:var(--gds-surface);border:1px solid var(--gds-border);border-radius:var(--gds-radius-lg);box-shadow:var(--gds-shadow-card)"></div>
  </div>
  <div id="imageArea" class="gds-viewer-pane" style="display:{$htmlImageAreaDisplay};justify-content:center">
    <img id="previewImage" alt="" style="max-width:100%;height:auto;background:var(--gds-surface);border:1px solid var(--gds-border);border-radius:var(--gds-radius-lg);box-shadow:var(--gds-shadow-card)" />
  </div>
  <div id="docxArea" class="gds-viewer-pane" style="display:{$htmlDocxAreaDisplay}">
    <div id="docxHost" class="gds-docx-host" style="max-height:min(70vh,900px);overflow:auto;background:var(--gds-surface);border:1px solid var(--gds-border);border-radius:var(--gds-radius-lg);box-shadow:var(--gds-shadow-card);padding:18px 22px"></div>
  </div>
  <div id="textArea" class="gds-viewer-pane" style="display:{$htmlTextAreaDisplay}">
    <pre id="textHost" style="max-height:min(70vh,900px);overflow:auto;margin:0;background:var(--gds-surface);border:1px solid var(--gds-border);border-radius:var(--gds-radius-lg);box-shadow:var(--gds-shadow-card);padding:14px 16px;font-size:var(--gds-text-sm);line-height:1.45;white-space:pre-wrap;word-break:break-word;color:var(--gds-text)"></pre>
  </div>
</div>
<iframe name="gds_download_frame" title="Download" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0);visibility:hidden" aria-hidden="true"></iframe>

{$pdfScriptTag}
{$sheetScriptTag}
{$docxScriptTag}
<script>
(() => {
  const VIEWER_KIND = {$viewerKindJs};
  const url = {$pdfUrlJs};
  const DOC_TITLE = {$titleJs};
  const watermarkEnabled = {$wmEnabledJs};
  const watermarkUrl = {$wmUrlJs};
  const viewerIp = {$ipJs};

  const fetchOpts = { credentials: "same-origin", cache: "no-store" };
  const workerSrc = new URL("assets/vendor/pdf.worker.min.js", document.baseURI).href;

  if (VIEWER_KIND === "image") {
    const img = document.getElementById("previewImage");
    const pane = document.getElementById("imageArea");
    if (img) {
      img.alt = DOC_TITLE || "Preview";
      (async () => {
        try {
          const resp = await fetch(url, fetchOpts);
          if (!resp.ok) throw new Error("HTTP " + resp.status);
          const blob = await resp.blob();
          const mime = (blob.type || "").toLowerCase();
          const name = (DOC_TITLE || "").toLowerCase();
          if (mime.indexOf("heic") >= 0 || mime.indexOf("heif") >= 0 || /\.hei[cf]$/.test(name)) {
            throw new Error("HEIC/HEIF preview is not supported in this browser. Use Download or convert to JPEG/PNG.");
          }
          const o = URL.createObjectURL(blob);
          img.onload = () => { try { URL.revokeObjectURL(o); } catch (e) {} };
          img.onerror = () => {
            try { URL.revokeObjectURL(o); } catch (e) {}
            img.alt = "Preview failed";
            if (pane) {
              pane.insertAdjacentHTML("beforeend", "<p class=\"gds-sheet-note\" style=\"margin-top:12px\">Could not load image preview. Try Download or open the file in a new tab.</p>");
            }
          };
          img.src = o;
        } catch (e) {
          console.error(e);
          if (pane) {
            pane.insertAdjacentHTML("beforeend", "<p class=\"gds-sheet-note\">Could not load image preview (" + (e && e.message ? e.message : "error") + ").</p>");
          }
        }
      })();
    }
    return;
  }

  if (VIEWER_KIND === "text") {
    const pre = document.getElementById("textHost");
    (async () => {
      try {
        const resp = await fetch(url, fetchOpts);
        if (!resp.ok) throw new Error("HTTP " + resp.status);
        const t = await resp.text();
        const max = 1500000;
        if (pre) {
          pre.textContent = t.length > max
            ? t.slice(0, max) + "\\n\\n\u2026 Preview truncated (" + String(t.length) + " characters total)."
            : t;
        }
      } catch (e) {
        console.error(e);
        if (pre) pre.textContent = "Could not load file for preview.";
      }
    })();
    return;
  }

  if (VIEWER_KIND === "docx") {
    const host = document.getElementById("docxHost");
    (async () => {
      try {
        if (typeof docx === "undefined" || typeof docx.renderAsync !== "function") {
          throw new Error("Word preview library failed to load (docx-preview).");
        }
        if (typeof JSZip === "undefined") {
          throw new Error("JSZip failed to load.");
        }
        const resp = await fetch(url, fetchOpts);
        if (!resp.ok) throw new Error("Could not download file (HTTP " + resp.status + ").");
        const blob = await resp.blob();
        if (host) host.innerHTML = "";
        await docx.renderAsync(blob, host, null, {
          className: "gds-docx",
          inWrapper: true,
          ignoreWidth: false,
          ignoreHeight: false,
          ignoreFonts: false,
          breakPages: true,
          renderHeaders: true,
          renderFooters: true,
          renderFootnotes: true,
          renderEndnotes: true,
          renderAltChunks: false,
        });
      } catch (e) {
        console.error(e);
        if (host) {
          const msg = (e && e.message) ? String(e.message) : "Could not preview this document.";
          host.innerHTML = "<p class=\"gds-sheet-note\">" + msg.replace(/</g, "&lt;").replace(/>/g, "&gt;") + " You can try Download if enabled.</p>";
        }
      }
    })();
    return;
  }

  if (VIEWER_KIND === "sheet") {
    const host = document.getElementById("gdsXsHost");
    function sheetFatal(msg) {
      if (host) {
        host.innerHTML = "<p class=\"gds-sheet-note\" style=\"padding:16px;max-width:42rem;margin:0 auto\">" + String(msg).replace(/</g, "&lt;").replace(/>/g, "&gt;") + "</p>";
      }
    }

    // OOXML DrawingML clrScheme order (Office default): dk1, lt1, dk2, lt2, accent1–6, hlink, folHlink
    const OOXML_THEME = [
      "#000000", "#FFFFFF", "#44546A", "#E7E6E6", "#4472C4", "#ED7D31", "#A5A5A5", "#FFC000",
      "#5B9BD5", "#70AD47", "#0563C1", "#954F72"
    ];
    const INDEXED_HEX = [
      "000000", "FFFFFF", "FF0000", "00FF00", "0000FF", "FFFF00", "FF00FF", "00FFFF",
      "000000", "FFFFFF", "FF0000", "00FF00", "0000FF", "FFFF00", "FF00FF", "00FFFF",
      "800000", "008000", "000080", "808000", "800080", "008080", "C0C0C0", "808080",
      "9999FF", "993366", "FFFFCC", "CCFFFF", "660066", "FF8080", "0066CC", "CCCCFF",
      "000080", "FF00FF", "FFFF00", "00FFFF", "800080", "800000", "008080", "0000FF",
      "00CCFF", "CCFFFF", "CCFFCC", "FFFF99", "99CCFF", "FF99CC", "CC99FF", "FFCC99",
      "3366FF", "33CCCC", "99CC00", "FFCC00", "FF9900", "FF6600", "666699", "969696",
      "003366", "339966", "003300", "333300", "993300", "993366", "333333", "333333"
    ];
    let fileThemePalette = null;

    function parseClrSchemeColors(themeXml) {
      if (!themeXml || typeof themeXml !== "string") return null;
      const m = themeXml.match(/<a:clrScheme[^>]*>([\\s\\S]*?)<\\/a:clrScheme>/i);
      if (!m) return null;
      const inner = m[1];
      const out = [];
      const re = /<a:srgbClr[^>]*val=\"([0-9A-Fa-f]{6,8})\"|<a:sysClr[^>]*lastClr=\"([0-9A-Fa-f]{6})\"/gi;
      let mm;
      while ((mm = re.exec(inner)) !== null) {
        const raw = mm[1] || mm[2];
        if (!raw) continue;
        const h = raw.length === 8 ? raw.slice(2) : raw;
        if (h.length === 6) out.push("#" + h.toUpperCase());
      }
      return out.length ? out : null;
    }

    async function extractThemePaletteFromXlsx(ab) {
      try {
        if (typeof JSZip === "undefined") return null;
        const zip = await JSZip.loadAsync(ab);
        let f = zip.file("xl/theme/theme1.xml");
        if (!f) {
          const names = Object.keys(zip.files);
          for (let i = 0; i < names.length; i++) {
            if (/^xl\\/theme\\/theme\\d+\\.xml$/i.test(names[i])) {
              f = zip.file(names[i]);
              break;
            }
          }
        }
        if (!f) return null;
        const xml = await f.async("string");
        return parseClrSchemeColors(xml);
      } catch (e) {
        return null;
      }
    }

    function hexToRgb(hex) {
      const h = String(hex).replace(/^#/, "");
      if (h.length !== 6) return null;
      return {
        r: parseInt(h.slice(0, 2), 16),
        g: parseInt(h.slice(2, 4), 16),
        b: parseInt(h.slice(4, 6), 16)
      };
    }
    function rgbToHex(r, g, b) {
      function cl(x) { return Math.max(0, Math.min(255, Math.round(x))); }
      function p2(x) { const s = cl(x).toString(16); return s.length > 1 ? s : "0" + s; }
      return ("#" + p2(r) + p2(g) + p2(b)).toUpperCase();
    }
    function applyTintToHex(hex, tint) {
      if (tint == null || tint === 0 || Number(tint) === 0) return hex;
      const t = Number(tint);
      const rgb = hexToRgb(hex);
      if (!rgb) return hex;
      if (t < 0) return rgbToHex(rgb.r * (1 + t), rgb.g * (1 + t), rgb.b * (1 + t));
      return rgbToHex(
        rgb.r + (255 - rgb.r) * t,
        rgb.g + (255 - rgb.g) * t,
        rgb.b + (255 - rgb.b) * t
      );
    }

    function excelColorToCss(c) {
      if (!c || typeof c !== "object") return null;
      if (c.auto != null && (c.auto === 1 || c.auto === true || c.auto === "1")) {
        if (c.rgb == null && c.theme == null && (c.indexed == null || c.indexed === "")) return null;
      }
      if (c.indexed != null && c.indexed !== "") {
        const ix = parseInt(String(c.indexed), 10);
        if (ix >= 0 && ix < INDEXED_HEX.length) return "#" + INDEXED_HEX[ix].toUpperCase();
      }
      if (c.rgb != null && String(c.rgb) !== "") {
        let h = String(c.rgb).replace(/^#/, "").toUpperCase();
        if (h.length === 8) h = h.slice(2);
        if (h.length === 6) return applyTintToHex("#" + h, c.tint != null ? Number(c.tint) : 0);
      }
      if (c.theme != null && c.theme !== "") {
        const idx = parseInt(String(c.theme), 10);
        if (!isNaN(idx)) {
          let base = null;
          if (fileThemePalette && fileThemePalette.length && idx >= 0 && idx < fileThemePalette.length) base = fileThemePalette[idx];
          if (!base) {
            const mod = OOXML_THEME.length;
            base = OOXML_THEME[((idx % mod) + mod) % mod] || "#000000";
          }
          return applyTintToHex(base, c.tint != null ? Number(c.tint) : 0);
        }
      }
      return null;
    }

    function styleKey(s) {
      if (!s || typeof s !== "object") return "";
      const f = s.font || {};
      const fill = s.fill || {};
      const fg = fill.fgColor || {};
      const al = s.alignment || {};
      const b = s.border || {};
      return JSON.stringify({
        fn: f.name || "", fs: f.sz || "", fb: !!f.bold, fi: !!f.italic, fu: !!f.underline, fx: !!f.strike,
        fc: (f.color && (f.color.rgb || f.color.theme || f.color.indexed || "")) || "",
        bg: (fg && (fg.rgb || fg.theme || fg.indexed || "")) || "",
        ha: al.horizontal || "", va: al.vertical || "", wt: !!al.wrapText,
        bt: b.top || null, br: b.right || null, bb: b.bottom || null, bl: b.left || null,
      });
    }

    function toXsBorder(edge) {
      if (!edge || !edge.style || String(edge.style).toLowerCase() === "none") return null;
      const c = excelColorToCss(edge.color) || "#D1D5DB";
      return ["thin", c];
    }

    function styleFromWorkbook(cell, wb) {
      if (!cell || !wb) return null;
      var s = cell.s;
      if (s && typeof s === "object" && !Array.isArray(s)) return s;
      if (s == null || (typeof s !== "number" && typeof s !== "string")) return null;
      var st = wb.Styles || wb.styles;
      if (!st) return null;
      var xfs = st.cellXfs || st.CellXfs;
      if (!xfs) return null;
      var xi = parseInt(String(s), 10);
      if (isNaN(xi) || xfs[xi] == null) return null;
      var xf = xfs[xi];
      var o = {};
      var fills = st.Fills || st.fills;
      var fonts = st.Fonts || st.fonts;
      var borders = st.Borders || st.borders;
      if (fills && xf.fillId != null && fills[xf.fillId]) o.fill = fills[xf.fillId];
      if (fonts && xf.fontId != null && fonts[xf.fontId]) o.font = fonts[xf.fontId];
      if (borders && xf.borderId != null && borders[xf.borderId]) o.border = borders[xf.borderId];
      if (xf.alignment) o.alignment = xf.alignment;
      return Object.keys(o).length ? o : null;
    }

    function toXsStyle(cellStyle) {
      if (!cellStyle || typeof cellStyle !== "object") return null;
      const out = {};
      const f = cellStyle.font || {};
      const fill = cellStyle.fill || {};
      const fg = fill.fgColor || {};
      const bg = fill.bgColor || {};
      const al = cellStyle.alignment || {};
      const b = cellStyle.border || {};

      const bgcolor = excelColorToCss(fg) || excelColorToCss(bg);
      if (bgcolor) out.bgcolor = bgcolor;
      const color = excelColorToCss(f.color);
      if (color) out.color = color;

      if (f.name) out.font = Object.assign({}, out.font || {}, { name: String(f.name) });
      if (f.sz != null && f.sz !== "") out.font = Object.assign({}, out.font || {}, { size: Number(String(f.sz).replace(/pt$/i, "")) || 10 });
      if (f.bold) out.font = Object.assign({}, out.font || {}, { bold: true });
      if (f.italic) out.font = Object.assign({}, out.font || {}, { italic: true });

      if (f.underline) out.underline = true;
      if (f.strike) out.strike = true;

      if (al.horizontal) out.align = String(al.horizontal).toLowerCase();
      if (al.vertical) out.valign = String(al.vertical).toLowerCase();
      if (al.wrapText) out.textwrap = true;

      const top = toXsBorder(b.top);
      const right = toXsBorder(b.right);
      const bottom = toXsBorder(b.bottom);
      const left = toXsBorder(b.left);
      if (top || right || bottom || left) {
        out.border = {};
        if (top) out.border.top = top;
        if (right) out.border.right = right;
        if (bottom) out.border.bottom = bottom;
        if (left) out.border.left = left;
      }
      return Object.keys(out).length ? out : null;
    }

    function cellText(cell) {
      if (!cell) return "";
      if (cell.w != null && String(cell.w) !== "") return String(cell.w);
      const v = cell.v;
      if (v == null) return "";
      if (cell.t === "z") return "";
      if (cell.t === "n" && cell.z != null && typeof XLSX.SSF !== "undefined" && XLSX.SSF && typeof XLSX.SSF.format === "function") {
        try { return XLSX.SSF.format(cell.z, v); } catch (e) {}
      }
      if (cell.t === "d" && v instanceof Date) return v.toLocaleString();
      return String(v);
    }

    function stox(wb) {
      const out = [];
      const styles = [];
      const styleIndexByKey = new Map();
      const MAX_ROWS = 1200;
      const MAX_COLS = 220;

      const sheetNames = wb.SheetNames || [];
      for (let si = 0; si < sheetNames.length; si++) {
        const name = sheetNames[si];
        const ws = wb.Sheets[name];
        if (!ws) continue;
        const ref = ws["!ref"];
        if (!ref) {
          out.push({ name: name, styles: styles, merges: [], rows: {} });
          continue;
        }
        const range = XLSX.utils.decode_range(ref);
        const r0 = 0;
        const c0 = 0;
        const r1 = Math.min(range.e.r, MAX_ROWS - 1);
        const c1 = Math.min(range.e.c, MAX_COLS - 1);

        const rows = {};
        const cols = {};
        const rowHeights = ws["!rows"] || [];
        for (let rr = r0; rr <= r1; rr++) {
          const rm = rowHeights[rr];
          const hpx = rm && rm.hpx != null ? Number(rm.hpx) : (rm && rm.hpt != null ? Number(rm.hpt) * (96 / 72) : null);
          if (hpx && isFinite(hpx) && hpx > 0) {
            rows[rr] = Object.assign({}, rows[rr] || {}, { height: Math.round(hpx) });
          }
        }
        const colWidths = ws["!cols"] || [];
        for (let cc = c0; cc <= c1; cc++) {
          const cm = colWidths[cc];
          let wpx = null;
          if (cm) {
            if (cm.wpx != null) wpx = Number(cm.wpx);
            else if (cm.wch != null) wpx = Number(cm.wch) * 7 + 5;
            else if (cm.width != null) wpx = Number(cm.width) * 7 + 5;
          }
          if (wpx && isFinite(wpx) && wpx > 0) {
            cols[cc] = { width: Math.round(wpx) };
          }
        }
        const keys = Object.keys(ws).filter((k) => k[0] !== "!");
        for (let i = 0; i < keys.length; i++) {
          const k = keys[i];
          let addr;
          try { addr = XLSX.utils.decode_cell(k); } catch (e) { continue; }
          const r = addr.r, c = addr.c;
          if (r < r0 || c < c0 || r > r1 || c > c1) continue;
          const cell = ws[k];
          if (!cell) continue;
          const text = cellText(cell);
          const entry = { text: text };

          const s = styleFromWorkbook(cell, wb);
          if (s) {
            const sk = styleKey(s);
            let idx = styleIndexByKey.get(sk);
            if (idx == null) {
              const xsStyle = toXsStyle(s);
              if (xsStyle) {
                idx = styles.length;
                styles.push(xsStyle);
                styleIndexByKey.set(sk, idx);
              } else {
                styleIndexByKey.set(sk, null);
              }
            }
            if (idx != null) entry.style = idx;
          }

          const rr = rows[r] || {};
          const cells = rr.cells || {};
          cells[c] = entry;
          rows[r] = Object.assign({}, rr, { cells: cells });
        }

        const merges = [];
        const m = ws["!merges"] || [];
        for (let i = 0; i < m.length; i++) {
          const mr0 = m[i].s.r, mc0 = m[i].s.c, mr1 = m[i].e.r, mc1 = m[i].e.c;
          if (mr0 < r0 || mc0 < c0 || mr1 > r1 || mc1 > c1) continue;
          merges.push(XLSX.utils.encode_range({ s: { r: mr0, c: mc0 }, e: { r: mr1, c: mc1 } }));
        }

        out.push({ name: name, styles: styles, merges: merges, rows: rows, cols: cols });
      }
      return out;
    }

    (async () => {
      if (!host) throw new Error("Missing spreadsheet host element");
      if (typeof XLSX === "undefined" || typeof XLSX.read !== "function") {
        sheetFatal("Spreadsheet preview library failed to load (xlsx.full.min.js).");
        return;
      }
      if (typeof x_spreadsheet !== "function") {
        sheetFatal("Spreadsheet UI failed to load (xspreadsheet.js).");
        return;
      }

      host.innerHTML = "<p class=\"gds-sheet-note\" style=\"padding:16px\">Loading spreadsheet…</p>";
      const resp = await fetch(url, fetchOpts);
      if (!resp.ok) throw new Error("Could not download file (HTTP " + resp.status + ").");
      const bytes = await resp.arrayBuffer();
      fileThemePalette = await extractThemePaletteFromXlsx(bytes);
      const wb = XLSX.read(bytes, { type: "array", cellStyles: true, cellNF: true });

      host.innerHTML = "";
      const xs = x_spreadsheet(host, {
        mode: "read",
        showToolbar: false,
        showContextmenu: false,
        showGrid: true,
        view: {
          height: () => host.clientHeight,
          width: () => host.clientWidth,
        },
      });
      xs.loadData(stox(wb));

      window.__gdsViewerAnalyticsContext = function () {
        try {
          const sheet = xs.sheet && xs.sheet.data ? xs.sheet.data : null;
          return { sheet_tab: sheet && sheet.name ? String(sheet.name) : "" };
        } catch (e) {
          return { sheet_tab: "" };
        }
      };
    })().catch(function (err) {
      console.error(err);
      var msg = (err && err.message) ? String(err.message) : "Could not load spreadsheet preview.";
      sheetFatal(msg + " You can also open the file in Excel if download is enabled.");
    });
    return;
  }

  pdfjsLib.GlobalWorkerOptions.workerSrc = workerSrc;

  const canvas = document.getElementById("pdfCanvas");
  const ctx = canvas.getContext("2d");
  const zoomLabel = document.getElementById("zoomLabel");
  const pageNumEl = document.getElementById("pageNum");
  const pageCountEl = document.getElementById("pageCount");
  const pdfPane = document.getElementById("pdfArea");
  const pdfWrap = document.getElementById("pdfCanvasWrap");

  // Grab-to-pan inside the PDF viewport when zoomed.
  (function enablePdfPanning() {
    if (!pdfPane) return;
    let down = false;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;
    let moved = false;

    function onDown(e) {
      if (!e || e.button !== 0) return;
      down = true;
      moved = false;
      pdfPane.style.cursor = "grabbing";
      startX = e.clientX;
      startY = e.clientY;
      startLeft = pdfPane.scrollLeft;
      startTop = pdfPane.scrollTop;
    }
    function onMove(e) {
      if (!down) return;
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      if (Math.abs(dx) > 2 || Math.abs(dy) > 2) moved = true;
      pdfPane.scrollLeft = startLeft - dx;
      pdfPane.scrollTop = startTop - dy;
      if (e.cancelable) e.preventDefault();
    }
    function onUp() {
      if (!down) return;
      down = false;
      pdfPane.style.cursor = "grab";
    }

    pdfPane.addEventListener("mousedown", onDown);
    window.addEventListener("mousemove", onMove, { passive: false });
    window.addEventListener("mouseup", onUp);
    // Prevent accidental text selection while panning.
    if (pdfWrap) {
      pdfWrap.addEventListener("dragstart", (e) => e.preventDefault());
    }
    // Suppress click events if we actually dragged.
    pdfPane.addEventListener("click", (e) => {
      if (moved) {
        e.preventDefault();
        e.stopPropagation();
        moved = false;
      }
    }, true);
  })();

  function pdfFatal(msg) {
    if (pdfPane) {
      pdfPane.innerHTML = "<p class=\"gds-sheet-note\" style=\"padding:16px;max-width:42rem;margin:0 auto\">" + String(msg).replace(/</g, "&lt;").replace(/>/g, "&gt;") + "</p>";
    }
  }

  let pdfDoc = null;
  let pageNum = 1;
  window.__gdsViewerAnalyticsContext = function () {
    return {
      pdf_page: pageNum,
      pdf_pages_total: pdfDoc ? pdfDoc.numPages : null,
    };
  };
  let scale = 1.1;
  let rendering = false;
  let pending = null;
  let wmImg = null;
  let wmReady = false;

  async function loadWatermark() {
    if (!watermarkEnabled) return;
    try {
      const img = new Image();
      img.decoding = "async";
      img.src = watermarkUrl;
      await img.decode();
      wmImg = img;
      wmReady = true;
    } catch (e) {
      wmImg = null;
      wmReady = false;
    }
  }

  function drawWatermark(viewport) {
    if (!watermarkEnabled || !wmReady || !wmImg) return;
    const margin = 16;
    const maxW = Math.min(140, viewport.width * 0.22);
    const aspect = wmImg.naturalWidth > 0 ? (wmImg.naturalHeight / wmImg.naturalWidth) : 0.35;
    const w = maxW;
    const h = Math.max(16, w * aspect);
    ctx.save();
    ctx.globalAlpha = 0.18;
    ctx.drawImage(wmImg, margin, margin, w, h);
    ctx.globalAlpha = 0.55;
    ctx.fillStyle = "rgba(17,24,39,1)";
    ctx.font = "12px ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial";
    ctx.textBaseline = "top";
    ctx.fillText("IP: " + (viewerIp || ""), margin, margin + h + 6);
    ctx.restore();
  }

  function setZoomLabel() {
    zoomLabel.textContent = Math.round(scale * 100) + "%";
  }

  async function renderPage(num) {
    if (!pdfDoc) return;
    if (rendering) { pending = num; return; }
    const prevW = canvas.width || 0;
    const prevH = canvas.height || 0;
    const prevLeft = pdfPane ? pdfPane.scrollLeft : 0;
    const prevTop = pdfPane ? pdfPane.scrollTop : 0;
    const prevCenterX = pdfPane ? (prevLeft + pdfPane.clientWidth / 2) : 0;
    const prevCenterY = pdfPane ? (prevTop + pdfPane.clientHeight / 2) : 0;
    const prevRelX = prevW > 0 ? (prevCenterX / prevW) : 0.5;
    const prevRelY = prevH > 0 ? (prevCenterY / prevH) : 0.5;
    pageNum = num;
    rendering = true;
    const page = await pdfDoc.getPage(num);
    const viewport = page.getViewport({ scale });
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({ canvasContext: ctx, viewport }).promise;
    drawWatermark(viewport);
    rendering = false;
    pageNumEl.textContent = String(num);
    setZoomLabel();
    // Keep the viewport anchored around the same relative center point after zoom.
    if (pdfPane) {
      const nextCenterX = prevRelX * canvas.width;
      const nextCenterY = prevRelY * canvas.height;
      pdfPane.scrollLeft = Math.max(0, nextCenterX - pdfPane.clientWidth / 2);
      pdfPane.scrollTop = Math.max(0, nextCenterY - pdfPane.clientHeight / 2);
    }
    if (pending !== null) {
      const next = pending;
      pending = null;
      await renderPage(next);
    }
  }

  function clampScale(v) { return Math.max(0.6, Math.min(3.0, v)); }

  document.getElementById("zoomInBtn").addEventListener("click", async () => {
    scale = clampScale(scale + 0.15);
    await renderPage(pageNum);
  });
  document.getElementById("zoomOutBtn").addEventListener("click", async () => {
    scale = clampScale(scale - 0.15);
    await renderPage(pageNum);
  });
  document.getElementById("nextBtn").addEventListener("click", async () => {
    if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
    pageNum += 1;
    await renderPage(pageNum);
  });
  document.getElementById("prevBtn").addEventListener("click", async () => {
    if (!pdfDoc || pageNum <= 1) return;
    pageNum -= 1;
    await renderPage(pageNum);
  });

  window.addEventListener("keydown", async (e) => {
    if (e.key === "+" || e.key === "=") { scale = clampScale(scale + 0.15); await renderPage(pageNum); }
    if (e.key === "-") { scale = clampScale(scale - 0.15); await renderPage(pageNum); }
    if (e.key === "ArrowRight") { if (pdfDoc && pageNum < pdfDoc.numPages) { pageNum++; await renderPage(pageNum); } }
    if (e.key === "ArrowLeft") { if (pdfDoc && pageNum > 1) { pageNum--; await renderPage(pageNum); } }
  });

  (async () => {
    if (typeof pdfjsLib === "undefined") {
      pdfFatal("PDF viewer failed to load. Ensure public/assets/vendor/pdf.min.js is reachable from this page.");
      return;
    }
    await loadWatermark();
    const resp = await fetch(url, fetchOpts);
    if (!resp.ok) {
      const t = await resp.text().catch(() => "");
      throw new Error("Could not load PDF (HTTP " + resp.status + "). " + (t.length < 120 ? t : ""));
    }
    const bytes = await resp.arrayBuffer();
    const u8 = new Uint8Array(bytes);
    if (u8.length >= 4 && u8[0] === 0x3c && u8[1] === 0x21) {
      throw new Error("Server returned HTML instead of a PDF (session may have expired). Reload or sign in again.");
    }
    const loadPdf = async () => {
      const task = pdfjsLib.getDocument({ data: u8, useSystemFonts: true });
      return task.promise;
    };
    try {
      pdfDoc = await loadPdf();
    } catch (e1) {
      console.warn("PDF load with worker failed, retrying on main thread:", e1);
      try {
        pdfDoc = await pdfjsLib.getDocument({ data: u8, disableWorker: true, useSystemFonts: true }).promise;
      } catch (e2) {
        throw new Error((e2 && e2.message) ? String(e2.message) : String(e1 && e1.message ? e1.message : "Invalid or encrypted PDF."));
      }
    }
    pageCountEl.textContent = String(pdfDoc.numPages);
    setZoomLabel();
    await renderPage(pageNum);
  })().catch((err) => {
    console.error(err);
    pdfFatal((err && err.message) ? String(err.message) : "Failed to load PDF.");
  });
})();
</script>
HTML;

renderFooter();

