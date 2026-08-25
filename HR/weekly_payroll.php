<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'weekly_payroll');

// ── Session context — same dept-scoping pattern as payroll_cutoff.php ──
$sessionDept = trim($_SESSION['Department'] ?? '');
$empId       = $_SESSION['EmployeeID'] ?? '';
$userLevel   = $_SESSION['userlevel']  ?? '';
$isHR        = ($userLevel === 'Admin' || $userLevel === 'HR');

$allDeptSentinels  = ['', 'all', 'all department', 'all departments', '*'];
$isAllDeptSession  = in_array(strtolower($sessionDept), $allDeptSentinels, true);
$canFilterDept     = $isHR || $isAllDeptSession;

if ($canFilterDept) {
    $filterDept = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : '';
} else {
    $filterDept = $sessionDept;
}

// ── Filters ──────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

$filterCategory = isset($_GET['category']) && $_GET['category'] !== '' ? trim($_GET['category']) : '';
$filterPGroup   = isset($_GET['pgroup'])   && $_GET['pgroup']   !== '' ? trim($_GET['pgroup'])   : '';
$filterYear     = isset($_GET['year'])     && $_GET['year']     !== '' ? trim($_GET['year'])     : date('Y');
$filterMonth    = isset($_GET['month'])    && $_GET['month']    !== '' ? trim($_GET['month'])    : (isset($_GET['month']) ? '' : date('n'));
$searchName     = isset($_GET['q'])        && $_GET['q']        !== '' ? trim($_GET['q'])        : '';

$filterDeptSafe     = str_replace("'", "''", $filterDept);
$filterCategorySafe = str_replace("'", "''", $filterCategory);
$filterPGroupSafe   = str_replace("'", "''", $filterPGroup);
$filterYearSafe     = str_replace("'", "''", $filterYear);
$filterMonthSafe    = str_replace("'", "''", $filterMonth);
$searchNameSafe     = str_replace("'", "''", $searchName);

// ── Dept WHERE clause (mirrors payroll_cutoff.php's dc()) ─────
function dc(string $deptSafe, string $col = 'Department'): string {
    return $deptSafe !== '' ? "AND RTRIM($col) = '$deptSafe'" : '';
}
function eqOrAll(string $valSafe, string $col): string {
    return $valSafe !== '' ? "AND RTRIM($col) = '$valSafe'" : '';
}
function csEq(string $dateSafe): string {
    return $dateSafe !== '' ? "AND CAST(CutoffStart AS DATE) = '$dateSafe'" : '';
}

// ── Dropdown option lists — dept-scoped for locked-department users ──
// Sourced from View_Payroll_Weekly_DayCount (canonical dept/category/
// pgroup list); the Late view shares the same dimension columns.
$deptList = [];
if ($canFilterDept) {
    $dStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Department) AS Department FROM View_Payroll_Weekly_DayCount WHERE Department IS NOT NULL AND Department <> '' ORDER BY Department");
    if ($dStmt) { while ($r = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) { $deptList[] = $r['Department']; } sqlsrv_free_stmt($dStmt); }
}

// ── Single combined dropdown-source query — replaces 4 separate
// DISTINCT scans (category/pgroup/year/week) against the view with
// ONE query. Category/PGroup/Year lists stay dept-scoped only (same
// behavior as before); the Week list additionally narrows by
// Category/PGroup/Year/Month, done in PHP below from this same
// result set instead of hitting the DB a second time.
$dropdownRows = [];
$ddStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Category) AS Category, RTRIM(PayrollGroup) AS PayrollGroup, CutoffStart, PayrollYear, RTRIM(MonthName) AS MonthName, RTRIM(CutOff) AS CutOff
    FROM View_Payroll_Weekly_DayCount
    WHERE CutoffStart IS NOT NULL " . dc($filterDeptSafe));
if ($ddStmt) {
    while ($r = sqlsrv_fetch_array($ddStmt, SQLSRV_FETCH_ASSOC)) {
        $cs = $r['CutoffStart'];
        $dropdownRows[] = [
            'Category'     => $r['Category'],
            'PayrollGroup' => $r['PayrollGroup'],
            'CutoffStart'  => ($cs instanceof DateTime) ? $cs->format('Y-m-d') : (string)$cs,
            'PayrollYear'  => $r['PayrollYear'],
            'MonthName'    => $r['MonthName'],
            'CutOff'       => $r['CutOff'],
        ];
    }
    sqlsrv_free_stmt($ddStmt);
}

$categoryList = [];
foreach ($dropdownRows as $r) {
    if ($r['Category'] !== null && $r['Category'] !== '' && !in_array($r['Category'], $categoryList, true)) {
        $categoryList[] = $r['Category'];
    }
}
sort($categoryList);

$pGroupList = [];
foreach ($dropdownRows as $r) {
    if ($r['PayrollGroup'] !== null && $r['PayrollGroup'] !== '' && !in_array($r['PayrollGroup'], $pGroupList, true)) {
        $pGroupList[] = $r['PayrollGroup'];
    }
}
sort($pGroupList);

