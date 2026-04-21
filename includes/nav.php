<?php
// includes/nav.php
// ── Central Navigation Helper ─────────────────────────────────
// Include this file ONCE per page. Provides route() and redirect().

if (defined('NAV_LOADED')) return; // prevent double-include
define('NAV_LOADED', true);

// ── Base URL builder ──────────────────────────────────────────
function base_url(string $path = ''): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $base     = '/TWM';
    return $protocol . '://' . $host . $base . '/' . ltrim($path, '/');
}

// ── All routes in ONE place ───────────────────────────────────
define('ROUTES', [

    // ── Core / Root
    'home'              => 'home.php',
    'login'             => 'login.php',
    'logout'            => 'logout.php',
    'orgchart'          => 'orgchart.php',
    'help'              => 'help-manual.php',
    'set_department'    => 'set_department.php',

    // ── HR Module
    'careers'           => 'HR/careers.php',
    'careers_admin'     => 'HR/careers-admin.php',
    'careers_details'   => 'HR/careers-details.php',
    'job_application'   => 'HR/job-application.php',
    'view_applications' => 'HR/view-applications.php',
    'update_status'     => 'HR/update-status.php',
    'save_interview'    => 'HR/save-interview.php',
    'download_resume'   => 'HR/download-resume.php',
    'uniform_inventory' => 'HR/uniform-inventory.php',
    'uniform_po_items'  => 'HR/uniform-po-items.php',
    'employee_list'     => 'HR/employee-list.php',

    // ── Logistics Module
    'fuel_dashboard'    => 'LOGISTICS/fuel_dashboard.php',
    'graphs'            => 'LOGISTICS/graphs.php',

    // ── Logistics Module
    'po_index'            => 'PO/index.php',

    // ── Forms
    'awards'            => 'forms/awards.php',
    'awards_details'    => 'forms/awards-details.php',
    'contact'           => 'forms/contact.php',
    'newsletter'        => 'forms/newsletter.php',

]);

// ── Get a full URL by route name ──────────────────────────────
function route(string $name, array $params = []): string {
    if (!isset(ROUTES[$name])) {
        error_log("nav.php: Unknown route '{$name}'");
        return '#';
    }
    $url = base_url(ROUTES[$name]);
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

// ── Redirect by route name ────────────────────────────────────
function redirect(string $name, array $params = []): void {
    header('Location: ' . route($name, $params));
    exit();
}