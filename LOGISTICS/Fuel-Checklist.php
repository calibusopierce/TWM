<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic']);
require_once __DIR__ . '/fuel_shared.php';

$activeTab = 'checklist';
$checklistFilterActive = $dateActive || $vtypeActive || $plateActive || $driverActive;

$checklistFrom = $baseFrom;
$checklistTo   = $baseTo;

// Prevent infinite execution
set_time_limit(15);
ini_set('memory_limit', '256M');

$tab = 'checklist';
[$deptList, $vtypeList, $plateList] = loadLookups($conn, $selVtype, $_selVtypeSafe);
$stats        = loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
$anomalyCount = loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL);
['trucks' => $totalTrucks, 'liters' => $totalLiters, 'amount' => $totalAmount, 'refuels' => $totalRefuels] = $stats;

$data = [];

if ($checklistFilterActive) {

    $data = runQuery($conn, "
        SELECT TOP 500
            DAY(ts.ScheduleDate) AS [Day],
            ts.ScheduleDate AS [Date],
            CONVERT(VARCHAR(8), f.FuelTime, 108) AS [Fuel Time],
            ts.PlateNumber AS [Plate Number],
            ts.Department AS [Department],
            v.Vehicletype AS [Vehicle Type],
            (SELECT TOP 1 td.Employee_Name 
             FROM [dbo].[teamschedule] td 
             WHERE td.PlateNumber = ts.PlateNumber 
               AND td.ScheduleDate = ts.ScheduleDate 
               AND td.Position LIKE '%DRIVER%') AS [Sched. Driver],
            ts.Area AS [Sched. Area],
            f.Requested AS [Driver],
            f.ORnumber AS [INV #],
            ROUND(f.Liters, 2) AS [Liters],
            ROUND(f.Amount, 2) AS [Amount],
            CASE WHEN f.FuelID IS NOT NULL THEN 'REFUELED' ELSE 'NOT REFUELED' END AS [Status]
        FROM [dbo].[TruckSchedule] ts
        LEFT JOIN [dbo].[Tbl_fuel] f 
            ON f.PlateNumber = ts.PlateNumber 
           AND f.Fueldate = ts.ScheduleDate
        LEFT JOIN [dbo].[Vehicle] v 
            ON v.PlateNumber = ts.PlateNumber
        WHERE ts.ScheduleDate BETWEEN '$baseFrom' AND '$baseTo'
          AND ts.PlateNumber IS NOT NULL 
          AND ts.PlateNumber <> ''
          $deptWhereF $vtypeWhereF $plateWhereF $driverWhereF $areaWhereF
        ORDER BY ts.ScheduleDate DESC");
}

$rowLimit    = 20;
$totalRows   = count($data);
$totalPages  = max(1, (int)ceil($totalRows / $rowLimit));
$curPage     = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset      = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

$refueledCount    = count(array_filter($data, fn($r) => ($r['Status'] ?? '') === 'REFUELED'));
$notRefueledCount = count(array_filter($data, fn($r) => ($r['Status'] ?? '') === 'NOT REFUELED'));

sqlsrv_close($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Checklist — Fuel Dashboard</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<?php renderSharedStyles(); ?>
</head>

<body>
    <div id="loadingOverlay" style="display:none;position:fixed;inset:0;background:rgba(255,255,255,.7);z-index:9999;align-items:center;justify-content:center;">
  <div style="font-weight:700;color:#3b82f6;">⏳ Loading Monthly Checklist...</div>
</div>

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
    $dateActive,
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
      <div class="table-title">✅ Refuel Checklist
        <span class="table-count"><?= $totalRows ?> rows</span>
        <span class="table-count" style="background:#dcfce7;color:#166534;border-color:#86efac;">✅ <?= $refueledCount ?> Refueled</span>
        <span class="table-count" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">❌ <?= $notRefueledCount ?> Not Refueled</span>
      </div>
      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <span style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:.25rem .6rem;border-radius:.4rem;font-size:.8rem;">
          <i class="bi bi-calendar-check"></i>
          <?= $checklistFilterActive ? htmlspecialchars($checklistFrom) . ' → ' . htmlspecialchars($checklistTo) : 'Apply a filter to load data' ?>
        </span>
        <input type="text" class="search-box" id="searchBox" placeholder="🔍 Search plate, driver, area..." oninput="filterTable(this.value)">
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print" onclick="checklistPrint()"><i class="bi bi-printer"></i> Print</button>
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
        <th onclick="sortTable(5)">Vehicle Type <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)">Sched. Driver <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)">Sched. Area <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)">INV # <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Liters <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Amount <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)">Status <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="12"><div class="empty-state">
          <?php if (!$checklistFilterActive): ?>
            <span class="icon">🔍</span><p>Apply a <strong>date range</strong> or <strong>vehicle type</strong> filter to load checklist data.</p>
          <?php else: ?>
            <span class="icon">📭</span><p>No scheduled deliveries for this date range.</p>
          <?php endif; ?>
        </div></td></tr>
      <?php else: foreach ($displayData as $row):
          $refueled = (($row['Status'] ?? '') === 'REFUELED');
          $rowClass = $refueled ? 'row-refueled' : 'row-not-refueled';
          $dateVal  = $row['Date'] instanceof DateTime ? $row['Date']->format('Y-m-d') : htmlspecialchars($row['Date'] ?? '');
      ?>
        <tr class="<?= $rowClass ?>">
          <td class="right mono dim bold"><?= htmlspecialchars((string)($row['Day'] ?? '—')) ?></td>
          <td class="mono dim"><?= $dateVal ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['Fuel Time'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td><span class="plate"><?= htmlspecialchars($row['Plate Number'] ?? '—') ?></span></td>
          <td><?= deptBadge($row['Department'] ?? '') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Vehicle Type'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Driver'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Sched. Area'] ?? '—') ?></td>
          <td class="mono dim"><?= $refueled ? htmlspecialchars($row['INV #'] ?? '—') : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? fmt($row['Liters']) . ' L' : '<span class="text-muted">—</span>' ?></td>
          <td class="right mono bold"><?= $refueled ? peso($row['Amount']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= $refueled ? "<span class='badge badge-everyday'>✅ Refueled</span>" : "<span class='badge badge-norefuel'>❌ Not Refueled</span>" ?></td>
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

<script>

/**
 * checklistPrint()
 * ─────────────────────────────────────────────────────────────────
 * Prints the Refuel Checklist table exactly as it looks on screen:
 *   • Green rows  → REFUELED
 *   • Pink rows   → NOT REFUELED
 *   • Plate badges (blue pill)
 *   • Dept badges  (color-coded pill)
 *   • Monospace numbers, dashes for empty values
 *
 * Fixes vs old version:
 *   1. Moved OUT of the submit event listener (now top-level)
 *   2. Reads from _checklistData (set by renderSharedJS)
 *   3. Faithful green/red row highlighting matching the screen UI
 *   4. Page-break-inside: avoid on each row
 *   5. Pop-up hint if blocked
 * ─────────────────────────────────────────────────────────────────
 * Expected global vars (set in renderSharedJS PHP output):
 *   _checklistData  — array of row objects
 *   _checklistLabel — e.g. "March 2026"
 */
function checklistPrint() {
    // ── Guard ────────────────────────────────────────────────────────
    if (!window._checklistData || !_checklistData.length) {
        alert('No checklist data to print. Please apply a filter first.');
        return;
    }

    const rows       = _checklistData;
    const monthLabel = window._checklistLabel || '';

    // ── Helpers ──────────────────────────────────────────────────────
    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    const dash = v => (!v || String(v).trim() === '' || String(v).trim() === '—')
        ? '<span class="dim-dash">—</span>'
        : esc(v);

    const fmtL = v => {
        const n = parseFloat(v) || 0;
        return n === 0 ? '<span class="dim-dash">—</span>'
            : `<span class="mono">${n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})} L</span>`;
    };

    const fmtP = v => {
        const n = parseFloat(v) || 0;
        return n === 0 ? '<span class="dim-dash">—</span>'
            : `<span class="mono">₱${n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`;
    };

    // ── Dept badge colors ────────────────────────────────────────────
    const deptColors = {
        monde:      { bg:'#fee2e2', color:'#b91c1c', border:'#fca5a5' },
        century:    { bg:'#dbeafe', color:'#1d4ed8', border:'#93c5fd' },
        multilines: { bg:'#fef9c3', color:'#a16207', border:'#fde047' },
        nutriasia:  { bg:'#d1fae5', color:'#047857', border:'#6ee7b7' },
    };

    function deptBadgeHtml(dept) {
        const key = String(dept).toLowerCase().trim();
        const c = deptColors[key] || { bg:'#f1f5f9', color:'#475569', border:'#cbd5e1' };
        return `<span class="badge" style="background:${c.bg};color:${c.color};border-color:${c.border};">${esc(dept)}</span>`;
    }

    function plateBadgeHtml(plate) {
        if (!plate || plate.trim() === '') return '<span class="dim-dash">—</span>';
        return `<span class="plate-badge">${esc(plate)}</span>`;
    }

    function statusBadgeHtml(refueled) {
        return refueled
            ? `<span class="badge badge-refueled">✅ REFUELED</span>`
            : `<span class="badge badge-notrefueled">✕ NOT REFUELED</span>`;
    }

    // ── Summary counts ───────────────────────────────────────────────
    const refueledCount    = rows.filter(r => String(r['Status']||'').toUpperCase() === 'REFUELED').length;
    const notRefueledCount = rows.length - refueledCount;

    // ── Build table rows ─────────────────────────────────────────────
    let tbodyHtml = '';
    rows.forEach((row, i) => {
        const refueled = String(row['Status'] || '').toUpperCase() === 'REFUELED';
        const rowClass = refueled ? 'row-refueled' : 'row-not-refueled';

        // Format date
        let dateVal = row['Date'] || '';
        if (dateVal instanceof Object && dateVal.date) dateVal = dateVal.date.substring(0, 10);
        else if (typeof dateVal === 'string' && dateVal.length > 10) dateVal = dateVal.substring(0, 10);

        tbodyHtml += `<tr class="${rowClass}">
            <td class="center mono bold">${esc(String(row['Day'] || '—'))}</td>
            <td class="mono">${esc(dateVal) || '<span class="dim-dash">—</span>'}</td>
            <td class="mono center">${refueled ? esc(row['Fuel Time'] || '—') : '<span class="dim-dash">—</span>'}</td>
            <td class="center">${plateBadgeHtml(row['Plate Number'])}</td>
            <td class="center">${deptBadgeHtml(row['Department'] || '—')}</td>
            <td>${esc(row['Vehicle Type'] || '—')}</td>
            <td>${esc(row['Sched. Driver'] || '—')}</td>
            <td>${esc(row['Sched. Area'] || '—')}</td>
            <td class="mono center">${refueled ? esc(row['INV #'] || '—') : '<span class="dim-dash">—</span>'}</td>
            <td class="right">${refueled ? fmtL(row['Liters']) : '<span class="dim-dash">—</span>'}</td>
            <td class="right">${refueled ? fmtP(row['Amount']) : '<span class="dim-dash">—</span>'}</td>
            <td class="center">${statusBadgeHtml(refueled)}</td>
        </tr>`;
    });

    // ── Full print HTML ──────────────────────────────────────────────
    const printHtml = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Refuel Checklist — ${esc(monthLabel)}</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 12px;
    color: #0f172a;
    background: #fff;
    padding: 14px 16px;
  }

  /* ── Print bar ────────────────────────────────────────────── */
  .print-bar {
    display: flex; gap: 8px; align-items: center;
    padding: 8px 12px; background: #f8fafc;
    border: 1px solid #e2e8f0; border-radius: 8px;
    margin-bottom: 14px;
  }
  .print-bar button {
    font-size: 12px; padding: 5px 16px;
    border: 1px solid #cbd5e1; border-radius: 6px;
    background: #fff; cursor: pointer; font-weight: 600;
  }
  .print-bar button.primary { background: #1e40af; color: #fff; border-color: #1e40af; }
  .print-bar .hint { font-size: 12px; color: #94a3b8; }

  /* ── Report header ────────────────────────────────────────── */
  .rpt-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 2.5px solid #1e40af;
    padding-bottom: 8px; margin-bottom: 10px;
  }
  .rpt-title  { font-size: 15px; font-weight: 800; color: #1e40af; }
  .rpt-sub    { font-size: 9px; color: #64748b; margin-top: 2px; }
  .rpt-meta   { text-align: right; font-size: 8.5px; color: #64748b; line-height: 1.6; }
  .rpt-month  { font-size: 13px; font-weight: 800; color: #1e40af; }

  /* ── Stat strip ───────────────────────────────────────────── */
  .stat-strip {
    display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap;
  }
  .stat-box {
    padding: 5px 14px; border-radius: 8px;
    border: 1px solid; text-align: center; min-width: 90px;
  }
  .stat-box .lbl { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .stat-box .val { font-size: 14px; font-weight: 800; font-family: 'Courier New', monospace; }
  .stat-total  { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
  .stat-green  { background:#f0fdf4; border-color:#6ee7b7; color:#047857; }
  .stat-red    { background:#fff1f2; border-color:#fca5a5; color:#b91c1c; }

  /* ── Table ────────────────────────────────────────────────── */
  table {
    width: 100%; border-collapse: collapse;
    font-size: 12px;
    table-layout: auto;
  }

  thead tr th {
    background: #1e40af; color: #fff;
    padding: 3px 5px;
    border: 1px solid #1e3a8a;
    text-align: left;
    white-space: nowrap;
    font-size: 12px; font-weight: 700;
    letter-spacing: .03em;
    position: sticky; top: 0;
  }
  thead tr th.center { text-align: center; }
  thead tr th.right  { text-align: right; }

  tbody tr { page-break-inside: avoid; }

  tbody td {
    padding: 2px 5px;
    border: 1px solid #e2e8f0;
    vertical-align: middle;
    white-space: nowrap;
  }

  /* ── Row colors ───────────────────────────────────────────── */
  tr.row-refueled td {
    background: #f0fdf4;         /* light green */
  }
  tr.row-not-refueled td {
    background: #fff1f2;         /* light pink/red */
  }
  /* Subtle zebra within each group */
  tr.row-refueled:nth-of-type(even) td    { background: #dcfce7; }
  tr.row-not-refueled:nth-of-type(even) td { background: #ffe4e6; }

  /* ── Badges ───────────────────────────────────────────────── */
  .badge {
    display: inline-block;
    font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 20px;
    border: 1px solid;
    white-space: nowrap;
  }
  .badge-refueled {
    background: #dcfce7; color: #166534; border-color: #86efac;
  }
  .badge-notrefueled {
    background: #fee2e2; color: #991b1b; border-color: #fca5a5;
  }

  .plate-badge {
    display: inline-block;
    font-size: 8px; font-weight: 700;
    padding: 2px 8px; border-radius: 6px;
    background: #eff6ff; color: #1d4ed8;
    border: 1px solid #93c5fd;
    font-family: 'Courier New', monospace;
    letter-spacing: .05em;
  }

  /* ── Utility ──────────────────────────────────────────────── */
  .mono      { font-family: 'Courier New', monospace; }
  .bold      { font-weight: 700; }
  .center    { text-align: center; }
  .right     { text-align: right; }
  .dim-dash  { color: #94a3b8; font-family: 'Courier New', monospace; }

  /* ── Footer ───────────────────────────────────────────────── */
  .rpt-footer {
    margin-top: 10px; padding-top: 6px;
    border-top: 1px solid #e2e8f0;
    font-size: 7.5px; color: #94a3b8;
    display: flex; justify-content: space-between;
  }

  /* ── Print overrides ──────────────────────────────────────── */
  @media print {
    .no-print { display: none !important; }
    body { padding: 4px 6px; font-size: 15px; }
    table { font-size: 10px; }
    thead tr th { font-size: 15px; padding: 2px 3px; }
    tbody td { padding: 2px 3px; }

    /* Keep row colors in print */
    tr.row-refueled td {
      background: #f0fdf4 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    tr.row-not-refueled td {
      background: #fff1f2 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    tr.row-refueled:nth-of-type(even) td {
      background: #dcfce7 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    tr.row-not-refueled:nth-of-type(even) td {
      background: #ffe4e6 !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .badge, .plate-badge {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    @page { size: letter landscape; margin: 10mm; }
  }
</style>
</head>
<body>

<!-- Print bar -->
<div class="print-bar no-print">
  <button class="primary" onclick="window.print()">🖨 Print / Save as PDF</button>
  <button onclick="window.close()">✕ Close</button>
  <span class="hint">Tip: Use A3 Landscape · Enable "Background graphics" in print settings to keep row colors.</span>
</div>

<!-- Report header -->
<div class="rpt-header">
  <div>
    <div class="rpt-title">Tradewell Fleet Monitoring System</div>
    <div class="rpt-sub">Refuel Checklist — Scheduled vs. Actual Refueling</div>
  </div>
  <div class="rpt-meta">
    <div class="rpt-month">${esc(monthLabel)}</div>
    <div>Generated: ${new Date().toLocaleString('en-PH',{year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'})}</div>
    <div>Tradewell Fleet Monitoring System &nbsp;·&nbsp; Confidential</div>
  </div>
</div>

<!-- Stat strip -->
<div class="stat-strip">
  <div class="stat-box stat-total">
    <div class="lbl">Total Rows</div>
    <div class="val">${rows.length}</div>
  </div>
  <div class="stat-box stat-green">
    <div class="lbl">✅ Refueled</div>
    <div class="val">${refueledCount}</div>
  </div>
  <div class="stat-box stat-red">
    <div class="lbl">✕ Not Refueled</div>
    <div class="val">${notRefueledCount}</div>
  </div>
  <div class="stat-box stat-total" style="min-width:110px;">
    <div class="lbl">Refuel Rate</div>
    <div class="val">${rows.length ? Math.round(refueledCount/rows.length*100) : 0}%</div>
  </div>
</div>

<!-- Main table -->
<table>
  <thead>
    <tr>
      <th class="center">Day</th>
      <th>Date</th>
      <th class="center">Fuel Time</th>
      <th class="center">Plate #</th>
      <th class="center">Dept</th>
      <th>Veh. Type</th>
      <th>Sched. Driver</th>
      <th>Area</th>
      <th class="center">INV #</th>
      <th class="right">Liters</th>
      <th class="right">Amount</th>
      <th class="center">Status</th>
    </tr>
  </thead>
  <tbody>
    ${tbodyHtml}
  </tbody>
</table>

<!-- Footer -->
<div class="rpt-footer">
  <span>Fuel Dashboard &nbsp;·&nbsp; Tradewell Fleet Monitoring System &nbsp;·&nbsp; ${esc(monthLabel)}</span>
  <span>${rows.length} rows &nbsp;·&nbsp; ${refueledCount} refueled &nbsp;·&nbsp; ${notRefueledCount} not refueled</span>
</div>

</body>
</html>`;

    // ── Open print window ────────────────────────────────────────────
    const w = window.open('', '_blank', 'width=1400,height=900,scrollbars=yes,resizable=yes');
    if (!w) {
        alert('Pop-up blocked! Please allow pop-ups for this site, then click Print again.');
        return;
    }
    w.document.write(printHtml);
    w.document.close();

    w.addEventListener('load', function () {
        setTimeout(() => w.print(), 600);
    });
}

// ── Checklist print data exposed by PHP ──────────────────────────────────────
window._checklistData = <?php
    // Sanitize for JSON: convert DateTime objects to strings
    $printData = array_map(function($row) {
        $clean = [];
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $clean[$k] = $v->format('Y-m-d');
            } else {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }, $data ?? []);
    echo json_encode($printData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>;

window._checklistLabel = <?= json_encode(
    isset($baseFrom, $baseTo)
        ? date('F Y', strtotime($baseFrom)) . ($baseFrom !== $baseTo ? ' (' . $baseFrom . ' → ' . $baseTo . ')' : '')
        : date('F Y')
) ?>;



</script>

</html>
