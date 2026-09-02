<?php
/**
 * Cartrack Fleet API — usage examples
 * Not meant to run as-is in production; reference for building TWM modules.
 */

require_once __DIR__ . '/cartrack_client.php';

// --- Vehicle status (live overview of the fleet) ---
$vehicleStatus = cartrack_get('/vehicles/status');

// --- Single vehicle details ---
// $vehicle = cartrack_get('/vehicles/123');

// --- Trips (filtered by vehicle + date range) ---
$trips = cartrack_get('/trips', [
    'filter[vehicle_id]'  => 123,
    'filter[date_from]'   => '2026-08-01',
    'filter[date_to]'     => '2026-08-31',
]);

// --- Fuel data ---
$fuel = cartrack_get('/fuel', [
    'filter[vehicle_id]' => 123,
]);

// --- Drivers list ---
$drivers = cartrack_get('/drivers');

// --- Delivery jobs (if using Cartrack's delivery module) ---
$deliveryJobs = cartrack_get('/delivery/jobs', [
    'filter[create_ts_from]' => '2026-08-01',
    'filter[create_ts_to]'   => '2026-08-31',
]);

// --- Error handling pattern ---
if (isset($vehicleStatus['error'])) {
    error_log('Cartrack API error [' . $vehicleStatus['code'] . ']: ' . $vehicleStatus['raw']);
    // handle gracefully — e.g. show cached data, or a "fleet data unavailable" notice in TWM
}
