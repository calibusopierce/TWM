<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'payroll_dashboard');

date_default_timezone_set('Asia/Manila');
$today      = date('Y-m-d');
$empName    = trim($_SESSION['DisplayName'] ?? 'User');
$roleLabel  = trim($_SESSION['UserType']    ?? 'Employee');
$canEditPayroll = !rbac_is_view_only('payroll_dashboard');

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v) { return '₱' . number_format((float)$v, 2); }

/* -----------------------------------------------------------
   LIVE: Total Employees + Present Today
   Confirmed against employee-list.php: TBL_HREmployeeList uses
   a bit column Active (1/0), not a status string.
----------------------------------------------------------- */
$totalEmployees = 0;
$te = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM TBL_HREmployeeList WHERE Active = 1");
if ($te) { $r = sqlsrv_fetch_array($te, SQLSRV_FETCH_ASSOC); $totalEmployees = (int)($r['C'] ?? 0); sqlsrv_free_stmt($te); }

$presentToday = 0;
$pt = sqlsrv_query($conn, "SELECT COUNT(DISTINCT EmployeeID) AS C FROM View_Attendance_Record_Daily WHERE ADate = ?", [$today]);
if ($pt) { $r = sqlsrv_fetch_array($pt, SQLSRV_FETCH_ASSOC); $presentToday = (int)($r['C'] ?? 0); sqlsrv_free_stmt($pt); }
$presentPct = $totalEmployees > 0 ? round(($presentToday / $totalEmployees) * 100, 1) : 0;

/* -----------------------------------------------------------
   LIVE: Attendance Overview donut (Present / Absent / Late today)
   ASSUMPTION (still unverified): View_Attendance_Record_Daily has
   Late1 (minutes late) and a per-day row per employee — same view
   used for the Late Days stat card on my_attendance.php.
----------------------------------------------------------- */
$attPresent = $presentToday;
$attLate = 0;
$al = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM View_Attendance_Record_Daily WHERE ADate = ? AND Late1 > 0", [$today]);
if ($al) { $r = sqlsrv_fetch_array($al, SQLSRV_FETCH_ASSOC); $attLate = (int)($r['C'] ?? 0); sqlsrv_free_stmt($al); }
$attAbsent = max(0, $totalEmployees - $attPresent);
$attOnLeave = 0; // placeholder — no Leave Management table confirmed yet
$attPresentOnly = max(0, $attPresent - $attLate); // Late is a subset of Present, carve it out so donut slices don't overlap

