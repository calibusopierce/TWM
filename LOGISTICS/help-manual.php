<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('fuel_dashboard') && !rbac_can('graphs')) {
    header('Location: ' . base_url('help-manual.php')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Logistics · Tradewell</title>
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
.hn-group-body.open{max-height:800px}
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
.col-table td:first-child{font-weight:700;color:var(--primary);white-space:nowrap;font-family:'DM Mono',monospace;font-size:.8rem;width:180px}
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
.filter-grid{display:grid;grid-template-columns:1fr 1fr;gap:.65rem;margin-bottom:1rem}
.filter-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem .9rem;box-shadow:var(--shadow-sm)}
.filter-item-name{font-weight:700;font-size:.85rem;color:var(--text-primary);margin-bottom:.2rem;display:flex;align-items:center;gap:.35rem}
.filter-item-name i{color:var(--primary-light)}
.filter-item-desc{font-size:.82rem;color:var(--text-secondary);line-height:1.55}
.graph-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:.85rem;box-shadow:var(--shadow-sm)}
.graph-card:hover{border-color:var(--primary-light)}
.graph-card-title{font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:.35rem;display:flex;align-items:center;gap:.4rem}
.graph-card-desc{font-size:.88rem;color:var(--text-primary);line-height:1.7;margin-bottom:.6rem}
.toggle-pills{display:flex;gap:.3rem;flex-wrap:wrap}
.toggle-pill{font-size:.74rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;border:1px solid var(--border-strong);background:var(--surface-3);color:var(--text-secondary)}
.help-divider{border:none;border-top:1px solid var(--border);margin:2rem 0}
.footer{text-align:center;padding:1.5rem;font-size:.75rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:1rem}
@media(max-width:900px){.help-layout{flex-direction:column;padding:1rem}.help-sidebar{width:100%;position:static}.filter-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="help-layout">

  <nav class="help-sidebar">
    <div class="hn-title">📖 Logistics Help</div>
    <div class="hn-group" data-group="started">
      <button class="hn-group-toggle" onclick="toggleGroup('started')"><span>🚀 Getting Started</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-started">
        <a href="#quickstart" class="hn-link"><i class="bi bi-play-circle"></i> Quick Start</a>
        <a href="#filters"    class="hn-link"><i class="bi bi-funnel"></i> Using Filters</a>
        <a href="#export"     class="hn-link"><i class="bi bi-download"></i> Export &amp; Print</a>
      </div>
    </div>
    <div class="hn-group" data-group="fuel">
      <button class="hn-group-toggle" onclick="toggleGroup('fuel')"><span>⛽ Fuel Dashboard</span><i class="bi bi-chevron-down toggle-caret"></i></button>
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
    <div class="hn-group" data-group="graphs">
      <button class="hn-group-toggle" onclick="toggleGroup('graphs')"><span>📊 Graphs Page</span><i class="bi bi-chevron-down toggle-caret"></i></button>
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
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Logistics <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to the Fuel Monitoring Dashboard and Analytics Graphs — track fleet fuel usage, spot anomalies, and export reports with ease.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-fuel-pump"></i> Fuel Dashboard</span>
        <span class="help-chip"><i class="bi bi-bar-chart"></i> Analytics Graphs</span>
        <span class="help-chip"><i class="bi bi-exclamation-triangle"></i> Anomaly Detection</span>
        <span class="help-chip"><i class="bi bi-truck"></i> Fleet Coverage</span>
        <span class="help-chip"><i class="bi bi-download"></i> Export &amp; Print</span>
      </div>
    </div>

    <div class="help-section" id="quickstart">
      <div class="help-section-header"><span class="help-section-icon">🚀</span><div class="help-section-title">Quick Start</div></div>
      <div class="help-intro">The <strong>Fuel Monitoring Dashboard</strong> is your tool for tracking how much fuel every truck uses, how often they refuel, and whether anything looks suspicious. Think of it as a <strong>report card for every truck in the fleet</strong>.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Data defaults to <strong>this month</strong>. Change the date range using the filters at the top.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Use the <strong>tabs</strong> to switch between different views — each tab shows a different angle of the same data.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Download or print any table using the <strong>CSV</strong>, <strong>Excel</strong>, or <strong>Print</strong> buttons in the top right of each tab.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Go to the <strong>Graphs page</strong> for visual charts of the same data — trends, comparisons, and distributions at a glance.</div></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="filters">
      <div class="help-section-header"><span class="help-section-icon">🔍</span><div class="help-section-title">Using Filters</div></div>
      <div class="help-intro">Filters let you <strong>narrow down the data</strong> so you only see what you need. All filters apply to every tab at the same time. Click <strong>Apply</strong> to run, or <strong>Clear</strong> to reset everything.</div>
      <div class="filter-grid">
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-calendar3"></i> Date From / To</div><div class="filter-item-desc">Show data only within a specific date range. Leave blank to use the current month default.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-truck"></i> Vehicle Type</div><div class="filter-item-desc">Filter by truck category (e.g. 4-Wheeler, 6-Wheeler). Useful when comparing trucks of the same type.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-tag"></i> Plate #</div><div class="filter-item-desc">Search for a specific truck. You can type just part of the plate number — it will find all matches.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-person"></i> Driver</div><div class="filter-item-desc">Filter records by driver name. Partial text accepted — matches against the fuel record driver and the team schedule.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-geo-alt"></i> Area</div><div class="filter-item-desc">Show only trucks or refuels from a specific delivery area or route. Filters all tabs simultaneously.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-building"></i> Department (Auto)</div><div class="filter-item-desc">Your department is automatically applied from your login. Change it via the avatar menu in the topbar.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Combine multiple filters at once — e.g. Vehicle Type = "6-Wheeler" AND Area = "Makati". Active filters appear as <strong>removable chips</strong> in the filter bar so you can see and clear them at a glance.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="export">
      <div class="help-section-header"><span class="help-section-icon">💾</span><div class="help-section-title">Export &amp; Print</div></div>
      <div class="help-intro">Every tab has three export buttons in the top right corner. All exports reflect the <strong>full filtered dataset</strong> — not just the current page.</div>
      <table class="col-table">
        <thead><tr><th>Button</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>CSV</td><td>Downloads the data as a plain text file that can be opened in Excel or Google Sheets</td></tr>
          <tr><td>Excel</td><td>Downloads a formatted Excel file (.xls) ready for reporting</td></tr>
          <tr><td>Print</td><td>Opens a print-ready version of the current table with all row colors and filters preserved</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>If you're exporting more than <strong>5,000 rows</strong>, the system will warn you first. Apply date or plate filters to reduce the data before large exports.</span></div>
    </div>
    <hr class="help-divider">

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

    <div class="help-section" id="ranking">
      <div class="help-section-header"><span class="help-section-icon">📈</span><div class="help-section-title">Low → High &amp; High → Low Tabs</div></div>
      <div class="help-intro">Same as Overall Summary but <strong>pre-sorted by fuel consumption</strong>. <strong>Low → High</strong> shows the most fuel-efficient trucks first. <strong>High → Low</strong> shows the heaviest consumers first.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use <strong>High → Low</strong> at the end of each month to quickly identify trucks that may need inspection or driver coaching.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="30day">
      <div class="help-section-header"><span class="help-section-icon">📅</span><div class="help-section-title">30-Day Monitor Tab</div></div>
      <div class="help-intro">An <strong>attendance record for refueling</strong>. For every truck scheduled to operate, this shows how many days it actually got refueled vs. how many days it didn't. The <strong>Coverage bar</strong> shows the percentage — the higher, the better.</div>
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
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>A truck consistently showing <strong>"50% higher"</strong> uses 50% more fuel than similar trucks in the same area. This could indicate a mechanical issue, route inefficiency, or fuel misuse worth investigating.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="anomaly">
      <div class="help-section-header"><span class="help-section-icon">🚨</span><div class="help-section-title">Anomaly Flags Tab</div></div>
      <div class="help-intro">The <strong>alarm system</strong> of the dashboard. It automatically detects individual refuel transactions that look suspicious. Each flagged truck gets its own <strong>card</strong> showing all its suspicious records for easy review.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>A flag does <strong>not</strong> automatically mean fraud. It means <strong>"this looks unusual and should be reviewed."</strong> Always check the driver, invoice, and area before drawing conclusions.</span></div>
      <p style="font-size:.82rem;color:var(--text-secondary);margin-bottom:.6rem;font-weight:600;">Flag severity levels:</p>
      <div style="display:flex;flex-direction:column;gap:.45rem;margin-bottom:1rem;">
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(239,68,68,.12);color:#b91c1c;border:1px solid rgba(239,68,68,.3);border-radius:20px;padding:.15rem .55rem;font-size:.72rem;font-weight:700;">🔴 Critical</span><span style="font-size:.82rem;color:var(--text-secondary);">More than <strong>100% above</strong> the truck's own normal OR more than <strong>200% above</strong> similar trucks</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(245,158,11,.12);color:#b45309;border:1px solid rgba(245,158,11,.3);border-radius:20px;padding:.15rem .55rem;font-size:.72rem;font-weight:700;">🟠 High</span><span style="font-size:.82rem;color:var(--text-secondary);"><strong>50–100% above</strong> the truck's own normal OR <strong>100–200% above</strong> area average</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(234,179,8,.12);color:#854d0e;border:1px solid rgba(234,179,8,.3);border-radius:20px;padding:.15rem .55rem;font-size:.72rem;font-weight:700;">🟡 Watch</span><span style="font-size:.82rem;color:var(--text-secondary);">Slightly unusual — worth monitoring but not immediately alarming</span></div>
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
    </div>
    <hr class="help-divider">

    <div class="help-section" id="checklist">
      <div class="help-section-header"><span class="help-section-icon">✅</span><div class="help-section-title">Monthly Checklist Tab</div></div>
      <div class="help-intro">A <strong>daily checklist</strong> of every scheduled truck. <span style="color:var(--green,#10b981);font-weight:700;">Green rows = refueled ✅</span>, <span style="color:#ef4444;font-weight:700;">Red rows = not refueled ❌</span>. Results are paginated and a summary count of refueled vs. not refueled is shown above the table.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>This tab does <strong>not load automatically</strong>. Apply at least a <strong>date range</strong>, <strong>vehicle type</strong>, or <strong>plate</strong> filter first. A maximum of <strong>500 rows</strong> are returned per query.</span></div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Day</td><td>Day number of the month (e.g. 1, 2, 3...)</td></tr>
          <tr><td>Date</td><td>Full date of the schedule</td></tr>
          <tr><td>Fuel Time</td><td>The exact time the refuel was recorded (only shows if refueled that day)</td></tr>
          <tr><td>Plate #</td><td>The truck that was scheduled</td></tr>
          <tr><td>Department</td><td>Which department the truck belongs to</td></tr>
          <tr><td>Vehicle Type</td><td>What type of truck it is</td></tr>
          <tr><td>Sched. Driver</td><td>The driver assigned on this date per the team schedule</td></tr>
          <tr><td>Sched. Area</td><td>The area this truck was scheduled to serve</td></tr>
          <tr><td>Driver</td><td>The driver who actually requested the fuel (may differ from Sched. Driver)</td></tr>
          <tr><td>INV #</td><td>The fuel invoice/receipt number (only shows if refueled)</td></tr>
          <tr><td>Liters</td><td>How much fuel was received (only shows if refueled)</td></tr>
          <tr><td>Amount</td><td>How much it cost (only shows if refueled)</td></tr>
          <tr><td>Status</td><td>✅ Refueled or ❌ Not Refueled</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="consumption">
      <div class="help-section-header"><span class="help-section-icon">📆</span><div class="help-section-title">Fuel Consumption Tab</div></div>
      <div class="help-intro">A <strong>monthly breakdown of fuel consumption per truck</strong>, split into weekly columns. See how each vehicle's fuel usage is distributed across Week 1–5 of the selected month. Rows are grouped by <strong>Department → Vehicle Type</strong>.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>month/year selector</strong> at the top of this tab to navigate to any past or current month using the prev/next arrows. This tab uses its own <code>?fc_year=</code> / <code>?fc_month=</code> parameters, independently of the main date-range filter.</span></div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate #</td><td>The truck's plate number</td></tr>
          <tr><td>Department</td><td>Which department this truck belongs to</td></tr>
          <tr><td>Vehicle Type</td><td>What category of truck it is</td></tr>
          <tr><td>Total Refuels</td><td>How many times the truck was refueled across the entire selected month</td></tr>
          <tr><td>Total Liters</td><td>Total fuel consumed for the month</td></tr>
          <tr><td>Total Amount</td><td>Total money spent on fuel for the month</td></tr>
          <tr><td>Week 1–5 (Liters)</td><td>Liters consumed per weekly period (W1 = days 1–7, W2 = 8–14, W3 = 15–21, W4 = 22–28, W5 = 29–end)</td></tr>
          <tr><td>Week 1–5 (Amount)</td><td>Money spent per weekly period</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The table includes <strong>department subtotals</strong> and a <strong>grand total row</strong> so you can instantly compare departments and see the overall monthly picture.</span></div>
    </div>
    <hr class="help-divider">

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

    <div class="help-section" id="graphs-overview">
      <div class="help-section-header"><span class="help-section-icon">📈</span><div class="help-section-title">Fuel Graphs Page — Overview</div></div>
      <div class="help-intro">The <strong>Graphs page</strong> shows the same fuel data in <strong>visual chart form</strong>. Instead of reading numbers in a table, you see trends, comparisons, and distributions at a glance. All the same filters apply here too.</div>
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
        <div class="graph-card-desc">A stacked bar chart. <span style="color:#10b981;font-weight:700;">Green = days refueled</span>, <span style="color:#ef4444;font-weight:700;">Red = days not refueled</span>. The taller the green portion, the more consistently the truck is refueled. Hover to see counts and coverage percentage.</div>
      </div>
    </div>

  </main>
</div>
<div class="footer">Logistics Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'quickstart':'started','filters':'started','export':'started',
  'summary':'fuel','ranking':'fuel','30day':'fuel','area':'fuel','comparison':'fuel',
  'anomaly':'fuel','checklist':'fuel','consumption':'fuel','report':'fuel',
  'graphs-overview':'graphs','graph-consumption':'graphs','graph-trend':'graphs',
  'graph-area':'graphs','graph-vtype':'graphs','graph-top10':'graphs','graph-status':'graphs',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'started');});
</script>
</body>
</html>
