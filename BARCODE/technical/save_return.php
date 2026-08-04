<?php
/**
 * save_return.php
 * Records a (partial or full) return against a release.
 *
 * POST fields:
 *   release_id  (int)
 *   lines_json  JSON array of {release_item_id, qty_returned}
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

$releaseId = isset($_POST['release_id']) ? (int)$_POST['release_id'] : 0;
$lines     = json_decode($_POST['lines_json'] ?? '[]', true);

if (!$releaseId) {
    echo json_encode(['error' => true, 'message' => 'Missing release ID.']);
    exit;
}
if (!is_array($lines) || count($lines) === 0) {
    echo json_encode(['error' => true, 'message' => 'No return quantities provided.']);
    exit;
}

$conn = getConnection();
sqlsrv_begin_transaction($conn);

foreach ($lines as $line) {
    $itemId     = isset($line['release_item_id']) ? (int)$line['release_item_id'] : 0;
    $qtyReturn  = is_numeric($line['qty_returned'] ?? '') ? (float)$line['qty_returned'] : 0;
    if (!$itemId || $qtyReturn <= 0) continue;

    // Cap at QtyReleased so you can't return more than was released
    $capSql = "SELECT QtyReleased, QtyReturned FROM TBL_Technical_Release_Items
               WHERE ReleaseItemID = ? AND ReleaseID = ?";
    $capStmt = sqlsrv_query($conn, $capSql, [$itemId, $releaseId]);
    $capRow  = $capStmt !== false ? sqlsrv_fetch_array($capStmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$capRow) continue;

    $maxReturn = max(0, (float)$capRow['QtyReleased'] - (float)$capRow['QtyReturned']);
    $qtyReturn = min($qtyReturn, $maxReturn);
    if ($qtyReturn <= 0) continue;

    sqlsrv_query($conn,
        "UPDATE TBL_Technical_Release_Items
         SET QtyReturned = QtyReturned + ?
         WHERE ReleaseItemID = ? AND ReleaseID = ?",
        [$qtyReturn, $itemId, $releaseId]);
}

// Determine new release status
$statusStmt = sqlsrv_query($conn,
    "SELECT SUM(QtyReleased) AS TotalReleased, SUM(QtyReturned) AS TotalReturned
     FROM TBL_Technical_Release_Items WHERE ReleaseID = ?",
    [$releaseId]);
$statusRow = $statusStmt !== false ? sqlsrv_fetch_array($statusStmt, SQLSRV_FETCH_ASSOC) : null;

$newStatus = 'Open';
if ($statusRow) {
    $totalReleased = (float)($statusRow['TotalReleased'] ?? 0);
    $totalReturned = (float)($statusRow['TotalReturned'] ?? 0);
    if ($totalReturned >= $totalReleased && $totalReleased > 0) {
        $newStatus = 'Returned';
    } elseif ($totalReturned > 0) {
        $newStatus = 'Partial';
    }
}

sqlsrv_query($conn,
    "UPDATE TBL_Technical_Release SET Status = ? WHERE ReleaseID = ?",
    [$newStatus, $releaseId]);

sqlsrv_commit($conn);
closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Return saved.', 'new_status' => $newStatus]);
