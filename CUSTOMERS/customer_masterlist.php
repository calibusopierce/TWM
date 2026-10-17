<?php
// TWM/CUSTOMERS/customer_masterlist.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'customer_masterlist');

$view_only = rbac_is_view_only('customer_masterlist');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['cml_csrf'])) $_SESSION['cml_csrf'] = bin2hex(random_bytes(32));

// ---- AJAX: create / edit customer (shared with QR/qr_untagged_map.php) ----
require_once __DIR__ . '/customer_form_helper.php';
cfh_handle_post($conn, $view_only, 'cml_csrf');

const CML_PAGE_SIZE = 20;
const CML_PRINT_MAX = 5000;

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function cml_fix_coords($lat, $lng) {
    $lat = (float)$lat; $lng = (float)$lng;
    if (abs($lat) > 90 && abs($lng) <= 90) return [$lng, $lat];
    return [$lat, $lng];
}

function cml_select(int $top = 0): string {
    return "SELECT " . ($top ? "TOP $top " : "") . "ID, Code, CustomerName, Address, Barangay, Area, Province,
                   ContactNo, ContactPerson, CustomerType, TIN, CAST(Status AS varchar(50)) AS Status,
                   Remarks, Latitude, Longitude, QRCODE, DateTimeInput, UserInputID, File_Path
            FROM dbo.Tbl_Customer_Info";
}

function cml_rows($conn, string $sql, array $params): array {
    $st = sqlsrv_query($conn, $sql, $params);
    if ($st === false) die('Query failed: ' . print_r(sqlsrv_errors(), true));
    $out = [];
    while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
        foreach ($r as $k => $v) {
            if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d H:i:s');
            elseif (is_string($v))      $r[$k] = trim($v);
        }
        $r['Full'] = implode(', ', array_filter([$r['Address'], $r['Barangay'], $r['Area'], $r['Province']]));
        $out[] = $r;
    }
    return $out;
}

function cml_opts($conn, string $expr): array {
    $st = sqlsrv_query($conn, "SELECT DISTINCT $expr AS v FROM dbo.Tbl_Customer_Info
                               WHERE $expr IS NOT NULL AND LTRIM(RTRIM($expr)) <> '' AND CAST(Status AS varchar(50)) = '1' ORDER BY v");
    $o = [];
    if ($st) while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $o[] = trim((string)$r['v']);
    return $o;
}

function cml_csv_safe($v): string {
    $s = (string)$v;
    return ($s !== '' && !is_numeric($s) && strpbrk($s[0], "=+-@") !== false) ? "'" . $s : $s;
}

// ---- Filters ----
$q      = trim($_GET['q'] ?? '');
$type   = trim($_GET['type'] ?? '');
$area   = trim($_GET['area'] ?? '');
$qrf    = in_array($_GET['qr'] ?? '', ['with', 'without'], true) ? $_GET['qr'] : '';
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = ["CAST(Status AS varchar(50)) = '1'"]; $params = [];
if ($q !== '') {
    $like = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $q) . '%';
    $cols = ['Code', 'CustomerName', 'Address', 'Barangay', 'Area', 'Province', 'ContactNo', 'ContactPerson', 'TIN', 'QRCODE'];
    $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cols)) . ')';
    foreach ($cols as $_) $params[] = $like;
}
if ($type !== '')   { $where[] = 'CustomerType = ?';                    $params[] = $type; }
if ($area !== '')   { $where[] = 'Area = ?';                            $params[] = $area; }
if ($qrf === 'with')    $where[] = "(QRCODE IS NOT NULL AND LTRIM(RTRIM(QRCODE)) <> '')";
if ($qrf === 'without') $where[] = "(QRCODE IS NULL OR LTRIM(RTRIM(QRCODE)) = '')";
$whereSql = implode(' AND ', $where);

$baseQs = ['q' => $q, 'type' => $type, 'area' => $area, 'qr' => $qrf];
function cml_url(array $base, array $extra = []): string {
    return '?' . http_build_query(array_filter(array_merge($base, $extra), fn($v) => $v !== '' && $v !== null));
}

