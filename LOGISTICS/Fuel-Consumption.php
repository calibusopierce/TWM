<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

// ── RBAC gate ────────────────────────────────────────────────
$pdo_rbac = new PDO(
    "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
rbac_gate($pdo_rbac, 'fuel_dashboard');
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'fuel_monthly';

$tab = 'fuel_monthly';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

// ── Week helpers ─────────────────────────────────────────────
function getMonthWeeks(int $year, int $month): array {
    $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $splits = [[1,7],[8,14],[15,21],[22,28]];
    if ($daysInMonth > 28) $splits[] = [29, $daysInMonth];
    $weeks = [];
    foreach ($splits as [$ds, $de]) {
        $weeks[] = [
            'sql_from' => date('Y-m-d', mktime(0,0,0,$month,$ds,$year)),
            'sql_to'   => date('Y-m-d', mktime(0,0,0,$month,$de,$year)),
            'label'    => date('M j', mktime(0,0,0,$month,$ds,$year)) . ' – ' . date('j', mktime(0,0,0,$month,$de,$year)),
        ];
    }
    return $weeks;
}

$fcWeeks     = getMonthWeeks($fcYear, $fcMonth);
$fcWeekCount = count($fcWeeks);
$fcMonthStart = sprintf('%04d-%02d-01', $fcYear, $fcMonth);
$fcMonthEnd   = date('Y-m-t', mktime(0, 0, 0, $fcMonth, 1, $fcYear));
$fcMonthLabel = $months[$fcMonth - 1] . ' ' . $fcYear;

// Build week CASE columns
$weekCases = '';
for ($wi = 0; $wi < $fcWeekCount; $wi++) {
    $wf = $fcWeeks[$wi]['sql_from']; $wt = $fcWeeks[$wi]['sql_to']; $n = $wi + 1;
    $weekCases .= "
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Liters ELSE 0 END),2) AS W{$n}Liters,
        ROUND(SUM(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.Amount ELSE 0 END),2) AS W{$n}Amount,
        COUNT(CASE WHEN f.Fueldate BETWEEN '$wf' AND '$wt' THEN f.FuelID END)               AS W{$n}Refuels,";
}
$weekCases = rtrim($weekCases, ',');

