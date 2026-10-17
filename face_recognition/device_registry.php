<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'face_recognition_devices');
$view_only = rbac_is_view_only('face_recognition_devices');

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$departments = ['Monde', 'Nutriasia', 'Silver Swan', 'Century', 'Multilines'];
$branches    = ['Quezon', 'Quezon Upper', 'Marinduque'];

function deptColor($dept) {
    $colors = [
        'Monde'        => '#ef4444',
        'Century'      => '#3b82f6',
        'Nutriasia'    => '#22c55e',
        'Silver Swan'  => '#22c55e',
        'Multilines'   => '#eab308',
    ];
    return $colors[$dept] ?? '#94a3b8'; // gray for blank/unassigned
}

$errors = [];
$success = '';

// ---- Search / filter / pagination params ----
$search       = trim($_GET['search'] ?? '');
$filterDept   = trim($_GET['filter_department'] ?? '');
$filterBranch = trim($_GET['filter_branch'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_only) {
    $action = $_POST['action'] ?? '';

    if ($action === 'insert') {
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $position = trim($_POST['position'] ?? '');

        if ($name === '') $errors[] = 'Name is required.';
        if (!in_array($dept, $departments, true)) $errors[] = 'Invalid department.';
        if (!in_array($branch, $branches, true)) $errors[] = 'Invalid branch.';
        if ($position === '') $errors[] = 'Position is required.';

        if (!$errors) {
            // Compute next Device ID server-side so it can never be spoofed from the form
            $maxSql = "SELECT MAX(CAST(DeviceID AS INT)) AS MaxID FROM TBL_FaceRecognitionDevices";
            $maxResult = sqlsrv_query($conn, $maxSql);
            if ($maxResult === false) {
                $errors[] = 'DB error computing next Device ID: ' . print_r(sqlsrv_errors(), true);
            } else {
                $row = sqlsrv_fetch_array($maxResult, SQLSRV_FETCH_ASSOC);
                $nextNum = ((int)($row['MaxID'] ?? 0)) + 1;
                $newDeviceID = str_pad($nextNum, 5, '0', STR_PAD_LEFT);

                $insertSql = "INSERT INTO TBL_FaceRecognitionDevices
                              (DeviceID, EmployeeName, Department, Branch, Position)
                              VALUES (?, ?, ?, ?, ?)";
                $insertResult = sqlsrv_query($conn, $insertSql, [$newDeviceID, $name, $dept, $branch, $position]);

                if ($insertResult === false) {
                    $errors[] = 'Insert failed: ' . print_r(sqlsrv_errors(), true);
                } else {
                    $success = "Saved — Device ID $newDeviceID assigned to $name.";
                }
            }
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $branch = trim($_POST['branch'] ?? '');
        $position = trim($_POST['position'] ?? '');

        if ($id <= 0) $errors[] = 'Invalid record.';
        if ($name === '') $errors[] = 'Name is required.';
        if (!in_array($dept, $departments, true)) $errors[] = 'Invalid department.';
        if (!in_array($branch, $branches, true)) $errors[] = 'Invalid branch.';
        if ($position === '') $errors[] = 'Position is required.';

        if (!$errors) {
            $updateSql = "UPDATE TBL_FaceRecognitionDevices
                          SET EmployeeName = ?, Department = ?, Branch = ?, Position = ?
                          WHERE ID = ?";
            $updateResult = sqlsrv_query($conn, $updateSql, [$name, $dept, $branch, $position, $id]);
            if ($updateResult === false) {
                $errors[] = 'Update failed: ' . print_r(sqlsrv_errors(), true);
            } else {
                $success = "Record #$id updated.";
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $deleteResult = sqlsrv_query($conn, "DELETE FROM TBL_FaceRecognitionDevices WHERE ID = ?", [$id]);
            if ($deleteResult === false) {
                $errors[] = 'Delete failed: ' . print_r(sqlsrv_errors(), true);
            } else {
                $success = "Record #$id deleted.";
            }
        }
    }
}

// ---- Should the "Add New Device" panel render open? (e.g. insert attempt had errors) ----
$showAddForm = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insert' && $errors);

// ---- Next Device ID preview for the form (display only, recomputed again at actual insert) ----
$previewResult = sqlsrv_query($conn, "SELECT MAX(CAST(DeviceID AS INT)) AS MaxID FROM TBL_FaceRecognitionDevices");
$previewRow = $previewResult ? sqlsrv_fetch_array($previewResult, SQLSRV_FETCH_ASSOC) : ['MaxID' => 0];
$previewDeviceID = str_pad(((int)($previewRow['MaxID'] ?? 0)) + 1, 5, '0', STR_PAD_LEFT);

// ---- Build WHERE clause from search + filters ----
$whereClauses = [];
$whereParams  = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $whereClauses[] = "(EmployeeName LIKE ? OR DeviceID LIKE ? OR Position LIKE ?)";
    array_push($whereParams, $like, $like, $like);
}
if ($filterDept !== '' && in_array($filterDept, $departments, true)) {
    $whereClauses[] = "Department = ?";
    $whereParams[] = $filterDept;
}
if ($filterBranch !== '' && in_array($filterBranch, $branches, true)) {
    $whereClauses[] = "Branch = ?";
    $whereParams[] = $filterBranch;
}

$whereSql = $whereClauses ? ('WHERE ' . implode(' AND ', $whereClauses)) : '';

// ---- Count matching records (drives pagination) ----
$totalRecords = 0;
$countResult = sqlsrv_query($conn, "SELECT COUNT(*) AS Total FROM TBL_FaceRecognitionDevices $whereSql", $whereParams);
if ($countResult !== false) {
    $countRow = sqlsrv_fetch_array($countResult, SQLSRV_FETCH_ASSOC);
    $totalRecords = (int)($countRow['Total'] ?? 0);
}
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ---- Fetch just this page of matching records ----
$listSql = "SELECT * FROM TBL_FaceRecognitionDevices
            $whereSql
            ORDER BY CAST(DeviceID AS INT) DESC
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";
$listResult = sqlsrv_query($conn, $listSql, array_merge($whereParams, [$offset, $perPage]));
$records = [];
if ($listResult !== false) {
    while ($r = sqlsrv_fetch_array($listResult, SQLSRV_FETCH_ASSOC)) {
        $records[] = $r;
    }
}

// ---- Build a querystring helper that preserves current search/filter state ----
function pageQuery(int $p, array $overrides = []): string {
    global $search, $filterDept, $filterBranch;
    $qs = array_merge([
        'search'            => $search,
        'filter_department' => $filterDept,
        'filter_branch'     => $filterBranch,
        'page'              => $p,
    ], $overrides);
    return '?' . http_build_query(array_filter($qs, fn($v) => $v !== '' && $v !== null));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Device Registry - Face Recognition</title>
<link rel="stylesheet" href="<?= base_url() ?>assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= base_url() ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= base_url() ?>assets/css/admin.css">
<link rel="stylesheet" href="<?= base_url() ?>assets/css/topbar.css">
<link rel="stylesheet" href="<?= base_url() ?>assets/css/responsive-patch.css">

<style>
.dept-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
}
.dept-select-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.dept-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
</style>

</head>
<body>

<?php
$topbar_page = 'face_recognition_devices';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
?>

<div class="page-header">
  <h1 class="page-title">Face Recognition Device Registry</h1>
</div>

<div class="container-fluid mt-3">

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
  <?php endif; ?>

  <?php if (!$view_only): ?>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center<?= $showAddForm ? ' mb-3' : '' ?>">
        <h5 class="card-title mb-0">Add New Device Record</h5>
        <button type="button" id="toggleAddFormBtn" class="btn btn-primary btn-sm" onclick="toggleAddForm()">
          <i class="bi bi-plus-lg"></i> <span id="toggleAddFormLabel">Add Device</span>
        </button>
      </div>
      <div id="addRecordPanel" style="display:<?= $showAddForm ? 'block' : 'none' ?>;">
        <form method="post" class="row g-3 mt-1">
          <input type="hidden" name="action" value="insert">
          <div class="col-md-2">
            <label class="form-label">Device ID</label>
            <input type="text" class="form-control" value="<?= h($previewDeviceID) ?>" disabled>
            <small class="text-muted">Auto-assigned on save</small>
          </div>
          <div class="col-md-3">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Department</label>
            <select name="department" class="form-select" required>
              <option value="">Select</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= h($d) ?>"><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Branch</label>
            <select name="branch" class="form-select" required>
              <option value="">Select</option>
              <?php foreach ($branches as $b): ?>
                <option value="<?= h($b) ?>"><?= h($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Position</label>
            <input type="text" name="position" class="form-control" required>
          </div>
          <div class="col-md-1 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input type="text" name="search" class="form-control" placeholder="Name, Device ID, or Position"
                 value="<?= h($search) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Department</label>
          <select name="filter_department" class="form-select">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= h($d) ?>" <?= $d === $filterDept ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Branch</label>
          <select name="filter_branch" class="form-select">
            <option value="">All Branches</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= h($b) ?>" <?= $b === $filterBranch ? 'selected' : '' ?>><?= h($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button type="submit" class="btn btn-outline-primary flex-fill">
            <i class="bi bi-search"></i> Search
          </button>
          <?php if ($search !== '' || $filterDept !== '' || $filterBranch !== ''): ?>
            <a href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>" class="btn btn-outline-secondary flex-fill">Clear</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">
        Registered Devices (<?= $totalRecords ?>)
        <?php if ($search !== '' || $filterDept !== '' || $filterBranch !== ''): ?>
          <small class="text-muted fw-normal">— filtered</small>
        <?php endif; ?>
      </h5>
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
          <thead>
            <tr>
              <th>Device ID</th>
              <th>Name</th>
              <th>Department</th>
              <th>Branch</th>
              <th>Position</th>
              <th>Date Entered</th>
              <?php if (!$view_only): ?><th>Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $r): ?>
              <tr>
                <td><?= h($r['DeviceID']) ?></td>
                <td><?= h($r['EmployeeName']) ?></td>
                <td>
                  <span class="dept-badge" style="background:<?= deptColor($r['Department']) ?>">
                    <?= h($r['Department'] ?: 'Unassigned') ?>
                  </span>
                </td>
                <td><?= h($r['Branch']) ?></td>
                <td><?= h($r['Position']) ?></td>
                <td><?= h($r['DateEntered'] instanceof DateTime ? $r['DateEntered']->format('M d, Y h:i A') : $r['DateEntered']) ?></td>
                <?php if (!$view_only): ?>
                <td class="text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary btn-edit"
                          data-id="<?= (int)$r['ID'] ?>"
                          data-device="<?= h($r['DeviceID']) ?>"
                          data-name="<?= h($r['EmployeeName']) ?>"
                          data-dept="<?= h($r['Department']) ?>"
                          data-branch="<?= h($r['Branch']) ?>"
                          data-position="<?= h($r['Position']) ?>">Update</button>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalRecords > 0): ?>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?>
          </small>
          <?php if ($totalPages > 1): ?>
            <nav aria-label="Device registry pagination">
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h(pageQuery($page - 1)) ?>">Previous</a>
                </li>
                <?php
                  // Show a small window of page numbers around the current page
                  $windowStart = max(1, $page - 2);
                  $windowEnd   = min($totalPages, $page + 2);
                  if ($windowStart > 1) {
                      echo '<li class="page-item"><a class="page-link" href="' . h(pageQuery(1)) . '">1</a></li>';
                      if ($windowStart > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  }
                  for ($p = $windowStart; $p <= $windowEnd; $p++):
                ?>
                  <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= h(pageQuery($p)) ?>"><?= $p ?></a>
                  </li>
                <?php
                  endfor;
                  if ($windowEnd < $totalPages) {
                      if ($windowEnd < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                      echo '<li class="page-item"><a class="page-link" href="' . h(pageQuery($totalPages)) . '">' . $totalPages . '</a></li>';
                  }
                ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= h(pageQuery($page + 1)) ?>">Next</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php if (!$view_only): ?>
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="editId">
      <div class="modal-header">
        <h5 class="modal-title">Update Device Record</h5>
        <button type="button" class="btn-close" data-close-modal aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Device ID</label>
          <input type="text" id="editDeviceId" class="form-control" disabled>
        </div>
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" name="name" id="editName" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Department</label>
          <select name="department" id="editDept" class="form-select" required>
            <option value="">Select</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= h($d) ?>"><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Branch</label>
          <select name="branch" id="editBranch" class="form-select" required>
            <option value="">Select</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= h($b) ?>"><?= h($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-0">
          <label class="form-label">Position</label>
          <input type="text" name="position" id="editPosition" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
// Confirm before delete — delete buttons submit a separate tiny form appended via JS to keep the update form clean
document.querySelectorAll('.btn-delete').forEach(btn => {
  btn.addEventListener('click', function(e) {
    if (!confirm('Delete this device record? This cannot be undone.')) e.preventDefault();
  });
});

// Toggle the "Add New Device Record" panel
function toggleAddForm() {
  var panel = document.getElementById('addRecordPanel');
  var label = document.getElementById('toggleAddFormLabel');
  var open  = panel.style.display !== 'none';
  panel.style.display = open ? 'none' : 'block';
  label.textContent   = open ? 'Add Device' : 'Close';
}

// Edit modal (plain JS, no Bootstrap JS dependency)
var editModal    = document.getElementById('editModal');
var editBackdrop = null;

function openEditModal(btn) {
  document.getElementById('editId').value       = btn.dataset.id;
  document.getElementById('editDeviceId').value = btn.dataset.device;
  document.getElementById('editName').value     = btn.dataset.name;
  document.getElementById('editDept').value     = btn.dataset.dept;
  document.getElementById('editBranch').value   = btn.dataset.branch;
  document.getElementById('editPosition').value = btn.dataset.position;

  editModal.style.display = 'block';
  editModal.classList.add('show');
  document.body.classList.add('modal-open');
  editBackdrop = document.createElement('div');
  editBackdrop.className = 'modal-backdrop fade show';
  document.body.appendChild(editBackdrop);
  document.getElementById('editName').focus();
}

function closeEditModal() {
  editModal.classList.remove('show');
  editModal.style.display = 'none';
  document.body.classList.remove('modal-open');
  if (editBackdrop) { editBackdrop.remove(); editBackdrop = null; }
}

document.querySelectorAll('.btn-edit').forEach(function (btn) {
  btn.addEventListener('click', function () { openEditModal(btn); });
});
document.querySelectorAll('[data-close-modal]').forEach(function (el) {
  el.addEventListener('click', closeEditModal);
});
editModal.addEventListener('mousedown', function (e) {
  if (e.target === editModal) closeEditModal();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && editModal.classList.contains('show')) closeEditModal();
});
</script>
<?php endif; ?>

</body>
</html>