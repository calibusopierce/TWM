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

    // Senior dev added [TimeOutAM] and [TimeOutPM] to the view — wired in
    // below as ScheduleAmOut/SchedulePmOut, same pattern as TimeIn/TimeInPM.
    // Renamed from View_ATtendanceTimeInTimeOut2 -> ..._Override (same
    // column set) per the updated schema the team sent over.
    $sql = "SELECT [ADate], [Category], [MorningIn], [MorningOut], [AfternoonIn], [AfternoonOut],
                   [TimeIn], [TimeInPM], [TimeOutAM], [TimeOutPM], [AMLate], [PMLate], [Late1],
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

    // ── Also check for an EXISTING override row for this date, so the
    //    frontend can auto-fill from it (edit mode) instead of always
    //    defaulting to the raw system record. Whichever row exists most
    //    recently (there should only be one pending per date in practice,
    //    but ORDER BY + TOP 1 guards against duplicates).
    $ovSql = "SELECT TOP 1 [OriginalTime], [ATime], [Direction], [ShiftPart],
                     [AtimeIn], [AtimeOut], [AtimeOutAM], [AtimeInPM],
                     [SetTime], [SetTimeOutAM], [SetTimeInPM], [SetTimeOutPM],
                     [AMLate], [PMLate], [Late],
                     [MorningTotalHours], [AfternoonTotalHours], [MorningAfternoonTotal], [TotalHours],
                     [Status], [DayCount], [PayrollGroup], [Aday], [Area], [AreaOut]
              FROM [dbo].[TBL_Attendance_Override]
              WHERE [EmployeeID] = ? AND CAST([ADate] AS DATE) = CAST(? AS DATE)
              ORDER BY [DateTimeInput] DESC";
    $ovStmt = sqlsrv_query($conn, $ovSql, [$employeeId, $aDate]);
    $ovRow  = ($ovStmt !== false) ? sqlsrv_fetch_array($ovStmt, SQLSRV_FETCH_ASSOC) : false;

    $override = null;
    if ($ovRow) {
        $override = [
            'OriginalTime'          => oea_fmt_time($ovRow['OriginalTime']),
            'ATime'                 => oea_fmt_time($ovRow['ATime']),
            'Direction'             => trim($ovRow['Direction'] ?? ''),
            'ShiftPart'             => trim($ovRow['ShiftPart'] ?? ''),
            'AtimeIn'               => oea_fmt_time($ovRow['AtimeIn']),
            'AtimeOutAM'            => oea_fmt_time($ovRow['AtimeOutAM']),
            'AtimeInPM'             => oea_fmt_time($ovRow['AtimeInPM']),
            'AtimeOut'              => oea_fmt_time($ovRow['AtimeOut']),
            'SetTime'               => oea_fmt_time($ovRow['SetTime']),
            'SetTimeOutAM'          => oea_fmt_time($ovRow['SetTimeOutAM']),
            'SetTimeInPM'           => oea_fmt_time($ovRow['SetTimeInPM']),
            'SetTimeOutPM'          => oea_fmt_time($ovRow['SetTimeOutPM']),
            'AMLate'                => trim((string)($ovRow['AMLate'] ?? '')),
            'PMLate'                => trim((string)($ovRow['PMLate'] ?? '')),
            'Late'                  => trim((string)($ovRow['Late'] ?? '')),
            'MorningTotalHours'     => trim((string)($ovRow['MorningTotalHours'] ?? '')),
            'AfternoonTotalHours'   => trim((string)($ovRow['AfternoonTotalHours'] ?? '')),
            'MorningAfternoonTotal' => trim((string)($ovRow['MorningAfternoonTotal'] ?? '')),
            'TotalHours'            => trim((string)($ovRow['TotalHours'] ?? '')),
            'Status'                => trim($ovRow['Status'] ?? ''),
            'DayCount'              => trim((string)($ovRow['DayCount'] ?? '')),
            'PayrollGroup'          => trim($ovRow['PayrollGroup'] ?? ''),
            'Aday'                  => trim((string)($ovRow['Aday'] ?? '')),
            'Area'                  => trim($ovRow['Area'] ?? ''),
            'AreaOut'               => trim($ovRow['AreaOut'] ?? ''),
        ];
    }

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
            'ScheduleAmOut'         => oea_fmt_time($row['TimeOutAM']),
            'SchedulePmIn'          => oea_fmt_time($row['TimeInPM']),
            'SchedulePmOut'         => oea_fmt_time($row['TimeOutPM']),
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
            'override'              => $override,
        ],
    ]);
    exit;
}

