<?php
// TWM/PO/print.php — Print-ready layout, exact Urban Tradewell Corp. format
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) die("Invalid PO.");

$res = sqlsrv_query($conn,
    "SELECT po.*, cat.category_name FROM purchase_orders po
     JOIN po_categories cat ON cat.category_id = po.category_id
     WHERE po.po_id = ?", [$po_id]);
$po = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
if (!$po) die("PO not found.");

$items_res = sqlsrv_query($conn,
    "SELECT * FROM po_items WHERE po_id = ? ORDER BY line_no", [$po_id]);
$items = [];
while ($r = sqlsrv_fetch_array($items_res, SQLSRV_FETCH_ASSOC)) $items[] = $r;
while (count($items) < 8) $items[] = null; // pad blank rows

$date_str = ($po['po_date'] instanceof DateTime) ? $po['po_date']->format('m/d/Y') : $po['po_date'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>PO <?= htmlspecialchars($po['po_number']) ?></title>
  <style>
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial, Helvetica, sans-serif; font-size:11px; color:#000; background:#f0f0f0; }

    /* Print bar */
    .print-bar { width:700px; margin:12px auto 0; display:flex; justify-content:flex-end; gap:.5rem; padding:0 30px 8px; }
    .print-bar button {
      padding:7px 18px; border:none; border-radius:6px; cursor:pointer;
      font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:.4rem; }
    .btn-print-go { background:#1a3a5c; color:#fff; }
    .btn-print-go:hover { background:#2e6da4; }
    .btn-close-win { background:#6c757d; color:#fff; }
    @media print {
      .print-bar { display:none !important; }
      body { background:#fff; }
      .page { margin:0; border:none; box-shadow:none; }
    }

    /* Page */
    .page { width:700px; margin:0 auto 24px; padding:30px 32px;
            background:#fff; border:1px solid #ccc;
            box-shadow:0 4px 24px rgba(0,0,0,.1); }

    /* Header */
    .po-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; }
    .company-name { font-size:14px; font-weight:700; text-decoration:underline; font-style:italic; }
    .company-info { font-size:10.5px; line-height:1.6; margin-top:2px; }
    .po-title-block { text-align:right; }
    .po-title { font-size:28px; font-weight:700; color:#2e6da4; letter-spacing:1px; }
    .date-row { display:flex; align-items:center; gap:8px; justify-content:flex-end; margin-top:6px; font-size:10.5px; }
    .date-box { border:1px solid #888; padding:2px 12px; min-width:110px; font-size:10.5px; }

    /* Address boxes */
    .addr-row { display:flex; gap:10px; margin-bottom:14px; }
    .addr-box { flex:1; border:1px solid #888; }
    .addr-head { background:#1a3a5c; color:#fff; font-weight:700; font-size:10.5px; padding:4px 9px; }
    .addr-body { padding:6px 9px; font-size:10.5px; line-height:1.7; }
    .addr-name { font-weight:700; font-style:italic; }

    /* Items table */
    .items-table { width:100%; border-collapse:collapse; font-size:10.5px; margin-bottom:6px; }
    .items-table thead tr { background:#1a3a5c; color:#fff; }
    .items-table th { padding:5px 7px; border:1px solid #1a3a5c; text-align:center; font-weight:700; }
    .items-table td { padding:4px 7px; border:1px solid #ccc; vertical-align:middle; }
    .items-table .c-qty   { width:55px;  text-align:center; }
    .items-table .c-desc  { text-align:center; }
    .items-table .c-num   { width:105px; text-align:right; }
    .items-table .c-total { width:105px; text-align:right; font-weight:600; }
    .items-table .blank   { text-align:center; color:#bbb; }
    .empty-row td { height:22px; }

    /* Totals */
    .totals-wrap { display:flex; justify-content:flex-end; margin-bottom:18px; }
    .totals-table { width:340px; border-collapse:collapse; font-size:10.5px; }
    .totals-table td { padding:3px 9px; border-bottom:1px solid #e0e0e0; }
    .t-lbl { font-weight:600; text-align:right; width:160px; }
    .t-val { text-align:right; min-width:110px; border-left:1px solid #e0e0e0; padding-left:10px; }
    .grand-row { background:#1a3a5c; }
    .grand-row td { color:#fff; font-weight:700; font-size:11.5px; border:none; }

    /* Signatures */
    .sig-section { margin-top:28px; }
    .sig-prepared-label, .sig-approved-label { font-size:10.5px; margin-bottom:18px; }
    .sig-approved-label { margin-top:12px; }
    .sig-name { font-weight:700; font-size:11.5px; }
    .sig-title { font-style:italic; font-size:10.5px; }
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
  <div class="po-header">
    <div>
      <div class="company-name">URBAN TRADEWELL CORP.</div>
      <div class="company-info">
        Sta. Monica St Lourdes Subd, Ibabang Iyam<br>
        Lucena City, 4301<br>
        Phone: (042) 788 -0765<br><br>
        Email: creative.tradewell@gmail.com
      </div>
    </div>
    <div class="po-title-block">
      <div class="po-title">PURCHASE ORDER</div>
      <div class="date-row">
        <span>DATE</span>
        <div class="date-box"><?= htmlspecialchars($date_str) ?></div>
      </div>
    </div>
  </div>

  <!-- Vendor & Ship To -->
  <div class="addr-row">
    <div class="addr-box">
      <div class="addr-head">VENDOR</div>
      <div class="addr-body">
        <?= htmlspecialchars($po['vendor_company']) ?><br>
        <?= htmlspecialchars($po['vendor_contact'] ?? '') ?><br>
        <?= htmlspecialchars($po['vendor_address'] ?? '') ?><br>
        <?= htmlspecialchars($po['vendor_phone']   ?? '') ?>
      </div>
    </div>
    <div class="addr-box">
      <div class="addr-head">SHIP TO</div>
      <div class="addr-body">
        <div class="addr-name"><?= htmlspecialchars($po['ship_to_name']) ?></div>
        <?= htmlspecialchars($po['ship_to_company'] ?? '') ?><br>
        <?= htmlspecialchars($po['ship_to_address'] ?? '') ?><br>
        <?= htmlspecialchars($po['ship_to_phone']   ?? '') ?>
      </div>
    </div>
  </div>

  <!-- Items -->
  <table class="items-table">
    <thead>
      <tr>
        <th class="c-qty">QTY</th>
        <th class="c-desc">DESCRIPTION</th>
        <th class="c-num">CASH PRICE</th>
        <th class="c-num">% PRICE</th>
        <th class="c-total">TOTAL</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <?php if ($item): ?>
      <tr>
        <td class="c-qty"><?= $item['quantity'] ?></td>
        <td class="c-desc"><?= htmlspecialchars($item['description']) ?></td>
        <td class="c-num"><?= number_format($item['cash_price'],    2) ?></td>
        <td class="c-num"><?= number_format($item['percent_price'], 2) ?></td>
        <td class="c-total"><?= number_format($item['total_price'], 2) ?></td>
      </tr>
      <?php else: ?>
      <tr class="empty-row">
        <td class="c-qty"></td><td class="c-desc"></td>
        <td class="c-num"></td><td class="c-num"></td>
        <td class="c-total blank">-</td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals-wrap">
    <table class="totals-table">
      <tr><td class="t-lbl">SUBTOTAL</td>
          <td class="t-val"><?= $po['subtotal']>0 ? number_format($po['subtotal'],2) : '-' ?></td></tr>
      <tr><td class="t-lbl">TAX</td>
          <td class="t-val"><?= $po['tax_amount']>0 ? number_format($po['tax_amount'],2) : '-' ?></td></tr>
      <tr><td class="t-lbl">SHIPPING</td>
          <td class="t-val"><?= $po['shipping_amount']>0 ? number_format($po['shipping_amount'],2) : '-' ?></td></tr>
      <tr><td class="t-lbl">OTHER</td>
          <td class="t-val"><?= $po['other_amount']>0 ? number_format($po['other_amount'],2) : '-' ?></td></tr>
      <tr class="grand-row">
        <td class="t-lbl">TOTAL</td>
        <td class="t-val">&#8369; <?= number_format($po['total_amount'],2) ?></td>
      </tr>
    </table>
  </div>

  <!-- Signatures -->
  <div class="sig-section">
    <div class="sig-prepared-label">Prepared by:</div>
    <div style="margin-left:12px;">
      <div class="sig-name"><?= htmlspecialchars($po['prepared_by'] ?? '') ?></div>
      <div class="sig-title"><?= htmlspecialchars($po['prepared_title'] ?? '') ?></div>
    </div>

    <div class="sig-approved-label" style="margin-top:20px;">Approved by:</div>
    <div style="margin-left:12px;">
      <div class="sig-name"><?= htmlspecialchars($po['approved_by'] ?? '') ?></div>
      <div class="sig-title"><?= htmlspecialchars($po['approved_title'] ?? '') ?></div>
      <div class="sig-title"><?= htmlspecialchars($po['ship_to_company'] ?? 'Urban Tradewell Corp.') ?></div>
    </div>
  </div>

</div><!-- /page -->
</body>
</html>