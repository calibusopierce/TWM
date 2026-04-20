<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);

// ════════════════════════════════════════════════════════════════
// VEHICLE CLASSIFICATION (mirrors fuel_dashboard-Current.php)
// ════════════════════════════════════════════════════════════════
$ALL_TYPES = "'ELF','CANTER','FORWARD','FIGHTER','CAR','MOTOR','L300','VAN','CROSS WIND'";
$modeVtypeWhere = "AND v.Vehicletype IN ($ALL_TYPES)";

// ── DATE FILTER ───────────────────────────────────────────────
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';
$dateActive = ($dateFrom !== '' || $dateTo !== '');

$anyFilterApplied = ($dateFrom !== '' || $dateTo !== ''
    || (isset($_GET['dept'])  && $_GET['dept']  !== '')
    || (isset($_GET['vtype']) && $_GET['vtype'] !== '')
    || (isset($_GET['plate']) && $_GET['plate'] !== '')
    || (isset($_GET['area'])  && $_GET['area']  !== ''));

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

// ── DEPARTMENT (user-selectable — matches current dashboard) ──
$selDept      = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : ($_SESSION['Department'] ?? '');
$deptActive   = ($selDept !== '');
$_selDeptSafe = str_replace("'", "''", $selDept);
$deptWhereFuel = $deptActive ? "AND (
    NULLIF(f.Department,'') = '$_selDeptSafe'
    OR (NULLIF(f.Department,'') IS NULL AND EXISTS (
        SELECT 1 FROM [dbo].[TruckSchedule] tsx
        WHERE tsx.PlateNumber = f.PlateNumber
          AND tsx.Department = '$_selDeptSafe'
    ))
)" : '';

// ── OTHER FILTERS ─────────────────────────────────────────────
$selVtype      = isset($_GET['vtype']) && $_GET['vtype'] !== '' ? trim($_GET['vtype']) : '';
$vtypeActive   = ($selVtype !== '');
$_selVtypeSafe = str_replace("'", "''", $selVtype);
$vtypeWhereV   = $vtypeActive ? "AND v.Vehicletype = '$_selVtypeSafe'" : '';

$selPlate     = isset($_GET['plate']) && $_GET['plate'] !== '' ? trim($_GET['plate']) : '';
$plateActive  = ($selPlate !== '');
$_plateSafe   = str_replace("'", "''", $selPlate);
$plateWhereF  = $plateActive ? "AND f.PlateNumber LIKE '%$_plateSafe%'" : '';

$selArea      = isset($_GET['area']) && $_GET['area'] !== '' ? trim($_GET['area']) : '';
$areaActive   = ($selArea !== '');
$_areaSafe    = str_replace("'", "''", $selArea);
$areaWhereF   = $areaActive ? "AND f.Area LIKE '%$_areaSafe%'" : '';

$anyFilter = $dateActive || $deptActive || $vtypeActive || $plateActive || $areaActive;

// ── COMBINED filterSQL (Tbl_fuel queries) ─────────────────────
$filterSQL = '';
if ($dateFrom !== '' && $dateTo !== '') {
    $filterSQL .= " AND f.Fueldate BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($dateFrom !== '') {
    $filterSQL .= " AND f.Fueldate >= '$dateFrom'";
} elseif ($dateTo !== '') {
    $filterSQL .= " AND f.Fueldate <= '$dateTo'";
}
if ($vtypeActive) $filterSQL .= " AND v.Vehicletype = '$_selVtypeSafe'";
if ($plateActive) $filterSQL .= " AND f.PlateNumber LIKE '%$_plateSafe%'";
if ($areaActive)  $filterSQL .= " AND f.Area LIKE '%$_areaSafe%'";

