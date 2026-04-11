<?php
session_start();
if (isset($_SESSION['UserID'])) {
    header("Location: /TWM/home.php");
    exit();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
include_once __DIR__ . '/test_sqlsrv.php';

$error = "";

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $hashedPassword = md5($password);
$sql    = "SELECT id, username, user_type, Department, DisplayName FROM [dbo].[ViewUserLogIn] WHERE username = ? AND password = ? AND Active = 1";
$params = [$username, $hashedPassword];
$stmt   = sqlsrv_query($conn, $sql, $params);

if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $_SESSION['UserID']      = $row['id'];
    $_SESSION['Username']    = $row['username'];
    $_SESSION['UserType']    = $row['user_type'];
    $_SESSION['Department']  = trim($row['Department']);
    $_SESSION['DisplayName'] = $row['DisplayName'];

    // Look up DepartmentID from Departments table using trimmed name
    $deptName  = trim($row['Department']);
    $deptSql   = "SELECT DepartmentID FROM Departments WHERE LTRIM(RTRIM(DepartmentName)) = ? AND Status = 1";
    $deptStmt  = sqlsrv_query($conn, $deptSql, [$deptName]);
    $deptRow   = $deptStmt ? sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC) : null;
    $_SESSION['DepartmentID'] = $deptRow ? (int)$deptRow['DepartmentID'] : null;
    if ($deptStmt) sqlsrv_free_stmt($deptStmt);

    redirect('home');
    } else {
        $error = "Invalid Username or Password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login</title>

  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/img/apple-touch-icon.png') ?>" rel="apple-touch-icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <!-- ✅ FIX: base_url() for asset path -->
  <script src="<?= base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>

  <style>
    :root {
      --blue-deep:   #08173d;
      --blue-mid:    #1a2f6b;
      --blue-main:   #1e40af;
      --blue-bright: #4380e2;
      --blue-light:  #93c5fd;
      --blue-glow:   rgba(67,128,226,0.25);
      --white:       #ffffff;
      --white-10:    rgba(255,255,255,0.10);
      --white-15:    rgba(255,255,255,0.15);
      --white-25:    rgba(255,255,255,0.25);
      --white-60:    rgba(255,255,255,0.60);
      --white-80:    rgba(255,255,255,0.80);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      overflow: hidden;
    }

    .bg {
      position: fixed; inset: 0;
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      z-index: 0;
    }
    .orb { position: absolute; border-radius: 50%; animation: drift linear infinite; }
    .orb-1 { width:420px;height:420px;top:-160px;left:-140px;background:transparent;border:1.5px solid rgba(255,255,255,.1);animation-duration:22s; }
    .orb-2 { width:260px;height:260px;top:-60px;left:-60px;background:transparent;border:1px solid rgba(255,255,255,.07);animation-duration:18s;animation-direction:reverse; }
    .orb-3 { width:380px;height:380px;bottom:-140px;right:-120px;background:radial-gradient(circle at 40% 40%,rgba(67,128,226,.22),transparent 70%);border:1.5px solid rgba(255,255,255,.08);animation-duration:26s; }
    .orb-4 { width:160px;height:160px;top:38%;right:8%;background:transparent;border:1px solid rgba(147,197,253,.15);animation-duration:14s;animation-direction:reverse; }
    .orb-5 { width:80px;height:80px;bottom:18%;left:6%;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);animation-duration:20s; }
    @keyframes drift {
      0%,100% { transform:translate(0,0) rotate(0deg); }
      33%      { transform:translate(20px,-25px) rotate(5deg); }
      66%      { transform:translate(-15px,18px) rotate(-4deg); }
    }

    .page {
      position: relative; z-index: 10;
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; padding: 1.5rem;
    }

    .login-card {
      width: 100%; max-width: 420px;
      background: rgba(255,255,255,0.07);
      border: 1px solid var(--white-15);
      border-radius: 24px;
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      box-shadow:
        0 0 0 1px rgba(255,255,255,.06) inset,
        0 32px 80px rgba(8,23,61,.5),
        0 4px 16px rgba(0,0,0,.2);
      padding: 2.5rem 2.25rem 2rem;
      animation: cardIn .6s cubic-bezier(.22,.68,0,1.2) both;
    }
    @keyframes cardIn {
      from { opacity:0; transform:translateY(28px) scale(.97); }
      to   { opacity:1; transform:translateY(0) scale(1); }
    }

    .card-top {
      text-align: center; margin-bottom: 2rem;
      animation: fadeUp .5s .15s ease both;
    }
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(12px); }
      to   { opacity:1; transform:translateY(0); }
    }

    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 72px; height: 72px; border-radius: 50%;
      background: var(--white-10); border: 1px solid var(--white-25);
      margin-bottom: 1.1rem;
      box-shadow: 0 8px 24px rgba(0,0,0,.2);
    }
    .logo-ring img { width: 46px; height: 46px; object-fit: contain; }

    .card-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.5rem; font-weight: 800;
      color: var(--white); letter-spacing: -.04em; line-height: 1.15;
    }
    .card-subtitle {
      font-size: .82rem; color: var(--white-60);
      margin-top: .35rem; font-weight: 400; letter-spacing: .01em;
    }

    .form-group { margin-bottom: 1.1rem; animation: fadeUp .5s ease both; }
    .form-group:nth-child(1) { animation-delay: .2s; }
    .form-group:nth-child(2) { animation-delay: .28s; }
    .form-group:nth-child(3) { animation-delay: .36s; }

    .field-label {
      display: block; font-size: .73rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .09em;
      color: var(--white-60); margin-bottom: .45rem;
    }

    .field-wrap { position: relative; display: flex; align-items: center; }
    .field-icon {
      position: absolute; left: .9rem;
      color: var(--white-60); font-size: .95rem;
      pointer-events: none; z-index: 2;
    }
    .field-input {
      width: 100%;
      background: var(--white-10);
      border: 1.5px solid var(--white-15);
      border-radius: 12px;
      padding: .7rem 1rem .7rem 2.6rem;
      font-family: 'DM Sans', sans-serif;
      font-size: .9rem; font-weight: 500;
      color: var(--white); outline: none;
      transition: border-color .2s, background .2s, box-shadow .2s;
    }
    .field-input::placeholder { color: var(--white-25); font-weight: 400; }
    .field-input:focus {
      border-color: var(--blue-light);
      background: var(--white-15);
      box-shadow: 0 0 0 3px var(--blue-glow);
    }
    .field-input:-webkit-autofill,
    .field-input:-webkit-autofill:focus {
      -webkit-box-shadow: 0 0 0 1000px rgba(30,64,175,.6) inset;
      -webkit-text-fill-color: #fff;
      caret-color: #fff;
    }

    .toggle-pw {
      position: absolute; right: .75rem;
      background: none; border: none;
      color: var(--white-60); cursor: pointer;
      padding: .25rem; font-size: .95rem;
      transition: color .15s; z-index: 2;
    }
    .toggle-pw:hover { color: var(--white); }

    .btn-login {
      width: 100%; margin-top: .5rem; padding: .8rem 1rem;
      background: linear-gradient(135deg, var(--blue-bright) 0%, var(--blue-main) 100%);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 12px;
      font-family: 'Sora', sans-serif;
      font-size: .9rem; font-weight: 700;
      color: var(--white); letter-spacing: .02em;
      cursor: pointer; transition: all .2s;
      position: relative; overflow: hidden;
      box-shadow: 0 4px 20px rgba(67,128,226,.4);
      animation: fadeUp .5s .42s ease both;
    }
    .btn-login::before {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg,rgba(255,255,255,.15),transparent);
      opacity: 0; transition: opacity .2s;
    }
    .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(67,128,226,.55); }
    .btn-login:hover::before { opacity: 1; }
    .btn-login:active { transform: translateY(0); }

    .back-wrap {
      text-align: center; margin-top: 1.5rem;
      animation: fadeUp .5s .5s ease both;
    }
    .back-link {
      display: inline-flex; align-items: center; gap: .35rem;
      font-size: .8rem; font-weight: 600;
      color: var(--white-60); text-decoration: none;
      transition: color .15s, transform .15s;
    }
    .back-link:hover { color: var(--white); transform: translateX(-3px); }

    .card-divider { height: 1px; background: var(--white-10); margin: 1.5rem 0 1.25rem; }

    @media (max-width: 480px) {
      .login-card { padding: 2rem 1.4rem 1.5rem; border-radius: 20px; }
      .card-title { font-size: 1.3rem; }
      .logo-ring { width: 60px; height: 60px; border-radius: 16px; }
      .logo-ring img { width: 38px; height: 38px; }
    }
  </style>
