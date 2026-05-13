<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('po_index')) {
    header('Location: ' . base_url('help-manual.php')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — Purchase Orders · Tradewell</title>
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
.status-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem}
.status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:700}
.status-draft{background:rgba(245,158,11,.12);color:#b45309;border:1px solid rgba(245,158,11,.3)}
.status-approved{background:rgba(16,185,129,.12);color:#065f46;border:1px solid rgba(16,185,129,.3)}
.status-cancelled{background:rgba(239,68,68,.12);color:#991b1b;border:1px solid rgba(239,68,68,.3)}
.help-divider{border:none;border-top:1px solid var(--border);margin:2rem 0}
.footer{text-align:center;padding:1.5rem;font-size:.75rem;color:var(--text-muted);border-top:1px solid var(--border);margin-top:1rem}
@media(max-width:900px){.help-layout{flex-direction:column;padding:1rem}.help-sidebar{width:100%;position:static}}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="help-layout">

  <nav class="help-sidebar">
    <div class="hn-title">📖 PO Help</div>
    <div class="hn-group" data-group="overview">
      <button class="hn-group-toggle" onclick="toggleGroup('overview')"><span>📋 Overview</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-overview">
        <a href="#intro"    class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#po-flow"  class="hn-link"><i class="bi bi-diagram-3"></i> PO Lifecycle</a>
        <a href="#statuses" class="hn-link"><i class="bi bi-tag"></i> Statuses Explained</a>
      </div>
    </div>
    <div class="hn-group" data-group="po">
      <button class="hn-group-toggle" onclick="toggleGroup('po')"><span>📄 Purchase Orders</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-po">
        <a href="#po-list"   class="hn-link"><i class="bi bi-list-ul"></i> PO List</a>
        <a href="#po-create" class="hn-link"><i class="bi bi-plus-circle"></i> Creating a PO</a>
        <a href="#po-view"   class="hn-link"><i class="bi bi-eye"></i> Viewing a PO</a>
        <a href="#po-edit"   class="hn-link"><i class="bi bi-pencil"></i> Editing a PO</a>
        <a href="#po-print"  class="hn-link"><i class="bi bi-printer"></i> Printing a PO</a>
      </div>
    </div>
    <div class="hn-group" data-group="categories">
      <button class="hn-group-toggle" onclick="toggleGroup('categories')"><span>🗂️ Categories</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-categories">
        <a href="#cat-overview" class="hn-link"><i class="bi bi-grid"></i> What Are Categories?</a>
        <a href="#cat-manage"   class="hn-link"><i class="bi bi-pencil-square"></i> Managing Categories</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">Purchase Orders <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to creating, reviewing, and managing Purchase Orders across all departments — from Draft to Approved, and printing formal PO documents for suppliers.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Purchase Orders</span>
        <span class="help-chip"><i class="bi bi-printer"></i> PO Printing</span>
        <span class="help-chip"><i class="bi bi-grid"></i> PO Categories</span>
        <span class="help-chip"><i class="bi bi-shield-lock"></i> Admin Only</span>
      </div>
    </div>

    <div class="help-section" id="intro">
      <div class="help-section-header"><span class="help-section-icon">🚀</span><div class="help-section-title">What Is This?</div></div>
      <div class="help-intro">The <strong>Purchase Order (PO) module</strong> lets Admin staff create and manage formal purchase orders sent to external suppliers. A PO is an official document that authorizes the purchase of specific goods at agreed quantities and prices. It provides a clear paper trail for procurement and spending.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to the <strong>PO List</strong> to see all existing purchase orders and their current status.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click <strong>Create PO</strong> to raise a new purchase order — fill in the supplier, items, quantities, and prices.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">A PO starts as <strong>Draft</strong>. Once reviewed, it can be moved to <strong>Approved</strong> or <strong>Cancelled</strong>.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Print the PO document to send to the supplier — it includes all line items, totals, and signature fields.</div></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-flow">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">PO Lifecycle</div></div>
      <div class="help-intro">Every Purchase Order follows this flow from creation to completion.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text"><strong>Draft</strong> — A PO is created and saved. It can still be edited at this stage. Not yet sent to the supplier.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text"><strong>Approved</strong> — The PO has been reviewed and authorized. It is now official and can be printed and sent to the supplier.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text"><strong>Cancelled</strong> — The PO was voided before fulfillment. A cancelled PO cannot be reactivated — a new PO must be created if still needed.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Once a PO is set to <strong>Approved</strong>, its line items are locked — editing requires creating a new PO or cancelling and re-creating. Always double-check quantities and prices before approving.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="statuses">
      <div class="help-section-header"><span class="help-section-icon">🏷️</span><div class="help-section-title">Statuses Explained</div></div>
      <div class="help-intro">PO statuses appear as colored badges throughout the PO list and detail pages.</div>
      <div class="status-row"><span class="status-badge status-draft">● Draft</span><span style="font-size:.88rem;color:var(--text-primary);">PO created, not yet approved. Still editable. Shown in amber/yellow.</span></div>
      <div class="status-row"><span class="status-badge status-approved">● Approved</span><span style="font-size:.88rem;color:var(--text-primary);">PO authorized and locked. Ready to print and send to supplier. Shown in green.</span></div>
      <div class="status-row"><span class="status-badge status-cancelled">● Cancelled</span><span style="font-size:.88rem;color:var(--text-primary);">PO voided. Cannot be reactivated. Shown in red.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-list">
      <div class="help-section-header"><span class="help-section-icon">📋</span><div class="help-section-title">PO List</div></div>
      <div class="help-intro">The <strong>PO List</strong> is the main page — it shows all purchase orders across all categories. Use the search and status filter to find specific POs quickly.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>PO Number</td><td>The unique auto-generated reference number for this PO (e.g. PO-2025-001)</td></tr>
          <tr><td>Category</td><td>The department or type of purchase this PO belongs to (e.g. HR, Fleet, Finance, General)</td></tr>
          <tr><td>Supplier</td><td>The vendor or supplier the order is being placed with</td></tr>
          <tr><td>PO Date</td><td>The date this PO was created</td></tr>
          <tr><td>Items</td><td>Number of line items in this PO</td></tr>
          <tr><td>Total Amount</td><td>The total computed cost across all line items</td></tr>
          <tr><td>Status</td><td>Current status — Draft, Approved, or Cancelled</td></tr>
          <tr><td>Created By</td><td>The admin user who created this PO</td></tr>
          <tr><td>Actions</td><td>👁️ View details · ✏️ Edit (Draft only) · 🖨️ Print · ✅ Approve · ❌ Cancel</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>status filter</strong> (All / Draft / Approved / Cancelled) at the top of the list to focus on POs at a specific stage.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-create">
      <div class="help-section-header"><span class="help-section-icon">➕</span><div class="help-section-title">Creating a Purchase Order</div></div>
      <div class="help-intro">Click <strong>Create PO</strong> at the top right of the PO List to open the creation form.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Select a <strong>Category</strong> — this determines which department or purpose the PO belongs to (e.g. HR for uniforms, Fleet for vehicle parts).</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Enter the <strong>Supplier</strong> name — the vendor you're ordering from.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Set the <strong>PO Date</strong> — defaults to today.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Add <strong>line items</strong> — for each item, enter: Item Description, Unit, Quantity, and Unit Price. The Amount auto-calculates.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Click <strong>+ Add Line</strong> to add more items. Remove a line using the <strong>✕</strong> button on the right.</div></div>
        <div class="step-item"><div class="step-num">6</div><div class="step-text">Add any <strong>Remarks</strong> or notes at the bottom (optional).</div></div>
        <div class="step-item"><div class="step-num">7</div><div class="step-text">Click <strong>Save as Draft</strong> to save without approving, or <strong>Save &amp; Approve</strong> to finalize immediately.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Always save as <strong>Draft</strong> first to review the PO before approving it. Once approved, line items are locked.</span></div>
      <table class="col-table">
        <thead><tr><th>Line Item Field</th><th>What to enter</th></tr></thead>
        <tbody>
          <tr><td>Item Description</td><td>What is being ordered (e.g. "Polo Shirt L", "Printer Ink Cartridge")</td></tr>
          <tr><td>Unit</td><td>The unit of measurement (e.g. pcs, boxes, reams, sets)</td></tr>
          <tr><td>Quantity</td><td>How many units are being ordered</td></tr>
          <tr><td>Unit Price</td><td>Price per unit — the Amount column auto-calculates (Qty × Unit Price)</td></tr>
          <tr><td>Amount</td><td>Auto-calculated — Quantity × Unit Price</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-view">
      <div class="help-section-header"><span class="help-section-icon">👁️</span><div class="help-section-title">Viewing a PO</div></div>
      <div class="help-intro">Clicking the <strong>eye icon</strong> or the PO number opens the <strong>PO Detail Page</strong> — a formatted summary showing all PO information in a clean layout ready for review or printing.</div>
      <table class="col-table">
        <thead><tr><th>Section</th><th>What it shows</th></tr></thead>
        <tbody>
          <tr><td>PO Header</td><td>PO Number, status badge, PO date, category, and creation date</td></tr>
          <tr><td>Supplier Info</td><td>Supplier name and any contact details on file</td></tr>
          <tr><td>Created By</td><td>Name of the admin who created the PO</td></tr>
          <tr><td>Line Items Table</td><td>Full list of ordered items — description, unit, quantity, unit price, and amount per line</td></tr>
          <tr><td>Grand Total</td><td>Total cost across all line items, highlighted at the bottom of the table</td></tr>
          <tr><td>Remarks</td><td>Any notes added during creation</td></tr>
          <tr><td>Signature Block</td><td>Prepared by / Approved by signature lines — appears on the printed version</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>You can <strong>Approve</strong> or <strong>Cancel</strong> a Draft PO directly from the detail page using the action buttons at the top — no need to go back to the list.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-edit">
      <div class="help-section-header"><span class="help-section-icon">✏️</span><div class="help-section-title">Editing a PO</div></div>
      <div class="help-intro">Only <strong>Draft</strong> POs can be edited. Click the <strong>pencil icon ✏️</strong> on any Draft row in the PO list, or the <strong>Edit</strong> button on the PO detail page.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Once a PO is <strong>Approved</strong>, it can no longer be edited. If changes are needed after approval, cancel the PO and create a new one.</span></div>
      <table class="col-table">
        <thead><tr><th>What can be changed</th><th>Details</th></tr></thead>
        <tbody>
          <tr><td>Supplier</td><td>Can be updated if the wrong supplier was selected</td></tr>
          <tr><td>PO Date</td><td>Can be adjusted if the date needs correction</td></tr>
          <tr><td>Category</td><td>Can be changed to a different department category</td></tr>
          <tr><td>Line Items</td><td>Items, quantities, and prices can all be modified. Lines can be added or removed.</td></tr>
          <tr><td>Remarks</td><td>Notes can be updated at any time while in Draft</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="po-print">
      <div class="help-section-header"><span class="help-section-icon">🖨️</span><div class="help-section-title">Printing a PO</div></div>
      <div class="help-intro">Click the <strong>Print</strong> button on any PO (from the list or detail page) to open the <strong>print-ready PO document</strong> in a new browser tab. The printed document is formatted as a formal purchase order suitable for sending to suppliers.</div>
      <table class="col-table">
        <thead><tr><th>Print Document Section</th><th>What it includes</th></tr></thead>
        <tbody>
          <tr><td>Header</td><td>Company name, logo, and "Purchase Order" title</td></tr>
          <tr><td>PO Details</td><td>PO Number, PO Date, Status, and Category</td></tr>
          <tr><td>Supplier Details</td><td>Supplier name — add supplier address/contact in the Remarks if needed</td></tr>
          <tr><td>Line Items Table</td><td>Item description, unit, quantity, unit price, and amount per line</td></tr>
          <tr><td>Grand Total</td><td>Total amount highlighted at the bottom of the items table</td></tr>
          <tr><td>Remarks</td><td>Any notes or special instructions included when the PO was created</td></tr>
          <tr><td>Signature Block</td><td>"Prepared by" and "Approved by" signature lines with name and title fields</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>The print view <strong>automatically hides</strong> all navigation bars, sidebars, and buttons — only the document content is shown when printing. Use Ctrl+P / Cmd+P or the browser print dialog.</span></div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Both Draft and Approved POs can be printed. The status badge is included in the print header so you can tell at a glance whether the document is final or still pending approval.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="cat-overview">
      <div class="help-section-header"><span class="help-section-icon">🗂️</span><div class="help-section-title">What Are PO Categories?</div></div>
      <div class="help-intro"><strong>PO Categories</strong> are labels that group purchase orders by department or purpose. They make it easier to filter and report on spending by area — for example, separating uniform purchases (HR) from vehicle parts (Fleet) or office supplies (General).</div>
      <div style="display:flex;flex-direction:column;gap:.45rem;margin-bottom:1rem;">
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(52,211,153,.15);color:#065f46;border:1px solid rgba(52,211,153,.3);border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700;">HR</span><span style="font-size:.88rem;color:var(--text-primary);">Uniforms, employee supplies, HR-related purchases</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(251,191,36,.15);color:#92400e;border:1px solid rgba(251,191,36,.3);border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700;">Fleet</span><span style="font-size:.88rem;color:var(--text-primary);">Vehicle parts, fuel equipment, fleet maintenance supplies</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(167,139,250,.15);color:#4c1d95;border:1px solid rgba(167,139,250,.3);border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700;">Finance</span><span style="font-size:.88rem;color:var(--text-primary);">Accounting supplies, financial tools, Finance department purchases</span></div>
        <div style="display:flex;align-items:center;gap:.75rem;"><span style="background:rgba(96,165,250,.15);color:#1e3a5f;border:1px solid rgba(96,165,250,.3);border-radius:20px;padding:.15rem .6rem;font-size:.75rem;font-weight:700;">General</span><span style="font-size:.88rem;color:var(--text-primary);">Office supplies, miscellaneous purchases not covered by other categories</span></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>On the PO List, use the <strong>category filter</strong> to show only POs from a specific department — useful for department heads reviewing their own spending.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="cat-manage">
      <div class="help-section-header"><span class="help-section-icon">✏️</span><div class="help-section-title">Managing PO Categories</div></div>
      <div class="help-intro">PO Categories can be created, edited, or deleted from the <strong>Categories</strong> page (Admin only). Changes here affect the category dropdown on the PO creation form.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to <strong>PO → Categories</strong> in the navigation to open the categories management page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click <strong>Add Category</strong> to create a new one — enter the category name and an optional description.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">To edit an existing category, click the <strong>Edit</strong> button on any row and update the name or description.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">To delete a category, click the <strong>Delete</strong> button. A category <strong>cannot be deleted</strong> if it has existing POs linked to it.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Deleting a category that still has POs linked to it will show an error and the deletion will be blocked. Reassign or archive those POs first before deleting the category.</span></div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Each category shows a <strong>PO count</strong> — how many purchase orders are linked to it. This helps you see which categories are most active.</span></div>
    </div>

  </main>
</div>
<div class="footer">Purchase Orders Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'intro':'overview','po-flow':'overview','statuses':'overview',
  'po-list':'po','po-create':'po','po-view':'po','po-edit':'po','po-print':'po',
  'cat-overview':'categories','cat-manage':'categories',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'overview');});
</script>
</body>
</html>
