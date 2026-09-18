<?php
/**
 * vehicle_status.php
 * TWM/FLEET — Fleet Tracking (Cartrack Fleet API)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/cartrack_client.php';

auth_check();
rbac_gate($pdo, 'fleet_tracking');
$viewOnly = rbac_is_view_only('fleet_tracking');

$topbar_page = 'fleet_tracking';

function h($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

// Resolve an employee Picture value into TWM/legacy portal URL candidates —
// mirrors the dual-path + onerror-fallback convention used in employee-list.php.
// TWM stores:    uploads/employee_pics/filename.jpg  (forward slash, subdirectory)
// Legacy stores: uploads\filename.jpg                (backslash, no subdirectory)
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

// Pin color coding by Department/principal — per-company color assignments as given.
// Falls back to a category default (employee/customer/other) when Department is blank
// or doesn't match a known key.
function ft_department_color($dept) {
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

// --- Employee & Customer GPS locations (dbo.View_GPS_Location) ---
// Merged from gps_location_map.php: rendered as toggleable layers on the Map tab,
// on the same Leaflet instance as the fleet vehicle pins.
// Employee rows are left-joined to TBL_HREmployeeList on EmployeeID = Code so the
// popup can show the employee's Picture (same convention as employee-list.php).
$gpsLocations = [];
$gpsNoCoordCount = 0;
$gpsCorrectedCount = 0;

$gpsSql = "
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
$gpsStmt = sqlsrv_query($conn, $gpsSql);

if ($gpsStmt === false) {
    error_log('View_GPS_Location query error: ' . print_r(sqlsrv_errors(), true));
} else {
    while ($row = sqlsrv_fetch_array($gpsStmt, SQLSRV_FETCH_ASSOC)) {
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
            $gpsNoCoordCount++;
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
            $gpsCorrectedCount++;
        }

        $empPic = '';
        $empPicLegacy = '';
        $empInitials = '';
        if ($category === 'employee') {
            [$empPic, $empPicLegacy] = ft_resolve_picture_paths($row['EmployeePicture'] ?? '');
            $fn = trim((string)($row['EmployeeFirstName'] ?? ''));
            $ln = trim((string)($row['EmployeeLastName'] ?? ''));
            $empInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));
        }

        $categoryDefault = $category === 'employee' ? '#2563eb' : ($category === 'customer' ? '#d97706' : '#6b7280');
        $department = trim((string)($row['Department'] ?? ''));
        $pinColor = ft_department_color($department) ?? $categoryDefault;

        // DateTimeInput comes back as a DateTime object from sqlsrv — format it for
        // display and flag rows that haven't reported in over 24hr (same threshold
        // used for vehicle staleness above).
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

        $gpsLocations[] = [
            'category'    => $category,
            'type'        => $row['Type'] ?? '',
            'department'  => $department,
            'code'        => $row['Code'] ?? '',
            'name'        => $row['Name'] ?? '',
            'lat'         => $lat,
            'lng'         => $lng,
            'corrected'   => $corrected,
            'pic'         => $empPic,
            'picLegacy'   => $empPicLegacy,
            'initials'    => $empInitials,
            'pinColor'    => $pinColor,
            'lastUpdate'  => $lastUpdate,
            'isStale'     => $isStale,
        ];
    }
    sqlsrv_free_stmt($gpsStmt);
}

$GpsEmployeeCount = count(array_filter($gpsLocations, fn($l) => $l['category'] === 'employee'));
$GpsCustomerCount = count(array_filter($gpsLocations, fn($l) => $l['category'] === 'customer'));
$GpsOtherCount    = count(array_filter($gpsLocations, fn($l) => $l['category'] === 'other'));

$vehicleStatus = cartrack_get('/vehicles/status');
if (isset($vehicleStatus['error'])) {
    error_log('Cartrack API error [' . $vehicleStatus['code'] . ']: ' . $vehicleStatus['raw']);
}
$apiError = isset($vehicleStatus['error']);

// --- Trips: vehicle selector + fetch ---
// CONFIRMED (2026-09-05) against Cartrack's official API reference: per-vehicle trips is a
// dedicated endpoint, GET /trips/:registration (registration as a path segment) — see
// https://developer.cartrack.com/docs/fleet-api/get-trips-by-registration. Earlier attempts at
// 'filter[vehicle_id]' and a 'registration' query param were both silently ignored by the
// fleet-wide /trips endpoint, which has no vehicle-scoping param at all. Fixed below.
$tripVehicleOptions = $vehicleStatus['data'] ?? [];
usort($tripVehicleOptions, fn($a, $b) => strcmp($a['registration'] ?? '', $b['registration'] ?? ''));

$TripVehicleId = $_GET['TripVehicleId'] ?? '';
$TripPage = max(1, (int)($_GET['TripPage'] ?? 1));

if ($TripVehicleId === '' && !empty($tripVehicleOptions)) {
    $TripVehicleId = $tripVehicleOptions[0]['vehicle_id'] ?? '';
}

// The selector still keys on vehicle_id (stable, unique), so look up the matching
// registration to actually send to the API.
$TripVehicleRegistration = '';
foreach ($tripVehicleOptions as $opt) {
    if ((string)($opt['vehicle_id'] ?? '') === (string)$TripVehicleId) {
        $TripVehicleRegistration = $opt['registration'] ?? '';
        break;
    }
}

$tripsParams = [
    'start_timestamp' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    'end_timestamp'   => date('Y-m-d H:i:s'),
    'page'            => $TripPage,
];

// Per-vehicle trips use the dedicated /trips/:registration endpoint (see note above);
// otherwise fall back to the fleet-wide /trips.
if ($TripVehicleRegistration !== '') {
    $tripsData = cartrack_get('/trips/' . rawurlencode($TripVehicleRegistration), $tripsParams);
} else {
    $tripsData = cartrack_get('/trips', $tripsParams);
}
$geofencesData = cartrack_get('/geofences');

// --- Selected geofence + its visitor/visit history (read-only drill-down) ---
// NOTE: exact endpoint paths unconfirmed against Cartrack's official docs as of this
// edit — verify before relying on this in production. Using conventional REST shape.
$GeofenceId = $_GET['GeofenceId'] ?? '';
$VisitFrom = $_GET['VisitFrom'] ?? date('Y-m-d', strtotime('-7 days'));
$VisitTo   = $_GET['VisitTo'] ?? date('Y-m-d');
$AlertsFrom = $_GET['AlertsFrom'] ?? date('Y-m-d', strtotime('-1 days'));
$AlertsTo   = $_GET['AlertsTo'] ?? date('Y-m-d');
$geofenceVisitorsData = null;
$geofenceVisitsData = null;
if ($GeofenceId !== '') {
    $geofenceVisitorsData = cartrack_get('/geofences/' . rawurlencode($GeofenceId) . '/visitors');

    // /geofences/visits is fleet-wide with no geofence filter param (confirmed against docs —
    // same shape as /trips/elapsed), so fetch and filter client-side. Cartrack only writes a
    // visit record on IGN_OFF, so a vehicle currently inside the zone won't show up yet.
    // /geofences/visits enforces a max ~1-day range between filter[enter_timestamp] and
    // filter[exit_timestamp] (confirmed via two 422s: the rejected cutoff was exactly
    // ~1 day after whatever enter_timestamp we sent, each time). So we page backward in
    // 1-day windows across the user-selected [$VisitFrom, $VisitTo] range instead of a
    // fixed 7 days.
    $rawVisits = [];
    $maxCallsPerWindow = 10;   // pagination safety cap within a single day's window
    $maxWindows = 45;          // hard cap on total days walked, regardless of range picked

    $rangeEnd = DateTime::createFromFormat('Y-m-d H:i:s', $VisitTo . ' 23:59:59');
    $rangeStart = DateTime::createFromFormat('Y-m-d H:i:s', $VisitFrom . ' 00:00:00');
    if (!$rangeEnd || !$rangeStart || $rangeStart > $rangeEnd) {
        $geofenceVisitsData = ['error' => true, 'code' => 0, 'raw' => 'Invalid date range.'];
    } else {
        $windowEnd = $rangeEnd;
        $windows = 0;
        while ($windowEnd > $rangeStart && $windows < $maxWindows) {
            $windowStart = max($rangeStart, (clone $windowEnd)->modify('-1 day'));

            $page = 1;
            $lastPage = 1;
            do {
                $visitsResp = cartrack_get('/geofences/visits', [
                    'filter[enter_timestamp]' => $windowStart->format('Y-m-d H:i:s'),
                    'filter[exit_timestamp]'  => $windowEnd->format('Y-m-d H:i:s'),
                    'page'                    => $page,
                ]);
                if (isset($visitsResp['error'])) {
                    $geofenceVisitsData = $visitsResp;
                    break 2; // stop entirely on a real error
                }
                foreach (($visitsResp['data'] ?? []) as $visit) {
                    if ((string)($visit['geofence_id'] ?? '') === (string)$GeofenceId) {
                        $rawVisits[] = $visit;
                    }
                }
                $lastPage = $visitsResp['meta']['last_page'] ?? 1;
                $page++;
            } while ($page <= $lastPage && $page <= $maxCallsPerWindow);

            $windowEnd = $windowStart;
            $windows++;
        }
    }

    if (!isset($geofenceVisitsData)) {
        $geofenceVisitsData = ['data' => $rawVisits];
    }

    // --- Near real-time entry/exit feed, mirroring Cartrack's own email alerts ---
    // Powered by GET /alerts/notifications, fed by whatever geofence alerts are already
    // configured per-vehicle in the Cartrack portal (same source as the "[Cartrack Alert]"
    // emails). Fleet-wide + paginated, so filter by geofence_id client-side while paging.
    // Each physical crossing fires once per configured notification channel (E-Mail, RSS,
    // etc.) with an identical event_ts, so dedupe on (registration, event_ts, direction).
    // Direction isn't its own field — read it out of notification_msg.
    $geofenceAlerts = [];
    $geofenceAlertsData = null;
    $alertsPage = 1;
    $alertsLastPage = 1;
    $alertsMaxPages = 30;
    $seenAlerts = [];
    do {
        $alertsResp = cartrack_get('/alerts/notifications', [
            'filter[date_from]' => $VisitFrom . ' 00:00:00',
            'filter[date_to]'   => $VisitTo . ' 23:59:59',
            'page'              => $alertsPage,
        ]);
        if (isset($alertsResp['error'])) { $geofenceAlertsData = $alertsResp; break; }
        foreach (($alertsResp['data'] ?? []) as $alert) {
            if ((string)($alert['geofence_id'] ?? '') !== (string)$GeofenceId) continue;

            $msg = $alert['notification_msg'] ?? '';
            if (stripos($msg, 'entered') !== false) {
                $direction = 'entered';
            } elseif (stripos($msg, 'left') !== false) {
                $direction = 'left';
            } else {
                $direction = $alert['trigger_description'] ?? '—';
            }

            $dedupeKey = ($alert['registration'] ?? '') . '|' . ($alert['event_ts'] ?? '') . '|' . $direction;
            if (isset($seenAlerts[$dedupeKey])) continue;
            $seenAlerts[$dedupeKey] = true;

            $geofenceAlerts[] = [
                'registration' => $alert['registration'] ?? '—',
                'direction'    => $direction,
                'event_ts'     => $alert['event_ts'] ?? '—',
            ];
        }
        $alertsLastPage = $alertsResp['meta']['last_page'] ?? 1;
        $alertsPage++;
    } while ($alertsPage <= $alertsLastPage && $alertsPage <= $alertsMaxPages);

    // Newest first
    usort($geofenceAlerts, fn($a, $b) => strcmp($b['event_ts'], $a['event_ts']));

    // --- Employee ping count inside this zone (simple count, not session-based visits —
    // pings are periodic samples, not a continuous feed like Cartrack gives for vehicles) ---
    $selectedGeofencePolygon = [];
    if (!isset($geofencesData['error'])) {
        foreach (($geofencesData['data'] ?? []) as $gf) {
            if ((string)($gf['geofence_id'] ?? '') === (string)$GeofenceId) {
                $selectedGeofencePolygon = ft_parse_wkt_polygon($gf['polygon'] ?? '');
                break;
            }
        }
    }

    $employeePingCounts = [];
    if (!empty($selectedGeofencePolygon)) {
        $empSql = "
            SELECT EmployeeID, EmployeeName, Longitude, Latitude
            FROM dbo.View_Employee_GPS
            WHERE DateTimeInput >= ? AND DateTimeInput <= ?
        ";
        $empStmt = sqlsrv_query($conn, $empSql, [$VisitFrom . ' 00:00:00', $VisitTo . ' 23:59:59']);
        if ($empStmt === false) {
            error_log('View_Employee_GPS query error: ' . print_r(sqlsrv_errors(), true));
        } else {
            while ($row = sqlsrv_fetch_array($empStmt, SQLSRV_FETCH_ASSOC)) {
                $lat = $row['Latitude']; $lng = $row['Longitude'];
                if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) continue;
                if (!ft_point_in_polygon((float)$lat, (float)$lng, $selectedGeofencePolygon)) continue;

                $empId = $row['EmployeeID'];
                if (!isset($employeePingCounts[$empId])) {
                    $employeePingCounts[$empId] = ['name' => $row['EmployeeName'] ?? $empId, 'count' => 0];
                }
                $employeePingCounts[$empId]['count']++;
            }
        }
        uasort($employeePingCounts, fn($a, $b) => $b['count'] <=> $a['count']);
    }
}

// --- Fleet-wide real-time entry/exit feed (Alerts tab) — same source, dedupe logic and
// direction parsing as the per-geofence panel above, just not filtered to one geofence_id.
$fleetAlerts = [];
$fleetAlertsData = null;
$fleetAlertsPage = 1;
$fleetAlertsLastPage = 1;
$fleetAlertsMaxPages = 30;
$seenFleetAlerts = [];
do {
    $fleetAlertsResp = cartrack_get('/alerts/notifications', [
        'filter[date_from]' => $AlertsFrom . ' 00:00:00',
        'filter[date_to]'   => $AlertsTo . ' 23:59:59',
        'page'              => $fleetAlertsPage,
    ]);
    if (isset($fleetAlertsResp['error'])) { $fleetAlertsData = $fleetAlertsResp; break; }
    foreach (($fleetAlertsResp['data'] ?? []) as $alert) {
        $msg = $alert['notification_msg'] ?? '';
        if (stripos($msg, 'entered') !== false) {
            $direction = 'entered';
        } elseif (stripos($msg, 'left') !== false) {
            $direction = 'left';
        } else {
            $direction = $alert['trigger_description'] ?? '—';
        }
        $dedupeKey = ($alert['registration'] ?? '') . '|' . ($alert['event_ts'] ?? '') . '|' . $direction;
        if (isset($seenFleetAlerts[$dedupeKey])) continue;
        $seenFleetAlerts[$dedupeKey] = true;

        $fleetAlerts[] = [
            'registration' => $alert['registration'] ?? '—',
            'geofence'     => $alert['name'] ?? '—',
            'direction'    => $direction,
            'event_ts'     => $alert['event_ts'] ?? '—',
        ];
    }
    $fleetAlertsLastPage = $fleetAlertsResp['meta']['last_page'] ?? 1;
    $fleetAlertsPage++;
} while ($fleetAlertsPage <= $fleetAlertsLastPage && $fleetAlertsPage <= $fleetAlertsMaxPages);

usort($fleetAlerts, fn($a, $b) => strcmp($b['event_ts'], $a['event_ts']));

$fleetAlertsByPlate = [];
foreach ($fleetAlerts as $alert) {
    $fleetAlertsByPlate[$alert['registration']][] = $alert;
}

// --- Elapsed points: used to draw a real (if coarse) route line per trip ---
// Confirmed against a live API response on 2026-09-02: /trips/elapsed returns one GPS
// ping per STOP (arrival point + time_elapsed until the vehicle moved again), not a
// continuous breadcrumb trail. Chaining these chronologically for one vehicle gives a
// real stop-to-stop path across the day; a single short trip may still only have its
// own start/end if no stop happened mid-trip. Endpoint takes no vehicle filter param
// (confirmed — a plain call returns all vehicles mixed together), so we filter client-side.
$elapsedPoints = [];
if ($TripVehicleId !== '') {
    $elapsedPage = 1;
    $elapsedLastPage = 1;
    $elapsedMaxPages = 15; // safety cap: avoid dozens of calls on a very busy day
    do {
        $elapsedResp = cartrack_get('/trips/elapsed', [
            'start_timestamp' => $tripsParams['start_timestamp'],
            'end_timestamp'   => $tripsParams['end_timestamp'],
            'page'            => $elapsedPage,
        ]);
        if (isset($elapsedResp['error'])) break;
        foreach (($elapsedResp['data'] ?? []) as $pt) {
            if ((string)($pt['vehicle_id'] ?? '') !== (string)$TripVehicleId) continue;
            if (!isset($pt['latitude'], $pt['longitude'])) continue;
            $elapsedPoints[] = [
                'lat' => (float)$pt['latitude'],
                'lng' => (float)$pt['longitude'],
                'ts'  => strtotime($pt['start_timestamp'] ?? ''),
                'address' => $pt['address'] ?? '',
            ];
        }
        $elapsedLastPage = $elapsedResp['meta']['last_page'] ?? 1;
        $elapsedPage++;
    } while ($elapsedPage <= $elapsedLastPage && $elapsedPage <= $elapsedMaxPages);

    usort($elapsedPoints, fn($a, $b) => $a['ts'] <=> $b['ts']);
}

// --- Classify ---
$vehicles = $apiError ? [] : ($vehicleStatus['data'] ?? []);

foreach ($vehicles as &$v) {
    $moving = ($v['speed'] ?? 0) > 0;
    $ignition = $v['ignition'] ?? false;
    if ($moving) {
        $v['_statusLabel'] = 'Moving';
        $v['_statusRank']  = 0;
    } elseif ($ignition) {
        $v['_statusLabel'] = 'Idle (Engine On)';
        $v['_statusRank']  = 1;
    } else {
        $v['_statusLabel'] = 'Parked';
        $v['_statusRank']  = 2;
    }
    $v['_fuelPct'] = $v['fuel']['precentage_left'] ?? null; // Cartrack's own field-name typo, not ours
    $v['_position'] = $v['location']['position_description'] ?? '';
    $v['_lastUpdate'] = $v['location']['updated'] ?? $v['event_ts'] ?? '';
    $v['_lastUpdateTs'] = strtotime($v['_lastUpdate'] ?? '') ?: 0;
    $v['_isStale'] = $v['_lastUpdateTs'] > 0 && $v['_lastUpdateTs'] < (time() - 86400); // 24hr threshold

    // Extra columns
    $v['_chassis']     = $v['chassis_number'] ?? '—';
    $v['_engineType']  = $v['engine_type'] ?? '—';
    $v['_speed']       = $v['speed'] ?? 0;
    $v['_bearing']     = $v['bearing'] ?? null;
    $v['_gpsFix']      = $v['location']['gps_fix_type'] ?? null; // raw code — Cartrack hasn't confirmed the 1/2/3 meaning to us, shown as-is
    $v['_geofenceIds'] = $v['location']['geofence_ids'] ?? [];
    $v['_idling']      = $v['idling'] ?? false;
    $v['_vext']        = $v['vext'] ?? null;
    $v['_tcu']         = $v['tcu_percentage'] ?? null;
}
unset($v);

// --- Stat totals (computed on the FULL unfiltered set, so cards stay a fleet-wide summary) ---
$TotalCount  = count($vehicles);
$MovingCount = count(array_filter($vehicles, fn($v) => $v['_statusRank'] === 0));
$IdleCount   = count(array_filter($vehicles, fn($v) => $v['_statusRank'] === 1));
$ParkedCount = count(array_filter($vehicles, fn($v) => $v['_statusRank'] === 2));
$LowFuelCount = count(array_filter($vehicles, fn($v) => $v['_fuelPct'] !== null && $v['_fuelPct'] <= 15));
$StaleCount = count(array_filter($vehicles, fn($v) => $v['_isStale']));

// --- Filters (GET so they're bookmarkable/shareable) ---
$Status = $_GET['Status'] ?? '';           // '', 'Moving', 'Idle (Engine On)', 'Parked'
$Search = trim($_GET['Search'] ?? '');

if ($Status !== '') {
    $vehicles = array_filter($vehicles, fn($v) => $v['_statusLabel'] === $Status);
}
if ($Search !== '') {
    $needle = mb_strtolower($Search);
    $vehicles = array_filter($vehicles, function($v) use ($needle) {
        $haystack = mb_strtolower(($v['registration'] ?? '') . ' ' . $v['_position'] . ' ' . ($v['chassis_number'] ?? ''));
        return str_contains($haystack, $needle);
    });
}

// --- Sort: Last Update, most recent first ---
usort($vehicles, fn($a, $b) => $b['_lastUpdateTs'] <=> $a['_lastUpdateTs']);

$FilteredCount = count($vehicles);

// Helper to preserve filters across links
function ft_qs($overrides = []) {
    $current = $_GET;
    foreach ($overrides as $k => $v) { $current[$k] = $v; }
    return htmlspecialchars('?' . http_build_query($current));
}

// Parses a WKT "POLYGON((lng lat, lng lat, ...))" string into [[lat,lng], ...] pairs Leaflet expects.
// Cartrack returns coordinates as lng,lat (WKT standard) — Leaflet wants lat,lng, so they're swapped here.
function ft_parse_wkt_polygon($wkt) {
    if (!preg_match('/POLYGON\(\((.*)\)\)/', $wkt, $m)) return [];
    $coords = [];
    foreach (explode(',', trim($m[1])) as $pair) {
        $parts = explode(' ', trim($pair));
        if (count($parts) < 2) continue;
        $coords[] = [(float)$parts[1], (float)$parts[0]]; // [lat, lng]
    }
    return $coords;
}

// Standard ray-casting point-in-polygon test. $polygon is an array of [lat, lng] pairs
// (same shape ft_parse_wkt_polygon returns). Used to attribute employee GPS pings to a
// Cartrack geofence zone, since employees have no Cartrack-side enter/exit events of
// their own — only a raw ping history in Tbl_Employee_GPS_Location.
function ft_point_in_polygon($lat, $lng, $polygon) {
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $latI = $polygon[$i][0]; $lngI = $polygon[$i][1];
        $latJ = $polygon[$j][0]; $lngJ = $polygon[$j][1];
        $intersects = (($lngI > $lng) !== ($lngJ > $lng))
            && ($lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI);
        if ($intersects) $inside = !$inside;
    }
    return $inside;
}

// Renders a label/value grid for one section of a vehicle's full record
function ft_detail_grid($pairs) {
    $out = '<div class="ft-detail-grid">';
    foreach ($pairs as $label => $value) {
        if (is_array($value)) $value = implode(', ', $value);
        $display = ($value === null || $value === '') ? '—' : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        $out .= '<div class="ft-detail-item"><span class="ft-detail-label">' . h($label) . '</span><span class="ft-detail-value">' . h($display) . '</span></div>';
    }
    $out .= '</div>';
    return $out;
}

// Deterministic badge color per status
function ft_status_color($label) {
    switch ($label) {
        case 'Moving':            return ['bg' => '#dcfce7', 'fg' => '#15803d', 'bd' => '#86efac'];
        case 'Idle (Engine On)':  return ['bg' => '#fef3c7', 'fg' => '#b45309', 'bd' => '#fcd34d'];
        default:                  return ['bg' => '#f3f4f6', 'fg' => '#374151', 'bd' => '#e5e7eb'];
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Fleet Tracking</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: #f4f5f7;
            color: #1a1d23;
            font-size: 14px;
            line-height: 1.5;
        }

        .ft-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

        /* ── Page Header ──────────────────────────────── */
        .ft-page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px;
            border-bottom: 2px solid #e2e5ea;
        }
        .ft-dept-label {
            font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px;
        }
        .ft-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
        .ft-page-title span { color: #2563eb; }

        /* ── Stat Cards ───────────────────────────────── */
        .ft-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .ft-stat-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 16px 22px; min-width: 160px; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .ft-stat-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px;
        }
        .ft-stat-value { font-size: 22px; font-weight: 700; color: #111827; }
        .ft-stat-card.accent .ft-stat-value { color: #2563eb; }
        .ft-stat-card.warn .ft-stat-value { color: #dc2626; }

        /* ── Staleness ────────────────────────────────── */
        .ft-stale-row td { opacity: 0.55; }
        .ft-stale-tag {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.04em; color: #b91c1c; margin-left: 6px;
        }

        /* ── Filter / Search Card ─────────────────────── */
        .ft-search-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 20px 24px; margin-bottom: 20px; display: flex; align-items: end;
            gap: 14px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .ft-field { display: flex; flex-direction: column; gap: 6px; }
        .ft-field label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #6b7280;
        }
        .ft-field input, .ft-field select {
            height: 42px; padding: 0 14px; border: 1.5px solid #d1d5db; border-radius: 9px;
            font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
            background: #f9fafb; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .ft-field input:focus, .ft-field select:focus {
            border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }
        .ft-btn {
            height: 42px; padding: 0 20px; border: none; border-radius: 9px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; white-space: nowrap; transition: background .15s, transform .1s;
        }
        .ft-btn:active { transform: scale(.98); }
        .ft-btn--primary { background: #2563eb; color: #fff; }
        .ft-btn--primary:hover { background: #1d4ed8; }
        .ft-btn--ghost { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
        .ft-btn--ghost:hover { background: #f3f4f6; border-color: #9ca3af; }
        .ft-result-count { font-size: 12px; color: #6b7280; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

        /* ── View Toggle ──────────────────────────────── */
        .ft-view-toggle { display: flex; gap: 4px; background: #f3f4f6; padding: 4px; border-radius: 9px; }
        .ft-view-toggle button {
            border: none; background: transparent; padding: 7px 16px; border-radius: 7px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; font-weight: 600;
            color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            transition: background .15s, color .15s;
        }
        .ft-view-toggle button.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .ft-view-toggle button:hover:not(.active) { color: #374151; }
        .ft-view-toggle a {
            border: none; background: transparent; padding: 7px 16px; border-radius: 7px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; font-weight: 600;
            color: #6b7280; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; transition: background .15s, color .15s;
        }
        .ft-view-toggle a:hover { color: #374151; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .ft-map-controls { position: absolute; top: 12px; right: 12px; z-index: 40; }
        #ft-map-section .leaflet-top, #ft-map-section .leaflet-bottom,
        #ft-geofences-section .leaflet-top, #ft-geofences-section .leaflet-bottom,
        #ft-trips-section .leaflet-top, #ft-trips-section .leaflet-bottom {
            z-index: 40;
        }

        /* ── Map ──────────────────────────────────────── */
        #ft-map { width: 100%; height: 560px; border-radius: 0; }
        .ft-map-popup { font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; min-width: 200px; }
        .ft-map-popup .ft-popup-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #111827; }
        .ft-map-popup .ft-popup-row { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; color: #4b5563; }
        .ft-map-popup .ft-popup-row span:last-child { font-family: 'IBM Plex Mono', monospace; color: #111827; }
        .ft-popup-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .ft-popup-avatar {
            width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
            border: 2px solid #e2e5ea; flex-shrink: 0; display: block;
        }
        .ft-popup-avatar-initials {
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 14px; background: #2563eb;
        }
        .ft-popup-head .ft-popup-title { margin-bottom: 0; }

        /* ── Custom Vehicle Pins ───────────────────────── */
        .ft-vehicle-pin {
            width: 34px; height: 34px; border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            display: flex; align-items: center; justify-content: center;
            border: 3px solid #fff; background: #15803d; box-shadow: 0 2px 6px rgba(0,0,0,.35);
            transition: transform .15s;
            cursor: pointer;
        }
        .ft-vehicle-pin:hover { transform: rotate(-45deg) scale(1.15); }
        .ft-vehicle-pin i { transform: rotate(45deg); color: #fff; font-size: 15px; }
        .ft-vehicle-pin.ft-pin-stale { opacity: 0.5; }
        .ft-pin-label {
            font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;
            color: #111827; background: rgba(255,255,255,.9); padding: 1px 5px;
            border-radius: 4px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }

        /* ── GPS Layer: Employee / Customer pins (merged from gps_location_map.php) ── */
        /* Pin fill color is set inline per-marker now (department color-coding), not by category class. */
        .ft-gps-pin {
            width: 28px; height: 28px; border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
        }
        .ft-gps-pin i { transform: rotate(45deg); color: #fff; font-size: 13px; }
        .ft-gps-pin-corrected { outline: 2px dashed #b91c1c; outline-offset: 2px; }
        /* Employee photo shown directly on the pin (not just the popup) */
        .ft-gps-pin-photo {
            width: 22px; height: 22px; border-radius: 50%; object-fit: cover;
            transform: rotate(45deg); border: 1px solid rgba(255,255,255,.8);
        }
        .ft-gps-pin-initials { transform: rotate(45deg); color: #fff; font-weight: 700; font-size: 11px; }

        .ft-gps-cluster {
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; color: #fff; font-weight: 700; font-size: 12px;
            border: 3px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4);
        }
        .ft-gps-cluster-employee { background: rgba(37, 99, 235, .85); }
        .ft-gps-cluster-customer { background: rgba(217, 119, 6, .85); }
        .ft-gps-cluster-other { background: rgba(107, 114, 128, .85); }

        /* ── Department color-coding legend ─────────────── */
        .ft-dept-legend {
            border-top: 1px solid #e5e7eb; margin-top: 8px; padding-top: 8px;
            display: flex; flex-direction: column; gap: 5px;
        }
        .ft-dept-legend-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 2px;
        }
        .ft-dept-legend-item {
            display: flex; align-items: center; gap: 7px; font-size: 11px;
            color: #374151; white-space: nowrap;
        }
        .ft-dept-legend-item .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

        /* ── Layer Toggle (vehicles / employees / customers on the Map tab) ── */
        /* Bottom-left, clear of Leaflet's own zoom control (top-left) */
        .ft-layer-controls {
            position: absolute; bottom: 12px; left: 12px; z-index: 40;
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 9px;
            padding: 10px 14px; box-shadow: 0 1px 4px rgba(0,0,0,.08);
            display: flex; flex-direction: column; gap: 6px;
        }
        .ft-layer-toggle {
            display: flex; align-items: center; gap: 7px; font-size: 12px;
            font-weight: 600; cursor: pointer; user-select: none; white-space: nowrap;
        }
        .ft-layer-toggle input { cursor: pointer; }
        .ft-layer-toggle .dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
        .ft-layer-toggle .dot-vehicle { background: #e8ff19; }
        .ft-layer-toggle .dot-employee { background: #2563eb; }
        .ft-layer-toggle .dot-customer { background: #d97706; }

        /* ── Section / Table Card ─────────────────────── */
        .ft-section {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .ft-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .ft-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ft-table thead th {
            background: #f8f9fb; color: #4b5563; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 12px;
            border-bottom: 1.5px solid #e2e5ea; white-space: nowrap; text-align: left;
        }
        .ft-table thead th.r { text-align: right; }
        .ft-table tbody td {
            padding: 9px 12px; border-bottom: 1px solid #f1f3f7; color: #374151; vertical-align: middle;
        }
        .ft-table tbody td.r { text-align: right; font-family: 'IBM Plex Mono', monospace; }
        .ft-table tbody tr:last-child td { border-bottom: none; }
        .ft-table tbody tr:hover td { background: #f8f9fb; }
        .ft-table td.mono { font-family: 'IBM Plex Mono', monospace; }
        .ft-fuel-low { color: #dc2626; font-weight: 600; }

        /* ── Status Badges ─────────────────────────────── */
        .ft-badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }

        /* ── Empty / Error State ───────────────────────── */
        .ft-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
        .ft-error {
            background: #fef2f2; border: 1.5px dashed #f87171; border-radius: 14px;
            padding: 18px 22px; color: #991b1b; margin-bottom: 20px;
        }

        /* ── Detail Panel ─────────────────────────────── */
        .ft-expand-btn {
            background: none; border: none; cursor: pointer; padding: 4px;
            color: #6b7280; font-size: 14px; border-radius: 6px;
        }
        .ft-expand-btn:hover { background: #f3f4f6; color: #111827; }
        .ft-detail-row td { background: #f8f9fb; padding: 0 !important; }
        .ft-detail-panel { padding: 18px 22px; display: flex; flex-wrap: wrap; gap: 24px; }
        .ft-detail-section { min-width: 200px; }
        .ft-detail-section h4 {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #2563eb; margin-bottom: 8px;
        }
        .ft-detail-grid { display: flex; flex-direction: column; gap: 4px; }
        .ft-detail-item { display: flex; gap: 8px; font-size: 12px; }
        .ft-detail-label { color: #6b7280; min-width: 130px; font-family: 'IBM Plex Mono', monospace; }
        .ft-detail-value { color: #111827; font-family: 'IBM Plex Mono', monospace; word-break: break-word; }

        @media (max-width: 640px) {
            .ft-page { padding: 16px 12px 40px; }
        }
    </style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="ft-page">

    <!-- ── Page Header ───────────────────────────────── -->
    <div class="ft-page-header">
        <div>
            <div class="ft-dept-label">Fleet &nbsp;· Live Vehicle Monitoring</div>
            <h1 class="ft-page-title">Fleet <span>Tracking</span></h1>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <a href="routes_eta.php" class="ft-btn ft-btn--primary">
                <i class="bi bi-arrow-right-circle"></i> Routes &amp; ETA
            </a>
            <?php if ($viewOnly): ?>
                <span class="ft-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;">View Only</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($apiError): ?>
        <div class="ft-error">
            Fleet data is currently unavailable (Cartrack API error <?= h($vehicleStatus['code'] ?? '') ?>).
            Please try again shortly or contact IT if this persists.
        </div>
    <?php else: ?>

    <!-- ── Stat Cards ───────────────────────────────── -->
    <div class="ft-stats">
        <div class="ft-stat-card">
            <div class="ft-stat-label">Total Vehicles</div>
            <div class="ft-stat-value"><?= number_format($TotalCount) ?></div>
        </div>
        <div class="ft-stat-card accent">
            <div class="ft-stat-label">Moving</div>
            <div class="ft-stat-value"><?= number_format($MovingCount) ?></div>
        </div>
        <div class="ft-stat-card">
            <div class="ft-stat-label">Idle (Engine On)</div>
            <div class="ft-stat-value"><?= number_format($IdleCount) ?></div>
        </div>
        <div class="ft-stat-card">
            <div class="ft-stat-label">Parked</div>
            <div class="ft-stat-value"><?= number_format($ParkedCount) ?></div>
        </div>
        <div class="ft-stat-card warn">
            <div class="ft-stat-label">Low Fuel (≤15%)</div>
            <div class="ft-stat-value"><?= number_format($LowFuelCount) ?></div>
        </div>
        <div class="ft-stat-card warn">
            <div class="ft-stat-label">Stale (24h+)</div>
            <div class="ft-stat-value"><?= number_format($StaleCount) ?></div>
        </div>
    </div>

    <!-- ── Filter Card ──────────────────────────────── -->
    <form method="get" class="ft-search-card">
        <div class="ft-field">
            <label for="Status">Status</label>
            <select id="Status" name="Status">
                <option value="" <?= $Status === '' ? 'selected' : '' ?>>All</option>
                <option value="Moving" <?= $Status === 'Moving' ? 'selected' : '' ?>>Moving</option>
                <option value="Idle (Engine On)" <?= $Status === 'Idle (Engine On)' ? 'selected' : '' ?>>Idle (Engine On)</option>
                <option value="Parked" <?= $Status === 'Parked' ? 'selected' : '' ?>>Parked</option>
            </select>
        </div>
        <div class="ft-field">
            <label for="Search">Search</label>
            <input type="text" id="Search" name="Search" value="<?= h($Search) ?>" placeholder="Plate no., location, chassis...">
        </div>
        <div class="ft-field">
            <button type="submit" class="ft-btn ft-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($Status || $Search): ?>
        <div class="ft-field">
            <a href="vehicle_status.php" class="ft-btn ft-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <div class="ft-result-count">
        <span>Showing <?= number_format($FilteredCount) ?> of <?= number_format($TotalCount) ?> vehicles · sorted by Last Update (newest first)</span>
        <div class="ft-view-toggle">
            <button type="button" id="ft-btn-table" class="active" onclick="ftSwitchView('table')">
                <i class="bi bi-table"></i> Table
            </button>
            <button type="button" id="ft-btn-map" onclick="ftSwitchView('map')">
                <i class="bi bi-geo-alt-fill"></i> Map
            </button>
            <button type="button" id="ft-btn-trips" onclick="ftSwitchView('trips')">
                <i class="bi bi-signpost-split-fill"></i> Trips
            </button>
            <button type="button" id="ft-btn-geofences" onclick="ftSwitchView('geofences')">
                <i class="bi bi-pentagon-fill"></i> Geofences
            </button>
            <button type="button" id="ft-btn-alerts" onclick="ftSwitchView('alerts')">
                <i class="bi bi-bell-fill"></i> Alerts
            </button>
        </div>
    </div>

    <!-- ── Trips View ───────────────────────────────── -->
    <div class="ft-section" id="ft-trips-section" style="display:none; margin-bottom: 20px; position: relative; z-index: 0;">
        <?php if (isset($tripsData['error'])): ?>
            <div class="ft-error" style="margin: 20px;">
                Trips endpoint returned an error (code <?= h($tripsData['code'] ?? '') ?>).
            </div>
        <?php else:
            $trips = $tripsData['data'] ?? [];
            $tripsMeta = $tripsData['meta'] ?? [];
        ?>
            <form method="get" action="#trips" style="display:flex; align-items:end; gap:14px; padding:20px; border-bottom:1.5px solid #e2e5ea; flex-wrap:wrap;">
                <input type="hidden" name="Status" value="<?= h($Status) ?>">
                <input type="hidden" name="Search" value="<?= h($Search) ?>">
                <div class="ft-field">
                    <label for="TripVehicleId">Vehicle</label>
                    <select id="TripVehicleId" name="TripVehicleId" onchange="this.form.submit()">
                        <?php foreach ($tripVehicleOptions as $opt): ?>
                            <option value="<?= h($opt['vehicle_id']) ?>" <?= (string)$TripVehicleId === (string)$opt['vehicle_id'] ? 'selected' : '' ?>>
                                <?= h($opt['registration'] ?? ('#' . $opt['vehicle_id'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="font-size:12px;color:#6b7280;padding-bottom:10px;">
                    Trips in the last 24 hours · route line is built from GPS stop-points, not a continuous breadcrumb trail — short trips may show a dashed straight line where no mid-trip stop was recorded. Grey line shows every stop the vehicle made today.
                </div>
            </form>

            <div style="display:flex; flex-wrap:wrap;">
                <div style="flex:1; min-width:320px; max-width:420px; padding:20px; border-right:1.5px solid #e2e5ea; max-height:560px; overflow-y:auto;">
                    <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">
                        <?= count($trips) ?> Trip<?= count($trips) !== 1 ? 's' : '' ?> <?= isset($tripsMeta['total']) ? '(of ' . $tripsMeta['total'] . ' total)' : '' ?>
                    </h4>
                    <?php if (empty($trips)): ?>
                        <div class="ft-empty" style="padding:20px 0;">No trips found for this vehicle in the last 24 hours.</div>
                    <?php endif; ?>
                    <?php foreach ($trips as $t): ?>
                        <div style="padding:10px 0;border-bottom:1px solid #f1f3f7;cursor:pointer;" onclick="ftFocusTrip(<?= h($t['trip_id']) ?>)">
                            <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:#111827;">
                                <span><?= h($t['start_timestamp'] ?? '—') ?></span>
                                <span class="mono"><?= h($t['trip_duration'] ?? '—') ?></span>
                            </div>
                            <div style="font-size:11px;color:#6b7280;margin:3px 0;">
                                <?= h($t['start_location'] ?? '—') ?> → <?= h($t['end_location'] ?? '—') ?>
                            </div>
                            <div style="display:flex;gap:12px;font-size:11px;color:#374151;font-family:'IBM Plex Mono',monospace;">
                                <span><?= isset($t['trip_distance']) ? number_format($t['trip_distance'] / 1000, 1) . ' km' : '—' ?></span>
                                <span>Max <?= h($t['max_speed'] ?? '—') ?> km/h</span>
                                <?php if (($t['harsh_braking_events'] ?? 0) + ($t['harsh_cornering_events'] ?? 0) + ($t['harsh_acceleration_events'] ?? 0) > 0): ?>
                                    <span style="color:#dc2626;">⚠ Harsh event</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (($tripsMeta['last_page'] ?? 1) > 1): ?>
                        <div style="display:flex;justify-content:space-between;margin-top:14px;">
                            <a class="ft-btn ft-btn--ghost" href="<?= ft_qs(['TripPage' => max(1, $TripPage - 1)]) ?>#trips" style="<?= $TripPage <= 1 ? 'pointer-events:none;opacity:.4;' : '' ?>">Prev</a>
                            <span style="font-size:12px;color:#6b7280;align-self:center;">Page <?= h($TripPage) ?> of <?= h($tripsMeta['last_page']) ?></span>
                            <a class="ft-btn ft-btn--ghost" href="<?= ft_qs(['TripPage' => min($tripsMeta['last_page'], $TripPage + 1)]) ?>#trips" style="<?= $TripPage >= $tripsMeta['last_page'] ? 'pointer-events:none;opacity:.4;' : '' ?>">Next</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="flex:3; min-width:300px;">
                    <div id="ft-trips-map" style="width:100%; height:560px;"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Geofences View ───────────────────────────── -->
    <div class="ft-section" id="ft-geofences-section" style="display:none; margin-bottom: 20px; position: relative; z-index: 0;">
        <?php if (isset($geofencesData['error'])): ?>
            <div class="ft-error" style="margin: 20px;">
                Geofences endpoint returned an error (code <?= h($geofencesData['code'] ?? '') ?>).
            </div>
        <?php else:
            $geofences = $geofencesData['data'] ?? [];
        ?>
            <div style="display:flex; flex-wrap:wrap;">
                <div style="flex:1; min-width:280px; max-width:340px; padding:20px; border-right:1.5px solid #e2e5ea;">
                    <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">
                        <?= count($geofences) ?> Zone<?= count($geofences) !== 1 ? 's' : '' ?>
                    </h4>
                    <?php foreach ($geofences as $gf):
                        $gfId = $gf['geofence_id'] ?? '';
                        $isSelected = $GeofenceId !== '' && (string)$gfId === (string)$GeofenceId;
                    ?>
                        <a href="?GeofenceId=<?= urlencode($gfId) ?>#geofences"
                           style="display:flex;align-items:center;gap:10px;padding:8px;border-radius:6px;margin:0 -8px;border-bottom:1px solid #f1f3f7;cursor:pointer;text-decoration:none;<?= $isSelected ? 'background:#eff6ff;' : '' ?>">
                            <span style="width:14px;height:14px;border-radius:4px;background:<?= h($gf['colour'] ?? '#999') ?>;flex-shrink:0;"></span>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#111827;"><?= h($gf['name'] ?: 'Unnamed Zone') ?></div>
                                <div style="font-size:11px;color:#6b7280;"><?= h($gf['position_description'] ?: '—') ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div style="flex:3; min-width:300px;">
                    <div id="ft-geofence-map" style="width:100%; height:560px;"></div>
                </div>
            </div>

            <?php if ($GeofenceId !== ''): ?>
            <div style="display:flex; flex-wrap:wrap; gap:20px; padding:20px; border-top:1.5px solid #e2e5ea;">
                <div style="flex:1; min-width:300px;">
                    <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">Visitors</h4>
                    <?php if (isset($geofenceVisitorsData['error'])): ?>
                        <div class="ft-error">Visitors endpoint returned an error (code <?= h($geofenceVisitorsData['code'] ?? '') ?>).</div>
                    <?php else:
                        $visitors = $geofenceVisitorsData['data'] ?? [];
                    ?>
                        <?php if (empty($visitors)): ?>
                            <div style="font-size:12px;color:#6b7280;">No visitors recorded.</div>
                        <?php else: ?>
                            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                                <thead><tr style="text-align:left;color:#6b7280;border-bottom:1px solid #e2e5ea;">
                                    <th style="padding:6px 4px;">Vehicle</th><th style="padding:6px 4px;">Driver</th><th style="padding:6px 4px;">Last Visit</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($visitors as $vis): ?>
                                    <tr style="border-bottom:1px solid #f1f3f7;">
                                        <td style="padding:6px 4px;"><?= h($vis['registration'] ?? $vis['vehicle_id'] ?? '—') ?></td>
                                        <td style="padding:6px 4px;"><?= h($vis['driver_name'] ?? '—') ?></td>
                                        <td style="padding:6px 4px;"><?= h($vis['last_visit'] ?? $vis['timestamp'] ?? '—') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="flex:1; min-width:300px;">
                                        <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">Visits</h4>
                    <form method="GET" action="?#geofences" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                        <input type="hidden" name="GeofenceId" value="<?= h($GeofenceId) ?>">
                        <input type="date" name="VisitFrom" value="<?= h($VisitFrom) ?>" style="font-size:12px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;">
                        <span style="font-size:12px;color:#6b7280;">to</span>
                        <input type="date" name="VisitTo" value="<?= h($VisitTo) ?>" style="font-size:12px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;">
                        <button type="submit" style="font-size:12px;padding:4px 10px;border:1px solid #d1d5db;border-radius:4px;background:#f9fafb;cursor:pointer;">Apply</button>
                    </form>
                    <?php if (!empty($rawVisits)): ?>
                        <div style="font-size:11px;color:#9ca3af;margin-bottom:6px;">Debug — first row raw: <?= h(json_encode($rawVisits[0])) ?></div>
                    <?php endif; ?>
                    <?php if (isset($geofenceVisitsData['error'])): ?>
                        <div class="ft-error">
                            Visits endpoint returned an error (code <?= h($geofenceVisitsData['code'] ?? '') ?>).<br>
                            <small style="font-family:monospace;white-space:pre-wrap;"><?= h($geofenceVisitsData['raw'] ?? '') ?></small>
                        </div>
                    <?php else:
                        $visits = $geofenceVisitsData['data'] ?? [];
                    ?>
                        <?php if (empty($visits)): ?>
                            <div style="font-size:12px;color:#6b7280;">No visits in the last 7 days.</div>
                        <?php else: ?>
                            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                                <thead><tr style="text-align:left;color:#6b7280;border-bottom:1px solid #e2e5ea;">
                                    <th style="padding:6px 4px;">Vehicle</th><th style="padding:6px 4px;">Entry</th><th style="padding:6px 4px;">Exit</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($visits as $visit): ?>
                                    <tr style="border-bottom:1px solid #f1f3f7;">
                                        <td style="padding:6px 4px;"><?= h($visit['registration'] ?? $visit['vehicle_id'] ?? '—') ?></td>
                                        <td style="padding:6px 4px;"><?= h($visit['entry_timestamp'] ?? '—') ?></td>
                                        <td style="padding:6px 4px;"><?= h($visit['exit_timestamp'] ?? '—') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding:0 20px 20px;">
                <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">
                    Employees in Zone <span style="font-weight:400;color:#9ca3af;">(ping count, <?= h($VisitFrom) ?> to <?= h($VisitTo) ?>)</span>
                </h4>
                <?php if (empty($employeePingCounts)): ?>
                    <div style="font-size:12px;color:#6b7280;">No employee pings recorded inside this zone for the selected range.</div>
                <?php else: ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <?php foreach ($employeePingCounts as $emp): ?>
                            <div style="display:flex;align-items:center;gap:8px;background:#f9fafb;border:1px solid #e2e5ea;border-radius:20px;padding:6px 14px;">
                                <span style="font-size:12px;font-weight:600;color:#111827;"><?= h($emp['name']) ?></span>
                                <span style="font-size:11px;font-weight:700;color:#2563eb;background:#eff6ff;padding:1px 8px;border-radius:10px;"><?= $emp['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="padding:0 20px 20px;">
                <h4 style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;">Recent Alerts (Entry / Exit)</h4>
                <?php if (isset($geofenceAlertsData['error'])): ?>
                    <div class="ft-error">Alerts endpoint returned an error (code <?= h($geofenceAlertsData['code'] ?? '') ?>).</div>
                <?php elseif (empty($geofenceAlerts)): ?>
                    <div style="font-size:12px;color:#6b7280;">No entry/exit alerts in this range.</div>
                <?php else: ?>
                    <table style="width:100%;font-size:12px;border-collapse:collapse;max-width:500px;">
                        <thead>
                            <tr style="text-align:left;color:#6b7280;border-bottom:1px solid #e2e5ea;">
                                <th style="padding:6px 4px;">Vehicle</th>
                                <th style="padding:6px 4px;">Event</th>
                                <th style="padding:6px 4px;">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($geofenceAlerts as $alert): ?>
                            <tr style="border-bottom:1px solid #f1f3f7;">
                                <td style="padding:6px 4px;font-weight:600;"><?= h($alert['registration']) ?></td>
                                <td style="padding:6px 4px;">
                                    <span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;
                                        <?= $alert['direction'] === 'entered' ? 'background:#dcfce7;color:#15803d;' : ($alert['direction'] === 'left' ? 'background:#fee2e2;color:#b91c1c;' : 'background:#f3f4f6;color:#6b7280;') ?>">
                                        <?= h(ucfirst($alert['direction'])) ?>
                                    </span>
                                </td>
                                <td style="padding:6px 4px;color:#6b7280;"><?= h($alert['event_ts']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

        <!-- ── Alerts View (fleet-wide, real-time entry/exit) ─── -->
    <div class="ft-section" id="ft-alerts-section" style="display:none; margin-bottom: 20px; padding: 24px;">
        <form method="GET" action="?#alerts" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
            <div class="ft-field" style="gap:2px;">
                <label style="font-size:10px;">From</label>
                <input type="date" name="AlertsFrom" value="<?= h($AlertsFrom) ?>" style="height:36px;padding:0 10px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;">
            </div>
            <div class="ft-field" style="gap:2px;">
                <label style="font-size:10px;">To</label>
                <input type="date" name="AlertsTo" value="<?= h($AlertsTo) ?>" style="height:36px;padding:0 10px;border:1.5px solid #d1d5db;border-radius:8px;font-size:13px;">
            </div>
            <button type="submit" class="ft-btn ft-btn--primary" style="height:36px;align-self:flex-end;">Apply</button>
            <?php if (!empty($fleetAlerts)): ?>
                <span style="align-self:flex-end;font-size:12px;color:#6b7280;margin-left:auto;">
                    <?= count($fleetAlerts) ?> event<?= count($fleetAlerts) !== 1 ? 's' : '' ?> across <?= count($fleetAlertsByPlate) ?> vehicle<?= count($fleetAlertsByPlate) !== 1 ? 's' : '' ?>
                </span>
            <?php endif; ?>
        </form>

        <?php if (isset($fleetAlertsData['error'])): ?>
            <div class="ft-error">Alerts endpoint returned an error (code <?= h($fleetAlertsData['code'] ?? '') ?>).</div>
        <?php elseif (empty($fleetAlertsByPlate)): ?>
            <div style="font-size:13px;color:#6b7280;padding:20px 0;">No entry/exit alerts in this range.</div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:16px;">
                <?php foreach ($fleetAlertsByPlate as $plate => $plateAlerts): ?>
                    <div style="background:#fff;border:1.5px solid #e2e5ea;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f9fafb;border-bottom:1.5px solid #e2e5ea;">
                            <span style="font-size:13px;font-weight:700;color:#111827;font-family:'IBM Plex Mono',monospace;"><?= h($plate) ?></span>
                            <span style="font-size:11px;font-weight:600;color:#6b7280;background:#eef0f3;padding:2px 8px;border-radius:10px;"><?= count($plateAlerts) ?></span>
                        </div>
                        <div style="max-height:320px;overflow-y:auto;">
                            <?php foreach ($plateAlerts as $i => $alert): ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;<?= $i > 0 ? 'border-top:1px solid #f1f3f7;' : '' ?>">
                                <span style="flex-shrink:0;width:56px;text-align:center;padding:2px 0;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;
                                    <?= $alert['direction'] === 'entered' ? 'background:#dcfce7;color:#15803d;' : ($alert['direction'] === 'left' ? 'background:#fee2e2;color:#b91c1c;' : 'background:#f3f4f6;color:#6b7280;') ?>">
                                    <?= h($alert['direction'] === 'entered' ? 'In' : ($alert['direction'] === 'left' ? 'Out' : $alert['direction'])) ?>
                                </span>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12.5px;color:#111827;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($alert['geofence']) ?></div>
                                    <div style="font-size:11px;color:#9ca3af;font-family:'IBM Plex Mono',monospace;"><?= h($alert['event_ts']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Map View ─────────────────────────────────── -->
    <div class="ft-section" id="ft-map-section" style="display:none; margin-bottom: 20px; position: relative; z-index: 0;">
        <div class="ft-map-controls ft-view-toggle">
            <button type="button" id="ft-btn-street" class="active" onclick="ftSwitchBasemap('street')">
                <i class="bi bi-map"></i> Street
            </button>
            <button type="button" id="ft-btn-satellite" onclick="ftSwitchBasemap('satellite')">
                <i class="bi bi-globe-americas"></i> Satellite
            </button>
            <a href="employee_gps_history.php">
                <i class="bi bi-person-walking"></i> Employee GPS
            </a>
        </div>
        <div class="ft-layer-controls">
            <label class="ft-layer-toggle">
                <input type="checkbox" id="ft-layer-vehicles" checked onchange="ftToggleLayer('vehicles', this.checked)">
                <span class="dot dot-vehicle"></span> Vehicles (<?= number_format($TotalCount) ?>)
            </label>
            <label class="ft-layer-toggle">
                <input type="checkbox" id="ft-layer-employees" checked onchange="ftToggleLayer('employee', this.checked)">
                <span class="dot dot-employee"></span> Employees (<?= number_format($GpsEmployeeCount) ?>)
            </label>
            <label class="ft-layer-toggle">
                <input type="checkbox" id="ft-layer-customers" checked onchange="ftToggleLayer('customer', this.checked)">
                <span class="dot dot-customer"></span> Customers (<?= number_format($GpsCustomerCount) ?>)
            </label>
            <?php if ($GpsOtherCount > 0): ?>
            <label class="ft-layer-toggle">
                <input type="checkbox" id="ft-layer-other" onchange="ftToggleLayer('other', this.checked)">
                <span class="dot" style="background:#6b7280;"></span> Other (<?= number_format($GpsOtherCount) ?>)
            </label>
            <?php endif; ?>
            <?php if (($GpsEmployeeCount + $GpsCustomerCount) > 0): ?>
            <div class="ft-dept-legend">
                <div class="ft-dept-legend-title">Pin Color · Department</div>
                <div class="ft-dept-legend-item"><span class="dot" style="background:#dc2626;"></span> Monde</div>
                <div class="ft-dept-legend-item"><span class="dot" style="background:#2563eb;"></span> Century</div>
                <div class="ft-dept-legend-item"><span class="dot" style="background:#16a34a;"></span> NutriAsia / Silver Swan</div>
                <div class="ft-dept-legend-item"><span class="dot" style="background:#ca8a04;"></span> Multilines</div>
            </div>
            <?php endif; ?>
        </div>
        <div id="ft-map"></div>
    </div>

    <!-- ── Table Section ────────────────────────────── -->
    <div class="ft-section" id="ft-table-section">
        <div class="ft-table-wrap">
            <table class="ft-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Plate No.</th>
                        <th>Chassis No.</th>
                        <th>Engine</th>
                        <th>Status</th>
                        <th>Idling</th>
                        <th class="r">Speed / Bearing</th>
                        <th class="r">GPS Fix</th>
                        <th class="r">Fuel</th>
                        <th class="r">Odometer</th>
                        <th class="r">Battery / TCU</th>
                        <th>Geofence</th>
                        <th>Location</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($vehicles): foreach ($vehicles as $v):
                        $bc = ft_status_color($v['_statusLabel']);
                        $fuelPct = $v['_fuelPct'];
                    ?>
                    <?php $vid = h($v['vehicle_id'] ?? uniqid()); ?>
                    <tr class="<?= $v['_isStale'] ? 'ft-stale-row' : '' ?>">
                        <td>
                            <button type="button" class="ft-expand-btn" onclick="ftToggleDetail('<?= $vid ?>')">
                                <i class="bi bi-caret-right-fill" id="caret-<?= $vid ?>"></i>
                            </button>
                        </td>
                        <td><strong><?= h($v['registration'] ?? ('#' . ($v['vehicle_id'] ?? '?'))) ?></strong></td>
                        <td class="mono"><?= h($v['_chassis']) ?></td>
                        <td><?= h($v['_engineType']) ?></td>
                        <td>
                            <span class="ft-badge" style="background:<?= $bc['bg'] ?>;color:<?= $bc['fg'] ?>;border:1px solid <?= $bc['bd'] ?>;">
                                <?= h($v['_statusLabel']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($v['_idling']): ?>
                                <span class="ft-badge" style="background:#fef3c7;color:#b45309;border:1px solid #fcd34d;">Idling</span>
                            <?php else: ?>
                                <span class="ft-badge" style="background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;">No</span>
                            <?php endif; ?>
                        </td>
                        <td class="r mono">
                            <?= h($v['_speed']) ?> km/h<?= $v['_bearing'] !== null ? ' @ ' . h($v['_bearing']) . '°' : '' ?>
                        </td>
                        <td class="r mono"><?= $v['_gpsFix'] !== null ? h($v['_gpsFix']) : '—' ?></td>
                        <td class="r mono <?= ($fuelPct !== null && $fuelPct <= 15) ? 'ft-fuel-low' : '' ?>">
                            <?= $fuelPct !== null ? h($fuelPct) . '%' : '—' ?>
                        </td>
                        <td class="r mono"><?= isset($v['odometer']) ? h(number_format($v['odometer'] / 1000, 1)) . ' km' : '—' ?></td>
                        <td class="r mono">
                            <?= $v['_vext'] !== null ? h($v['_vext']) . 'V' : '—' ?> / <?= $v['_tcu'] !== null ? h($v['_tcu']) . '%' : '—' ?>
                        </td>
                        <td class="mono">
                            <?php if (!empty($v['_geofenceIds'])): ?>
                                <span class="ft-badge" style="background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;" title="<?= h(implode(', ', $v['_geofenceIds'])) ?>">
                                    <?= count($v['_geofenceIds']) ?> zone<?= count($v['_geofenceIds']) > 1 ? 's' : '' ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= h($v['_position'] ?: '—') ?></td>
                        <td class="mono">
                            <?= h($v['_lastUpdate'] ?: '—') ?>
                            <?php if ($v['_isStale']): ?>
                                <span class="ft-stale-tag"><i class="bi bi-exclamation-triangle-fill"></i> Stale</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="ft-detail-row" id="detail-<?= $vid ?>" style="display:none;">
                        <td colspan="14">
                            <div class="ft-detail-panel">
                                <div class="ft-detail-section">
                                    <h4>Vehicle</h4>
                                    <?= ft_detail_grid([
                                        'vehicle_id' => $v['vehicle_id'] ?? null,
                                        'chassis_number' => $v['chassis_number'] ?? null,
                                        'engine_type' => $v['engine_type'] ?? null,
                                        'event_ts' => $v['event_ts'] ?? null,
                                    ]) ?>
                                </div>
                                <div class="ft-detail-section">
                                    <h4>Telemetry</h4>
                                    <?= ft_detail_grid([
                                        'speed' => $v['speed'] ?? null,
                                        'road_speed' => $v['road_speed'] ?? null,
                                        'bearing' => $v['bearing'] ?? null,
                                        'ignition' => $v['ignition'] ?? null,
                                        'idling' => $v['idling'] ?? null,
                                        'rpm' => $v['rpm'] ?? null,
                                        'altitude' => $v['altitude'] ?? null,
                                        'clock' => $v['clock'] ?? null,
                                        'vext' => $v['vext'] ?? null,
                                        'tcu_percentage' => $v['tcu_percentage'] ?? null,
                                        'io_panic' => $v['io_panic'] ?? null,
                                        'io_disarm' => $v['io_disarm'] ?? null,
                                        'central_locking_status' => $v['central_locking_status'] ?? null,
                                        'input_state' => $v['input_state'] ?? null,
                                        'input_state2' => $v['input_state2'] ?? null,
                                        'input_state3' => $v['input_state3'] ?? null,
                                        'dynamic1' => $v['dynamic1'] ?? null,
                                        'dynamic2' => $v['dynamic2'] ?? null,
                                        'dynamic3' => $v['dynamic3'] ?? null,
                                        'dynamic4' => $v['dynamic4'] ?? null,
                                        'temp1' => $v['temp1'] ?? null,
                                        'temp2' => $v['temp2'] ?? null,
                                        'temp3' => $v['temp3'] ?? null,
                                        'temp4' => $v['temp4'] ?? null,
                                        'last_identification_tag_id' => $v['last_identification_tag_id'] ?? null,
                                    ]) ?>
                                </div>
                                <div class="ft-detail-section">
                                    <h4>Fuel</h4>
                                    <?= ft_detail_grid([
                                        'updated' => $v['fuel']['updated'] ?? null,
                                        'level' => $v['fuel']['level'] ?? null,
                                        'precentage_left' => $v['fuel']['precentage_left'] ?? null,
                                        'total_consumed' => $v['fuel']['total_consumed'] ?? null,
                                    ]) ?>
                                </div>
                                <div class="ft-detail-section">
                                    <h4>Driver</h4>
                                    <?= ft_detail_grid([
                                        'driver_id' => $v['driver']['driver_id'] ?? null,
                                        'first_name' => $v['driver']['first_name'] ?? null,
                                        'last_name' => $v['driver']['last_name'] ?? null,
                                        'id_number' => $v['driver']['id_number'] ?? null,
                                        'license_number' => $v['driver']['license_number'] ?? null,
                                        'driver_id_tag' => $v['driver']['driver_id_tag'] ?? null,
                                        'phone_number' => $v['driver']['phone_number'] ?? null,
                                    ]) ?>
                                </div>
                                <div class="ft-detail-section">
                                    <h4>Electric</h4>
                                    <?= ft_detail_grid([
                                        'battery_percentage_left' => $v['electric']['battery_percentage_left'] ?? null,
                                        'battery_ts' => $v['electric']['battery_ts'] ?? null,
                                        'charging_status' => $v['electric']['charging_status'] ?? null,
                                        'charging_status_ts' => $v['electric']['charging_status_ts'] ?? null,
                                    ]) ?>
                                </div>
                                <div class="ft-detail-section">
                                    <h4>Location</h4>
                                    <?= ft_detail_grid([
                                        'updated' => $v['location']['updated'] ?? null,
                                        'longitude' => $v['location']['longitude'] ?? null,
                                        'latitude' => $v['location']['latitude'] ?? null,
                                        'gps_fix_type' => $v['location']['gps_fix_type'] ?? null,
                                        'position_description' => $v['location']['position_description'] ?? null,
                                        'geofence_ids' => $v['location']['geofence_ids'] ?? null,
                                    ]) ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="14" class="ft-empty">No vehicles match your filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

<script>
// Vehicle data for the map, built from the same filtered set as the table
const ftVehicles = [
    <?php foreach ($vehicles as $v):
        $lat = $v['location']['latitude'] ?? null;
        $lng = $v['location']['longitude'] ?? null;
        if ($lat === null || $lng === null) continue;
        $color = $v['_statusRank'] === 0 ? '#15803d' : ($v['_statusRank'] === 1 ? '#b45309' : '#6b7280');
    ?>
    {
        plate: <?= json_encode($v['registration'] ?? ('#' . ($v['vehicle_id'] ?? '?'))) ?>,
        lat: <?= json_encode($lat) ?>,
        lng: <?= json_encode($lng) ?>,
        status: <?= json_encode($v['_statusLabel']) ?>,
        color: <?= json_encode($color) ?>,
        fuel: <?= json_encode($v['_fuelPct']) ?>,
        speed: <?= json_encode($v['_speed']) ?>,
        position: <?= json_encode($v['_position']) ?>,
        lastUpdate: <?= json_encode($v['_lastUpdate']) ?>,
        isStale: <?= json_encode($v['_isStale']) ?>,
        geofenceCount: <?= json_encode(count($v['_geofenceIds'])) ?>
    },
    <?php endforeach; ?>
];

// Employee/customer GPS data for the map, merged from gps_location_map.php
const ftGpsLocations = <?= json_encode($gpsLocations, JSON_UNESCAPED_UNICODE) ?>;

let ftMap = null;
let ftMapInitialized = false;
let ftGeofenceMap = null;
let ftGeofenceMapInitialized = false;
let ftGeofenceLayers = {};
let ftTripsMap = null;
let ftTripsMapInitialized = false;
let ftTripMarkers = {};
let ftVehicleLayer = null;      // plain layer group — vehicle count is small, no need to cluster
let ftGpsClusterGroups = {};    // category ('employee'/'customer'/'other') -> L.markerClusterGroup

const ftGeofences = [
    <?php if (!isset($geofencesData['error'])): foreach (($geofencesData['data'] ?? []) as $gf): ?>
    {
        id: <?= json_encode($gf['geofence_id']) ?>,
        name: <?= json_encode($gf['name'] ?: 'Unnamed Zone') ?>,
        description: <?= json_encode($gf['position_description'] ?? '') ?>,
        color: <?= json_encode($gf['colour'] ?? '#3388ff') ?>,
        coords: <?= json_encode(ft_parse_wkt_polygon($gf['polygon'] ?? '')) ?>
    },
    <?php endforeach; endif; ?>
];

function ftInitGeofenceMap() {
    if (ftGeofenceMapInitialized) return;
    ftGeofenceMapInitialized = true;

    ftGeofenceMap = L.map('ft-geofence-map').setView([13.9, 121.6], 9);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(ftGeofenceMap);

    const allBounds = [];
    ftGeofences.forEach(gf => {
        if (!gf.coords.length) return;
        const polygon = L.polygon(gf.coords, {
            color: gf.color,
            fillColor: gf.color,
            fillOpacity: 0.25,
            weight: 2
        }).addTo(ftGeofenceMap);

        polygon.bindPopup(`
            <div class="ft-map-popup">
                <div class="ft-popup-title">${gf.name}</div>
                <div style="color:#6b7280;font-size:11px;">${gf.description}</div>
            </div>
        `);

        ftGeofenceLayers[gf.id] = polygon;
        gf.coords.forEach(c => allBounds.push(c));
    });

    if (allBounds.length) {
        ftGeofenceMap.fitBounds(allBounds, { padding: [30, 30] });
    }
}

function ftFocusGeofence(id) {
    const layer = ftGeofenceLayers[id];
    if (!layer || !ftGeofenceMap) return;
    ftGeofenceMap.fitBounds(layer.getBounds(), { padding: [50, 50] });
    layer.openPopup();
}

// Trips data. 'route' is built from /trips/elapsed stop-pings that fall inside this trip's
// time window (real GPS points, but stop-level granularity — often just start/end for a
// short trip). Falls back to a straight start->end line client-side when route.length < 2.
const ftTrips = [
    <?php if (!isset($tripsData['error'])): foreach (($tripsData['data'] ?? []) as $t):
        $tripStartTs = strtotime($t['start_timestamp'] ?? '');
        $tripEndTs = strtotime($t['end_timestamp'] ?? '');
        $routePoints = array_values(array_filter($elapsedPoints, function($p) use ($tripStartTs, $tripEndTs) {
            return $tripStartTs && $tripEndTs && $p['ts'] >= $tripStartTs && $p['ts'] <= $tripEndTs;
        }));
        $routeLatLng = array_map(fn($p) => [$p['lat'], $p['lng']], $routePoints);
    ?>
    {
        id: <?= json_encode($t['trip_id']) ?>,
        start: <?= json_encode([$t['start_coordinates']['latitude'] ?? null, $t['start_coordinates']['longitude'] ?? null]) ?>,
        end: <?= json_encode([$t['end_coordinates']['latitude'] ?? null, $t['end_coordinates']['longitude'] ?? null]) ?>,
        route: <?= json_encode($routeLatLng) ?>,
        startLabel: <?= json_encode(($t['start_timestamp'] ?? '') . ' — ' . ($t['start_location'] ?? '')) ?>,
        endLabel: <?= json_encode(($t['end_timestamp'] ?? '') . ' — ' . ($t['end_location'] ?? '')) ?>,
        distanceKm: <?= json_encode(isset($t['trip_distance']) ? round($t['trip_distance'] / 1000, 1) : null) ?>,
        duration: <?= json_encode($t['trip_duration'] ?? null) ?>,
        maxSpeed: <?= json_encode($t['max_speed'] ?? null) ?>
    },
    <?php endforeach; endif; ?>
];

// Whole-day stop-to-stop path for the selected vehicle (real ping data, chronological).
// Drawn as a light backdrop line so the focused trip's segment stands out on top of it.
const ftDayRoute = <?= json_encode(array_map(fn($p) => [$p['lat'], $p['lng']], $elapsedPoints)) ?>;

let ftDayRouteLine = null;
let ftTripRouteLine = null;
let ftFocusToken = 0;

function ftInitTripsMap() {
    if (ftTripsMapInitialized) return;
    ftTripsMapInitialized = true;

    ftTripsMap = L.map('ft-trips-map').setView([13.9, 121.6], 9);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(ftTripsMap);

    // Backdrop: every stop the vehicle made today, in order — real data, drawn faint.
    if (ftDayRoute.length >= 2) {
        ftDayRouteLine = L.polyline(ftDayRoute, {
            color: '#9ca3af', weight: 3, opacity: 0.6
        }).addTo(ftTripsMap);
    }

    if (ftTrips.length) {
        ftFocusTrip(ftTrips[0].id);
    } else if (ftDayRouteLine) {
        ftTripsMap.fitBounds(ftDayRouteLine.getBounds(), { padding: [40, 40] });
    }
}

function ftFocusTrip(id) {
    if (!ftTripsMap) return;
    const trip = ftTrips.find(t => t.id === id);
    if (!trip) return;

    ftFocusToken++;
    const myToken = ftFocusToken;

    Object.values(ftTripMarkers).forEach(m => ftTripsMap.removeLayer(m));
    ftTripMarkers = {};
    if (ftTripRouteLine) { ftTripsMap.removeLayer(ftTripRouteLine); ftTripRouteLine = null; }

    if (trip.start[0] === null || trip.end[0] === null) return;

    const startIcon = L.divIcon({
        className: '',
        html: `<div style="width:16px;height:16px;border-radius:50%;background:#15803d;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>`,
        iconSize: [16, 16], iconAnchor: [8, 8]
    });
    const endIcon = L.divIcon({
        className: '',
        html: `<div style="width:16px;height:16px;border-radius:50%;background:#dc2626;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);"></div>`,
        iconSize: [16, 16], iconAnchor: [8, 8]
    });

    const startMarker = L.marker(trip.start, { icon: startIcon }).addTo(ftTripsMap)
        .bindPopup(`<div class="ft-map-popup"><div class="ft-popup-title">Trip Start</div>${trip.startLabel}</div>`);
    const endMarker = L.marker(trip.end, { icon: endIcon }).addTo(ftTripsMap)
        .bindPopup(`<div class="ft-map-popup"><div class="ft-popup-title">Trip End</div>${trip.endLabel}<br>${trip.distanceKm ?? '—'} km · ${trip.duration ?? '—'} · max ${trip.maxSpeed ?? '—'} km/h</div>`);

    ftTripMarkers.start = startMarker;
    ftTripMarkers.end = endMarker;

    // Waypoints for this trip: start -> any real stop-pings inside the trip window -> end.
    const waypoints = [trip.start, ...(trip.route.length >= 2 ? trip.route : []), trip.end];

    // Draw an immediate dashed placeholder (straight segments) so the map isn't empty
    // while the real road-snapped route loads.
    ftTripRouteLine = L.polyline(waypoints, {
        color: '#2563eb', weight: 3, opacity: 0.5, dashArray: '6,8'
    }).addTo(ftTripsMap);

    ftTripsMap.fitBounds([trip.start, trip.end], { padding: [60, 60] });

    ftFetchRoadRoute(waypoints).then(roadRoute => {
        // Bail if the user focused a different trip while this was in flight.
        if (myToken !== ftFocusToken || !ftTripsMap) return;
        if (roadRoute && roadRoute.length >= 2) {
            ftTripsMap.removeLayer(ftTripRouteLine);
            ftTripRouteLine = L.polyline(roadRoute, { color: '#2563eb', weight: 4, opacity: 0.9 }).addTo(ftTripsMap);
        }
        // On failure, the dashed placeholder line stays — still real waypoints, just unsnapped.
    });
}

// Snaps a sequence of [lat,lng] waypoints to the road network via OSRM's public demo
// routing server (free, no API key — not officially rated for heavy production load,
// but fine for on-demand per-trip lookups like this). Returns [lat,lng] pairs for the
// driven route, or null on any failure so the caller can fall back to the straight line.
async function ftFetchRoadRoute(waypointsLatLng) {
    if (waypointsLatLng.length < 2) return null;
    const coordStr = waypointsLatLng.map(p => `${p[1]},${p[0]}`).join(';');
    try {
        const resp = await fetch(`https://router.project-osrm.org/route/v1/driving/${coordStr}?overview=full&geometries=geojson`);
        if (!resp.ok) return null;
        const data = await resp.json();
        if (data.code !== 'Ok' || !data.routes || !data.routes.length) return null;
        return data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
    } catch (e) {
        return null;
    }
}
let ftStreetLayer = null;
let ftSatelliteLayer = null;

function ftSwitchBasemap(type) {
    if (!ftMap) return;
    const btnStreet = document.getElementById('ft-btn-street');
    const btnSatellite = document.getElementById('ft-btn-satellite');

    if (type === 'satellite') {
        ftMap.removeLayer(ftStreetLayer);
        ftSatelliteLayer.addTo(ftMap);
        btnStreet.classList.remove('active');
        btnSatellite.classList.add('active');
    } else {
        ftMap.removeLayer(ftSatelliteLayer);
        ftStreetLayer.addTo(ftMap);
        btnSatellite.classList.remove('active');
        btnStreet.classList.add('active');
    }
}

// Cluster bubble color follows the GPS layer's category
function ftGpsClusterIconCreate(category) {
    return function (cluster) {
        const count = cluster.getChildCount();
        const size = count < 10 ? 32 : (count < 50 ? 40 : 48);
        return L.divIcon({
            html: `<div class="ft-gps-cluster ft-gps-cluster-${category}" style="width:${size}px;height:${size}px;">${count}</div>`,
            className: '',
            iconSize: [size, size]
        });
    };
}

const FT_GPS_ICONS = {
    employee: { bi: 'bi-person-fill', fallback: '#2563eb' },
    customer: { bi: 'bi-shop',        fallback: '#d97706' },
    other:    { bi: 'bi-geo-fill',    fallback: '#6b7280' }
};

// Builds the pin itself. Employees show their photo right on the pin (falling back
// to legacy path, then initials, same chain as the popup avatar); other categories
// show the category icon. Fill color is the department color-coding (or the
// category default when Department is blank/unmapped) — set inline per marker.
function ftGpsBuildIcon(loc) {
    const cfg = FT_GPS_ICONS[loc.category] || FT_GPS_ICONS.other;
    const cls = loc.corrected ? 'ft-gps-pin ft-gps-pin-corrected' : 'ft-gps-pin';
    const bg = loc.pinColor || cfg.fallback;

    let inner;
    if (loc.category === 'employee') {
        const initials = (loc.initials || '?').replace(/"/g, '&quot;');
        if (loc.pic) {
            const src = loc.pic.replace(/"/g, '&quot;');
            const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
            inner = `<img src="${src}" class="ft-gps-pin-photo" alt=""
                         data-legacy="${legacy}" data-initials="${initials}"
                         onerror="(function(img){
                             var leg = img.getAttribute('data-legacy');
                             if (leg && !img.getAttribute('data-legacy-tried')) {
                                 img.setAttribute('data-legacy-tried','1');
                                 img.src = leg;
                             } else {
                                 var span = document.createElement('span');
                                 span.className = 'ft-gps-pin-initials';
                                 span.textContent = img.getAttribute('data-initials') || '?';
                                 img.replaceWith(span);
                             }
                         })(this)">`;
        } else {
            inner = `<span class="ft-gps-pin-initials">${initials}</span>`;
        }
    } else {
        inner = `<i class="bi ${cfg.bi}"></i>`;
    }

    return L.divIcon({
        className: '',
        html: `<div class="${cls}" style="background:${bg};">${inner}</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 28],
        popupAnchor: [0, -28]
    });
}

// Employee avatar for the popup — TWM path first, legacy portal path on error,
// initials bubble if both fail (or there's no picture on file at all). Mirrors
// the resolution chain used in employee-list.php.
function ftGpsAvatarHtml(loc) {
    if (loc.category !== 'employee') return '';
    const initials = (loc.initials || '?').replace(/"/g, '&quot;');
    if (!loc.pic) {
        return `<div class="ft-popup-avatar ft-popup-avatar-initials">${initials}</div>`;
    }
    const src = loc.pic.replace(/"/g, '&quot;');
    const legacy = (loc.picLegacy || '').replace(/"/g, '&quot;');
    return `<img src="${src}" class="ft-popup-avatar" alt=""
                 data-legacy="${legacy}" data-initials="${initials}"
                 onerror="(function(img){
                     var leg = img.getAttribute('data-legacy');
                     if (leg && !img.getAttribute('data-legacy-tried')) {
                         img.setAttribute('data-legacy-tried','1');
                         img.src = leg;
                     } else {
                         var d = document.createElement('div');
                         d.className = 'ft-popup-avatar ft-popup-avatar-initials';
                         d.textContent = img.getAttribute('data-initials') || '?';
                         img.replaceWith(d);
                     }
                 })(this)">`;
}

// Toggle a layer on/off from the checkboxes in .ft-layer-controls
function ftToggleLayer(key, show) {
    if (!ftMap) return;
    const layer = key === 'vehicles' ? ftVehicleLayer : ftGpsClusterGroups[key];
    if (!layer) return;
    if (show) {
        if (!ftMap.hasLayer(layer)) ftMap.addLayer(layer);
    } else {
        if (ftMap.hasLayer(layer)) ftMap.removeLayer(layer);
    }
}

function ftInitMap() {
    if (ftMapInitialized) return;
    ftMapInitialized = true;

    // Center on the average position of all plotted vehicles + GPS points, fallback to Quezon province
    let centerLat = 13.9, centerLng = 121.6;
    const centerPoints = [...ftVehicles, ...ftGpsLocations];
    if (centerPoints.length) {
        centerLat = centerPoints.reduce((s, p) => s + p.lat, 0) / centerPoints.length;
        centerLng = centerPoints.reduce((s, p) => s + p.lng, 0) / centerPoints.length;
    }

    ftMap = L.map('ft-map').setView([centerLat, centerLng], 9);

    ftStreetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    });

    ftSatelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
        maxZoom: 19
    });

    ftStreetLayer.addTo(ftMap); // default basemap

    const bounds = [];

    // --- Vehicle layer (single flat color for all pins, same style as employee/customer pins) ---
    ftVehicleLayer = L.layerGroup();
    ftVehicles.forEach(v => {
        const icon = L.divIcon({
            className: '', // avoid Leaflet's default icon styles leaking in
            html: `
                <div style="text-align:center;">
                    <div class="ft-vehicle-pin ${v.isStale ? 'ft-pin-stale' : ''}">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="ft-pin-label">${v.plate}</div>
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
            <div class="ft-map-popup">
                <div class="ft-popup-title">${v.plate}${staleTag}</div>
                <div class="ft-popup-row"><span>Status</span><span>${v.status}</span></div>
                <div class="ft-popup-row"><span>Speed</span><span>${v.speed} km/h</span></div>
                <div class="ft-popup-row"><span>Fuel</span><span>${fuelDisplay}</span></div>
                <div class="ft-popup-row"><span>Geofences</span><span>${v.geofenceCount}</span></div>
                <div class="ft-popup-row"><span>Updated</span><span>${v.lastUpdate || '—'}</span></div>
                <div style="margin-top:6px;color:#6b7280;font-size:11px;">${v.position || ''}</div>
            </div>
        `);

        marker.addTo(ftVehicleLayer);
        bounds.push([v.lat, v.lng]);
    });
    ftVehicleLayer.addTo(ftMap);

    // --- Employee / Customer / Other GPS layers (one cluster group per category,
    //     so each toggle checkbox just shows/hides its whole layer) ---
    ['employee', 'customer', 'other'].forEach(cat => {
        ftGpsClusterGroups[cat] = L.markerClusterGroup({
            iconCreateFunction: ftGpsClusterIconCreate(cat),
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            maxClusterRadius: 50
        });
    });

    ftGpsLocations.forEach(loc => {
        const marker = L.marker([loc.lat, loc.lng], { icon: ftGpsBuildIcon(loc) });
        const avatarHtml = ftGpsAvatarHtml(loc);
        marker.bindPopup(`
            <div class="ft-map-popup">
                <div class="ft-popup-head">
                    ${avatarHtml}
                    <div class="ft-popup-title">${loc.name || '(no name)'}</div>
                </div>
                <div class="ft-popup-row"><span>Type</span><span>${loc.type || '—'}</span></div>
                <div class="ft-popup-row"><span>Department</span><span>${loc.department || '—'}</span></div>
                <div class="ft-popup-row"><span>Code</span><span>${loc.code || '—'}</span></div>
                <div class="ft-popup-row"><span>Last Update</span><span${loc.isStale ? ' style="color:#b91c1c;"' : ''}>${loc.lastUpdate || '—'}</span></div>
                ${loc.corrected ? '<div style="color:#b91c1c;margin-top:4px;font-size:11px;">⚠ Lat/Lng appeared swapped in source data — auto-corrected for display</div>' : ''}
            </div>
        `);
        const group = ftGpsClusterGroups[loc.category] || ftGpsClusterGroups.other;
        group.addLayer(marker);
        bounds.push([loc.lat, loc.lng]);
    });

    // Employees and customers are shown by default; "other" stays off unless there's
    // no dedicated toggle for it (kept out of the initial view to match the two checkboxes).
    ftGpsClusterGroups.employee.addTo(ftMap);
    ftGpsClusterGroups.customer.addTo(ftMap);

    if (bounds.length) {
        ftMap.fitBounds(bounds, { padding: [30, 30] });
    }
}

function ftSwitchView(view) {
    const sections = {
        table: document.getElementById('ft-table-section'),
        map: document.getElementById('ft-map-section'),
        trips: document.getElementById('ft-trips-section'),
        geofences: document.getElementById('ft-geofences-section'),
        alerts: document.getElementById('ft-alerts-section')
    };
    const buttons = {
        table: document.getElementById('ft-btn-table'),
        map: document.getElementById('ft-btn-map'),
        trips: document.getElementById('ft-btn-trips'),
        geofences: document.getElementById('ft-btn-geofences'),
        alerts: document.getElementById('ft-btn-alerts')
    };

    Object.keys(sections).forEach(key => {
        sections[key].style.display = (key === view) ? 'block' : 'none';
        buttons[key].classList.toggle('active', key === view);
    });

    if (view === 'map') {
        ftInitMap();
        setTimeout(() => { if (ftMap) ftMap.invalidateSize(); }, 50);
    }
    if (view === 'geofences') {
        ftInitGeofenceMap();
        setTimeout(() => { if (ftGeofenceMap) ftGeofenceMap.invalidateSize(); }, 50);
    }
    if (view === 'trips') {
        ftInitTripsMap();
        setTimeout(() => { if (ftTripsMap) ftTripsMap.invalidateSize(); }, 50);
    }
}

function ftToggleDetail(vid) {
    const row = document.getElementById('detail-' + vid);
    const caret = document.getElementById('caret-' + vid);
    if (!row) return;
    const isHidden = row.style.display === 'none';
    row.style.display = isHidden ? 'table-row' : 'none';
    if (caret) {
        caret.classList.toggle('bi-caret-right-fill', !isHidden);
        caret.classList.toggle('bi-caret-down-fill', isHidden);
    }
}

// Restore the active tab after a full page reload (Trips vehicle-select reload,
// Trips pagination Prev/Next) so the user doesn't get bounced back to Table every time.
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    if (['table', 'map', 'trips', 'geofences', 'alerts'].includes(hash)) {
        ftSwitchView(hash);
    }
    <?php if ($GeofenceId !== ''): ?>
    setTimeout(() => ftFocusGeofence(<?= json_encode($GeofenceId) ?>), 100);
    <?php endif; ?>
});
</script>
</body>
</html>