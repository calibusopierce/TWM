<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'cash_advance_record');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

if (rbac_is_view_only('cash_advance_record')) {
    header("Location: " . base_url('VALE/view.php?id=' . (int)($_GET['id'] ?? 0)));
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: cash-advance-record.php"); exit; }

// The payments page is only meaningful once a request has been Received —
// Requested/Approved have nothing to pay against yet, and Rejected/Paid are closed.
// (The AJAX record_payment handler below also re-checks this independently.)
if (!isset($_POST['ajax_action'])) {
    $page_status_chk = sqlsrv_query($conn, "SELECT Status FROM TBL_CashAdvance WHERE CashAdvanceID = ?", [$id]);
    $page_status_row = $page_status_chk ? sqlsrv_fetch_array($page_status_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$page_status_row || $page_status_row['Status'] !== 'Received') {
        header("Location: " . base_url('VALE/view.php?id=' . $id . '&error=not_received'));
        exit;
    }
}

// ── AJAX: record / unlink a payment ───────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $user = $_SESSION['EmployeeID'] ?? $_SESSION['Username'] ?? 'system';

    if ($_POST['ajax_action'] === 'record_payment') {
        // Payments may only be recorded against a Received cash advance.
        $status_chk = sqlsrv_query($conn, "SELECT Status FROM TBL_CashAdvance WHERE CashAdvanceID = ?", [$id]);
        $status_row = $status_chk ? sqlsrv_fetch_array($status_chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$status_row || $status_row['Status'] !== 'Received') {
            echo json_encode(['ok' => false, 'error' => 'Payments can only be recorded on a Received cash advance.']);
            exit;
        }

        $stmt_id    = (int)$_POST['statement_id'];
        $pay_date   = trim($_POST['payment_date']);
        $pay_amt    = floatval($_POST['payment_amount']);
        $pay_method = trim($_POST['payment_method']);
        $pay_ref    = trim($_POST['payment_ref']);

        if (!$stmt_id || !$pay_date || $pay_amt <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Statement row, date, and a positive amount are required.']);
            exit;
        }

        // Insert payment
        $ins = "INSERT INTO TBL_CashAdvance_Payment
                    (PaymentDate, PaymentAmount, PaymentMethod, ReferenceNumber, UserInput, InputDateTime)
                OUTPUT INSERTED.CashAdvancePaymentID
                VALUES (?,?,?,?,?,GETDATE())";
        $res = sqlsrv_query($conn, $ins, [$pay_date, $pay_amt, $pay_method, $pay_ref, $user]);
        if ($res === false) {
            echo json_encode(['ok' => false, 'error' => print_r(sqlsrv_errors(), true)]);
            exit;
        }
        $row    = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        $pay_id = $row['CashAdvancePaymentID'];

        // Link to statement row
        sqlsrv_query($conn, "UPDATE TBL_CashAdvance_Statement SET PaymentID = ? WHERE StatementID = ?",
            [$pay_id, $stmt_id]);

        // Update cash advance paid/balance totals
        sqlsrv_query($conn, "
            UPDATE TBL_CashAdvance SET
                PaidAmount    = ISNULL(PaidAmount, 0) + ?,
                BalanceAmount = ISNULL(BalanceAmount, 0) - ?,
                ModifiedBy    = ?, ModifiedDate = GETDATE()
            WHERE CashAdvanceID = ?",
            [$pay_amt, $pay_amt, $user, $id]);

        // Auto-flip to Paid if balance <= 0
        sqlsrv_query($conn, "
            UPDATE TBL_CashAdvance SET Status = 'Paid'
            WHERE CashAdvanceID = ? AND BalanceAmount <= 0 AND Status = 'Received'",
            [$id]);

        echo json_encode(['ok' => true, 'payment_id' => $pay_id]);
        exit;
    }

    if ($_POST['ajax_action'] === 'unlink_payment') {
        $stmt_id = (int)$_POST['statement_id'];
        $pay_id  = (int)$_POST['payment_id'];

        // Get payment amount first
        $pr   = sqlsrv_query($conn, "SELECT PaymentAmount FROM TBL_CashAdvance_Payment WHERE CashAdvancePaymentID = ?", [$pay_id]);
        $prow = $pr ? sqlsrv_fetch_array($pr, SQLSRV_FETCH_ASSOC) : null;
        $amt  = $prow ? (float)$prow['PaymentAmount'] : 0;

        sqlsrv_query($conn, "UPDATE TBL_CashAdvance_Statement SET PaymentID = NULL WHERE StatementID = ?", [$stmt_id]);
        sqlsrv_query($conn, "DELETE FROM TBL_CashAdvance_Payment WHERE CashAdvancePaymentID = ?", [$pay_id]);

        if ($amt > 0) {
            sqlsrv_query($conn, "
                UPDATE TBL_CashAdvance SET
                    PaidAmount    = ISNULL(PaidAmount, 0) - ?,
                    BalanceAmount = ISNULL(BalanceAmount, 0) + ?,
                    ModifiedBy    = ?, ModifiedDate = GETDATE()
                WHERE CashAdvanceID = ?",
                [$amt, $amt, $user, $id]);
        }

        // Reverse an auto Paid flip if the balance is no longer fully covered.
        sqlsrv_query($conn, "
            UPDATE TBL_CashAdvance SET Status = 'Received'
            WHERE CashAdvanceID = ? AND BalanceAmount > 0 AND Status = 'Paid'",
            [$id]);

        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Fetch cash advance ─────────────────────────────────────────
$res = sqlsrv_query($conn, "SELECT * FROM TBL_CashAdvance WHERE CashAdvanceID = ?", [$id]);
$ca  = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
if (!$ca) { echo "Cash advance record not found."; exit; }

// Employee info
$emp_res = sqlsrv_query($conn,
    "SELECT LastName, FirstName, MiddleName, Department, Position_held, EmployeeID
     FROM TBL_HrEmployeeList WHERE EmployeeID = ?", [$ca['EmployeeID']]);
$emp = $emp_res ? sqlsrv_fetch_array($emp_res, SQLSRV_FETCH_ASSOC) : [];
$full_name = trim(($emp['LastName'] ?? '') . ', ' . ($emp['FirstName'] ?? '') . ' ' . ($emp['MiddleName'] ?? ''));

// Schedule + linked payments
$sched_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount, p.PaymentMethod, p.ReferenceNumber AS PayRef
    FROM TBL_CashAdvance_Statement s
    LEFT JOIN TBL_CashAdvance_Payment p ON p.CashAdvancePaymentID = s.PaymentID
    WHERE s.CashAdvanceID = ? ORDER BY s.Due_Date ASC, s.StatementID ASC", [$id]);
$schedule = [];
if ($sched_res) while ($r = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $r;

$ca_amt   = (float)($ca['Amount']        ?? 0);
$paid_amt = (float)($ca['PaidAmount']    ?? 0);
$bal_amt  = (float)($ca['BalanceAmount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments · Cash Advance #<?= $id ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .info-grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
    .info-card  { background:var(--surface); border:1px solid var(--border);
                  border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow-sm); }
    .info-card-header { background:var(--surface-alt,#f8fafc); border-bottom:1px solid var(--border);
                        padding:.6rem 1rem; font-size:.78rem; font-weight:700;
                        text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .info-card-body { padding:.9rem 1rem; font-size:1.2rem; font-weight:700; color:var(--text-main); }

    .sched-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .sched-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .sched-table th { padding:.6rem .85rem; font-size:.74rem; font-weight:700;
                      text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .sched-table th.num { text-align:right; }
    .sched-table td { padding:.6rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .sched-table .num { text-align:right; font-weight:600; }
    .paid-row { background:rgba(16,185,129,.05); }
    .paid-chip  { display:inline-flex; align-items:center; gap:.25rem;
                  background:rgba(16,185,129,.12); color:#065f46;
                  padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .btn-pay { font-size:.76rem; padding:.25rem .65rem; }
    .action-wrap { display:flex; gap:.3rem; align-items:center; justify-content:center; }
    .btn-icon { width:28px; height:28px; border-radius:6px; border:1px solid var(--border);
                display:inline-flex; align-items:center; justify-content:center;
                font-size:.78rem; cursor:pointer; background:var(--surface); color:var(--text-muted); }
    .btn-icon.del { color:#ef4444; border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.07); }
    .btn-icon.del:hover { background:rgba(239,68,68,.18); }
    .form-group { display:flex; flex-direction:column; gap:.3rem; }
    .form-group label { font-size:.78rem; font-weight:700; color:var(--text-muted);
                        text-transform:uppercase; letter-spacing:.04em; }
    .form-group input, .form-group select {
      padding:.48rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
      font-size:.88rem; color:var(--text-main); background:var(--surface); }
  </style>
</head>
<body>
<?php $topbar_page = 'cash_advance_record'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">Payments: Cash Advance #<?= $id ?></div>
      <div class="page-subtitle"><?= htmlspecialchars($full_name) ?></div>
    </div>
    <a href="<?= base_url('VALE/view.php?id=' . $id) ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to Record
    </a>
  </div>

  <div class="info-grid3">
    <div class="info-card">
      <div class="info-card-header">Cash Advance Amount</div>
      <div class="info-card-body">₱ <?= number_format($ca_amt, 2) ?></div>
    </div>
    <div class="info-card">
      <div class="info-card-header">Paid</div>
      <div class="info-card-body" style="color:#10b981;">₱ <?= number_format($paid_amt, 2) ?></div>
    </div>
    <div class="info-card">
      <div class="info-card-header">Balance</div>
      <div class="info-card-body" style="color:<?= $bal_amt > 0 ? '#ef4444' : '#10b981' ?>;">₱ <?= number_format($bal_amt, 2) ?></div>
    </div>
  </div>

  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-cash-stack" style="color:var(--primary-light);"></i>
        Schedule &amp; Payments
        <span class="count-chip"><?= count($schedule) ?> rows</span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="sched-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>Due Date</th>
            <th class="num">Amount (₱)</th>
            <th style="text-align:center; width:220px;">Payment</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($schedule)): ?>
            <tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted);">
              No schedule rows for this cash advance yet.
            </td></tr>
          <?php else: ?>
            <?php foreach ($schedule as $i => $row):
              $paid    = !empty($row['PaymentID']);
              $raw_due = $row['Due_Date'] ?? '';
              $due_str = ($raw_due instanceof DateTime) ? $raw_due->format('M d, Y') : (is_string($raw_due) ? $raw_due : '—');
            ?>
            <tr class="<?= $paid ? 'paid-row' : '' ?>">
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i + 1 ?></td>
              <td style="font-size:.83rem;color:var(--text-muted);"><?= htmlspecialchars($due_str) ?></td>
              <td class="num">₱ <?= number_format((float)($row['Amortization_Amount'] ?? 0), 2) ?></td>
              <td style="text-align:center;">
                <?php if ($paid): ?>
                  <div class="action-wrap">
                    <span class="paid-chip"><i class="bi bi-check-circle-fill"></i>
                      ₱ <?= number_format((float)($row['PaymentAmount'] ?? 0), 2) ?>
                    </span>
                    <button type="button" class="btn-icon del" title="Remove payment"
                      onclick="unlinkPayment(<?= (int)$row['StatementID'] ?>, <?= (int)$row['PaymentID'] ?>)">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </div>
                <?php else: ?>
                  <button type="button" class="btn btn-add btn-pay"
                    onclick="openPayModal(<?= (int)$row['StatementID'] ?>, <?= (float)($row['Amortization_Amount'] ?? 0) ?>)">
                    <i class="bi bi-cash"></i> Record Payment
                  </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main-wrapper -->

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash me-2" style="color:var(--primary-light);"></i> Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;padding:1.5rem;">
        <input type="hidden" id="pay_stmt_id">
        <div class="form-group">
          <label>Payment Date *</label>
          <input type="date" id="pay_date" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>Amount (₱) *</label>
          <input type="number" id="pay_amount" step="0.01">
        </div>
        <div class="form-group">
          <label>Method</label>
          <select id="pay_method">
            <option value="Cash">Cash</option>
            <option value="Bank Transfer">Bank Transfer</option>
            <option value="Payroll Deduction">Payroll Deduction</option>
            <option value="Check">Check</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>Reference No.</label>
          <input type="text" id="pay_ref" placeholder="Optional">
        </div>
        <div id="pay_error" class="alert alert-danger" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-add" onclick="submitPayment()">
          <i class="bi bi-floppy-fill"></i> Save Payment
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const caId     = <?= $id ?>;
const payModal = new bootstrap.Modal(document.getElementById('payModal'));

function openPayModal(stmtId, amort) {
  document.getElementById('pay_stmt_id').value = stmtId;
  document.getElementById('pay_amount').value  = amort.toFixed(2);
  document.getElementById('pay_error').style.display = 'none';
  payModal.show();
}

function submitPayment() {
  const stmtId = document.getElementById('pay_stmt_id').value;
  const date   = document.getElementById('pay_date').value;
  const amt    = document.getElementById('pay_amount').value;
  const method = document.getElementById('pay_method').value;
  const ref    = document.getElementById('pay_ref').value;

  if (!date || !amt) {
    document.getElementById('pay_error').textContent = 'Date and amount are required.';
    document.getElementById('pay_error').style.display = '';
    return;
  }

  const fd = new FormData();
  fd.append('ajax_action',    'record_payment');
  fd.append('statement_id',   stmtId);
  fd.append('payment_date',   date);
  fd.append('payment_amount', amt);
  fd.append('payment_method', method);
  fd.append('payment_ref',    ref);

  fetch(`payments.php?id=${caId}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) { location.reload(); }
      else {
        document.getElementById('pay_error').textContent = data.error || 'Error saving payment.';
        document.getElementById('pay_error').style.display = '';
      }
    });
}

function unlinkPayment(stmtId, payId) {
  if (!confirm('Remove this payment? The amount will be reversed from the cash advance balance.')) return;
  const fd = new FormData();
  fd.append('ajax_action',  'unlink_payment');
  fd.append('statement_id', stmtId);
  fd.append('payment_id',   payId);
  fetch(`payments.php?id=${caId}`, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => { if (data.ok) location.reload(); });
}
</script>
</body>
</html>