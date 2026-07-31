<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/test_sqlsrv.php';

header('Content-Type: application/json');

rbac_load_permissions();
if (!rbac_can('short_stocks_paid', 'view')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this module.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action !== 'get_rows') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$dateFrom  = trim($input['date_from'] ?? '');
$dateTo    = trim($input['date_to'] ?? '');
$department= trim($input['department'] ?? '');
$area      = trim($input['area'] ?? '');
$outlet    = trim($input['outlet'] ?? '');
$typeShort = trim($input['type_short'] ?? '');
$category  = trim($input['category'] ?? '');
$status    = trim($input['status'] ?? '');
$search    = trim($input['search'] ?? '');

$where  = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = "[DatePaid] >= :date_from";
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "[DatePaid] <= :date_to";
    $params[':date_to'] = $dateTo;
}
if ($department !== '') {
    $where[] = "[Department] = :department";
    $params[':department'] = $department;
}
if ($area !== '') {
    $where[] = "[Area] = :area";
    $params[':area'] = $area;
}
if ($outlet !== '') {
    $where[] = "[Outlet] = :outlet";
    $params[':outlet'] = $outlet;
}
if ($typeShort !== '') {
    $where[] = "[TypeShort] = :type_short";
    $params[':type_short'] = $typeShort;
}
if ($category !== '') {
    $where[] = "[Category] = :category";
    $params[':category'] = $category;
}
if ($status !== '') {
    $where[] = "[StatusofShort] = :status";
    $params[':status'] = $status;
}
if ($search !== '') {
    $where[] = "([EmployeeName] LIKE :search OR [RefNo] LIKE :search OR [PlateNumber] LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        [SEID], [Position], [Status], [Amount], [SPPID], [AmountDue], [PaidAmount],
        [Balance], [DateGenerate], [SDID], [DID], [Department],
        CONVERT(varchar(10), [DateSchedule], 23) AS [DateSchedule],
        [PlateNumber], [Area], [Outlet], [RefNo], [TotalAmount], [NumAccountable],
        [AmountL], [StatusofShort], [Remarks], [IDS], [EmployeeID], [EmployeeName],
        CONVERT(varchar(10), [DatePaid], 23) AS [DatePaid],
        [TypeShort], [Category], [Employee_Status], [Job_tittle], [Position_held],
        [PaymentID], [Source]
    FROM [dbo].[View_ShortPaymentPaidDetails]
    $whereSql
    ORDER BY [DatePaid] DESC
";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'rows' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed.']);
}
