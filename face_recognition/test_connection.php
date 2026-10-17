<?php
// test_connection.php - Simple single-request test
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/HikvisionAPI.php';
require_once __DIR__ . '/hik_config.php';

auth_check();
rbac_gate($pdo, 'face_recognition');

$ip = HIKVISION_IP;
$port = HIKVISION_PORT;
$username = HIKVISION_USERNAME;
$password = HIKVISION_PASSWORD;

echo "<h1>🔍 Hikvision Simple Test</h1>";
echo "<p>Testing: <strong>{$ip}</strong> with user: <strong>{$username}</strong></p>";
echo "<hr>";

// First, test with curl directly (bypasses our API)
echo "<h2>Step 1: Direct CURL Test</h2>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://{$ip}/ISAPI/System/deviceInfo?format=json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<p>HTTP Status: <strong>{$httpCode}</strong></p>";

if ($httpCode === 200) {
    echo "<p style='color:green;'>✅ Success! Device responded with 200 OK</p>";
    
    // Try to parse as JSON
    $json = json_decode($response, true);
    if ($json) {
        echo "<pre style='background:#1a1a2e;color:#00ff88;padding:15px;border-radius:8px;'>";
        print_r($json);
        echo "</pre>";
    } else {
        echo "<p style='color:orange;'>⚠️ Response not JSON. Raw response:</p>";
        echo "<pre style='background:#1a1a2e;color:#ffd700;padding:15px;border-radius:8px;max-height:300px;overflow:auto;'>";
        echo htmlspecialchars(substr($response, 0, 1000));
        echo "</pre>";
    }
} elseif ($httpCode === 401) {
    echo "<p style='color:orange;'>⚠️ Authentication required (401)</p>";
    echo "<p>Check username and password.</p>";
} elseif ($httpCode === 403) {
    echo "<p style='color:red;'>❌ Access Denied (403)</p>";
    echo "<p>The device is rejecting the request. It may be locked.</p>";
} else {
    echo "<p style='color:red;'>❌ HTTP Error: {$httpCode}</p>";
    echo "<pre style='background:#1a1a2e;color:#ff6b6b;padding:15px;border-radius:8px;max-height:200px;overflow:auto;'>";
    echo htmlspecialchars(substr($response, 0, 500));
    echo "</pre>";
}

if ($error) {
    echo "<p style='color:red;'>CURL Error: {$error}</p>";
}

// Check for lock message in response
if (strpos($response, 'lockStatus') !== false) {
    preg_match('/<lockStatus>(.*?)<\/lockStatus>/', $response, $matches);
    if (isset($matches[1]) && $matches[1] === 'lock') {
        preg_match('/<unlockTime>(.*?)<\/unlockTime>/', $response, $timeMatches);
        $unlockTime = isset($timeMatches[1]) ? (int)$timeMatches[1] : 0;
        $minutes = ceil($unlockTime / 60);
        echo "<p style='color:red;font-size:1.2rem;'>🔒 DEVICE IS LOCKED! Please wait {$minutes} minute(s).</p>";
    }
}

echo "<hr>";
echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
?>