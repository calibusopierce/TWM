<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);

// --- ACTIVE TAB ---
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';

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

// --- DEPARTMENT FILTER ---
$selDept = $_SESSION['Department'] ?? '';
$deptActive = ($selDept !== '');
$deptWhere  = $deptActive ? "AND v.Department = '$selDept'" : '';
$deptWhereF = $deptActive ? "AND ts.Department = '$selDept'" : '';

$deptColors = [
    'Monde'      => ['bg'=>'rgba(239,68,68,.15)',  'color'=>'#ef4444', 'border'=>'#fca5a5'],
    'Century'    => ['bg'=>'rgba(59,130,246,.15)', 'color'=>'#3b82f6', 'border'=>'#93c5fd'],
    'Multilines' => ['bg'=>'rgba(234,179,8,.15)',  'color'=>'#ca8a04', 'border'=>'#fde047'],
    'NutriAsia'  => ['bg'=>'rgba(16,185,129,.15)', 'color'=>'#059669', 'border'=>'#6ee7b7'],
    ''           => ['bg'=>'rgba(107,114,128,.15)','color'=>'#6b7280', 'border'=>'#9ca3af'],
];
function deptStyle(string $dept, array $deptColors): string {
    $c = $deptColors[$dept] ?? $deptColors[''];
    return "background:{$c['bg']};color:{$c['color']};border-color:{$c['border']};";
}

// --- VEHICLE TYPE FILTER ---
$selVtype = isset($_GET['vtype']) && $_GET['vtype'] !== '' ? trim($_GET['vtype']) : '';
$vtypeActive = ($selVtype !== '');
$vtypeWhere  = $vtypeActive ? "AND v.Vehicletype = '$selVtype'" : '';
$vtypeWhereF = $vtypeActive 
    ? "AND v.Vehicletype = '$selVtype'" 
    : '';

// --- PLATE FILTER ---
$selPlate = isset($_GET['plate']) && $_GET['plate'] !== '' ? trim($_GET['plate']) : '';
$plateActive = ($selPlate !== '');
$plateWhereF = $plateActive ? "AND ts.PlateNumber LIKE '%".str_replace("'","''",$selPlate)."%'" : '';
$plateWhereR = $plateActive ? "AND f.PlateNumber LIKE '%".str_replace("'","''",$selPlate)."%'" : '';

// --- DRIVER FILTER ---
$selDriver = isset($_GET['driver']) && $_GET['driver'] !== '' ? trim($_GET['driver']) : '';
$driverActive = ($selDriver !== '');
$driverWhereF = $driverActive ? "AND EXISTS (SELECT 1 FROM [dbo].[teamschedule] td2 WHERE td2.PlateNumber = ts.PlateNumber AND td2.ScheduleDate = ts.ScheduleDate AND td2.Position LIKE '%DRIVER%' AND td2.Employee_Name LIKE '%".str_replace("'","''",$selDriver)."%')" : '';
$driverWhereR = $driverActive ? "AND f.Requested LIKE '%".str_replace("'","''",$selDriver)."%'" : '';

// --- AREA FILTER ---
$selArea = isset($_GET['area']) && $_GET['area'] !== '' ? trim($_GET['area']) : '';
//$areaActive = ($selArea !== '');
//$areaWhereF = $areaActive ? "AND ts.Area LIKE '%".str_replace("'","''",$selArea)."%'" : '';
//$areaWhereR = $areaActive ? "AND f.Area LIKE '%".str_replace("'","''",$selArea)."%'" : '';

$anyFilter  = $dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive;

$checklistFrom = $anyFilter ? $baseFrom : date('Y-m-d');
$checklistTo   = $anyFilter ? $baseTo   : date('Y-m-d');
$checklistFilterActive = $dateActive || $vtypeActive || $plateActive || $driverActive;

// --- MONTH/YEAR PARAMS ---
$selYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$selMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear  = max(2025, min((int)date('Y'), $selYear));
$selMonth = max(1, min(12, $selMonth));

// ============================================================
// FUEL MONTHLY TAB — Month/Year params + week helper
// ============================================================
$fcYear  = isset($_GET['fc_year'])  ? max(2020, min((int)date('Y'), (int)$_GET['fc_year']))  : (int)date('Y');
$fcMonth = isset($_GET['fc_month']) ? max(1,    min(12,              (int)$_GET['fc_month'])) : (int)date('m');

function getMonthWeeks(int $year, int $month): array {
    $firstDay = mktime(0,0,0,$month,1,$year);
    $lastDay  = mktime(0,0,0,$month+1,0,$year);
    $daysInMonth = (int)date('t', $firstDay);
    
    // Always exactly 4 weeks, split days evenly
    $baseSize = intdiv($daysInMonth, 4);
    $remainder = $daysInMonth % 4;
    
    $weeks = [];
    $dayStart = 1;
    for ($i = 0; $i < 4; $i++) {
        // Distribute remainder days to first weeks
        $size    = $baseSize + ($i < $remainder ? 1 : 0);
        $dayEnd  = $dayStart + $size - 1;
        $weeks[] = [
            'sql_from' => date('Y-m-d', mktime(0,0,0,$month,$dayStart,$year)),
            'sql_to'   => date('Y-m-d', mktime(0,0,0,$month,$dayEnd,  $year)),
            'label'    => date('M j',   mktime(0,0,0,$month,$dayStart,$year))
                        . ' – '
                        . date('j',     mktime(0,0,0,$month,$dayEnd,  $year)),
        ];
        $dayStart = $dayEnd + 1;
    }
    return $weeks;
}

$fcWeeks     = getMonthWeeks($fcYear, $fcMonth);
$fcWeekCount = count($fcWeeks);

// Build per-week CASE expressions
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
$fcMonthEnd   = date('Y-m-t', mktime(0,0,0,$fcMonth,1,$fcYear));

$q_fuel_monthly = "
SELECT
    ts.PlateNumber,
    ts.Department,
    v.Vehicletype,
    COUNT(DISTINCT f.FuelID)          AS TotalRefuels,
    ROUND(SUM(f.Liters),2)            AS TotalLiters,
    ROUND(SUM(f.Amount),2)            AS TotalAmount,
    $weekCases
FROM [dbo].[TruckSchedule] ts
LEFT JOIN [dbo].[Tbl_fuel] f
    ON  f.PlateNumber  = ts.PlateNumber
    AND f.Fueldate     = ts.ScheduleDate
    AND f.Fueldate     BETWEEN '$fcMonthStart' AND '$fcMonthEnd'
LEFT JOIN (
    -- Only grab one Vehicle record per plate, prioritize non-null Vehicletype
    SELECT PlateNumber, Vehicletype, Department
    FROM (
        SELECT PlateNumber, Vehicletype, Department,
               ROW_NUMBER() OVER (
                   PARTITION BY PlateNumber 
                   ORDER BY 
                       CASE WHEN Vehicletype IS NOT NULL 
                            AND Vehicletype NOT IN ('[select]','WH','TRUCKING') 
                            THEN 0 ELSE 1 END,
                       CASE WHEN Active = 1 THEN 0 ELSE 1 END
               ) AS rn
        FROM [dbo].[Vehicle]
    ) ranked
    WHERE rn = 1
) v ON v.PlateNumber = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$fcMonthStart' AND '$fcMonthEnd'
  AND ts.PlateNumber IS NOT NULL 
  AND ts.PlateNumber <> ''
  AND v.Vehicletype IN ('CANTER','ELF','FIGHTER','FORWARD','L300','CAR','MOTOR','CROSS WIND','VAN')
  $deptWhereF
  $vtypeWhereF
  $driverWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
ORDER BY v.Vehicletype, ts.PlateNumber";

// ============================================================
// HELPER: RUN QUERY
// ============================================================
function runQuery($conn, $sql, $params = []) {
    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function fmt($n, $dec = 2) {
    return $n !== null ? number_format((float)$n, $dec) : '—';
}
function peso($n) {
    return $n !== null ? '₱' . number_format((float)$n, 2) : '—';
}

// ============================================================
// QUERY 1 — Overall Summary
// ============================================================
$q_summary = "
SELECT
    ts.PlateNumber,
    ts.Department,
    v.Vehicletype,
    v.FuelType,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    MAX(ts.ScheduleDate)          AS LastRefuelDate,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = ts.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC)    AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = ts.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[TruckSchedule] ts
LEFT JOIN [dbo].[Tbl_fuel] f
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v
    ON  v.PlateNumber   = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  AND (v.Active = 1 OR v.Active IS NULL)
  $deptWhereF
  $vtypeWhereF
  $plateWhereF
  $driverWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype, v.FuelType
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 2/3 — Ranked
// ============================================================
$q_ranked_asc = "
SELECT
    RANK() OVER (ORDER BY SUM(f.Liters) ASC) AS Rank,
    ts.PlateNumber,
    ts.Department,
    v.Vehicletype,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = ts.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC)    AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = ts.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[TruckSchedule] ts
INNER JOIN [dbo].[Tbl_fuel] f
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v
    ON  v.PlateNumber   = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  AND (v.Active = 1 OR v.Active IS NULL)
  $deptWhereF
  $vtypeWhereF
  $plateWhereF
  $driverWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
ORDER BY TotalLiters ASC";

$q_ranked_desc = "
SELECT
    RANK() OVER (ORDER BY SUM(f.Liters) DESC) AS Rank,
    ts.PlateNumber,
    ts.Department,
    v.Vehicletype,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3
     WHERE ts3.PlateNumber = ts.PlateNumber
       AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
     ORDER BY ts3.ScheduleDate DESC)    AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area
           FROM [dbo].[TruckSchedule] ts4
           WHERE ts4.PlateNumber = ts.PlateNumber
             AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
             AND ts4.Area IS NOT NULL AND ts4.Area <> ''
           FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[TruckSchedule] ts
INNER JOIN [dbo].[Tbl_fuel] f
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v
    ON  v.PlateNumber   = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  AND (v.Active = 1 OR v.Active IS NULL)
  $deptWhereF
  $vtypeWhereF
  $plateWhereF
  $driverWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 4 — 30-Day Summary
