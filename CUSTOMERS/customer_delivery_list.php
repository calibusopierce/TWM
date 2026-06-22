<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['UserID'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
rbac_gate($pdo, 'customer_list');
error_reporting(0);
ini_set('display_errors', 0);

if (empty($_SESSION['UserID'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// Check RBAC manually for AJAX — rbac_gate() outputs HTML which breaks JSON
$userType = $_SESSION['UserType'] ?? '';
if (!in_array($userType, rbac_superadmin_roles())) {
    rbac_load_permissions($pdo, $userType);
    if (!rbac_can('customer_list')) {
        echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
    }
}

header('Content-Type: application/json');

try {
    // $conn is available globally from nav.php via test_sqlsrv.php

    // Group by CustomerName1 + Department, pick latest Remarks as ModeOfPayment
    $sql = "
        SELECT
            Customer,
            Department,
            MAX(Branch)         AS Branch,
            MAX(Area)           AS Area,
            MAX(Salesman)       AS Salesman,
            MAX(Remarks)        AS ModeOfPayment
        FROM [dbo].[View_RemittanceCollectionSlip2]
        WHERE Customer IS NOT NULL AND Customer <> ''
        GROUP BY Customer, Department
        ORDER BY Customer ASC
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        throw new Exception(implode(' | ', array_map(fn($e) => $e['message'], sqlsrv_errors())));
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Normalize datetime objects if any
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    echo json_encode(['success' => true, 'rows' => $rows]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}