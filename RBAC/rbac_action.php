<?php
// /RBAC/rbac_action.php
// Handles AJAX POST requests from the RBAC management UI

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/rbac_helper.php';

auth_check(); // login + session guard only

rbac_gate($pdo, 'RBAC'); // DB-driven — only roles with can_access=1 for 'RBAC' get in

// ── View-only users may not mutate any RBAC data ─────────────────────────────
// GET requests (e.g. get_users_by_type) are read-only and always allowed.
// All POST actions are writes, so we block them here for view-only users.
// CSRF guard: all POST requests must carry a valid token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rbac_csrf_verify();
    rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
    rbac_enforce_full_access('RBAC', isAjax: true);
}

header('Content-Type: application/json');

// ── GET: fetch users for a specific type ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_users_by_type') {
    $type = trim($_GET['type'] ?? '');
    if ($type === '') {
        echo json_encode(['ok' => false, 'msg' => 'Missing type.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, username, email, user_type, DisplayName,
                   Department, Job_tittle, Position_held, Active
            FROM   ViewUserLogIn
            WHERE  user_type = ?
              AND  Active = 1
            ORDER  BY DisplayName ASC
        ");
        $stmt->execute([$type]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'users' => $users]);
    } catch (PDOException $e) {
        error_log('[RBAC] get_users_by_type: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'A database error occurred.']);
    }
    exit;
}

$action    = $_POST['action'] ?? '';

// FIX #11 — use a clearly distinguishable fallback so audit logs aren't misleading
$grantedBy = $_SESSION['Username'] ?? '[unknown]';

// ── Resolve real client IP ────────────────────────────────────────────────────
// X-Forwarded-For is user-controllable, so we only trust it when the direct
// connection comes from a known private/loopback address (i.e. a reverse proxy).
// We take the FIRST (leftmost) IP in the chain — that's the original client.
function resolve_client_ip(): string {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $xff        = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

    // Only trust XFF when the request actually came from a local reverse proxy
    // 172.16.0.0/12 — parse the second octet reliably without sscanf
    $is172 = false;
    if (str_starts_with($remoteAddr, '172.')) {
        $parts     = explode('.', $remoteAddr);
        $secondOct = isset($parts[1]) ? (int)$parts[1] : -1;
        $is172     = ($secondOct >= 16 && $secondOct <= 31);
    }

    $isLocalProxy = (
        $remoteAddr === '127.0.0.1'             ||
        $remoteAddr === '::1'                    ||
        str_starts_with($remoteAddr, '10.')      ||
        str_starts_with($remoteAddr, '192.168.') ||
        $is172
    );

    if ($isLocalProxy && $xff !== '') {
        // Take the first IP in the chain and strip it clean
        $candidate = trim(explode(',', $xff)[0]);
        // Basic validation — accept only plausible IP strings
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return $remoteAddr;
}
$clientIp = resolve_client_ip();

// ── Actions that manage modules themselves — no role/module fields needed ──
$moduleOnlyActions = ['add_module', 'edit_module', 'delete_module'];

// ── Actions that need role but NOT a specific module ──────────────────────
$roleOnlyActions = ['grant_all', 'revoke_all', 'add_role', 'delete_role'];

// ── Actions that handle their own validation inside the switch ────────────
$selfValidatedActions = ['change_user_type', 'reorder_modules', 'manage_dept_access', 'assign_user_access', 'assign_user_roles'];

// ── Input validation helpers: verify values actually exist in the DB ──────────────
function rbac_role_exists(PDO $pdo, string $role): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM rbac_roles WHERE role_name = ?");
    $s->execute([$role]);
    if ((int)$s->fetchColumn() > 0) return true;
    // Also allow roles that exist only as user_type values in the users table
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type = ?");
    $s->execute([$role]);
    return (int)$s->fetchColumn() > 0;
}
function rbac_module_exists(PDO $pdo, string $key): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM rbac_modules WHERE module_key = ?");
    $s->execute([$key]);
    return (int)$s->fetchColumn() > 0;
}

