<?php
/**
 * get_po.php
 * Returns a single PO's header and line items, for the Edit modal's
 * prefill (see po_form.js).
 *
 * GET params:
 *   id (int) -- POID
 *
 * Response: { error, po: {...}, lines: [{...}] }
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

$poId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$poId) {
    echo json_encode(['error' => true, 'message' => 'Missing PO.']);
    exit;
}

$conn = getConnection();

$poStmt = sqlsrv_query($conn, "SELECT POID, PONumber, SupplierCode, Department, Status, Remarks, Discount, Tax, SubTotal, Total FROM TBL_Technical_PO WHERE POID = ?", [$poId]);
$po = $poStmt !== false ? sqlsrv_fetch_array($poStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$po) {
    echo json_encode(['error' => true, 'message' => 'Purchase order not found.']);
    closeConnection($conn);
    exit;
}

$linesStmt = sqlsrv_query($conn, "SELECT POItemID, Category, ItemDescription, Brand, Model, Unit, Condition, TrackingMethod, QtyOrdered, QtyReceived, UnitCost, UnitBarcode FROM TBL_Technical_PO_Items WHERE POID = ? ORDER BY POItemID ASC", [$poId]);
$lines = [];
if ($linesStmt !== false) {
    while ($row = sqlsrv_fetch_array($linesStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
    }
}

closeConnection($conn);

echo json_encode(['error' => false, 'po' => $po, 'lines' => $lines]);