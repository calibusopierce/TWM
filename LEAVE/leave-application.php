<?php
/* =====================================================================
   leave-application.php
   File location: TWM/LEAVE/leave-application.php
   RBAC module key: leave_application
   Employee-facing Leave Application page.

   Bootstrap confirmed from uniform-inventory.php's real header — uses
   $_SERVER['DOCUMENT_ROOT'] absolute paths, auth_check(), rbac_gate(),
   and a PDO connection ($pdo) from test_sqlsrv.php (not raw sqlsrv_*).

   topbar.php requires the local vendor Bootstrap/Bootstrap-Icons +
   admin.css/topbar.css/responsive-patch.css set (matching
   leave-info-management.php / careers-admin.php) rather than CDN
   Bootstrap — mixing the two broke the topbar's layout on the
   approval page. This page's own :root variable names were renamed
   to --lv-* to avoid colliding with admin.css's globals, same fix
   already applied on leave-info-management.php.

   REMAINING ASSUMPTIONS (verify/adjust):
   - $_SESSION['EmployeeID'] holds the logged-in employee's ID (same
     shape as SA_EmployeeID / HR_EmployeeID / Tbl_HREmployeeList.EmployeeID).
   - $_SESSION['EmployeeName'] holds the display name.
   - Attachments are saved under uploads/leave_attachments/ relative to
     this file; change to match your existing upload convention.
   ===================================================================== */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'leave_application');
$topbar_page = 'leave_application';

$employeeID   = $_SESSION['EmployeeID'] ?? '';
$employeeName = $_SESSION['EmployeeName'] ?? '';
$isViewOnly   = rbac_is_view_only('leave_application'); // hide/disable filing action if true

