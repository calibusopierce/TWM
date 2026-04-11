<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);

// --- ACTIVE TAB ---
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';

// --- DATE FILTER (applies to all tabs except checklist) ---
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';

// Validate dates
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

// Build the base date condition
// Default (no filter): current month only — keeps page load light
// When any filter is applied: use the full specified range (or all-time if only one end set)
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
    // Dept or vtype filter only — show all-time so filter has full data to work with
    $baseFrom = '1900-01-01';
    $baseTo   = date('Y-m-d');
} else {
    // No filter at all — default to current month
    $baseFrom = date('Y-m-01');
    $baseTo   = date('Y-m-d');
}

// --- DEPARTMENT FILTER ---
// Always driven by session — changed via set_department.php
$selDept = $_SESSION['Department'] ?? '';
$deptActive = ($selDept !== '');
$deptWhere  = $deptActive ? "AND v.Department = '$selDept'" : '';
// TruckSchedule is the driving table for all queries — use its Department column
$deptWhereF = $deptActive ? "AND ts.Department = '$selDept'" : '';

// Department color map
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
$vtypeWhereF = $vtypeActive ? "AND ts.PlateNumber IN (SELECT PlateNumber FROM [dbo].[Vehicle] WHERE Vehicletype = '$selVtype')" : '';

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
$areaActive = ($selArea !== '');
$areaWhereF = $areaActive ? "AND ts.Area LIKE '%".str_replace("'","''",$selArea)."%'" : '';
$areaWhereR = $areaActive ? "AND f.Area LIKE '%".str_replace("'","''",$selArea)."%'" : '';

$anyFilter  = $dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive || $areaActive;

// Checklist date range — today by default, filter range when any filter active
$checklistFrom = $anyFilter ? $baseFrom : date('Y-m-d');
$checklistTo   = $anyFilter ? $baseTo   : date('Y-m-d');
// Checklist only loads when user explicitly sets a date range or vehicle type
$checklistFilterActive = $dateActive || $vtypeActive || $plateActive || $driverActive || $areaActive;

// --- MONTH/YEAR PARAMS FOR CHECKLIST ---
$selYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$selMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear  = max(2025, min((int)date('Y'), $selYear));
$selMonth = max(1, min(12, $selMonth));

// --- HELPER: RUN QUERY ---
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
// QUERY 1 — Overall Summary per Truck
// Driving table: TruckSchedule → Tbl_fuel (boss's reference pattern)
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
  $areaWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype, v.FuelType
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 2/3 — Ranked Low to High / High to Low
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
  $areaWhereF
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
  $areaWhereF
GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 4 — 30-Day Summary (uses date filter if set, else last 30 days)
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
          $areaWhereF
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
          $areaWhereF
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
// Based on actual fuel records only (INNER JOIN = no NULL rows)
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
  $areaWhereR
GROUP BY f.Area
ORDER BY TotalLiters DESC";

