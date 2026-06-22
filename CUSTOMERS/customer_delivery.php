<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['UserID'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
rbac_gate($pdo, 'customer_list');

header('Content-Type: application/json');

$customer   = $_GET['customer']   ?? '';
$department = $_GET['department'] ?? '';

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer is required.']);
    exit;
}

try {
    // $conn is available globally from nav.php via test_sqlsrv.php

    $sql = "
        SELECT
            InvoiceNo,
            DocNo,
            InvoiceDate,
            DocDate,
            Remarks        AS ModeOfPayment,
            Bank,
            CheckNo,
            CheckDate,
            NetAmount,
            CashAmount,
            CheckAmount,
            TotalPaid,
            CreditAmount,
            AdjustmentAmount,
            AddLess,
            ManualLess,
            ManualAdd,
            Note,
            Branch,
            Area,
            Salesman,
            Department,
            DatetimeInput,
            InvoiceRemarks,
            ARCreate
        FROM [dbo].[View_RemittanceCollectionSlip2]
        WHERE Customer = ?
          AND Department = ?
        ORDER BY InvoiceDate DESC, DocDate DESC
    ";

    $params = [$customer, $department];
    $stmt = sqlsrv_query($conn, $sql, $params);
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

    echo json_encode(['success' => true, 'rows' => $rows, 'count' => count($rows)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}