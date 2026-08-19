<?php
// includes/nav.php
// ── Central Navigation Helper ─────────────────────────────────
// Include this file ONCE per page. Provides route() and redirect().

if (defined('NAV_LOADED')) return; // prevent double-include
define('NAV_LOADED', true);

// ── DB Connection (available globally on every page) ──────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';

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
    'home'                 => 'home.php',
    'login'                => 'login.php',
    'logout'               => 'logout.php',
    'orgchart'             => 'orgchart.php',
    'help'                 => 'help-manual.php',
    'set_department'       => 'set_department.php',

    // ── HR Module
    'payroll_dashboard'    => 'HR/payroll_dashboard.php',
    'careers'              => 'HR/careers.php',
    'careers_admin'        => 'HR/careers-admin.php',
    'careers_details'      => 'HR/careers-details.php',
    'job_application'      => 'HR/job-application.php',
    'view_applications'    => 'HR/view-applications.php',
    'update_status'        => 'HR/update-status.php',
    'save_interview'       => 'HR/save-interview.php',
    'download_resume'      => 'HR/download-resume.php',
    'uniform_inventory'    => 'HR/uniform-inventory.php',
    'uniform_po_items'     => 'HR/uniform-po-items.php',
    'employee_list'        => 'HR/employee-list.php',
    'attendance'           => 'HR/attendance.php',
    'my_attendance'        => 'HR/my_attendance.php',
    'employee_loans'       => 'EMPLOYEE/index.php',
    'my_loans'             => 'EMPLOYEE/my_loans.php',
    'cash_advance'         => 'VALE/create.php',
    'cash_advance_record'  => 'VALE/cash-advance-record.php',
    'leave_application'    => 'LEAVE/leave-application.php',
    'leave_management'     => 'LEAVE/leave-info-management.php',
    'leave_approval'       => 'LEAVE/leave-application-list.php',
    'override_attendance'  => 'HR/override-attendance.php',
    'override_attendance_approval' => 'HR/override-approval.php',
    'schedule_calendar'    => 'HR/schedule_calendar.php',
    'payroll_cutoff'       => 'HR/payroll_cutoff.php',
    'attendance_present'   => 'HR/attendance_present.php',
    'weekly_payroll'      => 'HR/weekly_payroll.php',

    // ── Logistics Module
    'fuel_dashboard'       => 'LOGISTICS/fuel_dashboard.php',
    'graphs'               => 'LOGISTICS/graphs.php',
    'team_schedule'        => 'LOGISTICS/team_schedule.php',
    'fuel'                 => 'fuel/index.php',
    'maintenance_report'     => 'MaintenanceReport/index.php',

    // ── PO Module
    'po_index'             => 'PO/index.php',

    // ── Accounting Module
    'short_stocks_paid' => 'ACCOUNTING/short_stocks_paid.php',
    'employee_expenses' => 'ACCOUNTING/employee_expenses.php',
    
    // ── Customer Module
    'customer_list'        => 'CUSTOMERS/customer-list.php',
    'customer_detail'      => 'CUSTOMERS/customer-detail.php',

    // ── FINANCE Module
    'delivery_remittance'  => 'FINANCE/delivery_remittance.php',
    'ar_remittance'        => 'FINANCE/ar_remittance.php',
    'invoice_monitoring'   => 'FINANCE/invoice_monitoring.php',
    'check_information'    => 'FINANCE/check_information.php',
    'deduction_records'    => 'FINANCE/deduction_records.php',

    // ── SALES Module
    'sales_order_report'   => 'SALES/sales_order_report.php',

    // ── TEST Module
    'message_user'         => 'TEST/messages.php',

    // ── Forms
    'awards'               => 'forms/awards.php',
    'awards_details'       => 'forms/awards-details.php',
    'contact'              => 'forms/contact.php',
    'newsletter'           => 'forms/newsletter.php',

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

