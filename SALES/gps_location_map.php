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

// --- Vehicle master data (dbo.View_Vehicle) — keyed by PlateNumber so we can match
// against Cartrack's 'registration' and pull Department (for pin color-coding, same
// scheme as employees/customers) plus the full spec sheet for the details modal.
// Cartrack's registration and TWM's PlateNumber aren't guaranteed to be formatted
// identically (spaces/dashes may differ), so the key strips everything but
// alphanumerics before matching, not just trim+uppercase.
function gm_normalize_plate($plate) {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$plate)));
}

// Resolve a vehicle Picture value the same way employee photos are resolved —
// TWM stores these under uploads/vehicle/filename.jpg (forward slash), so the
// resolver just needs its own subdirectory, not the employee_pics one.
function gm_resolve_vehicle_picture_paths($rawPic) {
    $rawPic = trim((string)$rawPic);
    if ($rawPic === '') return ['', ''];
    $normPic = str_replace('\\', '/', $rawPic);
    $picFile = basename($normPic);
    if ($picFile === '') return ['', ''];
        // Some filenames on disk contain literal spaces (e.g. "RDX 417.jpg"), which
    // break unencoded inside an <img src="..."> attribute. Always build the final
    // path from the encoded filename — never from the raw $normPic — regardless
    // of which subdirectory shape the raw value came in.
    $picFileEncoded = rawurlencode($picFile);
    // Legacy vehicle photos live directly under tradewellportal/vehicle/ (NOT
    // tradewellportal/uploads/ — confirmed against the actual folder on disk).
    $twmPic = '/TWM/uploads/vehicle/' . $picFileEncoded;
    $legacyPic = '/tradewellportal/vehicle/' . $picFileEncoded;
    return [$twmPic, $legacyPic];
}

$vehicleDetailsByPlate = [];
$vdSql = "
    SELECT [VehicleID],[Department],[PlateNumber],[Description],[Vehicletype],
           [Capacityweight],[Tiresize],[Active],[Picture],[FuelType],[Brand],
           [Model],[Year],[Transmission],[Wheeler],[Category]
    FROM [dbo].[View_Vehicle]
";
$vdStmt = sqlsrv_query($conn, $vdSql);
if ($vdStmt === false) {
    error_log('View_Vehicle query error: ' . print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($vdStmt, SQLSRV_FETCH_ASSOC)) {
        $plateKey = gm_normalize_plate($row['PlateNumber'] ?? '');
        if ($plateKey === '') continue;
        // Some plates have duplicate rows in View_Vehicle (data-quality issue,
        // e.g. placeholder plates like "0"/"UTC" shared across vehicles). Don't
        // let a blank-Department duplicate silently overwrite a good one —
        // only overwrite if we don't have this plate yet, or the existing
        // entry has no Department but this row does.
        $existing = $vehicleDetailsByPlate[$plateKey] ?? null;
        $existingHasDept = $existing && trim((string)($existing['Department'] ?? '')) !== '';
        $newHasDept = trim((string)($row['Department'] ?? '')) !== '';
        if ($existing === null || (!$existingHasDept && $newHasDept)) {
            $vehicleDetailsByPlate[$plateKey] = $row;
        }
    }
}

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

    $plateKey = gm_normalize_plate($v['registration'] ?? '');
    $vd = $vehicleDetailsByPlate[$plateKey] ?? null;
    $v['_department'] = $vd['Department'] ?? '';
    $v['_pinColor'] = gm_department_color($v['_department']) ?? '#15803d';
    [$v['_vehiclePic'], $v['_vehiclePicLegacy']] = gm_resolve_vehicle_picture_paths($vd['Picture'] ?? '');
    $v['_details'] = $vd;
}
unset($v);

$VehicleCount = count($vehicles);
$TotalCount   = $VehicleCount + count($locations);

// --- Employee GPS History tab (merged from standalone employee_gps_history.php) ---
$ehEmployees = [];
$ehEmpListSql = "
    SELECT DISTINCT e.EmployeeID, e.FirstName, e.LastName, e.[Picture]
    FROM dbo.TBL_HREmployeeList e
    INNER JOIN dbo.View_Employee_GPS g ON g.EmployeeID = e.EmployeeID
    WHERE e.Active = 1
    ORDER BY e.LastName, e.FirstName
