<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'attendance_present');

// ── Session context — same dept-scoping pattern as attendance.php ──
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

// ── Dept WHERE clause (mirrors attendance.php's dc()) ─────
function dc(string $deptSafe, string $col = 'Department'): string {
    return $deptSafe !== '' ? "AND RTRIM($col) = '$deptSafe'" : '';
}
function eqOrAll(string $valSafe, string $col): string {
    return $valSafe !== '' ? "AND RTRIM($col) = '$valSafe'" : '';
}

// ── Dropdown option lists — dept-scoped for locked-department users ──
$deptList = [];
if ($canFilterDept) {
    $dStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Department) AS Department FROM View_Payroll_Setup_DayCount WHERE Department IS NOT NULL AND Department <> '' ORDER BY Department");
    if ($dStmt) { while ($r = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) { $deptList[] = $r['Department']; } sqlsrv_free_stmt($dStmt); }
}

$categoryList = [];
$catStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Category) AS V FROM View_Payroll_Setup_DayCount WHERE Category IS NOT NULL AND Category <> '' " . dc($filterDeptSafe) . " ORDER BY V");
if ($catStmt) { while ($r = sqlsrv_fetch_array($catStmt, SQLSRV_FETCH_ASSOC)) { $categoryList[] = $r['V']; } sqlsrv_free_stmt($catStmt); }

$pGroupList = [];
$pgStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(PayrollGroup) AS V FROM View_Payroll_Setup_DayCount WHERE PayrollGroup IS NOT NULL AND PayrollGroup <> '' " . dc($filterDeptSafe) . " ORDER BY V");
if ($pgStmt) { while ($r = sqlsrv_fetch_array($pgStmt, SQLSRV_FETCH_ASSOC)) { $pGroupList[] = $r['V']; } sqlsrv_free_stmt($pgStmt); }

$yearList = [];
$yStmt = sqlsrv_query($conn, "SELECT DISTINCT PayrollYear AS V FROM View_Payroll_Setup_DayCount WHERE PayrollYear IS NOT NULL " . dc($filterDeptSafe) . " ORDER BY V DESC");
if ($yStmt) { while ($r = sqlsrv_fetch_array($yStmt, SQLSRV_FETCH_ASSOC)) { $yearList[] = $r['V']; } sqlsrv_free_stmt($yStmt); }

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// ── Main query — pull every Day1..Day31 column + the Present/Absent/
// HalfDay/TotalDays summary counts ──
$dayCols = implode(',', array_map(fn($i) => "[Day$i]", range(1, 31)));
$debugLog = [];

$sql = "
    SELECT Department, Category, PayrollGroup, EmployeeName, PayrollYear, PayrollMonth, CutoffName,
           $dayCols, TotalDays, Present, Absent, HalfDay
    FROM View_Payroll_Setup_DayCount
    WHERE 1=1
          " . dc($filterDeptSafe) . "
          " . eqOrAll($filterCategorySafe, 'Category') . "
          " . eqOrAll($filterPGroupSafe, 'PayrollGroup') . "
          " . ($filterYearSafe !== '' ? "AND PayrollYear = '$filterYearSafe'" : '') . "
          " . ($filterMonthSafe !== '' ? "AND PayrollMonth = '$filterMonthSafe'" : '') . "
          " . ($searchNameSafe !== '' ? "AND EmployeeName LIKE '%$searchNameSafe%'" : '') . "
    ORDER BY PayrollYear DESC, PayrollMonth DESC, EmployeeName ASC
";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) { $debugLog['attendance_present'] = sqlsrv_errors(); }

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

// ── Split into 1st Cutoff / 2nd Cutoff tabs ───────────────
// Matched off CutoffName text (case-insensitive "1st"/"2nd"/"first"/"second").
// Anything that doesn't match either pattern falls back into the 1st tab
// rather than being silently dropped.
$rows1 = []; $rows2 = [];
foreach ($rows as $r) {
    $cn = strtolower(trim($r['CutoffName'] ?? ''));
    if (strpos($cn, '2nd') !== false || strpos($cn, 'second') !== false) {
        $rows2[] = $r;
    } else {
        $rows1[] = $r;
    }
}

// ── Figure out which Day1..Day31 columns actually have data ─────
// (keeps the grid tight to the real cutoff span, e.g. 11–25, instead of
// always printing all 31 day columns)
function activeDays(array $rows): array {
    $days = [];
    for ($d = 1; $d <= 31; $d++) {
        foreach ($rows as $r) {
            $v = $r["Day$d"] ?? null;
            if ($v !== null && $v !== '') { $days[] = $d; break; }
        }
    }
    return $days;
}

