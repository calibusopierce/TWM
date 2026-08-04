<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'attendance');

// ── Session context ────────────────────────────────────────
$sessionDept = trim($_SESSION['Department'] ?? '');
$empId       = $_SESSION['EmployeeID'] ?? '';
$userLevel   = $_SESSION['userlevel']  ?? '';
$isHR        = ($userLevel === 'Admin' || $userLevel === 'HR');

// Session Department can be a real department name, or a sentinel value
// (e.g. "ALL") for staff who oversee every department. Only those users
// get to see/use the department picker — everyone else is locked to
// their own department, no dropdown, no override.
$allDeptSentinels  = ['', 'all', 'all department', 'all departments', '*'];
$isAllDeptSession  = in_array(strtolower($sessionDept), $allDeptSentinels, true);
$canFilterDept     = $isHR || $isAllDeptSession;

// ── Date & dept filters ────────────────────────────────────
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : $today;
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : $today;

// Effective department scope:
// - Session-locked users: always their own department, GET is ignored entirely.
// - "All departments" users: free to pick from the dropdown (blank = all).
if ($canFilterDept) {
    $filterDept = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : '';
} else {
    $filterDept = $sessionDept;
}

$dateFromSafe   = str_replace("'", "''", $dateFrom);
$dateToSafe     = str_replace("'", "''", $dateTo);
$empIdSafe      = str_replace("'", "''", $empId);
$filterDeptSafe = str_replace("'", "''", $filterDept);

// ── Dept dropdown list (only needed for "all departments" users) ──
$deptList = [];
if ($canFilterDept) {
    $dStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Department) AS Department FROM View_ATtendanceTimeInTimeOut WHERE Department IS NOT NULL AND Department <> '' ORDER BY Department");
    if ($dStmt) { while ($dr = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) { $deptList[] = $dr['Department']; } sqlsrv_free_stmt($dStmt); }
}

// ── Active tab ─────────────────────────────────────────────
$validTabs = ['timeinout', 'devicelog', 'toplates', 'absents', 'generated', 'calendar'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : 'timeinout';

// ── Category filter ──────────────────────────────────────────
// $filterCategory is shared across tabs: the Generated Attendance tab uses
// it against View_AttendanceRecord (see $categoryList/$extraFilters below),
// and the Time In/Out tab uses it against View_ATtendanceTimeInTimeOut2
// (see $timeinoutCategoryList / $catFilter below). Cut off/Branch/Employee
// Status remain 'generated'-tab-only.
$filterCutOff    = isset($_GET['cutoff'])    && $_GET['cutoff']    !== '' ? trim($_GET['cutoff'])    : '';
$filterCategory  = isset($_GET['category'])  && $_GET['category']  !== '' ? trim($_GET['category'])  : '';
$filterBranch    = isset($_GET['branch'])    && $_GET['branch']    !== '' ? trim($_GET['branch'])    : '';
$filterEmpStatus = isset($_GET['empstatus']) && $_GET['empstatus'] !== '' ? trim($_GET['empstatus']) : '';

$filterCutOffSafe    = str_replace("'", "''", $filterCutOff);
$filterCategorySafe  = str_replace("'", "''", $filterCategory);
$filterBranchSafe    = str_replace("'", "''", $filterBranch);
$filterEmpStatusSafe = str_replace("'", "''", $filterEmpStatus);

// Category dropdown for the Time In/Out tab — sourced from the same view
// that tab's query uses (View_ATtendanceTimeInTimeOut2), independent of
// the Generated Attendance tab's $categoryList below.
$timeinoutCategoryList = [];
if ($tab === 'timeinout') {
    $tcStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Category) AS Category FROM View_ATtendanceTimeInTimeOut2 WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category");
    if ($tcStmt) { while ($tr = sqlsrv_fetch_array($tcStmt, SQLSRV_FETCH_ASSOC)) { $timeinoutCategoryList[] = $tr['Category']; } sqlsrv_free_stmt($tcStmt); }
}

// Dropdown option lists — only queried when the tab is actually open,
// same lazy pattern as $deptList above.
$cutOffList = $categoryList = $branchList = $empStatusList = [];
if ($tab === 'generated') {
    $cStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(CutOff) AS V FROM View_AttendanceRecord WHERE CutOff IS NOT NULL AND CutOff <> '' ORDER BY V");
    if ($cStmt) { while ($r = sqlsrv_fetch_array($cStmt, SQLSRV_FETCH_ASSOC)) { $cutOffList[] = $r['V']; } sqlsrv_free_stmt($cStmt); }

    $catStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Category) AS V FROM View_AttendanceRecord WHERE Category IS NOT NULL AND Category <> '' ORDER BY V");
    if ($catStmt) { while ($r = sqlsrv_fetch_array($catStmt, SQLSRV_FETCH_ASSOC)) { $categoryList[] = $r['V']; } sqlsrv_free_stmt($catStmt); }

    $brStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Branch) AS V FROM View_AttendanceRecord WHERE Branch IS NOT NULL AND Branch <> '' ORDER BY V");
    if ($brStmt) { while ($r = sqlsrv_fetch_array($brStmt, SQLSRV_FETCH_ASSOC)) { $branchList[] = $r['V']; } sqlsrv_free_stmt($brStmt); }

    $esStmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(Employee_Status) AS V FROM View_AttendanceRecord WHERE Employee_Status IS NOT NULL AND Employee_Status <> '' ORDER BY V");
    if ($esStmt) { while ($r = sqlsrv_fetch_array($esStmt, SQLSRV_FETCH_ASSOC)) { $empStatusList[] = $r['V']; } sqlsrv_free_stmt($esStmt); }
}

// ── Dept WHERE clause ──────────────────────────────────────
function dc(string $deptSafe, string $col = 'Department'): string {
    return $deptSafe !== '' ? "AND RTRIM($col) = '$deptSafe'" : '';
}

// ── Fetch ALL rows for the active tab (for search + pagination) ─
// We load everything into PHP arrays, JSON-encode into JS, and let
// the client handle pagination + search without extra round-trips.
$rows      = []; // Device Time In/Out summary (timeinout tab)
$logRows   = []; // Integrated attendance log — 3 sources merged (devicelog tab)
$lateRows  = []; // All late-arrival records, sorted by minutes late (toplates tab)
$absentRows = []; // Absentee list for the selected day (absents tab)
$genRows   = []; // Generated attendance record, dept-scoped (generated tab)

function fetchAll($stmt): array {
    $out = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($r as $k => $v) { if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s'); }
            $out[] = $r;
        }
        sqlsrv_free_stmt($stmt);
    } else {
        error_log('TWM attendance.php SQL error: ' . print_r(sqlsrv_errors(), true));
    }
    return $out;
}

$debugLog = []; // tab => sqlsrv_errors() when a query fails, surfaced in an HTML comment for troubleshooting

