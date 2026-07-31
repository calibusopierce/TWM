<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'employee_loans');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

$isAdmin = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);
if (!$isAdmin) {
    header("Location: " . base_url('EMPLOYEE/index.php?error=unauthorized'));
    exit;
}

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
    header("Location: " . base_url('EMPLOYEE/index.php'));
    exit;
}

// Verify loan exists and get ref number for the flash message
$chk = sqlsrv_query($conn, "SELECT LoanID, ReferenceNumber, Status FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);
$loan = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;

if (!$loan) {
    header("Location: " . base_url('EMPLOYEE/index.php?error=not_found'));
    exit;
}

// Loans may only be deleted while still in Proposal status — once Approved
// (or Fully Paid / Cancelled), the record is locked, regardless of whether
// it has payments yet. This supersedes the old "has payments" check, since
// Approved loans can't be deleted at all now, payments or not.
if (trim($loan['Status'] ?? '') !== 'Proposal') {
    header("Location: " . base_url('EMPLOYEE/index.php?error=locked'));
    exit;
}

// Delete child records first, then the loan header
sqlsrv_query($conn, "DELETE FROM TBL_Loan_Statement WHERE LoanID = ?", [$loan_id]);
sqlsrv_query($conn, "DELETE FROM TBL_Loan WHERE LoanID = ?", [$loan_id]);

header("Location: " . base_url('EMPLOYEE/index.php?deleted=1'));
exit;