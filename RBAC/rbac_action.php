<?php
// /RBAC/rbac_action.php
// Handles AJAX POST requests from the RBAC management UI

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check(['Admin', 'Administrator']);

header('Content-Type: application/json');

$action    = $_POST['action']    ?? '';
$grantedBy = $_SESSION['Username'] ?? 'admin';

// ── These actions use their own POST fields, not the global role/module ──
$moduleOnlyActions = ['add_module', 'edit_module', 'delete_module', 'grant_all', 'revoke_all'];

if (!in_array($action, $moduleOnlyActions)) {
    $roleName  = trim($_POST['role']   ?? '');
    $moduleKey = trim($_POST['module'] ?? '');
    if (!$roleName || !$moduleKey) {
        echo json_encode(['ok' => false, 'msg' => 'Missing role or module.']);
        exit;
    }
}

// RBAC uses PDO
try {
    $pdo = new PDO(
        "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
        null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
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
            echo json_encode(['ok' => true]);
            break;

        // ── Grant all modules to a role ─────────────────────────────
        case 'grant_all':
            // Get all module keys
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

            // Validate category
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
            // Cascade: remove permissions too
            $pdo->prepare("DELETE FROM rbac_permissions WHERE module_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM rbac_modules    WHERE module_key = ?")->execute([$key]);
            echo json_encode(['ok' => true]);
            break;

        default:
            echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    }

} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}