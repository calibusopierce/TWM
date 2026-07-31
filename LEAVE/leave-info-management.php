<?php
/* =====================================================================
   leave-info-management.php
   File location: TWM/LEAVE/leave-info-management.php
   RBAC module key: leave_management  (separate from leave_application —
   this page manages the leave TYPE catalog + employee CREDIT balances,
   not individual applications, so it warrants its own gate. Assign it
   to whichever roles you land on — HR only, HR+Admin, etc. — via the
   Categories/Users tab like your other modules.)

   Tables involved (as provided):
     dbo.Tbl_Leave_Type  — ID, Code, Type_Name, Category, With_Pay,
                            Regular_Credit, Requires_Attachment,
                            Max_Days_Per_Year, Carry_Forward, Status,
                            UserInput, DateTimeInput
     dbo.Tbl_Leave_Qty   — ID, ControlNo, LeaveID, EmployeeID, Year, Qty

   ASSUMPTIONS (verify/adjust):
   - "Active employee" scoping for bulk-assign uses TBL_HREmployeeList.Active = 1
     (confirmed against the real table schema).
   - topbar.php is included via base_url('assets/css/topbar.css'), matching
     the confirmed pattern from careers-admin.php.
   - This page's own <style> block used generic :root variable names
     (--primary, --surface, etc.) that likely collided with topbar.css's own
     globals, overriding the topbar's purple accent with this page's blue.
     Renamed to --pg-* to scope them to this page only.
   ===================================================================== */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'leave_management');
$isViewOnly = rbac_is_view_only('leave_management'); // hide/disable write actions if true
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Leave Information Management - TWM</title>

<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/responsive-patch.css') ?>" rel="stylesheet">

<style>
/* Page-specific styles only — admin.css owns .page-header/.page-title/
   .page-subtitle/.table-card and the base typography, so this page no
   longer redefines them (that collision was overriding the shared
   topbar/admin look with this page's own colors). */
