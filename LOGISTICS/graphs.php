<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);

// ── DATE FILTER ───────────────────────────────────────────────
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';
$dateActive = ($dateFrom !== '' || $dateTo !== '');

$anyFilterApplied = ($dateFrom !== '' || $dateTo !== ''
    || (isset($_GET['vtype'])  && $_GET['vtype']  !== '')
    || (isset($_GET['plate'])  && $_GET['plate']  !== '')
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

// ── DEPARTMENT (session only) ─────────────────────────────────
$selDept    = $_SESSION['Department'] ?? '';
$deptActive = ($selDept !== '');
$deptWhereF = $deptActive ? "AND ts.Department = '$selDept'" : '';
$deptWhereR = $deptActive ? "AND f.Department  = '$selDept'" : '';

// ── OTHER FILTERS ─────────────────────────────────────────────
$selVtype = isset($_GET['vtype']) && $_GET['vtype'] !== '' ? trim($_GET['vtype']) : '';
$vtypeActive  = ($selVtype !== '');
$vtypeWhereF  = $vtypeActive ? "AND ts.PlateNumber IN (SELECT PlateNumber FROM [dbo].[Vehicle] WHERE Vehicletype = '".str_replace("'","''",$selVtype)."')" : '';

$selPlate = isset($_GET['plate']) && $_GET['plate'] !== '' ? trim($_GET['plate']) : '';
$plateActive  = ($selPlate !== '');
$plateWhereF  = $plateActive ? "AND ts.PlateNumber LIKE '%".str_replace("'","''",$selPlate)."%'" : '';
$plateWhereR  = $plateActive ? "AND f.PlateNumber  LIKE '%".str_replace("'","''",$selPlate)."%'" : '';

$selArea = isset($_GET['area']) && $_GET['area'] !== '' ? trim($_GET['area']) : '';
$areaActive   = ($selArea !== '');
$areaWhereF   = $areaActive ? "AND ts.Area LIKE '%".str_replace("'","''",$selArea)."%'" : '';
$areaWhereR   = $areaActive ? "AND f.Area  LIKE '%".str_replace("'","''",$selArea)."%'" : '';

$anyFilter = $dateActive || $deptActive || $vtypeActive || $plateActive || $areaActive;

// ── HELPER ────────────────────────────────────────────────────
function runQuery($conn, $sql) {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

// ── VEHICLE TYPES FOR FILTER ──────────────────────────────────
$vtypeList = runQuery($conn, "SELECT DISTINCT Vehicletype FROM [dbo].[Vehicle] WHERE Active = 1 AND Vehicletype IS NOT NULL ORDER BY Vehicletype");

// ════════════════════════════════════════════════════════════════
// CHART 1 — Fuel Consumption per Truck (bar, grouped by VehicleType)
// ════════════════════════════════════════════════════════════════
$chart1_data = runQuery($conn, "
    SELECT
        ts.PlateNumber,
        ts.Department,
        v.Vehicletype,
        ROUND(SUM(f.Liters), 2)   AS TotalLiters,
        ROUND(SUM(f.Amount), 2)   AS TotalAmount,
        COUNT(f.FuelID)           AS TotalRefuels
    FROM [dbo].[TruckSchedule] ts
    LEFT JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND (v.Active = 1 OR v.Active IS NULL)
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereF
    GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 2 — Fuel Cost Over Time (line, daily total amount)
// ════════════════════════════════════════════════════════════════
$chart2_data = runQuery($conn, "
    SELECT
        CONVERT(VARCHAR(10), f.Fueldate, 120) AS FuelDay,
        ROUND(SUM(f.Liters), 2)  AS DayLiters,
        ROUND(SUM(f.Amount), 2)  AS DayAmount,
        COUNT(f.FuelID)          AS DayRefuels
    FROM [dbo].[Tbl_fuel] f
    LEFT JOIN [dbo].[TruckSchedule] ts
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
      $deptWhereF $vtypeWhereF $plateWhereR $areaWhereR
    GROUP BY CONVERT(VARCHAR(10), f.Fueldate, 120)
    ORDER BY FuelDay ASC
");

// ════════════════════════════════════════════════════════════════
// CHART 3 — Area Breakdown (donut — liters per area)
// ════════════════════════════════════════════════════════════════
$chart3_data = runQuery($conn, "
    SELECT
        f.Area,
        ROUND(SUM(f.Liters), 2)          AS TotalLiters,
        ROUND(SUM(f.Amount), 2)          AS TotalAmount,
        COUNT(f.FuelID)                  AS TotalRefuels,
        COUNT(DISTINCT f.PlateNumber)    AS UniqueTrucks
    FROM [dbo].[TruckSchedule] ts
    INNER JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereR
    GROUP BY f.Area
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 4 — Refuel Status Summary (stacked bar — refueled vs not)
// ════════════════════════════════════════════════════════════════
$chart4_data = runQuery($conn, "
    SELECT
        ts.PlateNumber,
        v.Vehicletype,
        SUM(CASE WHEN f.FuelID IS NOT NULL THEN 1 ELSE 0 END) AS Refueled,
        SUM(CASE WHEN f.FuelID IS NULL     THEN 1 ELSE 0 END) AS NotRefueled
    FROM [dbo].[TruckSchedule] ts
    LEFT JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND ts.PlateNumber IS NOT NULL AND ts.PlateNumber <> ''
      AND (v.Active = 1 OR v.Active IS NULL)
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereF
    GROUP BY ts.PlateNumber, v.Vehicletype
    ORDER BY Refueled DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 5 — Top 10 Consumers Horizontal Bar
// ════════════════════════════════════════════════════════════════
$chart5_data = runQuery($conn, "
    SELECT TOP 10
        ts.PlateNumber,
        ts.Department,
        v.Vehicletype,
        ROUND(SUM(f.Liters), 2)  AS TotalLiters,
        ROUND(SUM(f.Amount), 2)  AS TotalAmount
    FROM [dbo].[TruckSchedule] ts
    INNER JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND (v.Active = 1 OR v.Active IS NULL)
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereF
    GROUP BY ts.PlateNumber, ts.Department, v.Vehicletype
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// CHART 6 — Fuel Consumption by Vehicle Type (grouped bar)
// ════════════════════════════════════════════════════════════════
$chart6_data = runQuery($conn, "
    SELECT
        v.Vehicletype,
        ROUND(SUM(f.Liters), 2)          AS TotalLiters,
        ROUND(SUM(f.Amount), 2)          AS TotalAmount,
        COUNT(f.FuelID)                  AS TotalRefuels,
        COUNT(DISTINCT ts.PlateNumber)   AS UniqueTrucks
    FROM [dbo].[TruckSchedule] ts
    INNER JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = ts.PlateNumber
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      AND v.Vehicletype IS NOT NULL
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereF
    GROUP BY v.Vehicletype
    ORDER BY TotalLiters DESC
");

// ════════════════════════════════════════════════════════════════
// STAT CARDS
// ════════════════════════════════════════════════════════════════
$statRow = runQuery($conn, "
    SELECT
        COUNT(DISTINCT ts.PlateNumber)  AS TotalTrucks,
        ROUND(SUM(f.Liters), 2)         AS TotalLiters,
        ROUND(SUM(f.Amount), 2)         AS TotalAmount,
        COUNT(f.FuelID)                 AS TotalRefuels
    FROM [dbo].[TruckSchedule] ts
    LEFT JOIN [dbo].[Tbl_fuel] f
        ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
    WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
      $deptWhereF $vtypeWhereF $plateWhereF $areaWhereF
");
$totalTrucks  = $statRow[0]['TotalTrucks']  ?? 0;
$totalLiters  = $statRow[0]['TotalLiters']  ?? 0;
$totalAmount  = $statRow[0]['TotalAmount']  ?? 0;
$totalRefuels = $statRow[0]['TotalRefuels'] ?? 0;

// ── DEPT COLORS ───────────────────────────────────────────────
$deptColors = [
    'Monde'      => ['bg'=>'rgba(239,68,68,.15)',  'color'=>'#ef4444', 'border'=>'#fca5a5'],
    'Century'    => ['bg'=>'rgba(59,130,246,.15)', 'color'=>'#3b82f6', 'border'=>'#93c5fd'],
    'Multilines' => ['bg'=>'rgba(234,179,8,.15)',  'color'=>'#ca8a04', 'border'=>'#fde047'],
    'NutriAsia'  => ['bg'=>'rgba(16,185,129,.15)', 'color'=>'#059669', 'border'=>'#6ee7b7'],
    ''           => ['bg'=>'rgba(107,114,128,.15)','color'=>'#6b7280', 'border'=>'#9ca3af'],
];
$activeDept = $_SESSION['Department'] ?? '';
$dc  = $deptColors[$activeDept] ?? $deptColors[''];
$ddStyle = "background:{$dc['bg']};color:{$dc['color']};border-color:{$dc['border']};";

sqlsrv_close($conn);

// ── ENCODE ALL DATA FOR JS ────────────────────────────────────
function encodeRows($rows, $key) {
    return array_column($rows, $key);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fuel Graphs — Tradewell</title>
<link href="../assets/img/logo.png" rel="icon">
<link href="../assets/vendor/fonts/fonts.css" rel="stylesheet">
<link href="../assets/css/fuel.css" rel="stylesheet">
<link href="../assets/css/topbar.css" rel="stylesheet">
<script src="<?= base_url('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<style>
/* ── GRAPHS PAGE STYLES ───────────────────────────────────────── */
.graphs-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
    margin-top: 1.25rem;
}
.graphs-grid .chart-full {
    grid-column: 1 / -1;
}
.chart-card {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 16px;
    padding: 1.25rem 1.5rem;
    position: relative;
    overflow: hidden;
}
.chart-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
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
    margin-top: .1rem;
}
.chart-toggle-btns {
    display: flex;
    gap: .3rem;
}
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
}
.chart-toggle-btns button.active {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}
.chart-wrap {
    position: relative;
    width: 100%;
}
.chart-stats {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-bottom: .75rem;
}
.chart-stat-pill {
    background: var(--hover);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: .2rem .65rem;
    font-size: .72rem;
    font-weight: 700;
    color: var(--text-secondary);
    white-space: nowrap;
}
.chart-stat-pill span {
    color: var(--primary-light);
}
.area-legend {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .75rem;
}
.area-legend-item {
    display: flex;
    align-items: center;
    gap: .3rem;
    font-size: .72rem;
    color: var(--text-secondary);
}
.area-legend-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.no-data {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 180px;
    color: var(--text-muted);
    font-size: .85rem;
    gap: .4rem;
}
.section-divider {
    font-family: 'Sora', sans-serif;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    padding: .5rem 0 .1rem;
    border-bottom: 1px solid var(--border);
    margin: 1.5rem 0 0;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: .2rem;
}
@media (max-width: 768px) {
    .graphs-grid { grid-template-columns: 1fr; }
    .chart-full  { grid-column: 1; }
}
</style>
</head>
<body>

<?php $topbar_page = 'fuel'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ══ PAGE HEADER ═════════════════════════════════════════ -->
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
      <div class="stat-label">Total Trucks</div>
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
      <div class="stat-label">Total Refuels<?= $dateActive ? ' <span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value"><?= number_format($totalRefuels) ?></div>
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
          <span class="filter-select-label"><i class="bi bi-geo-alt"></i> Area</span>
          <input type="text" name="area" class="date-input" style="width:130px;"
                 value="<?= htmlspecialchars($selArea) ?>" placeholder="Area name">
        </div>

        <button type="submit" class="btn-apply">
          <i class="bi bi-search"></i> Apply
        </button>
        <?php if ($anyFilter): ?>
        <a href="graphs.php" class="btn-clear">
          <i class="bi bi-x-lg"></i> Clear
        </a>
        <?php endif; ?>
      </div>

      <?php if ($dateActive || $vtypeActive || $plateActive || $areaActive): ?>
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
        <?php if ($areaActive): ?>
        <span class="filter-active-badge" style="background:#fff7ed;color:#c2410c;border-color:#fdba74;"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selArea) ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ══ CHARTS ══════════════════════════════════════════════ -->

  <div class="section-divider">📊 Consumption Overview</div>

  <div class="graphs-grid">

    <!-- ── CHART 1: Fuel Consumption per Truck (full width) ── -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">⛽ Fuel Consumption per Truck</div>
          <div class="chart-card-sub">Total liters consumed — grouped by Vehicle Type. Hover for details.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric1('liters')" id="btn1L" class="active">Liters</button>
          <button onclick="switchMetric1('amount')" id="btn1A">Amount</button>
        </div>
      </div>
      <?php if (empty($chart1_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:320px;">
        <canvas id="chart1"></canvas>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── CHART 2: Fuel Cost Over Time ── -->
    <div class="chart-card chart-full">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">📈 Fuel Trend Over Time</div>
          <div class="chart-card-sub">Daily fuel consumption and cost trend within the selected period.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric2('liters')" id="btn2L" class="active">Liters</button>
          <button onclick="switchMetric2('amount')" id="btn2A">Amount</button>
          <button onclick="switchMetric2('refuels')" id="btn2R">Refuels</button>
        </div>
      </div>
      <?php if (empty($chart2_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:260px;">
        <canvas id="chart2"></canvas>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── CHART 3: Area Breakdown (donut) ── -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">📍 Fuel by Area</div>
          <div class="chart-card-sub">Distribution of fuel consumption per area.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric3('liters')" id="btn3L" class="active">Liters</button>
          <button onclick="switchMetric3('amount')" id="btn3A">Amount</button>
        </div>
      </div>
      <?php if (empty($chart3_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:260px;">
        <canvas id="chart3"></canvas>
      </div>
      <div class="area-legend" id="areaLegend"></div>
      <?php endif; ?>
    </div>

    <!-- ── CHART 6: By Vehicle Type ── -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🚛 By Vehicle Type</div>
          <div class="chart-card-sub">Total consumption grouped by vehicle category.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric6('liters')" id="btn6L" class="active">Liters</button>
          <button onclick="switchMetric6('amount')" id="btn6A">Amount</button>
          <button onclick="switchMetric6('trucks')" id="btn6T">Trucks</button>
        </div>
      </div>
      <?php if (empty($chart6_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:260px;">
        <canvas id="chart6"></canvas>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <div class="section-divider">🏆 Rankings & Status</div>

  <div class="graphs-grid">

    <!-- ── CHART 5: Top 10 Consumers ── -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">🏆 Top 10 Highest Consumers</div>
          <div class="chart-card-sub">Trucks with the highest total fuel consumption.</div>
        </div>
        <div class="chart-toggle-btns">
          <button onclick="switchMetric5('liters')" id="btn5L" class="active">Liters</button>
          <button onclick="switchMetric5('amount')" id="btn5A">Amount</button>
        </div>
      </div>
      <?php if (empty($chart5_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:320px;">
        <canvas id="chart5"></canvas>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── CHART 4: Refuel Status ── -->
    <div class="chart-card">
      <div class="chart-card-header">
        <div>
          <div class="chart-card-title">✅ Refuel Status per Truck</div>
          <div class="chart-card-sub">Scheduled days refueled vs not refueled per truck.</div>
        </div>
      </div>
      <?php if (empty($chart4_data)): ?>
        <div class="no-data">📭 <span>No data for this period.</span></div>
      <?php else: ?>
      <div class="chart-wrap" style="height:320px;">
        <canvas id="chart4"></canvas>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <div class="footer">
    Fuel Analytics Graphs · Tradewell Fleet Monitoring System
    · Generated <?= date('Y-m-d H:i:s') ?>
  </div>

</div><!-- /container -->

<script>
// ── CHART.JS GLOBAL DEFAULTS ─────────────────────────────────
Chart.defaults.font.family = "'DM Sans', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#94a3b8';

// ── COLOR PALETTES ────────────────────────────────────────────
const PALETTE = [
    '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6',
    '#06b6d4','#f97316','#84cc16','#ec4899','#14b8a6',
    '#6366f1','#eab308','#22c55e','#f43f5e','#a78bfa'
];
const AREA_PALETTE = [
    '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6',
    '#06b6d4','#f97316','#84cc16','#ec4899','#14b8a6'
];

// ── RAW PHP DATA ──────────────────────────────────────────────
const _chart1 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'plate'    => $r['PlateNumber'] ?? '',
            'dept'     => $r['Department']  ?? '',
            'vtype'    => $r['Vehicletype'] ?? 'Unknown',
            'liters'   => (float)($r['TotalLiters']  ?? 0),
            'amount'   => (float)($r['TotalAmount']  ?? 0),
            'refuels'  => (int)  ($r['TotalRefuels'] ?? 0),
        ];
    }, $chart1_data), JSON_UNESCAPED_UNICODE);
?>;

const _chart2 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'day'     => $r['FuelDay']    ?? '',
            'liters'  => (float)($r['DayLiters']  ?? 0),
            'amount'  => (float)($r['DayAmount']  ?? 0),
            'refuels' => (int)  ($r['DayRefuels'] ?? 0),
        ];
    }, $chart2_data), JSON_UNESCAPED_UNICODE);
?>;

const _chart3 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'area'    => $r['Area']         ?? 'Unknown',
            'liters'  => (float)($r['TotalLiters']   ?? 0),
            'amount'  => (float)($r['TotalAmount']   ?? 0),
            'refuels' => (int)  ($r['TotalRefuels']  ?? 0),
            'trucks'  => (int)  ($r['UniqueTrucks']  ?? 0),
        ];
    }, $chart3_data), JSON_UNESCAPED_UNICODE);
?>;

const _chart4 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'plate'       => $r['PlateNumber']  ?? '',
            'vtype'       => $r['Vehicletype']  ?? 'Unknown',
            'refueled'    => (int)($r['Refueled']    ?? 0),
            'notRefueled' => (int)($r['NotRefueled'] ?? 0),
        ];
    }, $chart4_data), JSON_UNESCAPED_UNICODE);
