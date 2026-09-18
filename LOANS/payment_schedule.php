<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

// Same dual-tier access as view.php (full admin module OR employee self-service)
$perms = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!isset($perms['employee_loans']) && !isset($perms['my_loans'])) {
    rbac_gate($pdo, 'employee_loans');
}

$is_readonly = isset($_GET['readonly']) && $_GET['readonly'] == '1';
$my_emp_id   = $_SESSION['EmployeeID'] ?? '';
$is_admin    = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) { header("Location: index.php"); exit; }

// Non-admins in readonly mode can only view their own loans
if ($is_readonly && !$is_admin) {
    $own_chk = sqlsrv_query($conn, "SELECT EmployeeID FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
    $own_row = $own_chk ? sqlsrv_fetch_array($own_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$own_row || $own_row['EmployeeID'] !== $my_emp_id) {
        header("Location: " . base_url('EMPLOYEE/my_loans.php'));
        exit;
    }
}

// ── Loan + Employee info (same join pattern as view.php) ──────
$res = sqlsrv_query($conn, "
    SELECT l.*, t.TypeName AS LoanTypeName, t.Code AS LoanTypeCode,
           e.LastName, e.FirstName, e.MiddleName,
           e.Department, e.Position_held, e.Branch
    FROM TBL_Loan l
    LEFT JOIN TBL_Loan_Type t       ON t.ID          = l.LoanType
    LEFT JOIN TBL_HREmployeeList e  ON e.EmployeeID  = l.EmployeeID
    WHERE l.LoanID = ?", [$loan_id]);
$loan = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
if (!$loan) { echo "Loan not found."; exit; }
$loan['Status'] = trim($loan['Status'] ?? '');

// ── Amortization schedule (same query as view.php/payments.php) ──
$sched_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount
    FROM TBL_Loan_Statement s
    LEFT JOIN TBL_Loan_Payment p ON p.LoanPaymentID = s.PaymentID
    WHERE s.LoanID = ?
    ORDER BY s.Due_Date ASC, s.StatementID ASC", [$loan_id]);
$schedule = [];
if ($sched_res) while ($r = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $r;

$full_name = trim(($loan['LastName'] ?? '') . ', ' . ($loan['FirstName'] ?? '') . ' ' . ($loan['MiddleName'] ?? ''));

$loan_amt = (float)($loan['LoanAmount']  ?? 0);
$monthly  = (float)($loan['TermsAmount'] ?? 0);

$cutoff_int_map = [4 => 'Weekly', 1 => 'Monthly', 2 => '15th & 30th'];
$cutoff_int     = (int)($loan['CutOff'] ?? 0);
$cutoff_disp    = $cutoff_int > 0 ? ($cutoff_int_map[$cutoff_int] ?? $cutoff_int . 'x/month') : '—';
$cutoff_amt     = (float)($loan['CutOff_Amount'] ?? 0);

// Helper: safely stringify sqlsrv DateTime/string/null values
$toStr = function ($v, $fmt = 'n/j/y', $fallback = '—') {
    if ($v instanceof DateTime) return $v->format($fmt);
    if (is_string($v) && $v !== '') return $v;
    return $fallback;
};

// Flatten schedule rows into [amount, date, paid] for the grid
$cells = [];
foreach ($schedule as $row) {
    $cells[] = [
        'amount' => (float)($row['Amortization_Amount'] ?? $monthly),
        'date'   => $toStr($row['Due_Date'] ?? null),
        'paid'   => !empty($row['PaymentID']),
    ];
}

$perRow = 10; // amount/date columns per row
$rows = array_chunk($cells, $perRow);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment Schedule · <?= htmlspecialchars($loan['ReferenceNumber'] ?? 'Loan') ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <style>
    body {
      font-family: 'Segoe UI', Arial, sans-serif;
      background:#f1f3f5;
      margin:0;
      padding:1rem;
      color:#1a1a1a;
    }
    .sheet {
      max-width:1100px;
      margin:0 auto;
      background:#fff;
      padding:1rem 1.25rem;
      box-shadow:0 1px 4px rgba(0,0,0,.12);
    }
    .toolbar { max-width:1100px; margin:0 auto 1rem; display:flex; justify-content:flex-end; gap:.5rem; }

    /* Info header — compact landscape style: fields flow inline, wrap as needed */
    .hdr-grid { display:grid; grid-template-columns:1fr 1fr; gap:.6rem; margin-bottom:.6rem; }
    .hdr-card { border:1px solid #c7d2e0; border-radius:4px; overflow:hidden; }
    .hdr-card-title {
      background:#2451a3; color:#fff; font-weight:700; font-size:.68rem;
      letter-spacing:.04em; padding:.25rem .6rem; text-transform:uppercase;
    }
    .hdr-card-body {
      padding:.4rem .6rem;
      display:flex; flex-wrap:wrap;
      gap:.15rem .9rem;
    }
    .hdr-field { display:flex; gap:.3rem; font-size:.72rem; white-space:nowrap; }
    .hdr-field.wide { flex-basis:100%; white-space:normal; }
    .hdr-field .lbl { color:#555; }
    .hdr-field .val { font-weight:700; color:#111; }
    .hdr-field.emphasis { font-size:.95rem; flex-basis:100%; white-space:normal; }
    .hdr-field.emphasis .val { font-size:1rem; }

    /* Payment grid */
    .pay-grid { width:100%; border-collapse:collapse; margin-bottom:.6rem; }
    .pay-grid td {
      border:1px solid #888;
      text-align:center;
      padding:0;
      width:10%;
    }
    .pay-grid .amt {
      font-weight:700; font-size:.78rem; padding:.3rem .25rem;
      border-bottom:1px solid #888;
    }
    .pay-grid .due {
      font-size:.7rem; padding:.25rem .25rem;
    }
    .pay-grid .blank-amt, .pay-grid .blank-due { padding:.3rem .25rem; }
    .pay-grid .blank-due { height:1.1rem; }
    .pay-grid .note-cell { height:1.1rem; }
    .row-gap td { border:none; padding:.12rem 0; }

    .pay-grid .amt.is-paid, .pay-grid .due.is-paid { background:rgba(16,185,129,.08); }
    .paid-tag {
      display:inline-block; margin-top:.1rem;
      font-size:.6rem; font-weight:700; letter-spacing:.02em;
      color:#0d9488; background:rgba(16,185,129,.15);
      border-radius:3px; padding:.04rem .3rem;
    }

    @page {
      margin: 0.4in;
      size: auto;
    }

    @media print {
      body { background:#fff; padding:0; font-size:.92em; }
      .toolbar { display:none; }
      .sheet { box-shadow:none; padding:0; max-width:100%; }
    }
  </style>
</head>
<body>

  <div class="toolbar">
    <button class="btn btn-secondary" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
  </div>

  <div class="sheet">

    <div class="hdr-grid">
      <div class="hdr-card">
        <div class="hdr-card-title">Employee Information</div>
        <div class="hdr-card-body">
          <div class="hdr-field emphasis"><span class="lbl">Name:</span><span class="val"><?= htmlspecialchars($full_name) ?></span></div>
          <div class="hdr-field"><span class="lbl">Employee ID:</span><span class="val"><?= htmlspecialchars($loan['EmployeeID'] ?? '—') ?></span></div>
          <div class="hdr-field"><span class="lbl">Department:</span><span class="val"><?= htmlspecialchars($loan['Department'] ?? '—') ?></span></div>
          <div class="hdr-field"><span class="lbl">Position:</span><span class="val"><?= htmlspecialchars($loan['Position_held'] ?? '—') ?></span></div>
          <div class="hdr-field"><span class="lbl">Branch:</span><span class="val"><?= htmlspecialchars($loan['Branch'] ?? '—') ?></span></div>
        </div>
      </div>
      <div class="hdr-card">
        <div class="hdr-card-title">Loan Details</div>
        <div class="hdr-card-body">
          <div class="hdr-field"><span class="lbl">Loan Type:</span><span class="val"><?= htmlspecialchars($loan['LoanTypeName'] ?? '—') ?></span></div>
          <div class="hdr-field"><span class="lbl">Loan Amount:</span><span class="val">₱ <?= number_format($loan_amt, 2) ?></span></div>
          <div class="hdr-field"><span class="lbl">Terms:</span><span class="val"><?= (int)($loan['Terms'] ?? 0) ?></span></div>
          <div class="hdr-field"><span class="lbl">Monthly:</span><span class="val">₱ <?= number_format($monthly, 2) ?></span></div>
          <div class="hdr-field"><span class="lbl">Payment Schedule:</span><span class="val"><?= htmlspecialchars($cutoff_disp) ?></span></div>
          <div class="hdr-field"><span class="lbl">Payment Amt:</span><span class="val">₱ <?= number_format($cutoff_amt, 2) ?></span></div>
          <div class="hdr-field"><span class="lbl">Status:</span><span class="val"><?= htmlspecialchars($loan['Status'] ?? '—') ?></span></div>
          <div class="hdr-field wide emphasis"><span class="lbl">Description:</span><span class="val"><?= htmlspecialchars($loan['Description'] ?? '—') ?></span></div>
        </div>
      </div>
    </div>

    <?php if (empty($cells)): ?>
      <p style="text-align:center;color:#888;padding:2rem;">No schedule rows found for this loan.</p>
    <?php else: ?>
      <table class="pay-grid">
        <?php foreach ($rows as $rIdx => $rowCells): ?>
          <tr>
            <?php foreach ($rowCells as $c): ?>
              <td class="amt<?= $c['paid'] ? ' is-paid' : '' ?>"><?= number_format($c['amount'], 2) ?></td>
            <?php endforeach; ?>
            <?php for ($i = count($rowCells); $i < $perRow; $i++): ?>
              <td class="blank-amt">&nbsp;</td>
            <?php endfor; ?>
          </tr>
          <tr>
            <?php foreach ($rowCells as $c): ?>
              <td class="due<?= $c['paid'] ? ' is-paid' : '' ?>">
                <?= htmlspecialchars($c['date']) ?><?php if ($c['paid']): ?><br><span class="paid-tag">✓ Paid</span><?php endif; ?>
              </td>
            <?php endforeach; ?>
            <?php for ($i = count($rowCells); $i < $perRow; $i++): ?>
              <td class="blank-due">&nbsp;</td>
            <?php endfor; ?>
          </tr>
          <tr>
            <?php for ($i = 0; $i < $perRow; $i++): ?>
              <td class="note-cell">&nbsp;</td>
            <?php endfor; ?>
          </tr>
          <?php if ($rIdx < count($rows) - 1): ?>
          <tr class="row-gap"><td colspan="<?= $perRow ?>"></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

  </div>

</body>
</html>