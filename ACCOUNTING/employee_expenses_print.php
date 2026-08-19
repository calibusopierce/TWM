<?php
/**
 * TWM - Employee Expenses - Print View
 * Prints ALL rows matching the current filters (ignores pagination).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'employee_expenses');

$search    = trim($_GET['search'] ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$department = trim($_GET['department'] ?? '');
$area       = trim($_GET['area'] ?? '');
$exp_type   = trim($_GET['exp_type'] ?? '');

$hasAnyFilter =
    $search    !== '' ||
    $date_from !== '' ||
    $date_to   !== '' ||
    $department !== '' ||
    $area       !== '' ||
    $exp_type   !== '';

if (!$hasAnyFilter) {
    $date_from = date('Y-m-d', strtotime('-6 days'));
    $date_to   = date('Y-m-d');
}

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(Employee_name LIKE ? OR Note LIKE ?)";
    $searchValue = '%' . $search . '%';
    $params[] = $searchValue;
    $params[] = $searchValue;
}
if ($date_from !== '') {
    $where[] = "CONVERT(date, Exp_date) >= CONVERT(date, ?, 23)";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[] = "CONVERT(date, Exp_date) <= CONVERT(date, ?, 23)";
    $params[] = $date_to;
}
if ($department !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Department, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $department;
}
if ($area !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Area, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $area;
}
if ($exp_type !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Exp_type, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $exp_type;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT Department, Employee_name, Position, Note, Exp_date, Exp_type, Exp_amount, Area
        FROM dbo.View_EmployeeExpenses
        $whereSql
        ORDER BY Department ASC, Exp_date DESC";

$stmt = sqlsrv_query($conn, $sql, $params);
if (!$stmt) {
    error_log('[TWM employee_expenses_print] SQL failed: ' . print_r(sqlsrv_errors(), true) . "\nSQL: " . $sql . "\nPARAMS: " . print_r($params, true));
    die('Print failed. Please try again or contact IT.');
}

$rows = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
    }
    $rows[] = $row;
}
sqlsrv_free_stmt($stmt);

$grandTotal = 0;
foreach ($rows as $r) { $grandTotal += (float)($r['Exp_amount'] ?? 0); }

function ee_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Expenses - Print</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'JetBrains Mono', 'Consolas', monospace; color: #1f2937; padding: 24px; }
  h1 { font-size: 18px; margin: 0 0 4px 0; }
  .meta { font-size: 12px; color: #6b7280; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #ede9fe; color: #7c3aed; }
  td.amount, th.amount { text-align: right; }
  tfoot td { font-weight: 700; background: #f9fafb; }
  .print-bar { margin-bottom: 16px; }
  .print-bar button {
    font-family: inherit; padding: 8px 16px; border-radius: 6px;
    border: 1px solid #7c3aed; background: #7c3aed; color: #fff; cursor: pointer;
  }
  @media print {
    .print-bar { display: none; }
    body { padding: 0; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <button onclick="window.print()">Print</button>
</div>

<h1>Employee Expenses</h1>
<div class="meta">
  Generated <?= date('Y-m-d H:i') ?>
  <?php if ($search !== '' || $date_from !== '' || $date_to !== '' || $department !== '' || $area !== '' || $exp_type !== ''): ?>
    &nbsp;|&nbsp; Filters:
    <?php if ($search !== ''): ?> Search="<?= ee_h($search) ?>" <?php endif; ?>
    <?php if ($date_from !== ''): ?> From=<?= ee_h($date_from) ?> <?php endif; ?>
    <?php if ($date_to !== ''): ?> To=<?= ee_h($date_to) ?> <?php endif; ?>
    <?php if ($department !== ''): ?> Dept=<?= ee_h($department) ?> <?php endif; ?>
    <?php if ($area !== ''): ?> Area=<?= ee_h($area) ?> <?php endif; ?>
    <?php if ($exp_type !== ''): ?> Type=<?= ee_h($exp_type) ?> <?php endif; ?>
  <?php endif; ?>
  &nbsp;|&nbsp; Total records: <?= count($rows) ?>
</div>

<table>
  <thead>
    <tr>
      <th>Department</th>
      <th>Employee Name</th>
      <th>Position</th>
      <th>Note</th>
      <th>Exp. Date</th>
      <th>Exp. Type</th>
      <th class="amount">Amount</th>
      <th>Area</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= ee_h($r['Department']) ?></td>
      <td><?= ee_h($r['Employee_name']) ?></td>
      <td><?= ee_h($r['Position']) ?></td>
      <td><?= ee_h($r['Note']) ?></td>
      <td><?= ee_h($r['Exp_date']) ?></td>
      <td><?= ee_h($r['Exp_type']) ?></td>
      <td class="amount"><?= number_format((float)$r['Exp_amount'], 2) ?></td>
      <td><?= ee_h($r['Area']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="6">Grand Total</td>
      <td class="amount"><?= number_format($grandTotal, 2) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>

<script>
  // Auto-open print dialog on load; comment out if you'd rather leave it manual
  // window.onload = () => window.print();
</script>

</body>
</html>