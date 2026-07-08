<?php
/**
 * twm_auth_bridge.php
 * ─────────────────────────────────────────────────────────────────
 * Bridges the fuel module onto the main TWM login/session instead of
 * its own standalone one. Include this at the very top of any fuel
 * entry point (before any output), in place of the old
 * `session_start(); if (empty($_SESSION['user_id'])) { ... }` check.
 *
 * What it does:
 *   1. Reuses TWM's own session + login (nav.php / auth_check.php).
 *      No new session or auth logic is created — if the person isn't
 *      logged into TWM, auth_check() sends them to the real TWM login,
 *      exactly like every other module.
 *   2. Maps the TWM session keys onto the lowercase keys the fuel
 *      module's existing code was written against (user_id, username,
 *      user_type, department, display_name, is_superadmin), so nothing
 *      else in fuel/ has to change.
 *   3. Re-applies the fuel module's OWN access-approval gate
 *      (Tbl_UserSystemAccess), which previously only ran once inside
 *      fuel/login.php at login time. Since login no longer happens
 *      inside fuel/, this check now runs on every entry so a user who
 *      was rejected from the fuel module stays rejected even though
 *      they're logged into TWM.
 *
 * NOTE: this does NOT touch TWM's RBAC ('fuel' module key / rbac_gate
 * call in index.php) — that was left as-is per explicit instruction.
 */

// ── 1. Reuse TWM's session + login/auth (do not create a new one) ──
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check(); // redirects to the real TWM login if not logged in

// ── 2. Map TWM session keys -> fuel module's expected session keys ─
$_SESSION['user_id']       = $_SESSION['UserID'];
$_SESSION['username']      = $_SESSION['Username'];
$_SESSION['display_name']  = $_SESSION['DisplayName'];
$_SESSION['user_type']     = $_SESSION['UserType'];
$_SESSION['department']    = $_SESSION['Department'];
// Same rule fuel's own login.php used — unrelated to TWM RBAC's
// separate 'Administrator' superadmin concept, left exactly as-is.
$_SESSION['is_superadmin'] = (strtolower($_SESSION['UserType'] ?? '') === 'superadmin');

/**
 * Enforce the fuel module's own system-access approval gate.
 * Call once, right after including this file.
 *
 * @param bool $jsonOnDeny  true = respond with a JSON 403 (AJAX/api.php),
 *                          false = show an HTML access-denied page (index.php)
 */
function fuel_enforce_system_access(bool $jsonOnDeny = false): void {
    if (!empty($_SESSION['is_superadmin'])) return; // superadmins always pass, same as before

    require_once __DIR__ . '/functions.php';

    if (getUserSystemAccess((int)$_SESSION['user_id'])) return; // approved (or no row = default allow)

    if ($jsonOnDeny) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => true,
            'message' => 'Your access to the Fuel Monitoring module has been rejected. Contact a superadmin.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(403);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Access Denied</title></head>
    <body style='font-family:Arial,sans-serif;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;'>
    <div style='text-align:center;max-width:420px;'>
        <h2 style='color:#f0a500;'>Access Denied</h2>
        <p>Your account does not have approved access to the Fuel Monitoring module. Contact a superadmin if you believe this is a mistake.</p>
        <p><a href='" . htmlspecialchars(route('home')) . "' style='color:#58a6ff;'>Back to TWM Home</a></p>
    </div></body></html>";
    exit;
}