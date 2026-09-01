<?php
/**
 * get_brand_category.php
 * Returns the brands and categories on file for a given supplier,
 * so the "Create New Item" modal can cascade Brand Name / Category
 * once a Supplier Code is picked.
 *
 * GET params:
 *   supplier_code (string) — value from TBL_Item_Supplier.SupplierCode
 *
 * Response: { brands: [{value,label}], categories: [{value,label}] }
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
require_once __DIR__ . '/../includes/item_lookups.php';

$supplierCode = trim($_GET['supplier_code'] ?? '');

if ($supplierCode === '') {
    echo json_encode(['error' => false, 'brands' => [], 'categories' => []]);
    exit;
}

$conn = getConnection();

$brandRows    = getBrandsForSupplier($conn, $supplierCode);
$categoryRows = getCategoriesForSupplier($conn, $supplierCode);

closeConnection($conn);

$brands = array_map(function ($b) {
    return ['value' => $b['BrandName'], 'label' => $b['BrandName']];
}, $brandRows);

$categories = array_map(function ($c) {
    return ['value' => $c['CategoryName'], 'label' => $c['CategoryName']];
}, $categoryRows);

echo json_encode([
    'error'      => false,
    'brands'     => array_values($brands),
    'categories' => array_values($categories),
]);
