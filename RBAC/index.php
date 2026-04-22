<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check(['Admin', 'Administrator']);

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

try {
    $pdo = new PDO(
        "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
        null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ RBAC DB connection failed: " . $e->getMessage());
}

// ── Load all modules ──────────────────────────────────────────
$modules = $pdo->query("
    SELECT * FROM rbac_modules ORDER BY sort_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Load all distinct roles from users table ──────────────────
$rolesFromUsers = $pdo->query("
    SELECT DISTINCT user_type AS role_name, COUNT(*) AS total
    FROM users
    WHERE user_type IS NOT NULL AND user_type != ''
    GROUP BY user_type
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Load all current permissions as a flat lookup ─────────────
$permsRaw = $pdo->query("
    SELECT role_name, module_key, can_access
    FROM rbac_permissions
")->fetchAll(PDO::FETCH_ASSOC);

$permsMap = [];
foreach ($permsRaw as $p) {
    $permsMap[$p['role_name'] . '|' . $p['module_key']] = (int)$p['can_access'];
}

// ── Category meta ─────────────────────────────────────────────
$categoryMeta = [
    'hr'      => ['label' => 'HR',      'color' => '#34d399'],
    'fleet'   => ['label' => 'Fleet',   'color' => '#fbbf24'],
    'finance' => ['label' => 'Finance', 'color' => '#a78bfa'],
    'general' => ['label' => 'General', 'color' => '#60a5fa'],
];

// ── Encode modules to JSON for JS use ────────────────────────
$modulesJson = json_encode(array_values($modules));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RBAC · Role Access Control</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <style>
    :root {
      --bg-0:    #060d1f;
      --bg-1:    #0b1530;
      --bg-2:    #101d3e;
      --surface: rgba(255,255,255,0.04);
      --border:  rgba(255,255,255,0.08);
      --border2: rgba(255,255,255,0.14);
      --white:   #ffffff;
      --w60:     rgba(255,255,255,0.60);
      --w40:     rgba(255,255,255,0.40);
      --w15:     rgba(255,255,255,0.15);
      --w08:     rgba(255,255,255,0.08);
      --accent:  #4380e2;
      --accent2: #93c5fd;
      --green:   #34d399;
      --amber:   #fbbf24;
      --red:     #f87171;
      --purple:  #a78bfa;
      --on-color:  #34d399;
      --off-color: rgba(255,255,255,0.15);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--bg-0);
      color: var(--white);
      overflow-x: hidden;
    }

    /* ── Background mesh ──────────────────────────────────── */
    .mesh {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 80% 50% at 10% 0%,   rgba(67,128,226,0.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%,  rgba(52,211,153,0.10) 0%, transparent 60%),
        radial-gradient(ellipse 100% 80% at 50% 50%,  rgba(6,13,31,1) 40%,  transparent 100%);
    }
    .mesh::after {
      content: '';
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
      background-size: 48px 48px;
    }

    /* ── Layout ────────────────────────────────────────────── */
    .wrap {
      position: relative; z-index: 10;
      max-width: 1300px;
      margin: 0 auto;
      padding: 2rem 1.5rem 4rem;
    }

    /* ── Page header ──────────────────────────────────────── */
    .page-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;
      animation: fadeUp .4s ease both;
    }
    .breadcrumb {
      font-size: .72rem; color: var(--w40);
      letter-spacing: .06em; text-transform: uppercase; margin-bottom: .4rem;
    }
    .breadcrumb a { color: var(--accent2); text-decoration: none; }
    .breadcrumb a:hover { text-decoration: underline; }
    .page-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.75rem; font-weight: 800;
      letter-spacing: -.04em; color: var(--white); line-height: 1.1;
    }
    .page-title span { color: var(--accent2); }
    .page-sub { font-size: .82rem; color: var(--w60); margin-top: .35rem; }
    .page-header-right { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }

    /* ── Buttons ──────────────────────────────────────────── */
    .btn {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .5rem 1.1rem; border-radius: 10px;
      font-size: .8rem; font-weight: 600; cursor: pointer;
      border: 1px solid transparent; transition: all .2s;
      text-decoration: none; font-family: 'DM Sans', sans-serif;
    }
    .btn-primary { background: var(--accent); color: #fff; border-color: rgba(67,128,226,.5); }
    .btn-primary:hover { background: #3370d4; }
    .btn-ghost { background: var(--w08); color: var(--w60); border-color: var(--border); }
    .btn-ghost:hover { background: var(--w15); color: var(--white); }
    .btn-danger { background: rgba(239,68,68,.15); color: #fca5a5; border-color: rgba(239,68,68,.3); }
    .btn-danger:hover { background: rgba(239,68,68,.25); }
    .btn-sm { padding: .3rem .7rem; font-size: .72rem; }

    /* ── Stats bar ────────────────────────────────────────── */
    .stats-bar {
      display: flex; gap: 1rem; flex-wrap: wrap;
      margin-bottom: 1.75rem;
      animation: fadeUp .4s .1s ease both;
    }
    .stat-chip {
      display: flex; align-items: center; gap: .6rem;
      padding: .6rem 1rem;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; font-size: .78rem;
    }
    .stat-chip-num { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--white); }
    .stat-chip-label { color: var(--w60); }
    .stat-chip .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

    /* ── Filter row ───────────────────────────────────────── */
    .filter-row {
      display: flex; gap: .75rem; flex-wrap: wrap; align-items: center;
      margin-bottom: 1.5rem;
      animation: fadeUp .4s .15s ease both;
    }
    .search-box {
      display: flex; align-items: center; gap: .5rem;
      background: var(--surface); border: 1px solid var(--border2);
      border-radius: 10px; padding: .45rem .85rem;
      flex: 1; min-width: 200px; max-width: 320px;
    }
    .search-box i { color: var(--w40); font-size: .9rem; }
    .search-box input {
      background: none; border: none; outline: none;
      color: var(--white); font-size: .82rem; width: 100%;
      font-family: 'DM Sans', sans-serif;
    }
    .search-box input::placeholder { color: var(--w40); }

    .filter-pills { display: flex; gap: .5rem; flex-wrap: wrap; }
    .pill {
      padding: .3rem .8rem; border-radius: 999px;
      font-size: .72rem; font-weight: 600; cursor: pointer;
      border: 1px solid var(--border2);
      background: var(--w08); color: var(--w60); transition: all .15s;
    }
    .pill.active, .pill:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
    .pill[data-cat="hr"].active      { background: #34d399; border-color: #34d399; color: #000; }
    .pill[data-cat="fleet"].active   { background: #fbbf24; border-color: #fbbf24; color: #000; }
    .pill[data-cat="finance"].active { background: #a78bfa; border-color: #a78bfa; color: #000; }
    .pill[data-cat="general"].active { background: #60a5fa; border-color: #60a5fa; color: #000; }

    /* ── Matrix card ──────────────────────────────────────── */
    .matrix-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 20px; overflow: hidden;
      animation: fadeUp .4s .2s ease both;
    }
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    table { width: 100%; border-collapse: collapse; min-width: 700px; }

    thead tr { background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border); }
    th {
      padding: .9rem 1rem;
      font-size: .72rem; font-weight: 700;
      letter-spacing: .06em; text-transform: uppercase;
      color: var(--w40); text-align: left; white-space: nowrap;
    }
    th.mod-col { text-align: center; min-width: 100px; }
    .mod-col-inner { display: flex; flex-direction: column; align-items: center; gap: .2rem; }
    .mod-col-icon { font-size: 1rem; }
    .mod-col-name {
      font-size: .65rem; font-weight: 600; letter-spacing: .04em;
      text-transform: none; color: var(--w60); line-height: 1.3;
      text-align: center; max-width: 80px;
    }
    .cat-badge {
      display: inline-block; padding: .1rem .4rem; border-radius: 4px;
      font-size: .58rem; font-weight: 700;
      letter-spacing: .04em; text-transform: uppercase; margin-top: .1rem;
    }

    tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: rgba(255,255,255,0.03); }

    td { padding: .75rem 1rem; font-size: .82rem; vertical-align: middle; }

    /* Role cell */
    .role-cell { display: flex; align-items: center; gap: .65rem; white-space: nowrap; }
    .role-avatar {
      width: 32px; height: 32px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-size: .7rem; font-weight: 800;
      flex-shrink: 0;
      background: rgba(67,128,226,.2); color: var(--accent2);
      border: 1px solid rgba(67,128,226,.25);
    }
    .role-name  { font-weight: 600; color: var(--white); }
    .role-count { font-size: .72rem; color: var(--w40); }

    /* Row actions cell */
    .row-actions { display: flex; gap: .35rem; align-items: center; white-space: nowrap; }
    .btn-grant-all {
      background: rgba(52,211,153,.12); color: #34d399;
      border: 1px solid rgba(52,211,153,.25);
    }
    .btn-grant-all:hover { background: rgba(52,211,153,.22); }
    .btn-revoke-all {
      background: rgba(248,113,113,.10); color: #f87171;
      border: 1px solid rgba(248,113,113,.22);
    }
    .btn-revoke-all:hover { background: rgba(248,113,113,.2); }

    /* Toggle cell */
    td.toggle-cell { text-align: center; }
    .toggle { position: relative; display: inline-block; width: 40px; height: 22px; cursor: pointer; }
    .toggle input { display: none; }
    .toggle-track {
      position: absolute; inset: 0;
      background: var(--off-color); border-radius: 999px;
      transition: background .2s; border: 1px solid rgba(255,255,255,.1);
    }
    .toggle input:checked ~ .toggle-track { background: var(--on-color); border-color: var(--on-color); }
    .toggle-thumb {
      position: absolute; top: 3px; left: 3px;
      width: 14px; height: 14px;
      background: #fff; border-radius: 50%;
      transition: transform .2s; box-shadow: 0 1px 4px rgba(0,0,0,.3);
    }
    .toggle input:checked ~ .toggle-thumb { transform: translateX(18px); }

    tr.saving td { opacity: .6; pointer-events: none; }

    /* ── Module registry panel ────────────────────────────── */
    .modules-panel { margin-top: 2rem; animation: fadeUp .4s .3s ease both; }
    .panel-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 1rem; flex-wrap: wrap; gap: .75rem;
    }
    .panel-title { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; color: var(--white); }
    .panel-title span { color: var(--w40); font-size: .8rem; font-weight: 400; }

    .modules-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: .75rem;
    }

    /* Module chip — now matches homepage card style */
    .module-chip {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 16px; padding: 1rem 1rem .85rem;
      display: flex; flex-direction: column; gap: .6rem;
      transition: border-color .2s, background .2s;
      position: relative;
    }
    .module-chip:hover { border-color: var(--border2); background: rgba(255,255,255,0.06); }

    .chip-top { display: flex; align-items: center; gap: .75rem; }
    .module-chip-icon {
      width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .module-chip-icon.green  { background: rgba(52,211,153,.15);  border: 1px solid rgba(52,211,153,.25);  color: #34d399; }
    .module-chip-icon.amber  { background: rgba(251,191,36,.15);  border: 1px solid rgba(251,191,36,.25);  color: #fbbf24; }
    .module-chip-icon.purple { background: rgba(167,139,250,.15); border: 1px solid rgba(167,139,250,.25); color: #a78bfa; }
    .module-chip-icon.blue   { background: rgba(96,165,250,.15);  border: 1px solid rgba(96,165,250,.25);  color: #60a5fa; }

    .chip-info { flex: 1; min-width: 0; }
    .module-chip-name { font-size: .84rem; font-weight: 600; color: var(--white); }
    .module-chip-key  { font-size: .68rem; color: var(--w40); font-family: monospace; margin-top: .1rem; }

    .chip-desc { font-size: .72rem; color: var(--w60); line-height: 1.45; padding: 0 .1rem; }

    .chip-footer { display: flex; align-items: center; gap: .4rem; margin-top: .1rem; }
    .chip-cat-badge {
      padding: .15rem .5rem; border-radius: 5px;
      font-size: .62rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
    }
    .chip-cat-badge.hr      { background: rgba(52,211,153,.15);  color: #34d399; }
    .chip-cat-badge.fleet   { background: rgba(251,191,36,.15);  color: #fbbf24; }
    .chip-cat-badge.finance { background: rgba(167,139,250,.15); color: #a78bfa; }
    .chip-cat-badge.general { background: rgba(96,165,250,.15);  color: #60a5fa; }

    .chip-actions { margin-left: auto; display: flex; gap: .3rem; }
    .module-chip-edit, .module-chip-del {
      background: none; border: none; cursor: pointer;
      font-size: .8rem; padding: .25rem .4rem;
      border-radius: 6px; transition: color .15s, background .15s;
    }
    .module-chip-edit { color: var(--w40); }
    .module-chip-edit:hover { color: var(--accent2); background: rgba(147,197,253,.1); }
    .module-chip-del  { color: var(--w40); }
    .module-chip-del:hover  { color: var(--red); background: rgba(248,113,113,.1); }

    /* ── Homepage preview strip ───────────────────────────── */
    .preview-strip {
      margin-top: .6rem;
      padding: .65rem .9rem;
      background: rgba(255,255,255,.03); border-radius: 10px;
      border: 1px solid rgba(255,255,255,.06);
      font-size: .72rem; color: var(--w40);
      display: flex; align-items: center; gap: .5rem;
    }
    .preview-strip i { font-size: .8rem; }

    /* ── Modals (shared) ──────────────────────────────────── */
    .modal-backdrop {
      display: none; position: fixed; inset: 0; z-index: 100;
      background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      background: #0f1c3a; border: 1px solid var(--border2);
      border-radius: 20px; padding: 1.75rem;
      width: 100%; max-width: 520px;
      box-shadow: 0 24px 64px rgba(0,0,0,.5);
      animation: popIn .2s ease both;
    }
    @keyframes popIn {
      from { opacity:0; transform:scale(.94) translateY(10px); }
      to   { opacity:1; transform:scale(1) translateY(0); }
    }
    .modal-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.05rem; font-weight: 700; margin-bottom: 1.25rem;
      display: flex; align-items: center; gap: .5rem;
    }
    .form-group { margin-bottom: 1rem; }
    .form-label {
      display: block; font-size: .74rem; font-weight: 600;
      color: var(--w60); margin-bottom: .35rem;
      letter-spacing: .04em; text-transform: uppercase;
    }
    .form-control {
      width: 100%; padding: .55rem .85rem;
      background: rgba(255,255,255,0.06);
      border: 1px solid var(--border2); border-radius: 10px;
      color: var(--white); font-size: .82rem; outline: none;
      transition: border-color .2s; font-family: 'DM Sans', sans-serif;
    }
    .form-control:focus { border-color: var(--accent); }
    select.form-control option { background: #0f1c3a; }
    .form-row { display: flex; gap: .75rem; }
    .form-row .form-group { flex: 1; }
    .modal-footer { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.25rem; }

    /* ── Live card preview inside modal ──────────────────── */
    .card-preview-wrap {
      margin-bottom: 1.25rem;
      background: rgba(255,255,255,.03); border: 1px solid var(--border);
      border-radius: 14px; padding: .9rem 1rem;
    }
    .card-preview-label {
      font-size: .66rem; font-weight: 700; letter-spacing: .06em;
      text-transform: uppercase; color: var(--w40); margin-bottom: .65rem;
    }
    /* Mirrors homepage .hub-card exactly */
    .hp-card-preview {
      display: inline-flex; flex-direction: column; align-items: center;
      background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,.15);
      border-radius: 18px; padding: 1.4rem 1.1rem 1.2rem;
      min-width: 150px; max-width: 190px; text-align: center;
      box-shadow: 0 4px 20px rgba(8,23,61,.2);
    }
    .hp-card-preview .prev-icon { font-size: 2.1rem; margin-bottom: .7rem; display: block; line-height: 1; }
    .hp-card-preview .prev-icon.blue   { color: #60a5fa; }
    .hp-card-preview .prev-icon.green  { color: #34d399; }
    .hp-card-preview .prev-icon.amber  { color: #fbbf24; }
    .hp-card-preview .prev-icon.purple { color: #a78bfa; }
    .hp-card-preview .prev-name { font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 700; margin-bottom: .3rem; }
    .hp-card-preview .prev-desc { font-size: .72rem; color: var(--w60); line-height: 1.45; }

    /* Icon search */
    .icon-search-wrap { position: relative; }
    .icon-suggestions {
      position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 10;
      background: #0f1c3a; border: 1px solid var(--border2); border-radius: 10px;
      max-height: 160px; overflow-y: auto; display: none;
    }
    .icon-suggestions.open { display: block; }
    .icon-sug-item {
      display: flex; align-items: center; gap: .6rem;
      padding: .45rem .8rem; cursor: pointer; font-size: .8rem;
      transition: background .1s;
    }
    .icon-sug-item:hover { background: rgba(255,255,255,.06); }
    .icon-sug-item i { font-size: 1rem; color: var(--accent2); width: 20px; text-align: center; }

    /* ── Confirm overlay (replaces native confirm()) ──────── */
    .confirm-overlay {
      display: none; position: fixed; inset: 0; z-index: 200;
      background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
    }
    .confirm-overlay.open { display: flex; }
    .confirm-box {
      background: #0f1c3a; border: 1px solid rgba(248,113,113,.3);
      border-radius: 18px; padding: 1.75rem; max-width: 380px; width: 100%;
      box-shadow: 0 24px 64px rgba(0,0,0,.6); animation: popIn .2s ease both;
      text-align: center;
    }
    .confirm-box-icon { font-size: 2rem; color: var(--red); margin-bottom: .75rem; }
    .confirm-box-title { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: .5rem; }
    .confirm-box-msg { font-size: .82rem; color: var(--w60); margin-bottom: 1.25rem; line-height: 1.5; }
    .confirm-box-actions { display: flex; gap: .6rem; justify-content: center; }

    /* ── Toast ────────────────────────────────────────────── */
    .toast-wrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 300;
      display: flex; flex-direction: column; gap: .5rem; pointer-events: none;
    }
    .toast {
      display: flex; align-items: center; gap: .6rem;
      padding: .65rem 1rem;
      background: #0f1c3a; border: 1px solid var(--border2);
      border-radius: 12px; font-size: .8rem;
      box-shadow: 0 8px 24px rgba(0,0,0,.4);
      animation: toastIn .25s ease both; pointer-events: auto;
    }
    .toast.success { border-color: rgba(52,211,153,.4); }
    .toast.error   { border-color: rgba(248,113,113,.4); }
    .toast i.success { color: var(--green); }
    .toast i.error   { color: var(--red); }
    @keyframes toastIn  { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:translateX(0); } }
    @keyframes toastOut { to   { opacity:0; transform:translateX(20px); } }

    /* ── Misc ─────────────────────────────────────────────── */
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }
    .empty-row td { text-align: center; padding: 2rem; color: var(--w40); font-size: .82rem; }

    @media (max-width: 640px) {
      .page-title { font-size: 1.35rem; }
      .wrap { padding: 1.25rem 1rem 3rem; }
      .form-row { flex-direction: column; gap: 0; }
    }
  </style>
</head>
<body>

<div class="mesh"></div>

<div class="wrap">

  <!-- ── Page Header ── -->
  <div class="page-header">
    <div class="page-header-left">
      <div class="breadcrumb">
        <a href="<?= route('home') ?>">Home</a> &rsaquo; RBAC
      </div>
      <div class="page-title">Role-Based <span>Access Control</span></div>
      <div class="page-sub">Manage which user types can access each portal module.</div>
    </div>
    <div class="page-header-right">
      <button class="btn btn-primary" id="btnAddModule">
        <i class="bi bi-plus-lg"></i> Add Module
      </button>
      <a href="<?= route('home') ?>" class="btn btn-ghost">
        <i class="bi bi-arrow-left"></i> Back
      </a>
    </div>
  </div>

  <!-- ── Stats bar ── -->
  <div class="stats-bar">
    <div class="stat-chip">
      <div class="dot" style="background:#4380e2"></div>
      <div>
        <div class="stat-chip-num"><?= count($rolesFromUsers) ?></div>
        <div class="stat-chip-label">User Types</div>
      </div>
    </div>
    <div class="stat-chip">
      <div class="dot" style="background:#34d399"></div>
      <div>
        <div class="stat-chip-num" id="statModuleCount"><?= count($modules) ?></div>
        <div class="stat-chip-label">Modules</div>
      </div>
    </div>
    <div class="stat-chip">
      <div class="dot" style="background:#fbbf24"></div>
      <div>
        <div class="stat-chip-num" id="statGrantCount"><?= count(array_filter($permsRaw, fn($p) => $p['can_access'])) ?></div>
        <div class="stat-chip-label">Active Grants</div>
      </div>
    </div>
    <div class="stat-chip" style="margin-left:auto;">
      <i class="bi bi-shield-lock-fill" style="color:var(--accent2)"></i>
      <div class="stat-chip-label">Logged in as <strong style="color:var(--white)"><?= htmlspecialchars($displayName) ?></strong></div>
    </div>
  </div>

  <!-- ── Filter row ── -->
  <div class="filter-row">
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="text" id="roleSearch" placeholder="Search user type…">
    </div>
    <div class="filter-pills">
      <span class="pill active" data-cat="all">All</span>
      <span class="pill" data-cat="hr">HR</span>
      <span class="pill" data-cat="fleet">Fleet</span>
      <span class="pill" data-cat="finance">Finance</span>
      <span class="pill" data-cat="general">General</span>
    </div>
  </div>

  <!-- ── Permissions Matrix ── -->
  <div class="matrix-card">
    <div class="table-scroll">
      <table id="permMatrix">
        <thead>
          <tr id="matrixHead">
            <th style="min-width:180px">User Type</th>
            <?php foreach ($modules as $mod):
              $catColor = $categoryMeta[$mod['category']]['color'] ?? '#60a5fa';
              $catLabel = $categoryMeta[$mod['category']]['label'] ?? $mod['category'];
            ?>
            <th class="mod-col" data-cat="<?= $mod['category'] ?>" data-mod-key="<?= htmlspecialchars($mod['module_key']) ?>">
              <div class="mod-col-inner">
                <i class="bi <?= htmlspecialchars($mod['icon']) ?> mod-col-icon" style="color:<?= $catColor ?>"></i>
                <div class="mod-col-name"><?= htmlspecialchars($mod['module_name']) ?></div>
                <span class="cat-badge" style="background:<?= $catColor ?>22;color:<?= $catColor ?>"><?= $catLabel ?></span>
              </div>
            </th>
            <?php endforeach; ?>
            <th class="th-actions" style="min-width:130px; text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody id="matrixBody">
          <?php foreach ($rolesFromUsers as $role):
            $rn       = $role['role_name'];
            $initials = strtoupper(substr($rn, 0, 2));
          ?>
          <tr data-role="<?= htmlspecialchars($rn) ?>">
            <td>
              <div class="role-cell">
                <div class="role-avatar"><?= $initials ?></div>
                <div>
                  <div class="role-name"><?= htmlspecialchars($rn) ?></div>
                  <div class="role-count"><?= number_format($role['total']) ?> users</div>
                </div>
              </div>
            </td>
            <?php foreach ($modules as $mod):
              $key     = $rn . '|' . $mod['module_key'];
              $checked = isset($permsMap[$key]) && $permsMap[$key] ? 'checked' : '';
            ?>
            <td class="toggle-cell" data-cat="<?= $mod['category'] ?>">
              <label class="toggle">
                <input type="checkbox" <?= $checked ?>
                       data-role="<?= htmlspecialchars($rn) ?>"
                       data-module="<?= htmlspecialchars($mod['module_key']) ?>">
                <div class="toggle-track"></div>
                <div class="toggle-thumb"></div>
              </label>
            </td>
            <?php endforeach; ?>
            <td>
              <div class="row-actions">
                <button class="btn btn-sm btn-grant-all" data-role="<?= htmlspecialchars($rn) ?>" title="Grant all modules">
                  <i class="bi bi-check-all"></i> All
                </button>
                <button class="btn btn-sm btn-revoke-all" data-role="<?= htmlspecialchars($rn) ?>" title="Revoke all modules">
                  <i class="bi bi-x-lg"></i> None
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rolesFromUsers): ?>
          <tr class="empty-row"><td colspan="<?= count($modules) + 2 ?>">No user types found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Module Registry ── -->
  <div class="modules-panel">
    <div class="panel-header">
      <div class="panel-title">
        Module Registry <span id="moduleRegistryCount">— <?= count($modules) ?> portal card<?= count($modules) !== 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="modules-grid" id="modulesGrid">
      <?php foreach ($modules as $mod):
        $catLabel = $categoryMeta[$mod['category']]['label'] ?? $mod['category'];
      ?>
      <div class="module-chip"
           data-key="<?= htmlspecialchars($mod['module_key']) ?>"
           data-name="<?= htmlspecialchars($mod['module_name']) ?>"
           data-cat="<?= htmlspecialchars($mod['category']) ?>"
           data-icon="<?= htmlspecialchars($mod['icon']) ?>"
           data-color="<?= htmlspecialchars($mod['color']) ?>"
           data-desc="<?= htmlspecialchars($mod['description'] ?? '') ?>">
        <div class="chip-top">
          <div class="module-chip-icon <?= htmlspecialchars($mod['color']) ?>">
            <i class="bi <?= htmlspecialchars($mod['icon']) ?>"></i>
          </div>
          <div class="chip-info">
            <div class="module-chip-name"><?= htmlspecialchars($mod['module_name']) ?></div>
            <div class="module-chip-key"><?= htmlspecialchars($mod['module_key']) ?></div>
          </div>
        </div>
        <?php if (!empty($mod['description'])): ?>
        <div class="chip-desc"><?= htmlspecialchars($mod['description']) ?></div>
        <?php endif; ?>
        <div class="chip-footer">
          <span class="chip-cat-badge <?= htmlspecialchars($mod['category']) ?>"><?= $catLabel ?></span>
          <div class="chip-actions">
            <button class="module-chip-edit" title="Edit module"
                    data-key="<?= htmlspecialchars($mod['module_key']) ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="module-chip-del" title="Delete module"
                    data-key="<?= htmlspecialchars($mod['module_key']) ?>"
                    data-name="<?= htmlspecialchars($mod['module_name']) ?>">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>
        <div class="preview-strip">
          <i class="bi bi-eye"></i>
          Homepage card preview ↓
        </div>
        <!-- Homepage card preview -->
        <div class="hp-card-preview" style="width:100%">
          <i class="bi <?= htmlspecialchars($mod['icon']) ?> prev-icon <?= htmlspecialchars($mod['color']) ?>"></i>
          <div class="prev-name"><?= htmlspecialchars($mod['module_name']) ?></div>
          <div class="prev-desc"><?= htmlspecialchars($mod['description'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div><!-- /.wrap -->

<!-- ══════════════════════════════════════════════════════════
     ADD MODULE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="addModuleModal">
  <div class="modal">
    <div class="modal-title">
      <i class="bi bi-plus-circle" style="color:var(--accent2)"></i>
      Add New Module
    </div>

    <!-- Live homepage card preview -->
    <div class="card-preview-wrap">
      <div class="card-preview-label"><i class="bi bi-eye"></i> &nbsp;Live Homepage Card Preview</div>
      <div class="hp-card-preview" id="addPreviewCard">
        <i class="bi bi-grid prev-icon blue" id="addPrevIcon"></i>
        <div class="prev-name" id="addPrevName">Module Name</div>
        <div class="prev-desc" id="addPrevDesc">Description appears here</div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Module Key <span style="color:var(--w40);font-weight:400;text-transform:none">(unique, no spaces)</span></label>
        <input class="form-control" id="m_key" placeholder="e.g. reports_page">
      </div>
      <div class="form-group">
        <label class="form-label">Display Name</label>
        <input class="form-control" id="m_name" placeholder="e.g. Reports">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Category</label>
        <select class="form-control" id="m_cat">
          <option value="hr">HR</option>
          <option value="fleet">Fleet</option>
          <option value="finance">Finance</option>
          <option value="general" selected>General</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Color</label>
        <select class="form-control" id="m_color">
          <option value="blue">Blue</option>
          <option value="green">Green</option>
          <option value="amber">Amber</option>
          <option value="purple">Purple</option>
        </select>
      </div>
    </div>
    <div class="form-group icon-search-wrap">
      <label class="form-label">Bootstrap Icon Class</label>
      <input class="form-control" id="m_icon" placeholder="e.g. bi-bar-chart-fill" autocomplete="off">
      <div class="icon-suggestions" id="addIconSuggestions"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Description <span style="color:var(--w40);font-weight:400;text-transform:none">(shown on homepage card)</span></label>
      <input class="form-control" id="m_desc" placeholder="Short description for the card">
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="closeAddModal">Cancel</button>
      <button class="btn btn-primary" id="saveModule">
        <i class="bi bi-check-lg"></i> Save Module
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT MODULE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="editModuleModal">
  <div class="modal">
    <div class="modal-title">
      <i class="bi bi-pencil-square" style="color:var(--amber)"></i>
      Edit Module
      <span id="editModalKeyBadge" style="font-size:.72rem;font-weight:400;color:var(--w40);margin-left:.25rem;font-family:monospace;"></span>
    </div>

    <!-- Live homepage card preview -->
    <div class="card-preview-wrap">
      <div class="card-preview-label"><i class="bi bi-eye"></i> &nbsp;Live Homepage Card Preview</div>
      <div class="hp-card-preview" id="editPreviewCard">
        <i class="bi bi-grid prev-icon blue" id="editPrevIcon"></i>
        <div class="prev-name" id="editPrevName">Module Name</div>
        <div class="prev-desc" id="editPrevDesc">Description appears here</div>
      </div>
    </div>

    <input type="hidden" id="e_key">
    <div class="form-row">
      <div class="form-group" style="flex:2">
        <label class="form-label">Display Name</label>
        <input class="form-control" id="e_name" placeholder="e.g. Reports">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Category</label>
        <select class="form-control" id="e_cat">
          <option value="hr">HR</option>
          <option value="fleet">Fleet</option>
          <option value="finance">Finance</option>
          <option value="general">General</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Color</label>
        <select class="form-control" id="e_color">
          <option value="blue">Blue</option>
          <option value="green">Green</option>
          <option value="amber">Amber</option>
          <option value="purple">Purple</option>
        </select>
      </div>
    </div>
    <div class="form-group icon-search-wrap">
      <label class="form-label">Bootstrap Icon Class</label>
      <input class="form-control" id="e_icon" placeholder="e.g. bi-bar-chart-fill" autocomplete="off">
      <div class="icon-suggestions" id="editIconSuggestions"></div>
    </div>
    <div class="form-group">
      <label class="form-label">Description <span style="color:var(--w40);font-weight:400;text-transform:none">(shown on homepage card)</span></label>
      <input class="form-control" id="e_desc" placeholder="Short description for the card">
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="closeEditModal">Cancel</button>
      <button class="btn btn-primary" id="updateModule">
        <i class="bi bi-check-lg"></i> Save Changes
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CONFIRM DELETE OVERLAY
     ══════════════════════════════════════════════════════════ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="confirm-box-icon"><i class="bi bi-trash3-fill"></i></div>
    <div class="confirm-box-title" id="confirmTitle">Delete Module?</div>
    <div class="confirm-box-msg" id="confirmMsg">This will permanently remove the module and all its permission grants.</div>
    <div class="confirm-box-actions">
      <button class="btn btn-ghost" id="confirmCancel">Cancel</button>
      <button class="btn btn-danger" id="confirmOk"><i class="bi bi-trash3"></i> Delete</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const ACTION_URL   = '<?= base_url('RBAC/rbac_action.php') ?>';
let   activeFilter = 'all';

// ── Bootstrap icon list (common subset for autocomplete) ─────
const BI_ICONS = [
  'bi-grid','bi-grid-fill','bi-house','bi-house-fill','bi-people','bi-people-fill',
  'bi-person','bi-person-fill','bi-truck','bi-truck-flatbed','bi-cash-stack',
  'bi-receipt','bi-receipt-cutoff','bi-bar-chart','bi-bar-chart-fill',
  'bi-pie-chart','bi-pie-chart-fill','bi-calendar','bi-calendar-fill',
  'bi-clipboard','bi-clipboard-fill','bi-file-text','bi-file-text-fill',
  'bi-gear','bi-gear-fill','bi-shield','bi-shield-fill','bi-shield-lock',
  'bi-lock','bi-lock-fill','bi-key','bi-key-fill','bi-bell','bi-bell-fill',
  'bi-envelope','bi-envelope-fill','bi-chat','bi-chat-fill','bi-briefcase',
  'bi-briefcase-fill','bi-building','bi-buildings','bi-box','bi-boxes',
  'bi-cart','bi-cart-fill','bi-credit-card','bi-credit-card-fill',
  'bi-bank','bi-bank2','bi-currency-dollar','bi-currency-exchange',
  'bi-graph-up','bi-graph-down','bi-activity','bi-speedometer',
  'bi-map','bi-map-fill','bi-geo-alt','bi-geo-alt-fill',
  'bi-tools','bi-wrench','bi-hammer','bi-cpu','bi-laptop',
  'bi-phone','bi-tablet','bi-display','bi-archive','bi-archive-fill',
  'bi-bookmark','bi-bookmark-fill','bi-star','bi-star-fill',
  'bi-award','bi-award-fill','bi-trophy','bi-trophy-fill',
  'bi-tag','bi-tags','bi-flag','bi-flag-fill',
  'bi-check-circle','bi-check-circle-fill','bi-x-circle','bi-x-circle-fill',
  'bi-exclamation-triangle','bi-info-circle','bi-question-circle',
  'bi-list-check','bi-list-ul','bi-table','bi-kanban',
  'bi-clipboard-data','bi-clipboard-check','bi-person-badge',
  'bi-person-lines-fill','bi-person-workspace','bi-headset',
  'bi-fuel-pump','bi-ev-front','bi-car-front','bi-bicycle',
  'bi-airplane','bi-train-front','bi-bus-front',
];

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const wrap = document.getElementById('toastWrap');
  const el   = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="bi ${type === 'success' ? 'bi-check-circle-fill success' : 'bi-x-circle-fill error'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut .25s ease forwards';
    setTimeout(() => el.remove(), 260);
  }, 2800);
}

// ── Confirm dialog ────────────────────────────────────────────
function confirmDialog(title, msg) {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent   = msg;
    const overlay = document.getElementById('confirmOverlay');
    overlay.classList.add('open');
    const ok     = document.getElementById('confirmOk');
    const cancel = document.getElementById('confirmCancel');
    function cleanup(result) {
      overlay.classList.remove('open');
      ok.removeEventListener('click', onOk);
      cancel.removeEventListener('click', onCancel);
      resolve(result);
    }
    const onOk     = () => cleanup(true);
    const onCancel = () => cleanup(false);
    ok.addEventListener('click', onOk);
    cancel.addEventListener('click', onCancel);
  });
}

// ── Live preview updater ──────────────────────────────────────
function updatePreview(prefix) {
  const icon  = document.getElementById(prefix + '_icon')?.value.trim()  || 'bi-grid';
  const name  = document.getElementById(prefix + '_name')?.value.trim()  || 'Module Name';
  const color = document.getElementById(prefix + '_color')?.value        || 'blue';
  const desc  = document.getElementById(prefix + '_desc')?.value.trim()  || '';

  const iEl = document.getElementById(prefix + 'PrevIcon');
  const nEl = document.getElementById(prefix + 'PrevName');
  const dEl = document.getElementById(prefix + 'PrevDesc');
  if (!iEl) return;

  iEl.className = `bi ${icon} prev-icon ${color}`;
  nEl.textContent = name || 'Module Name';
  dEl.textContent = desc || '';
}

// Map modal field IDs — add uses m_, edit uses e_
function wirePreview(prefix, fieldPrefix) {
  ['_icon', '_name', '_color', '_desc'].forEach(f => {
    const el = document.getElementById(fieldPrefix + f);
    if (el) el.addEventListener('input', () => updatePreview(prefix));
    if (el && el.tagName === 'SELECT') el.addEventListener('change', () => updatePreview(prefix));
  });
}
wirePreview('add', 'm');
wirePreview('edit', 'e');

// ── Icon autocomplete ─────────────────────────────────────────
function setupIconSearch(inputId, suggestionsId, previewPrefix, fieldPrefix) {
  const input = document.getElementById(inputId);
  const box   = document.getElementById(suggestionsId);

  input.addEventListener('input', () => {
    const q = input.value.toLowerCase().replace(/^bi-/, '');
    if (!q) { box.classList.remove('open'); return; }
    const matches = BI_ICONS.filter(ic => ic.includes(q)).slice(0, 8);
    if (!matches.length) { box.classList.remove('open'); return; }
    box.innerHTML = matches.map(ic =>
      `<div class="icon-sug-item" data-icon="${ic}">
         <i class="bi ${ic}"></i><span>${ic}</span>
       </div>`
    ).join('');
    box.classList.add('open');
  });

  box.addEventListener('click', e => {
    const item = e.target.closest('.icon-sug-item');
    if (!item) return;
    input.value = item.dataset.icon;
    box.classList.remove('open');
    updatePreview(previewPrefix);
  });

  document.addEventListener('click', e => {
    if (!input.contains(e.target) && !box.contains(e.target)) box.classList.remove('open');
  });
}
setupIconSearch('m_icon', 'addIconSuggestions',  'add',  'm');
setupIconSearch('e_icon', 'editIconSuggestions', 'edit', 'e');

// ── Grant count helper ────────────────────────────────────────
function recountGrants() {
  const checked = document.querySelectorAll('#permMatrix input[type=checkbox]:checked').length;
  document.getElementById('statGrantCount').textContent = checked;
}

// ── Toggle permission ─────────────────────────────────────────
document.getElementById('permMatrix').addEventListener('change', async function(e) {
  const cb = e.target;
  if (cb.type !== 'checkbox') return;
  const row    = cb.closest('tr');
  const role   = cb.dataset.role;
  const module = cb.dataset.module;
  const action = cb.checked ? 'grant' : 'revoke';

  row.classList.add('saving');
  try {
    const fd = new FormData();
    fd.append('action', action); fd.append('role', role); fd.append('module', module);
    const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      toast(`${action === 'grant' ? 'Granted' : 'Revoked'} <strong>${module}</strong> for <strong>${role}</strong>`);
      recountGrants();
    } else {
      toast(data.msg || 'Error saving.', 'error');
      cb.checked = !cb.checked;
    }
  } catch(err) {
    toast('Network error.', 'error');
    cb.checked = !cb.checked;
  }
  row.classList.remove('saving');
});

// ── Grant All / Revoke All ────────────────────────────────────
document.getElementById('matrixBody').addEventListener('click', async e => {
  const grantBtn  = e.target.closest('.btn-grant-all');
  const revokeBtn = e.target.closest('.btn-revoke-all');
  if (!grantBtn && !revokeBtn) return;

  const btn    = grantBtn || revokeBtn;
  const role   = btn.dataset.role;
  const action = grantBtn ? 'grant_all' : 'revoke_all';
  const label  = grantBtn ? 'Grant all' : 'Revoke all';

  const row = btn.closest('tr');
  row.classList.add('saving');
  try {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('role',   role);
    // NOTE: no 'module' field needed — backend handles all modules internally
    const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      // Update ALL toggles in this row — including hidden (filtered) ones
      row.querySelectorAll('input[type=checkbox]').forEach(cb => {
        cb.checked = !!grantBtn;
      });
      recountGrants();
      toast(`${label} modules ${grantBtn ? 'granted' : 'revoked'} for <strong>${role}</strong>`);
    } else {
      toast(data.msg || 'Error.', 'error');
    }
  } catch(err) {
    toast('Network error.', 'error');
  }
  row.classList.remove('saving');
});

// ── Role search ───────────────────────────────────────────────
document.getElementById('roleSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#matrixBody tr').forEach(tr => {
    tr.style.display = (tr.dataset.role || '').toLowerCase().includes(q) ? '' : 'none';
  });
});

// ── Category filter ───────────────────────────────────────────
document.querySelectorAll('.pill').forEach(pill => {
  pill.addEventListener('click', function() {
    document.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    activeFilter = this.dataset.cat;
    document.querySelectorAll('th.mod-col, td.toggle-cell').forEach(el => {
      el.style.display = (activeFilter === 'all' || el.dataset.cat === activeFilter) ? '' : 'none';
    });
  });
});

// ── Add Module Modal ──────────────────────────────────────────
const addModal = document.getElementById('addModuleModal');
document.getElementById('btnAddModule').addEventListener('click', () => {
  // Reset form
  ['m_key','m_name','m_icon','m_desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('m_cat').value   = 'general';
  document.getElementById('m_color').value = 'blue';
  updatePreview('add');
  addModal.classList.add('open');
});
document.getElementById('closeAddModal').addEventListener('click', () => addModal.classList.remove('open'));
addModal.addEventListener('click', e => { if (e.target === addModal) addModal.classList.remove('open'); });

document.getElementById('saveModule').addEventListener('click', async () => {
  const key   = document.getElementById('m_key').value.trim();
  const name  = document.getElementById('m_name').value.trim();
  const cat   = document.getElementById('m_cat').value;
  const color = document.getElementById('m_color').value;
  const icon  = document.getElementById('m_icon').value.trim() || 'bi-grid';
  const desc  = document.getElementById('m_desc').value.trim();

  if (!key || !name) { toast('Key and Name are required.', 'error'); return; }
  if (/\s/.test(key)) { toast('Module key cannot contain spaces.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',      'add_module');
  fd.append('module_key',  key);
  fd.append('module_name', name);
  fd.append('category',    cat);
  fd.append('color',       color);
  fd.append('icon',        icon);
  fd.append('description', desc);

  const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error adding module.', 'error'); return; }

  addModal.classList.remove('open');
  toast(`Module <strong>${name}</strong> added successfully.`);

  // ── 1. Add chip to Module Registry ──────────────────────
  const catLabels = {hr:'HR', fleet:'Fleet', finance:'Finance', general:'General'};
  const chip = document.createElement('div');
  chip.className = 'module-chip';
  chip.dataset.key   = key;
  chip.dataset.name  = name;
  chip.dataset.cat   = cat;
  chip.dataset.icon  = icon;
  chip.dataset.color = color;
  chip.dataset.desc  = desc;
  chip.innerHTML = `
    <div class="chip-top">
      <div class="module-chip-icon ${color}"><i class="bi ${icon}"></i></div>
      <div class="chip-info">
        <div class="module-chip-name">${name}</div>
        <div class="module-chip-key">${key}</div>
      </div>
    </div>
    ${desc ? `<div class="chip-desc">${desc}</div>` : ''}
    <div class="chip-footer">
      <span class="chip-cat-badge ${cat}">${catLabels[cat]||cat}</span>
      <div class="chip-actions">
        <button class="module-chip-edit" title="Edit module" data-key="${key}"><i class="bi bi-pencil"></i></button>
        <button class="module-chip-del"  title="Delete module" data-key="${key}" data-name="${name}"><i class="bi bi-trash3"></i></button>
      </div>
    </div>
    <div class="preview-strip"><i class="bi bi-eye"></i> Homepage card preview ↓</div>
    <div class="hp-card-preview" style="width:100%">
      <i class="bi ${icon} prev-icon ${color}"></i>
      <div class="prev-name">${name}</div>
      <div class="prev-desc">${desc}</div>
    </div>`;
  document.getElementById('modulesGrid').appendChild(chip);

  // ── 2. Inject column into matrix table header ────────────
  const catColors = {hr:'#34d399', fleet:'#fbbf24', finance:'#a78bfa', general:'#60a5fa'};
  const catColor  = catColors[cat] || '#60a5fa';
  const catLabel  = catLabels[cat] || cat;
  const newTh = document.createElement('th');
  newTh.className = 'mod-col';
  newTh.dataset.cat    = cat;
  newTh.dataset.modKey = key;
  // Insert before the last th (Actions)
  const actTh = document.querySelector('thead tr .th-actions');
  newTh.innerHTML = `
    <div class="mod-col-inner">
      <i class="bi ${icon} mod-col-icon" style="color:${catColor}"></i>
      <div class="mod-col-name">${name}</div>
      <span class="cat-badge" style="background:${catColor}22;color:${catColor}">${catLabel}</span>
    </div>`;
  actTh.before(newTh);

  // ── 3. Inject toggle cell into every data row ─────────────
  document.querySelectorAll('#matrixBody tr[data-role]').forEach(tr => {
    const role   = tr.dataset.role;
    const newTd  = document.createElement('td');
    newTd.className    = 'toggle-cell';
    newTd.dataset.cat  = cat;
    newTd.innerHTML = `
      <label class="toggle">
        <input type="checkbox" data-role="${role}" data-module="${key}">
        <div class="toggle-track"></div>
        <div class="toggle-thumb"></div>
      </label>`;
    // Insert before last td (actions)
    tr.lastElementChild.before(newTd);
  });

  // ── 4. Apply current filter to new elements ───────────────
  if (activeFilter !== 'all') {
    document.querySelectorAll(`th[data-mod-key="${key}"], td.toggle-cell`).forEach(el => {
      el.style.display = el.dataset.cat === activeFilter ? '' : 'none';
    });
  }

  // ── 5. Update stat counters ───────────────────────────────
  const modCount = document.querySelectorAll('#modulesGrid .module-chip').length;
  document.getElementById('statModuleCount').textContent   = modCount;
  document.getElementById('moduleRegistryCount').textContent = `— ${modCount} portal card${modCount !== 1 ? 's' : ''}`;
});

// ── Edit Module Modal ─────────────────────────────────────────
const editModal = document.getElementById('editModuleModal');

document.getElementById('modulesGrid').addEventListener('click', e => {
  const editBtn = e.target.closest('.module-chip-edit');
  if (!editBtn) return;

  const chip = editBtn.closest('.module-chip');
  const key  = chip.dataset.key;

  // Pre-fill fields from chip data attributes
  document.getElementById('e_key').value   = key;
  document.getElementById('e_name').value  = chip.dataset.name  || '';
  document.getElementById('e_cat').value   = chip.dataset.cat   || 'general';
  document.getElementById('e_color').value = chip.dataset.color || 'blue';
  document.getElementById('e_icon').value  = chip.dataset.icon  || 'bi-grid';
  document.getElementById('e_desc').value  = chip.dataset.desc  || '';

  document.getElementById('editModalKeyBadge').textContent = key;
  updatePreview('edit');
  editModal.classList.add('open');
});
document.getElementById('closeEditModal').addEventListener('click', () => editModal.classList.remove('open'));
editModal.addEventListener('click', e => { if (e.target === editModal) editModal.classList.remove('open'); });

document.getElementById('updateModule').addEventListener('click', async () => {
  const key   = document.getElementById('e_key').value.trim();
  const name  = document.getElementById('e_name').value.trim();
  const cat   = document.getElementById('e_cat').value;
  const color = document.getElementById('e_color').value;
  const icon  = document.getElementById('e_icon').value.trim() || 'bi-grid';
  const desc  = document.getElementById('e_desc').value.trim();

  if (!name) { toast('Display name is required.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',      'edit_module');
  fd.append('module_key',  key);
  fd.append('module_name', name);
  fd.append('category',    cat);
  fd.append('color',       color);
  fd.append('icon',        icon);
  fd.append('description', desc);

  const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error updating module.', 'error'); return; }

  editModal.classList.remove('open');
  toast(`Module <strong>${name}</strong> updated.`);

  const catColors = {hr:'#34d399', fleet:'#fbbf24', finance:'#a78bfa', general:'#60a5fa'};
  const catLabels = {hr:'HR', fleet:'Fleet', finance:'Finance', general:'General'};
  const catColor  = catColors[cat] || '#60a5fa';
  const catLabel  = catLabels[cat] || cat;

  // ── Update chip in registry ──────────────────────────────
  const chip = document.querySelector(`.module-chip[data-key="${key}"]`);
  if (chip) {
    chip.dataset.name  = name;
    chip.dataset.cat   = cat;
    chip.dataset.icon  = icon;
    chip.dataset.color = color;
    chip.dataset.desc  = desc;

    chip.querySelector('.module-chip-icon').className = `module-chip-icon ${color}`;
    chip.querySelector('.module-chip-icon i').className = `bi ${icon}`;
    chip.querySelector('.module-chip-name').textContent = name;
    chip.querySelector('.chip-desc') && (chip.querySelector('.chip-desc').textContent = desc);
    chip.querySelector('.chip-cat-badge').className   = `chip-cat-badge ${cat}`;
    chip.querySelector('.chip-cat-badge').textContent  = catLabel;

    // Update homepage preview inside chip
    const prevIcon = chip.querySelector('.hp-card-preview .prev-icon');
    const prevName = chip.querySelector('.hp-card-preview .prev-name');
    const prevDesc = chip.querySelector('.hp-card-preview .prev-desc');
    if (prevIcon) prevIcon.className  = `bi ${icon} prev-icon ${color}`;
    if (prevName) prevName.textContent = name;
    if (prevDesc) prevDesc.textContent = desc;
  }

  // ── Update matrix column header ───────────────────────────
  const th = document.querySelector(`th.mod-col[data-mod-key="${key}"]`);
  if (th) {
    th.dataset.cat = cat;
    th.innerHTML = `
      <div class="mod-col-inner">
        <i class="bi ${icon} mod-col-icon" style="color:${catColor}"></i>
        <div class="mod-col-name">${name}</div>
        <span class="cat-badge" style="background:${catColor}22;color:${catColor}">${catLabel}</span>
      </div>`;
    // Apply filter
    th.style.display = (activeFilter === 'all' || th.dataset.cat === activeFilter) ? '' : 'none';
    // Update toggle-cell data-cat for this column
    const colIdx = Array.from(th.parentElement.children).indexOf(th);
    document.querySelectorAll('#matrixBody tr[data-role]').forEach(tr => {
      const td = tr.children[colIdx];
      if (td) { td.dataset.cat = cat; td.style.display = th.style.display; }
    });
  }
});

// ── Delete Module ─────────────────────────────────────────────
document.getElementById('modulesGrid').addEventListener('click', async e => {
  const btn = e.target.closest('.module-chip-del');
  if (!btn) return;
  const key  = btn.dataset.key;
  const name = btn.dataset.name || key;

  const confirmed = await confirmDialog(
    `Delete "${name}"?`,
    `This will permanently remove the module and revoke all role permissions for it.`
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action',     'delete_module');
  fd.append('module_key', key);
  const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
  const data = await res.json();

  if (!data.ok) { toast(data.msg || 'Error deleting.', 'error'); return; }

  // Remove chip
  btn.closest('.module-chip').remove();
  // Remove matrix column header
  const th = document.querySelector(`th.mod-col[data-mod-key="${key}"]`);
  if (th) {
    const colIdx = Array.from(th.parentElement.children).indexOf(th);
    th.remove();
    document.querySelectorAll('#matrixBody tr[data-role]').forEach(tr => {
      tr.children[colIdx]?.remove();
    });
  }

  const modCount = document.querySelectorAll('#modulesGrid .module-chip').length;
  document.getElementById('statModuleCount').textContent   = modCount;
  document.getElementById('moduleRegistryCount').textContent = `— ${modCount} portal card${modCount !== 1 ? 's' : ''}`;
  recountGrants();
  toast(`Module <strong>${name}</strong> deleted.`);
});
</script>

</body>
</html>