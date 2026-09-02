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

$vehicleStatus = cartrack_get('/vehicles/status');
if (isset($vehicleStatus['error'])) {
    error_log('Cartrack API error [' . $vehicleStatus['code'] . ']: ' . $vehicleStatus['raw']);
}
$apiError = isset($vehicleStatus['error']);

// NOTE: endpoint paths below are guesses based on the docblock example in cartrack_client.php —
// not yet confirmed against Cartrack's actual API docs. If these 404, the real path needs
// to be found in Cartrack's API reference before the Trips/Geofences tabs can show real data.
// --- Trips: vehicle selector + fetch ---
$tripVehicleOptions = $vehicleStatus['data'] ?? [];
usort($tripVehicleOptions, fn($a, $b) => strcmp($a['registration'] ?? '', $b['registration'] ?? ''));

$TripVehicleId = $_GET['TripVehicleId'] ?? '';
$TripPage = max(1, (int)($_GET['TripPage'] ?? 1));

if ($TripVehicleId === '' && !empty($tripVehicleOptions)) {
    $TripVehicleId = $tripVehicleOptions[0]['vehicle_id'] ?? '';
}

$tripsParams = [
    'start_timestamp' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    'end_timestamp'   => date('Y-m-d H:i:s'),
    'page'            => $TripPage,
];
// NOTE: 'filter[vehicle_id]' is a guess based on cartrack_client.php's original docblock example —
// not yet confirmed against Cartrack's official API docs.
if ($TripVehicleId !== '') {
    $tripsParams['filter[vehicle_id]'] = $TripVehicleId;
}