?>;

const _chart5 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'plate'  => $r['PlateNumber'] ?? '',
            'dept'   => $r['Department']  ?? '',
            'vtype'  => $r['Vehicletype'] ?? 'Unknown',
            'liters' => (float)($r['TotalLiters'] ?? 0),
            'amount' => (float)($r['TotalAmount'] ?? 0),
        ];
    }, $chart5_data), JSON_UNESCAPED_UNICODE);
?>;

const _chart6 = <?php
    echo json_encode(array_map(function($r) {
        return [
            'vtype'   => $r['Vehicletype']  ?? 'Unknown',
            'liters'  => (float)($r['TotalLiters']  ?? 0),
            'amount'  => (float)($r['TotalAmount']  ?? 0),
            'refuels' => (int)  ($r['TotalRefuels'] ?? 0),
            'trucks'  => (int)  ($r['UniqueTrucks'] ?? 0),
        ];
    }, $chart6_data), JSON_UNESCAPED_UNICODE);
?>;

// ── HELPERS ───────────────────────────────────────────────────
function fmtNum(v, isMoney) {
    if (isMoney) return '₱' + v.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
    return v.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L';
}
function setActiveBtn(group, active) {
    document.querySelectorAll(`.chart-toggle-btns button[id^="btn${group}"]`)
        .forEach(b => b.classList.toggle('active', b.id === active));
}

