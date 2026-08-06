<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// Already logged in? go home.
if (isset($_SESSION['UserID'])) {
    header("Location: " . route('home'));
    exit();
}

include_once __DIR__ . '/test_sqlsrv.php';

$error   = "";
$success = "";

// CSRF token for this form
if (empty($_SESSION['forgot_csrf'])) {
    $_SESSION['forgot_csrf'] = bin2hex(random_bytes(32));
}

if (isset($_POST['Forgotsubmit'])) {

    if (!hash_equals($_SESSION['forgot_csrf'], $_POST['csrf_token'] ?? '')) {

        $error = "Your session expired. Please refresh the page and try again.";

    } else {

        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

            $error = "Please enter a valid email address.";

        } elseif (isset($_SESSION['forgot_last_request']) && (time() - $_SESSION['forgot_last_request']) < 60) {

            // Basic anti-spam cooldown, 60s between requests per browser session
            $error = "Please wait a minute before requesting another reset link.";

        } else {

            // Parameterized query (fixes SQL injection present in legacy version)
            $sql  = "SELECT EmployeeID, FirstName, LastName FROM TBL_HREmployeeList WHERE Email_Address = ?";
            $stmt = sqlsrv_query($conn, $sql, [$email]);

            if ($stmt === false) {
                die(print_r(sqlsrv_errors(), true));
            }

            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

            if ($row) {

                date_default_timezone_set('Asia/Manila');

                $employeeID = $row['EmployeeID'];
                $fullname   = trim($row['FirstName'] . ' ' . $row['LastName']);

                // Generate token. Only the SHA-256 hash is stored (legacy stored it in plaintext).
                $token      = bin2hex(random_bytes(32));
                $tokenHash  = hash('sha256', $token);
                $expiresAt  = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                $updateSql  = "UPDATE users SET token_key = ?, token_expired_date = ? WHERE EmployeeID = ?";
                $updateStmt = sqlsrv_query($conn, $updateSql, [$tokenHash, $expiresAt, $employeeID]);

                if ($updateStmt === false) {
                    die(print_r(sqlsrv_errors(), true));
                }

                $link = base_url('reset_password.php?token=' . $token);

                $subject     = "TWM Password Reset";
                $htmlMessage = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset Your Password</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table width="500" cellpadding="20" cellspacing="0" style="background-color: #ffffff; border-radius: 8px;">
                    <tr>
                        <td align="center">
                            <h2 style="color: #08173d;">Password Reset Request.</h2>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <p>Hello, <strong>{$fullname}</strong></p>
                            <p>We received a request to reset your Tradewell Management System password. Click the button below to create a new password. This link expires in 30 minutes.</p>
                            <p style="text-align: center;">
                                <a href="{$link}"
                                   style="background-color: #1e40af; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 5px; display:inline-block;">
                                   Reset Password
                                </a>
                            </p>
                            <p>If you did not request a password reset, you can safely ignore this email — your password will not be changed.</p>
                            <p>Thank you,<br>Urban Tradewell Corporation</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                $headers .= "From: hr.tradewell@gmail.com\r\n";

                @mail($email, $subject, $htmlMessage, $headers);
            }

            // Same message whether or not the email exists — prevents attackers
            // from using this form to discover which emails are registered.
            $_SESSION['forgot_last_request'] = time();
            $success = "If that email address is on file, a password reset link has been sent. Please check your inbox (and spam folder).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password</title>

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

    .card-top {
      text-align: center; margin-bottom: 1.75rem;
      animation: fadeUp .5s .15s ease both;
    }
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(12px); }
      to   { opacity:1; transform:translateY(0); }
    }

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
      margin-top: .5rem; font-weight: 400; letter-spacing: .01em;
      line-height: 1.5;
    }

    .alert-box {
      border-radius: 12px;
      padding: .75rem 1rem;
      font-size: .82rem;
      margin-bottom: 1.1rem;
      border: 1px solid;
      animation: fadeUp .4s ease both;
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

    .form-group { margin-bottom: 1.1rem; animation: fadeUp .5s ease both; }

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
      animation: fadeUp .5s .3s ease both;
    }
    .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(67,128,226,.55); }
    .btn-login:active { transform: translateY(0); }
    .btn-login:disabled { opacity: .6; cursor: not-allowed; transform: none; }

    .back-wrap {
      text-align: center; margin-top: 1.5rem;
      animation: fadeUp .5s .4s ease both;
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
      .card-title { font-size: 1.2rem; }
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
  <div style="width:100%;max-width:440px;">
    <div class="login-card">

      <div class="card-top">
        <div class="logo-ring">
          <i class="bi bi-key-fill"></i>
        </div>
        <div class="card-title">Forgot Password</div>
        <div class="card-subtitle">Enter your registered email address and we'll send you a link to reset your password.</div>
      </div>

      <?php if ($error): ?>
        <div class="alert-box alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert-box alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" id="forgotForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['forgot_csrf']) ?>">

        <div class="form-group">
          <label class="field-label" for="email">Email Address</label>
          <div class="field-wrap">
            <i class="bi bi-envelope-fill field-icon"></i>
            <input type="email" name="email" id="email" class="field-input"
                   placeholder="you@example.com" required autocomplete="email">
          </div>
        </div>

        <div class="form-group">
          <button type="submit" name="Forgotsubmit" class="btn-login" id="submitBtn">
            <span class="btn-text">Send Reset Link &nbsp;<i class="bi bi-arrow-right-short" style="font-size:1.1rem;vertical-align:middle;"></i></span>
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
  const forgotForm = document.getElementById('forgotForm');
  if (forgotForm) {
    forgotForm.addEventListener('submit', function () {
      const btn = document.getElementById('submitBtn');
      // Defer disabling the button — disabling it synchronously inside the
      // submit event can cause some browsers to cancel the form submission
      // entirely (the button becomes disabled before the POST is sent).
      setTimeout(function () {
        btn.disabled = true;
        btn.querySelector('.btn-text').innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Sending...';
      }, 0);
    });
  }
</script>

</body>
</html>
