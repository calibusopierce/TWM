<?php
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
rbac_gate($pdo_rbac, 'careers_admin');


$messages = [];

// --- DELETE ---
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    if ($delId > 0) {
        $delStmt = sqlsrv_query($conn, "DELETE FROM Careers WHERE CareerID=?", [$delId]);
        if ($delStmt === false) {
            $messages[] = ['type' => 'danger', 'text' => 'Failed to delete career listing.'];
        } else {
            $messages[] = ['type' => 'success', 'text' => 'Career listing deleted successfully.'];
        }
    }
}

// --- SAVE (INSERT / UPDATE) ---
if (isset($_POST['save'])) {
    $careerId       = isset($_POST['CareerID']) ? intval($_POST['CareerID']) : 0;
    $jobTitle       = isset($_POST['JobTitle']) ? trim($_POST['JobTitle']) : '';
    $department     = isset($_POST['Department']) ? trim($_POST['Department']) : '';
    $location       = isset($_POST['Location']) ? trim($_POST['Location']) : '';
    $qualifications = isset($_POST['Qualifications']) ? trim($_POST['Qualifications']) : '';
    $jobDescription = isset($_POST['JobDescription']) ? trim($_POST['JobDescription']) : '';
    $isActive       = isset($_POST['IsActive']) ? intval($_POST['IsActive']) : 1;

    $hasFile = !empty($_FILES['JobImage']['tmp_name']) && is_uploaded_file($_FILES['JobImage']['tmp_name']);
    $jobImageStream = null;
    if ($hasFile) {
        $jobImageStream = fopen($_FILES['JobImage']['tmp_name'], 'rb');
    }

    if ($careerId > 0) {
        if ($hasFile) {
            $sql = "UPDATE Careers SET JobTitle=?, Department=?, Location=?, Qualifications=?, JobDescription=?, JobImage=?, IsActive=? WHERE CareerID=?";
            $params = [
                $jobTitle, $department, $location, $qualifications, $jobDescription,
                [$jobImageStream, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY)],
                $isActive, $careerId
            ];
        } else {
            $sql = "UPDATE Careers SET JobTitle=?, Department=?, Location=?, Qualifications=?, JobDescription=?, IsActive=? WHERE CareerID=?";
            $params = [$jobTitle, $department, $location, $qualifications, $jobDescription, $isActive, $careerId];
        }
    } else {
        $sql = "INSERT INTO Careers (JobTitle, Department, Location, Qualifications, JobDescription, JobImage, IsActive) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $imageParam = $hasFile ? [$jobImageStream, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY)] : null;
        $params = [$jobTitle, $department, $location, $qualifications, $jobDescription, $imageParam, $isActive];
    }

    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        $messages[] = ['type' => 'danger', 'text' => 'Failed to save data. Please check your connection or query.'];
    } else {
        $messages[] = ['type' => 'success', 'text' => 'Career data saved successfully.'];
    }

    if ($hasFile && is_resource($jobImageStream)) fclose($jobImageStream);
}

// --- FETCH ALL CAREERS ---
$sql  = "SELECT CareerID, JobTitle, Department, Location, Qualifications, JobDescription, JobImage, IsActive FROM Careers ORDER BY CareerID DESC";
$stmt = sqlsrv_query($conn, $sql);

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
$rowCount = count($rows);

