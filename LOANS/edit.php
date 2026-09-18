<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (rbac_is_view_only('employee_loans')) {
    header("Location: " . base_url('employee_loans/view.php?id=' . (int)($_GET['id'] ?? 0)));
    exit;
}

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) { header("Location: index.php"); exit; }

// ── Fetch loan ───────────────────────────────────────────────
$res  = sqlsrv_query($conn, "SELECT * FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
$loan = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
if (!$loan) { echo "Loan not found."; exit; }

// Editing is only allowed while a loan is in Proposal status. Once it's
// Approved / Fully Paid / Cancelled, the record is locked — send the
// user to the read-only detail view instead.
if (trim($loan['Status'] ?? '') !== 'Proposal') {
    header("Location: " . base_url('EMPLOYEE/view.php?id=' . $loan_id . '&error=locked'));
    exit;
}

// Employee info
$emp_res = sqlsrv_query($conn,
    "SELECT LastName, FirstName, MiddleName, Department, Position_held, EmployeeID
     FROM TBL_HREmployeeList WHERE EmployeeID = ?", [$loan['EmployeeID']]);
$emp = $emp_res ? sqlsrv_fetch_array($emp_res, SQLSRV_FETCH_ASSOC) : [];
$full_name = trim(($emp['LastName'] ?? '') . ', ' . ($emp['FirstName'] ?? '') . ' ' . ($emp['MiddleName'] ?? ''));

// Noted By / Approved By names (read-only display — set at creation, not editable here)
function twm_lookup_employee_name($conn, $employee_id) {
    if (!$employee_id) return '—';
    $r = sqlsrv_query($conn,
        "SELECT LastName, FirstName, MiddleName FROM TBL_HREmployeeList WHERE EmployeeID = ?",
        [$employee_id]);
    $row = $r ? sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) return '—';
    return trim(($row['LastName'] ?? '') . ', ' . ($row['FirstName'] ?? '') . ' ' . ($row['MiddleName'] ?? ''));
}
$noted_by_name    = twm_lookup_employee_name($conn, $loan['NotedByID'] ?? null);
$approved_by_name = twm_lookup_employee_name($conn, $loan['ApprovedByID'] ?? null);

// Loan types
$types_q    = sqlsrv_query($conn, "SELECT ID, TypeName FROM TBL_Loan_Type WHERE Active = 1 ORDER BY TypeName");
$loan_types = [];
if ($types_q !== false) while ($r = sqlsrv_fetch_array($types_q, SQLSRV_FETCH_ASSOC)) $loan_types[] = $r;

// Schedule
$sched_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount, p.PaymentMethod, p.ReferenceNumber AS PayRef
    FROM TBL_Loan_Statement s
    LEFT JOIN TBL_Loan_Payment p ON p.LoanPaymentID = s.PaymentID
    WHERE s.LoanID = ? ORDER BY s.Due_Date ASC, s.StatementID ASC", [$loan_id]);
$schedule = [];
if ($sched_res) while ($r = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $r;

$loan_date = ($loan['LoanDate'] instanceof DateTime) ? $loan['LoanDate']->format('Y-m-d') : ($loan['LoanDate'] ?? date('Y-m-d'));
$error     = '';


  $first_amort     = !empty($schedule[0]) ? (float)($schedule[0]['Amortization_Amount'] ?? 0) : 0;
  $cutoff_amt_disp = $first_amort;
  $monthly_disp    = $first_amort * 2;

// CutOff is stored as a [Freq:...] tag at the start of Remarks (TBL_Loan.CutOff is an int
// column and can't hold frequency labels). Parse it back out for the form fields.
$remarks_raw   = $loan['Remarks'] ?? '';
$cutoff_saved  = '';
$remarks_clean = $remarks_raw;
if (preg_match('/^\[Freq:([^\]]*)\]\s?(.*)$/s', $remarks_raw, $rm)) {
    $cutoff_saved  = $rm[1];
    $remarks_clean = $rm[2];
}


// ── Handle header UPDATE ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_header') {
    // Re-check status server-side — a Proposal could have been approved by
    // someone else in another tab between page load and this submit.
    $cur_chk = sqlsrv_query($conn, "SELECT Status FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
    $cur_row = $cur_chk ? sqlsrv_fetch_array($cur_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$cur_row || trim($cur_row['Status'] ?? '') !== 'Proposal') {
        header("Location: " . base_url('EMPLOYEE/view.php?id=' . $loan_id . '&error=locked'));
        exit;
    }

    $loan_type    = (int)$_POST['loan_type'];
    $ref_number   = trim($_POST['ref_number']);
    $description  = trim($_POST['description']);
    $loan_amount  = floatval($_POST['loan_amount']);
    $terms        = (int)$_POST['terms'];
    $terms_amount = floatval($_POST['terms_amount']);
    $cutoff       = trim($_POST['cutoff']);
    $cutoff_amt   = floatval($_POST['cutoff_amount']);
    $loan_date_u  = trim($_POST['loan_date']);
    // NOTE: Status is intentionally NOT read from $_POST here. Approval is a
    // separate, deliberate action handled by approve.php — the edit form can
    // no longer change status, even if a stray 'status' field were submitted.
    $remarks      = trim($_POST['remarks']);
    $user         = $_SESSION['EmployeeID'] ?? $_SESSION['Username'] ?? 'system';

    // NOTE: TBL_Loan.CutOff is an int column — it cannot hold frequency labels like
    // "Weekly" / "15th & 30th" / "Specific Date". Store the frequency as a [Freq:...]
    // tag prefix on Remarks instead (print.php / view.php parse it back out).
    $remarks_full = $cutoff !== '' ? "[Freq:{$cutoff}]" . ($remarks !== '' ? " {$remarks}" : '') : $remarks;

    $upd = "UPDATE TBL_Loan SET
        LoanType=?, ReferenceNumber=?, Description=?, LoanAmount=?,
        Terms=?, TermsAmount=?, CutOff_Amount=?,
        LoanDate=?, Remarks=?, RemarksBy=?,
        UserUpdate=?, UpdateDateTime=GETDATE()
        WHERE LoanID=? AND Status='Proposal'";
    $p = [$loan_type, $ref_number, $description, $loan_amount,
          $terms, $terms_amount, $cutoff_amt,
          $loan_date_u, $remarks_full, $user,
          $user, $loan_id];
    $r2 = sqlsrv_query($conn, $upd, $p);
    if ($r2 === false) {
        $errs  = sqlsrv_errors() ?: [];
        $error = implode(' ', array_column($errs, 'message')) ?: 'Update failed.';
    } else {
        header("Location: view.php?id=$loan_id&updated=1"); exit;
    }
}

// ── Handle schedule UPDATE (dates & amounts only — no payment logic here) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_schedule') {
    // Same re-check as update_header — schedule edits are also Proposal-only.
    $cur_chk = sqlsrv_query($conn, "SELECT Status FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
    $cur_row = $cur_chk ? sqlsrv_fetch_array($cur_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$cur_row || trim($cur_row['Status'] ?? '') !== 'Proposal') {
        header("Location: " . base_url('EMPLOYEE/view.php?id=' . $loan_id . '&error=locked'));
        exit;
    }

    $user = $_SESSION['EmployeeID'] ?? $_SESSION['Username'] ?? 'system';

    if (!empty($_POST['sched_id']) && is_array($_POST['sched_id'])) {
        foreach ($_POST['sched_id'] as $i => $stmt_id) {
            $stmt_id = (int)$stmt_id;
            if (!$stmt_id) continue;

            // Find whether this row already has a linked payment — if so, don't touch its amounts.
            $is_paid = false;
            foreach ($schedule as $s) {
                if ((int)$s['StatementID'] === $stmt_id) { $is_paid = !empty($s['PaymentID']); break; }
            }

            $month_name = trim($_POST['sched_month'][$i] ?? '');
            $due_date   = trim($_POST['sched_due'][$i] ?? '') ?: null;

            if ($is_paid) {
                // Paid rows: only the label/date may be adjusted, never the money fields.
                sqlsrv_query($conn,
                    "UPDATE TBL_Loan_Statement SET MonthName = ?, Due_Date = ? WHERE StatementID = ?",
                    [$month_name, $due_date, $stmt_id]);
                continue;
            }

            $opb       = floatval($_POST['sched_opb'][$i] ?? 0);
            $principal = floatval($_POST['sched_principal'][$i] ?? 0);
            $interest  = floatval($_POST['sched_int'][$i] ?? 0);
            $amort     = floatval($_POST['sched_amort'][$i] ?? 0);

            sqlsrv_query($conn, "
                UPDATE TBL_Loan_Statement SET
                    MonthName = ?, Due_Date = ?, OPB = ?,
                    Pricipal_Amount = ?, Interest_Amount = ?, Amortization_Amount = ?
                WHERE StatementID = ?",
                [$month_name, $due_date, $opb, $principal, $interest, $amort, $stmt_id]);
        }
    }

    sqlsrv_query($conn, "UPDATE TBL_Loan SET UserUpdate = ?, UpdateDateTime = GETDATE() WHERE LoanID = ?", [$user, $loan_id]);

    header("Location: edit.php?id=$loan_id&sched_updated=1"); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Loan · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .form-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                 margin-bottom:1.25rem; overflow:hidden; }
    .form-card-header { padding:.75rem 1.25rem; border-bottom:1px solid var(--border);
                        display:flex; align-items:center; gap:.5rem; font-weight:700;
                        font-size:.88rem; color:var(--text-main); background:var(--surface-alt,#f8fafc); }
    .form-card-header i { color:var(--primary); }
    .form-card-body { padding:1.25rem; }
    .form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:1rem; }
    .form-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
    .form-group { display:flex; flex-direction:column; gap:.3rem; }
    .form-group label { font-size:.78rem; font-weight:700; color:var(--text-muted);
                        text-transform:uppercase; letter-spacing:.04em; }
    .form-group input, .form-group select, .form-group textarea {
      padding:.48rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
      font-size:.88rem; color:var(--text-main); background:var(--surface); transition:border-color .15s; }
    .form-group input:focus, .form-group select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    .form-group input[readonly] { background:var(--surface-alt,#f8fafc); color:var(--text-muted); }

    /* Schedule */
    .sched-table { width:100%; border-collapse:collapse; font-size:.84rem; }
    .sched-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .sched-table th { padding:.6rem .75rem; font-size:.73rem; font-weight:700;
                      text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .sched-table th.num { text-align:right; }
    .sched-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .sched-table .num { text-align:right; font-weight:600; }
    .paid-row { background:rgba(16,185,129,.05); }
    .paid-chip  { display:inline-flex; align-items:center; gap:.25rem;
                  background:rgba(16,185,129,.12); color:#065f46;
                  padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .unpaid-chip { display:inline-flex; align-items:center; gap:.25rem;
                   background:rgba(245,158,11,.12); color:#b45309;
                   padding:.15rem .55rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .btn-pay { font-size:.76rem; padding:.25rem .65rem; }
    .action-wrap { display:flex; gap:.3rem; align-items:center; }
    .btn-icon { width:28px; height:28px; border-radius:6px; border:1px solid var(--border);
                display:inline-flex; align-items:center; justify-content:center;
                font-size:.78rem; cursor:pointer; background:var(--surface); color:var(--text-muted);
                transition:background .15s; }
    .btn-icon.del { color:#ef4444; border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.07); }
    .btn-icon.del:hover { background:rgba(239,68,68,.18); }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">Edit: <?= htmlspecialchars($loan['ReferenceNumber'] ?? 'Loan') ?></div>
      <div class="page-subtitle">Update loan details and schedule · Payments are recorded on the Payments page</div>
    </div>
    <a href="<?= base_url('EMPLOYEE/view.php?id=' . $loan_id) ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Cancel
    </a>
  </div>

  <?php if (isset($_GET['sched_updated'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Schedule updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" id="loan-form">
    <input type="hidden" name="action" value="update_header">

    <!-- Loan Details -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-file-earmark-text-fill"></i> Loan Details</div>
      <div class="form-card-body">
        <div class="form-grid-4" style="margin-bottom:1rem;">
          <div class="form-group">
            <label>Reference No.</label>
            <input type="text" name="ref_number" value="<?= htmlspecialchars($loan['ReferenceNumber'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Loan Date</label>
            <input type="date" name="loan_date" value="<?= $loan_date ?>">
          </div>
          <div class="form-group">
            <label>Loan Type *</label>
            <select name="loan_type" required>
              <?php foreach ($loan_types as $lt): ?>
                <option value="<?= $lt['ID'] ?>" <?= $loan['LoanType'] == $lt['ID'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lt['TypeName']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <input type="text" value="<?= htmlspecialchars($loan['Status'] ?? '') ?>" readonly>
            <small style="color:var(--text-muted);font-size:.72rem;">
              Status changes via the Approve action on the loan's detail page.
            </small>
          </div>
        </div>
        <div class="form-grid-4" style="margin-bottom:1rem;">
          <div class="form-group">
            <label>Loan Amount (₱)</label>
            <input type="number" name="loan_amount" step="0.01" value="<?= $loan['LoanAmount'] ?? 0 ?>">
          </div>
          <div class="form-group">
            <label>Terms (months)</label>
            <input type="number" name="terms" min="1" value="<?= $loan['Terms'] ?? 0 ?>">
          </div>
          <div class="form-group">
            <label>Monthly Amortization (₱)</label>
            <input type="number" name="terms_amount" value="<?= number_format($monthly_disp, 2, '.', '') ?>">
          </div>
          <div class="form-group">
            <label>Cut-Off</label>
            <select name="cutoff">
              <option value="">-- Select --</option>
              <?php foreach (['Weekly','15th','30th','15th & 30th','Specific Date'] as $co): ?>
                <option value="<?= $co ?>" <?= $cutoff_saved === $co ? 'selected' : '' ?>><?= $co ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-grid-3">
          <div class="form-group">
            <label>Cut-Off Amount (₱)</label>
            <input type="number" name="cutoff_amount" value="<?= number_format($cutoff_amt_disp, 2, '.', '') ?>">
          </div>
          <div class="form-group">
            <label>Noted By</label>
            <input type="text" value="<?= htmlspecialchars($noted_by_name) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Approved By</label>
            <input type="text" value="<?= htmlspecialchars($approved_by_name) ?>" readonly>
          </div>
        </div>
        <div class="form-group" style="margin-top:1rem;">
          <label>Description</label>
          <textarea name="description" rows="2"><?= htmlspecialchars($loan['Description'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin-top:.75rem;">
          <label>Remarks</label>
          <textarea name="remarks" rows="2"><?= htmlspecialchars($remarks_clean) ?></textarea>
        </div>
      </div>
    </div>

    <!-- Employee (read-only in edit) -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-person-fill"></i> Employee</div>
      <div class="form-card-body">
        <div class="form-grid-2">
          <div class="form-group">
            <label>Employee</label>
            <input type="text" value="<?= htmlspecialchars($full_name) ?>" readonly>
          </div>
          <div class="form-group">
            <label>Department</label>
            <input type="text" value="<?= htmlspecialchars($emp['Department'] ?? '') ?>" readonly>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-bottom:1.25rem;">
      <a href="<?= base_url('EMPLOYEE/view.php?id=' . $loan_id) ?>" class="btn btn-secondary-custom">
        <i class="bi bi-x-lg"></i> Cancel
      </a>
      <button type="submit" class="btn btn-add">
        <i class="bi bi-floppy-fill"></i> Save Changes
      </button>
    </div>
  </form>

  <!-- Amortization Schedule (fields & dates editable here; payments are recorded on a separate page) -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-calendar3" style="color:var(--primary-light);"></i>
        Amortization Schedule
        <span class="count-chip"><?= count($schedule) ?> rows</span>
      </div>
    </div>
    <form method="POST" id="schedule-form">
      <input type="hidden" name="action" value="update_schedule">
      <div class="table-responsive">
        <table class="sched-table">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th>Month</th>
              <th>Due Date</th>
              <th class="num">OPB (₱)</th>
              <th class="num">Principal (₱)</th>
              <th class="num">Interest (₱)</th>
              <th class="num">Amortization (₱)</th>
              <th style="text-align:center; width:140px;">Payment Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($schedule)): ?>
              <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted);">
                No schedule rows. Edit the loan terms above and re-save to regenerate, or add rows via the DB.
              </td></tr>
            <?php else: ?>
              <?php foreach ($schedule as $i => $row):
                $paid    = !empty($row['PaymentID']);
                $raw_due = $row['Due_Date'] ?? '';
                $due_val = ($raw_due instanceof DateTime) ? $raw_due->format('Y-m-d') : (is_string($raw_due) ? $raw_due : '');
                $month_v = ($row['MonthName'] instanceof DateTime) ? $row['MonthName']->format('F Y') : ($row['MonthName'] ?? '');
              ?>
              <tr class="<?= $paid ? 'paid-row' : '' ?>">
                <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;">
                  <?= $i + 1 ?>
                  <input type="hidden" name="sched_id[]" value="<?= (int)$row['StatementID'] ?>">
                </td>
                <td><input type="text" name="sched_month[]" value="<?= htmlspecialchars($month_v) ?>" <?= $paid ? 'readonly' : '' ?>></td>
                <td><input type="date" name="sched_due[]" value="<?= htmlspecialchars($due_val) ?>" <?= $paid ? 'readonly' : '' ?>></td>
                <td class="num"><input type="number" step="0.01" name="sched_opb[]" value="<?= number_format((float)($row['OPB'] ?? 0), 2, '.', '') ?>" readonly></td>
                <td class="num"><input type="number" step="0.01" name="sched_principal[]" value="<?= number_format((float)($row['Pricipal_Amount'] ?? 0), 2, '.', '') ?>" <?= $paid ? 'readonly' : '' ?>></td>
                <td class="num"><input type="number" step="0.01" name="sched_int[]" value="<?= number_format((float)($row['Interest_Amount'] ?? 0), 2, '.', '') ?>" <?= $paid ? 'readonly' : '' ?>></td>
                <td class="num"><input type="number" step="0.01" name="sched_amort[]" value="<?= number_format((float)($row['Amortization_Amount'] ?? 0), 2, '.', '') ?>" <?= $paid ? 'readonly' : '' ?>></td>
                <td style="text-align:center;">
                  <?php if ($paid): ?>
                    <span class="paid-chip"><i class="bi bi-check-circle-fill"></i>
                      ₱ <?= number_format((float)($row['PaymentAmount'] ?? 0), 2) ?>
                    </span>
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
      <?php if (!empty($schedule)): ?>
      <div style="display:flex;justify-content:flex-end;padding:1rem 1.25rem;border-top:1px solid var(--border);">
        <button type="submit" class="btn btn-add">
          <i class="bi bi-floppy-fill"></i> Save Schedule Changes
        </button>
      </div>
      <?php endif; ?>
    </form>
  </div>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>