if (in_array($action, $roleOnlyActions)) {
    // These actions operate on a whole role — only role is required
    $roleName  = trim($_POST['role_name'] ?? $_POST['role'] ?? '');
    $moduleKey = '';
    if (!$roleName) {
        echo json_encode(['ok' => false, 'msg' => 'Missing role.']);
        exit;
    }
    // add_role is allowed to reference a not-yet-existing role (it's creating it)
    if ($action !== 'add_role' && !rbac_role_exists($pdo, $roleName)) {
        echo json_encode(['ok' => false, 'msg' => 'Role does not exist.']);
        exit;
    }
} elseif (!in_array($action, $moduleOnlyActions) && !in_array($action, $selfValidatedActions)) {
    // Standard toggle/grant/revoke — both role and module are required
    $roleName  = trim($_POST['role']   ?? '');
    $moduleKey = trim($_POST['module'] ?? '');
    if (!$roleName || !$moduleKey) {
        echo json_encode(['ok' => false, 'msg' => 'Missing role or module.']);
        exit;
    }
    if (!rbac_role_exists($pdo, $roleName)) {
        echo json_encode(['ok' => false, 'msg' => 'Role does not exist.']);
        exit;
    }
    if (!rbac_module_exists($pdo, $moduleKey)) {
        echo json_encode(['ok' => false, 'msg' => 'Module does not exist.']);
        exit;
    }
}

// ── Allowlist validators for icon/color (prevents stored XSS) ────────────────

function rbac_validate_icon(string $icon): string {
    // Accept only Bootstrap Icon class names: bi-<word-chars-and-hyphens>
    return preg_match('/^bi-[a-z0-9\-]+$/i', $icon) ? $icon : 'bi-grid';
}

// FIX #1 — accept hex values (#rgb / #rrggbb) in addition to legacy named colors.
// The UI now sends hex strings from the color wheel; the old named-only list
// silently reset every color to 'blue'.
function rbac_validate_color(string $color): string {
    // Accept short (#abc) or full (#aabbcc) hex colors
    if (preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color)) {
        return strtolower($color);
    }
    // Keep accepting legacy named colors stored in older rows
    $legacy = ['blue', 'green', 'amber', 'red', 'purple', 'cyan', 'gray'];
    return in_array($color, $legacy, true) ? $color : '#60a5fa';
}

// FIX #5 — sanitize free-text category (picker was replaced with a textbox).
// Strip everything except lowercase letters, digits, underscores, and hyphens;
// fall back to 'general' on an empty result.
function rbac_sanitize_category(string $cat): string {
    $cat = strtolower(trim($cat));
    $cat = preg_replace('/[^a-z0-9_\-]/', '', $cat);
    $cat = substr($cat, 0, 50);
    return $cat !== '' ? $cat : 'general';
}

