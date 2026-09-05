<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'other_payment_details');

// -- AJAX: OPD detail lines for a given OPID (modal) --
if (isset($_GET['ajax']) && $_GET['ajax'] === 'opd_detail') {
    header('Content-Type: application/json');
    $opid = (int)($_GET['opid'] ?? 0);
    if ($opid === 0) { echo json_encode([]); exit; }
    $sql = "
        SELECT
            OPDID, OPD_Date, EmployeeID, ReferenceNo, Source, ShortRemarks,
            Amount, AddLess, NetAmount, Remitted, UserInput, DateTimeInput,
            ShortEmployeeName, InputName, SourceName, Status,
            OP_Date, Department, Branch
        FROM [dbo].[View_OtherPayment_Details]
        WHERE OPID = $opid
        ORDER BY OPD_Date ASC, OPDID ASC
    ";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        $errs = sqlsrv_errors();
        error_log('opd_detail SQL error: ' . json_encode($errs));
        sqlsrv_close($conn);
        echo json_encode(['__error__' => $errs[0]['message'] ?? 'Unknown SQL error']);
        exit;
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d H:i:s');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    echo json_encode($rows);
    exit;
}

// ── Current user context ─────────────────────────────────────
$currentUserDept   = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');
$currentUserBranch = $_SESSION['Branch']     ?? ($_SESSION['branch']     ?? '');

// Tab-switch style AJAX for table body only (no full reload); kept for
// consistency with other Finance modules even though this page has one view.
$isTableAjax = isset($_GET['ajax']) && $_GET['ajax'] === 'table_only';

// ── View tab: Summary (grouped by OPID) vs Search Details (flat OPD lines) ──
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'details' ? 'details' : 'summary';
$searchQ   = isset($_GET['q']) ? trim($_GET['q']) : '';

// ── Date filters ─────────────────────────────────────────────
$today     = date('Y-m-d');
$weekFrom  = date('Y-m-d', strtotime('-29 days')); // last 30 days

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
if (!$dateActive) {
    $baseFrom = $weekFrom;
    $baseTo   = $today;
} else {
    $baseFrom = $dateFrom !== '' ? $dateFrom : $weekFrom;
    $baseTo   = $dateTo   !== '' ? $dateTo   : $today;
}

// ── Other filters ────────────────────────────────────────────
$selBranch = isset($_GET['branch']) ? trim($_GET['branch']) : '';
$selDept   = isset($_GET['dept'])   ? trim($_GET['dept'])   : $currentUserDept;
$selStatus = isset($_GET['status']) ? trim($_GET['status']) : '';

$branchActive = $selBranch !== '';
$deptActive   = $selDept   !== '' && strtolower($selDept) !== 'all';
$statusActive = $selStatus !== '';

$searchActive     = $searchQ !== '';
$anyFilterApplied = $dateActive || $branchActive || $statusActive || $searchActive;

// ── Safe values ───────────────────────────────────────────────
$_branchSafe = str_replace("'", "''", $selBranch);
$_deptSafe   = str_replace("'", "''", $selDept);
$_statusSafe = str_replace("'", "''", $selStatus);

// ── WHERE clauses (applied on the header-level GROUP BY query) ─
$branchWhere = $branchActive ? " AND Branch = '$_branchSafe'"          : '';
$deptWhere   = $deptActive   ? " AND RTRIM(Department) = '$_deptSafe'" : '';
$statusWhere = $statusActive ? " AND Status = '$_statusSafe'"          : '';
$commonWhere = $branchWhere . $deptWhere . $statusWhere;

// ── Helper: run a query and return all rows ──────────────────
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

