<?php
$pageTitle = 'Release';
$pageCrumb = 'Inventory';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';

$conn = getConnection();
$departments = getActiveDepartments($conn);

// List of releases — newest first
$sql = "SELECT r.ReleaseID, r.ReleaseNumber, r.Department, r.ReleasedTo,
               r.Status, r.DateTimeInput,
               (SELECT COUNT(*) FROM TBL_Technical_Release_Items ri WHERE ri.ReleaseID = r.ReleaseID) AS LineCount,
               (SELECT SUM(ri.QtyReleased) FROM TBL_Technical_Release_Items ri WHERE ri.ReleaseID = r.ReleaseID) AS TotalQty
        FROM TBL_Technical_Release r
        ORDER BY r.ReleaseID DESC";
$stmt = sqlsrv_query($conn, $sql);
$releases = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $releases[] = $row;
    }
}

// Available stock for the Create Release item picker (dropdown fallback
// for when there's no scanner handy) -- one row per PO line (POItemID)
// that still has stock left, same figure the barcode scan looks up.
$stockSql = "
SELECT
    pi.POItemID,
    pi.ItemBarcode,
    pi.ItemDescription,
    COALESCE(pi.Condition, 'Brand New')                 AS ItemCondition,
    po.PONumber,
    COALESCE((SELECT SUM(ri.QtyReceived) FROM TBL_Technical_Receiving_Items ri WHERE ri.POItemID = pi.POItemID), 0)
    - COALESCE((
        SELECT SUM(rli.QtyReleased - COALESCE(rli.QtyReturned, 0))
        FROM TBL_Technical_Release_Items rli
        JOIN TBL_Technical_Release rl ON rl.ReleaseID = rli.ReleaseID
        WHERE rli.POItemID = pi.POItemID
          AND rl.Status IN ('Open','Partial')
    ), 0) AS AvailableQty
FROM TBL_Technical_PO_Items pi
JOIN TBL_Technical_PO po ON po.POID = pi.POID
WHERE pi.ItemBarcode IS NOT NULL
GROUP BY pi.POItemID, pi.ItemBarcode, pi.ItemDescription, pi.Condition, po.PONumber
HAVING COALESCE((SELECT SUM(ri.QtyReceived) FROM TBL_Technical_Receiving_Items ri WHERE ri.POItemID = pi.POItemID), 0)
       - COALESCE((
           SELECT SUM(rli.QtyReleased - COALESCE(rli.QtyReturned, 0))
           FROM TBL_Technical_Release_Items rli
           JOIN TBL_Technical_Release rl ON rl.ReleaseID = rli.ReleaseID
           WHERE rli.POItemID = pi.POItemID
             AND rl.Status IN ('Open','Partial')
       ), 0) > 0
ORDER BY pi.ItemDescription ASC, pi.Condition ASC";

