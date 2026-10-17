<?php
/* ==========================================================
   payroll_cutoff_ajax.php
   AJAX endpoint for TBL_PayrollCutOff CRUD, used by the
   Payroll Cutoff modal on payroll_dashboard.php.
========================================================== */
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'payroll_dashboard');

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// CSRF only applies to state-changing requests, not reads like 'list'
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && function_exists('rbac_csrf_verify')) {
    rbac_csrf_verify();
}

$canEdit   = !rbac_is_view_only('payroll_dashboard');
$userInput = trim($_SESSION['DisplayName'] ?? $_SESSION['UserID'] ?? 'system');

function jexit($arr) {
    echo json_encode($arr);
    exit;
}

switch ($action) {

    case 'list':
        $sql = "SELECT ID, PayrollCutOff, StartDayName, EndDayName, CreatedBy, DateCreated
                FROM TBL_PayrollCutOff
                ORDER BY DateCreated DESC";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Query failed.', 'errors' => sqlsrv_errors()]);
        }
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                'ID'            => (int)$r['ID'],
                'PayrollCutOff' => trim($r['PayrollCutOff'] ?? ''),
                'StartDayName'  => trim($r['StartDayName'] ?? ''),
                'EndDayName'    => trim($r['EndDayName'] ?? ''),
                'CreatedBy'     => trim($r['CreatedBy'] ?? ''),
                'DateCreated'   => $r['DateCreated'] instanceof DateTime ? $r['DateCreated']->format('Y-m-d H:i') : (string)$r['DateCreated'],
            ];
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true, 'data' => $rows]);
        break;

    case 'create':
        if (!$canEdit) jexit(['success' => false, 'message' => 'View-only access.']);

        $payrollCutOff = trim($_POST['PayrollCutOff'] ?? '');
        $startDayName  = trim($_POST['StartDayName'] ?? '');
        $endDayName    = trim($_POST['EndDayName'] ?? '');

        if ($payrollCutOff === '' || $startDayName === '' || $endDayName === '') {
            jexit(['success' => false, 'message' => 'Cutoff Name, Start Day, and End Day are required.']);
        }

        $sql = "INSERT INTO TBL_PayrollCutOff
                    (PayrollCutOff, StartDayName, EndDayName, CreatedBy, DateCreated)
                VALUES (?, ?, ?, ?, GETDATE())";
        $stmt = sqlsrv_query($conn, $sql, [$payrollCutOff, $startDayName, $endDayName, $userInput]);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Insert failed.', 'errors' => sqlsrv_errors()]);
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true]);
        break;

    case 'update':
        if (!$canEdit) jexit(['success' => false, 'message' => 'View-only access.']);

        $id            = (int)($_POST['ID'] ?? 0);
        $payrollCutOff = trim($_POST['PayrollCutOff'] ?? '');
        $startDayName  = trim($_POST['StartDayName'] ?? '');
        $endDayName    = trim($_POST['EndDayName'] ?? '');

        if ($id <= 0) jexit(['success' => false, 'message' => 'Invalid ID.']);
        if ($payrollCutOff === '' || $startDayName === '' || $endDayName === '') {
            jexit(['success' => false, 'message' => 'Cutoff Name, Start Day, and End Day are required.']);
        }

        $sql = "UPDATE TBL_PayrollCutOff
                SET PayrollCutOff = ?, StartDayName = ?, EndDayName = ?
                WHERE ID = ?";
        $stmt = sqlsrv_query($conn, $sql, [$payrollCutOff, $startDayName, $endDayName, $id]);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Update failed.', 'errors' => sqlsrv_errors()]);
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true]);
        break;

    default:
        jexit(['success' => false, 'message' => 'Unknown action.']);
}