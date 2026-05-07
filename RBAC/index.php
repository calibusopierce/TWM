<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/rbac_helper.php';

auth_check(); // login + session guard only

rbac_gate($pdo, 'RBAC'); // DB-driven — only roles with can_access=1 for 'RBAC' get in

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

// ── Load all modules ──────────────────────────────────────────
$modules = $pdo->query("
    SELECT * FROM rbac_modules ORDER BY sort_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Load all distinct roles: from users table + rbac_roles table ──
// rbac_roles holds roles created via "Add User Type" that may have 0 users
$rolesFromUsers = $pdo->query("
    SELECT DISTINCT user_type AS role_name, COUNT(*) AS total
    FROM users
    WHERE user_type IS NOT NULL AND user_type != ''
    GROUP BY user_type
")->fetchAll(PDO::FETCH_ASSOC);

// ── Auto-create rbac_roles table if it does not exist yet ────
$pdo->exec("
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_NAME = 'rbac_roles'
    )
    BEGIN
        CREATE TABLE rbac_roles (
            role_name   NVARCHAR(100) NOT NULL PRIMARY KEY,
            created_by  NVARCHAR(100) NULL,
            created_at  DATETIME      NOT NULL DEFAULT GETDATE()
        )
    END
");

// Extra roles defined in rbac_roles but not yet assigned to any user
$extraRoles = $pdo->query("
    SELECT role_name, 0 AS total FROM rbac_roles
    WHERE role_name NOT IN (
        SELECT DISTINCT user_type FROM users WHERE user_type IS NOT NULL AND user_type != ''
    )
")->fetchAll(PDO::FETCH_ASSOC);

$allRoles = array_merge($rolesFromUsers, $extraRoles);
// Sort deferred — happens below after $roleGrantCount is built

// ── Load all current permissions as a flat lookup ─────────────
$permsRaw = $pdo->query("
    SELECT role_name, module_key, can_access FROM rbac_permissions
")->fetchAll(PDO::FETCH_ASSOC);

$permsMap = [];
foreach ($permsRaw as $p) {
    $permsMap[$p['role_name'] . '|' . $p['module_key']] = (int)$p['can_access'];
}

// ── Per-role grant counts ─────────────────────────────────────
$roleGrantCount = [];
foreach ($permsRaw as $p) {
    if ($p['can_access']) {
        $roleGrantCount[$p['role_name']] = ($roleGrantCount[$p['role_name']] ?? 0) + 1;
    }
}

// ── Sort roles by granted modules desc, then name asc ─────────
usort($allRoles, function($a, $b) use ($roleGrantCount) {
    $ga = $roleGrantCount[$a['role_name']] ?? 0;
    $gb = $roleGrantCount[$b['role_name']] ?? 0;
    return $gb <=> $ga ?: strcmp($a['role_name'], $b['role_name']);
});

// ── Category meta ─────────────────────────────────────────────
$categoryMeta = [
    'hr'      => ['label' => 'HR',      'color' => '#34d399'],
    'fleet'   => ['label' => 'Fleet',   'color' => '#fbbf24'],
    'finance' => ['label' => 'Finance', 'color' => '#a78bfa'],
    'general' => ['label' => 'General', 'color' => '#60a5fa'],
];

$modulesJson  = json_encode(array_values($modules));
$permsMapJson = json_encode($permsMap);
$totalGrants  = count(array_filter($permsRaw, fn($p) => $p['can_access']));

// ── Paginated users for the Users tab ────────────────────────
$usersPerPage   = 20;
$usersPage      = max(1, (int)($_GET['upage'] ?? 1));
$usersSearch    = trim($_GET['usearch']  ?? '');
$usersTypeFilter= trim($_GET['utype']    ?? '');
$usersActFilter = $_GET['uactive'] ?? '';

// Build WHERE clause
$whereParts = [];
$whereParams = [];

if ($usersSearch !== '') {
    $whereParts[]  = "(DisplayName LIKE ? OR username LIKE ? OR email LIKE ?)";
    $likeVal       = '%' . $usersSearch . '%';
    $whereParams[] = $likeVal;
    $whereParams[] = $likeVal;
    $whereParams[] = $likeVal;
}
if ($usersTypeFilter !== '') {
    $whereParts[]  = "user_type = ?";
    $whereParams[] = $usersTypeFilter;
}
if ($usersActFilter !== '') {
    $whereParts[]  = "Active = ?";
    $whereParams[] = (int)$usersActFilter;
}

$whereSQL = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Total count for this filter set
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ViewUserLogIn $whereSQL");
$countStmt->execute($whereParams);
$totalUsers  = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalUsers / $usersPerPage));
$usersPage   = min($usersPage, $totalPages);
$usersOffset = ($usersPage - 1) * $usersPerPage;

// Fetch only the current page
$usersStmt = $pdo->prepare("
    SELECT id, username, email, user_type, DisplayName,
           Department, Job_tittle, Position_held,
           Category, FileNo, EmployeeID, Active,
           CONVERT(VARCHAR(16), Reg_DateTime, 120) AS reg_date
    FROM   ViewUserLogIn
    $whereSQL
    ORDER  BY DisplayName ASC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
");

// Bind filter params first, then explicitly bind OFFSET and FETCH as integers
// SQL Server requires these to be typed as integers — passing via array coerces to string
$paramIndex = 1;
foreach ($whereParams as $val) {
    $usersStmt->bindValue($paramIndex++, $val);
}
$usersStmt->bindValue($paramIndex++, $usersOffset, PDO::PARAM_INT);
$usersStmt->bindValue($paramIndex,   $usersPerPage, PDO::PARAM_INT);
$usersStmt->execute();
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Load all distinct departments for the dept-access modal ──
$allDepts = $pdo->query("
    SELECT DISTINCT Department FROM ViewUserLogIn
    WHERE Department IS NOT NULL AND Department != ''
    ORDER BY Department ASC
")->fetchAll(PDO::FETCH_COLUMN);

// ── Load dept access map: user_id => [dept, dept, ...] ───────
$deptAccessRaw = $pdo->query("
    SELECT UserID, Department FROM Tbl_UserAccessDepartment
")->fetchAll(PDO::FETCH_ASSOC);

$deptAccessMap = [];
foreach ($deptAccessRaw as $row) {
    $deptAccessMap[(int)$row['UserID']][] = $row['Department'];
}

$allDeptsJson   = json_encode($allDepts);
$deptAccessJson = json_encode($deptAccessMap);
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
    html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--bg-0); color: var(--white); overflow-x: hidden; }

    /* ── Mesh bg ── */
    .mesh { position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background: radial-gradient(ellipse 80% 50% at 10% 0%, rgba(67,128,226,0.18) 0%, transparent 60%),
                  radial-gradient(ellipse 60% 40% at 90% 100%, rgba(52,211,153,0.10) 0%, transparent 60%),
                  radial-gradient(ellipse 100% 80% at 50% 50%, rgba(6,13,31,1) 40%, transparent 100%); }
    .mesh::after { content:''; position:absolute; inset:0;
      background-image: linear-gradient(rgba(255,255,255,0.025) 1px,transparent 1px),
                        linear-gradient(90deg,rgba(255,255,255,0.025) 1px,transparent 1px);
      background-size: 48px 48px; }

    /* ── Layout ── */
    .wrap { position:relative; z-index:10; max-width:1300px; margin:0 auto; padding:2rem 1.5rem 4rem; }

    /* ── Page header ── */
    .page-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:2rem; animation:fadeUp .4s ease both; }
    .breadcrumb { font-size:.72rem; color:var(--w40); letter-spacing:.06em; text-transform:uppercase; margin-bottom:.4rem; }
    .breadcrumb a { color:var(--accent2); text-decoration:none; }
    .breadcrumb a:hover { text-decoration:underline; }
    .page-title { font-family:'Sora',sans-serif; font-size:1.75rem; font-weight:800; letter-spacing:-.04em; color:var(--white); line-height:1.1; }
    .page-title span { color:var(--accent2); }
    .page-sub { font-size:.82rem; color:var(--w60); margin-top:.35rem; }
    .page-header-right { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; }

    /* ── Buttons ── */
    .btn { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1.1rem; border-radius:10px; font-size:.8rem; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .2s; text-decoration:none; font-family:'DM Sans',sans-serif; }
    .btn-primary { background:var(--accent); color:#fff; border-color:rgba(67,128,226,.5); }
    .btn-primary:hover { background:#3370d4; }
    .btn-ghost { background:var(--w08); color:var(--w60); border-color:var(--border); }
    .btn-ghost:hover { background:var(--w15); color:var(--white); }
    .btn-success { background:rgba(52,211,153,.12); color:#34d399; border:1px solid rgba(52,211,153,.25); }
    .btn-success:hover { background:rgba(52,211,153,.22); }
    .btn-danger { background:rgba(239,68,68,.15); color:#fca5a5; border-color:rgba(239,68,68,.3); }
    .btn-danger:hover { background:rgba(239,68,68,.25); }
    .btn-sm { padding:.3rem .7rem; font-size:.72rem; }

    /* ── Stats bar ── */
    .stats-bar { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:1.75rem; animation:fadeUp .4s .1s ease both; }
    .stat-chip { display:flex; align-items:center; gap:.6rem; padding:.6rem 1rem; background:var(--surface); border:1px solid var(--border); border-radius:12px; font-size:.78rem; }
    .stat-chip-num { font-family:'Sora',sans-serif; font-size:1.1rem; font-weight:700; color:var(--white); }
    .stat-chip-label { color:var(--w60); }
    .stat-chip .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }

    /* ── Tabs ── */
    .tab-bar { display:flex; gap:.5rem; margin-bottom:1.5rem; border-bottom:1px solid var(--border); padding-bottom:.75rem; animation:fadeUp .4s .12s ease both; }
    .tab-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.45rem 1rem; border-radius:10px 10px 0 0; font-size:.82rem; font-weight:600; cursor:pointer; border:1px solid transparent; border-bottom:none; background:transparent; color:var(--w40); transition:all .2s; font-family:'DM Sans',sans-serif; position:relative; top:1px; }
    .tab-btn:hover { color:var(--white); background:var(--w08); }
    .tab-btn.active { background:var(--bg-1); border-color:var(--border); color:var(--white); border-bottom-color:var(--bg-0); }
    .tab-btn .tab-badge { background:var(--accent); color:#fff; font-size:.6rem; font-weight:700; padding:.1rem .4rem; border-radius:999px; margin-left:.15rem; }
    .tab-panel { display:none; }
    .tab-panel.active { display:block; }

    /* ── Role cards grid ── */
    .roles-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:.85rem; animation:fadeUp .4s .2s ease both; }

    .role-card {
      background:var(--surface); border:1px solid var(--border);
      border-radius:18px; padding:1.25rem 1.25rem 1rem;
      cursor:pointer; transition:border-color .2s, background .2s, transform .15s;
      display:flex; flex-direction:column; gap:.75rem; position:relative;
    }
    .role-card:hover { border-color:var(--border2); background:rgba(255,255,255,0.06); transform:translateY(-2px); }
    .role-card-top { display:flex; align-items:center; gap:.75rem; }
    .role-avatar {
      width:42px; height:42px; border-radius:12px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-family:'Sora',sans-serif; font-size:.82rem; font-weight:800;
      background:rgba(67,128,226,.18); color:var(--accent2);
      border:1px solid rgba(67,128,226,.28);
    }
    .role-card-info { flex:1; min-width:0; }
    .role-card-name { font-size:.92rem; font-weight:700; color:var(--white); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .role-card-sub  { font-size:.72rem; color:var(--w40); margin-top:.1rem; }

    .role-card-arrow { color:var(--w40); font-size:.9rem; transition:color .15s, transform .15s; }
    .role-card:hover .role-card-arrow { color:var(--accent2); transform:translateX(2px); }

    /* Grant bar */
    .role-grant-bar { height:4px; background:var(--border); border-radius:999px; overflow:hidden; }
    .role-grant-fill { height:100%; background:var(--green); border-radius:999px; transition:width .4s ease; }

    .role-card-footer { display:flex; align-items:center; justify-content:space-between; }
    .role-grant-label { font-size:.7rem; color:var(--w40); }
    .role-grant-label strong { color:var(--green); }

    /* Delete role btn (top-right corner) */
    .role-card-del {
      position:absolute; top:.65rem; right:.65rem;
      background:none; border:none; cursor:pointer; font-size:.8rem;
      color:var(--w40); padding:.2rem .35rem; border-radius:6px;
      transition:color .15s, background .15s; opacity:0; pointer-events:none;
    }
    .role-card:hover .role-card-del { opacity:1; pointer-events:auto; }
    .role-card-del:hover { color:var(--red); background:rgba(248,113,113,.12); }

    /* ── Drawer overlay ── */
    .drawer-overlay {
      display:none; position:fixed; inset:0; z-index:150;
      background:rgba(0,0,0,.55); backdrop-filter:blur(4px);
    }
    .drawer-overlay.open { display:block; }

    /* ── Drawer panel ── */
    .drawer {
      position:fixed; top:0; right:0; bottom:0; z-index:160;
      width:min(680px, 95vw);
      background:#0a1428; border-left:1px solid var(--border2);
      display:flex; flex-direction:column;
      transform:translateX(100%); transition:transform .3s cubic-bezier(.4,0,.2,1);
      box-shadow:-24px 0 64px rgba(0,0,0,.5);
    }
    .drawer.open { transform:translateX(0); }

    .drawer-header {
      display:flex; align-items:center; justify-content:space-between;
      padding:1.25rem 1.5rem; border-bottom:1px solid var(--border);
      flex-shrink:0;
    }
    .drawer-header-left { display:flex; align-items:center; gap:.85rem; }
    .drawer-avatar {
      width:44px; height:44px; border-radius:13px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-family:'Sora',sans-serif; font-size:.9rem; font-weight:800;
      background:rgba(67,128,226,.2); color:var(--accent2);
      border:1px solid rgba(67,128,226,.3);
    }
    .drawer-role-name { font-family:'Sora',sans-serif; font-size:1.1rem; font-weight:700; }
    .drawer-role-sub  { font-size:.74rem; color:var(--w40); margin-top:.15rem; }
    .drawer-close { background:var(--w08); border:1px solid var(--border); color:var(--w60); width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center; transition:all .15s; }
    .drawer-close:hover { background:var(--w15); color:var(--white); }

    .drawer-toolbar {
      display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
      padding:.75rem 1.5rem; border-bottom:1px solid var(--border); flex-shrink:0;
    }
    .drawer-filter-pills { display:flex; gap:.4rem; flex-wrap:wrap; }
    .drawer-actions { display:flex; gap:.4rem; }

    .drawer-body { flex:1; overflow-y:auto; padding:1.25rem 1.5rem; }

    /* ── Module toggle list inside drawer ── */
    .drawer-cat-label {
      font-size:.68rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
      color:var(--w40); margin:1.25rem 0 .6rem; padding-bottom:.35rem;
      border-bottom:1px solid var(--border);
    }
    .drawer-cat-label:first-child { margin-top:0; }

    .module-row {
      display:flex; align-items:center; gap:.9rem;
      padding:.75rem .9rem; border-radius:12px;
      transition:background .15s; cursor:pointer;
      border:1px solid transparent; margin-bottom:.35rem;
    }
    .module-row:hover { background:rgba(255,255,255,.04); border-color:var(--border); }
    .module-row.granted { background:rgba(52,211,153,.05); border-color:rgba(52,211,153,.15); }

    .mod-row-icon {
      width:36px; height:36px; border-radius:10px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center; font-size:1rem;
    }
    .mod-row-icon.green  { background:rgba(52,211,153,.15);  border:1px solid rgba(52,211,153,.25);  color:#34d399; }
    .mod-row-icon.amber  { background:rgba(251,191,36,.15);  border:1px solid rgba(251,191,36,.25);  color:#fbbf24; }
    .mod-row-icon.purple { background:rgba(167,139,250,.15); border:1px solid rgba(167,139,250,.25); color:#a78bfa; }
    .mod-row-icon.blue   { background:rgba(96,165,250,.15);  border:1px solid rgba(96,165,250,.25);  color:#60a5fa; }

    .mod-row-info { flex:1; min-width:0; }
    .mod-row-name { font-size:.85rem; font-weight:600; color:var(--white); }
    .mod-row-key  { font-size:.68rem; color:var(--w40); font-family:monospace; margin-top:.1rem; }

    /* Toggle */
    .toggle { position:relative; display:inline-block; width:42px; height:24px; cursor:pointer; flex-shrink:0; }
    .toggle input { display:none; }
    .toggle-track { position:absolute; inset:0; background:var(--off-color); border-radius:999px; transition:background .2s; border:1px solid rgba(255,255,255,.1); }
    .toggle input:checked ~ .toggle-track { background:var(--on-color); border-color:var(--on-color); }
    .toggle-thumb { position:absolute; top:3px; left:3px; width:16px; height:16px; background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 4px rgba(0,0,0,.3); }
    .toggle input:checked ~ .toggle-thumb { transform:translateX(18px); }

    /* ── Module Registry (tab 2) ── */
    .registry-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; animation:fadeUp .4s .2s ease both; }
    .registry-header-actions { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
    .reorder-hint { font-size:.72rem; color:var(--w40); display:flex; align-items:center; gap:.35rem; }
    .reorder-hint i { color:var(--accent2); }
    .save-order-btn { display:none !important; }
    .save-order-btn.visible { display:inline-flex !important; }

    .panel-title { font-family:'Sora',sans-serif; font-size:1rem; font-weight:700; color:var(--white); }
    .panel-title span { color:var(--w40); font-size:.8rem; font-weight:400; }

    .modules-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:.75rem; }

    .module-chip { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:1rem 1rem .85rem; display:flex; flex-direction:column; gap:.6rem; transition:border-color .2s,background .2s,box-shadow .15s,transform .15s; position:relative; }
    .module-chip:hover { border-color:var(--border2); background:rgba(255,255,255,0.06); }
    .module-chip.dragging { opacity:.35; border-color:var(--accent); box-shadow:0 0 0 2px var(--accent); transform:scale(.98); }
    .module-chip.drag-over { border-color:var(--accent2); background:rgba(147,197,253,.08); box-shadow:0 0 0 2px rgba(147,197,253,.5); }

    /* Order badge — top-left number */
    .order-badge {
      position:absolute; top:.6rem; left:.6rem;
      min-width:20px; height:20px; padding:0 .3rem; border-radius:6px;
      background:var(--accent); color:#fff;
      font-size:.6rem; font-weight:800; font-family:'Sora',sans-serif;
      display:flex; align-items:center; justify-content:center; z-index:2;
    }

    /* Drag handle — top-right, appears on hover */
    .drag-handle {
      position:absolute; top:.6rem; right:2.6rem;
      color:var(--w40); font-size:.9rem; cursor:grab;
      padding:.2rem .3rem; border-radius:5px;
      transition:color .15s,background .15s; opacity:0; pointer-events:none;
    }
    .module-chip:hover .drag-handle { opacity:1; pointer-events:auto; }
    .drag-handle:hover { color:var(--accent2); background:rgba(147,197,253,.1); }
    .drag-handle:active { cursor:grabbing; }
    .chip-top { display:flex; align-items:center; gap:.75rem; }
    .module-chip-icon { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
    .module-chip-icon.green  { background:rgba(52,211,153,.15);  border:1px solid rgba(52,211,153,.25);  color:#34d399; }
    .module-chip-icon.amber  { background:rgba(251,191,36,.15);  border:1px solid rgba(251,191,36,.25);  color:#fbbf24; }
    .module-chip-icon.purple { background:rgba(167,139,250,.15); border:1px solid rgba(167,139,250,.25); color:#a78bfa; }
    .module-chip-icon.blue   { background:rgba(96,165,250,.15);  border:1px solid rgba(96,165,250,.25);  color:#60a5fa; }
    .chip-info { flex:1; min-width:0; }
    .module-chip-name { font-size:.84rem; font-weight:600; color:var(--white); }
    .module-chip-key  { font-size:.68rem; color:var(--w40); font-family:monospace; margin-top:.1rem; }
    .chip-desc { font-size:.72rem; color:var(--w60); line-height:1.45; padding:0 .1rem; }
    .chip-footer { display:flex; align-items:center; gap:.4rem; margin-top:.1rem; }
    .chip-cat-badge { padding:.15rem .5rem; border-radius:5px; font-size:.62rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    .chip-cat-badge.hr      { background:rgba(52,211,153,.15);  color:#34d399; }
    .chip-cat-badge.fleet   { background:rgba(251,191,36,.15);  color:#fbbf24; }
    .chip-cat-badge.finance { background:rgba(167,139,250,.15); color:#a78bfa; }
    .chip-cat-badge.general { background:rgba(96,165,250,.15);  color:#60a5fa; }
    .chip-actions { margin-left:auto; display:flex; gap:.3rem; }
    .module-chip-edit, .module-chip-del { background:none; border:none; cursor:pointer; font-size:.8rem; padding:.25rem .4rem; border-radius:6px; transition:color .15s,background .15s; }
    .module-chip-edit { color:var(--w40); }
    .module-chip-edit:hover { color:var(--accent2); background:rgba(147,197,253,.1); }
    .module-chip-del  { color:var(--w40); }
    .module-chip-del:hover  { color:var(--red); background:rgba(248,113,113,.1); }

    .preview-strip { margin-top:.6rem; padding:.65rem .9rem; background:rgba(255,255,255,.03); border-radius:10px; border:1px solid rgba(255,255,255,.06); font-size:.72rem; color:var(--w40); display:flex; align-items:center; gap:.5rem; }

    .hp-card-preview { display:inline-flex; flex-direction:column; align-items:center; background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,.15); border-radius:18px; padding:1.4rem 1.1rem 1.2rem; min-width:150px; max-width:190px; text-align:center; box-shadow:0 4px 20px rgba(8,23,61,.2); }
    .hp-card-preview .prev-icon { font-size:2.1rem; margin-bottom:.7rem; display:block; line-height:1; }
    .hp-card-preview .prev-icon.blue   { color:#60a5fa; }
    .hp-card-preview .prev-icon.green  { color:#34d399; }
    .hp-card-preview .prev-icon.amber  { color:#fbbf24; }
    .hp-card-preview .prev-icon.purple { color:#a78bfa; }
    .hp-card-preview .prev-name { font-family:'Sora',sans-serif; font-size:.9rem; font-weight:700; margin-bottom:.3rem; }
    .hp-card-preview .prev-desc { font-size:.72rem; color:var(--w60); line-height:1.45; }

    /* ── Filter row for roles tab ── */
    .filter-row { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; margin-bottom:1.25rem; animation:fadeUp .4s .15s ease both; }
    .search-box { display:flex; align-items:center; gap:.5rem; background:var(--surface); border:1px solid var(--border2); border-radius:10px; padding:.45rem .85rem; flex:1; min-width:200px; max-width:320px; }
    .search-box i { color:var(--w40); font-size:.9rem; }
    .search-box input { background:none; border:none; outline:none; color:var(--white); font-size:.82rem; width:100%; font-family:'DM Sans',sans-serif; }
    .search-box input::placeholder { color:var(--w40); }

    .pill { padding:.3rem .8rem; border-radius:999px; font-size:.72rem; font-weight:600; cursor:pointer; border:1px solid var(--border2); background:var(--w08); color:var(--w60); transition:all .15s; }
    .pill.active, .pill:hover { background:var(--accent); border-color:var(--accent); color:#fff; }

    /* ── Modals (shared) ── */
    .modal-backdrop { display:none; position:fixed; inset:0; z-index:200; background:rgba(0,0,0,.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
    .modal-backdrop.open { display:flex; }
    .modal { background:#0f1c3a; border:1px solid var(--border2); border-radius:20px; padding:1.75rem; width:100%; max-width:520px; box-shadow:0 24px 64px rgba(0,0,0,.5); animation:popIn .2s ease both; }
    @keyframes popIn { from{opacity:0;transform:scale(.94) translateY(10px);} to{opacity:1;transform:scale(1) translateY(0);} }
    .modal-title { font-family:'Sora',sans-serif; font-size:1.05rem; font-weight:700; margin-bottom:1.25rem; display:flex; align-items:center; gap:.5rem; }
    .form-group { margin-bottom:1rem; }
    .form-label { display:block; font-size:.74rem; font-weight:600; color:var(--w60); margin-bottom:.35rem; letter-spacing:.04em; text-transform:uppercase; }
    .form-control { width:100%; padding:.55rem .85rem; background:rgba(255,255,255,0.06); border:1px solid var(--border2); border-radius:10px; color:var(--white); font-size:.82rem; outline:none; transition:border-color .2s; font-family:'DM Sans',sans-serif; }
    .form-control:focus { border-color:var(--accent); }
    select.form-control option { background:#0f1c3a; }
    .form-row { display:flex; gap:.75rem; }
    .form-row .form-group { flex:1; }
    .modal-footer { display:flex; justify-content:flex-end; gap:.6rem; margin-top:1.25rem; }
    .form-hint { font-size:.72rem; color:var(--w40); margin-top:.3rem; }

    .card-preview-wrap { margin-bottom:1.25rem; background:rgba(255,255,255,.03); border:1px solid var(--border); border-radius:14px; padding:.9rem 1rem; }
    .card-preview-label { font-size:.66rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--w40); margin-bottom:.65rem; }

    .icon-search-wrap { position:relative; }
    .icon-suggestions { position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:10; background:#0f1c3a; border:1px solid var(--border2); border-radius:10px; max-height:160px; overflow-y:auto; display:none; }
    .icon-suggestions.open { display:block; }
    .icon-sug-item { display:flex; align-items:center; gap:.6rem; padding:.45rem .8rem; cursor:pointer; font-size:.8rem; transition:background .1s; }
    .icon-sug-item:hover { background:rgba(255,255,255,.06); }
    .icon-sug-item i { font-size:1rem; color:var(--accent2); width:20px; text-align:center; }

    /* ── Confirm overlay ── */
    .confirm-overlay { display:none; position:fixed; inset:0; z-index:300; background:rgba(0,0,0,.65); backdrop-filter:blur(4px); align-items:center; justify-content:center; }
    .confirm-overlay.open { display:flex; }
    .confirm-box { background:#0f1c3a; border:1px solid rgba(248,113,113,.3); border-radius:18px; padding:1.75rem; max-width:380px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,.6); animation:popIn .2s ease both; text-align:center; }
    .confirm-box-icon { font-size:2rem; color:var(--red); margin-bottom:.75rem; }
    .confirm-box-title { font-family:'Sora',sans-serif; font-size:1rem; font-weight:700; margin-bottom:.5rem; }
    .confirm-box-msg { font-size:.82rem; color:var(--w60); margin-bottom:1.25rem; line-height:1.5; }
    .confirm-box-actions { display:flex; gap:.6rem; justify-content:center; }

    /* ── Toast ── */
    .toast-wrap { position:fixed; bottom:1.5rem; right:1.5rem; z-index:400; display:flex; flex-direction:column; gap:.5rem; pointer-events:none; }
    .toast { display:flex; align-items:center; gap:.6rem; padding:.65rem 1rem; background:#0f1c3a; border:1px solid var(--border2); border-radius:12px; font-size:.8rem; box-shadow:0 8px 24px rgba(0,0,0,.4); animation:toastIn .25s ease both; pointer-events:auto; }
    .toast.success { border-color:rgba(52,211,153,.4); }
    .toast.error   { border-color:rgba(248,113,113,.4); }
    .toast i.success { color:var(--green); }
    .toast i.error   { color:var(--red); }
    @keyframes toastIn  { from{opacity:0;transform:translateX(20px);} to{opacity:1;transform:translateX(0);} }
    @keyframes toastOut { to{opacity:0;transform:translateX(20px);} }

    @keyframes fadeUp { from{opacity:0;transform:translateY(14px);} to{opacity:1;transform:translateY(0);} }
    .empty-state { text-align:center; padding:3rem 1rem; color:var(--w40); font-size:.85rem; }
    .empty-state i { font-size:2.5rem; display:block; margin-bottom:.75rem; opacity:.4; }

    @media (max-width:640px) {
      .page-title { font-size:1.35rem; }
      .wrap { padding:1.25rem 1rem 3rem; }
      .form-row { flex-direction:column; gap:0; }
    }

    /* ── Users Tab ── */
    .users-toolbar { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; margin-bottom:1.25rem; }
    .users-toolbar .search-box { flex:1; min-width:180px; }

    .users-table-wrap { overflow-x:auto; border-radius:16px; border:1px solid var(--border); }
    .users-table { width:100%; border-collapse:collapse; font-size:.8rem; }
    .users-table thead th {
      padding:.7rem 1rem; text-align:left; font-size:.68rem; font-weight:700;
      letter-spacing:.06em; text-transform:uppercase; color:var(--w40);
      background:rgba(255,255,255,.03); border-bottom:1px solid var(--border);
      white-space:nowrap;
    }
    .users-table tbody tr { border-bottom:1px solid var(--border); transition:background .15s; }
    .users-table tbody tr:last-child { border-bottom:none; }
    .users-table tbody tr:hover { background:rgba(255,255,255,.04); }
    .users-table td { padding:.65rem 1rem; color:var(--w60); vertical-align:middle; }
    .users-table td.name-cell { color:var(--white); font-weight:600; }
    .users-table td.mono { font-family:monospace; font-size:.75rem; color:var(--w40); }

    .user-avatar-sm {
      width:30px; height:30px; border-radius:8px; flex-shrink:0;
      display:inline-flex; align-items:center; justify-content:center;
      font-family:'Sora',sans-serif; font-size:.65rem; font-weight:800;
      background:rgba(67,128,226,.18); color:var(--accent2);
      border:1px solid rgba(67,128,226,.28); vertical-align:middle; margin-right:.5rem;
    }
    .user-name-wrap { display:inline-flex; align-items:center; }

    .active-dot { display:inline-block; width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .active-dot.on  { background:var(--green); box-shadow:0 0 5px var(--green); }
    .active-dot.off { background:var(--w40); }

    .role-badge {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.2rem .65rem; border-radius:999px; font-size:.7rem; font-weight:700;
      background:rgba(67,128,226,.15); color:var(--accent2);
      border:1px solid rgba(67,128,226,.25);
    }

    .change-type-btn {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.25rem .6rem; border-radius:8px; font-size:.7rem; font-weight:600;
      background:var(--w08); color:var(--w60); border:1px solid var(--border);
      cursor:pointer; transition:all .15s; font-family:'DM Sans',sans-serif;
    }
    .change-type-btn:hover { background:var(--accent); color:#fff; border-color:var(--accent); }
    .dept-access-btn {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.25rem .6rem; border-radius:8px; font-size:.7rem; font-weight:600;
      background:rgba(167,139,250,.12); color:#a78bfa;
      border:1px solid rgba(167,139,250,.25);
      cursor:pointer; transition:all .15s; font-family:'DM Sans',sans-serif;
    }
    .dept-access-btn:hover { background:rgba(167,139,250,.28); color:#c4b5fd; border-color:rgba(167,139,250,.5); }

    /* Change Type Modal */
    .ct-user-info { display:flex; align-items:center; gap:.85rem; margin-bottom:1.25rem;
      padding:.9rem 1rem; background:rgba(255,255,255,.04); border:1px solid var(--border);
      border-radius:12px; }
    .ct-avatar { width:42px; height:42px; border-radius:12px; flex-shrink:0;
      display:flex; align-items:center; justify-content:center;
      font-family:'Sora',sans-serif; font-size:.85rem; font-weight:800;
      background:rgba(67,128,226,.18); color:var(--accent2); border:1px solid rgba(67,128,226,.28); }
    .ct-name { font-weight:700; color:var(--white); font-size:.9rem; }
    .ct-meta { font-size:.74rem; color:var(--w40); margin-top:.15rem; }
    .ct-arrow { display:flex; align-items:center; gap:.5rem; margin:1rem 0 .35rem;
      font-size:.72rem; color:var(--w40); font-weight:600; letter-spacing:.04em; text-transform:uppercase; }
    .ct-arrow::before, .ct-arrow::after { content:''; flex:1; height:1px; background:var(--border); }

    .users-count-info { font-size:.78rem; color:var(--w40); padding:.5rem 0; }
    .filter-select { padding:.45rem .85rem; background:rgba(255,255,255,0.06); border:1px solid var(--border2); border-radius:10px; color:var(--white); font-size:.8rem; outline:none; font-family:'DM Sans',sans-serif; cursor:pointer; }
    .filter-select option { background:#0f1c3a; }

    /* ── Pagination ── */
    .pagination-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem; margin-top:1rem; }
    .pagination-info { font-size:.76rem; color:var(--w40); }
    .pagination-info strong { color:var(--white); }
    .pagination-controls { display:flex; align-items:center; gap:.35rem; }
    .page-btn {
      display:inline-flex; align-items:center; justify-content:center;
      width:32px; height:32px; border-radius:8px; font-size:.78rem; font-weight:600;
      border:1px solid var(--border); background:var(--w08); color:var(--w60);
      cursor:pointer; transition:all .15s; font-family:'DM Sans',sans-serif;
    }
    .page-btn:hover:not(:disabled) { background:var(--accent); border-color:var(--accent); color:#fff; }
    .page-btn:disabled { opacity:.3; cursor:not-allowed; }
    .page-btn.active { background:var(--accent); border-color:var(--accent); color:#fff; }
    .page-ellipsis { color:var(--w40); font-size:.78rem; padding:0 .2rem; }
  </style>
</head>
<body>
<div class="mesh"></div>
<div class="wrap">

  <!-- ── Page Header ── -->
  <div class="page-header">
    <div>
      <div class="breadcrumb"><a href="<?= route('home') ?>">Home</a> &rsaquo; RBAC</div>
      <div class="page-title">Role-Based <span>Access Control</span></div>
      <div class="page-sub">Manage which user types can access each portal module.</div>
    </div>
    <div class="page-header-right">
      <button class="btn btn-success" id="btnAddRole">
        <i class="bi bi-person-plus-fill"></i> Add User Type
      </button>
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
        <div class="stat-chip-num" id="statRoleCount"><?= count($allRoles) ?></div>
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
        <div class="stat-chip-num" id="statGrantCount"><?= $totalGrants ?></div>
        <div class="stat-chip-label">Active Grants</div>
      </div>
    </div>
    <div class="stat-chip" style="margin-left:auto;">
      <i class="bi bi-shield-lock-fill" style="color:var(--accent2)"></i>
      <div class="stat-chip-label">Logged in as <strong style="color:var(--white)"><?= htmlspecialchars($displayName) ?></strong></div>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="roles">
      <i class="bi bi-people-fill"></i> User Types
      <span class="tab-badge" id="tabRoleBadge"><?= count($allRoles) ?></span>
    </button>
    <button class="tab-btn" data-tab="users">
      <i class="bi bi-person-lines-fill"></i> Users
      <span class="tab-badge" id="tabUserBadge"><?= $totalUsers ?></span>
    </button>
    <button class="tab-btn" data-tab="registry">
      <i class="bi bi-grid-fill"></i> Module Registry
      <span class="tab-badge" id="tabModBadge"><?= count($modules) ?></span>
    </button>
  </div>

  <!-- ══════════════════ TAB: USER TYPES ══════════════════ -->
  <div class="tab-panel active" id="tab-roles">

    <!-- Filter row -->
    <div class="filter-row">
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="roleSearch" placeholder="Search user type…">
      </div>
    </div>

    <!-- Role cards -->
    <div class="roles-grid" id="rolesGrid">
      <?php foreach ($allRoles as $role):
        $rn       = $role['role_name'];
        $total    = (int)$role['total'];
        $granted  = $roleGrantCount[$rn] ?? 0;
        $modCount = count($modules);
        $pct      = $modCount > 0 ? round($granted / $modCount * 100) : 0;
        $initials = strtoupper(substr($rn, 0, 2));
      ?>
      <div class="role-card" data-role="<?= htmlspecialchars($rn) ?>" data-total="<?= $total ?>">
        <button class="role-card-del btn btn-sm btn-danger"
                data-role="<?= htmlspecialchars($rn) ?>"
                title="Delete user type"
                onclick="event.stopPropagation()">
          <i class="bi bi-trash3"></i>
        </button>
        <div class="role-card-top">
          <div class="role-avatar"><?= $initials ?></div>
          <div class="role-card-info">
            <div class="role-card-name"><?= htmlspecialchars($rn) ?></div>
            <div class="role-card-sub"><?= $total > 0 ? number_format($total) . ' user' . ($total !== 1 ? 's' : '') : '<em>No users yet</em>' ?></div>
          </div>
          <i class="bi bi-chevron-right role-card-arrow"></i>
        </div>
        <div class="role-grant-bar">
          <div class="role-grant-fill" style="width:<?= $pct ?>%"></div>
        </div>
        <div class="role-card-footer">
          <span class="role-grant-label"><strong><?= $granted ?></strong> / <?= $modCount ?> modules granted</span>
          <span class="role-grant-label"><?= $pct ?>%</span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$allRoles): ?>
      <div class="empty-state" style="grid-column:1/-1">
        <i class="bi bi-people"></i>
        No user types found. Add one to get started.
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /tab-roles -->

  <!-- ══════════════════ TAB: USERS ══════════════════ -->
  <div class="tab-panel" id="tab-users">

    <div class="users-toolbar">
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="userSearch" placeholder="Search by name, username, email…"
               value="<?= htmlspecialchars($usersSearch) ?>">
      </div>
      <select class="filter-select" id="userTypeFilter">
        <option value="">All User Types</option>
        <?php foreach ($allRoles as $role): ?>
        <option value="<?= htmlspecialchars($role['role_name']) ?>"
          <?= $usersTypeFilter === $role['role_name'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($role['role_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select class="filter-select" id="userActiveFilter">
        <option value="">All Statuses</option>
        <option value="1" <?= $usersActFilter === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $usersActFilter === '0' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>

    <div class="users-count-info" id="usersCountInfo">
      <?php
        $from = $totalUsers > 0 ? $usersOffset + 1 : 0;
        $to   = min($usersOffset + $usersPerPage, $totalUsers);
        echo "Showing <strong>{$from}–{$to}</strong> of <strong>{$totalUsers}</strong> user" . ($totalUsers !== 1 ? 's' : '');
        if ($usersSearch || $usersTypeFilter || $usersActFilter !== '') {
            echo ' &nbsp;<a href="?tab=users" style="font-size:.72rem;color:var(--accent2);text-decoration:none">Clear filters ×</a>';
        }
      ?>
    </div>

    <div class="users-table-wrap">
      <table class="users-table" id="usersTable">
        <thead>
          <tr>
            <th>Name</th>
            <th>Username</th>
            <th>Email</th>
            <th>Department</th>
            <th>Position</th>
            <th>User Type</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersTableBody">
          <?php foreach ($users as $u):
            $initials = strtoupper(substr($u['DisplayName'] ?? $u['username'], 0, 2));
            $active   = (int)($u['Active'] ?? 1);
          ?>
          <tr data-id="<?= (int)$u['id'] ?>"
              data-username="<?= htmlspecialchars($u['username']) ?>"
              data-displayname="<?= htmlspecialchars($u['DisplayName'] ?? '') ?>"
              data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
              data-type="<?= htmlspecialchars($u['user_type'] ?? '') ?>"
              data-dept="<?= htmlspecialchars($u['Department'] ?? '') ?>"
              data-active="<?= $active ?>">
            <td class="name-cell">
              <span class="user-name-wrap">
                <span class="user-avatar-sm"><?= $initials ?></span>
                <?= htmlspecialchars($u['DisplayName'] ?: $u['username']) ?>
              </span>
            </td>
            <td class="mono"><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['Department'] ?? '—') ?></td>
            <td><?= htmlspecialchars($u['Position_held'] ?? $u['Job_tittle'] ?? '—') ?></td>
            <td>
              <span class="role-badge">
                <i class="bi bi-shield-fill" style="font-size:.6rem"></i>
                <?= htmlspecialchars($u['user_type'] ?? '—') ?>
              </span>
            </td>
            <td>
              <span class="active-dot <?= $active ? 'on' : 'off' ?>"></span>
              <?= $active ? 'Active' : 'Inactive' ?>
            </td>
            <td style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
              <button class="change-type-btn"
                      data-id="<?= (int)$u['id'] ?>"
                      data-username="<?= htmlspecialchars($u['username']) ?>"
                      data-displayname="<?= htmlspecialchars($u['DisplayName'] ?? $u['username']) ?>"
                      data-dept="<?= htmlspecialchars($u['Department'] ?? '') ?>"
                      data-current-type="<?= htmlspecialchars($u['user_type'] ?? '') ?>"
                      title="Change user type">
                <i class="bi bi-pencil-fill"></i> Change Type
              </button>
              <button class="dept-access-btn"
                      data-id="<?= (int)$u['id'] ?>"
                      data-displayname="<?= htmlspecialchars($u['DisplayName'] ?? $u['username']) ?>"
                      data-dept="<?= htmlspecialchars($u['Department'] ?? '') ?>"
                      title="Manage department access">
                <i class="bi bi-building"></i> Dept Access
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$users): ?>
          <tr><td colspan="8" style="text-align:center;padding:2.5rem;color:var(--w40)">
            <i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
            No users found<?= ($usersSearch || $usersTypeFilter) ? ' matching your filters' : ' in ViewUserLogIn' ?>.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ── Pagination bar ── -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <div class="pagination-info">
        Page <strong><?= $usersPage ?></strong> of <strong><?= $totalPages ?></strong>
      </div>
      <div class="pagination-controls">
        <?php
          // Build base query string preserving filters
          $pagerBase = http_build_query(array_filter([
              'tab'     => 'users',
              'usearch' => $usersSearch,
              'utype'   => $usersTypeFilter,
              'uactive' => $usersActFilter,
          ]));

          function pageBtn(int $pg, int $cur, string $base, string $label = '', bool $isNum = true): string {
              $active  = $isNum && $pg === $cur ? ' active' : '';
              $disable = ($pg < 1) ? ' disabled' : '';
              $lbl     = $label ?: $pg;
              return "<button class='page-btn{$active}'{$disable} onclick=\"goPage($pg,'$base')\">{$lbl}</button>";
          }

          echo pageBtn($usersPage - 1, $usersPage, $pagerBase, '<i class="bi bi-chevron-left"></i>', false);

          // Smart page window
          $window = 2;
          $shown  = [];
          for ($p = 1; $p <= $totalPages; $p++) {
              if ($p === 1 || $p === $totalPages || abs($p - $usersPage) <= $window) $shown[] = $p;
          }
          $prev = null;
          foreach ($shown as $p) {
              if ($prev !== null && $p - $prev > 1) echo "<span class='page-ellipsis'>…</span>";
              echo pageBtn($p, $usersPage, $pagerBase);
              $prev = $p;
          }

          $nextPage = $usersPage < $totalPages ? $usersPage + 1 : -1;
          echo pageBtn($nextPage, $usersPage, $pagerBase, '<i class="bi bi-chevron-right"></i>', false);
        ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /tab-users -->

  <!-- ══════════════════ TAB: MODULE REGISTRY ══════════════════ -->
  <div class="tab-panel" id="tab-registry">
    <div class="registry-header">
      <div class="panel-title">
        Module Registry <span id="moduleRegistryCount">— <?= count($modules) ?> portal card<?= count($modules) !== 1 ? 's' : '' ?></span>
      </div>
      <div class="registry-header-actions">
        <span class="reorder-hint"><i class="bi bi-grip-vertical"></i> Drag cards to reorder</span>
        <button class="btn btn-sm btn-success save-order-btn" id="saveOrderBtn">
          <i class="bi bi-check-lg"></i> Save Order
        </button>
      </div>
    </div>
    <div class="modules-grid" id="modulesGrid">
      <?php foreach ($modules as $i => $mod):
        $catLabel = $categoryMeta[$mod['category']]['label'] ?? $mod['category'];
        $orderNum = $i + 1;
      ?>
      <div class="module-chip"
           draggable="true"
           data-key="<?= htmlspecialchars($mod['module_key']) ?>"
           data-name="<?= htmlspecialchars($mod['module_name']) ?>"
           data-cat="<?= htmlspecialchars($mod['category']) ?>"
           data-icon="<?= htmlspecialchars($mod['icon']) ?>"
           data-color="<?= htmlspecialchars($mod['color']) ?>"
           data-desc="<?= htmlspecialchars($mod['description'] ?? '') ?>">
        <span class="order-badge" title="Sort order"><?= $orderNum ?></span>
        <button class="drag-handle" title="Drag to reorder" onmousedown="event.stopPropagation()">
          <i class="bi bi-grip-vertical"></i>
        </button>
        <div class="chip-top" style="padding-left:1.6rem">
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
            <button class="module-chip-edit" title="Edit module" data-key="<?= htmlspecialchars($mod['module_key']) ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="module-chip-del" title="Delete module"
                    data-key="<?= htmlspecialchars($mod['module_key']) ?>"
                    data-name="<?= htmlspecialchars($mod['module_name']) ?>">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>
        <div class="preview-strip"><i class="bi bi-eye"></i> Homepage card preview ↓</div>
        <div class="hp-card-preview" style="width:100%">
          <i class="bi <?= htmlspecialchars($mod['icon']) ?> prev-icon <?= htmlspecialchars($mod['color']) ?>"></i>
          <div class="prev-name"><?= htmlspecialchars($mod['module_name']) ?></div>
          <div class="prev-desc"><?= htmlspecialchars($mod['description'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div><!-- /tab-registry -->

</div><!-- /.wrap -->

<!-- ══════════════════════════════════════════════════════════
     DRAWER OVERLAY + DRAWER
     ══════════════════════════════════════════════════════════ -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="roleDrawer">
  <div class="drawer-header">
    <div class="drawer-header-left">
      <div class="drawer-avatar" id="drawerAvatar">AD</div>
      <div>
        <div class="drawer-role-name" id="drawerRoleName">Role</div>
        <div class="drawer-role-sub"  id="drawerRoleSub">0 users &middot; 0 grants</div>
      </div>
    </div>
    <button class="drawer-close" id="drawerClose"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="drawer-toolbar">
    <div class="drawer-filter-pills">
      <span class="pill active" data-dcat="all">All</span>
      <span class="pill" data-dcat="hr">HR</span>
      <span class="pill" data-dcat="fleet">Fleet</span>
      <span class="pill" data-dcat="finance">Finance</span>
      <span class="pill" data-dcat="general">General</span>
    </div>
    <div class="drawer-actions">
      <button class="btn btn-sm btn-success" id="drawerGrantAll"><i class="bi bi-check-all"></i> Grant All</button>
      <button class="btn btn-sm btn-danger"  id="drawerRevokeAll"><i class="bi bi-x-lg"></i> Revoke All</button>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody">
    <!-- Populated by JS -->
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ADD USER TYPE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="addRoleModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-title">
      <i class="bi bi-person-plus-fill" style="color:var(--green)"></i>
      Add User Type
    </div>
    <div class="form-group">
      <label class="form-label">User Type Name</label>
      <input class="form-control" id="r_name" placeholder="e.g. Supervisor">
      <div class="form-hint">This becomes a role_name in the permissions table. Use PascalCase, no spaces.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="closeAddRole">Cancel</button>
      <button class="btn btn-primary" id="saveRole">
        <i class="bi bi-check-lg"></i> Create User Type
      </button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     ADD MODULE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="addModuleModal">
  <div class="modal">
    <div class="modal-title">
      <i class="bi bi-plus-circle" style="color:var(--accent2)"></i>
      Add New Module
    </div>
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
      <button class="btn btn-primary" id="saveModule"><i class="bi bi-check-lg"></i> Save Module</button>
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
      <label class="form-label">Description</label>
      <input class="form-control" id="e_desc" placeholder="Short description for the card">
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="closeEditModal">Cancel</button>
      <button class="btn btn-primary" id="updateModule"><i class="bi bi-check-lg"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CONFIRM DELETE OVERLAY
     ══════════════════════════════════════════════════════════ -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="confirm-box-icon"><i class="bi bi-trash3-fill"></i></div>
    <div class="confirm-box-title" id="confirmTitle">Delete?</div>
    <div class="confirm-box-msg"   id="confirmMsg">This action cannot be undone.</div>
    <div class="confirm-box-actions">
      <button class="btn btn-ghost"  id="confirmCancel">Cancel</button>
      <button class="btn btn-danger" id="confirmOk"><i class="bi bi-trash3"></i> Delete</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     CHANGE USER TYPE MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="changeTypeModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-title">
      <i class="bi bi-shield-lock-fill" style="color:var(--accent2)"></i>
      Change User Type
    </div>

    <!-- User info card -->
    <div class="ct-user-info">
      <div class="ct-avatar" id="ct_avatar">AB</div>
      <div>
        <div class="ct-name" id="ct_displayname">—</div>
        <div class="ct-meta" id="ct_meta">—</div>
      </div>
    </div>

    <div class="ct-arrow">New User Type</div>

    <div class="form-group">
      <label class="form-label">Select Role</label>
      <select class="form-control" id="ct_new_type">
        <?php foreach ($allRoles as $role): ?>
        <option value="<?= htmlspecialchars($role['role_name']) ?>"><?= htmlspecialchars($role['role_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint" id="ct_current_hint">Current type will be shown here.</div>
    </div>

    <input type="hidden" id="ct_user_id">

    <div class="modal-footer">
      <button class="btn btn-ghost" id="closeChangeType">Cancel</button>
      <button class="btn btn-primary" id="saveChangeType">
        <i class="bi bi-check-lg"></i> Apply Change
      </button>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     DEPT ACCESS MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="deptAccessModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-title">
      <i class="bi bi-building" style="color:#a78bfa"></i>
      Department Access
    </div>

    <!-- User info card -->
    <div class="ct-user-info">
      <div class="ct-avatar" id="da_avatar">AB</div>
      <div>
        <div class="ct-name" id="da_displayname">—</div>
        <div class="ct-meta" id="da_meta">—</div>
      </div>
    </div>

    <div class="ct-arrow">Allowed Departments</div>
    <div class="form-hint" style="margin-bottom:.75rem">
      Check the departments this user is allowed to access. Unchecked = no access.
    </div>

    <div id="da_dept_list" style="
      display:grid; grid-template-columns:1fr 1fr; gap:.4rem;
      max-height:260px; overflow-y:auto; padding:.25rem 0;
    ">
      <!-- Populated by JS -->
    </div>

    <input type="hidden" id="da_user_id">

    <div class="modal-footer" style="margin-top:1rem">
      <button class="btn btn-ghost" id="closeDeptAccess">Cancel</button>
      <button class="btn btn-primary" id="saveDeptAccess">
        <i class="bi bi-check-lg"></i> Save Access
      </button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const ACTION_URL = '<?= base_url('RBAC/rbac_action.php') ?>';

// ── Data from PHP ─────────────────────────────────────────────
const ALL_MODULES  = <?= $modulesJson ?>;
let   permsMap     = <?= $permsMapJson ?>;  // "role|module_key" => 0|1
const ALL_DEPTS    = <?= $allDeptsJson ?>;
let   deptAccessMap = <?= $deptAccessJson ?>; // { userId: [dept, ...] }

// ── Helpers ───────────────────────────────────────────────────
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

function confirmDialog(title, msg) {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent   = msg;
    const overlay = document.getElementById('confirmOverlay');
    overlay.classList.add('open');
    const ok = document.getElementById('confirmOk'), cancel = document.getElementById('confirmCancel');
    function cleanup(r) { overlay.classList.remove('open'); ok.removeEventListener('click', onOk); cancel.removeEventListener('click', onCancel); resolve(r); }
    const onOk = () => cleanup(true), onCancel = () => cleanup(false);
    ok.addEventListener('click', onOk); cancel.addEventListener('click', onCancel);
  });
}

// ── Tabs ──────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});

// If "Add Module" is clicked while on roles tab, switch to registry first
document.getElementById('btnAddModule').addEventListener('click', () => {
  // Switch to registry tab
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('[data-tab="registry"]').classList.add('active');
  document.getElementById('tab-registry').classList.add('active');
  // Open modal
  ['m_key','m_name','m_icon','m_desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('m_cat').value   = 'general';
  document.getElementById('m_color').value = 'blue';
  updatePreview('add');
  document.getElementById('addModuleModal').classList.add('open');
});

// ── Role search ───────────────────────────────────────────────
document.getElementById('roleSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#rolesGrid .role-card').forEach(card => {
    card.style.display = (card.dataset.role || '').toLowerCase().includes(q) ? '' : 'none';
  });
});

// ── Stats counters ────────────────────────────────────────────
function recountGrants() {
  let total = 0;
  for (const k in permsMap) { if (permsMap[k]) total++; }
  document.getElementById('statGrantCount').textContent = total;
}

function resortRoleCards() {
  const grid  = document.getElementById('rolesGrid');
  const cards = Array.from(grid.querySelectorAll('.role-card'));
  cards.sort((a, b) => {
    const ga = parseInt(a.querySelector('.role-grant-label strong').textContent) || 0;
    const gb = parseInt(b.querySelector('.role-grant-label strong').textContent) || 0;
    return gb - ga || a.dataset.role.localeCompare(b.dataset.role);
  });
  cards.forEach(c => grid.appendChild(c));
}

function updateRoleCard(roleName) {
  const card     = document.querySelector(`.role-card[data-role="${roleName}"]`);
  if (!card) return;
  const modCount = ALL_MODULES.length;
  let   granted  = 0;
  ALL_MODULES.forEach(m => { if (permsMap[roleName + '|' + m.module_key]) granted++; });
  const pct      = modCount > 0 ? Math.round(granted / modCount * 100) : 0;
  card.querySelector('.role-grant-fill').style.width = pct + '%';
  card.querySelector('.role-grant-label strong').textContent = granted;
  card.querySelectorAll('.role-grant-label')[1].textContent  = pct + '%';
}

// ── DRAWER ────────────────────────────────────────────────────
let drawerRole        = '';
let drawerCatFilter   = 'all';
const drawer          = document.getElementById('roleDrawer');
const drawerOverlay   = document.getElementById('drawerOverlay');

function openDrawer(roleName, total) {
  drawerRole = roleName;
  drawerCatFilter = 'all';

  // Reset filter pills
  document.querySelectorAll('[data-dcat]').forEach(p => p.classList.toggle('active', p.dataset.dcat === 'all'));

  // Header
  document.getElementById('drawerAvatar').textContent   = roleName.substring(0,2).toUpperCase();
  document.getElementById('drawerRoleName').textContent = roleName;

  let granted = 0;
  ALL_MODULES.forEach(m => { if (permsMap[roleName + '|' + m.module_key]) granted++; });
  document.getElementById('drawerRoleSub').textContent  =
    (total > 0 ? total + ' user' + (total != 1 ? 's' : '') : 'No users yet') +
    ' · ' + granted + ' / ' + ALL_MODULES.length + ' modules';

  renderDrawerBody();

  drawer.classList.add('open');
  drawerOverlay.classList.add('open');
}

function closeDrawer() {
  drawer.classList.remove('open');
  drawerOverlay.classList.remove('open');
}

function renderDrawerBody() {
  const body   = document.getElementById('drawerBody');
  const cats   = {hr:'HR', fleet:'Fleet', finance:'Finance', general:'General'};
  const colors = {hr:'#34d399', fleet:'#fbbf24', finance:'#a78bfa', general:'#60a5fa'};

  // Group by category
  const grouped = {};
  ALL_MODULES.forEach(m => {
    if (drawerCatFilter !== 'all' && m.category !== drawerCatFilter) return;
    if (!grouped[m.category]) grouped[m.category] = [];
    grouped[m.category].push(m);
  });

  let html = '';
  for (const cat in grouped) {
    html += `<div class="drawer-cat-label" style="color:${colors[cat]||'#60a5fa'}">${cats[cat]||cat}</div>`;
    grouped[cat].forEach(m => {
      const key     = drawerRole + '|' + m.module_key;
      const checked = permsMap[key] ? 'checked' : '';
      const grantedClass = permsMap[key] ? 'granted' : '';
      html += `
        <div class="module-row ${grantedClass}" data-module="${m.module_key}">
          <div class="mod-row-icon ${m.color}"><i class="bi ${m.icon}"></i></div>
          <div class="mod-row-info">
            <div class="mod-row-name">${m.module_name}</div>
            <div class="mod-row-key">${m.module_key}</div>
          </div>
          <label class="toggle" onclick="event.stopPropagation()">
            <input type="checkbox" ${checked} data-role="${drawerRole}" data-module="${m.module_key}">
            <div class="toggle-track"></div>
            <div class="toggle-thumb"></div>
          </label>
        </div>`;
    });
  }

  if (!html) html = `<div class="empty-state"><i class="bi bi-grid"></i> No modules in this category.</div>`;
  body.innerHTML = html;
  updateDrawerSub();
}

function updateDrawerSub() {
  let granted = 0;
  ALL_MODULES.forEach(m => { if (permsMap[drawerRole + '|' + m.module_key]) granted++; });
  const totalUsers = document.querySelector(`.role-card[data-role="${drawerRole}"]`)?.dataset.total || 0;
  document.getElementById('drawerRoleSub').textContent =
    (totalUsers > 0 ? totalUsers + ' user' + (totalUsers != 1 ? 's' : '') : 'No users yet') +
    ' · ' + granted + ' / ' + ALL_MODULES.length + ' modules';
}

// Open drawer on card click
document.getElementById('rolesGrid').addEventListener('click', function(e) {
  const card = e.target.closest('.role-card');
  if (!card) return;
  if (e.target.closest('.role-card-del')) return;
  openDrawer(card.dataset.role, parseInt(card.dataset.total) || 0);
});

// Close drawer
document.getElementById('drawerClose').addEventListener('click', closeDrawer);
drawerOverlay.addEventListener('click', closeDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

// Drawer category filter
document.querySelectorAll('[data-dcat]').forEach(pill => {
  pill.addEventListener('click', function() {
    document.querySelectorAll('[data-dcat]').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    drawerCatFilter = this.dataset.dcat;
    renderDrawerBody();
  });
});

// ── Toggle permission inside drawer ──────────────────────────
document.getElementById('drawerBody').addEventListener('change', async function(e) {
  const cb = e.target;
  if (cb.type !== 'checkbox') return;
  const role   = cb.dataset.role;
  const mod    = cb.dataset.module;
  const action = cb.checked ? 'grant' : 'revoke';

  const row = cb.closest('.module-row');
  row.style.opacity = '.5';
  row.style.pointerEvents = 'none';

  try {
    const fd = new FormData();
    fd.append('action', action); fd.append('role', role); fd.append('module', mod);
    const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
    const data = await res.json();
    if (data.ok) {
      permsMap[role + '|' + mod] = cb.checked ? 1 : 0;
      row.classList.toggle('granted', cb.checked);
      updateDrawerSub();
      updateRoleCard(role);
      recountGrants();
      resortRoleCards();
      toast(`${action === 'grant' ? 'Granted' : 'Revoked'} <strong>${mod}</strong> for <strong>${role}</strong>`);
    } else {
      toast(data.msg || 'Error saving.', 'error');
      cb.checked = !cb.checked;
    }
  } catch(err) {
    toast('Network error.', 'error');
    cb.checked = !cb.checked;
  }
  row.style.opacity = '';
  row.style.pointerEvents = '';
});

// ── Drawer: Grant All / Revoke All ───────────────────────────
async function drawerBulkAction(action) {
  const btn = action === 'grant_all'
    ? document.getElementById('drawerGrantAll')
    : document.getElementById('drawerRevokeAll');
  btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action', action); fd.append('role', drawerRole);
    const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
    const data = await res.json();
    if (data.ok) {
      ALL_MODULES.forEach(m => {
        permsMap[drawerRole + '|' + m.module_key] = action === 'grant_all' ? 1 : 0;
      });
      renderDrawerBody();
      updateRoleCard(drawerRole);
      recountGrants();
      resortRoleCards();
      toast(action === 'grant_all' ? 'All modules granted.' : 'All modules revoked.');
    } else {
      toast(data.msg || 'Error.', 'error');
    }
  } catch(err) {
    toast('Network error.', 'error');
  }
  btn.disabled = false;
}

document.getElementById('drawerGrantAll').addEventListener('click',  () => drawerBulkAction('grant_all'));
document.getElementById('drawerRevokeAll').addEventListener('click', () => drawerBulkAction('revoke_all'));

// ── Add User Type ─────────────────────────────────────────────
const addRoleModal = document.getElementById('addRoleModal');
document.getElementById('btnAddRole').addEventListener('click', () => {
  document.getElementById('r_name').value = '';
  addRoleModal.classList.add('open');
});
document.getElementById('closeAddRole').addEventListener('click',  () => addRoleModal.classList.remove('open'));
addRoleModal.addEventListener('click', e => { if (e.target === addRoleModal) addRoleModal.classList.remove('open'); });

document.getElementById('saveRole').addEventListener('click', async () => {
  const name = document.getElementById('r_name').value.trim();
  if (!name) { toast('Role name is required.', 'error'); return; }
  if (/\s/.test(name)) { toast('Role name cannot contain spaces.', 'error'); return; }

  // Check duplicate in current grid
  if (document.querySelector(`.role-card[data-role="${name}"]`)) {
    toast('User type already exists.', 'error'); return;
  }

  const fd = new FormData();
  fd.append('action',    'add_role');
  fd.append('role_name', name);
  const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error creating role.', 'error'); return; }

  addRoleModal.classList.remove('open');
  toast(`User type <strong>${name}</strong> created.`);

  // Add card to DOM
  const initials = name.substring(0,2).toUpperCase();
  const modCount = ALL_MODULES.length;
  const card = document.createElement('div');
  card.className = 'role-card';
  card.dataset.role  = name;
  card.dataset.total = '0';
  card.innerHTML = `
    <button class="role-card-del btn btn-sm btn-danger" data-role="${name}" title="Delete user type" onclick="event.stopPropagation()">
      <i class="bi bi-trash3"></i>
    </button>
    <div class="role-card-top">
      <div class="role-avatar">${initials}</div>
      <div class="role-card-info">
        <div class="role-card-name">${name}</div>
        <div class="role-card-sub"><em>No users yet</em></div>
      </div>
      <i class="bi bi-chevron-right role-card-arrow"></i>
    </div>
    <div class="role-grant-bar"><div class="role-grant-fill" style="width:0%"></div></div>
    <div class="role-card-footer">
      <span class="role-grant-label"><strong>0</strong> / ${modCount} modules granted</span>
      <span class="role-grant-label">0%</span>
    </div>`;
  document.getElementById('rolesGrid').appendChild(card);

  // Update stat
  const cnt = document.querySelectorAll('#rolesGrid .role-card').length;
  document.getElementById('statRoleCount').textContent = cnt;
  document.getElementById('tabRoleBadge').textContent  = cnt;
});

// ── Delete Role ───────────────────────────────────────────────
document.getElementById('rolesGrid').addEventListener('click', async e => {
  const btn = e.target.closest('.role-card-del');
  if (!btn) return;
  const role = btn.dataset.role;

  const confirmed = await confirmDialog(
    `Delete "${role}"?`,
    `This will remove the user type and revoke all its module permissions. Users still assigned this role in the users table will not be affected.`
  );
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action',    'delete_role');
  fd.append('role_name', role);
  const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error deleting role.', 'error'); return; }

  // Remove card
  btn.closest('.role-card').remove();
  // Clean permsMap for this role
  for (const k in permsMap) { if (k.startsWith(role + '|')) delete permsMap[k]; }
  recountGrants();

  const cnt = document.querySelectorAll('#rolesGrid .role-card').length;
  document.getElementById('statRoleCount').textContent = cnt;
  document.getElementById('tabRoleBadge').textContent  = cnt;
  toast(`User type <strong>${role}</strong> deleted.`);
});

// ── Bootstrap icon list (autocomplete) ───────────────────────
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

function updatePreview(prefix) {
  const icon  = document.getElementById(prefix + '_icon')?.value.trim() || 'bi-grid';
  const name  = document.getElementById(prefix + '_name')?.value.trim() || 'Module Name';
  const color = document.getElementById(prefix + '_color')?.value       || 'blue';
  const desc  = document.getElementById(prefix + '_desc')?.value.trim() || '';
  const iEl   = document.getElementById(prefix + 'PrevIcon');
  const nEl   = document.getElementById(prefix + 'PrevName');
  const dEl   = document.getElementById(prefix + 'PrevDesc');
  if (!iEl) return;
  iEl.className   = `bi ${icon} prev-icon ${color}`;
  nEl.textContent = name || 'Module Name';
  dEl.textContent = desc || '';
}
function wirePreview(prefix, fp) {
  ['_icon','_name','_color','_desc'].forEach(f => {
    const el = document.getElementById(fp + f);
    if (el) el.addEventListener('input',  () => updatePreview(prefix));
    if (el && el.tagName === 'SELECT') el.addEventListener('change', () => updatePreview(prefix));
  });
}
wirePreview('add','m'); wirePreview('edit','e');

function setupIconSearch(inputId, suggestionsId, previewPrefix) {
  const input = document.getElementById(inputId);
  const box   = document.getElementById(suggestionsId);
  input.addEventListener('input', () => {
    const q = input.value.toLowerCase().replace(/^bi-/,'');
    if (!q) { box.classList.remove('open'); return; }
    const matches = BI_ICONS.filter(ic => ic.includes(q)).slice(0,8);
    if (!matches.length) { box.classList.remove('open'); return; }
    box.innerHTML = matches.map(ic => `<div class="icon-sug-item" data-icon="${ic}"><i class="bi ${ic}"></i><span>${ic}</span></div>`).join('');
    box.classList.add('open');
  });
  box.addEventListener('click', e => {
    const item = e.target.closest('.icon-sug-item');
    if (!item) return;
    input.value = item.dataset.icon;
    box.classList.remove('open');
    updatePreview(previewPrefix);
  });
  document.addEventListener('click', e => { if (!input.contains(e.target) && !box.contains(e.target)) box.classList.remove('open'); });
}
setupIconSearch('m_icon','addIconSuggestions','add');
setupIconSearch('e_icon','editIconSuggestions','edit');

// ── Add Module Modal ──────────────────────────────────────────
const addModal = document.getElementById('addModuleModal');
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
  fd.append('action','add_module'); fd.append('module_key',key); fd.append('module_name',name);
  fd.append('category',cat); fd.append('color',color); fd.append('icon',icon); fd.append('description',desc);

  const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error adding module.', 'error'); return; }

  addModal.classList.remove('open');
  toast(`Module <strong>${name}</strong> added successfully.`);

  // Add to ALL_MODULES
  ALL_MODULES.push({ module_key:key, module_name:name, category:cat, icon, color, description:desc });

  // Add chip to registry
  const catLabels = {hr:'HR',fleet:'Fleet',finance:'Finance',general:'General'};
  const chip = document.createElement('div');
  chip.className = 'module-chip';
  Object.assign(chip.dataset, { key, name, cat, icon, color, desc });
  chip.innerHTML = `
    <div class="chip-top">
      <div class="module-chip-icon ${color}"><i class="bi ${icon}"></i></div>
      <div class="chip-info"><div class="module-chip-name">${name}</div><div class="module-chip-key">${key}</div></div>
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

  const cnt = ALL_MODULES.length;
  document.getElementById('statModuleCount').textContent     = cnt;
  document.getElementById('tabModBadge').textContent         = cnt;
  document.getElementById('moduleRegistryCount').textContent = `— ${cnt} portal card${cnt !== 1 ? 's' : ''}`;
  // Refresh open drawer if needed
  if (drawer.classList.contains('open')) renderDrawerBody();
});

// ── Edit Module Modal ─────────────────────────────────────────
const editModal = document.getElementById('editModuleModal');
document.getElementById('modulesGrid').addEventListener('click', e => {
  const editBtn = e.target.closest('.module-chip-edit');
  if (!editBtn) return;
  const chip = editBtn.closest('.module-chip');
  const key  = chip.dataset.key;
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
  fd.append('action','edit_module'); fd.append('module_key',key); fd.append('module_name',name);
  fd.append('category',cat); fd.append('color',color); fd.append('icon',icon); fd.append('description',desc);

  const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error updating module.', 'error'); return; }

  editModal.classList.remove('open');
  toast(`Module <strong>${name}</strong> updated.`);

  // Update ALL_MODULES
  const idx = ALL_MODULES.findIndex(m => m.module_key === key);
  if (idx >= 0) Object.assign(ALL_MODULES[idx], { module_name:name, category:cat, icon, color, description:desc });

  const catColors = {hr:'#34d399',fleet:'#fbbf24',finance:'#a78bfa',general:'#60a5fa'};
  const catLabels = {hr:'HR',fleet:'Fleet',finance:'Finance',general:'General'};

  // Update chip
  const chip = document.querySelector(`.module-chip[data-key="${key}"]`);
  if (chip) {
    Object.assign(chip.dataset, { name, cat, icon, color, desc });
    chip.querySelector('.module-chip-icon').className = `module-chip-icon ${color}`;
    chip.querySelector('.module-chip-icon i').className = `bi ${icon}`;
    chip.querySelector('.module-chip-name').textContent = name;
    chip.querySelector('.chip-desc') && (chip.querySelector('.chip-desc').textContent = desc);
    chip.querySelector('.chip-cat-badge').className   = `chip-cat-badge ${cat}`;
    chip.querySelector('.chip-cat-badge').textContent  = catLabels[cat]||cat;
    const pi = chip.querySelector('.hp-card-preview .prev-icon');
    const pn = chip.querySelector('.hp-card-preview .prev-name');
    const pd = chip.querySelector('.hp-card-preview .prev-desc');
    if (pi) pi.className   = `bi ${icon} prev-icon ${color}`;
    if (pn) pn.textContent = name;
    if (pd) pd.textContent = desc;
  }
  // Refresh drawer if open
  if (drawer.classList.contains('open')) renderDrawerBody();
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
  fd.append('action','delete_module'); fd.append('module_key',key);
  const res  = await fetch(ACTION_URL, { method:'POST', body:fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error deleting.', 'error'); return; }

  btn.closest('.module-chip').remove();

  // Remove from ALL_MODULES
  const idx = ALL_MODULES.findIndex(m => m.module_key === key);
  if (idx >= 0) ALL_MODULES.splice(idx, 1);

  // Clean permsMap
  for (const k in permsMap) { if (k.endsWith('|' + key)) delete permsMap[k]; }
  recountGrants();

  const cnt = ALL_MODULES.length;
  document.getElementById('statModuleCount').textContent     = cnt;
  document.getElementById('tabModBadge').textContent         = cnt;
  document.getElementById('moduleRegistryCount').textContent = `— ${cnt} portal card${cnt !== 1 ? 's' : ''}`;

  // Refresh all role cards' grant bars
  document.querySelectorAll('.role-card[data-role]').forEach(card => updateRoleCard(card.dataset.role));
  // Refresh drawer if open
  if (drawer.classList.contains('open')) renderDrawerBody();

  toast(`Module <strong>${name}</strong> deleted.`);
});
// ══════════════════════════════════════════════════════════════
// USERS TAB
// ══════════════════════════════════════════════════════════════

// ── Server-side filter navigation ────────────────────────────
function goPage(page, baseQs) {
  const url = new URL(window.location.href);
  // Rebuild params from base query string
  const params = new URLSearchParams(baseQs);
  params.set('upage', page);
  params.set('tab', 'users');
  url.search = params.toString();
  window.location.href = url.toString();
}

// Debounced search — waits 400ms after typing before navigating
let searchTimer;
document.getElementById('userSearch').addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    const url = new URL(window.location.href);
    url.searchParams.set('usearch', this.value);
    url.searchParams.set('upage', '1');
    url.searchParams.set('tab', 'users');
    window.location.href = url.toString();
  }, 400);
});

document.getElementById('userTypeFilter').addEventListener('change', function() {
  const url = new URL(window.location.href);
  if (this.value) url.searchParams.set('utype', this.value);
  else url.searchParams.delete('utype');
  url.searchParams.set('upage', '1');
  url.searchParams.set('tab', 'users');
  window.location.href = url.toString();
});

document.getElementById('userActiveFilter').addEventListener('change', function() {
  const url = new URL(window.location.href);
  if (this.value !== '') url.searchParams.set('uactive', this.value);
  else url.searchParams.delete('uactive');
  url.searchParams.set('upage', '1');
  url.searchParams.set('tab', 'users');
  window.location.href = url.toString();
});

// ── Auto-open Users tab if ?tab=users is in URL ───────────────
(function() {
  const params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'users') {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector('.tab-btn[data-tab="users"]').classList.add('active');
    document.getElementById('tab-users').classList.add('active');
  }
})();

// ── Change Type Modal ─────────────────────────────────────────
const changeTypeModal = document.getElementById('changeTypeModal');

document.getElementById('usersTableBody').addEventListener('click', e => {
  const btn = e.target.closest('.change-type-btn');
  if (!btn) return;

  const id          = btn.dataset.id;
  const displayname = btn.dataset.displayname || btn.dataset.username;
  const dept        = btn.dataset.dept || '—';
  const currentType = btn.dataset.currentType || '';
  const initials    = displayname.slice(0, 2).toUpperCase();

  document.getElementById('ct_user_id').value       = id;
  document.getElementById('ct_avatar').textContent  = initials;
  document.getElementById('ct_displayname').textContent = displayname;
  document.getElementById('ct_meta').textContent    = dept;
  document.getElementById('ct_current_hint').innerHTML =
    `Current type: <strong style="color:var(--accent2)">${currentType || '(none)'}</strong>`;

  // Pre-select current type in dropdown
  const sel = document.getElementById('ct_new_type');
  for (let opt of sel.options) {
    opt.selected = (opt.value === currentType);
  }

  changeTypeModal.classList.add('open');
});

document.getElementById('closeChangeType').addEventListener('click', () => changeTypeModal.classList.remove('open'));
changeTypeModal.addEventListener('click', e => { if (e.target === changeTypeModal) changeTypeModal.classList.remove('open'); });

document.getElementById('saveChangeType').addEventListener('click', async () => {
  const userId  = document.getElementById('ct_user_id').value;
  const newType = document.getElementById('ct_new_type').value;
  if (!userId || !newType) { toast('Missing user or type.', 'error'); return; }

  const fd = new FormData();
  fd.append('action',    'change_user_type');
  fd.append('user_id',   userId);
  fd.append('user_type', newType);

  const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) { toast(data.msg || 'Error updating user type.', 'error'); return; }

  changeTypeModal.classList.remove('open');

  // Update the row in-place without a page reload
  const row = document.querySelector(`#usersTableBody tr[data-id="${userId}"]`);
  if (row) {
    row.dataset.type = newType;
    row.querySelector('.role-badge').innerHTML =
      `<i class="bi bi-shield-fill" style="font-size:.6rem"></i> ${newType}`;
    // Update the button's current-type so re-opening it is accurate
    const btn = row.querySelector('.change-type-btn');
    if (btn) btn.dataset.currentType = newType;
  }

  toast(`User type updated to <strong>${newType}</strong>.`);
});

// ══════════════════════════════════════════════════════════════
// MODULE DRAG-AND-DROP REORDER  (localStorage — no DB needed)
// ══════════════════════════════════════════════════════════════

(function () {
  const grid        = document.getElementById('modulesGrid');
  const saveBtn     = document.getElementById('saveOrderBtn');
  const LS_KEY      = 'rbac_module_order';
  let   dragSrc     = null;
  let   orderDirty  = false;

  // ── Apply saved order from localStorage on page load ─────────
  function applyStoredOrder() {
    const stored = localStorage.getItem(LS_KEY);
    if (!stored) return;
    let keys;
    try { keys = JSON.parse(stored); } catch { return; }

    keys.forEach(key => {
      const chip = grid.querySelector(`.module-chip[data-key="${CSS.escape(key)}"]`);
      if (chip) grid.appendChild(chip); // moves to end in stored sequence
    });
    refreshBadges();
  }

  function refreshBadges() {
    grid.querySelectorAll('.module-chip').forEach((chip, i) => {
      chip.querySelector('.order-badge').textContent = i + 1;
    });
  }

  function markDirty() {
    orderDirty = true;
    saveBtn.classList.add('visible');
  }

  function currentKeyOrder() {
    return [...grid.querySelectorAll('.module-chip')].map(c => c.dataset.key);
  }

  // Apply on load immediately
  applyStoredOrder();

  // ── Drag events ──────────────────────────────────────────────
  grid.addEventListener('dragstart', e => {
    const chip = e.target.closest('.module-chip');
    if (!chip) return;
    dragSrc = chip;
    chip.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', chip.dataset.key);
  });

  grid.addEventListener('dragend', e => {
    const chip = e.target.closest('.module-chip');
    if (chip) chip.classList.remove('dragging');
    grid.querySelectorAll('.module-chip').forEach(c => c.classList.remove('drag-over'));
    dragSrc = null;
  });

  grid.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const chip = e.target.closest('.module-chip');
    if (!chip || chip === dragSrc) return;
    grid.querySelectorAll('.module-chip').forEach(c => c.classList.remove('drag-over'));
    chip.classList.add('drag-over');
  });

  grid.addEventListener('dragleave', e => {
    const chip = e.target.closest('.module-chip');
    if (chip) chip.classList.remove('drag-over');
  });

  grid.addEventListener('drop', e => {
    e.preventDefault();
    const target = e.target.closest('.module-chip');
    if (!target || target === dragSrc || !dragSrc) return;

    const chips  = [...grid.querySelectorAll('.module-chip')];
    const srcIdx = chips.indexOf(dragSrc);
    const tgtIdx = chips.indexOf(target);
    grid.insertBefore(dragSrc, tgtIdx > srcIdx ? target.nextSibling : target);

    target.classList.remove('drag-over');
    refreshBadges();
    markDirty();
  });

  // ── Save to localStorage ──────────────────────────────────────
  saveBtn.addEventListener('click', () => {
    localStorage.setItem(LS_KEY, JSON.stringify(currentKeyOrder()));
    orderDirty = false;
    saveBtn.classList.remove('visible');
    toast('Module order saved. Homepage will reflect the new order.');
  });

  // ── Warn before leaving with unsaved changes ──────────────────
  window.addEventListener('beforeunload', e => {
    if (orderDirty) { e.preventDefault(); e.returnValue = ''; }
  });

})();

// ══════════════════════════════════════════════════════════════
// DEPT ACCESS MODAL
// ══════════════════════════════════════════════════════════════
const deptAccessModal = document.getElementById('deptAccessModal');

document.getElementById('usersTableBody').addEventListener('click', e => {
  const btn = e.target.closest('.dept-access-btn');
  if (!btn) return;

  const id           = btn.dataset.id;
  const displayname  = btn.dataset.displayname || '—';
  const dept         = btn.dataset.dept || '—';
  const initials     = displayname.slice(0, 2).toUpperCase();
  const currentDepts = deptAccessMap[id] || [];

  document.getElementById('da_user_id').value          = id;
  document.getElementById('da_avatar').textContent     = initials;
  document.getElementById('da_displayname').textContent = displayname;
  document.getElementById('da_meta').textContent       = 'Primary dept: ' + dept;

  // Build checkbox list
  const list = document.getElementById('da_dept_list');
  list.innerHTML = ALL_DEPTS.map(d => {
    const checked = currentDepts.includes(d) ? 'checked' : '';
    return `
      <label style="display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;
             border-radius:8px;cursor:pointer;border:1px solid var(--border);
             background:var(--surface);font-size:.8rem;transition:background .15s"
             onmouseover="this.style.background='rgba(255,255,255,.07)'"
             onmouseout="this.style.background='var(--surface)'">
        <input type="checkbox" value="${d.replace(/"/g, '&quot;')}" ${checked}
               style="accent-color:#a78bfa;width:15px;height:15px;cursor:pointer">
        <span style="color:var(--w60)">${d}</span>
      </label>`;
  }).join('');

  deptAccessModal.classList.add('open');
});

document.getElementById('closeDeptAccess').addEventListener('click', () =>
  deptAccessModal.classList.remove('open'));
deptAccessModal.addEventListener('click', e => {
  if (e.target === deptAccessModal) deptAccessModal.classList.remove('open');
});

document.getElementById('saveDeptAccess').addEventListener('click', async () => {
  const userId = document.getElementById('da_user_id').value;
  const checked = [...document.querySelectorAll('#da_dept_list input[type=checkbox]:checked')]
                    .map(cb => cb.value);

  const fd = new FormData();
  fd.append('action',      'manage_dept_access');
  fd.append('user_id',     userId);
  fd.append('departments', JSON.stringify(checked));

  const res  = await fetch(ACTION_URL, { method: 'POST', body: fd });
  const data = await res.json();

  if (!data.ok) { toast(data.msg || 'Error saving dept access.', 'error'); return; }

  // Update local cache so re-opening modal reflects latest state
  deptAccessMap[userId] = checked;

  deptAccessModal.classList.remove('open');
  toast(`Department access updated — <strong>${data.count}</strong> dept${data.count !== 1 ? 's' : ''} assigned.`);
});


</script>
</body>
</html>