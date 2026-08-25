<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_approval');
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

$mode = trim($_GET['mode'] ?? 'supervisor');
if (!in_array($mode, ['supervisor', 'hr'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid mode']);
    exit;
}

if ($mode === 'hr') {
    try {
        rbac_gate($pdo, 'leave_approval', 'hr');
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'You do not have HR approval access.']);
        exit;
    }
}

if (isset($_GET['detail'])) {
    $id = (int)$_GET['detail'];

    $sql = "SELECT
                la.ID, la.ControlNo, la.NumberOfDays, la.Date_Start, la.Date_End,
                la.HalfDay, la.ReasonOfLeave, la.Attachment,
                la.SA_Status, la.SA_Date_Approved, la.SA_Note,
                la.HR_Status, la.HR_Date_Approved, la.HR_Note,
                la.EmployeeID, la.SA_EmployeeID, la.HR_EmployeeID,
                lt.Type_Name,
                emp.FirstName AS Emp_FirstName, emp.MiddleName AS Emp_MiddleName, emp.LastName AS Emp_LastName,
                emp.Department AS DepartmentName,
                sup.FirstName AS Sup_FirstName, sup.MiddleName AS Sup_MiddleName, sup.LastName AS Sup_LastName,
                hr.FirstName  AS HR_FirstName,  hr.MiddleName  AS HR_MiddleName,  hr.LastName  AS HR_LastName
            FROM dbo.Tbl_Leave_Application la
            LEFT JOIN dbo.Tbl_Leave_Type lt ON lt.ID = la.TypeID
            LEFT JOIN dbo.TBL_HREmployeeList emp ON emp.EmployeeID = la.EmployeeID
            LEFT JOIN dbo.TBL_HREmployeeList sup ON sup.EmployeeID = la.SA_EmployeeID
            LEFT JOIN dbo.TBL_HREmployeeList hr  ON hr.EmployeeID  = la.HR_EmployeeID
            WHERE la.ID = :id";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[leave-approval-data] detail query failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred while loading this application.']);
        exit;
    }

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Application not found']);
        exit;
    }

    // Compare as trimmed strings, not strict ===. SQL Server CHAR(n) columns
    // space-pad values to their fixed length (e.g. "1023     " vs "1023"),
    // and SQL itself ignores that padding on comparison — but PHP's === does
    // not, so a tagged user could show up correctly in the list query (SQL
    // comparison) yet get rejected here (PHP comparison) for the exact same
    // application. Trimming both sides neutralizes that regardless of the
    // actual underlying column type.
    $isSupervisor = (trim((string)($row['SA_EmployeeID'] ?? '')) === trim((string)$employeeID));
    $isTaggedHr   = (trim((string)($row['HR_EmployeeID'] ?? '')) === trim((string)$employeeID));

    // Visibility: 'supervisor' mode is still tag-scoped (only your own team).
    // 'hr' mode is RBAC-tier-scoped — rbac_gate($pdo, 'leave_approval', 'hr')
    // already ran above for this whole request when $mode === 'hr', so any
    // HR-tier user may view. Only $isTaggedHr may actually act (below).
    $authorized = ($mode === 'supervisor') ? $isSupervisor : true;

    if (!$authorized) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to view this application.']);
        exit;
    }

    $row['CanApprove'] = ($mode === 'supervisor') ? $isSupervisor : $isTaggedHr;

    foreach (['Date_Start', 'Date_End'] as $f) {
        if ($row[$f]) $row[$f] = date('M d, Y', strtotime($row[$f]));
    }
    foreach (['SA_Date_Approved', 'HR_Date_Approved'] as $f) {
        if ($row[$f]) $row[$f] = date('M d, Y g:i A', strtotime($row[$f]));
    }

    $row['ApplicantName']  = buildName($row['Emp_FirstName'], $row['Emp_MiddleName'], $row['Emp_LastName']);
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
$search   = trim($_GET['search'] ?? '');

$baseSelect = "SELECT
            la.ID, la.ControlNo, la.NumberOfDays, la.HalfDay,
            la.Date_Start, la.Date_End, la.SA_Status, la.HR_Status,
            la.HR_EmployeeID,
            lt.Type_Name,
            emp.FirstName AS Emp_FirstName, emp.MiddleName AS Emp_MiddleName, emp.LastName AS Emp_LastName,
            emp.Department AS DepartmentName,
            hr.FirstName AS HR_FirstName, hr.MiddleName AS HR_MiddleName, hr.LastName AS HR_LastName
        FROM dbo.Tbl_Leave_Application la
        LEFT JOIN dbo.Tbl_Leave_Type lt ON lt.ID = la.TypeID
        LEFT JOIN dbo.TBL_HREmployeeList emp ON emp.EmployeeID = la.EmployeeID
        LEFT JOIN dbo.TBL_HREmployeeList hr ON hr.EmployeeID = la.HR_EmployeeID";

$baseCount = "SELECT COUNT(*) AS Total
        FROM dbo.Tbl_Leave_Application la
        LEFT JOIN dbo.TBL_HREmployeeList emp ON emp.EmployeeID = la.EmployeeID";

$params = [];

if ($mode === 'supervisor') {
    $where = "WHERE la.SA_EmployeeID = :myId";
    $params['myId'] = $employeeID;
} else {
    // HR mode: visibility is now RBAC-tier-based, not tag-based — any user
    // with 'hr' access on leave_approval (already rbac_gate()'d above) can
    // see every department application. Only the specific HR_EmployeeID
    // tagged on a row can actually act on it (enforced in
    // leave-approval-action.php, unchanged).
    $where = "WHERE 1=1";
}

if ($search !== '') {
    $where .= " AND (la.ControlNo LIKE :search1 OR emp.FirstName LIKE :search2 OR emp.LastName LIKE :search3)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

try {
    $countStmt = $pdo->prepare("$baseCount $where");
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['Total'] ?? 0);
    $totalPages = max(1, (int)ceil($total / $pageSize));

    $sql = "$baseSelect $where ORDER BY la.DateTimeInput DESC OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':pageSize', $pageSize, PDO::PARAM_INT);
    $stmt->execute();
} catch (PDOException $e) {
    error_log('[leave-approval-data] list query failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred while loading applications.']);
    exit;
}

$rows = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rows[] = [
        'ID'             => $row['ID'],
        'ControlNo'      => $row['ControlNo'],
        'TypeName'       => $row['Type_Name'],
        'ApplicantName'  => buildName($row['Emp_FirstName'], $row['Emp_MiddleName'], $row['Emp_LastName']),
        'DepartmentName' => $row['DepartmentName'],
        'Date_Start'     => $row['Date_Start'] ? date('M d, Y', strtotime($row['Date_Start'])) : null,
        'Date_End'       => $row['Date_End'] ? date('M d, Y', strtotime($row['Date_End'])) : null,
        'NumberOfDays'   => $row['NumberOfDays'],
        'HalfDay'        => $row['HalfDay'],
        'SA_Status'      => leaveStatusLabel($row['SA_Status']),
        'HR_Status'      => leaveStatusLabel($row['HR_Status']),
        'HRName'         => $mode === 'hr' ? buildName($row['HR_FirstName'], $row['HR_MiddleName'], $row['HR_LastName']) : null,
        'CanApprove'     => $mode === 'supervisor' ? true : (trim((string)($row['HR_EmployeeID'] ?? '')) === trim((string)$employeeID)),
    ];
}

echo json_encode([
    'success'    => true,
    'rows'       => $rows,
    'total'      => $total,
    'totalPages' => $totalPages,
    'page'       => $page,
]);