<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

// Filters
$filter_category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$filter_status   = isset($_GET['status'])   ? $_GET['status']         : '';
$filter_search   = isset($_GET['search'])   ? trim($_GET['search'])    : '';

$where  = "WHERE 1=1";
$params = [];
if ($filter_category > 0) { $where .= " AND po.category_id = ?"; $params[] = $filter_category; }
if ($filter_status !== '')  { $where .= " AND po.status = ?";      $params[] = $filter_status; }
if ($filter_search !== '') {
    $where .= " AND (po.po_number LIKE ? OR po.vendor_company LIKE ?)";
    $params[] = "%$filter_search%";
    $params[] = "%$filter_search%";
}

$sql = "
    SELECT po.po_id, po.po_number, po.po_date,
           cat.category_name, po.vendor_company,
           po.vendor_contact, po.total_amount,
           po.status, po.prepared_by
    FROM purchase_orders po
    JOIN po_categories cat ON cat.category_id = po.category_id
    $where
    ORDER BY po.po_date DESC, po.po_id DESC
";
$stmt = sqlsrv_query($conn, $sql, $params);

// Summary counts
$counts = ['total'=>0,'Draft'=>0,'Approved'=>0,'Cancelled'=>0];
$count_res = sqlsrv_query($conn, "SELECT status, COUNT(*) AS cnt FROM purchase_orders GROUP BY status");
while ($r = sqlsrv_fetch_array($count_res, SQLSRV_FETCH_ASSOC)) {
    $counts[$r['status']] = $r['cnt'];
    $counts['total'] += $r['cnt'];
}

// Categories for filter dropdown
$cats_q = sqlsrv_query($conn, "SELECT category_id, category_name FROM po_categories ORDER BY category_name");
$categories = [];
while ($r = sqlsrv_fetch_array($cats_q, SQLSRV_FETCH_ASSOC)) $categories[] = $r;

