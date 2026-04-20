<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'auth_check.php';
auth_check(['Admin', 'Administrator', 'Delivery', 'Logistic', 'HR']);

$topbar_page  = 'help';
$_role        = $_SESSION['UserType'] ?? '';
$_can_fuel    = in_array($_role, ['Admin','Administrator','Delivery','Logistic']);
$_can_careers = in_array($_role, ['Admin','Administrator','HR']);
$_can_uniform = in_array($_role, ['Admin','Administrator','HR']);
$_can_emp     = in_array($_role, ['Admin','Administrator','HR']);
$_is_admin    = in_array($_role, ['Admin','Administrator']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">

<style>
/* Help manual readability overrides */
body { font-size: 15px; }
</style>
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
.help-layout {
  display: flex;
  max-width: 1300px;
  margin: 0 auto;
  padding: 2rem 2rem 3rem;
  gap: 2rem;
  align-items: flex-start;
}
.help-sidebar {
  width: 230px; flex-shrink: 0;
  position: sticky; top: 80px;
  max-height: calc(100vh - 100px);
  overflow-y: auto;
  scrollbar-width: thin;
  scrollbar-color: var(--border) transparent;
}
.help-sidebar::-webkit-scrollbar { width: 4px; }
.help-sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.help-main { flex: 1; min-width: 0; }

/* Sidebar */
.hn-title {
  font-size: .65rem; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: var(--text-muted);
  padding: 0 .5rem .5rem;
  border-bottom: 1px solid var(--border);
  margin-bottom: .5rem;
}

/* Accordion group */
.hn-group { margin-bottom: .25rem; }

.hn-group-toggle {
  display: flex; align-items: center; justify-content: space-between;
  width: 100%; padding: .38rem .55rem;
  background: none; border: none; cursor: pointer;
  font-family: 'DM Sans', sans-serif;
  font-size: .63rem; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; color: var(--text-muted);
  border-radius: 7px;
  transition: background .12s, color .12s;
  text-align: left;
}
.hn-group-toggle:hover { background: var(--surface-3); color: var(--text-secondary); }
.hn-group-toggle.open  { color: var(--primary); }

.hn-group-toggle .toggle-caret {
  font-size: .6rem; transition: transform .2s; flex-shrink: 0;
}
.hn-group-toggle.open .toggle-caret { transform: rotate(180deg); }

.hn-group-body {
  overflow: hidden;
  max-height: 0;
  transition: max-height .22s ease;
  padding-left: .25rem;
}
.hn-group-body.open { max-height: 600px; }

.hn-link {
  display: flex; align-items: center; gap: .45rem;
  padding: .38rem .55rem; border-radius: 8px;
  color: var(--text-secondary); font-size: .8rem; font-weight: 500;
  text-decoration: none; transition: background .12s, color .12s;
}
.hn-link:hover { background: var(--surface-3); color: var(--text-primary); }
.hn-link.active { background: var(--primary-glow); color: var(--primary); font-weight: 700; }
.hn-link i { font-size: .8rem; width: 15px; text-align: center; flex-shrink: 0; }

/* Hero */
.help-hero {
  background: linear-gradient(135deg, var(--primary-glow) 0%, rgba(14,165,233,.06) 100%);
  border: 1.5px solid rgba(59,130,246,.2);
  border-radius: var(--radius-lg);
  padding: 1.75rem 2rem;
  margin-bottom: 2rem;
}
.help-hero-title {
  font-family: 'Sora', sans-serif;
  font-size: 1.65rem; font-weight: 800;
  color: var(--text-primary);
  letter-spacing: -.03em; line-height: 1.2;
  margin-bottom: .4rem;
}
.help-hero-title span { color: var(--primary-light); }
.help-hero-sub { color: var(--text-primary); font-size: .95rem; max-width: 520px; line-height: 1.65; }
.help-hero-chips { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .85rem; }
.help-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .65rem;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 20px; font-size: .72rem; font-weight: 600; color: var(--text-secondary);
}

/* Section */
.help-section { margin-bottom: 2.75rem; scroll-margin-top: 80px; }
.help-section-header {
  display: flex; align-items: center; gap: .6rem;
  margin-bottom: 1rem; padding-bottom: .65rem;
  border-bottom: 2px solid var(--border);
}
.help-section-icon { font-size: 1.3rem; line-height: 1; }
.help-section-title {
  font-family: 'Sora', sans-serif;
  font-size: 1.15rem; font-weight: 800;
  color: var(--text-primary); letter-spacing: -.02em;
}

/* Intro box */
.help-intro {
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-left: 3px solid var(--primary-light);
  border-radius: var(--radius);
  padding: .85rem 1.1rem;
  margin-bottom: 1rem;
  font-size: .9rem; color: var(--text-primary); line-height: 1.75;
}
.help-intro strong { color: var(--text-primary); }

/* Column table */
.col-table {
  width: 100%; border-collapse: collapse; font-size: .82rem;
  margin-bottom: 1rem; background: var(--surface);
  border-radius: var(--radius); overflow: hidden;
  border: 1px solid var(--border); box-shadow: var(--shadow-sm);
}
.col-table thead tr { background: var(--surface-3); border-bottom: 2px solid var(--border); }
.col-table thead th {
  padding: .55rem .9rem; text-align: left;
  font-size: .67rem; font-weight: 700; letter-spacing: .07em;
  text-transform: uppercase; color: var(--text-muted);
}
.col-table tbody tr { border-top: 1px solid var(--border); transition: background .1s; }
.col-table tbody tr:hover { background: var(--surface-2); }
.col-table td { padding: .6rem .9rem; vertical-align: top; }
.col-table td:first-child {
  font-weight: 700; color: var(--primary);
  white-space: nowrap; font-family: 'DM Mono', monospace;
  font-size: .8rem; width: 170px;
}
.col-table td:last-child { color: var(--text-primary); font-size: .84rem; }

/* Tip boxes */
.tip-box {
  display: flex; gap: .65rem; align-items: flex-start;
  background: rgba(59,130,246,.06);
  border: 1px solid rgba(59,130,246,.2);
  border-radius: var(--radius);
  padding: .85rem 1.1rem; margin-bottom: .85rem;
  font-size: .86rem; color: var(--text-primary); line-height: 1.7;
}
.tip-box i { color: var(--primary-light); font-size: .95rem; margin-top: .1rem; flex-shrink: 0; }
.tip-box strong { color: var(--text-primary); }
.tip-box.warn { background: rgba(217,119,6,.06); border-color: rgba(217,119,6,.2); }
.tip-box.warn i { color: #d97706; }
.tip-box.success { background: rgba(16,185,129,.06); border-color: rgba(16,185,129,.2); }
.tip-box.success i { color: var(--green); }

/* Step list */
.step-list { display: flex; flex-direction: column; gap: .6rem; margin-bottom: 1rem; }
.step-item { display: flex; gap: .8rem; align-items: flex-start; }
.step-num {
  width: 24px; height: 24px; flex-shrink: 0;
  background: var(--primary); border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; font-weight: 800; color: #fff; margin-top: .18rem;
}
.step-text { font-size: .9rem; color: var(--text-primary); padding-top: .18rem; line-height: 1.7; }
.step-text strong { color: var(--text-primary); }

/* Filter grid */
.filter-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .65rem; margin-bottom: 1rem; }
.filter-item {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: .75rem .9rem; box-shadow: var(--shadow-sm);
}
.filter-item-name {
  font-weight: 700; font-size: .85rem; color: var(--text-primary);
  margin-bottom: .2rem; display: flex; align-items: center; gap: .35rem;
}
.filter-item-name i { color: var(--primary-light); }
.filter-item-desc { font-size: .82rem; color: var(--text-secondary); line-height: 1.55; }

/* Graph card */
.graph-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-lg); padding: 1rem 1.25rem;
  margin-bottom: .85rem; box-shadow: var(--shadow-sm);
  transition: border-color .15s, box-shadow .15s;
}
.graph-card:hover { border-color: var(--primary-light); box-shadow: var(--shadow-md); }
.graph-card-title {
  font-family: 'Sora', sans-serif; font-size: .95rem; font-weight: 700;
  color: var(--text-primary); margin-bottom: .35rem;
  display: flex; align-items: center; gap: .4rem;
}
.graph-card-desc { font-size: .88rem; color: var(--text-primary); line-height: 1.7; margin-bottom: .6rem; }
.toggle-pills { display: flex; gap: .3rem; flex-wrap: wrap; }
.toggle-pill {
  font-size: .74rem; font-weight: 700; padding: .2rem .6rem;
  border-radius: 20px; border: 1px solid var(--border-strong);
  background: var(--surface-3); color: var(--text-secondary);
}

.help-divider { border: none; border-top: 1px solid var(--border); margin: 2rem 0; }

