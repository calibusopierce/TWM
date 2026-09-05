<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

// ── AJAX: Payment History ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'payhist' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $empid   = str_replace("'", "''", trim($_GET['empid'] ?? ''));
    $sdidVal = str_replace("'", "''", trim($_GET['sdid']  ?? ''));
    $rows = [];
    if ($empid !== '' && $sdidVal !== '') {
        $rows = runQuery($conn, "
            SELECT [SPPID],[AmountDue],[PaidAmount],[Balance],[DatePaid],[DateGenerate],[RefNo],[StatusofShort],[Remarks]
            FROM [dbo].[View_ShortPaymentPaidDetails]
            WHERE EmployeeID = '$empid' AND SDID = '$sdidVal'
            ORDER BY DateGenerate ASC
        ");
        // Format DateTime objects
        foreach ($rows as &$r) {
            foreach (['DatePaid','DateGenerate'] as $col) {
                if (isset($r[$col]) && $r[$col] instanceof DateTime) {
                    $r[$col] = $r[$col]->format('Y-m-d');
                }
            }
        }
        unset($r);
    }
    sqlsrv_close($conn);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows ?: []);
    exit;
}


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
    if (!$stmt) {
        // Was silently swallowing driver errors — log them so failures are
        // visible instead of just showing up as "0 records" in the UI.
        error_log('[TWM runQuery] SQL failed: ' . print_r(sqlsrv_errors(), true) . "\nQuery: " . $sql);
        return [];
    }
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
$deptListWhere = $sessionDept !== '' ? " AND UPPER(RTRIM(LTRIM(Department))) IN ($deptInStr)" : '';
$areaList   = lookupList($conn, "SELECT DISTINCT Area FROM [dbo].[teamschedule] WHERE Area IS NOT NULL AND Area <> '' $deptListWhere ORDER BY Area");
$plateList  = lookupList($conn, "SELECT DISTINCT PlateNumber FROM [dbo].[teamschedule] WHERE PlateNumber IS NOT NULL AND PlateNumber <> '' $deptListWhere ORDER BY PlateNumber");
$statusList = lookupList($conn, "SELECT DISTINCT RTRIM(Status) FROM [dbo].[teamschedule] WHERE Status IS NOT NULL AND Status <> '' $deptListWhere ORDER BY RTRIM(Status)");
// Short stocks dropdowns (scoped to dept)
$ssDeptWhere   = $sessionDept !== '' ? " AND UPPER(RTRIM(LTRIM(Department))) IN ($deptInStr)" : '';
$ssAreaList   = lookupList($conn, "SELECT DISTINCT Area FROM [dbo].[View_ShortPaymentPaidDetails] WHERE Area IS NOT NULL AND Area <> '' $ssDeptWhere ORDER BY Area");
$ssPlateList  = lookupList($conn, "SELECT DISTINCT PlateNumber FROM [dbo].[View_ShortPaymentPaidDetails] WHERE PlateNumber IS NOT NULL AND PlateNumber <> '' $ssDeptWhere ORDER BY PlateNumber");
$ssShortStatusList = lookupList($conn, "SELECT DISTINCT RTRIM(StatusofShort) FROM [dbo].[View_ShortPaymentPaidDetails] WHERE StatusofShort IS NOT NULL AND StatusofShort <> '' $ssDeptWhere ORDER BY RTRIM(StatusofShort)");

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
// Short stocks always filters by date — default to last 30 days when no date is set
$ssFrom        = $dateActive ? $baseFrom : $monthFrom;
$ssTo          = $dateActive ? $baseTo   : $today;
// Filter by DateSchedule (always populated for every accountable employee),
// not DateGenerate — DateGenerate is only set once a payment/installment row
// exists, so employees who still owe and haven't paid anything have a NULL
// DateGenerate and were being silently excluded by the BETWEEN clause.
$ssDateWhere   = " AND DateSchedule BETWEEN '$ssFrom' AND '$ssTo'";
$ssAreaWhere   = $areaActive   ? " AND Area = '$_areaSafe'"                            : '';
$ssPlateWhere  = $plateActive  ? " AND PlateNumber = '$_plateSafe'"                    : '';
$ssStatusWhere = $statusActive ? " AND RTRIM(StatusofShort) = '$_statusSafe'"          : '';
// Status sub-toggle (Confirmed / Created / Void / All)
$ssStatusFilter = isset($_GET['ss_status']) ? strtolower(trim($_GET['ss_status'])) : 'all';
if (!in_array($ssStatusFilter, ['all','paid','unpaid'])) $ssStatusFilter = 'all';
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
WHERE 1=1
' . $shortStocksDeptWhere . $ssDateWhere . $ssAreaWhere . $ssPlateWhere . $ssStatusWhere . '
ORDER BY Balance DESC, DateGenerate DESC
';
$shortStocksRawRows = runQuery($conn, $shortStocksSql);

// ── Deduplicate Individual rows: keep only latest (min Balance) per EmployeeID ──
// Then exclude employees whose latest balance is fully settled (= 0)
$_empLatest = [];
foreach ($shortStocksRawRows as $r) {
    $eid = $r['EmployeeID'] ?? '';
    if (!isset($_empLatest[$eid]) || (float)($r['Balance'] ?? 0) < (float)($_empLatest[$eid]['Balance'] ?? 0)) {
        $_empLatest[$eid] = $r;
    }
}
// Keep all employees regardless of balance — toggle handles filtering in the view
// Only remove fully settled when 'unpaid' filter is active
if ($ssStatusFilter === 'unpaid') {
    $_empLatest = array_filter($_empLatest, fn($r) => (float)($r['Balance'] ?? 0) > 0);
}
$_unsettledEids = array_keys($_empLatest);
$shortStocksRawRows = array_values(array_filter($shortStocksRawRows, fn($r) => in_array($r['EmployeeID'] ?? '', $_unsettledEids)));
$shortStocksRows = array_values($_empLatest);
// Sort by balance desc (same order as raw query)
usort($shortStocksRows, fn($a, $b) => (float)($b['Balance'] ?? 0) <=> (float)($a['Balance'] ?? 0));

// Remove the employee-level balance filter for "all" — raw rows already scoped by dept/date
// (old code excluded balance=0 employees; we now keep them for the Settled toggle)

// ── Short Stocks: SDID grouped (uses raw rows for correct paid totals) ──
$sdidGrouped = [];
foreach ($shortStocksRawRows as $r) {
    $sdid = (string)($r['SDID'] ?? 'Unknown');
    $sdidGrouped[$sdid][] = $r;
}
// NOTE: SDID-level totals now come straight from View_ShortPaymentWithBalance
// (SQL Server computes Balance1/Paid1/TotalAmount/NumAccountable for us) instead
// of being re-derived in PHP from the raw employee rows above. The old PHP-side
// "max AmountDue per employee, then sum" logic was fragile and dropped/mis-summed
// newly added short payment records in some edge cases (e.g. an employee with no
// prior installment row yet). $sdidGrouped (raw rows) is still used below purely
// to render the expandable per-employee detail under each SDID group.
$sdidBalanceWhereParts = [];
if ($sessionDept !== '') $sdidBalanceWhereParts[] = "UPPER(RTRIM(LTRIM(Department))) IN ($deptInStr)";
$sdidBalanceWhereParts[] = "DateSchedule BETWEEN '$ssFrom' AND '$ssTo'";
if ($areaActive)   $sdidBalanceWhereParts[] = "Area = '$_areaSafe'";
if ($plateActive)  $sdidBalanceWhereParts[] = "PlateNumber = '$_plateSafe'";
if ($statusActive) $sdidBalanceWhereParts[] = "RTRIM(Status) = '$_statusSafe'";
$sdidBalanceWhereSql = $sdidBalanceWhereParts ? ('WHERE ' . implode(' AND ', $sdidBalanceWhereParts)) : '';

