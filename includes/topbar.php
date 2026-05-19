<?php
// /TWM/includes/topbar.php
// Navigation visibility is driven by RBAC module permissions, not hardcoded role arrays.

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

// ── Session vars ──────────────────────────────────────────────────────────────
$_topbar_user = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$_topbar_role = $_SESSION['UserType']    ?? '';
$_topbar_dept = $_SESSION['Department']  ?? '';

// ── RBAC: load this user's permissions once (cached in session) ───────────────
if ($pdo) {
    rbac_load_permissions($pdo, $_topbar_role);
}

// ── RBAC-driven admin check ───────────────────────────────────────────────────
$_can_rbac   = rbac_can('RBAC');
$_can_admin  = $_can_rbac || in_array($_topbar_role, ['Admin', 'Administrator', 'HR']);

// ── All other nav links are RBAC-driven ───────────────────────────────────────
$_can_fuel       = rbac_can('fuel_dashboard');
$_can_graphs     = rbac_can('graphs');
$_can_careers    = rbac_can('careers_admin');
$_can_view_apps  = rbac_can('view_applications');
$_can_employees  = rbac_can('employee_list');
$_can_uniform    = rbac_can('uniform_inventory');
$_can_help       = rbac_can('help');
$_can_po         = rbac_can('po_index');
$_can_delivery   = rbac_can('delivery_remittance');
$_can_ar        = rbac_can('ar_remittance');

$_has_fleet   = $_can_fuel || $_can_graphs;
$_has_hr      = $_can_careers || $_can_view_apps || $_can_employees || $_can_uniform;
$_has_finance = $_can_po || $_can_delivery || $_can_ar;

// ── Brand subtitle ────────────────────────────────────────────────────────────
if ($_can_admin) {
    $_brand_sub = 'Admin Portal';
} elseif ($_can_fuel || $_can_graphs) {
    $_brand_sub = 'Fleet Monitoring';
} elseif ($_can_careers || $_can_view_apps || $_can_employees) {
    $_brand_sub = 'Careers Admin';
} else {
    $_brand_sub = 'Portal';
}

// ── Department color map ──────────────────────────────────────────────────────
$_deptColors = [
    'Monde'      => ['bg' => 'rgba(239,68,68,.15)',   'color' => '#ef4444', 'border' => '#fca5a5'],
    'Century'    => ['bg' => 'rgba(59,130,246,.15)',  'color' => '#3b82f6', 'border' => '#93c5fd'],
    'Multilines' => ['bg' => 'rgba(234,179,8,.15)',   'color' => '#ca8a04', 'border' => '#fde047'],
    'NutriAsia'  => ['bg' => 'rgba(16,185,129,.15)',  'color' => '#059669', 'border' => '#6ee7b7'],
    ''           => ['bg' => 'rgba(107,114,128,.15)', 'color' => '#6b7280', 'border' => '#9ca3af'],
];
$_dc        = $_deptColors[$_topbar_dept] ?? $_deptColors[''];
$_ddStyle   = "background:{$_dc['bg']};color:{$_dc['color']};border-color:{$_dc['border']};";
$_deptLabel = $_topbar_dept !== '' ? htmlspecialchars($_topbar_dept) : 'All Departments';
?>

<!-- APP_URL for JS/AJAX -->
<script>const APP_URL = "<?= base_url() ?>";</script>

