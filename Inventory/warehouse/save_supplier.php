<?php
/**
 * save_supplier.php
 * Handles the "Add New Supplier" form (supplier.php modal).
 * Inserts a row into dbo.TBL_Item_Supplier. Responds as JSON since
 * it's called via fetch() from assets/js/app.js.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed.']);
    exit;
}

$supplierCode = trim($_POST['supplier_code'] ?? '');
$supplierName = trim($_POST['supplier_name'] ?? '');
$department   = trim($_POST['department'] ?? '');
$category     = trim($_POST['category'] ?? '') ?: 'Principal'; // default keeps new suppliers visible in the list
$status       = (isset($_POST['status']) && $_POST['status'] === 'Active') ? 1 : 0;

if ($supplierCode === '' || $supplierName === '') {
    echo json_encode(['error' => true, 'message' => 'Supplier Code and Supplier Name are required.']);
    exit;
}

$conn = getConnection();

// Optional image upload — TBL_Item_Supplier.Image is an [image]/binary column.
$imageStream = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $imageStream = fopen($_FILES['image']['tmp_name'], 'rb');
}

$sql = "INSERT INTO TBL_Item_Supplier (SupplierCode, SupplierName, Department, Category, Status, Image, UpdateDateTime)
        VALUES (?, ?, ?, ?, ?, ?, GETDATE())";

$params = [
    $supplierCode,
    $supplierName,
    $department,
    $category,
    $status,
    [$imageStream, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY), SQLSRV_SQLTYPE_IMAGE],
];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($imageStream) {
    fclose($imageStream);
}

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    exit;
}

closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Supplier saved.']);
