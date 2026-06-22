<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['UserID'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
rbac_gate($pdo, 'customer_list');

header('Content-Type: application/json');

try {
    // $conn is available globally from nav.php via test_sqlsrv.php

    $sql = "
        SELECT
            CustomerName1       AS Customer,
            Department,
            MAX(Branch)         AS Branch,
            MAX(ARArea)         AS Area,
            MAX(Salesman)       AS Salesman,
            MAX(RemitRemarks)   AS ModeOfPayment
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE CustomerName1 IS NOT NULL AND CustomerName1 <> ''
        GROUP BY CustomerName1, Department
        ORDER BY CustomerName1 ASC
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        throw new Exception(implode(' | ', array_map(fn($e) => $e['message'], sqlsrv_errors())));
    }

    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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
