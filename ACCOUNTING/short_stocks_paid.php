<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'short_stocks_paid');
$isViewOnly = rbac_is_view_only('short_stocks_paid');

// ── Helpers ───────────────────────────────────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        error_log('[TWM runQuery] SQL failed: ' . print_r(sqlsrv_errors(), true) . "\nQuery: " . $sql);
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
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

function esc(string $v): string {
    return str_replace("'", "''", $v);
}

// ── Filters (GET, page reload — same pattern as team_schedule.php) ──
$dateFromSet = isset($_GET['date_from']);
$dateToSet   = isset($_GET['date_to']);
$dateFrom    = $dateFromSet ? trim($_GET['date_from']) : '';
$dateTo      = $dateToSet   ? trim($_GET['date_to'])   : '';

// Default to current month on a fresh load only — keeps first paint light.
// If the user submits the form with dates cleared, isset() is still true
// so their explicit "no date filter" choice is respected.
if (!$dateFromSet && !$dateToSet) {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
}
$selDept    = isset($_GET['department'])? trim($_GET['department']): '';
$selArea    = isset($_GET['area'])      ? trim($_GET['area'])      : '';
$selOutlet  = isset($_GET['outlet'])    ? trim($_GET['outlet'])    : '';
$selType    = isset($_GET['type_short']) ? trim($_GET['type_short']) : '';
$selCat     = isset($_GET['category'])  ? trim($_GET['category'])  : '';
$selStatus  = isset($_GET['status'])    ? trim($_GET['status'])    : '';
$search     = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$viewMode   = isset($_GET['view'])      ? trim($_GET['view'])      : 'unpaid';

