<?php
/**
 * save_release.php
 * Creates a new release from available stock.
 *
 * POST fields:
 *   department   (string)
 *   released_to  (string)
 *   remarks      (string)
 *   lines_json   JSON array of {po_item_id, item_description, condition,
 *                tracking_method, qty_released, remarks, unit_barcodes?}
 *                unit_barcodes is only present/used for lines where
 *                tracking_method is 'Individual Unit Tracking' -- its
 *                length must match qty_released.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth_guard.php';

if (!isset($_SESSION['inventory_type'])) {
    http_response_code(440);
    echo json_encode(['error' => true, 'message' => 'Session expired.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$department  = trim($_POST['department']  ?? '');
$releasedTo  = trim($_POST['released_to'] ?? '');
$remarks     = trim($_POST['remarks']     ?? '');
$lines       = json_decode($_POST['lines_json'] ?? '[]', true);

if ($department === '' || $releasedTo === '') {
    echo json_encode(['error' => true, 'message' => 'Department and Released To are required.']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['error' => true, 'message' => 'Add at least one line item.']);
    exit;
}

$conn = getConnection();

// ---- Validate every Individual Unit Tracking line BEFORE writing anything ----
$allBarcodesInBatch = [];

foreach ($lines as $line) {
    $trackingMethod = trim($line['tracking_method'] ?? 'Quantity-Based');
    if ($trackingMethod !== 'Individual Unit Tracking') {
        continue;
    }

    $poItemId = isset($line['po_item_id']) ? (int)$line['po_item_id'] : 0;
    $qty      = is_numeric($line['qty_released'] ?? '') ? (int)$line['qty_released'] : 0;
    $barcodes = is_array($line['unit_barcodes'] ?? null) ? $line['unit_barcodes'] : [];

    if (count($barcodes) !== $qty) {
        echo json_encode(['error' => true, 'message' => 'Selected unit count does not match quantity for one of the lines.']);
        closeConnection($conn);
        exit;
    }

    foreach ($barcodes as $bc) {
        $bc = trim($bc);
        if ($bc === '') {
            echo json_encode(['error' => true, 'message' => 'One of the selected units is blank.']);
            closeConnection($conn);
            exit;
        }
        if (in_array($bc, $allBarcodesInBatch, true)) {
            echo json_encode(['error' => true, 'message' => 'Unit "' . $bc . '" was selected more than once in this release.']);
            closeConnection($conn);
            exit;
        }
        $allBarcodesInBatch[] = $bc;

        // Confirm the unit belongs to this PO item and isn't already checked out.
        $unitCheck = sqlsrv_query(
            $conn,
            "SELECT u.POItemUnitID
             FROM TBL_Technical_PO_Item_Units u
             WHERE u.UnitBarcode = ? AND u.POItemID = ?
               AND NOT EXISTS (
                   SELECT 1 FROM TBL_Technical_Release_Item_Units riu
                   JOIN TBL_Technical_Release_Items ri ON ri.ReleaseItemID = riu.ReleaseItemID
                   JOIN TBL_Technical_Release rl ON rl.ReleaseID = ri.ReleaseID
                   WHERE riu.POItemUnitID = u.POItemUnitID
                     AND riu.ReturnedFlag = 0
                     AND rl.Status IN ('Open','Partial')
               )",
            [$bc, $poItemId]
        );
        $unitRow = $unitCheck !== false ? sqlsrv_fetch_array($unitCheck, SQLSRV_FETCH_ASSOC) : null;

        if (!$unitRow) {
            echo json_encode(['error' => true, 'message' => 'Unit "' . $bc . '" is not available for this item.']);
            closeConnection($conn);
            exit;
        }
    }
}

// ---- All valid -- now write everything, inside a transaction ----
sqlsrv_begin_transaction($conn);

// Insert header
$headerSql = "INSERT INTO TBL_Technical_Release
              (Department, ReleasedTo, Remarks, Status, DateTimeInput)
              VALUES (?, ?, ?, 'Open', GETDATE());
              SELECT SCOPE_IDENTITY() AS NewID;";
$headerStmt = sqlsrv_query($conn, $headerSql, [$department, $releasedTo, $remarks]);

if ($headerStmt === false) {
    $errMsg = print_r(sqlsrv_errors(), true);
    sqlsrv_rollback($conn);
    echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
    exit;
}

sqlsrv_next_result($headerStmt);
sqlsrv_fetch($headerStmt);
$releaseId = (int)sqlsrv_get_field($headerStmt, 0);

if (!$releaseId) {
    sqlsrv_rollback($conn);
    echo json_encode(['error' => true, 'message' => 'Could not create release header.']);
    exit;
}

// Auto-generate release number
$releaseNumber = 'REL-' . str_pad($releaseId, 5, '0', STR_PAD_LEFT);
sqlsrv_query($conn, "UPDATE TBL_Technical_Release SET ReleaseNumber = ? WHERE ReleaseID = ?",
    [$releaseNumber, $releaseId]);

// Insert lines
$lineSql = "INSERT INTO TBL_Technical_Release_Items
            (ReleaseID, POItemID, ItemDescription, ItemCondition, QtyReleased, QtyReturned, Remarks)
            VALUES (?, ?, ?, ?, ?, 0, ?);
            SELECT SCOPE_IDENTITY() AS NewID;";

$unitSql = "INSERT INTO TBL_Technical_Release_Item_Units (ReleaseItemID, POItemUnitID, UnitBarcode, DateTimeInput)
            VALUES (?, ?, ?, GETDATE())";

foreach ($lines as $line) {
    $poItemId       = isset($line['po_item_id']) ? (int)$line['po_item_id'] : 0;
    $desc           = trim($line['item_description'] ?? '');
    $condition      = trim($line['condition']        ?? 'Brand New');
    $trackingMethod = trim($line['tracking_method']  ?? 'Quantity-Based');
    $qty            = is_numeric($line['qty_released'] ?? '') ? (float)$line['qty_released'] : 0;
    $lineRmk        = trim($line['remarks'] ?? '');
    $barcodes       = is_array($line['unit_barcodes'] ?? null) ? $line['unit_barcodes'] : [];

    if ($desc === '' || $qty <= 0) continue;

    $lineStmt = sqlsrv_query($conn, $lineSql, [$releaseId, $poItemId ?: null, $desc, $condition, $qty, $lineRmk]);
    if ($lineStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }

    if ($trackingMethod === 'Individual Unit Tracking' && count($barcodes) > 0) {
        sqlsrv_next_result($lineStmt);
        sqlsrv_fetch($lineStmt);
        $releaseItemId = (int)sqlsrv_get_field($lineStmt, 0);

        foreach ($barcodes as $bc) {
            $bc = trim($bc);
            $unitLookup = sqlsrv_query($conn, "SELECT POItemUnitID FROM TBL_Technical_PO_Item_Units WHERE UnitBarcode = ? AND POItemID = ?", [$bc, $poItemId]);
            $unitRow = $unitLookup !== false ? sqlsrv_fetch_array($unitLookup, SQLSRV_FETCH_ASSOC) : null;

            if (!$unitRow) {
                $errMsg = 'Unit "' . $bc . '" could not be matched during save.';
                sqlsrv_rollback($conn);
                echo json_encode(['error' => true, 'message' => $errMsg]);
                exit;
            }

            $unitStmt = sqlsrv_query($conn, $unitSql, [$releaseItemId, $unitRow['POItemUnitID'], $bc]);
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

echo json_encode([
    'error'          => false,
    'message'        => 'Release saved.',
    'release_number' => $releaseNumber,
]);
