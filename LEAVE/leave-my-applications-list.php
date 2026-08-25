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

define('LEAVE_STATUS_PENDING', 0);
define('LEAVE_STATUS_APPROVED', 1);
define('LEAVE_STATUS_REJECTED', 2);

function leaveStatusLabel($code) {
    switch ((int)$code) {
        case LEAVE_STATUS_APPROVED: return 'Approved';
        case LEAVE_STATUS_REJECTED: return 'Rejected';
        default: return 'Pending';
    }
}

function buildName($first, $middle, $last) {
    $mid = trim($middle ?? '');
    $mi  = $mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '';
    return trim($first . ' ' . $mi . $last);
}

$employeeID = $_SESSION['EmployeeID'] ?? '';
if (!$employeeID) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (isset($_GET['detail'])) {
    $id = (int)$_GET['detail'];

    $sql = "SELECT
                la.ID, la.ControlNo, la.TypeID, la.NumberOfDays, la.Date_Start, la.Date_End,
                la.HalfDay, la.ReasonOfLeave, la.Attachment,
                la.SA_EmployeeID, la.SA_Status, la.SA_Date_Approved, la.SA_Note,
                la.HR_EmployeeID, la.HR_Status, la.HR_Date_Approved, la.HR_Note,
                lt.Type_Name,
                sup.FirstName AS Sup_FirstName, sup.MiddleName AS Sup_MiddleName, sup.LastName AS Sup_LastName,
                hr.FirstName  AS HR_FirstName,  hr.MiddleName  AS HR_MiddleName,  hr.LastName  AS HR_LastName
            FROM dbo.Tbl_Leave_Application la
            LEFT JOIN dbo.Tbl_Leave_Type lt ON lt.ID = la.TypeID
            LEFT JOIN dbo.TBL_HREmployeeList sup ON sup.EmployeeID = la.SA_EmployeeID
            LEFT JOIN dbo.TBL_HREmployeeList hr  ON hr.EmployeeID  = la.HR_EmployeeID
            WHERE la.ID = :id AND la.EmployeeID = :empId";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id, 'empId' => $employeeID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Application not found']);
        exit;
    }

    $row['Date_Start_Raw'] = $row['Date_Start'] ? date('Y-m-d', strtotime($row['Date_Start'])) : null;
    $row['Date_End_Raw']   = $row['Date_End']   ? date('Y-m-d', strtotime($row['Date_End']))   : null;

    foreach (['Date_Start', 'Date_End'] as $f) {
        if ($row[$f]) $row[$f] = date('M d, Y', strtotime($row[$f]));
    }
    foreach (['SA_Date_Approved', 'HR_Date_Approved'] as $f) {
        if ($row[$f]) $row[$f] = date('M d, Y g:i A', strtotime($row[$f]));
    }

    $row['SupervisorName'] = buildName($row['Sup_FirstName'], $row['Sup_MiddleName'], $row['Sup_LastName']);
    $row['HRName']         = buildName($row['HR_FirstName'], $row['HR_MiddleName'], $row['HR_LastName']);
    $row['SA_Status']      = leaveStatusLabel($row['SA_Status']);
    $row['HR_Status']      = leaveStatusLabel($row['HR_Status']);

    echo json_encode(['success' => true, 'row' => $row]);
    exit;
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 20;
$offset   = ($page - 1) * $pageSize;

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$type   = trim($_GET['type'] ?? '');

$where  = "WHERE la.EmployeeID = :empId";
$params = ['empId' => $employeeID];

if ($search !== '') {
    $where .= " AND (la.ControlNo LIKE :search1 OR la.ReasonOfLeave LIKE :search2)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

if ($type !== '') {
    $where .= " AND la.TypeID = :typeId";
    $params['typeId'] = (int)$type;
}

switch ($status) {
    case 'PendingSA':
        $where .= " AND la.SA_Status = " . LEAVE_STATUS_PENDING;
        break;
    case 'ApprovedSA':
        $where .= " AND la.SA_Status = " . LEAVE_STATUS_APPROVED;
        break;
    case 'RejectedSA':
        $where .= " AND la.SA_Status = " . LEAVE_STATUS_REJECTED;
        break;
    case 'PendingHR':
        $where .= " AND la.HR_Status = " . LEAVE_STATUS_PENDING;
        break;
    case 'Approved':
        $where .= " AND la.HR_Status = " . LEAVE_STATUS_APPROVED;
        break;
    case 'RejectedHR':
        $where .= " AND la.HR_Status = " . LEAVE_STATUS_REJECTED;
        break;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS Total FROM dbo.Tbl_Leave_Application la $where");
$countStmt->execute($params);
$total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $pageSize));

$sql = "SELECT
            la.ID, la.ControlNo, la.NumberOfDays, la.HalfDay,
            la.Date_Start, la.Date_End, la.SA_Status, la.HR_Status,
            lt.Type_Name,
            sup.FirstName AS Sup_FirstName, sup.MiddleName AS Sup_MiddleName, sup.LastName AS Sup_LastName,
            hr.FirstName AS HR_FirstName, hr.MiddleName AS HR_MiddleName, hr.LastName AS HR_LastName
        FROM dbo.Tbl_Leave_Application la
        LEFT JOIN dbo.Tbl_Leave_Type lt ON lt.ID = la.TypeID
        LEFT JOIN dbo.TBL_HREmployeeList sup ON sup.EmployeeID = la.SA_EmployeeID
        LEFT JOIN dbo.TBL_HREmployeeList hr  ON hr.EmployeeID  = la.HR_EmployeeID
        $where
        ORDER BY la.DateTimeInput DESC
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
    $rows[] = [
        'ID'             => $row['ID'],
        'ControlNo'      => $row['ControlNo'],
        'TypeName'       => $row['Type_Name'],
        'Date_Start'     => $row['Date_Start'] ? date('M d, Y', strtotime($row['Date_Start'])) : null,
        'Date_End'       => $row['Date_End'] ? date('M d, Y', strtotime($row['Date_End'])) : null,
        'NumberOfDays'   => $row['NumberOfDays'],
        'HalfDay'        => $row['HalfDay'],
        'SupervisorName' => buildName($row['Sup_FirstName'], $row['Sup_MiddleName'], $row['Sup_LastName']),
        'HRName'         => buildName($row['HR_FirstName'], $row['HR_MiddleName'], $row['HR_LastName']),
        'SA_Status'      => leaveStatusLabel($row['SA_Status']),
        'HR_Status'      => leaveStatusLabel($row['HR_Status']),
    ];
}

echo json_encode([
    'success'    => true,
    'rows'       => $rows,
    'total'      => $total,
    'totalPages' => $totalPages,
    'page'       => $page,
]);