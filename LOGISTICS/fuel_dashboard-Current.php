<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'fuel_dashboard');

// ============================================================
// AJAX: Fetch plates by vehicle type
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'plates') {
    header('Content-Type: application/json');
    $vt = isset($_GET['vtype']) ? trim($_GET['vtype']) : '';
    $_vtSafe = str_replace("'", "''", $vt);
    if ($vt !== '') {
        $sql = "SELECT DISTINCT f.PlateNumber
                FROM [dbo].[Tbl_fuel] f
                LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
                WHERE v.Vehicletype = '$_vtSafe'
                  AND f.PlateNumber IS NOT NULL AND f.PlateNumber <> ''
                ORDER BY f.PlateNumber";
    } else {
        $sql = "SELECT DISTINCT PlateNumber
                FROM [dbo].[Tbl_fuel]
                WHERE PlateNumber IS NOT NULL AND PlateNumber <> ''
                ORDER BY PlateNumber";
    }
    $stmt = sqlsrv_query($conn, $sql);
    $plates = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $plates[] = $row['PlateNumber'];
    }
    sqlsrv_free_stmt($stmt);
    echo json_encode($plates);
    sqlsrv_close($conn);
    exit;
}

// ============================================================
// VEHICLE CLASSIFICATION
// ============================================================
$TRUCK_TYPES = "'ELF','CANTER','FORWARD','FIGHTER'";
$CAR_TYPES   = "'CAR','MOTOR','L300','VAN','CROSS WIND'";
$ALL_TYPES   = "'ELF','CANTER','FORWARD','FIGHTER','CAR','MOTOR','L300','VAN','CROSS WIND'";

// --- ACTIVE TAB ---
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';

// --- VEHICLE MODE: always ALL now ---
$mode      = 'all';
$modeTypes = $ALL_TYPES;
$modeVtypeWhere         = "AND v.Vehicletype IN ($modeTypes)";
$modeVtypeWhereNullable = "AND (v.Vehicletype IS NULL OR v.Vehicletype IN ($modeTypes))";

// --- DATE FILTER ---
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
$anyFilterApplied = ($dateFrom !== '' || $dateTo !== ''
    || (isset($_GET['dept'])   && $_GET['dept']   !== '')
    || (isset($_GET['vtype'])  && $_GET['vtype']  !== '')
    || (isset($_GET['plate'])  && $_GET['plate']  !== '')
    || (isset($_GET['driver']) && $_GET['driver'] !== '')
    || (isset($_GET['area'])   && $_GET['area']   !== ''));

if ($dateActive) {
    $baseFrom = $dateFrom !== '' ? $dateFrom : '1900-01-01';
    $baseTo   = $dateTo   !== '' ? $dateTo   : date('Y-m-d');
} elseif ($anyFilterApplied) {
    $baseFrom = '1900-01-01';
    $baseTo   = date('Y-m-d');
} else {
    $baseFrom = date('Y-m-01');
    $baseTo   = date('Y-m-d');
}

// --- DEPARTMENT FILTER (now user-selectable, no longer forced from session) ---
$selDept    = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : ($_SESSION['Department'] ?? '');
$deptActive = ($selDept !== '');
$_selDeptSafe = str_replace("'", "''", $selDept);
$deptWhere  = $deptActive ? "AND v.Department = '$_selDeptSafe'" : '';
$deptWhereF    = $deptActive ? "AND ts.Department = '$_selDeptSafe'" : '';
$deptWhereFuel = $deptActive ? "AND (
    NULLIF(f.Department,'') = '$_selDeptSafe'
    OR (NULLIF(f.Department,'') IS NULL AND EXISTS (
        SELECT 1 FROM [dbo].[TruckSchedule] tsx
        WHERE tsx.PlateNumber = f.PlateNumber
          AND tsx.Department = '$_selDeptSafe'
    ))
)" : '';

$deptColors = [
    'Monde'      => ['bg' => 'rgba(239,68,68,.15)',   'color' => '#ef4444', 'border' => '#fca5a5'],
    'Century'    => ['bg' => 'rgba(59,130,246,.15)',  'color' => '#3b82f6', 'border' => '#93c5fd'],
    'Multilines' => ['bg' => 'rgba(234,179,8,.15)',   'color' => '#ca8a04', 'border' => '#fde047'],
    'NutriAsia'  => ['bg' => 'rgba(16,185,129,.15)',  'color' => '#059669', 'border' => '#6ee7b7'],
    ''           => ['bg' => 'rgba(107,114,128,.15)', 'color' => '#6b7280', 'border' => '#9ca3af'],
];
function deptStyle(string $dept, array $deptColors): string {
    $c = $deptColors[$dept] ?? $deptColors[''];
    return "background:{$c['bg']};color:{$c['color']};border-color:{$c['border']};";
}

// --- VEHICLE TYPE FILTER ---
$selVtype  = isset($_GET['vtype']) && $_GET['vtype'] !== '' ? trim($_GET['vtype']) : '';
$vtypeActive = ($selVtype !== '');
$_selVtypeSafe = str_replace("'", "''", $selVtype);
$vtypeWhere  = $vtypeActive ? "AND v.Vehicletype = '$_selVtypeSafe'" : '';
$vtypeWhereF = $vtypeActive ? "AND v.Vehicletype = '$_selVtypeSafe'" : '';

// --- PLATE FILTER ---
$selPlate  = isset($_GET['plate']) && $_GET['plate'] !== '' ? trim($_GET['plate']) : '';
$plateActive = ($selPlate !== '');
$_plateSafe  = str_replace("'", "''", $selPlate);
$plateWhereF = $plateActive ? "AND ts.PlateNumber LIKE '%$_plateSafe%'" : '';
$plateWhereR = $plateActive ? "AND f.PlateNumber LIKE '%$_plateSafe%'" : '';

// --- DRIVER FILTER ---
$selDriver = isset($_GET['driver']) && $_GET['driver'] !== '' ? trim($_GET['driver']) : '';
$driverActive = ($selDriver !== '');
$_driverSafe  = str_replace("'", "''", $selDriver);
$driverWhereF = $driverActive ? "AND EXISTS (SELECT 1 FROM [dbo].[teamschedule] td2 WHERE td2.PlateNumber = ts.PlateNumber AND td2.ScheduleDate = ts.ScheduleDate AND td2.Position LIKE '%DRIVER%' AND td2.Employee_Name LIKE '%$_driverSafe%')" : '';
$driverWhereR = $driverActive ? "AND f.Requested LIKE '%$_driverSafe%'" : '';

// --- AREA FILTER ---
$selArea = isset($_GET['area']) && $_GET['area'] !== '' ? trim($_GET['area']) : '';
$areaActive = ($selArea !== '');
$_areaSafe  = str_replace("'", "''", $selArea);
$areaWhereF = $areaActive ? "AND ts.Area LIKE '%$_areaSafe%'" : '';
$areaWhereR = $areaActive ? "AND f.Area LIKE '%$_areaSafe%'" : '';

$anyFilter = $dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive;

$checklistFrom = $anyFilter ? $baseFrom : date('Y-m-d');
$checklistTo   = $anyFilter ? $baseTo   : date('Y-m-d');
$checklistFilterActive = $dateActive || $vtypeActive || $plateActive || $driverActive;

// --- MONTH/YEAR PARAMS ---
$selYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$selMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear  = max(2025, min((int)date('Y'), $selYear));
$selMonth = max(1, min(12, $selMonth));

// ============================================================
// BUILD $filterSQL ONCE
// ============================================================
$filterSQL = '';

if ($dateFrom !== '' && $dateTo !== '') {
    $filterSQL .= " AND f.Fueldate BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($dateFrom !== '') {
    $filterSQL .= " AND f.Fueldate >= '$dateFrom'";
} elseif ($dateTo !== '') {
    $filterSQL .= " AND f.Fueldate <= '$dateTo'";
}

if ($selVtype !== '') {
    $filterSQL .= " AND v.Vehicletype = '$_selVtypeSafe'";
}
if ($selPlate !== '') {
    $filterSQL .= " AND f.PlateNumber LIKE '%$_plateSafe%'";
}
if ($selDriver !== '') {
    $filterSQL .= " AND f.Requested LIKE '%$_driverSafe%'";
}
if ($selArea !== '') {
    $filterSQL .= " AND f.Area LIKE '%$_areaSafe%'";
}

// ============================================================
// FUEL MONTHLY TAB
// ============================================================
$fcYear  = isset($_GET['fc_year'])  ? max(2020, min((int)date('Y'), (int)$_GET['fc_year']))  : (int)date('Y');
$fcMonth = isset($_GET['fc_month']) ? max(1,    min(12,              (int)$_GET['fc_month'])) : (int)date('m');

function getMonthWeeks(int $year, int $month): array {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $splits = [[1, 7], [8, 14], [15, 21], [22, 28]];
    if ($daysInMonth > 28) $splits[] = [29, $daysInMonth];
    $weeks = [];
    foreach ($splits as [$ds, $de]) {
        $weeks[] = [
            'sql_from' => date('Y-m-d', mktime(0, 0, 0, $month, $ds, $year)),
            'sql_to'   => date('Y-m-d', mktime(0, 0, 0, $month, $de, $year)),
            'label'    => date('M j', mktime(0, 0, 0, $month, $ds, $year))
                        . ' – '
                        . date('j',   mktime(0, 0, 0, $month, $de, $year)),
        ];
    }
    return $weeks;
}

$fcWeeks     = getMonthWeeks($fcYear, $fcMonth);
$fcWeekCount = count($fcWeeks);

$weekCases = '';
for ($wi = 0; $wi < $fcWeekCount; $wi++) {
    $wf = $fcWeeks[$wi]['sql_from'];
    $wt = $fcWeeks[$wi]['sql_to'];
    $n  = $wi + 1;
    $weekCases .= "
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Liters ELSE 0 END),2) AS W{$n}Liters,
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Amount ELSE 0 END),2) AS W{$n}Amount,
        COUNT(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.FuelID END)               AS W{$n}Refuels,";
}
$weekCases = rtrim($weekCases, ',');

$fcMonthStart = sprintf('%04d-%02d-01', $fcYear, $fcMonth);
$fcMonthEnd   = date('Y-m-t', mktime(0, 0, 0, $fcMonth, 1, $fcYear));

// ============================================================
// HELPER: RUN QUERY
// ============================================================
function runQuery($conn, $sql, $params = []) {
    $stmt = empty($params) ? sqlsrv_query($conn, $sql) : sqlsrv_query($conn, $sql, $params);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function fmt($n, $dec = 2) { return $n !== null ? number_format((float)$n, $dec) : '—'; }
function peso($n)          { return $n !== null ? '₱' . number_format((float)$n, 2) : '—'; }

// ============================================================
// QUERIES (unchanged SQL logic, mode now always ALL)
// ============================================================
$q_summary = "
SELECT
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department)  AS Department,
    v.Vehicletype,
    v.FuelType,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    MAX(f.Fueldate)               AS LastRefuelDate,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = f.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC) AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = f.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  $modeVtypeWhere
  $deptWhereFuel
  $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype, v.FuelType
ORDER BY TotalLiters DESC";

$q_ranked_asc = "
SELECT
    RANK() OVER (ORDER BY SUM(f.Liters) ASC) AS Rank,
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
    v.Vehicletype,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = f.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC) AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = f.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  $modeVtypeWhere
  $deptWhereFuel
  $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
ORDER BY TotalLiters ASC";

$q_ranked_desc = "
SELECT
    RANK() OVER (ORDER BY SUM(f.Liters) DESC) AS Rank,
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
    v.Vehicletype,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = f.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC) AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = f.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  $modeVtypeWhere
  $deptWhereFuel
  $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
ORDER BY TotalLiters DESC";

