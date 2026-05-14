
pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

const overlay = document.getElementById("invOverlay");
const canvas = document.getElementById("invPdfCanvas");
const ctx = canvas.getContext("2d");
const statusEl = document.getElementById("invStatus");
const saveBtn = document.getElementById("invSaveBtn");
const removeFieldBtn = document.getElementById("invRemoveFieldBtn");

let pdfDoc = null;
let pageNum = 1;
let currentFieldKey = "signature";
let boxes = [];
let selectedBoxId = null;
let freeTextCount = 0;

function setStatus(msg, kind) {
  kind = kind || "info";
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
  const el = document.querySelector('[data-box-id="' + def.id + '"]');
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
  let el = document.querySelector('[data-box-id="' + def.id + '"]');
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

  let start = null;
  let mode = "move";
  function onDown(ev) {
    ev.preventDefault();
    ev.stopPropagation();
    selectedBoxId = def.id;
    mode = ev.target === handle ? "resize" : "move";
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
    const i = boxes.findIndex(function (b) { return String(b.id) === String(def.id); });
    if (i >= 0) {
      boxes[i] = Object.assign({}, boxes[i], normalizeBox(el), { page_num: pageNum });
    }
    setStatus("Updated.");
  }
  el.addEventListener("pointerdown", onDown);
  el.addEventListener("click", function (ev) { ev.stopPropagation(); selectedBoxId = def.id; });
  el.addEventListener("mousedown", function (ev) { ev.stopPropagation(); });

  overlay.appendChild(el);
  return el;
}

async function render() {
  const page = await pdfDoc.getPage(pageNum);
  const viewport = page.getViewport({ scale: 1.5 });
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await page.render({ canvasContext: ctx, viewport: viewport }).promise;

  document.getElementById("invPageLabel").textContent = String(pageNum);

  overlay.querySelectorAll("[data-box-id]").forEach(function (el) { el.remove(); });
  for (let i = 0; i < boxes.length; i++) {
    makeBox(boxes[i]);
    applyBox(boxes[i].field_key, boxes[i]);
  }
}

async function init() {
  setStatus("Loading PDF…");
  pdfDoc = await pdfjsLib.getDocument(INV_DOC_URL).promise;
  document.getElementById("invPageCount").textContent = String(pdfDoc.numPages);
  setStatus("Click a field button, then click on the PDF to place it. Drag to adjust.");
  boxes = (invExisting || []).map(function (d) {
    return {
      id: d.id || ("tmp_" + Math.random().toString(16).slice(2)),
      field_key: d.field_key,
      field_label: d.field_label || "",
      page_num: Number(d.page_num || 1),
      x: Number(d.x || 0),
      y: Number(d.y || 0),
      w: Number(d.w || 0.25),
      h: Number(d.h || 0.06),
      required: Number(d.required ?? 1),
    };
  });
  freeTextCount = boxes.filter(function (b) { return b.field_key === "free_text"; }).length;
  await render();
}

document.querySelectorAll(".invFieldBtn").forEach(function (btn) {
  btn.addEventListener("click", function () {
    currentFieldKey = btn.dataset.key;
    setStatus("Selected: " + currentFieldKey + " (click on the PDF to place)");
  });
  btn.addEventListener("dragstart", function (ev) {
    try {
      ev.dataTransfer.setData("text/plain", String(btn.dataset.key || ""));
      ev.dataTransfer.effectAllowed = "copy";
    } catch (e) {}
  });
});

overlay.addEventListener("dragover", function (ev) { ev.preventDefault(); });
overlay.addEventListener("drop", function (ev) {
  ev.preventDefault();
  const k = (ev.dataTransfer && ev.dataTransfer.getData("text/plain")) ? ev.dataTransfer.getData("text/plain") : currentFieldKey;
  currentFieldKey = k || currentFieldKey;
  placeNewAt(ev.clientX, ev.clientY);
});

function labelFor(k) {
  const m = {
    signature: "Signature",
    signed_date: "Date",
    signer_name: "Name",
    signer_position: "Position",
    signer_address: "Address",
    commitment_amount: "Commitment",
    free_text: "Free text"
  };
  return m[k] || k;
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
    id: id,
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
  const idx = boxes.findIndex(function (b) { return String(b.id) === String(selectedBoxId); });
  if (idx < 0) return;
  boxes.splice(idx, 1);
  selectedBoxId = null;
  freeTextCount = boxes.filter(function (b) { return b.field_key === "free_text"; }).length;
  await render();
  setStatus("Field removed.");
}

overlay.addEventListener("click", function (ev) {
  if (ev.target !== overlay) return;
  placeNewAt(ev.clientX, ev.clientY);
});

document.getElementById("invPrevPageBtn").addEventListener("click", async function () {
  if (!pdfDoc || pageNum <= 1) return;
  pageNum -= 1;
  await render();
});
document.getElementById("invNextPageBtn").addEventListener("click", async function () {
  if (!pdfDoc || pageNum >= pdfDoc.numPages) return;
  pageNum += 1;
  await render();
});

window.addEventListener("keydown", function (ev) {
  if (ev.key === "Delete" || ev.key === "Backspace") {
    if (!selectedBoxId) return;
    const el = document.querySelector('[data-box-id="' + selectedBoxId + '"]');
    if (!el || el.style.display === "none") return;
    ev.preventDefault();
    deleteSelectedField();
    return;
  }
  if (!selectedBoxId) return;
  const el = document.querySelector('[data-box-id="' + selectedBoxId + '"]');
  if (!el || el.style.display === "none") return;
  const step = ev.shiftKey ? 10 : 1;
  if (["ArrowUp","ArrowDown","ArrowLeft","ArrowRight"].indexOf(ev.key) < 0) return;
  ev.preventDefault();
  const left = parseFloat(el.style.left || "0");
  const top = parseFloat(el.style.top || "0");
  if (ev.key === "ArrowLeft") el.style.left = Math.max(0, left - step) + "px";
  if (ev.key === "ArrowRight") el.style.left = Math.max(0, left + step) + "px";
  if (ev.key === "ArrowUp") el.style.top = Math.max(0, top - step) + "px";
  if (ev.key === "ArrowDown") el.style.top = Math.max(0, top + step) + "px";
  const idx = boxes.findIndex(function (b) { return String(b.id) === String(selectedBoxId); });
  if (idx >= 0) boxes[idx] = Object.assign({}, boxes[idx], normalizeBox(el), { page_num: pageNum });
});

if (removeFieldBtn) {
  removeFieldBtn.addEventListener("click", function () { deleteSelectedField(); });
}

saveBtn.addEventListener("click", async function () {
  const oldText = saveBtn.textContent;
  saveBtn.disabled = true;
  saveBtn.textContent = "Saving…";
  setStatus("Saving…");
  try {
    const defs = boxes.map(function (d) {
      return {
        field_key: d.field_key,
        field_label: d.field_label || "",
        page_num: d.page_num || 1,
        x: d.x, y: d.y, w: d.w, h: d.h,
        required: d.required ? 1 : 0
      };
    });
    const res = await fetch("index.php?api=save_contract_fields", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ project_id: INV_PROJECT_ID, defs: defs, _csrf: INV_CSRF })
    });
    const j = await res.json().catch(function () { return null; });
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

init().catch(function (err) { console.error(err); setStatus("Failed to load PDF.", "err"); });
