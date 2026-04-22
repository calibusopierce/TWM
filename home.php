<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/RBAC/rbac_helper.php';

auth_check(['Admin', 'Administrator', 'HR', 'Delivery', 'Logistic']);

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$department  = $_SESSION['Department']  ?? '';

// ── RBAC: open a PDO connection and load this role's permissions ──
try {
    $pdo = new PDO(
        "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
        null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ DB connection failed: " . $e->getMessage());
}

// Always bust the RBAC cache on homepage load so admin changes reflect immediately
unset($_SESSION['rbac_permissions_' . $userType]);

$permissions = rbac_load_permissions($pdo, $userType);
$sections    = rbac_get_sections($pdo, $permissions);

// ── Count total visible cards ─────────────────────────────────
$totalCards = array_sum(array_map(fn($s) => count($s['cards']), $sections));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Home · Tradewell Admin</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
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

      --cat-hr:      rgba(52, 211, 153, 0.18);
      --cat-fleet:   rgba(251, 191, 36, 0.18);
      --cat-finance: rgba(167, 139, 250, 0.18);
      --cat-general: rgba(96, 165, 250, 0.18);

      --cat-hr-border:      rgba(52, 211, 153, 0.35);
      --cat-fleet-border:   rgba(251, 191, 36, 0.35);
      --cat-finance-border: rgba(167, 139, 250, 0.35);
      --cat-general-border: rgba(96, 165, 250, 0.35);

      --cat-hr-label:      #34d399;
      --cat-fleet-label:   #fbbf24;
      --cat-finance-label: #a78bfa;
      --cat-general-label: #60a5fa;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      overflow-y: auto;
      overflow-x: hidden;
      scroll-behavior: smooth;
    }

    .bg {
      position: fixed; inset: 0;
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      z-index: 0; pointer-events: none;
    }
    .orb {
      position: absolute; border-radius: 50%;
      animation: drift linear infinite; pointer-events: none;
    }
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
      display: flex; flex-direction: column;
      align-items: center; justify-content: flex-start;
      min-height: 100vh;
      padding: 2rem 2rem 3rem;
      gap: 2rem;
    }

    .hub-header { text-align: center; animation: fadeUp .5s ease both; margin-top: 0.5rem; }
    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 72px; height: 72px; border-radius: 50%;
      background: var(--white-10); border: 1px solid var(--white-25);
      margin-bottom: 1rem; box-shadow: 0 8px 24px rgba(0,0,0,.2);
    }
    .logo-ring img { width: 46px; height: 46px; object-fit: contain; }
    .hub-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.6rem; font-weight: 800;
      color: var(--white); letter-spacing: -.04em;
    }
    .hub-subtitle { font-size: .85rem; color: var(--white-60); margin-top: .35rem; }
    .welcome-text { margin-top: .75rem; font-size: .9rem; color: var(--white-80); }
    .welcome-text strong { color: var(--white); }
    .user-badge {
      display: inline-block; margin-top: .4rem;
      padding: .2rem .75rem;
      background: rgba(67,128,226,.25);
      border: 1px solid rgba(147,197,253,.3);
      border-radius: 999px;
      font-size: .75rem; font-weight: 600;
      color: var(--blue-light); letter-spacing: .04em;
    }
    .last-login {
      display: block; margin-top: .5rem;
      font-size: .72rem; color: var(--white-60); letter-spacing: .02em;
    }
    .last-login i { font-size: .7rem; margin-right: .25rem; }

    .hub-sections {
      display: flex; flex-direction: column; gap: 2rem;
      width: 100%; max-width: 1100px;
      animation: fadeUp .5s .15s ease both;
    }

    .hub-section {
      background: rgba(255,255,255,0.04);
      border-radius: 24px; border: 1px solid var(--white-10);
      padding: 1.5rem 1.75rem 1.75rem;
      box-shadow: 0 8px 32px rgba(8,23,61,.25);
      backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    }
    .hub-section.cat-hr      { border-top: 2px solid var(--cat-hr-label); }
    .hub-section.cat-fleet   { border-top: 2px solid var(--cat-fleet-label); }
    .hub-section.cat-finance { border-top: 2px solid var(--cat-finance-label); }
    .hub-section.cat-general { border-top: 2px solid var(--cat-general-label); }

    .section-header { display: flex; align-items: center; gap: .65rem; margin-bottom: 1.25rem; }
    .section-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 34px; height: 34px; border-radius: 10px;
      font-size: 1rem; flex-shrink: 0;
    }
    .cat-hr      .section-icon { background: var(--cat-hr);      color: var(--cat-hr-label);      border: 1px solid var(--cat-hr-border); }
    .cat-fleet   .section-icon { background: var(--cat-fleet);   color: var(--cat-fleet-label);   border: 1px solid var(--cat-fleet-border); }
    .cat-finance .section-icon { background: var(--cat-finance); color: var(--cat-finance-label); border: 1px solid var(--cat-finance-border); }
    .cat-general .section-icon { background: var(--cat-general); color: var(--cat-general-label); border: 1px solid var(--cat-general-border); }

    .section-label {
      font-family: 'Sora', sans-serif;
      font-size: .8rem; font-weight: 700;
      letter-spacing: .08em; text-transform: uppercase;
    }
    .cat-hr      .section-label { color: var(--cat-hr-label); }
    .cat-fleet   .section-label { color: var(--cat-fleet-label); }
    .cat-finance .section-label { color: var(--cat-finance-label); }
    .cat-general .section-label { color: var(--cat-general-label); }

    .section-divider { flex: 1; height: 1px; background: var(--white-10); }

    .section-grid { display: flex; flex-wrap: wrap; gap: 1rem; }

    .hub-card {
      flex: 1 1 180px; max-width: 230px; min-width: 160px;
      background: rgba(255,255,255,0.07);
      border: 1px solid var(--white-15); border-radius: 18px;
      backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
      padding: 1.6rem 1.25rem 1.4rem;
      text-align: center; text-decoration: none; color: var(--white);
      cursor: pointer;
      transition: transform .2s, box-shadow .2s, background .2s, border-color .2s;
      box-shadow: 0 4px 20px rgba(8,23,61,.2);
      position: relative; overflow: hidden;
    }
    .hub-card::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.06) 0%, transparent 60%);
      opacity: 0; transition: opacity .2s; border-radius: inherit; pointer-events: none;
    }
    .hub-card:hover::after { opacity: 1; }
    .hub-card:hover {
      transform: translateY(-5px);
      background: rgba(255,255,255,0.11);
      border-color: rgba(255,255,255,.28);
      box-shadow: 0 12px 36px rgba(8,23,61,.45);
    }
    .hub-card:active { transform: translateY(-2px); }

    .card-icon { font-size: 2.2rem; margin-bottom: .8rem; display: block; line-height: 1; }
    .card-icon.blue   { color: #60a5fa; }
    .card-icon.green  { color: #34d399; }
    .card-icon.amber  { color: #fbbf24; }
    .card-icon.purple { color: #a78bfa; }

    .card-name { font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 700; margin-bottom: .35rem; }
    .card-desc { font-size: .76rem; color: var(--white-60); line-height: 1.5; }

    .hub-logout { animation: fadeUp .5s .3s ease both; margin-bottom: 0.5rem; }
    .btn-logout {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .5rem 1.25rem;
      background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3);
      border-radius: 10px; color: #fca5a5;
      font-size: .82rem; font-weight: 600; text-decoration: none;
      transition: background .2s, border-color .2s;
    }
    .btn-logout:hover { background: rgba(239,68,68,.25); border-color: rgba(239,68,68,.5); }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(16px); }
      to   { opacity:1; transform:translateY(0); }
    }
    .hub-section:nth-child(1) { animation: fadeUp .45s .10s ease both; }
    .hub-section:nth-child(2) { animation: fadeUp .45s .20s ease both; }
    .hub-section:nth-child(3) { animation: fadeUp .45s .30s ease both; }
    .hub-section:nth-child(4) { animation: fadeUp .45s .40s ease both; }

    @media (max-width: 600px) {
      .hub-card { max-width: 100%; flex: 1 1 100%; }
      .hub-title { font-size: 1.3rem; }
      .page { padding: 1.5rem 1rem 2rem; }
      .hub-section { padding: 1.25rem 1rem 1.25rem; }
    }
    @media (min-width: 601px) and (max-width: 860px) {
      .hub-card { flex: 1 1 calc(50% - .5rem); max-width: calc(50% - .5rem); }
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

  <!-- ── Header ── -->
  <div class="hub-header">
    <div class="logo-ring">
      <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo"
           onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-briefcase-fill\' style=\'font-size:1.6rem;color:#fff;\'></i>';">
    </div>
    <div class="hub-title">Admin Portal</div>
    <div class="hub-subtitle">Urban Tradewell Corporation</div>
    <div class="welcome-text">
      Welcome back, <strong><?= htmlspecialchars($displayName) ?></strong>
    </div>
    <span class="user-badge">
      <?= htmlspecialchars($userType) ?>
      <?php if ($department): ?>&nbsp;·&nbsp;<?= htmlspecialchars($department) ?><?php endif; ?>
    </span>
    <span class="last-login">
      <i class="bi bi-clock"></i>
      Session started: <?= date('F j, Y \a\t g:i A') ?>
    </span>
  </div>

  <!-- ── Card Sections (RBAC-driven) ── -->
  <div class="hub-sections">
    <?php if (empty($sections)): ?>
      <div class="hub-section cat-general" style="text-align:center;color:rgba(255,255,255,.5);padding:2.5rem;">
        <i class="bi bi-shield-lock" style="font-size:2rem;display:block;margin-bottom:.75rem;"></i>
        No modules have been assigned to your role yet. Contact an administrator.
      </div>
    <?php else: ?>
      <?php foreach ($sections as $section): ?>
        <div class="hub-section <?= htmlspecialchars($section['css']) ?>">
          <div class="section-header">
            <span class="section-icon"><i class="bi <?= htmlspecialchars($section['icon']) ?>"></i></span>
            <span class="section-label"><?= $section['label'] ?></span>
            <div class="section-divider"></div>
          </div>
          <div class="section-grid">
            <?php foreach ($section['cards'] as $card): ?>
              <!-- $card['url'] is resolved by rbac_module_url() in rbac_helper.php -->
              <a href="<?= htmlspecialchars($card['url']) ?>" class="hub-card">
                <i class="bi <?= htmlspecialchars($card['icon']) ?> card-icon <?= htmlspecialchars($card['color']) ?>"></i>
                <div class="card-name"><?= htmlspecialchars($card['module_name']) ?></div>
                <div class="card-desc"><?= htmlspecialchars($card['description']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ── Logout ── -->
  <div class="hub-logout">
    <a href="<?= route('logout') ?>" class="btn-logout">
      <i class="bi bi-box-arrow-right"></i> Log out
    </a>
  </div>

</div>

<script>
  setInterval(() => {
    fetch('/TWM/check_session.php')
      .then(res => res.json())
      .then(data => { if (!data.loggedIn) window.location.href = '/TWM/login.php'; })
      .catch(() => {});
  }, 2000);
</script>

</body>
</html>