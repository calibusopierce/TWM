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
<title>Override Employee Attendance</title>
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

.oea-main-grid { display:grid; grid-template-columns: 1.1fr 1fr; gap:1.1rem; align-items:start; }
@media (max-width: 1000px) { .oea-main-grid { grid-template-columns:1fr; } }

.oea-ampm-split { display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; }
@media (max-width: 560px) { .oea-ampm-split { grid-template-columns:1fr; } }
.oea-ampm-split h4 { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin:0 0 .5rem; display:flex; align-items:center; gap:.35rem; }

.oea-divider { border:none; border-top:1px solid #e2e8f0; margin:1.15rem 0; }
.oea-subhead { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#94a3b8; margin:0 0 .7rem; display:flex; align-items:center; gap:.35rem; }

.oea-field-2col { display:grid; grid-template-columns:1fr 1fr; gap:.9rem; }
@media (max-width: 480px) { .oea-field-2col { grid-template-columns:1fr; } }

.oea-field.oea-critical label { color:#16a34a; }
.oea-field.oea-critical input { border:1.5px solid #16a34a; }

.oea-field { margin-bottom:.9rem; }
.oea-field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:.3rem; }
.oea-field input, .oea-field select, .oea-field textarea {
    width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .7rem;
    font-size:.85rem; color:#0f172a; background:#fff; font-family:inherit;
}
.oea-field input:read-only, .oea-field input:disabled { background:#f8fafc; color:#334155; }
.oea-field-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:.9rem; }

.oea-schedule-table { width:100%; border-collapse:collapse; font-size:.82rem; margin-bottom:.9rem; }
.oea-schedule-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:6px 6px; border-bottom:2px solid #e2e8f0; }
.oea-schedule-table td { padding:8px 6px; border-bottom:1px solid #f1f5f9; }
.oea-schedule-table .oea-actual { color:#2563eb; font-weight:700; }
.oea-schedule-table .oea-late { color:#ef4444; font-weight:700; }

.oea-table-wrap { overflow-x:auto; }
.oea-table { width:100%; border-collapse:collapse; font-size:.8rem; white-space:nowrap; }
.oea-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:8px 8px; border-bottom:2px solid #e2e8f0; position:sticky; top:0; background:#fff; }
.oea-table td { padding:8px 8px; border-bottom:1px solid #f1f5f9; }
.oea-badge { padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.oea-badge-in { background:#dcfce7; color:#15803d; }
.oea-badge-out { background:#fee2e2; color:#b91c1c; }
.oea-badge-pending { background:#fef3c7; color:#b45309; }
.oea-badge-approved { background:#dcfce7; color:#15803d; }
.oea-badge-rejected { background:#fee2e2; color:#b91c1c; }

.oea-btn { border:none; border-radius:8px; padding:.55rem 1.1rem; font-size:.82rem; font-weight:700; display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; color:#fff; }
.oea-btn:disabled { opacity:.5; cursor:not-allowed; }
.oea-btn-primary { background:#2563eb; }
.oea-btn-success { background:#16a34a; }

.oea-error { color:#ef4444; font-size:.8rem; margin-top:.5rem; display:none; }

.oea-tabs { display:flex; gap:.35rem; flex-wrap:wrap; margin-bottom:1.1rem; border-bottom:1px solid #e2e8f0; padding-bottom:.7rem; }
.oea-tab-btn {
    border:1px solid #e2e8f0; background:#f8fafc; color:#64748b;
    border-radius:999px; padding:.4rem .9rem; font-size:.76rem; font-weight:700;
    cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap;
}
.oea-tab-btn:hover { background:#f1f5f9; }
.oea-tab-btn.active { background:#eff6ff; border-color:#2563eb; color:#2563eb; }
.oea-tab-btn .oea-dot { width:6px; height:6px; border-radius:50%; background:#16a34a; display:none; }
.oea-tab-btn.has-value .oea-dot { display:inline-block; }
.oea-tab-pane { display:none; }
.oea-tab-pane.active { display:block; }
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

  <!-- ============ EMPLOYEE + DATE (auto-loaded from your session — this
       page only ever works on your own attendance) ============ -->
  <div class="oea-card">
    <div class="oea-card-head"><span><i class="bi bi-person-badge"></i> Your Information</span></div>
    <div class="oea-card-body">
      <div class="oea-field-row">
        <div class="oea-field">
          <label>Employee Name</label>
          <input type="text" id="txtEmployeeName" readonly>
        </div>
        <div class="oea-field">
          <label>Department</label>
          <input type="text" id="txtDepartment" readonly>
        </div>
        <div class="oea-field">
          <label>Position</label>
          <input type="text" id="txtPosition" readonly>
        </div>
        <div class="oea-field">
          <label>Device ID</label>
          <input type="text" id="txtDeviceID" readonly>
        </div>
        <div class="oea-field">
          <label>Attendance Date</label>
          <input type="date" id="txtAttendanceDate" value="<?= date('Y-m-d') ?>" <?= $is_view_only ? 'disabled' : '' ?>>
        </div>
        <div class="oea-field">
          <label>Day</label>
          <input type="text" id="txtDay" readonly>
        </div>
      </div>
      <div id="empInfoError" class="oea-error"></div>
    </div>
  </div>

  <!-- ============ TODAY'S ATTENDANCE (AM + PM + record, one place) + OVERRIDE FORM ============ -->
  <div class="oea-main-grid">

    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-calendar-week"></i> Today's Attendance</span> <small>From System</small></div>
      <div class="oea-card-body">

        <div class="oea-ampm-split">
          <div>
            <h4><i class="bi bi-sunrise"></i> AM</h4>
            <table class="oea-schedule-table">
              <thead><tr><th></th><th>Actual</th></tr></thead>
              <tbody>
                <tr><td>Time In</td><td class="oea-actual" id="amActualIn">--:--</td></tr>
                <tr><td>Time Out</td><td class="oea-actual" id="amActualOut">--:--</td></tr>
              </tbody>
            </table>
            <div class="oea-field-row">
              <div class="oea-field"><label>Total Hours</label><input type="text" id="amTotalHours" readonly></div>
              <div class="oea-field"><label>Late</label><input type="text" id="amLate" class="oea-late" readonly></div>
            </div>
          </div>
          <div>
            <h4><i class="bi bi-sunset"></i> PM</h4>
            <table class="oea-schedule-table">
              <thead><tr><th></th><th>Actual</th></tr></thead>
              <tbody>
                <tr><td>Time In</td><td class="oea-actual" id="pmActualIn">--:--</td></tr>
                <tr><td>Time Out</td><td class="oea-actual" id="pmActualOut">--:--</td></tr>
              </tbody>
            </table>
            <div class="oea-field-row">
              <div class="oea-field"><label>Total Hours</label><input type="text" id="pmTotalHours" readonly></div>
              <div class="oea-field"><label>Late</label><input type="text" id="pmLate" class="oea-late" readonly></div>
            </div>
          </div>
        </div>

        <hr class="oea-divider">

        <div id="recordEmpty" style="text-align:center; color:#94a3b8; padding:.5rem 0 .2rem;">Pick a date above to load your record.</div>
        <div id="recordDetails" style="display:none;">
          <div class="oea-subhead"><i class="bi bi-list-check"></i> Record Details</div>
          <div class="oea-field-row">
            <div class="oea-field"><label>Category</label><input type="text" id="recCategory" readonly></div>
            <div class="oea-field"><label>Status</label><input type="text" id="recStatus" readonly></div>
            <div class="oea-field"><label>Day Count</label><input type="text" id="recDayCount" readonly></div>
            <div class="oea-field"><label>Payroll Group</label><input type="text" id="recPayrollGroup" readonly></div>
            <div class="oea-field"><label>Total (AM+PM)</label><input type="text" id="recMorningAfternoonTotal" readonly></div>
            <div class="oea-field"><label>Total Hours</label><input type="text" id="recTotalHours" readonly></div>
          </div>
        </div>

      </div>
    </div>

    <!-- ============ ONE FORM, ONE SUBMIT — fill in whichever fields apply.
         A Corrected Time fixes a single punch; Shift Times set the whole
         day's schedule. Fill either, or both — it all saves as one
         submission and goes to HR together. Sections below are now tabbed
         instead of stacked, so only one is visible at a time. ============ -->
    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-pencil-square"></i> Override / Correction</span></div>
      <div class="oea-card-body">

        <div class="oea-tabs" id="ovTabNav">
          <button type="button" class="oea-tab-btn active" data-tab="punch"><i class="bi bi-clock"></i> Fix a Punch <span class="oea-dot"></span></button>
          <button type="button" class="oea-tab-btn" data-tab="shift"><i class="bi bi-calendar-range"></i> Shift Times <span class="oea-dot"></span></button>
          <button type="button" class="oea-tab-btn" data-tab="actual"><i class="bi bi-fingerprint"></i> Actual Punches <span class="oea-dot"></span></button>
          <button type="button" class="oea-tab-btn" data-tab="totals"><i class="bi bi-exclamation-triangle"></i> Lateness &amp; Totals <span class="oea-dot"></span></button>
          <button type="button" class="oea-tab-btn" data-tab="classify"><i class="bi bi-tags"></i> Classification <span class="oea-dot"></span></button>
        </div>

        <div class="oea-tab-pane active" data-pane="punch">
          <div class="oea-field-2col">
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
          </div>
          <div class="oea-field">
            <label>Original Time</label>
            <input type="text" id="ovOriginalTime" readonly>
          </div>
          <div class="oea-field oea-critical">
            <label>Corrected Time</label>
            <input type="time" id="ovCorrectedTime" <?= $is_view_only ? 'disabled' : '' ?>>
          </div>
        </div>

        <div class="oea-tab-pane" data-pane="shift">
          <div class="oea-ampm-split">
            <div>
              <h4><i class="bi bi-sunrise"></i> AM Schedule</h4>
              <div class="oea-field-row">
                <div class="oea-field"><label>Current Time In</label><input type="text" id="schedAmInCurrent" readonly></div>
                <div class="oea-field oea-critical"><label>Override Time In</label><input type="time" id="schedAmIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
              </div>
              <div class="oea-field-row">
                <div class="oea-field"><label>Current Time Out</label><input type="text" id="schedAmOutCurrent" readonly></div>
                <div class="oea-field oea-critical"><label>Override Time Out</label><input type="time" id="schedAmOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
              </div>
            </div>
            <div>
              <h4><i class="bi bi-sunset"></i> PM Schedule</h4>
              <div class="oea-field-row">
                <div class="oea-field"><label>Current Time In</label><input type="text" id="schedPmInCurrent" readonly></div>
                <div class="oea-field oea-critical"><label>Override Time In</label><input type="time" id="schedPmIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
              </div>
              <div class="oea-field-row">
                <div class="oea-field"><label>Current Time Out</label><input type="text" id="schedPmOutCurrent" readonly></div>
                <div class="oea-field oea-critical"><label>Override Time Out</label><input type="time" id="schedPmOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ============ NEW MANUAL-ENTRY FIELDS — added to match the updated
             TBL_Attendance_Override schema / View_ATtendanceTimeInTimeOut2_Override.
             Per team direction, every one of these is typed in by the employee;
             nothing here is auto-computed. Labels include the raw column name
             so it's unambiguous which DB field each input maps to — some of
             these (AtimeOut vs AtimeOutAM in particular) have overlapping
             names in the schema and should be double-checked with the team
             before relying on the mapping used here. -->
        <div class="oea-tab-pane" data-pane="actual">
          <div class="oea-field-row">
            <div class="oea-field"><label>AM Time In (AtimeIn)</label><input type="time" id="ovAtimeIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>AM Time Out (AtimeOutAM)</label><input type="time" id="ovAtimeOutAM" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>PM Time In (AtimeInPM)</label><input type="time" id="ovAtimeInPM" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Time Out (AtimeOut)</label><input type="time" id="ovAtimeOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
          </div>
        </div>

        <div class="oea-tab-pane" data-pane="totals">
          <div class="oea-field-row">
            <div class="oea-field"><label>AM Late</label><input type="text" id="ovAMLate" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>PM Late</label><input type="text" id="ovPMLate" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Late</label><input type="text" id="ovLate" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Morning Total Hours</label><input type="text" id="ovMorningTotalHours" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Afternoon Total Hours</label><input type="text" id="ovAfternoonTotalHours" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Morning+Afternoon Total</label><input type="text" id="ovMorningAfternoonTotal" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Total Hours</label><input type="text" id="ovTotalHours" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
          </div>
        </div>

        <div class="oea-tab-pane" data-pane="classify">
          <div class="oea-field-row">
            <div class="oea-field"><label>Status</label><input type="text" id="ovStatus" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Day Count</label><input type="text" id="ovDayCount" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Payroll Group</label><input type="text" id="ovPayrollGroup" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Aday</label><input type="text" id="ovAday" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Area (Time In)</label><input type="text" id="ovArea" <?= $is_view_only ? 'disabled' : '' ?>></div>
            <div class="oea-field"><label>Area (Time Out)</label><input type="text" id="ovAreaOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
          </div>
          <div class="oea-field">
            <label>Attachment <small style="font-weight:500; color:#94a3b8; text-transform:none;">(optional supporting document)</small></label>
            <input type="file" id="ovAttachment" <?= $is_view_only ? 'disabled' : '' ?>>
          </div>
        </div>

        <hr class="oea-divider">

        <div class="oea-field-2col">
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
        </div>
        <div class="oea-field">
          <label>Remarks</label>
          <textarea id="ovRemarks" rows="3" <?= $is_view_only ? 'disabled' : '' ?>></textarea>
        </div>
        <button type="button" class="oea-btn oea-btn-success" id="btnSaveOverride" style="width:100%; justify-content:center;" <?= $is_view_only ? 'disabled' : '' ?>>
          <i class="bi bi-save"></i> Submit for HR Approval
        </button>
        <div id="ovStatusMsg" class="oea-error"></div>
        <div id="ovSuccessMsg" style="color:#16a34a; font-size:.8rem; margin-top:.5rem; display:none;"></div>
      </div>
    </div>

  </div>

  <!-- ============ OVERRIDE HISTORY ============ -->
  <div class="oea-card">
    <div class="oea-card-head"><span><i class="bi bi-clock-history"></i> Override History</span> <small>Your submissions</small></div>
    <div class="oea-table-wrap">
      <table class="oea-table" id="tblOverrideHistory">
        <thead>
          <tr><th>Date</th><th>Details</th><th>Status</th></tr>
        </thead>
        <tbody id="tblOverrideHistoryBody">
          <tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:1.5rem;">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div> <!-- /.oea-wrap -->

  </main>
</div> <!-- /.hr-shell (opened by hr_nav.php) -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dateInput      = document.getElementById('txtAttendanceDate');
    const empInfoError   = document.getElementById('empInfoError');
    const historyBody    = document.getElementById('tblOverrideHistoryBody');
    const csrfToken       = document.getElementById('csrfToken').value;
    const ovShiftPart     = document.getElementById('ovShiftPart');
    const ovDirection     = document.getElementById('ovDirection');
    const ovOriginalTime  = document.getElementById('ovOriginalTime');
    const ovCorrectedTime = document.getElementById('ovCorrectedTime');
    const ovCategory      = document.getElementById('ovCategory');
    const ovType          = document.getElementById('ovType');
    const ovRemarks       = document.getElementById('ovRemarks');
    const ovStatusMsg     = document.getElementById('ovStatusMsg');
    const ovSuccessMsg    = document.getElementById('ovSuccessMsg');
    const btnSaveOverride = document.getElementById('btnSaveOverride');
    const recordEmpty     = document.getElementById('recordEmpty');
    const recordDetails   = document.getElementById('recordDetails');

    // Shift Time (full-day schedule) fields — now part of the single merged
    // form; the AM/PM override inputs feed into the same submit as the
    // point-correction fields.
    const schedAmInCurrent  = document.getElementById('schedAmInCurrent');
    const schedAmIn         = document.getElementById('schedAmIn');
    const schedAmOutCurrent = document.getElementById('schedAmOutCurrent');
    const schedAmOut        = document.getElementById('schedAmOut');
    const schedPmInCurrent  = document.getElementById('schedPmInCurrent');
    const schedPmIn         = document.getElementById('schedPmIn');
    const schedPmOutCurrent = document.getElementById('schedPmOutCurrent');
    const schedPmOut        = document.getElementById('schedPmOut');

    // New manual-entry fields (Actual Punch Times / Lateness & Totals /
    // Classification & Location / Attachment) — all optional, all typed in
    // by the employee, no auto-fill.
    const ovAtimeIn        = document.getElementById('ovAtimeIn');
    const ovAtimeOutAM     = document.getElementById('ovAtimeOutAM');
    const ovAtimeInPM      = document.getElementById('ovAtimeInPM');
    const ovAtimeOut       = document.getElementById('ovAtimeOut');
    const ovAMLate         = document.getElementById('ovAMLate');
    const ovPMLate         = document.getElementById('ovPMLate');
    const ovLate           = document.getElementById('ovLate');
    const ovMorningTotalHours     = document.getElementById('ovMorningTotalHours');
    const ovAfternoonTotalHours   = document.getElementById('ovAfternoonTotalHours');
    const ovMorningAfternoonTotal = document.getElementById('ovMorningAfternoonTotal');
    const ovTotalHours     = document.getElementById('ovTotalHours');
    const ovStatus         = document.getElementById('ovStatus');
    const ovDayCount       = document.getElementById('ovDayCount');
    const ovPayrollGroup   = document.getElementById('ovPayrollGroup');
    const ovAday           = document.getElementById('ovAday');
    const ovArea           = document.getElementById('ovArea');
    const ovAreaOut        = document.getElementById('ovAreaOut');
    const ovAttachment     = document.getElementById('ovAttachment');

    let currentRecord = null; // last-loaded attendance record, used to auto-fill Original Time
    let allOverrideTypes = [];

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s ?? '';
        return d.innerHTML;
    }

    function badgeClassForStatus(status) {
        if (status === 'Approved') return 'oea-badge-approved';
        if (status === 'Rejected') return 'oea-badge-rejected';
        return 'oea-badge-pending';
    }

    // ── Load the current user's own info once on page load ───────────────
    function fetchCurrentEmployee() {
        fetch('override-attendance-ajax.php?action=get_current_employee')
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    empInfoError.textContent = data.message || 'Unable to load your employee record.';
                    empInfoError.style.display = 'block';
                    return;
                }
                document.getElementById('txtEmployeeName').value = data.employee.EmployeeName;
                document.getElementById('txtDepartment').value   = data.employee.Department;
                document.getElementById('txtPosition').value     = data.employee.Position;
                document.getElementById('txtDeviceID').value     = data.employee.DeviceID;
            })
            .catch(() => {
                empInfoError.textContent = 'Error fetching your employee data.';
                empInfoError.style.display = 'block';
            });
    }

    // ── Load the attendance record + AM/PM actual times for the selected date ──
    function fetchAttendanceRecord() {
        const aDate = dateInput.value;
        empInfoError.style.display = 'none';
        if (!aDate) return;

        fetch(`override-attendance-ajax.php?action=get_attendance_record&adate=${encodeURIComponent(aDate)}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    empInfoError.textContent = data.message || 'Error loading your attendance record.';
                    empInfoError.style.display = 'block';
                    return;
                }

                document.getElementById('txtDay').value = data.day || '';

                if (!data.record) {
                    currentRecord = null;
                    clearAmPm();
                    recordEmpty.style.display = 'block';
                    recordDetails.style.display = 'none';
                    recordEmpty.textContent = data.message || 'No attendance record found for this date.';
                    return;
                }

                currentRecord = data.record;
                recordEmpty.style.display = 'none';
                recordDetails.style.display = 'block';

                document.getElementById('amActualIn').textContent  = data.record.MorningIn || '--:--';
                document.getElementById('amActualOut').textContent = data.record.MorningOut || '--:--';
                document.getElementById('amTotalHours').value      = data.record.MorningTotalHours || '';
                document.getElementById('amLate').value            = data.record.AMLate || '';

                document.getElementById('pmActualIn').textContent  = data.record.AfternoonIn || '--:--';
                document.getElementById('pmActualOut').textContent = data.record.AfternoonOut || '--:--';
                document.getElementById('pmTotalHours').value      = data.record.AfternoonTotalHours || '';
                document.getElementById('pmLate').value            = data.record.PMLate || '';

                document.getElementById('recCategory').value = data.record.Category || '';
                document.getElementById('recStatus').value   = data.record.Status || '';
                document.getElementById('recDayCount').value = data.record.DayCount || '';
                document.getElementById('recPayrollGroup').value = data.record.PayrollGroup || '';
                document.getElementById('recMorningAfternoonTotal').value = data.record.MorningAfternoonTotal || '';
                document.getElementById('recTotalHours').value = data.record.TotalHours || '';

                schedAmInCurrent.value  = data.record.ScheduleAmIn  || '';
                schedAmOutCurrent.value = data.record.ScheduleAmOut || '';
                schedPmInCurrent.value  = data.record.SchedulePmIn  || '';
                schedPmOutCurrent.value = data.record.SchedulePmOut || '';

                autoFillOriginalTime();
            })
            .catch(() => {
                empInfoError.textContent = 'Error loading your attendance record.';
                empInfoError.style.display = 'block';
            });
    }

    function clearAmPm() {
        ['amActualIn', 'amActualOut', 'pmActualIn', 'pmActualOut'].forEach(id => document.getElementById(id).textContent = '--:--');
        ['amTotalHours', 'amLate', 'pmTotalHours', 'pmLate',
         'recCategory', 'recStatus', 'recDayCount', 'recPayrollGroup',
         'recMorningAfternoonTotal', 'recTotalHours'].forEach(id => document.getElementById(id).value = '');
        ovOriginalTime.value = '';
        schedAmInCurrent.value = '';
        schedAmOutCurrent.value = '';
        schedPmInCurrent.value = '';
        schedPmOutCurrent.value = '';
    }

    function loadOverrideHistory() {
        historyBody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:1.5rem;">Loading…</td></tr>`;

        fetch('override-attendance-ajax.php?action=get_override_history')
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.history.length) {
                    historyBody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#94a3b8; padding:1.5rem;">No override submissions yet.</td></tr>`;
                    return;
                }
                historyBody.innerHTML = data.history.map(r => `<tr>
                        <td>${esc(r.Date)}</td>
                        <td>${esc(r.Details)}</td>
                        <td><span class="oea-badge ${badgeClassForStatus(r.Status)}">${esc(r.Status)}</span></td>
                    </tr>`).join('');
            })
            .catch(() => {
                historyBody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#ef4444; padding:1.5rem;">Error loading override history.</td></tr>`;
            });
    }

    // ── Auto-fill Original Time from the loaded attendance record, based on
    //    the selected Shift Part + Direction ─────────────────────────────
    function autoFillOriginalTime() {
        if (!currentRecord) { ovOriginalTime.value = ''; return; }
        const shift = ovShiftPart.value;
        const dir   = ovDirection.value;
        let val = '';
        if (shift === 'AM') val = dir === 'IN' ? currentRecord.MorningIn : currentRecord.MorningOut;
        if (shift === 'PM') val = dir === 'IN' ? currentRecord.AfternoonIn : currentRecord.AfternoonOut;
        ovOriginalTime.value = val || '';
    }
    ovShiftPart.addEventListener('change', autoFillOriginalTime);
    ovDirection.addEventListener('change', autoFillOriginalTime);

    // ── Override Category / Type dropdowns ──────────────────────────────
    // Generalized into a reusable wiring function so the new Shift Time
    // Override section can reuse the exact same categories/types data
    // without duplicating the fetch calls or touching the existing
    // ovCategory/ovType behavior.
    function wireCategoryTypeSelects(categorySelect, typeSelect, allTypesRef) {
        function renderTypes() {
            const selectedCategory = categorySelect.value;
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const selectedName = selectedOption ? (selectedOption.dataset.name || '') : '';

            const filtered = selectedCategory
                ? allTypesRef.list.filter(t => {
                    const tCat = String(t.Category ?? '').trim().toLowerCase();
                    return tCat === String(selectedCategory).trim().toLowerCase()
                        || tCat === selectedName.trim().toLowerCase();
                })
                : allTypesRef.list;
            typeSelect.innerHTML = filtered.length
                ? filtered.map(t => `<option value="${esc(t.TypeID)}">${esc(t.Type_Name)}</option>`).join('')
                : '<option value="">No types for this category</option>';
        }
        categorySelect.addEventListener('change', renderTypes);
        return renderTypes;
    }

    const allOverrideTypesRef = { list: [] };
    const renderOvTypes = wireCategoryTypeSelects(ovCategory, ovType, allOverrideTypesRef);

    fetch('override-attendance-ajax.php?action=get_override_categories')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            ovCategory.innerHTML = '<option value="">All Categories</option>' +
                data.categories.map(c => `<option value="${esc(c.OverrideID)}" data-name="${esc(c.Override_Name)}">${esc(c.Override_Name)}</option>`).join('');
        })
        .catch(() => {
            ovCategory.innerHTML = '<option value="">Unable to load</option>';
        });

    fetch('override-attendance-ajax.php?action=get_override_types')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            allOverrideTypesRef.list = data.types;
            allOverrideTypes = data.types; // kept for anything else referencing the old variable name
            renderOvTypes();
        })
        .catch(() => {
            ovType.innerHTML = '<option value="">Unable to load</option>';
        });

    // ── Tab switching for the Override / Correction sections ────────────
    const ovTabBtns  = document.querySelectorAll('#ovTabNav .oea-tab-btn');
    const ovTabPanes = document.querySelectorAll('.oea-tab-pane');
    ovTabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            ovTabBtns.forEach(b => b.classList.remove('active'));
            ovTabPanes.forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.querySelector(`.oea-tab-pane[data-pane="${btn.dataset.tab}"]`).classList.add('active');
        });
    });
    function refreshOvTabDots() {
        ovTabPanes.forEach(pane => {
            const hasValue = [...pane.querySelectorAll('input, select, textarea')]
                .some(el => el.type === 'file' ? el.files.length : el.value);
            document.querySelector(`.oea-tab-btn[data-tab="${pane.dataset.pane}"]`).classList.toggle('has-value', hasValue);
        });
    }
    document.querySelectorAll('.oea-tab-pane input, .oea-tab-pane select, .oea-tab-pane textarea')
        .forEach(el => el.addEventListener('input', refreshOvTabDots));

    // ── Save Override — one submit, whichever fields the user filled in
    //    (Corrected Time and/or Shift Times) go into a single row. ────────
    function resetOverrideForm() {
        ovCorrectedTime.value = '';
        schedAmIn.value = '';
        schedAmOut.value = '';
        schedPmIn.value = '';
        schedPmOut.value = '';
        ovRemarks.value = '';
        ovAtimeIn.value = '';
        ovAtimeOutAM.value = '';
        ovAtimeInPM.value = '';
        ovAtimeOut.value = '';
        ovAMLate.value = '';
        ovPMLate.value = '';
        ovLate.value = '';
        ovMorningTotalHours.value = '';
        ovAfternoonTotalHours.value = '';
        ovMorningAfternoonTotal.value = '';
        ovTotalHours.value = '';
        ovStatus.value = '';
        ovDayCount.value = '';
        ovPayrollGroup.value = '';
        ovAday.value = '';
        ovArea.value = '';
        ovAreaOut.value = '';
        ovAttachment.value = '';
        refreshOvTabDots();
    }

    btnSaveOverride.addEventListener('click', function () {
        ovStatusMsg.style.display = 'none';
        ovSuccessMsg.style.display = 'none';

        const hasCorrection = !!ovCorrectedTime.value;
        const hasSchedule = !!(schedAmIn.value || schedAmOut.value || schedPmIn.value || schedPmOut.value);

        if (!hasCorrection && !hasSchedule) {
            ovStatusMsg.textContent = 'Fill in a Corrected Time and/or at least one Shift Time (AM/PM In/Out).';
            ovStatusMsg.style.display = 'block';
            return;
        }
        if (!ovType.value) {
            ovStatusMsg.textContent = 'Override Type is required.';
            ovStatusMsg.style.display = 'block';
            return;
        }

        // FormData (not URLSearchParams) since Attachment is a file — the
        // backend reads the rest of these out of $_POST exactly as before.
        const body = new FormData();
        body.append('adate', dateInput.value);
        body.append('shift_part', ovShiftPart.value);
        body.append('direction', ovDirection.value);
        body.append('original_time', ovOriginalTime.value);
        body.append('corrected_time', ovCorrectedTime.value);
        body.append('am_in', schedAmIn.value);
        body.append('am_out', schedAmOut.value);
        body.append('pm_in', schedPmIn.value);
        body.append('pm_out', schedPmOut.value);
        body.append('override_type', ovType.value);
        body.append('remarks', ovRemarks.value);
        body.append('atime_in', ovAtimeIn.value);
        body.append('atime_out_am', ovAtimeOutAM.value);
        body.append('atime_in_pm', ovAtimeInPM.value);
        body.append('atime_out', ovAtimeOut.value);
        body.append('am_late', ovAMLate.value);
        body.append('pm_late', ovPMLate.value);
        body.append('late', ovLate.value);
        body.append('morning_total_hours', ovMorningTotalHours.value);
        body.append('afternoon_total_hours', ovAfternoonTotalHours.value);
        body.append('morning_afternoon_total', ovMorningAfternoonTotal.value);
        body.append('total_hours', ovTotalHours.value);
        body.append('status', ovStatus.value);
        body.append('day_count', ovDayCount.value);
        body.append('payroll_group', ovPayrollGroup.value);
        body.append('aday', ovAday.value);
        body.append('area', ovArea.value);
        body.append('area_out', ovAreaOut.value);
        if (ovAttachment.files[0]) body.append('attachment', ovAttachment.files[0]);

        btnSaveOverride.disabled = true;

        fetch('override-attendance-ajax.php?action=save_override', {
            method: 'POST',
            headers: {
                // No Content-Type header — the browser sets the correct
                // multipart/form-data boundary automatically for FormData.
                'X-CSRF-Token': csrfToken,
            },
            body: body,
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    let msg = data.message || 'Error saving override.';
                    if (data.errors && data.errors.length) {
                        msg += ' — ' + data.errors.map(e => e.message).join(' | ');
                    }
                    ovStatusMsg.textContent = msg;
                    ovStatusMsg.style.display = 'block';
                    return;
                }
                ovSuccessMsg.textContent = data.message || 'Saved.';
                ovSuccessMsg.style.display = 'block';
                resetOverrideForm();
                loadOverrideHistory();
            })
            .catch(() => {
                ovStatusMsg.textContent = 'Error saving override.';
                ovStatusMsg.style.display = 'block';
            })
            .finally(() => { btnSaveOverride.disabled = false; });
    });

    dateInput.addEventListener('change', fetchAttendanceRecord);

    // ── Initial load ──────────────────────────────────────────────────────
    fetchCurrentEmployee();
    fetchAttendanceRecord();
    loadOverrideHistory();
});
</script>
</body>
</html>