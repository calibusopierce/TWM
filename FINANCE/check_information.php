<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'check_information');

// ── Current user context ─────────────────────────────────────
$currentUserDept = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');

// ── Date filters (legacy default: last 30 days) ───────────────
$today    = date('Y-m-d');
$monthAgo = date('Y-m-d', strtotime('-30 days'));

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
if (!$dateActive) {
    $baseFrom = $monthAgo;
    $baseTo   = $today;
} else {
    $baseFrom = $dateFrom !== '' ? $dateFrom : $monthAgo;
    $baseTo   = $dateTo   !== '' ? $dateTo   : $today;
}

// ── Other filters ────────────────────────────────────────────
$selDept     = isset($_GET['dept'])     ? trim($_GET['dept'])     : $currentUserDept;
$selBank     = isset($_GET['bank'])     ? trim($_GET['bank'])     : '';
$selSalesman = isset($_GET['salesman']) ? trim($_GET['salesman']) : '';
$selArea     = isset($_GET['area'])     ? trim($_GET['area'])     : '';

$deptActive     = $selDept     !== '' && strtolower($selDept) !== 'all';
$bankActive     = $selBank     !== '';
$salesmanActive = $selSalesman !== '';
$areaActive     = $selArea     !== '';

// Department dropdown is only editable when the session department is "All".
// Otherwise the page is always locked to the user's own department, no matter
// what's in the querystring.
$deptIsLocked = strtolower($currentUserDept) !== 'all' && $currentUserDept !== '';
if ($deptIsLocked) {
    $selDept   = $currentUserDept;
    $deptActive = true;
}

$anyFilterApplied = $dateActive || ($deptActive && !$deptIsLocked) || $bankActive || $salesmanActive || $areaActive;

// ── Safe values ──────────────────────────────────────────────
$_deptSafe     = str_replace("'", "''", $selDept);
$_bankSafe     = str_replace("'", "''", $selBank);
$_salesmanSafe = str_replace("'", "''", $selSalesman);
$_areaSafe     = str_replace("'", "''", $selArea);

$deptWhere     = $deptActive     ? " AND RTRIM(Department) = '$_deptSafe'" : '';
$bankWhere     = $bankActive     ? " AND Bank = '$_bankSafe'"              : '';
$salesmanWhere = $salesmanActive ? " AND Salesman = '$_salesmanSafe'"      : '';
$areaWhere     = $areaActive     ? " AND Area = '$_areaSafe'"              : '';
$commonWhere   = $deptWhere . $bankWhere . $salesmanWhere . $areaWhere;

// ── Helper: run a query and return all rows ────────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
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

