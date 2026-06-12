<?php
// /TWM/includes/topbar.php

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

// ── FIX 1: h() declared here at the TOP so it is available to all
//           HTML below. Previously it was declared at line 608, after
//           hundreds of calls to it — a fatal "undefined function" risk.
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// ── Session vars ──────────────────────────────────────────────
$_topbar_user = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';
$_topbar_role = $_SESSION['UserType']    ?? '';
$_topbar_dept = $_SESSION['Department']  ?? '';

// ── RBAC ──────────────────────────────────────────────────────
if ($pdo) rbac_load_permissions($pdo, $_topbar_role);
$_can_rbac  = rbac_can('RBAC');
$_can_admin = $_can_rbac || in_array($_topbar_role, ['Admin', 'Administrator', 'HR']);

// ── Employee profile ──────────────────────────────────────────
$_ep = get_employee_profile($conn);

// ── Helper: safe field accessor ───────────────────────────────
function _ep(array|null $ep, string $key): string {
    if (!$ep) return '';
    $v = $ep[$key] ?? '';
    if ($v instanceof DateTime) return $v->format('Y-m-d');
    return (string) $v;
}

$_display_name = $_ep
    ? trim(
        _ep($_ep, 'FirstName') . ' ' .
        (_ep($_ep, 'MiddleName') ? substr(_ep($_ep, 'MiddleName'), 0, 1) . '. ' : '') .
        _ep($_ep, 'LastName')
    )
    : $_topbar_user;
$_display_name = $_display_name ?: $_topbar_user;

$_initials = $_ep
    ? strtoupper(substr(_ep($_ep, 'FirstName'), 0, 1) . substr(_ep($_ep, 'LastName'), 0, 1))
    : strtoupper(substr($_topbar_user, 0, 1));

$_position  = _ep($_ep, 'Job_tittle') ?: _ep($_ep, 'Position_held') ?: $_topbar_role;
$_dept      = _ep($_ep, 'Department') ?: $_topbar_dept;
$_status    = _ep($_ep, 'Employee_Status');

// Profile photo — same path logic as employee-list.php
$_pic_raw   = trim(_ep($_ep, 'Picture'));
if ($_pic_raw && !str_starts_with($_pic_raw, '/')) $_pic_raw = '/TWM/' . $_pic_raw;
$_photo     = $_pic_raw ?: null;

// FIX 3: _ep() always returns a string, so === 1 (int) can never match.
//         Simplified to a single strict string check.
$_is_active = (_ep($_ep, 'Active') === '1');

// ── Department color map ──────────────────────────────────────
$_deptColors = [
    'Monde'      => ['bg' => 'rgba(239,68,68,.15)',   'color' => '#ef4444', 'border' => '#fca5a5'],
    'Century'    => ['bg' => 'rgba(59,130,246,.15)',  'color' => '#3b82f6', 'border' => '#93c5fd'],
    'Multilines' => ['bg' => 'rgba(234,179,8,.15)',   'color' => '#ca8a04', 'border' => '#fde047'],
    'NutriAsia'  => ['bg' => 'rgba(16,185,129,.15)',  'color' => '#059669', 'border' => '#6ee7b7'],
    ''           => ['bg' => 'rgba(107,114,128,.15)', 'color' => '#6b7280', 'border' => '#9ca3af'],
];
$_dc       = $_deptColors[$_topbar_dept] ?? $_deptColors[''];
$_ddStyle  = "background:{$_dc['bg']};color:{$_dc['color']};border-color:{$_dc['border']};";
$_deptLabel = $_topbar_dept !== '' ? htmlspecialchars($_topbar_dept) : 'All Departments';

// ── Brand subtitle ────────────────────────────────────────────
if ($_can_admin)                    $_brand_sub = 'Admin Portal';
elseif (rbac_can('fuel_dashboard')) $_brand_sub = 'Fleet Monitoring';
elseif (rbac_can('careers_admin'))  $_brand_sub = 'Careers Admin';
else                                $_brand_sub = 'Portal';

// ── Pre-format dates for display ──────────────────────────────
$_hired_disp = fmt_date(_ep($_ep, 'Hired_date'),          'M d, Y');
$_hired_raw  = fmt_date(_ep($_ep, 'Hired_date'),          'Y-m-d');
$_birth_disp = fmt_date(_ep($_ep, 'Birth_date'),          'M d, Y');
$_sep_disp   = fmt_date(_ep($_ep, 'Date_Of_Seperation'),  'M d, Y');
$_service    = years_of_service(_ep($_ep, 'Hired_date'));
?>
<!-- ══ STYLES (placed before any HTML to prevent flash-of-unstyled-content) ══ -->
<style>
/* ── Dropdown ─────────────────────────────────────────────────── */
.tb-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:252px;background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,.08);box-shadow:0 12px 40px rgba(0,0,0,.14),0 2px 8px rgba(0,0,0,.06);opacity:0;pointer-events:none;transform:translateY(-6px) scale(.97);transform-origin:top right;transition:opacity .18s ease,transform .18s ease;z-index:1000;overflow:hidden;display:none;}
.tb-dropdown.open{opacity:1;pointer-events:all;transform:translateY(0) scale(1);display:block;}

