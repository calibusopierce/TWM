<?php
/**
 * save_po.php
 * Handles the Create/Edit PO form on technical/purchase_order.php.
 *
 * Create (no po_id in POST): inserts a new TBL_Technical_PO header +
 * TBL_Technical_PO_Items lines.
 *
 * Edit (po_id present): only allowed while the PO's Status is still
 * 'Open' (nothing has been received against it yet) — enforced here
 * server-side regardless of what the client sends, since editing
 * quantities after receiving has started would desync QtyReceived.
 * On edit, existing lines are replaced wholesale with the new set.
 *
 * Expected POST fields:
 *   po_id         (int, optional -- presence means "edit")
 *   supplier_code (string)
 *   department    (string)
 *   remarks       (string)
 *   discount      (numeric, %)
 *   tax           (numeric, %)
 *   sub_total     (numeric)
 *   total         (numeric)
 *   lines_json    (JSON string) -- array of {category, brand, model, description, unit, qty, cost, condition, tracking_method}
 *                 tracking_method is 'Individual Unit Tracking' or 'Quantity-Based'
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$poId         = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;
$supplierCode = trim($_POST['supplier_code'] ?? '');
$department   = trim($_POST['department']    ?? '');
$remarks      = trim($_POST['remarks']       ?? '');
$discount     = is_numeric($_POST['discount'] ?? '') ? (float)$_POST['discount'] : 0;
$tax          = is_numeric($_POST['tax']      ?? '') ? (float)$_POST['tax']      : 0;
$subTotal     = is_numeric($_POST['sub_total'] ?? '') ? (float)$_POST['sub_total'] : 0;
$total        = is_numeric($_POST['total']     ?? '') ? (float)$_POST['total']     : 0;
$lines        = json_decode($_POST['lines_json'] ?? '[]', true);

if ($supplierCode === '' || $department === '') {
    echo json_encode(['error' => true, 'message' => 'Supplier and Department are required.']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['error' => true, 'message' => 'Add at least one line item.']);
    exit;
}

$conn = getConnection();
sqlsrv_begin_transaction($conn);

if ($poId) {
    // ---- EDIT: only allowed while still Open ----
    $checkStmt = sqlsrv_query($conn, "SELECT Status FROM TBL_Technical_PO WHERE POID = ?", [$poId]);
    $checkRow  = $checkStmt !== false ? sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC) : null;

    if (!$checkRow) {
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => 'Purchase order not found.']);
        exit;
    }
    if ($checkRow['Status'] !== 'Open') {
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => "This PO can no longer be edited -- receiving has already started against it."]);
        exit;
    }

    $updateSql = "UPDATE TBL_Technical_PO
                  SET SupplierCode = ?, Department = ?, Remarks = ?, Discount = ?, Tax = ?, SubTotal = ?, Total = ?
                  WHERE POID = ?";
    $updateStmt = sqlsrv_query($conn, $updateSql, [$supplierCode, $department, $remarks, $discount, $tax, $subTotal, $total, $poId]);

    if ($updateStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    // Replace all existing lines wholesale -- safe here since Status = 'Open'
    // means QtyReceived is 0 on every line for this PO. Clean up the
    // per-unit barcode rows first (child of the lines we're about to delete).
    $deleteUnitsStmt = sqlsrv_query(
        $conn,
        "DELETE FROM TBL_Technical_PO_Item_Units WHERE POItemID IN (SELECT POItemID FROM TBL_Technical_PO_Items WHERE POID = ?)",
        [$poId]
    );
    if ($deleteUnitsStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    $deleteStmt = sqlsrv_query($conn, "DELETE FROM TBL_Technical_PO_Items WHERE POID = ?", [$poId]);
    if ($deleteStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }
} else {
    // ---- CREATE ----
    $headerSql = "INSERT INTO TBL_Technical_PO (SupplierCode, Department, Status, Remarks, Discount, Tax, SubTotal, Total, DateTimeInput)
                  VALUES (?, ?, 'Open', ?, ?, ?, ?, ?, GETDATE());
                  SELECT SCOPE_IDENTITY() AS NewID;";
    $headerStmt = sqlsrv_query($conn, $headerSql, [$supplierCode, $department, $remarks, $discount, $tax, $subTotal, $total]);

    if ($headerStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    sqlsrv_next_result($headerStmt);
    sqlsrv_fetch($headerStmt);
    $poId = (int)sqlsrv_get_field($headerStmt, 0);

    if (!$poId) {
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => 'Could not create the PO header.']);
        exit;
    }

    $poNumber = 'PO-' . str_pad($poId, 5, '0', STR_PAD_LEFT);
    sqlsrv_query($conn, "UPDATE TBL_Technical_PO SET PONumber = ? WHERE POID = ?", [$poNumber, $poId]);
}

$lineSql = "INSERT INTO TBL_Technical_PO_Items
            (POID, Category, ItemDescription, Brand, Model, Unit, Condition, TrackingMethod, QtyOrdered, QtyReceived, UnitCost)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?);
            SELECT SCOPE_IDENTITY() AS NewID;";

$unitSql = "INSERT INTO TBL_Technical_PO_Item_Units (POItemID, UnitBarcode, DateTimeInput) VALUES (?, ?, GETDATE())";

foreach ($lines as $line) {
    $category       = trim($line['category']    ?? '');
    $description    = trim($line['description'] ?? '');
    $brand          = trim($line['brand']        ?? '');
    $model          = trim($line['model']        ?? '');
    $unit           = trim($line['unit']         ?? '');
    $condition      = trim($line['condition']    ?? 'Brand New');
    $trackingMethod = trim($line['tracking_method'] ?? 'Quantity-Based');
    $qty            = is_numeric($line['qty']  ?? '') ? (float)$line['qty']  : 0;
    $cost           = is_numeric($line['cost'] ?? '') ? (float)$line['cost'] : 0;

    if (!in_array($trackingMethod, ['Individual Unit Tracking', 'Quantity-Based'], true)) {
        $trackingMethod = 'Quantity-Based';
    }

    if ($description === '' || $qty <= 0) {
        continue; // skip malformed lines rather than failing the whole PO
    }

    $lineStmt = sqlsrv_query($conn, $lineSql, [$poId, $category, $description, $brand, $model, $unit, $condition, $trackingMethod, $qty, $cost]);

    if ($lineStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    sqlsrv_next_result($lineStmt);
    sqlsrv_fetch($lineStmt);
    $poItemId = (int)sqlsrv_get_field($lineStmt, 0);

    if (!$poItemId) {
        continue;
    }

    // Every line gets one representative barcode (used by Release to scan
    // and identify this line), format UTC{POItemID}-01 regardless of
    // tracking method.
    $lineBarcode = 'UTC' . $poItemId . '-01';
    sqlsrv_query($conn, "UPDATE TBL_Technical_PO_Items SET UnitBarcode = ? WHERE POItemID = ?", [$lineBarcode, $poItemId]);

    // Individual Unit Tracking: generate one barcode per physical unit
    // expected (UTC{POItemID}-01 .. -NN). Unit 1 intentionally matches
    // the line's own UnitBarcode above, so nothing is duplicated.
    // Quantity-Based: no per-unit rows at all -- just the bulk qty above.
    if ($trackingMethod === 'Individual Unit Tracking') {
        $padWidth = max(2, strlen((string)(int)$qty));
        for ($seq = 1; $seq <= (int)$qty; $seq++) {
            $unitBarcode = 'UTC' . $poItemId . '-' . str_pad($seq, $padWidth, '0', STR_PAD_LEFT);
            $unitStmt = sqlsrv_query($conn, $unitSql, [$poItemId, $unitBarcode]);

            if ($unitStmt === false) {
                $errMsg = print_r(sqlsrv_errors(), true);
                sqlsrv_rollback($conn);
                echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
                exit;
            }
        }
    }
}

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Purchase order saved.']);