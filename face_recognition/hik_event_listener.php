<?php
// ══════════════════════════════════════════════════════════════════════════════
// hik_event_listener.php — receives pushed events (and their JPEGs) from the
// Hikvision terminal.
//
// ─── WHY THIS FILE EXISTS ────────────────────────────────────────────────────
// The old approach asked the device for a picture after the fact, using the
// pictureURL from an AcsEvent search. That points into /LOCALS/pic/, the
// device's short-lived capture store. By the time a page renders, the frame
// has usually rotated out, and the device answers HTTP 200 with a generic
// placeholder rather than a 404 — which is why "fetch the captured image"
// looked impossible. It isn't; the pull is just the wrong direction.
//
// Hikvision's ISAPI guide for face recognition terminals documents a
// listening mode: register an HTTP host on the device, and when an event
// fires the device opens a connection to that host and POSTs the event body
// together with the raw picture bytes as multipart/form-data. The photo
// arrives at the instant of capture, so there is no buffer to lose, and it
// lands in our own storage where it stays for as long as we want.
//
// ─── DEPLOYMENT ─────────────────────────────────────────────────────────────
// 1. Set TWM_LISTENER_IP / TWM_LISTENER_PATH / HIK_LISTENER_TOKEN in
//    hik_config.php.
// 2. Run hik_setup_listener.php once to register this URL on the device and
//    confirm the device can reach it.
// 3. On the device web UI, make sure picture capture is on for authentication
//    events (Access Control → Authentication Settings → save/upload captured
//    picture). If AcsEvent rows already carry a pictureURL, it is on.
//
// ─── THIS ENDPOINT IS NOT BEHIND auth_check() ───────────────────────────────
// It cannot be: the device has no TWM session and no cookies. It is guarded by
// a shared token in the URL plus a source-IP check against HIKVISION_IP. That
// is a deliberate, narrow exception — it accepts nothing but image bytes,
// writes only into cache/photos, and never reads from the database.
//
// Block the cache directory from the web. In face_recognition/.htaccess:
//     <FilesMatch "\.(log|json|ptr)$">
//         Require all denied
//     </FilesMatch>
// and in face_recognition/cache/.htaccess:
//     Require all denied
// (the photos are served through face_image.php, which IS behind RBAC, so
// nothing here needs to be directly reachable).
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';
require_once __DIR__ . '/hik_photo_store.php';

// ─── Always answer 200, always answer fast ───────────────────────────────────
// If the device has ANR / retransmission enabled it will replay an event it
// considers unacknowledged, forever. So acknowledge first and do the work
// after, and never let a PHP notice turn into a 500.
@ini_set('display_errors', '0');
ignore_user_abort(true);

/**
 * Append a line to the listener log, rotating it once it gets large.
 * Best-effort: a logging failure must never break event ingestion.
 */
function hik_log($message) {
    $path = HIK_LISTENER_LOG;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_file($path) && filesize($path) > HIK_LISTENER_LOG_MAX_BYTES) {
        @rename($path, $path . '.1');
    }
    @file_put_contents(
        $path,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND
    );
}

/**
 * Acknowledge the push and let the device close the connection, so a slow
 * disk write here can't stall the door terminal.
 */
function hik_ack($note = 'OK') {
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain');
        header('Content-Length: ' . strlen($note));
        header('Connection: close');
    }
    echo $note;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AUTHORISATION
// ══════════════════════════════════════════════════════════════════════════════

$remoteIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$token    = isset($_GET['token']) ? (string)$_GET['token'] : '';