// ── CHART 1: Per-Truck Bar ────────────────────────────────────
let chart1Inst = null;
function buildChart1(metric) {
    if (!_chart1.length) return;
    const vtypes  = [...new Set(_chart1.map(d => d.vtype))];
    const colorMap = {};
    vtypes.forEach((v, i) => colorMap[v] = PALETTE[i % PALETTE.length]);

    const labels  = _chart1.map(d => d.plate);
    const values  = _chart1.map(d => metric === 'liters' ? d.liters : d.amount);
    const bgColors = _chart1.map(d => colorMap[d.vtype] + 'cc');
    const borders  = _chart1.map(d => colorMap[d.vtype]);

    const ctx = document.getElementById('chart1').getContext('2d');
    if (chart1Inst) chart1Inst.destroy();
    chart1Inst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: metric === 'liters' ? 'Total Liters' : 'Total Amount (₱)',
                data: values,
                backgroundColor: bgColors,
                borderColor: borders,
                borderWidth: 1.5,
                borderRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: items => {
                            const d = _chart1[items[0].dataIndex];
                            return `${d.plate} (${d.vtype})`;
                        },
                        label: items => {
                            const d = _chart1[items.dataIndex];
                            return [
                                ` Liters: ${d.liters.toLocaleString('en',{minimumFractionDigits:2})} L`,
                                ` Amount: ₱${d.amount.toLocaleString('en',{minimumFractionDigits:2})}`,
                                ` Refuels: ${d.refuels}`,
                                ` Dept: ${d.dept}`
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { maxRotation: 45, font: { size: 10 } },
                    grid: { display: false }
                },
                y: {
                    ticks: {
                        callback: v => metric === 'amount'
                            ? '₱' + v.toLocaleString('en')
                            : v.toLocaleString('en') + ' L'
                    }
                }
            }
        }
    });
}
function switchMetric1(m) {
    buildChart1(m);
    setActiveBtn(1, m === 'liters' ? 'btn1L' : 'btn1A');
}

