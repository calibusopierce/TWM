<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// ── Helper: safe DB error logger (never exposes detail to user) ────────────
function db_fail(string $context): void {
    $errors = sqlsrv_errors();
    error_log('[DB ERROR] ' . $context . ' — ' . print_r($errors, true));
}

// ── Debug helper: show SQL errors on screen if ?debug_sql=1 ────────────────
$debugSql = false;

$careerId = 0;
if (!empty($_POST['career_id'])) {
    $careerId = (int)$_POST['career_id'];
} elseif (!empty($_GET['id'])) {
    $careerId = (int)$_GET['id'];
}

if ($careerId <= 0) {
    header('Location: careers.php');
    exit();
}

$defaultPosition = '';
$careerDeptId = null;
$cSql = "
    SELECT c.JobTitle, d.DepartmentID 
    FROM Careers c
    LEFT JOIN Departments d ON d.DepartmentName = c.Department
    WHERE c.CareerID = ?
";
$cStmt = sqlsrv_query($conn, $cSql, [$careerId]);
if ($cStmt === false) { die(print_r(sqlsrv_errors(), true)); }
$cRow = sqlsrv_fetch_array($cStmt, SQLSRV_FETCH_ASSOC);
if ($cRow) {
    $defaultPosition = $cRow['JobTitle'];
    $careerDeptId = $cRow['DepartmentID'];
} else {
    header('Location: careers.php');
    exit();
}
sqlsrv_free_stmt($cStmt);

// ── Fetch active file categories ──────────────────────────────────────────
$fileCategories = [];
$fcSql  = "SELECT FileCategoryID, CategoryName FROM FileCategories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName";
$fcStmt = sqlsrv_query($conn, $fcSql);
if ($fcStmt) {
    while ($fcRow = sqlsrv_fetch_array($fcStmt, SQLSRV_FETCH_ASSOC)) {
        $fileCategories[] = $fcRow;
    }
    sqlsrv_free_stmt($fcStmt);
}
$validCategoryIds = array_column($fileCategories, 'FileCategoryID');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Handle POST submission ────────────────────────────────────────────────

