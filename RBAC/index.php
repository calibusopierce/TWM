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
    .registry-cat-pills { display: flex; gap: .4rem; flex-wrap: wrap; }
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
    .filter-row { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1.25rem; animation: fadeUp .4s .15s ease both; position: relative; z-index: 20; }
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

    /* ══ GLOBAL USER SEARCH ══════════════════════════════════════════════════ */
    .gusearch-wrap { flex: 1.4; min-width: 260px; max-width: 420px; position: relative; z-index: 21; }
    .gusearch-wrap .icon-suggestions { max-height: 320px; z-index: 21; box-shadow: 0 12px 28px rgba(0,0,0,.45); }
    .gu-result-item {
      display: flex; align-items: center; gap: .65rem;
      padding: .5rem .8rem; cursor: pointer; transition: background .1s;
      background: #0f1c3a;
    }
    .gu-result-item:hover { background: rgba(255,255,255,.06); }
    .gu-result-avatar {
      width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
      background: rgba(67,128,226,.18); color: var(--accent2);
      display: flex; align-items: center; justify-content: center;
      font-size: .68rem; font-weight: 700; font-family: 'Sora', sans-serif;
    }
    .gu-result-info   { flex: 1; min-width: 0; }
    .gu-result-name   { font-size: .8rem; font-weight: 600; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gu-result-meta   { font-size: .68rem; color: var(--w40); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gu-result-type   { font-size: .62rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--accent2); background: rgba(67,128,226,.12); padding: .12rem .45rem; border-radius: 5px; flex-shrink: 0; }
    .gu-empty, .gu-loading { padding: .8rem; font-size: .76rem; color: var(--w40); text-align: center; }

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
      <div class="search-box gusearch-wrap" style="position:relative">
        <i class="bi bi-person-lines-fill"></i>
        <input type="text" id="globalUserSearch" placeholder="Search a user by name or username…" autocomplete="off">
        <div class="icon-suggestions" id="gusearch_results"></div>
      </div>
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

    <div class="filter-row">
      <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="registrySearch" placeholder="Search module name or key…">
      </div>
      <div class="registry-cat-pills" id="registryCatPills">
        <span class="pill active" data-rcat="all">All</span>
        <?php foreach ($categoryMeta as $catKey => $meta): ?>
        <span class="pill" data-rcat="<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($meta['label']) ?></span>
        <?php endforeach; ?>
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
      <?php if (!$isViewOnly): ?>
      <div style="display:flex;gap:.5rem;margin-bottom:.6rem">
        <button class="btn btn-sm btn-success" id="ma_grantAll" type="button"><i class="bi bi-check-all"></i> Grant All</button>
        <button class="btn btn-sm btn-danger"  id="ma_revokeAll" type="button"><i class="bi bi-x-lg"></i> Remove All</button>
      </div>
      <?php endif; ?>
            <div style="position:relative;margin-bottom:.6rem">
        <i class="bi bi-search" style="position:absolute;left:.6rem;top:50%;transform:translateY(-50%);color:var(--w60);font-size:.8rem"></i>
        <input type="text" id="ma_rbac_search" placeholder="Search modules..."
               style="width:100%;padding:.4rem .6rem .4rem 1.9rem;border-radius:8px;border:1px solid var(--border);
                      background:var(--surface);color:var(--w60);font-size:.8rem">
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
// GLOBAL USER SEARCH — find a user directly, no need to guess their user type
// ══════════════════════════════════════════════════════════════════════════════

const gu_input   = document.getElementById('globalUserSearch');
const gu_results = document.getElementById('gusearch_results');
let   gu_timer   = null;
let   gu_seq     = 0; // guards against out-of-order responses when typing fast

function gu_close() { gu_results.classList.remove('open'); gu_results.innerHTML = ''; }

function gu_render(users) {
  if (!users.length) {
    gu_results.innerHTML = `<div class="gu-empty">No matching users.</div>`;
    gu_results.classList.add('open');
    return;
  }
  gu_results.innerHTML = users.map(u => {
    const dn       = u.DisplayName || u.username;
    const initials = dn.slice(0, 2).toUpperCase();
    const dept     = u.Department || '—';
    return `<div class="gu-result-item"
                 data-id="${u.id}" data-displayname="${escHtml(dn)}"
                 data-username="${escHtml(u.username)}" data-dept="${escHtml(u.Department || '')}"
                 data-type="${escHtml(u.user_type || '')}">
      <div class="gu-result-avatar">${escHtml(initials)}</div>
      <div class="gu-result-info">
        <div class="gu-result-name">${escHtml(dn)}</div>
        <div class="gu-result-meta">${escHtml(u.username)}${dept !== '—' ? ' · ' + escHtml(dept) : ''}</div>
      </div>
      <span class="gu-result-type">${escHtml(u.user_type || '—')}</span>
    </div>`;
  }).join('');
  gu_results.classList.add('open');
}

gu_input.addEventListener('input', function () {
  const q = this.value.trim();
  clearTimeout(gu_timer);

  if (q.length < 2) { gu_close(); return; }

  gu_results.innerHTML = `<div class="gu-loading">Searching…</div>`;
  gu_results.classList.add('open');

  gu_timer = setTimeout(async () => {
    const mySeq = ++gu_seq;
    try {
      const res  = await fetch(ACTION_URL + '?action=search_users&q=' + encodeURIComponent(q));
      const data = await res.json();
      if (mySeq !== gu_seq) return; // a newer keystroke already fired another request
      if (!data.ok) { gu_results.innerHTML = `<div class="gu-empty">Search failed.</div>`; return; }
      gu_render(data.users || []);
    } catch {
      if (mySeq !== gu_seq) return;
      gu_results.innerHTML = `<div class="gu-empty">Network error.</div>`;
    }
  }, 250);
});

gu_results.addEventListener('click', e => {
  const item = e.target.closest('.gu-result-item');
  if (!item) return;
  openManageModal(item.dataset);
  gu_close();
  gu_input.value = '';
});

document.addEventListener('click', e => {
  if (!e.target.closest('.gusearch-wrap')) gu_close();
});

// ══════════════════════════════════════════════════════════════════════════════
// MODULE REGISTRY — search + category filter
// ══════════════════════════════════════════════════════════════════════════════

let registryCatFilter = 'all';

function applyRegistryFilter() {
  const q = (document.getElementById('registrySearch')?.value || '').trim().toLowerCase();
  const chips = document.querySelectorAll('#modulesGrid .module-chip');
  let visible = 0;

  chips.forEach(chip => {
    const matchesCat = registryCatFilter === 'all' || chip.dataset.cat === registryCatFilter;
    const matchesSearch = !q
      || (chip.dataset.name || '').toLowerCase().includes(q)
      || (chip.dataset.key  || '').toLowerCase().includes(q);
    const show = matchesCat && matchesSearch;
    chip.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  const total = chips.length;
  const countEl = document.getElementById('moduleRegistryCount');
  if (countEl) {
    countEl.textContent = (visible === total)
      ? `— ${total} portal card${total !== 1 ? 's' : ''}`
      : `— ${visible} of ${total} portal card${total !== 1 ? 's' : ''}`;
  }
}

document.getElementById('registrySearch')?.addEventListener('input', applyRegistryFilter);

document.querySelectorAll('[data-rcat]').forEach(pill => {
  pill.addEventListener('click', function () {
    document.querySelectorAll('[data-rcat]').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    registryCatFilter = this.dataset.rcat;
    applyRegistryFilter();
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
  'bi-0-circle','bi-0-circle-fill','bi-0-square','bi-0-square-fill','bi-1-circle',
  'bi-1-circle-fill','bi-1-square','bi-1-square-fill','bi-123','bi-2-circle',
  'bi-2-circle-fill','bi-2-square','bi-2-square-fill','bi-3-circle','bi-3-circle-fill',
  'bi-3-square','bi-3-square-fill','bi-4-circle','bi-4-circle-fill','bi-4-square',
  'bi-4-square-fill','bi-5-circle','bi-5-circle-fill','bi-5-square','bi-5-square-fill',
  'bi-6-circle','bi-6-circle-fill','bi-6-square','bi-6-square-fill','bi-7-circle',
  'bi-7-circle-fill','bi-7-square','bi-7-square-fill','bi-8-circle','bi-8-circle-fill',
  'bi-8-square','bi-8-square-fill','bi-9-circle','bi-9-circle-fill','bi-9-square',
  'bi-9-square-fill','bi-activity','bi-airplane','bi-airplane-engines',
  'bi-airplane-engines-fill','bi-airplane-fill','bi-alarm','bi-alarm-fill','bi-alexa',
  'bi-align-bottom','bi-align-center','bi-align-end','bi-align-middle','bi-align-start',
  'bi-align-top','bi-alipay','bi-alphabet','bi-alphabet-uppercase','bi-alt','bi-amazon',
  'bi-amd','bi-android','bi-android2','bi-anthropic','bi-app','bi-app-indicator',
  'bi-apple','bi-apple-music','bi-archive','bi-archive-fill','bi-arrow-90deg-down',
  'bi-arrow-90deg-left','bi-arrow-90deg-right','bi-arrow-90deg-up','bi-arrow-bar-down',
  'bi-arrow-bar-left','bi-arrow-bar-right','bi-arrow-bar-up','bi-arrow-clockwise',
  'bi-arrow-counterclockwise','bi-arrow-down','bi-arrow-down-circle',
  'bi-arrow-down-circle-fill','bi-arrow-down-left','bi-arrow-down-left-circle',
  'bi-arrow-down-left-circle-fill','bi-arrow-down-left-square',
  'bi-arrow-down-left-square-fill','bi-arrow-down-right','bi-arrow-down-right-circle',
  'bi-arrow-down-right-circle-fill','bi-arrow-down-right-square',
  'bi-arrow-down-right-square-fill','bi-arrow-down-short','bi-arrow-down-square',
  'bi-arrow-down-square-fill','bi-arrow-down-up','bi-arrow-left','bi-arrow-left-circle',
  'bi-arrow-left-circle-fill','bi-arrow-left-right','bi-arrow-left-short',
  'bi-arrow-left-square','bi-arrow-left-square-fill','bi-arrow-repeat',
  'bi-arrow-return-left','bi-arrow-return-right','bi-arrow-right','bi-arrow-right-circle',
  'bi-arrow-right-circle-fill','bi-arrow-right-short','bi-arrow-right-square',
  'bi-arrow-right-square-fill','bi-arrow-through-heart','bi-arrow-through-heart-fill',
  'bi-arrow-up','bi-arrow-up-circle','bi-arrow-up-circle-fill','bi-arrow-up-left',
  'bi-arrow-up-left-circle','bi-arrow-up-left-circle-fill','bi-arrow-up-left-square',
  'bi-arrow-up-left-square-fill','bi-arrow-up-right','bi-arrow-up-right-circle',
  'bi-arrow-up-right-circle-fill','bi-arrow-up-right-square',
  'bi-arrow-up-right-square-fill','bi-arrow-up-short','bi-arrow-up-square',
  'bi-arrow-up-square-fill','bi-arrows','bi-arrows-angle-contract',
  'bi-arrows-angle-expand','bi-arrows-collapse','bi-arrows-collapse-vertical',
  'bi-arrows-expand','bi-arrows-expand-vertical','bi-arrows-fullscreen','bi-arrows-move',
  'bi-arrows-vertical','bi-aspect-ratio','bi-aspect-ratio-fill','bi-asterisk','bi-at',
  'bi-award','bi-award-fill','bi-back','bi-backpack','bi-backpack-fill','bi-backpack2',
  'bi-backpack2-fill','bi-backpack3','bi-backpack3-fill','bi-backpack4',
  'bi-backpack4-fill','bi-backspace','bi-backspace-fill','bi-backspace-reverse',
  'bi-backspace-reverse-fill','bi-badge-3d','bi-badge-3d-fill','bi-badge-4k',
  'bi-badge-4k-fill','bi-badge-8k','bi-badge-8k-fill','bi-badge-ad','bi-badge-ad-fill',
  'bi-badge-ar','bi-badge-ar-fill','bi-badge-cc','bi-badge-cc-fill','bi-badge-hd',
  'bi-badge-hd-fill','bi-badge-sd','bi-badge-sd-fill','bi-badge-tm','bi-badge-tm-fill',
  'bi-badge-vo','bi-badge-vo-fill','bi-badge-vr','bi-badge-vr-fill','bi-badge-wc',
  'bi-badge-wc-fill','bi-bag','bi-bag-check','bi-bag-check-fill','bi-bag-dash',
  'bi-bag-dash-fill','bi-bag-fill','bi-bag-heart','bi-bag-heart-fill','bi-bag-plus',
  'bi-bag-plus-fill','bi-bag-x','bi-bag-x-fill','bi-balloon','bi-balloon-fill',
  'bi-balloon-heart','bi-balloon-heart-fill','bi-ban','bi-ban-fill','bi-bandaid',
  'bi-bandaid-fill','bi-bank','bi-bank2','bi-bar-chart','bi-bar-chart-fill',
  'bi-bar-chart-line','bi-bar-chart-line-fill','bi-bar-chart-steps','bi-basket',
  'bi-basket-fill','bi-basket2','bi-basket2-fill','bi-basket3','bi-basket3-fill',
  'bi-battery','bi-battery-charging','bi-battery-full','bi-battery-half','bi-battery-low',
  'bi-beaker','bi-beaker-fill','bi-behance','bi-bell','bi-bell-fill','bi-bell-slash',
  'bi-bell-slash-fill','bi-bezier','bi-bezier2','bi-bicycle','bi-bing','bi-binoculars',
  'bi-binoculars-fill','bi-blockquote-left','bi-blockquote-right','bi-bluesky',
  'bi-bluetooth','bi-body-text','bi-book','bi-book-fill','bi-book-half','bi-bookmark',
  'bi-bookmark-check','bi-bookmark-check-fill','bi-bookmark-dash','bi-bookmark-dash-fill',
  'bi-bookmark-fill','bi-bookmark-heart','bi-bookmark-heart-fill','bi-bookmark-plus',
  'bi-bookmark-plus-fill','bi-bookmark-star','bi-bookmark-star-fill','bi-bookmark-x',
  'bi-bookmark-x-fill','bi-bookmarks','bi-bookmarks-fill','bi-bookshelf','bi-boombox',
  'bi-boombox-fill','bi-bootstrap','bi-bootstrap-fill','bi-bootstrap-reboot','bi-border',
  'bi-border-all','bi-border-bottom','bi-border-center','bi-border-inner',
  'bi-border-left','bi-border-middle','bi-border-outer','bi-border-right',
  'bi-border-style','bi-border-top','bi-border-width','bi-bounding-box',
  'bi-bounding-box-circles','bi-box','bi-box-arrow-down','bi-box-arrow-down-left',
  'bi-box-arrow-down-right','bi-box-arrow-in-down','bi-box-arrow-in-down-left',
  'bi-box-arrow-in-down-right','bi-box-arrow-in-left','bi-box-arrow-in-right',
  'bi-box-arrow-in-up','bi-box-arrow-in-up-left','bi-box-arrow-in-up-right',
  'bi-box-arrow-left','bi-box-arrow-right','bi-box-arrow-up','bi-box-arrow-up-left',
  'bi-box-arrow-up-right','bi-box-fill','bi-box-seam','bi-box-seam-fill','bi-box2',
  'bi-box2-fill','bi-box2-heart','bi-box2-heart-fill','bi-boxes','bi-braces',
  'bi-braces-asterisk','bi-bricks','bi-briefcase','bi-briefcase-fill',
  'bi-brightness-alt-high','bi-brightness-alt-high-fill','bi-brightness-alt-low',
  'bi-brightness-alt-low-fill','bi-brightness-high','bi-brightness-high-fill',
  'bi-brightness-low','bi-brightness-low-fill','bi-brilliance','bi-broadcast',
  'bi-broadcast-pin','bi-browser-chrome','bi-browser-edge','bi-browser-firefox',
  'bi-browser-safari','bi-brush','bi-brush-fill','bi-bucket','bi-bucket-fill','bi-bug',
  'bi-bug-fill','bi-building','bi-building-add','bi-building-check','bi-building-dash',
  'bi-building-down','bi-building-exclamation','bi-building-fill','bi-building-fill-add',
  'bi-building-fill-check','bi-building-fill-dash','bi-building-fill-down',
  'bi-building-fill-exclamation','bi-building-fill-gear','bi-building-fill-lock',
  'bi-building-fill-slash','bi-building-fill-up','bi-building-fill-x','bi-building-gear',
  'bi-building-lock','bi-building-slash','bi-building-up','bi-building-x','bi-buildings',
  'bi-buildings-fill','bi-bullseye','bi-bus-front','bi-bus-front-fill','bi-c-circle',
  'bi-c-circle-fill','bi-c-square','bi-c-square-fill','bi-cake','bi-cake-fill','bi-cake2',
  'bi-cake2-fill','bi-calculator','bi-calculator-fill','bi-calendar','bi-calendar-check',
  'bi-calendar-check-fill','bi-calendar-date','bi-calendar-date-fill','bi-calendar-day',
  'bi-calendar-day-fill','bi-calendar-event','bi-calendar-event-fill','bi-calendar-fill',
  'bi-calendar-heart','bi-calendar-heart-fill','bi-calendar-minus',
  'bi-calendar-minus-fill','bi-calendar-month','bi-calendar-month-fill',
  'bi-calendar-plus','bi-calendar-plus-fill','bi-calendar-range','bi-calendar-range-fill',
  'bi-calendar-week','bi-calendar-week-fill','bi-calendar-x','bi-calendar-x-fill',
  'bi-calendar2','bi-calendar2-check','bi-calendar2-check-fill','bi-calendar2-date',
  'bi-calendar2-date-fill','bi-calendar2-day','bi-calendar2-day-fill',
  'bi-calendar2-event','bi-calendar2-event-fill','bi-calendar2-fill','bi-calendar2-heart',
  'bi-calendar2-heart-fill','bi-calendar2-minus','bi-calendar2-minus-fill',
  'bi-calendar2-month','bi-calendar2-month-fill','bi-calendar2-plus',
  'bi-calendar2-plus-fill','bi-calendar2-range','bi-calendar2-range-fill',
  'bi-calendar2-week','bi-calendar2-week-fill','bi-calendar2-x','bi-calendar2-x-fill',
  'bi-calendar3','bi-calendar3-event','bi-calendar3-event-fill','bi-calendar3-fill',
  'bi-calendar3-range','bi-calendar3-range-fill','bi-calendar3-week',
  'bi-calendar3-week-fill','bi-calendar4','bi-calendar4-event','bi-calendar4-range',
  'bi-calendar4-week','bi-camera','bi-camera-fill','bi-camera-reels',
  'bi-camera-reels-fill','bi-camera-video','bi-camera-video-fill','bi-camera-video-off',
  'bi-camera-video-off-fill','bi-camera2','bi-capslock','bi-capslock-fill','bi-capsule',
  'bi-capsule-pill','bi-car-front','bi-car-front-fill','bi-card-checklist',
  'bi-card-heading','bi-card-image','bi-card-list','bi-card-text','bi-caret-down',
  'bi-caret-down-fill','bi-caret-down-square','bi-caret-down-square-fill','bi-caret-left',
  'bi-caret-left-fill','bi-caret-left-square','bi-caret-left-square-fill',
  'bi-caret-right','bi-caret-right-fill','bi-caret-right-square',
  'bi-caret-right-square-fill','bi-caret-up','bi-caret-up-fill','bi-caret-up-square',
  'bi-caret-up-square-fill','bi-cart','bi-cart-check','bi-cart-check-fill','bi-cart-dash',
  'bi-cart-dash-fill','bi-cart-fill','bi-cart-plus','bi-cart-plus-fill','bi-cart-x',
  'bi-cart-x-fill','bi-cart2','bi-cart3','bi-cart4','bi-cash','bi-cash-coin',
  'bi-cash-stack','bi-cassette','bi-cassette-fill','bi-cast','bi-cc-circle',
  'bi-cc-circle-fill','bi-cc-square','bi-cc-square-fill','bi-chat','bi-chat-dots',
  'bi-chat-dots-fill','bi-chat-fill','bi-chat-heart','bi-chat-heart-fill','bi-chat-left',
  'bi-chat-left-dots','bi-chat-left-dots-fill','bi-chat-left-fill','bi-chat-left-heart',
  'bi-chat-left-heart-fill','bi-chat-left-quote','bi-chat-left-quote-fill',
  'bi-chat-left-text','bi-chat-left-text-fill','bi-chat-quote','bi-chat-quote-fill',
  'bi-chat-right','bi-chat-right-dots','bi-chat-right-dots-fill','bi-chat-right-fill',
  'bi-chat-right-heart','bi-chat-right-heart-fill','bi-chat-right-quote',
  'bi-chat-right-quote-fill','bi-chat-right-text','bi-chat-right-text-fill',
  'bi-chat-square','bi-chat-square-dots','bi-chat-square-dots-fill','bi-chat-square-fill',
  'bi-chat-square-heart','bi-chat-square-heart-fill','bi-chat-square-quote',
  'bi-chat-square-quote-fill','bi-chat-square-text','bi-chat-square-text-fill',
  'bi-chat-text','bi-chat-text-fill','bi-check','bi-check-all','bi-check-circle',
  'bi-check-circle-fill','bi-check-lg','bi-check-square','bi-check-square-fill',
  'bi-check2','bi-check2-all','bi-check2-circle','bi-check2-square',
  'bi-chevron-bar-contract','bi-chevron-bar-down','bi-chevron-bar-expand',
  'bi-chevron-bar-left','bi-chevron-bar-right','bi-chevron-bar-up',
  'bi-chevron-compact-down','bi-chevron-compact-left','bi-chevron-compact-right',
  'bi-chevron-compact-up','bi-chevron-contract','bi-chevron-double-down',
  'bi-chevron-double-left','bi-chevron-double-right','bi-chevron-double-up',
  'bi-chevron-down','bi-chevron-expand','bi-chevron-left','bi-chevron-right',
  'bi-chevron-up','bi-circle','bi-circle-fill','bi-circle-half','bi-circle-square',
  'bi-claude','bi-clipboard','bi-clipboard-check','bi-clipboard-check-fill',
  'bi-clipboard-data','bi-clipboard-data-fill','bi-clipboard-fill','bi-clipboard-heart',
  'bi-clipboard-heart-fill','bi-clipboard-minus','bi-clipboard-minus-fill',
  'bi-clipboard-plus','bi-clipboard-plus-fill','bi-clipboard-pulse','bi-clipboard-x',
  'bi-clipboard-x-fill','bi-clipboard2','bi-clipboard2-check','bi-clipboard2-check-fill',
  'bi-clipboard2-data','bi-clipboard2-data-fill','bi-clipboard2-fill',
  'bi-clipboard2-heart','bi-clipboard2-heart-fill','bi-clipboard2-minus',
  'bi-clipboard2-minus-fill','bi-clipboard2-plus','bi-clipboard2-plus-fill',
  'bi-clipboard2-pulse','bi-clipboard2-pulse-fill','bi-clipboard2-x',
  'bi-clipboard2-x-fill','bi-clock','bi-clock-fill','bi-clock-history','bi-cloud',
  'bi-cloud-arrow-down','bi-cloud-arrow-down-fill','bi-cloud-arrow-up',
  'bi-cloud-arrow-up-fill','bi-cloud-check','bi-cloud-check-fill','bi-cloud-download',
  'bi-cloud-download-fill','bi-cloud-drizzle','bi-cloud-drizzle-fill','bi-cloud-fill',
  'bi-cloud-fog','bi-cloud-fog-fill','bi-cloud-fog2','bi-cloud-fog2-fill','bi-cloud-hail',
  'bi-cloud-hail-fill','bi-cloud-haze','bi-cloud-haze-fill','bi-cloud-haze2',
  'bi-cloud-haze2-fill','bi-cloud-lightning','bi-cloud-lightning-fill',
  'bi-cloud-lightning-rain','bi-cloud-lightning-rain-fill','bi-cloud-minus',
  'bi-cloud-minus-fill','bi-cloud-moon','bi-cloud-moon-fill','bi-cloud-plus',
  'bi-cloud-plus-fill','bi-cloud-rain','bi-cloud-rain-fill','bi-cloud-rain-heavy',
  'bi-cloud-rain-heavy-fill','bi-cloud-slash','bi-cloud-slash-fill','bi-cloud-sleet',
  'bi-cloud-sleet-fill','bi-cloud-snow','bi-cloud-snow-fill','bi-cloud-sun',
  'bi-cloud-sun-fill','bi-cloud-upload','bi-cloud-upload-fill','bi-clouds',
  'bi-clouds-fill','bi-cloudy','bi-cloudy-fill','bi-code','bi-code-slash',
  'bi-code-square','bi-coin','bi-collection','bi-collection-fill','bi-collection-play',
  'bi-collection-play-fill','bi-columns','bi-columns-gap','bi-command','bi-compass',
  'bi-compass-fill','bi-cone','bi-cone-striped','bi-controller','bi-cookie','bi-copy',
  'bi-cpu','bi-cpu-fill','bi-credit-card','bi-credit-card-2-back',
  'bi-credit-card-2-back-fill','bi-credit-card-2-front','bi-credit-card-2-front-fill',
  'bi-credit-card-fill','bi-crop','bi-crosshair','bi-crosshair2','bi-css','bi-cup',
  'bi-cup-fill','bi-cup-hot','bi-cup-hot-fill','bi-cup-straw','bi-currency-bitcoin',
  'bi-currency-dollar','bi-currency-euro','bi-currency-exchange','bi-currency-pound',
  'bi-currency-rupee','bi-currency-yen','bi-cursor','bi-cursor-fill','bi-cursor-text',
  'bi-dash','bi-dash-circle','bi-dash-circle-dotted','bi-dash-circle-fill','bi-dash-lg',
  'bi-dash-square','bi-dash-square-dotted','bi-dash-square-fill','bi-database',
  'bi-database-add','bi-database-check','bi-database-dash','bi-database-down',
  'bi-database-exclamation','bi-database-fill','bi-database-fill-add',
  'bi-database-fill-check','bi-database-fill-dash','bi-database-fill-down',
  'bi-database-fill-exclamation','bi-database-fill-gear','bi-database-fill-lock',
  'bi-database-fill-slash','bi-database-fill-up','bi-database-fill-x','bi-database-gear',
  'bi-database-lock','bi-database-slash','bi-database-up','bi-database-x','bi-device-hdd',
  'bi-device-hdd-fill','bi-device-ssd','bi-device-ssd-fill','bi-diagram-2',
  'bi-diagram-2-fill','bi-diagram-3','bi-diagram-3-fill','bi-diamond','bi-diamond-fill',
  'bi-diamond-half','bi-dice-1','bi-dice-1-fill','bi-dice-2','bi-dice-2-fill','bi-dice-3',
  'bi-dice-3-fill','bi-dice-4','bi-dice-4-fill','bi-dice-5','bi-dice-5-fill','bi-dice-6',
  'bi-dice-6-fill','bi-disc','bi-disc-fill','bi-discord','bi-display','bi-display-fill',
  'bi-displayport','bi-displayport-fill','bi-distribute-horizontal',
  'bi-distribute-vertical','bi-door-closed','bi-door-closed-fill','bi-door-open',
  'bi-door-open-fill','bi-dot','bi-download','bi-dpad','bi-dpad-fill','bi-dribbble',
  'bi-dropbox','bi-droplet','bi-droplet-fill','bi-droplet-half','bi-duffle',
  'bi-duffle-fill','bi-ear','bi-ear-fill','bi-earbuds','bi-easel','bi-easel-fill',
  'bi-easel2','bi-easel2-fill','bi-easel3','bi-easel3-fill','bi-egg','bi-egg-fill',
  'bi-egg-fried','bi-eject','bi-eject-fill','bi-emoji-angry','bi-emoji-angry-fill',
  'bi-emoji-astonished','bi-emoji-astonished-fill','bi-emoji-dizzy','bi-emoji-dizzy-fill',
  'bi-emoji-expressionless','bi-emoji-expressionless-fill','bi-emoji-frown',
  'bi-emoji-frown-fill','bi-emoji-grimace','bi-emoji-grimace-fill','bi-emoji-grin',
  'bi-emoji-grin-fill','bi-emoji-heart-eyes','bi-emoji-heart-eyes-fill','bi-emoji-kiss',
  'bi-emoji-kiss-fill','bi-emoji-laughing','bi-emoji-laughing-fill','bi-emoji-neutral',
  'bi-emoji-neutral-fill','bi-emoji-smile','bi-emoji-smile-fill',
  'bi-emoji-smile-upside-down','bi-emoji-smile-upside-down-fill','bi-emoji-sunglasses',
  'bi-emoji-sunglasses-fill','bi-emoji-surprise','bi-emoji-surprise-fill','bi-emoji-tear',
  'bi-emoji-tear-fill','bi-emoji-wink','bi-emoji-wink-fill','bi-envelope',
  'bi-envelope-arrow-down','bi-envelope-arrow-down-fill','bi-envelope-arrow-up',
  'bi-envelope-arrow-up-fill','bi-envelope-at','bi-envelope-at-fill','bi-envelope-check',
  'bi-envelope-check-fill','bi-envelope-dash','bi-envelope-dash-fill',
  'bi-envelope-exclamation','bi-envelope-exclamation-fill','bi-envelope-fill',
  'bi-envelope-heart','bi-envelope-heart-fill','bi-envelope-open','bi-envelope-open-fill',
  'bi-envelope-open-heart','bi-envelope-open-heart-fill','bi-envelope-paper',
  'bi-envelope-paper-fill','bi-envelope-paper-heart','bi-envelope-paper-heart-fill',
  'bi-envelope-plus','bi-envelope-plus-fill','bi-envelope-slash','bi-envelope-slash-fill',
  'bi-envelope-x','bi-envelope-x-fill','bi-eraser','bi-eraser-fill','bi-escape',
  'bi-ethernet','bi-ev-front','bi-ev-front-fill','bi-ev-station','bi-ev-station-fill',
  'bi-exclamation','bi-exclamation-circle','bi-exclamation-circle-fill',
  'bi-exclamation-diamond','bi-exclamation-diamond-fill','bi-exclamation-lg',
  'bi-exclamation-octagon','bi-exclamation-octagon-fill','bi-exclamation-square',
  'bi-exclamation-square-fill','bi-exclamation-triangle','bi-exclamation-triangle-fill',
  'bi-exclude','bi-explicit','bi-explicit-fill','bi-exposure','bi-eye','bi-eye-fill',
  'bi-eye-slash','bi-eye-slash-fill','bi-eyedropper','bi-eyeglasses','bi-facebook',
  'bi-fan','bi-fast-forward','bi-fast-forward-btn','bi-fast-forward-btn-fill',
  'bi-fast-forward-circle','bi-fast-forward-circle-fill','bi-fast-forward-fill',
  'bi-feather','bi-feather2','bi-file','bi-file-arrow-down','bi-file-arrow-down-fill',
  'bi-file-arrow-up','bi-file-arrow-up-fill','bi-file-bar-graph','bi-file-bar-graph-fill',
  'bi-file-binary','bi-file-binary-fill','bi-file-break','bi-file-break-fill',
  'bi-file-check','bi-file-check-fill','bi-file-code','bi-file-code-fill','bi-file-diff',
  'bi-file-diff-fill','bi-file-earmark','bi-file-earmark-arrow-down',
  'bi-file-earmark-arrow-down-fill','bi-file-earmark-arrow-up',
  'bi-file-earmark-arrow-up-fill','bi-file-earmark-bar-graph',
  'bi-file-earmark-bar-graph-fill','bi-file-earmark-binary','bi-file-earmark-binary-fill',
  'bi-file-earmark-break','bi-file-earmark-break-fill','bi-file-earmark-check',
  'bi-file-earmark-check-fill','bi-file-earmark-code','bi-file-earmark-code-fill',
  'bi-file-earmark-diff','bi-file-earmark-diff-fill','bi-file-earmark-easel',
  'bi-file-earmark-easel-fill','bi-file-earmark-excel','bi-file-earmark-excel-fill',
  'bi-file-earmark-fill','bi-file-earmark-font','bi-file-earmark-font-fill',
  'bi-file-earmark-image','bi-file-earmark-image-fill','bi-file-earmark-lock',
  'bi-file-earmark-lock-fill','bi-file-earmark-lock2','bi-file-earmark-lock2-fill',
  'bi-file-earmark-medical','bi-file-earmark-medical-fill','bi-file-earmark-minus',
  'bi-file-earmark-minus-fill','bi-file-earmark-music','bi-file-earmark-music-fill',
  'bi-file-earmark-pdf','bi-file-earmark-pdf-fill','bi-file-earmark-person',
  'bi-file-earmark-person-fill','bi-file-earmark-play','bi-file-earmark-play-fill',
  'bi-file-earmark-plus','bi-file-earmark-plus-fill','bi-file-earmark-post',
  'bi-file-earmark-post-fill','bi-file-earmark-ppt','bi-file-earmark-ppt-fill',
  'bi-file-earmark-richtext','bi-file-earmark-richtext-fill','bi-file-earmark-ruled',
  'bi-file-earmark-ruled-fill','bi-file-earmark-slides','bi-file-earmark-slides-fill',
  'bi-file-earmark-spreadsheet','bi-file-earmark-spreadsheet-fill','bi-file-earmark-text',
  'bi-file-earmark-text-fill','bi-file-earmark-word','bi-file-earmark-word-fill',
  'bi-file-earmark-x','bi-file-earmark-x-fill','bi-file-earmark-zip',
  'bi-file-earmark-zip-fill','bi-file-easel','bi-file-easel-fill','bi-file-excel',
  'bi-file-excel-fill','bi-file-fill','bi-file-font','bi-file-font-fill','bi-file-image',
  'bi-file-image-fill','bi-file-lock','bi-file-lock-fill','bi-file-lock2',
  'bi-file-lock2-fill','bi-file-medical','bi-file-medical-fill','bi-file-minus',
  'bi-file-minus-fill','bi-file-music','bi-file-music-fill','bi-file-pdf',
  'bi-file-pdf-fill','bi-file-person','bi-file-person-fill','bi-file-play',
  'bi-file-play-fill','bi-file-plus','bi-file-plus-fill','bi-file-post',
  'bi-file-post-fill','bi-file-ppt','bi-file-ppt-fill','bi-file-richtext',
  'bi-file-richtext-fill','bi-file-ruled','bi-file-ruled-fill','bi-file-slides',
  'bi-file-slides-fill','bi-file-spreadsheet','bi-file-spreadsheet-fill','bi-file-text',
  'bi-file-text-fill','bi-file-word','bi-file-word-fill','bi-file-x','bi-file-x-fill',
  'bi-file-zip','bi-file-zip-fill','bi-files','bi-files-alt','bi-filetype-aac',
  'bi-filetype-ai','bi-filetype-bmp','bi-filetype-cs','bi-filetype-css','bi-filetype-csv',
  'bi-filetype-doc','bi-filetype-docx','bi-filetype-exe','bi-filetype-gif',
  'bi-filetype-heic','bi-filetype-html','bi-filetype-java','bi-filetype-jpg',
  'bi-filetype-js','bi-filetype-json','bi-filetype-jsx','bi-filetype-key',
  'bi-filetype-m4p','bi-filetype-md','bi-filetype-mdx','bi-filetype-mov',
  'bi-filetype-mp3','bi-filetype-mp4','bi-filetype-otf','bi-filetype-pdf',
  'bi-filetype-php','bi-filetype-png','bi-filetype-ppt','bi-filetype-pptx',
  'bi-filetype-psd','bi-filetype-py','bi-filetype-raw','bi-filetype-rb',
  'bi-filetype-sass','bi-filetype-scss','bi-filetype-sh','bi-filetype-sql',
  'bi-filetype-svg','bi-filetype-tiff','bi-filetype-tsx','bi-filetype-ttf',
  'bi-filetype-txt','bi-filetype-wav','bi-filetype-woff','bi-filetype-xls',
  'bi-filetype-xlsx','bi-filetype-xml','bi-filetype-yml','bi-film','bi-filter',
  'bi-filter-circle','bi-filter-circle-fill','bi-filter-left','bi-filter-right',
  'bi-filter-square','bi-filter-square-fill','bi-fingerprint','bi-fire','bi-flag',
  'bi-flag-fill','bi-flask','bi-flask-fill','bi-flask-florence','bi-flask-florence-fill',
  'bi-floppy','bi-floppy-fill','bi-floppy2','bi-floppy2-fill','bi-flower1','bi-flower2',
  'bi-flower3','bi-folder','bi-folder-check','bi-folder-fill','bi-folder-minus',
  'bi-folder-plus','bi-folder-symlink','bi-folder-symlink-fill','bi-folder-x',
  'bi-folder2','bi-folder2-open','bi-fonts','bi-fork-knife','bi-forward',
  'bi-forward-fill','bi-front','bi-fuel-pump','bi-fuel-pump-diesel',
  'bi-fuel-pump-diesel-fill','bi-fuel-pump-fill','bi-fullscreen','bi-fullscreen-exit',
  'bi-funnel','bi-funnel-fill','bi-gear','bi-gear-fill','bi-gear-wide',
  'bi-gear-wide-connected','bi-gem','bi-gender-ambiguous','bi-gender-female',
  'bi-gender-male','bi-gender-neuter','bi-gender-trans','bi-geo','bi-geo-alt',
  'bi-geo-alt-fill','bi-geo-fill','bi-gift','bi-gift-fill','bi-git','bi-github',
  'bi-gitlab','bi-globe','bi-globe-americas','bi-globe-americas-fill',
  'bi-globe-asia-australia','bi-globe-asia-australia-fill','bi-globe-central-south-asia',
  'bi-globe-central-south-asia-fill','bi-globe-europe-africa',
  'bi-globe-europe-africa-fill','bi-globe2','bi-google','bi-google-play','bi-gpu-card',
  'bi-graph-down','bi-graph-down-arrow','bi-graph-up','bi-graph-up-arrow','bi-grid',
  'bi-grid-1x2','bi-grid-1x2-fill','bi-grid-3x2','bi-grid-3x2-gap','bi-grid-3x2-gap-fill',
  'bi-grid-3x3','bi-grid-3x3-gap','bi-grid-3x3-gap-fill','bi-grid-fill',
  'bi-grip-horizontal','bi-grip-vertical','bi-h-circle','bi-h-circle-fill','bi-h-square',
  'bi-h-square-fill','bi-hammer','bi-hand-index','bi-hand-index-fill',
  'bi-hand-index-thumb','bi-hand-index-thumb-fill','bi-hand-thumbs-down',
  'bi-hand-thumbs-down-fill','bi-hand-thumbs-up','bi-hand-thumbs-up-fill','bi-handbag',
  'bi-handbag-fill','bi-hash','bi-hdd','bi-hdd-fill','bi-hdd-network',
  'bi-hdd-network-fill','bi-hdd-rack','bi-hdd-rack-fill','bi-hdd-stack',
  'bi-hdd-stack-fill','bi-hdmi','bi-hdmi-fill','bi-headphones','bi-headset',
  'bi-headset-vr','bi-heart','bi-heart-arrow','bi-heart-fill','bi-heart-half',
  'bi-heart-pulse','bi-heart-pulse-fill','bi-heartbreak','bi-heartbreak-fill','bi-hearts',
  'bi-heptagon','bi-heptagon-fill','bi-heptagon-half','bi-hexagon','bi-hexagon-fill',
  'bi-hexagon-half','bi-highlighter','bi-highlights','bi-hospital','bi-hospital-fill',
  'bi-hourglass','bi-hourglass-bottom','bi-hourglass-split','bi-hourglass-top','bi-house',
  'bi-house-add','bi-house-add-fill','bi-house-check','bi-house-check-fill',
  'bi-house-dash','bi-house-dash-fill','bi-house-door','bi-house-door-fill',
  'bi-house-down','bi-house-down-fill','bi-house-exclamation','bi-house-exclamation-fill',
  'bi-house-fill','bi-house-gear','bi-house-gear-fill','bi-house-heart',
  'bi-house-heart-fill','bi-house-lock','bi-house-lock-fill','bi-house-slash',
  'bi-house-slash-fill','bi-house-up','bi-house-up-fill','bi-house-x','bi-house-x-fill',
  'bi-houses','bi-houses-fill','bi-hr','bi-hurricane','bi-hypnotize','bi-image',
  'bi-image-alt','bi-image-fill','bi-images','bi-inbox','bi-inbox-fill','bi-inboxes',
  'bi-inboxes-fill','bi-incognito','bi-indent','bi-infinity','bi-info','bi-info-circle',
  'bi-info-circle-fill','bi-info-lg','bi-info-square','bi-info-square-fill',
  'bi-input-cursor','bi-input-cursor-text','bi-instagram','bi-intersect','bi-javascript',
  'bi-journal','bi-journal-album','bi-journal-arrow-down','bi-journal-arrow-up',
  'bi-journal-bookmark','bi-journal-bookmark-fill','bi-journal-check','bi-journal-code',
  'bi-journal-medical','bi-journal-minus','bi-journal-plus','bi-journal-richtext',
  'bi-journal-text','bi-journal-x','bi-journals','bi-joystick','bi-justify',
  'bi-justify-left','bi-justify-right','bi-kanban','bi-kanban-fill','bi-key',
  'bi-key-fill','bi-keyboard','bi-keyboard-fill','bi-ladder','bi-lamp','bi-lamp-fill',
  'bi-laptop','bi-laptop-fill','bi-layer-backward','bi-layer-forward','bi-layers',
  'bi-layers-fill','bi-layers-half','bi-layout-sidebar','bi-layout-sidebar-inset',
  'bi-layout-sidebar-inset-reverse','bi-layout-sidebar-reverse','bi-layout-split',
  'bi-layout-text-sidebar','bi-layout-text-sidebar-reverse','bi-layout-text-window',
  'bi-layout-text-window-reverse','bi-layout-three-columns','bi-layout-wtf','bi-leaf',
  'bi-leaf-fill','bi-life-preserver','bi-lightbulb','bi-lightbulb-fill',
  'bi-lightbulb-off','bi-lightbulb-off-fill','bi-lightning','bi-lightning-charge',
  'bi-lightning-charge-fill','bi-lightning-fill','bi-line','bi-link','bi-link-45deg',
  'bi-linkedin','bi-list','bi-list-check','bi-list-columns','bi-list-columns-reverse',
  'bi-list-nested','bi-list-ol','bi-list-stars','bi-list-task','bi-list-ul','bi-lock',
  'bi-lock-fill','bi-luggage','bi-luggage-fill','bi-lungs','bi-lungs-fill','bi-magic',
  'bi-magnet','bi-magnet-fill','bi-mailbox','bi-mailbox-flag','bi-mailbox2',
  'bi-mailbox2-flag','bi-map','bi-map-fill','bi-markdown','bi-markdown-fill',
  'bi-marker-tip','bi-mask','bi-mastodon','bi-measuring-cup','bi-measuring-cup-fill',
  'bi-medium','bi-megaphone','bi-megaphone-fill','bi-memory','bi-menu-app',
  'bi-menu-app-fill','bi-menu-button','bi-menu-button-fill','bi-menu-button-wide',
  'bi-menu-button-wide-fill','bi-menu-down','bi-menu-up','bi-messenger','bi-meta',
  'bi-mic','bi-mic-fill','bi-mic-mute','bi-mic-mute-fill','bi-microsoft',
  'bi-microsoft-teams','bi-minecart','bi-minecart-loaded','bi-modem','bi-modem-fill',
  'bi-moisture','bi-moon','bi-moon-fill','bi-moon-stars','bi-moon-stars-fill',
  'bi-mortarboard','bi-mortarboard-fill','bi-motherboard','bi-motherboard-fill',
  'bi-mouse','bi-mouse-fill','bi-mouse2','bi-mouse2-fill','bi-mouse3','bi-mouse3-fill',
  'bi-music-note','bi-music-note-beamed','bi-music-note-list','bi-music-player',
  'bi-music-player-fill','bi-newspaper','bi-nintendo-switch','bi-node-minus',
  'bi-node-minus-fill','bi-node-plus','bi-node-plus-fill','bi-noise-reduction','bi-nut',
  'bi-nut-fill','bi-nvidia','bi-nvme','bi-nvme-fill','bi-octagon','bi-octagon-fill',
  'bi-octagon-half','bi-openai','bi-opencollective','bi-optical-audio',
  'bi-optical-audio-fill','bi-option','bi-outlet','bi-p-circle','bi-p-circle-fill',
  'bi-p-square','bi-p-square-fill','bi-paint-bucket','bi-palette','bi-palette-fill',
  'bi-palette2','bi-paperclip','bi-paragraph','bi-pass','bi-pass-fill','bi-passport',
  'bi-passport-fill','bi-patch-check','bi-patch-check-fill','bi-patch-exclamation',
  'bi-patch-exclamation-fill','bi-patch-minus','bi-patch-minus-fill','bi-patch-plus',
  'bi-patch-plus-fill','bi-patch-question','bi-patch-question-fill','bi-pause',
  'bi-pause-btn','bi-pause-btn-fill','bi-pause-circle','bi-pause-circle-fill',
  'bi-pause-fill','bi-paypal','bi-pc','bi-pc-display','bi-pc-display-horizontal',
  'bi-pc-horizontal','bi-pci-card','bi-pci-card-network','bi-pci-card-sound','bi-peace',
  'bi-peace-fill','bi-pen','bi-pen-fill','bi-pencil','bi-pencil-fill','bi-pencil-square',
  'bi-pentagon','bi-pentagon-fill','bi-pentagon-half','bi-people','bi-people-fill',
  'bi-percent','bi-perplexity','bi-person','bi-person-add','bi-person-arms-up',
  'bi-person-badge','bi-person-badge-fill','bi-person-bounding-box','bi-person-check',
  'bi-person-check-fill','bi-person-circle','bi-person-dash','bi-person-dash-fill',
  'bi-person-down','bi-person-exclamation','bi-person-fill','bi-person-fill-add',
  'bi-person-fill-check','bi-person-fill-dash','bi-person-fill-down',
  'bi-person-fill-exclamation','bi-person-fill-gear','bi-person-fill-lock',
  'bi-person-fill-slash','bi-person-fill-up','bi-person-fill-x','bi-person-gear',
  'bi-person-heart','bi-person-hearts','bi-person-lines-fill','bi-person-lock',
  'bi-person-plus','bi-person-plus-fill','bi-person-raised-hand','bi-person-rolodex',
  'bi-person-slash','bi-person-square','bi-person-standing','bi-person-standing-dress',
  'bi-person-up','bi-person-vcard','bi-person-vcard-fill','bi-person-video',
  'bi-person-video2','bi-person-video3','bi-person-walking','bi-person-wheelchair',
  'bi-person-workspace','bi-person-x','bi-person-x-fill','bi-phone','bi-phone-fill',
  'bi-phone-flip','bi-phone-landscape','bi-phone-landscape-fill','bi-phone-vibrate',
  'bi-phone-vibrate-fill','bi-pie-chart','bi-pie-chart-fill','bi-piggy-bank',
  'bi-piggy-bank-fill','bi-pin','bi-pin-angle','bi-pin-angle-fill','bi-pin-fill',
  'bi-pin-map','bi-pin-map-fill','bi-pinterest','bi-pip','bi-pip-fill','bi-play',
  'bi-play-btn','bi-play-btn-fill','bi-play-circle','bi-play-circle-fill','bi-play-fill',
  'bi-playstation','bi-plug','bi-plug-fill','bi-plugin','bi-plus','bi-plus-circle',
  'bi-plus-circle-dotted','bi-plus-circle-fill','bi-plus-lg','bi-plus-slash-minus',
  'bi-plus-square','bi-plus-square-dotted','bi-plus-square-fill','bi-postage',
  'bi-postage-fill','bi-postage-heart','bi-postage-heart-fill','bi-postcard',
  'bi-postcard-fill','bi-postcard-heart','bi-postcard-heart-fill','bi-power',
  'bi-prescription','bi-prescription2','bi-printer','bi-printer-fill','bi-projector',
  'bi-projector-fill','bi-puzzle','bi-puzzle-fill','bi-qr-code','bi-qr-code-scan',
  'bi-question','bi-question-circle','bi-question-circle-fill','bi-question-diamond',
  'bi-question-diamond-fill','bi-question-lg','bi-question-octagon',
  'bi-question-octagon-fill','bi-question-square','bi-question-square-fill','bi-quora',
  'bi-quote','bi-r-circle','bi-r-circle-fill','bi-r-square','bi-r-square-fill','bi-radar',
  'bi-radioactive','bi-rainbow','bi-receipt','bi-receipt-cutoff','bi-reception-0',
  'bi-reception-1','bi-reception-2','bi-reception-3','bi-reception-4','bi-record',
  'bi-record-btn','bi-record-btn-fill','bi-record-circle','bi-record-circle-fill',
  'bi-record-fill','bi-record2','bi-record2-fill','bi-recycle','bi-reddit','bi-regex',
  'bi-repeat','bi-repeat-1','bi-reply','bi-reply-all','bi-reply-all-fill','bi-reply-fill',
  'bi-rewind','bi-rewind-btn','bi-rewind-btn-fill','bi-rewind-circle',
  'bi-rewind-circle-fill','bi-rewind-fill','bi-robot','bi-rocket','bi-rocket-fill',
  'bi-rocket-takeoff','bi-rocket-takeoff-fill','bi-router','bi-router-fill','bi-rss',
  'bi-rss-fill','bi-rulers','bi-safe','bi-safe-fill','bi-safe2','bi-safe2-fill','bi-save',
  'bi-save-fill','bi-save2','bi-save2-fill','bi-scissors','bi-scooter','bi-screwdriver',
  'bi-sd-card','bi-sd-card-fill','bi-search','bi-search-heart','bi-search-heart-fill',
  'bi-segmented-nav','bi-send','bi-send-arrow-down','bi-send-arrow-down-fill',
  'bi-send-arrow-up','bi-send-arrow-up-fill','bi-send-check','bi-send-check-fill',
  'bi-send-dash','bi-send-dash-fill','bi-send-exclamation','bi-send-exclamation-fill',
  'bi-send-fill','bi-send-plus','bi-send-plus-fill','bi-send-slash','bi-send-slash-fill',
  'bi-send-x','bi-send-x-fill','bi-server','bi-shadows','bi-share','bi-share-fill',
  'bi-shield','bi-shield-check','bi-shield-exclamation','bi-shield-fill',
  'bi-shield-fill-check','bi-shield-fill-exclamation','bi-shield-fill-minus',
  'bi-shield-fill-plus','bi-shield-fill-x','bi-shield-lock','bi-shield-lock-fill',
  'bi-shield-minus','bi-shield-plus','bi-shield-shaded','bi-shield-slash',
  'bi-shield-slash-fill','bi-shield-x','bi-shift','bi-shift-fill','bi-shop',
  'bi-shop-window','bi-shuffle','bi-sign-dead-end','bi-sign-dead-end-fill',
  'bi-sign-do-not-enter','bi-sign-do-not-enter-fill','bi-sign-intersection',
  'bi-sign-intersection-fill','bi-sign-intersection-side',
  'bi-sign-intersection-side-fill','bi-sign-intersection-t','bi-sign-intersection-t-fill',
  'bi-sign-intersection-y','bi-sign-intersection-y-fill','bi-sign-merge-left',
  'bi-sign-merge-left-fill','bi-sign-merge-right','bi-sign-merge-right-fill',
  'bi-sign-no-left-turn','bi-sign-no-left-turn-fill','bi-sign-no-parking',
  'bi-sign-no-parking-fill','bi-sign-no-right-turn','bi-sign-no-right-turn-fill',
  'bi-sign-railroad','bi-sign-railroad-fill','bi-sign-stop','bi-sign-stop-fill',
  'bi-sign-stop-lights','bi-sign-stop-lights-fill','bi-sign-turn-left',
  'bi-sign-turn-left-fill','bi-sign-turn-right','bi-sign-turn-right-fill',
  'bi-sign-turn-slight-left','bi-sign-turn-slight-left-fill','bi-sign-turn-slight-right',
  'bi-sign-turn-slight-right-fill','bi-sign-yield','bi-sign-yield-fill','bi-signal',
  'bi-signpost','bi-signpost-2','bi-signpost-2-fill','bi-signpost-fill',
  'bi-signpost-split','bi-signpost-split-fill','bi-sim','bi-sim-fill','bi-sim-slash',
  'bi-sim-slash-fill','bi-sina-weibo','bi-skip-backward','bi-skip-backward-btn',
  'bi-skip-backward-btn-fill','bi-skip-backward-circle','bi-skip-backward-circle-fill',
  'bi-skip-backward-fill','bi-skip-end','bi-skip-end-btn','bi-skip-end-btn-fill',
  'bi-skip-end-circle','bi-skip-end-circle-fill','bi-skip-end-fill','bi-skip-forward',
  'bi-skip-forward-btn','bi-skip-forward-btn-fill','bi-skip-forward-circle',
  'bi-skip-forward-circle-fill','bi-skip-forward-fill','bi-skip-start',
  'bi-skip-start-btn','bi-skip-start-btn-fill','bi-skip-start-circle',
  'bi-skip-start-circle-fill','bi-skip-start-fill','bi-skype','bi-slack','bi-slash',
  'bi-slash-circle','bi-slash-circle-fill','bi-slash-lg','bi-slash-square',
  'bi-slash-square-fill','bi-sliders','bi-sliders2','bi-sliders2-vertical',
  'bi-smartwatch','bi-snapchat','bi-snow','bi-snow2','bi-snow3','bi-sort-alpha-down',
  'bi-sort-alpha-down-alt','bi-sort-alpha-up','bi-sort-alpha-up-alt','bi-sort-down',
  'bi-sort-down-alt','bi-sort-numeric-down','bi-sort-numeric-down-alt',
  'bi-sort-numeric-up','bi-sort-numeric-up-alt','bi-sort-up','bi-sort-up-alt',
  'bi-soundwave','bi-sourceforge','bi-speaker','bi-speaker-fill','bi-speedometer',
  'bi-speedometer2','bi-spellcheck','bi-spotify','bi-square','bi-square-fill',
  'bi-square-half','bi-stack','bi-stack-overflow','bi-star','bi-star-fill','bi-star-half',
  'bi-stars','bi-steam','bi-stickies','bi-stickies-fill','bi-sticky','bi-sticky-fill',
  'bi-stop','bi-stop-btn','bi-stop-btn-fill','bi-stop-circle','bi-stop-circle-fill',
  'bi-stop-fill','bi-stoplights','bi-stoplights-fill','bi-stopwatch','bi-stopwatch-fill',
  'bi-strava','bi-stripe','bi-subscript','bi-substack','bi-subtract','bi-suit-club',
  'bi-suit-club-fill','bi-suit-diamond','bi-suit-diamond-fill','bi-suit-heart',
  'bi-suit-heart-fill','bi-suit-spade','bi-suit-spade-fill','bi-suitcase',
  'bi-suitcase-fill','bi-suitcase-lg','bi-suitcase-lg-fill','bi-suitcase2',
  'bi-suitcase2-fill','bi-sun','bi-sun-fill','bi-sunglasses','bi-sunrise',
  'bi-sunrise-fill','bi-sunset','bi-sunset-fill','bi-superscript',
  'bi-symmetry-horizontal','bi-symmetry-vertical','bi-table','bi-tablet','bi-tablet-fill',
  'bi-tablet-landscape','bi-tablet-landscape-fill','bi-tag','bi-tag-fill','bi-tags',
  'bi-tags-fill','bi-taxi-front','bi-taxi-front-fill','bi-telegram','bi-telephone',
  'bi-telephone-fill','bi-telephone-forward','bi-telephone-forward-fill',
  'bi-telephone-inbound','bi-telephone-inbound-fill','bi-telephone-minus',
  'bi-telephone-minus-fill','bi-telephone-outbound','bi-telephone-outbound-fill',
  'bi-telephone-plus','bi-telephone-plus-fill','bi-telephone-x','bi-telephone-x-fill',
  'bi-tencent-qq','bi-terminal','bi-terminal-dash','bi-terminal-fill','bi-terminal-plus',
  'bi-terminal-split','bi-terminal-x','bi-text-center','bi-text-indent-left',
  'bi-text-indent-right','bi-text-left','bi-text-paragraph','bi-text-right',
  'bi-text-wrap','bi-textarea','bi-textarea-resize','bi-textarea-t','bi-thermometer',
  'bi-thermometer-half','bi-thermometer-high','bi-thermometer-low','bi-thermometer-snow',
  'bi-thermometer-sun','bi-threads','bi-threads-fill','bi-three-dots',
  'bi-three-dots-vertical','bi-thunderbolt','bi-thunderbolt-fill','bi-ticket',
  'bi-ticket-detailed','bi-ticket-detailed-fill','bi-ticket-fill','bi-ticket-perforated',
  'bi-ticket-perforated-fill','bi-tiktok','bi-toggle-off','bi-toggle-on','bi-toggle2-off',
  'bi-toggle2-on','bi-toggles','bi-toggles2','bi-tools','bi-tornado',
  'bi-train-freight-front','bi-train-freight-front-fill','bi-train-front',
  'bi-train-front-fill','bi-train-lightrail-front','bi-train-lightrail-front-fill',
  'bi-translate','bi-transparency','bi-trash','bi-trash-fill','bi-trash2',
  'bi-trash2-fill','bi-trash3','bi-trash3-fill','bi-tree','bi-tree-fill','bi-trello',
  'bi-triangle','bi-triangle-fill','bi-triangle-half','bi-trophy','bi-trophy-fill',
  'bi-tropical-storm','bi-truck','bi-truck-flatbed','bi-truck-front',
  'bi-truck-front-fill','bi-tsunami','bi-tux','bi-tv','bi-tv-fill','bi-twitch',
  'bi-twitter','bi-twitter-x','bi-type','bi-type-bold','bi-type-h1','bi-type-h2',
  'bi-type-h3','bi-type-h4','bi-type-h5','bi-type-h6','bi-type-italic',
  'bi-type-strikethrough','bi-type-underline','bi-typescript','bi-ubuntu','bi-ui-checks',
  'bi-ui-checks-grid','bi-ui-radios','bi-ui-radios-grid','bi-umbrella','bi-umbrella-fill',
  'bi-unindent','bi-union','bi-unity','bi-universal-access','bi-universal-access-circle',
  'bi-unlock','bi-unlock-fill','bi-unlock2','bi-unlock2-fill','bi-upc','bi-upc-scan',
  'bi-upload','bi-usb','bi-usb-c','bi-usb-c-fill','bi-usb-drive','bi-usb-drive-fill',
  'bi-usb-fill','bi-usb-micro','bi-usb-micro-fill','bi-usb-mini','bi-usb-mini-fill',
  'bi-usb-plug','bi-usb-plug-fill','bi-usb-symbol','bi-valentine','bi-valentine2',
  'bi-vector-pen','bi-view-list','bi-view-stacked','bi-vignette','bi-vimeo','bi-vinyl',
  'bi-vinyl-fill','bi-virus','bi-virus2','bi-voicemail','bi-volume-down',
  'bi-volume-down-fill','bi-volume-mute','bi-volume-mute-fill','bi-volume-off',
  'bi-volume-off-fill','bi-volume-up','bi-volume-up-fill','bi-vr','bi-wallet',
  'bi-wallet-fill','bi-wallet2','bi-watch','bi-water','bi-webcam','bi-webcam-fill',
  'bi-wechat','bi-whatsapp','bi-wifi','bi-wifi-1','bi-wifi-2','bi-wifi-off',
  'bi-wikipedia','bi-wind','bi-window','bi-window-dash','bi-window-desktop',
  'bi-window-dock','bi-window-fullscreen','bi-window-plus','bi-window-sidebar',
  'bi-window-split','bi-window-stack','bi-window-x','bi-windows','bi-wordpress',
  'bi-wrench','bi-wrench-adjustable','bi-wrench-adjustable-circle',
  'bi-wrench-adjustable-circle-fill','bi-x','bi-x-circle','bi-x-circle-fill',
  'bi-x-diamond','bi-x-diamond-fill','bi-x-lg','bi-x-octagon','bi-x-octagon-fill',
  'bi-x-square','bi-x-square-fill','bi-xbox','bi-yelp','bi-yin-yang','bi-youtube',
  'bi-zoom-in','bi-zoom-out'
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

// ── Filter BI_ICONS down to icons the loaded bootstrap-icons.min.css actually has glyphs for ──
// (prevents blank boxes when BI_ICONS was generated from a newer bootstrap-icons version
// than the vendored CSS file, e.g. bi-anthropic / bi-bluesky / bi-arrow-through-heart)
let USABLE_BI_ICONS = null;
function getUsableIcons() {
  if (USABLE_BI_ICONS) return USABLE_BI_ICONS;
  const probe = document.createElement('i');
  probe.style.cssText = 'position:absolute;visibility:hidden;pointer-events:none;top:-9999px;';
  document.body.appendChild(probe);
  USABLE_BI_ICONS = BI_ICONS.filter(ic => {
    probe.className = `bi ${ic}`;
    const content = getComputedStyle(probe, '::before').content;
    return content && content !== 'none' && content !== '""' && content !== 'normal';
  });
  document.body.removeChild(probe);
  return USABLE_BI_ICONS;
}

// ── Icon grid picker ──────────────────────────────────────────────────────────
function setupIconPicker(searchId, gridId, selectedId, hiddenId, previewPrefix) {
  const searchInput = document.getElementById(searchId);
  const grid        = document.getElementById(gridId);
  const selectedEl  = document.getElementById(selectedId);
  const hidden      = document.getElementById(hiddenId);
  const RESULT_CAP  = 300;

  function renderGrid(filter) {
    const icons  = getUsableIcons();
    const q       = (filter || '').toLowerCase().replace(/^bi-/, '');
    const matches = q ? icons.filter(ic => ic.includes(q)) : icons;
    const shown   = matches.slice(0, RESULT_CAP);
    grid.innerHTML = shown.map(ic =>
      `<div class="icon-grid-item ${hidden.value === ic ? 'active' : ''}" data-icon="${ic}" title="${ic}">
        <i class="bi ${ic}"></i>
      </div>`
    ).join('');
    if (matches.length > RESULT_CAP) {
      grid.innerHTML += `<div style="grid-column:1/-1;padding:.5rem;text-align:center;font-size:.75rem;color:var(--w40);">
        Showing ${RESULT_CAP} of ${matches.length} matches — keep typing to narrow it down
      </div>`;
    }
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
  document.getElementById('statModuleCount').textContent = cnt;
  document.getElementById('tabModBadge').textContent     = cnt;
  applyRegistryFilter(); // re-applies current search/category filter and sets moduleRegistryCount accordingly
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
    return `<div class="ma-module-row" data-name="${escHtml(mod.module_name.toLowerCase())}" style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;
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
  document.getElementById('ma_rbac_search').value = '';

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
// Grant All / Remove All — bulk-set the dropdowns locally; existing
// "Save Module Access" click still persists via assign_user_access,
// same as manually setting each select then saving.
// Recolor a dropdown immediately when manually changed — mirrors what
// Grant All / Remove All already do, so a single manual edit gets the
// same live visual feedback instead of keeping its stale color until Save.
document.getElementById('ma_rbac_list').addEventListener('change', e => {
  const sel = e.target;
  if (!sel.classList.contains('perm-select')) return;
  sel.classList.remove('is-none', 'is-view_only', 'is-full');
  sel.classList.add('is-' + sel.value);
});

document.getElementById('ma_rbac_search').addEventListener('input', e => {
  const q = e.target.value.trim().toLowerCase();
  document.querySelectorAll('#ma_rbac_list .ma-module-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) ? 'flex' : 'none';
  });
});

document.getElementById('ma_grantAll')?.addEventListener('click', () => {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  document.querySelectorAll('#ma_rbac_list .perm-select').forEach(sel => {
    sel.value = 'full';
    sel.className = 'perm-select is-full';
  });
  toast('All modules set to Full Access — click Save to apply.');
});

document.getElementById('ma_revokeAll')?.addEventListener('click', () => {
  if (IS_VIEW_ONLY) { toast('You have view-only access — changes are not allowed.', 'error'); return; }
  document.querySelectorAll('#ma_rbac_list .perm-select').forEach(sel => {
    sel.value = 'none';
    sel.className = 'perm-select is-none';
  });
  toast('All modules set to No Access — click Save to apply.');
});

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