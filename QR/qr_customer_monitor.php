<?php
// TWM/QR/qr_customer_monitor.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'qr_customer_monitor');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---- Date range (defaults to the current month) ----
$isDate   = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$dateFrom = (isset($_GET['date_from']) && $isDate($_GET['date_from'])) ? $_GET['date_from'] : date('Y-m-01');
$dateTo   = (isset($_GET['date_to'])   && $isDate($_GET['date_to']))   ? $_GET['date_to']   : date('Y-m-d');
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

// ---- One row per registered customer QR x salesman x day; Freq = number of scans (visits) that day ----
$sql = "
    SELECT s.QRCODE,
           ci.Code,
           ci.CustomerName,
           ci.Address, ci.Barangay, ci.Area, ci.Province,
           CAST(s.DateTimeInput AS date) AS VisitDate,
           e.FirstName + ' ' + e.LastName AS Salesman,
           e.Department,
           COUNT(*) AS Freq
    FROM dbo.Tbl_QRCode_Scan_Log s
    INNER JOIN dbo.Tbl_Customer_Info ci ON s.QRCODE = ci.QRCODE
    INNER JOIN dbo.TBL_HREmployeeList e ON s.FileNo = e.FileNo
    WHERE s.QRCODE IS NOT NULL AND LTRIM(RTRIM(s.QRCODE)) <> ''
      AND s.DateTimeInput >= ?
      AND s.DateTimeInput < DATEADD(DAY, 1, ?)
    GROUP BY s.QRCODE, ci.Code, ci.CustomerName, ci.Address, ci.Barangay, ci.Area, ci.Province, CAST(s.DateTimeInput AS date),
             e.FirstName + ' ' + e.LastName, e.Department
    ORDER BY VisitDate DESC, ci.CustomerName ASC
";
// Query whole months so the F1/F2/F4 tier is counted correctly even if the date filter cuts a month
$qFrom = date('Y-m-01', strtotime($dateFrom));
$qTo   = date('Y-m-t',  strtotime($dateTo));
$stmt = sqlsrv_query($conn, $sql, [$qFrom, $qTo]);
if ($stmt === false) {
    die('QR monitor query failed: ' . print_r(sqlsrv_errors(), true));
}

$rows = [];
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $d = $r['VisitDate'];
    $dt = ($d instanceof DateTime) ? $d : new DateTime((string)$d);
    $dept = trim((string)$r['Department']);
    $rows[] = [
        'qr'    => trim((string)$r['QRCODE']),
        'code'  => trim((string)$r['Code']),
        'cust'  => trim((string)$r['CustomerName']),
        'addr'  => implode(', ', array_filter([trim((string)$r['Address']), trim((string)$r['Barangay']), trim((string)$r['Province'])])),
        'area'  => trim((string)$r['Area']),
        'month' => $dt->format('F Y'),
        'date'  => $dt->format('Y-m-d'),
        'dept'  => $dept !== '' ? $dept : 'Unassigned',
        'sales' => trim((string)$r['Salesman']),
        'freq'  => (int)$r['Freq'],
    ];
}

// ---- Collapse duplicates: the same QR + salesman + date must count as ONE visit date.
// (Duplicates appear when Tbl_Customer_Info holds more than one record with the same QRCODE.)
$merged = [];
foreach ($rows as $r) {
    $k = $r['qr'] . '|' . $r['sales'] . '|' . $r['date'];
    if (!isset($merged[$k])) {
        $merged[$k] = $r;
    } else {
        $merged[$k]['freq'] = max($merged[$k]['freq'], $r['freq']);
    }
}
$rows = array_values($merged);

// ---- Visit days per month for each customer QR x salesman ----
$monthDays = [];
foreach ($rows as $r) {
    $k = $r['qr'] . '|' . $r['sales'] . '|' . substr($r['date'], 0, 7);
    $monthDays[$k] = ($monthDays[$k] ?? 0) + 1;
}

// Keep only the requested date range, then tag each row with its frequency code
$rows = array_values(array_filter($rows, fn($r) => $r['date'] >= $dateFrom && $r['date'] <= $dateTo));
foreach ($rows as &$r) {
    $n = $monthDays[$r['qr'] . '|' . $r['sales'] . '|' . substr($r['date'], 0, 7)] ?? 1;
    $r['fcode'] = $n >= 4 ? 'F4' : ($n >= 2 ? 'F2' : 'F1');
}
unset($r);

