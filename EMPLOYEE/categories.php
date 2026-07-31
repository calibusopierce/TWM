<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$isViewOnly = rbac_is_view_only('employee_loans');

$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isViewOnly) {
        $messages[] = ['type' => 'danger', 'text' => 'You have view-only access — changes are not allowed.'];
        goto render_page;
    }

    // ── ADD ──────────────────────────────────────────────────
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['type_name']);
        $desc = trim($_POST['description']);
        $code = strtoupper(trim($_POST['code']));
        if ($name === '') {
            $messages[] = ['type' => 'danger', 'text' => 'Loan type name is required.'];
        } else {
            $chk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM TBL_Loan_Type WHERE TypeName = ?", [$name]);
            $chkRow = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            if ($chkRow['cnt'] > 0) {
                $messages[] = ['type' => 'danger', 'text' => "Loan type \"$name\" already exists."];
            } else {
                sqlsrv_query($conn,
                    "INSERT INTO TBL_Loan_Type (TypeName, Description, Code, Active) VALUES (?, ?, ?, 1)",
                    [$name, $desc, $code]);
                $messages[] = ['type' => 'success', 'text' => "Loan type \"$name\" added successfully."];
            }
        }
    }

    // ── EDIT ─────────────────────────────────────────────────
    if ($_POST['action'] === 'edit') {
        $tid  = (int)$_POST['type_id'];
        $name = trim($_POST['type_name']);
        $desc = trim($_POST['description']);
        $code = strtoupper(trim($_POST['code']));
        if ($name === '' || $tid === 0) {
            $messages[] = ['type' => 'danger', 'text' => 'Loan type name is required.'];
        } else {
            $chk = sqlsrv_query($conn,
                "SELECT COUNT(*) AS cnt FROM TBL_Loan_Type WHERE TypeName = ? AND ID != ?",
                [$name, $tid]);
            $chkRow = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
            if ($chkRow['cnt'] > 0) {
                $messages[] = ['type' => 'danger', 'text' => "Another loan type named \"$name\" already exists."];
            } else {
                sqlsrv_query($conn,
                    "UPDATE TBL_Loan_Type SET TypeName = ?, Description = ?, Code = ? WHERE ID = ?",
                    [$name, $desc, $code, $tid]);
                $messages[] = ['type' => 'success', 'text' => "Loan type updated to \"$name\" successfully."];
            }
        }
    }

    // ── TOGGLE ACTIVE ─────────────────────────────────────────
    if ($_POST['action'] === 'toggle') {
        $tid    = (int)$_POST['type_id'];
        $active = (int)$_POST['active']; // current value — we flip it
        sqlsrv_query($conn,
            "UPDATE TBL_Loan_Type SET Active = ? WHERE ID = ?",
            [$active ? 0 : 1, $tid]);
        $messages[] = ['type' => 'success', 'text' => 'Loan type status updated.'];
    }

    // ── DELETE ────────────────────────────────────────────────
    if ($_POST['action'] === 'delete') {
        $tid = (int)$_POST['type_id'];
        $chk = sqlsrv_query($conn,
            "SELECT COUNT(*) AS cnt FROM TBL_Loan WHERE LoanType = ?", [$tid]);
        $row = sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC);
        if ($row['cnt'] > 0) {
            $messages[] = ['type' => 'danger',
                'text' => "Cannot delete — this loan type has {$row['cnt']} loan(s) linked to it."];
        } else {
            sqlsrv_query($conn, "DELETE FROM TBL_Loan_Type WHERE ID = ?", [$tid]);
            $messages[] = ['type' => 'success', 'text' => 'Loan type deleted successfully.'];
        }
    }
}

