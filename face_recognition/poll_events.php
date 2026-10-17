<?php
// ══════════════════════════════════════════════════════════════════════════════
// poll_events.php — Persistent background photo-cacher (replaces listen_events.php)
//
// listen_events.php watched the device's
// /ISAPI/Event/notification/alertStream endpoint for events, caching each
// photo the instant it fired. That endpoint doesn't exist on this device
// (DS-K1T343MWX access controller) — every connection attempt returned
// HTTP 404 (see cache/listener.log), so it never actually cached a single
// photo. alertStream is a video-device (NVR/camera) feature; this is an
// access-control terminal, which doesn't expose it.
//
// This script does the same job — grab each event's photo before the
// device discards it — using the ONE endpoint already confirmed to work
// end-to-end on this device: AcsEvent search (the same call index.php and
// test_connection.php use). It just polls that on a short interval instead
// of trying to hold a long-lived stream open. No multipart parsing, no
// guessing at undocumented framing — same request/response shape already
// proven to work.
//
// Run as a persistent service (see hik-listener.service — point its
// ExecStart at this file instead of listen_events.php), not via cron: this
// script loops and sleeps internally, it isn't meant to exit.
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';

$logFile = __DIR__ . '/cache/listener.log';
function logmsg($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line; // also visible via `journalctl -u hik-listener -f`
}

$photoCacheDir = __DIR__ . '/cache/photos';
if (!is_dir($photoCacheDir)) {
    mkdir($photoCacheDir, 0755, true);
}

$api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

// How far back each poll looks, vs. how often it polls. The lookback window
// is deliberately wider than the poll interval so a single slow/failed
// cycle (network blip, device hiccup) can't let an event slip through an
// uncovered gap — events already cached are skipped in a cheap
// file_exists() check below, so the overlap costs nothing.
$pollIntervalSeconds = 4;
$lookbackSeconds = 120;

logmsg("Starting poller (interval={$pollIntervalSeconds}s, lookback={$lookbackSeconds}s)");

while (true) {
    $end = time();
    $start = $end - $lookbackSeconds;
    $startTime = date('Y-m-d\TH:i:s', $start);
    $endTime   = date('Y-m-d\TH:i:s', $end);

    $result = $api->getAccessEvents($startTime, $endTime, null, 50, 0);

    if (isset($result['error'])) {
        logmsg("Poll failed: " . $result['error']);
    } else {
        $events = isset($result['events']) ? $result['events'] : array();
        logmsg("Cycle OK — found " . count($events) . " event(s) in the last {$lookbackSeconds}s");
        foreach ($events as $evt) {
            if (empty($evt['pictureURL']) || empty($evt['serialNo'])) {
                continue;
            }

            $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '', $evt['serialNo']);
            if ($safeSerial === '') {
                continue;
            }
            $photoFile = $photoCacheDir . '/' . $safeSerial . '.jpg';

            // Already have a good copy cached — nothing to do. Both this
            // poller and index.php only ever write a file here after it
            // passes HikvisionAPI::checkPhotoValidity(), so file_exists()
            // being true is a reliable "already handled, and it's good"
            // signal — no need to re-open and re-validate it every cycle.
            if (file_exists($photoFile)) {
                continue;
            }

            $imgResult = $api->fetchImage($evt['pictureURL']);
            if (!empty($imgResult['data'])) {
                file_put_contents($photoFile, $imgResult['data']);
                logmsg("Cached photo for serial={$safeSerial} ({$evt['eventType']})");
            } elseif (isset($imgResult['error'])) {
                // Normal on the first pass or two after an event fires —
                // device may not have finished writing the frame, or (this
                // device's more common case) already discarded it. Not
                // fatal: nothing gets written, so this same event is simply
                // retried on the next cycle for as long as it stays inside
                // the lookback window.
                logmsg("Not cached yet, serial={$safeSerial}: {$imgResult['error']}");
            }
        }
    }

    sleep($pollIntervalSeconds);
}
