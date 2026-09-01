<?php
/**
 * auth_guard.php
 * Checked at the very top of every page (via session_guard.php for
 * inner Warehouse/Technical pages, and directly by the root
 * index.php). If nobody's logged in, redirects to login.php and
 * carries the originally-requested URL along so login can send them
 * back where they meant to go.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../RBAC/rbac_helper.php';

if (empty($_SESSION['UserID'])) {
    // No TWM session -> send to TWM's login, not Inventory's own.
    $redirectTo = $_SERVER['REQUEST_URI'] ?? '';
    $target = '/TWM/login.php' . ($redirectTo ? '?redirect=' . urlencode($redirectTo) : ''); // TODO: confirm TWM's actual login URL
    header('Location: ' . $target);
    exit;
}

// rbac_gate() needs a PDO connection -- Inventory's own config.php only
// gives us a raw sqlsrv resource, so open a lightweight PDO one here.
$rbacPdo = new PDO(
    "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_DATABASE, // TODO: confirm this matches TWM's actual DSN/credentials
    DB_USERNAME,
    DB_PASSWORD
);

rbac_gate($rbacPdo, 'inventory');

if (!function_exists('getUserInitials')) {
    function getUserInitials() {
        $name = trim($_SESSION['display_name'] ?? $_SESSION['username'] ?? '');
        if ($name === '') return '?';
        $words = preg_split('/\s+/', $name);
        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
        return $initials !== '' ? $initials : '?';
    }
}
