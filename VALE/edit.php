<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'cash_advance_record');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

if (rbac_is_view_only('cash_advance_record')) {
    header("Location: " . base_url('VALE/cash-advance-record.php'));
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header("Location: cash-advance-record.php"); exit; }

$errors  = [];
$success = false;

// ── Fixed 4-boss Approver list — same placeholder list as create.php ────
$APPROVER_IDS = [
    '88808',           // Rozaldie Chua
    '88803',           // Mary Donna Chua
    'TID-16188-2024',  // Thomas Jefferson Chua
    'TID-16604-2026',  // Thomas Edward Chua
];
$approvers = [];
if (!empty($APPROVER_IDS)) {
    $placeholders = implode(',', array_fill(0, count($APPROVER_IDS), '?'));
    $apRes = sqlsrv_query($conn,
        "SELECT EmployeeID, FirstName, LastName, Position_held
         FROM TBL_HrEmployeeList WHERE EmployeeID IN ($placeholders)", $APPROVER_IDS);
    if ($apRes !== false) {
        while ($ap = sqlsrv_fetch_array($apRes, SQLSRV_FETCH_ASSOC)) $approvers[] = $ap;
    }
}

// ── Fetch the record — editing only makes sense before cash is released.
// Once Received, PaidAmount/schedule links may already exist and changing
// the total out from under a live payment schedule isn't safe.
$ca_res = sqlsrv_query($conn, "SELECT * FROM TBL_CashAdvance WHERE CashAdvanceID = ?", [$id]);
$ca     = $ca_res ? sqlsrv_fetch_array($ca_res, SQLSRV_FETCH_ASSOC) : null;
if (!$ca) { die('Record not found.'); }

if (!in_array($ca['Status'], ['Requested', 'Approved'])) {
    header("Location: view.php?id=$id&error=not_editable");
    exit;
}

$emp_res = sqlsrv_query($conn, "SELECT FirstName, LastName FROM TBL_HrEmployeeList WHERE EmployeeID = ?", [$ca['EmployeeID']]);
$emp     = $emp_res ? sqlsrv_fetch_array($emp_res, SQLSRV_FETCH_ASSOC) : [];
$empName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));

// Existing schedule, for pre-filling the builder
$sched_res = sqlsrv_query($conn,
    "SELECT * FROM TBL_CashAdvance_Statement WHERE CashAdvanceID = ? ORDER BY Due_Date ASC, StatementID ASC", [$id]);
$existingSchedule = [];
if ($sched_res) {
    while ($sr = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) {
        $raw_due = $sr['Due_Date'] ?? '';
        $due_str = ($raw_due instanceof DateTime) ? $raw_due->format('Y-m-d') : (is_string($raw_due) ? $raw_due : '');
        $existingSchedule[] = ['date' => $due_str, 'amount' => $sr['Amortization_Amount']];
    }
}

