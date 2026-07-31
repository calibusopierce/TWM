<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

// This page is shared by two tiers: full admin module (employee_loans)
// and the employee self-service view (my_loans). Allow either —
// the ownership check below still restricts my_loans users to their own record.
$perms = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!isset($perms['employee_loans']) && !isset($perms['my_loans'])) {
    rbac_gate($pdo, 'employee_loans'); // no access to either — let rbac_gate redirect/deny as usual
}

$is_readonly  = isset($_GET['readonly']) && $_GET['readonly'] == '1';
$my_emp_id    = $_SESSION['EmployeeID'] ?? '';
$is_admin     = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);

// Check view-only status against whichever module key actually granted access —
// a my_loans-only user has no 'employee_loans' entry, so checking that key
// would always fall through to the 'full' default regardless of their real level.
$loanModuleKey = isset($perms['employee_loans']) ? 'employee_loans' : 'my_loans';
$isViewOnly     = rbac_is_view_only($loanModuleKey);

// Non-admins in readonly mode can only view their own loans
if ($is_readonly && !$is_admin) {
    $own_chk = sqlsrv_query($conn, "SELECT EmployeeID FROM TBL_Loan WHERE LoanID = ?", [(int)($_GET['id'] ?? 0)]);
    $own_row = $own_chk ? sqlsrv_fetch_array($own_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$own_row || $own_row['EmployeeID'] !== $my_emp_id) {
        header("Location: " . base_url('EMPLOYEE/my_loans.php'));
        exit;
    }
}

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) { header("Location: index.php"); exit; }

