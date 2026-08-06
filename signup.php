<?php 
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
include('validation.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up</title>

  <link href="img/utc.png" rel="icon">
  <link href="assets/img/apple-touch-icon.png" rel="apple-touch-icon">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
      min-height: 100%;
      font-family: 'DM Sans', sans-serif;
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
      display: flex; align-items: flex-start; justify-content: center;
      min-height: 100vh; padding: 0.5rem 1.5rem;
    }

    .signup-card {
      width: 100%; max-width: 620px;
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
      text-align: center; margin-bottom: 1.1rem;
      animation: fadeUp .5s .15s ease both;
    }
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(12px); }
      to   { opacity:1; transform:translateY(0); }
    }

    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 48px; height: 48px; border-radius: 50%;
      background: var(--white-10); border: 1px solid var(--white-25);
      margin-bottom: 0.6rem;
      box-shadow: 0 8px 24px rgba(0,0,0,.2);
      overflow: hidden;
    }
    .logo-ring img { width: 30px; height: 30px; object-fit: contain; }

    .card-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.5rem; font-weight: 800;
      color: var(--white); letter-spacing: -.03em; line-height: 1.15;
    }
    .card-subtitle {
      font-size: .82rem; color: var(--white-60);
      margin-top: .4rem; font-weight: 400; letter-spacing: .01em;
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

    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0 1rem;
    }
    .form-grid .form-group.full { grid-column: 1 / -1; }

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

    .field-error {
      display: block;
      font-size: .72rem;
      color: #fca5a5;
      margin-top: .35rem;
      min-height: 1em;
    }

    .btn-signup {
      width: 100%; margin-top: .5rem; padding: .8rem 1rem;
      background: linear-gradient(135deg, var(--blue-bright) 0%, var(--blue-main) 100%);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 12px;
      font-family: 'Sora', sans-serif;
      font-size: .9rem; font-weight: 700;
      color: var(--white); letter-spacing: .02em;
      cursor: pointer; transition: all .2s;
      box-shadow: 0 4px 20px rgba(67,128,226,.4);
      animation: fadeUp .5s .42s ease both;
    }
    .btn-signup:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(67,128,226,.55); }
    .btn-signup:active { transform: translateY(0); }

    .login-row {
      text-align: center;
      font-size: .8rem;
      color: var(--white-60);
      margin-top: 1.25rem;
      animation: fadeUp .5s .46s ease both;
    }
    .login-link {
      color: var(--blue-light);
      font-weight: 700;
      text-decoration: none;
      margin-left: .3rem;
      transition: color .15s;
    }
    .login-link:hover { color: var(--white); text-decoration: underline; }

    .card-divider { height: 1px; background: var(--white-10); margin: 1.5rem 0 1.25rem; }

    .back-wrap { text-align: center; animation: fadeUp .5s .5s ease both; }
    .back-link {
      display: inline-flex; align-items: center; gap: .35rem;
      font-size: .8rem; font-weight: 600;
      color: var(--white-60); text-decoration: none;
      transition: color .15s, transform .15s;
    }
    .back-link:hover { color: var(--white); transform: translateX(-3px); }

    @media (max-width: 620px) {
      .form-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
      .signup-card { padding: 2rem 1.4rem 1.5rem; border-radius: 20px; }
      .card-title { font-size: 1.3rem; }
      .logo-ring { width: 56px; height: 56px; }
      .logo-ring img { width: 34px; height: 34px; }
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
  <div style="width:100%;max-width:620px;">

    <div class="signup-card">

      <div class="card-top">
        <div class="logo-ring">
          <img src="img/utc.png" alt="Logo"
               onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-person-plus-fill\' style=\'font-size:1.5rem;color:#fff;\'></i>';">
        </div>
        <div class="card-title">Create Account</div>
        <div class="card-subtitle">Urban Tradewell Corporation</div>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert-box alert-error"><?= $error ?></div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert-box alert-success"><?= $success ?></div>
      <?php endif; ?>

      <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" autocomplete="off">

        <div class="form-grid">

          <div class="form-group full">
            <label class="field-label" for="EID">Employee ID</label>
            <div class="field-wrap">
              <i class="bi bi-person-badge-fill field-icon"></i>
              <input type="text" name="EID" id="EID" class="field-input" placeholder="Employee ID" required>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label" for="firstName">First Name</label>
            <div class="field-wrap">
              <i class="bi bi-person-fill field-icon"></i>
              <input type="text" name="firstName" id="firstName" class="field-input" placeholder="First Name" required>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label" for="lastName">Last Name</label>
            <div class="field-wrap">
              <i class="bi bi-person-fill field-icon"></i>
              <input type="text" name="lastName" id="lastName" class="field-input" placeholder="Last Name" required>
            </div>
          </div>

          <div class="form-group full">
            <label class="field-label" for="email">Email</label>
            <div class="field-wrap">
              <i class="bi bi-envelope-fill field-icon"></i>
              <input type="email" name="email" id="email" class="field-input" placeholder="you@example.com" required>
            </div>
          </div>

          <div class="form-group full">
            <label class="field-label" for="username">Username</label>
            <div class="field-wrap">
              <i class="bi bi-at field-icon"></i>
              <input type="text" name="username" id="username" class="field-input" placeholder="Username" required>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label" for="password">Password</label>
            <div class="field-wrap">
              <i class="bi bi-lock-fill field-icon"></i>
              <input type="password" name="password_1" id="password" class="field-input"
                     placeholder="Password" oninput="validatePassword()" required>
              <button type="button" class="toggle-pw" data-target="password" aria-label="Toggle password">
                <i class="bi bi-eye-slash-fill"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label" for="confirmPassword">Confirm Password</label>
            <div class="field-wrap">
              <i class="bi bi-lock-fill field-icon"></i>
              <input type="password" name="password_2" id="confirmPassword" class="field-input"
                     placeholder="Confirm Password" oninput="validatePassword()" required>
              <button type="button" class="toggle-pw" data-target="confirmPassword" aria-label="Toggle password">
                <i class="bi bi-eye-slash-fill"></i>
              </button>
            </div>
            <small class="field-error" id="passwordErrorMessage"></small>
          </div>

        </div>

        <button type="submit" name="SignUp_btn" class="btn-signup">
          Register &nbsp;<i class="bi bi-arrow-right-short" style="font-size:1.1rem;vertical-align:middle;"></i>
        </button>

      </form>

      <div class="login-row">
        Already have an account?
        <a href="<?= route('login') ?>" class="login-link">Log In</a>
      </div>

      <div class="card-divider"></div>

      <div class="back-wrap">
        <a href="https://122.52.195.3/" class="back-link">
          <i class="bi bi-arrow-left"></i> Back to Website
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

  function validatePassword() {
    var password = document.getElementById("password").value;
    var confirmPassword = document.getElementById("confirmPassword").value;
    var passwordErrorMessage = document.getElementById("passwordErrorMessage");

    if (confirmPassword && password !== confirmPassword) {
      passwordErrorMessage.innerText = "Passwords do not match.";
    } else {
      passwordErrorMessage.innerText = "";
    }
  }
</script>

</body>
</html>