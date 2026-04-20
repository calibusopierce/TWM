<?php
// ══════════════════════════════════════════════════════════════
//  HR/employee-inactive.php
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR']);

// ── Session context ────────────────────────────────────────────
$_userType = $_SESSION['UserType'] ?? '';
$isAdmin   = in_array($_userType, ['Admin', 'Administrator']);

// ══════════════════════════════════════════════════════════════
//  AJAX / POST handlers (must run before any output)
// ══════════════════════════════════════════════════════════════

// ── EXCEL EXPORT ───────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exportSearch  = trim($_GET['search']  ?? '');
    $exportDept    = trim($_GET['dept']    ?? '');
    $_sessionDeptE = trim($_SESSION['Department'] ?? '');
    $viewAllE      = ($isAdmin && $_sessionDeptE === '');

    $expParams = [];
    $expWhere  = "WHERE Active = 0";
    if (!$viewAllE && $_sessionDeptE !== '') {
        $expWhere   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $expParams[] = '%' . $_sessionDeptE . '%';
    }
    if ($viewAllE && $exportDept !== '') {
        $expWhere   .= " AND LTRIM(RTRIM(Department)) LIKE ?";
        $expParams[] = '%' . $exportDept . '%';
    }
    if ($exportSearch !== '') {
        $sp = "%{$exportSearch}%";
        $expWhere .= " AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ? OR Department LIKE ? OR Position_held LIKE ? OR Branch LIKE ?)";
        array_push($expParams, $sp, $sp, $sp, $sp, $sp, $sp);
    }

    $expSql  = "SELECT FileNo, EmployeeID, LastName, FirstName, MiddleName, Department, Position_held, Job_tittle, Category, Branch, Hired_date, Date_Of_Seperation, Employee_Status, SSS_Number, TIN_Number, Philhealth_Number, HDMF, Mobile_Number, Phone_Number, Email_Address, Birth_date, Birth_Place, Civil_Status, Gender, Nationality, Religion, Present_Address, Permanent_Address, Contact_Person, Relationship, Contact_Number_Emergency, Educational_Background, Notes, Active, Blacklisted FROM [dbo].[TBL_HREmployeeList] {$expWhere} ORDER BY LastName, FirstName";
    $expStmt = sqlsrv_query($conn, $expSql, $expParams);

    $rows = [];
    if ($expStmt) {
        while ($r = sqlsrv_fetch_array($expStmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $r;
        }
        sqlsrv_free_stmt($expStmt);
    }

    $fmtD = function($d): string {
        if ($d instanceof DateTime) return $d->format('m/d/Y');
        if (is_string($d) && $d)   return date('m/d/Y', strtotime($d));
        return '';
    };

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Inactive_Employees_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    echo '<table border="1">';
    echo '<tr style="background:#475569;color:#fff;font-weight:bold;">';
    $headers = ['File No','Employee ID','Last Name','First Name','Middle Name','Department','Position','Job Title','Category','Branch','Hired Date','Separation Date','Employee Status','SSS No','TIN No','PhilHealth','HDMF','Mobile','Phone','Email','Birth Date','Birth Place','Civil Status','Gender','Nationality','Religion','Present Address','Permanent Address','Emergency Contact','Relationship','Emergency No','Education','Notes','Active','Blacklisted'];
    foreach ($headers as $h) echo "<th>" . htmlspecialchars($h) . "</th>";
    echo '</tr>';

    foreach ($rows as $r) {
        echo '<tr>';
        $cols = [
            $r['FileNo'], $r['EmployeeID'], $r['LastName'], $r['FirstName'], $r['MiddleName'],
            $r['Department'], $r['Position_held'], $r['Job_tittle'], $r['Category'], $r['Branch'],
            $fmtD($r['Hired_date']), $fmtD($r['Date_Of_Seperation']), $r['Employee_Status'],
            $r['SSS_Number'], $r['TIN_Number'], $r['Philhealth_Number'], $r['HDMF'],
            $r['Mobile_Number'], $r['Phone_Number'], $r['Email_Address'],
            $fmtD($r['Birth_date']), $r['Birth_Place'], $r['Civil_Status'], $r['Gender'],
            $r['Nationality'], $r['Religion'], $r['Present_Address'], $r['Permanent_Address'],
            $r['Contact_Person'], $r['Relationship'], $r['Contact_Number_Emergency'],
            $r['Educational_Background'], $r['Notes'],
            ((int)($r['Active'] ?? 0) ? 'Yes' : 'No'),
            ((int)($r['Blacklisted'] ?? 0) ? 'Yes' : 'No'),
        ];
        foreach ($cols as $c) echo '<td>' . htmlspecialchars((string)($c ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// ── CRUD AJAX ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    header('Content-Type: application/json');

    $action = $_POST['_action'];
    $fileNo = (int)($_POST['FileNo'] ?? 0);

    // ── Helper: sanitize string param ─────────────────────────
    $sp = fn(string $k) => isset($_POST[$k]) ? trim($_POST[$k]) : null;
    $ip = fn(string $k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (int)$_POST[$k] : null;

    // ─── UPDATE ───────────────────────────────────────────────
    if ($action === 'update' && $fileNo > 0) {
        $fields = [
            'EmployeeID'               => $sp('EmployeeID'),
            'OfficeID'                 => $sp('OfficeID'),
            'Department'               => $sp('Department'),
            'Position_held'            => $sp('Position_held'),
            'Job_tittle'               => $sp('Job_tittle'),
            'Category'                 => $sp('Category'),
            'Branch'                   => $sp('Branch'),
            'System'                   => $sp('System'),
            'Employee_Status'          => $sp('Employee_Status'),
            'CutOff'                   => $sp('CutOff'),
            'LastName'                 => $sp('LastName'),
            'FirstName'                => $sp('FirstName'),
            'MiddleName'               => $sp('MiddleName'),
            'SSS_Number'               => $sp('SSS_Number'),
            'TIN_Number'               => $sp('TIN_Number'),
            'Philhealth_Number'        => $sp('Philhealth_Number'),
            'HDMF'                     => $sp('HDMF'),
            'Mobile_Number'            => $sp('Mobile_Number'),
            'Phone_Number'             => $sp('Phone_Number'),
            'Email_Address'            => $sp('Email_Address'),
            'Birth_Place'              => $sp('Birth_Place'),
            'Civil_Status'             => $sp('Civil_Status'),
            'Gender'                   => $sp('Gender'),
            'Nationality'              => $sp('Nationality'),
            'Religion'                 => $sp('Religion'),
            'Present_Address'          => $sp('Present_Address'),
            'Permanent_Address'        => $sp('Permanent_Address'),
            'Contact_Person'           => $sp('Contact_Person'),
            'Relationship'             => $sp('Relationship'),
            'Contact_Number_Emergency' => $sp('Contact_Number_Emergency'),
            'Educational_Background'   => $sp('Educational_Background'),
            'Notes'                    => $sp('Notes'),
            'Active'                   => $ip('Active'),
            'Blacklisted'              => $ip('Blacklisted'),
        ];

        // Date fields
        foreach (['Hired_date','Date_Of_Seperation','Birth_date'] as $df) {
            $v = $sp($df);
            $fields[$df] = ($v && $v !== '') ? $v : null;
        }

        $setParts = [];
        $setVals  = [];
        foreach ($fields as $col => $val) {
            $setParts[] = "[{$col}] = ?";
            $setVals[]  = $val;
        }
        $setVals[] = $fileNo;

        $updateSql  = "UPDATE [dbo].[TBL_HREmployeeList] SET " . implode(', ', $setParts) . " WHERE FileNo = ?";
        $updateStmt = sqlsrv_query($conn, $updateSql, $setVals);

        if ($updateStmt === false) {
            $errors = sqlsrv_errors();
            echo json_encode(['success' => false, 'message' => $errors[0]['message'] ?? 'Update failed.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Employee record updated successfully.']);
            sqlsrv_free_stmt($updateStmt);
        }
        exit;
    }

    // ─── DELETE ───────────────────────────────────────────────
    if ($action === 'delete' && $fileNo > 0 && $isAdmin) {
        $delStmt = sqlsrv_query($conn,
            "DELETE FROM [dbo].[TBL_HREmployeeList] WHERE FileNo = ?",
            [$fileNo]);
        if ($delStmt === false) {
            echo json_encode(['success' => false, 'message' => 'Delete failed.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Employee record deleted.']);
            sqlsrv_free_stmt($delStmt);
        }
        exit;
    }

    // ─── REACTIVATE ───────────────────────────────────────────
    if ($action === 'reactivate' && $fileNo > 0) {
        $reactStmt = sqlsrv_query($conn,
            "UPDATE [dbo].[TBL_HREmployeeList] SET Active = 1 WHERE FileNo = ?",
            [$fileNo]);
        if ($reactStmt === false) {
            echo json_encode(['success' => false, 'message' => 'Reactivation failed.']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Employee reactivated successfully.']);
            sqlsrv_free_stmt($reactStmt);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action or missing FileNo.']);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  PAGE RENDERING
// ══════════════════════════════════════════════════════════════

// ── Pagination & filters ───────────────────────────────────────
$perPage    = 20;
$page       = max(1, (int)($_GET['page']   ?? 1));
$offset     = ($page - 1) * $perPage;
$search     = trim($_GET['search']          ?? '');
$deptFilter = trim($_GET['dept']            ?? '');

// ── Session department ─────────────────────────────────────────
$_sessionDept = trim($_SESSION['Department'] ?? '');
$viewAll      = ($isAdmin && $_sessionDept === '');
$activeDept   = $_sessionDept;

// ── Build WHERE — Active = 0 ───────────────────────────────────
function buildWhere(bool $viewAll, string $userDept, string $search, string $deptFilter, array &$params): string
{
    $params = [];
    $where  = "WHERE Active = 0";

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
        $where .= " AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ? OR Department LIKE ? OR Position_held LIKE ? OR Branch LIKE ?)";
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
        CONVERT(varchar(10), Hired_date, 23)        AS Hired_date,
        CONVERT(varchar(10), Date_Of_Seperation, 23) AS Date_Of_Seperation,
        Employee_Status,
        LastName, FirstName, MiddleName,
        Permanent_Address, Present_Address,
        SSS_Number, TIN_Number, Philhealth_Number, HDMF,
        Phone_Number, Mobile_Number, Email_Address,
        CONVERT(varchar(10), Birth_date, 23) AS Birth_date,
        Birth_Place, Civil_Status, Gender,
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

// Safety net: convert any residual DateTime objects to plain strings
function serializeRow(array $row): array
{
    $dateFields = ['Hired_date', 'Date_Of_Seperation', 'Birth_date'];
    foreach ($dateFields as $f) {
        if (isset($row[$f]) && $row[$f] instanceof DateTime) {
            $row[$f] = $row[$f]->format('Y-m-d');
        } elseif (!isset($row[$f]) || $row[$f] === '') {
            $row[$f] = null;
        }
    }
    return $row;
}

$fetchParams = array_merge($params, [$offset, $perPage]);
$stmt        = sqlsrv_query($conn, $sql, $fetchParams);
$employees   = [];
if ($stmt) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = serializeRow($r);
    }
    sqlsrv_free_stmt($stmt);
}

// ── Departments for filter dropdown ───────────────────────────
$deptStmt = sqlsrv_query($conn,
    "SELECT DepartmentName FROM [dbo].[Departments] WHERE Status = 1 ORDER BY DepartmentName");
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
function fmtDateVal($d): string
{
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    if (is_string($d) && $d)   return date('Y-m-d', strtotime($d));
    return '';
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

// ── Export URL (preserves current filters) ────────────────────
$exportUrl = '?export=excel&search=' . urlencode($search) . ($viewAll && $deptFilter ? '&dept=' . urlencode($deptFilter) : '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inactive Employees · HR</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Inactive banner ── */
    .inactive-banner {
      background: linear-gradient(135deg, #1e3a5f, #2563eb);
      border-radius: 12px;
      padding: .85rem 1.25rem;
      display: flex; align-items: center; gap: .75rem;
      color: #fff; font-size: .85rem; font-weight: 600;
      margin-bottom: 1.25rem;
      box-shadow: 0 4px 16px rgba(37,99,235,.2);
    }
    .inactive-banner i { font-size: 1.1rem; flex-shrink: 0; }
    .inactive-banner span { opacity: .85; font-weight: 400; margin-left: .25rem; }

    /* ── Employee table ── */
    .emp-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .78rem; font-weight: 800; color: #fff;
      flex-shrink: 0; object-fit: cover;
      border: 2px solid rgba(255,255,255,.6);
      box-shadow: 0 2px 6px rgba(0,0,0,.15);
      filter: grayscale(60%);
    }
    .emp-name-wrap { display: flex; align-items: center; gap: .65rem; }
    .emp-name { font-weight: 700; font-size: .88rem; color: var(--text-primary); line-height: 1.2; }
    .emp-sub  { font-size: .72rem; color: var(--text-muted); margin-top: .1rem; }

    .status-inactive {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .18rem .55rem; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      background: rgba(100,116,139,.1); color: #475569;
      border: 1px solid rgba(100,116,139,.3);
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
    .sep-badge {
      display: inline-flex; align-items: center; gap: .25rem;
      padding: .15rem .45rem; border-radius: 999px;
      font-size: .65rem; font-weight: 700;
      background: rgba(100,116,139,.08); color: #64748b;
      border: 1px solid rgba(100,116,139,.25);
    }

    .emp-row { cursor: pointer; transition: background .12s; }
    .emp-row:hover td { background: rgba(37,99,235,.04); }
    .emp-row td:first-child { border-left: 3px solid rgba(100,116,139,.25); }

    /* ── Toast ── */
    #toastContainer {
      position: fixed; top: 1rem; right: 1rem; z-index: 9999;
      display: flex; flex-direction: column; gap: .5rem;
    }
    .toast-msg {
      padding: .75rem 1.1rem; border-radius: 8px;
      font-size: .83rem; font-weight: 600; color: #fff;
      box-shadow: 0 4px 16px rgba(0,0,0,.15);
      animation: slideIn .25s ease;
    }
    .toast-success { background: #10b981; }
    .toast-error   { background: #ef4444; }
    @keyframes slideIn {
      from { transform: translateX(100%); opacity: 0; }
      to   { transform: translateX(0);    opacity: 1; }
    }

    /* ── Detail / Edit Modal ── */
    .detail-modal .modal-content {
      border-radius: 16px; border: none;
      box-shadow: 0 24px 80px rgba(0,0,0,.2);
    }
    .detail-modal .modal-header {
      background: var(--bs-body-bg, #fff);
      border-bottom: 1px solid #e2e8f0;
      border-radius: 16px 16px 0 0;
      padding: 1rem 1.5rem;
    }
    .detail-modal .modal-title { font-weight: 700; color: #0f172a; font-size: 1rem; }
    .detail-modal .btn-close { filter: none; opacity: .6; }

    .modal-avatar-wrap {
      display: flex; align-items: center; gap: 1rem;
      padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9;
    }
    .modal-avatar {
      width: 64px; height: 64px; border-radius: 50%;
      object-fit: cover; border: 3px solid #e2e8f0;
      flex-shrink: 0; filter: grayscale(40%);
    }
    .modal-avatar-initials {
      width: 64px; height: 64px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; font-weight: 800; color: #fff; flex-shrink: 0;
    }
    .modal-emp-name { font-size: 1.1rem; font-weight: 800; color: #0f172a; }
    .modal-emp-role { font-size: .82rem; color: #64748b; margin-top: .15rem; }

    .detail-section {
      padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9;
    }
    .detail-section:last-child { border-bottom: none; }
    .detail-section-title {
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .07em;
      color: #475569;
      margin-bottom: .75rem;
      padding-left: .6rem;
      border-left: 3px solid #3b82f6;
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .detail-grid   { display: grid; grid-template-columns: 1fr 1fr;     gap: .5rem .75rem; }
    .detail-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .5rem .75rem; }
    .detail-item label {
      font-size: .68rem; font-weight: 700; color: #94a3b8;
      text-transform: uppercase; letter-spacing: .06em;
      display: block; margin-bottom: .15rem;
    }

    /* View mode */
    .detail-item .view-val,
    .detail-item .d-val {
      font-size: .83rem; font-weight: 500; color: #1e293b;
      display: block; word-break: break-word;
    }
    .detail-item .view-val.empty,
    .detail-item .d-val.empty { color: #cbd5e1; font-style: italic; }

    /* Edit mode — inputs hidden in view mode */
    .detail-item .edit-ctrl { display: none; margin-top: .2rem; }
    .detail-item .edit-ctrl input,
    .detail-item .edit-ctrl select,
    .detail-item .edit-ctrl textarea {
      width: 100%; padding: .28rem .45rem; border: 1px solid #c7d2fe;
      border-radius: 6px; font-size: .82rem; color: #1e293b;
      background: #f8faff; transition: border-color .15s, box-shadow .15s;
      resize: vertical;
    }
    .detail-item .edit-ctrl input:focus,
    .detail-item .edit-ctrl select:focus,
    .detail-item .edit-ctrl textarea:focus {
      border-color: #4380e2; box-shadow: 0 0 0 3px rgba(67,128,226,.15); outline: none;
    }
    /* Read-only fields in edit mode */
    .detail-item .edit-ctrl input[readonly] {
      background: #f1f5f9 !important; color: #94a3b8; cursor: not-allowed;
    }

    /* Toggle: edit mode active */
    .modal-body.edit-mode .view-val,
    .modal-body.edit-mode .d-val  { display: none !important; }
    .modal-body.edit-mode .edit-ctrl { display: block; }

    /* Edit indicator strip */
    #editModeStrip {
      display: none; background: rgba(37,99,235,.07);
      border-bottom: 1px solid rgba(37,99,235,.2);
      padding: .5rem 1.5rem; font-size: .78rem;
      color: #1d4ed8; font-weight: 600;
      align-items: center; gap: .5rem;
    }
    #editModeStrip.active { display: flex; }

    @media (max-width: 576px) {
      .detail-grid, .detail-grid-3 { grid-template-columns: 1fr; }
    }

    /* ── Print styles ── */
    @media print {
      /* Hide everything except the print target */
      body > *                          { display: none !important; }
      #printArea, #printArea *          { display: block !important; }
      #printArea                        { display: block !important; }

      /* Profile print */
      .print-profile-header {
        display: flex !important; align-items: center; gap: 1rem;
        border-bottom: 2px solid #1e3a5f; padding-bottom: .75rem; margin-bottom: 1rem;
      }
      .print-profile-header h2 { margin: 0; font-size: 1.2rem; color: #1e3a5f; }
      .print-profile-header p  { margin: .1rem 0; font-size: .8rem; color: #475569; }
      .print-section-title {
        font-size: .65rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: .08em; color: #94a3b8; margin: .75rem 0 .4rem;
        border-bottom: 1px solid #e2e8f0; padding-bottom: .2rem;
      }
      .print-grid {
        display: grid !important; grid-template-columns: 1fr 1fr 1fr; gap: .3rem .5rem;
      }
      .print-item label {
        font-size: .6rem; font-weight: 700; color: #94a3b8;
        text-transform: uppercase; display: block;
      }
      .print-item span { font-size: .78rem; color: #1e293b; display: block; }

      /* List print */
      .print-list-table { width: 100%; border-collapse: collapse; font-size: .72rem; }
      .print-list-table th {
        background: #1e3a5f; color: #fff; padding: .3rem .4rem;
        text-align: left; font-size: .65rem; text-transform: uppercase;
      }
      .print-list-table td { padding: .25rem .4rem; border-bottom: 1px solid #e2e8f0; }
      .print-list-table tr:nth-child(even) td { background: #f8fafc; }
    }
  </style>
</head>
<body>

<?php $topbar_page = 'employees';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<!-- Toast container -->
<div id="toastContainer"></div>

<!-- Hidden print area -->
<div id="printArea" style="display:none;"></div>

<div class="main-wrapper">

  <!-- Page header -->
  <div class="page-header" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;">
    <div>
      <div class="page-title" style="display:flex;align-items:center;gap:.5rem;">
        <i class="bi bi-person-dash" style="color:#64748b;font-size:1.15rem;"></i>
        Inactive Employees
      </div>
      <div class="page-subtitle">
        Separated / inactive employees &nbsp;—&nbsp; <strong><?= $totalRows ?></strong> record<?= $totalRows !== 1 ? 's' : '' ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.5rem;margin-top:.35rem;">
      <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-sm" style="background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.3);font-weight:600;">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
      <!--<button type="button" onclick="printList()" class="btn btn-sm" style="background:rgba(100,116,139,.1);color:#475569;border:1px solid rgba(100,116,139,.3);font-weight:600;">
        <i class="bi bi-printer"></i> Print List -->
      </button>
      <a href="employee-list.php" class="btn btn-secondary btn-sm">
        <i class="bi bi-people"></i> Active Employees
      </a>
      <a href="employee-blacklist.php" class="btn btn-sm" style="background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.3);font-weight:600;">
        <i class="bi bi-slash-circle"></i> Blacklisted Employees
      </a>
    </div>
  </div>

  <!-- Info banner -->
  <div class="inactive-banner">
    <i class="bi bi-person-dash-fill"></i>
    <div>
      Showing separated and inactive employee records.
      <span>Click any row to view details, edit information, reactivate, or manage records.</span>
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
        <a href="employee-inactive.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
      </div>
    </form>
  </div>

  <!-- Table -->
  <div class="table-card">
    <div class="table-responsive">
      <table class="apps-table" id="mainTable">
        <thead>
          <tr>
            <th>Employee</th>
            <th>ID / File No</th>
            <th>Department &amp; Position</th>
            <th>Branch / Category</th>
            <th>Contact</th>
            <th>Hired / Separated</th>
            <th style="text-align:center;">Status</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($employees)): foreach ($employees as $emp):
            $fullName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));
            $initials = initials($emp['FirstName'] ?? '', $emp['LastName'] ?? '');
            $bgColor  = avatarColor($fullName);
            $isBlack  = (int)($emp['Blacklisted'] ?? 0) === 1;
            $picPath  = trim($emp['Picture'] ?? '');
            if ($picPath && !str_starts_with($picPath, '/')) {
                $picPath = '/TWM/tradewellportal/' . $picPath;
            }
            $hasPic = !empty($picPath) && file_exists($_SERVER['DOCUMENT_ROOT'] . $picPath);
        ?>
          <tr class="emp-row"
              data-emp="<?= htmlspecialchars(json_encode($emp, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
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
                <div><a href="mailto:<?= htmlspecialchars($emp['Email_Address']) ?>" class="text-link" style="font-size:.78rem;" onclick="event.stopPropagation()">
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
              <?php if ($emp['Date_Of_Seperation']): ?>
                <div class="date-time" style="color:#ef4444;"><i class="bi bi-door-open" style="font-size:.65rem;"></i> <?= fmtDate($emp['Date_Of_Seperation']) ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:center;">
              <span class="status-inactive"><span class="status-dot" style="background:#94a3b8;"></span> Inactive</span>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">
            <div class="empty-state">
              <i class="bi bi-person-dash"></i>
              <p>No inactive employees found.</p>
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


<!-- ══ EMPLOYEE DETAIL / EDIT MODAL ════════════════════════════ -->
<div class="modal fade detail-modal" id="empDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Inactive Employee Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- Edit mode indicator -->
      <div id="editModeStrip">
        <i class="bi bi-pencil-fill"></i> Edit mode — make changes below and click Save
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

      <div class="modal-body" style="padding:0;" id="modalBody">

        <!-- ── Identification ─────────────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-fingerprint"></i> Identification</div>
          <div class="detail-grid-3">
            <?php
            $idFields = [
              'EmployeeID'        => ['Employee ID',     'text',  false],
              'FileNo'            => ['File No',         'text',  true],   // readonly
              'OfficeID'          => ['Office ID',       'text',  false],
              'SSS_Number'        => ['SSS Number',      'text',  false],
              'TIN_Number'        => ['TIN Number',      'text',  false],
              'Philhealth_Number' => ['PhilHealth',      'text',  false],
              'HDMF'              => ['HDMF / Pag-IBIG', 'text',  false],
            ];
            foreach ($idFields as $field => [$label, $type, $readonly]) {
              $ro = $readonly ? 'readonly' : '';
              echo "<div class=\"detail-item\">
                <label>{$label}</label>
                <span class=\"view-val\" id=\"v-{$field}\">—</span>
                <div class=\"edit-ctrl\"><input type=\"{$type}\" id=\"e-{$field}\" name=\"{$field}\" {$ro}></div>
              </div>";
            }
            ?>
          </div>
        </div>

        <!-- ── Work Information ──────────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-briefcase"></i> Work Information</div>
          <div class="detail-grid">
            <?php
            $workFields = [
              'Department'       => ['Department',       'text'],
              'Position_held'    => ['Position',         'text'],
              'Job_tittle'       => ['Job Title',        'text'],
              'Category'         => ['Category',         'text'],
              'Branch'           => ['Branch',           'text'],
              'System'           => ['System',           'text'],
              'Hired_date'       => ['Hired Date',       'date'],
              'Date_Of_Seperation' => ['Separation Date','date'],
              'Employee_Status'  => ['Employee Status',  'text'],
              'CutOff'           => ['Cut-Off',          'text'],
            ];
            foreach ($workFields as $field => [$label, $type]) {
              echo "<div class=\"detail-item\">
                <label>{$label}</label>
                <span class=\"view-val\" id=\"v-{$field}\">—</span>
                <div class=\"edit-ctrl\"><input type=\"{$type}\" id=\"e-{$field}\" name=\"{$field}\"></div>
              </div>";
            }
            ?>
          </div>
        </div>

        <!-- ── Personal Information ──────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-person"></i> Personal Information</div>
          <div class="detail-grid">
            <?php
            $personalFields = [
              'LastName'     => ['Last Name',    'text'],
              'FirstName'    => ['First Name',   'text'],
              'MiddleName'   => ['Middle Name',  'text'],
              'Birth_date'   => ['Birth Date',   'date'],
              'Birth_Place'  => ['Birth Place',  'text'],
              'Civil_Status' => ['Civil Status', 'select', ['Single','Married','Widowed','Separated','Divorced']],
              'Gender'       => ['Gender',       'select', ['Male','Female','Other']],
              'Nationality'  => ['Nationality',  'text'],
              'Religion'     => ['Religion',     'text'],
            ];
            foreach ($personalFields as $field => $meta) {
              [$label, $type] = $meta;
              $opts = $meta[2] ?? [];
              if ($type === 'select') {
                $optHtml = '<option value="">—</option>';
                foreach ($opts as $o) $optHtml .= "<option value=\"{$o}\">{$o}</option>";
                echo "<div class=\"detail-item\">
                  <label>{$label}</label>
                  <span class=\"view-val\" id=\"v-{$field}\">—</span>
                  <div class=\"edit-ctrl\"><select id=\"e-{$field}\" name=\"{$field}\">{$optHtml}</select></div>
                </div>";
              } else {
                echo "<div class=\"detail-item\">
                  <label>{$label}</label>
                  <span class=\"view-val\" id=\"v-{$field}\">—</span>
                  <div class=\"edit-ctrl\"><input type=\"{$type}\" id=\"e-{$field}\" name=\"{$field}\"></div>
                </div>";
              }
            }
            ?>
          </div>
        </div>

        <!-- ── Contact Information ───────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
          <div class="detail-grid">
            <?php
            $contactFields = [
              'Mobile_Number'    => ['Mobile',           'tel'],
              'Phone_Number'     => ['Phone',            'tel'],
              'Email_Address'    => ['Email',            'email'],
              'Present_Address'  => ['Present Address',  'text'],
              'Permanent_Address'=> ['Permanent Address','text'],
            ];
            foreach ($contactFields as $field => [$label, $type]) {
              echo "<div class=\"detail-item\">
                <label>{$label}</label>
                <span class=\"view-val\" id=\"v-{$field}\">—</span>
                <div class=\"edit-ctrl\"><input type=\"{$type}\" id=\"e-{$field}\" name=\"{$field}\"></div>
              </div>";
            }
            ?>
          </div>
        </div>

        <!-- ── Emergency Contact ─────────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-heart-pulse"></i> Emergency Contact</div>
          <div class="detail-grid">
            <?php
            $emergFields = [
              'Contact_Person'           => ['Contact Person', 'text'],
              'Relationship'             => ['Relationship',   'text'],
              'Contact_Number_Emergency' => ['Contact Number', 'tel'],
            ];
            foreach ($emergFields as $field => [$label, $type]) {
              echo "<div class=\"detail-item\">
                <label>{$label}</label>
                <span class=\"view-val\" id=\"v-{$field}\">—</span>
                <div class=\"edit-ctrl\"><input type=\"{$type}\" id=\"e-{$field}\" name=\"{$field}\"></div>
              </div>";
            }
            ?>
          </div>
        </div>

        <!-- ── Education & Notes ─────────────────────── -->
        <div class="detail-section">
          <div class="detail-section-title"><i class="bi bi-book"></i> Education &amp; Notes</div>
          <div class="detail-grid">
            <div class="detail-item">
              <label>Educational Background</label>
              <span class="view-val" id="v-Educational_Background">—</span>
              <div class="edit-ctrl"><input type="text" id="e-Educational_Background" name="Educational_Background"></div>
            </div>
            <div class="detail-item">
              <label>Notes</label>
              <span class="view-val" id="v-Notes" style="white-space:pre-wrap;">—</span>
              <div class="edit-ctrl"><textarea id="e-Notes" name="Notes" rows="3"></textarea></div>
            </div>
          </div>
        </div>

        <!-- ── Flags (Admin only) ─────────────────────── -->
        <?php if ($isAdmin): ?>
        <div class="detail-section" id="adminFlagsSection">
          <div class="detail-section-title"><i class="bi bi-shield-check"></i> Record Flags <span style="color:#ef4444;font-size:.65rem;">(Admin)</span></div>
          <div class="detail-grid-3">
            <div class="detail-item">
              <label>Active Status</label>
              <span class="view-val" id="v-Active">—</span>
              <div class="edit-ctrl">
                <select id="e-Active" name="Active">
                  <option value="0">Inactive</option>
                  <option value="1">Active</option>
                </select>
              </div>
            </div>
            <div class="detail-item">
              <label>Blacklisted</label>
              <span class="view-val" id="v-Blacklisted">—</span>
              <div class="edit-ctrl">
                <select id="e-Blacklisted" name="Blacklisted">
                  <option value="0">No</option>
                  <option value="1">Yes</option>
                </select>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /modal-body -->

      <div class="modal-footer" style="gap:.5rem;flex-wrap:wrap;">
        <!-- View mode buttons -->
        <div id="viewModeActions" style="display:flex;gap:.5rem;flex-wrap:wrap;width:100%;justify-content:flex-end;">
          <button type="button" class="btn btn-sm" onclick="printProfile()" style="background:rgba(100,116,139,.1);color:#475569;border:1px solid rgba(100,116,139,.3);font-weight:600;">
            <i class="bi bi-printer"></i> Print Profile
          </button>
          <?php if ($isAdmin): ?>
          <button type="button" class="btn btn-sm btn-success" id="btnReactivate" onclick="reactivateEmp()">
            <i class="bi bi-person-check"></i> Reactivate
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-primary" id="btnEdit" onclick="enterEditMode()">
            <i class="bi bi-pencil"></i> Edit
          </button>
          <?php if ($isAdmin): ?>
          <button type="button" class="btn btn-sm btn-danger" id="btnDelete" onclick="deleteEmp()">
            <i class="bi bi-trash"></i> Delete
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
        <!-- Edit mode buttons -->
        <div id="editModeActions" style="display:none;gap:.5rem;flex-wrap:wrap;width:100%;justify-content:flex-end;">
          <button type="button" class="btn btn-sm btn-primary" id="btnSave" onclick="saveEmp()">
            <i class="bi bi-check-lg"></i> Save Changes
          </button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="exitEditMode()">
            <i class="bi bi-x"></i> Cancel
          </button>
        </div>
      </div>

    </div>
  </div>
</div><!-- /modal -->


<!-- ══ CONFIRM MODAL (delete / reactivate) ══════════════════════ -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 16px 60px rgba(0,0,0,.2);">
      <div class="modal-body" style="padding:1.5rem 1.5rem 1rem;">
        <div id="confirmIcon" style="font-size:2rem;margin-bottom:.5rem;text-align:center;"></div>
        <h6 id="confirmTitle" style="font-weight:800;text-align:center;margin-bottom:.4rem;"></h6>
        <p id="confirmMessage" style="font-size:.83rem;color:#64748b;text-align:center;margin:0;"></p>
      </div>
      <div class="modal-footer" style="justify-content:center;gap:.5rem;padding-top:0;">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm" id="confirmOkBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>


<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════════
   Inactive Employees — JS
══════════════════════════════════════════════════════════════ */
'use strict';

// ── State ──────────────────────────────────────────────────────
let _currentEmp   = {};
const isAdmin     = <?= $isAdmin ? 'true' : 'false' ?>;

// ── Avatar colors (must match PHP) ────────────────────────────
const _colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
function _avatarColor(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
  return _colors[Math.abs(h) % _colors.length];
}

// ── Toast ──────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const c = document.getElementById('toastContainer');
  const t = document.createElement('div');
  t.className = 'toast-msg toast-' + type;
  t.textContent = msg;
  c.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// ── Date formatting ────────────────────────────────────────────
function fmtDate(str) {
  if (!str) return null;
  // Parse as local date (split avoids UTC timezone off-by-one)
  const parts = String(str).substring(0, 10).split('-');
  if (parts.length !== 3) return str;
  const d = new Date(+parts[0], +parts[1] - 1, +parts[2]);
  if (isNaN(d)) return str;
  return d.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
}
function fmtDateInput(str) {
  if (!str) return '';
  // Return YYYY-MM-DD for <input type="date">
  return String(str).substring(0, 10);
}

// ── val helper ─────────────────────────────────────────────────
function setViewVal(id, raw) {
  const el = document.getElementById('v-' + id);
  if (!el) return;
  const v = (raw === null || raw === undefined || String(raw).trim() === '') ? null : String(raw).trim();
  if (v) {
    el.className = 'view-val';
    if (id === 'Email_Address') {
      el.innerHTML = `<a href="mailto:${v}" style="color:var(--primary);">${v}</a>`;
    } else {
      el.textContent = v;
    }
  } else {
    el.className = 'view-val empty';
    el.textContent = '—';
  }
}
function setEditVal(id, raw) {
  const el = document.getElementById('e-' + id);
  if (!el) return;
  if (el.tagName === 'SELECT') {
    const v = (raw === null || raw === undefined) ? '' : String(raw).trim();
    el.value = v;
  } else if (el.type === 'date') {
    el.value = fmtDateInput(raw);
  } else {
    el.value = (raw === null || raw === undefined) ? '' : String(raw).trim();
  }
}

// ── Date fields ────────────────────────────────────────────────
const DATE_FIELDS = ['Hired_date','Date_Of_Seperation','Birth_date'];
const ALL_FIELDS  = [
  'EmployeeID','FileNo','OfficeID','SSS_Number','TIN_Number','Philhealth_Number','HDMF',
  'Department','Position_held','Job_tittle','Category','Branch','System',
  'Hired_date','Date_Of_Seperation','Employee_Status','CutOff',
  'LastName','FirstName','MiddleName','Birth_date','Birth_Place',
  'Civil_Status','Gender','Nationality','Religion',
  'Mobile_Number','Phone_Number','Email_Address','Present_Address','Permanent_Address',
  'Contact_Person','Relationship','Contact_Number_Emergency',
  'Educational_Background','Notes','Active','Blacklisted'
];

// ── Populate modal ─────────────────────────────────────────────
document.getElementById('empDetailModal').addEventListener('show.bs.modal', e => {
  const row = e.relatedTarget;
  if (!row) return;
  exitEditMode();
  try { _currentEmp = JSON.parse(row.dataset.emp || '{}'); } catch { _currentEmp = {}; }
  populateModal(_currentEmp);
});

function populateModal(emp) {
  const firstName = emp.FirstName || '';
  const lastName  = emp.LastName  || '';
  const fullName  = `${firstName} ${lastName}`.trim();
  const initials  = (firstName[0] || '') + (lastName[0] || '');
  const color     = _avatarColor(fullName);
  const isBlack   = parseInt(emp.Blacklisted || 0) === 1;

  // Avatar
  const avatarEl = document.getElementById('modalAvatarEl');
  let picSrc = (emp.Picture || '').trim();
  if (picSrc && !picSrc.startsWith('/')) picSrc = '/TWM/tradewellportal/' + picSrc;
  if (picSrc) {
    avatarEl.innerHTML = `<img src="${picSrc}" class="modal-avatar" alt="${fullName}"
      onerror="this.outerHTML='<div class=modal-avatar-initials style=background:${color};>${initials.toUpperCase()}</div>'">`;
  } else {
    avatarEl.innerHTML = `<div class="modal-avatar-initials" style="background:${color};">${initials.toUpperCase()}</div>`;
  }

  document.getElementById('modalEmpName').textContent = `${lastName}, ${firstName}` || '—';
  document.getElementById('modalEmpRole').textContent =
    [emp.Position_held, emp.Department].filter(Boolean).join(' · ') || '—';

  let badges = '<span class="status-inactive" style="font-size:.68rem;"><span class="status-dot" style="background:#94a3b8;width:6px;height:6px;border-radius:50%;display:inline-block;"></span> Inactive</span>';
  if (isBlack) badges += ' <span class="blacklisted-badge"><i class="bi bi-slash-circle"></i> Blacklisted</span>';
  document.getElementById('modalEmpBadges').innerHTML = badges;

  // Populate view values
  ALL_FIELDS.forEach(f => {
    let raw = emp[f];
    if (DATE_FIELDS.includes(f)) {
      setViewVal(f, fmtDate(raw) || null);
    } else if (f === 'Active') {
      setViewVal(f, parseInt(raw || 0) === 1 ? 'Active' : 'Inactive');
    } else if (f === 'Blacklisted') {
      setViewVal(f, parseInt(raw || 0) === 1 ? 'Yes' : 'No');
    } else {
      setViewVal(f, raw);
    }
    if (document.getElementById('e-' + f)) setEditVal(f, raw);  // guard admin-only inputs
  });
}

// ── Edit mode ──────────────────────────────────────────────────
function enterEditMode() {
  document.getElementById('modalBody').classList.add('edit-mode');
  document.getElementById('editModeStrip').classList.add('active');
  document.getElementById('viewModeActions').style.display = 'none';
  document.getElementById('editModeActions').style.display = 'flex';
}
function exitEditMode() {
  document.getElementById('modalBody').classList.remove('edit-mode');
  document.getElementById('editModeStrip').classList.remove('active');
  document.getElementById('viewModeActions').style.display = 'flex';
  document.getElementById('editModeActions').style.display = 'none';
}

// ── Save ───────────────────────────────────────────────────────
async function saveEmp() {
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

  const fd = new FormData();
  fd.append('_action', 'update');
  fd.append('FileNo', _currentEmp.FileNo || '');

  ALL_FIELDS.forEach(f => {
    const el = document.getElementById('e-' + f);
    if (el && !el.readOnly) fd.append(f, el.value);  // skip readonly & missing (admin-only) fields
  });

  try {
    const res  = await fetch(window.location.pathname, { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      showToast(json.message, 'success');
      // ── Sync _currentEmp from inputs
      ALL_FIELDS.forEach(f => {
        const el = document.getElementById('e-' + f);
        if (el) _currentEmp[f] = el.value;
      });
      exitEditMode();
      populateModal(_currentEmp);
      // ── Update the matching table row's data-emp + visible cells
      document.querySelectorAll('.emp-row').forEach(r => {
        try {
          const d = JSON.parse(r.dataset.emp);
          if (String(d.FileNo) === String(_currentEmp.FileNo)) {
            r.dataset.emp = JSON.stringify(_currentEmp);
            const nameEl = r.querySelector('.emp-name');
            if (nameEl) {
              // Keep only the first text node (the name), preserve badge spans
              const textNodes = [...nameEl.childNodes].filter(n => n.nodeType === 3);
              if (textNodes[0]) {
                textNodes[0].textContent = `${_currentEmp.LastName || ''}, ${_currentEmp.FirstName || ''} `;
              }
            }
            const subEl = r.querySelector('.emp-sub');
            if (subEl) subEl.textContent = _currentEmp.MiddleName || '';
          }
        } catch { /**/ }
      });
    } else {
      showToast(json.message || 'Save failed.', 'error');
    }
  } catch {
    showToast('Network error — please try again.', 'error');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-check-lg"></i> Save Changes';
}

// ── Confirm helper ─────────────────────────────────────────────
function showConfirm({ icon, title, message, okLabel, okClass, onOk }) {
  document.getElementById('confirmIcon').innerHTML    = icon;
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMessage').textContent = message;
  const okBtn = document.getElementById('confirmOkBtn');
  okBtn.className = 'btn btn-sm ' + okClass;
  okBtn.textContent = okLabel;
  okBtn.onclick = () => {
    bootstrap.Modal.getInstance(document.getElementById('confirmModal'))?.hide();
    onOk();
  };
  new bootstrap.Modal(document.getElementById('confirmModal')).show();
}

// ── Reactivate ────────────────────────────────────────────────
function reactivateEmp() {
  showConfirm({
    icon: '<i class="bi bi-person-check-fill" style="color:#10b981;"></i>',
    title: 'Reactivate Employee',
    message: `Set ${_currentEmp.FirstName || ''} ${_currentEmp.LastName || ''} back to Active?`,
    okLabel: 'Reactivate',
    okClass: 'btn-success',
    onOk: async () => {
      const fd = new FormData();
      fd.append('_action', 'reactivate');
      fd.append('FileNo',  _currentEmp.FileNo);
      try {
        const res  = await fetch('employee-inactive.php', { method:'POST', body: fd });
        const json = await res.json();
        if (json.success) {
          showToast(json.message, 'success');
          bootstrap.Modal.getInstance(document.getElementById('empDetailModal'))?.hide();
          setTimeout(() => location.reload(), 800);
        } else {
          showToast(json.message || 'Reactivation failed.', 'error');
        }
      } catch {
        showToast('Network error.', 'error');
      }
    }
  });
}

// ── Delete ────────────────────────────────────────────────────
function deleteEmp() {
  showConfirm({
    icon: '<i class="bi bi-trash-fill" style="color:#ef4444;"></i>',
    title: 'Delete Employee Record',
    message: `Permanently delete ${_currentEmp.FirstName || ''} ${_currentEmp.LastName || ''}? This cannot be undone.`,
    okLabel: 'Delete',
    okClass: 'btn-danger',
    onOk: async () => {
      const fd = new FormData();
      fd.append('_action', 'delete');
      fd.append('FileNo',  _currentEmp.FileNo);
      try {
        const res  = await fetch('employee-inactive.php', { method:'POST', body: fd });
        const json = await res.json();
        if (json.success) {
          showToast(json.message, 'success');
          bootstrap.Modal.getInstance(document.getElementById('empDetailModal'))?.hide();
          setTimeout(() => location.reload(), 800);
        } else {
          showToast(json.message || 'Delete failed.', 'error');
        }
      } catch {
        showToast('Network error.', 'error');
      }
    }
  });
}

// ── Print Profile ─────────────────────────────────────────────
function printProfile() {
  const emp = _currentEmp;
  const fullName = `${emp.FirstName || ''} ${emp.LastName || ''}`.trim();
  const fmt = v => v && String(v).trim() ? String(v).trim() : '—';
  const fmtD = v => fmtDate(v) || '—';

  const sections = [
    {
      title: 'Identification',
      fields: [
        ['Employee ID',    fmt(emp.EmployeeID)],
        ['File No',        fmt(emp.FileNo)],
        ['Office ID',      fmt(emp.OfficeID)],
        ['SSS Number',     fmt(emp.SSS_Number)],
        ['TIN Number',     fmt(emp.TIN_Number)],
        ['PhilHealth',     fmt(emp.Philhealth_Number)],
        ['HDMF / Pag-IBIG',fmt(emp.HDMF)],
      ]
    },
    {
      title: 'Work Information',
      fields: [
        ['Department',     fmt(emp.Department)],
        ['Position',       fmt(emp.Position_held)],
        ['Job Title',      fmt(emp.Job_tittle)],
        ['Category',       fmt(emp.Category)],
        ['Branch',         fmt(emp.Branch)],
        ['Hired Date',     fmtD(emp.Hired_date)],
        ['Separation Date',fmtD(emp.Date_Of_Seperation)],
        ['Employee Status',fmt(emp.Employee_Status)],
      ]
    },
    {
      title: 'Personal Information',
      fields: [
        ['Birth Date',   fmtD(emp.Birth_date)],
        ['Birth Place',  fmt(emp.Birth_Place)],
        ['Gender',       fmt(emp.Gender)],
        ['Civil Status', fmt(emp.Civil_Status)],
        ['Nationality',  fmt(emp.Nationality)],
        ['Religion',     fmt(emp.Religion)],
      ]
    },
    {
      title: 'Contact Information',
      fields: [
        ['Mobile',            fmt(emp.Mobile_Number)],
        ['Phone',             fmt(emp.Phone_Number)],
        ['Email',             fmt(emp.Email_Address)],
        ['Present Address',   fmt(emp.Present_Address)],
        ['Permanent Address', fmt(emp.Permanent_Address)],
      ]
    },
    {
      title: 'Emergency Contact',
      fields: [
        ['Contact Person', fmt(emp.Contact_Person)],
        ['Relationship',   fmt(emp.Relationship)],
        ['Contact Number', fmt(emp.Contact_Number_Emergency)],
      ]
    },
  ];

  let sectionsHtml = sections.map(s => `
    <div class="print-section-title">${s.title}</div>
    <div class="print-grid">
      ${s.fields.map(([l,v]) => `<div class="print-item"><label>${l}</label><span>${v}</span></div>`).join('')}
    </div>
  `).join('');

  const notesVal = fmt(emp.Notes);
  sectionsHtml += `
    <div class="print-section-title">Notes</div>
    <div style="font-size:.78rem;color:#1e293b;white-space:pre-wrap;border:1px solid #e2e8f0;padding:.5rem .75rem;border-radius:6px;">${notesVal}</div>
  `;

  const html = `
    <div class="print-profile-header">
      <div>
        <h2>${emp.LastName || ''}, ${emp.FirstName || ''} ${emp.MiddleName || ''}</h2>
        <p>${fmt(emp.Position_held)} — ${fmt(emp.Department)} | ${fmt(emp.Branch)}</p>
        <p style="color:#ef4444;font-size:.75rem;font-weight:600;">⚫ INACTIVE EMPLOYEE &nbsp; Printed: ${new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</p>
      </div>
    </div>
    ${sectionsHtml}
  `;

  const pa = document.getElementById('printArea');
  pa.innerHTML = html;
  pa.style.display = 'block';
  window.print();
  pa.style.display = 'none';
  pa.innerHTML = '';
}

// ── Print List ────────────────────────────────────────────────
function printList() {
  const rows = document.querySelectorAll('#mainTable tbody tr.emp-row');
  if (!rows.length) { showToast('No records to print.', 'error'); return; }

  let tableRows = '';
  rows.forEach(r => {
    let emp = {};
    try { emp = JSON.parse(r.dataset.emp); } catch {}
    const fmt = v => v && String(v).trim() ? String(v).trim() : '—';
    const fmtD = v => fmtDate(v) || '—';
    tableRows += `<tr>
      <td>${fmt(emp.LastName)}, ${fmt(emp.FirstName)}</td>
      <td>${fmt(emp.EmployeeID)}</td>
      <td>${fmt(emp.Department)}</td>
      <td>${fmt(emp.Position_held)}</td>
      <td>${fmt(emp.Branch)}</td>
      <td>${fmtD(emp.Hired_date)}</td>
      <td>${fmtD(emp.Date_Of_Seperation)}</td>
      <td>${fmt(emp.Employee_Status)}</td>
      <td>${fmt(emp.Mobile_Number) !== '—' ? fmt(emp.Mobile_Number) : fmt(emp.Phone_Number)}</td>
    </tr>`;
  });

  const html = `
    <div style="display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #1e3a5f;padding-bottom:.5rem;margin-bottom:.75rem;">
      <div>
        <h2 style="margin:0;font-size:1.1rem;color:#1e3a5f;">Inactive Employees List</h2>
        <p style="margin:.1rem 0;font-size:.75rem;color:#475569;">Printed: ${new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</p>
      </div>
      <p style="font-size:.75rem;color:#475569;margin:0;">Total: ${rows.length} record(s)</p>
    </div>
    <table class="print-list-table">
      <thead>
        <tr>
          <th>Name</th><th>Employee ID</th><th>Department</th><th>Position</th>
          <th>Branch</th><th>Hired</th><th>Separated</th><th>Status</th><th>Contact</th>
        </tr>
      </thead>
      <tbody>${tableRows}</tbody>
    </table>
  `;

  const pa = document.getElementById('printArea');
  pa.innerHTML = html;
  pa.style.display = 'block';
  window.print();
  pa.style.display = 'none';
  pa.innerHTML = '';
}
</script>
</body>
</html>