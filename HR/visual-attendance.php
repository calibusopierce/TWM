<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'attendance');

// ── Session context ────────────────────────────────────────
$sessionDept = trim($_SESSION['Department'] ?? '');
$userLevel   = $_SESSION['userlevel']  ?? '';
$isHR        = ($userLevel === 'Admin' || $userLevel === 'HR');

// Some parts of the app set Department to a sentinel value when the
// person has picked "All Departments" from a department switcher —
// treat those the same as "no department filter" instead of trying
// (and failing) to match a department literally named "All".
$allDeptSentinels = ['', 'all', 'all department', 'all departments', '*'];
$isAllDept = in_array(strtolower($sessionDept), $allDeptSentinels, true);
$showAllDepts = $isHR || $isAllDept;

// ── Date defaults ──────────────────────────────────────────
date_default_timezone_set('Asia/Manila');
$today     = date('Y-m-d');
$thirtyAgo = date('Y-m-d', strtotime('-30 day'));

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : $thirtyAgo;
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : $today;

$dateFromSafe = str_replace("'", "''", $dateFrom);
$dateToSafe   = str_replace("'", "''", $dateTo);
$deptSafe     = str_replace("'", "''", $sessionDept);

// ── Picture base URL ───────────────────────────────────────
$picBase = 'http://122.52.195.3/tradewellportal/';

// ── Dept WHERE helpers ─────────────────────────────────────
// RTRIM guards against padded char(N) Department columns not matching
// an unpadded session value.
$deptWhere1 = $showAllDepts ? '' : "AND RTRIM(Department) = '$deptSafe'";
$deptWhere2 = $showAllDepts ? '' : "AND RTRIM(Department) = '$deptSafe'";

// ── Fetch: online check-ins (View_Selfie) ──────────────────
$sql1 = "
    SELECT FullName, Area, Remarks, Action, DateUpload, TimeUpload, Picture
    FROM View_Selfie
    WHERE DateUpload BETWEEN '$dateFromSafe' AND '$dateToSafe'
    $deptWhere1
    GROUP BY FullName, Area, Remarks, Action, DateUpload, TimeUpload, Picture
    ORDER BY DateUpload DESC, TimeUpload DESC
";
$stmt1 = sqlsrv_query($conn, $sql1);

$online = [];
if ($stmt1) {
    while ($r = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC)) {
        if ($r['DateUpload'] instanceof DateTime) $r['DateUpload'] = $r['DateUpload']->format('Y-m-d');
        if ($r['TimeUpload'] instanceof DateTime) $r['TimeUpload'] = $r['TimeUpload']->format('H:i:s');
        $online[] = $r;
    }
    sqlsrv_free_stmt($stmt1);
}

// ── Fetch: offline check-ins (View_Selfie_Offline) ────────
$sql2 = "
    SELECT EmployeeName, Area, Remarks, Direction, ADate, ATime, Picture, Longitude, Latitude
    FROM View_Selfie_Offline
    WHERE ADate BETWEEN '$dateFromSafe' AND '$dateToSafe'
    $deptWhere2
    GROUP BY EmployeeName, Area, Remarks, Direction, ADate, ATime, Picture, Longitude, Latitude
    ORDER BY ADate DESC, ATime DESC
";
$stmt2 = sqlsrv_query($conn, $sql2);

$offline = [];
if ($stmt2) {
    while ($r = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
        if ($r['ADate'] instanceof DateTime) $r['ADate'] = $r['ADate']->format('Y-m-d');
        if ($r['ATime'] instanceof DateTime) $r['ATime'] = $r['ATime']->format('H:i:s');
        $offline[] = $r;
    }
    sqlsrv_free_stmt($stmt2);
}

