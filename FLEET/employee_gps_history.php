<?php
/**
 * employee_gps_history.php
 * TWM/FLEET — Employee GPS Ping History
 *
 * Standalone page: pick an employee + a date range, see every GPS ping logged
 * for them in dbo.Tbl_Employee_GPS_Location (via dbo.View_Employee_GPS) plotted
 * on a map, plus the raw log as a table. Pure internal SQL Server data —
 * no Cartrack API involved (that's vehicles only).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'employee_gps_history');

$topbar_page = 'employee_gps_history';

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// Resolve an employee Picture value into TWM/legacy portal URL candidates —
// mirrors the convention used in vehicle_status.php / employee-list.php.
function ft_resolve_picture_paths($rawPic) {
    $rawPic = trim((string)$rawPic);
    if ($rawPic === '') return ['', ''];
    $normPic = str_replace('\\', '/', $rawPic);
    $picFile = basename($normPic);
    if ($picFile === '') return ['', ''];
    $twmPic = strpos($normPic, 'employee_pics') !== false
        ? (str_starts_with($normPic, '/') ? $normPic : '/TWM/' . $normPic)
        : '/TWM/uploads/employee_pics/' . $picFile;
    $legacyPic = '/tradewellportal/uploads/' . $picFile;
    return [$twmPic, $legacyPic];
}

// --- Employee list for the selector (active employees only) ---
$employees = [];
$empListSql = "
    SELECT DISTINCT e.EmployeeID, e.FirstName, e.LastName, e.[Picture]
    FROM dbo.TBL_HREmployeeList e
    INNER JOIN dbo.View_Employee_GPS g ON g.EmployeeID = e.EmployeeID
    WHERE e.Active = 1
    ORDER BY e.LastName, e.FirstName
";
$empListStmt = sqlsrv_query($conn, $empListSql);
if ($empListStmt === false) {
    error_log('TBL_HREmployeeList query error: ' . print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($empListStmt, SQLSRV_FETCH_ASSOC)) {
        $employees[] = $row;
    }
}

// --- Selected filters ---
$EmployeeId = $_GET['EmployeeId'] ?? '';
$DateFrom   = $_GET['DateFrom'] ?? date('Y-m-d', strtotime('-7 days'));
$DateTo     = $_GET['DateTo'] ?? date('Y-m-d');

// --- Ping log for the selected employee + date range ---
$pings = [];
$queryError = null;

if ($EmployeeId !== '') {
    $sql = "
        SELECT EmployeeID, EmployeeName, Longitude, Latitude, DateTimeInput
        FROM dbo.View_Employee_GPS
        WHERE EmployeeID = ? AND DateTimeInput >= ? AND DateTimeInput <= ?
        ORDER BY DateTimeInput ASC
    ";
    $stmt = sqlsrv_query($conn, $sql, [$EmployeeId, $DateFrom . ' 00:00:00', $DateTo . ' 23:59:59']);
    if ($stmt === false) {
        $queryError = sqlsrv_errors();
        error_log('View_Employee_GPS history query error: ' . print_r($queryError, true));
    } else {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $lat = $row['Latitude']; $lng = $row['Longitude'];
            if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) continue;

            $rawDt = $row['DateTimeInput'] ?? null;
            $ts = $rawDt instanceof DateTime ? $rawDt->format('Y-m-d H:i:s') : (string)$rawDt;

            $pings[] = ['lat' => (float)$lat, 'lng' => (float)$lng, 'ts' => $ts];
        }
    }
}

$selectedEmployeeName = '';
$selectedEmployeePic = '';
$selectedEmployeePicLegacy = '';
$selectedEmployeeInitials = '';
foreach ($employees as $e) {
    if ((string)$e['EmployeeID'] === (string)$EmployeeId) {
        $selectedEmployeeName = trim($e['FirstName'] . ' ' . $e['LastName']);
        [$selectedEmployeePic, $selectedEmployeePicLegacy] = ft_resolve_picture_paths($e['Picture'] ?? '');
        $selectedEmployeeInitials = strtoupper(substr($e['FirstName'], 0, 1) . substr($e['LastName'], 0, 1));
        break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/logo.png') ?>">
    <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Employee GPS History</title>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body { font-family: 'IBM Plex Sans', sans-serif; background: #f4f5f7; color: #1a1d23; font-size: 14px; line-height: 1.5; }
.eh-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

.eh-header { display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 2px solid #e2e5ea; }
.eh-dept-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px; }
.eh-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
.eh-page-title span { color: #2563eb; }

.eh-toolbar {
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
    padding: 18px 24px; margin-bottom: 20px; display: flex; align-items: end;
    gap: 14px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.eh-field { display: flex; flex-direction: column; gap: 6px; }
.eh-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
.eh-field input, .eh-field select {
    height: 42px; padding: 0 14px; border: 1.5px solid #d1d5db; border-radius: 9px;
    font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
    background: #f9fafb; outline: none; min-width: 180px;
}
.eh-field input:focus, .eh-field select:focus { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.eh-btn {
    height: 42px; padding: 0 20px; border: none; border-radius: 9px; background: #2563eb; color: #fff;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer;
}
.eh-btn:hover { background: #1d4ed8; }

.eh-stats { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.eh-stat-card { background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px; padding: 16px 22px; min-width: 160px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.eh-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px; }
.eh-stat-value { font-size: 22px; font-weight: 700; color: #111827; }

.eh-map-wrap { position: relative; z-index: 0; margin-bottom: 20px; }
#eh-map { width: 100%; height: 520px; border-radius: 14px; border: 1.5px solid #e2e5ea; box-shadow: 0 1px 4px rgba(0,0,0,.04); }

.eh-panel { background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px; padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.eh-panel h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin-bottom: 12px; }
.eh-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.eh-table thead tr { text-align: left; color: #6b7280; border-bottom: 1px solid #e2e5ea; }
.eh-table th, .eh-table td { padding: 8px 6px; }
.eh-table tbody tr { border-bottom: 1px solid #f1f3f7; }
.eh-table td.mono { font-family: 'IBM Plex Mono', monospace; color: #6b7280; }
.eh-empty { font-size: 13px; color: #6b7280; padding: 20px 0; }
.eh-error { background: #fef2f2; border: 1.5px solid #fecaca; color: #b91c1c; border-radius: 12px; padding: 12px 18px; font-size: 13px; margin-bottom: 20px; }

.eh-gps-pin {
    width: 28px; height: 28px; border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
    background: #2563eb;
}
.eh-gps-pin-photo {
    width: 22px; height: 22px; border-radius: 50%; object-fit: cover;
    transform: rotate(45deg); border: 1px solid rgba(255,255,255,.8);
}
.eh-gps-pin-initials { transform: rotate(45deg); color: #fff; font-weight: 700; font-size: 11px; }
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="eh-page">

    <div class="eh-header">
        <div>
            <div class="eh-dept-label">HR &nbsp;· Employee GPS Log</div>
            <h1 class="eh-page-title">Employee <span>GPS History</span></h1>
        </div>
    </div>

    <form method="GET" class="eh-toolbar">
        <div class="eh-field">
            <label>Employee</label>
            <select name="EmployeeId">
                <option value="">— Select —</option>
                <?php foreach ($employees as $e): ?>
                    <option value="<?= h($e['EmployeeID']) ?>" <?= (string)$e['EmployeeID'] === (string)$EmployeeId ? 'selected' : '' ?>>
                        <?= h($e['LastName'] . ', ' . $e['FirstName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="eh-field">
            <label>From</label>
            <input type="date" name="DateFrom" value="<?= h($DateFrom) ?>">
        </div>
        <div class="eh-field">
            <label>To</label>
            <input type="date" name="DateTo" value="<?= h($DateTo) ?>">
        </div>
        <button type="submit" class="eh-btn">Apply</button>
    </form>

    <?php if ($queryError): ?>
        <div class="eh-error">Could not load ping history — check the error log for details.</div>
    <?php endif; ?>

    <?php if ($EmployeeId === ''): ?>
        <div class="eh-panel"><div class="eh-empty">Select an employee to see their GPS ping log.</div></div>
    <?php elseif (empty($pings)): ?>
        <div class="eh-panel"><div class="eh-empty">No pings logged for <?= h($selectedEmployeeName) ?> in this date range.</div></div>
    <?php else: ?>

        <div class="eh-stats">
            <div class="eh-stat-card">
                <div class="eh-stat-label">Total Pings</div>
                <div class="eh-stat-value"><?= count($pings) ?></div>
            </div>
            <div class="eh-stat-card">
                <div class="eh-stat-label">First Ping</div>
                <div class="eh-stat-value" style="font-size:15px;"><?= h($pings[0]['ts']) ?></div>
            </div>
            <div class="eh-stat-card">
                <div class="eh-stat-label">Last Ping</div>
                <div class="eh-stat-value" style="font-size:15px;"><?= h(end($pings)['ts']) ?></div>
            </div>
        </div>

        <div class="eh-map-wrap">
            <div id="eh-map"></div>
        </div>

        <div class="eh-panel">
            <h4><?= h($selectedEmployeeName) ?> — Ping Log (<?= count($pings) ?>)</h4>
            <div style="max-height:400px;overflow-y:auto;">
                <table class="eh-table">
                    <thead><tr><th>Timestamp</th><th>Latitude</th><th>Longitude</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($pings) as $p): ?>
                        <tr>
                            <td class="mono"><?= h($p['ts']) ?></td>
                            <td class="mono"><?= h($p['lat']) ?></td>
                            <td class="mono"><?= h($p['lng']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>
</div>

<script>
const ehPings = <?= json_encode($pings, JSON_UNESCAPED_UNICODE) ?>;
const ehEmployeeName = <?= json_encode($selectedEmployeeName) ?>;
const ehEmployeePic = <?= json_encode($selectedEmployeePic) ?>;
const ehEmployeePicLegacy = <?= json_encode($selectedEmployeePicLegacy) ?>;
const ehEmployeeInitials = <?= json_encode($selectedEmployeeInitials ?: '?') ?>;

document.addEventListener('DOMContentLoaded', () => {
    if (!ehPings.length) return;

    const map = L.map('eh-map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    const bounds = [];

    // Connecting line showing order of movement
    const trailLatLngs = ehPings.map(p => [p.lat, p.lng]);
    L.polyline(trailLatLngs, { color: '#9ca3af', weight: 2, opacity: 0.6, dashArray: '4,4' }).addTo(map);

    // Build the employee pin once — photo if we have one, initials fallback,
    // same resolution chain as the fleet map (TWM path -> legacy path -> initials).
    let ehPinInner;
    if (ehEmployeePic) {
        ehPinInner = `<img src="${ehEmployeePic}" class="eh-gps-pin-photo" alt=""
                           data-legacy="${ehEmployeePicLegacy}" data-initials="${ehEmployeeInitials}"
                           onerror="(function(img){
                               var leg = img.getAttribute('data-legacy');
                               if (leg && !img.getAttribute('data-legacy-tried')) {
                                   img.setAttribute('data-legacy-tried','1');
                                   img.src = leg;
                               } else {
                                   var span = document.createElement('span');
                                   span.className = 'eh-gps-pin-initials';
                                   span.textContent = img.getAttribute('data-initials') || '?';
                                   img.replaceWith(span);
                               }
                           })(this)">`;
    } else {
        ehPinInner = `<span class="eh-gps-pin-initials">${ehEmployeeInitials}</span>`;
    }
    const ehPinIcon = L.divIcon({
        className: '',
        html: `<div class="eh-gps-pin">${ehPinInner}</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 28],
        popupAnchor: [0, -28]
    });

    // One pin per ping
    ehPings.forEach(p => {
        L.marker([p.lat, p.lng], { icon: ehPinIcon })
            .bindPopup(`<b>${ehEmployeeName}</b><br>${p.ts}`).addTo(map);
        bounds.push([p.lat, p.lng]);
    });

    if (bounds.length) map.fitBounds(bounds, { padding: [30, 30] });
});
</script>
</body>
</html>