/* Header */
.tb-drop-header{display:flex;align-items:center;gap:.7rem;padding:.9rem 1rem .75rem;background:linear-gradient(150deg,rgba(99,102,241,.07),rgba(59,130,246,.03));border-bottom:1px solid rgba(0,0,0,.06);}
.tb-drop-avatar-wrap{position:relative;flex-shrink:0;}
.tb-drop-avatar-lg{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:.95rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
.tb-drop-avatar-img{width:44px;height:44px;border-radius:50%;object-fit:cover;display:block;}
.tb-avatar-photo{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block;}
.pm-avatar-img{object-fit:cover;font-size:0;}
.tb-drop-status-dot{position:absolute;bottom:1px;right:1px;width:11px;height:11px;border-radius:50%;border:2px solid #fff;}
.tb-dot-active{background:#22c55e;}.tb-dot-inactive{background:#9ca3af;}
.tb-drop-identity-text{min-width:0;flex:1;}
.tb-drop-name{font-size:.82rem;font-weight:700;color:#111827;white-space:normal;overflow:visible;text-overflow:unset;line-height:1.3;}
.tb-drop-sub{font-size:.68rem;color:#6b7280;margin-top:.08rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tb-drop-badges{display:flex;flex-wrap:wrap;gap:.25rem;margin-top:.35rem;}
.tb-drop-badge{font-size:.58rem;font-weight:700;border-radius:20px;padding:.1rem .42rem;border:1px solid transparent;}
.tb-drop-badge-dept{background:rgba(99,102,241,.1);color:#6366f1;border-color:rgba(99,102,241,.2);}
.tb-drop-badge-status{background:rgba(34,197,94,.1);color:#16a34a;border-color:rgba(34,197,94,.2);}

/* Menu */
.tb-drop-menu{padding:.35rem .45rem;}
.tb-drop-footer{padding:.35rem .45rem .45rem;border-top:1px solid rgba(0,0,0,.06);}

.tb-drop-item{display:flex;align-items:center;gap:.6rem;width:100%;padding:.48rem .55rem;border-radius:9px;border:none;background:none;cursor:pointer;text-decoration:none;color:#374151;font-family:inherit;font-size:.81rem;font-weight:500;transition:background .12s,color .12s;text-align:left;}
.tb-drop-item:hover{background:rgba(0,0,0,.045);color:#111827;}
.tb-drop-item-active{background:rgba(99,102,241,.08);color:#6366f1;}
.tb-drop-item-active:hover{background:rgba(99,102,241,.13);color:#6366f1;}
.tb-drop-item-danger{color:#dc2626;}
.tb-drop-item-danger:hover{background:rgba(220,38,38,.07);color:#b91c1c;}

/* Icon tiles */
.tb-drop-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.82rem;}
.tb-drop-icon-indigo{background:rgba(99,102,241,.12);color:#6366f1;}
.tb-drop-icon-blue{background:rgba(59,130,246,.1);color:#2563eb;}
.tb-drop-icon-amber{background:rgba(234,179,8,.12);color:#b45309;}
.tb-drop-icon-gray{background:rgba(107,114,128,.1);color:#6b7280;}
.tb-drop-icon-red{background:rgba(220,38,38,.1);color:#dc2626;}

/* Label + extras */
.tb-drop-label{flex:1;}
.tb-drop-chevron{font-size:.65rem;color:#9ca3af;}
.tb-drop-tag{font-size:.58rem;font-weight:700;background:rgba(234,179,8,.12);color:#b45309;border:1px solid rgba(234,179,8,.25);border-radius:20px;padding:.1rem .42rem;}

/* ══ Modal ══ */
.pm-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;padding:1rem;opacity:0;pointer-events:none;transition:opacity .2s ease;backdrop-filter:blur(4px);}
.pm-overlay.open{display:flex;opacity:1;pointer-events:all;}
.pm-modal{background:#fff;border-radius:20px;width:100%;max-width:660px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 32px 80px rgba(0,0,0,.25);transform:translateY(24px) scale(.97);transition:transform .25s cubic-bezier(.22,.68,0,1.2);overflow:hidden;}
.pm-overlay.open .pm-modal{transform:translateY(0) scale(1);}

/* Header */
.pm-header{display:flex;align-items:flex-start;gap:1rem;padding:1.4rem 1.4rem 1rem;background:linear-gradient(135deg,#f8faff,#eef2ff);border-bottom:1px solid #e5e7eb;flex-shrink:0;}
.pm-avatar-wrap{position:relative;flex-shrink:0;}
.pm-avatar{width:62px;height:62px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:1.35rem;font-weight:800;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(99,102,241,.35);}
.pm-status-dot{position:absolute;bottom:2px;right:2px;width:13px;height:13px;border-radius:50%;border:2.5px solid #fff;}
.pm-dot-active{background:#22c55e;} .pm-dot-inactive{background:#9ca3af;}
.pm-header-info{flex:1;min-width:0;}
.pm-full-name{font-size:1.1rem;font-weight:800;color:#111827;letter-spacing:-.02em;line-height:1.2;margin-bottom:.18rem;}
.pm-position{font-size:.78rem;color:#6b7280;margin-bottom:.45rem;}
.pm-badges{display:flex;flex-wrap:wrap;gap:.28rem;}
.pm-badge{padding:.16rem .52rem;border-radius:20px;font-size:.64rem;font-weight:700;border:1px solid transparent;}
.pm-badge-role{background:rgba(99,102,241,.1);color:#6366f1;border-color:rgba(99,102,241,.25);}
.pm-badge-cat{background:rgba(234,179,8,.1);color:#b45309;border-color:rgba(234,179,8,.3);}
.pm-badge-active{background:rgba(34,197,94,.1);color:#16a34a;border-color:rgba(34,197,94,.3);}
.pm-badge-inactive{background:rgba(107,114,128,.1);color:#6b7280;border-color:rgba(107,114,128,.3);}
.pm-badge-blacklist{background:rgba(239,68,68,.1);color:#dc2626;border-color:rgba(239,68,68,.3);}

.pm-header-actions{display:flex;gap:.5rem;flex-shrink:0;align-items:flex-start;}
.pm-btn-edit{display:flex;align-items:center;gap:.35rem;padding:.38rem .85rem;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;color:#374151;font-size:.76rem;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit;}
.pm-btn-edit:hover{border-color:#6366f1;color:#6366f1;background:rgba(99,102,241,.06);}
.pm-btn-edit.active{background:#6366f1;color:#fff;border-color:#6366f1;}
.pm-close{background:rgba(0,0,0,.06);border:none;border-radius:9px;width:32px;height:32px;cursor:pointer;color:#6b7280;display:flex;align-items:center;justify-content:center;transition:background .15s,color .15s;font-size:.85rem;}
.pm-close:hover{background:rgba(0,0,0,.12);color:#111827;}

/* Tabs */
.pm-tabs{display:flex;border-bottom:1px solid #e5e7eb;padding:0 1.25rem;overflow-x:auto;flex-shrink:0;scrollbar-width:none;}
.pm-tabs::-webkit-scrollbar{display:none;}
.pm-tab{background:none;border:none;cursor:pointer;font-family:inherit;font-size:.76rem;font-weight:600;color:#9ca3af;padding:.7rem .8rem;border-bottom:2.5px solid transparent;margin-bottom:-1px;white-space:nowrap;display:flex;align-items:center;gap:.3rem;transition:color .15s,border-color .15s;}
.pm-tab:hover{color:#6366f1;}
.pm-tab.active{color:#6366f1;border-bottom-color:#6366f1;}

/* Save bar */
.pm-save-bar{display:none;align-items:center;justify-content:space-between;gap:1rem;padding:.6rem 1.25rem;background:#fffbeb;border-bottom:1px solid #fde68a;flex-shrink:0;}
.pm-save-bar.visible{display:flex;}
.pm-save-hint{font-size:.76rem;color:#92400e;font-weight:600;display:flex;align-items:center;gap:.35rem;}
.pm-save-actions{display:flex;gap:.5rem;}
.pm-btn-cancel{padding:.38rem .85rem;border-radius:9px;border:1.5px solid #d1d5db;background:#fff;color:#374151;font-size:.76rem;font-weight:700;cursor:pointer;font-family:inherit;transition:border-color .15s;}
.pm-btn-cancel:hover{border-color:#9ca3af;}
.pm-btn-save{display:flex;align-items:center;gap:.35rem;padding:.38rem .9rem;border-radius:9px;border:none;background:#6366f1;color:#fff;font-size:.76rem;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s;}
.pm-btn-save:hover{background:#4f46e5;}
.pm-btn-save:disabled{opacity:.6;cursor:not-allowed;}

/* Body */
.pm-body{flex:1;overflow-y:auto;padding:1.1rem 1.25rem;}
.pm-body::-webkit-scrollbar{width:5px;}
.pm-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.1);border-radius:4px;}
.pm-tab-panel{display:none;}
.pm-tab-panel.active{display:block;}

/* Grid */
.pm-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem 1rem;}
.pm-field-full{grid-column:1/-1;}
.pm-field{background:#f9fafb;border:1px solid #f3f4f6;border-radius:11px;padding:.65rem .85rem;transition:border-color .15s;}
.pm-field-label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9ca3af;margin-bottom:.28rem;display:flex;align-items:center;gap:.28rem;}
.pm-field-label i{font-size:.68rem;}
.pm-field-value{font-size:.82rem;font-weight:600;color:#111827;line-height:1.4;display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;}
.pm-mono{font-family:'Courier New',monospace;letter-spacing:.04em;}

/* Editable inputs */
.pm-input{width:100%;padding:.42rem .65rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.82rem;font-weight:500;color:#111827;background:#fff;font-family:inherit;outline:none;transition:border-color .15s,box-shadow .15s;}
.pm-input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12);}
.pm-textarea{resize:vertical;min-height:70px;}

/* Chips */
.pm-chip-green{font-size:.65rem;font-weight:700;background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.25);border-radius:20px;padding:.08rem .42rem;}
.pm-chip-gray{font-size:.65rem;font-weight:700;background:rgba(107,114,128,.1);color:#6b7280;border:1px solid rgba(107,114,128,.25);border-radius:20px;padding:.08rem .42rem;}
.pm-chip-red{font-size:.65rem;font-weight:700;background:rgba(239,68,68,.1);color:#dc2626;border:1px solid rgba(239,68,68,.25);border-radius:20px;padding:.08rem .42rem;}

/* Gov note */
.pm-gov-note{font-size:.75rem;color:#6b7280;background:#f3f4f6;border-radius:10px;padding:.65rem .85rem;display:flex;align-items:flex-start;gap:.45rem;line-height:1.5;}
.pm-gov-note i{color:#9ca3af;margin-top:.1rem;flex-shrink:0;}

/* Toast */
.pm-toast{position:fixed;bottom:1.5rem;right:1.5rem;z-index:10001;padding:.7rem 1.1rem;border-radius:12px;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.15);opacity:0;transform:translateY(10px);transition:all .25s ease;pointer-events:none;}
.pm-toast.show{opacity:1;transform:translateY(0);}
.pm-toast-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
.pm-toast-error{background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca;}
@keyframes pm-spin{to{transform:rotate(360deg);}}.pm-spin{display:inline-block;animation:pm-spin .7s linear infinite;}

/* Responsive */
@media(max-width:520px){
  .pm-grid{grid-template-columns:1fr;}
  .pm-field-full{grid-column:1;}
  .pm-header{padding:1rem;gap:.7rem;}
  .pm-avatar{width:50px;height:50px;font-size:1rem;}
  .pm-full-name{font-size:1rem;}
  .pm-body{padding:.9rem 1rem;}
  .pm-tabs{padding:0 .75rem;}
}
</style>

<script>const APP_URL = "<?= base_url() ?>";</script>

<!-- ══ TOPBAR ═════════════════════════════════════════════════ -->
<header class="topbar">

  <a href="<?= route('home') ?>" class="topbar-brand">
    <img src="<?= base_url('assets/img/logo.png') ?>" alt="Logo" class="topbar-brand-logo"
         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
    <div class="topbar-brand-icon" style="display:none;"><i class="bi bi-briefcase-fill"></i></div>
    <div class="topbar-brand-text">
      <span class="topbar-brand-name">Urban Tradewell Corporation</span>
      <span class="topbar-brand-sub"><?= $_brand_sub ?></span>
    </div>
  </a>

  <div class="topbar-divider"></div>

  <span class="topbar-date">
    <?= (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('D, M d Y · g:i A') ?>
  </span>

  <div class="topbar-sep"></div>

  <?php if ($_can_admin): ?>
  <a href="<?= route('set_department') ?>" class="dept-dropdown-btn" style="<?= $_ddStyle ?>" title="Switch Department">
    <i class="bi bi-building"></i> <?= $_deptLabel ?>
    <i class="bi bi-pencil-square" style="font-size:.62rem;opacity:.7;"></i>
  </a>
  <?php elseif ($_topbar_dept): ?>
  <span class="dept-dropdown-btn" style="<?= $_ddStyle ?>;cursor:default;">
    <i class="bi bi-building"></i> <?= $_deptLabel ?>
  </span>
  <?php endif; ?>

  <div class="topbar-divider"></div>

  <!-- Avatar + dropdown -->
  <div class="tb-avatar-wrap" id="tbAvatarWrap">
    <button class="tb-avatar-btn" id="tbAvatarBtn" title="Account" aria-haspopup="true" aria-expanded="false">
      <?php if ($_photo): ?>
        <img src="<?= htmlspecialchars($_photo) ?>" class="tb-avatar-photo" alt="<?= htmlspecialchars($_initials) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <span class="tb-avatar-initials" style="display:none;"><?= $_initials ?></span>
      <?php else: ?>
        <span class="tb-avatar-initials"><?= $_initials ?></span>
      <?php endif; ?>
    </button>

    <div class="tb-dropdown" id="tbDropdown" role="menu">

      <!-- Identity header -->
      <div class="tb-drop-header">
        <div class="tb-drop-avatar-wrap">
          <?php if ($_photo): ?>
            <img src="<?= htmlspecialchars($_photo) ?>" class="tb-drop-avatar-lg tb-drop-avatar-img" alt="<?= htmlspecialchars($_initials) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
            <div class="tb-drop-avatar-lg" style="display:none;"><?= $_initials ?></div>
          <?php else: ?>
            <div class="tb-drop-avatar-lg"><?= $_initials ?></div>
          <?php endif; ?>
          <span class="tb-drop-status-dot <?= $_is_active ? 'tb-dot-active' : 'tb-dot-inactive' ?>"></span>
        </div>
        <div class="tb-drop-identity-text">
          <div class="tb-drop-name"><?= htmlspecialchars($_display_name) ?></div>
          <div class="tb-drop-sub"><?= htmlspecialchars($_position) ?><?= $_position && $_topbar_role ? ' · ' : '' ?><?= htmlspecialchars($_topbar_role) ?></div>
          <div class="tb-drop-badges">
            <?php if ($_dept): ?>
            <span class="tb-drop-badge tb-drop-badge-dept" style="<?= $_ddStyle ?>"><?= htmlspecialchars($_dept) ?></span>
            <?php endif; ?>
            <?php if (_ep($_ep, 'Employee_Status')): ?>
            <span class="tb-drop-badge tb-drop-badge-status"><?= htmlspecialchars(_ep($_ep, 'Employee_Status')) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Menu items -->
      <div class="tb-drop-menu">

        <button class="tb-drop-item" onclick="tbOpenProfile()">
          <span class="tb-drop-icon tb-drop-icon-indigo">
            <i class="bi bi-person-circle"></i>
          </span>
          <span class="tb-drop-label">My Profile</span>
          <i class="bi bi-chevron-right tb-drop-chevron"></i>
        </button>

        <a href="<?= route('home') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'home' ? 'tb-drop-item-active' : '' ?>">
          <span class="tb-drop-icon tb-drop-icon-blue">
            <i class="bi bi-house-door-fill"></i>
          </span>
          <span class="tb-drop-label">Home</span>
        </a>

        <?php if ($_can_rbac): ?>
        <a href="<?= rbac_module_url('RBAC') ?>" class="tb-drop-item">
          <span class="tb-drop-icon tb-drop-icon-amber">
            <i class="bi bi-shield-lock-fill"></i>
          </span>
          <span class="tb-drop-label">Access Control</span>
          <span class="tb-drop-tag">RBAC</span>
        </a>
        <?php endif; ?>

        <?php if ($_can_admin): ?>
        <a href="<?= route('set_department') ?>" class="tb-drop-item">
          <span class="tb-drop-icon tb-drop-icon-gray">
            <i class="bi bi-building"></i>
          </span>
          <span class="tb-drop-label">Switch Department</span>
        </a>
        <?php endif; ?>

        <a href="<?= route('help') ?>" class="tb-drop-item <?= ($topbar_page ?? '') === 'help' ? 'tb-drop-item-active' : '' ?>">
          <span class="tb-drop-icon tb-drop-icon-gray">
            <i class="bi bi-book-fill"></i>
          </span>
          <span class="tb-drop-label">Help Manual</span>
        </a>

      </div>

      <!-- Logout -->
      <div class="tb-drop-footer">
        <a href="<?= route('logout') ?>" class="tb-drop-item tb-drop-item-danger">
          <span class="tb-drop-icon tb-drop-icon-red">
            <i class="bi bi-box-arrow-right"></i>
          </span>
          <span class="tb-drop-label">Log Out</span>
        </a>
      </div>

    </div>
  </div>

</header>

<!-- ══ PROFILE MODAL ══════════════════════════════════════════ -->
<div class="pm-overlay" id="pmOverlay" onclick="tbCloseProfile()">
<div class="pm-modal" onclick="event.stopPropagation()" role="dialog" aria-modal="true">

  <!-- Header -->
  <div class="pm-header">
    <div class="pm-avatar-wrap" <?= $_photo ? 'onclick="tbOpenPhotoPreview()" style="cursor:pointer;" title="View photo"' : '' ?>>
      <?php if ($_photo): ?>
        <img src="<?= htmlspecialchars($_photo) ?>" class="pm-avatar pm-avatar-img" alt="<?= htmlspecialchars($_initials) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="pm-avatar" style="display:none;"><?= $_initials ?></div>
      <?php else: ?>
        <div class="pm-avatar"><?= $_initials ?></div>
      <?php endif; ?>
      <span class="pm-status-dot <?= $_is_active ? 'pm-dot-active' : 'pm-dot-inactive' ?>"></span>
    </div>
    <div class="pm-header-info">
      <div class="pm-full-name"><?= htmlspecialchars($_display_name) ?></div>
      <?php if ($_position): ?>
      <div class="pm-position"><?= htmlspecialchars($_position) ?></div>
      <?php endif; ?>
      <div class="pm-badges">
        <span class="pm-badge pm-badge-role"><?= htmlspecialchars($_topbar_role) ?></span>
        <?php if ($_dept): ?>
        <span class="pm-badge" style="<?= $_ddStyle ?>"><?= htmlspecialchars($_dept) ?></span>
        <?php endif; ?>
        <?php if (_ep($_ep, 'Category')): ?>
        <span class="pm-badge pm-badge-cat"><?= htmlspecialchars(_ep($_ep, 'Category')) ?></span>
        <?php endif; ?>
        <?php if ($_status): ?>
        <span class="pm-badge <?= $_is_active ? 'pm-badge-active' : 'pm-badge-inactive' ?>">
          <?= htmlspecialchars($_status) ?>
        </span>
        <?php endif; ?>
        <?php if (_ep($_ep, 'Blacklisted') === '1'): ?>
        <span class="pm-badge pm-badge-blacklist"><i class="bi bi-slash-circle-fill"></i> Blacklisted</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="pm-header-actions">
      <button class="pm-btn-edit" id="pmEditBtn" onclick="tbToggleEdit()" title="Edit profile">
        <i class="bi bi-pencil-fill"></i> Edit
      </button>
      <button class="pm-close" onclick="tbCloseProfile()" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
  </div>

  <!-- Tabs -->
  <div class="pm-tabs">
    <button class="pm-tab active" onclick="tbSwitchTab(this,'work')"><i class="bi bi-briefcase-fill"></i> Work</button>
    <button class="pm-tab" onclick="tbSwitchTab(this,'personal')"><i class="bi bi-person-fill"></i> Personal</button>
    <button class="pm-tab" onclick="tbSwitchTab(this,'contact')"><i class="bi bi-telephone-fill"></i> Contact</button>
    <button class="pm-tab" onclick="tbSwitchTab(this,'gov')"><i class="bi bi-shield-fill"></i> Gov't IDs</button>
    <button class="pm-tab" onclick="tbSwitchTab(this,'emergency')"><i class="bi bi-heart-pulse-fill"></i> Emergency</button>
    <button class="pm-tab" onclick="tbSwitchTab(this,'system')"><i class="bi bi-gear-fill"></i> System</button>
  </div>

  <!-- Save bar (edit mode) -->
  <div class="pm-save-bar" id="pmSaveBar">
    <span class="pm-save-hint"><i class="bi bi-pencil-square"></i> Editing — changes will update your profile</span>
    <div class="pm-save-actions">
      <button class="pm-btn-cancel" onclick="tbCancelEdit()">Cancel</button>
      <button class="pm-btn-save"   onclick="tbSaveProfile()"><i class="bi bi-check-lg"></i> Save Changes</button>
    </div>
  </div>

  <!-- Body -->
  <form id="pmForm" class="pm-body">
    <input type="hidden" name="_action" value="profile_save">

    <!-- ── WORK ──────────────────────────────────────────────── -->
    <div class="pm-tab-panel active" id="pmTab-work">
      <div class="pm-grid">

        <?php $v = _ep($_ep, 'EmployeeID'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-badge-fill"></i> Employee ID</div>
          <div class="pm-field-value pm-readonly"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'FileNo'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-file-earmark-fill"></i> File No.</div>
          <div class="pm-field-value pm-readonly"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <!-- Editable: Department (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-building"></i> Department</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Department')) ?: '—' ?></div>
          <input type="text" name="Department" class="pm-input" value="<?= h(_ep($_ep, 'Department')) ?>" style="display:none;">
        </div>

        <!-- Editable: Branch (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-geo-alt-fill"></i> Branch</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Branch')) ?: '—' ?></div>
          <input type="text" name="Branch" class="pm-input" value="<?= h(_ep($_ep, 'Branch')) ?>" style="display:none;">
        </div>

        <!-- Editable: Job Title (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-workspace"></i> Job Title</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Job_tittle')) ?: '—' ?></div>
          <input type="text" name="Job_tittle" class="pm-input" value="<?= h(_ep($_ep, 'Job_tittle')) ?>" style="display:none;">
        </div>

        <!-- Editable: Position Held (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-award-fill"></i> Position Held</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Position_held')) ?: '—' ?></div>
          <input type="text" name="Position_held" class="pm-input" value="<?= h(_ep($_ep, 'Position_held')) ?>" style="display:none;">
        </div>

        <!-- Editable: Category (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-tag-fill"></i> Category</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Category')) ?: '—' ?></div>
          <input type="text" name="Category" class="pm-input" value="<?= h(_ep($_ep, 'Category')) ?>" style="display:none;">
        </div>

        <!-- Editable: Employment Status (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-activity"></i> Employment Status</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Employee_Status')) ?: '—' ?></div>
          <input type="text" name="Employee_Status" class="pm-input" value="<?= h(_ep($_ep, 'Employee_Status')) ?>" placeholder="e.g. Regular, Probationary" style="display:none;">
        </div>

        <?php if ($_hired_disp !== '—'): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-calendar2-check-fill"></i> Date Hired</div>
          <div class="pm-field-value pm-readonly">
            <?= $_hired_disp ?>
            <?php if ($_service !== '—'): ?>
            <span class="pm-chip-green"><?= $_service ?> of service</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($_sep_disp !== '—'): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-calendar-x-fill"></i> Date of Separation</div>
          <div class="pm-field-value pm-readonly"><?= $_sep_disp ?></div>
        </div>
        <?php endif; ?>

        <!-- Editable: Cut-Off -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-scissors"></i> Cut-Off</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'CutOff')) ?: '—' ?></div>
          <input type="text" name="CutOff" class="pm-input" value="<?= h(_ep($_ep, 'CutOff')) ?>" style="display:none;">
        </div>

      </div>
    </div>

    <!-- ── PERSONAL ───────────────────────────────────────────── -->
    <div class="pm-tab-panel" id="pmTab-personal">
      <div class="pm-grid">

        <?php $v = _ep($_ep, 'FirstName'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-fill"></i> First Name</div>
          <div class="pm-field-value pm-readonly"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'MiddleName'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-fill"></i> Middle Name</div>
          <div class="pm-field-value pm-readonly"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'LastName'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-fill"></i> Last Name</div>
          <div class="pm-field-value pm-readonly"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <!-- Editable: Gender (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-gender-ambiguous"></i> Gender</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Gender')) ?: '—' ?></div>
          <select name="Gender" class="pm-input" style="display:none;">
            <option value="">— Select —</option>
            <?php foreach (['Male', 'Female', 'Prefer not to say'] as $g): ?>
            <option value="<?= $g ?>" <?= _ep($_ep, 'Gender') === $g ? 'selected' : '' ?>><?= $g ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Birth Date: read-only -->
        <?php if ($_birth_disp !== '—'): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-cake2-fill"></i> Birth Date</div>
          <div class="pm-field-value pm-readonly"><?= $_birth_disp ?></div>
        </div>
        <?php endif; ?>

        <!-- Editable: Birth Place (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-geo-fill"></i> Birth Place</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Birth_Place')) ?: '—' ?></div>
          <input type="text" name="Birth_Place" class="pm-input" value="<?= h(_ep($_ep, 'Birth_Place')) ?>" style="display:none;">
        </div>

        <!-- Editable: Civil Status (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-heart-fill"></i> Civil Status</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Civil_Status')) ?: '—' ?></div>
          <select name="Civil_Status" class="pm-input" style="display:none;">
            <option value="">— Select —</option>
            <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Divorced'] as $cs): ?>
            <option value="<?= $cs ?>" <?= _ep($_ep, 'Civil_Status') === $cs ? 'selected' : '' ?>><?= $cs ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Editable: Nationality (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-flag-fill"></i> Nationality</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Nationality')) ?: '—' ?></div>
          <input type="text" name="Nationality" class="pm-input" value="<?= h(_ep($_ep, 'Nationality')) ?>" style="display:none;">
        </div>

        <!-- Editable: Religion (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-book-fill"></i> Religion</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Religion')) ?: '—' ?></div>
          <input type="text" name="Religion" class="pm-input" value="<?= h(_ep($_ep, 'Religion')) ?>" style="display:none;">
        </div>

        <!-- Editable: Permanent Address (always shown) -->
        <div class="pm-field pm-field-full">
          <div class="pm-field-label"><i class="bi bi-house-fill"></i> Permanent Address</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Permanent_Address')) ?: '—' ?></div>
          <textarea name="Permanent_Address" class="pm-input pm-textarea" style="display:none;"><?= h(_ep($_ep, 'Permanent_Address')) ?></textarea>
        </div>

        <!-- Editable: Present Address (always shown) -->
        <div class="pm-field pm-field-full">
          <div class="pm-field-label"><i class="bi bi-house-door-fill"></i> Present Address</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Present_Address')) ?: '—' ?></div>
          <textarea name="Present_Address" class="pm-input pm-textarea" style="display:none;"><?= h(_ep($_ep, 'Present_Address')) ?></textarea>
        </div>

        <!-- Editable: Educational Background (always shown) -->
        <div class="pm-field pm-field-full">
          <div class="pm-field-label"><i class="bi bi-mortarboard-fill"></i> Educational Background</div>
          <div class="pm-field-value pm-readonly"><?= nl2br(h(_ep($_ep, 'Educational_Background'))) ?: '—' ?></div>
          <textarea name="Educational_Background" class="pm-input pm-textarea" style="display:none;"><?= h(_ep($_ep, 'Educational_Background')) ?></textarea>
        </div>

      </div>
    </div>

    <!-- ── CONTACT ────────────────────────────────────────────── -->
    <div class="pm-tab-panel" id="pmTab-contact">
      <div class="pm-grid">

        <!-- Editable: Email -->
        <div class="pm-field pm-field-full">
          <div class="pm-field-label"><i class="bi bi-envelope-fill"></i> Email Address</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Email_Address')) ?: '—' ?></div>
          <input type="email" name="Email_Address" class="pm-input" value="<?= h(_ep($_ep, 'Email_Address')) ?>" style="display:none;">
        </div>

        <!-- Editable: Mobile -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-phone-fill"></i> Mobile Number</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Mobile_Number')) ?: '—' ?></div>
          <input type="text" name="Mobile_Number" class="pm-input" value="<?= h(_ep($_ep, 'Mobile_Number')) ?>" style="display:none;">
        </div>

        <!-- Editable: Phone -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-telephone-fill"></i> Phone Number</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Phone_Number')) ?: '—' ?></div>
          <input type="text" name="Phone_Number" class="pm-input" value="<?= h(_ep($_ep, 'Phone_Number')) ?>" style="display:none;">
        </div>

      </div>
    </div>

    <!-- ── GOVERNMENT IDs ─────────────────────────────────────── -->
    <div class="pm-tab-panel" id="pmTab-gov">
      <div class="pm-grid">

        <?php $v = _ep($_ep, 'SSS_Number'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-credit-card-fill"></i> SSS Number</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= $v ?: '—' ?></div>
        </div>

        <?php $v = _ep($_ep, 'TIN_Number'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-credit-card-2-front-fill"></i> TIN Number</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= $v ?: '—' ?></div>
        </div>

        <?php $v = _ep($_ep, 'Philhealth_Number'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-heart-pulse-fill"></i> PhilHealth Number</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= $v ?: '—' ?></div>
        </div>

        <?php $v = _ep($_ep, 'HDMF'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-bank2"></i> HDMF / Pag-IBIG</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= $v ?: '—' ?></div>
        </div>

        <p class="pm-gov-note pm-field-full">
          <i class="bi bi-info-circle-fill"></i>
          Government ID numbers are managed by HR and cannot be self-edited. Contact HR for corrections.
        </p>

      </div>
    </div>

    <!-- ── EMERGENCY ──────────────────────────────────────────── -->
    <div class="pm-tab-panel" id="pmTab-emergency">
      <div class="pm-grid">

        <!-- Editable: Contact Person (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-fill"></i> Contact Person</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Contact_Person')) ?: '—' ?></div>
          <input type="text" name="Contact_Person" class="pm-input" value="<?= h(_ep($_ep, 'Contact_Person')) ?>" style="display:none;">
        </div>

        <!-- Editable: Relationship (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-people-fill"></i> Relationship</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'Relationship')) ?: '—' ?></div>
          <input type="text" name="Relationship" class="pm-input" value="<?= h(_ep($_ep, 'Relationship')) ?>" style="display:none;">
        </div>

        <!-- Editable: Emergency Number (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-telephone-fill"></i> Emergency Number</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= h(_ep($_ep, 'Contact_Number_Emergency')) ?: '—' ?></div>
          <input type="text" name="Contact_Number_Emergency" class="pm-input" value="<?= h(_ep($_ep, 'Contact_Number_Emergency')) ?>" style="display:none;">
        </div>

        <!-- Editable: Notes (always shown) -->
        <div class="pm-field pm-field-full">
          <div class="pm-field-label"><i class="bi bi-journal-text"></i> Notes</div>
          <div class="pm-field-value pm-readonly"><?= nl2br(h(_ep($_ep, 'Notes'))) ?: '—' ?></div>
          <textarea name="Notes" class="pm-input pm-textarea" style="display:none;"><?= h(_ep($_ep, 'Notes')) ?></textarea>
        </div>

      </div>
    </div>

    <!-- ── SYSTEM ─────────────────────────────────────────────── -->
    <div class="pm-tab-panel" id="pmTab-system">
      <div class="pm-grid">

        <?php $v = _ep($_ep, 'EmployeeID1'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-person-badge"></i> Employee ID (Alt)</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'OfficeID'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-building-gear"></i> Office ID</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'ApplicationID'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-file-earmark-person-fill"></i> Application ID</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <!-- Editable: System (always shown) -->
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-pc-display"></i> System</div>
          <div class="pm-field-value pm-readonly"><?= h(_ep($_ep, 'System')) ?: '—' ?></div>
          <input type="text" name="System" class="pm-input" value="<?= h(_ep($_ep, 'System')) ?>" style="display:none;">
        </div>

        <?php $v = _ep($_ep, 'SortNo'); if ($v): ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-sort-numeric-up"></i> Sort No.</div>
          <div class="pm-field-value pm-readonly pm-mono"><?= h($v) ?></div>
        </div>
        <?php endif; ?>

        <?php $v = _ep($_ep, 'Active'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-toggle-on"></i> Active</div>
          <div class="pm-field-value pm-readonly">
            <span class="pm-chip-<?= $v === '1' ? 'green' : 'gray' ?>"><?= $v === '1' ? 'Yes' : 'No' ?></span>
          </div>
        </div>

        <?php $bl = _ep($_ep, 'Blacklisted'); ?>
        <div class="pm-field">
          <div class="pm-field-label"><i class="bi bi-slash-circle"></i> Blacklisted</div>
          <div class="pm-field-value pm-readonly">
            <span class="pm-chip-<?= $bl === '1' ? 'red' : 'gray' ?>"><?= $bl === '1' ? 'Yes' : 'No' ?></span>
          </div>
        </div>

      </div>
    </div>

  </form><!-- /pm-body form -->

</div>
</div>

<!-- ══ PHOTO PREVIEW OVERLAY ═════════════════════════════════ -->
<?php if ($_photo): ?>
<div id="tbPhotoPreview" onclick="tbClosePhotoPreview()" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.82);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);align-items:center;justify-content:center;flex-direction:column;gap:1.1rem;">
  <div style="display:flex;flex-direction:column;align-items:center;gap:1rem;animation:pvFadeIn .22s ease;" onclick="event.stopPropagation()">
    <div style="position:relative;">
      <img src="<?= htmlspecialchars($_photo) ?>" alt="<?= htmlspecialchars($_display_name) ?>"
        style="width:240px;height:240px;border-radius:16px;object-fit:cover;border:3px solid rgba(255,255,255,.15);box-shadow:0 24px 64px rgba(0,0,0,.55);display:block;">
      <div style="position:absolute;inset:0;border-radius:16px;border:1px solid rgba(255,255,255,.08);pointer-events:none;"></div>
    </div>
    <div style="text-align:center;">
      <div style="color:#fff;font-weight:700;font-size:1rem;letter-spacing:.01em;"><?= htmlspecialchars($_display_name) ?></div>
      <div style="color:rgba(255,255,255,.5);font-size:.78rem;margin-top:.2rem;"><?= htmlspecialchars($_position) ?><?= $_position && $_dept ? ' · ' : '' ?><?= htmlspecialchars($_dept) ?></div>
    </div>
    <button onclick="tbClosePhotoPreview()" style="display:flex;align-items:center;gap:.4rem;padding:.42rem .95rem;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);font-size:.78rem;font-weight:500;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
      <i class="bi bi-x-lg"></i> Close
    </button>
  </div>
  <style>@keyframes pvFadeIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}</style>
</div>
<?php endif; ?>

<!-- ══ SCRIPTS ════════════════════════════════════════════════ -->
<script>
// ── Dropdown ──────────────────────────────────────────────────
(function(){
  var btn  = document.getElementById('tbAvatarBtn'),
      drop = document.getElementById('tbDropdown');
  if (!btn || !drop) return;
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    var o = drop.classList.toggle('open');
    btn.setAttribute('aria-expanded', o);
  });
  document.addEventListener('click', function(){
    drop.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  });
  drop.addEventListener('click', function(e){ e.stopPropagation(); });
})();

// ── Photo preview ─────────────────────────────────────────────
function tbOpenPhotoPreview(){
  var el = document.getElementById('tbPhotoPreview');
  if (!el) return;
  el.style.display = 'flex';
}
function tbClosePhotoPreview(){
  var el = document.getElementById('tbPhotoPreview');
  if (el) el.style.display = 'none';
}
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') tbClosePhotoPreview();
});

// ── Profile modal ─────────────────────────────────────────────
var _editing = false;

function tbOpenProfile(){
  document.getElementById('tbDropdown').classList.remove('open');
  document.getElementById('tbAvatarBtn').setAttribute('aria-expanded', 'false');
  document.getElementById('pmOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function tbCloseProfile(){
  if (_editing) tbCancelEdit();
  document.getElementById('pmOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

// ── Tabs ──────────────────────────────────────────────────────
function tbSwitchTab(btn, id){
  document.querySelectorAll('.pm-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.pm-tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('pmTab-' + id).classList.add('active');
}

// ── Edit mode ─────────────────────────────────────────────────
// FIX 4: Removed the dead code block at the end of tbToggleEdit that
//         tried to reset the button label in the !_editing branch — it
//         was unreachable because the label was already set in the
//         ternary two lines above it.
function tbToggleEdit(){
  _editing = !_editing;
  var bar  = document.getElementById('pmSaveBar');
  var eBtn = document.getElementById('pmEditBtn');

  bar.classList.toggle('visible', _editing);
  eBtn.classList.toggle('active', _editing);
  eBtn.innerHTML = _editing
    ? '<i class="bi bi-x"></i> Cancel'
    : '<i class="bi bi-pencil-fill"></i> Edit';

  document.querySelectorAll('.pm-input').forEach(function(el){
    el.style.display = _editing ? '' : 'none';
  });
  document.querySelectorAll('.pm-readonly').forEach(function(el){
    el.style.display = _editing ? 'none' : '';
  });
}

function tbCancelEdit(){
  _editing = false;
  document.getElementById('pmSaveBar').classList.remove('visible');
  var eBtn = document.getElementById('pmEditBtn');
  eBtn.classList.remove('active');
  eBtn.innerHTML = '<i class="bi bi-pencil-fill"></i> Edit';
  document.querySelectorAll('.pm-input').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.pm-readonly').forEach(el => el.style.display = '');
}

function tbSaveProfile(){
  var form    = document.getElementById('pmForm');
  var data    = new FormData(form);
  var saveBtn = document.querySelector('.pm-btn-save');
  saveBtn.disabled  = true;
  saveBtn.innerHTML = '<i class="bi bi-arrow-repeat pm-spin"></i> Saving...';

  // Snapshot input values BEFORE cancelling edit (which hides inputs)
  var snapshots = [];
  document.querySelectorAll('.pm-input').forEach(function(input){
    var field = input.closest('.pm-field');
    if (!field) return;
    var ro = field.querySelector('.pm-readonly');
    if (!ro) return;
    var displayVal;
    if (input.tagName === 'SELECT') {
      displayVal = { type: 'text', val: input.options[input.selectedIndex]?.text || '—' };
    } else if (input.tagName === 'TEXTAREA') {
      displayVal = { type: 'html', val: input.value
        ? input.value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>')
        : '—' };
    } else {
      displayVal = { type: 'text', val: input.value || '—' };
    }
    snapshots.push({ ro: ro, displayVal: displayVal });
  });

  fetch(APP_URL + 'includes/profile-save.php', { method: 'POST', body: data })
    .then(function(r){
      var contentType = r.headers.get('content-type') || '';
      if (!contentType.includes('application/json')) {
        // Server returned HTML/text instead of JSON — grab the text for debugging
        return r.text().then(function(txt){
          console.error('[tbSaveProfile] Non-JSON response:', txt.substring(0, 500));
          throw new Error('Server returned non-JSON response. Check console for details.');
        });
      }
      return r.json();
    })
    .then(function(res){
      if (res.ok){
        tbCancelEdit();
        // Now apply the pre-snapshotted values to the read-only displays
        snapshots.forEach(function(s){
          if (s.displayVal.type === 'html') {
            s.ro.innerHTML = s.displayVal.val;
          } else {
            s.ro.textContent = s.displayVal.val;
          }
        });
        tbShowToast('Profile updated successfully.', 'success');
      } else {
        tbShowToast('Error: ' + (res.msg || 'Unknown error'), 'error');
      }
    })
    .catch(function(err){
      console.error('[tbSaveProfile] Catch:', err);
      tbShowToast((err && err.message) ? err.message : 'Network error. Please try again.', 'error');
    })
    .finally(function(){
      saveBtn.disabled  = false;
      saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save Changes';
    });
}

// ── Toast ─────────────────────────────────────────────────────
function tbShowToast(msg, type){
  var t = document.createElement('div');
  t.className = 'pm-toast pm-toast-' + type;
  t.innerHTML = '<i class="bi bi-' + (type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill') + '"></i> ' + msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.classList.add('show'); }, 10);
  setTimeout(function(){
    t.classList.remove('show');
    setTimeout(function(){ t.remove(); }, 300);
  }, 3500);
}

// ── Escape key ────────────────────────────────────────────────
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') tbCloseProfile();
});

// ── Session check ─────────────────────────────────────────────
setInterval(function(){
  fetch('/TWM/check_session.php').then(r => r.json()).then(function(d){
    if (!d.loggedIn) window.location.href = '/TWM/login.php';
  });
}, 30000);
</script>