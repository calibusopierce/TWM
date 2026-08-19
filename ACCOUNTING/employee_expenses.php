<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'employee_expenses');
$isViewOnly = rbac_is_view_only('employee_expenses');

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

// ── Filters (GET, page reload) ─────────────────────────────

$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

$selDept  = isset($_GET['department']) ? trim($_GET['department']) : '';
$selArea  = isset($_GET['area'])       ? trim($_GET['area'])       : '';
$selType  = isset($_GET['exp_type'])   ? trim($_GET['exp_type'])   : '';
$search   = isset($_GET['search'])     ? trim($_GET['search'])     : '';


// ── Default date range ─────────────────────────────────────

$usingDefaultRange = false;

$hasAnyFilter =
    $dateFrom !== '' ||
    $dateTo   !== '' ||
    $selDept  !== '' ||
    $selArea  !== '' ||
    $selType  !== '' ||
    $search   !== '';

if (!$hasAnyFilter) {
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $dateTo   = date('Y-m-d');
    $usingDefaultRange = true;
}


// ── Build main WHERE clause ─────────────────────────────────

$where = [];
$params = [];


// DATE FROM
if ($dateFrom !== '') {
    $where[] = "CONVERT(date, Exp_date) >= CONVERT(date, ?, 23)";
    $params[] = $dateFrom;
}


// DATE TO
if ($dateTo !== '') {
    $where[] = "CONVERT(date, Exp_date) <= CONVERT(date, ?, 23)";
    $params[] = $dateTo;
}


// DEPARTMENT
if ($selDept !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Department, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selDept;
}


// AREA
if ($selArea !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Area, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selArea;
}


// EXPENSE TYPE
if ($selType !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Exp_type, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selType;
}


// SEARCH
if ($search !== '') {
    $where[] = "(
        Employee_name LIKE ?
        OR Note LIKE ?
    )";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
}


$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


// ── DEBUG-FRIENDLY MAIN QUERY ───────────────────────────────

$mainSql = "
    SELECT
        [Department],
        [Employee_name],
        [Position],
        [Note],
        [Exp_date],
        [Exp_type],
        [Exp_amount],
        [Area]
    FROM [dbo].[View_EmployeeExpenses]
    $whereSql
    ORDER BY Exp_date DESC
";


$stmt = sqlsrv_query($conn, $mainSql, $params);


if (!$stmt) {

    error_log(
        '[TWM Employee Expenses] SQL ERROR: ' .
        print_r(sqlsrv_errors(), true) .
        "\nSQL: " . $mainSql .
        "\nPARAMS: " . print_r($params, true)
    );

    $rows = [];

} else {

    $rows = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        foreach ($row as $key => $value) {

            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d');
            }
        }

        $rows[] = $row;
    }

    sqlsrv_free_stmt($stmt);
}


// ── Dropdown helper ─────────────────────────────────────────

function getExpenseFilterOptions(
    $conn,
    string $column,
    string $dateFrom,
    string $dateTo,
    string $department,
    string $area,
    string $expType,
    string $search
): array {

    $allowedColumns = [
        'Department',
        'Area',
        'Exp_type'
    ];

    if (!in_array($column, $allowedColumns, true)) {
        return [];
    }


    $conditions = [];
    $params = [];


    // Date filters always apply to dropdowns
    if ($dateFrom !== '') {
        $conditions[] = "CONVERT(date, Exp_date) >= CONVERT(date, ?, 23)";
        $params[] = $dateFrom;
    }

    if ($dateTo !== '') {
        $conditions[] = "CONVERT(date, Exp_date) <= CONVERT(date, ?, 23)";
        $params[] = $dateTo;
    }


    // Department dropdown ignores its own selected Department
    if ($column !== 'Department' && $department !== '') {

        $conditions[] =
            "UPPER(LTRIM(RTRIM(ISNULL(Department, '')))) = UPPER(LTRIM(RTRIM(?)))";

        $params[] = $department;
    }


    // Area dropdown ignores its own selected Area
    if ($column !== 'Area' && $area !== '') {

        $conditions[] =
            "UPPER(LTRIM(RTRIM(ISNULL(Area, '')))) = UPPER(LTRIM(RTRIM(?)))";

        $params[] = $area;
    }


    // Expense Type dropdown ignores its own selected Type
    if ($column !== 'Exp_type' && $expType !== '') {

        $conditions[] =
            "UPPER(LTRIM(RTRIM(ISNULL(Exp_type, '')))) = UPPER(LTRIM(RTRIM(?)))";

        $params[] = $expType;
    }


    // Search applies to all dropdowns
    if ($search !== '') {

        $conditions[] = "(
            Employee_name LIKE ?
            OR Note LIKE ?
        )";

        $searchValue = '%' . $search . '%';

        $params[] = $searchValue;
        $params[] = $searchValue;
    }


    // Remove NULL / blank values from the dropdown itself
    $conditions[] =
        "[$column] IS NOT NULL AND LTRIM(RTRIM([$column])) <> ''";


    $whereSql = 'WHERE ' . implode(' AND ', $conditions);


    $sql = "
        SELECT DISTINCT
            LTRIM(RTRIM([$column])) AS [$column]
        FROM [dbo].[View_EmployeeExpenses]
        $whereSql
        ORDER BY [$column]
    ";


    $stmt = sqlsrv_query($conn, $sql, $params);


    if (!$stmt) {

        error_log(
            '[TWM Employee Expenses] Dropdown SQL ERROR: ' .
            print_r(sqlsrv_errors(), true) .
            "\nColumn: " . $column .
            "\nSQL: " . $sql .
            "\nPARAMS: " . print_r($params, true)
        );

        return [];
    }


    $list = [];

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {

        $value = $row[$column] ?? '';

        if (trim((string)$value) !== '') {
            $list[] = trim((string)$value);
        }
    }


    sqlsrv_free_stmt($stmt);

    return $list;
}


