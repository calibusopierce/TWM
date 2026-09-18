<?php
/**
 * routes_eta.php
 *
 * New Fleet Tracking page: per-route ETA (from historical trip durations)
 * plus km / fuel / max speed / duration analytics and stopover dwell times.
 *
 * RBAC: mirrors vehicle_status.php exactly — auth_check() + rbac_gate()
 * against the fleet_tracking module_key.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/cartrack_client.php';
require_once __DIR__ . '/routes_eta_data.php';

auth_check();
rbac_gate($pdo, 'fleet_tracking');
$viewOnly = rbac_is_view_only('fleet_tracking');
$topbar_page = 'fleet_tracking';

// Date range drives both the vehicle table (only vehicles with trips in
// range are shown) and the route/ETA/stopover analysis. Defaults to today
// if nothing's been picked yet.
$DateFrom = $_GET['DateFrom'] ?? (new DateTime())->format('Y-m-d');
$DateTo   = $_GET['DateTo'] ?? (new DateTime())->format('Y-m-d');
$rangeStart = DateTime::createFromFormat('Y-m-d', $DateFrom) ?: new DateTime('today');
$rangeStart->setTime(0, 0, 0);
$rangeEnd = DateTime::createFromFormat('Y-m-d', $DateTo) ?: new DateTime();
$rangeEnd->setTime(23, 59, 59);

// Note: trips report start_location/end_location as plain strings, not
// geofence ids, so we no longer need the geofence list here — routes are
// grouped by those location strings directly (see routes_eta_data.php).

/**
 * Per-visit stopover dwell times (arrival + departure timestamps per stop),
 * built directly from the raw trip list. A "visit" is any point where one
 * trip's end_location matches the next trip's start_location — the same
 * consecutive-trip logic implied by compute_stopover_dwell_times()'s empty
 * state ("Not enough consecutive trips yet..."), just kept at the
 * individual-visit level instead of pre-aggregated, so the UI can show a
 * date/arrived/departed row per visit rather than only the average.
 *
 * Returns: [
 *   'Location Name' => [
 *       'avg_dwell' => int|null (seconds),
 *       'visits'    => [
 *           ['arrived_at' => DateTime, 'departed_at' => DateTime, 'dwell' => int seconds],
 *           ...
 *       ],
 *   ],
 *   ...
 * ]
 */
function build_stopover_visits(array $trips): array {
    $sorted = $trips;
    usort($sorted, fn($a, $b) => strtotime($a['start_timestamp'] ?? '') <=> strtotime($b['start_timestamp'] ?? ''));

    $byLocation = [];
    for ($i = 0; $i < count($sorted) - 1; $i++) {
        $cur  = $sorted[$i];
        $next = $sorted[$i + 1];

        $loc = $cur['end_location'] ?? null;
        if (!$loc || $loc !== ($next['start_location'] ?? null)) {
            continue; // not a consecutive stop at the same place
        }
        if (empty($cur['end_timestamp']) || empty($next['start_timestamp'])) {
            continue;
        }

        $arrived  = new DateTime($cur['end_timestamp']);
        $departed = new DateTime($next['start_timestamp']);
        $dwell    = $departed->getTimestamp() - $arrived->getTimestamp();
        if ($dwell < 0) {
            continue;
        }

        $byLocation[$loc]['visits'][] = [
            'arrived_at'  => $arrived,
            'departed_at' => $departed,
            'dwell'       => $dwell,
        ];
    }

    foreach ($byLocation as &$data) {
        $dwells = array_column($data['visits'], 'dwell');
        $data['avg_dwell'] = $dwells ? (int) round(array_sum($dwells) / count($dwells)) : null;
    }
    unset($data);

    return $byLocation;
}

/**
 * A "trip" should mean the vehicle actually went somewhere. Some records
 * start and end at the same location — that's idling, not a trip — so we
 * drop those before computing routes/stats/stopovers. If a location string
 * is missing we keep the record rather than guess.
 */
function is_actual_trip(array $trip): bool {
    $start = trim($trip['start_location'] ?? '');
    $end   = trim($trip['end_location'] ?? '');
    if ($start === '' || $end === '') {
        return true;
    }
    return strcasecmp($start, $end) !== 0;
}

/**
 * Merges individual trips and stopover visits into one chronological list,
 * so a stopover renders between the trip that ended there and the trip
 * that left from there, instead of in a disconnected section.
 */
