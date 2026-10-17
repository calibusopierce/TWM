<?php
// ══════════════════════════════════════════════════════════════════════════════
// HikvisionAPI.php — Hikvision ISAPI Integration Handler
// ══════════════════════════════════════════════════════════════════════════════

class HikvisionAPI {
    private $baseUrl;
    private $username;
    private $password;
    private $lastError;

    public function __construct($ip, $username, $password, $port = '80') {
        $this->baseUrl = "http://{$ip}:{$port}";
        $this->username = $username;
        $this->password = $password;
        $this->lastError = null;
    }

    /**
     * Send HTTP request with Digest Authentication
     * Handles both JSON and XML responses
     */
    private function sendRequest($endpoint, $method = 'GET', $data = null, $timeout = 30) {
        $url = $this->baseUrl . $endpoint;

        // Add format=json if not already present
        if (strpos($url, 'format=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'format=json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json, application/xml, text/xml, */*'
        ));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Accept: application/json, application/xml, text/xml, */*',
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($jsonData)
                ));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->lastError = 'CURL Error: ' . $error;
            return array('code' => 0, 'data' => null, 'error' => $this->lastError);
        }

        // Check for device lock (response may be JSON or XML depending on device/firmware)
        if (strpos($response, 'lockStatus') !== false) {
            $lockStatus = null;
            $unlockTime = 0;

            $asJson = json_decode($response, true);
            if (is_array($asJson) && isset($asJson['lockStatus'])) {
                $lockStatus = $asJson['lockStatus'];
                $unlockTime = isset($asJson['unlockTime']) ? (int)$asJson['unlockTime'] : 0;
            } else {
                preg_match('/<lockStatus>(.*?)<\/lockStatus>/', $response, $matches);
                $lockStatus = $matches[1] ?? null;
                preg_match('/<unlockTime>(.*?)<\/unlockTime>/', $response, $timeMatches);
                $unlockTime = isset($timeMatches[1]) ? (int)$timeMatches[1] : 0;
            }

            if ($lockStatus === 'lock') {
                $minutes = ceil($unlockTime / 60);
                $this->lastError = "Device is locked. Please try again after {$minutes} minute(s).";
                return array('code' => $httpCode, 'data' => null, 'error' => $this->lastError);
            }
        }

        $isSuccess = ($httpCode === 200 || $httpCode === 201);

        // Try to parse as JSON
        $decoded = json_decode($response, true);
        if ($decoded !== null) {
            if (!$isSuccess) {
                $this->lastError = $this->extractDeviceError($decoded, $httpCode);
                return array('code' => $httpCode, 'data' => $decoded, 'error' => $this->lastError);
            }
            return array('code' => $httpCode, 'data' => $decoded, 'error' => null);
        }

        // Try to parse as XML
        if (strpos($response, '<?xml') !== false || strpos(trim($response), '<') === 0) {
            $xmlData = $this->xmlToArray($response);
            if ($xmlData) {
                if (!$isSuccess) {
                    $this->lastError = $this->extractDeviceError($xmlData, $httpCode);
                    return array('code' => $httpCode, 'data' => $xmlData, 'error' => $this->lastError);
                }
                return array('code' => $httpCode, 'data' => $xmlData, 'error' => null);
            }
        }

        // If HTTP code is not successful, return error
        if (!$isSuccess) {
            $this->lastError = 'HTTP Error: ' . $httpCode;
            return array('code' => $httpCode, 'data' => null, 'error' => $this->lastError);
        }

