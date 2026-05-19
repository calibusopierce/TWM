<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

global $pdo;
if ($pdo) {
    rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
}

$show_hub = true;

// ── Module guide registry — add new modules here as they grow ────────────────
// Each entry: module_keys (any one grants access), href, emoji, title, desc, chips
$guide_registry = [
    [
        'keys'  => ['fuel_dashboard', 'graphs'],
        'href'  => base_url('LOGISTICS/help-manual.php'),
        'emoji' => '⛽',
        'cat'   => 'Fleet & Logistics',
        'title' => 'Logistics',
        'desc'  => 'Fuel Monitoring Dashboard and Analytics Graphs — track fleet fuel usage, spot anomalies, and export reports.',
        'chips' => ['Fuel Dashboard', 'Graphs', 'Anomaly Flags', 'Export'],
        'color' => '#f59e0b',
    ],
    [
        'keys'  => ['delivery_remittance', 'ar_remittance'],
        'href'  => base_url('FINANCE/help-manual.php'),
        'emoji' => '💸',
        'cat'   => 'Finance',
        'title' => 'Finance',
        'desc'  => 'Delivery Remittance Dashboard — track delivery documents from creation through remittance to Finance receipt, including shorts monitoring.',
        'chips' => ['Remittance', 'Shorts', 'By Leadman', 'By Salesman'],
        'color' => '#8b5cf6',
    ],
    [
        'keys'  => ['careers_admin', 'view_applications', 'uniform_inventory', 'employee_list'],
        'href'  => base_url('HR/help-manual.php'),
        'emoji' => '👥',
        'cat'   => 'Human Resources',
        'title' => 'HR',
        'desc'  => 'Careers Admin, Applications, Uniform Inventory, and Employee Directory — manage hiring, uniforms, and staff records.',
        'chips' => ['Careers', 'Applications', 'Uniforms', 'Employees'],
        'color' => '#10b981',
    ],
    [
        'keys'  => ['po_index'],
        'href'  => base_url('PO/help-manual.php'),
        'emoji' => '📄',
        'cat'   => 'Procurement',
        'title' => 'Purchase Orders',
        'desc'  => 'Create, review, approve, and print Purchase Orders across all departments. Manage PO categories and track order statuses.',
        'chips' => ['Create PO', 'Approve', 'Print', 'Categories'],
        'color' => '#3b82f6',
    ],
    [
        'keys'  => ['RBAC'],
        'href'  => base_url('RBAC/help-manual.php'),
        'emoji' => '🔐',
        'cat'   => 'Administration',
        'title' => 'Access Control',
        'desc'  => 'Manage user accounts and per-user module permissions — control who can access what across the entire portal.',
        'chips' => ['User Access', 'Audit Log', 'Module Registry'],
        'color' => '#ef4444',
    ],
];

// ── Filter to only what this user can access ─────────────────────────────────
$accessible = array_filter($guide_registry, function($guide) {
    foreach ($guide['keys'] as $key) {
        if (rbac_can($key)) return true;
    }
    return false;
});