// Year list — uses the view's own PayrollYear column directly (added
// alongside Day7/MonthName) instead of parsing CutoffStart's calendar
// year, since a cutoff's CutoffStart date can fall in a different
// calendar month/year than the payroll period it's actually reported under.
$yearList = [];
foreach ($dropdownRows as $r) {
    $y = (int) ($r['PayrollYear'] ?? 0);
    if ($y > 0 && !in_array($y, $yearList, true)) { $yearList[] = $y; }
}
rsort($yearList);

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// ── Week lists — GROUP-SCOPED (Mon-Sat vs Sat-Fri), narrowed from
// $dropdownRows by Category/PGroup/Year/Month, then split by the same
// weekday rule used later to split the fetched rows (CutoffStart on a
// Saturday => Sat-Fri group, everything else => Mon-Sat group). Actual
// filtering happens client-side (MS_WEEKS/SF_WEEKS + weekFilterChange()
// in the script below) so it can never over-narrow the other tab.
// Month name resolved from the numeric Month filter, for matching
// against the view's MonthName column (case-insensitive, trimmed).
$filterMonthName = ($filterMonth !== '') ? ($monthNames[(int)$filterMonth] ?? '') : '';

function buildWeekList(array $rows, string $group, string $cat, string $pg, string $yr, string $monthName): array {
    $dates = [];
    foreach ($rows as $r) {
        if ($cat !== '' && strcasecmp($r['Category'] ?? '', $cat) !== 0) { continue; }
        if ($pg  !== '' && strcasecmp($r['PayrollGroup'] ?? '', $pg) !== 0) { continue; }
        // Filtered against the view's own PayrollYear/MonthName rather
        // than CutoffStart's calendar date — a cutoff's CutoffStart can
        // land in a different calendar month/year than the payroll
        // period it's actually reported under (e.g. a Sat-Fri cutoff
        // starting the last Saturday of July but belonging to August).
        if ($yr !== '' && (string)($r['PayrollYear'] ?? '') !== (string)$yr) { continue; }
        if ($monthName !== '' && strcasecmp(trim((string)($r['MonthName'] ?? '')), $monthName) !== 0) { continue; }
        $rowGroup = (trim((string)($r['CutOff'] ?? '')) === 'Sat-Fri') ? 'sf' : 'ms'; // same rule as weeklyGroup()
        if ($rowGroup !== $group) { continue; }
        $cs = $r['CutoffStart'];
        $ts = strtotime($cs);
        if ($ts === false) { continue; }
        if (!in_array($cs, $dates, true)) { $dates[] = $cs; }
    }
    sort($dates);

    $list = [];
    $idx = 1;
    foreach ($dates as $dateStr) {
        $ts = strtotime($dateStr);
        if ($ts === false) { continue; }
        $isSat = ((int)date('N', $ts) === 6);
        $endTs = $isSat ? strtotime('+6 days', $ts) : strtotime('+5 days', $ts);
        $label = 'Week ' . $idx . ' (' . date('M j', $ts) . '–' . date('j', $endTs) . ')';
        $list[] = ['idx' => $idx, 'date' => $dateStr, 'label' => $label];
        $idx++;
    }
    return $list;
}

$msWeekList = buildWeekList($dropdownRows, 'ms', $filterCategory, $filterPGroup, $filterYear, $filterMonthName);
$sfWeekList = buildWeekList($dropdownRows, 'sf', $filterCategory, $filterPGroup, $filterYear, $filterMonthName);

// ── Main queries ─────────────────────────────────────────
// Attendance = View_Payroll_Weekly_DayCount, Lates = View_Payroll_Weekly_Late.
// Both filtered identically; Year/Month filters apply against CutoffStart
// since neither view carries a PayrollYear/PayrollMonth column.
$debugLog = [];

// Shared WHERE builder — View_Payroll_Weekly_Late now carries the same
// PayrollYear/MonthName/Day7 columns as View_Payroll_Weekly_DayCount
// (confirmed), so both Attendance and Lates use identical filter logic.
// Filters by the view's own PayrollYear/MonthName rather than
// YEAR(CutoffStart)/MONTH(CutoffStart): a cutoff's CutoffStart date can
// fall in the PREVIOUS calendar month (e.g. CutoffStart = July 25 for a
// cutoff whose payroll period is August) — filtering by the raw
// calendar date silently excluded those rows whenever a Month filter
// was applied.
function weeklyWhere(string $deptSafe, string $categorySafe, string $pgroupSafe, string $yearSafe, string $monthNameSafe, string $searchSafe): string {
    return "WHERE 1=1
          " . dc($deptSafe) . "
          " . eqOrAll($categorySafe, 'Category') . "
          " . eqOrAll($pgroupSafe, 'PayrollGroup') . "
          " . ($yearSafe      !== '' ? "AND PayrollYear = '$yearSafe'" : '') . "
          " . ($monthNameSafe !== '' ? "AND RTRIM(MonthName) = '$monthNameSafe'" : '') . "
          " . ($searchSafe    !== '' ? "AND EmployeeName LIKE '%$searchSafe%'" : '');
}
$filterMonthNameSafe = str_replace("'", "''", $filterMonthName);
// NOTE: Week filtering happens client-side, per group (Mon-Sat vs
// Sat-Fri), so picking a week on one tab can never wipe out the other
// tab's rows. See MS_WEEKS / SF_WEEKS / weekFilterChange() below.
$whereClause = weeklyWhere($filterDeptSafe, $filterCategorySafe, $filterPGroupSafe, $filterYearSafe, $filterMonthNameSafe, $searchNameSafe);