// ============================================================
// QUERY 6 — Truck vs Area (frequency-bracket normalized area avg)
// Frequency brackets: HIGH=15+/month, MID=4-14/month, LOW=1-3/month
// Area avg is computed within same bracket to avoid infrequent trucks
// inflating the area average vs daily refuelers.
// ============================================================
$q_truck_area = "
WITH DateRange AS (
    SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays
),
-- Step 1: per-truck refuel count + avg per area
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
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereR
    GROUP BY ts.PlateNumber, f.Area, ts.Department, v.Vehicletype
),
-- Step 2: assign frequency bracket per truck per area
-- Normalize to monthly rate: Refuels / (TotalDays/30)
TruckBracket AS (
    SELECT ts.*,
        CASE
            WHEN ts.Refuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 15 THEN 'HIGH'
            WHEN ts.Refuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 4  THEN 'MID'
            ELSE 'LOW'
        END AS FreqBracket
    FROM TruckStats ts
),
-- Step 3: area avg per bracket AND vehicle type (only same type trucks)
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
    -- Number of peer trucks in same bracket + area + vehicle type (including self)
    (SELECT COUNT(*) FROM TruckBracket p
     WHERE p.Area = tb.Area AND p.FreqBracket = tb.FreqBracket
       AND ISNULL(p.Vehicletype,'') = ISNULL(tb.Vehicletype,''))           AS PeerCount,
    -- Pipe-delimited list: PlateNumber|VehicleType|TruckAvg|Refuels
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
// QUERY 7 — Anomaly Flags (per individual refuel record)
// Flags each record against:
//   A) Truck's own historical average (personal baseline)
//   B) Bracket-normalized area average (peer comparison)
// Either trigger = flagged. Needs at least 2 records for truck avg baseline.
// ============================================================
$q_anomaly = "
WITH DateRange AS (
    SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays
),
-- All refuel records in range
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
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereR
),
-- Truck historical avg per area (needs 2+ records as baseline)
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
-- Frequency bracket per truck
TruckBracket AS (
    SELECT tb.*,
        CASE
            WHEN tb.TotalRefuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 15 THEN 'HIGH'
            WHEN tb.TotalRefuels / NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0) >= 4  THEN 'MID'
            ELSE 'LOW'
        END AS FreqBracket
    FROM TruckBaseline tb
),
-- Bracket-normalized area avg
BracketAreaAvg AS (
    SELECT
        tb.Area, tb.FreqBracket,
        ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
    FROM TruckBracket tb
    GROUP BY tb.Area, tb.FreqBracket
)
-- Final: join each individual record to its truck baseline + bracket area avg
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
    -- % above truck's own avg
    ROUND(((ar.Liters - tb.TruckAvgLiters) / NULLIF(tb.TruckAvgLiters,0))*100,1) AS PctAboveTruckAvg,
    -- % above bracket area avg
    ROUND(((ar.Liters - ba.BracketAreaAvg) / NULLIF(ba.BracketAreaAvg,0))*100,1) AS PctAboveAreaAvg,
    -- Flag level: worst of both comparisons
    CASE
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 1.0
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 2.0 THEN 'CRITICAL'
        WHEN ((ar.Liters - tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0)) > 0.5
          OR ((ar.Liters - ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0)) > 1.0 THEN 'HIGH'
        ELSE 'WATCH'
    END AS FlagLevel,
    -- Which baseline triggered the flag
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
// QUERY 8 -- Monthly Checklist (per-day stacked rows)
// One row per scheduled day per plate.
// Department from TruckSchedule. Driver/Area/INV# from Tbl_fuel (NULL = not refueled).
// Green = refueled, Red = not refueled.
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
    f.Area                        AS [Area],
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
  $areaWhereF
ORDER BY ts.ScheduleDate, f.ORnumber ";

$checklistParams = [];

// --- Load data for active tab only ---
$data = [];
switch ($tab) {
    case 'summary':    $data = runQuery($conn, $q_summary); break;
    case 'rank_asc':   $data = runQuery($conn, $q_ranked_asc); break;
    case 'rank_desc':  $data = runQuery($conn, $q_ranked_desc); break;
    case '30day':      $data = runQuery($conn, $q_30day); break;
    case 'area':       $data = runQuery($conn, $q_area); break;
    case 'truck_area': $data = runQuery($conn, $q_truck_area); break;
    case 'anomaly':    $data = runQuery($conn, $q_anomaly); break;
    case 'checklist':  $data = $checklistFilterActive ? runQuery($conn, $q_checklist, $checklistParams) : []; break;
    case 'report':
        // Direct from Tbl_fuel — clean columns only
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
                ts.Department                     AS [Department]
            FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[TruckSchedule] ts
                ON  ts.PlateNumber  = f.PlateNumber
                AND ts.ScheduleDate = f.Fueldate
            WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
              $deptWhereF
              $vtypeWhereF
              $plateWhereR
              $driverWhereR
              $areaWhereR
            ORDER BY f.Fueldate DESC");
        break;
}

$months = ['January','February','March','April','May','June',
           'July','August','September','October','November','December'];
$currentYear = (int)date('Y');

// Stat cards — single aggregated query, much lighter than per-truck grouping
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
// Anomaly count — only run when on the anomaly tab (heavy query)
$anomalyCount = ($tab === 'anomaly') ? count($data) : '—';

// Fetch distinct departments for dropdown
$deptList = runQuery($conn, "SELECT DISTINCT Department FROM [dbo].[Vehicle] WHERE Active = 1 AND Department IS NOT NULL ORDER BY Department");

// Fetch distinct vehicle types for dropdown
$vtypeList = runQuery($conn, "SELECT DISTINCT Vehicletype FROM [dbo].[Vehicle] WHERE Active = 1 AND Vehicletype IS NOT NULL ORDER BY Vehicletype");

