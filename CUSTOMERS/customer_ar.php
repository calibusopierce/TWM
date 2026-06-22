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
            ARRefNo,
            ARCollectionNo,
            InvoiceNo,
            DocNo,
            InvoiceDate,
            DateCollection,
            ARForCollectionDate,
            RemitRemarks       AS ModeOfPayment,
            Bank,
            BankBranch,
            CheckNo,
            CheckDate,
            InvoiceAmount,
            TotalAmount,
            Remitted,
            PaidAmount,
            Cash,
            CheckAmount,
            Balance,
            Deduction,
            Status,
            Status1,
            PayTag,
            Terms,
            ARNote,
            Note,
            Branch,
            ARArea             AS Area,
            Salesman,
            Department,
            RemittanceNo,
            ForCollectionNo,
            DateAndTimeInput,
            InputName
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE CustomerName1 = ?
          AND Department = ?
        ORDER BY InvoiceDate DESC, DateAndTimeInput DESC
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
