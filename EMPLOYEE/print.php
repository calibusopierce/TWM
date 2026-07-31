<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

// Shared by the admin module (employee_loans) and the employee
// self-service view (my_loans) — same dual-permission pattern as view.php.
$perms = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!isset($perms['employee_loans']) && !isset($perms['my_loans'])) {
    rbac_gate($pdo, 'employee_loans');
}

$is_readonly = isset($_GET['readonly']) && $_GET['readonly'] == '1';
$my_emp_id   = $_SESSION['EmployeeID'] ?? '';
$is_admin    = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);

$loan_id = (int)($_GET['id'] ?? 0);
if (!$loan_id) die("Invalid loan.");

// Non-admins may only print their own loan, regardless of the readonly param.
if (!$is_admin) {
    $own_chk = sqlsrv_query($conn, "SELECT EmployeeID FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
    $own_row = $own_chk ? sqlsrv_fetch_array($own_chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$own_row || $own_row['EmployeeID'] !== $my_emp_id) {
        header("Location: " . base_url('EMPLOYEE/my_loans.php'));
        exit;
    }
}

$res = sqlsrv_query($conn, "
    SELECT l.*, t.TypeName AS LoanTypeName, t.Code AS LoanTypeCode,
           e.LastName, e.FirstName, e.MiddleName, e.Department,
           e.Position_held, e.SSS_Number, e.HDMF, e.TIN_Number,
           e.Branch, e.EmployeeID AS EmpID,
           nb.LastName  AS NB_LastName,  nb.FirstName  AS NB_FirstName,
           nb.MiddleName AS NB_MiddleName, nb.Job_tittle AS NB_JobTitle,
           ab.LastName  AS AB_LastName,  ab.FirstName  AS AB_FirstName,
           ab.MiddleName AS AB_MiddleName, ab.Job_tittle AS AB_JobTitle,
           pb.LastName  AS PB_LastName,  pb.FirstName  AS PB_FirstName,
           pb.MiddleName AS PB_MiddleName, pb.Job_tittle AS PB_JobTitle
    FROM TBL_Loan l
    LEFT JOIN TBL_Loan_Type t       ON t.ID          = l.LoanType
    LEFT JOIN TBL_HREmployeeList e  ON e.EmployeeID  = l.EmployeeID
    LEFT JOIN TBL_HREmployeeList nb ON nb.EmployeeID = l.NotedByID
    LEFT JOIN TBL_HREmployeeList ab ON ab.EmployeeID = l.ApprovedByID
    LEFT JOIN TBL_HREmployeeList pb ON pb.EmployeeID = l.UserInput
    WHERE l.LoanID = ?", [$loan_id]);
$loan = $res ? sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC) : null;
if (!$loan) die("Loan not found.");

$sched_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount, p.PaymentMethod
    FROM TBL_Loan_Statement s
    LEFT JOIN TBL_Loan_Payment p ON p.LoanPaymentID = s.PaymentID
    WHERE s.LoanID = ?
    ORDER BY s.Due_Date ASC, s.StatementID ASC", [$loan_id]);
$schedule = [];
if ($sched_res) while ($r = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $r;

// Pad to at least 6 rows
while (count($schedule) < 6) $schedule[] = null;

$full_name = trim(($loan['LastName'] ?? '') . ', ' . ($loan['FirstName'] ?? '') . ' ' . ($loan['MiddleName'] ?? ''));
$loan_date = ($loan['LoanDate'] instanceof DateTime) ? $loan['LoanDate']->format('m/d/Y') : ($loan['LoanDate'] ?? '');
$loan_amt  = (float)($loan['LoanAmount']    ?? 0);
$paid_amt  = (float)($loan['PaidAmount']    ?? 0);
$bal_amt   = (float)($loan['BalanceAmount'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <title>SOA – <?= htmlspecialchars($loan['ReferenceNumber'] ?? '') ?></title>
  <style>
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#000; background:#f0f0f0; }

    /* Print bar */
    .print-bar { width:720px; margin:12px auto 0; display:flex; justify-content:flex-end;
                 gap:.5rem; padding:0 30px 8px; }
    .print-bar button { padding:7px 18px; border:none; border-radius:6px; cursor:pointer;
                        font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:.4rem; }
    .btn-print-go  { background:#1a3a5c; color:#fff; }
    .btn-print-go:hover { background:#2e6da4; }
    .btn-close-win { background:#6c757d; color:#fff; }
    @media print {
      .print-bar { display:none !important; }
      body { background:#fff; }
      .page { margin:0; border:none; box-shadow:none; }
    }

    /* Page */
    .page { width:720px; margin:0 auto 24px; padding:30px 32px;
            background:#fff; border:1px solid #ccc; box-shadow:0 4px 24px rgba(0,0,0,.1); }

    /* Header */
    .soa-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    margin-bottom:20px;
    border-bottom:2px solid #1a3a5c;
    padding-bottom:10px;
}
    .company-name { font-size:14px; font-weight:700; text-decoration:underline; font-style:italic; }
    .company-info { font-size:10.5px; line-height:1.6; margin-top:2px; }
    .soa-title-block { text-align:right; }
    .soa-title { font-size:22px; font-weight:700; color:#1a3a5c; letter-spacing:1px; }
    .soa-subtitle { font-size:11px; color:#555; margin-top:2px; }
    .date-row { display:flex; align-items:center; gap:8px; justify-content:flex-end;
                margin-top:6px; font-size:10.5px; }
    .date-box { border:1px solid #888; padding:2px 12px; min-width:110px; font-size:10.5px; }

    /* Info boxes */
    .info-row-wrap { display:flex; gap:10px; margin-bottom:12px; }
    .info-box { flex:1; border:1px solid #888; }
    .info-box-head { background:#1a3a5c; color:#fff; font-weight:700;
                     font-size:10.5px; padding:4px 9px; }
    .info-box-body { padding:6px 9px; font-size:10.5px; line-height:1.75; }
    .info-box-body .irow { display:flex; gap:6px; }
    .info-box-body .ilbl { color:#555; min-width:90px; }
    .info-box-body .ival { font-weight:700; }

    /* Schedule table */
    .sched-table { width:100%; border-collapse:collapse; font-size:10.5px; margin-bottom:8px; }
    .sched-table thead tr { background:#1a3a5c; color:#fff; }
    .sched-table th { padding:5px 7px; border:1px solid #1a3a5c; text-align:center; font-weight:700; }
    .sched-table td { padding:4px 7px; border:1px solid #ccc; vertical-align:middle; }
    .sched-table .c-num  { text-align:right; }
    .sched-table .c-cent { text-align:center; }
    .sched-table .paid-row { background:#f0fdf4; }
    .sched-table .empty td { height:20px; }

    /* Totals */
    .totals-wrap { display:flex; justify-content:flex-end; margin-bottom:18px; }
    .totals-table { width:300px; border-collapse:collapse; font-size:10.5px; }
    .totals-table td { padding:3px 9px; border-bottom:1px solid #e0e0e0; }
    .t-lbl { font-weight:600; text-align:right; width:140px; }
    .t-val { text-align:right; border-left:1px solid #e0e0e0; padding-left:10px; }
    .grand-row { background:#1a3a5c; }
    .grand-row td { color:#fff; font-weight:700; font-size:11.5px; border:none; }

    /* Signatures */
    .sig-section { margin-top:28px; display:flex; gap:40px; }
    .sig-block { flex:1; }
    .sig-label { font-size:10.5px; margin-bottom:18px; color:#555; }
    .sig-line  { border-top:2px solid #1a3a5c; padding-top:5px; }
    .sig-name  { font-weight:700; font-size:11.5px; }
    .sig-title { font-style:italic; font-size:10.5px; color:#555; }

    .company-brand{
    display:flex;
    align-items:flex-start;
    gap:12px;
    }

    .company-logo{
        width:65px;
        height:auto;
    }
  </style>
</head>
<body>

<div class="print-bar">
  <button class="btn-print-go" onclick="window.print()">
    <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="margin-right:3px;">
      <path d="M5 1a2 2 0 0 0-2 2v1h10V3a2 2 0 0 0-2-2H5zm6 8H5a1 1 0 0 0-1 1v3h8v-3a1 1 0 0 0-1-1z"/>
      <path d="M0 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-1v-2a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v2H2a2 2 0 0 1-2-2V7zm2.5 1a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
    </svg>
    Print / Save PDF
  </button>
  <button class="btn-close-win" onclick="window.close()">✕ Close</button>
</div>

<div class="page">

  <!-- Header -->
  <div class="soa-header">
      <div class="company-brand">
    <img src="<?= base_url('assets/img/LOGO.png') ?>" alt="TradeWell Logo" class="company-logo">
    <div>
        <div class="company-name">URBAN TRADEWELL CORP.</div>
        <div class="company-info">
            Sta. Monica St Lourdes Subd, Ibabang Iyam<br>
            Lucena City, 4301<br>
            Phone: (042) 788-0765<br>
            Email: creative.tradewell@gmail.com
        </div>
    </div>
</div>
    <div class="soa-title-block">
      <div class="soa-title">STATEMENT OF ACCOUNT</div>
      <div class="soa-subtitle"><?= htmlspecialchars($loan['LoanTypeName'] ?? 'Employee Loan') ?></div>
      <div class="date-row">
        <span>DATE</span>
        <div class="date-box"><?= htmlspecialchars($loan_date) ?></div>
      </div>
      <div class="date-row" style="margin-top:4px;">
        <span>REF NO.</span>
        <div class="date-box" style="font-weight:700;"><?= htmlspecialchars($loan['ReferenceNumber'] ?? '') ?></div>
      </div>
    </div>
  </div>

  <!-- Employee & Loan Info -->
  <div class="info-row-wrap">
    <div class="info-box">
      <div class="info-box-head">EMPLOYEE INFORMATION</div>
      <div class="info-box-body">
        <div class="irow"><span class="ilbl">Name:</span>      <span class="ival"><?= htmlspecialchars($full_name) ?></span></div>
        <div class="irow"><span class="ilbl">Employee ID:</span><span class="ival"><?= htmlspecialchars($loan['EmpID'] ?? '—') ?></span></div>
        <div class="irow"><span class="ilbl">Department:</span> <span class="ival"><?= htmlspecialchars($loan['Department'] ?? '—') ?></span></div>
        <div class="irow"><span class="ilbl">Position:</span>   <span class="ival"><?= htmlspecialchars($loan['Position_held'] ?? '—') ?></span></div>
        <div class="irow"><span class="ilbl">Branch:</span>     <span class="ival"><?= htmlspecialchars($loan['Branch'] ?? '—') ?></span></div>
      </div>
    </div>
    <div class="info-box">
      <div class="info-box-head">LOAN DETAILS</div>
      <div class="info-box-body">
        <div class="irow"><span class="ilbl">Loan Type:</span>   <span class="ival"><?= htmlspecialchars($loan['LoanTypeName'] ?? '—') ?></span></div>
        <div class="irow"><span class="ilbl">Loan Amount:</span> <span class="ival">&#8369; <?= number_format($loan_amt, 2) ?></span></div>
        <div class="irow"><span class="ilbl">Terms:</span>       <span class="ival"><?= (int)($loan['Terms'] ?? 0) ?></span></div>
        <?php
  $cutoff_int_map  = [4 => 'Weekly', 1 => 'Monthly', 2 => '15th & 30th'];
  $cutoff_int      = (int)($loan['CutOff'] ?? 0);
  $cutoff_display  = $cutoff_int > 0 ? ($cutoff_int_map[$cutoff_int] ?? $cutoff_int . 'x/month') : '—';

  $cutoff_amt_display = (float)($loan['CutOff_Amount'] ?? 0);
  $monthly_display    = (float)($loan['TermsAmount']   ?? 0);

  $remarks_raw   = $loan['Remarks'] ?? '';
  $remarks_clean = preg_replace('/^\[Freq:[^\]]*\]\s?/', '', $remarks_raw); // strip legacy tag if present
?>
<div class="irow"><span class="ilbl">Monthly:</span>     <span class="ival">&#8369; <?= number_format($monthly_display, 2) ?></span></div>
<div class="irow"><span class="ilbl">Payment Schedule: </span> <span class="ival"> <?= htmlspecialchars($cutoff_display) ?></span></div>
<div class="irow"><span class="ilbl">Payment Amt:</span> <span class="ival">&#8369; <?= number_format($cutoff_amt_display, 2) ?></span></div>
        <div class="irow"><span class="ilbl">Status:</span>      <span class="ival"><?= htmlspecialchars($loan['Status'] ?? '—') ?></span></div>
      </div>
    </div>
  </div>

  <?php if (!empty($loan['Description'])): ?>
  <div style="margin-bottom:10px;font-size:10.5px;">
    <strong>Purpose:</strong> <?= htmlspecialchars($loan['Description']) ?>
  </div>
  <?php endif; ?>

  <!-- Amortization Table -->
  <table class="sched-table">
    <thead>
      <tr>
        <th style="width:30px;">#</th>
        <th style="width:160px;">Month</th>
        <th style="width:90px;">Due Date</th>
        <th style="width:90px;" class="c-num">OPB (₱)</th>
        <th style="width:90px;" class="c-num">Principal (₱)</th>
        <th style="width:80px;" class="c-num">Interest (₱)</th>
        <th style="width:90px;" class="c-num">Amortization (₱)</th>
        <th style="width:90px;" class="c-cent">Payment Date</th>
        <th style="width:60px;" class="c-cent">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($schedule as $i => $row): ?>
        <?php if ($row): ?>
          <?php
            $paid    = !empty($row['PaymentID']);
            $raw_due = $row['Due_Date'] ?? '';
            $due_str = ($raw_due instanceof DateTime) ? $raw_due->format('m/d/Y') : (is_string($raw_due) ? $raw_due : '');
            $raw_pay = $row['PaymentDate'] ?? '';
            $pay_str = ($raw_pay instanceof DateTime) ? $raw_pay->format('m/d/Y') : (is_string($raw_pay) ? $raw_pay : '');
          ?>
          <tr class="<?= $paid ? 'paid-row' : '' ?>">
            <td class="c-cent"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars(($row['MonthName'] instanceof DateTime) ? $row['MonthName']->format('F Y') : ($row['MonthName'] ?? '')) ?></td>
            <td class="c-cent"><?= htmlspecialchars($due_str) ?></td>
            <td class="c-num"><?= number_format((float)($row['OPB'] ?? 0), 2) ?></td>
            <td class="c-num"><?= number_format((float)($row['Pricipal_Amount'] ?? 0), 2) ?></td>
            <td class="c-num"><?= number_format((float)($row['Interest_Amount'] ?? 0), 2) ?></td>
            <td class="c-num"><?= number_format((float)($row['Amortization_Amount'] ?? 0), 2) ?></td>
            <td class="c-cent"><?= htmlspecialchars($pay_str) ?></td>
            <td class="c-cent" style="font-weight:700;color:<?= $paid ? '#065f46' : '#b45309' ?>;">
              <?= $paid ? 'PAID' : 'PENDING' ?>
            </td>
          </tr>
        <?php else: ?>
          <tr class="empty"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals-wrap">
    <table class="totals-table">
      <tr><td class="t-lbl">TOTAL LOAN</td>      <td class="t-val">&#8369; <?= number_format($loan_amt, 2) ?></td></tr>
      <tr><td class="t-lbl">TOTAL PAID</td>       <td class="t-val">&#8369; <?= number_format($paid_amt, 2) ?></td></tr>
      <tr class="grand-row">
        <td class="t-lbl">OUTSTANDING BALANCE</td>
        <td class="t-val">&#8369; <?= number_format($bal_amt, 2) ?></td>
      </tr>
    </table>
  </div>

  <!-- Signatures -->
  <?php
  // Prepared By — from HR record matched on login username (EmployeeID1)
  $pb_mid   = !empty($loan['PB_MiddleName']) ? strtoupper(substr($loan['PB_MiddleName'], 0, 1)) . '.' : '';
  $pb_name  = trim(strtoupper(($loan['PB_FirstName'] ?? '') . ' ' . $pb_mid . ' ' . ($loan['PB_LastName'] ?? '')));
  $pb_title = $loan['PB_JobTitle'] ?? '';
  if (!$loan['PB_LastName']) {
      $pb_name  = strtoupper($_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'SYSTEM');
      $pb_title = '';
  }

  // Noted By — from HR record stored at loan creation
  $nb_mid   = !empty($loan['NB_MiddleName']) ? strtoupper(substr($loan['NB_MiddleName'], 0, 1)) . '.' : '';
  $nb_name  = !empty($loan['NB_LastName'])
      ? strtoupper(trim($loan['NB_FirstName'] . ' ' . $nb_mid . ' ' . $loan['NB_LastName']))
      : '';
  $nb_title = $loan['NB_JobTitle'] ?? '';
?>
<div class="sig-section">
  <div class="sig-block">
    <div class="sig-label">Prepared by:</div>
    <div class="sig-line">
      <div class="sig-name"><?= htmlspecialchars($pb_name) ?></div>
      <div class="sig-title"><?= htmlspecialchars($pb_title) ?></div>
    </div>
  </div>
  <div class="sig-block">
    <div class="sig-label">Noted by:</div>
    <div class="sig-line">
      <?php if ($nb_name && $nb_name !== ','): ?>
        <div class="sig-name"><?= htmlspecialchars($nb_name) ?></div>
        <div class="sig-title"><?= htmlspecialchars($nb_title) ?></div>
      <?php else: ?>
        <div class="sig-name" style="color:#aaa;font-style:italic;">— Not specified —</div>
      <?php endif; ?>
    </div>
  </div>
  <?php
  $ab_mid   = !empty($loan['AB_MiddleName']) ? strtoupper(substr($loan['AB_MiddleName'], 0, 1)) . '.' : '';
  $ab_name  = !empty($loan['AB_LastName'])
      ? strtoupper(trim($loan['AB_FirstName'] . ' ' . $ab_mid . ' ' . $loan['AB_LastName']))
      : '';
  $ab_title = $loan['AB_JobTitle'] ?? '';
  ?>
  <div class="sig-block">
    <div class="sig-label">Approved by:</div>
    <div class="sig-line">
      <?php if ($ab_name): ?>
        <div class="sig-name"><?= htmlspecialchars($ab_name) ?></div>
        <div class="sig-title"><?= htmlspecialchars($ab_title) ?></div>
      <?php else: ?>
        <div class="sig-name" style="color:#aaa;font-style:italic;">— Not specified —</div>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- /page -->
</body>
</html>