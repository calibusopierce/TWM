<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

auth_check();
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

header('Content-Type: application/json');

if (!rbac_can('cash_advance_record')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
// Must be FULL access, not view_only, to mark as received
rbac_enforce_full_access('cash_advance_record', true);

$id = $_POST['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit;
}

$checkSql = "SELECT Status, ApprovedByID FROM TBL_CashAdvance WHERE CashAdvanceID = ?";
$checkStmt = sqlsrv_query($conn, $checkSql, [$id]);
$current = ($checkStmt !== false) ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$current) {
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    exit;
}

if ($current['Status'] !== 'Approved') {
    echo json_encode(['success' => false, 'message' => 'Only requests with status "Approved" can be marked as received.']);
    exit;
}

// Only the same person who approved this request is allowed to mark it received
if (trim((string)$current['ApprovedByID']) !== trim((string)$_SESSION['EmployeeID'])) {
    echo json_encode([
        'success' => false,
        'message' => 'DEBUG mismatch — ApprovedByID: [' . $current['ApprovedByID'] . '] SessionEmployeeID: [' . $_SESSION['EmployeeID'] . ']'
    ]);
    exit;
}

$sql = "UPDATE TBL_CashAdvance
        SET Status = 'Received',
            ReceivedDate = GETDATE(),
            ModifiedBy = ?,
            ModifiedDate = GETDATE()
        WHERE CashAdvanceID = ?";

$params = [$_SESSION['UserID'], $id];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . print_r(sqlsrv_errors(), true)]);
    exit;
}

echo json_encode(['success' => true]);
