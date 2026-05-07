<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'fuel_dashboard');
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'area';

$tab = 'area';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

$data = runQuery($conn, "
SELECT
    f.Area,
    COUNT(f.FuelID)               AS TotalRefuels,
    ROUND(SUM(f.Liters),2)        AS TotalLiters,
    ROUND(AVG(f.Liters),2)        AS AvgLiters,
    ROUND(SUM(f.Amount),2)        AS TotalAmount,
    ROUND(AVG(f.Amount),2)        AS AvgAmount,
    COUNT(DISTINCT f.PlateNumber) AS UniqueTrucks
FROM [dbo].[Tbl_fuel] f
LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
  AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
  $modeVtypeWhere $deptWhereFuel $filterSQL
GROUP BY f.Area
ORDER BY TotalLiters DESC");

$rowLimit    = 20;
$totalRows   = count($data);
$totalPages  = max(1, (int)ceil($totalRows / $rowLimit));
$curPage     = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset      = ($curPage - 1) * $rowLimit;
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
<title>Area Summary — Fuel Dashboard</title>
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
  <?php renderFilterBar(
    $activeTab,
    $dateFrom,
    $dateTo,
    $dateActive, // ✅ FIX

    $selDept,
    $deptActive,
    $selVtype,
    $vtypeActive,
    $selPlate,
    $plateActive,
    $selDriver,
    $driverActive,
    $selArea,
    $areaActive,

    $anyFilterApplied,

    $deptList,
    $vtypeList,
    $plateList,

    $fcYear,
    $fcMonth
); ?>

  <?php renderTabNav($tab, $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype, $fcYear, $fcMonth); ?>

  <div class="table-section">
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

    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <span class="pagination-info">Showing <strong><?= $offset+1 ?>–<?= min($offset+$rowLimit,$totalRows) ?></strong> of <strong><?= $totalRows ?></strong> · Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong></span>
      <div class="pagination-btns">
        <?php if ($prevUrl): ?><a href="<?= htmlspecialchars($prevUrl) ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Previous</a><?php else: ?><span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Previous</span><?php endif; ?>
        <?php if ($nextUrl): ?><a href="<?= htmlspecialchars($nextUrl) ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a><?php else: ?><span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span><?php endif; ?>
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