<?php
// uniform-po-items.php — Returns HTML snippet of PO items (called via fetch)
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

// ── RBAC gate ────────────────────────────────────────────────
$pdo_rbac = new PDO(
    "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
rbac_gate($pdo_rbac, 'uniform_inventory');

function rqItems($conn2,$sql,$p=[]) {
    $stmt = empty($p) ? sqlsrv_query($conn2,$sql) : sqlsrv_query($conn2,$sql,$p);
    if (!$stmt) return [];
    $rows=[];
    while ($r=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $rows[]=$r;
    sqlsrv_free_stmt($stmt);
    return $rows;
}
function safeI($s) { return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

$poid  = intval($_GET['poid']??0);
$sizes = ['XS','S','M','L','XL','XXL','XXXL','4XL'];

if(!$poid){ echo '<p style="color:#dc2626;font-size:.82rem;">Invalid PO ID.</p>'; exit; }

$items = rqItems($conn2,
    "SELECT * FROM [dbo].[UniformPOItems] WHERE POID=? ORDER BY UniformType,
     CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4
               WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END",
    [$poid]);

if(empty($items)){ echo '<p style="color:var(--text-muted);font-size:.82rem;text-align:center;padding:1.5rem 0;"><i class="bi bi-inbox"></i> No items found for this PO.</p>'; exit; }

// Group by type
$grouped=[];
foreach($items as $it) $grouped[$it['UniformType']][] = $it;

$uLabels=['TSHIRT'=>'T-Shirt (Logistics)','POLOSHIRT'=>'Polo Shirt (Office/Sales)'];
?>
<style>
.poi-tbl{width:100%;border-collapse:collapse;font-size:.8rem;margin-bottom:1rem;}
.poi-tbl thead th{padding:.4rem .75rem;text-align:center;color:#fff;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#1e3a5f;border:1px solid #15305a;}
.poi-tbl thead th:first-child{text-align:left;}
.poi-tbl tbody td{padding:.4rem .75rem;border:1px solid #dde3ee;text-align:center;color:#333;}
.poi-tbl tbody td:first-child{text-align:left;font-weight:700;font-family:'DM Mono',monospace;}
.poi-tbl tr:nth-child(even) td{background:#f5f8fc;}
.poi-tbl .poi-total td{background:#dce6f4;font-weight:800;color:#1e3a5f;}
.poi-zero{color:#bbb;}
.poi-type-hdr{font-family:'Sora',sans-serif;font-size:.82rem;font-weight:800;color:#1e3a5f;background:#e8eef8;border:1.5px solid #c5d4ea;padding:.4rem .75rem;border-radius:6px 6px 0 0;margin-top:.75rem;display:flex;align-items:center;gap:.4rem;}
</style>

<?php foreach($grouped as $type=>$rows):
  $itemsBySize=[];
  foreach($rows as $r) $itemsBySize[$r['Size']]=$r;
  $tReq=0;$tAdd=0;$tTotal=0;
?>
<div class="poi-type-hdr">
  <?= $type==='TSHIRT'?'👕':'👔' ?> <?= safeI($uLabels[$type]??$type) ?>
</div>
<table class="poi-tbl">
  <thead>
    <tr>
      <th style="width:110px;">Category</th>
      <?php foreach($sizes as $sz): ?><th><?= $sz ?></th><?php endforeach; ?>
      <th>Total</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Requested</td>
      <?php $rowT=0; foreach($sizes as $sz): $v=intval($itemsBySize[$sz]['Requested']??0); $rowT+=$v; $tReq+=$v; ?>
      <td class="<?= $v?'':'poi-zero' ?>"><?= $v?$v:'—' ?></td>
      <?php endforeach; ?>
      <td style="font-weight:800;"><?= $rowT ?></td>
    </tr>
    <tr>
      <td>Additional</td>
      <?php $rowT=0; foreach($sizes as $sz): $v=intval($itemsBySize[$sz]['Additional']??0); $rowT+=$v; $tAdd+=$v; ?>
      <td class="<?= $v?'':'poi-zero' ?>"><?= $v?$v:'—' ?></td>
      <?php endforeach; ?>
      <td style="font-weight:800;"><?= $rowT ?></td>
    </tr>
    <tr class="poi-total">
      <td>TOTAL PIECES</td>
      <?php foreach($sizes as $sz):
        $t=intval($itemsBySize[$sz]['Requested']??0)+intval($itemsBySize[$sz]['Additional']??0);
        $tTotal+=$t;
      ?>
      <td><?= $t?$t:'—' ?></td>
      <?php endforeach; ?>
      <td><?= $tTotal ?></td>
    </tr>
  </tbody>
</table>
<?php endforeach; ?>

<div style="display:flex;justify-content:flex-end;gap:16px;padding:.6rem .75rem;background:#e8eef8;border:1.5px solid #c5d4ea;border-radius:6px;margin-top:.25rem;font-size:.8rem;">
  <?php
  $allReq=0;$allAdd=0;$allTotal=0;
  foreach($items as $it){ $allReq+=intval($it['Requested']); $allAdd+=intval($it['Additional']); $allTotal+=intval($it['Requested'])+intval($it['Additional']); }
  ?>
  <span style="color:#555;">Total Requested: <strong style="color:#1e3a5f;"><?= number_format($allReq) ?></strong></span>
  <span style="color:#555;">Total Additional: <strong style="color:#1e3a5f;"><?= number_format($allAdd) ?></strong></span>
  <span style="color:#555;">Grand Total: <strong style="color:#1e3a5f;font-size:.9rem;"><?= number_format($allTotal) ?> pcs</strong></span>
</div>