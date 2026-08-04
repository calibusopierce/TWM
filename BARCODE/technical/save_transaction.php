<?php
/**
 * save_transaction.php
 * Handles the action panel on technical/stocks.php. Updates the
 * item's current Department/AssignedTo/ItemStatus in TBL_Technical_Items
 * and writes a row to TBL_Technical_Transactions as an audit trail.
 *
 * Expected POST fields:
 *   item_id       (int)
 *   action_type   (string) — 'assign' | 'repair' | 'return' | 'retire'
 *   to_department (string) — only used for 'assign'
 *   to_assigned_to (string) — only used for 'assign'
 *   remarks       (string)
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

$itemId       = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$actionType   = trim($_POST['action_type']    ?? '');
$toDepartment = trim($_POST['to_department']  ?? '');
$toAssignedTo = trim($_POST['to_assigned_to'] ?? '');
$remarks      = trim($_POST['remarks']        ?? '');

$validActions = ['assign', 'repair', 'return', 'retire'];

if (!$itemId || !in_array($actionType, $validActions, true)) {
    echo json_encode(['error' => true, 'message' => 'Missing or invalid item/action.']);
    exit;
}

if ($actionType === 'assign' && $toDepartment === '' && $toAssignedTo === '') {
    echo json_encode(['error' => true, 'message' => 'Pick a department or a person to assign this item to.']);
    exit;
}

$conn = getConnection();

// Pull the item's current state first, both to confirm it exists and
// to record where it's moving FROM in the transaction log.
$current = sqlsrv_query(
    $conn,
    "SELECT ItemID, Barcode, Department, AssignedTo, ItemStatus FROM TBL_Technical_Items WHERE ItemID = ?",
    [$itemId]
);
$row = $current !== false ? sqlsrv_fetch_array($current, SQLSRV_FETCH_ASSOC) : null;

if (!$row) {
    echo json_encode(['error' => true, 'message' => 'Item not found.']);
    closeConnection($conn);
    exit;
}

$fromDepartment = $row['Department'];
$fromAssignedTo = $row['AssignedTo'];

// Work out the new state based on the action taken.
switch ($actionType) {
    case 'assign':
        $newDepartment = $toDepartment !== '' ? $toDepartment : $fromDepartment;
        $newAssignedTo = $toAssignedTo;
        $newItemStatus = 'Assigned';
        $newStatus     = 1;
        break;
    case 'repair':
        $newDepartment = $fromDepartment;
        $newAssignedTo = $fromAssignedTo;
        $newItemStatus = 'Under Repair';
        $newStatus     = 1;
        break;
    case 'return':
        $newDepartment = $fromDepartment;
        $newAssignedTo = null;
        $newItemStatus = 'In Stock';
        $newStatus     = 1;
        break;
    case 'retire':
        $newDepartment = $fromDepartment;
        $newAssignedTo = $fromAssignedTo;
        $newItemStatus = 'Retired';
        $newStatus     = 0; // also flips the existing Active/Inactive badge in the Items list
        break;
}

$updateSql = "UPDATE TBL_Technical_Items
              SET Department = ?, AssignedTo = ?, ItemStatus = ?, Status = ?
              WHERE ItemID = ?";
$updateStmt = sqlsrv_query($conn, $updateSql, [$newDepartment, $newAssignedTo, $newItemStatus, $newStatus, $itemId]);

if ($updateStmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$logSql = "INSERT INTO TBL_Technical_Transactions
           (ItemID, Barcode, ActionType, FromDepartment, ToDepartment, FromAssignedTo, ToAssignedTo, Remarks, DateTimeInput)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

$actionLabels = [
    'assign' => 'Assign',
    'repair' => 'Under Repair',
    'return' => 'Return to Stock',
    'retire' => 'Retire',
];

sqlsrv_query($conn, $logSql, [
    $itemId,
    $row['Barcode'],
    $actionLabels[$actionType],
    $fromDepartment,
    $newDepartment,
    $fromAssignedTo,
    $newAssignedTo,
    $remarks,
]);

closeConnection($conn);

echo json_encode(['error' => false, 'message' => 'Transaction saved.', 'new_status' => $newItemStatus]);
