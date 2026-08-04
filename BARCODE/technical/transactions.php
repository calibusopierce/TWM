<?php
$pageTitle = 'Asset Transactions';
$pageCrumb = 'Inventory';

require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/departments.php';

$conn = getConnection();

$departments = getActiveDepartments($conn);

// Recent movement log, newest first — joined back to the item for display.
$sql = "SELECT TOP 25 t.TransactionID, t.Barcode, t.ActionType,
               t.FromDepartment, t.ToDepartment, t.FromAssignedTo, t.ToAssignedTo,
               t.Remarks, t.DateTimeInput, i.ItemName
        FROM TBL_Technical_Transactions t
        LEFT JOIN TBL_Technical_Items i ON i.ItemID = t.ItemID
        ORDER BY t.TransactionID DESC";

$stmt = sqlsrv_query($conn, $sql);
$transactions = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $transactions[] = $row;
    }
}

closeConnection($conn);
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
<div class="app" id="appShell">

  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <div class="main">
    <?php include __DIR__ . '/../includes/header_inner.php'; ?>

    <main class="content">

<div class="card">
  <div class="toolbar">
    <div class="searchbox" style="flex:1;">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="text" id="barcodeScanInput" placeholder="Scan or type a barcode, then press Enter..." autocomplete="off" autofocus>
    </div>
    <button class="btn btn-primary" id="barcodeLookupBtn">Look Up</button>
  </div>

  <div id="scanEmptyState" class="table-empty" style="padding: 32px 0;">
    Scan or type a barcode above to pull up an item.
  </div>

  <div id="scanNotFound" class="table-empty" style="padding: 32px 0; display:none; color: var(--red);">
    No item found for that barcode.
  </div>

  <div id="itemPreviewWrap" style="display:none; padding: 20px 24px 24px;">
    <div style="display:flex; gap:16px; align-items:flex-start; padding-bottom:18px; border-bottom:1px solid var(--line);">
      <img id="previewThumb" class="thumb" style="width:56px; height:56px;" src="" alt="">
      <div style="flex:1;">
        <div style="font-size:15px; font-weight:600;" id="previewName">—</div>
        <div style="font-size:12px; color: var(--ink-300); margin-top:2px;" id="previewMeta">—</div>
        <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
          <span class="status status-active" id="previewStatusBadge">In Stock</span>
          <span class="cell-strong" id="previewBarcode" style="font-size:12px;"></span>
        </div>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px;">
      <div class="field">
        <label>Current Department</label>
        <div id="previewDepartment" style="font-size:13px; color: var(--ink-700);">—</div>
      </div>
      <div class="field">
        <label>Currently Assigned To</label>
        <div id="previewAssignedTo" style="font-size:13px; color: var(--ink-700);">—</div>
      </div>
    </div>

    <form id="transactionForm" style="margin-top: 20px;">
      <input type="hidden" id="txnItemId" name="item_id">

      <div class="field-group__label" style="margin-bottom:10px;">Take Action</div>

      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom: 16px;" id="actionTypeGroup">
        <label class="action-choice"><input type="radio" name="action_type" value="assign" checked> Assign / Transfer</label>
        <label class="action-choice"><input type="radio" name="action_type" value="repair"> Mark Under Repair</label>
        <label class="action-choice"><input type="radio" name="action_type" value="return"> Return to Stock</label>
        <label class="action-choice"><input type="radio" name="action_type" value="retire"> Retire</label>
      </div>

      <div id="assignFields" style="display:grid; grid-template-columns: 1fr 1fr; gap: 14px;">
        <div class="field">
          <label for="txnToDepartment">To Department</label>
          <select id="txnToDepartment" name="to_department">
            <option value="">Select department</option>
            <?php foreach ($departments as $d):
              $deptValue = $d['Department'] ?: ($d['DepartmentCode'] ?? '');
              $deptLabel = $d['DepartmentName'] ?: $deptValue;
            ?>
            <option value="<?php echo htmlspecialchars($deptValue); ?>"><?php echo htmlspecialchars($deptLabel); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="txnToAssignedTo">Assigned To</label>
          <input type="text" id="txnToAssignedTo" name="to_assigned_to" placeholder="e.g. J. Dela Cruz">
        </div>
      </div>

      <div class="field" style="margin-top:14px;">
        <label for="txnRemarks">Remarks</label>
        <textarea id="txnRemarks" name="remarks" rows="2" placeholder="Optional notes"></textarea>
      </div>

      <div style="margin-top:16px; display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">Save Transaction</button>
      </div>
    </form>
  </div>
</div>

<div class="card" style="margin-top:20px;">
  <div class="table-wrap">
    <table class="ledger">
      <thead>
        <tr>
          <th>Date</th>
          <th>Barcode</th>
          <th>Item Name</th>
          <th>Action</th>
          <th>From</th>
          <th>To</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
        <tr><td colspan="7"><div class="table-empty">No transactions yet.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $t): ?>
        <tr>
          <td><?php echo $t['DateTimeInput'] ? $t['DateTimeInput']->format('M j, Y g:i A') : ''; ?></td>
          <td class="cell-strong"><?php echo htmlspecialchars($t['Barcode'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($t['ItemName'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($t['ActionType'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars(trim(($t['FromDepartment'] ?? '') . ' ' . ($t['FromAssignedTo'] ? '(' . $t['FromAssignedTo'] . ')' : ''))) ?: '—'; ?></td>
          <td><?php echo htmlspecialchars(trim(($t['ToDepartment'] ?? '') . ' ' . ($t['ToAssignedTo'] ? '(' . $t['ToAssignedTo'] . ')' : ''))) ?: '—'; ?></td>
          <td><?php echo htmlspecialchars($t['Remarks'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="table-footer">
    <span>Showing last <?php echo count($transactions); ?> transactions</span>
    <span>TradewellDatabase · dbo.TBL_Technical_Transactions</span>
  </div>
</div>

<style>
.action-choice {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12.5px; font-weight: 500; color: var(--ink-700);
  border: 1px solid var(--line-strong); border-radius: 999px;
  padding: 7px 14px; cursor: pointer; user-select: none;
}
.action-choice input { accent-color: var(--accent); }
</style>

<script src="../assets/js/stocks_scan.js"></script>
<script src="../assets/js/app.js"></script>

    </main>
  </div>
</div>
</body>
</html>
