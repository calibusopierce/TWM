<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

$perms   = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$isAdmin = isset($perms['cash_advance_record']);
if (!$isAdmin && !isset($perms['cash_advance'])) rbac_gate($pdo, 'cash_advance');

$EmployeeID = $_SESSION['EmployeeID'];
$id = $_GET['id'] ?? null;
if (!$id || !ctype_digit((string)$id)) die('Invalid request ID.');

// Which copy is being printed — defaults to Employee Copy. Print again with
// ?copy=Office to get the other copy (each print job is one slip only now).
$copyLabel = (($_GET['copy'] ?? '') === 'Office') ? 'Office Copy' : 'Employee Copy';

$sql = "SELECT
            ca.CashAdvanceID, ca.Amount, ca.Reason, ca.Department, ca.Branch,
            ca.Remarks, ca.RecommendRemarks, ca.Status,
            CONVERT(varchar(20), ca.RequestDate,  107) AS RequestDate,
            CONVERT(varchar(20), ca.ApprovedDate, 107) AS ApprovedDate,
            CONVERT(varchar(20), ca.ReceivedDate, 107) AS ReceivedDate,
            emp.FirstName  + ' ' + emp.LastName  AS EmployeeName,
            emp.Position_held,
            rec.FirstName  + ' ' + rec.LastName  AS RecommendByName,
            appr.FirstName + ' ' + appr.LastName AS ApprovedByName
        FROM TBL_CashAdvance ca
        LEFT JOIN TBL_HrEmployeeList emp  ON emp.EmployeeID  = ca.EmployeeID
        LEFT JOIN TBL_HrEmployeeList rec  ON rec.EmployeeID  = ca.RecommendByID
        LEFT JOIN TBL_HrEmployeeList appr ON appr.EmployeeID = ca.ApprovedByID
        WHERE ca.CashAdvanceID = ?" . (!$isAdmin ? " AND ca.EmployeeID = ?" : "");

$params = $isAdmin ? [$id] : [$id, $EmployeeID];
$stmt   = sqlsrv_query($conn, $sql, $params);
$r      = ($stmt !== false) ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
if (!$r) die('Record not found or access denied.');

