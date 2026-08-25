<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php'; // defines base_url()
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php'; // establishes $conn / $pdo
auth_check();

header('Content-Type: application/json');

// ASSUMPTION (please confirm): new RBAC module key for this page, following
// the same pattern as leave_application / leave_management being two keys
// for one feature. If you'd rather this reuse the existing
// 'override_attendance' key with a role check, let me know and I'll swap
// rbac_gate()/rbac_enforce_full_access() calls below accordingly.
rbac_gate($pdo, 'override_attendance_approval');

function opa_fmt_time($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('h:i A');
    $ts = strtotime($v);
    return $ts !== false ? date('h:i A', $ts) : (string)$v;
}

// 24-hour 'H:i' for populating an <input type="time"> in the edit modal.
function opa_fmt_time24($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('H:i');
    $ts = strtotime($v);
    return $ts !== false ? date('H:i', $ts) : '';
}

function opa_fmt_date($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('m/d/Y');
    $ts = strtotime($v);
    return $ts !== false ? date('m/d/Y', $ts) : (string)$v;
}

function opa_fmt_datetime($v): string {
    if ($v === null || $v === '') return '';
    if ($v instanceof DateTime) return $v->format('m/d/Y h:i A');
    $ts = strtotime($v);
    return $ts !== false ? date('m/d/Y h:i A', $ts) : (string)$v;
}

// The employee/user ID of the HR reviewer taking the action — stored back
// into HR_EmployeeID so approvals/rejections/edits are attributable.
// Same ASSUMPTION as override-attendance-ajax.php: $_SESSION['EmployeeID']
// is unconfirmed against the real login script. Falling back to UserID
// (cast to string) if EmployeeID isn't present in session.
function opa_reviewer_id(): string {
    $eid = trim($_SESSION['EmployeeID'] ?? '');
    if ($eid !== '') return $eid;
    return trim((string)($_SESSION['UserID'] ?? ''));
}

// A submission can now carry a point correction (ShiftPart/Direction/ATime),
// a full-shift schedule (SetTime*), or both at once — the form on
// override-attendance.php writes only whichever the employee filled in.
// Looked up server-side rather than trusted from the client, since which
// columns get written by update/approve/reject depends on it.
function opa_row_kind($conn, int $id): ?array {
    $stmt = sqlsrv_query($conn, "SELECT [ShiftPart], [Direction], [SetTime], [SetTimeOutAM], [SetTimeInPM], [SetTimeOutPM] FROM [dbo].[TBL_Attendance_Override] WHERE [ID] = ?", [$id]);
    if ($stmt === false) return null;
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$row) return null;
    $hasCorrection = trim($row['ShiftPart'] ?? '') !== '' || trim($row['Direction'] ?? '') !== '';
    $hasSchedule = $row['SetTime'] !== null || $row['SetTimeOutAM'] !== null
        || $row['SetTimeInPM'] !== null || $row['SetTimeOutPM'] !== null;
    $kind = $hasCorrection && $hasSchedule ? 'mixed' : ($hasCorrection ? 'time' : 'schedule');
    return ['kind' => $kind, 'hasCorrection' => $hasCorrection, 'hasSchedule' => $hasSchedule];
}

$action = $_GET['action'] ?? '';

