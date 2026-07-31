<?php
/* =====================================================================
   leave-type-save.php
   File location: TWM/LEAVE/leave-type-save.php
   RBAC module key: leave_management

   Add/edit endpoint for dbo.Tbl_Leave_Type. If ID is present in the
   POST body, updates that row; otherwise inserts a new leave type.
   ===================================================================== */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_management', 'full');
ob_end_clean();

header('Content-Type: application/json');
rbac_csrf_verify();

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

$username = $_SESSION['Username'] ?? ($_SESSION['EmployeeID'] ?? '');

$id               = (int)($_POST['ID'] ?? 0);
$code             = trim($_POST['Code'] ?? '');
$typeName         = trim($_POST['Type_Name'] ?? '');
$category         = trim($_POST['Category'] ?? '');
$withPay          = isset($_POST['With_Pay']) ? 1 : 0;
$requiresAttach   = isset($_POST['Requires_Attachment']) ? 1 : 0;
$maxDaysPerYear   = $_POST['Max_Days_Per_Year'] !== '' ? (float)$_POST['Max_Days_Per_Year'] : null;
$carryForward     = isset($_POST['Carry_Forward']) ? 1 : 0;

// Regular_Credit is BIT in the DB (yes/no flag), not a day count —
// the form now sends it as a checkbox.
$regularCredit    = isset($_POST['Regular_Credit']) ? 1 : 0;

// Status is BIT (1 = Active, 0 = Inactive); the <select> sends text.
$statusRaw        = trim($_POST['Status'] ?? 'Active');
$status           = (strtolower($statusRaw) === 'active') ? 1 : 0;

if (!$code)     respond(false, 'Code is required.');
if (!$typeName) respond(false, 'Type Name is required.');

try {
    // Uniqueness check on Code (excluding the current row when editing)
    $dupSql = "SELECT COUNT(*) AS Cnt FROM dbo.Tbl_Leave_Type WHERE Code = :code AND ID <> :id";
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute(['code' => $code, 'id' => $id]);
    if ((int)$dupStmt->fetch(PDO::FETCH_ASSOC)['Cnt'] > 0) {
        respond(false, 'A leave type with this code already exists.');
    }

    if ($id > 0) {
        $sql = "UPDATE dbo.Tbl_Leave_Type SET
                    Code = :code, Type_Name = :typeName, Category = :category,
                    With_Pay = :withPay, Regular_Credit = :regularCredit,
                    Requires_Attachment = :requiresAttach,
                    Max_Days_Per_Year = :maxDays, Carry_Forward = :carryForward,
                    Status = :status
                WHERE ID = :id";
        $params = [
            'code' => $code, 'typeName' => $typeName, 'category' => $category,
            'withPay' => $withPay, 'regularCredit' => $regularCredit,
            'requiresAttach' => $requiresAttach, 'maxDays' => $maxDaysPerYear,
            'carryForward' => $carryForward, 'status' => $status, 'id' => $id,
        ];
    } else {
        $sql = "INSERT INTO dbo.Tbl_Leave_Type
                    (Code, Type_Name, Category, With_Pay, Regular_Credit,
                     Requires_Attachment, Max_Days_Per_Year, Carry_Forward,
                     Status, UserInput, DateTimeInput)
                VALUES
                    (:code, :typeName, :category, :withPay, :regularCredit,
                     :requiresAttach, :maxDays, :carryForward,
                     :status, :username, GETDATE())";
        $params = [
            'code' => $code, 'typeName' => $typeName, 'category' => $category,
            'withPay' => $withPay, 'regularCredit' => $regularCredit,
            'requiresAttach' => $requiresAttach, 'maxDays' => $maxDaysPerYear,
            'carryForward' => $carryForward, 'status' => $status, 'username' => $username,
        ];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    respond(true, 'Leave type saved successfully.');
} catch (PDOException $e) {
    // TEMP DEBUG — remove once the root cause is confirmed and fixed.
    // Surfacing the real driver error instead of a generic message so we
    // can see exactly what SQL Server is rejecting (column type mismatch,
    // constraint violation, etc).
    error_log('leave-type-save.php PDOException: ' . $e->getMessage());
    respond(false, 'Database error while saving the leave type.', [
        'debug' => $e->getMessage(),
    ]);
}