<?php
// ══════════════════════════════════════════════════════════════════════════════
// hik_photo_store.php — on-disk store for captured event photos.
//
// Both hik_event_listener.php (writer) and face_image.php (reader) go through
// here so they can never disagree about how a photo is keyed. Keying is by the
// device's own per-event 'serialNo', which appears both in the pushed event
// payload and in AcsEvent search results, so a dashboard row and a pushed
// photo line up without any matching heuristics.
//
// A secondary "alias" key (employeeNo + event time) is also written as a small
// pointer file. That covers the case where a firmware omits serialNo from one
// side or the other — rather than losing the photo, the reader can still find
// it. Pointer files are cheap and avoid the read-modify-write locking that a
// single shared JSON index would need under concurrent pushes.
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/hik_config.php';

/**
 * Make sure the photo directory exists. Returns the path, or null if it
 * couldn't be created.
 */
function hik_store_dir() {
    $dir = HIK_PHOTO_DIR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
    }
    return $dir;
}

/**
 * Reduce any device-supplied string to something safe to use as a filename.
 * Everything outside [A-Za-z0-9_-] is dropped, and the result is length
 * capped. Returns '' if nothing usable survives, which callers must treat as
 * "no key" rather than as a valid empty key.
 */
function hik_store_safe_key($raw) {
    if (!is_scalar($raw)) {
        return '';
    }
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$raw);
    return substr($key, 0, 64);
}

/**
 * Primary path for a photo, keyed by the device's event serial number.
 */
function hik_store_path($serialNo) {
    $key = hik_store_safe_key($serialNo);
    if ($key === '') {
        return null;
    }
    $dir = hik_store_dir();
    return $dir === null ? null : $dir . '/' . $key . '.jpg';
}

/**
 * Sidecar metadata path for a photo (employee, name, time, event codes).
 */
function hik_store_meta_path($serialNo) {
    $key = hik_store_safe_key($serialNo);
    if ($key === '') {
        return null;
    }
    $dir = hik_store_dir();
    return $dir === null ? null : $dir . '/' . $key . '.json';
}

/**
 * Build the secondary alias key from an employee number and an event time.
 * The time is normalised to whole seconds in UTC first, so that the same
 * moment expressed with a different timezone offset (the device sends
 * +08:00; a DB round-trip might not) still produces the same key.
 */
function hik_store_alias_key($employeeNo, $time) {
    $employeeNo = trim((string)$employeeNo);
    $time = trim((string)$time);
    if ($employeeNo === '' || $time === '') {
        return '';
    }
    $ts = strtotime($time);
    if ($ts === false) {
        return '';
    }
    return 'a_' . substr(sha1($employeeNo . '|' . gmdate('Y-m-d\TH:i:s', $ts)), 0, 32);
}

/**
 * Alias pointer path. The file contains just the serial key it points at.
 */
function hik_store_alias_path($employeeNo, $time) {
    $key = hik_store_alias_key($employeeNo, $time);
    if ($key === '') {
        return null;
    }
    $dir = hik_store_dir();
    return $dir === null ? null : $dir . '/' . $key . '.ptr';
}

/**
 * Write a validated photo to the store.
 *
 * $bytes MUST already have passed HikvisionAPI::checkPhotoValidity() — this
 * function does not re-check, because callers differ in what they want to do
 * with a rejection (the listener logs it, face_image.php falls through to the
 * next candidate).
 *
 * Writes are done to a temp file then renamed, so a reader can never observe
 * a half-written JPEG. Returns true on success.
 */
function hik_store_save($serialNo, $bytes, $meta = array()) {
    $path = hik_store_path($serialNo);
    if ($path === null || !is_string($bytes) || $bytes === '') {
        return false;
    }

    $tmp = $path . '.tmp' . uniqid();
    if (@file_put_contents($tmp, $bytes) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);

    // Sidecar metadata — useful for auditing and for the alias lookup below.
    $metaPath = hik_store_meta_path($serialNo);
    if ($metaPath !== null) {
        $meta['storedAt'] = date('c');
        $meta['bytes'] = strlen($bytes);
        @file_put_contents($metaPath, json_encode($meta));
    }

    // A stored photo must never sit behind a stale "we failed" marker.
    hik_store_clear_miss($serialNo);

    // Alias pointer, when we know who and when.
    $employeeNo = isset($meta['employeeNo']) ? $meta['employeeNo'] : '';
    $time = isset($meta['time']) ? $meta['time'] : '';
    $aliasPath = hik_store_alias_path($employeeNo, $time);
    if ($aliasPath !== null) {
        @file_put_contents($aliasPath, hik_store_safe_key($serialNo));
    }

    return true;
}

/**
 * Look up a stored photo for a dashboard event row.
 *
 * $event is the associative array produced by HikvisionAPI::parseEventData(),
 * so it may carry 'serialNo', 'employeeID' and 'time'. Tries the serial key
 * first, then the alias pointer. Returns an absolute path to a readable .jpg,
 * or null.
 */