// Main loan record
$res = sqlsrv_query($conn, "
    SELECT l.*, t.TypeName AS LoanTypeName, t.Code AS LoanTypeCode,
           e.LastName, e.FirstName, e.MiddleName,
           e.Department, e.Position_held, e.SSS_Number, e.HDMF,
           e.TIN_Number, e.Branch, e.Philhealth_Number
    FROM TBL_Loan l
    LEFT JOIN TBL_Loan_Type t       ON t.ID          = l.LoanType
    LEFT JOIN TBL_HREmployeeList e  ON e.EmployeeID  = l.EmployeeID
    WHERE l.LoanID = ?", [$loan_id]);
$loan = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
if (!$loan) { echo "Loan not found."; exit; }
$loan['Status'] = trim($loan['Status'] ?? ''); // normalize char-field padding

// Amortization schedule
$stmt_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount, p.PaymentMethod, p.ReferenceNumber AS PayRef
    FROM TBL_Loan_Statement s
    LEFT JOIN TBL_Loan_Payment p ON p.LoanPaymentID = s.PaymentID
    WHERE s.LoanID = ?
    ORDER BY s.Due_Date ASC, s.StatementID ASC", [$loan_id]);
$schedule = [];
if ($stmt_res) while ($r = sqlsrv_fetch_array($stmt_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $r;

// Standalone payments (not yet linked to a statement row)
$pay_res = sqlsrv_query($conn, "
    SELECT p.*
    FROM TBL_Loan_Payment p
    WHERE p.LoanPaymentID NOT IN (
        SELECT PaymentID FROM TBL_Loan_Statement WHERE LoanID = ? AND PaymentID IS NOT NULL
    )
    ORDER BY p.PaymentDate DESC", [$loan_id]);
$loose_payments = [];
if ($pay_res) while ($r = sqlsrv_fetch_array($pay_res, SQLSRV_FETCH_ASSOC)) $loose_payments[] = $r;

$loan_date = ($loan['LoanDate'] instanceof DateTime) ? $loan['LoanDate']->format('M d, Y') : ($loan['LoanDate'] ?? '—');
$full_name = trim(($loan['LastName'] ?? '') . ', ' . ($loan['FirstName'] ?? '') . ' ' . ($loan['MiddleName'] ?? ''));
$created   = isset($_GET['created']);
$updated   = isset($_GET['updated']);

$status_map = [
    'Proposal'   => 'bs-proposal',
    'Approved'   => 'bs-approved',
    'Active'     => 'bs-active',
    'Fully Paid' => 'bs-fully-paid',
    'Cancelled'  => 'bs-cancelled',
];
$bs = $status_map[$loan['Status']] ?? 'bs-proposal';

$loan_amt = (float)($loan['LoanAmount']    ?? 0);
$paid_amt = (float)($loan['PaidAmount']    ?? 0);
$bal_amt  = (float)($loan['BalanceAmount'] ?? 0);
$pct_paid = $loan_amt > 0 ? min(100, round($paid_amt / $loan_amt * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($loan['ReferenceNumber'] ?? 'Loan') ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .info-grid  { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
    .info-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1rem; }
    .info-card  { background:var(--surface); border:1px solid var(--border);
                  border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow-sm); }
    .info-card-header { background:var(--surface-alt,#f8fafc); border-bottom:1px solid var(--border);
                        padding:.6rem 1rem; font-size:.78rem; font-weight:700;
                        text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted);
                        display:flex; align-items:center; gap:.4rem; }
    .info-card-header i { color:var(--primary); }
    .info-card-body { padding:.9rem 1rem; font-size:.88rem; line-height:1.9; color:var(--text-main); }
    .info-card-body .name { font-weight:700; font-size:.95rem; }
    .info-row { display:flex; justify-content:space-between; border-bottom:1px solid var(--border);
                padding:.3rem 0; font-size:.85rem; }
    .info-row:last-child { border-bottom:none; }
    .info-row .lbl { color:var(--text-muted); font-weight:600; }
    .info-row .val { font-weight:700; }

    /* Progress */
    .progress-loan { height:8px; border-radius:999px; background:var(--border); overflow:hidden; margin-top:.5rem; }
    .progress-loan-fill { height:100%; border-radius:999px; background:#10b981; transition:width .4s; }

    /* Badge */
    .badge-status { display:inline-flex; align-items:center; gap:.3rem;
                    padding:.22rem .65rem; border-radius:999px; font-size:.75rem; font-weight:700; }
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

    /* Schedule table */
    .sched-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .sched-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .sched-table th { padding:.6rem .85rem; font-size:.74rem; font-weight:700;
                      text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .sched-table th.num { text-align:right; }
    .sched-table td { padding:.6rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .sched-table .num { text-align:right; font-weight:600; }
    .paid-row   { background:rgba(16,185,129,.05); }
    .unpaid-row { }
    .paid-chip  { display:inline-flex; align-items:center; gap:.25rem;
                  background:rgba(16,185,129,.12); color:#065f46;
                  padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .unpaid-chip { display:inline-flex; align-items:center; gap:.25rem;
                   background:rgba(245,158,11,.12); color:#b45309;
                   padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .empty-row td { text-align:center; padding:2.5rem; color:var(--text-muted); }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title"><?= htmlspecialchars($loan['ReferenceNumber'] ?? 'Loan') ?></div>
      <div class="page-subtitle">
        <span class="badge-status <?= $bs ?>"><?= htmlspecialchars($loan['Status'] ?? '') ?></span>
        &nbsp;·&nbsp; <?= htmlspecialchars($loan['LoanTypeName'] ?? '—') ?>
        &nbsp;·&nbsp; <?= $loan_date ?>
      </div>
    </div>
    <div style="display:flex;gap:.6rem;">
      <a href="<?= base_url($is_readonly ? 'EMPLOYEE/my_loans.php' : 'EMPLOYEE/index.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-arrow-left"></i> Back
      </a>
      <?php if (!$isViewOnly && !$is_readonly && ($loan['Status'] ?? '') === 'Proposal'): ?>
      <a href="<?= base_url('EMPLOYEE/edit.php?id=' . $loan_id) ?>" class="btn btn-secondary-custom">
        <i class="bi bi-pencil-fill"></i> Edit
      </a>
      <button type="button" class="btn btn-add" onclick="openApproveModal()">
        <i class="bi bi-check-circle-fill"></i> Approve Loan
      </button>
      <?php endif; ?>
      <?php if (!$isViewOnly && !$is_readonly && ($loan['Status'] ?? '') === 'Approved'): ?>
      <a href="<?= base_url('EMPLOYEE/payments.php?id=' . $loan_id) ?>" class="btn btn-add">
        <i class="bi bi-cash-coin"></i> Payments
      </a>
      <?php endif; ?>
      <a href="<?= base_url('EMPLOYEE/print.php?id=' . $loan_id . ($is_readonly ? '&readonly=1' : '')) ?>" class="btn btn-add" target="_blank">
        <i class="bi bi-printer-fill"></i> Print SOA
      </a>
      <a href="<?= base_url('EMPLOYEE/payment_schedule.php?id=' . $loan_id . ($is_readonly ? '&readonly=1' : '')) ?>" class="btn btn-add" target="_blank">
        <i class="bi bi-calendar3"></i> Payment Schedule
      </a>
    </div>
  </div>

  <?php if ($created): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Loan record created successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($updated): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Loan record updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Employee & Loan Info -->
  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-header"><i class="bi bi-person-fill"></i> Employee</div>
      <div class="info-card-body">
        <div class="name"><?= htmlspecialchars($full_name) ?></div>
        <div style="font-size:.82rem;color:var(--text-muted);">
          <?= htmlspecialchars($loan['Department'] ?? '') ?>
          <?php if (!empty($loan['Position_held'])): ?> · <?= htmlspecialchars($loan['Position_held']) ?><?php endif; ?>
        </div>
        <div style="margin-top:.6rem;">
          <div class="info-row"><span class="lbl">Employee ID</span><span class="val"><?= htmlspecialchars($loan['EmployeeID'] ?? '—') ?></span></div>
          <div class="info-row"><span class="lbl">Branch</span><span class="val"><?= htmlspecialchars($loan['Branch'] ?? '—') ?></span></div>
          <div class="info-row"><span class="lbl">SSS No.</span><span class="val"><?= htmlspecialchars($loan['SSS_Number'] ?? '—') ?></span></div>
          <div class="info-row"><span class="lbl">Pag-IBIG (HDMF)</span><span class="val"><?= htmlspecialchars($loan['HDMF'] ?? '—') ?></span></div>
          <div class="info-row"><span class="lbl">TIN</span><span class="val"><?= htmlspecialchars($loan['TIN_Number'] ?? '—') ?></span></div>
          <div class="info-row"><span class="lbl">PHILHEALTH</span><span class="val"><?= htmlspecialchars($loan['Philhealth_Number'] ?? '—') ?></span></div>
        </div>
      </div>
    </div>
    <div class="info-card">
      <div class="info-card-header"><i class="bi bi-cash-stack"></i> Loan Summary</div>
      <div class="info-card-body">
        <div class="info-row"><span class="lbl">Loan Amount</span><span class="val">₱ <?= number_format($loan_amt, 2) ?></span></div>
        <div class="info-row"><span class="lbl">Amount Paid</span><span class="val" style="color:#10b981;">₱ <?= number_format($paid_amt, 2) ?></span></div>
        <div class="info-row"><span class="lbl">Balance</span><span class="val" style="color:<?= $bal_amt > 0 ? '#ef4444' : '#10b981' ?>;">₱ <?= number_format($bal_amt, 2) ?></span></div>
        <div class="info-row"><span class="lbl">Terms</span><span class="val"><?= (int)($loan['Terms'] ?? 0) ?> </span></div>
<?php
  $cutoff_int_map = [4 => 'Weekly', 1 => 'Monthly', 2 => '15th & 30th'];
  $cutoff_int     = (int)($loan['CutOff'] ?? 0);
  $cutoff_disp    = $cutoff_int > 0 ? ($cutoff_int_map[$cutoff_int] ?? $cutoff_int . 'x/month') : '—';

  $cutoff_amt_disp = (float)($loan['CutOff_Amount'] ?? 0);
  $monthly_disp    = (float)($loan['TermsAmount']   ?? 0);

  $remarks_raw   = $loan['Remarks'] ?? '';
  $remarks_clean = preg_replace('/^\[Freq:[^\]]*\]\s?/', '', $remarks_raw); // strip legacy tag if present
?>
        <div class="info-row"><span class="lbl">Amortization</span><span class="val">₱ <?= number_format($monthly_disp, 2) ?></span></div>
        <div class="info-row"><span class="lbl">Payment Schedule</span><span class="val"><?= htmlspecialchars($cutoff_disp) ?></span></div>
        <div class="info-row"><span class="lbl">Amount</span><span class="val">₱ <?= number_format($cutoff_amt_disp, 2) ?></span></div>
        <?php if ($loan_amt > 0): ?>
        <div style="margin-top:.5rem;">
          <div style="font-size:.74rem;color:var(--text-muted);margin-bottom:.25rem;"><?= $pct_paid ?>% paid</div>
          <div class="progress-loan"><div class="progress-loan-fill" style="width:<?= $pct_paid ?>%;"></div></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Description & Remarks -->
  <?php if (!empty($loan['Description']) || !empty($remarks_clean)): ?>
  <div class="info-card" style="margin-bottom:1rem;">
    <div class="info-card-header"><i class="bi bi-chat-text-fill"></i> Notes</div>
    <div class="info-card-body" style="display:flex;gap:2rem;">
      <?php if (!empty($loan['Description'])): ?>
        <div><span style="font-size:.74rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Purpose</span><br>
          <?= htmlspecialchars($loan['Description']) ?></div>
      <?php endif; ?>
      <?php if (!empty($remarks_clean)): ?>
        <div><span style="font-size:.74rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;">Remarks</span><br>
          <?= htmlspecialchars($remarks_clean) ?> <span style="font-size:.78rem;color:var(--text-muted);">(<?= htmlspecialchars($loan['RemarksBy'] ?? '') ?>)</span></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Amortization Schedule -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-calendar3" style="color:var(--primary-light);"></i>
        Amortization Schedule
        <span class="count-chip"><?= count($schedule) ?> row<?= count($schedule) !== 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="sched-table">
        <thead>
          <tr>
            <th style="width:45px;">#</th>
            <th>Month</th>
            <th>Due Date</th>
            <th class="num">OPB (₱)</th>
            <th class="num">Principal (₱)</th>
            <th class="num">Interest (₱)</th>
            <th class="num">Amortization (₱)</th>
            <th style="text-align:center;">Payment</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($schedule)): ?>
            <tr class="empty-row"><td colspan="8">
              <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
              No schedule rows yet.
            </td></tr>
          <?php else: ?>
            <?php foreach ($schedule as $i => $row):
              $paid    = !empty($row['PaymentID']);
              // Helper: safely convert any sqlsrv value to string
              $toStr = function($v, $fmt = 'M d, Y', $fallback = '') {
                  if ($v instanceof DateTime) return $v->format($fmt);
                  if (is_string($v)) return $v;
                  if ($v === null) return $fallback;
                  return (string)$v;
              };
              $raw_due   = $row['Due_Date'] ?? null;
              $due_str   = ($raw_due instanceof DateTime) ? $raw_due->format('M d, Y') : (is_string($raw_due) ? $raw_due : '—');
              $raw_pay   = $row['PaymentDate'] ?? null;
              $pay_str   = ($raw_pay instanceof DateTime) ? $raw_pay->format('M d, Y') : (is_string($raw_pay) ? $raw_pay : '');
              $month_str = $toStr($row['MonthName'] ?? null, 'M d, Y', '—');
            ?>
            <tr class="<?= $paid ? 'paid-row' : 'unpaid-row' ?>">
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i + 1 ?></td>
              <td><?= htmlspecialchars($month_str) ?></td>
              <td style="font-size:.83rem;color:var(--text-muted);"><?= htmlspecialchars($due_str) ?></td>
              <td class="num">₱ <?= number_format((float)($row['OPB'] ?? 0), 2) ?></td>
              <td class="num">₱ <?= number_format((float)($row['Pricipal_Amount'] ?? 0), 2) ?></td>
              <td class="num">₱ <?= number_format((float)($row['Interest_Amount'] ?? 0), 2) ?></td>
              <td class="num">₱ <?= number_format((float)($row['Amortization_Amount'] ?? 0), 2) ?></td>
              <td style="text-align:center;">
                <?php if ($paid): ?>
                  <span class="paid-chip"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($pay_str) ?></span>
                <?php else: ?>
                  <span class="unpaid-chip"><i class="bi bi-clock"></i> Pending</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php if (!$isViewOnly && !$is_readonly && ($loan['Status'] ?? '') === 'Proposal'): ?>
<!-- Approve confirmation modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-check-circle-fill me-2" style="color:#10b981;"></i> Approve Loan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:1.5rem;">
        <p style="margin-bottom:1rem;">
          You're about to approve loan
          <strong><?= htmlspecialchars($loan['ReferenceNumber'] ?? '') ?></strong>
          for <strong><?= htmlspecialchars($full_name) ?></strong>
          (₱ <?= number_format((float)($loan['LoanAmount'] ?? 0), 2) ?>).
        </p>
        <p style="margin-bottom:0;color:var(--text-muted);font-size:.86rem;">
          Once approved, the loan's details can no longer be edited or deleted —
          only payments can be recorded against it from this point on.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
        <form method="POST" action="<?= base_url('EMPLOYEE/approve.php') ?>" style="display:inline;">
          <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
          <input type="hidden" name="return" value="view">
          <button type="submit" class="btn btn-add">
            <i class="bi bi-check-circle-fill"></i> Confirm Approval
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<?php if (!$isViewOnly && !$is_readonly && ($loan['Status'] ?? '') === 'Proposal'): ?>
<script>
function openApproveModal() {
  new bootstrap.Modal(document.getElementById('approveModal')).show();
}
</script>
<?php endif; ?>
</body>
</html>