<?php
/* =====================================================================
   leave-application-list.php
   File location: TWM/LEAVE/leave-application-list.php
   RBAC module key: leave_approval

   Approval page. Two tabs:
     - "My Team"    : applications where I'm the assigned supervisor.
                      Available to any authenticated employee — it's
                      inherently self-scoped (SA_EmployeeID = me), so no
                      elevated permission is required to see it.
     - "Department" : HR-only. Applications from my own department where
                      the supervisor has already approved. Only rendered
                      if the user holds the 'hr' tier on leave_approval —
                      checked server-side here AND re-checked on every
                      leave-approval-data.php / leave-approval-action.php
                      call, since a hidden tab is not a security boundary.
   ===================================================================== */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_approval');
$topbar_page = 'leave_approval';
$navHtml = ob_get_clean();

$employeeID = $_SESSION['EmployeeID'] ?? '';

$isHr = false;
try {
    rbac_gate($pdo, 'leave_approval', 'hr');
    $isHr = true;
} catch (Throwable $e) {
    $isHr = false;
}

echo $navHtml;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Application Approvals</title>

<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/responsive-patch.css') ?>" rel="stylesheet">
<style>
    .badge-status { font-size: .78rem; padding: .35em .6em; border-radius: .35rem; font-weight: 500; }
    .badge-pending-sa, .badge-pending-hr { background: #fff3cd; color: #664d03; }
    .badge-rejected-sa, .badge-rejected { background: #f8d7da; color: #842029; }
    .badge-approved { background: #d1e7dd; color: #0f5132; }
    .mono { font-variant-numeric: tabular-nums; }
    .empty-state { text-align: center; color: #6c757d; padding: 2rem 0; }
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="container py-4">
    <h4 class="mb-1"><i class="bi bi-clipboard-check"></i> Leave Application Approvals</h4>
    <p class="text-muted mb-4">Review and act on leave applications assigned to you.</p>

    <ul class="nav nav-tabs mb-3" id="approvalTabs">
        <li class="nav-item">
            <button class="nav-link active" id="tab-sa" data-mode="supervisor" onclick="switchTab('supervisor')">
                <i class="bi bi-people"></i> My Team
            </button>
        </li>
        <?php if ($isHr): ?>
        <li class="nav-item">
            <button class="nav-link" id="tab-hr" data-mode="hr" onclick="switchTab('hr')">
                <i class="bi bi-building"></i> Department
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <div class="d-flex gap-2 mb-3">
        <input type="text" id="fltSearch" class="form-control form-control-sm" style="max-width:260px"
               placeholder="Search control no. or name" oninput="debouncedLoad()">
    </div>

    <div id="alertBox" class="alert d-none" role="alert"></div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Control No.</th>
                    <th>Applicant</th>
                    <th id="colDept" class="d-none">Department</th>
                    <th>Type</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Days</th>
                    <th>Supervisor</th>
                    <th>HR</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody id="approvalTableBody">
                <tr><td colspan="9" class="empty-state">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <nav><ul class="pagination pagination-sm" id="approvalPagination"></ul></nav>
</div>

<!-- View / Act Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text"></i> Leave Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewBody"></div>
      <div class="modal-footer" id="viewFooter"></div>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const CSRF_TOKEN = <?= json_encode(function_exists('rbac_csrf_token') ? rbac_csrf_token() : '') ?>;
let currentMode = 'supervisor';
let currentPage = 1;
let searchDebounce;

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function simpleBadge(status) {
    if (status === 'Approved') return '<span class="badge-status badge-approved">Approved</span>';
    if (status === 'Rejected') return '<span class="badge-status badge-rejected">Rejected</span>';
    return '<span class="badge-status badge-pending-sa">Pending</span>';
}

function switchTab(mode) {
    currentMode = mode;
    currentPage = 1;
    document.getElementById('tab-sa').classList.toggle('active', mode === 'supervisor');
    const tabHr = document.getElementById('tab-hr');
    if (tabHr) tabHr.classList.toggle('active', mode === 'hr');
    document.getElementById('colDept').classList.toggle('d-none', mode !== 'hr');
    loadApprovals();
}

function debouncedLoad() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => loadApprovals(1), 300);
}

function loadApprovals(page = 1) {
    currentPage = page;
    const params = new URLSearchParams({
        mode: currentMode,
        page: page,
        search: document.getElementById('fltSearch').value
    });

    fetch('leave-approval-data.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('approvalTableBody');
            const cols = currentMode === 'hr' ? 10 : 9;

            if (!data.success) {
                tbody.innerHTML = `<tr><td colspan="${cols}" class="text-danger text-center py-3">${escHtml(data.message || 'Failed to load.')}</td></tr>`;
                document.getElementById('approvalPagination').innerHTML = '';
                return;
            }
            if (!data.rows || data.rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${cols}"><div class="empty-state"><i class="bi bi-inbox fs-2 d-block mb-2"></i>${escHtml(data.message || 'No applications found')}</div></td></tr>`;
                document.getElementById('approvalPagination').innerHTML = '';
                return;
            }

            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td class="mono">${escHtml(r.ControlNo)}</td>
                    <td>${escHtml(r.ApplicantName)}</td>
                    <td class="d-none dept-cell">${escHtml(r.DepartmentName)}</td>
                    <td>${escHtml(r.TypeName)}</td>
                    <td class="mono">${escHtml(r.Date_Start)}</td>
                    <td class="mono">${escHtml(r.Date_End)}</td>
                    <td class="mono">${escHtml(r.NumberOfDays)}${r.HalfDay == 1 ? ' (½)' : ''}</td>
                    <td>${simpleBadge(r.SA_Status)}</td>
                    <td>${simpleBadge(r.HR_Status)}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" onclick="openApplication(${r.ID})">
                            <i class="bi bi-eye"></i> Review
                        </button>
                    </td>
                </tr>
            `).join('');

            if (currentMode === 'hr') {
                document.querySelectorAll('.dept-cell').forEach(el => el.classList.remove('d-none'));
            }

            renderPagination(data.totalPages, page);
        })
        .catch(err => {
            const tbody = document.getElementById('approvalTableBody');
            const cols = currentMode === 'hr' ? 10 : 9;
            tbody.innerHTML = `<tr><td colspan="${cols}" class="text-danger text-center py-3">
                Failed to load applications. Please refresh and try again.
            </td></tr>`;
            document.getElementById('approvalPagination').innerHTML = '';
            console.error('loadApprovals failed:', err);
        });
}

function renderPagination(totalPages, page) {
    const el = document.getElementById('approvalPagination');
    if (totalPages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="loadApprovals(${i}); return false;">${i}</a>
                 </li>`;
    }
    el.innerHTML = html;
}

function openApplication(id) {
    fetch('leave-approval-data.php?mode=' + currentMode + '&detail=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                showAlert(d.message || 'Failed to load application.', 'danger');
                return;
            }
            const r = d.row;
            const pendingStage = (currentMode === 'supervisor' && r.SA_Status === 'Pending')
                               || (currentMode === 'hr' && r.HR_Status === 'Pending');
            const canAct = pendingStage && r.CanApprove;

            document.getElementById('viewBody').innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6"><strong>Control No.</strong><div class="mono">${escHtml(r.ControlNo)}</div></div>
                    <div class="col-md-6"><strong>Applicant</strong><div>${escHtml(r.ApplicantName)} <span class="text-muted small">(${escHtml(r.DepartmentName)})</span></div></div>
                    <div class="col-md-6"><strong>Leave Type</strong><div>${escHtml(r.Type_Name)}</div></div>
                    <div class="col-md-6"><strong>Days</strong><div class="mono">${escHtml(r.NumberOfDays)}${r.HalfDay == 1 ? ' (½)' : ''}</div></div>
                    <div class="col-md-6"><strong>Start</strong><div class="mono">${escHtml(r.Date_Start)}</div></div>
                    <div class="col-md-6"><strong>End</strong><div class="mono">${escHtml(r.Date_End)}</div></div>
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
                    ${canAct ? `
                    <div class="col-12"><hr></div>
                    <div class="col-12">
                        <label class="form-label">Note ${currentMode === 'hr' || true ? '(required if rejecting)' : ''}</label>
                        <textarea id="actionNote" class="form-control" rows="2" placeholder="Optional remarks…"></textarea>
                    </div>` : ''}
                    ${pendingStage && !r.CanApprove ? `
                    <div class="col-12"><hr></div>
                    <div class="col-12 text-muted small">
                        <i class="bi bi-info-circle"></i> Only <strong>${escHtml(r.HRName || 'the assigned HR approver')}</strong> can act on this application.
                    </div>` : ''}
                </div>
            `;

            document.getElementById('viewFooter').innerHTML = canAct ? `
                <button type="button" class="btn btn-outline-danger" onclick="submitAction(${r.ID}, 'reject')">
                    <i class="bi bi-x-circle"></i> Reject
                </button>
                <button type="button" class="btn btn-success" onclick="submitAction(${r.ID}, 'approve')">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
            ` : `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>`;

            new bootstrap.Modal(document.getElementById('viewModal')).show();
        })
        .catch(err => {
            showAlert('Failed to load application. Please try again.', 'danger');
            console.error('openApplication failed:', err);
        });
}

function submitAction(id, action) {
    const note = document.getElementById('actionNote')?.value.trim() || '';
    if (action === 'reject' && !note) {
        showAlert('Please provide a note explaining the rejection.', 'danger');
        return;
    }

    const body = new URLSearchParams({
        id: id,
        role: currentMode === 'hr' ? 'hr' : 'sa',
        action: action,
        note: note,
        csrf_token: CSRF_TOKEN
    });

    fetch('leave-approval-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(d => {
            showAlert(d.message, d.success ? 'success' : 'danger');
            if (d.success) {
                bootstrap.Modal.getInstance(document.getElementById('viewModal'))?.hide();
                loadApprovals(currentPage);
            }
        })
        .catch(() => showAlert('Network error. Please try again.', 'danger'));
}

function showAlert(msg, type) {
    const box = document.getElementById('alertBox');
    box.className = 'alert alert-' + type;
    box.textContent = msg;
    box.classList.remove('d-none');
    setTimeout(() => box.classList.add('d-none'), 5000);
}

loadApprovals();
</script>
</body>
</html>