function hik_store_lookup($event) {
    if (!is_array($event)) {
        return null;
    }

    $serial = isset($event['serialNo']) ? $event['serialNo'] : '';
    $path = hik_store_path($serial);
    if ($path !== null && is_file($path) && filesize($path) > 0) {
        return $path;
    }

    $employeeNo = isset($event['employeeID']) ? $event['employeeID'] : '';
    $time = isset($event['time']) ? $event['time'] : '';
    $aliasPath = hik_store_alias_path($employeeNo, $time);
    if ($aliasPath !== null && is_file($aliasPath)) {
        $pointed = hik_store_safe_key(trim((string)@file_get_contents($aliasPath)));
        if ($pointed !== '') {
            $target = hik_store_path($pointed);
            if ($target !== null && is_file($target) && filesize($target) > 0) {
                return $target;
            }
        }
    }

    return null;
}

/**
 * Cheap existence check for the dashboard, so index.php can decide whether to
 * render an <img> for a row whose pictureURL is empty but whose photo we
 * happen to have from a push.
 */
function hik_store_has($event) {
    return hik_store_lookup($event) !== null;
}

// ══════════════════════════════════════════════════════════════════════════
// NEGATIVE CACHE
//
// When every source fails for an event, record that. Without this, each page
// load re-attempts the same hopeless fetch for every photo-less row — 24
// concurrent requests at a door terminal that answers slowly and locks itself
// out after enough failures. A miss marker is a zero-byte file whose mtime is
// the only thing that matters.
//
// Misses are deliberately NOT permanent: a pushed photo can arrive later
// (listener restarted, clock skew, a retransmitted event), so the marker
// expires after HIK_MISS_TTL and the event gets another chance.
// ══════════════════════════════════════════════════════════════════════════

function hik_store_miss_path($serialNo) {
    $key = hik_store_safe_key($serialNo);
    if ($key === '') {
        return null;
    }
    $dir = hik_store_dir();
    return $dir === null ? null : $dir . '/' . $key . '.miss';
}

/**
 * Record that this event has no obtainable photo right now.
 */
function hik_store_mark_miss($serialNo) {
    $path = hik_store_miss_path($serialNo);
    if ($path === null) {
        return false;
    }
    return @file_put_contents($path, '') !== false;
}

/**
 * Has this event failed recently enough that retrying is pointless?
 */
function hik_store_recent_miss($serialNo) {
    if (!defined('HIK_MISS_TTL') || (int)HIK_MISS_TTL <= 0) {
        return false;
    }
    $path = hik_store_miss_path($serialNo);
    if ($path === null || !is_file($path)) {
        return false;
    }
    if ((time() - (int)@filemtime($path)) < (int)HIK_MISS_TTL) {
        return true;
    }
    @unlink($path);   // expired; let the caller try again
    return false;
}

/**
 * Drop the miss marker — called once a photo actually lands, so a stored
 * photo is never shadowed by a stale marker.
 */
function hik_store_clear_miss($serialNo) {
    $path = hik_store_miss_path($serialNo);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Age of an event in seconds, or null if its timestamp is unusable.
 */
function hik_event_age_seconds($event) {
    if (!is_array($event) || empty($event['time'])) {
        return null;
    }
    $ts = strtotime(trim((string)$event['time']));
    return $ts === false ? null : (time() - $ts);
}

/**
 * Single source of truth for "what should we do about this row's photo?",
 * shared by index.php (which decides whether to emit an <img> at all) and
 * face_image.php (which decides whether to touch the device). Keeping one
 * function means the dashboard can never request something the proxy was
 * always going to refuse.
 *
 *   'stored' — we hold the photo; serve it from disk.
 *   'try'    — no photo, but a live attempt has a real chance.
 *   'skip'   — don't bother: no URL, a recent failure, or too old to exist.
 */
function hik_store_decide($event) {
    if (hik_store_lookup($event) !== null) {
        return 'stored';
    }

    if (empty($event['pictureURL'])) {
        return 'skip';
    }

    $serial = isset($event['serialNo']) ? $event['serialNo'] : '';
    if ($serial !== '' && hik_store_recent_miss($serial)) {
        return 'skip';
    }

    $maxAge = defined('HIK_LIVE_FETCH_MAX_AGE') ? (int)HIK_LIVE_FETCH_MAX_AGE : 0;
    if ($maxAge > 0) {
        $age = hik_event_age_seconds($event);
        // An unparseable timestamp is not grounds for skipping — better one
        // wasted request than a silently blank column.
        if ($age !== null && $age > $maxAge) {
            return 'skip';
        }
    }

    return 'try';
}

/**
 * Delete photos, sidecars and pointers older than HIK_PHOTO_RETENTION_DAYS.
 *
 * Called opportunistically from the listener rather than on a cron, and
 * rate-limited by a stamp file so a busy door doesn't scan the directory on
 * every single event. A no-op when retention is 0.
 */
function hik_store_prune($minIntervalSeconds = 3600) {
    if (!defined('HIK_PHOTO_RETENTION_DAYS') || (int)HIK_PHOTO_RETENTION_DAYS <= 0) {
        return;
    }
    $dir = hik_store_dir();
    if ($dir === null) {
        return;
    }

    $stamp = $dir . '/.last_prune';
    if (is_file($stamp) && (time() - filemtime($stamp)) < $minIntervalSeconds) {
        return;
    }
    @file_put_contents($stamp, (string)time());

    $cutoff = time() - ((int)HIK_PHOTO_RETENTION_DAYS * 86400);
    foreach (array('*.jpg', '*.json', '*.ptr', '*.miss') as $pattern) {
        $files = glob($dir . '/' . $pattern);
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
