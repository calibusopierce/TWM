<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check(['Admin', 'Administrator', 'HR']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
global $pdo;
if ($pdo) rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
if (!rbac_can('careers_admin') && !rbac_can('view_applications') && !rbac_can('uniform_inventory') && !rbac_can('employee_list')) {
    header('Location: ' . base_url('help-manual.php')); exit();
}
$topbar_page = 'help';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Manual — HR · Tradewell</title>
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
    <div class="hn-title">📖 HR Help</div>

    <div class="hn-group" data-group="careers">
      <button class="hn-group-toggle" onclick="toggleGroup('careers')"><span>💼 Careers Admin</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-careers">
        <a href="#careers-overview" class="hn-link"><i class="bi bi-briefcase"></i> Overview</a>
        <a href="#careers-add"      class="hn-link"><i class="bi bi-plus-circle"></i> Adding a Job Post</a>
        <a href="#careers-edit"     class="hn-link"><i class="bi bi-pencil"></i> Editing &amp; Deleting</a>
        <a href="#careers-public"   class="hn-link"><i class="bi bi-globe"></i> Public Careers Page</a>
      </div>
    </div>

    <div class="hn-group" data-group="applications">
      <button class="hn-group-toggle" onclick="toggleGroup('applications')"><span>📋 Applications</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-applications">
        <a href="#app-overview"  class="hn-link"><i class="bi bi-people"></i> Applications Page</a>
        <a href="#app-status"    class="hn-link"><i class="bi bi-tag"></i> Application Statuses</a>
        <a href="#app-files"     class="hn-link"><i class="bi bi-paperclip"></i> Viewing Files</a>
        <a href="#app-apply"     class="hn-link"><i class="bi bi-file-earmark-person"></i> Submitting (Applicant)</a>
      </div>
    </div>

    <div class="hn-group" data-group="uniform">
      <button class="hn-group-toggle" onclick="toggleGroup('uniform')"><span>🧥 Uniform Inventory</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-uniform">
        <a href="#uni-overview"   class="hn-link"><i class="bi bi-bag"></i> Overview</a>
        <a href="#uni-items"      class="hn-link"><i class="bi bi-plus-circle"></i> Adding Items</a>
        <a href="#uni-issue"      class="hn-link"><i class="bi bi-arrow-up-circle"></i> Issuing Uniforms</a>
        <a href="#uni-return"     class="hn-link"><i class="bi bi-arrow-down-circle"></i> Returning Uniforms</a>
        <a href="#uni-history"    class="hn-link"><i class="bi bi-clock-history"></i> Issuance History</a>
        <a href="#uni-po"         class="hn-link"><i class="bi bi-file-earmark-text"></i> Purchase Orders</a>
        <a href="#uni-receiving"  class="hn-link"><i class="bi bi-box-arrow-in-down"></i> Receiving</a>
        <a href="#uni-print"      class="hn-link"><i class="bi bi-printer"></i> Printing</a>
      </div>
    </div>

    <div class="hn-group" data-group="employees">
      <button class="hn-group-toggle" onclick="toggleGroup('employees')"><span>👤 Employee Directory</span><i class="bi bi-chevron-down toggle-caret"></i></button>
      <div class="hn-group-body" id="grp-employees">
        <a href="#emp-list"        class="hn-link"><i class="bi bi-people"></i> Employee List</a>
        <a href="#emp-inactive"    class="hn-link"><i class="bi bi-person-dash"></i> Inactive Employees</a>
        <a href="#emp-blacklisted" class="hn-link"><i class="bi bi-person-x"></i> Blacklisted Employees</a>
      </div>
    </div>
  </nav>

  <main class="help-main">
    <div class="help-hero">
      <div class="help-hero-title">HR <span>Help Manual</span></div>
      <div class="help-hero-sub">A guide to managing job postings, reviewing applicants, tracking uniform inventory, and maintaining the employee directory — all from one place.</div>
      <div class="help-hero-chips">
        <span class="help-chip"><i class="bi bi-briefcase"></i> Careers Admin</span>
        <span class="help-chip"><i class="bi bi-people"></i> Applications</span>
        <span class="help-chip"><i class="bi bi-bag-fill"></i> Uniform Inventory</span>
        <span class="help-chip"><i class="bi bi-file-earmark-text"></i> Purchase Orders</span>
        <span class="help-chip"><i class="bi bi-person-badge"></i> Employee Directory</span>
      </div>
    </div>

    <!-- ── CAREERS ADMIN ─────────────────────────────────── -->
    <div class="help-section" id="careers-overview">
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

    <div class="help-section" id="careers-add">
      <div class="help-section-header"><span class="help-section-icon">➕</span><div class="help-section-title">Adding a New Job Post</div></div>
      <div class="help-intro">Click <strong>Add New Career</strong> at the top right of the Careers Admin page to open the form.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Enter the <strong>Job Title</strong> — this is what applicants will see on the public page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Set the <strong>Status</strong> — Active makes it visible to applicants immediately. Inactive keeps it hidden until you're ready.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Fill in the <strong>Department</strong> and <strong>Location</strong> so applicants know where the job is.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Write the <strong>Job Description</strong> — what the job involves day to day.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Write the <strong>Qualifications</strong> — what the applicant needs (education, experience, skills).</div></div>
        <div class="step-item"><div class="step-num">6</div><div class="step-text">Upload a <strong>Job Image</strong> (optional) — appears as a banner on the job details page.</div></div>
        <div class="step-item"><div class="step-num">7</div><div class="step-text">Click <strong>Save</strong> — the posting is now live if you set it to Active.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Save a posting as <strong>Inactive</strong> first to draft it, then switch it to Active when you're ready to publish.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="careers-edit">
      <div class="help-section-header"><span class="help-section-icon">✏️</span><div class="help-section-title">Editing &amp; Deleting Job Posts</div></div>
      <div class="help-intro">Click the <strong>pencil icon ✏️</strong> on any row to edit that job posting — all fields can be changed including the image. Click <strong>Save</strong> when done. Click the <strong>trash icon 🗑️</strong> to permanently delete a posting — a confirmation prompt will appear first.</div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Deleting a job post is <strong>permanent</strong> and cannot be undone. If you just want to hide it from applicants, set its Status to <strong>Inactive</strong> instead.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="careers-public">
      <div class="help-section-header"><span class="help-section-icon">🌐</span><div class="help-section-title">Public Careers Page</div></div>
      <div class="help-intro">The <strong>public careers page</strong> shows all <strong>Active</strong> job postings as cards. Applicants can click any card to view full job details and submit their application.</div>
      <table class="col-table">
        <thead><tr><th>Element</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Job Cards</td><td>Each card shows the job title and location. Only Active postings appear here.</td></tr>
          <tr><td>View Details</td><td>Opens the full job details page with description, qualifications, and an Apply button</td></tr>
          <tr><td>Apply Button</td><td>Opens the application form where applicants fill in their info and upload documents by category</td></tr>
          <tr><td>Data Privacy Notice</td><td>Applicants must read and accept the Terms &amp; Conditions before the form is shown — required by RA 10173</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>Setting a posting to <strong>Active</strong> makes it appear on the public page <strong>immediately</strong>. Setting it to <strong>Inactive</strong> removes it right away.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── APPLICATIONS ─────────────────────────────────── -->
    <div class="help-section" id="app-overview">
      <div class="help-section-header"><span class="help-section-icon">👥</span><div class="help-section-title">Applications Page</div></div>
      <div class="help-intro">Shows all job applications submitted through the public careers page, organized into <strong>tabs by stage</strong> — Pending, Evaluating, Interview, Hired, and Rejected — so you can focus on one group at a time.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Applicant</td><td>The applicant's full name and the department they applied to</td></tr>
          <tr><td>Contact</td><td>The applicant's email address and phone number</td></tr>
          <tr><td>Position</td><td>Which job posting they applied for</td></tr>
          <tr><td>Date Applied</td><td>When they submitted their application</td></tr>
          <tr><td>Files</td><td>Attached files grouped by category (e.g. Resume/CV, NBI Clearance) — click to view or download each file</td></tr>
          <tr><td>Interview</td><td>Shows the scheduled interview date, time, and office address if one has been set</td></tr>
          <tr><td>Status</td><td>The current stage — click the status badge to update it</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Use the <strong>date range</strong>, <strong>status</strong>, and <strong>search</strong> filters at the top to quickly find specific applicants.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Interview Tab:</strong> When moving an applicant to <strong>For Interview</strong> or <strong>Final Interview</strong>, you'll be asked to fill in the interview date &amp; time, office address, and HR contact. These details appear in the applicant's row under the Interview column.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span><strong>Department Assignment:</strong> When moving an applicant out of Pending, assign them a department so HR staff from that department can see and manage the application.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="app-status">
      <div class="help-section-header"><span class="help-section-icon">🏷️</span><div class="help-section-title">Application Statuses</div></div>
      <div class="help-intro">Each application has a <strong>status</strong> that tracks where the applicant is in the hiring process. Click the status badge on any applicant row to update it.</div>
      <table class="col-table">
        <thead><tr><th>Status</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Pending</td><td>Newly submitted — not yet reviewed. All new applications land here first. Visible to all HR staff regardless of department.</td></tr>
          <tr><td>Evaluating</td><td>Currently being reviewed by HR. Once moved here, only visible to the assigned department's HR.</td></tr>
          <tr><td>For Interview</td><td>Applicant shortlisted and scheduled for a first (initial) interview</td></tr>
          <tr><td>Re-schedule Interview</td><td>The original interview was moved — a new schedule is being arranged</td></tr>
          <tr><td>Final Interview</td><td>Applicant passed the first interview and is being called for a final round</td></tr>
          <tr><td>Final Interview Rescheduled</td><td>The final interview was moved — a new schedule is being arranged</td></tr>
          <tr><td>Hired</td><td>Applicant has been accepted and will be onboarded</td></tr>
          <tr><td>Rejected</td><td>Applicant was not selected for this position</td></tr>
        </tbody>
      </table>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="app-files">
      <div class="help-section-header"><span class="help-section-icon">📎</span><div class="help-section-title">Viewing Applicant Files</div></div>
      <div class="help-intro">Each applicant row shows a <strong>files button</strong> with a count (e.g. "3 files"). Clicking it opens a modal showing all uploaded documents grouped by their category.</div>
      <table class="col-table">
        <thead><tr><th>Element</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Category Header</td><td>Files are grouped under their category label (e.g. Resume/CV, NBI Clearance) so HR can quickly find the right document</td></tr>
          <tr><td>File Row</td><td>Shows the file icon (color-coded by type), cleaned file name, and file type label</td></tr>
          <tr><td>Open Link</td><td>Click any file row to open the document in a new tab for viewing or downloading</td></tr>
          <tr><td>File Icons</td><td>🔴 PDF · 🔵 DOC/DOCX · 🟡 JPG/PNG images · 🟢 Excel files</td></tr>
        </tbody>
      </table>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>File names are automatically <strong>cleaned up</strong> for display — the system strips the unique ID suffix added during upload so HR sees a readable name.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="app-apply">
      <div class="help-section-header"><span class="help-section-icon">📝</span><div class="help-section-title">Submitting a Job Application (Applicant View)</div></div>
      <div class="help-intro">When an applicant clicks <strong>Apply</strong> on a job posting, they go through a two-step process — accepting the <strong>Data Privacy Notice</strong> first, then filling out the application form.</div>
      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin-bottom:.5rem;">Step 1 — Data Privacy Notice</p>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">A <strong>Data Privacy Notice modal</strong> appears. The applicant must scroll through and read the notice.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">The applicant checks the <strong>"I have read and understood"</strong> checkbox to enable the Agree button.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Clicking <strong>"I Agree &amp; Proceed"</strong> records their consent and reveals the application form. Clicking <strong>Decline</strong> redirects them back to the careers page.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>T&amp;C acceptance is recorded with the exact <strong>date and time</strong> of consent — ensuring compliance with <strong>RA 10173 (Data Privacy Act of 2012)</strong>.</span></div>
      <p style="font-size:.88rem;font-weight:700;color:var(--text-primary);margin:.85rem 0 .5rem;">Step 2 — Application Form</p>
      <table class="col-table">
        <thead><tr><th>Field</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td>Full Name *</td><td>The applicant's complete name</td></tr>
          <tr><td>Email Address *</td><td>Used for confirmation email and HR contact</td></tr>
          <tr><td>Phone Number</td><td>Optional contact number</td></tr>
          <tr><td>Position Applied For</td><td>Pre-filled from the job posting — cannot be changed by the applicant</td></tr>
          <tr><td>Documents *</td><td>File uploads — each file must have a category selected. At least one file is required.</td></tr>
        </tbody>
      </table>
      <table class="col-table">
        <thead><tr><th>File Upload Rule</th><th>Details</th></tr></thead>
        <tbody>
          <tr><td>Category Required</td><td>Every file must have a category selected (e.g. Resume/CV, NBI Clearance, Police Clearance)</td></tr>
          <tr><td>Allowed File Types</td><td>PDF, DOC, DOCX, JPG, JPEG, PNG</td></tr>
          <tr><td>Max File Size</td><td>10 MB per file</td></tr>
          <tr><td>Max Files</td><td>Up to 10 files per application</td></tr>
          <tr><td>Add More Files</td><td>Click <strong>"+ Add Another Document"</strong> to add more file slots</td></tr>
          <tr><td>Remove a File</td><td>Click the <strong>✕</strong> button on any slot to remove it. The last slot resets instead of being removed.</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>After a successful submission, the applicant receives a <strong>confirmation email</strong> listing all submitted files and their categories, along with the date and time of submission.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── UNIFORM INVENTORY ─────────────────────────────── -->
    <div class="help-section" id="uni-overview">
      <div class="help-section-header"><span class="help-section-icon">🧥</span><div class="help-section-title">Uniform Inventory — Overview</div></div>
      <div class="help-intro">The <strong>Uniform Inventory</strong> module manages the full lifecycle of company uniforms — from stocking items and issuing them to employees, to creating Purchase Orders for restocking and receiving deliveries from suppliers.</div>
      <table class="col-table">
        <thead><tr><th>Feature</th><th>What it does</th></tr></thead>
        <tbody>
          <tr><td>Stat Cards</td><td>Live summary: total uniform types, total stock, low-stock items, and out-of-stock items</td></tr>
          <tr><td>Inventory Tab</td><td>Full list of all uniform items with stock levels and status badges</td></tr>
          <tr><td>History Tab</td><td>Searchable log of every issue and return transaction</td></tr>
          <tr><td>Purchase Orders</td><td>Create and track POs for ordering uniforms from suppliers</td></tr>
          <tr><td>Receiving</td><td>Record actual delivery of items against an existing PO — updates stock automatically</td></tr>
          <tr><td>Printing</td><td>Print issuance slips, PO documents, and receiving reports directly from the system</td></tr>
          <tr><td>Department Filter</td><td>The page respects your active department — each department only sees their own uniforms</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Set a <strong>Low Stock Alert</strong> threshold on each item. Items at or below the threshold turn <strong>yellow</strong>; items at zero turn <strong>red</strong>.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-items">
      <div class="help-section-header"><span class="help-section-icon">➕</span><div class="help-section-title">Adding &amp; Editing Uniform Items</div></div>
      <div class="help-intro">Uniform items are the master catalog — each entry represents a specific uniform type and size combination (e.g. "Polo Shirt · L").</div>
      <table class="col-table">
        <thead><tr><th>Field</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td>Item Name *</td><td>Name of the uniform (e.g. "Polo Shirt", "Safety Shoes")</td></tr>
          <tr><td>Category</td><td>Group items together (e.g. "Tops", "Bottoms", "Footwear", "Safety Gear")</td></tr>
          <tr><td>Size</td><td>Select from XS, S, M, L, XL, XXL, XXXL, or Free Size</td></tr>
          <tr><td>Department</td><td>Assign to a specific department, or leave blank to show for all departments</td></tr>
          <tr><td>Stock Quantity</td><td>Current number of items in storage</td></tr>
          <tr><td>Low Stock Alert At</td><td>When stock drops to this number or below, the item is flagged yellow as "Low Stock"</td></tr>
          <tr><td>Description</td><td>Optional notes (e.g. brand, material, supplier info)</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Click <strong>Add Uniform Item</strong> in the top right of the page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Fill in the item name, category, size, and starting stock quantity.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Set the <strong>Low Stock Alert</strong> threshold — the number at which the system warns you stock is running low.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Save Item</strong>. The item appears in the inventory table immediately.</div></div>
      </div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Deleting an item <strong>permanently removes</strong> it and all its transaction history. Deactivate items instead of deleting them whenever possible.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-issue">
      <div class="help-section-header"><span class="help-section-icon">⬆️</span><div class="help-section-title">Issuing Uniforms to Employees</div></div>
      <div class="help-intro">When an employee receives a uniform from stock, record it as an <strong>Issue</strong> transaction. This automatically deducts the quantity from the item's stock count.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">On the <strong>Inventory</strong> tab, find the item you want to issue and click <strong>Issue/Return</strong>.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">In the modal, make sure <strong>Issue</strong> is selected (highlighted in blue).</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Enter the employee's full name, their department, and the quantity being issued.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Add any optional remarks and click <strong>Confirm</strong>.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>The system checks stock before saving. If you try to issue more than what's available, you'll see an error and the transaction won't be recorded.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-return">
      <div class="help-section-header"><span class="help-section-icon">⬇️</span><div class="help-section-title">Returning Uniforms</div></div>
      <div class="help-intro">When an employee returns a uniform (e.g. when resigning or exchanging sizes), record it as a <strong>Return</strong> transaction. This adds the quantity back to the item's stock count.</div>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">On the <strong>Inventory</strong> tab, find the item being returned and click <strong>Issue/Return</strong>.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">In the modal, click <strong>Return</strong> (it turns green when selected).</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Enter the employee's name, department, quantity being returned, and any remarks.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>Confirm</strong>. The stock count updates automatically.</div></div>
      </div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-history">
      <div class="help-section-header"><span class="help-section-icon">🕐</span><div class="help-section-title">Issuance History</div></div>
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
    <hr class="help-divider">

    <div class="help-section" id="uni-po">
      <div class="help-section-header"><span class="help-section-icon">📄</span><div class="help-section-title">Purchase Orders (PO)</div></div>
      <div class="help-intro">Create and track orders for uniform restocking. A PO is a formal request sent to a supplier listing which items need to be ordered, in what quantities, and at what price.</div>
      <table class="col-table">
        <thead><tr><th>Column / Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>PO Number</td><td>A unique auto-generated reference number for this purchase order</td></tr>
          <tr><td>Supplier</td><td>The vendor the order is being sent to</td></tr>
          <tr><td>Date Created</td><td>When the PO was raised</td></tr>
          <tr><td>Items</td><td>The list of uniform items being ordered, with size, quantity, and unit price per line</td></tr>
          <tr><td>Total Amount</td><td>The computed total cost of all items in the PO</td></tr>
          <tr><td>Status</td><td><strong>Pending</strong> = not yet delivered · <strong>Partially Received</strong> = some items delivered · <strong>Fully Received</strong> = all items received</td></tr>
          <tr><td>Created By</td><td>The HR or Admin user who created the PO</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Click <strong>Create PO</strong> at the top of the Purchase Orders page.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Select or type the <strong>Supplier</strong> name.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">Add line items — select the <strong>uniform item</strong>, its <strong>size</strong>, the <strong>quantity</strong> to order, and the <strong>unit price</strong>.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Click <strong>+ Add Line</strong> to add more items. Remove lines with the <strong>✕</strong> button.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Review the total amount at the bottom, then click <strong>Save PO</strong>. Status starts as <strong>Pending</strong>.</div></div>
      </div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>A PO does <strong>not</strong> automatically update stock levels. Stock is only updated when items are received through the <strong>Receiving</strong> page.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Once a PO is fully received, it is <strong>closed</strong> and can no longer be edited.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-receiving">
      <div class="help-section-header"><span class="help-section-icon">📦</span><div class="help-section-title">Receiving</div></div>
      <div class="help-intro">Record the actual delivery of uniform items from a supplier against an existing PO. When you confirm receipt, quantities are automatically added to the inventory stock count.</div>
      <table class="col-table">
        <thead><tr><th>Column / Field</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>PO Number</td><td>The Purchase Order this delivery is for</td></tr>
          <tr><td>Supplier</td><td>Which supplier made the delivery</td></tr>
          <tr><td>Received Date</td><td>The date the items were physically received</td></tr>
          <tr><td>Ordered Qty</td><td>How many were originally ordered on the PO</td></tr>
          <tr><td>Previously Received</td><td>How many have already been received from prior deliveries on the same PO</td></tr>
          <tr><td>Received Now</td><td>The quantity being received in this specific delivery — enter the actual count</td></tr>
          <tr><td>Remarks</td><td>Optional notes (e.g. "2 units damaged on arrival", "partial delivery")</td></tr>
        </tbody>
      </table>
      <div class="step-list">
        <div class="step-item"><div class="step-num">1</div><div class="step-text">Go to the <strong>Receiving</strong> page and click <strong>Receive Items</strong>, or click the <strong>Receive</strong> action button on an open PO.</div></div>
        <div class="step-item"><div class="step-num">2</div><div class="step-text">Select the <strong>PO Number</strong> from the list. Only POs with Pending or Partially Received status appear.</div></div>
        <div class="step-item"><div class="step-num">3</div><div class="step-text">The PO line items load automatically. Enter the <strong>Received Now</strong> quantity for each item based on what was physically delivered.</div></div>
        <div class="step-item"><div class="step-num">4</div><div class="step-text">Add any <strong>Remarks</strong> for discrepancies, damage, or partial deliveries.</div></div>
        <div class="step-item"><div class="step-num">5</div><div class="step-text">Click <strong>Confirm Receipt</strong>. Stock levels update immediately and the PO status updates automatically.</div></div>
      </div>
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>You can record <strong>multiple partial deliveries</strong> against the same PO. Each receiving entry is logged separately with date, quantities, and who confirmed receipt.</span></div>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>You cannot receive more items than the quantity on the PO. Extra units beyond what was ordered must be handled through a separate PO or manual stock adjustment.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="uni-print">
      <div class="help-section-header"><span class="help-section-icon">🖨️</span><div class="help-section-title">Printing</div></div>
      <div class="help-intro">The system provides <strong>print-ready templates</strong> for key Uniform Inventory documents, formatted for clean professional output.</div>
      <table class="col-table">
        <thead><tr><th>Document</th><th>How to print it</th></tr></thead>
        <tbody>
          <tr><td>Issuance Slip</td><td>After confirming an Issue transaction, click <strong>Print Slip</strong> in the confirmation modal. Shows employee name, item, size, quantity, date, and HR staff who processed it.</td></tr>
          <tr><td>Purchase Order</td><td>On the PO list or detail page, click <strong>Print PO</strong>. Shows PO number, supplier, line items, quantities, unit prices, total amount, and date.</td></tr>
          <tr><td>Receiving Report</td><td>After confirming a receiving entry, click <strong>Print Receiving Report</strong>. Shows the PO reference, supplier, items received, quantities, date, and who confirmed receipt.</td></tr>
        </tbody>
      </table>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>All print templates open in a <strong>new browser tab</strong> formatted for standard paper size. Use Ctrl+P / Cmd+P to print or save as PDF.</span></div>
    </div>
    <hr class="help-divider">

    <!-- ── EMPLOYEE DIRECTORY ─────────────────────────── -->
    <div class="help-section" id="emp-list">
      <div class="help-section-header"><span class="help-section-icon">👤</span><div class="help-section-title">Employee List (Active)</div></div>
      <div class="help-intro">The <strong>Employee List</strong> is the master directory of all currently active employees. Admin and HR staff can view, search, add, and manage employee records. The list is filtered by your active department — Admins can switch departments to view all staff.</div>
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
          <tr><td>Actions</td><td>✏️ Edit details · 📋 View profile · ⬇️ Move to Inactive · 🚫 Move to Blacklist</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span>Moving an employee to <strong>Inactive</strong> or <strong>Blacklisted</strong> does not delete their record — it transfers them to a separate list so their history is preserved.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="emp-inactive">
      <div class="help-section-header"><span class="help-section-icon">😴</span><div class="help-section-title">Inactive Employees</div></div>
      <div class="help-intro">Shows staff who are no longer actively employed but whose records are retained — resigned employees, those on extended leave, or former contractors. Their data is kept for reference and can be reactivated if they return.</div>
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
      <div class="tip-box success"><i class="bi bi-info-circle-fill"></i><span>To <strong>reactivate</strong> an employee (e.g. a rehire), click the <strong>Reactivate</strong> button. Their record moves back to the Active Employee List with their original details intact.</span></div>
    </div>
    <hr class="help-divider">

    <div class="help-section" id="emp-blacklisted">
      <div class="help-section-header"><span class="help-section-icon">🚫</span><div class="help-section-title">Blacklisted Employees</div></div>
      <div class="help-intro">A restricted list of individuals who are not eligible for rehire — employees terminated for cause, those with serious conduct violations, or individuals identified as a risk. Access is limited to <strong>Admin and HR</strong> only.</div>
      <table class="col-table">
        <thead><tr><th>Column</th><th>What it means</th></tr></thead>
        <tbody>
          <tr><td>Employee Name</td><td>Full name of the blacklisted individual</td></tr>
          <tr><td>Employee ID</td><td>Their original employee ID</td></tr>
          <tr><td>Department</td><td>The department they belonged to when blacklisted</td></tr>
          <tr><td>Position</td><td>Their last held position</td></tr>
          <tr><td>Date Blacklisted</td><td>When they were added to the blacklist</td></tr>
          <tr><td>Reason</td><td>The documented reason (e.g. Terminated for Cause, Theft, Serious Misconduct)</td></tr>
          <tr><td>Blacklisted By</td><td>The Admin or HR user who added them to the list</td></tr>
          <tr><td>Actions</td><td>👁️ View full record · 📝 Edit reason/notes (Admin only)</td></tr>
        </tbody>
      </table>
      <div class="tip-box warn"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Blacklisted employees cannot be reactivated</strong> through the normal process. Removal requires Admin-level authorization. If a blacklisted name appears in the Careers applicant list, HR will be alerted automatically.</span></div>
      <div class="tip-box"><i class="bi bi-lightbulb-fill"></i><span>Always document a clear and accurate <strong>reason</strong> when blacklisting. This record may be referenced for future screening, legal compliance, or internal audits.</span></div>
    </div>

  </main>
</div>
<div class="footer">HR Help Manual · Tradewell · <?= date('Y-m-d') ?></div>
<script>
const sectionGroup={
  'careers-overview':'careers','careers-add':'careers','careers-edit':'careers','careers-public':'careers',
  'app-overview':'applications','app-status':'applications','app-files':'applications','app-apply':'applications',
  'uni-overview':'uniform','uni-items':'uniform','uni-issue':'uniform','uni-return':'uniform',
  'uni-history':'uniform','uni-po':'uniform','uni-receiving':'uniform','uni-print':'uniform',
  'emp-list':'employees','emp-inactive':'employees','emp-blacklisted':'employees',
};
function toggleGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;const isOpen=body.classList.contains('open');body.classList.toggle('open',!isOpen);if(toggle)toggle.classList.toggle('open',!isOpen);}
function openGroup(id){const body=document.getElementById('grp-'+id),toggle=body?.previousElementSibling;if(!body)return;body.classList.add('open');if(toggle)toggle.classList.add('open');}
const sections=document.querySelectorAll('.help-section[id]'),navLinks=document.querySelectorAll('.hn-link');
window.addEventListener('scroll',()=>{let current='';sections.forEach(s=>{if(window.scrollY>=s.offsetTop-120)current=s.id;});navLinks.forEach(a=>{a.classList.toggle('active',a.getAttribute('href')==='#'+current);});if(current&&sectionGroup[current])openGroup(sectionGroup[current]);},{passive:true});
document.addEventListener('DOMContentLoaded',()=>{const hash=location.hash.replace('#','');openGroup((hash&&sectionGroup[hash])?sectionGroup[hash]:'careers');});
</script>
</body>
</html>
