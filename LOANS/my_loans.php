<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'my_loans');

$my_employee_id = $_SESSION['EmployeeID'] ?? '';

if (!$my_employee_id) {
    header("Location: " . base_url('index.php'));
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_type   = isset($_GET['loan_type']) ? (int)$_GET['loan_type'] : 0;

$where  = "WHERE l.EmployeeID = ?";
$params = [$my_employee_id];

if ($filter_search !== '') {
    $where   .= " AND l.ReferenceNumber LIKE ?";
    $params[] = "%$filter_search%";
}
if ($filter_status !== '') {
    $where   .= " AND l.Status = ?";
    $params[] = $filter_status;
}
if ($filter_type > 0) {
    $where   .= " AND l.LoanType = ?";
    $params[] = $filter_type;
}

// ── Main query ────────────────────────────────────────────────
$sql = "
    SELECT
        l.LoanID, l.ReferenceNumber, l.LoanDate,
        l.LoanAmount, l.PaidAmount, l.BalanceAmount,
        l.Terms, l.TermsAmount, l.Status, l.Description,
        t.TypeName AS LoanTypeName,
        e.LastName, e.FirstName, e.MiddleName,
        e.Department, e.Branch, e.Position_held
    FROM TBL_Loan l
    LEFT JOIN TBL_Loan_Type      t ON t.ID         = l.LoanType
    LEFT JOIN TBL_HREmployeeList e ON e.EmployeeID = l.EmployeeID
    $where
    ORDER BY l.LoanID DESC
";
$stmt        = sqlsrv_query($conn, $sql, $params);
$query_error = '';
if ($stmt === false) {
    $errs        = sqlsrv_errors() ?: [];
    $query_error = trim(implode(' ', array_column($errs, 'message'))) ?: 'Unknown query error.';
}

// ── Stat counts (own loans only) ──────────────────────────────
$counts        = ['total' => 0, 'Proposal' => 0, 'Approved' => 0, 'Fully Paid' => 0];
$balance_total = 0;
$paid_total    = 0;
$loan_total    = 0;

$count_res = sqlsrv_query($conn, "
    SELECT Status, COUNT(*) AS cnt, SUM(LoanAmount) AS loan_amt,
           SUM(PaidAmount) AS paid_amt, SUM(BalanceAmount) AS bal
    FROM TBL_Loan
    WHERE EmployeeID = ?
    GROUP BY Status
", [$my_employee_id]);
if ($count_res !== false) {
    while ($r = sqlsrv_fetch_array($count_res, SQLSRV_FETCH_ASSOC)) {
        $s = $r['Status'] ?? '';
        if (array_key_exists($s, $counts)) $counts[$s] = (int)$r['cnt'];
        $counts['total'] += (int)$r['cnt'];
        $loan_total      += (float)($r['loan_amt'] ?? 0);
        $paid_total      += (float)($r['paid_amt'] ?? 0);
        if (!in_array($s, ['Fully Paid', 'Cancelled'])) {
            $balance_total += (float)($r['bal'] ?? 0);
        }
    }
}

// ── Loan types for filter ─────────────────────────────────────
$loan_types = [];
$types_q    = sqlsrv_query($conn, "SELECT ID, TypeName FROM TBL_Loan_Type ORDER BY TypeName");
if ($types_q !== false) while ($r = sqlsrv_fetch_array($types_q, SQLSRV_FETCH_ASSOC)) $loan_types[] = $r;

// ── Employee info ─────────────────────────────────────────────
$emp_res  = sqlsrv_query($conn, "
    SELECT LastName, FirstName, MiddleName, Department, Branch, Position_held
    FROM TBL_HREmployeeList WHERE EmployeeID = ?
", [$my_employee_id]);
$emp_info = ($emp_res !== false) ? sqlsrv_fetch_array($emp_res, SQLSRV_FETCH_ASSOC) : [];
$emp_name = trim(($emp_info['FirstName'] ?? '') . ' ' . ($emp_info['MiddleName'] ? $emp_info['MiddleName'][0] . '. ' : '') . ($emp_info['LastName'] ?? ''));

// ── Collect rows ──────────────────────────────────────────────
$rows_data = [];
if ($stmt !== false) while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows_data[] = $r;
$rowCount = count($rows_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Loans · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Employee identity card ─────────────────────────── */
    .emp-card {
      background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
      border-radius: var(--radius-lg); padding: 1.5rem 1.75rem;
      display: flex; align-items: center; gap: 1.25rem;
      margin-bottom: 1.5rem; color: #fff; box-shadow: var(--shadow-sm);
    }
    .emp-avatar {
      width: 56px; height: 56px; border-radius: 50%;
      background: rgba(255,255,255,.2); display: flex;
      align-items: center; justify-content: center;
      font-size: 1.6rem; flex-shrink: 0;
    }
    .emp-card-name  { font-size: 1.15rem; font-weight: 800; line-height: 1.2; }
    .emp-card-sub   { font-size: .82rem; opacity: .8; margin-top: .2rem; }
    .emp-card-badge {
      margin-left: auto; background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.25); border-radius: var(--radius-lg);
      padding: .75rem 1.25rem; text-align: right;
    }
    .emp-card-badge .val  { font-size: 1.3rem; font-weight: 800; line-height: 1; }
    .emp-card-badge .lbl  { font-size: .72rem; opacity: .75; margin-top: .2rem;
                            text-transform: uppercase; letter-spacing: .05em; }

    /* ── Stat cards ─────────────────────────────────────── */
    .stat-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 1rem; margin-bottom: 1.5rem;
    }
    .stat-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: var(--radius-lg); padding: .9rem 1.1rem;
      display: flex; align-items: center; gap: .75rem;
      box-shadow: var(--shadow-sm);
    }
    .stat-icon {
      width: 38px; height: 38px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.05rem; flex-shrink: 0;
    }
    .stat-val    { font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .stat-val.sm { font-size: 1rem; }
    .stat-lbl    { font-size: .7rem; color: var(--text-muted); margin-top: .12rem;
                   font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .si-total    { background: rgba(59,130,246,.12);  color: #3b82f6; }
    .si-active   { background: rgba(16,185,129,.12);  color: #10b981; }
    .si-paid     { background: rgba(20,184,166,.12);  color: #0d9488; }
    .si-balance  { background: rgba(239,68,68,.12);   color: #ef4444; }
    .si-proposal { background: rgba(245,158,11,.12);  color: #f59e0b; }

    /* ── Table ──────────────────────────────────────────── */
    .loans-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
    .loans-table thead tr { background: var(--surface-alt,#f8fafc); border-bottom: 2px solid var(--border); }
    .loans-table th {
      padding: .7rem 1rem; text-align: left; font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); white-space: nowrap;
    }
    .loans-table td {
      padding: .75rem 1rem; border-bottom: 1px solid var(--border);
      vertical-align: middle; color: var(--text-main);
    }
    .loans-table tbody tr:nth-child(even) { background: var(--surface-alt,#f8fafc); }
    .loans-table tbody tr:hover { background: rgba(59,130,246,.04); }

    .ref-number  { font-weight: 700; color: var(--primary); font-size: .87rem;
                   font-family: 'JetBrains Mono', monospace; }
    .amount-cell { text-align: right; font-weight: 700; font-size: .87rem;
                   font-family: 'JetBrains Mono', monospace; }

    /* ── Progress bar ───────────────────────────────────── */
    .prog-wrap  { margin-top: .3rem; display: flex; align-items: center; gap: .4rem; }
    .prog-bar   { flex: 1; height: 5px; border-radius: 999px; background: var(--border); overflow: hidden; }
    .prog-fill  { height: 100%; border-radius: 999px; background: #10b981; }
    .prog-label { font-size: .7rem; color: var(--text-muted); white-space: nowrap; }

    /* ── Status badges ──────────────────────────────────── */
    .badge-status {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .22rem .65rem; border-radius: 999px;
      font-size: .72rem; font-weight: 700; white-space: nowrap;
    }
    .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
    .bs-proposal   { background:rgba(245,158,11,.12); color:#b45309; }
    .bs-proposal::before   { background:#f59e0b; }
    .bs-approved   { background:rgba(99,102,241,.12); color:#4338ca; }
    .bs-approved::before   { background:#6366f1; }
    .bs-active     { background:rgba(16,185,129,.12); color:#065f46; }
    .bs-active::before     { background:#10b981; }
    .bs-fully-paid { background:rgba(20,184,166,.12); color:#134e4a; }
    .bs-fully-paid::before { background:#0d9488; }
    .bs-cancelled  { background:rgba(239,68,68,.12);  color:#991b1b; }
    .bs-cancelled::before  { background:#ef4444; }

    /* ── Action buttons ─────────────────────────────────── */
    .action-wrap { display: flex; gap: .3rem; align-items: center; justify-content: center; }
    .btn-icon {
      width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--border);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .78rem; cursor: pointer; text-decoration: none;
      transition: background .15s; background: var(--surface); color: var(--text-muted);
    }
    .btn-icon.view  { color:#3b82f6; border-color:rgba(59,130,246,.3);  background:rgba(59,130,246,.07); }
    .btn-icon.print { color:#6366f1; border-color:rgba(99,102,241,.3);  background:rgba(99,102,241,.07); }
    .btn-icon.view:hover  { background:rgba(59,130,246,.18); }
    .btn-icon.print:hover { background:rgba(99,102,241,.18); }

    .type-chip {
      font-size: .73rem; background: rgba(99,102,241,.1); color: #4f46e5;
      padding: .18rem .6rem; border-radius: 999px; font-weight: 600; white-space: nowrap;
    }
    .empty-row td { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }

    /* ── Read-only notice ───────────────────────────────── */
    .readonly-notice {
      background: rgba(59,130,246,.07); border: 1px solid rgba(59,130,246,.2);
      border-radius: var(--radius); padding: .55rem 1rem;
      font-size: .82rem; color: #1e40af; display: flex; align-items: center; gap: .5rem;
      margin-bottom: 1.25rem;
    }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">My Loans</div>
      <div class="page-subtitle">View your personal loan records and payment history</div>
    </div>
  </div>

  <!-- Read-only notice -->
  <div class="readonly-notice">
    <i class="bi bi-info-circle-fill"></i>
    This is a read-only view. To request changes or make payments, please contact HR or Finance.
  </div>

  <?php if (!empty($query_error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong>Query error:</strong> <?= htmlspecialchars($query_error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Employee Identity Card -->
  <div class="emp-card">
    <div class="emp-avatar"><i class="bi bi-person-fill"></i></div>
    <div>
      <div class="emp-card-name"><?= htmlspecialchars($emp_name) ?></div>
      <div class="emp-card-sub">
        <?= htmlspecialchars($emp_info['Position_held'] ?? '') ?>
        <?php if (!empty($emp_info['Department'])): ?>
          · <?= htmlspecialchars($emp_info['Department']) ?>
        <?php endif; ?>
        <?php if (!empty($emp_info['Branch'])): ?>
          · <?= htmlspecialchars($emp_info['Branch']) ?>
        <?php endif; ?>
      </div>
      <div class="emp-card-sub" style="margin-top:.3rem;opacity:.65;font-size:.76rem;">
        Employee ID: <?= htmlspecialchars($my_employee_id) ?>
      </div>
    </div>
    <div class="emp-card-badge">
      <div class="val">₱ <?= number_format($balance_total, 0) ?></div>
      <div class="lbl">Outstanding Balance</div>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-icon si-total"><i class="bi bi-journal-text"></i></div>
      <div>
        <div class="stat-val"><?= $counts['total'] ?></div>
        <div class="stat-lbl">Total Loans</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-proposal"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-val"><?= $counts['Proposal'] + ($counts['Approved'] ?? 0) ?></div>
        <div class="stat-lbl">Pending</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-active"><i class="bi bi-activity"></i></div>
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
      <div class="stat-icon si-active" style="background:rgba(59,130,246,.12);color:#3b82f6;"><i class="bi bi-cash-coin"></i></div>
      <div>
        <div class="stat-val sm">₱ <?= number_format($paid_total, 0) ?></div>
        <div class="stat-lbl">Total Paid</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-balance"><i class="bi bi-cash-stack"></i></div>
      <div>
        <div class="stat-val sm">₱ <?= number_format($balance_total, 0) ?></div>
        <div class="stat-lbl">Outstanding</div>
      </div>
    </div>
  </div>

  <!-- Table Card -->
  <div class="table-card">

    <!-- Title + filters -->
    <div class="table-card-header" style="border-bottom:0;padding-bottom:.5rem;">
      <div class="table-card-title">
        <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
        My Loan Records
        <span class="count-chip"><?= $rowCount ?> record<?= $rowCount !== 1 ? 's' : '' ?></span>
      </div>
      <button onclick="printMyLoans()" class="btn btn-secondary-custom" style="padding:.38rem .85rem;font-size:.82rem;margin-left:auto;">
        <i class="bi bi-printer-fill"></i> Print Summary
      </button>
    </div>

    <div style="padding:.65rem 1.25rem 1rem;border-bottom:1px solid var(--border);background:var(--surface-alt,#f8fafc);">
      <form method="GET">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:.6rem;align-items:flex-end;">
          <div>
            <div style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.28rem;">Search</div>
            <input type="text" name="search" placeholder="Reference No…"
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
            <?php if ($filter_search || $filter_type || $filter_status): ?>
              <a href="<?= base_url('EMPLOYEE/my_loans.php') ?>" class="btn btn-secondary-custom"
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
            <th>Loan Type</th>
            <th>Date</th>
            <th style="text-align:right;">Loan Amount</th>
            <th style="text-align:center;">Terms</th>
            <th style="text-align:right;">Monthly Amt</th>
            <th>Progress</th>
            <th style="text-align:right;">Balance</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:center;width:90px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows_data)): ?>
            <tr class="empty-row">
              <td colspan="11">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No loan records found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows_data as $i => $row):
              $date_str   = ($row['LoanDate'] instanceof DateTime)
                  ? $row['LoanDate']->format('M d, Y') : ($row['LoanDate'] ?? '—');
              $status_key = strtolower(str_replace(' ', '-', $row['Status'] ?? ''));
              $loan_amt   = (float)($row['LoanAmount']   ?? 0);
              $paid_amt   = (float)($row['PaidAmount']   ?? 0);
              $bal_amt    = (float)($row['BalanceAmount'] ?? 0);
              $terms_amt  = (float)($row['TermsAmount']  ?? 0);
              $pct        = $loan_amt > 0 ? min(100, round($paid_amt / $loan_amt * 100)) : 0;
            ?>
            <tr>
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i + 1 ?></td>
              <td>
                <a href="<?= base_url('EMPLOYEE/view.php?id=' . $row['LoanID'] . '&readonly=1') ?>"
                   style="text-decoration:none;">
                  <span class="ref-number"><?= htmlspecialchars($row['ReferenceNumber'] ?? '—') ?></span>
                </a>
              </td>
              <td><span class="type-chip"><?= htmlspecialchars($row['LoanTypeName'] ?? '—') ?></span></td>
              <td style="color:var(--text-muted);font-size:.83rem;"><?= htmlspecialchars($date_str) ?></td>
              <td class="amount-cell">₱ <?= number_format($loan_amt, 2) ?></td>
              <td style="text-align:center;font-size:.84rem;color:var(--text-muted);"><?= (int)($row['Terms'] ?? 0) ?> mo.</td>
              <td class="amount-cell">₱ <?= number_format($terms_amt, 2) ?></td>
              <td style="min-width:120px;">
                <div class="prog-wrap">
                  <div class="prog-bar">
                    <div class="prog-fill" style="width:<?= $pct ?>%;"></div>
                  </div>
                  <span class="prog-label"><?= $pct ?>%</span>
                </div>
              </td>
              <td class="amount-cell" style="color:<?= $bal_amt <= 0 ? '#10b981' : '#ef4444' ?>;">
                <?= $bal_amt <= 0 ? '—' : '₱ ' . number_format($bal_amt, 2) ?>
              </td>
              <td style="text-align:center;">
                <span class="badge-status bs-<?= $status_key ?>">
                  <?= htmlspecialchars($row['Status'] ?? '—') ?>
                </span>
              </td>
              <td>
                <div class="action-wrap">
                  <a href="<?= base_url('EMPLOYEE/view.php?id=' . $row['LoanID'] . '&readonly=1') ?>"
                     class="btn-icon view" title="View Details"><i class="bi bi-eye-fill"></i></a>
                  <a href="<?= base_url('EMPLOYEE/print.php?id=' . $row['LoanID'] . '&readonly=1') ?>"
                     class="btn-icon print" title="Print SOA" target="_blank">
                    <i class="bi bi-printer-fill"></i>
                  </a>
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
<script>
function printMyLoans() {
  const rows = [];
  document.querySelectorAll('.loans-table tbody tr').forEach(tr => {
    if (tr.classList.contains('empty-row')) return;
    const cells = tr.querySelectorAll('td');
    if (!cells.length) return;
    rows.push({
      ref:     cells[1]?.innerText.trim() || '',
      type:    cells[2]?.innerText.trim() || '',
      date:    cells[3]?.innerText.trim() || '',
      amount:  cells[4]?.innerText.trim() || '',
      terms:   cells[5]?.innerText.trim() || '',
      monthly: cells[6]?.innerText.trim() || '',
      pct:     cells[7]?.innerText.trim() || '',
      balance: cells[8]?.innerText.trim() || '',
      status:  cells[9]?.innerText.trim() || '',
    });
  });

  const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
  <title>My Loans Summary</title>
  <style>
    body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 24px; }
    h2   { font-size: 15px; margin: 0 0 2px; }
    .sub { font-size: 10px; color: #666; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1f5f9; font-size: 9px; text-transform: uppercase;
         letter-spacing: .05em; padding: 6px 8px; border: 1px solid #e2e8f0;
         text-align: left; color: #64748b; }
    td { padding: 6px 8px; border: 1px solid #e2e8f0; }
    tr:nth-child(even) td { background: #f8fafc; }
    .footer { margin-top: 14px; font-size: 9px; color: #999; text-align: right; }
    @media print { body { margin: 0; } }
  </style></head><body>
  <h2>My Loan Summary</h2>
  <div class="sub">
    <?= htmlspecialchars($emp_name) ?> · <?= htmlspecialchars($my_employee_id) ?>
    &nbsp;|&nbsp; <?= htmlspecialchars($emp_info['Department'] ?? '') ?>
    &nbsp;|&nbsp; Generated: ${new Date().toLocaleString('en-PH')}
  </div>
  <table>
    <thead><tr>
      <th>Ref No.</th><th>Loan Type</th><th>Date</th>
      <th>Loan Amount</th><th>Terms</th><th>Monthly</th>
      <th>Paid %</th><th>Balance</th><th>Status</th>
    </tr></thead>
    <tbody>
      ${rows.map(r => `<tr>
        <td>${r.ref}</td><td>${r.type}</td><td>${r.date}</td>
        <td>${r.amount}</td><td>${r.terms}</td><td>${r.monthly}</td>
        <td>${r.pct}</td><td>${r.balance}</td><td>${r.status}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div class="footer">Tradewell Management System · My Loans · ${new Date().toLocaleDateString('en-PH')}</div>
  </body></html>`;

  const w = window.open('', '_blank', 'width=1000,height=700');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}
</script>
</body>
</html>