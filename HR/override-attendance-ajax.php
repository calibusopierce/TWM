<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php'; // defines base_url()
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php'; // establishes $conn / $pdo
auth_check();

header('Content-Type: application/json');

rbac_gate($pdo, 'override_attendance');

/**
 * Safely format a sqlsrv time/datetime value (may come back as a DateTime
 * object, a string, or null depending on column type) into 'h:i A'.
 */
function oea_fmt_time($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('h:i A');
    $ts = strtotime($v);
    return $ts !== false ? date('h:i A', $ts) : (string)$v;
}

function oea_fmt_date($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('m/d/Y');
    $ts = strtotime($v);
    return $ts !== false ? date('m/d/Y', $ts) : (string)$v;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_employee') {

    $employeeId = trim($_GET['employee_id'] ?? '');
    $aDate      = trim($_GET['adate'] ?? '');

    if ($employeeId === '') {
        echo json_encode(['success' => false, 'message' => 'Employee ID is required.']);
        exit;
    }

    // Employee name/department/position from the latest log row
    $sql = "SELECT TOP 1
                [EmployeeName], [Department], [Position_Held], [Job_tittle], [ADate]
            FROM [dbo].[View_Attendance_Log2]
            WHERE [EmployeeID] = ?
            ORDER BY [ADate] DESC";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Employee not found.']);
        exit;
    }

    // Device ID from TBL_DeviceID
    $deviceId = '';
    $sqlDevice = "SELECT TOP 1 [DeviceID]
                  FROM [dbo].[TBL_DeviceID]
                  WHERE [EmployeeID] = ?";

    $stmtDevice = sqlsrv_query($conn, $sqlDevice, [$employeeId]);

    if ($stmtDevice === false) {
        echo json_encode(['success' => false, 'message' => 'Query error (device).', 'errors' => sqlsrv_errors()]);
        exit;
    }

    if ($rowDevice = sqlsrv_fetch_array($stmtDevice, SQLSRV_FETCH_ASSOC)) {
        $deviceId = trim($rowDevice['DeviceID']);
    }

    // Day is derived from the Attendance Date the user picked, not the log's own ADate
    $day = '';
    if ($aDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $aDate);
        $day = $dt ? $dt->format('l') : '';
    }

    echo json_encode([
        'success' => true,
        'employee' => [
            'EmployeeName' => trim($row['EmployeeName']),
            'Department'   => trim($row['Department']),
            'Position'     => trim($row['Position_Held'] ?? $row['Job_tittle']),
            'Day'          => $day,
            'DeviceID'     => $deviceId,
        ],
    ]);
    exit;
}

if ($action === 'get_all_employees') {

    $sql = "SELECT TOP 2000 e.EmployeeID, e.LastName, e.FirstName, e.MiddleName, d.DeviceID
            FROM TBL_HREmployeeList e
            LEFT JOIN TBL_DeviceID d ON d.EmployeeID = e.EmployeeID
            WHERE e.Active = 1
            ORDER BY e.LastName, e.FirstName";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $mid  = trim($row['MiddleName'] ?? '');
        $mi   = $mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '';
        $name = trim($row['FirstName'] . ' ' . $mi . $row['LastName']);

        $results[] = [
            'EmployeeID'   => trim($row['EmployeeID']),
            'EmployeeName' => $name,
            'DeviceID'     => trim($row['DeviceID'] ?? ''),
        ];
    }

    echo json_encode(['success' => true, 'employees' => $results]);
    exit;
}

if ($action === 'get_attendance_logs') {

    $employeeId = trim($_GET['employee_id'] ?? '');
    $aDate      = trim($_GET['adate'] ?? '');

    if ($employeeId === '' || $aDate === '') {
        echo json_encode(['success' => false, 'message' => 'Employee ID and date are required.']);
        exit;
    }

    $sql = "SELECT [ADate], [ATime], [CheckIn], [TimeIn], [Direction], [ShiftPart],
                   [Category], [DataFrom], [Area]
            FROM [dbo].[View_Attendance_Log2]
            WHERE [EmployeeID] = ? AND CAST([ADate] AS DATE) = CAST(? AS DATE)
            ORDER BY [ATime] ASC";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId, $aDate]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = [
            'Date'      => oea_fmt_date($r['ADate']),
            'Time'      => oea_fmt_time($r['ATime']),
            'Direction' => trim($r['Direction'] ?? ''),
            'ShiftPart' => trim($r['ShiftPart'] ?? ''),
            'Category'  => trim($r['Category'] ?? ''),
            'DataFrom'    => trim($r['DataFrom'] ?? ''),
            'Area'      => trim($r['Area'] ?? ''),
        ];
    }

    echo json_encode(['success' => true, 'logs' => $rows]);
    exit;
}