// ---- AJAX: customer photo (loaded on demand, not with the list) ----
if (($_GET['ajax'] ?? '') === 'pic') {
    header('Content-Type: application/json');
    $st = sqlsrv_query($conn, "SELECT Picture FROM dbo.Tbl_Customer_Info WHERE ID = ?", [(int)($_GET['id'] ?? 0)]);
    $pic = '';
    if ($st && ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) && !empty($r['Picture'])) {
        $bin = is_resource($r['Picture']) ? stream_get_contents($r['Picture']) : $r['Picture'];
        if ($bin !== false && $bin !== '') $pic = 'data:image/jpeg;base64,' . base64_encode($bin);
    }
    echo json_encode(['pic' => $pic]);
    exit;
}

// ---- Export CSV (all records matching the current filters) ----
if (($_GET['export'] ?? '') === 'csv') {
    $rows = cml_rows($conn, cml_select() . " WHERE $whereSql ORDER BY CustomerName, ID", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customer_masterlist_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Code', 'Customer Name', 'Address', 'Barangay', 'Area', 'Province', 'Contact Person', 'Contact No', 'Customer Type', 'TIN', 'Status', 'Remarks', 'Latitude', 'Longitude', 'QR Code', 'Registered']);
    foreach ($rows as $r) {
        fputcsv($out, array_map('cml_csv_safe', [
            $r['Code'], $r['CustomerName'], $r['Address'], $r['Barangay'], $r['Area'], $r['Province'],
            $r['ContactPerson'], $r['ContactNo'], $r['CustomerType'], $r['TIN'], $r['Status'], $r['Remarks'],
            $r['Latitude'], $r['Longitude'], $r['QRCODE'], $r['DateTimeInput'],
        ]));
    }
    fclose($out);
    exit;
}

// ---- Print report (all records matching the current filters, capped) ----
if (isset($_GET['print'])) {
    $rows = cml_rows($conn, cml_select(CML_PRINT_MAX) . " WHERE $whereSql ORDER BY CustomerName, ID", $params);
    $sum = [];
    if ($q !== '')      $sum[] = 'Search: ' . $q;
    if ($type !== '')   $sum[] = 'Type: ' . $type;
    if ($area !== '')   $sum[] = 'Area: ' . $area;
    if ($qrf !== '')    $sum[] = 'QR: ' . ($qrf === 'with' ? 'With QR' : 'Without QR');
    ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Customer Masterlist</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; color:#111; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  .meta { color:#555; margin-bottom: 12px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
  th { background: #eee; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
  .no-print { margin-bottom: 12px; } @media print { .no-print { display: none; } }
</style></head>
<body onload="window.print()">
<div class="no-print"><button onclick="window.print()">Print</button></div>
<h1>Customer Masterlist</h1>
<div class="meta">
  <?php echo count($rows); ?> record(s) &middot; Printed <?php echo h(date('Y-m-d H:i')); ?>
  <?php if ($sum): ?> &middot; <?php echo h(implode(' | ', $sum)); ?><?php endif; ?>
  <?php if (count($rows) >= CML_PRINT_MAX): ?> &middot; <strong>Limited to first <?php echo CML_PRINT_MAX; ?> records — narrow filters or use Export.</strong><?php endif; ?>
</div>
<table>
  <thead><tr><th>#</th><th>Code</th><th>Customer</th><th>Address</th><th>Contact</th><th>Type</th><th>TIN</th><th>Status</th><th>QR Code</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <tr>
      <td><?php echo $i + 1; ?></td>
      <td><?php echo h($r['Code']); ?></td>
      <td><?php echo h($r['CustomerName']); ?></td>
      <td><?php echo h($r['Full']); ?></td>
      <td><?php echo h(trim($r['ContactPerson'] . ' ' . $r['ContactNo'])); ?></td>
      <td><?php echo h($r['CustomerType']); ?></td>
      <td><?php echo h($r['TIN']); ?></td>
      <td><?php echo h($r['Status']); ?></td>
      <td><?php echo h($r['QRCODE']); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</body></html>
    <?php
    exit;
}

// ---- Page data: total + current page of 20 ----
$cst = sqlsrv_query($conn, "SELECT COUNT(*) AS n FROM dbo.Tbl_Customer_Info WHERE $whereSql", $params);
$total = ($cst && ($cr = sqlsrv_fetch_array($cst, SQLSRV_FETCH_ASSOC))) ? (int)$cr['n'] : 0;
$pages = max(1, (int)ceil($total / CML_PAGE_SIZE));
$page  = min($page, $pages);
$off   = ($page - 1) * CML_PAGE_SIZE;
$rows  = cml_rows($conn, cml_select() . " WHERE $whereSql ORDER BY CustomerName, ID OFFSET $off ROWS FETCH NEXT " . CML_PAGE_SIZE . " ROWS ONLY", $params);

$types    = cml_opts($conn, 'CustomerType');
$areas    = cml_opts($conn, 'Area');
$cf_options = cfh_options($conn);
$cf_csrf    = $_SESSION['cml_csrf'];

$jsRows = array_map(function ($r) {
    [$lat, $lng] = cml_fix_coords($r['Latitude'], $r['Longitude']);
    $hasGeo = ($lat != 0 && $lng != 0);
    return [
        'id' => (int)$r['ID'], 'code' => $r['Code'], 'name' => $r['CustomerName'], 'addr' => $r['Full'],
        'cper' => $r['ContactPerson'], 'cno' => $r['ContactNo'], 'ctype' => $r['CustomerType'],
        'tin' => $r['TIN'], 'status' => $r['Status'], 'rmk' => $r['Remarks'], 'dt' => $r['DateTimeInput'],
        'qr' => (string)$r['QRCODE'], 'lat' => $hasGeo ? $lat : null, 'lng' => $hasGeo ? $lng : null,
        'address' => $r['Address'], 'brgy' => $r['Barangay'], 'area' => $r['Area'], 'prov' => $r['Province'],
        'uid' => (int)$r['UserInputID'], 'rlat' => $r['Latitude'], 'rlng' => $r['Longitude'],
        'fpath' => (string)($r['File_Path'] ?? ''),
        'flat' => ($lat != 0 || $lng != 0) ? $lat : '', 'flng' => ($lat != 0 || $lng != 0) ? $lng : '',
    ];
}, $rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customer Masterlist</title>
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/admin.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/topbar.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/responsive-patch.css">
<style>
  .cml-wrap { padding: 24px; }
  .cml-filter-card { border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:16px; background:#fff; }
  .cml-table th, .cml-table td { vertical-align: middle; font-size: 13.5px; }
  .cml-table tbody tr { cursor: pointer; }
  .cml-empty { text-align:center; padding:40px; color:#64748b; }
  .cml-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:4000; align-items:center; justify-content:center; padding:20px; }
  .cml-backdrop.active { display:flex; }
  .cml-modal { background:#fff; border-radius:14px; padding:20px; max-width:480px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.35); }
</style>
</head>
<body>
<?php $topbar_page = 'customer_masterlist'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="cml-wrap">
  <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
      <h1 class="page-title">Customer Masterlist</h1>
      <p class="text-muted mb-0"><?php echo number_format($total); ?> customer<?php echo $total === 1 ? '' : 's'; ?> found</p>
    </div>
    <div class="d-flex gap-2">
      <?php if (!$view_only): ?><button type="button" class="btn btn-primary" onclick="cfOpenCreate()"><i class="bi bi-person-plus"></i> Add Customer</button><?php endif; ?>
      <a class="btn btn-outline-secondary" target="_blank" href="<?php echo h(cml_url($baseQs, ['print' => 1])); ?>"><i class="bi bi-printer"></i> Print Report</a>
      <a class="btn btn-success" href="<?php echo h(cml_url($baseQs, ['export' => 'csv'])); ?>"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
    </div>
  </div>

  <form method="get" class="cml-filter-card row g-3 align-items-end">
    <div class="col-md-6">
      <label class="form-label">Search</label>
      <input type="text" name="q" class="form-control" value="<?php echo h($q); ?>" placeholder="Name, code, address, contact, TIN, QR code...">
    </div>
    <div class="col-md-2">
      <label class="form-label">Customer Type</label>
      <select name="type" class="form-select">
        <option value="">All</option>
        <?php foreach ($types as $o): ?><option value="<?php echo h($o); ?>" <?php echo $type === $o ? 'selected' : ''; ?>><?php echo h($o); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Area</label>
      <select name="area" class="form-select">
        <option value="">All</option>
        <?php foreach ($areas as $o): ?><option value="<?php echo h($o); ?>" <?php echo $area === $o ? 'selected' : ''; ?>><?php echo h($o); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">QR Code</label>
      <select name="qr" class="form-select">
        <option value="">All</option>
        <option value="with" <?php echo $qrf === 'with' ? 'selected' : ''; ?>>With QR</option>
        <option value="without" <?php echo $qrf === 'without' ? 'selected' : ''; ?>>Without QR</option>
      </select>
    </div>
    <div class="col-12 d-flex gap-2">
      <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Search</button>
      <a href="?" class="btn btn-light">Reset</a>
    </div>
  </form>

  <?php if (!$rows): ?>
    <div class="cml-empty">No customers match your filters.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover cml-table bg-white">
        <thead class="table-light">
          <tr><th>Code</th><th>Customer</th><th>Address</th><th>Contact</th><th>Type</th><th>Status</th><th>QR Code</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr onclick="openCust(<?php echo $i; ?>)">
            <td><?php echo h($r['Code']); ?></td>
            <td><?php echo h($r['CustomerName']); ?></td>
            <td><?php echo h($r['Full']); ?></td>
            <td><?php echo h($r['ContactPerson']); ?><?php echo $r['ContactNo'] !== '' ? '<div class="text-muted" style="font-size:12px;">' . h($r['ContactNo']) . '</div>' : ''; ?></td>
            <td><?php echo h($r['CustomerType']); ?></td>
            <td><?php echo h($r['Status']); ?></td>
            <td>
              <?php if ((string)$r['QRCODE'] !== ''): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation(); openCust(<?php echo $i; ?>)" title="Preview QR">
                  <i class="bi bi-qr-code"></i> <?php echo h($r['QRCODE']); ?>
                </button>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="text-muted">Showing <?php echo $off + 1; ?>–<?php echo $off + count($rows); ?> of <?php echo number_format($total); ?></div>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(cml_url($baseQs, ['page' => $page - 1])); ?>">Prev</a>
        <span>Page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo h(cml_url($baseQs, ['page' => $page + 1])); ?>">Next</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<div id="cmlBackdrop" class="cml-backdrop" onclick="if (event.target === this) closeCust()">
  <div class="cml-modal">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <h5 class="mb-0" id="cmlName">Customer</h5>
      <button type="button" class="btn-close" onclick="closeCust()" aria-label="Close"></button>
    </div>
    <div id="cmlBody" style="font-size:13.5px;"></div>
    <div class="d-flex justify-content-end gap-2 mt-3">
      <?php if (!$view_only): ?><button type="button" class="btn btn-outline-primary" onclick="editCust()"><i class="bi bi-pencil-square"></i> Edit</button><?php endif; ?>
      <button type="button" id="cmlPrintQr" class="btn btn-outline-secondary d-none" onclick="printQr()"><i class="bi bi-printer"></i> Print QR</button>
      <button type="button" class="btn btn-light" onclick="closeCust()">Close</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/customer_form_modal.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const ROWS = <?php echo json_encode($jsRows, JSON_INVALID_UTF8_SUBSTITUTE); ?>;
let cur = null;
const CML_BASE = <?php echo json_encode(base_url()); ?>;

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function openCust(i) {
    const c = ROWS[i]; if (!c) return;
    cur = c;
    document.getElementById('cmlName').textContent = c.name;

    const rows = [
        ['ID', c.id], ['Code', c.code], ['Customer Name', c.name],
        ['Address', c.address], ['Barangay', c.brgy], ['Area', c.area], ['Province', c.prov],
        ['Contact Person', c.cper], ['Contact No.', c.cno], ['Customer Type', c.ctype], ['TIN', c.tin],
        ['Status', c.status], ['Remarks', c.rmk],
        ['Latitude', c.rlat], ['Longitude', c.rlng],
        ['Registered By (User ID)', c.uid > 0 ? c.uid : ''], ['Registered', c.dt],
        ['QR Code', c.qr],
    ];

    let html = '<div id="cmlPic" class="text-center mb-2"></div>' + rows.map(([l, v]) => `
        <div class="d-flex justify-content-between border-top py-1">
            <span class="text-muted">${esc(l)}</span>
<span class="text-end" style="max-width:65%;">${esc(v === null || v === undefined || v === '' ? '—' : v)}</span>
        </div>`).join('');

    if (c.fpath) {
        html += `<div class="d-flex justify-content-between border-top py-1">
            <span class="text-muted">File</span>
            <a href="${esc(CML_BASE + encodeURI(c.fpath))}" target="_blank" rel="noopener">View file</a></div>`;
    }
    if (c.lat !== null) {
        html += `<div class="d-flex justify-content-between border-top py-1">
            <span class="text-muted">Map</span>
            <a href="https://www.google.com/maps?q=${c.lat},${c.lng}" target="_blank" rel="noopener">${c.lat}, ${c.lng}</a></div>`;
    }
    if (c.qr) {
        html += `<div class="border-top pt-2 mt-2 text-center">
            <div class="text-muted mb-1" style="font-size:12px;">QR Code: ${esc(c.qr)}</div>
            <div id="cmlQrBox" style="display:inline-block;"></div></div>`;
    } else {
        html += '<div class="border-top pt-2 mt-2 text-muted text-center" style="font-size:12px;">No QR code tagged yet</div>';
    }
    document.getElementById('cmlBody').innerHTML = html;
    document.getElementById('cmlPrintQr').classList.toggle('d-none', !c.qr);
    if (c.qr) new QRCode(document.getElementById('cmlQrBox'), { text: c.qr, width: 200, height: 200 });

    document.getElementById('cmlBackdrop').classList.add('active');

    // Photo is fetched on demand so the list stays light
    const id = c.id;
    fetch(location.pathname + '?ajax=pic&id=' + id)
        .then(r => r.json())
        .then(d => {
            if (!cur || cur.id !== id || !d.pic) return;
            document.getElementById('cmlPic').innerHTML = `<img src="${d.pic}" alt="Customer photo" style="max-width:100%;max-height:160px;border-radius:8px;object-fit:cover;">`;
        })
        .catch(() => {});
}

function editCust() {
    if (!cur) return;
    const row = cur;
    closeCust();
    cfOpenEdit(row);
}

function closeCust() {
    document.getElementById('cmlBackdrop').classList.remove('active');
    cur = null;
}

function printQr() {
    if (!cur || !cur.qr) return;
    const cv = document.querySelector('#cmlQrBox canvas');
    const im = document.querySelector('#cmlQrBox img');
    const src = cv ? cv.toDataURL('image/png') : (im ? im.src : '');
    if (!src) return;
    const w = window.open('', '_blank', 'width=420,height=520');
    if (!w) return;
    w.document.write(`<html><head><title>QR ${esc(cur.qr)}</title></head>
        <body style="font-family:Arial,sans-serif;text-align:center;padding:24px;">
        <img src="${src}" style="width:260px;height:260px;">
        <h3 style="margin:8px 0 0;">${esc(cur.name)}</h3><div>${esc(cur.qr)}</div>
        <script>window.onload = function(){ window.print(); }<\/script></body></html>`);
    w.document.close();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCust(); });
</script>
</body>
</html>
