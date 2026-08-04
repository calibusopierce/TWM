<?php
/**
 * save_po_received.php
 * Handles the "Received" confirm button on technical/purchase_order.php.
 * Marks a PO as fully received in one step (no per-unit barcodes) --
 * creates a TBL_Technical_Receiving header + line rows recording what
 * was received, sets every PO line's QtyReceived to its full
 * QtyOrdered, and closes the PO.
 *
 * Expected POST fields:
 *   po_id   (int)
 *   remarks (string, optional)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$poId    = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
$remarks = trim($_POST['remarks'] ?? '');

if (!$poId) {
    echo json_encode(['error' => true, 'message' => 'Missing PO.']);
    exit;
}

$conn = getConnection();

$poStmt = sqlsrv_query($conn, "SELECT POID, PONumber, SupplierCode, Status FROM TBL_Technical_PO WHERE POID = ?", [$poId]);
$poRow  = $poStmt !== false ? sqlsrv_fetch_array($poStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$poRow) {
    echo json_encode(['error' => true, 'message' => 'Purchase order not found.']);
    closeConnection($conn);
    exit;
}
if (!in_array($poRow['Status'], ['Open', 'Partially Received'], true)) {
    echo json_encode(['error' => true, 'message' => 'This PO is already ' . $poRow['Status'] . '.']);
    closeConnection($conn);
    exit;
}

$linesStmt = sqlsrv_query($conn, "SELECT POItemID, QtyOrdered, QtyReceived FROM TBL_Technical_PO_Items WHERE POID = ?", [$poId]);
$lines = [];
if ($linesStmt !== false) {
    while ($row = sqlsrv_fetch_array($linesStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
    }
}

if (count($lines) === 0) {
    echo json_encode(['error' => true, 'message' => 'This PO has no line items.']);
    closeConnection($conn);
    exit;
}

sqlsrv_begin_transaction($conn);

$headerSql = "INSERT INTO TBL_Technical_Receiving (POID, SupplierCode, DateReceived, Remarks, DateTimeInput)
              VALUES (?, ?, GETDATE(), ?, GETDATE());
              SELECT SCOPE_IDENTITY() AS NewID;";
$headerStmt = sqlsrv_query($conn, $headerSql, [$poId, $poRow['SupplierCode'], $remarks]);

if ($headerStmt === false) {
    $errMsg = print_r(sqlsrv_errors(), true);
    sqlsrv_rollback($conn);
    echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
    exit;
}

sqlsrv_next_result($headerStmt);
sqlsrv_fetch($headerStmt);
$receivingId = (int)sqlsrv_get_field($headerStmt, 0);

$receivingNumber = 'RCV-' . str_pad($receivingId, 5, '0', STR_PAD_LEFT);
sqlsrv_query($conn, "UPDATE TBL_Technical_Receiving SET ReceivingNumber = ? WHERE ReceivingID = ?", [$receivingNumber, $receivingId]);

foreach ($lines as $line) {
    $ordered  = (float)$line['QtyOrdered'];
    $received = (float)$line['QtyReceived'];
    $remaining = max(0, $ordered - $received);

    if ($remaining <= 0) {
        continue; // this line was already fully received earlier
    }

    $riStmt = sqlsrv_query(
        $conn,
        "INSERT INTO TBL_Technical_Receiving_Items (ReceivingID, POItemID, QtyReceived) VALUES (?, ?, ?)",
        [$receivingId, $line['POItemID'], $remaining]
    );

    if ($riStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    sqlsrv_query($conn, "UPDATE TBL_Technical_PO_Items SET QtyReceived = QtyOrdered WHERE POItemID = ?", [$line['POItemID']]);
}

sqlsrv_query($conn, "UPDATE TBL_Technical_PO SET Status = 'Closed' WHERE POID = ?", [$poId]);

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'PO marked as received.', 'receiving_number' => $receivingNumber]);
