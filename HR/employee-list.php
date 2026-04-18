<?php
// ══════════════════════════════════════════════════════════════
//  HR/employee-list.php  — REVAMPED
//  New features:
//    • Empty-field highlighting/warnings in detail modal
//    • Active status toggle (Admin only)
//    • Profile picture upload via avatar click
//    • Print employee list (full table)
//    • Export to Excel (client-side via SheetJS)
//  Preserved: all existing AJAX update, pagination, search,
//              dept filter, modal edit/save, print single record
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR' ]);

$_userType = $_SESSION['UserType'] ?? '';
$isAdmin   = in_array($_userType, ['Admin', 'Administrator']);

// ── FIX: Define $viewAll / $activeDept BEFORE the POST handler ─
$_sessionDept = trim($_SESSION['Department'] ?? '');
$viewAll      = ($isAdmin && $_sessionDept === '');
$activeDept   = $_sessionDept;

// ── buildWhere also needs to be available in the POST handler ──
function buildWhere(bool $viewAll, string $userDept, string $search, string $deptFilter, array &$params): string {
    $params = []; $where = "WHERE Active = 1";
    if (!$viewAll && $userDept !== '') { $where .= " AND LTRIM(RTRIM(Department)) LIKE ?"; $params[] = '%'.$userDept.'%'; }
    if ($viewAll && $deptFilter !== '') { $where .= " AND LTRIM(RTRIM(Department)) LIKE ?"; $params[] = '%'.$deptFilter.'%'; }
    if ($search !== '') {
        $sp = "%{$search}%";
        $where .= " AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ? OR Department LIKE ? OR Position_held LIKE ? OR Branch LIKE ?)";
        array_push($params, $sp,$sp,$sp,$sp,$sp,$sp);
    }
    return $where;
}

function serializeRow(array $row): array {
    $dateFields = ['Hired_date','Date_Of_Seperation','Birth_date'];
    foreach ($dateFields as $f) {
        if (isset($row[$f]) && $row[$f] instanceof DateTime) $row[$f] = $row[$f]->format('Y-m-d');
        elseif (isset($row[$f]) && is_string($row[$f]) && $row[$f]) $row[$f] = $row[$f];
        else $row[$f] = null;
    }
    return $row;
}

// ══════════════════════════════════════════════════════════════
//  INLINE AJAX — POST handler
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {

    // ── UPDATE employee fields ────────────────────────────────
    if ($_POST['_action'] === 'update') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }

        $fileNo = isset($_POST['FileNo']) && $_POST['FileNo'] !== '' ? (int)$_POST['FileNo'] : null;
        $empId  = isset($_POST['EmployeeID']) && $_POST['EmployeeID'] !== '' ? trim($_POST['EmployeeID']) : null;
        if (!$fileNo && !$empId) { echo json_encode(['success'=>false,'message'=>'Missing employee identifier.']); exit; }

        $sp_fn = fn(string $k) => isset($_POST[$k]) ? trim($_POST[$k]) : null;

        $stringFields = [
            'OfficeID','SSS_Number','TIN_Number','Philhealth_Number','HDMF',
            'LastName','FirstName','MiddleName',
            'Department','Position_held','Job_tittle','Category',
            'Branch','System','Employee_Status','CutOff',
            'Birth_Place','Civil_Status','Gender','Nationality','Religion',
            'Mobile_Number','Phone_Number','Email_Address',
            'Present_Address','Permanent_Address',
            'Contact_Person','Relationship','Contact_Number_Emergency',
            'Educational_Background','Notes',
        ];
        $dateFields = ['Hired_date','Date_Of_Seperation','Birth_date'];

        $setClauses = []; $queryParams = [];
        foreach ($stringFields as $field) {
            $v = $sp_fn($field); if ($v === null) continue;
            $setClauses[] = "[{$field}] = ?"; $queryParams[] = ($v === '') ? null : $v;
        }
        foreach ($dateFields as $field) {
            $v = $sp_fn($field); if ($v === null) continue;
            $setClauses[] = "[{$field}] = ?";
            $queryParams[] = ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        }

        if (empty($setClauses)) { echo json_encode(['success'=>false,'message'=>'Nothing to update.']); exit; }

        if ($fileNo) { $queryParams[] = $fileNo; $whereSql = "WHERE FileNo = ?"; }
        else         { $queryParams[] = $empId;   $whereSql = "WHERE EmployeeID = ?"; }

        $updateSql  = "UPDATE [dbo].[TBL_HREmployeeList] SET ".implode(', ',$setClauses)." {$whereSql}";
        $updateStmt = sqlsrv_query($conn, $updateSql, $queryParams);
        if ($updateStmt === false) {
            $errors = sqlsrv_errors();
            echo json_encode(['success'=>false,'message'=>$errors[0]['message'] ?? 'Update failed.']);
        } else {
            sqlsrv_free_stmt($updateStmt);
            echo json_encode(['success'=>true,'message'=>'Employee record updated successfully.']);
        }
        exit;
    }

    // ── TOGGLE active status ──────────────────────────────────
    if ($_POST['_action'] === 'toggle_active') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }

        $fileNo    = isset($_POST['FileNo']) ? (int)$_POST['FileNo'] : 0;
        $newActive = isset($_POST['Active']) ? (int)$_POST['Active'] : 0;
        if (!$fileNo) { echo json_encode(['success'=>false,'message'=>'Missing FileNo.']); exit; }

        $sql  = "UPDATE [dbo].[TBL_HREmployeeList] SET Active = ? WHERE FileNo = ?";
        $stmt = sqlsrv_query($conn, $sql, [$newActive, $fileNo]);
        if ($stmt === false) {
            $e = sqlsrv_errors();
            echo json_encode(['success'=>false,'message'=>$e[0]['message'] ?? 'Update failed.']);
        } else {
            sqlsrv_free_stmt($stmt);
            echo json_encode(['success'=>true,'newActive'=>$newActive]);
        }
        exit;
    }

    // ── UPLOAD profile picture ────────────────────────────────
    if ($_POST['_action'] === 'upload_picture') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Unauthorized.']); exit; }

        $fileNo = isset($_POST['FileNo']) ? (int)$_POST['FileNo'] : 0;
        if (!$fileNo || empty($_FILES['picture'])) {
            echo json_encode(['success'=>false,'message'=>'Missing data.']); exit;
        }

        $file    = $_FILES['picture'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['success'=>false,'message'=>'Invalid file type.']); exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success'=>false,'message'=>'File too large (max 5MB).']); exit;
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'emp_' . $fileNo . '_' . time() . '.' . $ext;
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/tradewellportal/uploads/employee_pics/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $destPath = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success'=>false,'message'=>'Upload failed.']); exit;
        }

        $relPath = 'uploads/employee_pics/' . $filename;
        $sql  = "UPDATE [dbo].[TBL_HREmployeeList] SET Picture = ? WHERE FileNo = ?";
        $stmt = sqlsrv_query($conn, $sql, [$relPath, $fileNo]);
        if ($stmt === false) {
            echo json_encode(['success'=>false,'message'=>'DB update failed.']); exit;
        }
        sqlsrv_free_stmt($stmt);
        echo json_encode(['success'=>true,'picturePath'=>'/TWM/tradewellportal/'.$relPath]);
        exit;
    }

    // ── EXPORT all employees ──────────────────────────────────
    if ($_POST['_action'] === 'export_data') {
        header('Content-Type: application/json');

        $exportSearch = trim($_POST['search'] ?? '');
        $exportDept   = trim($_POST['dept']   ?? '');

        $exportParams = [];
        $exportWhere  = buildWhere($viewAll, $activeDept, $exportSearch, $exportDept, $exportParams);

        $exportSql = "
            SELECT FileNo, EmployeeID, LastName, FirstName, MiddleName,
                Department, Position_held, Job_tittle, Category, Branch, System,
                Employee_Status,
                CONVERT(varchar(10), Hired_date, 23)           AS Hired_date,
                CONVERT(varchar(10), Date_Of_Seperation, 23)   AS Date_Of_Seperation,
                SSS_Number, TIN_Number, Philhealth_Number, HDMF,
                Mobile_Number, Phone_Number, Email_Address,
                Present_Address, Permanent_Address,
                CONVERT(varchar(10), Birth_date, 23)           AS Birth_date,
                Birth_Place, Gender, Civil_Status, Nationality, Religion,
                Contact_Person, Relationship, Contact_Number_Emergency,
                Educational_Background, Notes, Active, Blacklisted
            FROM [dbo].[TBL_HREmployeeList]
            {$exportWhere}
            ORDER BY LastName, FirstName";

        $exportStmt = sqlsrv_query($conn, $exportSql, $exportParams);
        $rows = [];
        if ($exportStmt) {
            while ($r = sqlsrv_fetch_array($exportStmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = serializeRow($r);
            }
            sqlsrv_free_stmt($exportStmt);
        }

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

}

// ── Pagination & filters ───────────────────────────────────────
$perPage    = 20;
$page       = max(1, (int)($_GET['page']    ?? 1));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search']           ?? '');
$deptFilter = trim($_GET['dept']             ?? '');

// NOTE: $viewAll, $activeDept, buildWhere(), serializeRow() are already defined above

$params = []; $where = buildWhere($viewAll, $activeDept, $search, $deptFilter, $params);

$countSql  = "SELECT COUNT(*) AS total FROM [dbo].[TBL_HREmployeeList] {$where}";
$countStmt = sqlsrv_query($conn, $countSql, $params);
$totalRows = 0;
if ($countStmt) { $cr = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC); $totalRows = (int)($cr['total'] ?? 0); sqlsrv_free_stmt($countStmt); }
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$sql = "
    SELECT FileNo, EmployeeID, EmployeeID1, OfficeID,
        Department, Position_held, Job_tittle, Category,
        CONVERT(varchar(10), Hired_date, 23) AS Hired_date,
        CONVERT(varchar(10), Date_Of_Seperation, 23) AS Date_Of_Seperation, Employee_Status,
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
    FROM [dbo].[TBL_HREmployeeList] {$where}
    ORDER BY LastName, FirstName
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY";

