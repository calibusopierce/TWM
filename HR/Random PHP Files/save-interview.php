<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php'; // DB connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $applicationID   = $_POST['applicationID'] ?? null;
    $interviewDT     = $_POST['InterviewDateTime'] ?? null;
    $officeAddress   = $_POST['OfficeAddress'] ?? null;
    $hrContactFileNo = $_POST['HRContactFileNo'] ?? null;

    $errors = [];

    if (empty($applicationID) || !is_numeric($applicationID)) {
        $errors[] = "invalid_application_id";
    } else {
        $applicationID = (int)$applicationID;
    }

    if (empty($interviewDT)) {
        $errors[] = "missing_datetime";
    } else {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $interviewDT);
        if (!$dt) {
            $errors[] = "invalid_datetime";
        } else {
            $interviewDT_db    = $dt->format('Y-m-d H:i:s');
            $interviewDT_email = $dt->format('F j, Y, g:i A');
        }
    }

    if (empty($officeAddress)) $errors[] = "missing_address";
    if (empty($hrContactFileNo)) $errors[] = "missing_hr_contact";

    if (!empty($errors)) {
        header("Location: view-applications.php?interview_saved=0&errors=" . implode(",", $errors));
        exit;
    }

    try {
        if (!sqlsrv_begin_transaction($conn)) {
            throw new Exception(print_r(sqlsrv_errors(), true));
        }

        // --- FETCH HR INFO ---
        $hrName   = 'HR Department';
        $hrDept   = '';
        $deptID   = null;

        $sqlHR = "SELECT CONCAT(FirstName, ' ', LastName) AS FullName, Department 
                  FROM TBL_HREmployeeList 
                  WHERE FileNo = ?";
        $stmtHR = sqlsrv_query($conn, $sqlHR, [$hrContactFileNo]);

        if ($stmtHR === false) {
            $errors = sqlsrv_errors();
            error_log("SQL Error in HR lookup: " . print_r($errors, true));
            header("Location: view-applications.php?interview_saved=0&error=hr_lookup_failed");
            exit;
        }

        $rowHR = sqlsrv_fetch_array($stmtHR, SQLSRV_FETCH_ASSOC);
        if ($rowHR) {
            $hrName = $rowHR['FullName'] ?? 'HR Department';
            $hrDept = $rowHR['Department'] ?? '';
        }
        sqlsrv_free_stmt($stmtHR);

        // --- FETCH DepartmentID using Department name ---
        if (!empty($hrDept)) {
            $sqlDept = "SELECT DepartmentID FROM Departments WHERE DepartmentName = ?";
            $stmtDept = sqlsrv_query($conn, $sqlDept, [$hrDept]);

            if ($stmtDept && ($rowDept = sqlsrv_fetch_array($stmtDept, SQLSRV_FETCH_ASSOC))) {
                $deptID = $rowDept['DepartmentID'];
            }
            sqlsrv_free_stmt($stmtDept);
        }

        // --- UPDATE APPLICATION STATUS + DepartmentID ---
        $updateSql = "UPDATE JobApplications SET Status = ?, DepartmentID = ? WHERE ApplicationID = ?";
        $updateStmt = sqlsrv_query($conn, $updateSql, [2, $deptID, $applicationID]);
        if ($updateStmt === false) {
            $errors = sqlsrv_errors();
            error_log("SQL Error in Department update: " . print_r($errors, true));
            header("Location: view-applications.php?interview_saved=0&error=dept_update_failed");
            exit;
        }
        sqlsrv_free_stmt($updateStmt);

        // --- FETCH APPLICANT INFO ---
        $fetchSql = "SELECT FullName, Email FROM JobApplications WHERE ApplicationID = ?";
        $fetchStmt = sqlsrv_query($conn, $fetchSql, [$applicationID]);
        $applicant = sqlsrv_fetch_array($fetchStmt, SQLSRV_FETCH_ASSOC);
        $applicantName  = $applicant['FullName'] ?? '';
        $applicantEmail = $applicant['Email'] ?? '';
        sqlsrv_free_stmt($fetchStmt);

        // --- INSERT OR UPDATE INTERVIEW RECORD ---
        $checkSql = "SELECT COUNT(*) AS cnt FROM JobApplicationsInterview WHERE ApplicationID = ?";
        $checkStmt = sqlsrv_query($conn, $checkSql, [$applicationID]);
        $row = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
        $exists = ($row && $row['cnt'] > 0);
        sqlsrv_free_stmt($checkStmt);

        // save-interview.php is always an Initial interview (InterviewType = 1)
        $interviewType = 1;

        if ($exists) {
            $sql = "UPDATE JobApplicationsInterview
                    SET InterviewDateTime = ?, OfficeAddress = ?, HRContactFileNo = ?, InterviewType = ?, ModifiedAt = GETDATE()
                    WHERE ApplicationID = ?";
            $stmt = sqlsrv_query($conn, $sql, [$interviewDT_db, $officeAddress, $hrContactFileNo, $interviewType, $applicationID]);
        } else {
            $sql = "INSERT INTO JobApplicationsInterview
                    (ApplicationID, InterviewDateTime, OfficeAddress, HRContactFileNo, InterviewType, CreatedAt)
                    VALUES (?, ?, ?, ?, ?, GETDATE())";
            $stmt = sqlsrv_query($conn, $sql, [$applicationID, $interviewDT_db, $officeAddress, $hrContactFileNo, $interviewType]);
        }

        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log("SQL Error in Interview save: " . print_r($errors, true));
            header("Location: view-applications.php?interview_saved=0&error=interview_save_failed");
            exit;
        }

        sqlsrv_free_stmt($stmt);
        sqlsrv_commit($conn);

        // --- SEND EMAIL (Bootstrap-Inspired Design) ---
        if (!empty($applicantEmail)) {
            $fromName  = 'Urban Tradewell Corporation';
            $host      = $_SERVER['HTTP_HOST'] ?? 'example.com';
            $hostClean = preg_replace('/[^a-z0-9\.\-]/i', '', $host);
            $fromEmail = 'no-reply@' . ($hostClean ?: 'example.com');

            $htmlMessage = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Interview Schedule</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:Arial, Helvetica, sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f6">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:10px;overflow:hidden;">
          <!-- Header -->
          <tr>
            <td style="background:#1e3799; color:#ffffff; padding:0;">
              <!-- Nested table for perfect centering -->
              <table width="100%" cellpadding="0" cellspacing="0" class="header-table">
                <tr>
                  <!-- Left cell: contains the logo -->
                  <td width="40" align="left" valign="middle" style="padding:20px 0 20px 30px;">
                    <img src="http://122.52.195.3/tradewellportal/img/utc.png" 
                        width="40" height="30" 
                        style="display:block; height:30px; width:auto;" 
                        alt="UTC Logo">
                  </td>
                  <!-- Center cell: heading and subtitle -->
                  <td align="center" valign="middle" style="padding:20px 0;">
                    <h1 style="margin:0; font-size:20px;">Urban Tradewell Corporation</h1>
                    <div style="font-size:14px; opacity:0.9;">Interview Schedule Notification</div>
                  </td>
                  <!-- Right cell: empty spacer with same width as left cell -->
                  <td width="40" style="padding:20px 30px 20px 0;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:25px 30px;color:#333;">
              <p style="margin:0 0 12px;">Good day! <strong>{$applicantName}</strong>,</p>

              <p style="margin:0 0 12px;">
                You are cordially invited for an interview. Please find your interview details below:
              </p>

              <ul style="margin:16px 0;padding-left:20px;color:#1e3799;">
                <li><strong>Date & Time:</strong> {$interviewDT_email}</li>
                <li><strong>Office Address:</strong> {$officeAddress}</li>
                <li><strong>HR Contact:</strong> {$hrName}</li>
                <li><strong>Department:</strong> {$hrDept}</li>
              </ul>

              <p style="margin:16px 0 0;color:#555;">
                Please make sure to arrive on time. If you have any questions, feel free to contact us.
              </p>

              <div style="text-align:center;margin-top:20px;">
                <a href="https://www.facebook.com/UrbanTradewellCorp" style="background:#1e3799;color:#ffffff;padding:12px 20px;text-decoration:none;border-radius:5px;display:inline-block;">
                  Contact HR
                </a>
              </div>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f1f3f5;padding:15px 30px;color:#666;font-size:13px;text-align:center;">
              Urban Tradewell Corporation &copy; 2026 <br>
              Sta. Monica Street Lourdes Subdivision, Phase 2 Ibabang Iyam, Lucena City, Quezon 4301<br>
              Email: <a href="mailto:hr.tradewell@gmail.com" style="color:#1e3799;text-decoration:none;">hr_tradewell@yahoo.com, hr.tradewell@gmail.com </a> | Phone: (042) 719-1306
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
            $headers .= "Reply-To: hr.tradewell@gmail.com\r\n";


            mail($applicantEmail, "Interview Schedule — Urban Tradewell Corp.", $htmlMessage, $headers);
        }

        header("Location: view-applications.php?interview_saved=1");
        exit;

    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Error in save-interview: " . $e->getMessage());
        header("Location: view-applications.php?interview_saved=0&error=exception");
        exit;
    }
}
?>