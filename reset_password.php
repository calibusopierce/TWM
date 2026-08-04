<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (isset($_SESSION['UserID'])) {
    header("Location: " . route('home'));
    exit();
}

include_once __DIR__ . '/test_sqlsrv.php';

$error       = "";
$success     = "";
$tokenValid  = false;
$employeeID  = null;

// Token can arrive via the emailed link (GET) or be resubmitted with the form (POST)
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if (empty($token)) {

    $error = "This password reset link is invalid.";

} else {

    $tokenHash = hash('sha256', $token);

    $sql  = "SELECT EmployeeID, token_expired_date FROM users WHERE token_key = ?";
    $stmt = sqlsrv_query($conn, $sql, [$tokenHash]);

    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if (!$row) {

        $error = "This password reset link is invalid or has already been used.";

    } else {

        $expiresAt = $row['token_expired_date'];
        $expiryOk  = false;

        if ($expiresAt instanceof DateTime) {
            $expiryOk = $expiresAt->getTimestamp() >= time();
        } elseif (is_string($expiresAt)) {
            $expiryOk = strtotime($expiresAt) >= time();
        }

        if (!$expiryOk) {
            $error = "This password reset link has expired. Please request a new one.";
        } else {
            $tokenValid = true;
            $employeeID = $row['EmployeeID'];
        }
    }
}

if (empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(32));
}

