<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

/* -----------------------------------------------------------
   SECURITY: employee identity comes ONLY from the session.
   Never read EmployeeID from $_GET / $_POST anywhere on this
   page — that is the entire boundary that keeps this a
   "my own records only" page instead of an HR page.
----------------------------------------------------------- */
$empId   = $_SESSION['EmployeeID']  ?? '';
$empName = trim($_SESSION['DisplayName'] ?? '');
$dept    = trim($_SESSION['Department']  ?? '');

if ($empId === '') {
    // No employee record tied to this login (e.g. a system/admin-only
    // account with no HR employee row) — nothing to show, bail safely.
    http_response_code(403);
    die('No employee record is linked to this account.');
}

date_default_timezone_set('Asia/Manila');
$today          = date('Y-m-d');
$firstOfMonth   = date('Y-m-01');

// Date range filter — the ONLY filter exposed on this page.
// No department, no employee picker, no "view other employee" option.
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : $firstOfMonth;
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : $today;

// Active tab — whitelisted, defaults to overview.
$validTabs = ['overview', 'generated', 'calendar'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs, true) ? $_GET['tab'] : 'overview';

/* -----------------------------------------------------------
   Daily Time Record — same view/shape as the HR "myattendance"
   tab, but as its own parameterized query so this file has zero
   dependency on attendance.php internals.
----------------------------------------------------------- */
$dtrSql = "
    SELECT EmployeeID, Department, EmployeeName, Category, ADate,
           MorningIn, MorningOut, AfternoonIn, AfternoonOut, TimeIn, TimeInPM,
           AMLate, PMLate, Late1, MorningTotalHours, AfternoonTotalHours,
           MorningAfternoonTotal, TotalHours
    FROM View_ATtendanceTimeInTimeOut2
    WHERE EmployeeID = ? AND ADate BETWEEN ? AND ?
    ORDER BY ADate DESC
