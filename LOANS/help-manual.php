<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
$perms = rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$hasAdminAccess = isset($perms['employee_loans']);
$hasMyLoans     = isset($perms['my_loans']);
if (!$hasAdminAccess && !$hasMyLoans) {
    header("Location: " . base_url('index.php'));
    exit;
}
$isAdmin = in_array($_SESSION['UserType'] ?? '', ['Admin', 'Administrator']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Employee Loans — Help Manual · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* ── Layout ───────────────────────────────────────────── */
    .help-layout {
      display: grid;
      grid-template-columns: 240px 1fr;
      gap: 1.5rem;
      align-items: start;
    }
    @media (max-width: 768px) {
      .help-layout { grid-template-columns: 1fr; }
      .help-nav    { display: none; }
    }

    /* ── Sidebar nav ──────────────────────────────────────── */
    .help-nav {
      position: sticky;
      top: 1.5rem;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 1rem 0;
      box-shadow: var(--shadow-sm);
    }
    .help-nav-title {
      font-size: .7rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .07em; color: var(--text-muted);
      padding: .25rem 1.1rem .6rem;
    }
    .help-nav a {
      display: flex; align-items: center; gap: .5rem;
      padding: .45rem 1.1rem; font-size: .83rem; color: var(--text-main);
      text-decoration: none; border-left: 2px solid transparent;
      transition: background .12s, color .12s, border-color .12s;
    }
    .help-nav a:hover  { background: var(--surface-alt, #f8fafc); color: var(--primary); }
    .help-nav a.active { background: rgba(59,130,246,.07); color: var(--primary); border-left-color: var(--primary); font-weight: 600; }
    .help-nav a i      { font-size: .88rem; opacity: .75; }
    .help-nav hr       { margin: .5rem 1.1rem; border-color: var(--border); }

    /* ── Content ──────────────────────────────────────────── */
    .help-content { min-width: 0; }

    .help-section {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      margin-bottom: 1.5rem;
      overflow: hidden;
    }
    .help-section-header {
      display: flex; align-items: center; gap: .65rem;
      padding: .9rem 1.25rem;
      border-bottom: 1px solid var(--border);
      background: var(--surface-alt, #f8fafc);
    }
    .help-section-header i {
      font-size: 1rem; color: var(--primary); flex-shrink: 0;
    }
    .help-section-header h2 {
      font-size: .97rem; font-weight: 700; color: var(--text-main);
      margin: 0;
    }
    .help-section-body { padding: 1.25rem 1.4rem; }

    /* ── Prose ────────────────────────────────────────────── */
    .help-section-body p {
      font-size: .875rem; color: var(--text-main); line-height: 1.65;
      margin-bottom: .75rem;
    }
    .help-section-body p:last-child { margin-bottom: 0; }
    .help-section-body h3 {
      font-size: .83rem; font-weight: 800; text-transform: uppercase;
      letter-spacing: .05em; color: var(--primary);
      margin: 1.2rem 0 .5rem; border-bottom: 1px solid var(--border);
      padding-bottom: .35rem;
    }
    .help-section-body h3:first-child { margin-top: 0; }

    /* ── Step list ────────────────────────────────────────── */
    .step-list { list-style: none; padding: 0; margin: 0 0 .75rem; counter-reset: steps; }
    .step-list li {
      display: flex; gap: .85rem; align-items: flex-start;
      padding: .55rem 0; border-bottom: 1px dashed var(--border);
      font-size: .875rem; color: var(--text-main); line-height: 1.55;
      counter-increment: steps;
    }
    .step-list li:last-child { border-bottom: none; }
    .step-num {
      flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%;
      background: var(--primary); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: .72rem; font-weight: 700; margin-top: .1rem;
    }

    /* ── Bullet list ──────────────────────────────────────── */
    .bullet-list { list-style: none; padding: 0; margin: 0 0 .75rem; }
    .bullet-list li {
      display: flex; gap: .6rem; align-items: flex-start;
      font-size: .875rem; color: var(--text-main); line-height: 1.55;
      padding: .3rem 0;
    }
    .bullet-list li i { color: var(--primary); font-size: .75rem; margin-top: .3rem; flex-shrink: 0; }

    /* ── Status badges ────────────────────────────────────── */
    .status-table { width: 100%; border-collapse: collapse; font-size: .84rem; margin-bottom: .75rem; }
    .status-table th {
      padding: .5rem .85rem; font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
      background: var(--surface-alt, #f8fafc); border-bottom: 2px solid var(--border);
      text-align: left;
    }
    .status-table td { padding: .55rem .85rem; border-bottom: 1px solid var(--border); vertical-align: top; }
    .status-table tr:last-child td { border-bottom: none; }
    .status-table td:first-child { white-space: nowrap; }

    .sbadge {
      display: inline-flex; align-items: center; gap: .3rem;
      padding: .2rem .65rem; border-radius: 999px;
      font-size: .74rem; font-weight: 700; white-space: nowrap;
    }
    .sbadge::before { content: ''; width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .s-proposal  { background: rgba(245,158,11,.12); color: #92400e; }
    .s-proposal::before  { background: #f59e0b; }
    .s-approved  { background: rgba(99,102,241,.12); color: #3730a3; }
    .s-approved::before  { background: #6366f1; }
    .s-active    { background: rgba(16,185,129,.12); color: #065f46; }
    .s-active::before    { background: #10b981; }
    .s-paid      { background: rgba(20,184,166,.12); color: #134e4a; }
    .s-paid::before      { background: #0d9488; }
    .s-cancelled { background: rgba(239,68,68,.12); color: #991b1b; }
    .s-cancelled::before { background: #ef4444; }

    /* ── Callouts ─────────────────────────────────────────── */
    .callout {
      display: flex; gap: .75rem; align-items: flex-start;
      padding: .75rem 1rem; border-radius: var(--radius);
      margin-bottom: .85rem; font-size: .85rem; line-height: 1.55;
    }
    .callout i { font-size: 1rem; flex-shrink: 0; margin-top: .05rem; }
    .callout-info    { background: rgba(59,130,246,.08);  border-left: 3px solid #3b82f6; color: #1e3a5f; }
    .callout-info i  { color: #3b82f6; }
    .callout-warn    { background: rgba(245,158,11,.09);  border-left: 3px solid #f59e0b; color: #78350f; }
    .callout-warn i  { color: #f59e0b; }
    .callout-danger  { background: rgba(239,68,68,.08);   border-left: 3px solid #ef4444; color: #7f1d1d; }
    .callout-danger i{ color: #ef4444; }
    .callout-success { background: rgba(16,185,129,.08);  border-left: 3px solid #10b981; color: #064e3b; }
    .callout-success i { color: #10b981; }

    /* ── Field reference table ────────────────────────────── */
    .field-table { width: 100%; border-collapse: collapse; font-size: .84rem; margin-bottom: .75rem; }
    .field-table th {
      padding: .5rem .85rem; font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
      background: var(--surface-alt, #f8fafc); border-bottom: 2px solid var(--border);
      text-align: left;
    }
    .field-table td { padding: .5rem .85rem; border-bottom: 1px solid var(--border); vertical-align: top; line-height: 1.5; }
    .field-table tr:last-child td { border-bottom: none; }
    .field-name { font-family: monospace; font-size: .8rem; font-weight: 700; color: var(--primary); white-space: nowrap; }
    .req-badge  { font-size: .68rem; font-weight: 700; padding: .1rem .4rem; border-radius: 4px;
                  background: rgba(239,68,68,.1); color: #b91c1c; }

    /* ── Freq grid ────────────────────────────────────────── */
    .freq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: .75rem; margin-bottom: .75rem; }
    .freq-card {
      border: 1px solid var(--border); border-radius: var(--radius);
      padding: .75rem 1rem; background: var(--surface-alt, #f8fafc);
    }
    .freq-card-title { font-size: .78rem; font-weight: 700; color: var(--text-main); margin-bottom: .25rem; }
    .freq-card-desc  { font-size: .77rem; color: var(--text-muted); line-height: 1.45; }

    /* ── Page hero ────────────────────────────────────────── */
    .help-hero {
      background: linear-gradient(135deg, rgba(59,130,246,.08) 0%, rgba(99,102,241,.06) 100%);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.5rem;
      display: flex; align-items: center; gap: 1.25rem;
    }
    .help-hero-icon {
      width: 52px; height: 52px; border-radius: 14px;
      background: var(--primary); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.5rem; flex-shrink: 0;
    }
    .help-hero-title   { font-size: 1.3rem; font-weight: 800; color: var(--text-main); margin-bottom: .2rem; }
    .help-hero-subtitle{ font-size: .85rem; color: var(--text-muted); line-height: 1.5; }

    /* ── RBAC access table ────────────────────────────────── */
    .access-grid {
      display: grid; grid-template-columns: 1fr 1fr 1fr;
      gap: 0; border: 1px solid var(--border); border-radius: var(--radius);
      overflow: hidden; margin-bottom: .75rem;
    }
    .access-col-header {
      padding: .55rem .85rem; font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
      background: var(--surface-alt, #f8fafc); border-bottom: 1px solid var(--border);
      text-align: center;
    }
    .access-col-header:not(:last-child) { border-right: 1px solid var(--border); }
    .access-cell {
      padding: .5rem .85rem; font-size: .82rem; color: var(--text-main);
      border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: .4rem;
    }
    .access-cell:not(:last-child):nth-child(3n+1),
    .access-cell:not(:last-child):nth-child(3n+2) { border-right: 1px solid var(--border); }
    .access-grid > *:nth-last-child(-n+3) { border-bottom: none; }
    .chk  { color: #10b981; } 
    .nchk { color: #e2e8f0; }
  </style>
</head>
<body>
<?php $topbar_page = $hasAdminAccess ? 'employee_loans' : 'my_loans';
      require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">Help Manual</div>
      <div class="page-subtitle">Employee Loans Module — complete guide</div>
    </div>
    <a href="<?= base_url($hasAdminAccess ? 'EMPLOYEE/index.php' : 'EMPLOYEE/my_loans.php') ?>" class="btn btn-secondary-custom">
      <i class="bi bi-arrow-left"></i> Back to Loans
    </a>
  </div>

  <!-- Hero -->
  <div class="help-hero">
    <div class="help-hero-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
    <div>
      <div class="help-hero-title">Employee Loans — User Guide</div>
      <div class="help-hero-subtitle">
        This page covers everything about the Employee Loans module: creating and managing loan records,
        understanding the amortization schedule, recording payments, and what each status means.
        <?php if ($isAdmin): ?>
          It also covers admin-only features like Loan Types management and approval workflow.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="help-layout">

    <!-- ── Sidebar nav ──────────────────────────────────── -->
    <nav class="help-nav">
      <div class="help-nav-title">On this page</div>
      <a href="#overview"   class="active"><i class="bi bi-grid-1x2"></i> Overview</a>
      <a href="#statuses">  <i class="bi bi-circle-half"></i> Loan Statuses</a>
      <?php if ($hasAdminAccess): ?>
      <hr>
      <div class="help-nav-title" style="padding-top:.25rem;">Admin</div>
      <a href="#loan-types"><i class="bi bi-tags"></i> Loan Types</a>
      <a href="#create">    <i class="bi bi-plus-circle"></i> Creating a Loan</a>
      <a href="#schedule">  <i class="bi bi-calendar3"></i> Amortization Schedule</a>
      <a href="#view">      <i class="bi bi-eye"></i> Viewing a Loan</a>
      <a href="#approve">   <i class="bi bi-check2-circle"></i> Approving a Loan</a>
      <a href="#payments">  <i class="bi bi-cash-coin"></i> Recording Payments</a>
      <a href="#edit">      <i class="bi bi-pencil"></i> Editing a Loan</a>
      <a href="#delete">    <i class="bi bi-trash"></i> Deleting a Loan</a>
      <a href="#print">     <i class="bi bi-printer"></i> Printing</a>
      <a href="#filters">   <i class="bi bi-funnel"></i> Filters &amp; Search</a>
      <?php endif; ?>
      <?php if ($hasMyLoans): ?>
      <hr>
      <div class="help-nav-title" style="padding-top:.25rem;">My Loans</div>
      <a href="#my-loans"><i class="bi bi-person-lines-fill"></i> My Loans Page</a>
      <?php endif; ?>
      <hr>
      <a href="#rbac"><i class="bi bi-shield-lock"></i> Access &amp; Permissions</a>
    </nav>

    <!-- ── Main content ─────────────────────────────────── -->
    <div class="help-content">

      <!-- ─ Overview ──────────────────────────────────── -->
      <div class="help-section" id="overview">
        <div class="help-section-header">
          <i class="bi bi-grid-1x2-fill"></i>
          <h2>Overview</h2>
        </div>
        <div class="help-section-body">
          <p>
            The <strong>Employee Loans</strong> module lets Urban Tradewell track salary loans issued to employees —
            from initial proposal through approval, payment collection, and full settlement.
            Each loan has a generated reference number, an amortization schedule, and a running balance
            that updates automatically as payments are recorded.
          </p>

          <h3>Pages in this module</h3>
          <table class="status-table">
            <thead><tr><th>Page</th><th>URL</th><th>Purpose</th></tr></thead>
            <tbody>
              <?php if ($hasAdminAccess): ?>
              <tr><td><strong>Loan List</strong></td><td><code>EMPLOYEE/index.php</code></td><td>Master list of all loans with stat cards, filters, and quick actions.</td></tr>
              <tr><td><strong>Create Loan</strong></td><td><code>EMPLOYEE/create.php</code></td><td>New loan form with employee lookup, schedule builder, and noted/approved-by fields.</td></tr>
              <tr><td><strong>View Loan</strong></td><td><code>EMPLOYEE/view.php?id=…</code></td><td>Full loan detail: header info, amortization table, payment history, and action buttons.</td></tr>
              <tr><td><strong>Edit Loan</strong></td><td><code>EMPLOYEE/edit.php?id=…</code></td><td>Edit a loan that is still in <em>Proposal</em> status.</td></tr>
              <tr><td><strong>Payments</strong></td><td><code>EMPLOYEE/payments.php?id=…</code></td><td>Record payments against individual schedule rows for an <em>Approved</em> loan.</td></tr>
              <tr><td><strong>Print</strong></td><td><code>EMPLOYEE/print.php?id=…</code></td><td>Printable amortization slip / voucher.</td></tr>
              <tr><td><strong>Loan Types</strong></td><td><code>EMPLOYEE/categories.php</code></td><td>Manage loan type categories (e.g. SSS, HDMF, Company).</td></tr>
              <?php endif; ?>
              <?php if ($hasMyLoans): ?>
              <tr><td><strong>My Loans</strong></td><td><code>EMPLOYEE/my_loans.php</code></td><td>Employee self-service view — shows only your own loan records.</td></tr>
              <?php endif; ?>
              <tr><td><strong>Help Manual</strong></td><td><code>EMPLOYEE/help-manual.php</code></td><td>This page.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ─ Loan Statuses ─────────────────────────────── -->
      <div class="help-section" id="statuses">
        <div class="help-section-header">
          <i class="bi bi-circle-half"></i>
          <h2>Loan Statuses</h2>
        </div>
        <div class="help-section-body">
          <p>Every loan moves through a defined lifecycle. The current status controls which actions are available.</p>
          <table class="status-table">
            <thead><tr><th>Status</th><th>Meaning</th><th>What's allowed</th></tr></thead>
            <tbody>
              <tr>
                <td><span class="sbadge s-proposal">Proposal</span></td>
                <td>Loan has been submitted but not yet approved.</td>
                <td>Edit, Delete, Approve, Print</td>
              </tr>
              <tr>
                <td><span class="sbadge s-approved">Approved</span></td>
                <td>Approved by management — payments can now be recorded.</td>
                <td>Record Payments, Print. <em>Edit and Delete are locked.</em></td>
              </tr>
              <tr>
                <td><span class="sbadge s-active">Active</span></td>
                <td>At least one payment has been received; balance is still outstanding.</td>
                <td>Record Payments, Print.</td>
              </tr>
              <tr>
                <td><span class="sbadge s-paid">Fully Paid</span></td>
                <td>Balance reached ₱0. Set automatically when the last payment is recorded.</td>
                <td>View, Print only.</td>
              </tr>
              <tr>
                <td><span class="sbadge s-cancelled">Cancelled</span></td>
                <td>Loan was voided.</td>
                <td>View, Print only.</td>
              </tr>
            </tbody>
          </table>
          <div class="callout callout-info">
            <i class="bi bi-info-circle-fill"></i>
            <div>A loan automatically flips to <strong>Fully Paid</strong> once the balance drops to ₱0 after a payment is recorded — no manual status change needed.</div>
          </div>
        </div>
      </div>

      <?php if ($hasAdminAccess): ?>

      <!-- ─ Loan Types ─────────────────────────────────── -->
      <div class="help-section" id="loan-types">
        <div class="help-section-header">
          <i class="bi bi-tags-fill"></i>
          <h2>Loan Types (Categories)</h2>
        </div>
        <div class="help-section-body">
          <p>
            Loan Types are the classifications used to categorise loans (e.g. <em>SSS Loan</em>, <em>HDMF / Pag-IBIG</em>,
            <em>Company Loan</em>). They also drive the auto-generated reference number prefix.
          </p>
          <h3>Managing loan types</h3>
          <p>Go to <strong>Loan Types</strong> from the top navigation on the Loans index page.</p>
          <ul class="bullet-list">
            <li><i class="bi bi-plus-circle-fill chk"></i> <span><strong>Add</strong> — fill in Code, Name, and optional Description, then click <em>Add Type</em>. The Code becomes the prefix of all reference numbers for that type (e.g. <code>SSS-2025-0001</code>).</span></li>
            <li><i class="bi bi-pencil-fill" style="color:#f59e0b;"></i> <span><strong>Edit</strong> — click the yellow pencil icon to open the edit modal and update name, code, or description.</span></li>
            <li><i class="bi bi-toggle-on chk"></i> <span><strong>Activate / Deactivate</strong> — click the green toggle icon. Inactive types won't appear in the loan creation form but remain on existing records.</span></li>
            <li><i class="bi bi-trash-fill" style="color:#ef4444;"></i> <span><strong>Delete</strong> — only available if no loans are linked to that type. The trash icon is greyed out otherwise.</span></li>
          </ul>
          <div class="callout callout-warn">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>Changing a loan type's <strong>Code</strong> does <em>not</em> retroactively update reference numbers on existing loans — only future loans will use the new code.</div>
          </div>
        </div>
      </div>

      <!-- ─ Creating a Loan ────────────────────────────── -->
      <div class="help-section" id="create">
        <div class="help-section-header">
          <i class="bi bi-plus-circle-fill"></i>
          <h2>Creating a Loan</h2>
        </div>
        <div class="help-section-body">
          <p>Click <strong>+ New Loan</strong> on the Loans index page. View-only users are redirected back to the list.</p>

          <h3>Step-by-step</h3>
          <ol class="step-list">
            <li><span class="step-num">1</span><div><strong>Select Loan Type</strong> — choose from active types. The reference number prefix is auto-filled (e.g. <code>SSS-2025-0001</code>). The sequence resets to <code>0001</code> each calendar year.</div></li>
            <li><span class="step-num">2</span><div><strong>Search for Employee</strong> — type at least 2 characters. A dropdown will show matching active employees. Select one to fill in their ID, name, and department automatically.</div></li>
            <li><span class="step-num">3</span><div><strong>Fill in loan details</strong> — Loan Date, Loan Amount, Number of Terms (months), Description, and optional Remarks.</div></li>
            <li><span class="step-num">4</span><div><strong>Choose Payment Frequency</strong> — this controls how the amortization schedule is generated. See the <a href="#schedule">Schedule section</a> below.</div></li>
            <li><span class="step-num">5</span><div><strong>Noted By / Approved By</strong> — optional employee lookups. These print on the amortization slip.</div></li>
            <li><span class="step-num">6</span><div><strong>Review the schedule</strong> — the schedule table is built client-side for preview. Verify due dates and amounts before saving.</div></li>
            <li><span class="step-num">7</span><div><strong>Submit</strong> — click <em>Save Loan</em>. The loan is created in <strong>Proposal</strong> status and you are redirected to the detail view.</div></li>
          </ol>

          <h3>Required fields</h3>
          <table class="field-table">
            <thead><tr><th>Field</th><th>Notes</th></tr></thead>
            <tbody>
              <tr><td><span class="field-name">Employee</span> <span class="req-badge">Required</span></td><td>Must be an active employee in <code>TBL_HREmployeeList</code>.</td></tr>
              <tr><td><span class="field-name">Loan Type</span> <span class="req-badge">Required</span></td><td>Determines the reference number prefix.</td></tr>
              <tr><td><span class="field-name">Loan Amount</span> <span class="req-badge">Required</span></td><td>Principal amount in Philippine Peso.</td></tr>
              <tr><td><span class="field-name">Terms</span> <span class="req-badge">Required</span></td><td>Number of monthly installments.</td></tr>
              <tr><td><span class="field-name">Loan Date</span></td><td>Defaults to today. Used as the start date for schedule generation.</td></tr>
              <tr><td><span class="field-name">Payment Frequency</span></td><td>Controls how many schedule rows are generated per month. Defaults to <em>Monthly (30th)</em>.</td></tr>
              <tr><td><span class="field-name">Reference Number</span></td><td>Auto-generated; can be overridden if needed.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ─ Amortization Schedule ──────────────────────── -->
      <div class="help-section" id="schedule">
        <div class="help-section-header">
          <i class="bi bi-calendar3"></i>
          <h2>Amortization Schedule</h2>
        </div>
        <div class="help-section-body">
          <p>
            The schedule is generated in the browser when you fill in Loan Amount, Terms, and Starting Date.
            It creates one row per installment and stores each row in <code>TBL_Loan_Statement</code> when the loan is saved.
          </p>

          <h3>Payment Frequency options</h3>
          <div class="freq-grid">
            <div class="freq-card">
              <div class="freq-card-title"><i class="bi bi-calendar-event"></i> 15th</div>
              <div class="freq-card-desc">One payment per month, due on the 15th. <em>Terms</em> rows generated.</div>
            </div>
            <div class="freq-card">
              <div class="freq-card-title"><i class="bi bi-calendar-event"></i> 30th</div>
              <div class="freq-card-desc">One payment per month, due on the last day (30th). <em>Terms</em> rows generated.</div>
            </div>
            <div class="freq-card">
              <div class="freq-card-title"><i class="bi bi-calendar-week"></i> 15th &amp; 30th</div>
              <div class="freq-card-desc">Two payments per month — semi-monthly. <em>Terms × 2</em> rows, each at half the monthly amount.</div>
            </div>
            <div class="freq-card">
              <div class="freq-card-title"><i class="bi bi-calendar2-week"></i> Weekly</div>
              <div class="freq-card-desc">Four payments per month. <em>Terms × 4</em> rows, each at one-quarter of the monthly amount.</div>
            </div>
            <div class="freq-card">
              <div class="freq-card-title"><i class="bi bi-input-cursor-text"></i> Specific Date (manual)</div>
              <div class="freq-card-desc">You manually add rows with custom due dates and amounts. Use for non-standard schedules.</div>
            </div>
          </div>

          <div class="callout callout-info">
            <i class="bi bi-info-circle-fill"></i>
            <div>
              For <strong>Specific Date</strong> mode, the schedule table becomes fully editable.
              Click <em>+ Add Row</em> to insert a line, then fill in the due date and principal for each installment.
              Totals must match the loan amount before saving.
            </div>
          </div>

          <h3>Schedule columns</h3>
          <table class="field-table">
            <thead><tr><th>Column</th><th>Description</th></tr></thead>
            <tbody>
              <tr><td><span class="field-name">Due Date</span></td><td>When this installment is due.</td></tr>
              <tr><td><span class="field-name">Amortization</span></td><td>Installment amount for this row (principal portion).</td></tr>
              <tr><td><span class="field-name">Balance</span></td><td>Remaining principal after this installment.</td></tr>
              <tr><td><span class="field-name">Payment Date</span></td><td>Filled when a payment is recorded against this row.</td></tr>
              <tr><td><span class="field-name">Amount Paid</span></td><td>Payment amount recorded.</td></tr>
              <tr><td><span class="field-name">Method</span></td><td>Payment method (Cash, Check, Bank Transfer, etc.).</td></tr>
              <tr><td><span class="field-name">Status</span></td><td><em>Paid</em> once a payment is linked; <em>Unpaid</em> otherwise.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ─ Viewing a Loan ────────────────────────────── -->
      <div class="help-section" id="view">
        <div class="help-section-header">
          <i class="bi bi-eye-fill"></i>
          <h2>Viewing a Loan</h2>
        </div>
        <div class="help-section-body">
          <p>
            Click the <strong>reference number</strong> or the <i class="bi bi-eye"></i> icon from the loans list to open the detail view.
            The detail page shows the loan header, a progress bar for how much has been paid, the full amortization schedule, and any standalone payments.
          </p>
          <h3>Action buttons (top-right)</h3>
          <ul class="bullet-list">
            <li><i class="bi bi-check2-circle chk"></i> <span><strong>Approve</strong> — visible only on <em>Proposal</em> loans. Opens a confirmation modal before committing.</span></li>
            <li><i class="bi bi-cash-coin" style="color:#6366f1;"></i> <span><strong>Record Payments</strong> — visible only on <em>Approved</em> loans. Navigates to <code>payments.php</code>.</span></li>
            <li><i class="bi bi-pencil-square" style="color:#f59e0b;"></i> <span><strong>Edit</strong> — visible only on <em>Proposal</em> loans. Navigates to <code>edit.php</code>.</span></li>
            <li><i class="bi bi-printer-fill" style="color:var(--primary);"></i> <span><strong>Print</strong> — opens the printable amortization slip in a new tab at any status.</span></li>
          </ul>
          <div class="callout callout-warn">
            <i class="bi bi-lock-fill"></i>
            <div>Once a loan is <strong>Approved</strong>, editing and deletion are permanently locked. Make sure all details are correct before approving.</div>
          </div>
        </div>
      </div>

      <!-- ─ Approving a Loan ──────────────────────────── -->
      <div class="help-section" id="approve">
        <div class="help-section-header">
          <i class="bi bi-check2-circle"></i>
          <h2>Approving a Loan</h2>
        </div>
        <div class="help-section-body">
          <p>
            Approval transitions a loan from <strong>Proposal → Approved</strong>, enabling payment recording.
            Only users with <em>full</em> access to the <code>employee_loans</code> module can approve.
          </p>
          <ol class="step-list">
            <li><span class="step-num">1</span><div>Open the loan detail page (<code>view.php?id=…</code>).</div></li>
            <li><span class="step-num">2</span><div>Click <strong>Approve Loan</strong> (top right). A confirmation dialog will appear.</div></li>
            <li><span class="step-num">3</span><div>Read the warning — approval is irreversible — then click <strong>Confirm Approval</strong>.</div></li>
            <li><span class="step-num">4</span><div>The page reloads and the status badge changes to <span class="sbadge s-approved" style="font-size:.75rem;">Approved</span>. The Edit button is now hidden.</div></li>
          </ol>
          <div class="callout callout-danger">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div><strong>Approval is one-way.</strong> There is no built-in "unapprove" action. If a loan was approved in error, contact your system administrator.</div>
          </div>
        </div>
      </div>

      <!-- ─ Recording Payments ────────────────────────── -->
      <div class="help-section" id="payments">
        <div class="help-section-header">
          <i class="bi bi-cash-coin"></i>
          <h2>Recording Payments</h2>
        </div>
        <div class="help-section-body">
          <p>
            Payments are recorded per schedule row. Each row can hold one payment. The loan balance updates instantly
            when a payment is saved.
          </p>
          <h3>How to record a payment</h3>
          <ol class="step-list">
            <li><span class="step-num">1</span><div>Open an <span class="sbadge s-approved" style="font-size:.75rem;">Approved</span> loan and click <strong>Record Payments</strong>.</div></li>
            <li><span class="step-num">2</span><div>On the payments page, find the schedule row you want to pay. Click the <strong>Record</strong> button on that row.</div></li>
            <li><span class="step-num">3</span><div>Fill in: <em>Payment Date</em>, <em>Amount</em>, <em>Method</em> (Cash / Check / Bank Transfer / Salary Deduction / Others), and optional <em>Reference No.</em></div></li>
            <li><span class="step-num">4</span><div>Click <strong>Save Payment</strong>. The row's status changes to <em>Paid</em> and the loan's PaidAmount / BalanceAmount are updated immediately.</div></li>
          </ol>
          <div class="callout callout-info">
            <i class="bi bi-info-circle-fill"></i>
            <div>If the balance reaches ₱0 after a payment, the loan status automatically flips to <strong>Fully Paid</strong>. No further payments can be recorded after that.</div>
          </div>
          <h3>Unlinking a payment</h3>
          <p>
            Each payment row has an <strong>Unlink</strong> button. This removes the payment record from the schedule row
            and reverses the paid/balance amounts on the loan header, effectively "undoing" that payment entry
            so it can be corrected and re-recorded.
          </p>
          <div class="callout callout-warn">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>Payments can only be recorded on <strong>Approved</strong> loans. Proposal, Fully Paid, and Cancelled loans do not allow payment entry.</div>
          </div>
        </div>
      </div>

      <!-- ─ Editing a Loan ────────────────────────────── -->
      <div class="help-section" id="edit">
        <div class="help-section-header">
          <i class="bi bi-pencil-fill"></i>
          <h2>Editing a Loan</h2>
        </div>
        <div class="help-section-body">
          <p>
            Editing is only available while a loan is in <strong>Proposal</strong> status.
            Once approved, the record is locked and the Edit button disappears.
          </p>
          <ul class="bullet-list">
            <li><i class="bi bi-check-circle-fill chk"></i> <span>You can change the loan amount, terms, frequency, description, remarks, noted-by, and approved-by fields.</span></li>
            <li><i class="bi bi-check-circle-fill chk"></i> <span>The amortization schedule can be rebuilt by adjusting terms/amount and letting the generator re-run.</span></li>
            <li><i class="bi bi-x-circle-fill" style="color:#ef4444;"></i> <span>You cannot change the Employee or Loan Type after creation. Delete and recreate if those need to change.</span></li>
          </ul>
          <div class="callout callout-warn">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>Saving an edit <strong>replaces</strong> all existing schedule rows for that loan with the newly generated ones. Any manual schedule adjustments from a previous save will be lost.</div>
          </div>
        </div>
      </div>

      <!-- ─ Deleting a Loan ────────────────────────────── -->
      <div class="help-section" id="delete">
        <div class="help-section-header">
          <i class="bi bi-trash-fill"></i>
          <h2>Deleting a Loan</h2>
        </div>
        <div class="help-section-body">
          <p>
            Deletion is only allowed on loans in <strong>Proposal</strong> status. The delete action is available
            from the loans list via the trash icon on each row.
          </p>
          <ul class="bullet-list">
            <li><i class="bi bi-check-circle-fill chk"></i> <span>Deleting a Proposal loan removes the loan header and all its schedule rows.</span></li>
            <li><i class="bi bi-x-circle-fill" style="color:#ef4444;"></i> <span>Approved, Fully Paid, and Cancelled loans cannot be deleted.</span></li>
          </ul>
          <div class="callout callout-danger">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>Deletion is permanent and cannot be undone. A confirmation prompt appears before anything is removed.</div>
          </div>
        </div>
      </div>

      <!-- ─ Printing ───────────────────────────────────── -->
      <div class="help-section" id="print">
        <div class="help-section-header">
          <i class="bi bi-printer-fill"></i>
          <h2>Printing</h2>
        </div>
        <div class="help-section-body">
          <p>
            Click <strong>Print</strong> from the loan detail view or loans list to open the printable amortization slip
            in a new tab (<code>print.php?id=…</code>). The print layout includes:
          </p>
          <ul class="bullet-list">
            <li><i class="bi bi-check2 chk"></i> <span>Employee name, department, and position</span></li>
            <li><i class="bi bi-check2 chk"></i> <span>Loan type, reference number, date, and amount</span></li>
            <li><i class="bi bi-check2 chk"></i> <span>Full amortization schedule with due dates and amounts</span></li>
            <li><i class="bi bi-check2 chk"></i> <span>Noted By and Approved By signature lines</span></li>
            <li><i class="bi bi-check2 chk"></i> <span>Government numbers (SSS, HDMF, TIN, PhilHealth) where available</span></li>
          </ul>
          <p>Use your browser's <strong>Ctrl+P</strong> (or <strong>⌘+P</strong> on Mac) to send to a printer or save as PDF.</p>
        </div>
      </div>

      <!-- ─ Filters ────────────────────────────────────── -->
      <div class="help-section" id="filters">
        <div class="help-section-header">
          <i class="bi bi-funnel-fill"></i>
          <h2>Filters &amp; Search</h2>
        </div>
        <div class="help-section-body">
          <p>The loans list has a filter bar at the top of the table. All filters work together — you can combine them to narrow results.</p>
          <table class="field-table">
            <thead><tr><th>Filter</th><th>Searches across</th></tr></thead>
            <tbody>
              <tr><td><span class="field-name">Search</span></td><td>Reference number, employee last name, employee first name</td></tr>
              <tr><td><span class="field-name">Loan Type</span></td><td>Filter by a specific loan type category</td></tr>
              <tr><td><span class="field-name">Status</span></td><td>Proposal / Approved / Active / Fully Paid / Cancelled</td></tr>
              <tr><td><span class="field-name">Department</span></td><td>Employee department</td></tr>
              <tr><td><span class="field-name">Branch</span></td><td>Employee branch location</td></tr>
            </tbody>
          </table>
          <p>Click <strong>Search</strong> to apply, or <strong>Reset</strong> to clear all filters.</p>
        </div>
      </div>

      <?php endif; // hasAdminAccess ?>

      <?php if ($hasMyLoans): ?>

      <!-- ─ My Loans ───────────────────────────────────── -->
      <div class="help-section" id="my-loans">
        <div class="help-section-header">
          <i class="bi bi-person-lines-fill"></i>
          <h2>My Loans</h2>
        </div>
        <div class="help-section-body">
          <p>
            <strong>My Loans</strong> (<code>EMPLOYEE/my_loans.php</code>) is the self-service view for employees.
            It shows only <em>your own</em> loan records — you cannot see or modify other employees' loans here.
          </p>
          <ul class="bullet-list">
            <li><i class="bi bi-eye chk"></i> <span>You can view your loan details, amortization schedule, and payment history.</span></li>
            <li><i class="bi bi-printer chk"></i> <span>You can print your own loan slip.</span></li>
            <li><i class="bi bi-x-circle-fill" style="color:#ef4444;"></i> <span>You cannot create, edit, approve, or record payments — those are admin actions.</span></li>
          </ul>
          <h3>Summary cards</h3>
          <p>The top of the page shows four stat cards for your loans: Total loans, Proposals pending, Approved / Active loans, and your current Outstanding Balance.</p>
          <h3>Filters</h3>
          <p>You can filter your own loans by <em>Search</em> (reference number), <em>Status</em>, and <em>Loan Type</em>.</p>
        </div>
      </div>

      <?php endif; ?>

      <!-- ─ Access & Permissions ────────────────────────── -->
      <div class="help-section" id="rbac">
        <div class="help-section-header">
          <i class="bi bi-shield-lock-fill"></i>
          <h2>Access &amp; Permissions</h2>
        </div>
        <div class="help-section-body">
          <p>
            Access to the Employee Loans module is controlled by the RBAC system. There are two module keys
            and three permission levels:
          </p>
          <table class="field-table" style="margin-bottom:1rem;">
            <thead><tr><th>Module Key</th><th>Who uses it</th></tr></thead>
            <tbody>
              <tr><td><span class="field-name">employee_loans</span></td><td>Admins and HR staff — full loan management (create, approve, pay, delete).</td></tr>
              <tr><td><span class="field-name">my_loans</span></td><td>Regular employees — read-only view of their own loans.</td></tr>
            </tbody>
          </table>

          <h3>Permission levels</h3>
          <div class="access-grid">
            <div class="access-col-header">Action</div>
            <div class="access-col-header">View Only</div>
            <div class="access-col-header">Full Access</div>

            <div class="access-cell">View loan list &amp; details</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Print loan slip</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Create new loan</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Edit a Proposal loan</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Approve a loan</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Record payments</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Delete a Proposal loan</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>

            <div class="access-cell">Manage Loan Types</div>
            <div class="access-cell"><i class="bi bi-x-circle-fill nchk"></i> No</div>
            <div class="access-cell"><i class="bi bi-check-circle-fill chk"></i> Yes</div>
          </div>

          <div class="callout callout-info">
            <i class="bi bi-info-circle-fill"></i>
            <div>
              Permissions are managed in the RBAC admin panel under <strong>dbo.rbac_modules</strong>.
              Contact your system administrator to adjust access levels.
              View-only users see all data but all write buttons are disabled or redirect back to the list.
            </div>
          </div>
        </div>
      </div>

    </div><!-- /help-content -->
  </div><!-- /help-layout -->
</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
// Highlight active nav link on scroll
(function () {
  const links = document.querySelectorAll('.help-nav a[href^="#"]');
  const sections = Array.from(links).map(l => document.querySelector(l.getAttribute('href'))).filter(Boolean);

  function onScroll() {
    let current = sections[0];
    sections.forEach(s => {
      if (window.scrollY >= s.offsetTop - 120) current = s;
    });
    links.forEach(l => {
      l.classList.toggle('active', l.getAttribute('href') === '#' + current.id);
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
</script>
</body>
</html>
