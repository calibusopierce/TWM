<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'schedule_calendar'); // TODO: confirm/register this RBAC key — copied attendance.php's pattern verbatim

// ── Session context (same dept-scoping pattern as attendance.php) ──
$sessionDept = trim($_SESSION['Department'] ?? '');
$userLevel   = $_SESSION['userlevel']  ?? '';
$isHR        = ($userLevel === 'Admin' || $userLevel === 'HR');

$allDeptSentinels = ['', 'all', 'all department', 'all departments', '*'];
$isAllDeptSession = in_array(strtolower($sessionDept), $allDeptSentinels, true);
$canFilterDept    = $isHR || $isAllDeptSession;

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

// Session-locked users are pinned to their own department; HR/"all
// departments" users get the dropdown and can leave it blank for all.
if ($canFilterDept) {
    $filterDept = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : '';
} else {
    $filterDept = $sessionDept;
}
$filterDeptSafe = str_replace("'", "''", $filterDept);

// Dept dropdown list (only needed for "all departments" users) — same
// source attendance.php uses.
$deptList = [];
if ($canFilterDept) {
    $dStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Department) AS Department FROM dbo.TBL_HREmployeeList WHERE Department IS NOT NULL AND Department <> '' AND Active = 1 AND System = 0 ORDER BY Department");
    if ($dStmt) { while ($dr = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) { $deptList[] = $dr['Department']; } sqlsrv_free_stmt($dStmt); }
}

/* -----------------------------------------------------------
   Employee picker — same source + dept-scoping as the Calendar
   tab in attendance.php: dbo.TBL_HREmployeeList, Active=1,
   System=0, name built from FirstName+LastName.
----------------------------------------------------------- */
$employees = [];
$empDc = $filterDeptSafe !== '' ? "AND RTRIM(Department) = '$filterDeptSafe'" : '';
$empSql = "
    SELECT EmployeeID, LTRIM(RTRIM(ISNULL(FirstName,'') + ' ' + ISNULL(LastName,''))) AS EmployeeName, Department
    FROM dbo.TBL_HREmployeeList
    WHERE Active = 1 AND System = 0 $empDc
    ORDER BY EmployeeName
