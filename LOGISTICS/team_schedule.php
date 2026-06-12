<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

// ── AJAX: Short Stock Items ───────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'ssitems' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $seid    = (int)($_GET['seid']  ?? 0);
    $empid   = trim($_GET['empid'] ?? '');
    $sdidVal = trim($_GET['sdid']  ?? '');
    $itemRows = [];
    $cols = "
            SELECT [Item],[UOM]
                  ,SUM(CAST([QTY] AS FLOAT))        AS QTY
                  ,MAX([UnitPrice])                  AS UnitPrice
                  ,SUM(CAST([ItemAmount] AS FLOAT))  AS ItemAmount
                  ,MAX([DateSchedule])               AS DateSchedule
                  ,MAX([PlateNumber])                AS PlateNumber
                  ,MAX([Area])                       AS Area
                  ,MAX([Outlet])                     AS Outlet
                  ,MAX([TypeShort])                  AS TypeShort
                  ,MAX([RefNo])                      AS RefNo
                  ,MAX([SDID])                       AS SDID
                  ,MAX([TotalAmount])                AS TotalAmount
                  ,MAX([NumAccountable])             AS NumAccountable
                  ,MAX([AmountDue])                  AS AmountDue
                  ,MAX([EmployeeID])                 AS EmployeeID
                  ,MAX([EmployeeName])               AS EmployeeName
                  ,MAX([SEID])                       AS SEID
            FROM [dbo].[View_ShortPaymentPaidItems]";

    // Primary: fetch by SEID
    if ($seid > 0) {
        $itemRows = runQuery($conn, $cols . " WHERE SEID = $seid GROUP BY [Item],[UOM] ORDER BY Item ASC");
    }
    // Fallback: if SEID returned nothing, try SDID + EmployeeID
    if (empty($itemRows) && $sdidVal !== '' && $empid !== '') {
        $sdidSafe  = str_replace("'", "''", $sdidVal);
        $empidSafe = str_replace("'", "''", $empid);
        $itemRows  = runQuery($conn, $cols . " WHERE SDID = '$sdidSafe' AND EmployeeID = '$empidSafe' GROUP BY [Item],[UOM] ORDER BY Item ASC");
    }
    sqlsrv_close($conn);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($itemRows ?: []);
    exit;
}

rbac_gate($pdo, 'team_schedule');

// ── Date filters ──────────────────────────────────────────
$today     = date('Y-m-d');
$monthFrom = date('Y-m-d', strtotime('-30 days'));
$monthTo   = date('Y-m-d');

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
if (!$dateActive) {
    $baseFrom = $monthFrom;
    $baseTo   = $monthTo;
} else {
    $baseFrom = $dateFrom !== '' ? $dateFrom : $monthFrom;
    $baseTo   = $dateTo   !== '' ? $dateTo   : $today;
}



// ── Other filters ─────────────────────────────────────────

$selArea   = isset($_GET['area'])   ? trim($_GET['area'])   : '';
$selPlate  = isset($_GET['plate'])  ? trim($_GET['plate'])  : '';
$selStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$validTabs = ['truck', 'shortstocks_sdid', 'shortstocks_emp'];
$selTab    = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : 'truck';

$areaActive   = $selArea   !== '';
$plateActive  = $selPlate  !== '';
$statusActive = $selStatus !== '';
$anyFilter    = $dateActive || $areaActive || $plateActive || $statusActive;

// ── Safe values ───────────────────────────────────────────
$_areaSafe   = str_replace("'", "''", $selArea);
$_plateSafe  = str_replace("'", "''", $selPlate);
$_statusSafe = str_replace("'", "''", $selStatus);

// ── WHERE clauses ─────────────────────────────────────────
$areaWhere   = $areaActive   ? " AND Area = '$_areaSafe'"                  : '';
$plateWhere  = $plateActive  ? " AND PlateNumber = '$_plateSafe'"          : '';
$statusWhere = $statusActive ? " AND RTRIM(Status) = '$_statusSafe'"       : '';
$selDept     = ''; // deprecated — department now comes from session
$sessionDept      = $_SESSION['Department'] ?? '';
$sessionDeptSafe  = str_replace("'", "''", $sessionDept);
$sessionDeptUpper = strtoupper(trim($sessionDept));
$deptAliases = [
    'CENTURY'    => ['CENTURY', 'CENT'],
    'NUTRIASIA'  => ['NUTRIASIA', 'NUTRI'],
    'MULTILINES' => ['MULTILINES', 'MULTI'],
    'MONDE'      => ['MONDE', 'MON'],
];
$deptInValues = $deptAliases[$sessionDeptUpper] ?? [$sessionDeptUpper];
$deptInStr    = "'" . implode("','", array_map(fn($v) => str_replace("'", "''", $v), $deptInValues)) . "'";
$deptWhereClause   = "AND UPPER(RTRIM(LTRIM(Department))) IN (" . $deptInStr . ")";
$deptWhereTsClause = "AND UPPER(RTRIM(LTRIM(ts.Department))) IN (" . $deptInStr . ")";
$sessionDeptWhere   = $sessionDept !== '' ? " " . $deptWhereClause   : '';
$sessionDeptWhereTs = $sessionDept !== '' ? " " . $deptWhereTsClause : '';
$commonWhere = $sessionDeptWhere . $areaWhere . $plateWhere . $statusWhere;

// ── Helpers ───────────────────────────────────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function lookupList($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $list = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Lookup dropdowns ──────────────────────────────────────
$areaList   = lookupList($conn, "SELECT DISTINCT Area FROM [dbo].[teamschedule] WHERE Area IS NOT NULL AND Area <> '' ORDER BY Area");
$plateList  = lookupList($conn, "SELECT DISTINCT PlateNumber FROM [dbo].[teamschedule] WHERE PlateNumber IS NOT NULL AND PlateNumber <> '' ORDER BY PlateNumber");
$statusList = lookupList($conn, "SELECT DISTINCT RTRIM(Status) FROM [dbo].[teamschedule] WHERE Status IS NOT NULL AND Status <> '' ORDER BY RTRIM(Status)");

// ── Stat query ────────────────────────────────────────────
$statSql = "
SELECT
    COUNT(DISTINCT TruckScheduleID)                                                                 AS TotalSchedules,
    COUNT(DISTINCT PlateNumber)                                                                     AS TotalTrucks,
    COUNT(WID)                                                                                      AS TotalCrew,
    COUNT(DISTINCT CASE WHEN RTRIM(ISNULL(Position,'')) LIKE '%DRIVER%' THEN EmployeeID END)       AS TotalDrivers,
    COUNT(DISTINCT CASE WHEN RTRIM(ISNULL(Position,'')) NOT LIKE '%DRIVER%' THEN EmployeeID END)   AS TotalHelpers,
    ISNULL(SUM(CAST(ISNULL(Cases,0) AS INT)), 0)                                                   AS TotalCases,
    ISNULL(SUM(CAST(ISNULL(Calls,0)  AS INT)), 0)                                                  AS TotalCalls,
    ROUND(ISNULL(SUM(CAST(ISNULL(Rate,0) AS FLOAT)), 0), 2)                                        AS TotalRate,
    ROUND(ISNULL(SUM(CAST(ISNULL(Allowance,0) AS FLOAT)), 0), 2)                                   AS TotalAllowance,
    COUNT(DISTINCT CASE WHEN RTRIM(ISNULL(Status,'')) = 'Regular'      THEN EmployeeID END)        AS RegularCount,
    COUNT(DISTINCT CASE WHEN RTRIM(ISNULL(Status,'')) = 'Probationary' THEN EmployeeID END)        AS ProbationaryCount,
    COUNT(DISTINCT CASE WHEN RTRIM(ISNULL(Status,'')) = 'Extra'        THEN EmployeeID END)        AS ExtraCount
FROM [dbo].[teamschedule]
WHERE ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
";
$stat = runQuery($conn, $statSql)[0] ?? [];

// ── Team Schedule data (View_RemittanceEmployee) ─────────
$teamSql = "
SELECT
    vr.DocNo,
    vr.DID,
    vr.Department,
    vr.DocDate                          AS ScheduleDate,
    vr.PlateNumber,
    vr.Salesman,
    vr.Area,
    vr.TotalCases                       AS Cases,
    vr.TotalCalls                       AS Calls,
    vr.TotalNetAmount,
    vr.Status                           AS DocStatus,
    vr.Remarks,
    vr.TruckScheduleID,
    vr.ScheduleDate                     AS ActualScheduleDate,
    RTRIM(ISNULL(vr.Employee_Name, '')) AS Employee_Name,
    RTRIM(ISNULL(vr.Position, ''))      AS Position,
    vr.EmployeeID,
    vr.Mobile_Number,
    RTRIM(ISNULL((
        SELECT TOP 1 RTRIM(ISNULL(ts2.Status,''))
        FROM [dbo].[teamschedule] ts2
        WHERE ts2.TruckScheduleID = vr.TruckScheduleID
          AND ts2.EmployeeID      = vr.EmployeeID
    ), ''))                             AS EmploymentStatus
FROM [dbo].[View_RemittanceEmployee] vr
WHERE vr.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  $sessionDeptWhere
ORDER BY vr.DocNo DESC, vr.Position ASC
";
$teamRows = runQuery($conn, $teamSql);

// ── Group by DocNo (one card = one delivery doc with full crew) ───
$grouped = [];
foreach ($teamRows as $row) {
    $docNo = $row['DocNo'];
    if (!isset($grouped[$docNo])) {
        $grouped[$docNo] = [
            'DocNo'            => $docNo,
            'TruckScheduleID'  => $row['TruckScheduleID'],
            'Department'       => trim($row['Department'] ?? ''),
            'ScheduleDate'     => $row['ScheduleDate'] ?? '',
            'ActualScheduleDate' => $row['ActualScheduleDate'] ?? '',
            'PlateNumber'      => trim($row['PlateNumber'] ?? ''),
            'Salesman'         => trim($row['Salesman'] ?? ''),
            'Area'             => trim($row['Area'] ?? ''),
            'Remarks'          => trim($row['Remarks'] ?? ''),
            'Cases'            => (int)($row['Cases'] ?? 0),
            'Calls'            => (int)($row['Calls'] ?? 0),
            'TotalNetAmount'   => (float)($row['TotalNetAmount'] ?? 0),
            'DocStatus'        => trim($row['DocStatus'] ?? ''),
            'crew'             => [],
        ];
    }
    $row['Employee_Name'] = trim($row['Employee_Name'] ?? '');
    $row['Position']      = trim($row['Position'] ?? '');
    $row['Mobile_Number'] = (trim($row['Mobile_Number'] ?? '') === 'NULL') ? '' : trim($row['Mobile_Number'] ?? '');
    // avoid duplicate crew per doc
    $alreadyAdded = in_array($row['EmployeeID'], array_column($grouped[$docNo]['crew'], 'EmployeeID'));
    if (!$alreadyAdded) {
        $grouped[$docNo]['crew'][] = $row;
    }
}

// ── Truck Schedule data ───────────────────────────────────
$truckSql = "
SELECT
    TruckScheduleID, Department, ScheduleDate, PlateNumber, Salesman, Area,
    Remarks, Cases, Calls,
    COUNT(EmployeeID)                                                                     AS CrewCount,
    COUNT(CASE WHEN RTRIM(ISNULL(Position,'')) LIKE '%DRIVER%' THEN 1 END)           AS DriverCount,
    COUNT(CASE WHEN RTRIM(ISNULL(Position,'')) NOT LIKE '%DRIVER%' THEN 1 END)       AS HelperCount,
    ROUND(ISNULL(SUM(CAST(ISNULL(Rate,0) AS FLOAT)), 0), 2)                          AS TotalRate,
    ROUND(ISNULL(SUM(CAST(ISNULL(Allowance,0) AS FLOAT)), 0), 2)                     AS TotalAllowance,
    COUNT(CASE WHEN RTRIM(ISNULL(Status,'')) = 'Regular'      THEN 1 END)            AS RegularCount,
    COUNT(CASE WHEN RTRIM(ISNULL(Status,'')) = 'Probationary' THEN 1 END)            AS ProbCount,
    COUNT(CASE WHEN RTRIM(ISNULL(Status,'')) = 'Extra'        THEN 1 END)            AS ExtraCount