// Build URL helper to preserve all current params when switching tabs or filters
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

  <!-- ══ STAT CARDS (filtered by date range if active) ══════════════ -->
  <?php $statLabel = $dateActive ? htmlspecialchars($baseFrom).' → '.htmlspecialchars($baseTo) : 'All Records'; ?>
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
      <?php if ($dateActive || $vtypeActive || $plateActive || $driverActive || $areaActive): ?>
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
        <?php if ($areaActive): ?>
        <span class="filter-active-badge" style="background:#fff7ed;color:#c2410c;border-color:#fdba74;"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selArea) ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ TABS ═════════════════════════════════ -->
  <div class="tabs-wrapper">
    <a href="<?= tabUrl('summary',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='summary'    ? 'active' : '' ?>">📊 Overall Summary</a>
    <a href="<?= tabUrl('rank_asc',   $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='rank_asc'   ? 'active' : '' ?>">📈 Low → High</a>
    <a href="<?= tabUrl('rank_desc',  $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='rank_desc'  ? 'active' : '' ?>">📉 High → Low</a>
    <a href="<?= tabUrl('30day',      $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='30day'      ? 'active' : '' ?>">📅 30-Day Monitor</a>
    <a href="<?= tabUrl('area',       $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='area'       ? 'active' : '' ?>">📍 Area Summary</a>
    <a href="<?= tabUrl('truck_area', $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='truck_area' ? 'active' : '' ?>">📊 Fuel Comparison</a>
    <a href="<?= tabUrl('anomaly',    $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn danger <?= $tab=='anomaly'    ? 'active' : '' ?>">🚨 Anomaly Flags</a>
    <a href="<?= tabUrl('checklist',  $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn warning <?= $tab=='checklist' ? 'active' : '' ?>">✅ Monthly Checklist</a>
    <a href="<?= tabUrl('report',     $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype) ?>"
       class="tab-btn <?= $tab=='report'     ? 'active' : '' ?>">📋 Usage Report</a>
  </div>

  <!-- ══ TABLE SECTION ════════════════════════ -->
  <div class="table-section">

    <?php
    // ── helpers ──────────────────────────────
    function deptBadge($dept) {
        $map = [];
        // Case-insensitive lookup
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
    // Pagination: 20 rows per page
    $rowLimit    = 20;
    $totalRows   = count($data);
    $totalPages  = max(1, (int)ceil($totalRows / $rowLimit));
    $curPage     = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
    $offset      = ($curPage - 1) * $rowLimit;
    $displayData = array_slice($data, $offset, $rowLimit);

    // Build pagination URL helper
    function pageUrl($page) {
        $p = $_GET;
        $p['page'] = $page;
        return '?' . http_build_query($p);
    }
    $prevUrl = $curPage > 1          ? pageUrl($curPage - 1) : '';
    $nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';
    ?>

    <?php if ($tab === 'summary'): ?>
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
          $bracketLabel = match($bracket) { 'HIGH'=>'daily', 'MID'=>'weekly', default=>'occasional' };
          $peerCount    = (int)($row['PeerCount'] ?? 0);
          $peerList     = $row['PeerList'] ?? '';
          // Build data-ref: line 1 = area avg context, lines 2+ = each peer truck
          $refLines = [];
          $refLines[] = '📍 Area avg: ' . fmt($row['AreaAvg']) . ' L — bracket-normalized (' . $bracketLabel . ' refuelers · ' . htmlspecialchars($row['Vehicletype'] ?? 'all types') . ' · ' . htmlspecialchars($row['Area'] ?? '') . ', ' . $peerCount . ' trucks)';
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

    <!-- Anomaly legend -->
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;font-size:.75rem;">
      <span style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🔴 Critical — >100% above avg</span>
      <span style="background:rgba(249,115,22,.15);color:#ea580c;border:1px solid #fdba74;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟠 High — 50–100% above avg</span>
      <span style="background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🟡 Watch — suspicious pattern</span>
      <span style="background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;padding:.2rem .65rem;border-radius:20px;font-weight:700;">🔵 BOTH — triggered by truck & area avg</span>
    </div>

    <!-- Stack cards -->
    <div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php
    // Group records by PlateNumber for card grouping
    $grouped = [];
    foreach ($data as $row) {
        $grouped[$row['PlateNumber']][] = $row;
    }
    $shownCards = 0;
    foreach ($grouped as $plate => $records):
        if ($shownCards >= 20) break; // safety cap per page
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
        // Worst flag across all records for this truck
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
        // Reference text stored as data attrs, used by JS popover on TriggeredBy badges
        $truckRefText = "Truck avg: ".fmt($truckAvg)." L · Range: ".fmt($truckMin)."–".fmt($truckMax)." L · ".$totalRefs." refuels";
        $bracketLabel = match($freqBkt) { 'HIGH'=>'daily', 'MID'=>'weekly', default=>'occasional' };
        $areaRefText  = "Area avg: ".fmt($areaAvg)." L (bracket-normalized — ".$bracketLabel." refuelers in same area)";
    ?>
    <div style="background:var(--surface);border:1.5px solid <?= $cardBorder ?>;border-radius:14px;overflow:hidden;"
         data-truck-ref="<?= htmlspecialchars($truckRefText) ?>"
         data-area-ref="<?= htmlspecialchars($areaRefText) ?>">

      <!-- Card header -->
      <div style="display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:var(--hover);flex-wrap:wrap;border-bottom:1px solid var(--border);">
        <span class="plate" style="font-size:.85rem;"><?= htmlspecialchars($plate) ?></span>
        <?= deptBadge($dept) ?>
        <?php if ($vtype): ?>
        <span style="background:rgba(99,102,241,.12);color:#4f46e5;border:1px solid rgba(99,102,241,.3);padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($vtype) ?></span>
        <?php endif; ?>
        <span style="<?= $bracketStyle ?>;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars(match($freqBkt) { 'HIGH'=>'Daily Refueler', 'MID'=>'Weekly Refueler', default=>'Occasional Refueler' }) ?></span>
        <?= flagBadge($worstFlag) ?>
        <!-- Truck baseline stat pills -->
        <span style="font-size:.72rem;color:var(--text-muted);margin-left:auto;">
          <span style="font-weight:700;color:var(--text-secondary)"><?= $totalRefs ?></span> refuels
          &nbsp;·&nbsp; Avg <span style="font-weight:700;color:var(--teal)"><?= fmt($truckAvg) ?> L</span>
          &nbsp;·&nbsp; Range <span style="font-weight:700;"><?= fmt($truckMin) ?>–<?= fmt($truckMax) ?> L</span>
        </span>
      </div>

      <!-- Records table inside card -->
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
            // Build popover reference lines for this badge
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
    </div><!-- /stack cards -->
    <?php endif; ?>

    <?php elseif ($tab === 'checklist'): ?>
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
        <tr class="<?= $rowClass ?>"
            data-status="<?= $refueled ? 'REFUELED' : 'NOT REFUELED' ?>"
            data-plate="<?= htmlspecialchars($row['Plate Number'] ?? '') ?>"
            data-dept="<?= htmlspecialchars($row['Department'] ?? '') ?>"
            data-driver="<?= htmlspecialchars($row['Sched. Driver'] ?? '') ?>"
            data-area="<?= htmlspecialchars($row['Sched. Area'] ?? '') ?>">
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

    <?php elseif ($tab === 'report'): ?>
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
        <th onclick="sortTable(2)">Fuel Date <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)">Fuel Time <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)" class="right">Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)" class="right">Price/Liter <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)">Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)">Driver <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)">INV # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)">Supplier <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="10"><div class="empty-state"><span class="icon">📭</span><p>No records found. Try adjusting the filters.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
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
    <?php if ($totalPages > 1): ?>
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
// ── Department Dropdown Toggle ────────────────────────────────
const deptBtn  = document.getElementById('deptDropBtn');
const deptMenu = document.getElementById('deptDropMenu');
if (deptBtn && deptMenu) {
    deptBtn.addEventListener('click', e => {
        e.stopPropagation();
        deptMenu.classList.toggle('open');
    });
    document.addEventListener('click', () => deptMenu.classList.remove('open'));
}