// ── CHART 2: Trend Line ───────────────────────────────────────
let chart2Inst = null;
function buildChart2(metric) {
    if (!_chart2.length) return;
    const labels = _chart2.map(d => d.day);
    const values = _chart2.map(d =>
        metric === 'liters' ? d.liters : metric === 'amount' ? d.amount : d.refuels
    );
    const color  = metric === 'liters' ? '#3b82f6' : metric === 'amount' ? '#10b981' : '#f59e0b';
    const label  = metric === 'liters' ? 'Liters' : metric === 'amount' ? 'Amount (₱)' : 'Refuels';

    const ctx = document.getElementById('chart2').getContext('2d');
    if (chart2Inst) chart2Inst.destroy();

    const grad = ctx.createLinearGradient(0, 0, 0, 260);
    grad.addColorStop(0, color + '40');
    grad.addColorStop(1, color + '00');

    chart2Inst = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label,
                data: values,
                borderColor: color,
                backgroundColor: grad,
                borderWidth: 2.5,
                pointRadius: _chart2.length > 60 ? 0 : 3,
                pointHoverRadius: 5,
                fill: true,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: items => {
                            const d = _chart2[items.dataIndex];
                            return [
                                ` Liters: ${d.liters.toLocaleString('en',{minimumFractionDigits:2})} L`,
                                ` Amount: ₱${d.amount.toLocaleString('en',{minimumFractionDigits:2})}`,
                                ` Refuels: ${d.refuels}`,
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { maxRotation: 45, font: { size: 10 },
                        callback: function(val, idx) {
                            return _chart2.length > 30 && idx % 3 !== 0 ? '' : this.getLabelForValue(val);
                        }
                    },
                    grid: { color: 'rgba(148,163,184,.1)' }
                },
                y: {
                    ticks: {
                        callback: v => metric === 'amount'
                            ? '₱' + v.toLocaleString('en')
                            : v.toLocaleString('en') + (metric === 'refuels' ? '' : ' L')
                    },
                    grid: { color: 'rgba(148,163,184,.1)' }
                }
            }
        }
    });
}
function switchMetric2(m) {
    buildChart2(m);
    const map = { liters:'btn2L', amount:'btn2A', refuels:'btn2R' };
    setActiveBtn(2, map[m]);
}

