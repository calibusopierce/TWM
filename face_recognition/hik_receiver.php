<?php
// ══════════════════════════════════════════════════════════════════════════════
// hik_receiver.php — Endpoint the Hikvision terminal POSTs each event to.
//
// WHY THIS EXISTS
// The device's AcsEvent search returns a pictureURL like
//   http://<dev>/LOCALS/pic/acsLinkCap/202609_00/18_065145_30075_0.jpeg@WEB000000003257
// but GET-ing that URL from PHP returns 404 even while the device's own web UI
// is happily rendering the same frame. Pulling the picture after the fact is
// therefore unreliable on this firmware. Instead we let the DEVICE push each
// event to us the instant it fires, with the capture attached, and we cache the
// JPEG keyed by serialNo. face_image.php already checks cache/photos/<serial>.jpg
// before attempting a live fetch, so no change is needed there.
//
// Unlike the abandoned poll_events.php approach, this needs no persistent
// background process — it's an ordinary PHP page that Apache serves when the
// device calls it.
//
// AUTH
// The device cannot log into TWM, so this endpoint deliberately does NOT call
// auth_check()/rbac_gate(). It is gated by a shared token in the query string
// plus a source-IP check. It writes JPEGs into cache/photos/ and returns no
// data, so its blast radius is limited to filling that directory.
//
// DEVICE-SIDE SETUP
//   Configuration → Network → Advanced Settings → HTTP Listening
//   URL: http://<XAMPP-LAN-IP>/TWM/face_recognition/hik_receiver.php?k=<HIK_PUSH_TOKEN>
//   (127.0.0.1 will NOT work — the device has to reach this box over the LAN,
//    and Windows Firewall must allow inbound TCP 80.)
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';

$cacheDir = __DIR__ . '/cache';
$photoDir = $cacheDir . '/photos';
$logFile  = $cacheDir . '/receiver.log';
$lastFile = $cacheDir . '/last_push.txt';

if (!is_dir($photoDir)) {
    @mkdir($photoDir, 0775, true);
}

function hik_log($msg) {
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ── Gate 1: shared token ─────────────────────────────────────────────────────
$token = isset($_GET['k']) ? $_GET['k'] : '';
if (!defined('HIK_PUSH_TOKEN') || HIK_PUSH_TOKEN === '' || !hash_equals(HIK_PUSH_TOKEN, $token)) {
    http_response_code(403);
    hik_log('REJECT — bad or missing token from ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?'));
    exit('forbidden');
}

// ── Gate 2: source IP ────────────────────────────────────────────────────────
// Localhost is allowed alongside the device so you can replay a captured POST
// with curl from the XAMPP box while testing.
$remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$allowed = array(HIKVISION_IP, '127.0.0.1', '::1');
if (!in_array($remote, $allowed, true)) {
    http_response_code(403);
    hik_log("REJECT — unexpected source IP {$remote} (expected " . HIKVISION_IP . ')');
    exit('forbidden');
}

// ── Pull the text payload ────────────────────────────────────────────────────
// Firmware varies: some send multipart/form-data (event body as a form field,
// capture as a file part), some send a bare XML/JSON body with no picture.
// Handle both rather than assuming, since the exact shape this DS-K1T343MWX
// sends has not been verified yet.
$rawText = '';
if (!empty($_POST)) {
    foreach ($_POST as $v) {
        if (is_string($v) && (strpos($v, 'AccessControllerEvent') !== false
                           || strpos($v, 'EventNotificationAlert') !== false)) {
            $rawText = $v;
            break;
        }
    }
    if ($rawText === '') {
        $strings = array();
        foreach ($_POST as $v) {
            if (is_string($v)) $strings[] = $v;
        }
        $rawText = implode("\n", $strings);
    }
} else {
    $rawText = file_get_contents('php://input');
    if ($rawText === false) $rawText = '';
}

// Always keep the most recent payload verbatim. This is the file to open when
// verifying that the serialNo the device pushes actually matches the serialNo
// the AcsEvent search returns — the whole cache lookup depends on that.
@file_put_contents($lastFile, $rawText);

