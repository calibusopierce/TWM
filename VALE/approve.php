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
// Must be FULL access, not view_only, to approve
rbac_enforce_full_access('cash_advance_record', true);

$id = $_POST['id'] ?? null;

if (!$id || !ctype_digit((string)$id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit;
}

// Only allow approving records currently in Requested status
$checkSql = "SELECT Status FROM TBL_CashAdvance WHERE CashAdvanceID = ?";
$checkStmt = sqlsrv_query($conn, $checkSql, [$id]);
$current = ($checkStmt !== false) ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$current) {
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    exit;
}

if ($current['Status'] !== 'Requested') {
    echo json_encode(['success' => false, 'message' => 'Only requests with status "Requested" can be approved.']);
    exit;
}

$sql = "UPDATE TBL_CashAdvance
        SET Status = 'Approved',
            ApprovedByID = ?,
            ApprovedDate = GETDATE(),
            ModifiedBy = ?,
            ModifiedDate = GETDATE()
        WHERE CashAdvanceID = ?";

$params = [$_SESSION['EmployeeID'], $_SESSION['UserID'], $id];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
    exit;
}

echo json_encode(['success' => true]);
