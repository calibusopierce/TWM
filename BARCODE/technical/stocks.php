<?php
$pageTitle = 'Stocks';
$pageCrumb = 'Inventory';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';

$conn = getConnection();

$departments = getActiveDepartments($conn);

/*
 * Stocks are built from what has actually been RECEIVED, not from what
 * was ordered or registered on the Items page.
 *
 * Grouped by ItemDescription + Condition (Option A) so Brand New and
 * Used/Old stock of the same item appear as separate rows:
 *
 *   UPS | Brand New | 3
 *   UPS | Old       | 2
 *
 * Flow:
 *   PO created  → NOT in stocks yet
 *   PO received → TBL_Technical_Receiving_Items rows created → NOW in stocks
 *   Release issued → reduces available count for that item + condition
 *   Release returned → adds back to available count
 *   Transactions (Assign/Return/Retire) → further adjusts count
 *
 *   Available = TotalReceived - TotalNetReleased - TotalAssigned
 *               + TotalReturned - TotalRetired
 */
$sql = "
SELECT
    pi.ItemDescription                                  AS ItemName,
    pi.Category,
    pi.Brand,
    pi.Model,
    MIN(po.SupplierCode)                                AS SupplierCode,
    COALESCE(pi.Condition, 'Brand New')                 AS ItemCondition,
    COALESCE(SUM(ri.QtyReceived), 0)                   AS TotalReceived,

    -- Net released for THIS item AND this specific Condition only.
    -- Match directly on rli.ItemCondition (stored at release time) to avoid
    -- fan-out from re-joining through TBL_Technical_PO_Items.
    COALESCE((
        SELECT SUM(rli.QtyReleased - COALESCE(rli.QtyReturned, 0))
        FROM TBL_Technical_Release_Items rli
        JOIN TBL_Technical_Release rl ON rl.ReleaseID = rli.ReleaseID
        WHERE rli.ItemDescription = pi.ItemDescription
          AND COALESCE(rli.ItemCondition, 'Brand New') = COALESCE(pi.Condition, 'Brand New')
          AND rl.Status IN ('Open', 'Partial')
    ), 0)                                               AS TotalNetReleased,

    COALESCE((
        SELECT COUNT(*)
        FROM TBL_Technical_Transactions tx
        JOIN TBL_Technical_Items ti ON ti.ItemID = tx.ItemID
        WHERE tx.ActionType = 'assign'
          AND ti.ItemName = pi.ItemDescription
    ), 0)                                               AS TotalAssigned,

    COALESCE((
        SELECT COUNT(*)
        FROM TBL_Technical_Transactions tx
        JOIN TBL_Technical_Items ti ON ti.ItemID = tx.ItemID
        WHERE tx.ActionType = 'return'
          AND ti.ItemName = pi.ItemDescription
    ), 0)                                               AS TotalReturned,

    COALESCE((
        SELECT COUNT(*)
        FROM TBL_Technical_Transactions tx
        JOIN TBL_Technical_Items ti ON ti.ItemID = tx.ItemID
        WHERE tx.ActionType = 'retire'
          AND ti.ItemName = pi.ItemDescription
    ), 0)                                               AS TotalRetired

FROM TBL_Technical_Receiving_Items  ri
JOIN TBL_Technical_PO_Items         pi  ON pi.POItemID   = ri.POItemID
JOIN TBL_Technical_PO               po  ON po.POID       = pi.POID
JOIN TBL_Technical_Receiving        r   ON r.ReceivingID = ri.ReceivingID

WHERE pi.ItemDescription IS NOT NULL
  AND pi.ItemDescription <> ''

GROUP BY
    pi.ItemDescription,
    pi.Category,
    pi.Brand,
    pi.Model,
    pi.Condition

ORDER BY pi.ItemDescription ASC, pi.Condition ASC";

