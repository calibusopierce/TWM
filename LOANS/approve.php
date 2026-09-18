<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

if (rbac_is_view_only('employee_loans')) {
    header("Location: " . base_url('EMPLOYEE/index.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . base_url('EMPLOYEE/index.php'));
    exit;
}

$loan_id = (int)($_POST['loan_id'] ?? 0);
if (!$loan_id) {
    header("Location: " . base_url('EMPLOYEE/index.php?error=invalid'));
    exit;
}

// Where to send the user back to (view.php sends ?return=view, index.php has no param -> defaults to index)
$return_to = ($_POST['return'] ?? '') === 'view'
    ? base_url('EMPLOYEE/view.php?id=' . $loan_id)
    : base_url('EMPLOYEE/index.php');

// ── Fetch current status — approval is ONLY valid from Proposal ────────────
$chk = sqlsrv_query($conn, "SELECT LoanID, ReferenceNumber, Status, ApprovedByID FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
$loan = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;

if (!$loan) {
    header("Location: " . base_url('EMPLOYEE/index.php?error=not_found'));
    exit;
}

if (trim($loan['Status'] ?? '') !== 'Proposal') {
    // Already approved/paid/cancelled — nothing to do, this isn't a valid transition.
    header("Location: $return_to?error=invalid_status");
    exit;
}

$user = $_SESSION['EmployeeID'] ?? $_SESSION['Username'] ?? 'system';

// Only flips Status -> Approved and stamps who/when.
// Does NOT touch ApprovedByID's identity selection — Noted By / Approved By
// are expected to already be set (e.g. at creation), per the agreed design.
$upd = sqlsrv_query($conn, "
    UPDATE TBL_Loan SET
        Status = 'Approved',
        UserUpdate = ?, UpdateDateTime = GETDATE()
    WHERE LoanID = ? AND Status = 'Proposal'
", [$user, $loan_id]);

if ($upd === false) {
    header("Location: $return_to?error=update_failed");
    exit;
}

header("Location: $return_to?approved=1");
exit;