// activeDays() returns day-of-month numbers in plain ascending order
// (1..31), which is correct for the 2nd Cutoff (11..25, a single month)
// but wrong for the 1st Cutoff: its Day26..Day31 columns are the earlier,
// PREVIOUS-month dates and Day1..Day10 are the later, row's-own-month
// dates — so a plain numeric sort would put the row's month before the
// previous month. This puts them back in actual chronological order:
// 26..31 (previous month) first, then 1..10 (row's own month).
function orderCutoffDays(array $days, string $tabId): array {
    if ($tabId !== 'co1') { return $days; }
    $head = array_values(array_filter($days, fn($d) => $d >= 26));
    $tail = array_values(array_filter($days, fn($d) => $d <= 10));
    sort($head); sort($tail);
    return array_merge($head, $tail);
}

// ── Figure out the single Year/Month a group of rows belongs to ───
// (needed to anchor the cutoff's start date and know which day columns
// are Sundays). Prefers the explicit Year/Month filter; falls back to
// the rows' own PayrollYear/PayrollMonth if they all agree; returns
// null if it's ambiguous (mixed months in view).
function ymFor(array $rows, string $filterYearSafe, string $filterMonthSafe): ?array {
    if ($filterYearSafe !== '' && $filterMonthSafe !== '') {
        return [(int)$filterYearSafe, (int)$filterMonthSafe];
    }
    $ym = null;
    foreach ($rows as $r) {
        $y = (int)($r['PayrollYear'] ?? 0);
        $m = (int)($r['PayrollMonth'] ?? 0);
        if ($y <= 0 || $m <= 0) continue;
        if ($ym === null) { $ym = [$y, $m]; }
        elseif ($ym !== [$y, $m]) { return null; }
    }
    return $ym;
}

// ── Fixed semi-monthly cutoff boundaries ───────────────────
// The row's PayrollMonth is the SETTLEMENT/ending month for both cutoffs.
// 2nd Cutoff: 11th–25th of the row's own PayrollMonth/Year — Day{n}
// is simply day n of that month.
// 1st Cutoff: 26th of the PREVIOUS month – 10th of the row's own
// PayrollMonth/Year — Day{n} for n=26..31 is day n of the PREVIOUS
// month, and Day{n} for n=1..10 is day n of the row's own month.
// e.g. PayrollMonth = August → 1st Cutoff runs July 26 – August 10.
function dateForDay(string $tabId, int $y, int $m, int $n): array {
    if ($tabId === 'co2' || $n <= 10) {
        return [$y, $m, $n];
    }
    $py = $y; $pm = $m - 1;
    if ($pm < 1) { $pm = 12; $py--; }
    return [$py, $pm, $n];
}

// Builds column definitions for a tab: $days = the day-of-month positions
// (1..31) that have data, as returned by activeDays().
function buildCols(array $days, ?array $ym, string $tabId): array {
    $cols = [];
    foreach ($days as $n) {
        $label = "Day $n";
        $sunday = false;
        if ($ym !== null) {
            [$dy, $dm, $dd] = dateForDay($tabId, $ym[0], $ym[1], $n);
            if (checkdate($dm, $dd, $dy)) {
                $ts = mktime(0, 0, 0, $dm, $dd, $dy);
                $label  = date('j-M', $ts);
                $sunday = ((int)date('N', $ts) === 7);
            }
        }
        $cols[] = ['d' => $n, 'label' => $label, 'sunday' => $sunday];
    }
    return $cols;
}

// activeDays() only keeps a Day{n} column when some row has real data in
// it — but Sundays are day-offs, so the view returns NULL/blank for them
// across every row and they get silently dropped from the grid entirely
// (instead of showing as a "DAY OFF" column). This backfills any Sunday
// that falls inside the already-active date range, so it renders as a
// DAY OFF column instead of vanishing.
function fillMissingSundays(array $days, ?array $ym, string $tabId): array {
    if ($ym === null || empty($days)) { return $days; }

    $timestamps = [];
    foreach ($days as $n) {
        [$dy, $dm, $dd] = dateForDay($tabId, $ym[0], $ym[1], $n);
        if (checkdate($dm, $dd, $dy)) { $timestamps[] = mktime(0, 0, 0, $dm, $dd, $dy); }
    }
    if (empty($timestamps)) { return $days; }

    $existing = array_flip($days);
    for ($ts = min($timestamps); $ts <= max($timestamps); $ts += 86400) {
        if ((int)date('N', $ts) === 7) { // Sunday
            $n = (int)date('j', $ts);
            if (!isset($existing[$n])) { $days[] = $n; $existing[$n] = true; }
        }
    }
    return $days;
}