if ($dateActive) {
    $q_30day = "
    DECLARE @S DATE = '$baseFrom';
    DECLARE @E DATE = '$baseTo';
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT f.PlateNumber,
            COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
            COUNT(DISTINCT f.Fueldate) AS DaysRefueled,
            COUNT(f.FuelID)            AS TotalRefuels,
            ROUND(SUM(f.Liters),2)     AS TotalLiters,
            ROUND(AVG(f.Liters),2)     AS AvgLiters,
            ROUND(SUM(f.Amount),2)     AS TotalAmount,
            ROUND(AVG(f.Amount),2)     AS AvgAmount
        FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE f.Fueldate BETWEEN @S AND @E $modeVtypeWhere $deptWhereFuel
        GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department)
    )
    SELECT ra.PlateNumber, ra.Department, v.Vehicletype,
        ISNULL(ra.DaysRefueled,0) AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0) AS DaysNotRefueled,
        ISNULL(ra.TotalRefuels,0) AS TotalRefuels,
        ISNULL(ra.TotalLiters,0) AS TotalLiters,
        ISNULL(ra.AvgLiters,0) AS AvgLiters,
        ISNULL(ra.TotalAmount,0) AS TotalAmount,
        ISNULL(ra.AvgAmount,0) AS AvgAmount,
        (SELECT TOP 1 tla.Area FROM [dbo].[TruckSchedule] tla WHERE tla.PlateNumber = ra.PlateNumber AND tla.ScheduleDate BETWEEN @S AND @E ORDER BY tla.ScheduleDate DESC) AS LatestArea,
        STUFF((SELECT DISTINCT ', ' + taa.Area FROM [dbo].[TruckSchedule] taa WHERE taa.PlateNumber = ra.PlateNumber AND taa.ScheduleDate BETWEEN @S AND @E AND taa.Area IS NOT NULL AND taa.Area <> '' FOR XML PATH('')), 1, 2, '') AS AllAreas
    FROM RA ra
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ra.PlateNumber
    ORDER BY ra.TotalLiters DESC";
} else {
    $q_30day = "
    DECLARE @S DATE = DATEADD(DAY,-29,CAST(GETDATE() AS DATE));
    DECLARE @E DATE = CAST(GETDATE() AS DATE);
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT f.PlateNumber,
            COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
            COUNT(DISTINCT f.Fueldate) AS DaysRefueled,
            COUNT(f.FuelID) AS TotalRefuels,
            ROUND(SUM(f.Liters),2) AS TotalLiters,
            ROUND(AVG(f.Liters),2) AS AvgLiters,
            ROUND(SUM(f.Amount),2) AS TotalAmount,
            ROUND(AVG(f.Amount),2) AS AvgAmount
        FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE f.Fueldate BETWEEN @S AND @E $modeVtypeWhere $deptWhereFuel
        GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department)
    )
    SELECT ra.PlateNumber, ra.Department, v.Vehicletype,
        ISNULL(ra.DaysRefueled,0) AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0) AS DaysNotRefueled,
        ISNULL(ra.TotalRefuels,0) AS TotalRefuels,
        ISNULL(ra.TotalLiters,0) AS TotalLiters,
        ISNULL(ra.AvgLiters,0) AS AvgLiters,
        ISNULL(ra.TotalAmount,0) AS TotalAmount,
        ISNULL(ra.AvgAmount,0) AS AvgAmount,
        (SELECT TOP 1 tla.Area FROM [dbo].[TruckSchedule] tla WHERE tla.PlateNumber = ra.PlateNumber AND tla.ScheduleDate BETWEEN @S AND @E ORDER BY tla.ScheduleDate DESC) AS LatestArea,
        STUFF((SELECT DISTINCT ', ' + taa.Area FROM [dbo].[TruckSchedule] taa WHERE taa.PlateNumber = ra.PlateNumber AND taa.ScheduleDate BETWEEN @S AND @E AND taa.Area IS NOT NULL AND taa.Area <> '' FOR XML PATH('')), 1, 2, '') AS AllAreas
    FROM RA ra
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ra.PlateNumber
    ORDER BY ra.TotalLiters DESC";
}

$q_area = "
SELECT
    f.Area,
    COUNT(f.FuelID)                   AS TotalRefuels,
    ROUND(SUM(f.Liters),2)            AS TotalLiters,
    ROUND(AVG(f.Liters),2)            AS AvgLiters,
    ROUND(SUM(f.Amount),2)            AS TotalAmount,
    ROUND(AVG(f.Amount),2)            AS AvgAmount,
    COUNT(DISTINCT f.PlateNumber)     AS UniqueTrucks
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
  $modeVtypeWhere $deptWhereFuel $filterSQL
GROUP BY f.Area
ORDER BY TotalLiters DESC";

$q_truck_area = "
WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
TruckStats AS (
    SELECT f.PlateNumber, f.Area,
        COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
        v.Vehicletype,
        COUNT(f.FuelID) AS Refuels,
        ROUND(SUM(f.Liters),2) AS TotalLiters,
        ROUND(AVG(f.Liters),2) AS TruckAvg,
        ROUND(SUM(f.Amount),2) AS TotalAmount,
        ROUND(AVG(f.Amount),2) AS AvgAmount
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY f.PlateNumber, f.Area, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
),
TruckBracket AS (
    SELECT ts.*,
        CASE
            WHEN ts.Refuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 15 THEN 'HIGH'
            WHEN ts.Refuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 4  THEN 'MID'
            ELSE 'LOW'
        END AS FreqBracket
    FROM TruckStats ts
),
BracketAreaAvg AS (
    SELECT tb.Area, tb.FreqBracket, tb.Vehicletype,
        ROUND(AVG(tb.TruckAvg),2) AS BracketAreaAvg
    FROM TruckBracket tb
    GROUP BY tb.Area, tb.FreqBracket, tb.Vehicletype
)
SELECT
    tb.PlateNumber, tb.Department, tb.Vehicletype, tb.Area,
    tb.FreqBracket AS [Freq Bracket],
    tb.Refuels, tb.TruckAvg,
    ba.BracketAreaAvg AS AreaAvg,
    ROUND(((tb.TruckAvg - ba.BracketAreaAvg) / NULLIF(ba.BracketAreaAvg,0))*100,1) AS PctAboveAreaAvg,
    tb.TotalLiters, tb.TotalAmount, tb.AvgAmount,
    (SELECT COUNT(*) FROM TruckBracket p WHERE p.Area = tb.Area AND p.FreqBracket = tb.FreqBracket AND ISNULL(p.Vehicletype,'') = ISNULL(tb.Vehicletype,'')) AS PeerCount,
    STUFF((SELECT ';;' + p2.PlateNumber + '|' + ISNULL(p2.Vehicletype,'—') + '|' + CAST(p2.TruckAvg AS VARCHAR) + '|' + CAST(p2.Refuels AS VARCHAR)
           FROM TruckBracket p2
           WHERE p2.Area = tb.Area AND p2.FreqBracket = tb.FreqBracket AND ISNULL(p2.Vehicletype,'') = ISNULL(tb.Vehicletype,'')
           ORDER BY p2.TruckAvg DESC FOR XML PATH(''), TYPE).value('.','NVARCHAR(MAX)'), 1, 2, '') AS PeerList
FROM TruckBracket tb
INNER JOIN BracketAreaAvg ba ON ba.Area = tb.Area AND ba.FreqBracket = tb.FreqBracket AND ISNULL(ba.Vehicletype,'') = ISNULL(tb.Vehicletype,'')
ORDER BY tb.Area, tb.FreqBracket, tb.Vehicletype, PctAboveAreaAvg DESC";

$q_anomaly = "
WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
AllRecords AS (
    SELECT f.FuelID, f.PlateNumber,
        COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
        v.Vehicletype, f.Fueldate, f.Area,
        f.Requested AS Driver, f.ORnumber AS InvNum,
        ROUND(f.Liters,2) AS Liters, ROUND(f.Amount,2) AS Amount, ROUND(f.Price,2) AS PricePerLiter
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $modeVtypeWhere $deptWhereFuel $filterSQL
),
TruckBaseline AS (
    SELECT PlateNumber, Area, Department, Vehicletype,
        COUNT(FuelID) AS TotalRefuels,
        ROUND(AVG(Liters),2) AS TruckAvgLiters,
        ROUND(AVG(Amount),2) AS TruckAvgAmount,
        ROUND(MIN(Liters),2) AS TruckMinLiters,
        ROUND(MAX(Liters),2) AS TruckMaxLiters
    FROM AllRecords GROUP BY PlateNumber, Area, Department, Vehicletype HAVING COUNT(FuelID) >= 2
),
TruckBracket AS (
    SELECT tb.*,
        CASE
            WHEN tb.TotalRefuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 15 THEN 'HIGH'
            WHEN tb.TotalRefuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 4  THEN 'MID'
            ELSE 'LOW'
        END AS FreqBracket
    FROM TruckBaseline tb
),
BracketAreaAvg AS (
    SELECT tb.Area, tb.FreqBracket,
        ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
    FROM TruckBracket tb GROUP BY tb.Area, tb.FreqBracket
)
SELECT
    ar.PlateNumber, ar.Department, ar.Vehicletype,
    ar.Fueldate, ar.Area, ar.Driver, ar.InvNum,
    ar.Liters, ar.Amount, ar.PricePerLiter,
    tb.TotalRefuels, tb.TruckAvgLiters, tb.TruckMinLiters, tb.TruckMaxLiters,
    tb.FreqBracket, ba.BracketAreaAvg,
    ROUND(((ar.Liters - tb.TruckAvgLiters) / NULLIF(tb.TruckAvgLiters,0))*100,1) AS PctAboveTruckAvg,
    ROUND(((ar.Liters - ba.BracketAreaAvg) / NULLIF(ba.BracketAreaAvg,0))*100,1) AS PctAboveAreaAvg,
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 1.0 OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 2.0 THEN 'CRITICAL'
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5 OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 1.0 THEN 'HIGH'
        ELSE 'WATCH'
    END AS FlagLevel,
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5 AND ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 0.5 THEN 'BOTH'
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5 THEN 'TRUCK AVG'
        ELSE 'AREA AVG'
    END AS TriggeredBy
FROM AllRecords ar
INNER JOIN TruckBracket tb ON tb.PlateNumber = ar.PlateNumber AND tb.Area = ar.Area
INNER JOIN BracketAreaAvg ba ON ba.Area = ar.Area AND ba.FreqBracket = tb.FreqBracket
WHERE ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
   OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 0.5
ORDER BY ar.Fueldate DESC,
    CASE WHEN ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>1.0 OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>2.0 THEN 1
         WHEN ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>0.5 OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>1.0 THEN 2
         ELSE 3 END ASC";

$q_checklist = "
SELECT
    DAY(ts.ScheduleDate) AS [Day],
    ts.ScheduleDate AS [Date],
    CONVERT(VARCHAR(8), f.FuelTime, 108) AS [Fuel Time],
    ts.PlateNumber AS [Plate Number],
    ts.Department AS [Department],
    v.Vehicletype AS [Vehicle Type],
    (SELECT TOP 1 td.Employee_Name FROM [dbo].[teamschedule] td WHERE td.PlateNumber = ts.PlateNumber AND td.ScheduleDate = ts.ScheduleDate AND td.Position LIKE '%DRIVER%') AS [Sched. Driver],
    ts.Area AS [Sched. Area],
    f.Requested AS [Driver],
    f.ORnumber AS [INV #],
    ROUND(f.Liters, 2) AS [Liters],
    ROUND(f.Amount, 2) AS [Amount],
    CASE WHEN f.FuelID IS NOT NULL THEN 'REFUELED' ELSE 'NOT REFUELED' END AS [Status]
FROM [dbo].[TruckSchedule] ts
LEFT JOIN [dbo].[Tbl_fuel] f ON f.PlateNumber = ts.PlateNumber AND f.Fueldate = ts.ScheduleDate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$checklistFrom' AND '$checklistTo'
  AND ts.PlateNumber IS NOT NULL AND ts.PlateNumber <> ''
  $deptWhereF $vtypeWhereF $plateWhereF $driverWhereF $areaWhereF
ORDER BY ts.ScheduleDate, f.ORnumber";

// ============================================================
// LOAD DATA FOR ACTIVE TAB ONLY
// ============================================================
$data = []; $carsData = []; $carsDetail = [];

switch ($tab) {
    case 'summary':     $data = runQuery($conn, $q_summary);    break;
    case 'rank_asc':    $data = runQuery($conn, $q_ranked_asc); break;
    case 'rank_desc':   $data = runQuery($conn, $q_ranked_desc); break;
    case '30day':       $data = runQuery($conn, $q_30day);      break;
    case 'area':        $data = runQuery($conn, $q_area);       break;
    case 'truck_area':  $data = runQuery($conn, $q_truck_area); break;
    case 'anomaly':     $data = runQuery($conn, $q_anomaly);    break;
    case 'checklist':   $data = runQuery($conn, $q_checklist);  break;

    case 'fuel_monthly':
        $weekCases = '';
        for ($wi = 0; $wi < $fcWeekCount; $wi++) {
            $wf = $fcWeeks[$wi]['sql_from']; $wt = $fcWeeks[$wi]['sql_to']; $n = $wi + 1;
            $weekCases .= "
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Liters ELSE 0 END),2) AS W{$n}Liters,
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Amount ELSE 0 END),2) AS W{$n}Amount,
        COUNT(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.FuelID END)               AS W{$n}Refuels,";
        }
        $weekCases = rtrim($weekCases, ',');

        $q_fuel_monthly = "
SELECT
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
    v.Vehicletype,
    COUNT(DISTINCT f.FuelID)          AS TotalRefuels,
    ROUND(SUM(f.Liters),2)            AS TotalLiters,
    ROUND(SUM(f.Amount),2)            AS TotalAmount,
    $weekCases
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts ON f.PlateNumber = ts.PlateNumber AND f.Fueldate = ts.ScheduleDate
LEFT JOIN (
    SELECT PlateNumber, Vehicletype, Department
    FROM (
        SELECT PlateNumber, Vehicletype, Department,
               ROW_NUMBER() OVER (PARTITION BY PlateNumber ORDER BY
                   CASE WHEN Vehicletype IS NOT NULL AND Vehicletype NOT IN ('[select]','WH','TRUCKING') THEN 0 ELSE 1 END,
                   CASE WHEN Active = 1 THEN 0 ELSE 1 END) AS rn
        FROM [dbo].[Vehicle]
    ) ranked WHERE rn = 1
) v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$fcMonthStart' AND '$fcMonthEnd'
  $modeVtypeWhereNullable $deptWhereFuel $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