";
$empStmt = sqlsrv_query($conn, $empSql);
if ($empStmt) {
    while ($r = sqlsrv_fetch_array($empStmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = $r;
    }
    sqlsrv_free_stmt($empStmt);
} else {
    // Swallow here — surfaced via $empLoadError so the page still renders
    // the rest of the UI (and the debug comment) instead of a hard 500.
    $empLoadError = sqlsrv_errors();
}

$empId = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
// Only trust employee_id if it's actually in this user's dept-scoped list —
// same guard attendance.php uses for cal_emp, stops URL-editing to peek
// at another department.
$empValid = false;
foreach ($employees as $e) {
    if ((string)$e['EmployeeID'] === $empId) { $empValid = true; break; }
}
if (!$empValid) { $empId = ''; }

/* -----------------------------------------------------------
   Month being viewed. Whole month always renders (no date-range
   filter like my_attendance.php) — just prev/next month nav.
----------------------------------------------------------- */
$year  = isset($_GET['year'])  && ctype_digit($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) && ctype_digit($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($month < 1 || $month > 12) { $month = (int)date('n'); }

$firstOfMonth = sprintf('%04d-%02d-01', $year, $month);
$daysInMonth  = (int)date('t', strtotime($firstOfMonth));
$startDow     = (int)date('w', strtotime($firstOfMonth)); // 0 = Sun
$monthLabel   = date('F Y', strtotime($firstOfMonth));

$prevStamp = strtotime('-1 month', strtotime($firstOfMonth));
$nextStamp = strtotime('+1 month', strtotime($firstOfMonth));
$prevYear = (int)date('Y', $prevStamp); $prevMonth = (int)date('n', $prevStamp);
$nextYear = (int)date('Y', $nextStamp); $nextMonth = (int)date('n', $nextStamp);

/* -----------------------------------------------------------
   Pull every schedule-adjustment row for the selected employee.
   No date filtering in SQL — "Every" rows have no ADate at all,
   and we need to evaluate all three types against the visible
   month in PHP anyway.
----------------------------------------------------------- */
$debugLog = [];
$rows = [];
if ($empId !== '') {
    $sql = "
        SELECT [ID],[TimeAdjustmentType],[EmployeeID],[ADate],[ADateEnd],
               [EveryMonday],[EveryTuesday],[EveryWednesday],[EveryThursday],
               [EveryFriday],[EverySaturday],[EverySunday],
               [Original_TimeIN_AM],[Adjusted_TimeIN_AM],
               [Original_TimeOUT_AM],[Adjusted_TimeOUT_AM],
               [Original_TimeIN_PM],[Adjusted_TimeIN_PM],
               [Original_TimeOUT_PM],[Adjusted_TimeOUT_PM],
               [Status]
        FROM [dbo].[Tbl_Attendance_Schedule_Time_Adjustment]
        WHERE [EmployeeID] = ?
    ";
    // TODO: confirm what Status actually gates (sample rows show 1 / NULL / NULL
    // inconsistently) — for now every row for the employee is treated as active.
    $stmt = sqlsrv_query($conn, $sql, [$empId]);
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // IMPORTANT: format as full datetime, not just 'Y-m-d' — several of
            // these columns (Original_TimeIN_AM etc.) are TIME columns and also
            // come back as DateTime objects. Truncating to 'Y-m-d' silently wiped
            // out their actual time-of-day. scTime() below re-parses via strtotime()
            // so it handles either shape fine.
            foreach ($r as $k => $v) { if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s'); }
            // Normalize the two date-only columns explicitly (defensive — same
            // substr(...,0,10) guard used in my_attendance.php/attendance.php) so
            // key lookups below are never thrown off by a stray time component.
            if (!empty($r['ADate']))    { $r['ADate']    = substr((string)$r['ADate'], 0, 10); }
            if (!empty($r['ADateEnd'])) { $r['ADateEnd'] = substr((string)$r['ADateEnd'], 0, 10); }
            $rows[] = $r;
        }
        sqlsrv_free_stmt($stmt);
    } else {
        $debugLog['schedule'] = sqlsrv_errors();
        error_log('schedule_calendar.php sql failed: ' . print_r(sqlsrv_errors(), true));
    }
}

/* -----------------------------------------------------------
   Split rows into the three lookup shapes the resolver needs.
   Priority when a date matches more than one: One Day (most
   specific) > Range > Every (recurring, least specific).
----------------------------------------------------------- */
$oneDayMap = []; // 'Y-m-d' => row
$rangeList = []; // [ ['start'=>, 'end'=>, 'row'=>] ]
$everyMap  = [];  // dow(0-6) => row

$dowFlagCols = [
    0 => 'EverySunday', 1 => 'EveryMonday',   2 => 'EveryTuesday', 3 => 'EveryWednesday',
    4 => 'EveryThursday', 5 => 'EveryFriday', 6 => 'EverySaturday',
];

foreach ($rows as $r) {
    $type = trim($r['TimeAdjustmentType'] ?? '');
    if ($type === 'One Day' && !empty($r['ADate'])) {
        $oneDayMap[$r['ADate']] = $r;
    } elseif ($type === 'Range' && !empty($r['ADate']) && !empty($r['ADateEnd'])) {
        $rangeList[] = ['start' => $r['ADate'], 'end' => $r['ADateEnd'], 'row' => $r];
    } elseif ($type === 'Every') {
        foreach ($dowFlagCols as $dow => $col) {
            if (!empty($r[$col])) {
                $everyMap[$dow] = $r; // last matching row wins if more than one is ever set up per weekday
            }
        }
    }
}

// Diagnostic dump — view page source and look for this HTML comment if a
// date isn't highlighting the way you expect. Shows exactly what got parsed
// out of Tbl_Attendance_Schedule_Time_Adjustment for the selected employee.
if ($empId !== '') {
    $debugLog['schedule_employee_id']  = $empId;
    $debugLog['schedule_raw_row_count'] = count($rows);
    $debugLog['schedule_raw_rows']      = $rows;
    $debugLog['schedule_oneDayMap_keys'] = array_keys($oneDayMap);
    $debugLog['schedule_rangeList']      = array_map(fn($rg) => ['start' => $rg['start'], 'end' => $rg['end']], $rangeList);
    $debugLog['schedule_everyMap_dows']  = array_keys($everyMap); // 0=Sun..6=Sat
}

/* -----------------------------------------------------------
   Day status resolver — mirrors the shape of maDayStatus() in
   my_attendance.php. Returns 'oneday' | 'range' | 'every' | 'none'.
----------------------------------------------------------- */
function scDayStatus(string $dateStr, array $oneDayMap, array $rangeList, array $everyMap): array {
    if (isset($oneDayMap[$dateStr])) {
        return ['status' => 'oneday', 'row' => $oneDayMap[$dateStr]];
    }
    foreach ($rangeList as $rg) {
        if ($dateStr >= $rg['start'] && $dateStr <= $rg['end']) {
            return ['status' => 'range', 'row' => $rg['row']];
        }
    }
    $dow = (int)date('w', strtotime($dateStr));
    if (isset($everyMap[$dow])) {
        return ['status' => 'every', 'row' => $everyMap[$dow]];
    }
    return ['status' => 'none'];
}

/* -----------------------------------------------------------
   Helpers
----------------------------------------------------------- */
function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function scTime(?string $t): string {
    if (!$t) return '—';
    $ts = strtotime($t);
    return $ts ? date('h:i a', $ts) : '—';
}
// Compact form for inline calendar-cell display — "9:00am" instead of "09:00 am".
function scTimeCompact(?string $t): ?string {
    if (!$t) return null;
    $ts = strtotime($t);
    return $ts ? date('g:ia', $ts) : null;
}
// Adjusted-time-only shift lines (AM / PM), skipping a segment entirely if
// neither its in nor out time is set. Only Adjusted_* columns are used —
// Original_* is intentionally left out of the display per request.
function scShiftLines(array $row): array {
    $lines = [];
    $amIn  = scTimeCompact($row['Adjusted_TimeIN_AM']  ?? null);
    $amOut = scTimeCompact($row['Adjusted_TimeOUT_AM'] ?? null);
    if ($amIn || $amOut) {
        $lines[] = ['label' => 'AM', 'text' => ($amIn ?: '—') . '–' . ($amOut ?: '—')];
    }
    $pmIn  = scTimeCompact($row['Adjusted_TimeIN_PM']  ?? null);
    $pmOut = scTimeCompact($row['Adjusted_TimeOUT_PM'] ?? null);
    if ($pmIn || $pmOut) {
        $lines[] = ['label' => 'PM', 'text' => ($pmIn ?: '—') . '–' . ($pmOut ?: '—')];
    }
    return $lines;
}
// Adjusted-only summary for the title="" hover fallback.
function scScheduleTooltip(array $row): string {
    $lines = scShiftLines($row);
    if (empty($lines)) return 'No adjusted time set';
    return implode(' · ', array_map(fn($l) => $l['label'] . ' ' . $l['text'], $lines));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Schedule Calendar · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.sc-wrap { max-width: 980px; margin: 0 auto; padding: 1.25rem; }

.sc-hero {
    display:flex; align-items:center; gap:1rem;
    background:linear-gradient(135deg,#eff6ff,#f0f9ff);
    border:1px solid #bfdbfe; border-radius:14px;
    padding:1.1rem 1.4rem; margin-bottom:1.25rem;
}
.sc-hero i { font-size:2.4rem; color:#2563eb; }
.sc-hero-name { font-weight:800; font-size:1.15rem; color:#1e40af; }
.sc-hero-sub { font-size:.85rem; color:#64748b; margin-top:.15rem; }

.sc-filter {
    display:flex; align-items:end; gap:.7rem; flex-wrap:wrap;
    background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:12px; padding:1rem 1.2rem; margin-bottom:1.25rem;
}
.sc-filter label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:.3rem; }
.sc-filter select { padding:.5rem .6rem; border:1px solid var(--border,#e2e8f0); border-radius:8px; font-size:.85rem; min-width:220px; }
.sc-btn {
    display:inline-flex; align-items:center; gap:.4rem;
    background:#2563eb; color:#fff; border:none; border-radius:8px;
    padding:.55rem 1.1rem; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none;
}
.sc-btn:hover { background:#1d4ed8; color:#fff; }
.sc-btn-ghost { background:#f1f5f9; color:#334155; }
.sc-btn-ghost:hover { background:#e2e8f0; color:#334155; }

.sc-empty { padding:2.5rem 1rem; text-align:center; color:#94a3b8; }
.sc-empty i { font-size:2rem; display:block; margin-bottom:.5rem; opacity:.4; }

.sc-card { background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0); border-radius:12px; margin-bottom:1.25rem; overflow:hidden; }
.sc-card-head { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.2rem; border-bottom:1px solid var(--border,#e2e8f0); font-weight:700; }

.sc-cal-legend { display:flex; flex-wrap:wrap; gap:1.1rem; padding:.9rem 1.2rem; font-size:.78rem; color:#64748b; border-bottom:1px solid var(--border,#e2e8f0); }
.sc-cal-legend span { display:inline-flex; align-items:center; gap:.35rem; }
.sc-dot { width:10px; height:10px; border-radius:3px; display:inline-block; }
.sc-dot-oneday { background:#16a34a; }
.sc-dot-every  { background:#eab308; }
.sc-dot-range  { background:#2563eb; }
.sc-dot-none   { background:#cbd5e1; }

.sc-cal-nav { display:flex; align-items:center; justify-content:space-between; padding:.9rem 1.2rem; }
.sc-cal-nav-title { font-weight:800; color:#0f172a; font-size:1rem; }
.sc-cal-nav a { display:inline-flex; align-items:center; gap:.3rem; color:#2563eb; text-decoration:none; font-weight:600; font-size:.85rem; }
.sc-cal-nav a:hover { text-decoration:underline; }

.sc-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; padding:0 1.2rem 1.2rem; }
.sc-cal-dow { text-align:center; font-size:.68rem; font-weight:700; text-transform:uppercase; color:#94a3b8; padding-bottom:4px; }

.sc-cal-cell {
    position:relative; min-height:82px; border-radius:10px; padding:7px 8px;
    border:1px solid var(--border,#e2e8f0); border-left:3px solid transparent; background:#fff;
    display:flex; flex-direction:column; gap:3px;
    transition:box-shadow .15s ease, transform .15s ease;
}
.sc-cal-daynum { font-size:.74rem; font-weight:700; color:#334155; }
.sc-cal-pad { visibility:hidden; }

.sc-cal-head-row { display:flex; align-items:center; justify-content:space-between; gap:4px; }

.sc-cal-none   { background:#f8fafc; }
.sc-cal-none .sc-cal-daynum { color:#94a3b8; }

.sc-cal-oneday { background:#f0fdf4; border-color:#bbf7d0; border-left-color:#16a34a; }
.sc-cal-every  { background:#fefce8; border-color:#fde68a; border-left-color:#eab308; }
.sc-cal-range  { background:#eff6ff; border-color:#bfdbfe; border-left-color:#2563eb; }

.sc-cal-oneday:hover, .sc-cal-every:hover, .sc-cal-range:hover {
    box-shadow:0 4px 12px rgba(15,23,42,.09); transform:translateY(-1px);
}

.sc-cal-badge {
    font-size:.56rem; font-weight:800; text-transform:uppercase; letter-spacing:.03em;
    padding:1px 6px; border-radius:999px; display:inline-flex; align-items:center; gap:2px;
    white-space:nowrap;
}
.sc-cal-badge-oneday { background:#dcfce7; color:#15803d; }
.sc-cal-badge-every  { background:#fef9c3; color:#a16207; }
.sc-cal-badge-range  { background:#dbeafe; color:#1d4ed8; }

.sc-cal-shift { margin-top:1px; font-family:'JetBrains Mono',monospace; font-size:.62rem; line-height:1.4; }
.sc-shift-row { display:flex; align-items:baseline; gap:4px; }
.sc-shift-lbl { font-weight:800; font-size:.56rem; opacity:.5; width:14px; flex-shrink:0; }
.sc-cal-oneday .sc-shift-row { color:#166534; }
.sc-cal-every  .sc-shift-row { color:#854d0e; }
.sc-cal-range  .sc-shift-row { color:#1e40af; }

@media (max-width:640px) {
    .sc-cal-cell { min-height:64px; padding:5px 6px; }
    .sc-cal-badge { font-size:.5rem; padding:1px 4px; }
    .sc-cal-shift { font-size:.55rem; }
    .sc-shift-lbl { width:12px; }
}
</style>
</head>
<body>
<?php if (!empty($debugLog) || !empty($empLoadError)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r(['schedule' => $debugLog, 'employees' => $empLoadError ?? null], true)) ?>
-->
<?php endif; ?>

<?php $topbar_page = 'schedule_calendar'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="sc-wrap">

  <div class="sc-hero">
    <i class="bi bi-calendar2-week"></i>
    <div>
      <div class="sc-hero-name">Employee Schedule Calendar</div>
      <div class="sc-hero-sub">Tbl_Attendance_Schedule_Time_Adjustment · <?= h($monthLabel) ?></div>
    </div>
  </div>

  <!-- Employee picker (dept-scoped, same pattern as attendance.php's Calendar tab) -->
  <form method="GET" class="sc-filter">
    <input type="hidden" name="year" value="<?= (int)$year ?>">
    <input type="hidden" name="month" value="<?= (int)$month ?>">
    <?php if ($canFilterDept): ?>
    <div>
      <label>Department</label>
      <select name="dept" onchange="this.form.submit()">
        <option value="">— All Departments —</option>
        <?php foreach ($deptList as $d): ?>
          <option value="<?= h($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= h($d) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div>
      <label>Employee <?= $filterDept !== '' ? '(' . h($filterDept) . ')' : '(All Departments)' ?></label>
      <select name="employee_id" onchange="this.form.submit()">
        <option value="">— Select an Employee —</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= h($e['EmployeeID']) ?>" <?= $empId === (string)$e['EmployeeID'] ? 'selected' : '' ?>>
            <?= h($e['EmployeeName']) ?> — <?= h($e['EmployeeID']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="sc-btn"><i class="bi bi-calendar3"></i> View Calendar</button>
  </form>

  <?php if ($empId === ''): ?>

    <div class="sc-card">
      <div class="sc-empty">
        <i class="bi bi-person-lines-fill"></i>
        Select an employee above to view their schedule calendar.
      </div>
    </div>

  <?php else: ?>

    <div class="sc-card">
      <div class="sc-card-head">
        <span>📅 Schedule Calendar</span>
      </div>

      <div class="sc-cal-legend">
        <span><i class="sc-dot sc-dot-oneday"></i> One Day</span>
        <span><i class="sc-dot sc-dot-every"></i> Every (recurring weekday)</span>
        <span><i class="sc-dot sc-dot-range"></i> Range</span>
        <span><i class="sc-dot sc-dot-none"></i> No override</span>
      </div>

      <?php
        $navBase = ['employee_id' => $empId];
        if ($canFilterDept) { $navBase['dept'] = $filterDept; }
      ?>
      <div class="sc-cal-nav">
        <a href="?<?= h(http_build_query($navBase + ['year' => $prevYear, 'month' => $prevMonth])) ?>">
          <i class="bi bi-chevron-left"></i> <?= h(date('M Y', $prevStamp)) ?>
        </a>
        <div class="sc-cal-nav-title"><?= h($monthLabel) ?></div>
        <a href="?<?= h(http_build_query($navBase + ['year' => $nextYear, 'month' => $nextMonth])) ?>">
          <?= h(date('M Y', $nextStamp)) ?> <i class="bi bi-chevron-right"></i>
        </a>
      </div>

      <div class="sc-cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dowLbl): ?>
          <div class="sc-cal-dow"><?= $dowLbl ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $startDow; $i++): ?>
          <div class="sc-cal-cell sc-cal-pad"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $st = scDayStatus($dateStr, $oneDayMap, $rangeList, $everyMap);
            $cls = 'sc-cal-cell sc-cal-' . $st['status'];
            $title = date('M j, Y', strtotime($dateStr));
            $shiftLines = ($st['status'] !== 'none') ? scShiftLines($st['row']) : [];
            $title .= ($st['status'] !== 'none')
                ? ' — ' . ucfirst($st['status']) . ' · ' . scScheduleTooltip($st['row'])
                : ' — No override';
        ?>
          <div class="<?= $cls ?>" title="<?= h($title) ?>">
            <div class="sc-cal-head-row">
              <div class="sc-cal-daynum"><?= $day ?></div>
              <?php if ($st['status'] === 'oneday'): ?>
                <span class="sc-cal-badge sc-cal-badge-oneday"><i class="bi bi-circle-fill" style="font-size:.45em;"></i> 1-Day</span>
              <?php elseif ($st['status'] === 'every'): ?>
                <span class="sc-cal-badge sc-cal-badge-every"><i class="bi bi-arrow-repeat"></i> Every</span>
              <?php elseif ($st['status'] === 'range'): ?>
                <span class="sc-cal-badge sc-cal-badge-range"><i class="bi bi-arrow-left-right"></i> Range</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($shiftLines)): ?>
              <div class="sc-cal-shift">
                <?php foreach ($shiftLines as $line): ?>
                  <div class="sc-shift-row"><span class="sc-shift-lbl"><?= h($line['label']) ?></span><?= h($line['text']) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>

  <?php endif; ?>

</div>

</body>
</html>