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
 *   lines_json    (JSON string) -- array of {category, brand, model, description, unit, qty, cost}
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
    // means QtyReceived is 0 on every line for this PO.
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
            (POID, Category, ItemDescription, Brand, Model, Unit, Condition, QtyOrdered, QtyReceived, UnitCost)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?);
            SELECT SCOPE_IDENTITY() AS NewID;";

foreach ($lines as $line) {
    $category    = trim($line['category']    ?? '');
    $description = trim($line['description'] ?? '');
    $brand       = trim($line['brand']        ?? '');
    $model       = trim($line['model']        ?? '');
    $unit        = trim($line['unit']         ?? '');
    $condition   = trim($line['condition']    ?? 'Brand New');
    $qty         = is_numeric($line['qty']  ?? '') ? (float)$line['qty']  : 0;
    $cost        = is_numeric($line['cost'] ?? '') ? (float)$line['cost'] : 0;

    if ($description === '' || $qty <= 0) {
        continue; // skip malformed lines rather than failing the whole PO
    }

    $lineStmt = sqlsrv_query($conn, $lineSql, [$poId, $category, $description, $brand, $model, $unit, $condition, $qty, $cost]);

    if ($lineStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    // Auto-generate this line's own scannable barcode (TWM-000001),
    // based on the POItemID identity value just assigned to it.
    sqlsrv_next_result($lineStmt);
    sqlsrv_fetch($lineStmt);
    $poItemId = (int)sqlsrv_get_field($lineStmt, 0);

    if ($poItemId) {
        $itemBarcode = 'TWM-' . str_pad($poItemId, 6, '0', STR_PAD_LEFT);
        sqlsrv_query($conn, "UPDATE TBL_Technical_PO_Items SET ItemBarcode = ? WHERE POItemID = ?", [$itemBarcode, $poItemId]);
    }
}

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Purchase order saved.']);