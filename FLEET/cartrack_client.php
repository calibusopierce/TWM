<?php
/**
 * Cartrack Fleet API — client
 *
 * Usage:
 *   require_once 'cartrack_client.php';
 *   $vehicles = cartrack_get('/vehicles/status');
 *   $trips    = cartrack_get('/trips', ['filter[vehicle_id]' => 123]);
 */

function cartrack_config() {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/cartrack_config.php';
    }
    return $config;
}

/**
 * Perform a GET request against the Cartrack Fleet API.
 *
 * @param string $endpoint  e.g. '/vehicles/status', '/trips', '/drivers'
 * @param array  $params    query params, e.g. ['filter[date_from]' => '2026-08-01']
 * @return array            decoded JSON response, or ['error' => true, 'code' => ..., 'raw' => ...] on failure
 */
function cartrack_get($endpoint, $params = []) {
    $config = cartrack_config();

    $url = rtrim($config['base_url'], '/') . '/' . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $config['api_user'] . ':' . $config['api_pass']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout'] ?? 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => true, 'code' => 0, 'raw' => $curlErr];
    }

    if ($httpCode !== 200) {
        return ['error' => true, 'code' => $httpCode, 'raw' => $response];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => true, 'code' => $httpCode, 'raw' => $response];
    }

    return $decoded;
}

/**
 * POST/PUT/PATCH/DELETE helper — for endpoints that write data
 * (e.g. terminal commands, updating delivery jobs).
 *
 * @param string $method    'POST', 'PUT', 'PATCH', 'DELETE'
 * @param string $endpoint
 * @param array  $body      request payload, sent as JSON
 * @return array
 */
function cartrack_write($method, $endpoint, $body = []) {
    $config = cartrack_config();
    $url = rtrim($config['base_url'], '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_USERPWD, $config['api_user'] . ':' . $config['api_pass']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout'] ?? 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => true, 'code' => 0, 'raw' => $curlErr];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['error' => true, 'code' => $httpCode, 'raw' => $response];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => true, 'code' => $httpCode, 'raw' => $response];
    }

    return $decoded;
}
