<?php
$pageTitle = 'List of Items';
$pageCrumb = 'Maintenance';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';
require_once __DIR__ . '/../includes/technical_lookups.php';

$conn = getConnection();

$departments = getActiveDepartments($conn);
$suppliers   = getActiveTechnicalSuppliers($conn);
$categories  = getActiveTechnicalCategories($conn);

// Every registered asset, newest first. Active IS NULL is included so
// nothing gets silently hidden if that flag was ever left unset.
$sql = "SELECT ItemID, Barcode, ItemName, Category, SupplierCode, Status,
               CASE WHEN Image IS NOT NULL THEN 1 ELSE 0 END AS HasImage
        FROM TBL_Technical_Items
        WHERE Active = 1 OR Active IS NULL
        ORDER BY ItemID DESC";

$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]));
}

$items = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $items[] = $row;
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="itemSearch" placeholder="Search items, barcodes, serials...">
    </div>
    <button class="btn btn-primary" data-open-modal>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Create New
    </button>
  </div>

  <div class="table-wrap">
    <table class="ledger" id="itemTable">
      <thead>
        <tr>
          <th style="width:48px;">ID</th>
          <th style="width:72px;">Image</th>
          <th>Item Name</th>
          <th style="width:150px;">Barcode</th>
          <th>Category</th>
          <th>Supplier Code</th>
          <th>Status</th>
          <th style="width:110px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
        <tr>
          <td colspan="8">
            <div class="table-empty">No items yet. Click "Create New" to register the first one.</div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($items as $it): ?>
        <?php $isActive = !empty($it['Status']); ?>
        <tr>
          <td class="cell-id"><?php echo str_pad($it['ItemID'], 2, '0', STR_PAD_LEFT); ?></td>
          <td>
            <?php if (!empty($it['HasImage'])): ?>
              <img class="thumb" src="image_item.php?id=<?php echo (int)$it['ItemID']; ?>" alt="">
            <?php else: ?>
              <div class="thumb thumb-placeholder" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.8"/><path d="m21 15-4.5-4.5L6 21"/></svg>
              </div>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($it['ItemName'] ?? ''); ?></td>
          <td>
            <?php if (!empty($it['Barcode'])): ?>
              <svg class="js-barcode" data-code="<?php echo htmlspecialchars($it['Barcode']); ?>"></svg>
            <?php else: ?>
              <span class="hint">—</span>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($it['Category'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($it['SupplierCode'] ?? ''); ?></td>
          <td>
            <span class="status <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
              <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
          </td>
          <td>
            <div class="rowactions">
              <button class="iconbtn" title="Edit item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <button class="iconbtn" title="View item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="table-footer">
    <span>Showing <?php echo count($items); ?> of <?php echo count($items); ?> items</span>
    <span>TradewellDatabase · dbo.TBL_Technical_Items</span>
  </div>
</div>

<!-- ============ Register New Item Modal ============ -->
<div class="modal-overlay" id="itemModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="itemModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="itemModalTitle">Register New Item</h3>
        <span>Maintenance · Items</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <form id="techItemForm" method="post" enctype="multipart/form-data">
      <div class="modal__body">
        <div class="field">
          <label for="itemImage">Image</label>
          <input type="file" id="itemImage" name="image" accept="image/*">
          <span class="hint">PNG or JPG, used as the item's thumbnail in the list.</span>
        </div>

        <div class="field">
          <label for="itemBarcode">Barcode</label>
          <input type="text" id="itemBarcode" name="barcode" placeholder="Auto-generating..." readonly required>
          <span class="hint">Auto-generated in the TWM888-XX series — this will be the item's key for future transactions.</span>
        </div>

        <div class="field">
          <label for="itemName">Item Name</label>
          <input type="text" id="itemName" name="item_name" placeholder="e.g. Dell OptiPlex 3090 Desktop" required>
        </div>

        <div class="field">
          <label for="itemCategory">Category</label>
          <select id="itemCategory" name="category">
            <option value="">Select category</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo htmlspecialchars($c['CategoryName'] ?? ''); ?>"><?php echo htmlspecialchars($c['CategoryName'] ?? ''); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="itemBrand">Brand</label>
          <input type="text" id="itemBrand" name="brand" placeholder="e.g. Dell, HP, Epson">
        </div>

        <div class="field">
          <label for="itemModel">Model</label>
          <input type="text" id="itemModel" name="model" placeholder="e.g. OptiPlex 3090">
        </div>

        <div class="field">
          <label for="itemSupplier">Supplier</label>
          <select id="itemSupplier" name="supplier_code">
            <option value="">Select supplier</option>
            <?php foreach ($suppliers as $s): ?>
            <option value="<?php echo htmlspecialchars($s['SupplierCode'] ?? ''); ?>">
              <?php echo htmlspecialchars(($s['SupplierCode'] ?? '') . ' — ' . ($s['SupplierName'] ?? '')); ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label for="itemCost">Cost</label>
          <input type="number" id="itemCost" name="cost" min="0" step="0.01" placeholder="0.00">
        </div>

        <div class="field">
          <label for="itemDateAcquired">Date Acquired</label>
          <input type="date" id="itemDateAcquired" name="date_acquired">
        </div>

        <div class="field">
          <label for="itemRemarks">Remarks</label>
          <textarea id="itemRemarks" name="remarks" rows="2" placeholder="Optional notes"></textarea>
        </div>

        <div class="field">
          <label for="itemStatus">Status</label>
          <select id="itemStatus" name="status">
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Item</button>
      </div>
    </form>
  </div>
</div>

<!-- Renders real, scannable Code128 barcodes for each item in the list -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  // Render every barcode image in the items table
  document.querySelectorAll('svg.js-barcode').forEach(function (el) {
    var code = el.dataset.code;
    if (!code || typeof JsBarcode === 'undefined') return;
    try {
      JsBarcode(el, code, {
        format: 'CODE128',
        width: 1.4,
        height: 32,
        fontSize: 11,
        margin: 0,
        displayValue: true
      });
    } catch (e) {
      console.error('Could not render barcode for', code, e);
    }
  });

  // Auto-fill the next TWM888-XX barcode when the Register New Item modal opens
  var openBtn = document.querySelector('[data-open-modal]');
  var barcodeField = document.getElementById('itemBarcode');
  if (openBtn && barcodeField) {
    openBtn.addEventListener('click', function () {
      barcodeField.value = 'Generating...';
      fetch('get_next_barcode.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
          barcodeField.value = data.error ? '' : (data.barcode || '');
        })
        .catch(function () {
          barcodeField.value = '';
        });
    });
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>