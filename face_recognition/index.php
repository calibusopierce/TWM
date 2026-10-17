<?php
// ══════════════════════════════════════════════════════════════════════════════
// face_recognition/index.php — Access Control & Face Recognition Dashboard
// ══════════════════════════════════════════════════════════════════════════════

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';

auth_check();
rbac_gate($pdo, 'face_recognition');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

$isViewOnly   = rbac_is_view_only('face_recognition');
$userType     = $_SESSION['UserType'] ?? '';
$displayName  = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

// ── Hikvision Device Configuration ───────────────────────────────────────────
require_once __DIR__ . '/hik_config.php';

// ── Create cache directory ───────────────────────────────────────────────────
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// ── Cache helper function ───────────────────────────────────────────────────
function getCachedOrFetch($api, $method, $cacheKey, $ttl = 300) {
    $cacheFile = __DIR__ . '/cache/' . $cacheKey . '.json';

    // Check if cache exists and is fresh
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && !isset($cached['error'])) {
            return $cached;
        }
    }

    // Fetch fresh data
    $result = $api->$method();
    if ($result && !isset($result['error'])) {
        file_put_contents($cacheFile, json_encode($result));
    }
    return $result;
}

// ── Include Hikvision API Handler ────────────────────────────────────────────
require_once __DIR__ . '/HikvisionAPI.php';

$api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

// ── Get device info with caching (5 minute TTL) ─────────────────────────────
$deviceInfo = getCachedOrFetch($api, 'getDeviceInfo', 'device_info', 300);
$deviceName = isset($deviceInfo['deviceName']) ? $deviceInfo['deviceName'] : 'HIKVISION';

if (!$deviceInfo || isset($deviceInfo['error'])) {
    $apiError = 'Could not connect to device';
    if ($deviceInfo && isset($deviceInfo['error'])) {
        $apiError = $deviceInfo['error'];
    }
} else {
    $apiError = null;
}

// ── Get filter parameters ────────────────────────────────────────────────────
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d') . 'T00:00:00';
$endDate   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d') . 'T23:59:59';
$page      = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage   = 24;
$eventType = isset($_GET['event_type']) ? $_GET['event_type'] : 'all';
$employeeId = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';

// ── Fetch events (no caching for events - need real-time data) ──────────────
$eventTypes = array();
if ($eventType !== 'all') {
    $eventTypes = array($eventType);
}

// Only fetch events if device is not locked
$events = array();
$total = 0;
$totalPages = 1;

if (!$apiError) {
    $result = $api->getAccessEvents(
        $startDate,
        $endDate,
        $eventTypes,
        $perPage,
        ($page - 1) * $perPage
    );

    if (isset($result['error'])) {
        $apiError = $result['error'];
    } else {
        $events = isset($result['events']) ? $result['events'] : array();
        $total = isset($result['total']) ? $result['total'] : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));

        // NOTE: thumbnails are served by face_image.php, which reads
        // cache/photos/<serialNo>.jpg first. That directory is filled by
        // hik_receiver.php — the endpoint the device POSTs each event to
        // via HTTP Listening, capture attached. Pulling the picture after
        // the fact (GET on the event's pictureURL) does not work reliably
        // on this firmware: the device's own web UI renders a frame that
        // the same GET from PHP 404s on minutes later, so whatever the
        // "@WEB..." handle is scoped to, we're not in scope. The live
        // fetch in face_image.php is now only a fallback for events that
        // predate the receiver; expect it to fail and show the icon.

        // NOTE: filters below apply only to the current page of already-fetched
        // events, since the device search condition ('major'/'minor' — see
        // HikvisionAPI::getAccessEvents) can't distinguish 'face' vs 'door' (they
        // share major=5) or filter by employee server-side. Total/pagination
        // reflect the on-page filtered count, not a true filtered total across
        // all pages — revisit if these need to work correctly across pages.
        if ($eventType !== 'all') {
            $events = array_values(array_filter($events, function($e) use ($eventType) {
                if ($eventType === 'face') return strpos($e['eventType'], 'Face') !== false;
                if ($eventType === 'door') return strpos($e['eventType'], 'Door') !== false;
                return true;
            }));
            $total = count($events);
            $totalPages = max(1, (int)ceil($total / $perPage));
        }

        if ($employeeId !== '') {
            $events = array_values(array_filter($events, function($e) use ($employeeId) {
                return $e['employeeID'] === $employeeId;
            }));
            $total = count($events);
            $totalPages = max(1, (int)ceil($total / $perPage));
        }
    }
}

