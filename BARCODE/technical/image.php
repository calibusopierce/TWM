<?php
/**
 * image.php
 * Streams the [Image] binary column from TBL_Technical_Supplier for a
 * given supplier ID. Used as the src of the thumbnail in the supplier
 * list, e.g. <img src="image.php?id=10">
 */
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    http_response_code(400);
    exit;
}

$conn = getConnection();

$sql = "SELECT Image FROM TBL_Technical_Supplier WHERE ID = ?";
$stmt = sqlsrv_query($conn, $sql, [$id]);

if ($stmt === false || !sqlsrv_fetch($stmt)) {
    http_response_code(404);
    exit;
}

$stream = sqlsrv_get_field($stmt, 0, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY));

if ($stream === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
fpassthru($stream);

closeConnection($conn);
