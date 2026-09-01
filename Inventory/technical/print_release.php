<?php
/**
 * print_release.php
 * Standalone printable Delivery Receipt for a single release, opened
 * in a new tab from the release-print-btn on technical/release.php.
 *
 * "Released By" is a fixed store rep name -- change RELEASED_BY_NAME
 * below whenever that person changes; it's not pulled from the
 * database because Release doesn't currently track who processed it,
 * only who it was released to.
 */
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

define('RELEASED_BY_NAME', 'SANDRO MUENA');

$releaseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$releaseId) {
    die('Missing release.');
}

$conn = getConnection();

$relStmt = sqlsrv_query($conn, "SELECT ReleaseID, ReleaseNumber, Department, ReleasedTo, Status, Remarks, DateTimeInput FROM TBL_Technical_Release WHERE ReleaseID = ?", [$releaseId]);
$release = $relStmt !== false ? sqlsrv_fetch_array($relStmt, SQLSRV_FETCH_ASSOC) : null;

if (!$release) {
    die('Release not found.');
}

// Image is best-effort: matched by item name back to a registered
// TBL_Technical_Items row that has a photo, since Release/PO lines
// don't carry a direct FK to a specific registered asset.
$linesStmt = sqlsrv_query($conn, "
    SELECT ri.ItemDescription, ri.QtyReleased, ri.QtyReturned,
           pi.UnitCost, pi.Category,
           (SELECT TOP 1 ti.ItemID
            FROM TBL_Technical_Items ti
            WHERE ti.ItemName = ri.ItemDescription AND ti.Image IS NOT NULL
            ORDER BY ti.ItemID DESC) AS ImageItemID
    FROM TBL_Technical_Release_Items ri
    LEFT JOIN TBL_Technical_PO_Items pi ON pi.POItemID = ri.POItemID
    WHERE ri.ReleaseID = ?
    ORDER BY ri.ReleaseItemID ASC", [$releaseId]);
$lines = [];
if ($linesStmt !== false) {
    while ($row = sqlsrv_fetch_array($linesStmt, SQLSRV_FETCH_ASSOC)) {
        $lines[] = $row;
    }
}

closeConnection($conn);

// Cost comes from the PO line each item was released against (via
// POItemID) -- Release itself never tracked price, only quantity.
// Older release lines from before that link existed will show a
// blank cost/total rather than a wrong number.
$grandTotal = 0;
foreach ($lines as &$line) {
    $cost = $line['UnitCost'] !== null ? (float)$line['UnitCost'] : null;
    $qty  = (float)($line['QtyReleased'] ?? 0);
    $line['LineTotal'] = $cost !== null ? $cost * $qty : null;
    if ($line['LineTotal'] !== null) {
        $grandTotal += $line['LineTotal'];
    }
}
unset($line);

$dateReleased = $release['DateTimeInput'] instanceof DateTime ? $release['DateTimeInput']->format('F j, Y g:i A') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($release['ReleaseNumber'] ?? 'Release'); ?> · Delivery Receipt</title>
<link href="https://fonts.googleapis.com/css2?family=League+Gothic&display=swap" rel="stylesheet">
<style>
  @page { size: Letter; margin: 0.45in; }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, Segoe UI, Arial, sans-serif; color: #1b2a41; padding: 20px; max-width: 700px; margin: 0 auto; font-size: 12px; }
  @media print { body { padding: 0; margin: 10px; max-width: none; } }
  .brand-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
  .brand-logo { height: 55px; width: auto; }
  .brand-logo-placeholder { height: 26px; width: 26px; border-radius: 50%; background: #eef0f4; border: 1px dashed #c6cddb; display: flex; align-items: center; justify-content: center; font-size: 6px; color: #9aa5b8; font-family: Arial, sans-serif; flex-shrink: 0; }
  .brand-name { font-family: 'League Gothic', sans-serif; font-size: 26px; text-decoration: underline; letter-spacing: .01em; color: #1b2a41; }
  h1.title { font-size: 18px; margin: 0 0 2px; color: #1b2a41; }
  .release-no { font-size: 12px; color: #4a5568; margin: 0 0 6px; }
  .rule { border: none; border-top: 1.5px solid #1b2a41; margin: 0 0 10px; }
  .field-row { display: flex; gap: 30px; margin-bottom: 8px; }
  .label { color: #2a6fdb; font-size: 10px; font-weight: 600; margin: 0 0 1px; text-transform: uppercase; letter-spacing: .02em; }
  .value { font-size: 11.5px; margin: 0; font-weight: 600; }
  h2 { color: #1b2a41; font-size: 11.5px; margin: 12px 0 4px; }
  table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  th { text-align: left; padding: 3px 4px; border-bottom: 1.5px solid #1b2a41; font-size: 9.5px; }
  td { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: middle; }
  .num { text-align: right; }
  .item-cell { display: flex; align-items: center; gap: 6px; }
  .item-thumb { width: 24px; height: 24px; border-radius: 4px; border: 1px solid #dfe4ec; object-fit: cover; flex-shrink: 0; background: #f3f5f8; }
  .item-thumb-placeholder { width: 24px; height: 24px; border-radius: 4px; border: 1px dashed #c6cddb; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 5.5px; color: #9aa5b8; text-align: center; background: #f8f9fb; line-height: 1.1; }
  .item-name { font-weight: 600; }
  .item-category { font-size: 8.5px; color: #7a8699; margin-top: 0; }
  .grand-total-row { text-align: right; margin-top: 6px; font-size: 11.5px; }
  .grand-total-row .amt { font-weight: 700; margin-left: 8px; }
  .signature-row { display: flex; justify-content: space-between; margin-top: 26px; gap: 40px; }
  .signature-block { flex: 1; text-align: center; }
  .signature-block .sig-label { font-size: 10px; font-weight: 600; text-align: left; margin-bottom: 20px; }
  .signature-block .sig-line { border-top: 1px solid #1b2a41; padding-top: 3px; font-size: 10px; font-weight: 600; letter-spacing: .02em; }
  .print-bar { text-align: right; margin-bottom: 8px; }
  .print-bar button { background:#2a6fdb; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; }
  @media print { .print-bar { display: none; } }
</style>
</head>
<body>

<div class="print-bar"><button onclick="window.print()">Print</button></div>

<div class="brand-header">
  <img src="../assets/img/logo.png" alt="Urban Tradewell Corp." class="brand-logo"
       onerror="this.outerHTML='&lt;div class=&quot;brand-logo-placeholder&quot;&gt;LOGO&lt;/div&gt;'">
  <span class="brand-name">URBAN TRADEWELL CORP.</span>
</div>

<h1 class="title">Delivery Receipt</h1>
<p class="release-no"><?php echo htmlspecialchars($release['ReleaseNumber'] ?? ''); ?></p>
<hr class="rule">

<div class="field-row">
  <div>
    <p class="label">Department</p>
    <p class="value"><?php echo htmlspecialchars($release['Department'] ?? 'N/A'); ?></p>
  </div>
  <div>
    <p class="label">Date Released</p>
    <p class="value"><?php echo htmlspecialchars($dateReleased); ?></p>
  </div>
</div>

<div class="field-row">
  <div>
    <p class="label">Released To</p>
    <p class="value"><?php echo htmlspecialchars($release['ReleasedTo'] ?? 'N/A'); ?></p>
  </div>
</div>

<h2>Items Released</h2>
<table>
  <thead>
    <tr>
      <th>Item</th>
      <th class="num" style="width:60px;">Qty</th>
      <th class="num" style="width:90px;">Cost</th>
      <th class="num" style="width:100px;">Total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($lines as $line): ?>
    <tr>
      <td>
        <div class="item-cell">
          <?php if (!empty($line['ImageItemID'])): ?>
            <img class="item-thumb" src="image_item.php?id=<?php echo (int)$line['ImageItemID']; ?>" alt="">
          <?php else: ?>
            <div class="item-thumb-placeholder">No image</div>
          <?php endif; ?>
          <div>
            <div class="item-name"><?php echo htmlspecialchars($line['ItemDescription'] ?? ''); ?></div>
            <?php if (!empty($line['Category'])): ?>
              <div class="item-category"><?php echo htmlspecialchars($line['Category']); ?></div>
            <?php endif; ?>
          </div>
        </div>
      </td>
      <td class="num"><?php echo number_format((float)($line['QtyReleased'] ?? 0), 0); ?></td>
      <td class="num"><?php echo $line['UnitCost'] !== null ? number_format((float)$line['UnitCost'], 2) : '—'; ?></td>
      <td class="num"><?php echo $line['LineTotal'] !== null ? number_format((float)$line['LineTotal'], 2) : '—'; ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="grand-total-row">Grand Total: <span class="amt"><?php echo number_format($grandTotal, 2); ?></span></div>

<?php if (!empty($release['Remarks'])): ?>
<h2>Remarks</h2>
<p><?php echo nl2br(htmlspecialchars($release['Remarks'])); ?></p>
<?php endif; ?>

<div class="signature-row">
  <div class="signature-block">
    <div class="sig-label">Released By:</div>
    <div class="sig-line"><?php echo htmlspecialchars(RELEASED_BY_NAME); ?></div>
  </div>
  <div class="signature-block">
    <div class="sig-label">Received By:</div>
    <div class="sig-line"><?php echo htmlspecialchars($release['ReleasedTo'] ?? ''); ?></div>
  </div>
</div>

</body>
</html>