/* -----------------------------------------------------------
   LIVE: Top 5 Departments by headcount
----------------------------------------------------------- */
$topDepts = [];
$td = sqlsrv_query($conn, "
    SELECT TOP 5 Department, COUNT(*) AS C
    FROM TBL_HREmployeeList
    WHERE Active = 1 AND Department IS NOT NULL AND Department <> ''
    GROUP BY Department
    ORDER BY C DESC
");
if ($td) { while ($r = sqlsrv_fetch_array($td, SQLSRV_FETCH_ASSOC)) { $topDepts[] = $r; } sqlsrv_free_stmt($td); }
$maxDeptCount = $topDepts ? max(array_column($topDepts, 'C')) : 1;

/* -----------------------------------------------------------
   LIVE: Cash Advance — For Deduction (VALE module, Approved status)
   Confirmed against cash-advance-record.php: TBL_CashAdvance,
   Status = 'Approved' is exactly right.
----------------------------------------------------------- */
$cashAdvanceCount = 0;
$ca = sqlsrv_query($conn, "SELECT COUNT(*) AS C FROM TBL_CashAdvance WHERE Status = 'Approved'");
if ($ca) { $r = sqlsrv_fetch_array($ca, SQLSRV_FETCH_ASSOC); $cashAdvanceCount = (int)($r['C'] ?? 0); sqlsrv_free_stmt($ca); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payroll Dashboard · TWM</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/hr.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
.pd-wrap { max-width: 1400px; margin: 0 auto; padding: 1.25rem; }

.pd-hero { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem; }
.pd-hero h1 { font-size:1.4rem; font-weight:800; color:#1e1b4b; margin:0; }
.pd-hero .sub { font-size:.85rem; color:#64748b; margin-top:.2rem; }
.pd-hero .clock { font-size:.85rem; color:#64748b; }

.pd-stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:1rem; margin-bottom:1.25rem; }
.pd-stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem 1.3rem; display:flex; align-items:center; gap:.9rem; }
.pd-stat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#fff; flex-shrink:0; }
.pd-stat-val { font-size:1.6rem; font-weight:800; color:#0f172a; font-family:'JetBrains Mono',monospace; line-height:1; }
.pd-stat-lbl { font-size:.78rem; color:#334155; font-weight:600; margin-top:.15rem; }
.pd-stat-sub { font-size:.7rem; color:#94a3b8; margin-top:.1rem; }

.pd-grid { display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:1.1rem; margin-bottom:1.1rem; }
@media (max-width: 1100px) { .pd-grid { grid-template-columns:1fr; } }

.pd-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
.pd-card-head { padding:.9rem 1.2rem; border-bottom:1px solid #e2e8f0; font-weight:700; font-size:.88rem; color:#1e1b4b; text-transform:uppercase; letter-spacing:.03em; }
.pd-card-body { padding:1.1rem 1.2rem; }

.pd-placeholder { border:1px dashed #cbd5e1 !important; background:#f8fafc; }
.pd-placeholder .pd-card-head { color:#94a3b8; }
.pd-placeholder-note { display:flex; align-items:center; gap:.5rem; color:#94a3b8; font-size:.8rem; padding:1.5rem 1rem; text-align:center; justify-content:center; }

.pd-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.pd-table th { text-align:left; color:#64748b; font-size:.72rem; text-transform:uppercase; padding:6px 4px; border-bottom:2px solid #e2e8f0; }
.pd-table td { padding:8px 4px; border-bottom:1px solid #f1f5f9; }
.pd-badge { padding:2px 10px; border-radius:999px; font-size:.72rem; font-weight:700; background:#fef3c7; color:#b45309; }

.pd-bar-row { display:flex; align-items:center; gap:.6rem; margin-bottom:.7rem; font-size:.82rem; }
.pd-bar-label { width:100px; flex-shrink:0; color:#334155; font-weight:600; }
.pd-bar-track { flex:1; background:#f1f5f9; border-radius:6px; height:18px; position:relative; overflow:hidden; }
.pd-bar-fill { height:100%; border-radius:6px; display:flex; align-items:center; justify-content:flex-end; padding-right:8px; color:#fff; font-size:.72rem; font-weight:700; }

.pd-donut-wrap { display:flex; align-items:center; gap:1.2rem; }
.pd-legend div { display:flex; align-items:center; gap:.5rem; font-size:.8rem; margin-bottom:.5rem; }
.pd-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

.pd-actions { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:.9rem; margin-top:.5rem; }
.pd-action { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem .6rem; text-align:center; text-decoration:none; color:#1e1b4b; font-weight:600; font-size:.8rem; transition:.15s; }
.pd-action:hover { border-color:#2563eb; color:#2563eb; transform:translateY(-2px); }
.pd-action i { font-size:1.5rem; display:block; margin-bottom:.4rem; color:#2563eb; }

/* Payroll Group Modal */
.pg-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; padding:1rem; }
.pg-overlay.open { display:flex; }
.pg-modal { background:#fff; border-radius:16px; width:100%; max-width:920px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,.25); }
.pg-table-wrap { overflow-x: auto; }
.pg-modal-head { padding:1rem 1.3rem; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; }
.pg-modal-head h3 { margin:0; font-size:1.05rem; font-weight:800; color:#1e1b4b; display:flex; align-items:center; gap:8px; }
.pg-close { background:none; border:none; font-size:1.3rem; color:#64748b; cursor:pointer; line-height:1; }
.pg-close:hover { color:#0f172a; }
.pg-modal-body { padding:1.1rem 1.3rem; overflow-y:auto; }
.pg-toolbar { display:flex; justify-content:flex-end; margin-bottom:.8rem; }
.pg-btn { border:none; border-radius:30px; padding:7px 16px; font-size:.78rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
.pg-btn-primary { background:#2563eb; color:#fff; }
.pg-btn-primary:hover { background:#1d4ed8; }
.pg-btn-secondary { background:#e2e8f0; color:#334155; }
.pg-btn-secondary:hover { background:#cbd5e1; }
.pg-btn-sm { padding:4px 12px; font-size:.72rem; margin-right:4px; }
.pg-form { display:none; grid-template-columns:1fr 1fr; gap:.7rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1rem; margin-bottom:1rem; }
.pg-form.open { display:grid; }
.pg-form label { font-size:.72rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.03em; display:block; margin-bottom:.25rem; }
.pg-form input, .pg-form select { width:100%; padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; font-size:.85rem; }
.pg-form-actions { grid-column:1 / -1; display:flex; gap:.5rem; justify-content:flex-end; margin-top:.3rem; }
.pg-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.pg-table th { text-align:left; color:#64748b; font-size:.7rem; text-transform:uppercase; padding:8px 6px; border-bottom:2px solid #e2e8f0; }
.pg-table td { padding:9px 6px; border-bottom:1px solid #f1f5f9; }
.pg-badge-active { background:#dcfce7; color:#166534; padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.pg-badge-inactive { background:#f1f5f9; color:#64748b; padding:2px 10px; border-radius:999px; font-size:.7rem; font-weight:700; }
.pg-empty { text-align:center; color:#94a3b8; padding:2rem; font-size:.85rem; }
</style>
</head>
<body>

<?php
$topbar_page = 'payroll_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'payroll_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/HR/hr_nav.php';
?>

<div class="pd-wrap">

  <div class="pd-hero">
    <div>
      <h1>Payroll Dashboard</h1>
      <div class="sub">Urban Tradewell Corporation — Welcome, <?= h($empName) ?> (<?= h($roleLabel) ?>)</div>
    </div>
    <div class="clock"><i class="bi bi-calendar3"></i> <?= date('l, F j, Y · h:i A') ?></div>
  </div>

  <!-- Stat cards -->
  <div class="pd-stat-row">
    <div class="pd-stat-card">
      <div class="pd-stat-icon" style="background:#3b82f6;"><i class="bi bi-people-fill"></i></div>
      <div><div class="pd-stat-val"><?= number_format($totalEmployees) ?></div><div class="pd-stat-lbl">Total Employees</div><div class="pd-stat-sub">Active employees</div></div>
    </div>
    <div class="pd-stat-card">
      <div class="pd-stat-icon" style="background:#22c55e;"><i class="bi bi-calendar-check-fill"></i></div>
      <div><div class="pd-stat-val"><?= number_format($presentToday) ?></div><div class="pd-stat-lbl">Present Today</div><div class="pd-stat-sub"><?= $presentPct ?>% of employees</div></div>
    </div>
    <div class="pd-stat-card pd-placeholder">
      <div class="pd-stat-icon" style="background:#f97316;"><i class="bi bi-cash-coin"></i></div>
      <div><div class="pd-stat-val">—</div><div class="pd-stat-lbl">Pending Payroll</div><div class="pd-stat-sub">No data source yet</div></div>
    </div>
    <div class="pd-stat-card pd-placeholder">
      <div class="pd-stat-icon" style="background:#818cf8;"><i class="bi bi-journal-text"></i></div>
      <div><div class="pd-stat-val">—</div><div class="pd-stat-lbl">Leave Requests</div><div class="pd-stat-sub">No data source yet</div></div>
    </div>
    <div class="pd-stat-card">
      <div class="pd-stat-icon" style="background:#ef4444;"><i class="bi bi-credit-card-2-front-fill"></i></div>
      <div><div class="pd-stat-val"><?= number_format($cashAdvanceCount) ?></div><div class="pd-stat-lbl">Cash Advance</div><div class="pd-stat-sub">For deduction</div></div>
    </div>
  </div>

  <div class="pd-grid">

    <!-- Payroll Schedule — PLACEHOLDER -->
    <div class="pd-card pd-placeholder">
      <div class="pd-card-head"><i class="bi bi-calendar-week"></i> Payroll Schedule</div>
      <div class="pd-placeholder-note"><i class="bi bi-info-circle"></i> No Payroll Processing table confirmed yet — tell me the table/view name and I'll wire this in.</div>
    </div>

    <!-- Payroll Summary — PLACEHOLDER -->
    <div class="pd-card pd-placeholder">
      <div class="pd-card-head"><i class="bi bi-cash-stack"></i> Payroll Summary (This Period)</div>
      <div class="pd-placeholder-note"><i class="bi bi-info-circle"></i> No Payroll Summary table confirmed yet.</div>
    </div>

    <!-- Attendance Overview — LIVE -->
    <div class="pd-card">
      <div class="pd-card-head"><i class="bi bi-pie-chart-fill"></i> Attendance Overview (Today)</div>
      <div class="pd-card-body">
        <?php
          $total = max(1, $attPresentOnly + $attAbsent + $attLate + $attOnLeave);
          $pPct = round($attPresentOnly / $total * 360);
          $lPct = round($attLate / $total * 360);
          $aPct = 360 - $pPct - $lPct;
        ?>
        <div class="pd-donut-wrap">
          <svg width="130" height="130" viewBox="0 0 36 36" style="flex-shrink:0;">
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="4"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#3b82f6" stroke-width="4"
                    stroke-dasharray="<?= round($attPresentOnly/$total*100,2) ?> 100" stroke-dashoffset="25"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#22c55e" stroke-width="4"
                    stroke-dasharray="<?= round($attAbsent/$total*100,2) ?> 100"
                    stroke-dashoffset="<?= 25 - round($attPresentOnly/$total*100,2) ?>"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f97316" stroke-width="4"
                    stroke-dasharray="<?= round($attLate/$total*100,2) ?> 100"
                    stroke-dashoffset="<?= 25 - round($attPresentOnly/$total*100,2) - round($attAbsent/$total*100,2) ?>"></circle>
          </svg>
          <div class="pd-legend">
            <div><span class="pd-dot" style="background:#3b82f6;"></span> Present <b style="margin-left:auto;"><?= $attPresent ?></b></div>
            <div><span class="pd-dot" style="background:#22c55e;"></span> Absent <b style="margin-left:auto;"><?= $attAbsent ?></b></div>
            <div><span class="pd-dot" style="background:#f97316;"></span> Late <b style="margin-left:auto;"><?= $attLate ?></b></div>
            <div><span class="pd-dot" style="background:#94a3b8;"></span> On Leave <b style="margin-left:auto;">—</b></div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <div class="pd-grid">

    <!-- Top 5 Departments — LIVE -->
    <div class="pd-card">
      <div class="pd-card-head"><i class="bi bi-bar-chart-fill"></i> Top 5 Departments (By Headcount)</div>
      <div class="pd-card-body">
        <?php if (empty($topDepts)): ?>
          <div class="pd-placeholder-note">No department data found.</div>
        <?php else: foreach ($topDepts as $i => $d):
          $colors = ['#f63b54','#007df1','#22c55e','#d3be00','#28bbc0'];
          $pct = round(((int)$d['C'] / $maxDeptCount) * 100);
        ?>
        <div class="pd-bar-row">
          <div class="pd-bar-label"><?= h($d['Department']) ?></div>
          <div class="pd-bar-track">
            <div class="pd-bar-fill" style="width:<?= max($pct,14) ?>%;background:<?= $colors[$i % 5] ?>;"><?= (int)$d['C'] ?></div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Government Contributions — PLACEHOLDER -->
    <div class="pd-card pd-placeholder">
      <div class="pd-card-head"><i class="bi bi-bank"></i> Government Contributions (This Period)</div>
      <div class="pd-placeholder-note"><i class="bi bi-info-circle"></i> No Government Contribution table confirmed yet.</div>
    </div>

    <!-- Recent Activities — PLACEHOLDER -->
    <div class="pd-card pd-placeholder">
      <div class="pd-card-head"><i class="bi bi-clock-history"></i> Recent Activities</div>
      <div class="pd-placeholder-note"><i class="bi bi-info-circle"></i> No shared activity log confirmed yet — the RBAC audit log covers RBAC actions only, not module-wide activity.</div>
    </div>

  </div>

  <!-- Quick Actions — Loans confirmed at LOANS/index.php; Cash Advance
       folder still unconfirmed (cash-advance-record.php didn't self-reference
       its own path) — update that one href once you tell me where it lives. -->
  <div class="pd-card" style="padding:1.2rem;">
    <div class="pd-actions">
      <a href="<?= base_url('HR/attendance.php') ?>" class="pd-action"><i class="bi bi-calendar-check"></i>Daily Attendance</a>
      <a href="<?= base_url('HR/employee-list.php') ?>" class="pd-action"><i class="bi bi-people"></i>Employees</a>
      <a href="<?= base_url('LOANS/index.php') ?>" class="pd-action"><i class="bi bi-cash-coin"></i>Loans</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;" title="Path unconfirmed — see note above"><i class="bi bi-credit-card-2-front"></i>Cash Advance</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;"><i class="bi bi-calculator"></i>Process Payroll</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;"><i class="bi bi-file-earmark-text"></i>Reports</a>
      <button type="button" class="pd-action" style="cursor:pointer;font-family:inherit;background:#fff;" onclick="openPayrollGroupModal()"><i class="bi bi-diagram-3"></i>Payroll Group</button>
      <button type="button" class="pd-action" style="cursor:pointer;font-family:inherit;background:#fff;" onclick="openPayrollCutoffModal()"><i class="bi bi-calendar-range"></i>Payroll Cutoff</button>
      <button type="button" class="pd-action" style="cursor:pointer;font-family:inherit;background:#fff;" onclick="openBasicSalaryModal()"><i class="bi bi-wallet2"></i>Basic Salary</button>
    </div>
  </div>

</div> <!-- /.pd-wrap -->

  <!-- Payroll Cutoff Modal -->
  <div class="pg-overlay" id="pcOverlay">
    <div class="pg-modal">
      <div class="pg-modal-head">
        <h3><i class="bi bi-calendar-range"></i> Payroll Cutoff</h3>
        <button class="pg-close" onclick="closePayrollCutoffModal()">&times;</button>
      </div>
      <div class="pg-modal-body">
        <?php if ($canEditPayroll): ?>
        <div class="pg-toolbar">
          <button class="pg-btn pg-btn-primary" onclick="showAddPcForm()"><i class="bi bi-plus-lg"></i> Add Cutoff</button>
        </div>
        <div class="pg-form" id="pcForm">
          <div style="grid-column:1 / -1;"><strong id="pcFormTitle">Add Cutoff</strong></div>
          <div>
            <label>Cutoff Name</label>
            <input type="text" id="pcName" placeholder="e.g. 26th–10th">
          </div>
          <div></div>
          <div>
            <label>Start Day</label>
            <input type="text" id="pcStartDay" placeholder="e.g. 26th">
          </div>
          <div>
            <label>End Day</label>
            <input type="text" id="pcEndDay" placeholder="e.g. 10th">
          </div>
          <div class="pg-form-actions">
            <button class="pg-btn pg-btn-secondary" onclick="closePcForm()">Cancel</button>
            <button class="pg-btn pg-btn-primary" onclick="savePayrollCutoff()">Save</button>
          </div>
        </div>
        <?php endif; ?>
        <table class="pg-table">
          <thead>
            <tr>
              <th>Cutoff Name</th>
              <th>Start Day</th>
              <th>End Day</th>
              <th>Created By</th>
              <?php if ($canEditPayroll): ?><th>Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="pcTableBody">
            <tr><td colspan="5" class="pg-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Payroll Group Modal -->
  <div class="pg-overlay" id="pgOverlay">
    <div class="pg-modal">
      <div class="pg-modal-head">
        <h3><i class="bi bi-diagram-3"></i> Payroll Group</h3>
        <button class="pg-close" onclick="closePayrollGroupModal()">&times;</button>
      </div>
      <div class="pg-modal-body">
        <div id="pgMainView">
        <?php if ($canEditPayroll): ?>
        <div class="pg-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:.6rem;">
          <div style="display:flex;gap:.6rem;flex:1 1 320px;">
            <input type="text" id="pgSearch" placeholder="Search department or payroll group…" style="flex:1 1 200px;padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
            <select id="pgDeptFilter" style="flex:0 1 190px;padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;background:#fff;">
              <option value="">All Departments</option>
            </select>
          </div>
          <button class="pg-btn pg-btn-primary" onclick="showAddPgForm()"><i class="bi bi-plus-lg"></i> Add Payroll Group</button>
        </div>
        <div class="pg-form" id="pgForm">
          <div style="grid-column:1 / -1;"><strong id="pgFormTitle">Add Payroll Group</strong></div>
          <div>
            <label>Department</label>
            <input type="text" id="pgDepartment">
          </div>
          <div>
            <label>Payroll Group</label>
            <input type="text" id="pgGroup" placeholder="e.g. ATM Employees">
          </div>
          <div>
            <label>Payment Method</label>
            <select id="pgMethod">
              <option value="">Select…</option>
              <option value="ATM">ATM</option>
              <option value="Cash">Cash</option>
            </select>
          </div>
          <div>
            <label>Payroll Frequency</label>
            <select id="pgFrequency">
              <option value="">Select…</option>
              <option value="Semi-Monthly">Semi-Monthly</option>
              <option value="Monthly">Monthly</option>
              <option value="Weekly">Weekly</option>
            </select>
          </div>
          <div class="pg-form-actions">
            <button class="pg-btn pg-btn-secondary" onclick="closePgForm()">Cancel</button>
            <button class="pg-btn pg-btn-primary" onclick="savePayrollGroup()">Save</button>
          </div>
        </div>
        <?php endif; ?>
        <div class="pg-table-wrap">
        <table class="pg-table">
          <thead>
            <tr>
              <th>Department</th>
              <th>Payroll Group</th>
              <th>Payment Method</th>
              <th>Frequency</th>
              <th>Status</th>
              <?php if ($canEditPayroll): ?><th>Action</th><?php endif; ?>
            </tr>
          </thead>
          <tbody id="pgTableBody">
            <tr><td colspan="6" class="pg-empty">Loading…</td></tr>
          </tbody>
        </table>
        </div>
        </div>

        <!-- Employees sub-view: tag/re-tag employees to a Payroll Group via TBL_DeviceID -->
        <div id="pgEmployeesView" style="display:none;">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;">
            <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="closePgEmployees()"><i class="bi bi-arrow-left"></i> Back</button>
            <strong id="pgEmpGroupTitle" style="font-size:.95rem;color:#1e1b4b;"></strong>
          </div>

          <?php if ($canEditPayroll): ?>
          <div style="position:relative;margin-bottom:1rem;">
            <input type="text" id="pgEmpSearch" placeholder="Search employee by name to tag…" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
            <div id="pgEmpSearchResults" style="display:none;position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-top:4px;max-height:220px;overflow-y:auto;box-shadow:0 8px 20px rgba(0,0,0,.1);z-index:5;"></div>
          </div>
          <?php endif; ?>

          <div class="pg-table-wrap">
          <table class="pg-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Payroll Group</th>
                <?php if ($canEditPayroll): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody id="pgEmpTableBody">
              <tr><td colspan="4" class="pg-empty">Loading…</td></tr>
            </tbody>
          </table>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
  const CSRF_TOKEN = "<?= htmlspecialchars(rbac_csrf_token(), ENT_QUOTES, 'UTF-8') ?>";
</script>

<script>
(function() {
  const pgOverlay    = document.getElementById('pgOverlay');
  const pgForm       = document.getElementById('pgForm');
  const pgTableBody  = document.getElementById('pgTableBody');
  const pgFormTitle  = document.getElementById('pgFormTitle');
  const pgCanEdit    = <?= $canEditPayroll ? 'true' : 'false' ?>;
  const pgSearch     = document.getElementById('pgSearch');
  const pgDeptFilter = document.getElementById('pgDeptFilter');

  let editingId = null;
  let pgRows = [];

  window.openPayrollGroupModal = function() {
    pgOverlay.classList.add('open');
    closePgForm();
    closePgEmployees();
    if (pgSearch) pgSearch.value = '';
    if (pgDeptFilter) pgDeptFilter.value = '';
    loadPayrollGroups();
  };

  function populateDeptFilter() {
    if (!pgDeptFilter) return;
    const current = pgDeptFilter.value;
    const depts = Array.from(new Set(pgRows.map(r => r.Department).filter(d => d))).sort();
    pgDeptFilter.innerHTML = '<option value="">All Departments</option>' +
      depts.map(d => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('');
    pgDeptFilter.value = depts.includes(current) ? current : '';
  }

  function getFilteredPgRows() {
    const q = (pgSearch && pgSearch.value.trim().toLowerCase()) || '';
    const dept = (pgDeptFilter && pgDeptFilter.value) || '';
    return pgRows.filter(row => {
      if (dept && row.Department !== dept) return false;
      if (q) {
        const hay = (row.Department + ' ' + row.PayrollGroup + ' ' + row.PaymentMethod + ' ' + (row.PayrollFrequency || '')).toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }

  if (pgSearch)     pgSearch.addEventListener('input', renderPgTable);
  if (pgDeptFilter) pgDeptFilter.addEventListener('change', renderPgTable);

  window.closePayrollGroupModal = function() {
    pgOverlay.classList.remove('open');
  };

  window.showAddPgForm = function() {
    editingId = null;
    pgFormTitle.textContent = 'Add Payroll Group';
    document.getElementById('pgDepartment').value = '';
    document.getElementById('pgGroup').value = '';
    document.getElementById('pgMethod').value = '';
    document.getElementById('pgFrequency').value = '';
    pgForm.classList.add('open');
    scrollModalToForm();
  };

  window.closePgForm = function() {
    pgForm.classList.remove('open');
  };

  window.editPayrollGroup = function(id) {
    const row = pgRows.find(r => r.ID === id);
    if (!row) return;
    editingId = row.ID;
    pgFormTitle.textContent = 'Edit Payroll Group';
    document.getElementById('pgDepartment').value = row.Department;
    document.getElementById('pgGroup').value = row.PayrollGroup;
    document.getElementById('pgMethod').value = row.PaymentMethod;
    document.getElementById('pgFrequency').value = row.PayrollFrequency;
    pgForm.classList.add('open');
    scrollModalToForm();
  };

  window.savePayrollGroup = function() {
    const department = document.getElementById('pgDepartment').value.trim();
    const group       = document.getElementById('pgGroup').value.trim();
    const method       = document.getElementById('pgMethod').value.trim();
    const frequency     = document.getElementById('pgFrequency').value.trim();

    if (!department || !group || !method) {
      alert('Department, Payroll Group, and Payment Method are required.');
      return;
    }

    const body = new URLSearchParams();
    body.set('action', editingId ? 'update' : 'create');
    if (editingId) body.set('ID', editingId);
    body.set('Department', department);
    body.set('PayrollGroup', group);
    body.set('PaymentMethod', method);
    body.set('PayrollFrequency', frequency);
    body.set('csrf_token', CSRF_TOKEN);

    fetch('payroll_group_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closePgForm();
        loadPayrollGroups();
      } else {
        alert(data.message || data.msg || 'Save failed.');
      }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  window.togglePayrollGroupActive = function(id) {
    if (!confirm('Change the active status of this payroll group?')) return;
    fetch('payroll_group_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: 'action=toggle_active&ID=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        loadPayrollGroups();
      } else {
        alert(data.message || data.msg || 'Action failed.');
      }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  function loadPayrollGroups() {
    pgTableBody.innerHTML = '<tr><td colspan="6" class="pg-empty">Loading…</td></tr>';
    fetch('payroll_group_ajax.php?action=list')
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          pgTableBody.innerHTML = '<tr><td colspan="6" class="pg-empty">' + (data.message || 'Failed to load.') + '</td></tr>';
          return;
        }
        pgRows = data.data;
        populateDeptFilter();
        renderPgTable();
      })
      .catch(() => {
        pgTableBody.innerHTML = '<tr><td colspan="6" class="pg-empty">Something went wrong loading the list.</td></tr>';
      });
  }

  function renderPgTable() {
    const rows = getFilteredPgRows();
    if (!rows.length) {
      pgTableBody.innerHTML = '<tr><td colspan="6" class="pg-empty">' + (pgRows.length ? 'No payroll groups match your filter.' : 'No payroll groups yet.') + '</td></tr>';
      return;
    }
    pgTableBody.innerHTML = rows.map(row => `
      <tr>
        <td>${escapeHtml(row.Department)}</td>
        <td>${escapeHtml(row.PayrollGroup)}</td>
        <td>${escapeHtml(row.PaymentMethod)}</td>
        <td>${escapeHtml(row.PayrollFrequency || '—')}</td>
        <td>${row.Active ? '<span class="pg-badge-active">Active</span>' : '<span class="pg-badge-inactive">Inactive</span>'}</td>
        <td>
          ${pgCanEdit ? `
          <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="editPayrollGroup(${row.ID})">Edit</button>
          <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="togglePayrollGroupActive(${row.ID})">${row.Active ? 'Deactivate' : 'Activate'}</button>
          ` : ''}
          <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="openPgEmployees(${row.ID})">Employees</button>
        </td>
      </tr>
    `).join('');
  }

  function scrollModalToForm() {
    document.querySelector('.pg-modal-body').scrollTo({ top: 0, behavior: 'smooth' });
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }

  // ---- Employees sub-view (tag employees to a Payroll Group via TBL_DeviceID) ----
  const pgMainView         = document.getElementById('pgMainView');
  const pgEmployeesView    = document.getElementById('pgEmployeesView');
  const pgEmpGroupTitle    = document.getElementById('pgEmpGroupTitle');
  const pgEmpTableBody     = document.getElementById('pgEmpTableBody');
  const pgEmpSearch        = document.getElementById('pgEmpSearch');
  const pgEmpSearchResults = document.getElementById('pgEmpSearchResults');

  let currentGroupName = null;
  let empSearchTimer = null;

  window.openPgEmployees = function(id) {
    const row = pgRows.find(r => r.ID === id);
    if (!row) return;
    currentGroupName = row.PayrollGroup;
    pgEmpGroupTitle.textContent = 'Employees — ' + row.PayrollGroup;
    pgMainView.style.display = 'none';
    pgEmployeesView.style.display = 'block';
    if (pgEmpSearch) pgEmpSearch.value = '';
    if (pgEmpSearchResults) { pgEmpSearchResults.style.display = 'none'; pgEmpSearchResults.innerHTML = ''; }
    loadPgEmployees();
  };

  window.closePgEmployees = function() {
    pgEmployeesView.style.display = 'none';
    pgMainView.style.display = 'block';
    currentGroupName = null;
  };

  function groupOptionsHtml(selected) {
    const names = new Set(pgRows.filter(r => r.Active).map(r => r.PayrollGroup));
    if (selected) names.add(selected);
    return Array.from(names).sort().map(n =>
      `<option value="${escapeHtml(n)}" ${n === selected ? 'selected' : ''}>${escapeHtml(n)}</option>`
    ).join('');
  }

  function loadPgEmployees() {
    if (!currentGroupName) return;
    pgEmpTableBody.innerHTML = '<tr><td colspan="4" class="pg-empty">Loading…</td></tr>';
    fetch('payroll_device_ajax.php?action=list_by_group&PayrollGroup=' + encodeURIComponent(currentGroupName))
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          pgEmpTableBody.innerHTML = '<tr><td colspan="4" class="pg-empty">' + escapeHtml(data.message || 'Failed to load.') + '</td></tr>';
          return;
        }
        if (!data.data.length) {
          pgEmpTableBody.innerHTML = '<tr><td colspan="4" class="pg-empty">No employees tagged to this group yet.</td></tr>';
          return;
        }
        pgEmpTableBody.innerHTML = data.data.map(emp => `
          <tr>
            <td>${escapeHtml(emp.Name)}</td>
            <td>${escapeHtml(emp.Department)}</td>
            <td>${pgCanEdit
              ? `<select onchange="reassignPgEmployee('${emp.EmployeeID}', this.value)">${groupOptionsHtml(emp.PayrollGroup)}</select>`
              : escapeHtml(emp.PayrollGroup)}</td>
            ${pgCanEdit ? `<td><button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="untagPgEmployee('${emp.EmployeeID}')">Remove</button></td>` : '<td>—</td>'}
          </tr>
        `).join('');
      })
      .catch(() => {
        pgEmpTableBody.innerHTML = '<tr><td colspan="4" class="pg-empty">Something went wrong loading employees.</td></tr>';
      });
  }

  window.reassignPgEmployee = function(employeeId, newGroup) {
    fetch('payroll_device_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: 'action=assign&EmployeeID=' + encodeURIComponent(employeeId) + '&PayrollGroup=' + encodeURIComponent(newGroup)
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success) alert(data.message || 'Failed to reassign.');
      loadPgEmployees();
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  window.untagPgEmployee = function(employeeId) {
    if (!confirm('Remove this employee from the payroll group?')) return;
    fetch('payroll_device_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: 'action=assign&EmployeeID=' + encodeURIComponent(employeeId) + '&PayrollGroup='
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        loadPgEmployees();
      } else {
        alert(data.message || 'Failed to remove.');
      }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  window.tagPgEmployee = function(employeeId) {
    if (!currentGroupName) return;
    fetch('payroll_device_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: 'action=assign&EmployeeID=' + encodeURIComponent(employeeId) + '&PayrollGroup=' + encodeURIComponent(currentGroupName)
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        if (pgEmpSearch) pgEmpSearch.value = '';
        if (pgEmpSearchResults) { pgEmpSearchResults.style.display = 'none'; pgEmpSearchResults.innerHTML = ''; }
        loadPgEmployees();
      } else {
        alert(data.message || 'Failed to tag employee.');
      }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  if (pgEmpSearch) {
    pgEmpSearch.addEventListener('input', function() {
      const q = this.value.trim();
      clearTimeout(empSearchTimer);
      if (!q) {
        pgEmpSearchResults.style.display = 'none';
        pgEmpSearchResults.innerHTML = '';
        return;
      }
      empSearchTimer = setTimeout(() => {
        fetch('payroll_device_ajax.php?action=search_employees&q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(data => {
            if (!data.success || !data.data.length) {
              pgEmpSearchResults.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:.8rem;">No matches.</div>';
              pgEmpSearchResults.style.display = 'block';
              return;
            }
            pgEmpSearchResults.innerHTML = data.data.map(emp => `
              <div style="padding:8px 10px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;font-size:.83rem;">
                <span>${escapeHtml(emp.Name)} <span style="color:#94a3b8;">— ${escapeHtml(emp.Department || '')}</span>${emp.CurrentPayrollGroup ? ` <span style="color:#f97316;">(currently: ${escapeHtml(emp.CurrentPayrollGroup)})</span>` : ''}</span>
                <button class="pg-btn pg-btn-primary pg-btn-sm" onclick="tagPgEmployee('${emp.EmployeeID}')">Tag</button>
              </div>
            `).join('');
            pgEmpSearchResults.style.display = 'block';
          })
          .catch(() => {
            pgEmpSearchResults.innerHTML = '<div style="padding:10px;color:#94a3b8;font-size:.8rem;">Search failed.</div>';
            pgEmpSearchResults.style.display = 'block';
          });
      }, 300);
    });
  }
})();

(function() {
  const pcOverlay    = document.getElementById('pcOverlay');
  const pcForm       = document.getElementById('pcForm');
  const pcTableBody  = document.getElementById('pcTableBody');
  const pcFormTitle  = document.getElementById('pcFormTitle');
  const pcCanEdit    = <?= $canEditPayroll ? 'true' : 'false' ?>;

  let editingId = null;
  let pcRows = [];

  window.openPayrollCutoffModal = function() {
    pcOverlay.classList.add('open');
    closePcForm();
    loadPayrollCutoffs();
  };

  window.closePayrollCutoffModal = function() {
    pcOverlay.classList.remove('open');
  };

  window.showAddPcForm = function() {
    editingId = null;
    pcFormTitle.textContent = 'Add Cutoff';
    document.getElementById('pcName').value = '';
    document.getElementById('pcStartDay').value = '';
    document.getElementById('pcEndDay').value = '';
    pcForm.classList.add('open');
    scrollPcModalToForm();
  };

  window.closePcForm = function() {
    pcForm.classList.remove('open');
  };

  window.editPayrollCutoff = function(id) {
    const row = pcRows.find(r => r.ID === id);
    if (!row) return;
    editingId = row.ID;
    pcFormTitle.textContent = 'Edit Cutoff';
    document.getElementById('pcName').value = row.PayrollCutOff;
    document.getElementById('pcStartDay').value = row.StartDayName;
    document.getElementById('pcEndDay').value = row.EndDayName;
    pcForm.classList.add('open');
    scrollPcModalToForm();
  };

  window.savePayrollCutoff = function() {
    const name     = document.getElementById('pcName').value.trim();
    const startDay = document.getElementById('pcStartDay').value.trim();
    const endDay   = document.getElementById('pcEndDay').value.trim();

    if (!name || !startDay || !endDay) {
      alert('Cutoff Name, Start Day, and End Day are required.');
      return;
    }

    const body = new URLSearchParams();
    body.set('action', editingId ? 'update' : 'create');
    if (editingId) body.set('ID', editingId);
    body.set('PayrollCutOff', name);
    body.set('StartDayName', startDay);
    body.set('EndDayName', endDay);

    fetch('payroll_cutoff_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
      body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closePcForm();
        loadPayrollCutoffs();
      } else {
        alert(data.message || data.error || JSON.stringify(data));
      }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
  };

  function loadPayrollCutoffs() {
    pcTableBody.innerHTML = '<tr><td colspan="5" class="pg-empty">Loading…</td></tr>';
    fetch('payroll_cutoff_ajax.php?action=list')
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          pcTableBody.innerHTML = '<tr><td colspan="5" class="pg-empty">' + (data.message || 'Failed to load.') + '</td></tr>';
          return;
        }
        pcRows = data.data;
        if (!pcRows.length) {
          pcTableBody.innerHTML = '<tr><td colspan="5" class="pg-empty">No payroll cutoffs yet.</td></tr>';
          return;
        }
        pcTableBody.innerHTML = pcRows.map(row => `
          <tr>
            <td>${escapeHtmlPc(row.PayrollCutOff)}</td>
            <td>${escapeHtmlPc(row.StartDayName)}</td>
            <td>${escapeHtmlPc(row.EndDayName)}</td>
            <td>${escapeHtmlPc(row.CreatedBy)}</td>
            ${pcCanEdit ? `<td>
              <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="editPayrollCutoff(${row.ID})">Edit</button>
            </td>` : '<td>—</td>'}
          </tr>
        `).join('');
      })
      .catch(() => {
        pcTableBody.innerHTML = '<tr><td colspan="5" class="pg-empty">Something went wrong loading the list.</td></tr>';
      });
  }

  function scrollPcModalToForm() {
    document.querySelector('#pcOverlay .pg-modal-body').scrollTo({ top: 0, behavior: 'smooth' });
  }

  function escapeHtmlPc(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }
})();
</script>

<?php require __DIR__ . '/payroll_salary_modal.php'; ?>

  </main>
</div> <!-- /.hr-shell (opened by hr_nav.php) -->

</body>
</html>