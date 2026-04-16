<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'anomaly';

$tab = 'anomaly';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

$data = runQuery($conn, "
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
         ELSE 3 END ASC");

$anomalyCount = count($data);
sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Anomaly Flags — Fuel Dashboard</title>
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
      <div class="table-title">🚨 Anomaly Flags — Suspicious Refuels
        <span class="table-count"><?= $anomalyCount ?> flagged records</span>
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
    $grouped = [];
    foreach ($data as $row) { $grouped[$row['PlateNumber']][] = $row; }
    $shownCards = 0;
    foreach ($grouped as $plate => $records):
        if ($shownCards >= 20) break; $shownCards++;
        $firstRow  = $records[0];
        $dept      = $firstRow['Department'] ?? '';
        $vtype     = $firstRow['Vehicletype'] ?? '';
        $freqBkt   = $firstRow['FreqBracket'] ?? '';
        $truckAvg  = $firstRow['TruckAvgLiters'] ?? 0;
        $truckMin  = $firstRow['TruckMinLiters'] ?? 0;
        $truckMax  = $firstRow['TruckMaxLiters'] ?? 0;
        $areaAvg   = $firstRow['BracketAreaAvg'] ?? 0;
        $totalRefs = $firstRow['TotalRefuels'] ?? 0;
        $flagLevels = array_column($records, 'FlagLevel');
        $worstFlag  = in_array('CRITICAL', $flagLevels) ? 'CRITICAL' : (in_array('HIGH', $flagLevels) ? 'HIGH' : 'WATCH');
        $bracketStyle = match($freqBkt) {
            'HIGH' => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
            'MID'  => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
            default => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
        };
        $cardBorder = match($worstFlag) { 'CRITICAL' => '#ef4444', 'HIGH' => '#f97316', default => '#eab308' };
        $bracketLabel = match($freqBkt) { 'HIGH' => 'daily', 'MID' => 'weekly', default => 'occasional' };
        $truckRefText = "Vehicle avg: " . fmt($truckAvg) . " L · Range: " . fmt($truckMin) . "–" . fmt($truckMax) . " L · " . $totalRefs . " refuels";
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
          <strong style="color:var(--text-secondary)"><?= $totalRefs ?></strong> refuels
          &nbsp;·&nbsp; Avg <strong style="color:var(--teal)"><?= fmt($truckAvg) ?> L</strong>
          &nbsp;·&nbsp; Range <strong><?= fmt($truckMin) ?>–<?= fmt($truckMax) ?> L</strong>
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

    <div class="footer">Fuel Dashboard · Tradewell Fleet Monitoring System · Generated <?= date('Y-m-d H:i:s') ?></div>
  </div>
</div>
<?php renderSharedModals(); ?>
<?php renderSharedJS($plateList, $selVtype, $tab, $data); ?>
</body>
</html>