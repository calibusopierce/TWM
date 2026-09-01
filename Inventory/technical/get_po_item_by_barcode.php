<?php
/**
 * get_po_item_by_barcode.php
 * Looks up a TBL_Technical_PO_Items line by barcode, for the Create
 * Release modal's scan-to-identify workflow. Accepts EITHER:
 *   - a line's own UnitBarcode (Quantity-Based items scan this), or
 *   - one of that line's individual unit barcodes, from
 *     TBL_Technical_PO_Item_Units (Individual Unit Tracking items)
 * Either way, it resolves back to the same PO line/item.
 *
 * Available is computed differently depending on TrackingMethod:
 *   Individual Unit Tracking -> count of that line's unit barcodes
 *     that aren't currently checked out on an open/partial release
 *   Quantity-Based -> (units received) - (units released, net of
 *     returns, on open/partial releases)
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

// Resolve the barcode to a POItemID -- either the line's own barcode,
// or one of its individual unit barcodes.
$resolveSql = "SELECT POItemID FROM TBL_Technical_PO_Items WHERE UnitBarcode = ?
               UNION
               SELECT POItemID FROM TBL_Technical_PO_Item_Units WHERE UnitBarcode = ?";
$resolveStmt = sqlsrv_query($conn, $resolveSql, [$barcode, $barcode]);
$resolveRow  = $resolveStmt !== false ? sqlsrv_fetch_array($resolveStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$resolveRow) {
    echo json_encode(['error' => false, 'found' => false]);
    closeConnection($conn);
    exit;
}

$poItemId = (int)$resolveRow['POItemID'];

$sql = "SELECT
    pi.POItemID,
    pi.UnitBarcode,
    pi.ItemDescription,
    pi.Category,
    pi.Brand,
    pi.Model,
    pi.Condition                                        AS ItemCondition,
    COALESCE(pi.TrackingMethod, 'Quantity-Based')       AS TrackingMethod,
    pi.POID,
    po.PONumber
FROM TBL_Technical_PO_Items pi
JOIN TBL_Technical_PO po ON po.POID = pi.POID
WHERE pi.POItemID = ?";

$stmt = sqlsrv_query($conn, $sql, [$poItemId]);
$row  = $stmt !== false ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

if (!$row) {
    echo json_encode(['error' => false, 'found' => false]);
    closeConnection($conn);
    exit;
}

if ($row['TrackingMethod'] === 'Individual Unit Tracking') {
    $totalUnitsStmt = sqlsrv_query($conn, "SELECT COUNT(*) AS Cnt FROM TBL_Technical_PO_Item_Units WHERE POItemID = ?", [$poItemId]);
    $totalUnits = $totalUnitsStmt !== false ? (int)sqlsrv_fetch_array($totalUnitsStmt, SQLSRV_FETCH_ASSOC)['Cnt'] : 0;

    $checkedOutStmt = sqlsrv_query($conn, "
        SELECT COUNT(*) AS Cnt
        FROM TBL_Technical_Release_Item_Units riu
        JOIN TBL_Technical_Release_Items ri ON ri.ReleaseItemID = riu.ReleaseItemID
        JOIN TBL_Technical_Release rl ON rl.ReleaseID = ri.ReleaseID
        JOIN TBL_Technical_PO_Item_Units u ON u.POItemUnitID = riu.POItemUnitID
        WHERE u.POItemID = ?
          AND riu.ReturnedFlag = 0
          AND rl.Status IN ('Open', 'Partial')
    ", [$poItemId]);
    $checkedOut = $checkedOutStmt !== false ? (int)sqlsrv_fetch_array($checkedOutStmt, SQLSRV_FETCH_ASSOC)['Cnt'] : 0;

    $available = max(0, $totalUnits - $checkedOut);
} else {
    $receivedStmt = sqlsrv_query($conn, "SELECT SUM(QtyReceived) AS Total FROM TBL_Technical_Receiving_Items WHERE POItemID = ?", [$poItemId]);
    $totalReceived = $receivedStmt !== false ? (float)(sqlsrv_fetch_array($receivedStmt, SQLSRV_FETCH_ASSOC)['Total'] ?? 0) : 0;

    $releasedStmt = sqlsrv_query($conn, "
        SELECT SUM(rli.QtyReleased - COALESCE(rli.QtyReturned, 0)) AS Total
        FROM TBL_Technical_Release_Items rli
        JOIN TBL_Technical_Release rl ON rl.ReleaseID = rli.ReleaseID
        WHERE rli.POItemID = ? AND rl.Status IN ('Open', 'Partial')
    ", [$poItemId]);
    $totalReleased = $releasedStmt !== false ? (float)(sqlsrv_fetch_array($releasedStmt, SQLSRV_FETCH_ASSOC)['Total'] ?? 0) : 0;

    $available = max(0, (int)$totalReceived - (int)$totalReleased);
}

closeConnection($conn);

echo json_encode([
    'error' => false,
    'found' => true,
    'item'  => [
        'POItemID'        => (int)$row['POItemID'],
        'UnitBarcode'      => $row['UnitBarcode'],
        'ItemDescription'  => $row['ItemDescription'],
        'Category'         => $row['Category'],
        'Brand'            => $row['Brand'],
        'Model'            => $row['Model'],
        'ItemCondition'    => $row['ItemCondition'],
        'TrackingMethod'   => $row['TrackingMethod'],
        'PONumber'         => $row['PONumber'],
        'Available'        => $available,
    ],
]);
