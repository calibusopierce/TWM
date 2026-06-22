<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/test_sqlsrv.php'; 
require_once __DIR__ . '/RBAC/rbac_helper.php';

auth_check(); // RBAC handles module-level access; just verify login + session

$userType    = $_SESSION['UserType']    ?? '';
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$department  = $_SESSION['Department']  ?? '';

// Bust RBAC cache on homepage load so admin changes reflect immediately
unset($_SESSION['rbac_permissions_uid_' . ($_SESSION['UserID'] ?? 0)]);

$permissions = rbac_load_permissions($pdo, $userType);

// Filter out nav-only modules before building homepage sections
$navOnlyModules = ['RBAC'];
$homepagePerms  = array_filter($permissions, fn($k) => !in_array($k, $navOnlyModules, true), ARRAY_FILTER_USE_KEY);

$sections   = rbac_get_sections($pdo, $homepagePerms);
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
      --blue-bright: #4380e2;
      --blue-light:  #93c5fd;
      --white:       #ffffff;
      --w10: rgba(255,255,255,0.10);
      --w15: rgba(255,255,255,0.15);
      --w25: rgba(255,255,255,0.25);
      --w60: rgba(255,255,255,0.60);
      --w80: rgba(255,255,255,0.80);

      --cat-hr-bg:      rgba(52,211,153,0.10);
      --cat-fleet-bg:   rgba(251,191,36,0.10);
      --cat-finance-bg: rgba(167,139,250,0.10);
      --cat-customers-bg: rgba(248,113,113,0.10);
      --cat-general-bg: rgba(96,165,250,0.10);

      --cat-hr-bdr:      rgba(52,211,153,0.28);
      --cat-fleet-bdr:   rgba(251,191,36,0.28);
      --cat-finance-bdr: rgba(167,139,250,0.28);
      --cat-customers-bdr: rgba(248,113,113,0.28);
      --cat-general-bdr: rgba(96,165,250,0.28);

      --cat-hr:      #34d399;
      --cat-fleet:   #fbbf24;
      --cat-finance: #a78bfa;
      --cat-customers: #f87171;
      --cat-general: #60a5fa;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      overflow-y: auto;
      overflow-x: hidden;
      scroll-behavior: smooth;
      /* Static gradient — same colors, zero GPU animation overhead */
      background: linear-gradient(145deg, var(--blue-bright) 0%, var(--blue-deep) 100%);
      background-attachment: fixed;
    }

    /* ── Layout ── */
    .page {
      display: flex; flex-direction: column;
      align-items: center;
      min-height: 100vh;
      padding: 2rem 2rem 3rem;
      gap: 1.75rem;
    }

    /* ── Header ── */
    .hub-header { text-align: center; animation: fadeUp .4s ease both; }
    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 68px; height: 68px; border-radius: 50%;
      background: var(--w10); border: 1px solid var(--w25);
      margin-bottom: .9rem; box-shadow: 0 6px 18px rgba(0,0,0,.2);
    }
    .logo-ring img { width: 44px; height: 44px; object-fit: contain; }
    .hub-title {
      font-family: 'Sora', sans-serif;
      font-size: 1.55rem; font-weight: 800;
      color: var(--white); letter-spacing: -.04em;
    }
    .hub-subtitle { font-size: .83rem; color: var(--w60); margin-top: .3rem; }
    .welcome-text { margin-top: .65rem; font-size: .88rem; color: var(--w80); }
    .welcome-text strong { color: var(--white); }
    .user-badge {
      display: inline-block; margin-top: .4rem;
      padding: .2rem .75rem;
      background: rgba(67,128,226,.25);
      border: 1px solid rgba(147,197,253,.3);
      border-radius: 999px;
      font-size: .74rem; font-weight: 600;
      color: var(--blue-light); letter-spacing: .04em;
    }
    .last-login {
      display: block; margin-top: .45rem;
      font-size: .71rem; color: var(--w60); letter-spacing: .02em;
    }
    .last-login i { font-size: .69rem; margin-right: .2rem; }

    /* ── Search bar ── */
    .hub-search-wrap {
      width: 100%; max-width: 460px;
      animation: fadeUp .4s .08s ease both;
    }
    .hub-search {
      position: relative; display: flex; align-items: center;
    }
    .hub-search .si {
      position: absolute; left: .85rem;
      font-size: .95rem; color: var(--w60); pointer-events: none;
    }
    .hub-search input {
      width: 100%;
      padding: .62rem .9rem .62rem 2.35rem;
      background: rgba(255,255,255,0.10);
      border: 1px solid var(--w15);
      border-radius: 12px;
      color: var(--white);
      font-family: 'DM Sans', sans-serif;
      font-size: .87rem;
      outline: none;
      transition: background .15s, border-color .15s;
    }
    .hub-search input::placeholder { color: var(--w60); }
    .hub-search input:focus {
      background: rgba(255,255,255,0.14);
      border-color: rgba(147,197,253,.4);
    }
    .hub-search-clear {
      position: absolute; right: .7rem;
      background: none; border: none;
      color: var(--w60); cursor: pointer;
      font-size: .88rem; padding: 0; line-height: 1;
      display: none;
    }
    .hub-search-clear.visible { display: block; }
    #search-status {
      margin-top: .45rem; font-size: .74rem; color: var(--w60);
      text-align: center; min-height: 1.1em;
    }

    /* ── Section container ── */
    .hub-sections {
      display: flex; flex-direction: column; gap: 1.25rem;
      width: 100%; max-width: 1100px;
    }
    .hub-section {
      background: rgba(255,255,255,0.06);
      border-radius: 18px; border: 1px solid var(--w10);
      overflow: hidden;
      box-shadow: 0 3px 14px rgba(8,23,61,.18);
    }
    /* staggered fade-in */
    .hub-section { animation: fadeUp .4s ease both; }
    .hub-section:nth-child(1) { animation-delay: .10s; }
    .hub-section:nth-child(2) { animation-delay: .18s; }
    .hub-section:nth-child(3) { animation-delay: .26s; }
    .hub-section:nth-child(4) { animation-delay: .34s; }
    .hub-section:nth-child(n+5) { animation-delay: .40s; }

    .hub-section.cat-hr      { border-top: 2px solid var(--cat-hr); }
    .hub-section.cat-fleet   { border-top: 2px solid var(--cat-fleet); }
    .hub-section.cat-finance { border-top: 2px solid var(--cat-finance); }
    .hub-section.cat-customers { border-top: 2px solid var(--cat-customers); }
    .hub-section.cat-general { border-top: 2px solid var(--cat-general); }

    /* ── Clickable section header (collapse/expand) ── */
    .section-header {
      display: flex; align-items: center; gap: .6rem;
      padding: .9rem 1.4rem;
      cursor: pointer; user-select: none;
      transition: background .15s;
    }
    .section-header:hover { background: rgba(255,255,255,0.04); }

    .section-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 8px;
      font-size: .9rem; flex-shrink: 0;
    }
    .cat-hr      .section-icon { background: var(--cat-hr-bg);      color: var(--cat-hr);      border: 1px solid var(--cat-hr-bdr); }
    .cat-fleet   .section-icon { background: var(--cat-fleet-bg);   color: var(--cat-fleet);   border: 1px solid var(--cat-fleet-bdr); }
    .cat-finance .section-icon { background: var(--cat-finance-bg); color: var(--cat-finance); border: 1px solid var(--cat-finance-bdr); }
    .cat-customers .section-icon { background: var(--cat-customers-bg); color: var(--cat-customers); border: 1px solid var(--cat-customers-bdr); }
    .cat-general .section-icon { background: var(--cat-general-bg); color: var(--cat-general); border: 1px solid var(--cat-general-bdr); }

    .section-label {
      font-family: 'Sora', sans-serif;
      font-size: .75rem; font-weight: 700;
      letter-spacing: .08em; text-transform: uppercase;
    }
    .cat-hr      .section-label { color: var(--cat-hr); }
    .cat-fleet   .section-label { color: var(--cat-fleet); }
    .cat-finance .section-label { color: var(--cat-finance); }
    .cat-customers .section-label { color: var(--cat-customers); }
    .cat-general .section-label { color: var(--cat-general); }

    .section-count {
      font-size: .67rem; font-weight: 600;
      padding: .12rem .48rem;
      border-radius: 999px;
      background: var(--w10);
      color: var(--w60);
    }

    .section-divider { flex: 1; height: 1px; background: var(--w10); }

    .section-chevron {
      font-size: .78rem; color: var(--w60);
      transition: transform .22s;
      flex-shrink: 0;
    }
    .hub-section.collapsed .section-chevron { transform: rotate(-90deg); }

    /* ── Collapsible body ── */
    .section-body {
      max-height: 9999px; opacity: 1;
      transition: max-height .28s ease, opacity .22s ease;
      overflow: visible;
    }
    .hub-section.collapsed .section-body { max-height: 0; opacity: 0; }

    .section-grid {
      display: flex; flex-wrap: wrap; gap: .8rem;
      padding: 0 1.4rem 1.4rem;
    }

    /* ── Cards — no backdrop-filter (was the main GPU hog) ── */
    .hub-card {
      flex: 1 1 155px; max-width: 210px; min-width: 145px;
      background: rgba(255,255,255,0.08);
      border: 1px solid var(--w15); border-radius: 14px;
      padding: 1.3rem 1rem 1.1rem;
      text-align: center; text-decoration: none; color: var(--white);
      transition: transform .16s, box-shadow .16s, background .16s, border-color .16s;
      box-shadow: 0 2px 10px rgba(8,23,61,.15);
      will-change: transform;
    }
    .hub-card:hover {
      transform: translateY(-4px);
      background: rgba(255,255,255,0.13);
      border-color: rgba(255,255,255,.26);
      box-shadow: 0 9px 26px rgba(8,23,61,.35);
    }
    .hub-card:active { transform: translateY(-1px); }
    .hub-card.search-hidden { display: none; }

    .card-icon { font-size: 1.9rem; margin-bottom: .65rem; display: block; line-height: 1; }
    .card-name { font-family: 'Sora', sans-serif; font-size: .88rem; font-weight: 700; margin-bottom: .28rem; }
    .card-desc { font-size: .71rem; color: var(--w60); line-height: 1.5; }

    /* section-level empty message shown during search */
    .section-empty {
      display: none; padding: .6rem 1.4rem 1.1rem;
      font-size: .78rem; color: var(--w60); font-style: italic;
    }

    /* ── Logout ── */
    .hub-logout { animation: fadeUp .4s .32s ease both; }
    .btn-logout {
      display: inline-flex; align-items: center; gap: .4rem;
      padding: .48rem 1.2rem;
      background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.26);
      border-radius: 10px; color: #fca5a5;
      font-size: .81rem; font-weight: 600; text-decoration: none;
      transition: background .15s, border-color .15s;
    }
    .btn-logout:hover { background: rgba(239,68,68,.22); border-color: rgba(239,68,68,.48); }

    @keyframes fadeUp {
      from { opacity:0; transform:translateY(13px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* No-modules state */
    .hub-empty {
      text-align: center; color: var(--w60);
      padding: 2.5rem; font-size: .88rem;
    }
    .hub-empty i { font-size: 2rem; display: block; margin-bottom: .75rem; }

    /* Global no-results banner */
    #no-results {
      display: none; text-align: center;
      color: var(--w60); font-size: .85rem; padding: .5rem 0;
    }

    @media (max-width: 600px) {
      .hub-card { max-width: 100%; flex: 1 1 100%; }
      .hub-title { font-size: 1.28rem; }
      .page { padding: 1.25rem 1rem 2rem; }
      .section-grid { padding: 0 1rem 1.2rem; gap: .6rem; }
      .section-header { padding: .8rem 1rem; }
    }
    @media (min-width: 601px) and (max-width: 860px) {
      .hub-card { flex: 1 1 calc(50% - .45rem); max-width: calc(50% - .45rem); }
    }
    @media (prefers-reduced-motion: reduce) {
      *, .hub-section, .hub-header, .hub-search-wrap, .hub-logout {
        animation: none !important; transition: none !important;
      }
    }
  </style>
</head>
<body>

<div class="page">

  <!-- ── Header ── -->
  <div class="hub-header">
    <div class="logo-ring">
      <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo"
           onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'bi bi-briefcase-fill\' style=\'font-size:1.5rem;color:#fff;\'></i>';">
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

  <!-- ── Module Search ── -->
  <?php if ($totalCards > 6): ?>
  <div class="hub-search-wrap">
    <div class="hub-search">
      <i class="bi bi-search si"></i>
      <input type="text" id="mod-search" placeholder="Search modules…" autocomplete="off" spellcheck="false">
      <button class="hub-search-clear" id="search-clear" title="Clear">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div id="search-status"></div>
  </div>
  <?php endif; ?>

  <!-- ── Card Sections (RBAC-driven) ── -->
  <div class="hub-sections">
    <?php if (empty($sections)): ?>
      <div class="hub-empty">
        <i class="bi bi-shield-lock"></i>
        No modules assigned to your role yet. Contact an administrator.
      </div>
    <?php else: ?>
      <?php foreach ($sections as $section): ?>
        <?php $cardCount = count($section['cards']); ?>
        <div class="hub-section <?= htmlspecialchars($section['css']) ?>"
             data-section>
          <div class="section-header" role="button"
               aria-expanded="false"
               onclick="toggleSection(this.closest('.hub-section'))">
            <span class="section-icon"><i class="bi <?= htmlspecialchars($section['icon']) ?>"></i></span>
            <span class="section-label"><?= htmlspecialchars($section['label']) ?></span>
            <span class="section-count"><?= $cardCount ?></span>
            <div class="section-divider"></div>
            <i class="bi bi-chevron-down section-chevron"></i>
          </div>
          <div class="section-body">
            <div class="section-grid">
              <?php foreach ($section['cards'] as $card):
                  $colorVal  = $card['color'] ?? 'blue';
                  $legacyMap = ['blue'=>'#60a5fa','green'=>'#34d399','amber'=>'#fbbf24','purple'=>'#a78bfa'];
                  $iconColor = $legacyMap[$colorVal] ?? $colorVal;
                ?>
                <a href="<?= htmlspecialchars($card['url']) ?>"
                   class="hub-card"
                   data-name="<?= htmlspecialchars(strtolower($card['module_name'])) ?>"
                   data-desc="<?= htmlspecialchars(strtolower($card['description'] ?? '')) ?>">
                  <i class="bi <?= htmlspecialchars($card['icon']) ?> card-icon"
                     style="color:<?= htmlspecialchars($iconColor) ?>"></i>
                  <div class="card-name"><?= htmlspecialchars($card['module_name']) ?></div>
                  <div class="card-desc"><?= htmlspecialchars($card['description']) ?></div>
                </a>
              <?php endforeach; ?>
            </div>
            <div class="section-empty">No matching modules in this section.</div>
          </div>
        </div>
      <?php endforeach; ?>
      <div id="no-results">No modules match your search.</div>
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
// ── Session heartbeat (slowed to 30s — 2s was excessive) ──
setInterval(() => {
  fetch('/TWM/check_session.php')
    .then(r => r.json())
    .then(d => { if (!d.loggedIn) location.href = '/TWM/login.php'; })
    .catch(() => {});
}, 30000);

// ── Section collapse/expand ──
function toggleSection(el) {
  const body = el.querySelector('.section-body');
  const collapsed = el.classList.toggle('collapsed');
  el.querySelector('.section-header').setAttribute('aria-expanded', String(!collapsed));
  body.style.overflow = collapsed ? 'hidden' : 'visible';
}

// ── Module search ──
(function () {
  const input   = document.getElementById('mod-search');
  const clearBtn= document.getElementById('search-clear');
  const status  = document.getElementById('search-status');
  const noRes   = document.getElementById('no-results');
  if (!input) return;

  function runSearch() {
    const q = input.value.trim().toLowerCase();
    clearBtn.classList.toggle('visible', q.length > 0);

    const sections = document.querySelectorAll('[data-section]');
    let totalVisible = 0;

    sections.forEach(sec => {
      const cards = sec.querySelectorAll('.hub-card');
      let secVisible = 0;

      cards.forEach(card => {
        const hit = !q
          || card.dataset.name.includes(q)
          || card.dataset.desc.includes(q);
        card.classList.toggle('search-hidden', !hit);
        if (hit) secVisible++;
      });

      totalVisible += secVisible;

      const empty = sec.querySelector('.section-empty');
      if (q) {
        // During search: expand all so results are visible
        sec.classList.remove('collapsed');
        sec.querySelector('.section-header').setAttribute('aria-expanded', 'true');
        empty.style.display = secVisible === 0 ? 'block' : 'none';
      } else {
        empty.style.display = 'none';
      }

      // Count badge update
      const badge = sec.querySelector('.section-count');
      if (badge) badge.textContent = secVisible;
    });

    if (noRes) noRes.style.display = (q && totalVisible === 0) ? 'block' : 'none';
    if (status) {
      status.textContent = q
        ? (totalVisible === 0 ? 'No modules found.' : `${totalVisible} module${totalVisible !== 1 ? 's' : ''} found`)
        : '';
    }
  }

  input.addEventListener('input', runSearch);
  clearBtn.addEventListener('click', () => {
    input.value = '';
    runSearch();
    input.focus();
  });

  // Keyboard shortcut: "/" focuses search
  document.addEventListener('keydown', e => {
    if (e.key === '/' && document.activeElement !== input) {
      e.preventDefault(); input.focus();
    }
    if (e.key === 'Escape' && document.activeElement === input) {
      input.value = ''; runSearch(); input.blur();
    }
  });
})();

// ── Apply RBAC module order saved from admin drag-and-drop ──
(function () {
  const stored = localStorage.getItem('rbac_module_order');
  if (!stored) return;
  let keys;
  try { keys = JSON.parse(stored); } catch { return; }
  keys.forEach(key => {
    const card = document.querySelector(`.hub-card[href*="${key}"]`);
    if (card?.parentElement) card.parentElement.appendChild(card);
  });
})();
</script>

</body>
</html>