<?php
/**
 * routes_eta_data.php
 *
 * Aggregation layer for the "Routes & ETA" page.
 * Builds on cartrack_client.php's cartrack_get()/cartrack_write() and
 * cartrack_config.php's credentials — same pattern as vehicle_status.php.
 *
 * Cartrack does NOT provide live routing/ETA. Everything here is derived
 * from historical /trips data: a "route" is defined as a start-location ->
 * end-location string pair (trips have no geofence-id fields of their own),
 * and ETA is the average/median duration of past trips on that same route.
 *
 * TODO before going live:
 *  - Confirm exact Fuel API endpoint path + response shape against
 *    https://developer.cartrack.com/docs/fleet-api/get-fuel-used-estimate-for-a-vehicle/
 *    (placeholder path used below: /fuel/estimate) and whether it takes
 *    vehicle_id or registration as its identifier param.
 *  - start_location/end_location are free-text (likely reverse-geocoded
 *    addresses) — minor address variations for the "same" real-world stop
 *    will currently be treated as different routes/stopovers. Worth
 *    revisiting with a fuzzy-match or geofence-radius grouping later.
 */

require_once __DIR__ . '/cartrack_config.php';
require_once __DIR__ . '/cartrack_client.php';

/** Cache the geofence list for a request so we don't refetch per trip. */
function get_geofence_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $resp = cartrack_get('/geofences', []);
    foreach ($resp['data'] ?? [] as $zone) {
        // Index by geofence id for O(1) lookup when matching trip locations.
        $map[$zone['geofence_id']] = [
            'name'  => $zone['name'] ?? 'Unnamed zone',
            'color' => $zone['color'] ?? null,
        ];
    }
    return $map;
}

/**
 * Fetch trips for a vehicle within an explicit date range.
 * CONFIRMED (per vehicle_status.php, 2026-09-05): per-vehicle trips is
 * GET /trips/:registration (registration as a path segment) — the
 * fleet-wide /trips endpoint has no vehicle-scoping param at all.
 * Cartrack limits this to a 24h window per call, so this chunks the
 * range into daily calls and merges the results.
 */
function fetch_trips_for_vehicle(string $registration, DateTime $range_start, DateTime $range_end): array {
    // UNVERIFIED ASSUMPTION REMOVED: this used to chunk into daily calls on
    // the assumption that Cartrack caps /trips/:registration at a 24h
    // window. That was never actually confirmed — test this single-call
    // version against a real multi-day range first. If Cartrack truncates
    // or errors on wide ranges, reintroduce chunking, but with wide (e.g.
    // 7-day) windows instead of daily ones — not one call per day.
    $resp = cartrack_get('/trips/' . rawurlencode($registration), [
        'start_timestamp' => $range_start->format('Y-m-d H:i:s'),
        'end_timestamp'   => $range_end->format('Y-m-d H:i:s'),
    ]);

    return $resp['data'] ?? [];
}

/**
 * Given the full vehicle list from /vehicles/status, return only those with
 * at least one trip inside the given date range, each tagged with its trips
 * so callers don't have to refetch. Note: this makes one (chunked) /trips
 * call per vehicle — fine for a fleet of dozens, but worth caching or
 * capping the date range for larger fleets to avoid rate-limit issues
 * (60 calls/min per the Cartrack docs).
 */
function filter_vehicles_with_trips_in_range(array $vehicles, DateTime $range_start, DateTime $range_end): array {
    $result = [];
    foreach ($vehicles as $vehicle) {
        $registration = $vehicle['registration'] ?? '';
        if ($registration === '') {
            continue;
        }
        $trips = fetch_trips_for_vehicle($registration, $range_start, $range_end);
        if ($trips) {
            $result[] = $vehicle + ['_trips' => $trips];
        }
    }
    return $result;
}

/**
 * Fetch fuel usage estimate for a vehicle over a date range.
 * Uses the dedicated Fuel API (separate from vehicles/status's fuel %),
 * requires the vehicle to have a configured fuel sensor.
 */
function fetch_fuel_estimate(string $vehicle_id, string $start, string $end): ?array {
    $resp = cartrack_get('/fuel/estimate', [ // TODO: confirm real path
        'vehicle_id'      => $vehicle_id,
        'start_timestamp' => $start,
        'end_timestamp'   => $end,
    ]);
    if (empty($resp['data'])) {
        return null;
    }
    $row = $resp['data'][0];
    return [
        'fuel_start' => $row['fuel_level_start'] ?? null,
        'fuel_end'   => $row['fuel_level_end'] ?? null,
        'fuel_used'  => $row['fuel_used_estimate'] ?? null,
    ];
}