$tokenConfigured = (HIK_LISTENER_TOKEN !== '' &&
                    HIK_LISTENER_TOKEN !== 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
$tokenOk = ($tokenConfigured && $token !== '' &&
            hash_equals(HIK_LISTENER_TOKEN, $token));
$ipOk    = ($remoteIp !== '' && $remoteIp === HIKVISION_IP);

// Some firmware builds drop the query string from the configured callback URL.
// Rather than silently failing every push, accept a tokenless request that
// demonstrably came from the device itself — but say so in the log, because it
// is a weaker check and worth knowing about.
if (!$tokenOk && !$ipOk) {
    hik_log("REJECT auth — ip={$remoteIp} tokenPresent=" . ($token !== '' ? 'yes' : 'no'));
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Forbidden');
}
if (!$tokenOk && $ipOk) {
    hik_log("WARN  accepted on source-IP only (no/!bad token) — ip={$remoteIp}. " .
            "If this persists, the device dropped the ?token= from the callback URL.");
}

// ══════════════════════════════════════════════════════════════════════════════
// BODY EXTRACTION
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Parse a raw multipart body into a list of
 * array('headers' => array(lowername => value), 'body' => string).
 *
 * Only used when PHP itself did not parse the request (see below). Hikvision
 * sends a Content-Length per part, but splitting on the boundary is simpler
 * and safe here: a boundary is by definition a byte sequence that does not
 * occur in the payload, and any mis-split would leave a JPEG that fails the
 * marker check downstream rather than being silently stored.
 */
function hik_parse_multipart_raw($body, $boundary) {
    $parts = array();
    $segments = explode('--' . $boundary, $body);

    foreach ($segments as $segment) {
        if ($segment === '' || trim($segment) === '' || trim($segment) === '--') {
            continue;
        }
        // Each segment begins with the CRLF that terminated the delimiter line.
        if (strncmp($segment, "\r\n", 2) === 0) {
            $segment = substr($segment, 2);
        } elseif (strncmp($segment, "\n", 1) === 0) {
            $segment = substr($segment, 1);
        }

        // Headers are separated from the body by a blank line.
        $split = strpos($segment, "\r\n\r\n");
        $skip = 4;
        if ($split === false) {
            $split = strpos($segment, "\n\n");
            $skip = 2;
        }
        if ($split === false) {
            continue;
        }

        $headerBlock = substr($segment, 0, $split);
        $content = substr($segment, $split + $skip);

        // Drop the CRLF that precedes the next delimiter.
        if (substr($content, -2) === "\r\n") {
            $content = substr($content, 0, -2);
        } elseif (substr($content, -1) === "\n") {
            $content = substr($content, 0, -1);
        }

        $headers = array();
        foreach (preg_split('/\r?\n/', $headerBlock) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $colon)))] =
                trim(substr($line, $colon + 1));
        }

        $parts[] = array('headers' => $headers, 'body' => $content);
    }

    return $parts;
}

