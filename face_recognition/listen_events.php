<?php
// ══════════════════════════════════════════════════════════════════════════════
// listen_events.php — Long-lived listener on the device's alertStream.
// Caches each event's photo the instant it fires, instead of waiting for a
// dashboard load or cron poll to catch it before the device purges it.
// Meant to run as a persistent background service (see hik-listener.service),
// NOT via cron — this script is designed to stay connected indefinitely.
//
// Before trusting this in production: run it manually in a terminal
// (`php listen_events.php`), trigger a real badge/face event at the device,
// and confirm cache/listener.log shows "Cached photo for serial=...". The
// exact multipart framing of alertStream can vary by firmware, so this
// parser is defensive (discovers the boundary from response headers rather
// than hardcoding one) but hasn't been verified yet against this specific
// device/firmware.
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';

$logFile = __DIR__ . '/cache/listener.log';
function logmsg($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . "] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line; // also visible via `journalctl -u <service> -f`
}

$photoCacheDir = __DIR__ . '/cache/photos';
if (!is_dir($photoCacheDir)) {
    mkdir($photoCacheDir, 0755, true);
}

$api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

function cachePhoto($api, $serialNo, $pictureUrl) {
    global $photoCacheDir;
    if ($serialNo === '' || $pictureUrl === '') return;
    $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '', $serialNo);
    $photoFile = $photoCacheDir . '/' . $safeSerial . '.jpg';
    if (file_exists($photoFile)) return;

    $result = $api->fetchImage($pictureUrl);
    if (!empty($result['data'])) {
        file_put_contents($photoFile, $result['data']);
        logmsg("Cached photo for serial={$safeSerial}");
    } elseif (isset($result['error'])) {
        // Normal right as an event fires — device hasn't finished writing
        // the frame yet. Not fatal: falls through to index.php's own
        // pre-cache loop as a safety net.
        logmsg("Not ready yet, serial={$safeSerial}: {$result['error']}");
    }
}

function cachePhotoFromBytes($serialNo, $bytes) {
    global $photoCacheDir;
    if ($serialNo === '' || $bytes === '') return;
    $safeSerial = preg_replace('/[^A-Za-z0-9_-]/', '', $serialNo);
    $photoFile = $photoCacheDir . '/' . $safeSerial . '.jpg';
    if (file_exists($photoFile)) return;
    if (substr($bytes, 0, 2) !== "\xFF\xD8") {
        logmsg("Inline part for serial={$safeSerial} doesn't look like a JPEG (first8hex=" . bin2hex(substr($bytes, 0, 8)) . "), skipping");
        return;
    }
    file_put_contents($photoFile, $bytes);
    logmsg("Cached photo for serial={$safeSerial} (from inline alertStream part)");
}

/**
 * Streaming multipart parser for the alertStream body. Frames each part by
 * its own Content-Length header rather than naive boundary-splitting, since
 * a JPEG body could coincidentally contain the boundary token as raw bytes.
 */
class AlertStreamParser {
    private $buffer = '';
    private $state = 'SEEK_BOUNDARY';
    private $boundaryToken;
    private $headers = array();
    private $bodyRemaining = 0;
    private $onPart;

    public function __construct($boundaryToken, $onPart) {
        $this->boundaryToken = $boundaryToken ?: 'boundary';
        $this->onPart = $onPart;
    }

    public function feed($chunk) {
        $this->buffer .= $chunk;
        $this->process();
    }