// ── Pending (unpaid) schedule rows — the new "pending payment dates" list ──
$pend_res = sqlsrv_query($conn, "
    SELECT Due_Date, Amortization_Amount
    FROM TBL_CashAdvance_Statement
    WHERE CashAdvanceID = ? AND PaymentID IS NULL
    ORDER BY Due_Date ASC", [$id]);
$pending = [];
if ($pend_res) while ($pr = sqlsrv_fetch_array($pend_res, SQLSRV_FETCH_ASSOC)) $pending[] = $pr;
$pendingCount = count($pending);
// Shrink the row font a bit once the list gets long, so more rows fit before
// they'd otherwise get clipped by the fixed quarter-page height.
$pendRowFont = $pendingCount > 8 ? '6pt' : '6.8pt';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vale Slip #<?= $r['CashAdvanceID'] ?> · Tradewell</title>
<link rel="icon" href="<?= base_url('assets/img/logo.png') ?>">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<style>
  /* ── Page: half-bond 5.5 × 8.5 in, PORTRAIT physical sheet ──────
     Only the TOP HALF (5.5in × 4.25in — already a landscape-shaped
     rectangle) is ever printed on. The bottom half is intentionally
     left blank on every print job. ─────────────────────────────── */
  @page {
    size: 5.5in 8.5in;
    margin: 0;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: Arial, sans-serif;
    font-size: 9pt;
    background: #f0f0f0;
    color: #111;
  }

  /* ── Toolbar (hidden on print) ── */
  .toolbar {
    background: #fff;
    padding: 10px 16px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-bottom: 1px solid #e5e7eb;
  }
  .btn-t {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 16px; border-radius: 6px;
    font-size: 0.8rem; font-weight: 600; cursor: pointer;
    border: 1.5px solid #e5e7eb; background: #fff; color: #374151;
    text-decoration: none; transition: background .15s;
  }
  .btn-t:hover { background: #f3f4f6; }
  .btn-t.primary { background: #2538e4; color: #fff; border-color: #131e83; }
  .btn-t.primary:hover { background: #131e83; }

  /* ── Page wrapper (screen preview only) ── */
  .page {
    width: 5.5in;
    height: 8.5in;
    margin: 20px auto;
    background: #fff;
    box-shadow: 0 2px 12px rgba(0,0,0,.15);
    position: relative;
  }

  /* ── The single slip — top half only, landscape-shaped ── */
  .slip {
    position: absolute;
    top: 0; left: 0;
    width: 5.5in;
    height: 4.25in;
    padding: 0.14in 0.22in;
    display: flex;
    flex-direction: row;
    gap: 10pt;
    overflow: hidden;
  }

  /* Visual marker (screen only) showing the unused bottom half */
  .blank-half {
    position: absolute;
    top: 4.25in; left: 0;
    width: 5.5in; height: 4.25in;
    display: flex; align-items: center; justify-content: center;
    color: #d1d5db; font-size: 7pt; letter-spacing: .06em;
    text-transform: uppercase;
  }
  @media print { .blank-half { display: none; } }

  /* ── Left column: request details + signatures ── */
  .slip-left {
    flex: 1.5;
    display: flex;
    flex-direction: column;
    gap: 4pt;
    min-width: 0;
  }

  .slip-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #ed3a3a;
    padding-bottom: 3pt;
  }
  .co-name { font-size: 9pt; font-weight: 800; color: #131e83; line-height: 1.1; }
  .co-sub  { font-size: 6pt; color: #6b7280; margin-top: 1px; }
  .slip-title-block { text-align: right; }
  .slip-title { font-size: 8pt; font-weight: 800; color: #1e1b2e; }
  .slip-meta  { font-size: 6pt; color: #6b7280; margin-top: 1px; }
  .copy-label {
    display: inline-block;
    background: #131e83; color: #fff;
    font-size: 5.5pt; font-weight: 800;
    padding: 1pt 5pt; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    margin-top: 2pt;
  }

  .amt-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f3ff;
    border: 1.5px solid #ed3a3a;
    border-radius: 4px;
    padding: 2pt 8pt;
    gap: 6pt;
  }
  .amt-label { font-size: 5.5pt; text-transform: uppercase; letter-spacing: .06em; color: #ed3a3a; font-weight: 800; }
  .amt-value { font-size: 12pt; font-weight: 900; color: #1e1b2e; line-height: 1; }

  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 2pt 8pt;
  }
  .info-row.span2 { grid-column: span 2; }
  .info-row.full  { grid-column: 1 / -1; }
  .info-lbl {
    font-size: 5pt; text-transform: uppercase; letter-spacing: .05em;
    color: #9ca3af; font-weight: 700; margin-bottom: 0;
  }
  .info-val {
    font-size: 6.6pt; font-weight: 700; color: #1e1b2e;
    border-bottom: 0.5pt solid #d1d5db;
    padding-bottom: 1pt; min-height: 9pt; line-height: 1.2;
  }

  .sig-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6pt;
    margin-top: auto;
  }
  .sig-block { text-align: center; }
  .sig-line  { border-bottom: 0.5pt solid #374151; height: 10pt; margin-bottom: 1pt; }
  .sig-lbl   { font-size: 5pt; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .sig-name  { font-size: 5.5pt; font-weight: 800; color: #374151; margin-top: 1pt; }

  /* ── Right column: pending payment dates ── */
  .slip-right {
    flex: 1;
    min-width: 0;
    border-left: 1px dashed #ccc;
    padding-left: 8pt;
    display: flex;
    flex-direction: column;
    gap: 3pt;
  }
  .pend-title {
    font-size: 6pt; font-weight: 800; text-transform: uppercase;
    letter-spacing: .05em; color: #131e83;
    border-bottom: 1px solid #e5e7eb; padding-bottom: 2pt;
  }
  .pend-table { width: 100%; border-collapse: collapse; font-size: <?= $pendRowFont ?>; }
  .pend-table th {
    text-align: left; font-weight: 700; color: #6b7280;
    text-transform: uppercase; letter-spacing: .03em;
    padding: 1pt 2pt; border-bottom: 0.5pt solid #d1d5db;
  }
  .pend-table td { padding: 1.5pt 2pt; border-bottom: 0.5pt dotted #e5e7eb; }
  .pend-table td.amt { text-align: right; font-weight: 700; }
  .pend-empty { font-size: 6.5pt; color: #9ca3af; font-style: italic; padding-top: 4pt; }
  .pend-total {
    margin-top: auto; padding-top: 3pt; border-top: 1px solid #e5e7eb;
    display: flex; justify-content: space-between; font-size: 6.5pt; font-weight: 800;
  }

  /* ── Print ── */
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .page {
      width: 5.5in !important;
      height: 8.5in !important;
      margin: 0 auto !important;
      box-shadow: none;
    }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn-t" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
  <button class="btn-t primary" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
</div>

<div class="page">

  <?php
  $slipNo  = '# ' . str_pad($r['CashAdvanceID'], 5, '0', STR_PAD_LEFT);
  $pendTotal = array_sum(array_column($pending, 'Amortization_Amount'));
  ?>

  <div class="slip">

    <!-- Left: request details + signatures -->
    <div class="slip-left">
      <div class="slip-head">
        <div>
          <div class="co-name">Urban Tradewell Corp.</div>
          <div class="co-sub">Cash Advance / Vale Slip</div>
        </div>
        <div class="slip-title-block">
          <div class="slip-title">VALE SLIP &nbsp; <span style="font-size:7pt;color:#6b7280;"><?= $slipNo ?></span></div>
          <div class="slip-meta"><?= htmlspecialchars($r['RequestDate'] ?: date('M d, Y')) ?></div>
          <div><span class="copy-label"><?= htmlspecialchars($copyLabel) ?></span></div>
        </div>
      </div>

      <div class="amt-banner">
        <div class="amt-label">Amount</div>
        <div class="amt-value">₱ <?= number_format($r['Amount'], 2) ?></div>
      </div>

      <div class="info-grid">
        <div class="info-row span2">
          <div class="info-lbl">Employee Name</div>
          <div class="info-val"><?= htmlspecialchars($r['EmployeeName'] ?: '—') ?></div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Status</div>
          <div class="info-val"><?= htmlspecialchars($r['Status']) ?></div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Department</div>
          <div class="info-val"><?= htmlspecialchars($r['Department'] ?: '—') ?></div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Branch</div>
          <div class="info-val"><?= htmlspecialchars($r['Branch'] ?: '—') ?></div>
        </div>
        <div class="info-row">
          <div class="info-lbl">Date Approved</div>
          <div class="info-val"><?= htmlspecialchars($r['ApprovedDate'] ?: '—') ?></div>
        </div>
        <div class="info-row full">
          <div class="info-lbl">Reason / Purpose</div>
          <div class="info-val"><?= htmlspecialchars($r['Reason'] ?: '—') ?></div>
        </div>
      </div>

      <div class="sig-row">
        <div class="sig-block">
          <div class="sig-line"></div>
          <div class="sig-lbl">Requested By</div>
          <div class="sig-name"><?= htmlspecialchars($r['EmployeeName'] ?: '') ?></div>
        </div>
        <div class="sig-block">
          <div class="sig-line"></div>
          <div class="sig-lbl">Approved By</div>
          <div class="sig-name"><?= htmlspecialchars($r['ApprovedByName'] ?: '') ?></div>
        </div>
      </div>
    </div><!-- /.slip-left -->

    <!-- Right: pending payment dates -->
    <div class="slip-right">
      <div class="pend-title"><i class="bi bi-calendar3"></i> Pending Payments</div>

      <?php if (empty($pending)): ?>
        <div class="pend-empty">No pending payment dates.</div>
      <?php else: ?>
        <table class="pend-table">
          <thead>
            <tr><th>Due Date</th><th style="text-align:right;">Amount</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $p):
              $raw_due = $p['Due_Date'] ?? '';
              $due_str = ($raw_due instanceof DateTime) ? $raw_due->format('M d, Y') : (is_string($raw_due) ? $raw_due : '—');
            ?>
            <tr>
              <td><?= htmlspecialchars($due_str) ?></td>
              <td class="amt">₱<?= number_format((float)($p['Amortization_Amount'] ?? 0), 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="pend-total">
          <span>Total Pending</span>
          <span>₱<?= number_format((float)$pendTotal, 2) ?></span>
        </div>
      <?php endif; ?>
    </div><!-- /.slip-right -->

  </div><!-- /.slip -->

  <div class="blank-half">bottom half intentionally left blank</div>

</div><!-- /.page -->

</body>
</html>