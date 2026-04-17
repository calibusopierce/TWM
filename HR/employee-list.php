<?php
// ══════════════════════════════════════════════════════════════
//  HR/employee-list.php
//  Fixes: DateTime objects → strings, MiddleName in modal,
//         Edit/Save now posts to self (no separate employee-update.php),
//         readonly fields excluded from FormData, edit-mode button flow
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR']);

// ── Session context (MUST come before any code that uses $isAdmin) ──
$_userType = $_SESSION['UserType'] ?? '';
$isAdmin   = in_array($_userType, ['Admin', 'Administrator']);

// ══════════════════════════════════════════════════════════════
//  INLINE AJAX — POST handler (runs before any HTML output)
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'update') {
    header('Content-Type: application/json');

    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $fileNo = isset($_POST['FileNo']) && $_POST['FileNo'] !== '' ? (int)$_POST['FileNo'] : null;
    $empId  = isset($_POST['EmployeeID']) && $_POST['EmployeeID'] !== '' ? trim($_POST['EmployeeID']) : null;

    if (!$fileNo && !$empId) {
        echo json_encode(['success' => false, 'message' => 'Missing employee identifier.']);
        exit;
    }

    $sp_fn = fn(string $k) => isset($_POST[$k]) ? trim($_POST[$k]) : null;

    $stringFields = [
        'OfficeID', 'SSS_Number', 'TIN_Number', 'Philhealth_Number', 'HDMF',
        'LastName', 'FirstName', 'MiddleName',
        'Department', 'Position_held', 'Job_tittle', 'Category',
        'Branch', 'System', 'Employee_Status', 'CutOff',
        'Birth_Place', 'Civil_Status', 'Gender', 'Nationality', 'Religion',
        'Mobile_Number', 'Phone_Number', 'Email_Address',
        'Present_Address', 'Permanent_Address',
        'Contact_Person', 'Relationship', 'Contact_Number_Emergency',
        'Educational_Background', 'Notes',
    ];
    $dateFields = ['Hired_date', 'Date_Of_Seperation', 'Birth_date'];

    $setClauses  = [];
    $queryParams = [];

    foreach ($stringFields as $field) {
        $v = $sp_fn($field);
        if ($v === null) continue;
        $setClauses[]  = "[{$field}] = ?";
        $queryParams[] = ($v === '') ? null : $v;
    }
    foreach ($dateFields as $field) {
        $v = $sp_fn($field);
        if ($v === null) continue;
        $setClauses[]  = "[{$field}] = ?";
        $queryParams[] = ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
    }

    if (empty($setClauses)) {
        echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
        exit;
    }

    if ($fileNo) {
        $queryParams[] = $fileNo;
        $whereSql = "WHERE FileNo = ?";
    } else {
        $queryParams[] = $empId;
        $whereSql = "WHERE EmployeeID = ?";
    }

    $updateSql  = "UPDATE [dbo].[TBL_HREmployeeList] SET " . implode(', ', $setClauses) . " {$whereSql}";
    $updateStmt = sqlsrv_query($conn, $updateSql, $queryParams);

    if ($updateStmt === false) {
        $errors = sqlsrv_errors();
        echo json_encode(['success' => false, 'message' => $errors[0]['message'] ?? 'Update failed.']);
    } else {
        sqlsrv_free_stmt($updateStmt);
        echo json_encode(['success' => true, 'message' => 'Employee record updated successfully.']);
    }
    exit;
}

// ── Pagination & filters ───────────────────────────────────────
$perPage    = 20;
$page       = max(1, (int)($_GET['page']    ?? 1));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search']           ?? '');
$deptFilter = trim($_GET['dept']             ?? '');

// ── Session department ─────────────────────────────────────────
$_sessionDept = trim($_SESSION['Department'] ?? '');
$viewAll      = ($isAdmin && $_sessionDept === '');
$activeDept   = $_sessionDept;

// ── Build WHERE — always Active = 1 ───────────────────────────
function buildWhere(bool $viewAll, string $userDept, string $search, string $deptFilter, array &$params): string
{
    $params = [];
    $where  = "WHERE Active = 1";

    if (!$viewAll && $userDept !== '') {
        $where   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $params[] = '%' . $userDept . '%';
    }
    if ($viewAll && $deptFilter !== '') {
        $where   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $params[] = '%' . $deptFilter . '%';
    }
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
$where  = buildWhere($viewAll, $activeDept, $search, $deptFilter, $params);

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
        CONVERT(varchar(10), Hired_date, 23) AS Hired_date, CONVERT(varchar(10), Date_Of_Seperation, 23) AS Date_Of_Seperation, Employee_Status,
        LastName, FirstName, MiddleName,
        Permanent_Address, Present_Address,
        SSS_Number, TIN_Number, Philhealth_Number, HDMF,
        Phone_Number, Mobile_Number, Email_Address,
       CONVERT(varchar(10), Birth_date, 23) AS Birth_date, Birth_Place, Civil_Status, Gender,
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

// ── FIX: Serialize DateTime objects → plain strings ────────────
function serializeRow(array $row): array
{
    $dateFields = ['Hired_date', 'Date_Of_Seperation', 'Birth_date'];
    foreach ($dateFields as $f) {
        if (isset($row[$f]) && $row[$f] instanceof DateTime) {
            $row[$f] = $row[$f]->format('Y-m-d');
        } elseif (isset($row[$f]) && is_string($row[$f]) && $row[$f]) {
            // normalise any existing string
            $row[$f] = $row[$f];
        } else {
            $row[$f] = null;
        }
    }
    return $row;
}

if ($stmt) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = serializeRow($r);
    }
    sqlsrv_free_stmt($stmt);
}

