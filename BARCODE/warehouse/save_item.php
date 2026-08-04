<?php
/**
 * save_item.php
 * Handles the "Create New Item" modal on items.php — inserts a new
 * row into dbo.Tbl_Item_Products.
 *
 * Expected POST fields (see the form in warehouse/items.php):
 *   department, item_code, item_name,
 *   supplier_code, brand_name, category,
 *   qty_case, uom_case, qty_bag, uom_bag, qty_pieces, uom_pieces,
 *   barcode_case, barcode_bag, barcode_pieces
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

$department   = trim($_POST['department']   ?? '');
$itemCode     = trim($_POST['item_code']    ?? '');
$itemName     = trim($_POST['item_name']    ?? '');
$supplierCode = trim($_POST['supplier_code'] ?? '');
$brandName    = trim($_POST['brand_name']   ?? '');
$category     = trim($_POST['category']     ?? '');

$qtyCase    = is_numeric($_POST['qty_case']    ?? '') ? (float)$_POST['qty_case']    : 0;
$qtyBag     = is_numeric($_POST['qty_bag']     ?? '') ? (float)$_POST['qty_bag']     : 0;
$qtyPieces  = is_numeric($_POST['qty_pieces']  ?? '') ? (float)$_POST['qty_pieces']  : 0;

$uomCase    = trim($_POST['uom_case']    ?? '');
$uomBag     = trim($_POST['uom_bag']     ?? '');
$uomPieces  = trim($_POST['uom_pieces']  ?? '');

$barcodeCase   = trim($_POST['barcode_case']   ?? '');
$barcodeBag    = trim($_POST['barcode_bag']    ?? '');
$barcodePieces = trim($_POST['barcode_pieces'] ?? '');

if ($itemCode === '' || $itemName === '') {
    echo json_encode(['error' => true, 'message' => 'Item Code and Item Name are required.']);
    exit;
}

$conn = getConnection();

// Guard against duplicate ItemCode before inserting.
$checkSql  = "SELECT ItemID FROM Tbl_Item_Products WHERE ItemCode = ?";
$checkStmt = sqlsrv_query($conn, $checkSql, [$itemCode]);

if ($checkStmt !== false && sqlsrv_fetch($checkStmt)) {
    echo json_encode(['error' => true, 'message' => 'That item code already exists.']);
    closeConnection($conn);
    exit;
}

$sql = "INSERT INTO Tbl_Item_Products
        (ItemCode, ItemDescription, Department, SupplierCode, BrandName, Category,
         QtyCs, QtyBg, QtyPc, UOMCs, UOMBg, UOMPc,
         BarcodeCs, BarcodeBg, BarcodePc,
         Active, Status, DateTimeInput)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, GETDATE())";

$params = [
    $itemCode, $itemName, $department, $supplierCode, $brandName, $category,
    $qtyCase, $qtyBag, $qtyPieces, $uomCase, $uomBag, $uomPieces,
    $barcodeCase, $barcodeBag, $barcodePieces,
];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Item saved.', 'item_code' => $itemCode]);