FROM [dbo].[teamschedule]
WHERE ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
GROUP BY TruckScheduleID, Department, ScheduleDate, PlateNumber, Salesman, Area, Remarks, Cases, Calls
HAVING COUNT(EmployeeID) > 0
ORDER BY ScheduleDate DESC, TruckScheduleID DESC
";
$truckRows = runQuery($conn, $truckSql);

// ── Short Stocks (Short Payment — Balance > 0) ────────────
$shortStocksDeptWhere = $sessionDept !== '' ? ' AND UPPER(RTRIM(LTRIM(Department))) IN (' . $deptInStr . ')' : '';
$shortStocksSql = '
SELECT
     [SEID],[Position],[Status],[Amount],[SPPID]
    ,[AmountDue],[PaidAmount],[Balance]
    ,[DateGenerate],[SDID],[DID],[Department]
    ,[DateSchedule],[PlateNumber],[Area],[Outlet]
    ,[RefNo],[TotalAmount],[NumAccountable]
    ,[AmountL],[StatusofShort],[Remarks],[IDS]
    ,[EmployeeID],[EmployeeName],[DatePaid]
    ,[TypeShort],[Category],[Employee_Status]
    ,[Job_tittle],[Position_held]
FROM [dbo].[View_ShortPaymentPaidDetails]
WHERE Balance > 0
' . $shortStocksDeptWhere . '
ORDER BY Balance DESC, DateGenerate DESC
';
$shortStocksRows = runQuery($conn, $shortStocksSql);

// ── Short Stocks: SDID grouped (reuse Individual data — same source, correct fields) ──
$sdidGrouped = [];
foreach ($shortStocksRows as $r) {
    $sdid = (string)($r['SDID'] ?? 'Unknown');
    $sdidGrouped[$sdid][] = $r;
}
$sdidSummaries = [];
foreach ($sdidGrouped as $sdid => $rows) {
    $totalBal  = array_sum(array_map(fn($r) => (float)($r['Balance']    ?? 0), $rows));
    $totalAmt  = array_sum(array_map(fn($r) => (float)($r['AmountDue']  ?? 0), $rows)); // per-employee share of the short
    $totalPaid = array_sum(array_map(fn($r) => (float)($r['PaidAmount'] ?? 0), $rows));
    $depts     = array_unique(array_filter(array_map(fn($r) => trim($r['Department'] ?? ''), $rows)));
    $areas     = array_unique(array_filter(array_map(fn($r) => trim($r['Area']       ?? ''), $rows)));
    $dates     = array_filter(array_map(fn($r) => $r['DateSchedule'] ?? '', $rows));
    $sdidSummaries[$sdid] = [
        'SDID'        => $sdid,
        'RowCount'    => count($rows),
        'TotalBalance'=> $totalBal,
        'TotalAmount' => $totalAmt,
        'TotalPaid'   => $totalPaid,
        'Departments' => implode(', ', $depts),
        'Areas'       => implode(', ', $areas),
        'LatestDate'  => !empty($dates) ? max($dates) : '',
        'PlateNumber' => $rows[0]['PlateNumber'] ?? '',
        'Status1'     => $rows[0]['StatusofShort'] ?? '',
    ];
}
usort($sdidSummaries, fn($a, $b) => $b['TotalBalance'] <=> $a['TotalBalance']);


// ── Individual Employee Short stats ───────────────────────
$totalShortStocksBalance = array_sum(array_column($shortStocksRows, 'Balance'));
$totalShortStocksCount   = count($shortStocksRows);
$totalSdidCount          = count($sdidSummaries);
$totalSdidBalance        = array_sum(array_column($sdidSummaries, 'TotalBalance'));

// NOTE: $conn is intentionally NOT closed here.
// topbar.php (included later) calls get_employee_profile($conn).
// Closing $conn here causes: "supplied resource is not a valid ss_sqlsrv_conn resource".
// PHP closes the connection automatically when the script ends.

// ── Build TSID-keyed crew lookup directly from teamschedule ──
$crewSql = "
SELECT
    ts.TruckScheduleID,
    ts.EmployeeID,
    RTRIM(ISNULL(ts.Employee_Name, ''))  AS Employee_Name,
    RTRIM(ISNULL(ts.Position, ''))       AS Position,
    RTRIM(ISNULL(ts.Status, ''))         AS EmploymentStatus,
    RTRIM(ISNULL(ts.Mobile_Number, ''))  AS Mobile_Number
FROM [dbo].[teamschedule] ts
WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
GROUP BY ts.TruckScheduleID, ts.EmployeeID, ts.Employee_Name, ts.Position, ts.Status, ts.Mobile_Number
ORDER BY ts.TruckScheduleID, ts.Position ASC
";
$crewRows = runQuery($conn, $crewSql);
$crewByTsid = [];
foreach ($crewRows as $c) {
    $tsid = (string)($c['TruckScheduleID'] ?? '');
    if ($tsid === '') continue;
    $c['Mobile_Number'] = (trim($c['Mobile_Number']) === 'NULL') ? '' : trim($c['Mobile_Number']);
    $alreadyAdded = isset($crewByTsid[$tsid]) &&
                    in_array($c['EmployeeID'], array_column($crewByTsid[$tsid], 'EmployeeID'));
    if (!$alreadyAdded) {
        $crewByTsid[$tsid][] = $c;
    }
}

// ── Pagination ────────────────────────────────────────────
$rowLimit    = 20;
$totalTrucks = count($truckRows);