        $this->lastError = 'Unable to parse response (neither JSON nor XML)';
        return array('code' => $httpCode, 'data' => null, 'error' => $this->lastError);
    }

    /**
     * Build a readable error message from a device error body, e.g.
     * {"statusCode":6,"statusString":"Invalid Content","subStatusCode":"badParameters","errorMsg":"0x60000001"}
     * Falls back to a generic HTTP-code message if the body doesn't match the expected shape.
     */
    private function extractDeviceError($decoded, $httpCode) {
        if (is_array($decoded) && (isset($decoded['statusString']) || isset($decoded['subStatusCode']))) {
            $parts = array();
            if (isset($decoded['statusString'])) $parts[] = $decoded['statusString'];
            if (isset($decoded['subStatusCode'])) $parts[] = $decoded['subStatusCode'];
            if (isset($decoded['errorMsg'])) $parts[] = $decoded['errorMsg'];
            return 'Device error (' . $httpCode . '): ' . implode(' — ', $parts);
        }
        return 'HTTP Error: ' . $httpCode;
    }

    /**
     * Convert XML to array
     */
    private function xmlToArray($xml) {
        try {
            // Remove namespaces for simpler parsing
            $xml = preg_replace('/xmlns[^=]*="[^"]*"/', '', $xml);
            $xmlObj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xmlObj === false) {
                return null;
            }
            $json = json_encode($xmlObj);
            return json_decode($json, true);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Safely get value from array
     */
    private function getValue($array, $key, $default = '') {
        if (isset($array[$key])) {
            if (is_array($array[$key]) || is_object($array[$key])) {
                return (string)$array[$key];
            }
            return $array[$key];
        }
        return $default;
    }

    /**
     * Ensure a datetime string has seconds and a timezone offset, since this
     * device rejects startTime/endTime without one. Hardcoded to +08:00
     * (Philippines) to match Urban Tradewell's operating region — adjust if
     * this class is ever reused for a device in a different timezone.
     */
    private function normalizeDateTime($dt) {
        $dt = trim($dt);
        // "Y-m-dTH:i" (no seconds) -> add ":00"
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dt)) {
            $dt .= ':00';
        }
        // Add +08:00 offset if none present (no trailing Z or +HH:MM/-HH:MM)
        if (!preg_match('/(Z|[+-]\d{2}:\d{2})$/', $dt)) {
            $dt .= '+08:00';
        }
        return $dt;
    }

    /**
     * Get Access Control Events
     */
    public function getAccessEvents($startTime = null, $endTime = null,
                                    $eventTypes = null, $limit = 24, $offset = 0) {
        if (!$startTime) {
            $startTime = date('Y-m-d') . 'T00:00:00';
        }
        if (!$endTime) {
            $endTime = date('Y-m-d') . 'T23:59:59';
        }

        // Confirmed against the live device: it requires a timezone offset on
        // startTime/endTime (returns HTTP 400 "Invalid Content" on startTime
        // without one) — the opposite of the original code's assumption. The
        // datetime-local inputs in index.php arrive as "Y-m-dTH:i" with no
        // seconds and no offset, so normalize both here.
        $startTime = $this->normalizeDateTime($startTime);
        $endTime = $this->normalizeDateTime($endTime);

        $cond = array(
            'searchID' => uniqid('search_'),
            'searchResultPosition' => $offset,
            'maxResults' => $limit,
            // This device (DS-K1T343MWX, firmware V3.3.4) rejects the search with
            // HTTP 400 if 'major'/'minor' are omitted — they're required, not optional,
            // on this firmware. 0/0 means "all event types" per Hikvision's ISAPI docs.
            // Face-auth (major 5, minor 75) and door events (major 5, minor 76-79) share
            // the same major code, so they can't be split via major/minor alone — the
            // 'face' vs 'door' dropdown filter is applied client-side in index.php instead.
            'major' => 0,
            'minor' => 0,
            'startTime' => $startTime,
            'endTime' => $endTime
        );

        $searchData = array('AcsEventCond' => $cond);

        try {
            // NOTE: we tried adding 'security=1&iv=<random>' here (matching
            // what the device's own web UI sends), based on capturing its
            // requests in DevTools. Confirmed via live testing that this is
            // real Hikvision behavior — but it encrypts sensitive fields
            // in the WHOLE response (name, employeeNoString became garbled
            // 32-char hex strings), and did NOT fix pictureURL fetchability
            // either. Reverted. Properly using 'security=1' would require
            // implementing Hikvision's response-decryption scheme (likely
            // AES keyed off something derived from the device password,
            // using the same iv) to un-scramble every field — not attempted,
            // too much guessing risk for a proprietary, undocumented format.
            $response = $this->sendRequest(
                '/ISAPI/AccessControl/AcsEvent',
                'POST',
                $searchData,
                60
            );

            if ($response['error']) {
                return array('error' => $response['error']);
            }

            if ($response['code'] === 200 || $response['code'] === 201) {
                return $this->parseEventData($response['data']);
            }

            return array('error' => 'Unexpected response code: ' . $response['code']);
        } catch (Exception $e) {
            return array('error' => 'Exception: ' . $e->getMessage());
        }
    }

    /**
     * Parse event data from array (could be from JSON or XML)
     */
    private function parseEventData($data) {
        $events = array();
        $total = 0;

        // Handle different response structures
        $eventList = null;

        // This device (and Hikvision's own ISAPI docs) return AcsEvent.InfoList,
        // not EventList.AcsEvent — that assumption in the original code was wrong
        // and silently produced "no events found" even on a successful 200.
        // Keeping EventList as a fallback in case a different model/firmware uses it.
        if (isset($data['AcsEvent']['InfoList'])) {
            $eventList = $data['AcsEvent']['InfoList'];
        } elseif (isset($data['EventList']['AcsEvent'])) {
            $eventList = $data['EventList']['AcsEvent'];
        } elseif (isset($data['EventList']['Event'])) {
            $eventList = $data['EventList']['Event'];
        }

        if ($eventList) {
            // Check if it's a single event or multiple
            if (isset($eventList['major']) || isset($eventList['eventID']) || isset($eventList['@attributes'])) {
                $eventList = array($eventList);
            }

            foreach ($eventList as $event) {
                // Handle SimpleXML objects
                if (is_object($event) && method_exists($event, '__toString')) {
                    $event = (array)$event;
                }

                $major = (int)$this->getValue($event, 'major', 0);
                $minor = (int)$this->getValue($event, 'minor', 0);
                $doorNo = $this->getValue($event, 'doorNo');

                $events[] = array(
                    'eventID' => $this->getValue($event, 'eventID'),
                    // The device's own unique per-event identifier is
                    // 'serialNo' — 'eventID' doesn't actually exist in this
                    // firmware's response and was always empty. Kept
                    // alongside it rather than replacing it, in case another
                    // firmware does send eventID.
                    'serialNo' => $this->getValue($event, 'serialNo'),
                    'employeeID' => $this->getValue($event, 'employeeNoString'),
                    'name' => $this->getValue($event, 'name'),
                    'cardNo' => $this->getValue($event, 'cardNo'),
                    'eventType' => $this->getEventType($major, $minor),
                    'doorName' => $doorNo !== '' ? ('Door ' . $doorNo) : $this->getValue($event, 'doorName', 'Unknown'),
                    'time' => $this->getValue($event, 'time'),
                    'status' => $this->getValue($event, 'status', 'Success'),
                    'pictureURL' => $this->getValue($event, 'pictureURL')
                );
            }
        }

        if (isset($data['AcsEvent']['totalMatches'])) {
            $total = (int)$data['AcsEvent']['totalMatches'];
        } elseif (isset($data['EventList']['numOfRecords'])) {
            $total = (int)$data['EventList']['numOfRecords'];
        }

        return array(
            'events' => $events,
            'total' => $total
        );
    }

    /**
     * Convert event codes to readable format
     */
    private function getEventType($major, $minor) {
        $types = array(
            5 => array(
                75 => 'Authenticated via Face',
                76 => 'Door Unlocked',
                77 => 'Door Locked',
                78 => 'Door Opened',
                79 => 'Door Closed',
                80 => 'Access Denied',
                81 => 'Invalid Credential'
            )
        );

        if (isset($types[$major]) && isset($types[$major][$minor])) {
            return $types[$major][$minor];
        }

        return "Event {$major}-{$minor}";
    }

    /**
     * Fetch a snapshot image from the device (e.g. an event's pictureURL).
     * These require the same digest auth as everything else, so they can't be
     * loaded directly in an <img> tag — this is meant to be called from a
     * server-side proxy script that streams the bytes back to the browser.
     */
    public function fetchImage($pictureUrl, &$debug = null) {
        // Hikvision's AcsEvent search often returns pictureURL as a bare path
        // (no scheme/host) rather than a full URL, so resolve it against our
        // own device first instead of rejecting it outright.
        $target = parse_url($pictureUrl);
        if ($target === false) {
            return array('error' => 'Rejected: malformed image URL');
        }
        if (!isset($target['host'])) {
            $path = isset($target['path']) ? $target['path'] : '';
            if ($path === '' || $path[0] !== '/') {
                $path = '/' . $path;
            }
            $pictureUrl = $this->baseUrl . $path . (isset($target['query']) ? '?' . $target['query'] : '');
        } else {
            // Absolute URL — only allow fetching from the configured device
            // (prevents this method being used as an open image-fetch proxy
            // for arbitrary URLs / SSRF).
            $base = parse_url($this->baseUrl);
            if (!isset($base['host']) || $target['host'] !== $base['host']) {
                return array('error' => 'Rejected: image URL host does not match the configured device');
            }
        }

        // NOTE: we intentionally do NOT strip the '@WEB...' suffix here.
        // It looks like a throwaway internal marker, but it isn't — it's
        // part of what tells the device which specific captured frame to
        // serve. Requesting the URL with it stripped still gets HTTP 200
        // and a valid JPEG back, but it's the device's generic/placeholder
        // frame (renders as a solid green block), not the actual capture.
        // face_image.php's candidate list intentionally does NOT include a
        // stripped-suffix variant for this reason — always request the
        // original, untouched URL.
        //
        // IMPORTANT: even the untouched URL isn't safe from this. If the
        // device has already purged/overwritten its capture buffer by the
        // time this request reaches it (which happens fast — see
        // hik_receiver.php), it returns the SAME kind of generic corrupted
        // frame for the correct URL too. That frame still has valid JPEG
        // start/end markers, so a markers-only check can't tell it apart
        // from a real photo. checkPhotoValidity() below also samples actual
        // pixel data to catch this.

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pictureUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // The ISAPI JSON endpoints on this device require Digest, but the
        // static /LOCALS/pic/ picture-serving path only accepts Basic (or
        // has a broken Digest implementation for that subsystem) — let curl
        // negotiate whichever scheme the device actually challenges with.
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        // Some Hikvision firmware serves /LOCALS/pic/ from a static-file
        // subsystem that filters on User-Agent, separate from the ISAPI
        // JSON API (which works fine via curl's default UA elsewhere in
        // this class) — send a browser-like UA for this request only.
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        // Fill diagnostic info regardless of outcome, so a caller debugging
        // "wrong image" issues (as opposed to outright fetch failures) can
        // see exactly what came back — byte count and the first/last bytes
        // are usually enough to tell a real capture apart from a device
        // placeholder frame without eyeballing the rendered image.
        $debug = array(
            'requestedUrl' => $pictureUrl,
            'httpCode'     => $httpCode,
            'curlError'    => $error ?: null,
            'contentType'  => $contentType,
            'byteLength'   => is_string($data) ? strlen($data) : 0,
            'first8Hex'    => is_string($data) ? bin2hex(substr($data, 0, 8)) : '',
            'last8Hex'     => is_string($data) ? bin2hex(substr($data, -8)) : '',
        );

        if ($error) {
            return array('error' => 'CURL Error: ' . $error);
        }
        if ($httpCode !== 200) {
            return array('error' => 'HTTP Error: ' . $httpCode);
        }

        // The device can return HTTP 200 with a corrupted or placeholder
        // frame instead of the real capture — either because the buffer
        // hadn't finished writing yet, or (more commonly) because it had
        // already been purged/overwritten by the time this request arrived.
        // Reject anything that doesn't pass checkPhotoValidity() so callers
        // don't cache or serve a broken frame — they can retry later.
        $check = self::checkPhotoValidity($data);
        if (!$check['valid']) {
            return array('error' => $check['reason']);
        }

        return array('data' => $data, 'contentType' => $contentType ?: 'image/jpeg');
    }

    /**
     * Decide whether binary data is a genuine captured photo, as opposed to
     * a truncated download or one of the device's generic/corrupted
     * placeholder frames. Two checks, in order:
     *
     *   1. Marker check — must have a proper JPEG start-of-image AND
     *      end-of-image marker. Catches plain truncated downloads.
     *   2. Blank-frame check — a truncated JPEG can still end up with both
     *      markers present (some encoders pad it), but decodes as a single
     *      flat color because the missing chroma data defaults to one
     *      value (this device's generic frame renders as solid green). A
     *      real photo has visible texture; a flat color across sampled
     *      points does not. This needs the GD extension — if it isn't
     *      available, this check is skipped (marker check still applies).
     *
     * Returns array('valid' => bool, 'reason' => string|null).
     */
    public static function checkPhotoValidity($data) {
        if (!is_string($data) || strlen($data) < 4) {
            return array('valid' => false, 'reason' => 'Empty or too-short image data');
        }
        $hasStart = (substr($data, 0, 2) === "\xFF\xD8");
        $hasEnd = (substr($data, -2) === "\xFF\xD9");
        if (!$hasStart || !$hasEnd) {
            return array('valid' => false, 'reason' => 'Incomplete image data (missing JPEG start/end marker)');
        }

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring($data);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                if ($w >= 2 && $h >= 2) {
                    // Sample a 5x5 grid of points spread across the frame.
                    $first = null;
                    $maxDiff = 0;
                    for ($fx = 0.1; $fx <= 0.9; $fx += 0.2) {
                        for ($fy = 0.1; $fy <= 0.9; $fy += 0.2) {
                            $rgb = imagecolorat($img, (int)($w * $fx), (int)($h * $fy));
                            $r = ($rgb >> 16) & 0xFF;
                            $g = ($rgb >> 8) & 0xFF;
                            $b = $rgb & 0xFF;
                            if ($first === null) {
                                $first = array($r, $g, $b);
                                continue;
                            }
                            $diff = abs($r - $first[0]) + abs($g - $first[1]) + abs($b - $first[2]);
                            if ($diff > $maxDiff) $maxDiff = $diff;
                        }
                    }
                    imagedestroy($img);
                    // A real captured face/photo has real texture. Flag
                    // anything where every sampled point across the frame
                    // is within a few shades of the first one as a flat
                    // placeholder frame, not a genuine photo.
                    if ($maxDiff < 12) {
                        return array('valid' => false, 'reason' => 'Rejected: image is a flat/blank frame (device placeholder, not the real capture)');
                    }
                } else {
                    imagedestroy($img);
                }
            }
            // If imagecreatefromstring() failed to decode at all, don't
            // treat that alone as invalid — the marker check above already
            // passed, and a decode hiccup shouldn't be stricter than that.
        }

        return array('valid' => true, 'reason' => null);
    }

    /**
     * Get device information
     */
    public function getDeviceInfo() {
        try {
            $response = $this->sendRequest('/ISAPI/System/deviceInfo', 'GET', null, 15);
            if ($response['error']) {
                $this->lastError = $response['error'];
                return null;
            }
            if ($response['code'] === 200) {
                return $response['data'];
            }
            $this->lastError = 'HTTP ' . $response['code'];
            return null;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Get last error
     */
    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Get persons/employees from face database
     */
    public function getPersons($page = 1, $pageSize = 50) {
        try {
            $searchData = array(
                'PersonInfoSearchCond' => array(
                    'searchID' => uniqid('person_search_'),
                    'searchResultPosition' => ($page - 1) * $pageSize,
                    'maxResults' => $pageSize
                )
            );

            $response = $this->sendRequest(
                '/ISAPI/Intelligent/FDLib/FaceDataRecord',
                'POST',
                $searchData,
                30
            );

            if ($response['error']) {
                return array('error' => $response['error']);
            }

            if ($response['code'] === 200 || $response['code'] === 201) {
                return $this->parsePersonData($response['data']);
            }
            return array('error' => 'HTTP ' . $response['code']);
        } catch (Exception $e) {
            return array('error' => 'Exception: ' . $e->getMessage());
        }
    }

    private function parsePersonData($data) {
        $persons = array();

        $personList = null;
        if (isset($data['FaceDataRecordList']['FaceDataRecord'])) {
            $personList = $data['FaceDataRecordList']['FaceDataRecord'];
        } elseif (isset($data['FaceDataRecord'])) {
            $personList = $data['FaceDataRecord'];
        }

        if ($personList) {
            if (isset($personList['faceID'])) {
                $personList = array($personList);
            }

            foreach ($personList as $person) {
                if (is_object($person) && method_exists($person, '__toString')) {
                    $person = (array)$person;
                }

                $persons[] = array(
                    'id' => $this->getValue($person, 'faceID'),
                    'name' => $this->getValue($person, 'name'),
                    'employeeId' => $this->getValue($person, 'employeeNoString'),
                    'cardNo' => $this->getValue($person, 'cardNo')
                );
            }
        }

        return array('persons' => $persons, 'total' => count($persons));
    }
}