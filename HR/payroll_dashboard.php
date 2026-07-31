<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'payroll_dashboard');

date_default_timezone_set('Asia/Manila');
$today      = date('Y-m-d');
$empName    = trim($_SESSION['DisplayName'] ?? 'User');
$roleLabel  = trim($_SESSION['UserType']    ?? 'Employee');

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
</style>
</head>
<body>

<?php
$topbar_page = 'payroll_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
$hr_active = 'payroll_dashboard';
require_once __DIR__ . '/hr_nav.php';
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
          $total = max(1, $attPresent + $attAbsent + $attLate + $attOnLeave);
          $pPct = round($attPresent / $total * 360);
          $lPct = round($attLate / $total * 360);
          $aPct = 360 - $pPct - $lPct;
        ?>
        <div class="pd-donut-wrap">
          <svg width="130" height="130" viewBox="0 0 36 36" style="flex-shrink:0;">
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e2e8f0" stroke-width="4"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#3b82f6" stroke-width="4"
                    stroke-dasharray="<?= round($attPresent/$total*100,2) ?> 100" stroke-dashoffset="25"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#22c55e" stroke-width="4"
                    stroke-dasharray="<?= round($attAbsent/$total*100,2) ?> 100"
                    stroke-dashoffset="<?= 25 - round($attPresent/$total*100,2) ?>"></circle>
            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f97316" stroke-width="4"
                    stroke-dasharray="<?= round($attLate/$total*100,2) ?> 100"
                    stroke-dashoffset="<?= 25 - round($attPresent/$total*100,2) - round($attAbsent/$total*100,2) ?>"></circle>
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

  <!-- Quick Actions — Loans confirmed at EMPLOYEE/index.php; Cash Advance
       folder still unconfirmed (cash-advance-record.php didn't self-reference
       its own path) — update that one href once you tell me where it lives. -->
  <div class="pd-card" style="padding:1.2rem;">
    <div class="pd-actions">
      <a href="<?= base_url('HR/attendance.php') ?>" class="pd-action"><i class="bi bi-calendar-check"></i>Daily Attendance</a>
      <a href="<?= base_url('HR/employee-list.php') ?>" class="pd-action"><i class="bi bi-people"></i>Employees</a>
      <a href="<?= base_url('EMPLOYEE/index.php') ?>" class="pd-action"><i class="bi bi-cash-coin"></i>Loans</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;" title="Path unconfirmed — see note above"><i class="bi bi-credit-card-2-front"></i>Cash Advance</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;"><i class="bi bi-calculator"></i>Process Payroll</a>
      <a href="#" class="pd-action" style="opacity:.5;pointer-events:none;"><i class="bi bi-file-earmark-text"></i>Reports</a>
    </div>
  </div>

</div> <!-- /.pd-wrap -->

  </main>
</div> <!-- /.hr-shell (opened by hr_nav.php) -->

</body>
</html>