$stmt = sqlsrv_query($conn, $sql);
$stocks = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $received  = (int)$row['TotalReceived'];
        $assigned  = (int)$row['TotalAssigned'];
        $returned  = (int)$row['TotalReturned'];
        $retired   = (int)$row['TotalRetired'];
        $released  = (int)($row['TotalNetReleased'] ?? 0);
        $available = max(0, $received - $assigned + $returned - $retired - $released);

        $row['AvailableStocks'] = $available;
        $row['TotalReceived']   = $received;
        $stocks[] = $row;
    }
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="stocksSearch" placeholder="Search item, supplier, category...">
    </div>
  </div>

  <div class="table-wrap">
    <table class="ledger" id="stocksTable">
      <thead>
        <tr>
          <th>Item Name</th>
          <th>Supplier</th>
          <th>Description</th>
          <th>Condition</th>
          <th style="width:110px; text-align:right;">Total Received</th>
          <th style="width:120px; text-align:right;">Available Stocks</th>
          <th style="width:90px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($stocks)): ?>
        <tr>
          <td colspan="7">
            <div class="table-empty">
              No received stock yet. Stocks will appear here once a Purchase Order has been received.
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($stocks as $s):
          $desc = trim(($s['Category'] ?? '') . ' ' . ($s['Brand'] ?? '') . ' ' . ($s['Model'] ?? '')) ?: '—';
          $available = (int)$s['AvailableStocks'];
          $received  = (int)$s['TotalReceived'];
          $condition = $s['ItemCondition'] ?? '';
        ?>
        <tr>
          <td class="cell-strong"><?php echo htmlspecialchars($s['ItemName'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($s['SupplierCode'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($desc); ?></td>
          <td>
            <?php if ($condition): ?>
            <span class="condition-badge condition-<?php echo strtolower(str_replace(' ', '-', $condition)); ?>">
              <?php echo htmlspecialchars($condition); ?>
            </span>
            <?php else: ?>
            <span style="color:var(--ink-300);">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right; font-family:var(--font-mono); color:var(--ink-500);">
            <?php echo $received; ?>
          </td>
          <td style="text-align:right;">
            <span class="status <?php echo $available > 0 ? 'status-active' : 'status-inactive'; ?>"
                  style="justify-content:flex-end;">
              <?php echo $available; ?>
            </span>
          </td>
          <td>
            <button class="iconbtn stock-view-btn"
                    data-item-name="<?php echo htmlspecialchars($s['ItemName'] ?? ''); ?>"
                    title="View units">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-footer">
    <span id="stocksCount">Showing <?php echo count($stocks); ?> items</span>
    <span>TradewellDatabase · received from TBL_Technical_Receiving</span>
  </div>
</div>

<!-- View Unit Modal (unchanged) -->
<div class="modal-overlay" id="stockViewModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="stockViewModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="stockViewModalTitle">View Item</h3>
        <span>Inventory · Stocks</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="modal__body">
      <div id="unitPickerStep">
        <p class="hint" style="margin-bottom:10px;">Registered units for this item — pick one to see full details.</p>
        <div class="table-wrap">
          <table class="ledger">
            <thead>
              <tr>
                <th>Barcode</th>
                <th>Condition</th>
                <th>Status</th>
                <th>Department</th>
                <th>Assigned To</th>
              </tr>
            </thead>
            <tbody id="unitPickerBody"></tbody>
          </table>
        </div>
      </div>

      <div id="unitDetailStep" style="display:none;">
        <button type="button" class="btn btn-ghost" id="backToUnitListBtn" style="margin-bottom:14px;">&larr; Back to list</button>
        <div style="display:flex; gap:16px; align-items:flex-start; padding-bottom:18px; border-bottom:1px solid var(--line);">
          <img id="detailThumb" class="thumb" style="width:56px; height:56px;" src="" alt="">
          <div style="flex:1;">
            <div style="font-size:15px; font-weight:600;" id="detailName">—</div>
            <div style="font-size:12px; color: var(--ink-300); margin-top:2px;" id="detailMeta">—</div>
            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
              <span class="status status-active" id="detailStatusBadge">In Stock</span>
              <span class="cell-strong" id="detailBarcode" style="font-size:12px;"></span>
            </div>
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px;">
          <div class="field"><label>Department</label><div id="detailDepartment" style="font-size:13px; color: var(--ink-700);">—</div></div>
          <div class="field"><label>Assigned To</label><div id="detailAssignedTo" style="font-size:13px; color: var(--ink-700);">—</div></div>
          <div class="field"><label>Condition</label><div id="detailCondition" style="font-size:13px; color: var(--ink-700);">—</div></div>
          <div class="field"><label>Serial Number</label><div id="detailSerial" style="font-size:13px; color: var(--ink-700);">—</div></div>
        </div>
        <p class="hint" style="margin-top:16px;">To assign, transfer, repair, or retire this unit, use Asset Transactions and scan its barcode.</p>
      </div>
    </div>
  </div>
</div>

<script>
// search filter
document.getElementById('stocksSearch').addEventListener('input', function () {
  var term = this.value.trim().toLowerCase();
  var rows = document.querySelectorAll('#stocksTable tbody tr');
  var count = 0;
  rows.forEach(function (r) {
    var show = !term || r.textContent.toLowerCase().includes(term);
    r.style.display = show ? '' : 'none';
    if (show) count++;
  });
  document.getElementById('stocksCount').textContent = 'Showing ' + count + ' items';
});
</script>

<script src="../assets/js/stocks_view.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>