$errorMessage = '';
$uploadedFiles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errorMessage = "Invalid form submission. Please refresh the page and try again.";
    }

    // Terms & Conditions
    $tncAccepted = isset($_POST['tnc_accepted']) && $_POST['tnc_accepted'] === '1';
    if (empty($errorMessage) && !$tncAccepted) {
        $errorMessage = "You must accept the Terms and Conditions to submit your application.";
    }

    // Form fields
    $fullname = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? $defaultPosition);

    if (empty($errorMessage)) {
        if ($fullname === '' || $email === '' || $position === '') {
            $errorMessage = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";
        } elseif (empty($_FILES['files']['name'][0])) {
            $errorMessage = "Please upload at least one document.";
        }
    }

       // ─────────────────────────────────────────────────────────────────────
    // 1. PRE-VALIDATE ALL FILES (no DB or disk writes yet)
    // ─────────────────────────────────────────────────────────────────────
    $fileInfos = [];
    if (empty($errorMessage)) {
        $rawFiles   = $_FILES['files'];
        $rawCats    = $_POST['category_id'] ?? [];

        // Normalize: strip empty/no-file slots into clean 0-based arrays
        $files = ['name'=>[], 'tmp_name'=>[], 'size'=>[], 'error'=>[], 'category_id'=>[]];
        foreach ($rawFiles['name'] as $i => $filename) {
            if (empty($filename) || ($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files['name'][]        = $filename;
            $files['tmp_name'][]    = $rawFiles['tmp_name'][$i];
            $files['size'][]        = $rawFiles['size'][$i];
            $files['error'][]       = $rawFiles['error'][$i];
            $files['category_id'][] = (int)($rawCats[$i] ?? 0);
        }

        if ($debugSql) {
            error_log("FILES: " . print_r($files['name'], true));
            error_log("CATEGORIES: " . print_r($files['category_id'], true));
        }

        foreach ($files['name'] as $i => $filename) {
            $categoryId = $files['category_id'][$i];
            if ($categoryId <= 0) {
                $errorMessage = "File '$filename' has no category selected. Please choose a category.";
                break;
            }
            if (!in_array($categoryId, $validCategoryIds)) {
                $errorMessage = "Selected category for file '$filename' does not exist in the system.";
                break;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowedExts)) {
                $errorMessage = "File '$filename' has invalid type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.";
                break;
            }

            // Size limit (10 MB)
            if ($files['size'][$i] > 10 * 1024 * 1024) {
                $errorMessage = "File '$filename' exceeds 10 MB limit.";
                break;
            }

            // Upload error check
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $errorMessage = "File '$filename' upload failed (error code: {$files['error'][$i]}).";
                break;
            }

            // MIME validation for images (blocks malicious scripts)
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                if (!function_exists('finfo_open')) {
                    error_log("WARNING: finfo_open not available, MIME check skipped for $filename");
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo === false) {
                        error_log("WARNING: finfo_open failed for $filename, skipping MIME check");
                    } else {
                        $mime = finfo_file($finfo, $files['tmp_name'][$i]);


                        finfo_close($finfo);
                        $mime = strtolower(trim($mime));
                        // Allowed MIME types (including common aliases)
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/x-png'];
                        if (!in_array($mime, $allowedMimes)) {
                            $errorMessage = "File '$filename' is not a valid image (detected MIME: $mime). Only JPEG and PNG images are allowed.";
                            break;
                        }
                    }
                }
            }

            // All checks passed – store file info for later processing
            $fileInfos[] = [
                'name'        => $filename,
                'tmp'         => $files['tmp_name'][$i],
                'category_id' => $categoryId,
                'ext'         => $ext
            ];
        }

        if (empty($errorMessage) && count($fileInfos) === 0) {
            $errorMessage = "Please upload at least one valid document.";
        }
        if (empty($errorMessage) && count($fileInfos) > 10) {
            $errorMessage = "Maximum 10 files allowed.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. ATOMIC DATABASE TRANSACTION
    // ─────────────────────────────────────────────────────────────────────
    if (empty($errorMessage)) {
        date_default_timezone_set('Asia/Manila');
        $submittedAt = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    if (sqlsrv_begin_transaction($conn) === false) {
            $errorMessage = "System error – could not start transaction.<br><pre>" . htmlspecialchars(print_r(sqlsrv_errors(), true)) . "</pre>";
        }


       if (empty($errorMessage)) {
            $insertAppSql = "
                INSERT INTO JobApplications 
                    (Fullname, Email, Phone, Position, DateApplied, Status, DepartmentID, TnCAccepted, TnCAcceptedAt)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);
                SELECT SCOPE_IDENTITY() AS ApplicationID;
            ";
            $params = [
                $fullname,
                $email,
                $phone,
                $position,
                $submittedAt,
                0,
                $careerDeptId,
                1,
                $submittedAt
            ];
            $stmt = sqlsrv_query($conn, $insertAppSql, $params);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                $errorMessage = "System error – could not save application.<br><pre>" . htmlspecialchars(print_r(sqlsrv_errors(), true)) . "</pre>";
            } else {
                sqlsrv_next_result($stmt); // advance to SELECT result
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $applicationID = (int)($row['ApplicationID'] ?? 0);
                sqlsrv_free_stmt($stmt);
                if (!$applicationID) {
                    sqlsrv_rollback($conn);
                    $errorMessage = "System error – failed to retrieve application ID.";
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. CREATE UPLOAD DIRECTORY
    // ─────────────────────────────────────────────────────────────────────
    if (empty($errorMessage)) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/uploads/job-applications/' . $applicationID . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            sqlsrv_rollback($conn);
            $errorMessage = "System error – cannot create upload folder.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. INSERT FILE RECORDS + MOVE FILES
    // ─────────────────────────────────────────────────────────────────────
    if (empty($errorMessage)) {
          error_log("fileInfos count: " . count($fileInfos));
          error_log("applicationID: " . $applicationID);
          error_log("uploadDir: " . $uploadDir);
          foreach ($fileInfos as $file) {
            error_log("Processing: " . $file['name'] . " | cat: " . $file['category_id'] . " | ext: " . $file['ext'] . " | tmp: " . $file['tmp']);
            $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $fileNameSafe = $safeBase . '_' . uniqid() . '.' . $file['ext'];
            $relativePath = '/TWM/uploads/job-applications/' . $applicationID . '/' . $fileNameSafe;
            $destPath = $uploadDir . $fileNameSafe;

            $fileTypeMap = [
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png'
            ];
            $fileType = $fileTypeMap[$file['ext']] ?? 'application/octet-stream';


          //debuging note
          error_log("Processing file: " . $file['name']);
          error_log("  Category ID: " . $file['category_id']);
          error_log("  Extension: " . $file['ext']);

            // Insert DB record FIRST
            $insertFileSql = "
                INSERT INTO ApplicationFiles
                    (ApplicationID, FileName, FilePath, FileType, UploadedAt, FileCategoryID)
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $fileParams = [
                            $applicationID,
                            $fileNameSafe,
                            $relativePath,
                            $fileType,
                            $submittedAt,
                            $file['category_id']
                        ];

            $fstmt = sqlsrv_query($conn, $insertFileSql, $fileParams);
            if ($fstmt === false) {
                sqlsrv_rollback($conn);
                $errorMessage = "System error – file record could not be saved.";
                break;
            }
            sqlsrv_free_stmt($fstmt);

            // Now move the uploaded file
            if (!move_uploaded_file($file['tmp'], $destPath)) {
                $deleteSql = "DELETE FROM ApplicationFiles WHERE ApplicationID = ? AND FileName = ?";
                $delStmt = sqlsrv_query($conn, $deleteSql, [$applicationID, $fileNameSafe]);
                sqlsrv_rollback($conn);
                $errorMessage = "System error – file could not be moved: " . $destPath . " | tmp: " . $file['tmp'];
                break;
            }

            // Get category name for email
            $catName = 'Unknown';
            foreach ($fileCategories as $fc) {
                if ($fc['FileCategoryID'] == $file['category_id']) {
                    $catName = $fc['CategoryName'];
                    break;
                }
            }
            $uploadedFiles[] = ['name' => $file['name'], 'category' => $catName];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. COMMIT OR ROLLBACK & CLEANUP
    // ─────────────────────────────────────────────────────────────────────
    if (empty($errorMessage)) {
        sqlsrv_commit($conn);
    } else {
        if (isset($uploadDir) && is_dir($uploadDir)) {
            array_map('unlink', glob($uploadDir . '*'));
            rmdir($uploadDir);
        }
        sqlsrv_rollback($conn);
    }


    // ─────────────────────────────────────────────────────────────────────
    // 6. SEND CONFIRMATION EMAIL (only if no error)
    // ─────────────────────────────────────────────────────────────────────
    if (empty($errorMessage)) {
        date_default_timezone_set('Asia/Manila');
        $appliedAtFormatted = date('F j, Y \a\t g:i A', strtotime($submittedAt));

        $safeFullname = htmlspecialchars($fullname, ENT_QUOTES);
        $safePosition = htmlspecialchars($position, ENT_QUOTES);
        $fromName     = 'Urban Tradewell Corporation';
        $host         = $_SERVER['HTTP_HOST'] ?? 'example.com';
        $fromEmail    = 'no-reply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);

        // Build file rows
        $fileRows = '';
        foreach ($uploadedFiles as $f) {
            $fileRows .= "
            <tr>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;'>" . htmlspecialchars($f['category']) . "</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;'>" . htmlspecialchars($f['name']) . "</td>
            </tr>";
        }

        $subject = "Application Received — {$safePosition}";

        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$safeFullname}</strong>,</p>
            <p style='margin:0 0 12px;'>Thank you for applying for the position of <strong>{$safePosition}</strong>. We have received your application on <strong>{$appliedAtFormatted}</strong>.</p>
            <p style='margin:0 0 12px;'>The following document(s) were submitted:</p>
            <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:16px;'>
                <thead>
                    <tr>
                        <th style='padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;text-align:left;font-size:13px;'>Category</th>
                        <th style='padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;text-align:left;font-size:13px;'>File</th>
                    </tr>
                </thead>
                <tbody>{$fileRows}</tbody>
            </table>
            <div style='background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 4px;font-weight:700;color:#0369a1;'>What happens next?</p>
                <p style='margin:0;color:#555;font-size:13px;'>Your application is currently being reviewed by our HR team. We will reach out to you via email once your application has been evaluated.</p>
            </div>
            <p style='margin:0;color:#555;font-size:13px;'>If you have any questions, feel free to contact us at <a href='mailto:hr.tradewell@gmail.com' style='color:#1e3799;'>hr.tradewell@gmail.com</a> or call <strong>(042) 719-1306</strong>.</p>";

        $currentYear = date('Y');
        $htmlMessage = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" bgcolor="#f4f4f6">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;">
        <tr><td style="background:#1e3799;color:#fff;padding:0;">
          <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td width="40" style="padding:20px 0 20px 30px;">
                <img src="http://122.52.195.3/tradewellportal/img/utc.png" width="40" height="30" style="display:block;" alt="UTC">
               </td>
              <td align="center" style="padding:20px 0;">
                <h1 style="margin:0;font-size:20px;">Urban Tradewell Corporation</h1>
                <div style="font-size:14px;opacity:.9;">Job Application Confirmation</div>
               </td>
              <td width="40" style="padding:20px 30px 20px 0;">&nbsp;</td>
            </tr>
          </table>
        </td></tr>
        <tr><td style="padding:25px 30px;color:#333;">
          {$bodyContent}
          <div style="text-align:center;margin-top:20px;">
            <a href="https://www.facebook.com/UrbanTradewellCorp" style="background:#1e3799;color:#fff;padding:12px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Contact HR</a>
          </div>
        </td></tr>
        <tr><td style="background:#f1f3f5;padding:15px 30px;color:#666;font-size:13px;text-align:center;">
          Urban Tradewell Corporation &copy; {$currentYear}<br>
          Sta. Monica Street Lourdes Subdivision, Phase 2 Ibabang Iyam, Lucena City, Quezon 4301<br>
          <a href="mailto:hr.tradewell@gmail.com" style="color:#1e3799;text-decoration:none;">hr_tradewell@yahoo.com, hr.tradewell@gmail.com</a> | (042) 719-1306
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: hr.tradewell@gmail.com\r\n";

        $mailSent = mail($email, $subject, $htmlMessage, $headers);
        if (!$mailSent) {
            error_log("[MAIL ERROR] Failed to send confirmation to: $email, ApplicationID: $applicationID");
        }

        // Success
        $_SESSION['successMessage'] = "Your application has been submitted successfully!";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: careers.php');
        exit();
    }
}

// ── DEBUG MODE (temporary) ────────────────────────────────
if (isset($_GET['debug'])) {
    echo '<pre>';
    var_dump([
        'GET' => $_GET,
        'POST' => $_POST,
        'careerId' => $careerId,
        'defaultPosition' => $defaultPosition
    ]);
    echo '</pre>';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Tradewell Application Form</title>

  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/img/apple-touch-icon.png') ?>" rel="apple-touch-icon">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/aos/aos.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/glightbox/css/glightbox.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/swiper/swiper-bundle.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/main.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/styles.css') ?>" rel="stylesheet">

  <style>
    /* ── T&C Modal ─────────────────────────────────────────── */
    .tnc-backdrop {
      position: fixed; inset: 0; z-index: 9999;
      background: rgba(0,0,0,.65);
      display: flex; align-items: center; justify-content: center;
      padding: 1rem;
    }
    .tnc-modal {
      background: #fff; border-radius: 16px;
      max-width: 680px; width: 100%;
      max-height: 90vh; display: flex; flex-direction: column;
      overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.3);
    }
    .tnc-header {
      padding: 1.25rem 1.5rem;
      border-bottom: 1px solid #e2e8f0;
      background: #1e3799; color: #fff;
      display: flex; align-items: center; gap: .75rem;
    }
    .tnc-header h5 { margin: 0; font-size: 1rem; font-weight: 700; color: #fff; }
    .tnc-header p  { margin: .2rem 0 0; font-size: .78rem; opacity: .85; color: #fff; }
    .tnc-header i  { color: #fff; }
    .tnc-body {
      padding: 1.5rem; overflow-y: auto; flex: 1;
      font-size: .85rem; line-height: 1.75; color: #334155;
    }
    .tnc-body h6 { font-weight: 700; color: #1e3799; margin: 1.1rem 0 .4rem; font-size: .875rem; }
    .tnc-body p  { margin: 0 0 .75rem; }
    .tnc-body ul { padding-left: 1.25rem; margin: 0 0 .75rem; }
    .tnc-body ul li { margin-bottom: .35rem; }
    .tnc-footer {
      padding: 1rem 1.5rem;
      border-top: 1px solid #e2e8f0;
      background: #f8fafc;
      display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    }
    .tnc-check-label {
      display: flex; align-items: flex-start; gap: .5rem;
      font-size: .82rem; color: #475569; cursor: pointer; flex: 1;
    }
    .tnc-check-label input { margin-top: .15rem; flex-shrink: 0; accent-color: #1e3799; }
    .btn-tnc-agree {
      background: #1e3799; color: #fff; border: none;
      padding: .55rem 1.5rem; border-radius: 8px;
      font-weight: 600; font-size: .85rem; cursor: pointer;
      transition: background .15s; white-space: nowrap;
    }
    .btn-tnc-agree:hover  { background: #1e40af; }
    .btn-tnc-agree:disabled { background: #94a3b8; cursor: not-allowed; }
    .btn-tnc-decline {
      background: transparent; color: #64748b;
      border: 1px solid #cbd5e1;
      padding: .55rem 1.2rem; border-radius: 8px;
      font-size: .82rem; cursor: pointer; transition: all .15s;
    }
    .btn-tnc-decline:hover { background: #fee2e2; color: #dc2626; border-color: #fca5a5; }

    /* ── File Upload Slots ──────────────────────────────────── */
    .file-slots-wrap { display: flex; flex-direction: column; gap: .75rem; }
    .file-slot {
      display: grid;
      grid-template-columns: 200px 1fr auto;
      gap: .6rem; align-items: center;
      background: #f8fafc;
      border: 1.5px dashed #cbd5e1;
      border-radius: 10px;
      padding: .65rem .85rem;
      transition: border-color .15s;
    }
    .file-slot:focus-within { border-color: #3b82f6; background: #eff6ff; }
    .file-slot select {
      font-size: .82rem; border: 1px solid #cbd5e1;
      border-radius: 7px; padding: .35rem .55rem;
      background: #fff; color: #334155;
      width: 100%;
    }
    .file-slot input[type="file"] {
      font-size: .78rem; color: #475569;
      cursor: pointer; width: 100%;
    }
    .btn-remove-slot {
      background: none; border: none; cursor: pointer;
      color: #94a3b8; font-size: 1rem; padding: .2rem .3rem;
      border-radius: 6px; transition: color .12s, background .12s;
      flex-shrink: 0;
    }
    .btn-remove-slot:hover { color: #ef4444; background: #fee2e2; }
    .btn-add-slot {
      display: inline-flex; align-items: center; gap: .4rem;
      background: #eff6ff; color: #1d4ed8;
      border: 1.5px dashed #93c5fd;
      padding: .5rem 1rem; border-radius: 8px;
      font-size: .82rem; font-weight: 600;
      cursor: pointer; transition: all .15s; margin-top: .25rem;
    }
    .btn-add-slot:hover           { background: #dbeafe; border-color: #60a5fa; }
    .btn-add-slot:disabled        { opacity: .45; cursor: not-allowed; }
    .slot-hint { font-size: .72rem; color: #94a3b8; margin-top: .2rem; }

    /* ── Form page hidden until T&C accepted ────────────────── */
    #formPage         { display: none; }
    #formPage.visible { display: block; }
  </style>
</head>

<body class="service-details-page">

<!-- ══════════════════════════════════════════════════════════
     T&C MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="tnc-backdrop" id="tncBackdrop">
  <div class="tnc-modal" role="dialog" aria-modal="true" aria-labelledby="tncTitle">

    <div class="tnc-header">
      <i class="bi bi-shield-lock-fill" style="font-size:1.4rem;flex-shrink:0;"></i>
      <div>
        <h5 id="tncTitle">Data Privacy Notice &amp; Terms and Conditions</h5>
        <p>Urban Tradewell Corporation — Job Application</p>
      </div>
    </div>

    <div class="tnc-body">

      <p>
        In compliance with <strong>Republic Act No. 10173</strong>, otherwise known as the
        <strong>Data Privacy Act of 2012</strong> of the Philippines and its Implementing Rules
        and Regulations, <strong>Urban Tradewell Corporation</strong> (hereinafter referred to
        as "the Company") is committed to protecting and respecting your personal data.
      </p>

      <h6>1. Purpose of Data Collection</h6>
      <p>The personal information you provide through this application form will be collected
         and processed solely for the purpose of evaluating your eligibility for employment with
         Urban Tradewell Corporation. This includes, but is not limited to:</p>
      <ul>
        <li>Reviewing your qualifications, work experience, and submitted documents</li>
        <li>Contacting you regarding the status of your application</li>
        <li>Scheduling interviews and related recruitment activities</li>
        <li>Storing your application record for future reference or consideration</li>
      </ul>

      <h6>2. Data Collected</h6>
      <p>The Company may collect the following personal data: full name, email address,
         phone number, position applied for, and any documents you voluntarily submit
         (e.g. resume, clearances, identification, certificates, and other
         pre-employment requirements).</p>

      <h6>3. Limitation of Liability</h6>
      <p><strong>Urban Tradewell Corporation shall not be held liable</strong> for any
         unauthorized access, disclosure, or misuse of personal data that results from
         circumstances beyond the Company's reasonable control, including but not limited to
         third-party security breaches, force majeure events, or actions taken by the
         applicant themselves. By submitting this form, you acknowledge that you are
         voluntarily providing your personal information and assume responsibility for the
         accuracy and completeness of the data submitted.</p>

      <h6>4. Data Retention</h6>
      <p>Your personal data will be retained for a period reasonably necessary to fulfill
         the purposes stated herein, or as required by applicable law. Applications of
         candidates not selected may be retained for a reasonable period for future
         consideration, after which the data will be securely disposed of.</p>

      <h6>5. Your Rights</h6>
      <p>As a data subject under RA 10173, you have the right to:</p>
      <ul>
        <li>Be informed of how your data is collected and used</li>
        <li>Access the personal data we hold about you</li>
        <li>Request correction of inaccurate or incomplete data</li>
        <li>Object to the processing of your personal data</li>
        <li>Request erasure or blocking of your data under lawful grounds</li>
      </ul>
      <p>To exercise any of these rights, you may contact the Company's HR Department at
         <a href="mailto:hr.tradewell@gmail.com">hr.tradewell@gmail.com</a>
         or call <strong>(042) 719-1306</strong>.</p>

      <h6>6. Consent</h6>
      <p>By checking the box below and clicking <strong>"I Agree &amp; Proceed"</strong>,
         you voluntarily give your informed consent to Urban Tradewell Corporation to collect,
         store, use, and process your personal data for the recruitment purposes described in
         this notice. If you do not agree, please click <strong>"Decline"</strong> and you
         will be redirected back to the job listings page.</p>

    </div>

    <div class="tnc-footer">
      <label class="tnc-check-label">
        <input type="checkbox" id="tncCheckbox" onchange="toggleAgreeBtn()">
        I have read and understood the Data Privacy Notice above, and I voluntarily give my
        consent to Urban Tradewell Corporation to collect and process my personal data for
        recruitment purposes.
      </label>
      <div style="display:flex;gap:.5rem;flex-shrink:0;">
        <button type="button" class="btn-tnc-decline" onclick="declineTnc()">
          <i class="bi bi-x-lg"></i> Decline
        </button>
        <button type="button" class="btn-tnc-agree" id="btnAgree"
                disabled onclick="acceptTnc()">
          <i class="bi bi-check2-circle"></i> I Agree &amp; Proceed
        </button>
      </div>
    </div>

  </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     FORM PAGE
     ══════════════════════════════════════════════════════════ -->
<div id="formPage">

  <header id="header" class="header">
    <div class="topbar d-flex align-items-center">
      <div class="container d-flex justify-content-center justify-content-md-between">
        <div class="contact-info d-flex align-items-center">
          <i class="bi bi-envelope d-flex align-items-center ms-4">
            <span>hr_tradewell@yahoo.com | hr.tradewell@gmail.com</span>
          </i>
          <i class="bi bi-telephone-fill d-flex align-items-center ms-4">
            <span>(042)719-1306</span>
          </i>
          <i class="bi bi-messenger d-flex align-items-center ms-4">
            <span>UrbanTradewellCorp</span>
          </i>
        </div>
      </div>
    </div>
  </header>

  <main class="main">

    <div class="page-hero">
      <div class="hero-inner">
        <div>
          <div class="page-hero-title">Job Application</div>
          <div class="page-hero-sub">
            <i class="bi bi-send-fill"></i>
            <?= !empty($defaultPosition)
                ? 'Applying for: ' . htmlspecialchars($defaultPosition)
                : 'Urban Tradewell Corporation' ?>
          </div>
        </div>
        <nav class="breadcrumb-nav">
          <a href="careers.php"><i class="bi bi-briefcase"></i> Careers</a>
          <span class="sep">/</span>
          <span class="current">Apply</span>
        </nav>
      </div>
    </div>

    <div class="form-main">

      <a href="javascript:history.back()" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Job Details
      </a>

      <div class="form-card">
        <div class="form-card-header">
          <h2>
            <i class="bi bi-person-fill" style="margin-right:.5rem;opacity:.8;"></i>
            Job Application Form
          </h2>
          <p>Fill out the form below to apply at Urban Tradewell Corporation.</p>
        </div>

        <div class="form-card-body">

          <?php if (!empty($errorMessage)): ?>
            <div class="alert-error">
              <i class="bi bi-exclamation-triangle-fill"></i>
              <?= htmlspecialchars($errorMessage) ?>
            </div>
          <?php endif; ?>

          <form action="job-application.php?id=<?= (int)$careerId ?>" method="POST" enctype="multipart/form-data" id="appForm">

            <input type="hidden" name="career_id" value="<?= (int)$careerId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="tnc_accepted" id="tncHidden" value="0">

            <div class="field-group">
              <label class="field-label">Full Name <span class="req">*</span></label>
              <input type="text" name="name" class="field-input" placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="two-col">
              <div class="field-group">
                <label class="field-label">Email Address <span class="req">*</span></label>
                <input type="email" name="email" class="field-input" placeholder="you@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
              </div>
              <div class="field-group">
                <label class="field-label">Phone Number</label>
                <input type="text" name="phone" class="field-input" placeholder="e.g. 09XX XXX XXXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label">Position Applied For <span class="req">*</span></label>
              <input type="text" class="field-input" value="<?= htmlspecialchars($defaultPosition) ?>" readonly>
              <input type="hidden" name="position" value="<?= htmlspecialchars($defaultPosition) ?>">
              <div class="field-hint"><i class="bi bi-info-circle"></i> Selected job title.</div>
            </div>

            <div class="field-divider"></div>

            <div class="field-group">
              <label class="field-label">
                Documents <span class="req">*</span>
                <span style="font-weight:400;font-size:.75rem;color:#64748b;margin-left:.35rem;">— select a category then attach the file. Click + to add more (max 10).</span>
              </label>

              <div class="file-slots-wrap" id="fileSlotsWrap">
                <div class="file-slot">
                  <select name="category_id[]" required>
                    <option value="">— Select Category —</option>
                    <?php foreach ($fileCategories as $fc): ?>
                      <option value="<?= (int)$fc['FileCategoryID'] ?>"><?= htmlspecialchars($fc['CategoryName']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="file" name="files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                  <button type="button" class="btn-remove-slot" onclick="removeSlot(this)" title="Remove this file"><i class="bi bi-x-lg"></i></button>
                </div>
              </div>

              <button type="button" class="btn-add-slot" id="btnAddSlot" onclick="addSlot()"><i class="bi bi-plus-lg"></i> Add Another Document</button>
              <div class="slot-hint">PDF, DOC, DOCX · Max 10 MB per file · Max 10 files</div>
            </div>

            <div style="margin-top:1.5rem;">
              <button type="submit" class="btn-submit"><i class="bi bi-send-fill"></i> Submit Application</button>
            </div>

          </form>
        </div>
      </div>

    </div>

  </main>

  <footer id="footer" class="footer">
    <div class="container footer-top">
      <div class="row gy-4">
        <div class="col-lg-4 col-md-6 footer-about">
          <a href="careers.php" class="d-flex align-items-center"><span class="sitename">Urban Tradewell Corporation</span></a>
          <div class="footer-contact pt-3">
            <p>Sta. Monica Street Lourdes Subdivision</p>
            <p>Phase 2 Ibabang Iyam, Lucena City 4301</p>
            <p class="mt-3"><strong>Phone:</strong> <span>(042) 719-1306</span></p>
            <p><strong>Email:</strong> <span>hr_tradewell@yahoo.com</span></p>
          </div>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Useful Links</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="tradewell.php">Home</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">About us</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="careers.php">Careers</a></li>
          </ul>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Departments</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Monde Nissin Corporation</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Century Pacific Food Inc.</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Nutriasia</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Multilines</a></li>
          </ul>
        </div>
        <div class="col-lg-4 col-md-12">
          <h4>Contact Us</h4>
          <p>For more information, feel free to contact us through the following:</p>
          <div class="social-links d-flex">
            <a href="https://www.facebook.com/UrbanTradewellCorp"><i class="bi bi-facebook"></i></a>
          </div>
        </div>
      </div>
    </div>
    <div class="container copyright text-center mt-4">
      <p>© <strong>Copyright</strong> <strong class="px-1 sitename">Urban Tradewell Corporation</strong> <span>All Rights Reserved.</span></p>
    </div>
  </footer>

  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

</div><!-- /formPage -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/aos/aos.js') ?>"></script>
<script src="<?= base_url('assets/vendor/glightbox/js/glightbox.min.js') ?>"></script>
<script src="<?= base_url('assets/vendor/swiper/swiper-bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/js/main.js') ?>"></script>

<script>
const CATEGORIES_HTML = `<?php
    $opts = '<option value="">— Select Category —</option>';
    foreach ($fileCategories as $fc) {
        $opts .= '<option value="' . (int)$fc['FileCategoryID'] . '">' . htmlspecialchars($fc['CategoryName'], ENT_QUOTES) . '</option>';
    }
    echo addslashes($opts);
?>`;
const MAX_SLOTS = 10;

function toggleAgreeBtn() {
    document.getElementById('btnAgree').disabled = !document.getElementById('tncCheckbox').checked;
}
function acceptTnc() {
    if (!document.getElementById('tncCheckbox').checked) return;
    document.getElementById('tncBackdrop').style.display = 'none';
    document.getElementById('formPage').classList.add('visible');
    document.getElementById('tncHidden').value = '1';
    sessionStorage.setItem('tnc_accepted_global', '1');
}
function declineTnc() {
    window.location.href = 'careers.php';
}
if (sessionStorage.getItem('tnc_accepted_global') === '1') {
    document.getElementById('tncBackdrop').style.display = 'none';
    document.getElementById('formPage').classList.add('visible');
    document.getElementById('tncHidden').value = '1';
}
<?php if (!empty($errorMessage)): ?>
document.getElementById('tncBackdrop').style.display = 'none';
document.getElementById('formPage').classList.add('visible');
document.getElementById('tncHidden').value = '1';
<?php endif; ?>

function updateAddButton() {
    const count = document.querySelectorAll('.file-slot').length;
    const btn = document.getElementById('btnAddSlot');
    btn.disabled = count >= MAX_SLOTS;
    btn.title = btn.disabled ? 'Maximum of ' + MAX_SLOTS + ' files allowed' : '';
}
function addSlot() {
    const wrap = document.getElementById('fileSlotsWrap');
    if (wrap.querySelectorAll('.file-slot').length >= MAX_SLOTS) return;
    const div = document.createElement('div');
    div.className = 'file-slot';
    // Corrected HTML with proper button and onclick
    div.innerHTML = `<select name="category_id[]" required>${CATEGORIES_HTML}</select>
                     <input type="file" name="files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                     <button type="button" class="btn-remove-slot" onclick="removeSlot(this)" title="Remove this file"><i class="bi bi-x-lg"></i></button>`;
    wrap.appendChild(div);
    updateAddButton();
}
function removeSlot(btn) {
    const wrap = document.getElementById('fileSlotsWrap');
    const slots = wrap.querySelectorAll('.file-slot');
    if (slots.length <= 1) {
        // Reset the only slot instead of removing
        const slot = btn.closest('.file-slot');
        if (slot) {
            slot.querySelector('select').value = '';
            slot.querySelector('input[type="file"]').value = '';
        }
        return;
    }
    btn.closest('.file-slot').remove();
    updateAddButton();
}

// No duplicate category check because we allow same category for multiple files
// Just ensure at least one file is selected before submit
document.getElementById('appForm').addEventListener('submit', function (e) {
    const files = document.querySelectorAll('input[type="file"]');
    let hasFile = false;
    files.forEach(f => { if (f.value) hasFile = true; });
    if (!hasFile) {
        e.preventDefault();
        alert('Please upload at least one document.');
        return;
    }
});

updateAddButton();
</script>

</body>
</html>