// ── Fetch employee profile from TBL_HREmployeeList ────────────
//
//  ROOT CAUSE FIX: The previous version used PDO ($pdo) to run
//  this query. But the rest of the app (employee-list.php, login.php)
//  uses sqlsrv_query($conn). Mixing drivers caused:
//    1. [System] (reserved word) returned with empty/null key by PDO
//    2. bit columns (Active, Blacklisted) returned as int by sqlsrv
//       but the code compared them as strings via _ep()
//    3. DateTime objects returned by sqlsrv for date columns, while
//       PDO returned strings — inconsistent handling
//  SOLUTION: Use $conn + sqlsrv_query(), matching exactly how
//  employee-list.php fetches the same table. Dates are pre-converted
//  to strings with CONVERT() so no DateTime object handling needed.
//
function get_employee_profile(mixed $conn): ?array {
    // Match the session key names set by login.php exactly
    $employeeId = $_SESSION['EmployeeID'] ?? null;
    $username   = $_SESSION['Username']   ?? null;

    if (!$conn || (!$employeeId && !$username)) return null;

    // Cache removed — was causing Picture and other columns to return
    // stale null values when the SELECT was updated after initial login.
    // Query is lightweight so no cache needed.

    // Use CONVERT() on all date columns so they come back as plain
    // strings (no DateTime objects). Use [System] alias to guarantee
    // the key name is always 'System' regardless of driver behaviour.
    $selectAll = "
        SELECT TOP 1
            e.FileNo,
            e.EmployeeID,
            e.EmployeeID1,
            e.OfficeID,
            e.ApplicationID,
            e.Department,
            e.Position_held,
            e.Category,
            e.Job_tittle,
            e.Branch,
            e.[System]                                      AS [System],
            e.SortNo,
            e.CutOff,
            CONVERT(varchar(10), e.Hired_date, 23)         AS Hired_date,
            CONVERT(varchar(10), e.Date_Of_Seperation, 23) AS Date_Of_Seperation,
            e.Employee_Status,
            CAST(e.Active AS int)                          AS Active,
            CAST(e.Blacklisted AS int)                     AS Blacklisted,
            e.LastName,
            e.FirstName,
            e.MiddleName,
            e.Permanent_Address,
            e.Present_Address,
            e.SSS_Number,
            e.TIN_Number,
            e.Philhealth_Number,
            e.HDMF,
            e.Phone_Number,
            e.Mobile_Number,
            e.Email_Address,
            CONVERT(varchar(10), e.Birth_date, 23)         AS Birth_date,
            e.Birth_Place,
            e.Civil_Status,
            e.Gender,
            e.Nationality,
            e.Religion,
            e.Relationship,
            e.Contact_Person,
            e.Contact_Number_Emergency,
            e.Notes,
            e.Educational_Background,
            e.Picture,
            e.IDPicture,
            e.Signature
        FROM [dbo].[TBL_HREmployeeList] e";

    if ($employeeId) {
        $sql  = $selectAll . " WHERE e.EmployeeID = ? AND e.Active = 1";
        $stmt = sqlsrv_query($conn, $sql, [$employeeId]);
    } else {
        $sql  = $selectAll . " WHERE e.Email_Address = ? AND e.Active = 1";
        $stmt = sqlsrv_query($conn, $sql, [$username]);
    }

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        error_log('get_employee_profile query failed: ' . json_encode($errors));
        return null;
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);

    if (!$row) return null;

    // Trim trailing whitespace from all string values (Gender etc.)
    foreach ($row as $k => $v) {
        if (is_string($v)) $row[$k] = trim($v);
    }

    // Normalise bit/int fields to string '1'/'0' so _ep() comparisons
    // work consistently everywhere in topbar.php
    foreach (['Active', 'Blacklisted'] as $bitCol) {
        if (isset($row[$bitCol])) {
            $row[$bitCol] = ($row[$bitCol] == 1) ? '1' : '0';
        }
    }

    return $row;
}

// ── Format a DB date value cleanly ───────────────────────────
function fmt_date(?string $val, string $format = 'M d, Y'): string {
    if (!$val) return '—';
    try {
        return (new DateTime($val))->format($format);
    } catch (Throwable) {
        return '—';
    }
}

// ── Compute years of service ──────────────────────────────────
function years_of_service(?string $hired_date): string {
    if (!$hired_date) return '—';
    try {
        $hired  = new DateTime($hired_date);
        $diff   = (new DateTime('now', new DateTimeZone('Asia/Manila')))->diff($hired);
        $years  = $diff->y;
        $months = $diff->m;
        if ($years === 0) return "{$months}mo";
        return $months > 0 ? "{$years}y {$months}mo" : "{$years}y";
    } catch (Throwable) {
        return '—';
    }
}