if ($tab === 'timeinout') {
    $dc = dc($filterDeptSafe);
    $catFilter = $filterCategorySafe !== '' ? " AND RTRIM(Category) = '$filterCategorySafe'" : '';
    $timeinoutStmt = sqlsrv_query($conn, "
    SELECT EmployeeID, Department, EmployeeName, Category, ADate,
           MorningIn, MorningOut, AfternoonIn, AfternoonOut, TimeIn, TimeInPM,
           AMLate, PMLate, Late1, MorningTotalHours, AfternoonTotalHours,
           MorningAfternoonTotal, TotalHours
    FROM View_ATtendanceTimeInTimeOut2
    WHERE ADate BETWEEN '$dateFromSafe' AND '$dateToSafe' $dc $catFilter
    ORDER BY ADate DESC, MorningIn DESC
");
    if ($timeinoutStmt === false) { $debugLog['timeinout'] = sqlsrv_errors(); }
    $rows = fetchAll($timeinoutStmt);

} elseif ($tab === 'devicelog') {
    $dc = dc($filterDeptSafe);
    $logRows = fetchAll(sqlsrv_query($conn, "
        SELECT DataFrom, Department, EmployeeID, EmployeeName, Position_Held, ADate, CheckIn, ATime, Area, Category, Direction, Late
        FROM View_Attendance_Log2
        WHERE ADate BETWEEN '$dateFromSafe' AND '$dateToSafe' $dc
        ORDER BY ADate DESC, ATime DESC
    "));

} elseif ($tab === 'toplates') {
    // All late-arrival records in the date range, sorted by minutes late (highest first).
    // Sourced from View_ATtendanceTimeInTimeOut2 — the SAME view the Time In/Out tab
    // uses — so the two tabs' Late figures always agree. (Previously sourced from the
    // merged 3-source Integrated Log, View_Attendance_Log2, which used a different Late
    // computation and could disagree with the Time In/Out tab.) Note this view doesn't
    // carry Position_Held or per-punch source info, so "Morning In" stands in as the
    // reference punch instead of the old "Earliest Integrated Log Punch + Source".
    $dc = dc($filterDeptSafe);
    $lateRows = fetchAll(sqlsrv_query($conn, "
        SELECT EmployeeID, Department, EmployeeName, Category, ADate,
               MorningIn, AMLate, PMLate, Late1
        FROM View_ATtendanceTimeInTimeOut2
        WHERE ADate BETWEEN '$dateFromSafe' AND '$dateToSafe' AND Late1 > 0 $dc
        ORDER BY Late1 DESC
    "));
    foreach ($lateRows as $i => $r) { $lateRows[$i]['Rank'] = $i + 1; }

} elseif ($tab === 'absents') {
    // Absentee list across the whole selected date range (one row per employee per absent day).
    $dc = dc($filterDeptSafe, 'E.Department');
    $absentRows = fetchAll(sqlsrv_query($conn, "
        ;WITH DateSeries AS (
            SELECT CAST('$dateFromSafe' AS DATE) AS ADate
            UNION ALL
            SELECT DATEADD(DAY, 1, ADate) FROM DateSeries WHERE ADate < CAST('$dateToSafe' AS DATE)
        )
        SELECT E.Department, E.EmployeeID, E.FirstName + ' ' + E.LastName AS EmployeeName,
               E.Position_held, DS.ADate
        FROM DateSeries DS
        CROSS JOIN dbo.TBL_HREmployeeList E
        LEFT OUTER JOIN dbo.View_Attendance_Record_Daily A
            ON E.EmployeeID = A.EmployeeID AND CAST(A.ADate AS DATE) = DS.ADate
        WHERE E.Active = 1 AND E.System = 0 AND A.EmployeeID IS NULL
              AND DATENAME(WEEKDAY, DS.ADate) <> 'Sunday' $dc
        ORDER BY DS.ADate, EmployeeName
        OPTION (MAXRECURSION 366)
    "));

} elseif ($tab === 'generated') {
    // Dept-scoped, not employee-scoped — same $filterDept / dc() pattern as
    // every other tab on this page. Session-locked users only ever see their
    // own department; "all departments" HR/Admin users can pick or see all.
    $dc = dc($filterDeptSafe);

    $extraFilters = '';
    if ($filterCutOffSafe    !== '') $extraFilters .= " AND RTRIM(CutOff) = '$filterCutOffSafe'";
    if ($filterCategorySafe  !== '') $extraFilters .= " AND RTRIM(Category) = '$filterCategorySafe'";
    if ($filterBranchSafe    !== '') $extraFilters .= " AND RTRIM(Branch) = '$filterBranchSafe'";
    if ($filterEmpStatusSafe !== '') $extraFilters .= " AND RTRIM(Employee_Status) = '$filterEmpStatusSafe'";

    $genRows = fetchAll(sqlsrv_query($conn, "
        SELECT
            EmployeeID,
            NULLIF(LTRIM(RTRIM(ISNULL(FirstName,'') + ' ' + ISNULL(LastName,''))), '') AS EmployeeName,
            Department, Adate, Aday, AtimeIn, AtimeOut, Late, Position_held, Job_tittle,
            Category, Employee_Status, Branch, Area, CutOff, SetTime, Remarks
        FROM View_AttendanceRecord
        WHERE Adate BETWEEN '$dateFromSafe' AND '$dateToSafe' $dc $extraFilters
        ORDER BY Adate DESC, EmployeeName
    "));
}

// ── Calendar tab: dept-scoped employee picker + per-employee calendar ──
// "Under their own department" = same $filterDept scoping every other tab
// already uses (session-locked users only ever see their own dept; HR/
// "all departments" users get whatever the top filter bar has selected).
$calEmpList = [];
$calEmpId   = '';
$calEmpName = '';
$calAttendanceMap = [];
$calLeaveMap      = [];
$calHoursMap      = [];
$calLateMap       = [];
$calLateCount        = 0;
$calLateMinutesTotal = 0;

if ($tab === 'calendar') {
    $dc = dc($filterDeptSafe);
    $ceStmt = sqlsrv_query($conn, "
        SELECT EmployeeID, LTRIM(RTRIM(ISNULL(FirstName,'') + ' ' + ISNULL(LastName,''))) AS EmployeeName, Department
        FROM dbo.TBL_HREmployeeList
        WHERE Active = 1 AND System = 0 $dc
        ORDER BY EmployeeName
    ");
    if ($ceStmt) { while ($r = sqlsrv_fetch_array($ceStmt, SQLSRV_FETCH_ASSOC)) { $calEmpList[] = $r; } sqlsrv_free_stmt($ceStmt); }
    else { error_log('TWM attendance.php calEmpList SQL error: ' . print_r(sqlsrv_errors(), true)); }

    $calEmpId = isset($_GET['cal_emp']) ? trim($_GET['cal_emp']) : '';
    // Only trust cal_emp if it's actually in this user's dept-scoped list —
    // stops someone hand-editing the URL to peek at another department.
    $calEmpValid = false;
    foreach ($calEmpList as $ce) {
        if ($ce['EmployeeID'] === $calEmpId) { $calEmpName = $ce['EmployeeName']; $calEmpValid = true; break; }
    }
    if (!$calEmpValid) $calEmpId = '';

    if ($calEmpId !== '') {
        $calEmpIdSafe = str_replace("'", "''", $calEmpId);

        // DayCount only — Present/Halfday/Absent status. Late minutes are
        // merged in from View_ATtendanceTimeInTimeOut2 below (same view the
        // Time In/Out tab uses), so the two tabs always agree on lates.
        $attStmt = sqlsrv_query($conn, "
            SELECT ADate, DayCount
            FROM View_Attendance_Record_Daily
            WHERE EmployeeID = '$calEmpIdSafe' AND ADate BETWEEN '$dateFromSafe' AND '$dateToSafe'
        ");
        if ($attStmt) {
            while ($ar = sqlsrv_fetch_array($attStmt, SQLSRV_FETCH_ASSOC)) {
                $d = $ar['ADate']; if ($d instanceof DateTime) $d = $d->format('Y-m-d');
                $d = substr((string)$d, 0, 10);
                $calAttendanceMap[$d] = ['daycount' => (float)($ar['DayCount'] ?? 0), 'late' => 0];
            }
            sqlsrv_free_stmt($attStmt);
        } else {
            $debugLog['calendar'] = sqlsrv_errors();
            error_log('TWM attendance.php cal attStmt SQL error: ' . print_r(sqlsrv_errors(), true));
        }

        // Late minutes + TotalHours — sourced from View_ATtendanceTimeInTimeOut2,
        // the SAME view + Late1 column the Time In/Out tab uses, so the calendar's
        // late figures always agree with what's shown there. (Previously this came
        // from the Integrated Log, which uses a different Late computation and could
        // disagree with the Time In/Out tab.)
        $hrsStmt = sqlsrv_query($conn, "
            SELECT ADate, TotalHours, Late1
            FROM View_ATtendanceTimeInTimeOut2
            WHERE EmployeeID = '$calEmpIdSafe' AND ADate BETWEEN '$dateFromSafe' AND '$dateToSafe'
        ");
        if ($hrsStmt) {
            while ($hr = sqlsrv_fetch_array($hrsStmt, SQLSRV_FETCH_ASSOC)) {
                $d = $hr['ADate']; if ($d instanceof DateTime) $d = $d->format('Y-m-d');
                $d = substr((string)$d, 0, 10);
                $calHoursMap[$d] = (float)($hr['TotalHours'] ?? 0);
                $late = (int)($hr['Late1'] ?? 0);
                $calLateMap[$d] = $late;
                if (isset($calAttendanceMap[$d])) {
                    $calAttendanceMap[$d]['late'] = $late;
                }
            }
            sqlsrv_free_stmt($hrsStmt);
            $debugLog['calendar_attendance_map'] = $calAttendanceMap; // raw DayCount + merged Late1 per date, for troubleshooting
        } else {
            error_log('TWM attendance.php cal hrsStmt SQL error: ' . print_r(sqlsrv_errors(), true));
        }

        // Same approval-gate assumption as my_attendance.php: both SA_Status
        // and HR_Status must be 'Approved'. Adjust here too if that changes.
        $leaveStmt = sqlsrv_query($conn, "
            SELECT Date_Start, Date_End, HalfDay, ReasonOfLeave
            FROM Tbl_Leave_Application
            WHERE EmployeeID = '$calEmpIdSafe'
              AND SA_Status = 'Approved' AND HR_Status = 'Approved'
              AND Date_Start <= '$dateToSafe' AND Date_End >= '$dateFromSafe'
        ");
        if ($leaveStmt) {
            while ($lv = sqlsrv_fetch_array($leaveStmt, SQLSRV_FETCH_ASSOC)) {
                $ds = $lv['Date_Start']; if ($ds instanceof DateTime) $ds = $ds->format('Y-m-d');
                $de = $lv['Date_End'];   if ($de instanceof DateTime) $de = $de->format('Y-m-d');
                $ds = max(substr((string)$ds, 0, 10), $dateFrom);
                $de = min(substr((string)$de, 0, 10), $dateTo);
                $cursor = strtotime($ds); $end = strtotime($de);
                while ($cursor !== false && $cursor <= $end) {
                    $calLeaveMap[date('Y-m-d', $cursor)] = [
                        'reason'  => trim($lv['ReasonOfLeave'] ?? ''),
                        'halfday' => (int)($lv['HalfDay'] ?? 0),
                    ];
                    $cursor = strtotime('+1 day', $cursor);
                }
            }
            sqlsrv_free_stmt($leaveStmt);
        } else { error_log('TWM attendance.php cal leaveStmt SQL error: ' . print_r(sqlsrv_errors(), true)); }

        // Late Minutes stat totals — walked through the same calDayStatus()
        // resolver the calendar cells use below, so the number here always
        // matches what's actually shown as "late" on the calendar (a leave
        // day, for instance, shouldn't inflate this total even if it has a
        // nonzero Late value in $calLateMap).
        $calLateCursor = strtotime($dateFrom);
        $calLateEnd    = min(strtotime($dateTo), strtotime($today));
        while ($calLateCursor !== false && $calLateCursor <= $calLateEnd) {
            $dKey = date('Y-m-d', $calLateCursor);
            $st   = calDayStatus($dKey, $today, $calAttendanceMap, $calLeaveMap, $calHoursMap);
            if (($st['status'] === 'present' || $st['status'] === 'halfday') && !empty($st['late'])) {
                $calLateCount++;
                $calLateMinutesTotal += $st['late'];
            }
            $calLateCursor = strtotime('+1 day', $calLateCursor);
        }
    }
}

// Priority: Sunday > future(unknown) > Leave > Present/Half-day(+Late) > Absent
function calDayStatus(string $dateStr, string $today, array $attendanceMap, array $leaveMap, array $hoursMap = []): array {
    $dow = (int)date('w', strtotime($dateStr)); // 0 = Sunday
    if ($dow === 0) return ['status' => 'sunday'];
    if ($dateStr > $today) return ['status' => 'future'];
    if (isset($leaveMap[$dateStr])) {
        return ['status' => 'leave', 'reason' => $leaveMap[$dateStr]['reason'], 'halfday' => $leaveMap[$dateStr]['halfday']];
    }
    if (isset($attendanceMap[$dateStr])) {
        $dc = $attendanceMap[$dateStr]['daycount'];
        $late = $attendanceMap[$dateStr]['late'];
        $hours = $hoursMap[$dateStr] ?? null;
        if ($dc >= 1) return ['status' => 'present', 'late' => $late, 'hours' => $hours];
        if ($dc > 0)  return ['status' => 'halfday', 'late' => $late, 'hours' => $hours];
    }
    return ['status' => 'absent'];
}
function calMonths(string $dateFrom, string $dateTo): array {
    $months = [];
    $cursor = strtotime(date('Y-m-01', strtotime($dateFrom)));
    $end    = strtotime(date('Y-m-01', strtotime($dateTo)));
    while ($cursor <= $end) {
        $months[] = ['y' => (int)date('Y', $cursor), 'm' => (int)date('n', $cursor), 'label' => date('F Y', $cursor)];
        $cursor = strtotime('+1 month', $cursor);
    }
    return $months;
}

// ── Helpers ────────────────────────────────────────────────
function fmtTime(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('h:i a', $t) : '—';
}
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    $t = strtotime($dt);
    return $t ? date('M d, Y', $t) : '—';
}
function dirBadge(string $dir): string {
    $dir = strtolower(trim($dir));
    if (str_contains($dir, 'in'))  return '<span class="hr-badge hr-badge-in">⬇ In</span>';
    if (str_contains($dir, 'out')) return '<span class="hr-badge hr-badge-out">⬆ Out</span>';
    return '<span class="hr-badge">' . htmlspecialchars($dir) . '</span>';
}
function lateBadge($late): string {
    if (!$late || $late === '0' || $late === 0) return '<span class="hr-badge hr-badge-present">On Time</span>';
    return '<span class="hr-badge hr-badge-late"><i class="bi bi-clock-fill" style="margin-right:.35rem;"></i>Late</span>';
}
function tabUrl(string $t): string {
    $p = $_GET; $p['tab'] = $t; unset($p['page']);
    return '?' . http_build_query($p);
}
function deptLabel(string $d): string {
    return $d !== '' ? htmlspecialchars($d) : 'All Departments';
}
// Work Status for the calendar cell — mirrors the JS workStatusBadge()
// thresholds used on the Time In/Out tab:
//   > 8.3          -> Overtime
//   8.0 – 8.3      -> Regular
//   > 4.0 and < 8  -> Undertime
//   <= 4.0         -> Halfday
function calWorkStatusBadge($hours): string {
    if ($hours === null || $hours === '') return '';
    $h = (float)$hours;
    if ($h > 8.3) {
        return '<div class="att-cal-workstatus att-cal-ws-overtime" title="Overtime"><i class="bi bi-stopwatch" style="margin-right:.3rem;"></i>OT</div>';
    }
    if ($h >= 8 && $h <= 8.3) {
        return '<div class="att-cal-workstatus att-cal-ws-regular" title="Regular"><i class="bi bi-check-circle-fill" style="margin-right:.3rem;"></i>Reg</div>';
    }
    if ($h > 4 && $h < 8) {
        return '<div class="att-cal-workstatus att-cal-ws-undertime" title="Undertime"><i class="bi bi-clock-fill" style="margin-right:.3rem;"></i>UT</div>';
    }
    return '<div class="att-cal-workstatus att-cal-ws-halfday" title="Halfday"><i class="bi bi-circle-half" style="margin-right:.3rem;"></i>½ Day</div>';
}

// JSON for JS (safe embed)
$jsRows        = json_encode($rows,        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsLogRows     = json_encode($logRows,     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsLateRows    = json_encode($lateRows,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsAbsentRows  = json_encode($absentRows,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$jsGenRows     = json_encode($genRows,     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance — HR · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ── Compact rows: dense but readable ──────────────────── */
.att-table { width:100%; border-collapse:collapse; font-size:.92rem; }
.att-table thead th {
    background:var(--surface-raised, #f1f5f9);
    color:var(--text-muted, #64748b);
    font-size:.72rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.05em;
    padding:8px 10px;
    border-bottom:2px solid var(--border, #e2e8f0);
    white-space:nowrap;
    text-align:left;
}
.att-table tbody tr { border-bottom:1px solid var(--border, #e2e8f0); transition:background .12s; }
.att-table tbody tr:hover { background:var(--surface-hover, #f8fafc); }
.att-table tbody td {
    padding:6px 10px;
    font-size:.88rem;
    color:var(--text, #1e293b);
    vertical-align:middle;
    line-height:1.3;
}
.att-table tbody td.mono { font-family:'JetBrains Mono',monospace; font-size:.85rem; }
.att-time { font-family:'JetBrains Mono',monospace; font-size:.88rem; font-weight:600; color:#0f172a; }
.att-time.absent { color:#cbd5e1; font-weight:400; }
.att-table .hr-badge { padding:3px 8px; font-size:.78rem; white-space:nowrap; }

/* ── Integrated Log: Direction badge colors ────────────── */
#logTable .hr-badge-in  { background:#dbeafe; color:#1d4ed8; }
#logTable .hr-badge-out { background:#fee2e2; color:#dc2626; }

/* ── Top Employee Lates: severity-based row highlight ──── */
#latesTable .late-row-warn { background:#fefce8; }
#latesTable .late-row-warn:hover { background:#fef9c3; }
#latesTable .late-row-danger { background:#fef2f2; }
#latesTable .late-row-danger:hover { background:#fee2e2; }
.late-badge-big {
    font-size:.85rem !important;
    font-weight:800 !important;
    padding:5px 12px !important;
    border-radius:999px;
    display:inline-block;
}

/* ── Pagination ────────────────────────────────────────── */
.att-pagination {
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 16px; border-top:1px solid var(--border,#e2e8f0);
    gap:12px; flex-wrap:wrap;
}
.att-pagination .att-page-info { font-size:.88rem; color:var(--text-muted,#64748b); }
.att-page-btns { display:flex; gap:4px; align-items:center; }
.att-page-btns button {
    border:1px solid var(--border,#e2e8f0);
    background:var(--surface,#fff);
    color:var(--text,#1e293b);
    border-radius:6px;
    padding:5px 11px;
    font-size:.88rem;
    font-weight:600;
    cursor:pointer;
    transition:background .12s, color .12s;
    min-width:34px;
}
.att-page-btns button:hover:not(:disabled) { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button.active { background:var(--primary,#2563eb); color:#fff; border-color:var(--primary,#2563eb); }
.att-page-btns button:disabled { opacity:.35; cursor:not-allowed; }
.att-page-btns .ellipsis { padding:5px 4px; font-size:.88rem; color:var(--text-muted,#64748b); }

/* ── Employee Calendar tab ─────────────────────────────── */
.att-cal-legend { display:flex; flex-wrap:wrap; gap:1.1rem; padding:.9rem 1.1rem; font-size:.78rem; color:var(--text-muted,#64748b); border-top:1px solid var(--border,#e2e8f0); border-bottom:1px solid var(--border,#e2e8f0); }
.att-cal-legend span { display:inline-flex; align-items:center; gap:.35rem; }
.att-cal-dot { width:10px; height:10px; border-radius:3px; display:inline-block; }
.att-cal-dot-present { background:#16a34a; }
.att-cal-dot-halfday { background:#0ea5e9; }
.att-cal-dot-absent  { background:#dc2626; }
.att-cal-dot-leave   { background:#eab308; }
.att-cal-dot-sunday  { background:#cbd5e1; }

.att-cal-month { padding:1.1rem; border-bottom:1px solid var(--border,#e2e8f0); }
.att-cal-month:last-child { border-bottom:none; }
.att-cal-month-title { font-weight:800; color:#0f172a; margin-bottom:.7rem; font-size:.95rem; }
.att-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; }
.att-cal-dow { text-align:center; font-size:.68rem; font-weight:700; text-transform:uppercase; color:#94a3b8; padding-bottom:2px; }

.att-cal-cell {
    position:relative; min-height:64px; border-radius:8px; padding:5px 6px;
    border:1px solid var(--border,#e2e8f0); background:#fff;
    display:flex; flex-direction:column; gap:2px;
}
.att-cal-daynum { font-size:.74rem; font-weight:700; color:#334155; }
.att-cal-pad { visibility:hidden; }
.att-cal-unfiltered { background:#f8fafc; opacity:.45; }
.att-cal-unfiltered .att-cal-daynum { color:#cbd5e1; }

.att-cal-future { background:#fff; }
.att-cal-sunday { background:#f1f5f9; }
.att-cal-sunday .att-cal-daynum { color:#94a3b8; }
.att-cal-present { background:#f0fdf4; border-color:#bbf7d0; }
.att-cal-halfday { background:#f0f9ff; border-color:#bae6fd; }
.att-cal-absent  { background:#fef2f2; border-color:#fecaca; }
.att-cal-leave   { background:#fefce8; border-color:#fde68a; }

.att-cal-mark { font-size:.72rem; font-weight:800; display:flex; align-items:center; gap:3px; }
.att-cal-mark-present { color:#16a34a; }
.att-cal-mark-halfday { color:#0ea5e9; }
.att-cal-mark-absent  { color:#dc2626; }
.att-cal-mark-leave   { color:#a16207; }
.att-cal-mark-sunday  { color:#94a3b8; }
.att-cal-late { font-size:.63rem; font-weight:700; color:#ca8a04; line-height:1.2; }
.att-cal-workstatus { font-size:.6rem; font-weight:700; line-height:1.2; }
.att-cal-ws-overtime  { color:#2563eb; }
.att-cal-ws-regular   { color:#16a34a; }
.att-cal-ws-undertime { color:#7c3aed; }
.att-cal-ws-halfday   { color:#f97316; }

@media (max-width:640px) {
    .att-cal-cell { min-height:48px; padding:3px 4px; }
    .att-cal-mark { font-size:.6rem; }
    .att-cal-late { font-size:.55rem; }
    .att-cal-workstatus { font-size:.55rem; }
}
</style>
</head>
<body>
<?php if (!empty($debugLog)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r($debugLog, true)) ?>
-->
<?php endif; ?>

<?php if (!empty($debugLog)): ?>
<!-- TWM DEBUG (remove once fixed):
<?= htmlspecialchars(print_r($debugLog, true)) ?>
-->
<?php endif; ?>

<?php
$topbar_page = 'hr_attendance';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'attendance';
require_once __DIR__ . '/hr_nav.php';
?>

<!-- ── Page Header ───────────────────────────────────────── -->
<div class="hr-page-header">
  <div>
    <div class="hr-page-title">🕐 <span style="color:#2563eb;">Attendance</span> Monitoring</div>
    <div class="hr-page-badge">
      📁 <?= deptLabel($filterDept) ?>
      · <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?>
    </div>
  </div>
  <?php if ($isHR): ?>
  <a href="<?= base_url('HR/visual-attendance.php') ?>" class="hr-btn hr-btn-ghost">
    <i class="bi bi-camera-fill"></i> Visual Attendance
  </a>
  <?php endif; ?>
</div>

<!-- ── Filter Bar ────────────────────────────────────────── -->
<form method="GET" action="">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
  <div class="hr-filter-bar">
    <div class="hr-filter-group">
      <label>Date From</label>
      <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
    </div>
    <div class="hr-filter-group">
      <label>Date To</label>
      <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
    </div>
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
    <?php if ($tab === 'timeinout'): ?>
    <div class="hr-filter-group">
      <label>Category</label>
      <select name="category" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:150px;">
        <option value="">— All —</option>
        <?php foreach ($timeinoutCategoryList as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= ($filterCategory === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($tab === 'generated'): ?>
    <div class="hr-filter-group">
      <label>Cut Off</label>
      <select name="cutoff" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($cutOffList as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= ($filterCutOff === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="hr-filter-group">
      <label>Category</label>
      <select name="category" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($categoryList as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= ($filterCategory === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="hr-filter-group">
      <label>Branch</label>
      <select name="branch" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($branchList as $b): ?>
        <option value="<?= htmlspecialchars($b) ?>" <?= ($filterBranch === $b) ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="hr-filter-group">
      <label>Employee Status</label>
      <select name="empstatus" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:130px;">
        <option value="">— All —</option>
        <?php foreach ($empStatusList as $e): ?>
        <option value="<?= htmlspecialchars($e) ?>" <?= ($filterEmpStatus === $e) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="hr-btn hr-btn-primary">
      <i class="bi bi-funnel-fill"></i> Filter
    </button>
    <a href="?tab=<?= $tab ?>" class="hr-btn hr-btn-ghost">
      <i class="bi bi-x-circle"></i> Reset
    </a>
  </div>
</form>

<!-- ── Tab Nav ───────────────────────────────────────────── -->
<div class="hr-tab-nav">
  <a href="<?= tabUrl('timeinout') ?>" class="<?= $tab === 'timeinout' ? 'active' : '' ?>">
    <i class="bi bi-clock-history"></i> Time In / Out
    <?php if ($tab === 'timeinout'): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;"><?= count($rows) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= tabUrl('devicelog') ?>" class="<?= $tab === 'devicelog' ? 'active' : '' ?>">
    <i class="bi bi-layers-fill"></i> Integrated Log
    <?php if ($tab === 'devicelog'): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;"><?= count($logRows) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= tabUrl('toplates') ?>" class="<?= $tab === 'toplates' ? 'active' : '' ?>">
    <i class="bi bi-clock-fill"></i> Top Employee Lates
    <?php if ($tab === 'toplates'): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;"><?= count($lateRows) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= tabUrl('absents') ?>" class="<?= $tab === 'absents' ? 'active' : '' ?>">
    <i class="bi bi-person-x-fill"></i> Absents
    <?php if ($tab === 'absents'): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;"><?= count($absentRows) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= tabUrl('generated') ?>" class="<?= $tab === 'generated' ? 'active' : '' ?>">
    <i class="bi bi-file-earmark-text-fill"></i> Generated Attendance
    <?php if ($tab === 'generated'): ?>
    <span style="background:rgba(255,255,255,.25);border-radius:999px;padding:0 6px;font-size:.68rem;font-weight:700;"><?= count($genRows) ?></span>
    <?php endif; ?>
  </a>
  <a href="<?= tabUrl('calendar') ?>" class="<?= $tab === 'calendar' ? 'active' : '' ?>">
    <i class="bi bi-calendar3"></i> Calendar
  </a>
</div>

<?php if ($tab === 'timeinout'): ?>

<!-- ── Device Time In/Out ─────────────────────────────────── -->
<div class="hr-table-card" style="margin-bottom:1.25rem;">
  <div class="att-cal-legend" style="border-top:none;">
    <span><i class="bi bi-check-circle-fill" style="color:#16a34a;"></i> Present</span>
    <span><i class="bi bi-x-circle-fill" style="color:#dc2626;"></i> Absent</span>
    <span><i class="bi bi-stopwatch" style="color:#2563eb;"></i> Overtime</span>
    <span><i class="bi bi-clock-fill" style="color:#ca8a04;"></i> Late</span>
    <span><i class="bi bi-clock-fill" style="color:#7c3aed;"></i> Undertime</span>
    <span><i class="bi bi-dash-circle-fill" style="color:#6b7280;"></i> Incomplete</span>
    <span><i class="bi bi-circle-half" style="color:#f97316;"></i> Halfday</span>
  </div>
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      🖥️ Device Time In / Out
      <span class="hr-table-count" id="devCount"><?= count($rows) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="devSearch" placeholder="Search all records…" oninput="tableSearch('dev')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('dev', 'device_timeinout', ['EmployeeID','Department'])">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('dev', 'Device Time In / Out')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="att-table" id="devTable">
      <thead>
        <tr>
          <th>Name</th><th>Category</th><th>Date</th>
          <th>Sched. AM In</th><th>AM In</th><th>AM Out</th><th>AM Hours</th><th>AM Late</th>
          <th>Sched. PM In</th><th>PM In</th><th>PM Out</th><th>PM Hours</th><th>PM Late</th>
          <th>Total Hours</th><th>Total Late</th><th>Work Status</th>
        </tr>
      </thead>
      <tbody id="devBody"></tbody>
    </table>
    <div id="devEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
  </div>
  <div class="att-pagination" id="devPager"></div>
</div>

<div class="hr-table-card" style="margin-top:1rem;background:var(--surface-raised,#f8fafc);border:1px dashed var(--border,#e2e8f0);">
  <div style="display:flex;align-items:center;gap:.6rem;padding:.4rem 0;font-size:.85rem;color:var(--text-muted,#64748b);">
    <i class="bi bi-layers-fill" style="font-size:1.2rem;color:#2563eb;"></i>
    Looking for per-punch detail (device, portal, biometric)? See the <a href="<?= tabUrl('devicelog') ?>" style="font-weight:600;">Integrated Log</a> tab.
  </div>
</div>

<?php elseif ($tab === 'devicelog'): ?>

<!-- ── Integrated Attendance Log (device + portal + biometric) ── -->
<div class="hr-table-card">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      🔗 Integrated Attendance Log
      <span class="hr-table-count" id="logCount"><?= count($logRows) ?> entries</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="logSearch" placeholder="Search all records…" oninput="tableSearch('log')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('log', 'integrated_attendance_log')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('log', 'Integrated Attendance Log')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="att-table" id="logTable">
      <thead>
        <tr>
          <th>Employee ID</th><th>Name</th><th>Department</th><th>Position Held</th><th>Area</th><th>Date</th>
          <th>Check In</th><th>Time</th><th>Category</th><th>Direction</th><th>Late</th><th>Source</th>
        </tr>
      </thead>
      <tbody id="logBody"></tbody>
    </table>
    <div id="logEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No entries found.</p></div>
  </div>
  <div class="att-pagination" id="logPager"></div>
</div>

<?php elseif ($tab === 'toplates'): ?>

<div class="hr-table-card" style="margin-bottom:1rem;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border:1px solid #bfdbfe;">
  <div style="display:flex;align-items:center;gap:.6rem;padding:.25rem 0;font-size:.85rem;color:#1e40af;">
    <i class="bi bi-info-circle-fill" style="font-size:1.3rem;color:#2563eb;"></i>
    All late-arrival records, sorted by minutes late (highest first), within <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?>.
    Sourced from the same view as the Time In/Out tab, so figures here always match what's shown there.
  </div>
</div>

<!-- ── Top Employee Lates ─────────────────────────────────── -->
<div class="hr-table-card">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      ⏰ Employee Lates
      <span class="hr-table-count" id="latesCount"><?= count($lateRows) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="latesSearch" placeholder="Search…" oninput="tableSearch('lates')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('lates', 'top_employee_lates')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('lates', 'Top Employee Lates')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="att-table" id="latesTable">
      <thead>
        <tr>
          <th>Rank</th><th>Date</th><th>Employee ID</th><th>Name</th><th>Department</th><th>Category</th><th>Morning In</th><th>AM Late</th><th>PM Late</th><th>Total Late (mins)</th>
        </tr>
      </thead>
      <tbody id="latesBody"></tbody>
    </table>
    <div id="latesEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No late records found for this range.</p></div>
  </div>
  <div class="att-pagination" id="latesPager"></div>
</div>

<?php elseif ($tab === 'absents'): ?>

<div class="hr-table-card" style="margin-bottom:1rem;background:linear-gradient(135deg,#eff6ff,#f0f9ff);border:1px solid #bfdbfe;">
  <div style="display:flex;align-items:center;gap:.6rem;padding:.25rem 0;font-size:.85rem;color:#1e40af;">
    <i class="bi bi-info-circle-fill" style="font-size:1.3rem;color:#2563eb;"></i>
    Showing employees absent between <?= htmlspecialchars($dateFrom) ?> and <?= htmlspecialchars($dateTo) ?> (one row per employee per absent day).
  </div>
</div>

<!-- ── Absents ────────────────────────────────────────────── -->
<div class="hr-table-card">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      🚫 Absent Employees
      <span class="hr-table-count" id="absentCount"><?= count($absentRows) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="absentSearch" placeholder="Search…" oninput="tableSearch('absent')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('absent', 'absent_employees')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('absent', 'Absent Employees')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="att-table" id="absentTable">
      <thead>
        <tr>
          <th>Employee ID</th><th>Name</th><th>Department</th><th>Position</th><th>Date</th>
        </tr>
      </thead>
      <tbody id="absentBody"></tbody>
    </table>
    <div id="absentEmpty" class="hr-empty" style="display:none;"><span class="icon">🎉</span><p>No absences — full attendance for this day.</p></div>
  </div>
  <div class="att-pagination" id="absentPager"></div>
</div>

<?php elseif ($tab === 'generated'): ?>

<!-- ── Generated Attendance ───────────────────────────────── -->
<div class="hr-table-card">
  <div class="hr-table-toolbar">
    <div class="hr-table-title">
      📋 Generated Attendance
      <span class="hr-table-count" id="genCount"><?= count($genRows) ?> records</span>
    </div>
    <div class="hr-table-actions">
      <div class="hr-search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="genSearch" placeholder="Search all records…" oninput="tableSearch('gen')">
      </div>
      <button class="hr-btn hr-btn-ghost" onclick="exportCSV('gen', 'generated_attendance')">
        <i class="bi bi-filetype-csv"></i> CSV
      </button>
      <button class="hr-btn hr-btn-ghost" onclick="printTable('gen', 'Generated Attendance')">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
  <div class="hr-table-scroll">
    <table class="att-table" id="genTable">
      <thead>
        <tr>
          <th>Employee ID</th><th>Name</th><th>Department</th><th>Date</th><th>Day</th>
          <th>Time In</th><th>Time Out</th><th>Late</th><th>Position Held</th><th>Job Title</th>
          <th>Category</th><th>Employee Status</th><th>Branch</th><th>Area</th>
          <th>Cut Off</th><th>Set Time</th><th>Remarks</th>
        </tr>
      </thead>
      <tbody id="genBody"></tbody>
    </table>
    <div id="genEmpty" class="hr-empty" style="display:none;"><span class="icon">📭</span><p>No records found.</p></div>
  </div>
  <div class="att-pagination" id="genPager"></div>
</div>

<?php elseif ($tab === 'calendar'): ?>

<!-- ── Employee Calendar ──────────────────────────────────── -->
<div class="hr-table-card" style="margin-bottom:1.25rem;">
  <div class="hr-table-toolbar" style="border-bottom:none;">
    <div class="hr-table-title">🗓️ Employee Attendance Calendar</div>
  </div>
  <form method="GET" action="" style="display:flex;align-items:flex-end;gap:.8rem;flex-wrap:wrap;padding:0 1.1rem 1.1rem;">
    <input type="hidden" name="tab" value="calendar">
    <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
    <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
    <?php if ($canFilterDept): ?>
    <input type="hidden" name="dept" value="<?= htmlspecialchars($filterDept) ?>">
    <?php endif; ?>
    <div class="hr-filter-group">
      <label>Employee <?= $filterDept !== '' ? '(' . htmlspecialchars($filterDept) . ')' : '(All Departments)' ?></label>
      <select name="cal_emp" style="padding:0 .6rem;height:2.1rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;min-width:240px;">
        <option value="">— Select an Employee —</option>
        <?php foreach ($calEmpList as $ce): ?>
        <option value="<?= htmlspecialchars($ce['EmployeeID']) ?>" <?= ($calEmpId === $ce['EmployeeID']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($ce['EmployeeName']) ?> — <?= htmlspecialchars($ce['EmployeeID']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="hr-btn hr-btn-primary">
      <i class="bi bi-calendar3"></i> View Calendar
    </button>
  </form>

  <?php if ($calEmpId === ''): ?>
    <div class="hr-empty" style="padding:2rem 1rem;"><span class="icon">🗓️</span><p>Select an employee above to view their attendance calendar.</p></div>
  <?php else: ?>

    <div style="padding:0 1.1rem 1.1rem;display:flex;gap:.8rem;flex-wrap:wrap;">
      <div class="hr-table-card" style="flex:1;min-width:180px;padding:.8rem 1rem;box-shadow:none;border:1px solid var(--border,#e2e8f0);">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted,#64748b);">Employee</div>
        <div style="font-size:1rem;font-weight:800;color:#0f172a;"><?= htmlspecialchars($calEmpName) ?></div>
      </div>
      <div class="hr-table-card" style="flex:1;min-width:180px;padding:.8rem 1rem;box-shadow:none;border:1px solid var(--border,#e2e8f0);">
        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted,#64748b);">Late Minutes</div>
        <div style="font-size:1rem;font-weight:800;color:#a16207;">
          <?= number_format($calLateMinutesTotal) ?> mins
          <?= $calLateCount > 0 ? '<span style="font-size:.75rem;font-weight:600;color:var(--text-muted,#64748b);">(' . number_format($calLateCount) . ' day' . ($calLateCount !== 1 ? 's' : '') . ')</span>' : '' ?>
        </div>
      </div>
    </div>

    <div class="att-cal-legend">
      <span><i class="att-cal-dot att-cal-dot-present"></i> Present</span>
      <span><i class="att-cal-dot att-cal-dot-halfday"></i> Half Day</span>
      <span><i class="att-cal-dot att-cal-dot-absent"></i> Absent</span>
      <span><i class="att-cal-dot att-cal-dot-leave"></i> Leave</span>
      <span><i class="att-cal-dot att-cal-dot-sunday"></i> Sunday</span>
      <span><i class="bi bi-clock-fill" style="color:#ca8a04;"></i> Late (mins shown)</span>
      <span><i class="bi bi-speedometer2" style="color:#2563eb;"></i> Work Status (Present / Absent / OT / Late / UT / Incomplete / Halfday)</span>
    </div>

    <?php foreach (calMonths($dateFrom, $dateTo) as $mo):
        $firstOfMo = sprintf('%04d-%02d-01', $mo['y'], $mo['m']);
        $daysInMo  = (int)date('t', strtotime($firstOfMo));
        $startDow  = (int)date('w', strtotime($firstOfMo));
    ?>
    <div class="att-cal-month">
      <div class="att-cal-month-title"><?= htmlspecialchars($mo['label']) ?></div>
      <div class="att-cal-grid">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dowLbl): ?>
          <div class="att-cal-dow"><?= $dowLbl ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $startDow; $i++): ?>
          <div class="att-cal-cell att-cal-pad"></div>
        <?php endfor; ?>
        <?php for ($day = 1; $day <= $daysInMo; $day++):
            $dateStr = sprintf('%04d-%02d-%02d', $mo['y'], $mo['m'], $day);
            $inRange = ($dateStr >= $dateFrom && $dateStr <= $dateTo);
            if (!$inRange) {
                echo '<div class="att-cal-cell att-cal-unfiltered"><div class="att-cal-daynum">' . $day . '</div></div>';
                continue;
            }
            $st = calDayStatus($dateStr, $today, $calAttendanceMap, $calLeaveMap, $calHoursMap);
            $cls = 'att-cal-cell att-cal-' . $st['status'];
            $title = date('M j, Y', strtotime($dateStr));
        ?>
          <div class="<?= $cls ?>" title="<?= htmlspecialchars($title . ' — ' . ucfirst($st['status'])) ?>">
            <div class="att-cal-daynum"><?= $day ?></div>
            <?php if ($st['status'] === 'present'): ?>
              <div class="att-cal-mark att-cal-mark-present"><i class="bi bi-check-lg"></i></div>
              <?php if (!empty($st['late'])): ?><div class="att-cal-late"><i class="bi bi-clock-fill" style="margin-right:.25rem;"></i><?= (int)$st['late'] ?>m late</div><?php endif; ?>
              <?= calWorkStatusBadge($st['hours'] ?? null) ?>
            <?php elseif ($st['status'] === 'halfday'): ?>
              <div class="att-cal-mark att-cal-mark-halfday"><i class="bi bi-check-lg"></i> ½</div>
              <?php if (!empty($st['late'])): ?><div class="att-cal-late"><i class="bi bi-clock-fill" style="margin-right:.25rem;"></i><?= (int)$st['late'] ?>m late</div><?php endif; ?>
              <?= calWorkStatusBadge($st['hours'] ?? null) ?>
            <?php elseif ($st['status'] === 'absent'): ?>
              <div class="att-cal-mark att-cal-mark-absent">A</div>
            <?php elseif ($st['status'] === 'leave'): ?>
              <div class="att-cal-mark att-cal-mark-leave">L</div>
              <?php if (!empty($st['halfday'])): ?><div class="att-cal-late">½ day</div><?php endif; ?>
            <?php elseif ($st['status'] === 'sunday'): ?>
              <div class="att-cal-mark att-cal-mark-sunday">—</div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>
</div>

<?php endif; ?>

  </main>
</div>

<script>
// ── Data from PHP ──────────────────────────────────────────
const DATA = {
    dev: <?= $jsRows ?>,
    log: <?= $jsLogRows ?>,
    lates: <?= $jsLateRows ?>,
    absent: <?= $jsAbsentRows ?>,
    gen: <?= $jsGenRows ?>
};

const PAGE_SIZE = 20;

// Per-table state
const state = {};
['dev','log','lates','absent','gen'].forEach(id => {
    state[id] = { page: 1, filtered: DATA[id] || [] };
});

// ── Format helpers ─────────────────────────────────────────
function fmtTime(dt) {
    if (!dt) return '<span class="att-time absent">—</span>';
    const d = new Date(dt);
    if (isNaN(d)) return '<span class="att-time absent">—</span>';
    let h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'pm' : 'am';
    h = h % 12 || 12;
    return `<span class="att-time">${h}:${String(m).padStart(2,'0')} ${ampm}</span>`;
}
function fmtDate(dt) {
    if (!dt) return '—';
    const d = new Date(dt);
    if (isNaN(d)) return '—';
    return d.toLocaleDateString('en-PH', { month:'short', day:'2-digit', year:'numeric' });
}
function fmtHours(h) {
    if (h === null || h === undefined || h === '') return '<span class="att-time absent">—</span>';
    const n = parseFloat(h);
    if (isNaN(n)) return '<span class="att-time absent">—</span>';
    return `<span class="att-time">${n.toFixed(2)} hrs</span>`;
}

function lateMinsBadge(mins) {
    const m = parseFloat(mins) || 0;
    return m > 0
        ? `<span class="hr-badge hr-badge-late"><i class="bi bi-clock-fill" style="margin-right:.35rem;"></i>${m} min</span>`
        : '<span class="hr-badge hr-badge-present">On Time</span>';
}
function dirBadge(dir) {
    dir = (dir||'').toLowerCase();
    if (dir.includes('in'))  return '<span class="hr-badge hr-badge-in">⬇ In</span>';
    if (dir.includes('out')) return '<span class="hr-badge hr-badge-out">⬆ Out</span>';
    return `<span class="hr-badge">${dir}</span>`;
}
function lateBadge(late) {
    return (parseFloat(late) > 0)
        ? '<span class="hr-badge hr-badge-late"><i class="bi bi-clock-fill" style="margin-right:.35rem;"></i>Late</span>'
        : '<span class="hr-badge hr-badge-present">On Time</span>';
}
// Work Status badge builder — Bootstrap icon + tinted color, matching the
// scheme used across Present/Absent/Overtime/Late/Undertime/Incomplete/Halfday.
function statusBadge(icon, color, label) {
    return `<span class="hr-badge" style="background:${color}1a;color:${color};"><i class="bi ${icon}" style="margin-right:.35rem;"></i>${esc(label)}</span>`;
}

// Work Status — evaluated in priority order (most specific/definitive first):
//   1. Absent    -> MorningIn, MorningOut, AfternoonIn, AfternoonOut all null
//   2. Halfday   -> exactly one of AM/PM has no record at all
//   3. Late      -> Total Late minutes > 0 (same source as the Total Late column)
//   4. Overtime  -> TotalHours >= 9
//   5. Present   -> TotalHours >= 8 (and < 9, already caught by Overtime)
//   6. Undertime -> 5 < TotalHours < 8
//   7. Incomplete-> everything else (TotalHours < 4, and the 4–5 hr gap)
function workStatusBadge(totalHours, amIn, amOut, pmIn, pmOut, totalLate) {
    const isEmpty = v => v === null || v === undefined || v === '';
    const amMissing = isEmpty(amIn) && isEmpty(amOut);
    const pmMissing = isEmpty(pmIn) && isEmpty(pmOut);

    if (amMissing && pmMissing) {
        return statusBadge('bi-x-circle-fill', '#dc2626', 'Absent');
    }

    if (amMissing || pmMissing) {
        return statusBadge('bi-dash-circle-fill', '#6b7280', 'Incomplete');
        
    }

    if ((parseFloat(totalLate) || 0) > 0) {
        return statusBadge('bi-clock-fill', '#ca8a04', 'Late');
    }

    const h = parseFloat(totalHours);

    if (isNaN(h)) {
        return '<span class="hr-badge" style="background:#f1f5f9;color:#64748b;">—</span>';
    }

    if (h >= 9) {
        return statusBadge('bi-stopwatch', '#2563eb', 'Overtime');
    }

    if (h >= 8) {
        return statusBadge('bi-check-circle-fill', '#16a34a', 'Present');
    }

    if (h > 5 && h < 8) {
        return statusBadge('bi-clock-fill', '#7c3aed', 'Undertime');
    }
    return statusBadge('bi-circle-half', '#f97316', 'Halfday');
    
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
const SOURCE_COLORS = {
    device: '#7c3aed', site: '#09489b', app: '#08860e'
};
function sourceBadge(src) {
    const label = String(src||'—').trim();
    const key = label.toLowerCase();
    const color = SOURCE_COLORS[key] || SOURCE_COLORS.device; // Device is the default/fallback color
    return `<span class="hr-badge" style="background:${color}1a;color:${color};">${esc(label)}</span>`;
}

// ── Row renderers ──────────────────────────────────────────
function rowDev(r) {
    return `<tr>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${fmtDate(r.ADate)}</td>
      <td>${fmtTime(r.TimeIn)}</td>
      <td>${fmtTime(r.MorningIn)}</td>
      <td>${fmtTime(r.MorningOut)}</td>
      <td>${fmtHours(r.MorningTotalHours)}</td>
      <td>${lateMinsBadge(r.AMLate)}</td>
      <td>${fmtTime(r.TimeInPM)}</td>
      <td>${fmtTime(r.AfternoonIn)}</td>
      <td>${fmtTime(r.AfternoonOut)}</td>
      <td>${fmtHours(r.AfternoonTotalHours)}</td>
      <td>${lateMinsBadge(r.PMLate)}</td>
      <td>${fmtHours(r.TotalHours)}</td>
      <td>${lateMinsBadge(r.Late1)}</td>
      <td>${workStatusBadge(r.TotalHours, r.MorningIn, r.MorningOut, r.AfternoonIn, r.AfternoonOut, r.Late1)}</td>
    </tr>`;
}
function rowLog(r) {
    return `<tr>
      <td class="mono" style="color:#7c3aed;font-weight:700">${esc(r.EmployeeID||'—')}</td>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${esc(r.Position_Held||'—')}</td>
      <td>${esc(r.Area||'—')}</td>
      <td>${fmtDate(r.ADate)}</td>
      <td>${fmtTime(r.CheckIn)}</td>
      <td>${fmtTime(r.ATime)}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${dirBadge(r.Direction||'')}</td>
      <td>${lateMinsBadge(r.Late)}</td>
      <td>${sourceBadge(r.DataFrom)}</td>
    </tr>`;
}

function rowLates(r) {
    const mins = parseFloat(r.Late1) || 0;
    const isDanger  = mins > 60;
    const rowClass  = isDanger ? 'late-row-danger' : 'late-row-warn';
    const badgeBg   = isDanger ? '#fee2e2' : '#fef9c3';
    const badgeText = isDanger ? '#dc2626' : '#a16207';

    return `<tr class="${rowClass}">
      <td style="font-weight:800;color:#dc2626;">#${esc(r.Rank||'—')}</td>
      <td>${fmtDate(r.ADate)}</td>
      <td class="mono" style="color:#7c3aed;font-weight:700">${esc(r.EmployeeID||'—')}</td>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${fmtTime(r.MorningIn)}</td>
      <td>${lateMinsBadge(r.AMLate)}</td>
      <td>${lateMinsBadge(r.PMLate)}</td>
      <td><span class="late-badge-big" style="background:${badgeBg};color:${badgeText};">⚠ ${esc(r.Late1||0)} mins</span></td>
    </tr>`;
}
function rowAbsent(r) {
    return `<tr>
      <td class="mono" style="color:#7c3aed;font-weight:700">${esc(r.EmployeeID||'—')}</td>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${esc(r.Position_held||'—')}</td>
      <td>${fmtDate(r.ADate)}</td>
    </tr>`;
}

function rowGen(r) {
    return `<tr>
      <td class="mono" style="color:#7c3aed;font-weight:700">${esc(r.EmployeeID||'—')}</td>
      <td style="font-weight:600">${esc(r.EmployeeName||'—')}</td>
      <td>${esc(r.Department||'—')}</td>
      <td>${fmtDate(r.Adate)}</td>
      <td>${esc(r.Aday||'—')}</td>
      <td>${fmtTime(r.AtimeIn)}</td>
      <td>${fmtTime(r.AtimeOut)}</td>
      <td>${(r.Late === null || r.Late === undefined || r.Late === '') ? '—' : ((parseFloat(r.Late)||0) > 0 ? `<span class="hr-badge hr-badge-late"><i class="bi bi-clock-fill" style="margin-right:.35rem;"></i>${esc(r.Late)} min</span>` : '<span class="hr-badge hr-badge-present">On Time</span>')}</td>
      <td>${esc(r.Position_held||'—')}</td>
      <td>${esc(r.Job_tittle||'—')}</td>
      <td>${esc(r.Category||'—')}</td>
      <td>${esc(r.Employee_Status||'—')}</td>
      <td>${esc(r.Branch||'—')}</td>
      <td>${esc(r.Area||'—')}</td>
      <td>${esc(r.CutOff||'—')}</td>
      <td>${esc(r.SetTime||'—')}</td>
      <td style="color:var(--text-muted,#64748b);">${esc(r.Remarks||'—')}</td>
    </tr>`;
}

const RENDERERS = { dev: rowDev, log: rowLog, lates: rowLates, absent: rowAbsent, gen: rowGen };

// ── Render a table page ────────────────────────────────────
function renderTable(id) {
    const s   = state[id];
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
        if (empty)  empty.style.display = '';
        if (count)  count.textContent = '0 records';
        if (pager)  pager.innerHTML = '';
        return;
    }
    if (empty) empty.style.display = 'none';
    if (count) count.textContent = `${total} record${total !== 1 ? 's' : ''}`;

    tbody.innerHTML = slice.map(RENDERERS[id]).join('');

    // Pagination
    if (pager) {
        const from = start + 1, to = Math.min(start + PAGE_SIZE, total);
        let btns = `<span class="att-page-info">Showing ${from}–${to} of ${total}</span><div class="att-page-btns">`;
        btns += `<button onclick="goPage('${id}',${s.page-1})" ${s.page<=1?'disabled':''}>‹</button>`;

        // Smart page number list
        const pageNums = buildPageNums(s.page, pages);
        let prev = null;
        for (const p of pageNums) {
            if (p === '…') { btns += `<span class="ellipsis">…</span>`; }
            else {
                btns += `<button class="${p===s.page?'active':''}" onclick="goPage('${id}',${p})">${p}</button>`;
            }
            prev = p;
        }

        btns += `<button onclick="goPage('${id}',${s.page+1})" ${s.page>=pages?'disabled':''}>›</button>`;
        btns += `</div>`;
        pager.innerHTML = btns;
    }
}

function buildPageNums(cur, total) {
    if (total <= 7) return Array.from({length:total},(_,i)=>i+1);
    const pages = [];
    pages.push(1);
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

// ── Search (across all loaded records) ────────────────────
function tableSearch(id) {
    const q = (document.getElementById(id + 'Search')?.value || '').toLowerCase().trim();
    const all = DATA[id] || [];
    state[id].filtered = q ? all.filter(r =>
        Object.values(r).some(v => String(v||'').toLowerCase().includes(q))
    ) : all;
    state[id].page = 1;
    renderTable(id);
}

// ── CSV export (all filtered rows) ────────────────────────
function exportCSV(id, filename, excludeKeys) {
    const all = state[id].filtered;
    if (!all.length) return;
    const skip = new Set(excludeKeys || []);
    const keys = Object.keys(all[0]).filter(k => !skip.has(k));
    const rows = [keys.join(','), ...all.map(r =>
        keys.map(k => '"' + String(r[k]||'').replace(/"/g,'""') + '"').join(',')
    )];
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows.join('\n'));
    a.download = filename + '_<?= date('Ymd') ?>.csv';
    a.click();
}

// ── Print (all filtered rows) ─────────────────────────────
function printTable(id, title) {
    const tbody = document.getElementById(id + 'Body');
    const table = document.getElementById(id + 'Table');
    if (!table) return;
    // Temporarily render all filtered rows for printing
    const saved = tbody.innerHTML;
    tbody.innerHTML = state[id].filtered.map(RENDERERS[id]).join('');
    const win = window.open('', '_blank', 'width=1100,height=700');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>${title}</title>
      <style>
        body { font-family: Arial, sans-serif; font-size: 10px; padding: 8px; }
        h3   { margin: 0 0 6px; font-size: 13px; }
        p    { margin: 0 0 8px; font-size: 8px; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #e8e8e8; font-size: 8px; text-transform: uppercase; border: 1px solid #aaa; padding: 4px 6px; }
        td { border: 1px solid #ccc; padding: 3px 6px; }
        tr:nth-child(even) td { background: #f7f7f7; }
        @page { size: landscape; margin: 10mm; }
      </style>
    </head><body>
      <h3>Urban Tradewell Corporation — ${title}</h3>
      <p>Department: <?= deptLabel($filterDept) ?> &nbsp;|&nbsp; <?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?> &nbsp;|&nbsp; Printed: <?= date('Y-m-d H:i') ?></p>
      ${table.outerHTML}
    </body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
    tbody.innerHTML = saved;
}

// Sidebar toggle/collapse-state persistence is already handled by the
// shared window.toggleHRSidebar() defined in hr_nav.php — don't redefine
// it here, it would overwrite the shared one and break main-content offset.

// ── Init: render all active tables on load ─────────────────
document.addEventListener('DOMContentLoaded', () => {
<?php if ($tab === 'timeinout'): ?>
    renderTable('dev');
<?php elseif ($tab === 'devicelog'): ?>
    renderTable('log');
<?php elseif ($tab === 'toplates'): ?>
    renderTable('lates');
<?php elseif ($tab === 'absents'): ?>
    renderTable('absent');
<?php elseif ($tab === 'generated'): ?>
    renderTable('gen');
<?php endif; ?>
});
</script>

</body>
</html>