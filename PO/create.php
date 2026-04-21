<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

$error = '';

// Auto-generate PO number
$sql_num = "SELECT TOP 1 po_number FROM purchase_orders ORDER BY po_id DESC";
$res     = sqlsrv_query($conn, $sql_num);
$last    = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);
$next_no = 1;
if ($last) { preg_match('/(\d+)$/', $last['po_number'], $m); $next_no = (int)($m[1] ?? 0) + 1; }
$new_po_number = 'PO-' . date('Y') . '-' . str_pad($next_no, 4, '0', STR_PAD_LEFT);

// Categories
$cats_q     = sqlsrv_query($conn, "SELECT category_id, category_name FROM po_categories ORDER BY category_name");
$categories = [];
while ($r = sqlsrv_fetch_array($cats_q, SQLSRV_FETCH_ASSOC)) $categories[] = $r;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $po_number       = trim($_POST['po_number']);
    $category_id     = (int)$_POST['category_id'];
    $po_date         = trim($_POST['po_date']);
    $vendor_company  = trim($_POST['vendor_company']);
    $vendor_contact  = trim($_POST['vendor_contact']);
    $vendor_address  = trim($_POST['vendor_address']);
    $vendor_phone    = trim($_POST['vendor_phone']);
    $ship_to_name    = trim($_POST['ship_to_name']);
    $ship_to_company = trim($_POST['ship_to_company']);
    $ship_to_address = trim($_POST['ship_to_address']);
    $ship_to_phone   = trim($_POST['ship_to_phone']);
    $tax_amount      = floatval($_POST['tax_amount']);
    $shipping_amount = floatval($_POST['shipping_amount']);
    $other_amount    = floatval($_POST['other_amount']);
    $prepared_by     = trim($_POST['prepared_by']);
    $prepared_title  = trim($_POST['prepared_title']);
    $approved_by     = trim($_POST['approved_by']);
    $approved_title  = trim($_POST['approved_title']);
    $status          = $_POST['status'];

    $items    = [];
    $subtotal = 0;
    if (isset($_POST['item_desc']) && is_array($_POST['item_desc'])) {
        foreach ($_POST['item_desc'] as $i => $desc) {
            if (trim($desc) === '') continue;
            $qty    = (int)  $_POST['item_qty'][$i];
            $cprice = floatval($_POST['item_cash_price'][$i]);
            $pprice = floatval($_POST['item_pct_price'][$i]);
            $total  = $qty * $pprice;
            $subtotal += $total;
            $items[] = compact('desc','qty','cprice','pprice','total');
        }
    }
    $grand_total = $subtotal + $tax_amount + $shipping_amount + $other_amount;

    if (empty($po_number) || empty($vendor_company) || $category_id === 0) {
        $error = "PO Number, Category, and Vendor Company are required.";
    } else {
        // OUTPUT INSERTED gets the new ID in the same round-trip — required for sqlsrv
        $ins = "INSERT INTO purchase_orders
            (po_number,category_id,po_date,vendor_company,vendor_contact,vendor_address,vendor_phone,
             ship_to_name,ship_to_company,ship_to_address,ship_to_phone,
             subtotal,tax_amount,shipping_amount,other_amount,total_amount,
             status,prepared_by,prepared_title,approved_by,approved_title,created_by)
            OUTPUT INSERTED.po_id
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $p = [
            $po_number, $category_id, $po_date,
            $vendor_company, $vendor_contact, $vendor_address, $vendor_phone,
            $ship_to_name, $ship_to_company, $ship_to_address, $ship_to_phone,
            $subtotal, $tax_amount, $shipping_amount, $other_amount, $grand_total,
            $status, $prepared_by, $prepared_title, $approved_by, $approved_title,
            $_SESSION['user_id'] ?? null
        ];

        $res2 = sqlsrv_query($conn, $ins, $p);
        if ($res2 === false) {
            $error = "Database error: " . print_r(sqlsrv_errors(), true);
        } else {
            // Fetch the returned ID directly from OUTPUT INSERTED
            $id_row = sqlsrv_fetch_array($res2, SQLSRV_FETCH_ASSOC);
            $po_id  = $id_row['po_id'];

            foreach ($items as $ln => $item) {
                sqlsrv_query($conn,
                    "INSERT INTO po_items
                        (po_id, line_no, description, cash_price, percent_price, quantity, total_price)
                     VALUES (?,?,?,?,?,?,?)",
                    [$po_id, ($ln+1), $item['desc'], $item['cprice'], $item['pprice'], $item['qty'], $item['total']]
                );
            }
            header("Location: view.php?id=$po_id&created=1"); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Purchase Order · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .form-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); margin-bottom:1.25rem; overflow:hidden; }
    .form-card-header { padding:.75rem 1.25rem; border-bottom:1px solid var(--border);
                        display:flex; align-items:center; gap:.5rem;
                        font-weight:700; font-size:.88rem; color:var(--text-main);
                        background:var(--surface-alt,#f8fafc); }
    .form-card-header i { color:var(--primary); }
    .form-card-body { padding:1.25rem; }
    .form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .form-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
    .form-group { display:flex; flex-direction:column; gap:.3rem; }
    .form-group label { font-size:.78rem; font-weight:700; color:var(--text-muted);
                        text-transform:uppercase; letter-spacing:.04em; }
    .form-group input, .form-group select {
      padding:.48rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
      font-size:.88rem; color:var(--text-main); background:var(--surface);
      transition:border-color .15s; }
    .form-group input:focus, .form-group select:focus {
      outline:none; border-color:var(--primary);
      box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    .form-group input[readonly] { background:var(--surface-alt,#f8fafc); color:var(--text-muted); }

    /* Items table */
    .items-tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
    .items-tbl thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .items-tbl th { padding:.55rem .7rem; font-size:.73rem; font-weight:700; text-transform:uppercase;
                    letter-spacing:.05em; color:var(--text-muted); text-align:left; }
    .items-tbl th.num { text-align:right; }
    .items-tbl td { padding:.4rem .5rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .items-tbl td input {
      width:100%; padding:.35rem .55rem; border:1px solid var(--border);
      border-radius:var(--radius); font-size:.84rem; background:var(--surface);
      color:var(--text-main); transition:border-color .15s; }
    .items-tbl td input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 2px rgba(59,130,246,.1); }
    .row-total { text-align:right; font-weight:700; color:var(--primary); font-size:.88rem; padding-right:.7rem !important; }
    .btn-remove-row { width:26px; height:26px; border-radius:6px; border:1px solid rgba(239,68,68,.3);
                      background:rgba(239,68,68,.07); color:#ef4444; cursor:pointer;
                      display:inline-flex; align-items:center; justify-content:center; font-size:.75rem; }
    .btn-remove-row:hover { background:rgba(239,68,68,.18); }

    /* Totals box */
    .totals-box { margin-left:auto; max-width:320px; }
    .totals-box table { width:100%; border-collapse:collapse; font-size:.87rem; }
    .totals-box td { padding:.4rem .75rem; border-bottom:1px solid var(--border); }
    .totals-box .t-label { color:var(--text-muted); font-weight:600; }
    .totals-box .t-val { text-align:right; }
    .totals-box .t-val input {
      width:130px; text-align:right; padding:.3rem .55rem;
      border:1px solid var(--border); border-radius:var(--radius);
      font-size:.87rem; background:var(--surface); color:var(--text-main); }
    .totals-box .t-val input[readonly] { background:var(--surface-alt,#f8fafc); font-weight:700; border:none; }
    .grand-row { background:var(--primary) !important; }
    .grand-row td { color:#fff !important; font-weight:800 !important; font-size:.95rem; border:none !important; }
    .grand-row .t-val input { background:transparent !important; color:#fff !important;
                               font-weight:800 !important; border:none !important; width:140px; }
  </style>
</head>
<body>
<?php $topbar_page = 'po'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">New Purchase Order</div>
      <div class="page-subtitle">Fill in the details below to create a new PO</div>
    </div>
    <a href="<?= base_url('PO/index.php') ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to List
    </a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <form method="POST" id="po-form">

    <!-- PO Details -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-file-earmark-text-fill"></i> PO Details</div>
      <div class="form-card-body">
        <div class="form-grid-4">
          <div class="form-group">
            <label>PO Number *</label>
            <input type="text" name="po_number" value="<?= htmlspecialchars($new_po_number) ?>" required>
          </div>
          <div class="form-group">
            <label>Date *</label>
            <input type="date" name="po_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Category *</label>
            <select name="category_id" required>
              <option value="">-- Select --</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status">
              <option value="Draft">Draft</option>
              <option value="Approved">Approved</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Vendor & Ship To -->
    <div class="form-grid-2">
      <div class="form-card">
        <div class="form-card-header"><i class="bi bi-building"></i> Vendor</div>
        <div class="form-card-body" style="display:flex;flex-direction:column;gap:.85rem;">
          <div class="form-group"><label>Company Name *</label>
            <input type="text" name="vendor_company" placeholder="e.g. Star Honda Inc." required></div>
          <div class="form-group"><label>Contact Person</label>
            <input type="text" name="vendor_contact" placeholder="e.g. Mr. Angelo Supremo"></div>
          <div class="form-group"><label>Address</label>
            <input type="text" name="vendor_address" placeholder="Brgy., City"></div>
          <div class="form-group"><label>Phone</label>
            <input type="text" name="vendor_phone" placeholder="0950 930 7198"></div>
        </div>
      </div>
      <div class="form-card">
        <div class="form-card-header"><i class="bi bi-geo-alt-fill"></i> Ship To</div>
        <div class="form-card-body" style="display:flex;flex-direction:column;gap:.85rem;">
          <div class="form-group"><label>Recipient Name *</label>
            <input type="text" name="ship_to_name" value="Rozaldie B. Chua" required></div>
          <div class="form-group"><label>Company</label>
            <input type="text" name="ship_to_company" value="Urban Tradewell Corp"></div>
          <div class="form-group"><label>Address</label>
            <input type="text" name="ship_to_address" value="Sta. Monica St. Lourdes Subd., Ibabang Iyam, Lucena City, Quezon Province 4301"></div>
          <div class="form-group"><label>Phone</label>
            <input type="text" name="ship_to_phone" value="(042) 788-0765"></div>
        </div>
      </div>
    </div>

    <!-- Items -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-cart-fill"></i> Items</div>
      <div class="form-card-body">
        <div class="table-responsive">
          <table class="items-tbl">
            <thead>
              <tr>
                <th style="width:40px;">#</th>
                <th>Description</th>
                <th style="width:75px;">Qty</th>
                <th style="width:130px;" class="num">Cash Price</th>
                <th style="width:130px;" class="num">% Price</th>
                <th style="width:120px;" class="num">Total</th>
                <th style="width:40px;"></th>
              </tr>
            </thead>
            <tbody id="items-body"></tbody>
          </table>
        </div>
        <button type="button" class="btn btn-secondary-custom mt-3" style="font-size:.82rem;" onclick="addRow()">
          <i class="bi bi-plus-lg"></i> Add Item
        </button>
      </div>
    </div>

    <!-- Totals -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-calculator-fill"></i> Totals</div>
      <div class="form-card-body">
        <div class="totals-box">
          <table>
            <tr><td class="t-label">Subtotal</td>
                <td class="t-val"><input type="text" id="subtotal" name="subtotal" readonly placeholder="0.00"></td></tr>
            <tr><td class="t-label">Tax</td>
                <td class="t-val"><input type="number" id="tax" name="tax_amount" step="0.01" value="0" onchange="recalc()"></td></tr>
            <tr><td class="t-label">Shipping</td>
                <td class="t-val"><input type="number" id="shipping" name="shipping_amount" step="0.01" value="0" onchange="recalc()"></td></tr>
            <tr><td class="t-label">Other</td>
                <td class="t-val"><input type="number" id="other" name="other_amount" step="0.01" value="0" onchange="recalc()"></td></tr>
            <tr class="grand-row">
              <td class="t-label">TOTAL</td>
              <td class="t-val"><input type="text" id="grand_total" readonly placeholder="0.00"></td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <!-- Signatories -->
    <div class="form-card">
      <div class="form-card-header"><i class="bi bi-pen-fill"></i> Signatories</div>
      <div class="form-card-body">
        <div class="form-grid-4">
          <div class="form-group"><label>Prepared By</label>
            <input type="text" name="prepared_by" placeholder="Full Name"></div>
          <div class="form-group"><label>Title / Position</label>
            <input type="text" name="prepared_title" placeholder="e.g. Purchasing Officer"></div>
          <div class="form-group"><label>Approved By</label>
            <input type="text" name="approved_by" placeholder="Full Name"></div>
          <div class="form-group"><label>Title / Position</label>
            <input type="text" name="approved_title" placeholder="e.g. Corporate President"></div>
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div style="display:flex; justify-content:flex-end; gap:.75rem; margin-bottom:2rem;">
      <a href="<?= base_url('PO/index.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-x-lg"></i> Cancel
      </a>
      <button type="submit" class="btn btn-add">
        <i class="bi bi-floppy-fill"></i> Save Purchase Order
      </button>
    </div>

  </form>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
let rowCount = 0;
function addRow(desc='', qty=1, cash=0, pct=0) {
  rowCount++;
  const n = rowCount;
  const total = (qty * pct).toFixed(2);
  const tr = document.createElement('tr');
  tr.id = 'row-' + n;
  tr.innerHTML = `
    <td style="text-align:center;color:var(--text-muted);font-size:.78rem;font-weight:600;">${n}</td>
    <td><input type="text" name="item_desc[]" value="${desc}" placeholder="Item description"></td>
    <td><input type="number" name="item_qty[]" value="${qty}" min="1" oninput="recalcRow(${n})"></td>
    <td><input type="number" name="item_cash_price[]" value="${cash}" step="0.01" oninput="recalcRow(${n})"></td>
    <td><input type="number" name="item_pct_price[]" value="${pct}" step="0.01" oninput="recalcRow(${n})"></td>
    <td class="row-total">
      <span id="rt-${n}">${fmt(total)}</span>
      <input type="hidden" name="item_total[]" id="ht-${n}" value="${total}">
    </td>
    <td style="text-align:center;">
      <button type="button" class="btn-remove-row" onclick="removeRow(${n})"><i class="bi bi-x-lg"></i></button>
    </td>`;
  document.getElementById('items-body').appendChild(tr);
  recalc();
}
function removeRow(n) { const r=document.getElementById('row-'+n); if(r){r.remove();recalc();} }
function recalcRow(n) {
  const row=document.getElementById('row-'+n); if(!row) return;
  const qty=parseFloat(row.querySelector('[name="item_qty[]"]').value)||0;
  const pct=parseFloat(row.querySelector('[name="item_pct_price[]"]').value)||0;
  const tot=qty*pct;
  document.getElementById('rt-'+n).textContent=fmt(tot.toFixed(2));
  document.getElementById('ht-'+n).value=tot.toFixed(2);
  recalc();
}
function recalc() {
  let sub=0;
  document.querySelectorAll('[name="item_total[]"]').forEach(el=>{sub+=parseFloat(el.value)||0;});
  const tax=parseFloat(document.getElementById('tax').value)||0;
  const ship=parseFloat(document.getElementById('shipping').value)||0;
  const oth=parseFloat(document.getElementById('other').value)||0;
  document.getElementById('subtotal').value=fmt(sub.toFixed(2));
  document.getElementById('grand_total').value=fmt((sub+tax+ship+oth).toFixed(2));
}
function fmt(n){ return parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2}); }
for(let i=0;i<5;i++) addRow();
</script>
</body>
</html>