// ── FULL DATA (all rows serialized from PHP, dates formatted) ─────────
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

// ── ACTIVE DATASET ────────────────────────────────────────────
const _rowLimit  = 20;
let _filteredData = null; // null = no active search

function _activeData() {
    return _filteredData !== null ? _filteredData : _allData;
}

// ── SEARCH: filter in-memory, rebuild rows preserving colors ──
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
                const badge    = refueled
                    ? `<span class='badge badge-everyday'>&#10003; Refueled</span>`
                    : `<span class='badge badge-norefuel'>&#10007; Not Refueled</span>`;
                const dash = `<span class="text-muted">&#8212;</span>`;
                const lit  = (row['Liters'] != null && refueled) ? parseFloat(row['Liters']).toFixed(2) + ' L' : '&#8212;';
                const amt  = (row['Amount'] != null && refueled)
                    ? '&#8369;' + parseFloat(row['Amount']).toLocaleString('en', {minimumFractionDigits:2})
                    : '&#8212;';
                return `<tr class="${cls}">
                    <td class="right mono dim bold">${row['Day'] ?? '&#8212;'}</td>
                    <td class="mono dim">${row['Date'] ?? '&#8212;'}</td>
                    <td><span class="plate">${row['Plate Number'] ?? '&#8212;'}</span></td>
                    <td><span class="dept dept-default">${row['Department'] ?? '&#8212;'}</span></td>
                    <td class="dim">${row['Vehicle Type'] ?? '&#8212;'}</td>
                    <td class="dim">${row['Sched. Driver'] ?? '&#8212;'}</td>
                    <td class="dim">${row['Sched. Area'] ?? '&#8212;'}</td>
                    <td class="mono dim">${refueled ? (row['INV #'] ?? '&#8212;') : dash}</td>
                    <td class="right mono bold">${lit}</td>
                    <td class="right mono bold">${amt}</td>
                    <td>${badge}</td>
                </tr>`;
            }
            // Usage Report: styled cells matching current PHP render
            if (_tabName === 'report') {
                const fmtL = v => (v != null && v !== '') ? parseFloat(v).toFixed(2) + ' L' : '&#8212;';
                const fmtP = v => (v != null && v !== '') ? '&#8369;' + parseFloat(v).toFixed(2) : '&#8212;';
                const peso = v => (v != null && v !== '') ? '&#8369;' + parseFloat(v).toLocaleString('en',{minimumFractionDigits:2}) : '&#8212;';
                return `<tr>
                    <td><span class="plate">${row['Plate Number'] ?? '&#8212;'}</span></td>
                    <td><span class="dept dept-default">${row['Department'] ?? '&#8212;'}</span></td>
                    <td class="mono dim">${row['Fuel Date'] ?? '&#8212;'}</td>
                    <td class="right mono bold" style="color:var(--teal)">${fmtL(row['Liters'])}</td>
                    <td class="right mono dim">${fmtP(row['Price/Liter'])}</td>
                    <td class="right mono bold">${peso(row['Amount'])}</td>
                    <td class="dim">${row['Area'] ?? '&#8212;'}</td>
                    <td class="dim">${row['Driver'] ?? '&#8212;'}</td>
                    <td class="mono dim">${row['INV #'] ?? '&#8212;'}</td>
                    <td class="dim">${row['Supplier'] ?? '&#8212;'}</td>
                </tr>`;
            }
            // Other tabs: plain cells
            const keys = Object.keys(row);
            return `<tr>${keys.map(k => `<td>${row[k] !== null && row[k] !== undefined ? row[k] : '&#8212;'}</td>`).join('')}</tr>`;
        }).join('');
    }

    const info = document.querySelector('.pagination-info');
    if (info) info.innerHTML = `Showing <strong>1&#8211;${Math.min(_rowLimit, _filteredData.length)}</strong> of <strong>${_filteredData.length}</strong> matching rows`;
}

