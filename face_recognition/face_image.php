<?php
// ══════════════════════════════════════════════════════════════════════════════
// face_image.php — Streams a Hikvision event snapshot through digest auth.
// ══════════════════════════════════════════════════════════════════════════════

ob_start();

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';

auth_check();
rbac_gate($pdo, 'face_recognition');

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';

$pictureUrl = isset($_GET['url']) ? $_GET['url'] : '';
$serial     = isset($_GET['serial']) ? $_GET['serial'] : '';
$debugMode  = isset($_GET['debug']) && $_GET['debug'] === '1';

// ── DEBUG MODE ───────────────────────────────────────────────────────────────
// Bypasses the local cache entirely and reports exactly what the device sent
// back for every candidate URL, instead of guessing from how the rendered
// image looks. Add &debug=1 to the request URL to use this.
if ($debugMode) {
    ob_end_clean();
    header('Content-Type: text/plain');

    if ($pictureUrl === '') {
        http_response_code(400);
        exit('Missing url parameter');
    }
    $decodedUrl = urldecode($pictureUrl);

    // Real candidates — same list the live path below will actually use.
    $candidates = array();
    $candidates[] = $decodedUrl;
    if (strpos($decodedUrl, 'http') !== 0 &&
        (strpos($decodedUrl, '/LOCALS') === 0 || strpos($decodedUrl, 'LOCALS') !== false)) {
        $candidates[] = 'http://' . HIKVISION_IP . ':' . HIKVISION_PORT . '/ISAPI' . $decodedUrl;
        $candidates[] = 'http://' . HIKVISION_IP . ':' . HIKVISION_PORT . $decodedUrl;
    }
    $candidates = array_unique($candidates);

    // Debug-only: also show what the suffix-stripped ("@WEB..." removed) URL
    // returns, purely as diagnostic info. Per HikvisionAPI::fetchImage()'s
    // docblock this variant returns the device's generic placeholder (a
    // valid but solid-green JPEG), never the real capture — it is NOT part
    // of $candidates above and must never be used by the live serving path,
    // since a "valid" wrong image would pass every completeness check and
    // get cached forever.
    $cleanUrl = preg_replace('/@WEB.*$/', '', $decodedUrl);
    $debugOnlyCandidates = ($cleanUrl !== $decodedUrl) ? array($cleanUrl) : array();

    echo "Decoded url param: {$decodedUrl}\n";
    echo "Serial: {$serial}\n";
    echo str_repeat('=', 80) . "\n";

    $api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

    echo "-- Real candidates (in the order the live path tries them) --\n";
    foreach ($candidates as $i => $tryUrl) {
        $dbg = null;
        $result = $api->fetchImage($tryUrl, $dbg);
        echo "[" . ($i + 1) . "] URL: {$tryUrl}\n";
        if ($dbg) {
            echo "    httpCode:    {$dbg['httpCode']}\n";
            echo "    curlError:   " . ($dbg['curlError'] ?: '(none)') . "\n";
            echo "    contentType: {$dbg['contentType']}\n";
            echo "    byteLength:  {$dbg['byteLength']}\n";
            echo "    first8Hex:   {$dbg['first8Hex']}  (should start ffd8 for jpeg)\n";
            echo "    last8Hex:    {$dbg['last8Hex']}  (should end ...ffd9 for jpeg)\n";
        } else {
            echo "    (request never reached curl — rejected before send)\n";
        }
        echo "    outcome:     " . (isset($result['error']) ? "REJECTED — {$result['error']}" : "ACCEPTED as valid image (passed marker check + flat-frame check)") . "\n";
        echo str_repeat('-', 80) . "\n";
    }

    if (!empty($debugOnlyCandidates)) {
        echo "\n-- Suffix-stripped variant (diagnostic only — NEVER used live, returns device placeholder) --\n";
        foreach ($debugOnlyCandidates as $i => $tryUrl) {
            $dbg = null;
            $result = $api->fetchImage($tryUrl, $dbg);
            echo "[" . ($i + 1) . "] URL: {$tryUrl}\n";
            if ($dbg) {
                echo "    httpCode:    {$dbg['httpCode']}\n";
                echo "    curlError:   " . ($dbg['curlError'] ?: '(none)') . "\n";
                echo "    contentType: {$dbg['contentType']}\n";
                echo "    byteLength:  {$dbg['byteLength']}\n";
                echo "    first8Hex:   {$dbg['first8Hex']}  (should start ffd8 for jpeg)\n";
                echo "    last8Hex:    {$dbg['last8Hex']}  (should end ...ffd9 for jpeg)\n";
            } else {
                echo "    (request never reached curl — rejected before send)\n";
            }
            echo "    outcome:     " . (isset($result['error']) ? "REJECTED — {$result['error']}" : "ACCEPTED (but is the placeholder frame, not the real capture)") . "\n";
            echo str_repeat('-', 80) . "\n";
        }
    }
    exit;
}

