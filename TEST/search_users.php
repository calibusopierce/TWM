<?php
/**
 * search_users.php
 * Returns a JSON list of active users matching a search query.
 * Used by the messaging sidebar search bar.
 */
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userType = $_SESSION['UserType'] ?? '';
rbac_load_permissions($pdo, $userType);
if (!in_array($userType, rbac_superadmin_roles()) && !rbac_can('message_user')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$currentUser = (int) $_SESSION['UserID'];
$q           = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

$like = '%' . $q . '%';

$sql = "
    SELECT TOP 15
        u.id,
        e.FirstName + ' ' + e.LastName AS DisplayName,
        e.Department
    FROM users u
    INNER JOIN TBL_HREmployeeList e ON u.EmployeeID = e.EmployeeID
    WHERE e.Active = 1
      AND u.id != ?
      AND (
          e.FirstName + ' ' + e.LastName LIKE ?
          OR e.Department LIKE ?
      )
    ORDER BY DisplayName
";

$stmt = sqlsrv_query($conn, $sql, [$currentUser, $like, $like]);

if (!$stmt) {
    error_log('search_users query failed: ' . print_r(sqlsrv_errors(), true));
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
    exit;
}

$users = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $users[] = [
        'id'   => (int) $row['id'],
        'name' => $row['DisplayName'],
        'dept' => $row['Department'] ?? '',
    ];
}

sqlsrv_free_stmt($stmt);

echo json_encode(['users' => $users]);