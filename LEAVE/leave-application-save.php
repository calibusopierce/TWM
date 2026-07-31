<?php
/* =====================================================================
   leave-application-save.php
   File location: TWM/LEAVE/leave-application-save.php
   RBAC module key: leave_application

   Handles submission of a new leave application (Step 1 of the workflow).
   Uses the confirmed real bootstrap + PDO ($pdo).
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
define('LEAVE_STATUS_APPROVED', 1);
define('LEAVE_STATUS_REJECTED', 2);

$employeeID = $_SESSION['EmployeeID'] ?? '';
$username   = $_SESSION['Username'] ?? $employeeID;

if (!$employeeID) {
    respond(false, 'Session expired. Please log in again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

/* ---------------- validate input ---------------- */
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

// Requires_Attachment check against the actual leave type row
$typeCheckStmt = $pdo->prepare("SELECT Requires_Attachment FROM dbo.Tbl_Leave_Type WHERE ID = :id");
$typeCheckStmt->execute(['id' => $typeID]);
$typeRow = $typeCheckStmt->fetch(PDO::FETCH_ASSOC);
if ($typeRow && (int)$typeRow['Requires_Attachment'] === 1 && empty($_FILES['Attachment']['name'])) {
    respond(false, 'This leave type requires an attachment.');
}

/* ---------------- attachment upload (optional) ---------------- */
$attachmentPath = null;
if (!empty($_FILES['Attachment']['name'])) {
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $ext = strtolower(pathinfo($_FILES['Attachment']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExt)) {
        respond(false, 'Attachment must be PDF, JPG, PNG, DOC, or DOCX.');
    }
    if ($_FILES['Attachment']['size'] > 5 * 1024 * 1024) {
        respond(false, 'Attachment must be under 5MB.');
    }

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/uploads/leave_attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeName = 'LV_' . $employeeID . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $safeName;

    if (!move_uploaded_file($_FILES['Attachment']['tmp_name'], $destPath)) {
        respond(false, 'Failed to upload attachment. Please try again.');
    }

    $attachmentPath = 'uploads/leave_attachments/' . $safeName;
}

/* ---------------- generate control number + insert (race-safe) ---------------- */
// Pattern: LV-YYYYMM-#### (sequence resets monthly). Adjust to match
// whatever control-number convention other forms use.
//
// COUNT(*)-based numbering lets two concurrent submissions read the same
// count before either commits, producing duplicate ControlNo values. Instead:
//   1. Derive the next number from MAX(existing suffix) + 1, inside a
//      transaction with an UPDLOCK/HOLDLOCK hint so concurrent transactions
//      serialize on this prefix rather than both reading a stale value.
//   2. As a second line of defense (covers races even without the lock hint
//      taking effect, or a pre-existing unique index), catch a duplicate-key
//      error on insert and retry with a fresh number a few times.
$prefix = 'LV-' . date('Ym') . '-';
$maxAttempts = 5;
$newId = null;
$controlNo = null;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $pdo->beginTransaction();

        // UPDLOCK+HOLDLOCK forces concurrent callers to wait for this
        // transaction to finish before they can read the same prefix range.
        $seqStmt = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING(ControlNo, LEN(:prefix2) + 1, 10) AS INT)) AS MaxSeq
             FROM dbo.Tbl_Leave_Application WITH (UPDLOCK, HOLDLOCK)
             WHERE ControlNo LIKE :prefix"
        );
        $seqStmt->execute(['prefix' => $prefix . '%', 'prefix2' => $prefix]);
        $seqRow = $seqStmt->fetch(PDO::FETCH_ASSOC);
        $nextSeq = (int)($seqRow['MaxSeq'] ?? 0) + 1;
        $controlNo = $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

        $insertSql = "INSERT INTO dbo.Tbl_Leave_Application
                        (ControlNo, TypeID, EmployeeID, NumberOfDays, DateFiled,
                         Date_Start, Date_End, HalfDay, ReasonOfLeave, Attachment,
                         SA_EmployeeID, SA_Status, HR_EmployeeID, HR_Status, UserInput, DateTimeInput)
                      OUTPUT INSERTED.ID
                      VALUES
                        (:controlNo, :typeId, :employeeId, :numDays, GETDATE(),
                         :dateStart, :dateEnd, :halfDay, :reason, :attachment,
                         :saEmpId, :saStatus, :hrEmpId, :hrStatus, :username, GETDATE())";

        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
            'controlNo'  => $controlNo,
            'typeId'     => $typeID,
            'employeeId' => $employeeID,
            'numDays'    => $numDays,
            'dateStart'  => $dateStart,
            'dateEnd'    => $dateEnd,
            'halfDay'    => $halfDay,
            'reason'     => $reason,
            'attachment' => $attachmentPath,
            'saEmpId'    => $saEmpID,
            'saStatus'   => LEAVE_STATUS_PENDING,
            'hrEmpId'    => $hrEmpID,
            'hrStatus'   => LEAVE_STATUS_PENDING,
            'username'   => $username,
        ]);

        $newRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $newId = $newRow['ID'] ?? null;

        $pdo->commit();
        break; // success — stop retrying

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // 23000 = integrity constraint violation (e.g. unique index on
        // ControlNo). Retry with a freshly computed number; anything else
        // is a real failure, so give up immediately.
        $isDuplicate = ($e->getCode() === '23000');
if (!$isDuplicate || $attempt === $maxAttempts) {
    if ($attachmentPath && file_exists($_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/' . $attachmentPath)) {
        unlink($_SERVER['DOCUMENT_ROOT'] . '/TWM/LEAVE/' . $attachmentPath);
    }
    respond(false, $e->getCode() . ': ' . $e->getMessage());
}
        // else: loop again for another attempt
    }
}

respond(true, 'Leave application submitted successfully.', [
    'id'        => $newId,
    'controlNo' => $controlNo,
]);