if ($action === 'get_overrides') {

    $status = $_GET['status'] ?? '0'; // '0' Pending, '1' Approved, '2' Rejected
    if (!in_array($status, ['0', '1', '2'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status filter.']);
        exit;
    }

    // Override_Type on TBL_Attendance_Override stores Tbl_Override_Type.TypeID
    // as text (see override-attendance.php's save_override, which writes the
    // <select> value straight through) — cast TypeID to compare/join.
    $sql = "SELECT
                o.[ID], o.[EmployeeID], o.[ADate], o.[OriginalTime], o.[ATime],
                o.[Direction], o.[ShiftPart], o.[Remarks],
                o.[SetTime], o.[SetTimeOutAM], o.[SetTimeInPM], o.[SetTimeOutPM],
                o.[AtimeIn], o.[AtimeOut], o.[AtimeOutAM], o.[AtimeInPM],
                o.[AMLate], o.[PMLate], o.[Late], o.[MorningTotalHours], o.[AfternoonTotalHours],
                o.[MorningAfternoonTotal], o.[TotalHours], o.[Status] AS ManualStatus,
                o.[DayCount] AS ManualDayCount, o.[PayrollGroup] AS ManualPayrollGroup,
                o.[Aday], o.[Area], o.[AreaOut], o.[Attachment],
                o.[Override_Type], o.[HR_Status], o.[HR_EmployeeID], o.[HR_Approved_DateTimeInput],
                o.[UserInput], o.[DateTimeInput],
                e.[FirstName], e.[MiddleName], e.[LastName], e.[Department],
                t.[Type_Name], t.[Category] AS TypeCategory,
                v.[MorningIn] AS OrigMorningIn, v.[MorningOut] AS OrigMorningOut,
                v.[AfternoonIn] AS OrigAfternoonIn, v.[AfternoonOut] AS OrigAfternoonOut
            FROM [dbo].[TBL_Attendance_Override] o
            LEFT JOIN [dbo].[TBL_HREmployeeList] e ON e.[EmployeeID] = o.[EmployeeID]
            LEFT JOIN [dbo].[Tbl_Override_Type] t ON CAST(t.[TypeID] AS NVARCHAR(50)) = o.[Override_Type]
            LEFT JOIN [dbo].[View_ATtendanceTimeInTimeOut2_Override] v
                ON v.[EmployeeID] = o.[EmployeeID] AND CAST(v.[ADate] AS DATE) = CAST(o.[ADate] AS DATE)
            WHERE o.[HR_Status] = ?
            ORDER BY o.[DateTimeInput] DESC";

    $stmt = sqlsrv_query($conn, $sql, [(int)$status]);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $statusLabels = [0 => 'Pending', 1 => 'Approved', 2 => 'Rejected'];

    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $mid  = trim($r['MiddleName'] ?? '');
        $mi   = $mid !== '' ? mb_substr($mid, 0, 1) . '. ' : '';
        $name = trim(($r['FirstName'] ?? '') . ' ' . $mi . ($r['LastName'] ?? ''));

        $origAmIn  = opa_fmt_time($r['OrigMorningIn']);
        $origAmOut = opa_fmt_time($r['OrigMorningOut']);
        $origPmIn  = opa_fmt_time($r['OrigAfternoonIn']);
        $origPmOut = opa_fmt_time($r['OrigAfternoonOut']);
        $atimeIn    = opa_fmt_time($r['AtimeIn']);
        $atimeOutAM = opa_fmt_time($r['AtimeOutAM']);
        $atimeInPM  = opa_fmt_time($r['AtimeInPM']);
        $atimeOut   = opa_fmt_time($r['AtimeOut']);

        $punchChecks = [
            ['label' => 'AM In',  'orig' => $origAmIn,  'now' => $atimeIn],
            ['label' => 'AM Out', 'orig' => $origAmOut, 'now' => $atimeOutAM],
            ['label' => 'PM In',  'orig' => $origPmIn,  'now' => $atimeInPM],
            ['label' => 'PM Out', 'orig' => $origPmOut, 'now' => $atimeOut],
        ];
        $hasCorrection = false;
        foreach ($punchChecks as $c) {
            if ($c['now'] !== '' && $c['now'] !== $c['orig']) { $hasCorrection = true; break; }
        }
        $hasSchedule = $r['SetTime'] !== null || $r['SetTimeOutAM'] !== null
            || $r['SetTimeInPM'] !== null || $r['SetTimeOutPM'] !== null;
        $kind = $hasCorrection && $hasSchedule ? 'mixed' : ($hasCorrection ? 'time' : 'schedule');

        $row = [
            'ID'                  => (int)$r['ID'],
            'EmployeeID'          => trim($r['EmployeeID'] ?? ''),
            'EmployeeName'        => $name !== '' ? $name : trim($r['EmployeeID'] ?? ''),
            'Department'          => trim($r['Department'] ?? ''),
            'Date'                => opa_fmt_date($r['ADate']),
            'Kind'                => $kind,
            'ShiftPart'           => trim($r['ShiftPart'] ?? ''),
            'Direction'           => trim($r['Direction'] ?? ''),
            'OriginalTime'        => opa_fmt_time($r['OriginalTime']),
            'CorrectedTime'       => opa_fmt_time($r['ATime']),
            'CorrectedTime24'     => opa_fmt_time24($r['ATime']), // for <input type="time"> in modal
            'ScheduleAmIn'        => opa_fmt_time($r['SetTime']),
            'ScheduleAmIn24'      => opa_fmt_time24($r['SetTime']),
            'ScheduleAmOut'       => opa_fmt_time($r['SetTimeOutAM']),
            'ScheduleAmOut24'     => opa_fmt_time24($r['SetTimeOutAM']),
            'SchedulePmIn'        => opa_fmt_time($r['SetTimeInPM']),
            'SchedulePmIn24'      => opa_fmt_time24($r['SetTimeInPM']),
            'SchedulePmOut'       => opa_fmt_time($r['SetTimeOutPM']),
            'SchedulePmOut24'     => opa_fmt_time24($r['SetTimeOutPM']),
            'AtimeIn24'           => opa_fmt_time24($r['AtimeIn']),
            'AtimeOut24'          => opa_fmt_time24($r['AtimeOut']),
            'AtimeOutAM24'        => opa_fmt_time24($r['AtimeOutAM']),
            'AtimeInPM24'         => opa_fmt_time24($r['AtimeInPM']),
            'OrigAtimeIn'         => $origAmIn,
            'OrigAtimeOutAM'      => $origAmOut,
            'OrigAtimeInPM'       => $origPmIn,
            'OrigAtimeOut'        => $origPmOut,
            'AtimeInChanged'      => ($atimeIn    !== '' && $atimeIn    !== $origAmIn),
            'AtimeOutAMChanged'   => ($atimeOutAM !== '' && $atimeOutAM !== $origAmOut),
            'AtimeInPMChanged'    => ($atimeInPM  !== '' && $atimeInPM  !== $origPmIn),
            'AtimeOutChanged'     => ($atimeOut   !== '' && $atimeOut   !== $origPmOut),
            'AMLate'                => $r['AMLate'] !== null ? trim((string)$r['AMLate']) : '',
            'PMLate'                => $r['PMLate'] !== null ? trim((string)$r['PMLate']) : '',
            'Late'                  => $r['Late'] !== null ? trim((string)$r['Late']) : '',
            'MorningTotalHours'     => $r['MorningTotalHours'] !== null ? trim((string)$r['MorningTotalHours']) : '',
            'AfternoonTotalHours'   => $r['AfternoonTotalHours'] !== null ? trim((string)$r['AfternoonTotalHours']) : '',
            'MorningAfternoonTotal' => $r['MorningAfternoonTotal'] !== null ? trim((string)$r['MorningAfternoonTotal']) : '',
            'TotalHours'            => $r['TotalHours'] !== null ? trim((string)$r['TotalHours']) : '',
            'ManualStatus'          => trim($r['ManualStatus'] ?? ''),
            'ManualDayCount'        => $r['ManualDayCount'] !== null ? trim((string)$r['ManualDayCount']) : '',
            'ManualPayrollGroup'    => trim($r['ManualPayrollGroup'] ?? ''),
            'Aday'                  => $r['Aday'] !== null ? trim((string)$r['Aday']) : '',
            'Area'                  => trim($r['Area'] ?? ''),
            'AreaOut'               => trim($r['AreaOut'] ?? ''),
            // Attachment stores a relative path (see save_override) — build
            // a servable URL for the modal's "View attachment" link.
            'AttachmentUrl'       => trim($r['Attachment'] ?? '') !== '' ? trim($r['Attachment']) : null,
            'OverrideTypeID'      => trim($r['Override_Type'] ?? ''),
            'OverrideType'        => trim($r['Type_Name'] ?? ''),
            'OverrideCategoryRaw' => trim($r['TypeCategory'] ?? ''), // hedge-matched against category ID/name, same as override-attendance.php
            'Remarks'             => trim($r['Remarks'] ?? ''),
            'Status'              => $statusLabels[(int)($r['HR_Status'] ?? 0)] ?? 'Pending',
            'ReviewedBy'          => trim($r['HR_EmployeeID'] ?? ''),
            'ReviewedAt'          => opa_fmt_datetime($r['HR_Approved_DateTimeInput']),
            'Submitted'           => opa_fmt_datetime($r['DateTimeInput']),
        ];

        // Details summary — used by the list table's single "Details" column;
        // shows only what was actually changed. A row where the user
        // corrected multiple punches now lists all of them, not just one.
        $detailParts = [];
        $corrBits = [];
        foreach ($punchChecks as $c) {
            if ($c['now'] !== '' && $c['now'] !== $c['orig']) {
                $corrBits[] = $c['label'] . ': ' . ($c['orig'] ?: '—') . ' → ' . $c['now'];
            }
        }
        if ($corrBits) $detailParts[] = implode(', ', $corrBits);
        if ($hasSchedule) {
            $schedBits = [];
            if ($row['ScheduleAmIn'])  $schedBits[] = 'AM In ' . $row['ScheduleAmIn'];
            if ($row['ScheduleAmOut']) $schedBits[] = 'AM Out ' . $row['ScheduleAmOut'];
            if ($row['SchedulePmIn'])  $schedBits[] = 'PM In ' . $row['SchedulePmIn'];
            if ($row['SchedulePmOut']) $schedBits[] = 'PM Out ' . $row['SchedulePmOut'];
            $detailParts[] = implode(', ', $schedBits);
        }
        $row['Details'] = $detailParts ? implode(' · ', $detailParts) : '—';

        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'overrides' => $rows]);
    exit;
}