$tripsData = cartrack_get('/trips', $tripsParams);
$geofencesData = cartrack_get('/geofences');

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
        .ft-map-controls { position: absolute; top: 12px; right: 12px; z-index: 1000; }

        /* ── Map ──────────────────────────────────────── */
        #ft-map { width: 100%; height: 560px; border-radius: 0; }
        .ft-map-popup { font-family: 'IBM Plex Sans', sans-serif; font-size: 12px; min-width: 200px; }
        .ft-map-popup .ft-popup-title { font-weight: 700; font-size: 14px; margin-bottom: 6px; color: #111827; }
        .ft-map-popup .ft-popup-row { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; color: #4b5563; }
        .ft-map-popup .ft-popup-row span:last-child { font-family: 'IBM Plex Mono', monospace; color: #111827; }

        /* ── Custom Vehicle Pins ───────────────────────── */
        .ft-vehicle-pin {
            width: 34px; height: 34px; border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            display: flex; align-items: center; justify-content: center;
            border: 3px solid; box-shadow: 0 2px 6px rgba(0,0,0,.35);
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
        <?php if ($viewOnly): ?>
            <span class="ft-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;">View Only</span>
        <?php endif; ?>
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
        </div>
    </div>

    <!-- ── Trips View ───────────────────────────────── -->
    <div class="ft-section" id="ft-trips-section" style="display:none; margin-bottom: 20px;">
        <?php if (isset($tripsData['error'])): ?>
            <div class="ft-error" style="margin: 20px;">
                Trips endpoint returned an error (code <?= h($tripsData['code'] ?? '') ?>).
            </div>
        <?php else:
            $trips = $tripsData['data'] ?? [];
            $tripsMeta = $tripsData['meta'] ?? [];
        ?>
            <form method="get" style="display:flex; align-items:end; gap:14px; padding:20px; border-bottom:1.5px solid #e2e5ea; flex-wrap:wrap;">
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
    <div class="ft-section" id="ft-geofences-section" style="display:none; margin-bottom: 20px;">
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
                    <?php foreach ($geofences as $gf): ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f3f7;cursor:pointer;"
                             onclick="ftFocusGeofence('<?= h($gf['geofence_id']) ?>')">
                            <span style="width:14px;height:14px;border-radius:4px;background:<?= h($gf['colour'] ?? '#999') ?>;flex-shrink:0;"></span>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#111827;"><?= h($gf['name'] ?: 'Unnamed Zone') ?></div>
                                <div style="font-size:11px;color:#6b7280;"><?= h($gf['position_description'] ?: '—') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="flex:3; min-width:300px;">
                    <div id="ft-geofence-map" style="width:100%; height:560px;"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Map View ─────────────────────────────────── -->
    <div class="ft-section" id="ft-map-section" style="display:none; margin-bottom: 20px; position: relative;">
        <div class="ft-map-controls ft-view-toggle">
            <button type="button" id="ft-btn-street" class="active" onclick="ftSwitchBasemap('street')">
                <i class="bi bi-map"></i> Street
            </button>
            <button type="button" id="ft-btn-satellite" onclick="ftSwitchBasemap('satellite')">
                <i class="bi bi-globe-americas"></i> Satellite
            </button>
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

let ftMap = null;
let ftMapInitialized = false;
let ftGeofenceMap = null;
let ftGeofenceMapInitialized = false;
let ftGeofenceLayers = {};
let ftTripsMap = null;
let ftTripsMapInitialized = false;
let ftTripMarkers = {};

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

    // Real ping-based route if we have 2+ points inside this trip's window; otherwise a
    // dashed straight line so it's visually clear this segment is an approximation.
    if (trip.route.length >= 2) {
        ftTripRouteLine = L.polyline(trip.route, { color: '#2563eb', weight: 4, opacity: 0.9 }).addTo(ftTripsMap);
    } else {
        ftTripRouteLine = L.polyline([trip.start, trip.end], {
            color: '#2563eb', weight: 3, opacity: 0.7, dashArray: '6,8'
        }).addTo(ftTripsMap);
    }

    ftTripsMap.fitBounds([trip.start, trip.end], { padding: [60, 60] });
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

function ftInitMap() {
    if (ftMapInitialized) return;
    ftMapInitialized = true;

    // Center on the average position of all plotted vehicles, fallback to Quezon province
    let centerLat = 13.9, centerLng = 121.6;
    if (ftVehicles.length) {
        centerLat = ftVehicles.reduce((s, v) => s + v.lat, 0) / ftVehicles.length;
        centerLng = ftVehicles.reduce((s, v) => s + v.lng, 0) / ftVehicles.length;
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

    // Deterministic color per vehicle, so the same plate always gets the same pin color across reloads
    function ftColorForPlate(plate) {
        let hash = 0;
        for (let i = 0; i < plate.length; i++) {
            hash = plate.charCodeAt(i) + ((hash << 5) - hash);
        }
        const hue = Math.abs(hash) % 360;
        return `hsl(${hue}, 70%, 45%)`;
    }

    const bounds = [];
    ftVehicles.forEach(v => {
        const pinColor = ftColorForPlate(v.plate);

        const icon = L.divIcon({
            className: '', // avoid Leaflet's default icon styles leaking in
            html: `
                <div style="text-align:center;">
                    <div class="ft-vehicle-pin ${v.isStale ? 'ft-pin-stale' : ''}"
                         style="background:${pinColor}; border-color:${v.color};">
                        <i class="bi bi-truck"></i>
                    </div>
                    <div class="ft-pin-label">${v.plate}</div>
                </div>
            `,
            iconSize: [34, 48],
            iconAnchor: [17, 40],
            popupAnchor: [0, -40]
        });

        const marker = L.marker([v.lat, v.lng], { icon }).addTo(ftMap);

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

        bounds.push([v.lat, v.lng]);
    });

    if (bounds.length) {
        ftMap.fitBounds(bounds, { padding: [30, 30] });
    }
}

function ftSwitchView(view) {
    const sections = {
        table: document.getElementById('ft-table-section'),
        map: document.getElementById('ft-map-section'),
        trips: document.getElementById('ft-trips-section'),
        geofences: document.getElementById('ft-geofences-section')
    };
    const buttons = {
        table: document.getElementById('ft-btn-table'),
        map: document.getElementById('ft-btn-map'),
        trips: document.getElementById('ft-btn-trips'),
        geofences: document.getElementById('ft-btn-geofences')
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
</script>
</body>
</html>