// Collect rows
$rows_data = [];
if ($stmt) while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows_data[] = $r;
$rowCount = count($rows_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Orders · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .stat-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 1rem 1.2rem;
      display: flex; align-items: center; gap: .85rem;
      box-shadow: var(--shadow-sm);
    }
    .stat-icon {
      width: 40px; height: 40px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.15rem; flex-shrink: 0;
    }
    .stat-val { font-size: 1.5rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .stat-lbl { font-size: .72rem; color: var(--text-muted); margin-top: .15rem;
                font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .si-total    { background:rgba(59,130,246,.12);  color:#3b82f6; }
    .si-draft    { background:rgba(245,158,11,.12);  color:#f59e0b; }
    .si-approved { background:rgba(16,185,129,.12);  color:#10b981; }
    .si-cancel   { background:rgba(239,68,68,.12);   color:#ef4444; }

    .filter-bar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
    .filter-bar input, .filter-bar select {
      padding:.42rem .75rem; border:1px solid var(--border);
      border-radius:var(--radius); font-size:.84rem;
      background:var(--surface); color:var(--text-main); min-width:150px;
    }
    .filter-bar input:focus, .filter-bar select:focus { outline:none; border-color:var(--primary); }

    .po-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .po-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .po-table th { padding:.65rem .85rem; text-align:left; font-size:.74rem; font-weight:700;
                   text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; }
    .po-table td { padding:.6rem .85rem; border-bottom:1px solid var(--border);
                   vertical-align:middle; color:var(--text-main); }
    .po-table tbody tr:hover { background:var(--surface-alt,#f8fafc); }
    .po-number { font-weight:700; color:var(--primary); font-size:.88rem; }
    .vendor-name    { font-weight:600; font-size:.88rem; }
    .vendor-contact { font-size:.74rem; color:var(--text-muted); margin-top:.1rem; }
    .amount-cell    { text-align:right; font-weight:700; font-size:.9rem; }

    .badge-status {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.22rem .65rem; border-radius:999px;
      font-size:.72rem; font-weight:700; letter-spacing:.03em;
    }
    .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
    .bs-draft     { background:rgba(245,158,11,.12); color:#b45309; }
    .bs-draft::before     { background:#f59e0b; }
    .bs-approved  { background:rgba(16,185,129,.12); color:#065f46; }
    .bs-approved::before  { background:#10b981; }
    .bs-cancelled { background:rgba(239,68,68,.12);  color:#991b1b; }
    .bs-cancelled::before { background:#ef4444; }

    .action-wrap { display:flex; gap:.35rem; align-items:center; justify-content:center; }
    .btn-icon {
      width:30px; height:30px; border-radius:7px; border:1px solid var(--border);
      display:inline-flex; align-items:center; justify-content:center;
      font-size:.82rem; cursor:pointer; text-decoration:none;
      transition:background .15s, border-color .15s;
      background:var(--surface); color:var(--text-muted);
    }
    .btn-icon.view  { color:#3b82f6; border-color:rgba(59,130,246,.3);  background:rgba(59,130,246,.07); }
    .btn-icon.edit  { color:#f59e0b; border-color:rgba(245,158,11,.3);  background:rgba(245,158,11,.07); }
    .btn-icon.print { color:#6366f1; border-color:rgba(99,102,241,.3);  background:rgba(99,102,241,.07); }
    .btn-icon.del   { color:#ef4444; border-color:rgba(239,68,68,.3);   background:rgba(239,68,68,.07);  }
    .btn-icon.view:hover  { background:rgba(59,130,246,.18); }
    .btn-icon.edit:hover  { background:rgba(245,158,11,.18); }
    .btn-icon.print:hover { background:rgba(99,102,241,.18); }
    .btn-icon.del:hover   { background:rgba(239,68,68,.18);  }

    .empty-row td { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
  </style>
</head>
<body>

<?php $topbar_page = 'po'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">Purchase Orders</div>
      <div class="page-subtitle">Create and manage company purchase orders by category</div>
    </div>
    <div style="display:flex;gap:.6rem;">
      <a href="<?= base_url('PO/categories.php') ?>" class="btn btn-secondary-custom">
        <i class="bi bi-tags-fill"></i> Categories
      </a>
      <a href="<?= base_url('PO/create.php') ?>" class="btn btn-add">
        <i class="bi bi-plus-lg"></i> New PO
      </a>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-icon si-total"><i class="bi bi-receipt-cutoff"></i></div>
      <div><div class="stat-val"><?= $counts['total'] ?></div><div class="stat-lbl">Total POs</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-draft"><i class="bi bi-pencil-square"></i></div>
      <div><div class="stat-val"><?= $counts['Draft'] ?></div><div class="stat-lbl">Draft</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-approved"><i class="bi bi-check-circle-fill"></i></div>
      <div><div class="stat-val"><?= $counts['Approved'] ?></div><div class="stat-lbl">Approved</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-cancel"><i class="bi bi-x-circle-fill"></i></div>
      <div><div class="stat-val"><?= $counts['Cancelled'] ?></div><div class="stat-lbl">Cancelled</div></div>
    </div>
  </div>

  <!-- Table Card -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
        All Purchase Orders
        <span class="count-chip" id="rowCount"><?= $rowCount ?> record<?= $rowCount !== 1 ? 's' : '' ?></span>
      </div>

      <!-- Filters -->
      <form method="GET" class="filter-bar">
        <input type="text" name="search" placeholder="🔍  PO No. / Vendor…"
               value="<?= htmlspecialchars($filter_search) ?>">
        <select name="category">
          <option value="0">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['category_id'] ?>"
              <?= $filter_category == $cat['category_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="status">
          <option value="">All Status</option>
          <option value="Draft"     <?= $filter_status=='Draft'     ? 'selected':'' ?>>Draft</option>
          <option value="Approved"  <?= $filter_status=='Approved'  ? 'selected':'' ?>>Approved</option>
          <option value="Cancelled" <?= $filter_status=='Cancelled' ? 'selected':'' ?>>Cancelled</option>
        </select>
        <button type="submit" class="btn btn-add" style="padding:.42rem .9rem; font-size:.84rem;">
          <i class="bi bi-funnel-fill"></i> Filter
        </button>
        <?php if ($filter_search || $filter_category || $filter_status): ?>
          <a href="<?= base_url('PO/index.php') ?>" class="btn btn-secondary-custom" style="padding:.42rem .9rem; font-size:.84rem;">
            <i class="bi bi-x-lg"></i> Reset
          </a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-responsive">
      <table class="po-table" id="poTable">
        <thead>
          <tr>
            <th style="width:45px;">#</th>
            <th>PO Number</th>
            <th>Date</th>
            <th>Category</th>
            <th>Vendor</th>
            <th>Prepared By</th>
            <th style="text-align:right;">Total (₱)</th>
            <th style="text-align:center;">Status</th>
            <th style="text-align:center; width:130px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows_data)): ?>
            <tr class="empty-row">
              <td colspan="9">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No purchase orders found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows_data as $i => $row):
              $date_str = ($row['po_date'] instanceof DateTime)
                  ? $row['po_date']->format('M d, Y') : $row['po_date'];
              $bs = 'bs-' . strtolower($row['status']);
            ?>
            <tr>
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i+1 ?></td>
              <td><span class="po-number"><?= htmlspecialchars($row['po_number']) ?></span></td>
              <td style="color:var(--text-muted);font-size:.83rem;"><?= htmlspecialchars($date_str) ?></td>
              <td>
                <span style="font-size:.76rem;background:rgba(99,102,241,.1);color:#4f46e5;
                             padding:.18rem .6rem;border-radius:999px;font-weight:600;">
                  <?= htmlspecialchars($row['category_name']) ?>
                </span>
              </td>
              <td>
                <div class="vendor-name"><?= htmlspecialchars($row['vendor_company']) ?></div>
                <?php if (!empty($row['vendor_contact'])): ?>
                  <div class="vendor-contact"><?= htmlspecialchars($row['vendor_contact']) ?></div>
                <?php endif; ?>
              </td>
              <td style="font-size:.84rem;"><?= htmlspecialchars($row['prepared_by'] ?? '—') ?></td>
              <td class="amount-cell">₱ <?= number_format($row['total_amount'], 2) ?></td>
              <td style="text-align:center;">
                <span class="badge-status <?= $bs ?>"><?= $row['status'] ?></span>
              </td>
              <td>
                <div class="action-wrap">
                  <a href="<?= base_url('PO/view.php?id='.$row['po_id']) ?>"
                     class="btn-icon view" title="View"><i class="bi bi-eye-fill"></i></a>
                  <a href="<?= base_url('PO/edit.php?id='.$row['po_id']) ?>"
                     class="btn-icon edit" title="Edit"><i class="bi bi-pencil-fill"></i></a>
                  <a href="<?= base_url('PO/print.php?id='.$row['po_id']) ?>"
                     class="btn-icon print" title="Print" target="_blank"><i class="bi bi-printer-fill"></i></a>
                  <a href="<?= base_url('PO/delete.php?id='.$row['po_id']) ?>"
                     class="btn-icon del" title="Delete"
                     onclick="return confirm('Delete PO <?= htmlspecialchars($row['po_number']) ?>?')">
                     <i class="bi bi-trash-fill"></i></a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>