// ── Serve from the push cache first ─────────────────────────────────────
// hik_receiver.php is the endpoint the device POSTs each event to (HTTP
// Listening, configured device-side) with the capture attached. It writes
// the validated JPEG to cache/photos/<serialNo>.jpg the moment the event
// fires, so this is the path that actually works — the live fetch below is
// only a fallback. Files here already passed checkPhotoValidity() in
// hik_receiver.php, so no re-check is needed.
if ($serial !== '') {
    $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '', $serial);
    $cachedFile = __DIR__ . '/cache/photos/' . $safeSerial . '.jpg';
    if ($safeSerial !== '' && is_file($cachedFile)) {
        ob_end_clean();
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($cachedFile));
        header('Cache-Control: private, max-age=3600');
        readfile($cachedFile);
        exit;
    }
}

// ── Fall back to a live fetch from the device ───────────────────────────
// Only reached when no photo has been pushed for this serialNo: events
// that predate hik_receiver.php, or a window where the receiver was
// unreachable. GET-ing an event's pictureURL is unreliable on this
// firmware and usually 404s, so treat anything this path returns as a
// bonus rather than something to rely on.

if ($pictureUrl === '') {
    // Not an error any more. Since index.php now renders a thumbnail for
    // any row with a serialNo, a cache miss with no pictureURL is the
    // normal case for older events — 404 so the onerror handler swaps in
    // the placeholder icon, no-store so it retries on the next load.
    ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    exit('No cached capture for this event');
}

// ── Decode the URL ──────────────────────────────────────────────────────────
$pictureUrl = urldecode($pictureUrl);

// ── Normalize the URL - try multiple possible paths ──────────────────────────
$possibleUrls = array();

// Always try the original, untouched URL first — per
// HikvisionAPI::fetchImage()'s docblock, this is the only URL variant that
// returns the actual captured frame.
$possibleUrls[] = $pictureUrl;

// If it's a bare path (no scheme), also try it resolved against the device
// directly, with and without an /ISAPI prefix.
if (strpos($pictureUrl, 'http') !== 0 &&
    (strpos($pictureUrl, '/LOCALS') === 0 || strpos($pictureUrl, 'LOCALS') !== false)) {
    $possibleUrls[] = 'http://' . HIKVISION_IP . ':' . HIKVISION_PORT . '/ISAPI' . $pictureUrl;
    $possibleUrls[] = 'http://' . HIKVISION_IP . ':' . HIKVISION_PORT . $pictureUrl;
}

// NOTE: deliberately no "@WEB..." suffix-stripped fallback here anymore.
// That variant returns HTTP 200 with a well-formed JPEG, but it's the
// device's generic placeholder frame (renders solid green), not the real
// capture — since it's a genuinely valid image, it passed every
// completeness check and was getting cached to disk forever as the wrong
// photo. If every real candidate below fails, we fall through to the
// transparent-pixel fallback instead, which is never cached — so the row
// just retries on the next page load/refresh instead of getting stuck.

// Remove duplicates
$possibleUrls = array_unique($possibleUrls);

// ── Log what we're trying ──────────────────────────────────────────────────
error_log("[face_image.php] Original URL: " . $pictureUrl);
error_log("[face_image.php] Trying " . count($possibleUrls) . " possible URLs");

$api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

// ── Try each possible URL until one works ─────────────────────────────────
$lastError = null;
foreach ($possibleUrls as $tryUrl) {
    error_log("[face_image.php] Trying: " . $tryUrl);
    $result = $api->fetchImage($tryUrl);

    if (!isset($result['error'])) {
        error_log("[face_image.php] Success with URL: " . $tryUrl);
        ob_end_clean();
        header('Content-Type: ' . ($result['contentType'] ?: 'image/jpeg'));
        header('Content-Length: ' . strlen($result['data']));
        header('Cache-Control: private, max-age=3600');
        echo $result['data'];
        exit;
    }
    $lastError = $result['error'];
    error_log("[face_image.php] Failed: " . $lastError);
}

// ── All attempts failed ─────────────────────────────────────────────────────
// Respond with a real HTTP error instead of a fake "valid" transparent
// pixel — index.php's <img onerror="..."> already swaps in the clean
// person-badge placeholder icon when the image request fails, so this
// gives a consistent look with rows that never had a photo at all, rather
// than a mysterious blank square.
error_log("[face_image.php] All attempts failed. Last error: " . $lastError);
ob_end_clean();
http_response_code(404);
header('Content-Type: text/plain');
header('Cache-Control: no-store');
exit('No valid capture available' . ($lastError ? " ({$lastError})" : ''));