// ── Departments for filter dropdown ───────────────────────────
$deptStmt = sqlsrv_query($conn,
    "SELECT DepartmentName FROM [dbo].[Departments]
     WHERE Status = 1 ORDER BY DepartmentName");
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
    .emp-name-wrap { display: flex; align-items: center; gap: .65rem; }
    .emp-name { font-weight: 700; font-size: .88rem; color: var(--text-primary); line-height: 1.2; }
    .emp-sub  { font-size: .72rem; color: var(--text-muted); margin-top: .1rem; }
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
      border: 1px solid rgba(239,68,68,.3); margin-left: .3rem;
    }
    .emp-row { cursor: pointer; transition: background .12s; }
    .emp-row:hover td { background: rgba(67,128,226,.04); }

    /* ── Detail Modal ── */
    .detail-modal .modal-content {
      border-radius: 16px; border: none;
      box-shadow: 0 24px 80px rgba(0,0,0,.2);
    }
    .detail-modal .modal-header {
      background: var(--bs-body-bg, #fff);   /* matches page bg */
      border-bottom: 1px solid #e2e8f0;
      border-radius: 16px 16px 0 0;
      padding: 1rem 1.5rem;
    }
    .detail-modal .modal-title {
      font-weight: 700;
      color: #0f172a;    /* dark, readable */
      font-size: 1rem;
    }
    .detail-modal .btn-close {
      filter: none;     /* remove the invert — dark icon on light bg now */
      opacity: .6;
    }
    .modal-avatar-wrap {
      display: flex; align-items: center; gap: 1rem;
      padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9;
    }
    .modal-avatar        { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; flex-shrink: 0; }
    .modal-avatar-initials {
      width: 64px; height: 64px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; font-weight: 800; color: #fff; flex-shrink: 0;
    }
    .modal-emp-name { font-size: 1.1rem; font-weight: 800; color: #0f172a; }
    .modal-emp-role { font-size: .82rem; color: #64748b; margin-top: .15rem; }
    .detail-section { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; }
    .detail-section:last-child { border-bottom: none; }
    .detail-section-title {
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: #475569;
      margin-bottom: .75rem;
      padding-left: .6rem;
      border-left: 3px solid #3b82f6;  /* blue accent bar */
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .detail-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem; }
    .detail-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .5rem .75rem; }
    .detail-item label {
      font-size: .68rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .06em;
      display: block; margin-bottom: .15rem;
    }
    .detail-item .d-val {
      font-size: .83rem; font-weight: 500; color: #1e293b;
      display: block; word-break: break-word;
    }
    .detail-item .d-val.empty { color: #cbd5e1; font-style: italic; }

    /* ── Edit mode ── */
    .detail-item .d-input { display: none; }
    .edit-mode .detail-item .d-val   { display: none !important; }
    .edit-mode .detail-item .d-input {
      display: block; width: 100%; padding: .3rem .5rem;
      border: 1px solid #c7d2fe; border-radius: 6px;
      font-size: .83rem; background: #f8faff;
      transition: border-color .15s, box-shadow .15s;
    }
    .edit-mode .detail-item .d-input:focus {
      outline: none; border-color: #4380e2;
      box-shadow: 0 0 0 3px rgba(67,128,226,.15);
    }
    /* Read-only fields in edit mode */
    .detail-item .d-input[readonly] {
      background: #f1f5f9 !important; color: #94a3b8; cursor: not-allowed;
    }

    /* edit-mode banner */
    #editModeBanner {
      display: none; background: #eff6ff; border-bottom: 1px solid #bfdbfe;
      padding: .5rem 1.5rem; font-size: .78rem; color: #1d4ed8;
      align-items: center; gap: .4rem;
    }
    #editModeBanner.visible { display: flex; }

    @media (max-width: 576px) {
      .detail-grid, .detail-grid-3 { grid-template-columns: 1fr; }
    }

    /* ── Print styles ── */
    @media print {
      body > *:not(#printArea) { display: none !important; }
      #printArea {
        display: block !important;
        font-family: 'Segoe UI', sans-serif;
        color: #0f172a; padding: 0; margin: 0;
      }
      .print-header {
        display: flex; align-items: center; gap: 1rem;
        padding: 1rem 1.5rem; border-bottom: 3px solid #1e40af;
        margin-bottom: 1rem;
      }
      .print-avatar {
        width: 70px; height: 70px; border-radius: 50%;
        object-fit: cover; border: 3px solid #e2e8f0;
      }
      .print-avatar-initials {
        width: 70px; height: 70px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem; font-weight: 800; color: #fff;
      }
      .print-name  { font-size: 1.2rem; font-weight: 800; }
      .print-role  { font-size: .85rem; color: #475569; }
      .print-section { margin-bottom: 1rem; padding: .75rem 1.5rem; page-break-inside: avoid; }
      .print-section-title {
        font-size: .65rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: .1em; color: #1e40af;
        border-bottom: 1px solid #e2e8f0; padding-bottom: .3rem; margin-bottom: .6rem;
      }
      .print-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem .75rem; }
      .print-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .3rem .75rem; }
      .print-item label { font-size: .65rem; font-weight: 700; color: #94a3b8; display: block; text-transform: uppercase; }
      .print-item span  { font-size: .82rem; color: #0f172a; display: block; }
      .print-footer { text-align: right; font-size: .65rem; color: #94a3b8; padding: 0 1.5rem; margin-top: 1.5rem; }
    }
    #printArea { display: none; }
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
        Showing active employees only &nbsp;—&nbsp;
        <strong><?= $totalRows ?></strong> record<?= $totalRows !== 1 ? 's' : '' ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:.6rem;margin-top:.35rem;">
      <a href="employee-inactive.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-person-dash"></i> Inactive Employees
      </a>
      <a href="employee-blacklist.php" class="btn btn-sm" style="background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.3);font-weight:600;">
        <i class="bi bi-slash-circle"></i> Blacklisted Employees
      </a>
    </div>
  </div>

  <!-- Filter bar -->
  <div class="filter-card">
    <form method="get" id="filterForm">
      <div class="filter-row">
        <div style="position:relative;">
          <i class="bi bi-search" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none;"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 class="form-control" placeholder="Search name, ID, position…"
                 style="padding-left:2rem;max-width:240px;">
        </div>
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
        <a href="?" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
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
            $fullName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));
            $initials = initials($emp['FirstName'] ?? '', $emp['LastName'] ?? '');
            $bgColor  = avatarColor($fullName);
            $isActive = (int)($emp['Active'] ?? 0) === 1;
            $isBlack  = (int)($emp['Blacklisted'] ?? 0) === 1;
            $picPath  = trim($emp['Picture'] ?? '');
            if ($picPath && !str_starts_with($picPath, '/')) {
                $picPath = '/TWM/tradewellportal/' . $picPath;
            }
            $hasPic = !empty($picPath);
        ?>
          <?php $empJson = json_encode($emp, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
          <tr class="emp-row"
              data-emp='<?= $empJson ?: "{}" ?>'
              data-bs-toggle="modal" data-bs-target="#empDetailModal">
            <td>
              <div class="emp-name-wrap">
                <?php if ($hasPic): ?>
                  <img src="<?= htmlspecialchars($picPath) ?>" class="emp-avatar" alt="<?= htmlspecialchars($fullName) ?>">
                <?php else: ?>
                  <div class="emp-avatar" style="background:<?= $bgColor ?>;"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
                <div>
                  <div class="emp-name">
                    <?= htmlspecialchars($emp['LastName'] ?? '') ?>, <?= htmlspecialchars($emp['FirstName'] ?? '') ?>
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

      <!-- Edit mode banner -->
      <div id="editModeBanner">
        <i class="bi bi-pencil-square"></i>
        <strong>Edit Mode</strong>&nbsp;— Make changes below, then click Save.
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

      <div class="modal-body" id="modalBody" style="padding:0;">

        <!-- IDs -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-fingerprint"></i> Identification</div>
          <div class="detail-grid-3">
            <?php
            // Helper macro for fields: field key, label, readonly in edit?
            // We'll use JS to render, just define structure here
            ?>
            <div class="detail-item"><label>Employee ID</label>
              <span class="d-val" id="d-EmployeeID">—</span>
              <input class="d-input" id="e-EmployeeID" data-field="EmployeeID" readonly>
            </div>
            <div class="detail-item"><label>File No</label>
              <span class="d-val" id="d-FileNo">—</span>
              <input class="d-input" id="e-FileNo" data-field="FileNo" readonly>
            </div>
            <div class="detail-item"><label>Office ID</label>
              <span class="d-val" id="d-OfficeID">—</span>
              <input class="d-input" id="e-OfficeID" data-field="OfficeID">
            </div>
            <div class="detail-item"><label>SSS Number</label>
              <span class="d-val" id="d-SSS_Number">—</span>
              <input class="d-input" id="e-SSS_Number" data-field="SSS_Number">
            </div>
            <div class="detail-item"><label>TIN Number</label>
              <span class="d-val" id="d-TIN_Number">—</span>
              <input class="d-input" id="e-TIN_Number" data-field="TIN_Number">
            </div>
            <div class="detail-item"><label>PhilHealth</label>
              <span class="d-val" id="d-Philhealth_Number">—</span>
              <input class="d-input" id="e-Philhealth_Number" data-field="Philhealth_Number">
            </div>
            <div class="detail-item"><label>HDMF / Pag-IBIG</label>
              <span class="d-val" id="d-HDMF">—</span>
              <input class="d-input" id="e-HDMF" data-field="HDMF">
            </div>
          </div>
        </div>

        <!-- Name -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person-vcard"></i> Full Name</div>
          <div class="detail-grid-3">
            <div class="detail-item"><label>Last Name</label>
              <span class="d-val" id="d-LastName">—</span>
              <input class="d-input" id="e-LastName" data-field="LastName">
            </div>
            <div class="detail-item"><label>First Name</label>
              <span class="d-val" id="d-FirstName">—</span>
              <input class="d-input" id="e-FirstName" data-field="FirstName">
            </div>
            <div class="detail-item"><label>Middle Name</label>
              <span class="d-val" id="d-MiddleName">—</span>
              <input class="d-input" id="e-MiddleName" data-field="MiddleName">
            </div>
          </div>
        </div>

        <!-- Work info -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-briefcase"></i> Work Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Department</label>
              <span class="d-val" id="d-Department">—</span>
              <input class="d-input" id="e-Department" data-field="Department">
            </div>
            <div class="detail-item"><label>Position</label>
              <span class="d-val" id="d-Position_held">—</span>
              <input class="d-input" id="e-Position_held" data-field="Position_held">
            </div>
            <div class="detail-item"><label>Job Title</label>
              <span class="d-val" id="d-Job_tittle">—</span>
              <input class="d-input" id="e-Job_tittle" data-field="Job_tittle">
            </div>
            <div class="detail-item"><label>Category</label>
              <span class="d-val" id="d-Category">—</span>
              <input class="d-input" id="e-Category" data-field="Category">
            </div>
            <div class="detail-item"><label>Branch</label>
              <span class="d-val" id="d-Branch">—</span>
              <input class="d-input" id="e-Branch" data-field="Branch">
            </div>
            <div class="detail-item"><label>System</label>
              <span class="d-val" id="d-System">—</span>
              <input class="d-input" id="e-System" data-field="System">
            </div>
            <div class="detail-item"><label>Hired Date</label>
              <span class="d-val" id="d-Hired_date">—</span>
              <input class="d-input" id="e-Hired_date" data-field="Hired_date" type="date">
            </div>
            <div class="detail-item"><label>Separation Date</label>
              <span class="d-val" id="d-Date_Of_Seperation">—</span>
              <input class="d-input" id="e-Date_Of_Seperation" data-field="Date_Of_Seperation" type="date">
            </div>
            <div class="detail-item"><label>Employee Status</label>
              <span class="d-val" id="d-Employee_Status">—</span>
              <input class="d-input" id="e-Employee_Status" data-field="Employee_Status">
            </div>
            <div class="detail-item"><label>Cut-Off</label>
              <span class="d-val" id="d-CutOff">—</span>
              <input class="d-input" id="e-CutOff" data-field="CutOff">
            </div>
          </div>
        </div>

        <!-- Personal info -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person"></i> Personal Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Birth Date</label>
              <span class="d-val" id="d-Birth_date">—</span>
              <input class="d-input" id="e-Birth_date" data-field="Birth_date" type="date">
            </div>
            <div class="detail-item"><label>Birth Place</label>
              <span class="d-val" id="d-Birth_Place">—</span>
              <input class="d-input" id="e-Birth_Place" data-field="Birth_Place">
            </div>
            <div class="detail-item"><label>Gender</label>
              <span class="d-val" id="d-Gender">—</span>
              <select class="d-input" id="e-Gender" data-field="Gender">
                <option value="">— Select —</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="detail-item"><label>Civil Status</label>
              <span class="d-val" id="d-Civil_Status">—</span>
              <select class="d-input" id="e-Civil_Status" data-field="Civil_Status">
                <option value="">— Select —</option>
                <option value="Single">Single</option>
                <option value="Married">Married</option>
                <option value="Widowed">Widowed</option>
                <option value="Separated">Separated</option>
                <option value="Divorced">Divorced</option>
              </select>
            </div>
            <div class="detail-item"><label>Nationality</label>
              <span class="d-val" id="d-Nationality">—</span>
              <input class="d-input" id="e-Nationality" data-field="Nationality">
            </div>
            <div class="detail-item"><label>Religion</label>
              <span class="d-val" id="d-Religion">—</span>
              <input class="d-input" id="e-Religion" data-field="Religion">
            </div>
          </div>
        </div>

        <!-- Contact -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Mobile</label>
              <span class="d-val" id="d-Mobile_Number">—</span>
              <input class="d-input" id="e-Mobile_Number" data-field="Mobile_Number">
            </div>
            <div class="detail-item"><label>Phone</label>
              <span class="d-val" id="d-Phone_Number">—</span>
              <input class="d-input" id="e-Phone_Number" data-field="Phone_Number">
            </div>
            <div class="detail-item"><label>Email</label>
              <span class="d-val" id="d-Email_Address">—</span>
              <input class="d-input" id="e-Email_Address" data-field="Email_Address" type="email">
            </div>
            <div class="detail-item"><label>Present Address</label>
              <span class="d-val" id="d-Present_Address">—</span>
              <input class="d-input" id="e-Present_Address" data-field="Present_Address">
            </div>
            <div class="detail-item"><label>Permanent Address</label>
              <span class="d-val" id="d-Permanent_Address">—</span>
              <input class="d-input" id="e-Permanent_Address" data-field="Permanent_Address">
            </div>
          </div>
        </div>

        <!-- Emergency contact -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-heart-pulse"></i> Emergency Contact</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Contact Person</label>
              <span class="d-val" id="d-Contact_Person">—</span>
              <input class="d-input" id="e-Contact_Person" data-field="Contact_Person">
            </div>
            <div class="detail-item"><label>Relationship</label>
              <span class="d-val" id="d-Relationship">—</span>
              <input class="d-input" id="e-Relationship" data-field="Relationship">
            </div>
            <div class="detail-item"><label>Contact Number</label>
              <span class="d-val" id="d-Contact_Number_Emergency">—</span>
              <input class="d-input" id="e-Contact_Number_Emergency" data-field="Contact_Number_Emergency">
            </div>
          </div>
        </div>

        <!-- Education & Notes -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-book"></i> Education &amp; Notes</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Educational Background</label>
              <span class="d-val" id="d-Educational_Background">—</span>
              <input class="d-input" id="e-Educational_Background" data-field="Educational_Background">
            </div>
            <div class="detail-item"><label>Notes</label>
              <span class="d-val" id="d-Notes">—</span>
              <textarea class="d-input" id="e-Notes" data-field="Notes" rows="2"></textarea>
            </div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer" style="gap:.5rem;">
        <!-- View mode buttons -->
        <div id="viewButtons" style="display:flex;gap:.5rem;width:100%;justify-content:flex-end;">
          <button type="button" id="btnPrint" class="btn btn-sm btn-secondary">
            <i class="bi bi-printer"></i> Print / PDF
          </button>
          <?php if ($isAdmin): ?>
          <button type="button" id="btnEdit" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil-square"></i> Edit
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
        <!-- Edit mode buttons (hidden until edit) -->
        <div id="editButtons" style="display:none;gap:.5rem;width:100%;justify-content:flex-end;">
          <button type="button" id="btnSave" class="btn btn-sm btn-success">
            <i class="bi bi-check-lg"></i> Save Changes
          </button>
          <button type="button" id="btnCancelEdit" class="btn btn-sm btn-secondary">
            <i class="bi bi-x-lg"></i> Cancel
          </button>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ══ HIDDEN PRINT AREA ══════════════════════════════════════ -->
<div id="printArea" aria-hidden="true"></div>


<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

  // ── Helpers ───────────────────────────────────────────────────
  const avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
  function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    return avatarColors[Math.abs(h) % avatarColors.length];
  }

  function val(v) {
    const s = (v === null || v === undefined) ? '' : String(v).trim();
    return s || null;   // return null when empty so we can show "—"
  }

  /**
   * FIX: PHP sqlsrv DateTime objects become { date: "...", timezone_type: N, timezone: "..." }
   * when JSON-encoded. We detect that and extract the date string.
   */
  function resolveDate(v) {
    if (!v) return null;
    // PHP DateTime serialised as object
    if (typeof v === 'object' && v.date) return v.date.substring(0, 10); // "YYYY-MM-DD"
    if (typeof v === 'string') {
      // strip time portion if present: "2020-01-15 00:00:00.000"
      return v.substring(0, 10);
    }
    return null;
  }

  function fmtDate(v) {
    const iso = resolveDate(v);
    if (!iso) return null;
    // Parse as local date (avoid UTC off-by-one)
    const [y, m, d] = iso.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function setVal(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html || '<span class="empty">—</span>';
  }

  function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text || '—';
  }

  // ── Current employee data ─────────────────────────────────────
  let currentEmp = {};
  let editMode   = false;

  // ── Modal open ───────────────────────────────────────────────
  document.getElementById('empDetailModal').addEventListener('show.bs.modal', e => {
    const row = e.relatedTarget;
    if (!row) return;

    try { currentEmp = JSON.parse(row.dataset.emp || '{}'); } catch { currentEmp = {}; }

    populateModal(currentEmp);
    exitEditMode();
  });

  function populateModal(emp) {
    const firstName = emp.FirstName || '';
    const lastName  = emp.LastName  || '';
    const fullName  = `${firstName} ${lastName}`.trim();
    const initials  = ((firstName[0] || '') + (lastName[0] || '')).toUpperCase();
    const color     = avatarColor(fullName);
    const isActive  = parseInt(emp.Active || 0) === 1;
    const isBlack   = parseInt(emp.Blacklisted || 0) === 1;

    // Avatar
    const avatarEl = document.getElementById('modalAvatarEl');
    let picSrc = (emp.Picture || '').trim();
    if (picSrc && !picSrc.startsWith('/')) picSrc = '/TWM/tradewellportal/' + picSrc;
    if (picSrc) {
      avatarEl.innerHTML = `<img src="${picSrc}" class="modal-avatar" alt="${fullName}"
        onerror="this.outerHTML='<div class=modal-avatar-initials style=background:${color};>${initials}</div>'">`;
    } else {
      avatarEl.innerHTML = `<div class="modal-avatar-initials" style="background:${color};">${initials}</div>`;
    }

    // Header text
    setText('modalEmpName', `${lastName}, ${firstName}`);
    document.getElementById('modalEmpRole').textContent =
      [emp.Position_held, emp.Department].filter(Boolean).join(' · ') || '—';

    // Badges
    let badges = isActive
      ? '<span class="status-active"><span class="status-dot" style="background:#10b981;"></span> Active</span>'
      : '<span class="status-inactive"><span class="status-dot" style="background:#ef4444;"></span> Inactive</span>';
    if (isBlack) badges += ' <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>';
    document.getElementById('modalEmpBadges').innerHTML = badges;

    // ── Populate display spans ─────────────────────────────────
    // Identification
    setVal('d-EmployeeID',       val(emp.EmployeeID));
    setVal('d-FileNo',           val(emp.FileNo));
    setVal('d-OfficeID',         val(emp.OfficeID));
    setVal('d-SSS_Number',       val(emp.SSS_Number));
    setVal('d-TIN_Number',       val(emp.TIN_Number));
    setVal('d-Philhealth_Number',val(emp.Philhealth_Number));
    setVal('d-HDMF',             val(emp.HDMF));

    // Full name (now in modal!)
    setVal('d-LastName',   val(emp.LastName));
    setVal('d-FirstName',  val(emp.FirstName));
    setVal('d-MiddleName', val(emp.MiddleName));   // ← FIX: was missing before

    // Work
    setVal('d-Department',       val(emp.Department));
    setVal('d-Position_held',    val(emp.Position_held));
    setVal('d-Job_tittle',       val(emp.Job_tittle));
    setVal('d-Category',         val(emp.Category));
    setVal('d-Branch',           val(emp.Branch));
    setVal('d-System',           val(emp.System));
    setVal('d-Hired_date',       fmtDate(emp.Hired_date));     // ← FIX: DateTime resolved
    setVal('d-Date_Of_Seperation', fmtDate(emp.Date_Of_Seperation));
    setVal('d-Employee_Status',  val(emp.Employee_Status));
    setVal('d-CutOff',           val(emp.CutOff));

    // Personal
    setVal('d-Birth_date',  fmtDate(emp.Birth_date));          // ← FIX: DateTime resolved
    setVal('d-Birth_Place', val(emp.Birth_Place));
    setVal('d-Gender',      val(emp.Gender));
    setVal('d-Civil_Status',val(emp.Civil_Status));
    setVal('d-Nationality', val(emp.Nationality));
    setVal('d-Religion',    val(emp.Religion));

    // Contact
    setVal('d-Mobile_Number',    val(emp.Mobile_Number));
    setVal('d-Phone_Number',     val(emp.Phone_Number));
    const email = val(emp.Email_Address);
    setVal('d-Email_Address', email
      ? `<a href="mailto:${email}" style="color:var(--primary);">${email}</a>`
      : null);
    setVal('d-Present_Address',   val(emp.Present_Address));
    setVal('d-Permanent_Address', val(emp.Permanent_Address));

    // Emergency
    setVal('d-Contact_Person',          val(emp.Contact_Person));
    setVal('d-Relationship',            val(emp.Relationship));
    setVal('d-Contact_Number_Emergency',val(emp.Contact_Number_Emergency));

    // Education & Notes
    setVal('d-Educational_Background', val(emp.Educational_Background));
    setVal('d-Notes',                  val(emp.Notes));

    // ── Populate edit inputs ───────────────────────────────────
    function setInput(id, v) {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'SELECT') {
        el.value = val(v) || '';
      } else if (el.type === 'date') {
        el.value = resolveDate(v) || '';
      } else {
        el.value = val(v) || '';
      }
    }

    setInput('e-EmployeeID',              emp.EmployeeID);
    setInput('e-FileNo',                  emp.FileNo);
    setInput('e-OfficeID',                emp.OfficeID);
    setInput('e-SSS_Number',              emp.SSS_Number);
    setInput('e-TIN_Number',              emp.TIN_Number);
    setInput('e-Philhealth_Number',       emp.Philhealth_Number);
    setInput('e-HDMF',                    emp.HDMF);
    setInput('e-LastName',                emp.LastName);
    setInput('e-FirstName',               emp.FirstName);
    setInput('e-MiddleName',              emp.MiddleName);
    setInput('e-Department',              emp.Department);
    setInput('e-Position_held',           emp.Position_held);
    setInput('e-Job_tittle',              emp.Job_tittle);
    setInput('e-Category',                emp.Category);
    setInput('e-Branch',                  emp.Branch);
    setInput('e-System',                  emp.System);
    setInput('e-Hired_date',              emp.Hired_date);
    setInput('e-Date_Of_Seperation',      emp.Date_Of_Seperation);
    setInput('e-Employee_Status',         emp.Employee_Status);
    setInput('e-CutOff',                  emp.CutOff);
    setInput('e-Birth_date',              emp.Birth_date);
    setInput('e-Birth_Place',             emp.Birth_Place);
    setInput('e-Gender',                  emp.Gender);
    setInput('e-Civil_Status',            emp.Civil_Status);
    setInput('e-Nationality',             emp.Nationality);
    setInput('e-Religion',                emp.Religion);
    setInput('e-Mobile_Number',           emp.Mobile_Number);
    setInput('e-Phone_Number',            emp.Phone_Number);
    setInput('e-Email_Address',           emp.Email_Address);
    setInput('e-Present_Address',         emp.Present_Address);
    setInput('e-Permanent_Address',       emp.Permanent_Address);
    setInput('e-Contact_Person',          emp.Contact_Person);
    setInput('e-Relationship',            emp.Relationship);
    setInput('e-Contact_Number_Emergency',emp.Contact_Number_Emergency);
    setInput('e-Educational_Background',  emp.Educational_Background);
    setInput('e-Notes',                   emp.Notes);
  }

  // ── Edit / Save / Cancel ──────────────────────────────────────
  function enterEditMode() {
    editMode = true;
    document.getElementById('modalBody').classList.add('edit-mode');
    document.getElementById('editModeBanner').classList.add('visible');
    document.getElementById('viewButtons').style.display  = 'none';
    document.getElementById('editButtons').style.display  = 'flex';
  }

  function exitEditMode() {
    editMode = false;
    document.getElementById('modalBody').classList.remove('edit-mode');
    document.getElementById('editModeBanner').classList.remove('visible');
    document.getElementById('viewButtons').style.display  = 'flex';
    document.getElementById('editButtons').style.display  = 'none';
  }

  document.getElementById('btnEdit')?.addEventListener('click', enterEditMode);
  document.getElementById('btnCancelEdit')?.addEventListener('click', () => {
    populateModal(currentEmp);  // reset fields
    exitEditMode();
  });

  document.getElementById('btnSave')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

    // Build FormData — post to self with _action=update
    const fd = new FormData();
    fd.append('_action',    'update');
    fd.append('FileNo',     currentEmp.FileNo     || '');
    fd.append('EmployeeID', currentEmp.EmployeeID || '');

    document.querySelectorAll('.d-input[data-field]').forEach(el => {
      if (!el.readOnly) fd.append(el.dataset.field, el.value);
    });

    try {
      const res = await fetch(window.location.pathname, { method: 'POST', body: fd });

      // Read as text first so a PHP error page doesn't crash JSON.parse silently
      const raw = await res.text();
      let json;
      try {
        json = JSON.parse(raw);
      } catch {
        console.error('Server returned non-JSON:', raw);
        showToast('Server error — check console for details.', 'danger');
        return;
      }

      if (json.success) {
        // Sync updated values back into currentEmp
        document.querySelectorAll('.d-input[data-field]').forEach(el => {
          currentEmp[el.dataset.field] = el.value;
        });
        populateModal(currentEmp);
        exitEditMode();
        showToast(json.message || 'Changes saved successfully.', 'success');

        // Update the matching table row so the list reflects the change live
        document.querySelectorAll('.emp-row').forEach(r => {
          try {
            const d = JSON.parse(r.dataset.emp);
            if (String(d.FileNo) === String(currentEmp.FileNo)) {
              r.dataset.emp = JSON.stringify(currentEmp);
              const nameEl = r.querySelector('.emp-name');
              if (nameEl) {
                const textNodes = [...nameEl.childNodes].filter(n => n.nodeType === 3);
                if (textNodes[0]) textNodes[0].textContent =
                  `${currentEmp.LastName || ''}, ${currentEmp.FirstName || ''} `;
              }
              const subEl = r.querySelector('.emp-sub');
              if (subEl) subEl.textContent = currentEmp.MiddleName || '';
            }
          } catch { /* ignore */ }
        });
      } else {
        showToast('Save failed: ' + (json.message || 'Unknown error.'), 'danger');
      }
    } catch (err) {
      console.error('Fetch error:', err);
      showToast('Network error — please try again.', 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Save Changes';
    }
  });

  // ── Toast helper ─────────────────────────────────────────────
  function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} alert-dismissible`;
    t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;min-width:260px;box-shadow:0 4px 20px rgba(0,0,0,.15);';
    t.innerHTML = msg + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4000);
  }

  // ── Print / PDF ───────────────────────────────────────────────
  document.getElementById('btnPrint')?.addEventListener('click', () => {
    buildPrintArea(currentEmp);
    window.print();
  });

  function pv(v) {  // print value
    const s = (v === null || v === undefined) ? '' : String(v).trim();
    return s || '—';
  }
  function pDate(v) {
    const f = fmtDate(v);
    return (f && !f.includes('—')) ? f : '—';
  }

  function section(title, rows) {
    const cols = rows.length <= 3 ? 'print-grid-3' : 'print-grid';
    const items = rows.map(([label, value]) =>
      `<div class="print-item"><label>${label}</label><span>${value}</span></div>`
    ).join('');
    return `
      <div class="print-section">
        <div class="print-section-title">${title}</div>
        <div class="${cols}">${items}</div>
      </div>`;
  }

  function buildPrintArea(emp) {
    const firstName = emp.FirstName || '';
    const lastName  = emp.LastName  || '';
    const fullName  = `${firstName} ${lastName}`.trim();
    const initials  = ((firstName[0] || '') + (lastName[0] || '')).toUpperCase();
    const color     = avatarColor(fullName);

    let picSrc = (emp.Picture || '').trim();
    if (picSrc && !picSrc.startsWith('/')) picSrc = '/TWM/tradewellportal/' + picSrc;

    const avatarHtml = picSrc
      ? `<img class="print-avatar" src="${picSrc}" alt="${fullName}">`
      : `<div class="print-avatar-initials" style="background:${color};">${initials}</div>`;

    const isActive = parseInt(emp.Active || 0) === 1;
    const isBlack  = parseInt(emp.Blacklisted || 0) === 1;
    let statusText = isActive ? '✔ Active' : '✘ Inactive';
    if (isBlack) statusText += '  |  ⚠ Blacklisted';

    const html = `
      <div class="print-header">
        ${avatarHtml}
        <div>
          <div class="print-name">${pv(lastName)}, ${pv(firstName)} ${pv(emp.MiddleName)}</div>
          <div class="print-role">${pv(emp.Position_held)} · ${pv(emp.Department)}</div>
          <div style="font-size:.75rem;margin-top:.25rem;color:#475569;">${statusText}</div>
        </div>
      </div>

      ${section('Identification', [
        ['Employee ID', pv(emp.EmployeeID)],
        ['File No',     pv(emp.FileNo)],
        ['Office ID',   pv(emp.OfficeID)],
        ['SSS Number',  pv(emp.SSS_Number)],
        ['TIN Number',  pv(emp.TIN_Number)],
        ['PhilHealth',  pv(emp.Philhealth_Number)],
        ['HDMF / Pag-IBIG', pv(emp.HDMF)],
      ])}

      ${section('Work Information', [
        ['Department',     pv(emp.Department)],
        ['Position',       pv(emp.Position_held)],
        ['Job Title',      pv(emp.Job_tittle)],
        ['Category',       pv(emp.Category)],
        ['Branch',         pv(emp.Branch)],
        ['System',         pv(emp.System)],
        ['Hired Date',     pDate(emp.Hired_date)],
        ['Separation Date',pDate(emp.Date_Of_Seperation)],
        ['Employee Status',pv(emp.Employee_Status)],
        ['Cut-Off',        pv(emp.CutOff)],
      ])}

      ${section('Personal Information', [
        ['Birth Date',    pDate(emp.Birth_date)],
        ['Birth Place',   pv(emp.Birth_Place)],
        ['Gender',        pv(emp.Gender)],
        ['Civil Status',  pv(emp.Civil_Status)],
        ['Nationality',   pv(emp.Nationality)],
        ['Religion',      pv(emp.Religion)],
      ])}

      ${section('Contact Information', [
        ['Mobile',            pv(emp.Mobile_Number)],
        ['Phone',             pv(emp.Phone_Number)],
        ['Email',             pv(emp.Email_Address)],
        ['Present Address',   pv(emp.Present_Address)],
        ['Permanent Address', pv(emp.Permanent_Address)],
      ])}

      ${section('Emergency Contact', [
        ['Contact Person', pv(emp.Contact_Person)],
        ['Relationship',   pv(emp.Relationship)],
        ['Contact Number', pv(emp.Contact_Number_Emergency)],
      ])}

      ${section('Education & Notes', [
        ['Educational Background', pv(emp.Educational_Background)],
        ['Notes',                  pv(emp.Notes)],
      ])}

      <div class="print-footer">
        Printed on ${new Date().toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'})}
        &nbsp;·&nbsp; HR Employee List
      </div>`;

    document.getElementById('printArea').innerHTML = html;
  }

});
</script>
</body>
</html>