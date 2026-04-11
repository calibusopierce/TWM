<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// Status constants — must match DB values and $statusLabels in view-applications.php
define('S_PENDING',       0);
define('S_EVALUATING',    1);
define('S_FOR_INTERVIEW', 2);
define('S_RESCHEDULE',    3);
define('S_FINAL',         4);
define('S_FINAL_RESCHED', 5);
define('S_HIRED',         6);
define('S_REJECTED',      7);

$interviewStatuses = [S_FOR_INTERVIEW, S_RESCHEDULE, S_FINAL, S_FINAL_RESCHED];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view-applications.php');
    exit;
}

$applicationID = isset($_POST['applicationID']) && is_numeric($_POST['applicationID'])
    ? (int)$_POST['applicationID'] : 0;
$status        = isset($_POST['status']) && is_numeric($_POST['status'])
    ? (int)$_POST['status'] : null;
$departmentID  = isset($_POST['department']) && is_numeric($_POST['department'])
    ? (int)$_POST['department'] : null;
$returnTab     = strtolower(trim($_POST['returnTab'] ?? 'pending'));
$returnAliases = ['active' => 'interview'];
if (isset($returnAliases[$returnTab])) {
    $returnTab = $returnAliases[$returnTab];
}
$validReturnTabs = ['pending', 'evaluating', 'interview', 'hired', 'rejected'];
if (!in_array($returnTab, $validReturnTabs, true)) {
    $returnTab = 'pending';
}

// Interview fields
$interviewDT   = trim($_POST['InterviewDateTime'] ?? '');
$officeAddress = trim($_POST['OfficeAddress'] ?? '');
$hrContactNo   = trim($_POST['HRContactFileNo'] ?? '');

if (!$applicationID || $status === null) {
    header('Location: view-applications.php?tab='.$returnTab.'&updated=0&error=invalid_input');
    exit;
}

// ── Begin transaction ──────────────────────────────────────────
if (!sqlsrv_begin_transaction($conn)) {
    error_log('Transaction begin failed: ' . print_r(sqlsrv_errors(), true));
    header('Location: view-applications.php?tab='.$returnTab.'&updated=0&error=transaction_failed');
    exit;
}

