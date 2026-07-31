<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

rbac_gate($pdo, 'short_stocks_paid');

$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$department= trim($_GET['department'] ?? '');
$area      = trim($_GET['area'] ?? '');
$outlet    = trim($_GET['outlet'] ?? '');
$typeShort = trim($_GET['type_short'] ?? '');
$category  = trim($_GET['category'] ?? '');
$status    = trim($_GET['status'] ?? '');
$search    = trim($_GET['search'] ?? '');

$where  = [];
$params = [];

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

$sql = "
    SELECT
        [EmployeeName], [Department], [Area], [Outlet], [PlateNumber],
        [RefNo], [AmountDue], [PaidAmount], [Balance],
        CONVERT(varchar(10), [DatePaid], 23) AS [DatePaid],
        [TypeShort], [Category], [StatusofShort], [PaymentID], [Source]
    FROM [dbo].[View_ShortPaymentPaidDetails]
    $whereSql
    ORDER BY [DatePaid] DESC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalDue     = 0;
$totalPaid    = 0;
$totalBalance = 0;
foreach ($rows as $r) {
    $totalDue     += floatval($r['AmountDue']);
    $totalPaid    += floatval($r['PaidAmount']);
    $totalBalance += floatval($r['Balance']);
}

function peso_fmt($n) {
    return '₱' . number_format((float)$n, 2);
}

$filterSummary = [];
if ($dateFrom || $dateTo) $filterSummary[] = 'Date Paid: ' . ($dateFrom ?: '...') . ' to ' . ($dateTo ?: '...');
if ($department) $filterSummary[] = 'Department: ' . $department;
if ($area) $filterSummary[] = 'Area: ' . $area;
if ($outlet) $filterSummary[] = 'Outlet: ' . $outlet;
if ($typeShort) $filterSummary[] = 'Type Short: ' . $typeShort;
if ($category) $filterSummary[] = 'Category: ' . $category;
if ($status) $filterSummary[] = 'Status: ' . $status;
if ($search) $filterSummary[] = 'Search: "' . $search . '"';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Short Stocks Paid - Report</title>
<style>
* { box-sizing: border-box; }
body { font-family: 'IBM Plex Sans', Arial, sans-serif; color: #1f2430; padding: 24px; }
.print-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #2563eb; padding-bottom: 12px; margin-bottom: 16px; }
.print-title { font-size: 1.35rem; font-weight: 700; margin: 0; }
.print-meta { font-size: .78rem; color: #6b7280; margin-top: 4px; }
.filters-box { background: #eaf0fe; border-radius: 8px; padding: 10px 14px; font-size: .8rem; margin-bottom: 16px; color: #1e3a8a; }
.filters-box strong { display: block; margin-bottom: 4px; }

@page { size: A4 landscape; margin: 10mm; }
table { width: 100%; border-collapse: collapse; font-size: .68rem; table-layout: fixed; }
thead th {
    background: #2563eb; color: #fff; text-align: left;
    padding: 5px 6px; word-wrap: break-word; overflow-wrap: break-word;
}
tbody td { padding: 4px 6px; border-bottom: 1px solid #e2e5ea; word-wrap: break-word; overflow-wrap: break-word; vertical-align: top; }
tbody tr:nth-child(even) { background: #f9fafb; }

tfoot td { padding: 8px; font-weight: 700; border-top: 2px solid #2563eb; }

.summary-cards { display: flex; gap: 12px; margin-bottom: 16px; }
.summary-card { border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px 14px; flex: 1; }
.summary-card .label { font-size: .72rem; color: #6b7280; text-transform: uppercase; }
.summary-card .value { font-size: 1.05rem; font-weight: 700; margin-top: 2px; }

.print-btn {
    background: #2563eb; color: #fff; border: none; border-radius: 8px;
    padding: 8px 16px; font-weight: 600; cursor: pointer; font-size: .85rem;
}

@media print {
    .no-print { display: none !important; }
    body { padding: 0; }
}
</style>
</head>
<body>

<div class="no-print" style="text-align:right; margin-bottom: 12px;">
    <button class="print-btn" onclick="window.print()">Print</button>
</div>

<div class="print-header">
    <div>
        <h1 class="print-title">Short Stocks Paid — Report</h1>
        <div class="print-meta">Generated on <?php echo date('F j, Y g:i A'); ?></div>
    </div>
    <div class="print-meta" style="text-align:right;">Urban Tradewell Corporation<br>TWM Finance Module</div>
</div>

<?php if (!empty($filterSummary)): ?>
<div class="filters-box">
    <strong>Applied Filters</strong>
    <?php echo htmlspecialchars(implode(' | ', $filterSummary)); ?>
</div>
<?php endif; ?>

<div class="summary-cards">
    <div class="summary-card">
        <div class="label">Total Records</div>
        <div class="value"><?php echo count($rows); ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Amount Due</div>
        <div class="value"><?php echo peso_fmt($totalDue); ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Paid</div>
        <div class="value"><?php echo peso_fmt($totalPaid); ?></div>
    </div>
    <div class="summary-card">
        <div class="label">Total Balance</div>
        <div class="value"><?php echo peso_fmt($totalBalance); ?></div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Employee Name</th>
            <th>Department</th>
            <th>Area</th>
            <th>Outlet</th>
            <th>Plate No.</th>
            <th>Ref No.</th>
            <th>Amount Due</th>
            <th>Paid Amount</th>
            <th>Balance</th>
            <th>Date Paid</th>
            <th>Type Short</th>
            <th>Category</th>
            <th>Status</th>
            <th>Payment ID</th>
            <th>Source</th>
            <th>Remarks</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="16" style="text-align:center; padding: 16px;">No records found for the selected filters.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars($r['EmployeeName'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Department'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Area'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Outlet'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['PlateNumber'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['RefNo'] ?? '-'); ?></td>
                <td><?php echo peso_fmt($r['AmountDue']); ?></td>
                <td><?php echo peso_fmt($r['PaidAmount']); ?></td>
                <td><?php echo peso_fmt($r['Balance']); ?></td>
                <td><?php echo htmlspecialchars($r['DatePaid'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['TypeShort'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Category'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['StatusofShort'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['PaymentID'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Source'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($r['Remarks'] ?? '-'); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
    <?php if (!empty($rows)): ?>
    <tfoot>
        <tr>
            <td colspan="6" style="text-align:right;">TOTALS</td>
            <td><?php echo peso_fmt($totalDue); ?></td>
            <td><?php echo peso_fmt($totalPaid); ?></td>
            <td><?php echo peso_fmt($totalBalance); ?></td>
            <td colspan="7"></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<script>
// Auto-open print dialog for convenience; comment out if not desired.
// window.onload = () => window.print();
</script>

</body>
</html>
