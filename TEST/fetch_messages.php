<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── RBAC ──────────────────────────────────────────────────────
$userType = $_SESSION['UserType'] ?? '';
rbac_load_permissions($pdo, $userType);
if (!in_array($userType, rbac_superadmin_roles()) && !rbac_can('message_user')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$currentUser = (int) $_SESSION['UserID'];
$otherUser   = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if ($otherUser <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid user']);
    exit;
}

if ($otherUser === $currentUser) {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot load conversation with yourself']);
    exit;
}

// Verify target user exists and is active
$checkSql  = "SELECT u.id FROM users u
              INNER JOIN TBL_HREmployeeList e ON u.EmployeeID = e.EmployeeID
              WHERE u.id = ? AND e.Active = 1";
$checkStmt = sqlsrv_query($conn, $checkSql, [$otherUser]);
if (!$checkStmt || !sqlsrv_fetch_array($checkStmt)) {
    sqlsrv_free_stmt($checkStmt);
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}
sqlsrv_free_stmt($checkStmt);

// Mark incoming messages from this user as read
$updateSql = "UPDATE Messages SET IsRead = 1
              WHERE ReceiverID = ? AND SenderID = ? AND IsRead = 0";
$updateStmt = sqlsrv_query($conn, $updateSql, [$currentUser, $otherUser]);
if ($updateStmt) sqlsrv_free_stmt($updateStmt);

// Optional incremental load — only fetch messages after last_id
$lastIdParam = isset($_GET['last_id']) ? (int) $_GET['last_id'] : 0;

$sql = "
    SELECT
        m.MessageID,
        m.SenderID,
        m.MessageText,
        m.DateSent,
        e.FirstName + ' ' + e.LastName AS SenderName
    FROM Messages m
    INNER JOIN users u ON m.SenderID = u.id
    INNER JOIN TBL_HREmployeeList e ON u.EmployeeID = e.EmployeeID
    WHERE (
        (m.SenderID = ? AND m.ReceiverID = ?)
        OR (m.SenderID = ? AND m.ReceiverID = ?)
    )
";

$params = [$currentUser, $otherUser, $otherUser, $currentUser];

if ($lastIdParam > 0) {
    $sql     .= " AND m.MessageID > ?";
    $params[] = $lastIdParam;
}

$sql .= " ORDER BY m.DateSent ASC";

$stmt = sqlsrv_query($conn, $sql, $params);

if (!$stmt) {
    $errors = sqlsrv_errors();
    http_response_code(500);
    echo json_encode(['error' => 'Query failed: ' . ($errors[0]['message'] ?? 'Unknown error')]);
    exit;
}

$messages = [];
$lastId   = $lastIdParam; // don't regress below what the caller already has

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $dateSent = $row['DateSent'];
    if ($dateSent instanceof DateTime) {
        $dateStr = $dateSent->format('Y-m-d H:i:s');
    } elseif (is_string($dateSent)) {
        $dateStr = $dateSent;
    } else {
        $dateStr = date('Y-m-d H:i:s');
    }

    $msgId = (int) $row['MessageID'];
    if ($msgId > $lastId) $lastId = $msgId;

    $messages[] = [
        'id'          => $msgId,
        'sender'      => (int) $row['SenderID'],
        'text'        => $row['MessageText'],
        'date'        => $dateStr,
        'sender_name' => $row['SenderName'],
        'type'        => ((int)$row['SenderID'] === $currentUser) ? 'sent' : 'received',
    ];
}

sqlsrv_free_stmt($stmt);

echo json_encode(['messages' => $messages, 'lastId' => $lastId]);