function lookupList($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $list = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Lookup dropdowns (scoped to current date range + dept) ───
$_dropDateWhere = " AND OP_Date BETWEEN '$baseFrom' AND '$baseTo'";
$_dropDeptWhere = ($deptActive) ? " AND RTRIM(Department) = '$_deptSafe'" : '';

if ($isTableAjax) {
    $branchList = [];
    $statusList = [];
} else {
    $branchList = lookupList($conn,
        "SELECT DISTINCT Branch FROM [dbo].[View_OtherPayment_Details]
         WHERE Branch IS NOT NULL AND Branch <> ''
           {$_dropDateWhere} {$_dropDeptWhere}
         ORDER BY Branch"
    );
    $statusList = lookupList($conn,
        "SELECT DISTINCT Status FROM [dbo].[View_OtherPayment_Details]
         WHERE Status IS NOT NULL AND Status <> ''
           {$_dropDateWhere} {$_dropDeptWhere}
         ORDER BY Status"
    );
}

// ── Header-level (grouped by OPID) dataset ────────────────────
// The view is flattened to one row per OPD line, so header columns repeat
// per OPID. GROUP BY the full header column set collapses back to one row
// per OPID without losing/mismatching any header value.
$headerSql = "
    SELECT
        OPID, OP_Date, Department, Branch, TypeID,
        TotalAmount, TotalAddLess, TotalNetAmount, Remarks,
        COUNT(OPDID) AS DetailCount
    FROM [dbo].[View_OtherPayment_Details]
    WHERE OP_Date BETWEEN '$baseFrom' AND '$baseTo'
      $commonWhere
    GROUP BY OPID, OP_Date, Department, Branch, TypeID, TotalAmount, TotalAddLess, TotalNetAmount, Remarks
    ORDER BY OP_Date DESC, OPID DESC
";

$data = runQuery($conn, $headerSql);

// ── Stats derived from the full (unpaginated) grouped dataset ─
$opCount          = count($data);
$totalAmountSum    = array_sum(array_column($data, 'TotalAmount'));
$totalAddLessSum   = array_sum(array_column($data, 'TotalAddLess'));
$totalNetAmountSum = array_sum(array_column($data, 'TotalNetAmount'));

// ── Pagination (PHP-based, no extra COUNT query) ──────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

// ── Detail-level (flat OPD line) dataset — Search Details tab ─
// One row per OPD line across all OPIDs, so a reference number, employee
// name, or remark can be matched directly instead of hunting through
// grouped OPID rows. Same branch/dept/status filters apply. Date scope:
// - explicit date_from/date_to always wins (user chose a range on purpose)
// - otherwise, a search term searches ALL TIME (no default-window limit)
// - with no search term and no explicit dates, falls back to the last 30 days
$_searchSafe = str_replace("'", "''", $searchQ);
// Strip thousands separators/currency symbol so "1,500" or "₱1500" still
// matches an Amount/NetAmount column stored as a plain decimal.
$_amountSafe = str_replace([',', '₱', ' '], '', $_searchSafe);
$searchWhere = '';
if ($_searchSafe !== '') {
    $searchWhere = " AND (
        ReferenceNo      LIKE '%$_searchSafe%'
        OR ShortEmployeeName LIKE '%$_searchSafe%'
        OR SourceName        LIKE '%$_searchSafe%'
        OR ShortRemarks      LIKE '%$_searchSafe%'
        OR Remarks            LIKE '%$_searchSafe%'
        OR CAST(OPID AS VARCHAR(20))  LIKE '%$_searchSafe%'
        OR CAST(OPDID AS VARCHAR(20)) LIKE '%$_searchSafe%'
        OR CAST(Amount AS VARCHAR(30))    LIKE '%$_amountSafe%'
        OR CAST(NetAmount AS VARCHAR(30)) LIKE '%$_amountSafe%'
    )";
}

$detailSearchAllTime = ($searchActive && !$dateActive);
$detailMonthFrom = date('Y-m-d', strtotime('-29 days')); // last 30 days — Search Details' own default window
if ($detailSearchAllTime) {
    $detailDateWhere = '';
    $detailRangeLabel = 'All time';
} elseif ($dateActive) {
    $detailDateWhere = " AND OP_Date BETWEEN '$baseFrom' AND '$baseTo'";
    $detailRangeLabel = "$baseFrom → $baseTo";
} else {
    // Browsing with no search and no explicit dates: default to last 30 days
    // (same 30-day default as the Summary tab) instead of loading all time.
    $detailDateWhere = " AND OP_Date BETWEEN '$detailMonthFrom' AND '$today'";
    $detailRangeLabel = "$detailMonthFrom → $today (last 30 days)";
}

$detailData = [];
if ($activeTab === 'details') {
    $detailSql = "
        SELECT
            OPID, OPDID, OPD_Date, OP_Date, Department, Branch, ReferenceNo,
            Amount, AddLess, NetAmount, SourceName, ShortEmployeeName,
            ShortRemarks, Remarks, DateTimeInput, InputName, Remitted, Status
        FROM [dbo].[View_OtherPayment_Details]
        WHERE 1=1
          $detailDateWhere
          $commonWhere
          $searchWhere
        ORDER BY OP_Date DESC, OPID DESC, OPD_Date ASC, OPDID ASC
    ";
    $detailData = runQuery($conn, $detailSql);
}


$detailTotalRows  = count($detailData);
$detailTotalPages = max(1, (int)ceil($detailTotalRows / $rowLimit));
$detailCurPage    = isset($_GET['dpage']) ? max(1, min($detailTotalPages, (int)$_GET['dpage'])) : 1;
$detailOffset     = ($detailCurPage - 1) * $rowLimit;
$detailDisplayData = array_slice($detailData, $detailOffset, $rowLimit);
$detailExportData  = $detailData;

// NOTE: $conn is intentionally NOT closed here.
// topbar.php (included later on this page) calls get_employee_profile($conn),
// which runs sqlsrv_query() against the same connection. PHP closes the
// connection automatically when the script ends. The sqlsrv_close() calls in
// the AJAX block above are safe because that block calls exit immediately
// after — it never reaches the topbar include.

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}

function detailPageUrl(int $p): string {
    $params = $_GET; $params['dpage'] = $p;
    return '?' . http_build_query($params);
}

function resetUrl(string $tab): string {
    return '?' . http_build_query(['tab' => $tab]);
}

function tabUrl(string $tab): string {
    $params = $_GET;
    $params['tab'] = $tab;
    unset($params['page'], $params['dpage']);
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

$detailPrevUrl = $detailCurPage > 1                ? detailPageUrl($detailCurPage - 1) : '';
$detailNextUrl = $detailCurPage < $detailTotalPages ? detailPageUrl($detailCurPage + 1) : '';

// ── Helpers ──────────────────────────────────────────────────
function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }

function fmtDate($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    if (is_string($d) && strlen($d) >= 10) return substr($d, 0, 10);
    return htmlspecialchars($d ?? '—');
}