// NOTE: View_ShortPaymentWithBalance returns ONE ROW PER EMPLOYEE per SDID
// (its GROUP BY includes EmployeeName), with Balance1/Paid1 already correctly
// summed per employee at the DB level. So to get the SDID-level group totals
// we sum Balance1/Paid1 across all employee rows sharing the same SDID here in
// PHP — TotalAmount/NumAccountable/Department/etc. are repeated identically on
// every row for a given SDID, so we just take them from the first row seen.
$sdidBalanceSql = "
SELECT [SDID],[DID],[Department],[DateSchedule],[PlateNumber],[Area],[Outlet]
      ,[TypeShort],[RefNo],[Remarks],[Status],[TotalAmount],[NumAccountable]
      ,[Balance1],[Paid1],[EmployeeName]
FROM [dbo].[View_ShortPaymentWithBalance]
$sdidBalanceWhereSql
ORDER BY SDID, Balance1 DESC
";
$sdidBalanceRows = runQuery($conn, $sdidBalanceSql);

$sdidSummaries = [];
foreach ($sdidBalanceRows as $r) {
    $sdid = (string)($r['SDID'] ?? 'Unknown');
    if (!isset($sdidSummaries[$sdid])) {
        $sdidSummaries[$sdid] = [
            'SDID'        => $sdid,
            'RowCount'    => 0,
            'TotalBalance'=> 0.0,
            'TotalAmount' => (float)($r['TotalAmount'] ?? 0),
            'TotalPaid'   => 0.0,
            'Departments' => trim($r['Department'] ?? ''),
            'Areas'       => trim($r['Area'] ?? ''),
            'LatestDate'  => $r['DateSchedule'] ?? '',
            'PlateNumber' => $r['PlateNumber'] ?? '',
            'Status1'     => $r['Status'] ?? '',
            'RefNo'       => $r['RefNo'] ?? '',
            'TypeShort'   => $r['TypeShort'] ?? '',
            'Outlet'      => $r['Outlet'] ?? '',
            // Per-employee name + balance/paid, straight from the view —
            // includes every accountable employee, paid or not.
            'Employees'   => [],
        ];
    }
    $sdidSummaries[$sdid]['RowCount']++;
    $sdidSummaries[$sdid]['TotalBalance'] += (float)($r['Balance1'] ?? 0);
    $sdidSummaries[$sdid]['TotalPaid']    += (float)($r['Paid1']    ?? 0);
    $sdidSummaries[$sdid]['Employees'][] = [
        'EmployeeName' => trim($r['EmployeeName'] ?? ''),
        'Balance'      => (float)($r['Balance1'] ?? 0),
        'Paid'         => (float)($r['Paid1']    ?? 0),
    ];
}
$sdidSummaries = array_values($sdidSummaries);
usort($sdidSummaries, fn($a, $b) => $b['TotalBalance'] <=> $a['TotalBalance']);

// Apply status sub-toggle filter
if ($ssStatusFilter === 'paid') {
    $sdidSummaries = array_values(array_filter($sdidSummaries, fn($s) => (float)($s['TotalBalance'] ?? 0) <= 0));
} elseif ($ssStatusFilter === 'unpaid') {
    $sdidSummaries = array_values(array_filter($sdidSummaries, fn($s) => (float)($s['TotalBalance'] ?? 0) > 0));
}

// ── Merge: build the COMPLETE per-employee list per SDID ──────────────────
// View_ShortPaymentPaidDetails ($sdidGrouped) only has a row for employees who
// already have a payment/installment record — someone accountable for the short
// who hasn't paid anything yet has no row there at all. View_ShortPaymentWithBalance
// (captured above as each summary's 'Employees') has the FULL accountable list per
// SDID, just without EmployeeID/SEID/Position/etc. We merge the two here by SDID +
// normalized employee name: use the richer paid-details row when a match exists
// (keeps EmployeeID-dependent buttons working), otherwise synthesize a row from
// the balance-view data so the employee still shows up with correct balance/paid.
foreach ($sdidSummaries as $summary) {
    $sdid = $summary['SDID'];
    $existingRows = $sdidGrouped[$sdid] ?? [];

    $byName = [];
    foreach ($existingRows as $r) {
        $key = strtoupper(trim($r['EmployeeName'] ?? ''));
        if ($key === '') continue;
        if (!isset($byName[$key]) || (float)($r['Balance'] ?? 0) < (float)($byName[$key]['Balance'] ?? 0)) {
            $byName[$key] = $r;
        }
    }

    $mergedRows = [];
    $matchedKeys = [];
    foreach ($summary['Employees'] as $emp) {
        $key = strtoupper(trim($emp['EmployeeName'] ?? ''));
        if (isset($byName[$key])) {
            $mergedRows[] = $byName[$key];
            $matchedKeys[$key] = true;
        } else {
            // No payment record yet for this employee — fall back to the
            // balance-view data. EmployeeID-dependent buttons (Items, Pay
            // History) won't work for this row until a payment record exists.
            $mergedRows[] = [
                'EmployeeID'   => '',
                'EmployeeName' => $emp['EmployeeName'],
                'Position'     => '',
                'TypeShort'    => $summary['TypeShort'] ?? '',
                'Outlet'       => $summary['Outlet'] ?? '',
                'Area'         => $summary['Areas'] ?? '',
                'AmountDue'    => $emp['Balance'] + $emp['Paid'],
                'PaidAmount'   => $emp['Paid'],
                'Balance'      => $emp['Balance'],
                'DatePaid'     => '',
                'RefNo'        => $summary['RefNo'] ?? '',
                'SEID'         => 0,
                'SDID'         => $sdid,
            ];
        }
    }
    // Keep any paid-details rows that didn't match a name in the balance view,
    // so nothing that was already showing correctly silently disappears.
    foreach ($byName as $key => $r) {
        if (!isset($matchedKeys[$key])) {
            $mergedRows[] = $r;
        }
    }

    $sdidGrouped[$sdid] = $mergedRows;
}


// ── Individual Employee Short stats ───────────────────────
$totalShortStocksBalance = array_sum(array_column($shortStocksRows, 'Balance'));
$totalShortStocksCount   = count($shortStocksRows);
$totalSdidCount          = count($sdidSummaries);
$totalSdidBalance        = array_sum(array_column($sdidSummaries, 'TotalBalance'));

// ── Individual Short Stocks — Payment History (audit view) ─
// Per audit team request: show EVERY payment/installment record per
// employee (not deduped to latest balance), grouped by employee, and
// filter this tab specifically by DateGenerate (not DateSchedule) so
// staff can back-check what was generated/paid within a date range.
$empGenFrom = $dateActive ? $baseFrom : $monthFrom;
$empGenTo   = $dateActive ? $baseTo   : $today;
$empHistDeptWhere   = $sessionDept !== '' ? ' AND UPPER(RTRIM(LTRIM(Department))) IN (' . $deptInStr . ')' : '';
$empHistDateWhere   = " AND DateGenerate BETWEEN '$empGenFrom' AND '$empGenTo'";
$empHistAreaWhere   = $areaActive   ? " AND Area = '$_areaSafe'"                   : '';
$empHistPlateWhere  = $plateActive  ? " AND PlateNumber = '$_plateSafe'"           : '';
$empHistStatusWhere = $statusActive ? " AND RTRIM(StatusofShort) = '$_statusSafe'" : '';

$empHistorySql = '
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
WHERE 1=1
' . $empHistDeptWhere . $empHistDateWhere . $empHistAreaWhere . $empHistPlateWhere . $empHistStatusWhere . '
ORDER BY EmployeeName ASC, DateGenerate ASC
';
$empHistoryRows = runQuery($conn, $empHistorySql);

