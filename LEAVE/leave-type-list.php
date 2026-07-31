<?php
/* =====================================================================
   leave-type-list.php
   File location: TWM/LEAVE/leave-type-list.php
   RBAC module key: leave_management

   Returns the full leave type catalog (small table, so no server-side
   pagination — client filters/searches the cached list in-browser via
   the "search" param below, matching how the page already caches it).
   ===================================================================== */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_management');
ob_end_clean();

header('Content-Type: application/json');

$search = trim($_GET['search'] ?? '');

$sql = "SELECT ID, Code, Type_Name, Category, With_Pay, Regular_Credit,
               Requires_Attachment, Max_Days_Per_Year, Carry_Forward, Status
        FROM dbo.Tbl_Leave_Type";
$params = [];

if ($search !== '') {
    $sql .= " WHERE Code LIKE :search1 OR Type_Name LIKE :search2";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

$sql .= " ORDER BY Type_Name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'rows' => $rows]);