// ── Helpers ────────────────────────────────────────────────
function picUrl(string $base, ?string $pic): string {
    if (!$pic) return '';
    return $base . str_replace(' ', '%20', $pic);
}
function fmtDate(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('M d, Y', $t) : '—';
}
function fmtTime(?string $t): string {
    if (!$t) return '—';
    $ts = strtotime($t);
    return $ts ? date('h:i:s a', $ts) : '—';
}
function actionBadge(?string $a): string {
    $a = strtolower(trim($a ?? ''));
    if (str_contains($a, 'in'))  return '<span class="va-badge va-badge-in">⬇ In</span>';
    if (str_contains($a, 'out')) return '<span class="va-badge va-badge-out">⬆ Out</span>';
    return '<span class="va-badge">' . htmlspecialchars($a) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visual Attendance — HR · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ── Visual attendance specific ───────────────────────── */
.va-table { width:100%; border-collapse:collapse; }
.va-table thead th {
    background:var(--surface-raised, #f1f5f9);
    color:var(--text-muted, #64748b);
    font-size:.82rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
    padding:11px 16px;
    border-bottom:2px solid var(--border, #e2e8f0);
    white-space:nowrap;
    text-align:left;
}
.va-table tbody tr { border-bottom:1px solid var(--border, #e2e8f0); transition:background .12s; }
.va-table tbody tr:hover { background:var(--surface-hover, #f8fafc); }
.va-table tbody td {
    padding:14px 16px;
    font-size:1.05rem;
    color:var(--text, #1e293b);
    vertical-align:middle;
}

/* ── Selfie thumbnail ──────────────────────────────────── */
.va-thumb-wrap {
    width:72px; height:72px;
    border-radius:10px;
    overflow:hidden;
    border:1px solid var(--border, #e2e8f0);
    background:var(--surface-raised, #f1f5f9);
    cursor:pointer;
    flex-shrink:0;
}
.va-thumb-wrap img {
    width:100%; height:100%;
    object-fit:cover;
    display:block;
    transition:opacity .15s;
    image-orientation:from-image;
}
.va-thumb-wrap:hover img { opacity:.85; }
.va-no-pic {
    width:72px; height:72px;
    border-radius:10px;
    border:1px solid var(--border, #e2e8f0);
    background:var(--surface-raised, #f1f5f9);
    display:flex; align-items:center; justify-content:center;
    color:var(--text-muted, #94a3b8);
    font-size:1.5rem;
}

/* ── Badges ────────────────────────────────────────────── */
.va-badge {
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    font-size:.78rem;
    font-weight:600;
    background:var(--surface-raised,#f1f5f9);
    color:var(--text-muted,#64748b);
    border:1px solid var(--border,#e2e8f0);
}
.va-badge-in  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.va-badge-out { background:#fef3c7; color:#92400e; border-color:#fcd34d; }

/* ── Map button ────────────────────────────────────────── */
.va-map-btn {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 12px;
    border-radius:6px;
    font-size:.82rem;
    font-weight:600;
    background:#eff6ff;
    color:#1d4ed8;
    border:1px solid #bfdbfe;
    text-decoration:none;
    transition:background .12s;
}
.va-map-btn:hover { background:#dbeafe; }

/* ── Lightbox overlay ──────────────────────────────────── */
#va-lightbox {
    display:none;
    position:fixed; inset:0; z-index:9999;
    background:rgba(0,0,0,.75);
    align-items:center; justify-content:center;
}
#va-lightbox.open { display:flex; }
#va-lightbox img {
    max-width:90vw !important; max-height:88vh !important;
    width:auto !important; height:auto !important;
    border-radius:12px;
    border:3px solid #fff;
    object-fit:contain !important;
    image-orientation:from-image;
    transition:transform .2s ease, max-width .2s ease, max-height .2s ease;
    transform:rotate(0deg);
}
/* When rotated 90/270 (sideways selfie corrected upright), swap the
   width/height ceiling so the rotated image still fits the viewport
   instead of overflowing off-screen. */
#va-lightbox.rotated-90 img {
    max-width:88vh !important; max-height:85vw !important;
}
#va-lightbox-close {
    position:absolute; top:20px; right:24px;
    background:rgba(255,255,255,.15);
    border:none; color:#fff;
    font-size:1.6rem; line-height:1;
    border-radius:50%; width:40px; height:40px;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
}
#va-lightbox-close:hover { background:rgba(255,255,255,.3); }
.va-lightbox-toolbar {
    position:absolute; top:20px; left:24px;
    display:flex; gap:8px;
}
.va-lightbox-toolbar button {
    background:rgba(255,255,255,.15);
    border:none; color:#fff;
    font-size:1.15rem; line-height:1;
    border-radius:50%; width:40px; height:40px;
    cursor:pointer; display:flex; align-items:center; justify-content:center;
    transition:background .12s;
}
.va-lightbox-toolbar button:hover { background:rgba(255,255,255,.3); }
.va-lightbox-hint {
    position:absolute; top:70px; left:24px;
    color:rgba(255,255,255,.85);
    font-size:.8rem;
    background:rgba(0,0,0,.35);
    padding:4px 10px; border-radius:6px;
    pointer-events:none;
}
/* Clean fallback state if the photo genuinely fails to load, instead of
   the browser's native broken-image icon. */
#va-lightbox.va-lightbox-error img { display:none; }
.va-lightbox-error-msg { display:none; color:rgba(255,255,255,.85); font-size:.9rem; text-align:center; }
#va-lightbox.va-lightbox-error .va-lightbox-error-msg { display:block; }

/* ── Pagination ────────────────────────────────────────── */
.va-pagination {
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 16px; border-top:1px solid var(--border,#e2e8f0);
    gap:12px; flex-wrap:wrap;
}
.va-page-info { font-size:.88rem; color:var(--text-muted,#64748b); }
.va-page-btns { display:flex; gap:4px; align-items:center; }
.va-page-btns button {
    border:1px solid var(--border,#e2e8f0);
    background:var(--surface,#fff);
    color:var(--text,#1e293b);
    border-radius:6px;
    padding:5px 11px;
    font-size:.88rem;
    font-weight:600;
    cursor:pointer;
    min-width:34px;
}
.va-page-btns button:hover:not(:disabled) { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.va-page-btns button.active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.va-page-btns button:disabled { opacity:.35; cursor:not-allowed; }
.va-page-btns .ellipsis { padding:5px 4px; font-size:.88rem; color:var(--text-muted,#64748b); }

/* ── Section divider label ─────────────────────────────── */
.va-section-label {
    display:flex; align-items:center; gap:.75rem;
    margin:1.5rem 0 .75rem;
    font-size:.72rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.1em;
    color:var(--text-muted,#94a3b8);
}
.va-section-label::after {
    content:''; flex:1;
    height:1px; background:var(--border,#e2e8f0);
}
</style>
</head>
<body>

<?php
$topbar_page = 'hr_attendance';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'visual-attendance';
require_once __DIR__ . '/hr_nav.php';
?>

<!-- ── Page Header ───────────────────────────────────────── -->
<div class="hr-page-header">
  <div>
    <div class="hr-page-title">📸 <span style="color:#2563eb;">Visual</span> Attendance</div>
    <div class="hr-page-badge">
      📁 <?= $showAllDepts ? 'All Departments' : htmlspecialchars($sessionDept) ?>
      · <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?>
    </div>
  </div>
  <a href="<?= base_url('HR/attendance.php') ?>" class="hr-btn hr-btn-ghost">
    <i class="bi bi-clock-history"></i> Back to Attendance
  </a>
</div>

<!-- ── Filter Bar ────────────────────────────────────────── -->
<form method="GET" action="">
  <div class="hr-filter-bar">
    <div class="hr-filter-group">
      <label>Date From</label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
    </div>
    <div class="hr-filter-group">
      <label>Date To</label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
    </div>
    <button type="submit" class="hr-btn hr-btn-primary">
      <i class="bi bi-funnel-fill"></i> Filter
    </button>
    <a href="?" class="hr-btn hr-btn-ghost">
      <i class="bi bi-x-circle"></i> Reset
    </a>
  </div>
</form>

<!-- ── Online Check-ins ──────────────────────────────────── -->
<div class="va-section-label"><i class="bi bi-phone"></i> Portal check-ins (online)</div>

<div class="hr-table-card" style="margin-bottom:1.25rem;">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      📱 Online Selfie Check-in
      <span class="hr-table-count" id="onlineCount"><?= count($online) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="onlineSearch" placeholder="Search…" oninput="tableSearch('online')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('online','online_checkin')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('online','Portal Check-in')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="va-table" id="onlineTable">
      <thead>
        <tr>
          <th style="width:90px">Photo</th>
          <th>Name</th>
          <th>Area</th>
          <th>Remarks</th>
          <th>Action</th>
          <th>Date</th>
          <th>Time</th>
        </tr>
      </thead>
      <tbody id="onlineBody"></tbody>
    </table>
    <div id="onlineEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
  </div>
  <div class="va-pagination" id="onlinePager"></div>
</div>

<!-- ── Offline Check-ins ─────────────────────────────────── -->
<div class="va-section-label"><i class="bi bi-hdd-network"></i> Device check-ins (offline)</div>

<div class="hr-table-card">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      🖥️ Offline / Device Check-in
      <span class="hr-table-count" id="offlineCount"><?= count($offline) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="offlineSearch" placeholder="Search…" oninput="tableSearch('offline')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('offline','offline_checkin')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('offline','Offline Check-in')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="va-table" id="offlineTable">
      <thead>
        <tr>
          <th style="width:90px">Photo</th>
          <th>Name</th>
          <th>Area</th>
          <th>Remarks</th>
          <th>Direction</th>
          <th>Date</th>
          <th>Time</th>
          <th>Location</th>
        </tr>
      </thead>
      <tbody id="offlineBody"></tbody>
    </table>
    <div id="offlineEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
  </div>
  <div class="va-pagination" id="offlinePager"></div>
</div>

<!-- ── Lightbox ──────────────────────────────────────────── -->
<div id="va-lightbox" onclick="closeLightbox()">
  <div class="va-lightbox-toolbar" onclick="event.stopPropagation()">
    <button onclick="rotateLightbox(-90)" title="Rotate left"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button onclick="rotateLightbox(90)" title="Rotate right"><i class="bi bi-arrow-clockwise"></i></button>
  </div>
  <div class="va-lightbox-hint">Photo sideways? Tap ⟲ ⟳ to fix</div>
  <button id="va-lightbox-close" onclick="closeLightbox()">✕</button>
  <img id="va-lightbox-img" src="" alt="Selfie preview" onclick="event.stopPropagation()">
  <div class="va-lightbox-error-msg">📷 Photo could not be loaded.</div>
</div>

  </main>
</div>

<script>
// Image URLs are built by pic-proxy.php (server-side) to avoid mixed-
// content blocking — see picUrl() below.

const DATA = {
    online:  <?= json_encode($online,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    offline: <?= json_encode($offline, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};

const PAGE_SIZE = 20;
const state = {
    online:  { page:1, filtered: DATA.online  },
    offline: { page:1, filtered: DATA.offline }
};

// ── Helpers ────────────────────────────────────────────────
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return isNaN(dt) ? d : dt.toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});
}
function fmtTime(t) {
    if (!t) return '—';
    const parts = t.split(':');
    if (parts.length < 2) return t;
    let h = parseInt(parts[0]), m = parts[1], s = parts[2]||'00';
    const ampm = h >= 12 ? 'pm' : 'am';
    h = h % 12 || 12;
    return `${h}:${m}:${s} ${ampm}`;
}
function picUrl(pic) {
    if (!pic) return '';
    return 'pic-proxy.php?path=' + encodeURIComponent(pic);
}
function actionBadge(a) {
    const v = (a||'').toLowerCase();
    if (v.includes('in'))  return `<span class="va-badge va-badge-in">⬇ In</span>`;
    if (v.includes('out')) return `<span class="va-badge va-badge-out">⬆ Out</span>`;
    return `<span class="va-badge">${esc(a)}</span>`;
}
function thumbHtml(pic, name) {
    const url = picUrl(pic);
    if (!url) return `<div class="va-no-pic"><i class="bi bi-person-fill"></i></div>`;
    return `<div class="va-thumb-wrap" onclick="openLightbox('${url.replace(/'/g,"\\'")}')">
        <img src="${url}" alt="${esc(name)}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=\\'va-no-pic\\'><i class=\\'bi bi-person-fill\\'></i></div>'">
    </div>`;
}

// ── Row renderers ──────────────────────────────────────────
function rowOnline(r) {
    return `<tr>
      <td>${thumbHtml(r.Picture, r.FullName)}</td>
      <td style="font-weight:600">${esc(r.FullName||'—')}</td>
      <td>${esc(r.Area||'—')}</td>
      <td style="color:var(--text-muted,#64748b);font-size:.95rem">${esc(r.Remarks||'—')}</td>
      <td>${actionBadge(r.Action)}</td>
      <td style="white-space:nowrap">${fmtDate(r.DateUpload)}</td>
      <td style="white-space:nowrap;font-family:'JetBrains Mono',monospace;font-size:.92rem">${fmtTime(r.TimeUpload)}</td>
    </tr>`;
}
function rowOffline(r) {
    const lat = r.Latitude, lng = r.Longitude;
    const hasMap = lat && lng && (parseFloat(lng) !== 0);
    const mapBtn = hasMap
        ? `<a class="va-map-btn" href="https://www.google.com/maps/place/${lng}+${lat}" target="_blank"><i class="bi bi-map-fill"></i> Map</a>`
        : '<span style="color:var(--text-muted,#94a3b8);font-size:.85rem">—</span>';
    return `<tr>
      <td>${thumbHtml(r.Picture, r.EmployeeName)}</td>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Area||'—')}</td>
      <td style="color:var(--text-muted,#64748b);font-size:.95rem">${esc(r.Remarks||'—')}</td>
      <td>${actionBadge(r.Direction)}</td>
      <td style="white-space:nowrap">${fmtDate(r.ADate)}</td>
      <td style="white-space:nowrap;font-family:'JetBrains Mono',monospace;font-size:.92rem">${fmtTime(r.ATime)}</td>
      <td>${mapBtn}</td>
    </tr>`;
}

const RENDERERS = { online: rowOnline, offline: rowOffline };

// ── Render ─────────────────────────────────────────────────
function renderTable(id) {
    const s     = state[id];
    const all   = s.filtered;
    const total = all.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    s.page = Math.min(s.page, pages);

    const start = (s.page - 1) * PAGE_SIZE;
    const slice = all.slice(start, start + PAGE_SIZE);

    const tbody = document.getElementById(id + 'Body');
    const empty = document.getElementById(id + 'Empty');
    const count = document.getElementById(id + 'Count');
    const pager = document.getElementById(id + 'Pager');

    if (!tbody) return;

    if (total === 0) {
        tbody.innerHTML = '';
        if (empty) empty.style.display = '';
        if (count) count.textContent = '0 records';
        if (pager) pager.innerHTML = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (count) count.textContent = `${total} record${total !== 1 ? 's' : ''}`;
    tbody.innerHTML = slice.map(RENDERERS[id]).join('');

    if (pager) {
        const from = start + 1, to = Math.min(start + PAGE_SIZE, total);
        let btns = `<span class="va-page-info">Showing ${from}–${to} of ${total}</span><div class="va-page-btns">`;
        btns += `<button onclick="goPage('${id}',${s.page-1})" ${s.page<=1?'disabled':''}>‹</button>`;
        const nums = buildPageNums(s.page, pages);
        for (const p of nums) {
            if (p === '…') btns += `<span class="ellipsis">…</span>`;
            else btns += `<button class="${p===s.page?'active':''}" onclick="goPage('${id}',${p})">${p}</button>`;
        }
        btns += `<button onclick="goPage('${id}',${s.page+1})" ${s.page>=pages?'disabled':''}>›</button></div>`;
        pager.innerHTML = btns;
    }
}

function buildPageNums(cur, total) {
    if (total <= 7) return Array.from({length:total},(_,i)=>i+1);
    const p = [1];
    if (cur > 3) p.push('…');
    for (let i = Math.max(2,cur-1); i <= Math.min(total-1,cur+1); i++) p.push(i);
    if (cur < total-2) p.push('…');
    p.push(total);
    return p;
}

function goPage(id, p) {
    const s = state[id];
    s.page = Math.max(1, Math.min(p, Math.ceil(s.filtered.length / PAGE_SIZE)));
    renderTable(id);
}

// ── Search ─────────────────────────────────────────────────
function tableSearch(id) {
    const q = (document.getElementById(id+'Search')?.value||'').toLowerCase().trim();
    state[id].filtered = q
        ? DATA[id].filter(r => Object.values(r).some(v => String(v||'').toLowerCase().includes(q)))
        : DATA[id];
    state[id].page = 1;
    renderTable(id);
}

// ── CSV export ─────────────────────────────────────────────
function exportCSV(id, filename) {
    const all = state[id].filtered;
    if (!all.length) return;
    const keys = Object.keys(all[0]).filter(k => !['Picture'].includes(k));
    const rows = [keys.join(','), ...all.map(r =>
        keys.map(k => '"' + String(r[k]||'').replace(/"/g,'""') + '"').join(',')
    )];
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows.join('\n'));
    a.download = filename + '_<?= date('Ymd') ?>.csv';
    a.click();
}

// ── Print ──────────────────────────────────────────────────
function printTable(id, title) {
    const table = document.getElementById(id+'Table');
    if (!table) return;
    const tbody = document.getElementById(id+'Body');
    const saved = tbody.innerHTML;
    tbody.innerHTML = state[id].filtered.map(RENDERERS[id]).join('');
    const win = window.open('','_blank','width=1100,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>${title}</title>
      <style>
        body{font-family:Arial,sans-serif;font-size:10px;padding:8px}
        h3{margin:0 0 4px;font-size:13px}p{margin:0 0 8px;font-size:8px;color:#555}
        table{width:100%;border-collapse:collapse}
        th{background:#e8e8e8;font-size:8px;text-transform:uppercase;border:1px solid #aaa;padding:4px 6px}
        td{border:1px solid #ccc;padding:3px 6px;vertical-align:middle}
        tr:nth-child(even) td{background:#f7f7f7}
        img{width:48px;height:48px;object-fit:cover;border-radius:4px}
        @page{size:landscape;margin:10mm}
      </style>
    </head><body>
      <h3>Urban Tradewell Corporation — ${title}</h3>
      <p><?= $showAllDepts ? 'All Departments' : htmlspecialchars($sessionDept) ?> &nbsp;|&nbsp; <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?> &nbsp;|&nbsp; Printed: <?= date('Y-m-d H:i') ?></p>
      ${table.outerHTML}
    </body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
    tbody.innerHTML = saved;
}

// ── Lightbox ───────────────────────────────────────────────
// These photos have no EXIF orientation data left (GD strips it on
// save), so there's no reliable way to know WHICH way a sideways photo
// needs to rotate — that can only be judged by looking at it. Guessing
// a fixed direction was wrong about half the time (rotating some photos
// the wrong way, e.g. upside-down instead of upright), so we don't
// auto-rotate. We do auto-size the box to fit a landscape-shaped photo
// without cropping/zooming it — tap either arrow once to set it upright.
let lbRotation = 0;

function openLightbox(url) {
    lbRotation = 0;
    const img = document.getElementById('va-lightbox-img');
    const lb  = document.getElementById('va-lightbox');
    img.style.transform = 'rotate(0deg)';
    lb.classList.remove('rotated-90', 'va-lightbox-error');
    img.onerror = () => lb.classList.add('va-lightbox-error');
    img.src = url;
    lb.classList.add('open');
}
function closeLightbox() {
    document.getElementById('va-lightbox').classList.remove('open', 'va-lightbox-error');
    document.getElementById('va-lightbox-img').src = '';
    document.getElementById('va-lightbox-img').onload = null;
    document.getElementById('va-lightbox-img').onerror = null;
    lbRotation = 0;
}
function rotateLightbox(deg) {
    lbRotation = (lbRotation + deg + 360) % 360;
    const img = document.getElementById('va-lightbox-img');
    const lb  = document.getElementById('va-lightbox');
    img.style.transform = `rotate(${lbRotation}deg)`;
    lb.classList.toggle('rotated-90', lbRotation === 90 || lbRotation === 270);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeLightbox();
    if (document.getElementById('va-lightbox').classList.contains('open')) {
        if (e.key === 'ArrowLeft')  rotateLightbox(-90);
        if (e.key === 'ArrowRight') rotateLightbox(90);
    }
});

// Sidebar toggle/collapse-state persistence is already handled by the
// shared window.toggleHRSidebar() defined in hr_nav.php — don't redefine
// it here, it would overwrite the shared one and break main-content offset.

// ── Init ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    renderTable('online');
    renderTable('offline');
});
</script>

</body>
</html>