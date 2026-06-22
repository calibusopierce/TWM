<?php
// /RBAC/rbac_helper.php

// ── CSRF helpers ─────────────────────────────────────────────────────────────

/**
 * Return (and lazily create) the session CSRF token for this user's session.
 */
function rbac_csrf_token(): string {
    if (empty($_SESSION['rbac_csrf_token'])) {
        $_SESSION['rbac_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['rbac_csrf_token'];
}

/**
 * Verify the CSRF token sent by the client.
 * Checks X-CSRF-Token header (preferred for AJAX) then falls back to POST field.
 * Exits with a 403 JSON response on failure.
 */
function rbac_csrf_verify(): void {
    $expected = $_SESSION['rbac_csrf_token'] ?? '';
    $received = $_SERVER['HTTP_X_CSRF_TOKEN']
             ?? $_POST['csrf_token']
             ?? '';

    if ($expected === '' || !hash_equals($expected, $received)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Invalid or missing CSRF token.']);
        exit;
    }
}

/**
 * ── Module URL overrides ─────────────────────────────────────────────────────
 * Maps module_key => explicit path for any module that doesn't follow
 * the standard route() convention.
 *
 * HOW TO USE:
 *   1. Check what module_key you saved for your RBAC card in rbac_modules.
 *   2. Make sure that key appears here pointing to /TWM/RBAC/index.php.
 *   3. Add any other modules whose keys don't map correctly via route().
 */
function rbac_module_urls(): array {
    return [
        // ── RBAC / Access Control ───────────────────────────────────────────
        'RBAC' => '/TWM/RBAC/index.php',

        // ── Add more overrides here as needed ───────────────────────────────
        // 'payroll'        => '/TWM/Finance/payroll/index.php',
        // 'fleet_tracking' => '/TWM/Fleet/tracking/index.php',
    ];
}

/**
 * Resolve the URL for a module key.
 * Checks override map first, then falls back to route().
 */
function rbac_module_url(string $moduleKey): string {
    $overrides = rbac_module_urls();
    return $overrides[$moduleKey] ?? route($moduleKey);
}

/**
 * Load the current user's accessible module keys from DB
 * and cache in session for the request lifetime.
 *
 * FIX #9 — PERMISSION MERGE BEHAVIOUR (was: fallback, now: merge)
 * ────────────────────────────────────────────────────────────────
 * Previously, rbac_user_access was treated as a full override: if even one
 * individual-access row existed, the role-based (rbac_permissions) grants were
 * completely ignored. This silently dropped all role permissions for any user
 * who had a single manually-assigned module, which was almost certainly not
 * the intended behaviour.
 *
 * New behaviour:
 *   1. Load role-based permissions (rbac_permissions WHERE role_name = user_type)
 *   2. Load individual-access permissions (rbac_user_access WHERE user_id = ?)
 *   3. Merge: individual-access wins on any key that appears in both
 *      (individual 'view_only' can downgrade a role 'full', and vice-versa)
 *
 * If you deliberately want individual-access to fully replace role access
 * (i.e. the old behaviour), revert to the if(empty($map)) guard below.
 */
function rbac_load_permissions(PDO $pdo, string $userType): array {
    $userId   = (int)($_SESSION['UserID'] ?? 0);
    $cacheKey = 'rbac_permissions_uid_' . $userId;
    if (isset($_SESSION[$cacheKey])) return $_SESSION[$cacheKey];

    $map = [];

    // ── Step 1: role-based permissions (legacy / group-level) ────────────────
    if ($userType !== '') {
        $stmt = $pdo->prepare("
            SELECT module_key, permission_level
            FROM   rbac_permissions
            WHERE  role_name  = ?
              AND  can_access = 1
        ");
        $stmt->execute([$userType]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['module_key']] = $row['permission_level'] ?? 'full';
        }
    }

    // ── Step 2: individual-access overrides (user-level) ─────────────────────
    // Individual rows are merged on top of role rows.
    // Per-user grants win for any key that appears in both sets,
    // allowing both upgrades (view_only → full) and downgrades (full → view_only).
    if ($userId > 0) {
        $stmt = $pdo->prepare("
            SELECT module_key, permission_level
            FROM   rbac_user_access
            WHERE  user_id   = ?
              AND  is_active = 1
        ");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['module_key']] = $row['permission_level'] ?? 'full';
        }
    }

    $_SESSION[$cacheKey] = $map;
    return $map;
}

/**
 * Check if the current user can access a given module key.
 * IMPORTANT: rbac_load_permissions() must have been called first this request,
 * otherwise this will silently return false (no access) for all modules.
 */
function rbac_can(string $moduleKey): bool {
    $userId   = (int)($_SESSION['UserID'] ?? 0);
    $cacheKey = 'rbac_permissions_uid_' . $userId;
    return isset(($_SESSION[$cacheKey] ?? [])[$moduleKey]);
}

/**
 * Check if the current user has VIEW-ONLY access to a module.
 * Returns true only when explicitly set to view_only.
 * Superadmins always return false (they always have full access).
 */
function rbac_is_view_only(string $moduleKey): bool {
    $userType = $_SESSION['UserType'] ?? '';
    if (in_array($userType, rbac_superadmin_roles())) return false;

    $userId   = (int)($_SESSION['UserID'] ?? 0);
    $cacheKey = 'rbac_permissions_uid_' . $userId;
    $map      = $_SESSION[$cacheKey] ?? [];
    return ($map[$moduleKey] ?? 'full') === 'view_only';
}

/**
 * Load all modules grouped by category for the homepage card loop.
 * Each card now includes a resolved 'url' key ready to use as href.
 */
function rbac_get_sections(PDO $pdo, array $permissions): array {
    $stmt = $pdo->query("
        SELECT module_key, module_name, category, icon, color, description
        FROM   rbac_modules
        ORDER  BY sort_order ASC
    ");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryMeta = [
        'hr'      => ['label' => 'Human Resources',       'icon' => 'bi-people-fill',    'css' => 'cat-hr'],
        'fleet'   => ['label' => 'Fleet &amp; Logistics',  'icon' => 'bi-truck',          'css' => 'cat-fleet'],
        'finance' => ['label' => 'Finance',                'icon' => 'bi-receipt-cutoff', 'css' => 'cat-finance'],
        'customers' => ['label' => 'Customers',             'icon' => 'bi-geo-alt-fill',   'css' => 'cat-customers'],
        'general'   => ['label' => 'General',               'icon' => 'bi-grid-fill',      'css' => 'cat-general'],
    ];

    $sections = [];
    foreach ($all as $mod) {
        if (!isset($permissions[$mod['module_key']])) continue;
        $cat = $mod['category'];
        if (!isset($sections[$cat])) {
            $sections[$cat] = array_merge(
                // Fall back gracefully for custom categories added via the free-text field
                $categoryMeta[$cat] ?? ['label' => ucfirst($cat), 'icon' => 'bi-grid', 'css' => 'cat-general'],
                ['cards' => []]
            );
        }
        $mod['url'] = rbac_module_url($mod['module_key']);
        $sections[$cat]['cards'][] = $mod;
    }

    // Return known categories in fixed order first, then any custom ones alphabetically
    $orderedSections = [];
    foreach (['hr', 'fleet', 'finance', 'customers', 'general'] as $cat) {
        if (isset($sections[$cat])) {
            $orderedSections[$cat] = $sections[$cat];
        }
    }
    // Append any custom categories (from free-text input) that aren't in the fixed list
    foreach ($sections as $cat => $data) {
        if (!isset($orderedSections[$cat])) {
            $orderedSections[$cat] = $data;
        }
    }

    return $orderedSections;
}

/**
 * Roles that always pass rbac_gate() for ANY module — no DB record needed.
 * Edit this list to match the exact UserType values stored in your session.
 */
function rbac_superadmin_roles(): array {
    return ['Administrator'];
}

/**
 * Return the resolved permission level ('full', 'view_only', or null = no access)
 * for the current user on a given module.
 * Requires rbac_load_permissions() to have been called first this request.
 */
function rbac_permission_level(string $moduleKey): ?string {
    $userId   = (int)($_SESSION['UserID'] ?? 0);
    $cacheKey = 'rbac_permissions_uid_' . $userId;
    $map      = $_SESSION[$cacheKey] ?? [];
    return $map[$moduleKey] ?? null;
}

/**
 * Enforce that the current user has FULL (not view-only) access to $moduleKey.
 * Call this at the top of any AJAX / form-POST handler inside a module.
 *
 * Usage (in a module's action handler):
 *   rbac_enforce_full_access('payroll');
 *
 * When the user is view-only it sends a JSON 403 (for AJAX) or an HTML 403
 * (for regular requests) and exits. Superadmins always pass.
 */
function rbac_enforce_full_access(string $moduleKey, bool $isAjax = false): void {
    $userType = $_SESSION['UserType'] ?? '';
    if (in_array($userType, rbac_superadmin_roles())) return;

    if (rbac_is_view_only($moduleKey)) {
        http_response_code(403);
        if ($isAjax || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'msg' => 'You have view-only access to this module.']);
        } else {
            $backUrlJs = json_encode($_SERVER['HTTP_REFERER'] ?? '/TWM/');
            echo "<!DOCTYPE html><html><head>
            <meta charset='UTF-8'>
            <script src='" . base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js') . "'></script>
            </head><body style='margin:0;background:#0f172a;'>
            <script>
            Swal.fire({
                icon: 'warning',
                title: 'View-Only Access',
                text: 'Your account can view this module but cannot make changes.',
                confirmButtonText: 'Go Back',
                confirmButtonColor: '#1e40af',
                background: '#1e293b',
                color: '#f1f5f9',
                iconColor: '#fbbf24',
                allowOutsideClick: false,
                allowEscapeKey: false,
            }).then(() => { window.location.href = {$backUrlJs}; });
            </script></body></html>";
        }
        exit;
    }
}

