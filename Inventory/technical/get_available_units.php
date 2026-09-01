<?php
/**
 * get_available_units.php
 * Returns every unit barcode for a PO line (Individual Unit Tracking
 * items only) that isn't currently checked out on an open/partial
 * release. Powers the "Select Units" checklist on the Create Release
 * modal.
 *
 * GET params:
 *   po_item_id (int)
 *
 * Response: { error, units: [{POItemUnitID, UnitBarcode}] }
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

$poItemId = isset($_GET['po_item_id']) ? (int)$_GET['po_item_id'] : 0;

if (!$poItemId) {
    echo json_encode(['error' => true, 'message' => 'Missing PO item.']);
    exit;
}

$conn = getConnection();

$sql = "SELECT u.POItemUnitID, u.UnitBarcode
        FROM TBL_Technical_PO_Item_Units u
        WHERE u.POItemID = ?
          AND NOT EXISTS (
              SELECT 1
              FROM TBL_Technical_Release_Item_Units riu
              JOIN TBL_Technical_Release_Items ri ON ri.ReleaseItemID = riu.ReleaseItemID
              JOIN TBL_Technical_Release rl ON rl.ReleaseID = ri.ReleaseID
              WHERE riu.POItemUnitID = u.POItemUnitID
                AND riu.ReturnedFlag = 0
                AND rl.Status IN ('Open', 'Partial')
          )
        ORDER BY u.UnitBarcode ASC";

$stmt = sqlsrv_query($conn, $sql, [$poItemId]);

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
