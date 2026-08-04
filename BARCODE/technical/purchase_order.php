<?php
$pageTitle = 'Purchase Order';
$pageCrumb = 'Inventory';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';
require_once __DIR__ . '/../includes/technical_lookups.php';

$conn = getConnection();

$departments = getActiveDepartments($conn);
$suppliers   = getActiveTechnicalSuppliers($conn);
$catalog     = getItemCatalog($conn);

$sql = "SELECT p.POID, p.PONumber, p.SupplierCode, p.Department, p.Status, p.DateTimeInput,
               (SELECT COUNT(*) FROM TBL_Technical_PO_Items pi WHERE pi.POID = p.POID) AS LineCount
        FROM TBL_Technical_PO p
        ORDER BY p.POID DESC";
$stmt = sqlsrv_query($conn, $sql);
$pos = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $pos[] = $row;
    }
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="poSearch" placeholder="Search PO number, supplier...">
    </div>
    <button class="btn btn-primary" id="createPoBtn" data-open-modal>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Create New PO
    </button>
  </div>

  <div class="table-wrap">
    <table class="ledger">
      <thead>
        <tr>
          <th>PO Number</th>
          <th>Supplier</th>
          <th>Department</th>
          <th>Lines</th>
          <th>Status</th>
          <th>Date</th>
          <th style="width:130px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pos)): ?>
        <tr><td colspan="7"><div class="table-empty">No purchase orders yet. Click "Create New PO" to make the first one.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($pos as $po): ?>
        <?php
          $statusClass = 'status-active';
          if ($po['Status'] === 'Cancelled') $statusClass = 'status-inactive';
          if ($po['Status'] === 'Open') $statusClass = 'status-inactive';
          $canEdit = ($po['Status'] === 'Open');
          $canReceive = in_array($po['Status'], ['Open', 'Partially Received'], true);
        ?>
        <tr>
          <td class="cell-strong"><?php echo htmlspecialchars($po['PONumber'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($po['SupplierCode'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($po['Department'] ?? ''); ?></td>
          <td><?php echo (int)$po['LineCount']; ?></td>
          <td><span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($po['Status'] ?? ''); ?></span></td>
          <td><?php echo $po['DateTimeInput'] ? $po['DateTimeInput']->format('M j, Y') : ''; ?></td>
          <td>
            <div class="rowactions">
              <?php if ($canReceive): ?>
              <button class="iconbtn po-receive-btn" data-po-id="<?php echo (int)$po['POID']; ?>" title="Mark as Received" style="color:#1a8a5f;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              </button>
              <?php endif; ?>
              <button class="iconbtn po-print-btn" data-po-id="<?php echo (int)$po['POID']; ?>" title="Print PO">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              </button>
              <?php if ($canEdit): ?>
              <button class="iconbtn po-edit-btn" data-po-id="<?php echo (int)$po['POID']; ?>" title="Edit PO">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <?php else: ?>
              <button class="iconbtn" disabled title="Can't edit once receiving has started">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.35"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
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
    <span>Showing <?php echo count($pos); ?> purchase orders</span>
    <span>TradewellDatabase · dbo.TBL_Technical_PO</span>
  </div>
</div>

<!-- ============ Create / Edit PO Modal ============ -->
<div class="modal-overlay" id="poModal">
  <div class="modal modal--xwide" role="dialog" aria-modal="true" aria-labelledby="poModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="poModalTitle">Create New Purchase Order</h3>
        <span>Inventory · Purchase Order</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <form id="poForm">
      <input type="hidden" id="poEditId" value="">
      <div class="modal__body">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
          <div class="field">
            <label for="poCodeDisplay">P.O. Code</label>
            <input type="text" id="poCodeDisplay" value="Auto-generated on save" disabled>
          </div>
          <div class="field">
            <label for="poSupplier">Supplier</label>
            <select id="poSupplier" required>
              <option value="">Select supplier</option>
              <?php foreach ($suppliers as $s): ?>
              <option value="<?php echo htmlspecialchars($s['SupplierCode'] ?? ''); ?>">
                <?php echo htmlspecialchars(($s['SupplierCode'] ?? '') . ' — ' . ($s['SupplierName'] ?? '')); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field" style="margin-top:14px;">
          <label for="poDepartment">Department</label>
          <select id="poDepartment" required>
            <option value="">Select department</option>
            <?php foreach ($departments as $d):
              $deptValue = $d['Department'] ?: ($d['DepartmentCode'] ?? '');
              $deptLabel = $d['DepartmentName'] ?: $deptValue;
            ?>
            <option value="<?php echo htmlspecialchars($deptValue); ?>"><?php echo htmlspecialchars($deptLabel); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field-group__label" style="margin: 18px 0 10px;">Item Form</div>

        <datalist id="poItemCatalog">
          <?php foreach ($catalog as $c): ?>
          <option value="<?php echo htmlspecialchars($c['ItemName'] ?? ''); ?>"></option>
          <?php endforeach; ?>
        </datalist>

        <div style="display:grid; grid-template-columns: repeat(5, 1fr); gap: 10px;">
          <div class="field">
            <label for="poItemBarcode">Scan Barcode</label>
            <input type="text" id="poItemBarcode" placeholder="Scan to auto-fill..." autocomplete="off">
          </div>
          <div class="field">
            <label for="poItemName">Item</label>
            <input type="text" id="poItemName" list="poItemCatalog" placeholder="Type or pick an existing item" autocomplete="off">
          </div>
          <div class="field">
            <label for="poItemCondition">Condition</label>
            <select id="poItemCondition">
              <option value="Brand New" selected>Brand New</option>
              <option value="Used">Used</option>
              <option value="Old">Old</option>
              <option value="Refurbished">Refurbished</option>
            </select>
          </div>
          <div class="field">
            <label for="poItemUnit">Unit</label>
            <input type="text" id="poItemUnit" placeholder="e.g. PCS">
          </div>
          <div class="field">
            <label for="poItemQty">Qty</label>
            <input type="number" id="poItemQty" min="1" placeholder="0">
          </div>
        </div>
        <div style="margin-top:10px;">
          <button type="button" class="btn btn-primary" id="addPoLineBtn">+ Add to List</button>
          <span class="hint" style="margin-left:8px;">Cost is pulled automatically from what this item was registered at.</span>
        </div>

        <div class="table-wrap" style="margin-top:16px;">
          <table class="ledger" id="poLinesTable">
            <thead>
              <tr>
                <th style="width:50px;"></th>
                <th style="width:60px;">Qty</th>
                <th style="width:90px;">Unit</th>
                <th>Item</th>
                <th style="width:120px;">Item Barcode</th>
                <th style="width:110px;">Condition</th>
                <th style="width:90px;">Cost</th>
                <th style="width:100px;">Total</th>
              </tr>
            </thead>
            <tbody id="poLinesBody">
              <tr id="poLinesEmptyRow"><td colspan="7"><div class="table-empty">No lines added yet.</div></td></tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="6" style="text-align:right; font-weight:600;">Sub Total</td>
                <td id="poSubTotalDisplay">0.00</td>
              </tr>
              <tr>
                <td colspan="6" style="text-align:right; font-weight:600;">
                  Discount <input type="number" id="poDiscountInput" min="0" max="100" step="0.01" value="0" style="width:60px; display:inline-block;">%
                </td>
                <td id="poDiscountDisplay">0.00</td>
              </tr>
              <tr>
                <td colspan="6" style="text-align:right; font-weight:600;">
                  Tax <input type="number" id="poTaxInput" min="0" max="100" step="0.01" value="0" style="width:60px; display:inline-block;">%
                </td>
                <td id="poTaxDisplay">0.00</td>
              </tr>
              <tr>
                <td colspan="6" style="text-align:right; font-weight:700;">Total</td>
                <td id="poTotalDisplay" style="font-weight:700;">0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div class="field" style="margin-top:16px;">
          <label for="poRemarks">Remarks</label>
          <textarea id="poRemarks" rows="2" placeholder="Optional notes"></textarea>
        </div>
      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary" id="poSaveBtn">Save Purchase Order</button>
      </div>
    </form>
  </div>
</div>

<script>
  // Item catalog with cost/category/brand/model, for auto-fill on pick or scan.
  var PO_ITEM_CATALOG = <?php echo json_encode($catalog); ?>;
</script>
<script src="../assets/js/po_form.js"></script>

<!-- ============ Confirm Received Modal ============ -->
<div class="modal-overlay" id="poReceiveModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="poReceiveModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="poReceiveModalTitle">Confirm Received</h3>
        <span>Inventory · Purchase Order</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="modal__body">
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
        <div class="field">
          <label>P.O. Code</label>
          <div id="rcvPoCode" style="font-size:14px; font-weight:600;">—</div>
        </div>
        <div class="field">
          <label>Supplier</label>
          <div id="rcvSupplier" style="font-size:14px; font-weight:600;">—</div>
        </div>
      </div>

      <div class="table-wrap" style="margin-top:14px;">
        <table class="ledger">
          <thead>
            <tr>
              <th style="width:60px;">Qty</th>
              <th style="width:90px;">Unit</th>
              <th>Item</th>
              <th style="width:90px;">Cost</th>
              <th style="width:100px;">Total</th>
            </tr>
          </thead>
          <tbody id="rcvLinesBody"></tbody>
        </table>
      </div>

      <p class="hint" style="margin-top:12px;">This will mark the PO as fully received. No barcodes are captured here — register barcodes for the physical items separately on the Items page.</p>
    </div>

    <div class="modal__foot">
      <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
      <button type="button" class="btn btn-primary" id="confirmReceivedBtn">Received</button>
    </div>
  </div>
</div>

<script src="../assets/js/po_receive.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>