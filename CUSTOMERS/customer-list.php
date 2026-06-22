<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'customer_list');

// ── Current user context ─────────────────────────────────────
$currentUserDept = trim($_SESSION['Department'] ?? ($_SESSION['department'] ?? ''));
$deptActive   = $currentUserDept !== '' && strtolower($currentUserDept) !== 'all';
$_deptSafe    = str_replace("'", "''", $currentUserDept);
$deptWhere    = $deptActive ? " AND RTRIM(LTRIM(Department)) = RTRIM(LTRIM('$_deptSafe'))" : '';

// ── Valid tabs ───────────────────────────────────────────────
$validTabs = ['delivery', 'ar'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : 'delivery';

// ── Filters ──────────────────────────────────────────────────
$branchList  = ['Quezon', 'Quezon Upper', 'Marinduque'];
$selBranch   = isset($_GET['branch'])   ? trim($_GET['branch'])   : '';
$selSalesman = isset($_GET['salesman']) ? trim($_GET['salesman']) : '';
$selArea     = isset($_GET['area'])     ? trim($_GET['area'])     : '';

// ── Date filter — default to last 7 days (lighter default load) ──
$defaultFrom = date('Y-m-d', strtotime('-6 days')); // last 7 days, inclusive of today
$defaultTo   = date('Y-m-d');
$dateFrom    = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : $defaultFrom;
$dateTo      = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : $defaultTo;
$dateActive  = isset($_GET['date_from']) || isset($_GET['date_to']);
$_dateFromSafe = str_replace("'", "''", $dateFrom);
$_dateToSafe   = str_replace("'", "''", $dateTo);
// Delivery uses DocDate, AR uses DeliveryDate
$dateColDelivery = 'DocDate';
$dateColAR       = 'DeliveryDate';
$dateWhereDelivery = " AND $dateColDelivery BETWEEN '$_dateFromSafe' AND '$_dateToSafe'";
$dateWhereAR       = " AND $dateColAR BETWEEN '$_dateFromSafe' AND '$_dateToSafe'";

$branchActive   = $selBranch   !== '';
$salesmanActive = $selSalesman !== '';
$areaActive     = $selArea     !== '';
$anyFilterApplied = $dateActive || $branchActive || $salesmanActive || $areaActive;

$_branchSafe   = str_replace("'", "''", $selBranch);
$_salesmanSafe = str_replace("'", "''", $selSalesman);
$_areaSafe     = str_replace("'", "''", $selArea);

$branchWhere   = $branchActive   ? " AND Branch = '$_branchSafe'"     : '';
$salesmanWhere = $salesmanActive ? " AND Salesman = '$_salesmanSafe'" : '';
$areaWhereDelivery = $areaActive ? " AND Area = '$_areaSafe'"   : '';
$areaWhereAR       = $areaActive ? " AND ARArea = '$_areaSafe'" : '';
$commonWhereDelivery = $deptWhere . $dateWhereDelivery . $branchWhere . $salesmanWhere . $areaWhereDelivery;
$commonWhereAR       = $deptWhere . $dateWhereAR       . $branchWhere . $salesmanWhere . $areaWhereAR;

// ── Source view per tab ────────────────────────────────────────
$tabSource = [
    'delivery' => ['view' => '[dbo].[View_RemittanceCollectionSlip2]', 'mop' => 'Remarks'],
    'ar'       => ['view' => '[dbo].[View_ARForCollectionDetails]',    'mop' => 'RemitRemarks'],
];

// ── Helper: run a query and return all rows ──────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        error_log('customer_list SQL error: ' . json_encode(sqlsrv_errors()));
        return [];
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function lookupList($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $list = [];
    $seen = []; // case-insensitive de-dupe (handles "JOHN DOE" vs "John Doe " vs "john doe")
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $val = array_values($row)[0];
        if ($val === null) continue;
        $val = trim((string)$val);
        if ($val === '') continue;
        $key = mb_strtoupper($val);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $list[] = $val;
    }
    sqlsrv_free_stmt($stmt);
    sort($list, SORT_STRING | SORT_FLAG_CASE);
    return $list;
}

