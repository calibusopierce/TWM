<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php'; // DB connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// ── Helper: safe DB error logger ───────────────────────────────
function db_fail(string $context): void {
    $errors = sqlsrv_errors();
    error_log('[DB ERROR] ' . $context . ' — ' . print_r($errors, true));
}

// ── CONFIG ────────────────────────────────────────────────────
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/TWM/uploads/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

// ── Get career ID ──────────────────────────────────────────────
$careerId = 0;
if (!empty($_POST['career_id'])) $careerId = (int)$_POST['career_id'];
elseif (!empty($_GET['id'])) $careerId = (int)$_GET['id'];
if ($careerId <= 0) { header('Location: careers.php'); exit(); }

// ── Fetch job details ──────────────────────────────────────────
$defaultPosition = '';
$careerDeptId = null;
$cSql = "SELECT JobTitle, Department FROM Careers WHERE CareerID = ?";
$cStmt = sqlsrv_query($conn, $cSql, [$careerId]);
if ($cStmt === false) { db_fail('Fetch Career'); die("Career not found"); }
$cRow = sqlsrv_fetch_array($cStmt, SQLSRV_FETCH_ASSOC);
if ($cRow) {
    $defaultPosition = $cRow['JobTitle'];
    $careerDeptId    = $cRow['Department'];
} else { header('Location: careers.php'); exit(); }
sqlsrv_free_stmt($cStmt);

// ── Fetch active file categories ───────────────────────────────
$fileCategories = [];
$fcSql  = "SELECT FileCategoryID, CategoryName FROM FileCategories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName";
$fcStmt = sqlsrv_query($conn, $fcSql);
if ($fcStmt) {
    while ($fcRow = sqlsrv_fetch_array($fcStmt, SQLSRV_FETCH_ASSOC)) $fileCategories[] = $fcRow;
    sqlsrv_free_stmt($fcStmt);
}

// ── CSRF token ────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── POST SUBMISSION ───────────────────────────────────────────
$errorMessage = '';
$uploadedFiles = [];
$debugLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errorMessage = "Invalid form submission. Refresh page and try again.";
    }

    // T&C check
    $tncAccepted = isset($_POST['tnc_accepted']) && $_POST['tnc_accepted'] === '1';
    if (empty($errorMessage) && !$tncAccepted) $errorMessage = "You must accept the Terms and Conditions.";

    // Form fields
    $fullname = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? $defaultPosition);

    if (empty($errorMessage)) {
        if ($fullname === '' || $email === '' || $position === '') $errorMessage = "Fill all required fields.";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errorMessage = "Invalid email address.";
        elseif (empty($_FILES['files']['name'][0])) $errorMessage = "Upload at least one document.";
    }

    if (empty($errorMessage)) {
        // ── Submission datetime ─────────────────────
        date_default_timezone_set('Asia/Manila');
        $submittedAt = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

        // ── Insert Applicant Data ──────────────────
        $insertAppSql = "
            INSERT INTO JobApplications 
                (CareerID, FullName, Email, Phone, Position, SubmittedAt)
            OUTPUT INSERTED.ApplicationID
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        $params = [$careerId, $fullname, $email, $phone, $position, $submittedAt];
        $stmt = sqlsrv_query($conn, $insertAppSql, $params);
        if ($stmt === false) { db_fail('Insert JobApplications'); $errorMessage="Failed to save application."; }
        else {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $applicationID = $row['ApplicationID'] ?? null;
            sqlsrv_free_stmt($stmt);
            if (!$applicationID) $errorMessage="Failed to retrieve application ID.";
        }
    }

    // ── Handle Files (filesystem only) ─────────
    if (empty($errorMessage)) {
        $fileCategoriesIds = $_POST['category_id'] ?? [];
        $files = $_FILES['files'];

        foreach ($files['name'] as $i => $filename) {
            if (empty($filename)) continue;

            $categoryId = (int)($fileCategoriesIds[$i] ?? 0);
            $tmpPath    = $files['tmp_name'][$i];
            $fileNameSafe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($filename));
            $targetPath = $uploadDir . $applicationID . '_' . $fileNameSafe;

            if (move_uploaded_file($tmpPath, $targetPath)) {
                $uploadedFiles[] = [
                    'name' => $fileNameSafe,
                    'category' => array_values(array_filter($fileCategories, fn($c) => $c['FileCategoryID']===$categoryId))[0]['CategoryName'] ?? 'Unknown',
                    'path' => $targetPath
                ];
                $debugLog[] = "Saved file: $targetPath";
            } else {
                $debugLog[] = "Failed to save file: $targetPath";
            }
        }
    }

    // ── Send Confirmation Email ───────────────
    if (empty($errorMessage)) {
        $safeFullname = htmlspecialchars($fullname, ENT_QUOTES);
        $safePosition = htmlspecialchars($position, ENT_QUOTES);
        $appliedAt    = $submittedAt;
        $fromName     = "Urban Tradewell HR";
        $fromEmail    = "hr.tradewell@gmail.com";

        $fileRows = '';
        foreach ($uploadedFiles as $f) {
            $fileRows .= "<tr><td style='padding:7px 10px;border:1px solid #e2e8f0'>{$f['category']}</td>"
                       . "<td style='padding:7px 10px;border:1px solid #e2e8f0'>{$f['name']}</td></tr>";
        }

        $subject = "Application Received — $safePosition";
        $htmlMessage = <<<HTML
<html><body>
<p>Good day! <strong>{$safeFullname}</strong>,</p>
<p>Thank you for applying for <strong>{$safePosition}</strong> on <strong>{$appliedAt}</strong>.</p>
<p>We have received your application and the following document(s):</p>
<table border="1" cellpadding="5" cellspacing="0">
<thead><tr><th>Category</th><th>File</th></tr></thead>
<tbody>{$fileRows}</tbody>
</table>
<p>Your application is being processed. We will contact you once evaluated.</p>
</body></html>
HTML;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: hr.tradewell@gmail.com\r\n";

        if (!mail($email, $subject, $htmlMessage, $headers)) {
            $debugLog[] = "Failed to send confirmation email to $email";
        }

        $_SESSION['successMessage'] = "Application submitted successfully!";
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: careers.php');
        exit();
    }
}

