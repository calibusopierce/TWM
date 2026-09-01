<?php
$pageTitle = 'List of Category';
$pageCrumb = 'Maintenance';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';

$conn = getConnection();

$departments = getActiveDepartments($conn);

// Live query against dbo.TBL_Technical_Category in TradewellDatabase.
$sql = "SELECT ID, CategoryCode, CategoryName, Status
        FROM TBL_Technical_Category
        ORDER BY CategoryName ASC";

$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(json_encode(['error' => true, 'message' => print_r(sqlsrv_errors(), true)]));
}

$categories = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $categories[] = $row;
}

closeConnection($conn);

include __DIR__ . '/../includes/header.php';
?>

<div class="card">
  <div class="toolbar">
    <div class="searchbox">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="categorySearch" placeholder="Search category code, name...">
    </div>
    <button class="btn btn-primary" data-open-modal>
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
      Create New
    </button>
  </div>

  <div class="table-wrap">
    <table class="ledger" id="categoryTable">
      <thead>
        <tr>
          <th style="width:48px;">ID</th>
          <th>Category Code</th>
          <th>Category Name</th>
          <th>Status</th>
          <th style="width:110px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
        <tr>
          <td colspan="5">
            <div class="table-empty">No categories yet. Click "Create New" to add the first one.</div>
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($categories as $c): ?>
        <?php $isActive = !empty($c['Status']); ?>
        <tr>
          <td class="cell-id"><?php echo str_pad($c['ID'], 2, '0', STR_PAD_LEFT); ?></td>
          <td class="cell-strong"><?php echo htmlspecialchars($c['CategoryCode'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($c['CategoryName'] ?? ''); ?></td>
          <td>
            <span class="status <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
              <?php echo $isActive ? 'Active' : 'Inactive'; ?>
            </span>
          </td>
          <td>
            <div class="rowactions">
              <button class="iconbtn" title="Edit category">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
              </button>
              <button class="iconbtn" title="View category">
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
    <span>Showing <?php echo count($categories); ?> of <?php echo count($categories); ?> categories</span>
    <span>TradewellDatabase · dbo.TBL_Technical_Category</span>
  </div>
</div>

<!-- ============ Add New Category Modal ============ -->
<div class="modal-overlay" id="categoryModal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
    <div class="modal__head">
      <div>
        <h3 id="categoryModalTitle">Add New Category</h3>
        <span>Maintenance · Category</span>
      </div>
      <button class="modal__close" data-close-modal aria-label="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <form id="categoryForm" method="post">
      <div class="modal__body">

        <div class="field">
          <label for="categoryCode">Category Code</label>
          <input type="text" id="categoryCode" name="category_code" placeholder="e.g. UPS" required autocomplete="off">
          <span class="hint">Short code, shown in Item Barcode and lookups.</span>
        </div>

        <div class="field">
          <label for="categoryName">Category Name</label>
          <input type="text" id="categoryName" name="category_name" placeholder="e.g. UPS / Power Supply" required>
        </div>

        <div class="field">
          <label for="categoryStatus">Status</label>
          <select id="categoryStatus" name="status">
            <option value="Active" selected>Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>

      </div>

      <div class="modal__foot">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Category</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