function runQueryDebug($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        $errors = sqlsrv_errors();
        error_log('Check Information SQL error: ' . json_encode($errors));
        return ['__error__' => $errors[0]['message'] ?? 'Unknown SQL error'];
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
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Lookup dropdowns ─────────────────────────────────────────
// Scoped to the current date range (and dept, if locked) so options always
// reflect what's actually visible in the table.
$_dropDateWhere = " AND CheckDate BETWEEN '$baseFrom' AND '$baseTo'";
$_dropDeptWhere = $deptActive ? " AND RTRIM(Department) = '$_deptSafe'" : '';

$deptList = $deptIsLocked ? [] : lookupList($conn,
    "SELECT DISTINCT Department FROM [dbo].[View_CheckInformation]
     WHERE Department IS NOT NULL AND Department <> ''
       {$_dropDateWhere}
     ORDER BY Department"
);

$bankList = lookupList($conn,
    "SELECT DISTINCT Bank FROM [dbo].[View_CheckInformation]
     WHERE Bank IS NOT NULL AND Bank <> ''
       {$_dropDateWhere} {$_dropDeptWhere}
     ORDER BY Bank"
);

$salesmanList = lookupList($conn,
    "SELECT DISTINCT Salesman FROM [dbo].[View_CheckInformation]
     WHERE Salesman IS NOT NULL AND Salesman <> ''
       {$_dropDateWhere} {$_dropDeptWhere}
     ORDER BY Salesman"
);

$areaList = lookupList($conn,
    "SELECT DISTINCT Area FROM [dbo].[View_CheckInformation]
     WHERE Area IS NOT NULL AND Area <> ''
       {$_dropDateWhere} {$_dropDeptWhere}
     ORDER BY Area"
);

// ── Stat query (single round trip) ────────────────────────────
$statSql = "
    SELECT
        COUNT(*)                AS TotalChecks,
        SUM(CheckAmount)        AS TotalAmount,
        AVG(CheckAmount)        AS AvgAmount,
        COUNT(DISTINCT Bank)    AS BankCount
    FROM [dbo].[View_CheckInformation]
    WHERE CheckDate BETWEEN '$baseFrom' AND '$baseTo'
      AND CheckAmount > 1
      $commonWhere
";
$statRow = runQuery($conn, $statSql)[0] ?? [];

$totalChecks = (int)($statRow['TotalChecks'] ?? 0);
$totalAmount = (float)($statRow['TotalAmount'] ?? 0);
$avgAmount   = (float)($statRow['AvgAmount'] ?? 0);
$bankCount   = (int)($statRow['BankCount'] ?? 0);

// ── Main query ──────────────────────────────────────────────
$sqlError = null;
$mainSql = "
    SELECT
        Department, CustomerName, Salesman, Area, Type,
        TransactionNo, InvoiceNo, InvoiceDate,
        Bank, CheckNo, CheckDate, CheckAmount
    FROM [dbo].[View_CheckInformation]
    WHERE CheckDate BETWEEN '$baseFrom' AND '$baseTo'
      AND CheckAmount > 1
      $commonWhere
    ORDER BY CheckDate DESC
";
$result = runQueryDebug($conn, $mainSql);
if (isset($result['__error__'])) {
    $sqlError = $result['__error__'];
    $data = [];
} else {
    $data = $result;
}

// ── Pagination (PHP-based, no extra COUNT query) ──────────────
$rowLimit   = 25;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

// Sync the stat card count to the real filtered row count (in case the
// stat query and main query ever drift, e.g. NULL CheckAmount edge cases)
$totalChecks = $totalRows;

// NOTE: $conn is intentionally NOT closed here — topbar.php (included later)
// runs its own sqlsrv_query() against the same connection. PHP closes the
// connection automatically when the script ends.

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

// ── Helpers ──────────────────────────────────────────────────
function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }

function fmtDate($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    if (is_string($d) && strlen($d) >= 10) return substr($d, 0, 10);
    return htmlspecialchars($d ?? '—');
}

function deptBadge(?string $d): string {
    $map = [
        'monde'      => ['rgba(239,68,68,.15)',    '#ef4444', '#fca5a5'],
        'century'    => ['rgba(59,130,246,.15)',   '#3b82f6', '#93c5fd'],
        'multilines' => ['rgba(234,179,8,.15)',    '#ca8a04', '#fde047'],
        'nutriasia'  => ['rgba(16,185,129,.15)',   '#059669', '#6ee7b7'],
        'silverswan' => ['rgba(99,102,241,.15)',   '#6366f1', '#a5b4fc'],
        'urban tradewell corp.' => ['rgba(28,61,126,.15)', '#0b2b6d', '#113472'],
        ''           => ['rgba(107,114,128,.15)',  '#6b7280', '#9ca3af'],
    ];
    $key = strtolower(trim($d ?? ''));
    [$bg,$color,$border] = $map[$key] ?? $map[''];
    $label = htmlspecialchars(trim($d ?? '') !== '' ? trim($d) : '—');
    return "<span style='background:$bg;color:$color;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>$label</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check Information — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
  --ci-accent  : #7c3aed;
  --ci-accent2 : #a78bfa;
  --ci-green   : #16a34a;
  --ci-blue    : #2563eb;
  --ci-teal    : #0d9488;
  --ci-orange  : #ea580c;
}