// ── CHART 3: Area Donut ───────────────────────────────────────
let chart3Inst = null;
function buildChart3(metric) {
    if (!_chart3.length) return;
    const labels = _chart3.map(d => d.area);
    const values = _chart3.map(d => metric === 'liters' ? d.liters : d.amount);
    const colors = _chart3.map((_, i) => AREA_PALETTE[i % AREA_PALETTE.length]);

    const ctx = document.getElementById('chart3').getContext('2d');
    if (chart3Inst) chart3Inst.destroy();
    chart3Inst = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: 'var(--surface, #1e293b)' }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: items => {
                            const d  = _chart3[items.dataIndex];
                            const pct = (items.parsed / values.reduce((a,b)=>a+b,0)*100).toFixed(1);
                            return [
                                ` ${d.area}`,
                                ` Liters: ${d.liters.toLocaleString('en',{minimumFractionDigits:2})} L`,
                                ` Amount: ₱${d.amount.toLocaleString('en',{minimumFractionDigits:2})}`,
                                ` Trucks: ${d.trucks}  · Share: ${pct}%`
                            ];
                        }
                    }
                }
            }
        }
    });

    const leg = document.getElementById('areaLegend');
    if (leg) {
        leg.innerHTML = _chart3.map((d, i) =>
            `<span class="area-legend-item">
                <span class="area-legend-dot" style="background:${colors[i]};"></span>
                ${d.area}
            </span>`
        ).join('');
    }
}
function switchMetric3(m) {
    buildChart3(m);
    setActiveBtn(3, m === 'liters' ? 'btn3L' : 'btn3A');
}

