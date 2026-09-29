<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('qr_untagged_map')) {
    header('Location: ' . base_url('help-manual.php')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — QR Code Tagging · Tradewell</title>
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
    <div class="hn-title">📖 QR Tagging Help</div>
    <div class="hn-group" data-group="overview">
      <button class="hn-group-toggle" onclick="toggleGroup('overview')"><span>📋 Overview</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-overview">
        <a href="#intro"    class="hn-link"><i class="bi bi-info-circle"></i> What Is This?</a>
        <a href="#tag-flow" class="hn-link"><i class="bi bi-diagram-3"></i> Tagging Lifecycle</a>
        <a href="#tabs"     class="hn-link"><i class="bi bi-tag"></i> The Three Views</a>
      </div>
    </div>
    <div class="hn-group" data-group="untagged">
      <button class="hn-group-toggle" onclick="toggleGroup('untagged')"><span>📍 Untagged QR Codes</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-untagged">
        <a href="#ut-map"      class="hn-link"><i class="bi bi-map"></i> Reading the Map</a>
        <a href="#ut-location" class="hn-link"><i class="bi bi-geo-alt"></i> Picking the Real Location</a>
        <a href="#ut-tag"      class="hn-link"><i class="bi bi-check2-circle"></i> Tagging a Customer</a>
        <a href="#ut-override" class="hn-link"><i class="bi bi-arrow-repeat"></i> Overriding an Existing Tag</a>
        <a href="#ut-delete"   class="hn-link"><i class="bi bi-trash"></i> Deleting a Wrong Scan</a>
      </div>
    </div>
    <div class="hn-group" data-group="pending">
      <button class="hn-group-toggle" onclick="toggleGroup('pending')"><span>🕓 Pending Sync</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-pending">
        <a href="#pend-overview" class="hn-link"><i class="bi bi-info-circle"></i> What Is Pending Sync?</a>
        <a href="#pend-sync"     class="hn-link"><i class="bi bi-arrow-down-circle"></i> Syncing a Record</a>
        <a href="#pend-void"     class="hn-link"><i class="bi bi-x-circle"></i> Voiding a Record</a>
      </div>
    </div>
    <div class="hn-group" data-group="search">
      <button class="hn-group-toggle" onclick="toggleGroup('search')"><span>🔍 Tables &amp; Search</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-search">
        <a href="#search-filter" class="hn-link"><i class="bi bi-funnel"></i> Filtering the Table</a>
        <a href="#cust-modal"    class="hn-link"><i class="bi bi-person-vcard"></i> Customer Detail Modal</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">QR Code Tagging <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to matching scanned QR codes to the right customer, syncing pre-assigned tags, and keeping the customer database's QR codes and coordinates accurate and up to date.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-qr-code"></i> QR Scanning</span>
        <span class="help-chip"><i class="bi bi-geo-alt"></i> Location Picking</span>
        <span class="help-chip"><i class="bi bi-arrow-down-circle"></i> Pending Sync</span>
        <span class="help-chip"><i class="bi bi-people"></i> Customer Search</span>
      </div>
    </div>

    <div class="help-section" id="intro">
      <div class="help-section-header"><span class="help-section-icon">🚀</span><div class="help-section-title">What Is This?</div></div>
      <div class="help-intro">The <strong>QR Code Tagging</strong> page matches QR codes that field staff scan at customer locations to the correct customer record in <strong>Tbl_Customer_Info</strong>. Every scan carries a code and a GPS location — tagging links that code (and its true location) to a specific customer, so the store's QR code always points back to the right shop.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to the <strong>Untagged QR Codes</strong> tab to see codes that have been scanned but not yet linked to any customer.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Select a code on the map or in the table, then search for and confirm the matching customer.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Some codes may already be pre-assigned to a customer elsewhere in the system — those appear under <strong>Pending Sync</strong> and just need a final confirmation.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Use the customer tables and search to review who already has a QR code, and to override a tag if it was assigned incorrectly.</div></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tag-flow">
      <div class="help-section-header"><span class="help-section-icon">🔄</span><div class="help-section-title">Tagging Lifecycle</div></div>
      <div class="help-intro">A QR code generally moves through these stages, though not every code goes through Pending Sync.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text"><strong>Untagged</strong> — The code has been scanned in the field but has no customer linked to it yet.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text"><strong>Pending Sync</strong> — A customer has already been assigned to this code elsewhere in the system, but that assignment hasn't been written into the customer's record yet.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text"><strong>Tagged</strong> — The code, latitude, and longitude are saved directly on the customer's record. The customer now shows under "Customers with QR."</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Tagging always <strong>overwrites</strong> whatever code and coordinates a customer previously had — there is no separate "untag" step. Double-check the customer before confirming, especially if they already show a QR code.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="tabs">
      <div class="help-section-header"><span class="help-section-icon">🏷️</span><div class="help-section-title">The Three Views</div></div>
      <div class="help-intro">The page is split into two tabs at the top, and the Untagged tab further splits into three filterable tables.</div>
      <div class="status-row"><span class="status-badge status-cancelled">● Untagged QR Codes</span><span style="font-size:.88rem;color:var(--text-primary);">Scanned codes with no customer linked yet. Shown in red.</span></div>
      <div class="status-row"><span class="status-badge" style="background:rgba(148,163,184,.15);color:#475569;border:1px solid rgba(148,163,184,.3);">● Customers without QR</span><span style="font-size:.88rem;color:var(--text-primary);">Customers with no code tagged — the pool you tag codes onto. Shown in grey.</span></div>
      <div class="status-row"><span class="status-badge status-approved">● Customers with QR</span><span style="font-size:.88rem;color:var(--text-primary);">Customers who already have a code linked to their record. Shown in green.</span></div>
      <div class="status-row"><span class="status-badge status-draft">● Pending Sync</span><span style="font-size:.88rem;color:var(--text-primary);">Pre-assigned tags waiting to be written into the customer record. Shown in amber.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ut-map">
      <div class="help-section-header"><span class="help-section-icon">🗺️</span><div class="help-section-title">Reading the Map</div></div>
      <div class="help-intro">Each red chip on the map is one untagged QR code. Clicking a chip — or its row in the table below — selects that code and hides the other pins so it's easy to see, since scans from the same area can otherwise sit right on top of each other.</div>
      <table class="col-table">
        <thead><tr><th>Element</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Red chip</td><td>An untagged QR code, labeled with the code itself</td></tr>
          <tr><td>"Showing only ___" bar</td><td>Appears once you select a code — click <strong>Show all pins</strong> to bring the rest back</td></tr>
          <tr><td>Scans count</td><td>How many times this code has been scanned in the field</td></tr>
          <tr><td>Distance badge</td><td>Green = under 100m, amber = 100–500m, red = over 500m from the selected scan location</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the search box above the table to jump straight to a code or FileNo instead of hunting for it on a crowded map.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ut-location">
      <div class="help-section-header"><span class="help-section-icon">📍</span><div class="help-section-title">Picking the Real Location</div></div>
      <div class="help-intro">A QR code can be photocopied and end up scanned in more than one place. When that happens, the panel shows a warning and a small radio button next to each scan.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Select the QR code you're working on — its scans appear in the panel on the right, each with the employee who scanned it.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">If more than one location shows up, a red note appears: "This code was scanned from more than one location."</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Click the radio next to the scan that matches the customer's actual shop. The map flies to that spot to confirm.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">The customer search and the eventual tag will now use <strong>this</strong> location, not just whichever scan happened to come first.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Always check for this warning before tagging a code with more than one scan — tagging without picking the right one may save the wrong coordinates onto the customer's record.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ut-tag">
      <div class="help-section-header"><span class="help-section-icon">✅</span><div class="help-section-title">Tagging a Customer</div></div>
      <div class="help-intro">Once you've selected a QR code (and confirmed its location, if needed), the panel suggests the nearest customers without a code.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">The panel lists the <strong>10 nearest</strong> customers with no QR code, closest first, with a distance badge.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Type in the search box to search <strong>all</strong> customers by name, code, or address instead of only the nearest ones.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Click a result to preview it — a dashed line draws between the scan location and that customer.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Tag</strong> to confirm. If the customer is more than 500m from the scan, a warning shows before you confirm.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Tagging writes the QR code, latitude, and longitude onto the customer's record all at once — the page reloads afterward so every table and map reflects the change.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ut-override">
      <div class="help-section-header"><span class="help-section-icon">🔁</span><div class="help-section-title">Overriding an Existing Tag</div></div>
      <div class="help-intro">The customer search also includes customers who <strong>already</strong> have a QR code — useful when a code was tagged to the wrong shop and needs correcting.</div>
      <table class="col-table">
        <thead><tr><th>What you'll see</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>"already: [code]" note</td><td>Shown under a candidate's name if they already have a different QR code tagged</td></tr>
          <tr><td><strong>Override</strong> button</td><td>Replaces the <strong>Tag</strong> button for customers who already have a code</td></tr>
          <tr><td>Confirmation warning</td><td>States which code will be overwritten before you can proceed</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>There is no separate "untag" action — overriding is the only way to change a customer's code. The customer's <strong>previous</strong> code becomes unclaimed and will reappear under Untagged QR Codes on the next page load, since nothing else in the system is holding onto it anymore.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="ut-delete">
      <div class="help-section-header"><span class="help-section-icon">🗑️</span><div class="help-section-title">Deleting a Wrong Scan</div></div>
      <div class="help-intro">If a scan was made by mistake or is an exact duplicate, it can be removed from the scan log entirely — this is different from voiding (see Pending Sync).</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Select the QR code — each individual scan is listed in the panel with a small <strong>×</strong> button.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click <strong>×</strong> next to the scan you want removed, then confirm.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">If it was the code's only scan, the whole code disappears from the Untagged list. If other scans remain, the count simply updates.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>This permanently deletes the scan record — it cannot be undone. Use this only for genuine mistakes or duplicates, not to "clean up" scans you simply haven't tagged yet.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="pend-overview">
      <div class="help-section-header"><span class="help-section-icon">🕓</span><div class="help-section-title">What Is Pending Sync?</div></div>
      <div class="help-intro">Some QR codes are pre-assigned to a customer through a separate process before they ever reach this page. The <strong>Pending Sync</strong> tab lists those — the assignment exists, but the customer's own record hasn't been updated yet.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>QR Code</td><td>The code that was pre-assigned</td></tr>
          <tr><td>Customer</td><td>The customer this code was already assigned to</td></tr>
          <tr><td>Code</td><td>The customer's own reference code</td></tr>
          <tr><td>Tagged By</td><td>The employee who made the original assignment</td></tr>
          <tr><td>Date Tagged</td><td>When that assignment was made</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="pend-sync">
      <div class="help-section-header"><span class="help-section-icon">⬇️</span><div class="help-section-title">Syncing a Record</div></div>
      <div class="help-intro">Syncing writes the pending assignment into the customer's actual record — the same fields (QR code, latitude, longitude) that manual tagging writes.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Select a row in the Pending Sync table or on its map.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Review the customer and QR code shown in the panel.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Click <strong>Sync to Tbl_Customer_Info</strong> and confirm.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Once synced, the record disappears from Pending Sync and the customer now shows up under "Customers with QR."</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="pend-void">
      <div class="help-section-header"><span class="help-section-icon">❌</span><div class="help-section-title">Voiding a Record</div></div>
      <div class="help-intro">If a pending assignment turns out to be wrong, it can be voided instead of synced — this cancels it without deleting the underlying record.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Select the row you want to cancel.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Click <strong>Void</strong> next to the Sync button, then confirm.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">The record is marked Void and removed from this list — it will not reappear here again.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Voiding does not delete the record from the database — it's kept on file marked Void for audit purposes. It simply stops
</div>
<div class="footer">Purchase Orders Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'intro':'overview','tag-flow':'overview','tabs':'overview',
  'ut-map':'untagged','ut-location':'untagged','ut-tag':'untagged','ut-override':'untagged','ut-delete':'untagged',
  'pend-overview':'pending','pend-sync':'pending','pend-void':'pending',
  'search-filter':'search','cust-modal':'search',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'overview');});
</script>
</body>
</html>
