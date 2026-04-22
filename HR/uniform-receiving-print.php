<?php
// uniform-receiving-print.php — Printable Receiving Document
// Opens in a new tab, auto-triggers print dialog.
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

// ── RBAC gate ────────────────────────────────────────────────
$pdo_rbac = new PDO(
    "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
rbac_gate($pdo_rbac, 'uniform_inventory');

// ── Helpers (defined BEFORE use) ──────────────────────────────
if (!function_exists('rq')) {
    function rq($conn2, $sql, $p = []) {
        $stmt = empty($p) ? sqlsrv_query($conn2,$sql) : sqlsrv_query($conn2,$sql,$p);
        if (!$stmt) return [];
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
        sqlsrv_free_stmt($stmt);
        return $rows;
    }
}
if (!function_exists('safe')) {
    function safe($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fmtDate')) {
    function fmtDate($v) {
        if (!$v) return '—';
        if ($v instanceof DateTime) return $v->format('M d, Y');
        return is_string($v) ? date('M d, Y', strtotime($v)) : '—';
    }
}

// ── Fetch data ────────────────────────────────────────────────
$recid = intval($_GET['recid'] ?? 0);
if (!$recid) die('No receiving record specified.');

$rec = rq($conn2,
    "SELECT r.*, p.PONumber, p.PODate, p.Remarks AS PORemarks
     FROM [dbo].[UniformReceiving] r
     LEFT JOIN [dbo].[UniformPO] p ON p.POID = r.POID
     WHERE r.RFID=?", [$recid]);
if (empty($rec)) die('Receiving record not found.');
$rec = $rec[0];

$items = rq($conn2,
    "SELECT * FROM [dbo].[UniformReceivingItems] WHERE RFID=?", [$recid]);

$itemMap = [];
$sizes   = ['XS','S','M','L','XL','XXL','XXXL','4XL'];
// Keyed so foreach gives $type => $label
$uTypes  = ['TSHIRT' => 'T-Shirt (Logistics)', 'POLOSHIRT' => 'Polo Shirt (Office/Sales)'];

foreach ($items as $it) {
    $type = strtoupper(trim($it['UniformType']));
    $size = strtoupper(trim($it['Size']));
    $itemMap[$type][$size] = intval($it['Quantity']);
}

$grandTotal = 0;
foreach ($items as $it) $grandTotal += intval($it['Quantity']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receiving — <?= safe($rec['PONumber'] ?? 'REC-'.$recid) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 10.5pt; color: #111; background: #fff; padding: 28px 32px; }

  /* ── Screen toolbar ── */
  .screen-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    background: #1e3a5f; color: #fff; padding: 10px 16px; border-radius: 8px;
    margin-bottom: 18px;
  }
  .screen-toolbar span { font-size: 10pt; font-weight: 700; }
  .btn-print {
    background: #fff; color: #1e3a5f; border: none; padding: 7px 18px;
    border-radius: 6px; font-size: 9.5pt; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .btn-print:hover { background: #e8f0fb; }
  @media print { .screen-toolbar { display: none !important; } }

  /* ── Document ── */
  .doc { max-width: 760px; margin: 0 auto; }

  .company-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 2.5px solid #1e3a5f; padding-bottom: 10px; margin-bottom: 14px;
  }
  .company-name { font-size: 15pt; font-weight: 900; color: #1e3a5f; letter-spacing: .02em; }
  .company-sub  { font-size: 8.5pt; color: #777; margin-top: 2px; }
  .doc-badge {
    background: #1e3a5f; color: #fff; font-size: 10pt; font-weight: 800;
    padding: 6px 16px; border-radius: 6px; letter-spacing: .05em; white-space: nowrap;
    align-self: flex-start;
  }

  .meta-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 0;
    border: 1.5px solid #ccc; border-radius: 6px; overflow: hidden;
    margin-bottom: 16px;
  }
  .meta-cell { padding: 7px 12px; border-right: 1px solid #ddd; }
  .meta-cell:last-child { border-right: none; }
  .meta-lbl { font-size: 7.5pt; font-weight: 700; text-transform: uppercase;
              letter-spacing: .06em; color: #888; margin-bottom: 2px; }
  .meta-val { font-size: 10.5pt; font-weight: 800; color: #1e3a5f; }

  .type-heading {
    font-size: 10pt; font-weight: 800; color: #1e3a5f;
    background: #e8eef8; border: 1.5px solid #c5d4ea;
    padding: 5px 10px; border-radius: 5px 5px 0 0;
    margin-top: 14px; margin-bottom: 0;
    display: flex; align-items: center; gap: 6px;
  }
  .rec-tbl { width: 100%; border-collapse: collapse; margin-bottom: 0; font-size: 9.5pt; }
  .rec-tbl th {
    background: #1e3a5f; color: #fff; padding: 5px 8px;
    text-align: center; font-size: 8.5pt; letter-spacing: .04em; border: 1px solid #15305a;
  }
  .rec-tbl th:first-child { text-align: left; }
  .rec-tbl td { padding: 4px 8px; border: 1px solid #d5dce8; text-align: center; }
  .rec-tbl td:first-child { text-align: left; font-weight: 600; color: #333; }
  .rec-tbl tr:nth-child(even) td { background: #f5f8fc; }
  .rec-tbl .zero { color: #bbb; }

  .grand-summary {
    display: flex; gap: 16px; justify-content: flex-end; align-items: center;
    margin-top: 12px; padding: 8px 12px;
    background: #e8eef8; border: 1.5px solid #c5d4ea; border-radius: 6px;
  }
  .gs-lbl { font-size: 8pt; color: #777; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
  .gs-val { font-size: 16pt; font-weight: 900; color: #1e3a5f; }

  .divider { border: none; border-top: 1.5px solid #ddd; margin: 16px 0; }

  .sig-section { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 18px; }
  .sig-box { border: 1.5px solid #ccc; border-radius: 7px; padding: 10px 14px; }
  .sig-label { font-size: 7.5pt; font-weight: 700; text-transform: uppercase;
               letter-spacing: .07em; color: #888; margin-bottom: 4px; }
  .sig-name-val { font-size: 10.5pt; font-weight: 800; color: #1e3a5f; margin-bottom: 8px; }
  .sig-line { border-top: 1.5px solid #111; margin-top: 40px; padding-top: 4px;
              font-size: 8pt; color: #555; }

  .footer-line {
    margin-top: 22px; border-top: 1px solid #ddd; padding-top: 7px;
    font-size: 7.5pt; color: #aaa; text-align: center;
  }

  @media print {
    body { padding: 12px 18px; }
    .doc { max-width: 100%; }
  }
</style>
</head>
<body>

<div class="screen-toolbar">
  <span>&#128230; Receiving Form — <?= safe($rec['PONumber'] ?? 'REC-'.$recid) ?></span>
  <button class="btn-print" onclick="window.print()">
    &#128438; Print / Save PDF
  </button>
</div>

<div class="doc">

  <!-- Company Header -->
  <div class="company-header">
    <div>
      <div class="company-name">URBAN TRADEWELL CORP.</div>
      <div class="company-sub">Uniform Receiving Document</div>
    </div>
    <div class="doc-badge">RECEIVING FORM</div>
  </div>

  <!-- Meta -->
  <div class="meta-grid">
    <div class="meta-cell">
      <div class="meta-lbl">PO Number</div>
      <div class="meta-val"><?= safe($rec['PONumber'] ?? '—') ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-lbl">PO Date</div>
      <div class="meta-val"><?= fmtDate($rec['PODate']) ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-lbl">Date Received</div>
      <div class="meta-val"><?= fmtDate($rec['DateReceived'] ?? $rec['RFDate']) ?></div>
    </div>
  </div>

  <?php if (!empty(trim($rec['PORemarks'] ?? ''))): ?>
  <div style="padding:7px 12px;background:#fffbea;border:1.5px solid #f0c060;border-radius:6px;margin-bottom:12px;font-size:9pt;color:#7a5a00;">
    <strong>PO Remarks:</strong> <?= safe($rec['PORemarks']) ?>
  </div>
  <?php endif; ?>

  <!-- Quantities per uniform type -->
  <?php foreach ($uTypes as $type => $label):
    $typeTotal = 0;
  ?>
  <div class="type-heading">
    <?= ($type === 'TSHIRT') ? '👕' : '👔' ?> <?= safe($label) ?>
  </div>
  <table class="rec-tbl">
    <thead>
      <tr>
        <th style="width:130px;">Category</th>
        <?php foreach ($sizes as $sz): ?><th><?= $sz ?></th><?php endforeach; ?>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Qty Received (pcs)</td>
        <?php foreach ($sizes as $sz):
          $qty = intval($itemMap[strtoupper(trim($type))][strtoupper(trim($sz))] ?? 0);
          $typeTotal += $qty; ?>
        <td class="<?= $qty ? '' : 'zero' ?>"><?= $qty ? $qty : '—' ?></td>
        <?php endforeach; ?>
        <td style="font-weight:800;"><?= $typeTotal ?></td>
      </tr>
    </tbody>
  </table>
  <?php endforeach; ?>

  <!-- Grand total -->
  <div class="grand-summary">
    <div>
      <div class="gs-lbl">Grand Total Pieces Received</div>
      <div class="gs-val"><?= number_format($grandTotal) ?> <span style="font-size:9pt;font-weight:600;color:#555;">pcs</span></div>
    </div>
  </div>

  <hr class="divider">

  <!-- Signatures -->
  <div class="sig-section">
    <div class="sig-box">
      <div class="sig-label">Printing Shop Representative</div>
      <div class="sig-name-val"><?= safe($rec['RepresentativeThem'] ?? '') ?></div>
      <div class="sig-line">Signature over Printed Name</div>
    </div>
    <div class="sig-box">
      <div class="sig-label">Urban Tradewell Corp Representative</div>
      <div class="sig-name-val"><?= safe($rec['RepresentativeUs'] ?? '') ?></div>
      <div class="sig-line">Signature over Printed Name</div>
    </div>
  </div>

  <div class="footer-line">
    Generated by Uniform Inventory System &nbsp;·&nbsp; Urban Tradewell Corp. &nbsp;·&nbsp; <?= date('M d, Y h:i A') ?>
  </div>

</div><!-- /doc -->

<script>
  window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>