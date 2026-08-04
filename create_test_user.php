<?php
/**
 * THIS IS STRCTLY FOR TESTING PURPOSES ONLY!!!!!
 * Run this ONCE to create a test messaging user, then delete it.
 * Access via browser: http://yoursite/TWM/create_test_user.php
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

// ── Config — change these if you want ────────────────────────
$testEmployeeID = 'TEST002';
$testUsername   = 'test';
$testEmail      = 'testuser@tradewell.com';
$testPassword   = 'test';        // plain text — will be hashed
$testFirstName  = 'Test';
$testLastName   = 'User';
$testDept       = 'IT';
$testPosition   = 'Tester';
$testBranch     = 'Quezon';
$testUserType   = 'user';             // adjust to match your RBAC role that has message_user
$testUserLevel  = 1;
// ─────────────────────────────────────────────────────────────

$hashedPassword = md5($testPassword);
$errors = [];
$done   = [];

// ── 1. Check if EmployeeID already exists ────────────────────
$chkEmp = sqlsrv_query($conn, "SELECT EmployeeID FROM TBL_HREmployeeList WHERE EmployeeID = ?", [$testEmployeeID]);
$empExists = $chkEmp && sqlsrv_fetch_array($chkEmp);

if (!$empExists) {
    $sqlEmp = "
        INSERT INTO TBL_HREmployeeList (
            EmployeeID, FirstName, LastName, Department,
            Position_held, Branch, Active, Hired_date, Gender,
            Employee_Status
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, 1, GETDATE(), 'Male',
            'Regular'
        )
    ";
    $stmtEmp = sqlsrv_query($conn, $sqlEmp, [
        $testEmployeeID, $testFirstName, $testLastName, $testDept,
        $testPosition, $testBranch
    ]);

    if ($stmtEmp) {
        $done[] = "✔ Employee record created (EmployeeID: <b>$testEmployeeID</b>)";
    } else {
        $errs = sqlsrv_errors();
        $errors[] = "✘ Employee insert failed: " . ($errs[0]['message'] ?? 'unknown');
    }
} else {
    $done[] = "⚠️ Employee record already exists — skipped.";
}

// ── 2. Check if username already exists ──────────────────────
$chkUsr = sqlsrv_query($conn, "SELECT id FROM users WHERE username = ?", [$testUsername]);
$usrExists = $chkUsr && sqlsrv_fetch_array($chkUsr);

if (!$usrExists) {
    $sqlUsr = "
        INSERT INTO users (
            username, email, password, user_type,
            userlevel, EmployeeID, Reg_DateTime
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, GETDATE()
        )
    ";
    $stmtUsr = sqlsrv_query($conn, $sqlUsr, [
        $testUsername, $testEmail, $hashedPassword, $testUserType,
        $testUserLevel, $testEmployeeID
    ]);

    if ($stmtUsr) {
        $done[] = "✔ User account created (username: <b>$testUsername</b>, password: <b>$testPassword</b>)";
    } else {
        $errs = sqlsrv_errors();
        $errors[] = "✘ User insert failed: " . ($errs[0]['message'] ?? 'unknown');
    }
} else {
    $done[] = "⚠️ User account already exists — skipped.";
}

// ── 3. Show result ────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Test User</title>
<style>
  body { font-family: system-ui; max-width: 560px; margin: 60px auto; padding: 0 1rem; }
  h2   { font-size: 1.2rem; margin-bottom: 1rem; }
  .card { border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1rem; }
  .ok   { border-color: #4ade80; background: #f0fdf4; }
  .err  { border-color: #fca5a5; background: #fef2f2; }
  .info { border-color: #93c5fd; background: #eff6ff; }
  li    { margin: .4rem 0; font-size: .9rem; line-height: 1.5; }
  .creds td { padding: .3rem .75rem; font-size: .88rem; }
  .creds td:first-child { font-weight: 600; color: #6b7280; }
  .warn { background:#fef9c3; border:1px solid #fde047; border-radius:8px; padding:.6rem 1rem; font-size:.82rem; margin-top:1rem; color:#713f12; }
</style>
</head>
<body>

<h2>🧪 Test User Setup</h2>

<?php if ($errors): ?>
<div class="card err">
  <b>Errors:</b>
  <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
</div>
<?php endif; ?>

<?php if ($done): ?>
<div class="card ok">
  <b>Done:</b>
  <ul><?php foreach ($done as $d) echo "<li>$d</li>"; ?></ul>
</div>
<?php endif; ?>

<?php if (!$errors): ?>
<div class="card info">
  <b>Login credentials:</b>
  <table class="creds">
    <tr><td>Username</td><td><?= htmlspecialchars($testUsername) ?></td></tr>
    <tr><td>Password</td><td><?= htmlspecialchars($testPassword) ?></td></tr>
    <tr><td>Email</td><td><?= htmlspecialchars($testEmail) ?></td></tr>
    <tr><td>Department</td><td><?= htmlspecialchars($testDept) ?></td></tr>
    <tr><td>UserType</td><td><?= htmlspecialchars($testUserType) ?></td></tr>
  </table>
</div>
<?php endif; ?>

<div class="warn">
  ⚠️ <b>Delete this file after testing.</b> It inserts directly into the database with no further auth.
  <br><br>
  To clean up, run:<br>
  <code>DELETE FROM users WHERE username = '<?= htmlspecialchars($testUsername) ?>'</code><br>
  <code>DELETE FROM TBL_HREmployeeList WHERE EmployeeID = '<?= htmlspecialchars($testEmployeeID) ?>'</code>
</div>

</body>
</html>
