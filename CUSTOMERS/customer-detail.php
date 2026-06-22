<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'customer_list');

// ── Required params ────────────────────────────────────────────
$customer   = isset($_GET['customer'])   ? trim($_GET['customer'])   : '';
$source     = isset($_GET['source']) && in_array($_GET['source'], ['delivery', 'ar']) ? $_GET['source'] : 'delivery';
$department = isset($_GET['department']) ? trim($_GET['department']) : '';

if ($customer === '') {
    header('Location: ' . base_url('CUSTOMERS/customer-list.php?tab=' . $source));
    exit();
}

$_customerSafe = str_replace("'", "''", $customer);
$_deptSafe     = str_replace("'", "''", trim($department));
$deptActive    = $department !== '' && strtolower(trim($department)) !== 'all';
$deptWhere     = $deptActive ? " AND RTRIM(LTRIM(Department)) = RTRIM(LTRIM('$_deptSafe'))" : '';

// ── Filters ────────────────────────────────────────────────────
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$selMop   = isset($_GET['mop'])       ? trim($_GET['mop'])       : '';

$dateActive = $dateFrom !== '' || $dateTo !== '';
$mopActive  = $selMop !== '';
$anyFilterApplied = $dateActive || $mopActive;

$_dateFromSafe = str_replace("'", "''", $dateFrom);
$_dateToSafe   = str_replace("'", "''", $dateTo);
$_mopSafe      = str_replace("'", "''", $selMop);

// ── Source-specific config ────────────────────────────────────
if ($source === 'delivery') {
    $view       = '[dbo].[View_RemittanceCollectionSlip2]';
    $dateCol    = 'DocDate';
    $mopCol     = 'Remarks';
    $custCol    = 'Customer';
} else {
    $view       = '[dbo].[View_ARForCollectionDetails]';
    $dateCol    = 'DeliveryDate';
    $mopCol     = 'RemitRemarks';
    $custCol    = 'CustomerName1';
}

$dateWhere = '';
if ($dateFrom !== '' && $dateTo !== '') {
    $dateWhere = " AND $dateCol BETWEEN '$_dateFromSafe' AND '$_dateToSafe'";
} elseif ($dateFrom !== '') {
    $dateWhere = " AND $dateCol >= '$_dateFromSafe'";
} elseif ($dateTo !== '') {
    $dateWhere = " AND $dateCol <= '$_dateToSafe'";
}
$mopWhere = $mopActive ? " AND $mopCol = '$_mopSafe'" : '';

$commonWhere = " AND RTRIM(LTRIM($custCol)) = RTRIM(LTRIM('$_customerSafe'))" . $deptWhere . $dateWhere . $mopWhere;

// ── Helper: run a query and return all rows ──────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        error_log('customer_detail SQL error: ' . json_encode(sqlsrv_errors()));
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
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $val = array_values($row)[0];
        if ($val !== null && trim((string)$val) !== '') $list[] = trim((string)$val);
    }
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Mode of Payment dropdown (scoped to this customer) ────────
$mopListSql = "SELECT DISTINCT $mopCol FROM $view
               WHERE RTRIM(LTRIM($custCol)) = RTRIM(LTRIM('$_customerSafe'))
                 {$deptWhere}
                 AND $mopCol IS NOT NULL AND $mopCol <> ''
               ORDER BY $mopCol";
$mopList = lookupList($conn, $mopListSql);

// ── Main transaction query ─────────────────────────────────────
if ($source === 'delivery') {
    $sql = "SELECT
                DocNo, InvoiceNo, DocDate, InvoiceDate,
                NetAmount, CashAmount, CheckAmount, CreditAmount, TotalPaid,
                Remarks AS ModeOfPayments,
                Branch, Area, Salesman, Department
            FROM $view
            WHERE 1=1 {$commonWhere}
            ORDER BY DocDate DESC, DocNo DESC";
} else {
    $sql = "SELECT
                ARCollectionNo, InvoiceNo, InvoiceDate, DeliveryDate,
                InvoiceAmount, PaidAmount, Balance,
                RemitRemarks AS ModeOfPayments,
                Bank, CheckNo, CheckDate, Status,
                Area, Salesman, Department
            FROM $view
            WHERE 1=1 {$commonWhere}
            ORDER BY DeliveryDate DESC, InvoiceNo DESC";
}

$data = runQuery($conn, $sql);

