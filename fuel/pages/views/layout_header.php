<?php
/**
 * layout_header.php
 * Shared top section: <head>, navbar, sidebar, and opening <main> tag.
 * Include at the top of every page view.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tradewell Fuel Monitoring System</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <!-- Anti-flash: apply saved theme before CSS renders -->
    <script>
    (function(){
        var t = localStorage.getItem('fuelTheme');
        if (t === 'light') document.documentElement.setAttribute('data-theme','light');
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script>
    // Permissions injected server-side for this session user
    const USER_PERMS    = <?= json_encode($userPerms) ?>;
    // Department injected server-side — used as default filter
    const USER_DEPT     = <?= json_encode($_SESSION['department'] ?? '') ?>;
    let ALL_DEPTS   = []; // populated from Tbl_Department via API on page load
    let DEPT_COLORS = {}; // populated from Tbl_Department via API on page load
    // Role hierarchy — only superadmins can access the Administration panel
    const IS_SUPERADMIN     = <?= $isSuperAdmin ? 'true' : 'false' ?>;
    // Departments this user is allowed to see (empty array = all, for superadmin)
    const USER_ALLOWED_DEPTS = <?= json_encode($_SESSION['allowed_depts'] ?? []) ?>;
    // Tank suppliers this user can access (TRADEWELL and/or TRADEWELL GUMACA; superadmins get both)
    const RESTRICTED_TANK_SUPPLIERS = ['TRADEWELL', 'TRADEWELL GUMACA'];
    const USER_TANK_SUPPLIERS = <?php
        $isSuperAdminPhp = !empty($_SESSION['is_superadmin']);
        if ($isSuperAdminPhp) {
            echo "['TRADEWELL', 'TRADEWELL GUMACA']";
        } else {
            require_once __DIR__ . '/../../includes/functions.php';
            $tankSuppliers = getUserTankSuppliers((int)($_SESSION['user_id'] ?? 0));
            echo json_encode($tankSuppliers);
        }
    ?>;
    const PENDING_ACCESS_COUNT = <?= (int)($pendingAccessCount ?? 0) ?>;
    </script>
    <style>
        /* ── FUEL RECORDS PAGE ─────────────────────────── */
        .fuel-filter-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 18px;
        }
        .fuel-filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            gap: 10px;
            flex-wrap: wrap;
        }
        .fuel-filter-title {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--muted);
            margin: 0;
        }
        .btn-add-fuel {
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-add-fuel span {
            font-size: 16px;
            line-height: 1;
        }
        .fuel-filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .fuel-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 150px;
        }
        .fuel-filter-group label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
        }
        .fuel-filter-group input,
        .fuel-filter-group select {
            background: var(--surface2);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-family: 'Barlow', sans-serif;
            width: 100%;
        }
        .fuel-filter-group input:focus,
        .fuel-filter-group select:focus {
            border-color: var(--accent);
            outline: none;
        }
        .fuel-filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-shrink: 0;
        }
        /* Summary totals bar */
        .fr-totals {
            display: flex;
            gap: 0;
            background: linear-gradient(135deg, rgba(240,165,0,.07), rgba(224,92,0,.05));
            border-top: 1px solid rgba(240,165,0,.2);
            border-bottom: 1px solid rgba(240,165,0,.2);
            padding: 12px 20px;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 0;
        }
        .fr-totals-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 100px;
        }
        .fr-totals-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--muted);
        }
        .fr-totals-val {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .5px;
        }
        .fr-totals-val.liters  { color: var(--blue); }
        .fr-totals-val.amount  { color: var(--accent); }
        .fr-totals-val.records { color: var(--green); }
        /* Export btn */
        .btn-export {
            background: var(--surface2);
            color: var(--green);
            border: 1px solid var(--green);
        }
        .btn-export:hover { background: rgba(63,185,80,.12); }
        /* Table row highlight on hover */
        #fr-table-body tr:hover td { background: rgba(240,165,0,.04); }
        /* Responsive — filter panel */
        @media (max-width: 700px) {
            .fuel-filter-group { min-width: calc(50% - 8px); }
            .fuel-filter-actions { width: 100%; }
            .fuel-filter-actions .btn { flex: 1; }
        }
        @media (max-width: 480px) {
            .fuel-filter-header { flex-direction: column; align-items: flex-start; }
            .btn-add-fuel { width: 100%; justify-content: center; }
            .fuel-filter-group { min-width: 100%; }
            .fr-totals-item { min-width: calc(50% - 12px); }
        }

        /* ── DEPARTMENT SWITCHER ───────────────────────── */
        .dept-switcher-wrap {
            position: relative;
        }
        .dept-trigger-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            background: var(--surface2);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            padding: 5px 11px 5px 10px;
            cursor: pointer;
            transition: border-color .15s, background .15s;
            white-space: nowrap;
            user-select: none;
        }
        .dept-trigger-btn:hover {
            border-color: var(--accent);
            background: rgba(240,165,0,.06);
        }
        .dept-trigger-btn.open {
            border-color: var(--accent);
            background: rgba(240,165,0,.08);
        }
        .dept-trigger-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--accent);
        }
        .dept-trigger-dot.dot-monde      { background: #3b82f6; }
        .dept-trigger-dot.dot-century    { background: #ef4444; }
        .dept-trigger-dot.dot-nutriasia  { background: #22c55e; }
        .dept-trigger-dot.dot-multilines { background: #a855f7; }
        .dept-trigger-dot.dot-all        { background: #f0a500; }
        .dept-trigger-label {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .5px;
            color: var(--text);
            text-transform: uppercase;
        }
        .dept-trigger-sub {
            font-size: 9px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .8px;
            line-height: 1;
        }
        .dept-trigger-chevron {
            font-size: 10px;
            color: var(--muted);
            transition: transform .2s;
            margin-left: 2px;
        }
        .dept-trigger-btn.open .dept-trigger-chevron { transform: rotate(180deg); }

        /* Dropdown panel */
        .dept-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: auto;
            width: 240px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.45);
            z-index: 10000;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-6px) scale(.97);
            pointer-events: none;
            transition: opacity .18s, transform .18s;
        }
        .dept-dropdown.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }
        @media (max-width: 900px) {
            .dept-dropdown {
                width: min(260px, 90vw);
                left: auto;
                right: 0;
            }
        }
        .dept-dropdown-header {
            padding: 12px 16px 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        .dept-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            cursor: pointer;
            transition: background .12s;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }
        .dept-option:hover { background: rgba(255,255,255,.04); }
        .dept-option.selected { background: rgba(240,165,0,.07); }
        .dept-option-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .dept-option-name {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .4px;
            color: var(--text);
            text-transform: uppercase;
            flex: 1;
        }
        .dept-option-check {
            font-size: 13px;
            color: var(--accent);
            opacity: 0;
        }
        .dept-option.selected .dept-option-check { opacity: 1; }
        .dept-dropdown-footer {
            padding: 10px 12px 12px;
            border-top: 1px solid var(--border);
        }
        .dept-apply-btn {
            width: 100%;
            padding: 9px;
            background: var(--accent);
            color: #000;
            border: none;
            border-radius: 7px;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: opacity .15s;
        }
        .dept-apply-btn:hover { opacity: .85; }
        .dept-apply-btn:active { opacity: .7; }
    </style>
</head>
<body>

<nav class="navbar">
    <button class="hamburger" id="hamburger" onclick="toggleSidebar()" aria-label="Toggle navigation">
        <span></span><span></span><span></span>
    </button>

    <!-- Brand: desktop only (hidden on mobile via CSS) -->
    <div class="brand navbar-brand">TRADEWELL<span>FUEL</span>MONITOR
        <div class="nav-date" id="clock"></div>
    </div>

    <!-- Brand: mobile only (shown in navbar center on mobile) -->
    <div class="brand navbar-brand-mobile">TRADEWELL<span>FUEL</span>MONITOR</div>

    <!-- Dept switcher: desktop = after brand, mobile = pushed to right (before user info) -->
    <div class="dept-switcher-wrap" id="deptSwitcherWrap">
        <div class="dept-trigger-btn" id="deptTriggerBtn" onclick="toggleDeptDropdown()">
            <span class="dept-trigger-dot" id="deptTriggerDot"></span>
            <div>
                <div class="dept-trigger-sub">Department</div>
                <div class="dept-trigger-label" id="deptTriggerLabel">Loading…</div>
            </div>
            <span class="dept-trigger-chevron">▼</span>
        </div>
        <div class="dept-dropdown" id="deptDropdown">
            <div class="dept-dropdown-header">📁 Select Department</div>
            <div id="deptOptionsList"></div>
            <div class="dept-dropdown-footer">
                <button class="dept-apply-btn" onclick="applyDeptSelection(event)">✓ Apply Department</button>
            </div>
        </div>
    </div>

    <!-- Live update indicator — hidden on mobile, shown on desktop -->
    <div id="live-indicator" class="live-indicator-navbar" title="Live updates active" style="
        display:flex;align-items:center;gap:6px;padding:4px 10px;
        border-radius:99px;font-size:11px;font-weight:700;
        background:rgba(34,197,94,.12);color:#22c55e;
        border:1px solid rgba(34,197,94,.25);
        cursor:default;user-select:none;transition:all .3s;white-space:nowrap">
        <span id="live-dot" style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;animation:livePulse 2s ease-in-out infinite"></span>
        <span id="live-label">LIVE</span>
    </div>

    <!-- Theme toggle: sun/moon button -->
    <button class="theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="Toggle dark/light mode" aria-label="Toggle theme">
        <span class="theme-icon-sun">☀️</span>
        <span class="theme-icon-moon">🌙</span>
    </button>

    <!-- User info: desktop shows name+role+signout, mobile hides signout (it's in sidebar) -->
    <div class="nav-user-info">
        <div class="nav-user-text">
            <div class="nav-user-name"><?= $displayName ?></div>
            <div class="nav-user-role">
                <?php if ($isSuperAdmin): ?>
                <span class="badge-superadmin">SUPERADMIN</span>
                <?php else: ?>
                <span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px"><?= $userType ?></span>
                <?php endif; ?>
            </div>
        </div>
        <a href="logout.php" class="nav-signout nav-signout-desktop" title="Sign out">⎋ <span class="nav-signout-text">Sign Out</span></a>
    </div>
</nav>

<!-- Live update toast stack -->
<div id="live-toast-stack"></div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <!-- Brand shown in sidebar on mobile -->
        <div class="sidebar-brand">TRADEWELL<span>FUEL</span>MONITOR</div>
        <!-- User info shown in sidebar on mobile -->
        <div class="sidebar-user">
            <div class="sidebar-user-name-row">
                <div class="sidebar-user-name"><?= $displayName ?></div>
                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                    <!-- Live badge — sidebar (mobile only) -->
                    <div id="live-indicator-sidebar" class="live-indicator-sidebar" title="Live updates active">
                        <span class="live-sidebar-dot"></span>
                        <span class="live-sidebar-label">LIVE</span>
                    </div>
                    <button class="sidebar-theme-mini-btn" onclick="toggleTheme()" title="Toggle theme" aria-label="Toggle dark/light mode">
                        <span class="theme-icon-sun">☀️</span>
                        <span class="theme-icon-moon">🌙</span>
                    </button>
                </div>
            </div>
            <?php if ($isSuperAdmin): ?>
            <span class="badge-superadmin">SUPERADMIN</span>
            <?php else: ?>
            <span class="sidebar-user-role"><?= $userType ?></span>
            <?php endif; ?>
        </div>
        <div class="sidebar-section">Navigation</div>
        <a href="#" class="<?= ($activePage === 'dashboard')    ? 'active' : '' ?>" onclick="showPage('dashboard',this);closeSidebar()"><span class="icon">📊</span> Dashboard</a>
        <a href="#" class="<?= ($activePage === 'fuel-records') ? 'active' : '' ?>" onclick="showPage('fuel-records',this);closeSidebar()"><span class="icon">📋</span> Fuel Records</a>
        <a href="#" class="<?= ($activePage === 'gas-card')     ? 'active' : '' ?>" onclick="showPage('gas-card',this);closeSidebar()"><span class="icon">🪪</span> Truck Gas Card</a>
        <a href="#" class="<?= ($activePage === 'tank') ? 'active' : '' ?>" onclick="showPage('tank',this);closeSidebar()"><span class="icon">🛢</span> Fuel Tank</a>
        <?php if ($isSuperAdmin): ?>
        <div class="sidebar-section" style="margin-top:12px">Super Admin</div>
        <a href="#" class="<?= ($activePage === 'administration') ? 'active' : '' ?>" onclick="showPage('administration',this);closeSidebar()" style="color:#f0a500"><span class="icon">⚙️</span> Administration <span id="adm-pending-badge" style="display:none;background:rgba(240,165,0,.25);color:#f0a500;font-size:10px;font-weight:800;padding:1px 7px;border-radius:99px;margin-left:4px">0</span></a>
        <a href="#" class="<?= ($activePage === 'tank-access')    ? 'active' : '' ?>" onclick="showPage('tank-access',this);closeSidebar()" style="color:#58a6ff"><span class="icon">🔑</span> Fuel Tank Access</a>
        <a href="#" class="<?= ($activePage === 'system-access')  ? 'active' : '' ?>" onclick="showPage('system-access',this);closeSidebar()" style="color:#3fb950"><span class="icon">🔐</span> System Access</a>
        <a href="#" class="<?= ($activePage === 'department')     ? 'active' : '' ?>" onclick="showPage('department',this);closeSidebar()" style="color:#f0a500"><span class="icon">🏢</span> Departments</a>
        <?php endif; ?>

        <!-- Sidebar footer: Sign Out -->
        <div class="sidebar-footer">
            <a href="logout.php" class="sidebar-signout">
                <span class="icon">⎋</span> Sign Out
            </a>
        </div>
    </aside>

    <main class="main">