$sqlAtt = "
    SELECT Department, Category, PayrollGroup, EmployeeName, CutoffStart, CutOff,
           Day1, Day2, Day3, Day4, Day5, Day6, Day7, Present, Absent, HalfDay, TotalDays
    FROM View_Payroll_Weekly_DayCount
    $whereClause
    ORDER BY CutoffStart DESC, EmployeeName ASC
";
$stmtAtt = sqlsrv_query($conn, $sqlAtt);
if ($stmtAtt === false) { $debugLog['weekly_payroll_att'] = sqlsrv_errors(); }

$sqlLate = "
    SELECT Department, Category, PayrollGroup, EmployeeName, CutoffStart, CutOff, MonthName, Week,
           Day1, Day2, Day3, Day4, Day5, Day6, Day7, Present, Absent, HalfDay, TotalDays, TotalLate
    FROM View_Payroll_Weekly_Late
    $whereClause
    ORDER BY CutoffStart DESC, EmployeeName ASC
";
$stmtLate = sqlsrv_query($conn, $sqlLate);
if ($stmtLate === false) { $debugLog['weekly_payroll_late'] = sqlsrv_errors(); }

function fetchRows($stmt): array {
    $rows = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($r as $k => $v) {
                if ($v instanceof DateTime) { $r[$k] = $v->format('Y-m-d'); }
            }
            $rows[] = $r;
        }
        sqlsrv_free_stmt($stmt);
    }
    return $rows;
}
$attRows  = fetchRows($stmtAtt);
$lateRows = fetchRows($stmtLate);

// ── Split each view's rows into Mon-Sat / Sat-Fri groups ──────────
// Grouping is keyed off the view's own [CutOff] column, which holds
// the exact literal 'Mon-Sat' or 'Sat-Fri' (confirmed via
// `SELECT DISTINCT RTRIM(CutOff) FROM View_Payroll_Weekly_DayCount`).
// This replaces the earlier CutoffStart-weekday guess, which silently
// misclassified/dropped rows whenever a cutoff's start date landed on
// an unexpected weekday relative to its actual payroll period.
// Anything that doesn't match either literal falls back to Mon-Sat
// rather than being silently dropped.
function weeklyGroup(string $cutOff): string {
    return (trim($cutOff) === 'Sat-Fri') ? 'sf' : 'ms';
}

function splitWeekly(array $rows): array {
    $out = ['ms' => [], 'sf' => []];
    foreach ($rows as $r) {
        $out[weeklyGroup($r['CutOff'] ?? '')][] = $r;
    }
    return $out;
}
$attSplit  = splitWeekly($attRows);
$lateSplit = splitWeekly($lateRows);

