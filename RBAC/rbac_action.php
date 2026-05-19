<?php
// /RBAC/rbac_action.php
// Handles AJAX POST requests from the RBAC management UI

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
require_once __DIR__ . '/rbac_helper.php';

auth_check(); // login + session guard only

rbac_gate($pdo, 'RBAC'); // DB-driven — only roles with can_access=1 for 'RBAC' get in

header('Content-Type: application/json');

$action    = $_POST['action']    ?? '';
$grantedBy = $_SESSION['Username'] ?? 'admin';

// ── Actions that manage modules themselves — no role/module fields needed ──
$moduleOnlyActions = ['add_module', 'edit_module', 'delete_module'];

// ── Actions that need role but NOT a specific module ──────────────────────
$roleOnlyActions = ['grant_all', 'revoke_all', 'add_role', 'delete_role'];

// ── Actions that handle their own validation inside the switch ────────────
$selfValidatedActions = ['change_user_type', 'reorder_modules', 'manage_dept_access', 'assign_user_access'];

if (in_array($action, $roleOnlyActions)) {
    // These actions operate on a whole role — only role is required
    $roleName  = trim($_POST['role_name'] ?? $_POST['role'] ?? '');
    $moduleKey = '';
    if (!$roleName) {
        echo json_encode(['ok' => false, 'msg' => 'Missing role.']);
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
}

try {
    switch ($action) {

        // ── Grant permission ────────────────────────────────────────
        case 'grant':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions
                SET can_access = 1, granted_by = ?, granted_at = GETDATE()
                WHERE role_name = ? AND module_key = ?
            ");
            $stmt->execute([$grantedBy, $roleName, $moduleKey]);

            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by, granted_at)
                    VALUES (?, ?, 1, ?, GETDATE())
                ");
                $stmt->execute([$roleName, $moduleKey, $grantedBy]);
            }
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address)
                VALUES ('grant', ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $_SERVER['REMOTE_ADDR'] ?? null]);
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
                    (action_type, role_name, module_key, performed_by, ip_address)
                VALUES ('revoke', ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $_SERVER['REMOTE_ADDR'] ?? null]);
            echo json_encode(['ok' => true]);
            break;

        // ── Toggle permission ───────────────────────────────────────
        case 'toggle':
            $checkStmt = $pdo->prepare("
                SELECT can_access FROM rbac_permissions
                WHERE role_name = ? AND module_key = ?
            ");
            $checkStmt->execute([$roleName, $moduleKey]);
            $existing = $checkStmt->fetchColumn();

            if ($existing === false) {
                $stmt = $pdo->prepare("
                    INSERT INTO rbac_permissions (role_name, module_key, can_access, granted_by, granted_at)
                    VALUES (?, ?, 1, ?, GETDATE())
                ");
                $stmt->execute([$roleName, $moduleKey, $grantedBy]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE rbac_permissions
                    SET can_access = CASE WHEN can_access = 1 THEN 0 ELSE 1 END,
                        granted_by = ?, granted_at = GETDATE()
                    WHERE role_name = ? AND module_key = ?
                ");
                $stmt->execute([$grantedBy, $roleName, $moduleKey]);
            }
            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, role_name, module_key, performed_by, ip_address)
                VALUES ('toggle', ?, ?, ?, ?)
            ")->execute([$roleName, $moduleKey, $grantedBy, $_SERVER['REMOTE_ADDR'] ?? null]);
            echo json_encode(['ok' => true]);
            break;

        // ── Grant all modules to a role ─────────────────────────────
        case 'grant_all':
            $allMods = $pdo->query("SELECT module_key FROM rbac_modules")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($allMods as $mk) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM rbac_permissions WHERE role_name = ? AND module_key = ?");
                $chk->execute([$roleName, $mk]);
                if ($chk->fetchColumn() > 0) {
                    $s = $pdo->prepare("UPDATE rbac_permissions SET can_access=1, granted_by=?, granted_at=GETDATE() WHERE role_name=? AND module_key=?");
                    $s->execute([$grantedBy, $roleName, $mk]);
                } else {
                    $s = $pdo->prepare("INSERT INTO rbac_permissions (role_name,module_key,can_access,granted_by,granted_at) VALUES(?,?,1,?,GETDATE())");
                    $s->execute([$roleName, $mk, $grantedBy]);
                }
            }
            echo json_encode(['ok' => true]);
            break;

        // ── Revoke all modules from a role ──────────────────────────
        case 'revoke_all':
            $stmt = $pdo->prepare("
                UPDATE rbac_permissions SET can_access=0, granted_by=?, granted_at=GETDATE()
                WHERE role_name=?
            ");
            $stmt->execute([$grantedBy, $roleName]);
            echo json_encode(['ok' => true]);
            break;

        // ── Add new module ──────────────────────────────────────────
        case 'add_module':
            $key   = trim($_POST['module_key']   ?? '');
            $name  = trim($_POST['module_name']  ?? '');
            $cat   = trim($_POST['category']     ?? 'general');
            $icon  = trim($_POST['icon']         ?? 'bi-grid');
            $color = trim($_POST['color']        ?? 'blue');
            $desc  = trim($_POST['description']  ?? '');

            if (!$key || !$name) {
                echo json_encode(['ok' => false, 'msg' => 'Key and name required.']);
                exit;
            }

            $validCats = ['hr', 'fleet', 'finance', 'general'];
            if (!in_array($cat, $validCats)) $cat = 'general';

            $stmt = $pdo->prepare("
                IF NOT EXISTS (SELECT 1 FROM rbac_modules WHERE module_key = ?)
                BEGIN
                    INSERT INTO rbac_modules (module_key, module_name, category, icon, color, description, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?,
                        (SELECT ISNULL(MAX(sort_order),0)+10 FROM rbac_modules))
                END
            ");
            $stmt->execute([$key, $key, $name, $cat, $icon, $color, $desc]);
            echo json_encode(['ok' => true]);
            break;

        // ── Edit existing module ────────────────────────────────────
        case 'edit_module':
            $key   = trim($_POST['module_key']   ?? '');
            $name  = trim($_POST['module_name']  ?? '');
            $cat   = trim($_POST['category']     ?? 'general');
            $icon  = trim($_POST['icon']         ?? 'bi-grid');
            $color = trim($_POST['color']        ?? 'blue');
            $desc  = trim($_POST['description']  ?? '');

            if (!$key || !$name) {
                echo json_encode(['ok' => false, 'msg' => 'Key and name required.']);
                exit;
            }

            $validCats = ['hr', 'fleet', 'finance', 'general'];
            if (!in_array($cat, $validCats)) $cat = 'general';

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
            $pdo->prepare("DELETE FROM rbac_permissions WHERE module_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM rbac_modules    WHERE module_key = ?")->execute([$key]);
            echo json_encode(['ok' => true]);
            break;

        // ── Add a new user type / role ──────────────────────────────
        case 'add_role':
            if (preg_match('/\s/', $roleName)) {
                echo json_encode(['ok' => false, 'msg' => 'Role name cannot contain spaces.']);
                exit;
            }
            $stmt = $pdo->prepare("
                IF NOT EXISTS (SELECT 1 FROM rbac_roles WHERE role_name = ?)
                BEGIN
                    INSERT INTO rbac_roles (role_name, created_by, created_at)
                    VALUES (?, ?, GETDATE())
                END
            ");
            $stmt->execute([$roleName, $roleName, $grantedBy]);
            echo json_encode(['ok' => true, 'role_name' => $roleName]);
            break;

        // ── Delete a user type / role ───────────────────────────────
        case 'delete_role':
            $pdo->prepare("DELETE FROM rbac_permissions WHERE role_name = ?")->execute([$roleName]);
            $pdo->prepare("DELETE FROM rbac_roles WHERE role_name = ?")->execute([$roleName]);
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

            // Update sort_order for each key in the submitted sequence
            $stmt = $pdo->prepare("
                UPDATE rbac_modules SET sort_order = ? WHERE module_key = ?
            ");
            foreach ($keys as $idx => $key) {
                $stmt->execute([($idx + 1) * 10, trim($key)]);
            }

            echo json_encode(['ok' => true]);
            break;

        // ── Change a user's user_type ───────────────────────────
        case 'change_user_type':
            $userId  = (int)($_POST['user_id']   ?? 0);
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

            $stmt = $pdo->prepare("UPDATE users SET user_type = ? WHERE id = ?");
            $stmt->execute([$newType, $userId]);

            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'User not found or type unchanged.']);
                exit;
            }

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

            // Delete existing dept access rows for this user
            $pdo->prepare("
                DELETE FROM Tbl_UserAccessDepartment WHERE UserID = ?
            ")->execute([$userId]);

            // Insert new selections
            if (!empty($depts)) {
                $ins = $pdo->prepare("
                    INSERT INTO Tbl_UserAccessDepartment (UserID, Department)
                    VALUES (?, ?)
                ");
                foreach ($depts as $dept) {
                    $dept = trim($dept);
                    if ($dept !== '') {
                        $ins->execute([$userId, $dept]);
                    }
                }
            }

            echo json_encode(['ok' => true, 'count' => count($depts)]);
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
            $modules = json_decode($_POST['modules'] ?? '[]', true);

            if (!$userId) {
                echo json_encode(['ok' => false, 'msg' => 'Missing user ID.']);
                exit;
            }
            if (!is_array($modules)) {
                echo json_encode(['ok' => false, 'msg' => 'Invalid modules data.']);
                exit;
            }

            // Wipe existing access for this user
            $pdo->prepare("DELETE FROM rbac_user_access WHERE user_id = ?")->execute([$userId]);

            // Insert new selections
            if (!empty($modules)) {
                $ins = $pdo->prepare("
                    INSERT INTO rbac_user_access (user_id, module_key, granted_by, granted_at, is_active)
                    VALUES (?, ?, ?, GETDATE(), 1)
                ");
                foreach ($modules as $mk) {
                    $mk = trim($mk);
                    if ($mk !== '') {
                        $ins->execute([$userId, $mk, $grantedBy]);
                    }
                }
            }

            // Clear session cache so gate re-checks on next request
            $cacheKey = 'rbac_permissions_uid_' . $userId;
            if (isset($_SESSION[$cacheKey])) unset($_SESSION[$cacheKey]);

            // ── Audit log ─────────────────────────────────────────────
            $targetUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $targetUser->execute([$userId]);
            $targetUsername = $targetUser->fetchColumn() ?: 'unknown';

            $pdo->prepare("
                INSERT INTO rbac_audit_log
                    (action_type, target_user, target_uid, performed_by, ip_address, notes)
                VALUES ('assign_access', ?, ?, ?, ?, ?)
            ")->execute([
                $targetUsername,
                $userId,
                $grantedBy,
                $_SERVER['REMOTE_ADDR'] ?? null,
                count($modules) . ' module(s) assigned: ' . implode(', ', $modules)
            ]);

            echo json_encode(['ok' => true, 'count' => count($modules)]);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}