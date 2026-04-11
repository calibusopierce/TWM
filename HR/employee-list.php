<?php
// ══════════════════════════════════════════════════════════════
//  HR/employee-list.php
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR']);

// ── Session context ────────────────────────────────────────────
$_userType = $_SESSION['UserType'] ?? '';
$isAdmin   = in_array($_userType, ['Admin', 'Administrator']);

// ── Pagination & filters ───────────────────────────────────────
$perPage    = 20;
$page       = max(1, (int)($_GET['page']    ?? 1));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search']           ?? '');
$deptFilter = trim($_GET['dept']             ?? '');
$showAll    = isset($_GET['show_all']) && $_GET['show_all'] === '1';

// ── Session department ─────────────────────────────────────────
$_sessionDept = trim($_SESSION['Department'] ?? '');
$viewAll      = ($isAdmin && $_sessionDept === '');
$activeDept   = $_sessionDept;

// ── Build WHERE ────────────────────────────────────────────────
function buildWhere(bool $showInactive, bool $viewAll, string $userDept, string $search, string $deptFilter, array &$params): string
{
    $params = [];

    // Active filter
    $where = $showInactive ? "WHERE 1=1" : "WHERE Active = 1";

    // Department filter — skip if viewAll (Admin sees all depts)
    if (!$viewAll && $userDept !== '') {
        $where   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $params[] = '%' . $userDept . '%';
    }

    // Dept dropdown filter (only when viewAll)
    if ($viewAll && $deptFilter !== '') {
        $where   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $params[] = '%' . $deptFilter . '%';
    }

    // Search
    if ($search !== '') {
        $sp = "%{$search}%";
        $where .= " AND (
            LastName      LIKE ? OR FirstName     LIKE ? OR
            EmployeeID    LIKE ? OR Department    LIKE ? OR
            Position_held LIKE ? OR Branch        LIKE ?
        )";
        array_push($params, $sp, $sp, $sp, $sp, $sp, $sp);
    }

    return $where;
}

$params = [];
$where = buildWhere($showAll, $viewAll, $activeDept, $search, $deptFilter, $params);

// ── Total count ────────────────────────────────────────────────
$countSql  = "SELECT COUNT(*) AS total FROM [dbo].[TBL_HREmployeeList] {$where}";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;
if ($countStmt) {
    $cr = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)($cr['total'] ?? 0);
    sqlsrv_free_stmt($countStmt);
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Fetch employees ────────────────────────────────────────────
$sql = "
    SELECT
        FileNo, EmployeeID, EmployeeID1, OfficeID,
        Department, Position_held, Job_tittle, Category,
        Hired_date, Date_Of_Seperation, Employee_Status,
        LastName, FirstName, MiddleName,
        Permanent_Address, Present_Address,
        SSS_Number, TIN_Number, Philhealth_Number, HDMF,
        Phone_Number, Mobile_Number, Email_Address,
        Birth_date, Birth_Place, Civil_Status, Gender,
        Nationality, Religion, Relationship,
        Contact_Person, Contact_Number_Emergency,
        Notes, Educational_Background,
        Picture, IDPicture, Signature,
        Active, Blacklisted, System, Branch, SortNo, CutOff
    FROM [dbo].[TBL_HREmployeeList]
    {$where}
    ORDER BY LastName, FirstName
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$fetchParams = array_merge($params, [$offset, $perPage]);
$stmt        = sqlsrv_query($conn, $sql, $fetchParams);
$employees   = [];
if ($stmt) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = $r;
    }
    sqlsrv_free_stmt($stmt);
}

