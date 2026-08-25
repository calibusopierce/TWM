<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';

auth_check();
rbac_gate($pdo, 'cash_advance_record');

// rbac_gate() already calls rbac_load_permissions() internally.
// Full access = can Approve/Receive. View-only = list is visible, no action buttons.
$canEdit = !rbac_is_view_only('cash_advance_record');

// --- Filters ---
$statusFilter = $_GET['status'] ?? 'All';
$validStatuses = ['All', 'Requested', 'Approved', 'Rejected', 'Received', 'Paid'];
if (!in_array($statusFilter, $validStatuses)) {
    $statusFilter = 'All';
}

$whereClause = "WHERE 1=1";
$params = [];

if ($statusFilter !== 'All') {
    $whereClause .= " AND ca.Status = ?";
    $params[] = $statusFilter;
}

$sql = "SELECT
            ca.CashAdvanceID,
            ca.Amount,
            ca.Reason,
            CONVERT(varchar(10), ca.RequestDate, 23) AS RequestDate,
            ca.Status,
            ca.Department,
            ca.Branch,
            emp.FirstName + ' ' + emp.LastName AS EmployeeName,
            rec.FirstName + ' ' + rec.LastName AS RecommendByName,
            appr.FirstName + ' ' + appr.LastName AS ApprovedByName,
            apv.FirstName + ' ' + apv.LastName AS ApproverName,
            CONVERT(varchar(10), ca.ApprovedDate, 23) AS ApprovedDate,
            CONVERT(varchar(10), ca.ReceivedDate, 23) AS ReceivedDate
        FROM TBL_CashAdvance ca
        LEFT JOIN TBL_HrEmployeeList emp  ON emp.EmployeeID  = ca.EmployeeID
        LEFT JOIN TBL_HrEmployeeList rec  ON rec.EmployeeID  = ca.RecommendByID
        LEFT JOIN TBL_HrEmployeeList appr ON appr.EmployeeID = ca.ApprovedByID
        LEFT JOIN TBL_HrEmployeeList apv  ON apv.EmployeeID  = ca.AssignedApproverID
        $whereClause
        ORDER BY
            CASE ca.Status WHEN 'Requested' THEN 0 WHEN 'Approved' THEN 1 ELSE 2 END,
            ca.RequestDate DESC";

$stmt = sqlsrv_query($conn, $sql, $params);
$requests = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }
}

// --- Stat cards (all records, unfiltered) ---
$statSql = "SELECT Status, COUNT(*) AS Cnt, SUM(Amount) AS Total FROM TBL_CashAdvance GROUP BY Status";
$statStmt = sqlsrv_query($conn, $statSql, []);
$stats = ['Requested' => 0, 'Approved' => 0, 'Received' => 0];
$statTotals = ['Requested' => 0, 'Approved' => 0, 'Received' => 0];
if ($statStmt !== false) {
    while ($row = sqlsrv_fetch_array($statStmt, SQLSRV_FETCH_ASSOC)) {
        if (isset($stats[$row['Status']])) {
            $stats[$row['Status']] = $row['Cnt'];
            $statTotals[$row['Status']] = $row['Total'];
        }
    }
}