<!-- ══ TOPBAR ══════════════════════════════════════════════════ -->
<header class="topbar">

  <!-- Brand -->
  <a href="<?= route('home') ?>" class="topbar-brand">
    <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo"
         class="topbar-brand-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
    <div class="topbar-brand-icon" style="display:none;">
      <i class="bi bi-briefcase-fill"></i>
    </div>
    <div class="topbar-brand-text">
      <span class="topbar-brand-name">Urban Tradewell Corporation</span>
      <span class="topbar-brand-sub"><?= $_brand_sub ?></span>
    </div>
  </a>

  <div class="topbar-divider"></div>

  <!-- Date chip -->
  <span class="topbar-date">
    <?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('D, M d Y · g:i A') ?>
  </span>

  <div class="topbar-sep"></div>

  <!-- Department badge -->
  <?php if ($_can_admin): ?>
  <a href="<?= route('set_department') ?>" class="dept-dropdown-btn"
     style="<?= $_ddStyle ?>" title="Switch Department">
    <i class="bi bi-building"></i>
    <?= $_deptLabel ?>
    <i class="bi bi-pencil-square" style="font-size:.62rem;opacity:.7;"></i>
  </a>
  <?php elseif ($_topbar_dept): ?>
  <span class="dept-dropdown-btn" style="<?= $_ddStyle ?>;cursor:default;">
    <i class="bi bi-building"></i>
    <?= $_deptLabel ?>
  </span>
  <?php endif; ?>

  <div class="topbar-divider"></div>

  <!-- Avatar dropdown -->
  <div class="tb-avatar-wrap" id="tbAvatarWrap">
    <button class="tb-avatar-btn" id="tbAvatarBtn"
            title="Account menu" aria-haspopup="true" aria-expanded="false">
      <span class="tb-avatar-initials"><?= strtoupper(substr($_topbar_user, 0, 1)) ?></span>
    </button>

    <div class="tb-dropdown" id="tbDropdown" role="menu">

      <!-- User info -->
      <div class="tb-drop-user">
        <div class="tb-drop-avatar-lg"><?= strtoupper(substr($_topbar_user, 0, 1)) ?></div>
        <div>
          <div class="tb-drop-name"><?= htmlspecialchars($_topbar_user) ?></div>
          <div class="tb-drop-meta">
            <span class="tb-drop-role-badge"><?= htmlspecialchars($_topbar_role) ?></span>
            <?php if ($_topbar_dept): ?>
            <span style="<?= $_ddStyle ?>;padding:.1rem .45rem;border-radius:20px;font-size:.65rem;font-weight:700;border:1px solid;">
              <?= htmlspecialchars($_topbar_dept) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="tb-drop-divider"></div>

      <!-- Switch Department + RBAC Manager (admin only) -->
      <?php if ($_can_admin || $_can_rbac): ?>
      <?php if ($_can_admin): ?>
      <a href="<?= route('set_department') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'dept' ? 'active' : '' ?>">
        <i class="bi bi-building"></i> Switch Department
      </a>
      <?php endif; ?>
      <?php if ($_can_rbac): ?>
      <a href="<?= rbac_module_url('RBAC') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'rbac' ? 'active' : '' ?>">
        <i class="bi bi-shield-lock-fill"></i> Access Control (RBAC)
      </a>
      <?php endif; ?>
      <div class="tb-drop-divider"></div>
      <?php endif; ?>