if ($action === 'get_override_history') {

    $employeeId = trim($_GET['employee_id'] ?? '');

    if ($employeeId === '') {
        echo json_encode(['success' => false, 'message' => 'Employee ID is required.']);
        exit;
    }

    $sql = "SELECT TOP 50
                [ADate], [SetTime], [ATime], [AtimeInPM], [SetTimeInPM],
                [Direction], [ShiftPart], [Category], [DataFrom]
            FROM [dbo].[View_Attendance_Override_Log]
            WHERE [EmployeeID] = ?
            ORDER BY [ADate] DESC";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $isPM = trim($r['ShiftPart'] ?? '') === 'PM';
        $rows[] = [
            'Date'          => oea_fmt_date($r['ADate']),
            'ShiftPart'     => trim($r['ShiftPart'] ?? ''),
            'Direction'     => trim($r['Direction'] ?? ''),
            'OriginalTime'  => oea_fmt_time($isPM ? $r['AtimeInPM'] : $r['ATime']),
            'CorrectedTime' => oea_fmt_time($isPM ? $r['SetTimeInPM'] : $r['SetTime']),
            'Category'      => trim($r['Category'] ?? ''),
            'DataFrom'      => trim($r['DataFrom'] ?? ''),
        ];
    }

    echo json_encode(['success' => true, 'history' => $rows]);
    exit;
}

if ($action === 'get_override_categories') {

    $sql = "SELECT [OverrideID], [Override_Name]
            FROM [dbo].[Tbl_Override_Category]
            WHERE [Status] = 1
            ORDER BY [Override_Name] ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'OverrideID'    => $row['OverrideID'],
            'Override_Name' => trim($row['Override_Name']),
        ];
    }

    echo json_encode(['success' => true, 'categories' => $results]);
    exit;
}

if ($action === 'get_override_types') {

    $sql = "SELECT [TypeID], [Category], [Type_Name]
            FROM [dbo].[Tbl_Override_Type]
            WHERE [Status] = 1
            ORDER BY [Type_Name] ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = [
            'TypeID'    => $row['TypeID'],
            'Category'  => $row['Category'],
            'Type_Name' => trim($row['Type_Name']),
        ];
    }

    echo json_encode(['success' => true, 'types' => $results]);
    exit;
}

if ($action === 'save_override') {

    // Mutating action — enforce full (not view-only) access and verify CSRF
    rbac_enforce_full_access('override_attendance', true);
    rbac_csrf_verify();

    $employeeId    = trim($_POST['employee_id'] ?? '');
    $aDate         = trim($_POST['adate'] ?? '');
    $aDay          = trim($_POST['aday'] ?? '');
    $shiftPart     = trim($_POST['shift_part'] ?? '');
    $direction     = trim($_POST['direction'] ?? '');
    $originalTime  = trim($_POST['original_time'] ?? '');
    $correctedTime = trim($_POST['corrected_time'] ?? '');
    $overrideType  = trim($_POST['override_type'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');

    $errors = [];
    if ($employeeId === '')    $errors[] = 'Employee is required.';
    if ($aDate === '')         $errors[] = 'Attendance Date is required.';
    if ($shiftPart === '')     $errors[] = 'Shift Part is required.';
    if ($direction === '')     $errors[] = 'Direction is required.';
    if ($correctedTime === '') $errors[] = 'Corrected Time is required.';
    if ($overrideType === '')  $errors[] = 'Override Type is required.';

    if ($errors) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    $userId = (int)($_SESSION['UserID'] ?? 0);

    $sql = "INSERT INTO [dbo].[TBL_Attendance_Override]
                ([EmployeeID], [ADate], [OriginalTime], [ATime], [Direction], [ShiftPart],
                 [Aday], [Override_Type], [Remarks], [SetTime], [HR_Status], [UserInput], [DateTimeInput])
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, GETDATE())";

    $params = [
        $employeeId, $aDate, $originalTime ?: null, $originalTime ?: null, $direction, $shiftPart,
        $aDay, $overrideType, $remarks, $correctedTime, $userId,
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Insert error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Override request submitted and is pending HR review.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);