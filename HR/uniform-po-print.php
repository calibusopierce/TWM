<?php
// uniform-po-print.php — Printable Purchase Order Document
// Opens in a new tab and triggers window.print() automatically.
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
$poid = intval($_GET['poid'] ?? 0);
if (!$poid) die('No PO specified.');

$po = rq($conn2, "SELECT * FROM [dbo].[UniformPO] WHERE POID=?", [$poid]);
if (empty($po)) die('PO not found.');
$po = $po[0];

$items = rq($conn2,
    "SELECT * FROM [dbo].[UniformPOItems] WHERE POID=?
     ORDER BY UniformType,
     CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4
               WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END",
    [$poid]);

// Build map: $itemMap[UniformType][Size] = ['Requested'=>x, 'Additional'=>y]
// Keys stored exactly as DB column names (capital R and A)
$itemMap = [];
$sizes   = ['XS','S','M','L','XL','XXL','XXXL','4XL'];
// Keyed array so foreach gives meaningful $type => $label
$uTypes  = ['TSHIRT' => 'T-Shirt (Logistics)', 'POLOSHIRT' => 'Polo Shirt (Office/Sales)'];

foreach ($items as $it) {
    $itemMap[$it['UniformType']][$it['Size']] = [
        'Requested'  => intval($it['Requested']),
        'Additional' => intval($it['Additional']),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PO — <?= safe($po['PONumber']) ?></title>
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

  .co-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    border-bottom: 2.5px solid #1e3a5f; padding-bottom: 10px; margin-bottom: 12px;
  }
  .co-name { font-size: 15pt; font-weight: 900; color: #1e3a5f; letter-spacing: .02em; }
  .co-sub  { font-size: 8.5pt; color: #777; margin-top: 2px; }
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
  .po-tbl { width: 100%; border-collapse: collapse; margin-bottom: 0; font-size: 9.5pt; }
  .po-tbl th {
    background: #1e3a5f; color: #fff; padding: 5px 8px;
    text-align: center; font-size: 8.5pt; letter-spacing: .04em; border: 1px solid #15305a;
  }
  .po-tbl th:first-child { text-align: left; }
  .po-tbl td { padding: 4px 8px; border: 1px solid #d5dce8; text-align: center; }
  .po-tbl td:first-child { text-align: left; font-weight: 600; color: #333; }
  .po-tbl tr:nth-child(even) td { background: #f5f8fc; }
  .po-tbl .sub-total-row td { background: #dce6f4; font-weight: 800; color: #1e3a5f; }
  .po-tbl .zero { color: #bbb; }

  .grand-summary {
    display: flex; gap: 16px; justify-content: flex-end; align-items: center;
    margin-top: 12px; padding: 8px 12px;
    background: #e8eef8; border: 1.5px solid #c5d4ea; border-radius: 6px;
  }
  .gs-item { text-align: right; }
  .gs-lbl { font-size: 8pt; color: #777; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
  .gs-val { font-size: 13pt; font-weight: 900; color: #1e3a5f; }

  .remarks-box {
    margin-top: 12px; padding: 8px 12px;
    border: 1.5px solid #ddd; border-radius: 6px; background: #fafafa;
    font-size: 9pt; color: #555;
  }
  .remarks-box strong { color: #333; font-size: 8pt; text-transform: uppercase; letter-spacing: .06em; }

  .note-bar {
    margin-top: 14px; padding: 7px 12px;
    border: 1.5px solid #f0c060; border-radius: 6px; background: #fffbea;
    font-size: 8.5pt; color: #7a5a00; font-weight: 600;
  }

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
  <span>&#128447; Purchase Order — <?= safe($po['PONumber']) ?></span>
  <button class="btn-print" onclick="window.print()">
    &#128438; Print / Save PDF
  </button>
</div>

<div class="doc">

  <!-- Company Header -->
  <div class="co-header">
    <div>
      <div class="co-name">URBAN TRADEWELL CORP.</div>
      <div class="co-sub">Uniform Purchase Order · Internal Finance Record</div>
    </div>
    <div class="doc-badge">PURCHASE ORDER</div>
  </div>

  <!-- Meta -->
  <div class="meta-grid">
    <div class="meta-cell">
      <div class="meta-lbl">PO Number</div>
      <div class="meta-val"><?= safe($po['PONumber']) ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-lbl">PO Date</div>
      <div class="meta-val"><?= fmtDate($po['PODate']) ?></div>
    </div>
    <div class="meta-cell">
      <div class="meta-lbl">Prepared By</div>
      <div class="meta-val"><?= safe($po['CreatedBy'] ?? '—') ?></div>
    </div>
  </div>

  <?php
  $grandReq = 0; $grandAdd = 0; $grandTotal = 0;
  // $uTypes is keyed: 'TSHIRT'=>'T-Shirt...', 'POLOSHIRT'=>'Polo Shirt...'
  foreach ($uTypes as $type => $label):
    $typeReq = 0; $typeAdd = 0; $typeTotal = 0;
    foreach ($sizes as $sz) {
      // Keys are 'Requested' and 'Additional' — match exactly what we stored above
      $typeReq   += intval($itemMap[$type][$sz]['Requested']  ?? 0);
      $typeAdd   += intval($itemMap[$type][$sz]['Additional'] ?? 0);
      $typeTotal += intval($itemMap[$type][$sz]['Requested']  ?? 0)
                 + intval($itemMap[$type][$sz]['Additional'] ?? 0);
    }
    $grandReq += $typeReq; $grandAdd += $typeAdd; $grandTotal += $typeTotal;
  ?>

  <div class="type-heading">
    <?= ($type === 'TSHIRT') ? '👕' : '👔' ?> <?= safe($label) ?>
  </div>
  <table class="po-tbl">
    <thead>
      <tr>
        <th style="width:130px;">Category</th>
        <?php foreach ($sizes as $sz): ?><th><?= $sz ?></th><?php endforeach; ?>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <!-- Requested row -->
      <tr>
        <td>Requested (pcs)</td>
        <?php $rowT = 0; foreach ($sizes as $sz):
          $v = intval($itemMap[$type][$sz]['Requested'] ?? 0); $rowT += $v; ?>
        <td class="<?= $v ? '' : 'zero' ?>"><?= $v ? $v : '—' ?></td>
        <?php endforeach; ?>
        <td style="font-weight:800;"><?= $rowT ?></td>
      </tr>
      <!-- Additional row -->
      <tr>
        <td>Additional (pcs)</td>
        <?php $rowT = 0; foreach ($sizes as $sz):
          $v = intval($itemMap[$type][$sz]['Additional'] ?? 0); $rowT += $v; ?>
        <td class="<?= $v ? '' : 'zero' ?>"><?= $v ? $v : '—' ?></td>
        <?php endforeach; ?>
        <td style="font-weight:800;"><?= $rowT ?></td>
      </tr>
      <!-- Sub-total row -->
      <tr class="sub-total-row">
        <td>TOTAL PIECES</td>
        <?php foreach ($sizes as $sz):
          $t = intval($itemMap[$type][$sz]['Requested']  ?? 0)
             + intval($itemMap[$type][$sz]['Additional'] ?? 0); ?>
        <td><?= $t ? $t : '—' ?></td>
        <?php endforeach; ?>
        <td><?= $typeTotal ?></td>
      </tr>
    </tbody>
  </table>

  <?php endforeach; ?>

  <!-- Grand Summary -->
  <div class="grand-summary">
    <div class="gs-item">
      <div class="gs-lbl">Total Requested</div>
      <div class="gs-val"><?= number_format($grandReq) ?> <span style="font-size:9pt;font-weight:600;color:#555;">pcs</span></div>
    </div>
    <div style="width:1px;background:#c5d4ea;align-self:stretch;"></div>
    <div class="gs-item">
      <div class="gs-lbl">Total Additional</div>
      <div class="gs-val"><?= number_format($grandAdd) ?> <span style="font-size:9pt;font-weight:600;color:#555;">pcs</span></div>
    </div>
    <div style="width:1px;background:#c5d4ea;align-self:stretch;"></div>
    <div class="gs-item">
      <div class="gs-lbl">Grand Total Pieces</div>
      <div class="gs-val" style="font-size:16pt;"><?= number_format($grandTotal) ?> <span style="font-size:9pt;font-weight:600;color:#555;">pcs</span></div>
    </div>
  </div>

  <?php if (!empty(trim($po['Remarks'] ?? ''))): ?>
  <div class="remarks-box"><strong>Remarks:</strong> &nbsp;<?= safe($po['Remarks']) ?></div>
  <?php endif; ?>

  <div class="note-bar">
    &#9432; This document is an <strong>internal PO request</strong> for finance records only. It is not a supplier purchase order.
  </div>

  <!-- Approval / Sign-off -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-top:22px;">
    <?php foreach (['Prepared By', 'Checked By', 'Approved By'] as $role): ?>
    <div style="border:1.5px solid #ccc;border-radius:6px;padding:10px 12px;">
      <div style="font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:32px;"><?= $role ?></div>
      <div style="border-top:1.5px solid #111;padding-top:4px;font-size:8pt;color:#555;">Signature over Printed Name</div>
    </div>
    <?php endforeach; ?>
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