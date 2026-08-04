<?php
/**
 * get_item_units.php
 * Returns every individual barcoded unit registered under a given
 * item name, for the Stocks page's "View" action (pick a specific
 * unit before seeing its full details).
 *
 * GET params:
 *   item_name (string)
 *
 * Response: { error, units: [{ItemID, Barcode, ItemStatus, Department, AssignedTo, Condition}] }
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['inventory_type'])) {
    http_response_code(440);
    echo json_encode(['error' => true, 'message' => 'Session expired. Please pick an inventory again.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$itemName = trim($_GET['item_name'] ?? '');

if ($itemName === '') {
    echo json_encode(['error' => true, 'message' => 'Missing item name.']);
    exit;
}

$conn = getConnection();

$sql = "SELECT ItemID, Barcode, ItemStatus, Department, AssignedTo, Condition
        FROM TBL_Technical_Items
        WHERE ItemName = ? AND (Active = 1 OR Active IS NULL)
        ORDER BY DateTimeInput DESC";

$stmt = sqlsrv_query($conn, $sql, [$itemName]);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$units = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $units[] = $row;
}

closeConnection($conn);

echo json_encode(['error' => false, 'units' => $units]);