$where = [];
if ($viewMode !== 'all') $where[] = "Source IS NULL";
if ($dateFrom !== '') $where[] = "DatePaid >= '" . esc($dateFrom) . "'";
if ($dateTo   !== '') $where[] = "DatePaid <= '" . esc($dateTo)   . "'";
if ($selDept  !== '') $where[] = "Department = '" . esc($selDept) . "'";
if ($selArea  !== '') $where[] = "Area = '" . esc($selArea) . "'";
if ($selOutlet!== '') $where[] = "Outlet = '" . esc($selOutlet) . "'";
if ($selType  !== '') $where[] = "TypeShort = '" . esc($selType) . "'";
if ($selCat   !== '') $where[] = "Category = '" . esc($selCat) . "'";
if ($selStatus!== '') $where[] = "StatusofShort = '" . esc($selStatus) . "'";
if ($search   !== '') {
    $s = esc($search);
    $where[] = "(EmployeeName LIKE '%$s%' OR RefNo LIKE '%$s%' OR PlateNumber LIKE '%$s%')";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = runQuery($conn, "
    SELECT [SEID],[Position],[Status],[Amount],[SPPID],[AmountDue],[PaidAmount],
           [Balance],[DateGenerate],[SDID],[DID],[Department],[DateSchedule],
           [PlateNumber],[Area],[Outlet],[RefNo],[TotalAmount],[NumAccountable],
           [AmountL],[StatusofShort],[Remarks],[IDS],[EmployeeID],[EmployeeName],
           [DatePaid],[TypeShort],[Category],[Employee_Status],[Job_tittle],
           [Position_held],[PaymentID],[Source]
    FROM [dbo].[View_ShortPaymentPaidDetails]
    $whereSql
    ORDER BY DatePaid DESC
");

// ── Dropdown option lists ────────────────────────────────
$deptList   = lookupList($conn, "SELECT DISTINCT Department FROM [dbo].[View_ShortPaymentPaidDetails] WHERE Department IS NOT NULL AND Department <> '' ORDER BY Department");
$areaList   = lookupList($conn, "SELECT DISTINCT Area FROM [dbo].[View_ShortPaymentPaidDetails] WHERE Area IS NOT NULL AND Area <> '' ORDER BY Area");
$outletList = lookupList($conn, "SELECT DISTINCT Outlet FROM [dbo].[View_ShortPaymentPaidDetails] WHERE Outlet IS NOT NULL AND Outlet <> '' ORDER BY Outlet");
$typeList   = lookupList($conn, "SELECT DISTINCT TypeShort FROM [dbo].[View_ShortPaymentPaidDetails] WHERE TypeShort IS NOT NULL AND TypeShort <> '' ORDER BY TypeShort");
$catList    = lookupList($conn, "SELECT DISTINCT Category FROM [dbo].[View_ShortPaymentPaidDetails] WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category");
$statusList = lookupList($conn, "SELECT DISTINCT StatusofShort FROM [dbo].[View_ShortPaymentPaidDetails] WHERE StatusofShort IS NOT NULL AND StatusofShort <> '' ORDER BY StatusofShort");

// ── Totals for stat cards ────────────────────────────────
$totalPaid = 0; $totalBalance = 0;
foreach ($rows as $r) {
    $totalPaid    += (float)($r['PaidAmount'] ?? 0);
    $totalBalance += (float)($r['Balance'] ?? 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Short Stocks Paid — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/responsive-patch.css') ?>" rel="stylesheet">
<style>
:root {
    --pg-accent: #2563eb;
    --pg-accent-soft: #eaf0fe;
    --pg-border: #e2e8f0;
    --pg-bg: #f8fafc;
    --pg-text-muted: #64748b;
    --pg-radius: 14px;
}
body { font-family: 'IBM Plex Sans', sans-serif; background: var(--pg-bg); margin: 0; }
.pg-content { padding: 1.5rem; }

.page-header { margin-bottom: 1.25rem; }
.page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
.page-subtitle { color: var(--pg-text-muted); font-size: .9rem; margin-top: .15rem; }

.pg-card {
    background: #fff;
    border: 1px solid var(--pg-border);
    border-radius: var(--pg-radius);
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}

.stat-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .75rem; margin-bottom: 1.1rem; }
.stat-cell {
    background: #fff; border: 1px solid var(--pg-border); border-radius: var(--pg-radius);
    padding: 1rem 1.1rem .85rem; display: flex; flex-direction: column; gap: 4px;
}
.stat-cell .label { font-size: .74rem; color: var(--pg-text-muted); text-transform: uppercase; letter-spacing: .02em; }
.stat-cell .value { font-size: 1.3rem; font-weight: 700; color: #0f172a; font-family: 'IBM Plex Mono', monospace; }
.stat-cell.accent .value { color: var(--pg-accent); }

.pg-filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .75rem; align-items: end; }
.pg-filter-grid label { display: block; font-size: .78rem; font-weight: 600; color: var(--pg-text-muted); margin-bottom: .3rem; text-transform: uppercase; letter-spacing: .02em; }
.pg-filter-grid select { text-overflow: ellipsis; }
.pg-filter-grid select, .pg-filter-grid input {
    width: 100%; padding: .5rem .65rem; border: 1px solid var(--pg-border); border-radius: 8px;
    font-size: .85rem; font-family: 'IBM Plex Sans', sans-serif; background: #fff;
}
.pg-filter-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.pg-search-actions-row {
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: 1rem; margin-top: 1rem; flex-wrap: wrap;
}
.pg-search-field { flex: 1 1 260px; min-width: 220px; }
.pg-search-field label { display: block; font-size: .78rem; font-weight: 600; color: var(--pg-text-muted); margin-bottom: .3rem; text-transform: uppercase; letter-spacing: .02em; }
.pg-search-field input {
    width: 100%; padding: .5rem .65rem; border: 1px solid var(--pg-border);
    border-radius: 8px; font-size: .85rem; font-family: inherit;
}

.pg-btn { border: none; border-radius: 8px; padding: .55rem 1rem; font-size: .85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem; text-decoration: none; }
.pg-btn-primary { background: var(--pg-accent); color: #fff; }
.pg-btn-primary:hover { background: #1d4ed8; }
.pg-btn-outline { background: #fff; border: 1px solid var(--pg-border); color: #374151; }
.pg-btn-outline:hover { background: #f3f4f6; }
.pg-btn-excel { background: #fff; border: 1px solid #bbf7d0; color: #15803d; }
.pg-btn-excel:hover { background: #f0fdf4; }

.pg-table-wrap { overflow-x: auto; }
table.pg-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
table.pg-table thead th {
    background: var(--pg-accent-soft); color: #1e3a8a; text-align: left;
    padding: .65rem .75rem; font-weight: 600; white-space: nowrap;
}
table.pg-table tbody td { padding: .6rem .75rem; border-bottom: 1px solid var(--pg-border); white-space: nowrap; }
table.pg-table tbody tr:nth-child(even) { background: #f9fafb; }
table.pg-table tbody tr:hover { background: #f1f5f9; }

.pg-badge { display: inline-block; padding: .2rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 600; }
.pg-badge-paid { background: #dcfce7; color: #166534; }
.pg-badge-default { background: #e5e7eb; color: #374151; }

.pg-empty { text-align: center; padding: 2.5rem 1rem; color: var(--pg-text-muted); }

.pg-pagination { display: flex; align-items: center; justify-content: flex-end; gap: .4rem; padding: .85rem .25rem 0; flex-wrap: wrap; }
.pg-pagination .pg-page-info { font-size: .8rem; color: var(--pg-text-muted); margin-right: auto; }
.pg-pagination button {
    border: 1px solid var(--pg-border); background: #fff; color: #374151;
    border-radius: 6px; padding: .35rem .65rem; font-size: .8rem; font-weight: 600; cursor: pointer;
}
.pg-pagination button.active { background: var(--pg-accent); border-color: var(--pg-accent); color: #fff; }
.pg-pagination button:disabled { opacity: .4; cursor: not-allowed; }
.pg-view-link { color: var(--pg-accent); text-decoration: none; font-weight: 600; }
.pg-view-link:hover { text-decoration: underline; }
</style>
</head>
<body>

<?php $topbar_page = 'short_stocks_paid'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="pg-content">

    <div class="page-header">
        <h1 class="page-title">Short Stocks Paid</h1>
        <p class="page-subtitle">Track and review all paid short stock records</p>
    </div>

    <div class="stat-strip">
        <div class="stat-cell">
            <div class="label">Records</div>
            <div class="value"><?= number_format(count($rows)) ?></div>
        </div>
        <div class="stat-cell accent">
            <div class="label">Total Paid Amount</div>
            <div class="value">₱<?= number_format($totalPaid, 2) ?></div>
        </div>
        <div class="stat-cell">
            <div class="label">Total Balance</div>
            <div class="value">₱<?= number_format($totalBalance, 2) ?></div>
        </div>
    </div>

    <form class="pg-card" method="get" id="filterForm">
        <div class="pg-filter-grid">
            <div>
                <label>Date Paid From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div>
                <label>Date Paid To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div>
                <label>Department</label>
                <select name="department">
                    <option value="">All</option>
                    <?php foreach ($deptList as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $selDept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Area</label>
                <select name="area">
                    <option value="">All</option>
                    <?php foreach ($areaList as $a): ?>
                        <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Outlet</label>
                <select name="outlet">
                    <option value="">All</option>
                    <?php foreach ($outletList as $o): ?>
                        <option value="<?= htmlspecialchars($o) ?>" <?= $selOutlet === $o ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Type Short</label>
                <select name="type_short">
                    <option value="">All</option>
                    <?php foreach ($typeList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $selType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Category</label>
                <select name="category">
                    <option value="">All</option>
                    <?php foreach ($catList as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $selCat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <?php foreach ($statusList as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $selStatus === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
             <div>
                <label>View</label>
                <select name="view" title="Not Yet Paid = records where Source is blank">
                <option value="unpaid" <?= $viewMode !== 'all' ? 'selected' : '' ?>>Not Yet Paid</option>
                <option value="all" <?= $viewMode === 'all' ? 'selected' : '' ?>>All Records</option>
            </select>
            </div>
            </div>

        <div class="pg-search-actions-row">
            <div class="pg-search-field">
                <label>Search (Employee Name / RefNo / Plate No.)</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Type to search...">
            </div>
            <div class="pg-filter-actions">
                <button type="submit" class="pg-btn pg-btn-primary">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
                <a href="<?= base_url('ACCOUNTING/short_stocks_paid.php') ?>" class="pg-btn pg-btn-outline">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
                <a class="pg-btn pg-btn-excel" href="short_stocks_paid_export.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>">
                    <i class="bi bi-file-earmark-excel"></i> Download Excel
                </a>
                <button type="button" class="pg-btn pg-btn-outline" onclick="printPaidReport()">
                    <i class="bi bi-printer"></i> Print Report
                </button>
            </div>
        </div>
    </form>

    <div class="pg-card">
        <div class="pg-table-wrap">
            <table class="pg-table" id="paidTable">
                <thead>
                    <tr>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th>Area</th>
                        <th>Outlet</th>
                        <th>Plate No.</th>
                        <th>Ref No.</th>
                        <th>Date Generated</th>
                        <th>Total Amount</th>
                        <th>Amount Due</th>
                        <th>Paid Amount</th>
                        <th>Balance</th>
                        <th>Date Paid</th>
                        <th>Type Short</th>
                        <th>Remarks</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="15" class="pg-empty">No records found for the selected filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <?php
                            $statusVal = $r['StatusofShort'] ?? '';
                            $badgeClass = stripos($statusVal, 'paid') !== false ? 'pg-badge-paid' : 'pg-badge-default';
                        ?>
                       <tr>
                            <td><?= htmlspecialchars($r['EmployeeName'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['Department'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['Area'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['Outlet'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['PlateNumber'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['RefNo'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['DateGenerate'] ?? '-') ?></td>
                            <td>₱<?= number_format((float)($r['TotalAmount'] ?? 0), 2) ?></td>
                            <td>₱<?= number_format((float)($r['AmountDue'] ?? 0), 2) ?></td>
                            <td>₱<?= number_format((float)($r['PaidAmount'] ?? 0), 2) ?></td>
                            <td>₱<?= number_format((float)($r['Balance'] ?? 0), 2) ?></td>
                            <td><?= htmlspecialchars($r['DatePaid'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['TypeShort'] ?? '-') ?></td>
                            <td title="<?= htmlspecialchars($r['Remarks'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($r['Remarks'] ?? '-', 0, 30, '…')) ?></td>
                            <td><a class="pg-view-link" href="<?= base_url('ACCOUNTING/short_stocks_paid_view.php') ?>?spp_id=<?= urlencode($r['SPPID'] ?? '') ?>">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pg-pagination" id="paidPagination"></div>
    </div>

</div>

<script>
// Filtered rows currently rendered server-side, used for the print report
const PAID_ROWS = <?= json_encode(array_values($rows)) ?>;

const ROWS_PER_PAGE = 20;
let currentPage = 1;

function paginateTable() {
    const tbody = document.querySelector('#paidTable tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const totalRows = rows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / ROWS_PER_PAGE));
    if (currentPage > totalPages) currentPage = totalPages;

    rows.forEach((tr, i) => {
        const page = Math.floor(i / ROWS_PER_PAGE) + 1;
        tr.style.display = (page === currentPage) ? '' : 'none';
    });

    const pager = document.getElementById('paidPagination');
    if (!totalRows) { pager.innerHTML = ''; return; }

    const start = (currentPage - 1) * ROWS_PER_PAGE + 1;
    const end = Math.min(currentPage * ROWS_PER_PAGE, totalRows);
    let html = `<span class="pg-page-info">Showing ${start}–${end} of ${totalRows}</span>`;
    html += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})">Prev</button>`;
    for (let p = 1; p <= totalPages; p++) {
        html += `<button class="${p === currentPage ? 'active' : ''}" onclick="goToPage(${p})">${p}</button>`;
    }
    html += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})">Next</button>`;
    pager.innerHTML = html;
}

function goToPage(p) {
    currentPage = p;
    paginateTable();
}

document.addEventListener('DOMContentLoaded', paginateTable);

function peso(n) {
    n = parseFloat(n) || 0;
    return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function printPaidReport() {
    if (!PAID_ROWS.length) return alert('No data to print.');

     let totalTotal = 0, totalDue = 0, totalPaid = 0, totalBalance = 0;
    let rowsHtml = '';
    PAID_ROWS.forEach((r, i) => {
        totalTotal   += parseFloat(r.TotalAmount) || 0;
        totalDue     += parseFloat(r.AmountDue) || 0;
        totalPaid    += parseFloat(r.PaidAmount) || 0;
        totalBalance += parseFloat(r.Balance) || 0;
        rowsHtml += `<tr style="background:${i % 2 === 0 ? '#fff' : '#f9fafb'}">
            <td>${r.EmployeeName ?? ''}</td><td>${r.Department ?? ''}</td>
            <td>${r.Area ?? ''}</td><td>${r.Outlet ?? ''}</td><td>${r.PlateNumber ?? ''}</td>
            <td>${r.RefNo ?? ''}</td><td>${r.DateGenerate ?? ''}</td>
            <td style="text-align:right">${peso(r.TotalAmount)}</td>
            <td style="text-align:right">${peso(r.AmountDue)}</td>
            <td style="text-align:right">${peso(r.PaidAmount)}</td>
            <td style="text-align:right">${peso(r.Balance)}</td>
            <td>${r.DatePaid ?? ''}</td><td>${r.TypeShort ?? ''}</td>
            <td>${r.Remarks ?? ''}</td>
        </tr>`;
    });

    const headers = ['Employee Name','Department','Area','Outlet','Plate No.','Ref No.','Date Generated','Total Amount','Amount Due','Paid Amount','Balance','Date Paid','Type Short','Remarks'];
    const thead = '<thead><tr>' + headers.map(h => `<th style="padding:4px 7px;border:1px solid #ccc;background:#f3f4f6;white-space:nowrap">${h}</th>`).join('') + '</tr></thead>';

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head><title>Short Stocks Paid — Report</title>
    <style>
      body{font-family:Arial,sans-serif;font-size:10px;margin:14px;color:#111}
      h3{margin:0 0 4px;font-size:14px}
      p{margin:0 0 8px;color:#666;font-size:10px}
      table{width:100%;border-collapse:collapse}
      td{padding:3px 7px;border:1px solid #ddd;white-space:nowrap}
      tfoot td{font-weight:700;border-top:2px solid #2563eb}
      @media print{body{margin:0}}
    </style></head><body>
    <h3>Short Stocks Paid — Report</h3>
    <p>Exported: ${new Date().toLocaleString()} · ${PAID_ROWS.length} records</p>
    <table>${thead}<tbody>${rowsHtml}</tbody>
    <tfoot><tr>
        <td colspan="7" style="text-align:right">TOTALS</td>
        <td style="text-align:right">${peso(totalTotal)}</td>
        <td style="text-align:right">${peso(totalDue)}</td>
        <td style="text-align:right">${peso(totalPaid)}</td>
        <td style="text-align:right">${peso(totalBalance)}</td>
        <td colspan="3"></td>
    </tr></tfoot>
    </table></body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}
</script>

</body>
</html>