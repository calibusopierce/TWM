<?php
// ══════════════════════════════════════════════════════════════════════════════
// RBAC / index.php  —  Role-Based Access Control dashboard
// ══════════════════════════════════════════════════════════════════════════════

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/rbac_helper.php';

auth_check();
rbac_gate($pdo, 'RBAC');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

$isViewOnly  = rbac_is_view_only('RBAC');
$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

// ── Modules ───────────────────────────────────────────────────────────────────
$modules = $pdo->query("
    SELECT * FROM rbac_modules ORDER BY sort_order ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Roles: from users table + standalone rbac_roles table ────────────────────
$rolesFromUsers = $pdo->query("
    SELECT DISTINCT user_type AS role_name, COUNT(*) AS total
    FROM   ViewUserLogIn
    WHERE  user_type IS NOT NULL 
      AND  user_type != ''
      AND  Active = 1
    GROUP  BY user_type
")->fetchAll(PDO::FETCH_ASSOC);

// ── Auto-create rbac_roles if missing — guarded so it only runs once per process ──
// We use a static flag to avoid re-running this DDL check on every page load.
// For a permanent solution, move this to a migration script and remove it here.
static $rbacRolesChecked = false;
if (!$rbacRolesChecked) {
    $rbacRolesChecked = true;
    $pdo->exec("
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'rbac_roles'
        )
        BEGIN
            CREATE TABLE rbac_roles (
                role_name  NVARCHAR(100) NOT NULL PRIMARY KEY,
                created_by NVARCHAR(100) NULL,
                created_at DATETIME      NOT NULL DEFAULT GETDATE()
            )
        END
    ");
}

$extraRoles = $pdo->query("
    SELECT role_name, 0 AS total FROM rbac_roles
    WHERE  role_name NOT IN (
        SELECT DISTINCT user_type FROM users WHERE user_type IS NOT NULL AND user_type != ''
    )
")->fetchAll(PDO::FETCH_ASSOC);

$allRoles = array_merge($rolesFromUsers, $extraRoles);

// ── Permissions flat map ──────────────────────────────────────────────────────
$permsRaw = $pdo->query("
    SELECT role_name, module_key, can_access, permission_level FROM rbac_permissions
")->fetchAll(PDO::FETCH_ASSOC);

$permsMap = [];
foreach ($permsRaw as $p) {
    if ((int)$p['can_access']) {
        $permsMap[$p['role_name'] . '|' . $p['module_key']] = $p['permission_level'] ?? 'full';
    }
}

// ── Per-role grant counts + sort ──────────────────────────────────────────────
$roleGrantCount = [];
foreach ($permsRaw as $p) {
    if ($p['can_access']) {
        $roleGrantCount[$p['role_name']] = ($roleGrantCount[$p['role_name']] ?? 0) + 1;
    }
}

usort($allRoles, fn($a, $b) =>
    ($roleGrantCount[$b['role_name']] ?? 0) <=> ($roleGrantCount[$a['role_name']] ?? 0)
    ?: strcmp($a['role_name'], $b['role_name'])
);

// ── Category meta ─────────────────────────────────────────────────────────────
$categoryMeta = [
    'hr'      => ['label' => 'HR',      'color' => '#34d399'],
    'fleet'   => ['label' => 'Fleet',   'color' => '#fbbf24'],
    'finance' => ['label' => 'Finance', 'color' => '#a78bfa'],
    'general' => ['label' => 'General', 'color' => '#60a5fa'],
];

// ── JSON blobs for JS ─────────────────────────────────────────────────────────
$modulesJson  = json_encode(array_values($modules));
$permsMapJson = json_encode($permsMap);
$totalGrants  = count(array_filter($permsRaw, fn($p) => $p['can_access']));
$isViewOnlyJs = $isViewOnly ? 'true' : 'false';

// ── User counts by type (still used by role cards in User Types tab) ──────────
$userCountByType = [];
foreach ($rolesFromUsers as $r) {
    $userCountByType[$r['role_name']] = (int)$r['total'];
}

// ── Departments ───────────────────────────────────────────────────────────────
$allDepts = $pdo->query("
    SELECT DISTINCT Department FROM ViewUserLogIn
    WHERE  Department IS NOT NULL AND Department != ''
    ORDER  BY Department ASC
")->fetchAll(PDO::FETCH_COLUMN);

$deptAccessRaw = $pdo->query("
    SELECT UserID, Department FROM Tbl_UserAccessDepartment
")->fetchAll(PDO::FETCH_ASSOC);

$deptAccessMap = [];
foreach ($deptAccessRaw as $row) {
    $deptAccessMap[(int)$row['UserID']][] = $row['Department'];
}

$allDeptsJson   = json_encode($allDepts);
$deptAccessJson = json_encode($deptAccessMap);

// ── Per-user module access map ────────────────────────────────────────────────
$userAccessRaw = $pdo->query("
    SELECT user_id, module_key, permission_level FROM rbac_user_access WHERE is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);

$userAccessMap = [];
foreach ($userAccessRaw as $row) {
    $userAccessMap[(int)$row['user_id']][$row['module_key']] = $row['permission_level'] ?? 'full';
}
$userAccessJson = json_encode($userAccessMap);

// ── Audit log (server-side paginated) ─────────────────────────────────────────
$auditPerPage  = 20;
$auditPage     = max(1, (int)($_GET['apage']   ?? 1));
$auditSearch   = trim($_GET['asearch'] ?? '');
$auditAction   = trim($_GET['aaction'] ?? '');
$auditDateFrom = trim($_GET['afrom']   ?? '');
$auditDateTo   = trim($_GET['ato']     ?? '');

$auditWhere  = [];
$auditParams = [];

if ($auditSearch !== '') {
    $auditWhere[]  = "(performed_by LIKE ? OR target_user LIKE ? OR module_key LIKE ? OR role_name LIKE ?)";
    $lk = '%' . $auditSearch . '%';
    $auditParams   = array_merge($auditParams, [$lk, $lk, $lk, $lk]);
}
if ($auditAction   !== '') { $auditWhere[] = "action_type = ?";   $auditParams[] = $auditAction; }
if ($auditDateFrom !== '') { $auditWhere[] = "performed_at >= ?"; $auditParams[] = $auditDateFrom . ' 00:00:00'; }
if ($auditDateTo   !== '') { $auditWhere[] = "performed_at <= ?"; $auditParams[] = $auditDateTo   . ' 23:59:59'; }

$auditWhereSQL = $auditWhere ? 'WHERE ' . implode(' AND ', $auditWhere) : '';

$auditCountStmt = $pdo->prepare("SELECT COUNT(*) FROM rbac_audit_log $auditWhereSQL");
$auditCountStmt->execute($auditParams);
$auditTotal      = (int)$auditCountStmt->fetchColumn();
$auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));
$auditPage       = min($auditPage, $auditTotalPages);
$auditOffset     = ($auditPage - 1) * $auditPerPage;

$auditStmt = $pdo->prepare("
    SELECT id, action_type, target_user, target_uid, module_key,
           role_name, performed_by, ip_address, notes,
           CONVERT(VARCHAR(19), performed_at, 120) AS performed_at
    FROM   rbac_audit_log
    $auditWhereSQL
    ORDER  BY performed_at DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
");
$pi = 1;
foreach ($auditParams as $val) { $auditStmt->bindValue($pi++, $val); }
$auditStmt->bindValue($pi++, $auditOffset, PDO::PARAM_INT);
$auditStmt->bindValue($pi,   $auditPerPage, PDO::PARAM_INT);
$auditStmt->execute();
$auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Pagination helper ─────────────────────────────────────────────────────────
// Builds a URL for a given audit page, preserving current filters.
function auditPageUrl(int $pg, string $search, string $action, string $from, string $to): string {
    return '?' . http_build_query(array_filter([
        'tab'     => 'audit',
        'apage'   => $pg,
        'asearch' => $search,
        'aaction' => $action,
        'afrom'   => $from,
        'ato'     => $to,
    ]));
}

// ── Action badge colours (shared PHP+HTML) ────────────────────────────────────
$actionColors = [
    'grant'            => ['bg' => 'rgba(52,211,153,.12)',  'color' => '#34d399'],
    'grant_view'       => ['bg' => 'rgba(52,211,153,.08)',  'color' => '#6ee7b7'],
    'revoke'           => ['bg' => 'rgba(248,113,113,.12)', 'color' => '#f87171'],
    'toggle'           => ['bg' => 'rgba(251,191,36,.12)',  'color' => '#fbbf24'],
    'assign_access'    => ['bg' => 'rgba(67,128,226,.12)',  'color' => '#93c5fd'],
    'change_user_type' => ['bg' => 'rgba(167,139,250,.12)', 'color' => '#a78bfa'],
    'grant_all'        => ['bg' => 'rgba(52,211,153,.18)',  'color' => '#10b981'],
    'revoke_all'       => ['bg' => 'rgba(248,113,113,.18)', 'color' => '#ef4444'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RBAC · Role Based Access Control</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <style>
    /* ══ CSS VARIABLES ═══════════════════════════════════════════════════════ */
    :root {
      --bg-0:    #060d1f;
      --bg-1:    #0b1530;
      --bg-2:    #101d3e;
      --surface: rgba(255,255,255,0.04);
      --border:  rgba(255,255,255,0.08);
      --border2: rgba(255,255,255,0.14);
      --white:   #ffffff;
      --w60:     rgba(255,255,255,.60);
      --w40:     rgba(255,255,255,.40);
      --w15:     rgba(255,255,255,.15);
      --w08:     rgba(255,255,255,.08);
      --accent:  #4380e2;
      --accent2: #93c5fd;
      --green:   #34d399;
      --amber:   #fbbf24;
      --red:     #f87171;
      --purple:  #a78bfa;
      --on-color:  #34d399;
      --off-color: rgba(255,255,255,.15);
    }

    /* ══ RESET & BASE ════════════════════════════════════════════════════════ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%; font-family: 'DM Sans', sans-serif;
      background: var(--bg-0); color: var(--white); overflow-x: hidden;
    }

    /* ══ BACKGROUND MESH ═════════════════════════════════════════════════════ */
    .mesh {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background:
        radial-gradient(ellipse 80% 50% at 10% 0%,  rgba(67,128,226,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(52,211,153,.10) 0%, transparent 60%),
        radial-gradient(ellipse 100% 80% at 50% 50%, rgba(6,13,31,1) 40%, transparent 100%);
    }


    /* ══ LAYOUT ══════════════════════════════════════════════════════════════ */
    .wrap { position: relative; z-index: 10; max-width: 1300px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

    /* ══ PAGE HEADER ═════════════════════════════════════════════════════════ */
    .page-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;
      animation: fadeUp .4s ease both;
    }
    .breadcrumb  { font-size: .72rem; color: var(--w40); letter-spacing: .06em; text-transform: uppercase; margin-bottom: .4rem; }
    .breadcrumb a { color: var(--accent2); text-decoration: none; }
    .breadcrumb a:hover { text-decoration: underline; }
    .page-title  { font-family: 'Sora', sans-serif; font-size: 1.75rem; font-weight: 800; letter-spacing: -.04em; color: var(--white); line-height: 1.1; }
    .page-title span { color: var(--accent2); }
    .page-sub    { font-size: .82rem; color: var(--w60); margin-top: .35rem; }
    .page-header-right { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }

    /* ══ BUTTONS ═════════════════════════════════════════════════════════════ */
    .btn {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .5rem 1.1rem; border-radius: 10px;
      font-size: .8rem; font-weight: 600; cursor: pointer;
      border: 1px solid transparent; transition: background .2s, border-color .2s, color .2s;
      text-decoration: none; font-family: 'DM Sans', sans-serif;
    }
    .btn-primary  { background: var(--accent);              color: #fff;          border-color: rgba(67,128,226,.5); }
    .btn-primary:hover  { background: #3370d4; }
    .btn-ghost    { background: var(--w08);                 color: var(--w60);    border-color: var(--border); }
    .btn-ghost:hover    { background: var(--w15);           color: var(--white); }
    .btn-success  { background: rgba(52,211,153,.12);       color: #34d399;       border: 1px solid rgba(52,211,153,.25); }
    .btn-success:hover  { background: rgba(52,211,153,.22); }
    .btn-danger   { background: rgba(239,68,68,.15);        color: #fca5a5;       border-color: rgba(239,68,68,.3); }
    .btn-danger:hover   { background: rgba(239,68,68,.25); }
    .btn-sm { padding: .3rem .7rem; font-size: .72rem; }

    /* ══ STATS BAR ═══════════════════════════════════════════════════════════ */
    .stats-bar { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.75rem; animation: fadeUp .4s .1s ease both; }
    .stat-chip {
      display: flex; align-items: center; gap: .6rem;
      padding: .6rem 1rem; background: var(--surface);
      border: 1px solid var(--border); border-radius: 12px; font-size: .78rem;
    }
    .stat-chip-num   { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--white); }
    .stat-chip-label { color: var(--w60); }
    .stat-chip .dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

    /* ══ TABS ════════════════════════════════════════════════════════════════ */
    .tab-bar {
      display: flex; gap: .5rem; margin-bottom: 1.5rem;
      border-bottom: 1px solid var(--border); padding-bottom: .75rem;
      animation: fadeUp .4s .12s ease both;
    }
    .tab-btn {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .45rem 1rem; border-radius: 10px 10px 0 0;
      font-size: .82rem; font-weight: 600; cursor: pointer;
      border: 1px solid transparent; border-bottom: none;
      background: transparent; color: var(--w40); transition: background .2s, color .2s;
      font-family: 'DM Sans', sans-serif; position: relative; top: 1px;
    }
    .tab-btn:hover { color: var(--white); background: var(--w08); }
    .tab-btn.active { background: var(--bg-1); border-color: var(--border); color: var(--white); border-bottom-color: var(--bg-0); }
    .tab-btn .tab-badge {
      background: var(--accent); color: #fff;
      font-size: .6rem; font-weight: 700; padding: .1rem .4rem;
      border-radius: 999px; margin-left: .15rem;
    }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ══ ROLE CARDS ══════════════════════════════════════════════════════════ */
    .roles-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: .85rem; animation: fadeUp .4s .2s ease both;
    }
    .role-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 18px; padding: 1.25rem 1.25rem 1rem;
      cursor: pointer; transition: border-color .2s, background .2s, transform .15s;
      display: flex; flex-direction: column; gap: .75rem; position: relative;
    }
    .role-card:hover { border-color: var(--border2); background: rgba(255,255,255,.06); }
    .role-card-top   { display: flex; align-items: center; gap: .75rem; }
    .role-avatar {
      width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-size: .82rem; font-weight: 800;
      background: rgba(67,128,226,.18); color: var(--accent2);
      border: 1px solid rgba(67,128,226,.28);
    }
    .role-card-info  { flex: 1; min-width: 0; }
    .role-card-name  { font-size: .92rem; font-weight: 700; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .role-card-sub   { font-size: .72rem; color: var(--w40); margin-top: .1rem; }
    .role-card-arrow { color: var(--w40); font-size: .9rem; transition: color .15s, transform .15s; }
    .role-card:hover .role-card-arrow { color: var(--accent2); transform: translateX(2px); }

    /* Grant progress bar */
    .role-grant-bar  { height: 4px; background: var(--border); border-radius: 999px; overflow: hidden; }
    .role-grant-fill { height: 100%; background: var(--green); border-radius: 999px; transition: width .4s ease; }
    .role-card-footer { display: flex; align-items: center; justify-content: space-between; }
    .role-grant-label { font-size: .7rem; color: var(--w40); }
    .role-grant-label strong { color: var(--green); }

    /* Delete button (appears on hover) */
    .role-card-del {
      position: absolute; top: .65rem; right: .65rem;
      background: none; border: none; cursor: pointer; font-size: .8rem;
      color: var(--w40); padding: .2rem .35rem; border-radius: 6px;
      transition: color .15s, background .15s; opacity: 0; pointer-events: none;
    }

    /* ══ ROLE CARD ACTION BUTTONS ════════════════════════════════════════════ */
.role-card-actions {
  display: flex; gap: .5rem;
  overflow: hidden; max-height: 0;
  opacity: 0; transition: max-height .25s ease, opacity .2s ease;
  margin-top: -.25rem;
}
.role-card.expanded .role-card-actions {
  max-height: 44px; opacity: 1;
}
.role-card.expanded .role-card-arrow {
  transform: rotate(90deg);
}
.rc-btn-roles,
.rc-btn-users {
  flex: 1; padding: .4rem 0;
  border-radius: 10px; border: 1px solid var(--border2);
  background: var(--w08); color: var(--w60);
  font-size: .75rem; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: .35rem;
  font-family: 'DM Sans', sans-serif; transition: background .15s, border-color .15s, color .15s;
}
.rc-btn-roles:hover {
  background: rgba(67,128,226,.18); border-color: rgba(67,128,226,.4); color: var(--accent2);
}
.rc-btn-users:hover {
  background: rgba(52,211,153,.12); border-color: rgba(52,211,153,.3); color: var(--green);
}

/* ══ USERS DRAWER ════════════════════════════════════════════════════════ */
.users-drawer {
  position: fixed; top: 0; right: 0; bottom: 0; z-index: 160;
  width: min(520px, 95vw); background: #0a1428;
  border-left: 1px solid var(--border2);
  display: flex; flex-direction: column;
  transform: translateX(100%); transition: transform .3s cubic-bezier(.4,0,.2,1);
  box-shadow: -24px 0 64px rgba(0,0,0,.5);
}
.users-drawer.open { transform: translateX(0); }

.udrawer-header {
  display: flex; align-items: center; gap: .85rem;
  padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.udrawer-avatar {
  width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 800;
  background: rgba(52,211,153,.15); color: var(--green);
  border: 1px solid rgba(52,211,153,.25);
}
.udrawer-title { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 700; }
.udrawer-sub   { font-size: .74rem; color: var(--w40); margin-top: .15rem; }

.udrawer-search {
  padding: .75rem 1.5rem; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.udrawer-body  { flex: 1; overflow-y: auto; padding: .75rem 1.5rem; }

.udr-row {
  display: flex; align-items: center; gap: .85rem;
  padding: .75rem 0; border-bottom: 1px solid var(--border);
}
.udr-row:last-child { border-bottom: none; }
.udr-avatar {
  width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-family: 'Sora', sans-serif; font-size: .72rem; font-weight: 800;
  background: rgba(67,128,226,.18); color: var(--accent2);
  border: 1px solid rgba(67,128,226,.28);
}
.udr-name  { font-size: .85rem; font-weight: 600; color: var(--white); line-height: 1.3; }
.udr-meta  { font-size: .72rem; color: var(--w40); margin-top: .1rem; }
.udr-pills { display: flex; flex-wrap: wrap; gap: .2rem; margin-top: .25rem; }
    .role-card:hover .role-card-del { opacity: 1; pointer-events: auto; }
    .role-card-del:hover { color: var(--red); background: rgba(248,113,113,.12); }

    /* ══ DRAWER ══════════════════════════════════════════════════════════════ */
    .drawer-overlay {
      display: none; position: fixed; inset: 0; z-index: 150;
      background: rgba(0,0,0,.6);
    }
    .drawer-overlay.open { display: block; }

    .drawer {
      position: fixed; top: 0; right: 0; bottom: 0; z-index: 160;
      width: min(680px, 95vw); background: #0a1428;
      border-left: 1px solid var(--border2);
      display: flex; flex-direction: column;
      transform: translateX(100%); transition: transform .3s cubic-bezier(.4,0,.2,1);
      box-shadow: -24px 0 64px rgba(0,0,0,.5);
    }
    .drawer.open { transform: translateX(0); }

    .drawer-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); flex-shrink: 0;
    }
    .drawer-header-left { display: flex; align-items: center; gap: .85rem; }
    .drawer-avatar {
      width: 44px; height: 44px; border-radius: 13px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 800;
      background: rgba(67,128,226,.2); color: var(--accent2); border: 1px solid rgba(67,128,226,.3);
    }
    .drawer-role-name { font-family: 'Sora', sans-serif; font-size: 1.1rem; font-weight: 700; }
    .drawer-role-sub  { font-size: .74rem; color: var(--w40); margin-top: .15rem; }
    .drawer-close {
      background: var(--w08); border: 1px solid var(--border); color: var(--w60);
      width: 32px; height: 32px; border-radius: 8px; cursor: pointer; font-size: 1rem;
      display: flex; align-items: center; justify-content: center; transition: background .15s, color .15s;
    }
    .drawer-close:hover { background: var(--w15); color: var(--white); }

    .drawer-toolbar {
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
      gap: .5rem; padding: .75rem 1.5rem; border-bottom: 1px solid var(--border); flex-shrink: 0;
    }
    .drawer-filter-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
    .drawer-actions      { display: flex; gap: .4rem; }
    .drawer-body         { flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem; }

    /* ══ MODULE ROWS (inside drawer) ═════════════════════════════════════════ */
    .drawer-cat-label {
      font-size: .68rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
      color: var(--w40); margin: 1.25rem 0 .6rem;
      padding-bottom: .35rem; border-bottom: 1px solid var(--border);
    }
    .drawer-cat-label:first-child { margin-top: 0; }

    .module-row {
      display: flex; align-items: center; gap: .9rem;
      padding: .75rem .9rem; border-radius: 12px;
      transition: background .15s; cursor: pointer;
      border: 1px solid transparent; margin-bottom: .35rem;
    }
    .module-row:hover  { background: rgba(255,255,255,.04); border-color: var(--border); }
    .module-row.granted { background: rgba(52,211,153,.05); border-color: rgba(52,211,153,.15); }

    .mod-row-icon {
      width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1rem;
    }
    .mod-row-info { flex: 1; min-width: 0; }
    .mod-row-name { font-size: .85rem; font-weight: 600; color: var(--white); }
    .mod-row-key  { font-size: .68rem; color: var(--w40); font-family: monospace; margin-top: .1rem; }

    /* ══ COLOUR THEMES (icons + chips) ══════════════════════════════════════ */
    .green  { background: rgba(52,211,153,.15);  border: 1px solid rgba(52,211,153,.25);  color: #34d399; }
    .amber  { background: rgba(251,191,36,.15);  border: 1px solid rgba(251,191,36,.25);  color: #fbbf24; }
    .purple { background: rgba(167,139,250,.15); border: 1px solid rgba(167,139,250,.25); color: #a78bfa; }
    .blue   { background: rgba(96,165,250,.15);  border: 1px solid rgba(96,165,250,.25);  color: #60a5fa; }

    /* ══ 3-STATE PERMISSION SELECT ═══════════════════════════════════════════ */
    /* Native <option> elements can't be fully styled cross-browser, but setting
       background/color overrides the OS white-on-white default in Chrome/Edge. */
    .perm-select option { background: #0f1c3a; color: #f1f5f9; }
    .perm-select {
      appearance: none; -webkit-appearance: none;
      background: var(--surface); border: 1px solid var(--border);
      color: var(--w60); border-radius: 8px; flex-shrink: 0;
      padding: .28rem .65rem .28rem .5rem; font-size: .72rem;
      font-family: 'DM Sans', sans-serif; cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,.3)'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right .4rem center;
      padding-right: 1.4rem; transition: border-color .15s, color .15s;
    }
    .perm-select:hover { border-color: var(--border2); color: var(--white); }
    .perm-select.is-full      { border-color: rgba(52,211,153,.4);  color: #34d399; background-color: rgba(52,211,153,.08); }
    .perm-select.is-view_only { border-color: rgba(251,191,36,.4);  color: #fbbf24; background-color: rgba(251,191,36,.08); }
    .perm-select.is-none      { border-color: var(--border); color: var(--w40); }

    /* ══ MODULE REGISTRY TAB ═════════════════════════════════════════════════ */
    .registry-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 1rem; flex-wrap: wrap; gap: .75rem;
      animation: fadeUp .4s .2s ease both;
    }
    .registry-header-actions { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .reorder-hint { font-size: .72rem; color: var(--w40); display: flex; align-items: center; gap: .35rem; }
    .reorder-hint i { color: var(--accent2); }
    .save-order-btn          { display: none !important; }
    .save-order-btn.visible  { display: inline-flex !important; }
    .panel-title { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; color: var(--white); }
    .panel-title span { color: var(--w40); font-size: .8rem; font-weight: 400; }

    .modules-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: .75rem;
    }
    .module-chip {
      background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
      padding: 1rem 1rem .85rem; display: flex; flex-direction: column; gap: .6rem;
      transition: border-color .2s, background .2s, box-shadow .15s, transform .15s;
      position: relative;
    }
    .module-chip:hover       { border-color: var(--border2); background: rgba(255,255,255,.06); }
    .module-chip.dragging    { opacity: .35; border-color: var(--accent); }
    .module-chip.drag-over   { border-color: var(--accent2); background: rgba(147,197,253,.06); }

    .order-badge {
      position: absolute; top: .6rem; left: .6rem;
      min-width: 20px; height: 20px; padding: 0 .3rem; border-radius: 6px;
      background: var(--accent); color: #fff;
      font-size: .6rem; font-weight: 800; font-family: 'Sora', sans-serif;
      display: flex; align-items: center; justify-content: center; z-index: 2;
    }
    .drag-handle {
      position: absolute; top: .6rem; right: 2.6rem;
      color: var(--w40); font-size: .9rem; cursor: grab;
      padding: .2rem .3rem; border-radius: 5px;
      transition: color .15s, background .15s; opacity: 0; pointer-events: none;
      background: none; border: none;
    }
    .module-chip:hover .drag-handle { opacity: 1; pointer-events: auto; }
    .drag-handle:hover  { color: var(--accent2); background: rgba(147,197,253,.1); }
    .drag-handle:active { cursor: grabbing; }

    .chip-top        { display: flex; align-items: center; gap: .75rem; }
    .module-chip-icon { width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .chip-info       { flex: 1; min-width: 0; }
    .module-chip-name { font-size: .84rem; font-weight: 600; color: var(--white); }
    .module-chip-key  { font-size: .68rem; color: var(--w40); font-family: monospace; margin-top: .1rem; }
    .chip-desc        { font-size: .72rem; color: var(--w60); line-height: 1.45; padding: 0 .1rem; }
    .chip-footer      { display: flex; align-items: center; gap: .4rem; margin-top: .1rem; }
    .chip-cat-badge   { padding: .15rem .5rem; border-radius: 5px; font-size: .62rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .chip-cat-badge.hr      { background: rgba(52,211,153,.15);  color: #34d399; }
    .chip-cat-badge.fleet   { background: rgba(251,191,36,.15);  color: #fbbf24; }
    .chip-cat-badge.finance { background: rgba(167,139,250,.15); color: #a78bfa; }
    .chip-cat-badge.general { background: rgba(96,165,250,.15);  color: #60a5fa; }
    .chip-actions { margin-left: auto; display: flex; gap: .3rem; }
    .module-chip-edit, .module-chip-del {
      background: none; border: none; cursor: pointer;
      font-size: .8rem; padding: .25rem .4rem; border-radius: 6px; transition: color .15s, background .15s;
      color: var(--w40);
    }
    .module-chip-edit:hover { color: var(--accent2); background: rgba(147,197,253,.1); }
    .module-chip-del:hover  { color: var(--red);     background: rgba(248,113,113,.1); }

    .preview-strip {
      margin-top: .6rem; padding: .65rem .9rem;
      background: rgba(255,255,255,.03); border-radius: 10px;
      border: 1px solid rgba(255,255,255,.06);
      font-size: .72rem; color: var(--w40); display: flex; align-items: center; gap: .5rem;
    }
    .hp-card-preview {
      display: inline-flex; flex-direction: column; align-items: center;
      background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.15);
      border-radius: 18px; padding: 1.4rem 1.1rem 1.2rem;
      min-width: 150px; max-width: 190px; text-align: center;
      box-shadow: 0 4px 20px rgba(8,23,61,.2);
    }
    .hp-card-preview .prev-icon { font-size: 2.1rem; margin-bottom: .7rem; display: block; line-height: 1; }
    .hp-card-preview .prev-name { font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 700; margin-bottom: .3rem; }
    .hp-card-preview .prev-desc { font-size: .72rem; color: var(--w60); line-height: 1.45; }

    /* ══ SEARCH / FILTER ROW ═════════════════════════════════════════════════ */
    .filter-row { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1.25rem; animation: fadeUp .4s .15s ease both; }
    .search-box {
      display: flex; align-items: center; gap: .5rem;
      background: var(--surface); border: 1px solid var(--border2);
      border-radius: 10px; padding: .45rem .85rem;
      flex: 1; min-width: 200px; max-width: 320px;
    }
    .search-box i { color: var(--w40); font-size: .9rem; }
    .search-box input { background: none; border: none; outline: none; color: var(--white); font-size: .82rem; width: 100%; font-family: 'DM Sans', sans-serif; }
    .search-box input::placeholder { color: var(--w40); }

    .filter-select {
      padding: .45rem .85rem; background: rgba(255,255,255,.06);
      border: 1px solid var(--border2); border-radius: 10px;
      color: var(--white); font-size: .8rem; outline: none;
      font-family: 'DM Sans', sans-serif; cursor: pointer;
    }
    .filter-select option { background: #0f1c3a; }

    .pill { padding: .3rem .8rem; border-radius: 999px; font-size: .72rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border2); background: var(--w08); color: var(--w60); transition: background .15s, border-color .15s, color .15s; }
    .pill.active, .pill:hover { background: var(--accent); border-color: var(--accent); color: #fff; }

    /* ══ MODALS ══════════════════════════════════════════════════════════════ */
    .modal-backdrop {
      display: none; position: fixed; inset: 0; z-index: 200;
      background: rgba(0,0,0,.65);
      align-items: center; justify-content: center;
    }
    .modal-backdrop.open { display: flex; }
    .modal {
      background: #0f1c3a; border: 1px solid var(--border2); border-radius: 20px;
      padding: 1.75rem; width: 100%; max-width: 520px;
      box-shadow: 0 24px 64px rgba(0,0,0,.5); animation: popIn .2s ease both;
    }
    .modal-title  { font-family: 'Sora', sans-serif; font-size: 1.05rem; font-weight: 700; margin-bottom: 1.25rem; display: flex; align-items: center; gap: .5rem; }
    .modal-footer { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1.25rem; }
    .form-group   { margin-bottom: 1rem; }
    .form-label   { display: block; font-size: .74rem; font-weight: 600; color: var(--w60); margin-bottom: .35rem; letter-spacing: .04em; text-transform: uppercase; }
    .form-control {
      width: 100%; padding: .55rem .85rem; background: rgba(255,255,255,.06);
      border: 1px solid var(--border2); border-radius: 10px;
      color: var(--white); font-size: .82rem; outline: none;
      transition: border-color .2s; font-family: 'DM Sans', sans-serif;
    }
    .form-control:focus { border-color: var(--accent); }
    select.form-control option { background: #0f1c3a; }
    .form-row { display: flex; gap: .75rem; }
    .form-row .form-group { flex: 1; }
    .form-hint { font-size: .72rem; color: var(--w40); margin-top: .3rem; }

    .card-preview-wrap  { margin-bottom: 1.25rem; background: rgba(255,255,255,.03); border: 1px solid var(--border); border-radius: 14px; padding: .9rem 1rem; }
    .card-preview-label { font-size: .66rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--w40); margin-bottom: .65rem; }

    .icon-search-wrap   { position: relative; }
    .icon-suggestions   { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 10; background: #0f1c3a; border: 1px solid var(--border2); border-radius: 10px; max-height: 160px; overflow-y: auto; display: none; }
    .icon-suggestions.open { display: block; }
    .icon-sug-item { display: flex; align-items: center; gap: .6rem; padding: .45rem .8rem; cursor: pointer; font-size: .8rem; transition: background .1s; }
    .icon-sug-item:hover { background: rgba(255,255,255,.06); }
    .icon-sug-item i { font-size: 1rem; color: var(--accent2); width: 20px; text-align: center; }

    /* ══ CONFIRM OVERLAY ═════════════════════════════════════════════════════ */
    .confirm-overlay { display: none; position: fixed; inset: 0; z-index: 300; background: rgba(0,0,0,.7); align-items: center; justify-content: center; }
    .confirm-overlay.open { display: flex; }
    .confirm-box {
      background: #0f1c3a; border: 1px solid rgba(248,113,113,.3); border-radius: 18px;
      padding: 1.75rem; max-width: 380px; width: 100%;
      box-shadow: 0 24px 64px rgba(0,0,0,.6); animation: popIn .2s ease both; text-align: center;
    }
    .confirm-box-icon    { font-size: 2rem; color: var(--red); margin-bottom: .75rem; }
    .confirm-box-title   { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; margin-bottom: .5rem; }
    .confirm-box-msg     { font-size: .82rem; color: var(--w60); margin-bottom: 1.25rem; line-height: 1.5; }
    .confirm-box-actions { display: flex; gap: .6rem; justify-content: center; }

    /* ══ TOAST ═══════════════════════════════════════════════════════════════ */
    .toast-wrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 400;
      display: flex; flex-direction: column; gap: .5rem; pointer-events: none;
    }
    .toast {
      display: flex; align-items: center; gap: .6rem; padding: .65rem 1rem;
      background: #0f1c3a; border: 1px solid var(--border2); border-radius: 12px;
      font-size: .8rem; box-shadow: 0 8px 24px rgba(0,0,0,.4);
      animation: toastIn .25s ease both; pointer-events: auto;
    }
    .toast.success { border-color: rgba(52,211,153,.4); }
    .toast.error   { border-color: rgba(248,113,113,.4); }
    .toast i.success { color: var(--green); }
    .toast i.error   { color: var(--red); }

    /* ══ USERS TAB ═══════════════════════════════════════════════════════════ */
    .users-toolbar { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; margin-bottom: 1.25rem; }
    .users-toolbar .search-box { flex: 1; min-width: 180px; }
    .users-count-info { font-size: .78rem; color: var(--w40); padding: .5rem 0; }

    .users-table-wrap { overflow-x: auto; border-radius: 16px; border: 1px solid var(--border); }
    .users-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
    .users-table thead th {
      padding: .7rem 1rem; text-align: left; font-size: .68rem; font-weight: 700;
      letter-spacing: .06em; text-transform: uppercase; color: var(--w40);
      background: rgba(255,255,255,.03); border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    .users-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
    .users-table tbody tr:last-child { border-bottom: none; }
    .users-table tbody tr:hover { background: rgba(255,255,255,.04); }
    .users-table td { padding: .65rem 1rem; color: var(--w60); vertical-align: middle; }
    .users-table td.name-cell { color: var(--white); font-weight: 600; }
    .users-table td.mono { font-family: monospace; font-size: .75rem; color: var(--w40); }

    .user-avatar-sm {
      width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
      display: inline-flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-size: .65rem; font-weight: 800;
      background: rgba(67,128,226,.18); color: var(--accent2);
      border: 1px solid rgba(67,128,226,.28); vertical-align: middle; margin-right: .5rem;
    }
    .user-name-wrap { display: inline-flex; align-items: center; }
    .active-dot     { display: inline-block; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
    .active-dot.on  { background: var(--green); box-shadow: 0 0 5px var(--green); }
    .active-dot.off { background: var(--w40); }

    .rbac-role-pill {
      display: inline-flex; align-items: center; gap: .25rem;
      padding: .15rem .5rem; border-radius: 999px; font-size: .66rem; font-weight: 700;
      background: rgba(52,211,153,.12); color: #34d399;
      border: 1px solid rgba(52,211,153,.2); margin: .1rem;
    }
    .rbac-no-roles { font-size: .7rem; color: var(--w40); font-style: italic; }

    /* ══ MANAGE-ACCESS MODAL ═════════════════════════════════════════════════ */
    .ct-user-info {
      display: flex; align-items: center; gap: .85rem; margin-bottom: 1.25rem;
      padding: .9rem 1rem; background: rgba(255,255,255,.04);
      border: 1px solid var(--border); border-radius: 12px;
    }
    .ct-avatar {
      width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-family: 'Sora', sans-serif; font-size: .85rem; font-weight: 800;
      background: rgba(67,128,226,.18); color: var(--accent2); border: 1px solid rgba(67,128,226,.28);
    }
    .ct-name { font-weight: 700; color: var(--white); font-size: .9rem; }
    .ct-meta { font-size: .74rem; color: var(--w40); margin-top: .15rem; }
    .ma-tab-pane { display: none; }
    .ma-tab-pane.active { display: block; }
    /* Modal tab buttons: fully self-contained, no tab-btn inheritance */
    .ma-tab-btn {
      display: inline-flex; align-items: center; gap: .35rem;
      padding: .35rem .85rem; border-radius: 10px;
      font-size: .76rem; font-weight: 600; cursor: pointer;
      border: 1px solid var(--border2);
      background: var(--w08); color: var(--w60);
      font-family: 'DM Sans', sans-serif;
      transition: background .15s, border-color .15s, color .15s; white-space: nowrap;
    }
    .ma-tab-btn:hover { background: var(--w15); color: var(--white); }
    .ma-tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

    /* ══ PAGINATION ══════════════════════════════════════════════════════════ */
    .pagination-bar {
      display: flex; align-items: center; justify-content: space-between;
      flex-wrap: wrap; gap: .75rem; margin-top: 1rem;
    }
    .pagination-info { font-size: .76rem; color: var(--w40); }
    .pagination-info strong { color: var(--white); }
    .pagination-controls { display: flex; align-items: center; gap: .35rem; }
    .page-btn {
      display: inline-flex; align-items: center; justify-content: center;
      width: 32px; height: 32px; border-radius: 8px; font-size: .78rem; font-weight: 600;
      border: 1px solid var(--border); background: var(--w08); color: var(--w60);
      cursor: pointer; transition: background .15s, border-color .15s, color .15s; font-family: 'DM Sans', sans-serif;
    }
    .page-btn:hover:not(:disabled) { background: var(--accent); border-color: var(--accent); color: #fff; }
    .page-btn:disabled { opacity: .3; cursor: not-allowed; }
    .page-btn.active   { background: var(--accent); border-color: var(--accent); color: #fff; }
    .page-ellipsis { color: var(--w40); font-size: .78rem; padding: 0 .2rem; }

    /* ══ KEYFRAMES ════════════════════════════════════════════════════════════ */
    @keyframes fadeUp  { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes popIn   { from { opacity: 0; transform: scale(.94) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes toastOut { to { opacity: 0; transform: translateX(20px); } }
    @keyframes spin    { to { transform: rotate(360deg); } }

    /* ══ EMPTY STATE ═════════════════════════════════════════════════════════ */
    .empty-state { text-align: center; padding: 3rem 1rem; color: var(--w40); font-size: .85rem; }
    .empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .4; }

    /* ══ RESPONSIVE ══════════════════════════════════════════════════════════ */
    @media (max-width: 640px) {
      .page-title { font-size: 1.35rem; }
      .wrap       { padding: 1.25rem 1rem 3rem; }
      .form-row   { flex-direction: column; gap: 0; }
    }
    /* ══ VISUAL PICKERS (category, color, icon) ══════════════════════════════ */
    .picker-pills { display: flex; gap: .4rem; flex-wrap: wrap; }

    /* Category pills */
    .cat-pill {
      padding: .35rem .85rem; border-radius: 8px; font-size: .78rem; font-weight: 600;
      border: 1px solid var(--border2); background: var(--surface); color: var(--w60);
      cursor: pointer; font-family: 'DM Sans', sans-serif;
      transition: background .15s, border-color .15s, color .15s;
    }
    .cat-pill:hover { color: var(--pill-color); border-color: var(--pill-color); }
    .cat-pill.active {
      background: color-mix(in srgb, var(--pill-color) 15%, transparent);
      border-color: var(--pill-color); color: var(--pill-color);
    }

    /* Color swatches — replaced by color wheel */
    .color-wheel-wrap {
      display: flex; align-items: center; gap: .65rem; flex-wrap: wrap;
    }
    .color-wheel-input {
      width: 36px; height: 36px; border-radius: 8px; border: 2px solid var(--border2);
      padding: 1px; background: none; cursor: pointer;
    }
    .color-wheel-label {
      font-family: monospace; font-size: .78rem; color: var(--w60);
    }
    .color-presets {
      display: flex; gap: .3rem; flex-wrap: wrap;
    }
    .color-preset {
      width: 22px; height: 22px; border-radius: 50%;
      border: 2px solid transparent; cursor: pointer;
      transition: border-color .1s, transform .1s;
    }
    .color-preset:hover  { border-color: var(--white); transform: scale(1.15); }
    .color-preset.active { border-color: var(--white); transform: scale(1.2); }

    /* Icon picker */
    .icon-picker-wrap {
      background: rgba(255,255,255,.04); border: 1px solid var(--border2);
      border-radius: 12px; overflow: hidden;
    }
    .icon-picker-search {
      display: flex; align-items: center; gap: .5rem;
      padding: .5rem .75rem; border-bottom: 1px solid var(--border);
    }
    .icon-picker-search i { color: var(--w40); font-size: .85rem; flex-shrink: 0; }
    .icon-picker-search input {
      background: none; border: none; outline: none;
      color: var(--white); font-size: .8rem; width: 100%;
      font-family: 'DM Sans', sans-serif;
    }
    .icon-picker-search input::placeholder { color: var(--w40); }
    .icon-picker-selected {
      display: flex; align-items: center; gap: .5rem;
      padding: .4rem .75rem; background: rgba(67,128,226,.1);
      border-bottom: 1px solid var(--border);
      font-size: .75rem; color: var(--accent2); font-family: monospace;
    }
    .icon-picker-selected i { font-size: 1rem; }
    .icon-picker-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(36px, 1fr));
      gap: 2px; padding: .5rem; max-height: 160px; overflow-y: auto;
    }
    .icon-grid-item {
      display: flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 8px; cursor: pointer;
      font-size: 1.05rem; color: var(--w60);
      transition: background .1s, color .1s;
      border: 1px solid transparent;
    }
    .icon-grid-item:hover { background: rgba(255,255,255,.08); color: var(--white); }
    .icon-grid-item.active { background: rgba(67,128,226,.2); border-color: rgba(67,128,226,.5); color: var(--accent2); }
  </style>
</head>
<body>
<div class="mesh"></div>
<div class="wrap">

  <!-- ══ PAGE HEADER ══════════════════════════════════════════════════════════ -->
  <div class="page-header">
    <div>
      <div class="breadcrumb"><a href="<?= route('home') ?>">Home</a> &rsaquo; RBAC</div>
      <div class="page-title">Role-Based <span>Access Control</span></div>
      <div class="page-sub">Manage which user types can access each portal module.</div>
    </div>
    <div class="page-header-right">
      <?php if (!$isViewOnly): ?>
      <button class="btn btn-success" id="btnAddRole">
        <i class="bi bi-person-plus-fill"></i> Add User Type
      </button>
      <button class="btn btn-primary" id="btnAddModule">
        <i class="bi bi-plus-lg"></i> Add Module
      </button>
      <?php else: ?>
      <span class="stat-chip" style="font-size:.75rem;color:var(--amber)">
        <i class="bi bi-eye-fill"></i> View Only
      </span>
      <?php endif; ?>
      <a href="<?= route('home') ?>" class="btn btn-ghost">
        <i class="bi bi-arrow-left"></i> Back
      </a>
    </div>
  </div>

  <!-- ══ STATS BAR ════════════════════════════════════════════════════════════ -->
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
    <div class="stat-chip" style="margin-left:auto">
      <i class="bi bi-shield-lock-fill" style="color:var(--accent2)"></i>
      <div class="stat-chip-label">
        Logged in as <strong style="color:var(--white)"><?= htmlspecialchars($displayName) ?></strong>
      </div>
    </div>
  </div>

  <!-- ══ TAB BAR ══════════════════════════════════════════════════════════════ -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="roles">
      <i class="bi bi-people-fill"></i> User Types
      <span class="tab-badge" id="tabRoleBadge"><?= count($allRoles) ?></span>
    </button>
    <button class="tab-btn" data-tab="registry">
      <i class="bi bi-grid-fill"></i> Module Registry
      <span class="tab-badge" id="tabModBadge"><?= count($modules) ?></span>
    </button>
    <button class="tab-btn" data-tab="audit">
      <i class="bi bi-clock-history"></i> Audit Log
      <span class="tab-badge" style="background:#f87171"><?= $auditTotal ?></span>
    </button>
  </div>

  <!-- ══════════════════ TAB: USER TYPES ═══════════════════════════════════ -->
  <div class="tab-panel active" id="tab-roles">
    <div class="filter-row">
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="roleSearch" placeholder="Search user type…">
      </div>
    </div>

    <div class="roles-grid" id="rolesGrid">
      <?php foreach ($allRoles as $role):
        $rn      = $role['role_name'];
        $total   = (int)$role['total'];
        $granted = $roleGrantCount[$rn] ?? 0;
        $modCount = count($modules);
        $pct     = $modCount > 0 ? round($granted / $modCount * 100) : 0;
      ?>
      <div class="role-card" data-role="<?= htmlspecialchars($rn) ?>" data-total="<?= $total ?>">
    <?php if (!$isViewOnly): ?>
    <button class="role-card-del btn btn-sm btn-danger"
            data-role="<?= htmlspecialchars($rn) ?>"
            title="Delete user type"
            onclick="event.stopPropagation()">
      <i class="bi bi-trash3"></i>
    </button>
    <?php endif; ?>
    <div class="role-card-top">
      <div class="role-avatar"><?= strtoupper(substr($rn, 0, 2)) ?></div>
      <div class="role-card-info">
        <div class="role-card-name"><?= htmlspecialchars($rn) ?></div>
        <div class="role-card-sub">
          <?= $total > 0 ? number_format($total) . ' user' . ($total !== 1 ? 's' : '') : '<em>No users yet</em>' ?>
        </div>
      </div>
      <i class="bi bi-chevron-right role-card-arrow" style="transition:transform .2s"></i>
    </div>
    <div class="role-grant-bar">
      <div class="role-grant-fill" style="width:<?= $pct ?>%"></div>
    </div>
    <div class="role-card-footer">
      <span class="role-grant-label"><strong><?= $granted ?></strong> / <?= $modCount ?> modules granted</span>
      <span class="role-grant-label"><?= $pct ?>%</span>
    </div>
    <!-- NEW: action buttons revealed on card click -->
    <div class="role-card-actions">
      <button class="rc-btn-roles" onclick="event.stopPropagation(); openDrawer('<?= htmlspecialchars($rn, ENT_QUOTES) ?>', <?= $total ?>)">
        <i class="bi bi-shield-check"></i> Roles
      </button>
      <button class="rc-btn-users" onclick="event.stopPropagation(); openUsersDrawer('<?= htmlspecialchars($rn, ENT_QUOTES) ?>', <?= $total ?>)">
        <i class="bi bi-people"></i> Users
      </button>
    </div>
  </div>
      <?php endforeach; ?>
      <?php if (!$allRoles): ?>
      <div class="empty-state" style="grid-column:1/-1">
        <i class="bi bi-people"></i>No user types found. Add one to get started.
      </div>
      <?php endif; ?>
    </div>
  </div><!-- /tab-roles -->

  <!-- ══════════════════ TAB: MODULE REGISTRY ══════════════════════════════ -->
  <div class="tab-panel" id="tab-registry">
    <div class="registry-header">
      <div class="panel-title">
        Module Registry
        <span id="moduleRegistryCount">— <?= count($modules) ?> portal card<?= count($modules) !== 1 ? 's' : '' ?></span>
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
      ?>
      <div class="module-chip"
           draggable="true"
           data-key="<?= htmlspecialchars($mod['module_key']) ?>"
           data-name="<?= htmlspecialchars($mod['module_name']) ?>"
           data-cat="<?= htmlspecialchars($mod['category']) ?>"
           data-icon="<?= htmlspecialchars($mod['icon']) ?>"
           data-color="<?= htmlspecialchars($mod['color']) ?>"
           data-desc="<?= htmlspecialchars($mod['description'] ?? '') ?>">

        <span class="order-badge" title="Sort order"><?= $i + 1 ?></span>
        <button class="drag-handle" title="Drag to reorder" onmousedown="event.stopPropagation()">
          <i class="bi bi-grip-vertical"></i>
        </button>

        <?php
          $chipColorRaw = $mod['color'] ?? 'blue';
          $legacyColors = ['blue'=>'#60a5fa','green'=>'#34d399','amber'=>'#fbbf24','purple'=>'#a78bfa'];
          $chipHex = $legacyColors[$chipColorRaw] ?? $chipColorRaw;
        ?>
        <div class="chip-top" style="padding-left:1.6rem">
          <div class="module-chip-icon" style="color:<?= htmlspecialchars($chipHex) ?>;background:<?= htmlspecialchars($chipHex) ?>1a;border:1px solid <?= htmlspecialchars($chipHex) ?>40">
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
            <?php if (!$isViewOnly): ?>
            <button class="module-chip-edit" title="Edit module" data-key="<?= htmlspecialchars($mod['module_key']) ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="module-chip-del"
                    title="Delete module"
                    data-key="<?= htmlspecialchars($mod['module_key']) ?>"
                    data-name="<?= htmlspecialchars($mod['module_name']) ?>">
              <i class="bi bi-trash3"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="preview-strip"><i class="bi bi-eye"></i> Homepage card preview ↓</div>
        <div class="hp-card-preview" style="width:100%">
          <i class="bi <?= htmlspecialchars($mod['icon']) ?> prev-icon" style="color:<?= htmlspecialchars($chipHex) ?>"></i>
          <div class="prev-name"><?= htmlspecialchars($mod['module_name']) ?></div>
          <div class="prev-desc"><?= htmlspecialchars($mod['description'] ?? '') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div><!-- /tab-registry -->

  <!-- ══════════════════ TAB: AUDIT LOG ════════════════════════════════════ -->
  <div class="tab-panel" id="tab-audit">

    <div class="users-toolbar" style="margin-bottom:1.25rem">
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="auditSearch"
               placeholder="Search user, module, role…"
               value="<?= htmlspecialchars($auditSearch) ?>">
      </div>
      <select class="filter-select" id="auditActionFilter">
        <option value="">All Actions</option>
        <option value="assign_access"    <?= $auditAction === 'assign_access'    ? 'selected' : '' ?>>Assign Access</option>
        <option value="grant"            <?= $auditAction === 'grant'            ? 'selected' : '' ?>>Grant (Full)</option>
        <option value="grant_view"       <?= $auditAction === 'grant_view'       ? 'selected' : '' ?>>Grant (View-Only)</option>
        <option value="revoke"           <?= $auditAction === 'revoke'           ? 'selected' : '' ?>>Revoke</option>
        <option value="toggle"           <?= $auditAction === 'toggle'           ? 'selected' : '' ?>>Toggle</option>
        <option value="grant_all"        <?= $auditAction === 'grant_all'        ? 'selected' : '' ?>>Grant All</option>
        <option value="revoke_all"       <?= $auditAction === 'revoke_all'       ? 'selected' : '' ?>>Revoke All</option>
        <option value="change_user_type" <?= $auditAction === 'change_user_type' ? 'selected' : '' ?>>Change User Type</option>
      </select>
      <input type="date" class="filter-select" id="auditFrom"
             value="<?= htmlspecialchars($auditDateFrom) ?>" title="From date">
      <input type="date" class="filter-select" id="auditTo"
             value="<?= htmlspecialchars($auditDateTo) ?>" title="To date">
      <button class="btn btn-ghost btn-sm" onclick="applyAuditFilters()">
        <i class="bi bi-funnel"></i> Filter
      </button>
      <?php if ($auditSearch || $auditAction || $auditDateFrom || $auditDateTo): ?>
      <a href="?tab=audit" class="btn btn-ghost btn-sm">
        <i class="bi bi-x-lg"></i> Clear
      </a>
      <?php endif; ?>
    </div>

    <div class="users-count-info">
      <?php
        $af = $auditTotal > 0 ? $auditOffset + 1 : 0;
        $at = min($auditOffset + $auditPerPage, $auditTotal);
        echo "Showing <strong>{$af}–{$at}</strong> of <strong>{$auditTotal}</strong> log entr" . ($auditTotal !== 1 ? 'ies' : 'y');
      ?>
    </div>

    <div class="users-table-wrap" style="margin-top:.75rem">
      <table class="users-table">
        <thead>
          <tr>
            <th>When</th><th>Action</th><th>Performed By</th>
            <th>Target User</th><th>Module / Role</th><th>Notes</th><th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($auditLogs as $log):
            $ac = $actionColors[$log['action_type']] ?? ['bg' => 'rgba(255,255,255,.06)', 'color' => '#fff'];
          ?>
          <tr>
            <td class="mono" style="white-space:nowrap"><?= htmlspecialchars($log['performed_at']) ?></td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;
                           border-radius:999px;font-size:.7rem;font-weight:700;
                           background:<?= $ac['bg'] ?>;color:<?= $ac['color'] ?>">
                <?= htmlspecialchars($log['action_type']) ?>
              </span>
            </td>
            <td style="color:var(--white);font-weight:600"><?= htmlspecialchars($log['performed_by']) ?></td>
            <td><?= $log['target_user'] ? htmlspecialchars($log['target_user']) : '—' ?></td>
            <td class="mono">
              <?= $log['module_key'] ? htmlspecialchars($log['module_key']) : '' ?>
              <?= $log['role_name']  ? '<span style="color:var(--accent2)">' . htmlspecialchars($log['role_name']) . '</span>' : '' ?>
              <?= (!$log['module_key'] && !$log['role_name']) ? '—' : '' ?>
            </td>
            <td style="font-size:.75rem;color:var(--w60);max-width:220px">
              <?= $log['notes'] ? htmlspecialchars($log['notes']) : '—' ?>
            </td>
            <td class="mono" style="font-size:.72rem">
              <?= $log['ip_address'] ? htmlspecialchars($log['ip_address']) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$auditLogs): ?>
          <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--w40)">
            <i class="bi bi-clock-history" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
            No audit logs found.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($auditTotalPages > 1): ?>
    <div class="pagination-bar">
      <div class="pagination-info">
        Page <strong><?= $auditPage ?></strong> of <strong><?= $auditTotalPages ?></strong>
      </div>
      <div class="pagination-controls">

        <?php // ── Prev button ────────────────────────────────────────────── ?>
        <?php if ($auditPage > 1): ?>
          <a href="<?= auditPageUrl($auditPage - 1, $auditSearch, $auditAction, $auditDateFrom, $auditDateTo) ?>"
             class="page-btn"><i class="bi bi-chevron-left"></i></a>
        <?php else: ?>
          <button class="page-btn" disabled><i class="bi bi-chevron-left"></i></button>
        <?php endif; ?>

        <?php
        // ── Number buttons with ellipsis ───────────────────────────────────
        // Always show: first page, last page, current page ±2
        $prev = null;
        for ($p = 1; $p <= $auditTotalPages; $p++):
            $show = ($p === 1 || $p === $auditTotalPages || abs($p - $auditPage) <= 2);
            if (!$show) continue;
            if ($prev !== null && $p - $prev > 1): ?>
              <span class="page-ellipsis">…</span>
            <?php endif; ?>
            <?php if ($p === $auditPage): ?>
              <button class="page-btn active"><?= $p ?></button>
            <?php else: ?>
              <a href="<?= auditPageUrl($p, $auditSearch, $auditAction, $auditDateFrom, $auditDateTo) ?>"
                 class="page-btn"><?= $p ?></a>
            <?php endif; ?>
        <?php $prev = $p; endfor; ?>

        <?php // ── Next button ────────────────────────────────────────────── ?>
        <?php if ($auditPage < $auditTotalPages): ?>
          <a href="<?= auditPageUrl($auditPage + 1, $auditSearch, $auditAction, $auditDateFrom, $auditDateTo) ?>"
             class="page-btn"><i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
          <button class="page-btn" disabled><i class="bi bi-chevron-right"></i></button>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

  </div><!-- /tab-audit -->

</div><!-- /.wrap -->
<!-- ══════════════════════════════════════════════════════════════════════════
     USERS DRAWER (per user-type user list)
══════════════════════════════════════════════════════════════════════════════ -->
<div class="users-drawer" id="usersDrawer">

  <div class="udrawer-header">
    <div class="udrawer-avatar" id="udrawer_avatar">AD</div>
    <div style="flex:1;min-width:0">
      <div class="udrawer-title" id="udrawer_title">Users</div>
      <div class="udrawer-sub"   id="udrawer_sub">0 active users</div>
    </div>
    <button class="drawer-close" id="usersDrawerClose"><i class="bi bi-x-lg"></i></button>
  </div>

  <div class="udrawer-search">
    <div class="search-box" style="max-width:100%">
      <i class="bi bi-search"></i>
      <input type="text" id="udrawer_search" placeholder="Filter by name or username…">
    </div>
  </div>

  <div class="udrawer-body" id="udrawer_body">
    <div id="udrawer_spinner" style="text-align:center;padding:3rem;color:var(--w40);display:none">
      <i class="bi bi-arrow-repeat" style="font-size:1.5rem;animation:spin .8s linear infinite;display:block;margin-bottom:.5rem"></i>
      Loading users…
    </div>
    <div id="udrawer_list"></div>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     DRAWER (role permission editor)
══════════════════════════════════════════════════════════════════════════════ -->
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
      <?php if (!$isViewOnly): ?>
      <button class="btn btn-sm btn-success" id="drawerGrantAll"><i class="bi bi-check-all"></i> Grant All</button>
      <button class="btn btn-sm btn-danger"  id="drawerRevokeAll"><i class="bi bi-x-lg"></i> Revoke All</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     ADD USER TYPE MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="addRoleModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-title">
      <i class="bi bi-person-plus-fill" style="color:var(--green)"></i> Add User Type
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

<!-- ══════════════════════════════════════════════════════════════════════════
     ADD MODULE MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="addModuleModal">
  <div class="modal">
    <div class="modal-title">
      <i class="bi bi-plus-circle" style="color:var(--accent2)"></i> Add New Module
    </div>
    <div class="card-preview-wrap">
      <div class="card-preview-label"><i class="bi bi-eye"></i> &nbsp;Live Homepage Card Preview</div>
      <div class="hp-card-preview" id="addPreviewCard">
        <i class="bi bi-grid prev-icon" style="color:#60a5fa" id="addPrevIcon"></i>
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
        <input class="form-control" id="m_cat" value="general" placeholder="e.g. hr, fleet, finance, general">
        <div class="form-hint">Used for grouping. Common values: hr, fleet, finance, general.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Color</label>
        <input type="hidden" id="m_color" value="#60a5fa">
        <div class="color-wheel-wrap">
          <input type="color" id="m_color_wheel" value="#60a5fa" class="color-wheel-input">
          <span class="color-wheel-label" id="m_color_label">#60a5fa</span>
          <div class="color-presets">
            <button type="button" class="color-preset" data-hex="#60a5fa" style="background:#60a5fa" title="Blue"></button>
            <button type="button" class="color-preset" data-hex="#34d399" style="background:#34d399" title="Green"></button>
            <button type="button" class="color-preset" data-hex="#fbbf24" style="background:#fbbf24" title="Amber"></button>
            <button type="button" class="color-preset" data-hex="#a78bfa" style="background:#a78bfa" title="Purple"></button>
            <button type="button" class="color-preset" data-hex="#f87171" style="background:#f87171" title="Red"></button>
            <button type="button" class="color-preset" data-hex="#fb923c" style="background:#fb923c" title="Orange"></button>
            <button type="button" class="color-preset" data-hex="#e879f9" style="background:#e879f9" title="Pink"></button>
            <button type="button" class="color-preset" data-hex="#22d3ee" style="background:#22d3ee" title="Cyan"></button>
          </div>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Icon</label>
      <input type="hidden" id="m_icon" value="bi-grid">
      <div class="icon-picker-wrap">
        <div class="icon-picker-search">
          <i class="bi bi-search"></i>
          <input type="text" id="m_icon_search" placeholder="Search icons… e.g. chart, person, truck" autocomplete="off">
        </div>
        <div class="icon-picker-selected" id="m_icon_selected">
          <i class="bi bi-grid"></i> <span>bi-grid</span>
        </div>
        <div class="icon-picker-grid" id="m_icon_grid"></div>
      </div>
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

<!-- ══════════════════════════════════════════════════════════════════════════
     EDIT MODULE MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="editModuleModal">
  <div class="modal">
    <div class="modal-title">
      <i class="bi bi-pencil-square" style="color:var(--amber)"></i> Edit Module
      <span id="editModalKeyBadge" style="font-size:.72rem;font-weight:400;color:var(--w40);margin-left:.25rem;font-family:monospace"></span>
    </div>
    <div class="card-preview-wrap">
      <div class="card-preview-label"><i class="bi bi-eye"></i> &nbsp;Live Homepage Card Preview</div>
      <div class="hp-card-preview" id="editPreviewCard">
        <i class="bi bi-grid prev-icon" style="color:#60a5fa" id="editPrevIcon"></i>
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
        <input class="form-control" id="e_cat" value="" placeholder="e.g. hr, fleet, finance, general">
        <div class="form-hint">Used for grouping. Common values: hr, fleet, finance, general.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Color</label>
        <input type="hidden" id="e_color" value="#60a5fa">
        <div class="color-wheel-wrap">
          <input type="color" id="e_color_wheel" value="#60a5fa" class="color-wheel-input">
          <span class="color-wheel-label" id="e_color_label">#60a5fa</span>
          <div class="color-presets">
            <button type="button" class="color-preset" data-hex="#60a5fa" style="background:#60a5fa" title="Blue"></button>
            <button type="button" class="color-preset" data-hex="#34d399" style="background:#34d399" title="Green"></button>
            <button type="button" class="color-preset" data-hex="#fbbf24" style="background:#fbbf24" title="Amber"></button>
            <button type="button" class="color-preset" data-hex="#a78bfa" style="background:#a78bfa" title="Purple"></button>
            <button type="button" class="color-preset" data-hex="#f87171" style="background:#f87171" title="Red"></button>
            <button type="button" class="color-preset" data-hex="#fb923c" style="background:#fb923c" title="Orange"></button>
            <button type="button" class="color-preset" data-hex="#e879f9" style="background:#e879f9" title="Pink"></button>
            <button type="button" class="color-preset" data-hex="#22d3ee" style="background:#22d3ee" title="Cyan"></button>
          </div>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Icon</label>
      <input type="hidden" id="e_icon" value="bi-grid">
      <div class="icon-picker-wrap">
        <div class="icon-picker-search">
          <i class="bi bi-search"></i>
          <input type="text" id="e_icon_search" placeholder="Search icons… e.g. chart, person, truck" autocomplete="off">
        </div>
        <div class="icon-picker-selected" id="e_icon_selected">
          <i class="bi bi-grid"></i> <span>bi-grid</span>
        </div>
        <div class="icon-picker-grid" id="e_icon_grid"></div>
      </div>
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

<!-- ══════════════════════════════════════════════════════════════════════════
     CONFIRM DELETE OVERLAY
══════════════════════════════════════════════════════════════════════════════ -->
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

<!-- ══════════════════════════════════════════════════════════════════════════
     MANAGE ACCESS MODAL (RBAC · Dept · User Type)
══════════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="manageAccessModal">
  <div class="modal" style="max-width:520px">

    <div class="ct-user-info" style="margin-bottom:1rem">
      <div class="ct-avatar" id="ma_avatar">AB</div>
      <div>
        <div class="ct-name" id="ma_displayname">—</div>
        <div class="ct-meta" id="ma_meta">—</div>
      </div>
      <button class="drawer-close" id="closeManageModal" style="margin-left:auto">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div style="display:flex;gap:.35rem;margin-bottom:1rem;border-bottom:1px solid var(--border);padding-bottom:.6rem">
      <button class="ma-tab-btn active" data-matab="rbac">
        <i class="bi bi-key-fill"></i> Module Access
      </button>
      <button class="ma-tab-btn" data-matab="dept">
        <i class="bi bi-building"></i> Departments
      </button>
      <button class="ma-tab-btn" data-matab="type">
        <i class="bi bi-shield-lock"></i> User Type
      </button>
    </div>

    <!-- Pane: RBAC modules -->
    <div id="ma_pane_rbac" class="ma-tab-pane active">
      <div class="form-hint" style="margin-bottom:.75rem">
        Controls which portal modules this user can access, independent of their legacy user type.
      </div>
      <div id="ma_rbac_list" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;max-height:260px;overflow-y:auto;padding:.1rem 0"></div>
      <?php if (!$isViewOnly): ?>
      <div class="modal-footer" style="margin-top:1rem">
        <button class="btn btn-primary btn-sm" id="ma_saveRbac">
          <i class="bi bi-check-lg"></i> Save Module Access
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Pane: Department access -->
    <div id="ma_pane_dept" class="ma-tab-pane">
      <div class="form-hint" style="margin-bottom:.75rem">
        Check the departments this user is allowed to access.
      </div>
      <div id="ma_dept_list" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;max-height:260px;overflow-y:auto;padding:.1rem 0"></div>
      <?php if (!$isViewOnly): ?>
      <div class="modal-footer" style="margin-top:1rem">
        <button class="btn btn-primary btn-sm" id="ma_saveDept">
          <i class="bi bi-check-lg"></i> Save Dept Access
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- Pane: Change user type -->
    <div id="ma_pane_type" class="ma-tab-pane">
      <div class="form-hint" id="ma_ct_current" style="margin-bottom:.75rem"></div>
      <div class="form-group">
        <label class="form-label">New User Type</label>
        <select class="form-control" id="ma_ct_new_type" <?= $isViewOnly ? 'disabled' : '' ?>>
          <?php foreach ($allRoles as $role): ?>
          <option value="<?= htmlspecialchars($role['role_name']) ?>">
            <?= htmlspecialchars($role['role_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!$isViewOnly): ?>
      <div class="modal-footer" style="margin-top:1rem">
        <button class="btn btn-primary btn-sm" id="ma_saveType">
          <i class="bi bi-check-lg"></i> Apply Type Change
        </button>
      </div>
      <?php endif; ?>
    </div>

    <input type="hidden" id="ma_user_id">
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── Constants from PHP ────────────────────────────────────────────────────────
const ACTION_URL    = '<?= base_url('RBAC/rbac_action.php') ?>';
const CSRF_TOKEN    = '<?= htmlspecialchars(rbac_csrf_token(), ENT_QUOTES) ?>';
const ALL_MODULES   = <?= $modulesJson ?>;
let   permsMap      = <?= $permsMapJson ?>;
const ALL_DEPTS     = <?= $allDeptsJson ?>;
let   deptAccessMap = <?= $deptAccessJson ?>;
let   userAccessMap = <?= $userAccessJson ?>;
const IS_VIEW_ONLY  = <?= $isViewOnlyJs ?>;

// ══════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ══════════════════════════════════════════════════════════════════════════════

// Escape a value for safe insertion into innerHTML (text or attribute context).
// Module names/keys, role names, and user display fields are admin/user-entered
// free text — they must never be interpolated into innerHTML without this.
function escHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="bi ${type === 'success' ? 'bi-check-circle-fill success' : 'bi-x-circle-fill error'}"></i> ${msg}`;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => {
    el.style.animation = 'toastOut .25s ease forwards';
    setTimeout(() => el.remove(), 260);
  }, 2800);
}

function confirmDialog(title, msg) {
  return new Promise(resolve => {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMsg').textContent   = msg;
    const overlay  = document.getElementById('confirmOverlay');
    const okBtn    = document.getElementById('confirmOk');
    const cancelBtn = document.getElementById('confirmCancel');
    overlay.classList.add('open');
    function cleanup(result) {
      overlay.classList.remove('open');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      resolve(result);
    }
    const onOk     = () => cleanup(true);
    const onCancel = () => cleanup(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
  });
}

async function apiPost(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  const res = await fetch(ACTION_URL, {
    method: 'POST',
    headers: { 'X-CSRF-Token': CSRF_TOKEN },
    body: fd
  });
  return res.json();
}

// ══════════════════════════════════════════════════════════════════════════════
// TABS
// ══════════════════════════════════════════════════════════════════════════════

// Scoped to [data-tab] only so modal tabs (.ma-tab-btn) are not caught here
document.querySelectorAll('.tab-btn[data-tab]').forEach(btn => {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});

// Activate tab from ?tab= URL param on load
(function () {
  const tab = new URLSearchParams(window.location.search).get('tab');
  if (!tab) return;
  const btn = document.querySelector(`.tab-btn[data-tab="${tab}"]`);
  if (!btn) return;
  document.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('tab-' + tab)?.classList.add('active');
})();

// ══════════════════════════════════════════════════════════════════════════════
// STATS COUNTERS + ROLE CARD HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function recountGrants() {
  const total = Object.values(permsMap).filter(v => v && v !== 'none').length;
  document.getElementById('statGrantCount').textContent = total;
}

function updateRoleCard(roleName) {
  const card = document.querySelector(`.role-card[data-role="${roleName}"]`);
  if (!card) return;
  const modCount = ALL_MODULES.length;
  const granted  = ALL_MODULES.filter(m => {
    const v = permsMap[roleName + '|' + m.module_key];
    return v && v !== 'none';
  }).length;
  const pct = modCount > 0 ? Math.round(granted / modCount * 100) : 0;
  card.querySelector('.role-grant-fill').style.width = pct + '%';
  card.querySelector('.role-grant-label strong').textContent = granted;
  card.querySelectorAll('.role-grant-label')[1].textContent  = pct + '%';
}

function resortRoleCards() {
  const grid  = document.getElementById('rolesGrid');
  const cards = [...grid.querySelectorAll('.role-card')];
  cards.sort((a, b) => {
    const ga = parseInt(a.querySelector('.role-grant-label strong').textContent) || 0;
    const gb = parseInt(b.querySelector('.role-grant-label strong').textContent) || 0;
    return gb - ga || a.dataset.role.localeCompare(b.dataset.role);
  });
  cards.forEach(c => grid.appendChild(c));
}

// ══════════════════════════════════════════════════════════════════════════════
// ROLE SEARCH
// ══════════════════════════════════════════════════════════════════════════════

document.getElementById('roleSearch').addEventListener('input', function () {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#rolesGrid .role-card').forEach(card => {
    card.style.display = (card.dataset.role || '').toLowerCase().includes(q) ? '' : 'none';
  });
});

// ══════════════════════════════════════════════════════════════════════════════
// USERS DRAWER — per user-type list with inline manage
// ══════════════════════════════════════════════════════════════════════════════

const usersDrawer        = document.getElementById('usersDrawer');
let   udrawerCurrentType = '';
let   udrawerAllUsers    = [];

async function openUsersDrawer(typeName, total) {
  udrawerCurrentType = typeName;
  udrawerAllUsers    = [];

  document.getElementById('udrawer_avatar').textContent = typeName.substring(0, 2).toUpperCase();
  document.getElementById('udrawer_title').textContent  = typeName;
  document.getElementById('udrawer_sub').textContent    = 'Loading…';
  document.getElementById('udrawer_search').value       = '';
  document.getElementById('udrawer_list').innerHTML     = '';
  document.getElementById('udrawer_spinner').style.display = '';

  usersDrawer.classList.add('open');
  drawerOverlay.classList.add('open');

  try {
    const res  = await fetch(ACTION_URL + '?action=get_users_by_type&type=' + encodeURIComponent(typeName));
    const data = await res.json();
    if (!data.ok) { toast(data.msg || 'Failed to load users.', 'error'); return; }
    udrawerAllUsers = data.users || [];
    renderUdrawerRows(udrawerAllUsers);
    document.getElementById('udrawer_sub').textContent =
      udrawerAllUsers.length + ' user' + (udrawerAllUsers.length !== 1 ? 's' : '');
  } catch {
    toast('Network error loading users.', 'error');
  } finally {
    document.getElementById('udrawer_spinner').style.display = 'none';
  }
}

function renderUdrawerRows(users) {
  const list = document.getElementById('udrawer_list');
  const esc  = escHtml; // use the shared, complete escaper (was missing < > escaping)

  if (!users.length) {
    list.innerHTML = `<div class="empty-state"><i class="bi bi-people"></i>No users found.</div>`;
    return;
  }

  list.innerHTML = users.map(u => {
    const id       = u.id;
    const dn       = u.DisplayName || u.username;
    const initials = dn.slice(0, 2).toUpperCase();
    const dept     = u.Department   || '—';
    const pos      = u.Position_held || u.Job_tittle || '';
    const access   = userAccessMap[id] || {};
    const keys     = Object.keys(access);

    const pillsHtml = keys.length
      ? keys.map(mk => {
          const mod    = ALL_MODULES.find(m => m.module_key === mk);
          const label  = mod ? esc(mod.module_name) : esc(mk);
          const isView = access[mk] === 'view_only';
          return `<span class="rbac-role-pill" title="${isView ? 'View Only' : 'Full Access'}">
            <i class="bi ${isView ? 'bi-eye' : 'bi-key'}" style="font-size:.55rem"></i>${label}
          </span>`;
        }).join('')
      : `<span class="rbac-no-roles">No individual access set</span>`;

    return `<div class="udr-row" data-id="${id}">
      <div class="udr-avatar">${esc(initials)}</div>
      <div style="flex:1;min-width:0">
        <div class="udr-name">${esc(dn)}</div>
        <div class="udr-meta">${esc(dept)}${pos ? ' · ' + esc(pos) : ''}</div>
        <div class="udr-pills udrawer-pills-${id}">${pillsHtml}</div>
      </div>
      ${IS_VIEW_ONLY
        ? `<button class="btn btn-sm btn-ghost udrawer-manage-btn"
               data-id="${id}" data-displayname="${esc(dn)}"
               data-username="${esc(u.username)}" data-dept="${esc(u.Department || '')}"
               data-type="${esc(u.user_type || '')}">
             <i class="bi bi-eye"></i> View
           </button>`
        : `<button class="btn btn-sm btn-primary udrawer-manage-btn"
               data-id="${id}" data-displayname="${esc(dn)}"
               data-username="${esc(u.username)}" data-dept="${esc(u.Department || '')}"
               data-type="${esc(u.user_type || '')}">
             <i class="bi bi-sliders"></i> Manage
           </button>`
      }
    </div>`;
  }).join('');
}

// Search filter
document.getElementById('udrawer_search').addEventListener('input', function () {
  const q = this.value.toLowerCase();
  if (!q) { renderUdrawerRows(udrawerAllUsers); return; }
  renderUdrawerRows(udrawerAllUsers.filter(u =>
    (u.DisplayName || '').toLowerCase().includes(q) ||
    (u.username    || '').toLowerCase().includes(q) ||
    (u.Department  || '').toLowerCase().includes(q)
  ));
});

// Manage button click inside users drawer
document.getElementById('udrawer_list').addEventListener('click', e => {
  const btn = e.target.closest('.udrawer-manage-btn');
  if (!btn) return;
  openManageModal(btn.dataset);
});

// Close users drawer
document.getElementById('usersDrawerClose').addEventListener('click', () => {
  usersDrawer.classList.remove('open');
  drawerOverlay.classList.remove('open');
});

// After saving module access in manageAccessModal — also refresh the pills in users drawer
// Patch the existing ma_saveRbac listener to update udrawer pills too
// (add these lines inside the existing ma_saveRbac click handler, after the toast call)
// -- see note in section 5 below --

// ══════════════════════════════════════════════════════════════════════════════
// DRAWER — role permission editor
// ══════════════════════════════════════════════════════════════════════════════

let drawerRole      = '';
let drawerCatFilter = 'all';
const drawer        = document.getElementById('roleDrawer');
const drawerOverlay = document.getElementById('drawerOverlay');

function openDrawer(roleName, total) {
  drawerRole      = roleName;
  drawerCatFilter = 'all';
  document.querySelectorAll('[data-dcat]').forEach(p => p.classList.toggle('active', p.dataset.dcat === 'all'));
  document.getElementById('drawerAvatar').textContent   = roleName.substring(0, 2).toUpperCase();
  document.getElementById('drawerRoleName').textContent = roleName;
  renderDrawerBody();
  updateDrawerSub(total);
  drawer.classList.add('open');
  drawerOverlay.classList.add('open');
}

function closeDrawer() {
  drawer.classList.remove('open');
  drawerOverlay.classList.remove('open');
}

function updateDrawerSub(totalOverride) {
  const granted    = ALL_MODULES.filter(m => permsMap[drawerRole + '|' + m.module_key]).length;
  const totalUsers = totalOverride ?? (document.querySelector(`.role-card[data-role="${drawerRole}"]`)?.dataset.total || 0);
  document.getElementById('drawerRoleSub').textContent =
    (totalUsers > 0 ? totalUsers + ' user' + (totalUsers != 1 ? 's' : '') : 'No users yet') +
    ' · ' + granted + ' / ' + ALL_MODULES.length + ' modules';
}

function renderDrawerBody() {
  const cats   = { hr: 'HR', fleet: 'Fleet', finance: 'Finance', general: 'General' };
  const colors = { hr: '#34d399', fleet: '#fbbf24', finance: '#a78bfa', general: '#60a5fa' };
  const grouped = {};

  ALL_MODULES.forEach(m => {
    if (drawerCatFilter !== 'all' && m.category !== drawerCatFilter) return;
    (grouped[m.category] ??= []).push(m);
  });

  let html = '';
  for (const cat in grouped) {
    html += `<div class="drawer-cat-label" style="color:${colors[cat] || '#60a5fa'}">${cats[cat] || cat}</div>`;
    grouped[cat].forEach(m => {
      const key   = drawerRole + '|' + m.module_key;
      const level = permsMap[key] || 'none';
      html += `
        <div class="module-row ${level !== 'none' ? 'granted' : ''}" data-module="${escHtml(m.module_key)}">
          <div class="mod-row-icon ${m.color}"><i class="bi ${m.icon}"></i></div>
          <div class="mod-row-info">
            <div class="mod-row-name">${escHtml(m.module_name)}</div>
            <div class="mod-row-key">${escHtml(m.module_key)}</div>
          </div>
          <select class="perm-select is-${level}" data-role="${escHtml(drawerRole)}" data-module="${escHtml(m.module_key)}" onclick="event.stopPropagation()" ${IS_VIEW_ONLY ? 'disabled style="opacity:.5;cursor:not-allowed"' : ''}>
            <option value="none"      ${level === 'none'      ? 'selected' : ''}>No Access</option>
            <option value="view_only" ${level === 'view_only' ? 'selected' : ''}>View Only</option>
            <option value="full"      ${level === 'full'      ? 'selected' : ''}>Full Access</option>
          </select>
        </div>`;
    });
  }

  document.getElementById('drawerBody').innerHTML =
    html || `<div class="empty-state"><i class="bi bi-grid"></i> No modules in this category.</div>`;
  updateDrawerSub();
}

// Toggle card expanded state — reveals Roles / Users buttons
document.getElementById('rolesGrid').addEventListener('click', e => {
  const card = e.target.closest('.role-card');
  if (!card) return;
  if (e.target.closest('.role-card-del')) return;
  if (e.target.closest('.rc-btn-roles') || e.target.closest('.rc-btn-users')) return;

  // Collapse any other expanded card
  document.querySelectorAll('#rolesGrid .role-card.expanded').forEach(c => {
    if (c !== card) c.classList.remove('expanded');
  });
  card.classList.toggle('expanded');
});

document.getElementById('drawerClose').addEventListener('click', closeDrawer);
drawerOverlay.addEventListener('click', () => {
  closeDrawer();
  usersDrawer.classList.remove('open');
  if (!drawer.classList.contains('open')) {
    drawerOverlay.classList.remove('open');
  }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

// Category filter pills
document.querySelectorAll('[data-dcat]').forEach(pill => {
  pill.addEventListener('click', function () {
    document.querySelectorAll('[data-dcat]').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    drawerCatFilter = this.dataset.dcat;
    renderDrawerBody();
  });
});

// Permission select (3-state)
document.getElementById('drawerBody').addEventListener('change', async e => {
  const sel = e.target;
  if (!sel.classList.contains('perm-select')) return;
  if (IS_VIEW_ONLY) {
    toast('You have view-only access — changes are not allowed.', 'error');
    // Revert to previous value
    const prevLevel = ['none', 'view_only', 'full'].find(l => sel.classList.contains('is-' + l)) || 'none';
    sel.value = prevLevel;
    return;
  }

  const role      = sel.dataset.role;
  const mod       = sel.dataset.module;
  const newLevel  = sel.value;
  const action    = newLevel === 'none' ? 'revoke' : newLevel === 'view_only' ? 'grant_view' : 'grant';
  const prevLevel = ['none', 'view_only', 'full'].find(l => sel.classList.contains('is-' + l)) || 'none';
  const row       = sel.closest('.module-row');

  row.style.opacity = '.5';
  row.style.pointerEvents = 'none';

  try {
    const data = await apiPost({ action, role, module: mod });
    if (data.ok) {
      if (newLevel === 'none') delete permsMap[role + '|' + mod];
      else permsMap[role + '|' + mod] = newLevel;

      sel.classList.remove('is-none', 'is-view_only', 'is-full');
      sel.classList.add('is-' + newLevel);
      row.classList.toggle('granted', newLevel !== 'none');

      updateDrawerSub();
      updateRoleCard(role);
      recountGrants();
      resortRoleCards();

      const label = newLevel === 'none' ? 'Revoked' : newLevel === 'view_only' ? 'View Only set' : 'Full Access granted';
      toast(`${label}: <strong>${escHtml(mod)}</strong> → <strong>${escHtml(role)}</strong>`);
    } else {
      toast(data.msg || 'Error saving.', 'error');
      sel.value = prevLevel;
    }
  } catch {
    toast('Network error.', 'error');
    sel.value = prevLevel;
  }

  row.style.opacity = '';
  row.style.pointerEvents = '';
});

// Grant All / Revoke All
async function drawerBulkAction(action) {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  const btn = document.getElementById(action === 'grant_all' ? 'drawerGrantAll' : 'drawerRevokeAll');
  btn.disabled = true;
  try {
    const data = await apiPost({ action, role: drawerRole });
    if (data.ok) {
      ALL_MODULES.forEach(m => {
        const key = drawerRole + '|' + m.module_key;
        if (action === 'grant_all') permsMap[key] = 'full';
        else delete permsMap[key];
      });
      renderDrawerBody();
      updateRoleCard(drawerRole);
      recountGrants();
      resortRoleCards();
      toast(action === 'grant_all' ? 'All modules granted.' : 'All modules revoked.');
    } else {
      toast(data.msg || 'Error.', 'error');
    }
  } catch { toast('Network error.', 'error'); }
  btn.disabled = false;
}

document.getElementById('drawerGrantAll')?.addEventListener('click',  () => drawerBulkAction('grant_all'));
document.getElementById('drawerRevokeAll')?.addEventListener('click', () => drawerBulkAction('revoke_all'));

// ══════════════════════════════════════════════════════════════════════════════
// ADD / DELETE USER TYPE
// ══════════════════════════════════════════════════════════════════════════════

const addRoleModal = document.getElementById('addRoleModal');

document.getElementById('btnAddRole')?.addEventListener('click', () => {
  document.getElementById('r_name').value = '';
  addRoleModal.classList.add('open');
});
document.getElementById('closeAddRole')?.addEventListener('click', () => addRoleModal.classList.remove('open'));
addRoleModal.addEventListener('click', e => { if (e.target === addRoleModal) addRoleModal.classList.remove('open'); });

document.getElementById('saveRole')?.addEventListener('click', async () => {
  const name = document.getElementById('r_name').value.trim();
  if (!name)          { toast('Role name is required.', 'error'); return; }
  if (/\s/.test(name)) { toast('Role name cannot contain spaces.', 'error'); return; }
  if (document.querySelector(`.role-card[data-role="${name}"]`)) {
    toast('User type already exists.', 'error'); return;
  }

  const data = await apiPost({ action: 'add_role', role_name: name });
  if (!data.ok) { toast(data.msg || 'Error creating role.', 'error'); return; }

  addRoleModal.classList.remove('open');
  toast(`User type <strong>${escHtml(name)}</strong> created.`);

  const modCount  = ALL_MODULES.length;
  const initials  = name.substring(0, 2).toUpperCase();
  const card      = document.createElement('div');
  card.className  = 'role-card';
  card.dataset.role  = name;
  card.dataset.total = '0';
  card.innerHTML = `
    <button class="role-card-del btn btn-sm btn-danger" data-role="${escHtml(name)}" title="Delete user type" onclick="event.stopPropagation()">
      <i class="bi bi-trash3"></i>
    </button>
    <div class="role-card-top">
      <div class="role-avatar">${escHtml(initials)}</div>
      <div class="role-card-info">
        <div class="role-card-name">${escHtml(name)}</div>
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

  const cnt = document.querySelectorAll('#rolesGrid .role-card').length;
  document.getElementById('statRoleCount').textContent = cnt;
  document.getElementById('tabRoleBadge').textContent  = cnt;
});

document.getElementById('rolesGrid').addEventListener('click', async e => {
  const btn = e.target.closest('.role-card-del');
  if (!btn) return;
  const role = btn.dataset.role;

  const confirmed = await confirmDialog(
    `Delete "${role}"?`,
    `This will remove the user type and revoke all its module permissions. Users still assigned this role will not be affected.`
  );
  if (!confirmed) return;

  const data = await apiPost({ action: 'delete_role', role_name: role });
  if (!data.ok) { toast(data.msg || 'Error deleting role.', 'error'); return; }

  btn.closest('.role-card').remove();
  for (const k in permsMap) { if (k.startsWith(role + '|')) delete permsMap[k]; }
  recountGrants();

  const cnt = document.querySelectorAll('#rolesGrid .role-card').length;
  document.getElementById('statRoleCount').textContent = cnt;
  document.getElementById('tabRoleBadge').textContent  = cnt;
  toast(`User type <strong>${escHtml(role)}</strong> deleted.`);
});

// ══════════════════════════════════════════════════════════════════════════════
// MODULE CRUD (Add, Edit, Delete)
// ══════════════════════════════════════════════════════════════════════════════

// ── Icon autocomplete ─────────────────────────────────────────────────────────
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

// ══════════════════════════════════════════════════════════════════════════════
// VISUAL PICKERS — Category, Color, Icon
// ══════════════════════════════════════════════════════════════════════════════

// ── Generic pill picker (category + color) ────────────────────────────────────
function setupPillPicker(pickerId, hiddenId, onChange) {
  const picker = document.getElementById(pickerId);
  if (!picker) return;
  picker.addEventListener('click', e => {
    const btn = e.target.closest('.picker-pill');
    if (!btn) return;
    picker.querySelectorAll('.picker-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(hiddenId).value = btn.dataset.val;
    if (onChange) onChange(btn.dataset.val);
  });
}

function setPickerValue(pickerId, hiddenId, val) {
  document.getElementById(hiddenId).value = val;
  const picker = document.getElementById(pickerId);
  if (!picker) return;
  picker.querySelectorAll('.picker-pill').forEach(b => {
    b.classList.toggle('active', b.dataset.val === val);
  });
}

// ── Icon grid picker ──────────────────────────────────────────────────────────
function setupIconPicker(searchId, gridId, selectedId, hiddenId, previewPrefix) {
  const searchInput = document.getElementById(searchId);
  const grid        = document.getElementById(gridId);
  const selectedEl  = document.getElementById(selectedId);
  const hidden      = document.getElementById(hiddenId);

  function renderGrid(filter) {
    const q       = (filter || '').toLowerCase().replace(/^bi-/, '');
    const matches = q ? BI_ICONS.filter(ic => ic.includes(q)) : BI_ICONS;
    grid.innerHTML = matches.slice(0, 80).map(ic =>
      `<div class="icon-grid-item ${hidden.value === ic ? 'active' : ''}" data-icon="${ic}" title="${ic}">
        <i class="bi ${ic}"></i>
      </div>`
    ).join('');
  }

  function setIcon(ic) {
    hidden.value = ic;
    selectedEl.innerHTML = `<i class="bi ${ic}"></i> <span>${ic}</span>`;
    grid.querySelectorAll('.icon-grid-item').forEach(el =>
      el.classList.toggle('active', el.dataset.icon === ic)
    );
    updatePreview(previewPrefix);
  }

  searchInput.addEventListener('input', () => renderGrid(searchInput.value));
  grid.addEventListener('click', e => {
    const item = e.target.closest('.icon-grid-item');
    if (!item) return;
    setIcon(item.dataset.icon);
  });

  // expose set function on the element for external use
  grid._setIcon = setIcon;
  grid._render  = renderGrid;

  renderGrid('');
}

setupIconPicker('m_icon_search', 'm_icon_grid', 'm_icon_selected', 'm_icon', 'add');
setupIconPicker('e_icon_search', 'e_icon_grid', 'e_icon_selected', 'e_icon', 'edit');


// ── Color wheel setup ─────────────────────────────────────────────────────────
function setupColorWheel(wheelId, hiddenId, labelId, presetsParent, previewPrefix) {
  const wheel  = document.getElementById(wheelId);
  const hidden = document.getElementById(hiddenId);
  const label  = document.getElementById(labelId);

  function applyColor(hex) {
    hidden.value  = hex;
    wheel.value   = hex;
    label.textContent = hex;
    // Update active preset
    document.querySelectorAll(`#${presetsParent} .color-preset`).forEach(p => {
      p.classList.toggle('active', p.dataset.hex.toLowerCase() === hex.toLowerCase());
    });
    updatePreview(previewPrefix);
  }

  wheel.addEventListener('input', () => applyColor(wheel.value));

  document.getElementById(presetsParent).addEventListener('click', e => {
    const btn = e.target.closest('.color-preset');
    if (!btn) return;
    applyColor(btn.dataset.hex);
  });

  // expose setter
  wheel._apply = applyColor;
}

// We wrap the preset containers in a parent div — use the color-wheel-wrap as delegate target
// Instead, pass the modal id as scope
function setupColorWheelForModal(modalId, wheelId, hiddenId, labelId, previewPrefix) {
  const wheel  = document.getElementById(wheelId);
  const hidden = document.getElementById(hiddenId);
  const label  = document.getElementById(labelId);
  const modal  = document.getElementById(modalId);

  function applyColor(hex) {
    hidden.value      = hex;
    wheel.value       = hex;
    label.textContent = hex;
    modal.querySelectorAll('.color-preset').forEach(p => {
      p.classList.toggle('active', p.dataset.hex.toLowerCase() === hex.toLowerCase());
    });
    updatePreview(previewPrefix);
  }

  wheel.addEventListener('input', () => applyColor(wheel.value));
  modal.addEventListener('click', e => {
    const btn = e.target.closest('.color-preset');
    if (!btn) return;
    applyColor(btn.dataset.hex);
  });

  wheel._apply = applyColor;
}

setupColorWheelForModal('addModuleModal',  'm_color_wheel', 'm_color', 'm_color_label', 'add');
setupColorWheelForModal('editModuleModal', 'e_color_wheel', 'e_color', 'e_color_label', 'edit');

function updatePreview(prefix) {
  const icon  = document.getElementById(prefix === 'add' ? 'm_icon'  : 'e_icon')?.value  || 'bi-grid';
  const name  = document.getElementById(prefix === 'add' ? 'm_name'  : 'e_name')?.value.trim()  || 'Module Name';
  const color = document.getElementById(prefix === 'add' ? 'm_color' : 'e_color')?.value || '#60a5fa';
  const desc  = document.getElementById(prefix === 'add' ? 'm_desc'  : 'e_desc')?.value.trim()  || '';
  const iEl   = document.getElementById(prefix + 'PrevIcon');
  const nEl   = document.getElementById(prefix + 'PrevName');
  const dEl   = document.getElementById(prefix + 'PrevDesc');
  if (!iEl) return;
  // Use inline style for hex, remove old color class
  iEl.className = `bi ${icon} prev-icon`;
  iEl.style.color = color;
  nEl.textContent = name || 'Module Name';
  dEl.textContent = desc || '';
}

// Wire name/desc text inputs to preview
['m_name','m_desc'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', () => updatePreview('add'));
});
['e_name','e_desc'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', () => updatePreview('edit'));
});

// ── Add Module ────────────────────────────────────────────────────────────────
const addModal = document.getElementById('addModuleModal');

document.getElementById('btnAddModule')?.addEventListener('click', () => {
  ['m_key','m_name','m_desc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('m_icon_search').value = '';
  setPickerValue('m_cat_picker', 'm_cat', 'general');
  document.getElementById('m_color_wheel')._apply('#60a5fa');
  // reset icon to default
  document.getElementById('m_icon').value = 'bi-grid';
  document.getElementById('m_icon_selected').innerHTML = '<i class="bi bi-grid"></i> <span>bi-grid</span>';
  document.getElementById('m_icon_grid')._render('');
  updatePreview('add');
  // Switch to registry tab first (scoped to main tabs only, not modal tabs)
  document.querySelectorAll('.tab-btn[data-tab]').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('[data-tab="registry"]').classList.add('active');
  document.getElementById('tab-registry').classList.add('active');
  addModal.classList.add('open');
});
document.getElementById('closeAddModal')?.addEventListener('click', () => addModal.classList.remove('open'));
addModal.addEventListener('click', e => { if (e.target === addModal) addModal.classList.remove('open'); });

document.getElementById('saveModule')?.addEventListener('click', async () => {
  const key   = document.getElementById('m_key').value.trim();
  const name  = document.getElementById('m_name').value.trim();
  const cat   = document.getElementById('m_cat').value;
  const color = document.getElementById('m_color').value;
  const icon  = document.getElementById('m_icon').value.trim() || 'bi-grid';
  const desc  = document.getElementById('m_desc').value.trim();

  if (!key || !name)   { toast('Key and Name are required.', 'error'); return; }
  if (/\s/.test(key))  { toast('Module key cannot contain spaces.', 'error'); return; }

  const data = await apiPost({ action: 'add_module', module_key: key, module_name: name, category: cat, color, icon, description: desc });
  if (!data.ok) { toast(data.msg || 'Error adding module.', 'error'); return; }

  addModal.classList.remove('open');
  toast(`Module <strong>${escHtml(name)}</strong> added successfully.`);

  ALL_MODULES.push({ module_key: key, module_name: name, category: cat, icon, color, description: desc });

  const catLabels = { hr: 'HR', fleet: 'Fleet', finance: 'Finance', general: 'General' };
  const chip = document.createElement('div');
  chip.className = 'module-chip';
  Object.assign(chip.dataset, { key, name, cat, icon, color, desc });
  chip.innerHTML = `
    <div class="chip-top">
      <div class="module-chip-icon" style="color:${color};background:${color}1a;border:1px solid ${color}40"><i class="bi ${icon}"></i></div>
      <div class="chip-info">
        <div class="module-chip-name">${escHtml(name)}</div>
        <div class="module-chip-key">${escHtml(key)}</div>
      </div>
    </div>
    ${desc ? `<div class="chip-desc">${escHtml(desc)}</div>` : ''}
    <div class="chip-footer">
      <span class="chip-cat-badge ${cat}">${catLabels[cat] || cat}</span>
      <div class="chip-actions">
        <button class="module-chip-edit" title="Edit module" data-key="${escHtml(key)}"><i class="bi bi-pencil"></i></button>
        <button class="module-chip-del"  title="Delete module" data-key="${escHtml(key)}" data-name="${escHtml(name)}"><i class="bi bi-trash3"></i></button>
      </div>
    </div>
    <div class="preview-strip"><i class="bi bi-eye"></i> Homepage card preview ↓</div>
    <div class="hp-card-preview" style="width:100%">
      <i class="bi ${icon} prev-icon" style="color:${color}"></i>
      <div class="prev-name">${escHtml(name)}</div>
      <div class="prev-desc">${escHtml(desc)}</div>
    </div>`;
  document.getElementById('modulesGrid').appendChild(chip);

  updateModuleCountUI();
  if (drawer.classList.contains('open')) renderDrawerBody();
});

// ── Edit Module ───────────────────────────────────────────────────────────────
const editModal = document.getElementById('editModuleModal');

document.getElementById('modulesGrid').addEventListener('click', e => {
  const editBtn = e.target.closest('.module-chip-edit');
  if (!editBtn) return;
  const chip = editBtn.closest('.module-chip');
  document.getElementById('e_key').value   = chip.dataset.key;
  document.getElementById('e_name').value  = chip.dataset.name  || '';
  document.getElementById('e_desc').value  = chip.dataset.desc  || '';
  document.getElementById('e_icon_search').value = '';
  document.getElementById('e_cat').value = chip.dataset.cat || 'general';
  // Handle legacy class names → hex
  const legacyColorMap = {blue:'#60a5fa',green:'#34d399',amber:'#fbbf24',purple:'#a78bfa'};
  const chipColor = chip.dataset.color || '#60a5fa';
  const hexColor  = legacyColorMap[chipColor] || chipColor;
  document.getElementById('e_color_wheel')._apply(hexColor);
  // set icon
  const eIcon = chip.dataset.icon || 'bi-grid';
  document.getElementById('e_icon').value = eIcon;
  document.getElementById('e_icon_selected').innerHTML = `<i class="bi ${eIcon}"></i> <span>${eIcon}</span>`;
  document.getElementById('e_icon_grid')._render('');
  document.getElementById('editModalKeyBadge').textContent = chip.dataset.key;
  updatePreview('edit');
  editModal.classList.add('open');
});
document.getElementById('closeEditModal')?.addEventListener('click', () => editModal.classList.remove('open'));
editModal.addEventListener('click', e => { if (e.target === editModal) editModal.classList.remove('open'); });

document.getElementById('updateModule')?.addEventListener('click', async () => {
  const key   = document.getElementById('e_key').value.trim();
  const name  = document.getElementById('e_name').value.trim();
  const cat   = document.getElementById('e_cat').value;
  const color = document.getElementById('e_color').value;
  const icon  = document.getElementById('e_icon').value.trim() || 'bi-grid';
  const desc  = document.getElementById('e_desc').value.trim();
  if (!name) { toast('Display name is required.', 'error'); return; }

  const data = await apiPost({ action: 'edit_module', module_key: key, module_name: name, category: cat, color, icon, description: desc });
  if (!data.ok) { toast(data.msg || 'Error updating module.', 'error'); return; }

  editModal.classList.remove('open');
  toast(`Module <strong>${escHtml(name)}</strong> updated.`);

  const idx = ALL_MODULES.findIndex(m => m.module_key === key);
  if (idx >= 0) Object.assign(ALL_MODULES[idx], { module_name: name, category: cat, icon, color, description: desc });

  const catLabels = { hr: 'HR', fleet: 'Fleet', finance: 'Finance', general: 'General' };
  const chip = document.querySelector(`.module-chip[data-key="${key}"]`);
  if (chip) {
    Object.assign(chip.dataset, { name, cat, icon, color, desc });
    chip.querySelector('.module-chip-icon').style.cssText = `color:${color};background:${color}1a;border:1px solid ${color}40`;
    chip.querySelector('.module-chip-icon').className     = 'module-chip-icon';
    chip.querySelector('.module-chip-icon i').className   = `bi ${icon}`;
    chip.querySelector('.module-chip-name').textContent      = name;
    const descEl = chip.querySelector('.chip-desc');
    if (descEl) descEl.textContent = desc;
    chip.querySelector('.chip-cat-badge').className          = `chip-cat-badge ${cat}`;
    chip.querySelector('.chip-cat-badge').textContent        = catLabels[cat] || cat;
    const pi = chip.querySelector('.hp-card-preview .prev-icon');
    const pn = chip.querySelector('.hp-card-preview .prev-name');
    const pd = chip.querySelector('.hp-card-preview .prev-desc');
    if (pi) { pi.className = `bi ${icon} prev-icon`; pi.style.color = color; }
    if (pn) pn.textContent = name;
    if (pd) pd.textContent = desc;
  }
  if (drawer.classList.contains('open')) renderDrawerBody();
});

// ── Delete Module ─────────────────────────────────────────────────────────────
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

  const data = await apiPost({ action: 'delete_module', module_key: key });
  if (!data.ok) { toast(data.msg || 'Error deleting.', 'error'); return; }

  btn.closest('.module-chip').remove();
  const idx = ALL_MODULES.findIndex(m => m.module_key === key);
  if (idx >= 0) ALL_MODULES.splice(idx, 1);
  for (const k in permsMap) { if (k.endsWith('|' + key)) delete permsMap[k]; }
  recountGrants();
  updateModuleCountUI();
  document.querySelectorAll('.role-card[data-role]').forEach(card => updateRoleCard(card.dataset.role));
  if (drawer.classList.contains('open')) renderDrawerBody();
  toast(`Module <strong>${escHtml(name)}</strong> deleted.`);
});

function updateModuleCountUI() {
  const cnt = ALL_MODULES.length;
  document.getElementById('statModuleCount').textContent     = cnt;
  document.getElementById('tabModBadge').textContent         = cnt;
  document.getElementById('moduleRegistryCount').textContent = `— ${cnt} portal card${cnt !== 1 ? 's' : ''}`;
}

// ══════════════════════════════════════════════════════════════════════════════
// AUDIT LOG
// ══════════════════════════════════════════════════════════════════════════════

// Pagination is handled by plain <a href> links — no JS needed.

function applyAuditFilters() {
  const params = new URLSearchParams({ tab: 'audit' });
  const search = document.getElementById('auditSearch').value;
  const action = document.getElementById('auditActionFilter').value;
  const from   = document.getElementById('auditFrom').value;
  const to     = document.getElementById('auditTo').value;
  if (search) params.set('asearch', search);
  if (action) params.set('aaction', action);
  if (from)   params.set('afrom', from);
  if (to)     params.set('ato', to);
  window.location.href = '?' + params.toString();
}

document.getElementById('auditSearch').addEventListener('keydown', e => {
  if (e.key === 'Enter') applyAuditFilters();
});

// ══════════════════════════════════════════════════════════════════════════════
// MANAGE ACCESS MODAL
// ══════════════════════════════════════════════════════════════════════════════

function openManageModal(d) {
  const id          = d.id;
  const displayname = d.displayname || d.username;
  const initials    = displayname.slice(0, 2).toUpperCase();
  const dept        = d.dept || '—';
  const type        = d.type || '—';
  const current     = userAccessMap[id] || {};

  document.getElementById('ma_user_id').value           = id;
  document.getElementById('ma_avatar').textContent      = initials;
  document.getElementById('ma_displayname').textContent = displayname;
  document.getElementById('ma_meta').textContent        = type + ' · ' + dept;

  // RBAC modules pane
  document.getElementById('ma_rbac_list').innerHTML = ALL_MODULES.map(mod => {
    const level = current[mod.module_key] || 'none';
    return `<div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;
                        padding:.4rem .6rem;border-radius:8px;border:1px solid var(--border);
                        background:var(--surface);font-size:.8rem;">
      <span style="color:var(--w60)">${escHtml(mod.module_name)}</span>
      <select class="perm-select is-${level}" data-module="${escHtml(mod.module_key)}" ${IS_VIEW_ONLY ? 'disabled style="opacity:.5;cursor:not-allowed"' : ''}>
        <option value="none"      ${level === 'none'      ? 'selected' : ''}>No Access</option>
        <option value="view_only" ${level === 'view_only' ? 'selected' : ''}>View Only</option>
        <option value="full"      ${level === 'full'      ? 'selected' : ''}>Full Access</option>
      </select>
    </div>`;
  }).join('');

  // Department pane
  const currentDepts = deptAccessMap[id] || [];
  document.getElementById('ma_dept_list').innerHTML = ALL_DEPTS.map(dep => {
    const checked = currentDepts.includes(dep) ? 'checked' : '';
    return `<label style="display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;
             border-radius:8px;${IS_VIEW_ONLY ? 'cursor:not-allowed;opacity:.6;' : 'cursor:pointer;'}border:1px solid var(--border);
             background:var(--surface);font-size:.8rem">
      <input type="checkbox" value="${dep.replace(/"/g, '&quot;')}" ${checked}
             ${IS_VIEW_ONLY ? 'disabled' : ''}
             style="accent-color:#a78bfa;width:15px;height:15px;${IS_VIEW_ONLY ? 'cursor:not-allowed' : 'cursor:pointer'}">
      <span style="color:var(--w60)">${dep}</span>
    </label>`;
  }).join('');

  // Change type pane
  document.getElementById('ma_ct_current').innerHTML =
    `Current type: <strong style="color:var(--accent2)">${escHtml(type)}</strong>`;
  const sel = document.getElementById('ma_ct_new_type');
  for (const opt of sel.options) opt.selected = (opt.value === type);

  switchManageTab('rbac');
  document.getElementById('manageAccessModal').classList.add('open');
}

function switchManageTab(tab) {
  document.querySelectorAll('.ma-tab-btn').forEach(b  => b.classList.toggle('active', b.dataset.matab === tab));
  document.querySelectorAll('.ma-tab-pane').forEach(p => p.classList.toggle('active', p.id === 'ma_pane_' + tab));
}

document.querySelectorAll('.ma-tab-btn').forEach(btn =>
  btn.addEventListener('click', e => {
    e.stopPropagation(); // prevent the click from being caught by any parent listener
    switchManageTab(btn.dataset.matab);
  })
);

const manageModal = document.getElementById('manageAccessModal');
document.getElementById('closeManageModal').addEventListener('click', e => {
  e.stopPropagation();
  manageModal.classList.remove('open');
});
manageModal.addEventListener('click', e => { if (e.target === manageModal) manageModal.classList.remove('open'); });

// Save RBAC module access (button only exists in DOM for full-access users)
document.getElementById('ma_saveRbac')?.addEventListener('click', async () => {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  const userId   = document.getElementById('ma_user_id').value;
  const selected = {};
  document.querySelectorAll('#ma_rbac_list .perm-select').forEach(sel => {
    if (sel.value !== 'none') selected[sel.dataset.module] = sel.value;
  });

  const data = await apiPost({ action: 'assign_user_access', user_id: userId, modules: JSON.stringify(selected) });
  if (!data.ok) { toast(data.msg || 'Error saving.', 'error'); return; }

  userAccessMap[userId] = selected;

  const cell = document.querySelector(`.rbac-roles-cell[data-uid="${userId}"]`);
  if (cell) {
    const keys = Object.keys(selected);
    cell.innerHTML = keys.length
      ? keys.map(mk => {
          const mod   = ALL_MODULES.find(m => m.module_key === mk);
          const label = mod ? escHtml(mod.module_name) : escHtml(mk);
          const isV   = selected[mk] === 'view_only';
          return `<span class="rbac-role-pill" title="${isV ? 'View Only' : 'Full Access'}">
            <i class="bi ${isV ? 'bi-eye' : 'bi-key'}" style="font-size:.55rem"></i>${label}
          </span>`;
        }).join('')
      : '<span class="rbac-no-roles">None</span>';
  }
  toast(`Module access updated — <strong>${Object.keys(selected).length}</strong> modules assigned.`);
  // Refresh pills in the users drawer if it's open
const pillsEl = document.querySelector(`.udrawer-pills-${userId}`);
if (pillsEl) {
  const keys = Object.keys(selected);
  pillsEl.innerHTML = keys.length
    ? keys.map(mk => {
        const mod   = ALL_MODULES.find(m => m.module_key === mk);
        const label = mod ? escHtml(mod.module_name) : escHtml(mk);
        const isV   = selected[mk] === 'view_only';
        return `<span class="rbac-role-pill" title="${isV ? 'View Only' : 'Full Access'}">
          <i class="bi ${isV ? 'bi-eye' : 'bi-key'}" style="font-size:.55rem"></i>${label}
        </span>`;
      }).join('')
    : `<span class="rbac-no-roles">No individual access set</span>`;
}
});

// Save department access (button only exists in DOM for full-access users)
document.getElementById('ma_saveDept')?.addEventListener('click', async () => {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  const userId  = document.getElementById('ma_user_id').value;
  const checked = [...document.querySelectorAll('#ma_dept_list input[type=checkbox]:checked')].map(cb => cb.value);

  const data = await apiPost({ action: 'manage_dept_access', user_id: userId, departments: JSON.stringify(checked) });
  if (!data.ok) { toast(data.msg || 'Error saving.', 'error'); return; }

  deptAccessMap[userId] = checked;
  toast(`Department access updated — <strong>${data.count}</strong> dept${data.count !== 1 ? 's' : ''} assigned.`);
});

// Save user type change (button only exists in DOM for full-access users)
document.getElementById('ma_saveType')?.addEventListener('click', async () => {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  const userId  = document.getElementById('ma_user_id').value;
  const newType = document.getElementById('ma_ct_new_type').value;
  if (!newType) { toast('Select a user type.', 'error'); return; }

  const data = await apiPost({ action: 'change_user_type', user_id: userId, user_type: newType });
  if (!data.ok) { toast(data.msg || 'Error updating type.', 'error'); return; }

  const row = document.querySelector(`#userPanelBody tr[data-id="${userId}"]`);
  if (row) {
    row.style.opacity    = '.4';
    row.style.transition = 'opacity .3s';
    setTimeout(() => row.remove(), 350);
  }
  manageModal.classList.remove('open');
  toast(`User type changed to <strong>${escHtml(newType)}</strong>. Refresh to update counts.`);
});

// ══════════════════════════════════════════════════════════════════════════════
// MODULE DRAG-AND-DROP REORDER  (localStorage — no DB needed)
// ══════════════════════════════════════════════════════════════════════════════
(function () {
  const grid    = document.getElementById('modulesGrid');
  const saveBtn = document.getElementById('saveOrderBtn');
  const LS_KEY  = 'rbac_module_order';
  let dragSrc   = null;
  let dirty     = false;

  function applyStoredOrder() {
    const stored = localStorage.getItem(LS_KEY);
    if (!stored) return;
    let keys;
    try { keys = JSON.parse(stored); } catch { return; }
    keys.forEach(key => {
      const chip = grid.querySelector(`.module-chip[data-key="${CSS.escape(key)}"]`);
      if (chip) grid.appendChild(chip);
    });
    refreshBadges();
  }

  function refreshBadges() {
    grid.querySelectorAll('.module-chip').forEach((chip, i) => {
      chip.querySelector('.order-badge').textContent = i + 1;
    });
  }

  function markDirty() {
    dirty = true;
    saveBtn.classList.add('visible');
  }

  function currentKeyOrder() {
    return [...grid.querySelectorAll('.module-chip')].map(c => c.dataset.key);
  }

  applyStoredOrder();

  grid.addEventListener('dragstart', e => {
    const chip = e.target.closest('.module-chip');
    if (!chip) return;
    dragSrc = chip;
    chip.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', chip.dataset.key);
  });

  grid.addEventListener('dragend', e => {
    e.target.closest('.module-chip')?.classList.remove('dragging');
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
    e.target.closest('.module-chip')?.classList.remove('drag-over');
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

  saveBtn.addEventListener('click', async () => {
    const keys = currentKeyOrder();
    const data = await apiPost({ action: 'reorder_modules', order: JSON.stringify(keys) });
    if (!data.ok) { toast(data.msg || 'Failed to save order.', 'error'); return; }
    // Mirror to localStorage so the order survives a hard refresh before next DB load
    localStorage.setItem(LS_KEY, JSON.stringify(keys));
    dirty = false;
    saveBtn.classList.remove('visible');
    toast('Module order saved. Homepage will reflect the new order.');
  });

  window.addEventListener('beforeunload', e => {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
</body>
</html>