<?php
/**
 * save_release.php
 * Creates a new release from available stock.
 *
 * POST fields:
 *   department   (string)
 *   released_to  (string)
 *   remarks      (string)
 *   lines_json   JSON array of {item_description, qty_released, remarks}
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

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
            VALUES (?, ?, ?, ?, ?, 0, ?)";

foreach ($lines as $line) {
    $poItemId  = isset($line['po_item_id']) ? (int)$line['po_item_id'] : 0;
    $desc      = trim($line['item_description'] ?? '');
    $condition = trim($line['condition']        ?? 'Brand New');
    $qty       = is_numeric($line['qty_released'] ?? '') ? (float)$line['qty_released'] : 0;
    $lineRmk   = trim($line['remarks'] ?? '');

    if ($desc === '' || $qty <= 0) continue;

    $lineStmt = sqlsrv_query($conn, $lineSql, [$releaseId, $poItemId ?: null, $desc, $condition, $qty, $lineRmk]);
    if ($lineStmt === false) {
        $errMsg = print_r(sqlsrv_errors(), true);
        sqlsrv_rollback($conn);
        echo json_encode(['error' => true, 'message' => $errMsg ?: 'Database error while saving.']);
        exit;
    }
}

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode([
    'error'          => false,
    'message'        => 'Release saved.',
    'release_number' => $releaseNumber,
]);
