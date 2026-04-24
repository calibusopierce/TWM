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

// ── RBAC gate ────────────────────────────────────────────────
$pdo_rbac = new PDO(
    "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
rbac_gate($pdo_rbac, 'view_applications');

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
        'FullName'                 => $app['Fullname']         ?? '',
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

// ── Current tab ─────────────────────────────────────────────────
$tabAliases = [
    'active' => 'interview',
];
$validTabs = ['pending', 'evaluating', 'interview', 'hired', 'rejected'];
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
];

$tabDisplayNames = [
    'pending'    => 'Pending',
    'evaluating' => 'Evaluating',
    'interview'  => 'Interview',
    'hired'      => 'Hired',
    'rejected'   => 'Rejected',
];

$tabIcons = [
    'pending'    => 'bi-inbox-fill',
    'evaluating' => 'bi-hourglass-split',
    'interview'  => 'bi-calendar-event',
    'hired'      => 'bi-award',
    'rejected'   => 'bi-x-circle-fill',
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
];
$statusBadgeClass = [
    0 => 's-0', 1 => 's-1', 2 => 's-2', 3 => 's-3',
    4 => 's-4', 5 => 's-5', 6 => 's-6', 7 => 's-7',
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
        ja.Position, ja.Status, ja.DateApplied, ja.DepartmentID,
        ja.TransferredToEmployee,
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
                'Position'          => $r['Position']     ?? '',
                'Status'            => (int)($r['Status'] ?? 0),
                'DateApplied'       => $r['DateApplied']  ?? null,
                'DepartmentID'          => isset($r['DepartmentID']) ? (int)$r['DepartmentID'] : null,
                'DepartmentName'        => $r['DepartmentName']    ?? null,
                'ColorCode'             => $r['ColorCode']         ?? null,
                'TransferredToEmployee' => !empty($r['TransferredToEmployee']),
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
$statusFilter = trim($_GET['status']    ?? '');
$deptFilter   = $viewAll ? trim($_GET['dept'] ?? '') : $_userDept;

function buildTabWhere(string $tab, array &$params): string
{
    global $viewAll, $_userDept, $deptFilter, $search, $dateFrom, $dateTo, $statusFilter, $tabStatusGroups;

    $statuses = $tabStatusGroups[$tab] ?? [0];
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $where = "WHERE ja.Status IN ({$placeholders})";
    $params = $statuses;

    if ($tab !== 'pending') {
        if (!$viewAll) {
            $where .= " AND d.DepartmentName = ?";
            $params[] = $_userDept;
        } elseif ($deptFilter !== '') {
            $where .= " AND d.DepartmentName = ?";
            $params[] = $deptFilter;
        }
    }

    $statusInt = is_numeric($statusFilter) ? (int)$statusFilter : null;
    if ($statusInt !== null && in_array($statusInt, $statuses, true)) {
        $where .= " AND ja.Status = ?";
        $params[] = $statusInt;
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

$tabCounts = [];
foreach (array_keys($tabStatusGroups) as $tabKey) {
    $countParams = [];
    $countWhere = buildTabWhere($tabKey, $countParams);
    $tabCounts[$tabKey] = countApps($conn, $countWhere, $countParams);
}

$activeParams = [];
$activeWhere = buildTabWhere($activeTab, $activeParams);
$tabTotal = $tabCounts[$activeTab];
$tabPages = max(1, (int)ceil($tabTotal / $perPage));
$tabApps = fetchApps($conn, $activeWhere, $activeParams, $offset, $perPage);

$paginationParams = [
    'tab'    => $activeTab,
    'search' => $search,
];
if ($viewAll && $activeTab !== 'pending' && $deptFilter !== '') {
    $paginationParams['dept'] = $deptFilter;
}
if ($activeTab === 'interview' && $statusFilter !== '' && is_numeric($statusFilter) && in_array((int)$statusFilter, $tabStatusGroups['interview'], true)) {
    $paginationParams['status'] = $statusFilter;
}
if ($dateFrom !== '') {
    $paginationParams['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $paginationParams['date_to'] = $dateTo;
}

// ── Flash ──────────────────────────────────────────────────────
function resolveFlash(): array
{
    $updated    = $_GET['updated']     ?? null;
    $transferred = $_GET['transferred'] ?? null;
    $info       = $_GET['info']        ?? '';

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

    // Existing status-update flash
    if ($updated === '1') return [
        'type' => 'success',
        'msg'  => '<i class="bi bi-check-circle-fill me-1"></i> Application updated successfully.',
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
 * Render uploaded files button.
 */
function renderFiles(array $files): string
{
    if (empty($files)) return '<span class="no-files">No files</span>';

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

    return '<nav class="pagination-wrap d-flex justify-content-between align-items-center" aria-label="Page navigation">'
         . '<a class="page-btn ' . $prevClass . '" href="' . $prevUrl . '"><i class="bi bi-chevron-left"></i> Prev</a>'
         . '<span class="page-info">Page ' . $page . ' of ' . $totalPages . '</span>'
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
    .app-sub        { font-size: .72rem; color: var(--text-muted); margin-top: .1rem; }
    .app-row        { cursor: default; transition: background .12s; }
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
    </div>
    <?php if ($isAdmin && $activeTab !== 'pending'): ?>
    <div class="page-actions">
      <?php if (!$viewAll): ?>
        <a href="?tab=<?= htmlspecialchars($activeTab) ?>&view_all=1" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-globe2 me-1"></i> View All Departments
        </a>
      <?php else: ?>
        <a href="?tab=<?= htmlspecialchars($activeTab) ?>&view_all=0" class="btn btn-warning btn-sm">
          <i class="bi bi-building me-1"></i> Back to My Department
        </a>
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
        <?php if (!empty($tabCounts[$tabKey])): ?>
          <span class="<?= $activeTab === $tabKey ? 'tab-badge-white' : 'tab-badge-red' ?>"><?= $tabCounts[$tabKey] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- ══ CURRENT TAB CONTENT ══════════════════════════════════════════ -->

  <div class="filter-card">
    <form method="get">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
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

        <?php if ($activeTab !== 'pending'): ?>
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

        <div style="position:relative;">
          <i class="bi bi-search" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.8rem;pointer-events:none;"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                 class="form-control" placeholder="Search applicants…" style="padding-left:2rem;max-width:220px;">
        </div>
        <div class="filter-divider"></div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel-fill"></i> Apply</button>
        <a href="?tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Clear</a>

      </div>
    </form>
  </div>

  <?php if ($activeTab === 'interview'): ?>
  <?php
      $stageBaseParams = [
          'tab'       => 'interview',
          'search'    => $search,
          'date_from' => $dateFrom,
          'date_to'   => $dateTo,
      ];
      if ($viewAll && $deptFilter !== '') {
          $stageBaseParams['dept'] = $deptFilter;
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
          <tr class="app-row" <?= deptRowStyle($app['DepartmentName'] ?? null, $color) ?>>
            <!-- Applicant column: avatar + name + dept badge -->
            <td>
              <div class="app-name-wrap">
                <div class="app-avatar" style="background:<?= $bgColor ?>;"><?= htmlspecialchars($initials) ?></div>
                <div>
                  <div class="app-name"><?= htmlspecialchars($fullName) ?></div>
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
            </td>

            <!-- Position & Department column -->
            <td>
              <div style="font-size:.82rem;font-weight:600;"><?= htmlspecialchars($app['Position']) ?></div>
              <div class="app-sub"><?= htmlspecialchars($app['DepartmentName'] ?: '—') ?></div>
            </td>

            <!-- Date Applied -->
            <td class="date-cell">
              <?php if ($app['DateApplied'] instanceof DateTime): ?>
                <div class="date-day"><?= $app['DateApplied']->format('M j, Y') ?></div>
                <div class="date-time"><?= $app['DateApplied']->format('g:i A') ?></div>
              <?php endif; ?>
            </td>

            <!-- Files -->
            <td><?= renderFiles($app['Files']) ?></td>

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
              <button type="button" class="status-pill <?= htmlspecialchars($pillClass) ?>"
                data-bs-toggle="modal" data-bs-target="#updateStatusModal"
                data-id="<?= $app['ApplicationID'] ?>"
                data-status="<?= $sv ?>"
                data-department="<?= (int)($app['DepartmentID'] ?? 0) ?>">
                <span class="dot"></span><?= htmlspecialchars($labelText) ?>
                <i class="bi bi-pencil-fill" style="font-size:.6rem;opacity:.5;"></i>
              </button>
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
          <tr><td colspan="<?= $activeTab === 'hired' ? 8 : 7 ?>">
            <div class="empty-state"><i class="bi bi-folder2-open"></i><p>No applications found for the selected filters.</p></div>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= renderPagination($page, $tabPages, $paginationParams) ?>
  </div>

</div><!-- /.main-wrapper -->


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
              <?php foreach ($statusLabels as $code => $label): ?>
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
    document.getElementById('InterviewDateTime').value       = '';
    document.getElementById('OfficeAddress').value           = '';
    document.getElementById('HRContactFileNo').selectedIndex = 0;
    toggleInterview(modalStatus.value);
    updateDeptHint(modalStatus.value);
  });

  modalStatus.addEventListener('change', function () {
    toggleInterview(this.value);
    updateDeptHint(this.value);
  });
  modalDept.addEventListener('change', () => updateDeptHint(modalStatus.value));

  // ── Submit — warn if no dept ───────────────────────────────
  document.getElementById('updateStatusForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const form       = this;
    const notPending = parseInt(modalStatus.value) !== 0;
    const noDept     = !modalDept.value;
    const proceed    = () => {
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
          bootstrap.Modal.getInstance(transferModalEl).hide();
          setTimeout(() => form.submit(), 300);
        }
      });
    });
  }
});
</script>
</body>
</html>