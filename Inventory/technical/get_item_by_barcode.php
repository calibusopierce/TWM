<?php
/**
 * get_item_by_barcode.php
 * Looks up a single item in TBL_Technical_Items by its barcode, for
 * the Stocks page's scan-to-find workflow.
 *
 * GET params:
 *   barcode (string)
 *
 * Response: { error, found, item: {...} }
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth_guard.php';

if (!isset($_SESSION['inventory_type'])) {
    http_response_code(440);
    echo json_encode(['error' => true, 'message' => 'Session expired. Please pick an inventory again.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$barcode = trim($_GET['barcode'] ?? '');

if ($barcode === '') {
    echo json_encode(['error' => false, 'found' => false]);
    exit;
}

$conn = getConnection();

$sql = "SELECT ItemID, Barcode, ItemName, Category, Brand, Model, SerialNumber,
               Department, AssignedTo, ItemStatus, Condition, Cost, Status,
               CASE WHEN Image IS NOT NULL THEN 1 ELSE 0 END AS HasImage
        FROM TBL_Technical_Items
        WHERE Barcode = ?";

$stmt = sqlsrv_query($conn, $sql, [$barcode]);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

closeConnection($conn);

if (!$item) {
    echo json_encode(['error' => false, 'found' => false]);
    exit;
}

echo json_encode(['error' => false, 'found' => true, 'item' => $item]);