$currentCount = match($selTab) {
    'shortstocks_sdid'=> count($sdidSummaries),
    'shortstocks_emp' => count($shortStocksRows),
    default           => $totalTrucks,
};
$totalPages = max(1, (int)ceil($currentCount / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;

$displayTrucks = array_slice($truckRows, $offset, $rowLimit);

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

function tabUrl(string $t): string {
    $p = $_GET; $p['tab'] = $t; unset($p['page']);
    return '?' . http_build_query($p);
}

function peso(float $v): string {
    return '₱' . number_format($v, 2);
}

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $ini = '';
    foreach ($parts as $p) $ini .= strtoupper(substr($p, 0, 1));
    return substr($ini, 0, 2);
}

function deptColor(string $d): string {
    $map = [
        'monde'      => '#ef4444',
        'century'    => '#2563eb',
        'multilines' => '#ca8a04',
        'nutriasia'  => '#059669',
        'silverswan' => '#6366f1',
    ];
    return $map[strtolower(trim($d))] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logistics Schedule — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ════════════════════════════════════════════════════════
   DESIGN TOKENS
════════════════════════════════════════════════════════ */
:root {
  --c-blue   : #0ea5e9;
  --c-green  : #16a34a;
  --c-yellow : #ca8a04;
  --c-red    : #dc2626;
  --c-purple : #7c3aed;
  --c-orange : #ea580c;
  --c-ink    : #0f172a;
  --c-dim    : #64748b;
  --c-border : var(--border, #e2e8f0);
  --c-surface: var(--card-bg, #fff);
  --c-muted  : var(--input-bg, #f8fafc);
}

/* ── Stat cards (fuel dashboard style) ──────────────────── */
.stat-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem;
  margin-bottom: 1.1rem;
}
.stat-cell {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 14px;
  padding: 1rem 1.1rem .85rem;
  display: flex; flex-direction: column; gap: 4px;
  position: relative; overflow: hidden;
  transition: box-shadow .15s, transform .15s;
}
.stat-cell:hover {
  box-shadow: 0 4px 18px rgba(0,0,0,.07);
  transform: translateY(-1px);
}
.stat-cell::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--sc-accent, var(--c-blue));
  border-radius: 14px 14px 0 0;
}
.stat-cell:first-child  { border-radius: 14px; }
.stat-cell:last-child   { border-radius: 14px; }
.sc-icon {
  font-size: 1.15rem; margin-bottom: .1rem;
  color: var(--sc-accent, var(--c-blue)); opacity: .85;
}
.sc-label {
  font-size: .67rem; text-transform: uppercase; letter-spacing: .08em;
  color: var(--c-dim); font-weight: 700;
}
.sc-value {
  font-size: 1.5rem; font-weight: 800; line-height: 1.1;
  color: var(--c-ink); font-family: 'JetBrains Mono', monospace;
}
.sc-sub { font-size: .71rem; color: var(--c-dim); margin-top: 1px; }

/* ── Filter bar ─────────────────────────────────────────── */
.filter-bar {
  display: flex; flex-wrap: wrap; align-items: center; gap: .5rem;
  padding: .65rem 1rem;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 10px;
  margin-bottom: .85rem;
}
.filter-bar select,
.filter-bar input[type="date"] {
  height: 30px; font-size: .78rem; padding: 0 .6rem;
  border: 1px solid var(--c-border); border-radius: 7px;
  background: var(--c-muted); color: var(--text, #111);
  min-width: 0;
}
.filter-bar select:focus,
.filter-bar input[type="date"]:focus {
  outline: none; border-color: var(--c-blue); box-shadow: 0 0 0 2px rgba(14,165,233,.15);
}
.filter-sep { width: 1px; height: 22px; background: var(--c-border); margin: 0 .1rem; }
.btn-filter {
  display: inline-flex; align-items: center; gap: .25rem;
  height: 30px; padding: 0 .85rem; border-radius: 7px;
  font-size: .78rem; font-weight: 700; cursor: pointer; border: 1.5px solid;
  transition: all .12s;
}
.btn-filter.apply  { background: var(--c-blue); color: #fff; border-color: var(--c-blue); }
.btn-filter.apply:hover { background: #0284c7; }
.btn-filter.reset  { background: var(--c-muted); color: var(--c-dim); border-color: var(--c-border); }
.btn-filter.reset:hover { border-color: var(--c-red); color: var(--c-red); }
.filter-label { font-size: .72rem; font-weight: 700; color: var(--c-dim); white-space: nowrap; }

/* ── Tab nav (fuel dashboard style) ────────────────────── */
.tab-nav {
  display: flex; gap: .5rem; margin-bottom: 1rem;
  background: none; border: none; border-radius: 0; overflow: visible;
}
.tab-nav a {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .5rem 1.1rem;
  font-size: .8rem; font-weight: 700; text-decoration: none;
  color: var(--c-dim);
  background: var(--c-surface);
  border: 1.5px solid var(--c-border);
  border-radius: 10px;
  transition: all .15s;
  white-space: nowrap;
}
.tab-nav a:hover {
  border-color: var(--c-blue);
  color: var(--c-blue);
  background: rgba(14,165,233,.04);
}
.tab-nav a.active {
  background: var(--c-blue);
  color: #fff;
  border-color: var(--c-blue);
  box-shadow: 0 3px 12px rgba(14,165,233,.3);
}
.tab-nav a.active .tab-badge {
  background: rgba(255,255,255,.25);
  color: #fff;
}
.tab-nav a.active-red {
  background: var(--c-red);
  color: #fff;
  border-color: var(--c-red);
  box-shadow: 0 3px 12px rgba(220,38,38,.3);
}
.tab-nav a.active-red .tab-badge { background: rgba(255,255,255,.25); color: #fff; }
.tab-badge {
  font-size: .68rem; background: rgba(14,165,233,.12); color: var(--c-blue);
  border-radius: 999px; padding: 1px 8px; font-weight: 700;
}
.tab-badge.red { background: rgba(220,38,38,.12); color: var(--c-red); }

/* ── Section toolbar ────────────────────────────────────── */
.section-toolbar {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
  margin-bottom: .65rem;
}
.section-toolbar-left { display: flex; align-items: center; gap: .4rem; flex: 1; min-width: 0; }
.section-count {
  font-size: .72rem; font-weight: 700;
  background: var(--c-muted); border: 1px solid var(--c-border);
  border-radius: 999px; padding: 1px 10px; color: var(--c-dim);
}
.search-input {
  display: flex; align-items: center; gap: .3rem;
  background: var(--c-surface); border: 1px solid var(--c-border);
  border-radius: 7px; padding: 0 .6rem; height: 30px;
}
.search-input i { font-size: .78rem; color: var(--c-dim); }
.search-input input {
  border: none; background: none; outline: none;
  font-size: .78rem; color: var(--text, #111); width: 180px;
}
.btn-sm {
  display: inline-flex; align-items: center; gap: .2rem;
  height: 30px; padding: 0 .75rem; border-radius: 7px;
  font-size: .75rem; font-weight: 700; cursor: pointer; border: 1.5px solid;
  transition: all .12s;
}
.btn-sm.green  { background: #f0fdf4; color: #166534; border-color: #86efac; }
.btn-sm.green:hover { background: #dcfce7; }
.btn-sm.blue   { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.btn-sm.blue:hover  { background: #dbeafe; }
.btn-sm.violet { background: #f5f3ff; color: #5b21b6; border-color: #c4b5fd; }
.btn-sm.violet:hover { background: #ede9fe; }

/* ── Font size boost ─────────────────────────────────────── */
.sched-head-meta,
.area-text,
.plate,
.date-chip,
.meta-pill,
.crew-table,
.data-table,
.short-inner-table,
.short-row,
.emp-name-bold,
.pager-info,
.section-count,
.sc-sub { font-size: .85rem !important; }

.plate       { font-size: 1rem !important; }
.area-text   { font-size: .88rem !important; }
.date-chip   { font-size: .82rem !important; }
.meta-pill   { font-size: .78rem !important; }
.sc-value    { font-size: 1.55rem !important; }
.sc-label    { font-size: .72rem !important; }

.crew-table thead th,
.data-table thead th,
.short-inner-table thead th { font-size: .74rem !important; }

.crew-table tbody td,
.data-table tbody td,
.short-inner-table tbody td { font-size: .85rem !important; }

.crew-table tfoot td { font-size: .83rem !important; }

.emp-name-bold { font-size: .92rem !important; }
.short-total   { font-size: 1rem !important; }
.plate-tag     { font-size: .88rem !important; }

/* ── Team cards: COMPACT table-style list ───────────────── */
.sched-list { display: flex; flex-direction: column; gap: .4rem; }
.sched-row {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 10px;
  overflow: hidden;
}
.sched-head {
  display: grid;
  grid-template-columns: 110px 1fr auto;
  align-items: center;
  gap: .5rem;
  padding: .5rem .85rem;
  cursor: pointer;
  transition: background .12s;
  user-select: none;
}
.sched-head:hover { background: var(--c-muted); }
.sched-head-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
.sched-head-right { display: flex; align-items: center; gap: .5rem; }
.plate {
  font-family: 'JetBrains Mono', monospace; font-weight: 800;
  font-size: .88rem; color: var(--c-blue); letter-spacing: .03em;
  white-space: nowrap;
}
.date-chip {
  font-family: 'JetBrains Mono', monospace; font-size: .72rem;
  color: var(--c-dim); white-space: nowrap;
}
.area-text {
  font-size: .78rem; font-weight: 600; color: var(--text, #374151);
}
.dept-dot {
  display: inline-block; width: 8px; height: 8px; border-radius: 50%;
  flex-shrink: 0;
}
.meta-pill {
  display: inline-flex; align-items: center; gap: .18rem;
  font-size: .7rem; font-weight: 700; padding: 1px 8px;
  border-radius: 999px; white-space: nowrap;
}
.mp-blue   { background: rgba(14,165,233,.1);  color: var(--c-blue);   border: 1px solid rgba(14,165,233,.25); }
.mp-green  { background: rgba(22,163,74,.1);   color: var(--c-green);  border: 1px solid rgba(22,163,74,.25);  }
.mp-purple { background: rgba(124,58,237,.1);  color: var(--c-purple); border: 1px solid rgba(124,58,237,.25); }
.mp-orange { background: rgba(234,88,12,.1);   color: var(--c-orange); border: 1px solid rgba(234,88,12,.25);  }
.mp-yellow { background: rgba(202,138,4,.1);   color: var(--c-yellow); border: 1px solid rgba(202,138,4,.25);  }
.mp-red    { background: rgba(220,38,38,.1);   color: var(--c-red);    border: 1px solid rgba(220,38,38,.25);  }
.mp-mono { font-family: 'JetBrains Mono', monospace; }
.caret { font-size: .72rem; color: var(--c-dim); transition: transform .2s; }
.caret.open { transform: rotate(180deg); }

/* ── Crew inner table ───────────────────────────────────── */
.crew-table-wrap {
  display: none;
  border-top: 1px solid var(--c-border);
}
.crew-table-wrap.open { display: block; }
.crew-table {
  width: 100%; border-collapse: collapse;
  font-size: .78rem;
}
.crew-table thead th {
  background: var(--c-muted); padding: .35rem .75rem;
  text-align: left; font-size: .67rem; text-transform: uppercase;
  letter-spacing: .06em; color: var(--c-dim); font-weight: 700;
  border-bottom: 1px solid var(--c-border);
}
.crew-table thead th.r { text-align: right; }
.crew-table tbody td {
  padding: .38rem .75rem; border-bottom: 1px solid var(--c-border);
  color: var(--text, #111); vertical-align: middle;
}
.crew-table tbody tr:last-child td { border-bottom: none; }
.crew-table tbody tr:hover { background: rgba(14,165,233,.03); }
.crew-table .r { text-align: right; }
.crew-table tfoot td {
  padding: .38rem .75rem; font-weight: 700;
  background: var(--c-muted); font-size: .76rem; border-top: 1px solid var(--c-border);
}
.crew-table tfoot .r { text-align: right; }
.emp-init {
  width: 28px; height: 28px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: .68rem; font-weight: 800; color: var(--c-blue);
  background: rgba(14,165,233,.12); border: 1px solid rgba(14,165,233,.25);
  vertical-align: middle; margin-right: .35rem; flex-shrink: 0;
}
.emp-img {
  width: 28px; height: 28px; border-radius: 50%;
  object-fit: cover; border: 1px solid var(--c-border);
  vertical-align: middle; margin-right: .35rem;
}
.pos-driver  { color: var(--c-purple); font-weight: 700; }
.pos-helper  { color: var(--c-orange); font-weight: 700; }
.pos-wh      { color: var(--c-green);  font-weight: 700; }
.mono { font-family: 'JetBrains Mono', monospace; }
.rate-val { color: var(--c-green); font-weight: 700; font-family: 'JetBrains Mono', monospace; }
.allow-val { color: var(--c-yellow); font-family: 'JetBrains Mono', monospace; }

/* ── Status badges ──────────────────────────────────────── */
.badge-regular    { background: #dcfce7; color: #166534; border: 1px solid #86efac; border-radius: 999px; padding: 1px 8px; font-size: .68rem; font-weight: 700; }
.badge-prob       { background: #fef9c3; color: #713f12; border: 1px solid #fde047; border-radius: 999px; padding: 1px 8px; font-size: .68rem; font-weight: 700; }
.badge-extra      { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; border-radius: 999px; padding: 1px 8px; font-size: .68rem; font-weight: 700; }

/* ── Truck table ────────────────────────────────────────── */
.table-scroll { overflow-x: auto; border: 1px solid var(--c-border); border-radius: 10px; }
.data-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.data-table thead th {
  background: var(--c-muted); padding: .5rem .75rem;
  font-size: .68rem; text-transform: uppercase; letter-spacing: .05em;
  color: var(--c-dim); font-weight: 700; white-space: nowrap;
  border-bottom: 1.5px solid var(--c-border);
  cursor: pointer; user-select: none;
}
.data-table thead th:hover { color: var(--c-blue); }
.data-table thead th.r { text-align: right; }
.data-table tbody td {
  padding: .45rem .75rem; border-bottom: 1px solid var(--c-border);
  color: var(--text, #111); vertical-align: middle;
}
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table tbody tr:hover { background: rgba(14,165,233,.03); }
.data-table .r { text-align: right; }
.plate-tag {
  font-family: 'JetBrains Mono', monospace; font-weight: 800; font-size: .82rem;
  color: var(--c-blue); background: rgba(14,165,233,.08);
  border: 1px solid rgba(14,165,233,.25); border-radius: 6px;
  padding: 1px 8px; white-space: nowrap;
}
.dim { color: var(--c-dim); }
.bold { font-weight: 700; }

/* ── Shorts tab ─────────────────────────────────────────── */
.shorts-list { display: flex; flex-direction: column; gap: .4rem; }
.short-row {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: 10px; overflow: hidden;
}
.short-row.has-shorts { border-left: 3px solid var(--c-red); }
.short-head {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center; gap: .5rem;
  padding: .5rem .85rem;
  cursor: pointer; user-select: none;
  transition: background .12s;
}
.short-head:hover { background: var(--c-muted); }
.short-head-left { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.emp-name-bold { font-size: .85rem; font-weight: 700; color: var(--text, #111); }
.short-head-right { display: flex; align-items: center; gap: .5rem; }
.short-total {
  font-family: 'JetBrains Mono', monospace; font-weight: 800;
  font-size: .9rem; color: var(--c-red);
}
.short-inner-table {
  width: 100%; border-collapse: collapse; font-size: .77rem;
}
.short-inner-table thead th {
  background: #fff5f5; padding: .32rem .75rem;
  font-size: .65rem; text-transform: uppercase; letter-spacing: .06em;
  color: #9f1239; font-weight: 700; border-bottom: 1px solid #fecdd3;
}
.short-inner-table thead th.r { text-align: right; }
.short-inner-table tbody td {
  padding: .35rem .75rem; border-bottom: 1px solid var(--c-border);
  vertical-align: middle;
}
.short-inner-table tbody tr:last-child td { border-bottom: none; }
.short-inner-table .r { text-align: right; }
.doc-no { font-family: 'JetBrains Mono', monospace; font-weight: 700; color: var(--c-purple); font-size: .78rem; }
.short-amt { font-family: 'JetBrains Mono', monospace; font-weight: 800; color: var(--c-red); }
.badge-unsettled { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; border-radius: 999px; padding: 1px 8px; font-size: .67rem; font-weight: 700; }

/* ── Empty state ─────────────────────────────────────────── */
.empty-state {
  text-align: center; padding: 2.5rem 1rem;
  color: var(--c-dim); font-size: .85rem;
}
.empty-state i { font-size: 2rem; display: block; margin-bottom: .4rem; opacity: .4; }

/* ── Pagination ──────────────────────────────────────────── */
.pager {
  display: flex; align-items: center; justify-content: space-between;
  padding: .65rem 0; margin-top: .5rem; flex-wrap: wrap; gap: .4rem;
}
.pager-info { font-size: .77rem; color: var(--c-dim); }
.pager-btns { display: flex; gap: .3rem; }
.btn-page {
  display: inline-flex; align-items: center; gap: .2rem;
  padding: .3rem .75rem; border-radius: 7px; font-size: .77rem; font-weight: 600;
  border: 1.5px solid var(--c-border); background: var(--c-surface);
  color: var(--text, #374151); text-decoration: none; transition: all .12s;
}
.btn-page:hover:not(.disabled) { border-color: var(--c-blue); color: var(--c-blue); }
.btn-page.disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }

/* ── Responsive ──────────────────────────────────────────── */
/* ── Short Items Modal ──────────────────────────────────── */
#ssItemsModal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,.45); align-items: center; justify-content: center; }
#ssItemsModal.open { display: flex; }
.ss-modal-box { background: var(--c-surface); border-radius: 14px; width: 92%; max-width: 780px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.2); overflow: hidden; }
.ss-modal-header { display: flex; align-items: center; justify-content: space-between; padding: .85rem 1.1rem; border-bottom: 1px solid var(--c-border); background: #fff5f5; }
.ss-modal-title { font-weight: 800; font-size: .92rem; color: var(--c-ink); }
.ss-modal-close { font-size: 1.3rem; line-height: 1; cursor: pointer; color: var(--c-dim); background: none; border: none; padding: 0 .2rem; }
.ss-modal-close:hover { color: var(--c-red); }
.ss-modal-body { overflow-y: auto; padding: .85rem 1.1rem; flex: 1; }
.ss-modal-footer { padding: .6rem 1.1rem; border-top: 1px solid var(--c-border); font-size: .75rem; color: var(--c-dim); background: var(--c-muted); text-align: right; }
.ss-items-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.ss-items-table th { background: #f9fafb; padding: .35rem .65rem; border: 1px solid var(--c-border); font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--c-dim); text-align: left; white-space: nowrap; }
.ss-items-table td { padding: .38rem .65rem; border: 1px solid var(--c-border); vertical-align: middle; }
.ss-items-table tr:nth-child(even) td { background: #fafafa; }
.ss-items-table tr:hover td { background: #fff5f5; }
.ss-loading { text-align: center; padding: 2rem; color: var(--c-dim); font-size: .85rem; }
.badge-short-status { font-size: .68rem; font-weight: 700; border-radius: 999px; padding: 1px 9px; white-space: nowrap; }
.bss-open    { background: #fee2e2; color: #991b1b; }
.bss-partial { background: #fef9c3; color: #854d0e; }
.bss-closed  { background: #dcfce7; color: #166534; }

@media (max-width: 640px) {
  .stat-strip { grid-template-columns: repeat(2, 1fr); }
  .stat-cell:nth-child(2n) { border-radius: 0; }
  .sched-head { grid-template-columns: 90px 1fr auto; }
  .search-input input { width: 120px; }
  .filter-bar { flex-direction: column; align-items: stretch; }
  .filter-bar select,
  .filter-bar input[type="date"] { width: 100%; }
}
/* ── Crew button (in truck table) ──────────────────────── */
.btn-crew {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: 3px 10px; border-radius: 7px; cursor: pointer;
  font-size: .78rem; font-weight: 700;
  background: rgba(14,165,233,.08); color: var(--c-blue);
  border: 1.5px solid rgba(14,165,233,.3);
  transition: all .13s; white-space: nowrap;
}
.btn-crew:hover {
  background: rgba(14,165,233,.18); border-color: var(--c-blue);
  box-shadow: 0 2px 8px rgba(14,165,233,.2);
}
.crew-mini-badges { display: inline-flex; gap: 2px; margin-left: 2px; }
.crew-mini-badges span {
  font-size: .63rem; font-weight: 800; padding: 1px 5px;
  border-radius: 999px; line-height: 1.4;
}
.cmb-drv  { background: rgba(124,58,237,.12); color: var(--c-purple); border: 1px solid rgba(124,58,237,.25); }
.cmb-hlp  { background: rgba(234,88,12,.12);  color: var(--c-orange); border: 1px solid rgba(234,88,12,.25);  }
.cmb-reg  { background: rgba(22,163,74,.12);  color: var(--c-green);  border: 1px solid rgba(22,163,74,.25);  }
.cmb-prob { background: rgba(202,138,4,.12);  color: var(--c-yellow); border: 1px solid rgba(202,138,4,.25);  }
.cmb-ext  { background: rgba(14,165,233,.12); color: var(--c-blue);   border: 1px solid rgba(14,165,233,.25); }

/* ── Crew Modal ─────────────────────────────────────────── */
.crew-modal-overlay {
  display: none; position: fixed; inset: 0; z-index: 1000;
  background: rgba(15,23,42,.45); backdrop-filter: blur(3px);
  align-items: center; justify-content: center;
  padding: 1rem;
}
.crew-modal-overlay.open { display: flex; }
.crew-modal-box {
  background: var(--c-surface); border: 1px solid var(--c-border);
  border-radius: 14px; width: 100%; max-width: 780px;
  max-height: 88vh; display: flex; flex-direction: column;
  box-shadow: 0 20px 60px rgba(0,0,0,.18);
  overflow: hidden;
}
.crew-modal-header {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: 1rem 1.25rem .85rem;
  border-bottom: 1px solid var(--c-border);
  flex-shrink: 0;
}
.crew-modal-title {
  display: flex; align-items: center; gap: .4rem;
  font-size: .95rem; font-weight: 800; color: var(--c-ink);
  flex: 1; min-width: 0;
}
.crew-modal-meta {
  display: flex; flex-wrap: wrap; gap: .35rem; align-items: center;
  font-size: .78rem;
}
.crew-modal-close {
  background: none; border: none; cursor: pointer; padding: 4px 6px;
  color: var(--c-dim); border-radius: 6px; font-size: .9rem;
  transition: all .12s; flex-shrink: 0;
}
.crew-modal-close:hover { background: #fee2e2; color: var(--c-red); }
.crew-modal-body {
  overflow-y: auto; padding: .75rem 1.25rem; flex: 1;
}
.crew-modal-footer {
  padding: .65rem 1.25rem; border-top: 1px solid var(--c-border);
  font-size: .75rem; color: var(--c-dim); display: flex;
  flex-wrap: wrap; gap: .5rem; align-items: center; flex-shrink: 0;
  background: var(--c-muted);
}
.crew-modal-table {
  width: 100%; border-collapse: collapse; font-size: .82rem;
}
.crew-modal-table thead th {
  background: var(--c-muted); padding: .35rem .7rem;
  text-align: left; font-size: .67rem; text-transform: uppercase;
  letter-spacing: .06em; color: var(--c-dim); font-weight: 700;
  border-bottom: 1px solid var(--c-border); white-space: nowrap;
}
.crew-modal-table tbody td {
  padding: .4rem .7rem; border-bottom: 1px solid var(--c-border);
  color: var(--text, #111); vertical-align: middle;
}
.crew-modal-table tbody tr:last-child td { border-bottom: none; }
.crew-modal-table tbody tr:hover { background: rgba(14,165,233,.03); }
.crew-modal-empty {
  text-align: center; padding: 2rem 1rem;
  color: var(--c-dim); font-size: .85rem;
}
.crew-modal-empty i { font-size: 1.8rem; display: block; margin-bottom: .3rem; opacity: .35; }
</style>
<body>

<?php $topbar_page = 'personnel_truck_assignment'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page header ────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">Logistics Schedule <span>Monitoring</span></div>
      <div class="page-badge">
        📅 <?= $anyFilter
            ? 'Filtered: ' . htmlspecialchars($baseFrom) . ' → ' . htmlspecialchars($baseTo)
            : 'This Month: ' . date('F Y') ?>
        · Live Data
      </div>
    </div>
  </div>

<!-- ── Stat strip ─────────────────────────────────────── -->
  <?php
    $totalShortStocksAmt = $totalShortStocksBalance;
  ?>
  <div class="stat-strip">
    <div class="stat-cell" style="--sc-accent:var(--c-blue)">
      <i class="bi bi-calendar2-check sc-icon"></i>
      <span class="sc-label">Schedules</span>
      <span class="sc-value"><?= number_format((int)($stat['TotalSchedules'] ?? 0)) ?></span>
      <span class="sc-sub"><?= number_format((int)($stat['TotalTrucks'] ?? 0)) ?> trucks</span>
    </div>
    <div class="stat-cell" style="--sc-accent:var(--c-purple)">
      <i class="bi bi-people-fill sc-icon"></i>
      <span class="sc-label">Total Crew</span>
      <span class="sc-value"><?= number_format((int)($stat['TotalCrew'] ?? 0)) ?></span>
      <span class="sc-sub"><?= number_format((int)($stat['TotalDrivers'] ?? 0)) ?> drivers · <?= number_format((int)($stat['TotalHelpers'] ?? 0)) ?> helpers</span>
    </div>
    <div class="stat-cell" style="--sc-accent:var(--c-green)">
      <i class="bi bi-person-check-fill sc-icon"></i>
      <span class="sc-label">By Status</span>
      <span class="sc-value" style="font-size:1.1rem;display:flex;gap:.5rem;align-items:baseline;">
        <span style="color:var(--c-green)"><?= (int)($stat['RegularCount'] ?? 0) ?></span>
        <span style="color:var(--c-yellow);font-size:.9rem"><?= (int)($stat['ProbationaryCount'] ?? 0) ?></span>
        <span style="color:var(--c-blue);font-size:.9rem"><?= (int)($stat['ExtraCount'] ?? 0) ?></span>
      </span>
      <span class="sc-sub">Reg · Prob · Extra</span>
    </div>
    <div class="stat-cell" style="--sc-accent:var(--c-orange)">
      <i class="bi bi-box-seam-fill sc-icon"></i>
      <span class="sc-label">Cases</span>
      <span class="sc-value"><?= number_format((int)($stat['TotalCases'] ?? 0)) ?></span>
      <span class="sc-sub"><?= number_format((int)($stat['TotalCalls'] ?? 0)) ?> calls</span>
    </div>
    <div class="stat-cell" style="--sc-accent:var(--c-red)">
      <i class="bi bi-wallet2 sc-icon"></i>
      <span class="sc-label">Short Stocks</span>
      <span class="sc-value" style="color:var(--c-red)"><?= number_format($totalSdidCount) ?> <span style="font-size:.85rem;color:var(--c-dim)">SDID</span></span>
      <span class="sc-sub"><?= number_format($totalShortStocksCount) ?> employees · <?= peso($totalSdidBalance) ?> balance</span>
    </div>
  </div>

  <!-- ── Filter bar ─────────────────────────────────────── -->
  <form method="GET" action="" id="filterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($selTab) ?>">
    <div class="filter-bar">
      <?php if ($sessionDept !== ''): ?>
        <span class="meta-pill mp-blue" title="Showing data for your department" style="cursor:default;font-size:.74rem;">
          <i class="bi bi-building"></i>&nbsp;<?= htmlspecialchars($sessionDept) ?>
        </span>
        <div class="filter-sep"></div>
      <?php endif; ?>
      <span class="filter-label"><i class="bi bi-calendar3"></i></span>
      <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="Date From">
      <input type="date" name="date_to"   value="<?= htmlspecialchars($dateTo) ?>"   title="Date To">
      <div class="filter-sep"></div>
      <select name="area" title="Area">
        <option value="">All Areas</option>
        <?php foreach ($areaList as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="plate" title="Plate Number">
        <option value="">All Plates</option>
        <?php foreach ($plateList as $pl): ?>
          <option value="<?= htmlspecialchars($pl) ?>" <?= $selPlate === $pl ? 'selected' : '' ?>><?= htmlspecialchars($pl) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" title="Employment Status">
        <option value="">All Statuses</option>
        <?php foreach ($statusList as $st): ?>
          <option value="<?= htmlspecialchars($st) ?>" <?= $selStatus === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="filter-sep"></div>
      <button type="submit" class="btn-filter apply"><i class="bi bi-funnel"></i> Apply</button>
      <a href="?tab=<?= htmlspecialchars($selTab) ?>" class="btn-filter reset"><i class="bi bi-x"></i> Reset</a>
      <?php if ($anyFilter): ?>
        <span class="meta-pill mp-blue"><i class="bi bi-funnel-fill"></i> Filtered</span>
      <?php endif; ?>
    </div>
  </form>

 <!-- ── Tab nav ────────────────────────────────────────── -->
  <div class="tab-nav">
    <a href="<?= tabUrl('truck') ?>" class="<?= $selTab === 'truck' ? 'active' : '' ?>">
      <i class="bi bi-truck-front-fill"></i> Truck Schedule
      <span class="tab-badge"><?= number_format($totalTrucks) ?></span>
    </a>
    <a href="<?= tabUrl('shortstocks_sdid') ?>" class="<?= $selTab === 'shortstocks_sdid' ? 'active-red' : '' ?>">
      <i class="bi bi-box-seam"></i> Team Short Stocks
      <span class="tab-badge red"><?= number_format($totalSdidCount) ?></span>
    </a>
    <a href="<?= tabUrl('shortstocks_emp') ?>" class="<?= $selTab === 'shortstocks_emp' ? 'active-red' : '' ?>">
      <i class="bi bi-person-exclamation"></i> Individual Short Stocks
      <span class="tab-badge red"><?= number_format($totalShortStocksCount) ?></span>
    </a>
  </div>



  <!-- ════════════════════════════════════════════════════
       TAB: TRUCK SCHEDULE
  ════════════════════════════════════════════════════ -->
  <?php if ($selTab === 'truck'): ?>

  <div class="section-toolbar">
    <div class="section-toolbar-left">
      <i class="bi bi-truck-front-fill" style="color:var(--c-blue);font-size:.9rem"></i>
      <span style="font-weight:700;font-size:.82rem">Truck Schedule</span>
      <span class="section-count"><?= $totalTrucks ?> trucks</span>
    </div>
    <div class="search-input">
      <i class="bi bi-search"></i>
      <input type="text" id="searchBox" placeholder="Search table…" oninput="filterTable(this.value)">
    </div>
    <button class="btn-sm green"  onclick="exportTruckCSV()"><i class="bi bi-download"></i> CSV</button>
    <button class="btn-sm blue"   onclick="exportTruckExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
    <button class="btn-sm violet" onclick="printTruck()"><i class="bi bi-printer"></i> Print</button>
  </div>

  <div class="table-scroll">
  <table class="data-table" id="truckTable">
    <thead><tr>
      <th onclick="sortTable(0)">ID ⇅</th>
      <th>Dept</th>
      <th onclick="sortTable(2)">Date ⇅</th>
      <th>Plate</th>
      <th>Area</th>
      <th>Remarks</th>
      <th class="r" onclick="sortTable(6)">Cases ⇅</th>
      <th class="r" onclick="sortTable(7)">Calls ⇅</th>
      <th class="r">Crew</th>
    </tr></thead>
    <tbody>
    <?php if (empty($displayTrucks)): ?>
      <tr><td colspan="9"><div class="empty-state"><i class="bi bi-truck"></i>No truck schedules found.</div></td></tr>
    <?php else: foreach ($displayTrucks as $t):
      $dc = deptColor($t['Department'] ?? '');
    ?>
      <tr>
        <td class="mono dim" style="font-size:.75rem"><?= htmlspecialchars($t['TruckScheduleID']) ?></td>
        <td>
          <span style="display:inline-flex;align-items:center;gap:.3rem;">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $dc ?>;flex-shrink:0;"></span>
            <span style="font-size:.77rem;font-weight:600"><?= htmlspecialchars($t['Department'] ?? '') ?></span>
          </span>
        </td>
        <td class="mono dim" style="font-size:.77rem"><?= htmlspecialchars($t['ScheduleDate'] ?? '—') ?></td>
        <td><span class="plate-tag"><?= htmlspecialchars($t['PlateNumber'] ?? '—') ?></span></td>
        <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($t['Area'] ?? '—') ?></td>
        <td class="dim" style="font-size:.75rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($t['Remarks'] ?? '') ?>"><?= htmlspecialchars($t['Remarks'] ?? '—') ?></td>
        <td class="r mono"><?= number_format((int)($t['Cases'] ?? 0)) ?></td>
        <td class="r mono"><?= number_format((int)($t['Calls'] ?? 0)) ?></td>
        <td class="r">
          <?php
            $tsidKey  = (string)($t['TruckScheduleID'] ?? '');
            $crewList = $crewByTsid[$tsidKey] ?? [];
            $crewCnt  = count($crewList);
            $drvCnt   = count(array_filter($crewList, fn($c) => str_contains(strtolower($c['Position'] ?? ''), 'driver')));
            $hlpCnt   = $crewCnt - $drvCnt;
            $regCnt   = count(array_filter($crewList, fn($c) => strtolower(trim($c['EmploymentStatus'] ?? '')) === 'regular'));
            $probCnt  = count(array_filter($crewList, fn($c) => str_contains(strtolower($c['EmploymentStatus'] ?? ''), 'probation')));
            $extCnt   = count(array_filter($crewList, fn($c) => strtolower(trim($c['EmploymentStatus'] ?? '')) === 'extra'));
          ?>
          <button class="btn-crew"
            onclick="openCrewModal(<?= htmlspecialchars(json_encode($tsidKey)) ?>,
                                   <?= htmlspecialchars(json_encode($t['PlateNumber'] ?? '')) ?>,
                                   <?= htmlspecialchars(json_encode($t['ScheduleDate'] ?? '')) ?>,
                                   <?= htmlspecialchars(json_encode($t['Area'] ?? '')) ?>)"
            title="View crew details">
            <i class="bi bi-people-fill"></i> <?= $crewCnt ?>
            <?php if ($crewCnt > 0): ?>
              <span class="crew-mini-badges">
                <?php if ($drvCnt > 0): ?><span class="cmb-drv"><?= $drvCnt ?>D</span><?php endif; ?>
                <?php if ($hlpCnt > 0): ?><span class="cmb-hlp"><?= $hlpCnt ?>H</span><?php endif; ?>
                <?php if ($regCnt  > 0): ?><span class="cmb-reg"><?= $regCnt ?>R</span><?php endif; ?>
                <?php if ($probCnt > 0): ?><span class="cmb-prob"><?= $probCnt ?>P</span><?php endif; ?>
                <?php if ($extCnt  > 0): ?><span class="cmb-ext"><?= $extCnt ?>E</span><?php endif; ?>
              </span>
            <?php endif; ?>
          </button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <?php endif; /* end truck tab */ ?>


  <!-- ════════════════════════════════════════════════════
       TAB: SHORT STOCKS — BY SDID (Grouped Delivery Schedule)
  ════════════════════════════════════════════════════ -->
  <?php if ($selTab === 'shortstocks_sdid'): ?>

  <style>
  .sdid-status-toggle { display:flex;align-items:center;gap:.25rem;background:var(--c-muted);border:1.5px solid var(--c-border);border-radius:8px;padding:3px;height:32px; }
  .sdid-svt-btn { padding:2px 10px;border-radius:6px;font-size:.76rem;font-weight:600;color:var(--c-dim);text-decoration:none;transition:all .15s;white-space:nowrap;height:24px;display:flex;align-items:center; }
  .sdid-svt-btn:hover { background:#e5e7eb;color:var(--text,#374151); }
  .sdid-svt-active { background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1); }
  .sdid-svt-confirmed { color:#166534 !important;background:#dcfce7 !important; }
  .sdid-svt-created   { color:#713f12 !important;background:#fef9c3 !important; }
  .sdid-svt-void      { color:#991b1b !important;background:#fee2e2 !important; }
  .sdid-svt-all       { color:#1e40af !important;background:#dbeafe !important; }
  </style>

  <?php
    function ssStatusUrl(string $v): string {
        $p = $_GET; $p['ss_status'] = $v; unset($p['page']);
        return '?' . http_build_query($p);
    }
  ?>

  <div class="section-toolbar">
    <div class="section-toolbar-left">
      <i class="bi bi-box-seam" style="color:var(--c-red);font-size:.9rem"></i>
      <span style="font-weight:700;font-size:.82rem">Short Stocks — By SDID</span>
      <span class="section-count" style="background:#fee2e2;color:#991b1b;border-color:#f87171"><?= count($sdidSummaries) ?> groups</span>
      <span style="font-size:.75rem;color:var(--c-dim)">Total Balance:
        <b style="color:var(--c-red);"><?= peso($totalSdidBalance) ?></b>
      </span>
    </div>
    <!-- Status sub-toggle -->
    <div class="sdid-status-toggle">
      <a href="<?= ssStatusUrl('confirmed') ?>" class="sdid-svt-btn <?= $ssStatusFilter === 'confirmed' ? 'sdid-svt-active sdid-svt-confirmed' : '' ?>">✓ Confirmed</a>
      <a href="<?= ssStatusUrl('created') ?>"   class="sdid-svt-btn <?= $ssStatusFilter === 'created'   ? 'sdid-svt-active sdid-svt-created'   : '' ?>">🕐 Created</a>
      <a href="<?= ssStatusUrl('void') ?>"      class="sdid-svt-btn <?= $ssStatusFilter === 'void'      ? 'sdid-svt-active sdid-svt-void'      : '' ?>">✕ Void</a>
      <a href="<?= ssStatusUrl('all') ?>"       class="sdid-svt-btn <?= $ssStatusFilter === 'all'       ? 'sdid-svt-active sdid-svt-all'       : '' ?>">📋 All</a>
    </div>
    <div class="search-input">
      <i class="bi bi-search"></i>
      <input type="text" id="sdidSearch" placeholder="Search SDID, dept, area, plate…" oninput="searchSdid(this.value)">
    </div>
    <button class="btn-sm green" onclick="exportSdidCSV()"><i class="bi bi-download"></i> CSV</button>
    <button class="btn-sm blue"  onclick="exportSdidExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
  </div>

  <?php if (empty($sdidSummaries)): ?>
    <div class="empty-state" style="border:1px solid var(--c-border);border-radius:10px;">
      <i class="bi bi-check-circle" style="color:var(--c-green);opacity:1;font-size:2rem"></i>
      No short stock groups found for the selected status.
    </div>
  <?php else: ?>
  <div class="table-scroll">
  <table class="data-table" id="sdidTable">
    <thead><tr>
      <th onclick="sortSdidTable(0)">SDID ⇅</th>
      <th class="r" onclick="sortSdidTable(1)"># Employees ⇅</th>
      <th>Department(s)</th>
      <th>Area(s)</th>
      <th>Plate No.</th>
      <th onclick="sortSdidTable(5)">Latest Schedule ⇅</th>
      <th class="r" onclick="sortSdidTable(6)">Total Amount ⇅</th>
      <th class="r" onclick="sortSdidTable(7)">Amount Paid ⇅</th>
      <th class="r" onclick="sortSdidTable(8)">Balance Due ⇅</th>
      <th>Status</th>
    </tr></thead>
    <tbody id="sdidTableBody">
    <?php foreach ($sdidSummaries as $s):
      $sBal = (float)$s['TotalBalance'];
      $rowBg = $sBal >= 5000 ? 'background:#fff0f0;border-left:3px solid #ef4444;'
             : ($sBal >= 1000 ? 'background:#fffbeb;border-left:3px solid #eab308;' : '');
      $s1 = strtoupper(trim($s['Status1'] ?? ''));
      [$s1bg, $s1color, $s1border, $s1icon] = match($s1) {
          'CONFIRMED' => ['#dcfce7','#166534','#4ade80','✓'],
          'CREATED'   => ['#fef9c3','#713f12','#fde047','🕐'],
          'VOID'      => ['#fee2e2','#991b1b','#f87171','✕'],
          default     => ['#f3f4f6','#374151','#d1d5db','•'],
      };
      $searchStr = strtolower(($s['SDID']??'').' '.($s['Departments']??'').' '.($s['Areas']??'').' '.($s['PlateNumber']??''));
    ?>
      <tr style="<?= $rowBg ?>cursor:pointer;" data-search="<?= htmlspecialchars($searchStr) ?>"
          class="sdid-summary-row" data-sdid="<?= htmlspecialchars((string)$s['SDID']) ?>"
          onclick="toggleSdidDetail('sdid-detail-<?= htmlspecialchars((string)$s['SDID']) ?>', 'sdid-caret-<?= htmlspecialchars((string)$s['SDID']) ?>')" title="Click to expand employees">
        <td><span style="font-weight:800;font-family:monospace;color:var(--c-purple);font-size:.9rem"><?= htmlspecialchars((string)$s['SDID']) ?></span>
          <i class="bi bi-chevron-down" id="sdid-caret-<?= htmlspecialchars((string)$s['SDID']) ?>" style="font-size:.65rem;color:var(--c-dim);margin-left:.3rem;transition:transform .2s"></i>
        </td>
        <td class="r mono bold">
          <span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:999px;padding:2px 10px;font-size:.78rem;font-weight:700;"><?= number_format($s['RowCount']) ?></span>
        </td>
        <td style="font-size:.8rem"><?= htmlspecialchars($s['Departments'] ?: '—') ?></td>
        <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($s['Areas'] ?: '—') ?></td>
        <td><span class="plate-tag" style="font-size:.75rem"><?= htmlspecialchars($s['PlateNumber'] ?: '—') ?></span></td>
        <td class="mono dim" style="font-size:.8rem"><?= htmlspecialchars($s['LatestDate'] ?: '—') ?></td>
        <td class="r mono bold" style="color:var(--c-green)"><?= peso($s['TotalAmount']) ?></td>
        <td class="r mono" style="color:var(--c-green)"><?= peso($s['TotalPaid']) ?></td>
        <td class="r mono bold" style="color:var(--c-red);font-size:.95rem">▼ <?= peso($sBal) ?></td>
        <td>
          <span style="background:<?= $s1bg ?>;color:<?= $s1color ?>;border:1px solid <?= $s1border ?>;border-radius:999px;padding:2px 9px;font-size:.73rem;font-weight:700;white-space:nowrap;">
            <?= $s1icon ?> <?= htmlspecialchars($s['Status1'] ?: '—') ?>
          </span>
        </td>
      </tr>
      <?php $thisEmpRows = $sdidGrouped[$s['SDID']] ?? []; ?>
      <tr id="sdid-detail-<?= htmlspecialchars((string)$s['SDID']) ?>" class="sdid-detail-row" style="display:none;">
        <td colspan="10" style="padding:0;background:#f9f5ff;">
          <div style="padding:.5rem .75rem .75rem;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;border:1px solid #ddd6fe;border-radius:8px;overflow:hidden;">
              <thead>
                <tr style="background:#ede9fe;">
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Employee ID</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Name</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Position</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Type</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Outlet / Area</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Total Amt</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Paid</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Balance</th>
                  <th style="padding:.3rem .6rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Items</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Date Paid</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($thisEmpRows as $ei => $emp): ?>
                <?php
                  $empBal  = (float)($emp['Balance']    ?? 0);
                  $empPaid = (float)($emp['PaidAmount'] ?? 0);
                  $empAmt  = (float)($emp['AmountDue']  ?? 0); // total short for this employee
                  $empBg2  = $ei % 2 === 0 ? '#fff' : '#faf5ff';
                  $hasPaid = $empPaid > 0;
                  $datePaid = $emp['DatePaid'] ?? '';
                ?>
                <tr style="background:<?= $empBg2 ?>;">
                  <td style="padding:.32rem .6rem;font-family:monospace;color:var(--c-dim);font-size:.77rem;"><?= htmlspecialchars($emp['EmployeeID'] ?? '—') ?></td>
                  <td style="padding:.32rem .6rem;font-weight:700;font-size:.8rem;">
                    <span style="display:inline-flex;align-items:center;gap:.3rem;">
                      <span style="width:22px;height:22px;border-radius:50%;background:rgba(124,58,237,.12);color:#7c3aed;font-size:.63rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"><?= strtoupper(substr(trim($emp['EmployeeName'] ?? ''), 0, 2)) ?></span>
                      <?= htmlspecialchars($emp['EmployeeName'] ?? '—') ?>
                    </span>
                  </td>
                  <td style="padding:.32rem .6rem;font-size:.77rem;color:var(--c-dim);"><?= htmlspecialchars($emp['Position'] ?? '—') ?></td>
                  <td style="padding:.32rem .6rem;font-size:.77rem;"><?= htmlspecialchars($emp['TypeShort'] ?? '—') ?></td>
                  <td style="padding:.32rem .6rem;font-size:.77rem;color:var(--c-dim);"><?= htmlspecialchars(($emp['Outlet'] ?? '') ?: ($emp['Area'] ?? '—')) ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:600;"><?= peso($empAmt) ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;color:<?= $hasPaid ? 'var(--c-green)' : 'var(--c-dim)' ?>;font-weight:<?= $hasPaid ? '700' : '400' ?>;">
                    <?= $hasPaid ? peso($empPaid) . '<i class="bi bi-check-circle-fill" style="font-size:.68rem;color:var(--c-green);margin-left:.2rem;"></i>' : '<span style=\'color:#d1d5db\'>—</span>' ?>
                  </td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:800;color:<?= $empBal > 0 ? 'var(--c-red)' : 'var(--c-green)' ?>;">
                    <?= $empBal > 0 ? '&#9660; ' . peso($empBal) : '<span style=\'color:var(--c-green)\'>&#10003; Settled</span>' ?>
                  </td>
                  <td style="padding:.32rem .6rem;text-align:center;">
                    <button onclick="event.stopPropagation();openSsItems(<?= (int)($emp['SEID'] ?? 0) ?>, <?= htmlspecialchars(json_encode($emp['EmployeeName'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['RefNo'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['EmployeeID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($sdid)) ?>)" style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:6px;border:1.5px solid #fca5a5;background:#fee2e2;color:#991b1b;cursor:pointer;white-space:nowrap;"><i class="bi bi-list-ul"></i> Items</button>
                  </td>
                  <td style="padding:.32rem .6rem;font-size:.77rem;font-family:monospace;">
                    <?php if (!empty($datePaid)): ?>
                      <span style="color:var(--c-green);font-weight:600;"><i class="bi bi-calendar-check" style="font-size:.7rem"></i> <?= htmlspecialchars($datePaid) ?></span>
                    <?php else: ?>
                      <span style="color:#d1d5db">Not yet paid</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#ede9fe;">
                  <td colspan="5" style="padding:.35rem .6rem;font-weight:700;font-size:.76rem;color:#5b21b6;"><?= count($thisEmpRows) ?> employee(s)</td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;"><?= peso((float)$s['TotalAmount']) ?></td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;color:var(--c-green);"><?= peso((float)$s['TotalPaid']) ?></td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:800;color:var(--c-red);">&#9660; <?= peso((float)$s['TotalBalance']) ?></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6" style="padding:.5rem .75rem;font-weight:700;font-size:.8rem;">TOTAL — <?= count($sdidSummaries) ?> SDID groups</td>
        <td class="r mono bold" style="color:var(--c-green);padding:.5rem .75rem"><?= peso(array_sum(array_column($sdidSummaries,'TotalAmount'))) ?></td>
        <td class="r mono" style="padding:.5rem .75rem"><?= peso(array_sum(array_column($sdidSummaries,'TotalPaid'))) ?></td>
        <td class="r mono bold" style="color:var(--c-red);padding:.5rem .75rem">▼ <?= peso($totalSdidBalance) ?></td>
        <td style="padding:.5rem .75rem"></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>

  <?php endif; /* end shortstocks_sdid tab */ ?>


  <!-- ════════════════════════════════════════════════════
       TAB: SHORT STOCKS — BY EMPLOYEE (Individual Records)
  ════════════════════════════════════════════════════ -->
  <?php if ($selTab === 'shortstocks_emp'): ?>

  <style>
  .sspay-list { display: flex; flex-direction: column; gap: .55rem; }
  .sspay-row {
    background: var(--c-surface);
    border: 1.5px solid #fecdd3;
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow .15s;
  }
  .sspay-row:hover { box-shadow: 0 3px 14px rgba(220,38,38,.1); }
  .sspay-head {
    display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
    padding: .6rem .85rem;
    cursor: pointer;
    background: #fff;
    justify-content: space-between;
  }
  .sspay-head:hover { background: #fff5f5; }
  .sspay-head-left  { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; flex: 1; }
  .sspay-head-right { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }
  .sspay-balance {
    font-size: 1rem; font-weight: 800; color: var(--c-red);
    font-family: 'JetBrains Mono', monospace;
    background: #fee2e2; border-radius: 8px; padding: 2px 10px;
  }
  .sspay-body {
    display: none;
    border-top: 1.5px solid #fecdd3;
    background: #fffafa;
  }
  .sspay-body.open { display: block; }
  .sspay-info-bar {
    display: flex; flex-wrap: wrap; gap: .85rem;
    padding: .5rem .85rem;
    font-size: .76rem;
    background: #fff5f5;
    border-bottom: 1px solid #fecdd3;
  }
  .sspay-info-bar span { color: var(--c-dim); }
  .sspay-info-bar b   { color: var(--c-ink); }
  .sspay-items-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .28rem .75rem; border-radius: 7px; font-size: .76rem;
    font-weight: 700; cursor: pointer; border: 1.5px solid #fca5a5;
    background: #fee2e2; color: #991b1b; transition: all .12s;
    margin: .5rem .85rem .5rem;
  }
  .sspay-items-btn:hover { background: #fecaca; }

  </style>

<?php
    // Summary totals for the emp tab header
    $empTotalBalance = array_sum(array_column($shortStocksRows, 'Balance'));
    $empTotalAmt     = array_sum(array_column($shortStocksRows, 'TotalAmount'));
    $empTotalPaid    = array_sum(array_column($shortStocksRows, 'PaidAmount'));
  ?>

  <div class="section-toolbar">
    <div class="section-toolbar-left">
      <i class="bi bi-person-exclamation" style="color:var(--c-red);font-size:.9rem"></i>
      <span style="font-weight:700;font-size:.82rem">Short Stocks — By Employee</span>
      <span class="section-count" style="background:#fee2e2;color:#991b1b;border-color:#f87171"><?= count($shortStocksRows) ?> records</span>
      <span style="font-size:.75rem;color:var(--c-dim)">Balance:
        <b style="color:var(--c-red)"><?= peso($empTotalBalance) ?></b>
        &nbsp;·&nbsp; Paid: <b style="color:var(--c-green)"><?= peso($empTotalPaid) ?></b>
        &nbsp;·&nbsp; Total Amt: <b><?= peso($empTotalAmt) ?></b>
      </span>
    </div>
    <div class="search-input">
      <i class="bi bi-search"></i>
      <input type="text" id="ssSearch" placeholder="Search employee, plate, area, ref…" oninput="searchSS(this.value)">
    </div>
    <button class="btn-sm green" onclick="exportSSCSV()"><i class="bi bi-download"></i> CSV</button>
    <button class="btn-sm blue"  onclick="exportSSExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
  </div>

  <div style="font-size:.74rem;color:var(--c-dim);margin-bottom:.6rem;padding:.5rem .85rem;background:var(--c-muted);border:1px solid var(--c-border);border-radius:8px;display:flex;align-items:center;gap:.4rem;">
    <i class="bi bi-info-circle" style="color:var(--c-blue)"></i>
    Records from Short Payment with an outstanding balance. Click a row to expand details, or <b>View Items</b> to see individual item breakdown.
  </div>

  <?php if (empty($shortStocksRows)): ?>
    <div class="empty-state" style="border:1px solid var(--c-border);border-radius:10px;">
      <i class="bi bi-check-circle" style="color:var(--c-green);opacity:1;font-size:2rem"></i>
      No outstanding short stock balances found. 🎉
    </div>
  <?php else: ?>
  <div class="sspay-list" id="ssPayList">
  <?php foreach ($shortStocksRows as $i => $r):
    $uid      = 'sspay-' . $i;
    $searchStr = strtolower(($r['EmployeeName']??'').' '.($r['PlateNumber']??'').' '.($r['Area']??'').' '.($r['RefNo']??'').' '.($r['EmployeeID']??'').' '.($r['Department']??''));
    $stLow    = strtolower(trim($r['StatusofShort'] ?? ''));
    $stClass  = match(true) {
      str_contains($stLow, 'close') || str_contains($stLow, 'paid') => 'bss-closed',
      str_contains($stLow, 'partial') => 'bss-partial',
      default => 'bss-open',
    };
    $ini = initials($r['EmployeeName'] ?? '');
    $dc  = deptColor($r['Department'] ?? '');
  ?>
  <div class="sspay-row" data-search="<?= htmlspecialchars($searchStr) ?>">
    <div class="sspay-head" onclick="toggleSS('<?= $uid ?>', 'ssc-<?= $uid ?>')">
      <div class="sspay-head-left">
        <span class="emp-init"><?= htmlspecialchars($ini) ?></span>
        <span class="emp-name-bold" style="font-weight:800;font-size:.85rem"><?= htmlspecialchars($r['EmployeeName'] ?? '—') ?></span>
        <span class="meta-pill mp-purple mono" style="font-size:.72rem"><?= htmlspecialchars($r['EmployeeID'] ?? '') ?></span>
        <?php if (!empty($r['PlateNumber'])): ?>
          <span class="plate-tag" style="font-size:.75rem"><?= htmlspecialchars($r['PlateNumber']) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['Area'])): ?>
          <span class="area-text"><?= htmlspecialchars($r['Area']) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['Department'])): ?>
          <span style="display:inline-flex;align-items:center;gap:.2rem;font-size:.72rem;color:var(--c-dim)">
            <span class="dept-dot" style="background:<?= $dc ?>"></span><?= htmlspecialchars($r['Department']) ?>
          </span>
        <?php endif; ?>
        <span class="date-chip"><i class="bi bi-calendar3"></i> <?= htmlspecialchars($r['DateSchedule'] ?? $r['DateGenerate'] ?? '') ?></span>
        <span class="badge-short-status <?= $stClass ?>"><?= htmlspecialchars($r['StatusofShort'] ?? 'Open') ?></span>
      </div>
      <div class="sspay-head-right">
        <span class="sspay-balance">▼ <?= peso((float)($r['Balance'] ?? 0)) ?></span>
        <i class="bi bi-chevron-down caret" id="ssc-<?= $uid ?>"></i>
      </div>
    </div>
    <div class="sspay-body" id="<?= $uid ?>">
      <div class="sspay-info-bar">
        <span><span>SDID:</span> <b class="mono" style="color:var(--c-purple)"><?= htmlspecialchars($r['SDID'] ?? '—') ?></b></span>
        <span><span>Ref No:</span> <b class="mono"><?= htmlspecialchars($r['RefNo'] ?? '—') ?></b></span>
        <span><span>Type:</span> <b><?= htmlspecialchars($r['TypeShort'] ?? '—') ?></b></span>
        <span><span>Category:</span> <b><?= htmlspecialchars($r['Category'] ?? '—') ?></b></span>
        <span><span>Total Amt:</span> <b style="color:var(--c-ink)"><?= peso((float)($r['TotalAmount'] ?? 0)) ?></b></span>
        <span><span>Amt Due:</span> <b style="color:var(--c-yellow)"><?= peso((float)($r['AmountDue'] ?? 0)) ?></b></span>
        <span><span>Paid:</span> <b style="color:var(--c-green)"><?= peso((float)($r['PaidAmount'] ?? 0)) ?></b></span>
        <span><span>Balance:</span> <b style="color:var(--c-red)"><?= peso((float)($r['Balance'] ?? 0)) ?></b></span>
        <?php if (!empty($r['DatePaid'])): ?>
          <span><span>Last Paid:</span> <b class="mono"><?= htmlspecialchars($r['DatePaid']) ?></b></span>
        <?php endif; ?>
        <?php if ((int)($r['NumAccountable'] ?? 0) > 0): ?>
          <span><span>Accountable:</span> <b><?= (int)$r['NumAccountable'] ?> pax</b></span>
        <?php endif; ?>
        <?php if (!empty($r['Remarks'])): ?>
          <span><span>Remarks:</span> <?= htmlspecialchars($r['Remarks']) ?></span>
        <?php endif; ?>
        <?php if (!empty($r['Outlet'])): ?>
          <span><span>Outlet:</span> <b><?= htmlspecialchars($r['Outlet']) ?></b></span>
        <?php endif; ?>
        <?php if (!empty($r['Job_tittle'])): ?>
          <span><span>Job Title:</span> <b><?= htmlspecialchars($r['Job_tittle']) ?></b></span>
        <?php endif; ?>
      </div>
      <button class="sspay-items-btn"
        onclick="openSsItems(
          <?= (int)($r['SEID'] ?? 0) ?>,
          <?= htmlspecialchars(json_encode($r['EmployeeName'] ?? '—')) ?>,
          <?= htmlspecialchars(json_encode($r['RefNo'] ?? '—')) ?>,
          <?= htmlspecialchars(json_encode($r['EmployeeID'] ?? '')) ?>,
          <?= htmlspecialchars(json_encode($r['SDID'] ?? '')) ?>
        )">
        <i class="bi bi-list-ul"></i> View Items
      </button>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php endif; /* end shortstocks_emp tab */ ?>


  <!-- ── Pagination ─────────────────────────────────────── -->
  <?php if ($totalPages > 1): ?>
  <div class="pager" id="teamPager">
    <span class="pager-info">
      Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $currentCount) ?></strong>
      of <strong><?= $currentCount ?></strong> · Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
    </span>
    <div class="pager-btns">
      <?php if ($prevUrl): ?>
        <a href="<?= htmlspecialchars($prevUrl) ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Prev</a>
      <?php else: ?>
        <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Prev</span>
      <?php endif; ?>
      <?php if ($nextUrl): ?>
        <a href="<?= htmlspecialchars($nextUrl) ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
      <?php else: ?>
        <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>


  <div style="font-size:.7rem;color:var(--c-dim);text-align:right;padding:.5rem 0 1rem;">
    Personnel Truck Assignment · Tradewell · <?= date('Y-m-d H:i:s') ?>
  </div>

</div><!-- /.container -->

<!-- ════════════════════════════════════════════════════════
     CREW DETAIL MODAL
════════════════════════════════════════════════════════ -->
<!-- Items modal — always present regardless of active tab -->
<div id="ssItemsModal" onclick="closeSsModal(event)" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
  <div class="ss-modal-box">
    <div class="ss-modal-header">
      <span class="ss-modal-title" id="ssModalTitle">Short Items</span>
      <button class="ss-modal-close" onclick="document.getElementById('ssItemsModal').classList.remove('open')">&#x2715;</button>
    </div>
    <div class="ss-modal-body" id="ssModalBody">
      <div class="ss-loading"><i class="bi bi-hourglass-split"></i> Loading…</div>
    </div>
    <div class="ss-modal-footer" id="ssModalFooter">—</div>
  </div>
</div>

<div id="crewModal" class="crew-modal-overlay" onclick="closeCrewModal(event)">
  <div class="crew-modal-box">
    <div class="crew-modal-header">
      <div class="crew-modal-title">
        <i class="bi bi-people-fill" style="color:var(--c-blue)"></i>
        <span id="crewModalTitle">Crew Details</span>
      </div>
      <div class="crew-modal-meta" id="crewModalMeta"></div>
      <button class="crew-modal-close" onclick="document.getElementById('crewModal').classList.remove('open')" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="crew-modal-body" id="crewModalBody">
      <!-- filled by JS -->
    </div>
    <div class="crew-modal-footer" id="crewModalFooter"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
<script>
/* ── Data ──────────────────────────────────────────────── */
const TEAM_ROWS   = <?= json_encode($teamRows) ?>;
const TRUCK_ROWS  = <?= json_encode($truckRows) ?>;
const CREW_BY_TSID = <?= json_encode($crewByTsid) ?>;

/* ── Toggle expand/collapse ────────────────────────────── */
function toggleCrew(id, caretId) {
    const el    = document.getElementById(id);
    const caret = document.getElementById(caretId);
    if (!el) return;
    el.classList.toggle('open');
    if (caret) caret.classList.toggle('open');
}

/* ── Crew Modal ────────────────────────────────────────── */
function openCrewModal(tsid, plate, date, area) {
    const modal  = document.getElementById('crewModal');
    const title  = document.getElementById('crewModalTitle');
    const meta   = document.getElementById('crewModalMeta');
    const body   = document.getElementById('crewModalBody');
    const footer = document.getElementById('crewModalFooter');

    // Header info
    title.textContent = 'Crew — ' + (plate || tsid);
    meta.innerHTML = [
        plate ? `<span class="meta-pill mp-blue mono"><i class="bi bi-truck-front-fill"></i> ${escHtml(plate)}</span>` : '',
        date  ? `<span class="meta-pill mp-blue"><i class="bi bi-calendar3"></i> ${escHtml(date)}</span>` : '',
        area  ? `<span class="meta-pill mp-blue"><i class="bi bi-geo-alt-fill"></i> ${escHtml(area)}</span>` : '',
        `<span class="meta-pill" style="background:rgba(100,116,139,.1);color:var(--c-dim);border:1px solid var(--c-border)">TSID: ${escHtml(String(tsid))}</span>`,
    ].join('');

    const crew = CREW_BY_TSID[tsid] || [];

    if (!crew.length) {
        body.innerHTML = `<div class="crew-modal-empty"><i class="bi bi-people"></i>No crew data found for this schedule.</div>`;
        footer.innerHTML = '0 crew members';
        modal.classList.add('open');
        return;
    }

    // Summary counts
    const drvCnt  = crew.filter(c => (c.Position||'').toLowerCase().includes('driver')).length;
    const hlpCnt  = crew.length - drvCnt;
    const regCnt  = crew.filter(c => (c.EmploymentStatus||'').toLowerCase().trim() === 'regular').length;
    const probCnt = crew.filter(c => (c.EmploymentStatus||'').toLowerCase().includes('probation')).length;
    const extCnt  = crew.filter(c => (c.EmploymentStatus||'').toLowerCase().trim() === 'extra').length;

    // Build table
    const rows = crew.map(c => {
        const posLow = (c.Position || '').toLowerCase();
        const posClass = posLow.includes('driver') ? 'pos-driver'
                       : posLow.includes('warehouse') ? 'pos-wh'
                       : 'pos-helper';
        const stLow = (c.EmploymentStatus || '').toLowerCase().trim();
        const stClass = stLow === 'regular' ? 'badge-regular'
                      : stLow.includes('probation') ? 'badge-prob'
                      : 'badge-extra';
        const ini = (c.Employee_Name || '').split(' ').map(w => w[0]||'').join('').substring(0,2).toUpperCase();
        const mobile = (c.Mobile_Number || '').replace('NULL','').trim() || '—';
        return `<tr>
            <td>
              <span class="emp-init">${escHtml(ini)}</span>
              <span style="font-weight:700">${escHtml(c.Employee_Name||'—')}</span>
            </td>
            <td class="mono dim" style="font-size:.77rem">${escHtml(c.EmployeeID||'—')}</td>
            <td><span class="${posClass}">${escHtml(c.Position||'—')}</span></td>
            <td><span class="${stClass}" style="font-size:.73rem">${escHtml(c.EmploymentStatus||'—')}</span></td>
            <td class="mono dim" style="font-size:.77rem">${escHtml(mobile)}</td>
        </tr>`;
    }).join('');

    body.innerHTML = `
        <table class="crew-modal-table">
            <thead><tr>
                <th>Employee</th>
                <th>Employee ID</th>
                <th>Position</th>
                <th>Status</th>
                <th>Mobile No.</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;

    footer.innerHTML = `
        <span><b>${crew.length}</b> crew total</span>
        ${drvCnt  ? `<span class="cmb-drv" style="padding:2px 8px;border-radius:999px;font-size:.73rem;font-weight:700">${drvCnt} Driver${drvCnt>1?'s':''}</span>` : ''}
        ${hlpCnt  ? `<span class="cmb-hlp" style="padding:2px 8px;border-radius:999px;font-size:.73rem;font-weight:700">${hlpCnt} Helper${hlpCnt>1?'s':''}</span>` : ''}
        ${regCnt  ? `<span class="cmb-reg" style="padding:2px 8px;border-radius:999px;font-size:.73rem;font-weight:700">${regCnt} Regular</span>` : ''}
        ${probCnt ? `<span class="cmb-prob" style="padding:2px 8px;border-radius:999px;font-size:.73rem;font-weight:700">${probCnt} Probationary</span>` : ''}
        ${extCnt  ? `<span class="cmb-ext" style="padding:2px 8px;border-radius:999px;font-size:.73rem;font-weight:700">${extCnt} Extra</span>` : ''}
    `;

    modal.classList.add('open');
}

function closeCrewModal(e) {
    if (e.target === document.getElementById('crewModal')) {
        document.getElementById('crewModal').classList.remove('open');
    }
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Truck table search ────────────────────────────────── */
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#truckTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

/* ── Truck table sort ──────────────────────────────────── */
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#truckTable tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const va = a.cells[col]?.innerText.replace(/[₱,]/g,'') ?? '';
        const vb = b.cells[col]?.innerText.replace(/[₱,]/g,'') ?? '';
        const na = parseFloat(va), nb = parseFloat(vb);
        const res = isNaN(na) || isNaN(nb) ? va.localeCompare(vb) : na - nb;
        return _sortDir[col] ? res : -res;
    });
    rows.forEach(r => tbody.appendChild(r));
}

/* ── Peso formatter ────────────────────────────────────── */
const pesoFmt = v => '₱' + parseFloat(v||0).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});

/* ── Truck CSV ─────────────────────────────────────────── */
function exportTruckCSV() {
    if (!TRUCK_ROWS.length) return alert('No data to export.');
    const keys = ['TruckScheduleID','Department','ScheduleDate','PlateNumber','Area','Remarks','Cases','Calls',
                  'CrewCount','DriverCount','HelperCount','RegularCount','ProbCount','ExtraCount'];
    const rows = [keys, ...TRUCK_ROWS.map(r => keys.map(k => r[k] ?? ''))];
    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv);
    a.download = `truck_schedule_<?= date('Ymd') ?>.csv`; a.click();
}

/* ── Truck Excel ───────────────────────────────────────── */
function exportTruckExcel() {
    if (!TRUCK_ROWS.length) return alert('No data to export.');
    const ws = XLSX.utils.json_to_sheet(TRUCK_ROWS);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Truck Schedule');
    XLSX.writeFile(wb, `truck_schedule_<?= date('Ymd') ?>.xlsx`);
}

/* ── Truck Print ───────────────────────────────────────── */
function printTruck() {
    if (!TRUCK_ROWS.length) return alert('No data to print.');
    const headers = ['ID','Dept','Date','Plate','Area','Remarks','Cases','Calls','Crew','Drv','Hlp','Reg','Prob','Ext'];
    let rows = '';
    TRUCK_ROWS.forEach((r,i) => {
        rows += `<tr style="background:${i%2===0?'#fff':'#f9fafb'}">
          <td>${r.TruckScheduleID??''}</td><td>${r.Department??''}</td><td>${r.ScheduleDate??''}</td>
          <td><b>${r.PlateNumber??''}</b></td><td>${r.Area??''}</td>
          <td style="font-size:9px">${r.Remarks??''}</td>
          <td style="text-align:right">${r.Cases??0}</td><td style="text-align:right">${r.Calls??0}</td>
          <td style="text-align:right"><b>${r.CrewCount??0}</b></td>
          <td style="text-align:right">${r.DriverCount??0}</td><td style="text-align:right">${r.HelperCount??0}</td>
          <td style="text-align:right">${r.RegularCount??0}</td><td style="text-align:right">${r.ProbCount??0}</td>
          <td style="text-align:right">${r.ExtraCount??0}</td></tr>`;
    });
    const thead = '<thead><tr>'+headers.map(h=>`<th style="padding:3px 7px;border:1px solid #ccc;background:#f3f4f6;white-space:nowrap">${h}</th>`).join('')+'</tr></thead>';
    const win = window.open('','_blank','width=1100,height=800');
    win.document.write(`<!DOCTYPE html><html><head><title>Personnel Truck Assignment</title>
    <style>body{font-family:Arial,sans-serif;font-size:10px;margin:14px;color:#111}h3{margin:0 0 4px;font-size:13px}p{margin:0 0 8px;color:#666;font-size:10px}table{width:100%;border-collapse:collapse}td{padding:2px 7px;border:1px solid #ddd}@media print{body{margin:0}}</style>
    </head><body><h3>Personnel Truck Assignment</h3><p>Date: <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?> · Exported: <?= date('Y-m-d H:i:s') ?> · ${TRUCK_ROWS.length} records</p>
    <table>${thead}<tbody>${rows}</tbody></table></body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}
/* ── SDID tab search ───────────────────────────────────── */
function searchSdid(q) {
    const term = q.trim().toLowerCase();
    document.querySelectorAll('#sdidTableBody tr.sdid-summary-row').forEach(tr => {
        const search = tr.dataset.search || tr.innerText.toLowerCase();
        const sdid   = tr.dataset.sdid || '';
        const detailRow = document.getElementById('sdid-detail-' + sdid);
        const show   = !term || search.includes(term);
        tr.style.display = show ? '' : 'none';
        if (detailRow) detailRow.style.display = 'none'; // collapse on search
    });
}

/* ── SDID table sort ───────────────────────────────────── */
let _sdidSortDir = {};
function sortSdidTable(col) {
    const tbody = document.querySelector('#sdidTableBody');
    if (!tbody) return;
    // Only get summary rows (not detail rows)
    const summaryRows = Array.from(tbody.querySelectorAll('tr.sdid-summary-row'));
    _sdidSortDir[col] = !_sdidSortDir[col];
    summaryRows.sort((a, b) => {
        const va = a.cells[col]?.innerText.replace(/[₱,▼ ]/g,'').trim() ?? '';
        const vb = b.cells[col]?.innerText.replace(/[₱,▼ ]/g,'').trim() ?? '';
        const na = parseFloat(va), nb = parseFloat(vb);
        const res = isNaN(na) || isNaN(nb) ? va.localeCompare(vb) : na - nb;
        return _sdidSortDir[col] ? res : -res;
    });
    // Re-insert each summary row followed by its detail row
    summaryRows.forEach(row => {
        const sdid = row.dataset.sdid;
        const detail = document.getElementById('sdid-detail-' + sdid);
        tbody.appendChild(row);
        if (detail) tbody.appendChild(detail);
    });
}

/* ── Toggle SDID detail expand ─────────────────────────── */
function toggleSdidDetail(detailId, caretId) {
    const detail = document.getElementById(detailId);
    const caret  = document.getElementById(caretId);
    if (!detail) return;
    const isOpen = detail.style.display !== 'none';
    detail.style.display = isOpen ? 'none' : 'table-row';
    if (caret) caret.style.transform = isOpen ? '' : 'rotate(180deg)';
}

/* ── SDID CSV export ───────────────────────────────────── */
const SDID_SUMMARIES = <?= json_encode(array_values($sdidSummaries)) ?>;
function exportSdidCSV() {
    if (!SDID_SUMMARIES.length) return alert('No data to export.');
    const keys = ['SDID','RowCount','Departments','Areas','PlateNumber','LatestDate','TotalAmount','TotalPaid','TotalBalance','Status1'];
    const labels = ['SDID','# Employees','Departments','Areas','Plate No.','Latest Schedule','Total Amount','Amount Paid','Balance Due','Status'];
    const rows = [labels, ...SDID_SUMMARIES.map(r => keys.map(k => r[k] ?? ''))];
    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const a = document.createElement('a'); a.href = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv);
    a.download = `short_stocks_sdid_<?= date('Ymd') ?>.csv`; a.click();
}
function exportSdidExcel() {
    if (!SDID_SUMMARIES.length) return alert('No data to export.');
    const ws = XLSX.utils.json_to_sheet(SDID_SUMMARIES);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Short Stocks SDID');
    XLSX.writeFile(wb, `short_stocks_sdid_<?= date('Ymd') ?>.xlsx`);
}

/* ── Short Stocks tab JS ───────────────────────────────── */
const SS_ROWS = <?= json_encode($shortStocksRows ?? []) ?>;

function toggleSS(id, caretId) {
    const el    = document.getElementById(id);
    const caret = document.getElementById(caretId);
    if (!el) return;
    el.classList.toggle('open');
    if (caret) caret.classList.toggle('open');
}

function searchSS(q) {
    const term  = q.trim().toLowerCase();
    const cards = document.querySelectorAll('#ssPayList .sspay-row');
    cards.forEach(el => {
        el.style.display = (!term || el.dataset.search.includes(term)) ? '' : 'none';
    });
}

function openSsItems(seid, empName, refNo, empId, sdid) {
    const modal = document.getElementById('ssItemsModal');
    const body  = document.getElementById('ssModalBody');
    const title = document.getElementById('ssModalTitle');
    const footer = document.getElementById('ssModalFooter');
    title.textContent = empName + ' — ' + refNo;
    body.innerHTML = '<div class="ss-loading"><i class="bi bi-hourglass-split"></i> Loading items…</div>';
    footer.textContent = '—';
    modal.classList.add('open');

    // Fetch items via same page with action=ssitems
    let _url = `?action=ssitems&seid=${encodeURIComponent(seid||0)}`;
    if (empId) _url += `&empid=${encodeURIComponent(empId)}`;
    if (sdid)  _url += `&sdid=${encodeURIComponent(sdid)}`;
    fetch(_url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.length) {
            body.innerHTML = '<div class="ss-loading" style="color:var(--c-dim)"><i class="bi bi-inbox"></i> No items found.</div>';
            footer.textContent = '0 items';
            return;
        }
        let totalAmt = 0;
        const cols = ['Item','UOM','QTY','UnitPrice','ItemAmount','DateSchedule','PlateNumber','Area','Outlet','TypeShort','RefNo','AmountDue'];
        const colLabels = { Item:'Item', UOM:'UOM', QTY:'Qty', UnitPrice:'Unit Price', ItemAmount:'Amount',
                            DateSchedule:'Date', PlateNumber:'Plate', Area:'Area', Outlet:'Outlet',
                            TypeShort:'Type', RefNo:'Ref No', AmountDue:'Amt Due' };
        const ths = cols.map(c => `<th>${colLabels[c]||c}</th>`).join('');
        const trs = data.map(r => {
            totalAmt += parseFloat(r.ItemAmount ?? 0);
            const tds = cols.map(c => {
                let v = r[c] ?? '—';
                if (c === 'ItemAmount' || c === 'UnitPrice' || c === 'AmountDue') {
                    v = '₱' + parseFloat(v||0).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
                }
                return `<td>${String(v).replace(/</g,'&lt;')}</td>`;
            }).join('');
            return `<tr>${tds}</tr>`;
        }).join('');
        body.innerHTML = `<div style="overflow-x:auto"><table class="ss-items-table"><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table></div>`;
        footer.textContent = `${data.length} items · Total: ₱${totalAmt.toLocaleString('en-PH', {minimumFractionDigits:2})}`;
    })
    .catch(() => {
        body.innerHTML = '<div class="ss-loading" style="color:var(--c-red)"><i class="bi bi-exclamation-triangle"></i> Failed to load items.</div>';
    });
}

function closeSsModal(e) {
    if (e.target === document.getElementById('ssItemsModal')) {
        document.getElementById('ssItemsModal').classList.remove('open');
    }
}

/* ── Short Stocks Export ───────────────────────────────── */
function exportSSCSV() {
    if (!SS_ROWS.length) return alert('No data to export.');
    const keys = ['EmployeeID','EmployeeName','Job_tittle','Position_held','Employee_Status',
                  'Department','PlateNumber','Area','Outlet','DateSchedule','DateGenerate',
                  'RefNo','TypeShort','Category','TotalAmount','AmountDue','PaidAmount',
                  'Balance','StatusofShort','Remarks'];
    const rows = [keys, ...SS_ROWS.map(r => keys.map(k => r[k] ?? ''))];
    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const a    = document.createElement('a');
    a.href     = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv);
    a.download = `short_stocks_<?= date('Ymd') ?>.csv`; a.click();
}

function exportSSExcel() {
    if (!SS_ROWS.length) return alert('No data to export.');
    const ws = XLSX.utils.json_to_sheet(SS_ROWS);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Short Stocks');
    XLSX.writeFile(wb, `short_stocks_<?= date('Ymd') ?>.xlsx`);
}
</script>

</body>
</html>