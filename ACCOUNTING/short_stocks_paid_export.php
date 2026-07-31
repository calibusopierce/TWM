<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'short_stocks_paid');

$dateFromSet = isset($_GET['date_from']);
$dateToSet   = isset($_GET['date_to']);
$dateFrom    = $dateFromSet ? trim($_GET['date_from']) : '';
$dateTo      = $dateToSet   ? trim($_GET['date_to'])   : '';

// Same current-month default as the list page for a fresh/no-filter export
if (!$dateFromSet && !$dateToSet) {
    $dateFrom = date('Y-m-01');
    $dateTo   = date('Y-m-t');
}

$department= trim($_GET['department'] ?? '');
$area      = trim($_GET['area'] ?? '');
$outlet    = trim($_GET['outlet'] ?? '');
$typeShort = trim($_GET['type_short'] ?? '');
$category  = trim($_GET['category'] ?? '');
$status    = trim($_GET['status'] ?? '');
$search    = trim($_GET['search'] ?? '');
$viewMode  = isset($_GET['view']) ? trim($_GET['view']) : 'unpaid';

$where  = [];
$params = [];

if ($viewMode !== 'all') $where[] = "Source IS NULL";
if ($dateFrom !== '') { $where[] = "[DatePaid] >= :date_from"; $params[':date_from'] = $dateFrom; }
if ($dateTo !== '')   { $where[] = "[DatePaid] <= :date_to";   $params[':date_to']   = $dateTo; }
if ($department !== ''){ $where[] = "[Department] = :department"; $params[':department'] = $department; }
if ($area !== '')      { $where[] = "[Area] = :area"; $params[':area'] = $area; }
if ($outlet !== '')    { $where[] = "[Outlet] = :outlet"; $params[':outlet'] = $outlet; }
if ($typeShort !== '') { $where[] = "[TypeShort] = :type_short"; $params[':type_short'] = $typeShort; }
if ($category !== '')  { $where[] = "[Category] = :category"; $params[':category'] = $category; }
if ($status !== '')    { $where[] = "[StatusofShort] = :status"; $params[':status'] = $status; }
if ($search !== '') {
    $where[] = "([EmployeeName] LIKE :search OR [RefNo] LIKE :search OR [PlateNumber] LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Full detail record — same column set as short_stocks_paid_ajax.php,
// independent of whatever's trimmed on the visible table / print report.
$sql = "
    SELECT
        [SEID], [Position], [Status], [Amount], [SPPID], [AmountDue], [PaidAmount],
        [Balance], [DateGenerate], [SDID], [DID], [Department],
        CONVERT(varchar(10), [DateSchedule], 23) AS [DateSchedule],
        [PlateNumber], [Area], [Outlet], [RefNo], [TotalAmount], [NumAccountable],
        [AmountL], [StatusofShort], [Remarks], [IDS], [EmployeeID], [EmployeeName],
        CONVERT(varchar(10), [DatePaid], 23) AS [DatePaid],
        [TypeShort], [Category], [Employee_Status], [Job_tittle], [Position_held],
        [PaymentID], [Source],
        CONVERT(varchar(10), [DateGenerate], 23) AS [DateGenerateFmt]
    FROM [dbo].[View_ShortPaymentPaidDetails]
    $whereSql
    ORDER BY [DatePaid] DESC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = $totalAmountDue = $totalPaid = $totalBalance = $totalAmountTotal = $totalAmountL = 0;
foreach ($rows as $r) {
    $totalAmount      += floatval($r['Amount']);
    $totalAmountDue   += floatval($r['AmountDue']);
    $totalPaid        += floatval($r['PaidAmount']);
    $totalBalance     += floatval($r['Balance']);
    $totalAmountTotal += floatval($r['TotalAmount']);
    $totalAmountL     += floatval($r['AmountL']);
}

$filename = 'short_stocks_paid_full_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders ₱ / accented text correctly
?>
<table border="1">
    <thead>
        <tr>
            <th>SEID</th>
            <th>Employee ID</th>
            <th>Employee Name</th>
            <th>Position</th>
            <th>Job Title</th>
            <th>Position Held</th>
            <th>Employee Status</th>
            <th>Record Status</th>
            <th>Department</th>
            <th>Area</th>
            <th>Outlet</th>
            <th>Plate Number</th>
            <th>SPPID</th>
            <th>Ref No.</th>
            <th>SDID</th>
            <th>DID</th>
            <th>IDS</th>
            <th>Amount</th>
            <th>Total Amount</th>
            <th>Amount Due</th>
            <th>Paid Amount</th>
            <th>Balance</th>
            <th>Amount (L)</th>
            <th>Num. Accountable</th>
            <th>Date Generated</th>
            <th>Date Schedule</th>
            <th>Date Paid</th>
            <th>Type Short</th>
            <th>Category</th>
            <th>Status of Short</th>
            <th>Payment ID</th>
            <th>Source</th>
            <th>Remarks</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?php echo htmlspecialchars($r['SEID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['EmployeeID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['EmployeeName'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Position'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Job_tittle'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Position_held'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Employee_Status'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Status'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Department'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Area'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Outlet'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['PlateNumber'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['SPPID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['RefNo'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['SDID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['DID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['IDS'] ?? '-'); ?></td>
            <td><?php echo number_format((float)($r['Amount'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($r['TotalAmount'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($r['AmountDue'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($r['PaidAmount'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($r['Balance'] ?? 0), 2); ?></td>
            <td><?php echo number_format((float)($r['AmountL'] ?? 0), 2); ?></td>
            <td><?php echo htmlspecialchars($r['NumAccountable'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['DateGenerateFmt'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['DateSchedule'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['DatePaid'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['TypeShort'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Category'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['StatusofShort'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['PaymentID'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Source'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['Remarks'] ?? '-'); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="17" align="right"><b>TOTALS</b></td>
            <td><b><?php echo number_format($totalAmount, 2); ?></b></td>
            <td><b><?php echo number_format($totalAmountTotal, 2); ?></b></td>
            <td><b><?php echo number_format($totalAmountDue, 2); ?></b></td>
            <td><b><?php echo number_format($totalPaid, 2); ?></b></td>
            <td><b><?php echo number_format($totalBalance, 2); ?></b></td>
            <td><b><?php echo number_format($totalAmountL, 2); ?></b></td>
            <td colspan="9"></td>
        </tr>
    </tfoot>
</table>