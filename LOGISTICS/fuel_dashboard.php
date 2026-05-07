<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'fuel_dashboard');

// ── AJAX: Fetch plates by vehicle type ──────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'plates') {
    header('Content-Type: application/json');
    $vt      = isset($_GET['vtype']) ? trim($_GET['vtype']) : '';
    $_vtSafe = str_replace("'", "''", $vt);
    $sql = $vt !== ''
        ? "SELECT DISTINCT f.PlateNumber FROM [dbo].[Tbl_fuel] f LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber WHERE v.Vehicletype = '$_vtSafe' AND f.PlateNumber IS NOT NULL AND f.PlateNumber <> '' ORDER BY f.PlateNumber"
        : "SELECT DISTINCT PlateNumber FROM [dbo].[Tbl_fuel] WHERE PlateNumber IS NOT NULL AND PlateNumber <> '' ORDER BY PlateNumber";
    $stmt   = sqlsrv_query($conn, $sql);
    $plates = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $plates[] = $row['PlateNumber'];
    sqlsrv_free_stmt($stmt);
    echo json_encode($plates);
    sqlsrv_close($conn);
    exit;
}

// ── Shared bootstrap ────────────────────────────────────────
require_once __DIR__ . '/fuel_shared.php';

// ── Active tab (only summary / rank_asc / rank_desc here) ──
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';
if (!in_array($tab, ['summary', 'rank_asc', 'rank_desc'])) $tab = 'summary';

// ── Lookups ────────────────────────────────────────────────
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);

// ── Stat cards ─────────────────────────────────────────────
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

// ── Queries ─────────────────────────────────────────────────
$q_summary = "
SELECT
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department)  AS Department,
    v.Vehicletype, v.FuelType,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    MAX(f.Fueldate)               AS LastRefuelDate,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3 WHERE ts3.PlateNumber = f.PlateNumber AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo' ORDER BY ts3.ScheduleDate DESC) AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area FROM [dbo].[TruckSchedule] ts4 WHERE ts4.PlateNumber = f.PlateNumber AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo' AND ts4.Area IS NOT NULL AND ts4.Area <> '' FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  $modeVtypeWhere $deptWhereFuel $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype, v.FuelType
ORDER BY TotalLiters DESC";

$q_ranked = "
SELECT
    RANK() OVER (ORDER BY SUM(f.Liters) " . ($tab === 'rank_asc' ? 'ASC' : 'DESC') . ") AS Rank,
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
    v.Vehicletype,
    COUNT(f.FuelID) AS TotalRefuels,
    ROUND(SUM(f.Liters),2) AS TotalLiters,
    ROUND(AVG(f.Liters),2) AS AvgLiters,
    ROUND(SUM(f.Amount),2) AS TotalAmount,
    ROUND(AVG(f.Amount),2) AS AvgAmount,
    (SELECT TOP 1 ts3.Area FROM [dbo].[TruckSchedule] ts3 WHERE ts3.PlateNumber = f.PlateNumber AND ts3.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo' ORDER BY ts3.ScheduleDate DESC) AS LatestArea,
    STUFF((SELECT DISTINCT ', ' + ts4.Area FROM [dbo].[TruckSchedule] ts4 WHERE ts4.PlateNumber = f.PlateNumber AND ts4.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo' AND ts4.Area IS NOT NULL AND ts4.Area <> '' FOR XML PATH('')), 1, 2, '') AS AllAreas
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  $modeVtypeWhere $deptWhereFuel $filterSQL
GROUP BY f.PlateNumber, COALESCE(NULLIF(f.Department,''), ts.Department), v.Vehicletype
ORDER BY TotalLiters " . ($tab === 'rank_asc' ? 'ASC' : 'DESC');

// ── Load data ───────────────────────────────────────────────
$data = runQuery($conn, $tab === 'summary' ? $q_summary : $q_ranked);

// ── Pagination ──────────────────────────────────────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

sqlsrv_close($conn);
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
<?php renderSharedStyles(); ?>
</head>
<body>

<?php $topbar_page = 'fuel'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <div class="page-header">
    <div>
      <div class="page-title">Fuel <span>Monitoring</span> Dashboard</div>
      <div class="page-badge">📅 <?= $anyFilterApplied ? 'Filtered: '.htmlspecialchars($baseFrom).' → '.htmlspecialchars($baseTo) : 'This Month: '.date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <?php renderStatCards($totalTrucks, $totalLiters, $totalAmount, $totalRefuels, $anomalyCount, $dateActive, $anyFilterApplied, $baseFrom, $baseTo); ?>

  <?php renderFilterBar($tab, $dateFrom, $dateTo, $selDept, $deptActive, $selVtype, $vtypeActive, $selPlate, $plateActive, $selDriver, $driverActive, $dateActive, $selArea, $areaActive, $anyFilterApplied, $deptList, $vtypeList, $plateList, $fcYear, $fcMonth); ?>

  <?php renderTabNav($tab, $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype, $fcYear, $fcMonth); ?>

  <div class="table-section">

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

    <?php else: /* rank_asc / rank_desc */ ?>
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
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
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

    <div class="footer">Fuel Dashboard · Tradewell Fleet Monitoring System · Generated <?= date('Y-m-d H:i:s') ?></div>
  </div>
</div>

<?php renderSharedModals(); ?>
<?php renderSharedJS($plateList, $selVtype, $tab, $data); ?>
</body>
</html>