.pg-card-panel{ background:#fff; border:1px solid var(--border,#e4e7ec); border-radius:var(--radius,10px); padding:20px; margin-bottom:20px; }
.mono{ font-family:'Courier New',monospace; }
.nav-tabs .nav-link{ font-weight:500; }
.filter-bar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.filter-bar .form-select, .filter-bar .form-control{ max-width:220px; }
table thead th{ font-size:.78rem; text-transform:uppercase; letter-spacing:.03em; white-space:nowrap; }
table td{ vertical-align:middle; font-size:.9rem; }
.badge-status{ padding:.4em .75em; border-radius:20px; font-weight:500; font-size:.75rem; }
.badge-active{ background:#dff6e6; color:#177a3b; }
.badge-inactive{ background:#f1f2f5; color:#6b7280; }
.empty-state{ text-align:center; padding:40px 0; }
</style>
</head>
<body>

<?php $topbar_page = 'leave_management'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

    <div class="page-header">
        <div>
            <div class="page-title"><i class="bi bi-sliders me-2"></i>Leave Information Management</div>
            <div class="page-subtitle">Maintain leave types and employee leave credits</div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="mgmtTabs">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabTypes">
                <i class="bi bi-tags me-1"></i> Leave Types
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCredits">
                <i class="bi bi-wallet2 me-1"></i> Employee Leave Credits
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ===================== TAB 1: LEAVE TYPES ===================== -->
        <div class="tab-pane fade show active" id="tabTypes">
            <div class="pg-card-panel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <input type="text" id="typeSearch" class="form-control" style="max-width:260px" placeholder="Search code / name...">
                    <?php if (!$isViewOnly): ?>
                    <button class="btn btn-primary" onclick="openTypeModal()">
                        <i class="bi bi-plus-lg me-1"></i> Add Leave Type
                    </button>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table" id="typesTable">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Type Name</th>
                                <th>Category</th>
                                <th>With Pay</th>
                                <th>Regular Credit</th>
                                <th>Requires Attachment</th>
                                <th>Max Days/Yr</th>
                                <th>Carry Forward</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="typesTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===================== TAB 2: EMPLOYEE LEAVE CREDITS ===================== -->
        <div class="tab-pane fade" id="tabCredits">
            <div class="pg-card-panel">
                <div class="filter-bar mb-3">
                    <input type="text" id="creditSearch" class="form-control" placeholder="Search employee...">
                    <select id="creditYear" class="form-select"></select>
                    <select id="creditType" class="form-select">
                        <option value="">All Leave Types</option>
                    </select>
                    <?php if (!$isViewOnly): ?>
                    <div class="ms-auto d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="openBulkAssignModal()">
                            <i class="bi bi-people me-1"></i> Bulk Assign Credits
                        </button>
                        <button class="btn btn-primary" onclick="openQtyModal()">
                            <i class="bi bi-plus-lg me-1"></i> Add / Edit Credit
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Control No.</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Leave Type</th>
                                <th>Year</th>
                                <th>Qty</th>
                                <th>Used</th>
                                <th>Balance</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="qtyTableBody"></tbody>
                    </table>
                </div>
                <nav><ul class="pagination justify-content-end" id="qtyPagination"></ul></nav>
            </div>
        </div>
    </div>
</div>

<!-- ===================== Leave Type Modal ===================== -->
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="typeForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(rbac_csrf_token()) ?>">
        <input type="hidden" name="ID" id="type_ID">
        <div class="modal-header">
          <h5 class="modal-title" id="typeModalTitle"><i class="bi bi-tag me-2"></i>Add Leave Type</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Code <span class="text-danger">*</span></label>
              <input type="text" name="Code" id="type_Code" class="form-control" maxlength="20" required>
            </div>
            <div class="col-md-9">
              <label class="form-label">Type Name <span class="text-danger">*</span></label>
              <input type="text" name="Type_Name" id="type_Type_Name" class="form-control" maxlength="100" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Category</label>
              <input type="text" name="Category" id="type_Category" class="form-control" maxlength="50" placeholder="e.g. Statutory, Company">
            </div>
            <div class="col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="Regular_Credit" id="type_Regular_Credit" value="1">
                <label class="form-check-label" for="type_Regular_Credit">Has Regular Credit</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Max Days per Year</label>
              <input type="number" step="0.5" min="0" name="Max_Days_Per_Year" id="type_Max_Days_Per_Year" class="form-control">
            </div>
            <div class="col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="With_Pay" id="type_With_Pay" value="1">
                <label class="form-check-label" for="type_With_Pay">With Pay</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="Requires_Attachment" id="type_Requires_Attachment" value="1">
                <label class="form-check-label" for="type_Requires_Attachment">Requires Attachment</label>
              </div>
            </div>
            <div class="col-md-4">
              <div class="form-check mt-4">
                <input class="form-check-input" type="checkbox" name="Carry_Forward" id="type_Carry_Forward" value="1">
                <label class="form-check-label" for="type_Carry_Forward">Carry Forward to Next Year</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="Status" id="type_Status" class="form-select">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
          </div>
          <div id="typeFormAlert" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Leave Type</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===================== Add/Edit Single Credit Modal ===================== -->
<div class="modal fade" id="qtyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="qtyForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(rbac_csrf_token()) ?>">
        <input type="hidden" name="ID" id="qty_ID">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-wallet2 me-2"></i>Add / Edit Leave Credit</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3 position-relative">
            <label class="form-label">Employee <span class="text-danger">*</span></label>
            <input type="text" id="qtyEmployeeSearch" class="form-control" placeholder="Type employee name..." autocomplete="off" required>
            <input type="hidden" name="EmployeeID" id="qty_EmployeeID">
            <div id="qtyEmployeeResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:2000; max-height:220px; overflow-y:auto; display:none;"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
            <select name="LeaveID" id="qty_LeaveID" class="form-select" required></select>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Year <span class="text-danger">*</span></label>
              <input type="number" name="Year" id="qty_Year" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Qty (days) <span class="text-danger">*</span></label>
              <input type="number" step="0.5" min="0" name="Qty" id="qty_Qty" class="form-control" required>
            </div>
          </div>
          <div id="qtyFormAlert" class="alert alert-danger mt-3 d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Credit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===================== Bulk Assign Modal ===================== -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(rbac_csrf_token()) ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-people me-2"></i>Bulk Assign Leave Credits</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">This assigns the chosen quantity to <strong>every active employee</strong> for the selected leave type and year. Employees who already have a credit record for that type/year will have it <strong>updated</strong>, not duplicated.</p>
          <div class="mb-3">
            <label class="form-label">Leave Type <span class="text-danger">*</span></label>
            <select name="LeaveID" id="bulk_LeaveID" class="form-select" required></select>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Year <span class="text-danger">*</span></label>
              <input type="number" name="Year" id="bulk_Year" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Qty (days) <span class="text-danger">*</span></label>
              <input type="number" step="0.5" min="0" name="Qty" id="bulk_Qty" class="form-control" required>
            </div>
          </div>
          <div id="bulkFormAlert" class="alert alert-danger mt-3 d-none"></div>
          <div id="bulkFormResult" class="alert alert-success mt-3 d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnBulkSubmit">
            <i class="bi bi-check2-circle me-1"></i> Confirm &amp; Assign
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const IS_VIEW_ONLY = <?= json_encode($isViewOnly) ?>;
let leaveTypesCache = [];
let currentQtyPage = 1;

/* ================= Leave Types tab ================= */
function loadTypes() {
    const q = document.getElementById('typeSearch').value;
    fetch('leave-type-list.php?search=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            leaveTypesCache = data.rows || [];
            populateTypeDropdowns();

            const tbody = document.getElementById('typesTableBody');
            if (!leaveTypesCache.length) {
                tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state">No leave types found</div></td></tr>';
                return;
            }
            tbody.innerHTML = leaveTypesCache.map(t => `
                <tr>
                    <td class="mono">${t.Code}</td>
                    <td>${t.Type_Name}</td>
                    <td>${t.Category ?? '-'}</td>
                    <td>${t.With_Pay == 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>'}</td>
                    <td>${t.Regular_Credit == 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>'}</td>
                    <td>${t.Requires_Attachment == 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>'}</td>
                    <td class="mono">${t.Max_Days_Per_Year ?? '-'}</td>
                    <td>${t.Carry_Forward == 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-dash text-muted"></i>'}</td>
                    <td><span class="badge-status ${t.Status == 1 ? 'badge-active' : 'badge-inactive'}">${t.Status == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td class="text-end">
                        ${IS_VIEW_ONLY ? '' : `<button class="btn btn-sm btn-outline-primary" onclick="openTypeModal(${t.ID})"><i class="bi bi-pencil"></i></button>`}
                    </td>
                </tr>
            `).join('');
        });
}

function populateTypeDropdowns() {
    const opts = leaveTypesCache
        .filter(t => t.Status == 1)
        .map(t => `<option value="${t.ID}">${t.Type_Name}</option>`).join('');

    document.getElementById('creditType').innerHTML = '<option value="">All Leave Types</option>' + opts;
    document.getElementById('qty_LeaveID').innerHTML = opts;
    document.getElementById('bulk_LeaveID').innerHTML = opts;
}

function openTypeModal(id = null) {
    document.getElementById('typeForm').reset();
    document.getElementById('typeFormAlert').classList.add('d-none');
    document.getElementById('type_ID').value = '';
    document.getElementById('typeModalTitle').innerHTML = '<i class="bi bi-tag me-2"></i>Add Leave Type';

    if (id) {
        const t = leaveTypesCache.find(x => x.ID == id);
        if (t) {
            document.getElementById('typeModalTitle').innerHTML = '<i class="bi bi-tag me-2"></i>Edit Leave Type';
            document.getElementById('type_ID').value = t.ID;
            document.getElementById('type_Code').value = t.Code;
            document.getElementById('type_Type_Name').value = t.Type_Name;
            document.getElementById('type_Category').value = t.Category ?? '';
            document.getElementById('type_Regular_Credit').checked = t.Regular_Credit == 1;
            document.getElementById('type_Max_Days_Per_Year').value = t.Max_Days_Per_Year ?? '';
            document.getElementById('type_With_Pay').checked = t.With_Pay == 1;
            document.getElementById('type_Requires_Attachment').checked = t.Requires_Attachment == 1;
            document.getElementById('type_Carry_Forward').checked = t.Carry_Forward == 1;
            document.getElementById('type_Status').value = t.Status == 1 ? 'Active' : 'Inactive';
        }
    }
    new bootstrap.Modal(document.getElementById('typeModal')).show();
}

document.getElementById('typeForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('typeFormAlert');
    alertBox.classList.add('d-none');
    const formData = new FormData(this);

    fetch('leave-type-save.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response. You may not have permission to perform this action.' })))
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('typeModal')).hide();
                loadTypes();
            } else {
                alertBox.textContent = (res.message || 'Failed to save leave type.') + (res.debug ? ' — ' + res.debug : '');
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('d-none');
        });
});

