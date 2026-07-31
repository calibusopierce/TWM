<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

$perms   = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$isAdmin = isset($perms['cash_advance_record']);
if (!$isAdmin && !isset($perms['cash_advance'])) {
    rbac_gate($pdo, 'cash_advance');
}

$EmployeeID = $_SESSION['EmployeeID'];
$id = $_GET['id'] ?? null;
if (!$id || !ctype_digit((string)$id)) { die('Invalid request ID.'); }

$sql = "SELECT
            ca.CashAdvanceID, ca.EmployeeID, ca.Amount, ca.Reason,
            ca.Department, ca.Branch, ca.Remarks, ca.RecommendRemarks, ca.Status,
            ca.RejectReason, ca.PaidAmount, ca.BalanceAmount,
            CONVERT(varchar(10), ca.RequestDate,  107) AS RequestDate,
            CONVERT(varchar(10), ca.ApprovedDate, 107) AS ApprovedDate,
            CONVERT(varchar(10), ca.ReceivedDate, 107) AS ReceivedDate,
            CONVERT(varchar(10), ca.RejectedDate, 107) AS RejectedDate,
            emp.FirstName  + ' ' + emp.LastName  AS EmployeeName,
            emp.Position_held,
            rec.FirstName  + ' ' + rec.LastName  AS RecommendByName,
            appr.FirstName + ' ' + appr.LastName AS ApprovedByName,
            rej.FirstName  + ' ' + rej.LastName  AS RejectedByName,
            apv.FirstName  + ' ' + apv.LastName  AS ApproverName
        FROM TBL_CashAdvance ca
        LEFT JOIN TBL_HrEmployeeList emp  ON emp.EmployeeID  = ca.EmployeeID
        LEFT JOIN TBL_HrEmployeeList rec  ON rec.EmployeeID  = ca.RecommendByID
        LEFT JOIN TBL_HrEmployeeList appr ON appr.EmployeeID = ca.ApprovedByID
        LEFT JOIN TBL_HrEmployeeList rej  ON rej.EmployeeID  = ca.RejectedByID
        LEFT JOIN TBL_HrEmployeeList apv  ON apv.EmployeeID  = ca.ApproverID
        WHERE ca.CashAdvanceID = ?" . (!$isAdmin ? " AND ca.EmployeeID = ?" : "");

$params = $isAdmin ? [$id] : [$id, $EmployeeID];
$stmt   = sqlsrv_query($conn, $sql, $params);
$r      = ($stmt !== false) ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$r) { die('Record not found or access denied.'); }