<a href="<?= route('home') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'home' ? 'active' : '' ?>">
        <i class="bi bi-house-door-fill"></i> Home
      </a>

      <div class="tb-drop-divider"></div>

      <div class="tb-nav-scroll">

        <?php if ($_has_fleet): ?>
        <div class="tb-nav-group">
          <button class="tb-group-header" aria-expanded="true" onclick="tbToggleGroup(this)">
            <span class="tb-group-label"><i class="bi bi-fuel-pump-fill"></i> Fleet</span>
            <i class="bi bi-chevron-down tb-group-chevron"></i>
          </button>
          <div class="tb-group-items">
            <?php if ($_can_fuel): ?>
            <a href="<?= rbac_module_url('fuel_dashboard') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'fuel' ? 'active' : '' ?>">
              <i class="bi bi-fuel-pump-fill"></i> Fuel Dashboard
            </a>
            <?php endif; ?>
            <?php if ($_can_graphs): ?>
            <a href="<?= rbac_module_url('graphs') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'graphs' ? 'active' : '' ?>">
              <i class="bi bi-bar-chart-fill"></i> Fuel Graphs
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($_has_hr): ?>
        <div class="tb-nav-group">
          <button class="tb-group-header" aria-expanded="true" onclick="tbToggleGroup(this)">
            <span class="tb-group-label"><i class="bi bi-people-fill"></i> HR &amp; Careers</span>
            <i class="bi bi-chevron-down tb-group-chevron"></i>
          </button>
          <div class="tb-group-items">
            <?php if ($_can_careers): ?>
            <a href="<?= rbac_module_url('careers_admin') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'careers' ? 'active' : '' ?>">
              <i class="bi bi-file-earmark-person"></i> Careers Admin
            </a>
            <?php endif; ?>
            <?php if ($_can_view_apps): ?>
            <a href="<?= rbac_module_url('view_applications') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'applications' ? 'active' : '' ?>">
              <i class="bi bi-file-earmark-person-fill"></i> Job Applications
            </a>
            <?php endif; ?>
            <?php if ($_can_employees): ?>
            <a href="<?= rbac_module_url('employee_list') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'employees' ? 'active' : '' ?>">
              <i class="bi bi-people-fill"></i> Employee List
            </a>
            <?php endif; ?>
            <?php if ($_can_uniform): ?>
            <a href="<?= rbac_module_url('uniform_inventory') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'uniform' ? 'active' : '' ?>">
              <i class="bi bi-bag-fill"></i> Uniform Inventory
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($_has_finance): ?>
        <div class="tb-nav-group">
          <button class="tb-group-header" aria-expanded="true" onclick="tbToggleGroup(this)">
            <span class="tb-group-label"><i class="bi bi-cash-coin"></i> Finance</span>
            <i class="bi bi-chevron-down tb-group-chevron"></i>
          </button>
          <div class="tb-group-items">
            <?php if ($_can_po): ?>
            <a href="<?= rbac_module_url('po_index') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'po' ? 'active' : '' ?>">
              <i class="bi bi-receipt-cutoff"></i> Purchase Orders
            </a>
            <?php endif; ?>
            <?php if ($_can_delivery): ?>
            <a href="<?= rbac_module_url('delivery_remittance') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'delivery' ? 'active' : '' ?>">
              <i class="bi bi-receipt"></i> Delivery Remittance
            </a>
            <?php endif; ?>
            <?php if ($_can_ar): ?>
            <a href="<?= rbac_module_url('ar_remittance') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'ar' ? 'active' : '' ?>">
              <i class="bi bi-receipt"></i> AR Remittance
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <div class="tb-drop-divider"></div>

      <a href="<?= route('help') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'help' ? 'active' : '' ?>">
        <i class="bi bi-book-fill"></i> Help Manual
      </a>

      <div class="tb-drop-divider"></div>

      <a href="<?= route('logout') ?>" class="tb-drop-item tb-drop-logout">
        <i class="bi bi-box-arrow-right"></i> Log Out
      </a>

    </div>
  </div>

</header>

<script>
(function () {
  var btn  = document.getElementById('tbAvatarBtn');
  var drop = document.getElementById('tbDropdown');
  if (!btn || !drop) return;
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = drop.classList.toggle('open');
    btn.setAttribute('aria-expanded', open);
  });
  document.addEventListener('click', function () {
    drop.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      drop.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    }
  });
  drop.addEventListener('click', function (e) { e.stopPropagation(); });
})();

function tbToggleGroup(btn) {
  var expanded = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', !expanded);
  btn.nextElementSibling.classList.toggle('collapsed', expanded);
}

setInterval(() => {
  fetch('/TWM/check_session.php')
    .then(res => res.json())
    .then(data => {
      if (!data.loggedIn) window.location.href = '/TWM/login.php';
    });
}, 30000);

</script>

<style>
.tb-nav-scroll {
  max-height: 280px;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.tb-nav-scroll::-webkit-scrollbar { width: 4px; }
.tb-nav-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 4px; }

.tb-nav-group { border-bottom: 1px solid rgba(0,0,0,.05); }
.tb-nav-group:last-child { border-bottom: none; }

.tb-group-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: .45rem 1rem;
  background: none;
  border: none;
  cursor: pointer;
  color: inherit;
}
.tb-group-header:hover { background: rgba(0,0,0,.04); }

.tb-group-label {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #9ca3af;
}

.tb-group-chevron {
  font-size: .65rem;
  color: #9ca3af;
  transition: transform .2s ease;
}
.tb-group-header[aria-expanded="false"] .tb-group-chevron {
  transform: rotate(-90deg);
}

.tb-group-items {
  overflow: hidden;
  transition: max-height .22s ease;
  max-height: 500px;
}
.tb-group-items.collapsed {
  max-height: 0;
}
</style>