</head>

<body>

<div class="bg">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
  <div class="orb orb-4"></div>
  <div class="orb orb-5"></div>
</div>

<div class="page">
  <div style="width:100%;max-width:420px;">

    <div class="login-card">

      <div class="card-top">
        <div class="logo-ring">
          <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo"
               onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-briefcase-fill\' style=\'font-size:1.6rem;color:#fff;\'></i>';">
        </div>
        <div class="card-title">Admin Portal</div>
        <div class="card-subtitle">Urban Tradewell Corporation</div>
      </div>

      <form method="POST" autocomplete="off">

        <div class="form-group">
          <label class="field-label" for="username">Username</label>
          <div class="field-wrap">
            <i class="bi bi-person-fill field-icon"></i>
            <input type="text" name="username" id="username" class="field-input"
                   placeholder="Enter your username" required autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label class="field-label" for="passwordField">Password</label>
          <div class="field-wrap">
            <i class="bi bi-lock-fill field-icon"></i>
            <input type="password" name="password" id="passwordField" class="field-input"
                   placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="toggle-pw" id="togglePassword"
                    tabindex="-1" aria-label="Toggle password">
              <i class="bi bi-eye-slash-fill" id="toggleIcon"></i>
            </button>
          </div>
        </div>

        <div class="form-group">
          <button type="submit" name="login" class="btn-login">
            Sign In &nbsp;<i class="bi bi-arrow-right-short" style="font-size:1.1rem;vertical-align:middle;"></i>
          </button>
        </div>

      </form>

      <div class="card-divider"></div>

      <div class="back-wrap">
        <!-- ✅ FIX: route() instead of hardcoded path -->
        <a href="<?= route('careers') ?>" class="back-link">
          <i class="bi bi-arrow-left"></i> Back to Website
        </a>
      </div>

    </div>
    <!-- /login-card -->

  </div>
</div>

<?php if ($error): ?>
<script>
  Swal.fire({
    icon: 'error',
    title: 'Login Failed',
    text: '<?= addslashes($error) ?>',
    confirmButtonColor: '#4380e2',
    background: '#0f172a',
    color: '#fff',
    iconColor: '#ef4444',
    timer: 4000,
    showConfirmButton: true,
    confirmButtonText: 'Try Again',
  });
</script>
<?php endif; ?>

<script>
  const togglePassword = document.getElementById('togglePassword');
  const passwordField  = document.getElementById('passwordField');
  const toggleIcon     = document.getElementById('toggleIcon');

  togglePassword.addEventListener('click', function () {
    const isHidden = passwordField.getAttribute('type') === 'password';
    passwordField.setAttribute('type', isHidden ? 'text' : 'password');
    toggleIcon.className = isHidden ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
  });

   // Auto redirect to home if session becomes active
  setInterval(() => {
    fetch('/TWM/check_session.php')
      .then(res => res.json())
      .then(data => {
        if (data.loggedIn) {
          window.location.href = '/TWM/home.php';
        }
      });
  }, 2000);
</script>

</body>
</html>