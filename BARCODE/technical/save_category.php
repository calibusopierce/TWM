<?php
/**
 * save_category.php
 * Handles the "Add New Category" form (technical/category.php modal).
 * Inserts a row into dbo.TBL_Technical_Category. Responds as JSON since
 * it's called via fetch() from assets/js/app.js.
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

$categoryCode = trim($_POST['category_code'] ?? '');
$categoryName = trim($_POST['category_name'] ?? '');
$status       = (isset($_POST['status']) && $_POST['status'] === 'Active') ? 1 : 0;

if ($categoryCode === '' || $categoryName === '') {
    echo json_encode(['error' => true, 'message' => 'Category Code and Category Name are required.']);
    exit;
}

$conn = getConnection();

// Category Code is used in lookups/barcodes going forward — reject duplicates.
$checkSql  = "SELECT ID FROM TBL_Technical_Category WHERE CategoryCode = ?";
$checkStmt = sqlsrv_query($conn, $checkSql, [$categoryCode]);

if ($checkStmt !== false && sqlsrv_fetch($checkStmt)) {
    echo json_encode(['error' => true, 'message' => 'That category code is already in use.']);
    closeConnection($conn);
    exit;
}

$sql = "INSERT INTO TBL_Technical_Category (CategoryCode, CategoryName, Status, DateTimeInput)
        VALUES (?, ?, ?, GETDATE())";

$stmt = sqlsrv_query($conn, $sql, [$categoryCode, $categoryName, $status]);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Category saved.']);
