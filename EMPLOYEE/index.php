<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$isViewOnly  = rbac_is_view_only('employee_loans');
$isAdmin     = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);

// ── Filters ───────────────────────────────────────────────────
$filter_search     = isset($_GET['search'])     ? trim($_GET['search'])     : '';
$filter_type       = isset($_GET['loan_type'])  ? (int)$_GET['loan_type']  : 0;
$filter_status     = isset($_GET['status'])     ? trim($_GET['status'])     : '';
$filter_department = isset($_GET['department']) ? trim($_GET['department']) : '';
$filter_branch     = isset($_GET['branch'])     ? trim($_GET['branch'])     : '';

$where  = "WHERE 1=1";
$params = [];

if ($filter_search !== '') {
    $where   .= " AND (l.ReferenceNumber LIKE ? OR e.LastName LIKE ? OR e.FirstName LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}
if ($filter_type > 0) {
    $where   .= " AND l.LoanType = ?";
    $params[] = $filter_type;
}
if ($filter_status !== '') {
    $where   .= " AND l.Status = ?";
    $params[] = $filter_status;
}
if ($filter_department !== '') {
    $where   .= " AND e.Department = ?";
    $params[] = $filter_department;
}
if ($filter_branch !== '') {
    $where   .= " AND e.Branch = ?";
    $params[] = $filter_branch;
}

$sql = "
    SELECT
        l.LoanID, l.ReferenceNumber, l.LoanDate,
        l.LoanAmount, l.PaidAmount, l.BalanceAmount,
        l.Terms, l.TermsAmount, l.CutOff, l.CutOff_Amount, l.Status,
        t.TypeName AS LoanTypeName,
        e.LastName, e.FirstName, e.MiddleName,
        e.Department, e.Branch, e.Position_held, e.EmployeeID
    FROM TBL_Loan l
    LEFT JOIN TBL_Loan_Type      t ON t.ID         = l.LoanType
    LEFT JOIN TBL_HREmployeeList e ON e.EmployeeID = l.EmployeeID
    $where
    ORDER BY l.LoanID DESC
";
$stmt      = sqlsrv_query($conn, $sql, $params);
$query_error = '';
if ($stmt === false) {
    $errs = sqlsrv_errors() ?: [];
    foreach ($errs as $e) $query_error .= '[' . $e['code'] . '] ' . $e['message'] . ' ';
    $query_error = trim($query_error) ?: 'Unknown query error on main loan list.';
}

// ── Stat counts ───────────────────────────────────────────────
$counts = ['total'=>0,'Proposal'=>0,'Approved'=>0,'Fully Paid'=>0,'Cancelled'=>0];
$balance_total = 0;
$count_res = sqlsrv_query($conn, "
    SELECT Status, COUNT(*) AS cnt, SUM(BalanceAmount) AS bal
    FROM TBL_Loan
    GROUP BY Status
");
if ($count_res !== false) {
    while ($r = sqlsrv_fetch_array($count_res, SQLSRV_FETCH_ASSOC)) {
        $s = $r['Status'] ?? '';
        if (array_key_exists($s, $counts)) $counts[$s] = (int)$r['cnt'];
        $counts['total'] += (int)$r['cnt'];
    }
}

// Outstanding balance — sum BalanceAmount for all non-closed statuses
$bal_res = sqlsrv_query($conn, "
    SELECT SUM(BalanceAmount) AS total_bal
    FROM TBL_Loan
    WHERE Status NOT IN ('Fully Paid', 'Cancelled')
      AND BalanceAmount IS NOT NULL
      AND BalanceAmount > 0
");
if ($bal_res !== false) {
    $bal_row = sqlsrv_fetch_array($bal_res, SQLSRV_FETCH_ASSOC);
    $balance_total = (float)($bal_row['total_bal'] ?? 0);
}

// ── Loan types for filter dropdown ───────────────────────────
$loan_types = [];
$types_q    = sqlsrv_query($conn, "SELECT ID, TypeName FROM TBL_Loan_Type ORDER BY TypeName");
if ($types_q !== false) while ($r = sqlsrv_fetch_array($types_q, SQLSRV_FETCH_ASSOC)) $loan_types[] = $r;

$departments = [
    'Urban Tradewell Corp.',
    'Monde',
    'Century',
    'Nutriasia',
    'Silver Swan',
    'Multilines',
];

$branches = [];
$branch_q = sqlsrv_query($conn, "SELECT DISTINCT Branch FROM TBL_HREmployeeList WHERE Branch IS NOT NULL AND Branch <> '' ORDER BY Branch");
if ($branch_q !== false) while ($r = sqlsrv_fetch_array($branch_q, SQLSRV_FETCH_ASSOC)) $branches[] = $r['Branch'];

// ── Collect rows ─────────────────────────────────────────────
$rows_data = [];
if ($stmt !== false) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows_data[] = $r;
}
$rowCount = count($rows_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee Loans · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Stat cards ───────────────────────────────────────── */
    .stat-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .stat-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-lg); padding: 1rem 1.2rem;
      display: flex; align-items: center; gap: .85rem;
      box-shadow: var(--shadow-sm);
    }
    .stat-icon {
      width: 40px; height: 40px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.15rem; flex-shrink: 0;
    }
    .stat-val { font-size: 1.5rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .stat-val.sm { font-size: 1.1rem; }
    .stat-lbl { font-size: .72rem; color: var(--text-muted); margin-top: .15rem;
                font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .si-total    { background: rgba(59,130,246,.12);  color: #3b82f6; }
    .si-proposal { background: rgba(245,158,11,.12);  color: #f59e0b; }
    .si-approved { background: rgba(99,102,241,.12);  color: #6366f1; }
    .si-active   { background: rgba(16,185,129,.12);  color: #10b981; }
    .si-paid     { background: rgba(20,184,166,.12);  color: #0d9488; }
    .si-cancel   { background: rgba(239,68,68,.12);   color: #ef4444; }

    /* ── Filter bar ──────────────────────────────────────── */
    .filter-bar { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; }
    .filter-bar input, .filter-bar select {
      padding: .42rem .75rem; border: 1px solid var(--border);
      border-radius: var(--radius); font-size: .84rem;
      background: var(--surface); color: var(--text-main); min-width: 150px;
    }
    .filter-bar input:focus, .filter-bar select:focus { outline: none; border-color: var(--primary); }

    /* ── Loans table ─────────────────────────────────────── */
    .loans-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
    .loans-table thead tr { background: var(--surface-alt,#f8fafc); border-bottom: 2px solid var(--border); }
    .loans-table th {
      padding: .7rem 1rem; text-align: left; font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
      white-space: nowrap; border-bottom: 2px solid var(--border);
    }
    .loans-table td {
      padding: .75rem 1rem; border-bottom: 1px solid var(--border);
      vertical-align: middle; color: var(--text-main);
    }
    .loans-table tbody tr { transition: background .1s; }
    .loans-table tbody tr:hover { background: rgba(59,130,246,.04); }
    .loans-table tbody tr:nth-child(even) { background: var(--surface-alt,#f8fafc); }
    .loans-table tbody tr:nth-child(even):hover { background: rgba(59,130,246,.06); }

    .ref-number  { font-weight: 700; color: var(--primary); font-size: .87rem; font-family: 'JetBrains Mono', monospace; }
    .emp-name    { font-weight: 600; font-size: .87rem; line-height: 1.3; }
    .emp-sub     { font-size: .73rem; color: var(--text-muted); margin-top: .15rem; }
    .amount-cell { text-align: right; font-weight: 700; font-size: .87rem; font-family: 'JetBrains Mono', monospace; }
    .dept-cell   { font-size: .82rem; color: var(--text-main); white-space: nowrap; }
    .branch-pill {
      display: inline-block; font-size: .72rem; font-weight: 600;
      background: rgba(16,185,129,.1); color: #065f46;
      padding: .15rem .55rem; border-radius: 999px; white-space: nowrap;
    }

    /* ── Status badges ───────────────────────────────────── */
    .badge-status {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .22rem .65rem; border-radius: 999px;
      font-size: .72rem; font-weight: 700; letter-spacing: .03em; white-space: nowrap;
    }
    .badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
    .bs-proposal   { background: rgba(245,158,11,.12); color: #b45309; }
    .bs-proposal::before   { background: #f59e0b; }
    .bs-approved   { background: rgba(99,102,241,.12); color: #4338ca; }
    .bs-approved::before   { background: #6366f1; }
    .bs-active     { background: rgba(16,185,129,.12); color: #065f46; }
    .bs-active::before     { background: #10b981; }
    .bs-fully-paid { background: rgba(20,184,166,.12); color: #134e4a; }
    .bs-fully-paid::before { background: #0d9488; }
    .bs-cancelled  { background: rgba(239,68,68,.12);  color: #991b1b; }
    .bs-cancelled::before  { background: #ef4444; }

    /* ── Action buttons ──────────────────────────────────── */
    .action-wrap { display: flex; gap: .35rem; align-items: center; justify-content: center; }
    .btn-icon {
      width: 30px; height: 30px; border-radius: 7px; border: 1px solid var(--border);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .82rem; cursor: pointer; text-decoration: none;
      transition: background .15s, border-color .15s;
      background: var(--surface); color: var(--text-muted);
    }
    .btn-icon.view  { color: #3b82f6; border-color: rgba(59,130,246,.3);  background: rgba(59,130,246,.07); }
    .btn-icon.pay   { color: #10b981; border-color: rgba(16,185,129,.3);  background: rgba(16,185,129,.07); }
    .btn-icon.print { color: #6366f1; border-color: rgba(99,102,241,.3);  background: rgba(99,102,241,.07); }
    .btn-icon.del   { color: #ef4444; border-color: rgba(239,68,68,.3);   background: rgba(239,68,68,.07); }
    .btn-icon.view:hover  { background: rgba(59,130,246,.18); }
    .btn-icon.pay:hover   { background: rgba(16,185,129,.18); }
    .btn-icon.print:hover { background: rgba(99,102,241,.18); }
    .btn-icon.del:hover   { background: rgba(239,68,68,.18); }

    .type-chip {
      font-size: .74rem; background: rgba(99,102,241,.1); color: #4f46e5;
      padding: .18rem .6rem; border-radius: 999px; font-weight: 600;
    }
    .empty-row td { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }

    /* ── Progress bar for paid amount ───────────────────── */
    .progress-wrap { margin-top: .25rem; }
    .progress-bar-loan {
      height: 4px; border-radius: 999px; background: var(--border);
      overflow: hidden; width: 100px;
    }
    .progress-bar-loan-fill { height: 100%; border-radius: 999px; background: #10b981; }

    /* ── Delete modal ───────────────────────────────────── */
    .del-modal-backdrop {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.45); z-index: 1050;
      align-items: center; justify-content: center;
    }
    .del-modal-backdrop.show { display: flex; }
    .del-modal {
      background: var(--surface); border-radius: var(--radius-lg);
      padding: 1.75rem 2rem; max-width: 420px; width: 90%;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
    }
    .del-modal h5 { font-size: 1rem; font-weight: 800; margin-bottom: .35rem; color: #ef4444; }
    .del-modal p  { font-size: .86rem; color: var(--text-muted); margin-bottom: 1.1rem; }
    .del-modal .ref-tag {
      font-weight: 700; color: var(--text-main);
      background: var(--surface-alt,#f8fafc);
      border: 1px solid var(--border); border-radius: var(--radius);
      padding: .25rem .6rem; font-size: .85rem; display: inline-block; margin-bottom: .85rem;
    }
    .del-modal label {
      font-size: .8rem; font-weight: 700; color: var(--text-muted);
      text-transform: uppercase; letter-spacing: .04em;
      display: block; margin-bottom: .3rem;
    }
    .del-modal input {
      width: 100%; padding: .45rem .75rem; border: 1px solid var(--border);
      border-radius: var(--radius); font-size: .88rem; margin-bottom: .85rem;
      background: var(--surface); color: var(--text-main);
    }
    .del-modal input:focus { outline: none; border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.12); }
    .del-modal-actions { display: flex; gap: .6rem; justify-content: flex-end; }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">Employee Loans</div>
      <div class="page-subtitle">Manage and track employee loan records and payment schedules</div>
    </div>
    <div style="display:flex; gap:.6rem;">
      <?php if (!$isViewOnly): ?>
      <a href="<?= base_url('EMPLOYEE/categories.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-tags-fill"></i> Loan Types
      </a>
      <a href="<?= base_url('EMPLOYEE/create.php') ?>" class="btn btn-add">
        <i class="bi bi-plus-lg"></i> New Loan
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stat Cards -->
  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Loan record deleted successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif (isset($_GET['error'])): ?>
    <?php
      $err_msg = match($_GET['error']) {
          'has_payments' => 'Cannot delete — this loan already has recorded payments. Remove payments first.',
          'not_found'    => 'Loan record not found.',
          default        => 'An error occurred.',
      };
    ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($err_msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if (!empty($query_error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong>Query error:</strong> <?= htmlspecialchars($query_error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-icon si-total"><i class="bi bi-person-lines-fill"></i></div>
      <div>
        <div class="stat-val"><?= $counts['total'] ?></div>
        <div class="stat-lbl">Total Loans</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-proposal"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-val"><?= $counts['Proposal'] ?></div>
        <div class="stat-lbl">Proposal</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-approved"><i class="bi bi-check2-circle"></i></div>
      <div>
        <div class="stat-val"><?= $counts['Approved'] ?></div>
        <div class="stat-lbl">Approved</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-paid"><i class="bi bi-patch-check-fill"></i></div>
      <div>
        <div class="stat-val"><?= $counts['Fully Paid'] ?></div>
        <div class="stat-lbl">Fully Paid</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-balance" style="background:rgba(239,68,68,.12);color:#ef4444;"><i class="bi bi-cash-stack"></i></div>
      <div>
        <div class="stat-val sm">₱ <?= number_format($balance_total, 0) ?></div>
        <div class="stat-lbl">Outstanding Balance</div>
      </div>
    </div>
  </div>

  <!-- Table Card -->
  <div class="table-card">
   <!-- Top bar: title + export buttons -->
    <div class="table-card-header" style="border-bottom:0;padding-bottom:.5rem;">
      <div class="table-card-title">
        <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
        All Loan Records
        <span class="count-chip" id="rowCount"><?= $rowCount ?> record<?= $rowCount !== 1 ? 's' : '' ?></span>
      </div>
      <div style="display:flex;gap:.45rem;margin-left:auto;">
        <button onclick="exportCSV()" class="btn btn-secondary-custom" style="padding:.38rem .85rem;font-size:.82rem;">
          <i class="bi bi-filetype-csv"></i> CSV
        </button>
        <button onclick="exportExcel()" class="btn btn-secondary-custom" style="padding:.38rem .85rem;font-size:.82rem;">
          <i class="bi bi-file-earmark-excel"></i> Excel
        </button>
        <button onclick="printReport()" class="btn btn-secondary-custom" style="padding:.38rem .85rem;font-size:.82rem;">
          <i class="bi bi-printer-fill"></i> Print
        </button>
      </div>
    </div>

    <!-- Filter bar: full width below title row -->
    <div style="padding:.65rem 1.25rem 1rem;border-bottom:1px solid var(--border);background:var(--surface-alt,#f8fafc);">
      <form method="GET">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:.6rem;align-items:flex-end;">
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Search</div>
            <input type="text" name="search" placeholder="Ref No. / Employee…"
                   value="<?= htmlspecialchars($filter_search) ?>"
                   style="width:100%;padding:.42rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem;background:var(--surface);color:var(--text-main);">
          </div>
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Loan Type</div>
            <select name="loan_type" style="width:100%;padding:.42rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem;background:var(--surface);color:var(--text-main);">
              <option value="0">All Types</option>
              <?php foreach ($loan_types as $lt): ?>
                <option value="<?= $lt['ID'] ?>" <?= $filter_type == $lt['ID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lt['TypeName']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Department</div>
            <select name="department" style="width:100%;padding:.42rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem;background:var(--surface);color:var(--text-main);">
              <option value="">All Departments</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>" <?= $filter_department === $dept ? 'selected' : '' ?>>
                  <?= htmlspecialchars($dept) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Branch</div>
            <select name="branch" style="width:100%;padding:.42rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem;background:var(--surface);color:var(--text-main);">
              <option value="">All Branches</option>
              <?php foreach ($branches as $branch): ?>
                <option value="<?= htmlspecialchars($branch) ?>" <?= $filter_branch === $branch ? 'selected' : '' ?>>
                  <?= htmlspecialchars($branch) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Status</div>
            <select name="status" style="width:100%;padding:.42rem .75rem;border:1px solid var(--border);border-radius:var(--radius);font-size:.84rem;background:var(--surface);color:var(--text-main);">
              <option value="">All Status</option>
              <option value="Proposal"   <?= $filter_status === 'Proposal'   ? 'selected' : '' ?>>Proposal</option>
              <option value="Approved"   <?= $filter_status === 'Approved'   ? 'selected' : '' ?>>Approved</option>
              <option value="Fully Paid" <?= $filter_status === 'Fully Paid' ? 'selected' : '' ?>>Fully Paid</option>
              <option value="Cancelled"  <?= $filter_status === 'Cancelled'  ? 'selected' : '' ?>>Cancelled</option>
            </select>
          </div>
          <div style="display:flex;gap:.4rem;padding-top:1.35rem;">
            <button type="submit" class="btn btn-add" style="padding:.42rem .9rem;font-size:.84rem;white-space:nowrap;">
              <i class="bi bi-funnel-fill"></i> Filter
            </button>
            <?php if ($filter_search || $filter_type || $filter_status || $filter_department || $filter_branch): ?>
              <a href="<?= base_url('EMPLOYEE/index.php') ?>" class="btn btn-secondary-custom"
                 style="padding:.42rem .9rem;font-size:.84rem;white-space:nowrap;">
                <i class="bi bi-x-lg"></i> Reset
              </a>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>

    <div class="table-responsive">
      <table class="loans-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>Ref No.</th>
            <th>Department</th>
            <th>Branch</th>
            <th>Employee</th>
            <th>Loan Type</th>
            <th>Date</th>
            <th style="text-align:right;">Loan Amount</th>
            <th style="text-align:center;">Terms</th>
            <th style="text-align:right;">Monthly Amt</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:center; width:130px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows_data)): ?>
            <tr class="empty-row">
              <td colspan="12">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No loan records found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows_data as $i => $row):
              $row['Status'] = trim($row['Status'] ?? ''); // normalize char-field padding
              $date_str   = ($row['LoanDate'] instanceof DateTime)
                  ? $row['LoanDate']->format('M d, Y') : ($row['LoanDate'] ?? '—');
              $status_key = strtolower(str_replace(' ', '-', $row['Status']));
              $loan_amt   = (float)($row['LoanAmount']  ?? 0);
              $terms_amt  = (float)($row['CutOff_Amount'] ?? $row['TermsAmount'] ?? 0);
              $full_name  = trim(($row['LastName'] ?? '') . ', ' . ($row['FirstName'] ?? '') . ' ' . ($row['MiddleName'] ?? ''));
            ?>
            <tr>
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i + 1 ?></td>
              <td>
                <a href="<?= base_url('EMPLOYEE/view.php?id=' . $row['LoanID']) ?>"
                   style="text-decoration:none;">
                  <span class="ref-number"><?= htmlspecialchars($row['ReferenceNumber'] ?? '—') ?></span>
                </a>
              </td>
              <td class="dept-cell"><?= htmlspecialchars($row['Department'] ?? '—') ?></td>
              <td><span class="branch-pill"><?= htmlspecialchars($row['Branch'] ?? '—') ?></span></td>
              <td>
                <div class="emp-name"><?= htmlspecialchars($full_name) ?></div>
                <?php if (!empty($row['Position_held'])): ?>
                  <div class="emp-sub"><?= htmlspecialchars($row['Position_held']) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="type-chip"><?= htmlspecialchars($row['LoanTypeName'] ?? '—') ?></span></td>
              <td style="color:var(--text-muted);font-size:.83rem;"><?= htmlspecialchars($date_str) ?></td>
              <td class="amount-cell">₱ <?= number_format($loan_amt, 2) ?></td>
              <td style="text-align:center;font-size:.84rem;color:var(--text-muted);">
                <?= (int)($row['Terms'] ?? 0) ?> mo.
              </td>
              <td class="amount-cell">₱ <?= number_format($row['TermsAmount'], 2) ?></td>
              <td style="text-align:center;">
                <span class="badge-status bs-<?= $status_key ?>">
                  <?= htmlspecialchars($row['Status'] ?? '—') ?>
                </span>
              </td>
              <td>
                <div class="action-wrap">
                  <a href="<?= base_url('EMPLOYEE/view.php?id=' . $row['LoanID']) ?>"
                     class="btn-icon view" title="View / Edit"><i class="bi bi-eye-fill"></i></a>
                  <?php if (!$isViewOnly && $row['Status'] === 'Proposal'): ?>
                  <button type="button" class="btn-icon approve" title="Approve"
                          onclick="openApproveModal(<?= $row['LoanID'] ?>, '<?= htmlspecialchars($row['ReferenceNumber'] ?? '', ENT_QUOTES) ?>')">
                    <i class="bi bi-check-circle-fill"></i>
                  </button>
                  <?php endif; ?>
                  <?php if (!$isViewOnly && $row['Status'] === 'Approved'): ?>
                  <a href="<?= base_url('EMPLOYEE/payments.php?id=' . $row['LoanID']) ?>"
                     class="btn-icon pay" title="Payments"><i class="bi bi-cash-coin"></i></a>
                  <?php endif; ?>
                  <a href="<?= base_url('EMPLOYEE/print.php?id=' . $row['LoanID']) ?>"
                     class="btn-icon print" title="Print SOA" target="_blank">
                     <i class="bi bi-printer-fill"></i></a>
                  <?php if ($isAdmin && $row['Status'] === 'Proposal'): ?>
                  <button type="button" class="btn-icon del" title="Delete"
                          onclick="openDelModal(<?= $row['LoanID'] ?>, '<?= htmlspecialchars($row['ReferenceNumber'] ?? '', ENT_QUOTES) ?>')">
                    <i class="bi bi-trash-fill"></i>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>

<!-- Approve confirmation modal -->
<div class="del-modal-backdrop" id="approveModalBackdrop">
  <div class="del-modal">
    <h5><i class="bi bi-check-circle-fill me-2" style="color:#10b981;"></i>Approve Loan</h5>
    <p>Once approved, this loan can no longer be edited or deleted — only payments can be recorded against it.</p>
    <div class="ref-tag" id="approveRefDisplay"></div>
    <form method="POST" action="<?= base_url('EMPLOYEE/approve.php') ?>" id="approveForm">
      <input type="hidden" name="loan_id" id="approveLoanId">
      <div class="del-modal-actions">
        <button type="button" class="btn btn-secondary-custom" onclick="closeApproveModal()">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <button type="submit" class="btn btn-add">
          <i class="bi bi-check-circle-fill"></i> Confirm Approval
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="del-modal-backdrop" id="delModalBackdrop">
  <div class="del-modal">
    <h5><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete Loan Record</h5>
    <p>This action is permanent and cannot be undone. Type the reference number below to confirm.</p>
    <div class="ref-tag" id="delRefDisplay"></div>
    <form method="POST" action="<?= base_url('EMPLOYEE/delete.php') ?>" id="delForm">
      <input type="hidden" name="loan_id" id="delLoanId">
      <label>Type reference number to confirm</label>
      <input type="text" id="delConfirmInput" placeholder="e.g. CA-2026-0001" autocomplete="off">
      <div class="del-modal-actions">
        <button type="button" class="btn btn-secondary-custom" onclick="closeDelModal()">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <button type="submit" class="btn btn-danger" id="delSubmitBtn" disabled
                style="background:#ef4444;border:none;color:#fff;padding:.42rem .9rem;border-radius:var(--radius);font-size:.84rem;font-weight:700;cursor:pointer;opacity:.5;"
                onclick="return validateDel()">
          <i class="bi bi-trash-fill"></i> Delete
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Export helpers ────────────────────────────────────────────
function getTableData() {
  const headers = [];
  document.querySelectorAll('.loans-table thead th').forEach(th => {
    const t = th.innerText.trim();
    if (t !== '#' && t !== 'ACTIONS') headers.push(t);
  });

  const rows = [];
  document.querySelectorAll('.loans-table tbody tr').forEach(tr => {
    if (tr.classList.contains('empty-row')) return;
    const cells = tr.querySelectorAll('td');
    if (!cells.length) return;
    const row = [];
    // Skip col 0 (#) and last col (Actions)
    for (let i = 1; i < cells.length - 1; i++) {
      row.push(cells[i].innerText.trim().replace(/\n+/g, ' '));
    }
    rows.push(row);
  });
  return { headers, rows };
}

function exportCSV() {
  const { headers, rows } = getTableData();
  const escape = v => '"' + v.replace(/"/g, '""') + '"';
  let csv = headers.map(escape).join(',') + '\n';
  rows.forEach(r => { csv += r.map(escape).join(',') + '\n'; });
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'employee_loans_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
}

function exportExcel() {
  const { headers, rows } = getTableData();
  let html = '<table><thead><tr>' + headers.map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>';
  rows.forEach(r => { html += '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>'; });
  html += '</tbody></table>';
  const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'employee_loans_' + new Date().toISOString().slice(0,10) + '.xls';
  a.click();
}

function printReport() {
  const { headers, rows } = getTableData();
  const filters = [];
  <?php if ($filter_search):    ?> filters.push('Search: <?= addslashes($filter_search) ?>'); <?php endif; ?>
  <?php if ($filter_type > 0):  ?> filters.push('Type: <?= addslashes($loan_types[array_search($filter_type, array_column($loan_types,'ID'))]['TypeName'] ?? '') ?>'); <?php endif; ?>
  <?php if ($filter_department): ?> filters.push('Department: <?= addslashes($filter_department) ?>'); <?php endif; ?>
  <?php if ($filter_branch):    ?> filters.push('Branch: <?= addslashes($filter_branch) ?>'); <?php endif; ?>
  <?php if ($filter_status):    ?> filters.push('Status: <?= addslashes($filter_status) ?>'); <?php endif; ?>

  let html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
  <title>Employee Loans Report</title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 24px; }
    h2 { font-size: 15px; margin: 0 0 4px; }
    .meta { font-size: 10px; color: #666; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1f5f9; font-size: 9px; text-transform: uppercase;
         letter-spacing: .05em; padding: 6px 8px; border: 1px solid #e2e8f0;
         text-align: left; color: #64748b; }
    td { padding: 6px 8px; border: 1px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .footer { margin-top: 16px; font-size: 9px; color: #999; text-align: right; }
    @media print { body { margin: 0; } }
  </style></head><body>
  <h2>Employee Loans Report</h2>
  <div class="meta">
    Generated: ${new Date().toLocaleString('en-PH')}
    ${filters.length ? ' &nbsp;|&nbsp; Filters: ' + filters.join(', ') : ''}
    &nbsp;|&nbsp; Total records: ${rows.length}
  </div>
  <table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead><tbody>`;
  rows.forEach(r => { html += '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>'; });
  html += `</tbody></table>
  <div class="footer">Tradewell Management System · Employee Loans · ${new Date().toLocaleDateString('en-PH')}</div>
  </body></html>`;

  const w = window.open('', '_blank', 'width=1000,height=700');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => { w.print(); }, 400);
}

// ── Approve modal ─────────────────────────────────────────────
function openApproveModal(id, ref) {
  document.getElementById('approveLoanId').value      = id;
  document.getElementById('approveRefDisplay').textContent = ref;
  document.getElementById('approveModalBackdrop').classList.add('show');
}

function closeApproveModal() {
  document.getElementById('approveModalBackdrop').classList.remove('show');
}

document.getElementById('approveModalBackdrop').addEventListener('click', function (e) {
  if (e.target === this) closeApproveModal();
});

// ── Delete modal ──────────────────────────────────────────────
let _delRef = '';

function openDelModal(id, ref) {
  _delRef = ref;
  document.getElementById('delLoanId').value      = id;
  document.getElementById('delRefDisplay').textContent = ref;
  document.getElementById('delConfirmInput').value = '';
  document.getElementById('delSubmitBtn').disabled = true;
  document.getElementById('delSubmitBtn').style.opacity = '.5';
  document.getElementById('delModalBackdrop').classList.add('show');
  setTimeout(() => document.getElementById('delConfirmInput').focus(), 100);
}

function closeDelModal() {
  document.getElementById('delModalBackdrop').classList.remove('show');
}

function validateDel() {
  return document.getElementById('delConfirmInput').value.trim() === _delRef;
}

document.getElementById('delConfirmInput').addEventListener('input', function () {
  const match = this.value.trim() === _delRef;
  const btn   = document.getElementById('delSubmitBtn');
  btn.disabled      = !match;
  btn.style.opacity = match ? '1' : '.5';
});

document.getElementById('delModalBackdrop').addEventListener('click', function (e) {
  if (e.target === this) closeDelModal();
});
</script>
</body>
</html>