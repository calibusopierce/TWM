<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

function db_fail(string $context): void {
    $errors = sqlsrv_errors();
    error_log('[DB ERROR] ' . $context . ' — ' . print_r($errors, true));
}

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

$errorMessage = '';
$uploadedFiles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errorMessage = "Invalid form submission. Please refresh the page and try again.";
    }

    $tncAccepted = isset($_POST['tnc_accepted']) && $_POST['tnc_accepted'] === '1';
    if (empty($errorMessage) && !$tncAccepted) {
        $errorMessage = "You must accept the Terms and Conditions to submit your application.";
    }

    $sp = fn(string $k) => trim($_POST[$k] ?? '');

    $firstName   = $sp('first_name');
    $lastName    = $sp('last_name');
    $middleName  = $sp('middle_name');
    $fullname    = trim("$firstName $middleName $lastName");
    $email       = $sp('email');
    $phone       = $sp('phone');
    $mobile      = $sp('mobile');
    $position    = $sp('position') ?: $defaultPosition;
    $birthDate   = $sp('birth_date');
    $birthPlace  = $sp('birth_place');
    $gender      = $sp('gender');
    $civilStatus = $sp('civil_status');
    $nationality = $sp('nationality');
    $religion    = $sp('religion');
    $presentAddress   = $sp('present_address');
    $permanentAddress = $sp('permanent_address');
    $sssNumber        = $sp('sss_number');
    $tinNumber        = $sp('tin_number');
    $philhealthNumber = $sp('philhealth_number');
    $hdmf             = $sp('hdmf');
    $educationalBackground = $sp('educational_background');

    if (empty($errorMessage)) {
        if ($firstName === '' || $lastName === '' || $email === '' || $position === '') {
            $errorMessage = "Please fill in all required fields (First Name, Last Name, Email, Position).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";
        } elseif (empty($_FILES['files']['name'][0])) {
            $errorMessage = "Please upload at least one document.";
        }
    }

    $fileInfos = [];
    if (empty($errorMessage)) {
        $rawFiles = $_FILES['files'];
        $rawCats  = $_POST['category_id'] ?? [];

        $files = ['name'=>[], 'tmp_name'=>[], 'size'=>[], 'error'=>[], 'category_id'=>[]];
        foreach ($rawFiles['name'] as $i => $filename) {
            if (empty($filename) || ($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files['name'][]        = $filename;
            $files['tmp_name'][]    = $rawFiles['tmp_name'][$i];
            $files['size'][]        = $rawFiles['size'][$i];
            $files['error'][]       = $rawFiles['error'][$i];
            $files['category_id'][] = (int)($rawCats[$i] ?? 0);
        }

        foreach ($files['name'] as $i => $filename) {
            $categoryId = $files['category_id'][$i];
            if ($categoryId <= 0) { $errorMessage = "File '$filename' has no category selected."; break; }
            if (!in_array($categoryId, $validCategoryIds)) { $errorMessage = "Selected category for '$filename' is invalid."; break; }
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            if (!in_array($ext, $allowedExts)) { $errorMessage = "File '$filename' has invalid type. Allowed: PDF, DOC, DOCX, JPG, PNG."; break; }
            if ($files['size'][$i] > 10 * 1024 * 1024) { $errorMessage = "File '$filename' exceeds 10 MB limit."; break; }
            if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errorMessage = "File '$filename' upload failed (error code: {$files['error'][$i]})."; break; }
            if (in_array($ext, ['jpg', 'jpeg', 'png']) && function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime = strtolower(trim(finfo_file($finfo, $files['tmp_name'][$i])));
                    finfo_close($finfo);
                    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/jpg', 'image/x-png'])) {
                        $errorMessage = "File '$filename' is not a valid image (MIME: $mime)."; break;
                    }
                }
            }
            $fileInfos[] = ['name' => $filename, 'tmp' => $files['tmp_name'][$i], 'category_id' => $categoryId, 'ext' => $ext];
        }

        if (empty($errorMessage) && count($fileInfos) === 0) $errorMessage = "Please upload at least one valid document.";
        if (empty($errorMessage) && count($fileInfos) > 10)  $errorMessage = "Maximum 10 files allowed.";
    }

    if (empty($errorMessage)) {
        date_default_timezone_set('Asia/Manila');
        $submittedAt = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

        if (sqlsrv_begin_transaction($conn) === false) {
            $errorMessage = "System error – could not start transaction.";
        }

        if (empty($errorMessage)) {
            $insertAppSql = "
                INSERT INTO JobApplications 
                    (Fullname, Email, Phone, Position, DateApplied, Status, DepartmentID, TnCAccepted, TnCAcceptedAt,
                     FirstName, MiddleName, LastName, Mobile_Number,
                     Birth_date, Birth_Place, Gender, Civil_Status, Nationality, Religion,
                     Present_Address, Permanent_Address,
                     SSS_Number, TIN_Number, Philhealth_Number, HDMF,
                     Contact_Person, Relationship, Contact_Number_Emergency,
                     Educational_Background, Notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?);
                SELECT SCOPE_IDENTITY() AS ApplicationID;
            ";
            $params = [
                $fullname, $email, $phone, $position, $submittedAt, 0, $careerDeptId, 1, $submittedAt,
                $firstName, $middleName, $lastName, $mobile,
                ($birthDate !== '' ? $birthDate : null), $birthPlace, $gender, $civilStatus, $nationality, $religion,
                $presentAddress, $permanentAddress,
                $sssNumber, $tinNumber, $philhealthNumber, $hdmf,
                null, null, null,
                $educationalBackground, null
            ];

            $stmt = sqlsrv_query($conn, $insertAppSql, $params);
            if ($stmt === false) {
                sqlsrv_rollback($conn);
                $errorMessage = "System error – could not save application.";
            } else {
                sqlsrv_next_result($stmt);
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

    if (empty($errorMessage)) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/uploads/job-applications/' . $applicationID . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            sqlsrv_rollback($conn);
            $errorMessage = "System error – cannot create upload folder.";
        }
    }

    if (empty($errorMessage)) {
        foreach ($fileInfos as $file) {
            $safeBase     = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $fileNameSafe = $safeBase . '_' . uniqid() . '.' . $file['ext'];
            $relativePath = '/TWM/uploads/job-applications/' . $applicationID . '/' . $fileNameSafe;
            $destPath     = $uploadDir . $fileNameSafe;
            $fileTypeMap  = ['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
            $fileType     = $fileTypeMap[$file['ext']] ?? 'application/octet-stream';

            $fstmt = sqlsrv_query($conn,
                "INSERT INTO ApplicationFiles (ApplicationID, FileName, FilePath, FileType, UploadedAt, FileCategoryID) VALUES (?, ?, ?, ?, ?, ?)",
                [$applicationID, $fileNameSafe, $relativePath, $fileType, $submittedAt, $file['category_id']]
            );
            if ($fstmt === false) { sqlsrv_rollback($conn); $errorMessage = "System error – file record could not be saved."; break; }
            sqlsrv_free_stmt($fstmt);

            if (!move_uploaded_file($file['tmp'], $destPath)) {
                sqlsrv_query($conn, "DELETE FROM ApplicationFiles WHERE ApplicationID = ? AND FileName = ?", [$applicationID, $fileNameSafe]);
                sqlsrv_rollback($conn);
                $errorMessage = "System error – file could not be saved."; break;
            }

            $catName = 'Unknown';
            foreach ($fileCategories as $fc) { if ($fc['FileCategoryID'] == $file['category_id']) { $catName = $fc['CategoryName']; break; } }
            $uploadedFiles[] = ['name' => $file['name'], 'category' => $catName];
        }
    }

    if (empty($errorMessage)) {
        sqlsrv_commit($conn);
    } else {
        if (isset($uploadDir) && is_dir($uploadDir)) { array_map('unlink', glob($uploadDir . '*')); rmdir($uploadDir); }
        sqlsrv_rollback($conn);
    }

    if (empty($errorMessage)) {
        date_default_timezone_set('Asia/Manila');
        $appliedAtFormatted = date('F j, Y \a\t g:i A', strtotime($submittedAt));
        $safeFullname = htmlspecialchars($fullname, ENT_QUOTES);
        $safePosition = htmlspecialchars($position, ENT_QUOTES);
        $fromName     = 'Urban Tradewell Corporation';
        $host         = $_SERVER['HTTP_HOST'] ?? 'example.com';
        $fromEmail    = 'no-reply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);

        $fileRows = '';
        foreach ($uploadedFiles as $f) {
            $fileRows .= "<tr>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;'>" . htmlspecialchars($f['category']) . "</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;'>" . htmlspecialchars($f['name']) . "</td>
            </tr>";
        }

        $subject     = "Application Received — {$safePosition}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$safeFullname}</strong>,</p>
            <p style='margin:0 0 12px;'>Thank you for applying for <strong>{$safePosition}</strong>. We have received your application on <strong>{$appliedAtFormatted}</strong>.</p>
            <p style='margin:0 0 12px;'>Documents submitted:</p>
            <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:16px;'>
                <thead><tr>
                    <th style='padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;text-align:left;font-size:13px;'>Category</th>
                    <th style='padding:8px 12px;border:1px solid #e2e8f0;background:#f8fafc;text-align:left;font-size:13px;'>File</th>
                </tr></thead>
                <tbody>{$fileRows}</tbody>
            </table>
            <div style='background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 4px;font-weight:700;color:#0369a1;'>What happens next?</p>
                <p style='margin:0;color:#555;font-size:13px;'>Your application is being reviewed by our HR team. We will reach out once evaluated.</p>
            </div>
            <p style='margin:0;color:#555;font-size:13px;'>Questions? Email <a href='mailto:hr.tradewell@gmail.com' style='color:#1e3799;'>hr.tradewell@gmail.com</a> or call <strong>(042) 719-1306</strong>.</p>";

        $currentYear = date('Y');
        $htmlMessage = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" bgcolor="#f4f4f6"><tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden;">
      <tr><td style="background:#1e3799;color:#fff;padding:0;">
        <table width="100%" cellpadding="0" cellspacing="0"><tr>
          <td width="40" style="padding:20px 0 20px 30px;"><img src="http://122.52.195.3/tradewellportal/img/utc.png" width="40" height="30" style="display:block;" alt="UTC"></td>
          <td align="center" style="padding:20px 0;"><h1 style="margin:0;font-size:20px;">Urban Tradewell Corporation</h1><div style="font-size:14px;opacity:.9;">Job Application Confirmation</div></td>
          <td width="40" style="padding:20px 30px 20px 0;">&nbsp;</td>
        </tr></table>
      </td></tr>
      <tr><td style="padding:25px 30px;color:#333;">{$bodyContent}
        <div style="text-align:center;margin-top:20px;"><a href="https://www.facebook.com/UrbanTradewellCorp" style="background:#1e3799;color:#fff;padding:12px 20px;text-decoration:none;border-radius:5px;display:inline-block;">Contact HR</a></div>
      </td></tr>
      <tr><td style="background:#f1f3f5;padding:15px 30px;color:#666;font-size:13px;text-align:center;">
        Urban Tradewell Corporation &copy; {$currentYear}<br>
        Sta. Monica Street Lourdes Subdivision, Phase 2 Ibabang Iyam, Lucena City, Quezon 4301<br>
        <a href="mailto:hr.tradewell@gmail.com" style="color:#1e3799;text-decoration:none;">hr_tradewell@yahoo.com, hr.tradewell@gmail.com</a> | (042) 719-1306
      </td></tr>
    </table>
  </td></tr></table>
</body></html>
HTML;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: hr.tradewell@gmail.com\r\n";

        $mailSent = mail($email, $subject, $htmlMessage, $headers);
        if (!$mailSent) error_log("[MAIL ERROR] Failed to send confirmation to: $email, ApplicationID: $applicationID");

        $_SESSION['successMessage'] = "Your application has been submitted successfully!";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: careers.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Job Application — Urban Tradewell Corporation</title>

  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/img/apple-touch-icon.png') ?>" rel="apple-touch-icon">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/main.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/styles.css') ?>" rel="stylesheet">

  <style>
    /* ── Root & Reset ─────────────────────────────────── */
    :root {
      --navy:      #0f2461;
      --navy-mid:  #1e3799;
      --navy-lite: #e8edf8;
      --accent:    #f5a623;
      --accent2:   #e84393;
      --green:     #10b981;
      --red:       #ef4444;
      --text:      #1a1f36;
      --muted:     #64748b;
      --border:    #dde3f0;
      --bg:        #f2f5fb;
      --white:     #ffffff;
      --radius:    14px;
      --shadow:    0 8px 32px rgba(15,36,97,.10);
      --shadow-sm: 0 2px 10px rgba(15,36,97,.07);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    /* ── T&C Modal ────────────────────────────────────── */
    .tnc-backdrop {
      position: fixed; inset: 0; z-index: 9999;
      background: rgba(10,20,60,.72);
      display: flex; align-items: center; justify-content: center; padding: 1rem;
      backdrop-filter: blur(4px);
    }
    .tnc-modal {
      background: var(--white); border-radius: 20px;
      max-width: 700px; width: 100%; max-height: 92vh;
      display: flex; flex-direction: column; overflow: hidden;
      box-shadow: 0 32px 80px rgba(10,20,60,.35);
    }
    .tnc-header {
      padding: 1.4rem 1.75rem;
      background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
      color: #fff; display: flex; align-items: center; gap: 1rem;
    }
    .tnc-header-icon {
      width: 44px; height: 44px; border-radius: 12px;
      background: rgba(255,255,255,.15);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.3rem; flex-shrink: 0;
    }
    .tnc-header h5 { margin: 0; font-size: 1.05rem; font-weight: 700; }
    .tnc-header p  { margin: .2rem 0 0; font-size: .78rem; opacity: .8; }
    .tnc-body {
      padding: 1.75rem; overflow-y: auto; flex: 1;
      font-size: .84rem; line-height: 1.8; color: #475569;
    }
    .tnc-body h6 {
      font-weight: 700; color: var(--navy);
      margin: 1.25rem 0 .45rem; font-size: .875rem;
      display: flex; align-items: center; gap: .4rem;
    }
    .tnc-body h6::before {
      content: ''; display: inline-block;
      width: 3px; height: 14px; background: var(--accent);
      border-radius: 2px;
    }
    .tnc-body p  { margin: 0 0 .6rem; }
    .tnc-body ul { padding-left: 1.4rem; margin: 0 0 .75rem; }
    .tnc-body ul li { margin-bottom: .35rem; }
    .tnc-footer {
      padding: 1.1rem 1.75rem;
      border-top: 1px solid var(--border);
      background: #f8fafc;
      display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
    }
    .tnc-check-label {
      display: flex; align-items: flex-start; gap: .55rem;
      font-size: .81rem; color: var(--text); cursor: pointer; flex: 1;
      font-weight: 500;
    }
    .tnc-check-label input { margin-top: .2rem; flex-shrink: 0; accent-color: var(--navy-mid); width:16px;height:16px; }
    .btn-tnc-agree {
      background: linear-gradient(135deg, var(--navy-mid), var(--navy));
      color: #fff; border: none; padding: .6rem 1.6rem;
      border-radius: 10px; font-weight: 700; font-size: .85rem;
      cursor: pointer; transition: all .2s; white-space: nowrap;
      box-shadow: 0 4px 14px rgba(30,55,153,.35);
    }
    .btn-tnc-agree:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(30,55,153,.4); }
    .btn-tnc-agree:disabled { background: #94a3b8; box-shadow: none; cursor: not-allowed; }
    .btn-tnc-decline {
      background: transparent; color: var(--muted);
      border: 1.5px solid var(--border); padding: .6rem 1.2rem;
      border-radius: 10px; font-size: .82rem; cursor: pointer; transition: all .15s;
    }
    .btn-tnc-decline:hover { background: #fee2e2; color: var(--red); border-color: #fca5a5; }

    /* ── Page Layout ──────────────────────────────────── */
    #formPage { display: none; }
    #formPage.visible { display: block; }

    .app-shell {
      min-height: 100vh;
      display: flex; flex-direction: column;
    }

    /* ── Top Bar ──────────────────────────────────────── */
    .topbar {
      background: linear-gradient(135deg, var(--navy) 0%, #162d8a 100%);
      color: #fff; padding: .55rem 0; font-size: .78rem;
    }
    .topbar .container { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
    .topbar i { margin-right: .3rem; opacity: .7; }
    .topbar span { opacity: .9; }

    /* ── Hero ─────────────────────────────────────────── */
    .app-hero {
      background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 60%, #2d4fc7 100%);
      color: #fff;
      padding: 2.25rem 0 1.5rem;
      position: relative;
      overflow: hidden;
    }
    .app-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .app-hero .container { position: relative; z-index: 1; }
    .hero-eyebrow {
      display: inline-flex; align-items: center; gap: .4rem;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 20px; padding: .28rem .85rem;
      font-size: .73rem; font-weight: 600; letter-spacing: .05em;
      text-transform: uppercase; margin-bottom: .75rem;
    }
    .hero-title {
      font-family: 'DM Serif Display', serif;
      font-size: clamp(1.6rem, 3vw, 2.2rem);
      line-height: 1.2; margin-bottom: .4rem;
    }
    .hero-sub {
      font-size: .88rem; opacity: .8;
      display: flex; align-items: center; gap: .4rem;
    }
    .hero-breadcrumb {
      margin-top: .75rem;
      display: flex; align-items: center; gap: .4rem;
      font-size: .75rem; opacity: .65;
    }
    .hero-breadcrumb a { color: #fff; text-decoration: none; }
    .hero-breadcrumb a:hover { opacity: 1; text-decoration: underline; }

    /* ── Wizard Container ─────────────────────────────── */
    .wizard-wrap {
      flex: 1;
      padding: 2rem 0 4rem;
    }
    .wizard-container {
      max-width: 820px; margin: 0 auto; padding: 0 1rem;
    }

    /* ── Step Progress Bar ────────────────────────────── */
    .step-progress {
      display: flex; align-items: flex-start;
      gap: 0; margin-bottom: 2rem;
      background: var(--white);
      border-radius: var(--radius);
      padding: 1.25rem 1.5rem;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--border);
      overflow-x: auto;
    }
    .step-item {
      display: flex; flex-direction: column; align-items: center;
      flex: 1; min-width: 60px; position: relative; cursor: pointer;
    }
    .step-item:not(:last-child)::after {
      content: '';
      position: absolute; top: 20px; left: calc(50% + 20px);
      width: calc(100% - 40px); height: 2px;
      background: var(--border);
      transition: background .4s;
      z-index: 0;
    }
    .step-item.done:not(:last-child)::after { background: var(--green); }
    .step-item.active:not(:last-child)::after { background: var(--border); }

    .step-bubble {
      width: 40px; height: 40px; border-radius: 50%;
      border: 2.5px solid var(--border);
      background: var(--white);
      display: flex; align-items: center; justify-content: center;
      font-size: .82rem; font-weight: 700; color: var(--muted);
      transition: all .3s; z-index: 1; position: relative;
      flex-shrink: 0;
    }
    .step-item.active .step-bubble {
      background: var(--navy-mid); border-color: var(--navy-mid);
      color: #fff; box-shadow: 0 0 0 4px rgba(30,55,153,.15);
    }
    .step-item.done .step-bubble {
      background: var(--green); border-color: var(--green); color: #fff;
    }
    .step-label {
      margin-top: .45rem; font-size: .68rem; font-weight: 600;
      color: var(--muted); text-align: center; line-height: 1.3;
      letter-spacing: .02em; text-transform: uppercase;
    }
    .step-item.active .step-label { color: var(--navy-mid); }
    .step-item.done .step-label   { color: var(--green); }

    /* ── Step Card ────────────────────────────────────── */
    .step-card {
      background: var(--white);
      border-radius: var(--radius);
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
      overflow: hidden;
      animation: slideIn .28s ease;
    }
    @keyframes slideIn {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .step-card-header {
      padding: 1.4rem 1.75rem 1.2rem;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(to right, #f8f9ff, var(--white));
      display: flex; align-items: center; gap: 1rem;
    }
    .step-card-icon {
      width: 46px; height: 46px; border-radius: 12px;
      background: var(--navy-lite);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; color: var(--navy-mid); flex-shrink: 0;
    }
    .step-card-header h3 {
      font-size: 1.1rem; font-weight: 700; color: var(--navy); margin-bottom: .15rem;
    }
    .step-card-header p { font-size: .8rem; color: var(--muted); margin: 0; }
    .step-badge {
      margin-left: auto; background: var(--navy-lite);
      color: var(--navy-mid); font-size: .7rem; font-weight: 700;
      padding: .25rem .65rem; border-radius: 20px; white-space: nowrap;
      text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0;
    }
    .step-card-body { padding: 1.75rem; }

    /* ── Form Fields ──────────────────────────────────── */
    .field-group { margin-bottom: 1.1rem; }
    .field-group:last-child { margin-bottom: 0; }
    .field-label {
      display: block; font-size: .75rem; font-weight: 700;
      color: var(--muted); margin-bottom: .35rem;
      text-transform: uppercase; letter-spacing: .05em;
    }
    .field-label .req { color: var(--red); margin-left: .15rem; }
    .field-input, .field-select, .field-textarea {
      width: 100%; padding: .65rem 1rem;
      border: 1.5px solid var(--border);
      border-radius: 10px; font-size: .875rem; color: var(--text);
      background: #fff; transition: border-color .15s, box-shadow .15s;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .field-input:focus, .field-select:focus, .field-textarea:focus {
      outline: none;
      border-color: var(--navy-mid);
      box-shadow: 0 0 0 3px rgba(30,55,153,.1);
    }
    .field-input.invalid, .field-select.invalid, .field-textarea.invalid {
      border-color: var(--red);
      box-shadow: 0 0 0 3px rgba(239,68,68,.1);
    }
    .field-input[readonly] { background: #f1f5f9; color: var(--muted); cursor: not-allowed; }
    .field-textarea { resize: vertical; min-height: 90px; }
    .field-hint { font-size: .72rem; color: #94a3b8; margin-top: .3rem; display: flex; align-items: center; gap: .25rem; }
    .field-error { font-size: .72rem; color: var(--red); margin-top: .3rem; display: none; }
    .field-error.show { display: block; }

    .two-col   { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; }
    @media (max-width: 600px) {
      .two-col, .three-col { grid-template-columns: 1fr; }
    }

    .section-divider {
      font-size: .7rem; font-weight: 700; color: var(--navy-mid);
      text-transform: uppercase; letter-spacing: .08em;
      display: flex; align-items: center; gap: .6rem;
      margin: 1.5rem 0 1rem;
    }
    .section-divider::before, .section-divider::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }

    /* ── File Upload Slots ────────────────────────────── */
    .file-slots-wrap { display: flex; flex-direction: column; gap: .7rem; }
    .file-slot {
      display: grid; grid-template-columns: 190px 1fr auto;
      gap: .65rem; align-items: center;
      background: #f8fafc; border: 1.5px dashed var(--border);
      border-radius: 10px; padding: .7rem .9rem;
      transition: border-color .15s;
    }
    .file-slot:focus-within { border-color: #3b82f6; background: #eff6ff; }
    .file-slot select { font-size: .82rem; border: 1.5px solid var(--border); border-radius: 8px; padding: .4rem .6rem; background: #fff; color: var(--text); width: 100%; font-family: inherit; }
    .file-slot select:focus { outline: none; border-color: var(--navy-mid); }
    .file-slot input[type="file"] { font-size: .78rem; color: var(--muted); cursor: pointer; width: 100%; }
    .btn-remove-slot {
      background: none; border: none; cursor: pointer;
      color: #94a3b8; font-size: 1rem; padding: .25rem .35rem;
      border-radius: 6px; transition: all .12s; flex-shrink: 0;
    }
    .btn-remove-slot:hover { color: var(--red); background: #fee2e2; }
    .btn-add-slot {
      display: inline-flex; align-items: center; gap: .4rem;
      background: var(--navy-lite); color: var(--navy-mid);
      border: 1.5px dashed #93c5fd; padding: .55rem 1.1rem;
      border-radius: 9px; font-size: .82rem; font-weight: 600;
      cursor: pointer; transition: all .15s; margin-top: .3rem;
    }
    .btn-add-slot:hover { background: #dbeafe; border-color: #60a5fa; }
    .btn-add-slot:disabled { opacity: .45; cursor: not-allowed; }
    .slot-hint { font-size: .72rem; color: #94a3b8; margin-top: .4rem; }
    @media (max-width: 600px) { .file-slot { grid-template-columns: 1fr; } }

    /* ── Alert ────────────────────────────────────────── */
    .alert-error {
      background: #fef2f2; border: 1px solid #fecaca;
      border-radius: 10px; padding: .9rem 1.1rem;
      font-size: .875rem; color: #dc2626;
      display: flex; align-items: flex-start; gap: .6rem;
      margin-bottom: 1.5rem;
    }
    .alert-error i { font-size: 1.1rem; flex-shrink: 0; margin-top: .1rem; }

    /* ── Navigation Buttons ───────────────────────────── */
    .wizard-nav {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 1.5rem; gap: 1rem;
    }
    .btn-prev {
      display: inline-flex; align-items: center; gap: .5rem;
      background: var(--white); color: var(--text);
      border: 1.5px solid var(--border); padding: .72rem 1.5rem;
      border-radius: 10px; font-size: .9rem; font-weight: 600;
      cursor: pointer; transition: all .18s; font-family: inherit;
    }
    .btn-prev:hover { background: #f1f5f9; border-color: #94a3b8; }
    .btn-prev:disabled { opacity: .4; cursor: not-allowed; }
    .btn-next {
      display: inline-flex; align-items: center; gap: .5rem;
      background: linear-gradient(135deg, var(--navy-mid), var(--navy));
      color: #fff; border: none; padding: .72rem 1.75rem;
      border-radius: 10px; font-size: .9rem; font-weight: 700;
      cursor: pointer; transition: all .18s; font-family: inherit;
      box-shadow: 0 4px 14px rgba(30,55,153,.3);
    }
    .btn-next:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(30,55,153,.38); }
    .btn-submit-final {
      display: inline-flex; align-items: center; gap: .5rem;
      background: linear-gradient(135deg, #059669, #047857);
      color: #fff; border: none; padding: .72rem 1.75rem;
      border-radius: 10px; font-size: .9rem; font-weight: 700;
      cursor: pointer; transition: all .18s; font-family: inherit;
      box-shadow: 0 4px 14px rgba(5,150,105,.3);
    }
    .btn-submit-final:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(5,150,105,.38); }

    /* ── Review Summary ───────────────────────────────── */
    .review-grid { display: flex; flex-direction: column; gap: 1.1rem; }
    .review-section {
      background: #f8fafc; border: 1px solid var(--border);
      border-radius: 10px; overflow: hidden;
    }
    .review-section-title {
      padding: .6rem 1rem;
      background: var(--navy-lite); color: var(--navy-mid);
      font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .06em;
      display: flex; align-items: center; gap: .4rem;
    }
    .review-row {
      display: grid; grid-template-columns: 140px 1fr;
      gap: .5rem; padding: .6rem 1rem; font-size: .84rem;
      border-top: 1px solid var(--border); align-items: start;
    }
    .review-row:first-of-type { border-top: none; }
    .review-label { color: var(--muted); font-weight: 600; font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; padding-top: .05rem; }
    .review-value { color: var(--text); font-weight: 500; word-break: break-word; }
    .review-value.empty { color: #94a3b8; font-style: italic; }
    .file-review-item {
      display: flex; align-items: center; gap: .5rem;
      padding: .4rem .7rem; background: #fff; border-radius: 7px;
      border: 1px solid var(--border); font-size: .8rem;
      margin: .3rem 0;
    }
    .file-review-item i { color: var(--navy-mid); }
    .file-cat-badge {
      background: var(--navy-lite); color: var(--navy-mid);
      font-size: .67rem; font-weight: 700; padding: .15rem .5rem;
      border-radius: 10px; text-transform: uppercase; letter-spacing: .03em;
    }

    /* ── Optional note ────────────────────────────────── */
    .opt-tag {
      font-size: .68rem; background: #f1f5f9; color: var(--muted);
      font-weight: 600; padding: .15rem .5rem; border-radius: 8px;
      margin-left: .35rem; vertical-align: middle; text-transform: uppercase;
    }

    /* ── Position readonly pill ───────────────────────── */
    .position-pill {
      display: inline-flex; align-items: center; gap: .6rem;
      background: var(--navy-lite); border: 1.5px solid #c3cfee;
      color: var(--navy); padding: .75rem 1.1rem;
      border-radius: 12px; font-weight: 700; font-size: .95rem;
      width: 100%;
    }
    .position-pill i { color: var(--navy-mid); font-size: 1.1rem; flex-shrink: 0; }

    /* ── Footer ───────────────────────────────────────── */
    .app-footer {
      background: var(--navy); color: rgba(255,255,255,.7);
      padding: 2rem 0; font-size: .8rem; text-align: center;
    }
    .app-footer strong { color: #fff; }
    .app-footer a { color: rgba(255,255,255,.8); text-decoration: none; }
    .app-footer a:hover { color: #fff; }

    /* ── Step indicator counter ───────────────────────── */
    .step-counter {
      font-size: .72rem; font-weight: 600; color: var(--muted);
      text-align: right; margin-bottom: .75rem;
    }
    .step-counter strong { color: var(--navy-mid); }
  </style>
</head>
<body>

<!-- ══ T&C MODAL ════════════════════════════════════════════════════════ -->
<div class="tnc-backdrop" id="tncBackdrop">
  <div class="tnc-modal" role="dialog" aria-modal="true" aria-labelledby="tncTitle">
    <div class="tnc-header">
      <div class="tnc-header-icon"><i class="bi bi-shield-lock-fill"></i></div>
      <div>
        <h5 id="tncTitle">Data Privacy Notice &amp; Terms and Conditions</h5>
        <p>Urban Tradewell Corporation — Job Application</p>
      </div>
    </div>
    <div class="tnc-body">
      <p>In compliance with <strong>Republic Act No. 10173</strong>, otherwise known as the <strong>Data Privacy Act of 2012</strong> of the Philippines and its Implementing Rules and Regulations, <strong>Urban Tradewell Corporation</strong> is committed to protecting and respecting your personal data.</p>
      <h6>1. Purpose of Data Collection</h6>
      <p>The personal information you provide will be collected and processed solely for evaluating your eligibility for employment. This includes:</p>
      <ul>
        <li>Reviewing your qualifications, work experience, and submitted documents</li>
        <li>Contacting you regarding the status of your application</li>
        <li>Scheduling interviews and related recruitment activities</li>
        <li>Storing your application record for future reference</li>
      </ul>
      <h6>2. Data Collected</h6>
      <p>The Company may collect: full name, email, phone number, position applied for, government IDs, addresses, educational background, and voluntarily submitted documents (resume, clearances, certificates, etc.).</p>
      <h6>3. Limitation of Liability</h6>
      <p><strong>Urban Tradewell Corporation shall not be held liable</strong> for unauthorized access or misuse of personal data resulting from circumstances beyond the Company's reasonable control.</p>
      <h6>4. Data Retention</h6>
      <p>Your data will be retained for the period necessary to fulfill recruitment purposes or as required by law. Applications of candidates not selected may be retained for future consideration.</p>
      <h6>5. Your Rights</h6>
      <p>As a data subject under RA 10173, you have the right to be informed, access, correct, object to, and request erasure of your personal data. Contact HR at <a href="mailto:hr.tradewell@gmail.com">hr.tradewell@gmail.com</a> or call <strong>(042) 719-1306</strong>.</p>
      <h6>6. Consent</h6>
      <p>By checking the box below and clicking <strong>"I Agree &amp; Proceed"</strong>, you voluntarily give your informed consent for Urban Tradewell Corporation to collect, store, use, and process your personal data for recruitment purposes.</p>
    </div>
    <div class="tnc-footer">
      <label class="tnc-check-label">
        <input type="checkbox" id="tncCheckbox" onchange="toggleAgreeBtn()">
        I have read and understood the Data Privacy Notice, and I voluntarily give my consent to Urban Tradewell Corporation to collect and process my personal data for recruitment purposes.
      </label>
      <div style="display:flex;gap:.5rem;flex-shrink:0;">
        <button type="button" class="btn-tnc-decline" onclick="declineTnc()"><i class="bi bi-x-lg"></i> Decline</button>
        <button type="button" class="btn-tnc-agree" id="btnAgree" disabled onclick="acceptTnc()"><i class="bi bi-check2-circle"></i> I Agree &amp; Proceed</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ FORM PAGE ════════════════════════════════════════════════════════ -->
<div id="formPage">
<div class="app-shell">

  <!-- Top Bar -->
  <div class="topbar">
    <div class="container">
      <span><i class="bi bi-envelope"></i><span>hr_tradewell@yahoo.com | hr.tradewell@gmail.com</span></span>
      <span><i class="bi bi-telephone-fill"></i><span>(042) 719-1306</span></span>
    </div>
  </div>

  <!-- Hero -->
  <div class="app-hero">
    <div class="container">
      <div class="hero-eyebrow"><i class="bi bi-briefcase-fill"></i> Career Opportunity</div>
      <div class="hero-title">Job Application</div>
      <div class="hero-sub">
        <i class="bi bi-building"></i>
        <?= !empty($defaultPosition) ? 'Applying for: <strong style="margin-left:.3rem;">' . htmlspecialchars($defaultPosition) . '</strong>' : 'Urban Tradewell Corporation' ?>
      </div>
      <div class="hero-breadcrumb">
        <a href="careers.php"><i class="bi bi-house"></i> Careers</a>
        <i class="bi bi-chevron-right" style="font-size:.65rem;"></i>
        <span>Job Application</span>
      </div>
    </div>
  </div>

  <!-- Wizard -->
  <div class="wizard-wrap">
    <div class="wizard-container">

      <?php if (!empty($errorMessage)): ?>
        <div class="alert-error">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= htmlspecialchars($errorMessage) ?></div>
        </div>
      <?php endif; ?>

      <!-- Step Progress -->
      <div class="step-progress" id="stepProgress">
        <div class="step-item active" data-step="1" onclick="goToStep(1)">
          <div class="step-bubble"><i class="bi bi-person-fill"></i></div>
          <div class="step-label">Personal</div>
        </div>
        <div class="step-item" data-step="2" onclick="goToStep(2)">
          <div class="step-bubble"><i class="bi bi-telephone-fill"></i></div>
          <div class="step-label">Contact</div>
        </div>
        <div class="step-item" data-step="3" onclick="goToStep(3)">
          <div class="step-bubble"><i class="bi bi-geo-alt-fill"></i></div>
          <div class="step-label">Address</div>
        </div>
        <div class="step-item" data-step="4" onclick="goToStep(4)">
          <div class="step-bubble"><i class="bi bi-card-text"></i></div>
          <div class="step-label">Gov't IDs</div>
        </div>
        <div class="step-item" data-step="5" onclick="goToStep(5)">
          <div class="step-bubble"><i class="bi bi-mortarboard-fill"></i></div>
          <div class="step-label">Education</div>
        </div>
        <div class="step-item" data-step="6" onclick="goToStep(6)">
          <div class="step-bubble"><i class="bi bi-paperclip"></i></div>
          <div class="step-label">Documents</div>
        </div>
        <div class="step-item" data-step="7" onclick="goToStep(7)">
          <div class="step-bubble"><i class="bi bi-check2-all"></i></div>
          <div class="step-label">Review</div>
        </div>
      </div>

      <div class="step-counter">Step <strong id="currentStepNum">1</strong> of <strong>7</strong></div>

      <form action="job-application.php?id=<?= (int)$careerId ?>" method="POST" enctype="multipart/form-data" id="appForm">
        <input type="hidden" name="career_id"    value="<?= (int)$careerId ?>">
        <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="tnc_accepted" id="tncHidden" value="0">
        <input type="hidden" name="position"     value="<?= htmlspecialchars($defaultPosition) ?>">

        <!-- ══ STEP 1: Personal Information ═══════════════════════════ -->
        <div class="step-card" id="step-1">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-person-vcard-fill"></i></div>
            <div>
              <h3>Personal Information</h3>
              <p>Tell us about yourself</p>
            </div>
            <div class="step-badge">Step 1 of 7</div>
          </div>
          <div class="step-card-body">

            <div class="field-group">
              <div class="field-label">Position Applied For</div>
              <div class="position-pill">
                <i class="bi bi-briefcase-fill"></i>
                <?= htmlspecialchars($defaultPosition) ?>
              </div>
            </div>

            <div class="section-divider">Name</div>
            <div class="three-col">
              <div class="field-group">
                <label class="field-label" for="last_name">Last Name <span class="req">*</span></label>
                <input type="text" id="last_name" name="last_name" class="field-input" placeholder="Dela Cruz" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                <div class="field-error" id="err-last_name">Last name is required.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="first_name">First Name <span class="req">*</span></label>
                <input type="text" id="first_name" name="first_name" class="field-input" placeholder="Juan" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                <div class="field-error" id="err-first_name">First name is required.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="middle_name">Middle Name <span class="opt-tag">Optional</span></label>
                <input type="text" id="middle_name" name="middle_name" class="field-input" placeholder="Santos" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
              </div>
            </div>

            <div class="section-divider">Details</div>
            <div class="two-col">
              <div class="field-group">
                <label class="field-label" for="birth_date">Date of Birth <span class="req">*</span></label>
                <input type="date" id="birth_date" name="birth_date" class="field-input" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
                <div class="field-error" id="err-birth_date">Date of birth is required.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="birth_place">Place of Birth <span class="req">*</span></label>
                <input type="text" id="birth_place" name="birth_place" class="field-input" placeholder="Lucena City, Quezon" value="<?= htmlspecialchars($_POST['birth_place'] ?? '') ?>">
                <div class="field-error" id="err-birth_place">Place of birth is required.</div>
              </div>
            </div>

            <div class="three-col">
              <div class="field-group">
                <label class="field-label" for="gender">Gender <span class="req">*</span></label>
                <select id="gender" name="gender" class="field-select">
                  <option value="">— Select —</option>
                  <?php foreach(['Male','Female','Prefer not to say'] as $g): ?>
                    <option value="<?= $g ?>" <?= (($_POST['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="field-error" id="err-gender">Please select a gender.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="civil_status">Civil Status <span class="req">*</span></label>
                <select id="civil_status" name="civil_status" class="field-select">
                  <option value="">— Select —</option>
                  <?php foreach(['Single','Married','Widowed','Separated','Annulled'] as $cs): ?>
                    <option value="<?= $cs ?>" <?= (($_POST['civil_status'] ?? '') === $cs) ? 'selected' : '' ?>><?= $cs ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="field-error" id="err-civil_status">Please select civil status.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="nationality">Nationality <span class="opt-tag">Optional</span></label>
                <input type="text" id="nationality" name="nationality" class="field-input" placeholder="Filipino" value="<?= htmlspecialchars($_POST['nationality'] ?? 'Filipino') ?>">
              </div>
            </div>

            <div class="field-group">
              <label class="field-label" for="religion">Religion <span class="opt-tag">Optional</span></label>
              <input type="text" id="religion" name="religion" class="field-input" placeholder="e.g. Roman Catholic" value="<?= htmlspecialchars($_POST['religion'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- ══ STEP 2: Contact Information ═════════════════════════════ -->
        <div class="step-card" id="step-2" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-telephone-fill"></i></div>
            <div>
              <h3>Contact Information</h3>
              <p>How can we reach you?</p>
            </div>
            <div class="step-badge">Step 2 of 7</div>
          </div>
          <div class="step-card-body">
            <div class="two-col">
              <div class="field-group">
                <label class="field-label" for="email">Email Address <span class="req">*</span></label>
                <input type="email" id="email" name="email" class="field-input" placeholder="you@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <div class="field-error" id="err-email">A valid email address is required.</div>
              </div>
              <div class="field-group">
                <label class="field-label" for="mobile">Mobile Number <span class="req">*</span></label>
                <input type="text" id="mobile" name="mobile" class="field-input" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                <div class="field-error" id="err-mobile">Mobile number is required.</div>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label" for="phone">Phone / Landline <span class="opt-tag">Optional</span></label>
              <input type="text" id="phone" name="phone" class="field-input" placeholder="(042) 123-4567" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- ══ STEP 3: Address ══════════════════════════════════════════ -->
        <div class="step-card" id="step-3" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-geo-alt-fill"></i></div>
            <div>
              <h3>Address</h3>
              <p>Your current and permanent address</p>
            </div>
            <div class="step-badge">Step 3 of 7</div>
          </div>
          <div class="step-card-body">
            <div class="field-group">
              <label class="field-label" for="present_address">Present Address <span class="req">*</span></label>
              <textarea id="present_address" name="present_address" class="field-textarea" placeholder="Unit/House No., Street, Barangay, City, Province"><?= htmlspecialchars($_POST['present_address'] ?? '') ?></textarea>
              <div class="field-error" id="err-present_address">Present address is required.</div>
            </div>
            <div class="field-group">
              <label class="field-label" for="permanent_address">Permanent Address <span class="opt-tag">Optional</span></label>
              <textarea id="permanent_address" name="permanent_address" class="field-textarea" placeholder="Leave blank if same as present address"><?= htmlspecialchars($_POST['permanent_address'] ?? '') ?></textarea>
              <div class="field-hint"><i class="bi bi-info-circle"></i> Leave blank if same as present address.</div>
            </div>
          </div>
        </div>

        <!-- ══ STEP 4: Government IDs ═══════════════════════════════════ -->
        <div class="step-card" id="step-4" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-card-text"></i></div>
            <div>
              <h3>Government IDs</h3>
              <p>Provide if currently or previously employed</p>
            </div>
            <div class="step-badge">Step 4 of 7 · Optional</div>
          </div>
          <div class="step-card-body">
            <p style="font-size:.83rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
              <i class="bi bi-info-circle" style="color:var(--navy-mid);"></i>
              All fields on this step are <strong>optional</strong>. These will be required if you are hired.
            </p>
            <div class="two-col">
              <div class="field-group">
                <label class="field-label" for="sss_number">SSS Number</label>
                <input type="text" id="sss_number" name="sss_number" class="field-input" placeholder="XX-XXXXXXX-X" value="<?= htmlspecialchars($_POST['sss_number'] ?? '') ?>">
              </div>
              <div class="field-group">
                <label class="field-label" for="tin_number">TIN Number</label>
                <input type="text" id="tin_number" name="tin_number" class="field-input" placeholder="XXX-XXX-XXX-XXX" value="<?= htmlspecialchars($_POST['tin_number'] ?? '') ?>">
              </div>
              <div class="field-group">
                <label class="field-label" for="philhealth_number">PhilHealth Number</label>
                <input type="text" id="philhealth_number" name="philhealth_number" class="field-input" placeholder="XXXX-XXXXXXX-X" value="<?= htmlspecialchars($_POST['philhealth_number'] ?? '') ?>">
              </div>
              <div class="field-group">
                <label class="field-label" for="hdmf">HDMF / Pag-IBIG Number</label>
                <input type="text" id="hdmf" name="hdmf" class="field-input" placeholder="XXXX-XXXX-XXXX" value="<?= htmlspecialchars($_POST['hdmf'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ══ STEP 5: Education ════════════════════════════════════════ -->
        <div class="step-card" id="step-5" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div>
              <h3>Educational Background</h3>
              <p>Your academic qualifications</p>
            </div>
            <div class="step-badge">Step 5 of 7</div>
          </div>
          <div class="step-card-body">
            <div class="field-group">
              <label class="field-label" for="educational_background">Educational Attainment <span class="req">*</span></label>
              <textarea id="educational_background" name="educational_background" class="field-textarea" style="min-height:110px;" placeholder="e.g. Bachelor of Science in Business Administration, University of the Philippines – 2020"><?= htmlspecialchars($_POST['educational_background'] ?? '') ?></textarea>
              <div class="field-hint"><i class="bi bi-info-circle"></i> List your highest attainment. Include school, degree, and year graduated.</div>
              <div class="field-error" id="err-educational_background">Educational background is required.</div>
            </div>
          </div>
        </div>

        <!-- ══ STEP 6: Documents ════════════════════════════════════════ -->
        <div class="step-card" id="step-6" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-paperclip"></i></div>
            <div>
              <h3>Documents</h3>
              <p>Upload your application requirements</p>
            </div>
            <div class="step-badge">Step 6 of 7</div>
          </div>
          <div class="step-card-body">
            <p style="font-size:.83rem;color:var(--muted);margin-bottom:1.25rem;line-height:1.6;">
              Select a category for each file, then attach the document. Maximum <strong>10 files</strong>, up to <strong>10 MB each</strong>.
            </p>
            <div class="file-slots-wrap" id="fileSlotsWrap">
              <div class="file-slot">
                <select name="category_id[]">
                  <option value="">— Category —</option>
                  <?php foreach ($fileCategories as $fc): ?>
                    <option value="<?= (int)$fc['FileCategoryID'] ?>"><?= htmlspecialchars($fc['CategoryName']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="file" name="files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                <button type="button" class="btn-remove-slot" onclick="removeSlot(this)" title="Remove"><i class="bi bi-x-lg"></i></button>
              </div>
            </div>
            <button type="button" class="btn-add-slot" id="btnAddSlot" onclick="addSlot()"><i class="bi bi-plus-lg"></i> Add Another Document</button>
            <div class="slot-hint"><i class="bi bi-info-circle"></i> Accepted: PDF, DOC, DOCX, JPG, PNG</div>
            <div class="field-error show" id="err-files" style="display:none;margin-top:.5rem;">Please upload at least one document with a category selected.</div>
          </div>
        </div>

        <!-- ══ STEP 7: Review & Submit ══════════════════════════════════ -->
        <div class="step-card" id="step-7" style="display:none;">
          <div class="step-card-header">
            <div class="step-card-icon"><i class="bi bi-check2-all"></i></div>
            <div>
              <h3>Review &amp; Submit</h3>
              <p>Please verify your information before submitting</p>
            </div>
            <div class="step-badge">Step 7 of 7</div>
          </div>
          <div class="step-card-body">
            <div class="review-grid" id="reviewGrid">
              <!-- Populated by JS -->
            </div>
          </div>
        </div>

        <!-- ══ Wizard Navigation ════════════════════════════════════════ -->
        <div class="wizard-nav">
          <button type="button" class="btn-prev" id="btnPrev" onclick="prevStep()" disabled>
            <i class="bi bi-arrow-left"></i> Previous
          </button>
          <button type="button" class="btn-next" id="btnNext" onclick="nextStep()">
            Next <i class="bi bi-arrow-right"></i>
          </button>
          <button type="submit" class="btn-submit-final" id="btnSubmit" style="display:none;">
            <i class="bi bi-send-fill"></i> Submit Application
          </button>
        </div>

      </form>
    </div>
  </div>

  <!-- Footer -->
  <footer class="app-footer">
    <div class="container">
      <strong>Urban Tradewell Corporation</strong> &copy; <?= date('Y') ?><br>
      Sta. Monica Street Lourdes Subdivision, Phase 2 Ibabang Iyam, Lucena City, Quezon 4301<br>
      <a href="mailto:hr.tradewell@gmail.com">hr_tradewell@yahoo.com · hr.tradewell@gmail.com</a> · (042) 719-1306
    </div>
  </footer>

</div>
</div><!-- /formPage -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>

<script>
// ── Category HTML for dynamic slots ───────────────────────────────────
const CATEGORIES_HTML = `<?php
  $opts = '<option value="">— Category —</option>';
  foreach ($fileCategories as $fc) {
    $opts .= '<option value="' . (int)$fc['FileCategoryID'] . '">' . htmlspecialchars($fc['CategoryName'], ENT_QUOTES) . '</option>';
  }
  echo addslashes($opts);
?>`;

const MAX_SLOTS   = 10;
const TOTAL_STEPS = 7;
let   currentStep = 1;

// ── T&C Logic ──────────────────────────────────────────────────────────
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
function declineTnc() { window.location.href = 'careers.php'; }
if (sessionStorage.getItem('tnc_accepted_global') === '1') {
  document.getElementById('tncBackdrop').style.display = 'none';
  document.getElementById('formPage').classList.add('visible');
  document.getElementById('tncHidden').value = '1';
}
<?php if (!empty($errorMessage)): ?>
document.getElementById('tncBackdrop').style.display = 'none';
document.getElementById('formPage').classList.add('visible');
document.getElementById('tncHidden').value = '1';
currentStep = 1;
<?php endif; ?>

// ── Step Validation Rules ──────────────────────────────────────────────
const stepValidations = {
  1: [
    { id: 'last_name',    required: true,  msg: 'Last name is required.' },
    { id: 'first_name',   required: true,  msg: 'First name is required.' },
    { id: 'birth_date',   required: true,  msg: 'Date of birth is required.' },
    { id: 'birth_place',  required: true,  msg: 'Place of birth is required.' },
    { id: 'gender',       required: true,  msg: 'Please select a gender.' },
    { id: 'civil_status', required: true,  msg: 'Please select civil status.' },
  ],
  2: [
    { id: 'email',  required: true, email: true, msg: 'A valid email address is required.' },
    { id: 'mobile', required: true, msg: 'Mobile number is required.' },
  ],
  3: [
    { id: 'present_address', required: true, msg: 'Present address is required.' },
  ],
  4: [], // all optional
  5: [
    { id: 'educational_background', required: true, msg: 'Educational background is required.' },
  ],
  6: [], // validated separately
  7: [],
};

function validateStep(step) {
  let valid = true;
  const rules = stepValidations[step] || [];

  rules.forEach(rule => {
    const el  = document.getElementById(rule.id);
    const err = document.getElementById('err-' + rule.id);
    if (!el) return;

    let ok = true;
    const val = el.value.trim();

    if (rule.required && val === '') ok = false;
    if (ok && rule.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) ok = false;

    el.classList.toggle('invalid', !ok);
    if (err) { err.textContent = rule.msg; err.style.display = ok ? 'none' : 'block'; }
    if (!ok) valid = false;
  });

  // Step 6: file validation
  if (step === 6) {
    const slots = document.querySelectorAll('.file-slot');
    let hasValid = false;
    slots.forEach(slot => {
      const cat  = slot.querySelector('select').value;
      const file = slot.querySelector('input[type="file"]').value;
      if (cat && file) hasValid = true;
    });
    const errFiles = document.getElementById('err-files');
    if (!hasValid) {
      errFiles.style.display = 'block';
      valid = false;
    } else {
      errFiles.style.display = 'none';
    }
  }

  return valid;
}

// ── Navigation ─────────────────────────────────────────────────────────
function showStep(n) {
  for (let i = 1; i <= TOTAL_STEPS; i++) {
    const card = document.getElementById('step-' + i);
    if (card) card.style.display = (i === n) ? 'block' : 'none';
  }

  // Update progress bar
  document.querySelectorAll('.step-item').forEach(item => {
    const s = parseInt(item.dataset.step);
    item.classList.remove('active', 'done');
    if (s === n)  item.classList.add('active');
    if (s < n)    item.classList.add('done');
    // replace number with checkmark for done
    const bubble = item.querySelector('.step-bubble');
    if (s < n) {
      bubble.innerHTML = '<i class="bi bi-check-lg"></i>';
    } else if (s === n) {
      bubble.innerHTML = stepIcons[s] || s;
    } else {
      bubble.innerHTML = stepIcons[s] || s;
    }
  });

  document.getElementById('currentStepNum').textContent = n;
  document.getElementById('btnPrev').disabled = (n === 1);

  const btnNext   = document.getElementById('btnNext');
  const btnSubmit = document.getElementById('btnSubmit');
  if (n === TOTAL_STEPS) {
    btnNext.style.display   = 'none';
    btnSubmit.style.display = 'inline-flex';
    buildReview();
  } else {
    btnNext.style.display   = 'inline-flex';
    btnSubmit.style.display = 'none';
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
  currentStep = n;
}

const stepIcons = {
  1: '<i class="bi bi-person-fill"></i>',
  2: '<i class="bi bi-telephone-fill"></i>',
  3: '<i class="bi bi-geo-alt-fill"></i>',
  4: '<i class="bi bi-card-text"></i>',
  5: '<i class="bi bi-mortarboard-fill"></i>',
  6: '<i class="bi bi-paperclip"></i>',
  7: '<i class="bi bi-check2-all"></i>',
};

function nextStep() {
  if (!validateStep(currentStep)) return;
  if (currentStep < TOTAL_STEPS) showStep(currentStep + 1);
}
function prevStep() {
  if (currentStep > 1) showStep(currentStep - 1);
}
function goToStep(n) {
  // Only allow jumping to completed or active steps
  if (n < currentStep) showStep(n);
}

// ── Review Builder ─────────────────────────────────────────────────────
function val(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}
function display(v) {
  return v ? `<span class="review-value">${escHtml(v)}</span>` : `<span class="review-value empty">—</span>`;
}
function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function buildReview() {
  // Personal
  const firstName   = val('first_name');
  const lastName    = val('last_name');
  const middleName  = val('middle_name');
  const birthDate   = val('birth_date');
  const birthPlace  = val('birth_place');
  const gender      = val('gender');
  const civilStatus = val('civil_status');
  const nationality = val('nationality');
  const religion    = val('religion');
  // Contact
  const email  = val('email');
  const mobile = val('mobile');
  const phone  = val('phone');
  // Address
  const presentAddr   = val('present_address');
  const permanentAddr = val('permanent_address');
  // IDs
  const sss  = val('sss_number');
  const tin  = val('tin_number');
  const phil = val('philhealth_number');
  const hdmf = val('hdmf');
  // Education
  const edu = val('educational_background');

  // Files
  let fileRows = '';
  document.querySelectorAll('.file-slot').forEach(slot => {
    const selectEl = slot.querySelector('select');
    const fileEl   = slot.querySelector('input[type="file"]');
    const catText  = selectEl.options[selectEl.selectedIndex]?.text || '';
    const fileName = fileEl.files[0]?.name || '';
    if (catText && fileName && selectEl.value) {
      fileRows += `<div class="file-review-item">
        <i class="bi bi-file-earmark-fill"></i>
        <span class="file-cat-badge">${escHtml(catText)}</span>
        <span style="flex:1;font-size:.8rem;">${escHtml(fileName)}</span>
      </div>`;
    }
  });

  const positionLabel = document.querySelector('[name="position"]')?.value || '';

  document.getElementById('reviewGrid').innerHTML = `
    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-briefcase-fill"></i> Position</div>
      <div class="review-row"><div class="review-label">Position</div>${display(positionLabel)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-person-fill"></i> Personal Information</div>
      <div class="review-row"><div class="review-label">Full Name</div>${display([firstName, middleName, lastName].filter(Boolean).join(' '))}</div>
      <div class="review-row"><div class="review-label">Birth Date</div>${display(birthDate)}</div>
      <div class="review-row"><div class="review-label">Birth Place</div>${display(birthPlace)}</div>
      <div class="review-row"><div class="review-label">Gender</div>${display(gender)}</div>
      <div class="review-row"><div class="review-label">Civil Status</div>${display(civilStatus)}</div>
      <div class="review-row"><div class="review-label">Nationality</div>${display(nationality)}</div>
      <div class="review-row"><div class="review-label">Religion</div>${display(religion)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-telephone-fill"></i> Contact</div>
      <div class="review-row"><div class="review-label">Email</div>${display(email)}</div>
      <div class="review-row"><div class="review-label">Mobile</div>${display(mobile)}</div>
      <div class="review-row"><div class="review-label">Phone</div>${display(phone)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-geo-alt-fill"></i> Address</div>
      <div class="review-row"><div class="review-label">Present</div>${display(presentAddr)}</div>
      <div class="review-row"><div class="review-label">Permanent</div>${display(permanentAddr || presentAddr)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-card-text"></i> Government IDs</div>
      <div class="review-row"><div class="review-label">SSS</div>${display(sss)}</div>
      <div class="review-row"><div class="review-label">TIN</div>${display(tin)}</div>
      <div class="review-row"><div class="review-label">PhilHealth</div>${display(phil)}</div>
      <div class="review-row"><div class="review-label">HDMF / Pag-IBIG</div>${display(hdmf)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-mortarboard-fill"></i> Education</div>
      <div class="review-row"><div class="review-label">Attainment</div>${display(edu)}</div>
    </div>

    <div class="review-section">
      <div class="review-section-title"><i class="bi bi-paperclip"></i> Documents</div>
      <div style="padding:.75rem 1rem;">${fileRows || '<span class="review-value empty">No documents attached.</span>'}</div>
    </div>
  `;
}

// ── File Slot Management ───────────────────────────────────────────────
function updateAddButton() {
  const count = document.querySelectorAll('.file-slot').length;
  const btn   = document.getElementById('btnAddSlot');
  btn.disabled = count >= MAX_SLOTS;
}
function addSlot() {
  const wrap = document.getElementById('fileSlotsWrap');
  if (wrap.querySelectorAll('.file-slot').length >= MAX_SLOTS) return;
  const div = document.createElement('div');
  div.className = 'file-slot';
  div.innerHTML = `<select name="category_id[]">${CATEGORIES_HTML}</select>
                   <input type="file" name="files[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                   <button type="button" class="btn-remove-slot" onclick="removeSlot(this)" title="Remove"><i class="bi bi-x-lg"></i></button>`;
  wrap.appendChild(div);
  updateAddButton();
}
function removeSlot(btn) {
  const wrap  = document.getElementById('fileSlotsWrap');
  const slots = wrap.querySelectorAll('.file-slot');
  if (slots.length <= 1) {
    const slot = btn.closest('.file-slot');
    if (slot) { slot.querySelector('select').value = ''; slot.querySelector('input[type="file"]').value = ''; }
    return;
  }
  btn.closest('.file-slot').remove();
  updateAddButton();
}

// ── Permanent address auto-fill on submit ─────────────────────────────
document.getElementById('appForm').addEventListener('submit', function() {
  const present   = document.getElementById('present_address');
  const permanent = document.getElementById('permanent_address');
  if (permanent && permanent.value.trim() === '' && present) {
    permanent.value = present.value;
  }
});

// ── Init ───────────────────────────────────────────────────────────────
updateAddButton();
showStep(1);
</script>

</body>
</html>