$data = runQuery($conn, "
SELECT
    f.PlateNumber,
    COALESCE(NULLIF(f.Department,''), ts.Department) AS Department,
    v.Vehicletype,
    COUNT(DISTINCT f.FuelID)   AS TotalRefuels,
    ROUND(SUM(f.Liters),2)     AS TotalLiters,
    ROUND(SUM(f.Amount),2)     AS TotalAmount,
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
ORDER BY v.Vehicletype, f.PlateNumber");

// Build export data
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

$prevM = $fcMonth - 1; $prevY = $fcYear; if ($prevM < 1)  { $prevM = 12; $prevY--; }
$nextM = $fcMonth + 1; $nextY = $fcYear; if ($nextM > 12) { $nextM = 1;  $nextY++; }

$wkColors    = ['fc-wk-1','fc-wk-2','fc-wk-3','fc-wk-4','fc-wk-5'];
$wkSubColors = ['fc-wk-sub-1','fc-wk-sub-2','fc-wk-sub-3','fc-wk-sub-4','fc-wk-sub-5'];
$fixedCols   = 3;
$totalCols   = $fixedCols + ($fcWeekCount * 2) + 2;

$deptColorMap = [
    'monde'      => ['bg' => 'rgba(239,68,68,.08)',  'color' => '#ef4444', 'border' => '#fca5a5'],
    'century'    => ['bg' => 'rgba(59,130,246,.08)', 'color' => '#3b82f6', 'border' => '#93c5fd'],
    'multilines' => ['bg' => 'rgba(234,179,8,.08)',  'color' => '#ca8a04', 'border' => '#fde047'],
    'nutriasia'  => ['bg' => 'rgba(16,185,129,.08)', 'color' => '#059669', 'border' => '#6ee7b7'],
];

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fuel Consumption — Fuel Dashboard</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<?php renderSharedStyles(); ?>
<style>
.fc-nav{display:flex;align-items:center;gap:.75rem;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:.55rem 1rem;flex-wrap:wrap;}
.fc-nav-arrow{background:none;border:1px solid var(--border,#e2e8f0);border-radius:8px;cursor:pointer;padding:.3rem .65rem;font-size:.85rem;color:var(--text-secondary,#475569);transition:background .15s;line-height:1;text-decoration:none;display:inline-flex;align-items:center;}
.fc-nav-arrow:hover{background:var(--hover,#f1f5f9);}
.fc-nav-label{font-weight:700;font-size:.95rem;color:var(--text-primary,#0f172a);min-width:160px;text-align:center;}
.fc-table-wrap{overflow-x:auto;margin-top:.5rem;}
#fcTable{width:100%;border-collapse:collapse;font-size:.78rem;min-width:900px;}
#fcTable thead tr th{background:var(--surface-2,#f8fafc);color:var(--text-muted,#64748b);font-weight:700;font-size:.68rem;letter-spacing:.04em;padding:.45rem .6rem;border:1px solid var(--border,#e2e8f0);text-align:center;white-space:nowrap;position:sticky;top:0;z-index:2;}
#fcTable thead tr:first-child th{font-size:.72rem;}
#fcTable tbody td{padding:.4rem .6rem;border:1px solid var(--border,#e2e8f0);vertical-align:middle;}
#fcTable tbody td.td-right{text-align:right;font-family:'DM Mono',monospace;}
#fcTable tbody td.td-center{text-align:center;}
#fcTable tbody td.td-dim{color:var(--text-muted,#64748b);font-size:.73rem;}
.fc-vtype-row td{background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;font-size:.75rem;letter-spacing:.05em;padding:.35rem .75rem;border-top:2px solid #93c5fd;border-bottom:1px solid #93c5fd;}
.fc-vtype-sub td{background:rgba(59,130,246,.1);color:#1e40af;font-weight:800;font-size:.76rem;border-top:2px solid #93c5fd;}
.fc-grand-total td{background:rgba(99,102,241,.1);color:#3730a3;font-weight:800;font-size:.8rem;border-top:2.5px solid #818cf8;}
.no-refuel{display:inline-flex;align-items:center;gap:.25rem;font-size:.65rem;font-weight:700;letter-spacing:.03em;color:#94a3b8;padding:.1rem .35rem;border-radius:20px;background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.25);white-space:nowrap;}
.fc-wk-1{background:rgba(139,92,246,.1)!important;color:#6d28d9!important;}
.fc-wk-2{background:rgba(59,130,246,.1)!important;color:#1d4ed8!important;}
.fc-wk-3{background:rgba(16,185,129,.1)!important;color:#065f46!important;}
.fc-wk-4{background:rgba(245,158,11,.1)!important;color:#92400e!important;}
.fc-wk-5{background:rgba(239,68,68,.1)!important;color:#991b1b!important;}
.fc-wk-sub-1{background:rgba(139,92,246,.04)!important;}
.fc-wk-sub-2{background:rgba(59,130,246,.04)!important;}
.fc-wk-sub-3{background:rgba(16,185,129,.04)!important;}
.fc-wk-sub-4{background:rgba(245,158,11,.04)!important;}
.fc-wk-sub-5{background:rgba(239,68,68,.04)!important;}
</style>
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
      <form method="GET" action="Fuel-Consumption.php" style="display:contents;">
        <?php foreach ($_GET as $k => $v): if (in_array($k, ['fc_year','fc_month'])) continue; ?>
        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <select name="fc_month" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);">
          <?php foreach ($months as $mi => $mn): ?>
          <option value="<?= $mi+1 ?>" <?= ($mi+1===$fcMonth)?'selected':'' ?>><?= $mn ?></option>
          <?php endforeach; ?>
        </select>
        <select name="fc_year" onchange="this.form.submit()" style="border-radius:8px;padding:.3rem .55rem;font-size:.8rem;border:1px solid var(--border,#e2e8f0);background:var(--surface,#fff);">
          <?php for ($y=(int)date('Y');$y>=2020;$y--): ?>
          <option value="<?= $y ?>" <?= ($y===$fcYear)?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
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
    $fcGrouped = [];
    foreach ($data as $row) {
        $dept = $row['Department'] ?? '—';
        $vt   = $row['Vehicletype'] ?? '—';
        $fcGrouped[$dept][$vt][] = $row;
    }
    $grandLiters = 0; $grandAmount = 0;
    $grandWkL = array_fill(0, $fcWeekCount, 0);
    $grandWkA = array_fill(0, $fcWeekCount, 0);

    foreach ($fcGrouped as $dept => $vtypes):
        $deptKey  = strtolower(trim($dept));
        $deptC    = $deptColorMap[$deptKey] ?? ['bg' => 'rgba(107,114,128,.08)', 'color' => '#6b7280', 'border' => '#9ca3af'];
        $deptBg   = $deptC['bg']; $deptCol = $deptC['color']; $deptBord = $deptC['border'];
        echo "<tr><td colspan='{$totalCols}' style='background:{$deptBg};color:{$deptCol};font-weight:800;font-size:.75rem;letter-spacing:.06em;padding:.4rem .75rem;border-top:2px solid {$deptBord};border-bottom:1px solid {$deptBord};'>🏢 " . htmlspecialchars($dept) . "</td></tr>\n";
        $deptLiters = 0; $deptAmount = 0;
        $deptWkL = array_fill(0, $fcWeekCount, 0); $deptWkA = array_fill(0, $fcWeekCount, 0);
        foreach ($vtypes as $vtype => $rows):
            $hasData = false;
            foreach ($rows as $r) { if ((float)($r['TotalLiters']??0)>0||(float)($r['TotalAmount']??0)>0){$hasData=true;break;} }
            if (!$hasData) continue;
            echo "<tr class='fc-vtype-row'><td></td><td colspan='" . ($totalCols-1) . "'>🚛 " . htmlspecialchars($vtype) . "</td></tr>\n";
            $vtypeLiters = 0; $vtypeAmount = 0;
            $vtypeWkL = array_fill(0,$fcWeekCount,0); $vtypeWkA = array_fill(0,$fcWeekCount,0);
            foreach ($rows as $row):
                $pLiters=(float)($row['TotalLiters']??0); $pAmount=(float)($row['TotalAmount']??0);
                if ($pLiters==0.0&&$pAmount==0.0) continue;
                echo "<tr>";
                echo "<td style='white-space:nowrap;background:{$deptBg};'>" . deptBadge($dept) . "</td>";
                echo "<td style='white-space:nowrap;'><span class='plate'>" . htmlspecialchars($row['PlateNumber']) . "</span></td>";
                echo "<td class='td-dim'>" . htmlspecialchars($vtype) . "</td>";
                for ($wi=0;$wi<$fcWeekCount;$wi++):
                    $n=$wi+1; $wL=(float)($row["W{$n}Liters"]??0); $wA=(float)($row["W{$n}Amount"]??0); $wR=(int)($row["W{$n}Refuels"]??0);
                    $vtypeWkL[$wi]+=$wL; $vtypeWkA[$wi]+=$wA; $sc=$wkSubColors[$wi];
                    if ($wR===0) echo "<td class='td-center $sc' colspan='2'><span class='no-refuel'>⊘ No Refuel</span></td>";
                    else { echo "<td class='td-right $sc'>" . fmt($wL) . " L</td>"; echo "<td class='td-right $sc'>" . peso($wA) . "</td>"; }
                endfor;
                echo "<td class='td-right' style='font-weight:700;color:var(--teal,#0d9488);'>" . fmt($pLiters) . " L</td>";
                echo "<td class='td-right' style='font-weight:700;'>" . peso($pAmount) . "</td>";
                echo "</tr>\n";
                $vtypeLiters+=$pLiters; $vtypeAmount+=$pAmount;
            endforeach;
            // VType subtotal
            echo "<tr class='fc-vtype-sub'>";
            echo "<td style='background:{$deptBg};'>" . deptBadge($dept) . "</td>";
            echo "<td colspan='1' style='color:#1e40af;padding-left:.75rem;'>🚛 Subtotal — " . htmlspecialchars($vtype) . "</td><td></td>";
            for ($wi=0;$wi<$fcWeekCount;$wi++): $sc=$wkSubColors[$wi];
                echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>" . fmt($vtypeWkL[$wi]) . " L</td>";
                echo "<td class='td-right $sc' style='color:#1e40af;font-weight:800;'>" . peso($vtypeWkA[$wi]) . "</td>";
                $deptWkL[$wi]+=$vtypeWkL[$wi]; $deptWkA[$wi]+=$vtypeWkA[$wi];
            endfor;
            echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>" . fmt($vtypeLiters) . " L</td>";
            echo "<td class='td-right' style='color:#1e40af;font-weight:800;'>" . peso($vtypeAmount) . "</td>";
            echo "</tr>\n";
            $deptLiters+=$vtypeLiters; $deptAmount+=$vtypeAmount;
        endforeach;
        // Dept subtotal
        echo "<tr style='background:{$deptBg};border-top:2px solid {$deptBord};'>";
        echo "<td colspan='3' style='color:{$deptCol};font-weight:800;font-size:.77rem;padding:.4rem .75rem;'>🏢 " . htmlspecialchars($dept) . " Total</td>";
        for ($wi=0;$wi<$fcWeekCount;$wi++): $sc=$wkSubColors[$wi];
            echo "<td class='td-right $sc' style='color:{$deptCol};font-weight:800;'>" . fmt($deptWkL[$wi]) . " L</td>";
            echo "<td class='td-right $sc' style='color:{$deptCol};font-weight:800;'>" . peso($deptWkA[$wi]) . "</td>";
            $grandWkL[$wi]+=$deptWkL[$wi]; $grandWkA[$wi]+=$deptWkA[$wi];
        endfor;
        echo "<td class='td-right' style='color:{$deptCol};font-weight:800;font-size:.82rem;'>" . fmt($deptLiters) . " L</td>";
        echo "<td class='td-right' style='color:{$deptCol};font-weight:800;font-size:.82rem;'>" . peso($deptAmount) . "</td>";
        echo "</tr>\n";
        $grandLiters+=$deptLiters; $grandAmount+=$deptAmount;
    endforeach;
    // Grand total
    echo "<tr class='fc-grand-total'><td colspan='3' style='padding-left:.75rem;'>🏁 Grand Total</td>";
    for ($wi=0;$wi<$fcWeekCount;$wi++): $sc=$wkSubColors[$wi];
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

    <div class="footer">Fuel Dashboard · Tradewell Fleet Monitoring System · Generated <?= date('Y-m-d H:i:s') ?></div>
  </div>
</div>

<?php renderSharedModals(); ?>
<?php renderSharedJS($plateList, $selVtype, $tab, $data); ?>

<?php
$fcExportJson    = json_encode($fcExport, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$fcMonthLabelJs  = json_encode($fcMonthLabel);
$fcMonthFileJs   = json_encode($months[$fcMonth-1] . '_' . $fcYear);
?>
<script>
const _fcExportData  = <?= $fcExportJson ?>;
const _fcMonthLabel  = <?= $fcMonthLabelJs ?>;
const _fcMonthFile   = <?= $fcMonthFileJs ?>;

/**
 * fcPrint() — Print-ready monthly fuel consumption report
 *
 * Reads from:
 *   _fcExportData  — array of row objects (built in PHP)
 *   _fcMonthLabel  — e.g. "April 2025"
 *   _fcMonthFile   — e.g. "April_2025"
 *
 * Each row object has keys:
 *   Department, Plate, VehicleType, TotalRefuels, TotalLiters, TotalAmount,
 *   "Wk1 (Apr 1 – 7) Liters", "Wk1 (Apr 1 – 7) Amount", "Wk1 (Apr 1 – 7) Refuels",
 *   ...repeated for each week
 */
function fcPrint() {
    if (!_fcExportData || !_fcExportData.length) {
        alert('No data to print.');
        return;
    }

    // ── 1. Detect weeks dynamically from export keys ─────────────────────────
    const firstRow = _fcExportData[0];
    const allKeys  = Object.keys(firstRow);

    // Collect week labels in order: "Wk1 (Apr 1 – 7)", "Wk2 (Apr 8 – 14)", ...
    const weekLabels = [];
    allKeys.forEach(k => {
        const m = k.match(/^(Wk\d+\s+\([^)]+\))\s+Liters$/);
        if (m && !weekLabels.includes(m[1])) weekLabels.push(m[1]);
    });
    // weekLabels = ["Wk1 (Apr 1 – 7)", "Wk2 (Apr 8 – 14)", ...]

    const weekCount = weekLabels.length;

    // Short display labels: "Wk 1" and date range "Apr 1 – 7"
    const weekDisplay = weekLabels.map(wl => {
        const m = wl.match(/^(Wk\d+)\s+\(([^)]+)\)$/);
        return {
            full:  wl,
            num:   m ? m[1].replace('Wk','Wk ') : wl,
            range: m ? m[2] : '',
        };
    });

    // ── 2. Helpers ────────────────────────────────────────────────────────────
    const esc = v => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    const fmtL  = n => {
        const num = parseFloat(n) || 0;
        return num === 0 ? '0.00 L' : num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' L';
    };
    const fmtP  = n => {
        const num = parseFloat(n) || 0;
        return '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    const fmtLshort = n => {
        const num = parseFloat(n) || 0;
        return num === 0 ? '—' : num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' L';
    };
    const fmtPshort = n => {
        const num = parseFloat(n) || 0;
        return num === 0 ? '—' : '₱' + num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    // ── 3. Color theme per department ────────────────────────────────────────
    const deptTheme = {
        monde:      { hdrBg:'#fef2f2', hdrColor:'#b91c1c', hdrBorder:'#fca5a5',
                      subBg:'rgba(239,68,68,.1)',  badge:'#fee2e2', badgeText:'#b91c1c' },
        century:    { hdrBg:'#eff6ff', hdrColor:'#1d4ed8', hdrBorder:'#93c5fd',
                      subBg:'rgba(59,130,246,.1)', badge:'#dbeafe', badgeText:'#1d4ed8' },
        multilines: { hdrBg:'#fefce8', hdrColor:'#a16207', hdrBorder:'#fde047',
                      subBg:'rgba(234,179,8,.1)',  badge:'#fef9c3', badgeText:'#a16207' },
        nutriasia:  { hdrBg:'#f0fdf4', hdrColor:'#047857', hdrBorder:'#6ee7b7',
                      subBg:'rgba(16,185,129,.1)', badge:'#d1fae5', badgeText:'#047857' },
    };

    function getDeptTheme(dept) {
        return deptTheme[String(dept).toLowerCase().trim()] || {
            hdrBg:'#f1f5f9', hdrColor:'#475569', hdrBorder:'#cbd5e1',
            subBg:'rgba(107,114,128,.1)', badge:'#f1f5f9', badgeText:'#475569',
        };
    }

    // Week header colors (5 max, mirrors the tab)
    const wkColors = [
        { bg:'#ede9fe', color:'#5b21b6', subBg:'#f5f3ff' },
        { bg:'#dbeafe', color:'#1d4ed8', subBg:'#eff6ff' },
        { bg:'#d1fae5', color:'#065f46', subBg:'#f0fdf4' },
        { bg:'#fef3c7', color:'#92400e', subBg:'#fffbeb' },
        { bg:'#fee2e2', color:'#991b1b', subBg:'#fff1f2' },
    ];
    const wkC = (wi) => wkColors[wi % wkColors.length];

    // ── 4. Group data: dept → vtype → rows ───────────────────────────────────
    const grouped = {};
    _fcExportData.forEach(row => {
        const dept  = row['Department']   || '—';
        const vtype = row['VehicleType']  || '—';
        if (!grouped[dept])        grouped[dept]        = {};
        if (!grouped[dept][vtype]) grouped[dept][vtype] = [];
        grouped[dept][vtype].push(row);
    });

    // ── 5. Build thead HTML ───────────────────────────────────────────────────
    // Columns: Dept | Plate | VehicleType | [Wk1 Liters, Wk1 Amount] * n | Total Liters | Total Amount
    const totalDataCols = weekCount * 2 + 2; // per week: liters+amount, plus grand total liters+amount

    let theadHtml = '';

    // Row 1: fixed cols (rowspan=3) + week groups (colspan=2) + grand total (colspan=2)
    theadHtml += '<tr>';
    theadHtml += '<th rowspan="3" style="min-width:50px;width:50px;">Department</th>';
    theadHtml += '<th rowspan="3" style="min-width:50px;width:50px;">Plate #</th>';
    theadHtml += '<th rowspan="3" style="min-width:50px;width:50px;">Vehicle Type</th>';
    weekDisplay.forEach((wd, wi) => {
        const c = wkC(wi);
        theadHtml += `<th colspan="2" style="background:${c.bg};color:${c.color};">${esc(wd.num)} <span style="font-weight:400;opacity:.75;font-size:7px;">${esc(wd.range)}</span></th>`;
    });
    theadHtml += '<th colspan="2" style="background:#e0e7ff;color:#3730a3;">Grand Total</th>';
    theadHtml += '</tr>';

    // Row 2: "Fuel" subheader per week + "Total"
    theadHtml += '<tr>';
    weekDisplay.forEach((wd, wi) => {
        const c = wkC(wi);
        theadHtml += `<th colspan="2" style="background:${c.bg};color:${c.color};font-size:7px;font-weight:600;opacity:.9;">Fuel</th>`;
    });
    theadHtml += '<th colspan="2" style="background:#e0e7ff;color:#3730a3;font-size:7px;">Total</th>';
    theadHtml += '</tr>';

    // Row 3: Liters / Amount per week + totals
    theadHtml += '<tr>';
    weekDisplay.forEach((wd, wi) => {
        const c = wkC(wi);
        theadHtml += `<th style="background:${c.bg};color:${c.color};font-size:7px;">Liters</th>`;
        theadHtml += `<th style="background:${c.bg};color:${c.color};font-size:7px;">Amount</th>`;
    });
    theadHtml += '<th style="background:#e0e7ff;color:#3730a3;font-size:7px;">Liters</th>';
    theadHtml += '<th style="background:#e0e7ff;color:#3730a3;font-size:7px;">Amount</th>';
    theadHtml += '</tr>';

    // ── 6. Build tbody HTML ───────────────────────────────────────────────────
    let tbodyHtml = '';
    let grandL = 0, grandA = 0;
    const grandWkL = new Array(weekCount).fill(0);
    const grandWkA = new Array(weekCount).fill(0);

    Object.entries(grouped).forEach(([dept, vtypes]) => {
        const dt  = getDeptTheme(dept);
        const colSpan = 3 + totalDataCols;

        // Dept header row
        tbodyHtml += `<tr>`;
        tbodyHtml += `<td colspan="${colSpan}" style="background:${dt.hdrBg};color:${dt.hdrColor};font-weight:800;font-size:7.5px;letter-spacing:.05em;padding:4px 8px;border-top:1.5px solid ${dt.hdrBorder};border-bottom:1px solid ${dt.hdrBorder};">&#127962; ${esc(dept)}</td>`;
        tbodyHtml += `</tr>`;

        let deptL = 0, deptA = 0;
        const deptWkL = new Array(weekCount).fill(0);
        const deptWkA = new Array(weekCount).fill(0);

        Object.entries(vtypes).forEach(([vtype, rows]) => {
            // Skip vtype group if all rows have zero liters+amount
            const hasAny = rows.some(r => (parseFloat(r['TotalLiters']) || 0) > 0 || (parseFloat(r['TotalAmount']) || 0) > 0);
            if (!hasAny) return;

            // VType header row
            tbodyHtml += `<tr>`;
            tbodyHtml += `<td style="background:rgba(59,130,246,.06);"></td>`;
            tbodyHtml += `<td colspan="${colSpan - 1}" style="background:rgba(59,130,246,.06);color:#1e40af;font-weight:700;font-size:7.5px;padding:3px 6px;border-top:1.5px solid #93c5fd;">&#128666; ${esc(vtype)}</td>`;
            tbodyHtml += `</tr>`;

            let vtypeL = 0, vtypeA = 0;
            const vtypeWkL = new Array(weekCount).fill(0);
            const vtypeWkA = new Array(weekCount).fill(0);

            rows.forEach((row, ri) => {
                const pLiters = parseFloat(row['TotalLiters'])  || 0;
                const pAmount = parseFloat(row['TotalAmount'])  || 0;
                if (pLiters === 0 && pAmount === 0) return;

                const rowBg = ri % 2 === 1 ? 'background:#f8fafc;' : '';

                tbodyHtml += `<tr>`;

                // Dept badge cell
                tbodyHtml += `<td style="${rowBg}background:${dt.hdrBg};text-align:center;">`;
                tbodyHtml += `<span style="display:inline-block;font-size:6.5px;font-weight:700;padding:1px 4px;border-radius:8px;background:${dt.badge};color:${dt.badgeText};">${esc(dept)}</span>`;
                tbodyHtml += `</td>`;

                // Plate
                tbodyHtml += `<td style="${rowBg}white-space:nowrap;font-weight:600;">${esc(row['Plate'] || row['PlateNumber'] || '')}</td>`;

                // Vehicle type
                tbodyHtml += `<td style="${rowBg}color:#94a3b8;font-size:7.5px;">${esc(vtype)}</td>`;

                // Per-week liters + amount
                weekLabels.forEach((wl, wi) => {
                    const wLiters  = parseFloat(row[wl + ' Liters'])  || 0;
                    const wAmount  = parseFloat(row[wl + ' Amount'])  || 0;
                    const wRefuels = parseInt(row[wl + ' Refuels'], 10) || 0;
                    vtypeWkL[wi] += wLiters;
                    vtypeWkA[wi] += wAmount;
                    const c = wkC(wi);

                    if (wRefuels === 0) {
                        // Span 2 cols with no-refuel indicator
                        tbodyHtml += `<td colspan="2" style="background:${c.subBg};text-align:center;">`;
                        tbodyHtml += `<span style="font-size:6.5px;color:#94a3b8;font-style:italic;">no refuel</span>`;
                        tbodyHtml += `</td>`;
                    } else {
                        tbodyHtml += `<td style="${rowBg}background:${c.subBg};text-align:right;font-family:'Courier New',monospace;">${fmtLshort(wLiters)}</td>`;
                        tbodyHtml += `<td style="${rowBg}background:${c.subBg};text-align:right;font-family:'Courier New',monospace;">${fmtPshort(wAmount)}</td>`;
                    }
                });

                // Row totals
                tbodyHtml += `<td style="${rowBg}text-align:right;font-weight:700;color:#0d9488;font-family:'Courier New',monospace;">${fmtL(pLiters)}</td>`;
                tbodyHtml += `<td style="${rowBg}text-align:right;font-weight:700;font-family:'Courier New',monospace;">${fmtP(pAmount)}</td>`;
                tbodyHtml += `</tr>`;

                vtypeL += pLiters;
                vtypeA += pAmount;
            });

            // VType subtotal row
            tbodyHtml += `<tr>`;
            tbodyHtml += `<td style="background:rgba(59,130,246,.06);"></td>`;
            tbodyHtml += `<td colspan="2" style="background:rgba(59,130,246,.06);color:#1e40af;font-weight:700;font-size:7.5px;padding:3px 8px;">&#8627; Subtotal &#8212; ${esc(vtype)}</td>`;
            vtypeWkL.forEach((wl, wi) => {
                const c = wkC(wi);
                tbodyHtml += `<td style="background:${c.subBg};text-align:right;font-weight:700;color:#1e40af;font-family:'Courier New',monospace;">${fmtLshort(wl)}</td>`;
                tbodyHtml += `<td style="background:${c.subBg};text-align:right;font-weight:700;color:#1e40af;font-family:'Courier New',monospace;">${fmtPshort(vtypeWkA[wi])}</td>`;
                deptWkL[wi] += wl;
                deptWkA[wi] += vtypeWkA[wi];
            });
            tbodyHtml += `<td style="text-align:right;font-weight:700;color:#1e40af;font-family:'Courier New',monospace;">${fmtL(vtypeL)}</td>`;
            tbodyHtml += `<td style="text-align:right;font-weight:700;color:#1e40af;font-family:'Courier New',monospace;">${fmtP(vtypeA)}</td>`;
            tbodyHtml += `</tr>`;

            deptL += vtypeL;
            deptA += vtypeA;
        });

        // Dept subtotal row
        tbodyHtml += `<tr style="border-top:1.5px solid ${dt.hdrBorder};">`;
        tbodyHtml += `<td colspan="3" style="background:${dt.subBg};color:${dt.hdrColor};font-weight:800;font-size:7.5px;padding:4px 8px;">&#127962; ${esc(dept)} Total</td>`;
        deptWkL.forEach((wl, wi) => {
            const c = wkC(wi);
            tbodyHtml += `<td style="background:${dt.subBg};background:${c.subBg};text-align:right;font-weight:800;color:${dt.hdrColor};font-family:'Courier New',monospace;">${fmtLshort(wl)}</td>`;
            tbodyHtml += `<td style="background:${dt.subBg};background:${c.subBg};text-align:right;font-weight:800;color:${dt.hdrColor};font-family:'Courier New',monospace;">${fmtPshort(deptWkA[wi])}</td>`;
            grandWkL[wi] += wl;
            grandWkA[wi] += deptWkA[wi];
        });
        tbodyHtml += `<td style="background:${dt.subBg};text-align:right;font-weight:800;font-size:8.5px;color:${dt.hdrColor};font-family:'Courier New',monospace;">${fmtL(deptL)}</td>`;
        tbodyHtml += `<td style="background:${dt.subBg};text-align:right;font-weight:800;font-size:8.5px;color:${dt.hdrColor};font-family:'Courier New',monospace;">${fmtP(deptA)}</td>`;
        tbodyHtml += `</tr>`;

        grandL += deptL;
        grandA += deptA;
    });

    // Grand total row
    tbodyHtml += `<tr style="border-top:2px solid #818cf8;">`;
    tbodyHtml += `<td colspan="3" style="background:rgba(99,102,241,.1);color:#3730a3;font-weight:800;font-size:8.5px;padding:4px 8px;">&#127937; Grand Total</td>`;
    grandWkL.forEach((wl, wi) => {
        const c = wkC(wi);
        tbodyHtml += `<td style="background:${c.subBg};text-align:right;font-weight:800;color:#3730a3;font-family:'Courier New',monospace;">${fmtLshort(wl)}</td>`;
        tbodyHtml += `<td style="background:${c.subBg};text-align:right;font-weight:800;color:#3730a3;font-family:'Courier New',monospace;">${fmtPshort(grandWkA[wi])}</td>`;
    });
    tbodyHtml += `<td style="background:rgba(99,102,241,.1);text-align:right;font-weight:800;font-size:9px;color:#3730a3;font-family:'Courier New',monospace;">${fmtL(grandL)}</td>`;
    tbodyHtml += `<td style="background:rgba(99,102,241,.1);text-align:right;font-weight:800;font-size:9px;color:#3730a3;font-family:'Courier New',monospace;">${fmtP(grandA)}</td>`;
    tbodyHtml += `</tr>`;

    // ── 7. Week legend pills ──────────────────────────────────────────────────
    const legendHtml = weekDisplay.map((wd, wi) => {
        const c = wkC(wi);
        return `<span style="font-size:8px;font-weight:700;padding:2px 8px;border-radius:20px;background:${c.bg};color:${c.color};border:1px solid ${c.color}33;">${esc(wd.num)}: ${esc(wd.range)}</span>`;
    }).join(' ');

    // ── 8. Stat summary strip ─────────────────────────────────────────────────
    const totalVehicles = _fcExportData.length;
    const statsHtml = `
        <div style="display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
            <div style="background:#f0fdf4;border:1px solid #6ee7b7;border-radius:6px;padding:5px 12px;text-align:center;">
                <div style="font-size:7.5px;color:#047857;font-weight:600;">TOTAL LITERS</div>
                <div style="font-size:13px;font-weight:800;color:#047857;">${fmtL(grandL)}</div>
            </div>
            <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;padding:5px 12px;text-align:center;">
                <div style="font-size:7.5px;color:#1d4ed8;font-weight:600;">TOTAL AMOUNT</div>
                <div style="font-size:13px;font-weight:800;color:#1d4ed8;">${fmtP(grandA)}</div>
            </div>
            <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:6px;padding:5px 12px;text-align:center;">
                <div style="font-size:7.5px;color:#6d28d9;font-weight:600;">VEHICLES</div>
                <div style="font-size:13px;font-weight:800;color:#6d28d9;">${totalVehicles}</div>
            </div>
            <div style="background:#fefce8;border:1px solid #fde047;border-radius:6px;padding:5px 12px;text-align:center;">
                <div style="font-size:7.5px;color:#a16207;font-weight:600;">WEEKS</div>
                <div style="font-size:13px;font-weight:800;color:#a16207;">${weekCount}</div>
            </div>
        </div>`;

    // ── 9. Full print HTML ────────────────────────────────────────────────────
    const printHtml = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fuel Consumption — ${esc(_fcMonthLabel)}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; padding: 14px 16px; color: #0f172a; background: #fff; }

  /* Report header */
  .rpt-header { display: flex; justify-content: space-between; align-items: flex-start;
                border-bottom: 2.5px solid #1e40af; padding-bottom: 8px; margin-bottom: 10px; }
  .rpt-title  { font-size: 15px; font-weight: 800; color: #1e40af; }
  .rpt-sub    { font-size: 9.5px; color: #64748b; margin-top: 2px; }
  .rpt-meta   { text-align: right; font-size: 8.5px; color: #64748b; line-height: 1.5; }
  .rpt-month  { font-size: 13px; font-weight: 800; color: #1e40af; }

  /* Legend */
  .wk-legend  { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; align-items: center; }
  .legend-lbl { font-size: 8px; font-weight: 700; color: #475569; margin-right: 2px; }

  /* Table */
  table { width: 100%; border-collapse: collapse; font-size: 8px; }
  th {
    padding: 4px 5px; border: 1px solid #cbd5e1; text-align: center;
    white-space: nowrap; font-size: 7.5px; font-weight: 700; position: sticky; top: 0;
  }
  td { padding: 3px 5px; border: 1px solid #e2e8f0; vertical-align: middle; }

  /* Alternating rows */
  tbody tr:nth-child(even) td { background: #f8fafc; }

  /* Footer */
  .rpt-footer {
    margin-top: 10px; padding-top: 6px;
    border-top: 1px solid #e2e8f0;
    font-size: 7.5px; color: #94a3b8;
    display: flex; justify-content: space-between;
  }

  /* Print styles */
  @media print {
    body { padding: 6px 8px; }
    .no-print { display: none !important; }
    table { font-size: 9px; }
    th, td { padding: 3px 5px; }
    @page { size: folio landscape; margin: 6mm; }
}

  /* Print button bar */
  .print-bar {
    display: flex; gap: 8px; margin-bottom: 12px; align-items: center;
    padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
  }
  .print-bar button {
    font-size: 11px; padding: 5px 16px; border: 1px solid #cbd5e1; border-radius: 6px;
    background: #fff; cursor: pointer; color: #0f172a; font-weight: 600;
  }
  .print-bar button:hover { background: #f1f5f9; }
  .print-bar button.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
  .print-bar button.primary:hover { background: #1e3a8a; }
  .print-bar .hint { font-size: 9px; color: #94a3b8; }
</style>
</head>
<body>

<!-- Print bar (hidden on print) -->
<div class="print-bar no-print">
  <button class="primary" onclick="window.print()">&#128438; Print / Save as PDF</button>
  <button onclick="window.close()">&#10005; Close</button>
  <span class="hint">Tip: Use A3 Landscape in print settings for best fit. Scale to fit page if needed.</span>
</div>

<!-- Report header -->
<div class="rpt-header">
  <div>
    <div class="rpt-title">Tradewell Fleet Monitoring System</div>
    <div class="rpt-sub">Fuel Consumption Report &mdash; Monthly Breakdown by Department &amp; Vehicle Type</div>
  </div>
  <div class="rpt-meta">
    <div class="rpt-month">${esc(_fcMonthLabel)}</div>
    <div>Generated: ${new Date().toLocaleString('en-PH', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' })}</div>
    <div>Tradewell Fleet Monitoring System &nbsp;&#183;&nbsp; Confidential</div>
  </div>
</div>

<!-- Summary stats -->
${statsHtml}

<!-- Week legend -->
<div class="wk-legend">
  <span class="legend-lbl">Weeks:</span>
  ${legendHtml}
</div>

<!-- Main table -->
<table>
  <thead>
    ${theadHtml}
  </thead>
  <tbody>
    ${tbodyHtml}
  </tbody>
</table>

<!-- Footer -->
<div class="rpt-footer">
  <span>Fuel Dashboard &nbsp;&#183;&nbsp; Tradewell Fleet Monitoring System &nbsp;&#183;&nbsp; ${esc(_fcMonthLabel)}</span>
  <span>${_fcExportData.length} vehicles &nbsp;&#183;&nbsp; ${weekCount} weeks tracked</span>
</div>

</body>
</html>`;

    // ── 10. Open print window ─────────────────────────────────────────────────
    const w = window.open('', '_blank', 'width=1600,height=950,scrollbars=yes,resizable=yes');
    if (!w) {
        alert('Pop-up blocked. Please allow pop-ups for this site and try again.');
        return;
    }
    w.document.write(printHtml);
    w.document.close();

    // Wait for styles to load then prompt print
    w.addEventListener('load', function () {
        setTimeout(function () { w.print(); }, 500);
    });
}

// ── CSV Export ────────────────────────────────────────────────────────────
function fcExportCSV() {
    if (!_fcExportData?.length) { alert('No data to export.'); return; }

    const firstRow = _fcExportData[0];
    const allKeys  = Object.keys(firstRow);
    const weekLabels = [];
    allKeys.forEach(k => {
        const m = k.match(/^(Wk\d+\s+\([^)]+\))\s+Liters$/);
        if (m && !weekLabels.includes(m[1])) weekLabels.push(m[1]);
    });

    const headers = ['Department','Plate #','Vehicle Type','Total Refuels','Total Liters','Total Amount (PHP)'];
    weekLabels.forEach((wl, wi) => {
        const m = wl.match(/^Wk\d+\s+\(([^)]+)\)$/);
        const range = m ? m[1] : wl;
        headers.push(`Wk${wi+1} (${range}) Liters`);
        headers.push(`Wk${wi+1} (${range}) Amount (PHP)`);
        headers.push(`Wk${wi+1} (${range}) Refuels`);
    });

    const csvCell = v => {
        const s = String(v ?? '');
        return s.includes(',') || s.includes('"') || s.includes('\n')
            ? '"' + s.replace(/"/g,'""') + '"' : s;
    };

    const rows = [headers.map(csvCell).join(',')];
    _fcExportData.forEach(row => {
        const cells = [
            row['Department']  || '',
            row['Plate']       || row['PlateNumber'] || '',
            row['VehicleType'] || '',
            row['TotalRefuels'] ?? 0,
            row['TotalLiters']  ?? 0,
            row['TotalAmount']  ?? 0,
        ];
        weekLabels.forEach(wl => {
            cells.push(row[wl + ' Liters']  ?? 0);
            cells.push(row[wl + ' Amount']  ?? 0);
            cells.push(row[wl + ' Refuels'] ?? 0);
        });
        rows.push(cells.map(csvCell).join(','));
    });

    const blob = new Blob(['\uFEFF' + rows.join('\r\n')], { type:'text/csv;charset=utf-8;' });
    _fcTriggerDownload(blob, `Fuel_Monthly_${_fcMonthFile}.csv`);
}

// ── Excel Export ──────────────────────────────────────────────────────────
function fcExportExcel() {
    if (!_fcExportData?.length) { alert('No data to export.'); return; }
    if (typeof XLSX === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
        s.onload  = _fcBuildExcel;
        s.onerror = () => alert('Could not load Excel library. Check your internet connection.');
        document.head.appendChild(s);
    } else {
        _fcBuildExcel();
    }
}

function _fcBuildExcel() {
    const firstRow   = _fcExportData[0];
    const allKeys    = Object.keys(firstRow);
    const weekLabels = [];
    allKeys.forEach(k => {
        const m = k.match(/^(Wk\d+\s+\([^)]+\))\s+Liters$/);
        if (m && !weekLabels.includes(m[1])) weekLabels.push(m[1]);
    });
    const weekDisplay = weekLabels.map(wl => {
        const m = wl.match(/^(Wk\d+)\s+\(([^)]+)\)$/);
        return { full: wl, num: m ? m[1].replace('Wk','Wk ') : wl, range: m ? m[2] : '' };
    });
    const wkCount = weekDisplay.length;

    // Group same as print
    const grouped = {};
    _fcExportData.forEach(row => {
        const dept  = row['Department']  || '—';
        const vtype = row['VehicleType'] || '—';
        if (!grouped[dept])        grouped[dept]        = {};
        if (!grouped[dept][vtype]) grouped[dept][vtype] = [];
        grouped[dept][vtype].push(row);
    });

    const xlDeptFill = {
        monde:      { hdr:'FFFEF2F2', color:'FFB91C1C' },
        century:    { hdr:'FFEFF6FF', color:'FF1D4ED8' },
        multilines: { hdr:'FFFEFCE8', color:'FFA16207' },
        nutriasia:  { hdr:'FFF0FDF4', color:'FF047857' },
    };
    const xlWkFill = [
        { hdr:'FFEDE9FE', sub:'FFF5F3FF', color:'FF5B21B6' },
        { hdr:'FFDBEAFE', sub:'FFEFF6FF', color:'FF1D4ED8' },
        { hdr:'FFD1FAE5', sub:'FFF0FDF4', color:'FF065F46' },
        { hdr:'FFFEF3C7', sub:'FFFFFBEB', color:'FF92400E' },
        { hdr:'FFFEE2E2', sub:'FFFFF1F2', color:'FF991B1B' },
    ];
    const xlGrandFill  = 'FFE0E7FF';
    const xlGrandColor = 'FF3730A3';

    const xFill  = argb => ({ type:'pattern', patternType:'solid', fgColor:{argb} });
    const xFont  = (argb, bold=false, sz=9) => ({ name:'Arial', sz, bold, color:{argb} });
    const xAlign = (h='center', v='middle', wrap=false) => ({ horizontal:h, vertical:v, wrapText:wrap });
    const xBorder = (color='FFCBD5E1') => {
        const side = { style:'thin', color:{argb:color} };
        return { top:side, bottom:side, left:side, right:side };
    };

    const wb = XLSX.utils.book_new();
    const ws = {};
    const merges = [];
    let R = 0;

    const setCell = (c, r, value, style={}) => {
        ws[XLSX.utils.encode_cell({c,r})] = { v:value, t: typeof value==='number'?'n':'s', s:style };
    };
    const numCell = (c, r, value, style={}) => {
        ws[XLSX.utils.encode_cell({c,r})] = { v:value, t:'n', s:style };
    };
    const merge = (rs,re,cs,ce) => merges.push({s:{r:rs,c:cs},e:{r:re,c:ce}});

    // COL layout: Dept | Plate | Type | [Liters, Amount, Refuels] * wkCount | TotRefuels | TotLiters | TotAmount
    const COL_DEPT  = 0, COL_PLATE = 1, COL_TYPE = 2;
    const COL_WK0   = 3;
    const COL_TREF  = COL_WK0 + wkCount * 3;
    const COL_TL    = COL_TREF + 1;
    const COL_TA    = COL_TREF + 2;
    const LAST_COL  = COL_TA;

    // Title
    setCell(0, R, `Fuel Consumption Report — ${_fcMonthLabel}`, { font:xFont('FF1D4ED8',true,13), alignment:xAlign('left','middle') });
    merge(R,R,0,5); R++;
    setCell(0, R, `Generated: ${new Date().toLocaleString('en-PH')}`, { font:xFont('FF64748B',false,9) });
    merge(R,R,0,5); R++;
    R++; // blank

    const hS = (argb, colorArgb='FF1F2937') => ({
        fill:xFill(argb), font:xFont(colorArgb,true,9),
        alignment:xAlign('center','middle'), border:xBorder(),
    });

    // Header row 1
    setCell(COL_DEPT,  R, 'Department', hS('FFF1F5F9')); merge(R,R+2,COL_DEPT,COL_DEPT);
    setCell(COL_PLATE, R, 'Plate #',   hS('FFF1F5F9')); merge(R,R+2,COL_PLATE,COL_PLATE);
    setCell(COL_TYPE,  R, 'Type',      hS('FFF1F5F9')); merge(R,R+2,COL_TYPE,COL_TYPE);
    weekDisplay.forEach((w,wi) => {
        const fc=xlWkFill[wi%xlWkFill.length], c=COL_WK0+wi*3;
        setCell(c, R, `${w.num} (${w.range})`, hS(fc.hdr,fc.color)); merge(R,R,c,c+2);
    });
    setCell(COL_TREF, R, 'Grand Total', hS(xlGrandFill,xlGrandColor)); merge(R,R,COL_TREF,COL_TA);
    R++;

    // Header row 2
    weekDisplay.forEach((w,wi) => {
        const fc=xlWkFill[wi%xlWkFill.length], c=COL_WK0+wi*3;
        setCell(c, R, 'Fuel', hS(fc.hdr,fc.color)); merge(R,R,c,c+2);
    });
    setCell(COL_TREF, R, 'Summary', hS(xlGrandFill,xlGrandColor)); merge(R,R,COL_TREF,COL_TA);
    R++;

    // Header row 3
    weekDisplay.forEach((w,wi) => {
        const fc=xlWkFill[wi%xlWkFill.length], c=COL_WK0+wi*3;
        setCell(c,   R, 'Liters',  hS(fc.hdr,fc.color));
        setCell(c+1, R, 'Amount',  hS(fc.hdr,fc.color));
        setCell(c+2, R, 'Refuels', hS(fc.hdr,fc.color));
    });
    setCell(COL_TREF, R, 'Refuels',      hS(xlGrandFill,xlGrandColor));
    setCell(COL_TL,   R, 'Liters',       hS(xlGrandFill,xlGrandColor));
    setCell(COL_TA,   R, 'Amount (PHP)', hS(xlGrandFill,xlGrandColor));
    R++;

    let grandL=0, grandA=0, grandRef=0;
    const grandWkL=new Array(wkCount).fill(0), grandWkA=new Array(wkCount).fill(0), grandWkR=new Array(wkCount).fill(0);

    Object.entries(grouped).forEach(([dept, vtypes]) => {
        const dk  = dept.toLowerCase().trim();
        const dxf = xlDeptFill[dk] || { hdr:'FFF1F5F9', color:'FF475569' };

        // Dept banner
        setCell(COL_DEPT, R, `🏢 ${dept}`, {
            fill:xFill(dxf.hdr), font:xFont(dxf.color,true,10),
            alignment:xAlign('left','middle'), border:xBorder(),
        });
        merge(R,R,COL_DEPT,LAST_COL); R++;

        let dL=0, dA=0, dRef=0;
        const dWkL=new Array(wkCount).fill(0), dWkA=new Array(wkCount).fill(0), dWkR=new Array(wkCount).fill(0);

        Object.entries(vtypes).forEach(([vtype, rows]) => {
            const hasAny = rows.some(r => (parseFloat(r['TotalLiters'])||0)>0 || (parseFloat(r['TotalAmount'])||0)>0);
            if (!hasAny) return;

            // VType banner
            setCell(COL_DEPT,  R, '', { fill:xFill('FFEFF6FF'), border:xBorder() });
            setCell(COL_PLATE, R, `🚛 ${vtype}`, {
                fill:xFill('FFEFF6FF'), font:xFont('FF1D4ED8',true,9),
                alignment:xAlign('left','middle'), border:xBorder(),
            });
            merge(R,R,COL_PLATE,LAST_COL); R++;

            let vL=0, vA=0, vRef=0;
            const vWkL=new Array(wkCount).fill(0), vWkA=new Array(wkCount).fill(0), vWkR=new Array(wkCount).fill(0);

            rows.forEach((row, ri) => {
                const pL=parseFloat(row['TotalLiters'])||0, pA=parseFloat(row['TotalAmount'])||0, pRef=parseInt(row['TotalRefuels'],10)||0;
                if (pL===0 && pA===0) return;
                const stripe = ri%2===1 ? 'FFF8FAFC' : 'FFFFFFFF';
                const bS = { fill:xFill(stripe), border:xBorder(), alignment:xAlign('left','middle') };

                setCell(COL_DEPT,  R, dept,  { ...bS, fill:xFill(dxf.hdr), font:xFont(dxf.color,false,8), alignment:xAlign('center','middle') });
                setCell(COL_PLATE, R, row['Plate']||row['PlateNumber']||'', { ...bS, font:xFont('FF0F172A',true,9) });
                setCell(COL_TYPE,  R, vtype, { ...bS, font:xFont('FF94A3B8',false,8) });

                weekDisplay.forEach((w,wi) => {
                    const wL=parseFloat(row[w.full+' Liters'])||0;
                    const wA=parseFloat(row[w.full+' Amount'])||0;
                    const wR=parseInt(row[w.full+' Refuels'],10)||0;
                    const wc=COL_WK0+wi*3;
                    const fc=xlWkFill[wi%xlWkFill.length];
                    const wS={ fill:xFill(fc.sub), border:xBorder(), alignment:xAlign('right','middle') };
                    if (wR===0) {
                        setCell(wc, R, 'no refuel', { fill:xFill(fc.sub), font:xFont('FF94A3B8',false,8), alignment:xAlign('center','middle'), border:xBorder() });
                        merge(R,R,wc,wc+2);
                    } else {
                        numCell(wc,   R, wL, {...wS, numFmt:'#,##0.00'});
                        numCell(wc+1, R, wA, {...wS, numFmt:'"₱"#,##0.00'});
                        numCell(wc+2, R, wR, {...wS, numFmt:'0'});
                    }
                    vWkL[wi]+=wL; vWkA[wi]+=wA; vWkR[wi]+=wR;
                });
                numCell(COL_TREF, R, pRef, { fill:xFill(stripe), border:xBorder(), font:xFont('FF0F172A',true,9), alignment:xAlign('right','middle'), numFmt:'0' });
                numCell(COL_TL,   R, pL,   { fill:xFill(stripe), border:xBorder(), font:xFont('FF0D9488',true,9), alignment:xAlign('right','middle'), numFmt:'#,##0.00' });
                numCell(COL_TA,   R, pA,   { fill:xFill(stripe), border:xBorder(), font:xFont('FF0F172A',true,9), alignment:xAlign('right','middle'), numFmt:'"₱"#,##0.00' });
                R++; vL+=pL; vA+=pA; vRef+=pRef;
            });

            // VType subtotal
            const vSS = { fill:xFill('FFEFF6FF'), border:xBorder(), font:xFont('FF1E40AF',true,9), alignment:xAlign('right','middle') };
            setCell(COL_DEPT,  R, '', { fill:xFill('FFEFF6FF'), border:xBorder() });
            setCell(COL_PLATE, R, `↳ Subtotal — ${vtype}`, { fill:xFill('FFEFF6FF'), font:xFont('FF1E40AF',true,9), alignment:xAlign('left','middle'), border:xBorder() });
            setCell(COL_TYPE,  R, '', { fill:xFill('FFEFF6FF'), border:xBorder() });
            weekDisplay.forEach((_,wi) => {
                const wc=COL_WK0+wi*3, fc=xlWkFill[wi%xlWkFill.length];
                const ss={...vSS, fill:xFill(fc.sub)};
                numCell(wc,   R, vWkL[wi], {...ss, numFmt:'#,##0.00'});
                numCell(wc+1, R, vWkA[wi], {...ss, numFmt:'"₱"#,##0.00'});
                numCell(wc+2, R, vWkR[wi], {...ss, numFmt:'0'});
                dWkL[wi]+=vWkL[wi]; dWkA[wi]+=vWkA[wi]; dWkR[wi]+=vWkR[wi];
            });
            numCell(COL_TREF, R, vRef, {...vSS, numFmt:'0'});
            numCell(COL_TL,   R, vL,   {...vSS, numFmt:'#,##0.00'});
            numCell(COL_TA,   R, vA,   {...vSS, numFmt:'"₱"#,##0.00'});
            R++; dL+=vL; dA+=vA; dRef+=vRef;
        });

        // Dept subtotal
        const dSS = { fill:xFill(dxf.hdr), border:xBorder(), font:xFont(dxf.color,true,10), alignment:xAlign('right','middle') };
        setCell(COL_DEPT, R, `🏢 ${dept} Total`, {...dSS, alignment:xAlign('left','middle')});
        merge(R,R,COL_DEPT,COL_TYPE);
        weekDisplay.forEach((_,wi) => {
            const wc=COL_WK0+wi*3, fc=xlWkFill[wi%xlWkFill.length];
            const ss={...dSS, fill:xFill(fc.sub)};
            numCell(wc,   R, dWkL[wi], {...ss, numFmt:'#,##0.00'});
            numCell(wc+1, R, dWkA[wi], {...ss, numFmt:'"₱"#,##0.00'});
            numCell(wc+2, R, dWkR[wi], {...ss, numFmt:'0'});
            grandWkL[wi]+=dWkL[wi]; grandWkA[wi]+=dWkA[wi]; grandWkR[wi]+=dWkR[wi];
        });
        numCell(COL_TREF, R, dRef, {...dSS, numFmt:'0'});
        numCell(COL_TL,   R, dL,   {...dSS, numFmt:'#,##0.00'});
        numCell(COL_TA,   R, dA,   {...dSS, numFmt:'"₱"#,##0.00'});
        R++; grandL+=dL; grandA+=dA; grandRef+=dRef;
    });

    // Grand total
    const gtS = { fill:xFill(xlGrandFill), border:xBorder(), font:xFont(xlGrandColor,true,11), alignment:xAlign('right','middle') };
    setCell(COL_DEPT, R, '🏁 Grand Total', {...gtS, alignment:xAlign('left','middle')});
    merge(R,R,COL_DEPT,COL_TYPE);
    weekDisplay.forEach((_,wi) => {
        const wc=COL_WK0+wi*3, fc=xlWkFill[wi%xlWkFill.length];
        const ss={...gtS, fill:xFill(fc.sub)};
        numCell(wc,   R, grandWkL[wi], {...ss, numFmt:'#,##0.00'});
        numCell(wc+1, R, grandWkA[wi], {...ss, numFmt:'"₱"#,##0.00'});
        numCell(wc+2, R, grandWkR[wi], {...ss, numFmt:'0'});
    });
    numCell(COL_TREF, R, grandRef, {...gtS, numFmt:'0'});
    numCell(COL_TL,   R, grandL,   {...gtS, numFmt:'#,##0.00'});
    numCell(COL_TA,   R, grandA,   {...gtS, numFmt:'"₱"#,##0.00'});
    R++;

    ws['!ref']    = XLSX.utils.encode_range({s:{r:0,c:0}, e:{r:R-1,c:LAST_COL}});
    ws['!merges'] = merges;

    // Column widths
    const colW = [{wch:14},{wch:12},{wch:12}];
    for (let i=0; i<wkCount; i++) colW.push({wch:10},{wch:13},{wch:8});
    colW.push({wch:8},{wch:10},{wch:14});
    ws['!cols'] = colW;

    ws['!freeze'] = { xSplit:3, ySplit:5, topLeftCell:'D6', activePane:'bottomRight' };

    XLSX.utils.book_append_sheet(wb, ws, _fcMonthLabel.replace(/[/\\?*[\]]/g,''));
    XLSX.writeFile(wb, `Fuel_Monthly_${_fcMonthFile}.xlsx`);
}

// ── Shared download trigger ───────────────────────────────────────────────
function _fcTriggerDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 5000);
}

</script>
</body>
</html>