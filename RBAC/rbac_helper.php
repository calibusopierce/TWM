<?php
// /RBAC/rbac_helper.php

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
        'RBAC'            => '/TWM/RBAC/index.php',

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
 */
function rbac_load_permissions(PDO $pdo, string $userType): array {
    $cacheKey = 'rbac_permissions_' . $userType;
    if (isset($_SESSION[$cacheKey])) return $_SESSION[$cacheKey];

    $stmt = $pdo->prepare("
        SELECT module_key
        FROM   rbac_permissions
        WHERE  role_name  = ?
          AND  can_access = 1
    ");
    $stmt->execute([$userType]);
    $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION[$cacheKey] = $keys;
    return $keys;
}

/**
 * Check if the current user can access a given module key.
 * Requires rbac_load_permissions() to have been called first this request.
 */
function rbac_can(string $moduleKey): bool {
    $userType = $_SESSION['UserType'] ?? '';
    $cacheKey = 'rbac_permissions_' . $userType;
    return in_array($moduleKey, $_SESSION[$cacheKey] ?? []);
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
        'hr'      => ['label' => 'Human Resources',      'icon' => 'bi-people-fill',    'css' => 'cat-hr'],
        'fleet'   => ['label' => 'Fleet &amp; Logistics', 'icon' => 'bi-truck',          'css' => 'cat-fleet'],
        'finance' => ['label' => 'Finance',               'icon' => 'bi-receipt-cutoff', 'css' => 'cat-finance'],
        'general' => ['label' => 'General',               'icon' => 'bi-grid-fill',      'css' => 'cat-general'],
    ];

    $sections = [];
    foreach ($all as $mod) {
        if (!in_array($mod['module_key'], $permissions)) continue;
        $cat = $mod['category'];
        if (!isset($sections[$cat])) {
            $sections[$cat] = array_merge(
                $categoryMeta[$cat] ?? ['label' => $cat, 'icon' => 'bi-grid', 'css' => 'cat-general'],
                ['cards' => []]
            );
        }
        $mod['url'] = rbac_module_url($mod['module_key']);
        $sections[$cat]['cards'][] = $mod;
    }

    return $sections;
}

/**
 * Gate a page to a specific module key.
 * Call this on every protected page after auth_check().
 * Exits with a 403 SweetAlert if the role doesn't have access.
 */
function rbac_gate(PDO $pdo, string $moduleKey): void {
    $userType = $_SESSION['UserType'] ?? '';
    rbac_load_permissions($pdo, $userType);
    if (rbac_can($moduleKey)) return;

    http_response_code(403);
    $backUrl = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '/TWM/');

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
    }).then(() => { window.location.href = '{$backUrl}'; });
    </script></body></html>";
    exit;
}