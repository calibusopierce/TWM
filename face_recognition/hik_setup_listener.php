<?php
// ══════════════════════════════════════════════════════════════════════════════
// hik_setup_listener.php — register this server as a listening host on the
// terminal, and diagnose why photos aren't arriving.
//
// Run this once after filling in the listener settings in hik_config.php.
// Read-only until you press Apply. Behind the same RBAC gate as the rest of
// the module, and refuses to write anything for a view-only user.
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';

auth_check();
rbac_gate($pdo, 'face_recognition');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');

$isViewOnly = rbac_is_view_only('face_recognition');

require_once __DIR__ . '/hik_config.php';
require_once __DIR__ . '/HikvisionAPI.php';
require_once __DIR__ . '/hik_photo_store.php';

$api = new HikvisionAPI(HIKVISION_IP, HIKVISION_USERNAME, HIKVISION_PASSWORD, HIKVISION_PORT);

// The path we want the device to call back on, token included.
$callbackPath = TWM_LISTENER_PATH . '?token=' . rawurlencode(HIK_LISTENER_TOKEN);
$callbackFull = 'http://' . TWM_LISTENER_IP . ':' . TWM_LISTENER_PORT . $callbackPath;

$action = isset($_POST['action']) ? $_POST['action'] : '';
$results = array();

// ─── Configuration sanity checks ─────────────────────────────────────────────
$problems = array();
if (HIK_LISTENER_TOKEN === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || strlen(HIK_LISTENER_TOKEN) < 16) {
    $problems[] = 'HIK_LISTENER_TOKEN is still the placeholder (or is too short). ' .
                  'Set a long random value in hik_config.php first.';
}
if (TWM_LISTENER_IP === '192.168.100.10') {
    $problems[] = 'TWM_LISTENER_IP still holds the example address. It must be the ' .
                  'LAN IP of THIS web server as the device sees it — not 127.0.0.1.';
}
if (in_array(TWM_LISTENER_IP, array('127.0.0.1', 'localhost', '::1'), true)) {
    $problems[] = 'TWM_LISTENER_IP is a loopback address. The device opens its own ' .
                  'connection, so loopback can never work.';
}
$photoDir = hik_store_dir();
if ($photoDir === null) {
    $problems[] = 'Could not create ' . HIK_PHOTO_DIR . '.';
} elseif (!is_writable($photoDir)) {
    $problems[] = $photoDir . ' is not writable by the web server user. ' .
                  'Pushed photos will be dropped.';
}

// ─── Apply ───────────────────────────────────────────────────────────────────
if ($action === 'apply' && !$isViewOnly && empty($problems)) {
    $put = $api->setHttpHost(
        HIK_HTTP_HOST_ID,
        TWM_LISTENER_IP,
        TWM_LISTENER_PORT,
        $callbackPath,
        'JSON'
    );
    $results['PUT /ISAPI/Event/notification/httpHosts'] = $put;

    // Read it straight back — a 200 on the PUT doesn't guarantee the device
    // kept what we sent.
    $results['GET /ISAPI/Event/notification/httpHosts (after write)'] = $api->getHttpHosts();
}

if ($action === 'test' && !$isViewOnly) {
    $results['POST .../httpHosts/' . HIK_HTTP_HOST_ID . '/test'] = $api->testHttpHost(HIK_HTTP_HOST_ID);
}

// ─── Always-on diagnostics ───────────────────────────────────────────────────
$deviceInfo = $api->getDeviceInfo();
$diagnostics = array(
    'GET /ISAPI/Event/notification/httpHosts/capabilities' => $api->getHttpHostCapabilities(),
    'GET /ISAPI/Event/notification/httpHosts'              => $api->getHttpHosts(),
    'GET .../httpHosts/' . HIK_HTTP_HOST_ID . '/uploadCtrl' => $api->getHttpHostUploadCtrl(HIK_HTTP_HOST_ID),
);

// ─── Listener activity ───────────────────────────────────────────────────────
$logTail = '(no log yet — the device has not pushed anything, or the log is not writable)';
if (is_file(HIK_LISTENER_LOG)) {
    $lines = @file(HIK_LISTENER_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines) && !empty($lines)) {
        $logTail = implode("\n", array_slice($lines, -40));
    }
}

