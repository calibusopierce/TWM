<?php
// ══════════════════════════════════════════════════════════════
//  HR/add-employee.php
//  Create a new employee record with IDENTITY_INSERT ON
//  FileNo is manually assigned (max + 1) to work alongside VB
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'employee_list');

$_userType = $_SESSION['UserType'] ?? '';
$isAdmin   = in_array($_userType, ['Admin', 'Administrator', 'HR']);

if (!$isAdmin) {
    header('Location: employee-list.php');
    exit;
}

// ── Pre-fill from hired applicant (passed via query string) ───
$prefill = [];
$fromApp = isset($_GET['from_app']) && (int)$_GET['from_app'] === 1;
if ($fromApp) {
    $strFields = ['FirstName','MiddleName','LastName','Email_Address','Mobile_Number',
                  'Position_held','Department'];
    foreach ($strFields as $f) {
        $prefill[$f] = trim($_GET[$f] ?? '');
    }
    $prefill['app_id']  = (int)($_GET['app_id'] ?? 0);
}
$hiredFlash = isset($_GET['hired_flash']) && (int)$_GET['hired_flash'] === 1;

// Helper: safely output pre-fill value in HTML attribute
$pf = fn(string $k) => htmlspecialchars($prefill[$k] ?? '', ENT_QUOTES, 'UTF-8');

// ── Load departments ───────────────────────────────────────────
$deptStmt    = sqlsrv_query($conn, "SELECT DepartmentName FROM [dbo].[Departments] WHERE Status = 1 ORDER BY DepartmentName");
$departments = [];
if ($deptStmt) {
    while ($dr = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC))
        $departments[] = $dr['DepartmentName'];
    sqlsrv_free_stmt($deptStmt);
}