";
$ehEmpListStmt = sqlsrv_query($conn, $ehEmpListSql);
if ($ehEmpListStmt === false) {
    error_log('TBL_HREmployeeList (GPS history tab) query error: ' . print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($ehEmpListStmt, SQLSRV_FETCH_ASSOC)) {
        $ehEmployees[] = $row;
    }
}

$EmployeeId = $_GET['EmployeeId'] ?? '';
$DateFrom   = $_GET['DateFrom'] ?? date('Y-m-d', strtotime('-7 days'));
$DateTo     = $_GET['DateTo'] ?? date('Y-m-d');

$ehPings = [];
$ehQueryError = null;

if ($EmployeeId !== '') {
    $ehSql = "
        SELECT EmployeeID, EmployeeName, Longitude, Latitude, DateTimeInput
        FROM dbo.View_Employee_GPS
        WHERE EmployeeID = ? AND DateTimeInput >= ? AND DateTimeInput <= ?
        ORDER BY DateTimeInput ASC
    ";
    $ehStmt = sqlsrv_query($conn, $ehSql, [$EmployeeId, $DateFrom . ' 00:00:00', $DateTo . ' 23:59:59']);
    if ($ehStmt === false) {
        $ehQueryError = sqlsrv_errors();
        error_log('View_Employee_GPS history query error: ' . print_r($ehQueryError, true));
    } else {
        while ($row = sqlsrv_fetch_array($ehStmt, SQLSRV_FETCH_ASSOC)) {
            $lat = $row['Latitude']; $lng = $row['Longitude'];
            if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) continue;

            $rawDt = $row['DateTimeInput'] ?? null;
            $ts = $rawDt instanceof DateTime ? $rawDt->format('Y-m-d H:i:s') : (string)$rawDt;

            $ehPings[] = ['lat' => (float)$lat, 'lng' => (float)$lng, 'ts' => $ts];
        }
    }
}

