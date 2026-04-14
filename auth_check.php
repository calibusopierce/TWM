<?php
// auth_check.php
// ── Session guard — include at the top of every protected page ─

function auth_check(array $allowedRoles = ['HR', 'Admin']): void {

    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!isset($_SESSION['UserID'], $_SESSION['UserType'])) {
        redirect('login');
    }

    // ── Session timeout (5 hours inactivity) ──────────────────
    $timeout = 18000;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header('Location: ' . route('login') . '?reason=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time();

    // ── Regenerate session ID periodically ────────────────────
    if (!isset($_SESSION['last_regenerated']) || (time() - $_SESSION['last_regenerated']) > 600) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }

    // ── Role check ────────────────────────────────────────────
    if (!in_array($_SESSION['UserType'], $allowedRoles, strict: true)) {
        error_log(sprintf(
            "Unauthorized access — UserID: %s, Role: %s, Page: %s, IP: %s",
            $_SESSION['UserID'],
            $_SESSION['UserType'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['REMOTE_ADDR']
        ));

        $role     = htmlspecialchars($_SESSION['UserType'] ?? 'Unknown');
        $loginUrl = route('login');

        echo "<!DOCTYPE html><html><head>
        <meta charset='UTF-8'>
        <title>Access Denied</title>
        <script src='" . base_url('assets/vendor/sweetalert2/sweetalert2.all.min.js') . "'></script>
        </head><body style='margin:0;background:#0f172a;'>
        <script>
        Swal.fire({
            icon: 'error',
            title: 'Access Denied',
            text: 'Your account ({$role}) does not have permission to view this page.',
            confirmButtonText: 'Back to Login',
            confirmButtonColor: '#1e40af',
            background: '#1e293b',
            color: '#f1f5f9',
            iconColor: '#ef4444',
            allowOutsideClick: false,
            allowEscapeKey: false,
        }).then(() => { window.location.href = '{$loginUrl}'; });
        </script>
        </body></html>";
        exit();
    }
}