/**
 * Gate a page to a specific module key.
 * Call this on every protected page after auth_check().
 * Exits with a 403 SweetAlert if the role doesn't have access.
 *
 * @param string $requiredLevel  'any' (default) = view_only or full both pass.
 *                               'full'          = only full access passes; view-only gets 403.
 */
function rbac_gate(PDO $pdo, string $moduleKey, string $requiredLevel = 'any'): void {
    $userType = $_SESSION['UserType'] ?? '';

    // ── Superadmin bypass ────────────────────────────────────────────────────
    // Roles listed in rbac_superadmin_roles() always get through, regardless
    // of what's in the DB. This prevents the chicken-and-egg lockout where
    // no one can access RBAC to grant RBAC access in the first place.
    if (in_array($userType, rbac_superadmin_roles())) return;
    // ────────────────────────────────────────────────────────────────────────

    rbac_load_permissions($pdo, $userType);

    // ── No access at all ─────────────────────────────────────────────────────
    if (!rbac_can($moduleKey)) {
        http_response_code(403);
        $backUrlJs = json_encode($_SERVER['HTTP_REFERER'] ?? '/TWM/');
        echo "<!DOCTYPE html><html><head>
        <meta charset='UTF-8'>
        <script src='" . base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js') . "'></script>
        </head><body style='margin:0;background:#0f172a;'>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'Your account does not have permission to view this page.',
            confirmButtonText: 'Go Back',
            confirmButtonColor: '#1e40af',
            background: '#1e293b',
            color: '#f1f5f9',
            iconColor: '#ef4444',
            allowOutsideClick: false,
            allowEscapeKey: false,
        }).then(() => { window.location.href = {$backUrlJs}; });
        </script></body></html>";
        exit;
    }

    // ── Enforce full-access requirement (e.g. action handlers) ───────────────
    if ($requiredLevel === 'full' && rbac_is_view_only($moduleKey)) {
        rbac_enforce_full_access($moduleKey);
        // rbac_enforce_full_access() always exits, but be explicit:
        exit;
    }
}