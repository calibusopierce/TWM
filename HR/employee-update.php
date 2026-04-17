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
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

// Discard anything printed by includes (warnings, notices, etc.)
ob_end_clean();
header('Content-Type: application/json');

// ── Auth check ─────────────────────────────────────────────────
if (!in_array($userType, ['Admin', 'Administrator'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// ── Read FormData (POST) ───────────────────────────────────────
// employee-list.php sends FormData (not JSON), so we read $_POST
$fileNo    = isset($_POST['FileNo'])    && $_POST['FileNo']    !== '' ? (int)$_POST['FileNo']    : null;
$empId     = isset($_POST['EmployeeID']) && $_POST['EmployeeID'] !== '' ? trim($_POST['EmployeeID']) : null;

if (!$fileNo && !$empId) {
    echo json_encode(['success' => false, 'message' => 'Missing employee identifier (FileNo or EmployeeID).']);
    exit;
}

// ── Fields allowed to be updated ──────────────────────────────
// EmployeeID and FileNo are identifiers — never updated via this endpoint
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

$setClauses = [];
$params     = [];

foreach ($stringFields as $field) {
    if (!isset($_POST[$field])) continue;
    $v = trim($_POST[$field]);
    $setClauses[] = "[{$field}] = ?";
    $params[]     = ($v === '') ? null : $v;
}

foreach ($dateFields as $field) {
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

?>