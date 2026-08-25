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
  /* ── Page: half-bond 8.5 × 5.5 in ─────────────────────────── */
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

  /* ── Page wrapper ── */
  .page {
    width: 5.5in;
    height: 8.5in;
    margin: 20px auto;
    background: #fff;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 12px rgba(0,0,0,.15);
  }

  /* ── Each slip takes exactly half the page ── */
    .slip {
    flex: 1 1 0;
    padding: 0.15in 0.3in 0.12in;
    display: flex;
    flex-direction: column;
    gap: 4pt;
    overflow: hidden;
    flex-shrink: 0;
    }

  /* ── Cut line between the two slips ── */
  .cut-line {
  flex: 0 0 14pt;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 0.35in;
  color: #9ca3af;
  font-size: 7pt;
  border-top: 1.5px dashed #ccc;
  border-bottom: 1.5px dashed #ccc;
  background: #fff;
}
  .cut-line i { font-size: 8pt; }

  /* ── Slip header row ── */
  .slip-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #ed3a3a;
    padding-bottom: 3pt;
  }
  .co-name { font-size: 9.5pt; font-weight: 800; color: #131e83; line-height: 1.1; }
  .co-sub  { font-size: 6.5pt; color: #6b7280; margin-top: 1px; }
  .slip-title-block { text-align: right; }
  .slip-title { font-size: 9pt; font-weight: 800; color: #1e1b2e; }
  .slip-meta  { font-size: 6.5pt; color: #6b7280; margin-top: 1px; }
  .copy-label {
    display: inline-block;
    background: #131e83; color: #fff;
    font-size: 6pt; font-weight: 800;
    padding: 1pt 6pt; border-radius: 20px;
    letter-spacing: .04em; text-transform: uppercase;
    margin-top: 2pt;
  }

  /* ── Amount banner ── */
  .amt-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f3ff;
    border: 1.5px solid #ed3a3a;
    border-radius: 4px;
    padding: 3pt 10pt;
    gap: 8pt;
  }
  .amt-label { font-size: 6pt; text-transform: uppercase; letter-spacing: .06em; color: #ed3a3a; font-weight: 800; }
  .amt-value { font-size: 15pt; font-weight: 900; color: #1e1b2e; line-height: 1; }

  /* ── Info grid ── */
  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 3pt 10pt;
    flex: 1;
  }
  .info-grid.two-col { grid-template-columns: 1fr 1fr; }
  .info-row { display: flex; flex-direction: column; }
  .info-row.span2 { grid-column: span 2; }
  .info-row.full  { grid-column: 1 / -1; }
  .info-lbl {
    font-size: 5.5pt; text-transform: uppercase; letter-spacing: .05em;
    color: #9ca3af; font-weight: 700; margin-bottom: 1px;
  }
  .info-val {
    font-size: 7.5pt; font-weight: 700; color: #1e1b2e;
    border-bottom: 0.5pt solid #d1d5db;
    padding-bottom: 1pt; min-height: 11pt;
  }

  /* ── Signature row ── */
  .sig-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8pt;
    margin-top: 2pt;
  }
  .sig-block { text-align: center; }
  .sig-line  { border-bottom: 0.5pt solid #374151; height: 14pt; margin-bottom: 2pt; }
  .sig-lbl   { font-size: 6pt; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; font-weight: 700; }
  .sig-name  { font-size: 6.5pt; font-weight: 800; color: #374151; margin-top: 1pt; }

  /* ── Print ── */
  @media print {
  body { background: #fff; }
  .toolbar { display: none; }
  .page {
    /* Keep the true physical 5.5in x 8.5in size — do NOT stretch to
       whatever paper size the print dialog/printer tray defaults to.
       Forcing 100%/100% was the bug: it made the slip scale up to
       fill Letter/A4 instead of printing at true half-bond size. */
    width: 5.5in !important;
    height: 8.5in !important;
    margin: 0 auto !important;
    box-shadow: none;
    border-radius: 0;
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
  $slipNo   = '# ' . str_pad($r['CashAdvanceID'], 5, '0', STR_PAD_LEFT);
  $copies   = ['Employee Copy', 'Office Copy'];

  foreach ($copies as $copyIdx => $copyLabel):
  ?>

  <!-- ══ SLIP ══════════════════════════════════════════════════ -->
  <div class="slip">

    <!-- Header -->
    <div class="slip-head">
      <div>
        <div class="co-name">Urban Tradewell Corp.</div>
        <div class="co-sub">Cash Advance / Vale Slip</div>
      </div>
      <div class="slip-title-block">
        <div class="slip-title">VALE SLIP &nbsp; <span style="font-size:8pt;color:#6b7280;"><?= $slipNo ?></span></div>
        <div class="slip-meta"><?= htmlspecialchars($r['RequestDate'] ?: date('M d, Y')) ?></div>
        <div><span class="copy-label"><?= $copyLabel ?></span></div>
      </div>
    </div>

    <!-- Amount -->
    <div class="amt-banner">
      <div class="amt-label">Amount</div>
      <div class="amt-value">₱ <?= number_format($r['Amount'], 2) ?></div>
    </div>

    <!-- Employee info -->
    <div class="info-grid">
      <div class="info-row span2">
        <div class="info-lbl">Employee Name</div>
        <div class="info-val"><?= htmlspecialchars($r['EmployeeName'] ?: '—') ?></div>
      </div>
      <div class="info-row">
        <div class="info-lbl">Position</div>
        <div class="info-val"><?= htmlspecialchars($r['Position_held'] ?: '—') ?></div>
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
        <div class="info-lbl">Status</div>
        <div class="info-val"><?= htmlspecialchars($r['Status']) ?></div>
      </div>
      <div class="info-row span2">
        <div class="info-lbl">Reason / Purpose</div>
        <div class="info-val"><?= htmlspecialchars($r['Reason'] ?: '—') ?></div>
      </div>
      <div class="info-row">
        <div class="info-lbl">Date Approved</div>
        <div class="info-val"><?= htmlspecialchars($r['ApprovedDate'] ?: '—') ?></div>
      </div>
    </div>

    <!-- Signatures -->
    <div class="sig-row">
      <div class="sig-block">
        <div class="sig-line"></div>
        <div class="sig-lbl">Requested By</div>
        <div class="sig-name"><?= htmlspecialchars($r['EmployeeName'] ?: '') ?></div>
      </div>
      <div class="sig-block">
        <div class="sig-line"></div>
        <div class="sig-lbl">Noted By</div>
        <div class="sig-name"><?= htmlspecialchars($r['RecommendByName'] ?: '') ?></div>
      </div>
      <div class="sig-block">
        <div class="sig-line"></div>
        <div class="sig-lbl">Approved By</div>
        <div class="sig-name"><?= htmlspecialchars($r['ApprovedByName'] ?: '') ?></div>
      </div>
    </div>

  </div><!-- /.slip -->

  <?php if ($copyIdx === 0): ?>
  <!-- ══ CUT LINE ══════════════════════════════════════════════ -->
  <div class="cut-line">
    <i class="bi bi-scissors"></i>
    cut here &nbsp;·&nbsp; cut here &nbsp;·&nbsp; cut here &nbsp;·&nbsp; cut here &nbsp;·&nbsp; cut here &nbsp;·&nbsp; cut here &nbsp;·&nbsp; cut here
  </div>
  <?php endif; ?>

  <?php endforeach; ?>

</div><!-- /.page -->

</body>
</html>