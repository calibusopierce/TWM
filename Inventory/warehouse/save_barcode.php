<?php
/**
 * save_barcode.php
 * Updates the barcode columns on a single item in dbo.Tbl_Item_Products.
 * Called via fetch() from assets/js/items_scan.js when the user saves
 * scanned barcodes from the scan panel.
 *
 * Expected POST fields:
 *   item_id    (int)    — ItemID of the row to update
 *   bc_cs      (string) — BarcodeCs  (leave empty to keep existing value)
 *   bc_bg      (string) — BarcodeBg
 *   bc_pc      (string) — BarcodePc
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

$itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;

if (!$itemId) {
    echo json_encode(['error' => true, 'message' => 'Invalid item ID.']);
    exit;
}

$bcCs  = trim($_POST['bc_cs']  ?? '');
$bcBg  = trim($_POST['bc_bg']  ?? '');
$bcPc  = trim($_POST['bc_pc']  ?? '');

// Build a partial UPDATE — only overwrite columns that were actually
// submitted (non-empty). This way existing barcodes aren't wiped if
// the user only scanned one field.
$setClauses = [];
$params     = [];

if ($bcCs !== '')  { $setClauses[] = 'BarcodeCs  = ?'; $params[] = $bcCs; }
if ($bcBg !== '')  { $setClauses[] = 'BarcodeBg  = ?'; $params[] = $bcBg; }
if ($bcPc !== '')  { $setClauses[] = 'BarcodePc  = ?'; $params[] = $bcPc; }

if (empty($setClauses)) {
    echo json_encode(['error' => true, 'message' => 'No barcodes were provided.']);
    exit;
}

$params[] = $itemId; // for the WHERE clause

$sql = 'UPDATE Tbl_Item_Products SET ' . implode(', ', $setClauses) . ' WHERE ItemID = ?';

$conn = getConnection();
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$rowsAffected = sqlsrv_rows_affected($stmt);
closeConnection($conn);

if ($rowsAffected === 0) {
    echo json_encode(['error' => true, 'message' => 'Item not found or nothing changed.']);
    exit;
}

echo json_encode([
    'error'   => false,
    'message' => 'Barcodes saved.',
    'item_id' => $itemId,
    'saved'   => [
        'BarcodeCs'  => $bcCs  ?: null,
        'BarcodeBg'  => $bcBg  ?: null,
        'BarcodePc'  => $bcPc  ?: null,
    ]
]);