if ($action === 'get_counts') {

    $sql = "SELECT [HR_Status], COUNT(*) AS Cnt
            FROM [dbo].[TBL_Attendance_Override]
            GROUP BY [HR_Status]";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Query error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = $map[(int)($row['HR_Status'] ?? -1)] ?? null;
        if ($key !== null) $counts[$key] = (int)$row['Cnt'];
    }

    echo json_encode(['success' => true, 'counts' => $counts]);
    exit;
}

// Same lookups as override-attendance-ajax.php, duplicated here since this
// is a separate RBAC module — the modal's Category/Type dropdowns need them.
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
        $results[] = ['OverrideID' => $row['OverrideID'], 'Override_Name' => trim($row['Override_Name'])];
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
        $results[] = ['TypeID' => $row['TypeID'], 'Category' => trim($row['Category'] ?? ''), 'Type_Name' => trim($row['Type_Name'])];
    }

    echo json_encode(['success' => true, 'types' => $results]);
    exit;
}

if ($action === 'update_override' || $action === 'approve_override' || $action === 'reject_override') {

    // Mutating action — enforce full (not view-only) access and verify CSRF
    rbac_enforce_full_access('override_attendance_approval', true);
    rbac_csrf_verify();

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid override ID.']);
        exit;
    }

    $rowKind = opa_row_kind($conn, $id);
    if ($rowKind === null) {
        echo json_encode(['success' => false, 'message' => 'Override not found.']);
        exit;
    }

    $overrideType = trim($_POST['override_type'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');
    $reviewer     = opa_reviewer_id();

    // The review modal always shows both the point-correction fields and the
    // schedule fields together (mirroring the single merged submission form),
    // so both groups get written here based on whatever the reviewer left
    // filled in — not on the row's original kind. This lets HR add a schedule
    // to a correction-only row (or vice versa) while editing, same as the
    // employee could have on submission.
    $correctedTime = trim($_POST['corrected_time'] ?? '');
    $amIn  = trim($_POST['sched_am_in']  ?? '');
    $amOut = trim($_POST['sched_am_out'] ?? '');
    $pmIn  = trim($_POST['sched_pm_in']  ?? '');
    $pmOut = trim($_POST['sched_pm_out'] ?? '');

    // New manual-entry fields — same set as save_override, editable here
    // regardless of what the employee originally submitted.
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

    $anyCorrection = $correctedTime !== '';
    $anySchedule   = $amIn !== '' || $amOut !== '' || $pmIn !== '' || $pmOut !== '';
    $anyManual     = $atimeIn !== '' || $atimeOutAm !== '' || $atimeInPm !== '' || $atimeOut !== ''
        || $amLate !== '' || $pmLate !== '' || $late !== '' || $morningTotal !== '' || $afternoonTotal !== ''
        || $dayTotal !== '' || $totalHours !== '' || $status !== '' || $dayCount !== '' || $payrollGroup !== ''
        || $aday !== '' || $area !== '' || $areaOut !== '';

    if ($action !== 'update_override' && !$anyCorrection && !$anySchedule && !$anyManual) {
        echo json_encode(['success' => false, 'message' => 'Fill in at least one field (a Corrected Time, a Shift Time, or one of the manual-entry fields).']);
        exit;
    }
    if ($action !== 'update_override' && $overrideType === '') {
        echo json_encode(['success' => false, 'message' => 'Override Type is required.']);
        exit;
    }

    $numericFields = [
        'AM Late' => $amLate, 'PM Late' => $pmLate, 'Late' => $late,
        'Morning Total Hours' => $morningTotal, 'Afternoon Total Hours' => $afternoonTotal,
        'Morning+Afternoon Total' => $dayTotal, 'Total Hours' => $totalHours,
        'Day Count' => $dayCount, 'Aday' => $aday,
    ];
    foreach ($numericFields as $label => $val) {
        if ($val !== '' && !is_numeric($val)) {
            echo json_encode(['success' => false, 'message' => "$label must be a number."]);
            exit;
        }
    }

    $setClauses = [
        "[ATime] = ?", "[SetTime] = ?", "[SetTimeOutAM] = ?", "[SetTimeInPM] = ?", "[SetTimeOutPM] = ?",
        "[AtimeIn] = ?", "[AtimeOut] = ?", "[AtimeOutAM] = ?", "[AtimeInPM] = ?",
        "[AMLate] = ?", "[PMLate] = ?", "[Late] = ?",
        "[MorningTotalHours] = ?", "[AfternoonTotalHours] = ?", "[MorningAfternoonTotal] = ?", "[TotalHours] = ?",
        "[Status] = ?", "[DayCount] = ?", "[PayrollGroup] = ?", "[Aday] = ?", "[Area] = ?", "[AreaOut] = ?",
        "[Override_Type] = ?", "[Remarks] = ?",
    ];
    $params = [
        $correctedTime ?: null, $amIn ?: null, $amOut ?: null, $pmIn ?: null, $pmOut ?: null,
        $atimeIn ?: null, $atimeOut ?: null, $atimeOutAm ?: null, $atimeInPm ?: null,
        $amLate !== '' ? $amLate : null, $pmLate !== '' ? $pmLate : null, $late !== '' ? $late : null,
        $morningTotal !== '' ? $morningTotal : null, $afternoonTotal !== '' ? $afternoonTotal : null,
        $dayTotal !== '' ? $dayTotal : null, $totalHours !== '' ? $totalHours : null,
        $status ?: null, $dayCount !== '' ? $dayCount : null, $payrollGroup ?: null,
        $aday !== '' ? $aday : null, $area ?: null, $areaOut ?: null,
        $overrideType, $remarks,
    ];

    // ── Duplicate-approval guard: don't let a second row for the same
    //    EmployeeID+ADate become Approved. If one already exists, HR must
    //    reject/reopen it first — this preserves the audit trail instead of
    //    silently superseding the earlier Approved row.
    if ($action === 'approve_override') {
        $dupCheckSql = "SELECT [ID] FROM [dbo].[TBL_Attendance_Override]
                         WHERE [EmployeeID] = (SELECT [EmployeeID] FROM [dbo].[TBL_Attendance_Override] WHERE [ID] = ?)
                           AND [ADate] = (SELECT [ADate] FROM [dbo].[TBL_Attendance_Override] WHERE [ID] = ?)
                           AND [HR_Status] = 1
                           AND [ID] != ?";
        $dupCheckStmt = sqlsrv_query($conn, $dupCheckSql, [$id, $id, $id]);
        $existingApproved = $dupCheckStmt ? sqlsrv_fetch_array($dupCheckStmt, SQLSRV_FETCH_ASSOC) : null;

        if ($existingApproved) {
            echo json_encode([
                'success' => false,
                'message' => 'An approved override already exists for this employee and date. Reject or reopen it before approving this one.',
            ]);
            exit;
        }
    }

    if ($action === 'approve_override' || $action === 'reject_override') {
        $newStatus = $action === 'approve_override' ? 1 : 2;
        $setClauses[] = "[HR_Status] = ?";
        $setClauses[] = "[HR_EmployeeID] = ?";
        $setClauses[] = "[HR_Approved_DateTimeInput] = GETDATE()";
        $params[] = $newStatus;
        $params[] = $reviewer;
    }

    $params[] = $id;

    // Approve/Reject only ever act on a still-Pending row (guarded here),
    // same as before — prevents two HR users double-deciding the same row.
    // Save Changes (update_override) has no such guard: editing a typo on
    // an already-Approved/Rejected row is allowed, and — because the view
    // chain queries TBL_Attendance_Override live — editing ATime (or
    // SetTime/SetTimeOutAM/SetTimeInPM/SetTimeOutPM) on an Approved row
    // will immediately change what shows in attendance.
    $statusGuard = ($action === 'approve_override' || $action === 'reject_override') ? ' AND [HR_Status] = 0' : '';

    $sql = "UPDATE [dbo].[TBL_Attendance_Override] SET " . implode(', ', $setClauses) . " WHERE [ID] = ?" . $statusGuard;

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Update error.', 'errors' => sqlsrv_errors()]);
        exit;
    }

    if (($action === 'approve_override' || $action === 'reject_override') && sqlsrv_rows_affected($stmt) === 0) {
        echo json_encode(['success' => false, 'message' => 'This override was already reviewed by someone else. Refresh the list.']);
        exit;
    }

    $messages = [
        'update_override'  => 'Changes saved.',
        'approve_override' => 'Override approved. It now reflects in the employee\'s attendance record.',
        'reject_override'  => 'Override rejected.',
    ];

    echo json_encode(['success' => true, 'message' => $messages[$action]]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);