// ── CHART 4: Refuel Status Stacked Bar ────────────────────────
let chart4Inst = null;
function buildChart4() {
    if (!_chart4.length) return;
    const sorted = [..._chart4].sort((a, b) => (b.refueled + b.notRefueled) - (a.refueled + a.notRefueled)).slice(0, 30);
    const labels  = sorted.map(d => d.plate);

    const ctx = document.getElementById('chart4').getContext('2d');
    if (chart4Inst) chart4Inst.destroy();
    chart4Inst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Refueled',
                    data: sorted.map(d => d.refueled),
                    backgroundColor: '#10b981cc',
                    borderColor: '#10b981',
                    borderWidth: 1.5,
                    borderRadius: 4,
                },
                {
                    label: 'Not Refueled',
                    data: sorted.map(d => d.notRefueled),
                    backgroundColor: '#ef4444cc',
                    borderColor: '#ef4444',
                    borderWidth: 1.5,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        title: items => {
                            const d = sorted[items[0].dataIndex];
                            return `${d.plate} (${d.vtype})`;
                        },
                        label: items => {
                            const d = sorted[items.dataIndex];
                            const total = d.refueled + d.notRefueled;
                            const pct = total > 0 ? ((d.refueled / total)*100).toFixed(1) : 0;
                            return [
                                ` Refueled: ${d.refueled} days`,
                                ` Not Refueled: ${d.notRefueled} days`,
                                ` Coverage: ${pct}%`
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { maxRotation: 45, font: { size: 10 } },
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}

// ── CHART 5: Top 10 Horizontal Bar ───────────────────────────
let chart5Inst = null;
function buildChart5(metric) {
    if (!_chart5.length) return;
    const labels = _chart5.map(d => d.plate);
    const values = _chart5.map(d => metric === 'liters' ? d.liters : d.amount);

    const ctx = document.getElementById('chart5').getContext('2d');
    if (chart5Inst) chart5Inst.destroy();
    chart5Inst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: metric === 'liters' ? 'Total Liters' : 'Total Amount (₱)',
                data: values,
                backgroundColor: PALETTE.map(c => c + 'cc'),
                borderColor: PALETTE,
                borderWidth: 1.5,
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: items => {
                            const d = _chart5[items[0].dataIndex];
                            return `${d.plate} · ${d.vtype}`;
                        },
                        label: items => {
                            const d = _chart5[items.dataIndex];
                            return [
                                ` Liters: ${d.liters.toLocaleString('en',{minimumFractionDigits:2})} L`,
                                ` Amount: ₱${d.amount.toLocaleString('en',{minimumFractionDigits:2})}`,
                                ` Dept: ${d.dept}`
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: v => metric === 'amount'
                            ? '₱' + v.toLocaleString('en')
                            : v.toLocaleString('en') + ' L',
                        font: { size: 10 }
                    },
                    grid: { color: 'rgba(148,163,184,.1)' }
                },
                y: {
                    ticks: { font: { size: 11, weight: '700' } },
                    grid: { display: false }
                }
            }
        }
    });
}
function switchMetric5(m) {
    buildChart5(m);
    setActiveBtn(5, m === 'liters' ? 'btn5L' : 'btn5A');
}

