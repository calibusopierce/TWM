<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'auth_check.php';
include 'test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR', 'Delivery', 'Logistic']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$current     = $_SESSION['Department']  ?? '';
// Pull master dept list directly from DB so names always match exactly
$allDepts = [];
if ($pdo) {
    $allDepts = $pdo->query("
        SELECT DISTINCT Department FROM ViewUserLogIn
        WHERE Department IS NOT NULL AND Department != ''
        ORDER BY Department ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
}
// Fallback if DB fails
if (empty($allDepts)) {
    $allDepts = ['Monde', 'Century', 'Multilines', 'NutriAsia', 'Silverswan', 'Urban Tradewell Corp.'];
}


// ── Build allowed list — NO role bypass, everyone goes through DB tags ────────
if ($pdo) {
    $userId = $_SESSION['UserID'] ?? 0;
    $stmt   = $pdo->prepare("
        SELECT Department
        FROM   Tbl_UserAccessDepartment
        WHERE  UserID = ?
        ORDER  BY Department ASC
    ");
    $stmt->execute([$userId]);
    $taggedDepts = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Names come from DB so they already match — no intersect filter needed
    $allowed = $taggedDepts;
} else {
    // No DB — fall back to session department only
    $allowed = $current !== '' ? [$current] : [];
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dept'])) {
    $dept = trim($_POST['dept']);

    if (in_array($dept, $allowed, true)) {
        $_SESSION['Department'] = $dept;
        header('Location: home.php');
        exit();
    }
}

$deptColors = [
    'Monde'      => ['bg' => 'rgba(239,68,68,.15)',   'color' => '#ef4444', 'border' => '#fca5a5', 'solid' => '#ef4444'],
    'Century'    => ['bg' => 'rgba(59,130,246,.15)',  'color' => '#3b82f6', 'border' => '#93c5fd', 'solid' => '#3b82f6'],
    'Multilines' => ['bg' => 'rgba(234,179,8,.15)',   'color' => '#ca8a04', 'border' => '#fde047', 'solid' => '#eab308'],
    'NutriAsia'  => ['bg' => 'rgba(16,185,129,.15)',  'color' => '#059669', 'border' => '#6ee7b7', 'solid' => '#10b981'],
    'Silverswan' => ['bg' => 'rgba(99,102,241,.15)',  'color' => '#6366f1', 'border' => '#a5b4fc', 'solid' => '#6366f1'],
    'Urban Tradewell Corp.' => ['bg' => 'rgba(28, 61, 126, 0.15)', 'color' => '#0b2b6d', 'border' => '#113472', 'solid' => '#0b2863'],
    ''           => ['bg' => 'rgba(107,114,128,.15)', 'color' => '#6b7280', 'border' => '#9ca3af', 'solid' => '#6b7280'],
];

// ── Build display options — always from $allowed ──────────────────────────────
$displayOptions = [];
foreach ($allowed as $d) {
    $displayOptions[$d] = $d !== '' ? $d : 'All Departments';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set Department · Tradewell</title>
  <link href="assets/img/logo.png" rel="icon">
  <link href="assets/vendor/fonts/fonts.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root {
      --blue-deep:   #08173d;
      --blue-bright: #4380e2;
      --blue-main:   #1e40af;
      --white:       #ffffff;
      --white-10:    rgba(255,255,255,0.10);
      --white-15:    rgba(255,255,255,0.15);
      --white-25:    rgba(255,255,255,0.25);
      --white-60:    rgba(255,255,255,0.60);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      width: 100%;
      font-family: 'DM Sans', sans-serif;
      overflow: hidden;
    }

    .bg {
      position: fixed;
      inset: 0;
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      overflow: hidden;
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
      position: relative;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
      -webkit-overflow-scrolling: touch;
      padding: 1.5rem;
    }

    .card {
      width: 100%; max-width: 620px;
      background: rgba(255,255,255,0.07);
      border: 1px solid var(--white-15);
      border-radius: 24px;
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      box-shadow: 0 0 0 1px rgba(255,255,255,.06) inset, 0 32px 80px rgba(8,23,61,.5);
      padding: 2.25rem 2rem 2rem;
      animation: cardIn .5s cubic-bezier(.22,.68,0,1.2) both;
    }
    @keyframes cardIn {
      from { opacity:0; transform:translateY(24px) scale(.97); }
      to   { opacity:1; transform:translateY(0) scale(1); }
    }

    .card-top { text-align: center; margin-bottom: 1.75rem; }
    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 64px; height: 64px; border-radius: 50%;
      background: var(--white-10); border: 1px solid var(--white-25);
      margin-bottom: .85rem; box-shadow: 0 8px 24px rgba(0,0,0,.2);
    }
    .logo-ring img { width: 40px; height: 40px; object-fit: contain; }
    .card-title { font-family: 'Sora', sans-serif; font-size: 1.3rem; font-weight: 800; color: var(--white); letter-spacing: -.03em; }
    .card-subtitle { font-size: .82rem; color: var(--white-60); margin-top: .3rem; }

    .current-dept {
      display: flex; align-items: center; justify-content: center;
      gap: .4rem; margin-bottom: 1.5rem;
      font-size: .78rem; color: var(--white-60);
    }
    .current-dept strong { color: var(--white); font-size: .85rem; }

    .lock-notice {
      display: flex; align-items: center; gap: .5rem;
      background: rgba(234,179,8,.1); border: 1px solid rgba(234,179,8,.3);
      border-radius: 10px; padding: .65rem .9rem;
      font-size: .78rem; color: #fde047;
      margin-bottom: 1.25rem;
    }

    .no-access-notice {
      display: flex; align-items: center; gap: .5rem;
      background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
      border-radius: 10px; padding: .65rem .9rem;
      font-size: .78rem; color: #fca5a5;
      margin-bottom: 1.25rem;
    }

    .dept-options {
      display: flex;
      flex-direction: column;
      gap: .65rem;
      margin-bottom: 1.5rem;
      max-height: 260px;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 2px 4px;
    }

    .dept-option {
      display: flex;
      align-items: center;
      gap: .85rem;
      padding: .85rem 1rem;
      border-radius: 14px;
      border: 1.5px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.05);
      cursor: pointer;
      transition: background .15s, border-color .15s;
      position: relative;
      -webkit-user-select: none;
      user-select: none;
      width: 100%;
      min-width: 0;
    }
    .dept-option:hover:not(.locked) {
      background: rgba(255,255,255,.1);
    }
    .dept-option.locked {
      opacity: .35; cursor: not-allowed;
      filter: grayscale(.5);
      pointer-events: none;
    }
    .dept-option.selected {
      border-color: var(--dept-color) !important;
      background: var(--dept-bg) !important;
      box-shadow: 0 0 0 2px var(--dept-color);
    }
    .dept-option input[type="radio"] { display: none; }

    .dept-dot {
      width: 12px; height: 12px; border-radius: 50%;
      flex-shrink: 0;
    }
    .dept-name { font-size: .9rem; font-weight: 700; color: var(--white); flex: 1; min-width: 0; }
    .dept-check { font-size: 1rem; color: var(--dept-color); opacity: 0; transition: opacity .15s; flex-shrink: 0; }
    .dept-option.selected .dept-check { opacity: 1; }
    .lock-icon { font-size: .8rem; color: rgba(255,255,255,.3); flex-shrink: 0; }

    .btn-submit {
      width: 100%; padding: .8rem 1rem;
      background: linear-gradient(135deg, var(--blue-bright) 0%, var(--blue-main) 100%);
      border: 1px solid rgba(255,255,255,.2); border-radius: 12px;
      font-family: 'Sora', sans-serif; font-size: .9rem; font-weight: 700;
      color: var(--white); cursor: pointer; transition: all .2s;
      box-shadow: 0 4px 20px rgba(67,128,226,.4); letter-spacing: .02em;
    }
    .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(67,128,226,.55); }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit:disabled { opacity: .45; cursor: not-allowed; transform: none; }

    .back-link {
      display: flex; align-items: center; justify-content: center;
      gap: .35rem; margin-top: 1.1rem;
      font-size: .8rem; font-weight: 600;
      color: var(--white-60); text-decoration: none; transition: color .15s;
    }
    .back-link:hover { color: var(--white); }
    .divider { height: 1px; background: rgba(255,255,255,.1); margin: 1.25rem 0; }

    @media (max-width: 520px) {
      .page { align-items: flex-start; padding-top: 2rem; padding-bottom: 3rem; }
      .card { padding: 2.5rem 2.5rem 2.2rem; }
    }
  </style>
