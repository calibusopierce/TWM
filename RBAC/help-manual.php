<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('help')) {
    header('Location: ' . route('home')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Access Control · Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<style>
body{font-size:15px}
.help-layout{display:flex;max-width:1300px;margin:0 auto;padding:2rem 2rem 3rem;gap:2rem;align-items:flex-start}
.help-sidebar{width:230px;flex-shrink:0;position:sticky;top:80px;max-height:calc(100vh - 100px);overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
.help-sidebar::-webkit-scrollbar{width:4px}
.help-sidebar::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.help-main{flex:1;min-width:0}
.hn-title{font-size:.65rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted);padding:0 .5rem .5rem;border-bottom:1px solid var(--border);margin-bottom:.5rem}
.hn-group{margin-bottom:.25rem}
.hn-group-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;padding:.38rem .55rem;background:none;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.63rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted);border-radius:7px;transition:background .12s,color .12s;text-align:left}
.hn-group-toggle:hover{background:var(--surface-3);color:var(--text-secondary)}
.hn-group-toggle.open{color:var(--primary)}
.toggle-caret{font-size:.6rem;transition:transform .2s;flex-shrink:0}
.hn-group-toggle.open .toggle-caret{transform:rotate(180deg)}
.hn-group-body{overflow:hidden;max-height:0;transition:max-height .22s ease;padding-left:.25rem}
.hn-group-body.open{max-height:700px}
.hn-link{display:flex;align-items:center;gap:.45rem;padding:.38rem .55rem;border-radius:8px;color:var(--text-secondary);font-size:.8rem;font-weight:500;text-decoration:none;transition:background .12s,color .12s}
.hn-link:hover{background:var(--surface-3);color:var(--text-primary)}
.hn-link.active{background:var(--primary-glow);color:var(--primary);font-weight:700}
.hn-link i{font-size:.8rem;width:15px;text-align:center;flex-shrink:0}
.help-hero{background:linear-gradient(135deg,var(--primary-glow) 0%,rgba(14,165,233,.06) 100%);border:1.5px solid rgba(59,130,246,.2);border-radius:var(--radius-lg);padding:1.75rem 2rem;margin-bottom:2rem}
.help-hero-title{font-family:'Sora',sans-serif;font-size:1.65rem;font-weight:800;color:var(--text-primary);letter-spacing:-.03em;line-height:1.2;margin-bottom:.4rem}
.help-hero-title span{color:var(--primary-light)}
.help-hero-sub{color:var(--text-primary);font-size:.95rem;max-width:520px;line-height:1.65}
.help-hero-chips{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.85rem}
.help-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;background:var(--surface);border:1px solid var(--border);border-radius:20px;font-size:.72rem;font-weight:600;color:var(--text-secondary)}
.help-section{margin-bottom:2.75rem;scroll-margin-top:80px}
.help-section-header{display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;padding-bottom:.65rem;border-bottom:2px solid var(--border)}
.help-section-icon{font-size:1.3rem;line-height:1}
.help-section-title{font-family:'Sora',sans-serif;font-size:1.15rem;font-weight:800;color:var(--text-primary);letter-spacing:-.02em}
.help-intro{background:var(--surface-3);border:1px solid var(--border);border-left:3px solid var(--primary-light);border-radius:var(--radius);padding:.85rem 1.1rem;margin-bottom:1rem;font-size:.9rem;color:var(--text-primary);line-height:1.75}
.help-intro strong{color:var(--text-primary)}
.col-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:1rem;background:var(--surface);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow-sm)}
.col-table thead tr{background:var(--surface-3);border-bottom:2px solid var(--border)}
.col-table thead th{padding:.55rem .9rem;text-align:left;font-size:.67rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted)}
.col-table tbody tr{border-top:1px solid var(--border);transition:background .1s}
.col-table tbody tr:hover{background:var(--surface-2)}
.col-table td{padding:.6rem .9rem;vertical-align:top}
.col-table td:first-child{font-weight:700;color:var(--primary);white-space:nowrap;font-family:'DM Mono',monospace;font-size:.8rem;width:200px}
.col-table td:last-child{color:var(--text-primary);font-size:.84rem}
.tip-box{display:flex;gap:.65rem;align-items:flex-start;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:var(--radius);padding:.85rem 1.1rem;margin-bottom:.85rem;font-size:.86rem;color:var(--text-primary);line-height:1.7}
.tip-box i{color:var(--primary-light);font-size:.95rem;margin-top:.1rem;flex-shrink:0}
.tip-box strong{color:var(--text-primary)}
.tip-box.warn{background:rgba(217,119,6,.06);border-color:rgba(217,119,6,.2)}
.tip-box.warn i{color:#d97706}
.tip-box.success{background:rgba(16,185,129,.06);border-color:rgba(16,185,129,.2)}
.tip-box.success i{color:var(--green)}
.step-list{display:flex;flex-direction:column;gap:.6rem;margin-bottom:1rem}
.step-item{display:flex;gap:.8rem;align-items:flex-start}
.step-num{width:24px;height:24px;flex-shrink:0;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:800;color:#fff;margin-top:.18rem}
.step-text{font-size:.9rem;color:var(--text-primary);padding-top:.18rem;line-height:1.7}
.step-text strong{color:var(--text-primary)}
.role-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:1rem}
.role-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:.85rem 1rem;box-shadow:var(--shadow-sm)}
.role-card-name{font-weight:700;font-size:.9rem;color:var(--text-primary);margin-bottom:.35rem;display:flex;align-items:center;gap:.4rem}
.role-card-name i{color:var(--primary-light)}
.role-card-desc{font-size:.82rem;color:var(--text-secondary);line-height:1.6}
.help-divider{border:none;border-top:1px solid var(--border);margin:2rem 0}
.footer{text-align:center;padding:1.5rem;font-size:.75rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:1rem}
@media(max-width:900px){.help-layout{flex-direction:column;padding:1rem}.help-sidebar{width:100%;position:static}.role-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="help-layout">

  <nav class="help-sidebar">
    <div class="hn-title">📖 RBAC Help</div>
    <div class="hn-group" data-group="overview">
      <button class="hn-group-toggle" onclick="toggleGroup('overview')"><span>📋 Overview</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-overview">
        <a href="#intro" class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#tabs"  class="hn-link"><i class="bi bi-grid-3x3-gap"></i> Page Tabs</a>
      </div>
    </div>
    <div class="hn-group" data-group="permissions">
      <button class="hn-group-toggle" onclick="toggleGroup('permissions')"><span>🔐 Role Templates</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-permissions">
        <a href="#perms-overview" class="hn-link"><i class="bi bi-shield-check"></i> How Role Templates Work</a>
        <a href="#perms-edit"     class="hn-link"><i class="bi bi-toggle-on"></i> Editing Role Access</a>
        <a href="#perms-modules"  class="hn-link"><i class="bi bi-grid"></i> Module List</a>
      </div>
    </div>
    <div class="hn-group" data-group="users">
      <button class="hn-group-toggle" onclick="toggleGroup('users')"><span>👥 Users</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-users">
        <a href="#users-list"   class="hn-link"><i class="bi bi-people"></i> Users Tab</a>
        <a href="#users-access" class="hn-link"><i class="bi bi-key-fill"></i> Assigning Module Access</a>
        <a href="#users-type"   class="hn-link"><i class="bi bi-pencil"></i> Changing Legacy Type</a>
        <a href="#users-dept"   class="hn-link"><i class="bi bi-building"></i> Department Access</a>
      </div>
    </div>
    <div class="hn-group" data-group="audit">
      <button class="hn-group-toggle" onclick="toggleGroup('audit')"><span>🕓 Audit Log</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-audit">
        <a href="#audit-overview" class="hn-link"><i class="bi bi-clock-history"></i> What Is Logged</a>
        <a href="#audit-filter"   class="hn-link"><i class="bi bi-funnel"></i> Filtering Logs</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Access Control <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to managing who can access what in the Tradewell portal — per-user module assignment, role templates, audit logging, and more.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-key-fill"></i> Per-User Module Access</span>
        <span class="help-chip"><i class="bi bi-people"></i> User Management</span>
        <span class="help-chip"><i class="bi bi-clock-history"></i> Audit Log</span>
        <span class="help-chip"><i class="bi bi-shield-lock"></i> RBAC-Gated</span>
      </div>
    </div>

    <div class="help-section" id="intro">
      <div class="help-section-header"><span class="help-section-icon">🚀</span><div class="help-section-title">What Is This?</div></div>
      <div class="help-intro">The <strong>Access Control (RBAC) page</strong> lets authorized staff manage <strong>who can access what</strong> in the Tradewell web portal. Access is controlled <strong>per user, per module</strong> — completely separate from the legacy VB system's <em>User Type</em>.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Important:</strong> A user's <em>User Type</em> (e.g. Admin, HR, Salesman) is a <strong>legacy label from the VB app</strong> — it is kept as a reference only and does <strong>not</strong> control web portal access. What controls access is the <strong>RBAC Module Access</strong> assigned to each user here.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Changes take effect immediately. A user who loses module access will be blocked on their next page load. A user with no modules assigned falls back to their legacy User Type permissions temporarily.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tabs">
      <div class="help-section-header"><span class="help-section-icon">📑</span><div class="help-section-title">Page Tabs</div></div>
      <div class="help-intro">The RBAC page is split into four tabs — each with a distinct purpose.</div>
      <table class="col-table">
        <thead><tr><th>Tab</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>User Types</td><td>Manage role templates — define which modules a role can access. Used as a reference or bulk-assign shortcut. Does not directly control individual user access.</td></tr>
          <tr><td>Users</td><td>The real access control — assign specific modules directly to each user via the <strong>RBAC Roles</strong> button. Also shows legacy User Type as a label and manages department access.</td></tr>
          <tr><td>Module Registry</td><td>View, add, edit, and reorder all registered portal modules. Controls what appears on the homepage and in the permission matrix.</td></tr>
          <tr><td>Audit Log</td><td>Full history of all access changes — who granted or revoked what, when, and from which IP address.</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="perms-overview">
      <div class="help-section-header"><span class="help-section-icon">🔐</span><div class="help-section-title">How Role Templates Work</div></div>
      <div class="help-intro">The <strong>User Types tab</strong> lets you define role templates — each role has a set of modules it can access. These are used as <strong>reference templates</strong>, not as the actual gate. Individual user access is managed in the Users tab.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Role templates do not directly control page access.</strong> A user's actual access is determined by what is assigned to them individually in <strong>rbac_user_access</strong> via the Users tab. Role templates are useful for bulk-assigning modules quickly.</span></div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Click any role card to open the permission drawer — toggle modules on or off for that role. Use <strong>Grant All</strong> or <strong>Revoke All</strong> for bulk actions.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="perms-edit">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">Editing Role Access</div></div>
      <div class="help-intro">Role module access is edited via the drawer that opens when you click a role card in the User Types tab.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to the <strong>User Types tab</strong> and click any role card.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">The drawer slides in showing all modules grouped by category.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Toggle any module on (green) or off (gray) — changes save instantly.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Use <strong>Grant All</strong> to enable all modules for this role, or <strong>Revoke All</strong> to clear them.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Role templates are a great starting point — set up an <em>HR</em> role with HR modules, then go to the Users tab and assign that role's modules to each HR user individually.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="perms-modules">
      <div class="help-section-header"><span class="help-section-icon">📦</span><div class="help-section-title">Module List</div></div>
      <div class="help-intro">Below is a reference of all modules in the system and what they control. The exact list visible on your RBAC page may vary depending on what has been registered in the system.</div>
      <table class="col-table">
        <thead><tr><th>Module</th><th>What it controls access to</th></tr></thead>
        <tbody>
          <tr><td>Fuel Dashboard</td><td>The Logistics fuel monitoring dashboard — all tabs and filters</td></tr>
          <tr><td>Fuel Graphs</td><td>The Logistics analytics graphs page — all chart types</td></tr>
          <tr><td>Finance Remittance</td><td>The Finance delivery remittance dashboard — all tabs</td></tr>
          <tr><td>Careers Admin</td><td>HR Careers Admin panel — creating and managing job postings</td></tr>
          <tr><td>Applications</td><td>HR Applications page — viewing and managing job applicants</td></tr>
          <tr><td>Uniform Inventory</td><td>HR Uniform Inventory — items, issuance, history, POs, receiving</td></tr>
          <tr><td>Employee List</td><td>HR Employee Directory — active, inactive, and blacklisted employees</td></tr>
          <tr><td>Purchase Orders</td><td>PO module — creating, viewing, approving, and printing POs</td></tr>
          <tr><td>PO Categories</td><td>PO Categories management — creating and editing PO category labels</td></tr>
          <tr><td>RBAC / Access Control</td><td>This page itself — managing permissions and users. Admin-only, always locked.</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="users-list">
      <div class="help-section-header"><span class="help-section-icon">👥</span><div class="help-section-title">Users Tab</div></div>
      <div class="help-intro">The <strong>Users tab</strong> is the main access control interface — assign exactly which modules each user can access, independent of their legacy User Type.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Name</td><td>The user's display name</td></tr>
          <tr><td>Username</td><td>Their login username (monospace)</td></tr>
          <tr><td>Email</td><td>Their registered email address</td></tr>
          <tr><td>Department</td><td>Their primary department from the VB system</td></tr>
          <tr><td>Position</td><td>Their job title or position</td></tr>
          <tr><td>Legacy Type</td><td>Their <code>user_type</code> from the VB app — shown as reference only, does not control web access</td></tr>
          <tr><td>RBAC Roles</td><td>The modules directly assigned to this user — green pills show what they can currently access on the web portal</td></tr>
          <tr><td>Status</td><td><strong>Active</strong> (green dot) = can log in · <strong>Inactive</strong> (gray dot) = account disabled</td></tr>
          <tr><td>Actions</td><td><strong>RBAC Roles</strong> — assign/remove module access · <strong>Change Type</strong> — update legacy user_type · <strong>Dept Access</strong> — manage department visibility</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>Legacy Type filter</strong> to find all users of a specific type — useful when migrating users from the old role-based system to the new per-user access system.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="users-access">
      <div class="help-section-header"><span class="help-section-icon">🔑</span><div class="help-section-title">Assigning Module Access</div></div>
      <div class="help-intro">Click the green <strong>RBAC Roles</strong> button on any user row to open the module assignment modal. This is the primary way to control what a user can access on the web portal.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Find the user in the Users tab — use search or the Legacy Type filter.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click the green <strong>RBAC Roles</strong> button on their row.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">The modal shows all registered modules as checkboxes. Currently assigned modules are pre-checked.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Check or uncheck modules as needed — you can assign any combination.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Click <strong>Save Roles</strong>. Access is updated immediately — the green pills on their row update instantly.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Saving replaces all existing module assignments for that user. If you uncheck everything and save, the user falls back to their legacy User Type permissions temporarily until re-assigned.</span></div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The user does not need to log out — their access updates on their <strong>next page navigation</strong> automatically.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="users-type">
      <div class="help-section-header"><span class="help-section-icon">✏️</span><div class="help-section-title">Changing Legacy User Type</div></div>
      <div class="help-intro">Click <strong>Change Type</strong> on any user row to update their <code>user_type</code> in the legacy system. This changes how they appear in the VB app and affects their fallback access if they have no RBAC modules assigned.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>This changes the actual <code>user_type</code> in the database.</strong> The VB app reads this value directly — only change it if you intend to update their VB app role as well.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="users-dept">
      <div class="help-section-header"><span class="help-section-icon">🏢</span><div class="help-section-title">Department Access</div></div>
      <div class="help-intro">Click <strong>Dept Access</strong> on any user row to manage which departments this user can view data for. This is separate from module access — it controls data visibility within modules, not module access itself.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>A user can have access to the Fuel Dashboard module but only see data for specific departments — department access and module access are independent controls.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="audit-overview">
      <div class="help-section-header"><span class="help-section-icon">🕓</span><div class="help-section-title">What Is Logged</div></div>
      <div class="help-intro">The <strong>Audit Log tab</strong> records every access control change made in the system — who did it, what they changed, when, and from which IP address.</div>
      <table class="col-table">
        <thead><tr><th>Action Type</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>assign_access</td><td>A user's full module access was replaced — shows how many modules were assigned and which ones</td></tr>
          <tr><td>grant</td><td>A module was granted to a role template via the User Types drawer</td></tr>
          <tr><td>revoke</td><td>A module was revoked from a role template</td></tr>
          <tr><td>toggle</td><td>A module permission was toggled on or off in the role drawer</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The audit log is <strong>append-only</strong> — entries cannot be edited or deleted. It provides a permanent record of all access changes for accountability.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="audit-filter">
      <div class="help-section-header"><span class="help-section-icon">🔍</span><div class="help-section-title">Filtering Logs</div></div>
      <div class="help-intro">Use the toolbar at the top of the Audit Log tab to filter the log entries.</div>
      <table class="col-table">
        <thead><tr><th>Filter</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Search</td><td>Search by username, target user, module key, or role name</td></tr>
          <tr><td>Action filter</td><td>Show only a specific action type — assign_access, grant, revoke, or toggle</td></tr>
          <tr><td>Date From / To</td><td>Narrow down logs to a specific date range</td></tr>
          <tr><td>Clear</td><td>Remove all filters and show the full log</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>To audit a specific user — search their username in the search box and set action to <stro

  </main>
</div>
<div class="footer">Access Control Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'intro':'overview','tabs':'overview',
  'perms-overview':'permissions','perms-edit':'permissions','perms-modules':'permissions',
  'users-list':'users','users-access':'users','users-type':'users','users-dept':'users',
  'audit-overview':'audit','audit-filter':'audit',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'overview');});
</script>
</body>
</html>
