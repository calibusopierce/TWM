<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── ADD ──────────────────────────────────────────────────
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['category_name']);
        $desc = trim($_POST['description']);
        if ($name === '') {
            $messages[] = ['type'=>'danger', 'text'=>'Category name is required.'];
        } else {
            $chk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM po_categories WHERE category_name = ?", [$name]);
            $chkRow = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            if ($chkRow['cnt'] > 0) {
                $messages[] = ['type'=>'danger', 'text'=>"Category \"$name\" already exists."];
            } else {
                sqlsrv_query($conn,
                    "INSERT INTO po_categories (category_name, description) VALUES (?,?)", [$name, $desc]);
                $messages[] = ['type'=>'success', 'text'=>"Category \"$name\" added successfully."];
            }
        }
    }

    // ── EDIT ─────────────────────────────────────────────────
    if ($_POST['action'] === 'edit') {
        $cid  = (int)$_POST['cat_id'];
        $name = trim($_POST['category_name']);
        $desc = trim($_POST['description']);
        if ($name === '' || $cid === 0) {
            $messages[] = ['type'=>'danger', 'text'=>'Category name is required.'];
        } else {
            // Check duplicate name but exclude self
            $chk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM po_categories WHERE category_name = ? AND category_id != ?",
                [$name, $cid]);
            $chkRow = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            if ($chkRow['cnt'] > 0) {
                $messages[] = ['type'=>'danger', 'text'=>"Another category named \"$name\" already exists."];
            } else {
                sqlsrv_query($conn,
                    "UPDATE po_categories SET category_name = ?, description = ? WHERE category_id = ?",
                    [$name, $desc, $cid]);
                $messages[] = ['type'=>'success', 'text'=>"Category updated to \"$name\" successfully."];
            }
        }
    }

    // ── DELETE ────────────────────────────────────────────────
    if ($_POST['action'] === 'delete') {
        $cid = (int)$_POST['cat_id'];
        $chk = sqlsrv_query($conn,
            "SELECT COUNT(*) AS cnt FROM purchase_orders WHERE category_id = ?", [$cid]);
        $row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            $messages[] = ['type'=>'danger',
                'text'=>"Cannot delete — this category has {$row['cnt']} Purchase Order(s) linked to it."];
        } else {
            sqlsrv_query($conn, "DELETE FROM po_categories WHERE category_id = ?", [$cid]);
            $messages[] = ['type'=>'success', 'text'=>'Category deleted successfully.'];
        }
    }
}

