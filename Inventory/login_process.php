<?php
/**
 * login_process.php
 * Authenticates against dbo.ViewUserLogIn. Passwords in that view are
 * stored as MD5 hashes, so the submitted password is hashed the same
 * way before comparing -- never compared or logged in plain text.
 *
 * POST fields:
 *   username (string)
 *   password (string)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => true, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/includes/config.php';

$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    echo json_encode(['error' => true, 'message' => 'Enter your username and password.']);
    exit;
}

$conn = getConnection();

$sql = "SELECT id, username, email, user_type, password, EmployeeID, Department,
               DisplayName, Active, Job_tittle, Category, Position_held, FileNo
        FROM dbo.ViewUserLogIn
        WHERE username = ?";

$stmt = sqlsrv_query($conn, $sql, [$username]);

if ($stmt === false) {
    echo json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]);
    closeConnection($conn);
    exit;
}

$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
closeConnection($conn);

// Deliberately vague on a bad username/password so we don't help
// anyone enumerate valid accounts.
$genericError = 'Incorrect username or password.';

if (!$user) {
    echo json_encode(['error' => true, 'message' => $genericError]);
    exit;
}

$submittedHash = md5($password);
$storedHash    = (string)($user['password'] ?? '');

if (strcasecmp($submittedHash, $storedHash) !== 0) {
    echo json_encode(['error' => true, 'message' => $genericError]);
    exit;
}

// Password's correct at this point, so it's safe to be specific here
// -- this doesn't leak anything an attacker couldn't already tell.
if (isset($user['Active']) && !$user['Active']) {
    echo json_encode(['error' => true, 'message' => 'This account is inactive. Contact your administrator.']);
    exit;
}

session_regenerate_id(true);

$_SESSION['logged_in']    = true;
$_SESSION['user_id']      = $user['id'];
$_SESSION['username']     = $user['username'];
$_SESSION['display_name'] = $user['DisplayName'] ?: $user['username'];
$_SESSION['user_type']    = $user['user_type'];
$_SESSION['department']   = $user['Department'];
$_SESSION['category']     = $user['Category'];
$_SESSION['employee_id']  = $user['EmployeeID'];

echo json_encode(['error' => false, 'message' => 'Signed in.']);