// ── Dropdown lists (scoped to department + active tab view) ──
$src = $tabSource[$tab];
$salesmanList = lookupList($conn,
    "SELECT DISTINCT Salesman FROM {$src['view']}
     WHERE Salesman IS NOT NULL AND Salesman <> '' {$deptWhere}
     ORDER BY Salesman"
);
$areaCol = ($tab === 'ar') ? 'ARArea' : 'Area';
$areaList = lookupList($conn,
    "SELECT DISTINCT $areaCol FROM {$src['view']}
     WHERE $areaCol IS NOT NULL AND $areaCol <> '' {$deptWhere}
     ORDER BY $areaCol"
);

// ── Customer list query — Delivery + AR combined via UNION ALL ──
// One round trip instead of three (main query + 2 count queries).
// GROUP BY Customer + Department — one row per customer, MAX()
// picks the latest Branch/Area/Salesman/ModeOfPayment. A 'Source'
// discriminator lets PHP split the combined result back out by tab.
$deliverySql = "SELECT
                'delivery'          AS Source,
                Customer,
                Department,
                MAX(Branch)         AS Branch,
                MAX(Area)           AS Area,
                MAX(Salesman)       AS Salesman,
                MAX(Remarks)        AS ModeOfPayments,
                SUM(NetAmount)      AS TotalAmount
            FROM [dbo].[View_RemittanceCollectionSlip2]
            WHERE Customer IS NOT NULL AND Customer <> '' {$commonWhereDelivery}
              AND ISNULL(RRID, 0) > 0
              AND Remarks IN ('CASH', 'CHECK', 'CREDIT')
            GROUP BY Customer, Department";

$arSql = "SELECT
                'ar'                 AS Source,
                CustomerName1        AS Customer,
                Department,
                MAX(Branch)          AS Branch,
                MAX(ARArea)          AS Area,
                MAX(Salesman)        AS Salesman,
                MAX(RemitRemarks)    AS ModeOfPayments,
                SUM(InvoiceAmount)   AS TotalAmount
            FROM [dbo].[View_ARForCollectionDetails]
            WHERE CustomerName1 IS NOT NULL AND CustomerName1 <> ''
              AND ISNULL(RRID, 0) > 0
              AND RemitRemarks IN ('CASH', 'CHECK', 'RETURN')
              {$commonWhereAR}
            GROUP BY CustomerName1, Department";

$combinedSql = "{$deliverySql}\nUNION ALL\n{$arSql}\nORDER BY TotalAmount DESC";

$allData = runQuery($conn, $combinedSql);

// ── Split combined result back out per tab (order is preserved) ─
$data          = array_values(array_filter($allData, fn($r) => $r['Source'] === $tab));
$deliveryCount = count(array_filter($allData, fn($r) => $r['Source'] === 'delivery'));
$arCount       = count(array_filter($allData, fn($r) => $r['Source'] === 'ar'));
foreach ($data as &$row) { unset($row['Source']); } // internal-only, keep exports clean
unset($row);

// ── Pagination (PHP-based, no extra COUNT query) ───────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

// NOTE: $conn is intentionally NOT closed here — topbar.php (included
// below) needs it for get_employee_profile(). PHP closes it on script end.

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

function tabUrl(string $t): string {
    $p = $_GET; $p['tab'] = $t; unset($p['page']);
    return '?' . http_build_query($p);
}

// ── Badge helpers ────────────────────────────────────────────
function deptBadge(?string $d): string {
    $map = [
        'monde'      => ['#fee2e2', '#b91c1c', '#fca5a5'],
        'century'    => ['#dbeafe', '#1e40af', '#93c5fd'],
        'multilines' => ['#fef9c3', '#854d0e', '#fde047'],
        'nutriasia'  => ['#d1fae5', '#047857', '#6ee7b7'],
        'silverswan' => ['#e0e7ff', '#4338ca', '#a5b4fc'],
        ''           => ['#f3f4f6', '#6b7280', '#d1d5db'],
    ];
    $key = strtolower(trim($d ?? ''));
    [$bg, $color, $border] = $map[$key] ?? $map[''];
    $label = htmlspecialchars(trim($d ?? '') !== '' ? trim($d) : '—');
    return "<span class='badge-pill' style='background:$bg;color:$color;border-color:$border;'>$label</span>";
}