try {

    // ── Fetch applicant ────────────────────────────────────────
    $fetchStmt = sqlsrv_query($conn,
        "SELECT FullName, Email, Position, Status FROM JobApplications WHERE ApplicationID = ?",
        [$applicationID]);
    if (!$fetchStmt || !($applicant = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC))) {
        throw new Exception('not_found');
    }
    $fullname      = $applicant['FullName'];
    $email         = $applicant['Email'];
    $position      = $applicant['Position'];
    $currentStatus = (int)$applicant['Status'];
    sqlsrv_free_stmt($fetchStmt);

    // No meaningful change — skip DB write
    if ($currentStatus === $status && !in_array($status, $interviewStatuses)) {
        sqlsrv_rollback($conn);
        header('Location: view-applications.php?tab='.$returnTab.'&updated=0&info=no_change');
        exit;
    }

    // ── Update status + department ─────────────────────────────
    $updateStmt = sqlsrv_query($conn,
        "UPDATE JobApplications SET Status = ?, DepartmentID = ? WHERE ApplicationID = ?",
        [$status, $departmentID, $applicationID]);
    if ($updateStmt === false) {
        throw new Exception('status_update_failed');
    }
    sqlsrv_free_stmt($updateStmt);

    // ── Fetch HR name & department for ALL statuses ────────────
    $hrName       = '';
    $hrDepartment = '';

    if ($hrContactNo !== '') {
        error_log('update-status.php | HRContactFileNo received: [' . $hrContactNo . ']');

        $hrStmt = sqlsrv_query($conn,
            "SELECT FirstName, LastName, Department FROM TBL_HREmployeeList WHERE FileNo = ?",
            [$hrContactNo]);

        if ($hrStmt === false) {
            error_log('update-status.php | HR query failed: ' . print_r(sqlsrv_errors(), true));
        } elseif ($hr = sqlsrv_fetch_array($hrStmt, SQLSRV_FETCH_ASSOC)) {
            $hrName       = trim(($hr['FirstName'] ?? '') . ' ' . ($hr['LastName'] ?? ''));
            $hrDepartment = trim($hr['Department'] ?? '');
            error_log('update-status.php | HR found: ' . $hrName . ' | Dept: ' . $hrDepartment);
            sqlsrv_free_stmt($hrStmt);
        } else {
            error_log('update-status.php | No HR row found for FileNo: [' . $hrContactNo . '] | sqlsrv errors: ' . print_r(sqlsrv_errors(), true));
            sqlsrv_free_stmt($hrStmt);
        }
    } else {
        error_log('update-status.php | HRContactFileNo is empty — no HR name will appear in email.');
    }

    // ── Save interview record if applicable ────────────────────
    if (in_array($status, $interviewStatuses) && $interviewDT !== '') {

        $dtFormatted   = date('Y-m-d H:i:s', strtotime($interviewDT));
        $interviewType = in_array($status, [S_FINAL, S_FINAL_RESCHED]) ? 2 : 1;

        // Insert or update interview record
        $existCheck = sqlsrv_query($conn,
            "SELECT TOP 1 InterviewID FROM JobApplicationsInterview WHERE ApplicationID = ? ORDER BY InterviewID DESC",
            [$applicationID]);
        $existRow = $existCheck ? sqlsrv_fetch_array($existCheck, SQLSRV_FETCH_ASSOC) : null;
        if ($existCheck) sqlsrv_free_stmt($existCheck);

        if ($existRow) {
            $intStmt = sqlsrv_query($conn,
                "UPDATE JobApplicationsInterview
                 SET InterviewDateTime = ?, OfficeAddress = ?, HRContactFileNo = ?, InterviewType = ?, ModifiedAt = GETDATE()
                 WHERE InterviewID = ?",
                [$dtFormatted, $officeAddress, $hrContactNo, $interviewType, $existRow['InterviewID']]);
        } else {
            $intStmt = sqlsrv_query($conn,
                "INSERT INTO JobApplicationsInterview (ApplicationID, InterviewDateTime, OfficeAddress, HRContactFileNo, InterviewType, CreatedAt)
                 VALUES (?, ?, ?, ?, ?, GETDATE())",
                [$applicationID, $dtFormatted, $officeAddress, $hrContactNo, $interviewType]);
        }

        if ($intStmt === false) {
            throw new Exception('interview_save_failed');
        }
        sqlsrv_free_stmt($intStmt);
    }

    // ── Commit ─────────────────────────────────────────────────
    sqlsrv_commit($conn);

} catch (Exception $e) {
    sqlsrv_rollback($conn);
    error_log('update-status.php error: ' . $e->getMessage() . ' | ' . print_r(sqlsrv_errors(), true));
    header('Location: view-applications.php?tab='.$returnTab.'&updated=0&error='.$e->getMessage());
    exit;
}

// ── Email setup ────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

$subject          = '';
$bodyContent      = '';
$interviewDateStr = '';
if ($interviewDT !== '') {
    $interviewDateStr = date('F j, Y \a\t g:i A', strtotime($interviewDT));
}

// ── HR signature block (used in all email templates) ──────────
$hrSignature = '';
if (!empty($hrName)) {
    $deptLine = !empty($hrDepartment)
        ? "<p style='margin:2px 0 0;font-size:13px;color:#555;'>{$hrDepartment}</p>"
        : '';
    $hrSignature = "
        <div style='margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:13px;'>
            <p style='margin:0 0 4px;color:#888;'>For inquiries, please contact your HR representative:</p>
            <p style='margin:0;font-weight:700;color:#1e3799;font-size:14px;'>{$hrName}</p>
            {$deptLine}
            <p style='margin:2px 0 0;color:#555;'>Urban Tradewell Corporation — Human Resources</p>
        </div>";
}

