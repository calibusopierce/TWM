<?php
$pageTitle = 'Receiving';
$pageCrumb = 'Inventory';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';

$conn = getConnection();

// Only POs that have actually been marked Received show up here --
// this table is a log of completed receipts, not a place to receive
// from. Marking "Received" happens on the Purchase Order page.
$sql = "SELECT r.ReceivingID, r.ReceivingNumber, r.DateTimeInput,
               p.PONumber,
               (SELECT SUM(QtyReceived) FROM TBL_Technical_Receiving_Items ri WHERE ri.ReceivingID = r.ReceivingID) AS ItemCount
        FROM TBL_Technical_Receiving r
        LEFT JOIN TBL_Technical_PO p ON p.POID = r.POID
        ORDER BY r.ReceivingID DESC";
$stmt = sqlsrv_query($conn, $sql);
$history = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $history[] = $row;
    }
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="receivingSearch" placeholder="Search PO number...">
    </div>
    <span class="hint">To receive a PO, go to Purchase Order and click the checkmark on an open PO.</span>
  </div>

  <div class="table-wrap">
    <table class="ledger">
      <thead>
        <tr>
          <th>Date Received</th>
          <th>PO Code</th>
          <th>Count of Items</th>
          <th style="width:110px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
        <tr><td colspan="4"><div class="table-empty">No purchase orders have been received yet. POs not yet received won't show up here.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($history as $h): ?>
        <tr>
          <td><?php echo $h['DateTimeInput'] ? $h['DateTimeInput']->format('M j, Y g:i A') : ''; ?></td>
          <td class="cell-strong"><?php echo htmlspecialchars($h['PONumber'] ?? ''); ?></td>
          <td><?php echo (int)($h['ItemCount'] ?? 0); ?></td>
          <td>
            <div class="rowactions">
              <button class="iconbtn rcv-view-btn" data-id="<?php echo (int)$h['ReceivingID']; ?>" title="View">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <button class="iconbtn rcv-print-btn" data-id="<?php echo (int)$h['ReceivingID']; ?>" title="Print proof of receipt">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-footer">
    <span>Showing <?php echo count($history); ?> receiving records</span>
    <span>TradewellDatabase · dbo.TBL_Technical_Receiving</span>
  </div>
</div>

<script>
  document.querySelectorAll('.rcv-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.open('view_receiving.php?id=' + encodeURIComponent(btn.dataset.id), '_blank');
    });
  });
  document.querySelectorAll('.rcv-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.open('view_receiving.php?id=' + encodeURIComponent(btn.dataset.id) + '&autoprint=1', '_blank');
    });
  });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
