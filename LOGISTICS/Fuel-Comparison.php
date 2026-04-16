<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'truck_area';

$tab = 'truck_area';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

$data = runQuery($conn, "
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
ORDER BY tb.Area, tb.FreqBracket, tb.Vehicletype, PctAboveAreaAvg DESC");

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
<title>Fuel Comparison — Fuel Dashboard</title>
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
          if ($pct > 200)    $pctColor = 'var(--red)';
          elseif ($pct > 50) $pctColor = 'var(--orange)';
          elseif ($pct >= 0) $pctColor = 'var(--teal)';
          else               $pctColor = 'var(--text-dim)';
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