try {
    switch ($action) {

        // ── Grant permission ────────────────────────────────────────
        case 'grant':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 1, permission_level = 'full', granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ? AND module_key = ?
            ");
            $stmt->execute([$grantedBy, $roleName, $moduleKey]);

            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO rbac_permissions (role_name, module_key, can_access, permission_level, granted_by, granted_at)
                    VALUES (?, ?, 1, 'full', ?, GETDATE())
                ");
                $stmt->execute([$roleName, $moduleKey, $grantedBy]);
            }
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address, notes)
                VALUES ('grant', ?, ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $clientIp,
                "Full access granted to [{$moduleKey}] for role [{$roleName}]"]);
            echo json_encode(['ok' => true]);
            break;

        // ── Grant VIEW-ONLY permission ──────────────────────────────
        case 'grant_view':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 1, permission_level = 'view_only', granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ? AND module_key = ?
            ");
            $stmt->execute([$grantedBy, $roleName, $moduleKey]);

            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO rbac_permissions (role_name, module_key, can_access, permission_level, granted_by, granted_at)
                    VALUES (?, ?, 1, 'view_only', ?, GETDATE())
                ");
                $stmt->execute([$roleName, $moduleKey, $grantedBy]);
            }
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address, notes)
                VALUES ('grant_view', ?, ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $clientIp,
                "View-only access granted to [{$moduleKey}] for role [{$roleName}]"]);
            echo json_encode(['ok' => true]);
            break;

        // ── Revoke permission ───────────────────────────────────────
        case 'revoke':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 0, granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ? AND module_key = ?
            ");
            $stmt->execute([$grantedBy, $roleName, $moduleKey]);
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address, notes)
                VALUES ('revoke', ?, ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $clientIp,
                "Access revoked from [{$moduleKey}] for role [{$roleName}]"]);
            echo json_encode(['ok' => true]);
            break;

        // ── Toggle permission ───────────────────────────────────────
        // NOTE: this action is no longer triggered by the UI (the 3-state
        // select uses grant/grant_view/revoke directly). It is kept here for
        // backwards-compatibility with any external callers. If you are sure
        // nothing uses it, remove this case entirely.
        case 'toggle':
            $checkStmt = $pdo->prepare("
                SELECT can_access FROM rbac_permissions
                WHERE role_name = ? AND module_key = ?
            ");
            $checkStmt->execute([$roleName, $moduleKey]);
            $existing = $checkStmt->fetchColumn();

            if ($existing === false) {
                // No row yet — insert as granted with full access
                $stmt = $pdo->prepare("
                    INSERT INTO rbac_permissions (role_name, module_key, can_access, permission_level, granted_by, granted_at)
                    VALUES (?, ?, 1, 'full', ?, GETDATE())
                ");
                $stmt->execute([$roleName, $moduleKey, $grantedBy]);
            } else {
                // Row exists — toggle; if turning ON always reset to 'full'
                $stmt = $pdo->prepare("
                    UPDATE rbac_permissions
                    SET can_access       = CASE WHEN can_access = 1 THEN 0 ELSE 1 END,
                        permission_level = CASE WHEN can_access = 1 THEN permission_level ELSE 'full' END,
                        granted_by       = ?,
                        granted_at       = GETDATE()
                    WHERE role_name = ? AND module_key = ?
                ");
                $stmt->execute([$grantedBy, $roleName, $moduleKey]);
            }

            // Read back the resulting state so the note is accurate
            $afterStmt = $pdo->prepare("
                SELECT can_access, permission_level FROM rbac_permissions
                WHERE role_name = ? AND module_key = ?
            ");
            $afterStmt->execute([$roleName, $moduleKey]);
            $after = $afterStmt->fetch(PDO::FETCH_ASSOC);
            $toggleNote = $after && $after['can_access']
                ? "Toggled ON [{$moduleKey}] for role [{$roleName}] (level: " . ($after['permission_level'] ?? 'full') . ")"
                : "Toggled OFF [{$moduleKey}] for role [{$roleName}]";

            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address, notes)
                VALUES ('toggle', ?, ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $clientIp, $toggleNote]);
            echo json_encode(['ok' => true]);
            break;

        // ── Grant all modules to a role ─────────────────────────────
        // FIX #3 — wrap in a transaction so a mid-loop failure doesn't leave
        // the role in a partially-granted state.
        case 'grant_all':
            $allMods = $pdo->query("SELECT module_key FROM rbac_modules")->fetchAll(PDO::FETCH_COLUMN);

            // Prepare both statements once outside the loop (cheaper than per-iteration prepare)
            $upd = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 1, permission_level = 'full', granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ? AND module_key = ?
            ");
            $ins = $pdo->prepare("
                INSERT INTO rbac_permissions (role_name, module_key, can_access, permission_level, granted_by, granted_at)
                VALUES (?, ?, 1, 'full', ?, GETDATE())
            ");

            $pdo->beginTransaction();
            try {
                foreach ($allMods as $mk) {
                    $upd->execute([$grantedBy, $roleName, $mk]);
                    if ($upd->rowCount() === 0) {
                        $ins->execute([$roleName, $mk, $grantedBy]);
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e; // re-throw so the outer catch handles it cleanly
            }

            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, performed_by, ip_address, notes)
                VALUES ('grant_all', ?, ?, ?, ?)
            ")->execute([$roleName, $grantedBy, $clientIp, count($allMods) . ' modules granted']);
            echo json_encode(['ok' => true]);
            break;

        // ── Revoke all modules from a role ──────────────────────────
        case 'revoke_all':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 0, granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ?
            ");
            $stmt->execute([$grantedBy, $roleName]);
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, performed_by, ip_address, notes)
                VALUES ('revoke_all', ?, ?, ?, ?)
            ")->execute([$roleName, $grantedBy, $clientIp, 'All modules revoked']);
            echo json_encode(['ok' => true]);
            break;

        // ── Add new module ──────────────────────────────────────────
        case 'add_module':
            $key   = trim($_POST['module_key']  ?? '');
            $name  = trim($_POST['module_name'] ?? '');
            // FIX #5 — use sanitize helper instead of hard allowlist
            $cat   = rbac_sanitize_category($_POST['category'] ?? 'general');
            $icon  = rbac_validate_icon(trim($_POST['icon']   ?? 'bi-grid'));
            // FIX #1 — rbac_validate_color now accepts hex values
            $color = rbac_validate_color(trim($_POST['color'] ?? '#60a5fa'));
            $desc  = trim($_POST['description'] ?? '');

            if (!$key || !$name) {
                echo json_encode(['ok' => false, 'msg' => 'Key and name required.']);
                exit;
            }
            if (preg_match('/\s/', $key)) {
                echo json_encode(['ok' => false, 'msg' => 'Module key cannot contain spaces.']);
                exit;
            }

            // FIX #2 — explicitly check for duplicate and return an error instead
            // of silently succeeding with an IF NOT EXISTS that inserts nothing.
            $dup = $pdo->prepare("SELECT COUNT(*) FROM rbac_modules WHERE module_key = ?");
            $dup->execute([$key]);
            if ((int)$dup->fetchColumn() > 0) {
                echo json_encode(['ok' => false, 'msg' => 'Module key already exists. Choose a different key.']);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO rbac_modules (module_key, module_name, category, icon, color, description, sort_order)
                VALUES (?, ?, ?, ?, ?, ?,
                    (SELECT ISNULL(MAX(sort_order), 0) + 10 FROM rbac_modules))
            ");
            $stmt->execute([$key, $name, $cat, $icon, $color, $desc]);
            echo json_encode(['ok' => true]);
            break;

        // ── Edit existing module ────────────────────────────────────
        case 'edit_module':
            $key   = trim($_POST['module_key']  ?? '');
            $name  = trim($_POST['module_name'] ?? '');
            // FIX #5 — use sanitize helper instead of hard allowlist
            $cat   = rbac_sanitize_category($_POST['category'] ?? 'general');
            $icon  = rbac_validate_icon(trim($_POST['icon']   ?? 'bi-grid'));
            // FIX #1 — rbac_validate_color now accepts hex values
            $color = rbac_validate_color(trim($_POST['color'] ?? '#60a5fa'));
            $desc  = trim($_POST['description'] ?? '');

            if (!$key || !$name) {
                echo json_encode(['ok' => false, 'msg' => 'Key and name required.']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE rbac_modules
                SET module_name = ?, category = ?, icon = ?, color = ?, description = ?
                WHERE module_key = ?
            ");
            $stmt->execute([$name, $cat, $icon, $color, $desc, $key]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'Module not found.']);
                exit;
            }

            echo json_encode(['ok' => true, 'module' => [
                'module_key'  => $key,
                'module_name' => $name,
                'category'    => $cat,
                'icon'        => $icon,
                'color'       => $color,
                'description' => $desc,
            ]]);
            break;

        // ── Delete module ───────────────────────────────────────────
        case 'delete_module':
            $key = trim($_POST['module_key'] ?? $_POST['module'] ?? '');
            if (!$key) {
                echo json_encode(['ok' => false, 'msg' => 'Module key required.']);
                exit;
            }
            $pdo->prepare("DELETE FROM rbac_permissions  WHERE module_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM rbac_user_access  WHERE module_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM rbac_modules      WHERE module_key = ?")->execute([$key]);
            echo json_encode(['ok' => true]);
            break;

        // ── Add a new user type / role ──────────────────────────────
        case 'add_role':
            if (preg_match('/\s/', $roleName)) {
                echo json_encode(['ok' => false, 'msg' => 'Role name cannot contain spaces.']);
                exit;
            }
            // Explicit duplicate check — returns a clear error instead of silently no-oping
            $dup = $pdo->prepare("SELECT COUNT(*) FROM rbac_roles WHERE role_name = ?");
            $dup->execute([$roleName]);
            if ((int)$dup->fetchColumn() > 0) {
                echo json_encode(['ok' => false, 'msg' => 'User type already exists.']);
                exit;
            }
            $stmt = $pdo->prepare("
                INSERT INTO rbac_roles (role_name, created_by, created_at)
                VALUES (?, ?, GETDATE())
            ");
            $stmt->execute([$roleName, $grantedBy]);
            echo json_encode(['ok' => true, 'role_name' => $roleName]);
            break;

        // ── Delete a user type / role ───────────────────────────────
        // FIX #7 — also wipe rbac_user_access rows belonging to users of this
        // role so deleted roles don't leave orphaned individual-access grants.
        case 'delete_role':
            $pdo->prepare("DELETE FROM rbac_permissions WHERE role_name = ?")->execute([$roleName]);
            $pdo->prepare("DELETE FROM rbac_roles       WHERE role_name = ?")->execute([$roleName]);
            // Remove individual module access rows for users whose user_type matches
            // the deleted role (prevents orphaned grants from floating around)
            $pdo->prepare("
                DELETE FROM rbac_user_access
                WHERE user_id IN (
                    SELECT id FROM users WHERE user_type = ?
                )
            ")->execute([$roleName]);
            echo json_encode(['ok' => true]);
            break;

        // ── Reorder modules (drag-and-drop) ────────────────────────
        case 'reorder_modules':
            $orderJson = $_POST['order'] ?? '';
            $keys      = json_decode($orderJson, true);

            if (!is_array($keys) || empty($keys)) {
                echo json_encode(['ok' => false, 'msg' => 'Invalid order data.']);
                exit;
            }

            // FIX #4 — sanitize each key and skip blanks; prevents updating
            // garbage keys that don't exist in the DB
            $stmt = $pdo->prepare("
                UPDATE rbac_modules SET sort_order = ? WHERE module_key = ?
            ");
            foreach ($keys as $idx => $key) {
                $key = trim((string)$key);
                if ($key === '') continue;
                $stmt->execute([($idx + 1) * 10, $key]);
            }

            echo json_encode(['ok' => true]);
            break;

        // ── Change a user's user_type ───────────────────────────────
        case 'change_user_type':
            $userId  = (int)($_POST['user_id']  ?? 0);
            $newType = trim($_POST['user_type'] ?? '');

            if (!$userId || !$newType) {
                echo json_encode(['ok' => false, 'msg' => 'Missing user ID or user type.']);
                exit;
            }

            // Validate that the target role actually exists
            $chk = $pdo->prepare("
                SELECT COUNT(*)
                FROM rbac_roles
                WHERE role_name = ?
                UNION ALL
                SELECT COUNT(*)
                FROM users
                WHERE user_type = ?
            ");
            $chk->execute([$newType, $newType]);
            $counts = $chk->fetchAll(PDO::FETCH_COLUMN);
            if (array_sum($counts) === 0) {
                echo json_encode(['ok' => false, 'msg' => 'Role does not exist.']);
                exit;
            }

            // Capture old type AND username BEFORE the UPDATE so they're available for audit
            $preStmt = $pdo->prepare("SELECT username, user_type FROM users WHERE id = ?");
            $preStmt->execute([$userId]);
            $preRow         = $preStmt->fetch(PDO::FETCH_ASSOC);
            $targetUsername = $preRow['username']  ?? 'unknown';
            $oldType        = $preRow['user_type'] ?? 'unknown';

            $stmt = $pdo->prepare("UPDATE users SET user_type = ? WHERE id = ?");
            $stmt->execute([$newType, $userId]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'User not found or type unchanged.']);
                exit;
            }

            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, target_user, target_uid, performed_by, ip_address, notes)
                VALUES ('change_user_type', ?, ?, ?, ?, ?)
            ")->execute([
                $targetUsername,
                $userId,
                $grantedBy,
                $clientIp,
                "User type changed from [{$oldType}] to [{$newType}] for user [{$targetUsername}]"
            ]);

            echo json_encode(['ok' => true]);
            break;

        // ── Manage department access for a user ─────────────────────
        case 'manage_dept_access':
            $userId = (int)($_POST['user_id'] ?? 0);
            $depts  = json_decode($_POST['departments'] ?? '[]', true);

            if (!$userId) {
                echo json_encode(['ok' => false, 'msg' => 'Missing user ID.']);
                exit;
            }
            if (!is_array($depts)) {
                echo json_encode(['ok' => false, 'msg' => 'Invalid departments data.']);
                exit;
            }

            // FIX #12 — validate submitted department names against the actual
            // departments that exist in the DB; reject anything not on the list.
            $validDepts = $pdo->query("
                SELECT DISTINCT Department FROM ViewUserLogIn
                WHERE Department IS NOT NULL AND Department != ''
            ")->fetchAll(PDO::FETCH_COLUMN);
            $validDeptsSet = array_flip($validDepts); // O(1) lookup

            $safeDepts = [];
            foreach ($depts as $dept) {
                $dept = trim((string)$dept);
                if ($dept !== '' && isset($validDeptsSet[$dept])) {
                    $safeDepts[] = $dept;
                }
            }

            // Delete existing dept access rows for this user
            $pdo->prepare("
                DELETE FROM Tbl_UserAccessDepartment WHERE UserID = ?
            ")->execute([$userId]);

            if (!empty($safeDepts)) {
                $ins = $pdo->prepare("
                    INSERT INTO Tbl_UserAccessDepartment (UserID, Department)
                    VALUES (?, ?)
                ");
                foreach ($safeDepts as $dept) {
                    $ins->execute([$userId, $dept]);
                }
            }

            echo json_encode(['ok' => true, 'count' => count($safeDepts)]);
            break;

        // ── Assign RBAC roles to a user (replaces all existing) ─────
        case 'assign_user_roles':
            $userId = (int)($_POST['user_id'] ?? 0);
            $roles  = json_decode($_POST['roles'] ?? '[]', true);

            if (!$userId) {
                echo json_encode(['ok' => false, 'msg' => 'Missing user ID.']);
                exit;
            }
            if (!is_array($roles)) {
                echo json_encode(['ok' => false, 'msg' => 'Invalid roles data.']);
                exit;
            }

            // Get user_type from users table for the record
            $utStmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
            $utStmt->execute([$userId]);
            $userType = $utStmt->fetchColumn() ?: '';

            // Remove all existing active roles for this user
            $pdo->prepare("DELETE FROM rbac_user_roles WHERE user_id = ?")->execute([$userId]);

            // Insert new selections
            if (!empty($roles)) {
                $ins = $pdo->prepare("
                    INSERT INTO rbac_user_roles (user_id, user_type, role_name, assigned_by, assigned_at, is_active)
                    VALUES (?, ?, ?, ?, GETDATE(), 1)
                ");
                foreach ($roles as $role) {
                    $role = trim($role);
                    if ($role !== '') {
                        $ins->execute([$userId, $userType, $role, $grantedBy]);
                    }
                }
            }

            echo json_encode(['ok' => true, 'count' => count($roles)]);
            break;

        // ── Assign modules directly to a user ───────────────────────
        case 'assign_user_access':
            $userId  = (int)($_POST['user_id'] ?? 0);
            $modules = json_decode($_POST['modules'] ?? '{}', true);

            if (!$userId) {
                echo json_encode(['ok' => false, 'msg' => 'Missing user ID.']);
                exit;
            }
            if (!is_array($modules)) {
                echo json_encode(['ok' => false, 'msg' => 'Invalid modules data.']);
                exit;
            }

            // ── Resolve target username for the audit log ─────────────
            $targetUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $targetUser->execute([$userId]);
            $targetUsername = $targetUser->fetchColumn() ?: 'unknown';

            // ── Fetch BEFORE delete so the diff has the real old state ─
            // (must happen before DELETE — once the rows are gone $prevMap
            //  would always be empty, making everything look like "Added")
            $prevStmt = $pdo->prepare("
                SELECT module_key, permission_level
                FROM   rbac_user_access
                WHERE  user_id = ? AND is_active = 1
            ");
            $prevStmt->execute([$userId]);
            $prevMap = [];
            foreach ($prevStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $prevMap[$row['module_key']] = $row['permission_level'];
            }

            // Wipe existing access for this user
            $pdo->prepare("DELETE FROM rbac_user_access WHERE user_id = ?")->execute([$userId]);

            // Insert new selections — $modules is { module_key: 'full'|'view_only' }
            if (!empty($modules)) {
                $ins = $pdo->prepare("
                    INSERT INTO rbac_user_access (user_id, module_key, permission_level, granted_by, granted_at, is_active)
                    VALUES (?, ?, ?, ?, GETDATE(), 1)
                ");
                foreach ($modules as $mk => $level) {
                    $mk    = trim((string)$mk);
                    $level = in_array($level, ['full', 'view_only']) ? $level : 'full';
                    if ($mk !== '') {
                        $ins->execute([$userId, $mk, $level, $grantedBy]);
                    }
                }
            }

            // NOTE on FIX #6: $_SESSION belongs to the currently logged-in admin,
            // not the target user. We can clear our own cache but the target user's
            // session cache (rbac_permissions_uid_X) lives in their own PHP session
            // and will only refresh on their next page load. This is a PHP session
            // architecture limitation — nothing to fix here, but worth documenting.
            $cacheKey = 'rbac_permissions_uid_' . $userId;
            if (isset($_SESSION[$cacheKey])) unset($_SESSION[$cacheKey]);

            // ── Build diff for audit log ──────────────────────────────
            $added   = [];
            $removed = [];
            $changed = [];

            foreach ($modules as $mk => $lvl) {
                if (!isset($prevMap[$mk])) {
                    $added[] = "$mk ($lvl)";
                } elseif ($prevMap[$mk] !== $lvl) {
                    $changed[] = "$mk ({$prevMap[$mk]} → $lvl)";
                }
            }
            foreach ($prevMap as $mk => $lvl) {
                if (!isset($modules[$mk])) {
                    $removed[] = $mk;
                }
            }

            $parts = [];
            if (!empty($added))   $parts[] = 'Added: '   . implode(', ', $added);
            if (!empty($changed)) $parts[] = 'Changed: ' . implode(', ', $changed);
            if (!empty($removed)) $parts[] = 'Removed: ' . implode(', ', $removed);
            $summary = $parts ? implode(' | ', $parts) : 'No changes';

            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, target_user, target_uid, performed_by, ip_address, notes)
                VALUES ('assign_access', ?, ?, ?, ?, ?)
            ")->execute([
                $targetUsername,
                $userId,
                $grantedBy,
                $clientIp,
                $summary,
            ]);

            echo json_encode(['ok' => true, 'count' => count($modules)]);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    }

// FIX #10 — log full exception server-side but never expose DB internals
// (table names, column names, query structure) to the client.
} catch (PDOException $e) {
    error_log('[RBAC] PDOException in action=' . ($action ?? 'unknown') . ': ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'A database error occurred. Please try again or contact your administrator.']);
}