// Schedule + linked payments (only meaningful once Received/Paid, but harmless to fetch always)
$sched_res = sqlsrv_query($conn, "
    SELECT s.*, p.PaymentDate, p.PaymentAmount
    FROM TBL_CashAdvance_Statement s
    LEFT JOIN TBL_CashAdvance_Payment p ON p.CashAdvancePaymentID = s.PaymentID
    WHERE s.CashAdvanceID = ? ORDER BY s.Due_Date ASC, s.StatementID ASC", [$id]);
$schedule = [];
if ($sched_res) while ($sr = sqlsrv_fetch_array($sched_res, SQLSRV_FETCH_ASSOC)) $schedule[] = $sr;

$backUrl   = $isAdmin ? 'cash-advance-record.php' : 'my-request.php';
$steps     = ['Requested', 'Approved', 'Received', 'Paid'];
$stepIndex = array_search($r['Status'], $steps);
$isRejected = $r['Status'] === 'Rejected';

function statusBadgeClass($s) {
    return match($s) {
        'Requested' => 'badge-requested',
        'Approved'  => 'badge-approved',
        'Rejected'  => 'badge-rejected',
        'Received'  => 'badge-received',
        'Paid'      => 'badge-paid',
        default     => ''
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cash Advance #<?= $r['CashAdvanceID'] ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .detail-card { background:var(--surface); border:1px solid var(--border);
                   border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                   max-width:740px; overflow:hidden; }
    .detail-card-header {
      background:linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
      padding:1.1rem 1.4rem; color:#fff;
      display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;
    }
    .detail-card-header .left h4 { font-weight:700; font-size:1rem; margin:0 0 5px;
                                    display:flex; align-items:center; gap:8px; }
    .detail-card-header .left .sub { font-size:.82rem; opacity:.85; display:flex; align-items:center; gap:5px; }
    .detail-card-header .right .amt { font-size:1.8rem; font-weight:800; line-height:1.1; }
    .detail-card-header .right .amt-lbl { font-size:.68rem; opacity:.7; text-transform:uppercase; letter-spacing:.05em; }

    .badge-requested { background: rgba(255,255,255,.22); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
    .badge-approved  { background: rgba(255,255,255,.22); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
    .badge-rejected  { background: rgba(255,255,255,.22); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
    .badge-received  { background: rgba(255,255,255,.22); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
    .badge-paid      { background: rgba(255,255,255,.22); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: .7rem; font-weight: 700; }

    /* Timeline */
    .tl-wrap { padding:1rem 1.4rem .25rem; }
    .tl { display:flex; justify-content:space-between; position:relative; }
    .tl::before { content:''; position:absolute; top:12px; left:0; right:0; height:2px; background:var(--border); }
    .tl-step { flex:1; text-align:center; position:relative; z-index:1; }
    .tl-dot { width:24px; height:24px; border-radius:50%; background:var(--border); color:var(--text-muted);
               display:flex; align-items:center; justify-content:center; margin:0 auto 4px; font-size:.75rem; }
    .tl-step.done    .tl-dot { background:#7c3aed; color:#fff; }
    .tl-step.current .tl-dot { background:#fff; border:2px solid #7c3aed; color:#7c3aed; }
    .tl-label { font-size:.68rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.03em; }
    .tl-step.done .tl-label, .tl-step.current .tl-label { color:var(--text-main); }
    .tl-date { font-size:.64rem; color:var(--text-muted); margin-top:2px; }

    /* Detail grid */
    .detail-body { padding:1rem 1.4rem 1.4rem; }
    .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; }
    .detail-grid .full { grid-column:1 / -1; }
    .field-lbl { font-size:.66rem; text-transform:uppercase; letter-spacing:.04em;
                 color:var(--text-muted); font-weight:700; margin-bottom:2px; }
    .field-val { font-size:.86rem; color:var(--text-main); word-break:break-word; }
    hr.d { border:none; border-top:1px solid var(--border); margin:10px 0; }

    @media (max-width:576px) {
      .detail-grid { grid-template-columns:1fr; }
      .detail-card-header .right .amt { font-size:1.4rem; }
    }
  </style>
</head>
<body>
<?php $topbar_page = $isAdmin ? 'cash_advance_record' : 'cash_advance';
      require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">Cash Advance #<?= $r['CashAdvanceID'] ?></div>
      <div class="page-subtitle">Request details and current status</div>
    </div>
    <div style="display:flex; gap:.5rem;">
      <a href="<?= $backUrl ?>" class="btn btn-secondary-custom"><i class="bi bi-arrow-left"></i> Back</a>
      <?php if ($isAdmin && in_array($r['Status'], ['Requested', 'Approved'])): ?>
      <a href="edit.php?id=<?= $r['CashAdvanceID'] ?>" class="btn btn-secondary-custom">
        <i class="bi bi-pencil"></i> Edit
      </a>
      <?php endif; ?>
      <?php if ($isAdmin && in_array($r['Status'], ['Received', 'Paid'])): ?>
      <a href="payments.php?id=<?= $r['CashAdvanceID'] ?>" class="btn btn-add">
        <i class="bi bi-cash-coin"></i> Payments
      </a>
      <?php endif; ?>
      <a href="print.php?id=<?= $r['CashAdvanceID'] ?>" target="_blank" class="btn btn-add">
        <i class="bi bi-printer-fill"></i> Print Slip
      </a>
    </div>
  </div>

  <div style="display:flex; justify-content:center;">
  <div class="detail-card">

    <!-- Header -->
    <div class="detail-card-header">
      <div class="left">
        <h4>
          <i class="bi bi-cash-coin"></i>
          Cash Advance #<?= $r['CashAdvanceID'] ?>
          <span class="<?= statusBadgeClass($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></span>
        </h4>
        <div class="sub">
          <i class="bi bi-person-badge"></i>
          <?= htmlspecialchars($r['EmployeeName'] ?: '—') ?>
          <?php if ($r['Position_held']): ?>&nbsp;·&nbsp;<?= htmlspecialchars($r['Position_held']) ?><?php endif; ?>
        </div>
      </div>
      <div class="right">
        <div class="amt-lbl">Amount</div>
        <div class="amt">₱<?= number_format($r['Amount'], 2) ?></div>
      </div>
    </div>

    <!-- Timeline -->
    <div class="tl-wrap">
      <div class="tl">
        <?php if ($isRejected): ?>
          <div class="tl-step done">
            <div class="tl-dot"><i class="bi bi-check"></i></div>
            <div class="tl-label">Requested</div>
            <?php if ($r['RequestDate']): ?><div class="tl-date"><?= htmlspecialchars($r['RequestDate']) ?></div><?php endif; ?>
          </div>
          <div class="tl-step current" style="color:#991b1b;">
            <div class="tl-dot" style="background:#ef4444;color:#fff;border-color:#ef4444;"><i class="bi bi-x"></i></div>
            <div class="tl-label" style="color:#991b1b;">Rejected</div>
            <?php if ($r['RejectedDate']): ?><div class="tl-date"><?= htmlspecialchars($r['RejectedDate']) ?></div><?php endif; ?>
          </div>
        <?php else: ?>
          <?php foreach ($steps as $i => $step):
            $cls = $i < $stepIndex ? 'done' : ($i === $stepIndex ? 'current' : '');
            $dt  = match($step) {
              'Requested' => $r['RequestDate'],
              'Approved'  => $r['ApprovedDate'],
              'Received'  => $r['ReceivedDate'],
              'Paid'      => $r['Status'] === 'Paid' ? ($r['ReceivedDate'] ?: null) : null,
              default     => null
            };
          ?>
          <div class="tl-step <?= $cls ?>">
            <div class="tl-dot"><i class="bi <?= $cls === 'done' || $cls === 'current' ? 'bi-check' : 'bi-circle' ?>"></i></div>
            <div class="tl-label"><?= $step ?></div>
            <?php if ($dt): ?><div class="tl-date"><?= htmlspecialchars($dt) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Body -->
    <div class="detail-body">
      <hr class="d">
      <div class="detail-grid">
        <div>
          <div class="field-lbl">Reason / Purpose</div>
          <div class="field-val"><?= htmlspecialchars($r['Reason'] ?: '—') ?></div>
        </div>
        <div>
          <div class="field-lbl">Date Requested</div>
          <div class="field-val"><?= htmlspecialchars($r['RequestDate']) ?></div>
        </div>
        <div>
          <div class="field-lbl">Department</div>
          <div class="field-val"><?= htmlspecialchars($r['Department'] ?: '—') ?></div>
        </div>
        <div>
          <div class="field-lbl">Branch</div>
          <div class="field-val"><?= htmlspecialchars($r['Branch'] ?: '—') ?></div>
        </div>
        <hr class="d full">
        <div>
          <div class="field-lbl">Approver</div>
          <div class="field-val"><?= htmlspecialchars($r['ApproverName'] ?: '—') ?></div>
        </div>
        <div>
          <div class="field-lbl">Recommended By</div>
          <div class="field-val"><?= htmlspecialchars($r['RecommendByName'] ?: '— none —') ?></div>
        </div>
        <div>
          <div class="field-lbl">Recommendation Remarks</div>
          <div class="field-val"><?= htmlspecialchars($r['RecommendRemarks'] ?: '—') ?></div>
        </div>
        <div>
          <div class="field-lbl">Approved By</div>
          <div class="field-val"><?= htmlspecialchars($r['ApprovedByName'] ?: '— pending —') ?></div>
        </div>
        <div>
          <div class="field-lbl">Date Approved</div>
          <div class="field-val"><?= htmlspecialchars($r['ApprovedDate'] ?: '— pending —') ?></div>
        </div>
        <div>
          <div class="field-lbl">Date Received</div>
          <div class="field-val"><?= htmlspecialchars($r['ReceivedDate'] ?: '— pending —') ?></div>
        </div>
        <div></div>
        <?php if ($isRejected): ?>
        <div>
          <div class="field-lbl">Rejected By</div>
          <div class="field-val"><?= htmlspecialchars($r['RejectedByName'] ?: '—') ?></div>
        </div>
        <div>
          <div class="field-lbl">Date Rejected</div>
          <div class="field-val"><?= htmlspecialchars($r['RejectedDate'] ?: '—') ?></div>
        </div>
        <?php if ($r['RejectReason']): ?>
        <div class="full">
          <div class="field-lbl">Reject Reason</div>
          <div class="field-val"><?= htmlspecialchars($r['RejectReason']) ?></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($r['Remarks']): ?>
        <div class="full">
          <div class="field-lbl">Remarks</div>
          <div class="field-val"><?= htmlspecialchars($r['Remarks']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.detail-card -->
  </div><!-- /centering wrapper -->

  <?php if (in_array($r['Status'], ['Received', 'Paid']) && !empty($schedule)): ?>
  <div style="display:flex; justify-content:center; margin-top:1rem;">
  <div class="detail-card" style="width:100%;">
    <div class="detail-card-header" style="background:var(--surface-alt,#f8fafc); color:var(--text-main);">
      <div class="left"><h4 style="color:var(--text-main);"><i class="bi bi-calendar3"></i> Payment Schedule</h4></div>
      <div class="right">
        <div class="amt-lbl" style="color:var(--text-muted);">Balance</div>
        <div class="amt" style="color:<?= (float)($r['BalanceAmount'] ?? 0) > 0 ? '#ef4444' : '#10b981' ?>;">
          ₱<?= number_format((float)($r['BalanceAmount'] ?? 0), 2) ?>
        </div>
      </div>
    </div>
    <div class="detail-body">
      <table class="table" style="width:100%; font-size:.85rem;">
        <thead>
          <tr style="border-bottom:2px solid var(--border);">
            <th style="padding:.5rem;">#</th>
            <th style="padding:.5rem;">Due Date</th>
            <th style="padding:.5rem; text-align:right;">Amount</th>
            <th style="padding:.5rem; text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($schedule as $i => $sr):
            $paid = !empty($sr['PaymentID']);
            $raw_due = $sr['Due_Date'] ?? '';
            $due_str = ($raw_due instanceof DateTime) ? $raw_due->format('M d, Y') : (is_string($raw_due) ? $raw_due : '—');
          ?>
          <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:.5rem; color:var(--text-muted);"><?= $i + 1 ?></td>
            <td style="padding:.5rem;"><?= htmlspecialchars($due_str) ?></td>
            <td style="padding:.5rem; text-align:right; font-weight:600;">₱<?= number_format((float)($sr['Amortization_Amount'] ?? 0), 2) ?></td>
            <td style="padding:.5rem; text-align:center;">
              <?php if ($paid): ?>
                <span style="color:#065f46; font-weight:700; font-size:.78rem;"><i class="bi bi-check-circle-fill"></i> Paid</span>
              <?php else: ?>
                <span style="color:var(--text-muted); font-size:.78rem;"><i class="bi bi-clock"></i> Pending</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  </div>
  <?php endif; ?>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>