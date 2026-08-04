<?php
$pageTitle = 'List of Items';
$pageCrumb = 'Maintenance';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';
require_once __DIR__ . '/../includes/item_lookups.php';

$conn = getConnection();

// Pull items that need barcodes — or already have some — from Tbl_Item_Products.
// Active IS NULL is included on purpose: legacy rows imported before this
// column was consistently set shouldn't be hidden from the list.
$sql = "SELECT ItemID, ItemCode, ItemDescription, Department,
               BarcodeCs, BarcodeBg, BarcodePc,
               Active, Status,
               CASE WHEN Image IS NOT NULL THEN 1 ELSE 0 END AS HasImage
        FROM Tbl_Item_Products
        WHERE Active = 1 OR Active IS NULL
        ORDER BY ItemID DESC";

$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]));
}

$items = [];
$totalItems = 0;
$barcodeComplete = 0;

// Some legacy rows have the barcode columns literally set to "0" instead of
// NULL/empty. PHP's empty("0") already treats that as blank, but the raw
// value was still being sent to the browser as-is — and JS treats the
// string "0" as truthy, so the scan panel was pre-filling fields with "0"
// and new scans were appending onto it instead of replacing it. Normalize
// here, once, so nothing downstream (table, JSON, scan panel) ever sees it.
function normalizeBarcode($v) {
    $v = trim((string)($v ?? ''));
    return ($v === '' || $v === '0') ? null : $v;
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $totalItems++;
    $row['BarcodeCs'] = normalizeBarcode($row['BarcodeCs']);
    $row['BarcodeBg'] = normalizeBarcode($row['BarcodeBg']);
    $row['BarcodePc'] = normalizeBarcode($row['BarcodePc']);
    $hasAny = $row['BarcodeCs'] !== null || $row['BarcodeBg'] !== null || $row['BarcodePc'] !== null;
    if ($hasAny) $barcodeComplete++;
    $items[] = $row;
}

$departments = getActiveDepartments($conn);
$suppliers = getActiveSuppliers($conn);
$activeDepartment = 'Monde';
$activeDeptPrefix = 'MOND-';

closeConnection($conn);

// Barcode progress
$barcodeRemaining = $totalItems - $barcodeComplete;
$progressPct = $totalItems > 0 ? round(($barcodeComplete / $totalItems) * 100) : 0;

// Pass items as JSON for the scan panel JS
$itemsJson = json_encode(array_values($items));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> · Tradewell Inventory</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app app--<?php echo htmlspecialchars($inventoryType); ?>" id="appShell">

  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="main">
    <?php include __DIR__ . '/../includes/header_inner.php'; ?>

    <main class="content">

<!-- Barcode progress banner -->
<?php if ($totalItems > 0): ?>
<div class="bc-banner">
  <div class="bc-banner__left">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h2M7 5h1M11 5h3M17 5h1M20 5h1M3 9h1M7 9h4M14 9h2M18 9h3M3 13h2M8 13h1M11 13h4M17 13h1M20 13h1M3 17h3M9 17h1M13 17h3M18 17h3"/><rect x="1" y="3" width="22" height="16" rx="2"/></svg>
    <span>Barcode Registration Progress</span>
  </div>
  <div class="bc-banner__bar">
    <div class="bc-banner__track">
      <div class="bc-banner__fill" style="width:<?php echo $progressPct; ?>%"></div>
    </div>
    <span class="bc-banner__pct"><?php echo $progressPct; ?>%</span>
  </div>
  <div class="bc-banner__right">
    <span class="bc-stat bc-stat--done"><?php echo $barcodeComplete; ?> complete</span>
    <span class="bc-stat bc-stat--pending"><?php echo $barcodeRemaining; ?> pending</span>
    <button class="btn btn-primary btn-scan-all" id="btnScanAll">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h2M7 5h1M11 5h3M17 5h1M20 5h1M3 9h1M7 9h4M14 9h2M18 9h3M3 13h2M8 13h1M11 13h4M17 13h1M20 13h1M3 17h3M9 17h1M13 17h3M18 17h3"/></svg>
      Scan All (Quick Mode)
    </button>
  </div>
</div>
<?php endif; ?>

