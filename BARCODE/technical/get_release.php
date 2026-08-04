<?php
/**
 * get_release.php
 * Returns a single release header + line items for the Return modal.
 * GET: id (int) — ReleaseID
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['inventory_type'])) {
    http_response_code(440);
    echo json_encode(['error' => true, 'message' => 'Session expired.']);
    exit;
}

require_once __DIR__ . '/../includes/config.php';

$releaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$releaseId) {
    echo json_encode(['error' => true, 'message' => 'Missing release ID.']);
    exit;
}

$conn = getConnection();

$hStmt = sqlsrv_query($conn,
    "SELECT ReleaseID, ReleaseNumber, Department, ReleasedTo, Status, Remarks, DateTimeInput
     FROM TBL_Technical_Release WHERE ReleaseID = ?",
    [$releaseId]);
$header = $hStmt !== false ? sqlsrv_fetch_array($hStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$header) {
    echo json_encode(['error' => true, 'message' => 'Release not found.']);
    closeConnection($conn);
    exit;
}

// Format the datetime for JSON
if ($header['DateTimeInput'] instanceof DateTime) {
    $header['DateTimeInput'] = $header['DateTimeInput']->format('M j, Y g:i A');
}

$lStmt = sqlsrv_query($conn,
    "SELECT ReleaseItemID, ItemDescription, QtyReleased, QtyReturned, Remarks
     FROM TBL_Technical_Release_Items WHERE ReleaseID = ? ORDER BY ReleaseItemID ASC",
    [$releaseId]);
$lines = [];
if ($lStmt !== false) {
    while ($row = sqlsrv_fetch_array($lStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
    }
}

closeConnection($conn);
echo json_encode(['error' => false, 'release' => $header, 'lines' => $lines]);
