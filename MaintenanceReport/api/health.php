<?php
/**
 * api/health.php
 * Simple "can we reach the database" check used by the dashboard's
 * connection status indicator.
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

$conn = getConnection();
$stmt = sqlsrv_query($conn, "SELECT 1 AS ok");

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'detail' => print_r(sqlsrv_errors(), true)]);
} else {
    echo json_encode(['status' => 'connected']);
}

closeConnection($conn);
