<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$recId = intval($_GET['recid'] ?? 0);
if (!$recId) { echo '<p style="color:#dc2626">Invalid record.</p>'; exit; }

function rq2($conn2,$sql,$p=[]){
    $stmt=empty($p)?sqlsrv_query($conn2,$sql):sqlsrv_query($conn2,$sql,$p);
    if(!$stmt) return [];
    $rows=[];
    while($r=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $rows[]=$r;
    sqlsrv_free_stmt($stmt);
    return $rows;
}
function fmtD($v){
    if(!$v) return '—';
    if($v instanceof DateTime) return $v->format('M d, Y');
    return is_string($v)?date('M d, Y',strtotime($v)):'—';
}
function sf($s){ return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

$rec = rq2($conn2,
    "SELECT r.*,p.PONumber FROM [dbo].[UniformReceiving] r
     LEFT JOIN [dbo].[UniformPO] p ON p.POID=r.POID
     WHERE r.RFID=?",[$recId]);
if(empty($rec)){ echo '<p style="color:#dc2626">Record not found.</p>'; exit; }
$rec = $rec[0];

$items = rq2($conn2,
    "SELECT * FROM [dbo].[UniformReceivingItems] WHERE RFID=?
     ORDER BY CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4
     WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END",
    [$recId]);

// PO ordered quantities for variance
$poItems = rq2($conn2,"SELECT * FROM [dbo].[UniformPOItems] WHERE POID=?",[$rec['POID']??0]);
$poMap   = [];
foreach($poItems as $pi) $poMap[$pi['UniformType']][$pi['Size']] = intval($pi['Requested'])+intval($pi['Additional']);

$uType     = $rec['UniformType'] ?? '';
$typeLabel = $uType==='TSHIRT' ? 'T-Shirt (Logistics)' : ($uType==='POLOSHIRT' ? 'Polo Shirt (Office / Sales)' : $uType);
$accent    = $uType==='TSHIRT' ? '#1e40af' : '#0891b2';
$light     = $uType==='TSHIRT' ? 'rgba(59,130,246,.08)' : 'rgba(8,145,178,.08)';
$border    = $uType==='TSHIRT' ? 'rgba(59,130,246,.2)' : 'rgba(8,145,178,.2)';
$bdgCls    = $uType==='TSHIRT' ? 'bdg-tshirt' : 'bdg-polo';
$grandTotal = array_sum(array_column($items,'Quantity'));
?>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:1.1rem;">
  <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;">PO Number</div>
    <div style="font-family:'DM Mono',monospace;font-weight:700;color:var(--primary);font-size:.88rem;"><?= sf($rec['PONumber']??'—') ?></div>
  </div>
  <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;">Date Received</div>
    <div style="font-family:'DM Mono',monospace;font-weight:700;color:var(--text-primary);font-size:.88rem;"><?= fmtD($rec['RFDate']) ?></div>
  </div>
  <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;">Printing Shop</div>
    <div style="font-weight:700;color:var(--text-primary);font-size:.88rem;"><?= sf($rec['PrintingShop']??'—') ?></div>
  </div>
  <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;">Printing Shop Rep</div>
    <div style="font-weight:600;color:var(--text-primary);font-size:.85rem;"><?= sf($rec['RepresentativeThem']??'—') ?></div>
  </div>
  <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.2rem;">UTC Representative</div>
    <div style="font-weight:600;color:var(--text-primary);font-size:.85rem;"><?= sf($rec['RepresentativeUs']??'—') ?></div>
  </div>
  <div style="background:<?= $light ?>;border:1px solid <?= $border ?>;border-radius:9px;padding:.6rem .85rem;">
    <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?= $accent ?>;margin-bottom:.2rem;">Uniform Type</div>
    <div style="font-weight:700;color:<?= $accent ?>;font-size:.85rem;"><?= sf($typeLabel) ?></div>
  </div>
</div>

<div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;overflow:hidden;">
  <div style="background:<?= $light ?>;border-bottom:1.5px solid <?= $border ?>;padding:.6rem 1rem;font-family:'Sora',sans-serif;font-size:.82rem;font-weight:700;color:<?= $accent ?>;display:flex;align-items:center;gap:.4rem;">
    <i class="bi bi-grid-3x3-gap-fill"></i> <?= sf($typeLabel) ?> — Size Breakdown
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
    <thead>
      <tr>
        <th style="padding:.45rem .85rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);background:var(--surface-2);border-bottom:1px solid var(--border);">Size</th>
        <th style="padding:.45rem .85rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);background:var(--surface-2);border-bottom:1px solid var(--border);">PO Ordered</th>
        <th style="padding:.45rem .85rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);background:var(--surface-2);border-bottom:1px solid var(--border);">Qty Received</th>
        <th style="padding:.45rem .85rem;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);background:var(--surface-2);border-bottom:1px solid var(--border);">Variance</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($items as $it):
      $sz     = $it['Size'];
      $qtyRec = intval($it['Quantity']);
      $poOrd  = intval($poMap[$uType][$sz] ?? 0);
      $diff   = $qtyRec - $poOrd;
      $varCol = $diff===0 ? '#059669' : ($diff>0 ? '#ca8a04' : '#dc2626');
    ?>
    <tr>
      <td style="padding:.5rem .85rem;border-bottom:1px solid var(--border);">
        <span class="bdg <?= $bdgCls ?>" style="font-family:'DM Mono',monospace;font-size:.75rem;"><?= sf($sz) ?></span>
      </td>
      <td style="padding:.5rem .85rem;border-bottom:1px solid var(--border);text-align:center;font-family:'DM Mono',monospace;color:var(--text-muted);">
        <?= $poOrd>0 ? $poOrd : '—' ?>
      </td>
      <td style="padding:.5rem .85rem;border-bottom:1px solid var(--border);text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:var(--text-primary);">
        <?= $qtyRec ?>
      </td>
      <td style="padding:.5rem .85rem;border-bottom:1px solid var(--border);text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:<?= $varCol ?>;">
        <?= ($poOrd>0||$qtyRec>0) ? ($diff>=0?'+'.$diff:$diff) : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:var(--surface-3);">
        <td colspan="2" style="padding:.55rem .85rem;font-weight:700;color:<?= $accent ?>;font-size:.82rem;">Total Received</td>
        <td style="padding:.55rem .85rem;text-align:center;font-family:'DM Mono',monospace;font-weight:800;font-size:.95rem;color:<?= $accent ?>;"><?= $grandTotal ?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>