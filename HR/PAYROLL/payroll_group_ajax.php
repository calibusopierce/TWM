<?php
/* ==========================================================
   payroll_group_ajax.php
   AJAX endpoint for TBL_PayrollGroup CRUD, used by the
   Payroll Group modal on payroll_dashboard.php.
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
        $sql = "SELECT ID, Department, PayrollGroup, PaymentMethod, Active, PayrollFrequency
                FROM TBL_PayrollGroup
                ORDER BY Department, PayrollGroup";
        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Query failed.', 'errors' => sqlsrv_errors()]);
        }
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                'ID'               => (int)$r['ID'],
                'Department'       => trim($r['Department'] ?? ''),
                'PayrollGroup'     => trim($r['PayrollGroup'] ?? ''),
                'PaymentMethod'    => trim($r['PaymentMethod'] ?? ''),
                'PayrollFrequency' => trim($r['PayrollFrequency'] ?? ''),
                'Active'           => (bool)$r['Active'],
            ];
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true, 'data' => $rows]);
        break;

    case 'create':
        if (!$canEdit) jexit(['success' => false, 'message' => 'View-only access.']);

        $department       = trim($_POST['Department'] ?? '');
        $payrollGroup     = trim($_POST['PayrollGroup'] ?? '');
        $paymentMethod    = trim($_POST['PaymentMethod'] ?? '');
        $payrollFrequency = trim($_POST['PayrollFrequency'] ?? '');

        if ($department === '' || $payrollGroup === '' || $paymentMethod === '') {
            jexit(['success' => false, 'message' => 'Department, Payroll Group, and Payment Method are required.']);
        }

        $sql = "INSERT INTO TBL_PayrollGroup
                    (Department, PayrollGroup, PaymentMethod, Active, UserInput, DateTimeInput, PayrollFrequency)
                VALUES (?, ?, ?, 1, ?, GETDATE(), ?)";
        $stmt = sqlsrv_query($conn, $sql, [$department, $payrollGroup, $paymentMethod, $userInput, $payrollFrequency]);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Insert failed.', 'errors' => sqlsrv_errors()]);
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true]);
        break;

    case 'update':
        if (!$canEdit) jexit(['success' => false, 'message' => 'View-only access.']);

        $id               = (int)($_POST['ID'] ?? 0);
        $department       = trim($_POST['Department'] ?? '');
        $payrollGroup     = trim($_POST['PayrollGroup'] ?? '');
        $paymentMethod    = trim($_POST['PaymentMethod'] ?? '');
        $payrollFrequency = trim($_POST['PayrollFrequency'] ?? '');

        if ($id <= 0) jexit(['success' => false, 'message' => 'Invalid ID.']);
        if ($department === '' || $payrollGroup === '' || $paymentMethod === '') {
            jexit(['success' => false, 'message' => 'Department, Payroll Group, and Payment Method are required.']);
        }

        $sql = "UPDATE TBL_PayrollGroup
                SET Department = ?, PayrollGroup = ?, PaymentMethod = ?, PayrollFrequency = ?
                WHERE ID = ?";
        $stmt = sqlsrv_query($conn, $sql, [$department, $payrollGroup, $paymentMethod, $payrollFrequency, $id]);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Update failed.', 'errors' => sqlsrv_errors()]);
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true]);
        break;

    case 'toggle_active':
        if (!$canEdit) jexit(['success' => false, 'message' => 'View-only access.']);

        $id = (int)($_POST['ID'] ?? 0);
        if ($id <= 0) jexit(['success' => false, 'message' => 'Invalid ID.']);

        $sql = "UPDATE TBL_PayrollGroup
                SET Active = CASE WHEN Active = 1 THEN 0 ELSE 1 END
                WHERE ID = ?";
        $stmt = sqlsrv_query($conn, $sql, [$id]);
        if ($stmt === false) {
            jexit(['success' => false, 'message' => 'Update failed.', 'errors' => sqlsrv_errors()]);
        }
        sqlsrv_free_stmt($stmt);
        jexit(['success' => true]);
        break;

    default:
        jexit(['success' => false, 'message' => 'Unknown action.']);
}