function statusBadgeClass($status) {
    switch ($status) {
        case 'Requested': return 'badge-requested';
        case 'Approved':  return 'badge-approved';
        case 'Rejected':  return 'badge-rejected';
        case 'Received':  return 'badge-received';
        case 'Paid':      return 'badge-paid';
        default: return 'badge-secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Cash Advance Records</title>
<link rel="stylesheet" href="<?= base_url('assets/fuel.css') ?>">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
<style>
    /* ----------------------------------------------
       ROOT & RESET
    ------------------------------------------------ */
    :root {
        --ca-accent: #7c3aed;
        --ca-accent-light: #ede9fe;
        --ca-accent-dark: #5b21b6;
        --ca-bg: #f5f3ff;
        --ca-card-bg: #ffffff;
        --ca-text: #1e1b2e;
        --ca-muted: #6b7280;
        --ca-border: #e5e7eb;
        --ca-shadow: 0 4px 12px rgba(124, 58, 237, 0.06);
        --ca-radius: 14px;
    }

    * { box-sizing: border-box; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: var(--ca-bg);
        margin: 0;
        padding: 0;
        min-height: 100vh;
        color: var(--ca-text);
    }

    /* ---- LAYOUT ---- */
    .ca-wrap {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 20px;
    }

    /* ---- HEADER ---- */
    .ca-header {
        margin-bottom: 28px;
    }
    .ca-header h4 {
        font-weight: 700;
        font-size: 1.4rem;
        color: var(--ca-accent-dark);
        margin: 0 0 2px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .ca-header h4 i {
        font-size: 1.6rem;
        color: var(--ca-accent);
    }
    .ca-header .subtitle {
        color: var(--ca-muted);
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .ca-header .subtitle .view-only-badge {
        background: #e5e7eb;
        color: #4b5563;
        padding: 2px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    /* ---- STAT CARDS ---- */
    .stat-cards {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 28px;
    }

    .stat-card {
        background: #fff;
        border-radius: var(--ca-radius);
        padding: 18px 20px;
        border: 1px solid var(--ca-border);
        box-shadow: var(--ca-shadow);
        transition: transform 0.1s, box-shadow 0.15s;
        border-left: 5px solid transparent;
    }
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(124,58,237,0.08);
    }
    .stat-card .label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-weight: 600;
        color: var(--ca-muted);
    }
    .stat-card .count {
        font-size: 1.8rem;
        font-weight: 700;
        color: #1f2937;
        line-height: 1.2;
        margin: 4px 0 2px;
    }
    .stat-card .amt {
        font-size: 0.8rem;
        color: var(--ca-muted);
        font-weight: 500;
    }

    .stat-card.requested { border-left-color: #f59e0b; }
    .stat-card.approved  { border-left-color: #3b82f6; }
    .stat-card.received  { border-left-color: #10b981; }

    /* ---- TABS ---- */
    .ca-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 20px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        padding-bottom: 4px;
        flex-wrap: nowrap;
    }
    .ca-tabs a {
        padding: 8px 18px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        color: #6b7280;
        background: #fff;
        border: 1.5px solid #e5e7eb;
        white-space: nowrap;
        transition: all 0.15s;
        flex-shrink: 0;
    }
    .ca-tabs a:hover {
        background: var(--ca-accent-light);
        border-color: var(--ca-accent);
        color: var(--ca-accent-dark);
    }
    .ca-tabs a.active {
        background: var(--ca-accent);
        color: #fff;
        border-color: var(--ca-accent);
    }

    /* ---- TABLE ---- */
    .ca-table-wrap {
        background: #fff;
        border-radius: var(--ca-radius);
        border: 1px solid var(--ca-border);
        overflow: hidden;
        box-shadow: var(--ca-shadow);
    }

    table.ca-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }

    table.ca-table th {
        background: #f8f7fc;
        color: var(--ca-accent-dark);
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 14px 16px;
        text-align: left;
        border-bottom: 2px solid #ede9fe;
        white-space: nowrap;
    }

    table.ca-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle;
    }

    table.ca-table tr:last-child td {
        border-bottom: none;
    }
    table.ca-table tr:hover td {
        background: #faf9fe;
    }

    /* Badges */
    .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: capitalize;
    }
    .badge-requested { background: #fef3c7; color: #92400e; }
    .badge-approved  { background: #dbeafe; color: #1e40af; }
    .badge-rejected  { background: #fee2e2; color: #991b1b; }
    .badge-received  { background: #d1fae5; color: #065f46; }
    .badge-paid      { background: #ede9fe; color: #5b21b6; }

    /* Action buttons */
    .btn-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: none;
        border-radius: 30px;
        padding: 6px 16px;
        font-size: 0.75rem;
        font-weight: 600;
        color: #fff;
        cursor: pointer;
        transition: background 0.15s, transform 0.1s;
        text-decoration: none;
        white-space: nowrap;
    }
    .btn-action:active {
        transform: scale(0.96);
    }
    .btn-approve {
        background: #3b82f6;
    }
    .btn-approve:hover {
        background: #2563eb;
    }
    .btn-receive {
        background: #10b981;
    }
    .btn-receive:hover {
        background: #059669;
    }
    .btn-reject {
        background: #ef4444;
    }
    .btn-reject:hover {
        background: #dc2626;
    }
    .btn-edit {
        background: #6b7280;
    }
    .btn-edit:hover {
        background: #4b5563;
    }
    .btn-action i {
        font-size: 1rem;
    }

    .no-action {
        color: #9ca3af;
        font-size: 0.75rem;
    }

    /* ---- EMPTY STATE ---- */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #9ca3af;
        background: #fff;
        border-radius: var(--ca-radius);
        border: 1px dashed #d1d5db;
    }
    .empty-state i {
        font-size: 3rem;
        color: #d1d5db;
        display: block;
        margin-bottom: 12px;
    }
    .empty-state p {
        font-size: 0.95rem;
        margin: 0;
    }

    /* ---- RESPONSIVE ---- */
    @media (max-width: 768px) {
        .ca-wrap { padding: 0 16px; margin: 20px auto; }
        .ca-header h4 { font-size: 1.2rem; }
        .stat-cards { grid-template-columns: 1fr 1fr; gap: 12px; }
        .stat-card .count { font-size: 1.4rem; }
        .stat-card .label { font-size: 0.65rem; }
    }

    @media (max-width: 576px) {
        .ca-wrap { padding: 0 12px; margin: 12px auto; }
        .ca-header h4 { font-size: 1rem; }
        .ca-header .subtitle { font-size: 0.8rem; }
        .stat-cards { grid-template-columns: 1fr 1fr; gap: 8px; }
        .stat-card { padding: 12px 14px; }
        .stat-card .count { font-size: 1.2rem; }
        .stat-card .amt { font-size: 0.7rem; }

        /* Mobile card style for table */
        .ca-table-wrap {
            border: none;
            background: transparent;
            box-shadow: none;
        }
        table.ca-table,
        table.ca-table thead,
        table.ca-table tbody,
        table.ca-table tr,
        table.ca-table th,
        table.ca-table td {
            display: block;
        }
        table.ca-table thead {
            display: none;
        }
        table.ca-table tr {
            background: #fff;
            border: 1px solid var(--ca-border);
            border-radius: var(--ca-radius);
            margin-bottom: 14px;
            padding: 12px 14px;
            box-shadow: var(--ca-shadow);
        }
        table.ca-table td {
            border-bottom: none;
            padding: 6px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.82rem;
            background: transparent !important;
        }
        table.ca-table td:last-child {
            border-bottom: none;
        }
        table.ca-table td[data-label]:not([data-label=""])::before {
            content: attr(data-label);
            font-weight: 600;
            color: #9ca3af;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-right: 12px;
            flex-shrink: 0;
        }
        table.ca-table td.action-cell {
            justify-content: flex-end;
            padding-top: 8px;
            border-top: 1px solid #f3f4f6;
            margin-top: 6px;
        }
        table.ca-table td.action-cell::before {
            display: none;
        }
        .btn-action {
            padding: 4px 14px;
            font-size: 0.7rem;
        }
    }
</style>
</head>
<body>
<?php $topbar_page = 'cash_advance_record'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="main-wrapper">

<div class="ca-wrap">
    <div class="ca-header">
        <h4><i class="bi bi-clipboard-check"></i> Cash Advance Records</h4>
        <div class="subtitle">
            <span>Approver view — recommend, approve, and mark requests as received</span>
            <?php if (!$canEdit): ?>
                <span class="view-only-badge"><i class="bi bi-eye"></i> View Only</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="stat-cards">
        <div class="stat-card requested">
            <div class="label">Requested</div>
            <div class="count"><?= $stats['Requested'] ?></div>
            <div class="amt">₱<?= number_format($statTotals['Requested'], 2) ?></div>
        </div>
        <div class="stat-card approved">
            <div class="label">Approved</div>
            <div class="count"><?= $stats['Approved'] ?></div>
            <div class="amt">₱<?= number_format($statTotals['Approved'], 2) ?></div>
        </div>
        <div class="stat-card received">
            <div class="label">Received</div>
            <div class="count"><?= $stats['Received'] ?></div>
            <div class="amt">₱<?= number_format($statTotals['Received'], 2) ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="ca-tabs">
        <?php foreach ($validStatuses as $tab): ?>
            <a href="?status=<?= urlencode($tab) ?>" class="<?= $statusFilter === $tab ? 'active' : '' ?>"><?= $tab ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <?php if (empty($requests)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>No cash advance records found<?= $statusFilter !== 'All' ? ' for status: <strong>' . htmlspecialchars($statusFilter) . '</strong>' : '' ?>.</p>
        </div>
    <?php else: ?>
        <div class="ca-table-wrap">
            <table class="ca-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Employee</th>
                        <th>Amount</th>
                        <th>Requested</th>
                        <th>Approver</th>
                        <th>Recommended By</th>
                        <th>Approved By</th>
                        <th>Status</th>
                        <?php if ($canEdit): ?><th>Action</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr id="row-<?= $r['CashAdvanceID'] ?>">
                        <td data-label="ID">#<?= $r['CashAdvanceID'] ?></td>
                        <td data-label="Employee"><?= htmlspecialchars($r['EmployeeName'] ?: '—') ?></td>
                        <td data-label="Amount">₱<?= number_format($r['Amount'], 2) ?></td>
                        <td data-label="Requested"><?= htmlspecialchars($r['RequestDate']) ?></td>
                        <td data-label="Approver"><?= htmlspecialchars($r['ApproverName'] ?: '—') ?></td>
                        <td data-label="Recommended By"><?= htmlspecialchars($r['RecommendByName'] ?: '—') ?></td>
                        <td data-label="Approved By"><?= htmlspecialchars($r['ApprovedByName'] ?: '—') ?></td>
                        <td data-label="Status" class="status-cell">
                            <span class="badge <?= statusBadgeClass($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></span>
                        </td>
                        <?php if ($canEdit): ?>
                        <td data-label="Action" class="action-cell">
                            <?php if ($r['Status'] === 'Requested'): ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <button class="btn-action btn-approve" onclick="doAction(<?= $r['CashAdvanceID'] ?>, 'approve')">
                                    <i class="bi bi-check-lg"></i> Approve
                                </button>
                                <button class="btn-action btn-reject" onclick="doReject(<?= $r['CashAdvanceID'] ?>)">
                                    <i class="bi bi-x-lg"></i> Reject
                                </button>
                                <a class="btn-action btn-edit" href="edit.php?id=<?= $r['CashAdvanceID'] ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                </div>
                            <?php elseif ($r['Status'] === 'Approved'): ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <button class="btn-action btn-receive" onclick="doAction(<?= $r['CashAdvanceID'] ?>, 'receive')">
                                    <i class="bi bi-box-seam"></i> Mark Received
                                </button>
                                <a class="btn-action btn-edit" href="edit.php?id=<?= $r['CashAdvanceID'] ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                </div>
                            <?php else: ?>
                                <span class="no-action">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td data-label="" class="action-cell">
                            <a href="view.php?id=<?= $r['CashAdvanceID'] ?>" class="btn-action" style="background:#7c3aed;">
                                <i class="bi bi-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</div><!-- /main-wrapper -->

<script>
function doAction(id, action) {
    const label = action === 'approve' ? 'approve' : 'mark as received';
    if (!confirm('Are you sure you want to ' + label + ' this cash advance request?')) return;

    fetch(action + '.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Action failed.');
        }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
}

function doReject(id) {
    const reason = prompt('Reason for rejecting this request (optional):', '');
    if (reason === null) return; // cancelled
    if (!confirm('Reject this cash advance request?')) return;

    fetch('reject.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id) + '&reason=' + encodeURIComponent(reason)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Action failed.');
        }
    })
    .catch(() => alert('Something went wrong. Please try again.'));
}
</script>

</body>
</html>