<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'short_stocks_paid');
$topbar_page = 'short_stocks_paid';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Short Stocks Paid · Tradewell</title>
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
    <div class="hn-title">📖 Accounting Help</div>

    <div class="hn-group" data-group="ssp">
      <button class="hn-group-toggle" onclick="toggleGroup('ssp')"><span>💸 Short Stocks Paid</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-ssp">
        <a href="#ssp-overview" class="hn-link"><i class="bi bi-info-circle"></i> Overview &amp; Filters</a>
        <a href="#ssp-view"     class="hn-link"><i class="bi bi-toggle-on"></i> View: Not Yet Paid vs All</a>
        <a href="#ssp-stats"    class="hn-link"><i class="bi bi-graph-up"></i> Stat Cards</a>
        <a href="#ssp-table"    class="hn-link"><i class="bi bi-table"></i> Table Columns</a>
        <a href="#ssp-detail"   class="hn-link"><i class="bi bi-file-earmark-text"></i> Record Detail (View)</a>
        <a href="#ssp-export"   class="hn-link"><i class="bi bi-download"></i> Exporting to Excel</a>
        <a href="#ssp-print"    class="hn-link"><i class="bi bi-printer"></i> Printing</a>
        <a href="#ssp-page"     class="hn-link"><i class="bi bi-list-ol"></i> Pagination &amp; Record Count</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Short Stocks Paid <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to tracking, filtering, exporting, and printing paid short-stock records — including what each filter, stat card, and column means, and how the full detail view works.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-funnel-fill"></i> Filters</span>
        <span class="help-chip"><i class="bi bi-graph-up"></i> Stat Cards</span>
        <span class="help-chip"><i class="bi bi-table"></i> Report Table</span>
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Record Detail</span>
        <span class="help-chip"><i class="bi bi-file-earmark-excel"></i> Excel Export</span>
        <span class="help-chip"><i class="bi bi-printer"></i> Print View</span>
      </div>
    </div>

    <!-- ── OVERVIEW & FILTERS ─────────────────────────────────── -->
    <div class="help-section" id="ssp-overview">
      <div class="help-section-header"><span class="help-section-icon">💸</span><div class="help-section-title">Overview &amp; Filters</div></div>
      <div class="help-intro">The <strong>Short Stocks Paid</strong> page gives accounting a filterable, exportable view of every employee short-stock record and its payment status. On a fresh load it shows <strong>the current month's records where Source is blank</strong> (i.e. not yet paid) — use the filters to widen or narrow the view.</div>
      <table class="col-table">
        <thead><tr><th>Filter</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Date Paid From / To</td><td>Limits results to records paid within this date range. Defaults to the current month.</td></tr>
          <tr><td>Department</td><td>Filters by department, populated from the distinct departments present in the data</td></tr>
          <tr><td>Area</td><td>Filters by sales area</td></tr>
          <tr><td>Outlet</td><td>Filters by outlet</td></tr>
          <tr><td>Type Short</td><td>Filters by the type of short stock recorded</td></tr>
          <tr><td>Category</td><td>Filters by short-stock category</td></tr>
          <tr><td>Status</td><td>Filters by the short-stock's status</td></tr>
          <tr><td>Search</td><td>Free-text search across Employee Name, Ref No., and Plate Number at once</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Pick a <strong>View</strong> — Not Yet Paid or All Records (see the View section below).</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Set your <strong>Date Paid From</strong> and <strong>Date Paid To</strong> range if needed.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Narrow down using any combination of <strong>Department, Area, Outlet, Type Short, Category</strong>, or <strong>Status</strong>.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Optionally type into <strong>Search</strong> to jump straight to a specific employee, reference number, or plate number.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Click <strong>Apply Filters</strong>. A <strong>Reset</strong> button is always available to clear everything back to defaults in one click.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>All filters, the stat cards, the Excel export, and the print report stay in sync — whatever you filter on the screen is exactly what gets exported or printed.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── VIEW TOGGLE ─────────────────────────────────── -->
    <div class="help-section" id="ssp-view">
      <div class="help-section-header"><span class="help-section-icon">🔀</span><div class="help-section-title">View: Not Yet Paid vs All</div></div>
      <div class="help-intro">The <strong>View</strong> dropdown controls whether already-sourced (paid out) records are included.</div>
      <table class="col-table">
        <thead><tr><th>Option</th><th>What it shows</th></tr></thead>
        <tbody>
          <tr><td>Not Yet Paid</td><td>Records where <strong>Source is blank</strong> — this is the default on a fresh load</td></tr>
          <tr><td>All Records</td><td>Every record regardless of Source</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-info-circle-fill"></i><span>If you clear the date range yourself and re-apply, that's respected as an explicit choice — the page won't silently reset it back to the current month on you.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── STAT CARDS ─────────────────────────────────── -->
    <div class="help-section" id="ssp-stats">
      <div class="help-section-header"><span class="help-section-icon">📊</span><div class="help-section-title">Stat Cards</div></div>
      <div class="help-intro">Three summary cards sit above the table, all scoped to your <strong>current filters</strong> — change any filter and they recalculate.</div>
      <table class="col-table">
        <thead><tr><th>Card</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Records</td><td>Count of rows matching the current filters</td></tr>
          <tr><td>Total Paid Amount</td><td>Sum of Paid Amount across the filtered records</td></tr>
          <tr><td>Total Balance</td><td>Sum of Balance across the filtered records</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <!-- ── TABLE COLUMNS ─────────────────────────────────── -->
    <div class="help-section" id="ssp-table">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">Table Columns</div></div>
      <div class="help-intro">Each row is one short-stock payment record. The on-screen table is deliberately trimmed to the essentials — the full record is one click away via <strong>View</strong>.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee Name</td><td>The employee the short-stock record belongs to</td></tr>
          <tr><td>Department</td><td>The employee's department</td></tr>
          <tr><td>Area</td><td>The sales area tied to the record</td></tr>
          <tr><td>Outlet</td><td>The outlet tied to the record</td></tr>
          <tr><td>Plate No.</td><td>The vehicle plate number associated with the record</td></tr>
          <tr><td>Ref No.</td><td>The record's reference number</td></tr>
          <tr><td>Date Generated</td><td>The date the short-stock record was generated</td></tr>
          <tr><td>Total Amount</td><td>The record's total amount in pesos</td></tr>
          <tr><td>Amount Due</td><td>The amount still owed at the time of the record</td></tr>
          <tr><td>Paid Amount</td><td>The amount that has been paid</td></tr>
          <tr><td>Balance</td><td>Amount Due minus Paid Amount</td></tr>
          <tr><td>Date Paid</td><td>The date the payment was made</td></tr>
          <tr><td>Type Short</td><td>The type of short stock recorded</td></tr>
          <tr><td>Remarks</td><td>Any free-text remarks, truncated on screen — hover to see the full text</td></tr>
          <tr><td>Action</td><td>A <strong>View</strong> link that opens the full record detail page</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-eye-fill"></i><span>Columns like Employee ID, Category, Status, Payment ID, and Source aren't shown on screen to keep the table readable — click <strong>View</strong> on any row to see them.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── RECORD DETAIL (VIEW) ─────────────────────────────────── -->
    <div class="help-section" id="ssp-detail">
      <div class="help-section-header"><span class="help-section-icon">🗂️</span><div class="help-section-title">Record Detail (View)</div></div>
      <div class="help-intro">Clicking <strong>View</strong> on any row opens the full record, organized into four sections.</div>

      <div class="help-intro" style="margin-top:0;"><strong>Payment Summary</strong></div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Amount Due / Paid Amount / Balance / Total Amount</td><td>The same payment figures shown in the list, in full</td></tr>
          <tr><td>Date Paid</td><td>The date the payment was made</td></tr>
          <tr><td>Payment ID</td><td>The internal ID of the payment transaction</td></tr>
          <tr><td>Source</td><td>Where the payment was sourced from — blank means the record hasn't been paid out yet</td></tr>
          <tr><td>Reference No.</td><td>The record's reference number</td></tr>
        </tbody>
      </table>

      <div class="help-intro"><strong>Short Stock Details</strong></div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Type Short / Category</td><td>How the short stock is classified</td></tr>
          <tr><td>Num. Accountable</td><td>The accountable quantity tied to the short</td></tr>
          <tr><td>Amount (L)</td><td>The recorded liability amount</td></tr>
          <tr><td>Date Generated / Date Schedule</td><td>When the record was generated, and its scheduled date</td></tr>
          <tr><td>Remarks</td><td>Full remarks text, untruncated</td></tr>
        </tbody>
      </table>

      <div class="help-intro"><strong>Route / Assignment</strong></div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Plate Number / Area / Outlet</td><td>The route and location tied to the record</td></tr>
          <tr><td>SDID / DID</td><td>Internal schedule and delivery identifiers</td></tr>
        </tbody>
      </table>

      <div class="help-intro"><strong>Employee Info</strong></div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee ID</td><td>The employee's ID</td></tr>
          <tr><td>Job Title / Position Held</td><td>The employee's role</td></tr>
          <tr><td>Employee Status</td><td>The employee's current status</td></tr>
          <tr><td>Record Status</td><td>The short-stock record's own status field</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-arrow-left-circle-fill"></i><span>Use <strong>Back to List</strong> at the bottom, or the back link at the top of the page, to return to your filtered results.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── EXPORTING ─────────────────────────────────── -->
    <div class="help-section" id="ssp-export">
      <div class="help-section-header"><span class="help-section-icon">⬇️</span><div class="help-section-title">Exporting to Excel</div></div>
      <div class="help-intro">Click <strong>Download Excel</strong> to download <strong>every matching record</strong> for your current filters as a spreadsheet file — not just the current page.</div>
      <div class="tip-box success"><i class="bi bi-check-circle-fill"></i><span>The export includes the full detail record — every column from the View page — independent of whichever columns are trimmed from the on-screen table.</span></div>
      <div class="tip-box"><i class="bi bi-info-circle-fill"></i><span>The export ignores pagination entirely and uses the same filters and defaults as the list page.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── PRINTING ─────────────────────────────────── -->
    <div class="help-section" id="ssp-print">
      <div class="help-section-header"><span class="help-section-icon">🖨️</span><div class="help-section-title">Printing</div></div>
      <div class="help-intro">Click <strong>Print Report</strong> to open a clean, print-friendly version of the full filtered result set in a new tab — the browser's print dialog opens automatically.</div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Like the Excel export, the print view includes <strong>all</strong> matching records for your current filters, not just the visible page — and totals for Total Amount, Amount Due, Paid Amount, and Balance are printed at the bottom.</span></div>
      <div class="tip-box"><i class="bi bi-table"></i><span>The print report mirrors the same trimmed columns as the on-screen table (Employee Name through Remarks) — it doesn't include the extra detail fields found on the View page.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── PAGINATION ─────────────────────────────────── -->
    <div class="help-section" id="ssp-page">
      <div class="help-section-header"><span class="help-section-icon">🔢</span><div class="help-section-title">Pagination &amp; Record Count</div></div>
      <div class="help-intro">The on-screen table shows <strong>20 records per page</strong>. The "Showing X–Y of Z" line at the bottom always reflects your current filters — not the full unfiltered database.</div>
      <div class="tip-box"><i class="bi bi-info-circle-fill"></i><span>Use the page numbers at the bottom to move through results. Pagination only affects what's visible on screen — filtering, exporting, and printing always act on the full filtered set.</span></div>
    </div>

  </main>
</div>
<div class="footer">Accounting Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'ssp-overview':'ssp','ssp-view':'ssp','ssp-stats':'ssp','ssp-table':'ssp','ssp-detail':'ssp','ssp-export':'ssp','ssp-print':'ssp','ssp-page':'ssp',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'ssp');});
</script>
</body>
</html>