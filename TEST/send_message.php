<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── RBAC check ────────────────────────────────────────────────
$userType = $_SESSION['UserType'] ?? '';
rbac_load_permissions($pdo, $userType);
if (!in_array($userType, rbac_superadmin_roles()) && !rbac_can('message_user')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$sender   = (int) $_SESSION['UserID'];
$receiver = isset($_POST['receiver_id']) ? (int) $_POST['receiver_id'] : 0;
$message  = isset($_POST['message'])     ? trim($_POST['message'])      : '';

if ($receiver <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid receiver']);
    exit;
}

if ($sender === $receiver) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot send a message to yourself']);
    exit;
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty']);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long (max 2000 characters)']);
    exit;
}

// Verify receiver exists and is active
$checkSql  = "SELECT u.id FROM users u
              INNER JOIN TBL_HREmployeeList e ON u.EmployeeID = e.EmployeeID
              WHERE u.id = ? AND e.Active = 1";
$checkStmt = sqlsrv_query($conn, $checkSql, [$receiver]);
if (!$checkStmt || !sqlsrv_fetch_array($checkStmt)) {
    sqlsrv_free_stmt($checkStmt);
    http_response_code(404);
    echo json_encode(['error' => 'Recipient not found or inactive']);
    exit;
}
sqlsrv_free_stmt($checkStmt);

$sql  = "INSERT INTO Messages (SenderID, ReceiverID, MessageText, IsRead) VALUES (?, ?, ?, 0)";
$stmt = sqlsrv_query($conn, $sql, [$sender, $receiver, $message]);

if (!$stmt) {
    error_log('Message insert failed: ' . print_r(sqlsrv_errors(), true));
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
    exit;
}

sqlsrv_free_stmt($stmt);

echo json_encode(['success' => true]);