$fetchParams = array_merge($params, [$offset, $perPage]);
$stmt        = sqlsrv_query($conn, $sql, $fetchParams);
$employees   = [];

if ($stmt) { while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $employees[] = serializeRow($r); sqlsrv_free_stmt($stmt); }

$deptStmt = sqlsrv_query($conn,"SELECT DepartmentName FROM [dbo].[Departments] WHERE Status = 1 ORDER BY DepartmentName");
$departments = [];
if ($deptStmt) { while ($dr = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) $departments[] = $dr['DepartmentName']; sqlsrv_free_stmt($deptStmt); }

function fmtDate($d): string { if ($d instanceof DateTime) return $d->format('M j, Y'); if (is_string($d) && $d) return date('M j, Y', strtotime($d)); return '—'; }
function initials(string $first, string $last): string { return strtoupper(substr($first,0,1).substr($last,0,1)); }
function avatarColor(string $name): string { $c=['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316']; return $c[abs(crc32($name))%count($c)]; }

$paginationParams = ['page'=>1,'search'=>$search];
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
  <!-- SheetJS for Excel export -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <style>
    /* ══ Employee Table ══════════════════════════════════════════ */
    .emp-avatar {
      width:38px;height:38px;border-radius:50%;
      display:inline-flex;align-items:center;justify-content:center;
      font-size:.78rem;font-weight:800;color:#fff;flex-shrink:0;
      object-fit:cover;border:2px solid rgba(255,255,255,.6);
      box-shadow:0 2px 6px rgba(0,0,0,.15);
    }
    .emp-name-wrap{display:flex;align-items:center;gap:.65rem;}
    .emp-name{font-weight:700;font-size:.88rem;color:var(--text-primary);line-height:1.2;}
    .emp-sub{font-size:.72rem;color:var(--text-muted);margin-top:.1rem;}
    .status-active{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .55rem;border-radius:999px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:rgba(16,185,129,.12);color:#059669;border:1px solid rgba(16,185,129,.35);}
    .status-inactive{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .55rem;border-radius:999px;font-size:.68rem;font-weight:700;text-transform:uppercase;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.3);}
    .status-dot{width:6px;height:6px;border-radius:50%;display:inline-block;}
    .blacklisted-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .45rem;border-radius:999px;font-size:.65rem;font-weight:700;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.3);margin-left:.3rem;}
    .emp-row{cursor:pointer;transition:background .12s;}
    .emp-row:hover td{background:rgba(67,128,226,.04);}

    /* ══ Detail Modal ════════════════════════════════════════════ */
    .detail-modal .modal-content{border-radius:16px;border:none;box-shadow:0 24px 80px rgba(0,0,0,.2);}
    .detail-modal .modal-header{background:var(--bs-body-bg,#fff);border-bottom:1px solid #e2e8f0;border-radius:16px 16px 0 0;padding:1rem 1.5rem;}
    .detail-modal .modal-title{font-weight:700;color:#0f172a;font-size:1rem;}
    .detail-modal .btn-close{filter:none;opacity:.6;}
    .modal-avatar-wrap{display:flex;align-items:center;gap:1rem;padding:1.25rem 1.5rem;border-bottom:1px solid #f1f5f9;}
    .modal-avatar{width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0;flex-shrink:0;}
    .modal-avatar-initials{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:#fff;flex-shrink:0;}

    /* Avatar upload overlay */
    .avatar-upload-wrap{position:relative;flex-shrink:0;cursor:pointer;}
    .avatar-upload-wrap .avatar-overlay{
      position:absolute;inset:0;border-radius:50%;background:rgba(0,0,0,.45);
      display:flex;align-items:center;justify-content:center;
      opacity:0;transition:opacity .2s;flex-direction:column;gap:2px;
    }
    .avatar-upload-wrap:hover .avatar-overlay{opacity:1;}
    .avatar-overlay i{color:#fff;font-size:1rem;}
    .avatar-overlay span{color:#fff;font-size:.6rem;font-weight:700;text-align:center;line-height:1.2;}
    #avatarFileInput{display:none;}

    .modal-emp-name{font-size:1.1rem;font-weight:800;color:#0f172a;}
    .modal-emp-role{font-size:.82rem;color:#64748b;margin-top:.15rem;}
    .detail-section{padding:1rem 1.5rem;border-bottom:1px solid #f1f5f9;}
    .detail-section:last-child{border-bottom:none;}
    .detail-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#475569;margin-bottom:.75rem;padding-left:.6rem;border-left:3px solid #3b82f6;display:flex;align-items:center;gap:.4rem;}
    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem .75rem;}
    .detail-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem .75rem;}
    .detail-item label{font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.15rem;}
    .detail-item .d-val{font-size:.83rem;font-weight:500;color:#1e293b;display:block;word-break:break-word;}
    .detail-item .d-val.empty{color:#cbd5e1;font-style:italic;}

    /* ── Empty field warning ── */
    .detail-item.field-empty label{color:#f59e0b;}
    .detail-item.field-empty .d-val.empty{
      color:#f59e0b;font-style:normal;font-weight:600;
      display:inline-flex;align-items:center;gap:.25rem;
    }
    .detail-item.field-empty .d-val.empty::before{
      content:'\F33A';font-family:'Bootstrap-Icons';font-size:.7rem;
    }
    .missing-count-badge{
      display:inline-flex;align-items:center;gap:.3rem;
      background:#fef3c7;color:#d97706;border:1px solid #fde68a;
      border-radius:999px;padding:.2rem .6rem;font-size:.72rem;font-weight:700;
    }
    .missing-count-badge i{font-size:.65rem;}

    /* ── Edit mode ── */
    .detail-item .d-input{display:none;}
    .edit-mode .detail-item .d-val{display:none !important;}
    .edit-mode .detail-item .d-input{display:block;width:100%;padding:.3rem .5rem;border:1px solid #c7d2fe;border-radius:6px;font-size:.83rem;background:#f8faff;transition:border-color .15s,box-shadow .15s;}
    .edit-mode .detail-item .d-input:focus{outline:none;border-color:#4380e2;box-shadow:0 0 0 3px rgba(67,128,226,.15);}
    .detail-item .d-input[readonly]{background:#f1f5f9 !important;color:#94a3b8;cursor:not-allowed;}
    /* Highlight empty inputs in edit mode */
    .edit-mode .detail-item.field-empty .d-input:not([readonly]){border-color:#f59e0b;background:#fffbeb;}

    #editModeBanner{display:none;background:#eff6ff;border-bottom:1px solid #bfdbfe;padding:.5rem 1.5rem;font-size:.78rem;color:#1d4ed8;align-items:center;gap:.4rem;}
    #editModeBanner.visible{display:flex;}

    /* ── Active status toggle ── */
    .active-toggle-wrap{display:flex;align-items:center;gap:.5rem;}
    .toggle-switch{position:relative;display:inline-block;width:42px;height:22px;}
    .toggle-switch input{opacity:0;width:0;height:0;}
    .toggle-slider{position:absolute;cursor:pointer;inset:0;background:#cbd5e1;border-radius:22px;transition:.25s;}
    .toggle-slider:before{content:'';position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
    input:checked + .toggle-slider{background:#10b981;}
    input:checked + .toggle-slider:before{transform:translateX(20px);}
    .toggle-label{font-size:.78rem;font-weight:700;color:#475569;}

    @media(max-width:576px){.detail-grid,.detail-grid-3{grid-template-columns:1fr;}}

    /* ══ Print: Single Record ════════════════════════════════════ */
    @media print {
      body > *:not(#printArea):not(#printListArea){display:none !important;}
      #printArea{display:block !important;font-family:'Segoe UI',sans-serif;color:#0f172a;padding:0;margin:0;}
      .print-header{display:flex;align-items:center;gap:1rem;padding:1rem 1.5rem;border-bottom:3px solid #1e40af;margin-bottom:1rem;}
      .print-avatar{width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid #e2e8f0;}
      .print-avatar-initials{width:70px;height:70px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;color:#fff;}
      .print-name{font-size:1.2rem;font-weight:800;}
      .print-role{font-size:.85rem;color:#475569;}
      .print-section{margin-bottom:1rem;padding:.75rem 1.5rem;page-break-inside:avoid;}
      .print-section-title{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#1e40af;border-bottom:1px solid #e2e8f0;padding-bottom:.3rem;margin-bottom:.6rem;}
      .print-grid{display:grid;grid-template-columns:1fr 1fr;gap:.3rem .75rem;}
      .print-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.3rem .75rem;}
      .print-item label{font-size:.65rem;font-weight:700;color:#94a3b8;display:block;text-transform:uppercase;}
      .print-item span{font-size:.82rem;color:#0f172a;display:block;}
      .print-footer{text-align:right;font-size:.65rem;color:#94a3b8;padding:0 1.5rem;margin-top:1.5rem;}
      /* Print employee list table */
      #printListArea{display:block !important;}
      #printListArea table{width:100%;border-collapse:collapse;font-size:.72rem;}
      #printListArea th{background:#1e40af;color:#fff;padding:.4rem .5rem;text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;}
      #printListArea td{padding:.35rem .5rem;border-bottom:1px solid #e2e8f0;vertical-align:top;}
      #printListArea tr:nth-child(even) td{background:#f8faff;}
      .print-list-header{padding:1rem 0 .75rem;border-bottom:3px solid #1e40af;margin-bottom:.75rem;display:flex;justify-content:space-between;align-items:flex-end;}
      .print-list-header h2{font-size:1.1rem;font-weight:800;color:#0f172a;margin:0;}
      .print-list-header p{font-size:.72rem;color:#64748b;margin:0;}
    }
    #printArea,#printListArea{display:none;}

    /* ══ Toolbar action buttons ══════════════════════════════════ */
    .action-toolbar{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
    .btn-export{background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.3);font-weight:600;}
    .btn-export:hover{background:rgba(16,185,129,.2);color:#059669;}
    .btn-print-list{background:rgba(67,128,226,.1);color:#2563eb;border:1px solid rgba(67,128,226,.3);font-weight:600;}
    .btn-print-list:hover{background:rgba(67,128,226,.2);color:#2563eb;}

    /* Missing fields panel in modal */
    #missingFieldsPanel{
      margin:.75rem 1.5rem;padding:.65rem .85rem;
      background:#fffbeb;border:1px solid #fde68a;border-radius:10px;
      font-size:.78rem;color:#92400e;
      display:none;
    }
    #missingFieldsPanel.has-missing{display:block;}
    #missingFieldsPanel ul{margin:.3rem 0 0;padding-left:1.2rem;}
    #missingFieldsPanel li{line-height:1.8;}
  </style>
</head>
<body>

<?php $topbar_page = 'employees'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

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
    <div class="action-toolbar">
      <button class="btn btn-sm btn-print-list" id="btnPrintList">
        <i class="bi bi-printer"></i> Print List
      </button>
      <button class="btn btn-sm btn-export" id="btnExportExcel">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </button>
      <a href="employee-inactive.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-person-dash"></i> Inactive
      </a>
      <a href="employee-blacklist.php" class="btn btn-sm" style="background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.3);font-weight:600;">
        <i class="bi bi-slash-circle"></i> Blacklisted
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
            <option value="<?= htmlspecialchars($d) ?>" <?= $deptFilter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
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
      <table class="apps-table" id="employeeTable">
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
            $fullName = trim(($emp['FirstName']??'').' '.($emp['LastName']??''));
            $initials = initials($emp['FirstName']??'',$emp['LastName']??'');
            $bgColor  = avatarColor($fullName);
            $isActive = (int)($emp['Active']??0) === 1;
            $isBlack  = (int)($emp['Blacklisted']??0) === 1;
            $picPath  = trim($emp['Picture']??'');
            if ($picPath && !str_starts_with($picPath,'/')) $picPath = '/TWM/tradewellportal/'.$picPath;
            $hasPic   = !empty($picPath);
            $empJson  = json_encode($emp, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        ?>
          <tr class="emp-row" data-emp='<?= $empJson ?: "{}" ?>'
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
                    <?= htmlspecialchars($emp['LastName']??'') ?>, <?= htmlspecialchars($emp['FirstName']??'') ?>
                    <?php if ($isBlack): ?><span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span><?php endif; ?>
                  </div>
                  <div class="emp-sub"><?= htmlspecialchars($emp['MiddleName']??'') ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['EmployeeID']??'—') ?></div>
              <div class="emp-sub">File: <?= htmlspecialchars($emp['FileNo']??'—') ?></div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['Department']??'—') ?></div>
              <div class="emp-sub"><?= htmlspecialchars($emp['Position_held']??'—') ?></div>
            </td>
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($emp['Branch']??'—') ?></div>
              <div class="emp-sub"><?= htmlspecialchars($emp['Category']??'—') ?></div>
            </td>
            <td>
              <?php if (!empty($emp['Email_Address'])): ?>
                <div><a href="mailto:<?= htmlspecialchars($emp['Email_Address']) ?>" class="text-link" style="font-size:.78rem;"><i class="bi bi-envelope" style="font-size:.7rem;"></i> <?= htmlspecialchars($emp['Email_Address']) ?></a></div>
              <?php endif; ?>
              <?php $phone = $emp['Mobile_Number'] ?: ($emp['Phone_Number']??''); ?>
              <?php if ($phone): ?><div class="emp-sub"><i class="bi bi-telephone" style="font-size:.65rem;"></i> <?= htmlspecialchars($phone) ?></div><?php endif; ?>
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
          <tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i><p>No employees found.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
      $urlBase = fn($p) => '?'.http_build_query(array_merge($paginationParams,['page'=>$p]));
    ?>
    <nav class="pagination-wrap d-flex justify-content-between align-items-center">
      <a class="page-btn <?= $page<=1?'disabled':'' ?>" href="<?= $page>1?$urlBase($page-1):'#' ?>"><i class="bi bi-chevron-left"></i> Prev</a>
      <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
      <a class="page-btn <?= $page>=$totalPages?'disabled':'' ?>" href="<?= $page<$totalPages?$urlBase($page+1):'#' ?>">Next <i class="bi bi-chevron-right"></i></a>
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
      <div id="editModeBanner"><i class="bi bi-pencil-square"></i><strong>Edit Mode</strong>&nbsp;— Make changes below, then click Save.</div>

      <!-- Avatar + name strip -->
      <div class="modal-avatar-wrap" id="modalAvatarWrap">

        <!-- Avatar with upload overlay -->
        <div class="avatar-upload-wrap" id="avatarUploadWrap" title="Click to upload photo">
          <div id="modalAvatarEl"></div>
          <div class="avatar-overlay">
            <i class="bi bi-camera"></i>
            <span>Change<br>Photo</span>
          </div>
        </div>
        <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/gif,image/webp">

        <div style="flex:1;">
          <div class="modal-emp-name" id="modalEmpName">—</div>
          <div class="modal-emp-role" id="modalEmpRole">—</div>
          <div style="margin-top:.5rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;" id="modalEmpBadges"></div>
          <?php if ($isAdmin): ?>
          <div class="active-toggle-wrap mt-2" id="activeToggleWrap">
            <label class="toggle-switch" title="Toggle active status">
              <input type="checkbox" id="activeToggle">
              <span class="toggle-slider"></span>
            </label>
            <span class="toggle-label" id="activeToggleLabel">Active</span>
            <span id="activeToggleSpinner" class="spinner-border spinner-border-sm text-success" style="display:none;"></span>
          </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- Missing fields summary panel -->
      <div id="missingFieldsPanel">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Incomplete Record</strong>
        — The following fields are empty:
        <ul id="missingFieldsList"></ul>
      </div>

      <div class="modal-body" id="modalBody" style="padding:0;">

        <!-- IDs -->
        <div class="detail-section">
          <div class="detail-section-title">
            <i class="bi bi-fingerprint"></i> Identification
            <span id="idMissingBadge" class="missing-count-badge ms-auto" style="display:none;"><i class="bi bi-exclamation-triangle-fill"></i> <span></span> empty</span>
          </div>
          <div class="detail-grid-3">
            <div class="detail-item"><label>Employee ID</label><span class="d-val" id="d-EmployeeID">—</span><input class="d-input" id="e-EmployeeID" data-field="EmployeeID" readonly></div>
            <div class="detail-item"><label>File No</label><span class="d-val" id="d-FileNo">—</span><input class="d-input" id="e-FileNo" data-field="FileNo" readonly></div>
            <div class="detail-item"><label>Office ID</label><span class="d-val" id="d-OfficeID">—</span><input class="d-input" id="e-OfficeID" data-field="OfficeID"></div>
            <div class="detail-item"><label>SSS Number</label><span class="d-val" id="d-SSS_Number">—</span><input class="d-input" id="e-SSS_Number" data-field="SSS_Number"></div>
            <div class="detail-item"><label>TIN Number</label><span class="d-val" id="d-TIN_Number">—</span><input class="d-input" id="e-TIN_Number" data-field="TIN_Number"></div>
            <div class="detail-item"><label>PhilHealth</label><span class="d-val" id="d-Philhealth_Number">—</span><input class="d-input" id="e-Philhealth_Number" data-field="Philhealth_Number"></div>
            <div class="detail-item"><label>HDMF / Pag-IBIG</label><span class="d-val" id="d-HDMF">—</span><input class="d-input" id="e-HDMF" data-field="HDMF"></div>
          </div>
        </div>

        <!-- Name -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person-vcard"></i> Full Name</div>
          <div class="detail-grid-3">
            <div class="detail-item"><label>Last Name</label><span class="d-val" id="d-LastName">—</span><input class="d-input" id="e-LastName" data-field="LastName"></div>
            <div class="detail-item"><label>First Name</label><span class="d-val" id="d-FirstName">—</span><input class="d-input" id="e-FirstName" data-field="FirstName"></div>
            <div class="detail-item"><label>Middle Name</label><span class="d-val" id="d-MiddleName">—</span><input class="d-input" id="e-MiddleName" data-field="MiddleName"></div>
          </div>
        </div>

        <!-- Work info -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-briefcase"></i> Work Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Department</label><span class="d-val" id="d-Department">—</span><input class="d-input" id="e-Department" data-field="Department"></div>
            <div class="detail-item"><label>Position</label><span class="d-val" id="d-Position_held">—</span><input class="d-input" id="e-Position_held" data-field="Position_held"></div>
            <div class="detail-item"><label>Job Title</label><span class="d-val" id="d-Job_tittle">—</span><input class="d-input" id="e-Job_tittle" data-field="Job_tittle"></div>
            <div class="detail-item"><label>Category</label><span class="d-val" id="d-Category">—</span><input class="d-input" id="e-Category" data-field="Category"></div>
            <div class="detail-item"><label>Branch</label><span class="d-val" id="d-Branch">—</span><input class="d-input" id="e-Branch" data-field="Branch"></div>
            <div class="detail-item"><label>System</label><span class="d-val" id="d-System">—</span><input class="d-input" id="e-System" data-field="System"></div>
            <div class="detail-item"><label>Hired Date</label><span class="d-val" id="d-Hired_date">—</span><input class="d-input" id="e-Hired_date" data-field="Hired_date" type="date"></div>
            <div class="detail-item"><label>Separation Date</label><span class="d-val" id="d-Date_Of_Seperation">—</span><input class="d-input" id="e-Date_Of_Seperation" data-field="Date_Of_Seperation" type="date"></div>
            <div class="detail-item"><label>Employee Status</label><span class="d-val" id="d-Employee_Status">—</span><input class="d-input" id="e-Employee_Status" data-field="Employee_Status"></div>
            <div class="detail-item"><label>Cut-Off</label><span class="d-val" id="d-CutOff">—</span><input class="d-input" id="e-CutOff" data-field="CutOff"></div>
          </div>
        </div>

        <!-- Personal -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person"></i> Personal Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Birth Date</label><span class="d-val" id="d-Birth_date">—</span><input class="d-input" id="e-Birth_date" data-field="Birth_date" type="date"></div>
            <div class="detail-item"><label>Birth Place</label><span class="d-val" id="d-Birth_Place">—</span><input class="d-input" id="e-Birth_Place" data-field="Birth_Place"></div>
            <div class="detail-item"><label>Gender</label><span class="d-val" id="d-Gender">—</span>
              <select class="d-input" id="e-Gender" data-field="Gender"><option value="">— Select —</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select>
            </div>
            <div class="detail-item"><label>Civil Status</label><span class="d-val" id="d-Civil_Status">—</span>
              <select class="d-input" id="e-Civil_Status" data-field="Civil_Status"><option value="">— Select —</option><option value="Single">Single</option><option value="Married">Married</option><option value="Widowed">Widowed</option><option value="Separated">Separated</option><option value="Divorced">Divorced</option></select>
            </div>
            <div class="detail-item"><label>Nationality</label><span class="d-val" id="d-Nationality">—</span><input class="d-input" id="e-Nationality" data-field="Nationality"></div>
            <div class="detail-item"><label>Religion</label><span class="d-val" id="d-Religion">—</span><input class="d-input" id="e-Religion" data-field="Religion"></div>
          </div>
        </div>

        <!-- Contact -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Mobile</label><span class="d-val" id="d-Mobile_Number">—</span><input class="d-input" id="e-Mobile_Number" data-field="Mobile_Number"></div>
            <div class="detail-item"><label>Phone</label><span class="d-val" id="d-Phone_Number">—</span><input class="d-input" id="e-Phone_Number" data-field="Phone_Number"></div>
            <div class="detail-item"><label>Email</label><span class="d-val" id="d-Email_Address">—</span><input class="d-input" id="e-Email_Address" data-field="Email_Address" type="email"></div>
            <div class="detail-item"><label>Present Address</label><span class="d-val" id="d-Present_Address">—</span><input class="d-input" id="e-Present_Address" data-field="Present_Address"></div>
            <div class="detail-item"><label>Permanent Address</label><span class="d-val" id="d-Permanent_Address">—</span><input class="d-input" id="e-Permanent_Address" data-field="Permanent_Address"></div>
          </div>
        </div>

        <!-- Emergency -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-heart-pulse"></i> Emergency Contact</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Contact Person</label><span class="d-val" id="d-Contact_Person">—</span><input class="d-input" id="e-Contact_Person" data-field="Contact_Person"></div>
            <div class="detail-item"><label>Relationship</label><span class="d-val" id="d-Relationship">—</span><input class="d-input" id="e-Relationship" data-field="Relationship"></div>
            <div class="detail-item"><label>Contact Number</label><span class="d-val" id="d-Contact_Number_Emergency">—</span><input class="d-input" id="e-Contact_Number_Emergency" data-field="Contact_Number_Emergency"></div>
          </div>
        </div>

        <!-- Education & Notes -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-book"></i> Education &amp; Notes</div>
          <div class="detail-grid">
            <div class="detail-item"><label>Educational Background</label><span class="d-val" id="d-Educational_Background">—</span><input class="d-input" id="e-Educational_Background" data-field="Educational_Background"></div>
            <div class="detail-item"><label>Notes</label><span class="d-val" id="d-Notes">—</span><textarea class="d-input" id="e-Notes" data-field="Notes" rows="2"></textarea></div>
          </div>
        </div>

      </div><!-- /modal-body -->

      <div class="modal-footer" style="gap:.5rem;">
        <div id="viewButtons" style="display:flex;gap:.5rem;width:100%;justify-content:flex-end;">
          <button type="button" id="btnPrint" class="btn btn-sm btn-secondary"><i class="bi bi-printer"></i> Print / PDF</button>
          <?php if ($isAdmin): ?>
          <button type="button" id="btnEdit" class="btn btn-sm btn-primary"><i class="bi bi-pencil-square"></i> Edit</button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
        <div id="editButtons" style="display:none;gap:.5rem;width:100%;justify-content:flex-end;">
          <button type="button" id="btnSave" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Save Changes</button>
          <button type="button" id="btnCancelEdit" class="btn btn-sm btn-secondary"><i class="bi bi-x-lg"></i> Cancel</button>
        </div>
      </div>

    </div>
  </div>
</div>


<!-- ══ PICTURE PREVIEW MODAL — outside empDetailModal ══ -->
<div class="modal fade" id="picPreviewModal" tabindex="-1" aria-hidden="true" style="z-index:1060;">
  <div class="modal-dialog modal-dialog-centered" style="max-width:360px;">
    <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.25);">
      <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:.9rem 1.25rem;">
        <h6 class="modal-title fw-bold" style="color:#0f172a;font-size:.9rem;">
          <i class="bi bi-camera me-2"></i>Update Profile Photo
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:1.5rem;text-align:center;">
        <div style="position:relative;display:inline-block;margin-bottom:1rem;">
          <img id="picPreviewImg" src="" alt="Preview"
            style="width:120px;height:120px;border-radius:50%;object-fit:cover;
                   border:4px solid #e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,.12);">
        </div>
        <div style="font-size:.78rem;color:#64748b;margin-bottom:.25rem;" id="picPreviewName">—</div>
        <div style="font-size:.72rem;color:#94a3b8;" id="picPreviewSize">—</div>
      </div>
      <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:.85rem 1.25rem;gap:.5rem;justify-content:space-between;">
        <button type="button" id="btnPickDifferent" class="btn btn-sm btn-secondary">
          <i class="bi bi-arrow-left"></i> Pick Different
        </button>
        <button type="button" id="btnConfirmUpload" class="btn btn-sm btn-primary">
          <i class="bi bi-cloud-upload"></i> Upload Photo
          <span id="uploadSpinner" class="spinner-border spinner-border-sm ms-1" style="display:none;"></span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Hidden print areas -->
<div id="printArea" aria-hidden="true"></div>
<div id="printListArea" aria-hidden="true"></div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

  // ══ Config: fields that trigger "missing" warnings ════════════
  const REQUIRED_FIELDS = [
    {field:'SSS_Number',   label:'SSS Number'},
    {field:'TIN_Number',   label:'TIN Number'},
    {field:'Philhealth_Number', label:'PhilHealth Number'},
    {field:'HDMF',         label:'HDMF / Pag-IBIG'},
    {field:'Mobile_Number',label:'Mobile Number'},
    {field:'Email_Address',label:'Email Address'},
    {field:'Birth_date',   label:'Birth Date'},
    {field:'Birth_Place',  label:'Birth Place'},
    {field:'Gender',       label:'Gender'},
    {field:'Civil_Status', label:'Civil Status'},
    {field:'Present_Address',   label:'Present Address'},
    {field:'Permanent_Address', label:'Permanent Address'},
    {field:'Contact_Person',    label:'Emergency Contact Person'},
    {field:'Contact_Number_Emergency', label:'Emergency Contact Number'},
  ];

  // ══ Helpers ═══════════════════════════════════════════════════
  const avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
  function avatarColor(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
    return avatarColors[Math.abs(h) % avatarColors.length];
  }
  function val(v) { const s = (v===null||v===undefined)?'':String(v).trim(); return s||null; }
  function resolveDate(v) {
    if (!v) return null;
    if (typeof v==='object' && v.date) return v.date.substring(0,10);
    if (typeof v==='string') return v.substring(0,10);
    return null;
  }
  function fmtDate(v) {
    const iso = resolveDate(v); if (!iso) return null;
    const [y,m,d] = iso.split('-').map(Number);
    return new Date(y,m-1,d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'});
  }
  function pDate(v) {
    const f = fmtDate(v);
    return (f && !f.includes('—')) ? f : '—';
  }
  function setVal(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html || '<span class="empty">—</span>';
  }
  function setText(id, text) {
    const el = document.getElementById(id); if (el) el.textContent = text||'—';
  }

  let currentEmp = {}, editMode = false;

  // ══ Modal open ════════════════════════════════════════════════
  document.getElementById('empDetailModal').addEventListener('show.bs.modal', e => {
    const row = e.relatedTarget; if (!row) return;
    try { currentEmp = JSON.parse(row.dataset.emp||'{}'); } catch { currentEmp = {}; }
    populateModal(currentEmp);
    exitEditMode();
  });

  function populateModal(emp) {
    const firstName = emp.FirstName||'', lastName = emp.LastName||'';
    const fullName  = `${firstName} ${lastName}`.trim();
    const initials  = ((firstName[0]||'')+(lastName[0]||'')).toUpperCase();
    const color     = avatarColor(fullName);
    const isActive  = parseInt(emp.Active||0)===1;
    const isBlack   = parseInt(emp.Blacklisted||0)===1;

    // Avatar with upload overlay
    const avatarEl = document.getElementById('modalAvatarEl');
    let picSrc = (emp.Picture||'').trim();
    if (picSrc && !picSrc.startsWith('/')) picSrc = '/TWM/tradewellportal/'+picSrc;
    if (picSrc) {
      avatarEl.innerHTML = `<img src="${picSrc}" class="modal-avatar" alt="${fullName}"
        onerror="this.outerHTML='<div class=modal-avatar-initials style=background:${color};>${initials}</div>'">`;
    } else {
      avatarEl.innerHTML = `<div class="modal-avatar-initials" style="background:${color};">${initials}</div>`;
    }

    setText('modalEmpName', `${lastName}, ${firstName}`);
    document.getElementById('modalEmpRole').textContent =
      [emp.Position_held, emp.Department].filter(Boolean).join(' · ')||'—';

    // Badges
    let badges = isActive
      ? '<span class="status-active"><span class="status-dot" style="background:#10b981;"></span> Active</span>'
      : '<span class="status-inactive"><span class="status-dot" style="background:#ef4444;"></span> Inactive</span>';
    if (isBlack) badges += ' <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>';
    document.getElementById('modalEmpBadges').innerHTML = badges;

    // Active toggle
    const toggle = document.getElementById('activeToggle');
    const toggleLabel = document.getElementById('activeToggleLabel');
    if (toggle) {
      toggle.checked = isActive;
      toggleLabel.textContent = isActive ? 'Active' : 'Inactive';
      toggleLabel.style.color = isActive ? '#059669' : '#dc2626';
    }

    // ── Populate display spans ─────────────────────────────────
    setVal('d-EmployeeID',        val(emp.EmployeeID));
    setVal('d-FileNo',            val(emp.FileNo));
    setVal('d-OfficeID',          val(emp.OfficeID));
    setVal('d-SSS_Number',        val(emp.SSS_Number));
    setVal('d-TIN_Number',        val(emp.TIN_Number));
    setVal('d-Philhealth_Number', val(emp.Philhealth_Number));
    setVal('d-HDMF',              val(emp.HDMF));
    setVal('d-LastName',          val(emp.LastName));
    setVal('d-FirstName',         val(emp.FirstName));
    setVal('d-MiddleName',        val(emp.MiddleName));
    setVal('d-Department',        val(emp.Department));
    setVal('d-Position_held',     val(emp.Position_held));
    setVal('d-Job_tittle',        val(emp.Job_tittle));
    setVal('d-Category',          val(emp.Category));
    setVal('d-Branch',            val(emp.Branch));
    setVal('d-System',            val(emp.System));
    setVal('d-Hired_date',        fmtDate(emp.Hired_date));
    setVal('d-Date_Of_Seperation',fmtDate(emp.Date_Of_Seperation));
    setVal('d-Employee_Status',   val(emp.Employee_Status));
    setVal('d-CutOff',            val(emp.CutOff));
    setVal('d-Birth_date',        fmtDate(emp.Birth_date));
    setVal('d-Birth_Place',       val(emp.Birth_Place));
    setVal('d-Gender',            val(emp.Gender));
    setVal('d-Civil_Status',      val(emp.Civil_Status));
    setVal('d-Nationality',       val(emp.Nationality));
    setVal('d-Religion',          val(emp.Religion));
    setVal('d-Mobile_Number',     val(emp.Mobile_Number));
    setVal('d-Phone_Number',      val(emp.Phone_Number));
    const email = val(emp.Email_Address);
    setVal('d-Email_Address', email ? `<a href="mailto:${email}" style="color:var(--primary);">${email}</a>` : null);
    setVal('d-Present_Address',   val(emp.Present_Address));
    setVal('d-Permanent_Address', val(emp.Permanent_Address));
    setVal('d-Contact_Person',    val(emp.Contact_Person));
    setVal('d-Relationship',      val(emp.Relationship));
    setVal('d-Contact_Number_Emergency', val(emp.Contact_Number_Emergency));
    setVal('d-Educational_Background',   val(emp.Educational_Background));
    setVal('d-Notes',             val(emp.Notes));

    // ── Populate edit inputs ───────────────────────────────────
    function setInput(id, v) {
      const el = document.getElementById(id); if (!el) return;
      if (el.tagName==='SELECT') el.value = val(v)||'';
      else if (el.type==='date') el.value = resolveDate(v)||'';
      else el.value = val(v)||'';
    }
    ['EmployeeID','FileNo','OfficeID','SSS_Number','TIN_Number','Philhealth_Number','HDMF',
     'LastName','FirstName','MiddleName','Department','Position_held','Job_tittle','Category',
     'Branch','System','Hired_date','Date_Of_Seperation','Employee_Status','CutOff',
     'Birth_date','Birth_Place','Gender','Civil_Status','Nationality','Religion',
     'Mobile_Number','Phone_Number','Email_Address','Present_Address','Permanent_Address',
     'Contact_Person','Relationship','Contact_Number_Emergency',
     'Educational_Background','Notes'
    ].forEach(f => setInput('e-'+f, emp[f]));

    // ── Missing field highlighting ─────────────────────────────
    applyMissingHighlights(emp);
  }

  // ── Missing field logic ───────────────────────────────────────
  function applyMissingHighlights(emp) {
    document.querySelectorAll('.detail-item.field-empty').forEach(el => el.classList.remove('field-empty'));

    const missing = [];
    REQUIRED_FIELDS.forEach(({field, label}) => {
      const v = emp[field];
      const isEmpty = !v || String(v).trim() === '';
      const spanEl  = document.getElementById('d-'+field);
      const itemEl  = spanEl ? spanEl.closest('.detail-item') : null;
      if (isEmpty && itemEl) {
        itemEl.classList.add('field-empty');
        if (spanEl) spanEl.innerHTML = '<span class="empty"><i class="bi bi-exclamation-triangle-fill" style="font-size:.65rem;margin-right:.2rem;color:#f59e0b;"></i>Not set</span>';
        missing.push(label);
      }
    });

    const panel = document.getElementById('missingFieldsPanel');
    const list  = document.getElementById('missingFieldsList');
    if (missing.length > 0) {
      list.innerHTML = missing.map(l=>`<li>${l}</li>`).join('');
      panel.classList.add('has-missing');
    } else {
      panel.classList.remove('has-missing');
    }
  }

  // ══ Edit / Save / Cancel ══════════════════════════════════════
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
    populateModal(currentEmp); exitEditMode();
  });

  document.getElementById('btnSave')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

    const fd = new FormData();
    fd.append('_action','update');
    fd.append('FileNo', currentEmp.FileNo||'');
    fd.append('EmployeeID', currentEmp.EmployeeID||'');
    document.querySelectorAll('.d-input[data-field]').forEach(el => { if (!el.readOnly) fd.append(el.dataset.field, el.value); });

    try {
      const res  = await fetch(window.location.pathname, {method:'POST',body:fd});
      const raw  = await res.text();
      let json;
      try { json = JSON.parse(raw); } catch { showToast('Server error — check console.','danger'); return; }

      if (json.success) {
        document.querySelectorAll('.d-input[data-field]').forEach(el => { currentEmp[el.dataset.field] = el.value; });
        populateModal(currentEmp);
        exitEditMode();
        showToast(json.message||'Changes saved.','success');
        // Update table row live
        document.querySelectorAll('.emp-row').forEach(r => {
          try {
            const d = JSON.parse(r.dataset.emp);
            if (String(d.FileNo)===String(currentEmp.FileNo)) {
              r.dataset.emp = JSON.stringify(currentEmp);
              const nameEl = r.querySelector('.emp-name');
              if (nameEl) {
                const tn = [...nameEl.childNodes].filter(n=>n.nodeType===3);
                if (tn[0]) tn[0].textContent = `${currentEmp.LastName||''}, ${currentEmp.FirstName||''} `;
              }
              const subEl = r.querySelector('.emp-sub');
              if (subEl) subEl.textContent = currentEmp.MiddleName||'';
            }
          } catch {}
        });
      } else {
        showToast('Save failed: '+(json.message||'Unknown error.'),'danger');
      }
    } catch(err) { showToast('Network error.','danger'); }
    finally { btn.disabled=false; btn.innerHTML='<i class="bi bi-check-lg"></i> Save Changes'; }
  });

  // ══ Active status toggle ══════════════════════════════════════
  document.getElementById('activeToggle')?.addEventListener('change', async function() {
    const newActive = this.checked ? 1 : 0;
    const spinner   = document.getElementById('activeToggleSpinner');
    const label     = document.getElementById('activeToggleLabel');
    this.disabled   = true; spinner.style.display = 'inline-block';

    const fd = new FormData();
    fd.append('_action','toggle_active');
    fd.append('FileNo', currentEmp.FileNo||'');
    fd.append('Active', newActive);

    try {
      const res  = await fetch(window.location.pathname, {method:'POST',body:fd});
      const json = await res.json();
      if (json.success) {
        currentEmp.Active = newActive;
        label.textContent = newActive ? 'Active' : 'Inactive';
        label.style.color = newActive ? '#059669' : '#dc2626';

        let badges = newActive
          ? '<span class="status-active"><span class="status-dot" style="background:#10b981;"></span> Active</span>'
          : '<span class="status-inactive"><span class="status-dot" style="background:#ef4444;"></span> Inactive</span>';
        if (parseInt(currentEmp.Blacklisted||0)===1) badges += ' <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>';
        document.getElementById('modalEmpBadges').innerHTML = badges;

        document.querySelectorAll('.emp-row').forEach(r => {
          try {
            const d = JSON.parse(r.dataset.emp);
            if (String(d.FileNo)===String(currentEmp.FileNo)) {
              d.Active = newActive; r.dataset.emp = JSON.stringify(d);
              const statusTd = r.querySelector('td:last-child');
              if (statusTd) statusTd.innerHTML = newActive
                ? '<span class="status-active"><span class="status-dot" style="background:#10b981;"></span> Active</span>'
                : '<span class="status-inactive"><span class="status-dot" style="background:#ef4444;"></span> Inactive</span>';
            }
          } catch {}
        });

        showToast(`Employee marked as ${newActive?'Active':'Inactive'}.`, 'success');
      } else {
        this.checked = !this.checked;
        showToast('Failed: '+(json.message||'Error.'),'danger');
      }
    } catch { this.checked = !this.checked; showToast('Network error.','danger'); }
    finally { this.disabled=false; spinner.style.display='none'; }
  });

  // ══ Profile picture upload — preview first ════════════════════
  let selectedFile = null;
  const picPreviewModalEl = document.getElementById('picPreviewModal');
  const picPreviewModal   = new bootstrap.Modal(picPreviewModalEl, { backdrop: false });

  document.getElementById('avatarUploadWrap')?.addEventListener('click', () => {
    document.getElementById('avatarFileInput').click();
  });

  document.getElementById('avatarFileInput')?.addEventListener('change', function() {
    if (!this.files.length) return;
    const file = this.files[0];

    const allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!allowed.includes(file.type)) {
      showToast('Invalid file type. Please use JPG, PNG, GIF or WEBP.', 'danger');
      this.value = ''; return;
    }
    if (file.size > 5 * 1024 * 1024) {
      showToast('File too large. Maximum size is 5MB.', 'danger');
      this.value = ''; return;
    }

    selectedFile = file;

    const reader = new FileReader();
    reader.onload = e => {
      document.getElementById('picPreviewImg').src = e.target.result;
      document.getElementById('picPreviewName').textContent = file.name;
      document.getElementById('picPreviewSize').textContent =
        (file.size / 1024).toFixed(1) + ' KB · ' + file.type.replace('image/','').toUpperCase();
      picPreviewModal.show();
    };
    reader.readAsDataURL(file);
    this.value = '';
  });

  document.getElementById('btnPickDifferent')?.addEventListener('click', () => {
    picPreviewModal.hide();
    setTimeout(() => document.getElementById('avatarFileInput').click(), 300);
  });

  document.getElementById('btnConfirmUpload')?.addEventListener('click', async () => {
    if (!selectedFile) return;

    const btn     = document.getElementById('btnConfirmUpload');
    const spinner = document.getElementById('uploadSpinner');
    btn.disabled  = true;
    spinner.style.display = 'inline-block';

    const fd = new FormData();
    fd.append('_action', 'upload_picture');
    fd.append('FileNo',  currentEmp.FileNo || '');
    fd.append('picture', selectedFile);

    try {
      const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const json = await res.json();

      if (json.success) {
        picPreviewModal.hide();
        currentEmp.Picture = json.picturePath;

        const avatarEl  = document.getElementById('modalAvatarEl');
        const firstName = currentEmp.FirstName || '', lastName = currentEmp.LastName || '';
        const fullName  = `${firstName} ${lastName}`.trim();
        const initials  = ((firstName[0] || '') + (lastName[0] || '')).toUpperCase();
        const color     = avatarColor(fullName);
        avatarEl.innerHTML = `<img src="${json.picturePath}?t=${Date.now()}" class="modal-avatar" alt="${fullName}"
          onerror="this.outerHTML='<div class=modal-avatar-initials style=background:${color};>${initials}</div>'">`;

        document.querySelectorAll('.emp-row').forEach(r => {
          try {
            const d = JSON.parse(r.dataset.emp);
            if (String(d.FileNo) === String(currentEmp.FileNo)) {
              d.Picture = json.picturePath;
              r.dataset.emp = JSON.stringify(d);
              const wrap = r.querySelector('.emp-name-wrap');
              if (wrap) {
                const old = wrap.querySelector('.emp-avatar');
                if (old) {
                  const img = document.createElement('img');
                  img.src = json.picturePath + '?t=' + Date.now();
                  img.className = 'emp-avatar';
                  img.alt = fullName;
                  old.parentNode.replaceChild(img, old);
                }
              }
            }
          } catch {}
        });

        selectedFile = null;
        showToast('Profile picture updated successfully.', 'success');
      } else {
        showToast('Upload failed: ' + (json.message || 'Unknown error.'), 'danger');
      }
    } catch {
      showToast('Network error — please try again.', 'danger');
    } finally {
      btn.disabled = false;
      spinner.style.display = 'none';
    }
  });

  // ══ Toast ═════════════════════════════════════════════════════
  function showToast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `alert alert-${type} alert-dismissible`;
    t.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;min-width:260px;box-shadow:0 4px 20px rgba(0,0,0,.15);';
    t.innerHTML = msg+'<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),4000);
  }

  // ══ Print single employee ═════════════════════════════════════
  document.getElementById('btnPrint')?.addEventListener('click', () => {
    buildPrintArea(currentEmp);
    document.getElementById('printListArea').innerHTML = '';
    window.print();
  });

  function pv(v) { const s=(v===null||v===undefined)?'':String(v).trim(); return s||'—'; }

  function section(title, rows) {
    const cls = rows.length <= 3 ? 'print-grid-3' : 'print-grid';
    return `<div class="print-section"><div class="print-section-title">${title}</div>
      <div class="${cls}">${rows.map(([l,v])=>`<div class="print-item"><label>${l}</label><span>${v}</span></div>`).join('')}</div></div>`;
  }

  function buildPrintArea(emp) {
    const firstName=emp.FirstName||'', lastName=emp.LastName||'';
    const fullName=`${firstName} ${lastName}`.trim();
    const initials=((firstName[0]||'')+(lastName[0]||'')).toUpperCase();
    const color=avatarColor(fullName);
    let picSrc=(emp.Picture||'').trim();
    if (picSrc&&!picSrc.startsWith('/')) picSrc='/TWM/tradewellportal/'+picSrc;
    const avatarHtml=picSrc
      ?`<img class="print-avatar" src="${picSrc}" alt="${fullName}">`
      :`<div class="print-avatar-initials" style="background:${color};">${initials}</div>`;
    const isActive=parseInt(emp.Active||0)===1;
    const isBlack=parseInt(emp.Blacklisted||0)===1;
    let statusText=isActive?'✔ Active':'✘ Inactive';
    if (isBlack) statusText+='  |  ⚠ Blacklisted';

    document.getElementById('printArea').innerHTML = `
      <div class="print-header">${avatarHtml}<div>
        <div class="print-name">${pv(lastName)}, ${pv(firstName)} ${pv(emp.MiddleName)}</div>
        <div class="print-role">${pv(emp.Position_held)} · ${pv(emp.Department)}</div>
        <div style="font-size:.75rem;margin-top:.25rem;color:#475569;">${statusText}</div>
      </div></div>
      ${section('Identification',[['Employee ID',pv(emp.EmployeeID)],['File No',pv(emp.FileNo)],['Office ID',pv(emp.OfficeID)],['SSS Number',pv(emp.SSS_Number)],['TIN Number',pv(emp.TIN_Number)],['PhilHealth',pv(emp.Philhealth_Number)],['HDMF / Pag-IBIG',pv(emp.HDMF)]])}
      ${section('Work Information',[['Department',pv(emp.Department)],['Position',pv(emp.Position_held)],['Job Title',pv(emp.Job_tittle)],['Category',pv(emp.Category)],['Branch',pv(emp.Branch)],['System',pv(emp.System)],['Hired Date',pDate(emp.Hired_date)],['Separation Date',pDate(emp.Date_Of_Seperation)],['Employee Status',pv(emp.Employee_Status)],['Cut-Off',pv(emp.CutOff)]])}
      ${section('Personal Information',[['Birth Date',pDate(emp.Birth_date)],['Birth Place',pv(emp.Birth_Place)],['Gender',pv(emp.Gender)],['Civil Status',pv(emp.Civil_Status)],['Nationality',pv(emp.Nationality)],['Religion',pv(emp.Religion)]])}
      ${section('Contact Information',[['Mobile',pv(emp.Mobile_Number)],['Phone',pv(emp.Phone_Number)],['Email',pv(emp.Email_Address)],['Present Address',pv(emp.Present_Address)],['Permanent Address',pv(emp.Permanent_Address)]])}
      ${section('Emergency Contact',[['Contact Person',pv(emp.Contact_Person)],['Relationship',pv(emp.Relationship)],['Contact Number',pv(emp.Contact_Number_Emergency)]])}
      ${section('Education & Notes',[['Educational Background',pv(emp.Educational_Background)],['Notes',pv(emp.Notes)]])}
      <div class="print-footer">Printed on ${new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})} · HR Employee List</div>`;
  }

  // ══ Print employee LIST — fetches ALL records via server ══════
  document.getElementById('btnPrintList')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnPrintList');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Loading…';

    try {
      const fd = new FormData();
      fd.append('_action', 'export_data');
      fd.append('search', <?= json_encode($search) ?>);
      fd.append('dept',   <?= json_encode($deptFilter) ?>);

      const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const json = await res.json();

      if (!json.success || !json.data || !json.data.length) {
        showToast('No data to print.', 'danger');
        return;
      }

      buildPrintListArea(json.data);
      document.getElementById('printArea').innerHTML = '';
      window.print();

    } catch(err) {
      console.error(err);
      showToast('Failed to load records for printing.', 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-printer"></i> Print List';
    }
  });

  function buildPrintListArea(employees) {
    const date     = new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    const deptText = <?= json_encode($deptFilter ?: ($activeDept ?: 'All Departments')) ?>;
    const search   = <?= json_encode($search) ?>;
    let filter = deptText;
    if (search) filter += ` · Search: "${search}"`;

    const tableRows = employees.map(emp => {
      const name     = `${emp.LastName||''}, ${emp.FirstName||''} ${emp.MiddleName||''}`.trim();
      const isActive = parseInt(emp.Active||0) === 1;
      return `<tr>
        <td>${name}</td>
        <td>${emp.EmployeeID||'—'}</td>
        <td>${emp.Department||'—'}</td>
        <td>${emp.Position_held||'—'}</td>
        <td>${emp.Branch||'—'}</td>
        <td>${emp.Mobile_Number||emp.Phone_Number||'—'}</td>
        <td>${pDate(emp.Hired_date)}</td>
        <td>${isActive?'Active':'Inactive'}</td>
      </tr>`;
    }).join('');

    document.getElementById('printListArea').innerHTML = `
      <div class="print-list-header">
        <div><h2>Employee List</h2><p>Department: ${filter}</p></div>
        <div style="text-align:right;"><p>Total: ${employees.length} record${employees.length!==1?'s':''}</p><p>Printed: ${date}</p></div>
      </div>
      <table>
        <thead><tr><th>Name</th><th>Employee ID</th><th>Department</th><th>Position</th><th>Branch</th><th>Contact</th><th>Hired Date</th><th>Status</th></tr></thead>
        <tbody>${tableRows}</tbody>
      </table>`;
  }

  // ══ Export to Excel — all records via server ══════════════════
  document.getElementById('btnExportExcel')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnExportExcel');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Exporting…';

    try {
      const fd = new FormData();
      fd.append('_action', 'export_data');
      fd.append('search', <?= json_encode($search) ?>);
      fd.append('dept',   <?= json_encode($deptFilter) ?>);

      const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
      const json = await res.json();

      if (!json.success || !json.data || !json.data.length) {
        showToast('No data to export.', 'danger');
        return;
      }

      const headers = [
        'File No','Employee ID','Last Name','First Name','Middle Name',
        'Department','Position','Job Title','Category','Branch','System',
        'Employee Status','Hired Date','Separation Date',
        'SSS Number','TIN Number','PhilHealth','HDMF/Pag-IBIG',
        'Mobile','Phone','Email','Present Address','Permanent Address',
        'Birth Date','Birth Place','Gender','Civil Status','Nationality','Religion',
        'Emergency Contact','Relationship','Emergency Number',
        'Educational Background','Notes','Active','Blacklisted'
      ];

      const data = json.data.map(e => [
        e.FileNo||'', e.EmployeeID||'', e.LastName||'', e.FirstName||'', e.MiddleName||'',
        e.Department||'', e.Position_held||'', e.Job_tittle||'', e.Category||'', e.Branch||'', e.System||'',
        e.Employee_Status||'', e.Hired_date||'', e.Date_Of_Seperation||'',
        e.SSS_Number||'', e.TIN_Number||'', e.Philhealth_Number||'', e.HDMF||'',
        e.Mobile_Number||'', e.Phone_Number||'', e.Email_Address||'',
        e.Present_Address||'', e.Permanent_Address||'',
        e.Birth_date||'', e.Birth_Place||'', e.Gender||'', e.Civil_Status||'',
        e.Nationality||'', e.Religion||'',
        e.Contact_Person||'', e.Relationship||'', e.Contact_Number_Emergency||'',
        e.Educational_Background||'', e.Notes||'',
        parseInt(e.Active||0)===1?'Yes':'No',
        parseInt(e.Blacklisted||0)===1?'Yes':'No'
      ]);

      const ws = XLSX.utils.aoa_to_sheet([headers, ...data]);
      ws['!cols'] = [8,12,14,14,14,18,18,18,12,14,14,14,12,12,14,14,14,14,14,14,24,28,28,12,18,10,14,14,14,20,14,14,24,24,8,10]
        .map(w => ({ wch: w }));

      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Employee List');

      const dept  = <?= json_encode($deptFilter ?: ($activeDept ?: 'All')) ?>;
      const fname = `Employee_List_${dept.replace(/\s+/g,'_')}_${new Date().toISOString().slice(0,10)}.xlsx`;
      XLSX.writeFile(wb, fname);

      showToast(`Exported ${json.data.length} records successfully.`, 'success');

    } catch(err) {
      console.error(err);
      showToast('Export failed — check console.', 'danger');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-file-earmark-excel"></i> Export Excel';
    }
  });

});
</script>
</body>
</html>