// ── Email templates ────────────────────────────────────────────
switch ($status) {

    case S_EVALUATING:
        $subject = "Application Status Update: Under Evaluation";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>Your application for the position of <strong>{$position}</strong> is now being evaluated by our HR team.</p>
            <p style='margin:0;color:#555;'>We will get back to you as soon as a decision has been made. Thank you for your patience.</p>
            {$hrSignature}";
        break;

    case S_FOR_INTERVIEW:
        $subject = "Interview Invitation — {$position}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>We are pleased to inform you that you are invited for an <strong>Initial Interview</strong> for the position of <strong>{$position}</strong>.</p>
            " . ($interviewDT ? "<div style='background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 6px;font-weight:700;color:#0369a1;'>📅 Interview Details</p>
                <p style='margin:0 0 4px;'><strong>Date &amp; Time:</strong> {$interviewDateStr}</p>
                " . ($officeAddress ? "<p style='margin:0;'><strong>Venue:</strong> {$officeAddress}</p>" : "") . "
            </div>" : "") . "
            <p style='margin:0;color:#555;'>Please come prepared with your documents. We look forward to meeting you!</p>
            {$hrSignature}";
        break;

    case S_RESCHEDULE:
        $subject = "Interview Re-schedule — {$position}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>We would like to inform you that your interview for the position of <strong>{$position}</strong> has been <strong>rescheduled</strong>.</p>
            " . ($interviewDT ? "<div style='background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 6px;font-weight:700;color:#92400e;'>📅 New Interview Schedule</p>
                <p style='margin:0 0 4px;'><strong>Date &amp; Time:</strong> {$interviewDateStr}</p>
                " . ($officeAddress ? "<p style='margin:0;'><strong>Venue:</strong> {$officeAddress}</p>" : "") . "
            </div>" : "") . "
            <p style='margin:0;color:#555;'>Please confirm your availability. Thank you!</p>
            {$hrSignature}";
        break;

    case S_FINAL:
        $subject = "Final Interview Invitation — {$position}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>We are pleased to invite you for a <strong>Final Interview</strong> for the position of <strong>{$position}</strong>.</p>
            " . ($interviewDT ? "<div style='background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 6px;font-weight:700;color:#15803d;'>📅 Final Interview Details</p>
                <p style='margin:0 0 4px;'><strong>Date &amp; Time:</strong> {$interviewDateStr}</p>
                " . ($officeAddress ? "<p style='margin:0;'><strong>Venue:</strong> {$officeAddress}</p>" : "") . "
            </div>" : "") . "
            <p style='margin:0;color:#555;'>This is the final step of our selection process. We look forward to seeing you!</p>
            {$hrSignature}";
        break;

    case S_FINAL_RESCHED:
        $subject = "Final Interview Re-schedule — {$position}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>We would like to inform you that your <strong>Final Interview</strong> for the position of <strong>{$position}</strong> has been <strong>rescheduled</strong>.</p>
            " . ($interviewDT ? "<div style='background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin:16px 0;'>
                <p style='margin:0 0 6px;font-weight:700;color:#92400e;'>📅 New Final Interview Schedule</p>
                <p style='margin:0 0 4px;'><strong>Date &amp; Time:</strong> {$interviewDateStr}</p>
                " . ($officeAddress ? "<p style='margin:0;'><strong>Venue:</strong> {$officeAddress}</p>" : "") . "
            </div>" : "") . "
            <p style='margin:0;color:#555;'>Please confirm your availability. Thank you!</p>
            {$hrSignature}";
        break;

    case S_HIRED:
        $subject = "Congratulations — You're Hired!";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 12px;'>We are thrilled to inform you that you have been selected for the position of <strong>{$position}</strong> at Urban Tradewell Corporation!</p>
            <p style='margin:0 0 12px;'>Our HR representative will contact you shortly with the next steps, including your schedule and employment details.</p>
            <p style='margin:16px 0 0;'><strong>Welcome to Urban Tradewell Corporation — we look forward to working with you! 🎉</strong></p>
            {$hrSignature}";
        break;

    case S_REJECTED:
        $subject = "Update on Your Application — {$position}";
        $bodyContent = "
            <p style='margin:0 0 12px;'>Good day! <strong>{$fullname}</strong>,</p>
            <p style='margin:0 0 14px;'><strong>After careful review, we regret to inform you that we will not be proceeding with your application at this time.</strong></p>
            <p style='margin:0 0 12px;'>Thank you for your interest in the <strong>{$position}</strong> position at Urban Tradewell Corporation.</p>
            <p style='margin:0;color:#555;'>We appreciate the time and effort you invested in the application process and wish you all the best in your career journey.</p>
            {$hrSignature}";
        break;
}

// ── Send email ─────────────────────────────────────────────────
if (!empty($subject) && !empty($bodyContent) && !empty($email)) {
    $fromName  = 'Urban Tradewell Corporation';
    $host      = $_SERVER['HTTP_HOST'] ?? 'example.com';
    $fromEmail = 'no-reply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);

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
                <div style="font-size:14px;opacity:.9;">Application Status Update</div>
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
          Urban Tradewell Corporation &copy; <?= date('Y') ?><br>
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
    @mail($email, $subject, $htmlMessage, $headers);
}

header('Location: view-applications.php?tab=' . $returnTab . '&updated=1');
exit;