if ($tokenValid && isset($_POST['ResetSubmit'])) {

    if (!hash_equals($_SESSION['reset_csrf'], $_POST['csrf_token'] ?? '')) {

        $error = "Your session expired. Please refresh the page and try again.";

    } else {

        $password1 = $_POST['password1'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (strlen($password1) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($password1 !== $password2) {
            $error = "Passwords do not match.";
        } else {

            // NOTE: kept as md5() to stay compatible with the existing TWM login
            // (login.php still checks md5($password) against ViewUserLogIn).
            // Recommend migrating both login.php and this hash to password_hash()/
            // password_verify() in a follow-up pass — flagging here, not changing
            // now since that touches the login flow too.
            $hashedPassword = md5($password1);

            $updateSql  = "UPDATE users SET password = ?, token_key = NULL, token_expired_date = NULL WHERE EmployeeID = ?";
            $updateStmt = sqlsrv_query($conn, $updateSql, [$hashedPassword, $employeeID]);

            if ($updateStmt === false) {
                die(print_r(sqlsrv_errors(), true));
            }

            $success    = "Your password has been reset successfully. You may now log in.";
            $tokenValid = false; // hide the form after a successful reset
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password</title>

  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/img/apple-touch-icon.png') ?>" rel="apple-touch-icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
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
      width: 100%; max-width: 440px;
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

    .card-top { text-align: center; margin-bottom: 1.75rem; }

    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 64px; height: 64px; border-radius: 50%;
      background: var(--white-10); border: 1px solid var(--white-25);
      margin-bottom: 1rem;
      box-shadow: 0 8px 24px rgba(0,0,0,.2);
    }
    .logo-ring i { font-size: 1.5rem; color: #fff; }

    .card-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.4rem; font-weight: 800;
      color: var(--white); letter-spacing: -.03em; line-height: 1.15;
    }
    .card-subtitle {
      font-size: .82rem; color: var(--white-60);
      margin-top: .5rem; font-weight: 400; line-height: 1.5;
    }

    .alert-box {
      border-radius: 12px;
      padding: .75rem 1rem;
      font-size: .82rem;
      margin-bottom: 1.1rem;
      border: 1px solid;
    }
    .alert-error {
      background: rgba(239,68,68,.12);
      border-color: rgba(239,68,68,.35);
      color: #fecaca;
    }
    .alert-success {
      background: rgba(34,197,94,.12);
      border-color: rgba(34,197,94,.35);
      color: #bbf7d0;
    }

    .form-group { margin-bottom: 1.1rem; }

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

    .toggle-pw {
      position: absolute; right: .75rem;
      background: none; border: none;
      color: var(--white-60); cursor: pointer;
      padding: .25rem; font-size: .95rem;
      z-index: 2;
    }
    .toggle-pw:hover { color: var(--white); }

    .pw-hint {
      font-size: .72rem; color: var(--white-60);
      margin-top: .4rem;
    }

    .btn-login {
      width: 100%; margin-top: .5rem; padding: .8rem 1rem;
      background: linear-gradient(135deg, var(--blue-bright) 0%, var(--blue-main) 100%);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 12px;
      font-family: 'Sora', sans-serif;
      font-size: .9rem; font-weight: 700;
      color: var(--white); letter-spacing: .02em;
      cursor: pointer; transition: all .2s;
      box-shadow: 0 4px 20px rgba(67,128,226,.4);
    }
    .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(67,128,226,.55); }
    .btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }

    .back-wrap { text-align: center; margin-top: 1.5rem; }
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
      .card-title { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

<div class="bg">
  <div class="orb orb-1"></div>
  <div class="orb orb-2"></div>
  <div class="orb orb-3"></div>
</div>

<div class="page">
  <div style="width:100%;max-width:440px;">
    <div class="login-card">

      <div class="card-top">
        <div class="logo-ring">
          <i class="bi bi-shield-lock-fill"></i>
        </div>
        <div class="card-title">Reset Password</div>
        <div class="card-subtitle">Choose a new password for your account.</div>
      </div>

      <?php if ($error): ?>
        <div class="alert-box alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert-box alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if ($tokenValid): ?>
      <form method="POST" id="resetForm" autocomplete="off">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['reset_csrf']) ?>">

        <div class="form-group">
          <label class="field-label" for="password1">New Password</label>
          <div class="field-wrap">
            <i class="bi bi-lock-fill field-icon"></i>
            <input type="password" name="password1" id="password1" class="field-input"
                   placeholder="Enter new password" required minlength="8" autocomplete="new-password">
            <button type="button" class="toggle-pw" data-target="password1" aria-label="Toggle password">
              <i class="bi bi-eye-slash-fill"></i>
            </button>
          </div>
          <div class="pw-hint">At least 8 characters.</div>
        </div>

        <div class="form-group">
          <label class="field-label" for="password2">Confirm New Password</label>
          <div class="field-wrap">
            <i class="bi bi-lock-fill field-icon"></i>
            <input type="password" name="password2" id="password2" class="field-input"
                   placeholder="Re-enter new password" required minlength="8" autocomplete="new-password">
            <button type="button" class="toggle-pw" data-target="password2" aria-label="Toggle password">
              <i class="bi bi-eye-slash-fill"></i>
            </button>
          </div>
        </div>

        <div class="form-group">
          <button type="submit" name="ResetSubmit" class="btn-login" id="submitBtn">
            <span class="btn-text">Reset Password &nbsp;<i class="bi bi-arrow-right-short" style="font-size:1.1rem;vertical-align:middle;"></i></span>
          </button>
        </div>
      </form>
      <?php endif; ?>

      <div class="card-divider"></div>

      <div class="back-wrap">
        <a href="<?= route('login') ?>" class="back-link">
          <i class="bi bi-arrow-left"></i> Back to Login
        </a>
      </div>

    </div>
  </div>
</div>

<script>
  document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const field = document.getElementById(btn.dataset.target);
      const icon  = btn.querySelector('i');
      const isHidden = field.getAttribute('type') === 'password';
      field.setAttribute('type', isHidden ? 'text' : 'password');
      icon.className = isHidden ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
    });
  });

  const resetForm = document.getElementById('resetForm');
  if (resetForm) {
    resetForm.addEventListener('submit', function (e) {
      const p1 = document.getElementById('password1').value;
      const p2 = document.getElementById('password2').value;
      if (p1 !== p2) {
        e.preventDefault();
        Swal.fire({
          icon: 'error',
          title: 'Passwords do not match',
          confirmButtonColor: '#4380e2',
          background: '#0f172a',
          color: '#fff',
        });
        return;
      }
      const btn = document.getElementById('submitBtn');
      // Deferred for the same reason as forgot_password.php — disabling
      // synchronously inside 'submit' can cause the browser to cancel
      // the POST before it's sent.
      setTimeout(function () {
        btn.disabled = true;
        btn.querySelector('.btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Resetting...';
      }, 0);
    });
  }
</script>

</body>
</html>