// ── DEBUG MODE ───────────────────────────────────────────────
if (isset($_GET['debug'])) {
    echo '<pre>';
    var_dump([
        'GET' => $_GET,
        'POST' => $_POST,
        'careerId' => $careerId,
        'defaultPosition' => $defaultPosition,
        'uploadedFiles' => $uploadedFiles,
        'debugLog' => $debugLog
    ]);
    echo '</pre>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Job Application</title>
<style>
body{font-family:sans-serif;background:#f0f2f5;padding:1rem;}
form{max-width:720px;margin:auto;background:#fff;padding:1.5rem;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.1);}
.field-group{margin-bottom:1rem;}
.field-label{font-weight:600;margin-bottom:.25rem;display:block;}
.field-input{width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;}
.file-slots-wrap{display:flex;flex-direction:column;gap:.5rem;margin-top:.5rem;}
.file-slot{display:grid;grid-template-columns:200px 1fr auto;gap:.5rem;align-items:center;border:1.5px dashed #cbd5e1;border-radius:8px;padding:.5rem;}
.btn-remove-slot{background:none;border:none;cursor:pointer;color:#ef4444;font-size:1rem;}
.btn-add-slot{margin-top:.5rem;padding:.5rem 1rem;border:1px dashed #3b82f6;color:#3b82f6;border-radius:8px;cursor:pointer;}
.alert-error{background:#fee2e2;color:#b91c1c;padding:.75rem 1rem;margin-bottom:1rem;border-radius:6px;}
</style>
</head>
<body>

<?php if(!empty($errorMessage)): ?>
<div class="alert-error"><?= htmlspecialchars($errorMessage) ?></div>
<?php endif; ?>

<form action="" method="POST" enctype="multipart/form-data" id="appForm">
<input type="hidden" name="career_id" value="<?= (int)$careerId ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
<input type="hidden" name="tnc_accepted" id="tncHidden" value="0">

<div class="field-group">
<label class="field-label">Full Name *</label>
<input type="text" name="name" class="field-input" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
</div>

<div class="field-group">
<label class="field-label">Email *</label>
<input type="email" name="email" class="field-input" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
</div>

<div class="field-group">
<label class="field-label">Phone</label>
<input type="text" name="phone" class="field-input" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
</div>

<div class="field-group">
<label class="field-label">Position *</label>
<input type="text" class="field-input" readonly value="<?= htmlspecialchars($defaultPosition) ?>">
<input type="hidden" name="position" value="<?= htmlspecialchars($defaultPosition) ?>">
</div>

<div class="field-group">
<label class="field-label">Documents *</label>
<div class="file-slots-wrap" id="fileSlotsWrap">
<div class="file-slot">
<select name="category_id[]" required>
<option value="">— Select Category —</option>
<?php foreach($fileCategories as $fc): ?>
<option value="<?= (int)$fc['FileCategoryID'] ?>"><?= htmlspecialchars($fc['CategoryName']) ?></option>
<?php endforeach; ?>
</select>
<input type="file" name="files[]" accept=".pdf,.doc,.docx" required>
<button type="button" class="btn-remove-slot" onclick="removeSlot(this)">✖</button>
</div>
</div>
<button type="button" class="btn-add-slot" onclick="addSlot()">➕ Add Another Document</button>
</div>

<div style="margin-top:1rem;">
<button type="submit">Submit Application</button>
</div>
</form>

<script>
const CATEGORIES_HTML = `<?php
$opts='<option value="">— Select Category —</option>';
foreach($fileCategories as $fc) $opts.='<option value="'.(int)$fc['FileCategoryID'].'">'.htmlspecialchars($fc['CategoryName'],ENT_QUOTES).'</option>';
echo addslashes($opts);
?>`;
const MAX_SLOTS=10;
function addSlot(){
const wrap=document.getElementById('fileSlotsWrap');
if(wrap.querySelectorAll('.file-slot').length>=MAX_SLOTS)return;
const div=document.createElement('div');
div.className='file-slot';
div.innerHTML=`<select name="category_id[]" required>${CATEGORIES_HTML}</select><input type="file" name="files[]" accept=".pdf,.doc,.docx"><button type="button" class="btn-remove-slot" onclick="removeSlot(this)">✖</button>`;
wrap.appendChild(div);
}
function removeSlot(btn){
const wrap=document.getElementById('fileSlotsWrap');
const slots=wrap.querySelectorAll('.file-slot');
if(slots.length<=1){const slot=btn.closest('.file-slot');slot.querySelector('select').value='';slot.querySelector('input[type="file"]').value='';return;}
btn.closest('.file-slot').remove();
}
</script>

</body>
</html>