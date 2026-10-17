<?php
// ══════════════════════════════════════════════════════════════
//  view-applications.php
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'view_applications');

// ══ AJAX: fetch applicant detail + generate FileNo/EmployeeID ══
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['_action']) && $_GET['_action'] === 'fetch_applicant') {
    header('Content-Type: application/json');

    $appID = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
    if (!$appID) { echo json_encode(['success' => false, 'message' => 'Missing application ID.']); exit; }

    // ── Fetch full applicant record using exact column names ─
    $appSql = "
        SELECT
            ja.ApplicationID,
            ja.Fullname,
            ja.Email,
            ja.Phone,
            ja.Position,
            ja.DateApplied,
            ja.DepartmentID,
            ja.TransferredToEmployee,
            ja.FirstName,
            ja.MiddleName,
            ja.LastName,
            ja.Mobile_Number,
            ja.Birth_date,
            ja.Birth_Place,
            ja.Gender,
            ja.Civil_Status,
            ja.Nationality,
            ja.Religion,
            ja.Present_Address,
            ja.Permanent_Address,
            ja.SSS_Number,
            ja.TIN_Number,
            ja.Philhealth_Number,
            ja.HDMF,
            ja.Contact_Person,
            ja.Relationship,
            ja.Contact_Number_Emergency,
            ja.Educational_Background,
            ja.Notes,
            d.DepartmentName
        FROM   [dbo].[JobApplications] ja
        LEFT JOIN [dbo].[Departments] d ON ja.DepartmentID = d.DepartmentID
        WHERE  ja.ApplicationID = ?";

    $appStmt = sqlsrv_query($conn, $appSql, [$appID]);
    if (!$appStmt) {
        $err = sqlsrv_errors();
        echo json_encode(['success' => false, 'message' => $err[0]['message'] ?? 'Query failed.']);
        exit;
    }
    $app = sqlsrv_fetch_array($appStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($appStmt);
    if (!$app) {
        echo json_encode(['success' => false, 'message' => 'Applicant not found.']); exit;
    }

    // ── Serialize DateTime fields ────────────────────────────
    foreach (['DateApplied', 'Birth_date'] as $df) {
        if (isset($app[$df]) && $app[$df] instanceof DateTime) {
            $app[$df] = $app[$df]->format('Y-m-d');
        } elseif (isset($app[$df]) && is_string($app[$df]) && $app[$df]) {
            $app[$df] = substr($app[$df], 0, 10);
        } else {
            $app[$df] = null;
        }
    }

    // ── Name fallback: if FirstName/LastName empty, parse Fullname
    $firstName  = trim($app['FirstName']  ?? '');
    $lastName   = trim($app['LastName']   ?? '');
    $middleName = trim($app['MiddleName'] ?? '');
    if ($firstName === '' && $lastName === '') {
        $parts     = array_values(array_filter(explode(' ', trim($app['Fullname'] ?? ''))));
        $firstName = $parts[0] ?? '';
        $lastName  = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';
    }

    // ── Generate next FileNo (MAX + 1) ───────────────────────
    $fnStmt = sqlsrv_query($conn,
        "SELECT ISNULL(MAX(CAST(FileNo AS INT)), 0) + 1 AS NextFileNo
         FROM [dbo].[TBL_HREmployeeList]");
    $fnRow      = $fnStmt ? sqlsrv_fetch_array($fnStmt, SQLSRV_FETCH_ASSOC) : null;
    $nextFileNo = (int)($fnRow['NextFileNo'] ?? 1);
    if ($fnStmt) sqlsrv_free_stmt($fnStmt);

    // ── Build EmployeeID: TID-{FileNo}-{Year} ────────────────
    $generatedEmployeeID = 'TID-' . $nextFileNo . '-' . date('Y');

    echo json_encode([
        'success'                  => true,
        // Generated IDs (shown read-only at top of modal)
        'NextFileNo'               => $nextFileNo,
        'GeneratedEmpID'           => $generatedEmployeeID,
        // Transfer status
        'TransferredToEmployee'    => !empty($app['TransferredToEmployee']),
        // Core
        'ApplicationID'            => (int)$app['ApplicationID'],
        'FullName'                 => $app['Fullname'] ?? $app['FullName'] ?? '',
        'FirstName'                => $firstName,
        'MiddleName'               => $middleName,
        'LastName'                 => $lastName,
        'Email'                    => $app['Email']            ?? '',
        'Phone'                    => $app['Phone']            ?? '',
        'Mobile_Number'            => $app['Mobile_Number']    ?? '',
        'Position'                 => $app['Position']         ?? '',
        'DepartmentName'           => $app['DepartmentName']   ?? '',
        // Personal
        'Birth_date'               => $app['Birth_date'],
        'Birth_Place'              => $app['Birth_Place']      ?? '',
        'Gender'                   => $app['Gender']           ?? '',
        'Civil_Status'             => $app['Civil_Status']     ?? '',
        'Nationality'              => $app['Nationality'] !== '' ? $app['Nationality'] : 'Filipino',
        'Religion'                 => $app['Religion']         ?? '',
        // Address
        'Present_Address'          => $app['Present_Address']  ?? '',
        'Permanent_Address'        => $app['Permanent_Address'] ?? '',
        // Government IDs
        'SSS_Number'               => $app['SSS_Number']       ?? '',
        'TIN_Number'               => $app['TIN_Number']       ?? '',
        'Philhealth_Number'        => $app['Philhealth_Number'] ?? '',
        'HDMF'                     => $app['HDMF']             ?? '',
        // Emergency
        'Contact_Person'           => $app['Contact_Person']   ?? '',
        'Relationship'             => $app['Relationship']     ?? '',
        'Contact_Number_Emergency' => $app['Contact_Number_Emergency'] ?? '',
        // Others
        'Educational_Background'   => $app['Educational_Background'] ?? '',
        'Notes'                    => $app['Notes']            ?? '',
    ]);
    exit;
}

// ── Session context ────────────────────────────────────────────
$_userType   = $_SESSION['UserType']     ?? '';
$_userDept   = $_SESSION['Department']   ?? '';
$_userDeptID = $_SESSION['DepartmentID'] ?? null;
$_userName   = $_SESSION['DisplayName']  ?? $_SESSION['Username'] ?? 'User';
$isAdmin     = in_array($_userType, ['Admin', 'Administrator']);

// Guard: HR must have a department assigned
if (!$isAdmin && empty($_userDeptID)) {
    die('<div class="alert alert-danger m-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Your account has no department assigned. Please contact your administrator.
    </div>');
}

// ── View-All toggle (Admin only) ───────────────────────────────
// Stored in session so it persists across pagination/filter changes.
// Redirect immediately to strip ?view_all from URL — keeps pagination links clean.
if ($isAdmin && isset($_GET['view_all'])) {
    $_SESSION['view_all_depts'] = filter_var($_GET['view_all'], FILTER_VALIDATE_BOOLEAN);
    $clean = $_GET;
    unset($clean['view_all']);
    header('Location: ?' . http_build_query($clean));
    exit;
}
$viewAll = $isAdmin && ($_SESSION['view_all_depts'] ?? false);

// ══ Remarks: edit permission, CSRF, save endpoint ══
if (empty($_SESSION['va_csrf'])) $_SESSION['va_csrf'] = bin2hex(random_bytes(16));
$canEditRemarks = !(function_exists('rbac_is_view_only') && rbac_is_view_only('view_applications'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_remarks') {
    header('Content-Type: application/json');
    $out = function (bool $ok, string $msg = '', array $extra = []): void {
        echo json_encode(['success' => $ok, 'message' => $msg] + $extra);
        exit;
    };
    if (!hash_equals($_SESSION['va_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) $out(false, 'Session expired. Please refresh the page.');
    if (!$canEditRemarks) $out(false, 'You do not have permission to edit remarks.');

    $appID = (int)($_POST['application_id'] ?? 0);
    $field = (string)($_POST['field'] ?? '');
    $cols  = ['hr' => 'HRRemarks', 'rejection' => 'RejectionRemarks'];
    if ($appID <= 0 || !isset($cols[$field])) $out(false, 'Invalid request.');

    $text = trim(preg_replace('/\r\n?/', "\n", (string)($_POST['remarks'] ?? '')));
    if (mb_strlen($text, 'UTF-8') > 1000) $out(false, 'Remarks must be 1000 characters or fewer.');

    $chk = sqlsrv_query($conn,
        "SELECT ja.Status, d.DepartmentName
         FROM JobApplications ja LEFT JOIN Departments d ON ja.DepartmentID = d.DepartmentID
         WHERE ja.ApplicationID = ?", [$appID]);
    $r = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$r) $out(false, 'Applicant not found.');
    $st = (int)$r['Status'];

    if (!$isAdmin && $st !== 0 && $_userDept !== '' && strcasecmp(trim((string)$r['DepartmentName']), trim((string)$_userDept)) !== 0) {
        $out(false, 'You do not have access to this application.');
    }
    if ($field === 'rejection') {
        if ($st !== 7) $out(false, 'Rejection remarks only apply to rejected applications.');
        if ($text === '') $out(false, 'Rejection remarks cannot be empty.');
    }

    $col = $cols[$field];
    $upd = sqlsrv_query($conn,
        "UPDATE JobApplications SET {$col} = ?, RemarksUpdatedBy = ?, RemarksUpdatedAt = GETDATE() WHERE ApplicationID = ?",
        [$text === '' ? null : $text, mb_substr((string)$_userName, 0, 100, 'UTF-8'), $appID]);
    if (!$upd) { $err = sqlsrv_errors(); $out(false, $err[0]['message'] ?? 'Could not save remarks.'); }
    $out(true, 'Saved.', ['remarks' => $text]);
}

// ── Current tab ─────────────────────────────────────────────────
$tabAliases = [
    'active' => 'interview',
];
$validTabs = ['pending', 'evaluating', 'interview', 'hired', 'rejected', 'walkin'];
$requestedTab = strtolower(trim($_GET['tab'] ?? ''));
if ($requestedTab !== '' && isset($tabAliases[$requestedTab])) {
    $requestedTab = $tabAliases[$requestedTab];
}
$activeTab = in_array($requestedTab, $validTabs, true) ? $requestedTab : 'pending';

$tabStatusGroups = [
    'pending'    => [0],
    'evaluating' => [1],
    'interview'  => [2, 3, 4, 5],
    'hired'      => [6],
    'rejected'   => [7],
    'walkin'     => [8],
];

$tabDisplayNames = [
    'pending'    => 'Pending',
    'evaluating' => 'Evaluating',
    'interview'  => 'Interview',
    'hired'      => 'Hired',
    'rejected'   => 'Rejected',
    'walkin'     => 'Manual Entry',
];

$tabIcons = [
    'pending'    => 'bi-inbox-fill',
    'evaluating' => 'bi-hourglass-split',
    'interview'  => 'bi-calendar-event',
    'hired'      => 'bi-award',
    'rejected'   => 'bi-x-circle-fill',
    'walkin'     => 'bi-person-walking',
];

// ── Status definitions ─────────────────────────────────────────
$statusLabels = [
    0 => 'Pending',
    1 => 'Evaluating',
    2 => 'For Interview',
    3 => 'Re-schedule Interview',
    4 => 'Final Interview',
    5 => 'Final Interview Rescheduled',
    6 => 'Hired',
    7 => 'Rejected',
    8 => 'Manual Entry',
];
$statusBadgeClass = [
    0 => 's-0', 1 => 's-1', 2 => 's-2', 3 => 's-3',
    4 => 's-4', 5 => 's-5', 6 => 's-6', 7 => 's-7', 8 => 's-8',
];

// ── Departments — include ColorCode ───────────────────────────
$departments = [];   // full rows for dropdowns
$deptColorMap = [];  // [DepartmentID => ColorCode] for fast lookup

$deptStmt = sqlsrv_query($conn,
    "SELECT DepartmentID, DepartmentName, ColorCode
     FROM Departments WHERE Status = 1 ORDER BY DepartmentName");
if ($deptStmt) {
    while ($r = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
        $departments[]                         = $r;
        $deptColorMap[(int)$r['DepartmentID']] = $r['ColorCode'] ?? null;
    }
    sqlsrv_free_stmt($deptStmt);
}

// ── HR Contacts ────────────────────────────────────────────────
$hrContacts = [];
$hrStmt = sqlsrv_query($conn,
    "SELECT FileNo, FirstName, LastName, Department
     FROM TBL_HREmployeeList
     WHERE Position_held LIKE '%HR%' AND Active = 1
     ORDER BY LastName, FirstName");
if ($hrStmt) {
    while ($r = sqlsrv_fetch_array($hrStmt, SQLSRV_FETCH_ASSOC)) $hrContacts[] = $r;
    sqlsrv_free_stmt($hrStmt);
}

// ── File categories (for walk-in document uploads) ──────────────
$fileCategories = [];
$fcStmt = sqlsrv_query($conn, "SELECT FileCategoryID, CategoryName FROM FileCategories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName");
if ($fcStmt) {
    while ($r = sqlsrv_fetch_array($fcStmt, SQLSRV_FETCH_ASSOC)) $fileCategories[] = $r;
    sqlsrv_free_stmt($fcStmt);
}

// ══ AJAX: full applicant details (row-click modal) ═════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['_action'] ?? '') === 'view_applicant') {
    header('Content-Type: application/json');
    $send = function (array $payload): void {
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json !== false ? $json : '{"success":false,"message":"Could not encode the response."}';
        exit;
    };

    $appID = (int)($_GET['application_id'] ?? 0);
    if ($appID <= 0) $send(['success' => false, 'message' => 'Missing application ID.']);

    $stmt = sqlsrv_query($conn,
        "SELECT ja.*, d.DepartmentName AS DeptNameJoined
         FROM   JobApplications ja
         LEFT JOIN Departments d ON ja.DepartmentID = d.DepartmentID
         WHERE  ja.ApplicationID = ?", [$appID]);
    if (!$stmt) {
        $err = sqlsrv_errors();
        $send(['success' => false, 'message' => $err[0]['message'] ?? 'Query failed.']);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$row) $send(['success' => false, 'message' => 'Applicant not found.']);

    // Lower-case keys so column-name casing in the table can't break lookups
    $a  = array_change_key_case($row, CASE_LOWER);
    $g  = fn(string $k): string => is_scalar($a[$k] ?? null) ? trim((string)$a[$k]) : '';
    $st = (int)($a['status'] ?? 0);
    $deptName = trim((string)($a['deptnamejoined'] ?? ''));

    // Same scoping as the list: non-admins only see their own department (Pending is shared)
    if (!$isAdmin && $st !== 0 && $_userDept !== '' && strcasecmp($deptName, trim((string)$_userDept)) !== 0) {
        $send(['success' => false, 'message' => 'You do not have access to this application.']);
    }

    $fmt = function ($v, string $f): string {
        if ($v instanceof DateTimeInterface) return $v->format($f);
        if (is_string($v) && trim($v) !== '' && ($ts = strtotime($v)) !== false) return date($f, $ts);
        return '';
    };

    $first = $g('firstname'); $mid = $g('middlename'); $last = $g('lastname');
    $fullName = $g('fullname');
    if ($fullName === '') $fullName = trim(implode(' ', array_filter([$first, $mid, $last])));

    // Files
    $files = [];
    $fs = sqlsrv_query($conn,
        "SELECT af.FileName, af.FilePath, fc.CategoryName
         FROM   ApplicationFiles af
         LEFT JOIN FileCategories fc ON af.FileCategoryID = fc.FileCategoryID
         WHERE  af.ApplicationID = ?
         ORDER BY af.FileID", [$appID]);
    if ($fs) {
        while ($f = sqlsrv_fetch_array($fs, SQLSRV_FETCH_ASSOC)) {
            $files[] = [
                'FileName'     => (string)($f['FileName'] ?? ''),
                'FilePath'     => (string)($f['FilePath'] ?? ''),
                'FileCategory' => ($f['CategoryName'] ?? '') ?: 'Document',
            ];
        }
        sqlsrv_free_stmt($fs);
    }

    // Interview history (newest first)
    $hrMap = [];
    foreach ($hrContacts as $h) {
        $hrMap[trim((string)($h['FileNo'] ?? ''))] = trim(($h['FirstName'] ?? '') . ' ' . ($h['LastName'] ?? ''));
    }
    $interviews = [];
    $is = sqlsrv_query($conn,
        "SELECT InterviewDateTime, OfficeAddress, HRContactFileNo
         FROM   JobApplicationsInterview
         WHERE  ApplicationID = ?
         ORDER BY InterviewDateTime DESC, InterviewID DESC", [$appID]);
    if ($is) {
        while ($i = sqlsrv_fetch_array($is, SQLSRV_FETCH_ASSOC)) {
            $hrFn = trim((string)($i['HRContactFileNo'] ?? ''));
            $interviews[] = [
                'When'      => $fmt($i['InterviewDateTime'] ?? null, 'M j, Y g:i A'),
                'Address'   => trim((string)($i['OfficeAddress'] ?? '')),
                'HRContact' => ($hrMap[$hrFn] ?? '') ?: $hrFn,
            ];
        }
        sqlsrv_free_stmt($is);
    }

    $send([
        'success'                  => true,
        'ApplicationID'            => $appID,
        'FullName'                 => $fullName,
        'Status'                   => $st,
        'StatusLabel'              => $statusLabels[$st] ?? 'Unknown',
        'Position'                 => $g('position'),
        'DepartmentName'           => $deptName,
        'ApplicationType'          => $g('applicationtype') ?: 'Online',
        'DateApplied'              => $fmt($a['dateapplied'] ?? null, 'M j, Y g:i A'),
        'Transferred'              => !empty($a['transferredtoemployee']),
        'Email'                    => $g('email'),
        'Phone'                    => $g('phone'),
        'Mobile_Number'            => $g('mobile_number'),
        'Birth_date'               => $fmt($a['birth_date'] ?? null, 'M j, Y'),
        'Birth_Place'              => $g('birth_place'),
        'Gender'                   => $g('gender'),
        'Civil_Status'             => $g('civil_status'),
        'Nationality'              => $g('nationality'),
        'Religion'                 => $g('religion'),
        'Present_Address'          => $g('present_address'),
        'Permanent_Address'        => $g('permanent_address'),
        'SSS_Number'               => $g('sss_number'),
        'TIN_Number'               => $g('tin_number'),
        'Philhealth_Number'        => $g('philhealth_number'),
        'HDMF'                     => $g('hdmf'),
        'Contact_Person'           => $g('contact_person'),
        'Relationship'             => $g('relationship'),
        'Contact_Number_Emergency' => $g('contact_number_emergency'),
        'Educational_Background'   => $g('educational_background'),
        'Notes'                    => $g('notes'),
        'RejectionRemarks'         => $g('rejectionremarks'),
        'HRRemarks'                => $g('hrremarks'),
        'RemarksUpdatedBy'         => $g('remarksupdatedby'),
        'RemarksUpdatedAt'         => $fmt($a['remarksupdatedat'] ?? null, 'M j, Y g:i A'),
        'CanEditRemarks'           => $canEditRemarks,
        'FilesLocked'              => $st === 7,          // same rule as the list: rejected = files locked
        'FileCount'                => count($files),
        'Files'                    => $st === 7 ? [] : $files,
        'Interviews'               => $interviews,
    ]);
}

// ══ Query helpers ══════════════════════════════════════════════

function fetchApps($conn, $whereSQL, $params, $offset, $perPage): array
{
    $sql = "
    WITH Paged AS (
        SELECT ja.ApplicationID
        FROM   JobApplications ja
        LEFT JOIN Departments d ON ja.DepartmentID = d.DepartmentID
        {$whereSQL}
        ORDER BY ja.DateApplied DESC
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    )
    SELECT
        ja.ApplicationID, ja.FullName, ja.Email, ja.Phone,
        ja.Mobile_Number,
        ja.Position, ja.Status, ja.DateApplied, ja.DepartmentID,
        ja.ApplicationType,
        ja.TransferredToEmployee,
        ja.RejectionRemarks, ja.HRRemarks,
        d.DepartmentName, d.ColorCode,
        af.FileID, af.FileName, af.FilePath,
        fc.CategoryName AS FileCategory,
        li.InterviewDateTime, li.OfficeAddress, li.HRContactFileNo
    FROM Paged p
    JOIN  JobApplications ja ON ja.ApplicationID = p.ApplicationID
    LEFT JOIN Departments      d  ON ja.DepartmentID  = d.DepartmentID
    LEFT JOIN ApplicationFiles af ON ja.ApplicationID = af.ApplicationID
    LEFT JOIN FileCategories   fc ON af.FileCategoryID = fc.FileCategoryID
    OUTER APPLY (
        SELECT TOP 1 InterviewDateTime, OfficeAddress, HRContactFileNo
        FROM JobApplicationsInterview ij
        WHERE ij.ApplicationID = ja.ApplicationID
        ORDER BY ij.InterviewDateTime DESC, ij.InterviewID DESC
    ) li
    ORDER BY ja.DateApplied DESC";

    $stmt = sqlsrv_query($conn, $sql, array_merge($params, [$offset, $perPage]));
    if (!$stmt) return [];

    $apps = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $id = (int)$r['ApplicationID'];
        if (!isset($apps[$id])) {
            $apps[$id] = [
                'ApplicationID'     => $id,
                'FullName'          => $r['FullName']     ?? '',
                'Email'             => $r['Email']        ?? '',
                'Phone'             => $r['Phone']        ?? '',
                'Mobile_Number'     => $r['Mobile_Number'] ?? '',
                'Position'          => $r['Position']     ?? '',
                'ApplicationType'   => $r['ApplicationType'] ?? 'Online',
                'Status'            => (int)($r['Status'] ?? 0),
                'DateApplied'       => $r['DateApplied']  ?? null,
                'DepartmentID'          => isset($r['DepartmentID']) ? (int)$r['DepartmentID'] : null,
                'DepartmentName'        => $r['DepartmentName']    ?? null,
                'ColorCode'             => $r['ColorCode']         ?? null,
                'TransferredToEmployee' => !empty($r['TransferredToEmployee']),
                'RejectionRemarks'      => $r['RejectionRemarks'] ?? '',
                'HRRemarks'             => $r['HRRemarks'] ?? '',
                'InterviewDateTime'     => $r['InterviewDateTime'] ?? null,
                'InterviewAddress'  => $r['OfficeAddress']     ?? null,
                'HRContactFileNo'   => $r['HRContactFileNo']   ?? null,
                'Files'             => [],
            ];
        }
        if (!empty($r['FileID'])) {
            $apps[$id]['Files'][] = [
                'FileID'       => $r['FileID'],
                'FileName'     => $r['FileName'],
                'FilePath'     => $r['FilePath'],
                'FileCategory' => $r['FileCategory'] ?: 'Document',
            ];
        }
    }
    sqlsrv_free_stmt($stmt);
    return array_values($apps);
}

function fetchTabIds($conn, $whereSQL, $params): array
{
    $sql  = "SELECT ja.ApplicationID, ja.DateApplied
             FROM   JobApplications ja
             LEFT JOIN Departments d ON ja.DepartmentID = d.DepartmentID
             {$whereSQL}";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) return [];
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[(int)$r['ApplicationID']] = $r['DateApplied'] ?? null;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

/**
 * Flag applicants who match an existing/former employee, and whether that
 * employee record is blacklisted. Matches on LastName + FirstName (a
 * conflicting middle initial rules the match out). Runs once per page load.
 * Returns [ApplicationID => ['FileNo','Department','Position','HiredDate','Active','Blacklisted']]
 */
function fetchEmployeeMatches($conn, array $apps): array
{
    $ids = [];
    foreach ($apps as $a) {
        if (empty($a['TransferredToEmployee'])) $ids[] = (int)$a['ApplicationID'];
    }
    if (!$ids) return [];

    $norm   = fn($s) => mb_strtoupper(preg_replace('/\s+/', ' ', trim((string)$s)), 'UTF-8');
    $isTrue = fn($v) => in_array(strtoupper(trim((string)$v)), ['1', 'TRUE', 'YES', 'Y'], true);

    // 1) Structured names for this page's applicants
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = sqlsrv_query($conn,
        "SELECT ApplicationID, FirstName, MiddleName, LastName FROM JobApplications WHERE ApplicationID IN ({$in})",
        $ids);
    $names = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $names[(int)$r['ApplicationID']] = $r;
        sqlsrv_free_stmt($stmt);
    }

    $want = [];
    $lastNames = [];
    foreach ($apps as $a) {
        $id = (int)$a['ApplicationID'];
        if (!in_array($id, $ids, true)) continue;
        $n     = $names[$id] ?? [];
        $first = $norm($n['FirstName']  ?? '');
        $last  = $norm($n['LastName']   ?? '');
        $mid   = $norm($n['MiddleName'] ?? '');
        if ($first === '' || $last === '') {
            // Fallback for old rows: same split fetch_applicant uses (first word = first name)
            $parts = preg_split('/\s+/', trim((string)($a['FullName'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            $first = $norm($parts[0] ?? '');
            $last  = $norm(implode(' ', array_slice($parts, 1)));
            $mid   = '';
        }
        if ($first === '' || $last === '') continue;
        $want[$id] = [$last, $first, $mid];
        $lastNames[$last] = true;
    }
    if (!$want) return [];

    // 2) Candidate employees sharing a last name with anyone on this page
    $in2  = implode(',', array_fill(0, count($lastNames), '?'));
    $stmt = sqlsrv_query($conn,
        "SELECT FileNo, LastName, FirstName, MiddleName, Department, Branch, Position_held, Job_tittle, Employee_Status, Hired_date, Active, Blacklisted
         FROM   TBL_HREmployeeList
         WHERE  UPPER(LTRIM(RTRIM(LastName))) IN ({$in2})
         ORDER BY Hired_date DESC",
        array_map('strval', array_keys($lastNames)));
    $emps = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $emps[] = $r;
        sqlsrv_free_stmt($stmt);
    }

    // 3) Match in PHP
    $out = [];
    foreach ($want as $appId => [$last, $first, $mid]) {
        foreach ($emps as $e) {
            if ($norm($e['LastName'] ?? '') !== $last || $norm($e['FirstName'] ?? '') !== $first) continue;
            $eMid = $norm($e['MiddleName'] ?? '');
            if ($mid !== '' && $eMid !== '' && mb_substr($mid, 0, 1, 'UTF-8') !== mb_substr($eMid, 0, 1, 'UTF-8')) continue; // different middle initial = likely different person

            if (!isset($out[$appId])) {
                $hd = $e['Hired_date'] ?? null;
                $out[$appId] = [
                    'FileNo'      => trim((string)($e['FileNo'] ?? '')),
                    'Department'  => trim((string)($e['Department'] ?? '')),
                    'Position'    => trim((string)($e['Position_held'] ?? '')),
                    'HiredDate'   => $hd instanceof DateTime ? $hd->format('M j, Y') : ($hd ? date('M j, Y', strtotime($hd)) : ''),
                    'Active'      => $isTrue($e['Active'] ?? 0),
                    'Blacklisted' => false,
                ];
            }
            if ($isTrue($e['Blacklisted'] ?? 0)) $out[$appId]['Blacklisted'] = true;

            $hd2 = $e['Hired_date'] ?? null;
            $out[$appId]['Records'][] = [
                'FileNo'         => trim((string)($e['FileNo'] ?? '')),
                'Name'           => trim(trim((string)($e['LastName'] ?? '')) . ', ' . trim((string)($e['FirstName'] ?? '')) . ' ' . trim((string)($e['MiddleName'] ?? ''))),
                'Department'     => trim((string)($e['Department'] ?? '')),
                'Branch'         => trim((string)($e['Branch'] ?? '')),
                'Position'       => trim((string)($e['Position_held'] ?? '')),
                'JobTitle'       => trim((string)($e['Job_tittle'] ?? '')),
                'EmployeeStatus' => trim((string)($e['Employee_Status'] ?? '')),
                'HiredDate'      => $hd2 instanceof DateTime ? $hd2->format('M j, Y') : ($hd2 ? date('M j, Y', strtotime($hd2)) : ''),
                'Active'         => $isTrue($e['Active'] ?? 0),
                'Blacklisted'    => $isTrue($e['Blacklisted'] ?? 0),
            ];
        }
    }
    return $out;
}

function countApps($conn, $whereSQL, $params): int
{
    $sql  = "SELECT COUNT(DISTINCT ja.ApplicationID) AS total
             FROM   JobApplications ja
             LEFT JOIN Departments d ON ja.DepartmentID = d.DepartmentID
             {$whereSQL}";
    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) return 0;
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    return (int)($r['total'] ?? 0);
}

// ══ Filters & pagination ═══════════════════════════════════════
$perPage      = 20;
$page         = max(1, (int)($_GET['page']      ?? 1));
$offset       = ($page - 1) * $perPage;
$search       = trim($_GET['search']    ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to']   ?? '');
$showAll      = (($_GET['range'] ?? '') === 'all');
$dateDefaulted = false;
if (!$showAll && $dateFrom === '' && $dateTo === '') {
    $dateFrom      = date('Y-m-d', strtotime('-30 days'));
    $dateDefaulted = true;
}
define('NEW_LOOKBACK_DAYS', 3);
$statusFilter = trim($_GET['status']    ?? '');
$validTypes   = ['Online', 'Walk-in', 'Job Fair', 'Other'];
$typeFilter   = trim($_GET['type'] ?? '');
if (!in_array($typeFilter, $validTypes, true)) $typeFilter = '';
$deptFilter   = $viewAll ? trim($_GET['dept'] ?? '') : $_userDept;

function buildTabWhere(string $tab, array &$params): string
{
    global $viewAll, $_userDept, $deptFilter, $search, $dateFrom, $dateTo, $statusFilter, $typeFilter, $tabStatusGroups;

    $statuses = $tabStatusGroups[$tab] ?? [0];
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = "WHERE ja.Status IN ({$placeholders})";
    $params = $statuses;

    if ($tab !== 'pending') {
        if ($viewAll) {
            if ($deptFilter !== '') {
                $where .= " AND d.DepartmentName = ?";
                $params[] = $deptFilter;
            }
        } elseif ($_userDept !== '') {
            $where .= " AND d.DepartmentName = ?";
            $params[] = $_userDept;
        }
        // else: $_userDept === '' means "All Departments" was selected via the
        // department switcher (set_department.php) — no dept filter, show all.
    }

    $statusInt = is_numeric($statusFilter) ? (int)$statusFilter : null;
    if ($statusInt !== null && in_array($statusInt, $statuses, true)) {
        $where .= " AND ja.Status = ?";
        $params[] = $statusInt;
    }

    if ($typeFilter !== '') {
        $where .= " AND ja.ApplicationType = ?";
        $params[] = $typeFilter;
    }

    if ($search !== '') {
        $sp = "%{$search}%";
        $where .= " AND (ja.FullName LIKE ? OR ja.Email LIKE ? OR ja.Position LIKE ?)";
        array_push($params, $sp, $sp, $sp);
    }
    if ($dateFrom !== '') {
        $where .= " AND ja.DateApplied >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where .= " AND ja.DateApplied <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }

    return $where;
}

$tabCounts = []; $tabNewCounts = []; $tabIdMap = [];
$firstVisit = !isset($_SESSION['app_seen']);
if ($firstVisit) $_SESSION['app_seen'] = [];
$newCutoff = new DateTime('-' . NEW_LOOKBACK_DAYS . ' days');

foreach (array_keys($tabStatusGroups) as $tabKey) {
    $countParams = [];
    $countWhere  = buildTabWhere($tabKey, $countParams);
    $rows        = fetchTabIds($conn, $countWhere, $countParams);
    $tabIdMap[$tabKey]  = $rows;
    $tabCounts[$tabKey] = count($rows);

    if ($firstVisit) {
        $seed = [];
        foreach ($rows as $id => $d) {
            if (!($d instanceof DateTime) || $d < $newCutoff) $seed[] = $id;
        }
        $_SESSION['app_seen'][$tabKey] = $seed;
    }
    $tabNewCounts[$tabKey] = count(array_diff(array_keys($rows), $_SESSION['app_seen'][$tabKey] ?? []));
}

$activeParams = [];
$activeWhere = buildTabWhere($activeTab, $activeParams);
$tabTotal = $tabCounts[$activeTab];
$tabPages = max(1, (int)ceil($tabTotal / $perPage));
$tabApps = fetchApps($conn, $activeWhere, $activeParams, $offset, $perPage);
$empMatches = fetchEmployeeMatches($conn, $tabApps);

$activeIds  = array_keys($tabIdMap[$activeTab] ?? []);
$seenActive = $_SESSION['app_seen'][$activeTab] ?? [];
$newIdSet   = array_flip(array_diff($activeIds, $seenActive));   // for row highlighting this load
$_SESSION['app_seen'][$activeTab] = array_values(array_unique(array_merge($seenActive, $activeIds)));

$paginationParams = [
    'tab'    => $activeTab,
    'search' => $search,
];
if ($typeFilter !== '') {
    $paginationParams['type'] = $typeFilter;
}
if ($viewAll && $activeTab !== 'pending' && $deptFilter !== '') {
    $paginationParams['dept'] = $deptFilter;
}
if ($activeTab === 'interview' && $statusFilter !== '' && is_numeric($statusFilter) && in_array((int)$statusFilter, $tabStatusGroups['interview'], true)) {
    $paginationParams['status'] = $statusFilter;
}
if ($dateFrom !== '' && !$dateDefaulted) {
    $paginationParams['date_from'] = $dateFrom;
}
if ($showAll) {
    $paginationParams['range'] = 'all';
}
if ($dateTo !== '') {
    $paginationParams['date_to'] = $dateTo;
}

// ── Flash ──────────────────────────────────────────────────────
function resolveFlash(): array
{
    $updated     = $_GET['updated']      ?? null;
    $transferred = $_GET['transferred']  ?? null;
    $walkinAdded = $_GET['walkin_added'] ?? null;
    $info        = $_GET['info']         ?? '';
    $error       = $_GET['error']        ?? '';

    if ($walkinAdded !== null) {
        if ($walkinAdded === '1') return [
            'type' => 'success',
            'msg'  => '<i class="bi bi-person-check-fill me-1"></i> Applicant added successfully.',
        ];
        $wkErrors = [
            'missing_required'  => 'Please fill in Name, Position and Department.',
            'invalid_email'     => 'Please enter a valid email address.',
            'invalid_category'  => 'Each uploaded file needs a document category.',
            'invalid_file_type' => 'One of the files has an unsupported type. Allowed: PDF, DOC, DOCX, JPG, PNG.',
            'file_too_large'    => 'One of the files exceeds the 10 MB limit.',
            'too_many_files'    => 'Maximum 5 files allowed.',
            'invalid_type'      => 'Please choose an application type.',
            'db_error'          => 'A database error occurred. Please check the error log.',
        ];
        return [
            'type' => 'danger',
            'msg'  => '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' . ($wkErrors[$error] ?? 'Could not add walk-in applicant. Please try again.'),
        ];
    }

    // Transfer flash messages
    if ($transferred !== null) {
        if ($transferred === '1') return [
            'type' => 'success',
            'msg'  => '<i class="bi bi-person-check-fill me-1"></i> Applicant successfully transferred to the Employee List.',
        ];
        $errMessages = [
            'missing_required'   => 'Transfer failed: required fields (Name, Department, Position, Hired Date) are missing.',
            'already_transferred'=> 'This applicant has already been transferred to the Employee List.',
            'fileno_conflict'    => 'Transfer failed: File No conflict detected. Please try again to get a fresh File Number.',
            'db_error'           => 'Transfer failed: a database error occurred. Please check the error log.',
        ];
        return [
            'type' => 'danger',
            'msg'  => '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' . ($errMessages[$info] ?? 'Transfer failed. Please try again.'),
        ];
    }

    if ($updated === '0' && ($_GET['error'] ?? '') === 'status_locked') return [
        'type' => 'warning',
        'msg'  => '<i class="bi bi-lock-fill me-1"></i> This application is already Hired or Rejected and can no longer be changed.',
    ];

    // Existing status-update flash
    if ($updated === '1') return [
        'type' => 'success',
        'msg'  => '<i class="bi bi-check-circle-fill me-1"></i> Application updated successfully.',
    ];
    if ($updated === '0' && $error === 'remarks_required') return [
        'type' => 'warning',
        'msg'  => '<i class="bi bi-exclamation-triangle-fill me-1"></i> Please enter the rejection remarks before rejecting an applicant.',
    ];
    if ($updated === '0' && $error === 'email_incomplete') return [
        'type' => 'warning',
        'msg'  => '<i class="bi bi-exclamation-triangle-fill me-1"></i> Your custom email needs both a subject and a message. Nothing was changed.',
    ];
    if ($updated === '0' && $error === 'walkin_not_selectable') return [
        'type' => 'warning',
        'msg'  => '<i class="bi bi-exclamation-triangle-fill me-1"></i> Manual Entry is a starting status only and can\'t be selected here.',
    ];
    if ($updated === '0') return [
        'type' => $info === 'no_change' ? 'info' : 'danger',
        'msg'  => $info === 'no_change'
            ? '<i class="bi bi-info-circle-fill me-1"></i> No changes were made.'
            : '<i class="bi bi-exclamation-triangle-fill me-1"></i> Failed to update application. Please try again.',
    ];
    return [];
}
$flash = resolveFlash();

// ══ Render helpers ═════════════════════════════════════════════

/**
 * Sanitise a hex color from DB; returns a safe value or fallback.
 */
function safeHex(?string $raw, string $fallback = '#94a3b8'): string
{
    if (!$raw) return $fallback;
    $hex = ltrim(trim($raw), '#');
    return preg_match('/^[0-9a-fA-F]{3,6}$/', $hex) ? '#' . strtoupper($hex) : $fallback;
}

/**
 * Expand shorthand hex (#abc → #aabbcc).
 */
function expandHex(string $hex): string
{
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) {
        $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
    }
    return $h;
}

/**
 * Resolve the final color for a department — DB color takes priority,
 * then slate fallback.
 */
function resolveDeptColor(?string $deptName, ?string $colorCode = null): string
{
    $dbColor = safeHex($colorCode, '');
    if ($dbColor !== '') return $dbColor;

    return '#64748b'; // slate fallback if no color in DB
}

/**
 * Compute readable text color (white or dark) for a given hex background.
 */
function contrastColor(string $hex): string
{
    $h = expandHex(safeHex($hex));
    $r = hexdec(substr($h, 0, 2));
    $g = hexdec(substr($h, 2, 2));
    $b = hexdec(substr($h, 4, 2));
    $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
    return $brightness > 145 ? '#1e293b' : '#ffffff';
}

/**
 * Left border stripe style for table rows.
 */
function deptRowStyle(?string $deptName, ?string $colorCode = null): string
{
    $color = resolveDeptColor($deptName, $colorCode);
    return "style=\"border-left: 3px solid {$color};\"";
}

/**
 * Department badge — solid color bg, auto contrast text, clean pill shape.
 */

function deptBadgeStyle(?string $deptName, ?string $colorCode = null): string
{
    $bg = resolveDeptColor($deptName, $colorCode);

    $h = expandHex(safeHex($bg));
    $r = hexdec(substr($h, 0, 2));
    $g = hexdec(substr($h, 2, 2));
    $b = hexdec(substr($h, 4, 2));

    $bgRgba     = "rgba({$r},{$g},{$b},0.12)";
    $borderRgba = "rgba({$r},{$g},{$b},0.45)";

    // Darken text for bright/light colors so it stays readable
    $brightness = ($r * 299 + $g * 587 + $b * 114) / 1000;
    $textColor  = $brightness > 160
        ? "rgba(" . (int)($r * 0.55) . "," . (int)($g * 0.55) . "," . (int)($b * 0.55) . ",1)" // darkened
        : $bg; // original color is dark enough

    return "style=\""
        . "background: {$bgRgba};"
        . "color: {$textColor};"
        . "border: 1px solid {$borderRgba};"
        . "padding: .18rem .55rem .18rem .42rem;"
        . "border-radius: 999px;"
        . "font-size: .68rem;"
        . "font-weight: 700;"
        . "letter-spacing: .04em;"
        . "text-transform: uppercase;"
        . "display: inline-flex;"
        . "align-items: center;"
        . "gap: .28rem;"
        . "white-space: nowrap;"
        . "\"";
}

/**
 * Application type badge (Online / Walk-in / Job Fair / Other).
 */
function appTypeBadge(string $type): string
{
    $map = [
        'Online'   => ['t-online',  'bi-globe'],
        'Walk-in'  => ['t-walkin',  'bi-person-walking'],
        'Job Fair' => ['t-jobfair', 'bi-people-fill'],
        'Other'    => ['t-other',   'bi-three-dots'],
    ];
    [$cls, $icon] = $map[$type] ?? $map['Other'];
    return '<span class="type-badge ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . htmlspecialchars($type) . '</span>';
}

/**
 * Render uploaded files button.
 */
function renderFiles(array $files, bool $locked = false): string
{
    if (empty($files)) return '<span class="no-files">No files</span>';

    if ($locked) {
        $n = count($files);
        return '<button type="button" class="btn btn-sm btn-outline-secondary files-btn is-locked" disabled'
             . ' title="Files are locked for rejected applications">'
             . '<i class="bi bi-lock-fill me-1"></i>' . $n . ' file' . ($n !== 1 ? 's' : '')
             . '</button>';
    }

    $count   = count($files);
    $payload = htmlspecialchars(
        json_encode($files, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ENT_QUOTES, 'UTF-8'
    );
    $label = $count . ' file' . ($count !== 1 ? 's' : '');

    return '<button type="button" class="btn btn-sm btn-outline-secondary files-btn"'
         . ' data-bs-toggle="modal" data-bs-target="#filesModal"'
         . ' data-files="' . $payload . '">'
         . '<i class="bi bi-paperclip me-1"></i>' . $label
         . '</button>';
}

/**
 * Render Bootstrap-style pagination nav.
 */
function renderPagination(int $page, int $totalPages, array $extra = []): string
{
    if ($totalPages <= 1) return '';

    $url       = fn($p) => '?' . http_build_query(array_merge($extra, ['page' => $p]));
    $prevUrl   = $page > 1            ? $url($page - 1) : '#';
    $nextUrl   = $page < $totalPages  ? $url($page + 1) : '#';
    $prevClass = $page <= 1           ? 'disabled' : '';
    $nextClass = $page >= $totalPages ? 'disabled' : '';

    // Numbered buttons: first, last, and a window of 2 pages around the current one
    $window = 2;
    $pages  = [1];
    for ($i = max(2, $page - $window); $i <= min($totalPages - 1, $page + $window); $i++) $pages[] = $i;
    $pages[] = $totalPages;

    $numbers = '';
    $prevShown = 0;
    foreach ($pages as $p) {
        if ($p - $prevShown > 1) $numbers .= '<span class="page-gap">…</span>';
        $numbers .= $p === $page
            ? '<span class="page-btn page-num active" aria-current="page">' . $p . '</span>'
            : '<a class="page-btn page-num" href="' . htmlspecialchars($url($p)) . '">' . $p . '</a>';
        $prevShown = $p;
    }

    return '<nav class="pagination-wrap d-flex justify-content-between align-items-center flex-wrap gap-2" aria-label="Page navigation">'
         . '<a class="page-btn ' . $prevClass . '" href="' . $prevUrl . '"><i class="bi bi-chevron-left"></i> Prev</a>'
         . '<div class="page-numbers">' . $numbers . '</div>'
         . '<a class="page-btn ' . $nextClass . '" href="' . $nextUrl . '">Next <i class="bi bi-chevron-right"></i></a>'
         . '</nav>';
}

/**
 * Format a DateTime|string interview timestamp.
 */
function fmtInterview($dt): string
{
    if ($dt instanceof DateTime) return $dt->format('M j, Y g:i A');
    if (is_string($dt) && $dt)  return date('M j, Y g:i A', strtotime($dt));
    return '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Job Applications</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Stage row ─────────────────────────────────────────────── */
    .interview-stage-row {
      display: flex; flex-wrap: wrap; align-items: center;
      gap: 0.5rem; margin: 0; padding: 0.25rem 0;
    }
    .interview-stage-row .stage-label {
      white-space: nowrap; font-size: 0.87rem;
      font-weight: 600; color: var(--text-muted);
    }
    .interview-stage-row .stage-pill { white-space: nowrap; }

    /* ── Page layout ───────────────────────────────────────────── */
    .page-header {
      display: flex; flex-wrap: wrap; align-items: flex-start;
      justify-content: space-between; gap: 1rem;
    }
    .page-actions { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.35rem; }

    /* ── Applicant avatar (mirrors employee-list) ──────────────── */
    .app-avatar {
      width: 44px; height: 44px; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .82rem; font-weight: 800; color: #fff; flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(0,0,0,.15);
    }
    .app-name-wrap  { display: flex; align-items: center; gap: .65rem; }
    .app-name       { font-weight: 700; font-size: .88rem; color: var(--text-primary); line-height: 1.2; }
    .app-row.is-new td { background: rgba(37,99,235,.045); }
    .new-chip { display:inline-block; margin-left:.35rem; padding:.05rem .4rem; font-size:.58rem; font-weight:800; letter-spacing:.04em; color:#fff; background:#2563eb; border-radius:99px; vertical-align:middle; }
    .status-pill.is-locked { cursor:not-allowed; opacity:.8; }
    .page-numbers { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:.3rem; }
    .page-numbers .page-num { min-width:34px; justify-content:center; text-align:center; }
    .page-numbers .page-btn.active { background:var(--primary, #2563eb); color:#fff; border-color:var(--primary, #2563eb); cursor:default; pointer-events:none; }
    .page-numbers .page-gap { color:var(--text-muted); padding:0 .25rem; user-select:none; }
    .emp-flags { display:flex; flex-wrap:wrap; gap:.3rem; margin:.2rem 0 .15rem; }
    .emp-flag { display:inline-flex; align-items:center; gap:.28rem; padding:.15rem .5rem; border-radius:999px; font-size:.64rem; font-weight:800; letter-spacing:.03em; text-transform:uppercase; white-space:nowrap; cursor:help; }
    .emp-flag.ef-former      { background:rgba(217,119,6,.10); color:#b45309; border:1px solid rgba(217,119,6,.4); }
    .emp-flag.ef-current     { background:rgba(37,99,235,.10); color:#1d4ed8; border:1px solid rgba(37,99,235,.35); }
    .emp-flag.ef-blacklisted { background:#dc2626; color:#fff; border:1px solid #b91c1c; }
    .emp-flag[data-bs-toggle] { cursor:pointer; }
    .emp-flag[data-bs-toggle]:hover { filter:brightness(.95); box-shadow:0 1px 4px rgba(0,0,0,.15); }
    .files-btn.is-locked   { cursor:not-allowed; opacity:.55; pointer-events:none; }
    .status-pill.s-8 { background: rgba(100,116,139,.12); color:#475569; border:1px solid rgba(100,116,139,.35); }
    .status-pill.s-8 .dot { background:#475569; }
    .type-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .55rem; border-radius:999px; font-size:.68rem; font-weight:700; letter-spacing:.03em; white-space:nowrap; }
    .type-badge.t-online  { background:rgba(37,99,235,.10);  color:#1d4ed8; border:1px solid rgba(37,99,235,.35); }
    .type-badge.t-walkin  { background:rgba(217,119,6,.10);  color:#b45309; border:1px solid rgba(217,119,6,.35); }
    .type-badge.t-jobfair { background:rgba(124,58,237,.10); color:#6d28d9; border:1px solid rgba(124,58,237,.35); }
    .type-badge.t-other   { background:rgba(100,116,139,.10);color:#475569; border:1px solid rgba(100,116,139,.35); }
    .app-sub        { font-size: .72rem; color: var(--text-muted); margin-top: .1rem; }
    .app-row        { cursor: pointer; transition: background .12s; }
    .rej-remark { margin-top:.3rem; font-size:.7rem; line-height:1.3; color:#b91c1c; max-width:210px; margin-inline:auto; }
    .hr-remark { margin-top:.3rem; font-size:.7rem; line-height:1.3; color:#1d4ed8; max-width:210px; margin-inline:auto; }
    .ad-hr { border:1px solid #bfdbfe; background:#eff6ff; border-radius:12px; padding:.8rem 1rem; margin-bottom:.8rem; }
    .ad-hr .ad-reject-title { color:#1d4ed8; }
    .ad-remark-edit { margin-left:auto; border:0; background:transparent; color:inherit; font-size:.7rem; font-weight:700; cursor:pointer; text-transform:none; letter-spacing:0; }
    .ad-remark-actions { display:flex; gap:.4rem; margin-top:.5rem; }
    .ad-remark-meta { font-size:.68rem; color:#64748b; margin-top:.4rem; }

    /* ── Email composer ────────────────────────────────────────── */
    .email-mode-toggle { display:flex; flex-wrap:wrap; gap:.4rem; }

    /* ── Page loading overlay (blocks double clicks while saving) ── */
    .page-loading { position:fixed; inset:0; z-index:2000; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,.45); backdrop-filter:blur(2px); cursor:progress; }
    .page-loading.show { display:flex; }
    .page-loading-box { background:#fff; border-radius:14px; padding:1.4rem 2rem; display:flex; flex-direction:column; align-items:center; gap:.75rem; box-shadow:0 20px 60px rgba(0,0,0,.25); font-weight:600; color:#0f172a; font-size:.9rem; }

    /* ── Application details modal ─────────────────────────────── */
    .ad-head { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem .85rem; margin-bottom:1rem; }
    .ad-name { font-size:1.15rem; font-weight:800; color:#0f172a; }
    .ad-meta { display:flex; flex-wrap:wrap; align-items:center; gap:.4rem; }
    .ad-section { border:1px solid #e2e8f0; border-radius:12px; padding:.85rem 1rem; margin-bottom:.8rem; background:#fff; }
    .ad-title { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#475569; margin-bottom:.65rem; padding-left:.6rem; border-left:3px solid #3b82f6; display:flex; align-items:center; gap:.4rem; }
    .ad-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:.7rem 1rem; }
    .ad-full { grid-column:1 / -1; }
    .ad-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
    .ad-value { font-size:.85rem; font-weight:600; color:#1e293b; word-break:break-word; }
    .ad-empty { color:#cbd5e1; font-weight:400; font-size:.82rem; }
    .ad-pre   { white-space:pre-wrap; word-break:break-word; font-size:.84rem; color:#1e293b; }
    .ad-chip  { border:1px solid #e2e8f0; border-radius:8px; padding:.45rem .65rem; margin-bottom:.4rem; font-size:.8rem; background:#f8fafc; }
    .ad-k     { font-weight:700; text-transform:capitalize; color:#475569; }
    .ad-reject { border:1px solid #fecaca; background:#fef2f2; border-radius:12px; padding:.8rem 1rem; margin-bottom:.8rem; }
    .ad-reject-title { font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#b91c1c; margin-bottom:.35rem; display:flex; align-items:center; gap:.4rem; }
    .ad-file { display:flex; align-items:center; gap:.6rem; padding:.5rem .75rem; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:.4rem; text-decoration:none; color:#1e293b; font-size:.82rem; }
    .ad-file:hover { background:#f1f5f9; }
    .app-row:hover td { background: rgba(67,128,226,.04); }

    /* ── Transfer button ───────────────────────────────────────── */
    .btn-transfer {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .25rem .6rem; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase;
      background: rgba(16,185,129,.1); color: #059669;
      border: 1px solid rgba(16,185,129,.35);
      transition: background .15s, border-color .15s;
      white-space: nowrap;
    }
    .btn-transfer:hover { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.6); }
    .btn-transfer:disabled,
    .btn-transfer.transferred {
      background: rgba(100,116,139,.08); color: #94a3b8;
      border-color: rgba(100,116,139,.25); cursor: default; pointer-events: none;
    }

    /* ── Transferred badge ─────────────────────────────────────── */
    .badge-transferred {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .25rem .65rem; border-radius: 999px;
      font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
      background: rgba(16,185,129,.08); color: #059669;
      border: 1px solid rgba(16,185,129,.3);
      white-space: nowrap;
    }
    .badge-transferred .tf-checkmark {
      width: 14px; height: 14px; border-radius: 50%;
      background: #059669; color: #fff;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: .6rem; flex-shrink: 0;
    }

    /* ── Transfer modal ────────────────────────────────────────── */
    .transfer-modal .modal-content { border-radius: 16px; border: none; box-shadow: 0 24px 80px rgba(0,0,0,.2); }
    .transfer-modal .modal-header  { background: var(--bs-body-bg,#fff); border-bottom: 1px solid #e2e8f0; border-radius: 16px 16px 0 0; padding: 1rem 1.5rem; }
    .transfer-modal .modal-title   { font-weight: 700; color: #0f172a; font-size: 1rem; }
    .transfer-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem .75rem; }
    .transfer-form-grid .tf-full   { grid-column: 1 / -1; }
    .tf-section-title {
      font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
      color: #475569; margin-bottom: .6rem; padding-left: .6rem;
      border-left: 3px solid #3b82f6; display: flex; align-items: center; gap: .4rem;
    }
    .tf-label {
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .04em; color: #94a3b8; display: block; margin-bottom: .25rem;
    }
    @media(max-width: 576px) {
      .transfer-form-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
      .interview-stage-row { justify-content: flex-start; }
      .interview-stage-row .stage-pill { font-size: .78rem; padding: .35rem .7rem; }
    }
  </style>
</head>
<body>

<?php $topbar_page = 'applications';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<!-- ══ MAIN ════════════════════════════════════════════════════ -->
<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title">Job Applications</div>
<div class="page-subtitle">Review, filter, and manage all incoming applicants</div>
      <?php if (rbac_can_access('careers_admin')): ?>
      <a href="<?= route('careers_admin') ?>" class="btn btn-outline-secondary btn-sm mt-2">
        <i class="bi bi-briefcase-fill me-1"></i> Careers Admin
      </a>
      <?php endif; ?>
    </div>
    <?php if (($isAdmin && $activeTab !== 'pending') || $activeTab === 'walkin'): ?>
    <div class="page-actions">
      <?php if ($activeTab === 'walkin'): ?>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addWalkinModal">
          <i class="bi bi-person-plus-fill me-1"></i> Add Applicant
        </button>
      <?php endif; ?>
      <?php if ($isAdmin && $activeTab !== 'pending'): ?>
        <?php if (!$viewAll): ?>
          <a href="?<?= htmlspecialchars(http_build_query(array_merge(array_diff_key($paginationParams, ['dept' => 1]), ['view_all' => 1]))) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-globe2 me-1"></i> View All Departments
          </a>
        <?php else: ?>
          <a href="?<?= htmlspecialchars(http_build_query(array_merge(array_diff_key($paginationParams, ['dept' => 1]), ['view_all' => 0]))) ?>" class="btn btn-warning btn-sm">
            <i class="bi bi-building me-1"></i> Back to My Department
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Flash -->
  <?php if (!empty($flash)): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show mb-3" role="alert">
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- View-All banner (Admin only, when active) -->
  <?php if ($isAdmin && $viewAll): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.85rem;">
    <i class="bi bi-globe2"></i>
    <span>You are viewing <strong>all departments</strong>.</span>
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="nav nav-pills mb-3" role="tablist">
    <?php foreach ($tabDisplayNames as $tabKey => $label): ?>
      <a href="?tab=<?= htmlspecialchars($tabKey) ?>" class="app-tab <?= $activeTab === $tabKey ? 'active' : '' ?>">
        <i class="bi <?= htmlspecialchars($tabIcons[$tabKey] ?? 'bi-kanban-fill') ?>"></i> <?= htmlspecialchars($label) ?>
        <?php $nc = $tabNewCounts[$tabKey] ?? 0; if ($nc > 0): ?>
          <span class="<?= $activeTab === $tabKey ? 'tab-badge-white' : 'tab-badge-red' ?>" title="<?= $nc ?> new"><?= $nc ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- ══ CURRENT TAB CONTENT ══════════════════════════════════════════ -->

  <div class="filter-card">
    <form method="get">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <?php if ($showAll): ?><input type="hidden" name="range" value="all"><?php endif; ?>
      <div class="filter-row">

        <div class="record-info">
          <strong><?= $tabTotal ?></strong> <?= strtolower(htmlspecialchars($tabDisplayNames[$activeTab])) ?> application<?= $tabTotal !== 1 ? 's' : '' ?>
          <?php if ($activeTab !== 'pending' && !$viewAll && $_userDept): ?>
            — <span style="color:var(--primary);font-weight:700;"><?= htmlspecialchars($_userDept) ?></span>
          <?php elseif ($activeTab !== 'pending' && $viewAll): ?>
            — <span style="color:#ca8a04;font-weight:700;">All Departments</span>
          <?php else: ?>
            <span style="font-size:.72rem;color:var(--text-muted);margin-left:.4rem;">
              <i class="bi bi-people-fill"></i> All Departments
            </span>
          <?php endif; ?>
        </div>

        <?php if (true): // date range now applies to every tab (30-day default) ?>
          <span class="filter-label">From</span>
          <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                 class="form-control" style="max-width:145px;">
          <span class="filter-label">To</span>
          <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                 class="form-control" style="max-width:145px;">
          <div class="filter-divider"></div>
        <?php endif; ?>

        <?php if ($activeTab === 'interview'): ?>
          <select name="status" class="form-select" style="max-width:210px;">
            <option value="">All Interview Stages</option>
            <?php foreach ([2, 3, 4, 5] as $code): ?>
              <option value="<?= $code ?>" <?= $statusFilter === (string)$code ? 'selected' : '' ?>>
                <?= htmlspecialchars($statusLabels[$code]) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="filter-divider"></div>
        <?php endif; ?>

        <?php if ($isAdmin && $viewAll && $activeTab !== 'pending'): ?>
          <select name="dept" class="form-select" style="max-width:175px;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= htmlspecialchars($d['DepartmentName']) ?>"
                <?= $deptFilter === $d['DepartmentName'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['DepartmentName']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="filter-divider"></div>
        <?php endif; ?>

        <select name="type" class="form-select" style="max-width:140px;">
          <option value="">All Types</option>
          <?php foreach ($validTypes as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="filter-divider"></div>

        <div style="position:relative;">
          <i class="bi bi-search" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none;"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 class="form-control" placeholder="Search applicants…" style="padding-left:2rem;max-width:220px;">
        </div>
        <div class="filter-divider"></div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel-fill"></i> Apply</button>
        <a href="?tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>
        <?php if ($dateDefaulted): ?>
          <a href="?<?= htmlspecialchars(http_build_query(array_merge($paginationParams, ['range' => 'all']))) ?>" class="btn btn-outline-secondary" title="Currently showing the last 30 days"><i class="bi bi-calendar-range"></i> Show all time</a>
        <?php endif; ?>

      </div>
    </form>
  </div>

  <?php if ($activeTab === 'interview'): ?>
  <?php
      $stageBaseParams = [
          'tab'       => 'interview',
          'search'    => $search,
          'date_from' => $dateDefaulted ? '' : $dateFrom,
          'date_to'   => $dateTo,
      ];
      if ($showAll) {
          $stageBaseParams['range'] = 'all';
      }
      if ($viewAll && $deptFilter !== '') {
          $stageBaseParams['dept'] = $deptFilter;
      }
      if ($typeFilter !== '') {
          $stageBaseParams['type'] = $typeFilter;
      }
  ?>
  <div class="filter-card" style="padding:0.75rem 1rem 0.75rem 1rem; margin-bottom:1rem;">
    <div class="interview-stage-row">
      <span class="stage-label">Interview stages:</span>
      <a href="?<?= htmlspecialchars(http_build_query($stageBaseParams)) ?>"
         class="btn btn-sm stage-pill <?= $statusFilter === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
         All Interview Stages
      </a>
      <?php foreach ([2, 3, 4, 5] as $code): ?>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge($stageBaseParams, ['status' => $code]))) ?>"
           class="btn btn-sm stage-pill <?= $statusFilter === (string)$code ? 'btn-primary' : 'btn-outline-secondary' ?>">
           <?= htmlspecialchars($statusLabels[$code]) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="table-card">
    <div class="table-responsive">
      <table class="apps-table" id="applicantsTable">
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Contact</th>
            <th>Position &amp; Department</th>
            <th>Type</th>
            <th>Date Applied</th>
            <th>Files</th>
            <th>Interview</th>
            <th style="text-align:center;">Status</th>
            <?php if ($activeTab === 'hired'): ?><th style="text-align:center;">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php
        // Avatar helpers (mirrors employee-list)
        $avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316'];
        function appAvatarColor(string $name, array $colors): string {
            return $colors[abs(crc32($name)) % count($colors)];
        }
        function appInitials(string $fullName): string {
            $parts = array_filter(explode(' ', trim($fullName)));
            if (count($parts) >= 2) return strtoupper(substr($parts[0],0,1).substr(end($parts),0,1));
            return strtoupper(substr($fullName,0,2));
        }
        if (!empty($tabApps)): foreach ($tabApps as $app):
            $sv        = $app['Status'];
            $labelText = $statusLabels[$sv] ?? 'Unknown';
            $pillClass = $statusBadgeClass[$sv] ?? 's-0';
            $color     = $app['ColorCode'] ?? null;
            $fullName  = $app['FullName'] ?? '';
            $bgColor   = appAvatarColor($fullName, $avatarColors);
            $initials  = appInitials($fullName);
        ?>
                    <tr class="app-row<?= isset($newIdSet[$app['ApplicationID']]) ? ' is-new' : '' ?>" data-app-id="<?= (int)$app['ApplicationID'] ?>" <?= deptRowStyle($app['DepartmentName'] ?? null, $color) ?>>
            <!-- Applicant column: avatar + name + dept badge -->
            <td>
              <div class="app-name-wrap">
                <div class="app-avatar" style="background:<?= $bgColor ?>;"><?= htmlspecialchars($initials) ?></div>
                <div>
                  <div class="app-name"><?= htmlspecialchars($fullName) ?><?php if (isset($newIdSet[$app['ApplicationID']])): ?> <span class="new-chip">NEW</span><?php endif; ?></div>
                  <?php if (isset($empMatches[$app['ApplicationID']])):
                      $m   = $empMatches[$app['ApplicationID']];
                      $matchPayload = htmlspecialchars(json_encode([
                          'applicant'   => $fullName,
                          'blacklisted' => $m['Blacklisted'],
                          'records'     => $m['Records'] ?? [],
                      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8');
                      $tip = 'Matched by name, please verify. File No ' . ($m['FileNo'] ?: '—')
                           . ' · ' . ($m['Department'] ?: '—')
                           . ' · ' . ($m['Position'] ?: '—')
                           . ($m['HiredDate'] ? ' · Hired ' . $m['HiredDate'] : '');
                  ?>
                  <div class="emp-flags">
                    <span class="emp-flag <?= $m['Active'] ? 'ef-current' : 'ef-former' ?>" title="Click to view details" data-bs-toggle="modal" data-bs-target="#empMatchModal" data-match="<?= $matchPayload ?>">
                      <i class="bi bi-person-badge"></i> <?= $m['Active'] ? 'Current Employee' : 'Former Employee' ?>
                    </span>
                    <?php if ($m['Blacklisted']): ?>
                    <span class="emp-flag ef-blacklisted" title="Click to view details" data-bs-toggle="modal" data-bs-target="#empMatchModal" data-match="<?= $matchPayload ?>">
                      <i class="bi bi-slash-circle-fill"></i> Blacklisted
                    </span>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                  <?php if ($app['DepartmentName']): ?>
                    <span <?= deptBadgeStyle($app['DepartmentName'], $color) ?>>
                      <i class="bi bi-building" style="font-size:.6rem;"></i>
                      <?= htmlspecialchars($app['DepartmentName']) ?>
                    </span>
                  <?php else: ?>
                    <div class="app-sub"><i class="bi bi-building" style="font-size:.65rem;"></i> No Department</div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Contact column -->
            <td>
              <div>
                <a href="mailto:<?= htmlspecialchars($app['Email']) ?>" class="text-link" style="font-size:.78rem;">
                  <i class="bi bi-envelope" style="font-size:.7rem;"></i> <?= htmlspecialchars($app['Email']) ?>
                </a>
              </div>
              <div class="app-sub">
                <i class="bi bi-telephone" style="font-size:.65rem;"></i>
                <?= htmlspecialchars($app['Phone'] ?: '—') ?>
              </div>
              <div class="app-sub">
                <i class="bi bi-phone" style="font-size:.65rem;"></i>
                <?= htmlspecialchars($app['Mobile_Number'] ?: '—') ?>
              </div>
            </td>

            <!-- Position & Department column -->
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($app['Position']) ?></div>
              <div class="app-sub"><?= htmlspecialchars($app['DepartmentName'] ?: '—') ?></div>
            </td>

            <!-- Application Type -->
            <td><?= appTypeBadge($app['ApplicationType'] ?? 'Online') ?></td>

            <!-- Date Applied -->
            <td class="date-cell">
              <?php if ($app['DateApplied'] instanceof DateTime): ?>
                <div class="date-day"><?= $app['DateApplied']->format('M j, Y') ?></div>
                <div class="date-time"><?= $app['DateApplied']->format('g:i A') ?></div>
              <?php endif; ?>
            </td>

            <!-- Files -->
            <td><?= renderFiles($app['Files'], $sv === 7) ?></td>

            <!-- Interview -->
            <td>
              <?php $idtStr = fmtInterview($app['InterviewDateTime']); ?>
              <?php if ($idtStr): ?>
                <div style="font-size:.78rem;font-weight:600;color:var(--text-primary);"><?= $idtStr ?></div>
                <?php if ($app['InterviewAddress']): ?>
                  <div class="app-sub"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($app['InterviewAddress']) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>

            <!-- Status pill -->
            <td style="text-align:center;">
              <?php if (in_array($sv, [6, 7], true)): ?>
                <span class="status-pill is-locked <?= htmlspecialchars($pillClass) ?>" title="Final status. Cannot be changed">
                  <span class="dot"></span><?= htmlspecialchars($labelText) ?>
                  <i class="bi bi-lock-fill" style="font-size:.6rem;opacity:.5;"></i>
                </span>
                <?php if ($sv === 7 && trim((string)($app['RejectionRemarks'] ?? '')) !== ''): ?>
                  <div class="rej-remark" title="<?= htmlspecialchars($app['RejectionRemarks']) ?>">
                    <i class="bi bi-chat-left-text"></i> <?= htmlspecialchars(mb_strimwidth(trim($app['RejectionRemarks']), 0, 60, '…', 'UTF-8')) ?>
                  </div>
                <?php endif; ?>
              <?php else: ?>
              <button type="button" class="status-pill <?= htmlspecialchars($pillClass) ?>"
                data-bs-toggle="modal" data-bs-target="#updateStatusModal"
                data-id="<?= $app['ApplicationID'] ?>"
                data-status="<?= $sv ?>"
                data-department="<?= (int)($app['DepartmentID'] ?? 0) ?>"
                data-email="<?= htmlspecialchars($app['Email'] ?? '') ?>">
                <span class="dot"></span><?= htmlspecialchars($labelText) ?>
                <i class="bi bi-pencil-fill" style="font-size:.6rem;opacity:.5;"></i>
              </button>
              <?php endif; ?>
              <?php if (trim((string)($app['HRRemarks'] ?? '')) !== ''): ?>
                <div class="hr-remark" title="<?= htmlspecialchars($app['HRRemarks']) ?>">
                  <i class="bi bi-bookmark-star-fill"></i> <?= htmlspecialchars(mb_strimwidth(trim($app['HRRemarks']), 0, 60, '…', 'UTF-8')) ?>
                </div>
              <?php endif; ?>
            </td>

            <!-- Transfer button — Hired tab only -->
            <?php if ($activeTab === 'hired'): ?>
            <td style="text-align:center;">
              <?php if (!empty($app['TransferredToEmployee'])): ?>
                <span class="badge-transferred" title="Already added to Employee List">
                  <span class="tf-checkmark"><i class="bi bi-check-lg"></i></span>
                  Transferred
                </span>
              <?php else: ?>
                <a href="employee-list.php?from_app=<?= (int)$app['ApplicationID'] ?>"
                   class="btn-transfer">
                  <i class="bi bi-person-plus-fill"></i> Add to Employee List
                </a>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="<?= $activeTab === 'hired' ? 9 : 8 ?>">
            <div class="empty-state"><i class="bi bi-folder2-open"></i><p>No applications found for the selected filters.</p></div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= renderPagination($page, $tabPages, $paginationParams) ?>
  </div>

</div><!-- /.main-wrapper -->


<!-- ══ ADD WALK-IN MODAL ═══════════════════════════════════── -->
<div class="modal fade" id="addWalkinModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <form id="addWalkinForm" method="post" action="add-walkin.php" enctype="multipart/form-data">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2" style="color:#2563eb;"></i>Add Applicant (Manual Entry)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem 1.5rem;">
          <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" style="font-size:.79rem;border-radius:10px;">
            <i class="bi bi-info-circle-fill" style="margin-top:.1rem;flex-shrink:0;"></i>
            <span>This creates a new application with status <strong>Manual Entry</strong>. It can be moved to any other stage afterward, same as an online application.</span>
          </div>

          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-tags"></i> Application Type <span style="color:#dc2626;">*</span></div>
            <select class="form-select form-select-sm" name="application_type" required>
              <option value="Walk-in" selected>Walk-in</option>
              <option value="Job Fair">Job Fair</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-person-vcard"></i> Full Name</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Last Name <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="last_name" required>
              </div>
              <div>
                <label class="tf-label">First Name <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="first_name" required>
              </div>
              <div>
                <label class="tf-label">Middle Name</label>
                <input type="text" class="form-control form-control-sm" name="middle_name">
              </div>
            </div>
          </div>

          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-briefcase"></i> Position &amp; Department</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Position <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="position" required>
              </div>
              <div>
                <label class="tf-label">Department <span style="color:#dc2626;">*</span></label>
                <select class="form-select form-select-sm" name="department_id" required>
                  <option value="">— Select Department —</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['DepartmentID'] ?>"><?= htmlspecialchars($d['DepartmentName']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Email <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                <input type="email" class="form-control form-control-sm" name="email">
              </div>
              <div>
                <label class="tf-label">Mobile Number</label>
                <input type="text" class="form-control form-control-sm" name="mobile">
              </div>
              <div class="tf-full">
                <label class="tf-label">Present Address</label>
                <input type="text" class="form-control form-control-sm" name="present_address">
              </div>
            </div>
          </div>

          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-paperclip"></i> Documents <span style="font-weight:400;color:#94a3b8;text-transform:none;">(optional)</span></div>
            <div id="wkFileRows"></div>
            <button type="button" id="wkAddFileRow" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> Add file</button>
            <template id="wkFileRowTemplate">
              <div class="d-flex gap-2 align-items-center mb-2 wk-file-row">
                <input type="file" name="files[]" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                <select name="category_id[]" class="form-select form-select-sm" style="max-width:170px;">
                  <option value="">— Category —</option>
                  <?php foreach ($fileCategories as $fc): ?>
                    <option value="<?= (int)$fc['FileCategoryID'] ?>"><?= htmlspecialchars($fc['CategoryName']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-danger wk-remove-row"><i class="bi bi-x-lg"></i></button>
              </div>
            </template>
          </div>

          <div class="mb-0">
            <div class="tf-section-title"><i class="bi bi-journal-text"></i> Notes</div>
            <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Anything HR should know about this walk-in"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Add Applicant</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ══ FILES PREVIEW MODAL ═══════════════════════════════════─ -->
<div class="modal fade" id="filesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Uploaded Files</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="filesModalBody" class="list-group"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>


<!-- ══ UPDATE STATUS MODAL ════════════════════════════════════ -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form id="updateStatusForm" method="post" action="update-status.php">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-pencil-square me-2" style="color:var(--primary-light);"></i>Update Application
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="applicationID" id="modalAppID">
          <input type="hidden" name="returnTab"     value="<?= htmlspecialchars($activeTab) ?>">

          <div class="mb-3">
            <label class="form-label">Status</label>
            <select name="status" id="modalStatus" class="form-select" required>
              <?php foreach ($statusLabels as $code => $label): if ($code === 8) continue; ?>
                <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">
              Department
              <span id="deptRequired" style="color:#dc2626;display:none;">*</span>
              <span id="deptOptional" style="font-weight:400;"> (optional)</span>
            </label>
            <select name="department" id="modalDept" class="form-select">
              <option value="">— keep existing —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= (int)$d['DepartmentID'] ?>"><?= htmlspecialchars($d['DepartmentName']) ?></option>
              <?php endforeach; ?>
            </select>
            <div id="deptHint" style="display:none;font-size:.75rem;color:#dc2626;margin-top:.3rem;">
              <i class="bi bi-info-circle"></i> Please assign a department when moving from Pending.
            </div>
          </div>

          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="sendEmail" id="modalSendEmail" value="1" checked>
            <label class="form-check-label" for="modalSendEmail" style="font-size:.85rem;">
              Send email notification to the applicant
            </label>
          </div>

          <!-- Email options — shown only while "Send email" is ticked -->
          <div id="emailOptions" class="mb-3">
            <div class="email-mode-toggle">
              <input type="radio" class="btn-check" name="emailMode" id="emailModeTemplate" value="template" checked>
              <label class="btn btn-outline-primary btn-sm" for="emailModeTemplate"><i class="bi bi-file-earmark-text"></i> Use template</label>
              <input type="radio" class="btn-check" name="emailMode" id="emailModeCustom" value="custom">
              <label class="btn btn-outline-primary btn-sm" for="emailModeCustom"><i class="bi bi-pencil-square"></i> Compose my own</label>
            </div>
            <div id="customEmailBox" style="display:none;" class="interview-fields-box mt-2">
              <div class="ibox-title"><i class="bi bi-envelope-paper"></i> Your Email</div>
              <div class="mb-2">
                <label class="form-label" for="emailSubject">Subject</label>
                <input type="text" class="form-control" name="emailSubject" id="emailSubject" maxlength="200" placeholder="e.g. Update on your application">
              </div>
              <div>
                <label class="form-label" for="emailBody">Message</label>
                <textarea class="form-control" name="emailBody" id="emailBody" rows="7" maxlength="5000" placeholder="Write your message to the applicant…"></textarea>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem;">
                  Sent as written. For interview statuses, the date, venue and HR contact you select below are added under your message automatically, so there's no need to type them.
                </div>
              </div>
            </div>
          </div>

          <!-- Rejection remarks — status 7 only (internal, never emailed) -->
          <div id="rejectFields" style="display:none;" class="mb-3">
            <label class="form-label" for="rejectionRemarks">Rejection Remarks <span style="color:#dc2626;">*</span></label>
            <textarea class="form-control" name="rejectionRemarks" id="rejectionRemarks" rows="3" maxlength="1000"
                      placeholder="Why is this applicant being rejected? (internal reminder for HR, not sent to the applicant)"></textarea>
          </div>

          <!-- Interview details — visible for statuses 2, 3, 4, 5 -->
          <div id="interviewFields" style="display:none;" class="interview-fields-box">
            <div class="ibox-title"><i class="bi bi-calendar-event"></i> Interview Details</div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Interview Date &amp; Time</label>
                <input type="datetime-local" class="form-control" name="InterviewDateTime" id="InterviewDateTime">
              </div>
              <div class="col-md-6">
                <label class="form-label">Office Address</label>
                <input type="text" class="form-control" name="OfficeAddress" id="OfficeAddress"
                       placeholder="e.g. Head Office, Lucena City">
              </div>
              <div class="col-12">
                <label class="form-label">HR Contact</label>
                <select class="form-select" name="HRContactFileNo" id="HRContactFileNo">
                  <option value="">— Select HR Contact —</option>
                  <?php foreach ($hrContacts as $hr):
                    $fn   = $hr['FileNo'] ?? '';
                    $disp = trim(($hr['FirstName'] ?? '') . ' ' . ($hr['LastName'] ?? '')) ?: $fn;
                    $dept = $hr['Department'] ?? '';
                  ?>
                    <option value="<?= htmlspecialchars($fn) ?>" data-dept="<?= htmlspecialchars($dept) ?>">
                      <?= htmlspecialchars($disp) ?><?= $dept ? ' (' . $dept . ')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="updateStatus" class="btn btn-primary">
            <i class="bi bi-check2"></i> Save Changes
          </button>
        </div>

      </div>
    </form>
  </div>
</div>

<!-- ══ EMPLOYEE MATCH MODAL ══ -->
<div class="modal fade" id="empMatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-badge me-2" style="color:#b45309;"></i>Employee Record Match</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 mb-3" style="font-size:.79rem;border-radius:10px;">
          <i class="bi bi-info-circle-fill me-1"></i>
          <strong id="empMatchApplicant"></strong> matched the record(s) below by <strong>name only</strong>. Please confirm it is the same person before acting on this.
        </div>
        <div id="empMatchBody"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ APPLICATION DETAILS MODAL ══ -->
<div id="pageLoading" class="page-loading" role="status" aria-live="polite">
  <div class="page-loading-box">
    <div class="spinner-border text-primary" aria-hidden="true"></div>
    <div id="pageLoadingText">Please wait…</div>
  </div>
</div>

<div class="modal fade" id="appDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-lines-fill me-2"></i>Application Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="appDetailBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>

<!-- ══ TRANSFER TO EMPLOYEE LIST MODAL ════════════════════════ -->
<div class="modal fade transfer-modal" id="transferModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <form id="transferForm" method="post" action="transfer-applicant.php">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-person-plus-fill me-2" style="color:#059669;"></i>Transfer to Employee List
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <!-- Loading overlay -->
        <div id="transferLoadingOverlay" style="
          display:none; position:absolute; inset:0; z-index:10;
          background:rgba(255,255,255,.88); border-radius:16px;
          align-items:center; justify-content:center; flex-direction:column; gap:.75rem;">
          <div class="spinner-border text-success" style="width:2.5rem;height:2.5rem;"></div>
          <div style="font-size:.82rem;color:#475569;font-weight:600;">Loading applicant details…</div>
        </div>

        <div class="modal-body" id="transferModalBody" style="padding:1.25rem 1.5rem;">
          <input type="hidden" name="applicationID" id="transferAppID">

          <!-- ── Auto-generated IDs banner ── -->
          <div style="
            background: linear-gradient(135deg,#f0fdf4,#dcfce7);
            border: 1px solid #bbf7d0; border-radius: 12px;
            padding: .85rem 1.1rem; margin-bottom: 1.1rem;
            display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
            <div style="flex:1;min-width:160px;">
              <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#059669;margin-bottom:.2rem;">
                <i class="bi bi-hash"></i> File No <span style="color:#94a3b8;font-weight:400;">(auto-generated)</span>
              </div>
              <div id="tf-display-FileNo" style="font-size:1.1rem;font-weight:800;color:#065f46;letter-spacing:.03em;">—</div>
              <input type="hidden" name="FileNo" id="tf-FileNo">
            </div>
            <div style="width:1px;background:#bbf7d0;align-self:stretch;"></div>
            <div style="flex:2;min-width:200px;">
              <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#059669;margin-bottom:.2rem;">
                <i class="bi bi-person-badge"></i> System / Employee ID <span style="color:#94a3b8;font-weight:400;">(auto-generated)</span>
              </div>
              <div id="tf-display-EmployeeID" style="font-size:1.1rem;font-weight:800;color:#065f46;letter-spacing:.03em;">—</div>
              <input type="hidden" name="EmployeeID" id="tf-EmployeeID">
            </div>
          </div>

          <!-- Info note -->
          <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" style="font-size:.79rem;border-radius:10px;">
            <i class="bi bi-info-circle-fill" style="margin-top:.1rem;flex-shrink:0;"></i>
            <span>All fields below are pre-filled from the applicant's submitted information. Review and complete any missing details before confirming the transfer.</span>
          </div>

          <!-- ── IDENTIFICATION ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-fingerprint"></i> Identification</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Assigned Employee ID</label>
                <input type="text" class="form-control form-control-sm" name="EmployeeID1" id="tf-EmployeeID1" placeholder="e.g. EMP-0001">
              </div>
              <div>
                <label class="tf-label">Office</label>
                <select class="form-select form-select-sm" name="OfficeID" id="tf-OfficeID">
                  <option value="">— Select Office —</option>
                  <?php
                  $tfOfficeStmt = sqlsrv_query($conn, "SELECT [ID], [OfficeName] FROM [dbo].[Tbl_Office_Information] ORDER BY [OfficeName]");
                  if ($tfOfficeStmt) {
                      while ($or = sqlsrv_fetch_array($tfOfficeStmt, SQLSRV_FETCH_ASSOC))
                          echo '<option value="'.(int)$or['ID'].'">'.htmlspecialchars($or['OfficeName']).'</option>';
                      sqlsrv_free_stmt($tfOfficeStmt);
                  }
                  ?>
                </select>
              </div>
              <div>
                <label class="tf-label">SSS Number</label>
                <input type="text" class="form-control form-control-sm" name="SSS_Number" id="tf-SSS_Number">
              </div>
              <div>
                <label class="tf-label">TIN Number</label>
                <input type="text" class="form-control form-control-sm" name="TIN_Number" id="tf-TIN_Number">
              </div>
              <div>
                <label class="tf-label">PhilHealth</label>
                <input type="text" class="form-control form-control-sm" name="Philhealth_Number" id="tf-Philhealth_Number">
              </div>
              <div>
                <label class="tf-label">HDMF / Pag-IBIG</label>
                <input type="text" class="form-control form-control-sm" name="HDMF" id="tf-HDMF">
              </div>
            </div>
          </div>

          <!-- ── FULL NAME ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-person-vcard"></i> Full Name</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Last Name <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="LastName" id="tf-LastName" required>
              </div>
              <div>
                <label class="tf-label">First Name <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="FirstName" id="tf-FirstName" required>
              </div>
              <div>
                <label class="tf-label">Middle Name</label>
                <input type="text" class="form-control form-control-sm" name="MiddleName" id="tf-MiddleName">
              </div>
            </div>
          </div>

          <!-- ── WORK INFORMATION ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-briefcase"></i> Work Information</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Department <span style="color:#dc2626;">*</span></label>
                <select class="form-select form-select-sm" name="Department" id="tf-Department" required>
                  <option value="">— Select Department —</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= htmlspecialchars($d['DepartmentName']) ?>"><?= htmlspecialchars($d['DepartmentName']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="tf-label">Position <span style="color:#dc2626;">*</span></label>
                <input type="text" class="form-control form-control-sm" name="Position_held" id="tf-Position_held" required>
              </div>
              <div>
                <label class="tf-label">Job Title</label>
                <input type="text" class="form-control form-control-sm" name="Job_tittle" id="tf-Job_tittle">
              </div>
              <div>
                <label class="tf-label">Category</label>
                <input type="text" class="form-control form-control-sm" name="Category" id="tf-Category">
              </div>
              <div>
                <label class="tf-label">Branch</label>
                <input type="text" class="form-control form-control-sm" name="Branch" id="tf-Branch">
              </div>
              <div>
                <label class="tf-label">Employee Status</label>
                <input type="text" class="form-control form-control-sm" name="Employee_Status" id="tf-Employee_Status" placeholder="e.g. Regular, Probationary">
              </div>
              <div>
                <label class="tf-label">Hired Date <span style="color:#dc2626;">*</span></label>
                <input type="date" class="form-control form-control-sm" name="Hired_date" id="tf-Hired_date" required>
              </div>
              <div>
                <label class="tf-label">Cut-Off</label>
                <input type="text" class="form-control form-control-sm" name="CutOff" id="tf-CutOff" placeholder="e.g. 15th/30th">
              </div>
            </div>
          </div>

          <!-- ── CONTACT INFORMATION ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-telephone"></i> Contact Information</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Mobile Number</label>
                <input type="text" class="form-control form-control-sm" name="Mobile_Number" id="tf-Mobile_Number">
              </div>
              <div>
                <label class="tf-label">Phone Number</label>
                <input type="text" class="form-control form-control-sm" name="Phone_Number" id="tf-Phone_Number">
              </div>
              <div>
                <label class="tf-label">Email Address</label>
                <input type="email" class="form-control form-control-sm" name="Email_Address" id="tf-Email_Address">
              </div>
              <div class="tf-full">
                <label class="tf-label">Present Address</label>
                <input type="text" class="form-control form-control-sm" name="Present_Address" id="tf-Present_Address">
              </div>
              <div class="tf-full">
                <label class="tf-label">Permanent Address</label>
                <input type="text" class="form-control form-control-sm" name="Permanent_Address" id="tf-Permanent_Address">
              </div>
            </div>
          </div>

          <!-- ── PERSONAL INFORMATION ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-person"></i> Personal Information</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Birth Date</label>
                <input type="date" class="form-control form-control-sm" name="Birth_date" id="tf-Birth_date">
              </div>
              <div>
                <label class="tf-label">Birth Place</label>
                <input type="text" class="form-control form-control-sm" name="Birth_Place" id="tf-Birth_Place">
              </div>
              <div>
                <label class="tf-label">Gender</label>
                <select class="form-select form-select-sm" name="Gender" id="tf-Gender">
                  <option value="">— Select —</option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div>
                <label class="tf-label">Civil Status</label>
                <select class="form-select form-select-sm" name="Civil_Status" id="tf-Civil_Status">
                  <option value="">— Select —</option>
                  <option value="Single">Single</option>
                  <option value="Married">Married</option>
                  <option value="Widowed">Widowed</option>
                  <option value="Separated">Separated</option>
                  <option value="Divorced">Divorced</option>
                </select>
              </div>
              <div>
                <label class="tf-label">Nationality</label>
                <input type="text" class="form-control form-control-sm" name="Nationality" id="tf-Nationality">
              </div>
              <div>
                <label class="tf-label">Religion</label>
                <input type="text" class="form-control form-control-sm" name="Religion" id="tf-Religion">
              </div>
            </div>
          </div>

          <!-- ── EMERGENCY CONTACT ── -->
          <div class="mb-3">
            <div class="tf-section-title"><i class="bi bi-heart-pulse"></i> Emergency Contact</div>
            <div class="transfer-form-grid">
              <div>
                <label class="tf-label">Contact Person</label>
                <input type="text" class="form-control form-control-sm" name="Contact_Person" id="tf-Contact_Person">
              </div>
              <div>
                <label class="tf-label">Relationship</label>
                <input type="text" class="form-control form-control-sm" name="Relationship" id="tf-Relationship">
              </div>
              <div>
                <label class="tf-label">Contact Number</label>
                <input type="text" class="form-control form-control-sm" name="Contact_Number_Emergency" id="tf-Contact_Number_Emergency">
              </div>
            </div>
          </div>

          <!-- ── EDUCATION & NOTES ── -->
          <div class="mb-0">
            <div class="tf-section-title"><i class="bi bi-book"></i> Education &amp; Notes</div>
            <div class="transfer-form-grid">
              <div class="tf-full">
                <label class="tf-label">Educational Background</label>
                <input type="text" class="form-control form-control-sm" name="Educational_Background" id="tf-Educational_Background">
              </div>
              <div class="tf-full">
                <label class="tf-label">Notes</label>
                <textarea class="form-control form-control-sm" name="Notes" id="tf-Notes" rows="2"></textarea>
              </div>
            </div>
          </div>

        </div><!-- /modal-body -->

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="transferSubmitBtn" name="transferApplicant" class="btn btn-success">
            <i class="bi bi-person-plus-fill"></i> Confirm Transfer to Employee List
          </button>
        </div>

      </div><!-- /modal-content -->
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl        = document.getElementById('updateStatusModal');
  const modalStatus    = document.getElementById('modalStatus');
  const modalDept      = document.getElementById('modalDept');
  const interviewPanel = document.getElementById('interviewFields');
  const filesModal     = document.getElementById('filesModal');
  const filesModalBody = document.getElementById('filesModalBody');
  const INTERVIEW_STATUSES = [2, 3, 4, 5];

  // ── Page loading overlay + double-submit lock ──────────────
  const loadingEl   = document.getElementById('pageLoading');
  const loadingText = document.getElementById('pageLoadingText');
  let submitLocked  = false;
  let loadingTimer  = null;

  function resetLoading() {
    clearTimeout(loadingTimer);
    if (loadingEl) loadingEl.classList.remove('show');
    submitLocked = false;
    document.querySelectorAll('form button[type="submit"]').forEach(b => {
      if (b.id !== 'transferSubmitBtn') b.disabled = false;
    });
  }
  function showLoading(msg) {
    if (loadingText) loadingText.textContent = msg || 'Please wait…';
    if (loadingEl) loadingEl.classList.add('show');
    clearTimeout(loadingTimer);
    loadingTimer = setTimeout(resetLoading, 60000);   // safety net if the request never leaves
  }
  // Back/forward cache can restore the page mid-"loading"
  window.addEventListener('pageshow', e => { if (e.persisted) resetLoading(); });

  // ── Row click → full application details ───────────────────
  const detailModalEl = document.getElementById('appDetailModal');
  const detailBody    = document.getElementById('appDetailBody');
  if (detailModalEl && detailBody) {
    let detailReq = 0;
    const VA_CSRF = <?= json_encode($_SESSION['va_csrf'] ?? '') ?>;
    const adEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const adField = (label, val, full = false) => `
      <div class="ad-field${full ? ' ad-full' : ''}">
        <div class="ad-label">${adEsc(label)}</div>
        <div class="ad-value">${val ? adEsc(val) : '<span class="ad-empty">—</span>'}</div>
      </div>`;
    const adSection = (icon, title, inner) => `
      <div class="ad-section">
        <div class="ad-title"><i class="bi ${icon}"></i> ${adEsc(title)}</div>
        ${inner}
      </div>`;
    const adText = val => val ? `<div class="ad-pre">${adEsc(val)}</div>` : '<span class="ad-empty">—</span>';
    const adHref = u => /^\s*(javascript|data|vbscript):/i.test(String(u || '')) ? '#' : String(u || '#');

    // Educational_Background may be plain text or JSON from the wizard form — handle both
    const adFlex = raw => {
      const txt = String(raw ?? '').trim();
      if (!txt) return '<span class="ad-empty">—</span>';
      if (txt[0] === '[' || txt[0] === '{') {
        try {
          const parsed = JSON.parse(txt);
          const items  = Array.isArray(parsed) ? parsed : [parsed];
          if (!items.length) return '<span class="ad-empty">—</span>';
          return items.map(it => {
            if (it && typeof it === 'object') {
              const lines = Object.entries(it)
                .filter(([, v]) => v !== null && v !== '')
                .map(([k, v]) => `<div><span class="ad-k">${adEsc(k.replace(/_/g, ' '))}:</span> ${adEsc(typeof v === 'object' ? JSON.stringify(v) : v)}</div>`)
                .join('');
              return `<div class="ad-chip">${lines}</div>`;
            }
            return `<div class="ad-chip">${adEsc(it)}</div>`;
          }).join('');
        } catch (err) { /* not JSON, fall through to plain text */ }
      }
      return `<div class="ad-pre">${adEsc(txt)}</div>`;
    };

    const renderDetail = d => {
      const st = parseInt(d.Status, 10);

      const canEdit = !!d.CanEditRemarks;
      const remarkBox = (field, cls, icon, title, val, emptyTxt, meta) => `
        <div class="${cls}" data-remark-box="${field}" data-app="${d.ApplicationID}" data-empty="${adEsc(emptyTxt)}">
          <div class="ad-reject-title"><i class="bi ${icon}"></i> ${title}
            ${canEdit ? '<button type="button" class="ad-remark-edit" data-remark-edit><i class="bi bi-pencil-fill"></i> Edit</button>' : ''}
          </div>
          <div data-remark-view>${val ? `<div class="ad-pre">${adEsc(val)}</div>` : `<span class="ad-empty">${adEsc(emptyTxt)}</span>`}</div>
          ${meta ? `<div class="ad-remark-meta">${adEsc(meta)}</div>` : ''}
        </div>`;
      detailBody._rm    = { hr: d.HRRemarks || '', rejection: d.RejectionRemarks || '' };
      detailBody._dirty = false;
      const rejectBox = st === 7
        ? remarkBox('rejection', 'ad-reject', 'bi-x-octagon-fill', 'Rejection Remarks', d.RejectionRemarks, 'No remarks were recorded for this applicant.', '')
        : '';
      const hrBox = remarkBox('hr', 'ad-hr', 'bi-bookmark-star-fill', 'HR Remarks', d.HRRemarks,
        'No HR remarks yet. Use Edit to flag or note this applicant.',
        d.RemarksUpdatedBy ? `Last edited by ${d.RemarksUpdatedBy}${d.RemarksUpdatedAt ? ' · ' + d.RemarksUpdatedAt : ''}` : '');

      const interviews = (d.Interviews || []).length
        ? d.Interviews.map(i => `
            <div class="ad-chip">
              <div><i class="bi bi-calendar-event"></i> <strong>${adEsc(i.When || '—')}</strong></div>
              ${i.Address   ? `<div><span class="ad-k">Location:</span> ${adEsc(i.Address)}</div>` : ''}
              ${i.HRContact ? `<div><span class="ad-k">HR Contact:</span> ${adEsc(i.HRContact)}</div>` : ''}
            </div>`).join('')
        : '<span class="ad-empty">No interview scheduled yet.</span>';

      let filesHtml;
      if (d.FilesLocked) {
        const n = d.FileCount || 0;
        filesHtml = `<span class="ad-empty"><i class="bi bi-lock-fill"></i> Files are locked for rejected applications (${n} file${n === 1 ? '' : 's'} on record).</span>`;
      } else if (!(d.Files || []).length) {
        filesHtml = '<span class="ad-empty">No files uploaded.</span>';
      } else {
        filesHtml = d.Files.map(f => `
          <a class="ad-file" href="${adEsc(adHref(f.FilePath))}" target="_blank" rel="noreferrer noopener">
            <i class="bi bi-paperclip"></i>
            <span><strong>${adEsc(f.FileCategory)}</strong> · ${adEsc(f.FileName)}</span>
          </a>`).join('');
      }

      detailBody.innerHTML = `
        <div class="ad-head">
          <div class="ad-name">${adEsc(d.FullName || 'Unnamed applicant')}</div>
          <div class="ad-meta">
            <span class="status-pill s-${st}"><span class="dot"></span>${adEsc(d.StatusLabel)}</span>
            ${d.Transferred ? '<span class="badge-transferred"><span class="tf-checkmark"><i class="bi bi-check-lg"></i></span> Transferred</span>' : ''}
          </div>
        </div>
        ${rejectBox}
        ${hrBox}
        ${adSection('bi-briefcase-fill', 'Application', `<div class="ad-grid">
          ${adField('Position', d.Position)}
          ${adField('Department', d.DepartmentName)}
          ${adField('Application Type', d.ApplicationType)}
          ${adField('Date Applied', d.DateApplied)}
        </div>`)}
        ${adSection('bi-telephone-fill', 'Contact', `<div class="ad-grid">
          ${adField('Email', d.Email)}
          ${adField('Phone', d.Phone)}
          ${adField('Mobile', d.Mobile_Number)}
        </div>`)}
        ${adSection('bi-person-vcard-fill', 'Personal Information', `<div class="ad-grid">
          ${adField('Birth Date', d.Birth_date)}
          ${adField('Birth Place', d.Birth_Place)}
          ${adField('Gender', d.Gender)}
          ${adField('Civil Status', d.Civil_Status)}
          ${adField('Nationality', d.Nationality)}
          ${adField('Religion', d.Religion)}
        </div>`)}
        ${adSection('bi-geo-alt-fill', 'Address', `<div class="ad-grid">
          ${adField('Present Address', d.Present_Address, true)}
          ${adField('Permanent Address', d.Permanent_Address, true)}
        </div>`)}
        ${adSection('bi-card-heading', 'Government IDs', `<div class="ad-grid">
          ${adField('SSS', d.SSS_Number)}
          ${adField('TIN', d.TIN_Number)}
          ${adField('PhilHealth', d.Philhealth_Number)}
          ${adField('HDMF / Pag-IBIG', d.HDMF)}
        </div>`)}
        ${adSection('bi-telephone-inbound-fill', 'Emergency Contact', `<div class="ad-grid">
          ${adField('Contact Person', d.Contact_Person)}
          ${adField('Relationship', d.Relationship)}
          ${adField('Contact Number', d.Contact_Number_Emergency)}
        </div>`)}
        ${adSection('bi-mortarboard-fill', 'Educational Background', adFlex(d.Educational_Background))}
        ${adSection('bi-journal-text', 'Notes', adText(d.Notes))}
        ${adSection('bi-calendar-event', 'Interview History', interviews)}
        ${adSection('bi-paperclip', 'Uploaded Files', filesHtml)}
      `;
    };

    const openDetail = async appId => {
      const my = ++detailReq;   // ignore stale responses if another row is clicked meanwhile
      detailBody.innerHTML = '<div style="text-align:center;padding:2.5rem;color:#94a3b8;"><div class="spinner-border spinner-border-sm me-2"></div>Loading details…</div>';
      bootstrap.Modal.getOrCreateInstance(detailModalEl).show();
      try {
        const res  = await fetch(`?_action=view_applicant&application_id=${encodeURIComponent(appId)}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (my !== detailReq) return;
        if (!data.success) {
          detailBody.innerHTML = `<div class="alert alert-danger mb-0">${adEsc(data.message || 'Could not load the application details.')}</div>`;
          return;
        }
        renderDetail(data);
      } catch (err) {
        if (my !== detailReq) return;
        console.error('Application details error:', err);
        detailBody.innerHTML = '<div class="alert alert-danger mb-0">Could not load the application details. Please try again.</div>';
      }
    };

    detailModalEl.addEventListener('hidden.bs.modal', () => { if (detailBody._dirty) location.reload(); });

    detailBody.addEventListener('click', async e => {
      const box = e.target.closest('[data-remark-box]');
      if (!box) return;
      const editBtn = box.querySelector('[data-remark-edit]');
      const field   = box.dataset.remarkBox;
      const view    = box.querySelector('[data-remark-view]');
      const rm      = detailBody._rm || (detailBody._rm = {});
      const paint   = () => {
        const v = rm[field] || '';
        view.innerHTML = v ? `<div class="ad-pre">${adEsc(v)}</div>` : `<span class="ad-empty">${adEsc(box.dataset.empty)}</span>`;
        if (editBtn) editBtn.style.display = '';
      };

      if (e.target.closest('[data-remark-edit]')) {
        view.innerHTML = `<textarea class="form-control form-control-sm" rows="3" maxlength="1000">${adEsc(rm[field] || '')}</textarea>
          <div class="ad-remark-actions">
            <button type="button" class="btn btn-sm btn-primary" data-remark-save>Save</button>
            <button type="button" class="btn btn-sm btn-light" data-remark-cancel>Cancel</button>
          </div>`;
        editBtn.style.display = 'none';
        view.querySelector('textarea').focus();
        return;
      }
      if (e.target.closest('[data-remark-cancel]')) { paint(); return; }

      const saveBtn = e.target.closest('[data-remark-save]');
      if (!saveBtn) return;
      const ta = view.querySelector('textarea');
      if (field === 'rejection' && !ta.value.trim()) {
        Swal.fire({ icon: 'warning', title: 'Remarks required', text: 'Rejection remarks cannot be empty.' });
        return;
      }
      saveBtn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('_action', 'save_remarks');
        fd.append('csrf', VA_CSRF);
        fd.append('application_id', box.dataset.app);
        fd.append('field', field);
        fd.append('remarks', ta.value);
        const res  = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) {
          Swal.fire({ icon: 'error', title: 'Could not save', text: data.message || 'Please try again.' });
          saveBtn.disabled = false;
          return;
        }
        rm[field] = data.remarks || '';
        detailBody._dirty = true;   // list snippet refreshes when the modal closes
        paint();
      } catch (err) {
        console.error('Save remarks error:', err);
        Swal.fire({ icon: 'error', title: 'Could not save', text: 'Network error. Please try again.' });
        saveBtn.disabled = false;
      }
    });

    document.getElementById('applicantsTable')?.addEventListener('click', e => {
      // Don't hijack clicks on buttons/links/pills/flags, or when the user is selecting text to copy
      if (e.target.closest('a, button, input, select, textarea, label, .emp-flag, [data-bs-toggle]')) return;
      if (String(window.getSelection ? window.getSelection() : '').trim() !== '') return;
      const row = e.target.closest('tr.app-row');
      if (row && row.dataset.appId) openDetail(row.dataset.appId);
    });
  }

  // ── Employee match modal ───────────────────────────────────
  const empMatchModal = document.getElementById('empMatchModal');
  if (empMatchModal) {
    const escH = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const field = (label, val) => val ? `
      <div>
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;">${label}</div>
        <div style="font-size:.85rem;font-weight:600;color:#1e293b;">${escH(val)}</div>
      </div>` : '';

    empMatchModal.addEventListener('show.bs.modal', e => {
      let data = {};
      try { data = JSON.parse(e.relatedTarget?.dataset?.match || '{}'); } catch (err) {}
      document.getElementById('empMatchApplicant').textContent = data.applicant || 'This applicant';
      const body = document.getElementById('empMatchBody');
      const recs = data.records || [];

      if (!recs.length) {
        body.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8;">No details available.</div>';
        return;
      }

      body.innerHTML = recs.map(r => `
        <div style="border:1px solid ${r.Blacklisted ? '#fecaca' : '#e2e8f0'};background:${r.Blacklisted ? '#fef2f2' : '#fff'};border-radius:12px;padding:.85rem 1rem;margin-bottom:.75rem;">
          <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.65rem;">
            <div style="font-weight:800;font-size:.95rem;color:#0f172a;">${escH(r.Name)}</div>
            <div style="display:flex;gap:.35rem;flex-wrap:wrap;">
              <span class="emp-flag ${r.Active ? 'ef-current' : 'ef-former'}" style="cursor:default;">${r.Active ? 'Active' : 'Inactive'}</span>
              ${r.Blacklisted ? '<span class="emp-flag ef-blacklisted" style="cursor:default;"><i class="bi bi-slash-circle-fill"></i> Blacklisted</span>' : ''}
            </div>
          </div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem;">
            ${field('File No', r.FileNo)}
            ${field('Department', r.Department)}
            ${field('Branch', r.Branch)}
            ${field('Position', r.Position)}
            ${field('Job Title', r.JobTitle)}
            ${field('Employee Status', r.EmployeeStatus)}
            ${field('Hired Date', r.HiredDate)}
          </div>
        </div>`).join('');
    });
  }

 // ── Uploaded files modal ───────────────────────────────────
if (filesModal && filesModalBody) {
  filesModal.addEventListener('show.bs.modal', e => {
    const button = e.relatedTarget;
    let files = [];
    try {
      files = JSON.parse(button?.dataset?.files || '[]');
    } catch (err) {
      files = [];
    }

    filesModalBody.innerHTML = '';

    if (!files.length) {
      filesModalBody.innerHTML = `
        <div style="text-align:center;padding:2rem;color:#94a3b8;">
          <i class="bi bi-folder2-open" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
          No uploaded files available.
        </div>`;
      return;
    }

    const extConfig = {
      pdf:  { icon: 'bi-file-earmark-pdf',    color: '#ef4444', bg: 'rgba(239,68,68,.1)'   },
      doc:  { icon: 'bi-file-earmark-word',   color: '#2563eb', bg: 'rgba(37,99,235,.1)'   },
      docx: { icon: 'bi-file-earmark-word',   color: '#2563eb', bg: 'rgba(37,99,235,.1)'   },
      jpg:  { icon: 'bi-file-earmark-image',  color: '#f59e0b', bg: 'rgba(245,158,11,.1)'  },
      jpeg: { icon: 'bi-file-earmark-image',  color: '#f59e0b', bg: 'rgba(245,158,11,.1)'  },
      png:  { icon: 'bi-file-earmark-image',  color: '#f59e0b', bg: 'rgba(245,158,11,.1)'  },
      gif:  { icon: 'bi-file-earmark-image',  color: '#f59e0b', bg: 'rgba(245,158,11,.1)'  },
      xls:  { icon: 'bi-file-earmark-excel',  color: '#16a34a', bg: 'rgba(22,163,74,.1)'   },
      xlsx: { icon: 'bi-file-earmark-excel',  color: '#16a34a', bg: 'rgba(22,163,74,.1)'   },
    };
    const defaultExt = { icon: 'bi-file-earmark', color: '#64748b', bg: 'rgba(100,116,139,.1)' };

    // Clean up ugly UUID filenames
    const cleanName = (raw) => {
      if (!raw) return 'Unnamed file';
      // Strip leading underscores and UUID-like suffixes (_69d7117c...)
      return raw
        .replace(/_[0-9a-f]{8,}(\.[^.]+)$/i, '$1') // strip UUID suffix before extension
        .replace(/^_+/, '')                          // strip leading underscores
        .replace(/_/g, ' ');                         // replace remaining underscores with spaces
    };

    // Group by category
    const grouped = files.reduce((acc, file) => {
      const cat = file.FileCategory || 'Other';
      acc[cat] = acc[cat] || [];
      acc[cat].push(file);
      return acc;
    }, {});

    Object.entries(grouped).forEach(([category, items]) => {
      // Category header
      const header = document.createElement('div');
      header.style.cssText = 'font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:1rem 0 .4rem;padding:0 .25rem;';
      header.innerHTML = `<i class="bi bi-folder-fill" style="margin-right:.35rem;"></i>${category}`;
      filesModalBody.appendChild(header);

      items.forEach(file => {
        const ext  = (file.FileName || '').split('.').pop().toLowerCase();
        const conf = extConfig[ext] || defaultExt;
        const name = cleanName(file.FileName);

        const item = document.createElement('a');
        item.href   = file.FilePath || '#';
        item.target = '_blank';
        item.rel    = 'noreferrer noopener';
        item.style.cssText = `
          display: flex;
          align-items: center;
          gap: .75rem;
          padding: .65rem .85rem;
          border-radius: 10px;
          border: 1px solid #e2e8f0;
          margin-bottom: .4rem;
          text-decoration: none;
          color: #1e293b;
          transition: background .15s, border-color .15s, transform .1s;
          background: #fff;
        `;

        item.addEventListener('mouseenter', () => {
          item.style.background    = conf.bg;
          item.style.borderColor   = conf.color + '55';
          item.style.transform     = 'translateX(3px)';
        });
        item.addEventListener('mouseleave', () => {
          item.style.background    = '#fff';
          item.style.borderColor   = '#e2e8f0';
          item.style.transform     = 'translateX(0)';
        });

        item.innerHTML = `
          <div style="
            width: 36px; height: 36px; border-radius: 8px;
            background: ${conf.bg};
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
          ">
            <i class="bi ${conf.icon}" style="font-size:1.1rem;color:${conf.color};"></i>
          </div>
          <div style="min-width:0;flex:1;">
            <div style="font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              ${name}
            </div>
            <div style="font-size:.7rem;color:#94a3b8;margin-top:.1rem;">
              ${ext.toUpperCase()} file &nbsp;·&nbsp; <i class="bi bi-box-arrow-up-right" style="font-size:.65rem;"></i> Open
            </div>
          </div>
        `;

        filesModalBody.appendChild(item);
      });
    });
  });
}

  // ── Interview panel ────────────────────────────────────────
  function toggleInterview(status) {
    const show = INTERVIEW_STATUSES.includes(parseInt(status));
    interviewPanel.style.display = show ? 'block' : 'none';
    ['InterviewDateTime', 'OfficeAddress', 'HRContactFileNo'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.required = show;
    });
  }

  // ── Dept hint / required marker ───────────────────────────
  function updateDeptHint(status) {
    const notPending = parseInt(status) !== 0;
    const hasDept    = !!modalDept.value;
    document.getElementById('deptHint').style.display     = notPending && !hasDept ? 'block' : 'none';
    document.getElementById('deptRequired').style.display = notPending ? 'inline' : 'none';
    document.getElementById('deptOptional').style.display = notPending ? 'none'   : 'inline';
  }

  // ── Populate modal on open ─────────────────────────────────
  modalEl.addEventListener('show.bs.modal', e => {
    const btn = e.relatedTarget;
    if (!btn) return;
    document.getElementById('modalAppID').value = btn.dataset.id     || '';
    modalStatus.value                           = btn.dataset.status || '0';
    if (btn.dataset.department) modalDept.value = btn.dataset.department;
    const sendEmailChk = document.getElementById('modalSendEmail');
    if (sendEmailChk) {
      const hasEmail = !!(btn.dataset.email && btn.dataset.email.trim());
      sendEmailChk.checked  = hasEmail;
      sendEmailChk.disabled = !hasEmail;
    }
    document.getElementById('InterviewDateTime').value       = '';
    document.getElementById('OfficeAddress').value           = '';
    document.getElementById('HRContactFileNo').selectedIndex = 0;
    document.getElementById('rejectionRemarks').value    = '';
    document.getElementById('emailSubject').value        = '';
    document.getElementById('emailBody').value           = '';
    document.getElementById('emailModeTemplate').checked = true;
    toggleReject(modalStatus.value);
    syncEmailUI();
    toggleInterview(modalStatus.value);
    updateDeptHint(modalStatus.value);
  });

  modalStatus.addEventListener('change', function () {
    toggleInterview(this.value);
    toggleReject(this.value);
    updateDeptHint(this.value);
  });
  modalDept.addEventListener('change', () => updateDeptHint(modalStatus.value));

  // ── Rejection remarks (status 7 only) ──────────────────────
  function toggleReject(status) {
    const show = parseInt(status, 10) === 7;
    document.getElementById('rejectFields').style.display = show ? 'block' : 'none';
    document.getElementById('rejectionRemarks').required  = show;
  }

  // ── Email composer (template vs. compose my own) ───────────
  function syncEmailUI() {
    const chk        = document.getElementById('modalSendEmail');
    const on         = !!chk && chk.checked && !chk.disabled;
    const showCustom = on && document.getElementById('emailModeCustom').checked;
    document.getElementById('emailOptions').style.display   = on ? 'block' : 'none';
    document.getElementById('customEmailBox').style.display = showCustom ? 'block' : 'none';
    document.getElementById('emailSubject').required = showCustom;
    document.getElementById('emailBody').required    = showCustom;
  }
  document.getElementById('modalSendEmail').addEventListener('change', syncEmailUI);
  document.querySelectorAll('input[name="emailMode"]').forEach(r => r.addEventListener('change', syncEmailUI));

  // ── Submit — warn if no dept ───────────────────────────────
  document.getElementById('updateStatusForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form       = this;
    if (submitLocked) return;
    const remarksEl = document.getElementById('rejectionRemarks');
    if (parseInt(modalStatus.value, 10) === 7 && !remarksEl.value.trim()) {
      Swal.fire({
        icon: 'warning', title: 'Remarks required',
        text: 'Please enter the reason for rejecting this applicant.',
        confirmButtonColor: '#ca8a04', background: '#fff', color: '#0f172a',
      }).then(() => remarksEl.focus());
      return;
    }
    const customOn = document.getElementById('customEmailBox').style.display !== 'none';
    if (customOn && (!document.getElementById('emailSubject').value.trim() || !document.getElementById('emailBody').value.trim())) {
      Swal.fire({
        icon: 'warning', title: 'Email incomplete',
        text: 'Please enter both a subject and a message, or switch back to the template.',
        confirmButtonColor: '#ca8a04', background: '#fff', color: '#0f172a',
      });
      return;
    }
    const notPending = parseInt(modalStatus.value) !== 0;
    const noDept     = !modalDept.value;
    const proceed    = () => {
      if (submitLocked) return;
      submitLocked = true;
      form.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true);
      const mailChk = document.getElementById('modalSendEmail');
      showLoading(mailChk && mailChk.checked && !mailChk.disabled ? 'Saving, Please wait…' : 'Saving changes…');
      bootstrap.Modal.getInstance(modalEl).hide();
      setTimeout(() => form.submit(), 300);
    };
    if (notPending && noDept) {
      Swal.fire({
        title: 'No Department Selected',
        text:  'It is recommended to assign a department when changing from Pending. Continue anyway?',
        icon:  'warning',
        showCancelButton:    true,
        confirmButtonColor:  '#ca8a04',
        cancelButtonColor:   '#64748b',
        confirmButtonText:   'Yes, continue',
        background: '#fff',  color: '#0f172a',
      }).then(r => { if (r.isConfirmed) proceed(); });
    } else {
      proceed();
    }
  });

  // ── Close file dropdowns on outside click ──────────────────
  document.addEventListener('click', e => {
    document.querySelectorAll('.files-wrap.open').forEach(w => {
      if (!w.contains(e.target)) w.classList.remove('open');
    });
  });

  // ══ Transfer Modal ═════════════════════════════════════════
  const transferModalEl = document.getElementById('transferModal');
  if (transferModalEl) {

    // Helper: set value of any input/select/textarea by id
    function tfSet(id, value) {
      const el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'SELECT') {
        // Try exact match first, fallback to case-insensitive
        let matched = false;
        for (const opt of el.options) {
          if (opt.value === value) { opt.selected = true; matched = true; break; }
        }
        if (!matched && value) {
          for (const opt of el.options) {
            if (opt.value.toLowerCase() === value.toLowerCase()) { opt.selected = true; break; }
          }
        }
      } else {
        el.value = value || '';
      }
    }

    transferModalEl.addEventListener('show.bs.modal', async e => {
      const btn   = e.relatedTarget;
      if (!btn) return;
      const appId = btn.dataset.appId;
      if (!appId) return;

      // Show loading overlay, disable submit
      const overlay    = document.getElementById('transferLoadingOverlay');
      const submitBtn  = document.getElementById('transferSubmitBtn');
      overlay.style.display    = 'flex';
      submitBtn.disabled       = true;

      // Reset all fields first
      document.getElementById('transferAppID').value       = '';
      document.getElementById('tf-display-FileNo').textContent     = '…';
      document.getElementById('tf-display-EmployeeID').textContent = '…';
      document.getElementById('tf-FileNo').value      = '';
      document.getElementById('tf-EmployeeID').value  = '';

      try {
        const res  = await fetch(`?_action=fetch_applicant&application_id=${encodeURIComponent(appId)}`);
        const data = await res.json();

        if (!data.success) {
          Swal.fire({
            icon: 'error', title: 'Failed to load',
            text: data.message || 'Could not fetch applicant details.',
            background: '#fff', color: '#0f172a',
          });
          bootstrap.Modal.getInstance(transferModalEl).hide();
          return;
        }

        // ── Guard: already transferred ───────────────────────
        if (data.TransferredToEmployee) {
          bootstrap.Modal.getInstance(transferModalEl).hide();
          Swal.fire({
            icon: 'info',
            title: 'Already Transferred',
            html: 'This applicant has already been added to the Employee List.',
            confirmButtonColor: '#059669',
            background: '#fff', color: '#0f172a',
          });
          return;
        }

        // ── Auto-generated IDs (display + hidden) ────────────
        document.getElementById('tf-display-FileNo').textContent     = data.NextFileNo;
        document.getElementById('tf-display-EmployeeID').textContent = data.GeneratedEmpID;
        document.getElementById('tf-FileNo').value     = data.NextFileNo;
        document.getElementById('tf-EmployeeID').value = data.GeneratedEmpID;
        document.getElementById('transferAppID').value = data.ApplicationID;

        // ── Name ─────────────────────────────────────────────
        tfSet('tf-LastName',   data.LastName);
        tfSet('tf-FirstName',  data.FirstName);
        tfSet('tf-MiddleName', data.MiddleName);

        // ── Work ─────────────────────────────────────────────
        tfSet('tf-Position_held', data.Position);
        tfSet('tf-Department',    data.DepartmentName);
        // Hired date = today
        document.getElementById('tf-Hired_date').value = new Date().toISOString().slice(0, 10);

        // ── Contact ───────────────────────────────────────────
        tfSet('tf-Mobile_Number',   data.Mobile_Number || data.Phone);
        tfSet('tf-Phone_Number',    data.Phone);
        tfSet('tf-Email_Address',   data.Email);
        tfSet('tf-Present_Address', data.Present_Address);
        tfSet('tf-Permanent_Address', data.Permanent_Address);

        // ── Government IDs ───────────────────────────────────
        tfSet('tf-SSS_Number',        data.SSS_Number);
        tfSet('tf-TIN_Number',        data.TIN_Number);
        tfSet('tf-Philhealth_Number', data.Philhealth_Number);
        tfSet('tf-HDMF',              data.HDMF);

        // ── Personal ─────────────────────────────────────────
        tfSet('tf-Birth_date',  data.Birth_date);
        tfSet('tf-Birth_Place', data.Birth_Place);
        tfSet('tf-Gender',      data.Gender);
        tfSet('tf-Civil_Status',data.Civil_Status);
        tfSet('tf-Nationality', data.Nationality || 'Filipino');
        tfSet('tf-Religion',    data.Religion);

        // ── Emergency ─────────────────────────────────────────
        tfSet('tf-Contact_Person',           data.Contact_Person);
        tfSet('tf-Relationship',             data.Relationship);
        tfSet('tf-Contact_Number_Emergency', data.Contact_Number_Emergency);

        // ── Education & Notes ─────────────────────────────────
        tfSet('tf-Educational_Background', data.Educational_Background);
        tfSet('tf-Notes',                  data.Notes);

      } catch (err) {
        console.error('Transfer fetch error:', err);
        Swal.fire({
          icon: 'error', title: 'Network Error',
          text: 'Could not connect to the server. Please try again.',
          background: '#fff', color: '#0f172a',
        });
        bootstrap.Modal.getInstance(transferModalEl).hide();
      } finally {
        overlay.style.display = 'none';
        submitBtn.disabled    = false;
      }
    });

    // ── Confirm before submit ──────────────────────────────
    document.getElementById('transferForm').addEventListener('submit', function (e) {
      e.preventDefault();
      const form     = this;
      const fileNo   = document.getElementById('tf-display-FileNo').textContent;
      const empID    = document.getElementById('tf-display-EmployeeID').textContent;
      const fullName = [
        document.getElementById('tf-LastName').value,
        document.getElementById('tf-FirstName').value,
      ].filter(Boolean).join(', ');

      Swal.fire({
        title: 'Confirm Transfer',
        html: `Create a new employee record for <strong>${fullName}</strong>?<br>
               <span style="font-size:.82rem;color:#64748b;">File No: <strong>${fileNo}</strong> &nbsp;·&nbsp; System ID: <strong>${empID}</strong></span>`,
        icon:  'question',
        showCancelButton:   true,
        confirmButtonColor: '#059669',
        cancelButtonColor:  '#64748b',
        confirmButtonText:  '<i class="bi bi-person-plus-fill"></i> Yes, Transfer',
        background: '#fff', color: '#0f172a',
      }).then(r => {
        if (r.isConfirmed) {
          if (submitLocked) return;
          submitLocked = true;
          showLoading('Transferring applicant…');
          bootstrap.Modal.getInstance(transferModalEl).hide();
          setTimeout(() => form.submit(), 300);
        }
      });
    });
  }

  // ══ Add Walk-in Modal ══════════════════════════════════════
  const walkinForm = document.getElementById('addWalkinForm');
  if (walkinForm) {
    walkinForm.addEventListener('submit', e => {
      if (submitLocked) { e.preventDefault(); return; }
      submitLocked = true;
      showLoading('Adding applicant…');
      setTimeout(() => walkinForm.querySelectorAll('button[type="submit"]').forEach(b => b.disabled = true), 0);
    });
  }

  const wkTemplate = document.getElementById('wkFileRowTemplate');
  const wkRows      = document.getElementById('wkFileRows');
  const wkAddBtn    = document.getElementById('wkAddFileRow');
  if (wkTemplate && wkRows && wkAddBtn) {
    function wkAddRow() {
      if (wkRows.children.length >= 5) return;
      const clone = wkTemplate.content.cloneNode(true);
      clone.querySelector('.wk-remove-row').addEventListener('click', ev => {
        ev.target.closest('.wk-file-row').remove();
      });
      wkRows.appendChild(clone);
    }
    wkAddBtn.addEventListener('click', wkAddRow);
    wkAddRow();
  }
});
</script>
</body>
</html>