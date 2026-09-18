<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
auth_check();
require_once __DIR__ . '/cartrack_client.php';

header('Content-Type: text/plain');

$vehicleStatus = cartrack_get('/vehicles/status');

echo "=== FULL RESPONSE ===\n";
print_r($vehicleStatus);

echo "\n\n=== FIRST VEHICLE ONLY ===\n";
if (isset($vehicleStatus['data'][0])) {
    print_r($vehicleStatus['data'][0]);
} else {
    echo "(no data[0] found — check structure above)\n";
}