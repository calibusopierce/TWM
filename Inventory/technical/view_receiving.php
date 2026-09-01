<?php
/**
 * view_receiving.php
 * Read-only "proof of receipt" for a completed receiving record.
 * Opened from technical/receiving.php's View and Print actions --
 * Print just adds ?autoprint=1 to trigger the print dialog on load.
 */
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

$receivingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$autoprint   = isset($_GET['autoprint']);

if (!$receivingId) {
    die('Missing receiving record.');
}

$conn = getConnection();

$headerStmt = sqlsrv_query(
    $conn,
    "SELECT r.ReceivingID, r.ReceivingNumber, r.SupplierCode, r.DateTimeInput, r.Remarks,
            p.PONumber, p.Department
     FROM TBL_Technical_Receiving r
     LEFT JOIN TBL_Technical_PO p ON p.POID = r.POID
     WHERE r.ReceivingID = ?",
    [$receivingId]
);
$header = $headerStmt !== false ? sqlsrv_fetch_array($headerStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$header) {
    die('Receiving record not found.');
}

$linesStmt = sqlsrv_query(
    $conn,
    "SELECT ri.QtyReceived, pi.Category, pi.ItemDescription, pi.Brand, pi.Model, pi.Unit, pi.UnitCost
     FROM TBL_Technical_Receiving_Items ri
     LEFT JOIN TBL_Technical_PO_Items pi ON pi.POItemID = ri.POItemID
     WHERE ri.ReceivingID = ?
     ORDER BY ri.ReceivingItemID ASC",
    [$receivingId]
);
$lines = [];
$totalItems = 0;
if ($linesStmt !== false) {
    while ($row = sqlsrv_fetch_array($linesStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
        $totalItems += (float)$row['QtyReceived'];
    }
}

closeConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($header['ReceivingNumber'] ?? 'Receiving'); ?> · Proof of Receipt</title>
<style>
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; color: #1b2a41; padding: 32px; max-width: 720px; margin: 0 auto; }
  .label { color: #2a6fdb; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin: 0 0 2px; }
  .value { font-size: 14px; margin: 0 0 14px; font-weight: 600; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  h2 { color: #2a6fdb; font-size: 15px; margin: 24px 0 10px; }
  .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; margin-top: 18px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; padding: 8px 6px; border-bottom: 2px solid #1b2a41; font-size: 12px; }
  td { padding: 8px 6px; border-bottom: 1px solid #e3e8ef; vertical-align: top; }
  .num { text-align: right; }
  .item-sub { font-size: 11px; color: #7a8699; }
  .print-bar { text-align: right; margin-bottom: 16px; }
  .print-bar button { background:#2a6fdb; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; }
  .stamp { display:inline-block; margin-top:24px; padding:10px 16px; border:2px solid #1a8a5f; color:#1a8a5f; font-weight:700; letter-spacing:.05em; border-radius:6px; }
  @media print { .print-bar { display: none; } }
</style>
</head>
<body>

<div class="print-bar"><button onclick="window.print()">Print</button></div>

<h1>Proof of Receipt</h1>
<p class="hint" style="color:#7a8699; font-size:12px; margin-top:0;"><?php echo htmlspecialchars($header['ReceivingNumber'] ?? ''); ?></p>

<div class="meta-grid">
  <div>
    <p class="label">P.O. Code</p>
    <p class="value"><?php echo htmlspecialchars($header['PONumber'] ?? ''); ?></p>
  </div>
  <div>
    <p class="label">Supplier</p>
    <p class="value"><?php echo htmlspecialchars($header['SupplierCode'] ?? ''); ?></p>
  </div>
  <div>
    <p class="label">Department</p>
    <p class="value"><?php echo htmlspecialchars($header['Department'] ?? ''); ?></p>
  </div>
  <div>
    <p class="label">Date Received</p>
    <p class="value"><?php echo $header['DateTimeInput'] ? $header['DateTimeInput']->format('M j, Y g:i A') : ''; ?></p>
  </div>
</div>

<h2>Items Received</h2>
<table>
  <thead>
    <tr>
      <th style="width:60px;">Qty</th>
      <th style="width:70px;">Unit</th>
      <th>Item</th>
      <th class="num" style="width:80px;">Cost</th>
      <th class="num" style="width:90px;">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $line): ?>
    <?php $lineTotal = (float)$line['QtyReceived'] * (float)$line['UnitCost']; ?>
    <tr>
      <td><?php echo number_format((float)$line['QtyReceived'], 2); ?></td>
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

<p style="margin-top:10px; font-size:12px; color:#7a8699;">Total items received: <?php echo number_format($totalItems, 2); ?></p>

<?php if (!empty($header['Remarks'])): ?>
<h2>Remarks</h2>
<p><?php echo nl2br(htmlspecialchars($header['Remarks'])); ?></p>
<?php endif; ?>

<div class="stamp">✓ RECEIVED</div>

<?php if ($autoprint): ?>
<script>window.onload = function () { window.print(); };</script>
<?php endif; ?>

</body>
</html>
