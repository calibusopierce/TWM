<?php
/**
 * get_next_barcode.php
 * Returns a preview of the next barcode in the TWM888-XX series for
 * the Register Item modal. This is a PREVIEW only -- save_item.php
 * independently (and safely) recomputes the real value inside a
 * locked transaction right before insert, so this endpoint never has
 * to be the single source of truth.
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
require_once __DIR__ . '/../includes/technical_lookups.php';

$conn = getConnection();
$barcode = getNextTechnicalAssetBarcode($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'barcode' => $barcode]);