/**
 * Group trips into routes keyed by "start_location|end_location".
 * CONFIRMED (vehicle_status.php Trips tab): trips have start_location /
 * end_location as plain strings — there is NO start_geofence_id /
 * end_geofence_id on the trip record itself (geofence_ids only exist on
 * live /vehicles/status positions). So routes are grouped by these
 * location strings, not by geofence matching.
 */
function group_trips_into_routes(array $trips): array {
    $routes = [];
    foreach ($trips as $trip) {
        $start = $trip['start_location'] ?? 'Unknown start';
        $end   = $trip['end_location'] ?? 'Unknown end';
        $key = $start . '|' . $end;

        $routes[$key]['label'] = $start . ' &rarr; ' . $end;
        $routes[$key]['trips'][] = $trip;
    }
    return $routes;
}

/**
 * Compute summary stats for one route's set of trips.
 * CONFIRMED fields (vehicle_status.php Trips tab): trip_distance (metres),
 * max_speed (km/h, direct field — no need to derive from events),
 * start_timestamp / end_timestamp. Duration is computed from the two
 * timestamps rather than parsed from the display-only trip_duration
 * string, whose exact format isn't confirmed.
 */
function compute_route_stats(array $trips): array {
    $distances = [];
    $durations = [];
    $max_speeds = [];

    foreach ($trips as $trip) {
        if (isset($trip['trip_distance'])) {
            $distances[] = (float) $trip['trip_distance'] / 1000; // metres -> km
        }
        if (!empty($trip['start_timestamp']) && !empty($trip['end_timestamp'])) {
            $secs = strtotime($trip['end_timestamp']) - strtotime($trip['start_timestamp']);
            if ($secs > 0) {
                $durations[] = $secs;
            }
        }
        if (isset($trip['max_speed'])) {
            $max_speeds[] = (float) $trip['max_speed'];
        }
    }

    sort($durations);
    $count = count($durations);

    return [
        'trip_count'      => count($trips),
        'total_distance'  => array_sum($distances),
        'avg_distance'    => $distances ? array_sum($distances) / count($distances) : null,
        'total_duration'  => $durations ? array_sum($durations) : null,
        'median_duration' => $durations ? $durations[intdiv($count, 2)] : null, // still used for ETA estimates
        'max_speed'       => $max_speeds ? max($max_speeds) : null,
    ];
}

/**
 * Compute typical dwell time at each stopover, derived from the gap
 * between one trip's end_timestamp and the NEXT trip's start_timestamp —
 * i.e. however long the vehicle sat still between trips. Trips have no
 * idle_time or dwell field of their own, so this is the closest available
 * proxy. Gaps over MAX_DWELL_SECONDS (24h) are excluded so a weekend or
 * multi-day gap between trips doesn't blow out the average.
 */
function compute_stopover_dwell_times(array $trips): array {
    $max_dwell_seconds = 24 * 3600;

    usort($trips, fn($a, $b) => strtotime($a['start_timestamp'] ?? '') <=> strtotime($b['start_timestamp'] ?? ''));

    $stops = [];
    for ($i = 0; $i < count($trips) - 1; $i++) {
        $this_trip = $trips[$i];
        $next_trip = $trips[$i + 1];

        if (empty($this_trip['end_timestamp']) || empty($next_trip['start_timestamp'])) {
            continue;
        }
        $dwell = strtotime($next_trip['start_timestamp']) - strtotime($this_trip['end_timestamp']);
        if ($dwell <= 0 || $dwell > $max_dwell_seconds) {
            continue;
        }

        $name = $this_trip['end_location'] ?? 'Unnamed stop';
        $stops[$name]['total_dwell'] = ($stops[$name]['total_dwell'] ?? 0) + $dwell;
        $stops[$name]['visits'] = ($stops[$name]['visits'] ?? 0) + 1;
    }

    $result = [];
    foreach ($stops as $name => $agg) {
        $result[] = [
            'name'      => $name,
            'avg_dwell' => (int) round($agg['total_dwell'] / $agg['visits']),
            'visits'    => $agg['visits'],
        ];
    }
    // Sort by most-visited first, so the busiest stopovers surface at the top.
    usort($result, fn($a, $b) => $b['visits'] <=> $a['visits']);
    return $result;
}

/**
 * Estimate ETA given a departure time and a route's historical median duration.
 */
function estimate_eta(DateTime $departed_at, ?int $median_duration_seconds): ?DateTime {
    if ($median_duration_seconds === null) {
        return null;
    }
    return (clone $departed_at)->modify("+{$median_duration_seconds} seconds");
}

function format_duration(?int $seconds): string {
    if ($seconds === null) {
        return '&mdash;';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}