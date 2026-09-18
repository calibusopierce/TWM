<?php
// TWM/QR/qr_scan_log.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'qr_scan_log');

$view_only = rbac_is_view_only('qr_scan_log');

// ---- Employee dropdown: only employees who actually have scan logs ----
$empSql = "
    SELECT DISTINCT EmployeeName
    FROM dbo.View_QRScan_Log
    ORDER BY EmployeeName
";
$empStmt = sqlsrv_query($conn, $empSql);
if ($empStmt === false) {
    die('Employee list query failed: ' . print_r(sqlsrv_errors(), true));
}
$employees = [];
while ($row = sqlsrv_fetch_array($empStmt, SQLSRV_FETCH_ASSOC)) {
    $employees[] = $row;
}

// ---- Filters ----
$selectedEmployee = isset($_GET['employee']) ? trim($_GET['employee']) : '';
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : date('Y-m-d');
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : date('Y-m-d');

$logs = [];
$employeeName = '';

if ($selectedEmployee !== '') {
    $logSql = "
        SELECT
            [CustomerName],
            [Address],
            [Area],
            [EmployeeName],
            [DateTimeInput],
            [Longitude],
            [Latitude],
            [Remarks],
            [QRCODE],
            [QRPicture]
        FROM dbo.View_QRScan_Log
        WHERE EmployeeName = ?
          AND DateTimeInput >= ?
          AND DateTimeInput < DATEADD(DAY, 1, ?)
        ORDER BY DateTimeInput ASC
    ";
    $params = [$selectedEmployee, $dateFrom, $dateTo];
    $logStmt = sqlsrv_query($conn, $logSql, $params);
    if ($logStmt === false) {
        die('Log query failed: ' . print_r(sqlsrv_errors(), true));
    }

    $seq = 1;
    while ($row = sqlsrv_fetch_array($logStmt, SQLSRV_FETCH_ASSOC)) {
        $row['Seq'] = $seq++;
        if ($row['DateTimeInput'] instanceof DateTime) {
            $row['DateTimeInput'] = $row['DateTimeInput']->format('Y-m-d H:i:s');
        }
        // QRPicture is binary (confirmed PNG) — same pattern as customer_qr_ajax.php's View_Customer_With_QRCODE
        if (!empty($row['QRPicture'])) {
            $binary = is_resource($row['QRPicture']) ? stream_get_contents($row['QRPicture']) : $row['QRPicture'];
            $row['QRPicture'] = ($binary !== false && $binary !== '') ? 'data:image/png;base64,' . base64_encode($binary) : '';
        } else {
            $row['QRPicture'] = '';
        }
        $logs[] = $row;
        $employeeName = $row['EmployeeName'];
    }
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee QR Scan Log</title>
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/admin.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/topbar.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/responsive-patch.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
  .qsl-wrap { padding: 24px; }
  .qsl-filter-card { border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:20px; background:#fff; }
  .qsl-map { height: 460px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:20px; position:relative; z-index:0; }
  .qsl-table th, .qsl-table td { vertical-align: middle; font-size: 13.5px; }
  .qsl-seq-badge { display:inline-block; min-width:26px; padding:2px 6px; border-radius:999px; background:#1d4ed8; color:#fff; font-weight:600; text-align:center; }
  .qsl-empty { text-align:center; padding:40px; color:#64748b; }

  /* ── Image lightbox — full-size QR preview, same pattern as customer_qr.php ── */
  .qsl-lightbox-backdrop {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,.85);
      z-index: 4000; align-items: center; justify-content: center; padding: 30px;
      cursor: zoom-out;
  }
  .qsl-lightbox-backdrop.active { display: flex; }
  .qsl-lightbox-backdrop img {
      max-width: 90vw; max-height: 90vh; border-radius: 10px; background: #fff; padding: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,.5);
  }
  .qsl-lightbox-close {
      position: absolute; top: 20px; right: 24px; border: none; background: rgba(255,255,255,.15);
      width: 38px; height: 38px; border-radius: 50%; font-size: 22px; line-height: 1;
      color: #fff; cursor: pointer;
  }
  .qsl-lightbox-close:hover { background: rgba(255,255,255,.3); }
</style>
</head>
<body>
<?php $topbar_page = 'qr_scan_log'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="qsl-wrap">
  <div class="page-header">
    <h1 class="page-title">Employee QR Scan Log</h1>
    <?php if ($employeeName): ?>
      <p class="text-muted mb-0"><?php echo h($employeeName); ?> — <?php echo h($dateFrom); ?> to <?php echo h($dateTo); ?> (<?php echo count($logs); ?> scans)</p>
    <?php endif; ?>
  </div>

  <form method="get" class="qsl-filter-card row g-3 align-items-end">
    <div class="col-md-4">
      <label class="form-label">Employee</label>
      <select name="employee" class="form-select" required>
        <option value="">Select employee...</option>
        <?php foreach ($employees as $emp): ?>
          <option value="<?php echo h($emp['EmployeeName']); ?>" <?php echo ($selectedEmployee === (string)$emp['EmployeeName']) ? 'selected' : ''; ?>>
            <?php echo h($emp['EmployeeName']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">From</label>
      <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">To</label>
      <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
    </div>
    <div class="col-md-2">
      <button type="submit" class="btn btn-primary w-100">Filter</button>
    </div>
  </form>

  <?php if (!$logs): ?>
    <div class="qsl-empty">
      <?php echo $selectedEmployee ? 'No scan logs found for this employee in the selected range.' : 'Select an employee and date range to view scan logs.'; ?>
    </div>
  <?php else: ?>
    <div id="qslMap" class="qsl-map"></div>

    <div class="table-responsive">
      <table class="table table-bordered table-hover qsl-table bg-white">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Date/Time</th>
            <th>Customer</th>
            <th>Address</th>
            <th>Area</th>
            <th>Code</th>
            <th>Remarks</th>
            <th>Latitude</th>
            <th>Longitude</th>
            <th>QR Picture</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><span class="qsl-seq-badge" style="cursor:pointer;" onclick="qslGoToSeq(<?php echo (int)$log['Seq']; ?>)"><?php echo (int)$log['Seq']; ?></span></td>
              <td><?php echo h($log['DateTimeInput']); ?></td>
              <td><?php echo h($log['CustomerName']); ?></td>
              <td><?php echo h($log['Address']); ?></td>
              <td><?php echo h($log['Area']); ?></td>
              <td><?php echo h($log['QRCODE']); ?></td>
              <td><?php echo h($log['Remarks']); ?></td>
              <td><?php echo h($log['Latitude']); ?></td>
              <td><?php echo h($log['Longitude']); ?></td>
              <td>
                <?php if (!empty($log['QRPicture'])): ?>
                <img src="<?php echo h($log['QRPicture']); ?>" alt="QR" style="height:36px;border-radius:4px;cursor:zoom-in;"
                    onclick="qslOpenLightbox(this.src); event.stopPropagation();"
                    onerror="this.style.display='none'; this.insertAdjacentHTML('afterend','<span style=&quot;color:#94a3b8;&quot;>n/a</span>');">
                <?php else: ?>
                <span style="color:#94a3b8;">n/a</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div id="qsl-lightbox-backdrop" class="qsl-lightbox-backdrop" onclick="qslCloseLightbox()">
    <button type="button" class="qsl-lightbox-close" onclick="qslCloseLightbox(); event.stopPropagation();">&times;</button>
    <img id="qsl-lightbox-img" src="" alt="">
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script>
function qslOpenLightbox(src) {
    if (!src) return;
    document.getElementById('qsl-lightbox-img').src = src;
    document.getElementById('qsl-lightbox-backdrop').classList.add('active');
}
function qslCloseLightbox() {
    document.getElementById('qsl-lightbox-backdrop').classList.remove('active');
    document.getElementById('qsl-lightbox-img').src = '';
}
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') qslCloseLightbox();
});
<?php if ($logs): ?>
const qslPoints = <?php echo json_encode(array_map(function($l) {
    return [
        'seq' => $l['Seq'],
        'lat' => (float)$l['Latitude'],
        'lng' => (float)$l['Longitude'],
        'time' => $l['DateTimeInput'],
        'customer' => $l['CustomerName'],
        'address' => $l['Address'],
        'area' => $l['Area'],
        'code' => $l['QRCODE'],
        'remarks' => $l['Remarks'],
    ];
}, $logs)); ?>;

const qslMap = L.map('qslMap');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(qslMap);

// Group visits by customer so repeat scans at the same place share one pin
const qslGroups = {};
qslPoints.forEach(p => {
    if (!p.lat || !p.lng) return;
    const key = p.customer || `${p.lat},${p.lng}`;
    if (!qslGroups[key]) {
        qslGroups[key] = { customer: p.customer, lat: p.lat, lng: p.lng, visits: [] };
    }
    qslGroups[key].visits.push(p);
});

const qslMarkerByGroup = {};
const qslGroupBySeq = {};
const latlngs = [];

Object.keys(qslGroups).forEach(key => {
    const g = qslGroups[key];
    const ll = [g.lat, g.lng];
    latlngs.push(ll);

    const count = g.visits.length;
    const minSeq = Math.min(...g.visits.map(v => v.seq));
    const icon = L.divIcon({
        className: 'qsl-pin',
        html: `<div style="background:#1d4ed8;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);">${minSeq}</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14]
    });

    const visitsHtml = g.visits
        .sort((a, b) => a.seq - b.seq)
        .map(v => `<div style="padding:2px 0;border-top:1px solid #e2e8f0;"><strong>#${v.seq}</strong> — ${v.time}${v.remarks ? ' — ' + v.remarks : ''}</div>`)
        .join('');

    const marker = L.marker(ll, { icon }).addTo(qslMap)
        .bindPopup(`<strong>${g.customer}</strong><br>${g.visits[0].address ?? ''} (${g.visits[0].area ?? ''})<br>Code: ${g.visits[0].code ?? ''}<br>${count} visit${count > 1 ? 's' : ''}<div style="margin-top:4px;max-height:160px;overflow-y:auto;">${visitsHtml}</div>`);

    qslMarkerByGroup[key] = marker;
    g.visits.forEach(v => { qslGroupBySeq[v.seq] = key; });
});

// Route line still connects customer stops in visiting order (by first visit there)
const orderedGroups = Object.values(qslGroups).sort((a, b) => {
    const aMin = Math.min(...a.visits.map(v => v.seq));
    const bMin = Math.min(...b.visits.map(v => v.seq));
    return aMin - bMin;
});
const pathLatLngs = orderedGroups.map(g => [g.lat, g.lng]);
if (pathLatLngs.length > 1) {
    L.polyline(pathLatLngs, { color: '#1d4ed8', weight: 3, opacity: 0.7, dashArray: '6,6' }).addTo(qslMap);
}

if (latlngs.length) {
    qslMap.fitBounds(latlngs, { padding: [40, 40] });
} else {
    qslMap.setView([13.9, 121.9], 9); // fallback: South Luzon
}

// Clicking a row's # in the table flies to its pin and opens the popup
window.qslGoToSeq = function(seq) {
    const key = qslGroupBySeq[seq];
    const marker = qslMarkerByGroup[key];
    if (!marker) return;
    qslMap.flyTo(marker.getLatLng(), Math.max(qslMap.getZoom(), 17));
    marker.openPopup();
};
<?php endif; ?>
</script>
</body>
</html>