// ── Department options ──────────────────────────────────────

$deptList = getExpenseFilterOptions(
    $conn,
    'Department',
    $dateFrom,
    $dateTo,
    $selDept,
    $selArea,
    $selType,
    $search
);


// ── Area options ────────────────────────────────────────────

$areaList = getExpenseFilterOptions(
    $conn,
    'Area',
    $dateFrom,
    $dateTo,
    $selDept,
    $selArea,
    $selType,
    $search
);


// ── Expense Type options ────────────────────────────────────

$typeList = getExpenseFilterOptions(
    $conn,
    'Exp_type',
    $dateFrom,
    $dateTo,
    $selDept,
    $selArea,
    $selType,
    $search
);


// ── Totals ──────────────────────────────────────────────────

$totalAmount = 0;

foreach ($rows as $r) {
    $totalAmount += (float)($r['Exp_amount'] ?? 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Expenses — Tradewell</title>
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

.pg-empty { text-align: center; padding: 2.5rem 1rem; color: var(--pg-text-muted); }

.pg-pagination { display: flex; align-items: center; justify-content: flex-end; gap: .4rem; padding: .85rem .25rem 0; flex-wrap: wrap; }
.pg-pagination .pg-page-info { font-size: .8rem; color: var(--pg-text-muted); margin-right: auto; }
.pg-pagination button {
    border: 1px solid var(--pg-border); background: #fff; color: #374151;
    border-radius: 6px; padding: .35rem .65rem; font-size: .8rem; font-weight: 600; cursor: pointer;
}
.pg-pagination button.active { background: var(--pg-accent); border-color: var(--pg-accent); color: #fff; }
.pg-pagination button:disabled { opacity: .4; cursor: not-allowed; }
</style>
</head>
<body>

<?php $topbar_page = 'employee_expenses'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="pg-content">

    <div class="page-header">
        <h1 class="page-title">Employee Expenses</h1>
        <p class="page-subtitle">
            View, print, and export employee expense records
            <?php if ($usingDefaultRange): ?>
                <span style="color:#2563eb;">— showing last 7 days by default (<?= htmlspecialchars($dateFrom) ?> to <?= htmlspecialchars($dateTo) ?>). Adjust the date filters below for a wider range.</span>
            <?php endif; ?>
        </p>
    </div>

    <div class="stat-strip">
        <div class="stat-cell">
            <div class="label">Records</div>
            <div class="value"><?= number_format(count($rows)) ?></div>
        </div>
        <div class="stat-cell accent">
            <div class="label">Total Amount</div>
            <div class="value">₱<?= number_format($totalAmount, 2) ?></div>
        </div>
    </div>

    <form class="pg-card" method="get" id="filterForm">
        <div class="pg-filter-grid">
            <div>
                <label>Exp. Date From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div>
                <label>Exp. Date To</label>
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
                <label>Exp. Type</label>
                <select name="exp_type">
                    <option value="">All</option>
                    <?php foreach ($typeList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $selType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="pg-search-actions-row">
            <div class="pg-search-field">
                <label>Search (Employee Name / Note)</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Type to search...">
            </div>
            <div class="pg-filter-actions">
                <button type="submit" class="pg-btn pg-btn-primary">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
                <a href="<?= base_url('ACCOUNTING/employee_expenses.php') ?>" class="pg-btn pg-btn-outline">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                </a>
                <a class="pg-btn pg-btn-excel" href="employee_expenses_export.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>">
                    <i class="bi bi-file-earmark-excel"></i> Download Excel
                </a>
                <button type="button" class="pg-btn pg-btn-outline" onclick="printExpensesReport()">
                    <i class="bi bi-printer"></i> Print Report
                </button>
            </div>
        </div>
    </form>

    <div class="pg-card">
        <div class="pg-table-wrap">
            <table class="pg-table" id="expensesTable">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Employee Name</th>
                        <th>Position</th>
                        <th>Note</th>
                        <th>Exp. Date</th>
                        <th>Exp. Type</th>
                        <th>Amount</th>
                        <th>Area</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="pg-empty">No records found for the selected filters.</td></tr>
                    <?php else: ?>
                       <?php foreach ($rows as $r): ?>
                    <tr data-expense-row="1">
                        <td><?= htmlspecialchars($r['Department'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['Employee_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['Position'] ?? '-') ?></td>
                        <td title="<?= htmlspecialchars($r['Note'] ?? '') ?>">
                            <?= htmlspecialchars(mb_strimwidth($r['Note'] ?? '-', 0, 30, '…')) ?>
                        </td>
                        <td><?= htmlspecialchars($r['Exp_date'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($r['Exp_type'] ?? '-') ?></td>
                        <td>₱<?= number_format((float)($r['Exp_amount'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars($r['Area'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="pg-pagination" id="expensesPagination"></div>
    </div>

</div>

<script>
// Filtered rows currently rendered server-side, used for the print report
const EXPENSE_ROWS = <?= json_encode(array_values($rows)) ?>;

const ROWS_PER_PAGE = 20;
let currentPage = 1;

function paginateTable() {
    const tbody = document.querySelector('#expensesTable tbody');

    // Only count actual expense rows.
    // The "No records found" row must NOT be counted.
    const rows = Array.from(
        tbody.querySelectorAll('tr[data-expense-row="1"]')
    );

    const totalRows = rows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / ROWS_PER_PAGE));

    if (currentPage > totalPages) {
        currentPage = totalPages;
    }

    // Hide/show actual records
    rows.forEach((tr, i) => {

        const page = Math.floor(i / ROWS_PER_PAGE) + 1;

        tr.style.display =
            (page === currentPage) ? '' : 'none';
    });


    const pager = document.getElementById('expensesPagination');

    if (!totalRows) {
        pager.innerHTML = '';
        return;
    }


    const start =
        (currentPage - 1) * ROWS_PER_PAGE + 1;

    const end =
        Math.min(currentPage * ROWS_PER_PAGE, totalRows);


    let html =
        `<span class="pg-page-info">
            Showing ${start}–${end} of ${totalRows}
        </span>`;


    html += `
        <button
            ${currentPage === 1 ? 'disabled' : ''}
            onclick="goToPage(${currentPage - 1})">
            Prev
        </button>
    `;


    for (let p = 1; p <= totalPages; p++) {

        html += `
            <button
                class="${p === currentPage ? 'active' : ''}"
                onclick="goToPage(${p})">
                ${p}
            </button>
        `;
    }


    html += `
        <button
            ${currentPage === totalPages ? 'disabled' : ''}
            onclick="goToPage(${currentPage + 1})">
            Next
        </button>
    `;


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

function printExpensesReport() {
    if (!EXPENSE_ROWS.length) return alert('No data to print.');

    let totalAmount = 0;
    let rowsHtml = '';
    EXPENSE_ROWS.forEach((r, i) => {
        totalAmount += parseFloat(r.Exp_amount) || 0;
        rowsHtml += `<tr style="background:${i % 2 === 0 ? '#fff' : '#f9fafb'}">
            <td>${r.Department ?? ''}</td><td>${r.Employee_name ?? ''}</td>
            <td>${r.Position ?? ''}</td><td>${r.Note ?? ''}</td>
            <td>${r.Exp_date ?? ''}</td><td>${r.Exp_type ?? ''}</td>
            <td style="text-align:right">${peso(r.Exp_amount)}</td>
            <td>${r.Area ?? ''}</td>
        </tr>`;
    });

    const headers = ['Department','Employee Name','Position','Note','Exp. Date','Exp. Type','Amount','Area'];
    const thead = '<thead><tr>' + headers.map(h => `<th style="padding:4px 7px;border:1px solid #ccc;background:#f3f4f6;white-space:nowrap">${h}</th>`).join('') + '</tr></thead>';

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head><title>Employee Expenses — Report</title>
    <style>
      body{font-family:Arial,sans-serif;font-size:10px;margin:14px;color:#111}
      h3{margin:0 0 4px;font-size:14px}
      p{margin:0 0 8px;color:#666;font-size:10px}
      table{width:100%;border-collapse:collapse}
      td{padding:3px 7px;border:1px solid #ddd;white-space:nowrap}
      tfoot td{font-weight:700;border-top:2px solid #2563eb}
      @media print{body{margin:0}}
    </style></head><body>
    <h3>Employee Expenses — Report</h3>
    <p>Exported: ${new Date().toLocaleString()} · ${EXPENSE_ROWS.length} records</p>
    <table>${thead}<tbody>${rowsHtml}</tbody>
    <tfoot><tr>
        <td colspan="6" style="text-align:right">TOTAL</td>
        <td style="text-align:right">${peso(totalAmount)}</td>
        <td></td>
    </tr></tfoot>
    </table></body></html>`);
    win.document.close(); win.focus(); win.print(); win.close();
}
</script>

</body>
</html>