// ── Get employees for filter dropdown with caching (10 minute TTL) ──────────
$persons = getCachedOrFetch($api, 'getPersons', 'persons', 600);
$employees = isset($persons['persons']) ? $persons['persons'] : array();

// ── Stats ────────────────────────────────────────────────────────────────────
$todayEvents = array_filter($events, function($e) {
    return strpos($e['time'], date('Y-m-d')) === 0;
});

$stats = array(
    'total' => $total,
    'today' => count($todayEvents),
    'face_auth' => count(array_filter($events, function($e) { return strpos($e['eventType'], 'Face') !== false; })),
    'door_unlocks' => count(array_filter($events, function($e) { return $e['eventType'] === 'Door Unlocked'; }))
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Recognition Access Control</title>
    <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
    <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <style>
        :root {
            --bg-0: #060d1f;
            --bg-1: #0b1530;
            --bg-2: #101d3e;
            --surface: rgba(255,255,255,0.04);
            --border: rgba(255,255,255,0.08);
            --border2: rgba(255,255,255,0.14);
            --white: #ffffff;
            --w60: rgba(255,255,255,.60);
            --w40: rgba(255,255,255,.40);
            --w15: rgba(255,255,255,.15);
            --w08: rgba(255,255,255,.08);
            --accent: #4380e2;
            --accent2: #93c5fd;
            --green: #34d399;
            --amber: #fbbf24;
            --red: #f87171;
            --purple: #a78bfa;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg-0);
            color: var(--white);
        }

        /* ── Background Mesh ── */
        .mesh {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 80% 50% at 10% 0%, rgba(67,128,226,.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 90% 100%, rgba(52,211,153,.10) 0%, transparent 60%),
                radial-gradient(ellipse 100% 80% at 50% 50%, rgba(6,13,31,1) 40%, transparent 100%);
        }

        /* ── Layout ── */
        .wrap { position: relative; z-index: 10; max-width: 1400px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }

        /* ── Header ── */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;
            animation: fadeUp .4s ease both;
        }
        .breadcrumb { font-size: .72rem; color: var(--w40); letter-spacing: .06em; text-transform: uppercase; margin-bottom: .4rem; }
        .breadcrumb a { color: var(--accent2); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .page-title { font-family: 'Sora', sans-serif; font-size: 1.75rem; font-weight: 800; letter-spacing: -.04em; }
        .page-title span { color: var(--accent2); }
        .page-sub { font-size: .82rem; color: var(--w60); margin-top: .35rem; }
        .page-header-right { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .5rem 1.1rem; border-radius: 10px;
            font-size: .8rem; font-weight: 600; cursor: pointer;
            border: 1px solid transparent; transition: background .2s, border-color .2s, color .2s;
            text-decoration: none; font-family: 'DM Sans', sans-serif;
        }
        .btn-primary { background: var(--accent); color: #fff; border-color: rgba(67,128,226,.5); }
        .btn-primary:hover { background: #3370d4; }
        .btn-ghost { background: var(--w08); color: var(--w60); border-color: var(--border); }
        .btn-ghost:hover { background: var(--w15); color: var(--white); }
        .btn-success { background: rgba(52,211,153,.12); color: #34d399; border: 1px solid rgba(52,211,153,.25); }
        .btn-success:hover { background: rgba(52,211,153,.22); }
        .btn-danger { background: rgba(239,68,68,.15); color: #fca5a5; border-color: rgba(239,68,68,.3); }
        .btn-danger:hover { background: rgba(239,68,68,.25); }
        .btn-sm { padding: .3rem .7rem; font-size: .72rem; }

        /* ── Stats ── */
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem; margin-bottom: 2rem;
            animation: fadeUp .4s .1s ease both;
        }
        .stat-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; padding: 1.25rem 1.5rem;
        }
        .stat-card .stat-value {
            font-family: 'Sora', sans-serif;
            font-size: 1.75rem; font-weight: 700;
            margin-bottom: .25rem;
        }
        .stat-card .stat-label {
            font-size: .75rem; color: var(--w60);
            text-transform: uppercase; letter-spacing: .05em;
        }
        .stat-card .stat-value.blue { color: var(--accent2); }
        .stat-card .stat-value.green { color: var(--green); }
        .stat-card .stat-value.amber { color: var(--amber); }
        .stat-card .stat-value.purple { color: var(--purple); }

        /* ── Filter Section ── */
        .filter-section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; padding: 1.25rem 1.5rem;
            margin-bottom: 2rem;
            animation: fadeUp .4s .15s ease both;
        }
        .filter-form {
            display: flex; flex-wrap: wrap; gap: 1rem;
            align-items: flex-end;
        }
        .filter-group {
            display: flex; flex-direction: column; gap: .25rem;
        }
        .filter-group label {
            font-size: .7rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: .06em; color: var(--w40);
        }
        .filter-group input, .filter-group select {
            padding: .5rem .85rem; border-radius: 10px;
            border: 1px solid var(--border2);
            background: var(--w08); color: var(--white);
            font-size: .8rem; min-width: 160px;
            font-family: 'DM Sans', sans-serif;
            outline: none;
        }
        .filter-group input:focus, .filter-group select:focus {
            border-color: var(--accent);
        }
        .filter-group input::placeholder { color: var(--w40); }
        .filter-group select option { background: #0f1c3a; }

        /* ── Table ── */
        .table-container {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
            animation: fadeUp .4s .2s ease both;
        }
        .table-container table {
            width: 100%; border-collapse: collapse;
        }
        .table-container th {
            padding: .85rem 1.25rem; text-align: left;
            font-size: .68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--w40);
            background: rgba(255,255,255,.03);
            border-bottom: 1px solid var(--border);
        }
        .table-container td {
            padding: .85rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,.04);
            font-size: .85rem;
            color: var(--w60);
        }
        .table-container tr:hover td { background: rgba(255,255,255,.04); }
        .table-container td.name-cell { color: var(--white); font-weight: 600; }
        .table-container td.mono { font-family: monospace; font-size: .78rem; }

        .event-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .25rem .8rem; border-radius: 999px;
            font-size: .7rem; font-weight: 700;
        }
        .event-badge.face { background: rgba(147,197,253,.15); color: var(--accent2); }
        .event-badge.unlock { background: rgba(52,211,153,.15); color: var(--green); }
        .event-badge.lock { background: rgba(248,113,113,.15); color: var(--red); }
        .event-badge.denied { background: rgba(251,191,36,.15); color: var(--amber); }
        .event-badge.default { background: var(--w08); color: var(--w40); }

        .status-dot {
            display: inline-block; width: 8px; height: 8px;
            border-radius: 50%; margin-right: .5rem;
        }
        .status-dot.success { background: var(--green); }
        .status-dot.failed { background: var(--red); }

        /* ── Event Photo Thumbnail ── */
        .photo-cell { width: 56px; }
        .photo-thumb {
            width: 44px; height: 44px; border-radius: 8px;
            object-fit: cover; cursor: pointer;
            border: 1px solid var(--border2); background: var(--w08);
            display: block; transition: transform .15s, border-color .15s;
        }
        .photo-thumb:hover { transform: scale(1.06); border-color: var(--accent); }
        .photo-placeholder {
            width: 44px; height: 44px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: var(--w08); border: 1px solid var(--border);
            color: var(--w40); font-size: 1rem;
        }

        /* ── Lightbox ── */
        .lightbox-overlay {
            display: none; position: fixed; inset: 0; z-index: 1000;
            background: rgba(6,13,31,.88);
            align-items: center; justify-content: center; padding: 2rem;
        }
        .lightbox-overlay.open { display: flex; }
        .lightbox-overlay img {
            max-width: min(90vw, 640px); max-height: 85vh;
            border-radius: 12px; border: 1px solid var(--border2);
        }
        .lightbox-close {
            position: absolute; top: 1.25rem; right: 1.5rem;
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--w08); border: 1px solid var(--border2);
            color: var(--white); font-size: 1.2rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }

        /* ── Pagination ── */
        .pagination-section {
            display: flex; justify-content: space-between; align-items: center;
            padding: 1rem 1.25rem; flex-wrap: wrap; gap: .75rem;
            border-top: 1px solid var(--border);
        }
        .pagination-info {
            font-size: .78rem; color: var(--w40);
        }
        .pagination-info strong { color: var(--white); }
        .pagination-controls {
            display: flex; gap: .35rem; align-items: center;
        }
        .page-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 8px;
            font-size: .8rem; font-weight: 600;
            border: 1px solid var(--border); background: var(--w08);
            color: var(--w60); cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
            font-family: 'DM Sans', sans-serif; text-decoration: none;
        }
        .page-btn:hover:not(:disabled):not(.active) {
            background: var(--accent); border-color: var(--accent); color: #fff;
        }
        .page-btn:disabled { opacity: .3; cursor: not-allowed; }
        .page-btn.active {
            background: var(--accent); border-color: var(--accent); color: #fff;
        }
        .page-ellipsis { color: var(--w40); padding: 0 .2rem; }

        /* ── Animations ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .page-title { font-size: 1.35rem; }
            .wrap { padding: 1.25rem 1rem 3rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .filter-form { flex-direction: column; }
            .filter-group { width: 100%; }
            .filter-group input, .filter-group select { width: 100%; }
        }
    </style>
</head>
<body>
<div class="mesh"></div>
<div class="wrap">

    <!-- ══ PAGE HEADER ══════════════════════════════════════════════════════ -->
    <div class="page-header">
        <div>
            <div class="breadcrumb">
                <a href="<?= route('home') ?>">Home</a> &rsaquo;
                <a href="<?= route('dashboard') ?>">Dashboard</a> &rsaquo;
                Face Recognition
            </div>
            <div class="page-title">Face Recognition <span>Access Control</span></div>
            <div class="page-sub"><?= htmlspecialchars($deviceName) ?> · Live access events and monitoring</div>
        </div>
        <div class="page-header-right">
            <?php if (!$isViewOnly): ?>
            <button class="btn btn-success" onclick="refreshEvents()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
            <a href="manage.php" class="btn btn-primary">
                <i class="bi bi-people-fill"></i> Manage Users
            </a>
            <?php endif; ?>
            <a href="<?= route('home') ?>" class="btn btn-ghost">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- ══ ERROR BANNER ═════════════════════════════════════════════════════ -->
    <?php if (isset($apiError)): ?>
    <div style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:1rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:flex-start;gap:1rem;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#f87171;font-size:1.5rem;flex-shrink:0;margin-top:2px;"></i>
        <div>
            <strong style="color:#fca5a5;">Connection Error:</strong>
            <span style="color:var(--w60);"><?= htmlspecialchars($apiError) ?></span>
            <?php if (strpos($apiError, 'locked') !== false): ?>
            <div style="font-size:.85rem;color:var(--amber);margin-top:.5rem;">
                <i class="bi bi-clock"></i>
                The device is temporarily locked. Please wait a few minutes and refresh the page.
            </div>
            <?php endif; ?>
            <div style="font-size:.75rem;color:var(--w40);margin-top:.25rem;">
                Check that the Hikvision device is on the network and credentials are correct.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ STATS ════════════════════════════════════════════════════════════ -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value blue"><?= number_format($stats['total']) ?></div>
            <div class="stat-label">Total Events</div>
        </div>
        <div class="stat-card">
            <div class="stat-value green"><?= number_format($stats['today']) ?></div>
            <div class="stat-label">Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-value amber"><?= number_format($stats['face_auth']) ?></div>
            <div class="stat-label">Face Authentications</div>
        </div>
        <div class="stat-card">
            <div class="stat-value purple"><?= number_format($stats['door_unlocks']) ?></div>
            <div class="stat-label">Door Unlocks</div>
        </div>
    </div>

    <!-- ══ FILTERS ══════════════════════════════════════════════════════════ -->
    <div class="filter-section">
        <form class="filter-form" method="GET">
            <div class="filter-group">
                <label><i class="bi bi-calendar"></i> Start Time</label>
                <input type="datetime-local" name="start_date"
                       value="<?= htmlspecialchars($startDate) ?>">
            </div>
            <div class="filter-group">
                <label><i class="bi bi-calendar"></i> End Time</label>
                <input type="datetime-local" name="end_date"
                       value="<?= htmlspecialchars($endDate) ?>">
            </div>
            <div class="filter-group">
                <label><i class="bi bi-tag"></i> Event Type</label>
                <select name="event_type">
                    <option value="all" <?= $eventType === 'all' ? 'selected' : '' ?>>All Events</option>
                    <option value="face" <?= $eventType === 'face' ? 'selected' : '' ?>>Face Auth</option>
                    <option value="door" <?= $eventType === 'door' ? 'selected' : '' ?>>Door Events</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="bi bi-person"></i> Employee</label>
                <select name="employee_id">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= htmlspecialchars($emp['employeeId']) ?>"
                                <?= $employeeId === $emp['employeeId'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['employeeId']) ?> - <?= htmlspecialchars($emp['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-funnel"></i> Apply Filters
            </button>
            <a href="?" class="btn btn-ghost">
                <i class="bi bi-x-lg"></i> Clear
            </a>
        </form>
    </div>

    <!-- ══ EVENTS TABLE ═════════════════════════════════════════════════════ -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>Time</th>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Card No.</th>
                    <th>Event Type</th>
                    <th>Door</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (isset($apiError) && strpos($apiError, 'locked') !== false): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:3rem;color:var(--amber);">
                        <i class="bi bi-lock-fill" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                        <strong>Device is Locked</strong>
                        <div style="margin-top:.5rem;font-size:.85rem;color:var(--w60);">
                            Please wait a few minutes and refresh the page.
                        </div>
                    </td>
                </tr>
                <?php elseif (empty($events)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:3rem;color:var(--w40);">
                        <i class="bi bi-camera-reels" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
                        No events found for the selected period.
                    </td>
                </tr>
                <?php else: foreach ($events as $event):
                    $badgeClass = 'default';
                    if (strpos($event['eventType'], 'Face') !== false) $badgeClass = 'face';
                    elseif ($event['eventType'] === 'Door Unlocked') $badgeClass = 'unlock';
                    elseif ($event['eventType'] === 'Door Locked') $badgeClass = 'lock';
                    elseif ($event['eventType'] === 'Access Denied') $badgeClass = 'denied';
                ?>
                <tr>
                    <td class="photo-cell">
                        <?php
                        // Either key is enough: pictureURL feeds the live-fetch
                        // fallback, serialNo feeds the cache lookup that
                        // hik_receiver.php populates. Requiring pictureURL here
                        // meant a pushed photo was invisible on any event where
                        // the search response left that field empty.
                        if (!empty($event['serialNo']) || !empty($event['pictureURL'])):
                            $imgSrc = 'face_image.php?serial=' . urlencode($event['serialNo'])
                                    . '&url=' . urlencode($event['pictureURL']);
                        ?>
                        <img src="<?= htmlspecialchars($imgSrc) ?>"
                             class="photo-thumb" loading="lazy" alt="Capture"
                             onclick="openLightbox(this.src)"
                             onerror="this.onerror=null; this.outerHTML='<div class=&quot;photo-placeholder&quot;><i class=&quot;bi bi-person-badge&quot;></i></div>';">
                        <?php else: ?>
                        <div class="photo-placeholder"><i class="bi bi-person-badge"></i></div>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= htmlspecialchars($event['time']) ?></td>
                    <td class="mono"><?= htmlspecialchars($event['employeeID']) ?></td>
                    <td class="name-cell"><?= htmlspecialchars($event['name']) ?></td>
                    <td class="mono"><?= htmlspecialchars($event['cardNo']) ?></td>
                    <td>
                        <span class="event-badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($event['eventType']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($event['doorName']) ?></td>
                    <td>
                        <span class="status-dot <?= $event['status'] === 'Success' ? 'success' : 'failed' ?>"></span>
                        <?= htmlspecialchars($event['status']) ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- ══ PAGINATION ═══════════════════════════════════════════════════ -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-section">
            <div class="pagination-info">
                Showing <strong><?= ($page - 1) * $perPage + 1 ?></strong> –
                <strong><?= min($page * $perPage, $total) ?></strong>
                of <strong><?= number_format($total) ?></strong> events
            </div>
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, array('page' => $page - 1))) ?>"
                       class="page-btn"><i class="bi bi-chevron-left"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled><i class="bi bi-chevron-left"></i></button>
                <?php endif; ?>

                <?php
                for ($p = 1; $p <= $totalPages; $p++):
                    if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2):
                        if ($p === $page):
                ?>
                    <button class="page-btn active"><?= $p ?></button>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($_GET, array('page' => $p))) ?>"
                       class="page-btn"><?= $p ?></a>
                <?php endif; endif; endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, array('page' => $page + 1))) ?>"
                       class="page-btn"><i class="bi bi-chevron-right"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled><i class="bi bi-chevron-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.wrap -->

<!-- ══ PHOTO LIGHTBOX ═══════════════════════════════════════════════════════ -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)">
    <button class="lightbox-close" onclick="closeLightbox(event)"><i class="bi bi-x-lg"></i></button>
    <img id="lightbox-img" src="" alt="Capture">
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox(e) {
    if (e.target.id === 'lightbox' || e.target.closest('.lightbox-close')) {
        document.getElementById('lightbox').classList.remove('open');
        document.getElementById('lightbox-img').src = '';
    }
}

function refreshEvents() {
    window.location.reload();
}

// Auto-refresh every 60 seconds (optional)
setTimeout(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>