let typeSearchDebounce;
document.getElementById('typeSearch').addEventListener('input', () => {
    clearTimeout(typeSearchDebounce);
    typeSearchDebounce = setTimeout(loadTypes, 300);
});

/* ================= Employee Leave Credits tab ================= */
function initYearDropdown() {
    const sel = document.getElementById('creditYear');
    const current = new Date().getFullYear();
    let html = '<option value="">All Years</option>';
    for (let y = current + 1; y >= current - 3; y--) {
        html += `<option value="${y}" ${y === current ? 'selected' : ''}>${y}</option>`;
    }
    sel.innerHTML = html;

    // default bulk/qty year inputs to current year
    document.getElementById('bulk_Year').value = current;
    document.getElementById('qty_Year').value = current;
}

function loadQty(page = 1) {
    currentQtyPage = page;
    const params = new URLSearchParams({
        page: page,
        search: document.getElementById('creditSearch').value,
        year: document.getElementById('creditYear').value,
        type: document.getElementById('creditType').value
    });

    fetch('leave-qty-list.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('qtyTableBody');
            if (!data.rows || !data.rows.length) {
                tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state">No leave credit records found</div></td></tr>';
                document.getElementById('qtyPagination').innerHTML = '';
                return;
            }
            tbody.innerHTML = data.rows.map(r => `
                <tr>
                    <td class="mono">${r.ControlNo}</td>
                    <td>${r.EmployeeName ?? r.EmployeeID}</td>
                    <td>${r.Department ?? '-'}</td>
                    <td>${r.Position_held ?? '-'}</td>
                    <td>${r.Type_Name}</td>
                    <td class="mono">${r.Year}</td>
                    <td class="mono">${r.Qty}</td>
                    <td class="mono">${r.TotalLeave}</td>
                    <td class="mono">${r.TotalBalance}</td>
                    <td class="text-end">
                        ${IS_VIEW_ONLY ? '' : `<button class="btn btn-sm btn-outline-primary" onclick='openQtyModal(${JSON.stringify(r)})'><i class="bi bi-pencil"></i></button>`}
                    </td>
                </tr>
            `).join('');
            renderQtyPagination(data.totalPages, page);
        });
}