/** Does this blob look like a JPEG? (start-of-image marker) */
function hik_looks_like_jpeg($blob) {
    return is_string($blob) && strlen($blob) > 4 && substr($blob, 0, 2) === "\xFF\xD8";
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
$images = array();    // candidate JPEG blobs
$metaBlobs = array(); // candidate XML/JSON event bodies

// --- Path A: PHP already parsed the request -------------------------------
// This is the normal case. Whether a picture part lands in $_FILES or in
// $_POST depends on whether the firmware sent a filename= attribute on the
// part, and that varies between builds — so check both rather than assuming.
if (!empty($_FILES)) {
    foreach ($_FILES as $field => $file) {
        if (!isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
            continue;
        }
        if ($file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
            continue;
        }
        $blob = @file_get_contents($file['tmp_name']);
        if (hik_looks_like_jpeg($blob)) {
            $images[] = array('name' => $field, 'data' => $blob);
        } elseif (is_string($blob) && $blob !== '') {
            $metaBlobs[] = $blob;
        }
    }
}

if (!empty($_POST)) {
    foreach ($_POST as $field => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (hik_looks_like_jpeg($value)) {
            $images[] = array('name' => $field, 'data' => $value);
        } else {
            $metaBlobs[] = $value;
        }
    }
}

// --- Path B: parse the raw body ourselves ---------------------------------
// Reached when PHP handed us nothing. Two ways that happens: the body is a
// bare XML/JSON event (no multipart, so no $_POST), or POST parsing has been
// turned off for this script via HIK_LISTENER_RAW_MULTIPART.
$rawLen = 0;
if (empty($images) && empty($metaBlobs)) {
    $raw = @file_get_contents('php://input');
    $rawLen = is_string($raw) ? strlen($raw) : 0;

    if (is_string($raw) && $raw !== '') {
        if (stripos($contentType, 'multipart/') !== false &&
            preg_match('/boundary=("?)([^";]+)\1/i', $contentType, $m)) {
            foreach (hik_parse_multipart_raw($raw, $m[2]) as $part) {
                if (hik_looks_like_jpeg($part['body'])) {
                    $images[] = array('name' => 'raw', 'data' => $part['body']);
                } elseif (trim($part['body']) !== '') {
                    $metaBlobs[] = $part['body'];
                }
            }
        } else {
            // Bare body: either the event document, or a naked JPEG.
            if (hik_looks_like_jpeg($raw)) {
                $images[] = array('name' => 'body', 'data' => $raw);
            } else {
                $metaBlobs[] = $raw;
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// METADATA
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Pull the fields we care about out of an event document, accepting either
 * the JSON or the XML rendering. Both wrap the interesting values in an
 * AccessControllerEvent object; the surrounding envelope differs.
 */
function hik_extract_event_meta($blob) {
    $meta = array();

    $node = null;

    $decoded = json_decode($blob, true);
    if (is_array($decoded)) {
        $node = isset($decoded['AccessControllerEvent'])
            ? $decoded['AccessControllerEvent']
            : $decoded;
        if (isset($decoded['dateTime'])) {
            $meta['time'] = (string)$decoded['dateTime'];
        }
        if (isset($decoded['eventType'])) {
            $meta['eventType'] = (string)$decoded['eventType'];
        }
    } elseif (strpos($blob, '<') !== false) {
        $clean = preg_replace('/xmlns[^=]*="[^"]*"/', '', $blob);
        $xml = @simplexml_load_string($clean, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml !== false) {
            $arr = json_decode(json_encode($xml), true);
            if (is_array($arr)) {
                $node = isset($arr['AccessControllerEvent'])
                    ? $arr['AccessControllerEvent']
                    : $arr;
                if (isset($arr['dateTime'])) {
                    $meta['time'] = (string)$arr['dateTime'];
                }
                if (isset($arr['eventType'])) {
                    $meta['eventType'] = (string)$arr['eventType'];
                }
            }
        }
    }

    if (!is_array($node)) {
        return $meta;
    }

    $map = array(
        'serialNo'         => 'serialNo',
        'employeeNoString' => 'employeeNo',
        'name'             => 'name',
        'cardNo'           => 'cardNo',
        'majorEventType'   => 'major',
        'subEventType'     => 'minor',
        'doorNo'           => 'doorNo',
        'attendanceStatus' => 'attendanceStatus',
        'currentVerifyMode' => 'verifyMode',
        'picturesNumber'   => 'picturesNumber',
    );
    foreach ($map as $from => $to) {
        if (isset($node[$from]) && !is_array($node[$from])) {
            $meta[$to] = (string)$node[$from];
        }
    }

    // Some builds put the timestamp only on the inner node.
    if (empty($meta['time']) && isset($node['time']) && !is_array($node['time'])) {
        $meta['time'] = (string)$node['time'];
    }

    return $meta;
}

$meta = array();
foreach ($metaBlobs as $blob) {
    $found = hik_extract_event_meta($blob);
    if (!empty($found)) {
        // Later parts shouldn't clobber earlier known-good values.
        $meta = array_merge($found, $meta);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ACKNOWLEDGE, THEN STORE
// ══════════════════════════════════════════════════════════════════════════════

hik_ack('OK');

$serial = isset($meta['serialNo']) ? $meta['serialNo'] : '';
$who = (isset($meta['employeeNo']) ? $meta['employeeNo'] : '?') .
       (isset($meta['name']) && $meta['name'] !== '' ? ' (' . $meta['name'] . ')' : '');

// Heartbeats and door-state changes carry no picture. They're the bulk of the
// traffic, so don't treat them as failures — just note them thinly.
if (empty($images)) {
    $desc = isset($meta['eventType']) ? $meta['eventType'] : 'unknown';
    if (empty($meta)) {
        // Nothing parsed at all: worth a loud entry, since this is what a
        // firmware quirk or a disabled POST parser looks like.
        hik_log('NOPARSE contentType=' . $contentType .
                ' filesCount=' . count($_FILES) .
                ' postCount=' . count($_POST) .
                ' rawLen=' . (int)$rawLen .
                ' — no picture and no readable event body.');
    } else {
        hik_log("SKIP  no picture — type={$desc} serial={$serial} who={$who}");
    }
    exit;
}

if ($serial === '') {
    // Without a serial we cannot key the photo to a dashboard row. Fall back
    // to a synthetic key derived from employee + time so the bytes aren't
    // thrown away; the alias pointer will still resolve it.
    $basis = (isset($meta['employeeNo']) ? $meta['employeeNo'] : 'unknown') . '|' .
             (isset($meta['time']) ? $meta['time'] : (string)microtime(true));
    $serial = 'nos_' . substr(sha1($basis), 0, 24);
    hik_log("WARN  event had no serialNo; stored under synthetic key {$serial}");
}

// If the device sent several frames for one event (picturesNumber > 1), keep
// the largest — the extra frames are typically a smaller cropped face thumb
// and the full scene shot, and the bigger one is the more useful of the two.
usort($images, function ($a, $b) {
    return strlen($b['data']) - strlen($a['data']);
});

$saved = false;
foreach ($images as $image) {
    $check = HikvisionAPI::checkPhotoValidity($image['data']);
    if (!$check['valid']) {
        hik_log("REJECT part '{$image['name']}' for serial={$serial}: {$check['reason']}" .
                ' bytes=' . strlen($image['data']));
        continue;
    }

    if (hik_store_save($serial, $image['data'], $meta)) {
        hik_log("SAVED serial={$serial} who={$who} bytes=" . strlen($image['data']) .
                ' part=' . $image['name'] .
                ' time=' . (isset($meta['time']) ? $meta['time'] : '?'));
        $saved = true;
        break;
    }

    hik_log("ERROR could not write photo for serial={$serial} — check that " .
            HIK_PHOTO_DIR . ' exists and is writable by the web server user.');
    break;
}

if (!$saved && !empty($images)) {
    hik_log("FAIL  had " . count($images) . ' image part(s) but stored none for ' .
            "serial={$serial}");
}

hik_store_prune();