// Fetch all categories with PO count
$cats = sqlsrv_query($conn, "
    SELECT c.category_id, c.category_name, c.description,
           COUNT(p.po_id) AS po_count
    FROM po_categories c
    LEFT JOIN purchase_orders p ON p.category_id = c.category_id
    GROUP BY c.category_id, c.category_name, c.description
    ORDER BY c.category_name
");
$rows     = [];
while ($r = sqlsrv_fetch_array($cats, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
$rowCount = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>PO Categories · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .add-card { background:var(--surface); border:1px solid var(--border);
                border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
                margin-bottom:1.25rem; overflow:hidden; }
    .add-card-header { padding:.75rem 1.25rem; border-bottom:1px solid var(--border);
                       display:flex; align-items:center; gap:.5rem; font-weight:700;
                       font-size:.88rem; color:var(--text-main); background:var(--surface-alt,#f8fafc); }
    .add-card-header i { color:var(--primary); }
    .add-card-body { padding:1.25rem; }
    .add-form-row { display:flex; gap:.75rem; align-items:flex-end; flex-wrap:wrap; }
    .fg { display:flex; flex-direction:column; gap:.3rem; flex:1; min-width:180px; }
    .fg label { font-size:.78rem; font-weight:700; color:var(--text-muted);
                text-transform:uppercase; letter-spacing:.04em; }
    .fg input { padding:.48rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
                font-size:.88rem; color:var(--text-main); background:var(--surface);
                transition:border-color .15s; }
    .fg input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }

    /* Table */
    .cats-table { width:100%; border-collapse:collapse; font-size:.86rem; }
    .cats-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .cats-table th { padding:.65rem .85rem; font-size:.74rem; font-weight:700;
                     text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .cats-table td { padding:.65rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .cats-table tbody tr:hover { background:var(--surface-alt,#f8fafc); }
    .cat-name { font-weight:700; font-size:.9rem; color:var(--text-main); }
    .cat-desc { font-size:.78rem; color:var(--text-muted); margin-top:.15rem; }
    .po-chip { display:inline-flex; align-items:center; gap:.3rem;
               background:rgba(59,130,246,.1); color:#2563eb;
               padding:.18rem .65rem; border-radius:999px;
               font-size:.76rem; font-weight:700; }
    .in-use-text { font-size:.76rem; color:var(--text-muted); font-style:italic; }

    /* Action buttons */
    .action-wrap { display:flex; gap:.35rem; align-items:center; justify-content:center; }
    .btn-icon {
      width:30px; height:30px; border-radius:7px; border:1px solid var(--border);
      display:inline-flex; align-items:center; justify-content:center;
      font-size:.82rem; cursor:pointer; background:var(--surface); color:var(--text-muted);
      transition:background .15s, border-color .15s; }
    .btn-icon.edit  { color:#f59e0b; border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.07); }
    .btn-icon.edit:hover  { background:rgba(245,158,11,.18); }
    .btn-icon.del   { color:#ef4444; border-color:rgba(239,68,68,.3);  background:rgba(239,68,68,.07); }
    .btn-icon.del:hover   { background:rgba(239,68,68,.18); }

    .empty-row td { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
  </style>
</head>
<body>
<?php $topbar_page = 'po'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">PO Categories</div>
      <div class="page-subtitle">Manage purchase order categories for classification</div>
    </div>
    <a href="<?= base_url('PO/index.php') ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to POs
    </a>
  </div>

  <!-- Alerts -->
  <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= $m['type'] ?> alert-dismissible fade show">
      <?= $m['type']==='success'
          ? '<i class="bi bi-check-circle-fill me-2"></i>'
          : '<i class="bi bi-exclamation-triangle-fill me-2"></i>' ?>
      <?= htmlspecialchars($m['text']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <!-- Add Category Card -->
  <div class="add-card">
    <div class="add-card-header">
      <i class="bi bi-plus-circle-fill"></i> Add New Category
    </div>
    <div class="add-card-body">
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="add-form-row">
          <div class="fg">
            <label>Category Name *</label>
            <input type="text" name="category_name" placeholder="e.g. Vehicles" required>
          </div>
          <div class="fg" style="flex:2;">
            <label>Description</label>
            <input type="text" name="description" placeholder="Short description (optional)">
          </div>
          <button type="submit" class="btn btn-add" style="padding:.48rem 1.1rem; white-space:nowrap;">
            <i class="bi bi-plus-lg"></i> Add Category
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Categories Table -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-tags-fill" style="color:var(--primary-light);"></i>
        All Categories
        <span class="count-chip" id="rowCount">
          <?= $rowCount ?> categor<?= $rowCount !== 1 ? 'ies' : 'y' ?>
        </span>
      </div>
    </div>

    <div class="table-responsive">
      <table class="cats-table">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th>Category Name</th>
            <th>Description</th>
            <th style="text-align:center; width:120px;">PO Count</th>
            <th style="text-align:center; width:100px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="empty-row">
              <td colspan="5">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No categories yet. Add one above.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i+1 ?></td>
              <td>
                <div class="cat-name"><?= htmlspecialchars($row['category_name']) ?></div>
              </td>
              <td>
                <div class="cat-desc"><?= htmlspecialchars($row['description'] ?? '—') ?></div>
              </td>
              <td style="text-align:center;">
                <span class="po-chip">
                  <i class="bi bi-receipt-cutoff"></i>
                  <?= $row['po_count'] ?> PO<?= $row['po_count'] != 1 ? 's' : '' ?>
                </span>
              </td>
              <td>
                <div class="action-wrap">
                  <!-- Edit Button — triggers modal -->
                  <button type="button" class="btn-icon edit" title="Edit"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-id="<?= $row['category_id'] ?>"
                    data-name="<?= htmlspecialchars($row['category_name'], ENT_QUOTES) ?>"
                    data-desc="<?= htmlspecialchars($row['description'] ?? '', ENT_QUOTES) ?>">
                    <i class="bi bi-pencil-fill"></i>
                  </button>

                  <!-- Delete Button -->
                  <?php if ($row['po_count'] == 0): ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete category \'<?= htmlspecialchars($row['category_name'], ENT_QUOTES) ?>\'? This cannot be undone.')">
                      <input type="hidden" name="action"  value="delete">
                      <input type="hidden" name="cat_id"  value="<?= $row['category_id'] ?>">
                      <button type="submit" class="btn-icon del" title="Delete">
                        <i class="bi bi-trash-fill"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="btn-icon" style="cursor:default;opacity:.35;" title="Cannot delete — in use">
                      <i class="bi bi-trash-fill"></i>
                    </span>
                  <?php endif; ?>
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

<!-- ══ EDIT MODAL ══════════════════════════════════════════════ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">
          <i class="bi bi-pencil-fill me-2" style="color:var(--primary-light);font-size:.9rem;"></i>
          Edit Category
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" id="editForm">
        <input type="hidden" name="action"  value="edit">
        <input type="hidden" name="cat_id"  id="editCatId">

        <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;padding:1.5rem;">

          <div class="fg">
            <label>Category Name *</label>
            <input type="text" name="category_name" id="editCatName"
                   placeholder="e.g. Vehicles" required>
          </div>

          <div class="fg">
            <label>Description</label>
            <input type="text" name="description" id="editCatDesc"
                   placeholder="Short description (optional)">
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Cancel
          </button>
          <button type="submit" class="btn btn-add"
                  onclick="return confirm('Save changes to this category?')">
            <i class="bi bi-floppy-fill"></i> Save Changes
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const editModal = document.getElementById('editModal');

  editModal.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('editCatId').value   = btn.dataset.id;
    document.getElementById('editCatName').value = btn.dataset.name;
    document.getElementById('editCatDesc').value = btn.dataset.desc;
  });

  // Clear fields on modal hide
  editModal.addEventListener('hidden.bs.modal', function () {
    document.getElementById('editCatId').value   = '';
    document.getElementById('editCatName').value = '';
    document.getElementById('editCatDesc').value = '';
  });
});
</script>

</body>
</html>