@media (max-width: 900px) {
  .help-layout { flex-direction: column; padding: 1rem; }
  .help-sidebar { width: 100%; position: static; }
  .filter-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php $topbar_page = 'help'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="help-layout">

  <!-- ── SIDEBAR ─────────────────────────────────────────── -->
  <nav class="help-sidebar" id="helpSidebar">
    <div class="hn-title">📖 Contents</div>

    <!-- Getting Started -->
    <div class="hn-group" data-group="started">
      <button class="hn-group-toggle" onclick="toggleGroup('started')">
        <span>Getting Started</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-started">
        <a href="#getting-started" class="hn-link"><i class="bi bi-play-circle"></i> Quick Start</a>
        <?php if ($_can_fuel): ?>
        <a href="#filters"    class="hn-link"><i class="bi bi-funnel"></i> Using Filters</a>
        <a href="#department" class="hn-link"><i class="bi bi-building"></i> Department</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($_can_fuel): ?>
    <!-- Fuel Dashboard -->
    <div class="hn-group" data-group="fuel">
      <button class="hn-group-toggle" onclick="toggleGroup('fuel')">
        <span>⛽ Fuel Dashboard</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-fuel">
        <a href="#summary"     class="hn-link"><i class="bi bi-table"></i> Overall Summary</a>
        <a href="#ranking"     class="hn-link"><i class="bi bi-sort-numeric-down"></i> Low / High Ranking</a>
        <a href="#30day"       class="hn-link"><i class="bi bi-calendar3"></i> 30-Day Monitor</a>
        <a href="#area"        class="hn-link"><i class="bi bi-geo-alt"></i> Area Summary</a>
        <a href="#comparison"  class="hn-link"><i class="bi bi-bar-chart"></i> Fuel Comparison</a>
        <a href="#anomaly"     class="hn-link"><i class="bi bi-exclamation-triangle"></i> Anomaly Flags</a>
        <a href="#checklist"   class="hn-link"><i class="bi bi-check2-square"></i> Monthly Checklist</a>
        <a href="#consumption" class="hn-link"><i class="bi bi-calendar-week"></i> Fuel Consumption</a>
        <a href="#report"      class="hn-link"><i class="bi bi-receipt"></i> Usage Report</a>
      </div>
    </div>

    <!-- Graphs -->
    <div class="hn-group" data-group="graphs">
      <button class="hn-group-toggle" onclick="toggleGroup('graphs')">
        <span>📊 Graphs Page</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-graphs">
        <a href="#graphs-overview"   class="hn-link"><i class="bi bi-graph-up"></i> Overview</a>
        <a href="#graph-consumption" class="hn-link"><i class="bi bi-bar-chart-fill"></i> Consumption</a>
        <a href="#graph-trend"       class="hn-link"><i class="bi bi-graph-up-arrow"></i> Trend Over Time</a>
        <a href="#graph-area"        class="hn-link"><i class="bi bi-pie-chart"></i> By Area</a>
        <a href="#graph-vtype"       class="hn-link"><i class="bi bi-truck"></i> By Vehicle Type</a>
        <a href="#graph-top10"       class="hn-link"><i class="bi bi-trophy"></i> Top 10</a>
        <a href="#graph-status"      class="hn-link"><i class="bi bi-check-circle"></i> Refuel Status</a>
      </div>
    </div>

    <!-- Other -->
    <div class="hn-group" data-group="other">
      <button class="hn-group-toggle" onclick="toggleGroup('other')">
        <span>Other</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-other">
        <a href="#export" class="hn-link"><i class="bi bi-download"></i> Export &amp; Print</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($_can_careers): ?>
    <!-- Careers Admin -->
    <div class="hn-group" data-group="careers">
      <button class="hn-group-toggle" onclick="toggleGroup('careers')">
        <span>💼 Careers Admin</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-careers">
        <a href="#careers-admin"     class="hn-link"><i class="bi bi-briefcase"></i> Careers Admin Panel</a>
        <a href="#careers-add"       class="hn-link"><i class="bi bi-plus-circle"></i> Adding a Job Post</a>
        <a href="#careers-edit"      class="hn-link"><i class="bi bi-pencil"></i> Editing / Deleting</a>
        <a href="#applications"      class="hn-link"><i class="bi bi-people"></i> Applications Page</a>
        <a href="#app-status"        class="hn-link"><i class="bi bi-tag"></i> Application Statuses</a>
        <a href="#careers-public"    class="hn-link"><i class="bi bi-globe"></i> Public Careers Page</a>
        <a href="#careers-apply"     class="hn-link"><i class="bi bi-file-earmark-person"></i> Applying for a Job</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($_can_uniform): ?>
    <!-- Uniform Inventory -->
    <div class="hn-group" data-group="uniform">
      <button class="hn-group-toggle" onclick="toggleGroup('uniform')">
        <span>🧥 Uniform Inventory</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-uniform">
        <a href="#uniform-overview"  class="hn-link"><i class="bi bi-bag"></i> Overview</a>
        <a href="#uniform-items"     class="hn-link"><i class="bi bi-plus-circle"></i> Adding Items</a>
        <a href="#uniform-issue"     class="hn-link"><i class="bi bi-arrow-up-circle"></i> Issuing Uniforms</a>
        <a href="#uniform-return"    class="hn-link"><i class="bi bi-arrow-down-circle"></i> Returning Uniforms</a>
        <a href="#uniform-history"   class="hn-link"><i class="bi bi-clock-history"></i> Issuance History</a>
        <a href="#uniform-po"        class="hn-link"><i class="bi bi-file-earmark-text"></i> Purchase Orders</a>
        <a href="#uniform-receiving" class="hn-link"><i class="bi bi-box-arrow-in-down"></i> Receiving</a>
        <a href="#uniform-print"     class="hn-link"><i class="bi bi-printer"></i> Printing</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($_can_emp): ?>
    <!-- Employee Directory -->
    <div class="hn-group" data-group="employees">
      <button class="hn-group-toggle" onclick="toggleGroup('employees')">
        <span>👤 Employee Directory</span>
        <i class="bi bi-chevron-down toggle-caret"></i>
      </button>
      <div class="hn-group-body" id="grp-employees">
        <a href="#emp-list"        class="hn-link"><i class="bi bi-people"></i> Employee List</a>
        <a href="#emp-inactive"    class="hn-link"><i class="bi bi-person-dash"></i> Inactive Employees</a>
        <a href="#emp-blacklisted" class="hn-link"><i class="bi bi-person-x"></i> Blacklisted Employees</a>
      </div>
    </div>
    <?php endif; ?>

  </nav>

  <!-- ── MAIN ────────────────────────────────────────────── -->
  <main class="help-main">

    <div class="help-hero">
      <div class="help-hero-title">Help <span>Manual</span></div>
      <div class="help-hero-sub">A simple guide to using the
        <?php if ($_can_fuel && $_can_careers): ?>Fuel Monitoring Dashboard, Analytics Graphs, Careers pages, Uniform Inventory, and Employee Directory.
        <?php elseif ($_can_fuel): ?>Fuel Monitoring Dashboard and Analytics Graphs.
        <?php else: ?>Careers Admin, Applications, Uniform Inventory, and Employee Directory.
        <?php endif; ?>
        No technical knowledge needed!</div>
      <div class="help-hero-chips">
        <?php if ($_can_fuel): ?>
        <span class="help-chip"><i class="bi bi-fuel-pump"></i> Fuel Dashboard</span>
        <span class="help-chip"><i class="bi bi-bar-chart"></i> Analytics Graphs</span>
        <?php endif; ?>
        <?php if ($_can_careers): ?>
        <span class="help-chip"><i class="bi bi-briefcase"></i> Careers Admin</span>
        <span class="help-chip"><i class="bi bi-people"></i> Applications</span>
        <span class="help-chip"><i class="bi bi-globe"></i> Public Careers</span>
        <?php endif; ?>
        <?php if ($_can_uniform): ?>
        <span class="help-chip"><i class="bi bi-bag-fill"></i> Uniform Inventory</span>
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Purchase Orders</span>
        <span class="help-chip"><i class="bi bi-box-arrow-in-down"></i> Receiving</span>
        <?php endif; ?>
        <?php if ($_can_emp): ?>
        <span class="help-chip"><i class="bi bi-person-badge"></i> Employee Directory</span>
        <?php endif; ?>
        <span class="help-chip"><i class="bi bi-people"></i> For All Users</span>
      </div>
    </div>

    <!-- GETTING STARTED -->
    <div class="help-section" id="getting-started">
      <div class="help-section-header">
        <span class="help-section-icon">🚀</span>
        <div class="help-section-title">Quick Start</div>
      </div>
      <div class="help-intro">
        <?php if ($_can_fuel && $_can_careers): ?>
        This portal gives you access to the <strong>Fuel Monitoring Dashboard</strong>, <strong>Analytics Graphs</strong>, <strong>Careers Management</strong>, <strong>Uniform Inventory</strong>, and <strong>Employee Directory</strong> — all in one place. Use the sidebar to jump to any topic.
        <?php elseif ($_can_fuel): ?>
        The <strong>Fuel Monitoring Dashboard</strong> is your tool for tracking how much fuel every truck uses, how often they refuel, and whether anything looks suspicious. Think of it like a <strong>report card for every truck in the fleet</strong>.
        <?php else: ?>
        The <strong>Careers Admin Panel</strong> is your tool for managing job postings and reviewing applicants. Any changes you make appear on the public careers page immediately.
        <?php endif; ?>
      </div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Log in and go to <strong>Home</strong>. Click the page you want to open from the available cards.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click your <strong>profile avatar</strong> in the top right corner to navigate between pages, switch departments, or log out.</div></div>
        <?php if ($_can_fuel): ?>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">On the Fuel Dashboard, data defaults to <strong>this month</strong>. Change the date range using the filters at the top.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Use the <strong>tabs</strong> to switch between different views — each tab shows a different angle of the same data.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text"><strong>Download or print</strong> any table using the CSV, Excel, or Print buttons on the top right of each tab.</div></div>
        <?php endif; ?>
        <?php if ($_can_careers): ?>
        <div class="step-item"><div class="step-num"><?= $_can_fuel ? '6' : '3' ?></div><div class="step-text">On <strong>Careers Admin</strong>, you can add, edit, or delete job postings. Changes appear on the public careers page immediately.</div></div>
        <div class="step-item"><div class="step-num"><?= $_can_fuel ? '7' : '4' ?></div><div class="step-text">On <strong>Applications</strong>, you can view and manage all job applicants — filter by date, status, or search by name.</div></div>
        <?php endif; ?>
        <?php if ($_can_emp): ?>
        <div class="step-item"><div class="step-num"><?= $_can_fuel ? '8' : ($_can_careers ? '5' : '3') ?></div><div class="step-text">On <strong>Employee Directory</strong>, you can view active employees, manage inactive employees, and maintain the blacklist.</div></div>
        <?php endif; ?>
      </div>
    </div>
    <hr class="help-divider">

    <?php if ($_can_fuel): ?>
    <!-- FILTERS -->
    <div class="help-section" id="filters">
      <div class="help-section-header">
        <span class="help-section-icon">🔍</span>
        <div class="help-section-title">Using Filters</div>
      </div>
      <div class="help-intro">Filters let you <strong>narrow down the data</strong> so you only see what you need. All filters apply to every tab at the same time. Click <strong>Apply</strong> to run the filter, or <strong>Clear</strong> to reset everything.</div>
      <div class="filter-grid">
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-calendar3"></i> Date From / To</div><div class="filter-item-desc">Show data only within a specific date range. Leave blank to use the current month default.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-truck"></i> Vehicle Type</div><div class="filter-item-desc">Filter by truck category (e.g. 4-Wheeler, 6-Wheeler). Useful when comparing trucks of the same type.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-tag"></i> Plate #</div><div class="filter-item-desc">Search for a specific truck. You can type just part of the plate number — it will find all matches.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-person"></i> Driver</div><div class="filter-item-desc">Filter records by driver name. Partial text accepted — matches against both the fuel record driver and the team schedule. Shows as an active chip in the filter bar when set.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-geo-alt"></i> Area</div><div class="filter-item-desc">Show only trucks or refuels from a specific delivery area or route (e.g. Makati, Pasig). Filters all tabs simultaneously. Shows as an active chip in the filter bar when set.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-building"></i> Department (Auto)</div><div class="filter-item-desc">Your department is automatically applied from your login. Change it via the avatar menu in the topbar.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><strong>Tip:</strong> Combine multiple filters at once. Example: Vehicle Type = "6-Wheeler" AND Area = "Makati" to see only 6-wheelers in Makati. The <strong>Driver</strong> and <strong>Area</strong> filters accept partial text — you don't need to type the full name. Active filters appear as <strong>removable chips</strong> in the filter bar so you can see and clear them at a glance.</span></div>
    </div>
    <hr class="help-divider">

    <!-- DEPARTMENT -->
    <div class="help-section" id="department">
      <div class="help-section-header">
        <span class="help-section-icon">🏢</span>
        <div class="help-section-title">Department Filter</div>
      </div>
      <div class="help-intro">Your <strong>department</strong> is shown as a colored badge in the topbar. All data shown is automatically filtered to your department. Click your <strong>avatar</strong> or the department badge to switch departments if you have permission.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Note:</strong> Only Admin users can switch to a different department. Regular users can only see their own department's data.</span></div>
    </div>
    <hr class="help-divider">

    <!-- OVERALL SUMMARY -->
    <div class="help-section" id="summary">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Overall Summary Tab</div></div>
      <div class="help-intro">The <strong>main report card</strong> of the dashboard. Every truck in one table with a summary of all its fuel activity for the selected period.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>The truck's unique plate number</td></tr>
          <tr><td>Department</td><td>Which department this truck belongs to</td></tr>
          <tr><td>Vehicle Type</td><td>What category of truck it is (e.g. 4-Wheeler, Motorcycle)</td></tr>
          <tr><td>Fuel Type</td><td>Whether it runs on Diesel or Gasoline</td></tr>
          <tr><td>Total Refuels</td><td>How many times it was refueled during the period</td></tr>
          <tr><td>Total Liters</td><td>The total amount of fuel it consumed</td></tr>
          <tr><td>Avg Liters</td><td>On average, how many liters it gets per refuel visit</td></tr>
          <tr><td>Total Amount</td><td>The total money spent on fuel for this truck</td></tr>
          <tr><td>Avg Amount</td><td>On average, how much each refuel costs</td></tr>
          <tr><td>Last Refuel</td><td>The date of its most recent refuel</td></tr>
          <tr><td>Latest Area</td><td>The last area/route it was deployed to</td></tr>
          <tr><td>All Areas</td><td>Click "View Areas" to see every area this truck has been to</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Click any column header to sort the table. Click once for ascending, click again for descending.</span></div>
    </div>
    <hr class="help-divider">

    <!-- RANKING -->
    <div class="help-section" id="ranking">
      <div class="help-section-header"><span class="help-section-icon">📈</span><div class="help-section-title">Low → High &amp; High → Low Tabs</div></div>
      <div class="help-intro">Same as Overall Summary but <strong>pre-sorted by fuel consumption</strong>. <strong>Low → High</strong> shows the most fuel-efficient trucks first. <strong>High → Low</strong> shows the heaviest consumers first.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use <strong>High → Low</strong> at the end of each month to quickly identify trucks that may need inspection or driver coaching.</span></div>
    </div>
    <hr class="help-divider">

    <!-- 30 DAY -->
    <div class="help-section" id="30day">
      <div class="help-section-header"><span class="help-section-icon">📅</span><div class="help-section-title">30-Day Monitor Tab</div></div>
      <div class="help-intro">Think of this like an <strong>attendance record for refueling</strong>. For every truck scheduled to operate, this shows how many days it actually got refueled versus how many days it didn't. The <strong>Coverage bar</strong> shows the percentage — the higher, the better.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>The truck's plate number</td></tr>
          <tr><td>Vehicle Type</td><td>What category of truck it is</td></tr>
          <tr><td>Days Refueled</td><td>Number of days this truck actually got fuel</td></tr>
          <tr><td>Days Not Refueled</td><td>Scheduled days with no refuel recorded</td></tr>
          <tr><td>Coverage</td><td>Percentage of scheduled days that were refueled. 100% = refueled every scheduled day</td></tr>
          <tr><td>Total Refuels</td><td>Total number of refuel transactions in the period</td></tr>
          <tr><td>Total Liters</td><td>Total fuel consumed</td></tr>
          <tr><td>Total Amount</td><td>Total money spent on fuel</td></tr>
          <tr><td>Latest Area / All Areas</td><td>Where the truck has been deployed</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>A truck with very <strong>low coverage</strong> (e.g. 20%) might mean refuel records are missing or the truck wasn't actually operating those days.</span></div>
    </div>
    <hr class="help-divider">

    <!-- AREA SUMMARY -->
    <div class="help-section" id="area">
      <div class="help-section-header"><span class="help-section-icon">📍</span><div class="help-section-title">Area Summary Tab</div></div>
      <div class="help-intro">Instead of individual trucks, this tab <strong>groups everything by area/route</strong>. It answers: <em>"Which delivery area uses the most fuel overall?"</em></div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Area</td><td>The delivery area or route name</td></tr>
          <tr><td>Refuels</td><td>How many refuel transactions happened in this area</td></tr>
          <tr><td>Total Liters</td><td>Total fuel consumed by all trucks in this area</td></tr>
          <tr><td>Avg Liters</td><td>On average, how many liters per refuel in this area</td></tr>
          <tr><td>Total Amount</td><td>Total money spent on fuel across this area</td></tr>
          <tr><td>Avg Amount</td><td>Average cost per refuel in this area</td></tr>
          <tr><td>Unique Trucks</td><td>How many different trucks operated in this area</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <!-- FUEL COMPARISON -->
    <div class="help-section" id="comparison">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Fuel Comparison Tab</div></div>
      <div class="help-intro">Compares each truck <strong>against other trucks that operate similarly</strong> — same area, same refuel frequency. This makes it a fair comparison. A truck that only refuels twice a month naturally takes more liters per visit than one that refuels daily.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>The truck's plate number</td></tr>
          <tr><td>Department</td><td>Which department this truck belongs to</td></tr>
          <tr><td>Area</td><td>The delivery area for this comparison</td></tr>
          <tr><td>Refuel Frequency</td><td><strong>Daily</strong> = 15+ refuels/month · <strong>Weekly</strong> = 4–14/month · <strong>Occasional</strong> = 1–3/month</td></tr>
          <tr><td>Total Refuels</td><td>How many times this truck was refueled</td></tr>
          <tr><td>This Truck's Avg</td><td>How many liters this specific truck uses on average per refuel</td></tr>
          <tr><td>Similar Trucks' Avg</td><td>The average liters of other trucks with the same frequency and area — the benchmark</td></tr>
          <tr><td>Difference</td><td>"20% higher" means it uses 20% more fuel than similar trucks in the same area</td></tr>
          <tr><td>Total Liters</td><td>Total fuel consumed</td></tr>
          <tr><td>Total Amount</td><td>Total money spent</td></tr>
          <tr><td>Avg Amount</td><td>Average cost per refuel</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>A truck consistently showing <strong>"50% higher"</strong> uses 50% more fuel than similar trucks in the same area. This could indicate a mechanical issue, route inefficiency, or fuel misuse worth investigating.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ANOMALY FLAGS -->
    <div class="help-section" id="anomaly">
      <div class="help-section-header">
        <span class="help-section-icon">🚨</span>
        <div class="help-section-title">Anomaly Flags Tab</div>
        <span class="badge badge-critical" style="margin-left:.25rem;">Needs Attention</span>
      </div>
      <div class="help-intro">The <strong>alarm system</strong> of the dashboard. It automatically detects individual refuel transactions that look suspicious. Each flagged truck gets its own <strong>card</strong> showing all its suspicious records for easy review.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>A flag does <strong>not</strong> automatically mean fraud. It means <strong>"this looks unusual and should be reviewed."</strong> Always check the driver, invoice, and area before conclusions.</span></div>
      <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:.6rem;font-weight:600;">Flag severity levels:</p>
      <div style="display:flex;flex-direction:column;gap:.45rem;margin-bottom:1rem;">
        <div style="display:flex;align-items:center;gap:.75rem;"><span class="badge badge-critical">🔴 Critical</span><span style="font-size:.82rem;color:var(--text-secondary);">More than <strong>100% above</strong> the truck's own normal OR more than <strong>200% above</strong> similar trucks</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span class="badge badge-high">🟠 High</span><span style="font-size:.82rem;color:var(--text-secondary);"><strong>50–100% above</strong> the truck's own normal OR <strong>100–200% above</strong> area average</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span class="badge badge-watch">🟡 Watch</span><span style="font-size:.82rem;color:var(--text-secondary);">Slightly unusual — worth monitoring but not immediately alarming</span></div>
      </div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Date</td><td>When this specific suspicious refuel happened</td></tr>
          <tr><td>Area</td><td>Where the refuel took place</td></tr>
          <tr><td>Driver</td><td>Who was driving the truck that day</td></tr>
          <tr><td>INV #</td><td>The invoice or receipt number from the fuel station</td></tr>
          <tr><td>Liters</td><td>How much fuel was pumped during this specific refuel</td></tr>
          <tr><td>Amount</td><td>How much it cost</td></tr>
          <tr><td>Price/L</td><td>Price per liter — unusually high prices could also indicate an issue</td></tr>
          <tr><td>vs Truck's Avg</td><td>"+80%" means 80% more fuel than this truck normally gets per refuel</td></tr>
          <tr><td>vs Area Avg</td><td>How much more this refuel is vs similar trucks in the same area</td></tr>
          <tr><td>Triggered By</td><td><strong>TRUCK AVG</strong> = unusual for this truck · <strong>AREA AVG</strong> = unusual for area · <strong>BOTH</strong> = both baselines flagged it</td></tr>
          <tr><td>Flag</td><td>The severity — Critical, High, or Watch</td></tr>
        </tbody>
      </table>
      <div class="help-intro" style="margin-top:.75rem;">Each card header also shows the truck's <strong>baseline stats</strong>: total refuels, average liters, and min–max range — so you can immediately judge whether the flagged record is genuinely unusual.</div>
    </div>
    <hr class="help-divider">

    <!-- CHECKLIST -->
    <div class="help-section" id="checklist">
      <div class="help-section-header"><span class="help-section-icon">✅</span><div class="help-section-title">Monthly Checklist Tab</div></div>
      <div class="help-intro">A <strong>daily checklist</strong> of every scheduled truck. <span style="color:var(--green);font-weight:700;">Green rows = refueled ✅</span>, <span style="color:var(--red);font-weight:700;">Red rows = not refueled ❌</span>. Results are paginated — 20 rows per page — and a summary count of refueled vs. not refueled is shown above the table.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>This tab does <strong>not load automatically</strong>. Apply at least a <strong>date range</strong>, <strong>vehicle type</strong>, or <strong>plate</strong> filter first to prevent loading too much data at once. A maximum of <strong>500 rows</strong> are returned per query.</span></div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Day</td><td>Day number of the month (e.g. 1, 2, 3...)</td></tr>
          <tr><td>Date</td><td>Full date of the schedule</td></tr>
          <tr><td>Fuel Time</td><td>The exact time the refuel was recorded (only shows if refueled that day)</td></tr>
          <tr><td>Plate #</td><td>The truck that was scheduled</td></tr>
          <tr><td>Department</td><td>Which department the truck belongs to</td></tr>
          <tr><td>Vehicle Type</td><td>What type of truck it is</td></tr>
          <tr><td>Sched. Driver</td><td>The driver assigned on this date per the team schedule (pulled from teamschedule, matched by plate + date)</td></tr>
          <tr><td>Sched. Area</td><td>The area this truck was scheduled to serve</td></tr>
          <tr><td>Driver</td><td>The driver who actually requested the fuel (from the fuel record — may differ from Sched. Driver)</td></tr>
          <tr><td>INV #</td><td>The fuel invoice/receipt number (only shows if refueled)</td></tr>
          <tr><td>Liters</td><td>How much fuel was received (only shows if refueled)</td></tr>
          <tr><td>Amount</td><td>How much it cost (only shows if refueled)</td></tr>
          <tr><td>Status</td><td>✅ Refueled or ❌ Not Refueled</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>Driver</strong> filter to narrow down the checklist to a specific driver. Use the <strong>search box</strong> to find rows by plate, driver name, or area across the current result set.</span></div>
    </div>
    <hr class="help-divider">

    <!-- FUEL CONSUMPTION -->
    <div class="help-section" id="consumption">
      <div class="help-section-header"><span class="help-section-icon">📆</span><div class="help-section-title">Fuel Consumption Tab</div></div>
      <div class="help-intro">A <strong>monthly breakdown of fuel consumption per truck</strong>, split into weekly columns. Instead of a simple total, you can see how each vehicle's fuel usage is distributed across Week 1, Week 2, Week 3, Week 4, and (when applicable) Week 5 of the selected month. Rows are grouped by <strong>Department → Vehicle Type</strong> for easy comparison.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>month/year selector</strong> at the top of this tab to navigate to any past or current month using the <strong>prev/next arrows</strong> or by typing directly. This tab uses its own <code>?fc_year=</code> / <code>?fc_month=</code> parameters, independently of the main date-range filter.</span></div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>The truck's plate number</td></tr>
          <tr><td>Department</td><td>Which department this truck belongs to</td></tr>
          <tr><td>Vehicle Type</td><td>What category of truck it is</td></tr>
          <tr><td>Total Refuels</td><td>How many times the truck was refueled across the entire selected month</td></tr>
          <tr><td>Total Liters</td><td>Total fuel consumed for the month</td></tr>
          <tr><td>Total Amount</td><td>Total money spent on fuel for the month</td></tr>
          <tr><td>Week 1–5 (Liters)</td><td>Liters consumed per weekly period (Week 1 = days 1–7, Week 2 = days 8–14, Week 3 = days 15–21, Week 4 = days 22–28, Week 5 = days 29–end of month)</td></tr>
          <tr><td>Week 1–5 (Amount)</td><td>Money spent per weekly period</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The table is grouped with <strong>department subtotals</strong> and a <strong>grand total row</strong> at the bottom so you can instantly compare departments and see the overall monthly picture.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Months with 29, 30, or 31 days will have a <strong>5th week column</strong> (days 29–end of month). February in a non-leap year will only show 4 weeks.</span></div>
    </div>
    <hr class="help-divider">

    <!-- USAGE REPORT -->
    <div class="help-section" id="report">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">Usage Report Tab</div></div>
      <div class="help-intro">A <strong>complete transaction history</strong> — every single refuel record in a simple list, like a receipt log. No calculations, no comparisons — just raw data. Perfect for auditing or reconciling with invoices.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>Which truck was refueled</td></tr>
          <tr><td>Department</td><td>Which department the truck belongs to</td></tr>
          <tr><td>Fuel Date</td><td>The date the refuel happened</td></tr>
          <tr><td>Liters</td><td>How much fuel was pumped</td></tr>
          <tr><td>Price/Liter</td><td>The price per liter at the time of refuel</td></tr>
          <tr><td>Amount</td><td>Total cost of this refuel (Liters × Price/Liter)</td></tr>
          <tr><td>Area</td><td>Where the refuel took place</td></tr>
          <tr><td>Driver</td><td>The driver who requested the fuel</td></tr>
          <tr><td>INV #</td><td>The invoice or receipt number from the fuel station</td></tr>
          <tr><td>Supplier</td><td>Which fuel station or supplier provided the fuel</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <!-- GRAPHS OVERVIEW -->
    <div class="help-section" id="graphs-overview">
      <div class="help-section-header"><span class="help-section-icon">📈</span><div class="help-section-title">Fuel Graphs Page — Overview</div></div>
      <div class="help-intro">The <strong>Graphs page</strong> shows the same fuel data but in <strong>visual chart form</strong>. Instead of reading numbers in a table, you see trends, comparisons, and distributions at a glance. All the same filters apply here too.</div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Every chart has <strong>toggle buttons</strong> (e.g. Liters / Amount / Refuels) to switch the metric shown. <strong>Hover</strong> over any bar or slice to see detailed numbers.</span></div>
    </div>

    <div class="help-section" id="graph-consumption">
      <div class="graph-card">
        <div class="graph-card-title">⛽ Fuel Consumption per Truck</div>
        <div class="graph-card-desc">A vertical bar chart showing every truck side by side. The taller the bar, the more fuel it used. Bars are color-coded by vehicle type. Hover for plate, vehicle type, liters, amount, and refuels.</div>
        <div class="toggle-pills"><span class="toggle-pill">Liters — total fuel consumed</span><span class="toggle-pill">Amount — total money spent</span></div>
      </div>
    </div>

    <div class="help-section" id="graph-trend">
      <div class="graph-card">
        <div class="graph-card-title">📈 Fuel Trend Over Time</div>
        <div class="graph-card-desc">A line chart showing fuel activity <strong>day by day</strong> across the selected period. Use this to spot spikes, slow days, or patterns over time.</div>
        <div class="toggle-pills"><span class="toggle-pill">Liters — daily total fuel</span><span class="toggle-pill">Amount — daily total cost</span><span class="toggle-pill">Refuels — how many refuels that day</span></div>
      </div>
    </div>

    <div class="help-section" id="graph-area">
      <div class="graph-card">
        <div class="graph-card-title">📍 Fuel by Area</div>
        <div class="graph-card-desc">A donut chart showing <strong>which areas consume the most fuel</strong>. The bigger the slice, the more fuel. Hover on any slice to see area name, liters, amount, trucks, and percentage share.</div>
        <div class="toggle-pills"><span class="toggle-pill">Liters — fuel share per area</span><span class="toggle-pill">Amount — cost share per area</span></div>
      </div>
    </div>

    <div class="help-section" id="graph-vtype">
      <div class="graph-card">
        <div class="graph-card-title">🚛 By Vehicle Type</div>
        <div class="graph-card-desc">A bar chart comparing fuel consumption <strong>grouped by vehicle category</strong>. Useful for understanding which type of vehicle burns the most fuel overall.</div>
        <div class="toggle-pills"><span class="toggle-pill">Liters — total fuel per type</span><span class="toggle-pill">Amount — total cost per type</span><span class="toggle-pill">Trucks — unique trucks per type</span></div>
      </div>
    </div>

    <div class="help-section" id="graph-top10">
      <div class="graph-card">
        <div class="graph-card-title">🏆 Top 10 Highest Consumers</div>
        <div class="graph-card-desc">A horizontal bar chart showing the <strong>10 trucks that used the most fuel</strong> in the selected period. The quickest way to identify which trucks are driving up fuel costs. Hover for plate, type, liters, amount, and department.</div>
        <div class="toggle-pills"><span class="toggle-pill">Liters — ranked by fuel volume</span><span class="toggle-pill">Amount — ranked by cost</span></div>
      </div>
    </div>

    <div class="help-section" id="graph-status">
      <div class="graph-card">
        <div class="graph-card-title">✅ Refuel Status per Truck</div>
        <div class="graph-card-desc">A stacked bar chart. <span style="color:var(--green);font-weight:700;">Green = days refueled</span>, <span style="color:var(--red);font-weight:700;">Red = days not refueled</span>. The taller the green portion, the more consistently the truck is refueled. Hover to see counts and coverage percentage.</div>
      </div>
    </div>

    <hr class="help-divider">

    <!-- EXPORT -->
    <div class="help-section" id="export">
      <div class="help-section-header"><span class="help-section-icon">💾</span><div class="help-section-title">Export &amp; Print</div></div>
      <div class="help-intro">Every tab has three export buttons in the top right corner. All exports reflect the <strong>full filtered dataset</strong> — not just the current page.</div>
      <table class="col-table">
        <thead><tr><th>Button</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>CSV</td><td>Downloads the data as a plain text file that can be opened in Excel or Google Sheets</td></tr>
          <tr><td>Excel</td><td>Downloads the data as a formatted Excel file (.xls) ready for reporting</td></tr>
          <tr><td>Print</td><td>Opens a print-ready version of the current table with all row colors and filters preserved</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>If you're exporting more than <strong>5,000 rows</strong>, the system will warn you first. Apply date or plate filters to reduce the data before large exports.</span></div>
    </div>

    <?php endif; /* end $_can_fuel */ ?>

    <?php if ($_can_careers): ?>

    <hr class="help-divider">

    <!-- ── CAREERS ADMIN ───────────────────────────────────── -->
    <div class="help-section" id="careers-admin">
      <div class="help-section-header"><span class="help-section-icon">💼</span><div class="help-section-title">Careers Admin Panel</div></div>
      <div class="help-intro">The <strong>Careers Admin Panel</strong> is where you manage all job postings for the company. Any posting you create, edit, or delete here will immediately reflect on the <strong>public careers page</strong> that applicants see.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>ID</td><td>The unique number assigned to each job posting</td></tr>
          <tr><td>Job Title</td><td>The name of the position being offered, along with the department it belongs to</td></tr>
          <tr><td>Image</td><td>A thumbnail preview of the job posting image. Click to see the full image.</td></tr>
          <tr><td>Location</td><td>Where the job is based</td></tr>
          <tr><td>Status</td><td><strong>Active</strong> = visible to applicants on the public page · <strong>Inactive</strong> = hidden from applicants</td></tr>
          <tr><td>Actions</td><td>✏️ Edit the posting · 🗑️ Delete the posting permanently</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <!-- ── ADDING A JOB POST ───────────────────────────────── -->
    <div class="help-section" id="careers-add">
      <div class="help-section-header"><span class="help-section-icon">➕</span><div class="help-section-title">Adding a New Job Post</div></div>
      <div class="help-intro">Click the <strong>Add New Career</strong> button at the top right of the Careers Admin page to open the form.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Enter the <strong>Job Title</strong> — this is what applicants will see on the public page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Set the <strong>Status</strong> — Active makes it visible to applicants immediately. Inactive keeps it hidden until you're ready.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Fill in the <strong>Department</strong> and <strong>Location</strong> so applicants know where the job is.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Write the <strong>Job Description</strong> — what the job involves day to day.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Write the <strong>Qualifications</strong> — what the applicant needs to have (education, experience, skills).</div></div>
        <div class="step-item"><div class="step-num">6</div><div class="step-text">Upload a <strong>Job Image</strong> (optional) — this appears as a banner on the job details page.</div></div>
        <div class="step-item"><div class="step-num">7</div><div class="step-text">Click <strong>Save</strong> — the posting is now live if you set it to Active.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><strong>Tip:</strong> You can save a posting as <strong>Inactive</strong> first to draft it, then switch it to Active when you're ready to publish.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── EDITING / DELETING ──────────────────────────────── -->
    <div class="help-section" id="careers-edit">
      <div class="help-section-header"><span class="help-section-icon">✏️</span><div class="help-section-title">Editing &amp; Deleting Job Posts</div></div>
      <div class="help-intro">Click the <strong>pencil icon ✏️</strong> on any row to edit that job posting. All fields can be changed including the image. Click <strong>Save</strong> when done. Click the <strong>trash icon 🗑️</strong> to permanently delete a posting — a confirmation prompt will appear first.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Note:</strong> Deleting a job post is <strong>permanent</strong> and cannot be undone. If you just want to hide it from applicants, set its Status to <strong>Inactive</strong> instead.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── APPLICATIONS ────────────────────────────────────── -->
    <div class="help-section" id="applications">
      <div class="help-section-header"><span class="help-section-icon">👥</span><div class="help-section-title">Applications Page</div></div>
      <div class="help-intro">The <strong>Applications page</strong> shows all job applications submitted through the public careers page. Applications are organized into <strong>tabs by stage</strong> — Pending, Evaluating, Interview, Hired, and Rejected — so you can focus on one group at a time. You can review applicant details, view their uploaded documents by category, and update their application status.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Applicant</td><td>The applicant's full name and the department they applied to</td></tr>
          <tr><td>Contact</td><td>The applicant's email address and phone number</td></tr>
          <tr><td>Position</td><td>Which job posting they applied for</td></tr>
          <tr><td>Date Applied</td><td>When they submitted their application</td></tr>
          <tr><td>Files</td><td>Attached files grouped by category (e.g. Resume/CV, NBI Clearance) — click to view or download each file</td></tr>
          <tr><td>Interview</td><td>Shows the scheduled interview date, time, and office address if one has been set</td></tr>
          <tr><td>Status</td><td>The current stage of their application — click the status badge to update it (see below)</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>date range</strong>, <strong>status</strong>, and <strong>search</strong> filters at the top to quickly find specific applicants.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Interview Tab:</strong> When moving an applicant to <strong>For Interview</strong> or <strong>Final Interview</strong>, you'll be asked to fill in the <strong>interview date &amp; time</strong>, <strong>office address</strong>, and <strong>HR contact</strong>. These details appear in the applicant's row under the Interview column.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><strong>Department Assignment:</strong> When moving an applicant out of Pending, it's recommended to assign them a <strong>department</strong>. This ensures HR staff from that department can see and manage the application.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── APPLICATION STATUSES ───────────────────────────── -->
    <div class="help-section" id="app-status">
      <div class="help-section-header"><span class="help-section-icon">🏷️</span><div class="help-section-title">Application Statuses</div></div>
      <div class="help-intro">Each application has a <strong>status</strong> that tracks where the applicant is in the hiring process. You can update the status by clicking the status badge on any applicant row.</div>
      <table class="col-table">
        <thead><tr><th>Status</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Pending</td><td>Newly submitted — has not been reviewed yet. All new applications land here first. Visible to all HR staff regardless of department.</td></tr>
          <tr><td>Evaluating</td><td>Currently being reviewed by HR — checking qualifications and fit. Once moved here, the application is visible only to the assigned department's HR.</td></tr>
          <tr><td>For Interview</td><td>Applicant has been shortlisted and scheduled for a first (initial) interview</td></tr>
          <tr><td>Re-schedule Interview</td><td>The original interview was moved — a new schedule is being arranged</td></tr>
          <tr><td>Final Interview</td><td>Applicant passed the first interview and is being called for a final round</td></tr>
          <tr><td>Final Interview Rescheduled</td><td>The final interview was moved — a new schedule is being arranged</td></tr>
          <tr><td>Hired</td><td>Applicant has been accepted and will be onboarded</td></tr>
          <tr><td>Rejected</td><td>Applicant was not selected for this position</td></tr>
        </tbody>
      </table>
    </div>

    <hr class="help-divider">

    <!-- ── PUBLIC CAREERS PAGE ────────────────────────────── -->
    <div class="help-section" id="careers-public">
      <div class="help-section-header"><span class="help-section-icon">🌐</span><div class="help-section-title">Public Careers Page</div></div>
      <div class="help-intro">The <strong>public careers page</strong> is what applicants see when they visit the company website. It shows all <strong>Active</strong> job postings as cards. Applicants can click any card to view the full job details and submit their application.</div>
      <table class="col-table">
        <thead><tr><th>Element</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Job Cards</td><td>Each card shows the job title and location. Only Active postings appear here.</td></tr>
          <tr><td>View Details</td><td>Clicking a card opens the full job details page with description, qualifications, and an Apply button</td></tr>
          <tr><td>Apply Button</td><td>Opens the application form where applicants fill in their info and upload their files, each tagged with a document category (e.g. Resume/CV, NBI Clearance)</td></tr>
          <tr><td>Data Privacy Notice</td><td>Applicants must read and accept the <strong>Terms &amp; Conditions / Data Privacy Notice</strong> before the form is shown — this is required by RA 10173</td></tr>
          <tr><td>Active Listings count</td><td>Shows how many open positions are currently available</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>When you set a job posting to <strong>Active</strong> in the admin panel, it appears on the public page <strong>immediately</strong>. Setting it to <strong>Inactive</strong> removes it from the public page right away too.</span></div>
    </div>

    <hr class="help-divider">

    <!-- ── APPLICATION FORM ───────────────────────────────────────────────────── -->
    <div class="help-section" id="careers-apply">
      <div class="help-section-header"><span class="help-section-icon">📝</span><div class="help-section-title">Submitting a Job Application</div></div>
      <div class="help-intro">When an applicant clicks <strong>Apply</strong> on a job posting, they go through a two-step process — accepting the <strong>Data Privacy Notice</strong> first, then filling out the application form.</div>

      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin-bottom:.5rem;">Step 1 — Data Privacy Notice (T&amp;C)</p>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">A <strong>Data Privacy Notice modal</strong> appears before the form. The applicant must scroll through and read the notice.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">The applicant checks the <strong>"I have read and understood"</strong> checkbox to enable the Agree button.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Clicking <strong>"I Agree &amp; Proceed"</strong> records their consent and reveals the application form. Clicking <strong>Decline</strong> redirects them back to the careers page.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>T&amp;C acceptance is recorded in the database along with the exact <strong>date and time</strong> of consent — this ensures compliance with <strong>RA 10173 (Data Privacy Act of 2012)</strong>.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The application form <strong>cannot be submitted</strong> without accepting the T&amp;C first. There is no way to bypass this step.</span></div>

      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">Step 2 — Application Form</p>
      <table class="col-table">
        <thead><tr><th>Field</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td>Full Name <span style="color:#dc2626">*</span></td><td>The applicant's complete name</td></tr>
          <tr><td>Email Address <span style="color:#dc2626">*</span></td><td>Used for confirmation email and HR contact</td></tr>
          <tr><td>Phone Number</td><td>Optional contact number</td></tr>
          <tr><td>Position Applied For</td><td>Pre-filled from the job posting — cannot be changed by the applicant</td></tr>
          <tr><td>Documents <span style="color:#dc2626">*</span></td><td>File uploads — each file must have a <strong>category</strong> selected (see below). At least one file is required.</td></tr>
        </tbody>
      </table>

      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">File Upload &amp; Categories</p>
      <div class="help-intro">Each uploaded file must be tagged with a <strong>document category</strong> so HR knows what they're reviewing. Applicants select the category first, then attach the file.</div>
      <table class="col-table">
        <thead><tr><th>Rule</th><th>Details</th></tr></thead>
        <tbody>
          <tr><td>Category Required</td><td>Every file must have a category selected (e.g. Resume/CV, NBI Clearance, Police Clearance). The form will not submit without one.</td></tr>
          <tr><td>Allowed File Types</td><td>PDF, DOC, DOCX, JPG, JPEG, PNG</td></tr>
          <tr><td>Max File Size</td><td>10 MB per file</td></tr>
          <tr><td>Max Files</td><td>Up to 10 files per application</td></tr>
          <tr><td>Add More Files</td><td>Click <strong>"+ Add Another Document"</strong> to add more file slots. Each slot has its own category selector.</td></tr>
          <tr><td>Remove a File</td><td>Click the <strong>✕</strong> button on any slot to remove it. The last slot resets instead of being removed.</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>After a successful submission, the applicant receives a <strong>confirmation email</strong> listing all submitted files and their categories, along with the date and time of submission.</span></div>
    </div>

    <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">How File Uploads Work — Applicant Side</p>
    <div class="step-list">
      <div class="step-item"><div class="step-num">1</div><div class="step-text">The form starts with <strong>one file slot</strong>. Each slot has two parts — a <strong>category dropdown</strong> on the left and a <strong>file picker</strong> on the right.</div></div>
      <div class="step-item"><div class="step-num">2</div><div class="step-text">Select a <strong>document category</strong> from the dropdown first (e.g. Resume/CV, NBI Clearance, Police Clearance) — the file picker is in the same slot right beside it.</div></div>
      <div class="step-item"><div class="step-num">3</div><div class="step-text">Click <strong>+ Add Another Document</strong> to add more slots. Each slot is independent — you can mix different categories and file types.</div></div>
      <div class="step-item"><div class="step-num">4</div><div class="step-text">To remove a slot, click the <strong>✕</strong> button on the right side of the slot. If it's the only slot remaining, it resets instead of disappearing.</div></div>
      <div class="step-item"><div class="step-num">5</div><div class="step-text">Once all files are attached and categories selected, click <strong>Submit Application</strong>. The system validates everything before saving.</div></div>
    </div>

    <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The form will <strong>block submission</strong> if any of these are true: a file has no category selected, a file exceeds 10 MB, an unsupported file type is uploaded, or no files are attached at all.</span></div>

    <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">How File Uploads Work — HR Side</p>
    <div class="help-intro">On the <strong>Applications page</strong>, each applicant row shows a <strong>files button</strong> with a count (e.g. "3 files"). Clicking it opens a modal showing all uploaded documents grouped by their category.</div>
    <table class="col-table">
      <thead><tr><th>Element</th><th>What it means</th></tr></thead>
      <tbody>
        <tr><td>Category Header</td><td>Files are grouped under their category label (e.g. Resume/CV, NBI Clearance) so HR can quickly find the right document</td></tr>
        <tr><td>File Row</td><td>Shows the file icon (color-coded by type), cleaned file name, and file type label</td></tr>
        <tr><td>Open Link</td><td>Click any file row to open the document in a new tab for viewing or downloading</td></tr>
        <tr><td>File Icons</td><td>🔴 PDF · 🔵 DOC/DOCX · 🟡 JPG/PNG images · 🟢 Excel files</td></tr>
      </tbody>
    </table>
    <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>File names are automatically <strong>cleaned up</strong> for display — the system strips the unique ID suffix added during upload so HR sees a readable name instead of a long string of characters.</span></div>

    <?php endif; /* end $_can_careers */ ?>

    <!-- ══ UNIFORM INVENTORY SECTION ══════════════════════════ -->
    <?php if ($_can_uniform): ?>

    <hr class="help-divider">

    <div class="help-section" id="uniform-overview">
      <div class="help-section-header">
        <span class="help-section-icon">🧥</span>
        <div class="help-section-title">Uniform Inventory — Overview</div>
      </div>
      <div class="help-intro">The <strong>Uniform Inventory</strong> module lets Admin and HR staff manage the full lifecycle of company uniforms — from stocking items and issuing them to employees, to creating Purchase Orders for restocking and receiving deliveries from suppliers.</div>
      <table class="col-table">
        <thead><tr><th>Feature</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Stat Cards</td><td>Shows a live summary: total uniform types, total stock, low-stock items, and out-of-stock items</td></tr>
          <tr><td>Inventory Tab</td><td>Full list of all uniform items with stock levels and status badges</td></tr>
          <tr><td>History Tab</td><td>A searchable log of every issue and return transaction</td></tr>
          <tr><td>Purchase Orders</td><td>Create and track POs for ordering uniforms from suppliers</td></tr>
          <tr><td>Receiving</td><td>Record the actual delivery of items against an existing PO</td></tr>
          <tr><td>Printing</td><td>Print issuance slips, PO documents, and receiving reports directly from the system</td></tr>
          <tr><td>Department Filter</td><td>The page respects your active department (set via the topbar), so each department only sees their own uniforms</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>Low Stock Alert</strong> threshold on each item so the system warns you before you run out. Items at or below the threshold turn <strong>yellow</strong>; items at zero turn <strong>red</strong>.</span></div>
    </div>

    <div class="help-section" id="uniform-items">
      <div class="help-section-header">
        <span class="help-section-icon">➕</span>
        <div class="help-section-title">Adding &amp; Editing Uniform Items</div>
      </div>
      <div class="help-intro">Uniform items are the master catalog — each entry represents a specific uniform type and size combination (e.g. "Polo Shirt · L"). You can add as many as needed.</div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td>Item Name <span style="color:#dc2626">*</span></td><td>Name of the uniform (e.g. "Polo Shirt", "Safety Shoes")</td></tr>
          <tr><td>Category</td><td>Group items together (e.g. "Tops", "Bottoms", "Footwear", "Safety Gear")</td></tr>
          <tr><td>Size</td><td>Select from XS, S, M, L, XL, XXL, XXXL, or Free Size</td></tr>
          <tr><td>Department</td><td>Assign to a specific department, or leave blank to show for all departments</td></tr>
          <tr><td>Stock Quantity</td><td>Current number of items in storage</td></tr>
          <tr><td>Low Stock Alert At</td><td>When stock drops to this number or below, the item is flagged yellow as "Low Stock"</td></tr>
          <tr><td>Description</td><td>Optional notes about the item (e.g. brand, material, supplier info)</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Click <strong>Add Uniform Item</strong> in the top right of the page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Fill in the item name, category, size, and starting stock quantity.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Set the <strong>Low Stock Alert</strong> threshold — this is the number at which the system warns you stock is running low.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Save Item</strong>. The item will appear in the inventory table immediately.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>To edit an existing item, click the <strong>Edit</strong> button (pencil icon) on any row. The same form opens pre-filled with the current data.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Deleting an item <strong>permanently removes</strong> it and all its transaction history. This action cannot be undone — deactivate items instead of deleting them whenever possible.</span></div>
    </div>

    <div class="help-section" id="uniform-issue">
      <div class="help-section-header">
        <span class="help-section-icon">⬆️</span>
        <div class="help-section-title">Issuing Uniforms to Employees</div>
      </div>
      <div class="help-intro">When an employee receives a uniform from stock, record it as an <strong>Issue</strong> transaction. This automatically deducts the quantity from the item's stock count.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">On the <strong>Inventory</strong> tab, find the item you want to issue and click <strong>Issue/Return</strong>.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">In the modal, make sure <strong>Issue</strong> is selected (highlighted in blue).</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Enter the employee's full name, their department, and the quantity being issued.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Add any optional remarks (e.g. "replacement for damaged item") and click <strong>Confirm</strong>.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>The system checks stock before saving. If you try to issue more than what's available, you'll see an error and the transaction won't be recorded.</span></div>
    </div>

    <div class="help-section" id="uniform-return">
      <div class="help-section-header">
        <span class="help-section-icon">⬇️</span>
        <div class="help-section-title">Returning Uniforms</div>
      </div>
      <div class="help-intro">When an employee returns a uniform (e.g. when resigning or exchanging for a different size), record it as a <strong>Return</strong> transaction. This adds the quantity back to the item's stock count.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">On the <strong>Inventory</strong> tab, find the item being returned and click <strong>Issue/Return</strong>.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">In the modal, click <strong>Return</strong> (it turns green when selected).</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Enter the employee's name, department, quantity being returned, and any remarks.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Confirm</strong>. The stock count updates automatically.</div></div>
      </div>
    </div>

    <div class="help-section" id="uniform-history">
      <div class="help-section-header">
        <span class="help-section-icon">🕐</span>
        <div class="help-section-title">Issuance History</div>
      </div>
      <div class="help-intro">The <strong>History tab</strong> shows the last 200 transactions across all uniform items. Every issue and return is logged with the employee name, date, quantity, and who processed it.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td>Date</td><td>Exact date and time the transaction was recorded</td></tr>
          <tr><td>Type</td><td><strong>ISSUE</strong> (blue) = given to employee · <strong>RETURN</strong> (green) = received back from employee</td></tr>
          <tr><td>Item / Size</td><td>Which uniform was transacted</td></tr>
          <tr><td>Employee</td><td>Name and department of the employee</td></tr>
          <tr><td>Qty</td><td>Number of items in the transaction</td></tr>
          <tr><td>Processed By</td><td>The logged-in admin/HR user who recorded the transaction</td></tr>
          <tr><td>Remarks</td><td>Any notes entered at the time of the transaction</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Use the <strong>search box</strong> to find transactions by employee name or item name. Use the <strong>type filter</strong> to show only Issues or only Returns.</span></div>
    </div>

    <!-- ── PURCHASE ORDERS ─────────────────────────────────── -->
    <div class="help-section" id="uniform-po">
      <div class="help-section-header">
        <span class="help-section-icon">📄</span>
        <div class="help-section-title">Purchase Orders (PO)</div>
      </div>
      <div class="help-intro">The <strong>Purchase Order</strong> page lets Admin and HR staff create and track orders for uniform restocking. A PO is a formal request sent to a supplier that lists which items need to be ordered, in what quantities, and at what price. Once a PO is created, it can be monitored until delivery is confirmed through the Receiving page.</div>
      <table class="col-table">
        <thead><tr><th>Column / Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>PO Number</td><td>A unique auto-generated reference number for this purchase order</td></tr>
          <tr><td>Supplier</td><td>The vendor or supplier the order is being sent to</td></tr>
          <tr><td>Date Created</td><td>When the PO was raised</td></tr>
          <tr><td>Items</td><td>The list of uniform items being ordered, with size, quantity, and unit price per line</td></tr>
          <tr><td>Total Amount</td><td>The computed total cost of all items in the PO</td></tr>
          <tr><td>Status</td><td><strong>Pending</strong> = not yet delivered · <strong>Partially Received</strong> = some items delivered · <strong>Fully Received</strong> = all items received and closed</td></tr>
          <tr><td>Created By</td><td>The HR or Admin user who created the PO</td></tr>
          <tr><td>Actions</td><td>View PO details · Print PO document · Record a delivery via Receiving</td></tr>
        </tbody>
      </table>
      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">Creating a Purchase Order</p>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Click <strong>Create PO</strong> at the top of the Purchase Orders page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Select or type the <strong>Supplier</strong> name.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Add line items — select the <strong>uniform item</strong>, its <strong>size</strong>, the <strong>quantity</strong> to order, and the <strong>unit price</strong>.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>+ Add Line</strong> to add more items to the same PO. Remove lines with the <strong>✕</strong> button.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Review the total amount at the bottom, then click <strong>Save PO</strong>. The PO is created with a <strong>Pending</strong> status.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>A PO does <strong>not</strong> automatically update stock levels. Stock is only updated when items are actually received through the <strong>Receiving</strong> page.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Once a PO has been fully received, it is <strong>closed</strong> and can no longer be edited. Make sure quantities and prices are correct before saving.</span></div>
    </div>

    <!-- ── RECEIVING ───────────────────────────────────────── -->
    <div class="help-section" id="uniform-receiving">
      <div class="help-section-header">
        <span class="help-section-icon">📦</span>
        <div class="help-section-title">Receiving</div>
      </div>
      <div class="help-intro">The <strong>Receiving</strong> page is where you record the actual delivery of uniform items from a supplier against an existing Purchase Order. When you confirm receipt of items, their quantities are automatically added to the inventory stock count.</div>
      <table class="col-table">
        <thead><tr><th>Column / Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>PO Number</td><td>The Purchase Order this delivery is for</td></tr>
          <tr><td>Supplier</td><td>Which supplier made the delivery</td></tr>
          <tr><td>Received Date</td><td>The date the items were physically received</td></tr>
          <tr><td>Item</td><td>The uniform item being received</td></tr>
          <tr><td>Ordered Qty</td><td>How many were originally ordered on the PO</td></tr>
          <tr><td>Previously Received</td><td>How many of this item have already been received from prior deliveries on the same PO</td></tr>
          <tr><td>Received Now</td><td>The quantity being received in this specific delivery — enter the actual count</td></tr>
          <tr><td>Remarks</td><td>Optional notes (e.g. "2 units damaged on arrival", "partial delivery")</td></tr>
          <tr><td>Received By</td><td>The HR or Admin user confirming the receipt</td></tr>
        </tbody>
      </table>
      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">Recording a Delivery</p>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to the <strong>Receiving</strong> page and click <strong>Receive Items</strong>, or click the <strong>Receive</strong> action button on an open PO.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Select the <strong>PO Number</strong> from the list. Only POs with a Pending or Partially Received status will appear.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">The line items from the PO will load automatically. For each item, enter the <strong>Received Now</strong> quantity based on what was physically delivered.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Add any <strong>Remarks</strong> for discrepancies, damage, or partial deliveries.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Click <strong>Confirm Receipt</strong>. Stock levels are immediately updated for each received item, and the PO status updates automatically to <strong>Partially Received</strong> or <strong>Fully Received</strong>.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>You can record <strong>multiple partial deliveries</strong> against the same PO. Each receiving entry is logged separately in the receiving history, showing the date, quantities, and who confirmed the receipt.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>You cannot receive more items than the quantity on the PO. If a supplier delivers extra units beyond what was ordered, those must be handled through a separate PO or manual stock adjustment.</span></div>
    </div>

    <!-- ── PRINTING ────────────────────────────────────────── -->
    <div class="help-section" id="uniform-print">
      <div class="help-section-header">
        <span class="help-section-icon">🖨️</span>
        <div class="help-section-title">Printing</div>
      </div>
      <div class="help-intro">The system provides <strong>print-ready templates</strong> for key Uniform Inventory documents. These are formatted for clean, professional output — no extra setup needed. You can trigger printing directly from the relevant page.</div>
      <table class="col-table">
        <thead><tr><th>Document</th><th>How to print it</th></tr></thead>
        <tbody>
          <tr><td>Issuance Slip</td><td>After confirming an Issue transaction, click the <strong>Print Slip</strong> button in the confirmation modal. Shows employee name, item, size, quantity, date, and the HR staff who processed it.</td></tr>
          <tr><td>Purchase Order</td><td>On the Purchase Orders list or the PO detail page, click the <strong>Print PO</strong> button. Shows PO number, supplier, line items, quantities, unit prices, total amount, and date.</td></tr>
          <tr><td>Receiving Report</td><td>After confirming a receiving entry, click <strong>Print Receiving Report</strong>. Shows the PO reference, supplier, items received, quantities, date, and who confirmed the receipt.</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>All print templates open in a <strong>new browser tab</strong> formatted for standard paper size. Use your browser's <strong>Print</strong> dialog (Ctrl+P / Cmd+P) to send to a printer or save as PDF.</span></div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Print templates <strong>automatically hide</strong> navigation bars, sidebars, and buttons — only the document content is shown when printing.</span></div>
    </div>

    <?php endif; /* end $_can_uniform */ ?>

    <!-- ══ EMPLOYEE DIRECTORY SECTION ══════════════════════════ -->
    <?php if ($_can_emp): ?>

    <hr class="help-divider">

    <!-- ── EMPLOYEE LIST ──────────────────────────────────── -->
    <div class="help-section" id="emp-list">
      <div class="help-section-header">
        <span class="help-section-icon">👤</span>
        <div class="help-section-title">Employee List (Active)</div>
      </div>
      <div class="help-intro">The <strong>Employee List</strong> is the master directory of all currently active employees in the company. Admin and HR staff can view, search, add, and manage employee records from this page. The list is filtered by your active department — Admins can switch departments to view all staff.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee Name</td><td>Full name of the employee</td></tr>
          <tr><td>Employee ID</td><td>The unique ID number assigned to the employee</td></tr>
          <tr><td>Department</td><td>Which department the employee belongs to</td></tr>
          <tr><td>Position</td><td>The employee's job title or role</td></tr>
          <tr><td>Date Hired</td><td>When the employee officially started</td></tr>
          <tr><td>Contact</td><td>Email address and/or phone number</td></tr>
          <tr><td>Status</td><td>Shows <strong>Active</strong> for employees currently in this list</td></tr>
          <tr><td>Actions</td><td>✏️ Edit employee details · 📋 View full profile · ⬇️ Move to Inactive · 🚫 Move to Blacklist</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>search box</strong> to find employees by name, ID, or position. Use the <strong>department filter</strong> to narrow results to a specific team.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Moving an employee to <strong>Inactive</strong> or <strong>Blacklisted</strong> does not delete their record — it transfers them to a separate list so their history is preserved.</span></div>
    </div>

    <!-- ── INACTIVE EMPLOYEES ─────────────────────────────── -->
    <div class="help-section" id="emp-inactive">
      <div class="help-section-header">
        <span class="help-section-icon">😴</span>
        <div class="help-section-title">Inactive Employees</div>
      </div>
      <div class="help-intro">The <strong>Inactive Employees</strong> page shows staff who are no longer actively employed but whose records are retained — for example, resigned employees, those on extended leave, or former contractors. Their data is kept for reference and can be reactivated if they return.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee Name</td><td>Full name of the employee</td></tr>
          <tr><td>Employee ID</td><td>Their original employee ID number</td></tr>
          <tr><td>Department</td><td>The department they belonged to</td></tr>
          <tr><td>Position</td><td>Their last held job title</td></tr>
          <tr><td>Date Hired</td><td>When they originally started</td></tr>
          <tr><td>Date Inactivated</td><td>When their status was changed to Inactive</td></tr>
          <tr><td>Reason</td><td>The reason for inactivation (e.g. Resigned, End of Contract, Extended Leave)</td></tr>
          <tr><td>Actions</td><td>✅ Reactivate — moves back to the Active Employee List · 🚫 Move to Blacklist</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>To <strong>reactivate</strong> an employee (e.g. a rehire), click the <strong>Reactivate</strong> button on their row. Their record will move back to the Active Employee List with their original details intact.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Inactive employees are <strong>not visible</strong> on the main Employee List and do not count toward active headcount reports — but all their historical records (uniform issuance, applications, etc.) are still accessible.</span></div>
    </div>

    <!-- ── BLACKLISTED EMPLOYEES ──────────────────────────── -->
    <div class="help-section" id="emp-blacklisted">
      <div class="help-section-header">
        <span class="help-section-icon">🚫</span>
        <div class="help-section-title">Blacklisted Employees</div>
      </div>
      <div class="help-intro">The <strong>Blacklisted Employees</strong> page is a restricted list of individuals who have been flagged and are not eligible for rehire. This may include employees terminated for cause, those with serious conduct violations, or individuals identified as a risk. Access to this list is limited to <strong>Admin and HR</strong> only.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee Name</td><td>Full name of the blacklisted individual</td></tr>
          <tr><td>Employee ID</td><td>Their original employee ID</td></tr>
          <tr><td>Department</td><td>The department they belonged to when blacklisted</td></tr>
          <tr><td>Position</td><td>Their last held position</td></tr>
          <tr><td>Date Blacklisted</td><td>When they were added to the blacklist</td></tr>
          <tr><td>Reason</td><td>The documented reason for blacklisting (e.g. Terminated for Cause, Theft, Serious Misconduct)</td></tr>
          <tr><td>Blacklisted By</td><td>The Admin or HR user who added them to the list</td></tr>
          <tr><td>Actions</td><td>👁️ View full record · 📝 Edit reason/notes (Admin only)</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Blacklisted employees cannot be reactivated</strong> through the normal process. Removal from the blacklist requires Admin-level authorization and should be handled with care. If a blacklisted name appears in the Careers applicant list, HR will be alerted automatically.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Always document a clear and accurate <strong>reason</strong> when blacklisting an employee. This record may be referenced for future screening, legal compliance, or internal audits.</span></div>
    </div>

    <?php endif; /* end $_can_emp */ ?>

  </main>
</div>

<div class="footer">Help Manual · Tradewell Admin Portal · Generated <?= date('Y-m-d') ?></div>

<script>
// ── Section ID → group mapping ─────────────────────────────────
const sectionGroup = {
  'getting-started': 'started', 'filters': 'started', 'department': 'started',
  'summary': 'fuel', 'ranking': 'fuel', '30day': 'fuel', 'area': 'fuel',
  'comparison': 'fuel', 'anomaly': 'fuel', 'checklist': 'fuel', 'consumption': 'fuel', 'report': 'fuel',
  'graphs-overview': 'graphs', 'graph-consumption': 'graphs', 'graph-trend': 'graphs',
  'graph-area': 'graphs', 'graph-vtype': 'graphs', 'graph-top10': 'graphs', 'graph-status': 'graphs',
  'export': 'other',
  'careers-admin': 'careers', 'careers-add': 'careers', 'careers-edit': 'careers',
  'applications': 'careers', 'app-status': 'careers', 'careers-apply': 'careers', 'careers-public': 'careers',
  'uniform-overview': 'uniform', 'uniform-items': 'uniform', 'uniform-issue': 'uniform',
  'uniform-return': 'uniform', 'uniform-history': 'uniform',
  'uniform-po': 'uniform', 'uniform-receiving': 'uniform', 'uniform-print': 'uniform',
  'emp-list': 'employees', 'emp-inactive': 'employees', 'emp-blacklisted': 'employees',
};

// ── Toggle accordion group ─────────────────────────────────────
function toggleGroup(id) {
  const body   = document.getElementById('grp-' + id);
  const toggle = body?.previousElementSibling;
  if (!body) return;
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  if (toggle) toggle.classList.toggle('open', !isOpen);
}

function openGroup(id) {
  const body   = document.getElementById('grp-' + id);
  const toggle = body?.previousElementSibling;
  if (!body) return;
  body.classList.add('open');
  if (toggle) toggle.classList.add('open');
}

// ── Scroll spy ─────────────────────────────────────────────────
const sections = document.querySelectorAll('.help-section[id]');
const navLinks  = document.querySelectorAll('.hn-link');

window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(s => { if (window.scrollY >= s.offsetTop - 120) current = s.id; });

  navLinks.forEach(a => {
    const active = a.getAttribute('href') === '#' + current;
    a.classList.toggle('active', active);
  });

  // Auto-open the group that contains the active section
  if (current && sectionGroup[current]) {
    openGroup(sectionGroup[current]);
  }
}, { passive: true });

// ── Open the first group on load ───────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const hash = location.hash.replace('#', '');
  const initGroup = (hash && sectionGroup[hash]) ? sectionGroup[hash] : 'started';
  openGroup(initGroup);
});
</script>
</body>
</html>