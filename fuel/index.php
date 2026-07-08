<?php
/**
 * index.php
 * Main entry point — authenticates the session, sets shared variables,
 * then assembles the layout by including each view file separately.
 *
 * Page views are stored in: pages/views/
 *   layout_header.php    — <head>, navbar, sidebar, opening <main>
 *   page_dashboard.php   — Dashboard page content
 *   page_fuel_records.php — Fuel Records page content
 *   page_gas_card.php    — Truck Gas Card page content
 *   page_tank.php        — Fuel Tank page content
 *   layout_footer.php    — modals, all JavaScript, closing tags
 */

require_once __DIR__ . '/includes/twm_auth_bridge.php';
fuel_enforce_system_access(false); // HTML access-denied page if not approved

// Variables available to all view files
$displayName   = htmlspecialchars($_SESSION['display_name'] ?? 'User');
$userType      = htmlspecialchars($_SESSION['user_type']    ?? '');
$department    = htmlspecialchars($_SESSION['department']   ?? '');
$isSuperAdmin  = !empty($_SESSION['is_superadmin']);
$activePage    = 'dashboard'; // default active sidebar link

// ── Load this user's permissions & allowed departments ───
require_once 'includes/functions.php';
$userPerms    = getUserPermissions((int)$_SESSION['user_id']);
$allowedDepts = $isSuperAdmin ? [] : getUserAllowedDepts((int)$_SESSION['user_id']);
$_SESSION['allowed_depts'] = $allowedDepts;

// Store permissions in session so api.php can enforce them correctly
$_SESSION['perm_edit_fuel_price'] = !empty($userPerms['perm_edit_fuel_price']);

// Pre-fetch rejected count for sidebar badge (superadmin only)
$pendingAccessCount = 0;
if ($isSuperAdmin) {
    $saData = getSystemAccessUsers();
    foreach ($saData['users'] as $saUser) {
        if (!$saUser['isApproved'] && strtolower($saUser['user_type'] ?? '') !== 'superadmin') {
            $pendingAccessCount++;
        }
    }
}

// ── Assemble layout ──────────────────────────────────────
require_once 'pages/views/layout_header.php';

require_once 'pages/views/page_dashboard.php';
require_once 'pages/views/page_fuel_records.php';
require_once 'pages/views/page_gas_card.php';
require_once 'pages/views/page_tank.php';
require_once 'pages/views/page_administration.php';
require_once 'pages/views/page_department.php';
require_once 'pages/views/page_tank_access.php';
require_once 'pages/views/page_system_access.php';

require_once 'pages/views/layout_footer.php';
