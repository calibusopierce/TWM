<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'cash_advance');

$EmployeeID   = $_SESSION['EmployeeID'];
$statusFilter = $_GET['status'] ?? 'All';
$validStatuses = ['All', 'Requested', 'Approved', 'Received'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'All';

$where  = "WHERE ca.EmployeeID = ?";
$params = [$EmployeeID];
if ($statusFilter !== 'All') { $where .= " AND ca.Status = ?"; $params[] = $statusFilter; }

$sql = "SELECT ca.CashAdvanceID, ca.Amount, ca.Reason, ca.Status, ca.Department, ca.Branch,
               CONVERT(varchar(10), ca.RequestDate,  107) AS RequestDate,
               CONVERT(varchar(10), ca.ApprovedDate, 107) AS ApprovedDate,
               CONVERT(varchar(10), ca.ReceivedDate, 107) AS ReceivedDate,
               rec.FirstName  + ' ' + rec.LastName  AS RecommendByName,
               appr.FirstName + ' ' + appr.LastName AS ApprovedByName
        FROM TBL_CashAdvance ca
        LEFT JOIN TBL_HrEmployeeList rec  ON rec.EmployeeID  = ca.RecommendByID
        LEFT JOIN TBL_HrEmployeeList appr ON appr.EmployeeID = ca.ApprovedByID
        $where ORDER BY ca.RequestDate DESC";

$stmt     = sqlsrv_query($conn, $sql, $params);
$requests = [];
if ($stmt !== false) while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $requests[] = $row;

// Stat counts (always unfiltered)
$statStmt = sqlsrv_query($conn,
    "SELECT Status, COUNT(*) AS Cnt FROM TBL_CashAdvance WHERE EmployeeID = ? GROUP BY Status",
    [$EmployeeID]);
$stats = ['Requested' => 0, 'Approved' => 0, 'Received' => 0];
if ($statStmt !== false)
    while ($row = sqlsrv_fetch_array($statStmt, SQLSRV_FETCH_ASSOC))
        if (isset($stats[$row['Status']])) $stats[$row['Status']] = $row['Cnt'];

function statusBadge($s) {
    return match($s) {
        'Requested' => '<span class="badge-status s-requested">Requested</span>',
        'Approved'  => '<span class="badge-status s-approved">Approved</span>',
        'Received'  => '<span class="badge-status s-received">Received</span>',
        default     => '<span class="badge-status">'.htmlspecialchars($s).'</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Cash Advance Requests · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    /* stat cards */
    .stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1rem; }
    .stat-card { background:var(--surface); border:1px solid var(--border);
                 border-radius:var(--radius-lg); padding:.85rem 1rem;
                 display:flex; align-items:center; gap:.75rem; }
    .stat-icon { width:38px; height:38px; border-radius:var(--radius);
                 display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
    .stat-icon.requested { background:rgba(245,158,11,.12); color:#d97706; }
    .stat-icon.approved  { background:rgba(59,130,246,.12);  color:#2563eb; }
    .stat-icon.received  { background:rgba(16,185,129,.12);  color:#059669; }
    .stat-num  { font-size:1.4rem; font-weight:800; color:var(--text-main); line-height:1; }
    .stat-lbl  { font-size:.72rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }

    /* status filter tabs */
    .filter-tabs { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.85rem; }
    .filter-tab {
      padding:.32rem .85rem; border-radius:999px; font-size:.78rem; font-weight:600;
      border:1px solid var(--border); background:var(--surface); color:var(--text-muted);
      text-decoration:none; transition:background .15s, color .15s;
    }
    .filter-tab:hover { background:var(--surface-alt,#f8fafc); color:var(--text-main); }
    .filter-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

    /* table */
    .req-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .req-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .req-table th { padding:.6rem .85rem; font-size:.72rem; font-weight:700;
                    text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .req-table td { padding:.65rem .85rem; border-bottom:1px solid var(--border); vertical-align:middle; }
    .req-table tbody tr:hover { background:var(--surface-alt,#f8fafc); }
    .req-table .amt { font-weight:700; color:var(--text-main); }

    /* status badges */
    .badge-status { padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:700; }
    .s-requested { background:rgba(245,158,11,.12);  color:#92400e; }
    .s-approved  { background:rgba(59,130,246,.12);   color:#1e40af; }
    .s-received  { background:rgba(16,185,129,.12);   color:#065f46; }

    .btn-view { display:inline-flex; align-items:center; gap:4px;
                padding:.28rem .7rem; border-radius:var(--radius); font-size:.78rem; font-weight:600;
                background:rgba(124,58,237,.08); color:#7c3aed; border:1px solid rgba(124,58,237,.2);
                text-decoration:none; transition:background .15s; }
    .btn-view:hover { background:rgba(124,58,237,.16); color:#6d28d9; }

    .empty-row td { text-align:center; padding:3rem 1rem; color:var(--text-muted); }

    @media (max-width:640px) {
      .stat-row { grid-template-columns:1fr 1fr; }
      .req-table thead { display:none; }
      .req-table td { display:block; text-align:right; padding:.4rem .85rem; }
      .req-table td::before { content:attr(data-label); float:left; font-weight:700; color:var(--text-muted); font-size:.72rem; }
      .req-table tr { border-bottom:2px solid var(--border); display:block; margin-bottom:.5rem; }
    }
  </style>
</head>
<body>
<?php $topbar_page = 'cash_advance'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">

  <div class="page-header">
    <div>
      <div class="page-title"><i class="bi bi-cash-coin me-2" style="color:var(--primary);"></i>My Cash Advance Requests</div>
      <div class="page-subtitle">Track and manage your submitted requests</div>
    </div>
    <a href="create.php" class="btn btn-add"><i class="bi bi-plus-lg"></i> New Request</a>
  </div>

  <?php if (isset($_GET['submitted'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
      <i class="bi bi-check-circle-fill me-2"></i>
      Request submitted! Your cash advance is now <strong>Requested</strong> and pending recommendation / approval.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- Stat cards -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-icon requested"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-num"><?= $stats['Requested'] ?></div>
        <div class="stat-lbl">Requested</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon approved"><i class="bi bi-check-circle-fill"></i></div>
      <div>
        <div class="stat-num"><?= $stats['Approved'] ?></div>
        <div class="stat-lbl">Approved</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon received"><i class="bi bi-box-seam-fill"></i></div>
      <div>
        <div class="stat-num"><?= $stats['Received'] ?></div>
        <div class="stat-lbl">Received</div>
      </div>
    </div>
  </div>

  <!-- Table card -->
  <div class="table-card">
    <div class="table-card-header">
      <div class="table-card-title">
        <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
        Requests
        <span class="count-chip"><?= count($requests) ?></span>
      </div>
      <!-- Status filter tabs -->
      <div class="filter-tabs">
        <?php foreach (['All', 'Requested', 'Approved', 'Received'] as $tab): ?>
          <a href="?status=<?= $tab ?>"
             class="filter-tab <?= $statusFilter === $tab ? 'active' : '' ?>">
            <?= $tab ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="table-responsive">
      <table class="req-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Amount</th>
            <th>Reason</th>
            <th>Requested</th>
            <th>Recommended By</th>
            <th>Approved By</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr class="empty-row">
              <td colspan="8">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No requests found<?= $statusFilter !== 'All' ? " with status \"$statusFilter\"" : '' ?>.
                <?php if ($statusFilter === 'All'): ?>
                  <a href="create.php" style="color:var(--primary);">Submit your first request</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($requests as $r): ?>
            <tr>
              <td data-label="#">#<?= $r['CashAdvanceID'] ?></td>
              <td data-label="Amount" class="amt">₱<?= number_format($r['Amount'], 2) ?></td>
              <td data-label="Reason"><?= htmlspecialchars($r['Reason'] ?: '—') ?></td>
              <td data-label="Requested"><?= htmlspecialchars($r['RequestDate']) ?></td>
              <td data-label="Recommended By"><?= htmlspecialchars($r['RecommendByName'] ?: '—') ?></td>
              <td data-label="Approved By"><?= htmlspecialchars($r['ApprovedByName'] ?: '—') ?></td>
              <td data-label="Status"><?= statusBadge($r['Status']) ?></td>
              <td data-label="">
                <a href="view.php?id=<?= $r['CashAdvanceID'] ?>" class="btn-view">
                  <i class="bi bi-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>