</head>
<body>

<div class="bg">
  <div class="orb orb-1"></div><div class="orb orb-2"></div>
  <div class="orb orb-3"></div><div class="orb orb-4"></div>
  <div class="orb orb-5"></div>
</div>

<div class="page">
  <div class="card">

    <div class="card-top">
      <div class="logo-ring">
        <img src="assets/img/logo.png" alt="Logo"
             onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-building\' style=\'font-size:1.5rem;color:#fff;\'></i>';">
      </div>
      <div class="card-title">Select Department</div>
      <div class="card-subtitle">
        Welcome, <strong style="color:#fff"><?= htmlspecialchars($displayName) ?></strong>
        &nbsp;·&nbsp;
        <?= htmlspecialchars($userType) ?>
      </div>
    </div>

    <div class="current-dept">
      <i class="bi bi-building"></i>
      Current: <strong><?= $current !== '' ? htmlspecialchars($current) : 'All Departments' ?></strong>
    </div>

    <form method="POST" action="set_department.php">
      <div class="dept-options">
        <?php foreach ($displayOptions as $val => $label):
          // Case-insensitive color lookup
          $colorKey = $val;
          if (!isset($deptColors[$colorKey])) {
              foreach ($deptColors as $k => $v) {
                  if (strcasecmp($k, $val) === 0) { $colorKey = $k; break; }
              }
          }
          $c        = $deptColors[$colorKey] ?? $deptColors[''];
          $isActive = ($current === $val);
          $icon     = ($val === '') ? 'bi-globe' : 'bi-building';
          $radioId  = 'dept_' . ($val === '' ? 'all' : strtolower(preg_replace('/\s+/', '_', $val)));
        ?>
        <label
          class="dept-option<?= $isActive ? ' selected' : '' ?>"
          style="--dept-color:<?= $c['color'] ?>;--dept-bg:<?= $c['bg'] ?>;"
          for="<?= $radioId ?>">

          <input
            type="radio"
            name="dept"
            id="<?= $radioId ?>"
            value="<?= htmlspecialchars($val) ?>"
            <?= $isActive ? 'checked' : '' ?>>

          <span class="dept-dot"
                style="background:<?= $c['solid'] ?>;box-shadow:0 0 6px <?= $c['solid'] ?>;"></span>

          <span class="dept-name">
            <i class="bi <?= $icon ?>"></i>
            <?= htmlspecialchars($label) ?>
          </span>

          <i class="bi bi-check-circle-fill dept-check"></i>
        </label>
        <?php endforeach; ?>

        <?php if (empty($displayOptions)): ?>
        <div style="text-align:center;padding:1.5rem;color:rgba(255,255,255,.3);font-size:.82rem">
          <i class="bi bi-building-slash" style="font-size:2rem;display:block;margin-bottom:.5rem"></i>
          No departments available.
        </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn-submit" <?= empty($displayOptions) ? 'disabled' : '' ?>>
        <i class="bi bi-check2-circle"></i>&nbsp; Apply Department
      </button>
    </form>

    <?php if (empty($displayOptions)): ?>
    <div class="no-access-notice" style="margin-top:1rem">
      <i class="bi bi-exclamation-triangle-fill"></i>
      No departments have been assigned to your account yet. Please contact your administrator.
    </div>
    <?php else: ?>
    <div class="lock-notice" style="margin-top:1rem">
      <i class="bi bi-lock-fill"></i>
      You can only access departments assigned to your account.
      Contact IT to request additional access.
    </div>
    <?php endif; ?>

    <div class="divider"></div>
    <a href="home.php" class="back-link">
      <i class="bi bi-arrow-left"></i> Back
    </a>

  </div>
</div>

<script>
  document.querySelectorAll('.dept-option:not(.locked)').forEach(function(label) {
    label.addEventListener('click', function() {
      document.querySelectorAll('.dept-option').forEach(function(l) {
        l.classList.remove('selected');
      });
      this.classList.add('selected');
    });
  });
</script>

</body>
</html>