// ============================================================
if ($dateActive) {
    $q_30day = "
    DECLARE @S DATE = '$baseFrom';
    DECLARE @E DATE = '$baseTo';
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT ts.PlateNumber,
            COUNT(DISTINCT ts.ScheduleDate)  AS DaysRefueled,
            COUNT(f.FuelID)                  AS TotalRefuels,
            ROUND(SUM(f.Liters),2)           AS TotalLiters,
            ROUND(AVG(f.Liters),2)           AS AvgLiters,
            ROUND(SUM(f.Amount),2)           AS TotalAmount,
            ROUND(AVG(f.Amount),2)           AS AvgAmount
        FROM [dbo].[TruckSchedule] ts
        LEFT JOIN [dbo].[Tbl_fuel] f
            ON  ts.PlateNumber  = f.PlateNumber
            AND ts.ScheduleDate = f.Fueldate
        WHERE ts.ScheduleDate BETWEEN @S AND @E
          $deptWhereF
          $vtypeWhereF
          $plateWhereF
          $driverWhereF
        GROUP BY ts.PlateNumber
    )
    SELECT ts2.PlateNumber, ts2.Department, v.Vehicletype,
        ISNULL(ra.DaysRefueled,0)           AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0)     AS DaysNotRefueled,
        ISNULL(ra.TotalRefuels,0)           AS TotalRefuels,
        ISNULL(ra.TotalLiters,0)            AS TotalLiters,
        ISNULL(ra.AvgLiters,0)              AS AvgLiters,
        ISNULL(ra.TotalAmount,0)            AS TotalAmount,
        ISNULL(ra.AvgAmount,0)              AS AvgAmount,
        (SELECT TOP 1 tla.Area FROM [dbo].[TruckSchedule] tla
         WHERE tla.PlateNumber = ts2.PlateNumber
           AND tla.ScheduleDate BETWEEN @S AND @E
         ORDER BY tla.ScheduleDate DESC)    AS LatestArea,
        STUFF((SELECT DISTINCT ', ' + taa.Area
               FROM [dbo].[TruckSchedule] taa
               WHERE taa.PlateNumber = ts2.PlateNumber
                 AND taa.ScheduleDate BETWEEN @S AND @E
                 AND taa.Area IS NOT NULL AND taa.Area <> ''
               FOR XML PATH('')), 1, 2, '') AS AllAreas
    FROM (SELECT DISTINCT PlateNumber, Department FROM [dbo].[TruckSchedule] WHERE ScheduleDate BETWEEN @S AND @E) ts2
    LEFT JOIN RA ra ON ra.PlateNumber = ts2.PlateNumber
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts2.PlateNumber
    ORDER BY ra.TotalLiters DESC";
} else {
    $q_30day = "
    DECLARE @S DATE = DATEADD(DAY,-29,CAST(GETDATE() AS DATE));
    DECLARE @E DATE = CAST(GETDATE() AS DATE);
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT ts.PlateNumber,
            COUNT(DISTINCT ts.ScheduleDate)  AS DaysRefueled,
            COUNT(f.FuelID)                  AS TotalRefuels,
            ROUND(SUM(f.Liters),2)           AS TotalLiters,
            ROUND(AVG(f.Liters),2)           AS AvgLiters,
            ROUND(SUM(f.Amount),2)           AS TotalAmount,
            ROUND(AVG(f.Amount),2)           AS AvgAmount
        FROM [dbo].[TruckSchedule] ts
        LEFT JOIN [dbo].[Tbl_fuel] f
            ON  ts.PlateNumber  = f.PlateNumber
            AND ts.ScheduleDate = f.Fueldate
        WHERE ts.ScheduleDate BETWEEN @S AND @E
          $deptWhereF
          $vtypeWhereF
          $plateWhereF
          $driverWhereF
        GROUP BY ts.PlateNumber
    )
    SELECT ts2.PlateNumber, ts2.Department, v.Vehicletype,
        ISNULL(ra.DaysRefueled,0)           AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0)     AS DaysNotRefueled,
        ISNULL(ra.TotalRefuels,0)           AS TotalRefuels,
        ISNULL(ra.TotalLiters,0)            AS TotalLiters,
        ISNULL(ra.AvgLiters,0)              AS AvgLiters,
        ISNULL(ra.TotalAmount,0)            AS TotalAmount,
        ISNULL(ra.AvgAmount,0)              AS AvgAmount,
        (SELECT TOP 1 tla.Area FROM [dbo].[TruckSchedule] tla
         WHERE tla.PlateNumber = ts2.PlateNumber
           AND tla.ScheduleDate BETWEEN @S AND @E
         ORDER BY tla.ScheduleDate DESC)    AS LatestArea,
        STUFF((SELECT DISTINCT ', ' + taa.Area
               FROM [dbo].[TruckSchedule] taa
               WHERE taa.PlateNumber = ts2.PlateNumber
                 AND taa.ScheduleDate BETWEEN @S AND @E
                 AND taa.Area IS NOT NULL AND taa.Area <> ''
               FOR XML PATH('')), 1, 2, '') AS AllAreas
    FROM (SELECT DISTINCT PlateNumber, Department FROM [dbo].[TruckSchedule] WHERE ScheduleDate BETWEEN @S AND @E) ts2
    LEFT JOIN RA ra ON ra.PlateNumber = ts2.PlateNumber
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts2.PlateNumber
    ORDER BY ra.TotalLiters DESC";
}

// ============================================================
// QUERY 5 — Area Summary
// ============================================================
$q_area = "
SELECT
    f.Area                            AS Area,
    COUNT(f.FuelID)                   AS TotalRefuels,
    ROUND(SUM(f.Liters),2)            AS TotalLiters,
    ROUND(AVG(f.Liters),2)            AS AvgLiters,
    ROUND(SUM(f.Amount),2)            AS TotalAmount,
    ROUND(AVG(f.Amount),2)            AS AvgAmount,
    COUNT(DISTINCT f.PlateNumber)     AS UniqueTrucks
FROM [dbo].[TruckSchedule] ts
INNER JOIN [dbo].[Tbl_fuel] f
    ON  ts.PlateNumber  = f.PlateNumber
    AND ts.ScheduleDate = f.Fueldate
WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
  AND f.Area IS NOT NULL
  AND f.Liters IS NOT NULL
  $deptWhereF
  $vtypeWhereF
  $plateWhereF

GROUP BY f.Area
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 6 — Truck vs Area
// ============================================================
$q_truck_area = "
WITH DateRange AS (
    SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays
),
TruckStats AS (
    SELECT
        ts.PlateNumber,
        f.Area,
        ts.Department,
        v.Vehicletype,
        COUNT(f.FuelID)                                         AS Refuels,
        ROUND(SUM(f.Liters),2)                                  AS TotalLiters,
        ROUND(AVG(f.Liters),2)                                  AS TruckAvg,
        ROUND(SUM(f.Amount),2)                                  AS TotalAmount,
        ROUND(AVG(f.Amount),2)                                  AS AvgAmount
    FROM [dbo].[TruckSchedule] ts
    INNER JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $deptWhereF $vtypeWhereF $plateWhereF 
    GROUP BY ts.PlateNumber, f.Area, ts.Department, v.Vehicletype
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
    SELECT
        tb.Area,
        tb.FreqBracket,
        tb.Vehicletype,
        ROUND(AVG(tb.TruckAvg),2) AS BracketAreaAvg
    FROM TruckBracket tb
    GROUP BY tb.Area, tb.FreqBracket, tb.Vehicletype
)
SELECT
    tb.PlateNumber,
    tb.Department,
    tb.Vehicletype,
    tb.Area,
    tb.FreqBracket                                                          AS [Freq Bracket],
    tb.Refuels,
    tb.TruckAvg,
    ba.BracketAreaAvg                                                       AS AreaAvg,
    ROUND(((tb.TruckAvg - ba.BracketAreaAvg) / NULLIF(ba.BracketAreaAvg,0))*100,1) AS PctAboveAreaAvg,
    tb.TotalLiters,
    tb.TotalAmount,
    tb.AvgAmount,
    (SELECT COUNT(*) FROM TruckBracket p
     WHERE p.Area = tb.Area AND p.FreqBracket = tb.FreqBracket
       AND ISNULL(p.Vehicletype,'') = ISNULL(tb.Vehicletype,''))           AS PeerCount,
    STUFF((
        SELECT ';;' + p2.PlateNumber + '|' + ISNULL(p2.Vehicletype,'—') + '|' + CAST(p2.TruckAvg AS VARCHAR) + '|' + CAST(p2.Refuels AS VARCHAR)
        FROM TruckBracket p2
        WHERE p2.Area = tb.Area AND p2.FreqBracket = tb.FreqBracket
          AND ISNULL(p2.Vehicletype,'') = ISNULL(tb.Vehicletype,'')
        ORDER BY p2.TruckAvg DESC
        FOR XML PATH(''), TYPE
    ).value('.','NVARCHAR(MAX)'), 1, 2, '')                                 AS PeerList
FROM TruckBracket tb
INNER JOIN BracketAreaAvg ba ON ba.Area = tb.Area AND ba.FreqBracket = tb.FreqBracket
  AND ISNULL(ba.Vehicletype,'') = ISNULL(tb.Vehicletype,'')
ORDER BY tb.Area, tb.FreqBracket, tb.Vehicletype, PctAboveAreaAvg DESC";

// ============================================================
// QUERY 7 — Anomaly Flags
// ============================================================
$q_anomaly = "
WITH DateRange AS (
    SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays
),
AllRecords AS (
    SELECT
        f.FuelID,
        ts.PlateNumber,
        ts.Department,
        v.Vehicletype,
        f.Fueldate,
        f.Area,
        f.Requested                                 AS Driver,
        f.ORnumber                                  AS InvNum,
        ROUND(f.Liters,2)                           AS Liters,
        ROUND(f.Amount,2)                           AS Amount,
        ROUND(f.Price,2)                            AS PricePerLiter
    FROM [dbo].[TruckSchedule] ts
    INNER JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $deptWhereF $vtypeWhereF $plateWhereF
),
TruckBaseline AS (
    SELECT
        PlateNumber, Area, Department, Vehicletype,
        COUNT(FuelID)               AS TotalRefuels,
        ROUND(AVG(Liters),2)        AS TruckAvgLiters,
        ROUND(AVG(Amount),2)        AS TruckAvgAmount,
        ROUND(MIN(Liters),2)        AS TruckMinLiters,
        ROUND(MAX(Liters),2)        AS TruckMaxLiters
    FROM AllRecords
    GROUP BY PlateNumber, Area, Department, Vehicletype
    HAVING COUNT(FuelID) >= 2
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
    SELECT
        tb.Area, tb.FreqBracket,
        ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
    FROM TruckBracket tb
    GROUP BY tb.Area, tb.FreqBracket
)
SELECT
    ar.PlateNumber,
    ar.Department,
    ar.Vehicletype,
    ar.Fueldate,
    ar.Area,
    ar.Driver,
    ar.InvNum,
    ar.Liters,
    ar.Amount,
    ar.PricePerLiter,
    tb.TotalRefuels,
    tb.TruckAvgLiters,
    tb.TruckMinLiters,
    tb.TruckMaxLiters,
    tb.FreqBracket,
    ba.BracketAreaAvg,
    ROUND(((ar.Liters - tb.TruckAvgLiters) / NULLIF(tb.TruckAvgLiters,0))*100,1) AS PctAboveTruckAvg,
    ROUND(((ar.Liters - ba.BracketAreaAvg) / NULLIF(ba.BracketAreaAvg,0))*100,1) AS PctAboveAreaAvg,
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 1.0
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 2.0 THEN 'CRITICAL'
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 1.0 THEN 'HIGH'
        ELSE 'WATCH'
    END AS FlagLevel,
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
         AND ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 0.5 THEN 'BOTH'
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5 THEN 'TRUCK AVG'
        ELSE 'AREA AVG'
    END AS TriggeredBy
FROM AllRecords ar
INNER JOIN TruckBracket tb ON tb.PlateNumber = ar.PlateNumber AND tb.Area = ar.Area
INNER JOIN BracketAreaAvg ba ON ba.Area = ar.Area AND ba.FreqBracket = tb.FreqBracket
WHERE
    ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
    OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 0.5
ORDER BY ar.Fueldate DESC,
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 1.0
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 2.0 THEN 1
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 1.0 THEN 2
        ELSE 3
    END ASC";

