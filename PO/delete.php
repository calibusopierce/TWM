<?php
// TWM/PO/delete.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check(['Admin', 'Administrator']);

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) { header("Location: index.php"); exit; }

$res = sqlsrv_query($conn, "SELECT po_number FROM purchase_orders WHERE po_id = ?", [$po_id]);
$po  = sqlsrv_fetch_array($res, SQLSRV_FETCH_ASSOC);

if ($po) {
    // CASCADE in SQL handles po_items deletion automatically
    sqlsrv_query($conn, "DELETE FROM purchase_orders WHERE po_id = ?", [$po_id]);
}

header("Location: " . base_url('PO/index.php') . "?deleted=1");
exit;