<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (rbac_is_view_only('employee_loans')) {
    header("Location: " . base_url('EMPLOYEE/index.php'));
    exit;
}

// ── AJAX: next reference number by loan type code ────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'next_ref') {
    header('Content-Type: application/json');
    $code = trim($_GET['code'] ?? '');
    if (!$code) { echo json_encode(['ref' => '']); exit; }

    $year   = date('Y');
    $prefix = $code . '-' . $year . '-%';

    $res = sqlsrv_query($conn,
        "SELECT TOP 1 ReferenceNumber FROM TBL_Loan
         WHERE ReferenceNumber LIKE ?
         ORDER BY LoanID DESC",
        [$prefix]);

    $next_no = 1;
    if ($res) {
        $row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
        if ($row && preg_match('/(\d+)$/', $row['ReferenceNumber'], $m)) {
            $next_no = (int)$m[1] + 1;
        }
    }

    $ref = $code . '-' . $year . '-' . str_pad($next_no, 4, '0', STR_PAD_LEFT);
    echo json_encode(['ref' => $ref]);
    exit;
}

// ── AJAX: employee search ────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_employee') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $res = sqlsrv_query($conn,
        "SELECT TOP 15 EmployeeID, LastName, FirstName, MiddleName, Department, Position_held
         FROM TBL_HREmployeeList
         WHERE Active = 1 AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ?)
         ORDER BY LastName, FirstName",
        [$q, $q, $q]);
    $rows = [];
    if ($res) while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    echo json_encode($rows);
    exit;
}

// ── AJAX: noted-by employee search ──────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_noted_by') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $res = sqlsrv_query($conn,
        "SELECT TOP 15 EmployeeID, LastName, FirstName, MiddleName, Job_tittle
         FROM TBL_HREmployeeList
         WHERE Active = 1 AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ?)
         ORDER BY LastName, FirstName",
        [$q, $q, $q]);
    $rows = [];
    if ($res) while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    echo json_encode($rows);
    exit;
}

// ── AJAX: approved-by employee search ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_approved_by') {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $res = sqlsrv_query($conn,
        "SELECT TOP 15 EmployeeID, LastName, FirstName, MiddleName, Job_tittle
         FROM TBL_HREmployeeList
         WHERE Active = 1 AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ?)
         ORDER BY LastName, FirstName",
        [$q, $q, $q]);
    $rows = [];
    if ($res) while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
    echo json_encode($rows);
    exit;
}

// ── Loan types ───────────────────────────────────────────────
$types_q    = sqlsrv_query($conn, "SELECT ID, TypeName, Code FROM TBL_Loan_Type WHERE Active = 1 ORDER BY TypeName");
$loan_types = [];
if ($types_q !== false) while ($r = sqlsrv_fetch_array($types_q, SQLSRV_FETCH_ASSOC)) $loan_types[] = $r;

// ── Auto-generate reference number (fallback until loan type is chosen) ──
$new_ref = '';

