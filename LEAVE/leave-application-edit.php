<?php
/* =====================================================================
   leave-application-edit.php
   File location: TWM/LEAVE/leave-application-edit.php
   RBAC module key: leave_application

   Edits an existing leave application. Only the original applicant may
   edit, and only while BOTH SA_Status and HR_Status are still Pending —
   enforced server-side regardless of what the UI shows.
   ===================================================================== */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_application', 'full');
ob_end_clean();

header('Content-Type: application/json');
rbac_csrf_verify();

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

define('LEAVE_STATUS_PENDING', 0);

$employeeID = $_SESSION['EmployeeID'] ?? '';

if (!$employeeID)                          respond(false, 'Session expired. Please log in again.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Invalid request method.');

$id = (int)($_POST['id'] ?? 0);
if (!$id) respond(false, 'Missing application ID.');

/* ---------------- ownership + editability check ---------------- */
$checkStmt = $pdo->prepare("SELECT EmployeeID, SA_Status, HR_Status, Attachment
                             FROM dbo.Tbl_Leave_Application
                             WHERE ID = :id");
$checkStmt->execute(['id' => $id]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

if (!$existing)                                       respond(false, 'Application not found.');
if ($existing['EmployeeID'] !== $employeeID)          respond(false, 'You are not authorized to edit this application.');
if ((int)$existing['SA_Status'] !== LEAVE_STATUS_PENDING || (int)$existing['HR_Status'] !== LEAVE_STATUS_PENDING) {
    respond(false, 'This application has already been acted on and can no longer be edited.');
}

/* ---------------- validate input (same rules as filing) ---------------- */
$typeID     = (int)($_POST['TypeID'] ?? 0);
$saEmpID    = trim($_POST['SA_EmployeeID'] ?? '');
$hrEmpID    = trim($_POST['HR_EmployeeID'] ?? '');
$dateStart  = trim($_POST['Date_Start'] ?? '');
$dateEnd    = trim($_POST['Date_End'] ?? '');
$numDays    = (float)($_POST['NumberOfDays'] ?? 0);
$halfDay    = isset($_POST['HalfDay']) ? 1 : 0;
$reason     = trim($_POST['ReasonOfLeave'] ?? '');

if (!$typeID)                 respond(false, 'Please select a leave type.');
if (!$saEmpID)                respond(false, 'Please select a supervisor.');
if (!$hrEmpID)                respond(false, 'Please select an HR reviewer.');
if (!$dateStart || !$dateEnd) respond(false, 'Please provide both start and end dates.');
if (strtotime($dateEnd) < strtotime($dateStart)) respond(false, 'End date cannot be before start date.');
if ($numDays <= 0)            respond(false, 'Invalid number of days.');
if (!$reason)                 respond(false, 'Please provide a reason for leave.');
if ($saEmpID === $employeeID) respond(false, 'You cannot select yourself as the approving supervisor.');
if ($hrEmpID === $employeeID) respond(false, 'You cannot select yourself as the HR reviewer.');

$typeCheckStmt = $pdo->prepare("SELECT Requires_Attachment FROM dbo.Tbl_Leave_Type WHERE ID = :id");
$typeCheckStmt->execute(['id' => $typeID]);
$typeRow = $typeCheckStmt->fetch(PDO::FETCH_ASSOC);
$requiresAttachment = $typeRow && (int)$typeRow['Requires_Attachment'] === 1;

/* ---------------- attachment (optional replace) ---------------- */
$attachmentPath = $existing['Attachment']; // keep existing unless a new one is uploaded
$oldAttachmentToDelete = null;

if (!empty($_FILES['Attachment']['name'])) {
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $ext = strtolower(pathinfo($_FILES['Attachment']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt))               respond(false, 'Attachment must be PDF, JPG, PNG, DOC, or DOCX.');
    if ($_FILES['Attachment']['size'] > 5 * 1024 * 1024) respond(false, 'Attachment must be under 5MB.');

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/uploads/leave_attachments/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $safeName = 'LV_' . $employeeID . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['Attachment']['tmp_name'], $destPath)) {
        respond(false, 'Failed to upload attachment. Please try again.');
    }

    $oldAttachmentToDelete = $attachmentPath; // only deleted after a successful DB update
    $attachmentPath = 'uploads/leave_attachments/' . $safeName;
}

if ($requiresAttachment && !$attachmentPath) {
    respond(false, 'This leave type requires an attachment.');
}

/* ---------------- update (WHERE re-checks pending status atomically) ---------------- */
$sql = "UPDATE dbo.Tbl_Leave_Application
        SET TypeID = :typeId, NumberOfDays = :numDays, Date_Start = :dateStart,
            Date_End = :dateEnd, HalfDay = :halfDay, ReasonOfLeave = :reason,
            Attachment = :attachment, SA_EmployeeID = :saEmpId, HR_EmployeeID = :hrEmpId
        WHERE ID = :id AND EmployeeID = :employeeId
          AND SA_Status = " . LEAVE_STATUS_PENDING . " AND HR_Status = " . LEAVE_STATUS_PENDING;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'typeId' => $typeID, 'numDays' => $numDays, 'dateStart' => $dateStart, 'dateEnd' => $dateEnd,
    'halfDay' => $halfDay, 'reason' => $reason, 'attachment' => $attachmentPath,
    'saEmpId' => $saEmpID, 'hrEmpId' => $hrEmpID, 'id' => $id, 'employeeId' => $employeeID,
]);

if ($stmt->rowCount() === 0) {
    // Someone acted on it between our check and the UPDATE — roll back any new upload.
    if (!empty($_FILES['Attachment']['name'])) {
        @unlink($_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/' . $attachmentPath);
    }
    respond(false, 'This application was just acted on and can no longer be edited.');
}

if ($oldAttachmentToDelete) {
    @unlink($_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/' . $oldAttachmentToDelete);
}

respond(true, 'Leave application updated successfully.');