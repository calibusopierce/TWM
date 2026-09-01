<?php
$pageTitle = 'List of Supplier';
$pageCrumb = 'Maintenance';

require_once __DIR__ . '/../includes/session_guard.php'; // must come before any DB work
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';

$conn = getConnection();

// Live query against dbo.TBL_Item_Supplier in TradewellDatabase.
// Only "Principal" category, active suppliers show up in this list.
$sql = "SELECT ID, Department, SupplierCode, SupplierName, Status,
               CASE WHEN Image IS NOT NULL THEN 1 ELSE 0 END AS HasImage
        FROM TBL_Item_Supplier
        WHERE Category = 'Principal' AND Status = 1
        ORDER BY ID DESC";

$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]));
}

$suppliers = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $suppliers[] = $row;
}

// For the topbar filter and the "Add New Supplier" department dropdown.
$departments = getActiveDepartments($conn);

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="supplierSearch" placeholder="Search suppliers, contacts...">
    </div>
    <button class="btn btn-primary" data-open-modal>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Create New
    </button>
  </div>

  <div class="table-wrap">
    <table class="ledger" id="supplierTable">
      <thead>
        <tr>
          <th style="width:48px;">ID</th>
          <th style="width:72px;">Image</th>
          <th>Supplier Code</th>
          <th>Supplier Name</th>
          <th>Department</th>
          <th>Status</th>
          <th style="width:110px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($suppliers)): ?>
        <tr>
          <td colspan="7">
            <div class="table-empty">No suppliers yet. Click "Create New" to add the first one.</div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($suppliers as $s): ?>
        <?php $isActive = !empty($s['Status']); ?>
        <tr>
          <td class="cell-id"><?php echo str_pad($s['ID'], 2, '0', STR_PAD_LEFT); ?></td>
          <td>
            <?php if (!empty($s['HasImage'])): ?>
              <img class="thumb" src="image.php?id=<?php echo (int)$s['ID']; ?>" alt="">
            <?php else: ?>
              <div class="thumb thumb-placeholder" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.8"/><path d="m21 15-4.5-4.5L6 21"/></svg>
              </div>
            <?php endif; ?>
          </td>
          <td class="cell-strong"><?php echo htmlspecialchars($s['SupplierCode'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($s['SupplierName'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($s['Department'] ?? ''); ?></td>
          <td>
            <span class="status <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
              <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
          </td>
          <td>
            <div class="rowactions">
              <button class="iconbtn" title="Edit supplier">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <button class="iconbtn" title="View supplier">
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
    <span>Showing <?php echo count($suppliers); ?> of <?php echo count($suppliers); ?> suppliers</span>
    <span>TradewellDatabase · dbo.TBL_Item_Supplier</span>
  </div>
</div>

<!-- ============ Add New Supplier Modal ============ -->
<div class="modal-overlay" id="supplierModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="supplierModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="supplierModalTitle">Add New Supplier</h3>
        <span>Maintenance · Supplier</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <form id="supplierForm" method="post" enctype="multipart/form-data">
      <div class="modal__body">
        <div class="field">
          <label for="supplierImage">Image</label>
          <input type="file" id="supplierImage" name="image" accept="image/*">
          <span class="hint">PNG or JPG, used as the supplier's thumbnail in the list.</span>
        </div>

        <div class="field">
          <label for="supplierCode">Supplier Code</label>
          <input type="text" id="supplierCode" name="supplier_code" placeholder="e.g. PAI FOOD" required>
        </div>

        <div class="field">
          <label for="supplierName">Supplier Name</label>
          <input type="text" id="supplierName" name="supplier_name" placeholder="e.g. Prifood Corporation" required>
        </div>

        <div class="field">
          <label for="supplierDepartment">Department</label>
          <select id="supplierDepartment" name="department">
            <option value="">Select department</option>
            <?php if (!empty($departments)): ?>
              <?php foreach ($departments as $d):
                $deptValue = $d['Department'] ?: ($d['DepartmentCode'] ?? '');
                $deptLabel = $d['DepartmentName'] ?: $deptValue;
              ?>
              <option value="<?php echo htmlspecialchars($deptValue); ?>"><?php echo htmlspecialchars($deptLabel); ?></option>
              <?php endforeach; ?>
            <?php else: ?>
              <option value="" disabled>No departments found</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="field">
          <label for="supplierCategory">Category</label>
          <select id="supplierCategory" name="category">
            <option value="Principal" selected>Principal</option>
            <option value="Non-Principal">Non-Principal</option>
          </select>
          <span class="hint">Only "Principal" suppliers show up in this list.</span>
        </div>

        <div class="field">
          <label for="supplierStatus">Status</label>
          <select id="supplierStatus" name="status">
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Supplier</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
