<?php
/**
 * login.php
 * If already logged in, skip straight past this page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

$redirect = $_GET['redirect'] ?? '';
// Only ever follow a same-site relative path -- never an absolute URL,
// which would make this an open redirect.
if ($redirect !== '' && (strpos($redirect, '://') !== false || strpos($redirect, '//') === 0)) {
    $redirect = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · Tradewell Inventory</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
  .login-body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg, #f4f6f9);
  }
  .login-card {
    width: 100%;
    max-width: 360px;
    background: var(--surface, #fff);
    border: 1px solid var(--line);
    border-radius: 12px;
    box-shadow: 0 20px 50px rgba(27,42,65,0.10);
    padding: 32px 30px 28px;
  }
  .login-card__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
  }
  .login-card__mark {
    width: 34px; height: 34px;
    border-radius: 8px;
    background: var(--accent, #1a8a5f);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px;
  }
  .login-card__brand span { font-weight: 700; font-size: 15px; }
  .login-card h1 { font-size: 17px; margin: 0 0 4px; }
  .login-card p.sub { font-size: 12.5px; color: var(--ink-300); margin: 0 0 22px; }
  .login-field { margin-bottom: 14px; }
  .login-field label { display:block; font-size:12px; font-weight:600; color:var(--ink-700); margin-bottom:6px; }
  .login-field input {
    width: 100%; box-sizing: border-box;
    border: 1px solid var(--line-strong); border-radius: var(--radius, 8px);
    padding: 10px 12px; font-size: 13.5px;
  }
  .login-field input:focus { outline: 2px solid var(--accent-tint, #cdeee1); border-color: var(--accent, #1a8a5f); }
  .login-error {
    display: none;
    background: #fdecec; color: #b3261e; border: 1px solid #f3c6c4;
    border-radius: 8px; padding: 9px 12px; font-size: 12.5px; margin-bottom: 14px;
  }
  .login-submit {
    width: 100%; margin-top: 4px;
    background: var(--accent, #1a8a5f); color: #fff; border: none;
    border-radius: var(--radius, 8px); padding: 11px 0; font-size: 13.5px; font-weight: 600;
    cursor: pointer;
  }
  .login-submit:disabled { opacity: 0.65; cursor: default; }
</style>
</head>
<body class="login-body">

<div class="login-card">
  <div class="login-card__brand">
    <div class="login-card__mark">TW</div>
    <span>Tradewell</span>
  </div>
  <h1>Sign in</h1>
  <p class="sub">Enter your account to continue to the Inventory System.</p>

  <div class="login-error" id="loginError"></div>

  <form id="loginForm">
    <div class="login-field">
      <label for="loginUsername">Username</label>
      <input type="text" id="loginUsername" name="username" autocomplete="username" autofocus required>
    </div>
    <div class="login-field">
      <label for="loginPassword">Password</label>
      <input type="password" id="loginPassword" name="password" autocomplete="current-password" required>
    </div>
    <input type="hidden" id="loginRedirect" value="<?php echo htmlspecialchars($redirect); ?>">
    <button type="submit" class="login-submit" id="loginSubmitBtn">Sign in</button>
  </form>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function (e) {
  e.preventDefault();

  const errorBox = document.getElementById('loginError');
  const submitBtn = document.getElementById('loginSubmitBtn');
  errorBox.style.display = 'none';

  const payload = new FormData();
  payload.append('username', document.getElementById('loginUsername').value.trim());
  payload.append('password', document.getElementById('loginPassword').value);

  submitBtn.disabled = true;
  submitBtn.textContent = 'Signing in...';

  fetch('login_process.php', { method: 'POST', body: payload })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data.error) {
        errorBox.textContent = data.message || 'Could not sign in.';
        errorBox.style.display = '';
        return;
      }
      const redirect = document.getElementById('loginRedirect').value;
      window.location.href = redirect || 'index.php';
    })
    .catch(function (err) {
      console.error(err);
      errorBox.textContent = 'Could not reach the server. Check the connection to TradewellDatabase.';
      errorBox.style.display = '';
    })
    .finally(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Sign in';
    });
});
</script>

</body>
</html>