$ehSelectedEmployeeName = '';
$ehSelectedEmployeePic = '';
$ehSelectedEmployeePicLegacy = '';
$ehSelectedEmployeeInitials = '';
foreach ($ehEmployees as $e) {
    if ((string)$e['EmployeeID'] === (string)$EmployeeId) {
        $ehSelectedEmployeeName = trim($e['FirstName'] . ' ' . $e['LastName']);
        [$ehSelectedEmployeePic, $ehSelectedEmployeePicLegacy] = gm_resolve_picture_paths($e['Picture'] ?? '');
        $ehSelectedEmployeeInitials = strtoupper(substr($e['FirstName'], 0, 1) . substr($e['LastName'], 0, 1));
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

.gm-page-tabs { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 9px; width: fit-content; margin-bottom: 20px; }
.gm-page-tabs button {
    border: none; background: transparent; padding: 7px 16px; border-radius: 7px;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; font-weight: 600;
    color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, color .15s;
}
.gm-page-tabs button.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.gm-page-tabs button:hover:not(.active) { color: #374151; }

.gm-field { display: flex; flex-direction: column; gap: 6px; }
.gm-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; }
.gm-field input, .gm-field select {
    height: 42px; padding: 0 14px; border: 1.5px solid #d1d5db; border-radius: 9px;
    font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
    background: #f9fafb; outline: none; min-width: 180px;
}
.gm-field input:focus, .gm-field select:focus { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
.gm-btn {
    height: 42px; padding: 0 20px; border: none; border-radius: 9px; background: #2563eb; color: #fff;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600; cursor: pointer;
}
.gm-btn:hover { background: #1d4ed8; }

.gm-panel { background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px; padding: 20px 24px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.gm-panel h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #6b7280; margin-bottom: 12px; }
.gm-eh-table { width: 100%; font-size: 13px; border-collapse: collapse; }
.gm-eh-table thead tr { text-align: left; color: #6b7280; border-bottom: 1px solid #e2e5ea; }
.gm-eh-table th, .gm-eh-table td { padding: 8px 6px; }
.gm-eh-table tbody tr { border-bottom: 1px solid #f1f3f7; }
.gm-eh-table td.mono { font-family: 'IBM Plex Mono', monospace; color: #6b7280; }
.gm-empty { font-size: 13px; color: #6b7280; padding: 20px 0; }

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
#gm-eh-map {
    width: 100%; height: 520px; border-radius: 14px; border: 1.5px solid #e2e5ea;
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

/* Vehicle photo shown directly on the truck pin, same treatment as employee pins */
.gm-vehicle-pin-photo {
    width: 26px; height: 26px; border-radius: 50%; object-fit: cover;
    border: 1px solid rgba(255,255,255,.8);
}

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

/* ── Pin details modal (Employee / Customer / Vehicle) ── */
.gm-modal-backdrop {
    display: none; position: fixed; inset: 0; background: rgba(17,24,39,.5);
    z-index: 3000; align-items: center; justify-content: center; padding: 20px;
}
.gm-modal-backdrop.active { display: flex; }
.gm-modal {
    background: #fff; border-radius: 16px; max-width: 480px; width: 100%;
    max-height: 85vh; overflow-y: auto; position: relative;
    box-shadow: 0 20px 50px rgba(0,0,0,.25); padding: 28px 26px 22px;
}
.gm-modal-close {
    position: absolute; top: 14px; right: 14px; border: none; background: #f3f4f6;
    width: 30px; height: 30px; border-radius: 50%; font-size: 18px; line-height: 1;
    color: #6b7280; cursor: pointer;
}
.gm-modal-close:hover { background: #e5e7eb; color: #111827; }
.gm-modal-head { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
.gm-modal-avatar {
    width: 56px; height: 56px; border-radius: 50%; object-fit: cover;
    border: 2px solid #e2e5ea; flex-shrink: 0;
}
.gm-modal-avatar-initials {
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 18px; background: #2563eb;
}
.gm-modal-avatar-icon {
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 22px;
}
.gm-modal-title { font-size: 18px; font-weight: 700; color: #111827; }
.gm-modal-subtitle { font-size: 12px; color: #6b7280; margin-top: 2px; }
.gm-modal-grid { display: flex; flex-direction: column; gap: 2px; }
.gm-modal-row {
    display: flex; justify-content: space-between; gap: 14px; padding: 7px 0;
    border-bottom: 1px solid #f1f3f7; font-size: 13px;
}
.gm-modal-row:last-child { border-bottom: none; }
.gm-modal-row span:first-child { color: #6b7280; }
.gm-modal-row span:last-child { color: #111827; font-family: 'IBM Plex Mono', monospace; text-align: right; }
.gm-modal-warn {
    margin-top: 12px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
    border-radius: 10px; padding: 8px 12px; font-size: 12px;
}

/* Modal avatar becomes clickable when a real photo is loaded (icon/initials
   fallbacks are not — nothing to preview) */
.gm-modal-avatar[data-previewable] { cursor: zoom-in; }

/* ── Image lightbox — full-size preview on avatar click ── */
.gm-lightbox-backdrop {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,.85);
    z-index: 4000; align-items: center; justify-content: center; padding: 30px;
    cursor: zoom-out;
}
.gm-lightbox-backdrop.active { display: flex; }
.gm-lightbox-backdrop img {
    max-width: 90vw; max-height: 90vh; border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,.5);
}
.gm-lightbox-close {
    position: absolute; top: 20px; right: 24px; border: none; background: rgba(255,255,255,.15);
    width: 38px; height: 38px; border-radius: 50%; font-size: 22px; line-height: 1;
    color: #fff; cursor: pointer;
}
.gm-lightbox-close:hover { background: rgba(255,255,255,.3); }
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

    <div class="gm-page-tabs">
        <button type="button" id="gm-tab-map" class="active" onclick="gmSwitchTab('map')">
            <i class="bi bi-geo-alt-fill"></i> Map
        </button>
        <button type="button" id="gm-tab-eh" onclick="gmSwitchTab('eh')">
            <i class="bi bi-person-walking"></i> Employee GPS History
        </button>
    </div>

    <div id="gm-map-tab-section">
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
            <div class="gm-legend-title">Pin Color · Department</div>
            <div class="gm-legend-item"><span class="dot" style="background:#dc2626;"></span> Monde</div>
            <div class="gm-legend-item"><span class="dot" style="background:#2563eb;"></span> Century</div>
            <div class="gm-legend-item"><span class="dot" style="background:#16a34a;"></span> NutriAsia / Silver Swan</div>
            <div class="gm-legend-item"><span class="dot" style="background:#ca8a04;"></span> Multilines</div>
            <div class="gm-legend-item"><span class="dot" style="background:#15803d;"></span> No dept match</div>
        </div>
        <div id="gm-map"></div>
    </div>
    </div>

    <div id="gm-eh-tab-section" style="display:none;">
        <?php if ($ehQueryError): ?>
            <div class="gm-error">Could not load ping history — check the error log for details.</div>
        <?php endif; ?>

        <form method="GET" class="gm-toolbar">
            <div class="gm-field">
                <label>Employee</label>
                <select name="EmployeeId">
                    <option value="">— Select —</option>
                    <?php foreach ($ehEmployees as $e): ?>
                        <option value="<?= h($e['EmployeeID']) ?>" <?= (string)$e['EmployeeID'] === (string)$EmployeeId ? 'selected' : '' ?>>
                            <?= h($e['LastName'] . ', ' . $e['FirstName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="gm-field">
                <label>From</label>
                <input type="date" name="DateFrom" value="<?= h($DateFrom) ?>">
            </div>
            <div class="gm-field">
                <label>To</label>
                <input type="date" name="DateTo" value="<?= h($DateTo) ?>">
            </div>
            <input type="hidden" name="tab" value="eh">
            <button type="submit" class="gm-btn">Apply</button>
        </form>

        <?php if ($EmployeeId === ''): ?>
            <div class="gm-panel"><div class="gm-empty">Select an employee to see their GPS ping log.</div></div>
        <?php elseif (empty($ehPings)): ?>
            <div class="gm-panel"><div class="gm-empty">No pings logged for <?= h($ehSelectedEmployeeName) ?> in this date range.</div></div>
        <?php else: ?>
            <div class="gm-stats">
                <div class="gm-stat-card">
                    <div class="gm-stat-label">Total Pings</div>
                    <div class="gm-stat-value"><?= count($ehPings) ?></div>
                </div>
                <div class="gm-stat-card">
                    <div class="gm-stat-label">First Ping</div>
                    <div class="gm-stat-value" style="font-size:15px;"><?= h($ehPings[0]['ts']) ?></div>
                </div>
                <div class="gm-stat-card">
                    <div class="gm-stat-label">Last Ping</div>
                    <div class="gm-stat-value" style="font-size:15px;"><?= h(end($ehPings)['ts']) ?></div>
                </div>
            </div>

            <div class="gm-map-wrap" style="margin-bottom:20px;">
                <div id="gm-eh-map"></div>
            </div>

            <div class="gm-panel">
                <h4><?= h($ehSelectedEmployeeName) ?> — Ping Log (<?= count($ehPings) ?>)</h4>
                <div style="max-height:400px;overflow-y:auto;">
                    <table class="gm-eh-table">
                        <thead><tr><th>Timestamp</th><th>Latitude</th><th>Longitude</th></tr></thead>
                        <tbody>
                            <?php foreach (array_reverse($ehPings) as $p): ?>
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
</div>

<div id="gm-modal-backdrop" class="gm-modal-backdrop" onclick="if(event.target===this) gmCloseModal()">
    <div class="gm-modal">
        <button type="button" class="gm-modal-close" onclick="gmCloseModal()">&times;</button>
        <div id="gm-modal-body"></div>
    </div>
</div>

<div id="gm-lightbox-backdrop" class="gm-lightbox-backdrop" onclick="gmCloseLightbox()">
    <button type="button" class="gm-lightbox-close" onclick="gmCloseLightbox(); event.stopPropagation();">&times;</button>
    <img id="gm-lightbox-img" src="" alt="">
</div>

<script>
// --- Vehicle data for the map (Cartrack) ---
const gmVehicles = [
    <?php foreach ($vehicles as $v):
        $lat = $v['location']['latitude'] ?? null;
        $lng = $v['location']['longitude'] ?? null;
        if ($lat === null || $lng === null) continue;
        $vd = $v['_details'];
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
        geofenceCount: <?= json_encode(count($v['_geofenceIds'])) ?>,
        department: <?= json_encode($v['_department']) ?>,
        pinColor: <?= json_encode($v['_pinColor']) ?>,
        pic: <?= json_encode($v['_vehiclePic']) ?>,
        picLegacy: <?= json_encode($v['_vehiclePicLegacy']) ?>,
        details: {
            vehicleId: <?= json_encode($vd['VehicleID'] ?? null) ?>,
            description: <?= json_encode($vd['Description'] ?? null) ?>,
            vehicleType: <?= json_encode($vd['Vehicletype'] ?? null) ?>,
            capacityWeight: <?= json_encode($vd['Capacityweight'] ?? null) ?>,
            tireSize: <?= json_encode($vd['Tiresize'] ?? null) ?>,
            active: <?= json_encode($vd['Active'] ?? null) ?>,
            fuelType: <?= json_encode($vd['FuelType'] ?? null) ?>,
            brand: <?= json_encode($vd['Brand'] ?? null) ?>,
            model: <?= json_encode($vd['Model'] ?? null) ?>,
            year: <?= json_encode($vd['Year'] ?? null) ?>,
            transmission: <?= json_encode($vd['Transmission'] ?? null) ?>,
            wheeler: <?= json_encode($vd['Wheeler'] ?? null) ?>,
            category: <?= json_encode($vd['Category'] ?? null) ?>
        }
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

// --- Details modal, shared by Employee / Customer / Other / Vehicle pins ---
function gmModalRow(label, value) {
    if (value === null || value === undefined || value === '') value = '—';
    return `<div class="gm-modal-row"><span>${label}</span><span>${value}</span></div>`;
}

function gmModalAvatarHtml(kind, loc) {
    if (kind === 'vehicle') {
        if (!loc.pic) {
            return `<div class="gm-modal-avatar gm-modal-avatar-icon" style="background:${loc.pinColor || '#15803d'};"><i class="bi bi-truck"></i></div>`;
        }
        const src = loc.pic.replace(/"/g, '&quot;');
        const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
        const fallbackBg = loc.pinColor || '#15803d';
        return `<img src="${src}" class="gm-modal-avatar" alt="" data-previewable
                     data-legacy="${legacy}"
                     onclick="gmOpenLightbox(this.src); event.stopPropagation();"
                     onerror="(function(img){
                         var leg = img.getAttribute('data-legacy');
                         if (leg && !img.getAttribute('data-legacy-tried')) {
                             img.setAttribute('data-legacy-tried','1');
                             img.src = leg;
                         } else {
                             var d = document.createElement('div');
                             d.className = 'gm-modal-avatar gm-modal-avatar-icon';
                             d.style.background = '${fallbackBg}';
                             var ic = document.createElement('i');
                             ic.className = 'bi bi-truck';
                             d.appendChild(ic);
                             img.replaceWith(d);
                         }
                     })(this)">`;
    }
    if (loc.category !== 'employee') {
        const cfg = GM_ICONS[loc.category] || GM_ICONS.other;
        return `<div class="gm-modal-avatar gm-modal-avatar-icon" style="background:${loc.pinColor || cfg.fallback};"><i class="bi ${cfg.bi}"></i></div>`;
    }
    const initials = (loc.initials || '?').replace(/"/g, '&quot;');
    if (!loc.pic) {
        return `<div class="gm-modal-avatar gm-modal-avatar-initials">${initials}</div>`;
    }
    const src = loc.pic.replace(/"/g, '&quot;');
    const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
    return `<img src="${src}" class="gm-modal-avatar" alt="" data-previewable
                 data-legacy="${legacy}" data-initials="${initials}"
                 onclick="gmOpenLightbox(this.src); event.stopPropagation();"
                 onerror="(function(img){
                     var leg = img.getAttribute('data-legacy');
                     if (leg && !img.getAttribute('data-legacy-tried')) {
                         img.setAttribute('data-legacy-tried','1');
                         img.src = leg;
                     } else {
                         var d = document.createElement('div');
                         d.className = 'gm-modal-avatar gm-modal-avatar-initials';
                         d.textContent = img.getAttribute('data-initials') || '?';
                         img.replaceWith(d);
                     }
                 })(this)">`;
}

function gmOpenModal(kind, data) {
    const body = document.getElementById('gm-modal-body');
    let html = '';

    if (kind === 'vehicle') {
        const v = data;
        const d = v.details || {};
        html += `<div class="gm-modal-head">
            ${gmModalAvatarHtml('vehicle', v)}
            <div>
                <div class="gm-modal-title">${v.plate}</div>
                <div class="gm-modal-subtitle">${d.brand || ''} ${d.model || ''} ${d.year ? '(' + d.year + ')' : ''}</div>
            </div>
        </div>`;
        html += '<div class="gm-modal-grid">';
        html += gmModalRow('Status', v.status);
        html += gmModalRow('Department', v.department);
        html += gmModalRow('Fuel', v.fuel !== null ? v.fuel + '%' : null);
        html += gmModalRow('Geofences', v.geofenceCount);
        html += gmModalRow('Last Update', v.lastUpdate);
        html += gmModalRow('Position', v.position);
        html += gmModalRow('Description', d.description);
        html += gmModalRow('Vehicle Type', d.vehicleType);
        html += gmModalRow('Category', d.category);
        html += gmModalRow('Capacity (weight)', d.capacityWeight);
        html += gmModalRow('Tire Size', d.tireSize);
        html += gmModalRow('Fuel Type', d.fuelType);
        html += gmModalRow('Transmission', d.transmission);
        html += gmModalRow('Wheeler', d.wheeler);
        html += gmModalRow('Active', d.active === null || d.active === undefined ? null : (d.active ? 'Yes' : 'No'));
        html += '</div>';
        if (v.isStale) html += '<div class="gm-modal-warn">⚠ No update in over 24 hours</div>';
    } else {
        const loc = data;
        html += `<div class="gm-modal-head">
            ${gmModalAvatarHtml('location', loc)}
            <div>
                <div class="gm-modal-title">${loc.name || '(no name)'}</div>
                <div class="gm-modal-subtitle">${loc.type || ''}</div>
            </div>
        </div>`;
        html += '<div class="gm-modal-grid">';
        html += gmModalRow('Type', loc.type);
        html += gmModalRow('Department', loc.department);
        html += gmModalRow('Code', loc.code);
        html += gmModalRow('Last Update', loc.lastUpdate);
        html += '</div>';
        if (loc.corrected) html += '<div class="gm-modal-warn">⚠ Lat/Lng appeared swapped in source data — auto-corrected for display</div>';
        if (loc.isStale) html += '<div class="gm-modal-warn">⚠ No update in over 24 hours</div>';
    }

    body.innerHTML = html;
    document.getElementById('gm-modal-backdrop').classList.add('active');
}

function gmCloseModal() {
    document.getElementById('gm-modal-backdrop').classList.remove('active');
}

function gmOpenLightbox(src) {
    if (!src) return;
    document.getElementById('gm-lightbox-img').src = src;
    document.getElementById('gm-lightbox-backdrop').classList.add('active');
}

function gmCloseLightbox() {
    document.getElementById('gm-lightbox-backdrop').classList.remove('active');
    document.getElementById('gm-lightbox-img').src = '';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        gmCloseLightbox();
        gmCloseModal();
    }
});

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
let gmVehicleMarkers = []; // { marker, plate } — vehicles, now included in search filtering
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
        let vInner = '<i class="bi bi-truck"></i>';
        if (v.pic) {
            const src = v.pic.replace(/"/g, '&quot;');
            const legacy = (v.picLegacy || '').replace(/"/g, '&quot;');
            vInner = `<img src="${src}" class="gm-vehicle-pin-photo" alt=""
                           data-legacy="${legacy}"
                           onerror="(function(img){
                               var leg = img.getAttribute('data-legacy');
                               if (leg && !img.getAttribute('data-legacy-tried')) {
                                   img.setAttribute('data-legacy-tried','1');
                                   img.src = leg;
                               } else {
                                   var i = document.createElement('i');
                                   i.className = 'bi bi-truck';
                                   img.replaceWith(i);
                               }
                           })(this)">`;
        }
        const icon = L.divIcon({
            className: '',
            html: `
                <div style="text-align:center;">
                    <div class="gm-vehicle-pin ${v.isStale ? 'gm-pin-stale' : ''}" style="background:${v.pinColor};">
                        ${vInner}
                    </div>
                    <div class="gm-pin-label">${v.plate}</div>
                </div>
            `,
            iconSize: [34, 48],
            iconAnchor: [17, 40],
            popupAnchor: [0, -40]
        });

        const marker = L.marker([v.lat, v.lng], { icon });
        marker.on('click', () => gmOpenModal('vehicle', v));
        marker.addTo(gmVehicleLayer);
        gmVehicleMarkers.push({ marker, plate: (v.plate || '').toLowerCase() });
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
        marker.on('click', () => gmOpenModal('location', loc));
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

    // Vehicle layer: now filtered by the search box too (previously simple
    // show/hide only) — rebuilt from gmVehicleMarkers on every filter change,
    // same clear-and-repopulate pattern as the GPS category clusters below.
    if (gmVehicleLayer) {
        gmVehicleLayer.clearLayers();
        if (showVehicle) {
            if (!gmMap.hasLayer(gmVehicleLayer)) gmMap.addLayer(gmVehicleLayer);
            gmVehicleMarkers
                .filter(m => q === '' || m.plate.includes(q))
                .forEach(m => gmVehicleLayer.addLayer(m.marker));
        } else {
            if (gmMap.hasLayer(gmVehicleLayer)) gmMap.removeLayer(gmVehicleLayer);
        }
    }

    const categoryShown = { employee: showEmployee, customer: showCustomer, other: showOther };

    // Always rebuild each cluster group's contents from the current query + toggle
    // state (an empty query just means "match everything"). Previously, clearing the
    // search box short-circuited to a plain show/hide of the whole group, which left
    // it holding only whatever subset the last non-empty search had matched.
    Object.keys(gmClusterGroups).forEach(cat => {
        const group = gmClusterGroups[cat];
        group.clearLayers();
        if (!categoryShown[cat]) {
            if (gmMap.hasLayer(group)) gmMap.removeLayer(group);
            return;
        }
        if (!gmMap.hasLayer(group)) gmMap.addLayer(group);
        gmMarkers
            .filter(m => m.category === cat && (q === '' || m.name.includes(q) || m.code.includes(q)))
            .forEach(m => group.addLayer(m.marker));
    });
}

const ehPings = <?= json_encode($ehPings, JSON_UNESCAPED_UNICODE) ?>;
const ehEmployeeName = <?= json_encode($ehSelectedEmployeeName) ?>;
const ehEmployeePic = <?= json_encode($ehSelectedEmployeePic) ?>;
const ehEmployeePicLegacy = <?= json_encode($ehSelectedEmployeePicLegacy) ?>;
const ehEmployeeInitials = <?= json_encode($ehSelectedEmployeeInitials ?: '?') ?>;

let ehMap = null;
let ehMapInitialized = false;

function ehInitMap() {
    if (ehMapInitialized || !ehPings.length) return;
    ehMapInitialized = true;

    ehMap = L.map('gm-eh-map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(ehMap);

    const bounds = [];
    const trailLatLngs = ehPings.map(p => [p.lat, p.lng]);
    L.polyline(trailLatLngs, { color: '#9ca3af', weight: 2, opacity: 0.6, dashArray: '4,4' }).addTo(ehMap);

    // Reuse the same photo/initials pin as the main GPS layer
    const ehIcon = gmBuildIcon({
        category: 'employee', pic: ehEmployeePic, picLegacy: ehEmployeePicLegacy,
        initials: ehEmployeeInitials, corrected: false, pinColor: '#2563eb'
    });

    ehPings.forEach(p => {
        L.marker([p.lat, p.lng], { icon: ehIcon })
            .bindPopup(`<b>${ehEmployeeName}</b><br>${p.ts}`).addTo(ehMap);
        bounds.push([p.lat, p.lng]);
    });

    if (bounds.length) ehMap.fitBounds(bounds, { padding: [30, 30] });
}

function gmSwitchTab(tab) {
    const mapSection = document.getElementById('gm-map-tab-section');
    const ehSection = document.getElementById('gm-eh-tab-section');
    const btnMap = document.getElementById('gm-tab-map');
    const btnEh = document.getElementById('gm-tab-eh');

    mapSection.style.display = tab === 'map' ? 'block' : 'none';
    ehSection.style.display = tab === 'eh' ? 'block' : 'none';
    btnMap.classList.toggle('active', tab === 'map');
    btnEh.classList.toggle('active', tab === 'eh');

    if (tab === 'eh') {
        ehInitMap();
        setTimeout(() => { if (ehMap) ehMap.invalidateSize(); }, 50);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    gmInitMap();
    const initialTab = new URLSearchParams(window.location.search).get('tab');
    if (initialTab === 'eh') gmSwitchTab('eh');
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