$stockStmt = sqlsrv_query($conn, $stockSql);
$availableStock = [];
if ($stockStmt !== false) {
    while ($row = sqlsrv_fetch_array($stockStmt, SQLSRV_FETCH_ASSOC)) {
        $availableStock[] = $row;
    }
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<!-- Release List -->
<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="releaseSearch" placeholder="Search release number, department, person...">
    </div>
    <button class="btn btn-primary" id="createReleaseBtn">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Create New Release
    </button>
  </div>

  <div class="table-wrap">
    <table class="ledger" id="releaseTable">
      <thead>
        <tr>
          <th>Release No.</th>
          <th>Department</th>
          <th>Released To</th>
          <th style="width:70px; text-align:center;">Items</th>
          <th style="width:90px; text-align:center;">Total Qty</th>
          <th>Status</th>
          <th>Date</th>
          <th style="width:110px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($releases)): ?>
        <tr>
          <td colspan="8">
            <div class="table-empty">No releases yet. Click "Create New Release" to issue items from stock.</div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($releases as $rel):
          $statusClass = 'status-inactive';
          if ($rel['Status'] === 'Open')     $statusClass = 'release-status-open';
          if ($rel['Status'] === 'Partial')  $statusClass = 'release-status-partial';
          if ($rel['Status'] === 'Returned') $statusClass = 'release-status-returned';
          $canReturn = in_array($rel['Status'], ['Open', 'Partial'], true);
        ?>
        <tr>
          <td class="cell-strong"><?php echo htmlspecialchars($rel['ReleaseNumber'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($rel['Department'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($rel['ReleasedTo'] ?? ''); ?></td>
          <td style="text-align:center;"><?php echo (int)($rel['LineCount'] ?? 0); ?></td>
          <td style="text-align:center; font-family:var(--font-mono);"><?php echo (int)($rel['TotalQty'] ?? 0); ?></td>
          <td>
            <span class="status <?php echo $statusClass; ?>">
              <?php echo htmlspecialchars($rel['Status'] ?? ''); ?>
            </span>
          </td>
          <td class="cell-date">
            <?php echo $rel['DateTimeInput'] instanceof DateTime
              ? $rel['DateTimeInput']->format('M j, Y') : ''; ?>
          </td>
          <td>
            <div class="rowactions">
              <button class="iconbtn release-view-btn"
                      data-id="<?php echo (int)$rel['ReleaseID']; ?>"
                      title="View release">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
              <?php if ($canReturn): ?>
              <button class="iconbtn release-return-btn"
                      data-id="<?php echo (int)$rel['ReleaseID']; ?>"
                      title="Return items"
                      style="color:var(--accent);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-footer">
    <span id="releaseCount">Showing <?php echo count($releases); ?> releases</span>
    <span>TradewellDatabase · TBL_Technical_Release</span>
  </div>
</div>

<!-- ============ Create New Release Modal ============ -->
<div class="modal-overlay" id="releaseModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="releaseModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="releaseModalTitle">Create New Release</h3>
        <span>Inventory · Release</span>
      </div>
      <button class="modal__close" id="closeReleaseModal" aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <form id="releaseForm">
      <div class="modal__body">

        <!-- Header fields -->
        <div class="field-row" style="margin-bottom:14px;">
          <div class="field">
            <label for="relDepartment">Department</label>
            <select id="relDepartment" required>
              <option value="">Select department</option>
              <?php foreach ($departments as $d):
                $dv = $d['Department'] ?: ($d['DepartmentCode'] ?? '');
                $dl = $d['DepartmentName'] ?: $dv;
              ?>
              <option value="<?php echo htmlspecialchars($dv); ?>"><?php echo htmlspecialchars($dl); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="relReleasedTo">Released To</label>
            <input type="text" id="relReleasedTo" placeholder="e.g. J. Dela Cruz" required>
          </div>
        </div>

        <!-- Item picker row -->
        <div class="field-group__label" style="margin-bottom:10px;">Add Items</div>
        <div style="display:grid; grid-template-columns: 200px 1fr 100px 1fr auto; gap:10px; align-items:flex-end; margin-bottom:6px;">
          <div class="field">
            <label for="relScanBarcode">Scan Barcode</label>
            <input type="text" id="relScanBarcode" placeholder="Scan the PO item barcode..." autocomplete="off">
          </div>
          <div class="field">
            <label for="relItemName">Item</label>
            <select id="relItemName">
              <option value="">Select item</option>
              <?php foreach ($availableStock as $s):
                $condition = htmlspecialchars($s['ItemCondition'] ?? 'Brand New');
                $available = (int)$s['AvailableQty'];
              ?>
              <option value="<?php echo (int)$s['POItemID']; ?>"
                      data-po-item-id="<?php echo (int)$s['POItemID']; ?>"
                      data-item-barcode="<?php echo htmlspecialchars($s['ItemBarcode'] ?? ''); ?>"
                      data-description="<?php echo htmlspecialchars($s['ItemDescription'] ?? ''); ?>"
                      data-condition="<?php echo $condition; ?>"
                      data-available="<?php echo $available; ?>"
                      data-ponumber="<?php echo htmlspecialchars($s['PONumber'] ?? ''); ?>">
                <?php echo htmlspecialchars($s['ItemDescription'] ?? ''); ?> — <?php echo $condition; ?>
                (<?php echo $available; ?> available)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="relQty">Quantity</label>
            <input type="number" id="relQty" min="1" placeholder="0">
          </div>
          <div class="field">
            <label for="relLineRemarks">Remarks</label>
            <input type="text" id="relLineRemarks" placeholder="Optional">
          </div>
          <div class="field">
            <label>&nbsp;</label>
            <button type="button" class="btn btn-primary" id="addReleaseLineBtn">+ Add</button>
          </div>
        </div>
        <p class="hint" id="relAvailHint" style="margin-bottom:14px;">Scan a barcode, or pick an item from the list if no scanner is handy.</p>

        <!-- Lines table -->
        <div class="table-wrap">
          <table class="ledger" id="relLinesTable">
            <thead>
              <tr>
                <th style="width:40px;"></th>
                <th>Item</th>
                <th style="width:110px;">Condition</th>
                <th style="width:100px; text-align:center;">Qty Released</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody id="relLinesBody">
              <tr id="relLinesEmptyRow">
                <td colspan="4"><div class="table-empty">No items added yet.</div></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="field" style="margin-top:16px;">
          <label for="relRemarks">Overall Remarks</label>
          <textarea id="relRemarks" rows="2" placeholder="Optional notes about this release"></textarea>
        </div>

      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" id="cancelReleaseBtn">Cancel</button>
        <button type="submit" class="btn btn-primary" id="relSaveBtn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
          Save Release
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============ Return Modal ============ -->
<div class="modal-overlay" id="returnModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="returnModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="returnModalTitle">Return Items</h3>
        <span>Inventory · Release</span>
      </div>
      <button class="modal__close" id="closeReturnModal" aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="modal__body">
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:16px;">
        <div class="field"><label>Release No.</label><div id="retReleaseNumber" style="font-size:14px; font-weight:600;">—</div></div>
        <div class="field"><label>Department</label><div id="retDepartment" style="font-size:14px;">—</div></div>
        <div class="field"><label>Released To</label><div id="retReleasedTo" style="font-size:14px;">—</div></div>
      </div>

      <div class="table-wrap">
        <table class="ledger">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width:100px; text-align:center;">Released</th>
              <th style="width:100px; text-align:center;">Already Returned</th>
              <th style="width:110px; text-align:center;">Remaining</th>
              <th style="width:130px;">Return Qty</th>
            </tr>
          </thead>
          <tbody id="retLinesBody"></tbody>
        </table>
      </div>
    </div>

    <div class="modal__foot">
      <button type="button" class="btn btn-ghost" id="cancelReturnBtn">Cancel</button>
      <button type="button" class="btn btn-primary" id="confirmReturnBtn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>
        Confirm Return
      </button>
    </div>
  </div>
</div>

<!-- ============ View Modal ============ -->
<div class="modal-overlay" id="viewModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="viewModalTitle">Release Details</h3>
        <span>Inventory · Release</span>
      </div>
      <button class="modal__close" id="closeViewModal" aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal__body">
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:16px;">
        <div class="field"><label>Release No.</label><div id="viewReleaseNumber" style="font-size:14px; font-weight:600;">—</div></div>
        <div class="field"><label>Department</label><div id="viewDepartment" style="font-size:14px;">—</div></div>
        <div class="field"><label>Released To</label><div id="viewReleasedTo" style="font-size:14px;">—</div></div>
        <div class="field"><label>Date</label><div id="viewDate" style="font-size:14px;">—</div></div>
        <div class="field"><label>Status</label><div id="viewStatus" style="font-size:14px;">—</div></div>
        <div class="field"><label>Remarks</label><div id="viewRemarks" style="font-size:13px; color:var(--ink-500);">—</div></div>
      </div>
      <div class="table-wrap">
        <table class="ledger">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width:110px; text-align:center;">Qty Released</th>
              <th style="width:110px; text-align:center;">Qty Returned</th>
              <th style="width:110px; text-align:center;">Outstanding</th>
            </tr>
          </thead>
          <tbody id="viewLinesBody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal__foot">
      <button type="button" class="btn btn-ghost" id="closeViewModalBtn">Close</button>
    </div>
  </div>
</div>

<script src="../assets/js/release.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>