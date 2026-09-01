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
    $userDept   = $_SESSION['Department'] ?? '';
    $userBranch = $_SESSION['Branch'] ?? '';

    $sql = "SELECT b.BulletinID, b.Title, b.Message, b.CreatedByName, b.CreatedAt, c.CategoryName
            FROM TBL_Bulletin b
            LEFT JOIN TBL_Bulletin_Category c ON c.CategoryID = b.CategoryID
            WHERE b.IsActive = 1
              AND CAST(GETDATE() AS DATE) BETWEEN b.StartDate AND b.EndDate
              AND (
                    NOT EXISTS (SELECT 1 FROM TBL_Bulletin_TargetDepartment td WHERE td.BulletinID = b.BulletinID)
                    OR EXISTS (SELECT 1 FROM TBL_Bulletin_TargetDepartment td WHERE td.BulletinID = b.BulletinID AND td.Department = ?)
                  )
              AND (
                    NOT EXISTS (SELECT 1 FROM TBL_Bulletin_TargetBranch tb WHERE tb.BulletinID = b.BulletinID)
                    OR EXISTS (SELECT 1 FROM TBL_Bulletin_TargetBranch tb WHERE tb.BulletinID = b.BulletinID AND tb.Branch = ?)
                  )
            ORDER BY b.CreatedAt DESC";
    $stmt = sqlsrv_query($conn, $sql, [$userDept, $userBranch]);

    $out = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $id = (int) $row['BulletinID'];
            if (in_array($id, $_SESSION['bulletin_dismissed'], true)) {
                continue; // already dismissed this session
            }
            $out[] = [
                'id'       => $id,
                'title'    => $row['Title'],
                'message'  => $row['Message'],
                'author'   => $row['CreatedByName'] ?? 'Unknown',
                'date'     => $row['CreatedAt']->format('M j, Y'),
                'category' => $row['CategoryName'] ?? 'General Announcement',
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