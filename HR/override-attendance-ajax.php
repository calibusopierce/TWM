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

// ── Employee selection is no longer a lookup field — every action here
//    acts on the CURRENT LOGGED-IN USER only. ───────────────────────────────
// ASSUMPTION (please confirm): the employee's ID is available on
// $_SESSION['EmployeeID'] at login. If the session only carries UserID,
// this needs a lookup (e.g. against TBL_HREmployeeList or an accounts
// table) to resolve UserID -> EmployeeID instead of reading it directly.
$employeeId = trim($_SESSION['EmployeeID'] ?? '');

if ($employeeId === '') {
    echo json_encode(['success' => false, 'message' => 'No employee record linked to this session. Please log out and back in.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_current_employee') {

    $sql = "SELECT TOP 1 e.FirstName, e.MiddleName, e.LastName, e.Department, e.Position_held, d.DeviceID
            FROM TBL_HREmployeeList e
            LEFT JOIN TBL_DeviceID d ON d.EmployeeID = e.EmployeeID
            WHERE e.EmployeeID = ?";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
        exit;
    }

    $mid  = trim($row['MiddleName'] ?? '');
    $mi   = $mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '';
    $name = trim($row['FirstName'] . ' ' . $mi . $row['LastName']);

    echo json_encode([
        'success' => true,
        'employee' => [
            'EmployeeName' => $name,
            'Department'   => trim($row['Department'] ?? ''),
            'Position'     => trim($row['Position_held'] ?? ''),
            'DeviceID'     => trim($row['DeviceID'] ?? ''),
        ],
    ]);
    exit;
}

if ($action === 'get_attendance_record') {

    $aDate = trim($_GET['adate'] ?? '');

    if ($aDate === '') {
        echo json_encode(['success' => false, 'message' => 'Attendance Date is required.']);
        exit;
    }

    // Reverted TimeOut/TimeOutPM — that guess broke the query. Back to only
    // TimeIn/TimeInPM (previously confirmed working) until the real Time Out
    // schedule column names are confirmed.
    // Renamed from View_ATtendanceTimeInTimeOut2 -> ..._Override (same
    // column set) per the updated schema the team sent over.
    $sql = "SELECT [ADate], [Category], [MorningIn], [MorningOut], [AfternoonIn], [AfternoonOut],
                   [TimeIn], [TimeInPM], [AMLate], [PMLate], [Late1],
                   [MorningTotalHours], [AfternoonTotalHours], [MorningAfternoonTotal],
                   [TotalHours], [Status], [DayCount], [PayrollGroup]
            FROM [dbo].[View_ATtendanceTimeInTimeOut2_Override]
            WHERE [EmployeeID] = ? AND CAST([ADate] AS DATE) = CAST(? AS DATE)";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId, $aDate]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    $dt  = DateTime::createFromFormat('Y-m-d', $aDate);
    $day = $dt ? $dt->format('l') : '';

    if (!$row) {
        echo json_encode(['success' => true, 'record' => null, 'day' => $day, 'message' => 'No attendance record found for this date.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'day'     => $day,
        'record'  => [
            'Date'                  => oea_fmt_date($row['ADate']),
            'Category'              => trim($row['Category'] ?? ''),
            'MorningIn'             => oea_fmt_time($row['MorningIn']),
            'MorningOut'            => oea_fmt_time($row['MorningOut']),
            'AfternoonIn'           => oea_fmt_time($row['AfternoonIn']),
            'AfternoonOut'          => oea_fmt_time($row['AfternoonOut']),
            'ScheduleAmIn'          => oea_fmt_time($row['TimeIn']),
            'ScheduleAmOut'         => '', // no confirmed source column yet — see note above
            'SchedulePmIn'          => oea_fmt_time($row['TimeInPM']),
            'SchedulePmOut'         => '', // no confirmed source column yet — see note above
            'AMLate'                => trim($row['AMLate'] ?? ''),
            'PMLate'                => trim($row['PMLate'] ?? ''),
            'Late1'                 => trim($row['Late1'] ?? ''),
            'MorningTotalHours'     => trim($row['MorningTotalHours'] ?? ''),
            'AfternoonTotalHours'   => trim($row['AfternoonTotalHours'] ?? ''),
            'MorningAfternoonTotal' => trim($row['MorningAfternoonTotal'] ?? ''),
            'TotalHours'            => trim($row['TotalHours'] ?? ''),
            'Status'                => trim($row['Status'] ?? ''),
            'DayCount'              => trim($row['DayCount'] ?? ''),
            'PayrollGroup'          => trim($row['PayrollGroup'] ?? ''),
        ],
    ]);
    exit;
}

if ($action === 'get_override_history') {

    // Shows the current user's own override submissions regardless of
    // review status, so they can see what's still pending HR. One row here
    // = one submission, which may carry a Corrected Time, Shift Times, or
    // both — the Details column summarizes whichever fields are present.
    $sql = "SELECT TOP 50
                [ADate], [OriginalTime], [ATime], [Direction], [ShiftPart],
                [SetTime], [SetTimeOutAM], [SetTimeInPM], [SetTimeOutPM],
                [AtimeIn], [AtimeOut], [AtimeOutAM], [AtimeInPM],
                [AMLate], [PMLate], [Late], [MorningTotalHours], [AfternoonTotalHours],
                [MorningAfternoonTotal], [TotalHours], [Status], [DayCount],
                [PayrollGroup], [Aday], [Area], [AreaOut], [Attachment],
                [Override_Type], [HR_Status], [DateTimeInput]
            FROM [dbo].[TBL_Attendance_Override]
            WHERE [EmployeeID] = ?
            ORDER BY [DateTimeInput] DESC";

    $stmt = sqlsrv_query($conn, $sql, [$employeeId]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    // Same 0/1/2 convention as the Leave Application module — unconfirmed
    // against this table specifically, please verify.
    $statusLabels = [0 => 'Pending', 1 => 'Approved', 2 => 'Rejected'];

    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $hasCorrection = trim($r['ShiftPart'] ?? '') !== '' || trim($r['Direction'] ?? '') !== '';
        $amIn  = oea_fmt_time($r['SetTime']);
        $amOut = oea_fmt_time($r['SetTimeOutAM']);
        $pmIn  = oea_fmt_time($r['SetTimeInPM']);
        $pmOut = oea_fmt_time($r['SetTimeOutPM']);
        $hasSchedule = $amIn !== '' || $amOut !== '' || $pmIn !== '' || $pmOut !== '';

        $parts = [];
        if ($hasCorrection) {
            $parts[] = trim(($r['ShiftPart'] ?: '—') . ' ' . ($r['Direction'] ?: '') . ': '
                . (oea_fmt_time($r['OriginalTime']) ?: '—') . ' → ' . (oea_fmt_time($r['ATime']) ?: '—'));
        }
        if ($hasSchedule) {
            $schedBits = [];
            if ($amIn)  $schedBits[] = 'AM In ' . $amIn;
            if ($amOut) $schedBits[] = 'AM Out ' . $amOut;
            if ($pmIn)  $schedBits[] = 'PM In ' . $pmIn;
            if ($pmOut) $schedBits[] = 'PM Out ' . $pmOut;
            $parts[] = implode(', ', $schedBits);
        }

        // Manual-entry fields (actual punches, lateness/totals, classification,
        // area, attachment) — summarized the same way as the two groups above.
        $manualBits = [];
        if (trim($r['Status'] ?? '') !== '') $manualBits[] = 'Status ' . trim($r['Status']);
        if ($r['AMLate'] !== null) $manualBits[] = 'AM Late ' . trim((string)$r['AMLate']);
        if ($r['PMLate'] !== null) $manualBits[] = 'PM Late ' . trim((string)$r['PMLate']);
        if ($r['TotalHours'] !== null) $manualBits[] = 'Total Hrs ' . trim((string)$r['TotalHours']);
        if (trim($r['Attachment'] ?? '') !== '') $manualBits[] = 'Has attachment';
        if ($manualBits) $parts[] = implode(', ', $manualBits);

        $rows[] = [
            'Date'          => oea_fmt_date($r['ADate']),
            'ShiftPart'     => trim($r['ShiftPart'] ?? ''),
            'Direction'     => trim($r['Direction'] ?? ''),
            'OriginalTime'  => oea_fmt_time($r['OriginalTime']),
            'CorrectedTime' => oea_fmt_time($r['ATime']),
            'ScheduleAmIn'  => $amIn,
            'ScheduleAmOut' => $amOut,
            'SchedulePmIn'  => $pmIn,
            'SchedulePmOut' => $pmOut,
            'Details'       => $parts ? implode(' · ', $parts) : '—',
            'Status'        => $statusLabels[(int)($r['HR_Status'] ?? 0)] ?? 'Pending',
            'Submitted'     => oea_fmt_date($r['DateTimeInput']),
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
            'Category'  => trim($row['Category'] ?? ''),
            'Type_Name' => trim($row['Type_Name']),
        ];
    }

    echo json_encode(['success' => true, 'types' => $results]);
    exit;
}

// ── Single submission, single row. The form on override-attendance.php now
//    shows both the point-correction fields (Shift Part/Direction/Corrected
//    Time) and the full-shift fields (AM/PM In/Out) together — the user
//    fills in whichever applies (one group, or both) and hits one Submit
//    button. This action writes only the columns that were actually filled;
//    a row can therefore end up as a point correction, a full shift
//    override, or both at once (all in the same TBL_Attendance_Override row).
if ($action === 'save_override') {

    // Mutating action — enforce full (not view-only) access and verify CSRF
    rbac_enforce_full_access('override_attendance', true);
    rbac_csrf_verify();

    $aDate         = trim($_POST['adate'] ?? '');
    $shiftPart     = trim($_POST['shift_part'] ?? '');
    $direction     = trim($_POST['direction'] ?? '');
    $originalTime  = trim($_POST['original_time'] ?? '');
    $correctedTime = trim($_POST['corrected_time'] ?? '');
    $overrideType  = trim($_POST['override_type'] ?? '');
    $remarks       = trim($_POST['remarks'] ?? '');

    $amIn  = trim($_POST['am_in']  ?? '');
    $amOut = trim($_POST['am_out'] ?? '');
    $pmIn  = trim($_POST['pm_in']  ?? '');
    $pmOut = trim($_POST['pm_out'] ?? '');

    // New manual-entry fields — all optional, all typed in directly, no
    // server-side computation or auto-fill.
    $atimeIn      = trim($_POST['atime_in']      ?? '');
    $atimeOutAm   = trim($_POST['atime_out_am']  ?? '');
    $atimeInPm    = trim($_POST['atime_in_pm']   ?? '');
    $atimeOut     = trim($_POST['atime_out']     ?? '');
    $amLate       = trim($_POST['am_late']       ?? '');
    $pmLate       = trim($_POST['pm_late']       ?? '');
    $late         = trim($_POST['late']          ?? '');
    $morningTotal = trim($_POST['morning_total_hours']   ?? '');
    $afternoonTotal = trim($_POST['afternoon_total_hours'] ?? '');
    $dayTotal     = trim($_POST['morning_afternoon_total'] ?? '');
    $totalHours   = trim($_POST['total_hours']   ?? '');
    $status       = trim($_POST['status']        ?? '');
    $dayCount     = trim($_POST['day_count']     ?? '');
    $payrollGroup = trim($_POST['payroll_group'] ?? '');
    $aday         = trim($_POST['aday']          ?? '');
    $area         = trim($_POST['area']          ?? '');
    $areaOut      = trim($_POST['area_out']      ?? '');

    $hasCorrection = $correctedTime !== '';
    $hasSchedule   = $amIn !== '' || $amOut !== '' || $pmIn !== '' || $pmOut !== '';
    $hasManual     = $atimeIn !== '' || $atimeOutAm !== '' || $atimeInPm !== '' || $atimeOut !== ''
        || $amLate !== '' || $pmLate !== '' || $late !== '' || $morningTotal !== '' || $afternoonTotal !== ''
        || $dayTotal !== '' || $totalHours !== '' || $status !== '' || $dayCount !== '' || $payrollGroup !== ''
        || $aday !== '' || $area !== '' || $areaOut !== '';

    $errors = [];
    if ($aDate === '') $errors[] = 'Attendance Date is required.';
    if (!$hasCorrection && !$hasSchedule && !$hasManual) {
        $errors[] = 'Fill in at least one field (a Corrected Time, a Shift Time, or one of the manual-entry fields below).';
    }
    if ($hasCorrection && ($shiftPart === '' || $direction === '')) {
        $errors[] = 'Shift Part and Direction are required when setting a Corrected Time.';
    }
    if ($overrideType === '') $errors[] = 'Override Type is required.';

    // Numeric fields — reject non-numeric input up front rather than letting
    // sqlsrv fail with an opaque "Error converting data type" message (this
    // is exactly what happened previously with Aday).
    $numericFields = [
        'AM Late' => $amLate, 'PM Late' => $pmLate, 'Late' => $late,
        'Morning Total Hours' => $morningTotal, 'Afternoon Total Hours' => $afternoonTotal,
        'Morning+Afternoon Total' => $dayTotal, 'Total Hours' => $totalHours,
        'Day Count' => $dayCount, 'Aday' => $aday,
    ];
    foreach ($numericFields as $label => $val) {
        if ($val !== '' && !is_numeric($val)) {
            $errors[] = "$label must be a number.";
        }
    }

    if ($errors) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // ── Attachment upload (optional) ────────────────────────────────────
    // ASSUMPTION (please confirm): [Attachment] is a varchar/nvarchar column
    // storing a file path, not a varbinary column storing the file itself.
    // Storage location/naming below is a reasonable default, not a confirmed
    // convention from elsewhere in the app — happy to change to match
    // wherever the rest of TWM keeps uploaded files if that's documented.
    $attachmentPath = null;
    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        $maxBytes   = 10 * 1024 * 1024; // 10MB
        $origName   = $_FILES['attachment']['name'];
        $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            echo json_encode(['success' => false, 'message' => 'Attachment type not allowed. Allowed: ' . implode(', ', $allowedExt)]);
            exit;
        }
        if ($_FILES['attachment']['size'] > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'Attachment is too large (max 10MB).']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/override_attachments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $fileName = $employeeId . '_' . date('YmdHis') . '_' . $safeName . '.' . $ext;

        if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fileName)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save attachment.']);
            exit;
        }
        $attachmentPath = 'uploads/override_attachments/' . $fileName;
    }

    $userId = (int)($_SESSION['UserID'] ?? 0);

    // NOTE: Aday is numeric(18,1) in the schema — now validated as numeric
    // above and included directly, since it's manually typed in by the user
    // rather than derived from a weekday name.
    $sql = "INSERT INTO [dbo].[TBL_Attendance_Override]
                ([EmployeeID], [ADate], [OriginalTime], [ATime], [Direction], [ShiftPart],
                 [SetTime], [SetTimeOutAM], [SetTimeInPM], [SetTimeOutPM],
                 [AtimeIn], [AtimeOut], [AtimeOutAM], [AtimeInPM],
                 [AMLate], [PMLate], [Late],
                 [MorningTotalHours], [AfternoonTotalHours], [MorningAfternoonTotal], [TotalHours],
                 [Status], [DayCount], [PayrollGroup], [Aday], [Area], [AreaOut], [Attachment],
                 [Override_Type], [Remarks], [HR_Status], [UserInput], [DateTimeInput])
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, GETDATE())";

    $params = [
        $employeeId,
        $aDate,
        $hasCorrection ? ($originalTime ?: null) : null,
        $hasCorrection ? $correctedTime : null,
        $hasCorrection ? $direction : null,
        $hasCorrection ? $shiftPart : null,
        $amIn  ?: null,
        $amOut ?: null,
        $pmIn  ?: null,
        $pmOut ?: null,
        $atimeIn    ?: null,
        $atimeOut   ?: null,
        $atimeOutAm ?: null,
        $atimeInPm  ?: null,
        $amLate       !== '' ? $amLate       : null,
        $pmLate       !== '' ? $pmLate       : null,
        $late         !== '' ? $late         : null,
        $morningTotal !== '' ? $morningTotal : null,
        $afternoonTotal !== '' ? $afternoonTotal : null,
        $dayTotal     !== '' ? $dayTotal     : null,
        $totalHours   !== '' ? $totalHours   : null,
        $status       ?: null,
        $dayCount     !== '' ? $dayCount     : null,
        $payrollGroup ?: null,
        $aday         !== '' ? $aday         : null,
        $area         ?: null,
        $areaOut      ?: null,
        $attachmentPath,
        $overrideType,
        $remarks,
        $userId,
    ];

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Insert error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Override request submitted. It is pending HR review and will not affect your attendance record until approved.',
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);