// ── TABLE SORT (sorts visible DOM rows) ──────────────────────
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
        if (icon) icon.textContent = i === col ? (asc ? '\u2191' : '\u2193') : '\u21C5';
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

// ── PROGRESS BARS ANIMATE ON LOAD ────────────────────────────
document.querySelectorAll('.progress-fill').forEach(el => {
    const w = el.style.width;
    el.style.width = '0';
    setTimeout(() => { el.style.width = w; }, 80);
});

// ── EXPORT HELPER: get data + safety cap ─────────────────────
function _getExportData() {
    const src = _activeData();
    if (src.length > 5000) {
        const ok = confirm(
            `You are about to export ${src.length.toLocaleString()} rows.\n` +
            `This may be slow or cause the browser to freeze.\n\n` +
            `Tip: Apply a date or department filter first to reduce the data.\n\n` +
            `Proceed anyway?`
        );
        if (!ok) return null;
    }
    return src;
}

function _buildFilename(ext) {
    const p     = new URLSearchParams(window.location.search);
    const parts = ['Fuel Dashboard', _tabName];
    const dFrom = p.get('date_from') || '';
    const dTo   = p.get('date_to')   || '';
    const vtype = p.get('vtype')     || '';
    const plate = p.get('plate')     || '';
    const driver= p.get('driver')    || '';
    const area  = p.get('area')      || '';
    // Department comes from session — injected via PHP
    const dept  = <?php echo json_encode($_SESSION['Department'] ?? ''); ?>;
    if (dFrom) parts.push(dFrom + (dTo ? '_to_' + dTo : ''));
    if (dept)  parts.push(dept);
    if (vtype) parts.push(vtype);
    if (plate) parts.push(plate);
    if (driver) parts.push(driver);
    if (area)  parts.push(area);
    if (_filteredData !== null) parts.push('filtered');
    return parts.join('_').replace(/[^a-zA-Z0-9_-]/g, '_') + '.' + ext;
}