// ============================================================
// QUERY 8 — Monthly Checklist
// ============================================================
$q_checklist = "
SELECT
    DAY(ts.ScheduleDate)          AS [Day],
    ts.ScheduleDate               AS [Date],
    CONVERT(VARCHAR(8), f.FuelTime, 108) AS [Fuel Time],
    ts.PlateNumber                AS [Plate Number],
    ts.Department                 AS [Department],
    v.Vehicletype                 AS [Vehicle Type],
    (SELECT TOP 1 td.Employee_Name
     FROM [dbo].[teamschedule] td
     WHERE td.PlateNumber  = ts.PlateNumber
       AND td.ScheduleDate = ts.ScheduleDate
       AND td.Position LIKE '%DRIVER%')  AS [Sched. Driver],
    ts.Area                       AS [Sched. Area],
    f.Requested                   AS [Driver],
    f.ORnumber                    AS [INV #],
    ROUND(f.Liters, 2)            AS [Liters],
    ROUND(f.Amount, 2)            AS [Amount],
    CASE WHEN f.FuelID IS NOT NULL THEN 'REFUELED' ELSE 'NOT REFUELED' END AS [Status]
FROM [dbo].[TruckSchedule] ts
LEFT JOIN [dbo].[Tbl_fuel] f
    ON  f.PlateNumber  = ts.PlateNumber
    AND f.Fueldate     = ts.ScheduleDate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
WHERE ts.ScheduleDate BETWEEN '$checklistFrom' AND '$checklistTo'
  AND ts.PlateNumber IS NOT NULL
  AND ts.PlateNumber <> ''
  $deptWhereF
  $vtypeWhereF
  $plateWhereF
  $driverWhereF
ORDER BY ts.ScheduleDate, f.ORnumber ";

$checklistParams = [];

// --- Load data for active tab only ---
$data = [];
switch ($tab) {
    case 'summary':      $data = runQuery($conn, $q_summary); break;
    case 'rank_asc':     $data = runQuery($conn, $q_ranked_asc); break;
    case 'rank_desc':    $data = runQuery($conn, $q_ranked_desc); break;
    case '30day':        $data = runQuery($conn, $q_30day); break;
    case 'area':         $data = runQuery($conn, $q_area); break;
    case 'truck_area':   $data = runQuery($conn, $q_truck_area); break;
    case 'anomaly':      $data = runQuery($conn, $q_anomaly); break;
    case 'checklist':    $data = $checklistFilterActive ? runQuery($conn, $q_checklist, $checklistParams) : []; break;
    case 'fuel_monthly': $data = runQuery($conn, $q_fuel_monthly); break;
    case 'report':
        $data = runQuery($conn, "
            SELECT
                f.PlateNumber                     AS [Plate Number],
                f.Fueldate                        AS [Fuel Date],
                CONVERT(VARCHAR(8), f.FuelTime, 108) AS [Fuel Time],
                ROUND(f.Liters, 2)                AS [Liters],
                ROUND(f.Price, 2)                 AS [Price/Liter],
                ROUND(f.Amount, 2)                AS [Amount],
                f.Area                            AS [Area],
                f.Requested                       AS [Driver],
                f.ORnumber                        AS [INV #],
                f.Supplier                        AS [Supplier],
                ts.Department                     AS [Department],
                v.Vehicletype                     AS [Vehicle Type]
            FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[TruckSchedule] ts
                ON  ts.PlateNumber  = f.PlateNumber
                AND ts.ScheduleDate = f.Fueldate
            LEFT JOIN (
                SELECT PlateNumber, Vehicletype
                FROM (
                    SELECT PlateNumber, Vehicletype,
                           ROW_NUMBER() OVER (
                               PARTITION BY PlateNumber
                               ORDER BY
                                   CASE WHEN Vehicletype IS NOT NULL
                                        AND Vehicletype NOT IN ('[select]','WH','TRUCKING')
                                        THEN 0 ELSE 1 END,
                                   CASE WHEN Active = 1 THEN 0 ELSE 1 END
                           ) AS rn
                    FROM [dbo].[Vehicle]
                ) ranked
                WHERE rn = 1
            ) v ON v.PlateNumber = f.PlateNumber
            WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
              $deptWhereF
              $vtypeWhereF
              $plateWhereR
              $driverWhereR
            ORDER BY f.Fueldate DESC");
        break;
}

$months = ['January','February','March','April','May','June',
           'July','August','September','October','November','December'];
$currentYear = (int)date('Y');

// Stat cards
$statRow = runQuery($conn, "
    SELECT
        COUNT(DISTINCT ts.PlateNumber)  AS TotalTrucks,
        ROUND(SUM(f.Liters),2)          AS TotalLiters,
        ROUND(SUM(f.Amount),2)          AS TotalAmount,
        COUNT(f.FuelID)                 AS TotalRefuels
    FROM [dbo].[TruckSchedule] ts
    LEFT JOIN [dbo].[Tbl_fuel] f
        ON  ts.PlateNumber  = f.PlateNumber
        AND ts.ScheduleDate = f.Fueldate
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      $deptWhereF
      $vtypeWhereF");
$totalTrucks  = $statRow[0]['TotalTrucks']  ?? 0;
$totalLiters  = $statRow[0]['TotalLiters']  ?? 0;
$totalAmount  = $statRow[0]['TotalAmount']  ?? 0;
$totalRefuels = $statRow[0]['TotalRefuels'] ?? 0;
$anomalyCount = ($tab === 'anomaly') ? count($data) : '—';

$deptList  = runQuery($conn, "SELECT DISTINCT Department FROM [dbo].[Vehicle] WHERE Active = 1 AND Department IS NOT NULL ORDER BY Department");
$vtypeList = runQuery($conn, "
    SELECT DISTINCT Vehicletype 
    FROM [dbo].[Vehicle] 
    WHERE Active = 1 
      AND Vehicletype IN ('CANTER','ELF','FIGHTER','FORWARD','L300','CAR','MOTOR','CROSS WIND','VAN')
    ORDER BY Vehicletype
");

function tabUrl($tab, $dateFrom, $dateTo, $selYear, $selMonth, $selDept = '', $selVtype = '', $extraParams = []) {
    global $selPlate, $selDriver, $selArea;
    $params = ['tab' => $tab];
    if ($dateFrom  !== '') $params['date_from'] = $dateFrom;
    if ($dateTo    !== '') $params['date_to']   = $dateTo;
    if ($selDept   !== '') $params['dept']       = $selDept;
    if ($selVtype  !== '') $params['vtype']      = $selVtype;
    if ($selPlate  !== '') $params['plate']      = $selPlate;
    if ($selDriver !== '') $params['driver']     = $selDriver;
    if ($selArea   !== '') $params['area']       = $selArea;
    foreach ($extraParams as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}

// Fuel monthly nav URL helper
function fcUrl($y, $m, $tab = 'fuel_monthly', $extra = []) {
    $p = array_merge($_GET, ['tab' => $tab, 'fc_year' => $y, 'fc_month' => $m]);
    unset($p['page']);
    foreach ($extra as $k => $v) $p[$k] = $v;
    return '?' . http_build_query($p);
}
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
    border-radius:12px;padding:.55rem 1rem;
    flex-wrap:wrap;
}
.fc-nav-arrow {
    background:none;border:1px solid var(--border,#e2e8f0);
    border-radius:8px;cursor:pointer;padding:.3rem .65rem;
    font-size:.85rem;color:var(--text-secondary,#475569);
    transition:background .15s;line-height:1;text-decoration:none;
    display:inline-flex;align-items:center;
}
.fc-nav-arrow:hover { background:var(--hover,#f1f5f9); }
.fc-nav-label {
    font-weight:700;font-size:.95rem;
    color:var(--text-primary,#0f172a);
    min-width:160px;text-align:center;
}
.fc-table-wrap { overflow-x:auto;margin-top:.5rem; }
#fcTable {
    width:100%;border-collapse:collapse;font-size:.78rem;min-width:900px;
}
#fcTable thead tr th {
    background:var(--surface-2,#f8fafc);
    color:var(--text-muted,#64748b);
    font-weight:700;font-size:.68rem;letter-spacing:.04em;
    padding:.45rem .6rem;
    border:1px solid var(--border,#e2e8f0);
    text-align:center;white-space:nowrap;
    position:sticky;top:0;z-index:2;
}
#fcTable thead tr:first-child th { font-size:.72rem; }
#fcTable tbody td {
    padding:.4rem .6rem;
    border:1px solid var(--border,#e2e8f0);
    vertical-align:middle;
}
#fcTable tbody td.td-right  { text-align:right;font-family:'DM Mono',monospace; }
#fcTable tbody td.td-center { text-align:center; }
#fcTable tbody td.td-dim    { color:var(--text-muted,#64748b);font-size:.73rem; }
.fc-vtype-row td {
    background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;
    font-size:.75rem;letter-spacing:.05em;padding:.35rem .75rem;
    border-top:2px solid #93c5fd;border-bottom:1px solid #93c5fd;
}
.fc-area-sub td {
    background:rgba(16,185,129,.08);color:#065f46;font-weight:700;
    font-size:.73rem;border-top:1.5px dashed #6ee7b7;border-bottom:1px solid #6ee7b7;
}
.fc-vtype-sub td {
    background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;
    font-size:.76rem;border-top:2px solid #93c5fd;
}
.fc-grand-total td {
    background:rgba(99,102,241,.1);color:#3730a3;font-weight:800;
    font-size:.8rem;border-top:2.5px solid #818cf8;
}
.no-refuel {
    display:inline-flex;align-items:center;gap:.25rem;
    font-size:.65rem;font-weight:700;letter-spacing:.03em;
    color:#94a3b8;padding:.1rem .35rem;border-radius:20px;
    background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.25);
    white-space:nowrap;
}
.fc-wk-1 { background:rgba(139,92,246,.1)!important;color:#6d28d9!important; }
.fc-wk-2 { background:rgba(59,130,246,.1)!important;color:#1d4ed8!important; }
.fc-wk-3 { background:rgba(16,185,129,.1)!important;color:#065f46!important; }
.fc-wk-4 { background:rgba(245,158,11,.1)!important;color:#92400e!important; }
.fc-wk-5 { background:rgba(239,68,68,.1)!important; color:#991b1b!important; }
.fc-wk-sub-1 { background:rgba(139,92,246,.04)!important; }
.fc-wk-sub-2 { background:rgba(59,130,246,.04)!important; }
.fc-wk-sub-3 { background:rgba(16,185,129,.04)!important; }
.fc-wk-sub-4 { background:rgba(245,158,11,.04)!important; }
.fc-wk-sub-5 { background:rgba(239,68,68,.04)!important;  }
</style>
</head>
<body>

<?php $topbar_page = 'fuel'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ══ PAGE HEADER ══════════════════════════ -->
  <div class="page-header">
    <div>
      <div class="page-title">Fuel <span>Monitoring</span> Dashboard</div>
      <div class="page-badge">📅 <?= $anyFilterApplied ? 'Filtered: '.htmlspecialchars($baseFrom).' → '.htmlspecialchars($baseTo) : 'This Month: '.date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <!-- ══ STAT CARDS ══════════════════════════════════════════ -->
  <div class="stats-row">
    <div class="stat-card">
      <span class="stat-icon">🚛</span>
      <div class="stat-label">Total Trucks</div>
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

  <!-- ══ FILTER BAR ══════════════════════════════════════════ -->
  <div class="date-filter-bar">
    <form method="GET" style="display:flex;flex-direction:column;gap:.6rem;width:100%;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <?php if ($tab === 'fuel_monthly'): ?>
      <input type="hidden" name="fc_year"  value="<?= $fcYear ?>">
      <input type="hidden" name="fc_month" value="<?= $fcMonth ?>">
      <?php endif; ?>
      <div style="display:flex;align-items:flex-end;gap:.5rem;flex-wrap:wrap;">
        <i class="bi bi-funnel-fill" style="color:var(--primary-light);font-size:.85rem;margin-bottom:.45rem;"></i>
        <span class="filter-label" style="margin-bottom:.45rem;">Filters</span>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-calendar3"></i> From</span>
          <input type="date" name="date_from" class="date-input" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-calendar3"></i> To</span>
          <input type="date" name="date_to" class="date-input" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-truck"></i> Vehicle Type</span>
          <select name="vtype" class="dept-select">
            <option value="">All Types</option>
            <?php foreach ($vtypeList as $vt): ?>
            <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($vt['Vehicletype']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-tag"></i> Plate #</span>
          <input type="text" name="plate" class="date-input" style="width:110px;"
                 value="<?= htmlspecialchars($selPlate) ?>" placeholder="e.g. ABC 123">
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-person"></i> Driver</span>
          <input type="text" name="driver" class="date-input" style="width:140px;"
                 value="<?= htmlspecialchars($selDriver) ?>" placeholder="Driver name">
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-geo-alt"></i> Area</span>
          <input type="text" name="area" class="date-input" style="width:130px;"
                 value="<?= htmlspecialchars($selArea) ?>" placeholder="Area name">
        </div>
        <button type="submit" class="btn-apply">
          <i class="bi bi-search"></i> Apply
        </button>
        <?php if ($anyFilter): ?>
        <a href="?tab=<?= htmlspecialchars($tab) ?>" class="btn-clear">
          <i class="bi bi-x-lg"></i> Clear
        </a>
        <?php endif; ?>
      </div>
      <?php if ($dateActive || $vtypeActive || $plateActive || $driverActive): ?>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
        <span style="font-size:.72rem;color:var(--text-muted);">Active:</span>
        <?php if ($dateActive): ?>
        <span class="filter-active-badge"><i class="bi bi-calendar-check"></i> <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
        <?php endif; ?>
        <?php if ($vtypeActive): ?>
        <span class="filter-active-badge" style="background:#f0fdf4;color:#166534;border-color:#86efac;"><i class="bi bi-truck"></i> <?= htmlspecialchars($selVtype) ?></span>
        <?php endif; ?>
        <?php if ($plateActive): ?>
        <span class="filter-active-badge" style="background:#e0f2fe;color:#0369a1;border-color:#7dd3fc;"><i class="bi bi-tag"></i> <?= htmlspecialchars($selPlate) ?></span>
        <?php endif; ?>
        <?php if ($driverActive): ?>
        <span class="filter-active-badge" style="background:#faf5ff;color:#7e22ce;border-color:#c4b5fd;"><i class="bi bi-person"></i> <?= htmlspecialchars($selDriver) ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ TABS ═════════════════════════════════ -->
  <div class="tabs-wrapper">
    <a href="<?= tabUrl('summary',      $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='summary'      ? 'active' : '' ?>">📊 Overall Summary</a>
    <a href="<?= tabUrl('rank_asc',     $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='rank_asc'     ? 'active' : '' ?>">📈 Low → High</a>
    <a href="<?= tabUrl('rank_desc',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='rank_desc'    ? 'active' : '' ?>">📉 High → Low</a>
    <a href="<?= tabUrl('30day',        $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='30day'        ? 'active' : '' ?>">📅 30-Day Monitor</a>
    <a href="<?= tabUrl('area',         $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='area'         ? 'active' : '' ?>">📍 Area Summary</a>
    <a href="<?= tabUrl('truck_area',   $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='truck_area'   ? 'active' : '' ?>">📊 Fuel Comparison</a>
    <a href="<?= tabUrl('anomaly',      $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn danger <?= $tab=='anomaly'      ? 'active' : '' ?>">🚨 Anomaly Flags</a>
    <a href="<?= tabUrl('checklist',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn warning <?= $tab=='checklist'   ? 'active' : '' ?>">✅ Monthly Checklist</a>
    <a href="<?= tabUrl('fuel_monthly', $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype, ['fc_year'=>$fcYear,'fc_month'=>$fcMonth]) ?>"
       class="tab-btn <?= $tab=='fuel_monthly' ? 'active' : '' ?>">📆 Fuel Consumption</a>
    <a href="<?= tabUrl('report',       $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='report'       ? 'active' : '' ?>">📋 Usage Report</a>
  </div>

  <!-- ══ TABLE SECTION ════════════════════════ -->
  <div class="table-section">

    <?php
    function deptBadge($dept) {
        $map = [];
        foreach ([
            'monde'      => 'background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;',
            'century'    => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            'multilines' => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
            'nutriasia'  => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
        ] as $key => $style) { $map[$key] = $style; }
        $style = $map[strtolower(trim($dept ?? ''))] ?? 'background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;';
        $label = htmlspecialchars($dept ?: '—');
        return "<span class='dept' style='$style;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em;'>$label</span>";
    }
    function rankBadge($r) {
        $cls = $r <= 3 ? "rank-$r" : "";
        return "<span class='rank $cls'>$r</span>";
    }
    function flagBadge($f) {
        switch($f) {
            case 'CRITICAL': return "<span class='badge badge-critical'>🔴 Critical</span>";
            case 'HIGH':     return "<span class='badge badge-high'>🟠 High</span>";
            default:         return "<span class='badge badge-watch'>🟡 Watch</span>";
        }
    }
    function statusBadge($s) {
        switch($s) {
            case 'EVERY DAY': return "<span class='badge badge-everyday'>✅ Every Day</span>";
            case 'ACTIVE':    return "<span class='badge badge-active'>🟢 Active</span>";
            case 'LOW':       return "<span class='badge badge-low'>🟡 Low</span>";
            default:          return "<span class='badge badge-norefuel'>❌ No Refuel</span>";
        }
    }
    function progressBar($pct) {
        $pct = (float)$pct;
        $cls = $pct < 30 ? 'crit' : ($pct < 60 ? 'low' : '');
        return "<div class='progress-wrap'>
            <div class='progress-bar'><div class='progress-fill $cls' style='width:{$pct}%'></div></div>
            <div class='progress-pct'>{$pct}%</div>
        </div>";
    }
    $rowLimit    = 20;
    $totalRows   = count($data);
    $totalPages  = max(1, (int)ceil($totalRows / $rowLimit));
    $curPage     = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
    $offset      = ($curPage - 1) * $rowLimit;
    $displayData = array_slice($data, $offset, $rowLimit);

    function pageUrl($page) {
        $p = $_GET;
        $p['page'] = $page;
        return '?' . http_build_query($p);
    }
    $prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
    $nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';
    ?>

    <?php if ($tab === 'summary'): ?>
    <!-- ══ SUMMARY TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        📊 Overall Fuel Summary per Truck
        <span class="table-count"><?= $totalRows ?> trucks</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
        <th onclick="sortTable(11)">All Areas <span class="sort-icon">⇅</span></th>
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
    <!-- ══ RANKED TABS ══ -->
    <div class="table-header">
      <div class="table-title">
        <?= $tab === 'rank_asc' ? '📈 Ranked: Lowest → Highest Consumption' : '📉 Ranked: Highest → Lowest Consumption' ?>
        <span class="table-count"><?= $totalRows ?> trucks</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
        <th onclick="sortTable(10)">All Areas <span class="sort-icon">⇅</span></th>
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
    <!-- ══ 30-DAY TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        📅 <?= $dateActive ? 'Refuel Monitor' : '30-Day Refuel Monitor' ?>
        <span class="table-count"><?= $totalRows ?> trucks</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
          $pct = $covered > 0 ? round($row['DaysRefueled'] / $covered * 100, 1) : 0;
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
                    onclick="showAreas('<?= htmlspecialchars($row['PlateNumber']) ?>', '<?= htmlspecialchars(str_replace("'", "\'", $row['AllAreas'])) ?>')">
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
    <!-- ══ AREA TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        📍 Fuel by Area
        <span class="table-count"><?= $totalRows ?> areas</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
        <th onclick="sortTable(6)" class="right">Unique Trucks <span class="sort-icon">⇅</span></th>
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
    <!-- ══ TRUCK VS AREA TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        📊 Fuel Comparison — How Each Truck Compares to Similar Trucks
        <span class="table-count"><?= $totalRows ?> records</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
        <th onclick="sortTable(6)" class="right">This Truck's Avg <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Similar Trucks' Avg <span class="sort-icon">⇅</span></th>
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
          if ($pct > 200)      { $pctColor = 'var(--red)';    }
          elseif ($pct > 50)   { $pctColor = 'var(--orange)'; }
          elseif ($pct >= 0)   { $pctColor = 'var(--teal)';   }
          else                 { $pctColor = 'var(--text-dim)'; }
          $bracket = $row['Freq Bracket'] ?? '';
          $bracketStyle = match($bracket) {
              'HIGH' => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
              'MID'  => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
              default => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
          };
          $peerCount    = (int)($row['PeerCount'] ?? 0);
          $peerList     = $row['PeerList'] ?? '';
          $refLines = [];
          $refLines[] = '📍 Area avg: ' . fmt($row['AreaAvg']) . ' L — bracket-normalized (' . match($bracket) { 'HIGH'=>'daily', 'MID'=>'weekly', default=>'occasional' } . ' refuelers · ' . htmlspecialchars($row['Vehicletype'] ?? 'all types') . ' · ' . htmlspecialchars($row['Area'] ?? '') . ', ' . $peerCount . ' trucks)';
          if ($peerList !== '') {
              $refLines[] = '— Similar trucks (same area · same frequency) —';
              foreach (explode(';;', $peerList) as $peer) {
                  $parts = explode('|', $peer);
                  $pPlate = $parts[0] ?? '—';
                  $pVtype = $parts[1] ?? '—';
                  $pAvg   = isset($parts[2]) ? fmt((float)$parts[2], 1) : '—';
                  $pRefs  = $parts[3] ?? '—';
                  $marker = ($pPlate === $row['PlateNumber']) ? ' ◀ this truck' : '';
                  $refLines[] = '🚛 ' . $pPlate . '  (' . $pVtype . ')  avg ' . $pAvg . ' L  · ' . $pRefs . ' refuels' . $marker;
              }
          }
          $refAttr = htmlspecialchars(implode("\n", $refLines));
      ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber']) ?></span></td>
          <td><?= deptBadge($row['Department']) ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicletype'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td><span style="<?= $bracketStyle ?>;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars(match($bracket) { 'HIGH'=>'Daily', 'MID'=>'Weekly', default=>'Occasional' }) ?></span></td>
          <td class="right mono"><?= $row['Refuels'] ?></td>
          <td class="right mono bold"><?= fmt($row['TruckAvg']) ?> L</td>
          <td class="right mono dim"><?= fmt($row['AreaAvg']) ?> L</td>
          <td class="right mono bold" style="color:<?= $pctColor ?>">
            <?php if ($pct > 0): ?>+<?= fmt($pct,1) ?>%
            <?php else: ?><?= fmt($pct,1) ?>%<?php endif; ?>
          </td>
          <td class="right mono"><?= fmt($row['TotalLiters']) ?> L</td>
          <td class="right mono"><?= peso($row['TotalAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgAmount']) ?></td>
          <td class="center">
            <button type="button" class="trig-badge"
                    style="background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.3);padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;cursor:pointer;border-width:1px;border-style:solid;font-family:inherit;white-space:nowrap;"
                    data-ref="<?= $refAttr ?>"
                    onclick="showRefPop(this)">
              <?= $peerCount ?> trucks <span style="opacity:.55;font-size:.6rem;">ⓘ</span>
            </button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'anomaly'): ?>
    <!-- ══ ANOMALY TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        🚨 Anomaly Flags — Suspicious Refuels
        <span class="table-count"><?= $totalRows ?> flagged records</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
      <span style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🔴 Critical — >100% above avg</span>
      <span style="background:rgba(249,115,22,.15);color:#ea580c;border:1px solid #fdba74;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟠 High — 50–100% above avg</span>
      <span style="background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟡 Watch — suspicious pattern</span>
      <span style="background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🔵 BOTH — triggered by truck & area avg</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php
    $grouped = [];
    foreach ($data as $row) {
        $grouped[$row['PlateNumber']][] = $row;
    }
    $shownCards = 0;
    foreach ($grouped as $plate => $records):
        if ($shownCards >= 20) break;
        $shownCards++;
        $firstRow   = $records[0];
        $dept       = $firstRow['Department'] ?? '';
        $vtype      = $firstRow['Vehicletype'] ?? '';
        $freqBkt    = $firstRow['FreqBracket'] ?? '';
        $truckAvg   = $firstRow['TruckAvgLiters'] ?? 0;
        $truckMin   = $firstRow['TruckMinLiters'] ?? 0;
        $truckMax   = $firstRow['TruckMaxLiters'] ?? 0;
        $areaAvg    = $firstRow['BracketAreaAvg'] ?? 0;
        $totalRefs  = $firstRow['TotalRefuels'] ?? 0;
        $flagLevels = array_column($records, 'FlagLevel');
        $worstFlag  = in_array('CRITICAL',$flagLevels) ? 'CRITICAL' : (in_array('HIGH',$flagLevels) ? 'HIGH' : 'WATCH');
        $bracketStyle = match($freqBkt) {
            'HIGH' => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
            'MID'  => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            default => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
        };
        $cardBorder = match($worstFlag) {
            'CRITICAL' => '#ef4444',
            'HIGH'     => '#f97316',
            default    => '#eab308',
        };
        $truckRefText = "Truck avg: ".fmt($truckAvg)." L · Range: ".fmt($truckMin)."–".fmt($truckMax)." L · ".$totalRefs." refuels";
        $bracketLabel = match($freqBkt) { 'HIGH'=>'daily', 'MID'=>'weekly', default=>'occasional' };
        $areaRefText  = "Area avg: ".fmt($areaAvg)." L (bracket-normalized — ".$bracketLabel." refuelers in same area)";
    ?>
    <div style="background:var(--surface);border:1.5px solid <?= $cardBorder ?>;border-radius:14px;overflow:hidden;"
         data-truck-ref="<?= htmlspecialchars($truckRefText) ?>"
         data-area-ref="<?= htmlspecialchars($areaRefText) ?>">
      <div style="display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:var(--hover);flex-wrap:wrap;border-bottom:1px solid var(--border);">
        <span class="plate" style="font-size:.85rem;"><?= htmlspecialchars($plate) ?></span>
        <?= deptBadge($dept) ?>
        <?php if ($vtype): ?>
        <span style="background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.3);padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($vtype) ?></span>
        <?php endif; ?>
        <span style="<?= $bracketStyle ?>;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars(match($freqBkt) { 'HIGH'=>'Daily Refueler', 'MID'=>'Weekly Refueler', default=>'Occasional Refueler' }) ?></span>
        <?= flagBadge($worstFlag) ?>
        <span style="font-size:.72rem;color:var(--text-muted);margin-left:auto;">
          <span style="font-weight:700;color:var(--text-secondary)"><?= $totalRefs ?></span> refuels
          &nbsp;·&nbsp; Avg <span style="font-weight:700;color:var(--teal)"><?= fmt($truckAvg) ?> L</span>
          &nbsp;·&nbsp; Range <span style="font-weight:700;"><?= fmt($truckMin) ?>–<?= fmt($truckMax) ?> L</span>
        </span>
      </div>
      <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
        <thead>
          <tr style="background:var(--hover);">
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Date</th>
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Area</th>
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Driver</th>
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">INV #</th>
            <th style="padding:.4rem .75rem;text-align:right;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Liters</th>
            <th style="padding:.4rem .75rem;text-align:right;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Amount</th>
            <th style="padding:.4rem .75rem;text-align:right;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Price/L</th>
            <th style="padding:.4rem .75rem;text-align:right;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">vs Truck Avg</th>
            <th style="padding:.4rem .75rem;text-align:right;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">vs Area Avg</th>
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Triggered By</th>
            <th style="padding:.4rem .75rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:.7rem;border-bottom:1px solid var(--border);">Flag</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $rec):
            $fuelDate    = $rec['Fueldate'] instanceof DateTime ? $rec['Fueldate']->format('Y-m-d') : htmlspecialchars($rec['Fueldate'] ?? '—');
            $pctTruck    = (float)($rec['PctAboveTruckAvg'] ?? 0);
            $pctArea     = (float)($rec['PctAboveAreaAvg']  ?? 0);
            $pctTColor   = $pctTruck > 100 ? 'var(--red)' : ($pctTruck > 50 ? 'var(--orange)' : 'var(--yellow)');
            $pctAColor   = $pctArea  > 200 ? 'var(--red)' : ($pctArea  > 100 ? 'var(--orange)' : 'var(--yellow)');
            $triggeredBy = $rec['TriggeredBy'] ?? '—';
            $trigStyle   = match($triggeredBy) {
                'BOTH'     => 'background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;',
                'TRUCK AVG'=> 'background:rgba(139,92,246,.15);color:#7c3aed;border:1px solid #c4b5fd;',
                default    => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            };
            $popParts = [];
            if ($triggeredBy === 'TRUCK AVG' || $triggeredBy === 'BOTH') $popParts[] = '🚛 '.$truckRefText;
            if ($triggeredBy === 'AREA AVG'  || $triggeredBy === 'BOTH') $popParts[] = '📍 '.$areaRefText;
            $popAttr = htmlspecialchars(implode("\n", $popParts));
        ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:.45rem .75rem;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= $fuelDate ?></td>
            <td style="padding:.45rem .75rem;color:var(--text-secondary);"><?= htmlspecialchars($rec['Area'] ?? '—') ?></td>
            <td style="padding:.45rem .75rem;color:var(--text-secondary);"><?= htmlspecialchars($rec['Driver'] ?? '—') ?></td>
            <td style="padding:.45rem .75rem;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= htmlspecialchars($rec['InvNum'] ?? '—') ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--teal);"><?= fmt($rec['Liters']) ?> L</td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;"><?= peso($rec['Amount']) ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;color:var(--text-muted);"><?= $rec['PricePerLiter'] !== null ? '₱'.fmt($rec['PricePerLiter']) : '—' ?></td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?= $pctTColor ?>;"><?= $pctTruck >= 0 ? '+' : '' ?><?= fmt($pctTruck,1) ?>%</td>
            <td style="padding:.45rem .75rem;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?= $pctAColor ?>;"><?= $pctArea  >= 0 ? '+' : '' ?><?= fmt($pctArea,1) ?>%</td>
            <td style="padding:.45rem .75rem;">
              <button type="button" class="trig-badge"
                      style="<?= $trigStyle ?>;padding:.12rem .45rem;border-radius:20px;font-size:.68rem;font-weight:700;cursor:pointer;border-width:1px;border-style:solid;font-family:inherit;"
                      data-ref="<?= $popAttr ?>"
                      onclick="showRefPop(this)">
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
    <!-- ══ CHECKLIST TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        ✅ Refuel Checklist
        <span class="table-count"><?= $totalRows ?> rows</span>
        <?php
          $refueledCount    = count(array_filter($data, fn($r) => $r['Status'] === 'REFUELED'));
          $notRefueledCount = count(array_filter($data, fn($r) => $r['Status'] === 'NOT REFUELED'));
        ?>
        <span class="table-count" style="background:#dcfce7;color:#166534;border-color:#86efac;">✅ <?= $refueledCount ?> Refueled</span>
        <span class="table-count" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">❌ <?= $notRefueledCount ?> Not Refueled</span>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:.25rem .6rem;border-radius:.4rem;font-size:.8rem;">
          <i class="bi bi-calendar-check"></i>
          <?= $checklistFilterActive ? htmlspecialchars($checklistFrom).' → '.htmlspecialchars($checklistTo) : 'Apply a filter to load data' ?>
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
        <th onclick="sortTable(4)">Vehicle Type <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)">Sched. Driver <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)">Sched. Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)">INV # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)">Status <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="11"><div class="empty-state">
          <?php if (!$checklistFilterActive): ?>
            <span class="icon">🔍</span>
            <p>Apply a <strong>date range</strong> or <strong>vehicle type</strong> filter to load checklist data.</p>
          <?php else: ?>
            <span class="icon">📭</span>
            <p>No scheduled deliveries for this date range.</p>
          <?php endif; ?>
        </div></td></tr>
      <?php else: foreach ($displayData as $row):
          $refueled  = ($row['Status'] === 'REFUELED');
          $rowClass  = $refueled ? 'row-refueled' : 'row-not-refueled';
          $dateVal   = $row['Date'] instanceof DateTime ? $row['Date']->format('Y-m-d') : htmlspecialchars($row['Date'] ?? '');
      ?>
        <tr class="<?= $rowClass ?>">
          <td class="right mono dim bold"><?= htmlspecialchars($row['Day'] ?? '—') ?></td>
          <td class="mono dim"><?= $dateVal ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['Fuel Time'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicle Type'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Driver'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Area'] ?? '—') ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['INV #'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? fmt($row['Liters']).' L' : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? peso($row['Amount']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= $refueled
                ? "<span class='badge badge-everyday'>✅ Refueled</span>"
                : "<span class='badge badge-norefuel'>❌ Not Refueled</span>" ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php elseif ($tab === 'fuel_monthly'): ?>
    <!-- ══ FUEL CONSUMPTION MONTHLY TAB ══ -->
    <?php
    $fcMonthLabel = $months[$fcMonth-1].' '.$fcYear;
    $prevM = $fcMonth-1; $prevY = $fcYear; if($prevM < 1){ $prevM=12; $prevY--; }
    $nextM = $fcMonth+1; $nextY = $fcYear; if($nextM > 12){ $nextM=1;  $nextY++; }
    $wkColors    = ['fc-wk-1','fc-wk-2','fc-wk-3','fc-wk-4','fc-wk-5'];
    $wkSubColors = ['fc-wk-sub-1','fc-wk-sub-2','fc-wk-sub-3','fc-wk-sub-4','fc-wk-sub-5'];
    $fixedCols   = 2;
    $totalCols   = $fixedCols + ($fcWeekCount * 2) + 2;
    ?>
    <div class="table-header">
      <div class="table-title" style="flex:1;min-width:0;">
        📆 Fuel Consumption — <?= htmlspecialchars($fcMonthLabel) ?>
        <span class="table-count"><?= count($data) ?> trucks</span>
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
      <a href="<?= fcUrl($prevY,$prevM) ?>" class="fc-nav-arrow" title="Previous month">&#8249;</a>

      <form method="GET" style="display:contents;">
        <?php foreach($_GET as $k=>$v): if($k==='fc_year'||$k==='fc_month'||$k==='tab') continue; ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <input type="hidden" name="tab" value="fuel_monthly">
        <select name="fc_month" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);color:var(--text-secondary,#475569);">
          <?php foreach($months as $mi=>$mn): ?>
          <option value="<?= $mi+1 ?>" <?= ($mi+1===$fcMonth)?'selected':'' ?>><?= $mn ?></option>
          <?php endforeach; ?>
        </select>
        <select name="fc_year" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);color:var(--text-secondary,#475569);">
          <?php for($y=(int)date('Y');$y>=2020;$y--): ?>
          <option value="<?= $y ?>" <?= ($y===$fcYear)?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>

      <span class="fc-nav-label"><?= htmlspecialchars($fcMonthLabel) ?></span>
      <a href="<?= fcUrl($nextY,$nextM) ?>" class="fc-nav-arrow" title="Next month">&#8250;</a>

      <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-left:auto;">
        <?php
        $wkRgb   = ['139,92,246','59,130,246','16,185,129','245,158,11','239,68,68'];
        $wkHex   = ['#6d28d9','#1d4ed8','#065f46','#92400e','#991b1b'];
        foreach($fcWeeks as $wi=>$wk): ?>
        <span style="font-size:.68rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:rgba(<?= $wkRgb[$wi] ?>,.12);color:<?= $wkHex[$wi] ?>;border:1px solid rgba(<?= $wkRgb[$wi] ?>,.3);">
          Wk<?= $wi+1 ?>: <?= htmlspecialchars($wk['label']) ?>
        </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Table -->
    <?php if (empty($data)): ?>
    <div class="empty-state" style="padding:3rem;">
      <span class="icon">📭</span>
      <p>No fuel records found for <strong><?= htmlspecialchars($fcMonthLabel) ?></strong>.</p>
    </div>
    <?php else: ?>
    <div class="fc-table-wrap">
    <table id="fcTable">
    <thead>
      <tr>
        <th rowspan="3" style="min-width:90px;">Plate #</th>
        <th rowspan="3" style="min-width:80px;">Vehicle Type</th>
        <?php foreach($fcWeeks as $wi=>$wk): ?>
        <th colspan="2" class="<?= $wkColors[$wi] ?>">
          Week <?= $wi+1 ?> &nbsp;<span style="font-weight:400;opacity:.75;font-size:.65rem;"><?= htmlspecialchars($wk['label']) ?></span>
        </th>
        <?php endforeach; ?>
        <th colspan="2" style="background:rgba(99,102,241,.12);color:#4338ca;">Grand Total</th>
      </tr>
      <tr>
        <?php foreach($fcWeeks as $wi=>$wk): ?>
        <th colspan="2" class="<?= $wkColors[$wi] ?>" style="font-weight:600;font-size:.66rem;opacity:.85;">Fuel</th>
        <?php endforeach; ?>
        <th colspan="2" style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.66rem;">Total</th>
      </tr>
      <tr>
        <?php foreach($fcWeeks as $wi=>$wk): ?>
        <th class="<?= $wkColors[$wi] ?>" style="font-size:.65rem;">Liters</th>
        <th class="<?= $wkColors[$wi] ?>" style="font-size:.65rem;">Amount</th>
        <?php endforeach; ?>
        <th style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.65rem;">Liters</th>
        <th style="background:rgba(99,102,241,.08);color:#4338ca;font-size:.65rem;">Amount</th>
      </tr>
    </thead>
    <tbody>
    <?php
    // Group: Vehicletype → rows (no more Area grouping)
      $fcGrouped = [];
      foreach($data as $row){
          $vt = $row['Vehicletype'] ?? '—';
          $fcGrouped[$vt][] = $row;
      }
      $grandLiters = 0; $grandAmount = 0;
      $grandWkL    = array_fill(0, $fcWeekCount, 0);
      $grandWkA    = array_fill(0, $fcWeekCount, 0);

      foreach($fcGrouped as $vtype => $rows):
          // Pre-check: skip entire vehicle type group if all rows are zero
          $hasData = false;
          foreach($rows as $r) {
              if((float)($r['TotalLiters'] ?? 0) > 0 || (float)($r['TotalAmount'] ?? 0) > 0) {
                  $hasData = true;
                  break;
              }
          }

      if(!$hasData) continue;

          echo "<tr class='fc-vtype-row'><td colspan='$totalCols'>🚛 ".htmlspecialchars($vtype)."</td></tr>\n";
          $vtypeLiters = 0; $vtypeAmount = 0;
          $vtypeWkL    = array_fill(0, $fcWeekCount, 0);
          $vtypeWkA    = array_fill(0, $fcWeekCount, 0);

          foreach($rows as $row):
                $pLiters = (float)($row['TotalLiters'] ?? 0);
                $pAmount = (float)($row['TotalAmount'] ?? 0);


          // Skip rows with zero fuel data
          if($pLiters == 0 && $pAmount == 0) continue;

              echo "<tr>";
              echo "<td style='white-space:nowrap;'><span class='plate'>".htmlspecialchars($row['PlateNumber'])."</span></td>";
              echo "<td class='td-dim'>".htmlspecialchars($vtype)."</td>";

              for($wi = 0; $wi < $fcWeekCount; $wi++):
                  $n  = $wi + 1;
                  $wL = isset($row["W{$n}Liters"])  ? (float)$row["W{$n}Liters"]  : 0.0;
                  $wA = isset($row["W{$n}Amount"])  ? (float)$row["W{$n}Amount"]  : 0.0;
                  $wR = isset($row["W{$n}Refuels"]) ? (int)  $row["W{$n}Refuels"] : 0;
                  $vtypeWkL[$wi] += $wL;
                  $vtypeWkA[$wi] += $wA;
                  $sc = $wkSubColors[$wi];
                  if($wR === 0){
                      echo "<td class='td-center $sc' colspan='2'><span class='no-refuel'>⊘ No Refuel</span></td>";
                  } else {
                      echo "<td class='td-right $sc'>".fmt($wL)." L</td>";
                      echo "<td class='td-right $sc'>".peso($wA)."</td>";
                  }
              endfor;

              echo "<td class='td-right' style='font-weight:700;color:var(--teal,#0d9488);'>".fmt($pLiters)." L</td>";
              echo "<td class='td-right' style='font-weight:700;'>".peso($pAmount)."</td>";
              echo "</tr>\n";
              $vtypeLiters += $pLiters;
              $vtypeAmount += $pAmount;
          endforeach;

          // Vehicle type subtotal
          echo "<tr class='fc-vtype-sub'>";
          echo "<td colspan='1' style='color:#1e40af;padding-left:.75rem;'>🚛 Subtotal — ".htmlspecialchars($vtype)."</td><td></td>";
          for($wi = 0; $wi < $fcWeekCount; $wi++):
              $sc = $wkSubColors[$wi];
              echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>".fmt($vtypeWkL[$wi])." L</td>";
              echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>".peso($vtypeWkA[$wi])."</td>";
              $grandWkL[$wi] += $vtypeWkL[$wi];
              $grandWkA[$wi] += $vtypeWkA[$wi];
          endfor;
          echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>".fmt($vtypeLiters)." L</td>";
          echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>".peso($vtypeAmount)."</td>";
          echo "</tr>\n";
          $grandLiters += $vtypeLiters;
          $grandAmount += $vtypeAmount;
      endforeach;

      // Grand total
      echo "<tr class='fc-grand-total'>";
      echo "<td colspan='1' style='padding-left:.75rem;'>🏁 Grand Total</td><td></td>";
      for($wi = 0; $wi < $fcWeekCount; $wi++):
          $sc = $wkSubColors[$wi];
          echo "<td class='td-right $sc' style='color:#3730a3;font-weight:800;'>".fmt($grandWkL[$wi])." L</td>";
          echo "<td class='td-right $sc' style='color:#3730a3;font-weight:800;'>".peso($grandWkA[$wi])."</td>";
      endfor;
      echo "<td class='td-right' style='color:#3730a3;font-weight:800;font-size:.85rem;'>".fmt($grandLiters)." L</td>";
      echo "<td class='td-right' style='color:#3730a3;font-weight:800;font-size:.85rem;'>".peso($grandAmount)."</td>";
      echo "</tr>\n";

    ?>
    
    </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'report'): ?>
    <!-- ══ REPORT TAB ══ -->
    <div class="table-header">
      <div class="table-title">
        📋 Fuel Usage Report
        <span class="table-count"><?= number_format($totalRows) ?> records</span>
        <?php if ($anyFilter): ?><span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span><?php endif; ?>
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
        <tr><td colspan="10"><div class="empty-state"><span class="icon">📭</span><p>No records found. Try adjusting the filters.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicle Type'] ?? '—') ?></td>
          <td class="mono dim"><?= $row['Fuel Date'] instanceof DateTime ? $row['Fuel Date']->format('Y-m-d') : htmlspecialchars($row['Fuel Date'] ?? '—') ?></td>
          <td class="mono dim"><?= htmlspecialchars($row['Fuel Time'] ?? '—') ?></td>
          <td class="right mono bold" style="color:var(--teal)"><?= $row['Liters'] !== null ? fmt($row['Liters']).' L' : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono dim"><?= $row['Price/Liter'] !== null ? '₱'.number_format((float)$row['Price/Liter'], 2) : '—' ?></td>
          <td class="right mono bold"><?= $row['Amount'] !== null ? peso($row['Amount']) : '—' ?></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Driver'] ?? '—') ?></td>
          <td class="mono dim"><?= htmlspecialchars($row['INV #'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Supplier'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>

    <?php endif; ?>

    <!-- ══ PAGINATION ════════════════════════════ -->
    <?php if ($totalPages > 1 && $tab !== 'fuel_monthly'): ?>
    <div class="pagination-bar">
      <span class="pagination-info">
        Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong> of <strong><?= $totalRows ?></strong> rows
        · Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
      </span>
      <div class="pagination-btns">
        <?php if ($prevUrl): ?>
        <a href="<?= htmlspecialchars($prevUrl) ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Previous</a>
        <?php else: ?>
        <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Previous</span>
        <?php endif; ?>
        <?php if ($nextUrl): ?>
        <a href="<?= htmlspecialchars($nextUrl) ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
        <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  <div class="footer">
    Fuel Dashboard · Tradewell Fleet Monitoring System · All Records
    · Generated <?= date('Y-m-d H:i:s') ?>
  </div>

</div><!-- /container -->

<script>
const deptBtn  = document.getElementById('deptDropBtn');
const deptMenu = document.getElementById('deptDropMenu');
if (deptBtn && deptMenu) {
    deptBtn.addEventListener('click', e => { e.stopPropagation(); deptMenu.classList.toggle('open'); });
    document.addEventListener('click', () => deptMenu.classList.remove('open'));
}

const _allData = <?php
    $exportRows = array_map(function($row) {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = ($v instanceof DateTime) ? $v->format('Y-m-d') : $v;
        }
        return $out;
    }, $data);
    echo json_encode($exportRows, JSON_UNESCAPED_UNICODE);
?>;
const _tabName     = <?php echo json_encode($tab); ?>;
const _isChecklist = (_tabName === 'checklist');
const _isFcMonthly = (_tabName === 'fuel_monthly');
const _rowLimit    = 20;
let _filteredData  = null;

function _activeData() {
    return _filteredData !== null ? _filteredData : _allData;
}

function filterTable(query) {
    const q     = query.trim().toLowerCase();
    const tbody = document.querySelector('#mainTable tbody');
    if (!tbody) return;
    if (!q) {
        _filteredData = null;
        const p = new URLSearchParams(window.location.search);
        p.delete('page');
        location.href = '?' + p.toString();
        return;
    }
    _filteredData = _allData.filter(row =>
        Object.values(row).some(v => v !== null && String(v).toLowerCase().includes(q))
    );
    if (_filteredData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="99"><div class="empty-state"><span class="icon">&#128269;</span><p>No results for "<strong>${q}</strong>".</p></div></td></tr>`;
    } else {
        tbody.innerHTML = _filteredData.slice(0, _rowLimit).map(row => {
            if (_isChecklist) {
                const refueled = String(row['Status'] ?? '').toUpperCase() === 'REFUELED';
                const cls      = refueled ? 'row-refueled' : 'row-not-refueled';
                const badge    = refueled ? `<span class='badge badge-everyday'>&#10003; Refueled</span>` : `<span class='badge badge-norefuel'>&#10007; Not Refueled</span>`;
                const dash = `<span class="text-muted">&#8212;</span>`;
                const lit  = (row['Liters'] != null && refueled) ? parseFloat(row['Liters']).toFixed(2) + ' L' : '&#8212;';
                const amt  = (row['Amount'] != null && refueled) ? '&#8369;' + parseFloat(row['Amount']).toLocaleString('en', {minimumFractionDigits:2}) : '&#8212;';
                return `<tr class="${cls}"><td class="right mono dim bold">${row['Day']??'&#8212;'}</td><td class="mono dim">${row['Date']??'&#8212;'}</td><td class="mono dim">${refueled?(row['Fuel Time']??'&#8212;'):'&#8212;'}</td><td><span class="plate">${row['Plate Number']??'&#8212;'}</span></td><td><span class="dept dept-default">${row['Department']??'&#8212;'}</span></td><td class="dim">${row['Vehicle Type']??'&#8212;'}</td><td class="dim">${row['Sched. Driver']??'&#8212;'}</td><td class="dim">${row['Sched. Area']??'&#8212;'}</td><td class="mono dim">${refueled?(row['INV #']??'&#8212;'):dash}</td><td class="right mono bold">${lit}</td><td class="right mono bold">${amt}</td><td>${badge}</td></tr>`;
            }
            if (_tabName === 'report') {
                const fmtL = v => (v != null && v !== '') ? parseFloat(v).toFixed(2) + ' L' : '&#8212;';
                const fmtP = v => (v != null && v !== '') ? '&#8369;' + parseFloat(v).toFixed(2) : '&#8212;';
                const peso = v => (v != null && v !== '') ? '&#8369;' + parseFloat(v).toLocaleString('en',{minimumFractionDigits:2}) : '&#8212;';
                return `<tr><td><span class="plate">${row['Plate Number']??'&#8212;'}</span></td><td><span class="dept dept-default">${row['Department']??'&#8212;'}</span></td><td class="mono dim">${row['Fuel Date']??'&#8212;'}</td><td class="right mono bold" style="color:var(--teal)">${fmtL(row['Liters'])}</td><td class="right mono dim">${fmtP(row['Price/Liter'])}</td><td class="right mono bold">${peso(row['Amount'])}</td><td class="dim">${row['Area']??'&#8212;'}</td><td class="dim">${row['Driver']??'&#8212;'}</td><td class="mono dim">${row['INV #']??'&#8212;'}</td><td class="dim">${row['Supplier']??'&#8212;'}</td></tr>`;
            }
            const keys = Object.keys(row);
            return `<tr>${keys.map(k => `<td>${row[k] !== null && row[k] !== undefined ? row[k] : '&#8212;'}</td>`).join('')}</tr>`;
        }).join('');
    }
    const info = document.querySelector('.pagination-info');
    if (info) info.innerHTML = `Showing <strong>1&#8211;${Math.min(_rowLimit, _filteredData.length)}</strong> of <strong>${_filteredData.length}</strong> matching rows`;
}

let sortDir = {};
function sortTable(col) {
    const table = document.getElementById('mainTable');
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    const ths   = table.querySelectorAll('thead th');
    sortDir[col] = !sortDir[col];
    const asc = sortDir[col];
    ths.forEach((th, i) => {
        th.classList.toggle('sorted', i === col);
        const icon = th.querySelector('.sort-icon');
        if (icon) icon.textContent = i === col ? '\u2191' : (sortDir[col] === undefined ? '\u21C5' : '\u2193');
    });
    rows.sort((a, b) => {
        const aT = a.cells[col]?.textContent.trim() ?? '';
        const bT = b.cells[col]?.textContent.trim() ?? '';
        const aN = parseFloat(aT.replace(/[\u20B1,L% +]/g, ''));
        const bN = parseFloat(bT.replace(/[\u20B1,L% +]/g, ''));
        if (!isNaN(aN) && !isNaN(bN)) return asc ? aN - bN : bN - aN;
        return asc ? aT.localeCompare(bT) : bT.localeCompare(aT);
    });
    rows.forEach(r => tbody.appendChild(r));
}

document.querySelectorAll('.progress-fill').forEach(el => {
    const w = el.style.width;
    el.style.width = '0';
    setTimeout(() => { el.style.width = w; }, 80);
});

function _getExportData() {
    const src = _activeData();
    if (src.length > 5000) {
        const ok = confirm(`You are about to export ${src.length.toLocaleString()} rows.\nThis may be slow.\n\nProceed anyway?`);
        if (!ok) return null;
    }
    return src;
}

function _buildFilename(ext) {
    const p      = new URLSearchParams(window.location.search);
    const parts  = ['Fuel Dashboard', _tabName];
    const dFrom  = p.get('date_from') || '';
    const dTo    = p.get('date_to')   || '';
    const vtype  = p.get('vtype')     || '';
    const plate  = p.get('plate')     || '';
    const driver = p.get('driver')    || '';
    const area   = p.get('area')      || '';
    const dept   = <?php echo json_encode($_SESSION['Department'] ?? ''); ?>;
    if (dFrom) parts.push(dFrom + (dTo ? '_to_' + dTo : ''));
    if (dept)  parts.push(dept);
    if (vtype) parts.push(vtype);
    if (plate) parts.push(plate);
    if (driver) parts.push(driver);
    if (area)  parts.push(area);
    if (_filteredData !== null) parts.push('filtered');
    return parts.join('_').replace(/[^a-zA-Z0-9_-]/g, '_') + '.' + ext;
}

function exportCSV() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to export.'); return; }
    const headers  = Object.keys(rows[0]);
    const csvLines = [headers.map(h => '"' + h + '"').join(',')];
    rows.forEach(row => {
        csvLines.push(headers.map(h => {
            const v = row[h] !== null && row[h] !== undefined ? String(row[h]) : '';
            return '"' + v.replace(/"/g, '""') + '"';
        }).join(','));
    });
    const url = URL.createObjectURL(new Blob(['\uFEFF' + csvLines.join('\n')], {type:'text/csv;charset=utf-8;'}));
    const a   = document.createElement('a');
    a.href = url; a.download = _buildFilename('csv');
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}

function exportExcel() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to export.'); return; }
    const headers = Object.keys(rows[0]);
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let xml = '<?xml version="1.0"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n<Worksheet ss:Name="Fuel Dashboard"><Table>\n';
    xml += '<Row>' + headers.map(h => `<Cell><Data ss:Type="String">${esc(h)}</Data></Cell>`).join('') + '</Row>\n';
    rows.forEach(row => {
        xml += '<Row>' + headers.map(h => {
            const raw   = row[h] ?? '';
            const clean = String(raw).replace(/[\u20B1,L% ]/g,'').trim();
            const num   = parseFloat(clean);
            if (!isNaN(num) && isFinite(num) && String(raw).match(/^[\u20B1]?[\d,]+(\.\d+)?[L%]?$/)) {
                return `<Cell><Data ss:Type="Number">${num}</Data></Cell>`;
            }
            return `<Cell><Data ss:Type="String">${esc(raw)}</Data></Cell>`;
        }).join('') + '</Row>\n';
    });
    xml += '</Table></Worksheet></Workbook>';
    const url = URL.createObjectURL(new Blob([xml], {type:'application/vnd.ms-excel;charset=utf-8;'}));
    const a   = document.createElement('a');
    a.href = url; a.download = _buildFilename('xls');
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}

function printTable() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to print.'); return; }
    const title  = document.querySelector('.table-title')?.innerText?.replace(/[\u21C5\u2191\u2193]/g,'').trim() || 'Report';
    const p      = new URLSearchParams(window.location.search);
    const filters = [];
    const dFrom  = p.get('date_from'), dTo = p.get('date_to');
    const dept   = <?php echo json_encode($_SESSION['Department'] ?? ''); ?>;
    const vtype  = p.get('vtype') || '', plate = p.get('plate') || '', driver = p.get('driver') || '', area = p.get('area') || '';
    if (dFrom || dTo) filters.push('Date: ' + (dFrom||'\u2014') + ' \u2192 ' + (dTo||'\u2014'));
    if (dept)   filters.push('Dept: ' + dept);
    if (vtype)  filters.push('Type: ' + vtype);
    if (plate)  filters.push('Plate: ' + plate);
    if (driver) filters.push('Driver: ' + driver);
    if (area)   filters.push('Area: ' + area);
    if (_filteredData !== null) filters.push('Search filtered');
    if (_tabName === 'anomaly') {
        const w2 = window.open('', '_blank', 'width=1200,height=800');
        const liveTable = document.getElementById('mainTable');
        const content = liveTable ? liveTable.cloneNode(true).outerHTML : '<p>No anomaly data.</p>';
        w2.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>body{font-family:Arial,sans-serif;padding:16px;color:#0f172a;font-size:11px;}h2{margin:0 0 3px;font-size:15px;color:#1e40af;}p{margin:0 0 12px;font-size:10px;color:#64748b;}table{border-collapse:collapse;width:100%;margin-bottom:8px;}th{background:#1e40af;color:#fff;padding:4px 6px;font-size:9px;text-align:left;border:1px solid #1e3a8a;}td{padding:4px 6px;border:1px solid #e2e8f0;font-size:9px;vertical-align:middle;}.plate{background:#e0f2fe;color:#0369a1;border-radius:3px;padding:1px 4px;font-size:8px;font-weight:700;}.badge{border-radius:3px;padding:1px 4px;font-size:8px;font-weight:700;}.badge-critical{background:#fee2e2;color:#991b1b;}.badge-high{background:#ffedd5;color:#9a3412;}.badge-watch{background:#fef9c3;color:#713f12;}@media print{body{padding:4px;}}</style></head><body><h2>Admin Dashboard &middot; Tradewell Fleet Monitoring</h2><p>${title}${filters.length?' &middot; '+filters.join(' &middot; '):''} &middot; ${rows.length} flagged records &middot; ${new Date().toLocaleString()}</p>${content}</body></html>`);
        w2.document.close();
        setTimeout(() => w2.print(), 400);
        return;
    }
    const liveTable = document.getElementById('mainTable');
    if (!liveTable) { alert('Table not found.'); return; }
    const theadClone = liveTable.querySelector('thead').cloneNode(true);
    theadClone.querySelectorAll('.sort-icon').forEach(el => el.remove());
    theadClone.querySelectorAll('th').forEach(th => { th.style.cssText = 'background:#1e40af;color:#fff;padding:5px 7px;font-size:10px;text-align:left;border:1px solid #1e3a8a;'; });
    const allRows  = _activeData();
    const tbodyHTML = allRows.map(row => {
        if (_isChecklist) {
            const refueled = String(row['Status'] ?? '').toUpperCase() === 'REFUELED';
            const bg = refueled ? 'background:#dcfce7;' : 'background:#fee2e2;';
            const dash = '&#8212;';
            const lit  = (row['Liters'] != null && refueled) ? parseFloat(row['Liters']).toFixed(2)+' L' : dash;
            const amt  = (row['Amount'] != null && refueled) ? '&#8369;'+parseFloat(row['Amount']).toLocaleString('en',{minimumFractionDigits:2}) : dash;
            const badge = refueled ? '<span style="background:#dcfce7;color:#166534;border-radius:3px;padding:1px 4px;font-size:9px;">&#10003; Refueled</span>' : '<span style="background:#fee2e2;color:#991b1b;border-radius:3px;padding:1px 4px;font-size:9px;">&#10007; Not Refueled</span>';
            return `<tr style="${bg}"><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Day']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Date']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${refueled?(row['Fuel Time']??dash):dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;"><span style="background:#e0f2fe;color:#0369a1;border-radius:3px;padding:1px 4px;font-size:9px;font-weight:700;">${row['Plate Number']??dash}</span></td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Department']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Vehicle Type']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Sched. Driver']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Sched. Area']??dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${refueled?(row['INV #']??dash):dash}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;text-align:right;">${lit}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;text-align:right;">${amt}</td><td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${badge}</td></tr>`;
        }
        const tdStyle = 'padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;vertical-align:middle;';
        return '<tr>' + Object.values(row).map(v => `<td style="${tdStyle}">${v !== null && v !== undefined ? v : '&#8212;'}</td>`).join('') + '</tr>';
    }).join('');
    const tableHTML = `<table style="border-collapse:collapse;width:100%;"><thead>${theadClone.innerHTML}</thead><tbody>${tbodyHTML}</tbody></table>`;
    const w = window.open('', '_blank', 'width=1200,height=800');
    w.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>body{font-family:Arial,sans-serif;padding:16px;color:#0f172a;}h2{margin:0 0 3px;font-size:15px;color:#1e40af;}p{margin:0 0 12px;font-size:10px;color:#64748b;}table{border-collapse:collapse;width:100%;page-break-inside:auto;}tr{page-break-inside:avoid;}.plate{background:#e0f2fe;color:#0369a1;border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;}.badge{border-radius:4px;padding:1px 5px;font-size:9px;font-weight:600;}.badge-everyday{background:#dcfce7;color:#166534;}.badge-norefuel{background:#fee2e2;color:#991b1b;}.text-muted{color:#94a3b8;font-style:italic;}@media print{body{padding:6px;}}</style></head><body><h2>Admin Dashboard &middot; Tradewell Fleet Monitoring</h2><p>${title}${filters.length?' &middot; '+filters.join(' &middot; '):''} &middot; ${allRows.length} rows &middot; ${new Date().toLocaleString()}</p>${tableHTML}</body></html>`);
    w.document.close();
    setTimeout(() => w.print(), 400);
}

// ── Fuel Monthly Export & Print ──────────────────────────────
function _fcRows() {
    const tbl = document.getElementById('fcTable');
    if (!tbl) return [];
    const headers = [];
    tbl.querySelectorAll('thead tr:last-child th').forEach(th => headers.push(th.textContent.trim()));
    const rows = [];
    tbl.querySelectorAll('tbody tr').forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length <= 2) return;
        const obj = {};
        let hi = 0;
        cells.forEach(td => {
            const key = headers[hi] || ('Col' + hi);
            obj[key] = td.textContent.trim().replace(/⊘\s*/,'').replace(/\s+/g,' ');
            hi += (parseInt(td.getAttribute('colspan') || '1'));
        });
        rows.push(obj);
    });
    return rows;
}

function fcExportCSV() {
    const rows = _fcRows();
    if (!rows.length) { alert('No data to export.'); return; }
    const headers = Object.keys(rows[0]);
    const lines   = [headers.map(h => '"'+h+'"').join(',')];
    rows.forEach(r => lines.push(headers.map(h => '"'+(r[h]??'').replace(/"/g,'""')+'"').join(',')));
    const url = URL.createObjectURL(new Blob(['\uFEFF'+lines.join('\n')],{type:'text/csv;charset=utf-8;'}));
    const a   = document.createElement('a');
    a.href=url; a.download='Fuel_Monthly_'+<?php echo json_encode($months[$fcMonth-1].'_'.$fcYear); ?>+'.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

function fcExportExcel() {
    const rows = _fcRows();
    if (!rows.length) { alert('No data to export.'); return; }
    const headers = Object.keys(rows[0]);
    const esc = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let xml = '<?xml version="1.0"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n<Worksheet ss:Name="Fuel Monthly"><Table>\n';
    xml += '<Row>'+headers.map(h=>`<Cell><Data ss:Type="String">${esc(h)}</Data></Cell>`).join('')+'</Row>\n';
    rows.forEach(r=>{
        xml+='<Row>'+headers.map(h=>{
            const raw=r[h]??'';
            const clean=String(raw).replace(/[₱,L\s]/g,'').trim();
            const num=parseFloat(clean);
            return (!isNaN(num)&&isFinite(num))?`<Cell><Data ss:Type="Number">${num}</Data></Cell>`:`<Cell><Data ss:Type="String">${esc(raw)}</Data></Cell>`;
        }).join('')+'</Row>\n';
    });
    xml+='</Table></Worksheet></Workbook>';
    const url=URL.createObjectURL(new Blob([xml],{type:'application/vnd.ms-excel;charset=utf-8;'}));
    const a=document.createElement('a');
    a.href=url; a.download='Fuel_Monthly_'+<?php echo json_encode($months[$fcMonth-1].'_'.$fcYear); ?>+'.xls';
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}

function fcPrint() {
    const tbl = document.getElementById('fcTable');
    if (!tbl) { alert('Table not found.'); return; }
    const title = <?php echo json_encode('Fuel Consumption — '.$months[$fcMonth-1].' '.$fcYear); ?>;
    const weekChips = <?php
        $chips = [];
        foreach($fcWeeks as $wi=>$wk) $chips[] = 'Wk'.($wi+1).': '.$wk['label'];
        echo json_encode(implode('  ·  ', $chips));
    ?>;
    const w = window.open('','_blank','width=1400,height=900');
    w.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>body{font-family:Arial,sans-serif;padding:12px;color:#0f172a;font-size:10px;}h2{margin:0 0 2px;font-size:14px;color:#1e40af;}p{margin:0 0 10px;font-size:9px;color:#64748b;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #e2e8f0;padding:3px 5px;font-size:9px;}th{background:#f1f5f9;font-weight:700;text-align:center;}.fc-vtype-row td{background:#dbeafe;color:#1e40af;font-weight:800;}.fc-area-sub td{background:#d1fae5;color:#065f46;font-weight:700;}.fc-vtype-sub td{background:#e0f2fe;color:#0369a1;font-weight:800;}.fc-grand-total td{background:#ede9fe;color:#3730a3;font-weight:900;}@media print{body{padding:4px;}}</style></head><body><h2>Tradewell Fleet &middot; ${title}</h2><p>${weekChips} &middot; Generated ${new Date().toLocaleString()}</p>${tbl.outerHTML}</body></html>`);
    w.document.close();
    setTimeout(()=>w.print(),400);
}
</script>

<!-- Areas Modal -->
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

<style>
.filter-group { display:flex;flex-direction:column;gap:.2rem; }
@keyframes modalIn { from{opacity:0;transform:scale(.95) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)} }
.btn-areas { display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3);cursor:pointer;transition:background .15s;font-family:inherit;white-space:nowrap; }
.btn-areas:hover { background:rgba(59,130,246,.22); }
.area-chip { display:inline-block;padding:.25rem .65rem;background:var(--hover);border:1px solid var(--border);border-radius:20px;font-size:.78rem;font-weight:600;color:var(--text-secondary); }
</style>

<script>
function showAreas(plate, areas) {
    document.getElementById('areasModalPlate').textContent = plate;
    const list = document.getElementById('areasModalList');
    list.innerHTML = areas.split(', ').filter(a => a.trim()).map(a => `<span class="area-chip"><i class="bi bi-geo-alt"></i> ${a.trim()}</span>`).join('');
    document.getElementById('areasModal').style.display = 'flex';
}
function closeAreas() {
    document.getElementById('areasModal').style.display = 'none';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAreas(); });
</script>

<!-- Triggered-By Reference Popover -->
<div id="refPopover" style="display:none;position:fixed;z-index:10000;max-width:340px;width:max-content;">
  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.85rem 1rem;box-shadow:0 8px 32px rgba(0,0,0,.18);font-size:.78rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
      <span style="font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Reference Baselines</span>
      <button onclick="closeRefPop()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:.1rem .3rem;line-height:1;">✕</button>
    </div>
    <div id="refPopLines" style="display:flex;flex-direction:column;gap:.45rem;color:var(--text-secondary);line-height:1.5;"></div>
  </div>
</div>

<script>
(function() {
    const pop = document.getElementById('refPopover');
    document.body.appendChild(pop);
    let currentBtn = null;
    window.showRefPop = function(btn) {
        if (currentBtn === btn && pop.style.display !== 'none') { closeRefPop(); return; }
        currentBtn = btn;
        const raw   = btn.getAttribute('data-ref') || '';
        const lines = raw.split('\n').filter(l => l.trim());
        const linesEl = document.getElementById('refPopLines');
        linesEl.innerHTML = lines.map(l => {
            const isSelf      = l.includes('◀ this truck');
            const isSeparator = l.startsWith('—');
            if (isSeparator) return `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);padding:.25rem 0 .1rem;">${l.replace(/—/g,'').trim()}</div>`;
            const bg = isSelf ? 'background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);' : 'background:var(--hover);border:1px solid transparent;';
            const weight = isSelf ? 'font-weight:700;' : '';
            return `<div style="padding:.3rem .5rem;border-radius:7px;font-size:.76rem;font-family:'DM Mono',monospace;${bg}${weight}">${l.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
        }).join('');
        pop.style.display = 'block';
        const btnRect = btn.getBoundingClientRect();
        const pw = pop.offsetWidth, ph = pop.offsetHeight, margin = 8;
        let left = btnRect.left, top = btnRect.top - ph - 6;
        if (top < margin) top = btnRect.bottom + 6;
        if (left + pw > window.innerWidth - margin) left = btnRect.right - pw;
        if (left < margin) left = margin;
        pop.style.left = left + 'px';
        pop.style.top  = top  + 'px';
    };
    window.closeRefPop = function() { pop.style.display = 'none'; currentBtn = null; };
    document.addEventListener('click', function(e) { if (!pop.contains(e.target) && !e.target.closest('.trig-badge')) closeRefPop(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeRefPop(); });
})();
</script>

</body>
</html>
<?php sqlsrv_close($conn); ?>