// Repopulate the schedule builder if this is a re-render after a validation error
$schedRepop = $existingSchedule;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sched_date'])) {
    $schedRepop = [];
    foreach ($_POST['sched_date'] as $i => $d) {
        $schedRepop[] = ['date' => $d, 'amount' => $_POST['sched_amount'][$i] ?? ''];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $Amount           = trim($_POST['Amount']           ?? '');
    $Reason           = trim($_POST['Reason']           ?? '');
    $AssignedApproverID = trim($_POST['ApproverID']       ?? '');
    $RecommendRemarks = trim($_POST['RecommendRemarks'] ?? '');
    $Remarks          = trim($_POST['Remarks']          ?? '');

    $schedDates   = $_POST['sched_date']   ?? [];
    $schedAmounts = $_POST['sched_amount'] ?? [];
    $schedule = [];
    foreach ($schedDates as $i => $d) {
        $d   = trim($d);
        $amt = floatval($schedAmounts[$i] ?? 0);
        if ($d === '' || $amt <= 0) continue;
        $schedule[] = ['date' => $d, 'amount' => $amt];
    }

    if ($Amount === '' || !is_numeric($Amount) || floatval($Amount) <= 0)
        $errors[] = 'Please enter a valid amount.';
    if ($AssignedApproverID === '')
        $errors[] = 'Please select an approver.';
    if (empty($schedule))
        $errors[] = 'At least one payment schedule date is required.';

    if (!empty($schedule) && $Amount !== '') {
        $schedTotal = array_sum(array_column($schedule, 'amount'));
        if (abs($schedTotal - floatval($Amount)) > 0.01) {
            $errors[] = 'Payment schedule total (₱' . number_format($schedTotal, 2)
                       . ') must equal the amount (₱' . number_format(floatval($Amount), 2) . ').';
        }
    }

    // Re-check status hasn't moved on us between page load and submit
    if (empty($errors)) {
        $chk = sqlsrv_query($conn, "SELECT Status FROM TBL_CashAdvance WHERE CashAdvanceID = ?", [$id]);
        $chkRow = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
        if (!$chkRow || !in_array($chkRow['Status'], ['Requested', 'Approved'])) {
            $errors[] = 'This request can no longer be edited — its status has changed.';
        }
    }

    if (empty($errors)) {
        $upd = sqlsrv_query($conn, "
            UPDATE TBL_CashAdvance SET
                Amount = ?, Reason = ?, AssignedApproverID = ?, RecommendRemarks = ?, Remarks = ?,
                ModifiedBy = ?, ModifiedDate = GETDATE()
            WHERE CashAdvanceID = ?",
            [floatval($Amount), $Reason, $AssignedApproverID, $RecommendRemarks, $Remarks,
             $_SESSION['UserID'], $id]);

        if ($upd === false) {
            $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
        } else {
            // Schedule is manually re-entered on every edit — safe to fully replace
            // since edit is only allowed pre-Received (no payments linked yet).
            sqlsrv_query($conn, "DELETE FROM TBL_CashAdvance_Statement WHERE CashAdvanceID = ?", [$id]);
            $schedOk = true;
            foreach ($schedule as $s) {
                $ins = sqlsrv_query($conn,
                    "INSERT INTO TBL_CashAdvance_Statement (CashAdvanceID, Due_Date, Amortization_Amount) VALUES (?, ?, ?)",
                    [$id, $s['date'], $s['amount']]);
                if ($ins === false) { $schedOk = false; break; }
            }
            if (!$schedOk) {
                $errors[] = 'Amount was updated but the schedule failed to save. Please review request #' . $id . '.';
            } else {
                $success = true;
                // Refresh $ca for display after a successful save
                $ca['Amount'] = floatval($Amount);
                $ca['Reason'] = $Reason;
                $ca['AssignedApproverID'] = $AssignedApproverID;
                $ca['RecommendRemarks'] = $RecommendRemarks;
                $ca['Remarks'] = $Remarks;
                $schedRepop = $schedule;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Cash Advance #<?= $id ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .form-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                 max-width:680px; overflow:hidden; }
    .form-card-header { padding:.75rem 1.25rem; border-bottom:1px solid var(--border);
                        display:flex; align-items:center; gap:.5rem; font-weight:700;
                        font-size:.88rem; color:var(--text-main); background:var(--surface-alt,#f8fafc); }
    .form-card-header i { color:var(--primary); }
    .form-card-body { padding:1.25rem; display:flex; flex-direction:column; gap:1rem; }
    .fg { display:flex; flex-direction:column; gap:.3rem; }
    .fg label { font-size:.78rem; font-weight:700; color:var(--text-muted);
                text-transform:uppercase; letter-spacing:.04em; }
    .fg .req { color:#ef4444; margin-left:2px; }
    .fg input, .fg textarea, .fg select {
      padding:.48rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
      font-size:.88rem; color:var(--text-main); background:var(--surface);
      transition:border-color .15s; font-family:inherit; width:100%; }
    .fg input:focus, .fg textarea:focus, .fg select:focus {
      outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(124,58,237,.1); }
    .fg textarea { resize:vertical; min-height:64px; }
    .fg .hint { font-size:.74rem; color:var(--text-muted); display:flex; align-items:center; gap:4px; }
    .section-label { font-size:.7rem; font-weight:700; text-transform:uppercase;
                     letter-spacing:.06em; color:var(--primary); padding-bottom:.4rem;
                     border-bottom:1px solid var(--border); margin-top:.25rem; }
    .sched-row { display:flex; gap:.5rem; align-items:center; }
    .sched-row input[type="date"] { flex:1.2; }
    .sched-row input[type="number"] { flex:1; }
    .sched-row .btn-remove-row {
      flex:0 0 auto; width:34px; height:34px; border-radius:var(--radius);
      border:1px solid var(--border); background:var(--surface); color:#ef4444;
      display:flex; align-items:center; justify-content:center; cursor:pointer;
    }
    .sched-row .btn-remove-row:hover { background:rgba(239,68,68,.08); }
    .banner-orig { background:var(--surface-alt,#f8fafc); border:1px solid var(--border);
                   border-radius:var(--radius); padding:.6rem .85rem; font-size:.82rem;
                   color:var(--text-muted); margin-bottom:.5rem; }
  </style>
</head>
<body>
<?php $topbar_page = 'cash_advance_record'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title"><i class="bi bi-pencil-square me-2" style="color:var(--primary);"></i>Edit Cash Advance #<?= $id ?></div>
      <div class="page-subtitle">Requested by: <strong><?= htmlspecialchars($empName) ?></strong></div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-secondary-custom"><i class="bi bi-arrow-left"></i> Back to Record</a>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Changes saved.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <div style="display:flex; justify-content:center;">
  <div class="form-card">
    <div class="form-card-header"><i class="bi bi-pencil-square"></i> Edit Request</div>
    <div class="form-card-body">

      <div class="banner-orig">
        <i class="bi bi-info-circle"></i> Editable only while status is <strong>Requested</strong> or <strong>Approved</strong> — current status: <strong><?= htmlspecialchars($ca['Status']) ?></strong>.
      </div>

      <form method="POST" autocomplete="off">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="save" value="1">

        <div class="section-label">Request Details</div>

        <div class="fg">
          <label>Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="Amount" id="AmountField"
                 value="<?= htmlspecialchars($_POST['Amount'] ?? $ca['Amount']) ?>" required>
        </div>

        <div class="fg">
          <label>Reason / Purpose</label>
          <textarea name="Reason"><?= htmlspecialchars($_POST['Reason'] ?? $ca['Reason']) ?></textarea>
        </div>

        <div class="section-label" style="margin-top:.5rem;">Approval</div>

        <div class="fg">
          <label>Approver <span class="req">*</span></label>
          <select name="ApproverID" required>
            <option value="">— Select approver —</option>
            <?php foreach ($approvers as $ap):
              $apName = trim($ap['FirstName'] . ' ' . $ap['LastName']);
              $apId   = $ap['EmployeeID'];
              $current = $_POST['ApproverID'] ?? $ca['AssignedApproverID'] ?? '';
              $sel    = ($current === $apId) ? 'selected' : '';
            ?>
              <option value="<?= htmlspecialchars($apId) ?>" <?= $sel ?>>
                <?= htmlspecialchars($apName) ?><?= $ap['Position_held'] ? ' — ' . htmlspecialchars($ap['Position_held']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="fg">
          <label>Recommendation Remarks</label>
          <input type="text" name="RecommendRemarks"
                 value="<?= htmlspecialchars($_POST['RecommendRemarks'] ?? $ca['RecommendRemarks']) ?>">
        </div>

        <div class="section-label" style="margin-top:.5rem;">Additional</div>

        <div class="fg">
          <label>Remarks</label>
          <input type="text" name="Remarks" value="<?= htmlspecialchars($_POST['Remarks'] ?? $ca['Remarks']) ?>">
        </div>

        <div class="section-label" style="margin-top:.5rem;">Payment Schedule <span class="req">*</span></div>
        <div class="fg .hint" style="font-size:.74rem; color:var(--text-muted); margin-bottom:.25rem;">
          <i class="bi bi-info-circle"></i> Re-enter the schedule to match the (possibly adjusted) amount above. Existing rows are pre-filled below.
        </div>

        <div id="schedRows" style="display:flex; flex-direction:column; gap:.5rem;"></div>

        <button type="button" class="btn btn-secondary-custom" style="align-self:flex-start;" onclick="addSchedRow()">
          <i class="bi bi-plus-lg"></i> Add Date
        </button>

        <div id="schedTotalRow" style="display:flex; justify-content:space-between; align-items:center;
             padding:.6rem .85rem; border-radius:var(--radius); background:var(--surface-alt,#f8fafc);
             border:1px solid var(--border); font-size:.85rem; font-weight:700;">
          <span>Schedule Total</span>
          <span id="schedTotalVal">₱ 0.00</span>
        </div>
        <div id="schedTotalWarn" style="display:none; color:#ef4444; font-size:.78rem;">
          <i class="bi bi-exclamation-triangle-fill"></i> Schedule total must match the amount.
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:.5rem;">
          <button type="submit" class="btn btn-add"><i class="bi bi-floppy-fill"></i> Save Changes</button>
        </div>

      </form>
    </div>
  </div>
  </div><!-- /centering wrapper -->

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.querySelector('form').addEventListener('submit', e => {
    if (!validateSchedTotal()) {
        e.preventDefault();
        alert('Payment schedule total must equal the amount before saving.');
    }
});

// ── Payment Schedule builder (same pattern as create.php) ──────
const schedRows      = document.getElementById('schedRows');
const amountInput    = document.getElementById('AmountField');
const schedTotalVal  = document.getElementById('schedTotalVal');
const schedTotalWarn = document.getElementById('schedTotalWarn');
const initialSchedule = <?= json_encode($schedRepop) ?>;

function addSchedRow(date = '', amount = '') {
    const row = document.createElement('div');
    row.className = 'sched-row';
    row.innerHTML = `
      <input type="date" name="sched_date[]" value="${date}" required>
      <input type="number" step="0.01" min="0.01" name="sched_amount[]" placeholder="0.00" value="${amount}" required>
      <button type="button" class="btn-remove-row" title="Remove"><i class="bi bi-trash"></i></button>
    `;
    row.querySelector('.btn-remove-row').addEventListener('click', () => {
        row.remove();
        updateSchedTotal();
    });
    row.querySelectorAll('input').forEach(inp => inp.addEventListener('input', updateSchedTotal));
    schedRows.appendChild(row);
    updateSchedTotal();
}

function updateSchedTotal() {
    let total = 0;
    schedRows.querySelectorAll('input[name="sched_amount[]"]').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    schedTotalVal.textContent = '₱ ' + total.toFixed(2);
    validateSchedTotal();
}

function validateSchedTotal() {
    const amt = parseFloat(amountInput.value) || 0;
    let total = 0;
    schedRows.querySelectorAll('input[name="sched_amount[]"]').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    const rowCount = schedRows.querySelectorAll('.sched-row').length;
    const ok = rowCount > 0 && Math.abs(total - amt) < 0.01;
    schedTotalWarn.style.display = ok ? 'none' : '';
    return ok;
}

amountInput.addEventListener('input', validateSchedTotal);

if (initialSchedule.length) {
    initialSchedule.forEach(s => addSchedRow(s.date, s.amount));
} else {
    addSchedRow();
}
</script>
</body>
</html>