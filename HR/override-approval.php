<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

// ASSUMPTION (please confirm, same as the ajax file): new RBAC module key
// 'override_attendance_approval'. Swap to whatever key you'd rather use.
rbac_gate($pdo, 'override_attendance_approval');
$is_view_only = rbac_is_view_only('override_attendance_approval');

date_default_timezone_set('Asia/Manila');
$reviewerName = trim($_SESSION['DisplayName'] ?? 'User');
$roleLabel    = trim($_SESSION['UserType']    ?? 'HR');

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Override Approval · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
.opa-wrap { max-width: 1500px; margin: 0 auto; padding: 1.25rem; }

.opa-hero { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; }
.opa-hero h1 { font-size:1.4rem; font-weight:800; color:#1e1b4b; margin:0; }
.opa-hero .sub { font-size:.85rem; color:#64748b; margin-top:.2rem; }
.opa-hero .clock { font-size:.85rem; color:#64748b; }

.opa-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }

.opa-tabs { display:flex; gap:.4rem; padding:.7rem .9rem 0; border-bottom:1px solid #e2e8f0; }
.opa-tab {
    border:none; background:transparent; cursor:pointer; padding:.6rem 1rem; font-size:.82rem; font-weight:700;
    color:#64748b; border-radius:8px 8px 0 0; display:flex; align-items:center; gap:.45rem;
}
.opa-tab .opa-count { background:#f1f5f9; color:#64748b; border-radius:999px; padding:1px 8px; font-size:.72rem; }
.opa-tab.active { color:#1e1b4b; background:#f8fafc; }
.opa-tab.active .opa-count { background:#e2e8f0; color:#1e1b4b; }
.opa-tab[data-tab="pending"].active { color:#b45309; }
.opa-tab[data-tab="pending"].active .opa-count { background:#fef3c7; color:#b45309; }
.opa-tab[data-tab="approved"].active { color:#15803d; }
.opa-tab[data-tab="approved"].active .opa-count { background:#dcfce7; color:#15803d; }
.opa-tab[data-tab="rejected"].active { color:#b91c1c; }
.opa-tab[data-tab="rejected"].active .opa-count { background:#fee2e2; color:#b91c1c; }

.opa-toolbar { display:flex; align-items:center; gap:.6rem; padding:.9rem 1.2rem; border-bottom:1px solid #e2e8f0; }
.opa-search { flex:1; max-width:320px; position:relative; }
.opa-search i { position:absolute; left:.7rem; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:.85rem; }
.opa-search input { width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .7rem .5rem 2rem; font-size:.83rem; font-family:inherit; }

.opa-table-wrap { overflow-x:auto; }
.opa-table { width:100%; border-collapse:collapse; font-size:.82rem; white-space:nowrap; }
.opa-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:10px 10px; border-bottom:2px solid #e2e8f0; position:sticky; top:0; background:#fff; }
.opa-table td { padding:10px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.opa-table td.opa-remarks { white-space:normal; max-width:220px; color:#64748b; }
.opa-table tbody tr { cursor:pointer; }
.opa-table tbody tr:hover { background:#f8fafc; }
.opa-emp { font-weight:700; color:#1e1b4b; }
.opa-emp small { display:block; font-weight:500; color:#94a3b8; font-size:.72rem; }

.opa-badge { padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.opa-badge-in { background:#dcfce7; color:#15803d; }
.opa-badge-out { background:#fee2e2; color:#b91c1c; }
.opa-badge-kind-time { background:#dbeafe; color:#1d4ed8; }
.opa-badge-kind-schedule { background:#ede9fe; color:#6d28d9; }
.opa-badge-kind-mixed { background:#fae8ff; color:#a21caf; }
.opa-badge-pending { background:#fef3c7; color:#b45309; }
.opa-badge-approved { background:#dcfce7; color:#15803d; }
.opa-badge-rejected { background:#fee2e2; color:#b91c1c; }

.opa-corrected { color:#2563eb; font-weight:700; }
.opa-reviewed { font-size:.76rem; color:#94a3b8; }
.opa-reviewed b { color:#334155; }

.opa-empty { text-align:center; color:#94a3b8; padding:2.5rem 1rem; }

/* ── Modal ─────────────────────────────────────────────────────────── */
.opa-overlay {
    display:none; position:fixed; inset:0; background:rgba(15,23,42,.45);
    align-items:center; justify-content:center; z-index:1000; padding:1rem;
}
.opa-overlay.open { display:flex; }
.opa-modal { background:#fff; border-radius:16px; width:100%; max-width:640px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 50px rgba(0,0,0,.25); }
.opa-modal-head { padding:1.1rem 1.3rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; }
.opa-modal-head h2 { font-size:1.05rem; font-weight:800; color:#1e1b4b; margin:0; }
.opa-modal-head .sub { font-size:.78rem; color:#64748b; margin-top:.2rem; }
.opa-modal-close { border:none; background:#f1f5f9; color:#64748b; width:30px; height:30px; border-radius:8px; cursor:pointer; flex:0 0 auto; }
.opa-modal-body { padding:1.2rem 1.3rem; }
.opa-modal-foot { padding:1rem 1.3rem 1.3rem; display:flex; flex-wrap:wrap; gap:.6rem; }

.opa-modal-readonly { display:grid; grid-template-columns:repeat(3, 1fr); gap:.9rem; margin-bottom:1.1rem; }
@media (max-width:480px) { .opa-modal-readonly { grid-template-columns:1fr 1fr; } }
.opa-ro-item label { display:block; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin-bottom:.25rem; }
.opa-ro-item div { font-size:.9rem; color:#1e1b4b; font-weight:600; }

.opa-subhead { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin:1.1rem 0 .7rem; display:flex; align-items:center; gap:.35rem; }
.opa-subhead small { text-transform:none; letter-spacing:0; font-weight:500; color:#cbd5e1; font-size:.68rem; }

.opa-field { margin-bottom:.9rem; }
.opa-field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:.3rem; }
.opa-field input, .opa-field select, .opa-field textarea {
    width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .7rem;
    font-size:.85rem; color:#0f172a; background:#fff; font-family:inherit;
}
.opa-field-2col { display:grid; grid-template-columns:1fr 1fr; gap:.9rem; }
@media (max-width:480px) { .opa-field-2col { grid-template-columns:1fr; } }

.opa-modal-note { font-size:.78rem; color:#b45309; background:#fef3c7; border-radius:8px; padding:.6rem .8rem; margin-bottom:1.1rem; display:flex; gap:.5rem; align-items:flex-start; }

.opa-btn { border:none; border-radius:8px; padding:.6rem 1.1rem; font-size:.83rem; font-weight:700; display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; color:#fff; }
.opa-btn:disabled { opacity:.5; cursor:not-allowed; }
.opa-btn-approve { background:#16a34a; }
.opa-btn-reject { background:#dc2626; }
.opa-btn-save { background:#2563eb; }
.opa-btn-ghost { background:#f1f5f9; color:#334155; }

.opa-modal-status { font-size:.8rem; margin-top:.3rem; display:none; }
.opa-modal-status.err { color:#ef4444; }
.opa-modal-status.ok { color:#16a34a; }

.opa-quad-grid { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; margin-bottom:1.1rem; }
@media (max-width:480px) { .opa-quad-grid { grid-template-columns:1fr; } }
.opa-quad { border:1px solid #e2e8f0; border-radius:10px; padding:.7rem; }
.opa-quad h5 { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin:0 0 .5rem; }
.opa-quad .opa-field { margin-bottom:0; }
</style>
</head>
<body>
<input type="hidden" id="csrfToken" value="<?= h(rbac_csrf_token()) ?>">

<?php
$topbar_page = 'override_approval';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'override_approval';
require_once __DIR__ . '/hr_nav.php';
?>

<div class="opa-wrap">

  <div class="opa-hero">
    <div>
      <h1><i class="bi bi-clipboard-check"></i> Attendance Override Approval</h1>
      <div class="sub">Urban Tradewell Corporation — <?= h($reviewerName) ?> (<?= h($roleLabel) ?>)</div>
    </div>
    <div class="clock"><i class="bi bi-calendar3"></i> <?= date('l, F j, Y · h:i A') ?></div>
  </div>

  <div class="opa-card">
    <div class="opa-tabs">
      <button type="button" class="opa-tab active" data-tab="pending" data-status="0">
        <i class="bi bi-hourglass-split"></i> Pending <span class="opa-count" id="cntPending">–</span>
      </button>
      <button type="button" class="opa-tab" data-tab="approved" data-status="1">
        <i class="bi bi-check-circle"></i> Approved <span class="opa-count" id="cntApproved">–</span>
      </button>
      <button type="button" class="opa-tab" data-tab="rejected" data-status="2">
        <i class="bi bi-x-circle"></i> Rejected <span class="opa-count" id="cntRejected">–</span>
      </button>
    </div>

    <div class="opa-toolbar">
      <div class="opa-search">
        <i class="bi bi-search"></i>
        <input type="text" id="txtSearch" placeholder="Search by employee name...">
      </div>
    </div>

    <div class="opa-table-wrap">
      <table class="opa-table" id="tblOverrides">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Date</th>
            <th>Kind</th>
            <th>Details</th>
            <th>Type</th>
            <th>Remarks</th>
            <th>Submitted</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="tblOverridesBody">
          <tr><td colspan="8" class="opa-empty">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div> <!-- /.opa-wrap -->

<!-- ============ REVIEW / EDIT MODAL ============ -->
<div class="opa-overlay" id="reviewOverlay">
  <div class="opa-modal">
    <div class="opa-modal-head">
      <div>
        <h2 id="mEmpName">—</h2>
        <div class="sub" id="mEmpMeta">—</div>
      </div>
      <button type="button" class="opa-modal-close" id="btnCloseModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="opa-modal-body">

      <div id="mReadOnlyNote" class="opa-modal-note" style="display:none;">
        <i class="bi bi-info-circle"></i>
        <span id="mReadOnlyNoteText"></span>
      </div>

      <div id="mTimeBlock">
        <div class="opa-subhead" style="margin-top:0;"><i class="bi bi-clock"></i> Specific Punch Correction <small>optional</small></div>
        <div class="opa-modal-readonly">
          <div class="opa-ro-item"><label>Shift Part</label><div id="mShiftPart">—</div></div>
          <div class="opa-ro-item"><label>Direction</label><div id="mDirection">—</div></div>
          <div class="opa-ro-item"><label>Original Time</label><div id="mOriginalTime">—</div></div>
        </div>

        <div class="opa-field">
          <label>Corrected Time</label>
          <input type="time" id="mCorrectedTime">
        </div>
      </div>

      <div class="opa-subhead"><i class="bi bi-calendar-range"></i> Full Day Shift Times <small>optional</small></div>
      <div id="mScheduleBlock" class="opa-quad-grid">
        <div class="opa-quad">
          <h5>AM Time In</h5>
          <div class="opa-field"><label>Shift Time</label><input type="time" id="mSchedAmIn"></div>
        </div>
        <div class="opa-quad">
          <h5>AM Time Out</h5>
          <div class="opa-field"><label>Shift Time</label><input type="time" id="mSchedAmOut"></div>
        </div>
        <div class="opa-quad">
          <h5>PM Time In</h5>
          <div class="opa-field"><label>Shift Time</label><input type="time" id="mSchedPmIn"></div>
        </div>
        <div class="opa-quad">
          <h5>PM Time Out</h5>
          <div class="opa-field"><label>Shift Time</label><input type="time" id="mSchedPmOut"></div>
        </div>
      </div>

      <div class="opa-subhead"><i class="bi bi-fingerprint"></i> Actual Punch Times <small>optional</small></div>
      <div class="opa-quad-grid">
        <div class="opa-quad"><h5>AM Time In (AtimeIn)</h5><div class="opa-field"><input type="time" id="mAtimeIn"></div></div>
        <div class="opa-quad"><h5>AM Time Out (AtimeOutAM)</h5><div class="opa-field"><input type="time" id="mAtimeOutAM"></div></div>
        <div class="opa-quad"><h5>PM Time In (AtimeInPM)</h5><div class="opa-field"><input type="time" id="mAtimeInPM"></div></div>
        <div class="opa-quad"><h5>Time Out (AtimeOut)</h5><div class="opa-field"><input type="time" id="mAtimeOut"></div></div>
      </div>

      <div class="opa-subhead"><i class="bi bi-exclamation-triangle"></i> Lateness &amp; Totals <small>optional</small></div>
      <div class="opa-quad-grid">
        <div class="opa-quad"><h5>AM Late</h5><div class="opa-field"><input type="text" id="mAMLate" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>PM Late</h5><div class="opa-field"><input type="text" id="mPMLate" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Late</h5><div class="opa-field"><input type="text" id="mLate" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Morning Total Hours</h5><div class="opa-field"><input type="text" id="mMorningTotalHours" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Afternoon Total Hours</h5><div class="opa-field"><input type="text" id="mAfternoonTotalHours" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Morning+Afternoon Total</h5><div class="opa-field"><input type="text" id="mMorningAfternoonTotal" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Total Hours</h5><div class="opa-field"><input type="text" id="mTotalHours" inputmode="decimal"></div></div>
      </div>

      <div class="opa-subhead"><i class="bi bi-tags"></i> Classification &amp; Location <small>optional</small></div>
      <div class="opa-quad-grid">
        <div class="opa-quad"><h5>Status</h5><div class="opa-field"><input type="text" id="mStatus"></div></div>
        <div class="opa-quad"><h5>Day Count</h5><div class="opa-field"><input type="text" id="mDayCount" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Payroll Group</h5><div class="opa-field"><input type="text" id="mPayrollGroup"></div></div>
        <div class="opa-quad"><h5>Aday</h5><div class="opa-field"><input type="text" id="mAday" inputmode="decimal"></div></div>
        <div class="opa-quad"><h5>Area (Time In)</h5><div class="opa-field"><input type="text" id="mArea"></div></div>
        <div class="opa-quad"><h5>Area (Time Out)</h5><div class="opa-field"><input type="text" id="mAreaOut"></div></div>
      </div>

      <div class="opa-field" id="mAttachmentWrap">
        <label>Attachment</label>
        <div id="mAttachmentLink" style="font-size:.85rem;">—</div>
      </div>

      <div class="opa-field-2col">
        <div class="opa-field">
          <label>Override Category</label>
          <select id="mCategory"><option value="">Loading…</option></select>
        </div>
        <div class="opa-field">
          <label>Override Type / Reason</label>
          <select id="mType"><option value="">Loading…</option></select>
        </div>
      </div>
      <div class="opa-field">
        <label>Remarks</label>
        <textarea id="mRemarks" rows="3"></textarea>
      </div>

      <div id="mStatusMsg" class="opa-modal-status err"></div>
      <div id="mSuccessMsg" class="opa-modal-status ok"></div>
    </div>
    <div class="opa-modal-foot">
      <button type="button" class="opa-btn opa-btn-save" id="btnSaveChanges"><i class="bi bi-save"></i> Save Changes</button>
      <button type="button" class="opa-btn opa-btn-approve" id="btnApprove"><i class="bi bi-check-lg"></i> Approve</button>
      <button type="button" class="opa-btn opa-btn-reject" id="btnReject"><i class="bi bi-x-lg"></i> Reject</button>
      <button type="button" class="opa-btn opa-btn-ghost" id="btnCancelModal">Cancel</button>
    </div>
  </div>
</div>

  </main>
</div> <!-- /.hr-shell (opened by hr_nav.php) -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken   = document.getElementById('csrfToken').value;
    const tabs        = document.querySelectorAll('.opa-tab');
    const searchInput = document.getElementById('txtSearch');
    const tbody       = document.getElementById('tblOverridesBody');
    const isViewOnly  = <?= $is_view_only ? 'true' : 'false' ?>;

    // Modal elements
    const overlay        = document.getElementById('reviewOverlay');
    const mEmpName        = document.getElementById('mEmpName');
    const mEmpMeta         = document.getElementById('mEmpMeta');
    const mReadOnlyNote    = document.getElementById('mReadOnlyNote');
    const mReadOnlyNoteText= document.getElementById('mReadOnlyNoteText');
    const mTimeBlock       = document.getElementById('mTimeBlock');
    const mShiftPart       = document.getElementById('mShiftPart');
    const mDirection       = document.getElementById('mDirection');
    const mOriginalTime    = document.getElementById('mOriginalTime');
    const mCorrectedTime   = document.getElementById('mCorrectedTime');
    const mScheduleBlock   = document.getElementById('mScheduleBlock');
    const mSchedAmIn       = document.getElementById('mSchedAmIn');
    const mSchedAmOut      = document.getElementById('mSchedAmOut');
    const mSchedPmIn       = document.getElementById('mSchedPmIn');
    const mSchedPmOut      = document.getElementById('mSchedPmOut');
    const mAtimeIn         = document.getElementById('mAtimeIn');
    const mAtimeOutAM      = document.getElementById('mAtimeOutAM');
    const mAtimeInPM       = document.getElementById('mAtimeInPM');
    const mAtimeOut        = document.getElementById('mAtimeOut');
    const mAMLate          = document.getElementById('mAMLate');
    const mPMLate          = document.getElementById('mPMLate');
    const mLate            = document.getElementById('mLate');
    const mMorningTotalHours     = document.getElementById('mMorningTotalHours');
    const mAfternoonTotalHours   = document.getElementById('mAfternoonTotalHours');
    const mMorningAfternoonTotal = document.getElementById('mMorningAfternoonTotal');
    const mTotalHours      = document.getElementById('mTotalHours');
    const mStatus          = document.getElementById('mStatus');
    const mDayCount        = document.getElementById('mDayCount');
    const mPayrollGroup    = document.getElementById('mPayrollGroup');
    const mAday            = document.getElementById('mAday');
    const mArea            = document.getElementById('mArea');
    const mAreaOut         = document.getElementById('mAreaOut');
    const mAttachmentLink  = document.getElementById('mAttachmentLink');
    const mCategory        = document.getElementById('mCategory');
    const mType            = document.getElementById('mType');
    const mRemarks         = document.getElementById('mRemarks');
    const mStatusMsg       = document.getElementById('mStatusMsg');
    const mSuccessMsg      = document.getElementById('mSuccessMsg');
    const btnSaveChanges   = document.getElementById('btnSaveChanges');
    const btnApprove       = document.getElementById('btnApprove');
    const btnReject        = document.getElementById('btnReject');
    const btnCloseModal    = document.getElementById('btnCloseModal');
    const btnCancelModal   = document.getElementById('btnCancelModal');

    let currentStatus   = '0';
    let currentRows     = [];
    let activeRow       = null; // the row object currently open in the modal
    let allOverrideTypes = [];
    let typesLoaded      = false;

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function badgeClassForStatus(status) {
        if (status === 'Approved') return 'opa-badge-approved';
        if (status === 'Rejected') return 'opa-badge-rejected';
        return 'opa-badge-pending';
    }

    function loadCounts() {
        fetch('override-approval-ajax.php?action=get_counts')
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;
                document.getElementById('cntPending').textContent  = data.counts.pending;
                document.getElementById('cntApproved').textContent = data.counts.approved;
                document.getElementById('cntRejected').textContent = data.counts.rejected;
            })
            .catch(() => {});
    }

    function renderRows() {
        const q = searchInput.value.trim().toLowerCase();
        const filtered = q
            ? currentRows.filter(r => r.EmployeeName.toLowerCase().includes(q))
            : currentRows;

        if (!filtered.length) {
            tbody.innerHTML = `<tr><td colspan="8" class="opa-empty">${q ? 'No matches for that search.' : 'Nothing here yet.'}</td></tr>`;
            return;
        }

        tbody.innerHTML = filtered.map(r => {
            const kindClass = r.Kind === 'mixed' ? 'opa-badge-kind-mixed' : (r.Kind === 'time' ? 'opa-badge-kind-time' : 'opa-badge-kind-schedule');
            const kindLabel = r.Kind === 'mixed' ? 'Mixed' : (r.Kind === 'time' ? 'Time' : 'Schedule');
            const statusCell = r.Status === 'Pending'
                ? `<span class="opa-badge ${badgeClassForStatus(r.Status)}">${esc(r.Status)}</span>`
                : `<span class="opa-reviewed">${esc(r.Status)} by <b>${esc(r.ReviewedBy || '—')}</b><br>${esc(r.ReviewedAt || '')}</span>`;

            return `<tr data-row-id="${r.ID}">
                <td><span class="opa-emp">${esc(r.EmployeeName)}<small>${esc(r.Department)}</small></span></td>
                <td>${esc(r.Date)}</td>
                <td><span class="opa-badge ${kindClass}">${kindLabel}</span></td>
                <td class="opa-corrected">${esc(r.Details) || '—'}</td>
                <td>${esc(r.OverrideType) || '—'}</td>
                <td class="opa-remarks">${esc(r.Remarks) || '—'}</td>
                <td>${esc(r.Submitted)}</td>
                <td>${statusCell}</td>
            </tr>`;
        }).join('');
    }

    function loadOverrides(status) {
        tbody.innerHTML = `<tr><td colspan="8" class="opa-empty">Loading…</td></tr>`;
        fetch(`override-approval-ajax.php?action=get_overrides&status=${encodeURIComponent(status)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="8" class="opa-empty">${esc(data.message || 'Error loading overrides.')}</td></tr>`;
                    return;
                }
                currentRows = data.overrides;
                renderRows();
            })
            .catch(() => {
                tbody.innerHTML = `<tr><td colspan="8" class="opa-empty">Error loading overrides.</td></tr>`;
            });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentStatus = tab.dataset.status;
            loadOverrides(currentStatus);
        });
    });

    searchInput.addEventListener('input', renderRows);

    // ── Category / Type dropdowns (loaded once, filtered per-open) ──────
    function ensureTypesLoaded(callback) {
        if (typesLoaded) { callback(); return; }

        Promise.all([
            fetch('override-approval-ajax.php?action=get_override_categories').then(r => r.json()),
            fetch('override-approval-ajax.php?action=get_override_types').then(r => r.json()),
        ]).then(([catData, typeData]) => {
            if (catData.success) {
                mCategory.innerHTML = '<option value="">All Categories</option>' +
                    catData.categories.map(c => `<option value="${esc(c.OverrideID)}" data-name="${esc(c.Override_Name)}">${esc(c.Override_Name)}</option>`).join('');
            }
            if (typeData.success) {
                allOverrideTypes = typeData.types;
            }
            typesLoaded = true;
            callback();
        }).catch(() => {
            mCategory.innerHTML = '<option value="">Unable to load</option>';
            mType.innerHTML = '<option value="">Unable to load</option>';
        });
    }

    function renderTypeOptions(preselectTypeId) {
        const selectedCategory = mCategory.value;
        const selectedOption   = mCategory.options[mCategory.selectedIndex];
        const selectedName     = selectedOption ? (selectedOption.dataset.name || '') : '';

        const filtered = selectedCategory
            ? allOverrideTypes.filter(t => {
                const tCat = String(t.Category ?? '').trim().toLowerCase();
                return tCat === String(selectedCategory).trim().toLowerCase()
                    || tCat === selectedName.trim().toLowerCase();
            })
            : allOverrideTypes;

        mType.innerHTML = filtered.length
            ? filtered.map(t => `<option value="${esc(t.TypeID)}">${esc(t.Type_Name)}</option>`).join('')
            : '<option value="">No types for this category</option>';

        if (preselectTypeId) {
            mType.value = String(preselectTypeId);
        }
    }
    mCategory.addEventListener('change', () => renderTypeOptions());

    // ── Open modal for a row ─────────────────────────────────────────────
    function openModal(row) {
        activeRow = row;
        mStatusMsg.style.display = 'none';
        mSuccessMsg.style.display = 'none';

        mEmpName.textContent = row.EmployeeName;
        mEmpMeta.textContent = `${row.Department || '—'} · ${row.Date} · Submitted ${row.Submitted}`;
        mRemarks.value = row.Remarks || '';

        // Both blocks are always visible — a row may have a correction, a
        // schedule, or both, and the reviewer can fill in either while
        // editing regardless of what the employee originally submitted.
        mShiftPart.textContent = row.ShiftPart || '—';
        mDirection.textContent = row.Direction === 'IN' ? 'IN = Time In' : (row.Direction === 'OUT' ? 'OUT = Time Out' : '—');
        mOriginalTime.textContent = row.OriginalTime || '—';
        mCorrectedTime.value = row.CorrectedTime24 || '';

        mSchedAmIn.value  = row.ScheduleAmIn24  || '';
        mSchedAmOut.value = row.ScheduleAmOut24 || '';
        mSchedPmIn.value  = row.SchedulePmIn24  || '';
        mSchedPmOut.value = row.SchedulePmOut24 || '';

        mAtimeIn.value    = row.AtimeIn24    || '';
        mAtimeOutAM.value = row.AtimeOutAM24 || '';
        mAtimeInPM.value  = row.AtimeInPM24  || '';
        mAtimeOut.value   = row.AtimeOut24   || '';
        mAMLate.value          = row.AMLate ?? '';
        mPMLate.value          = row.PMLate ?? '';
        mLate.value            = row.Late ?? '';
        mMorningTotalHours.value     = row.MorningTotalHours ?? '';
        mAfternoonTotalHours.value   = row.AfternoonTotalHours ?? '';
        mMorningAfternoonTotal.value = row.MorningAfternoonTotal ?? '';
        mTotalHours.value      = row.TotalHours ?? '';
        mStatus.value          = row.ManualStatus ?? '';
        mDayCount.value        = row.ManualDayCount ?? '';
        mPayrollGroup.value    = row.ManualPayrollGroup ?? '';
        mAday.value            = row.Aday ?? '';
        mArea.value            = row.Area ?? '';
        mAreaOut.value         = row.AreaOut ?? '';
        mAttachmentLink.innerHTML = row.AttachmentUrl
            ? `<a href="${esc(row.AttachmentUrl)}" target="_blank" rel="noopener">View attachment</a>`
            : '—';

        const editable = !isViewOnly;
        const timeInputs = [mCorrectedTime];
        const scheduleInputs = [mSchedAmIn, mSchedAmOut, mSchedPmIn, mSchedPmOut];
        const manualInputs = [
            mAtimeIn, mAtimeOutAM, mAtimeInPM, mAtimeOut,
            mAMLate, mPMLate, mLate, mMorningTotalHours, mAfternoonTotalHours,
            mMorningAfternoonTotal, mTotalHours, mStatus, mDayCount, mPayrollGroup,
            mAday, mArea, mAreaOut,
        ];
        [...timeInputs, ...scheduleInputs, ...manualInputs, mCategory, mType, mRemarks].forEach(el => el.disabled = !editable);

        if (row.Status !== 'Pending') {
            mReadOnlyNote.style.display = 'flex';
            mReadOnlyNoteText.textContent = `Already ${row.Status.toLowerCase()} by ${row.ReviewedBy || '—'} on ${row.ReviewedAt || '—'}. Saving changes here updates the live attendance record immediately — Approve/Reject are disabled since this was already decided.`;
        } else {
            mReadOnlyNote.style.display = 'none';
        }

        btnApprove.style.display = (row.Status === 'Pending' && editable) ? 'inline-flex' : 'none';
        btnReject.style.display  = (row.Status === 'Pending' && editable) ? 'inline-flex' : 'none';
        btnSaveChanges.style.display = editable ? 'inline-flex' : 'none';

        ensureTypesLoaded(() => {
            // Pre-select the category that matches this row's type, using the
            // same hedge (ID-or-name) match as override-attendance.php, then
            // pre-select the type itself.
            const cat = (row.OverrideCategoryRaw || '').trim().toLowerCase();
            let matched = '';
            for (const opt of mCategory.options) {
                const optName = (opt.dataset.name || '').trim().toLowerCase();
                if (opt.value.trim().toLowerCase() === cat || optName === cat) {
                    matched = opt.value;
                    break;
                }
            }
            mCategory.value = matched;
            renderTypeOptions(row.OverrideTypeID);
        });

        overlay.classList.add('open');
    }

    function closeModal() {
        overlay.classList.remove('open');
        activeRow = null;
    }

    btnCloseModal.addEventListener('click', closeModal);
    btnCancelModal.addEventListener('click', closeModal);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });

    tbody.addEventListener('click', function (e) {
        const tr = e.target.closest('tr[data-row-id]');
        if (!tr) return;
        const row = currentRows.find(r => String(r.ID) === tr.dataset.rowId);
        if (row) openModal(row);
    });

    // ── Submit an action from the modal ──────────────────────────────────
    function submitAction(action, confirmMsg) {
        if (!activeRow) return;
        if (confirmMsg && !confirm(confirmMsg)) return;

        mStatusMsg.style.display = 'none';
        mSuccessMsg.style.display = 'none';
        [btnSaveChanges, btnApprove, btnReject].forEach(b => b.disabled = true);

        const params = {
            id: activeRow.ID,
            override_type: mType.value,
            remarks: mRemarks.value,
            corrected_time: mCorrectedTime.value,
            sched_am_in:  mSchedAmIn.value,
            sched_am_out: mSchedAmOut.value,
            sched_pm_in:  mSchedPmIn.value,
            sched_pm_out: mSchedPmOut.value,
            atime_in:      mAtimeIn.value,
            atime_out_am:  mAtimeOutAM.value,
            atime_in_pm:   mAtimeInPM.value,
            atime_out:     mAtimeOut.value,
            am_late: mAMLate.value,
            pm_late: mPMLate.value,
            late:    mLate.value,
            morning_total_hours:     mMorningTotalHours.value,
            afternoon_total_hours:   mAfternoonTotalHours.value,
            morning_afternoon_total: mMorningAfternoonTotal.value,
            total_hours: mTotalHours.value,
            status:         mStatus.value,
            day_count:      mDayCount.value,
            payroll_group:  mPayrollGroup.value,
            aday:           mAday.value,
            area:           mArea.value,
            area_out:       mAreaOut.value,
        };
        const body = new URLSearchParams(params);

        fetch(`override-approval-ajax.php?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken,
            },
            body: body.toString(),
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    let msg = data.message || 'Something went wrong.';
                    if (data.errors && data.errors.length) msg += ' — ' + data.errors.map(e => e.message).join(' | ');
                    mStatusMsg.textContent = msg;
                    mStatusMsg.style.display = 'block';
                    return;
                }
                mSuccessMsg.textContent = data.message || 'Saved.';
                mSuccessMsg.style.display = 'block';
                loadCounts();
                loadOverrides(currentStatus);
                setTimeout(closeModal, action === 'update_override' ? 700 : 900);
            })
            .catch(() => {
                mStatusMsg.textContent = 'Request failed. Please try again.';
                mStatusMsg.style.display = 'block';
            })
            .finally(() => {
                [btnSaveChanges, btnApprove, btnReject].forEach(b => b.disabled = false);
            });
    }

    btnSaveChanges.addEventListener('click', () => submitAction('update_override', null));
    btnApprove.addEventListener('click', () => submitAction('approve_override', 'Approve this override? It will immediately reflect in the employee\'s attendance record.'));
    btnReject.addEventListener('click', () => submitAction('reject_override', 'Reject this override?'));

    loadCounts();
    loadOverrides(currentStatus);
});
</script>
</body>
</html>