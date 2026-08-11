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
.oea-field.oea-computed label { color:#2563eb; }
.oea-field.oea-computed input { background:#eff6ff; color:#1e3a8a; font-weight:700; }

.oea-field { margin-bottom:.9rem; }
.oea-field label { display:block; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; margin-bottom:.3rem; }
.oea-field input, .oea-field select, .oea-field textarea {
    width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:.5rem .7rem;
    font-size:.85rem; color:#0f172a; background:#fff; font-family:inherit;
}
.oea-field input:read-only, .oea-field input:disabled { background:#f8fafc; color:#334155; }
.oea-field-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:.9rem; }

.oea-table-wrap { overflow-x:auto; }
.oea-table { width:100%; border-collapse:collapse; font-size:.8rem; white-space:nowrap; }
.oea-table th { text-align:left; color:#64748b; font-size:.68rem; text-transform:uppercase; padding:8px 8px; border-bottom:2px solid #e2e8f0; position:sticky; top:0; background:#fff; }
.oea-table td { padding:8px 8px; border-bottom:1px solid #f1f5f9; }
.oea-badge { padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.oea-badge-pending { background:#fef3c7; color:#b45309; }
.oea-badge-approved { background:#dcfce7; color:#15803d; }
.oea-badge-rejected { background:#fee2e2; color:#b91c1c; }

.oea-btn { border:none; border-radius:8px; padding:.55rem 1.1rem; font-size:.82rem; font-weight:700; display:inline-flex; align-items:center; gap:.4rem; cursor:pointer; color:#fff; }
.oea-btn:disabled { opacity:.5; cursor:not-allowed; }
.oea-btn-success { background:#16a34a; }

.oea-error { color:#ef4444; font-size:.8rem; margin-top:.5rem; display:none; }
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

  <!-- ============ EMPLOYEE + DATE ============ -->
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

  <div class="oea-main-grid">

    <!-- ============ TODAY'S ATTENDANCE — editable source of truth for
         actual punch times. Picking a date auto-fills every field below
         from the existing TBL_Attendance_Override row if one exists,
         otherwise from the current system attendance record — so nothing
         is ever blank going into save_override, even if the user only
         edits one punch. Total Hours / Late / Day Count are computed live
         in the browser on every Time In/Out change; see computeAttendance()
         near the bottom of the page. ============ -->
    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-calendar-week"></i> Today's Attendance</span> <small>Editable · auto-computed</small></div>
      <div class="oea-card-body">

        <div class="oea-ampm-split">
          <div>
            <h4><i class="bi bi-sunrise"></i> AM</h4>
            <div class="oea-field-row">
              <div class="oea-field"><label>Actual Time In</label><input type="text" id="amActualIn" readonly></div>
              <div class="oea-field oea-critical"><label>Time In</label><input type="time" id="amTimeIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
            <div class="oea-field-row">
              <div class="oea-field"><label>Actual Time Out</label><input type="text" id="amActualOut" readonly></div>
              <div class="oea-field oea-critical"><label>Time Out</label><input type="time" id="amTimeOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
            <div class="oea-field-row">
              <div class="oea-field oea-computed"><label>Total Hours</label><input type="text" id="amTotalHours" readonly></div>
              <div class="oea-field oea-computed"><label>Late (mins)</label><input type="text" id="amLate" readonly></div>
            </div>
          </div>
          <div>
            <h4><i class="bi bi-sunset"></i> PM</h4>
            <div class="oea-field-row">
              <div class="oea-field"><label>Actual Time In</label><input type="text" id="pmActualIn" readonly></div>
              <div class="oea-field oea-critical"><label>Time In</label><input type="time" id="pmTimeIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
            <div class="oea-field-row">
              <div class="oea-field"><label>Actual Time Out</label><input type="text" id="pmActualOut" readonly></div>
              <div class="oea-field oea-critical"><label>Time Out</label><input type="time" id="pmTimeOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
            <div class="oea-field-row">
              <div class="oea-field oea-computed"><label>Total Hours</label><input type="text" id="pmTotalHours" readonly></div>
              <div class="oea-field oea-computed"><label>Late (mins)</label><input type="text" id="pmLate" readonly></div>
            </div>
          </div>
        </div>

        <hr class="oea-divider">

        <div class="oea-subhead"><i class="bi bi-calculator"></i> Day Summary <small>auto-computed</small></div>
        <div class="oea-field-row">
          <div class="oea-field oea-computed"><label>Total Hours (Day)</label><input type="text" id="dayTotalHours" readonly></div>
          <div class="oea-field oea-computed"><label>Total Late (Day, mins)</label><input type="text" id="dayTotalLate" readonly></div>
          <div class="oea-field oea-computed"><label>Day Count</label><input type="text" id="dayCount" readonly></div>
        </div>

        <div id="recordEmpty" style="text-align:center; color:#94a3b8; padding:.5rem 0 .2rem; display:none;">Pick a date above to load your record.</div>

      </div>
    </div>

    <!-- ============ OVERRIDE / CORRECTION — Fix-a-Punch, Actual Punch
         Times, and Lateness & Totals were removed from here since Today's
         Attendance above already covers all of that directly (editable +
         auto-computed) — keeping them here too would just be two places
         editing the same data. What's left is the full-day Shift Time
         override (a DIFFERENT thing — the assigned SCHEDULE, not what was
         actually punched) and Classification & Location. Flat/stacked,
         no tabs. ============ -->
    <div class="oea-card">
      <div class="oea-card-head"><span><i class="bi bi-pencil-square"></i> Override / Correction</span></div>
      <div class="oea-card-body">

        <div class="oea-subhead"><i class="bi bi-calendar-range"></i> Set the Full Day's Shift Times <small>optional</small></div>
        <div class="oea-ampm-split">
          <div>
            <h4><i class="bi bi-sunrise"></i> AM Schedule</h4>
            <div class="oea-field-row">
              <div class="oea-field"><label>Current Time In</label><input type="text" id="schedAmInCurrent" readonly></div>
              <div class="oea-field oea-critical"><label>Override Time In</label><input type="time" id="schedAmIn" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
            <div class="oea-field-row">
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
              <div class="oea-field oea-critical"><label>Override Time Out</label><input type="time" id="schedPmOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
            </div>
          </div>
        </div>
        <p style="font-size:.72rem; color:#94a3b8; margin-top:-.3rem;">
          <i class="bi bi-info-circle"></i> Time Out fields auto-fill from the employee's scheduled time out when no override exists yet.
        </p>

        <hr class="oea-divider">

        <div class="oea-subhead"><i class="bi bi-tags"></i> Classification &amp; Location <small>auto-filled · editable</small></div>
        <div class="oea-field-row">
          <div class="oea-field"><label>Status</label><input type="text" id="ovStatus" <?= $is_view_only ? 'disabled' : '' ?>></div>
          <div class="oea-field"><label>Payroll Group</label><input type="text" id="ovPayrollGroup" <?= $is_view_only ? 'disabled' : '' ?>></div>
          <div class="oea-field"><label>Aday</label><input type="text" id="ovAday" inputmode="decimal" <?= $is_view_only ? 'disabled' : '' ?>></div>
          <div class="oea-field"><label>Area (Time In)</label><input type="text" id="ovArea" <?= $is_view_only ? 'disabled' : '' ?>></div>
          <div class="oea-field"><label>Area (Time Out)</label><input type="text" id="ovAreaOut" <?= $is_view_only ? 'disabled' : '' ?>></div>
        </div>

        <div class="oea-field">
          <label>Attachment <small style="font-weight:500; color:#94a3b8; text-transform:none;">(optional supporting document)</small></label>
          <input type="file" id="ovAttachment" <?= $is_view_only ? 'disabled' : '' ?>>
        </div>

        <hr class="oea-divider">

        <div class="oea-field-2col">
          <div class="oea-field">
            <label>Override Category</label>
            <select id="ovCategory" disabled>
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
    const ovCategory      = document.getElementById('ovCategory');
    const ovType          = document.getElementById('ovType');
    const ovRemarks       = document.getElementById('ovRemarks');
    const ovStatusMsg     = document.getElementById('ovStatusMsg');
    const ovSuccessMsg    = document.getElementById('ovSuccessMsg');
    const btnSaveOverride = document.getElementById('btnSaveOverride');
    const recordEmpty     = document.getElementById('recordEmpty');

    // Today's Attendance — editable actual punch fields + readonly
    // "Actual" reference + auto-computed outputs.
    const amActualIn   = document.getElementById('amActualIn');
    const amActualOut  = document.getElementById('amActualOut');
    const amTimeIn     = document.getElementById('amTimeIn');
    const amTimeOut    = document.getElementById('amTimeOut');
    const amTotalHours = document.getElementById('amTotalHours');
    const amLate       = document.getElementById('amLate');

    const pmActualIn   = document.getElementById('pmActualIn');
    const pmActualOut  = document.getElementById('pmActualOut');
    const pmTimeIn     = document.getElementById('pmTimeIn');
    const pmTimeOut    = document.getElementById('pmTimeOut');
    const pmTotalHours = document.getElementById('pmTotalHours');
    const pmLate       = document.getElementById('pmLate');

    const dayTotalHours = document.getElementById('dayTotalHours');
    const dayTotalLate  = document.getElementById('dayTotalLate');
    const dayCount      = document.getElementById('dayCount');

    // Shift Time (full-day schedule) fields — DIFFERENT from the actual
    // punches above: this overrides the assigned schedule, not what the
    // employee actually clocked. Maps to SetTime/SetTimeOutAM/SetTimeInPM/
    // SetTimeOutPM in TBL_Attendance_Override.
    const schedAmInCurrent  = document.getElementById('schedAmInCurrent');
    const schedAmIn         = document.getElementById('schedAmIn');
    const schedAmOut        = document.getElementById('schedAmOut');
    const schedPmInCurrent  = document.getElementById('schedPmInCurrent');
    const schedPmIn         = document.getElementById('schedPmIn');
    const schedPmOut        = document.getElementById('schedPmOut');

    // Classification & Location — auto-filled on date load, editable.
    const ovStatus         = document.getElementById('ovStatus');
    const ovPayrollGroup   = document.getElementById('ovPayrollGroup');
    const ovAday           = document.getElementById('ovAday');
    const ovArea           = document.getElementById('ovArea');
    const ovAreaOut        = document.getElementById('ovAreaOut');
    const ovAttachment     = document.getElementById('ovAttachment');

    // Scheduled AM/PM Time In — reference point for the Late computation.
    let scheduledAmIn = null;
    let scheduledPmIn = null;

    // Original (pre-edit) actual punch values captured at load time, used
    // only to detect which single punch the user changed — for deriving
    // ATime / Direction / ShiftPart on save. Not shown anywhere in the UI.
    let originalPunches = { amIn: '', amOut: '', pmIn: '', pmOut: '' };

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

    // Convert whatever time format the backend sends ("08:03 AM",
    // "08:03:00", "2026-08-08 08:03:00", or already-24hr "08:03") into the
    // "HH:MM" 24-hour string a <input type="time"> expects.
    // NOTE: confirm the exact shape of MorningIn/MorningOut/etc coming back
    // from get_attendance_record with the backend — adjust here if the API
    // returns something else.
    function toTimeInputValue(raw) {
        if (!raw) return '';
        const s = String(raw).trim();
        const d = new Date(s.includes('T') || s.includes('-') ? s.replace(' ', 'T') : `1970-01-01 ${s}`);
        if (!isNaN(d.getTime())) {
            return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
        }
        const m = s.match(/(\d{1,2}):(\d{2})\s*([AaPp][Mm])?/);
        if (m) {
            let hh = parseInt(m[1], 10);
            const mm = m[2];
            const ap = (m[3] || '').toLowerCase();
            if (ap === 'pm' && hh !== 12) hh += 12;
            if (ap === 'am' && hh === 12) hh = 0;
            return `${String(hh).padStart(2, '0')}:${mm}`;
        }
        return '';
    }

    function toDisplayTime(raw) {
        const v = toTimeInputValue(raw);
        if (!v) return '--:--';
        const [h, m] = v.split(':').map(Number);
        const ap = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return `${h12}:${String(m).padStart(2, '0')} ${ap}`;
    }

    function parseTimeToMinutes(t) {
        if (!t) return null;
        const [h, m] = t.split(':').map(Number);
        if (isNaN(h) || isNaN(m)) return null;
        return h * 60 + m;
    }

    // ── Live compute: AM/PM Total Hours (decimal), AM/PM Late (mins),
    //    Day Total Hours, Day Total Late, Day Count. Runs on every
    //    Time In/Out edit.
    //   AM Total Hours = (amTimeOut - amTimeIn) in decimal hours
    //   PM Total Hours = (pmTimeOut - pmTimeIn) in decimal hours
    //   AM/PM Late     = (actualTimeIn - scheduledTimeIn) in minutes, if > 5, else 0
    //   Total Hours (Day) = AM Total Hours + PM Total Hours  → also written to
    //                       BOTH MorningAfternoonTotal and TotalHours on save
    //   Total Late (Day)  = AM Late + PM Late
    //   Day Count = 1 if AM and PM both complete (Time In AND Time Out present),
    //               0.5 if only one shift complete, 0 if neither
    function computeAttendance() {
        const amInMin  = parseTimeToMinutes(amTimeIn.value);
        const amOutMin = parseTimeToMinutes(amTimeOut.value);
        const pmInMin  = parseTimeToMinutes(pmTimeIn.value);
        const pmOutMin = parseTimeToMinutes(pmTimeOut.value);

        const amHrs = (amInMin != null && amOutMin != null) ? Math.max(0, (amOutMin - amInMin) / 60) : null;
        const pmHrs = (pmInMin != null && pmOutMin != null) ? Math.max(0, (pmOutMin - pmInMin) / 60) : null;
        amTotalHours.value = amHrs != null ? amHrs.toFixed(2) : '';
        pmTotalHours.value = pmHrs != null ? pmHrs.toFixed(2) : '';

        const schedAmMin = parseTimeToMinutes(scheduledAmIn);
        const schedPmMin = parseTimeToMinutes(scheduledPmIn);

        let amLateVal = 0;
        if (amInMin != null && schedAmMin != null) {
            const diff = amInMin - schedAmMin;
            amLateVal = diff > 5 ? diff : 0;
        }
        let pmLateVal = 0;
        if (pmInMin != null && schedPmMin != null) {
            const diff = pmInMin - schedPmMin;
            pmLateVal = diff > 5 ? diff : 0;
        }
        amLate.value = amLateVal;
        pmLate.value = pmLateVal;

        const dayHrs  = (amHrs || 0) + (pmHrs || 0);
        const dayLate = amLateVal + pmLateVal;
        dayTotalHours.value = dayHrs.toFixed(2);
        dayTotalLate.value  = dayLate;

        const amComplete = amInMin != null && amOutMin != null;
        const pmComplete = pmInMin != null && pmOutMin != null;
        let dc = 0;
        if (amComplete && pmComplete) dc = 1;
        else if (amComplete || pmComplete) dc = 0.5;
        dayCount.value = dc;
    }
    [amTimeIn, amTimeOut, pmTimeIn, pmTimeOut].forEach(el => el.addEventListener('input', computeAttendance));

    // ── Derive ATime / Direction / ShiftPart from whichever single punch
    //    changed vs. the original actual value loaded for this date.
    //    Priority if more than one changed: AM In → AM Out → PM In → PM Out.
    //    OriginalTime is intentionally always NULL now (legacy column, per
    //    team direction — no longer populated). ─────────────────────────
    function deriveOverrideMeta() {
        const checks = [
            { shift: 'AM', dir: 'IN',  orig: originalPunches.amIn,  now: amTimeIn.value },
            { shift: 'AM', dir: 'OUT', orig: originalPunches.amOut, now: amTimeOut.value },
            { shift: 'PM', dir: 'IN',  orig: originalPunches.pmIn,  now: pmTimeIn.value },
            { shift: 'PM', dir: 'OUT', orig: originalPunches.pmOut, now: pmTimeOut.value },
        ];
        const changed = checks.find(c => c.now && c.now !== c.orig);
        if (!changed) return { ShiftPart: '', Direction: '', ATime: '' };
        return { ShiftPart: changed.shift, Direction: changed.dir, ATime: changed.now };
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

    // ── Load the attendance record for the selected date, then auto-fill
    //    EVERY editable field: from an existing TBL_Attendance_Override row
    //    if one is already there (edit mode — record.override), otherwise
    //    from the current system attendance values, so nothing is blank
    //    going into save_override even if the user only touches one field.
    //    NOTE: this assumes get_attendance_record's response includes a
    //    `record.override` object (raw TBL_Attendance_Override row) when
    //    one already exists for this date. If the backend doesn't return
    //    that yet, flag it — edit-mode auto-fill depends on it. ──────────
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
                    clearAllFields();
                    recordEmpty.style.display = 'block';
                    recordEmpty.textContent = data.message || 'No attendance record found for this date.';
                    return;
                }
                recordEmpty.style.display = 'none';

                const rec = data.record;
                const ov  = rec.override || null; // existing TBL_Attendance_Override row for this date, if any

                // Actual reference (always the system record, never overridden)
                // NOTE: these are <input readonly> now, not <td> — must set
                // .value, not .textContent, or the box stays visibly blank
                // even though the data loaded fine.
                amActualIn.value  = toDisplayTime(rec.MorningIn);
                amActualOut.value = toDisplayTime(rec.MorningOut);
                pmActualIn.value  = toDisplayTime(rec.AfternoonIn);
                pmActualOut.value = toDisplayTime(rec.AfternoonOut);

                // Capture the ORIGINAL actual punches (not override values)
                // for the ATime/Direction/ShiftPart diff check on save.
                originalPunches = {
                    amIn:  toTimeInputValue(rec.MorningIn),
                    amOut: toTimeInputValue(rec.MorningOut),
                    pmIn:  toTimeInputValue(rec.AfternoonIn),
                    pmOut: toTimeInputValue(rec.AfternoonOut),
                };

                // Editable Time In/Out — default to existing override values
                // if present (edit mode), otherwise the actual punch times.
                amTimeIn.value  = toTimeInputValue(ov?.AtimeIn    ?? rec.MorningIn);
                amTimeOut.value = toTimeInputValue(ov?.AtimeOutAM ?? rec.MorningOut);
                pmTimeIn.value  = toTimeInputValue(ov?.AtimeInPM  ?? rec.AfternoonIn);
                pmTimeOut.value = toTimeInputValue(ov?.AtimeOut   ?? rec.AfternoonOut);

                // Scheduled Time In — reference point for the Late computation
                scheduledAmIn = toTimeInputValue(rec.ScheduleAmIn) || null;
                scheduledPmIn = toTimeInputValue(rec.SchedulePmIn) || null;
                schedAmInCurrent.value = toDisplayTime(rec.ScheduleAmIn);
                schedPmInCurrent.value = toDisplayTime(rec.SchedulePmIn);
                schedAmIn.value  = toTimeInputValue(ov?.SetTime      ?? rec.ScheduleAmIn ?? '');
                schedAmOut.value = toTimeInputValue(ov?.SetTimeOutAM ?? rec.ScheduleAmOut ?? '');
                schedPmIn.value  = toTimeInputValue(ov?.SetTimeInPM  ?? rec.SchedulePmIn ?? '');
                schedPmOut.value = toTimeInputValue(ov?.SetTimeOutPM ?? rec.SchedulePmOut ?? '');

                // Classification & Location — auto-fill from existing override
                // row if present, else the current system record.
                ovStatus.value       = ov?.Status       ?? rec.Status       ?? '';
                ovPayrollGroup.value = ov?.PayrollGroup  ?? rec.PayrollGroup ?? '';
                ovAday.value         = ov?.Aday          ?? '';
                ovArea.value         = ov?.Area          ?? '';
                ovAreaOut.value      = ov?.AreaOut       ?? '';

                computeAttendance();
            })
            .catch(() => {
                empInfoError.textContent = 'Error loading your attendance record.';
                empInfoError.style.display = 'block';
            });
    }

    function clearAllFields() {
        [amActualIn, amActualOut, pmActualIn, pmActualOut].forEach(el => el.value = '--:--');
        [amTimeIn, amTimeOut, pmTimeIn, pmTimeOut].forEach(el => el.value = '');
        [amTotalHours, amLate, pmTotalHours, pmLate, dayTotalHours, dayTotalLate, dayCount].forEach(el => el.value = '');
        schedAmInCurrent.value = '';
        schedPmInCurrent.value = '';
        schedAmIn.value = '';
        schedAmOut.value = '';
        schedPmIn.value = '';
        schedPmOut.value = '';
        ovStatus.value = '';
        ovPayrollGroup.value = '';
        ovAday.value = '';
        ovArea.value = '';
        ovAreaOut.value = '';
        scheduledAmIn = null;
        scheduledPmIn = null;
        originalPunches = { amIn: '', amOut: '', pmIn: '', pmOut: '' };
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

    // ── Override Category / Type dropdowns ──────────────────────────────
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

            // This page is attendance-only — default the category to
            // ATTENDANCE rather than leaving it on "All Categories".
            const attendanceOpt = data.categories.find(c => String(c.Override_Name).trim().toUpperCase() === 'ATTENDANCE');
            if (attendanceOpt) {
                ovCategory.value = attendanceOpt.OverrideID;
                ovCategory.dispatchEvent(new Event('change'));
            }
        })
        .catch(() => {
            ovCategory.innerHTML = '<option value="">Unable to load</option>';
        });

    fetch('override-attendance-ajax.php?action=get_override_types')
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;
            allOverrideTypesRef.list = data.types;
            renderOvTypes();
        })
        .catch(() => {
            ovType.innerHTML = '<option value="">Unable to load</option>';
        });

    // ── Save Override — every field on the page goes out together in one
    //    submission, whether or not the user touched it, since everything
    //    was auto-filled with a real default on date load. Field keys below
    //    match TBL_Attendance_Override columns exactly. ────────────────────
    function resetOverrideForm() {
        // Today's Attendance / Classification stay populated — re-fetching
        // the same date should show what was just saved. Only the truly
        // one-off fields reset.
        schedAmIn.value = '';
        schedAmOut.value = '';
        schedPmIn.value = '';
        schedPmOut.value = '';
        ovRemarks.value = '';
        ovAttachment.value = '';
    }

    btnSaveOverride.addEventListener('click', function () {
        ovStatusMsg.style.display = 'none';
        ovSuccessMsg.style.display = 'none';

        if (!ovType.value) {
            ovStatusMsg.textContent = 'Override Type is required.';
            ovStatusMsg.style.display = 'block';
            return;
        }

        const meta = deriveOverrideMeta();

        const body = new FormData();
        body.append('ADate', dateInput.value);

        // Legacy single-punch tracking columns — OriginalTime is no longer
        // populated (per team direction); ATime/Direction/ShiftPart are
        // auto-derived from whichever punch changed vs. the actual record.
        body.append('OriginalTime', '');
        body.append('ATime', meta.ATime);
        body.append('Direction', meta.Direction);
        body.append('ShiftPart', meta.ShiftPart);

        // Actual punch times — from Today's Attendance
        body.append('AtimeIn', amTimeIn.value);
        body.append('AtimeOutAM', amTimeOut.value);
        body.append('AtimeInPM', pmTimeIn.value);
        body.append('AtimeOut', pmTimeOut.value);

        // Computed totals
        body.append('AMLate', amLate.value);
        body.append('PMLate', pmLate.value);
        body.append('Late', dayTotalLate.value);
        body.append('MorningTotalHours', amTotalHours.value);
        body.append('AfternoonTotalHours', pmTotalHours.value);
        body.append('MorningAfternoonTotal', dayTotalHours.value);
        body.append('TotalHours', dayTotalHours.value);
        body.append('DayCount', dayCount.value);

        // Full-day shift schedule override
        body.append('SetTime', schedAmIn.value);
        body.append('SetTimeOutAM', schedAmOut.value);
        body.append('SetTimeInPM', schedPmIn.value);
        body.append('SetTimeOutPM', schedPmOut.value);

        // Classification & Location
        body.append('Status', ovStatus.value);
        body.append('PayrollGroup', ovPayrollGroup.value);
        body.append('Aday', ovAday.value);
        body.append('Area', ovArea.value);
        body.append('AreaOut', ovAreaOut.value);
        if (ovAttachment.files[0]) body.append('Attachment', ovAttachment.files[0]);

        body.append('Override_Type', ovType.value);
        body.append('Remarks', ovRemarks.value);

        btnSaveOverride.disabled = true;

        fetch('override-attendance-ajax.php?action=save_override', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
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