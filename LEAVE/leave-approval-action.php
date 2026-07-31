<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_approval');
ob_end_clean();

header('Content-Type: application/json');
rbac_csrf_verify();

define('LEAVE_STATUS_PENDING', 0);
define('LEAVE_STATUS_APPROVED', 1);
define('LEAVE_STATUS_REJECTED', 2);

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$employeeID = $_SESSION['EmployeeID'] ?? '';
$username   = $_SESSION['Username'] ?? $employeeID;

if (!$employeeID)                              respond(false, 'Session expired. Please log in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST')     respond(false, 'Invalid request method.');

$id     = (int)($_POST['id'] ?? 0);
$role   = trim($_POST['role'] ?? '');
$action = trim($_POST['action'] ?? '');
$note   = trim($_POST['note'] ?? '');

if (!$id)                                      respond(false, 'Missing application ID.');
if (!in_array($role, ['sa', 'hr'], true))      respond(false, 'Invalid role.');
if (!in_array($action, ['approve', 'reject'], true)) respond(false, 'Invalid action.');
if ($action === 'reject' && $note === '')      respond(false, 'A note is required when rejecting an application.');

$newStatus = ($action === 'approve') ? LEAVE_STATUS_APPROVED : LEAVE_STATUS_REJECTED;

try {
    if ($role === 'sa') {
        $sql = "UPDATE dbo.Tbl_Leave_Application
                SET SA_Status = :status, SA_Date_Approved = GETDATE(), SA_Note = :note
                WHERE ID = :id AND SA_EmployeeID = :myId AND SA_Status = " . LEAVE_STATUS_PENDING;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $newStatus,
            'note'   => $note !== '' ? $note : null,
            'id'     => $id,
            'myId'   => $employeeID,
        ]);

        if ($stmt->rowCount() === 0) {
            respond(false, 'This application is not assigned to you, or has already been processed.');
        }

    } else {
        try {
            rbac_gate($pdo, 'leave_approval', 'hr');
        } catch (Throwable $e) {
            respond(false, 'You do not have HR approval access.');
        }

        $sql = "UPDATE dbo.Tbl_Leave_Application
                SET HR_Status = :status, HR_Date_Approved = GETDATE(), HR_Note = :note
                WHERE ID = :id AND HR_EmployeeID = :myId AND HR_Status = " . LEAVE_STATUS_PENDING;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $newStatus,
            'note'   => $note !== '' ? $note : null,
            'id'     => $id,
            'myId'   => $employeeID,
        ]);

        if ($stmt->rowCount() === 0) {
            respond(false, 'This application is not assigned to you, or has already been processed.');
        }
    }

    respond(true, 'Application ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully.');

} catch (PDOException $e) {
    respond(false, 'Database error while processing this application. Please try again.');
}