<?php
/**
 * test_elapsed.php — standalone probe for Cartrack's "Get All Trips Elapsed" endpoint.
 *
 * Run this from the command line (php test_elapsed.php) or drop it in your webroot
 * and hit it in a browser. It reuses your existing cartrack_config.php, so place this
 * file in the SAME folder as cartrack_client.php / cartrack_config.php before running.
 *
 * DELETE THIS FILE after you're done testing — it dumps a raw API response which may
 * include vehicle/driver data, and it sits next to your live credentials.
 */

require_once __DIR__ . '/cartrack_client.php';

header('Content-Type: text/plain');

// --- Try the endpoint with no filters first, just to see the raw shape ---
echo "=== GET /trips/elapsed (no params) ===\n";
$result = cartrack_get('/trips/elapsed');
print_r($result);

echo "\n\n=== GET /trips/elapsed (last 24h, common param guesses) ===\n";
$result2 = cartrack_get('/trips/elapsed', [
    'start_timestamp' => date('Y-m-d H:i:s', strtotime('-24 hours')),
    'end_timestamp'   => date('Y-m-d H:i:s'),
]);
print_r($result2);

// If the first two calls 404 or error, uncomment and try alternate path guesses:
// echo "\n\n=== GET /trips/all/elapsed ===\n";
// print_r(cartrack_get('/trips/all/elapsed'));
//
// echo "\n\n=== GET /trip/elapsed ===\n";
// print_r(cartrack_get('/trip/elapsed'));
