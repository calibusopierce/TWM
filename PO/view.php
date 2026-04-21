<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) { header("Location: index.php"); exit; }

$res = sqlsrv_query($conn,
    "SELECT po.*, cat.category_name FROM purchase_orders po
     JOIN po_categories cat ON cat.category_id = po.category_id
     WHERE po.po_id = ?", [$po_id]);
$po = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
if (!$po) { echo "PO not found."; exit; }

$items_res = sqlsrv_query($conn,
    "SELECT * FROM po_items WHERE po_id = ? ORDER BY line_no", [$po_id]);
$items = [];
while ($r = sqlsrv_fetch_array($items_res, SQLSRV_FETCH_ASSOC)) $items[] = $r;

$date_str = ($po['po_date'] instanceof DateTime) ? $po['po_date']->format('M d, Y') : $po['po_date'];
$created  = isset($_GET['created']);
$updated  = isset($_GET['updated']);

$bs_map = ['Draft'=>'bs-draft','Approved'=>'bs-approved','Cancelled'=>'bs-cancelled'];
$bs = $bs_map[$po['status']] ?? 'bs-draft';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($po['po_number']) ?> · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
    .info-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); overflow:hidden; box-shadow:var(--shadow-sm); }
    .info-card-header { background:var(--surface-alt,#f8fafc); border-bottom:1px solid var(--border);
                        padding:.6rem 1rem; font-size:.78rem; font-weight:700;
                        text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted);
                        display:flex; align-items:center; gap:.4rem; }
    .info-card-header i { color:var(--primary); }
    .info-card-body { padding:.9rem 1rem; font-size:.88rem; line-height:1.8; color:var(--text-main); }
    .info-card-body .name { font-weight:700; font-size:.95rem; color:var(--text-main); }

    .po-table { width:100%; border-collapse:collapse; font-size:.86rem; }
    .po-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .po-table th { padding:.6rem .85rem; font-size:.74rem; font-weight:700;
                   text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .po-table th.num { text-align:right; }
    .po-table td { padding:.6rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .po-table .num { text-align:right; font-weight:600; }
    .po-table .grand-row { background:var(--primary); }
    .po-table .grand-row td { color:#fff; font-weight:800; font-size:.95rem; border:none; }

    .badge-status { display:inline-flex; align-items:center; gap:.3rem;
                    padding:.22rem .65rem; border-radius:999px;
                    font-size:.75rem; font-weight:700; letter-spacing:.03em; }
    .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
    .bs-draft     { background:rgba(245,158,11,.12); color:#b45309; }
    .bs-draft::before     { background:#f59e0b; }
    .bs-approved  { background:rgba(16,185,129,.12); color:#065f46; }
    .bs-approved::before  { background:#10b981; }
    .bs-cancelled { background:rgba(239,68,68,.12);  color:#991b1b; }
    .bs-cancelled::before { background:#ef4444; }

    .sig-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1rem; }
    .sig-box { border-top:2px solid var(--primary); padding-top:.6rem; }
    .sig-box .sig-name { font-weight:700; font-size:.95rem; color:var(--text-main); }
    .sig-box .sig-title { font-size:.8rem; color:var(--text-muted); font-style:italic; }
    .sig-label { font-size:.82rem; color:var(--text-muted); margin-bottom:.75rem; }
  </style>
</head>
<body>
<?php $topbar_page = 'po'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title"><?= htmlspecialchars($po['po_number']) ?></div>
      <div class="page-subtitle">
        <span class="badge-status <?= $bs ?>"><?= $po['status'] ?></span>
        &nbsp;·&nbsp; <?= $date_str ?>
        &nbsp;·&nbsp; <?= htmlspecialchars($po['category_name']) ?>
      </div>
    </div>
    <div style="display:flex;gap:.6rem;">
      <a href="<?= base_url('PO/index.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-arrow-left"></i> Back
      </a>
      <a href="<?= base_url('PO/edit.php?id='.$po_id) ?>" class="btn btn-secondary-custom">
        <i class="bi bi-pencil-fill"></i> Edit
      </a>
      <a href="<?= base_url('PO/print.php?id='.$po_id) ?>" class="btn btn-add" target="_blank">
        <i class="bi bi-printer-fill"></i> Print
      </a>
    </div>
  </div>

  <?php if ($created): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Purchase Order created successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($updated): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i> Purchase Order updated successfully.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Vendor & Ship To -->
  <div class="info-grid">
    <div class="info-card">
      <div class="info-card-header"><i class="bi bi-building"></i> Vendor</div>
      <div class="info-card-body">
        <div class="name"><?= htmlspecialchars($po['vendor_company']) ?></div>
        <?= htmlspecialchars($po['vendor_contact'] ?? '') ?><br>
        <?= htmlspecialchars($po['vendor_address'] ?? '') ?><br>
        <?= htmlspecialchars($po['vendor_phone']   ?? '') ?>
      </div>
    </div>
    <div class="info-card">
      <div class="info-card-header"><i class="bi bi-geo-alt-fill"></i> Ship To</div>
      <div class="info-card-body">
        <div class="name"><?= htmlspecialchars($po['ship_to_name']) ?></div>
        <?= htmlspecialchars($po['ship_to_company'] ?? '') ?><br>
        <?= htmlspecialchars($po['ship_to_address'] ?? '') ?><br>
        <?= htmlspecialchars($po['ship_to_phone']   ?? '') ?>
      </div>
    </div>
  </div>

  <!-- Items Table -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-cart-fill" style="color:var(--primary-light);"></i>
        Items
        <span class="count-chip"><?= count($items) ?> item<?= count($items)!==1?'s':'' ?></span>
      </div>
    </div>
    <div class="table-responsive">
      <table class="po-table">
        <thead>
          <tr>
            <th style="width:55px;text-align:center;">QTY</th>
            <th>Description</th>
            <th class="num">Cash Price</th>
            <th class="num">% Price</th>
            <th class="num">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr>
            <td style="text-align:center;font-weight:700;"><?= $item['quantity'] ?></td>
            <td><?= htmlspecialchars($item['description']) ?></td>
            <td class="num">₱ <?= number_format($item['cash_price'], 2) ?></td>
            <td class="num">₱ <?= number_format($item['percent_price'], 2) ?></td>
            <td class="num">₱ <?= number_format($item['total_price'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
          <!-- Subtotal -->
          <tr style="background:var(--surface-alt,#f8fafc);">
            <td colspan="4" style="text-align:right;font-weight:700;font-size:.82rem;
                color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;padding:.5rem .85rem;">
              Subtotal</td>
            <td class="num">₱ <?= number_format($po['subtotal'], 2) ?></td>
          </tr>
          <?php if ($po['tax_amount'] > 0): ?>
          <tr style="background:var(--surface-alt,#f8fafc);">
            <td colspan="4" style="text-align:right;font-weight:700;font-size:.82rem;color:var(--text-muted);padding:.5rem .85rem;">Tax</td>
            <td class="num">₱ <?= number_format($po['tax_amount'], 2) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($po['shipping_amount'] > 0): ?>
          <tr style="background:var(--surface-alt,#f8fafc);">
            <td colspan="4" style="text-align:right;font-weight:700;font-size:.82rem;color:var(--text-muted);padding:.5rem .85rem;">Shipping</td>
            <td class="num">₱ <?= number_format($po['shipping_amount'], 2) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($po['other_amount'] > 0): ?>
          <tr style="background:var(--surface-alt,#f8fafc);">
            <td colspan="4" style="text-align:right;font-weight:700;font-size:.82rem;color:var(--text-muted);padding:.5rem .85rem;">Other</td>
            <td class="num">₱ <?= number_format($po['other_amount'], 2) ?></td>
          </tr>
          <?php endif; ?>
          <tr class="grand-row">
            <td colspan="4" style="text-align:right;padding:.6rem .85rem;">TOTAL</td>
            <td class="num">₱ <?= number_format($po['total_amount'], 2) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Signatories -->
  <div class="table-card" style="margin-top:1rem;">
    <div class="table-card-header">
      <div class="table-card-title"><i class="bi bi-pen-fill" style="color:var(--primary-light);"></i> Signatories</div>
    </div>
    <div style="padding:1.25rem;">
      <div class="sig-grid">
        <div>
          <div class="sig-label">Prepared by:</div>
          <div class="sig-box">
            <div class="sig-name"><?= htmlspecialchars($po['prepared_by'] ?? '—') ?></div>
            <div class="sig-title"><?= htmlspecialchars($po['prepared_title'] ?? '') ?></div>
          </div>
        </div>
        <div>
          <div class="sig-label">Approved by:</div>
          <div class="sig-box">
            <div class="sig-name"><?= htmlspecialchars($po['approved_by'] ?? '—') ?></div>
            <div class="sig-title"><?= htmlspecialchars($po['approved_title'] ?? '') ?></div>
            <div class="sig-title"><?= htmlspecialchars($po['ship_to_company'] ?? '') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>