function renderQtyPagination(totalPages, page) {
    const el = document.getElementById('qtyPagination');
    if (totalPages <= 1) { el.innerHTML = ''; return; }
    let html = '';
    for (let i = 1; i <= totalPages; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
                    <a class="page-link" href="#" onclick="loadQty(${i}); return false;">${i}</a>
                 </li>`;
    }
    el.innerHTML = html;
}

function openQtyModal(row = null) {
    document.getElementById('qtyForm').reset();
    document.getElementById('qtyFormAlert').classList.add('d-none');
    document.getElementById('qty_ID').value = '';
    document.getElementById('qty_EmployeeID').value = '';
    document.getElementById('qtyEmployeeSearch').value = '';
    document.getElementById('qty_Year').value = new Date().getFullYear();

    if (row) {
        document.getElementById('qty_ID').value = row.ID;
        document.getElementById('qty_EmployeeID').value = row.EmployeeID;
        document.getElementById('qtyEmployeeSearch').value = row.EmployeeName ?? row.EmployeeID;
        document.getElementById('qty_LeaveID').value = row.LeaveID;
        document.getElementById('qty_Year').value = row.Year;
        document.getElementById('qty_Qty').value = row.Qty;
    }
    new bootstrap.Modal(document.getElementById('qtyModal')).show();
}

document.getElementById('qtyForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('qtyFormAlert');
    alertBox.classList.add('d-none');
    if (!document.getElementById('qty_EmployeeID').value) {
        alertBox.textContent = 'Please select an employee from the list.';
        alertBox.classList.remove('d-none');
        return;
    }
    const formData = new FormData(this);
    fetch('leave-qty-save.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response. You may not have permission to perform this action.' })))
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('qtyModal')).hide();
                loadQty(currentQtyPage);
            } else {
                alertBox.textContent = res.message || 'Failed to save credit.';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('d-none');
        });
});

/* employee autocomplete for the single-credit modal (reuses leave-employee-search.php) */
let qtyEmpDebounce;
document.getElementById('qtyEmployeeSearch').addEventListener('input', function () {
    clearTimeout(qtyEmpDebounce);
    const q = this.value.trim();
    const box = document.getElementById('qtyEmployeeResults');
    if (q.length < 2) { box.style.display = 'none'; return; }
    qtyEmpDebounce = setTimeout(() => {
        fetch('leave-employee-search.php?q=' + encodeURIComponent(q) + '&includeSelf=1')
            .then(r => r.json())
            .then(list => {
                if (!list.length) { box.style.display = 'none'; return; }
                box.innerHTML = list.map(emp => `
                    <a href="#" class="list-group-item list-group-item-action"
                       onclick="selectQtyEmployee('${emp.EmployeeID}', '${emp.EmployeeName.replace(/'/g, "\\'")}'); return false;">
                        ${emp.EmployeeName} <span class="text-muted small">(${emp.EmployeeID})</span>
                    </a>
                `).join('');
                box.style.display = 'block';
            });
    }, 250);
});
function selectQtyEmployee(id, name) {
    document.getElementById('qty_EmployeeID').value = id;
    document.getElementById('qtyEmployeeSearch').value = name;
    document.getElementById('qtyEmployeeResults').style.display = 'none';
}

document.getElementById('creditYear').addEventListener('change', () => loadQty(1));
document.getElementById('creditType').addEventListener('change', () => loadQty(1));
let creditSearchDebounce;
document.getElementById('creditSearch').addEventListener('input', () => {
    clearTimeout(creditSearchDebounce);
    creditSearchDebounce = setTimeout(() => loadQty(1), 350);
});

/* ================= Bulk assign ================= */
function openBulkAssignModal() {
    document.getElementById('bulkForm').reset();
    document.getElementById('bulkFormAlert').classList.add('d-none');
    document.getElementById('bulkFormResult').classList.add('d-none');
    document.getElementById('bulk_Year').value = new Date().getFullYear();
    new bootstrap.Modal(document.getElementById('bulkModal')).show();
}

// NOTE: Regular_Credit is a yes/no flag (bit), not a day count, so it can no
// longer be used to auto-fill the bulk Qty field. HR enters the qty manually.

document.getElementById('bulkForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const alertBox = document.getElementById('bulkFormAlert');
    const resultBox = document.getElementById('bulkFormResult');
    alertBox.classList.add('d-none');
    resultBox.classList.add('d-none');

    const leaveTypeName = leaveTypesCache.find(x => x.ID == document.getElementById('bulk_LeaveID').value)?.Type_Name || 'this leave type';
    const year = document.getElementById('bulk_Year').value;
    const qty = document.getElementById('bulk_Qty').value;

    if (!confirm(`Assign ${qty} day(s) of "${leaveTypeName}" to ALL active employees for ${year}? Existing records for that type/year will be updated.`)) {
        return;
    }

    const btn = document.getElementById('btnBulkSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Assigning...';

    const formData = new FormData(this);
    formData.append('bulk', '1');

    fetch('leave-qty-save.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json().catch(() => ({ success: false, message: 'Unexpected response. You may not have permission to perform this action.' })))
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm & Assign';
            if (res.success) {
                resultBox.textContent = `Done — ${res.affected} employee(s) updated.`;
                resultBox.classList.remove('d-none');
                loadQty(1);
            } else {
                alertBox.textContent = res.message || 'Bulk assign failed.';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm & Assign';
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('d-none');
        });
});

document.addEventListener('DOMContentLoaded', () => {
    initYearDropdown();
    loadTypes();
    loadQty(1);
});
</script>

</body>
</html>