$error = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id  = trim($_POST['employee_id']);
    $department   = trim($_POST['department']);
    $loan_type    = (int)$_POST['loan_type'];
    $ref_number   = trim($_POST['ref_number']);
    $description  = trim($_POST['description']);
    $loan_amount  = floatval($_POST['loan_amount']);
    $terms        = (int)$_POST['terms'];
    $terms_amount = floatval($_POST['terms_amount']);
    $cutoff       = trim($_POST['cutoff']);
    $cutoff_amt   = floatval(!empty($_POST['cutoff_amount']) ? $_POST['cutoff_amount'] : ($_POST['terms_amount'] ?? 0));
    $loan_date    = trim($_POST['loan_date']);
    $status       = 'Proposal';
    $remarks      = trim($_POST['remarks']);
    $noted_by_id    = trim($_POST['noted_by_id'] ?? '');
    $approved_by_id = trim($_POST['approved_by_id'] ?? '');

    if (!$employee_id || !$loan_type || !$loan_amount || !$terms) {
        $error = "Employee, Loan Type, Loan Amount, and Terms are required.";
    } else {
        $cutoff_map = [
            'Weekly'        => 4,
            '15th'          => 1,
            '30th'          => 1,
            '15th & 30th'   => 2,
            'Specific Date' => count(array_filter($_POST['sched_due'] ?? [], fn($d) => trim($d) !== '')),
        ];
        $cutoff_int = $cutoff_map[$cutoff] ?? null;

        $ins = "INSERT INTO TBL_Loan
            (EmployeeID, Department, LoanType, ReferenceNumber, Description,
             LoanAmount, Terms, TermsAmount, CutOff, CutOff_Amount,
             LoanDate, PaidAmount, BalanceAmount, Status, Remarks, RemarksBy, NotedByID, ApprovedByID,
             UserInput, InputDateTime)
            OUTPUT INSERTED.LoanID
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,GETDATE())";

        $p = [
            $employee_id, $department, $loan_type, $ref_number, $description,
            $loan_amount, $terms, $terms_amount, $cutoff_int, $cutoff_amt ?: null,
            $loan_date, $loan_amount, $status, $cutoff ?: $remarks ?: null, $_SESSION['EmployeeID'] ?? null, $noted_by_id ?: null, $approved_by_id ?: null,
            $_SESSION['EmployeeID']
        ];

        $res2 = sqlsrv_query($conn, $ins, $p);
        if ($res2 === false) {
            $errs  = sqlsrv_errors() ?: [];
            $error = implode(' ', array_column($errs, 'message')) ?: 'Insert failed.';
        } else {
            $id_row  = sqlsrv_fetch_array($res2, SQLSRV_FETCH_ASSOC);
            $loan_id = $id_row['LoanID'];

            // Insert amortization schedule rows
            $sched_errors = [];
            if (!empty($_POST['sched_due']) && is_array($_POST['sched_due'])) {
                foreach ($_POST['sched_due'] as $i => $due_date) {
                    $principal = $_POST['sched_principal'][$i] ?? '';
                    if (trim($due_date) === '') continue;

                    // Sanitize date — must be exactly YYYY-MM-DD
                    $due_clean = trim($due_date);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_clean)) continue;

                    // MonthName is a `date` column in TBL_Loan_Statement — NOT a varchar.
                    // Pass $due_clean for it too; both MonthName and Due_Date get the same
                    // date value. The label the user typed is intentionally discarded here
                    // because the column cannot store strings.
                    $due_safe = $due_clean; // validated YYYY-MM-DD, safe to inline

                    $sched_res = sqlsrv_query($conn,
                        "INSERT INTO TBL_Loan_Statement
                            (LoanID, MonthName, Applicable_Terms, Due_Date,
                             Amortization_Amount, Interest_Amount, Pricipal_Amount, OPB,
                             UserInput, InputDateTime)
                         VALUES (?,CAST('$due_safe' AS date),?,CAST('$due_safe' AS date),?,?,?,?,?,GETDATE())",
                        [
                            (int)$loan_id,
                            (int)($_POST['sched_term'][$i] ?? ($i + 1)),
                            floatval($_POST['sched_amort'][$i]   ?? $terms_amount),
                            floatval($_POST['sched_int'][$i]     ?? 0),
                            floatval($principal !== '' ? $principal : $terms_amount),
                            floatval($_POST['sched_opb'][$i]     ?? 0),
                            $_SESSION['EmployeeID'] ?? $_SESSION['Username'] ?? 'system'
                        ]
                    );

                    if ($sched_res === false) {
                        $errs = sqlsrv_errors() ?: [];
                        $sched_errors[] = 'Row ' . ($i + 1) . ': ' . (implode(' ', array_column($errs, 'message')) ?: 'Insert failed');
                    }
                }
            }

            if (!empty($sched_errors)) {
                $error = 'Loan #' . $loan_id . ' saved but schedule rows failed: ' . implode('; ', $sched_errors);
            } else {
                header("Location: view.php?id=$loan_id&created=1");
                exit;
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
  <title>New Employee Loan · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .form-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                 margin-bottom:1.25rem; }
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
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    .form-group input[readonly] { background:var(--surface-alt,#f8fafc); color:var(--text-muted); }

    .emp-search-wrap { position:relative; }
    .emp-search-wrap input { width:100%; }
    .emp-dropdown { position:absolute; top:100%; left:0; right:0; z-index:999;
                    background:var(--surface); border:1px solid var(--border);
                    border-radius:var(--radius); box-shadow:var(--shadow-sm);
                    max-height:220px; overflow-y:auto; display:none; }
    .emp-option { padding:.55rem .85rem; cursor:pointer; font-size:.85rem;
                  border-bottom:1px solid var(--border); }
    .emp-option:hover { background:var(--surface-alt,#f8fafc); }
    .emp-option .emp-id   { font-size:.74rem; color:var(--text-muted); }
    .emp-option .emp-name { font-weight:600; }
    .emp-option .emp-dept { font-size:.74rem; color:var(--text-muted); }
    .emp-selected { background:rgba(59,130,246,.06); border:1px solid rgba(59,130,246,.3);
                    border-radius:var(--radius); padding:.6rem .85rem; font-size:.88rem;
                    display:none; align-items:center; justify-content:space-between; gap:.5rem; }
    .emp-selected .emp-clear { cursor:pointer; color:var(--text-muted); font-size:.8rem; }
    .emp-selected .emp-clear:hover { color:#ef4444; }

    .sched-tbl { width:100%; border-collapse:collapse; font-size:.84rem; }
    .sched-tbl thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .sched-tbl th { padding:.55rem .7rem; font-size:.73rem; font-weight:700;
                    text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); text-align:left; }
    .sched-tbl th.num { text-align:right; }
    .sched-tbl td { padding:.35rem .5rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .sched-tbl td input {
      width:100%; padding:.3rem .5rem; border:1px solid var(--border);
      border-radius:var(--radius); font-size:.82rem; background:var(--surface); color:var(--text-main); }
    .sched-tbl td input:focus { outline:none; border-color:var(--primary); }
    .sched-tbl td input[readonly] { background:var(--surface-alt,#f8fafc); color:var(--text-muted); font-weight:600; }
    .sched-empty { text-align:center; padding:2rem; color:var(--text-muted); font-size:.85rem; }

    .badge-status { display:inline-flex; align-items:center; gap:.3rem;
                    padding:.22rem .65rem; border-radius:999px; font-size:.75rem; font-weight:700; }
    .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
    .bs-proposal { background:rgba(245,158,11,.12); color:#b45309; }
    .bs-proposal::before { background:#f59e0b; }

    .sched-tbl .sd-row-actions { text-align:center; width:50px; }
    .btn-icon-sm { width:26px; height:26px; border-radius:6px; border:1px solid var(--border);
                   display:inline-flex; align-items:center; justify-content:center; font-size:.74rem;
                   cursor:pointer; background:var(--surface); color:var(--text-muted); }
    .btn-icon-sm.del { color:#ef4444; border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.07); }
    .btn-icon-sm.del:hover { background:rgba(239,68,68,.18); }
    .btn-add-row { font-size:.8rem; padding:.4rem .9rem; }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">New Employee Loan</div>
      <div class="page-subtitle">Fill in the details below to create a new loan record</div>
    </div>
    <a href="<?= base_url('EMPLOYEE/index.php') ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to List
    </a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" id="loan-form">

    <!-- Loan Details -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-file-earmark-text-fill"></i> Loan Details</div>
      <div class="form-card-body">
        <div class="form-grid-4" style="margin-bottom:1rem;">
          <div class="form-group">
            <label>Loan Type *</label>
            <select name="loan_type" id="loan_type" required>
              <option value="">-- Select --</option>
              <?php foreach ($loan_types as $lt): ?>
                <option value="<?= $lt['ID'] ?>" data-code="<?= htmlspecialchars($lt['Code']) ?>"><?= htmlspecialchars($lt['TypeName']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Loan Date *</label>
            <input type="date" name="loan_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Reference No.</label>
            <input type="text" name="ref_number" id="ref_number" value="<?= htmlspecialchars($new_ref) ?>" placeholder="Select a Loan Type first…" readonly required>
          </div>
          <div class="form-group">
            <label>Status</label>
            <span class="badge-status bs-proposal" style="align-self:flex-start;margin-top:.2rem;">Proposal</span>
            <span style="font-size:.72rem;color:var(--text-muted);">New loans start as Proposal. Approval happens on the loan's edit page.</span>
          </div>
        </div>
        <div class="form-group">
          <label>Description / Purpose</label>
          <textarea name="description" rows="2" placeholder="e.g. Emergency cash advance for medical expenses"></textarea>
        </div>
      </div>
    </div>

    <!-- Employee -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-person-fill"></i> Employee</div>
      <div class="form-card-body">
        <div class="form-grid-2">
          <div class="form-group">
            <label>Search Employee *</label>
            <div class="emp-search-wrap">
              <input type="text" id="emp_search" placeholder="Type name or ID to search…" autocomplete="off">
              <div class="emp-dropdown" id="emp_dropdown"></div>
            </div>
            <div class="emp-selected" id="emp_selected">
              <span id="emp_selected_label"></span>
              <span class="emp-clear" onclick="clearEmployee()" title="Clear"><i class="bi bi-x-lg"></i></span>
            </div>
            <input type="hidden" name="employee_id" id="employee_id" required>
          </div>
          <div class="form-group">
            <label>Department</label>
            <input type="text" name="department" id="department" readonly placeholder="Auto-filled">
          </div>
        </div>
      </div>
    </div>

    <!-- Loan Amount & Terms -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-calculator-fill"></i> Loan Amount & Terms</div>
      <div class="form-card-body">
        <div class="form-grid-4" style="margin-bottom:1rem;">
          <div class="form-group">
            <label>Loan Amount (₱) *</label>
            <input type="number" name="loan_amount" id="loan_amount" step="0.01" placeholder="0.00"
                   oninput="updateSchedule()" required>
          </div>
          <div class="form-group">
            <label>Terms *</label>
            <input type="number" name="terms" id="terms" min="1" placeholder="e.g. 24"
                   oninput="updateSchedule()" required>
          </div>
          <div class="form-group">
            <label>Amortization (₱)</label>
            <input type="number" name="terms_amount" id="terms_amount" step="0.01" placeholder="Loan Payment">
          </div>
          <div class="form-group">
            <label>Payment Frequency</label>
            <select name="cutoff" id="cutoff">
              <option value="">-- Select --</option>
              <option value="Weekly">Weekly</option>
              <option value="15th">15th</option>
              <option value="30th">30th</option>
              <option value="15th & 30th">15th &amp; 30th</option>
              <option value="Specific Date">Specific Date (manual)</option>
            </select>
          </div>
        </div>
        <div class="form-grid-4">
          <div class="form-group" id="start_date_group">
            <label>Starting Date *</label>
            <input type="date" id="start_date" name="start_date" oninput="updateSchedule()">
          </div>
          <div class="form-group">
            <label>Amount / Installment (₱) <span style="font-weight:400;text-transform:none;font-size:.74rem;color:var(--text-muted);">— auto-computed</span></label>
            <input type="number" name="cutoff_amount" id="cutoff_amount" step="0.01" placeholder="Auto-computed" readonly>
          </div>
        </div>
      </div>
    </div>

    <!-- Amortization Schedule -->
    <div class="form-card">
      <div class="form-card-header">
        <i class="bi bi-calendar3"></i> Amortization Schedule
        <span style="margin-left:auto;font-size:.78rem;color:var(--text-muted);font-weight:400;" id="sched_mode_label">
          Auto-generated · editable
        </span>
      </div>
      <div class="form-card-body" style="padding:0;">
        <div class="table-responsive">
          <table class="sched-tbl">
            <thead>
              <tr>
                <th style="width:45px;">#</th>
                <th style="width:130px;">Month</th>
                <th style="width:110px;">Due Date</th>
                <th class="num" style="width:130px;">OPB (₱)</th>
                <th class="num" style="width:130px;">Principal (₱)</th>
                <th class="num" style="width:120px;">Interest (₱)</th>
                <th class="num" style="width:130px;">Amortization (₱)</th>
              </tr>
            </thead>
            <tbody id="sched_body">
              <tr><td colspan="7" class="sched-empty">
                Fill in Loan Amount and Terms above to generate the schedule.
              </td></tr>
            </tbody>
          </table>
        </div>
        <div id="add_row_wrap" style="display:none;padding:.85rem 1.25rem;border-top:1px solid var(--border);">
          <button type="button" class="btn btn-secondary-custom btn-add-row" onclick="addManualRow()">
            <i class="bi bi-plus-lg"></i> Add Installment
          </button>
        </div>
      </div>
    </div>

    <!-- Signatories / Remarks -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-pen-fill"></i> Signatories &amp; Remarks</div>
      <div class="form-card-body">
        <div class="form-grid-2" style="margin-bottom:1rem;">
          <div class="form-group">
            <label>Noted By <span style="color:var(--danger,#dc2626);">*</span></label>
            <div class="emp-search-wrap">
              <input type="text" id="noted_search" placeholder="Type name or ID to search…" autocomplete="off">
              <div class="emp-dropdown" id="noted_dropdown"></div>
            </div>
            <div class="emp-selected" id="noted_selected" style="display:none;">
              <span id="noted_selected_label"></span>
              <span class="emp-clear" onclick="clearNotedBy()" title="Clear"><i class="bi bi-x-lg"></i></span>
            </div>
            <input type="hidden" name="noted_by_id" id="noted_by_id">
          </div>
          <div class="form-group">
            <label>Approved By <span style="color:var(--danger,#dc2626);">*</span></label>
            <div class="emp-search-wrap">
              <input type="text" id="approved_search" placeholder="Type name or ID to search…" autocomplete="off">
              <div class="emp-dropdown" id="approved_dropdown"></div>
            </div>
            <div class="emp-selected" id="approved_selected" style="display:none;">
              <span id="approved_selected_label"></span>
              <span class="emp-clear" onclick="clearApprovedBy()" title="Clear"><i class="bi bi-x-lg"></i></span>
            </div>
            <input type="hidden" name="approved_by_id" id="approved_by_id">
          </div>
          <div class="form-group">
            <label>Remarks</label>
            <input type="text" name="remarks" placeholder="Optional notes">
          </div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div style="display:flex; justify-content:flex-end; gap:.75rem; margin-bottom:2rem;">
      <a href="<?= base_url('EMPLOYEE/index.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-x-lg"></i> Cancel
      </a>
      <button type="submit" class="btn btn-add">
        <i class="bi bi-floppy-fill"></i> Save Loan
      </button>
    </div>

  </form>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.getElementById('cutoff').addEventListener('change', function () {
  const isManual = this.value === 'Specific Date';
  document.getElementById('add_row_wrap').style.display     = isManual ? '' : 'none';
  document.getElementById('start_date_group').style.display  = isManual ? 'none' : '';
  document.getElementById('sched_mode_label').textContent    = isManual
    ? 'Manual entry · add rows below'
    : 'Auto-generated · editable';
  if (isManual) document.getElementById('start_date').removeAttribute('required');
  updateSchedule();
});

document.getElementById('loan-form').addEventListener('submit', function (e) {
  const cutoff = document.getElementById('cutoff').value;
  const rows   = document.querySelectorAll('#sched_body tr');
  const dataRows = Array.from(rows).filter(r => r.querySelector('[name="sched_due[]"]'));

  if (dataRows.length === 0) {
    e.preventDefault();
    alert('No amortization schedule rows have been added yet. Fill in Loan Amount, Terms' +
          (cutoff === 'Specific Date' ? ', and at least one installment row' : ', and Starting Date') +
          ' before saving.');
    return;
  }

  const incomplete = dataRows.filter(r => {
    const due  = r.querySelector('[name="sched_due[]"]')?.value || '';
    const rawPrin = r.querySelector('[name="sched_principal[]"]')?.value;
    const prin = (rawPrin === undefined || rawPrin === null) ? -1 : parseFloat(rawPrin);
    return !due || isNaN(prin) || prin < 0;
  });

  if (incomplete.length > 0) {
    e.preventDefault();
    alert(`${incomplete.length} schedule row(s) are missing a due date or a valid principal amount. ` +
          'Please fill in every row (or remove unused ones) before saving.');
  }
});

document.getElementById('loan_type').addEventListener('change', function () {
  const opt  = this.options[this.selectedIndex];
  const code = opt.dataset.code ?? '';
  const refEl = document.getElementById('ref_number');

  if (!code) {
    refEl.value       = '';
    refEl.placeholder = 'Select a Loan Type first…';
    return;
  }

  refEl.value       = '…';
  refEl.placeholder = '';

  fetch(`create.php?ajax=next_ref&code=${encodeURIComponent(code)}`)
    .then(r => r.json())
    .then(data => { refEl.value = data.ref || ''; })
    .catch(() => { refEl.value = ''; });
});

let empTimer;
document.getElementById('emp_search').addEventListener('input', function () {
  clearTimeout(empTimer);
  const q = this.value.trim();
  if (q.length < 2) { closeDropdown(); return; }
  empTimer = setTimeout(() => searchEmployee(q), 280);
});

document.getElementById('emp_search').addEventListener('blur', function () {
  setTimeout(closeDropdown, 200);
});

function searchEmployee(q) {
  fetch(`create.php?ajax=search_employee&q=${encodeURIComponent(q)}`)
    .then(r => r.json())
    .then(data => {
      const dd = document.getElementById('emp_dropdown');
      dd.innerHTML = '';
      if (!data.length) {
        dd.innerHTML = '<div class="emp-option" style="color:var(--text-muted);">No employees found.</div>';
      } else {
        data.forEach(emp => {
          const div = document.createElement('div');
          div.className = 'emp-option';
          const name = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
          div.innerHTML = `<div class="emp-name">${name}</div>
            <div class="emp-id">${emp.EmployeeID} · <span class="emp-dept">${emp.Department ?? ''} — ${emp.Position_held ?? ''}</span></div>`;
          div.addEventListener('mousedown', () => selectEmployee(emp));
          dd.appendChild(div);
        });
      }
      dd.style.display = 'block';
    });
}

function selectEmployee(emp) {
  document.getElementById('employee_id').value = emp.EmployeeID;
  document.getElementById('department').value  = emp.Department ?? '';
  const name = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
  document.getElementById('emp_selected_label').textContent = `${name} · ${emp.EmployeeID}`;
  document.getElementById('emp_selected').style.display = 'flex';
  document.getElementById('emp_search').style.display   = 'none';
  closeDropdown();
}

function clearEmployee() {
  document.getElementById('employee_id').value          = '';
  document.getElementById('department').value           = '';
  document.getElementById('emp_selected').style.display = 'none';
  document.getElementById('emp_search').style.display   = '';
  document.getElementById('emp_search').value           = '';
  document.getElementById('emp_search').focus();
}

let notedTimer;
document.getElementById('noted_search').addEventListener('input', function () {
  clearTimeout(notedTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('noted_dropdown').style.display = 'none'; return; }
  notedTimer = setTimeout(() => {
    fetch(`create.php?ajax=search_noted_by&q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        const dd = document.getElementById('noted_dropdown');
        dd.innerHTML = '';
        if (!data.length) {
          dd.innerHTML = '<div class="emp-option" style="color:var(--text-muted);">No employees found.</div>';
        } else {
          data.forEach(emp => {
            const div = document.createElement('div');
            div.className = 'emp-option';
            const name = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
            div.innerHTML = `<div class="emp-name">${name}</div>
              <div class="emp-id">${emp.EmployeeID} · <span class="emp-dept">${emp.Job_tittle ?? ''}</span></div>`;
            div.addEventListener('mousedown', () => {
              document.getElementById('noted_by_id').value = emp.EmployeeID;
              const label = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
              document.getElementById('noted_selected_label').textContent = `${label} · ${emp.Job_tittle ?? ''}`;
              document.getElementById('noted_selected').style.display = 'flex';
              document.getElementById('noted_search').style.display   = 'none';
              dd.style.display = 'none';
            });
            dd.appendChild(div);
          });
        }
        dd.style.display = 'block';
      });
  }, 280);
});

document.getElementById('noted_search').addEventListener('blur', function () {
  setTimeout(() => { document.getElementById('noted_dropdown').style.display = 'none'; }, 200);
});

function clearNotedBy() {
  document.getElementById('noted_by_id').value            = '';
  document.getElementById('noted_selected').style.display = 'none';
  document.getElementById('noted_search').style.display   = '';
  document.getElementById('noted_search').value           = '';
}

let approvedTimer;
document.getElementById('approved_search').addEventListener('input', function () {
  clearTimeout(approvedTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('approved_dropdown').style.display = 'none'; return; }
  approvedTimer = setTimeout(() => {
    fetch(`create.php?ajax=search_approved_by&q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        const dd = document.getElementById('approved_dropdown');
        dd.innerHTML = '';
        if (!data.length) {
          dd.innerHTML = '<div class="emp-option" style="color:var(--text-muted);">No employees found.</div>';
        } else {
          data.forEach(emp => {
            const div = document.createElement('div');
            div.className = 'emp-option';
            const name = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
            div.innerHTML = `<div class="emp-name">${name}</div>
              <div class="emp-id">${emp.EmployeeID} · <span class="emp-dept">${emp.Job_tittle ?? ''}</span></div>`;
            div.addEventListener('mousedown', () => {
              document.getElementById('approved_by_id').value = emp.EmployeeID;
              const label = [emp.LastName, emp.FirstName, emp.MiddleName].filter(Boolean).join(', ');
              document.getElementById('approved_selected_label').textContent = `${label} · ${emp.Job_tittle ?? ''}`;
              document.getElementById('approved_selected').style.display = 'flex';
              document.getElementById('approved_search').style.display   = 'none';
              dd.style.display = 'none';
            });
            dd.appendChild(div);
          });
        }
        dd.style.display = 'block';
      });
  }, 280);
});

document.getElementById('approved_search').addEventListener('blur', function () {
  setTimeout(() => { document.getElementById('approved_dropdown').style.display = 'none'; }, 200);
});

function clearApprovedBy() {
  document.getElementById('approved_by_id').value             = '';
  document.getElementById('approved_selected').style.display  = 'none';
  document.getElementById('approved_search').style.display    = '';
  document.getElementById('approved_search').value            = '';
}

function closeDropdown() {
  document.getElementById('emp_dropdown').style.display = 'none';
}

function updateSchedule() {
  const amt    = parseFloat(document.getElementById('loan_amount').value) || 0;
  const terms  = parseInt(document.getElementById('terms').value)          || 0;
  const cutoff = document.getElementById('cutoff').value;

  const body = document.getElementById('sched_body');

  if (cutoff === 'Specific Date') {
    document.getElementById('cutoff_amount').value = '';
    document.getElementById('terms_amount').value  = amt && terms ? (amt / terms).toFixed(2) : '';
    renderManualSchedule();
    return;
  }

  if (!amt || !terms) {
    body.innerHTML = '<tr><td colspan="7" class="sched-empty">Fill in Loan Amount, Terms, and Starting Date above to generate the schedule.</td></tr>';
    document.getElementById('cutoff_amount').value = '';
    document.getElementById('terms_amount').value  = '';
    return;
  }

  const freqMap = { 'Weekly': 4, '15th': 1, '30th': 1, '15th & 30th': 2 };
  const paymentsPerMonth = freqMap[cutoff] || 1;

  const monthlyAmort = amt / terms;
  document.getElementById('terms_amount').value = monthlyAmort.toFixed(2);

  const installment = monthlyAmort / paymentsPerMonth;
  document.getElementById('cutoff_amount').value = installment.toFixed(2);

  const totalRows = terms * paymentsPerMonth;
  const startDateVal = document.getElementById('start_date').value;

  function getDueDates(count, cutoff, startDateVal) {
    if (!startDateVal) return [];
    const dates = [];
    const fmt = d =>
      d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' +
      String(d.getDate()).padStart(2, '0');

    const start = new Date(startDateVal + 'T00:00:00');

    if (cutoff === 'Weekly') {
      const cur = new Date(start);
      for (let i = 0; i < count; i++) {
        dates.push(fmt(cur));
        cur.setDate(cur.getDate() + 7);
      }

    } else if (cutoff === '15th') {
      dates.push(fmt(start));
      let year = start.getFullYear(), month = start.getMonth();
      for (let i = 1; i < count; i++) {
        month++;
        if (month > 11) { month = 0; year++; }
        dates.push(`${year}-${String(month + 1).padStart(2, '0')}-15`);
      }

    } else if (cutoff === '30th') {
      let year = start.getFullYear(), month = start.getMonth();
      for (let i = 0; i < count; i++) {
        const lastDay = new Date(year, month + 1, 0).getDate();
        dates.push(`${year}-${String(month + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`);
        month++;
        if (month > 11) { month = 0; year++; }
      }

    } else if (cutoff === '15th & 30th') {
      dates.push(fmt(start));
      let year = start.getFullYear(), month = start.getMonth();
      const day = start.getDate();
      // If start is before the 15th → next is 15th of same month
      // If start is the 15th → next is end of same month
      // If start is after the 15th (e.g. 30th) → next is 15th of NEXT month
      let nextIs15th, advanceMonth;
      if (day < 15) {
        nextIs15th = true; advanceMonth = false;
      } else if (day === 15) {
        nextIs15th = false; advanceMonth = false;
      } else {
        nextIs15th = true; advanceMonth = true;
      }

      for (let i = 1; i < count; i++) {
        if (advanceMonth) { month++; if (month > 11) { month = 0; year++; } advanceMonth = false; }
        if (nextIs15th) {
          dates.push(`${year}-${String(month + 1).padStart(2, '0')}-15`);
          nextIs15th = false;
        } else {
          const lastDay = new Date(year, month + 1, 0).getDate();
          dates.push(`${year}-${String(month + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`);
          nextIs15th = true;
          advanceMonth = true;
        }
      }
    }

    return dates;
  }

  const dueDates = getDueDates(totalRows, cutoff, startDateVal);

  let opb  = amt;
  let html = '';

  for (let i = 0; i < totalRows; i++) {
    const isLast    = i === totalRows - 1;
    const principal = isLast ? parseFloat(opb.toFixed(2)) : parseFloat(installment.toFixed(2));
    const rowOpb    = parseFloat(opb.toFixed(2));
    const interest  = 0;
    const dueDate   = dueDates[i] || '';
    const label     = dueDate
      ? new Date(dueDate + 'T00:00:00').toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })
      : `Installment ${i+1}`;
    opb -= principal;

    html += `<tr>
      <td style="text-align:center;color:var(--text-muted);font-size:.78rem;font-weight:600;">${i+1}</td>
      <td><input type="text"   name="sched_month[]"     value="${label}" placeholder="Label"></td>
      <td><input type="date"   name="sched_due[]"       value="${dueDate}"></td>
      <td><input type="number" name="sched_opb[]"       value="${rowOpb.toFixed(2)}"               step="0.01" readonly></td>
      <td><input type="number" name="sched_principal[]" value="${principal.toFixed(2)}"            step="0.01" oninput="recalcRow(this)"></td>
      <td><input type="number" name="sched_int[]"       value="${interest.toFixed(2)}"             step="0.01" oninput="recalcRow(this)"></td>
      <td><input type="number" name="sched_amort[]"     value="${(principal+interest).toFixed(2)}" step="0.01" readonly></td>
      <input type="hidden" name="sched_term[]" value="${i+1}">
    </tr>`;
  }
  body.innerHTML = html;
}

function renderManualSchedule() {
  const body      = document.getElementById('sched_body');
  const terms     = parseInt(document.getElementById('terms').value) || 0;
  const amt       = parseFloat(document.getElementById('loan_amount').value) || 0;
  const suggested = (amt && terms) ? (amt / terms).toFixed(2) : '';

  if (!body.querySelector('.manual-row')) {
    body.innerHTML = '';
    const rowsToAdd = terms > 0 ? terms : 1;
    for (let i = 0; i < rowsToAdd; i++) addManualRow();
  } else {
    body.querySelectorAll('.manual-row').forEach(row => {
      const prinInput  = row.querySelector('[name="sched_principal[]"]');
      const amortInput = row.querySelector('[name="sched_amort[]"]');
      if (suggested && (parseFloat(prinInput.value) || 0) === 0) {
        prinInput.value  = suggested;
        amortInput.value = suggested;
      }
    });
    recalcManualOpb();
  }
}

function addManualRow() {
  const body = document.getElementById('sched_body');
  if (body.querySelector('.sched-empty')) body.innerHTML = '';
  const idx       = body.querySelectorAll('tr.manual-row').length + 1;
  const amt       = parseFloat(document.getElementById('loan_amount').value) || 0;
  const terms     = parseInt(document.getElementById('terms').value) || 0;
  const suggested = (amt && terms) ? (amt / terms).toFixed(2) : '0.00';

  const tmp = document.createElement('tbody');
  tmp.innerHTML = `<tr class="manual-row">
    <td style="text-align:center;color:var(--text-muted);font-size:.78rem;font-weight:600;" class="row-no">${idx}</td>
    <td><input type="text"   name="sched_month[]"     value="Installment ${idx}" placeholder="Label"></td>
    <td><input type="date"   name="sched_due[]"       value=""></td>
    <td><input type="number" name="sched_opb[]"       value="0.00" step="0.01" readonly></td>
    <td><input type="number" name="sched_principal[]" value="${suggested}" step="0.01" oninput="onManualRowInput(this)"></td>
    <td><input type="number" name="sched_int[]"       value="0.00" step="0.01" oninput="onManualRowInput(this)"></td>
    <td><input type="number" name="sched_amort[]"     value="${suggested}" step="0.01" readonly></td>
    <input type="hidden" name="sched_term[]" value="${idx}">
  </tr>`;
  body.appendChild(tmp.firstElementChild);
  recalcManualOpb();
}

function removeManualRow(el) {
  el.closest('tr').remove();
  renumberManualRows();
  recalcManualOpb();
  const body = document.getElementById('sched_body');
  if (!body.querySelector('.manual-row')) {
    body.innerHTML = '<tr><td colspan="7" class="sched-empty">No installments yet. Click "Add Installment" below to start.</td></tr>';
  }
}

function renumberManualRows() {
  document.querySelectorAll('#sched_body tr.manual-row').forEach((row, i) => {
    row.querySelector('.row-no').textContent = i + 1;
    row.querySelector('[name="sched_term[]"]').value  = i + 1;
    row.querySelector('[name="sched_month[]"]').value = 'Installment ' + (i + 1);
  });
}

function onManualRowInput(el) {
  const row  = el.closest('tr');
  const prin = parseFloat(row.querySelector('[name="sched_principal[]"]').value) || 0;
  const intr = parseFloat(row.querySelector('[name="sched_int[]"]').value)       || 0;
  row.querySelector('[name="sched_amort[]"]').value = (prin + intr).toFixed(2);
  recalcManualOpb();
}

function recalcManualOpb() {
  const amt = parseFloat(document.getElementById('loan_amount').value) || 0;
  let opb = amt;
  document.querySelectorAll('#sched_body .manual-row').forEach(row => {
    row.querySelector('[name="sched_opb[]"]').value = opb.toFixed(2);
    const prin = parseFloat(row.querySelector('[name="sched_principal[]"]').value) || 0;
    opb -= prin;
  });
}

function recalcRow(el) {
  const row  = el.closest('tr');
  const prin = parseFloat(row.querySelector('[name="sched_principal[]"]').value) || 0;
  const intr = parseFloat(row.querySelector('[name="sched_int[]"]').value)       || 0;
  row.querySelector('[name="sched_amort[]"]').value = (prin + intr).toFixed(2);
}
</script>
</body>
</html>