ORDER BY v.Vehicletype, f.PlateNumber";

        $data = runQuery($conn, $q_fuel_monthly);
        break;

    case 'report':
        $data = runQuery($conn, "
            SELECT
                f.PlateNumber AS [Plate Number],
                f.Fueldate AS [Fuel Date],
                CONVERT(VARCHAR(8), f.FuelTime, 108) AS [Fuel Time],
                ROUND(f.Liters, 2) AS [Liters],
                ROUND(f.Price, 2) AS [Price/Liter],
                ROUND(f.Amount, 2) AS [Amount],
                f.Area AS [Area],
                f.Requested AS [Driver],
                f.ORnumber AS [INV #],
                f.Supplier AS [Supplier],
                COALESCE(NULLIF(f.Department,''), ts.Department) AS [Department],
                v.Vehicletype AS [Vehicle Type]
            FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
            LEFT JOIN (
                SELECT PlateNumber, Vehicletype FROM (
                    SELECT PlateNumber, Vehicletype,
                           ROW_NUMBER() OVER (PARTITION BY PlateNumber ORDER BY
                               CASE WHEN Vehicletype IS NOT NULL AND Vehicletype NOT IN ('[select]','WH','TRUCKING') THEN 0 ELSE 1 END,
                               CASE WHEN Active = 1 THEN 0 ELSE 1 END) AS rn
                    FROM [dbo].[Vehicle]) ranked WHERE rn = 1
            ) v ON v.PlateNumber = f.PlateNumber
            WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
              $deptWhereF $vtypeWhereF $filterSQL $areaWhereR
            ORDER BY f.Fueldate DESC");
        break;
}

// ============================================================
// STAT CARDS
// ============================================================
$statRow = runQuery($conn, "
    SELECT
        COUNT(DISTINCT f.PlateNumber) AS TotalTrucks,
        ROUND(SUM(f.Liters),2)        AS TotalLiters,
        ROUND(SUM(f.Amount),2)        AS TotalAmount,
        COUNT(f.FuelID)               AS TotalRefuels
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $deptWhereFuel $filterSQL");

$totalTrucks  = $statRow[0]['TotalTrucks']  ?? 0;
$totalLiters  = $statRow[0]['TotalLiters']  ?? 0;
$totalAmount  = $statRow[0]['TotalAmount']  ?? 0;
$totalRefuels = $statRow[0]['TotalRefuels'] ?? 0;

// Anomaly count
if ($tab === 'anomaly') {
    $anomalyCount = count($data);
} else {
    $anomalyCountRow = runQuery($conn, "
        WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
        AllRecords AS (
            SELECT f.FuelID, f.PlateNumber, f.Fueldate, f.Area, ROUND(f.Liters,2) AS Liters
            FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
            LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
            WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
              AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
              $modeVtypeWhere $deptWhereFuel $filterSQL
        ),
        TruckBaseline AS (
            SELECT PlateNumber, Area, COUNT(FuelID) AS TotalRefuels, ROUND(AVG(Liters),2) AS TruckAvgLiters
            FROM AllRecords GROUP BY PlateNumber, Area HAVING COUNT(FuelID) >= 2
        ),
        TruckBracket AS (
            SELECT tb.*,
                CASE WHEN tb.TotalRefuels/NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0)>=15 THEN 'HIGH'
                     WHEN tb.TotalRefuels/NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0)>=4 THEN 'MID'
                     ELSE 'LOW' END AS FreqBracket
            FROM TruckBaseline tb
        ),
        BracketAreaAvg AS (
            SELECT tb.Area, tb.FreqBracket, ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
            FROM TruckBracket tb GROUP BY tb.Area, tb.FreqBracket
        )
        SELECT COUNT(*) AS cnt
        FROM AllRecords ar
        INNER JOIN TruckBracket tb ON tb.PlateNumber = ar.PlateNumber AND tb.Area = ar.Area
        INNER JOIN BracketAreaAvg ba ON ba.Area = ar.Area AND ba.FreqBracket = tb.FreqBracket
        WHERE ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>0.5
           OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>0.5");
    $anomalyCount = $anomalyCountRow[0]['cnt'] ?? 0;
}

// ============================================================
// LOOKUP LISTS
// ============================================================
$deptList  = runQuery($conn, "SELECT DISTINCT Department FROM [dbo].[Vehicle] WHERE Active = 1 AND Department IS NOT NULL ORDER BY Department");
$vtypeList = runQuery($conn, "
    SELECT DISTINCT Vehicletype FROM [dbo].[Vehicle]
    WHERE Active = 1
      AND Vehicletype IN ('CANTER','ELF','FIGHTER','FORWARD','L300','CAR','MOTOR','CROSS WIND','VAN')
    ORDER BY Vehicletype");

// Pre-load plates for current vtype selection (for initial render)
if ($selVtype !== '') {
    $plateList = runQuery($conn, "
        SELECT DISTINCT f.PlateNumber FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE v.Vehicletype = '$_selVtypeSafe' AND f.PlateNumber IS NOT NULL AND f.PlateNumber <> ''
        ORDER BY f.PlateNumber");
} else {
    $plateList = runQuery($conn, "
        SELECT DISTINCT PlateNumber FROM [dbo].[Tbl_fuel]
        WHERE PlateNumber IS NOT NULL AND PlateNumber <> ''
        ORDER BY PlateNumber");
}

// ============================================================
// URL HELPERS
// ============================================================
function tabUrl($tab, $dateFrom, $dateTo, $selYear, $selMonth, $selDept = '', $selVtype = '', $extraParams = []) {
    global $selPlate, $selDriver, $selArea;
    $params = $_GET;
    $params['tab'] = $tab;
    unset($params['page'], $params['mode']);
    if ($dateFrom  !== '') $params['date_from'] = $dateFrom; else unset($params['date_from']);
    if ($dateTo    !== '') $params['date_to']   = $dateTo;   else unset($params['date_to']);
    if ($selDept   !== '') $params['dept']       = $selDept;  else unset($params['dept']);
    if ($selVtype  !== '') $params['vtype']      = $selVtype; else unset($params['vtype']);
    if ($selPlate  !== '') $params['plate']      = $selPlate; else unset($params['plate']);
    if ($selDriver !== '') $params['driver']     = $selDriver; else unset($params['driver']);
    if ($selArea   !== '') $params['area']       = $selArea;  else unset($params['area']);
    foreach ($extraParams as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}

function fcUrl($y, $m, $tab = 'fuel_monthly', $extra = []) {
    $p = array_merge($_GET, ['tab' => $tab, 'fc_year' => $y, 'fc_month' => $m]);
    unset($p['page'], $p['mode']);
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query($p);
}

$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
$currentYear = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fuel Monitoring Dashboard — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<style>
/* ── Fuel Monthly Tab CSS ── */
.fc-nav {
    display:flex;align-items:center;gap:.75rem;
    background:var(--surface,#fff);
    border:1px solid var(--border,#e2e8f0);
    border-radius:12px;padding:.55rem 1rem;flex-wrap:wrap;
}
.fc-nav-arrow {
    background:none;border:1px solid var(--border,#e2e8f0);
    border-radius:8px;cursor:pointer;padding:.3rem .65rem;
    font-size:.85rem;color:var(--text-secondary,#475569);
    transition:background .15s;line-height:1;text-decoration:none;
    display:inline-flex;align-items:center;
}
.fc-nav-arrow:hover { background:var(--hover,#f1f5f9); }
.fc-nav-label { font-weight:700;font-size:.95rem;color:var(--text-primary,#0f172a);min-width:160px;text-align:center; }
.fc-table-wrap { overflow-x:auto;margin-top:.5rem; }
#fcTable { width:100%;border-collapse:collapse;font-size:.78rem;min-width:900px; }
#fcTable thead tr th {
    background:var(--surface-2,#f8fafc);color:var(--text-muted,#64748b);
    font-weight:700;font-size:.68rem;letter-spacing:.04em;padding:.45rem .6rem;
    border:1px solid var(--border,#e2e8f0);text-align:center;white-space:nowrap;
    position:sticky;top:0;z-index:2;
}
#fcTable thead tr:first-child th { font-size:.72rem; }
#fcTable tbody td { padding:.4rem .6rem;border:1px solid var(--border,#e2e8f0);vertical-align:middle; }
#fcTable tbody td.td-right  { text-align:right;font-family:'DM Mono',monospace; }
#fcTable tbody td.td-center { text-align:center; }
#fcTable tbody td.td-dim    { color:var(--text-muted,#64748b);font-size:.73rem; }
.fc-vtype-row td {
    background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;font-size:.75rem;
    letter-spacing:.05em;padding:.35rem .75rem;border-top:2px solid #93c5fd;border-bottom:1px solid #93c5fd;
}
.fc-vtype-sub td { background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;font-size:.76rem;border-top:2px solid #93c5fd; }
.fc-grand-total td { background:rgba(99,102,241,.1);color:#3730a3;font-weight:800;font-size:.8rem;border-top:2.5px solid #818cf8; }
.no-refuel {
    display:inline-flex;align-items:center;gap:.25rem;font-size:.65rem;font-weight:700;
    letter-spacing:.03em;color:#94a3b8;padding:.1rem .35rem;border-radius:20px;
    background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.25);white-space:nowrap;
}
.fc-wk-1 { background:rgba(139,92,246,.1)!important;color:#6d28d9!important; }
.fc-wk-2 { background:rgba(59,130,246,.1)!important;color:#1d4ed8!important; }
.fc-wk-3 { background:rgba(16,185,129,.1)!important;color:#065f46!important; }
.fc-wk-4 { background:rgba(245,158,11,.1)!important;color:#92400e!important; }
.fc-wk-5 { background:rgba(239,68,68,.1)!important;color:#991b1b!important; }
.fc-wk-sub-1 { background:rgba(139,92,246,.04)!important; }
.fc-wk-sub-2 { background:rgba(59,130,246,.04)!important; }
.fc-wk-sub-3 { background:rgba(16,185,129,.04)!important; }
.fc-wk-sub-4 { background:rgba(245,158,11,.04)!important; }
.fc-wk-sub-5 { background:rgba(239,68,68,.04)!important;  }

/* ── Revamped Filter Bar ── */
.filter-bar-card {
    background:var(--surface,#fff);
    border:1.5px solid var(--border,#e2e8f0);
    border-radius:14px;padding:1rem 1.25rem;
    margin-bottom:1rem;
    box-shadow:0 1px 4px rgba(0,0,0,.04);
}
.filter-section-label {
    font-size:.65rem;font-weight:800;letter-spacing:.1em;
    text-transform:uppercase;color:var(--text-muted,#64748b);
    margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;
}
.filter-row {
    display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:.65rem;
}
.filter-row:last-child { margin-bottom:0; }
.filter-group { display:flex;flex-direction:column;gap:.22rem; }
.filter-select-label {
    font-size:.68rem;font-weight:600;color:var(--text-muted,#64748b);
    display:flex;align-items:center;gap:.3rem;
}
.filter-divider {
    width:1px;height:32px;background:var(--border,#e2e8f0);
    align-self:flex-end;margin:0 .25rem;flex-shrink:0;
}

/* ── Searchable Plate Combobox ── */
.plate-combo-wrap { position:relative; }
.plate-combo-input {
    width:160px;padding:.32rem .75rem;
    border:1.5px solid var(--border,#e2e8f0);border-radius:8px;
    font-size:.8rem;background:var(--surface,#fff);
    color:var(--text-primary,#0f172a);font-family:inherit;
    transition:border-color .15s,box-shadow .15s;
    cursor:text;
}
.plate-combo-input:focus {
    outline:none;border-color:var(--primary,#3b82f6);
    box-shadow:0 0 0 3px rgba(59,130,246,.12);
}
.plate-combo-input.loading { color:var(--text-muted,#64748b); }
.plate-combo-dropdown {
    display:none;position:absolute;top:calc(100% + 4px);left:0;
    width:200px;max-height:220px;overflow-y:auto;
    background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);
    border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);
    z-index:200;
}
.plate-combo-dropdown.open { display:block; }
.plate-combo-item {
    padding:.4rem .75rem;font-size:.8rem;font-family:'DM Mono',monospace;
    cursor:pointer;color:var(--text-primary,#0f172a);
    transition:background .1s;border-bottom:1px solid var(--border,#e2e8f0);
}
.plate-combo-item:last-child { border-bottom:none; }
.plate-combo-item:hover,.plate-combo-item.highlighted { background:var(--hover,#f1f5f9); }
.plate-combo-item mark { background:rgba(59,130,246,.18);color:#1d4ed8;border-radius:2px;font-style:normal; }
.plate-combo-empty {
    padding:.6rem .75rem;font-size:.78rem;
    color:var(--text-muted,#64748b);text-align:center;
}
.plate-combo-spinner {
    padding:.6rem .75rem;font-size:.78rem;
    color:var(--text-muted,#64748b);text-align:center;
}

/* ── Active filter badges ── */
.active-filters-row {
    display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;
    padding-top:.6rem;border-top:1px dashed var(--border,#e2e8f0);margin-top:.6rem;
}
.filter-badge {
    display:inline-flex;align-items:center;gap:.3rem;
    font-size:.7rem;font-weight:700;padding:.18rem .55rem;
    border-radius:20px;border:1px solid;white-space:nowrap;
}
</style>
</head>
<body>

<?php $topbar_page = 'fuel'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ══ PAGE HEADER ══ -->
  <div class="page-header">
    <div>
      <div class="page-title">Fuel <span>Monitoring</span> Dashboard</div>
      <div class="page-badge">📅 <?= $anyFilterApplied ? 'Filtered: '.htmlspecialchars($baseFrom).' → '.htmlspecialchars($baseTo) : 'This Month: '.date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <!-- ══ STAT CARDS ══ -->
  <div class="stats-row">
    <div class="stat-card">
      <span class="stat-icon">🚛</span>
      <div class="stat-label">Total Vehicles</div>
      <div class="stat-value"><?= $totalTrucks ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⛽</span>
      <div class="stat-label">Total Liters <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value teal"><?= number_format($totalLiters, 0) ?> L</div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">💰</span>
      <div class="stat-label">Total Amount <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value accent">₱<?= number_format($totalAmount, 0) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">🔄</span>
      <div class="stat-label">Total Refuels <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value"><?= number_format($totalRefuels) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⚠️</span>
      <div class="stat-label">Anomaly Flags <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value red"><?= $anomalyCount ?></div>
    </div>
  </div>

  <!-- ══ REVAMPED FILTER BAR ══ -->
  <div class="filter-bar-card">
    <form method="GET" id="filterForm">
      <input type="hidden" name="tab"  value="<?= htmlspecialchars($tab) ?>">
      <?php if ($tab === 'fuel_monthly'): ?>
      <input type="hidden" name="fc_year"  value="<?= $fcYear ?>">
      <input type="hidden" name="fc_month" value="<?= $fcMonth ?>">
      <?php endif; ?>

      <!-- Row 1: Date Range -->
      <div class="filter-section-label">
        <i class="bi bi-calendar3"></i> Date Range
      </div>
      <div class="filter-row">
        <div class="filter-group">
          <span class="filter-select-label">From</span>
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="date-input">
        </div>
        <div class="filter-group">
          <span class="filter-select-label">To</span>
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="date-input">
        </div>
        <div class="filter-divider"></div>

        <!-- Department -->
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-building"></i> Department</span>
          <select name="dept" class="dept-select" style="min-width:130px;">
            <option value="">All Departments</option>
            <?php foreach ($deptList as $d):
                $dVal   = $d['Department'];
                $dStyle = '';
                switch(strtolower($dVal)) {
                    case 'monde':      $dStyle = 'color:#ef4444;font-weight:700;'; break;
                    case 'century':    $dStyle = 'color:#3b82f6;font-weight:700;'; break;
                    case 'multilines': $dStyle = 'color:#ca8a04;font-weight:700;'; break;
                    case 'nutriasia':  $dStyle = 'color:#059669;font-weight:700;'; break;
                }
            ?>
            <option value="<?= htmlspecialchars($dVal) ?>" <?= ($selDept === $dVal) ? 'selected' : '' ?> style="<?= $dStyle ?>">
              <?= htmlspecialchars($dVal) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="filter-divider"></div>

        <!-- Vehicle Type -->
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-truck"></i> Vehicle Type</span>
          <select name="vtype" id="vtypeSelect" class="dept-select" style="min-width:130px;" onchange="onVtypeChange(this.value)">
            <option value="">All Types</option>
            <optgroup label="🚛 Trucks">
              <?php foreach ($vtypeList as $vt):
                  if (!in_array($vt['Vehicletype'], ['ELF','CANTER','FORWARD','FIGHTER'])) continue; ?>
              <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($vt['Vehicletype']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="🚗 Cars & Motors">
              <?php foreach ($vtypeList as $vt):
                  if (!in_array($vt['Vehicletype'], ['CAR','MOTOR','L300','VAN','CROSS WIND'])) continue; ?>
              <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($vt['Vehicletype']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>

        <!-- Plate # — searchable combobox -->
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-tag"></i> Plate #</span>
          <div class="plate-combo-wrap">
            <input type="text"
                   id="plateComboInput"
                   name="plate"
                   class="plate-combo-input"
                   autocomplete="off"
                   placeholder="Search or type plate…"
                   value="<?= htmlspecialchars($selPlate) ?>">
            <div id="plateComboDropdown" class="plate-combo-dropdown"></div>
          </div>
        </div>

        <div class="filter-divider"></div>

        <!-- Driver -->
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-person"></i> Driver</span>
          <input type="text" name="driver" class="date-input" style="width:150px;"
                 value="<?= htmlspecialchars($selDriver) ?>" placeholder="Driver name">
        </div>
        <!-- Area -->
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-geo-alt"></i> Area</span>
          <input type="text" name="area" class="date-input" style="width:140px;"
                 value="<?= htmlspecialchars($selArea) ?>" placeholder="Area name">
        </div>

        <div style="display:flex;gap:.4rem;align-items:flex-end;">
          <button type="submit" class="btn-apply">
            <i class="bi bi-search"></i> Apply
          </button>
          <?php if ($anyFilterApplied): ?>
          <a href="?tab=<?= htmlspecialchars($tab) ?>" class="btn-clear">
            <i class="bi bi-x-lg"></i> Clear
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Active filter badges -->
      <?php if ($dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive || $areaActive): ?>
      <div class="active-filters-row">
        <span style="font-size:.68rem;color:var(--text-muted);font-weight:700;">Active filters:</span>
        <?php if ($dateActive): ?>
        <span class="filter-badge" style="background:rgba(59,130,246,.1);color:#1d4ed8;border-color:#93c5fd;">
          <i class="bi bi-calendar-check"></i> <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?>
        </span>
        <?php endif; ?>
        <?php if ($deptActive): ?>
        <span class="filter-badge" style="background:rgba(16,185,129,.1);color:#065f46;border-color:#6ee7b7;">
          <i class="bi bi-building"></i> <?= htmlspecialchars($selDept) ?>
        </span>
        <?php endif; ?>
        <?php if ($vtypeActive): ?>
        <span class="filter-badge" style="background:rgba(99,102,241,.1);color:#4338ca;border-color:#c4b5fd;">
          <i class="bi bi-truck"></i> <?= htmlspecialchars($selVtype) ?>
        </span>
        <?php endif; ?>
        <?php if ($plateActive): ?>
        <span class="filter-badge" style="background:rgba(234,179,8,.1);color:#92400e;border-color:#fde047;">
          <i class="bi bi-tag"></i> <?= htmlspecialchars($selPlate) ?>
        </span>
        <?php endif; ?>
        <?php if ($driverActive): ?>
        <span class="filter-badge" style="background:rgba(139,92,246,.1);color:#6d28d9;border-color:#c4b5fd;">
          <i class="bi bi-person"></i> <?= htmlspecialchars($selDriver) ?>
        </span>
        <?php endif; ?>
        <?php if ($areaActive): ?>
        <span class="filter-badge" style="background:rgba(239,68,68,.1);color:#991b1b;border-color:#fca5a5;">
          <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selArea) ?>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ TABS ══ -->
  <div class="tabs-wrapper">
    <a href="<?= tabUrl('summary',      $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'summary'      ? 'active' : '' ?>">📊 Overall Summary</a>
    <a href="<?= tabUrl('rank_asc',     $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'rank_asc'     ? 'active' : '' ?>">📈 Low → High</a>
    <a href="<?= tabUrl('rank_desc',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'rank_desc'    ? 'active' : '' ?>">📉 High → Low</a>
    <a href="<?= tabUrl('30day',        $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === '30day'        ? 'active' : '' ?>">📅 30-Day Monitor</a>
    <a href="<?= tabUrl('area',         $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'area'         ? 'active' : '' ?>">📍 Area Summary</a>
    <a href="<?= tabUrl('truck_area',   $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'truck_area'   ? 'active' : '' ?>">📊 Fuel Comparison</a>
    <a href="<?= tabUrl('anomaly',      $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn danger <?= $tab === 'anomaly'      ? 'active' : '' ?>">🚨 Anomaly Flags</a>
    <a href="<?= tabUrl('checklist',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn warning <?= $tab === 'checklist'   ? 'active' : '' ?>">✅ Monthly Checklist</a>
    <a href="<?= tabUrl('fuel_monthly', $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype, ['fc_year' => $fcYear, 'fc_month' => $fcMonth]) ?>"
       class="tab-btn <?= $tab === 'fuel_monthly' ? 'active' : '' ?>">📆 Fuel Consumption</a>
    <a href="<?= tabUrl('report',       $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab === 'report'       ? 'active' : '' ?>">📋 Usage Report</a>
  </div>

  <!-- ══ TABLE SECTION ══ -->
  <div class="table-section">

    <?php
    function deptBadge($dept) {
        $map = [
            'monde'      => 'background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;',
            'century'    => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            'multilines' => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
            'nutriasia'  => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
        ];
        $style = $map[strtolower(trim($dept ?? ''))] ?? 'background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;';
        $label = htmlspecialchars($dept ?: '—');
        return "<span class='dept' style='$style;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em;'>$label</span>";
    }
    function rankBadge($r) {
        $cls = $r <= 3 ? "rank-$r" : "";
        return "<span class='rank $cls'>$r</span>";
    }
    function flagBadge($f) {
        switch ($f) {
            case 'CRITICAL': return "<span class='badge badge-critical'>🔴 Critical</span>";
            case 'HIGH':     return "<span class='badge badge-high'>🟠 High</span>";
            default:         return "<span class='badge badge-watch'>🟡 Watch</span>";
        }
    }
    function progressBar($pct) {
        $pct = (float)$pct;
        $cls = $pct < 30 ? 'crit' : ($pct < 60 ? 'low' : '');
        return "<div class='progress-wrap'>
            <div class='progress-bar'><div class='progress-fill $cls' style='width:{$pct}%'></div></div>
            <div class='progress-pct'>{$pct}%</div></div>";
    }

    $rowLimit   = 20;
    $totalRows  = count($data);
    $totalPages = max(1, (int)ceil($totalRows / $rowLimit));
    $curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
    $offset     = ($curPage - 1) * $rowLimit;
    $displayData = array_slice($data, $offset, $rowLimit);

    function pageUrl($page) { $p = $_GET; $p['page'] = $page; return '?' . http_build_query($p); }
    $prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
    $nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';
    ?>

    <?php if ($tab === 'summary'): ?>
    <div class="table-header">
      <div class="table-title">📊 Overall Fuel Summary per Vehicle
        <span class="table-count"><?= $totalRows ?> vehicles</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search plate, dept..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Plate # <span class="sort-icon">⇅</span></th>
        <th>Department</th>
        <th>Vehicle Type</th>
        <th>Fuel Type</th>
        <th onclick="sortTable(4)" class="right">Refuels <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Total Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Avg Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Total Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Avg Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)">Last Refuel <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)">Latest Area <span class="sort-icon">⇅</span></th>
        <th>All Areas</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="12"><div class="empty-state"><span class="icon">📭</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber']) ?></span></td>
          <td><?= deptBadge($row['Department']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicletype'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['FuelType'] ?? '—') ?></td>
          <td class="right mono"><?= number_format($row['TotalRefuels']) ?></td>
          <td class="right mono bold"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono"><?= fmt($row['AvgLiters']) ?> L</td>
          <td class="right mono bold"><?= peso($row['TotalAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgAmount']) ?></td>
          <td class="dim mono"><?= $row['LastRefuelDate'] instanceof DateTime ? $row['LastRefuelDate']->format('Y-m-d') : htmlspecialchars($row['LastRefuelDate'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['LatestArea'] ?? '—') ?></td>
          <td>
            <?php if (!empty($row['AllAreas'])): ?>
            <button class="btn-areas" onclick="showAreas('<?= htmlspecialchars($row['PlateNumber']) ?>', '<?= htmlspecialchars($row['AllAreas']) ?>')">
              <i class="bi bi-geo-alt"></i> View Areas
            </button>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'rank_asc' || $tab === 'rank_desc'): ?>
    <div class="table-header">
      <div class="table-title">
        <?= $tab === 'rank_asc' ? '📈 Ranked: Lowest → Highest Consumption' : '📉 Ranked: Highest → Lowest Consumption' ?>
        <span class="table-count"><?= $totalRows ?> vehicles</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Rank <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)">Plate # <span class="sort-icon">⇅</span></th>
        <th>Department</th>
        <th>Vehicle Type</th>
        <th onclick="sortTable(4)" class="right">Refuels <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Total Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Avg Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Total Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Avg Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)">Latest Area <span class="sort-icon">⇅</span></th>
        <th>All Areas</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="11"><div class="empty-state"><span class="icon">📭</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td class="center"><?= rankBadge($row['Rank']) ?></td>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber']) ?></span></td>
          <td><?= deptBadge($row['Department']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicletype'] ?? '—') ?></td>
          <td class="right mono"><?= number_format($row['TotalRefuels']) ?></td>
          <td class="right mono bold"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono"><?= fmt($row['AvgLiters']) ?> L</td>
          <td class="right mono bold"><?= peso($row['TotalAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgAmount']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['LatestArea'] ?? '—') ?></td>
          <td>
            <?php if (!empty($row['AllAreas'])): ?>
            <button class="btn-areas" onclick="showAreas('<?= htmlspecialchars($row['PlateNumber']) ?>', '<?= htmlspecialchars($row['AllAreas']) ?>')">
              <i class="bi bi-geo-alt"></i> View Areas
            </button>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === '30day'): ?>
    <div class="table-header">
      <div class="table-title">
        📅 <?= $dateActive ? 'Refuel Monitor' : '30-Day Refuel Monitor' ?>
        <span class="table-count"><?= $totalRows ?> vehicles</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Plate # <span class="sort-icon">⇅</span></th>
        <th>Department</th>
        <th>Vehicle Type</th>
        <th onclick="sortTable(3)" class="right">Days Refueled <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)" class="right">Days Not Refueled <span class="sort-icon">⇅</span></th>
        <th>Coverage</th>
        <th onclick="sortTable(6)" class="right">Total Refuels <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Total Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Total Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)">Latest Area <span class="sort-icon">⇅</span></th>
        <th>All Areas</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="11"><div class="empty-state"><span class="icon">📭</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row):
          $covered = $row['DaysRefueled'] + $row['DaysNotRefueled'];
          $pct     = $covered > 0 ? round($row['DaysRefueled'] / $covered * 100, 1) : 0;
      ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber']) ?></span></td>
          <td><?= deptBadge($row['Department']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicletype'] ?? '—') ?></td>
          <td class="right mono" style="color:var(--teal)"><?= $row['DaysRefueled'] ?></td>
          <td class="right mono" style="color:var(--text-dim)"><?= $row['DaysNotRefueled'] ?></td>
          <td><?= progressBar($pct) ?></td>
          <td class="right mono"><?= number_format($row['TotalRefuels']) ?></td>
          <td class="right mono bold"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono bold"><?= peso($row['TotalAmount']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['LatestArea'] ?? '—') ?></td>
          <td>
            <?php if (!empty($row['AllAreas'])): ?>
            <button type="button" class="btn-areas"
                    onclick="showAreas('<?= htmlspecialchars($row['PlateNumber']) ?>', '<?= htmlspecialchars(str_replace("'","\'", $row['AllAreas'])) ?>')">
              <i class="bi bi-geo-alt-fill"></i> View Areas
            </button>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'area'): ?>
    <div class="table-header">
      <div class="table-title">📍 Fuel by Area
        <span class="table-count"><?= $totalRows ?> areas</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search area..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)" class="right">Refuels <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(2)" class="right">Total Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)" class="right">Avg Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)" class="right">Total Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Avg Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Unique Vehicles <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="7"><div class="empty-state"><span class="icon">📭</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td class="bold"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono"><?= number_format($row['TotalRefuels']) ?></td>
          <td class="right mono bold"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono"><?= fmt($row['AvgLiters']) ?> L</td>
          <td class="right mono bold"><?= peso($row['TotalAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgAmount']) ?></td>
          <td class="right mono"><?= $row['UniqueTrucks'] ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'truck_area'): ?>
    <div class="table-header">
      <div class="table-title">📊 Fuel Comparison
        <span class="table-count"><?= $totalRows ?> records</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
        <span class="table-count" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;">📐 Bracket-Normalized Avg</span>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search plate, area..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Plate # <span class="sort-icon">⇅</span></th>
        <th>Department</th>
        <th onclick="sortTable(2)">Vehicle Type <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)">Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)">Refuel Frequency <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Total Refuels <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">This Vehicle's Avg <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Similar Vehicles' Avg <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Difference <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Total Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Total Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)" class="right">Avg Amount <span class="sort-icon">⇅</span></th>
        <th class="center">References</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="13"><div class="empty-state"><span class="icon">⛽</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row):
          $pct = (float)($row['PctAboveAreaAvg'] ?? 0);
          if ($pct > 200)    { $pctColor = 'var(--red)'; }
          elseif ($pct > 50) { $pctColor = 'var(--orange)'; }
          elseif ($pct >= 0) { $pctColor = 'var(--teal)'; }
          else               { $pctColor = 'var(--text-dim)'; }
          $bracket = $row['Freq Bracket'] ?? '';
          $bracketStyle = match($bracket) {
              'HIGH' => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
              'MID'  => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
              default => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
          };
          $peerCount = (int)($row['PeerCount'] ?? 0);
          $peerList  = $row['PeerList'] ?? '';
          $refLines  = [];
          $refLines[] = '📍 Area avg: ' . fmt($row['AreaAvg']) . ' L — bracket-normalized (' . match($bracket) { 'HIGH' => 'daily', 'MID' => 'weekly', default => 'occasional' } . ' refuelers · ' . htmlspecialchars($row['Vehicletype'] ?? 'all types') . ' · ' . htmlspecialchars($row['Area'] ?? '') . ', ' . $peerCount . ' vehicles)';
          if ($peerList !== '') {
              $refLines[] = '— Similar vehicles (same area · same frequency) —';
              foreach (explode(';;', $peerList) as $peer) {
                  $parts = explode('|', $peer);
                  $pPlate = $parts[0] ?? '—'; $pVtype = $parts[1] ?? '—';
                  $pAvg   = isset($parts[2]) ? fmt((float)$parts[2], 1) : '—';
                  $pRefs  = $parts[3] ?? '—';
                  $marker = ($pPlate === $row['PlateNumber']) ? ' ◀ this vehicle' : '';
                  $refLines[] = '🚗 ' . $pPlate . '  (' . $pVtype . ')  avg ' . $pAvg . ' L  · ' . $pRefs . ' refuels' . $marker;
              }
          }
          $refAttr = htmlspecialchars(implode("\n", $refLines));
      ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber']) ?></span></td>
          <td><?= deptBadge($row['Department']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicletype'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td><span style="<?= $bracketStyle ?>;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars(match($bracket) { 'HIGH' => 'Daily', 'MID' => 'Weekly', default => 'Occasional' }) ?></span></td>
          <td class="right mono"><?= $row['Refuels'] ?></td>
          <td class="right mono bold"><?= fmt($row['TruckAvg']) ?> L</td>
          <td class="right mono dim"><?= fmt($row['AreaAvg']) ?> L</td>
          <td class="right mono bold" style="color:<?= $pctColor ?>"><?= $pct > 0 ? '+' : '' ?><?= fmt($pct, 1) ?>%</td>
          <td class="right mono"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono"><?= peso($row['TotalAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgAmount']) ?></td>
          <td class="center">
            <button type="button" class="trig-badge"
                    style="background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.3);padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;cursor:pointer;border-width:1px;border-style:solid;font-family:inherit;white-space:nowrap;"
                    data-ref="<?= $refAttr ?>" onclick="showRefPop(this)">
              <?= $peerCount ?> vehicles <span style="opacity:.55;font-size:.6rem;">ⓘ</span>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'anomaly'): ?>
    <div class="table-header">
      <div class="table-title">🚨 Anomaly Flags — Suspicious Refuels
        <span class="table-count"><?= $totalRows ?> flagged records</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
        <span class="table-count" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;">📐 Bracket-Normalized</span>
      </div>
      <div class="table-actions">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <?php if (empty($data)): ?>
    <div class="empty-state" style="padding:3rem;"><span class="icon">✅</span><p>No anomalies detected for this period.</p></div>
    <?php else: ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.75rem;">
      <span style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🔴 Critical — &gt;100% above avg</span>
      <span style="background:rgba(249,115,22,.15);color:#ea580c;border:1px solid #fdba74;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟠 High — 50–100% above avg</span>
      <span style="background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟡 Watch — suspicious pattern</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php
    $grouped = []; foreach ($data as $row) { $grouped[$row['PlateNumber']][] = $row; }
    $shownCards = 0;
    foreach ($grouped as $plate => $records):
        if ($shownCards >= 20) break; $shownCards++;
        $firstRow = $records[0];
        $dept = $firstRow['Department'] ?? ''; $vtype = $firstRow['Vehicletype'] ?? '';
        $freqBkt = $firstRow['FreqBracket'] ?? '';
        $truckAvg = $firstRow['TruckAvgLiters'] ?? 0; $truckMin = $firstRow['TruckMinLiters'] ?? 0;
        $truckMax = $firstRow['TruckMaxLiters'] ?? 0; $areaAvg = $firstRow['BracketAreaAvg'] ?? 0;
        $totalRefs = $firstRow['TotalRefuels'] ?? 0;
        $flagLevels = array_column($records, 'FlagLevel');
        $worstFlag = in_array('CRITICAL', $flagLevels) ? 'CRITICAL' : (in_array('HIGH', $flagLevels) ? 'HIGH' : 'WATCH');
        $bracketStyle = match($freqBkt) {
            'HIGH' => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
            'MID'  => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            default => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
        };
        $cardBorder = match($worstFlag) { 'CRITICAL' => '#ef4444', 'HIGH' => '#f97316', default => '#eab308' };
        $truckRefText = "Vehicle avg: " . fmt($truckAvg) . " L · Range: " . fmt($truckMin) . "–" . fmt($truckMax) . " L · " . $totalRefs . " refuels";
        $bracketLabel = match($freqBkt) { 'HIGH' => 'daily', 'MID' => 'weekly', default => 'occasional' };
        $areaRefText  = "Area avg: " . fmt($areaAvg) . " L (bracket-normalized — " . $bracketLabel . " refuelers in same area)";
    ?>
    <div style="background:var(--surface);border:1.5px solid <?= $cardBorder ?>;border-radius:14px;overflow:hidden;">
      <div style="display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:var(--hover);flex-wrap:wrap;border-bottom:1px solid var(--border);">
        <span class="plate" style="font-size:.85rem;"><?= htmlspecialchars($plate) ?></span>
        <?= deptBadge($dept) ?>
        <?php if ($vtype): ?><span style="background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.3);padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($vtype) ?></span><?php endif; ?>
        <span style="<?= $bracketStyle ?>;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars(match($freqBkt) { 'HIGH' => 'Daily Refueler', 'MID' => 'Weekly Refueler', default => 'Occasional Refueler' }) ?></span>
        <?= flagBadge($worstFlag) ?>
        <span style="font-size:.72rem;color:var(--text-muted);margin-left:auto;">
          <span style="font-weight:700;color:var(--text-secondary)"><?= $totalRefs ?></span> refuels
          &nbsp;·&nbsp; Avg <span style="font-weight:700;color:var(--teal)"><?= fmt($truckAvg) ?> L</span>
          &nbsp;·&nbsp; Range <span style="font-weight:700;"><?= fmt($truckMin) ?>–<?= fmt($truckMax) ?> L</span>
        </span>
      </div>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
        <thead><tr style="background:var(--hover);">
          <?php foreach (['Date','Area','Driver','INV #','Liters','Amount','Price/L','vs Vehicle Avg','vs Area Avg','Triggered By','Flag'] as $h): ?>
          <th style="padding:.4rem .75rem;text-align:<?= in_array($h,['Liters','Amount','Price/L','vs Vehicle Avg','vs Area Avg'])?'right':'left' ?>;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);"><?= $h ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($records as $rec):
            $fuelDate  = $rec['Fueldate'] instanceof DateTime ? $rec['Fueldate']->format('Y-m-d') : htmlspecialchars($rec['Fueldate'] ?? '—');
            $pctTruck  = (float)($rec['PctAboveTruckAvg'] ?? 0);
            $pctArea   = (float)($rec['PctAboveAreaAvg']  ?? 0);
            $pctTColor = $pctTruck > 100 ? 'var(--red)' : ($pctTruck > 50 ? 'var(--orange)' : 'var(--yellow)');
            $pctAColor = $pctArea  > 200 ? 'var(--red)' : ($pctArea  > 100 ? 'var(--orange)' : 'var(--yellow)');
            $triggeredBy = $rec['TriggeredBy'] ?? '—';
            $trigStyle   = match($triggeredBy) {
                'BOTH'      => 'background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;',
                'TRUCK AVG' => 'background:rgba(139,92,246,.15);color:#7c3aed;border:1px solid #c4b5fd;',
                default     => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            };
            $popParts = [];
            if ($triggeredBy === 'TRUCK AVG' || $triggeredBy === 'BOTH') $popParts[] = '🚗 ' . $truckRefText;
            if ($triggeredBy === 'AREA AVG'  || $triggeredBy === 'BOTH') $popParts[] = '📍 ' . $areaRefText;
            $popAttr = htmlspecialchars(implode("\n", $popParts));
        ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:.45rem .75rem;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= $fuelDate ?></td>
            <td style="padding:.45rem .75rem;"><?= htmlspecialchars($rec['Area']   ?? '—') ?></td>
            <td style="padding:.45rem .75rem;"><?= htmlspecialchars($rec['Driver'] ?? '—') ?></td>
            <td style="padding:.45rem .75rem;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= htmlspecialchars($rec['InvNum'] ?? '—') ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--teal);"><?= fmt($rec['Liters']) ?> L</td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;"><?= peso($rec['Amount']) ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= $rec['PricePerLiter'] !== null ? '₱' . fmt($rec['PricePerLiter']) : '—' ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?= $pctTColor ?>;"><?= $pctTruck >= 0 ? '+' : '' ?><?= fmt($pctTruck, 1) ?>%</td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?= $pctAColor ?>;"><?= $pctArea  >= 0 ? '+' : '' ?><?= fmt($pctArea, 1) ?>%</td>
            <td style="padding:.45rem .75rem;">
              <button type="button" class="trig-badge"
                      style="<?= $trigStyle ?>;padding:.12rem .45rem;border-radius:20px;font-size:.68rem;font-weight:700;cursor:pointer;border-width:1px;border-style:solid;font-family:inherit;"
                      data-ref="<?= $popAttr ?>" onclick="showRefPop(this)">
                <?= htmlspecialchars($triggeredBy) ?> <span style="opacity:.55;font-size:.6rem;">ⓘ</span>
              </button>
            </td>
            <td style="padding:.45rem .75rem;"><?= flagBadge($rec['FlagLevel']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'checklist'): ?>
    <?php
    $refueledCount    = count(array_filter($data, fn($r) => ($r['Status'] ?? '') === 'REFUELED'));
    $notRefueledCount = count(array_filter($data, fn($r) => ($r['Status'] ?? '') === 'NOT REFUELED'));
    ?>
    <div class="table-header">
      <div class="table-title">✅ Refuel Checklist
        <span class="table-count"><?= $totalRows ?> rows</span>
        <span class="table-count" style="background:#dcfce7;color:#166534;border-color:#86efac;">✅ <?= $refueledCount ?> Refueled</span>
        <span class="table-count" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">❌ <?= $notRefueledCount ?> Not Refueled</span>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:.25rem .6rem;border-radius:.4rem;font-size:.8rem;">
          <i class="bi bi-calendar-check"></i>
          <?= $checklistFilterActive ? htmlspecialchars($checklistFrom) . ' → ' . htmlspecialchars($checklistTo) : 'Apply a filter to load data' ?>
        </span>
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search plate, driver, area..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)" class="right">Day <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)">Date <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(2)">Fuel Time <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)">Plate # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)">Department <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)">Vehicle Type <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)">Sched. Driver <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)">Sched. Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)">INV # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)">Status <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="12"><div class="empty-state">
          <?php if (!$checklistFilterActive): ?>
            <span class="icon">🔍</span><p>Apply a <strong>date range</strong> or <strong>vehicle type</strong> filter to load checklist data.</p>
          <?php else: ?>
            <span class="icon">📭</span><p>No scheduled deliveries for this date range.</p>
          <?php endif; ?>
        </div></td></tr>
      <?php else: foreach ($displayData as $row):
          $refueled = (($row['Status'] ?? '') === 'REFUELED');
          $rowClass = $refueled ? 'row-refueled' : 'row-not-refueled';
          $dateVal  = $row['Date'] instanceof DateTime ? $row['Date']->format('Y-m-d') : htmlspecialchars($row['Date'] ?? '');
      ?>
        <tr class="<?= $rowClass ?>">
          <td class="right mono dim bold"><?= htmlspecialchars((string)($row['Day'] ?? '—')) ?></td>
          <td class="mono dim"><?= $dateVal ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['Fuel Time'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicle Type'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Driver'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Area'] ?? '—') ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['INV #'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? fmt($row['Liters']) . ' L' : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? peso($row['Amount']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= $refueled ? "<span class='badge badge-everyday'>✅ Refueled</span>" : "<span class='badge badge-norefuel'>❌ Not Refueled</span>" ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'fuel_monthly'): ?>
    <!-- ══ FUEL CONSUMPTION MONTHLY TAB (with Department column) ══ -->
    <?php
    $fcMonthLabel = $months[$fcMonth - 1] . ' ' . $fcYear;
    $prevM = $fcMonth - 1; $prevY = $fcYear; if ($prevM < 1)  { $prevM = 12; $prevY--; }
    $nextM = $fcMonth + 1; $nextY = $fcYear; if ($nextM > 12) { $nextM = 1;  $nextY++; }
    $wkColors    = ['fc-wk-1','fc-wk-2','fc-wk-3','fc-wk-4','fc-wk-5'];
    $wkSubColors = ['fc-wk-sub-1','fc-wk-sub-2','fc-wk-sub-3','fc-wk-sub-4','fc-wk-sub-5'];
    $fixedCols   = 3; // Dept + Plate + VType
    $totalCols   = $fixedCols + ($fcWeekCount * 2) + 2;
    ?>
    <div class="table-header">
      <div class="table-title" style="flex:1;min-width:0;">
        📆 Fuel Consumption — <?= htmlspecialchars($fcMonthLabel) ?>
        <span class="table-count"><?= count($data) ?> vehicles</span>
        <span class="table-count" style="background:#ede9fe;color:#6d28d9;border-color:#c4b5fd;"><?= $fcWeekCount ?> weeks</span>
      </div>
      <div class="table-actions" style="gap:.4rem;flex-wrap:wrap;">
        <button type="button" class="btn-export" onclick="fcExportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="fcExportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="fcPrint()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>

    <!-- Month navigator -->
    <div class="fc-nav" style="margin-bottom:.75rem;">
      <a href="<?= fcUrl($prevY, $prevM) ?>" class="fc-nav-arrow" title="Previous month">&#8249;</a>
      <form method="GET" style="display:contents;">
        <?php foreach ($_GET as $k => $v): if ($k === 'fc_year' || $k === 'fc_month' || $k === 'tab' || $k === 'mode') continue; ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="tab" value="fuel_monthly">
        <select name="fc_month" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);">
          <?php foreach ($months as $mi => $mn): ?><option value="<?= $mi+1 ?>" <?= ($mi+1===$fcMonth)?'selected':'' ?>><?= $mn ?></option><?php endforeach; ?>
        </select>
        <select name="fc_year" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);">
          <?php for ($y=(int)date('Y');$y>=2020;$y--): ?><option value="<?= $y ?>" <?= ($y===$fcYear)?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
        </select>
      </form>
      <span class="fc-nav-label"><?= htmlspecialchars($fcMonthLabel) ?></span>
      <a href="<?= fcUrl($nextY, $nextM) ?>" class="fc-nav-arrow" title="Next month">&#8250;</a>
      <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-left:auto;">
        <?php
        $wkRgb = ['139,92,246','59,130,246','16,185,129','245,158,11','239,68,68'];
        $wkHex = ['#6d28d9','#1d4ed8','#065f46','#92400e','#991b1b'];
        foreach ($fcWeeks as $wi => $wk): ?>
        <span style="font-size:.68rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:rgba(<?= $wkRgb[$wi] ?>,.12);color:<?= $wkHex[$wi] ?>;border:1px solid rgba(<?= $wkRgb[$wi] ?>,.3);">
          Wk<?= $wi+1 ?>: <?= htmlspecialchars($wk['label']) ?>
        </span>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (empty($data)): ?>
    <div class="empty-state" style="padding:3rem;">
      <span class="icon">📭</span><p>No fuel records found for <strong><?= htmlspecialchars($fcMonthLabel) ?></strong>.</p>
    </div>
    <?php else: ?>
    <div class="fc-table-wrap">
    <table id="fcTable">
    <thead>
      <tr>
        <th rowspan="3" style="min-width:90px;">Department</th>
        <th rowspan="3" style="min-width:90px;">Plate #</th>
        <th rowspan="3" style="min-width:80px;">Vehicle Type</th>
        <?php foreach ($fcWeeks as $wi => $wk): ?>
        <th colspan="2" class="<?= $wkColors[$wi] ?>">
          Week <?= $wi+1 ?> &nbsp;<span style="font-weight:400;opacity:.75;font-size:.65rem;"><?= htmlspecialchars($wk['label']) ?></span>
        </th>
        <?php endforeach; ?>
        <th colspan="2" style="background:rgba(99,102,241,.12);color:#4338ca;">Grand Total</th>
      </tr>
      <tr>
        <?php foreach ($fcWeeks as $wi => $wk): ?>
        <th colspan="2" class="<?= $wkColors[$wi] ?>" style="font-weight:600;font-size:.66rem;opacity:.85;">Fuel</th>
        <?php endforeach; ?>
        <th colspan="2" style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.66rem;">Total</th>
      </tr>
      <tr>
        <?php foreach ($fcWeeks as $wi => $wk): ?>
        <th class="<?= $wkColors[$wi] ?>" style="font-size:.65rem;">Liters</th>
        <th class="<?= $wkColors[$wi] ?>" style="font-size:.65rem;">Amount</th>
        <?php endforeach; ?>
        <th style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.65rem;">Liters</th>
        <th style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.65rem;">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // Group by Dept → VType for rendering
    $fcGrouped = [];
    foreach ($data as $row) {
        $dept = $row['Department'] ?? '—';
        $vt   = $row['Vehicletype'] ?? '—';
        $fcGrouped[$dept][$vt][] = $row;
    }

    // Dept color map for inline styles
    $deptColorMap = [
        'monde'      => ['bg' => 'rgba(239,68,68,.08)',  'color' => '#ef4444', 'border' => '#fca5a5'],
        'century'    => ['bg' => 'rgba(59,130,246,.08)', 'color' => '#3b82f6', 'border' => '#93c5fd'],
        'multilines' => ['bg' => 'rgba(234,179,8,.08)',  'color' => '#ca8a04', 'border' => '#fde047'],
        'nutriasia'  => ['bg' => 'rgba(16,185,129,.08)', 'color' => '#059669', 'border' => '#6ee7b7'],
    ];

    $grandLiters = 0; $grandAmount = 0;
    $grandWkL = array_fill(0, $fcWeekCount, 0);
    $grandWkA = array_fill(0, $fcWeekCount, 0);

    foreach ($fcGrouped as $dept => $vtypes):
        $deptKey  = strtolower(trim($dept));
        $deptC    = $deptColorMap[$deptKey] ?? ['bg' => 'rgba(107,114,128,.08)', 'color' => '#6b7280', 'border' => '#9ca3af'];
        $deptBg   = $deptC['bg'];
        $deptCol  = $deptC['color'];
        $deptBord = $deptC['border'];

        // Dept header row
        echo "<tr>";
        echo "<td colspan='" . $totalCols . "' style='background:{$deptBg};color:{$deptCol};font-weight:800;font-size:.75rem;letter-spacing:.06em;padding:.4rem .75rem;border-top:2px solid {$deptBord};border-bottom:1px solid {$deptBord};'>";
        echo "🏢 " . htmlspecialchars($dept);
        echo "</td></tr>\n";

        $deptLiters = 0; $deptAmount = 0;
        $deptWkL = array_fill(0, $fcWeekCount, 0);
        $deptWkA = array_fill(0, $fcWeekCount, 0);

        foreach ($vtypes as $vtype => $rows):
            // Check if any row has data
            $hasData = false;
            foreach ($rows as $r) {
                if ((float)($r['TotalLiters'] ?? 0) > 0 || (float)($r['TotalAmount'] ?? 0) > 0) { $hasData = true; break; }
            }
            if (!$hasData) continue;

            echo "<tr class='fc-vtype-row'><td></td><td colspan='" . ($totalCols - 1) . "'>🚛 " . htmlspecialchars($vtype) . "</td></tr>\n";

            $vtypeLiters = 0; $vtypeAmount = 0;
            $vtypeWkL = array_fill(0, $fcWeekCount, 0);
            $vtypeWkA = array_fill(0, $fcWeekCount, 0);

            foreach ($rows as $row):
                $pLiters = (float)($row['TotalLiters'] ?? 0);
                $pAmount = (float)($row['TotalAmount'] ?? 0);
                if ($pLiters == 0.0 && $pAmount == 0.0) continue;

                echo "<tr>";
                // Department badge cell (color-coded)
                echo "<td style='white-space:nowrap;background:{$deptBg};'>" . deptBadge($dept) . "</td>";
                echo "<td style='white-space:nowrap;'><span class='plate'>" . htmlspecialchars($row['PlateNumber']) . "</span></td>";
                echo "<td class='td-dim'>" . htmlspecialchars($vtype) . "</td>";

                for ($wi = 0; $wi < $fcWeekCount; $wi++):
                    $n  = $wi + 1;
                    $wL = isset($row["W{$n}Liters"])  ? (float)$row["W{$n}Liters"]  : 0.0;
                    $wA = isset($row["W{$n}Amount"])  ? (float)$row["W{$n}Amount"]  : 0.0;
                    $wR = isset($row["W{$n}Refuels"]) ? (int)  $row["W{$n}Refuels"] : 0;
                    $vtypeWkL[$wi] += $wL; $vtypeWkA[$wi] += $wA;
                    $sc = $wkSubColors[$wi];
                    if ($wR === 0) {
                        echo "<td class='td-center $sc' colspan='2'><span class='no-refuel'>⊘ No Refuel</span></td>";
                    } else {
                        echo "<td class='td-right $sc'>" . fmt($wL) . " L</td>";
                        echo "<td class='td-right $sc'>" . peso($wA) . "</td>";
                    }
                endfor;

                echo "<td class='td-right' style='font-weight:700;color:var(--teal,#0d9488);'>" . fmt($pLiters) . " L</td>";
                echo "<td class='td-right' style='font-weight:700;'>" . peso($pAmount) . "</td>";
                echo "</tr>\n";
                $vtypeLiters += $pLiters; $vtypeAmount += $pAmount;
            endforeach;

            // VType subtotal
            echo "<tr class='fc-vtype-sub'>";
            echo "<td style='background:{$deptBg};'>" . deptBadge($dept) . "</td>";
            echo "<td colspan='1' style='color:#1e40af;padding-left:.75rem;'>🚛 Subtotal — " . htmlspecialchars($vtype) . "</td><td></td>";
            for ($wi = 0; $wi < $fcWeekCount; $wi++):
                $sc = $wkSubColors[$wi];
                echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>" . fmt($vtypeWkL[$wi]) . " L</td>";
                echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>" . peso($vtypeWkA[$wi]) . "</td>";
                $deptWkL[$wi] += $vtypeWkL[$wi]; $deptWkA[$wi] += $vtypeWkA[$wi];
            endfor;
            echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>" . fmt($vtypeLiters) . " L</td>";
            echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>" . peso($vtypeAmount) . "</td>";
            echo "</tr>\n";
            $deptLiters += $vtypeLiters; $deptAmount += $vtypeAmount;
        endforeach;

        // Dept subtotal row
        echo "<tr style='background:{$deptBg};border-top:2px solid {$deptBord};'>";
        echo "<td colspan='3' style='color:{$deptCol};font-weight:800;font-size:.77rem;padding:.4rem .75rem;'>🏢 " . htmlspecialchars($dept) . " Total</td>";
        for ($wi = 0; $wi < $fcWeekCount; $wi++):
            $sc = $wkSubColors[$wi];
            echo "<td class='td-right $sc' style='color:{$deptCol};font-weight:800;'>" . fmt($deptWkL[$wi]) . " L</td>";
            echo "<td class='td-right $sc' style='color:{$deptCol};font-weight:800;'>" . peso($deptWkA[$wi]) . "</td>";
            $grandWkL[$wi] += $deptWkL[$wi]; $grandWkA[$wi] += $deptWkA[$wi];
        endfor;
        echo "<td class='td-right' style='color:{$deptCol};font-weight:800;font-size:.82rem;'>" . fmt($deptLiters) . " L</td>";
        echo "<td class='td-right' style='color:{$deptCol};font-weight:800;font-size:.82rem;'>" . peso($deptAmount) . "</td>";
        echo "</tr>\n";
        $grandLiters += $deptLiters; $grandAmount += $deptAmount;
    endforeach;

    // Grand total
    echo "<tr class='fc-grand-total'>";
    echo "<td colspan='3' style='padding-left:.75rem;'>🏁 Grand Total</td>";
    for ($wi = 0; $wi < $fcWeekCount; $wi++):
        $sc = $wkSubColors[$wi];
        echo "<td class='td-right $sc' style='color:#3730a3;font-weight:800;'>" . fmt($grandWkL[$wi]) . " L</td>";
        echo "<td class='td-right $sc' style='color:#3730a3;font-weight:800;'>" . peso($grandWkA[$wi]) . "</td>";
    endfor;
    echo "<td class='td-right' style='color:#3730a3;font-weight:800;font-size:.85rem;'>" . fmt($grandLiters) . " L</td>";
    echo "<td class='td-right' style='color:#3730a3;font-weight:800;font-size:.85rem;'>" . peso($grandAmount) . "</td>";
    echo "</tr>\n";
    ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'report'): ?>
    <div class="table-header">
      <div class="table-title">📋 Usage Report
        <span class="table-count"><?= number_format($totalRows) ?> records</span>
        <?php if ($anyFilterApplied): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
      </div>
      <div class="table-actions">
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search plate, driver, area..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
    <table id="mainTable">
      <thead><tr>
        <th onclick="sortTable(0)">Plate # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)">Department <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(2)">Vehicle Type <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)">Fuel Date <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)">Fuel Time <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Price/Liter <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)">Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)">Driver <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)">INV # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)">Supplier <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="12"><div class="empty-state"><span class="icon">📭</span><p>No records found. Try adjusting the filters.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicle Type'] ?? '—') ?></td>
          <td class="mono dim"><?= $row['Fuel Date'] instanceof DateTime ? $row['Fuel Date']->format('Y-m-d') : htmlspecialchars($row['Fuel Date'] ?? '—') ?></td>
          <td class="mono dim"><?= htmlspecialchars($row['Fuel Time'] ?? '—') ?></td>
          <td class="right mono bold" style="color:var(--teal)"><?= $row['Liters'] !== null ? fmt($row['Liters']) . ' L' : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono dim"><?= $row['Price/Liter'] !== null ? '₱' . number_format((float)$row['Price/Liter'], 2) : '—' ?></td>
          <td class="right mono bold"><?= $row['Amount'] !== null ? peso($row['Amount']) : '—' ?></td>
          <td class="dim"><?= htmlspecialchars($row['Area']     ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Driver']   ?? '—') ?></td>
          <td class="mono dim"><?= htmlspecialchars($row['INV #']   ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Supplier'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php endif; ?>

    <!-- ══ PAGINATION ══ -->
    <?php if ($totalPages > 1 && $tab !== 'fuel_monthly'): ?>
    <div class="pagination-bar">
      <span class="pagination-info">
        Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> rows
        · Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
      </span>
      <div class="pagination-btns">
        <?php if ($prevUrl): ?><a href="<?= htmlspecialchars($prevUrl) ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Previous</a>
        <?php else: ?><span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Previous</span><?php endif; ?>
        <?php if ($nextUrl): ?><a href="<?= htmlspecialchars($nextUrl) ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
        <?php else: ?><span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  <div class="footer">
    Fuel Dashboard · Tradewell Fleet Monitoring System · All Vehicles
    · Generated <?= date('Y-m-d H:i:s') ?>
  </div>
</div>

<!-- ══ MODALS ══ -->
<div id="areasModal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);" onclick="closeAreas()"></div>
  <div style="position:relative;background:var(--surface);border:1.5px solid var(--border);border-radius:16px;padding:1.5rem;min-width:280px;max-width:420px;width:90%;box-shadow:0 16px 48px rgba(0,0,0,.3);animation:modalIn .2s ease;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <div>
        <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">All Areas</div>
        <div id="areasModalPlate" style="font-weight:800;font-size:1rem;color:var(--text-primary);"></div>
      </div>
      <button onclick="closeAreas()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.2rem;padding:.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="areasModalList" style="display:flex;flex-wrap:wrap;gap:.4rem;"></div>
  </div>
</div>

<div id="refPopover" style="display:none;position:fixed;z-index:10000;max-width:340px;width:max-content;">
  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.85rem 1rem;box-shadow:0 8px 32px rgba(0,0,0,.18);font-size:.78rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
      <span style="font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Reference Baselines</span>
      <button onclick="closeRefPop()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:.1rem .3rem;line-height:1;">✕</button>
    </div>
    <div id="refPopLines" style="display:flex;flex-direction:column;gap:.45rem;color:var(--text-secondary);line-height:1.5;"></div>
  </div>
</div>

<style>
.filter-group { display:flex;flex-direction:column;gap:.2rem; }
@keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)} }
.btn-areas { display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3);cursor:pointer;transition:background .15s;font-family:inherit;white-space:nowrap; }
.btn-areas:hover { background:rgba(59,130,246,.22); }
.area-chip { display:inline-block;padding:.25rem .65rem;background:var(--hover);border:1px solid var(--border);border-radius:20px;font-size:.78rem;font-weight:600;color:var(--text-secondary); }
</style>

<script>
// ══ Plate Combobox (AJAX) ══════════════════════════════════════
(function () {
    const input    = document.getElementById('plateComboInput');
    const dropdown = document.getElementById('plateComboDropdown');
    const vtypeSel = document.getElementById('vtypeSelect');
    if (!input) return;

    let allPlates   = [];
    let loadedFor   = null;
    let fetchTimer  = null;
    let activeIdx   = -1;

    // Initial plates from PHP (for current vtype)
    const initPlates = <?php
        echo json_encode(array_column($plateList, 'PlateNumber'), JSON_UNESCAPED_UNICODE);
    ?>;
    allPlates = initPlates;
    loadedFor = <?php echo json_encode($selVtype); ?>;

    function renderDropdown(plates, query) {
        if (plates.length === 0) {
            dropdown.innerHTML = '<div class="plate-combo-empty">No plates found</div>';
        } else {
            const q = query.toUpperCase();
            dropdown.innerHTML = plates
                .filter(p => p.toUpperCase().includes(q))
                .slice(0, 60)
                .map((p, i) => {
                    const hi = q ? p.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'), '<mark>$1</mark>') : p;
                    return `<div class="plate-combo-item" data-val="${p}" data-idx="${i}">${hi}</div>`;
                }).join('');
            if (dropdown.querySelectorAll('.plate-combo-item').length === 0) {
                dropdown.innerHTML = '<div class="plate-combo-empty">No matching plates</div>';
            }
        }
        dropdown.querySelectorAll('.plate-combo-item').forEach(item => {
            item.addEventListener('mousedown', e => {
                e.preventDefault();
                input.value = item.dataset.val;
                closeDropdown();
            });
        });
        activeIdx = -1;
    }

    function openDropdown() {
        renderDropdown(allPlates, input.value);
        dropdown.classList.add('open');
    }
    function closeDropdown() { dropdown.classList.remove('open'); activeIdx = -1; }

    async function loadPlates(vtype) {
        if (loadedFor === vtype) return;
        dropdown.innerHTML = '<div class="plate-combo-spinner">⏳ Loading…</div>';
        dropdown.classList.add('open');
        input.classList.add('loading');
        try {
            const url = `?ajax=plates${vtype ? '&vtype=' + encodeURIComponent(vtype) : ''}`;
            const res = await fetch(url);
            allPlates = await res.json();
            loadedFor = vtype;
        } catch(e) { allPlates = []; }
        input.classList.remove('loading');
        renderDropdown(allPlates, input.value);
    }

    input.addEventListener('focus', () => openDropdown());
    input.addEventListener('input', () => renderDropdown(allPlates, input.value));
    input.addEventListener('blur',  () => setTimeout(closeDropdown, 150));

    input.addEventListener('keydown', e => {
        const items = dropdown.querySelectorAll('.plate-combo-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1);
            items.forEach((it, i) => it.classList.toggle('highlighted', i === activeIdx));
            if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0);
            items.forEach((it, i) => it.classList.toggle('highlighted', i === activeIdx));
        } else if (e.key === 'Enter') {
            if (activeIdx >= 0 && items[activeIdx]) {
                input.value = items[activeIdx].dataset.val;
                closeDropdown(); e.preventDefault();
            }
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    window.onVtypeChange = function(vtype) {
        input.value = '';
        loadPlates(vtype);
    };
})();

// ══ Export helpers ═══════════════════════════════════════════
const _allData = <?php
    $exportRows = array_map(function ($row) {
        $out = [];
        foreach ($row as $k => $v) { $out[$k] = ($v instanceof DateTime) ? $v->format('Y-m-d') : $v; }
        return $out;
    }, $data);
    echo json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>;

const _fcExportData = <?php
    if ($tab === 'fuel_monthly' && !empty($data)) {
        $fcExport = [];
        foreach ($data as $row) {
            $entry = [
                'Department'  => $row['Department'] ?? '',
                'Plate'       => $row['PlateNumber'],
                'VehicleType' => $row['Vehicletype'] ?? '',
                'TotalRefuels'=> $row['TotalRefuels'] ?? 0,
                'TotalLiters' => $row['TotalLiters']  ?? 0,
                'TotalAmount' => $row['TotalAmount']  ?? 0,
            ];
            for ($wi = 0; $wi < $fcWeekCount; $wi++) {
                $n = $wi + 1; $wLabel = $fcWeeks[$wi]['label'];
                $entry["Wk{$n} ({$wLabel}) Liters"]  = $row["W{$n}Liters"]  ?? 0;
                $entry["Wk{$n} ({$wLabel}) Amount"]  = $row["W{$n}Amount"]  ?? 0;
                $entry["Wk{$n} ({$wLabel}) Refuels"] = $row["W{$n}Refuels"] ?? 0;
            }
            $fcExport[] = $entry;
        }
        echo json_encode($fcExport, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    } else { echo '[]'; }
?>;

const _tabName   = <?php echo json_encode($tab, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const _isFcMonthly = (_tabName === 'fuel_monthly');

function _getExportData() {
    const src = _allData;
    if (!src || src.length === 0) return null;
    if (src.length > 5000) {
        if (!confirm(`Export ${src.length.toLocaleString()} rows? This may be slow.`)) return null;
    }
    return src;
}

function _buildFilename(ext) {
    const p = new URLSearchParams(window.location.search);
    const parts = ['Fuel', _tabName.replace('_',' ')];
    const dFrom = p.get('date_from') || ''; const dTo = p.get('date_to') || '';
    const dept  = p.get('dept')  || ''; const vtype = p.get('vtype') || '';
    const plate = p.get('plate') || ''; const driver = p.get('driver') || '';
    if (dFrom) parts.push(dFrom + (dTo ? '_to_' + dTo : ''));
    if (dept)  parts.push(dept);
    if (vtype) parts.push(vtype);
    if (plate) parts.push(plate);
    if (driver) parts.push(driver);
    return parts.join('_').replace(/[^a-zA-Z0-9_\-]/g, '_') + '.' + ext;
}

function filterTable(value) {
    value = value.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
    });
}

let sortDir = {};
function sortTable(col) {
    const table = document.getElementById('mainTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    const ths   = table.querySelectorAll('thead th');
    sortDir[col] = !sortDir[col]; const asc = sortDir[col];
    ths.forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const icon = th.querySelector('.sort-icon');
        if (icon) icon.textContent = (i === col) ? (asc ? '↑' : '↓') : '⇅';
    });
    rows.sort((a, b) => {
        const aT = a.cells[col]?.textContent.trim() ?? '';
        const bT = b.cells[col]?.textContent.trim() ?? '';
        const aN = parseFloat(aT.replace(/[₱,L% +]/g, ''));
        const bN = parseFloat(bT.replace(/[₱,L% +]/g, ''));
        if (!isNaN(aN) && !isNaN(bN)) return asc ? aN - bN : bN - aN;
        return asc ? aT.localeCompare(bT) : bT.localeCompare(aT);
    });
    rows.forEach(r => tbody.appendChild(r));
}

document.querySelectorAll('.progress-fill').forEach(el => {
    const w = el.style.width; el.style.width = '0';
    setTimeout(() => { el.style.width = w; }, 80);
});

// ── Generic CSV export ──
function exportCSV() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to export.'); return; }
    const headers = Object.keys(rows[0]);
    const lines = [headers.map(h => '"' + h + '"').join(',')];
    rows.forEach(row => lines.push(
        headers.map(h => '"' + String(row[h] ?? '').replace(/"/g,'""') + '"').join(',')
    ));
    const url = URL.createObjectURL(new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' }));
    const a = document.createElement('a'); a.href = url; a.download = _buildFilename('csv');
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

// ── Generic Excel export ──
function exportExcel() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to export.'); return; }
    const headers = Object.keys(rows[0]);
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let xml = '<?xml version="1.0"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n<Worksheet ss:Name="Fuel Dashboard"><Table>\n';
    xml += '<Row>' + headers.map(h => `<Cell><Data ss:Type="String">${esc(h)}</Data></Cell>`).join('') + '</Row>\n';
    rows.forEach(row => {
        xml += '<Row>' + headers.map(h => {
            const raw = row[h] ?? '';
            const num = parseFloat(String(raw).replace(/[₱,\s]/g,''));
            return (!isNaN(num) && isFinite(num) && /^[₱\s]*[\d,]+(\.\d+)?[\sL%]*$/.test(String(raw).trim()))
                ? `<Cell><Data ss:Type="Number">${num}</Data></Cell>`
                : `<Cell><Data ss:Type="String">${esc(raw)}</Data></Cell>`;
        }).join('') + '</Row>\n';
    });
    xml += '</Table></Worksheet></Workbook>';
    const url = URL.createObjectURL(new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' }));
    const a = document.createElement('a'); a.href = url; a.download = _buildFilename('xls');
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

// ── Generic Print ──
function printTable() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to print.'); return; }
    const title = document.querySelector('.table-title')?.innerText?.replace(/[⇅↑↓]/g,'').trim() || 'Report';
    const p = new URLSearchParams(window.location.search);
    const filters = [];
    const dFrom = p.get('date_from'), dTo = p.get('date_to');
    const dept = p.get('dept') || '', vtype = p.get('vtype') || '';
    const plate = p.get('plate') || '', driver = p.get('driver') || '';
    if (dFrom || dTo) filters.push('Date: ' + (dFrom||'—') + ' → ' + (dTo||'—'));
    if (dept)   filters.push('Dept: ' + dept);
    if (vtype)  filters.push('Type: ' + vtype);
    if (plate)  filters.push('Plate: ' + plate);
    if (driver) filters.push('Driver: ' + driver);
    const headers = Object.keys(rows[0]);
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const headerHtml = headers.map(h =>
        `<th style="background:#1e40af;color:#fff;padding:5px 8px;font-size:10px;text-align:left;border:1px solid #1e3a8a;white-space:nowrap;">${esc(h)}</th>`
    ).join('');
    const bodyHtml = rows.map(row =>
        '<tr>' + headers.map(h =>
            `<td style="padding:4px 8px;border:1px solid #e2e8f0;font-size:10px;vertical-align:middle;">${esc(row[h] ?? '—')}</td>`
        ).join('') + '</tr>'
    ).join('');
    const w = window.open('', '_blank', 'width=1300,height=900');
    w.document.write(`<!DOCTYPE html><html><head><title>${esc(title)}</title>
    <style>
      body{font-family:Arial,sans-serif;padding:16px;color:#0f172a;}
      h2{margin:0 0 2px;font-size:14px;color:#1e40af;} p{margin:0 0 10px;font-size:9px;color:#64748b;}
      table{border-collapse:collapse;width:100%;} tbody tr:nth-child(even) td{background:#f8fafc;}
      @media print{body{padding:6px;}}
    </style></head><body>
    <h2>Fuel Monitoring Dashboard · Tradewell</h2>
    <p>${esc(title)}${filters.length?' · '+filters.join(' · '):''} · ${rows.length.toLocaleString()} records · ${new Date().toLocaleString()}</p>
    <table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>
    </body></html>`);
    w.document.close(); setTimeout(() => w.print(), 400);
}

// ── Fuel Monthly CSV ──
function fcExportCSV() {
    if (!_fcExportData.length) { alert('No data to export.'); return; }
    const headers = Object.keys(_fcExportData[0]);
    const lines = [headers.map(h => '"' + h + '"').join(',')];
    _fcExportData.forEach(r => lines.push(
        headers.map(h => '"' + String(r[h] ?? '').replace(/"/g,'""') + '"').join(',')
    ));
    const url = URL.createObjectURL(new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' }));
    const a = document.createElement('a'); a.href = url;
    a.download = 'Fuel_Consumption_<?= $months[$fcMonth-1] ?>_<?= $fcYear ?>.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

// ── Fuel Monthly Excel ──
function fcExportExcel() {
    if (!_fcExportData.length) { alert('No data to export.'); return; }
    const headers = Object.keys(_fcExportData[0]);
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let xml = '<?xml version="1.0"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n<Worksheet ss:Name="Fuel Monthly"><Table>\n';
    xml += '<Row>' + headers.map(h => `<Cell><Data ss:Type="String">${esc(h)}</Data></Cell>`).join('') + '</Row>\n';
    _fcExportData.forEach(r => {
        xml += '<Row>' + headers.map(h => {
            const raw = r[h] ?? '';
            return (typeof raw === 'number')
                ? `<Cell><Data ss:Type="Number">${raw}</Data></Cell>`
                : `<Cell><Data ss:Type="String">${esc(raw)}</Data></Cell>`;
        }).join('') + '</Row>\n';
    });
    xml += '</Table></Worksheet></Workbook>';
    const url = URL.createObjectURL(new Blob([xml], { type: 'application/vnd.ms-excel;charset=utf-8;' }));
    const a = document.createElement('a'); a.href = url;
    a.download = 'Fuel_Consumption_<?= $months[$fcMonth-1] ?>_<?= $fcYear ?>.xls';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

// ── Fuel Monthly Print ──
function fcPrint() {
    if (!_fcExportData.length) { alert('No data to print.'); return; }
    const title = 'Fuel Consumption — <?= $months[$fcMonth-1] ?> <?= $fcYear ?>';
    const headers = Object.keys(_fcExportData[0]);
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // Dept color map for print (text-only, no bg for print compat)
    const deptPrintColor = { monde:'#ef4444', century:'#3b82f6', multilines:'#ca8a04', nutriasia:'#059669' };

    const headerHtml = headers.map(h =>
        `<th style="background:#1e40af;color:#fff;padding:4px 6px;font-size:9px;border:1px solid #1e3a8a;white-space:nowrap;">${esc(h)}</th>`
    ).join('');

    const bodyHtml = _fcExportData.map(r => {
        const deptRaw = String(r['Department'] || '').toLowerCase();
        const dCol = deptPrintColor[deptRaw] || '#6b7280';
        return '<tr>' + headers.map((h, i) => {
            const v = r[h] ?? '—';
            const style = (i === 0) ? `color:${dCol};font-weight:700;` : '';
            return `<td style="padding:3px 6px;border:1px solid #e2e8f0;font-size:9px;${style}">${esc(v)}</td>`;
        }).join('') + '</tr>';
    }).join('');

    const w = window.open('', '_blank', 'width=1500,height=900');
    w.document.write(`<!DOCTYPE html><html><head><title>${esc(title)}</title>
    <style>
      body{font-family:Arial,sans-serif;padding:12px;}
      h2{font-size:13px;color:#1e40af;margin:0 0 3px;}
      p{font-size:9px;color:#64748b;margin:0 0 8px;}
      table{border-collapse:collapse;width:100%;}
      tbody tr:nth-child(even) td{background:#f8fafc;}
      @media print{body{padding:4px;}}
    </style></head><body>
    <h2>Tradewell Fleet · ${esc(title)}</h2>
    <p>Generated ${new Date().toLocaleString()} · ${_fcExportData.length} vehicles</p>
    <table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>
    </body></html>`);
    w.document.close(); setTimeout(() => w.print(), 400);
}

// ── Modals ──
function showAreas(plate, areas) {
    document.getElementById('areasModalPlate').textContent = plate;
    const list = document.getElementById('areasModalList');
    list.innerHTML = areas.split(', ').filter(a => a.trim()).map(a =>
        `<span class="area-chip"><i class="bi bi-geo-alt"></i> ${a.trim()}</span>`
    ).join('');
    document.getElementById('areasModal').style.display = 'flex';
}
function closeAreas() { document.getElementById('areasModal').style.display = 'none'; }

(function () {
    const pop = document.getElementById('refPopover');
    document.body.appendChild(pop);
    let currentBtn = null;
    window.showRefPop = function (btn) {
        if (currentBtn === btn && pop.style.display !== 'none') { closeRefPop(); return; }
        currentBtn = btn;
        const raw = btn.getAttribute('data-ref') || '';
        const lines = raw.split('\n').filter(l => l.trim());
        document.getElementById('refPopLines').innerHTML = lines.map(l => {
            const isSelf = l.includes('◀ this'); const isSep = l.startsWith('—');
            if (isSep) return `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);padding:.25rem 0 .1rem;">${l.replace(/—/g,'').trim()}</div>`;
            const bg = isSelf ? 'background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);' : 'background:var(--hover);border:1px solid transparent;';
            return `<div style="padding:.3rem .5rem;border-radius:7px;font-size:.76rem;font-family:'DM Mono',monospace;${bg}${isSelf?'font-weight:700;':''}">${l.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
        }).join('');
        pop.style.display = 'block';
        const r = btn.getBoundingClientRect(), pw = pop.offsetWidth, ph = pop.offsetHeight, m = 8;
        let left = r.left, top = r.top - ph - 6;
        if (top < m) top = r.bottom + 6;
        if (left + pw > window.innerWidth - m) left = r.right - pw;
        if (left < m) left = m;
        pop.style.left = left + 'px'; pop.style.top = top + 'px';
    };
    window.closeRefPop = function () { pop.style.display = 'none'; currentBtn = null; };
    document.addEventListener('click', e => {
        if (!pop.contains(e.target) && !e.target.closest('.trig-badge')) closeRefPop();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeAreas(); closeRefPop(); }
    });
})();
</script>
</body>
</html>
<?php sqlsrv_close($conn); ?>