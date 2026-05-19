<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('delivery_remittance') && !rbac_can('ar_remittance') && !rbac_can('invoice_monitoring')) {
    header('Location: ' . base_url('dashboard.php')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Finance · Tradewell</title>
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
.hn-group-toggle .toggle-caret{font-size:.6rem;transition:transform .2s;flex-shrink:0}
.hn-group-toggle.open .toggle-caret{transform:rotate(180deg)}
.hn-group-body{overflow:hidden;max-height:0;transition:max-height .22s ease;padding-left:.25rem}
.hn-group-body.open{max-height:900px}
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
.color-legend{display:flex;flex-direction:column;gap:.45rem;margin-bottom:1rem}
.color-swatch{display:flex;align-items:center;gap:.75rem;font-size:.84rem;color:var(--text-primary)}
.swatch-box{width:18px;height:18px;border-radius:4px;border:1px solid;flex-shrink:0}
.tab-card{background:var(--surface-3);border:1px solid var(--border);border-radius:var(--radius);padding:.85rem 1.1rem;margin-bottom:.65rem}
.tab-card-title{font-weight:700;font-size:.9rem;color:var(--text-primary);margin-bottom:.3rem}
.tab-card-desc{font-size:.85rem;color:var(--text-secondary);line-height:1.65}
.help-divider{border:none;border-top:1px solid var(--border);margin:2rem 0}
.footer{text-align:center;padding:1.5rem;font-size:.75rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:1rem}
@media(max-width:900px){.help-layout{flex-direction:column;padding:1rem}.help-sidebar{width:100%;position:static}.filter-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="help-layout">
  <nav class="help-sidebar">
    <div class="hn-title">📖 Finance Help</div>
    <div class="hn-group" data-group="overview">
      <button class="hn-group-toggle" onclick="toggleGroup('overview')"><span>📋 Overview</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-overview">
        <a href="#intro"     class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#statcards" class="hn-link"><i class="bi bi-bar-chart"></i> Stat Cards</a>
        <a href="#filters"   class="hn-link"><i class="bi bi-funnel"></i> Using Filters</a>
        <a href="#export"    class="hn-link"><i class="bi bi-download"></i> Export &amp; Print</a>
      </div>
    </div>
    <div class="hn-group" data-group="tabs">
      <button class="hn-group-toggle" onclick="toggleGroup('tabs')"><span>📑 Tabs</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-tabs">
        <a href="#tab-summary"    class="hn-link"><i class="bi bi-grid-3x3-gap"></i> Summary</a>
        <a href="#tab-pending"    class="hn-link"><i class="bi bi-hourglass-split"></i> Pending</a>
        <a href="#tab-delivered"  class="hn-link"><i class="bi bi-truck"></i> Delivered</a>
        <a href="#tab-unremitted" class="hn-link"><i class="bi bi-exclamation-triangle"></i> Unremitted</a>
        <a href="#tab-remitted"   class="hn-link"><i class="bi bi-send"></i> Remitted</a>
        <a href="#tab-received"   class="hn-link"><i class="bi bi-bank"></i> Received</a>
        <a href="#tab-unserved"   class="hn-link"><i class="bi bi-x-circle"></i> Unserved</a>
        <a href="#tab-shorts"     class="hn-link"><i class="bi bi-graph-down-arrow"></i> Shorts</a>
        <a href="#tab-leadman"    class="hn-link"><i class="bi bi-people-fill"></i> By Leadman</a>
        <a href="#tab-salesman"   class="hn-link"><i class="bi bi-person-lines-fill"></i> By Salesman</a>
      </div>
    </div>
    <div class="hn-group" data-group="flow">
      <button class="hn-group-toggle" onclick="toggleGroup('flow')"><span>🔄 Remittance Flow</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-flow">
        <a href="#flow-overview" class="hn-link"><i class="bi bi-diagram-3"></i> Full Flow</a>
        <a href="#flow-statuses" class="hn-link"><i class="bi bi-tag"></i> Statuses Explained</a>
        <a href="#flow-shorts"   class="hn-link"><i class="bi bi-graph-down-arrow"></i> What Are Shorts?</a>
      </div>
    </div>
    <div class="hn-group" data-group="inv">
      <button class="hn-group-toggle" onclick="toggleGroup('inv')"><span>🔎 Invoice Monitor</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-inv">
        <a href="#inv-intro"    class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#inv-search"   class="hn-link"><i class="bi bi-search"></i> How to Search</a>
        <a href="#inv-flow"     class="hn-link"><i class="bi bi-diagram-3"></i> Flow Indicator</a>
        <a href="#inv-summary"  class="hn-link"><i class="bi bi-card-text"></i> Summary Bar</a>
        <a href="#inv-s1"       class="hn-link"><i class="bi bi-file-earmark-text"></i> Section 1 — Invoice</a>
        <a href="#inv-s2"       class="hn-link"><i class="bi bi-truck"></i> Section 2 — Delivery</a>
        <a href="#inv-s3"       class="hn-link"><i class="bi bi-receipt"></i> Section 3 — AR Created</a>
        <a href="#inv-s4"       class="hn-link"><i class="bi bi-bank"></i> Section 4 — AR Collection</a>
        <a href="#inv-print"    class="hn-link"><i class="bi bi-printer"></i> Print Button</a>
      </div>
    </div>
    <div class="hn-group" data-group="ar">
      <button class="hn-group-toggle" onclick="toggleGroup('ar')"><span>💳 AR Remittance</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-ar">
        <a href="#ar-intro"     class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#ar-statcards" class="hn-link"><i class="bi bi-bar-chart"></i> Stat Cards</a>
        <a href="#ar-filters"   class="hn-link"><i class="bi bi-funnel"></i> Using Filters</a>
        <a href="#ar-tabs"      class="hn-link"><i class="bi bi-journals"></i> Tabs Overview</a>
        <a href="#ar-export"    class="hn-link"><i class="bi bi-download"></i> Export &amp; Print</a>
        <a href="#ar-flow"      class="hn-link"><i class="bi bi-diagram-3"></i> AR Flow</a>
      </div>
    </div>
  </nav>
  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Finance <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to tracking delivery documents from creation all the way to Finance receipt — remittance status, shorts monitoring, and leadman/salesman breakdowns.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Delivery Docs</span>
        <span class="help-chip"><i class="bi bi-cash-stack"></i> Remittance</span>
        <span class="help-chip"><i class="bi bi-bank"></i> Finance Receipt</span>
        <span class="help-chip"><i class="bi bi-graph-down-arrow"></i> Shorts Tracking</span>
        <span class="help-chip"><i class="bi bi-people"></i> By Leadman &amp; Salesman</span>
      </div>
    </div>

    <div class="help-section" id="intro">
      <div class="help-section-header"><span class="help-section-icon">🚀</span><div class="help-section-title">What is This Page?</div></div>
      <div class="help-intro">The <strong>Delivery Remittance Dashboard</strong> tracks every delivery document — from creation, through delivery and remittance by the leadman, all the way to confirmation by Finance. Think of it as a <strong>live status board for all delivery collections</strong>.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Data defaults to the <strong>current month</strong>. Use the filters at the top to change the date range or narrow down by branch, area, salesman, or remitter.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">The <strong>stat cards</strong> at the top give a quick summary — total docs, pending, delivered, unremitted, remitted, received, and shorts.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Use the <strong>tabs</strong> to switch between different views of the same data. Each tab focuses on a specific stage of the delivery-remittance flow.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click any column header to <strong>sort</strong> the table. Use the <strong>search box</strong> to find a specific doc number, salesman, or area.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Export any tab's full data using the <strong>CSV</strong>, <strong>Excel</strong>, or <strong>Print</strong> buttons.</div></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="statcards">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Stat Cards</div></div>
      <div class="help-intro">The stat cards give a <strong>live count and total</strong> for the current filter period. Each card is also a <strong>shortcut</strong> — clicking it jumps to the relevant tab.</div>
      <table class="col-table">
        <thead><tr><th>Card</th><th>What it shows</th></tr></thead>
        <tbody>
          <tr><td>Period Overview</td><td>Total delivery documents and total net amount for the selected period, plus count of unique salesmen</td></tr>
          <tr><td>Created</td><td>Documents created but not yet delivered — still awaiting delivery confirmation</td></tr>
          <tr><td>Delivered</td><td>Documents where delivery has been confirmed (includes already-remitted ones)</td></tr>
          <tr><td>Unremitted</td><td>Documents delivered but not yet remitted to the leadman</td></tr>
          <tr><td>Remit Pending</td><td>Documents remitted by the leadman but not yet confirmed by Finance</td></tr>
          <tr><td>Received</td><td>Documents where Finance has confirmed receipt of the remittance</td></tr>
          <tr><td>Unserved</td><td>Delivery documents that were cancelled or could not be served</td></tr>
          <tr><td>Unsettled Shorts</td><td>Remittances where amount remitted is less than expected (difference &gt; ₱2). Shows total peso short and count of already-settled shorts.</td></tr>
          <tr><td>By Leadman</td><td>Number of unique leadmen active in this period, with total remitted and pending/received counts</td></tr>
          <tr><td>Active Salesmen</td><td>Count of unique salesmen with delivery docs in the selected period</td></tr>
          <tr><td>Total Net Amount</td><td>Grand total of net amounts across all delivery docs, plus total amount remitted</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>All stat cards automatically update when you change filters and always reflect the <strong>entire filtered dataset</strong>, not just the current page.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="filters">
      <div class="help-section-header"><span class="help-section-icon">🔍</span><div class="help-section-title">Using Filters</div></div>
      <div class="help-intro">The filter panel is <strong>collapsible</strong> — click the header to open or close it. When filters are active, a purple <strong>"Active"</strong> badge appears and filter tags show in the header even when collapsed. Click <strong>Apply Filters</strong> to run, or <strong>Reset</strong> to clear all.</div>
      <div class="filter-grid">
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-calendar3"></i> Date From / To</div><div class="filter-item-desc">Narrow data to a specific date range. Defaults to the current month when left blank.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-geo-alt"></i> Branch</div><div class="filter-item-desc">Filter by branch — Quezon, Quezon Upper, or Marinduque.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-map"></i> Area</div><div class="filter-item-desc">Filter by delivery area. Dropdown is populated from data matching the current department and branch.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-person"></i> Salesman</div><div class="filter-item-desc">Filter to show only delivery documents for a specific salesman code.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-person-check"></i> Remitted By</div><div class="filter-item-desc">Filter by the leadman who submitted the remittance. Only affects the Remitted, Received, Shorts, and By Leadman tabs.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-building"></i> Department (Auto)</div><div class="filter-item-desc">Automatically applied from your login session. Switch departments via the topbar avatar menu if you have permission.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Active filters appear as <strong>colored tags</strong> in the filter bar header — date = blue, branch = purple, area = yellow, salesman = green, remitted by = orange. See which filters are on without opening the panel.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="export">
      <div class="help-section-header"><span class="help-section-icon">💾</span><div class="help-section-title">Export &amp; Print</div></div>
      <div class="help-intro">Every tab has three export buttons in the top right of the table section. All exports use the <strong>full filtered dataset</strong> — not just the 20 rows visible on the current page.</div>
      <table class="col-table">
        <thead><tr><th>Button</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>CSV</td><td>Downloads a plain text file (.csv) that can be opened in Excel or Google Sheets</td></tr>
          <tr><td>Excel</td><td>Downloads a formatted Excel file (.xlsx) with all columns and data ready for reporting</td></tr>
          <tr><td>Print</td><td>Opens a new tab with a clean, print-ready version of the table. Use Ctrl+P / Cmd+P to print or save as PDF.</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The <strong>search box</strong> filters what you see on screen but does <strong>not</strong> affect exports — exports always contain all rows matching the current filter panel.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-summary">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">Summary Tab</div></div>
      <div class="help-intro">The <strong>main overview</strong> of all delivery documents for the selected period. Every document appears here regardless of status. Use the <strong>toggle buttons</strong> (All / Created / Delivered / Remitted) in the table header to filter by status without touching the filter panel.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>The unique delivery document number</td></tr>
          <tr><td>Branch</td><td>Which branch this document belongs to (color-coded badge)</td></tr>
          <tr><td>Department</td><td>The department/principal (e.g. Monde, Century, Nutriasia)</td></tr>
          <tr><td>Doc Date</td><td>The date the delivery document was created</td></tr>
          <tr><td>Salesman</td><td>The salesman code and name assigned to this delivery</td></tr>
          <tr><td>Area</td><td>The delivery area or route</td></tr>
          <tr><td>Calls</td><td>Number of customer calls/stops in this delivery</td></tr>
          <tr><td>Cases</td><td>Number of cases delivered</td></tr>
          <tr><td>Net Amt</td><td>The net amount of this delivery document</td></tr>
          <tr><td>Status</td><td>Current status — Created, Delivered, Remitted, etc.</td></tr>
          <tr><td>Days Old</td><td>How many days ago the document was created. Red = 6+ days, Orange = 3–5 days, Green = 0–2 days. Only shown for Created-status documents.</td></tr>
          <tr><td>Remarks</td><td>Any notes attached to the document</td></tr>
          <tr><td>Encoded By</td><td>The user who created the document</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Rows highlighted in <strong>red</strong> are Created documents 6+ days old — follow up. <strong>Yellow</strong> rows are 3–5 days old.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-pending">
      <div class="help-section-header"><span class="help-section-icon">🕐</span><div class="help-section-title">Pending Tab</div></div>
      <div class="help-intro">Shows delivery documents that have been <strong>created but not yet delivered</strong>. The same columns as the Summary tab apply here.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>A document stuck in Pending for many days usually means the delivery confirmation hasn't been encoded yet — coordinate with the Logistics team.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-delivered">
      <div class="help-section-header"><span class="help-section-icon">✅</span><div class="help-section-title">Delivered Tab</div></div>
      <div class="help-intro">Shows all documents where <strong>delivery has been confirmed</strong>. This includes documents already remitted — it is not limited to unremitted docs.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>Delivery document number</td></tr>
          <tr><td>Branch / Department</td><td>Branch and department badges</td></tr>
          <tr><td>Doc Date</td><td>Date the document was created</td></tr>
          <tr><td>Salesman</td><td>Salesman code and name</td></tr>
          <tr><td>Area</td><td>Delivery area</td></tr>
          <tr><td>Calls / Cases</td><td>Number of customer stops and cases</td></tr>
          <tr><td>Net Amt</td><td>Net amount of the delivery</td></tr>
          <tr><td>Plate No</td><td>The truck plate number that made the delivery</td></tr>
          <tr><td>Schedule Date</td><td>The date the delivery was scheduled</td></tr>
          <tr><td>Status</td><td>Delivery status (e.g. Delivered, Received)</td></tr>
          <tr><td>Del Remarks</td><td>Delivery remarks entered at the time of confirmation</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-unremitted">
      <div class="help-section-header"><span class="help-section-icon">⚠️</span><div class="help-section-title">Unremitted Tab</div></div>
      <div class="help-intro">Shows documents that have been <strong>delivered but not yet remitted</strong> by the leadman. Sorted oldest first so the most urgent ones appear at the top.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>Delivery document number</td></tr>
          <tr><td>Branch / Department</td><td>Branch and department badges</td></tr>
          <tr><td>Doc Date</td><td>Date of the delivery document</td></tr>
          <tr><td>Salesman</td><td>Salesman code and name</td></tr>
          <tr><td>Area</td><td>Delivery area</td></tr>
          <tr><td>Calls / Cases</td><td>Number of stops and cases</td></tr>
          <tr><td>Net Amt</td><td>Net amount to be remitted</td></tr>
          <tr><td>Days Old</td><td>How many days since the document date — used for urgency color coding</td></tr>
          <tr><td>Status</td><td>Current document status</td></tr>
          <tr><td>Remarks</td><td>Document remarks</td></tr>
        </tbody>
      </table>
      <div class="color-legend">
        <div class="color-swatch"><div class="swatch-box" style="background:#fee2e2;border-color:#f87171;"></div><span><strong>Red</strong> — 3 or more days old. Needs immediate attention.</span></div>
        <div class="color-swatch"><div class="swatch-box" style="background:#fef9c3;border-color:#fde047;"></div><span><strong>Yellow</strong> — 1–2 days old. Worth monitoring.</span></div>
        <div class="color-swatch"><div class="swatch-box" style="background:#fff;border-color:#e5e7eb;"></div><span><strong>No highlight</strong> — Same day. Recently delivered.</span></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-remitted">
      <div class="help-section-header"><span class="help-section-icon">💸</span><div class="help-section-title">Remitted Tab</div></div>
      <div class="help-intro">Shows documents where the leadman has <strong>submitted the remittance but Finance has not yet confirmed receipt</strong>. These are "in transit" between the leadman and the Finance team.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>Delivery document number</td></tr>
          <tr><td>Branch / Department</td><td>Branch and department badges</td></tr>
          <tr><td>Doc Date</td><td>Date of the delivery document</td></tr>
          <tr><td>Salesman</td><td>Salesman code</td></tr>
          <tr><td>Area</td><td>Delivery area</td></tr>
          <tr><td>Net Amt</td><td>Expected net amount</td></tr>
          <tr><td>Cash</td><td>Cash portion of the remittance</td></tr>
          <tr><td>Check</td><td>Check portion of the remittance</td></tr>
          <tr><td>Credit</td><td>Credit/charge portion of the remittance</td></tr>
          <tr><td>Total Remit</td><td>Total amount actually remitted (Cash + Check + Credit)</td></tr>
          <tr><td>Difference</td><td>Variance between Net Amount and Total Remit. ▲ green = over, ▼ red = short.</td></tr>
          <tr><td>Remitted By</td><td>The leadman who submitted this remittance</td></tr>
          <tr><td>Remarks</td><td>Remittance remarks entered by the leadman</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Rows with a <strong>negative difference</strong> (highlighted red) mean the leadman remitted less than the expected net amount — this may be a short that needs resolution.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-received">
      <div class="help-section-header"><span class="help-section-icon">🏦</span><div class="help-section-title">Received Tab</div></div>
      <div class="help-intro">Shows documents where <strong>Finance has confirmed receipt</strong> of the remittance. These are fully closed — the money is in. Same columns as the Remitted tab, plus:</div>
      <table class="col-table">
        <thead><tr><th>Additional Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>RRID</td><td>The Finance receipt/remittance record ID assigned when Finance confirms receipt. Shown as a green badge.</td></tr>
          <tr><td>Date Remit</td><td>The date Finance recorded the receipt of this remittance</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-unserved">
      <div class="help-section-header"><span class="help-section-icon">🚫</span><div class="help-section-title">Unserved Tab</div></div>
      <div class="help-intro">Shows delivery documents that were <strong>cancelled or could not be served</strong> — customers who refused delivery, out-of-stock items, or route issues.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>Delivery document number</td></tr>
          <tr><td>Branch / Department</td><td>Branch and department badges</td></tr>
          <tr><td>Doc Date</td><td>Date of the original delivery document</td></tr>
          <tr><td>Salesman</td><td>Salesman code and name</td></tr>
          <tr><td>Area</td><td>Delivery area</td></tr>
          <tr><td>Calls / Cases</td><td>Number of stops and cases</td></tr>
          <tr><td>Net Amt</td><td>Net amount of the unserved document</td></tr>
          <tr><td>Invoice No</td><td>The invoice number associated with the unserved delivery</td></tr>
          <tr><td>Customer</td><td>The customer who did not receive the delivery</td></tr>
          <tr><td>Cancelled Date</td><td>When the document was cancelled/marked unserved</td></tr>
          <tr><td>Note</td><td>Reason or note for why the delivery was unserved</td></tr>
          <tr><td>Remarks</td><td>Additional remarks</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-shorts">
      <div class="help-section-header"><span class="help-section-icon">📉</span><div class="help-section-title">Shorts Tab</div></div>
      <div class="help-intro">Shows remittances where the <strong>amount remitted is less than expected</strong> (difference &gt; ₱2). Use the <strong>Unsettled / Settled toggle</strong> in the table header to switch between outstanding and resolved shorts.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>Delivery document number</td></tr>
          <tr><td>Branch / Department</td><td>Branch and department badges</td></tr>
          <tr><td>Doc Date</td><td>Date of the delivery document</td></tr>
          <tr><td>Salesman</td><td>Salesman code</td></tr>
          <tr><td>Area</td><td>Delivery area</td></tr>
          <tr><td>Net Amt</td><td>Expected net amount</td></tr>
          <tr><td>Cash / Check / Credit</td><td>Breakdown of what was remitted</td></tr>
          <tr><td>Cancelled</td><td>Amount cancelled from this document</td></tr>
          <tr><td>Total Remit</td><td>Total amount actually submitted</td></tr>
          <tr><td>Short (Diff)</td><td>The peso amount that is short — always shown in red with ▼</td></tr>
          <tr><td>Remitted By</td><td>The leadman who submitted this remittance</td></tr>
          <tr><td>Date Remit</td><td>When this remittance was submitted</td></tr>
          <tr><td>Settlement</td><td><strong>Settled</strong> (green) = short resolved with payment ref · <strong>Unsettled</strong> (red) = still outstanding</td></tr>
          <tr><td>Remarks</td><td>Remittance remarks</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The <strong>Unsettled Shorts</strong> stat card counts only unresolved shorts. Once settled, the short moves to the Settled view and no longer counts.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-leadman">
      <div class="help-section-header"><span class="help-section-icon">👥</span><div class="help-section-title">By Leadman Tab</div></div>
      <div class="help-intro">Groups all remittance activity <strong>by leadman/remitter</strong>. Shows how much each leadman collected, how many are pending receipt by Finance, and whether they have outstanding shorts.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Leadman / Remitter</td><td>Name of the leadman who submitted the remittances</td></tr>
          <tr><td>Remittances</td><td>Total delivery documents remitted by this leadman in the period</td></tr>
          <tr><td>Unreceived</td><td>Documents remitted but Finance has not confirmed receipt. <strong>Click the number</strong> to see the full list.</td></tr>
          <tr><td>Received</td><td>Documents where Finance has confirmed receipt</td></tr>
          <tr><td>Total Net Amt</td><td>Total expected net amount across all this leadman's remittances</td></tr>
          <tr><td>Cash / Check / Credit</td><td>Breakdown of how collections were tendered</td></tr>
          <tr><td>Cancelled</td><td>Total cancelled amounts</td></tr>
          <tr><td>Total Remitted</td><td>Total amount actually submitted by this leadman</td></tr>
          <tr><td>Net Diff</td><td>Overall difference between net amount and total remitted. ▲ green = over, ▼ red = short.</td></tr>
          <tr><td>Shorts</td><td>Count of unresolved short remittances — shown as a red pill if any exist</td></tr>
          <tr><td>Total Short Amt</td><td>Total peso amount outstanding from shorts for this leadman</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span><strong>Unreceived count is clickable.</strong> Opens a detail modal showing every unreceived document for that leadman, including the full Cash/Check/Credit breakdown and Difference.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Rows with a red left border have <strong>unsettled shorts</strong>. Follow up with the respective leadman.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tab-salesman">
      <div class="help-section-header"><span class="help-section-icon">🧑‍💼</span><div class="help-section-title">By Salesman Tab</div></div>
      <div class="help-intro">Groups delivery activity <strong>by salesman</strong>. Useful for comparing performance — total deliveries, net amounts, and how many are still pending vs. delivered.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Salesman Code</td><td>The salesman's code identifier</td></tr>
          <tr><td>Salesman</td><td>Full salesman name</td></tr>
          <tr><td>Area</td><td>Primary delivery area</td></tr>
          <tr><td>Branch</td><td>Branch the salesman belongs to</td></tr>
          <tr><td>Deliveries</td><td>Total number of delivery documents for this salesman in the period</td></tr>
          <tr><td>Total Calls</td><td>Total number of customer calls/stops</td></tr>
          <tr><td>Total Cases</td><td>Total number of cases delivered</td></tr>
          <tr><td>Total Net Amt</td><td>Total net amount across all deliveries — sorted highest first by default</td></tr>
          <tr><td>Avg Net Amt</td><td>Average net amount per delivery</td></tr>
          <tr><td>Pending</td><td>Count of documents not yet delivery-confirmed (yellow)</td></tr>
          <tr><td>Delivered</td><td>Count of documents with confirmed delivery (green)</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="flow-overview">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">Full Remittance Flow</div></div>
      <div class="help-intro">Every delivery document follows this lifecycle from creation to Finance receipt.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text"><strong>Created</strong> — A delivery document is encoded by the sales team. It appears in the Pending tab.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text"><strong>Delivered</strong> — Logistics confirms the delivery. The document moves to the Delivered tab and is no longer Pending.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text"><strong>Remitted</strong> — The leadman collects cash/check/credit from the salesman and submits it. Appears in the Remitted tab (Pending Receipt by Finance).</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text"><strong>Received</strong> — Finance confirms receipt and assigns an RRID. The document moves to the Received tab — fully closed.</div></div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">🚫 Unserved — Parallel path</div>
        <div class="tab-card-desc">Some delivery documents never get delivered — customer refused, items unavailable, or route couldn't be served. These become <strong>Unserved</strong> and appear in the Unserved tab. They do not go through the remittance flow.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">📉 Shorts — Exception path</div>
        <div class="tab-card-desc">If a leadman remits less than expected (difference &gt; ₱2), a <strong>Short</strong> is recorded. The document still proceeds through Remitted → Received, but the short must be resolved separately — by additional payment (Settled) or written off.</div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="flow-statuses">
      <div class="help-section-header"><span class="help-section-icon">🏷️</span><div class="help-section-title">Statuses Explained</div></div>
      <div class="help-intro">Delivery document statuses appear as color-coded badges throughout the dashboard.</div>
      <table class="col-table">
        <thead><tr><th>Status</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>CREATED</td><td>Document is created and waiting for delivery confirmation. Shows in Pending tab.</td></tr>
          <tr><td>DELIVERED</td><td>Delivery has been confirmed by Logistics. Shows in Delivered tab.</td></tr>
          <tr><td>REMITTED</td><td>Leadman has submitted the collection. Shows in Remitted tab.</td></tr>
          <tr><td>CANCELLED</td><td>Document was cancelled — appears in Unserved tab.</td></tr>
          <tr><td>RECEIVED</td><td>Document has been received and finalized in the system.</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="flow-shorts">
      <div class="help-section-header"><span class="help-section-icon">📉</span><div class="help-section-title">What Are Shorts?</div></div>
      <div class="help-intro">A <strong>short</strong> occurs when the total amount remitted by the leadman is <strong>less than the expected net amount</strong> by more than ₱2. For example: net amount ₱10,500, leadman remits ₱10,200 → short is ₱300.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">The short is automatically detected when the remittance is recorded — no manual flagging needed.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">It appears in the <strong>Shorts tab</strong> under <strong>Unsettled</strong> and is counted in the <strong>Unsettled Shorts</strong> stat card.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Once the leadman covers the shortage (recorded as a payment with a PaymentID), the short is marked <strong>Settled</strong> (green badge) and moves to the Settled view.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Settled shorts no longer appear in the Unsettled Shorts stat card count.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>The <strong>By Leadman tab</strong> shows a per-leadman total of unsettled shorts — useful for identifying which leadmen have recurring shortage issues.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The ₱2 threshold exists to ignore minor rounding differences. Only differences greater than ₱2 are flagged as shorts.</span></div>
    </div>

  </main>
</div>

<!-- ═══════════════════ AR REMITTANCE SECTIONS ═══════════════════ -->
<div class="help-layout" style="padding-top:0;">
  <div class="help-sidebar" style="visibility:hidden;pointer-events:none;"></div>
  <main class="help-main">

    <hr class="help-divider" style="margin-top:0;">

    <div class="help-hero" id="ar-intro" style="scroll-margin-top:80px;">
      <div class="help-hero-title">AR Remittance <span>Dashboard</span></div>
      <div class="help-hero-sub">Tracks accounts-receivable collections from the point an AR is created all the way through collection, remittance, and Finance receipt — with drill-down modals per record.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-receipt"></i> AR Creation</span>
        <span class="help-chip"><i class="bi bi-send"></i> Collection</span>
        <span class="help-chip"><i class="bi bi-bank"></i> Finance Receipt</span>
        <span class="help-chip"><i class="bi bi-exclamation-circle"></i> Uncollected</span>
        <span class="help-chip"><i class="bi bi-download"></i> Full Export</span>
      </div>
    </div>

    <div class="help-section" id="ar-statcards">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Stat Cards</div></div>
      <div class="help-intro">Six stat cards sit at the top of the page. Each shows a <strong>count and total peso amount</strong> for the active filter period. Clicking a card jumps directly to that tab. Counts always match exactly what the corresponding tab shows — they are sourced from the same query, not a separate calculation.</div>
      <table class="col-table">
        <thead><tr><th>Card</th><th>What it shows</th></tr></thead>
        <tbody>
          <tr><td>Total Credit</td><td>Invoice lines pending AR creation — <code>CreditAmount &gt; ₱2</code> and <code>ARCreate = 0</code>. Grouped by Doc No.</td></tr>
          <tr><td>AR Created</td><td>AR For Collection records at Status 1 — created but not yet sent for collection.</td></tr>
          <tr><td>AR For Collection</td><td>Records at Status 2 — handed to the collector for field collection.</td></tr>
          <tr><td>Remitted</td><td>Records at Status 3 — collector has submitted, Finance not yet confirmed.</td></tr>
          <tr><td>Received</td><td>Records at Status 4 — Finance has confirmed and posted the receipt.</td></tr>
          <tr><td>Uncollected</td><td>Records at Status 5 or with a remaining balance &gt; ₱2 and <code>ARCreated = 0</code> — outstanding balances.</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Stat card counts always match the tab row count exactly — they are computed from the same query. Switching tabs updates the count for that tab in real time.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ar-filters">
      <div class="help-section-header"><span class="help-section-icon">🔍</span><div class="help-section-title">Using Filters</div></div>
      <div class="help-intro">The filter panel is collapsible. When filters are active, tags appear in the header even when collapsed. Hit <strong>Apply Filters</strong> to run or <strong>Reset</strong> to clear. The default date range is the <strong>last 7 days</strong>.</div>
      <div class="filter-grid">
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-calendar3"></i> Date From / To</div><div class="filter-item-desc">Filters by <strong>Doc Date</strong> for Total Credit, and by <strong>Delivery Date</strong> for all other tabs. Defaults to the last 7 days.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-diagram-3"></i> Branch</div><div class="filter-item-desc">Dropdown with the three valid branches: <strong>Quezon</strong>, <strong>Quezon Upper</strong>, and <strong>Marinduque</strong>. Works across all tabs — on AR views without a Branch column, the selected branch is automatically translated into its matching departments behind the scenes.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-person"></i> Salesman</div><div class="filter-item-desc">Populated <strong>live</strong> from the active tab's data source, scoped to the current date range, selected branch, and your session department. Only salesmen present in the currently visible dataset are listed — the options refresh automatically when you switch tabs or apply filters.</div></div>
        <div class="filter-item"><div class="filter-item-name"><i class="bi bi-map"></i> Area</div><div class="filter-item-desc">Same as Salesman — queried live from the active tab's view, scoped to the current date range, branch, and your department. The list reflects exactly what is in the current tab's data.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><strong>Department scoping is automatic.</strong> Your login session determines which department's data you see. The Salesman and Area dropdowns will only ever show names that belong to your department — no cross-department mixing.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>On the <strong>Received</strong> tab, the Salesman dropdown is disabled — the underlying view does not carry a Salesman column. Use <strong>Branch</strong> or <strong>Area</strong> to narrow down instead.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ar-tabs">
      <div class="help-section-header"><span class="help-section-icon">📑</span><div class="help-section-title">Tabs Overview</div></div>
      <div class="help-intro">Each tab shows a different stage of the AR collection lifecycle. The table is <strong>paginated at 20 rows per page</strong> — use the pagination controls to navigate. Clicking a row opens a detail modal with a full invoice-level breakdown.</div>
      <div class="tab-card">
        <div class="tab-card-title">💳 Total Credit</div>
        <div class="tab-card-desc">Invoice lines pending AR creation (<code>ARCreate = 0</code>). Grouped by Doc No with totals. Click a Doc No to drill into individual invoice lines — customer, credit amount, and days old.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">📋 AR Created</div>
        <div class="tab-card-desc">AR For Collection records at <strong>Status 1</strong>. Grouped by AFC ID. Shows invoice count, total amount, and days old. Click to open a detail modal with per-invoice breakdown including paid amount and balance.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">🗂️ AR For Collection</div>
        <div class="tab-card-desc">Records at <strong>Status 2</strong> — actively out for collection. Same columns as AR Created. Use this tab to monitor what collectors currently have in hand.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">📤 Remitted</div>
        <div class="tab-card-desc">Records at <strong>Status 3</strong>. Collector has submitted the collection but Finance has not yet posted it. Click the row to open the full payment breakdown: bank, check number, check date, cash amount, deductions, and balance.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">🏦 Received</div>
        <div class="tab-card-desc">Records at <strong>Status 4</strong> — Finance confirmed. Shows RRID, receiving date, total cash, total check, net amount, and the employee who received it.</div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">⚠️ Uncollected</div>
        <div class="tab-card-desc">Records with <strong>Status 5</strong> or a remaining <strong>Balance &gt; ₱2</strong> where <code>ARCreated = 0</code>. Sorted oldest delivery first to surface the most overdue accounts.</div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>The <strong>Days Old</strong> badge is color-coded — <span style="color:#059669;font-weight:700;">green</span> (≤ 7 days), <span style="color:#d97706;font-weight:700;">orange</span> (8–30 days), <span style="color:#ef4444;font-weight:700;">red</span> (&gt; 30 days).</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ar-export">
      <div class="help-section-header"><span class="help-section-icon">💾</span><div class="help-section-title">Export &amp; Print</div></div>
      <div class="help-intro">Every tab has <strong>CSV</strong>, <strong>Excel</strong>, and <strong>Print</strong> buttons. All three export the <strong>complete filtered dataset</strong> — every row matching your current date range and filters, not just the 20 rows visible on the current page.</div>
      <table class="col-table">
        <thead><tr><th>Button</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>CSV</td><td>Downloads a <code>.csv</code> file with all columns. Opens in Excel or Google Sheets.</td></tr>
          <tr><td>Excel</td><td>Downloads a formatted <code>.xlsx</code> file with the tab name as the sheet label.</td></tr>
          <tr><td>Print</td><td>Opens a clean print window with all rows. The header shows the active date range and total record count. Use Ctrl+P / Cmd+P to print or save as PDF.</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>The on-screen <strong>search box</strong> only filters what's visible — it does <strong>not</strong> affect exports. Exports always include every row for the active filter period.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Previously, exports were capped at 20 rows (the current page only). This has been fixed — exports now always deliver the full filtered dataset.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ar-flow">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">AR Collection Flow</div></div>
      <div class="help-intro">Every AR invoice follows this lifecycle from credit creation through Finance receipt.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text"><strong>Total Credit</strong> — Invoice is posted with a credit amount and appears in Total Credit until an AR For Collection record is created.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text"><strong>AR Created (Status 1)</strong> — The AR For Collection record is created. The invoice moves out of Total Credit into the AR Created tab.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text"><strong>AR For Collection (Status 2)</strong> — The record is handed to a collector for field collection.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text"><strong>Remitted (Status 3)</strong> — The collector submits the payment (cash, check, or both). Awaiting Finance confirmation.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text"><strong>Received (Status 4)</strong> — Finance confirms and posts the receipt. An RRID is assigned. Fully closed.</div></div>
      </div>
      <div class="tab-card">
        <div class="tab-card-title">⚠️ Uncollected — Exception path</div>
        <div class="tab-card-desc">If a collection attempt fails or a balance remains after remittance (Balance &gt; ₱2), the record appears in the <strong>Uncollected tab</strong> (Status 5). These need follow-up — either a new collection attempt or a write-off decision.</div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The ₱2 threshold filters out minor rounding differences — only genuine outstanding amounts are flagged.</span></div>
    </div>

  </main>
</div>

<!-- ═══════════════════ INVOICE MONITORING SECTIONS ═══════════════════ -->
<div class="help-layout" style="padding-top:0;">
  <div class="help-sidebar" style="visibility:hidden;pointer-events:none;"></div>
  <main class="help-main">

    <hr class="help-divider" style="margin-top:0;">

    <div class="help-hero" id="inv-intro" style="scroll-margin-top:80px;">
      <div class="help-hero-title">Invoice <span>Monitoring</span></div>
      <div class="help-hero-sub">Look up any invoice number and instantly see its full journey — from creation through delivery, AR creation, and final AR collection — all in one page with a live flow indicator.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Invoice Details</span>
        <span class="help-chip"><i class="bi bi-truck"></i> Delivery</span>
        <span class="help-chip"><i class="bi bi-receipt"></i> AR Created</span>
        <span class="help-chip"><i class="bi bi-bank"></i> AR Collection</span>
        <span class="help-chip"><i class="bi bi-printer"></i> Print</span>
      </div>
    </div>

    <div class="help-section" id="inv-search" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🔍</span><div class="help-section-title">How to Search</div></div>
      <div class="help-intro">Type an <strong>invoice number</strong> into the search box and hit <strong>Search</strong>. The page then loads all four sections below for that invoice. Invoice numbers are <strong>unique across all departments</strong> — you don't need to filter by department first.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Enter the invoice number in the search bar at the top (e.g. <strong>CE232055</strong>).</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click <strong>Search</strong>. The page refreshes and displays all available data for that invoice.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">The <strong>flow indicator</strong> at the top shows which stages have data — grey steps mean no record yet, green means the stage is complete.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Scroll through each section to review details. Use the <strong>Print</strong> button to generate a printable summary.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>If a section shows <strong>no data</strong>, it means that stage hasn't happened yet — the invoice hasn't been delivered, or AR hasn't been created, etc. It's not an error.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-flow" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">Flow Indicator</div></div>
      <div class="help-intro">Just below the search bar, a <strong>4-step flow bar</strong> shows the status of the searched invoice at a glance.</div>
      <table class="col-table">
        <thead><tr><th>Step</th><th>What it means</th><th>Color</th></tr></thead>
        <tbody>
          <tr><td>1. Invoice Created</td><td>The invoice exists in the system — record found in <code>View_Total_Invoice_Amount</code></td><td>🟢 Green when data exists</td></tr>
          <tr><td>2. Delivery</td><td>A delivery/remittance slip exists — record found in <code>View_RemittanceCollectionSlip</code></td><td>🟢 Green when data exists</td></tr>
          <tr><td>3. AR Created</td><td>An AR invoice record exists — record found in <code>View_ARInvoices</code></td><td>🟢 Green when data exists</td></tr>
          <tr><td>4. AR Collection</td><td>A collection record exists — record found in <code>View_ARForCollectionDetails</code></td><td>🟢 Green when data exists</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Steps turn <strong>green</strong> only when the corresponding section has at least one row of data. Grey means the stage is pending or hasn't occurred. The flow indicator doesn't predict future steps — it only reflects what's already in the database.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-summary" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">Summary Bar</div></div>
      <div class="help-intro">A quick-glance bar appears between the flow indicator and the detail sections. It shows the most important invoice facts without needing to scroll through the tables.</div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>Source</th><th>What it shows</th></tr></thead>
        <tbody>
          <tr><td>Customer</td><td>Section 1 (Invoice Created)</td><td>The customer name on the invoice</td></tr>
          <tr><td>Invoice Amount</td><td>Section 1 (Invoice Created)</td><td>Total net amount of the invoice</td></tr>
          <tr><td>Department</td><td>Section 1 (Invoice Created)</td><td>The department/principal the invoice belongs to</td></tr>
          <tr><td>Invoice Date</td><td>Section 1 (Invoice Created)</td><td>The date the invoice was created</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>The summary bar only appears if <strong>Section 1 has data</strong>. If the invoice number isn't found in the invoice view, the bar is hidden.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-s1" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">📄</span><div class="help-section-title">Section 1 — Invoice Created Details</div></div>
      <div class="help-intro">Pulled from <code>View_Total_Invoice_Amount</code>. Shows the raw invoice record — what was ordered, for whom, and how much.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Invoice No</td><td>The invoice number you searched for</td></tr>
          <tr><td>Customer</td><td>Customer name</td></tr>
          <tr><td>Department</td><td>The department/principal (e.g. Monde, Century, Nutriasia)</td></tr>
          <tr><td>Invoice Date</td><td>Date the invoice was created</td></tr>
          <tr><td>Net Amount</td><td>Total net amount on the invoice</td></tr>
          <tr><td>Remarks</td><td>Any remarks attached to the invoice at creation</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>This section has <strong>no department filter</strong> — invoice numbers are unique system-wide, so filtering by department was removed. All departments can look up any invoice.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-s2" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🚛</span><div class="help-section-title">Section 2 — Delivery Details</div></div>
      <div class="help-intro">Pulled from <code>View_RemittanceCollectionSlip</code>. Shows the delivery and remittance slip data linked to this invoice — when it was delivered and remitted.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Doc No</td><td>The delivery document number</td></tr>
          <tr><td>Invoice Date</td><td>Date on the remittance collection slip</td></tr>
          <tr><td>Remit Date</td><td>Date the remittance was submitted — sourced from <code>DocDate</code> on the slip (there is no dedicated remit date column in this view)</td></tr>
          <tr><td>Check Date</td><td>Date of any check payment submitted with this remittance</td></tr>
          <tr><td>Remitted By</td><td>The leadman who submitted the remittance (<code>RemitBy</code> / <code>RemittedBy</code>)</td></tr>
          <tr><td>Net Amount</td><td>Net amount on the delivery slip</td></tr>
          <tr><td>Cash / Check / Credit</td><td>Payment breakdown of the remittance</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><code>View_RemittanceCollectionSlip</code> does not have a dedicated remit date column. <strong>DocDate is used as the remit date</strong> — this is expected behavior, not a bug.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-s3" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🧾</span><div class="help-section-title">Section 3 — AR Created Details</div></div>
      <div class="help-intro">Pulled from <code>View_ARInvoices</code> (a join of <code>TBL_ARDetails</code> and <code>TBL_ARInformation</code> on <code>ARRefNo</code>). Shows the AR invoice record created for this invoice — when it was set up for accounts-receivable collection.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>AR Ref No</td><td>The AR reference number</td></tr>
          <tr><td>Customer</td><td>Customer on the AR record</td></tr>
          <tr><td>AR Date</td><td>Date the AR was created</td></tr>
          <tr><td>Due Date</td><td>Payment due date</td></tr>
          <tr><td>Credit Amount</td><td>Total credit amount on this AR record</td></tr>
          <tr><td>Paid Amount</td><td>Amount already paid against this AR</td></tr>
          <tr><td>Balance</td><td>Remaining balance (Credit Amount − Paid Amount)</td></tr>
          <tr><td>Status</td><td>Current AR status</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><code>View_ARInvoices</code> has <strong>no WHERE clause for department</strong> — it joins on <code>ARRefNo</code> across all departments. This is intentional; invoice numbers uniquely identify the record.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-s4" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🏦</span><div class="help-section-title">Section 4 — AR Collection Details</div></div>
      <div class="help-intro">Pulled from <code>View_ARForCollectionDetails</code> (a three-way join: <code>Tbl_ARForCollection</code> → <code>TBL_ARInformation</code> on <code>ForCollectionNo = ARForCollectionID</code> → <code>TBL_ARDetails</code> on <code>ARRefNo</code>). Shows the collection record — when it was collected, by whom, and how much was received.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>AR For Collection ID</td><td>The unique collection record ID (also used for the Print button — the first ID found is passed to the print view)</td></tr>
          <tr><td>Collection Date</td><td>Date the collection was made</td></tr>
          <tr><td>Remit Date</td><td>Date the collection was remitted — sourced from <code>DateAndTimeInput</code></td></tr>
          <tr><td>Collected By</td><td>The collector who processed this collection</td></tr>
          <tr><td>Cash Amount</td><td>Cash portion collected</td></tr>
          <tr><td>Check Amount</td><td>Check portion collected</td></tr>
          <tr><td>Deductions</td><td>Any deductions applied</td></tr>
          <tr><td>Total Collected</td><td>Total amount collected (Cash + Check − Deductions)</td></tr>
          <tr><td>Balance</td><td>Remaining balance after collection</td></tr>
          <tr><td>Status</td><td>Collection status (e.g. Remitted, Received)</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>All date columns from the SQL Server driver come back as PHP <code>DateTime</code> objects. The system checks <code>instanceof DateTime</code> before formatting — <strong>NULL dates display as a dash</strong>, not an error.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="inv-print" style="scroll-margin-top:80px;">
      <div class="help-section-header"><span class="help-section-icon">🖨️</span><div class="help-section-title">Print Button</div></div>
      <div class="help-intro">A <strong>Print</strong> button appears at the top of the results once a search is done. It opens a clean, printable summary of the invoice journey — all four sections on one page.</div>
      <table class="col-table">
        <thead><tr><th>Detail</th><th>Notes</th></tr></thead>
        <tbody>
          <tr><td>What gets printed</td><td>All four sections for the searched invoice, formatted cleanly without the page chrome</td></tr>
          <tr><td>Print ID used</td><td>The <strong>first <code>ARForCollectionID</code></strong> from Section 4 — retrieved via a separate <code>SELECT TOP 1</code> query so the main result set is not affected</td></tr>
          <tr><td>When it appears</td><td>Only when Section 4 has at least one record — if no collection exists yet, the Print button is hidden</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>If the Print button doesn't appear, it means <strong>Section 4 (AR Collection) has no data yet</strong> for this invoice. The invoice may not have reached the collection stage.</span></div>
    </div>

  </main>
</div>

<div class="footer">Finance Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'intro':'overview','statcards':'overview','filters':'overview','export':'overview',
  'tab-summary':'tabs','tab-pending':'tabs','tab-delivered':'tabs','tab-unremitted':'tabs',
  'tab-remitted':'tabs','tab-received':'tabs','tab-unserved':'tabs','tab-shorts':'tabs',
  'tab-leadman':'tabs','tab-salesman':'tabs',
  'flow-overview':'flow','flow-statuses':'flow','flow-shorts':'flow',
  'ar-intro':'ar','ar-statcards':'ar','ar-filters':'ar','ar-tabs':'ar','ar-export':'ar','ar-flow':'ar',
  'inv-intro':'inv','inv-search':'inv','inv-flow':'inv','inv-summary':'inv',
  'inv-s1':'inv','inv-s2':'inv','inv-s3':'inv','inv-s4':'inv','inv-print':'inv',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'overview');});
</script>
</body>
</html>