<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'cash_advance');

$EmployeeID  = $_SESSION['EmployeeID'];
$DisplayName = $_SESSION['DisplayName'];
$errors  = [];

// ── Fixed 4-boss Approver list ──────────────────────────────────────────
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

// Auto-fetch Department & Branch from employee profile
$empRes = sqlsrv_query($conn,
    "SELECT Department, Branch FROM TBL_HrEmployeeList WHERE EmployeeID = ?", [$EmployeeID]);
$empRow    = ($empRes !== false) ? sqlsrv_fetch_array($empRes, SQLSRV_FETCH_ASSOC) : [];
$empDept   = $empRow['Department'] ?? '';
$empBranch = $empRow['Branch']     ?? '';

// Repopulate the schedule builder if this is a re-render after a validation error
$schedRepop = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sched_date'])) {
    foreach ($_POST['sched_date'] as $i => $d) {
        $schedRepop[] = [
            'date'   => $d,
            'amount' => $_POST['sched_amount'][$i] ?? ''
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $Amount           = trim($_POST['Amount']           ?? '');
    $Reason           = trim($_POST['Reason']           ?? '');
    $ApproverID       = trim($_POST['ApproverID']       ?? '');
    $RecommendByID    = trim($_POST['RecommendByID']    ?? '');
    $RecommendRemarks = trim($_POST['RecommendRemarks'] ?? '');
    $Department       = trim($_POST['Department']       ?? $empDept);
    $Branch           = trim($_POST['Branch']           ?? $empBranch);
    $Remarks          = trim($_POST['Remarks']          ?? '');

    // Payment schedule — Cash Advance only supports manual "Specific Date" entry
    // (no Weekly/Monthly/CutOff frequency like Loans has).
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
    if ($ApproverID === '')
        $errors[] = 'Please select an approver.';
    // Recommended By is no longer required — the approver (one of the 4 bosses)
    // can approve/release directly, bypassing the recommendation step.
    if (empty($schedule))
        $errors[] = 'At least one payment schedule date is required.';

    // Schedule total must match the requested amount exactly (within a centavo
    // for float rounding).
    if (!empty($schedule) && $Amount !== '') {
        $schedTotal = array_sum(array_column($schedule, 'amount'));
        if (abs($schedTotal - floatval($Amount)) > 0.01) {
            $errors[] = 'Payment schedule total (₱' . number_format($schedTotal, 2)
                       . ') must equal the requested amount (₱' . number_format(floatval($Amount), 2) . ').';
        }
    }

    if (empty($errors)) {
        $sql = "INSERT INTO TBL_CashAdvance
                    (EmployeeID, Amount, Reason, RequestDate,
                     ApproverID, RecommendByID, RecommendRemarks,
                     Status, Department, Branch, Remarks,
                     PaidAmount, BalanceAmount,
                     CreatedBy, CreatedDate)
                OUTPUT INSERTED.CashAdvanceID
                VALUES (?, ?, ?, GETDATE(), ?, ?, ?, 'Requested', ?, ?, ?, 0, ?, ?, GETDATE())";
        $params = [
            $EmployeeID, floatval($Amount), $Reason,
            $ApproverID, ($RecommendByID !== '' ? $RecommendByID : null), $RecommendRemarks,
            $Department, $Branch, $Remarks,
            floatval($Amount), // BalanceAmount starts equal to the full requested amount
            $_SESSION['UserID']
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
        } else {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $newId = $row['CashAdvanceID'];

            $schedOk = true;
            foreach ($schedule as $s) {
                $insSched = sqlsrv_query($conn,
                    "INSERT INTO TBL_CashAdvance_Statement (CashAdvanceID, Due_Date, Amortization_Amount)
                     VALUES (?, ?, ?)",
                    [$newId, $s['date'], $s['amount']]);
                if ($insSched === false) { $schedOk = false; break; }
            }

            if (!$schedOk) {
                $errors[] = 'Request was created but the payment schedule failed to save. Please contact IT with request #' . $newId . '.';
            } else {
                header("Location: my-request.php?submitted=1");
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
  <title>New Cash Advance Request · Tradewell</title>
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
    .fg input:focus, .fg textarea:focus {
      outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(124,58,237,.1); }
    .fg input[readonly] { background:var(--surface-alt,#f8fafc); color:var(--text-muted); cursor:not-allowed; }
    .fg textarea { resize:vertical; min-height:64px; }
    .fg .hint { font-size:.74rem; color:var(--text-muted); display:flex; align-items:center; gap:4px; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
    .section-label { font-size:.7rem; font-weight:700; text-transform:uppercase;
                     letter-spacing:.06em; color:var(--primary); padding-bottom:.4rem;
                     border-bottom:1px solid var(--border); margin-top:.25rem; }

    /* recommender dropdown */
    .search-wrapper { position:relative; }
    #recommendResults {
      position:absolute; top:calc(100% + 3px); left:0; right:0;
      background:var(--surface); border:1px solid var(--border); border-top:none;
      border-radius:0 0 var(--radius) var(--radius);
      max-height:200px; overflow-y:auto; display:none; z-index:50;
      box-shadow:0 6px 16px rgba(0,0,0,0.07);
    }
    #recommendResults .rec-item {
      padding:.55rem .85rem; cursor:pointer; font-size:.86rem;
      border-bottom:1px solid var(--border); transition:background .1s;
    }
    #recommendResults .rec-item:last-child { border-bottom:none; }
    #recommendResults .rec-item:hover { background:var(--surface-alt,#f8fafc); color:var(--primary); }

    @media (max-width:576px) { .form-row { grid-template-columns:1fr; } }

    /* schedule rows */
    .sched-row { display:flex; gap:.5rem; align-items:center; }
    .sched-row input[type="date"] { flex:1.2; }
    .sched-row input[type="number"] { flex:1; }
    .sched-row .btn-remove-row {
      flex:0 0 auto; width:34px; height:34px; border-radius:var(--radius);
      border:1px solid var(--border); background:var(--surface); color:#ef4444;
      display:flex; align-items:center; justify-content:center; cursor:pointer;
    }
    .sched-row .btn-remove-row:hover { background:rgba(239,68,68,.08); }
  </style>
</head>
<body>
<?php $topbar_page = 'cash_advance'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title"><i class="bi bi-cash-coin me-2" style="color:var(--primary);"></i>Cash Advance / Vale Slip</div>
      <div class="page-subtitle">Requested by: <strong><?= htmlspecialchars($DisplayName) ?></strong></div>
    </div>
    <a href="my-request.php" class="btn btn-secondary-custom"><i class="bi bi-arrow-left"></i> My Requests</a>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <?= htmlspecialchars($err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <div style="display:flex; justify-content:center;">
  <div class="form-card">
    <div class="form-card-header"><i class="bi bi-plus-circle-fill"></i> New Request</div>
    <div class="form-card-body">
      <form method="POST" autocomplete="off">

        <div class="section-label">Request Details</div>

        <div class="fg">
          <label>Amount <span class="req">*</span></label>
          <input type="number" step="0.01" min="0.01" name="Amount"
                 placeholder="0.00" value="<?= htmlspecialchars($_POST['Amount'] ?? '') ?>" required>
        </div>

        <div class="fg">
          <label>Reason / Purpose</label>
          <textarea name="Reason" placeholder="e.g. Emergency, advance for supplies, etc."><?= htmlspecialchars($_POST['Reason'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
          <div class="fg">
            <label>Department</label>
            <input type="text" name="Department" readonly
                   value="<?= htmlspecialchars($_POST['Department'] ?? $empDept) ?>">
          </div>
          <div class="fg">
            <label>Branch</label>
            <input type="text" name="Branch" readonly
                   value="<?= htmlspecialchars($_POST['Branch'] ?? $empBranch) ?>">
          </div>
        </div>

        <div class="section-label" style="margin-top:.5rem;">Approval</div>

        <div class="fg">
          <label>Approver <span class="req">*</span></label>
          <select name="ApproverID" required>
            <option value="">— Select approver —</option>
            <?php foreach ($approvers as $ap):
              $apName = trim($ap['FirstName'] . ' ' . $ap['LastName']);
              $apId   = $ap['EmployeeID'];
              $sel    = (($_POST['ApproverID'] ?? '') === $apId) ? 'selected' : '';
            ?>
              <option value="<?= htmlspecialchars($apId) ?>" <?= $sel ?>>
                <?= htmlspecialchars($apName) ?><?= $ap['Position_held'] ? ' — ' . htmlspecialchars($ap['Position_held']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="hint"><i class="bi bi-info-circle"></i> The approver reviews, approves, and releases the cash.</div>
        </div>

        <div class="section-label" style="margin-top:.5rem;">Recommendation <span style="font-weight:400; text-transform:none; color:var(--text-muted); font-size:.72rem;">(optional)</span></div>

        <div class="fg">
          <label>Recommended By</label>
          <div class="search-wrapper">
            <input type="text" id="recommendSearch" placeholder="Type supervisor / lead name…">
            <input type="hidden" name="RecommendByID" id="RecommendByID"
                   value="<?= htmlspecialchars($_POST['RecommendByID'] ?? '') ?>">
            <div id="recommendResults"></div>
          </div>
          <div class="hint"><i class="bi bi-info-circle"></i> Optional — the approver can approve/release without a recommendation.</div>
        </div>

        <div class="fg">
          <label>Recommendation Remarks</label>
          <input type="text" name="RecommendRemarks" placeholder="Optional note from recommender"
                 value="<?= htmlspecialchars($_POST['RecommendRemarks'] ?? '') ?>">
        </div>

        <div class="section-label" style="margin-top:.5rem;">Additional</div>

        <div class="fg">
          <label>Remarks</label>
          <input type="text" name="Remarks" placeholder="Any other notes"
                 value="<?= htmlspecialchars($_POST['Remarks'] ?? '') ?>">
        </div>

        <div class="section-label" style="margin-top:.5rem;">Payment Schedule <span class="req">*</span></div>
        <div class="hint" style="margin-bottom:.25rem;">
          <i class="bi bi-info-circle"></i> Add specific dates and amounts. The total must equal the requested amount above.
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
          <i class="bi bi-exclamation-triangle-fill"></i> Schedule total must match the requested amount.
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:.5rem;">
          <button type="submit" class="btn btn-add"><i class="bi bi-send"></i> Submit Request</button>
        </div>

      </form>
    </div>
  </div>
  </div><!-- /centering wrapper -->

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const searchInput = document.getElementById('recommendSearch');
const hiddenInput  = document.getElementById('RecommendByID');
const resultsBox   = document.getElementById('recommendResults');
let debounceTimer, isFetching = false;

searchInput.addEventListener('input', function () {
    hiddenInput.value = '';
    clearTimeout(debounceTimer);
    const q = this.value.trim();
    if (q.length < 2) { resultsBox.style.display = 'none'; return; }

    debounceTimer = setTimeout(() => {
        if (isFetching) return;
        isFetching = true;
        resultsBox.innerHTML = '<div class="rec-item" style="color:var(--text-muted);cursor:default;">Searching…</div>';
        resultsBox.style.display = 'block';

        fetch('search_employees.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                resultsBox.innerHTML = '';
                if (!data.length) {
                    resultsBox.innerHTML = '<div class="rec-item" style="color:var(--text-muted);cursor:default;">No employees found</div>';
                } else {
                    data.forEach(emp => {
                        const div = document.createElement('div');
                        div.className = 'rec-item';
                        div.textContent = emp.FullName;
                        div.addEventListener('click', () => {
                            searchInput.value = emp.FullName;
                            hiddenInput.value = emp.EmployeeID;
                            resultsBox.style.display = 'none';
                        });
                        resultsBox.appendChild(div);
                    });
                }
                resultsBox.style.display = 'block';
                isFetching = false;
            })
            .catch(() => {
                resultsBox.innerHTML = '<div class="rec-item" style="color:#991b1b;cursor:default;">Error loading results</div>';
                isFetching = false;
            });
    }, 280);
});

document.addEventListener('click', e => {
    if (!resultsBox.contains(e.target) && e.target !== searchInput)
        resultsBox.style.display = 'none';
});

document.querySelector('form').addEventListener('submit', e => {
    if (!validateSchedTotal()) {
        e.preventDefault();
        alert('Payment schedule total must equal the requested amount before submitting.');
    }
});

// ── Payment Schedule builder ──────────────────────────────────
const schedRows      = document.getElementById('schedRows');
const amountInput    = document.querySelector('input[name="Amount"]');
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

// Restore rows after a failed submit, otherwise start with one blank row.
if (initialSchedule.length) {
    initialSchedule.forEach(s => addSchedRow(s.date, s.amount));
} else {
    addSchedRow();
}
</script>
</body>
</html>