$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual · Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<style>
  /* ── Reset & base ── */
  *, *::before, *::after { box-sizing: border-box; }

  body {
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    background: #f8fafc;
    color: #0f172a;
    min-height: 100vh;
  }

  /* ── Page shell ── */
  .hub-shell {
    max-width: 1080px;
    margin: 0 auto;
    padding: 2.5rem 2rem 5rem;
  }

  /* ── Hero ── */
  .hub-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 2rem;
    padding: 2.5rem 2.75rem;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 20px;
    margin-bottom: 2.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 24px rgba(0,0,0,.04);
    position: relative;
    overflow: hidden;
  }

  .hub-hero::before {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 340px; height: 100%;
    background: linear-gradient(135deg, transparent 0%, rgba(59,130,246,.04) 100%);
    pointer-events: none;
  }

  .hub-hero-left { position: relative; z-index: 1; }

  .hub-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #3b82f6;
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.18);
    border-radius: 20px;
    padding: .22rem .75rem;
    margin-bottom: .85rem;
  }

  .hub-hero-title {
    font-family: 'Sora', sans-serif;
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -.04em;
    line-height: 1.15;
    margin-bottom: .5rem;
  }

  .hub-hero-title span { color: #3b82f6; }

  .hub-hero-sub {
    font-size: .92rem;
    color: #64748b;
    line-height: 1.7;
    max-width: 480px;
  }

  .hub-hero-stat {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    margin-top: 1.25rem;
    flex-wrap: wrap;
  }

  .hub-stat-item {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-size: .8rem;
    color: #475569;
    font-weight: 500;
  }

  .hub-stat-item i { color: #3b82f6; font-size: .85rem; }

  .hub-hero-right {
    position: relative; z-index: 1;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: .5rem;
  }

  .hub-guide-count {
    font-family: 'Sora', sans-serif;
    font-size: 3.5rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -.06em;
    line-height: 1;
  }

  .hub-guide-label {
    font-size: .75rem;
    color: #94a3b8;
    font-weight: 600;
    text-align: right;
    text-transform: uppercase;
    letter-spacing: .06em;
  }

  /* ── Section label ── */
  .hub-section-label {
    display: flex;
    align-items: center;
    gap: .6rem;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #94a3b8;
    margin-bottom: 1rem;
  }

  .hub-section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: #e2e8f0;
  }

  /* ── Guide grid ── */
  .hub-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
  }

  /* ── Guide card ── */
  .hub-card {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 18px;
    padding: 0;
    text-decoration: none;
    transition: border-color .2s, box-shadow .2s, transform .15s;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    overflow: hidden;
    position: relative;
  }

  .hub-card:hover {
    border-color: var(--card-color, #3b82f6);
    box-shadow: 0 8px 32px rgba(0,0,0,.1), 0 0 0 1px var(--card-color, #3b82f6);
    transform: translateY(-3px);
  }

  /* Color accent bar at top */
  .hub-card-bar {
    height: 4px;
    background: var(--card-color, #3b82f6);
    width: 100%;
    flex-shrink: 0;
    transition: height .2s;
  }

  .hub-card:hover .hub-card-bar { height: 5px; }

  .hub-card-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 1.35rem 1.5rem 1.25rem;
  }

  .hub-card-header {
    display: flex;
    align-items: flex-start;
    gap: .85rem;
    margin-bottom: .85rem;
  }

  .hub-card-emoji {
    font-size: 2rem;
    line-height: 1;
    flex-shrink: 0;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: color-mix(in srgb, var(--card-color, #3b82f6) 10%, transparent);
    border-radius: 12px;
    border: 1px solid color-mix(in srgb, var(--card-color, #3b82f6) 20%, transparent);
  }

  .hub-card-meta { flex: 1; min-width: 0; }

  .hub-card-cat {
    font-size: .65rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--card-color, #3b82f6);
    margin-bottom: .2rem;
  }

  .hub-card-title {
    font-family: 'Sora', sans-serif;
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -.02em;
    line-height: 1.2;
  }

  .hub-card-desc {
    font-size: .82rem;
    color: #64748b;
    line-height: 1.7;
    flex: 1;
    margin-bottom: .85rem;
  }

  .hub-card-chips {
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
    margin-bottom: .9rem;
  }

  .hub-chip {
    font-size: .67rem;
    font-weight: 600;
    padding: .18rem .55rem;
    border-radius: 20px;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #475569;
    white-space: nowrap;
  }

  .hub-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: .75rem;
    border-top: 1px solid #f1f5f9;
    margin-top: auto;
  }

  .hub-card-cta {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .78rem;
    font-weight: 700;
    color: var(--card-color, #3b82f6);
    transition: gap .15s;
  }

  .hub-card:hover .hub-card-cta { gap: .55rem; }

  .hub-card-cta i { font-size: .8rem; transition: transform .15s; }
  .hub-card:hover .hub-card-cta i { transform: translateX(3px); }

  .hub-card-badge {
    font-size: .65rem;
    font-weight: 700;
    padding: .15rem .5rem;
    border-radius: 20px;
    background: color-mix(in srgb, var(--card-color, #3b82f6) 10%, transparent);
    color: var(--card-color, #3b82f6);
    border: 1px solid color-mix(in srgb, var(--card-color, #3b82f6) 20%, transparent);
  }

  /* ── Empty state ── */
  .hub-empty {
    text-align: center;
    padding: 4rem 2rem;
    background: #fff;
    border: 1.5px dashed #e2e8f0;
    border-radius: 18px;
    color: #94a3b8;
  }

  .hub-empty i { font-size: 2.5rem; display: block; margin-bottom: .75rem; opacity: .4; }
  .hub-empty strong { display: block; font-size: 1rem; color: #475569; margin-bottom: .35rem; }

  /* ── Footer ── */
  .hub-footer {
    text-align: center;
    padding: 1.5rem;
    font-size: .75rem;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    margin-top: 1rem;
  }

  /* ── Animations ── */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .hub-hero  { animation: fadeUp .4s ease both; }
  .hub-grid  { animation: fadeUp .4s .1s ease both; }

  .hub-card {
    animation: fadeUp .35s ease both;
  }

  <?php foreach (array_values($accessible) as $i => $g): ?>
  .hub-card:nth-child(<?= $i + 1 ?>) { animation-delay: <?= 0.08 + ($i * 0.06) ?>s; }
  <?php endforeach; ?>

  /* ── Responsive ── */
  @media (max-width: 700px) {
    .hub-shell { padding: 1.25rem 1rem 3rem; }
    .hub-hero { flex-direction: column; align-items: flex-start; padding: 1.5rem; }
    .hub-hero-right { align-items: flex-start; flex-direction: row; align-items: center; gap: .75rem; }
    .hub-guide-count { font-size: 2.5rem; }
    .hub-grid { grid-template-columns: 1fr; }
    .hub-hero-title { font-size: 1.5rem; }
  }
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="hub-shell">

  <!-- ── Hero ── -->
  <div class="hub-hero">
    <div class="hub-hero-left">
      <div class="hub-eyebrow">
        <i class="bi bi-book-fill"></i> Documentation
      </div>
      <div class="hub-hero-title">Help <span>Manual</span></div>
      <div class="hub-hero-sub">
        Select a module below to open its dedicated help guide. Each guide covers features, filters, workflows, and tips for that section of the portal.
      </div>
      <div class="hub-hero-stat">
        <div class="hub-stat-item">
          <i class="bi bi-grid-fill"></i>
          <?= count($accessible) ?> guide<?= count($accessible) !== 1 ? 's' : '' ?> available to you
        </div>
        <div class="hub-stat-item">
          <i class="bi bi-clock"></i>
          Last updated <?= date('M d, Y') ?>
        </div>
      </div>
    </div>
    <div class="hub-hero-right">
      <div class="hub-guide-count"><?= count($accessible) ?></div>
      <div class="hub-guide-label">Available<br>Guides</div>
    </div>
  </div>

  <!-- ── Guide cards ── -->
  <div class="hub-section-label">Available Help Guides</div>

  <?php if (empty($accessible)): ?>
  <div class="hub-empty">
    <i class="bi bi-book"></i>
    <strong>No guides available</strong>
    You don't have access to any modules with a help guide yet. Contact your administrator.
  </div>
  <?php else: ?>
  <div class="hub-grid">
    <?php foreach ($accessible as $guide): ?>
    <a href="<?= $guide['href'] ?>" class="hub-card"
       style="--card-color: <?= $guide['color'] ?>">
      <div class="hub-card-bar"></div>
      <div class="hub-card-body">
        <div class="hub-card-header">
          <div class="hub-card-emoji"><?= $guide['emoji'] ?></div>
          <div class="hub-card-meta">
            <div class="hub-card-cat"><?= htmlspecialchars($guide['cat']) ?></div>
            <div class="hub-card-title"><?= htmlspecialchars($guide['title']) ?></div>
          </div>
        </div>
        <div class="hub-card-desc"><?= htmlspecialchars($guide['desc']) ?></div>
        <div class="hub-card-chips">
          <?php foreach ($guide['chips'] as $chip): ?>
          <span class="hub-chip"><?= htmlspecialchars($chip) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="hub-card-footer">
          <span class="hub-card-cta">
            Open Guide <i class="bi bi-arrow-right"></i>
          </span>
          <span class="hub-card-badge">
            <?= count($guide['keys']) ?> module<?= count($guide['keys']) !== 1 ? 's' : '' ?>
          </span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<div class="hub-footer">
  Help Manual &middot; Urban Tradewell Corporation &middot; <?= date('Y') ?>
</div>

</body>
</html>