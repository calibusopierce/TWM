<?php
// uniform-po-items.php — AJAX endpoint: returns PO line items as HTML table
if (session_status() === PHP_SESSION_NONE) session_start();
include 'auth_check.php';
include 'test_sqlsrv.php';
auth_check(['Admin','Administrator','HR']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

$poid = intval($_GET['poid'] ?? 0);
if ($poid <= 0) { echo '<p style="color:#dc2626">Invalid PO.</p>'; exit; }

$items = [];
$stmt  = sqlsrv_query($conn2,
    "SELECT UniformType, Size, Requested, Additional, (Requested+Additional)*3 AS TotalPieces
     FROM [dbo].[UniformPOItems] WHERE POID=?
     ORDER BY UniformType, CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4 WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END",
    [$poid]);
if ($stmt) {
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $items[] = $r;
    sqlsrv_free_stmt($stmt);
}

if (empty($items)) { echo '<p style="color:var(--text-muted);text-align:center;padding:1.5rem;">No line items found for this PO.</p>'; exit; }

$grouped = ['TSHIRT'=>[],'POLOSHIRT'=>[]];
foreach ($items as $it) $grouped[$it['UniformType']][] = $it;

foreach ($grouped as $type => $rows):
    if (empty($rows)) continue;
    $label = $type === 'TSHIRT' ? 'T-Shirt' : 'Polo Shirt';
    $color = $type === 'TSHIRT' ? '#1e40af' : '#059669';
    $bg    = $type === 'TSHIRT' ? 'rgba(59,130,246,.08)' : 'rgba(16,185,129,.08)';
    $grandTotal = array_sum(array_column($rows,'TotalPieces'));
?>
<div style="margin-bottom:1.25rem;">
  <div style="background:<?= $bg ?>;color:<?= $color ?>;font-weight:700;font-size:.8rem;padding:.45rem .85rem;border-radius:8px 8px 0 0;border:1px solid <?= $color ?>33;">
    <?= $label ?>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:.8rem;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;overflow:hidden;">
    <thead>
      <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
        <th style="padding:.45rem .85rem;text-align:left;color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;">Size</th>
        <th style="padding:.45rem .85rem;text-align:center;color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;">Requested</th>
        <th style="padding:.45rem .85rem;text-align:center;color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;">Additional</th>
        <th style="padding:.45rem .85rem;text-align:center;color:#94a3b8;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;">Total Pieces (×3)</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="border-bottom:1px solid #e2e8f0;">
        <td style="padding:.45rem .85rem;font-family:'DM Mono',monospace;font-weight:700;"><?= htmlspecialchars($r['Size']) ?></td>
        <td style="padding:.45rem .85rem;text-align:center;font-family:'DM Mono',monospace;"><?= intval($r['Requested']) ?></td>
        <td style="padding:.45rem .85rem;text-align:center;font-family:'DM Mono',monospace;"><?= intval($r['Additional']) ?></td>
        <td style="padding:.45rem .85rem;text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:<?= $color ?>;"><?= intval($r['TotalPieces']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr style="background:#f8fafc;">
        <td colspan="3" style="padding:.45rem .85rem;font-weight:700;color:<?= $color ?>;">Total <?= $label ?></td>
        <td style="padding:.45rem .85rem;text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:<?= $color ?>;"><?= intval($grandTotal) ?></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endforeach; ?>