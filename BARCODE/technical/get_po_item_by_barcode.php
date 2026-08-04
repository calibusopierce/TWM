<?php
/**
 * get_po_item_by_barcode.php
 * Looks up a single TBL_Technical_PO_Items line by its auto-generated
 * ItemBarcode (see save_po.php), for the Create Release modal's
 * scan-to-fill workflow. Also computes how many units of THIS
 * specific PO line are still available to release right now:
 *
 *   Available = (units received against this line)
 *             - (units released against this line on still-open
 *                releases, net of any already returned)
 *
 * GET params:
 *   barcode (string) -- the ItemBarcode value, not the asset Barcode
 *
 * Response: { error, found, item: {...} }
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

$barcode = trim($_GET['barcode'] ?? '');

if ($barcode === '') {
    echo json_encode(['error' => false, 'found' => false]);
    exit;
}

$conn = getConnection();

$sql = "
SELECT
    pi.POItemID,
    pi.ItemBarcode,
    pi.ItemDescription,
    pi.Category,
    pi.Brand,
    pi.Model,
    pi.Condition                                        AS ItemCondition,
    pi.POID,
    po.PONumber,
    COALESCE((
        SELECT SUM(ri.QtyReceived)
        FROM TBL_Technical_Receiving_Items ri
        WHERE ri.POItemID = pi.POItemID
    ), 0) AS TotalReceived,
    COALESCE((
        SELECT SUM(rli.QtyReleased - COALESCE(rli.QtyReturned, 0))
        FROM TBL_Technical_Release_Items rli
        JOIN TBL_Technical_Release rl ON rl.ReleaseID = rli.ReleaseID
        WHERE rli.POItemID = pi.POItemID
          AND rl.Status IN ('Open','Partial')
    ), 0) AS TotalReleased
FROM TBL_Technical_PO_Items pi
JOIN TBL_Technical_PO po ON po.POID = pi.POID
WHERE pi.ItemBarcode = ?";

$stmt = sqlsrv_query($conn, $sql, [$barcode]);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

closeConnection($conn);

if (!$row) {
    echo json_encode(['error' => false, 'found' => false]);
    exit;
}

$available = max(0, (int)$row['TotalReceived'] - (int)$row['TotalReleased']);

echo json_encode([
    'error' => false,
    'found' => true,
    'item'  => [
        'POItemID'        => (int)$row['POItemID'],
        'ItemBarcode'      => $row['ItemBarcode'],
        'ItemDescription'  => $row['ItemDescription'],
        'Category'         => $row['Category'],
        'Brand'            => $row['Brand'],
        'Model'            => $row['Model'],
        'ItemCondition'    => $row['ItemCondition'],
        'PONumber'         => $row['PONumber'],
        'Available'        => $available,
    ],
]);