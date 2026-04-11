<?php
// ══════════════════════════════════════════════════════════════
//  view-applications.php
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR']);

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
                'DepartmentID'      => isset($r['DepartmentID']) ? (int)$r['DepartmentID'] : null,
                'DepartmentName'    => $r['DepartmentName']    ?? null,
                'ColorCode'         => $r['ColorCode']         ?? null,
                'InterviewDateTime' => $r['InterviewDateTime'] ?? null,
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
    $updated = $_GET['updated'] ?? null;
    $info    = $_GET['info']    ?? '';
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
    .interview-stage-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.5rem;
      margin: 0;
      padding: 0.25rem 0;
    }
    .interview-stage-row .stage-label {
      white-space: nowrap;
      font-size: 0.87rem;
      font-weight: 600;
      color: var(--text-muted);
    }
    .page-header {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
    }
    .page-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-top: 0.35rem;
    }
    .interview-stage-row .stage-pill {
      white-space: nowrap;
    }
    @media (max-width: 768px) {
      .interview-stage-row {
        justify-content: flex-start;
      }
      .interview-stage-row .stage-pill {
        font-size: 0.78rem;
        padding: 0.35rem 0.7rem;
      }
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
      <table class="apps-table">
        <thead>
          <tr><th>Applicant</th><th>Contact</th><th>Position</th><th>Date Applied</th><th>Files</th><th>Interview</th><th style="text-align:center;">Status</th></tr>
        </thead>
        <tbody>
        <?php if (!empty($tabApps)): foreach ($tabApps as $app):
            $sv        = $app['Status'];
            $labelText = $statusLabels[$sv] ?? 'Unknown';
            $pillClass = $statusBadgeClass[$sv] ?? 's-0';
            $color     = $app['ColorCode'] ?? null;
        ?>
          <tr <?= deptRowStyle($app['DepartmentName'] ?? null, $color) ?>>
            <td>
              <div class="applicant-name"><?= htmlspecialchars($app['FullName']) ?></div>
              <?php if ($app['DepartmentName']): ?>
                <span <?= deptBadgeStyle($app['DepartmentName'], $color) ?>>
                  <i class="bi bi-building" style="font-size:.6rem;"></i>
                  <?= htmlspecialchars($app['DepartmentName']) ?>
                </span>
              <?php endif; ?>
            </td>
            <td>
              <div>
                <a href="mailto:<?= htmlspecialchars($app['Email']) ?>" class="text-link">
                  <i class="bi bi-envelope" style="font-size:.75rem;"></i> <?= htmlspecialchars($app['Email']) ?>
                </a>
              </div>
              <div class="applicant-meta">
                <i class="bi bi-telephone" style="font-size:.7rem;"></i>
                <?= htmlspecialchars($app['Phone'] ?: '—') ?>
              </div>
            </td>
            <td><span style="font-weight:500;"><?= htmlspecialchars($app['Position']) ?></span></td>
            <td class="date-cell">
              <?php if ($app['DateApplied'] instanceof DateTime): ?>
                <div class="date-day"><?= $app['DateApplied']->format('M j, Y') ?></div>
                <div class="date-time"><?= $app['DateApplied']->format('g:i A') ?></div>
              <?php endif; ?>
            </td>
            <td><?= renderFiles($app['Files']) ?></td>
            <td>
              <?php $idtStr = fmtInterview($app['InterviewDateTime']); ?>
              <?php if ($idtStr): ?>
                <div style="font-size:.78rem;font-weight:600;color:var(--text-primary);"><?= $idtStr ?></div>
                <?php if ($app['InterviewAddress']): ?>
                  <div style="font-size:.72rem;color:var(--text-muted);">
                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($app['InterviewAddress']) ?>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
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
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7">
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
});
</script>
</body>
</html>