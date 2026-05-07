<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'fuel_dashboard');
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'report';

$tab = 'report';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

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
<title>Usage Report — Fuel Dashboard</title>
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