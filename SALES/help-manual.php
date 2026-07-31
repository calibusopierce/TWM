<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('sales_order_report')) {
    header('Location: ' . base_url('help-manual.php')); exit();
}
$topbar_page = 'sales_order_report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Sales Order Report · Tradewell</title>
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
.help-divider{border:none;border-top:1px solid var(--border);margin:2rem 0}
.footer{text-align:center;padding:1.5rem;font-size:.75rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:1rem}
@media(max-width:900px){.help-layout{flex-direction:column;padding:1rem}.help-sidebar{width:100%;position:static}}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="help-layout">

  <nav class="help-sidebar">
    <div class="hn-title">📖 Sales Help</div>

    <div class="hn-group" data-group="sor">
      <button class="hn-group-toggle" onclick="toggleGroup('sor')"><span>🧾 Sales Order Report</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-sor">
        <a href="#sor-overview"  class="hn-link"><i class="bi bi-info-circle"></i> Overview &amp; Filters</a>
        <a href="#sor-stats"     class="hn-link"><i class="bi bi-graph-up"></i> Stat Cards</a>
        <a href="#sor-table"     class="hn-link"><i class="bi bi-table"></i> Table Columns</a>
        <a href="#sor-export"    class="hn-link"><i class="bi bi-download"></i> Exporting to CSV</a>
        <a href="#sor-print"     class="hn-link"><i class="bi bi-printer"></i> Printing</a>
        <a href="#sor-page"      class="hn-link"><i class="bi bi-list-ol"></i> Pagination &amp; Record Count</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Sales Order Report <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to filtering, reading, exporting, and printing sales orders from the accounting view — including what each stat card and column means.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-funnel-fill"></i> Filters</span>
        <span class="help-chip"><i class="bi bi-graph-up"></i> Stat Cards</span>
        <span class="help-chip"><i class="bi bi-table"></i> Report Table</span>
        <span class="help-chip"><i class="bi bi-download"></i> CSV Export</span>
        <span class="help-chip"><i class="bi bi-printer"></i> Print View</span>
      </div>
    </div>

    <!-- ── OVERVIEW & FILTERS ─────────────────────────────────── -->
    <div class="help-section" id="sor-overview">
      <div class="help-section-header"><span class="help-section-icon">🧾</span><div class="help-section-title">Overview &amp; Filters</div></div>
      <div class="help-intro">The <strong>Sales Order Report</strong> gives accounting a filterable, exportable view of every sales order line item. By default it loads <strong>today's orders only</strong> — use the filters to widen the view.</div>
      <table class="col-table">
        <thead><tr><th>Filter</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Date From / To</td><td>Limits results to orders booked within this date range. Defaults to today.</td></tr>
          <tr><td>Department</td><td>Filters by department. Non-admin / non-HR users have this <strong>locked to their own department</strong> and can't change it.</td></tr>
          <tr><td>Branch</td><td>Filters by the branch the order was placed from</td></tr>
          <tr><td>Area</td><td>Filters by sales area</td></tr>
          <tr><td>Salesman</td><td>Filters to a single salesman's orders by code</td></tr>
          <tr><td>Supplier</td><td>Filters to orders sourced from a single supplier</td></tr>
          <tr><td>Customer</td><td>Filters to a single customer, shown by name in the dropdown</td></tr>
          <tr><td>Search</td><td>Free-text search across SOID, Customer, Product, and Salesman at once</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Set your <strong>Date From</strong> and <strong>Date To</strong> range.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Narrow down using any combination of <strong>Department, Branch, Area, Salesman, Supplier</strong>, or <strong>Customer</strong>.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Optionally type into <strong>Search</strong> to jump straight to a specific order, customer, or product.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Filter</strong> to apply. A <strong>Reset</strong> button appears whenever any filter differs from the default, letting you clear everything in one click.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>All filters, the stat cards, the CSV export, and the print view stay in sync — whatever you filter on the screen is exactly what gets exported or printed.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── STAT CARDS ─────────────────────────────────── -->
    <div class="help-section" id="sor-stats">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Stat Cards</div></div>
      <div class="help-intro">Five summary cards sit above the table, all scoped to your <strong>current filters</strong> — change any filter and they recalculate.</div>
      <table class="col-table">
        <thead><tr><th>Card</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Unique Customers</td><td>Count of distinct customers with an order in the current filter range</td></tr>
          <tr><td>Total Quantity</td><td>Sum of all item quantities across the filtered orders</td></tr>
          <tr><td>Total Sales Value</td><td>Sum of the ₱ sub-total across the filtered orders</td></tr>
          <tr><td>Unique Suppliers</td><td>Count of distinct suppliers that sourced the filtered orders</td></tr>
          <tr><td>Unique Salesmen</td><td>Count of distinct salesmen who booked the filtered orders</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-cursor-fill"></i><span><strong>Click</strong> the Unique Customers, Suppliers, or Salesmen cards to open a modal listing exactly who's included in that count — no need to scroll the full table to check. Total Quantity and Total Sales Value are display-only.</span></div>

      <div class="help-intro" style="margin-top:1.25rem;"><strong>Unique Customers modal</strong> — the only one of the three with full detail. It opens a table with these columns:</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Customer Code</td><td>The customer's unique code</td></tr>
          <tr><td>Customer Name</td><td>The customer's registered name</td></tr>
          <tr><td>Area</td><td>The sales area on their most recent order in range</td></tr>
          <tr><td>Address</td><td>The customer's address on file</td></tr>
          <tr><td>Encoded By</td><td>Who encoded that customer's most recent order in range</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-printer-fill"></i><span>The Unique Customers modal has its own <strong>Print</strong> button in the footer — separate from the report's main Print button. It opens a clean, dedicated printout of just the customer list shown in the modal, in a new tab.</span></div>
      <div class="tip-box"><i class="bi bi-truck"></i><span>The <strong>Unique Suppliers</strong> and <strong>Unique Salesmen</strong> modals are simpler — just a list of the matching codes, with no print button of their own.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── TABLE COLUMNS ─────────────────────────────────── -->
    <div class="help-section" id="sor-table">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">Table Columns</div></div>
      <div class="help-intro">Each row is one product line item on a sales order — a single SOID can span multiple rows if it has multiple products.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>SOID</td><td>The sales order's unique ID number</td></tr>
          <tr><td>Date Book</td><td>The date the order was booked</td></tr>
          <tr><td>Req. Delivery</td><td>The customer's requested delivery date</td></tr>
          <tr><td>Department / Branch / Area</td><td>Where the order was placed from</td></tr>
          <tr><td>Customer Name</td><td>The customer the order was placed for</td></tr>
          <tr><td>Terms</td><td>Payment terms for the order</td></tr>
          <tr><td>Salesman</td><td>The salesman code who booked the order</td></tr>
          <tr><td>Product</td><td>Product name, with the product code shown underneath</td></tr>
          <tr><td>UOM</td><td>Unit of measure for the quantity (e.g. box, pack, pc)</td></tr>
          <tr><td>Qty</td><td>Quantity ordered for that line item</td></tr>
          <tr><td>Unit Price</td><td>Price per unit in pesos</td></tr>
          <tr><td>Sub Total (₱)</td><td>Quantity × Unit Price for that line item</td></tr>
          <tr><td>Supplier</td><td>The supplier that sourced this product</td></tr>
          <tr><td>Remarks</td><td>Any free-text remarks attached to the order</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-dash-circle"></i><span>A dash (—) in Branch, Area, Terms, or Supplier just means that field wasn't filled in on the order — it's not an error.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── EXPORTING ─────────────────────────────────── -->
    <div class="help-section" id="sor-export">
      <div class="help-section-header"><span class="help-section-icon">⬇️</span><div class="help-section-title">Exporting to CSV</div></div>
      <div class="help-intro">Click <strong>Export CSV</strong> at the top right to download <strong>every matching record</strong> for your current filters as a spreadsheet file — not just the current page.</div>
      <div class="tip-box success"><i class="bi bi-check-circle-fill"></i><span>The export ignores pagination entirely — if your filters match 5,000 rows, the CSV will contain all 5,000, ready to open in Excel or Google Sheets.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── PRINTING ─────────────────────────────────── -->
    <div class="help-section" id="sor-print">
      <div class="help-section-header"><span class="help-section-icon">🖨️</span><div class="help-section-title">Printing</div></div>
      <div class="help-intro">Click <strong>Print</strong> at the top right to open a clean, print-friendly version of the full filtered result set in a new tab — the browser's print dialog opens automatically.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Like the CSV export, the print view includes <strong>all</strong> matching records for your current filters, not just the visible page.</span></div>
      <div class="tip-box"><i class="bi bi-people-fill"></i><span>Need just the customer list instead? Open the <strong>Unique Customers</strong> stat card and use the Print button inside that modal — see the Stat Cards section above.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── PAGINATION ─────────────────────────────────── -->
    <div class="help-section" id="sor-page">
      <div class="help-section-header"><span class="help-section-icon">🔢</span><div class="help-section-title">Pagination &amp; Record Count</div></div>
      <div class="help-intro">The on-screen table shows <strong>20 records per page</strong>. The record count next to the table title, and the "Showing X of Y records" line at the bottom, always reflect your current filters — not the full unfiltered database.</div>
      <div class="tip-box"><i class="bi bi-info-circle-fill"></i><span>Use the page numbers at the bottom to move through results. Applying a new filter always takes you back to page 1.</span></div>
    </div>

  </main>
</div>
<div class="footer">Sales Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'sor-overview':'sor','sor-stats':'sor','sor-table':'sor','sor-export':'sor','sor-print':'sor','sor-page':'sor',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'sor');});
</script>
</body>
</html>