$empGrouped = [];
foreach ($empHistoryRows as $r) {
    $eid = $r['EmployeeID'] ?? '';
    if ($eid === '') continue;
    if (!isset($empGrouped[$eid])) {
        $empGrouped[$eid] = [
            'EmployeeID'   => $eid,
            'EmployeeName' => trim($r['EmployeeName'] ?? ''),
            'Position'     => trim($r['Position'] ?? ($r['Job_tittle'] ?? '')),
            'Department'   => trim($r['Department'] ?? ''),
            'DetailId'     => 'emp-detail-' . preg_replace('/[^A-Za-z0-9]/', '', $eid),
            'CaretId'      => 'emp-caret-'  . preg_replace('/[^A-Za-z0-9]/', '', $eid),
            'records'      => [],
            'TotalAmount'  => 0.0, // sum of Due across the filtered records
            'TotalPaid'    => 0.0,
            'TotalBalance' => 0.0,
        ];
    }
    $empGrouped[$eid]['records'][]    = $r;
    $empGrouped[$eid]['TotalAmount']  += (float)($r['AmountDue']  ?? 0);
    $empGrouped[$eid]['TotalPaid']    += (float)($r['PaidAmount'] ?? 0);
    $empGrouped[$eid]['TotalBalance'] += (float)($r['Balance']    ?? 0);
}
$empGrouped = array_values($empGrouped);
usort($empGrouped, fn($a, $b) => strcasecmp($a['EmployeeName'], $b['EmployeeName']));