$ym1   = ymFor($rows1, $filterYearSafe, $filterMonthSafe);
$ym2   = ymFor($rows2, $filterYearSafe, $filterMonthSafe);
$cols1 = buildCols(orderCutoffDays(fillMissingSundays(activeDays($rows1), $ym1, 'co1'), 'co1'), $ym1, 'co1');
$cols2 = buildCols(orderCutoffDays(fillMissingSundays(activeDays($rows2), $ym2, 'co2'), 'co2'), $ym2, 'co2');

$jsRows = json_encode(['co1' => $rows1, 'co2' => $rows2], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsCols = json_encode(['co1' => $cols1, 'co2' => $cols2], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

function tabUrl_self(): string {
    $p = $_GET; unset($p['page']);
    return '?' . http_build_query($p);
}
function deptLabel(string $d): string {
    return $d !== '' ? htmlspecialchars($d) : 'All Departments';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Present — HR · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.co-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.co-table thead th {
    background:var(--surface-raised, #f1f5f9);
    color:var(--text-muted, #64748b);
    font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
    padding:8px 10px; border-bottom:2px solid var(--border, #e2e8f0);
    white-space:nowrap; text-align:left;
}
.co-table tbody tr { border-bottom:1px solid var(--border, #e2e8f0); transition:background .12s; }
.co-table tbody tr:hover { background:var(--surface-hover, #f8fafc); }
.co-table tbody td { padding:6px 10px; font-size:.88rem; color:var(--text, #1e293b); vertical-align:middle; line-height:1.3; }
.co-table .hr-badge { padding:3px 8px; font-size:.78rem; white-space:nowrap; }

.co-total-days { font-family:'JetBrains Mono',monospace; font-weight:700; color:#15803d; }
.co-total-days.zero { color:#94a3b8; font-weight:400; }
.co-summary-count { font-family:'JetBrains Mono',monospace; font-weight:700; }
.co-summary-count.present { color:#15803d; }
.co-summary-count.absent { color:#b91c1c; }
.co-summary-count.halfday { color:#b45309; }

.att-pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-top:1px solid var(--border,#e2e8f0); gap:12px; flex-wrap:wrap; }
.att-pagination .att-page-info { font-size:.88rem; color:var(--text-muted,#64748b); }
.att-page-btns { display:flex; gap:4px; align-items:center; }
.att-page-btns button { border:1px solid var(--border,#e2e8f0); background:var(--surface,#fff); color:var(--text,#1e293b); border-radius:6px; padding:5px 11px; font-size:.88rem; font-weight:600; cursor:pointer; transition:background .12s, color .12s; min-width:34px; }
.att-page-btns button:hover:not(:disabled) { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button.active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button:disabled { opacity:.35; cursor:not-allowed; }
.att-page-btns .ellipsis { padding:5px 4px; font-size:.88rem; color:var(--text-muted,#64748b); }

/* ── Cutoff tabs ─────────────────────────────────────────── */
.co-tabs { display:flex; gap:6px; border-bottom:1px solid var(--border,#e2e8f0); padding:0 16px; }
.co-tab-btn {
    border:none; background:transparent; padding:.7rem 1.1rem; font-size:.86rem; font-weight:700;
    color:var(--text-muted,#64748b); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px;
}
.co-tab-btn:hover { color:var(--text,#1e293b); }
.co-tab-btn.active { color:var(--primary,#2563eb); border-bottom-color:var(--primary,#2563eb); }
.co-tab-panel { display:none; }
.co-tab-panel.active { display:block; }

/* ── Day grid cells ──────────────────────────────────────── */
.co-table td.co-day { text-align:center; font-family:'JetBrains Mono',monospace; }
.co-table td.co-day.zero { color:#cbd5e1; }
.co-table td.co-day.present { color:#15803d; font-weight:800; background:#dcfce7; }
.co-table td.co-day.absent { color:#b91c1c; font-weight:800; background:#fee2e2; }
.co-table td.co-day.halfday { color:#b45309; font-weight:800; background:#fef3c7; }
.co-table td.co-dayoff { text-align:center; font-size:.72rem; font-weight:700; color:#94a3b8; background:#f1f5f9; letter-spacing:.03em; }
.co-table th.co-day-head.sunday { color:#dc2626; }

.co-legend { display:flex; gap:16px; flex-wrap:wrap; align-items:center; padding:8px 16px; font-size:.78rem; color:var(--text-muted,#64748b); border-bottom:1px solid var(--border,#e2e8f0); }
.co-legend-item { display:flex; align-items:center; gap:5px; }
.co-legend-swatch { display:inline-block; width:11px; height:11px; border-radius:3px; }
.co-legend-swatch.present { background:#dcfce7; border:1px solid #15803d; }
.co-legend-swatch.absent { background:#fee2e2; border:1px solid #b91c1c; }
.co-legend-swatch.halfday { background:#fef3c7; border:1px solid #b45309; }
.co-legend-swatch.dayoff { background:#f1f5f9; border:1px solid #94a3b8; }
</style>
</head>
<body>
<?php if (!empty($debugLog)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r($debugLog, true)) ?>
-->
<?php endif; ?>

<?php
$topbar_page = 'hr_attendance';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'attendance_present';
require_once __DIR__ . '/hr_nav.php';
?>

<!-- ── Page Header ───────────────────────────────────────── -->
<div class="hr-page-header">
  <div>
    <div class="hr-page-title">✅ <span style="color:#2563eb;">Attendance Present</span> Summary</div>
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
      <label>Payroll Year</label>
      <select name="year" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:100px;">
        <option value="">— All —</option>
        <?php foreach ($yearList as $y): ?>
        <option value="<?= htmlspecialchars((string)$y) ?>" <?= ((string)$filterYear === (string)$y) ? 'selected' : '' ?>><?= htmlspecialchars((string)$y) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hr-filter-group">
      <label>Payroll Month</label>
      <select name="month" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($monthNames as $num => $label): ?>
        <option value="<?= $num ?>" <?= ((string)$filterMonth === (string)$num) ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
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
// Renders the <thead> for a cutoff grid table given its computed day columns.
function renderCoThead(array $cols): string {
    $out = '<tr><th>Employee Name</th><th>Department</th><th>Category</th><th>Payroll Group</th><th>Cutoff</th>';
    foreach ($cols as $c) {
        $sundayClass = $c['sunday'] ? ' sunday' : '';
        $out .= '<th class="co-day-head' . $sundayClass . '">' . htmlspecialchars($c['label']) . '</th>';
    }
    $out .= '<th>Present</th><th>Absent</th><th>Half Day</th><th>Total Days</th></tr>';
    return $out;
}
?>

<!-- ── 1st Cutoff / 2nd Cutoff Tabs ──────────────────────── -->
<div class="hr-table-card">
  <div class="co-tabs">
    <button class="co-tab-btn active" id="coTabBtn-co1" onclick="switchCoTab('co1')">1st Cutoff</button>
    <button class="co-tab-btn" id="coTabBtn-co2" onclick="switchCoTab('co2')">2nd Cutoff</button>
  </div>
  <div class="co-legend">
    <span class="co-legend-item"><span class="co-legend-swatch present"></span> &#10003; = Present</span>
    <span class="co-legend-item"><span class="co-legend-swatch absent"></span> A = Absent</span>
    <span class="co-legend-item"><span class="co-legend-swatch halfday"></span> HD = Half Day</span>
    <span class="co-legend-item"><span class="co-legend-swatch dayoff"></span> DAY OFF = Sunday</span>
  </div>

  <?php foreach (['co1' => ['label' => '1st Cutoff', 'cols' => $cols1], 'co2' => ['label' => '2nd Cutoff', 'cols' => $cols2]] as $tabId => $tab): ?>
  <div class="co-tab-panel<?= $tabId === 'co1' ? ' active' : '' ?>" id="coPanel-<?= $tabId ?>">
    <div class="hr-table-toolbar">
      <div class="hr-table-title">
        ✅ <?= htmlspecialchars($tab['label']) ?> Present Summary
        <span class="hr-table-count" id="<?= $tabId ?>Count">0 records</span>
      </div>
      <div class="hr-table-actions">
        <div class="hr-search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="<?= $tabId ?>Search" placeholder="Search all records…" oninput="tableSearch('<?= $tabId ?>')">
        </div>
        <button class="hr-btn hr-btn-ghost" onclick="exportExcel('<?= $tabId ?>', 'attendance_<?= $tabId ?>_present_summary')">
          <i class="bi bi-file-earmark-excel"></i> Excel
        </button>
        <button class="hr-btn hr-btn-ghost" onclick="printTable('<?= $tabId ?>', '<?= htmlspecialchars($tab['label']) ?> Present Summary')">
          <i class="bi bi-printer"></i> Print
        </button>
      </div>
    </div>
    <div class="hr-table-scroll">
      <table class="co-table" id="<?= $tabId ?>Table">
        <thead><?= renderCoThead($tab['cols']) ?></thead>
        <tbody id="<?= $tabId ?>Body"></tbody>
      </table>
      <div id="<?= $tabId ?>Empty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
    </div>
    <div class="att-pagination" id="<?= $tabId ?>Pager"></div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const DATA = <?= $jsRows ?>; // { co1: [...], co2: [...] }
const COLS = <?= $jsCols ?>; // { co1: [{d,label,sunday}], co2: [...] }
const PAGE_SIZE = 20;
const state = {
    co1: { filtered: DATA.co1, page: 1 },
    co2: { filtered: DATA.co2, page: 1 },
};
const CO_LABELS = { co1: '1st Cutoff', co2: '2nd Cutoff' };
// Currently-selected filter values, for the print/export header block
// (Department/Category/Payroll Group/Cutoff are dropped as table columns
// there and shown once up top instead).
const FILTER_INFO = {
    department: <?= json_encode(deptLabel($filterDept)) ?>,
    category:   <?= json_encode($filterCategory !== '' ? $filterCategory : 'All Categories') ?>,
    pgroup:     <?= json_encode($filterPGroup !== '' ? $filterPGroup : 'All Payroll Groups') ?>,
    year:       <?= json_encode($filterYear) ?>,
    month:      <?= json_encode($filterMonth !== '' ? ($monthNames[(int)$filterMonth] ?? $filterMonth) : 'All Months') ?>,
};

// Clones a rendered table and removes the Department/Category/Payroll
// Group/Cutoff columns (indices 1-4) — used for print & Excel export,
// where those are shown once in a header block instead of per row.
function stripFixedCols(table) {
    const clone = table.cloneNode(true);
    clone.querySelectorAll('tr').forEach(tr => {
        [4, 3, 2, 1].forEach(i => { if (tr.children[i]) tr.children[i].remove(); });
    });
    return clone;
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// One <td> per day column: Sundays always show "DAY OFF". The day cell
// carries its own code from the view — P = Present, A = Absent,
// HD = Half Day — rendered and color-coded accordingly; anything blank
// renders as "—".
function rowCo(r, id) {
    const totalDays = parseFloat(r.TotalDays) || 0;
    const totalCls = totalDays <= 0 ? 'zero' : '';
    const dayCells = COLS[id].map(c => {
        if (c.sunday) return `<td class="co-dayoff">DAY OFF</td>`;
        const raw = r['Day' + c.d];
        if (raw === null || raw === undefined || String(raw).trim() === '') {
            return `<td class="co-day zero">—</td>`;
        }
        const code = String(raw).trim().toUpperCase();
        if (code === 'A')  return `<td class="co-day absent" title="Absent">A</td>`;
        if (code === 'HD') return `<td class="co-day halfday" title="Half Day">HD</td>`;
        if (code === 'P')  return `<td class="co-day present" title="Present">&#10003;</td>`;
        return `<td class="co-day">${esc(code)}</td>`;
    }).join('');
    const present = r.Present  !== null && r.Present  !== undefined ? r.Present  : 0;
    const absent  = r.Absent   !== null && r.Absent   !== undefined ? r.Absent   : 0;
    const halfday = r.HalfDay  !== null && r.HalfDay  !== undefined ? r.HalfDay  : 0;
    return `<tr>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${esc(r.PayrollGroup||'—')}</td>
      <td>${esc(r.CutoffName||'—')}</td>
      ${dayCells}
      <td><span class="co-summary-count present">${present}</span></td>
      <td><span class="co-summary-count absent">${absent}</span></td>
      <td><span class="co-summary-count halfday">${halfday}</span></td>
      <td><span class="co-total-days ${totalCls}">${totalCls==='zero' ? '—' : totalDays}</span></td>
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

    tbody.innerHTML = slice.map(r => rowCo(r, id)).join('');

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
    const all = DATA[id] || [];
    state[id].filtered = q ? all.filter(r =>
        Object.values(r).some(v => String(v||'').toLowerCase().includes(q))
    ) : all;
    state[id].page = 1;
    renderTable(id);
}
// Exports the on-screen grid (not raw JSON keys) to a real Excel file —
// same day columns, DAY OFF cells, and A/HD/Present color-coding as the
// page, opened directly in Excel via its HTML-table import support.
function exportExcel(id, filename) {
    const table = document.getElementById(id + 'Table');
    const tbody = document.getElementById(id + 'Body');
    if (!table || !tbody) return;

    const saved = tbody.innerHTML;
    tbody.innerHTML = state[id].filtered.map(r => rowCo(r, id)).join('');
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
  td.co-day.present { background:#dcfce7; color:#15803d; font-weight:bold; }
  td.co-day.absent  { background:#fee2e2; color:#b91c1c; font-weight:bold; }
  td.co-day.halfday { background:#fef3c7; color:#b45309; font-weight:bold; }
  td.co-dayoff       { background:#f1f5f9; color:#64748b; font-weight:bold; }
  .co-summary-count.present { color:#15803d; font-weight:bold; }
  .co-summary-count.absent { color:#b91c1c; font-weight:bold; }
  .co-summary-count.halfday { color:#b45309; font-weight:bold; }
  .co-xl-head td { border:none; padding:1px 4px; font-weight:bold; }
</style></head>
<body>
<table class="co-xl-head">
  <tr><td>Department:</td><td>${esc(info.department)}</td></tr>
  <tr><td>Category:</td><td>${esc(info.category)}</td></tr>
  <tr><td>Payroll Group:</td><td>${esc(info.pgroup)}</td></tr>
  <tr><td>Cutoff:</td><td>${esc(CO_LABELS[id])}</td></tr>
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
    tbody.innerHTML = state[id].filtered.map(r => rowCo(r, id)).join('');
    const cleanTable = stripFixedCols(table);
    tbody.innerHTML = saved;

    const info = FILTER_INFO;
    const win = window.open('', '_blank', 'width=1100,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>${title}</title>
      <style>
        body { font-family: Arial, sans-serif; font-size: 10px; padding: 8px; }
        h3   { margin: 0 0 6px; font-size: 13px; }
        .co-print-meta { margin: 0 0 8px; font-size: 9px; color: #333; display:flex; gap:18px; flex-wrap:wrap; }
        .co-print-meta span b { color:#000; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b !important; color:#fff; font-size: 8px; text-transform: uppercase; border: 1px solid #64748b; padding: 4px 6px; }
        td { border: 1px solid #ccc; padding: 3px 6px; text-align:center; }
        td:first-child, th:first-child { text-align:left; }
        tr:nth-child(even) td { background: #f7f7f7; }
        td.co-day.zero { color:#cbd5e1; }
        td.co-day.present { color:#15803d !important; font-weight:800; background:#dcfce7 !important; }
        td.co-day.absent  { color:#b91c1c !important; font-weight:800; background:#fee2e2 !important; }
        td.co-day.halfday { color:#b45309 !important; font-weight:800; background:#fef3c7 !important; }
        td.co-dayoff { color:#64748b !important; background:#f1f5f9 !important; font-weight:700; font-size:8px; }
        .co-total-days { font-weight:700; color:#15803d; }
        .co-total-days.zero { color:#94a3b8; font-weight:400; }
        .co-summary-count.present { color:#15803d !important; font-weight:700; }
        .co-summary-count.absent { color:#b91c1c !important; font-weight:700; }
        .co-summary-count.halfday { color:#b45309 !important; font-weight:700; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        button { display:none; }
        @page { size: landscape; margin: 10mm; }
      </style>
    </head><body>
      <h3>Urban Tradewell Corporation — ${title}</h3>
      <div class="co-print-meta">
        <span>Department: <b>${info.department}</b></span>
        <span>Category: <b>${info.category}</b></span>
        <span>Payroll Group: <b>${info.pgroup}</b></span>
        <span>Cutoff: <b>${CO_LABELS[id]}</b></span>
        <span>Period: <b>${info.month} ${info.year}</b></span>
        <span>Printed: <b><?= date('Y-m-d H:i') ?></b></span>
      </div>
      ${cleanTable.outerHTML}
    </body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}

// ── Tab switching ───────────────────────────────────────────
function switchCoTab(id) {
    ['co1', 'co2'].forEach(other => {
        document.getElementById('coTabBtn-' + other).classList.toggle('active', other === id);
        document.getElementById('coPanel-' + other).classList.toggle('active', other === id);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderTable('co1');
    renderTable('co2');
});
</script>

</body>
</html>