/* ── Page Header ─────────────────────────────────────── */
.page-header { margin-bottom: 1.25rem; }
.page-title { font-size: 1.4rem; font-weight: 800; color: var(--text, #111827); }
.page-title span { color: var(--ci-accent); }
.page-badge { font-size: .8rem; color: var(--text-dim, #6b7280); margin-top: .15rem; }

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
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.sc-icon  { font-size: 1.4rem; margin-bottom: .15rem; }
.sc-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: var(--text-dim, #6b7280); font-weight: 700; }
.sc-value { font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: var(--text, #111827); font-family: 'JetBrains Mono', monospace; }
.sc-sub   { font-size: .72rem; color: var(--text-dim, #6b7280); margin-top: .1rem; }

/* ── Filter Panel ───────────────────────────────────── */
.filter-panel {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
  margin-bottom: 1rem;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
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
  background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; white-space: nowrap;
}
.filter-tag.tag-date     { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.filter-tag.tag-dept     { background: #fef9c3; color: #713f12; border-color: #fde047; }
.filter-tag.tag-bank     { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; }
.filter-tag.tag-salesman { background: #dcfce7; color: #166534; border-color: #4ade80; }
.filter-tag.tag-area     { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
.filter-toggle-icon { font-size: .75rem; color: var(--text-dim, #9ca3af); transition: transform .2s; }
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
  transition: border-color .15s, box-shadow .15s;
}
.filter-group input[type=date]:focus,
.filter-group select:focus {
  outline: none; border-color: var(--ci-accent);
  box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}
.filter-locked {
  font-size: .82rem; padding: .4rem .7rem; height: 36px;
  display: flex; align-items: center;
  border: 1.5px dashed var(--border, #d1d5db); border-radius: 8px;
  color: var(--text-dim, #6b7280); background: var(--input-bg, #f3f4f6);
}
.filter-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.btn-filter {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem 1.1rem; border-radius: 8px;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  border: none; height: 36px; transition: all .15s;
}
.btn-filter.apply { background: var(--ci-accent); color: #fff; box-shadow: 0 1px 4px rgba(124,58,237,.3); }
.btn-filter.apply:hover { background: #6d28d9; }
.btn-filter.reset { background: var(--input-bg, #f3f4f6); color: var(--text, #374151); border: 1.5px solid var(--border, #d1d5db); }
.btn-filter.reset:hover { background: #e5e7eb; }

/* ── Table Card ─────────────────────────────────────── */
.table-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
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
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}
.table-count {
  font-size: .68rem; font-weight: 700; padding: .15rem .5rem;
  border-radius: 999px; background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe;
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
  transition: border-color .15s, box-shadow .15s;
}
.search-wrap input:focus { outline: none; border-color: var(--ci-accent); box-shadow: 0 0 0 3px rgba(124,58,237,.1); }
.btn-action {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .85rem; border-radius: 7px;
  font-size: .76rem; font-weight: 700; cursor: pointer;
  border: 1.5px solid transparent; height: 32px;
  transition: all .15s; white-space: nowrap;
}
.btn-csv   { background: #f0fdf4; color: #166534; border-color: #4ade80; }
.btn-csv:hover { background: #dcfce7; }
.btn-excel { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.btn-excel:hover { background: #dbeafe; }
.btn-print { background: #f5f3ff; color: #5b21b6; border-color: #c4b5fd; }
.btn-print:hover { background: #ede9fe; }

/* ── Main Table ─────────────────────────────────────── */
.table-scroll { overflow-x: auto; }
.main-table {
  width: 100%; border-collapse: collapse; font-size: .8rem;
}
.main-table thead th {
  background: var(--input-bg, #f9fafb); color: var(--text-dim, #6b7280);
  font-size: .68rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; padding: .6rem .75rem;
  border-bottom: 2px solid var(--border, #e5e7eb);
  white-space: nowrap; cursor: pointer; user-select: none;
}
.main-table thead th:hover { color: var(--ci-accent); }
.main-table tbody td {
  padding: .5rem .75rem; color: var(--text, #374151);
  border-bottom: 1px solid var(--border, #f1f3f7); vertical-align: middle;
}
.main-table tbody tr:hover { background: #f5f3ff; }
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
  text-decoration: none; transition: all .15s;
}
.btn-page:hover:not(.disabled) { border-color: var(--ci-accent); color: var(--ci-accent); }
.btn-page.disabled { opacity: .4; pointer-events: none; }

/* ── Empty / Error states ───────────────────────────── */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim, #9ca3af); }
.empty-state .icon { font-size: 2.2rem; display: block; margin-bottom: .5rem; opacity: .5; }
.ci-error {
  display: flex; align-items: flex-start; gap: .6rem;
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 10px; padding: .75rem 1rem;
  font-size: .8rem; color: #b91c1c; margin: .5rem 1rem;
}
</style>
</head>
<body>

<?php $topbar_page = 'check_information'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">Check <span>Information</span></div>
      <div class="page-badge">📅 <?= date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <div class="stat-card" style="border-left:3px solid var(--ci-accent);">
      <span class="sc-icon">🧾</span>
      <span class="sc-label">Total Checks</span>
      <span class="sc-value" style="color:var(--ci-accent)"><?= number_format($totalChecks) ?></span>
      <span class="sc-sub"><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--ci-blue);">
      <span class="sc-icon">💵</span>
      <span class="sc-label">Total Amount</span>
      <span class="sc-value" style="color:var(--ci-blue)"><?= peso($totalAmount) ?></span>
      <span class="sc-sub">Sum of checks in range</span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--ci-teal);">
      <span class="sc-icon">📊</span>
      <span class="sc-label">Average Check</span>
      <span class="sc-value" style="color:var(--ci-teal)"><?= peso($avgAmount) ?></span>
      <span class="sc-sub">Per check, this range</span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--ci-orange);">
      <span class="sc-icon">🏦</span>
      <span class="sc-label">Banks Involved</span>
      <span class="sc-value" style="color:var(--ci-orange)"><?= number_format($bankCount) ?></span>
      <span class="sc-sub">Distinct banks, this range</span>
    </div>
  </div>

  <!-- ── Filter Panel ─────────────────────────────────────── -->
  <div class="filter-panel">
    <div class="filter-panel-header" onclick="toggleFilter()">
      <div class="filter-panel-header-left">
        <i class="bi bi-funnel-fill" style="color:var(--ci-accent)"></i>
        Filters
        <?php if ($anyFilterApplied): ?>
          <span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:999px;padding:1px 8px;font-size:.68rem;">Active</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;">
        <div class="filter-active-tags" id="headerTags">
          <?php if ($dateActive): ?><span class="filter-tag tag-date"><i class="bi bi-calendar3"></i><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span><?php endif; ?>
          <?php if ($deptActive && !$deptIsLocked): ?><span class="filter-tag tag-dept"><i class="bi bi-diagram-3"></i><?= htmlspecialchars($selDept) ?></span><?php endif; ?>
          <?php if ($bankActive):     ?><span class="filter-tag tag-bank"><i class="bi bi-bank"></i><?= htmlspecialchars($selBank) ?></span><?php endif; ?>
          <?php if ($salesmanActive): ?><span class="filter-tag tag-salesman"><i class="bi bi-person"></i><?= htmlspecialchars($selSalesman) ?></span><?php endif; ?>
          <?php if ($areaActive):     ?><span class="filter-tag tag-area"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($selArea) ?></span><?php endif; ?>
        </div>
        <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
      </div>
    </div>
    <div class="filter-body" id="filterBody">
      <form method="GET" action="">
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
            <label>Department</label>
            <?php if ($deptIsLocked): ?>
              <div class="filter-locked"><i class="bi bi-lock-fill" style="margin-right:.4rem;"></i><?= htmlspecialchars($currentUserDept) ?></div>
            <?php else: ?>
              <select name="dept">
                <option value="">All Departments</option>
                <?php foreach ($deptList as $d): ?>
                  <option value="<?= htmlspecialchars($d) ?>" <?= $selDept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div class="filter-group">
            <label>Bank</label>
            <select name="bank">
              <option value="">All Banks</option>
              <?php foreach ($bankList as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $selBank === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
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
          <a href="?" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Table Card ───────────────────────────────────────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        🧾 Check List
        <span class="table-count"><?= number_format($totalRows) ?> records</span>
        <span style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
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

    <?php if ($sqlError): ?>
      <div class="ci-error"><span>⚠️</span><span><b>SQL Error:</b> <?= htmlspecialchars($sqlError) ?></span></div>
    <?php elseif (empty($data)): ?>
      <div class="empty-state"><span class="icon">📭</span><p>No checks found for the selected filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="mainTable">
          <thead>
            <tr>
              <th onclick="sortTable(0)">Department</th>
              <th onclick="sortTable(1)">Customer</th>
              <th onclick="sortTable(2)">Salesman</th>
              <th onclick="sortTable(3)">Area</th>
              <th onclick="sortTable(4)">Type</th>
              <th onclick="sortTable(5)">Ref. No.</th>
              <th onclick="sortTable(6)">Invoice No.</th>
              <th onclick="sortTable(7)">Inv. Date</th>
              <th onclick="sortTable(8)">Bank</th>
              <th onclick="sortTable(9)">Chk #</th>
              <th onclick="sortTable(10)">Chk Date</th>
              <th class="r" onclick="sortTable(11)">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($displayData as $row): ?>
            <tr style="border-left:3px solid var(--ci-accent)">
              <td><?= deptBadge($row['Department'] ?? null) ?></td>
              <td><?= htmlspecialchars($row['CustomerName'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['Type'] ?? '—') ?></td>
              <td class="mono"><?= htmlspecialchars($row['TransactionNo'] ?? '—') ?></td>
              <td class="mono"><?= htmlspecialchars($row['InvoiceNo'] ?? '—') ?></td>
              <td><?= fmtDate($row['InvoiceDate'] ?? null) ?></td>
              <td><?= htmlspecialchars($row['Bank'] ?? '—') ?></td>
              <td class="mono"><?= htmlspecialchars($row['CheckNo'] ?? '—') ?></td>
              <td><?= fmtDate($row['CheckDate'] ?? null) ?></td>
              <td class="r" style="color:var(--ci-accent);font-weight:700"><?= peso($row['CheckAmount'] ?? 0) ?></td>
            </tr>
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

// ── Filter panel toggle ─────────────────────────────────────
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const icon = document.getElementById('filterToggleIcon');
    const tags = document.getElementById('headerTags');
    const isOpen = body.classList.toggle('open');
    icon.classList.toggle('open', isOpen);
    tags.style.display = isOpen ? 'none' : 'flex';
}
<?php if ($anyFilterApplied): ?>toggleFilter();<?php endif; ?>

// ── Table search ─────────────────────────────────────────────
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

// ── Column sort ──────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.replace(/[₱,\s]/g, '') || '';
        const bv = b.cells[col]?.innerText.replace(/[₱,\s]/g, '') || '';
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
        return _sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── CSV export (full dataset) ──────────────────────────────────
function exportCSV() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const headers = Object.keys(ALL_DATA[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...ALL_DATA.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `check_information_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Excel export (full dataset) ──────────────────────────────────
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
    XLSX.utils.book_append_sheet(wb, ws, 'Check Information');
    XLSX.writeFile(wb, `check_information_<?= date('Ymd') ?>.xlsx`);
}

// ── Print (full dataset, proper print window) ───────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');

    function fmtDate(v) {
        if (!v) return '—';
        if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
        return String(v).substring(0, 10);
    }
    function peso(v) {
        return '₱ ' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    const thead = `<thead><tr>
      <th>Department</th><th>Customer</th><th>Salesman</th><th>Area</th><th>Type</th>
      <th>Ref. No.</th><th>Invoice No.</th><th>Inv. Date</th>
      <th>Bank</th><th>Chk #</th><th>Chk Date</th><th class="r">Amount</th>
    </tr></thead>`;

    const tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
      <td>${(r.Department||'—').trim()}</td>
      <td>${r.CustomerName??'—'}</td>
      <td>${r.Salesman??'—'}</td>
      <td>${r.Area??'—'}</td>
      <td>${r.Type??'—'}</td>
      <td>${r.TransactionNo??'—'}</td>
      <td>${r.InvoiceNo??'—'}</td>
      <td>${fmtDate(r.InvoiceDate)}</td>
      <td>${r.Bank??'—'}</td>
      <td>${r.CheckNo??'—'}</td>
      <td>${fmtDate(r.CheckDate)}</td>
      <td class="r">${peso(r.CheckAmount)}</td>
    </tr>`).join('') + '</tbody>';

    const totalAmt = ALL_DATA.reduce((s, r) => s + parseFloat(r.CheckAmount || 0), 0);

    const win = window.open('', '_blank');
    win.document.write(`
      <html><head><title>Check Information — <?= date('M d, Y') ?></title>
      <style>
        body { font-family: Arial, sans-serif; padding: 24px; color:#111827; }
        h2 { margin-bottom: 2px; }
        .sub { color:#6b7280; font-size:13px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { background:#f9fafb; text-align:left; padding:6px 8px; border-bottom:2px solid #e5e7eb; text-transform:uppercase; font-size:10px; color:#6b7280; }
        td { padding:5px 8px; border-bottom:1px solid #f1f3f7; }
        .r { text-align:right; }
        tfoot td { font-weight:700; border-top:2px solid #e5e7eb; background:#f9fafb; }
      </style></head>
      <body>
        <h2>🧾 Check Information</h2>
        <div class="sub">Range: <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?> &nbsp;·&nbsp; ${ALL_DATA.length} records</div>
        <table>${thead}${tbody}
          <tfoot><tr><td colspan="11" class="r">Total</td><td class="r">${peso(totalAmt)}</td></tr></tfoot>
        </table>
      </body></html>
    `);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 300);
}
</script>

</body>
</html>