if ($action === 'get_override_history') {

    // Shows the current user's own override submissions regardless of
    // review status, so they can see what's still pending HR. One row here
    // = one submission, which may carry Actual Punch corrections (up to 4,
    // diffed against the original system record below), Shift Times, or
    // both — the Details column summarizes only what was actually changed.
    $sql = "SELECT TOP 50
                o.[ADate], o.[OriginalTime], o.[ATime], o.[Direction], o.[ShiftPart],
                o.[SetTime], o.[SetTimeOutAM], o.[SetTimeInPM], o.[SetTimeOutPM],
                o.[AtimeIn], o.[AtimeOut], o.[AtimeOutAM], o.[AtimeInPM],
                o.[AMLate], o.[PMLate], o.[Late], o.[MorningTotalHours], o.[AfternoonTotalHours],
                o.[MorningAfternoonTotal], o.[TotalHours], o.[Status], o.[DayCount],
                o.[PayrollGroup], o.[Aday], o.[Area], o.[AreaOut], o.[Attachment],
                o.[Override_Type], o.[HR_Status], o.[DateTimeInput],
                v.[MorningIn] AS OrigMorningIn, v.[MorningOut] AS OrigMorningOut,
                v.[AfternoonIn] AS OrigAfternoonIn, v.[AfternoonOut] AS OrigAfternoonOut
            FROM [dbo].[TBL_Attendance_Override] o
            LEFT JOIN [dbo].[View_ATtendanceTimeInTimeOut2_Override] v
                ON v.[EmployeeID] = o.[EmployeeID] AND CAST(v.[ADate] AS DATE) = CAST(o.[ADate] AS DATE)
            WHERE o.[EmployeeID] = ?
            ORDER BY o.[DateTimeInput] DESC";

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
        $amIn  = oea_fmt_time($r['SetTime']);
        $amOut = oea_fmt_time($r['SetTimeOutAM']);
        $pmIn  = oea_fmt_time($r['SetTimeInPM']);
        $pmOut = oea_fmt_time($r['SetTimeOutPM']);
        $hasSchedule = $amIn !== '' || $amOut !== '' || $pmIn !== '' || $pmOut !== '';

        // Diff each of the 4 actual punches against the original system
        // record (joined in via the view above) so a row where the user
        // corrected MULTIPLE punches shows all of them, not just one.
        $punchChecks = [
            ['label' => 'AM In',  'orig' => oea_fmt_time($r['OrigMorningIn']),   'now' => oea_fmt_time($r['AtimeIn'])],
            ['label' => 'AM Out', 'orig' => oea_fmt_time($r['OrigMorningOut']),  'now' => oea_fmt_time($r['AtimeOutAM'])],
            ['label' => 'PM In',  'orig' => oea_fmt_time($r['OrigAfternoonIn']), 'now' => oea_fmt_time($r['AtimeInPM'])],
            ['label' => 'PM Out', 'orig' => oea_fmt_time($r['OrigAfternoonOut']),'now' => oea_fmt_time($r['AtimeOut'])],
        ];
        $corrBits = [];
        foreach ($punchChecks as $c) {
            if ($c['now'] !== '' && $c['now'] !== $c['orig']) {
                $corrBits[] = $c['label'] . ': ' . ($c['orig'] ?: '—') . ' → ' . $c['now'];
            }
        }

        $parts = [];
        if ($corrBits) $parts[] = implode(', ', $corrBits);
        if ($hasSchedule) {
            $schedBits = [];
            if ($amIn)  $schedBits[] = 'AM In ' . $amIn;
            if ($amOut) $schedBits[] = 'AM Out ' . $amOut;
            if ($pmIn)  $schedBits[] = 'PM In ' . $pmIn;
            if ($pmOut) $schedBits[] = 'PM Out ' . $pmOut;
            $parts[] = implode(', ', $schedBits);
        }

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

    // ── Field names below match TBL_Attendance_Override columns exactly —
    //    the frontend now posts PascalCase keys 1:1 with the table, no more
    //    snake_case translation layer. ────────────────────────────────────
    $aDate         = trim($_POST['ADate'] ?? '');
    $originalTime  = trim($_POST['OriginalTime'] ?? ''); // always blank now — legacy column, no longer populated
    $aTime         = trim($_POST['ATime'] ?? '');
    $direction     = trim($_POST['Direction'] ?? '');
    $shiftPart     = trim($_POST['ShiftPart'] ?? '');

    $atimeIn    = trim($_POST['AtimeIn']    ?? '');
    $atimeOutAm = trim($_POST['AtimeOutAM'] ?? '');
    $atimeInPm  = trim($_POST['AtimeInPM']  ?? '');
    $atimeOut   = trim($_POST['AtimeOut']   ?? '');

    $amLate       = trim($_POST['AMLate'] ?? '');
    $pmLate       = trim($_POST['PMLate'] ?? '');
    $late         = trim($_POST['Late']   ?? '');
    $morningTotal = trim($_POST['MorningTotalHours']     ?? '');
    $afternoonTotal = trim($_POST['AfternoonTotalHours'] ?? '');
    $dayTotal     = trim($_POST['MorningAfternoonTotal']  ?? '');
    $totalHours   = trim($_POST['TotalHours']  ?? '');
    $dayCount     = trim($_POST['DayCount']    ?? '');

    $amIn  = trim($_POST['SetTime']       ?? '');
    $amOut = trim($_POST['SetTimeOutAM']  ?? '');
    $pmIn  = trim($_POST['SetTimeInPM']   ?? '');
    $pmOut = trim($_POST['SetTimeOutPM']  ?? '');

    $status       = trim($_POST['Status']        ?? '');
    $payrollGroup = trim($_POST['PayrollGroup']  ?? '');
    $aday         = trim($_POST['Aday']          ?? '');
    $area         = trim($_POST['Area']          ?? '');
    $areaOut      = trim($_POST['AreaOut']       ?? '');

    $overrideType = trim($_POST['Override_Type'] ?? '');
    $remarks      = trim($_POST['Remarks']       ?? '');

    // ── Validation — Today's Attendance is now always auto-filled with a
    //    real default (either the existing override row or the actual
    //    punch times) before the user even touches anything, so we no
    //    longer need the old "at least one group filled" check. Just
    //    require the two things that truly can't have a sane default:
    //    the date, and the reason for the override. ──────────────────────
    $errors = [];
    if ($aDate === '') $errors[] = 'Attendance Date is required.';
    if ($overrideType === '') $errors[] = 'Override Type is required.';

    // Numeric fields — reject non-numeric input up front rather than letting
    // sqlsrv fail with an opaque "Error converting data type" message.
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
    $attachmentPath = null;
    if (!empty($_FILES['Attachment']['name']) && $_FILES['Attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        $maxBytes   = 10 * 1024 * 1024; // 10MB
        $origName   = $_FILES['Attachment']['name'];
        $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            echo json_encode(['success' => false, 'message' => 'Attachment type not allowed. Allowed: ' . implode(', ', $allowedExt)]);
            exit;
        }
        if ($_FILES['Attachment']['size'] > $maxBytes) {
            echo json_encode(['success' => false, 'message' => 'Attachment is too large (max 10MB).']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/override_attachments/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
        $fileName = $employeeId . '_' . date('YmdHis') . '_' . $safeName . '.' . $ext;

        if (!move_uploaded_file($_FILES['Attachment']['tmp_name'], $uploadDir . $fileName)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save attachment.']);
            exit;
        }
        $attachmentPath = 'uploads/override_attachments/' . $fileName;
    }

    $userId = (int)($_SESSION['UserID'] ?? 0);

    // ── Duplicate-row guard: if a Pending override already exists for this
    //    EmployeeID+ADate, UPDATE it in place instead of inserting a new
    //    row. Without this, resubmitting (e.g. tweaking a punch time by a
    //    minute) stacked a new Pending row on top of the old one instead of
    //    replacing it — the root cause of multiple Approved rows later
    //    showing up for the same EmployeeID+ADate.
    $pendingCheckSql = "SELECT [ID], [Attachment] FROM [dbo].[TBL_Attendance_Override]
                         WHERE [EmployeeID] = ? AND [ADate] = ? AND [HR_Status] = 0";
    $pendingCheckStmt = sqlsrv_query($conn, $pendingCheckSql, [$employeeId, $aDate]);
    $existingPending = $pendingCheckStmt ? sqlsrv_fetch_array($pendingCheckStmt, SQLSRV_FETCH_ASSOC) : null;

    // Keep the previous attachment if this resubmission didn't upload a new one.
    if ($existingPending && $attachmentPath === null) {
        $attachmentPath = $existingPending['Attachment'];
    }

    // NOTE: Aday is numeric(18,1) in the schema — validated as numeric above.
    if ($existingPending) {
        $existingId = (int)$existingPending['ID'];

        $sql = "UPDATE [dbo].[TBL_Attendance_Override]
                SET [OriginalTime] = ?, [ATime] = ?, [Direction] = ?, [ShiftPart] = ?,
                    [SetTime] = ?, [SetTimeOutAM] = ?, [SetTimeInPM] = ?, [SetTimeOutPM] = ?,
                    [AtimeIn] = ?, [AtimeOut] = ?, [AtimeOutAM] = ?, [AtimeInPM] = ?,
                    [AMLate] = ?, [PMLate] = ?, [Late] = ?,
                    [MorningTotalHours] = ?, [AfternoonTotalHours] = ?, [MorningAfternoonTotal] = ?, [TotalHours] = ?,
                    [Status] = ?, [DayCount] = ?, [PayrollGroup] = ?, [Aday] = ?, [Area] = ?, [AreaOut] = ?, [Attachment] = ?,
                    [Override_Type] = ?, [Remarks] = ?, [UserInput] = ?, [DateTimeInput] = GETDATE()
                WHERE [ID] = ?";

        $params = [
            $originalTime !== '' ? $originalTime : null,
            $aTime        !== '' ? $aTime        : null,
            $direction    !== '' ? $direction    : null,
            $shiftPart    !== '' ? $shiftPart    : null,
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
            $existingId,
        ];
    } else {
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
            $originalTime !== '' ? $originalTime : null,
            $aTime        !== '' ? $aTime        : null,
            $direction    !== '' ? $direction    : null,
            $shiftPart    !== '' ? $shiftPart    : null,
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
    }

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => ($existingPending ? 'Update' : 'Insert') . ' error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $existingPending
            ? 'Override request updated. It is still pending HR review.'
            : 'Override request submitted. It is pending HR review and will not affect your attendance record until approved.',
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);