";
$debugLog = []; // label => sqlsrv_errors(), surfaced as an HTML comment for troubleshooting
$dtrRows = [];
$dtrStmt = sqlsrv_query($conn, $dtrSql, [$empId, $dateFrom, $dateTo]);
if ($dtrStmt) {
    while ($r = sqlsrv_fetch_array($dtrStmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($r as $k => $v) { if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s'); }
        $dtrRows[] = $r;
    }
    sqlsrv_free_stmt($dtrStmt);
} else {
    $debugLog['dtr'] = sqlsrv_errors();
    error_log('my_attendance.php dtrSql failed: ' . print_r(sqlsrv_errors(), true));
}

/* -----------------------------------------------------------
   Recent activity log — integrated punches (device / portal /
   biometric) with source + direction, scoped to self.
----------------------------------------------------------- */
$logSql = "
    SELECT DataFrom, ADate, CheckIn, ATime, Area, Category, Direction
    FROM View_Attendance_Log2
    WHERE EmployeeID = ? AND ADate BETWEEN ? AND ?
    ORDER BY ADate DESC, ATime DESC
";
$logRows = [];
$logStmt = sqlsrv_query($conn, $logSql, [$empId, $dateFrom, $dateTo]);
if ($logStmt) {
    while ($r = sqlsrv_fetch_array($logStmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($r as $k => $v) { if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s'); }
        $logRows[] = $r;
    }
    sqlsrv_free_stmt($logStmt);
} else {
    error_log('my_attendance.php logSql failed: ' . print_r(sqlsrv_errors(), true));
}

/* -----------------------------------------------------------
   Attendance record map — DayCount (1 = present, 0.5 = half
   day, no row = absent) + Late1 minutes, both from the same
   generated view. This is the source of truth for the calendar
   tab's Present/Absent/Half-day status AND the Late Minutes
   stat card total — pulled in one round trip.
----------------------------------------------------------- */
$attendanceMap    = []; // 'Y-m-d' => ['daycount' => float, 'late' => int]
$attStmt = sqlsrv_query($conn, "
    SELECT ADate, DayCount, Late1
    FROM View_Attendance_Record_Daily
    WHERE EmployeeID = ? AND ADate BETWEEN ? AND ?
", [$empId, $dateFrom, $dateTo]);
if ($attStmt) {
    while ($ar = sqlsrv_fetch_array($attStmt, SQLSRV_FETCH_ASSOC)) {
        $d = $ar['ADate'];
        if ($d instanceof DateTime) $d = $d->format('Y-m-d');
        $d = substr((string)$d, 0, 10);
        $late = (int)($ar['Late1'] ?? 0);
        $attendanceMap[$d] = [
            'daycount' => (float)($ar['DayCount'] ?? 0),
            'late'     => $late,
        ];
    }
    sqlsrv_free_stmt($attStmt);
    $debugLog['calendar_attendance_map'] = $attendanceMap; // raw DayCount/Late1 per date, for troubleshooting
} else {
    $debugLog['calendar'] = sqlsrv_errors();
    error_log('my_attendance.php attStmt failed: ' . print_r(sqlsrv_errors(), true));
}

/* -----------------------------------------------------------
   Approved leave — for the calendar tab's "L" (yellow) days.
   ASSUMPTION: a leave is only "approved" once BOTH the SA and
   HR steps say Approved. If your workflow treats HR_Status as
   the sole final gate, just drop the SA_Status half of this
   WHERE clause.
----------------------------------------------------------- */
$leaveMap = []; // 'Y-m-d' => ['reason' => ..., 'halfday' => 0/1]
$leaveStmt = sqlsrv_query($conn, "
    SELECT Date_Start, Date_End, HalfDay, ReasonOfLeave
    FROM Tbl_Leave_Application
    WHERE EmployeeID = ?
      AND SA_Status = 'Approved' AND HR_Status = 'Approved'
      AND Date_Start <= ? AND Date_End >= ?
", [$empId, $dateTo, $dateFrom]);
if ($leaveStmt) {
    while ($lv = sqlsrv_fetch_array($leaveStmt, SQLSRV_FETCH_ASSOC)) {
        $ds = $lv['Date_Start']; if ($ds instanceof DateTime) $ds = $ds->format('Y-m-d');
        $de = $lv['Date_End'];   if ($de instanceof DateTime) $de = $de->format('Y-m-d');
        $ds = max(substr((string)$ds, 0, 10), $dateFrom);
        $de = min(substr((string)$de, 0, 10), $dateTo);
        $cursor = strtotime($ds);
        $end    = strtotime($de);
        while ($cursor !== false && $cursor <= $end) {
            $dKey = date('Y-m-d', $cursor);
            $leaveMap[$dKey] = [
                'reason'  => trim($lv['ReasonOfLeave'] ?? ''),
                'halfday' => (int)($lv['HalfDay'] ?? 0),
            ];
            $cursor = strtotime('+1 day', $cursor);
        }
    }
    sqlsrv_free_stmt($leaveStmt);
} else {
    error_log('my_attendance.php leaveStmt failed: ' . print_r(sqlsrv_errors(), true));
}

/* -----------------------------------------------------------
   Calendar day status resolver.
   Priority: Sunday > future(unknown) > Leave > Present/Half-day(+Late) > Absent
----------------------------------------------------------- */
function maDayStatus(string $dateStr, string $today, array $attendanceMap, array $leaveMap): array {
    $dow = (int)date('w', strtotime($dateStr)); // 0 = Sunday
    if ($dow === 0) {
        return ['status' => 'sunday'];
    }
    if ($dateStr > $today) {
        return ['status' => 'future'];
    }
    if (isset($leaveMap[$dateStr])) {
        return ['status' => 'leave', 'reason' => $leaveMap[$dateStr]['reason'], 'halfday' => $leaveMap[$dateStr]['halfday']];
    }
    if (isset($attendanceMap[$dateStr])) {
        $dc   = $attendanceMap[$dateStr]['daycount'];
        $late = $attendanceMap[$dateStr]['late'];
        if ($dc >= 1) {
            return ['status' => 'present', 'late' => $late];
        }
        if ($dc > 0) {
            return ['status' => 'halfday', 'late' => $late];
        }
        // DayCount of 0 with a row present — fall through to absent below.
    }
    return ['status' => 'absent'];
}

/* -----------------------------------------------------------
   Late Minutes stat totals — walked through the SAME
   maDayStatus() resolver the calendar tab uses, so a day only
   counts here if the calendar would actually flag it as late.
   (Previously this summed Late1 for every attendanceMap row
   regardless of status, so leave/absent days with a nonzero
   Late1 inflated the stat card total beyond what the calendar
   visually showed.)
----------------------------------------------------------- */
$lateCount        = 0;
$lateMinutesTotal = 0;
$lateCursor = strtotime($dateFrom);
$lateEnd    = min(strtotime($dateTo), strtotime($today));
while ($lateCursor !== false && $lateCursor <= $lateEnd) {
    $dKey = date('Y-m-d', $lateCursor);
    $st   = maDayStatus($dKey, $today, $attendanceMap, $leaveMap);
    if (($st['status'] === 'present' || $st['status'] === 'halfday') && !empty($st['late'])) {
        $lateCount++;
        $lateMinutesTotal += $st['late'];
    }
    $lateCursor = strtotime('+1 day', $lateCursor);
}

/* -----------------------------------------------------------
   Generated Attendance — HR-generated attendance record,
   scoped to self via EmployeeID from session. Only queried
   when its tab is active, same lazy-per-tab pattern used
   in attendance.php.
----------------------------------------------------------- */
$genRows = [];
if ($tab === 'generated') {
    $genSql = "
        SELECT
            [Adate], [Aday], [AtimeIn], [AtimeOut], [Late],
            [Job_tittle], [Department], [Position_held], [Category],
            [Remarks], [Employee_Status], [Branch], [CutOff], [SetTime], [Area]
        FROM [dbo].[View_AttendanceRecord]
        WHERE [EmployeeID] = ? AND [Adate] BETWEEN ? AND ?
        ORDER BY [Adate] DESC
    ";
    $genStmt = sqlsrv_query($conn, $genSql, [$empId, $dateFrom, $dateTo]);
    if ($genStmt) {
        while ($r = sqlsrv_fetch_array($genStmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($r as $k => $v) { if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s'); }
            $genRows[] = $r;
        }
        sqlsrv_free_stmt($genStmt);
    } else {
        error_log('my_attendance.php genSql failed: ' . print_r(sqlsrv_errors(), true));
    }
}

/* -----------------------------------------------------------
   Selfie / photo preview — CAVEAT: View_Selfie and
   View_Selfie_Offline do not expose EmployeeID, only a name
   column. Scoped by session DisplayName as the best available
   match. Ask to have an EmployeeID column added to these views
   for a hard ID match if this ever needs tightening.
----------------------------------------------------------- */
$picBase = 'http://122.52.195.3/tradewellportal/';
$empNameSafe = str_replace("'", "''", $empName);
$photos = [];

if ($empName !== '') {
    $selSql1 = "
        SELECT FullName AS Who, Area, Remarks, Action AS Direction, DateUpload AS PDate, TimeUpload AS PTime, Picture, 'Online Portal' AS Source
        FROM View_Selfie
        WHERE RTRIM(LTRIM(FullName)) = ? AND DateUpload BETWEEN ? AND ?
    ";
    $s1 = sqlsrv_query($conn, $selSql1, [$empName, $dateFrom, $dateTo]);
    if ($s1) {
        while ($r = sqlsrv_fetch_array($s1, SQLSRV_FETCH_ASSOC)) {
            if ($r['PDate'] instanceof DateTime) $r['PDate'] = $r['PDate']->format('Y-m-d');
            if ($r['PTime'] instanceof DateTime) $r['PTime'] = $r['PTime']->format('H:i:s');
            $photos[] = $r;
        }
        sqlsrv_free_stmt($s1);
    }

    $selSql2 = "
        SELECT EmployeeName AS Who, Area, Remarks, Direction, ADate AS PDate, ATime AS PTime, Picture, 'Offline Device' AS Source
        FROM View_Selfie_Offline
        WHERE RTRIM(LTRIM(EmployeeName)) = ? AND ADate BETWEEN ? AND ?
    ";
    $s2 = sqlsrv_query($conn, $selSql2, [$empName, $dateFrom, $dateTo]);
    if ($s2) {
        while ($r = sqlsrv_fetch_array($s2, SQLSRV_FETCH_ASSOC)) {
            if ($r['PDate'] instanceof DateTime) $r['PDate'] = $r['PDate']->format('Y-m-d');
            if ($r['PTime'] instanceof DateTime) $r['PTime'] = $r['PTime']->format('H:i:s');
            $photos[] = $r;
        }
        sqlsrv_free_stmt($s2);
    }

    usort($photos, fn($a, $b) => strcmp($b['PDate'] . $b['PTime'], $a['PDate'] . $a['PTime']));
}

/* -----------------------------------------------------------
   Stat totals — computed from the rows we already fetched,
   no extra round trips.
----------------------------------------------------------- */
$presentDays = count($dtrRows);
$totalHours  = 0;
foreach ($dtrRows as $r) {
    $totalHours += (float)($r['MorningAfternoonTotal'] ?? 0);
}

/* -----------------------------------------------------------
   Helpers
----------------------------------------------------------- */
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function maTime(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('h:i a', $t) : '—';
}
function maDate(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('M d, Y', $t) : '—';
}
function maDirBadge(?string $dir): string {
    $dir = strtolower(trim($dir ?? ''));
    if (str_contains($dir, 'in'))  return '<span class="ma-badge ma-badge-in">⬇ In</span>';
    if (str_contains($dir, 'out')) return '<span class="ma-badge ma-badge-out">⬆ Out</span>';
    return '<span class="ma-badge">' . h($dir) . '</span>';
}
// The view no longer supplies pre-computed hour totals — derive them
// from the raw in/out timestamps instead.

function maLateBadge($mins): string {
    $m = (float)($mins ?? 0);
    return $m > 0
        ? '<span class="ma-badge ma-badge-out">⚠ ' . h($mins) . ' min</span>'
        : '<span class="ma-badge ma-badge-in">On Time</span>';
}
function maSourceBadge(?string $src): string {
    $src = trim($src ?? '');
    $key = strtolower($src);
    $color = str_contains($key, 'device') ? '#7c3aed' : (str_contains($key, 'site') || str_contains($key, 'portal') ? '#09489b' : '#08860e');
    return '<span class="ma-badge" style="background:' . $color . '1a;color:' . $color . ';">' . h($src ?: '—') . '</span>';
}
function picUrl(string $base, ?string $pic): string {
    if (!$pic) return '';
    return $base . str_replace(' ', '%20', $pic);
}
// List of ['y'=>Y, 'm'=>m, 'label'=>'Month Year'] for every month touched by [from, to].
function maCalendarMonths(string $dateFrom, string $dateTo): array {
    $months = [];
    $cursor = strtotime(date('Y-m-01', strtotime($dateFrom)));
    $end    = strtotime(date('Y-m-01', strtotime($dateTo)));
    while ($cursor <= $end) {
        $months[] = ['y' => (int)date('Y', $cursor), 'm' => (int)date('n', $cursor), 'label' => date('F Y', $cursor)];
        $cursor = strtotime('+1 month', $cursor);
    }
    return $months;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Attendance · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.ma-wrap { max-width: 980px; margin: 0 auto; padding: 1.25rem; }

.ma-hero {
    display:flex; align-items:center; gap:1rem;
    background:linear-gradient(135deg,#eff6ff,#f0fdf4);
    border:1px solid #bfdbfe; border-radius:14px;
    padding:1.1rem 1.4rem; margin-bottom:1.25rem;
}
.ma-hero i { font-size:2.4rem; color:#2563eb; }
.ma-hero-name { font-weight:800; font-size:1.15rem; color:#1e40af; }
.ma-hero-sub { font-size:.85rem; color:#64748b; margin-top:.15rem; }

.ma-filter {
    display:flex; align-items:end; gap:.7rem; flex-wrap:wrap;
    background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:12px; padding:1rem 1.2rem; margin-bottom:1.25rem;
}
.ma-filter label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:.3rem; }
.ma-filter input[type=date] { padding:.5rem .6rem; border:1px solid var(--border,#e2e8f0); border-radius:8px; font-size:.85rem; }
.ma-filter .ma-btn { padding:.55rem 1.1rem; }

.ma-tab-nav { display:flex; gap:.4rem; margin-bottom:1.25rem; border-bottom:1px solid var(--border,#e2e8f0); }
.ma-tab-nav a {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.7rem 1.1rem; font-size:.86rem; font-weight:600;
    color:#64748b; text-decoration:none; border-bottom:2px solid transparent;
}
.ma-tab-nav a:hover { color:#2563eb; }
.ma-tab-nav a.active { color:#2563eb; border-bottom-color:#2563eb; }

.ma-stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.9rem; margin-bottom:1.25rem; }
.ma-stat-card {
    background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:12px; padding:1rem 1.2rem;
    display:flex; align-items:center; gap:.8rem;
}
.ma-stat-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.ma-stat-val { font-size:1.4rem; font-weight:800; color:#0f172a; line-height:1; }
.ma-stat-lbl { font-size:.75rem; color:#64748b; margin-top:.25rem; }

.ma-card { background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0); border-radius:12px; margin-bottom:1.25rem; overflow:hidden; }
.ma-card-head { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.2rem; border-bottom:1px solid var(--border,#e2e8f0); font-weight:700; }
.ma-card-head .ma-count { font-size:.72rem; font-weight:700; background:#f1f5f9; color:#64748b; padding:2px 9px; border-radius:999px; margin-left:.5rem; }

.ma-table { width:100%; border-collapse:collapse; font-size:.86rem; }
.ma-table thead th { background:#f8fafc; color:#64748b; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:8px 12px; border-bottom:2px solid var(--border,#e2e8f0); text-align:left; white-space:nowrap; }
.ma-table tbody td { padding:8px 12px; border-bottom:1px solid var(--border,#e2e8f0); }
.ma-table tbody tr:last-child td { border-bottom:none; }
.ma-time { font-family:'JetBrains Mono',monospace; font-weight:600; }

.ma-badge { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.75rem; font-weight:600; background:#f1f5f9; color:#64748b; }
.ma-badge-in { background:#dbeafe; color:#1d4ed8; }
.ma-badge-out { background:#fee2e2; color:#dc2626; }

.ma-empty { padding:2.5rem 1rem; text-align:center; color:#94a3b8; }
.ma-empty i { font-size:2rem; display:block; margin-bottom:.5rem; opacity:.4; }

.ma-photo-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.9rem; padding:1.1rem 1.2rem; }
.ma-photo { border:1px solid var(--border,#e2e8f0); border-radius:10px; overflow:hidden; background:#f8fafc; }
.ma-photo img { width:100%; height:120px; object-fit:cover; display:block; }
.ma-photo-meta { padding:.5rem .6rem; font-size:.72rem; color:#64748b; }
.ma-photo-meta .ma-photo-date { font-weight:700; color:#1e293b; }

.ma-btn {
    display:inline-flex; align-items:center; gap:.4rem;
    background:#2563eb; color:#fff; border:none; border-radius:8px;
    padding:.5rem .9rem; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none;
}
.ma-btn:hover { background:#1d4ed8; color:#fff; }
.ma-btn-ghost { background:#f1f5f9; color:#334155; }
.ma-btn-ghost:hover { background:#e2e8f0; color:#334155; }

.ma-cal-legend { display:flex; flex-wrap:wrap; gap:1.1rem; padding:.9rem 1.2rem; font-size:.78rem; color:#64748b; border-bottom:1px solid var(--border,#e2e8f0); }
.ma-cal-legend span { display:inline-flex; align-items:center; gap:.35rem; }
.ma-dot { width:10px; height:10px; border-radius:3px; display:inline-block; }
.ma-dot-present { background:#16a34a; }
.ma-dot-halfday { background:#0ea5e9; }
.ma-dot-absent  { background:#dc2626; }
.ma-dot-leave   { background:#eab308; }
.ma-dot-sunday  { background:#cbd5e1; }

.ma-cal-month { padding:1.1rem 1.2rem; border-bottom:1px solid var(--border,#e2e8f0); }
.ma-cal-month:last-child { border-bottom:none; }
.ma-cal-month-title { font-weight:800; color:#0f172a; margin-bottom:.7rem; font-size:.95rem; }
.ma-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
.ma-cal-dow { text-align:center; font-size:.68rem; font-weight:700; text-transform:uppercase; color:#94a3b8; padding-bottom:2px; }

.ma-cal-cell {
    position:relative; min-height:64px; border-radius:8px; padding:5px 6px;
    border:1px solid var(--border,#e2e8f0); background:#fff;
    display:flex; flex-direction:column; gap:2px;
}
.ma-cal-daynum { font-size:.74rem; font-weight:700; color:#334155; }
.ma-cal-pad { visibility:hidden; }
.ma-cal-unfiltered { background:#f8fafc; opacity:.45; }
.ma-cal-unfiltered .ma-cal-daynum { color:#cbd5e1; }

.ma-cal-future { background:#fff; }
.ma-cal-sunday { background:#f1f5f9; }
.ma-cal-sunday .ma-cal-daynum { color:#94a3b8; }
.ma-cal-present { background:#f0fdf4; border-color:#bbf7d0; }
.ma-cal-halfday { background:#f0f9ff; border-color:#bae6fd; }
.ma-cal-absent  { background:#fef2f2; border-color:#fecaca; }
.ma-cal-leave   { background:#fefce8; border-color:#fde68a; }

.ma-cal-mark { font-size:.72rem; font-weight:800; display:flex; align-items:center; gap:3px; }
.ma-cal-mark-present { color:#16a34a; }
.ma-cal-mark-halfday { color:#0ea5e9; }
.ma-cal-mark-absent  { color:#dc2626; }
.ma-cal-mark-leave   { color:#a16207; }
.ma-cal-mark-sunday  { color:#94a3b8; }
.ma-cal-late { font-size:.63rem; font-weight:700; color:#dc2626; line-height:1.2; }

@media (max-width:640px) {
    .ma-cal-cell { min-height:48px; padding:3px 4px; }
    .ma-cal-mark { font-size:.6rem; }
    .ma-cal-late { font-size:.55rem; }
}

@media print {
    .topbar, .ma-filter, .ma-btn { display:none !important; }
    .ma-wrap { max-width:100%; padding:0; }
}
</style>
</head>
<body>
<?php if (!empty($debugLog)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r($debugLog, true)) ?>
-->
<?php endif; ?>

<?php $topbar_page = 'my_attendance'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="ma-wrap">

  <div class="ma-hero">
    <i class="bi bi-person-badge"></i>
    <div>
      <div class="ma-hero-name"><?= h($empName ?: 'My Attendance') ?></div>
      <div class="ma-hero-sub">Employee ID: <?= h($empId) ?><?= $dept !== '' ? ' · ' . h($dept) : '' ?> · <?= h(maDate($dateFrom)) ?> → <?= h(maDate($dateTo)) ?></div>
    </div>
  </div>

  <!-- Date range filter — the ONLY filter on this page -->
  <form method="GET" class="ma-filter">
    <div>
      <label>From</label>
      <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
    </div>
    <div>
      <label>To</label>
      <input type="date" name="date_to" value="<?= h($dateTo) ?>">
    </div>
    <button type="submit" class="ma-btn"><i class="bi bi-funnel-fill"></i> Filter</button>
    <a href="?" class="ma-btn ma-btn-ghost"><i class="bi bi-x-circle"></i> Reset</a>
    <button type="button" class="ma-btn ma-btn-ghost" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
  </form>

  <!-- Tab Nav -->
  <div class="ma-tab-nav">
    <a href="?<?= h(http_build_query(array_merge($_GET, ['tab' => 'overview']))) ?>" class="<?= $tab === 'overview' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i> Overview
    </a>
    <a href="?<?= h(http_build_query(array_merge($_GET, ['tab' => 'generated']))) ?>" class="<?= $tab === 'generated' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-text-fill"></i> Generated Attendance
    </a>
    <a href="?<?= h(http_build_query(array_merge($_GET, ['tab' => 'calendar']))) ?>" class="<?= $tab === 'calendar' ? 'active' : '' ?>">
      <i class="bi bi-calendar3"></i> Calendar
    </a>
  </div>

  <?php if ($tab === 'overview'): ?>

  <!-- Stat cards -->
  <div class="ma-stat-row">
    <div class="ma-stat-card">
      <div class="ma-stat-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="bi bi-calendar-check-fill"></i></div>
      <div><div class="ma-stat-val"><?= number_format($presentDays) ?></div><div class="ma-stat-lbl">Days Present</div></div>
    </div>
    <div class="ma-stat-card">
      <div class="ma-stat-icon" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-clock-history"></i></div>
      <div><div class="ma-stat-val"><?= number_format($totalHours, 1) ?></div><div class="ma-stat-lbl">Total Hours</div></div>
    </div>
    <div class="ma-stat-card">
      <div class="ma-stat-icon" style="background:#fef9c3;color:#a16207;"><i class="bi bi-alarm-fill"></i></div>
      <div>
        <div class="ma-stat-val"><?= number_format($lateMinutesTotal) ?> <span style="font-size:.65em;font-weight:700;color:#94a3b8;">mins</span></div>
        <div class="ma-stat-lbl">Late Minutes <?= $lateCount > 0 ? '(' . number_format($lateCount) . ' day' . ($lateCount !== 1 ? 's' : '') . ')' : '' ?></div>
      </div>
    </div>
  </div>

  <!-- Daily Time Record -->
  <div class="ma-card">
    <div class="ma-card-head">
      <span>🕐 Daily Time Record<span class="ma-count"><?= count($dtrRows) ?> day<?= count($dtrRows) !== 1 ? 's' : '' ?></span></span>
    </div>
    <?php if (empty($dtrRows)): ?>
      <div class="ma-empty"><i class="bi bi-inbox"></i>No attendance records for this date range.</div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="ma-table">
          <thead>
            <tr>
              <th>Date</th><th>Sched. AM In</th><th>AM In</th><th>AM Out</th><th>AM Hours</th><th>AM Late</th>
              <th>Sched. PM In</th><th>PM In</th><th>PM Out</th><th>PM Hours</th><th>PM Late</th>
              <th>Daily Total</th><th>Total Late</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dtrRows as $r):
    $amHrs    = ($r['MorningTotalHours'] ?? '') !== '' ? (float)$r['MorningTotalHours'] : null;
    $pmHrs    = ($r['AfternoonTotalHours'] ?? '') !== '' ? (float)$r['AfternoonTotalHours'] : null;
    $dayTotal = ($r['MorningAfternoonTotal'] ?? '') !== '' ? (float)$r['MorningAfternoonTotal'] : null;
?>
<tr>
  <td><?= h(maDate($r['ADate'])) ?></td>
  <td class="ma-time"><?= h(maTime($r['TimeIn'])) ?></td>
  <td class="ma-time"><?= h(maTime($r['MorningIn'])) ?></td>
  <td class="ma-time"><?= h(maTime($r['MorningOut'])) ?></td>
  <td class="ma-time"><?= $amHrs !== null ? number_format($amHrs, 2) . ' hrs' : '—' ?></td>
  <td><?= maLateBadge($r['AMLate'] ?? 0) ?></td>
  <td class="ma-time"><?= h(maTime($r['TimeInPM'])) ?></td>
  <td class="ma-time"><?= h(maTime($r['AfternoonIn'])) ?></td>
  <td class="ma-time"><?= h(maTime($r['AfternoonOut'])) ?></td>
  <td class="ma-time"><?= $pmHrs !== null ? number_format($pmHrs, 2) . ' hrs' : '—' ?></td>
  <td><?= maLateBadge($r['PMLate'] ?? 0) ?></td>
  <td class="ma-time"><?= $dayTotal !== null ? number_format($dayTotal, 2) . ' hrs' : '—' ?></td>
  <td><?= maLateBadge($r['Late1'] ?? 0) ?></td>
</tr>
<?php endforeach; ?>

          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent Activity Log -->
  <div class="ma-card">
    <div class="ma-card-head">
      <span>🔗 Recent Activity<span class="ma-count"><?= count($logRows) ?> entr<?= count($logRows) !== 1 ? 'ies' : 'y' ?></span></span>
    </div>
    <?php if (empty($logRows)): ?>
      <div class="ma-empty"><i class="bi bi-inbox"></i>No activity log entries for this date range.</div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="ma-table">
          <thead>
            <tr><th>Date</th><th>Time</th><th>Area</th><th>Category</th><th>Direction</th><th>Source</th></tr>
          </thead>
          <tbody>
            <?php foreach ($logRows as $r): ?>
            <tr>
              <td><?= h(maDate($r['ADate'])) ?></td>
              <td class="ma-time"><?= h(maTime($r['ATime'])) ?></td>
              <td><?= h(trim($r['Area'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Category'] ?? '')) ?: '—' ?></td>
              <td><?= maDirBadge($r['Direction'] ?? '') ?></td>
              <td><?= maSourceBadge($r['DataFrom'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Selfie / Photo Preview -->
  <div class="ma-card">
    <div class="ma-card-head">
      <span>📷 Photo Check-ins<span class="ma-count"><?= count($photos) ?> photo<?= count($photos) !== 1 ? 's' : '' ?></span></span>
    </div>
    <?php if (empty($photos)): ?>
      <div class="ma-empty"><i class="bi bi-camera"></i>No photo check-ins for this date range.</div>
    <?php else: ?>
      <div class="ma-photo-grid">
        <?php foreach ($photos as $p):
          $url = picUrl($picBase, $p['Picture'] ?? null);
        ?>
        <div class="ma-photo">
          <?php if ($url): ?>
            <img src="<?= h($url) ?>" alt="Check-in photo" loading="lazy" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="ma-photo-meta">
            <div class="ma-photo-date"><?= h(maDate($p['PDate'])) ?> · <?= h(maTime($p['PTime'])) ?></div>
            <div><?= maDirBadge($p['Direction'] ?? '') ?> · <?= h($p['Source']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php endif; // end overview tab ?>

  <?php if ($tab === 'generated'): ?>

  <!-- Generated Attendance -->
  <div class="ma-card">
    <div class="ma-card-head">
      <span>📋 Generated Attendance<span class="ma-count"><?= count($genRows) ?> record<?= count($genRows) !== 1 ? 's' : '' ?></span></span>
    </div>
    <?php if (empty($genRows)): ?>
      <div class="ma-empty"><i class="bi bi-inbox"></i>No generated attendance records for this date range.</div>
    <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="ma-table">
          <thead>
            <tr>
              <th>Date</th><th>Day</th><th>Time In</th><th>Time Out</th><th>Late</th>
              <th>Department</th><th>Branch</th><th>Area</th><th>Category</th>
              <th>Position Held</th><th>Job Title</th><th>Employee Status</th>
              <th>Cut Off</th><th>Set Time</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($genRows as $r): ?>
            <tr>
              <td><?= h(maDate($r['Adate'])) ?></td>
              <td><?= h(trim($r['Aday'] ?? '')) ?: '—' ?></td>
              <td class="ma-time"><?= h(maTime($r['AtimeIn'])) ?></td>
              <td class="ma-time"><?= h(maTime($r['AtimeOut'])) ?></td>
              <td><?= ((float)($r['Late'] ?? 0)) > 0 ? '<span class="ma-badge ma-badge-out">⚠ ' . h($r['Late']) . ' min</span>' : '<span class="ma-badge ma-badge-in">On Time</span>' ?></td>
              <td><?= h(trim($r['Department'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Branch'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Area'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Category'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Position_held'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Job_tittle'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['Employee_Status'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['CutOff'] ?? '')) ?: '—' ?></td>
              <td><?= h(trim($r['SetTime'] ?? '')) ?: '—' ?></td>
              <td style="color:var(--text-muted,#64748b);"><?= h(trim($r['Remarks'] ?? '')) ?: '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php endif; // end generated tab ?>

  <?php if ($tab === 'calendar'): ?>

  <!-- Calendar -->
  <div class="ma-card">
    <div class="ma-card-head">
      <span>📅 Attendance Calendar</span>
    </div>
    <div class="ma-cal-legend">
      <span><i class="ma-dot ma-dot-present"></i> Present</span>
      <span><i class="ma-dot ma-dot-halfday"></i> Half Day</span>
      <span><i class="ma-dot ma-dot-absent"></i> Absent</span>
      <span><i class="ma-dot ma-dot-leave"></i> Leave</span>
      <span><i class="ma-dot ma-dot-sunday"></i> Sunday</span>
      <span><i class="bi bi-alarm-fill" style="color:#dc2626;"></i> Late (mins shown)</span>
    </div>

    <?php foreach (maCalendarMonths($dateFrom, $dateTo) as $mo):
        $firstOfMo   = sprintf('%04d-%02d-01', $mo['y'], $mo['m']);
        $daysInMo    = (int)date('t', strtotime($firstOfMo));
        $startDow    = (int)date('w', strtotime($firstOfMo)); // 0=Sun
    ?>
    <div class="ma-cal-month">
      <div class="ma-cal-month-title"><?= h($mo['label']) ?></div>
      <div class="ma-cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dowLbl): ?>
          <div class="ma-cal-dow"><?= $dowLbl ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $startDow; $i++): ?>
          <div class="ma-cal-cell ma-cal-pad"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMo; $day++):
            $dateStr = sprintf('%04d-%02d-%02d', $mo['y'], $mo['m'], $day);
            $inRange = ($dateStr >= $dateFrom && $dateStr <= $dateTo);
            if (!$inRange) {
                echo '<div class="ma-cal-cell ma-cal-unfiltered"><div class="ma-cal-daynum">' . $day . '</div></div>';
                continue;
            }
            $st = maDayStatus($dateStr, $today, $attendanceMap, $leaveMap);
            $cls = 'ma-cal-cell ma-cal-' . $st['status'];
            $title = date('M j, Y', strtotime($dateStr));
        ?>
          <div class="<?= $cls ?>" title="<?= h($title . ' — ' . ucfirst($st['status'])) ?>">
            <div class="ma-cal-daynum"><?= $day ?></div>
            <?php if ($st['status'] === 'present'): ?>
              <div class="ma-cal-mark ma-cal-mark-present"><i class="bi bi-check-lg"></i></div>
              <?php if (!empty($st['late'])): ?>
                <div class="ma-cal-late">⏰ <?= (int)$st['late'] ?>m late</div>
              <?php endif; ?>
            <?php elseif ($st['status'] === 'halfday'): ?>
              <div class="ma-cal-mark ma-cal-mark-halfday"><i class="bi bi-check-lg"></i> ½</div>
              <?php if (!empty($st['late'])): ?>
                <div class="ma-cal-late">⏰ <?= (int)$st['late'] ?>m late</div>
              <?php endif; ?>
            <?php elseif ($st['status'] === 'absent'): ?>
              <div class="ma-cal-mark ma-cal-mark-absent">A</div>
            <?php elseif ($st['status'] === 'leave'): ?>
              <div class="ma-cal-mark ma-cal-mark-leave">L</div>
              <?php if (!empty($st['halfday'])): ?>
                <div class="ma-cal-late">½ day</div>
              <?php endif; ?>
            <?php elseif ($st['status'] === 'sunday'): ?>
              <div class="ma-cal-mark ma-cal-mark-sunday">—</div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; // end calendar tab ?>

</div>

</body>
</html>