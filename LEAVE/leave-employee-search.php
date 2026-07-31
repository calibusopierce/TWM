<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_application');
ob_end_clean();

header('Content-Type: application/json');

$employeeID  = $_SESSION['EmployeeID'] ?? '';
$includeSelf = isset($_GET['includeSelf']) && $_GET['includeSelf'] == '1';

if (!$employeeID) {
    echo json_encode([]);
    exit;
}

function buildName($row) {
    $mid = trim($row['MiddleName'] ?? '');
    $mi  = $mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '';
    return trim($row['FirstName'] . ' ' . $mi . $row['LastName']);
}

if (isset($_GET['hr']) && $_GET['hr'] == '1') {
    $sql = "SELECT TOP 200 EmployeeID, LastName, FirstName, MiddleName, Position_held
            FROM TBL_HREmployeeList
            WHERE Active = 1 AND Position_held IN ('HR ASSISTANT', 'HR SPECIALIST')"
         . (!$includeSelf ? " AND EmployeeID <> ?" : "")
         . " ORDER BY LastName, FirstName";
    $params = $includeSelf ? [] : [$employeeID];

    $res = sqlsrv_query($conn, $sql, $params);

    $results = [];
    if ($res) {
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                'EmployeeID'   => $row['EmployeeID'],
                'EmployeeName' => buildName($row),
                'Position'     => $row['Position_held'],
            ];
        }
    }

    echo json_encode($results);
    exit;
}

if (isset($_GET['all']) && $_GET['all'] == '1') {
    $sql = "SELECT TOP 2000 EmployeeID, LastName, FirstName, MiddleName
            FROM TBL_HREmployeeList
            WHERE Active = 1"
         . (!$includeSelf ? " AND EmployeeID <> ?" : "")
         . " ORDER BY LastName, FirstName";
    $params = $includeSelf ? [] : [$employeeID];

    $res = sqlsrv_query($conn, $sql, $params);

    $results = [];
    if ($res) {
        while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
            $results[] = [
                'EmployeeID'   => $row['EmployeeID'],
                'EmployeeName' => buildName($row),
            ];
        }
    }

    echo json_encode($results);
    exit;
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$qLike = '%' . $q . '%';
$sql = "SELECT TOP 10 EmployeeID, LastName, FirstName, MiddleName
        FROM TBL_HREmployeeList
        WHERE Active = 1 AND (LastName LIKE ? OR FirstName LIKE ? OR EmployeeID LIKE ?)"
     . (!$includeSelf ? " AND EmployeeID <> ?" : "")
     . " ORDER BY LastName, FirstName";
$params = [$qLike, $qLike, $qLike];
if (!$includeSelf) $params[] = $employeeID;

$res = sqlsrv_query($conn, $sql, $params);

$results = [];
if ($res) {
    while ($row = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'EmployeeID'   => $row['EmployeeID'],
            'EmployeeName' => buildName($row),
        ];
    }
}

echo json_encode($results);