function branchBadge(?string $b): string {
    $map = [
        'quezon'       => ['#ede9fe', '#5b21b6', '#c4b5fd'],
        'quezon upper' => ['#dbeafe', '#1e3a8a', '#93c5fd'],
        'marinduque'   => ['#dcfce7', '#166534', '#86efac'],
    ];
    [$bg, $color, $border] = $map[strtolower(trim($b ?? ''))] ?? ['#f3f4f6', '#374151', '#d1d5db'];
    $label = htmlspecialchars(trim($b ?? '') !== '' ? trim($b) : '—');
    return "<span class='badge-pill' style='background:$bg;color:$color;border-color:$border;'>$label</span>";
}

function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer List — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
  --cl-accent  : #0d9488;
  --cl-accent2 : #5eead4;
  --cl-blue    : #2563eb;
}

/* ── Stat Cards ─────────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
  gap: .875rem;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
  padding: 1rem 1.2rem 1.3rem;
  display: flex; flex-direction: column; gap: .2rem;
  text-decoration: none; cursor: pointer;
}
.stat-card:hover { border-color: var(--cl-accent2); }
.sc-icon  { font-size: 1.4rem; margin-bottom: .15rem; }
.sc-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: var(--text-dim, #6b7280); font-weight: 700; }
.sc-value { font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: var(--text, #111827); font-family: 'JetBrains Mono', monospace; }

/* ── Filter Panel ───────────────────────────────────── */
.filter-panel {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
  margin-bottom: 1rem;
  overflow: hidden;
}
.filter-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1.25rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #f9fafb);
  cursor: pointer; user-select: none;
}
.filter-panel-header-left {
  display: flex; align-items: center; gap: .6rem;
  font-size: .82rem; font-weight: 700; color: var(--text, #374151);
}
.filter-active-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.filter-tag {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: 2px 9px; border-radius: 999px; font-size: .7rem; font-weight: 600;
  background: #ccfbf1; color: #0f766e; border: 1px solid #5eead4; white-space: nowrap;
}
.filter-toggle-icon { font-size: .75rem; color: var(--text-dim, #9ca3af); transition: transform .15s; }
.filter-toggle-icon.open { transform: rotate(180deg); }
.filter-body { padding: 1rem 1.25rem 1.1rem; display: none; }
.filter-body.open { display: block; }
.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem; margin-bottom: .875rem;
}
.filter-group { display: flex; flex-direction: column; gap: .3rem; }
.filter-group label {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .05em; color: var(--text-dim, #6b7280);
}
.filter-group input[type=date],
.filter-group select {
  font-size: .82rem; padding: .4rem .7rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; background: var(--input-bg, #f9fafb);
  color: var(--text, #111827); height: 36px; width: 100%;
}
.filter-group input[type=date]:focus,
.filter-group select:focus { outline: none; border-color: var(--cl-accent); }
.filter-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.btn-filter {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem 1.1rem; border-radius: 8px;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  border: none; height: 36px;
}
.btn-filter.apply { background: var(--cl-accent); color: #fff; }
.btn-filter.apply:hover { background: #0f766e; }
.btn-filter.reset { background: var(--input-bg, #f3f4f6); color: var(--text, #374151); border: 1.5px solid var(--border, #d1d5db); }
.btn-filter.reset:hover { background: #e5e7eb; }

/* ── Tab Navigation ─────────────────────────────────── */
.tab-nav { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
.tab-nav a {
  padding: .45rem 1.1rem; border-radius: 999px;
  font-size: .81rem; font-weight: 600;
  border: 1.5px solid var(--border, #e5e7eb);
  color: var(--text-dim, #6b7280); text-decoration: none;
  background: var(--card-bg, #fff);
  display: flex; align-items: center; gap: .35rem;
}
.tab-nav a:hover { border-color: var(--cl-accent2); color: var(--cl-accent); }
.tab-nav a.active { background: var(--cl-accent); color: #fff; border-color: var(--cl-accent); }
.tab-badge {
  border-radius: 999px; padding: 0 6px;
  font-size: .68rem; font-weight: 700;
  background: rgba(255,255,255,.25);
}
.tab-nav a:not(.active) .tab-badge { background: var(--border, #e5e7eb); color: var(--text-dim, #6b7280); }

/* ── Table Card ─────────────────────────────────────── */
.table-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px; overflow: hidden;
}
.table-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .6rem;
  padding: .75rem 1.25rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #f9fafb);
}
.table-title {
  font-size: .9rem; font-weight: 700; color: var(--text, #111827);
  display: flex; align-items: center; gap: .5rem;
}
.table-count {
  font-size: .68rem; font-weight: 700; padding: .15rem .5rem;
  border-radius: 999px; background: #ccfbf1; color: #0f766e; border: 1px solid #99f6e4;
}
.table-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.search-wrap { position: relative; flex: 1; min-width: 180px; max-width: 280px; }
.search-wrap i {
  position: absolute; left: .65rem; top: 50%; transform: translateY(-50%);
  color: var(--text-dim, #9ca3af); font-size: .85rem; pointer-events: none;
}
.search-wrap input {
  width: 100%; padding: .4rem .7rem .4rem 2rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; background: var(--input-bg, #f9fafb);
  font-size: .82rem; color: var(--text, #111827); height: 36px;
}
.search-wrap input:focus { outline: none; border-color: var(--cl-accent); }
.btn-action {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .85rem; border-radius: 7px;
  font-size: .76rem; font-weight: 700; cursor: pointer;
  border: 1.5px solid transparent; height: 32px;
  white-space: nowrap; text-decoration: none;
}
.btn-csv   { background: #f0fdf4; color: #166534; border-color: #4ade80; }
.btn-csv:hover { background: #dcfce7; }
.btn-excel { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.btn-excel:hover { background: #dbeafe; }
.btn-print { background: #f0fdfa; color: #0f766e; border-color: #5eead4; }
.btn-print:hover { background: #ccfbf1; }
.btn-view  { background: #f0fdfa; color: #0f766e; border-color: #5eead4; }
.btn-view:hover { background: #ccfbf1; }

/* ── Main Table ─────────────────────────────────────── */
.table-scroll { overflow-x: auto; }
.main-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.main-table thead th {
  background: var(--input-bg, #f9fafb); color: var(--text-dim, #6b7280);
  font-size: .68rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; padding: .6rem .75rem;
  border-bottom: 2px solid var(--border, #e5e7eb);
  white-space: nowrap; cursor: pointer; user-select: none;
}
.main-table thead th:hover { color: var(--cl-accent); }
.main-table tbody td {
  padding: .5rem .75rem; color: var(--text, #374151);
  border-bottom: 1px solid var(--border, #f1f3f7); vertical-align: middle;
}
.main-table tbody tr:hover { background: #f0fdfa; }
.r { text-align: right; }
.badge-pill {
  display: inline-flex; align-items: center; padding: 2px 8px;
  border-radius: 999px; font-size: .75rem; font-weight: 600;
  white-space: nowrap; border: 1px solid;
}

/* ── Pagination ─────────────────────────────────────── */
.pagination-bar {
  display: flex; align-items: center; gap: 1rem;
  justify-content: flex-end; flex-wrap: wrap;
  padding: .6rem 1.25rem;
  border-top: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #fafafa);
}
.pagination-info { font-size: .78rem; color: var(--text-dim, #6b7280); flex: 1; }
.pagination-btns { display: flex; gap: .5rem; }
.btn-page {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .85rem; height: 32px;
  font-size: .78rem; font-weight: 600;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; cursor: pointer;
  background: var(--card-bg, #fff); color: var(--text, #374151);
  text-decoration: none;
}
.btn-page:hover:not(.disabled) { border-color: var(--cl-accent); color: var(--cl-accent); }
.btn-page.disabled { opacity: .4; pointer-events: none; }

/* ── Empty state ─────────────────────────────────────── */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim, #9ca3af); }
.empty-state .icon { font-size: 2.2rem; display: block; margin-bottom: .5rem; opacity: .5; }
</style>
</head>
<body>

<?php $topbar_page = 'customer_list'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">Customer <span>List</span></div>
      <div class="page-badge">👥 <?= htmlspecialchars($deptActive ? $currentUserDept : 'All Departments') ?></div>
    </div>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <a href="<?= tabUrl('delivery') ?>" class="stat-card" style="border-left:3px solid var(--cl-accent);">
      <span class="sc-icon">🚚</span>
      <span class="sc-label">Delivery Customers</span>
      <span class="sc-value" style="color:var(--cl-accent)"><?= number_format($deliveryCount) ?></span>
    </a>
    <a href="<?= tabUrl('ar') ?>" class="stat-card" style="border-left:3px solid var(--cl-blue);">
      <span class="sc-icon">🧾</span>
      <span class="sc-label">AR Customers</span>
      <span class="sc-value" style="color:var(--cl-blue)"><?= number_format($arCount) ?></span>
    </a>
  </div>

  <!-- ── Filter Panel ─────────────────────────────────────── -->
  <div class="filter-panel">
    <div class="filter-panel-header" onclick="toggleFilter()">
      <div class="filter-panel-header-left">
        <i class="bi bi-funnel-fill" style="color:var(--cl-accent)"></i>
        Filters
        <?php if ($anyFilterApplied): ?>
          <span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;border-radius:999px;padding:1px 8px;font-size:.68rem;">Active</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;">
        <div class="filter-active-tags" id="headerTags">
          <span class="filter-tag"><i class="bi bi-calendar3"></i><?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?></span>
          <?php if ($branchActive):   ?><span class="filter-tag"><i class="bi bi-diagram-3"></i><?= htmlspecialchars($selBranch) ?></span><?php endif; ?>
          <?php if ($salesmanActive): ?><span class="filter-tag"><i class="bi bi-person"></i><?= htmlspecialchars($selSalesman) ?></span><?php endif; ?>
          <?php if ($areaActive):     ?><span class="filter-tag"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($selArea) ?></span><?php endif; ?>
        </div>
        <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
      </div>
    </div>
    <div class="filter-body" id="filterBody">
      <form method="GET" action="">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="filter-grid">
          <div class="filter-group">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
          </div>
          <div class="filter-group">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
          </div>
          <div class="filter-group">
            <label>Branch</label>
            <select name="branch">
              <option value="">All Branches</option>
              <?php foreach ($branchList as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $selBranch === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label>Salesman</label>
            <select name="salesman">
              <option value="">All Salesmen</option>
              <?php foreach ($salesmanList as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $selSalesman === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label>Area</label>
            <select name="area">
              <option value="">All Areas</option>
              <?php foreach ($areaList as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter apply"><i class="bi bi-funnel-fill"></i> Apply Filters</button>
          <a href="?tab=<?= $tab ?>" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Tab Navigation ───────────────────────────────────── -->
  <div class="tab-nav">
    <a href="<?= tabUrl('delivery') ?>" class="<?= $tab === 'delivery' ? 'active' : '' ?>">
      <i class="bi bi-truck"></i> Delivery
      <span class="tab-badge"><?= number_format($deliveryCount) ?></span>
    </a>
    <a href="<?= tabUrl('ar') ?>" class="<?= $tab === 'ar' ? 'active' : '' ?>">
      <i class="bi bi-receipt"></i> AR
      <span class="tab-badge"><?= number_format($arCount) ?></span>
    </a>
  </div>

  <!-- ── Table Card ───────────────────────────────────────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        <?= $tab === 'delivery' ? '🚚 Delivery Customers' : '🧾 AR Customers' ?>
        <span class="table-count"><?= number_format($totalRows) ?> customers</span>
      </div>
      <div class="table-actions">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" placeholder="Search table…" oninput="filterTable(this.value)">
        </div>
        <button class="btn-action btn-csv"   onclick="exportCSV()"><i class="bi bi-filetype-csv"></i> CSV</button>
        <button class="btn-action btn-excel" onclick="exportExcel()"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</button>
        <button class="btn-action btn-print" onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>

    <?php if (empty($data)): ?>
      <div class="empty-state"><span class="icon">📭</span><p>No customers found for the selected filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="mainTable">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Customer</th>
              <th onclick="sortTable(1)">Department</th>
              <th onclick="sortTable(2)">Branch</th>
              <th onclick="sortTable(3)">Area</th>
              <th onclick="sortTable(4)">Salesman</th>
              <th onclick="sortTable(5)">Mode of Payments</th>
              <th class="r" onclick="sortTable(6)">Total Amount</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($displayData as $row):
              $custName = trim($row['Customer'] ?? '');
              $detailUrl = 'customer-detail.php?' . http_build_query([
                  'customer'   => $custName,
                  'source'     => $tab,
                  'department' => $row['Department'] ?? '',
              ]);
            ?>
            <tr>
              <td><b><?= htmlspecialchars($custName !== '' ? $custName : '—') ?></b></td>
              <td><?= deptBadge($row['Department'] ?? null) ?></td>
              <td><?= branchBadge($row['Branch'] ?? null) ?></td>
              <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['ModeOfPayments'] ?? '—') ?></td>
              <td class="r" style="font-weight:700;color:var(--cl-accent)"><?= peso($row['TotalAmount'] ?? 0) ?></td>
              <td><a href="<?= htmlspecialchars($detailUrl) ?>" class="btn-action btn-view"><i class="bi bi-clock-history"></i> View History</a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="pagination-bar" id="paginationBar">
        <span class="pagination-info">
          Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong>
          of <strong><?= number_format($totalRows) ?></strong> customers &nbsp;·&nbsp;
          Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
        </span>
        <div class="pagination-btns">
          <?php if ($prevUrl): ?>
            <a href="<?= $prevUrl ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Prev</a>
          <?php else: ?>
            <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Prev</span>
          <?php endif; ?>
          <?php if ($nextUrl): ?>
            <a href="<?= $nextUrl ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<script>
// ── Full dataset for export/print (not paginated) ────────────
const ALL_DATA = <?= json_encode(array_values($exportData), JSON_UNESCAPED_UNICODE) ?>;
const TAB      = '<?= $tab ?>';

// ── Filter panel toggle ────────────────────────────────────────
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const icon = document.getElementById('filterToggleIcon');
    const tags = document.getElementById('headerTags');
    const isOpen = body.classList.toggle('open');
    icon.classList.toggle('open', isOpen);
    tags.style.display = isOpen ? 'none' : 'flex';
}
<?php if ($anyFilterApplied): ?>toggleFilter();<?php endif; ?>

// ── Table search (searches ALL_DATA — full dataset, not just current page) ──
let _searchActive = false;

function filterTable(q) {
    const term = q.trim().toLowerCase();
    _searchActive = term !== '';

    if (!_searchActive) {
        // Restore original paginated view by reloading with current page params
        // but only if user clears the box — keep URL pagination intact
        renderSearchResults(null);
        updateSearchCount(null);
        return;
    }

    const filtered = ALL_DATA.filter(row =>
        Object.values(row).some(v => String(v ?? '').toLowerCase().includes(term))
    );

    renderSearchResults(filtered);
    updateSearchCount(filtered.length);
}

function renderSearchResults(rows) {
    const tbody = document.querySelector('#mainTable tbody');
    if (!tbody) return;

    if (rows === null) {
        // Restore original server-rendered rows
        tbody.innerHTML = _originalTbody;
        document.getElementById('paginationBar')?.style.setProperty('display', '');
        document.getElementById('searchCountBadge')?.remove();
        return;
    }

    document.getElementById('paginationBar')?.style.setProperty('display', 'none');

    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-dim,#9ca3af);">No results found.</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(row => {
        const custName  = String(row.Customer  ?? '').trim() || '—';
        const dept      = String(row.Department ?? '').trim();
        const branch    = String(row.Branch     ?? '').trim();
        const area      = String(row.Area       ?? '').trim() || '—';
        const salesman  = String(row.Salesman   ?? '').trim() || '—';
        const mop       = String(row.ModeOfPayments ?? '').trim() || '—';
        const amt       = parseFloat(row.TotalAmount ?? 0);
        const amtFmt    = '₱ ' + amt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

        const detailUrl = 'customer-detail.php?' + new URLSearchParams({
            customer:   custName === '—' ? '' : custName,
            source:     TAB,
            department: dept,
        }).toString();

        return `<tr>
            <td><b>${escHtml(custName)}</b></td>
            <td>${renderDeptBadge(dept)}</td>
            <td>${renderBranchBadge(branch)}</td>
            <td>${escHtml(area)}</td>
            <td>${escHtml(salesman)}</td>
            <td>${escHtml(mop)}</td>
            <td class="r" style="font-weight:700;color:var(--cl-accent)">${amtFmt}</td>
            <td><a href="${escHtml(detailUrl)}" class="btn-action btn-view"><i class="bi bi-clock-history"></i> View History</a></td>
        </tr>`;
    }).join('');
}

function updateSearchCount(count) {
    const title = document.querySelector('.table-title');
    document.getElementById('searchCountBadge')?.remove();
    if (count === null) return;
    const badge = document.createElement('span');
    badge.id = 'searchCountBadge';
    badge.className = 'table-count';
    badge.style.background = '#fef9c3';
    badge.style.color = '#854d0e';
    badge.style.borderColor = '#fde047';
    badge.textContent = count + ' result' + (count !== 1 ? 's' : '');
    title?.appendChild(badge);
}

// ── Badge helpers (JS mirrors of PHP badge functions) ──────────
const DEPT_MAP = {
    'monde':      ['#fee2e2','#b91c1c','#fca5a5'],
    'century':    ['#dbeafe','#1e40af','#93c5fd'],
    'multilines': ['#fef9c3','#854d0e','#fde047'],
    'nutriasia':  ['#d1fae5','#047857','#6ee7b7'],
    'silverswan': ['#e0e7ff','#4338ca','#a5b4fc'],
    '':           ['#f3f4f6','#6b7280','#d1d5db'],
};
const BRANCH_MAP = {
    'quezon':       ['#ede9fe','#5b21b6','#c4b5fd'],
    'quezon upper': ['#dbeafe','#1e3a8a','#93c5fd'],
    'marinduque':   ['#dcfce7','#166534','#86efac'],
};

function renderDeptBadge(d) {
    const key = d.toLowerCase().trim();
    const [bg, color, border] = DEPT_MAP[key] ?? DEPT_MAP[''];
    const label = escHtml(d || '—');
    return `<span class="badge-pill" style="background:${bg};color:${color};border-color:${border};">${label}</span>`;
}
function renderBranchBadge(b) {
    const key = b.toLowerCase().trim();
    const [bg, color, border] = BRANCH_MAP[key] ?? ['#f3f4f6','#374151','#d1d5db'];
    const label = escHtml(b || '—');
    return `<span class="badge-pill" style="background:${bg};color:${color};border-color:${border};">${label}</span>`;
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Snapshot the server-rendered tbody so we can restore it on search clear
// (script is at bottom of body, DOM is already available)
const _originalTbody = document.querySelector('#mainTable tbody')?.innerHTML ?? '';

// ── Column sort ─────────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const araw = (a.cells[col]?.innerText || '').trim();
        const braw = (b.cells[col]?.innerText || '').trim();
        const an = parseFloat(araw.replace(/[₱,\s]/g, ''));
        const bn = parseFloat(braw.replace(/[₱,\s]/g, ''));
        const cmp = (!isNaN(an) && !isNaN(bn) && /[\d₱]/.test(araw))
            ? an - bn
            : araw.toLowerCase().localeCompare(braw.toLowerCase());
        return _sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── CSV export (full dataset) ─────────────────────────────────────
function exportCSV() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const headers = Object.keys(ALL_DATA[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...ALL_DATA.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `customer_list_${TAB}_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Excel export (full dataset) ────────────────────────────────────
function exportExcel() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const ws = XLSX.utils.json_to_sheet(ALL_DATA);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, TAB === 'delivery' ? 'Delivery' : 'AR');
    XLSX.writeFile(wb, `customer_list_${TAB}_<?= date('Ymd') ?>.xlsx`);
}

// ── Print (full dataset, simple print window) ──────────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');
    const headers = Object.keys(ALL_DATA[0]);
    const thead = '<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
    const tbody = ALL_DATA.map(row => '<tr>' + headers.map(h => `<td>${row[h] ?? ''}</td>`).join('') + '</tr>').join('');
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><title>Customer List</title>
      <style>
        body{font-family:Arial,sans-serif;padding:20px;}
        table{width:100%;border-collapse:collapse;font-size:12px;}
        th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;}
        th{background:#f3f4f6;}
      </style></head><body>
      <h3>Customer List — ${TAB === 'delivery' ? 'Delivery' : 'AR'}</h3>
      <table>${thead}${tbody}</table>
      </body></html>`);
    win.document.close();
    win.print();
}
</script>
</body>
</html>