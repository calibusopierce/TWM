<?php
date_default_timezone_set('Asia/Manila');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/test_sqlsrv.php'; 
require_once __DIR__ . '/RBAC/rbac_helper.php';

auth_check(); // RBAC handles module-level access; just verify login + session

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$department  = $_SESSION['Department']  ?? '';
$employeeId  = $_SESSION['EmployeeID']  ?? '';
$username    = $_SESSION['Username']    ?? '';

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// Bust RBAC cache on homepage load so admin changes reflect immediately
unset($_SESSION['rbac_permissions_uid_' . ($_SESSION['UserID'] ?? 0)]);

$permissions = rbac_load_permissions($pdo, $userType);

// Filter out nav-only modules before building homepage sections
$navOnlyModules = ['RBAC'];
$homepagePerms  = array_filter($permissions, fn($k) => !in_array($k, $navOnlyModules, true), ARRAY_FILTER_USE_KEY);

$sections   = rbac_get_sections($pdo, $homepagePerms);
$totalCards = array_sum(array_map(fn($s) => count($s['cards']), $sections));

// Flatten cards (already RBAC-filtered) for the client-side "recently used" row.
$legacyColorMap = ['blue'=>'#60a5fa','green'=>'#34d399','amber'=>'#fbbf24','purple'=>'#a78bfa'];
$allCardsForJs  = [];
foreach ($sections as $section) {
    foreach ($section['cards'] as $card) {
        $colorVal = $card['color'] ?? 'blue';
        $allCardsForJs[] = [
            'url'   => $card['url'],
            'name'  => $card['module_name'],
            'desc'  => $card['description'] ?? '',
            'icon'  => $card['icon'],
            'color' => $legacyColorMap[$colorVal] ?? $colorVal,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Home · Tradewell Admin</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <style>
    :root {
      --blue-deep:   #08173d;
      --blue-bright: #4380e2;
      --blue-light:  #93c5fd;
      --white:       #ffffff;
      --w10: rgba(255,255,255,0.10);
      --w15: rgba(255,255,255,0.15);
      --w25: rgba(255,255,255,0.25);
      --w60: rgba(255,255,255,0.60);
      --w80: rgba(255,255,255,0.80);

      --cat-hr-bg:      rgba(52,211,153,0.10);
      --cat-fleet-bg:   rgba(251,191,36,0.10);
      --cat-finance-bg: rgba(167,139,250,0.10);
      --cat-customers-bg: rgba(248,113,113,0.10);
      --cat-general-bg: rgba(96,165,250,0.10);

      --cat-hr-bdr:      rgba(52,211,153,0.28);
      --cat-fleet-bdr:   rgba(251,191,36,0.28);
      --cat-finance-bdr: rgba(167,139,250,0.28);
      --cat-customers-bdr: rgba(248,113,113,0.28);
      --cat-general-bdr: rgba(96,165,250,0.28);

      --cat-hr:      #34d399;
      --cat-fleet:   #fbbf24;
      --cat-finance: #a78bfa;
      --cat-sales: #2dd4bf;
      --cat-sales-bg: rgba(45,212,191,0.10);
      --cat-sales-bdr: rgba(45,212,191,0.28);
      --cat-customers: #f87171;
      --cat-general: #60a5fa;
      --cat-accounting-bg:  rgba(251,146,60,0.10);
      --cat-accounting-bdr: rgba(251,146,60,0.28);
      --cat-accounting:     #fb923c;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      overflow-y: auto;
      overflow-x: hidden;
      scroll-behavior: smooth;
      /* Static gradient — same colors, zero GPU animation overhead */
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      background-attachment: fixed;
    }

    /* ── Layout ── */
    .page {
      display: flex; flex-direction: column;
      align-items: center;
      min-height: 100vh;
      padding: 2rem 2rem 3rem;
      gap: 1.75rem;
    }

    /* ── Top bar (replaces old tall centered header) ── */
    .topbar {
      width: 100%; max-width: 1480px;
      display: flex; align-items: center; justify-content: space-between; gap: 1rem;
      animation: fadeUp .4s ease both;
      position: relative; z-index: 100;
      padding-bottom: 1.1rem;
      border-bottom: 1px solid var(--w15);
    }
    .topbar-brand { display: flex; align-items: center; gap: .65rem; flex-shrink: 0; }
    .topbar-logo {
      display: inline-flex; align-items: center; justify-content: center;
      width: 42px; height: 42px; border-radius: 50%;
      background: var(--w10); border: 1px solid var(--w25); flex-shrink: 0;
    }
    .topbar-logo img { width: 26px; height: 26px; object-fit: contain; }
    .topbar-titles { line-height: 1.15; }
    .topbar-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.05rem; font-weight: 800;
      color: var(--white); letter-spacing: -.03em;
    }
    .topbar-subtitle { font-size: .68rem; color: var(--w60); }

    .topbar-search-wrap { flex: 1; max-width: 380px; margin: 0 auto; }

    /* ── Profile / user menu ── */
    .topbar-user { position: relative; flex-shrink: 0; }
    .user-trigger {
      display: flex; align-items: center; gap: .5rem;
      background: rgba(255,255,255,.08); border: 1px solid var(--w15);
      border-radius: 999px; padding: .35rem .8rem .35rem .35rem;
      cursor: pointer; color: var(--white);
      font-family: 'DM Sans', sans-serif; font-size: .82rem;
      transition: background .15s, border-color .15s;
    }
    .user-trigger:hover { background: rgba(255,255,255,.13); border-color: rgba(255,255,255,.26); }
    .user-avatar {
      display: flex; align-items: center; justify-content: center;
      width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0;
      background: rgba(67,128,226,.35);
      font-weight: 700; font-size: .72rem; color: var(--blue-light);
    }
    .user-name {
      font-weight: 600; white-space: nowrap;
    }
    .user-trigger .bi-chevron-down { font-size: .7rem; color: var(--w60); transition: transform .18s; }
    .user-trigger[aria-expanded="true"] .bi-chevron-down { transform: rotate(180deg); }

    .user-dropdown {
      position: absolute; top: calc(100% + .5rem); right: 0;
      min-width: 220px;
      background: #0a1533;
      border: 1px solid var(--w25); border-radius: 12px;
      box-shadow: 0 12px 32px rgba(0,0,0,.5);
      padding: .6rem .65rem;
      opacity: 0; visibility: hidden; transform: translateY(-6px);
      transition: opacity .15s, transform .15s, visibility .15s;
      z-index: 200;
    }
    .user-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0); }
    .user-dropdown-name { color: var(--white); font-weight: 700; font-size: .82rem; margin-bottom: .3rem; }
    .user-badge {
      display: inline-block;
      padding: .12rem .6rem;
      background: rgba(67,128,226,.25);
      border: 1px solid rgba(147,197,253,.3);
      border-radius: 999px;
      font-size: .68rem; font-weight: 600;
      color: var(--blue-light); letter-spacing: .04em;
    }
    .last-login {
      display: block; margin-top: .35rem;
      font-size: .68rem; color: var(--w60); letter-spacing: .02em;
    }
    .last-login i { font-size: .66rem; margin-right: .2rem; }
    .user-detail-row {
      display: flex; align-items: center; justify-content: space-between; gap: .5rem;
      font-size: .72rem; color: var(--w80);
      margin-top: .3rem;
    }
    .user-detail-row .label { color: var(--w60); }
    .user-detail-row .value { color: var(--white); font-weight: 600; }
    .user-dropdown-divider { height: 1px; background: var(--w10); margin: .45rem 0 .3rem; }
    .user-dropdown-item {
      display: flex; align-items: center; gap: .5rem;
      padding: .4rem .4rem; border-radius: 8px;
      color: var(--white); text-decoration: none; font-size: .8rem;
      transition: background .15s;
    }
    .user-dropdown-item:hover { background: rgba(255,255,255,.08); }
    .user-dropdown-item.logout-item { color: #fca5a5; }
    .user-dropdown-item.logout-item:hover { background: rgba(239,68,68,.14); }

    /* ── Quick access (recently used) ── */
    .hub-quick-access { width: 100%; max-width: 1480px; animation: fadeUp .4s .06s ease both; position: relative; z-index: 40; }
    .qa-label {
      display: flex; align-items: center; gap: .4rem;
      font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
      color: var(--w60); margin-bottom: .6rem;
    }
    .qa-grid {
      display: flex; flex-wrap: wrap; gap: .5rem;
    }
    .qa-chip {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .4rem .8rem .4rem .6rem;
      background: rgba(255,255,255,0.07); border: 1px solid var(--w15);
      border-radius: 999px; color: var(--white); text-decoration: none;
      font-size: .78rem; font-weight: 600;
      transition: background .15s, border-color .15s;
    }
    .qa-chip:hover { background: rgba(255,255,255,0.13); border-color: rgba(255,255,255,.26); }
    .qa-chip i { font-size: .95rem; }

    /* ── Welcome strip (brought back from the old header, condensed) ── */
    .welcome-strip {
      width: 100%; max-width: 1480px;
      display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
      font-size: .85rem; color: var(--w80);
      margin-top: -.5rem;
      animation: fadeUp .4s .04s ease both;
    }
    .welcome-strip strong { color: var(--white); }
    .welcome-strip .user-badge { font-size: .68rem; padding: .12rem .6rem; }

    /* ── Search bar ── */
    .hub-search {
      animation: fadeUp .4s .08s ease both;
    }
    .hub-search {
      position: relative; display: flex; align-items: center;
    }
    .hub-search .si {
      position: absolute; left: .85rem;
      font-size: .95rem; color: var(--w60); pointer-events: none;
    }
    .hub-search input {
      width: 100%;
      padding: .62rem .9rem .62rem 2.35rem;
      background: rgba(255,255,255,0.10);
      border: 1px solid var(--w15);
      border-radius: 12px;
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      font-size: .87rem;
      outline: none;
      transition: background .15s, border-color .15s;
    }
    .hub-search input::placeholder { color: var(--w60); }
    .hub-search input:focus {
      background: rgba(255,255,255,0.14);
      border-color: rgba(147,197,253,.4);
    }
    .hub-search-clear {
      position: absolute; right: .7rem;
      background: none; border: none;
      color: var(--w60); cursor: pointer;
      font-size: .88rem; padding: 0; line-height: 1;
      display: none;
    }
    .hub-search-clear.visible { display: block; }
    #search-status {
      margin-top: .45rem; font-size: .74rem; color: var(--w60);
      text-align: center; min-height: 1.1em;
    }

    /* ── Section container ── */
    .hub-sections {
      display: flex; flex-direction: column; gap: 1.25rem;
      width: 100%; max-width: 1480px;
      position: relative; z-index: 1;
    }
    .hub-section {
      background: rgba(255,255,255,0.06);
      border-radius: 18px; border: 1px solid var(--w10);
      overflow: hidden;
      box-shadow: 0 3px 14px rgba(8,23,61,.18);
    }
    /* staggered fade-in */
    .hub-section { animation: fadeUp .4s ease both; }
    .hub-section:nth-child(1) { animation-delay: .10s; }
    .hub-section:nth-child(2) { animation-delay: .18s; }
    .hub-section:nth-child(3) { animation-delay: .26s; }
    .hub-section:nth-child(4) { animation-delay: .34s; }
    .hub-section:nth-child(n+5) { animation-delay: .40s; }

    .hub-section.cat-hr      { border-top: 2px solid var(--cat-hr); }
    .hub-section.cat-fleet   { border-top: 2px solid var(--cat-fleet); }
    .hub-section.cat-finance { border-top: 2px solid var(--cat-finance); }
    .hub-section.cat-sales   { border-top: 2px solid var(--cat-sales); }
    .hub-section.cat-customers { border-top: 2px solid var(--cat-customers); }
     .hub-section.cat-general { border-top: 2px solid var(--cat-general); }
    .hub-section.cat-accounting { border-top: 2px solid var(--cat-accounting); }

    /* ── Clickable section header (collapse/expand) ── */
    .section-header {
      display: flex; align-items: center; gap: .6rem;
      padding: .9rem 1.4rem;
      cursor: pointer; user-select: none;
      transition: background .15s;
    }
    .section-header:hover { background: rgba(255,255,255,0.04); }

    .section-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 8px;
      font-size: .9rem; flex-shrink: 0;
    }
    .cat-hr      .section-icon { background: var(--cat-hr-bg);      color: var(--cat-hr);      border: 1px solid var(--cat-hr-bdr); }
    .cat-fleet   .section-icon { background: var(--cat-fleet-bg);   color: var(--cat-fleet);   border: 1px solid var(--cat-fleet-bdr); }
    .cat-finance .section-icon { background: var(--cat-finance-bg); color: var(--cat-finance); border: 1px solid var(--cat-finance-bdr); }
    .cat-sales   .section-icon { background: var(--cat-sales-bg);   color: var(--cat-sales);   border: 1px solid var(--cat-sales-bdr); }
    .cat-customers .section-icon { background: var(--cat-customers-bg); color: var(--cat-customers); border: 1px solid var(--cat-customers-bdr); }
    .cat-general .section-icon { background: var(--cat-general-bg); color: var(--cat-general); border: 1px solid var(--cat-general-bdr); }
    .cat-accounting .section-icon { background: var(--cat-accounting-bg); color: var(--cat-accounting); border: 1px solid var(--cat-accounting-bdr); }

    .section-label {
      font-family: 'Sora', sans-serif;
      font-size: .75rem; font-weight: 700;
      letter-spacing: .08em; text-transform: uppercase;
    }
    .cat-hr      .section-label { color: var(--cat-hr); }
    .cat-fleet   .section-label { color: var(--cat-fleet); }
    .cat-finance .section-label { color: var(--cat-finance); }
    .cat-sales   .section-label { color: var(--cat-sales); }
    .cat-customers .section-label { color: var(--cat-customers); }
    .cat-general .section-label { color: var(--cat-general); }
    .cat-accounting .section-label { color: var(--cat-accounting); }

    .section-count {
      font-size: .67rem; font-weight: 600;
      padding: .12rem .48rem;
      border-radius: 999px;
      background: var(--w10);
      color: var(--w60);
    }

    .section-divider { flex: 1; height: 1px; background: var(--w10); }

    .section-chevron {
      font-size: .78rem; color: var(--w60);
      transition: transform .22s;
      flex-shrink: 0;
    }
    .hub-section.collapsed .section-chevron { transform: rotate(-90deg); }

    /* ── Collapsible body ── */
    .section-body {
      max-height: 9999px; opacity: 1;
      transition: max-height .28s ease, opacity .22s ease;
      overflow: visible;
    }
    .hub-section.collapsed .section-body { max-height: 0; opacity: 0; }

    .section-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(clamp(96px, 14vw, 150px), 1fr));
      gap: .8rem;
      padding: 0 1.4rem 1.4rem;
    }

    /* ── Cards — no backdrop-filter (was the main GPU hog) ── */
    .hub-card {
      background: rgba(255,255,255,0.08);
      border: 1px solid var(--w15); border-radius: 12px;
      padding: clamp(.75rem, 1.6vw + .3rem, 1rem) clamp(.6rem, 1.2vw + .25rem, .9rem);
      text-align: center; text-decoration: none; color: var(--white);
      display: flex; flex-direction: column; align-items: center;
      transition: transform .16s, box-shadow .16s, background .16s, border-color .16s;
      box-shadow: 0 2px 10px rgba(8,23,61,.15);
      will-change: transform;
    }
    .hub-card:hover {
      transform: translateY(-4px);
      background: rgba(255,255,255,0.13);
      border-color: rgba(255,255,255,.26);
      box-shadow: 0 9px 26px rgba(8,23,61,.35);
    }
    .hub-card:active { transform: translateY(-1px); }
    .hub-card.search-hidden { display: none; }
    .hub-section.search-hidden { display: none; }

    .card-icon { font-size: clamp(1.3rem, 1.7vw + .6rem, 1.55rem); margin-bottom: .5rem; display: block; line-height: 1; }
    .card-name { font-family: 'Sora', sans-serif; font-size: clamp(.76rem, .5vw + .68rem, .82rem); font-weight: 700; margin-bottom: .22rem; }
    .card-desc { font-size: clamp(.64rem, .3vw + .6rem, .68rem); color: var(--w60); line-height: 1.45; }

    /* section-level empty message shown during search */
    .section-empty {
      display: none; padding: .6rem 1.4rem 1.1rem;
      font-size: .78rem; color: var(--w60); font-style: italic;
    }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(13px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* No-modules state */
    .hub-empty {
      text-align: center; color: var(--w60);
      padding: 2.5rem; font-size: .88rem;
    }
    .hub-empty i { font-size: 2rem; display: block; margin-bottom: .75rem; }

    /* Global no-results banner */
    #no-results {
      display: none; text-align: center;
      color: var(--w60); font-size: .85rem; padding: .5rem 0;
    }

    @media (max-width: 600px) {
      .topbar { flex-wrap: wrap; gap: .6rem; }
      .topbar-subtitle { display: none; }
      .topbar-search-wrap { order: 3; max-width: 100%; flex-basis: 100%; }
      .user-name { display: none; }
      .page { padding: 1.25rem 1rem 2rem; }
      .section-grid { padding: 0 1rem 1.2rem; gap: .6rem; }
      .section-header { padding: .8rem 1rem; }
    }
    @media (max-width: 420px) {
      .page { padding: 1rem .75rem 1.75rem; gap: 1.25rem; }
      .section-grid { padding: 0 .75rem 1rem; gap: .55rem; }
      .user-dropdown { min-width: 0; width: calc(100vw - 1.5rem); right: -.75rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      *, .hub-section, .topbar, .hub-quick-access {
        animation: none !important; transition: none !important;
      }
    }
  </style>
</head>
<body>

<div class="page">

  <!-- ── Top bar: brand, search, profile ── -->
  <div class="topbar">
    <div class="topbar-brand">
      <div class="topbar-logo">
        <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo"
             onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-briefcase-fill\' style=\'font-size:1.1rem;color:#fff;\'></i>';">
      </div>
      <div class="topbar-titles">
        <div class="topbar-title">Admin Portal</div>
        <div class="topbar-subtitle">Urban Tradewell Corporation</div>
      </div>
    </div>

    <?php if ($totalCards > 6): ?>
    <div class="topbar-search-wrap">
      <div class="hub-search">
        <i class="bi bi-search si"></i>
        <input type="text" id="mod-search" placeholder="Search modules…" autocomplete="off" spellcheck="false">
        <button class="hub-search-clear" id="search-clear" title="Clear">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>
    <?php endif; ?>

    <div class="topbar-user" id="user-menu">
      <button class="user-trigger" onclick="toggleUserMenu()" aria-expanded="false" aria-haspopup="true">
        <span class="user-avatar"><?= htmlspecialchars(strtoupper(substr($displayName, 0, 1))) ?></span>
        <span class="user-name"><?= htmlspecialchars($displayName) ?></span>
        <i class="bi bi-chevron-down"></i>
      </button>
      <div class="user-dropdown" id="user-dropdown">
        <div class="user-dropdown-name"><?= $greeting ?>, <?= htmlspecialchars($displayName) ?></div>
        <span class="user-badge">
          <?= htmlspecialchars($userType) ?>
          <?php if ($department): ?>&nbsp;·&nbsp;<?= htmlspecialchars($department) ?><?php endif; ?>
        </span>
        <span class="last-login">
          <i class="bi bi-clock"></i>
          Session started: <?= date('F j, Y \a\t g:i A') ?>
        </span>
        <?php if ($username): ?>
        <div class="user-detail-row">
          <span class="label">Username</span>
          <span class="value"><?= htmlspecialchars($username) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($employeeId): ?>
        <div class="user-detail-row">
          <span class="label">Employee ID</span>
          <span class="value"><?= htmlspecialchars($employeeId) ?></span>
        </div>
        <?php endif; ?>
        <div class="user-detail-row">
          <span class="label">Modules access</span>
          <span class="value"><?= (int) $totalCards ?></span>
        </div>
        <div class="user-dropdown-divider"></div>
        <a href="<?= route('logout') ?>" class="user-dropdown-item logout-item">
          <i class="bi bi-box-arrow-right"></i> Log out
        </a>
      </div>
    </div>
  </div>
  <div class="welcome-strip">
    <?= $greeting ?>, <strong><?= htmlspecialchars($displayName) ?></strong>
    <span class="user-badge">
      <?= htmlspecialchars($userType) ?>
      <?php if ($department): ?>&nbsp;·&nbsp;<?= htmlspecialchars($department) ?><?php endif; ?>
    </span>
  </div>
  <div id="search-status"></div>

  <!-- ── Quick access (recently used modules) ── -->
  <?php if (!empty($sections)): ?>
  <div class="hub-quick-access" id="quick-access" style="display:none;">
    <div class="qa-label"><i class="bi bi-clock-history"></i> Recently used</div>
    <div class="qa-grid" id="qa-grid"></div>
  </div>
  <?php endif; ?>

  <!-- ── Card Sections (RBAC-driven) ── -->
  <div class="hub-sections">
    <?php if (empty($sections)): ?>
      <div class="hub-empty">
        <i class="bi bi-shield-lock"></i>
        No modules assigned to your role yet. Contact an administrator.
      </div>
    <?php else: ?>
      <?php foreach ($sections as $section): ?>
        <?php $cardCount = count($section['cards']); ?>
        <div class="hub-section <?= htmlspecialchars($section['css']) ?>"
             data-section>
          <div class="section-header" role="button"
               aria-expanded="false"
               onclick="toggleSection(this.closest('.hub-section'))">
            <span class="section-icon"><i class="bi <?= htmlspecialchars($section['icon']) ?>"></i></span>
            <span class="section-label"><?= htmlspecialchars($section['label']) ?></span>
            <span class="section-count"><?= $cardCount ?></span>
            <div class="section-divider"></div>
            <i class="bi bi-chevron-down section-chevron"></i>
          </div>
          <div class="section-body">
            <div class="section-grid">
              <?php foreach ($section['cards'] as $card):
                  $colorVal  = $card['color'] ?? 'blue';
                  $legacyMap = ['blue'=>'#60a5fa','green'=>'#34d399','amber'=>'#fbbf24','purple'=>'#a78bfa'];
                  $iconColor = $legacyMap[$colorVal] ?? $colorVal;
                ?>
                <a href="<?= htmlspecialchars($card['url']) ?>"
                   class="hub-card"
                   data-name="<?= htmlspecialchars(strtolower($card['module_name'])) ?>"
                   data-desc="<?= htmlspecialchars(strtolower($card['description'] ?? '')) ?>">
                  <i class="bi <?= htmlspecialchars($card['icon']) ?> card-icon"
                     style="color:<?= htmlspecialchars($iconColor) ?>"></i>
                  <div class="card-name"><?= htmlspecialchars($card['module_name']) ?></div>
                  <div class="card-desc"><?= htmlspecialchars($card['description']) ?></div>
                </a>
              <?php endforeach; ?>
            </div>
            <div class="section-empty">No matching modules in this section.</div>
          </div>
        </div>
      <?php endforeach; ?>
      <div id="no-results">No modules match your search.</div>
    <?php endif; ?>
  </div>

</div>

<script>
// ── RBAC-filtered card data, for the "recently used" quick-access row ──
const ALL_CARDS = <?= json_encode($allCardsForJs) ?>;

// ── Profile dropdown ──
function toggleUserMenu() {
  const dropdown = document.getElementById('user-dropdown');
  const trigger  = document.querySelector('.user-trigger');
  const isOpen   = dropdown.classList.toggle('open');
  trigger.setAttribute('aria-expanded', String(isOpen));
}
document.addEventListener('click', (e) => {
  const menu = document.getElementById('user-menu');
  if (menu && !menu.contains(e.target)) {
    document.getElementById('user-dropdown')?.classList.remove('open');
    document.querySelector('.user-trigger')?.setAttribute('aria-expanded', 'false');
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.getElementById('user-dropdown')?.classList.remove('open');
    document.querySelector('.user-trigger')?.setAttribute('aria-expanded', 'false');
  }
});

// ── Recently used modules (client-side, per-browser) ──
(function () {
  const KEY       = 'twm_recent_modules';
  const MAX_SHOWN = 6;
  const qaSection = document.getElementById('quick-access');
  const qaGrid    = document.getElementById('qa-grid');

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }
  function getRecent() {
    try { return JSON.parse(localStorage.getItem(KEY)) || []; } catch { return []; }
  }
  function trackVisit(url) {
    if (!url) return;
    const recent = getRecent().filter(u => u !== url);
    recent.unshift(url);
    try { localStorage.setItem(KEY, JSON.stringify(recent.slice(0, 12))); } catch {}
  }
  function renderQuickAccess() {
    if (!qaSection || !qaGrid) return;
    const cards = getRecent()
      .map(url => ALL_CARDS.find(c => c.url === url))
      .filter(Boolean)
      .slice(0, MAX_SHOWN);

    if (!cards.length) { qaSection.style.display = 'none'; return; }

    qaGrid.innerHTML = cards.map(c => `
      <a href="${escapeHtml(c.url)}" class="qa-chip"
         data-name="${escapeHtml(c.name.toLowerCase())}">
        <i class="bi ${escapeHtml(c.icon)}" style="color:${escapeHtml(c.color)}"></i>
        ${escapeHtml(c.name)}
      </a>
    `).join('');
    qaSection.style.display = '';
  }

  // Track visits from both the quick-access row and the regular grid
  document.addEventListener('click', (e) => {
    const card = e.target.closest('.hub-card, .qa-chip');
    if (card) trackVisit(card.getAttribute('href'));
  });

  renderQuickAccess();
})();