// Customer QR Code ascending (natural order), then date, then salesman
usort($rows, fn($a, $b) =>
    strnatcasecmp($a['qr'], $b['qr'])
    ?: strcmp($a['date'], $b['date'])
    ?: strcmp($a['sales'], $b['sales'])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer QR Code Monitoring</title>
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/admin.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/topbar.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/responsive-patch.css">
<style>
  .qcm-wrap { padding: 24px; }
  .qcm-filter-card { border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:20px; background:#fff; }
  .qcm-table th, .qcm-table td { vertical-align: middle; font-size: 13.5px; }
  .qcm-empty { text-align:center; padding:40px; color:#64748b; }
  .qcm-stat-row { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:20px; }
  .qcm-stat-card { flex:1; min-width:150px; border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; background:#fff; }
  .qcm-stat-label { font-size:11.5px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:4px; }
  .qcm-stat-value { font-size:20px; font-weight:700; color:#1e293b; }
  .qcm-freq { cursor:help; display:inline-block; min-width:38px; padding:2px 8px; border-radius:999px; font-weight:600; text-align:center; color:#fff; background:#94a3b8; }
  .qcm-freq.mid  { background:#1d4ed8; }
  .qcm-freq.high { background:#16a34a; }
  .qcm-qr-code { font-weight:600; }
  .qcm-qr-name { color:#64748b; font-size:12px; }
  .qcm-row { cursor:pointer; }
  .qcm-count { display:inline-block; min-width:28px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#1e293b; font-weight:600; text-align:center; cursor:help; }
  .qcm-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:4000; align-items:center; justify-content:center; padding:20px; }
  .qcm-modal-backdrop.active { display:flex; }
  .qcm-modal { background:#fff; border-radius:14px; padding:20px; max-width:720px; width:100%; box-shadow:0 10px 40px rgba(0,0,0,.35); max-height:90vh; overflow-y:auto; }
</style>
</head>
<body>
<?php $topbar_page = 'qr_customer_monitor'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="qcm-wrap">
  <div class="page-header">
    <h1 class="page-title">Customer QR Code Monitoring</h1>
    <p class="text-muted mb-0">Registered customer QR codes scanned from <?php echo h($dateFrom); ?> to <?php echo h($dateTo); ?></p>
  </div>

  <!-- Date range reloads the page (server-side); the other filters work instantly in the browser -->
  <form method="get" id="qcmDateForm" class="qcm-filter-card row g-3 align-items-end">
    <div class="col-md-2">
      <label class="form-label">Date From</label>
      <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Date To</label>
      <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Department</label>
      <select id="qcmDept" class="form-select"></select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Salesman</label>
      <select id="qcmSales" class="form-select"></select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Frequency</label>
      <select id="qcmFreq" class="form-select">
        <option value="">All</option>
        <option value="F1">F1 – Once a month</option>
        <option value="F2">F2 – Twice a month</option>
        <option value="F4">F4 – Once a week</option>
      </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-primary flex-grow-1">Apply</button>
      <button type="button" class="btn btn-light border" id="qcmReset" title="Reset filters">Reset</button>
    </div>
  </form>

  <div class="qcm-stat-row">
    <div class="qcm-stat-card"><div class="qcm-stat-label">Total Visits</div><div class="qcm-stat-value" id="qcmStatVisits">0</div></div>
    <div class="qcm-stat-card"><div class="qcm-stat-label">Customer QR Codes</div><div class="qcm-stat-value" id="qcmStatCust">0</div></div>
    <div class="qcm-stat-card"><div class="qcm-stat-label">Salesmen</div><div class="qcm-stat-value" id="qcmStatSales">0</div></div>
    <div class="qcm-stat-card"><div class="qcm-stat-label">Total Scans</div><div class="qcm-stat-value" id="qcmStatRows">0</div></div>
  </div>

  <div class="mb-3" style="max-width:360px;">
    <input type="text" id="qcmSearch" class="form-control" placeholder="Search QR code, customer, address, area, salesman, department...">
  </div>

  <div class="table-responsive">
    <table class="table table-bordered table-hover qcm-table bg-white">
      <thead class="table-light">
        <tr>
          <th>Customer QR Code</th>
          <th>Address</th>
          <th>Area</th>
          <th>Month</th>
          <th class="text-center">Department</th>
          <th class="text-center">Employee/Salesman</th>
          <th class="text-center">Frequency</th>
        </tr>
      </thead>
      <tbody id="qcmBody"></tbody>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center mt-2">
    <div class="text-muted" id="qcmShowing" style="font-size:13px;"></div>
    <div class="d-flex align-items-center gap-2">
      <button type="button" class="btn btn-sm btn-light border" id="qcmPrev">&laquo; Prev</button>
      <span style="font-size:13px;" id="qcmPageLabel"></span>
      <button type="button" class="btn btn-sm btn-light border" id="qcmNext">Next &raquo;</button>
    </div>
  </div>
</div>

<div id="qcmDetailBackdrop" class="qcm-modal-backdrop" onclick="if(event.target===this) qcmCloseDetail()">
  <div class="qcm-modal">
    <div class="d-flex justify-content-between align-items-start mb-1">
      <div>
        <h5 class="mb-0" id="qcmDetailTitle"></h5>
        <div class="text-muted" style="font-size:13px;" id="qcmDetailSub"></div>
      </div>
      <button type="button" class="btn-close" onclick="qcmCloseDetail()" aria-label="Close"></button>
    </div>
    <div class="mb-2" style="font-size:13px;" id="qcmDetailSummary"></div>
    <div class="table-responsive" style="max-height:55vh;overflow-y:auto;">
      <table class="table table-sm table-bordered qcm-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Salesman</th>
            <th>Department</th>
            <th class="text-center">Scans</th>
            <th class="text-center">Frequency</th>
          </tr>
        </thead>
        <tbody id="qcmDetailBody"></tbody>
      </table>
    </div>
    <div class="d-flex justify-content-end mt-3">
      <button type="button" class="btn btn-light border" onclick="qcmCloseDetail()">Close</button>
    </div>
  </div>
</div>

<script>
const ROWS = <?php echo json_encode($rows, JSON_INVALID_UTF8_SUBSTITUTE); ?>;
const PAGE_SIZE = 50;
const FREQ_LABEL = { F1: 'Once a month', F2: 'Twice a month', F4: 'Once a week' };

ROWS.forEach(r => { r.hay = [r.qr, r.code, r.cust, r.addr, r.area, r.sales, r.dept, r.month, r.date].join(' ').toLowerCase(); });

const qcmEl = id => document.getElementById(id);
function qcmEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

let qcmPage = 1;
let qcmFiltered = [];
let qcmGroups = [];

const QCM_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function qcmBadge(code) {
    const cls = code === 'F4' ? 'high' : (code === 'F2' ? 'mid' : '');
    return `<span class="qcm-freq ${cls}" title="Frequency (${qcmEsc(FREQ_LABEL[code])})">${qcmEsc(code)}</span>`;
}

// One month -> "October 2026"; spans several -> "Aug 2026 – Oct 2026"
function qcmMonthLabel(minDate, maxDate) {
    const mi = d => parseInt(d.slice(5, 7), 10) - 1;
    if (minDate.slice(0, 7) === maxDate.slice(0, 7)) return QCM_MONTHS[mi(minDate)] + ' ' + minDate.slice(0, 4);
    return QCM_MONTHS[mi(minDate)].slice(0, 3) + ' ' + minDate.slice(0, 4) + ' – ' +
           QCM_MONTHS[mi(maxDate)].slice(0, 3) + ' ' + maxDate.slice(0, 4);
}

// Group the (already filtered) rows by customer QR code; rows arrive QR-ascending so groups do too
function qcmBuildGroups(rows) {
    const map = new Map();
    rows.forEach(r => {
        let g = map.get(r.qr);
        if (!g) {
            g = { qr: r.qr, code: r.code, cust: r.cust, addr: r.addr, area: r.area, rows: [],
                  depts: new Set(), sales: new Set(), codes: new Set(), minDate: r.date, maxDate: r.date };
            map.set(r.qr, g);
        }
        g.rows.push(r);
        g.depts.add(r.dept);
        g.sales.add(r.sales);
        g.codes.add(r.fcode);
        if (r.date < g.minDate) g.minDate = r.date;
        if (r.date > g.maxDate) g.maxDate = r.date;
    });
    return [...map.values()];
}

function qcmPlural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

function qcmOpenDetail(i) {
    const g = qcmGroups[i];
    if (!g) return;
    qcmEl('qcmDetailTitle').textContent = g.qr;
    qcmEl('qcmDetailSub').textContent = [g.cust, g.code, g.addr, g.area].filter(Boolean).join(' · ');
    const days = new Set(g.rows.map(r => r.date)).size;
    qcmEl('qcmDetailSummary').textContent =
        qcmPlural(days, 'visit date', 'visit dates') + ' · ' +
        qcmPlural(g.sales.size, 'salesman', 'salesmen') + ' · ' +
        qcmPlural(g.depts.size, 'department', 'departments');
    qcmEl('qcmDetailBody').innerHTML = g.rows.map(r => `<tr>
        <td>${qcmEsc(r.date)}</td>
        <td>${qcmEsc(r.sales)}</td>
        <td>${qcmEsc(r.dept)}</td>
        <td class="text-center">${r.freq}</td>
        <td class="text-center">${qcmBadge(r.fcode)}</td>
    </tr>`).join('');
    qcmEl('qcmDetailBackdrop').classList.add('active');
}

function qcmCloseDetail() {
    qcmEl('qcmDetailBackdrop').classList.remove('active');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') qcmCloseDetail(); });

function qcmUnique(list) {
    return [...new Set(list)].sort((a, b) => a.localeCompare(b));
}

function qcmFillDepartments() {
    const sel = qcmEl('qcmDept');
    sel.innerHTML = '<option value="">All Departments</option>' +
        qcmUnique(ROWS.map(r => r.dept)).map(d => `<option value="${qcmEsc(d)}">${qcmEsc(d)}</option>`).join('');
}

// Salesman list narrows to the chosen department
function qcmFillSalesmen() {
    const dept = qcmEl('qcmDept').value;
    const sel = qcmEl('qcmSales');
    const keep = sel.value;
    const names = qcmUnique(ROWS.filter(r => !dept || r.dept === dept).map(r => r.sales));
    sel.innerHTML = '<option value="">All Salesmen</option>' +
        names.map(n => `<option value="${qcmEsc(n)}">${qcmEsc(n)}</option>`).join('');
    if (names.includes(keep)) sel.value = keep;
}

function qcmApply() {
    const dept  = qcmEl('qcmDept').value;
    const sales = qcmEl('qcmSales').value;
    const freq  = qcmEl('qcmFreq').value;
    const term  = qcmEl('qcmSearch').value.trim().toLowerCase();

    qcmFiltered = ROWS.filter(r => {
        if (dept && r.dept !== dept) return false;
        if (sales && r.sales !== sales) return false;
        if (freq && r.fcode !== freq) return false;
        if (term && !r.hay.includes(term)) return false;
        return true;
    });
    qcmGroups = qcmBuildGroups(qcmFiltered);
    qcmPage = 1;
    qcmRender();
}

function qcmRender() {
    const total = qcmGroups.length;
    const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (qcmPage > pages) qcmPage = pages;
    const start = (qcmPage - 1) * PAGE_SIZE;
    const slice = qcmGroups.slice(start, start + PAGE_SIZE);

    // stat cards reflect the filtered result
    qcmEl('qcmStatVisits').textContent = qcmFiltered.length.toLocaleString();
    qcmEl('qcmStatCust').textContent   = new Set(qcmFiltered.map(r => r.qr)).size.toLocaleString();
    qcmEl('qcmStatSales').textContent  = new Set(qcmFiltered.map(r => r.sales)).size.toLocaleString();
    qcmEl('qcmStatRows').textContent   = qcmFiltered.reduce((s, r) => s + r.freq, 0).toLocaleString();

    const body = qcmEl('qcmBody');
    if (!slice.length) {
        body.innerHTML = '<tr><td colspan="7" class="qcm-empty">No registered customer QR scans match the current filters.</td></tr>';
    } else {
        body.innerHTML = slice.map((g, i) => {
            const codes = ['F1', 'F2', 'F4'].filter(c => g.codes.has(c)).map(c => qcmBadge(c)).join(' ');
            return `<tr class="qcm-row" onclick="qcmOpenDetail(${start + i})">
                <td><div class="qcm-qr-code">${qcmEsc(g.qr)}</div><div class="qcm-qr-name">${qcmEsc(g.cust)}${g.code ? ' · ' + qcmEsc(g.code) : ''}</div></td>
                <td>${qcmEsc(g.addr)}</td>
                <td>${qcmEsc(g.area)}</td>
                <td>${qcmEsc(qcmMonthLabel(g.minDate, g.maxDate))}</td>
                <td class="text-center"><span class="qcm-count" title="${qcmEsc([...g.depts].sort().join(', '))}">${g.depts.size}</span></td>
                <td class="text-center"><span class="qcm-count" title="${qcmEsc([...g.sales].sort().join(', '))}">${g.sales.size}</span></td>
                <td class="text-center">${codes}</td>
            </tr>`;
        }).join('');
    }

    qcmEl('qcmShowing').textContent = total
        ? `Showing ${start + 1}–${Math.min(start + PAGE_SIZE, total)} of ${total.toLocaleString()}`
        : 'No records';
    qcmEl('qcmPageLabel').textContent = `Page ${qcmPage} of ${pages}`;
    qcmEl('qcmPrev').disabled = qcmPage <= 1;
    qcmEl('qcmNext').disabled = qcmPage >= pages;
}

qcmEl('qcmDept').addEventListener('change', () => { qcmFillSalesmen(); qcmApply(); });
qcmEl('qcmSales').addEventListener('change', qcmApply);
qcmEl('qcmFreq').addEventListener('change', qcmApply);
qcmEl('qcmSearch').addEventListener('input', qcmApply);
qcmEl('qcmPrev').addEventListener('click', () => { if (qcmPage > 1) { qcmPage--; qcmRender(); } });
qcmEl('qcmNext').addEventListener('click', () => { qcmPage++; qcmRender(); });
qcmEl('qcmReset').addEventListener('click', () => {
    // Reload without ?date_from/?date_to so the dates, dropdowns and search all return to defaults
    window.location.href = window.location.pathname;
});

qcmFillDepartments();
qcmFillSalesmen();
qcmApply();
</script>
</body>
</html>