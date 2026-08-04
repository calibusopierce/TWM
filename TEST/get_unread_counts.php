<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

header('Content-Type: application/json');

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

$currentUser = (int) $_SESSION['UserID'];

$sql = "
    SELECT SenderID, COUNT(*) AS UnreadCount
    FROM Messages
    WHERE ReceiverID = ? AND IsRead = 0
    GROUP BY SenderID
";

$stmt = sqlsrv_query($conn, $sql, [$currentUser]);

if (!$stmt) {
    // Return empty rather than crashing — the badge is non-critical UI
    error_log('get_unread_counts query failed: ' . print_r(sqlsrv_errors(), true));
    echo json_encode([]);
    exit;
}

$data = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $data[(string)$row['SenderID']] = (int) $row['UnreadCount'];
}

sqlsrv_free_stmt($stmt);

echo json_encode($data);