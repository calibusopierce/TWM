<?php
/**
 * print_po.php
 * Standalone printable view of a single PO, opened in a new tab from
 * the po-print-btn on technical/purchase_order.php.
 */
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

$poId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$poId) {
    die('Missing PO.');
}

$conn = getConnection();

$poStmt = sqlsrv_query($conn, "SELECT POID, PONumber, SupplierCode, Department, Status, Remarks, Discount, Tax, SubTotal, Total, DateTimeInput FROM TBL_Technical_PO WHERE POID = ?", [$poId]);
$po = $poStmt !== false ? sqlsrv_fetch_array($poStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$po) {
    die('Purchase order not found.');
}

$linesStmt = sqlsrv_query($conn, "SELECT Category, ItemDescription, Brand, Model, Unit, QtyOrdered, UnitCost FROM TBL_Technical_PO_Items WHERE POID = ? ORDER BY POItemID ASC", [$poId]);
$lines = [];
if ($linesStmt !== false) {
    while ($row = sqlsrv_fetch_array($linesStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
    }
}

closeConnection($conn);

$subTotal = (float)($po['SubTotal'] ?? 0);
$discount = (float)($po['Discount'] ?? 0);
$tax      = (float)($po['Tax'] ?? 0);
$total    = (float)($po['Total'] ?? 0);
$discountAmt = $subTotal * $discount / 100;
$afterDiscount = $subTotal - $discountAmt;
$taxAmt = $afterDiscount * $tax / 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($po['PONumber'] ?? 'Purchase Order'); ?> · Print</title>
<style>
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; color: #1b2a41; padding: 32px; max-width: 720px; margin: 0 auto; }
  .label { color: #2a6fdb; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 2px; }
  .value { font-size: 14px; margin: 0 0 14px; font-weight: 600; }
  h2 { color: #2a6fdb; font-size: 15px; margin: 24px 0 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; padding: 8px 6px; border-bottom: 2px solid #1b2a41; font-size: 12px; }
  td { padding: 8px 6px; border-bottom: 1px solid #e3e8ef; vertical-align: top; }
  .num { text-align: right; }
  .totals td { border-bottom: none; padding: 4px 6px; }
  .totals .totals-label { text-align: right; font-weight: 600; }
  .totals .grand { font-weight: 700; border-top: 2px solid #1b2a41; }
  .item-sub { font-size: 11px; color: #7a8699; }
  .print-bar { text-align: right; margin-bottom: 16px; }
  .print-bar button { background:#2a6fdb; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; }
  @media print { .print-bar { display: none; } }
</style>
</head>
<body>

<div class="print-bar"><button onclick="window.print()">Print</button></div>

<p class="label">P.O. Code</p>
<p class="value"><?php echo htmlspecialchars($po['PONumber'] ?? ''); ?></p>

<p class="label">Supplier</p>
<p class="value"><?php echo htmlspecialchars($po['SupplierCode'] ?? ''); ?></p>

<h2>Orders</h2>
<table>
  <thead>
    <tr>
      <th style="width:50px;">Qty</th>
      <th style="width:70px;">Unit</th>
      <th>Item</th>
      <th class="num" style="width:80px;">Cost</th>
      <th class="num" style="width:90px;">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $line): ?>
    <?php $lineTotal = (float)$line['QtyOrdered'] * (float)$line['UnitCost']; ?>
    <tr>
      <td><?php echo number_format((float)$line['QtyOrdered'], 2); ?></td>
      <td><?php echo htmlspecialchars($line['Unit'] ?? ''); ?></td>
      <td>
        <?php echo htmlspecialchars($line['ItemDescription'] ?? ''); ?>
        <div class="item-sub"><?php echo htmlspecialchars(trim(($line['Category'] ?? '') . ' ' . ($line['Brand'] ?? '') . ' ' . ($line['Model'] ?? '')) ?: 'N/A'); ?></div>
      </td>
      <td class="num"><?php echo number_format((float)$line['UnitCost'], 2); ?></td>
      <td class="num"><?php echo number_format($lineTotal, 2); ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<table class="totals">
  <tr><td colspan="4" class="totals-label">Sub Total</td><td class="num"><?php echo number_format($subTotal, 2); ?></td></tr>
  <tr><td colspan="4" class="totals-label">Discount <?php echo number_format($discount, 2); ?>%</td><td class="num"><?php echo number_format($discountAmt, 2); ?></td></tr>
  <tr><td colspan="4" class="totals-label">Tax <?php echo number_format($tax, 2); ?>%</td><td class="num"><?php echo number_format($taxAmt, 2); ?></td></tr>
  <tr class="grand"><td colspan="4" class="totals-label">Total</td><td class="num"><?php echo number_format($total, 2); ?></td></tr>
</table>

<?php if (!empty($po['Remarks'])): ?>
<h2>Remarks</h2>
<p><?php echo nl2br(htmlspecialchars($po['Remarks'])); ?></p>
<?php endif; ?>

</body>
</html>