// ── "Days Since Last Order" gap column ──────────────────────────
// $gapDateCol matches whichever date column drives this tab
// (DocDate for delivery, DeliveryDate for AR). We compute the gap
// chronologically (oldest -> newest), then below we re-sort the
// rows for display by Date DESC (most recent transaction first).
$gapDateCol = $dateCol;
function parseGapDate($v) {
    if ($v instanceof DateTime) return $v;
    if (is_string($v) && strlen($v) >= 10) {
        $d = DateTime::createFromFormat('Y-m-d', substr($v, 0, 10));
        return $d ?: null;
    }
    return null;
}
usort($data, function($a, $b) use ($gapDateCol) {
    $da = parseGapDate($a[$gapDateCol] ?? null);
    $db = parseGapDate($b[$gapDateCol] ?? null);
    if (!$da || !$db) return 0;
    return $da <=> $db; // chronological ascending, to measure gaps correctly
});
$prevDate = null;
foreach ($data as &$row) {
    $curDate = parseGapDate($row[$gapDateCol] ?? null);
    $row['DaysSinceLastOrder'] = ($prevDate && $curDate) ? $curDate->diff($prevDate)->days : null;
    if ($curDate) $prevDate = $curDate;
}
unset($row);
// ── Final display order — most recent transaction first ────────
usort($data, function($a, $b) use ($gapDateCol) {
    $da = parseGapDate($a[$gapDateCol] ?? null);
    $db = parseGapDate($b[$gapDateCol] ?? null);
    if (!$da || !$db) return 0;
    return $db <=> $da; // DESC — newest first
});

function fmtGap($g): string {
    if ($g === null) return '—';
    return number_format($g) . ' day' . ($g == 1 ? '' : 's');
}

// ── Stat totals ────────────────────────────────────────────────
$totalTransactions = count($data);
if ($source === 'delivery') {
    $totalAmount = array_sum(array_column($data, 'NetAmount'));
    $totalPaid   = array_sum(array_column($data, 'TotalPaid'));
} else {
    $totalAmount = array_sum(array_column($data, 'InvoiceAmount'));
    $totalPaid   = array_sum(array_column($data, 'PaidAmount'));
    $totalBalance = array_sum(array_column($data, 'Balance'));
}

// ── Pagination (PHP-based) ───────────────────────────────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

// ── Helpers ───────────────────────────────────────────────────
function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }

function fmtDate($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    if (is_string($d) && strlen($d) >= 10) return substr($d, 0, 10);
    return '—';
}

$backUrl = base_url('CUSTOMERS/customer-list.php?tab=' . $source);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer History — <?= htmlspecialchars($customer) ?> — Tradewell</title>
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
  --cl-red     : #dc2626;
  --cl-green   : #16a34a;
}