// ── Departments for filter dropdown — pull from clean Departments table ───
$deptStmt = sqlsrv_query($conn,
    "SELECT DepartmentName FROM [dbo].[Departments]
     WHERE Status = 1
     ORDER BY DepartmentName");
$departments = [];
if ($deptStmt) {
    while ($dr = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $dr['DepartmentName'];
    }
    sqlsrv_free_stmt($deptStmt);
}

// ── Helpers ────────────────────────────────────────────────────
function fmtDate($d): string
{
    if ($d instanceof DateTime) return $d->format('M j, Y');
    if (is_string($d) && $d)   return date('M j, Y', strtotime($d));
    return '—';
}

function initials(string $first, string $last): string
{
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}

function avatarColor(string $name): string
{
    $colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
    return $colors[abs(crc32($name)) % count($colors)];
}

$paginationParams = ['page' => 1, 'search' => $search];
if ($showAll) $paginationParams['show_all'] = '1';
if ($viewAll && $deptFilter !== '') $paginationParams['dept'] = $deptFilter;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee List · HR</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Employee table ── */
    .emp-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .78rem; font-weight: 800; color: #fff;
      flex-shrink: 0; object-fit: cover;
      border: 2px solid rgba(255,255,255,.6);
      box-shadow: 0 2px 6px rgba(0,0,0,.15);
    }
    .emp-name-wrap {
      display: flex; align-items: center; gap: .65rem;
    }
    .emp-name {
      font-weight: 700; font-size: .88rem;
      color: var(--text-primary); line-height: 1.2;
    }
    .emp-sub {
      font-size: .72rem; color: var(--text-muted); margin-top: .1rem;
    }
    .status-active {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .18rem .55rem; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      background: rgba(16,185,129,.12); color: #059669;
      border: 1px solid rgba(16,185,129,.35);
    }
    .status-inactive {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .18rem .55rem; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      background: rgba(239,68,68,.1); color: #dc2626;
      border: 1px solid rgba(239,68,68,.3);
    }
    .status-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
    .blacklisted-badge {
      display: inline-flex; align-items: center; gap: .25rem;
      padding: .15rem .45rem; border-radius: 999px;
      font-size: .65rem; font-weight: 700;
      background: rgba(239,68,68,.1); color: #dc2626;
      border: 1px solid rgba(239,68,68,.3);
      margin-left: .3rem;
    }
    .show-all-wrap {
      display: flex; align-items: center; gap: .5rem;
      font-size: .85rem; font-weight: 600; color: var(--text-muted);
      cursor: pointer; user-select: none;
    }
    .show-all-wrap input { cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary); }
    .emp-row { cursor: pointer; transition: background .12s; }
    .emp-row:hover td { background: rgba(67,128,226,.04); }

    /* ── Detail Modal ── */
    .detail-modal .modal-content {
      border-radius: 16px; border: none;
      box-shadow: 0 24px 80px rgba(0,0,0,.2);
    }
    .detail-modal .modal-header {
      background: linear-gradient(135deg, #1e40af, #4380e2);
      border-radius: 16px 16px 0 0; padding: 1.25rem 1.5rem;
    }
    .detail-modal .modal-title { color: #fff; font-weight: 700; }
    .detail-modal .btn-close { filter: brightness(0) invert(1); }
    .modal-avatar-wrap {
      display: flex; align-items: center; gap: 1rem;
      padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9;
    }
    .modal-avatar {
      width: 64px; height: 64px; border-radius: 50%;
      object-fit: cover; border: 3px solid #e2e8f0;
      flex-shrink: 0;
    }
    .modal-avatar-initials {
      width: 64px; height: 64px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; font-weight: 800; color: #fff;
      flex-shrink: 0;
    }
    .modal-emp-name { font-size: 1.1rem; font-weight: 800; color: #0f172a; }
    .modal-emp-role { font-size: .82rem; color: #64748b; margin-top: .15rem; }
    .detail-section {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid #f1f5f9;
    }
    .detail-section:last-child { border-bottom: none; }
    .detail-section-title {
      font-size: .68rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .08em; color: #94a3b8; margin-bottom: .75rem;
      display: flex; align-items: center; gap: .4rem;
    }
    .detail-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem;
    }
    .detail-grid-3 {
      display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .5rem .75rem;
    }
    .detail-item label {
      font-size: .68rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .06em;
      display: block; margin-bottom: .15rem;
    }
    .detail-item span {
      font-size: .83rem; font-weight: 500; color: #1e293b;
      display: block; word-break: break-word;
    }
    .detail-item span.empty { color: #cbd5e1; font-style: italic; }
    @media (max-width: 576px) {
      .detail-grid, .detail-grid-3 { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<?php $topbar_page = 'employees';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page header -->
  <div class="page-header" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;">
    <div>
      <div class="page-title">Employee List</div>
      <div class="page-subtitle">
        <?= $showAll ? 'Showing all employees (active &amp; inactive)' : 'Showing active employees only' ?>
        &nbsp;—&nbsp; <strong><?= $totalRows ?></strong> record<?= $totalRows !== 1 ? 's' : '' ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:1rem;margin-top:.35rem;">
      <!-- Show all toggle -->
      <label class="show-all-wrap" title="Include inactive employees">
        <input type="checkbox" id="showAllToggle" <?= $showAll ? 'checked' : '' ?>>
        Show inactive
      </label>
    </div>
  </div>

  <!-- Filter bar -->
  <div class="filter-card">
    <form method="get" id="filterForm">
      <?php if ($showAll): ?>
        <input type="hidden" name="show_all" value="1">
      <?php endif; ?>
      <div class="filter-row">
        <!-- Search -->
        <div style="position:relative;">
          <i class="bi bi-search" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none;"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 class="form-control" placeholder="Search name, ID, position…"
                 style="padding-left:2rem;max-width:240px;">
        </div>

        <!-- Department filter -->
        <?php if ($viewAll): ?>
        <select name="dept" class="form-select" style="max-width:185px;">
          <option value="">All Departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>>
              <?= htmlspecialchars($d) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="filter-divider"></div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel-fill"></i> Apply</button>
        <a href="?<?= $showAll ? 'show_all=1' : '' ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="table-card">
    <div class="table-responsive">
      <table class="apps-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>ID / File No</th>
            <th>Department &amp; Position</th>
            <th>Branch / Category</th>
            <th>Contact</th>
            <th>Hired Date</th>
            <th style="text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($employees)): foreach ($employees as $emp):
            $fullName  = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));
            $initials  = initials($emp['FirstName'] ?? '', $emp['LastName'] ?? '');
            $bgColor   = avatarColor($fullName);
            $isActive  = (int)($emp['Active'] ?? 0) === 1;
            $isBlack   = (int)($emp['Blacklisted'] ?? 0) === 1;
            $picPath = trim($emp['Picture'] ?? '');
            if ($picPath && !str_starts_with($picPath, '/')) {
            $picPath = '/TWM/tradewellportal/' . $picPath;
          }   

$hasPic = !empty($picPath) && file_exists($_SERVER['DOCUMENT_ROOT'] . $picPath);
        ?>
          <tr class="emp-row" data-emp="<?= htmlspecialchars(json_encode($emp, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
              data-bs-toggle="modal" data-bs-target="#empDetailModal">
            <td>
              <div class="emp-name-wrap">
                <?php if ($hasPic): ?>
                  <img src="<?= htmlspecialchars($picPath) ?>" class="emp-avatar" alt="<?= htmlspecialchars($fullName) ?>">
                <?php else: ?>
                  <div class="emp-avatar" style="background:<?= $bgColor ?>;"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div>
                  <div class="emp-name"><?= htmlspecialchars($emp['LastName'] ?? '') ?>, <?= htmlspecialchars($emp['FirstName'] ?? '') ?>
                    <?php if ($isBlack): ?>
                      <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>
                    <?php endif; ?>
                  </div>
                  <div class="emp-sub"><?= htmlspecialchars($emp['MiddleName'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['EmployeeID'] ?? '—') ?></div>
              <div class="emp-sub">File: <?= htmlspecialchars($emp['FileNo'] ?? '—') ?></div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['Department'] ?? '—') ?></div>
              <div class="emp-sub"><?= htmlspecialchars($emp['Position_held'] ?? '—') ?></div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['Branch'] ?? '—') ?></div>
              <div class="emp-sub"><?= htmlspecialchars($emp['Category'] ?? '—') ?></div>
            </td>
            <td>
              <?php if (!empty($emp['Email_Address'])): ?>
                <div><a href="mailto:<?= htmlspecialchars($emp['Email_Address']) ?>" class="text-link" style="font-size:.78rem;">
                  <i class="bi bi-envelope" style="font-size:.7rem;"></i> <?= htmlspecialchars($emp['Email_Address']) ?>
                </a></div>
              <?php endif; ?>
              <?php $phone = $emp['Mobile_Number'] ?: ($emp['Phone_Number'] ?? ''); ?>
              <?php if ($phone): ?>
                <div class="emp-sub"><i class="bi bi-telephone" style="font-size:.65rem;"></i> <?= htmlspecialchars($phone) ?></div>
              <?php endif; ?>
            </td>
            <td class="date-cell">
              <div class="date-day"><?= fmtDate($emp['Hired_date']) ?></div>
              <?php if (!$isActive && $emp['Date_Of_Seperation']): ?>
                <div class="date-time" style="color:#ef4444;">Sep: <?= fmtDate($emp['Date_Of_Seperation']) ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:center;">
              <?php if ($isActive): ?>
                <span class="status-active"><span class="status-dot" style="background:#10b981;"></span> Active</span>
              <?php else: ?>
                <span class="status-inactive"><span class="status-dot" style="background:#ef4444;"></span> Inactive</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">
            <div class="empty-state">
              <i class="bi bi-people"></i>
              <p>No employees found.</p>
            </div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
      $urlBase = fn($p) => '?' . http_build_query(array_merge($paginationParams, ['page' => $p]));
    ?>
    <nav class="pagination-wrap d-flex justify-content-between align-items-center" aria-label="Pagination">
      <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? $urlBase($page - 1) : '#' ?>">
        <i class="bi bi-chevron-left"></i> Prev
      </a>
      <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
      <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page < $totalPages ? $urlBase($page + 1) : '#' ?>">
        Next <i class="bi bi-chevron-right"></i>
      </a>
    </nav>
    <?php endif; ?>
  </div>

</div><!-- /.main-wrapper -->


<!-- ══ EMPLOYEE DETAIL MODAL ══════════════════════════════════ -->
<div class="modal fade detail-modal" id="empDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Employee Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Avatar + name strip -->
      <div class="modal-avatar-wrap" id="modalAvatarWrap">
        <div id="modalAvatarEl"></div>
        <div>
          <div class="modal-emp-name" id="modalEmpName">—</div>
          <div class="modal-emp-role" id="modalEmpRole">—</div>
          <div style="margin-top:.4rem;" id="modalEmpBadges"></div>
        </div>
      </div>

      <div class="modal-body" style="padding:0;">

        <!-- IDs -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-fingerprint"></i> Identification</div>
          <div class="detail-grid-3">
            <div class="detail-item"><label>Employee ID</label><span id="d-EmployeeID">—</span></div>
            <div class="detail-item"><label>File No</label><span id="d-FileNo">—</span></div>
            <div class="detail-item"><label>Office ID</label><span id="d-OfficeID">—</span></div>
            <div class="detail-item"><label>SSS Number</label><span id="d-SSS_Number">—</span></div>
            <div class="detail-item"><label>TIN Number</label><span id="d-TIN_Number">—</span></div>
            <div class="detail-item"><label>PhilHealth</label><span id="d-Philhealth_Number">—</span></div>
            <div class="detail-item"><label>HDMF / Pag-IBIG</label><span id="d-HDMF">—</span></div>
          </div>
        </div>

        <!-- Work info -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-briefcase"></i> Work Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Department</label><span id="d-Department">—</span></div>
            <div class="detail-item"><label>Position</label><span id="d-Position_held">—</span></div>
            <div class="detail-item"><label>Job Title</label><span id="d-Job_tittle">—</span></div>
            <div class="detail-item"><label>Category</label><span id="d-Category">—</span></div>
            <div class="detail-item"><label>Branch</label><span id="d-Branch">—</span></div>
            <div class="detail-item"><label>System</label><span id="d-System">—</span></div>
            <div class="detail-item"><label>Hired Date</label><span id="d-Hired_date">—</span></div>
            <div class="detail-item"><label>Separation Date</label><span id="d-Date_Of_Seperation">—</span></div>
            <div class="detail-item"><label>Employee Status</label><span id="d-Employee_Status">—</span></div>
            <div class="detail-item"><label>Cut-Off</label><span id="d-CutOff">—</span></div>
          </div>
        </div>

        <!-- Personal info -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person"></i> Personal Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Birth Date</label><span id="d-Birth_date">—</span></div>
            <div class="detail-item"><label>Birth Place</label><span id="d-Birth_Place">—</span></div>
            <div class="detail-item"><label>Gender</label><span id="d-Gender">—</span></div>
            <div class="detail-item"><label>Civil Status</label><span id="d-Civil_Status">—</span></div>
            <div class="detail-item"><label>Nationality</label><span id="d-Nationality">—</span></div>
            <div class="detail-item"><label>Religion</label><span id="d-Religion">—</span></div>
          </div>
        </div>

        <!-- Contact -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Mobile</label><span id="d-Mobile_Number">—</span></div>
            <div class="detail-item"><label>Phone</label><span id="d-Phone_Number">—</span></div>
            <div class="detail-item"><label>Email</label><span id="d-Email_Address">—</span></div>
            <div class="detail-item"><label>Present Address</label><span id="d-Present_Address">—</span></div>
            <div class="detail-item"><label>Permanent Address</label><span id="d-Permanent_Address">—</span></div>
          </div>
        </div>

        <!-- Emergency contact -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-heart-pulse"></i> Emergency Contact</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Contact Person</label><span id="d-Contact_Person">—</span></div>
            <div class="detail-item"><label>Relationship</label><span id="d-Relationship">—</span></div>
            <div class="detail-item"><label>Contact Number</label><span id="d-Contact_Number_Emergency">—</span></div>
          </div>
        </div>

        <!-- Education & Notes -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-book"></i> Education &amp; Notes</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Educational Background</label><span id="d-Educational_Background">—</span></div>
            <div class="detail-item"><label>Notes</label><span id="d-Notes">—</span></div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>


<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

  // ── Show all toggle ────────────────────────────────────────
  document.getElementById('showAllToggle').addEventListener('change', function () {
    const form   = document.getElementById('filterForm');
    const hidden = form.querySelector('input[name="show_all"]');
    if (this.checked) {
      if (!hidden) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'show_all'; inp.value = '1';
        form.appendChild(inp);
      }
    } else {
      if (hidden) hidden.remove();
    }
    form.submit();
  });

  // ── Avatar colors (must match PHP) ────────────────────────
  const avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
  function avatarColor(name) {
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = (hash * 31 + name.charCodeAt(i)) | 0;
    return avatarColors[Math.abs(hash) % avatarColors.length];
  }

  function val(v) {
    if (v === null || v === undefined || v === '') return '<span class="empty">—</span>';
    // Handle date-like objects from PHP JSON (they come as strings)
    return String(v).trim() || '<span class="empty">—</span>';
  }

  function fmtDate(str) {
    if (!str) return '<span class="empty">—</span>';
    const d = new Date(str);
    if (isNaN(d)) return val(str);
    return d.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
  }

  // ── Employee detail modal ──────────────────────────────────
  document.getElementById('empDetailModal').addEventListener('show.bs.modal', e => {
    const row = e.relatedTarget;
    if (!row) return;

    let emp = {};
    try { emp = JSON.parse(row.dataset.emp || '{}'); } catch {}

    const firstName = emp.FirstName || '';
    const lastName  = emp.LastName  || '';
    const fullName  = `${firstName} ${lastName}`.trim();
    const initials  = (firstName[0] || '') + (lastName[0] || '');
    const color     = avatarColor(fullName);
    const isActive  = parseInt(emp.Active  || 0) === 1;
    const isBlack   = parseInt(emp.Blacklisted || 0) === 1;

    // Avatar
    const avatarEl = document.getElementById('modalAvatarEl');
    let picSrc = (emp.Picture || '').trim();
    if (picSrc && !picSrc.startsWith('/')) {
        picSrc = '/TWM/tradewellportal/' + picSrc;
    }
    if (picSrc) {
        avatarEl.innerHTML = `<img src="${picSrc}" class="modal-avatar" alt="${fullName}" onerror="this.outerHTML='<div class=modal-avatar-initials style=background:${color};>${initials.toUpperCase()}</div>'">`;
    } else {
        avatarEl.innerHTML = `<div class="modal-avatar-initials" style="background:${color};">${initials.toUpperCase()}</div>`;
    }

    // Name & role
    document.getElementById('modalEmpName').textContent = `${lastName}, ${firstName}` || '—';
    document.getElementById('modalEmpRole').textContent = [emp.Position_held, emp.Department].filter(Boolean).join(' · ') || '—';

    // Badges
    let badges = '';
    badges += isActive
      ? '<span class="status-active" style="font-size:.68rem;"><span class="status-dot" style="background:#10b981;width:6px;height:6px;border-radius:50%;display:inline-block;"></span> Active</span>'
      : '<span class="status-inactive" style="font-size:.68rem;"><span class="status-dot" style="background:#ef4444;width:6px;height:6px;border-radius:50%;display:inline-block;"></span> Inactive</span>';
    if (isBlack) badges += ' <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>';
    document.getElementById('modalEmpBadges').innerHTML = badges;

    // Populate fields
    const fields = {
      'EmployeeID': val(emp.EmployeeID),
      'FileNo': val(emp.FileNo),
      'OfficeID': val(emp.OfficeID),
      'SSS_Number': val(emp.SSS_Number),
      'TIN_Number': val(emp.TIN_Number),
      'Philhealth_Number': val(emp.Philhealth_Number),
      'HDMF': val(emp.HDMF),
      'Department': val(emp.Department),
      'Position_held': val(emp.Position_held),
      'Job_tittle': val(emp.Job_tittle),
      'Category': val(emp.Category),
      'Branch': val(emp.Branch),
      'System': val(emp.System),
      'Hired_date': fmtDate(emp.Hired_date),
      'Date_Of_Seperation': fmtDate(emp.Date_Of_Seperation),
      'Employee_Status': val(emp.Employee_Status),
      'CutOff': val(emp.CutOff),
      'Birth_date': fmtDate(emp.Birth_date),
      'Birth_Place': val(emp.Birth_Place),
      'Gender': val(emp.Gender),
      'Civil_Status': val(emp.Civil_Status),
      'Nationality': val(emp.Nationality),
      'Religion': val(emp.Religion),
      'Mobile_Number': val(emp.Mobile_Number),
      'Phone_Number': val(emp.Phone_Number),
      'Email_Address': emp.Email_Address
        ? `<a href="mailto:${emp.Email_Address}" style="color:var(--primary);">${emp.Email_Address}</a>`
        : '<span class="empty">—</span>',
      'Present_Address': val(emp.Present_Address),
      'Permanent_Address': val(emp.Permanent_Address),
      'Contact_Person': val(emp.Contact_Person),
      'Relationship': val(emp.Relationship),
      'Contact_Number_Emergency': val(emp.Contact_Number_Emergency),
      'Educational_Background': val(emp.Educational_Background),
      'Notes': val(emp.Notes),
    };

    Object.entries(fields).forEach(([key, html]) => {
      const el = document.getElementById('d-' + key);
      if (el) el.innerHTML = html;
    });
  });

});
</script>
</body>
</html>