$photoCount = 0;
$newestPhoto = null;
if ($photoDir !== null) {
    $jpgs = glob($photoDir . '/*.jpg');
    if (is_array($jpgs)) {
        $photoCount = count($jpgs);
        foreach ($jpgs as $jpg) {
            $mtime = @filemtime($jpg);
            if ($newestPhoto === null || $mtime > $newestPhoto) {
                $newestPhoto = $mtime;
            }
        }
    }
}

/** Render one raw ISAPI result block. */
function render_result($label, $res) {
    echo '<h3>' . htmlspecialchars($label) . '</h3>';
    echo '<pre>';
    if (!is_array($res)) {
        echo '(no result)';
    } else {
        echo 'HTTP code  : ' . htmlspecialchars((string)($res['code'] ?? '')) . "\n";
        echo 'curl error : ' . htmlspecialchars((string)($res['error'] ?? '(none)')) . "\n";
        echo 'type       : ' . htmlspecialchars((string)($res['contentType'] ?? '')) . "\n";
        echo "body       :\n";
        $body = isset($res['body']) ? (string)$res['body'] : '';
        echo htmlspecialchars(substr($body, 0, 4000));
        if (strlen($body) > 4000) {
            echo "\n… (truncated)";
        }
    }
    echo '</pre>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Hikvision Push Listener Setup</title>
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
         max-width: 980px; margin: 2rem auto; padding: 0 1rem;
         background: #12141a; color: #e6e8ee; line-height: 1.55; }
  h1 { font-size: 1.4rem; }
  h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 1px solid #2a2e3a;
       padding-bottom: .35rem; }
  h3 { font-size: .85rem; margin: 1.2rem 0 .3rem; color: #9aa3b8;
       font-family: ui-monospace, monospace; font-weight: 600; }
  pre { background: #0b0d12; border: 1px solid #2a2e3a; border-radius: 6px;
        padding: .75rem; overflow-x: auto; font-size: .78rem; white-space: pre-wrap;
        word-break: break-word; }
  code { background: #0b0d12; padding: .1rem .3rem; border-radius: 3px; font-size: .85em; }
  .warn { background: #3a2a10; border: 1px solid #7a5a1a; color: #ffd48a;
          padding: .8rem 1rem; border-radius: 6px; margin: 1rem 0; }
  .ok   { background: #12301c; border: 1px solid #1f6b38; color: #9be8b4;
          padding: .8rem 1rem; border-radius: 6px; margin: 1rem 0; }
  button { background: #2563eb; color: #fff; border: 0; padding: .55rem 1.1rem;
           border-radius: 6px; font-size: .9rem; cursor: pointer; margin-right: .5rem; }
  button.secondary { background: #374151; }
  button[disabled] { opacity: .45; cursor: not-allowed; }
  table { border-collapse: collapse; width: 100%; font-size: .85rem; }
  td { padding: .35rem .6rem; border-bottom: 1px solid #23262f; vertical-align: top; }
  td:first-child { color: #9aa3b8; width: 34%; }
</style>
</head>
<body>

<h1>Hikvision push listener — setup &amp; diagnostics</h1>

<p>This registers TWM as a <em>listening host</em> on the terminal. Once active, the
device POSTs each authentication event <strong>and its captured JPEG</strong> to TWM at the
moment of capture, instead of TWM asking for the picture afterwards and losing the
race against the device's capture buffer.</p>

<h2>1. What will be configured</h2>
<table>
  <tr><td>Device</td><td><?= htmlspecialchars(HIKVISION_IP . ':' . HIKVISION_PORT) ?>
      <?= $deviceInfo && isset($deviceInfo['deviceName']) ? ' — ' . htmlspecialchars($deviceInfo['deviceName']) : '' ?>
      <?= $deviceInfo && isset($deviceInfo['firmwareVersion']) ? ' (fw ' . htmlspecialchars($deviceInfo['firmwareVersion']) . ')' : '' ?>
  </td></tr>
  <tr><td>Host slot</td><td><?= htmlspecialchars(HIK_HTTP_HOST_ID) ?></td></tr>
  <tr><td>Callback URL</td><td><code><?= htmlspecialchars($callbackFull) ?></code></td></tr>
  <tr><td>Photo store</td><td><?= htmlspecialchars(HIK_PHOTO_DIR) ?>
      — <?= (int)$photoCount ?> photo(s)<?php
        if ($newestPhoto) { echo ', newest ' . htmlspecialchars(date('Y-m-d H:i:s', $newestPhoto)); }
      ?></td></tr>
</table>

<?php if (!empty($problems)): ?>
  <div class="warn">
    <strong>Fix these before applying:</strong>
    <ul><?php foreach ($problems as $p) { echo '<li>' . htmlspecialchars($p) . '</li>'; } ?></ul>
  </div>
<?php else: ?>
  <div class="ok">Configuration looks usable.</div>
<?php endif; ?>

<?php if ($isViewOnly): ?>
  <div class="warn">You have view-only access to this module, so Apply and Test are disabled.</div>
<?php endif; ?>

<form method="post">
  <button type="submit" name="action" value="apply"
    <?= ($isViewOnly || !empty($problems)) ? 'disabled' : '' ?>>Apply to device</button>
  <button type="submit" name="action" value="test" class="secondary"
    <?= $isViewOnly ? 'disabled' : '' ?>>Test reachability</button>
</form>

<p style="font-size:.85rem;color:#9aa3b8;">
  <strong>Apply</strong> replaces the device's entire listening-host list with the single
  entry above — check the current list below first if anything else might be using it.
  <strong>Test</strong> asks the device to probe the callback URL; it is the fastest way to
  catch a wrong IP, a closed port, or a firewall in the way.
</p>

<?php if (!empty($results)): ?>
  <h2>2. Results</h2>
  <?php foreach ($results as $label => $res) { render_result($label, $res); } ?>
<?php endif; ?>

<h2>3. Current device state</h2>
<?php foreach ($diagnostics as $label => $res) { render_result($label, $res); } ?>

<h2>4. Listener activity</h2>
<p style="font-size:.85rem;color:#9aa3b8;">Present yourself to the terminal, then reload this
page. A <code>SAVED</code> line means the whole chain works.</p>
<pre><?= htmlspecialchars($logTail) ?></pre>

<h2>5. If nothing arrives</h2>
<ul style="font-size:.9rem;">
  <li><strong>Capabilities reply has no <code>HttpHostNotificationCap</code></strong> — this
      firmware doesn't expose push. Use the <code>alertStream</code> fallback instead
      (see the notes in <code>face_image.php</code>).</li>
  <li><strong>Test fails</strong> — the device can't reach TWM. Check that
      <code><?= htmlspecialchars(TWM_LISTENER_IP . ':' . TWM_LISTENER_PORT) ?></code> is
      correct from the device's subnet, and that the server firewall allows inbound
      from <?= htmlspecialchars(HIKVISION_IP) ?>.</li>
  <li><strong>Test passes but no log lines</strong> — the device is reaching a different
      path. Confirm <code>TWM_LISTENER_PATH</code> matches where this module actually
      lives, and re-Apply.</li>
  <li><strong>Log shows <code>NOPARSE</code></strong> — the push arrived but PHP couldn't
      read it. Set <code>HIK_LISTENER_RAW_MULTIPART</code> to true and add, in
      <code>face_recognition/.htaccess</code>:
      <pre>&lt;Files "hik_event_listener.php"&gt;
  php_flag enable_post_data_reading off
&lt;/Files&gt;</pre></li>
  <li><strong>Log shows <code>SKIP no picture</code> for face events</strong> — picture
      capture is off on the device. Enable saving/uploading the captured picture for
      authentication events in the device web UI.</li>
  <li><strong>Log shows <code>WARN … source-IP only</code></strong> — harmless, but it means
      the firmware stripped the <code>?token=</code>. Access stays limited to the
      device's own IP.</li>
</ul>

</body>
</html>