// ── CHART 6: By Vehicle Type ──────────────────────────────────
let chart6Inst = null;
function buildChart6(metric) {
    if (!_chart6.length) return;
    const labels = _chart6.map(d => d.vtype);
    const values = _chart6.map(d =>
        metric === 'liters' ? d.liters : metric === 'amount' ? d.amount : d.trucks
    );
    const colors = _chart6.map((_, i) => PALETTE[i % PALETTE.length]);

    const ctx = document.getElementById('chart6').getContext('2d');
    if (chart6Inst) chart6Inst.destroy();
    chart6Inst = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: metric === 'trucks' ? 'Unique Trucks' : metric === 'liters' ? 'Total Liters' : 'Total Amount (₱)',
                data: values,
                backgroundColor: colors.map(c => c + 'cc'),
                borderColor: colors,
                borderWidth: 1.5,
                borderRadius: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: items => _chart6[items[0].dataIndex].vtype,
                        label: items => {
                            const d = _chart6[items.dataIndex];
                            return [
                                ` Liters: ${d.liters.toLocaleString('en',{minimumFractionDigits:2})} L`,
                                ` Amount: ₱${d.amount.toLocaleString('en',{minimumFractionDigits:2})}`,
                                ` Refuels: ${d.refuels}`,
                                ` Unique Trucks: ${d.trucks}`
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: { font: { size: 11 } },
                    grid: { display: false }
                },
                y: {
                    ticks: {
                        callback: v => metric === 'amount'
                            ? '₱' + v.toLocaleString('en')
                            : v.toLocaleString('en') + (metric === 'trucks' ? '' : ' L'),
                        font: { size: 10 }
                    },
                    grid: { color: 'rgba(148,163,184,.1)' }
                }
            }
        }
    });
}
function switchMetric6(m) {
    buildChart6(m);
    const map = { liters:'btn6L', amount:'btn6A', trucks:'btn6T' };
    setActiveBtn(6, map[m]);
}

// ── INIT ALL CHARTS ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    buildChart1('liters');
    buildChart2('liters');
    buildChart3('liters');
    buildChart4();
    buildChart5('liters');
    buildChart6('liters');
});
</script>

</body>
</html>