<?php
// TWM/BULLETIN/bulletin_ajax.php
date_default_timezone_set('Asia/Manila');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check(); // any logged-in user can view/dismiss — no RBAC gate needed here
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Dismissal is session-scoped on purpose: it resets every time the user
// logs out and back in (fresh session), but persists across page loads
// within the same login so navigating around home.php doesn't re-trigger
// posts they already dismissed this session.
if (!isset($_SESSION['bulletin_dismissed']) || !is_array($_SESSION['bulletin_dismissed'])) {
    $_SESSION['bulletin_dismissed'] = [];
}

if ($action === 'list_active') {
    $sql = "SELECT BulletinID, Title, Message, CreatedByName, CreatedAt
            FROM TBL_Bulletin
            WHERE IsActive = 1
              AND CAST(GETDATE() AS DATE) BETWEEN StartDate AND EndDate
            ORDER BY CreatedAt DESC";
    $stmt = sqlsrv_query($conn, $sql);

    $out = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $id = (int) $row['BulletinID'];
            if (in_array($id, $_SESSION['bulletin_dismissed'], true)) {
                continue; // already dismissed this session
            }
            $out[] = [
                'id'      => $id,
                'title'   => $row['Title'],
                'message' => $row['Message'],
                'author'  => $row['CreatedByName'] ?? 'Unknown',
                'date'    => $row['CreatedAt']->format('M j, Y'),
            ];
        }
    } else {
        echo json_encode(['success' => false, 'error' => print_r(sqlsrv_errors(), true)]);
        exit;
    }

    echo json_encode(['success' => true, 'bulletins' => $out]);
    exit;
}

if ($action === 'dismiss') {
    $bulletinId = (int) ($_POST['bulletin_id'] ?? 0);
    if ($bulletinId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid bulletin ID']);
        exit;
    }

    if (!in_array($bulletinId, $_SESSION['bulletin_dismissed'], true)) {
        $_SESSION['bulletin_dismissed'][] = $bulletinId;
    }

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);