// ── Extract the fields we care about ─────────────────────────────────────────
// Deliberately regex-based rather than a strict XML or JSON parse, because the
// payload could be either format depending on parameterFormatType.
function hik_field($text, $key) {
    if (preg_match('#<' . $key . '>\s*([^<]*?)\s*</' . $key . '>#i', $text, $m)) {
        return trim($m[1]);
    }
    if (preg_match('#"' . $key . '"\s*:\s*"([^"]*)"#i', $text, $m)) {
        return trim($m[1]);
    }
    if (preg_match('#"' . $key . '"\s*:\s*(-?\d+)#i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

$serialNo   = hik_field($rawText, 'serialNo');
$employeeNo = hik_field($rawText, 'employeeNoString');
$eventTime  = hik_field($rawText, 'dateTime');
if ($eventTime === '') $eventTime = hik_field($rawText, 'time');

// ── Collect any attached images ──────────────────────────────────────────────
$images = array();
foreach ($_FILES as $field => $f) {
    if (!isset($f['tmp_name'])) continue;
    if (is_array($f['tmp_name'])) {
        foreach ($f['tmp_name'] as $i => $tmp) {
            if (is_uploaded_file($tmp) || is_file($tmp)) {
                $images[] = array('field' => $field . "[{$i}]", 'tmp' => $tmp);
            }
        }
    } else {
        if (is_uploaded_file($f['tmp_name']) || is_file($f['tmp_name'])) {
            $images[] = array('field' => $field, 'tmp' => $f['tmp_name']);
        }
    }
}

// ── Save ─────────────────────────────────────────────────────────────────────
$savedAs = null;
$reason  = null;
$data    = '';

if (empty($images)) {
    $reason = 'no image part in this POST';
} elseif ($serialNo === '') {
    // No serialNo means we have no key face_image.php could look the photo up
    // by. Park the bytes for inspection instead of silently dropping them —
    // if this fires often, the field name in hik_field() above is wrong for
    // this firmware and last_push.txt will show the correct one.
    $stash = $photoDir . '/unmatched';
    if (!is_dir($stash)) @mkdir($stash, 0775, true);
    $first = $images[0];
    @copy($first['tmp'], $stash . '/' . date('Ymd_His') . '_' . getmypid() . '.jpg');
    $reason = 'image received but no serialNo found in payload — stashed under photos/unmatched/';
} else {
    $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '', $serialNo);
    // Take the largest attached image. Some firmware attaches both the cropped
    // face and the full scene; the bigger file is the useful one.
    $best = null;
    $bestSize = -1;
    foreach ($images as $img) {
        $sz = @filesize($img['tmp']);
        if ($sz !== false && $sz > $bestSize) {
            $bestSize = $sz;
            $best = $img;
        }
    }
    $data = ($best !== null) ? @file_get_contents($best['tmp']) : false;

    if ($data === false || $data === '') {
        $reason = 'attached image could not be read';
    } else {
        // Same validity gate the live-fetch path uses, so a truncated or flat
        // placeholder frame never gets cached permanently as the wrong photo.
        $check = HikvisionAPI::checkPhotoValidity($data);
        if (!$check['valid']) {
            $reason = 'rejected by checkPhotoValidity — ' . $check['reason'];
        } else {
            $dest = $photoDir . '/' . $safeSerial . '.jpg';
            $tmpDest = $dest . '.tmp';
            if (@file_put_contents($tmpDest, $data) !== false && @rename($tmpDest, $dest)) {
                $savedAs = $dest;
            } else {
                @unlink($tmpDest);
                $reason = 'could not write to ' . $photoDir . ' (check Apache write permission)';
            }
        }
    }
}

hik_log(sprintf(
    'serial=%s emp=%s time=%s parts=%d %s',
    $serialNo !== '' ? $serialNo : '-',
    $employeeNo !== '' ? $employeeNo : '-',
    $eventTime !== '' ? $eventTime : '-',
    count($images),
    $savedAs !== null ? ('SAVED ' . basename($savedAs) . ' (' . strlen($data) . ' bytes)') : ('NOT SAVED — ' . $reason)
));

// The device retries or logs a failure if it doesn't get a prompt 200, so
// always answer 200 even when we couldn't use the payload — the log above is
// where problems get recorded, not the HTTP status.
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
