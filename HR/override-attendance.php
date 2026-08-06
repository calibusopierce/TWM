<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'override_attendance');
$is_view_only = rbac_is_view_only('override_attendance');

date_default_timezone_set('Asia/Manila');
$empName   = trim($_SESSION['DisplayName'] ?? 'User');
$roleLabel = trim($_SESSION['UserType']    ?? 'Employee');

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Override Employee Attendance · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
.oea-wrap { max-width: 1500px; margin: 0 auto; padding: 1.25rem; }

.oea-hero { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; }
.oea-hero h1 { font-size:1.4rem; font-weight:800; color:#1e1b4b; margin:0; }
.oea-hero .sub { font-size:.85rem; color:#64748b; margin-top:.2rem; }
.oea-hero .clock { font-size:.85rem; color:#64748b; }

.oea-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; margin-bottom:1.1rem; }
.oea-card-head { padding:.9rem 1.2rem; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:.88rem; color:#1e1b4b; text-transform:uppercase; letter-spacing:.03em; display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.oea-card-head small { text-transform:none; letter-spacing:0; font-weight:500; color:#94a3b8; font-size:.72rem; }
.oea-card-body { padding:1.1rem 1.2rem; }

.oea-top-grid { display:grid; grid-template-columns: 1fr 320px; gap:1.1rem; align-items:start; }
@media (max-width: 1100px) { .oea-top-grid { grid-template-columns:1fr; } }

.oea-mid-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:1.1rem; align-items:start; }
@media (max-width: 1100px) { .oea-mid-grid { grid-template-columns:1fr; } }

.oea-bottom-grid { display:grid; grid-template-columns: 1fr 1fr; gap:1.1rem; align-items:start; }
@media (max-width: 900px) { .oea-bottom-grid { grid-template-columns:1fr; } }

.oea-field { margin-bottom:.9rem; }
.oea-field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:.3rem; }
.oea-field input, .oea-field select, .oea-field textarea {
    width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .7rem;
    font-size:.85rem; color:#0f172a; background:#fff; font-family:inherit;
}
.oea-field input:read-only, .oea-field input:disabled { background:#f8fafc; color:#334155; }
.oea-field-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap:.9rem; }

.oea-schedule-table { width:100%; border-collapse:collapse; font-size:.82rem; margin-bottom:.9rem; }
.oea-schedule-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:6px 6px; border-bottom:2px solid #e2e8f0; }
.oea-schedule-table td { padding:8px 6px; border-bottom:1px solid #f1f5f9; }
.oea-schedule-table .oea-actual { color:#2563eb; font-weight:700; }
.oea-schedule-table .oea-late { color:#ef4444; font-weight:700; }

.oea-summary-row { display:flex; align-items:center; justify-content:space-between; padding:.5rem 0; border-bottom:1px solid #f1f5f9; font-size:.85rem; }
.oea-summary-row:last-of-type { border-bottom:none; }
.oea-summary-row .lbl { color:#64748b; font-weight:600; }
.oea-summary-row .val { font-family:'JetBrains Mono',monospace; font-weight:700; color:#0f172a; }

.oea-table-wrap { overflow-x:auto; }
.oea-table { width:100%; border-collapse:collapse; font-size:.8rem; white-space:nowrap; }
.oea-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:8px 8px; border-bottom:2px solid #e2e8f0; position:sticky; top:0; background:#fff; }
.oea-table td { padding:8px 8px; border-bottom:1px solid #f1f5f9; }
.oea-badge { padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.oea-badge-in { background:#dcfce7; color:#15803d; }
.oea-badge-out { background:#fee2e2; color:#b91c1c; }

.oea-btn-row { display:flex; flex-wrap:wrap; gap:.6rem; padding:1rem 1.2rem; }
.oea-btn { border:none; border-radius:8px; padding:.55rem 1.1rem; font-size:.82rem; font-weight:700; display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; color:#fff; }
.oea-btn:disabled { opacity:.5; cursor:not-allowed; }
.oea-btn-primary { background:#2563eb; }
.oea-btn-success { background:#16a34a; }
.oea-btn-warning { background:#f59e0b; }
.oea-btn-danger  { background:#ef4444; }
.oea-btn-secondary { background:#64748b; }

.oea-typeahead-dropdown {
    position:absolute; top:100%; left:0; right:0; z-index:20;
    background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-top:.25rem;
    box-shadow:0 8px 20px rgba(15,23,42,.1); max-height:240px; overflow-y:auto; display:none;
}
.oea-typeahead-item { padding:.55rem .8rem; cursor:pointer; font-size:.83rem; border-bottom:1px solid #f1f5f9; }
.oea-typeahead-item:last-child { border-bottom:none; }
.oea-typeahead-item:hover { background:#eff6ff; }
.oea-typeahead-item .name { font-weight:700; color:#0f172a; }
.oea-typeahead-item .meta { color:#94a3b8; font-size:.72rem; margin-top:1px; }
.oea-typeahead-empty { padding:.6rem .8rem; color:#94a3b8; font-size:.8rem; }

.oea-error { color:#ef4444; font-size:.8rem; margin-top:.5rem; display:none; }
.oea-footer-meta { font-size:.75rem; color:#94a3b8; padding:.8rem 1.2rem; border-top:1px solid #e2e8f0; display:flex; gap:1.5rem; flex-wrap:wrap; }
</style>
</head>
<body>
<input type="hidden" id="csrfToken" value="<?= h(rbac_csrf_token()) ?>">

<?php
$topbar_page = 'override_attendance';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'override_attendance';
require_once __DIR__ . '/hr_nav.php';
?>

<div class="oea-wrap">

  <div class="oea-hero">
    <div>
      <h1><i class="bi bi-calendar-check"></i> Override Employee Attendance</h1>
      <div class="sub">Urban Tradewell Corporation — <?= h($empName) ?> (<?= h($roleLabel) ?>)</div>
    </div>
    <div class="clock"><i class="bi bi-calendar3"></i> <?= date('l, F j, Y · h:i A') ?></div>
  </div>

  <!-- ============ ROW 1: EMPLOYEE INFORMATION + SUMMARY ============ -->
  <div class="oea-top-grid">

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-person-badge"></i> Employee Information</span></div>
      <div class="oea-card-body">
        <div class="oea-field-row">
          <div class="oea-field">
            <label>Employee ID</label>
            <input type="text" id="txtEmployeeID" placeholder="Enter Employee ID" <?= $is_view_only ? 'disabled' : '' ?>>
          </div>
          <div class="oea-field">
            <label>Device ID</label>
            <input type="text" id="txtDeviceID" readonly>
          </div>
          <div class="oea-field" style="grid-column: span 2; position:relative;">
            <label>Employee Name <span style="font-weight:400; text-transform:none; color:#94a3b8;">(search)</span></label>
            <input type="text" id="txtEmployeeName" autocomplete="off" placeholder="Type a name to search" <?= $is_view_only ? 'disabled' : '' ?>>
            <div id="empNameResults" class="oea-typeahead-dropdown"></div>
          </div>
          <div class="oea-field">
            <label>Attendance Date</label>
            <input type="date" id="txtAttendanceDate" value="<?= date('Y-m-d') ?>" <?= $is_view_only ? 'disabled' : '' ?>>
          </div>
          <div class="oea-field">
            <label>Day</label>
            <input type="text" id="txtDay" readonly>
          </div>
          <div class="oea-field">
            <label>Department</label>
            <input type="text" id="txtDepartment" readonly>
          </div>
          <div class="oea-field">
            <label>Position</label>
            <input type="text" id="txtPosition" readonly>
          </div>
        </div>
        <div id="empInfoError" class="oea-error"></div>
      </div>
    </div>

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-clipboard-data"></i> Summary</span> <small>After Override</small></div>
      <div class="oea-card-body">
        <div class="oea-summary-row"><span class="lbl">Total Late AM</span><span class="val" id="sumLateAM">00:00</span></div>
        <div class="oea-summary-row"><span class="lbl">Total Late PM</span><span class="val" id="sumLatePM">00:00</span></div>
        <div class="oea-summary-row"><span class="lbl">Total Undertime</span><span class="val" id="sumUndertime">00:00</span></div>
        <div class="oea-summary-row"><span class="lbl">Total Hours Worked</span><span class="val" id="sumHoursWorked">00:00</span></div>
        <button type="button" class="oea-btn oea-btn-primary" id="btnRecalculate" style="width:100%; justify-content:center; margin-top:.8rem;">
          <i class="bi bi-arrow-repeat"></i> Recalculate
        </button>
      </div>
    </div>

  </div>

  <!-- ============ ROW 2: AM / PM SCHEDULE + OVERRIDE-CORRECTION ============ -->
  <div class="oea-mid-grid">

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-sunrise"></i> AM Schedule &amp; Actual</span></div>
      <div class="oea-card-body">
        <table class="oea-schedule-table">
          <thead><tr><th></th><th>Schedule</th><th>Actual (From Logs)</th></tr></thead>
          <tbody>
            <tr><td>Time In</td><td id="amSchedIn">--:--</td><td class="oea-actual" id="amActualIn">--:--</td></tr>
            <tr><td>Time Out</td><td id="amSchedOut">--:--</td><td class="oea-actual" id="amActualOut">--:--</td></tr>
          </tbody>
        </table>
        <div class="oea-field-row">
          <div class="oea-field"><label>Total Hours (AM)</label><input type="text" id="amTotalHours" readonly></div>
          <div class="oea-field"><label>Late (AM)</label><input type="text" id="amLate" class="oea-late" readonly></div>
        </div>
      </div>
    </div>

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-sunset"></i> PM Schedule &amp; Actual</span></div>
      <div class="oea-card-body">
        <table class="oea-schedule-table">
          <thead><tr><th></th><th>Schedule</th><th>Actual (From Logs)</th></tr></thead>
          <tbody>
            <tr><td>Time In</td><td id="pmSchedIn">--:--</td><td class="oea-actual" id="pmActualIn">--:--</td></tr>
            <tr><td>Time Out</td><td id="pmSchedOut">--:--</td><td class="oea-actual" id="pmActualOut">--:--</td></tr>
          </tbody>
        </table>
        <div class="oea-field-row">
          <div class="oea-field"><label>Total Hours (PM)</label><input type="text" id="pmTotalHours" readonly></div>
          <div class="oea-field"><label>Late (PM)</label><input type="text" id="pmLate" class="oea-late" readonly></div>
        </div>
      </div>
    </div>

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-pencil-square"></i> Override / Correction</span></div>
      <div class="oea-card-body">
        <div class="oea-field">
          <label>Shift Part</label>
          <select id="ovShiftPart" <?= $is_view_only ? 'disabled' : '' ?>>
            <option value="AM">AM</option>
            <option value="PM">PM</option>
          </select>
        </div>
        <div class="oea-field">
          <label>Direction</label>
          <select id="ovDirection" <?= $is_view_only ? 'disabled' : '' ?>>
            <option value="IN">IN = Time In</option>
            <option value="OUT">OUT = Time Out</option>
          </select>
        </div>
        <div class="oea-field">
          <label>Original Time</label>
          <input type="text" id="ovOriginalTime" readonly>
        </div>
        <div class="oea-field">
          <label>Corrected Time</label>
          <input type="time" id="ovCorrectedTime" <?= $is_view_only ? 'disabled' : '' ?>>
        </div>
        <div class="oea-field">
          <label>Override Category</label>
          <select id="ovCategory" <?= $is_view_only ? 'disabled' : '' ?>>
            <option value="">Loading…</option>
          </select>
        </div>
        <div class="oea-field">
          <label>Override Type / Reason</label>
          <select id="ovType" <?= $is_view_only ? 'disabled' : '' ?>>
            <option value="">Loading…</option>
          </select>
        </div>
        <div class="oea-field">
          <label>Remarks</label>
          <textarea id="ovRemarks" rows="3" <?= $is_view_only ? 'disabled' : '' ?>></textarea>
        </div>
        <div id="ovStatusMsg" class="oea-error"></div>
        <div id="ovSuccessMsg" style="color:#16a34a; font-size:.8rem; margin-top:.5rem; display:none;"></div>
      </div>
    </div>

  </div>

  <!-- ============ ROW 3: ATTENDANCE LOGS + OVERRIDE HISTORY ============ -->
  <div class="oea-bottom-grid">

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-list-check"></i> Attendance Logs</span> <small>From System</small></div>
      <div class="oea-table-wrap">
        <table class="oea-table" id="tblAttendanceLogs">
          <thead>
            <tr><th>#</th><th>Date</th><th>Time</th><th>Direction</th><th>Shift Part</th><th>Category</th><th>Data From</th><th>Area</th></tr>
          </thead>
          <tbody id="tblAttendanceLogsBody">
            <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:1.5rem;">Enter an Employee ID above to load logs.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-clock-history"></i> Override History</span></div>
      <div class="oea-table-wrap">
        <table class="oea-table" id="tblOverrideHistory">
          <thead>
            <tr><th>Date</th><th>Shift Part</th><th>Direction</th><th>Original Time</th><th>Corrected Time</th><th>Category</th><th>Data From</th></tr>
          </thead>
          <tbody id="tblOverrideHistoryBody">
            <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:1.5rem;">Enter an Employee ID above to load override history.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- ============ BUTTONS ============ -->
  <div class="oea-card">
    <div class="oea-btn-row">
      <button type="button" class="oea-btn oea-btn-primary" id="btnNew"><i class="bi bi-file-earmark"></i> New</button>
      <button type="button" class="oea-btn oea-btn-success" id="btnSaveOverride" <?= $is_view_only ? 'disabled' : '' ?>><i class="bi bi-save"></i> Save Override</button>
      <button type="button" class="oea-btn oea-btn-warning" id="btnUpdate" <?= $is_view_only ? 'disabled' : '' ?>><i class="bi bi-pencil"></i> Update</button>
      <button type="button" class="oea-btn oea-btn-danger" id="btnDelete" <?= $is_view_only ? 'disabled' : '' ?>><i class="bi bi-trash"></i> Delete</button>
      <button type="button" class="oea-btn oea-btn-secondary" id="btnCancel">Cancel</button>
    </div>
    <div class="oea-footer-meta">
      <span>Created By: <b id="metaCreatedBy">—</b></span>
      <span>Created Date: <b id="metaCreatedDate">—</b></span>
      <span>Last Updated By: <b id="metaUpdatedBy">—</b></span>
      <span>Last Updated Date: <b id="metaUpdatedDate">—</b></span>
    </div>
  </div>

</div> <!-- /.oea-wrap -->

  </main>
</div> <!-- /.hr-shell (opened by hr_nav.php) -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const empIdInput    = document.getElementById('txtEmployeeID');
    const empNameInput  = document.getElementById('txtEmployeeName');
    const empNameResults = document.getElementById('empNameResults');
    const dateInput     = document.getElementById('txtAttendanceDate');
    const empInfoError  = document.getElementById('empInfoError');
    const logsBody      = document.getElementById('tblAttendanceLogsBody');
    const historyBody   = document.getElementById('tblOverrideHistoryBody');
    const csrfToken      = document.getElementById('csrfToken').value;
    const ovShiftPart    = document.getElementById('ovShiftPart');
    const ovDirection    = document.getElementById('ovDirection');
    const ovOriginalTime = document.getElementById('ovOriginalTime');
    const ovCorrectedTime = document.getElementById('ovCorrectedTime');
    const ovCategory     = document.getElementById('ovCategory');
    const ovType         = document.getElementById('ovType');
    const ovRemarks      = document.getElementById('ovRemarks');
    const ovStatusMsg    = document.getElementById('ovStatusMsg');
    const ovSuccessMsg   = document.getElementById('ovSuccessMsg');
    const btnSaveOverride = document.getElementById('btnSaveOverride');

    let currentLogs = []; // last-loaded Attendance Logs, used to auto-fill Original Time
    let allOverrideTypes = [];

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function clearEmployeeInfo() {
        ['txtDepartment', 'txtPosition', 'txtDay', 'txtDeviceID']
            .forEach(id => document.getElementById(id).value = '');
    }

    function resetTables(message) {
        logsBody.innerHTML    = `<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:1.5rem;">${esc(message)}</td></tr>`;
        historyBody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:1.5rem;">${esc(message)}</td></tr>`;
    }

    function fetchEmployeeInfo() {
        const employeeId = empIdInput.value.trim();
        const aDate = dateInput.value;

        empInfoError.style.display = 'none';
        if (!employeeId) return;

        fetch(`override-attendance-ajax.php?action=get_employee&employee_id=${encodeURIComponent(employeeId)}&adate=${encodeURIComponent(aDate)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    empInfoError.textContent = data.message || 'Employee not found.';
                    empInfoError.style.display = 'block';
                    clearEmployeeInfo();
                    resetTables('Employee not found.');
                    return;
                }
                document.getElementById('txtEmployeeName').value = data.employee.EmployeeName;
                document.getElementById('txtDepartment').value   = data.employee.Department;
                document.getElementById('txtPosition').value     = data.employee.Position;
                document.getElementById('txtDay').value          = data.employee.Day;
                document.getElementById('txtDeviceID').value     = data.employee.DeviceID;

                loadAttendanceLogs(employeeId, aDate);
                loadOverrideHistory(employeeId);
            })
            .catch(() => {
                empInfoError.textContent = 'Error fetching employee data.';
                empInfoError.style.display = 'block';
            });
    }

    function loadAttendanceLogs(employeeId, aDate) {
        logsBody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:1.5rem;">Loading…</td></tr>`;

        fetch(`override-attendance-ajax.php?action=get_attendance_logs&employee_id=${encodeURIComponent(employeeId)}&adate=${encodeURIComponent(aDate)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.logs.length) {
                    currentLogs = [];
                    logsBody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:1.5rem;">No attendance logs found for this date.</td></tr>`;
                    return;
                }
                currentLogs = data.logs;
                autoFillOriginalTime();
                logsBody.innerHTML = data.logs.map((r, i) => {
                    const dirClass = r.Direction === 'IN' ? 'oea-badge-in' : 'oea-badge-out';
                    return `<tr>
                        <td>${i + 1}</td>
                        <td>${esc(r.Date)}</td>
                        <td>${esc(r.Time)}</td>
                        <td><span class="oea-badge ${dirClass}">${esc(r.Direction)}</span></td>
                        <td>${esc(r.ShiftPart)}</td>
                        <td>${esc(r.Category)}</td>
                        <td>${esc(r.DataFrom)}</td>
                        <td>${esc(r.Area)}</td>
                    </tr>`;
                }).join('');
            })
            .catch(() => {
                logsBody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:#ef4444; padding:1.5rem;">Error loading attendance logs.</td></tr>`;
            });
    }

    function loadOverrideHistory(employeeId) {
        historyBody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:1.5rem;">Loading…</td></tr>`;

        fetch(`override-attendance-ajax.php?action=get_override_history&employee_id=${encodeURIComponent(employeeId)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.history.length) {
                    historyBody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:1.5rem;">No override history found.</td></tr>`;
                    return;
                }
                historyBody.innerHTML = data.history.map(r => {
                    const dirClass = r.Direction === 'IN' ? 'oea-badge-in' : 'oea-badge-out';
                    return `<tr>
                        <td>${esc(r.Date)}</td>
                        <td>${esc(r.ShiftPart)}</td>
                        <td><span class="oea-badge ${dirClass}">${esc(r.Direction)}</span></td>
                        <td>${esc(r.OriginalTime)}</td>
                        <td>${esc(r.CorrectedTime)}</td>
                        <td>${esc(r.Category)}</td>
                        <td>${esc(r.DataFrom)}</td>
                    </tr>`;
                }).join('');
            })
            .catch(() => {
                historyBody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#ef4444; padding:1.5rem;">Error loading override history.</td></tr>`;
            });
    }

    empIdInput.addEventListener('blur', fetchEmployeeInfo);
    empIdInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            fetchEmployeeInfo();
        }
    });
    dateInput.addEventListener('change', fetchEmployeeInfo);

    // ── Employee Name combobox (load once, filter client-side — matches
    //    the leave-application picker pattern instead of a per-keystroke
    //    server query) ───────────────────────────────────────────────────
    let allEmployees = [];

    fetch('override-attendance-ajax.php?action=get_all_employees')
        .then(res => res.json())
        .then(data => {
            if (data.success) allEmployees = data.employees;
        })
        .catch(() => { /* silently fail — Employee ID field still works */ });

    function renderNameResults(list) {
        if (list.length === 0) {
            empNameResults.innerHTML = `<div class="oea-typeahead-empty">No matching employees.</div>`;
        } else {
            empNameResults.innerHTML = list.slice(0, 50).map(r => `
                <div class="oea-typeahead-item" data-id="${esc(r.EmployeeID)}" data-name="${esc(r.EmployeeName)}">
                    <div class="name">${esc(r.EmployeeName)}</div>
                    <div class="meta">${esc(r.EmployeeID)}</div>
                </div>
            `).join('');
        }
        empNameResults.style.display = 'block';
    }

    empNameInput.addEventListener('input', function () {
        const term = this.value.trim().toLowerCase();
        empInfoError.style.display = 'none';

        if (term.length < 1) {
            empNameResults.style.display = 'none';
            empNameResults.innerHTML = '';
            return;
        }

        const matches = allEmployees.filter(r =>
            r.EmployeeName.toLowerCase().includes(term) || r.EmployeeID.toLowerCase().includes(term)
        );
        renderNameResults(matches);
    });

    empNameInput.addEventListener('focus', function () {
        if (this.value.trim().length >= 1 && empNameResults.innerHTML) {
            empNameResults.style.display = 'block';
        }
    });

    empNameResults.addEventListener('click', function (e) {
        const item = e.target.closest('.oea-typeahead-item');
        if (!item) return;

        empIdInput.value   = item.dataset.id;
        empNameInput.value = item.dataset.name;
        empNameResults.style.display = 'none';
        empNameResults.innerHTML = '';

        fetchEmployeeInfo();
    });

    document.addEventListener('click', function (e) {
        if (e.target !== empNameInput && !empNameResults.contains(e.target)) {
            empNameResults.style.display = 'none';
        }
    });

    // ── Auto-fill Original Time from the loaded Attendance Logs, based on
    //    the selected Shift Part + Direction ─────────────────────────────
    function autoFillOriginalTime() {
        const shift = ovShiftPart.value;
        const dir   = ovDirection.value;
        const match = currentLogs.find(r => r.ShiftPart === shift && r.Direction === dir);
        ovOriginalTime.value = match ? match.Time : '';
    }
    ovShiftPart.addEventListener('change', autoFillOriginalTime);
    ovDirection.addEventListener('change', autoFillOriginalTime);

    // ── Override Category / Type dropdowns ──────────────────────────────
    fetch('override-attendance-ajax.php?action=get_override_categories')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            ovCategory.innerHTML = '<option value="">All Categories</option>' +
                data.categories.map(c => `<option value="${esc(c.OverrideID)}">${esc(c.Override_Name)}</option>`).join('');
        })
        .catch(() => { ovCategory.innerHTML = '<option value="">Unable to load</option>'; });

    fetch('override-attendance-ajax.php?action=get_override_types')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            allOverrideTypes = data.types;
            renderTypeOptions();
        })
        .catch(() => { ovType.innerHTML = '<option value="">Unable to load</option>'; });

    function renderTypeOptions() {
        const selectedCategory = ovCategory.value;
        const filtered = selectedCategory
            ? allOverrideTypes.filter(t => String(t.Category) === String(selectedCategory))
            : allOverrideTypes;
        ovType.innerHTML = filtered.length
            ? filtered.map(t => `<option value="${esc(t.TypeID)}">${esc(t.Type_Name)}</option>`).join('')
            : '<option value="">No types for this category</option>';
    }
    ovCategory.addEventListener('change', renderTypeOptions);

    // ── Save Override ────────────────────────────────────────────────────
    function resetOverrideForm() {
        ovCorrectedTime.value = '';
        ovRemarks.value = '';
    }

    btnSaveOverride.addEventListener('click', function () {
        ovStatusMsg.style.display = 'none';
        ovSuccessMsg.style.display = 'none';

        const employeeId = empIdInput.value.trim();
        if (!employeeId) {
            ovStatusMsg.textContent = 'Load an employee first.';
            ovStatusMsg.style.display = 'block';
            return;
        }

        const body = new URLSearchParams({
            employee_id: employeeId,
            adate: dateInput.value,
            aday: document.getElementById('txtDay').value,
            shift_part: ovShiftPart.value,
            direction: ovDirection.value,
            original_time: ovOriginalTime.value,
            corrected_time: ovCorrectedTime.value,
            override_type: ovType.value,
            remarks: ovRemarks.value,
        });

        btnSaveOverride.disabled = true;

        fetch('override-attendance-ajax.php?action=save_override', {
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
                    ovStatusMsg.textContent = data.message || 'Error saving override.';
                    ovStatusMsg.style.display = 'block';
                    return;
                }
                ovSuccessMsg.textContent = data.message || 'Saved.';
                ovSuccessMsg.style.display = 'block';
                resetOverrideForm();
            })
            .catch(() => {
                ovStatusMsg.textContent = 'Error saving override.';
                ovStatusMsg.style.display = 'block';
            })
            .finally(() => { btnSaveOverride.disabled = false; });
    });
});
</script>
</body>
</html>