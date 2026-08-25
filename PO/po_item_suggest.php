<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'po_index');

header('Content-Type: application/json');

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
if ($category_id <= 0) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

// For each distinct item description ever used under this category, pull
// only the most recent price (ROW_NUMBER partitioned by description,
// ordered by po_date desc) — so the suggestion reflects current/latest
// pricing, not stale historical prices from months ago.
$sql = "
    SELECT description, cash_price, percent_price
    FROM (
        SELECT pi.description, pi.cash_price, pi.percent_price,
               ROW_NUMBER() OVER (PARTITION BY pi.description ORDER BY po.po_date DESC, pi.po_id DESC) AS rn
        FROM po_item pi
        JOIN purchase_order po ON po.po_id = pi.po_id
        WHERE po.category_id = ?
          AND pi.description IS NOT NULL
          AND LTRIM(RTRIM(pi.description)) <> ''
    ) x
    WHERE rn = 1
    ORDER BY description
";

$res = sqlsrv_query($conn, $sql, [$category_id]);

$items = [];
if ($res) {
    while ($r = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC)) {
        $items[] = [
            'description'   => $r['description'],
            'cash_price'    => (float)$r['cash_price'],
            'percent_price' => (float)$r['percent_price'],
        ];
    }
    echo json_encode(['success' => true, 'items' => $items]);
} else {
    error_log('[po_item_suggest] query failed: ' . print_r(sqlsrv_errors(), true));
    echo json_encode(['success' => false, 'message' => 'A database error occurred.', 'items' => []]);
}