<div class="items-layout" id="itemsLayout">

  <!-- Items table card -->
  <div class="card" id="itemsCard">
    <div class="toolbar">
      <div class="searchbox">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="text" id="itemSearch" placeholder="Search items, codes...">
      </div>
      <div class="toolbar__group" style="display:flex;gap:10px;align-items:center;">
        <label class="bc-filter-wrap">
          <select id="barcodeFilter">
            <option value="all">All items</option>
            <option value="pending">Pending (no barcode yet)</option>
            <option value="complete">Has barcode</option>
          </select>
        </label>
        <button class="btn btn-primary" data-open-modal>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
          Create New
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="ledger" id="itemTable">
        <thead>
          <tr>
            <th style="width:56px;">ID</th>
            <th style="width:68px;">Image</th>
            <th>Item Code</th>
            <th>Item Name</th>
            <th>Department</th>
            <th style="width:110px;">Barcodes</th>
            <th style="width:120px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
          <tr>
            <td colspan="7">
              <div class="table-empty">No items yet. Click "Create New" to add the first one.</div>
            </td>
          </tr>
          <?php endif; ?>
          <?php foreach ($items as $it):
            $csHas  = !empty($it['BarcodeCs']);
            $bgHas  = !empty($it['BarcodeBg']);
            $pcHas  = !empty($it['BarcodePc']);
            $hasAny = $csHas || $bgHas || $pcHas;
          ?>
          <tr data-item-id="<?php echo (int)$it['ItemID']; ?>"
              data-item-code="<?php echo htmlspecialchars($it['ItemCode'] ?? ''); ?>"
              data-item-name="<?php echo htmlspecialchars($it['ItemDescription'] ?? ''); ?>"
              data-department="<?php echo htmlspecialchars($it['Department'] ?? ''); ?>"
              data-bc-cs="<?php echo htmlspecialchars($it['BarcodeCs'] ?? ''); ?>"
              data-bc-bg="<?php echo htmlspecialchars($it['BarcodeBg'] ?? ''); ?>"
              data-bc-pc="<?php echo htmlspecialchars($it['BarcodePc'] ?? ''); ?>"
              data-bc-status="<?php echo $hasAny ? 'complete' : 'pending'; ?>">
            <td class="cell-id"><?php echo (int)$it['ItemID']; ?></td>
            <td>
              <?php if (!empty($it['HasImage'])): ?>
                <img class="thumb" src="image_item.php?id=<?php echo (int)$it['ItemID']; ?>" alt="">
              <?php else: ?>
                <div class="thumb thumb-placeholder" aria-hidden="true">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.8"/><path d="m21 15-4.5-4.5L6 21"/></svg>
                </div>
              <?php endif; ?>
            </td>
            <td class="cell-strong"><?php echo htmlspecialchars($it['ItemCode'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($it['ItemDescription'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($it['Department'] ?? ''); ?></td>
            <td>
              <div class="bc-dots">
                <span class="bc-dot <?php echo $csHas  ? 'bc-dot--done' : ''; ?>" title="Case">CS</span>
                <span class="bc-dot <?php echo $bgHas  ? 'bc-dot--done' : ''; ?>" title="Bag">BG</span>
                <span class="bc-dot <?php echo $pcHas  ? 'bc-dot--done' : ''; ?>" title="Pieces">PC</span>
              </div>
            </td>
            <td>
              <div class="rowactions">
                <button class="iconbtn btn-scan-row" title="Scan barcodes for this item"
                        data-item-id="<?php echo (int)$it['ItemID']; ?>">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h2M7 5h1M11 5h3M17 5h1M20 5h1M3 9h1M7 9h4M14 9h2M18 9h3M3 13h2M8 13h1M11 13h4M17 13h1M20 13h1M3 17h3M9 17h1M13 17h3M18 17h3"/></svg>
                </button>
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
      <span id="itemCount">Showing <?php echo count($items); ?> items</span>
      <span>TradewellDatabase · Tbl_Item_Products</span>
    </div>

    <div class="pagination" id="itemPagination"></div>
  </div>

  <!-- Scan Panel (slides in from right) -->
  <aside class="scan-panel" id="scanPanel" aria-hidden="true">
    <div class="scan-panel__head">
      <div class="scan-panel__title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h2M7 5h1M11 5h3M17 5h1M20 5h1M3 9h1M7 9h4M14 9h2M18 9h3M3 13h2M8 13h1M11 13h4M17 13h1M20 13h1M3 17h3M9 17h1M13 17h3M18 17h3"/></svg>
        Scan Barcodes
      </div>
      <button class="iconbtn" id="closeScanPanel" title="Close panel">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Item info header -->
    <div class="scan-item-info">
      <div class="scan-item-info__code" id="scanItemCode">—</div>
      <div class="scan-item-info__name" id="scanItemName">Select an item to begin</div>
      <div class="scan-item-info__dept" id="scanItemDept"></div>
    </div>

    <!-- Quick-scan mode toggle (Scan All) -->
    <div class="scan-mode-bar" id="scanModeBar" style="display:none;">
      <div class="scan-mode-bar__inner">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        <span>Quick Scan Mode — scan items one by one, the code auto-matches the row</span>
      </div>
      <button class="btn btn-ghost" id="exitQuickMode" style="font-size:11px;padding:5px 10px;">Exit</button>
    </div>

    <!-- Quick scan matcher input (hidden until Quick Mode) -->
    <div class="scan-quick-wrap" id="scanQuickWrap" style="display:none;">
      <label class="scan-field-label">Scan Item Barcode (any type)</label>
      <div class="scan-input-wrap">
        <input type="text" class="scan-input" id="scanQuickInput"
               placeholder="Point scanner at item barcode..." autocomplete="off">
      </div>
      <p class="scan-match-status" id="scanMatchStatus"></p>
    </div>

    <!-- Per-field barcode inputs -->
    <div class="scan-fields" id="scanFields">
      <?php
      $bcFields = [
        ['id' => 'bcCase',   'label' => 'Barcode Case',   'key' => 'BarcodeCs',  'short' => 'CS'],
        ['id' => 'bcBag',    'label' => 'Barcode Bag',    'key' => 'BarcodeBg',  'short' => 'BG'],
        ['id' => 'bcPieces', 'label' => 'Barcode Pieces', 'key' => 'BarcodePc',  'short' => 'PC'],
      ];
      foreach ($bcFields as $f): ?>
      <div class="scan-field" id="wrap_<?php echo $f['id']; ?>">
        <div class="scan-field__header">
          <label class="scan-field-label" for="<?php echo $f['id']; ?>">
            <span class="scan-field-badge"><?php echo $f['short']; ?></span>
            <?php echo $f['label']; ?>
          </label>
          <button type="button" class="btn-skip" data-field="<?php echo $f['id']; ?>">Skip</button>
        </div>
        <div class="scan-input-wrap" id="inputWrap_<?php echo $f['id']; ?>">
          <input type="text"
                 class="scan-input"
                 id="<?php echo $f['id']; ?>"
                 data-key="<?php echo $f['key']; ?>"
                 placeholder="Scan or type barcode…"
                 autocomplete="off">
          <span class="scan-check" id="check_<?php echo $f['id']; ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="scan-panel__foot">
      <div class="scan-nav">
        <button class="btn btn-ghost" id="btnPrevItem">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg>
          Prev
        </button>
        <span id="scanItemCounter" class="scan-counter">— / —</span>
        <button class="btn btn-ghost" id="btnNextItem">
          Next
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M9 18l6-6-6-6"/></svg>
        </button>
      </div>
      <button class="btn btn-primary" id="btnSaveBarcodes">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>
        Save Barcodes
      </button>
    </div>
  </aside>

</div><!-- /items-layout -->

<!-- ============ Create New Item Modal ============ -->
<div class="modal-overlay" id="itemModal">
  <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="itemModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="itemModalTitle">Create New Item</h3>
        <span>Maintenance · Items</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="itemForm">
      <div class="modal__body">
        <div class="form-columns">
          <div class="field-group">
            <div class="field">
              <label for="itemDepartment">Department</label>
              <input type="text" id="itemDepartment" name="department" value="<?php echo htmlspecialchars($activeDepartment); ?>" readonly>
              <span class="hint">Auto-filled from the department you're currently viewing.</span>
            </div>
            <div class="field">
              <label for="itemCodeSuffix">Item Code</label>
              <div class="input-prefix">
                <span class="input-prefix__tag" id="itemCodePrefix"><?php echo htmlspecialchars($activeDeptPrefix); ?></span>
                <input type="text" id="itemCodeSuffix" placeholder="F60048" autocomplete="off">
              </div>
              <input type="hidden" id="itemCodeFull" name="item_code">
              <span class="field-flag" id="itemCodeFlag">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 8v5"/><circle cx="12" cy="16.5" r="0.5" fill="currentColor"/><path d="M10.3 3.9 2.5 17.5A1.6 1.6 0 0 0 3.9 20h16.2a1.6 1.6 0 0 0 1.4-2.5L13.7 3.9a1.6 1.6 0 0 0-2.8 0Z"/></svg>
                Already Exist
              </span>
            </div>
            <div class="field">
              <label for="itemName">Item Name</label>
              <input type="text" id="itemName" name="item_name" placeholder="e.g. PAPA BC 240x25">
            </div>
            <div class="field-group__label">Connected fields</div>
            <div class="field">
              <label for="itemSupplierCode">Supplier Code</label>
              <select id="itemSupplierCode" name="supplier_code">
                <option value="">Select supplier</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?php echo htmlspecialchars($s['SupplierCode'] ?? ''); ?>">
                  <?php echo htmlspecialchars(($s['SupplierCode'] ?? '') . ' — ' . ($s['SupplierName'] ?? '')); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="itemBrandName">Brand Name</label>
              <select id="itemBrandName" name="brand_name" disabled>
                <option value="">Select supplier first</option>
              </select>
            </div>
            <div class="field">
              <label for="itemCategory">Category</label>
              <select id="itemCategory" name="category" disabled>
                <option value="">Select supplier first</option>
              </select>
            </div>
            <div class="connected-note">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
              <span>Picking a supplier loads only the brands and categories that supplier has on file — if none, leave those two as they are.</span>
            </div>
          </div>
          <div class="field-group">
            <div class="field-group__label">Quantity &amp; UOM</div>
            <div class="qty-row">
              <div class="field"><label for="qtyCase">Quantity Case</label><input type="number" id="qtyCase" name="qty_case" min="0" placeholder="0"></div>
              <div class="field"><label for="uomCase">UOM</label><select id="uomCase" name="uom_case"><option>CS</option><option>BOX</option><option>DZ</option><option>PACK</option></select></div>
            </div>
            <div class="qty-row">
              <div class="field"><label for="qtyBag">Quantity Bag</label><input type="number" id="qtyBag" name="qty_bag" min="0" placeholder="0"></div>
              <div class="field"><label for="uomBag">UOM</label><select id="uomBag" name="uom_bag"><option>BG</option><option>PACK</option><option>KG</option></select></div>
            </div>
            <div class="qty-row">
              <div class="field"><label for="qtyPieces">Quantity Pieces</label><input type="number" id="qtyPieces" name="qty_pieces" min="0" placeholder="0"></div>
              <div class="field"><label for="uomPieces">UOM</label><select id="uomPieces" name="uom_pieces"><option>PC</option><option>PCS</option><option>DZ</option></select></div>
            </div>
            <div class="field-group__label">Barcode</div>
            <div class="field"><label for="barcodeCase">Barcode Case</label><input type="text" id="barcodeCase" name="barcode_case" placeholder="e.g. 4800123456789"></div>
            <div class="field"><label for="barcodeBag">Barcode Bag</label><input type="text" id="barcodeBag" name="barcode_bag" placeholder="e.g. 4800123456796"></div>
            <div class="field"><label for="barcodePieces">Barcode Pieces</label><input type="text" id="barcodePieces" name="barcode_pieces" placeholder="e.g. 4800123456802"></div>
          </div>
        </div>
      </div>
      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Item</button>
      </div>
    </form>
  </div>
</div>

<script>
// Items data for the scan panel (from PHP, so no extra AJAX needed)
var ITEMS_DATA = <?php echo $itemsJson; ?>;
</script>
<script src="../assets/js/items_scan.js"></script>
<script src="../assets/js/app.js"></script>

</main>
</div><!-- /main -->
</div><!-- /app -->
</body>
</html>