// Leave types for the dropdown — sourced from the real Tbl_Leave_Type table
$typeStmt = $pdo->prepare("SELECT ID, Code, Type_Name, Category, With_Pay,
                                   Regular_Credit, Requires_Attachment,
                                   Max_Days_Per_Year, Carry_Forward
                            FROM dbo.Tbl_Leave_Type
                            WHERE Status = 1
                            ORDER BY Type_Name");
$typeStmt->execute();
$leaveTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Application - TWM</title>

<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/responsive-patch.css') ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
:root{
    --lv-surface:#ffffff;
    --lv-primary:#2f6fed;
    --lv-border:#e4e7ec;
    --lv-radius:10px;
    --lv-bg:#f6f7fb;
    --lv-text:#1c2333;
    --lv-muted:#6b7280;
}
body{
    background:var(--lv-bg);
    font-family:'Sora',sans-serif;
    color:var(--lv-text);
}
.mono{ font-family:'DM Mono',monospace; }

.page-header{
    display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:12px; margin-bottom:20px;
}
.page-header h4{ font-weight:600; margin:0; }

.card-panel{
    background:var(--lv-surface);
    border:1px solid var(--lv-border);
    border-radius:var(--lv-radius);
    padding:20px;
    margin-bottom:20px;
}

.filter-bar{
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
}
.filter-bar .form-select, .filter-bar .form-control{
    max-width:220px;
}

table.leave-table thead th{
    font-size:.78rem; text-transform:uppercase; letter-spacing:.03em;
    color:var(--lv-muted); border-bottom:2px solid var(--lv-border);
    white-space:nowrap;
}
table.leave-table td{ vertical-align:middle; font-size:.9rem; }
table.leave-table td.mono{ font-size:.85rem; }

.badge-status{
    padding:.4em .75em; border-radius:20px; font-weight:500; font-size:.75rem;
}
.badge-pending-sa   { background:#fff3cd; color:#8a6100; }
.badge-approved-sa  { background:#e2f0ff; color:#0b5cad; }
.badge-rejected-sa  { background:#fde2e1; color:#b3261e; }
.badge-pending-hr   { background:#e9e6ff; color:#4b3fb0; }
.badge-approved     { background:#dff6e6; color:#177a3b; }
.badge-rejected     { background:#fde2e1; color:#b3261e; }

.empty-state{ text-align:center; padding:40px 0; color:var(--lv-muted); }

.step-track{
    display:flex; align-items:center; gap:6px; font-size:.8rem; margin-top:10px;
}
.step-dot{
    width:10px; height:10px; border-radius:50%; background:var(--lv-border);
}
.step-dot.done{ background:#177a3b; }
.step-dot.rejected{ background:#b3261e; }
.step-line{ flex:1; height:2px; background:var(--lv-border); }
.step-line.done{ background:#177a3b; }


.form-section{ margin-bottom:22px; }
.form-section:last-of-type{ margin-bottom:0; }
.form-section-title{
    font-size:.72rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--lv-primary);
    margin-bottom:12px;
    padding-bottom:8px;
    border-bottom:1px solid var(--lv-border);
}
</style>
</head>
<body>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container-fluid p-4">

    <div class="page-header">
        <div>
            <h4><i class="bi bi-calendar2-check me-2"></i>Leave Application</h4>
            <div class="text-muted small">View and file your leave requests</div>
        </div>
        <?php if (!$isViewOnly): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newLeaveModal">
            <i class="bi bi-plus-lg me-1"></i> File Leave Application
        </button>
        <?php else: ?>
        <span class="badge bg-secondary-subtle text-secondary px-3 py-2">
            <i class="bi bi-eye me-1"></i> View-only access
        </span>
        <?php endif; ?>
    </div>

    <div class="card-panel">
        <div class="filter-bar mb-3">
            <input type="text" id="fltSearch" class="form-control" placeholder="Search control no. / reason...">
            <select id="fltStatus" class="form-select">
                <option value="">All Statuses</option>
                <option value="PendingSA">Pending Supervisor Approval</option>
                <option value="ApprovedSA">Approved by Supervisor</option>
                <option value="RejectedSA">Rejected by Supervisor</option>
                <option value="PendingHR">Pending HR Approval</option>
                <option value="Approved">Approved</option>
                <option value="RejectedHR">Rejected by HR</option>
            </select>
            <select id="fltType" class="form-select">
                <option value="">All Leave Types</option>
                <?php foreach ($leaveTypes as $t): ?>
                    <option value="<?= (int)$t['ID'] ?>"><?= htmlspecialchars($t['Type_Name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="loadApplications(1)">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>

        <div class="table-responsive">
            <table class="table leave-table" id="leaveTable">
                <thead>
                    <tr>
                        <th>Control No.</th>
                        <th>Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Days</th>
                        <th>Supervisor</th>
                        <th>HR</th>
                        <th>SA Status</th>
                        <th>HR Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="leaveTableBody">
                    <!-- populated via AJAX -->
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination justify-content-end" id="leavePagination"></ul>
        </nav>
    </div>
</div>

<!-- ===================== New Leave Application Modal ===================== -->
<div class="modal fade" id="newLeaveModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="leaveForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(rbac_csrf_token()) ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="leaveModalTitle"><i class="bi bi-calendar2-plus me-2"></i>File Leave Application</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

         <div class="form-section">
            <div class="form-section-title">Leave Details</div>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                <select name="TypeID" id="TypeID" class="form-select" required>
                  <option value="">Select type...</option>
                  <?php foreach ($leaveTypes as $t): ?>
                      <option value="<?= (int)$t['ID'] ?>"
                          data-requires-attachment="<?= (int)$t['Requires_Attachment'] ?>"
                          data-max-days="<?= htmlspecialchars($t['Max_Days_Per_Year'] ?? '') ?>"
                          data-with-pay="<?= (int)$t['With_Pay'] ?>">
                          <?= htmlspecialchars($t['Type_Name']) ?><?= $t['With_Pay'] ? '' : ' (Unpaid)' ?>
                      </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text" id="typeHint"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Attachment <span class="text-muted small">(if required)</span></label>
                <input type="file" name="Attachment" class="form-control">
              </div>

              <div class="col-md-4">
                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                <input type="date" name="Date_Start" id="Date_Start" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">End Date <span class="text-danger">*</span></label>
                <input type="date" name="Date_End" id="Date_End" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Number of Days</label>
                <input type="text" id="NumberOfDaysDisplay" class="form-control mono" readonly>
                <input type="hidden" name="NumberOfDays" id="NumberOfDays">
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="HalfDay" id="HalfDay" value="1">
                  <label class="form-check-label" for="HalfDay">Half Day</label>
                </div>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Route for Approval</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Supervisor (Approving Authority) <span class="text-danger">*</span></label>
                <div class="position-relative">
                  <input type="text" id="supervisorSearch" class="form-control" placeholder="Click to browse or type to filter…" autocomplete="off" required>
                  <input type="hidden" name="SA_EmployeeID" id="SA_EmployeeID">
                  <div id="supervisorResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:2000; max-height:220px; overflow-y:auto; display:none;"></div>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">HR Reviewer (Final Approval) <span class="text-danger">*</span></label>
                <div class="position-relative">
                  <input type="text" id="hrSearch" class="form-control" placeholder="Click to browse or type to filter…" autocomplete="off" required>
                  <input type="hidden" name="HR_EmployeeID" id="HR_EmployeeID">
                  <div id="hrResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:2000; max-height:220px; overflow-y:auto; display:none;"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Reason</div>
            <div class="row g-3">
              <div class="col-12">
                <textarea name="ReasonOfLeave" id="ReasonOfLeave" class="form-control" rows="3" maxlength="500" placeholder="Briefly explain the reason for this leave..." required></textarea>
              </div>
            </div>
          </div>

          <div id="leaveFormAlert" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSubmitLeave">
            <i class="bi bi-send me-1"></i> Submit Application
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===================== View Application Modal ===================== -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-text me-2"></i>Application Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewLeaveBody">
        <!-- populated via AJAX -->
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const CURRENT_EMPLOYEE_ID = <?= json_encode($employeeID) ?>;
const IS_VIEW_ONLY = <?= json_encode($isViewOnly) ?>;
let currentPage = 1;
let editingId = '';

/* ---------- escape helper (same pattern as RBAC's escHtml sink fix) ---------- */
function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ---------- status badge helper (mirrors the 6 states in the spec) ---------- */
function simpleBadge(status) {
    if (status === 'Approved') return '<span class="badge-status badge-approved">Approved</span>';
    if (status === 'Rejected') return '<span class="badge-status badge-rejected">Rejected</span>';
    return '<span class="badge-status badge-pending-sa">Pending</span>';
}

/* ---------- load table via AJAX (server does the filtering/pagination) ---------- */
function loadApplications(page = 1) {
    currentPage = page;
    const params = new URLSearchParams({
        page: page,
        search: document.getElementById('fltSearch').value,
        status: document.getElementById('fltStatus').value,
        type: document.getElementById('fltType').value
    });

    fetch('leave-my-applications-list.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('leaveTableBody');
            if (!data.rows || data.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No leave applications found</div></td></tr>';
                document.getElementById('leavePagination').innerHTML = '';
                return;
            }
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td class="mono">${escHtml(r.ControlNo)}</td>
                    <td>${escHtml(r.TypeName)}</td>
                    <td class="mono">${escHtml(r.Date_Start)}</td>
                    <td class="mono">${escHtml(r.Date_End)}</td>
                    <td class="mono">${escHtml(r.NumberOfDays)}${r.HalfDay == 1 ? ' (½)' : ''}</td>
                    <td>${r.SupervisorName ? escHtml(r.SupervisorName) : '-'}</td>
                    <td>${r.HRName ? escHtml(r.HRName) : '-'}</td>
                    <td>${simpleBadge(r.SA_Status)}</td>
                    <td>${simpleBadge(r.HR_Status)}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" onclick="viewApplication(${r.ID})" title="View">
                            <i class="bi bi-eye"></i>
                        </button>
                        ${(!IS_VIEW_ONLY && r.SA_Status === 'Pending' && r.HR_Status === 'Pending') ? `
                        <button class="btn btn-sm btn-outline-secondary ms-1" onclick="editApplication(${r.ID})" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>` : ''}
                    </td>
                </tr>
            `).join('');

            renderPagination(data.totalPages, page);
        })
        .catch(err => {
            console.error(err);
            document.getElementById('leaveTableBody').innerHTML =
                '<tr><td colspan="10" class="text-danger text-center py-3">Failed to load applications.</td></tr>';
        });
}

function renderPagination(totalPages, page) {
    const el = document.getElementById('leavePagination');
    if (totalPages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="loadApplications(${i}); return false;">${i}</a>
                 </li>`;
    }
    el.innerHTML = html;
}

/* ---------- view details modal ---------- */
function viewApplication(id) {
    fetch('leave-my-applications-list.php?detail=' + id)
        .then(r => r.json())
        .then(d => {
            const r = d.row;
            document.getElementById('viewLeaveBody').innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6"><strong>Control No.</strong><div class="mono">${escHtml(r.ControlNo)}</div></div>
                    <div class="col-md-6"><strong>Leave Type</strong><div>${escHtml(r.TypeName)}</div></div>
                    <div class="col-md-4"><strong>Start</strong><div class="mono">${escHtml(r.Date_Start)}</div></div>
                    <div class="col-md-4"><strong>End</strong><div class="mono">${escHtml(r.Date_End)}</div></div>
                    <div class="col-md-4"><strong>Days</strong><div class="mono">${escHtml(r.NumberOfDays)}${r.HalfDay == 1 ? ' (½)' : ''}</div></div>
                    <div class="col-12"><strong>Reason</strong><div>${escHtml(r.ReasonOfLeave)}</div></div>
                    ${r.Attachment ? `<div class="col-12"><a href="${encodeURI(r.Attachment)}" target="_blank"><i class="bi bi-paperclip"></i> View Attachment</a></div>` : ''}
                    <div class="col-12"><hr></div>
                    <div class="col-md-6">
                        <strong>Supervisor (Recommendation)</strong>
                        <div>${simpleBadge(r.SA_Status)}</div>
                        <div class="small text-muted mt-1">${escHtml(r.SupervisorName)}${r.SA_Date_Approved ? ' — ' + escHtml(r.SA_Date_Approved) : ''}</div>
                        ${r.SA_Note ? `<div class="small mt-1"><em>"${escHtml(r.SA_Note)}"</em></div>` : ''}
                    </div>
                    <div class="col-md-6">
                        <strong>HR (Final Decision)</strong>
                        <div>${simpleBadge(r.HR_Status)}</div>
                        <div class="small text-muted mt-1">${escHtml(r.HRName)}${r.HR_Date_Approved ? ' — ' + escHtml(r.HR_Date_Approved) : ''}</div>
                        ${r.HR_Note ? `<div class="small mt-1"><em>"${escHtml(r.HR_Note)}"</em></div>` : ''}
                    </div>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('viewLeaveModal')).show();
        });
}

/* ---------- edit an existing (still-Pending) application ---------- */
function editApplication(id) {
    fetch('leave-my-applications-list.php?detail=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                alert(d.message || 'Could not load this application for editing.');
                return;
            }
            const r = d.row;

            if (r.SA_Status !== 'Pending' || r.HR_Status !== 'Pending') {
                alert('This application has already been acted on and can no longer be edited.');
                return;
            }

            editingId = id;
            document.getElementById('leaveModalTitle').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Leave Application';
            document.getElementById('btnSubmitLeave').innerHTML = '<i class="bi bi-send me-1"></i> Update Application';

            document.getElementById('TypeID').value = r.TypeID;
            document.getElementById('TypeID').dispatchEvent(new Event('change'));

            document.getElementById('Date_Start').value = r.Date_Start_Raw || '';
            document.getElementById('Date_End').value = r.Date_End_Raw || '';
            document.getElementById('HalfDay').checked = (r.HalfDay == 1);
            recalcDays();

            document.getElementById('ReasonOfLeave').value = r.ReasonOfLeave || '';

            document.getElementById('SA_EmployeeID').value = r.SA_EmployeeID || '';
            document.getElementById('supervisorSearch').value = r.SupervisorName || '';
            document.getElementById('HR_EmployeeID').value = r.HR_EmployeeID || '';
            document.getElementById('hrSearch').value = r.HRName || '';

            bootstrap.Modal.getOrCreateInstance(document.getElementById('newLeaveModal')).show();
        });
}

/* ---------- always start clean when the modal closes, regardless of how it was opened ---------- */
document.getElementById('newLeaveModal').addEventListener('hidden.bs.modal', function () {
    editingId = '';
    document.getElementById('leaveForm').reset();
    supervisorPicker.reset();
    hrPicker.reset();
    document.getElementById('leaveModalTitle').innerHTML = '<i class="bi bi-calendar2-plus me-2"></i>File Leave Application';
    document.getElementById('btnSubmitLeave').innerHTML = '<i class="bi bi-send me-1"></i> Submit Application';
    document.getElementById('typeHint').textContent = '';
});

/* ---------- number of days auto-calc ---------- */
function recalcDays() {
    const start = document.getElementById('Date_Start').value;
    const end = document.getElementById('Date_End').value;
    const half = document.getElementById('HalfDay').checked;
    if (!start || !end) return;
    const d1 = new Date(start), d2 = new Date(end);
    if (d2 < d1) {
        document.getElementById('NumberOfDaysDisplay').value = 'Invalid range';
        document.getElementById('NumberOfDays').value = '';
        return;
    }
    let days = Math.round((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;
    if (half) days = days - 0.5;
    document.getElementById('NumberOfDaysDisplay').value = days;
    document.getElementById('NumberOfDays').value = days;
}
document.getElementById('Date_Start').addEventListener('change', recalcDays);
document.getElementById('Date_End').addEventListener('change', recalcDays);
document.getElementById('HalfDay').addEventListener('change', recalcDays);

/* ---------- leave type hint (max days/year, attachment requirement) ---------- */
document.getElementById('TypeID').addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const hint = document.getElementById('typeHint');
    if (!opt.value) { hint.textContent = ''; return; }
    const maxDays = opt.dataset.maxDays;
    const requiresAttachment = opt.dataset.requiresAttachment === '1';
    let parts = [];
    if (maxDays) parts.push(`Up to ${maxDays} day(s) per year`);
    if (requiresAttachment) parts.push('attachment required for this leave type');
    hint.textContent = parts.length ? parts.join(' · ') : '';
});

/* ---------- generic combobox picker factory (used for Supervisor and HR) ---------- */
function makeEmployeePicker({ endpoint, inputId, hiddenId, resultsId }) {
    let list = [];
    let loaded = false;

    function load() {
        if (loaded) return Promise.resolve();
        return fetch(endpoint)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                list = Array.isArray(data) ? data : [];
                loaded = true;
            })
            .catch(err => {
                console.error('Failed to load list for ' + endpoint, err);
                list = [];
                const box = document.getElementById(resultsId);
                box.innerHTML = '<div class="list-group-item text-danger small">Could not load list. Check console for details.</div>';
                box.style.display = 'block';
            });
    }

    function render(filtered) {
        const box = document.getElementById(resultsId);
        if (!filtered.length) {
            box.innerHTML = '<div class="list-group-item text-muted small">No matching employees</div>';
            box.style.display = 'block';
            return;
        }
        const shown = filtered.slice(0, 200);
        box.innerHTML = shown.map(emp => `
            <a href="#" class="list-group-item list-group-item-action"
               data-emp-id="${escHtml(emp.EmployeeID)}" data-emp-name="${escHtml(emp.EmployeeName)}">
                ${escHtml(emp.EmployeeName)} <span class="text-muted small">(${escHtml(emp.EmployeeID)})</span>
            </a>
        `).join('') + (filtered.length > shown.length
            ? `<div class="list-group-item text-muted small">+ ${filtered.length - shown.length} more — keep typing to narrow down</div>`
            : '');
        box.style.display = 'block';
    }

    function filtered(q) {
        return q
            ? list.filter(e => (e.EmployeeName || '').toLowerCase().includes(q) || String(e.EmployeeID).toLowerCase().includes(q))
            : list;
    }

    const input = document.getElementById(inputId);

    input.addEventListener('focus', function () {
        load().then(() => render(filtered(this.value.trim().toLowerCase())));
    });

    input.addEventListener('input', function () {
        document.getElementById(hiddenId).value = '';
        render(filtered(this.value.trim().toLowerCase()));
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#' + inputId) && !e.target.closest('#' + resultsId)) {
            document.getElementById(resultsId).style.display = 'none';
        }
    });

    document.getElementById(resultsId).addEventListener('click', function (e) {
        const item = e.target.closest('a[data-emp-id]');
        if (!item) return;
        e.preventDefault();
        document.getElementById(hiddenId).value = item.dataset.empId;
        document.getElementById(inputId).value = item.dataset.empName;
        document.getElementById(resultsId).style.display = 'none';
    });

    return { reset: () => { document.getElementById(hiddenId).value = ''; document.getElementById(inputId).value = ''; } };
}

const supervisorPicker = makeEmployeePicker({
    endpoint: 'leave-employee-search.php?all=1',
    inputId: 'supervisorSearch', hiddenId: 'SA_EmployeeID', resultsId: 'supervisorResults'
});
const hrPicker = makeEmployeePicker({
    endpoint: 'leave-employee-search.php?hr=1',
    inputId: 'hrSearch', hiddenId: 'HR_EmployeeID', resultsId: 'hrResults'
});

/* ---------- submit new application ---------- */
document.getElementById('leaveForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('leaveFormAlert');
    alertBox.classList.add('d-none');

    if (!document.getElementById('SA_EmployeeID').value) {
        alertBox.textContent = 'Please select a supervisor from the list.';
        alertBox.classList.remove('d-none');
        return;
    }
    if (!document.getElementById('HR_EmployeeID').value) {
        alertBox.textContent = 'Please select an HR reviewer from the list.';
        alertBox.classList.remove('d-none');
        return;
    }

    const typeSelect = document.getElementById('TypeID');
    const typeOpt = typeSelect.options[typeSelect.selectedIndex];
    const fileInput = this.querySelector('input[name="Attachment"]');
    if (typeOpt && typeOpt.dataset.requiresAttachment === '1' && (!fileInput.files || !fileInput.files.length)) {
        alertBox.textContent = 'This leave type requires an attachment.';
        alertBox.classList.remove('d-none');
        return;
    }

    const btn = document.getElementById('btnSubmitLeave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';

    const formData = new FormData(this);
    const isEditing = !!editingId;
    if (isEditing) formData.set('id', editingId);
    const endpoint = isEditing ? 'leave-application-edit.php' : 'leave-application-save.php';
    const busyLabel = isEditing
        ? '<span class="spinner-border spinner-border-sm me-1"></span> Updating...'
        : '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';
    const idleLabel = isEditing
        ? '<i class="bi bi-send me-1"></i> Update Application'
        : '<i class="bi bi-send me-1"></i> Submit Application';
    btn.innerHTML = busyLabel;

    fetch(endpoint, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response from server. You may not have permission to perform this action.' })))
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = idleLabel;
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('newLeaveModal')).hide();
                loadApplications(1);
            } else {
                alertBox.textContent = res.message || 'Something went wrong. Please try again.';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = idleLabel;
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('d-none');
        });
});

/* ---------- filter listeners ---------- */
document.getElementById('fltStatus').addEventListener('change', () => loadApplications(1));
document.getElementById('fltType').addEventListener('change', () => loadApplications(1));
let searchDebounce;
document.getElementById('fltSearch').addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => loadApplications(1), 350);
});

document.addEventListener('DOMContentLoaded', () => loadApplications(1));
</script>

</body>
</html>