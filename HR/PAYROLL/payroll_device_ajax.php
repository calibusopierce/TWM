<?php
/**
 * payroll_device_ajax.php
 *
 * Backend for the "Employees" sub-view inside the Payroll Group modal.
 * Handles:
 *   GET  ?action=list_by_group&PayrollGroup=...   -> employees currently tagged to a group
 *   GET  ?action=search_employees&q=...           -> search employees to tag
 *   POST action=assign&EmployeeID=...&PayrollGroup=... -> tag/reassign/untag (empty PayrollGroup = untag)
 *
 * ============================ FLAGGED / UNVERIFIED ============================
 * 1) TBL_HREmployeeList column names for first/last name are GUESSED as
 *    FirstName / LastName. If these are wrong, every query below will throw
 *    a SQL error. Confirm the real column names, then fix the two spots
 *    marked "GUESSED COLUMN" below.
 *
 * 2) TBL_DeviceID.EmployeeID is confirmed (per earlier notes) to be the
 *    internal EmployeeID, matching TBL_HREmployeeList's internal ID column
 *    (assumed to be EmployeeID here too — NOT confirmed against FileNo).
 *    If TBL_HREmployeeList's PK is actually something else (e.g. FileNo),
 *    the JOINs below will silently return zero rows rather than erroring.
 *
 * 3) CSRF: payroll_group_ajax.php is described as having CSRF handling
 *    where GET skips it. I don't have that file's exact token-check
 *    mechanism, so this file's POST (assign) action has NO CSRF check
 *    yet — marked below. Wire it to match payroll_group_ajax.php once
 *    you send me that snippet.
 * ================================================================================
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'payroll_dashboard');

header('Content-Type: application/json');

$canEdit = !rbac_is_view_only('payroll_dashboard');
$action  = $_REQUEST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && function_exists('rbac_csrf_verify')) {
    rbac_csrf_verify();
}

function respond($success, $data = null, $message = null) {
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
    exit;
}

switch ($action) {

    case 'list_by_group':
        $group = trim($_GET['PayrollGroup'] ?? '');
        if ($group === '') {
            respond(false, null, 'PayrollGroup is required.');
        }

        // GUESSED COLUMN: FirstName, LastName on TBL_HREmployeeList
        $sql = "
            SELECT
                d.EmployeeID,
                (e.FirstName + ' ' + e.LastName) AS Name,
                e.Department,
                d.PayrollGroup
            FROM TBL_DeviceID d
            INNER JOIN TBL_HREmployeeList e ON e.EmployeeID = d.EmployeeID
            WHERE d.PayrollGroup = ?
              AND e.Active = 1
            ORDER BY e.LastName, e.FirstName
        ";
        $stmt = sqlsrv_query($conn, $sql, [$group]);
        if ($stmt === false) {
            respond(false, null, 'Query failed: ' . print_r(sqlsrv_errors(), true));
        }

        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $r['EmployeeID']   = trim($r['EmployeeID'] ?? '');
            $r['Name']         = trim($r['Name'] ?? '');
            $r['Department']   = trim($r['Department'] ?? '');
            $r['PayrollGroup'] = trim($r['PayrollGroup'] ?? '');
            $rows[] = $r;
        }
        sqlsrv_free_stmt($stmt);
        respond(true, $rows);
        break;

    case 'search_employees':
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            respond(true, []);
        }
        $like = '%' . $q . '%';

        // GUESSED COLUMN: FirstName, LastName on TBL_HREmployeeList
        $sql = "
            SELECT TOP 15
                e.EmployeeID,
                (e.FirstName + ' ' + e.LastName) AS Name,
                e.Department,
                d.PayrollGroup AS CurrentPayrollGroup
            FROM TBL_HREmployeeList e
            LEFT JOIN TBL_DeviceID d ON d.EmployeeID = e.EmployeeID
            WHERE e.Active = 1
              AND (e.FirstName + ' ' + e.LastName) LIKE ?
            ORDER BY e.LastName, e.FirstName
        ";
        $stmt = sqlsrv_query($conn, $sql, [$like]);
        if ($stmt === false) {
            respond(false, null, 'Query failed: ' . print_r(sqlsrv_errors(), true));
        }

        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $r['EmployeeID']          = trim($r['EmployeeID'] ?? '');
            $r['Name']                = trim($r['Name'] ?? '');
            $r['Department']          = trim($r['Department'] ?? '');
            $r['CurrentPayrollGroup'] = trim($r['CurrentPayrollGroup'] ?? '');
            $rows[] = $r;
        }
        sqlsrv_free_stmt($stmt);
        respond(true, $rows);
        break;

    case 'assign':
        if (!$canEdit) {
            respond(false, null, 'You do not have permission to do this.');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            respond(false, null, 'Invalid request method.');
        }

        // TODO: CSRF check goes here once you share payroll_group_ajax.php's
        // token mechanism — e.g.:
        // if (!csrf_verify($_POST['csrf_token'] ?? '')) { respond(false, null, 'Invalid CSRF token.'); }
        $employeeId = trim($_POST['EmployeeID'] ?? '');
        $group      = trim($_POST['PayrollGroup'] ?? '');

        if ($employeeId === '') {
            respond(false, null, 'A valid EmployeeID is required.');
        }

        // Only update if a TBL_DeviceID row already exists for this employee.
        // Per earlier notes: this endpoint deliberately will NOT auto-create
        // device rows for employees who don't have one yet.
        $checkSql = "SELECT EmployeeID FROM TBL_DeviceID WHERE EmployeeID = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$employeeId]);
        if ($checkStmt === false) {
            respond(false, null, 'Lookup failed: ' . print_r(sqlsrv_errors(), true));
        }
        $exists = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($checkStmt);

        if (!$exists) {
            respond(false, null, 'This employee has no device record yet — cannot tag to a payroll group. (No auto-create by design.)');
        }

        $updateSql = "UPDATE TBL_DeviceID SET PayrollGroup = ? WHERE EmployeeID = ?";
        $params = $group === '' ? [null, $employeeId] : [$group, $employeeId];
        $updateStmt = sqlsrv_query($conn, $updateSql, $params);

        if ($updateStmt === false) {
            respond(false, null, 'Update failed: ' . print_r(sqlsrv_errors(), true));
        }
        $rowsAffected = sqlsrv_rows_affected($updateStmt);
        sqlsrv_free_stmt($updateStmt);

        if ($rowsAffected === 0) {
            respond(false, null, 'No device record was updated — the EmployeeID sent did not match TBL_DeviceID exactly.');
        }
        respond(true, null, $group === '' ? 'Employee untagged.' : 'Employee tagged successfully.');
        break;

    default:
        respond(false, null, 'Unknown action.');
}