function build_timeline(array $trips, array $stopoverVisits): array {
    $timeline = [];

    foreach ($trips as $t) {
        if (empty($t['start_timestamp'])) continue;
        $start = new DateTime($t['start_timestamp']);
        $end   = !empty($t['end_timestamp']) ? new DateTime($t['end_timestamp']) : null;
        $timeline[] = [
            'type'    => 'trip',
            'sort_ts' => $start->getTimestamp(),
            'label'   => htmlspecialchars($t['start_location'] ?? 'Unknown start') . ' &rarr; ' . htmlspecialchars($t['end_location'] ?? 'Unknown end'),
            'start'   => $start,
            'end'     => $end,
            'dur'     => $end ? ($end->getTimestamp() - $start->getTimestamp()) : null,
        ];
    }

    foreach ($stopoverVisits as $location => $data) {
        foreach ($data['visits'] as $v) {
            $timeline[] = [
                'type'     => 'stopover',
                'sort_ts'  => $v['arrived_at']->getTimestamp(),
                'location' => $location,
                'arrived'  => $v['arrived_at'],
                'departed' => $v['departed_at'],
                'dwell'    => $v['dwell'],
            ];
        }
    }

    usort($timeline, fn($a, $b) => $a['sort_ts'] <=> $b['sort_ts']);
    return $timeline;
}

// Same source as vehicle_status.php's Trips tab selector, but narrowed down
// to only vehicles that actually have trip data inside the selected range.
$vehicleStatus = cartrack_get('/vehicles/status');
$all_vehicles = $vehicleStatus['data'] ?? [];
usort($all_vehicles, fn($a, $b) => strcmp($a['registration'] ?? '', $b['registration'] ?? ''));
$vehicle_options = filter_vehicles_with_trips_in_range($all_vehicles, $rangeStart, $rangeEnd);

