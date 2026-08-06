<?php
// TEMPORARY DEBUG SCRIPT — delete after diagnosing the override_attendance access issue.
// Loads the real DB rows + session state so we can see exactly where the mismatch is.

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php'; // defines base_url()
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/test_sqlsrv.php'; // establishes $conn / $pdo
auth_check();

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

echo "<pre style='font:13px monospace; background:#111; color:#0f0; padding:16px;'>";

echo "=== SESSION ===\n";
echo "UserID:   " . var_export($_SESSION['UserID'] ?? null, true) . "\n";
echo "UserType: " . var_export($_SESSION['UserType'] ?? null, true) . "\n";
echo "Superadmin roles: " . implode(', ', rbac_superadmin_roles()) . "\n";
echo "Is superadmin (bypasses RBAC entirely)? " . (in_array($_SESSION['UserType'] ?? '', rbac_superadmin_roles()) ? "YES" : "no") . "\n\n";

$userId   = (int)($_SESSION['UserID'] ?? 0);
$userType = $_SESSION['UserType'] ?? '';
$cacheKey = 'rbac_permissions_uid_' . $userId;

echo "=== SESSION-CACHED PERMISSION MAP (what rbac_gate() actually checks) ===\n";
echo "Cache key: {$cacheKey}\n";
if (isset($_SESSION[$cacheKey])) {
    echo "CACHED — this is stale unless you've logged out/in since granting access.\n";
    print_r($_SESSION[$cacheKey]);
    echo "override_attendance in cached map? " . (isset($_SESSION[$cacheKey]['override_attendance']) ? "YES ({$_SESSION[$cacheKey]['override_attendance']})" : "NO") . "\n";
} else {
    echo "Not cached yet this session — will load fresh from DB on next rbac_gate() call.\n";
}
echo "\n";

echo "=== LIVE DB: rbac_permissions (role-based) for role_name = '{$userType}' ===\n";
$stmt = $pdo->prepare("SELECT module_key, permission_level, can_access FROM rbac_permissions WHERE role_name = ?");
$stmt->execute([$userType]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "No rows at all for this role_name.\n";
} else {
    foreach ($rows as $r) {
        $flag = $r['module_key'] === 'override_attendance' ? '  <<<<<< THIS ONE' : '';
        echo "  module_key={$r['module_key']}, permission_level={$r['permission_level']}, can_access={$r['can_access']}{$flag}\n";
    }
    $has = array_filter($rows, fn($r) => $r['module_key'] === 'override_attendance');
    echo $has ? "\noverride_attendance row FOUND above.\n" : "\noverride_attendance row NOT FOUND for role '{$userType}'.\n";
}
echo "\n";

echo "=== LIVE DB: rbac_user_access (per-user override) for user_id = {$userId} ===\n";
$stmt = $pdo->prepare("SELECT module_key, permission_level, is_active FROM rbac_user_access WHERE user_id = ?");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "No rows at all for this user_id.\n";
} else {
    foreach ($rows as $r) {
        $flag = $r['module_key'] === 'override_attendance' ? '  <<<<<< THIS ONE' : '';
        echo "  module_key={$r['module_key']}, permission_level={$r['permission_level']}, is_active={$r['is_active']}{$flag}\n";
    }
    $has = array_filter($rows, fn($r) => $r['module_key'] === 'override_attendance');
    echo $has ? "\noverride_attendance row FOUND above.\n" : "\noverride_attendance row NOT FOUND for user_id {$userId}.\n";
}
echo "\n";

echo "=== LIVE DB: rbac_modules (registry only — does NOT grant access) ===\n";
$stmt = $pdo->prepare("SELECT module_key, module_name, category FROM rbac_modules WHERE module_key = ?");
$stmt->execute(['override_attendance']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row ? "Registered: " . print_r($row, true) : "NOT found in rbac_modules — module_key may be misspelled.\n";

echo "</pre>";