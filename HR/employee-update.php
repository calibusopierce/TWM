<?php
// ══════════════════════════════════════════════════════════════
//  HR/employee-update.php  — AJAX save endpoint
//  Called by: employee-list.php (btnSave → fetch POST FormData)
// ══════════════════════════════════════════════════════════════
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Intercept any stray output from includes before responding ─
// (PHP notices/warnings from included files would break JSON)
$userType = $_SESSION['UserType'] ?? '';

// Check session BEFORE includes that might redirect
if (empty($userType)) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh and log in again.']);
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

// Discard anything printed by includes (warnings, notices, etc.)
ob_end_clean();
header('Content-Type: application/json');

// ── RBAC check (JSON-safe — no SweetAlert redirect) ────────────
// ── RBAC check ─────────────────────────────────────────────────
rbac_load_permissions($pdo, $userType);
if (!rbac_can('employee_list')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ── Read FormData (POST) ───────────────────────────────────────
// employee-list.php sends FormData (not JSON), so we read $_POST
$fileNo = isset($_POST['FileNo']) && $_POST['FileNo'] !== '' ? (int)$_POST['FileNo'] : null;
$empId  = isset($_POST['EmployeeID']) && $_POST['EmployeeID'] !== '' ? trim($_POST['EmployeeID']) : null;

if (!$fileNo && !$empId) {
    echo json_encode(['success' => false, 'message' => 'Missing employee identifier (FileNo or EmployeeID).']);
    exit;
}

// ── Fields allowed to be updated ──────────────────────────────
// FIX: FileNo is the IDENTITY (auto-increment) PK — never include it in SET.
//      EmployeeID is the natural key used in the WHERE fallback — also excluded.
//      OfficeID removed from stringFields; if it is NOT an identity column in
//      your schema, add it back. If unsure, keep it out to avoid the error.
$stringFields = [
    'EmployeeID1',
    'SSS_Number', 'TIN_Number', 'Philhealth_Number', 'HDMF',
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

// ── Hard block: identity/PK columns must NEVER appear in SET ──
// This is a safety net in case the form ever sends these field names.
$identityColumns = ['FileNo', 'EmployeeID'];

$setClauses = [];
$params     = [];

foreach ($stringFields as $field) {
    // Skip identity/PK columns regardless of what the form sends
    if (in_array($field, $identityColumns, true)) continue;
    if (!isset($_POST[$field])) continue;
    $v = trim($_POST[$field]);
    $setClauses[] = "[{$field}] = ?";
    $params[]     = ($v === '') ? null : $v;
}

foreach ($dateFields as $field) {
    if (in_array($field, $identityColumns, true)) continue;
    if (!isset($_POST[$field])) continue;
    $v = trim($_POST[$field]);
    // Validate YYYY-MM-DD format; discard anything malformed
    if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $setClauses[] = "[{$field}] = ?";
        $params[]     = $v;
    } else {
        $setClauses[] = "[{$field}] = ?";
        $params[]     = null;
    }
}

if (empty($setClauses)) {
    echo json_encode(['success' => false, 'message' => 'Nothing to update.']);
    exit;
}

// ── Build WHERE — prefer FileNo (integer PK), fall back to EmployeeID ──
if ($fileNo) {
    $params[] = $fileNo;
    $whereSql = "WHERE FileNo = ?";
} else {
    $params[] = $empId;
    $whereSql = "WHERE EmployeeID = ?";
}

$sql  = "UPDATE [dbo].[TBL_HREmployeeList] SET " . implode(', ', $setClauses) . " {$whereSql}";
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    $errors = sqlsrv_errors();
    $msg    = $errors[0]['message'] ?? 'SQL error.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

sqlsrv_free_stmt($stmt);
echo json_encode(['success' => true, 'message' => 'Employee record updated successfully.']);