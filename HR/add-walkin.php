<?php
// ══════════════════════════════════════════════════════════════
//  add-walkin.php — creates a manually-entered walk-in applicant
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'view_applications');

define('S_WALKIN', 8);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view-applications.php?tab=walkin');
    exit;
}

function wk_fail(string $reason): void {
    header('Location: view-applications.php?tab=walkin&walkin_added=0&error=' . urlencode($reason));
    exit;
}

$sp = fn(string $k) => trim($_POST[$k] ?? '');

$firstName      = $sp('first_name');
$lastName       = $sp('last_name');
$middleName     = $sp('middle_name');
$fullname       = trim("$firstName $middleName $lastName");
$email          = $sp('email');
$mobile         = $sp('mobile');
$position       = $sp('position');
$presentAddress = $sp('present_address');
$notes          = $sp('notes');
$applicationType = $sp('application_type');
$departmentID   = isset($_POST['department_id']) && is_numeric($_POST['department_id'])
    ? (int)$_POST['department_id'] : 0;

if ($firstName === '' || $lastName === '' || $position === '' || !$departmentID) {
    wk_fail('missing_required');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    wk_fail('invalid_email');
}
if (!in_array($applicationType, ['Walk-in', 'Job Fair', 'Other'], true)) {
    wk_fail('invalid_type');
}

// ── Validate uploaded files (files optional; category required per file) ──
$fileCategories = [];
$fcStmt = sqlsrv_query($conn, "SELECT FileCategoryID, CategoryName FROM FileCategories WHERE IsActive = 1");
if ($fcStmt) {
    while ($r = sqlsrv_fetch_array($fcStmt, SQLSRV_FETCH_ASSOC)) $fileCategories[] = $r;
    sqlsrv_free_stmt($fcStmt);
}
$validCategoryIds = array_column($fileCategories, 'FileCategoryID');

$fileInfos = [];
if (!empty($_FILES['files']['name'])) {
    $rawFiles    = $_FILES['files'];
    $rawCats     = $_POST['category_id'] ?? [];
    $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    foreach ($rawFiles['name'] as $i => $filename) {
        if (empty($filename) || ($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

        if (($rawFiles['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) wk_fail('upload_error');

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) wk_fail('invalid_file_type');
        if ($rawFiles['size'][$i] > 10 * 1024 * 1024) wk_fail('file_too_large');

        $categoryId = (int)($rawCats[$i] ?? 0);
        if ($categoryId <= 0 || !in_array($categoryId, $validCategoryIds)) wk_fail('invalid_category');

        $fileInfos[] = [
            'name'        => $filename,
            'tmp'         => $rawFiles['tmp_name'][$i],
            'ext'         => $ext,
            'category_id' => $categoryId,
        ];
    }
    if (count($fileInfos) > 5) wk_fail('too_many_files');
}

// ── Insert application ──────────────────────────────────────────
date_default_timezone_set('Asia/Manila');
$now = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

if (sqlsrv_begin_transaction($conn) === false) wk_fail('transaction_failed');

$insertSql = "
    INSERT INTO JobApplications
        (Fullname, Email, Phone, Position, DateApplied, Status, DepartmentID, TnCAccepted, TnCAcceptedAt,
         FirstName, MiddleName, LastName, Mobile_Number, Present_Address, Notes, ApplicationType)
    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL,
            ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS ApplicationID;
";
$params = [
    $fullname, $email, $mobile, $position, $now, S_WALKIN, $departmentID,
    $firstName, $middleName, $lastName, $mobile, $presentAddress, $notes, $applicationType,
];

$stmt = sqlsrv_query($conn, $insertSql, $params);
if ($stmt === false) {
    sqlsrv_rollback($conn);
    error_log('[add-walkin] insert failed: ' . print_r(sqlsrv_errors(), true));
    wk_fail('db_error');
}
sqlsrv_next_result($stmt);
$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$applicationID = (int)($row['ApplicationID'] ?? 0);
sqlsrv_free_stmt($stmt);
if (!$applicationID) {
    sqlsrv_rollback($conn);
    wk_fail('db_error');
}

// ── Save uploaded files (optional) ──────────────────────────────
if (!empty($fileInfos)) {
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/uploads/job-applications/' . $applicationID . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        sqlsrv_rollback($conn);
        wk_fail('upload_dir_failed');
    }
    $fileTypeMap = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    ];

    foreach ($fileInfos as $file) {
        $safeBase     = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $fileNameSafe = $safeBase . '_' . uniqid() . '.' . $file['ext'];
        $relativePath = '/TWM/uploads/job-applications/' . $applicationID . '/' . $fileNameSafe;
        $destPath     = $uploadDir . $fileNameSafe;
        $fileType     = $fileTypeMap[$file['ext']] ?? 'application/octet-stream';

        $fstmt = sqlsrv_query($conn,
            "INSERT INTO ApplicationFiles (ApplicationID, FileName, FilePath, FileType, UploadedAt, FileCategoryID) VALUES (?, ?, ?, ?, ?, ?)",
            [$applicationID, $fileNameSafe, $relativePath, $fileType, $now, $file['category_id']]
        );
        if ($fstmt === false) {
            sqlsrv_rollback($conn);
            wk_fail('db_error');
        }
        sqlsrv_free_stmt($fstmt);

        if (!move_uploaded_file($file['tmp'], $destPath)) {
            sqlsrv_query($conn, "DELETE FROM ApplicationFiles WHERE ApplicationID = ? AND FileName = ?", [$applicationID, $fileNameSafe]);
            sqlsrv_rollback($conn);
            wk_fail('upload_move_failed');
        }
    }
}

sqlsrv_commit($conn);

header('Location: view-applications.php?tab=walkin&walkin_added=1');
exit;