/* ── Back link ──────────────────────────────────────── */
.back-link {
  display: inline-flex; align-items: center; gap: .4rem;
  font-size: .82rem; font-weight: 600; color: var(--text-dim, #6b7280);
  text-decoration: none; margin-bottom: .75rem;
}
.back-link:hover { color: var(--cl-accent); }

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
}
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
  white-space: nowrap;
}
.btn-csv   { background: #f0fdf4; color: #166534; border-color: #4ade80; }
.btn-csv:hover { background: #dcfce7; }
.btn-excel { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.btn-excel:hover { background: #dbeafe; }
.btn-print { background: #f0fdfa; color: #0f766e; border-color: #5eead4; }
.btn-print:hover { background: #ccfbf1; }

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
.mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; }

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

  <a href="<?= $backUrl ?>" class="back-link"><i class="bi bi-arrow-left"></i> Back to Customer List</a>

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title"><?= htmlspecialchars($customer) ?></div>
      <div class="page-badge">
        <?= $source === 'delivery' ? '🚚 Delivery History' : '🧾 AR History' ?>
        <?php if ($department !== ''): ?> · <?= htmlspecialchars($department) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <div class="stat-card" style="border-left:3px solid var(--cl-accent);">
      <span class="sc-icon">📄</span>
      <span class="sc-label">Transactions</span>
      <span class="sc-value" style="color:var(--cl-accent)"><?= number_format($totalTransactions) ?></span>
    </div>
    <?php if ($source === 'delivery'): ?>
      <div class="stat-card" style="border-left:3px solid var(--cl-blue);">
        <span class="sc-icon">💰</span>
        <span class="sc-label">Total Net Amount</span>
        <span class="sc-value" style="color:var(--cl-blue)"><?= peso($totalAmount) ?></span>
      </div>
      <div class="stat-card" style="border-left:3px solid var(--cl-green);">
        <span class="sc-icon">✅</span>
        <span class="sc-label">Total Paid</span>
        <span class="sc-value" style="color:var(--cl-green)"><?= peso($totalPaid) ?></span>
      </div>
    <?php else: ?>
      <div class="stat-card" style="border-left:3px solid var(--cl-blue);">
        <span class="sc-icon">💰</span>
        <span class="sc-label">Total Invoice Amount</span>
        <span class="sc-value" style="color:var(--cl-blue)"><?= peso($totalAmount) ?></span>
      </div>
      <div class="stat-card" style="border-left:3px solid var(--cl-green);">
        <span class="sc-icon">✅</span>
        <span class="sc-label">Total Paid</span>
        <span class="sc-value" style="color:var(--cl-green)"><?= peso($totalPaid) ?></span>
      </div>
      <div class="stat-card" style="border-left:3px solid var(--cl-red);">
        <span class="sc-icon">⚠️</span>
        <span class="sc-label">Total Balance</span>
        <span class="sc-value" style="color:var(--cl-red)"><?= peso($totalBalance) ?></span>
      </div>
    <?php endif; ?>
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
          <?php if ($dateActive): ?><span class="filter-tag"><i class="bi bi-calendar3"></i><?= htmlspecialchars($dateFrom) ?> → <?= htmlspecialchars($dateTo) ?></span><?php endif; ?>
          <?php if ($mopActive):  ?><span class="filter-tag"><i class="bi bi-credit-card"></i><?= htmlspecialchars($selMop) ?></span><?php endif; ?>
        </div>
        <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
      </div>
    </div>
    <div class="filter-body" id="filterBody">
      <form method="GET" action="">
        <input type="hidden" name="customer"   value="<?= htmlspecialchars($customer) ?>">
        <input type="hidden" name="source"     value="<?= htmlspecialchars($source) ?>">
        <input type="hidden" name="department" value="<?= htmlspecialchars($department) ?>">
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
            <label>Mode of Payment</label>
            <select name="mop">
              <option value="">All Modes</option>
              <?php foreach ($mopList as $m): ?>
                <option value="<?= htmlspecialchars($m) ?>" <?= $selMop === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter apply"><i class="bi bi-funnel-fill"></i> Apply Filters</button>
          <a href="?customer=<?= urlencode($customer) ?>&source=<?= urlencode($source) ?>&department=<?= urlencode($department) ?>" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Table Card ───────────────────────────────────────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        <?= $source === 'delivery' ? '🚚 Delivery Transactions' : '🧾 AR Transactions' ?>
        <span class="table-count"><?= number_format($totalRows) ?> records</span>
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
      <div class="empty-state"><span class="icon">📭</span><p>No transactions found for the selected filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="mainTable">
          <thead>
            <?php if ($source === 'delivery'): ?>
              <tr>
                <th onclick="sortTable(0)">Doc No</th>
                <th onclick="sortTable(1)">Invoice No</th>
                <th onclick="sortTable(2)">Doc Date</th>
                <th onclick="sortTable(3)">Invoice Date</th>
                <th class="r" onclick="sortTable(4)">Days Since Last Order</th>
                <th onclick="sortTable(5)">Mode of Payment</th>
                <th onclick="sortTable(6)">Branch</th>
                <th onclick="sortTable(7)">Area</th>
                <th onclick="sortTable(8)">Salesman</th>
                <th class="r" onclick="sortTable(9)">Net Amount</th>
                <th class="r" onclick="sortTable(10)">Cash</th>
                <th class="r" onclick="sortTable(11)">Check</th>
                <th class="r" onclick="sortTable(12)">Credit</th>
                <th class="r" onclick="sortTable(13)">Total Paid</th>
              </tr>
            <?php else: ?>
              <tr>
                <th onclick="sortTable(0)">AR Collection No</th>
                <th onclick="sortTable(1)">Invoice No</th>
                <th onclick="sortTable(2)">Invoice Date</th>
                <th onclick="sortTable(3)">Delivery Date</th>
                <th class="r" onclick="sortTable(4)">Days Since Last Order</th>
                <th onclick="sortTable(5)">Mode of Payment</th>
                <th onclick="sortTable(6)">Bank</th>
                <th onclick="sortTable(7)">Check No</th>
                <th onclick="sortTable(8)">Check Date</th>
                <th onclick="sortTable(9)">Status</th>
                <th onclick="sortTable(10)">Area</th>
                <th onclick="sortTable(11)">Salesman</th>
                <th class="r" onclick="sortTable(12)">Invoice Amt</th>
                <th class="r" onclick="sortTable(13)">Paid</th>
                <th class="r" onclick="sortTable(14)">Balance</th>
              </tr>
            <?php endif; ?>
          </thead>
          <tbody>
            <?php foreach ($displayData as $row): ?>
              <?php if ($source === 'delivery'): ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></td>
                  <td class="mono"><?= htmlspecialchars($row['InvoiceNo'] ?? '—') ?></td>
                  <td><?= fmtDate($row['DocDate'] ?? null) ?></td>
                  <td><?= fmtDate($row['InvoiceDate'] ?? null) ?></td>
                  <td class="r" style="<?= ($row['DaysSinceLastOrder'] ?? 0) > 30 ? 'color:var(--cl-red);font-weight:700' : '' ?>"><?= fmtGap($row['DaysSinceLastOrder'] ?? null) ?></td>
                  <td><?= htmlspecialchars($row['ModeOfPayments'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Branch'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
                  <td class="r" style="font-weight:700"><?= peso($row['NetAmount'] ?? 0) ?></td>
                  <td class="r"><?= peso($row['CashAmount'] ?? 0) ?></td>
                  <td class="r"><?= peso($row['CheckAmount'] ?? 0) ?></td>
                  <td class="r"><?= peso($row['CreditAmount'] ?? 0) ?></td>
                  <td class="r" style="color:var(--cl-green);font-weight:700"><?= peso($row['TotalPaid'] ?? 0) ?></td>
                </tr>
              <?php else: ?>
                <tr>
                  <td class="mono"><?= htmlspecialchars($row['ARCollectionNo'] ?? '—') ?></td>
                  <td class="mono"><?= htmlspecialchars($row['InvoiceNo'] ?? '—') ?></td>
                  <td><?= fmtDate($row['InvoiceDate'] ?? null) ?></td>
                  <td><?= fmtDate($row['DeliveryDate'] ?? null) ?></td>
                  <td class="r" style="<?= ($row['DaysSinceLastOrder'] ?? 0) > 30 ? 'color:var(--cl-red);font-weight:700' : '' ?>"><?= fmtGap($row['DaysSinceLastOrder'] ?? null) ?></td>
                  <td><?= htmlspecialchars($row['ModeOfPayments'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Bank'] ?? '—') ?></td>
                  <td class="mono"><?= htmlspecialchars($row['CheckNo'] ?? '—') ?></td>
                  <td><?= fmtDate($row['CheckDate'] ?? null) ?></td>
                  <td><?= htmlspecialchars($row['Status'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
                  <td class="r" style="font-weight:700"><?= peso($row['InvoiceAmount'] ?? 0) ?></td>
                  <td class="r" style="color:var(--cl-green);font-weight:700"><?= peso($row['PaidAmount'] ?? 0) ?></td>
                  <td class="r" style="<?= (float)($row['Balance'] ?? 0) > 0 ? 'color:var(--cl-red);font-weight:700' : '' ?>"><?= peso($row['Balance'] ?? 0) ?></td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="pagination-bar">
        <span class="pagination-info">
          Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong>
          of <strong><?= number_format($totalRows) ?></strong> rows &nbsp;·&nbsp;
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
const SOURCE   = '<?= $source ?>';

// ── Filter panel toggle ───────────────────────────────────────
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const icon = document.getElementById('filterToggleIcon');
    const tags = document.getElementById('headerTags');
    const isOpen = body.classList.toggle('open');
    icon.classList.toggle('open', isOpen);
    tags.style.display = isOpen ? 'none' : 'flex';
}
<?php if ($anyFilterApplied): ?>toggleFilter();<?php endif; ?>

// ── Table search ────────────────────────────────────────────────
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

// ── Column sort ─────────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = (a.cells[col]?.innerText || '').replace(/[₱,\s]/g, '');
        const bv = (b.cells[col]?.innerText || '').replace(/[₱,\s]/g, '');
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
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
    a.download = `customer_history_${SOURCE}_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Excel export (full dataset) ────────────────────────────────────
function exportExcel() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const cleanData = ALL_DATA.map(row => {
        const r = {};
        for (let k in row) {
            let v = row[k];
            if (v && typeof v === 'object' && v.date) v = v.date.substring(0, 10);
            r[k] = v;
        }
        return r;
    });
    const ws = XLSX.utils.json_to_sheet(cleanData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, SOURCE === 'delivery' ? 'Delivery' : 'AR');
    XLSX.writeFile(wb, `customer_history_${SOURCE}_<?= date('Ymd') ?>.xlsx`);
}

// ── Print (full dataset, simple print window) ──────────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');
    const headers = Object.keys(ALL_DATA[0]);
    const thead = '<tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr>';
    const tbody = ALL_DATA.map(row => '<tr>' + headers.map(h => {
        let v = row[h];
        if (v && typeof v === 'object' && v.date) v = v.date.substring(0, 10);
        return `<td>${v ?? ''}</td>`;
    }).join('') + '</tr>').join('');
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><title>Customer History</title>
      <style>
        body{font-family:Arial,sans-serif;padding:20px;}
        table{width:100%;border-collapse:collapse;font-size:11px;}
        th,td{border:1px solid #ddd;padding:5px 7px;text-align:left;}
        th{background:#f3f4f6;}
      </style></head><body>
      <h3>Customer History — <?= htmlspecialchars($customer) ?> (${SOURCE === 'delivery' ? 'Delivery' : 'AR'})</h3>
      <table>${thead}${tbody}</table>
      </body></html>`);
    win.document.close();
    win.print();
}
</script>
</body>
</html>