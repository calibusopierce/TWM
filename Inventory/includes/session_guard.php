<?php
/**
 * session_guard.php
 * Every inner page runs through this before doing anything else —
 * including before hitting the database.
 *
 * The inventory type (Warehouse / Technical) is now determined by
 * which subfolder the running script lives in, since each inventory
 * has its own set of PHP files (warehouse/ vs technical/). This also
 * means deep links like /technical/stocks.php work directly, without
 * having to pass back through landing.php first.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/auth_guard.php';

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);

if (strpos($scriptPath, '/technical/') !== false) {
    $inventoryType = 'technical';
} else {
    $inventoryType = 'warehouse';
}

$_SESSION['inventory_type'] = $inventoryType;
$inventoryLabel = $inventoryType === 'technical' ? 'Technical' : 'Warehouse';
