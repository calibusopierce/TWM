<?php
/**
 * save_item.php
 * Handles the "Register New Item" modal on technical/items.php —
 * inserts a new row into dbo.TBL_Technical_Items.
 *
 * Barcode is auto-generated here (TWM888-01, TWM888-02, ...), not
 * taken from the client. The form shows a live preview
 * (get_next_barcode.php) so the user sees roughly what they'll get,
 * but the real value is computed fresh inside a locked transaction
 * right before the INSERT, so two people submitting at the same
 * moment can never collide on the same number.
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
require_once __DIR__ . '/../includes/technical_lookups.php';

$itemName      = trim($_POST['item_name']      ?? '');
$category      = trim($_POST['category']       ?? '');
$brand         = trim($_POST['brand']           ?? '');
$model         = trim($_POST['model']           ?? '');
$supplierCode  = trim($_POST['supplier_code']   ?? '');
$cost          = is_numeric($_POST['cost'] ?? '') ? (float)$_POST['cost'] : null;
$dateAcquired  = trim($_POST['date_acquired']   ?? '');
$remarks       = trim($_POST['remarks']         ?? '');
$status        = (isset($_POST['status']) && $_POST['status'] === 'Active') ? 1 : 0;

if ($itemName === '') {
    echo json_encode(['error' => true, 'message' => 'Item Name is required.']);
    exit;
}

$conn = getConnection();

// Optional image upload — TBL_Technical_Items.Image is an [image]/binary column.
$imageStream = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $imageStream = fopen($_FILES['image']['tmp_name'], 'rb');
}

$dateAcquiredParam = $dateAcquired !== '' ? $dateAcquired : null;

sqlsrv_begin_transaction($conn);

// Lock the range of matching rows while we read the current max, so a
// second concurrent submit has to wait its turn rather than reading
// the same "next" number.
$prefixLen = strlen(TECH_ASSET_BARCODE_PREFIX);
$lockSql = "SELECT MAX(TRY_CAST(SUBSTRING(Barcode, ?, 50) AS INT)) AS MaxNum
            FROM TBL_Technical_Items WITH (UPDLOCK, HOLDLOCK)
            WHERE Barcode LIKE ?";
$lockStmt = sqlsrv_query($conn, $lockSql, [$prefixLen + 1, TECH_ASSET_BARCODE_PREFIX . '%']);

$maxNum = 0;
if ($lockStmt !== false && ($row = sqlsrv_fetch_array($lockStmt, SQLSRV_FETCH_ASSOC))) {
    $maxNum = (int)($row['MaxNum'] ?? 0);
}

$barcode = TECH_ASSET_BARCODE_PREFIX . str_pad($maxNum + 1, 2, '0', STR_PAD_LEFT);

$sql = "INSERT INTO TBL_Technical_Items
        (Barcode, ItemName, Category, Brand, Model,
         SupplierCode, Cost, DateAcquired,
         Image, Remarks, Active, Status, ItemStatus, DateTimeInput)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 'In Stock', GETDATE())";

$params = [
    $barcode, $itemName, $category, $brand, $model,
    $supplierCode, $cost, $dateAcquiredParam,
    [$imageStream, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_IMAGE],
    $remarks, $status,
];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($imageStream) {
    fclose($imageStream);
}

if ($stmt === false) {
    sqlsrv_rollback($conn);
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Item registered.', 'barcode' => $barcode]);