// ── Fetch department color map ─────────────────────────────
$deptColorMap = [];
foreach ($rows as $career) {
    $dept = trim($career['Department'] ?? '');
    if (empty($dept) || isset($deptColorMap[$dept])) continue;

    $deptStmt = sqlsrv_query($conn,
        "SELECT TOP 1 ColorCode FROM Departments WHERE DepartmentName LIKE ? AND Status = 1",
        ['%' . $dept . '%']);
    if ($deptStmt) {
        $r = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC);
        $deptColorMap[$dept] = $r['ColorCode'] ?? '#64748b';
        sqlsrv_free_stmt($deptStmt);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Careers Admin · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">

</head>
<body>

<?php $topbar_page = 'careers'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<!-- ══ MAIN ════════════════════════════════════ -->
<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">Careers Admin Panel</div>
      <div class="page-subtitle">Manage active job postings and career listings</div>
    </div>
    <button class="btn btn-add" data-bs-toggle="modal" data-bs-target="#careerModal" id="addCareerBtn">
      <i class="bi bi-plus-lg"></i> Add New Career
    </button>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="alert alert-<?= htmlspecialchars($m['type']) ?> alert-dismissible fade show" role="alert">
      <?= $m['type'] === 'success' ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-exclamation-triangle-fill me-2"></i>' ?>
      <?= htmlspecialchars($m['text']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>

  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
        All Career Listings
        <span class="count-chip" id="rowCount"><?= $rowCount ?> listing<?= $rowCount !== 1 ? 's' : '' ?></span>
      </div>
    </div>

    <div class="table-responsive">
      <table class="careers-table" id="careersTable">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th>Job Title</th>
            <th style="width:70px;">Image</th>
            <th>Location</th>
            <th style="width:110px;text-align:center;">Status</th>
            <th style="width:110px;text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $id         = $row['CareerID'];
            $jobTitle   = $row['JobTitle'] ?? '';
            $department = $row['Department'] ?? '';
            $location   = $row['Location'] ?? '';
            $isActive   = $row['IsActive'] ?? 1;
            $qualEnc    = base64_encode($row['Qualifications'] ?? '');
            $descEnc    = base64_encode($row['JobDescription'] ?? '');
            $imgBase    = !empty($row['JobImage']) ? base64_encode($row['JobImage']) : '';
        ?>
          <tr>
            <td style="color:var(--text-muted);font-size:.8rem;font-weight:600;">#<?= htmlspecialchars($id) ?></td>

            <td>
              <div class="job-title-cell"><?= htmlspecialchars($jobTitle) ?></div>
              <?php if (!empty($department)):
                  $deptColor   = $deptColorMap[$department] ?? '#64748b';
                  $hex         = ltrim($deptColor, '#');
                  if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                  $r = hexdec(substr($hex,0,2));
                  $g = hexdec(substr($hex,2,2));
                  $b = hexdec(substr($hex,4,2));
                  $bgRgba     = "rgba({$r},{$g},{$b},0.12)";
                  $borderRgba = "rgba({$r},{$g},{$b},0.45)";
                  $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
                  $textColor  = $brightness > 160
                      ? "rgba(".((int)($r*.55)).",".((int)($g*.55)).",".((int)($b*.55)).",1)"
                      : $deptColor;
              ?>
                  <div style="
                      background:<?= $bgRgba ?>;
                      color:<?= $textColor ?>;
                      border:1px solid <?= $borderRgba ?>;
                      padding:.18rem .55rem .18rem .42rem;
                      border-radius:999px;
                      font-size:.68rem;
                      font-weight:700;
                      letter-spacing:.04em;
                      text-transform:uppercase;
                      display:inline-flex;
                      align-items:center;
                      gap:.28rem;
                      white-space:nowrap;
                      margin-top:.25rem;">
                      <i class="bi bi-building" style="font-size:.6rem;"></i>
                      <?= htmlspecialchars($department) ?>
                  </div>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($imgBase): ?>
                <img src="data:image/jpeg;base64,<?= $imgBase ?>"
                     alt="Job Preview"
                     class="job-thumb img-preview-trigger"
                     data-full-src="data:image/jpeg;base64,<?= $imgBase ?>"
                     title="Click to preview">
              <?php else: ?>
                <div class="no-image-placeholder">
                  <i class="bi bi-image" style="font-size:.9rem;"></i>
                </div>
              <?php endif; ?>
            </td>

            <td>
              <?php if (!empty($location)): ?>
                <span class="location-cell">
                  <i class="bi bi-geo-alt" style="color:var(--text-muted);font-size:.8rem;"></i>
                  <?= htmlspecialchars($location) ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.78rem;font-style:italic;">—</span>
              <?php endif; ?>
            </td>

            <td style="text-align:center;">
              <?php if ($isActive == 1): ?>
                <span class="status-pill status-active"><span class="dot"></span> Active</span>
              <?php else: ?>
                <span class="status-pill status-inactive"><span class="dot"></span> Inactive</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="actions-cell" style="justify-content:center;">
                <button
                  class="btn btn-edit editBtn"
                  type="button"
                  data-bs-toggle="modal"
                  data-bs-target="#careerModal"
                  data-id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
                  data-jobtitle="<?= htmlspecialchars($jobTitle, ENT_QUOTES) ?>"
                  data-department="<?= htmlspecialchars($department, ENT_QUOTES) ?>"
                  data-location="<?= htmlspecialchars($location, ENT_QUOTES) ?>"
                  data-qualifications="<?= htmlspecialchars($qualEnc, ENT_QUOTES) ?>"
                  data-jobdescription="<?= htmlspecialchars($descEnc, ENT_QUOTES) ?>"
                  data-isactive="<?= $isActive ?>"
                  <?= $imgBase ? 'data-image="' . htmlspecialchars($imgBase, ENT_QUOTES) . '"' : '' ?>
                  title="Edit">
                  <i class="bi bi-pencil-fill"></i>
                </button>

                <a href="<?= route('careers_admin') ?>?delete=<?= htmlspecialchars($id) ?>"
                   class="btn btn-delete"
                   onclick="return confirm('Are you sure you want to delete this career?')"
                   title="Delete">
                  <i class="bi bi-trash-fill"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if ($rowCount === 0): ?>
          <tr>
            <td colspan="6">
              <div class="empty-state">
                <div class="empty-icon"><i class="bi bi-briefcase"></i></div>
                <p>No career listings yet. Click <strong>Add New Career</strong> to get started.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main-wrapper -->


<!-- ══ ADD / EDIT MODAL ════════════════════════ -->
<div class="modal fade" id="careerModal" tabindex="-1" aria-labelledby="careerModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data" id="careerModalForm">

        <div class="modal-header">
          <h5 class="modal-title" id="careerModalLabel">
            <i class="bi bi-briefcase-fill me-2" style="color:var(--primary-light);"></i> Edit Career
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="CareerID" id="modalCareerID" value="0">

          <div class="modal-2col">

            <div class="modal-col-left">

              <div style="display:grid;grid-template-columns:1fr auto;gap:.75rem;align-items:end;margin-bottom:.75rem;">
                <div>
                  <label class="form-label">Job Title <span style="color:#ef4444;">*</span></label>
                  <input type="text" name="JobTitle" id="modalJobTitle" class="form-control"
                         placeholder="e.g. Senior Software Engineer" required>
                </div>
                <div>
                  <label class="form-label">Status</label>
                  <div class="status-toggle-group">
                    <input type="radio" name="IsActive" id="optionActive" value="1" checked>
                    <label class="active-label" for="optionActive">
                      <i class="bi bi-check-circle-fill" style="font-size:.75rem;"></i> Active
                    </label>
                    <input type="radio" name="IsActive" id="optionInactive" value="0">
                    <label class="inactive-label" for="optionInactive">
                      <i class="bi bi-x-circle-fill" style="font-size:.75rem;"></i> Inactive
                    </label>
                  </div>
                </div>
              </div>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
                <div>
                  <label class="form-label">Department <span style="color:#ef4444;">*</span></label>
                  <input type="text" name="Department" id="modalDepartment" class="form-control"
                         placeholder="e.g. Engineering" required>
                </div>
                <div>
                  <label class="form-label">Location</label>
                  <input type="text" name="Location" id="modalLocation" class="form-control"
                         placeholder="e.g. Cebu City, Philippines">
                </div>
              </div>

              <div style="margin-bottom:.75rem;">
                <label class="form-label">Qualifications</label>
                <textarea name="Qualifications" id="modalQualifications" class="form-control" rows="3"
                          placeholder="List required qualifications…"></textarea>
              </div>

              <div>
                <label class="form-label">Job Description</label>
                <textarea name="JobDescription" id="modalJobDescription" class="form-control" rows="3"
                          placeholder="Describe the role and responsibilities…"></textarea>
              </div>

            </div>

            <div class="modal-col-right">
              <div class="image-panel">
                <div class="image-panel-label">
                  <i class="bi bi-image me-1"></i> Job Image
                </div>
                <div id="modalImagePreviewWrapper">
                  <img id="modalImagePreview" src="" alt="Current image">
                </div>
                <div id="noImagePlaceholder" style="padding:.5rem 0;color:var(--text-muted);font-size:.78rem;">
                  <i class="bi bi-cloud-upload" style="font-size:1.6rem;display:block;margin-bottom:.35rem;opacity:.4;"></i>
                  No image selected
                </div>
                <input type="file" name="JobImage" id="jobImageInput" accept="image/*"
                       style="font-size:.78rem;width:100%;cursor:pointer;">
                <div style="font-size:.7rem;color:var(--text-muted);">JPG, PNG, GIF supported</div>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">
            <i class="bi bi-x"></i> Cancel
          </button>
          <button type="submit" name="save" class="btn btn-success-custom">
            <i class="bi bi-check2"></i> Save Career
          </button>
        </div>

      </form>
    </div>
  </div>
</div>


<!-- ══ IMAGE PREVIEW MODAL ═════════════════════ -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:transparent;border:none;box-shadow:none;">
      <div class="modal-body p-0 text-center">
        <img id="fullPreviewImage" src="" class="img-fluid"
             style="max-height:80vh;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);">
        <div class="mt-3">
          <button type="button" class="btn btn-delete" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Close Preview
          </button>
        </div>
      </div>
    </div>
  </div>
</div>


<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

  const careerModalEl      = document.getElementById('careerModal');
  const editButtons        = document.querySelectorAll('.editBtn');
  const addBtn             = document.getElementById('addCareerBtn');
  const modalTitle         = document.getElementById('careerModalLabel');
  const idInput            = document.getElementById('modalCareerID');
  const jobTitleInput      = document.getElementById('modalJobTitle');
  const departmentInput    = document.getElementById('modalDepartment');
  const locationInput      = document.getElementById('modalLocation');
  const qualificationsInput= document.getElementById('modalQualifications');
  const jobDescInput       = document.getElementById('modalJobDescription');
  const imagePreviewWrapper= document.getElementById('modalImagePreviewWrapper');
  const imagePreview       = document.getElementById('modalImagePreview');
  const noImgPlaceholder   = document.getElementById('noImagePlaceholder');
  const fileInput          = document.getElementById('jobImageInput');

  function resetImagePanel(imgBase64) {
    if (imgBase64) {
      imagePreview.src = 'data:image/jpeg;base64,' + imgBase64;
      imagePreviewWrapper.style.display = 'block';
      noImgPlaceholder.style.display = 'none';
    } else {
      imagePreview.src = '';
      imagePreviewWrapper.style.display = 'none';
      noImgPlaceholder.style.display = 'block';
    }
    if (fileInput) fileInput.value = '';
  }

  // Edit buttons
  editButtons.forEach(function(btn) {
    btn.addEventListener('click', function () {
      modalTitle.innerHTML = '<i class="bi bi-pencil-fill me-2" style="color:var(--primary-light);font-size:.9rem;"></i>Edit: ' + (btn.dataset.jobtitle || '');
      idInput.value             = btn.dataset.id || 0;
      jobTitleInput.value       = btn.dataset.jobtitle || '';
      departmentInput.value     = btn.dataset.department || '';
      locationInput.value       = btn.dataset.location || '';
      qualificationsInput.value = btn.dataset.qualifications ? atob(btn.dataset.qualifications) : '';
      jobDescInput.value        = btn.dataset.jobdescription ? atob(btn.dataset.jobdescription) : '';
      document.getElementById('optionActive').checked   = (btn.dataset.isactive == "1");
      document.getElementById('optionInactive').checked = (btn.dataset.isactive != "1");
      resetImagePanel(btn.dataset.image || '');
    });
  });

  // Add button
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      modalTitle.innerHTML = '<i class="bi bi-plus-circle-fill me-2" style="color:#10b981;font-size:.9rem;"></i>Add New Career';
      idInput.value             = 0;
      jobTitleInput.value       = '';
      departmentInput.value     = '';
      locationInput.value       = '';
      qualificationsInput.value = '';
      jobDescInput.value        = '';
      document.getElementById('optionActive').checked   = true;
      document.getElementById('optionInactive').checked = false;
      resetImagePanel('');
    });
  }

  // Confirm before save
  document.getElementById('careerModalForm').addEventListener('submit', function (e) {
    const id = parseInt(idInput.value || '0', 10);
    const msg = id > 0 ? 'Save changes to this career posting?' : 'Add this new career posting?';
    if (!confirm(msg)) e.preventDefault();
  });

  // Image preview on file select
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      const file = this.files[0];
      if (file && file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
          imagePreview.src = e.target.result;
          imagePreviewWrapper.style.display = 'block';
          noImgPlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
      } else {
        resetImagePanel('');
      }
    });
  }

  // Image preview modal trigger
  document.querySelectorAll('.img-preview-trigger').forEach(function(img) {
    img.addEventListener('click', function () {
      document.getElementById('fullPreviewImage').src = this.getAttribute('data-full-src');
      new bootstrap.Modal(document.getElementById('imagePreviewModal')).show();
    });
  });

});
</script>

</body>
</html>