<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_management');
ob_end_clean();

header('Content-Type: application/json');

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 20;
$offset   = ($page - 1) * $pageSize;

$search = trim($_GET['search'] ?? '');
$year   = trim($_GET['year'] ?? '');
$type   = trim($_GET['type'] ?? '');

$where  = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (qc.FirstName LIKE :search1 OR qc.LastName LIKE :search2)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}
if ($year !== '') {
    $where .= " AND qc.Year = :year";
    $params['year'] = (int)$year;
}
if ($type !== '') {
    $where .= " AND qc.LeaveID = :typeId";
    $params['typeId'] = (int)$type;
}

$base = "FROM (
            SELECT
                Q.ID, Q.ControlNo, Q.LeaveID, Q.EmployeeID, Q.Year, Q.Qty,
                ISNULL(SUM(A.NumberOfDays), 0) AS TotalLeave,
                Q.Qty - ISNULL(SUM(A.NumberOfDays), 0) AS TotalBalance,
                emp.Department, emp.FirstName, emp.LastName, emp.Position_held,
                lt.Type_Name
            FROM dbo.Tbl_Leave_Qty Q
            INNER JOIN dbo.TBL_HREmployeeList emp ON Q.EmployeeID = emp.EmployeeID
            LEFT JOIN dbo.Tbl_Leave_Type lt ON Q.LeaveID = lt.ID
            LEFT JOIN dbo.Tbl_Leave_Application A
                ON YEAR(A.Date_Start) = Q.Year
                   AND A.TypeID = Q.LeaveID
                   AND A.EmployeeID = Q.EmployeeID
                   AND A.HR_Status = 1
            GROUP BY Q.ID, Q.ControlNo, Q.LeaveID, Q.EmployeeID, Q.Year, Q.Qty,
                     emp.Department, emp.FirstName, emp.LastName, emp.Position_held, lt.Type_Name
        ) qc";

$countStmt = $pdo->prepare("SELECT COUNT(*) AS Total $base $where");
$countStmt->execute($params);
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $pageSize));

$sql = "SELECT qc.* $base $where
        ORDER BY qc.Year DESC, qc.LastName ASC, qc.FirstName ASC
        OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
$stmt->execute();

$rows = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['EmployeeName'] = trim($row['FirstName'] . ' ' . $row['LastName']);
    $rows[] = $row;
}

echo json_encode([
    'success'    => true,
    'rows'       => $rows,
    'total'      => $total,
    'totalPages' => $totalPages,
    'page'       => $page,
]);