$jsRows = json_encode([
    'msAtt'  => $attSplit['ms'],
    'sfAtt'  => $attSplit['sf'],
    'msLate' => $lateSplit['ms'],
    'sfLate' => $lateSplit['sf'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

function deptLabel(string $d): string {
    return $d !== '' ? htmlspecialchars($d) : 'All Departments';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weekly Payroll — HR · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.wp-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.wp-table thead th {
    background:var(--surface-raised, #f1f5f9);
    color:var(--text-muted, #64748b);
    font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
    padding:8px 10px; border-bottom:2px solid var(--border, #e2e8f0);
    white-space:nowrap; text-align:left;
}
.wp-table tbody tr { border-bottom:1px solid var(--border, #e2e8f0); transition:background .12s; }
.wp-table tbody tr:hover { background:var(--surface-hover, #f8fafc); }
.wp-table tbody td { padding:6px 10px; font-size:.88rem; color:var(--text, #1e293b); vertical-align:middle; line-height:1.3; }

.wp-total-late { font-family:'JetBrains Mono',monospace; font-weight:700; }
.wp-total-late.zero { color:#94a3b8; font-weight:400; }
.wp-total-late.warn { color:#ca8a04; }
.wp-total-late.danger { color:#dc2626; }

.att-pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-top:1px solid var(--border,#e2e8f0); gap:12px; flex-wrap:wrap; }
.att-pagination .att-page-info { font-size:.88rem; color:var(--text-muted,#64748b); }
.att-page-btns { display:flex; gap:4px; align-items:center; }
.att-page-btns button { border:1px solid var(--border,#e2e8f0); background:var(--surface,#fff); color:var(--text,#1e293b); border-radius:6px; padding:5px 11px; font-size:.88rem; font-weight:600; cursor:pointer; transition:background .12s, color .12s; min-width:34px; }
.att-page-btns button:hover:not(:disabled) { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button.active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button:disabled { opacity:.35; cursor:not-allowed; }
.att-page-btns .ellipsis { padding:5px 4px; font-size:.88rem; color:var(--text-muted,#64748b); }

/* ── Weekly Payroll tabs ─────────────────────────────────── */
.wp-tabs { display:flex; gap:6px; border-bottom:1px solid var(--border,#e2e8f0); padding:0 16px; flex-wrap:wrap; }
.wp-tab-btn {
    border:none; background:transparent; padding:.7rem 1.1rem; font-size:.86rem; font-weight:700;
    color:var(--text-muted,#64748b); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px;
}
.wp-tab-btn:hover { color:var(--text,#1e293b); }
.wp-tab-btn.active { color:var(--primary,#2563eb); border-bottom-color:var(--primary,#2563eb); }
.wp-tab-panel { display:none; }
.wp-tab-panel.active { display:block; }

/* ── Day grid cells ──────────────────────────────────────── */
.wp-table td.wp-day { text-align:center; font-family:'JetBrains Mono',monospace; }
.wp-table td.wp-day.zero { color:#cbd5e1; }
.wp-table td.wp-day.late { color:#dc2626; font-weight:700; }
.wp-table td.wp-day.absent { color:#b91c1c; font-weight:800; background:#fee2e2; }
.wp-table td.wp-day.halfday { color:#b45309; font-weight:800; background:#fef3c7; }
.wp-table td.wp-day.present { color:#15803d; font-weight:800; background:#dcfce7; }

.wp-legend { display:flex; gap:16px; flex-wrap:wrap; align-items:center; padding:8px 16px; font-size:.78rem; color:var(--text-muted,#64748b); border-bottom:1px solid var(--border,#e2e8f0); }
.wp-legend-item { display:flex; align-items:center; gap:5px; }
.wp-legend-swatch { display:inline-block; width:11px; height:11px; border-radius:3px; }
.wp-legend-swatch.late { background:#fecaca; }
.wp-legend-swatch.absent { background:#fee2e2; border:1px solid #b91c1c; }
.wp-legend-swatch.halfday { background:#fef3c7; border:1px solid #b45309; }
.wp-legend-swatch.present { background:#dcfce7; border:1px solid #15803d; }
</style>
</head>
<body>
<?php
// TEMP DIAGNOSTIC — remove once Sat-Fri empty-tab issue is confirmed fixed.
$diag = [
    'attRows_count'    => count($attRows),
    'attRows_cutoffs'  => array_values(array_unique(array_map(fn($r) => $r['CutoffStart'] ?? null, $attRows))),
    'attRows_weekdays' => array_map(function($cs) {
        $ts = strtotime($cs);
        return $cs . ' => ' . ($ts ? date('D (N)', $ts) : 'invalid');
    }, array_values(array_unique(array_map(fn($r) => $r['CutoffStart'] ?? '', $attRows)))),
    'attSplit_ms_count' => count($attSplit['ms']),
    'attSplit_sf_count' => count($attSplit['sf']),
    'sfWeekList'        => $sfWeekList,
];
?>
<!-- TWM DIAGNOSTIC (temporary — remove once Sat-Fri issue is confirmed fixed):
<?= htmlspecialchars(print_r($diag, true)) ?>
-->
<?php if (!empty($debugLog)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r($debugLog, true)) ?>
-->
<?php endif; ?>

<?php
$topbar_page = 'hr_attendance';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'weekly_payroll';
require_once __DIR__ . '/hr_nav.php';
?>

<!-- ── Page Header ───────────────────────────────────────── -->
<div class="hr-page-header">
  <div>
    <div class="hr-page-title">📅 <span style="color:#2563eb;">Weekly Payroll</span></div>
    <div class="hr-page-badge">
      📁 <?= deptLabel($filterDept) ?>
      <?php if ($filterYear !== ''): ?> · <?= htmlspecialchars($filterYear) ?><?php endif; ?>
      <?php if ($filterMonth !== ''): ?> · <?= htmlspecialchars($monthNames[(int)$filterMonth] ?? $filterMonth) ?><?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Filter Bar ────────────────────────────────────────── -->
<form method="GET" action="">
  <div class="hr-filter-bar">
    <?php if ($canFilterDept): ?>
    <div class="hr-filter-group">
      <label>Department</label>
      <select name="dept" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:160px;">
        <option value="">— All Departments —</option>
        <?php foreach ($deptList as $d): ?>
        <option value="<?= htmlspecialchars($d) ?>" <?= ($filterDept === $d) ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
    <div class="hr-filter-group">
      <label>Department</label>
      <div style="display:flex;align-items:center;height:2.1rem;padding:0 .6rem;font-size:.82rem;color:var(--text-muted,#64748b);">
        <i class="bi bi-lock-fill" style="margin-right:.4rem;font-size:.75rem;"></i><?= htmlspecialchars($sessionDept) ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="hr-filter-group">
      <label>Category</label>
      <select name="category" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:140px;">
        <option value="">— All —</option>
        <?php foreach ($categoryList as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= ($filterCategory === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hr-filter-group">
      <label>Payroll Group</label>
      <select name="pgroup" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:140px;">
        <option value="">— All —</option>
        <?php foreach ($pGroupList as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>" <?= ($filterPGroup === $p) ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hr-filter-group">
      <label>Year</label>
      <select name="year" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:100px;">
        <option value="">— All —</option>
        <?php foreach ($yearList as $y): ?>
        <option value="<?= htmlspecialchars((string)$y) ?>" <?= ((string)$filterYear === (string)$y) ? 'selected' : '' ?>><?= htmlspecialchars((string)$y) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hr-filter-group">
      <label>Month</label>
      <select name="month" onchange="this.form.submit()" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($monthNames as $num => $label): ?>
        <option value="<?= $num ?>" <?= ((string)$filterMonth === (string)$num) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hr-filter-group">
      <label>Week <span id="weekGroupLabel" style="font-weight:400;color:var(--text-muted,#64748b);">(Mon-Sat)</span></label>
      <select id="weekFilter" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:170px;" onchange="weekFilterChange()">
        <option value="0">— All Weeks —</option>
      </select>
    </div>

    <button type="submit" class="hr-btn hr-btn-primary">
      <i class="bi bi-funnel-fill"></i> Filter
    </button>
    <a href="?" class="hr-btn hr-btn-ghost">
      <i class="bi bi-x-circle"></i> Reset
    </a>
  </div>
</form>

<?php
// Renders the <thead> for a weekly grid table. $dayHeaders is the fixed
// 6-weekday label set for the tab's group (Mon-Sat or Sat-Fri).
// $hasLate toggles the MonthName/Week/Total Late columns for the two
// Lates tabs vs. the two Attendance tabs.
function renderWpThead(array $dayHeaders, bool $hasLate): string {
    $out = '<tr><th>Employee Name</th><th>Department</th><th>Category</th><th>Payroll Group</th><th>Cutoff Start</th><th>Cutoff</th>';
    if ($hasLate) { $out .= '<th>Month</th><th>Week</th>'; }
    foreach ($dayHeaders as $h) {
        $out .= '<th class="wp-day-head">' . htmlspecialchars($h) . '</th>';
    }
    $out .= '<th>Present</th><th>Absent</th><th>Half Day</th><th>Total Days</th>';
    if ($hasLate) { $out .= '<th>Total Late</th>'; }
    $out .= '</tr>';
    return $out;
}

$wpTabs = [
    'msAtt'  => ['label' => 'Cutoff Mon-Sat Attendance', 'dayHeaders' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 'hasLate' => false],
    'sfAtt'  => ['label' => 'Cutoff Sat-Fri Attendance',  'dayHeaders' => ['Sat','Sun','Mon','Tue','Wed','Thu','Fri'], 'hasLate' => false],
    'msLate' => ['label' => 'Cutoff Mon-Sat Lates',       'dayHeaders' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 'hasLate' => true],
    'sfLate' => ['label' => 'Sat-Fri Lates',               'dayHeaders' => ['Sat','Sun','Mon','Tue','Wed','Thu','Fri'], 'hasLate' => true],
];
?>

<!-- ── 4-tab grid: Mon-Sat / Sat-Fri × Attendance / Lates ──── -->
<div class="hr-table-card">
  <div class="wp-tabs">
    <?php foreach ($wpTabs as $tabId => $tab): ?>
    <button class="wp-tab-btn<?= $tabId === 'msAtt' ? ' active' : '' ?>" id="wpTabBtn-<?= $tabId ?>" onclick="switchWpTab('<?= $tabId ?>')"><?= htmlspecialchars($tab['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="wp-legend">
    <span class="wp-legend-item"><span class="wp-legend-swatch late"></span> Minutes late (Lates tabs)</span>
    <span class="wp-legend-item"><span class="wp-legend-swatch absent"></span> A = Absent</span>
    <span class="wp-legend-item"><span class="wp-legend-swatch halfday"></span> HD = Half Day</span>
    <span class="wp-legend-item"><span class="wp-legend-swatch present"></span> &#10003; = Present</span>
  </div>

  <?php foreach ($wpTabs as $tabId => $tab): ?>
  <div class="wp-tab-panel<?= $tabId === 'msAtt' ? ' active' : '' ?>" id="wpPanel-<?= $tabId ?>">
    <div class="hr-table-toolbar">
      <div class="hr-table-title">
        📅 <?= htmlspecialchars($tab['label']) ?>
        <span class="hr-table-count" id="<?= $tabId ?>Count">0 records</span>
      </div>
      <div class="hr-table-actions">
        <div class="hr-search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="<?= $tabId ?>Search" placeholder="Search all records…" oninput="tableSearch('<?= $tabId ?>')">
        </div>
        <button class="hr-btn hr-btn-ghost" onclick="exportExcel('<?= $tabId ?>', 'weekly_payroll_<?= $tabId ?>')">
          <i class="bi bi-file-earmark-excel"></i> Excel
        </button>
        <button class="hr-btn hr-btn-ghost" onclick="printTable('<?= $tabId ?>', '<?= htmlspecialchars($tab['label']) ?>')">
          <i class="bi bi-printer"></i> Print
        </button>
      </div>
    </div>
    <div class="hr-table-scroll">
      <table class="wp-table" id="<?= $tabId ?>Table">
        <thead><?= renderWpThead($tab['dayHeaders'], $tab['hasLate']) ?></thead>
        <tbody id="<?= $tabId ?>Body"></tbody>
      </table>
      <div id="<?= $tabId ?>Empty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
    </div>
    <div class="att-pagination" id="<?= $tabId ?>Pager"></div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const DATA = <?= $jsRows ?>; // { msAtt:[...], sfAtt:[...], msLate:[...], sfLate:[...] }
const PAGE_SIZE = 20;
const TAB_META = {
    msAtt:  { label: 'Cutoff Mon-Sat Attendance', hasLate: false, group: 'ms' },
    sfAtt:  { label: 'Cutoff Sat-Fri Attendance',  hasLate: false, group: 'sf' },
    msLate: { label: 'Cutoff Mon-Sat Lates',       hasLate: true,  group: 'ms' },
    sfLate: { label: 'Sat-Fri Lates',               hasLate: true,  group: 'sf' },
};
// Day-column render order per group. ms: Day1..Day7 = Mon..Sun, shown
// as-is. sf: Day1..Day6 = Sat,Mon,Tue,Wed,Thu,Fri and Day7 = Sun, but
// displayed with Day7 moved right after Day1 so the header reads
// Sat, Sun, Mon, Tue, Wed, Thu, Fri instead of the raw column order.
const DAY_ORDER = {
    ms: [1, 2, 3, 4, 5, 6, 7],
    sf: [1, 2, 3, 4, 5, 6, 7],
};
const TAB_IDS = Object.keys(TAB_META);
const MS_WEEKS = <?= json_encode($msWeekList) ?>;
const SF_WEEKS = <?= json_encode($sfWeekList) ?>;
const GROUP_WEEKS = { ms: MS_WEEKS, sf: SF_WEEKS };
const GROUP_LABEL = { ms: 'Mon-Sat', sf: 'Sat-Fri' };
let activeGroup = 'ms';

const state = {};
TAB_IDS.forEach(id => { state[id] = { filtered: DATA[id] || [], page: 1, weekDate: '', search: '' }; });

// Rebuilds a tab's filtered rows from scratch (week + search combined)
// so the two filters compose correctly instead of clobbering each other.
function applyFilters(id) {
    const s = state[id];
    let rows = DATA[id] || [];
    if (s.weekDate) { rows = rows.filter(r => (r.CutoffStart || '') === s.weekDate); }
    if (s.search) {
        const q = s.search;
        rows = rows.filter(r => Object.values(r).some(v => String(v||'').toLowerCase().includes(q)));
    }
    s.filtered = rows;
    s.page = 1;
    renderTable(id);
}

// Swaps #weekFilter's options to the given group's week list and resets
// both tabs in that group to "All Weeks" — called on load + tab switch.
function loadWeekFilterForGroup(group) {
    activeGroup = group;
    const sel = document.getElementById('weekFilter');
    const label = document.getElementById('weekGroupLabel');
    if (label) label.textContent = '(' + GROUP_LABEL[group] + ')';
    if (!sel) return;
    const weeks = GROUP_WEEKS[group] || [];
    sel.innerHTML = '<option value="0">— All Weeks —</option>' +
        weeks.map(w => `<option value="${esc(w.date)}">${esc(w.label)}</option>`).join('');
    sel.value = '0';
    TAB_IDS.filter(id => TAB_META[id].group === group).forEach(id => {
        state[id].weekDate = '';
        applyFilters(id);
    });
}

function weekFilterChange() {
    const sel = document.getElementById('weekFilter');
    const date = sel && sel.value !== '0' ? sel.value : '';
    TAB_IDS.filter(id => TAB_META[id].group === activeGroup).forEach(id => {
        state[id].weekDate = date;
        applyFilters(id);
    });
}

// Currently-selected filter values, for the print/export header block
// (Department/Category/Payroll Group are dropped as table columns
// there and shown once up top instead).
const FILTER_INFO = {
    department: <?= json_encode(deptLabel($filterDept)) ?>,
    category:   <?= json_encode($filterCategory !== '' ? $filterCategory : 'All Categories') ?>,
    pgroup:     <?= json_encode($filterPGroup !== '' ? $filterPGroup : 'All Payroll Groups') ?>,
    year:       <?= json_encode($filterYear) ?>,
    month:      <?= json_encode($filterMonth !== '' ? ($monthNames[(int)$filterMonth] ?? $filterMonth) : 'All Months') ?>,
};

// Clones a rendered table and removes the Department/Category/Payroll
// Group columns (indices 1-3) — used for print & Excel export, where
// those are shown once in a header block instead of per row.
function stripFixedCols(table) {
    const clone = table.cloneNode(true);
    clone.querySelectorAll('tr').forEach(tr => {
        [3, 2, 1].forEach(i => { if (tr.children[i]) tr.children[i].remove(); });
    });
    return clone;
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function totalLateClass(n) {
    const v = parseFloat(n) || 0;
    if (v <= 0) return 'zero';
    if (v >= 60) return 'danger';
    if (v > 0)  return 'warn';
    return '';
}

// One <td> per weekly day column (always 6, no DAY OFF cells — Sunday
// isn't represented in Day1..Day6 at all). Numeric values render as
// late-minutes; non-numeric codes (A = Absent, HD = Half Day, P = Present,
// or any other text code the view returns) render as-is instead of
// being coerced to 0.
function dayCell(raw) {
    if (raw === null || raw === undefined || String(raw).trim() === '') {
        return `<td class="wp-day zero">—</td>`;
    }
    const str = String(raw).trim();
    // Late minutes come back from the view with a trailing "L" baked in
    // (e.g. "8.72L", "26.28L") — strip it and show the number only.
    if (/^-?\d+(\.\d+)?\s*L?$/i.test(str)) {
        const mins = parseFloat(str);
        return `<td class="wp-day ${mins > 0 ? 'late' : 'zero'}">${mins}</td>`;
    }
    const code = str.toUpperCase();
    if (code === 'A')  return `<td class="wp-day absent" title="Absent">A</td>`;
    if (code === 'HD') return `<td class="wp-day halfday" title="Half Day">HD</td>`;
    if (code === 'P')  return `<td class="wp-day present" title="Present">&#10003;</td>`;
    if (code === 'L')  return `<td class="wp-day zero">—</td>`; // bare "L" with no number — not a real code
    return `<td class="wp-day">${esc(code)}</td>`;
}

function rowWp(r, id) {
    const meta = TAB_META[id];
    const dayCells = DAY_ORDER[meta.group].map(n => dayCell(r['Day' + n])).join('');
    let extraHead = '';
    let extraTail = '';
    if (meta.hasLate) {
        extraHead = `<td>${esc(r.MonthName||'—')}</td><td>${esc(r.Week||'—')}</td>`;
        const total = parseFloat(r.TotalLate) || 0;
        const cls = totalLateClass(total);
        extraTail = `<td><span class="wp-total-late ${cls}">${cls==='zero' ? '—' : total + ' min'}</span></td>`;
    }
    return `<tr>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${esc(r.PayrollGroup||'—')}</td>
      <td>${esc(r.CutoffStart||'—')}</td>
      <td>${esc(r.CutOff||'—')}</td>
      ${extraHead}
      ${dayCells}
      <td>${esc(r.Present ?? '—')}</td>
      <td>${esc(r.Absent ?? '—')}</td>
      <td>${esc(r.HalfDay ?? '—')}</td>
      <td>${esc(r.TotalDays ?? '—')}</td>
      ${extraTail}
    </tr>`;
}

function renderTable(id) {
    const s = state[id];
    const all = s.filtered;
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

    tbody.innerHTML = slice.map(r => rowWp(r, id)).join('');

    if (pager) {
        const from = start + 1, to = Math.min(start + PAGE_SIZE, total);
        let btns = `<span class="att-page-info">Showing ${from}–${to} of ${total}</span><div class="att-page-btns">`;
        btns += `<button onclick="goPage('${id}',${s.page-1})" ${s.page<=1?'disabled':''}>‹</button>`;
        const pageNums = buildPageNums(s.page, pages);
        for (const p of pageNums) {
            if (p === '…') { btns += `<span class="ellipsis">…</span>`; }
            else { btns += `<button class="${p===s.page?'active':''}" onclick="goPage('${id}',${p})">${p}</button>`; }
        }
        btns += `<button onclick="goPage('${id}',${s.page+1})" ${s.page>=pages?'disabled':''}>›</button></div>`;
        pager.innerHTML = btns;
    }
}
function buildPageNums(cur, total) {
    if (total <= 7) return Array.from({length:total},(_,i)=>i+1);
    const pages = [1];
    if (cur > 3) pages.push('…');
    for (let p = Math.max(2,cur-1); p <= Math.min(total-1,cur+1); p++) pages.push(p);
    if (cur < total - 2) pages.push('…');
    pages.push(total);
    return pages;
}
function goPage(id, p) {
    const s = state[id];
    const pages = Math.ceil(s.filtered.length / PAGE_SIZE);
    s.page = Math.max(1, Math.min(p, pages));
    renderTable(id);
}
function tableSearch(id) {
    const q = (document.getElementById(id + 'Search')?.value || '').toLowerCase().trim();
    state[id].search = q;
    applyFilters(id);
}
// Exports the on-screen grid (not raw JSON keys) to a real Excel file —
// same day columns and A/HD/Present color-coding as the page, opened
// directly in Excel via its HTML-table import support.
function exportExcel(id, filename) {
    const table = document.getElementById(id + 'Table');
    const tbody = document.getElementById(id + 'Body');
    if (!table || !tbody) return;

    const saved = tbody.innerHTML;
    tbody.innerHTML = state[id].filtered.map(r => rowWp(r, id)).join('');
    const cleanTable = stripFixedCols(table);
    tbody.innerHTML = saved;

    const info = FILTER_INFO;
    const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>${esc(filename)}</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>
  body { font-family: Calibri, Arial, sans-serif; }
  table { border-collapse: collapse; font-size: 11pt; }
  th { background:#1e293b; color:#ffffff; font-weight:bold; padding:4px 8px; border:1px solid #64748b; }
  td { padding:3px 8px; border:1px solid #cbd5e1; }
  td.wp-day.absent  { background:#fee2e2; color:#b91c1c; font-weight:bold; }
  td.wp-day.halfday { background:#fef3c7; color:#b45309; font-weight:bold; }
  td.wp-day.present { background:#dcfce7; color:#15803d; font-weight:bold; }
  .wp-xl-head td { border:none; padding:1px 4px; font-weight:bold; }
</style></head>
<body>
<table class="wp-xl-head">
  <tr><td>Department:</td><td>${esc(info.department)}</td></tr>
  <tr><td>Category:</td><td>${esc(info.category)}</td></tr>
  <tr><td>Payroll Group:</td><td>${esc(info.pgroup)}</td></tr>
  <tr><td>Tab:</td><td>${esc(TAB_META[id].label)}</td></tr>
  <tr><td>Period:</td><td>${esc(info.month)} ${esc(info.year)}</td></tr>
</table>
<br>
${cleanTable.outerHTML}
</body></html>`;

    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename + '_<?= date('Ymd') ?>.xls';
    a.click();
    URL.revokeObjectURL(a.href);
}
function printTable(id, title) {
    const tbody = document.getElementById(id + 'Body');
    const table = document.getElementById(id + 'Table');
    if (!table) return;
    const saved = tbody.innerHTML;
    tbody.innerHTML = state[id].filtered.map(r => rowWp(r, id)).join('');
    const cleanTable = stripFixedCols(table);
    tbody.innerHTML = saved;

    const info = FILTER_INFO;
    const win = window.open('', '_blank', 'width=1100,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>${title}</title>
      <style>
        body { font-family: Arial, sans-serif; font-size: 10px; padding: 8px; }
        h3   { margin: 0 0 6px; font-size: 13px; }
        .wp-print-meta { margin: 0 0 8px; font-size: 9px; color: #333; display:flex; gap:18px; flex-wrap:wrap; }
        .wp-print-meta span b { color:#000; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b !important; color:#fff; font-size: 8px; text-transform: uppercase; border: 1px solid #64748b; padding: 4px 6px; }
        td { border: 1px solid #ccc; padding: 3px 6px; text-align:center; }
        td:first-child, th:first-child { text-align:left; }
        tr:nth-child(even) td { background: #f7f7f7; }
        td.wp-day.zero { color:#cbd5e1; }
        td.wp-day.late { color:#dc2626 !important; font-weight:700; }
        td.wp-day.absent  { color:#b91c1c !important; font-weight:800; background:#fee2e2 !important; }
        td.wp-day.halfday { color:#b45309 !important; font-weight:800; background:#fef3c7 !important; }
        td.wp-day.present { color:#15803d !important; font-weight:800; background:#dcfce7 !important; }
        .wp-total-late { font-weight:700; }
        .wp-total-late.zero   { color:#94a3b8; font-weight:400; }
        .wp-total-late.warn   { color:#ca8a04 !important; }
        .wp-total-late.danger { color:#dc2626 !important; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        button { display:none; }
        @page { size: landscape; margin: 10mm; }
      </style>
    </head><body>
      <h3>Urban Tradewell Corporation — ${title}</h3>
      <div class="wp-print-meta">
        <span>Department: <b>${info.department}</b></span>
        <span>Category: <b>${info.category}</b></span>
        <span>Payroll Group: <b>${info.pgroup}</b></span>
        <span>Period: <b>${info.month} ${info.year}</b></span>
        <span>Printed: <b><?= date('Y-m-d H:i') ?></b></span>
      </div>
      ${cleanTable.outerHTML}
    </body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}

// ── Tab switching ───────────────────────────────────────────
function switchWpTab(id) {
    TAB_IDS.forEach(other => {
        document.getElementById('wpTabBtn-' + other).classList.toggle('active', other === id);
        document.getElementById('wpPanel-' + other).classList.toggle('active', other === id);
    });
    const group = TAB_META[id].group;
    if (group !== activeGroup) { loadWeekFilterForGroup(group); }
}

document.addEventListener('DOMContentLoaded', () => {
    loadWeekFilterForGroup('ms');
    TAB_IDS.forEach(id => renderTable(id));
});
</script>

</body>
</html>