function fmtDateTime($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d H:i');
    if (is_string($d) && strlen($d) >= 16) return substr($d, 0, 16);
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

function branchBadge(?string $b): string {
    $map = [
        'quezon'       => ['#ede9fe','#5b21b6','#a78bfa'],
        'quezon upper' => ['#dbeafe','#1e3a8a','#93c5fd'],
        'marinduque'   => ['#dcfce7','#166534','#4ade80'],
    ];
    [$bg,$text,$border] = $map[strtolower(trim($b ?? ''))] ?? ['#f3f4f6','#374151','#d1d5db'];
    return "<span style='background:$bg;color:$text;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>" . htmlspecialchars($b ?? '—') . "</span>";
}

function statusBadge($s): string {
    $label = trim((string)($s ?? ''));
    if ($label === '') return '<span style="color:var(--text-dim,#9ca3af);">—</span>';
    $map = [
        'created'  => ['#dbeafe', '#1e40af', '#93c5fd'],
        'received' => ['#dcfce7', '#166534', '#4ade80'],
    ];
    [$bg,$text,$border] = $map[strtolower($label)] ?? ['#f3f4f6','#374151','#d1d5db'];
    return "<span style='background:$bg;color:$text;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>" . htmlspecialchars($label) . "</span>";
}

function remittedBadge($v): string {
    if ($v === null || $v === '') return '<span style="color:var(--text-dim,#9ca3af);">—</span>';
    // Remitted view column: 1 = Remitted, 0 = Not yet Remitted.
    $s = is_string($v) ? trim($v) : $v;
    $isRemitted = ($s === 1 || $s === '1' || $s === true || strtolower((string)$s) === 'yes');
    if ($isRemitted) return '<span style="background:#dcfce7;color:#166534;border:1px solid #4ade80;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;">Remitted</span>';
    return '<span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;">Not Remitted</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Other Payment Details — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
  --od-accent  : #0d9488;
  --od-accent2 : #5eead4;
  --od-green   : #16a34a;
  --od-yellow  : #ca8a04;
  --od-red     : #dc2626;
  --od-blue    : #2563eb;
  --od-orange  : #ea580c;
  --od-purple  : #7c3aed;
}

/* ── View Tabs ──────────────────────────────────────── */
.view-tabs {
  display: flex; gap: .4rem; margin-bottom: 1.1rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
}
.view-tab {
  display: inline-flex; align-items: center; gap: .4rem;
  padding: .6rem 1.1rem; font-size: .82rem; font-weight: 700;
  color: var(--text-dim, #6b7280); text-decoration: none;
  border-bottom: 2.5px solid transparent; margin-bottom: -1px;
  transition: color .15s, border-color .15s;
}
.view-tab:hover { color: var(--od-accent); }
.view-tab.active { color: var(--od-accent); border-bottom-color: var(--od-accent); }
.view-tab-badge {
  background: #ccfbf1; color: #0f766e; border: 1px solid #5eead4;
  border-radius: 999px; padding: 0 7px; font-size: .68rem; font-weight: 700;
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
  background: #ccfbf1; color: #0f766e; border: 1px solid #5eead4; white-space: nowrap;
}
.filter-tag.tag-date   { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.filter-tag.tag-dept   { background: #fef9c3; color: #713f12; border-color: #fde047; }
.filter-tag.tag-status { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
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
.filter-group-search { min-width: 260px; }
.filter-hint {
  display: flex; align-items: center; gap: .3rem;
  font-size: .68rem; color: var(--text-dim, #9ca3af); margin-top: .15rem;
}
.smart-search-wrap {
  position: relative; display: flex; align-items: center;
  border: 1.5px solid var(--od-accent2, #5eead4);
  border-radius: 999px; background: var(--input-bg, #f9fafb);
  height: 36px; transition: border-color .15s, box-shadow .15s;
}
.smart-search-wrap:focus-within {
  border-color: var(--od-accent); box-shadow: 0 0 0 3px rgba(13,148,136,.12);
}
.smart-search-icon {
  position: absolute; left: .8rem; color: var(--od-accent); font-size: .85rem; pointer-events: none;
}
.smart-search-input {
  width: 100%; height: 100%; border: none; background: transparent;
  padding: 0 2.1rem 0 2.1rem; font-size: .82rem; color: var(--text, #111827);
  border-radius: 999px; outline: none;
}
.smart-search-clear {
  position: absolute; right: .55rem; border: none; background: none; cursor: pointer;
  color: var(--text-dim, #9ca3af); font-size: .95rem; padding: 0; line-height: 1;
  display: flex; align-items: center; transition: color .15s;
}
.smart-search-clear:hover { color: var(--od-red); }
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
  outline: none; border-color: var(--od-accent);
  box-shadow: 0 0 0 3px rgba(13,148,136,.1);
}
.filter-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.btn-filter {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem 1.1rem; border-radius: 8px;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  border: none; height: 36px; transition: all .15s;
}
.btn-filter.apply { background: var(--od-accent); color: #fff; box-shadow: 0 1px 4px rgba(13,148,136,.3); }
.btn-filter.apply:hover { background: #0f766e; }
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
  transition: border-color .15s, box-shadow .15s;
}
.search-wrap input:focus { outline: none; border-color: var(--od-accent); box-shadow: 0 0 0 3px rgba(13,148,136,.1); }
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
.btn-print { background: #f0fdfa; color: #0f766e; border-color: #5eead4; }
.btn-print:hover { background: #ccfbf1; }

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
.main-table thead th:hover { color: var(--od-accent); }
.main-table tbody td {
  padding: .5rem .75rem; color: var(--text, #374151);
  border-bottom: 1px solid var(--border, #f1f3f7); vertical-align: middle;
}
.main-table tbody tr:hover { background: #f0fdfa; }
.main-table tbody tr[onclick]:hover { background: #ccfbf1; cursor: pointer; }
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
.btn-page:hover:not(.disabled) { border-color: var(--od-accent); color: var(--od-accent); }
.btn-page.disabled { opacity: .4; pointer-events: none; }

/* ── Empty / Error states ───────────────────────────── */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim, #9ca3af); }
.empty-state .icon { font-size: 2.2rem; display: block; margin-bottom: .5rem; opacity: .5; }
.od-error {
  display: flex; align-items: flex-start; gap: .6rem;
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 10px; padding: .75rem 1rem;
  font-size: .8rem; color: #b91c1c; margin: .5rem 1rem;
}
</style>
</head>
<body>

<?php $topbar_page = 'other_payment_details'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">Other <span>Payment</span> Details</div>
      <div class="page-badge">📅 <?= date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <!-- ── View Tabs ────────────────────────────────────────── -->
  <div class="view-tabs">
    <a href="<?= tabUrl('summary') ?>" class="view-tab <?= $activeTab === 'summary' ? 'active' : '' ?>">
      <i class="bi bi-layers"></i> Summary
    </a>
    <a href="<?= tabUrl('details') ?>" class="view-tab <?= $activeTab === 'details' ? 'active' : '' ?>">
      <i class="bi bi-search"></i> Search Details
      <?php if ($searchQ !== '' && $activeTab === 'details'): ?>
        <span class="view-tab-badge"><?= number_format($detailTotalRows) ?></span>
      <?php endif; ?>
    </a>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <div class="stat-card" style="border-left:3px solid var(--od-accent);">
      <span class="sc-icon">🧾</span>
      <span class="sc-label">Other Payments</span>
      <span class="sc-value" style="color:var(--od-accent)"><?= number_format($opCount) ?></span>
      <span class="sc-sub"><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--od-blue);">
      <span class="sc-icon">💰</span>
      <span class="sc-label">Total Amount</span>
      <span class="sc-value" style="color:var(--od-blue)"><?= peso($totalAmountSum) ?></span>
      <span class="sc-sub">Sum across filtered records</span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--od-orange);">
      <span class="sc-icon">➕</span>
      <span class="sc-label">Total Add/Less</span>
      <span class="sc-value" style="color:var(--od-orange)"><?= peso($totalAddLessSum) ?></span>
      <span class="sc-sub">Sum across filtered records</span>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--od-green);">
      <span class="sc-icon">✅</span>
      <span class="sc-label">Total Net Amount</span>
      <span class="sc-value" style="color:var(--od-green)"><?= peso($totalNetAmountSum) ?></span>
      <span class="sc-sub">Sum across filtered records</span>
    </div>
  </div>

  <!-- ── Filter Panel ─────────────────────────────────────── -->
  <div class="filter-panel">
    <div class="filter-panel-header" onclick="toggleFilter()">
      <div class="filter-panel-header-left">
        <i class="bi bi-funnel-fill" style="color:var(--od-accent)"></i>
        Filters
        <?php if ($anyFilterApplied): ?>
          <span style="background:#ccfbf1;color:#0f766e;border:1px solid #5eead4;border-radius:999px;padding:1px 8px;font-size:.68rem;">Active</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;">
        <div class="filter-active-tags" id="headerTags">
          <?php if ($dateActive):   ?><span class="filter-tag tag-date"><i class="bi bi-calendar3"></i><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span><?php endif; ?>
          <?php if ($branchActive): ?><span class="filter-tag tag-dept"><i class="bi bi-diagram-3"></i><?= htmlspecialchars($selBranch) ?></span><?php endif; ?>
          <?php if ($statusActive): ?><span class="filter-tag tag-status"><i class="bi bi-flag"></i><?= htmlspecialchars($selStatus) ?></span><?php endif; ?>
          <?php if ($searchActive && $activeTab === 'details'): ?><span class="filter-tag tag-status"><i class="bi bi-search"></i><?= htmlspecialchars($searchQ) ?></span><?php endif; ?>
        </div>
        <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
      </div>
    </div>
    <div class="filter-body" id="filterBody">
      <form method="GET" action="">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
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
            <label>Status</label>
            <select name="status">
              <option value="">All Statuses</option>
              <?php foreach ($statusList as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $selStatus === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($activeTab === 'details'): ?>
          <div class="filter-group filter-group-search">
            <label>Search</label>
            <div class="smart-search-wrap">
              <i class="bi bi-search smart-search-icon"></i>
              <input type="text" id="qInput" name="q" class="smart-search-input"
                     placeholder="Reference #, employee, source, remark, amount, or OPID…"
                     value="<?= htmlspecialchars($searchQ) ?>" autocomplete="off">
              <?php if ($searchQ !== ''): ?>
                <button type="button" class="smart-search-clear" onclick="const i=document.getElementById('qInput'); i.value=''; i.closest('form').submit();" title="Clear search">
                  <i class="bi bi-x-circle-fill"></i>
                </button>
              <?php endif; ?>
            </div>
            <span class="filter-hint"><i class="bi bi-infinity"></i> Searches all time unless a date range is set</span>
          </div>
          <?php endif; ?>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter apply"><i class="bi bi-funnel-fill"></i> Apply Filters</button>
          <a href="<?= htmlspecialchars(resetUrl($activeTab)) ?>" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <?php if ($activeTab === 'summary'): ?>
  <!-- ── Table Card (Summary — grouped by OPID) ────────────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        🧾 Other Payment Details
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
      <div class="empty-state"><span class="icon">📭</span><p>No records found for the selected filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="mainTable">
          <thead>
            <tr>
              <th onclick="sortTable(0)">OPID</th>
              <th onclick="sortTable(1)">OP Date</th>
              <th onclick="sortTable(2)">Department</th>
              <th onclick="sortTable(3)">Branch</th>
              <th class="r" onclick="sortTable(4)">Total Amount</th>
              <th class="r" onclick="sortTable(5)">Total Add/Less</th>
              <th class="r" onclick="sortTable(6)">Total Net Amount</th>
              <th onclick="sortTable(7)">Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($displayData as $row):
              $jsOpid = (int)($row['OPID'] ?? 0);
            ?>
            <tr style="border-left:3px solid var(--od-accent);cursor:pointer;" onclick="openOPModal(<?= $jsOpid ?>)">
              <td><b style="color:var(--od-accent);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($row['OPID'] ?? '—') ?></b> <span style="font-size:.65rem;color:#5eead4;margin-left:.25rem;" title="Click to view details">&#128269; expand</span></td>
              <td><?= fmtDate($row['OP_Date'] ?? null) ?></td>
              <td><?= deptBadge($row['Department'] ?? null) ?></td>
              <td><?= branchBadge($row['Branch'] ?? null) ?></td>
              <td class="r" style="font-weight:700"><?= peso($row['TotalAmount'] ?? 0) ?></td>
              <td class="r" style="color:<?= (float)($row['TotalAddLess'] ?? 0) < 0 ? 'var(--od-red)' : 'var(--od-orange)' ?>"><?= peso($row['TotalAddLess'] ?? 0) ?></td>
              <td class="r" style="color:var(--od-green);font-weight:700"><?= peso($row['TotalNetAmount'] ?? 0) ?></td>
              <td><?= htmlspecialchars($row['Remarks'] ?? '—') ?></td>
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
  <?php endif; ?>

  <?php if ($activeTab === 'details'): ?>
  <!-- ── Table Card (Search Details — flat OPD lines) ──────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        🔍 Search Details
        <span class="table-count"><?= number_format($detailTotalRows) ?> lines</span>
        <?php if ($detailSearchAllTime): ?>
          <span class="table-count" style="background:#ede9fe;color:#5b21b6;border-color:#c4b5fd;"><i class="bi bi-infinity"></i> All time</span>
        <?php elseif (!$dateActive): ?>
          <span class="table-count" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd;"><i class="bi bi-calendar3"></i> Last 30 days</span>
        <?php endif; ?>
      </div>
      <div class="table-actions">
        <button class="btn-action btn-csv"   onclick="exportDetailCSV()"><i class="bi bi-filetype-csv"></i> CSV</button>
        <button class="btn-action btn-excel" onclick="exportDetailExcel()"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</button>
        <button class="btn-action btn-print" onclick="printDetailTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>

    <?php if (empty($detailData)): ?>
      <div class="empty-state">
        <span class="icon">📭</span>
        <p><?= $searchActive
              ? 'No lines match “' . htmlspecialchars($searchQ) . '”' . ($detailSearchAllTime ? ' (searched all time).' : ' in the selected date range.')
              : 'No detail lines found for the selected filters.' ?></p>
      </div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="detailTable">
          <thead>
            <tr>
              <th onclick="sortDetailTable(0)">OPID</th>
              <th onclick="sortDetailTable(1)">OP Date</th>
              <th onclick="sortDetailTable(2)">Department</th>
              <th onclick="sortDetailTable(3)">Branch</th>
              <th onclick="sortDetailTable(4)">Reference No</th>
              <th class="r" onclick="sortDetailTable(5)">Amount</th>
              <th class="r" onclick="sortDetailTable(6)">Add/Less</th>
              <th class="r" onclick="sortDetailTable(7)">Net Amount</th>
              <th onclick="sortDetailTable(8)">Source</th>
              <th onclick="sortDetailTable(9)">Employee</th>
              <th onclick="sortDetailTable(10)">Remarks</th>
              <th onclick="sortDetailTable(11)">Input By</th>
              <th onclick="sortDetailTable(12)">Remitted</th>
              <th onclick="sortDetailTable(13)">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($detailDisplayData as $row):
              $jsOpid = (int)($row['OPID'] ?? 0);
            ?>
            <tr style="border-left:3px solid var(--od-accent);cursor:pointer;" onclick="openOPModal(<?= $jsOpid ?>)" title="View this OPID's full group">
              <td><b style="color:var(--od-accent);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($row['OPID'] ?? '—') ?></b> <span style="font-size:.65rem;color:#5eead4;margin-left:.25rem;">&#128269; view group</span></td>
              <td><?= fmtDate($row['OP_Date'] ?? null) ?></td>
              <td><?= deptBadge($row['Department'] ?? null) ?></td>
              <td><?= branchBadge($row['Branch'] ?? null) ?></td>
              <td class="mono"><?= htmlspecialchars($row['ReferenceNo'] ?? '—') ?></td>
              <td class="r" style="font-weight:700"><?= peso($row['Amount'] ?? 0) ?></td>
              <td class="r" style="color:<?= (float)($row['AddLess'] ?? 0) < 0 ? 'var(--od-red)' : 'var(--od-orange)' ?>"><?= peso($row['AddLess'] ?? 0) ?></td>
              <td class="r" style="color:var(--od-green);font-weight:700"><?= peso($row['NetAmount'] ?? 0) ?></td>
              <td><?= htmlspecialchars($row['SourceName'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['ShortEmployeeName'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['ShortRemarks'] ?? $row['Remarks'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['InputName'] ?? '—') ?></td>
              <td><?= remittedBadge($row['Remitted'] ?? null) ?></td>
              <td><?= statusBadge($row['Status'] ?? null) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($detailTotalPages > 1): ?>
      <div class="pagination-bar">
        <span class="pagination-info">
          Showing <strong><?= $detailOffset + 1 ?>–<?= min($detailOffset + $rowLimit, $detailTotalRows) ?></strong>
          of <strong><?= number_format($detailTotalRows) ?></strong> lines &nbsp;·&nbsp;
          Page <strong><?= $detailCurPage ?></strong> of <strong><?= $detailTotalPages ?></strong>
        </span>
        <div class="pagination-btns">
          <?php if ($detailPrevUrl): ?>
            <a href="<?= $detailPrevUrl ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Prev</a>
          <?php else: ?>
            <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Prev</span>
          <?php endif; ?>
          <?php if ($detailNextUrl): ?>
            <a href="<?= $detailNextUrl ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<!-- OP Detail Modal -->
<div id="opModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:16px;width:min(1200px,98vw);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border,#e5e7eb);background:var(--input-bg,#f9fafb);flex-shrink:0;">
      <div style="font-weight:700;font-size:.95rem;color:var(--text,#111827);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <span>🧾</span>
        <span style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:600;">OPID</span>
        <span id="opModalOPID" style="font-family:'JetBrains Mono',monospace;color:var(--od-accent);"></span>
        <span id="opModalSubtitle" style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"></span>
      </div>
      <button onclick="closeOPModal()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:var(--text-dim,#9ca3af);line-height:1;padding:.2rem .4rem;">&times;</button>
    </div>
    <!-- Loading -->
    <div id="opModalLoading" style="text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);"><div style="font-size:1.5rem;margin-bottom:.5rem;">⏳</div>Loading details...</div>
    <!-- Empty -->
    <div id="opModalEmpty" style="display:none;text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);">No detail records found.</div>
    <!-- Content -->
    <div id="opModalContentWrap" style="overflow-y:auto;flex:1;">
      <div class="table-scroll">
        <table id="opModalTable" class="main-table" style="display:none;">
          <thead><tr>
            <th>OP Date</th>
            <th>Department</th>
            <th>Branch</th>
            <th>Reference No</th>
            <th class="r">Amount</th>
            <th class="r">Add/Less</th>
            <th class="r">Net Amount</th>
            <th>Source</th>
            <th>Employee</th>
            <th>Remarks</th>
            <th>Date/Time Input</th>
            <th>Input By</th>
            <th>Remitted</th>
            <th>Status</th>
          </tr></thead>
          <tbody id="opModalTbody"></tbody>
          <tfoot id="opModalTfoot" style="font-weight:700;background:var(--input-bg,#f9fafb);border-top:2px solid var(--border,#e5e7eb);"></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
// ── Full dataset for export/print (not paginated) ────────────
let ALL_DATA = <?= json_encode(array_values($exportData), JSON_UNESCAPED_UNICODE) ?>;
// Search Details tab — flat OPD-line dataset for the current filters/search
let DETAIL_ALL_DATA = <?= json_encode(array_values($detailExportData), JSON_UNESCAPED_UNICODE) ?>;

function peso(v) {
    return '₱ ' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtDate(v) {
    if (!v) return '—';
    if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
    return String(v).substring(0, 10);
}
function fmtDateTime(v) {
    if (!v) return '—';
    if (typeof v === 'object' && v.date) return v.date.substring(0, 16).replace('T', ' ');
    return String(v).substring(0, 16);
}
// Remitted: 1 = Remitted, 0 = Not yet Remitted
function remittedBadge(v) {
    if (v === null || v === undefined || v === '') return '<span style="color:#9ca3af;">—</span>';
    var s = String(v).trim().toLowerCase();
    var isRemitted = (s === '1' || s === 'yes' || s === 'y' || s === 'true');
    return isRemitted
        ? '<span style="background:#dcfce7;color:#166534;border:1px solid #4ade80;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:600;white-space:nowrap;">Remitted</span>'
        : '<span style="background:#fef9c3;color:#854d0e;border:1px solid #fde047;padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:600;white-space:nowrap;">Not Remitted</span>';
}

// Status: Created / Received
function statusBadge(v) {
    var s = (v || '').toString().trim();
    if (!s) return '<span style="color:#9ca3af;">—</span>';
    var map = {
        'created':  ['#dbeafe', '#1e40af', '#93c5fd'],
        'received': ['#dcfce7', '#166534', '#4ade80'],
    };
    var c = map[s.toLowerCase()] || ['#f3f4f6', '#374151', '#d1d5db'];
    return '<span style="background:' + c[0] + ';color:' + c[1] + ';border:1px solid ' + c[2] + ';padding:2px 8px;border-radius:999px;font-size:.72rem;font-weight:600;white-space:nowrap;">' + s + '</span>';
}

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
<?php if ($activeTab === 'summary' && isset($_GET['focus_opid'])): ?>
openOPModal(<?= (int)$_GET['focus_opid'] ?>);
<?php endif; ?>

// ── Table search ──────────────────────────────────────────────
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

// ── Column sort ───────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    if (!tbody) return;
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const bv = b.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
        return _sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── CSV export (full dataset) ─────────────────────────────────
function exportCSV() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const headers = Object.keys(ALL_DATA[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...ALL_DATA.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `other_payment_details_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Excel export (full dataset) ───────────────────────────────
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
    XLSX.utils.book_append_sheet(wb, ws, 'Other Payment Details');
    XLSX.writeFile(wb, `other_payment_details_<?= date('Ymd') ?>.xlsx`);
}

// ── Print (full dataset, proper print window) ─────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');

    let thead = `<thead><tr>
      <th>OPID</th><th>OP Date</th><th>Department</th><th>Branch</th>
      <th class="r">Total Amount</th><th class="r">Total Add/Less</th><th class="r">Total Net Amount</th>
      <th>Remarks</th>
    </tr></thead>`;
    let tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
      <td class="mono">${r.OPID??'—'}</td>
      <td>${fmtDate(r.OP_Date)}</td>
      <td>${(r.Department||'').toString().trim()||'—'}</td>
      <td>${r.Branch??'—'}</td>
      <td class="r">${peso(r.TotalAmount)}</td>
      <td class="r">${peso(r.TotalAddLess)}</td>
      <td class="r text-green">${peso(r.TotalNetAmount)}</td>
      <td>${r.Remarks??'—'}</td>
    </tr>`).join('') + '</tbody>';

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>Other Payment Details</title>
      <style>
        @page { size: landscape; margin: 10mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; margin: 0; padding: 6px 8px; }
        .print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; border-bottom: 1.5px solid #333; padding-bottom: 4px; }
        .print-header h3 { margin: 0; font-size: 11px; font-weight: 800; }
        .print-header p  { margin: 0; font-size: 7.5px; color: #555; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #e8e8e8; font-weight: 700; font-size: 7.5px; text-transform: uppercase;
             letter-spacing: .03em; border: 1px solid #aaa; padding: 3px 4px; white-space: nowrap; }
        td { border: 1px solid #ccc; padding: 2px 4px; font-size: 8px; vertical-align: middle; }
        tr:nth-child(even) td { background: #f7f7f7; }
        .r { text-align: right; }
        .mono { font-family: 'Courier New', monospace; }
        .text-green { color: #166534; font-weight: 700; }
        @media print { body { padding: 0; } }
      </style>
    </head><body>
      <div class="print-header">
        <h3>🧾 Other Payment Details</h3>
        <p>Date Range: <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?><br>Exported: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp; Total: ${ALL_DATA.length} records</p>
      </div>
      <table>${thead}${tbody}</table>
    </body></html>`);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

// ── Search Details tab: table sort/export/print ────────────────let _detailSortDir = {};
function sortDetailTable(col) {
    const tbody = document.querySelector('#detailTable tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    _detailSortDir[col] = !_detailSortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const bv = b.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
        return _detailSortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

function exportDetailCSV() {
    if (!DETAIL_ALL_DATA.length) return alert('No data to export.');
    const headers = Object.keys(DETAIL_ALL_DATA[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...DETAIL_ALL_DATA.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `other_payment_search_details_<?= date('Ymd') ?>.csv`;
    a.click();
}

function exportDetailExcel() {
    if (!DETAIL_ALL_DATA.length) return alert('No data to export.');
    const cleanData = DETAIL_ALL_DATA.map(row => {
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
    XLSX.utils.book_append_sheet(wb, ws, 'Search Details');
    XLSX.writeFile(wb, `other_payment_search_details_<?= date('Ymd') ?>.xlsx`);
}

function printDetailTable() {
    if (!DETAIL_ALL_DATA.length) return alert('No data to print.');

    let thead = `<thead><tr>
      <th>OPID</th><th>OP Date</th><th>Department</th><th>Branch</th><th>Reference No</th>
      <th class="r">Amount</th><th class="r">Add/Less</th><th class="r">Net Amount</th>
      <th>Source</th><th>Employee</th><th>Remarks</th><th>Status</th>
    </tr></thead>`;
    let tbody = '<tbody>' + DETAIL_ALL_DATA.map(r => `<tr>
      <td class="mono">${r.OPID??'—'}</td>
      <td>${fmtDate(r.OP_Date)}</td>
      <td>${(r.Department||'').toString().trim()||'—'}</td>
      <td>${r.Branch??'—'}</td>
      <td class="mono">${r.ReferenceNo??'—'}</td>
      <td class="r">${peso(r.Amount)}</td>
      <td class="r">${peso(r.AddLess)}</td>
      <td class="r text-green">${peso(r.NetAmount)}</td>
      <td>${r.SourceName??'—'}</td>
      <td>${r.ShortEmployeeName??'—'}</td>
      <td>${r.ShortRemarks ?? r.Remarks ?? '—'}</td>
      <td>${(r.Status||'—')}</td>
    </tr>`).join('') + '</tbody>';

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>Search Details — Other Payment Details</title>
      <style>
        @page { size: landscape; margin: 10mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; margin: 0; padding: 6px 8px; }
        .print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; border-bottom: 1.5px solid #333; padding-bottom: 4px; }
        .print-header h3 { margin: 0; font-size: 11px; font-weight: 800; }
        .print-header p  { margin: 0; font-size: 7.5px; color: #555; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #e8e8e8; font-weight: 700; font-size: 7.5px; text-transform: uppercase;
             letter-spacing: .03em; border: 1px solid #aaa; padding: 3px 4px; white-space: nowrap; }
        td { border: 1px solid #ccc; padding: 2px 4px; font-size: 8px; vertical-align: middle; }
        tr:nth-child(even) td { background: #f7f7f7; }
        .r { text-align: right; }
        .mono { font-family: 'Courier New', monospace; }
        .text-green { color: #166534; font-weight: 700; }
        @media print { body { padding: 0; } }
      </style>
    </head><body>
      <div class="print-header">
        <h3>🔍 Search Details — Other Payment Details</h3>
        <p>Date Range: <?= htmlspecialchars($detailRangeLabel) ?><?= $searchQ !== '' ? ' | Search: ' . htmlspecialchars($searchQ) : '' ?><br>Exported: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp; Total: ${DETAIL_ALL_DATA.length} lines</p>
      </div>
      <table>${thead}${tbody}</table>
    </body></html>`);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

// -- OP Detail Modal JS --
function openOPModal(opid) {
    document.getElementById('opModalOPID').textContent = opid;
    document.getElementById('opModalSubtitle').textContent = '';
    document.getElementById('opModalLoading').style.display = 'block';
    document.getElementById('opModalTable').style.display    = 'none';
    document.getElementById('opModalEmpty').style.display    = 'none';
    document.getElementById('opModalTbody').innerHTML = '';
    document.getElementById('opModalTfoot').innerHTML = '';
    document.getElementById('opModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    fetch('?ajax=opd_detail&opid=' + encodeURIComponent(opid))
        .then(function(res) { return res.json(); })
        .then(function(rows) {
            document.getElementById('opModalLoading').style.display = 'none';
            if (!rows || rows.__error__) {
                document.getElementById('opModalEmpty').innerHTML = rows && rows.__error__
                    ? '<span style="color:#dc2626;font-size:.8rem;">SQL Error: ' + rows.__error__ + '</span>'
                    : 'No detail records found.';
                document.getElementById('opModalEmpty').style.display = 'block';
                return;
            }
            if (!rows.length) {
                document.getElementById('opModalEmpty').style.display = 'block';
                return;
            }

            document.getElementById('opModalSubtitle').textContent =
                '— ' + rows.length + ' line' + (rows.length === 1 ? '' : 's');

            var tAmt = 0, tAddLess = 0, tNet = 0, tbody = '';
            rows.forEach(function(r) {
                var amt = parseFloat(r.Amount || 0);
                var add = parseFloat(r.AddLess || 0);
                var net = parseFloat(r.NetAmount || 0);
                tAmt += amt; tAddLess += add; tNet += net;

                tbody += '<tr>'
                    + '<td>' + fmtDate(r.OP_Date) + '</td>'
                    + '<td>' + ((r.Department||'').toString().trim() || '—') + '</td>'
                    + '<td>' + (r.Branch || '—') + '</td>'
                    + '<td class="mono">' + (r.ReferenceNo || '—') + '</td>'
                    + '<td class="r" style="font-weight:700">' + peso(amt) + '</td>'
                    + '<td class="r" style="' + (add < 0 ? 'color:var(--od-red)' : 'color:var(--od-orange)') + '">' + peso(add) + '</td>'
                    + '<td class="r" style="color:var(--od-green);font-weight:700">' + peso(net) + '</td>'
                    + '<td>' + (r.SourceName || '—') + '</td>'
                    + '<td>' + (r.ShortEmployeeName || '—') + '</td>'
                    + '<td>' + (r.ShortRemarks || '—') + '</td>'
                    + '<td class="mono" style="font-size:.72rem;">' + fmtDateTime(r.DateTimeInput) + '</td>'
                    + '<td>' + (r.InputName || '—') + '</td>'
                    + '<td>' + remittedBadge(r.Remitted) + '</td>'
                    + '<td>' + statusBadge(r.Status) + '</td>'
                    + '</tr>';
            });

            document.getElementById('opModalTbody').innerHTML = tbody;
            document.getElementById('opModalTfoot').innerHTML =
                '<tr>'
                + '<td colspan="4" style="text-align:right;font-size:.72rem;color:var(--text-dim,#6b7280);padding:.5rem .75rem;">TOTAL (' + rows.length + ' line' + (rows.length===1?'':'s') + ')</td>'
                + '<td class="r" style="padding:.5rem .75rem;">' + peso(tAmt) + '</td>'
                + '<td class="r" style="padding:.5rem .75rem;">' + peso(tAddLess) + '</td>'
                + '<td class="r" style="color:var(--od-green);padding:.5rem .75rem;">' + peso(tNet) + '</td>'
                + '<td colspan="6"></td>'
                + '</tr>';

            document.getElementById('opModalTable').style.display = 'table';
        })
        .catch(function() {
            document.getElementById('opModalLoading').style.display = 'none';
            document.getElementById('opModalEmpty').style.display   = 'block';
        });
}

function closeOPModal() {
    document.getElementById('opModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('opModal').addEventListener('click', function(e) { if (e.target === this) closeOPModal(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeOPModal(); });
</script>

</body>
</html>