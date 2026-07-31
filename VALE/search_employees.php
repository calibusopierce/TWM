<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

auth_check();
$perms = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

header('Content-Type: application/json');

// Any access level (full or view_only, either module) is fine here —
// this just powers the recommender autocomplete, it doesn't write anything.
if (!isset($perms['cash_advance']) && !isset($perms['cash_advance_record'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT TOP 15 EmployeeID, FirstName, LastName
        FROM TBL_HrEmployeeList
        WHERE (FirstName + ' ' + LastName) LIKE ?
        ORDER BY LastName, FirstName";

$params = ['%' . $q . '%'];
$stmt = sqlsrv_query($conn, $sql, $params);

$results = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'EmployeeID' => $row['EmployeeID'],
            'FullName'   => $row['FirstName'] . ' ' . $row['LastName']
        ];
    }
}

echo json_encode($results);