    private function process() {
        while (true) {
            if ($this->state === 'SEEK_BOUNDARY') {
                $needle = '--' . $this->boundaryToken;
                $pos = strpos($this->buffer, $needle);
                if ($pos === false) return; // wait for more data
                $lineEnd = strpos($this->buffer, "\r\n", $pos);
                if ($lineEnd === false) return;
                $this->buffer = substr($this->buffer, $lineEnd + 2);
                $this->headers = array();
                $this->state = 'READ_HEADERS';
            } elseif ($this->state === 'READ_HEADERS') {
                $headerEnd = strpos($this->buffer, "\r\n\r\n");
                if ($headerEnd === false) return;
                foreach (explode("\r\n", substr($this->buffer, 0, $headerEnd)) as $line) {
                    if (strpos($line, ':') !== false) {
                        list($k, $v) = explode(':', $line, 2);
                        $this->headers[strtolower(trim($k))] = trim($v);
                    }
                }
                $this->buffer = substr($this->buffer, $headerEnd + 4);
                if (!isset($this->headers['content-length'])) {
                    // Can't safely frame this part without a length — drop
                    // back to boundary search rather than risk misreading.
                    $this->state = 'SEEK_BOUNDARY';
                    continue;
                }
                $this->bodyRemaining = (int)$this->headers['content-length'];
                $this->state = 'READ_BODY';
            } elseif ($this->state === 'READ_BODY') {
                if (strlen($this->buffer) < $this->bodyRemaining) return;
                $body = substr($this->buffer, 0, $this->bodyRemaining);
                $this->buffer = substr($this->buffer, $this->bodyRemaining);
                $contentType = isset($this->headers['content-type']) ? $this->headers['content-type'] : '';
                call_user_func($this->onPart, $contentType, $body);
                $this->state = 'SEEK_BOUNDARY';
            }
        }
    }
}

// ── Main connect/reconnect loop ─────────────────────────────────────────────
$backoff = 2;
while (true) {
    logmsg("Connecting to alertStream...");
    $boundaryToken = null;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://' . HIKVISION_IP . ':' . HIKVISION_PORT . '/ISAPI/Event/notification/alertStream');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
    curl_setopt($ch, CURLOPT_USERPWD, HIKVISION_USERNAME . ':' . HIKVISION_PASSWORD);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);          // stream stays open indefinitely
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$boundaryToken) {
        if (preg_match('/boundary=([^;\r\n]+)/i', $headerLine, $m)) {
            $boundaryToken = trim($m[1], "\"'");
        }
        return strlen($headerLine);
    });

    $parser = null;
    $pendingEvent = null; // holds serial/pictureURL from the last XML part, in case an inline image part follows
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$parser, &$boundaryToken, &$pendingEvent, $api) {
        if ($parser === null) {
            $parser = new AlertStreamParser($boundaryToken, function ($contentType, $body) use ($api, &$pendingEvent) {
                if (stripos($contentType, 'image') !== false && $pendingEvent !== null) {
                    cachePhotoFromBytes($pendingEvent['serial'], $body);
                    $pendingEvent = null;
                    return;
                }
                if (stripos($contentType, 'json') === false) return;

                $data = json_decode($body, true);
                if (!is_array($data)) return;

                // Heartbeat/status pings look like {"ipAddress":...} with no event fields — skip silently.
                if (!isset($data['eventType']) && !isset($data['AccessControllerEvent'])) return;

                logmsg("Event JSON received: " . substr($body, 0, 1500));

                // Best-guess field paths — adjust once we see a real payload's actual keys.
                $ace = isset($data['AccessControllerEvent']) ? $data['AccessControllerEvent'] : $data;
                $serial = isset($ace['serialNo']) ? $ace['serialNo'] : (isset($data['serialNo']) ? $data['serialNo'] : '');
                $pictureUrl = isset($ace['pictureURL']) ? $ace['pictureURL'] : (isset($data['pictureURL']) ? $data['pictureURL'] : '');

                if ($serial === '') return;
                $pendingEvent = array('serial' => $serial, 'pictureURL' => $pictureUrl);
                if ($pictureUrl !== '') {
                    cachePhoto($api, $serial, $pictureUrl); // fallback path if no inline image part follows
                }
            });
        }
        $parser->feed($chunk);
        return strlen($chunk); // must return bytes consumed or curl aborts
    });

    curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // The device closes this connection periodically by design — normal,
    // not a failure. Reconnect promptly, with backoff only on real errors.
    logmsg("Stream ended (httpCode={$httpCode}" . ($error ? ", error={$error}" : '') . "), reconnecting in {$backoff}s");
    sleep($backoff);
    $backoff = ($httpCode === 200) ? 2 : min($backoff * 2, 30);
}