// Build one summary row per vehicle (all of them — no more dropdown), each
// carrying its overall stats, its routes broken out, and its stopover
// dwell times, so the expandable detail panel has everything it needs
// without hitting the API again.
$vehicle_rows = [];
foreach ($vehicle_options as $opt) {
    $trips = array_values(array_filter($opt['_trips'], 'is_actual_trip'));
    $overall = compute_route_stats($trips);
    $routes = group_trips_into_routes($trips);
    $stopover_visits = build_stopover_visits($trips);
    $timeline = build_timeline($trips, $stopover_visits);

    // If the vehicle is currently mid-trip (latest trip has no end time),
    // estimate arrival from that specific route's historical median.
    usort($trips, fn($a, $b) => strtotime($b['start_timestamp'] ?? '') <=> strtotime($a['start_timestamp'] ?? ''));
    $latest_trip = $trips[0] ?? null;
    $eta_banner = null;
    if ($latest_trip && empty($latest_trip['end_timestamp']) && !empty($latest_trip['start_timestamp'])) {
        $route_key = ($latest_trip['start_location'] ?? 'Unknown start') . '|' . ($latest_trip['end_location'] ?? 'Unknown end');
        $route_stats = isset($routes[$route_key]) ? compute_route_stats($routes[$route_key]['trips']) : null;
        $departed_at = new DateTime($latest_trip['start_timestamp']);
        $eta_banner = [
            'departed_at' => $departed_at,
            'eta'         => $route_stats ? estimate_eta($departed_at, $route_stats['median_duration']) : null,
            'sample_size' => $route_stats['trip_count'] ?? 0,
        ];
    }

    $vehicle_rows[] = [
        'vid'             => preg_replace('/[^a-zA-Z0-9]/', '', $opt['registration'] ?? uniqid()),
        'registration'    => $opt['registration'] ?? '—',
        'overall'         => $overall,
        'routes'          => $routes,
        'stopover_visits' => $stopover_visits,
        'timeline'        => $timeline,
        'eta_banner'      => $eta_banner,
    ];
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
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Routes & ETA</title>
<style>
.re-wrap { padding: 24px; font-family: 'IBM Plex Sans', sans-serif; }

/* Copied verbatim from vehicle_status.php — these live only in that file's
   own <style> block, not a shared stylesheet, so this page needs its own copy. */
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

.re-summary { font-size: 13px; color: #6b7280; margin: 0 0 12px; }
.re-empty { color: #9ca3af; font-size: 14px; padding: 20px 0; }

.re-banner { background: #eaf2fd; border-radius: 10px; padding: 16px 20px; display: flex;
             justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
.re-banner .re-label { font-size: 13px; color: #4a6fa5; margin: 0 0 4px; }
.re-banner .re-value { font-size: 18px; font-weight: 600; color: #1c3f66; margin: 0; }

/* Copied verbatim from vehicle_status.php's Table tab so this page looks
   and behaves the same, since these classes only live in that file's own
   <style> block and aren't in a shared stylesheet. */
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
.ft-expand-btn {
    background: none; border: none; cursor: pointer; padding: 4px;
    color: #6b7280; font-size: 14px; border-radius: 6px;
}
.ft-expand-btn:hover { background: #f3f4f6; color: #111827; }
.ft-detail-row td { background: #f8f9fb; padding: 0 !important; }
.ft-detail-panel { padding: 18px 22px; }
.ft-detail-panel h4 { font-size: 13px; font-weight: 600; margin: 16px 0 8px; color: #374151; }
.ft-detail-panel h4:first-child { margin-top: 0; }

/* Routes taken / stopover blocks — card layout (not a dense table) so the
   date, departed, arrived, and duration are each clearly labeled and large
   enough to scan at a glance. */
.re-block { border: 1.5px solid #e2e5ea; border-radius: 12px; margin-bottom: 16px; overflow: hidden; }
.re-block-header { padding: 12px 18px; background: #f1f3f7; }
.re-block-title { font-size: 15px; font-weight: 600; color: #111827; }
.re-block-meta { font-size: 12.5px; color: #6b7280; margin-top: 3px; }

.re-card { display: flex; flex-direction: column; gap: 10px; padding: 16px 18px; border-bottom: 1px solid #f1f3f7; }
.re-card:last-child { border-bottom: none; }
.re-card:nth-child(even) { background: #fafbfc; }
.re-card-top { display: flex; align-items: center; gap: 10px; }
.re-card-tag { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
.re-card--trip .re-card-tag { background: #eaf2fd; color: #1c3f66; }
.re-card--stopover .re-card-tag { background: #fdf3e7; color: #8a5a15; }
.re-card-label { font-size: 13.5px; font-weight: 600; color: #374151; }
.re-card-bottom { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 18px; }
.re-duration-pill--stop { background: #fdf3e7; color: #8a5a15; }

.re-field { display: flex; flex-direction: column; gap: 3px; min-width: 84px; }
.re-field-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.05em; color: #9ca3af;
}
.re-field-value { font-size: 15px; font-weight: 600; color: #111827; }
.re-field-value.re-time { font-family: 'IBM Plex Mono', monospace; font-size: 16px; }

.re-flow { display: flex; align-items: center; gap: 14px; }
.re-flow-arrow { color: #9ca3af; font-size: 18px; }

.re-duration-pill {
    background: #eaf2fd; color: #1c3f66; font-weight: 700; font-size: 14px;
    padding: 7px 16px; border-radius: 999px; white-space: nowrap;
}

@media (max-width: 640px) {
    .re-card { gap: 12px; padding: 14px; }
    .re-field { min-width: 70px; }
}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="re-wrap">
  <div class="ft-page-header">
    <div>
      <div class="ft-dept-label">Fleet &nbsp;&middot; Live Vehicle Monitoring</div>
      <h1 class="ft-page-title">Routes <span>&amp; ETA</span></h1>
    </div>
  </div>

  <form class="ft-search-card" method="get">
    <div class="ft-field">
      <label for="DateFrom">From</label>
      <input type="date" id="DateFrom" name="DateFrom" value="<?= htmlspecialchars($DateFrom) ?>">
    </div>
    <div class="ft-field">
      <label for="DateTo">To</label>
      <input type="date" id="DateTo" name="DateTo" value="<?= htmlspecialchars($DateTo) ?>">
    </div>
    <button type="submit" class="ft-btn ft-btn--primary"><i class="bi bi-funnel"></i> Apply</button>
  </form>

  <?php if (empty($vehicle_rows)): ?>
    <p class="re-empty">No vehicles have trip data in this date range.</p>
  <?php else: ?>
    <p class="re-summary">
      Showing <?= count($vehicle_rows) ?> of <?= count($all_vehicles) ?> vehicles &middot; trips between
      <?= htmlspecialchars($DateFrom) ?> and <?= htmlspecialchars($DateTo) ?>
    </p>
    <div class="ft-table-wrap">
      <table class="ft-table">
        <thead>
          <tr>
            <th></th>
            <th>Plate No.</th>
            <th class="r">Trips</th>
            <th class="r">Total distance</th>
            <th class="r">Max speed</th>
            <th class="r">Total duration</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vehicle_rows as $row): $vid = $row['vid']; $o = $row['overall']; ?>
          <tr>
            <td>
              <button type="button" class="ft-expand-btn" onclick="ftToggleDetail('<?= $vid ?>')">
                <i class="bi bi-caret-right-fill" id="caret-<?= $vid ?>"></i>
              </button>
            </td>
            <td><strong><?= htmlspecialchars($row['registration']) ?></strong></td>
            <td class="r mono"><?= $o['trip_count'] ?></td>
            <td class="r mono"><?= number_format($o['total_distance'], 1) ?> km</td>
            <td class="r mono"><?= $o['max_speed'] !== null ? number_format($o['max_speed'], 0) . ' kph' : '&mdash;' ?></td>
            <td class="r mono"><?= format_duration($o['total_duration'] ? (int) $o['total_duration'] : null) ?></td>
          </tr>
          <tr class="ft-detail-row" id="detail-<?= $vid ?>" style="display:none;">
            <td colspan="6">
              <div class="ft-detail-panel">

                <?php if ($row['eta_banner']): $eb = $row['eta_banner']; ?>
                  <div class="re-banner">
                    <div>
                      <p class="re-label">Departed <?= $eb['departed_at']->format('g:i A') ?></p>
                      <p class="re-value">Estimated arrival <?= $eb['eta'] ? $eb['eta']->format('g:i A') : '&mdash;' ?></p>
                    </div>
                    <div style="text-align:right;">
                      <p class="re-label">Based on</p>
                      <p class="re-value" style="font-size:14px;"><?= $eb['sample_size'] ?> past trips, this route</p>
                    </div>
                  </div>
                <?php endif; ?>

                <h4>Trip timeline</h4>
                <?php if ($row['timeline']): ?>
                  <div class="re-block">
                    <?php foreach ($row['timeline'] as $entry): ?>
                      <?php if ($entry['type'] === 'trip'): ?>
                        <div class="re-card re-card--trip">
                          <div class="re-card-top">
                            <span class="re-card-tag">Trip</span>
                            <span class="re-card-label"><?= $entry['label'] ?></span>
                          </div>
                          <div class="re-card-bottom">
                            <div class="re-field">
                              <span class="re-field-label">Date</span>
                              <span class="re-field-value"><?= $entry['start']->format('M j, Y') ?></span>
                            </div>
                            <div class="re-flow">
                              <div class="re-field">
                                <span class="re-field-label">Departed</span>
                                <span class="re-field-value re-time"><?= $entry['start']->format('g:i A') ?></span>
                              </div>
                              <i class="bi bi-arrow-right re-flow-arrow"></i>
                              <div class="re-field">
                                <span class="re-field-label">Arrived</span>
                                <span class="re-field-value re-time"><?= $entry['end'] ? $entry['end']->format('g:i A') : 'In progress' ?></span>
                              </div>
                            </div>
                            <div class="re-duration-pill"><?= format_duration($entry['dur']) ?></div>
                          </div>
                        </div>
                      <?php else: ?>
                        <div class="re-card re-card--stopover">
                          <div class="re-card-top">
                            <span class="re-card-tag">Stopover</span>
                            <span class="re-card-label"><?= htmlspecialchars($entry['location']) ?></span>
                          </div>
                          <div class="re-card-bottom">
                            <div class="re-field">
                              <span class="re-field-label">Date</span>
                              <span class="re-field-value"><?= $entry['arrived']->format('M j, Y') ?></span>
                            </div>
                            <div class="re-flow">
                              <div class="re-field">
                                <span class="re-field-label">Arrived</span>
                                <span class="re-field-value re-time"><?= $entry['arrived']->format('g:i A') ?></span>
                              </div>
                              <i class="bi bi-arrow-right re-flow-arrow"></i>
                              <div class="re-field">
                                <span class="re-field-label">Departed</span>
                                <span class="re-field-value re-time"><?= $entry['departed']->format('g:i A') ?></span>
                              </div>
                            </div>
                            <div class="re-duration-pill re-duration-pill--stop"><?= format_duration($entry['dwell']) ?></div>
                          </div>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="re-empty">No trips recorded for this range.</p>
                <?php endif; ?>

              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</div>
<script>
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