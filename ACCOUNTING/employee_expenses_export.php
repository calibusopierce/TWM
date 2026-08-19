<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'employee_expenses');

// ── Same filters as employee_expenses.php ────────────────
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$selDept  = isset($_GET['department']) ? trim($_GET['department']) : '';
$selArea  = isset($_GET['area'])       ? trim($_GET['area'])       : '';
$selType  = isset($_GET['exp_type'])   ? trim($_GET['exp_type'])   : '';
$search   = isset($_GET['search'])     ? trim($_GET['search'])     : '';

$hasAnyFilter =
    $dateFrom !== '' ||
    $dateTo   !== '' ||
    $selDept  !== '' ||
    $selArea  !== '' ||
    $selType  !== '' ||
    $search   !== '';

if (!$hasAnyFilter) {
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $dateTo   = date('Y-m-d');
}

$where  = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = "CONVERT(date, Exp_date) >= CONVERT(date, ?, 23)";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "CONVERT(date, Exp_date) <= CONVERT(date, ?, 23)";
    $params[] = $dateTo;
}
if ($selDept !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Department, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selDept;
}
if ($selArea !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Area, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selArea;
}
if ($selType !== '') {
    $where[] = "UPPER(LTRIM(RTRIM(ISNULL(Exp_type, '')))) = UPPER(LTRIM(RTRIM(?)))";
    $params[] = $selType;
}
if ($search !== '') {
    $where[] = "(Employee_name LIKE ? OR Note LIKE ?)";
    $searchValue = '%' . $search . '%';
    $params[] = $searchValue;
    $params[] = $searchValue;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT [Department],[Employee_name],[Position],[Note],[Exp_date],[Exp_type],[Exp_amount],[Area]
    FROM [dbo].[View_EmployeeExpenses]
    $whereSql
    ORDER BY Exp_date DESC
";

$stmt = sqlsrv_query($conn, $sql, $params);
if (!$stmt) {
    error_log('[TWM employee_expenses_export] SQL failed: ' . print_r(sqlsrv_errors(), true) . "\nSQL: " . $sql . "\nPARAMS: " . print_r($params, true));
    die('Export failed. Please try again or contact IT.');
}

$filename = 'employee_expenses_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so peso sign / special chars render correctly in Excel

echo "<table border='1'>";
echo "<tr>
        <th>Department</th><th>Employee Name</th><th>Position</th><th>Note</th>
        <th>Exp. Date</th><th>Exp. Type</th><th>Amount</th><th>Area</th>
      </tr>";

$totalAmount = 0;
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach ($row as $k => $v) {
        if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
    }
    $amount = (float)($row['Exp_amount'] ?? 0);
    $totalAmount += $amount;

    echo "<tr>
            <td>" . htmlspecialchars($row['Department'] ?? '') . "</td>
            <td>" . htmlspecialchars($row['Employee_name'] ?? '') . "</td>
            <td>" . htmlspecialchars($row['Position'] ?? '') . "</td>
            <td>" . htmlspecialchars($row['Note'] ?? '') . "</td>
            <td>" . htmlspecialchars($row['Exp_date'] ?? '') . "</td>
            <td>" . htmlspecialchars($row['Exp_type'] ?? '') . "</td>
            <td>" . number_format($amount, 2) . "</td>
            <td>" . htmlspecialchars($row['Area'] ?? '') . "</td>
          </tr>";
}
sqlsrv_free_stmt($stmt);

echo "<tr>
        <td colspan='6' align='right'><b>TOTAL</b></td>
        <td><b>" . number_format($totalAmount, 2) . "</b></td>
        <td></td>
      </tr>";
echo "</table>";
exit;