// ── Session heartbeat (slowed to 30s — 2s was excessive) ──
setInterval(() => {
  fetch('/TWM/check_session.php')
    .then(r => r.json())
    .then(d => { if (!d.loggedIn) location.href = '/TWM/login.php'; })
    .catch(() => {});
}, 30000);

// ── Section collapse/expand ──
function toggleSection(el) {
  const body = el.querySelector('.section-body');
  const collapsed = el.classList.toggle('collapsed');
  el.querySelector('.section-header').setAttribute('aria-expanded', String(!collapsed));
  body.style.overflow = collapsed ? 'hidden' : 'visible';
}

// ── Module search ──
(function () {
  const input   = document.getElementById('mod-search');
  const clearBtn= document.getElementById('search-clear');
  const status  = document.getElementById('search-status');
  const noRes   = document.getElementById('no-results');
  if (!input) return;

  function runSearch() {
    const q = input.value.trim().toLowerCase();
    clearBtn.classList.toggle('visible', q.length > 0);

    const sections = document.querySelectorAll('[data-section]');
    let totalVisible = 0;

    sections.forEach(sec => {
      const cards = sec.querySelectorAll('.hub-card');
      let secVisible = 0;

      cards.forEach(card => {
        const hit = !q
          || card.dataset.name.includes(q)
          || card.dataset.desc.includes(q);
        card.classList.toggle('search-hidden', !hit);
        if (hit) secVisible++;
      });

      totalVisible += secVisible;

      const empty = sec.querySelector('.section-empty');
      if (q) {
        // During search: hide sections with no hits entirely; expand the rest
        sec.classList.toggle('search-hidden', secVisible === 0);
        sec.classList.remove('collapsed');
        sec.querySelector('.section-header').setAttribute('aria-expanded', 'true');
        empty.style.display = 'none';
      } else {
        sec.classList.remove('search-hidden');
        empty.style.display = 'none';
      }

      // Count badge update
      const badge = sec.querySelector('.section-count');
      if (badge) badge.textContent = secVisible;
    });

    if (noRes) noRes.style.display = (q && totalVisible === 0) ? 'block' : 'none';
    if (status) {
      status.textContent = q
        ? (totalVisible === 0 ? 'No modules found.' : `${totalVisible} module${totalVisible !== 1 ? 's' : ''} found`)
        : '';
    }
  }

  input.addEventListener('input', runSearch);
  clearBtn.addEventListener('click', () => {
    input.value = '';
    runSearch();
    input.focus();
  });

  // Keyboard shortcut: "/" focuses search
  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== input) {
      e.preventDefault(); input.focus();
    }
    if (e.key === 'Escape' && document.activeElement === input) {
      input.value = ''; runSearch(); input.blur();
    }
  });
})();

// ── Apply RBAC module order saved from admin drag-and-drop ──
(function () {
  const stored = localStorage.getItem('rbac_module_order');
  if (!stored) return;
  let keys;
  try { keys = JSON.parse(stored); } catch { return; }
  keys.forEach(key => {
    const card = document.querySelector(`.hub-card[href*="${key}"]`);
    if (card?.parentElement) card.parentElement.appendChild(card);
  });
})();
</script>

</body>
</html>