// ── EXPORT CSV ────────────────────────────────────────────────
function exportCSV() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to export.'); return; }
    const headers  = Object.keys(rows[0]);
    const csvLines = [headers.map(h => '"' + h + '"').join(',')];
    rows.forEach(row => {
        csvLines.push(headers.map(h => {
            const v = row[h] !== null && row[h] !== undefined ? String(row[h]) : '';
            return '"' + v.replace(/"/g, '""')+'"';
        }).join(','));
    });
    const url = URL.createObjectURL(new Blob(['\uFEFF' + csvLines.join('\n')], {type:'text/csv;charset=utf-8;'}));
    const a   = document.createElement('a');
    a.href = url; a.download = _buildFilename('csv');
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
}

// ── EXPORT EXCEL ──────────────────────────────────────────────
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

// ── PRINT TABLE ───────────────────────────────────────────────
// Builds full table from _allData so ALL rows print, not just current page.
// Falls back to DOM clone for anomaly tab (card layout).
function printTable() {
    const rows = _getExportData();
    if (!rows || rows.length === 0) { alert('No data to print.'); return; }

    const title  = document.querySelector('.table-title')?.innerText?.replace(/[\u21C5\u2191\u2193]/g,'').trim() || 'Report';
    const p      = new URLSearchParams(window.location.search);
    const filters = [];
    const dFrom  = p.get('date_from'), dTo = p.get('date_to');
    const dept   = <?php echo json_encode($_SESSION['Department'] ?? ''); ?>;
    const vtype  = p.get('vtype')  || '';
    const plate  = p.get('plate')  || '';
    const driver = p.get('driver') || '';
    const area   = p.get('area')   || '';
    if (dFrom || dTo) filters.push('Date: ' + (dFrom||'\u2014') + ' \u2192 ' + (dTo||'\u2014'));
    if (dept)   filters.push('Dept: ' + dept);
    if (vtype)  filters.push('Type: ' + vtype);
    if (plate)  filters.push('Plate: ' + plate);
    if (driver) filters.push('Driver: ' + driver);
    if (area)   filters.push('Area: ' + area);
    if (_filteredData !== null) filters.push('Search filtered');

    // ── Anomaly tab uses card layout — clone DOM directly ──────
    if (_tabName === 'anomaly') {
        const cardsEl = document.querySelector('.anomaly-cards-wrap') || document.querySelector('[data-anomaly-cards]');
        const w2 = window.open('', '_blank', 'width=1200,height=800');
        const liveTable = document.getElementById('mainTable');
        const content = liveTable ? liveTable.cloneNode(true).outerHTML : '<p>No anomaly data.</p>';
        w2.document.write(`<!DOCTYPE html><html><head><title>${title}</title>
            <style>
                body { font-family:Arial,sans-serif; padding:16px; color:#0f172a; font-size:11px; }
                h2   { margin:0 0 3px; font-size:15px; color:#1e40af; }
                p    { margin:0 0 12px; font-size:10px; color:#64748b; }
                table { border-collapse:collapse; width:100%; margin-bottom:8px; }
                th { background:#1e40af;color:#fff;padding:4px 6px;font-size:9px;text-align:left;border:1px solid #1e3a8a; }
                td { padding:4px 6px;border:1px solid #e2e8f0;font-size:9px;vertical-align:middle; }
                .plate { background:#e0f2fe;color:#0369a1;border-radius:3px;padding:1px 4px;font-size:8px;font-weight:700; }
                .badge { border-radius:3px;padding:1px 4px;font-size:8px;font-weight:700; }
                .badge-critical { background:#fee2e2;color:#991b1b; }
                .badge-high     { background:#ffedd5;color:#9a3412; }
                .badge-watch    { background:#fef9c3;color:#713f12; }
                @media print { body { padding:4px; } }
            </style></head><body>
            <h2>Admin Dashboard &middot; Tradewell Fleet Monitoring</h2>
            <p>${title}${filters.length ? ' &middot; '+filters.join(' &middot; ') : ''} &middot; ${rows.length} flagged records &middot; ${new Date().toLocaleString()}</p>
            ${content}
        </body></html>`);
        w2.document.close();
        setTimeout(() => w2.print(), 400);
        return;
    }

    // ── All other tabs — build full table from _allData ────────
    const liveTable = document.getElementById('mainTable');
    if (!liveTable) { alert('Table not found.'); return; }

    // Get headers from live DOM thead
    const theadClone = liveTable.querySelector('thead').cloneNode(true);
    theadClone.querySelectorAll('.sort-icon').forEach(el => el.remove());
    theadClone.querySelectorAll('th').forEach(th => {
        th.style.cssText = 'background:#1e40af;color:#fff;padding:5px 7px;font-size:10px;text-align:left;border:1px solid #1e3a8a;';
    });

    // Build tbody from ALL data rows (not just current page DOM)
    const allRows = _activeData();
    const tbodyHTML = allRows.map(row => {
        if (_isChecklist) {
            const refueled = String(row['Status'] ?? '').toUpperCase() === 'REFUELED';
            const bg = refueled ? 'background:#dcfce7;' : 'background:#fee2e2;';
            const dash = '&#8212;';
            const lit  = (row['Liters'] != null && refueled) ? parseFloat(row['Liters']).toFixed(2) + ' L' : dash;
            const amt  = (row['Amount'] != null && refueled) ? '&#8369;' + parseFloat(row['Amount']).toLocaleString('en',{minimumFractionDigits:2}) : dash;
            const badge = refueled ? '<span style="background:#dcfce7;color:#166534;border-radius:3px;padding:1px 4px;font-size:9px;">&#10003; Refueled</span>'
                                   : '<span style="background:#fee2e2;color:#991b1b;border-radius:3px;padding:1px 4px;font-size:9px;">&#10007; Not Refueled</span>';
            return `<tr style="${bg}">
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Day']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Date']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;"><span style="background:#e0f2fe;color:#0369a1;border-radius:3px;padding:1px 4px;font-size:9px;font-weight:700;">${row['Plate Number']??dash}</span></td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Department']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Vehicle Type']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Sched. Driver']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${row['Sched. Area']??dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${refueled?(row['INV #']??dash):dash}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;text-align:right;">${lit}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;text-align:right;">${amt}</td>
                <td style="padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;">${badge}</td>
            </tr>`;
        }
        // All other tabs — generic row with inline styles
        const tdStyle = 'padding:4px 7px;border:1px solid #e2e8f0;font-size:10px;vertical-align:middle;';
        return '<tr>' + Object.values(row).map(v => `<td style="${tdStyle}">${v !== null && v !== undefined ? v : '&#8212;'}</td>`).join('') + '</tr>';
    }).join('');

    const tableHTML = `<table style="border-collapse:collapse;width:100%;"><thead>${theadClone.innerHTML}</thead><tbody>${tbodyHTML}</tbody></table>`;

    const w = window.open('', '_blank', 'width=1200,height=800');
    w.document.write(`<!DOCTYPE html><html><head><title>${title}</title>
        <style>
            body  { font-family:Arial,sans-serif; padding:16px; color:#0f172a; }
            h2    { margin:0 0 3px; font-size:15px; color:#1e40af; }
            p     { margin:0 0 12px; font-size:10px; color:#64748b; }
            table { border-collapse:collapse; width:100%; page-break-inside:auto; }
            tr    { page-break-inside:avoid; }
            .plate { background:#e0f2fe; color:#0369a1; border-radius:4px; padding:1px 5px; font-size:9px; font-weight:700; }
            .dept  { border-radius:4px; padding:1px 5px; font-size:9px; font-weight:600; }
            .badge { border-radius:4px; padding:1px 5px; font-size:9px; font-weight:600; }
            .badge-everyday { background:#dcfce7; color:#166534; }
            .badge-norefuel { background:#fee2e2; color:#991b1b; }
            .text-muted     { color:#94a3b8; font-style:italic; }
            @media print { body { padding:6px; } }
        </style></head><body>
        <h2>Admin Dashboard &middot; Tradewell Fleet Monitoring</h2>
        <p>${title}${filters.length ? ' &middot; '+filters.join(' &middot; ') : ''} &middot; ${allRows.length} rows &middot; ${new Date().toLocaleString()}</p>
        ${tableHTML}
    </body></html>`);
    w.document.close();
    setTimeout(() => w.print(), 400);
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
.filter-group {
  display: flex;
  flex-direction: column;
  gap: .2rem;
}
@keyframes modalIn {
  from { opacity:0; transform:scale(.95) translateY(8px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}
.btn-areas {
  display: inline-flex; align-items: center; gap: .3rem;
  font-size: .72rem; font-weight: 700;
  padding: .2rem .6rem; border-radius: 20px;
  background: rgba(59,130,246,.12);
  color: #3b82f6;
  border: 1px solid rgba(59,130,246,.3);
  cursor: pointer; transition: background .15s;
  font-family: inherit;
  white-space: nowrap;
}
.btn-areas:hover { background: rgba(59,130,246,.22); }
.area-chip {
  display: inline-block;
  padding: .25rem .65rem;
  background: var(--hover);
  border: 1px solid var(--border);
  border-radius: 20px;
  font-size: .78rem; font-weight: 600;
  color: var(--text-secondary);
}
</style>

<script>
function showAreas(plate, areas) {
  document.getElementById('areasModalPlate').textContent = plate;
  const list = document.getElementById('areasModalList');
  list.innerHTML = areas.split(', ')
    .filter(a => a.trim())
    .map(a => `<span class="area-chip"><i class="bi bi-geo-alt"></i> ${a.trim()}</span>`)
    .join('');
  const modal = document.getElementById('areasModal');
  modal.style.display = 'flex';
}
function closeAreas() {
  document.getElementById('areasModal').style.display = 'none';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAreas(); });
</script>

<!-- ── Triggered-By Reference Popover ─────────────────────── -->
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
    if (currentBtn === btn && pop.style.display !== 'none') {
      closeRefPop(); return;
    }
    currentBtn = btn;

    const raw   = btn.getAttribute('data-ref') || '';
    const lines = raw.split('\n').filter(l => l.trim());
    const linesEl = document.getElementById('refPopLines');

    linesEl.innerHTML = lines.map(l => {
      const isSelf      = l.includes('◀ this truck');
      const isSeparator = l.startsWith('—');
      if (isSeparator) {
        return `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);padding:.25rem 0 .1rem;">${l.replace(/—/g,'').trim()}</div>`;
      }
      const bg = isSelf
        ? 'background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);'
        : 'background:var(--hover);border:1px solid transparent;';
      const weight = isSelf ? 'font-weight:700;' : '';
      return `<div style="padding:.3rem .5rem;border-radius:7px;font-size:.76rem;font-family:'DM Mono',monospace;${bg}${weight}">${l.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
    }).join('');

    pop.style.display = 'block';

    const btnRect = btn.getBoundingClientRect();
    const pw = pop.offsetWidth;
    const ph = pop.offsetHeight;
    const margin = 8;

    // Default: above the badge
    let left = btnRect.left;
    let top  = btnRect.top - ph - 6;

    // Flip below if not enough room above
    if (top < margin) top = btnRect.bottom + 6;

    // Flip left if overflows right edge
    if (left + pw > window.innerWidth - margin) left = btnRect.right - pw;
    if (left < margin) left = margin;

    pop.style.position = 'fixed';
    pop.style.left = left + 'px';
    pop.style.top  = top  + 'px';
  };

  window.closeRefPop = function() {
    pop.style.display = 'none';
    currentBtn = null;
  };

  document.addEventListener('click', function(e) {
    if (!pop.contains(e.target) && !e.target.closest('.trig-badge')) {
      closeRefPop();
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRefPop();
  });
})();
</script>

</body>
</html>
<?php sqlsrv_close($conn); ?>