$empTotalRecords      = count($empHistoryRows);
$empGrandTotalAmt     = array_sum(array_column($empGrouped, 'TotalAmount'));
$empGrandTotalPaid    = array_sum(array_column($empGrouped, 'TotalPaid'));
$empGrandTotalBalance = array_sum(array_column($empGrouped, 'TotalBalance'));

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
    'shortstocks_emp' => count($empGrouped),
    default           => $totalTrucks,
};
$totalPages = max(1, (int)ceil($currentCount / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;

$displayTrucks       = array_slice($truckRows, $offset, $rowLimit);
$displaySdidSummaries = array_slice($sdidSummaries, $offset, $rowLimit);
$displayEmpGrouped    = array_slice($empGrouped, $offset, $rowLimit);

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
.ss-modal-box { background: var(--c-surface); border-radius: 14px; width: 96%; max-width: 1100px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.2); overflow: hidden; }
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
  border-radius: 14px; width: 96%; max-width: 1100px;
  max-height: 90vh; display: flex; flex-direction: column;
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

      <?php
        $isTruck = $selTab === 'truck';
        $isSS    = in_array($selTab, ['shortstocks_sdid','shortstocks_emp']);
        $curAreaList  = $isSS ? $ssAreaList  : $areaList;
        $curPlateList = $isSS ? $ssPlateList : $plateList;
      ?>

      <?php $dateFilterLabel = $selTab === 'shortstocks_emp' ? 'Date Generated' : 'Date'; ?>
      <span class="filter-label"><i class="bi bi-calendar3"></i><?= $selTab === 'shortstocks_emp' ? ' Generated' : '' ?></span>
      <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" title="<?= $dateFilterLabel ?> From">
      <input type="date" name="date_to"   value="<?= htmlspecialchars($dateTo) ?>"   title="<?= $dateFilterLabel ?> To">
      <div class="filter-sep"></div>

      <select name="area" title="Area">
        <option value="">All Areas</option>
        <?php foreach ($curAreaList as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="plate" title="Plate Number">
        <option value="">All Plates</option>
        <?php foreach ($curPlateList as $pl): ?>
          <option value="<?= htmlspecialchars($pl) ?>" <?= $selPlate === $pl ? 'selected' : '' ?>><?= htmlspecialchars($pl) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if ($isTruck): ?>
        <select name="status" title="Employment Status">
          <option value="">All Statuses</option>
          <?php foreach ($statusList as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>" <?= $selStatus === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($isSS): ?>
        <select name="status" title="Short Status">
          <option value="">All Short Statuses</option>
          <?php foreach ($ssShortStatusList as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>" <?= $selStatus === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

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
      <i class="bi bi-person-exclamation"></i> Individual Short Payment Paid
      <span class="tab-badge red"><?= number_format(count($empGrouped)) ?></span>
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
    // Remap: 'paid' = fully settled (balance=0), 'unpaid' = has balance, 'all' = all
    // ss_status values: all | paid | unpaid
    $ssStatusFilter = isset($_GET['ss_status']) ? strtolower(trim($_GET['ss_status'])) : 'all';
    if (!in_array($ssStatusFilter, ['all','paid','unpaid'])) $ssStatusFilter = 'all';
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
    <!-- Balance toggle -->
    <div class="sdid-status-toggle">
      <a href="<?= ssStatusUrl('unpaid') ?>" class="sdid-svt-btn <?= $ssStatusFilter === 'unpaid' ? 'sdid-svt-active sdid-svt-void'    : '' ?>">⚠ With Balance</a>
      <a href="<?= ssStatusUrl('paid') ?>"   class="sdid-svt-btn <?= $ssStatusFilter === 'paid'   ? 'sdid-svt-active sdid-svt-confirmed' : '' ?>">✓ Settled</a>
      <a href="<?= ssStatusUrl('all') ?>"    class="sdid-svt-btn <?= $ssStatusFilter === 'all'    ? 'sdid-svt-active sdid-svt-all'       : '' ?>">📋 All</a>
    </div>
    <div class="search-input">
      <i class="bi bi-search"></i>
      <input type="text" id="sdidSearch" placeholder="Search SDID, invoice no., dept, area, plate…" oninput="searchSdid(this.value)">
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
      <th onclick="sortSdidTable(10)">Invoice No. ⇅</th>
    </tr></thead>
    <tbody id="sdidTableBody">
    <?php foreach ($displaySdidSummaries as $s):
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
      $searchStr = strtolower(($s['SDID']??'').' '.($s['Departments']??'').' '.($s['Areas']??'').' '.($s['PlateNumber']??'').' '.($s['RefNo']??''));
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
        <td class="r mono bold" style="color:<?= $sBal > 0 ? 'var(--c-red)' : 'var(--c-dim)' ?>;font-size:.95rem">
          <?= $sBal > 0 ? '▼ ' . peso($sBal) : '—' ?>
        </td>
        <td>
          <span style="background:<?= $s1bg ?>;color:<?= $s1color ?>;border:1px solid <?= $s1border ?>;border-radius:999px;padding:2px 9px;font-size:.73rem;font-weight:700;white-space:nowrap;">
            <?= $s1icon ?> <?= htmlspecialchars($s['Status1'] ?: '—') ?>
          </span>
        </td>
        <td class="mono" style="font-size:.78rem;color:var(--c-purple);font-weight:700"><?= htmlspecialchars($s['RefNo'] ?: '—') ?></td>
      </tr>
      <?php $thisEmpRows = $sdidGrouped[$s['SDID']] ?? []; ?>
      <tr id="sdid-detail-<?= htmlspecialchars((string)$s['SDID']) ?>" class="sdid-detail-row" style="display:none;">
        <td colspan="12" style="padding:0;background:#f9f5ff;">
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
                  <th style="padding:.3rem .6rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Settled</th>
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
                    <?php if (!empty($datePaid) && $empBal <= 0): ?>
                      <span style="color:var(--c-green);font-weight:600;"><i class="bi bi-calendar-check" style="font-size:.7rem"></i> <?= htmlspecialchars($datePaid) ?></span>
                    <?php elseif ($hasPaid): ?>
                      <span style="color:#f59e0b;font-weight:600;"><i class="bi bi-clock-history" style="font-size:.7rem"></i> Partially paid</span>
                    <?php else: ?>
                      <span style="color:#d1d5db">Not yet paid</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:.32rem .6rem;text-align:center;">
                    <?php if ($empBal <= 0): ?>
                      <button onclick="event.stopPropagation();openPayHistory(<?= htmlspecialchars(json_encode($emp['EmployeeID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['SDID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['EmployeeName'] ?? '')) ?>)" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;cursor:pointer;"><i class="bi bi-check-circle-fill"></i> Settled</button>
                    <?php elseif ($hasPaid): ?>
                      <button onclick="event.stopPropagation();openPayHistory(<?= htmlspecialchars(json_encode($emp['EmployeeID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['SDID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($emp['EmployeeName'] ?? '')) ?>)" style="background:#fef3c7;color:#b45309;border:1px solid #fcd34d;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;cursor:pointer;"><i class="bi bi-hourglass-split"></i> Partial</button>
                    <?php else: ?>
                      <span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;"><i class="bi bi-x-circle-fill"></i> Unpaid</span>
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
        <td class="r mono bold" style="color:<?= $totalSdidBalance > 0 ? 'var(--c-red)' : 'var(--c-dim)' ?>;padding:.5rem .75rem">
          <?= $totalSdidBalance > 0 ? '▼ ' . peso($totalSdidBalance) : '—' ?>
        </td>
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

  <div class="section-toolbar">
    <div class="section-toolbar-left">
      <i class="bi bi-person-exclamation" style="color:var(--c-red);font-size:.9rem"></i>
      <span style="font-weight:700;font-size:.82rem">Individual Short Payment Paid</span>
      <span class="section-count" style="background:#fee2e2;color:#991b1b;border-color:#f87171"><?= count($empGrouped) ?> employees · <?= number_format($empTotalRecords) ?> records</span>
      <span style="font-size:.75rem;color:var(--c-dim)">
        Total: <b style="color:var(--c-ink)"><?= peso($empGrandTotalAmt) ?></b>
        · Paid: <b style="color:var(--c-green)"><?= peso($empGrandTotalPaid) ?></b>
        · Balance: <b style="color:var(--c-red)"><?= peso($empGrandTotalBalance) ?></b>
      </span>
    </div>
    <div class="search-input">
      <i class="bi bi-search"></i>
      <input type="text" id="empHistSearch" placeholder="Search employee, ref no, outlet…" oninput="searchEmpHistory(this.value)">
    </div>
    <button class="btn-sm green"  onclick="exportEmpHistoryCSV()"><i class="bi bi-download"></i> CSV</button>
    <button class="btn-sm blue"   onclick="exportEmpHistoryExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
    <button class="btn-sm violet" onclick="printEmpHistory()"><i class="bi bi-printer"></i> Print</button>
  </div>

  <?php if (empty($empGrouped)): ?>
    <div class="empty-state" style="border:1px solid var(--c-border);border-radius:10px;">
      <i class="bi bi-check-circle" style="color:var(--c-green);opacity:1;font-size:2rem"></i>
      No short stock payment records generated in the selected period.
    </div>
  <?php else: ?>

  <div class="table-scroll">
  <table class="data-table" id="empHistoryTable">
    <thead><tr>
      <th onclick="sortEmpTable(0)">Employee ⇅</th>
      <th>Position</th>
      <th>Department</th>
      <th class="r" onclick="sortEmpTable(3)"># Records ⇅</th>
      <th class="r" onclick="sortEmpTable(4)">Total Amount ⇅</th>
      <th class="r" onclick="sortEmpTable(5)">Paid ⇅</th>
      <th class="r" onclick="sortEmpTable(6)">Balance ⇅</th>
    </tr></thead>
    <tbody id="empHistoryTableBody">
    <?php foreach ($displayEmpGrouped as $g):
      $detailId  = $g['DetailId'];
      $caretId   = $g['CaretId'];
      $searchStr = strtolower($g['EmployeeName'] . ' ' . $g['Department'] . ' ' . $g['EmployeeID']);
    ?>
      <tr class="sdid-summary-row emp-summary-row" data-search="<?= htmlspecialchars($searchStr) ?>"
          data-detail="<?= $detailId ?>"
          onclick="toggleSdidDetail('<?= $detailId ?>', '<?= $caretId ?>')" style="cursor:pointer;" title="Click to expand payment history">
        <td>
          <span style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($g['EmployeeName']) ?></span>
          <i class="bi bi-chevron-down" id="<?= $caretId ?>" style="font-size:.65rem;color:var(--c-dim);margin-left:.3rem;transition:transform .2s"></i>
        </td>
        <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($g['Position']) ?></td>
        <td style="font-size:.8rem"><?= htmlspecialchars($g['Department']) ?></td>
        <td class="r mono bold"><?= count($g['records']) ?></td>
        <td class="r mono bold"><?= peso($g['TotalAmount']) ?></td>
        <td class="r mono" style="color:var(--c-green)"><?= peso($g['TotalPaid']) ?></td>
        <td class="r mono bold" style="color:<?= $g['TotalBalance'] > 0 ? 'var(--c-red)' : 'var(--c-green)' ?>">
          <?= $g['TotalBalance'] > 0 ? '▼ ' . peso($g['TotalBalance']) : '✓ ' . peso(0) ?>
        </td>
      </tr>
      <tr id="<?= $detailId ?>" class="sdid-detail-row" style="display:none;">
        <td colspan="7" style="padding:0;background:#fffbeb;">
          <div style="padding:.5rem .75rem .75rem;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;border:1px solid #fde68a;border-radius:8px;overflow:hidden;">
              <thead>
                <tr style="background:#fef3c7;">
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Schedule</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Generate</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">No.</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Due</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Paid</th>
                  <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Balance</th>
                  <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Ref / Outlet</th>
                  <th style="padding:.3rem .6rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#92400e;">Items</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($g['records'] as $ri => $rec):
                $recBg  = $ri % 2 === 0 ? '#fff' : '#fffdf5';
                $recBal = (float)($rec['Balance'] ?? 0);
              ?>
                <tr style="background:<?= $recBg ?>;">
                  <td style="padding:.32rem .6rem;font-family:monospace;color:var(--c-dim);font-size:.76rem;"><?= htmlspecialchars($rec['DateSchedule'] ?? '—') ?></td>
                  <td style="padding:.32rem .6rem;font-family:monospace;font-size:.76rem;"><?= htmlspecialchars($rec['DateGenerate'] ?? '—') ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;"><?= (int)($rec['NumAccountable'] ?? 0) ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:600;"><?= peso((float)($rec['AmountDue'] ?? 0)) ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;color:var(--c-green);font-weight:600;"><?= peso((float)($rec['PaidAmount'] ?? 0)) ?></td>
                  <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:800;color:<?= $recBal > 0 ? 'var(--c-red)' : 'var(--c-green)' ?>;">
                    <?= $recBal > 0 ? '▼ ' . peso($recBal) : '✓ ' . peso(0) ?>
                  </td>
                  <td style="padding:.32rem .6rem;font-size:.75rem;"><?= htmlspecialchars($rec['RefNo'] ?? '—') ?> <span class="dim">/ <?= htmlspecialchars($rec['Outlet'] ?: ($rec['Area'] ?? '—')) ?></span></td>
                  <td style="padding:.32rem .6rem;text-align:center;">
                    <button onclick="event.stopPropagation();openSsItems(<?= (int)($rec['SEID'] ?? 0) ?>, <?= htmlspecialchars(json_encode($rec['EmployeeName'] ?? '')) ?>, <?= htmlspecialchars(json_encode($rec['RefNo'] ?? '')) ?>, <?= htmlspecialchars(json_encode($rec['EmployeeID'] ?? '')) ?>, <?= htmlspecialchars(json_encode($rec['SDID'] ?? '')) ?>)" style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:6px;border:1.5px solid #fcd34d;background:#fef3c7;color:#92400e;cursor:pointer;white-space:nowrap;"><i class="bi bi-list-ul"></i> Items</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr style="background:#fef3c7;">
                  <td colspan="2" style="padding:.35rem .6rem;font-weight:700;font-size:.76rem;color:#92400e;"><?= count($g['records']) ?> record(s)</td>
                  <td></td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;"><?= peso($g['TotalAmount']) ?></td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;color:var(--c-green);"><?= peso($g['TotalPaid']) ?></td>
                  <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:800;color:var(--c-red);"><?= peso($g['TotalBalance']) ?></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
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

<!-- Payment History Modal -->
<div id="payHistModal" onclick="closePayHistModal(event)" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
  <div class="ss-modal-box" style="max-width:1100px;">
    <div class="ss-modal-header" style="background:#fffbeb;">
      <span class="ss-modal-title" id="payHistTitle" style="color:#92400e;"><i class="bi bi-hourglass-split" style="color:#f59e0b;margin-right:.35rem;"></i> Payment History</span>
      <button class="ss-modal-close" onclick="document.getElementById('payHistModal').style.display='none'">&#x2715;</button>
    </div>
    <div class="ss-modal-body" id="payHistBody">
      <div class="ss-loading"><i class="bi bi-hourglass-split"></i> Loading…</div>
    </div>
    <div class="ss-modal-footer" id="payHistFooter">—</div>
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
/* ── SDID tab search ── SEARCH ALL DATA (including paginated rows) ── */
function searchSdid(q) {
    const term = q.trim().toLowerCase();
    const tbody = document.getElementById('sdidTableBody');
    if (!tbody) return;
    
    const totalGroups = <?= json_encode(count($sdidSummaries)) ?>;
    const countEl = document.querySelector('.section-toolbar-left .section-count');
    
    // Get the full dataset from PHP
    const allData = <?= json_encode(array_values($sdidSummaries)) ?>;
    const allEmployees = <?= json_encode($sdidGrouped) ?>;
    const rowLimit = 20;
    
    // If search is cleared, reload the page with current filters
    if (!term) {
        // Just reload the page to reset to default view
        const params = new URLSearchParams(window.location.search);
        params.delete('q');
        window.location.href = '?' + params.toString();
        return;
    }
    
    // Filter the full dataset
    const filtered = allData.filter(item => {
        const searchStr = String(item.SDID + ' ' + 
                               (item.Departments || '') + ' ' + 
                               (item.Areas || '') + ' ' + 
                               (item.PlateNumber || '') + ' ' + 
                               (item.RefNo || '')).toLowerCase();
        return searchStr.includes(term);
    });
    
    // Clear the table body (keep thead and tfoot)
    const thead = tbody.closest('table').querySelector('thead');
    const tfoot = tbody.closest('table').querySelector('tfoot');
    tbody.innerHTML = '';
    
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="11"><div class="empty-state"><i class="bi bi-search"></i>No matching groups found for "${escHtml(term)}".</div></td></tr>`;
        if (countEl) {
            countEl.textContent = `0 / ${totalGroups} groups (filtered)`;
            countEl.style.background = '#fee2e2';
            countEl.style.color = '#991b1b';
            countEl.style.borderColor = '#f87171';
        }
        // Hide pagination
        const pager = document.getElementById('teamPager');
        if (pager) pager.style.display = 'none';
        return;
    }
    
    // Build rows for filtered data (show ALL matches, no pagination)
    let html = '';
    filtered.forEach((s, index) => {
        const sBal = parseFloat(s.TotalBalance || 0);
        const rowBg = sBal >= 5000 ? 'background:#fff0f0;border-left:3px solid #ef4444;'
                     : (sBal >= 1000 ? 'background:#fffbeb;border-left:3px solid #eab308;' : '');
        const s1 = String(s.Status1 || '').toUpperCase();
        let s1bg, s1color, s1border, s1icon;
        if (s1 === 'CONFIRMED') {
            s1bg = '#dcfce7'; s1color = '#166534'; s1border = '#4ade80'; s1icon = '✓';
        } else if (s1 === 'CREATED') {
            s1bg = '#fef9c3'; s1color = '#713f12'; s1border = '#fde047'; s1icon = '🕐';
        } else if (s1 === 'VOID') {
            s1bg = '#fee2e2'; s1color = '#991b1b'; s1border = '#f87171'; s1icon = '✕';
        } else {
            s1bg = '#f3f4f6'; s1color = '#374151'; s1border = '#d1d5db'; s1icon = '•';
        }
        const searchStr = String(s.SDID + ' ' + (s.Departments || '') + ' ' + (s.Areas || '') + ' ' + (s.PlateNumber || '') + ' ' + (s.RefNo || '')).toLowerCase();
        const sdidKey = String(s.SDID || '');
        const empRows = allEmployees[sdidKey] || [];
        
        html += `<tr style="${rowBg}cursor:pointer;" data-search="${escHtml(searchStr)}" data-sdid="${escHtml(sdidKey)}" class="sdid-summary-row" onclick="toggleSdidDetail('sdid-detail-${escHtml(sdidKey)}', 'sdid-caret-${escHtml(sdidKey)}')" title="Click to expand employees">
            <td><span style="font-weight:800;font-family:monospace;color:var(--c-purple);font-size:.9rem">${escHtml(sdidKey)}</span>
                <i class="bi bi-chevron-down" id="sdid-caret-${escHtml(sdidKey)}" style="font-size:.65rem;color:var(--c-dim);margin-left:.3rem;transition:transform .2s"></i>
            </td>
            <td class="r mono bold"><span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:999px;padding:2px 10px;font-size:.78rem;font-weight:700;">${Number(s.RowCount || 0).toLocaleString()}</span></td>
            <td style="font-size:.8rem">${escHtml(s.Departments || '—')}</td>
            <td class="dim" style="font-size:.8rem">${escHtml(s.Areas || '—')}</td>
            <td><span class="plate-tag" style="font-size:.75rem">${escHtml(s.PlateNumber || '—')}</span></td>
            <td class="mono dim" style="font-size:.8rem">${escHtml(s.LatestDate || '—')}</td>
            <td class="r mono bold" style="color:var(--c-green)">${pesoFmt(s.TotalAmount || 0)}</td>
            <td class="r mono" style="color:var(--c-green)">${pesoFmt(s.TotalPaid || 0)}</td>
            <td class="r mono bold" style="color:${sBal > 0 ? 'var(--c-red)' : 'var(--c-dim)'};font-size:.95rem">${sBal > 0 ? '▼ ' + pesoFmt(sBal) : '—'}</td>
            <td><span style="background:${s1bg};color:${s1color};border:1px solid ${s1border};border-radius:999px;padding:2px 9px;font-size:.73rem;font-weight:700;white-space:nowrap;">${s1icon} ${escHtml(s.Status1 || '—')}</span></td>
            <td class="mono" style="font-size:.78rem;color:var(--c-purple);font-weight:700">${escHtml(s.RefNo || '—')}</td>
        </tr>`;
        
        // Detail row
        html += `<tr id="sdid-detail-${escHtml(sdidKey)}" class="sdid-detail-row" style="display:none;">
            <td colspan="12" style="padding:0;background:#f9f5ff;">
                <div style="padding:.5rem .75rem .75rem;">
                    <table style="width:100%;border-collapse:collapse;font-size:.78rem;border:1px solid #ddd6fe;border-radius:8px;overflow:hidden;">
                        <thead><tr style="background:#ede9fe;">
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
                            <th style="padding:.3rem .6rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#5b21b6;border-bottom:1px solid #ddd6fe;">Settled</th>
                        </tr></thead>
                        <tbody>`;
        
        empRows.forEach((emp, ei) => {
            const empBal = parseFloat(emp.Balance || 0);
            const empPaid = parseFloat(emp.PaidAmount || 0);
            const empAmt = parseFloat(emp.AmountDue || 0);
            const empBg2 = ei % 2 === 0 ? '#fff' : '#faf5ff';
            const hasPaid = empPaid > 0;
            const datePaid = emp.DatePaid || '';
            const empId = String(emp.EmployeeID || '');
            const empName = String(emp.EmployeeName || '—');
            const sdidVal = String(sdidKey);
            
            html += `<tr style="background:${empBg2};">
                <td style="padding:.32rem .6rem;font-family:monospace;color:var(--c-dim);font-size:.77rem;">${escHtml(emp.EmployeeID || '—')}</td>
                <td style="padding:.32rem .6rem;font-weight:700;font-size:.8rem;">
                    <span style="display:inline-flex;align-items:center;gap:.3rem;">
                        <span style="width:22px;height:22px;border-radius:50%;background:rgba(124,58,237,.12);color:#7c3aed;font-size:.63rem;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">${escHtml(empName.substring(0,2).toUpperCase())}</span>
                        ${escHtml(empName)}
                    </span>
                </td>
                <td style="padding:.32rem .6rem;font-size:.77rem;color:var(--c-dim);">${escHtml(emp.Position || '—')}</td>
                <td style="padding:.32rem .6rem;font-size:.77rem;">${escHtml(emp.TypeShort || '—')}</td>
                <td style="padding:.32rem .6rem;font-size:.77rem;color:var(--c-dim);">${escHtml((emp.Outlet || '') ? emp.Outlet : (emp.Area || '—'))}</td>
                <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:600;">${pesoFmt(empAmt)}</td>
                <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;color:${hasPaid ? 'var(--c-green)' : 'var(--c-dim)'};font-weight:${hasPaid ? '700' : '400'};">
                    ${hasPaid ? pesoFmt(empPaid) + '<i class="bi bi-check-circle-fill" style="font-size:.68rem;color:var(--c-green);margin-left:.2rem;"></i>' : '<span style="color:#d1d5db">—</span>'}
                </td>
                <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:800;color:${empBal > 0 ? 'var(--c-red)' : 'var(--c-green)'};">${empBal > 0 ? '&#9660; ' + pesoFmt(empBal) : '<span style="color:var(--c-green)">&#10003; Settled</span>'}</td>
                <td style="padding:.32rem .6rem;text-align:center;">
                    <button onclick="event.stopPropagation();openSsItems(${Number(emp.SEID || 0)}, '${escHtml(emp.EmployeeName || '')}', '${escHtml(emp.RefNo || '')}', '${escHtml(emp.EmployeeID || '')}', '${escHtml(sdidVal)}')" style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:6px;border:1.5px solid #fca5a5;background:#fee2e2;color:#991b1b;cursor:pointer;white-space:nowrap;"><i class="bi bi-list-ul"></i> Items</button>
                </td>
                <td style="padding:.32rem .6rem;font-size:.77rem;font-family:monospace;">
                    ${datePaid && empBal <= 0 ? '<span style="color:var(--c-green);font-weight:600;"><i class="bi bi-calendar-check" style="font-size:.7rem"></i> ' + escHtml(datePaid) + '</span>' : 
                      hasPaid ? '<span style="color:#f59e0b;font-weight:600;"><i class="bi bi-clock-history" style="font-size:.7rem"></i> Partially paid</span>' : 
                      '<span style="color:#d1d5db">Not yet paid</span>'}
                </td>
                <td style="padding:.32rem .6rem;text-align:center;">
                    ${empBal <= 0 ? 
                      `<button onclick="event.stopPropagation();openPayHistory('${escHtml(emp.EmployeeID || '')}', '${escHtml(sdidVal)}', '${escHtml(emp.EmployeeName || '')}')" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;cursor:pointer;"><i class="bi bi-check-circle-fill"></i> Settled</button>` :
                      hasPaid ? 
                      `<button onclick="event.stopPropagation();openPayHistory('${escHtml(emp.EmployeeID || '')}', '${escHtml(sdidVal)}', '${escHtml(emp.EmployeeName || '')}')" style="background:#fef3c7;color:#b45309;border:1px solid #fcd34d;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;cursor:pointer;"><i class="bi bi-hourglass-split"></i> Partial</button>` :
                      `<span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:999px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap;"><i class="bi bi-x-circle-fill"></i> Unpaid</span>`
                    }
                </td>
            </tr>`;
        });
        
        html += `</tbody>
                <tfoot>
                    <tr style="background:#ede9fe;">
                        <td colspan="5" style="padding:.35rem .6rem;font-weight:700;font-size:.76rem;color:#5b21b6;">${empRows.length} employee(s)</td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;">${pesoFmt(s.TotalAmount || 0)}</td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;color:var(--c-green);">${pesoFmt(s.TotalPaid || 0)}</td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:800;color:var(--c-red);">&#9660; ${pesoFmt(s.TotalBalance || 0)}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </td>
</tr>`;
    });
    
    tbody.innerHTML = html;
    
    // Update count
    if (countEl) {
        countEl.textContent = `${filtered.length} / ${totalGroups} groups (filtered)`;
        countEl.style.background = '#fef3c7';
        countEl.style.color = '#92400e';
        countEl.style.borderColor = '#fcd34d';
    }
    
    // Hide pagination when searching
    const pager = document.getElementById('teamPager');
    if (pager) pager.style.display = 'none';
    
    // Also update the tfoot totals
    if (tfoot) {
        const totalAmt = filtered.reduce((sum, s) => sum + parseFloat(s.TotalAmount || 0), 0);
        const totalPaid = filtered.reduce((sum, s) => sum + parseFloat(s.TotalPaid || 0), 0);
        const totalBal = filtered.reduce((sum, s) => sum + parseFloat(s.TotalBalance || 0), 0);
        tfoot.innerHTML = `<tr>
            <td colspan="6" style="padding:.5rem .75rem;font-weight:700;font-size:.8rem;">TOTAL — ${filtered.length} SDID groups (filtered)</td>
            <td class="r mono bold" style="color:var(--c-green);padding:.5rem .75rem">${pesoFmt(totalAmt)}</td>
            <td class="r mono" style="padding:.5rem .75rem">${pesoFmt(totalPaid)}</td>
            <td class="r mono bold" style="color:${totalBal > 0 ? 'var(--c-red)' : 'var(--c-dim)'};padding:.5rem .75rem">${totalBal > 0 ? '▼ ' + pesoFmt(totalBal) : '—'}</td>
            <td style="padding:.5rem .75rem"></td>
        </tr>`;
    }
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
    const keys = ['SDID','RowCount','Departments','Areas','PlateNumber','LatestDate','TotalAmount','TotalPaid','TotalBalance','Status1','RefNo'];
    const labels = ['SDID','# Employees','Departments','Areas','Plate No.','Latest Schedule','Total Amount','Amount Paid','Balance Due','Status','Invoice No.'];
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

/* ── Individual Short Stocks tab JS (payment-history view) ─ */
const EMP_HISTORY_ROWS = <?= json_encode($empHistoryRows ?? []) ?>;
const EMP_GROUPED_DATA = <?= json_encode(array_values($empGrouped)) ?>;

function searchEmpHistory(q) {
    const term = q.trim().toLowerCase();
    const tbody = document.getElementById('empHistoryTableBody');
    if (!tbody) return;
    
    const totalEmployees = <?= json_encode(count($empGrouped)) ?>;
    const countEl = document.querySelector('.section-toolbar-left .section-count');
    
    // If search is cleared, reload the page
    if (!term) {
        const params = new URLSearchParams(window.location.search);
        params.delete('q');
        window.location.href = '?' + params.toString();
        return;
    }
    
    // Filter the full dataset
    const filtered = EMP_GROUPED_DATA.filter(item => {
        const searchStr = String(item.EmployeeName + ' ' + 
                               item.Department + ' ' + 
                               item.EmployeeID).toLowerCase();
        return searchStr.includes(term);
    });
    
    // Clear the table body
    tbody.innerHTML = '';
    
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="bi bi-search"></i>No matching employees found for "${escHtml(term)}".</div></td></tr>`;
        if (countEl) {
            countEl.textContent = `0 / ${totalEmployees} employees (filtered)`;
            countEl.style.background = '#fee2e2';
            countEl.style.color = '#991b1b';
            countEl.style.borderColor = '#f87171';
        }
        const pager = document.getElementById('teamPager');
        if (pager) pager.style.display = 'none';
        return;
    }
    
    // Build rows for filtered data
    let html = '';
    filtered.forEach((g) => {
        const detailId = g.DetailId || 'emp-detail-' + Math.random().toString(36).substr(2, 9);
        const caretId = g.CaretId || 'emp-caret-' + Math.random().toString(36).substr(2, 9);
        const searchStr = String(g.EmployeeName + ' ' + g.Department + ' ' + g.EmployeeID).toLowerCase();
        
        html += `<tr class="sdid-summary-row emp-summary-row" data-search="${escHtml(searchStr)}" data-detail="${detailId}" onclick="toggleSdidDetail('${detailId}', '${caretId}')" style="cursor:pointer;" title="Click to expand payment history">
            <td><span style="font-weight:700;font-size:.85rem">${escHtml(g.EmployeeName)}</span>
                <i class="bi bi-chevron-down" id="${caretId}" style="font-size:.65rem;color:var(--c-dim);margin-left:.3rem;transition:transform .2s"></i>
            </td>
            <td class="dim" style="font-size:.8rem">${escHtml(g.Position || '—')}</td>
            <td style="font-size:.8rem">${escHtml(g.Department || '—')}</td>
            <td class="r mono bold">${g.records ? g.records.length : 0}</td>
            <td class="r mono bold">${pesoFmt(g.TotalAmount || 0)}</td>
            <td class="r mono" style="color:var(--c-green)">${pesoFmt(g.TotalPaid || 0)}</td>
            <td class="r mono bold" style="color:${g.TotalBalance > 0 ? 'var(--c-red)' : 'var(--c-green)'}">${g.TotalBalance > 0 ? '▼ ' + pesoFmt(g.TotalBalance) : '✓ ' + pesoFmt(0)}</td>
        </tr>`;
        
        // Detail row
        html += `<tr id="${detailId}" class="sdid-detail-row" style="display:none;">
            <td colspan="7" style="padding:0;background:#fffbeb;">
                <div style="padding:.5rem .75rem .75rem;">
                    <table style="width:100%;border-collapse:collapse;font-size:.78rem;border:1px solid #fde68a;border-radius:8px;overflow:hidden;">
                        <thead><tr style="background:#fef3c7;">
                            <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Schedule</th>
                            <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Generate</th>
                            <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">No.</th>
                            <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Due</th>
                            <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Paid</th>
                            <th style="padding:.3rem .6rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#92400e;">Balance</th>
                            <th style="padding:.3rem .6rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#92400e;">Ref / Outlet</th>
                            <th style="padding:.3rem .6rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#92400e;">Items</th>
                        </tr></thead>
                        <tbody>`;
        
        if (g.records) {
            g.records.forEach((rec, ri) => {
                const recBg = ri % 2 === 0 ? '#fff' : '#fffdf5';
                const recBal = parseFloat(rec.Balance || 0);
                html += `<tr style="background:${recBg};">
                    <td style="padding:.32rem .6rem;font-family:monospace;color:var(--c-dim);font-size:.76rem;">${escHtml(rec.DateSchedule || '—')}</td>
                    <td style="padding:.32rem .6rem;font-family:monospace;font-size:.76rem;">${escHtml(rec.DateGenerate || '—')}</td>
                    <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;">${Number(rec.NumAccountable || 0)}</td>
                    <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:600;">${pesoFmt(rec.AmountDue || 0)}</td>
                    <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;color:var(--c-green);font-weight:600;">${pesoFmt(rec.PaidAmount || 0)}</td>
                    <td style="padding:.32rem .6rem;text-align:right;font-family:monospace;font-weight:800;color:${recBal > 0 ? 'var(--c-red)' : 'var(--c-green)'};">${recBal > 0 ? '▼ ' + pesoFmt(recBal) : '✓ ' + pesoFmt(0)}</td>
                    <td style="padding:.32rem .6rem;font-size:.75rem;">${escHtml(rec.RefNo || '—')} <span class="dim">/ ${escHtml(rec.Outlet || rec.Area || '—')}</span></td>
                    <td style="padding:.32rem .6rem;text-align:center;">
                        <button onclick="event.stopPropagation();openSsItems(${Number(rec.SEID || 0)}, '${escHtml(rec.EmployeeName || '')}', '${escHtml(rec.RefNo || '')}', '${escHtml(rec.EmployeeID || '')}', '${escHtml(rec.SDID || '')}')" style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:6px;border:1.5px solid #fcd34d;background:#fef3c7;color:#92400e;cursor:pointer;white-space:nowrap;"><i class="bi bi-list-ul"></i> Items</button>
                    </td>
                </tr>`;
            });
        }
        
        html += `</tbody>
                <tfoot>
                    <tr style="background:#fef3c7;">
                        <td colspan="2" style="padding:.35rem .6rem;font-weight:700;font-size:.76rem;color:#92400e;">${g.records ? g.records.length : 0} record(s)</td>
                        <td></td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;">${pesoFmt(g.TotalAmount || 0)}</td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:700;color:var(--c-green);">${pesoFmt(g.TotalPaid || 0)}</td>
                        <td style="text-align:right;padding:.35rem .6rem;font-family:monospace;font-weight:800;color:var(--c-red);">${pesoFmt(g.TotalBalance || 0)}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </td>
</tr>`;
    });
    
    tbody.innerHTML = html;
    
    // Update count
    if (countEl) {
        countEl.textContent = `${filtered.length} / ${totalEmployees} employees (filtered)`;
        countEl.style.background = '#fef3c7';
        countEl.style.color = '#92400e';
        countEl.style.borderColor = '#fcd34d';
    }
    
    // Hide pagination
    const pager = document.getElementById('teamPager');
    if (pager) pager.style.display = 'none';
}

let _empSortDir = {};
function sortEmpTable(col) {
    const tbody = document.querySelector('#empHistoryTableBody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr.emp-summary-row'));
    _empSortDir[col] = !_empSortDir[col];
    rows.sort((a, b) => {
        const va = (a.cells[col]?.innerText || '').replace(/[₱,▼✓ ]/g, '').trim();
        const vb = (b.cells[col]?.innerText || '').replace(/[₱,▼✓ ]/g, '').trim();
        const na = parseFloat(va), nb = parseFloat(vb);
        const res = isNaN(na) || isNaN(nb) ? va.localeCompare(vb) : na - nb;
        return _empSortDir[col] ? res : -res;
    });
    rows.forEach(row => {
        const detail = document.getElementById(row.dataset.detail);
        tbody.appendChild(row);
        if (detail) tbody.appendChild(detail);
    });
}

function exportEmpHistoryCSV() {
    if (!EMP_HISTORY_ROWS.length) return alert('No data to export.');
    const keys = ['EmployeeID','EmployeeName','Job_tittle','Position_held','Employee_Status',
                  'Department','PlateNumber','Area','Outlet','DateSchedule','DateGenerate','DatePaid',
                  'RefNo','TypeShort','Category','TotalAmount','AmountDue','PaidAmount',
                  'Balance','StatusofShort','Remarks'];
    const rows = [keys, ...EMP_HISTORY_ROWS.map(r => keys.map(k => r[k] ?? ''))];
    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const a    = document.createElement('a');
    a.href     = 'data:text/csv;charset=utf-8,\uFEFF' + encodeURIComponent(csv);
    a.download = `short_stocks_history_<?= date('Ymd') ?>.csv`; a.click();
}

function exportEmpHistoryExcel() {
    if (!EMP_HISTORY_ROWS.length) return alert('No data to export.');
    const ws = XLSX.utils.json_to_sheet(EMP_HISTORY_ROWS);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Short Stocks History');
    XLSX.writeFile(wb, `short_stocks_history_<?= date('Ymd') ?>.xlsx`);
}

function printEmpHistory() {
    if (!EMP_HISTORY_ROWS.length) return alert('No data to print.');
    const sorted = [...EMP_HISTORY_ROWS].sort((a, b) => {
        const n = (a.EmployeeName || '').localeCompare(b.EmployeeName || '');
        return n !== 0 ? n : (a.DateGenerate || '').localeCompare(b.DateGenerate || '');
    });
    let rows = '', lastEmp = null;
    sorted.forEach((r, i) => {
        if (r.EmployeeName !== lastEmp) {
            rows += `<tr style="background:#f3f4f6"><td colspan="9" style="font-weight:700;padding:4px 6px;">${r.EmployeeName || '—'} <span style="font-weight:400;color:#666">(${r.EmployeeID || ''})</span></td></tr>`;
            lastEmp = r.EmployeeName;
        }
        rows += `<tr style="background:${i % 2 === 0 ? '#fff' : '#fafafa'}">
          <td>${r.DateSchedule ?? ''}</td><td>${r.DateGenerate ?? ''}</td>
          <td style="text-align:right">${parseFloat(r.TotalAmount||0).toFixed(2)}</td>
          <td style="text-align:right">${r.NumAccountable ?? 0}</td>
          <td style="text-align:right">${parseFloat(r.AmountDue||0).toFixed(2)}</td>
          <td style="text-align:right">${parseFloat(r.PaidAmount||0).toFixed(2)}</td>
          <td style="text-align:right;font-weight:700">${parseFloat(r.Balance||0).toFixed(2)}</td>
          <td>${r.RefNo ?? ''}</td><td>${r.Outlet || r.Area || ''}</td></tr>`;
    });
    const headers = ['Schedule','Generate','Total Amt','No.','Due','Paid','Balance','Ref No','Outlet'];
    const thead = '<thead><tr>' + headers.map(h => `<th style="padding:3px 7px;border:1px solid #ccc;background:#f3f4f6;white-space:nowrap">${h}</th>`).join('') + '</tr></thead>';
    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head><title>Individual Short Payment Paid</title>
    <style>body{font-family:Arial,sans-serif;font-size:10px;margin:14px;color:#111}h3{margin:0 0 4px;font-size:13px}p{margin:0 0 8px;color:#666;font-size:10px}table{width:100%;border-collapse:collapse}td{padding:2px 7px;border:1px solid #ddd}@media print{body{margin:0}}</style>
    </head><body><h3>Individual Short Payment Paid</h3><p>Generated between: <?= htmlspecialchars($empGenFrom) ?> → <?= htmlspecialchars($empGenTo) ?> · Exported: ${new Date().toLocaleString()} · ${sorted.length} records</p>
    <table>${thead}<tbody>${rows}</tbody></table></body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
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
        const cols = ['Item','UOM','QTY','DateSchedule','PlateNumber','Area','Outlet','TypeShort'];
        const colLabels = { Item:'Item', UOM:'UOM', QTY:'Qty',
                            DateSchedule:'Date', PlateNumber:'Plate', Area:'Area', Outlet:'Outlet',
                            TypeShort:'Type' };
        const ths = cols.map(c => `<th>${colLabels[c]||c}</th>`).join('');
        const trs = data.map(r => {
            totalAmt += parseFloat(r.ItemAmount ?? 0);
            const tds = cols.map(c => {
                let v = r[c] ?? '—';
                return `<td>${String(v).replace(/</g,'&lt;')}</td>`;
            }).join('');
            return `<tr>${tds}</tr>`;
        }).join('');
        body.innerHTML = `<div style="overflow-x:auto"><table class="ss-items-table"><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table></div>`;
        footer.textContent = `${data.length} item${data.length !== 1 ? 's' : ''}`;
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

/* ── Payment History Modal ─────────────────────────────── */
function openPayHistory(empId, sdid, empName) {
    const modal  = document.getElementById('payHistModal');
    const title  = document.getElementById('payHistTitle');
    const body   = document.getElementById('payHistBody');
    const footer = document.getElementById('payHistFooter');

    title.innerHTML  = `<i class="bi bi-hourglass-split" style="color:#f59e0b;margin-right:.35rem;"></i> Payment History — <b>${escHtml(empName)}</b>`;
    body.innerHTML   = `<div class="ss-loading"><i class="bi bi-hourglass-split"></i> Loading…</div>`;
    footer.textContent = '—';
    modal.style.display = 'flex';

    fetch(`?action=payhist&empid=${encodeURIComponent(empId)}&sdid=${encodeURIComponent(sdid)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.length) {
            body.innerHTML = `<div class="ss-loading" style="color:var(--c-dim)"><i class="bi bi-inbox"></i> No payment records found.</div>`;
            footer.textContent = '0 records';
            return;
        }
        let totalPaid = 0;
        const rows = data.map((r, i) => {
            const paid    = parseFloat(r.PaidAmount ?? 0);
            const bal     = parseFloat(r.Balance    ?? 0);
            const due     = parseFloat(r.AmountDue  ?? 0);
            totalPaid += paid;
            const isLast  = i === data.length - 1;
            const rowBg   = isLast ? 'background:#f0fdf4;' : (i % 2 === 0 ? '' : 'background:#fafafa;');
            return `<tr style="${rowBg}">
               <td class="mono" style="font-size:.75rem;color:${r.DateGenerate ? 'var(--c-green)' : 'var(--c-dim)'};font-weight:${r.DateGenerate ? '700' : '400'}">${escHtml(r.DateGenerate ?? '—')}</td>
                <td class="mono r" style="font-weight:600;">₱${due.toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                <td class="mono r" style="color:var(--c-green);font-weight:700;">₱${paid.toLocaleString('en-PH',{minimumFractionDigits:2})} <i class="bi bi-check-circle-fill" style="font-size:.65rem"></i></td>
                <td class="mono r" style="color:${bal > 0 ? 'var(--c-red)' : 'var(--c-green)'};font-weight:800;">${bal > 0 ? '▼ ' : '✓ '}₱${bal.toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                <td style="font-size:.72rem;">${escHtml(r.RefNo ?? '—')}</td>
                <td style="font-size:.72rem;color:var(--c-dim);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.Remarks??'')}">${escHtml(r.Remarks || '—')}</td>
            </tr>`;
        }).join('');

        body.innerHTML = `
        <div style="overflow-x:auto">
        <table class="ss-items-table">
            <thead>
                <tr>
                    <th>Date Generated</th>
                    <th style="text-align:left">Amt Due</th>
                    <th style="text-align:left">Paid</th>
                    <th style="text-align:left">Balance</th>
                    <th>Ref No</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        </div>`;
        footer.textContent = `${data.length} installment${data.length !== 1 ? 's' : ''} · Total paid: ₱${totalPaid.toLocaleString('en-PH',{minimumFractionDigits:2})}`;
    })
    .catch(() => {
        body.innerHTML = `<div class="ss-loading" style="color:var(--c-red)"><i class="bi bi-exclamation-triangle"></i> Failed to load payment history.</div>`;
    });
}

function closePayHistModal(e) {
    if (e.target === document.getElementById('payHistModal')) {
        document.getElementById('payHistModal').style.display = 'none';
    }
}
</script>

</body>
</html>