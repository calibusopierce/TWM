<?php
/**
 * gps_location_map.php
 * TWM — Employee, Customer & Vehicle Map
 * (dbo.View_GPS_Location + Cartrack Fleet API)
 *
 * Standalone page mirroring the Map tab merged into vehicle_status.php: same
 * department color-coding, employee photo pins (with legacy-path/initials
 * fallback), popup avatar, and Last Update (DateTimeInput) field for
 * employees/customers — PLUS the live Cartrack vehicle layer (truck pins,
 * same solid green #15803d) and the shared TWM topbar/page shell.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
// cartrack_client.php lives alongside vehicle_status.php in TWM/FLEET, not here in
// TWM/SALES — reference it there explicitly rather than relative to this file.
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/FLEET/cartrack_client.php';

auth_check();
// NOTE: swap 'gps_location_map' for whatever RBAC feature key this module should
// actually be gated under.
rbac_gate($pdo, 'gps_location_map');

$topbar_page = 'gps_location_map';

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// Resolve an employee Picture value into TWM/legacy portal URL candidates —
// mirrors the dual-path + onerror-fallback convention used in employee-list.php
// and vehicle_status.php's Map tab.
// TWM stores:    uploads/employee_pics/filename.jpg  (forward slash, subdirectory)
// Legacy stores: uploads\filename.jpg                (backslash, no subdirectory)
function gm_resolve_picture_paths($rawPic) {
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

// Pin color coding by Department/principal — same per-company assignments as
// vehicle_status.php's Map tab. Falls back to a category default (employee/
// customer/other) when Department is blank or doesn't match a known key.
function gm_department_color($dept) {
    static $map = [
        'MONDE'       => '#dc2626', // red
        'CENTURY'     => '#2563eb', // blue
        'NUTRIASIA'   => '#16a34a', // green
        'SILVER SWAN' => '#16a34a', // green
        'MULTILINES'  => '#ca8a04', // yellow
    ];
    $key = strtoupper(trim((string)$dept));
    return $map[$key] ?? null;
}

// --- Employee/Customer/Other GPS locations (dbo.View_GPS_Location) ---
$locations = [];
$noCoordCount = 0;
$correctedCount = 0;

$sql = "
    SELECT
        g.[Type], g.[Department], g.[Code], g.[Name], g.[Longitude], g.[Latitude], g.[DateTimeInput],
        e.[Picture]   AS EmployeePicture,
        e.[FirstName] AS EmployeeFirstName,
        e.[LastName]  AS EmployeeLastName
    FROM [dbo].[View_GPS_Location] g
    LEFT JOIN [dbo].[TBL_HREmployeeList] e
        ON UPPER(LTRIM(RTRIM(ISNULL(g.[Type], '')))) = 'EMPLOYEE'
       AND LTRIM(RTRIM(CONVERT(VARCHAR(50), g.[Code]))) = LTRIM(RTRIM(CONVERT(VARCHAR(50), e.[EmployeeID])))
";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    error_log('View_GPS_Location query error: ' . print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $typeRaw = strtoupper(trim((string)($row['Type'] ?? '')));

        if ($typeRaw === 'EMPLOYEE') {
            $category = 'employee';
        } elseif ($typeRaw === 'CUSTOMER') {
            $category = 'customer';
        } else {
            $category = 'other'; // unknown/future Type values from the view fall back here
        }

        $lat = $row['Latitude'];
        $lng = $row['Longitude'];

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            $noCoordCount++;
            continue; // can't plot without coordinates
        }

        $lat = (float)$lat;
        $lng = (float)$lng;
        $corrected = false;

        // Defensive stopgap: some source rows have Latitude/Longitude swapped.
        // A real latitude can never exceed ±90, so if it does but swapping the two
        // would land within valid ranges, auto-correct and flag it.
        if (abs($lat) > 90 && abs($lng) <= 90) {
            [$lat, $lng] = [$lng, $lat];
            $corrected = true;
            $correctedCount++;
        }

        $empPic = '';
        $empPicLegacy = '';
        $empInitials = '';
        if ($category === 'employee') {
            [$empPic, $empPicLegacy] = gm_resolve_picture_paths($row['EmployeePicture'] ?? '');
            $fn = trim((string)($row['EmployeeFirstName'] ?? ''));
            $ln = trim((string)($row['EmployeeLastName'] ?? ''));
            $empInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));
        }

        $categoryDefault = $category === 'employee' ? '#2563eb' : ($category === 'customer' ? '#d97706' : '#6b7280');
        $department = trim((string)($row['Department'] ?? ''));
        $pinColor = gm_department_color($department) ?? $categoryDefault;

        // DateTimeInput as "Last Update" — mirrors the 24hr staleness threshold
        // used for vehicle pins on vehicle_status.php's Map tab.
        $lastUpdate = '';
        $lastUpdateTs = 0;
        $rawDt = $row['DateTimeInput'] ?? null;
        if ($rawDt instanceof DateTime) {
            $lastUpdateTs = $rawDt->getTimestamp();
            $lastUpdate = $rawDt->format('M j, Y g:i A');
        } elseif (!empty($rawDt)) {
            $lastUpdateTs = strtotime((string)$rawDt) ?: 0;
            $lastUpdate = $lastUpdateTs ? date('M j, Y g:i A', $lastUpdateTs) : (string)$rawDt;
        }
        $isStale = $lastUpdateTs > 0 && $lastUpdateTs < (time() - 86400);

        $locations[] = [
            'category'   => $category,
            'type'       => $row['Type'] ?? '',
            'department' => $department,
            'code'       => $row['Code'] ?? '',
            'name'       => $row['Name'] ?? '',
            'lat'        => $lat,
            'lng'        => $lng,
            'corrected'  => $corrected,
            'pic'        => $empPic,
            'picLegacy'  => $empPicLegacy,
            'initials'   => $empInitials,
            'pinColor'   => $pinColor,
            'lastUpdate' => $lastUpdate,
            'isStale'    => $isStale,
        ];
    }
    sqlsrv_free_stmt($stmt);
}

$EmployeeCount = count(array_filter($locations, fn($l) => $l['category'] === 'employee'));
$CustomerCount = count(array_filter($locations, fn($l) => $l['category'] === 'customer'));
$OtherCount    = count(array_filter($locations, fn($l) => $l['category'] === 'other'));

// --- Vehicles (Cartrack Fleet API) — same source/classification as vehicle_status.php ---
$vehicleStatus = cartrack_get('/vehicles/status');
if (isset($vehicleStatus['error'])) {
    error_log('Cartrack API error [' . $vehicleStatus['code'] . ']: ' . $vehicleStatus['raw']);
}
$vehicleApiError = isset($vehicleStatus['error']);
$vehicles = $vehicleApiError ? [] : ($vehicleStatus['data'] ?? []);

foreach ($vehicles as &$v) {
    $moving = ($v['speed'] ?? 0) > 0;
    $ignition = $v['ignition'] ?? false;
    $v['_statusLabel'] = $moving ? 'Moving' : ($ignition ? 'Idle (Engine On)' : 'Parked');
    $v['_fuelPct'] = $v['fuel']['precentage_left'] ?? null; // Cartrack's own field-name typo, not ours
    $v['_position'] = $v['location']['position_description'] ?? '';
    $v['_lastUpdate'] = $v['location']['updated'] ?? $v['event_ts'] ?? '';
    $v['_lastUpdateTs'] = strtotime($v['_lastUpdate'] ?? '') ?: 0;
    $v['_isStale'] = $v['_lastUpdateTs'] > 0 && $v['_lastUpdateTs'] < (time() - 86400); // 24hr threshold
    $v['_geofenceIds'] = $v['location']['geofence_ids'] ?? [];
}
unset($v);

$VehicleCount = count($vehicles);
$TotalCount   = $VehicleCount + count($locations);
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Employee, Customer &amp; Vehicle Map</title>

<style>
*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: #f4f5f7;
    color: #1a1d23;
    font-size: 14px;
    line-height: 1.5;
}

.gm-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

/* ── Page Header ──────────────────────────────── */
.gm-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px;
    border-bottom: 2px solid #e2e5ea;
}
.gm-dept-label {
    font-size: 12px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px;
}
.gm-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
.gm-page-title span { color: #2563eb; }
.gm-subtitle { color: #6b7280; font-size: 13px; margin: 4px 0 0; }

/* ── Stat Cards ───────────────────────────────── */
.gm-stats { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
.gm-stat-card {
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
    padding: 16px 22px; min-width: 150px; box-shadow: 0 1px 4px rgba(0,0,0,.04);
    display: flex; flex-direction: column;
}
.gm-stat-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px;
}
.gm-stat-value { font-size: 22px; font-weight: 700; color: #111827; }
.gm-stat-vehicle .gm-stat-value { color: #15803d; }
.gm-stat-employee .gm-stat-value { color: #2563eb; }
.gm-stat-customer .gm-stat-value { color: #d97706; }
.gm-stat-warn .gm-stat-value { color: #b91c1c; }

/* ── Toolbar / Filter Card ─────────────────────── */
.gm-toolbar {
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
    padding: 18px 24px; margin-bottom: 20px; display: flex; align-items: center;
    gap: 18px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.gm-search {
    max-width: 320px; height: 42px; padding: 0 14px; border: 1.5px solid #d1d5db;
    border-radius: 9px; font-family: 'IBM Plex Mono', monospace; font-size: 13px;
    color: #111827; background: #f9fafb; outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.gm-search:focus { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.gm-toggle-group { display: flex; gap: 16px; flex-wrap: wrap; }
.gm-toggle {
    display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; user-select: none; color: #374151;
}
.gm-toggle-vehicle i { color: #15803d; }
.gm-toggle-employee i { color: #2563eb; }
.gm-toggle-customer i { color: #d97706; }
.gm-toggle-other i { color: #6b7280; }

.gm-map-wrap { position: relative; z-index: 0; }
.gm-view-toggle { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 9px; }
.gm-view-toggle button {
    border: none; background: transparent; padding: 7px 16px; border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; font-weight: 600;
    color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, color .15s;
}
.gm-view-toggle button.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.gm-view-toggle button:hover:not(.active) { color: #374151; }
.gm-map-controls { position: absolute; top: 12px; right: 12px; z-index: 1000; }
#gm-map {
    width: 100%; height: 640px; border-radius: 14px; border: 1.5px solid #e2e5ea;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}

.gm-error {
    background: #fef2f2; border: 1.5px solid #fecaca; color: #b91c1c;
    border-radius: 12px; padding: 12px 18px; font-size: 13px; margin-bottom: 20px;
    font-weight: 500;
}

/* ── Vehicle pins (Cartrack), same teardrop style as vehicle_status.php ── */
.gm-vehicle-pin {
    width: 34px; height: 34px; border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    display: flex; align-items: center; justify-content: center;
    border: 3px solid #fff; background: #15803d; box-shadow: 0 2px 6px rgba(0,0,0,.35);
}
.gm-vehicle-pin i { transform: rotate(45deg); color: #fff; font-size: 15px; }
.gm-vehicle-pin.gm-pin-stale { opacity: 0.5; }
.gm-pin-label {
    font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;
    color: #111827; background: rgba(255,255,255,.9); padding: 1px 5px;
    border-radius: 4px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,.2);
}

/* ── Employee/Customer/Other pins ── */
.gm-pin {
    width: 30px; height: 30px; border-radius: 50% 50% 50% 0;
    transform: rotate(-45deg);
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
}
/* Pin fill color is set inline per-marker (department color-coding), not by category class. */
.gm-pin i { transform: rotate(45deg); color: #fff; font-size: 14px; }
.gm-pin-corrected { outline: 2px dashed #b91c1c; outline-offset: 2px; }

/* Employee photo shown directly on the pin (not just the popup) */
.gm-pin-photo {
    width: 24px; height: 24px; border-radius: 50%; object-fit: cover;
    transform: rotate(45deg); border: 1px solid rgba(255,255,255,.8);
}
.gm-pin-initials { transform: rotate(45deg); color: #fff; font-weight: 700; font-size: 11px; }

/* marker cluster bubbles (employees/customers/other only — vehicles aren't clustered) */
.gm-cluster {
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; color: #fff; font-weight: 700; font-size: 13px;
    border: 3px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
}
.gm-cluster-employee { background: rgba(37, 99, 235, .85); }
.gm-cluster-customer { background: rgba(217, 119, 6, .85); }
.gm-cluster-other    { background: rgba(107, 114, 128, .85); }

/* ── Legend, floating bottom-left over the map ── */
.gm-legend {
    position: absolute; bottom: 12px; left: 12px; z-index: 1000;
    background: #fff; border: 1.5px solid #e2e5ea; border-radius: 12px;
    padding: 10px 14px; box-shadow: 0 1px 4px rgba(0,0,0,.08);
    display: flex; flex-direction: column; gap: 5px;
    font-family: 'IBM Plex Sans', sans-serif;
}
.gm-legend-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 2px;
}
.gm-legend-item {
    display: flex; align-items: center; gap: 7px; font-size: 11px;
    color: #374151; white-space: nowrap;
}
.gm-legend-item .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

.gm-map-popup { font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; min-width: 200px; }
.gm-popup-title { font-weight: 700; margin-bottom: 4px; color: #111827; }
.gm-popup-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.gm-popup-head .gm-popup-title { margin-bottom: 0; }
.gm-popup-avatar {
    width: 38px; height: 38px; border-radius: 50%; object-fit: cover;
    border: 2px solid #e2e5ea; flex-shrink: 0; display: block;
}
.gm-popup-avatar-initials {
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 13px; background: #2563eb;
}
.gm-popup-row { display: flex; justify-content: space-between; gap: 12px; padding: 1px 0; }
.gm-popup-row span:first-child { color: #6b7280; }
.gm-popup-row span:last-child { font-family: 'IBM Plex Mono', monospace; color: #111827; }
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="gm-page">

    <!-- ── Page Header ───────────────────────────────── -->
    <div class="gm-header">
        <div>
            <div class="gm-dept-label">Fleet &nbsp;· Live Location Tracking</div>
            <h1 class="gm-page-title"><i class="bi bi-geo-alt-fill"></i> Employee, Customer &amp; <span>Vehicle Map</span></h1>
            <p class="gm-subtitle">Live positions from View_GPS_Location + Cartrack Fleet API</p>
        </div>
    </div>

    <?php if ($vehicleApiError): ?>
    <div class="gm-error">
        Vehicle data is currently unavailable (Cartrack API error <?= h($vehicleStatus['code'] ?? '') ?>).
        Employee/Customer positions below are unaffected.
    </div>
    <?php endif; ?>

    <div class="gm-stats">
        <div class="gm-stat-card gm-stat-vehicle">
            <div class="gm-stat-label">Vehicles</div>
            <div class="gm-stat-value"><?= number_format($VehicleCount) ?></div>
        </div>
        <div class="gm-stat-card gm-stat-employee">
            <div class="gm-stat-label">Employees</div>
            <div class="gm-stat-value"><?= number_format($EmployeeCount) ?></div>
        </div>
        <div class="gm-stat-card gm-stat-customer">
            <div class="gm-stat-label">Customers</div>
            <div class="gm-stat-value"><?= number_format($CustomerCount) ?></div>
        </div>
        <?php if ($OtherCount > 0): ?>
        <div class="gm-stat-card">
            <div class="gm-stat-label">Other</div>
            <div class="gm-stat-value"><?= number_format($OtherCount) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($noCoordCount > 0): ?>
        <div class="gm-stat-card gm-stat-warn">
            <div class="gm-stat-label">Missing Coordinates</div>
            <div class="gm-stat-value"><?= number_format($noCoordCount) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($correctedCount > 0): ?>
        <div class="gm-stat-card gm-stat-warn">
            <div class="gm-stat-label">Lat/Lng Auto-Corrected</div>
            <div class="gm-stat-value"><?= number_format($correctedCount) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="gm-toolbar">
        <input type="text" id="gm-search" class="gm-search" placeholder="Search by name, plate, or code...">
        <div class="gm-toggle-group">
            <label class="gm-toggle gm-toggle-vehicle">
                <input type="checkbox" id="gm-toggle-vehicle" checked> <i class="bi bi-truck"></i> Vehicles
            </label>
            <label class="gm-toggle gm-toggle-employee">
                <input type="checkbox" id="gm-toggle-employee" checked> <i class="bi bi-person-fill"></i> Employees
            </label>
            <label class="gm-toggle gm-toggle-customer">
                <input type="checkbox" id="gm-toggle-customer" checked> <i class="bi bi-shop"></i> Customers
            </label>
            <?php if ($OtherCount > 0): ?>
            <label class="gm-toggle gm-toggle-other">
                <input type="checkbox" id="gm-toggle-other" checked> <i class="bi bi-geo-fill"></i> Other
            </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="gm-map-wrap">
        <div class="gm-map-controls gm-view-toggle">
            <button type="button" id="gm-btn-street" class="active" onclick="gmSwitchBasemap('street')">
                <i class="bi bi-map"></i> Street
            </button>
            <button type="button" id="gm-btn-satellite" onclick="gmSwitchBasemap('satellite')">
                <i class="bi bi-globe-americas"></i> Satellite
            </button>
        </div>
        <div class="gm-legend">
            <div class="gm-legend-title">Pin Color</div>
            <div class="gm-legend-item"><span class="dot" style="background:#15803d;"></span> Vehicle</div>
            <?php if (($EmployeeCount + $CustomerCount) > 0): ?>
            <div class="gm-legend-title" style="margin-top:4px;">Department</div>
            <div class="gm-legend-item"><span class="dot" style="background:#dc2626;"></span> Monde</div>
            <div class="gm-legend-item"><span class="dot" style="background:#2563eb;"></span> Century</div>
            <div class="gm-legend-item"><span class="dot" style="background:#16a34a;"></span> NutriAsia / Silver Swan</div>
            <div class="gm-legend-item"><span class="dot" style="background:#ca8a04;"></span> Multilines</div>
            <?php endif; ?>
        </div>
        <div id="gm-map"></div>
    </div>
</div>
</div>

<script>
// --- Vehicle data for the map (Cartrack) ---
const gmVehicles = [
    <?php foreach ($vehicles as $v):
        $lat = $v['location']['latitude'] ?? null;
        $lng = $v['location']['longitude'] ?? null;
        if ($lat === null || $lng === null) continue;
    ?>
    {
        plate: <?= json_encode($v['registration'] ?? ('#' . ($v['vehicle_id'] ?? '?'))) ?>,
        lat: <?= json_encode($lat) ?>,
        lng: <?= json_encode($lng) ?>,
        status: <?= json_encode($v['_statusLabel']) ?>,
        fuel: <?= json_encode($v['_fuelPct']) ?>,
        position: <?= json_encode($v['_position']) ?>,
        lastUpdate: <?= json_encode($v['_lastUpdate']) ?>,
        isStale: <?= json_encode($v['_isStale']) ?>,
        geofenceCount: <?= json_encode(count($v['_geofenceIds'])) ?>
    },
    <?php endforeach; ?>
];

// --- Employee/Customer/Other GPS data for the map ---
const gmLocations = <?= json_encode($locations, JSON_UNESCAPED_UNICODE) ?>;

const GM_ICONS = {
    employee: { bi: 'bi-person-fill', fallback: '#2563eb' },
    customer: { bi: 'bi-shop',        fallback: '#d97706' },
    other:    { bi: 'bi-geo-fill',    fallback: '#6b7280' }
};

// Builds the GPS pin itself. Employees show their photo right on the pin (falling
// back to the legacy path, then initials, same chain as the popup avatar); other
// categories show the category icon. Fill color is the department color-coding
// (or the category default when Department is blank/unmapped) — set inline per marker.
function gmBuildIcon(loc) {
    const cfg = GM_ICONS[loc.category] || GM_ICONS.other;
    const cls = loc.corrected ? 'gm-pin gm-pin-corrected' : 'gm-pin';
    const bg = loc.pinColor || cfg.fallback;

    let inner;
    if (loc.category === 'employee') {
        const initials = (loc.initials || '?').replace(/"/g, '&quot;');
        if (loc.pic) {
            const src = loc.pic.replace(/"/g, '&quot;');
            const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
            inner = `<img src="${src}" class="gm-pin-photo" alt=""
                         data-legacy="${legacy}" data-initials="${initials}"
                         onerror="(function(img){
                             var leg = img.getAttribute('data-legacy');
                             if (leg && !img.getAttribute('data-legacy-tried')) {
                                 img.setAttribute('data-legacy-tried','1');
                                 img.src = leg;
                             } else {
                                 var span = document.createElement('span');
                                 span.className = 'gm-pin-initials';
                                 span.textContent = img.getAttribute('data-initials') || '?';
                                 img.replaceWith(span);
                             }
                         })(this)">`;
        } else {
            inner = `<span class="gm-pin-initials">${initials}</span>`;
        }
    } else {
        inner = `<i class="bi ${cfg.bi}"></i>`;
    }

    return L.divIcon({
        className: '',
        html: `<div class="${cls}" style="background:${bg};">${inner}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 30],
        popupAnchor: [0, -30]
    });
}

// Employee avatar for the popup — TWM path first, legacy portal path on error,
// initials bubble if both fail (or there's no picture on file at all). Mirrors
// the resolution chain used in employee-list.php and vehicle_status.php.
function gmAvatarHtml(loc) {
    if (loc.category !== 'employee') return '';
    const initials = (loc.initials || '?').replace(/"/g, '&quot;');
    if (!loc.pic) {
        return `<div class="gm-popup-avatar gm-popup-avatar-initials">${initials}</div>`;
    }
    const src = loc.pic.replace(/"/g, '&quot;');
    const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
    return `<img src="${src}" class="gm-popup-avatar" alt=""
                 data-legacy="${legacy}" data-initials="${initials}"
                 onerror="(function(img){
                     var leg = img.getAttribute('data-legacy');
                     if (leg && !img.getAttribute('data-legacy-tried')) {
                         img.setAttribute('data-legacy-tried','1');
                         img.src = leg;
                     } else {
                         var d = document.createElement('div');
                         d.className = 'gm-popup-avatar gm-popup-avatar-initials';
                         d.textContent = img.getAttribute('data-initials') || '?';
                         img.replaceWith(d);
                     }
                 })(this)">`;
}

let gmMap = null;
let gmStreetLayer = null;
let gmSatelliteLayer = null;

function gmSwitchBasemap(type) {
    if (!gmMap) return;
    const btnStreet = document.getElementById('gm-btn-street');
    const btnSatellite = document.getElementById('gm-btn-satellite');

    if (type === 'satellite') {
        gmMap.removeLayer(gmStreetLayer);
        gmSatelliteLayer.addTo(gmMap);
        btnStreet.classList.remove('active');
        btnSatellite.classList.add('active');
    } else {
        gmMap.removeLayer(gmSatelliteLayer);
        gmStreetLayer.addTo(gmMap);
        btnSatellite.classList.remove('active');
        btnStreet.classList.add('active');
    }
}
let gmVehicleLayer = null; // plain L.layerGroup — vehicles aren't clustered, same as vehicle_status.php
let gmMarkers = []; // { marker, category, name, code } — GPS locations only (search targets these)
let gmClusterGroups = {}; // category -> L.markerClusterGroup

// Cluster bubble color follows the GPS layer's category (vehicles use their own plain layer).
function gmClusterIconCreate(category) {
    return function (cluster) {
        const count = cluster.getChildCount();
        const size = count < 10 ? 34 : (count < 50 ? 42 : 50);
        return L.divIcon({
            html: `<div class="gm-cluster gm-cluster-${category}" style="width:${size}px;height:${size}px;">${count}</div>`,
            className: '',
            iconSize: [size, size]
        });
    };
}

function gmInitMap() {
    let centerLat = 13.9, centerLng = 121.6; // fallback: Quezon province
    const centerPoints = [...gmVehicles, ...gmLocations];
    if (centerPoints.length) {
        centerLat = centerPoints.reduce((s, p) => s + p.lat, 0) / centerPoints.length;
        centerLng = centerPoints.reduce((s, p) => s + p.lng, 0) / centerPoints.length;
    }

    gmMap = L.map('gm-map').setView([centerLat, centerLng], 10);

    gmStreetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    });

    gmSatelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        maxZoom: 19
    });

    gmStreetLayer.addTo(gmMap); // default basemap

    const bounds = [];

    // --- Vehicle layer (truck pins, same solid green as vehicle_status.php) ---
    gmVehicleLayer = L.layerGroup();
    gmVehicles.forEach(v => {
        const icon = L.divIcon({
            className: '',
            html: `
                <div style="text-align:center;">
                    <div class="gm-vehicle-pin ${v.isStale ? 'gm-pin-stale' : ''}">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="gm-pin-label">${v.plate}</div>
                </div>
            `,
            iconSize: [34, 48],
            iconAnchor: [17, 40],
            popupAnchor: [0, -40]
        });

        const marker = L.marker([v.lat, v.lng], { icon });
        const fuelDisplay = v.fuel !== null ? v.fuel + '%' : '—';
        const staleTag = v.isStale ? ' <span style="color:#b91c1c;font-weight:600;">(Stale)</span>' : '';

        marker.bindPopup(`
            <div class="gm-map-popup">
                <div class="gm-popup-title">${v.plate}${staleTag}</div>
                <div class="gm-popup-row"><span>Status</span><span>${v.status}</span></div>
                <div class="gm-popup-row"><span>Fuel</span><span>${fuelDisplay}</span></div>
                <div class="gm-popup-row"><span>Geofences</span><span>${v.geofenceCount}</span></div>
                <div class="gm-popup-row"><span>Last Update</span><span${v.isStale ? ' style="color:#b91c1c;"' : ''}>${v.lastUpdate || '—'}</span></div>
                <div style="margin-top:6px;color:#6b7280;font-size:11px;">${v.position || ''}</div>
            </div>
        `);

        marker.addTo(gmVehicleLayer);
        bounds.push([v.lat, v.lng]);
    });
    gmVehicleLayer.addTo(gmMap);

    // --- Employee / Customer / Other GPS layers (one cluster group per category,
    //     so each toggle checkbox just shows/hides its whole layer) ---
    ['employee', 'customer', 'other'].forEach(cat => {
        gmClusterGroups[cat] = L.markerClusterGroup({
            iconCreateFunction: gmClusterIconCreate(cat),
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            maxClusterRadius: 50
        });
        gmMap.addLayer(gmClusterGroups[cat]);
    });

    gmLocations.forEach(loc => {
        const marker = L.marker([loc.lat, loc.lng], { icon: gmBuildIcon(loc) });
        const avatarHtml = gmAvatarHtml(loc);
        marker.bindPopup(`
            <div class="gm-map-popup">
                <div class="gm-popup-head">
                    ${avatarHtml}
                    <div class="gm-popup-title">${loc.name || '(no name)'}</div>
                </div>
                <div class="gm-popup-row"><span>Type</span><span>${loc.type || '—'}</span></div>
                <div class="gm-popup-row"><span>Department</span><span>${loc.department || '—'}</span></div>
                <div class="gm-popup-row"><span>Code</span><span>${loc.code || '—'}</span></div>
                <div class="gm-popup-row"><span>Last Update</span><span${loc.isStale ? ' style="color:#b91c1c;"' : ''}>${loc.lastUpdate || '—'}</span></div>
                ${loc.corrected ? '<div style="color:#b91c1c;margin-top:4px;">⚠ Lat/Lng appeared swapped in source data — auto-corrected for display</div>' : ''}
            </div>
        `);
        const group = gmClusterGroups[loc.category] || gmClusterGroups.other;
        group.addLayer(marker);
        gmMarkers.push({ marker, category: loc.category, name: (loc.name || '').toLowerCase(), code: (loc.code || '').toLowerCase() });
        bounds.push([loc.lat, loc.lng]);
    });

    if (bounds.length) gmMap.fitBounds(bounds, { padding: [30, 30] });
}

function gmApplyFilters() {
    const q = document.getElementById('gm-search').value.trim().toLowerCase();
    const showVehicle = document.getElementById('gm-toggle-vehicle').checked;
    const showEmployee = document.getElementById('gm-toggle-employee').checked;
    const showCustomer = document.getElementById('gm-toggle-customer').checked;
    const otherToggle = document.getElementById('gm-toggle-other');
    const showOther = otherToggle ? otherToggle.checked : true;

    // Vehicle layer: simple show/hide, no search filtering (mirrors vehicle_status.php).
    if (gmVehicleLayer) {
        if (showVehicle) {
            if (!gmMap.hasLayer(gmVehicleLayer)) gmMap.addLayer(gmVehicleLayer);
        } else {
            if (gmMap.hasLayer(gmVehicleLayer)) gmMap.removeLayer(gmVehicleLayer);
        }
    }

    const categoryShown = { employee: showEmployee, customer: showCustomer, other: showOther };

    if (q === '') {
        // No search — just toggle whole cluster layers on/off (cheap).
        Object.keys(gmClusterGroups).forEach(cat => {
            const group = gmClusterGroups[cat];
            if (categoryShown[cat]) {
                if (!gmMap.hasLayer(group)) gmMap.addLayer(group);
            } else {
                if (gmMap.hasLayer(group)) gmMap.removeLayer(group);
            }
        });
        return;
    }

    // Searching — rebuild each cluster group's contents from scratch so
    // clusters only ever bubble matching markers.
    Object.keys(gmClusterGroups).forEach(cat => {
        const group = gmClusterGroups[cat];
        group.clearLayers();
        if (!categoryShown[cat]) {
            if (gmMap.hasLayer(group)) gmMap.removeLayer(group);
            return;
        }
        if (!gmMap.hasLayer(group)) gmMap.addLayer(group);
        gmMarkers
            .filter(m => m.category === cat && (m.name.includes(q) || m.code.includes(q)))
            .forEach(m => group.addLayer(m.marker));
    });
}

document.addEventListener('DOMContentLoaded', () => {
    gmInitMap();
    document.getElementById('gm-search').addEventListener('input', gmApplyFilters);
    document.getElementById('gm-toggle-vehicle').addEventListener('change', gmApplyFilters);
    document.getElementById('gm-toggle-employee').addEventListener('change', gmApplyFilters);
    document.getElementById('gm-toggle-customer').addEventListener('change', gmApplyFilters);
    const otherToggle = document.getElementById('gm-toggle-other');
    if (otherToggle) otherToggle.addEventListener('change', gmApplyFilters);
});
</script>
</body>
</html>