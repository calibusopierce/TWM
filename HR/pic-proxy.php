<?php
/**
 * pic-proxy.php — Selfie photo proxy for Visual Attendance
 *
 * WHY THIS EXISTS:
 * The selfie image host (122.52.195.3/tradewellportal/) only serves
 * plain HTTP. When visual-attendance.php is loaded over HTTPS, the
 * browser auto-upgrades those <img> requests to HTTPS and — since the
 * image host doesn't answer on HTTPS — the requests just fail (mixed
 * content blocking). That's why photos showed as broken images.
 *
 * This script fetches the photo over HTTP on the SERVER side (not
 * subject to browser mixed-content rules) and streams it back to the
 * browser over this app's own HTTPS origin, so the <img src="..."> the
 * browser sees is always same-origin HTTPS.
 *
 * Usage from the front end:
 *   <img src="pic-proxy.php?path=<?= urlencode($relativePicPath) ?>">
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'attendance');

// Must match the $picBase used in visual-attendance.php.
$picBase = 'http://122.52.195.3/tradewellportal/';

$path = isset($_GET['path']) ? (string) $_GET['path'] : '';
$path = ltrim($path, '/');

// Reject empty paths, path traversal, and anything trying to smuggle in
// its own scheme/host (this must stay pinned to $picBase, not become an
// open proxy for arbitrary URLs).
if (
    $path === '' ||
    strpos($path, '..') !== false ||
    preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) ||
    strpos($path, '\\') !== false
) {
    http_response_code(400);
    exit;
}

$url = $picBase . str_replace(' ', '%20', $path);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 2,
]);
$data       = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr    = curl_error($ch);
$contentTyp = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($data === false || $curlErr || $httpCode >= 400) {
    // Serve a 1x1 transparent pixel with 404 status so the <img onerror>
    // fallback already used on the thumbnails still fires cleanly, and
    // the lightbox can show a proper "unavailable" state (see below).
    http_response_code(404);
    exit;
}

// Fall back to a sane default if the upstream didn't report a type.
if (!$contentTyp || strpos($contentTyp, 'image/') !== 0) {
    $contentTyp = 'image/jpeg';
}

header('Content-Type: ' . $contentTyp);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo $data;