render_page:
// Fetch all loan types with loan count
$types = sqlsrv_query($conn, "
    SELECT t.ID, t.TypeName, t.Description, t.Code, t.Active,
           COUNT(l.LoanID) AS loan_count
    FROM TBL_Loan_Type t
    LEFT JOIN TBL_Loan l ON l.LoanType = t.ID
    GROUP BY t.ID, t.TypeName, t.Description, t.Code, t.Active
    ORDER BY t.TypeName
");
$rows     = [];
while ($r = sqlsrv_fetch_array($types, SQLSRV_FETCH_ASSOC)) $rows[] = $r;
$rowCount = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Loan Types · Tradewell</title>
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
    .code-chip { display:inline-flex; align-items:center;
                 background:rgba(99,102,241,.1); color:#4f46e5;
                 padding:.18rem .65rem; border-radius:999px;
                 font-size:.74rem; font-weight:700; font-family:monospace; letter-spacing:.04em; }
    .loan-chip { display:inline-flex; align-items:center; gap:.3rem;
                 background:rgba(59,130,246,.1); color:#2563eb;
                 padding:.18rem .65rem; border-radius:999px;
                 font-size:.76rem; font-weight:700; }

    /* Action buttons */
    .action-wrap { display:flex; gap:.35rem; align-items:center; justify-content:center; }
    .btn-icon {
      width:30px; height:30px; border-radius:7px; border:1px solid var(--border);
      display:inline-flex; align-items:center; justify-content:center;
      font-size:.82rem; cursor:pointer; background:var(--surface); color:var(--text-muted);
      transition:background .15s, border-color .15s; }
    .btn-icon.edit   { color:#f59e0b; border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.07); }
    .btn-icon.edit:hover   { background:rgba(245,158,11,.18); }
    .btn-icon.del    { color:#ef4444; border-color:rgba(239,68,68,.3);  background:rgba(239,68,68,.07); }
    .btn-icon.del:hover    { background:rgba(239,68,68,.18); }
    .btn-icon.toggle { color:#10b981; border-color:rgba(16,185,129,.3); background:rgba(16,185,129,.07); }
    .btn-icon.toggle:hover { background:rgba(16,185,129,.18); }
    .btn-icon.toggle.off   { color:#94a3b8; border-color:rgba(148,163,184,.3); background:rgba(148,163,184,.07); }

    .badge-active   { background:rgba(16,185,129,.12); color:#065f46;
                      padding:.18rem .6rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .badge-inactive { background:rgba(148,163,184,.12); color:#64748b;
                      padding:.18rem .6rem; border-radius:999px; font-size:.72rem; font-weight:700; }
    .empty-row td { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
  </style>
</head>
<body>
<?php $topbar_page = 'employee_loans'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">Loan Types</div>
      <div class="page-subtitle">Manage loan type classifications for employee loans</div>
    </div>
    <a href="<?= base_url('EMPLOYEE/index.php') ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to Loans
    </a>
  </div>

  <!-- Alerts -->
  <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= $m['type'] ?> alert-dismissible fade show">
      <?= $m['type'] === 'success'
          ? '<i class="bi bi-check-circle-fill me-2"></i>'
          : '<i class="bi bi-exclamation-triangle-fill me-2"></i>' ?>
      <?= htmlspecialchars($m['text']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>

  <!-- Add Loan Type Card -->
  <?php if (!$isViewOnly): ?>
  <div class="add-card">
    <div class="add-card-header">
      <i class="bi bi-plus-circle-fill"></i> Add New Loan Type
    </div>
    <div class="add-card-body">
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="add-form-row">
          <div class="fg" style="max-width:100px;">
            <label>Code</label>
            <input type="text" name="code" placeholder="e.g. SSS" maxlength="20" style="text-transform:uppercase;">
          </div>
          <div class="fg">
            <label>Loan Type Name *</label>
            <input type="text" name="type_name" placeholder="e.g. SSS Loan" required>
          </div>
          <div class="fg" style="flex:2;">
            <label>Description</label>
            <input type="text" name="description" placeholder="Short description (optional)">
          </div>
          <button type="submit" class="btn btn-add" style="padding:.48rem 1.1rem; white-space:nowrap;">
            <i class="bi bi-plus-lg"></i> Add Type
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Loan Types Table -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-tags-fill" style="color:var(--primary-light);"></i>
        All Loan Types
        <span class="count-chip" id="rowCount">
          <?= $rowCount ?> type<?= $rowCount !== 1 ? 's' : '' ?>
        </span>
      </div>
    </div>

    <div class="table-responsive">
      <table class="cats-table">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th style="width:90px;">Code</th>
            <th>Loan Type Name</th>
            <th>Description</th>
            <th style="text-align:center; width:110px;">Loans</th>
            <th style="text-align:center; width:90px;">Status</th>
            <th style="text-align:center; width:110px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr class="empty-row">
              <td colspan="7">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No loan types yet. Add one above.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $i => $row): ?>
            <tr>
              <td style="color:var(--text-muted);font-size:.78rem;font-weight:600;"><?= $i + 1 ?></td>
              <td>
                <?php if (!empty($row['Code'])): ?>
                  <span class="code-chip"><?= htmlspecialchars($row['Code']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-size:.78rem;">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="cat-name"><?= htmlspecialchars($row['TypeName']) ?></div>
              </td>
              <td>
                <div class="cat-desc"><?= htmlspecialchars($row['Description'] ?? '—') ?></div>
              </td>
              <td style="text-align:center;">
                <span class="loan-chip">
                  <i class="bi bi-person-lines-fill"></i>
                  <?= $row['loan_count'] ?> loan<?= $row['loan_count'] != 1 ? 's' : '' ?>
                </span>
              </td>
              <td style="text-align:center;">
                <span class="<?= $row['Active'] ? 'badge-active' : 'badge-inactive' ?>">
                  <?= $row['Active'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td>
                <div class="action-wrap">
                  <?php if (!$isViewOnly): ?>
                  <!-- Edit -->
                  <button type="button" class="btn-icon edit" title="Edit"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-id="<?= $row['ID'] ?>"
                    data-name="<?= htmlspecialchars($row['TypeName'], ENT_QUOTES) ?>"
                    data-desc="<?= htmlspecialchars($row['Description'] ?? '', ENT_QUOTES) ?>"
                    data-code="<?= htmlspecialchars($row['Code'] ?? '', ENT_QUOTES) ?>">
                    <i class="bi bi-pencil-fill"></i>
                  </button>

                  <!-- Toggle Active -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action"  value="toggle">
                    <input type="hidden" name="type_id" value="<?= $row['ID'] ?>">
                    <input type="hidden" name="active"  value="<?= $row['Active'] ?>">
                    <button type="submit"
                      class="btn-icon toggle <?= $row['Active'] ? '' : 'off' ?>"
                      title="<?= $row['Active'] ? 'Deactivate' : 'Activate' ?>"
                      onclick="return confirm('<?= $row['Active'] ? 'Deactivate' : 'Activate' ?> this loan type?')">
                      <i class="bi bi-<?= $row['Active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                    </button>
                  </form>

                  <!-- Delete (only if no loans linked) -->
                  <?php if ($row['loan_count'] == 0): ?>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Delete loan type \'<?= htmlspecialchars($row['TypeName'], ENT_QUOTES) ?>\'? This cannot be undone.')">
                      <input type="hidden" name="action"  value="delete">
                      <input type="hidden" name="type_id" value="<?= $row['ID'] ?>">
                      <button type="submit" class="btn-icon del" title="Delete">
                        <i class="bi bi-trash-fill"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="btn-icon" style="cursor:default;opacity:.35;" title="Cannot delete — in use">
                      <i class="bi bi-trash-fill"></i>
                    </span>
                  <?php endif; ?>
                  <?php endif; // !$isViewOnly ?>
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
          Edit Loan Type
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" id="editForm">
        <input type="hidden" name="action"  value="edit">
        <input type="hidden" name="type_id" id="editTypeId">

        <div class="modal-body" style="display:flex;flex-direction:column;gap:1rem;padding:1.5rem;">
          <div class="fg" style="max-width:120px;">
            <label>Code</label>
            <input type="text" name="code" id="editCode"
                   placeholder="e.g. SSS" maxlength="20" style="text-transform:uppercase;">
          </div>
          <div class="fg">
            <label>Loan Type Name *</label>
            <input type="text" name="type_name" id="editTypeName"
                   placeholder="e.g. SSS Loan" required>
          </div>
          <div class="fg">
            <label>Description</label>
            <input type="text" name="description" id="editTypeDesc"
                   placeholder="Short description (optional)">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Cancel
          </button>
          <button type="submit" class="btn btn-add"
                  onclick="return confirm('Save changes to this loan type?')">
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
    document.getElementById('editTypeId').value   = btn.dataset.id;
    document.getElementById('editTypeName').value = btn.dataset.name;
    document.getElementById('editTypeDesc').value = btn.dataset.desc;
    document.getElementById('editCode').value     = btn.dataset.code;
  });

  editModal.addEventListener('hidden.bs.modal', function () {
    document.getElementById('editTypeId').value   = '';
    document.getElementById('editTypeName').value = '';
    document.getElementById('editTypeDesc').value = '';
    document.getElementById('editCode').value     = '';
  });
});
</script>

</body>
</html>