// ── HELPER ────────────────────────────────────────────────────
function runQuery($conn, $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

// ── LOOKUPS ───────────────────────────────────────────────────
$deptList = runQuery($conn, "SELECT DISTINCT Department FROM [dbo].[Vehicle] WHERE Active = 1 AND Department IS NOT NULL ORDER BY Department");
$vtypeList = runQuery($conn, "
    SELECT DISTINCT Vehicletype FROM [dbo].[Vehicle]
    WHERE Active = 1 AND Vehicletype IN ('CANTER','ELF','FIGHTER','FORWARD','L300','CAR','MOTOR','CROSS WIND','VAN')
    ORDER BY Vehicletype");

// ════════════════════════════════════════════════════════════════
// CHART 1 — Fuel Consumption per Vehicle
// ════════════════════════════════════════════════════════════════
$chart1_data = runQuery($conn, "
    SELECT
        f.PlateNumber,
        COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
        v.Vehicletype,
        v.FuelType,
        COUNT(f.FuelID)          AS TotalRefuels,
        ROUND(SUM(f.Liters), 2)  AS TotalLiters,
        ROUND(AVG(f.Liters), 2)  AS AvgLiters,
        ROUND(SUM(f.Amount), 2)  AS TotalAmount,
        ROUND(AVG(f.Amount), 2)  AS AvgAmount
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype, v.FuelType
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 2 — Daily Fuel Trend
// ════════════════════════════════════════════════════════════════
$chart2_data = runQuery($conn, "
    SELECT
        CONVERT(VARCHAR(10), f.Fueldate, 120) AS FuelDay,
        ROUND(SUM(f.Liters), 2)       AS DayLiters,
        ROUND(SUM(f.Amount), 2)       AS DayAmount,
        COUNT(f.FuelID)               AS DayRefuels,
        COUNT(DISTINCT f.PlateNumber) AS DayTrucks
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY CONVERT(VARCHAR(10), f.Fueldate, 120)
    ORDER BY FuelDay ASC
");

// ════════════════════════════════════════════════════════════════
// CHART 3 — Area Breakdown
// ════════════════════════════════════════════════════════════════
$chart3_data = runQuery($conn, "
    SELECT
        f.Area,
        COUNT(f.FuelID)               AS TotalRefuels,
        ROUND(SUM(f.Liters), 2)       AS TotalLiters,
        ROUND(AVG(f.Liters), 2)       AS AvgLiters,
        ROUND(SUM(f.Amount), 2)       AS TotalAmount,
        COUNT(DISTINCT f.PlateNumber) AS UniqueTrucks
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY f.Area
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 4 — Refuel Coverage (30-day or filtered range)
// ════════════════════════════════════════════════════════════════
if ($dateActive) {
    $chart4_sql = "
    DECLARE @S DATE = '$baseFrom'; DECLARE @E DATE = '$baseTo';
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT f.PlateNumber,
            COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
            v.Vehicletype,
            COUNT(DISTINCT f.Fueldate) AS DaysRefueled,
            COUNT(f.FuelID) AS TotalRefuels,
            ROUND(SUM(f.Liters),2) AS TotalLiters,
            ROUND(SUM(f.Amount),2) AS TotalAmount
        FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE f.Fueldate BETWEEN @S AND @E $modeVtypeWhere $deptWhereFuel
        GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
    )
    SELECT ra.PlateNumber, ra.Department, ra.Vehicletype,
        ISNULL(ra.DaysRefueled,0) AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0) AS DaysMissed,
        @TD AS TotalDays,
        ISNULL(ra.TotalRefuels,0) AS TotalRefuels,
        ISNULL(ra.TotalLiters,0) AS TotalLiters,
        ISNULL(ra.TotalAmount,0) AS TotalAmount
    FROM RA ra ORDER BY ra.TotalLiters DESC";
} else {
    $chart4_sql = "
    DECLARE @S DATE = DATEADD(DAY,-29,CAST(GETDATE() AS DATE)); DECLARE @E DATE = CAST(GETDATE() AS DATE);
    DECLARE @TD INT = DATEDIFF(DAY,@S,@E)+1;
    WITH RA AS (
        SELECT f.PlateNumber,
            COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
            v.Vehicletype,
            COUNT(DISTINCT f.Fueldate) AS DaysRefueled,
            COUNT(f.FuelID) AS TotalRefuels,
            ROUND(SUM(f.Liters),2) AS TotalLiters,
            ROUND(SUM(f.Amount),2) AS TotalAmount
        FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE f.Fueldate BETWEEN @S AND @E $modeVtypeWhere $deptWhereFuel
        GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
    )
    SELECT ra.PlateNumber, ra.Department, ra.Vehicletype,
        ISNULL(ra.DaysRefueled,0) AS DaysRefueled,
        @TD - ISNULL(ra.DaysRefueled,0) AS DaysMissed,
        @TD AS TotalDays,
        ISNULL(ra.TotalRefuels,0) AS TotalRefuels,
        ISNULL(ra.TotalLiters,0) AS TotalLiters,
        ISNULL(ra.TotalAmount,0) AS TotalAmount
    FROM RA ra ORDER BY ra.TotalLiters DESC";
}
$chart4_data = runQuery($conn, $chart4_sql);

// ════════════════════════════════════════════════════════════════
// CHART 5 — Top 10 Consumers
// ════════════════════════════════════════════════════════════════
$chart5_data = runQuery($conn, "
    SELECT TOP 10
        f.PlateNumber,
        COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
        v.Vehicletype,
        COUNT(f.FuelID)          AS TotalRefuels,
        ROUND(SUM(f.Liters), 2)  AS TotalLiters,
        ROUND(AVG(f.Liters), 2)  AS AvgLiters,
        ROUND(SUM(f.Amount), 2)  AS TotalAmount
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 6 — By Vehicle Type
// ════════════════════════════════════════════════════════════════
$chart6_data = runQuery($conn, "
    SELECT
        v.Vehicletype,
        ROUND(SUM(f.Liters), 2)          AS TotalLiters,
        ROUND(SUM(f.Amount), 2)          AS TotalAmount,
        COUNT(f.FuelID)                  AS TotalRefuels,
        COUNT(DISTINCT f.PlateNumber)    AS UniqueTrucks,
        ROUND(AVG(f.Liters), 2)          AS AvgLiters
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      AND v.Vehicletype IS NOT NULL
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY v.Vehicletype
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 7 (NEW) — Department Breakdown
// ════════════════════════════════════════════════════════════════
$chart7_data = runQuery($conn, "
    SELECT
        COALESCE(NULLIF(f.Department,''), ts.Department, 'Unassigned') AS Department,
        ROUND(SUM(f.Liters), 2)          AS TotalLiters,
        ROUND(SUM(f.Amount), 2)          AS TotalAmount,
        COUNT(f.FuelID)                  AS TotalRefuels,
        COUNT(DISTINCT f.PlateNumber)    AS UniqueTrucks,
        ROUND(AVG(f.Liters), 2)          AS AvgLiters
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $filterSQL
    GROUP BY COALESCE(NULLIF(f.Department,''), ts.Department, 'Unassigned')
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 8 (NEW) — Anomaly Summary by Flag Level
// ════════════════════════════════════════════════════════════════
$chart8_data = runQuery($conn, "
    WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
    AllRecords AS (
        SELECT f.FuelID, f.PlateNumber, f.Area, ROUND(f.Liters,2) AS Liters
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
                 WHEN tb.TotalRefuels/NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0)>=4  THEN 'MID'
                 ELSE 'LOW' END AS FreqBracket
        FROM TruckBaseline tb
    ),
    BracketAreaAvg AS (
        SELECT tb.Area, tb.FreqBracket, ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
        FROM TruckBracket tb GROUP BY tb.Area, tb.FreqBracket
    ),
    Flagged AS (
        SELECT ar.PlateNumber, ar.Area,
            CASE
                WHEN ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>1.0
                  OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>2.0 THEN 'CRITICAL'
                WHEN ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>0.5
                  OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>1.0 THEN 'HIGH'
                ELSE 'WATCH'
            END AS FlagLevel
        FROM AllRecords ar
        INNER JOIN TruckBracket tb ON tb.PlateNumber = ar.PlateNumber AND tb.Area = ar.Area
        INNER JOIN BracketAreaAvg ba ON ba.Area = ar.Area AND ba.FreqBracket = tb.FreqBracket
        WHERE ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>0.5
           OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>0.5
    )
    SELECT FlagLevel, COUNT(*) AS FlagCount, COUNT(DISTINCT PlateNumber) AS UniqueTrucks
    FROM Flagged
    GROUP BY FlagLevel
    ORDER BY CASE FlagLevel WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 ELSE 3 END
");

// ════════════════════════════════════════════════════════════════
// CHART 9 (NEW) — Fuel Price per Liter Trend
// ════════════════════════════════════════════════════════════════
$chart9_data = runQuery($conn, "
    SELECT
        CONVERT(VARCHAR(10), f.Fueldate, 120) AS FuelDay,
        ROUND(AVG(f.Price), 2) AS AvgPricePerLiter,
        ROUND(MIN(f.Price), 2) AS MinPrice,
        ROUND(MAX(f.Price), 2) AS MaxPrice,
        COUNT(f.FuelID)        AS Transactions
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Price IS NOT NULL AND f.Price > 0
      $modeVtypeWhere $deptWhereFuel $filterSQL
    GROUP BY CONVERT(VARCHAR(10), f.Fueldate, 120)
    ORDER BY FuelDay ASC
");

// ════════════════════════════════════════════════════════════════
// STAT CARDS
// ════════════════════════════════════════════════════════════════
$statRow = runQuery($conn, "
    SELECT
        COUNT(DISTINCT f.PlateNumber) AS TotalTrucks,
        ROUND(SUM(f.Liters), 2)       AS TotalLiters,
        ROUND(SUM(f.Amount), 2)       AS TotalAmount,
        COUNT(f.FuelID)               AS TotalRefuels
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $modeVtypeWhere $deptWhereFuel $filterSQL");

$totalTrucks  = $statRow[0]['TotalTrucks']  ?? 0;
$totalLiters  = $statRow[0]['TotalLiters']  ?? 0;
$totalAmount  = $statRow[0]['TotalAmount']  ?? 0;
$totalRefuels = $statRow[0]['TotalRefuels'] ?? 0;
$avgPerRefuel = $totalRefuels > 0 ? round($totalLiters / $totalRefuels, 1) : 0;

$anomalyCountRow = runQuery($conn, "
    WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
    AllRecords AS (
        SELECT f.FuelID, f.PlateNumber, f.Area, ROUND(f.Liters,2) AS Liters
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

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fuel Graphs — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<style>
.graphs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    margin-top: 1.25rem;
}
.graphs-grid .chart-full { grid-column: 1 / -1; }

.chart-card {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    position: relative;
    overflow: hidden;
    transition: box-shadow .2s;
}
.chart-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); }

.chart-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: .85rem;
    flex-wrap: wrap;
    gap: .5rem;
}
.chart-card-title {
    font-family: 'Sora', sans-serif;
    font-size: .9rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: .4rem;
}
.chart-card-sub {
    font-size: .72rem;
    color: var(--text-muted);
    margin-top: .18rem;
}
.chart-toggle-btns { display: flex; gap: .3rem; flex-wrap: wrap; flex-shrink: 0; }
.chart-toggle-btns button {
    font-size: .7rem;
    font-weight: 700;
    padding: .2rem .55rem;
    border-radius: 20px;
    border: 1.5px solid var(--border);
    background: var(--hover);
    color: var(--text-muted);
    cursor: pointer;
    transition: all .15s;
    font-family: inherit;
    white-space: nowrap;
}
.chart-toggle-btns button.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}
.chart-wrap { position: relative; width: 100%; }

.chart-kpis {
    display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: .7rem;
}
.chart-kpi {
    background: var(--hover); border: 1px solid var(--border);
    border-radius: 20px; padding: .2rem .6rem;
    font-size: .7rem; font-weight: 700;
    color: var(--text-secondary); white-space: nowrap;
    display: flex; align-items: center; gap: .3rem;
}
.chart-kpi .kv { color: var(--primary-light); font-family: 'DM Mono', monospace; }

.area-legend {
    display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .7rem;
}
.area-legend-item {
    display: flex; align-items: center; gap: .28rem;
    font-size: .7rem; color: var(--text-secondary);
    background: var(--hover); border: 1px solid var(--border);
    border-radius: 20px; padding: .12rem .45rem;
}
.area-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

.no-data {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; height: 180px;
    color: var(--text-muted); font-size: .85rem; gap: .4rem;
}
.section-divider {
    font-family: 'Sora', sans-serif;
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; color: var(--text-muted);
    padding: .5rem 0 .1rem;
    border-bottom: 1px solid var(--border);
    margin: 1.5rem 0 0;
}
.flag-legend { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: .65rem; }
.flag-pill {
    font-size: .7rem; font-weight: 700; padding: .15rem .55rem;
    border-radius: 20px; border: 1px solid; display: flex; align-items: center; gap: .25rem;
}
.filter-group { display: flex; flex-direction: column; gap: .2rem; }
.filter-select-label { font-size: .68rem; font-weight: 600; color: var(--text-muted); display: flex; align-items: center; gap: .3rem; }

@media (max-width: 768px) {
    .graphs-grid { grid-template-columns: 1fr; }
    .chart-full  { grid-column: 1; }
}
</style>
</head>
<body>

<?php $topbar_page = 'fuel'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ══ HEADER ══════════════════════════════════════════════ -->
  <div class="page-header">
    <div>
      <div class="page-title">Fuel <span>Analytics</span> Graphs</div>
      <div class="page-badge">
        📅 <?= $anyFilterApplied ? 'Filtered: '.htmlspecialchars($baseFrom).' → '.htmlspecialchars($baseTo) : 'This Month: '.date('F Y') ?>
        · Live Data
      </div>
    </div>
  </div>

  <!-- ══ STAT CARDS ══════════════════════════════════════════ -->
  <div class="stats-row">
    <div class="stat-card">
      <span class="stat-icon">🚛</span>
      <div class="stat-label">Total Vehicles</div>
      <div class="stat-value"><?= number_format($totalTrucks) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⛽</span>
      <div class="stat-label">Total Liters<?= $dateActive ? ' <span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value teal"><?= number_format($totalLiters, 0) ?> L</div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">💰</span>
      <div class="stat-label">Total Amount<?= $dateActive ? ' <span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value accent">₱<?= number_format($totalAmount, 0) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">🔄</span>
      <div class="stat-label">Total Refuels</div>
      <div class="stat-value"><?= number_format($totalRefuels) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">📏</span>
      <div class="stat-label">Avg L / Refuel</div>
      <div class="stat-value"><?= number_format($avgPerRefuel, 1) ?> L</div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⚠️</span>
      <div class="stat-label">Anomaly Flags</div>
      <div class="stat-value red"><?= $anomalyCount ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">📍</span>
      <div class="stat-label">Areas Covered</div>
      <div class="stat-value"><?= count($chart3_data) ?></div>
    </div>
  </div>

  <!-- ══ FILTER BAR ══════════════════════════════════════════ -->
  <div class="date-filter-bar">
    <form method="GET" style="display:flex;flex-direction:column;gap:.6rem;width:100%;">
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
          <span class="filter-select-label"><i class="bi bi-building"></i> Department</span>
          <select name="dept" class="dept-select" style="min-width:130px;">
            <option value="">All Departments</option>
            <?php foreach ($deptList as $d):
                $dVal = $d['Department'];
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

        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-truck"></i> Vehicle Type</span>
          <select name="vtype" class="dept-select">
            <option value="">All Types</option>
            <optgroup label="🚛 Trucks">
              <?php foreach ($vtypeList as $vt): if (!in_array($vt['Vehicletype'], ['ELF','CANTER','FORWARD','FIGHTER'])) continue; ?>
              <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>><?= htmlspecialchars($vt['Vehicletype']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="🚗 Cars & Motors">
              <?php foreach ($vtypeList as $vt): if (!in_array($vt['Vehicletype'], ['CAR','MOTOR','L300','VAN','CROSS WIND'])) continue; ?>
              <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>><?= htmlspecialchars($vt['Vehicletype']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </div>

        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-tag"></i> Plate #</span>
          <input type="text" name="plate" class="date-input" style="width:120px;"
                 value="<?= htmlspecialchars($selPlate) ?>" placeholder="e.g. ABC 123">
        </div>
        <div class="filter-group">
          <span class="filter-select-label"><i class="bi bi-geo-alt"></i> Area</span>
          <input type="text" name="area" class="date-input" style="width:130px;"
                 value="<?= htmlspecialchars($selArea) ?>" placeholder="Area name">
        </div>

        <button type="submit" class="btn-apply"><i class="bi bi-search"></i> Apply</button>
        <?php if ($anyFilter): ?>
        <a href="graphs.php" class="btn-clear"><i class="bi bi-x-lg"></i> Clear</a>
        <?php endif; ?>
      </div>

      <?php if ($dateActive || $deptActive || $vtypeActive || $plateActive || $areaActive): ?>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
        <span style="font-size:.72rem;color:var(--text-muted);">Active:</span>
        <?php if ($dateActive): ?><span class="filter-active-badge"><i class="bi bi-calendar-check"></i> <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span><?php endif; ?>
        <?php if ($deptActive): ?><span class="filter-active-badge" style="background:rgba(16,185,129,.1);color:#065f46;border-color:#6ee7b7;"><i class="bi bi-building"></i> <?= htmlspecialchars($selDept) ?></span><?php endif; ?>
        <?php if ($vtypeActive): ?><span class="filter-active-badge" style="background:#f0fdf4;color:#166534;border-color:#86efac;"><i class="bi bi-truck"></i> <?= htmlspecialchars($selVtype) ?></span><?php endif; ?>
        <?php if ($plateActive): ?><span class="filter-active-badge" style="background:#e0f2fe;color:#0369a1;border-color:#7dd3fc;"><i class="bi bi-tag"></i> <?= htmlspecialchars($selPlate) ?></span><?php endif; ?>
        <?php if ($areaActive): ?><span class="filter-active-badge" style="background:#fff7ed;color:#c2410c;border-color:#fdba74;"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selArea) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ SECTION: CONSUMPTION OVERVIEW ══════════════════════ -->
  <div class="section-divider">📊 Consumption Overview</div>
  <div class="graphs-grid">

    <!-- Chart 1: Per-Vehicle Consumption -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">⛽ Fuel Consumption per Vehicle</div>
          <div class="chart-card-sub">Total liters consumed per plate — color-coded by vehicle type. Includes avg L/refuel and full cost breakdown on hover.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric1('liters')"  id="btn1L" class="active">Liters</button>
          <button onclick="switchMetric1('amount')"  id="btn1A">Amount</button>
          <button onclick="switchMetric1('refuels')" id="btn1R">Refuels</button>
          <button onclick="switchMetric1('avg')"     id="btn1V">Avg L</button>
        </div>
      </div>
      <?php
        $c1L = array_sum(array_column($chart1_data, 'TotalLiters'));
        $c1A = array_sum(array_column($chart1_data, 'TotalAmount'));
        $c1R = array_sum(array_column($chart1_data, 'TotalRefuels'));
      ?>
      <div class="chart-kpis">
        <div class="chart-kpi">Vehicles <span class="kv"><?= count($chart1_data) ?></span></div>
        <div class="chart-kpi">Total Liters <span class="kv"><?= number_format($c1L,0) ?> L</span></div>
        <div class="chart-kpi">Total Spend <span class="kv">₱<?= number_format($c1A,0) ?></span></div>
        <div class="chart-kpi">Total Refuels <span class="kv"><?= number_format($c1R) ?></span></div>
      </div>
      <?php if (empty($chart1_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:320px;"><canvas id="chart1"></canvas></div><?php endif; ?>
    </div>

    <!-- Chart 2: Daily Trend -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">📈 Daily Fuel Trend</div>
          <div class="chart-card-sub">Daily totals with dual-axis for liters & amount — shows unique trucks on site per day.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric2('both')"    id="btn2B" class="active">Liters + ₱</button>
          <button onclick="switchMetric2('liters')"  id="btn2L">Liters</button>
          <button onclick="switchMetric2('amount')"  id="btn2A">Amount</button>
          <button onclick="switchMetric2('refuels')" id="btn2R">Refuels</button>
          <button onclick="switchMetric2('trucks')"  id="btn2T">Trucks/Day</button>
        </div>
      </div>
      <?php if (empty($chart2_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:280px;"><canvas id="chart2"></canvas></div><?php endif; ?>
    </div>

  </div>

  <!-- ══ SECTION: BREAKDOWN ══════════════════════════════════ -->
  <div class="section-divider">🗂️ Breakdown — Area, Vehicle Type & Department</div>
  <div class="graphs-grid">

    <!-- Chart 3: Area Donut -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">📍 Fuel by Area</div>
          <div class="chart-card-sub">Distribution across delivery areas — percentage share included in legend.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric3('liters')"  id="btn3L" class="active">Liters</button>
          <button onclick="switchMetric3('amount')"  id="btn3A">Amount</button>
          <button onclick="switchMetric3('refuels')" id="btn3R">Refuels</button>
          <button onclick="switchMetric3('trucks')"  id="btn3T">Trucks</button>
        </div>
      </div>
      <?php if (empty($chart3_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:240px;"><canvas id="chart3"></canvas></div>
      <div class="area-legend" id="areaLegend"></div>
      <?php endif; ?>
    </div>

    <!-- Chart 6: By Vehicle Type -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🚛 By Vehicle Type</div>
          <div class="chart-card-sub">Aggregate consumption per vehicle category — includes avg L per refuel.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric6('liters')"  id="btn6L" class="active">Liters</button>
          <button onclick="switchMetric6('amount')"  id="btn6A">Amount</button>
          <button onclick="switchMetric6('avg')"     id="btn6V">Avg L</button>
          <button onclick="switchMetric6('trucks')"  id="btn6T">Trucks</button>
        </div>
      </div>
      <?php if (empty($chart6_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:240px;"><canvas id="chart6"></canvas></div><?php endif; ?>
    </div>

    <!-- Chart 7 (NEW): By Department -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🏢 Fuel by Department</div>
          <div class="chart-card-sub">Resolved department (f.Department → TruckSchedule fallback) — same logic as the main dashboard summary.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric7('liters')"  id="btn7L" class="active">Liters</button>
          <button onclick="switchMetric7('amount')"  id="btn7A">Amount</button>
          <button onclick="switchMetric7('refuels')" id="btn7R">Refuels</button>
          <button onclick="switchMetric7('trucks')"  id="btn7T">Trucks</button>
          <button onclick="switchMetric7('avg')"     id="btn7V">Avg L</button>
        </div>
      </div>
      <?php if (empty($chart7_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:190px;"><canvas id="chart7"></canvas></div><?php endif; ?>
    </div>

  </div>

  <!-- ══ SECTION: RANKINGS & COVERAGE ═══════════════════════ -->
  <div class="section-divider">🏆 Rankings & Refuel Coverage</div>
  <div class="graphs-grid">

    <!-- Chart 5: Top 10 -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🏆 Top 10 Highest Consumers</div>
          <div class="chart-card-sub">Color-coded by department. Hover for avg L/refuel and full breakdown.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric5('liters')"  id="btn5L" class="active">Liters</button>
          <button onclick="switchMetric5('amount')"  id="btn5A">Amount</button>
          <button onclick="switchMetric5('avg')"     id="btn5V">Avg L</button>
          <button onclick="switchMetric5('refuels')" id="btn5R">Refuels</button>
        </div>
      </div>
      <?php if (empty($chart5_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:320px;"><canvas id="chart5"></canvas></div><?php endif; ?>
    </div>

    <!-- Chart 4: Refuel Coverage -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">📅 Refuel Coverage per Vehicle</div>
          <div class="chart-card-sub">Days refueled vs missed in the <?= $dateActive ? 'selected date range' : '30-day rolling window' ?>. % Coverage color: 🟢 ≥70% / 🟡 40–70% / 🔴 &lt;40%.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric4('stacked')" id="btn4S" class="active">Stacked</button>
          <button onclick="switchMetric4('pct')"     id="btn4P">% Coverage</button>
          <button onclick="switchMetric4('liters')"  id="btn4L">Liters</button>
        </div>
      </div>
      <?php if (empty($chart4_data)): ?><div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:320px;"><canvas id="chart4"></canvas></div><?php endif; ?>
    </div>

  </div>

  <!-- ══ SECTION: ANOMALY & PRICE ════════════════════════════ -->
  <div class="section-divider">⚠️ Anomaly Flags & Fuel Price Trend</div>
  <div class="graphs-grid">

    <!-- Chart 8 (NEW): Anomaly Summary -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🚨 Anomaly Flag Summary</div>
          <div class="chart-card-sub">Bracket-normalized detection — same algorithm as the Anomaly Flags tab.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric8('events')" id="btn8E" class="active">Events</button>
          <button onclick="switchMetric8('trucks')" id="btn8T">Trucks</button>
        </div>
      </div>
      <?php if (empty($chart8_data)): ?>
        <div class="no-data">✅ <span>No anomalies detected for this period.</span></div>
      <?php else: ?>
      <div class="flag-legend">
        <span class="flag-pill" style="background:rgba(239,68,68,.15);color:#ef4444;border-color:#fca5a5;">🔴 Critical &gt;100% above avg</span>
        <span class="flag-pill" style="background:rgba(249,115,22,.15);color:#ea580c;border-color:#fdba74;">🟠 High 50–100%</span>
        <span class="flag-pill" style="background:rgba(234,179,8,.15);color:#ca8a04;border-color:#fde047;">🟡 Watch pattern</span>
      </div>
      <div class="chart-wrap" style="height:200px;"><canvas id="chart8"></canvas></div>
      <?php endif; ?>
    </div>

    <!-- Chart 9 (NEW): Price per Liter Trend -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">💲 Fuel Price per Liter Trend</div>
          <div class="chart-card-sub">Average ₱/L over time with optional min–max band — reveals supplier price changes.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric9('avg')"  id="btn9A" class="active">Avg ₱/L</button>
          <button onclick="switchMetric9('band')" id="btn9B">Min–Max Band</button>
        </div>
      </div>
      <?php if (empty($chart9_data)): ?><div class="no-data">📭 <span>No price data for this period.</span></div>
      <?php else: ?><div class="chart-wrap" style="height:200px;"><canvas id="chart9"></canvas></div><?php endif; ?>
    </div>

  </div>

  <div class="footer">
    Fuel Analytics Graphs · Tradewell Fleet Monitoring System · All Vehicles
    · Generated <?= date('Y-m-d H:i:s') ?>
  </div>

</div><!-- /container -->

<script>
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#94a3b8';

const PALETTE = [
    '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6',
    '#06b6d4','#f97316','#84cc16','#ec4899','#14b8a6',
    '#6366f1','#eab308','#22c55e','#f43f5e','#a78bfa'
];

const VTYPE_HEX = {
    'ELF':'#3b82f6','CANTER':'#10b981','FORWARD':'#f59e0b','FIGHTER':'#8b5cf6',
    'CAR':'#ef4444','MOTOR':'#f97316','L300':'#06b6d4','VAN':'#84cc16','CROSS WIND':'#ec4899'
};
function vtypeC(vt)  { const h = VTYPE_HEX[vt] || '#6366f1'; return { bg: h+'cc', border: h }; }

const DEPT_COLORS = {
    'monde':      { bg:'rgba(239,68,68,.72)',   border:'#ef4444' },
    'century':    { bg:'rgba(59,130,246,.72)',  border:'#3b82f6' },
    'multilines': { bg:'rgba(234,179,8,.72)',   border:'#ca8a04' },
    'nutriasia':  { bg:'rgba(16,185,129,.72)',  border:'#059669' },
    'unassigned': { bg:'rgba(107,114,128,.72)', border:'#6b7280' },
};
function deptC(dept) {
    const key = (dept || '').toLowerCase().trim();
    return DEPT_COLORS[key] || { bg:'rgba(107,114,128,.72)', border:'#6b7280' };
}

// ── PHP DATA ──────────────────────────────────────────────────
const _chart1 = <?php
    echo json_encode(array_map(fn($r) => [
        'plate'   => $r['PlateNumber'] ?? '',
        'dept'    => $r['Department']  ?? '',
        'vtype'   => $r['Vehicletype'] ?? 'Unknown',
        'ftype'   => $r['FuelType']    ?? '—',
        'liters'  => (float)($r['TotalLiters']  ?? 0),
        'amount'  => (float)($r['TotalAmount']  ?? 0),
        'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
        'avg'     => (float)($r['AvgLiters']    ?? 0),
    ], $chart1_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart2 = <?php
    echo json_encode(array_map(fn($r) => [
        'day'     => $r['FuelDay']     ?? '',
        'liters'  => (float)($r['DayLiters']  ?? 0),
        'amount'  => (float)($r['DayAmount']  ?? 0),
        'refuels' => (int)  ($r['DayRefuels'] ?? 0),
        'trucks'  => (int)  ($r['DayTrucks']  ?? 0),
    ], $chart2_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart3 = <?php
    echo json_encode(array_map(fn($r) => [
        'area'    => $r['Area']        ?? 'Unknown',
        'liters'  => (float)($r['TotalLiters']  ?? 0),
        'amount'  => (float)($r['TotalAmount']  ?? 0),
        'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
        'trucks'  => (int)  ($r['UniqueTrucks'] ?? 0),
        'avg'     => (float)($r['AvgLiters']    ?? 0),
    ], $chart3_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart4 = <?php
    echo json_encode(array_map(fn($r) => [
        'plate'       => $r['PlateNumber']  ?? '',
        'dept'        => $r['Department']   ?? '',
        'vtype'       => $r['Vehicletype']  ?? 'Unknown',
        'refueled'    => (int)  ($r['DaysRefueled'] ?? 0),
        'missed'      => (int)  ($r['DaysMissed']   ?? 0),
        'total'       => (int)  ($r['TotalDays']    ?? 30),
        'totalRefuels'=> (int)  ($r['TotalRefuels'] ?? 0),
        'liters'      => (float)($r['TotalLiters']  ?? 0),
        'amount'      => (float)($r['TotalAmount']  ?? 0),
    ], $chart4_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart5 = <?php
    echo json_encode(array_map(fn($r) => [
        'plate'   => $r['PlateNumber'] ?? '',
        'dept'    => $r['Department']  ?? '',
        'vtype'   => $r['Vehicletype'] ?? 'Unknown',
        'liters'  => (float)($r['TotalLiters']  ?? 0),
        'amount'  => (float)($r['TotalAmount']  ?? 0),
        'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
        'avg'     => (float)($r['AvgLiters']    ?? 0),
    ], $chart5_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart6 = <?php
    echo json_encode(array_map(fn($r) => [
        'vtype'   => $r['Vehicletype']  ?? 'Unknown',
        'liters'  => (float)($r['TotalLiters']  ?? 0),
        'amount'  => (float)($r['TotalAmount']  ?? 0),
        'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
        'trucks'  => (int)  ($r['UniqueTrucks'] ?? 0),
        'avg'     => (float)($r['AvgLiters']    ?? 0),
    ], $chart6_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart7 = <?php
    echo json_encode(array_map(fn($r) => [
        'dept'    => $r['Department']   ?? 'Unassigned',
        'liters'  => (float)($r['TotalLiters']  ?? 0),
        'amount'  => (float)($r['TotalAmount']  ?? 0),
        'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
        'trucks'  => (int)  ($r['UniqueTrucks'] ?? 0),
        'avg'     => (float)($r['AvgLiters']    ?? 0),
    ], $chart7_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart8 = <?php
    echo json_encode(array_map(fn($r) => [
        'flag'   => $r['FlagLevel']   ?? '',
        'events' => (int)($r['FlagCount']    ?? 0),
        'trucks' => (int)($r['UniqueTrucks'] ?? 0),
    ], $chart8_data), JSON_UNESCAPED_UNICODE);
?>;
const _chart9 = <?php
    echo json_encode(array_map(fn($r) => [
        'day'   => $r['FuelDay']            ?? '',
        'avg'   => (float)($r['AvgPricePerLiter'] ?? 0),
        'min'   => (float)($r['MinPrice']         ?? 0),
        'max'   => (float)($r['MaxPrice']         ?? 0),
        'count' => (int)  ($r['Transactions']     ?? 0),
    ], $chart9_data), JSON_UNESCAPED_UNICODE);
?>;

// ── FORMATTERS ────────────────────────────────────────────────
const fL = v => v.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' L';
const fP = v => '₱' + v.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
const fN = v => v.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
const fI = v => v.toLocaleString('en');

function setActiveBtn(group, id) {
    document.querySelectorAll(`.chart-toggle-btns button[id^="btn${group}"]`)
        .forEach(b => b.classList.toggle('active', b.id === id));
}
function grad(ctx, color, h = 280) {
    const g = ctx.createLinearGradient(0, 0, 0, h);
    g.addColorStop(0, color + '50');
    g.addColorStop(1, color + '00');
    return g;
}
function skipTick(data, every) {
    return function(val, idx) { return idx % every !== 0 ? '' : this.getLabelForValue(val); };
}

// ════════════════════════════════════════════════════════════════
// CHART 1 — Per-Vehicle Bar
// ════════════════════════════════════════════════════════════════
let c1 = null;
function buildChart1(metric) {
    if (!_chart1.length) return;
    const ctx = document.getElementById('chart1').getContext('2d');
    if (c1) c1.destroy();
    c1 = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: _chart1.map(d => d.plate),
            datasets: [{
                data: _chart1.map(d => d[metric] ?? 0),
                backgroundColor: _chart1.map(d => vtypeC(d.vtype).bg),
                borderColor:     _chart1.map(d => vtypeC(d.vtype).border),
                borderWidth: 1.5, borderRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: {
                    title: i => { const d = _chart1[i[0].dataIndex]; return `${d.plate}  ·  ${d.vtype}`; },
                    label: i => {
                        const d = _chart1[i.dataIndex];
                        return [` Dept: ${d.dept||'—'}`, ` Fuel Type: ${d.ftype}`,
                            ` Total Liters: ${fL(d.liters)}`, ` Total Amount: ${fP(d.amount)}`,
                            ` Refuels: ${fI(d.refuels)}`, ` Avg L/Refuel: ${fL(d.avg)}`];
                    }
                }}
            },
            scales: {
                x: { ticks: { maxRotation: 55, font: { size: 10 } }, grid: { display: false } },
                y: { ticks: { callback: v => metric === 'amount' ? '₱'+v.toLocaleString('en') : metric === 'refuels' ? fI(v) : v.toLocaleString('en')+' L' }, grid: { color: 'rgba(148,163,184,.1)' } }
            }
        }
    });
}
function switchMetric1(m) { buildChart1(m); setActiveBtn(1, {liters:'btn1L',amount:'btn1A',refuels:'btn1R',avg:'btn1V'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 2 — Daily Trend
// ════════════════════════════════════════════════════════════════
let c2 = null;
function buildChart2(metric) {
    if (!_chart2.length) return;
    const ctx = document.getElementById('chart2').getContext('2d');
    if (c2) c2.destroy();
    const skip = _chart2.length > 60 ? 7 : _chart2.length > 30 ? 3 : 1;
    const labels = _chart2.map(d => d.day);
    let datasets, scales;
    if (metric === 'both') {
        datasets = [
            { label:'Liters',    data:_chart2.map(d=>d.liters), borderColor:'#3b82f6', backgroundColor:grad(ctx,'#3b82f6',280), borderWidth:2.5, pointRadius:_chart2.length>60?0:3, pointHoverRadius:5, fill:true, tension:0.3, yAxisID:'yL' },
            { label:'Amount (₱)',data:_chart2.map(d=>d.amount), borderColor:'#10b981', backgroundColor:grad(ctx,'#10b981',280), borderWidth:2.5, pointRadius:_chart2.length>60?0:3, pointHoverRadius:5, fill:true, tension:0.3, yAxisID:'yA', borderDash:[4,2] }
        ];
        scales = {
            x:  { ticks:{ callback:skipTick(_chart2,skip), maxRotation:45, font:{size:10} }, grid:{color:'rgba(148,163,184,.08)'} },
            yL: { type:'linear', position:'left',  ticks:{ callback:v=>v.toLocaleString('en')+' L', font:{size:10} }, grid:{color:'rgba(148,163,184,.1)'} },
            yA: { type:'linear', position:'right', ticks:{ callback:v=>'₱'+v.toLocaleString('en'), font:{size:10} }, grid:{display:false} }
        };
    } else {
        const colorMap = { liters:'#3b82f6', amount:'#10b981', refuels:'#f59e0b', trucks:'#8b5cf6' };
        const color = colorMap[metric];
        datasets = [{ label: metric, data:_chart2.map(d=>d[metric]), borderColor:color, backgroundColor:grad(ctx,color,280), borderWidth:2.5, pointRadius:_chart2.length>60?0:3, pointHoverRadius:5, fill:true, tension:0.3 }];
        scales = {
            x: { ticks:{ callback:skipTick(_chart2,skip), maxRotation:45, font:{size:10} }, grid:{color:'rgba(148,163,184,.08)'} },
            y: { ticks:{ callback:v => metric==='amount'?'₱'+v.toLocaleString('en'):metric==='liters'?v.toLocaleString('en')+' L':fI(v), font:{size:10} }, grid:{color:'rgba(148,163,184,.1)'} }
        };
    }
    c2 = new Chart(ctx, {
        type:'line', data:{labels,datasets},
        options:{
            responsive:true, maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{display:metric==='both',position:'top',labels:{boxWidth:12,font:{size:11}}},
                tooltip:{callbacks:{label:i=>{const d=_chart2[i.dataIndex];return[` Liters: ${fL(d.liters)}`,` Amount: ${fP(d.amount)}`,` Refuels: ${fI(d.refuels)}`,` Trucks: ${fI(d.trucks)}`];}}}
            },
            scales
        }
    });
}
function switchMetric2(m) { buildChart2(m); setActiveBtn(2,{both:'btn2B',liters:'btn2L',amount:'btn2A',refuels:'btn2R',trucks:'btn2T'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 3 — Area Donut
// ════════════════════════════════════════════════════════════════
let c3 = null;
function buildChart3(metric) {
    if (!_chart3.length) return;
    const top = _chart3.slice(0,12);
    const vals   = top.map(d => d[metric] ?? 0);
    const total  = vals.reduce((a,b)=>a+b,0);
    const colors = top.map((_,i) => PALETTE[i % PALETTE.length]);
    const ctx = document.getElementById('chart3').getContext('2d');
    if (c3) c3.destroy();
    c3 = new Chart(ctx, {
        type:'doughnut',
        data:{labels:top.map(d=>d.area),datasets:[{data:vals,backgroundColor:colors.map(c=>c+'dd'),borderColor:colors,borderWidth:2,hoverOffset:8}]},
        options:{
            responsive:true,maintainAspectRatio:false,cutout:'62%',
            plugins:{legend:{display:false},tooltip:{callbacks:{label:i=>{
                const d=top[i.dataIndex]; const pct=total>0?(i.parsed/total*100).toFixed(1):0;
                return[` ${d.area}`,` Liters: ${fL(d.liters)} (${pct}%)`,` Amount: ${fP(d.amount)}`,` Refuels: ${fI(d.refuels)}`,` Trucks: ${fI(d.trucks)}`,` Avg L: ${fN(d.avg)}`];
            }}}}
        }
    });
    const leg = document.getElementById('areaLegend');
    if (leg) leg.innerHTML = top.map((d,i)=>{
        const pct = total>0?(d[metric]/total*100).toFixed(1):0;
        return `<span class="area-legend-item"><span class="area-legend-dot" style="background:${colors[i]};"></span>${d.area} <span style="color:var(--text-muted);font-size:.62rem;">(${pct}%)</span></span>`;
    }).join('');
}
function switchMetric3(m) { buildChart3(m); setActiveBtn(3,{liters:'btn3L',amount:'btn3A',refuels:'btn3R',trucks:'btn3T'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 4 — Refuel Coverage
// ════════════════════════════════════════════════════════════════
let c4 = null;
function buildChart4(metric) {
    if (!_chart4.length) return;
    const sorted = [..._chart4].sort((a,b)=>b.liters-a.liters).slice(0,30);
    const ctx = document.getElementById('chart4').getContext('2d');
    if (c4) c4.destroy();
    let datasets, yOpts, stacked = false;
    if (metric === 'pct') {
        const pcts = sorted.map(d=>{ const t=d.refueled+d.missed; return t>0?parseFloat((d.refueled/t*100).toFixed(1)):0; });
        datasets = [{data:pcts, backgroundColor:pcts.map(p=>p>=70?'#10b981cc':p>=40?'#f59e0bcc':'#ef4444cc'), borderColor:pcts.map(p=>p>=70?'#10b981':p>=40?'#f59e0b':'#ef4444'), borderWidth:1.5, borderRadius:4}];
        yOpts = { max:100, ticks:{callback:v=>v+'%'}, grid:{color:'rgba(148,163,184,.1)'} };
    } else if (metric === 'liters') {
        datasets = [{data:sorted.map(d=>d.liters), backgroundColor:sorted.map(d=>vtypeC(d.vtype).bg), borderColor:sorted.map(d=>vtypeC(d.vtype).border), borderWidth:1.5, borderRadius:4}];
        yOpts = { ticks:{callback:v=>v.toLocaleString('en')+' L'}, grid:{color:'rgba(148,163,184,.1)'} };
    } else {
        stacked = true;
        datasets = [
            {label:'Days Refueled', data:sorted.map(d=>d.refueled), backgroundColor:'#10b981cc', borderColor:'#10b981', borderWidth:1.5, borderRadius:4},
            {label:'Days Missed',   data:sorted.map(d=>d.missed),   backgroundColor:'#ef4444cc', borderColor:'#ef4444', borderWidth:1.5, borderRadius:4}
        ];
        yOpts = { stacked:true, ticks:{stepSize:1}, grid:{color:'rgba(148,163,184,.1)'} };
    }
    c4 = new Chart(ctx, {
        type:'bar', data:{labels:sorted.map(d=>d.plate),datasets},
        options:{
            responsive:true,maintainAspectRatio:false,
            plugins:{
                legend:{display:stacked,position:'top',labels:{boxWidth:12,font:{size:11}}},
                tooltip:{callbacks:{
                    title:i=>{const d=sorted[i[0].dataIndex];return`${d.plate}  ·  ${d.vtype}`;},
                    label:i=>{const d=sorted[i.dataIndex];const t=d.refueled+d.missed;const pct=t>0?(d.refueled/t*100).toFixed(1):0;
                        return[` Dept: ${d.dept||'—'}`,` Refueled: ${d.refueled} days`,` Missed: ${d.missed} days`,` Coverage: ${pct}%`,` Liters: ${fL(d.liters)}`,` Refuels: ${fI(d.totalRefuels)}`];}
                }}
            },
            scales:{x:{stacked,ticks:{maxRotation:55,font:{size:10}},grid:{display:false}},y:yOpts}
        }
    });
}
function switchMetric4(m) { buildChart4(m); setActiveBtn(4,{stacked:'btn4S',pct:'btn4P',liters:'btn4L'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 5 — Top 10 Horizontal Bar
// ════════════════════════════════════════════════════════════════
let c5 = null;
function buildChart5(metric) {
    if (!_chart5.length) return;
    const ctx = document.getElementById('chart5').getContext('2d');
    if (c5) c5.destroy();
    c5 = new Chart(ctx, {
        type:'bar',
        data:{
            labels:_chart5.map(d=>d.plate),
            datasets:[{data:_chart5.map(d=>d[metric]??0), backgroundColor:_chart5.map(d=>deptC(d.dept).bg), borderColor:_chart5.map(d=>deptC(d.dept).border), borderWidth:1.5, borderRadius:6}]
        },
        options:{
            indexAxis:'y',responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{
                title:i=>{const d=_chart5[i[0].dataIndex];return`${d.plate}  ·  ${d.vtype}`;},
                label:i=>{const d=_chart5[i.dataIndex];return[` Dept: ${d.dept||'—'}`,` Total Liters: ${fL(d.liters)}`,` Total Amount: ${fP(d.amount)}`,` Refuels: ${fI(d.refuels)}`,` Avg L/Refuel: ${fN(d.avg)} L`];}
            }}},
            scales:{x:{ticks:{callback:v=>metric==='amount'?'₱'+v.toLocaleString('en'):metric==='refuels'?fI(v):v.toLocaleString('en')+' L',font:{size:10}},grid:{color:'rgba(148,163,184,.1)'}},y:{ticks:{font:{size:11,weight:'700'}},grid:{display:false}}}
        }
    });
}
function switchMetric5(m) { buildChart5(m); setActiveBtn(5,{liters:'btn5L',amount:'btn5A',avg:'btn5V',refuels:'btn5R'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 6 — By Vehicle Type
// ════════════════════════════════════════════════════════════════
let c6 = null;
function buildChart6(metric) {
    if (!_chart6.length) return;
    const ctx = document.getElementById('chart6').getContext('2d');
    if (c6) c6.destroy();
    c6 = new Chart(ctx, {
        type:'bar',
        data:{labels:_chart6.map(d=>d.vtype),datasets:[{data:_chart6.map(d=>d[metric]??0),backgroundColor:_chart6.map(d=>vtypeC(d.vtype).bg),borderColor:_chart6.map(d=>vtypeC(d.vtype).border),borderWidth:1.5,borderRadius:8}]},
        options:{
            responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{title:i=>_chart6[i[0].dataIndex].vtype,label:i=>{const d=_chart6[i.dataIndex];return[` Liters: ${fL(d.liters)}`,` Amount: ${fP(d.amount)}`,` Avg L/Refuel: ${fN(d.avg)} L`,` Refuels: ${fI(d.refuels)}`,` Trucks: ${fI(d.trucks)}`];}}}},
            scales:{x:{ticks:{font:{size:11}},grid:{display:false}},y:{ticks:{callback:v=>metric==='amount'?'₱'+v.toLocaleString('en'):metric==='trucks'||metric==='refuels'?fI(v):v.toLocaleString('en')+' L',font:{size:10}},grid:{color:'rgba(148,163,184,.1)'}}}
        }
    });
}
function switchMetric6(m) { buildChart6(m); setActiveBtn(6,{liters:'btn6L',amount:'btn6A',avg:'btn6V',trucks:'btn6T'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 7 — By Department (horizontal bar)
// ════════════════════════════════════════════════════════════════
let c7 = null;
function buildChart7(metric) {
    if (!_chart7.length) return;
    const vals  = _chart7.map(d=>d[metric]??0);
    const total = vals.reduce((a,b)=>a+b,0);
    const ctx = document.getElementById('chart7').getContext('2d');
    if (c7) c7.destroy();
    c7 = new Chart(ctx, {
        type:'bar',
        data:{labels:_chart7.map(d=>d.dept),datasets:[{data:vals,backgroundColor:_chart7.map(d=>deptC(d.dept).bg),borderColor:_chart7.map(d=>deptC(d.dept).border),borderWidth:2,borderRadius:10}]},
        options:{
            indexAxis:'y',responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{title:i=>_chart7[i[0].dataIndex].dept,label:i=>{const d=_chart7[i.dataIndex];const pct=total>0?(d[metric]/total*100).toFixed(1):0;return[` Liters: ${fL(d.liters)} (${pct}%)`,` Amount: ${fP(d.amount)}`,` Refuels: ${fI(d.refuels)}`,` Trucks: ${fI(d.trucks)}`,` Avg L: ${fN(d.avg)} L`];}}}},
            scales:{x:{ticks:{callback:v=>metric==='amount'?'₱'+v.toLocaleString('en'):metric==='liters'||metric==='avg'?v.toLocaleString('en')+' L':fI(v),font:{size:10}},grid:{color:'rgba(148,163,184,.1)'}},y:{ticks:{font:{size:12,weight:'700'}},grid:{display:false}}}
        }
    });
}
function switchMetric7(m) { buildChart7(m); setActiveBtn(7,{liters:'btn7L',amount:'btn7A',refuels:'btn7R',trucks:'btn7T',avg:'btn7V'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 8 — Anomaly Summary
// ════════════════════════════════════════════════════════════════
let c8 = null;
function buildChart8(metric) {
    if (!_chart8.length) return;
    const FCOL = { CRITICAL:{bg:'rgba(239,68,68,.78)',border:'#ef4444'}, HIGH:{bg:'rgba(249,115,22,.78)',border:'#f97316'}, WATCH:{bg:'rgba(234,179,8,.78)',border:'#eab308'} };
    const ctx = document.getElementById('chart8').getContext('2d');
    if (c8) c8.destroy();
    c8 = new Chart(ctx, {
        type:'bar',
        data:{labels:_chart8.map(d=>d.flag.charAt(0)+d.flag.slice(1).toLowerCase()),datasets:[{data:_chart8.map(d=>d[metric]??0),backgroundColor:_chart8.map(d=>(FCOL[d.flag]||FCOL.WATCH).bg),borderColor:_chart8.map(d=>(FCOL[d.flag]||FCOL.WATCH).border),borderWidth:2,borderRadius:10}]},
        options:{
            responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{title:i=>_chart8[i[0].dataIndex].flag+' Flag',label:i=>{const d=_chart8[i.dataIndex];return[` Events: ${fI(d.events)}`,` Unique Trucks: ${fI(d.trucks)}`];}}}},
            scales:{x:{ticks:{font:{size:13,weight:'700'}},grid:{display:false}},y:{ticks:{stepSize:1},grid:{color:'rgba(148,163,184,.1)'}}}
        }
    });
}
function switchMetric8(m) { buildChart8(m); setActiveBtn(8,{events:'btn8E',trucks:'btn8T'}[m]); }

// ════════════════════════════════════════════════════════════════
// CHART 9 — Price per Liter Trend
// ════════════════════════════════════════════════════════════════
let c9 = null;
function buildChart9(metric) {
    if (!_chart9.length) return;
    const ctx = document.getElementById('chart9').getContext('2d');
    if (c9) c9.destroy();
    const labels = _chart9.map(d=>d.day);
    const skip = _chart9.length>60?7:_chart9.length>30?3:1;
    let datasets;
    if (metric === 'band') {
        datasets = [
            {label:'Max ₱/L',data:_chart9.map(d=>d.max),borderColor:'#ef4444',backgroundColor:'rgba(239,68,68,.1)',borderWidth:1.5,borderDash:[3,3],pointRadius:0,fill:'+1',tension:0.3},
            {label:'Avg ₱/L',data:_chart9.map(d=>d.avg),borderColor:'#6366f1',backgroundColor:grad(ctx,'#6366f1',200),borderWidth:2.5,pointRadius:_chart9.length>60?0:3,pointHoverRadius:5,fill:false,tension:0.3},
            {label:'Min ₱/L',data:_chart9.map(d=>d.min),borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.06)',borderWidth:1.5,borderDash:[3,3],pointRadius:0,fill:false,tension:0.3}
        ];
    } else {
        datasets = [{label:'Avg ₱/L',data:_chart9.map(d=>d.avg),borderColor:'#6366f1',backgroundColor:grad(ctx,'#6366f1',200),borderWidth:2.5,pointRadius:_chart9.length>60?0:3,pointHoverRadius:5,fill:true,tension:0.3}];
    }
    c9 = new Chart(ctx, {
        type:'line', data:{labels,datasets},
        options:{
            responsive:true,maintainAspectRatio:false,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{display:metric==='band',position:'top',labels:{boxWidth:12,font:{size:11}}},tooltip:{callbacks:{label:i=>{const d=_chart9[i.dataIndex];return[` Avg ₱/L: ${fP(d.avg)}`,` Range: ${fP(d.min)} – ${fP(d.max)}`,` Transactions: ${fI(d.count)}`];}}}},
            scales:{x:{ticks:{callback:skipTick(_chart9,skip),maxRotation:45,font:{size:10}},grid:{color:'rgba(148,163,184,.08)'}},y:{ticks:{callback:v=>'₱'+v.toFixed(2),font:{size:10}},grid:{color:'rgba(148,163,184,.1)'}}}
        }
    });
}
function switchMetric9(m) { buildChart9(m); setActiveBtn(9,{avg:'btn9A',band:'btn9B'}[m]); }

// ── INIT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    buildChart1('liters');
    buildChart2('both');
    buildChart3('liters');
    buildChart4('stacked');
    buildChart5('liters');
    buildChart6('liters');
    buildChart7('liters');
    buildChart8('events');
    buildChart9('avg');
});
</script>
</body>
</html>