// ══════════════════════════════════════════════════════════════
//  AJAX — POST handler
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'create_employee') {
    header('Content-Type: application/json');

    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    // Required fields
    $firstName = trim($_POST['FirstName'] ?? '');
    $lastName  = trim($_POST['LastName']  ?? '');
    $empID     = trim($_POST['EmployeeID'] ?? '');

    if (!$firstName || !$lastName) {
        echo json_encode(['success' => false, 'message' => 'First Name and Last Name are required.']);
        exit;
    }

    // ── Get next FileNo ────────────────────────────────────────
    $maxStmt = sqlsrv_query($conn, "SELECT ISNULL(MAX(FileNo), 0) + 1 AS NextFileNo FROM [dbo].[TBL_HREmployeeList]");
    if (!$maxStmt) {
        echo json_encode(['success' => false, 'message' => 'Could not determine next FileNo.']);
        exit;
    }
    $maxRow    = sqlsrv_fetch_array($maxStmt, SQLSRV_FETCH_ASSOC);
    $nextFileNo = (int)($maxRow['NextFileNo'] ?? 1);
    sqlsrv_free_stmt($maxStmt);

    // ── Collect all fields ─────────────────────────────────────
    $sp = fn(string $k) => isset($_POST[$k]) && trim($_POST[$k]) !== '' ? trim($_POST[$k]) : null;
    $dp = function(string $k): ?string {
        $v = trim($_POST[$k] ?? '');
        return ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
    };

    $fields = [
        'FileNo'                   => $nextFileNo,
        'EmployeeID'               => $sp('EmployeeID'),
        'OfficeID'                 => $sp('OfficeID'),
        'LastName'                 => $lastName,
        'FirstName'                => $firstName,
        'MiddleName'               => $sp('MiddleName'),
        'Department'               => $sp('Department'),
        'Position_held'            => $sp('Position_held'),
        'Job_tittle'               => $sp('Job_tittle'),
        'Category'                 => $sp('Category'),
        'Branch'                   => $sp('Branch'),
        'System'                   => $sp('System'),
        'Employee_Status'          => $sp('Employee_Status'),
        'CutOff'                   => $sp('CutOff'),
        'Hired_date'               => $dp('Hired_date'),
        'Date_Of_Seperation'       => $dp('Date_Of_Seperation'),
        'SSS_Number'               => $sp('SSS_Number'),
        'TIN_Number'               => $sp('TIN_Number'),
        'Philhealth_Number'        => $sp('Philhealth_Number'),
        'HDMF'                     => $sp('HDMF'),
        'Birth_date'               => $dp('Birth_date'),
        'Birth_Place'              => $sp('Birth_Place'),
        'Civil_Status'             => $sp('Civil_Status'),
        'Gender'                   => $sp('Gender'),
        'Nationality'              => $sp('Nationality'),
        'Religion'                 => $sp('Religion'),
        'Mobile_Number'            => $sp('Mobile_Number'),
        'Phone_Number'             => $sp('Phone_Number'),
        'Email_Address'            => $sp('Email_Address'),
        'Present_Address'          => $sp('Present_Address'),
        'Permanent_Address'        => $sp('Permanent_Address'),
        'Contact_Person'           => $sp('Contact_Person'),
        'Relationship'             => $sp('Relationship'),
        'Contact_Number_Emergency' => $sp('Contact_Number_Emergency'),
        'Educational_Background'   => $sp('Educational_Background'),
        'Notes'                    => $sp('Notes'),
        'Active'                   => 1,
        'Blacklisted'              => 0,
    ];

    $columns = implode(', ', array_map(fn($c) => "[{$c}]", array_keys($fields)));
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $values = array_values($fields);

    $insertSQL = "INSERT INTO [dbo].[TBL_HREmployeeList] ({$columns}) VALUES ({$placeholders})";

    // ── IDENTITY_INSERT wrap ───────────────────────────────────
    if (!sqlsrv_begin_transaction($conn)) {
        echo json_encode(['success' => false, 'message' => 'Transaction failed.']);
        exit;
    }

    try {
        sqlsrv_query($conn, "SET IDENTITY_INSERT [dbo].[TBL_HREmployeeList] ON");
        $stmt = sqlsrv_query($conn, $insertSQL, $values);

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            throw new Exception($errors[0]['message'] ?? 'Insert failed.');
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_query($conn, "SET IDENTITY_INSERT [dbo].[TBL_HREmployeeList] OFF");
        sqlsrv_commit($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Employee created successfully.',
            'fileNo'  => $nextFileNo,
        ]);

    } catch (Exception $e) {
        sqlsrv_query($conn, "SET IDENTITY_INSERT [dbo].[TBL_HREmployeeList] OFF");
        sqlsrv_rollback($conn);
        error_log('add-employee.php error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Employee · HR</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ══ Stepper ══════════════════════════════════════════════════ */
    .stepper-wrap {
      display: flex;
      align-items: center;
      gap: 0;
      margin-bottom: 2rem;
      padding: 1.25rem 1.75rem;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,.04);
    }
    .step-item {
      display: flex;
      align-items: center;
      gap: .6rem;
      flex: 1;
      position: relative;
    }
    .step-item:not(:last-child)::after {
      content: '';
      position: absolute;
      right: 0;
      top: 50%;
      transform: translateY(-50%);
      width: calc(100% - 2.4rem);
      left: 2.4rem;
      height: 2px;
      background: #e2e8f0;
      z-index: 0;
      transition: background .3s;
    }
    .step-item.done::after  { background: #3b82f6; }
    .step-item.active::after { background: #e2e8f0; }

    .step-circle {
      width: 32px; height: 32px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: .78rem; font-weight: 800;
      border: 2px solid #e2e8f0;
      background: #f8faff;
      color: #94a3b8;
      flex-shrink: 0;
      z-index: 1;
      transition: all .25s;
    }
    .step-item.active .step-circle {
      background: #3b82f6;
      border-color: #3b82f6;
      color: #fff;
      box-shadow: 0 0 0 4px rgba(59,130,246,.18);
    }
    .step-item.done .step-circle {
      background: #10b981;
      border-color: #10b981;
      color: #fff;
    }
    .step-label {
      font-size: .72rem;
      font-weight: 700;
      color: #94a3b8;
      white-space: nowrap;
      transition: color .25s;
    }
    .step-item.active .step-label { color: #3b82f6; }
    .step-item.done  .step-label  { color: #10b981; }

    /* ══ Form Card ════════════════════════════════════════════════ */
    .form-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,.04);
      overflow: hidden;
    }
    .form-card-header {
      padding: 1.1rem 1.75rem;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      gap: .65rem;
    }
    .form-card-header-icon {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: rgba(59,130,246,.1);
      display: flex; align-items: center; justify-content: center;
      color: #3b82f6;
      font-size: 1rem;
      flex-shrink: 0;
    }
    .form-card-title {
      font-size: .92rem;
      font-weight: 700;
      color: #0f172a;
    }
    .form-card-subtitle {
      font-size: .72rem;
      color: #94a3b8;
      margin-top: .05rem;
    }
    .form-card-body {
      padding: 1.5rem 1.75rem;
    }
    .form-step { display: none; }
    .form-step.active { display: block; }

    /* ══ Form Fields ══════════════════════════════════════════════ */
    .field-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.25rem; }
    .field-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem 1.25rem; }
    .field-full   { grid-column: 1 / -1; }

    .form-label {
      font-size: .72rem;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: .35rem;
      display: flex;
      align-items: center;
      gap: .25rem;
    }
    .form-label .req { color: #ef4444; font-size: .8rem; }
    .form-control, .form-select {
      font-size: .85rem;
      border-color: #e2e8f0;
      border-radius: 8px;
      padding: .45rem .7rem;
      transition: border-color .15s, box-shadow .15s;
      background: #f8faff;
    }
    .form-control:focus, .form-select:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59,130,246,.12);
      background: #fff;
    }
    .form-control.is-invalid { border-color: #ef4444; background: #fff5f5; }

    /* ══ Section divider ══════════════════════════════════════════ */
    .field-section-label {
      font-size: .68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .09em;
      color: #3b82f6;
      padding-left: .6rem;
      border-left: 3px solid #3b82f6;
      margin: 1.5rem 0 1rem;
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .field-section-label:first-child { margin-top: 0; }

    /* ══ Step Navigation ══════════════════════════════════════════ */
    .step-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1.1rem 1.75rem;
      border-top: 1px solid #f1f5f9;
      background: #f8faff;
    }
    .btn-step {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .5rem 1.1rem;
      border-radius: 8px;
      font-size: .83rem;
      font-weight: 700;
      cursor: pointer;
      transition: all .15s;
      border: none;
    }
    .btn-step-prev {
      background: transparent;
      color: #64748b;
      border: 1px solid #e2e8f0;
    }
    .btn-step-prev:hover { background: #f1f5f9; }
    .btn-step-next {
      background: #3b82f6;
      color: #fff;
      box-shadow: 0 2px 8px rgba(59,130,246,.3);
    }
    .btn-step-next:hover { background: #2563eb; }
    .btn-step-submit {
      background: #10b981;
      color: #fff;
      box-shadow: 0 2px 8px rgba(16,185,129,.3);
    }
    .btn-step-submit:hover { background: #059669; }
    .btn-step-submit:disabled { opacity: .6; cursor: not-allowed; }

    /* ══ Review Panel ═════════════════════════════════════════════ */
    .review-section { margin-bottom: 1.25rem; }
    .review-section-title {
      font-size: .68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .09em;
      color: #475569;
      margin-bottom: .6rem;
      padding-bottom: .4rem;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem .75rem; }
    .review-item label {
      font-size: .67rem;
      font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: .05em;
      display: block;
      margin-bottom: .1rem;
    }
    .review-item span {
      font-size: .82rem;
      font-weight: 500;
      color: #1e293b;
    }
    .review-item span.empty { color: #cbd5e1; font-style: italic; }

    /* ══ Success screen ═══════════════════════════════════════════ */
    #successScreen {
      display: none;
      text-align: center;
      padding: 3rem 2rem;
    }
    .success-icon-wrap {
      width: 72px; height: 72px;
      background: rgba(16,185,129,.12);
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1.25rem;
      animation: popIn .4s cubic-bezier(.175,.885,.32,1.275);
    }
    @keyframes popIn {
      from { transform: scale(0); opacity: 0; }
      to   { transform: scale(1); opacity: 1; }
    }
    .success-icon-wrap i { font-size: 2rem; color: #10b981; }
    .success-title { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-bottom: .4rem; }
    .success-sub   { font-size: .85rem; color: #64748b; margin-bottom: 1.5rem; }
    .fileno-badge  {
      display: inline-block;
      background: #f0fdf4;
      border: 1px solid #86efac;
      color: #15803d;
      font-size: .8rem;
      font-weight: 700;
      border-radius: 8px;
      padding: .35rem .75rem;
      margin-bottom: 1.5rem;
    }

    /* ══ Toast ════════════════════════════════════════════════════ */
    #toastWrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem;
      z-index: 9999; display: flex; flex-direction: column; gap: .5rem;
    }
    .toast-msg {
      padding: .65rem 1rem; border-radius: 10px;
      font-size: .82rem; font-weight: 600;
      display: flex; align-items: center; gap: .5rem;
      box-shadow: 0 4px 16px rgba(0,0,0,.12);
      animation: slideIn .2s ease;
    }
    @keyframes slideIn { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    .toast-success { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
    .toast-danger  { background: #fff5f5; color: #dc2626; border: 1px solid #fca5a5; }

    @media(max-width: 640px) {
      .field-grid, .field-grid-3 { grid-template-columns: 1fr; }
      .review-grid { grid-template-columns: 1fr; }
      .stepper-wrap { padding: 1rem; gap: 0; }
      .step-label { display: none; }
    }
  </style>
</head>
<body>

<?php $topbar_page = 'employees'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;">
    <div>
      <div class="page-title">Add New Employee</div>
      <div class="page-subtitle">
        <a href="employee-list.php" style="color:var(--text-muted);text-decoration:none;">
          <i class="bi bi-arrow-left" style="font-size:.75rem;"></i> Back to Employee List
        </a>
      </div>
    </div>
  </div>

  <!-- Hired Flash Banner -->
  <?php if ($hiredFlash): ?>
  <div style="
    display:flex;align-items:center;gap:.75rem;
    background:#f0fdf4;border:1px solid #86efac;border-radius:12px;
    padding:.85rem 1.25rem;margin-bottom:1.25rem;
    font-size:.85rem;color:#15803d;font-weight:600;">
    <i class="bi bi-award-fill" style="font-size:1.2rem;flex-shrink:0;"></i>
    <div>
      <div>Applicant marked as <strong>Hired</strong> successfully! 🎉</div>
      <div style="font-weight:400;font-size:.78rem;margin-top:.15rem;color:#166534;">
        The form below is pre-filled with the applicant's data. Please complete any missing fields before saving.
      </div>
    </div>
    <a href="view-applications.php?tab=hired" style="margin-left:auto;white-space:nowrap;font-size:.75rem;color:#15803d;text-decoration:underline;">
      <i class="bi bi-arrow-left"></i> Back to Applications
    </a>
  </div>
  <?php endif; ?>

  <!-- Stepper -->
  <div class="stepper-wrap" id="stepper">
    <div class="step-item active" data-step="1">
      <div class="step-circle">1</div>
      <span class="step-label">Basic Info</span>
    </div>
    <div class="step-item" data-step="2">
      <div class="step-circle">2</div>
      <span class="step-label">Employment</span>
    </div>
    <div class="step-item" data-step="3">
      <div class="step-circle">3</div>
      <span class="step-label">Personal</span>
    </div>
    <div class="step-item" data-step="4">
      <div class="step-circle">4</div>
      <span class="step-label">Government IDs</span>
    </div>
    <div class="step-item" data-step="5">
      <div class="step-circle">5</div>
      <span class="step-label">Contact</span>
    </div>
    <div class="step-item" data-step="6">
      <div class="step-circle"><i class="bi bi-check2" style="font-size:.85rem;"></i></div>
      <span class="step-label">Review</span>
    </div>
  </div>

  <!-- Form Card -->
  <div class="form-card">

    <!-- Success Screen (hidden until submit) -->
    <div id="successScreen">
      <div class="success-icon-wrap"><i class="bi bi-person-check-fill"></i></div>
      <div class="success-title">Employee Created!</div>
      <div class="success-sub">The new employee has been added to the system.</div>
      <div class="fileno-badge" id="successFileNo"></div>
      <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;">
        <a href="employee-list.php" class="btn btn-primary btn-sm">
          <i class="bi bi-people"></i> View Employee List
        </a>
        <?php if ($fromApp): ?>
        <a href="view-applications.php?tab=hired" class="btn btn-secondary btn-sm">
          <i class="bi bi-arrow-left"></i> Back to Applications
        </a>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm" id="btnAddAnother">
          <i class="bi bi-plus-circle"></i> Add Another
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Form -->
    <form id="addEmpForm" novalidate>
      <input type="hidden" name="_action" value="create_employee">
      <?php if ($fromApp && $prefill['app_id']): ?>
      <input type="hidden" name="from_app_id" value="<?= (int)$prefill['app_id'] ?>">
      <?php endif; ?>

      <!-- ══ STEP 1: Basic Info ══════════════════════════════════ -->
      <div class="form-step active" id="step1">
        <div class="form-card-header">
          <div class="form-card-header-icon"><i class="bi bi-person-fill"></i></div>
          <div>
            <div class="form-card-title">Basic Information</div>
            <div class="form-card-subtitle">Name and primary identifiers</div>
          </div>
        </div>
        <div class="form-card-body">

          <div class="field-section-label"><i class="bi bi-person-vcard"></i> Full Name</div>
          <div class="field-grid-3">
            <div>
              <label class="form-label">Last Name <span class="req">*</span></label>
              <input type="text" class="form-control" name="LastName" id="LastName" placeholder="Dela Cruz" value="<?= $pf('LastName') ?>" required>
              <div class="invalid-feedback">Last name is required.</div>
            </div>
            <div>
              <label class="form-label">First Name <span class="req">*</span></label>
              <input type="text" class="form-control" name="FirstName" id="FirstName" placeholder="Juan" value="<?= $pf('FirstName') ?>" required>
              <div class="invalid-feedback">First name is required.</div>
            </div>
            <div>
              <label class="form-label">Middle Name</label>
              <input type="text" class="form-control" name="MiddleName" placeholder="Santos" value="<?= $pf('MiddleName') ?>">
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-hash"></i> ID Numbers</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Employee ID</label>
              <input type="text" class="form-control" name="EmployeeID" placeholder="e.g. EMP-0001">
            </div>
            <div>
              <label class="form-label">Office ID</label>
              <input type="text" class="form-control" name="OfficeID" placeholder="e.g. OFF-0001">
            </div>
          </div>

        </div>
        <div class="step-nav">
          <span></span>
          <button type="button" class="btn-step btn-step-next" onclick="goNext(1)">
            Next <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ══ STEP 2: Employment ══════════════════════════════════ -->
      <div class="form-step" id="step2">
        <div class="form-card-header">
          <div class="form-card-header-icon"><i class="bi bi-briefcase-fill"></i></div>
          <div>
            <div class="form-card-title">Employment Details</div>
            <div class="form-card-subtitle">Department, position, and work information</div>
          </div>
        </div>
        <div class="form-card-body">

          <div class="field-section-label"><i class="bi bi-diagram-3"></i> Department & Position</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Department</label>
              <select class="form-select" name="Department">
                <option value="">— Select Department —</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= htmlspecialchars($d) ?>"
                    <?= ($prefill['Department'] ?? '') === $d ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">Position Held</label>
              <input type="text" class="form-control" name="Position_held" placeholder="e.g. Accounting Staff" value="<?= $pf('Position_held') ?>">
            </div>
            <div>
              <label class="form-label">Job Title</label>
              <input type="text" class="form-control" name="Job_tittle" placeholder="e.g. Junior Accountant">
            </div>
            <div>
              <label class="form-label">Category</label>
              <select class="form-select" name="Category">
                <option value="">— Select —</option>
                <option>Regular</option>
                <option>Probationary</option>
                <option>Contractual</option>
                <option>Part-time</option>
                <option>Project-based</option>
              </select>
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-building"></i> Work Setup</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Branch</label>
              <input type="text" class="form-control" name="Branch" placeholder="e.g. Lucena Main">
            </div>
            <div>
              <label class="form-label">System</label>
              <input type="text" class="form-control" name="System" placeholder="e.g. Payroll System A">
            </div>
            <div>
              <label class="form-label">Employee Status</label>
              <select class="form-select" name="Employee_Status">
                <option value="">— Select —</option>
                <option>Active</option>
                <option>On Leave</option>
                <option>Resigned</option>
                <option>Terminated</option>
                <option>Retired</option>
              </select>
            </div>
            <div>
              <label class="form-label">Cut-Off</label>
              <select class="form-select" name="CutOff">
                <option value="">— Select —</option>
                <option>1st Cut-Off</option>
                <option>2nd Cut-Off</option>
              </select>
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-calendar3"></i> Dates</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Hired Date</label>
              <input type="date" class="form-control" name="Hired_date">
            </div>
            <div>
              <label class="form-label">Date of Separation</label>
              <input type="date" class="form-control" name="Date_Of_Seperation">
            </div>
          </div>

        </div>
        <div class="step-nav">
          <button type="button" class="btn-step btn-step-prev" onclick="goPrev(2)">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-step btn-step-next" onclick="goNext(2)">
            Next <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ══ STEP 3: Personal ════════════════════════════════════ -->
      <div class="form-step" id="step3">
        <div class="form-card-header">
          <div class="form-card-header-icon"><i class="bi bi-person-lines-fill"></i></div>
          <div>
            <div class="form-card-title">Personal Information</div>
            <div class="form-card-subtitle">Demographics and background</div>
          </div>
        </div>
        <div class="form-card-body">

          <div class="field-section-label"><i class="bi bi-calendar-heart"></i> Demographics</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Birth Date</label>
              <input type="date" class="form-control" name="Birth_date">
            </div>
            <div>
              <label class="form-label">Birth Place</label>
              <input type="text" class="form-control" name="Birth_Place" placeholder="e.g. Lucena City, Quezon">
            </div>
            <div>
              <label class="form-label">Gender</label>
              <select class="form-select" name="Gender">
                <option value="">— Select —</option>
                <option>Male</option>
                <option>Female</option>
                <option>Other</option>
              </select>
            </div>
            <div>
              <label class="form-label">Civil Status</label>
              <select class="form-select" name="Civil_Status">
                <option value="">— Select —</option>
                <option>Single</option>
                <option>Married</option>
                <option>Widowed</option>
                <option>Separated</option>
                <option>Divorced</option>
              </select>
            </div>
            <div>
              <label class="form-label">Nationality</label>
              <input type="text" class="form-control" name="Nationality" placeholder="e.g. Filipino">
            </div>
            <div>
              <label class="form-label">Religion</label>
              <input type="text" class="form-control" name="Religion" placeholder="e.g. Roman Catholic">
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-mortarboard"></i> Education & Notes</div>
          <div class="field-grid">
            <div class="field-full">
              <label class="form-label">Educational Background</label>
              <textarea class="form-control" name="Educational_Background" rows="2"
                placeholder="e.g. BS Accountancy, University of the Philippines 2018"></textarea>
            </div>
            <div class="field-full">
              <label class="form-label">Notes</label>
              <textarea class="form-control" name="Notes" rows="2" placeholder="Optional remarks..."></textarea>
            </div>
          </div>

        </div>
        <div class="step-nav">
          <button type="button" class="btn-step btn-step-prev" onclick="goPrev(3)">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-step btn-step-next" onclick="goNext(3)">
            Next <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ══ STEP 4: Government IDs ══════════════════════════════ -->
      <div class="form-step" id="step4">
        <div class="form-card-header">
          <div class="form-card-header-icon"><i class="bi bi-card-text"></i></div>
          <div>
            <div class="form-card-title">Government ID Numbers</div>
            <div class="form-card-subtitle">SSS, TIN, PhilHealth, Pag-IBIG</div>
          </div>
        </div>
        <div class="form-card-body">

          <div class="field-grid">
            <div>
              <label class="form-label">SSS Number</label>
              <input type="text" class="form-control" name="SSS_Number" placeholder="XX-XXXXXXX-X">
            </div>
            <div>
              <label class="form-label">TIN Number</label>
              <input type="text" class="form-control" name="TIN_Number" placeholder="XXX-XXX-XXX-XXX">
            </div>
            <div>
              <label class="form-label">PhilHealth Number</label>
              <input type="text" class="form-control" name="Philhealth_Number" placeholder="XX-XXXXXXXXX-X">
            </div>
            <div>
              <label class="form-label">HDMF / Pag-IBIG</label>
              <input type="text" class="form-control" name="HDMF" placeholder="XXXX-XXXX-XXXX">
            </div>
          </div>

        </div>
        <div class="step-nav">
          <button type="button" class="btn-step btn-step-prev" onclick="goPrev(4)">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-step btn-step-next" onclick="goNext(4)">
            Next <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ══ STEP 5: Contact ════════════════════════════════════ -->
      <div class="form-step" id="step5">
        <div class="form-card-header">
          <div class="form-card-header-icon"><i class="bi bi-telephone-fill"></i></div>
          <div>
            <div class="form-card-title">Contact Information</div>
            <div class="form-card-subtitle">Phone, email, address, and emergency contact</div>
          </div>
        </div>
        <div class="form-card-body">

          <div class="field-section-label"><i class="bi bi-chat-dots"></i> Contact Details</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Mobile Number</label>
              <input type="text" class="form-control" name="Mobile_Number" placeholder="09XX-XXX-XXXX" value="<?= $pf('Mobile_Number') ?>">
            </div>
            <div>
              <label class="form-label">Phone Number</label>
              <input type="text" class="form-control" name="Phone_Number" placeholder="(042) XXX-XXXX">
            </div>
            <div class="field-full">
              <label class="form-label">Email Address</label>
              <input type="email" class="form-control" name="Email_Address" placeholder="juan.delacruz@email.com" value="<?= $pf('Email_Address') ?>">
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-geo-alt"></i> Address</div>
          <div class="field-grid">
            <div class="field-full">
              <label class="form-label">Present Address</label>
              <textarea class="form-control" name="Present_Address" rows="2"
                placeholder="House No., Street, Barangay, City, Province"></textarea>
            </div>
            <div class="field-full">
              <label class="form-label">Permanent Address</label>
              <textarea class="form-control" name="Permanent_Address" rows="2"
                placeholder="House No., Street, Barangay, City, Province"></textarea>
            </div>
          </div>

          <div class="field-section-label" style="margin-top:1.5rem;"><i class="bi bi-person-exclamation"></i> Emergency Contact</div>
          <div class="field-grid">
            <div>
              <label class="form-label">Contact Person</label>
              <input type="text" class="form-control" name="Contact_Person" placeholder="Full name">
            </div>
            <div>
              <label class="form-label">Relationship</label>
              <input type="text" class="form-control" name="Relationship" placeholder="e.g. Spouse, Parent">
            </div>
            <div>
              <label class="form-label">Emergency Contact Number</label>
              <input type="text" class="form-control" name="Contact_Number_Emergency" placeholder="09XX-XXX-XXXX">
            </div>
          </div>

        </div>
        <div class="step-nav">
          <button type="button" class="btn-step btn-step-prev" onclick="goPrev(5)">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-step btn-step-next" onclick="goNext(5)">
            Review <i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ══ STEP 6: Review ══════════════════════════════════════ -->
      <div class="form-step" id="step6">
        <div class="form-card-header">
          <div class="form-card-header-icon" style="background:rgba(16,185,129,.1);color:#10b981;"><i class="bi bi-clipboard2-check-fill"></i></div>
          <div>
            <div class="form-card-title">Review & Submit</div>
            <div class="form-card-subtitle">Double-check all information before saving</div>
          </div>
        </div>
        <div class="form-card-body" id="reviewBody">
          <!-- Populated by JS -->
        </div>
        <div class="step-nav">
          <button type="button" class="btn-step btn-step-prev" onclick="goPrev(6)">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="submit" class="btn-step btn-step-submit" id="btnSubmit">
            <i class="bi bi-person-plus-fill"></i> Create Employee
          </button>
        </div>
      </div>

    </form><!-- /addEmpForm -->
  </div><!-- /form-card -->

</div><!-- /main-wrapper -->

<div id="toastWrap"></div>

<script>
// ══ Stepper logic ══════════════════════════════════════════════
let currentStep = 1;
const totalSteps = 6;

function goNext(from) {
  if (!validateStep(from)) return;
  showStep(from + 1);
}
function goPrev(from) {
  showStep(from - 1);
}

function showStep(n) {
  document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
  document.getElementById('step' + n).classList.add('active');

  // Update stepper indicators
  document.querySelectorAll('.step-item').forEach((item, idx) => {
    const s = idx + 1;
    item.classList.remove('active', 'done');
    if (s < n)      item.classList.add('done');
    else if (s === n) item.classList.add('active');
    // Swap number for checkmark on done steps
    const circle = item.querySelector('.step-circle');
    if (s < n)      circle.innerHTML = '<i class="bi bi-check2" style="font-size:.85rem;"></i>';
    else if (s < 6) circle.textContent = s;
  });

  currentStep = n;
  if (n === 6) buildReview();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ══ Validation ═════════════════════════════════════════════════
function validateStep(step) {
  if (step === 1) {
    const ln = document.getElementById('LastName');
    const fn = document.getElementById('FirstName');
    let ok = true;
    [ln, fn].forEach(el => {
      if (!el.value.trim()) { el.classList.add('is-invalid'); ok = false; }
      else el.classList.remove('is-invalid');
    });
    if (!ok) { showToast('Last Name and First Name are required.', 'danger'); }
    return ok;
  }
  return true;
}

// ══ Review builder ═════════════════════════════════════════════
function buildReview() {
  const fd   = new FormData(document.getElementById('addEmpForm'));
  const data = Object.fromEntries(fd.entries());
  const v    = k => data[k] || '<span class="empty">—</span>';

  document.getElementById('reviewBody').innerHTML = `
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-person-fill"></i> Basic Info</div>
      <div class="review-grid">
        <div class="review-item"><label>Last Name</label><span>${v('LastName')}</span></div>
        <div class="review-item"><label>First Name</label><span>${v('FirstName')}</span></div>
        <div class="review-item"><label>Middle Name</label><span>${v('MiddleName')}</span></div>
        <div class="review-item"><label>Employee ID</label><span>${v('EmployeeID')}</span></div>
        <div class="review-item"><label>Office ID</label><span>${v('OfficeID')}</span></div>
      </div>
    </div>
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-briefcase-fill"></i> Employment</div>
      <div class="review-grid">
        <div class="review-item"><label>Department</label><span>${v('Department')}</span></div>
        <div class="review-item"><label>Position</label><span>${v('Position_held')}</span></div>
        <div class="review-item"><label>Job Title</label><span>${v('Job_tittle')}</span></div>
        <div class="review-item"><label>Category</label><span>${v('Category')}</span></div>
        <div class="review-item"><label>Branch</label><span>${v('Branch')}</span></div>
        <div class="review-item"><label>Employee Status</label><span>${v('Employee_Status')}</span></div>
        <div class="review-item"><label>Hired Date</label><span>${v('Hired_date')}</span></div>
        <div class="review-item"><label>Cut-Off</label><span>${v('CutOff')}</span></div>
      </div>
    </div>
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-person-lines-fill"></i> Personal</div>
      <div class="review-grid">
        <div class="review-item"><label>Birth Date</label><span>${v('Birth_date')}</span></div>
        <div class="review-item"><label>Birth Place</label><span>${v('Birth_Place')}</span></div>
        <div class="review-item"><label>Gender</label><span>${v('Gender')}</span></div>
        <div class="review-item"><label>Civil Status</label><span>${v('Civil_Status')}</span></div>
        <div class="review-item"><label>Nationality</label><span>${v('Nationality')}</span></div>
        <div class="review-item"><label>Religion</label><span>${v('Religion')}</span></div>
      </div>
    </div>
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-card-text"></i> Government IDs</div>
      <div class="review-grid">
        <div class="review-item"><label>SSS</label><span>${v('SSS_Number')}</span></div>
        <div class="review-item"><label>TIN</label><span>${v('TIN_Number')}</span></div>
        <div class="review-item"><label>PhilHealth</label><span>${v('Philhealth_Number')}</span></div>
        <div class="review-item"><label>HDMF / Pag-IBIG</label><span>${v('HDMF')}</span></div>
      </div>
    </div>
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-telephone-fill"></i> Contact</div>
      <div class="review-grid">
        <div class="review-item"><label>Mobile</label><span>${v('Mobile_Number')}</span></div>
        <div class="review-item"><label>Phone</label><span>${v('Phone_Number')}</span></div>
        <div class="review-item"><label>Email</label><span>${v('Email_Address')}</span></div>
        <div class="review-item"><label>Emergency Contact</label><span>${v('Contact_Person')}</span></div>
        <div class="review-item"><label>Relationship</label><span>${v('Relationship')}</span></div>
        <div class="review-item"><label>Emergency Number</label><span>${v('Contact_Number_Emergency')}</span></div>
      </div>
    </div>`;
}

// ══ Form submit ════════════════════════════════════════════════
document.getElementById('addEmpForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

  try {
    const fd  = new FormData(this);
    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const json = await res.json();

    if (json.success) {
      // Hide form, show success
      document.getElementById('addEmpForm').style.display = 'none';
      document.getElementById('stepper').style.display    = 'none';
      document.getElementById('successFileNo').textContent = '📋 File No: ' + json.fileNo;
      document.getElementById('successScreen').style.display = 'block';
    } else {
      showToast(json.message || 'Failed to create employee.', 'danger');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-person-plus-fill"></i> Create Employee';
    }
  } catch (err) {
    console.error(err);
    showToast('An unexpected error occurred.', 'danger');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-person-plus-fill"></i> Create Employee';
  }
});

// ══ Add another ════════════════════════════════════════════════
document.getElementById('btnAddAnother').addEventListener('click', () => {
  document.getElementById('addEmpForm').reset();
  document.getElementById('addEmpForm').style.display  = 'block';
  document.getElementById('stepper').style.display     = 'flex';
  document.getElementById('successScreen').style.display = 'none';
  showStep(1);
});

// ══ Toast ══════════════════════════════════════════════════════
function showToast(msg, type = 'success') {
  const wrap = document.getElementById('toastWrap');
  const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
  const el   = document.createElement('div');
  el.className = `toast-msg toast-${type}`;
  el.innerHTML = `<i class="bi ${icon}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}
</script>
</body>
</html>