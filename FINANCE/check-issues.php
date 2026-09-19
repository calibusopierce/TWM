<?php
/**
 * check-issues.php
 * TWM/FINANCE — Check Issues List (own page + own RBAC module key: check_issues)
 *
 * Display : dbo.View_CheckIssuesList (has supplier/account context)
 * Write   : dbo.TBL_CheckIssues (base table), keyed on ID. The only write
 *           action on this page is marking a record as Paid (Paid = 1).
 *
 * Split out of cheques.php so that Check Issues permissions are managed
 * separately from the Cheques module.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'check_issues');
$viewOnly = rbac_is_view_only('check_issues');

date_default_timezone_set('Asia/Manila');

$Department = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');

// --- Helpers (ci_ prefix so they can't collide with nav.php / topbar.php) ---
function ci_h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function ci_qs($overrides = []) {
    $current = $_GET;
    foreach ($overrides as $k => $v) { $current[$k] = $v; }
    return htmlspecialchars('?' . http_build_query($current));
}

// --- CSRF token (own session key, separate from cheques.php) ---
if (empty($_SESSION['ci_csrf_token'])) {
    $_SESSION['ci_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['ci_csrf_token'];

// --- Handle "Mark as Paid" submission (POST → redirect, preserves filters/page) ---
$ciPostAction = $_POST['form_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($ciPostAction, ['mark_paid', 'return_payment'], true)) {
    if ($viewOnly) {
        $_SESSION['ci_flash'] = ['type' => 'error', 'msg' => 'Your access is view-only.'];
    } elseif (!hash_equals($_SESSION['ci_csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['ci_flash'] = ['type' => 'error', 'msg' => 'Your session expired — please try again.'];
    } else {
        $ciId = (int)($_POST['CheckIssueID'] ?? 0);
        if ($ciId > 0) {
            if ($ciPostAction === 'return_payment') {
                $upd = $pdo->prepare("UPDATE dbo.TBL_CheckIssues SET Paid = 0 WHERE ID = ?");
                $upd->execute([$ciId]);
                $_SESSION['ci_flash'] = ['type' => 'success', 'msg' => "Payment for check issue #{$ciId} was returned. It is now under the Unpaid tab."];
            } else {
                $upd = $pdo->prepare("UPDATE dbo.TBL_CheckIssues SET Paid = 1 WHERE ID = ?");
                $upd->execute([$ciId]);
                $_SESSION['ci_flash'] = ['type' => 'success', 'msg' => "Check issue #{$ciId} marked as paid."];
            }
        } else {
            $_SESSION['ci_flash'] = ['type' => 'error', 'msg' => 'Invalid record.'];
        }
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: check-issues.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$flash = $_SESSION['ci_flash'] ?? null;
unset($_SESSION['ci_flash']);

// --- Filter inputs (GET so pagination links preserve filters) ---
$CISearch   = trim($_GET['CISearch'] ?? '');
$CIBank     = $_GET['CIBank'] ?? '';
$CIDept     = $_GET['CIDept'] ?? '';
$CIDateFrom = $_GET['CIDateFrom'] ?? '';
$CIDateTo   = $_GET['CIDateTo'] ?? '';
$Page       = max(1, (int)($_GET['page'] ?? 1));
$PageSize   = 25;
$ciTab      = (($_GET['tab'] ?? 'unpaid') === 'paid') ? 'paid' : 'unpaid';

$ciBankOptions = [];
$ciDeptOptions = [];
$ciRows        = [];
$CITotalCount = $CITotalAmount = $CITotalUnpaid = 0;
$CIPaidCount = $CIPaidAmount = $CIUnpaidAmount = 0;

// --- Build WHERE clause (parameterized) ---
$ciConditions = [];
$ciParams     = [];

if ($CIDept !== '') {
    $ciConditions[] = "Department = ?";
    $ciParams[]     = $CIDept;
}
if ($CIBank !== '') {
    $ciConditions[] = "BankIssue = ?";
    $ciParams[]     = $CIBank;
}
if ($CISearch !== '') {
    $term = '%' . $CISearch . '%';
    $ciConditions[] = "(CAST(ID AS VARCHAR(50)) LIKE ? OR ReferenceNo LIKE ? OR SupplierName LIKE ?
                         OR AccountName LIKE ? OR CAST(CheckNo AS VARCHAR(50)) LIKE ? OR Department LIKE ?
                         OR BankIssue LIKE ? OR CAST(Amount AS VARCHAR(50)) LIKE ?)";
    array_push($ciParams, $term, $term, $term, $term, $term, $term, $term, $term);
}
if ($CIDateFrom !== '') {
    $ciConditions[] = "CheckDate >= ?";
    $ciParams[]     = $CIDateFrom;
}
if ($CIDateTo !== '') {
    $ciConditions[] = "CheckDate <= ?";
    $ciParams[]     = $CIDateTo;
}

$ciWhereSql = $ciConditions ? ('WHERE ' . implode(' AND ', $ciConditions)) : '';

// --- Department / Bank dropdown options ---
$ciDeptStmt = $pdo->query("SELECT DISTINCT Department FROM dbo.View_CheckIssuesList WHERE Department IS NOT NULL AND Department <> '' ORDER BY Department");
while ($row = $ciDeptStmt->fetch(PDO::FETCH_ASSOC)) {
    $ciDeptOptions[] = $row['Department'];
}
$ciBankStmt = $pdo->query("SELECT DISTINCT BankIssue FROM dbo.View_CheckIssuesList WHERE BankIssue IS NOT NULL AND BankIssue <> '' ORDER BY BankIssue");
while ($row = $ciBankStmt->fetch(PDO::FETCH_ASSOC)) {
    $ciBankOptions[] = $row['BankIssue'];
}

// --- Stat totals ---
$ciStatSql = "SELECT COUNT(*) AS TotalCount, ISNULL(SUM(Amount), 0) AS TotalAmount,
                      SUM(CASE WHEN ISNULL(Paid, 0) = 0 THEN 1 ELSE 0 END) AS TotalUnpaid,
                      SUM(CASE WHEN ISNULL(Paid, 0) = 1 THEN 1 ELSE 0 END) AS PaidCount,
                      ISNULL(SUM(CASE WHEN ISNULL(Paid, 0) = 1 THEN Amount ELSE 0 END), 0) AS PaidAmount,
                      ISNULL(SUM(CASE WHEN ISNULL(Paid, 0) = 0 THEN Amount ELSE 0 END), 0) AS UnpaidAmount
               FROM dbo.View_CheckIssuesList
               $ciWhereSql";
$ciStatStmt = $pdo->prepare($ciStatSql);
$ciStatStmt->execute($ciParams);
if ($row = $ciStatStmt->fetch(PDO::FETCH_ASSOC)) {
    $CITotalCount  = (int)$row['TotalCount'];
    $CITotalAmount = (float)$row['TotalAmount'];
    $CITotalUnpaid  = (int)$row['TotalUnpaid'];
    $CIPaidCount    = (int)$row['PaidCount'];
    $CIPaidAmount   = (float)$row['PaidAmount'];
    $CIUnpaidAmount = (float)$row['UnpaidAmount'];
}
$CITabCount   = ($ciTab === 'paid') ? $CIPaidCount : $CITotalUnpaid;
$CITotalPages = max(1, (int)ceil($CITabCount / $PageSize));
$ciTabCond      = ($ciTab === 'paid') ? "ISNULL(Paid, 0) = 1" : "ISNULL(Paid, 0) = 0";
$ciListWhereSql = ($ciWhereSql !== '') ? ($ciWhereSql . ' AND ' . $ciTabCond) : ('WHERE ' . $ciTabCond);

// --- Export CSV / Print view (all rows for the current tab + filters, no pagination) ---
$ciExport = (($_GET['export'] ?? '') === 'csv');
$ciPrint  = (($_GET['print'] ?? '') === '1');
if ($ciExport || $ciPrint) {
    $exStmt = $pdo->prepare("
        SELECT Department, SupplierName, ReferenceNo,
               CONVERT(varchar(10), CheckDate, 23) AS CheckDateFmt,
               CheckNo, BankIssue, Amount
        FROM dbo.View_CheckIssuesList
        $ciListWhereSql
        ORDER BY CheckDate DESC, ID DESC
    ");
    $exStmt->execute($ciParams);
    $exRows  = $exStmt->fetchAll(PDO::FETCH_ASSOC);
    $exTotal = 0.0;
    foreach ($exRows as $r) { $exTotal += (float)($r['Amount'] ?? 0); }
    $exLabel = ($ciTab === 'paid') ? 'Paid' : 'Unpaid';

    if ($ciExport) {
        // Guard against spreadsheet formula injection in text cells
        $csvSafe = function ($v) {
            $v = (string)($v ?? '');
            return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
        };
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="check-issues-' . strtolower($exLabel) . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads the peso/accents correctly
        fputcsv($out, ['Department', 'Supplier', 'Reference No.', 'Check Date', 'Check #', 'Bank', 'Amount', 'Status'], ',', '"', '');
        foreach ($exRows as $r) {
            fputcsv($out, [
                $csvSafe($r['Department']),
                $csvSafe($r['SupplierName']),
                $csvSafe($r['ReferenceNo']),
                $r['CheckDateFmt'],
                $csvSafe($r['CheckNo']),
                $csvSafe($r['BankIssue']),
                number_format((float)($r['Amount'] ?? 0), 2, '.', ''),
                $exLabel,
            ], ',', '"', '');
        }
        fputcsv($out, ['TOTAL', '', '', '', '', '', number_format($exTotal, 2, '.', ''), $exLabel . ' (' . count($exRows) . ' records)'], ',', '"', '');
        fclose($out);
        exit;
    }

    // Print view
    $exFilters = [];
    if ($CIDept !== '')     { $exFilters[] = 'Department: ' . $CIDept; }
    if ($CIBank !== '')     { $exFilters[] = 'Bank: ' . $CIBank; }
    if ($CIDateFrom !== '') { $exFilters[] = 'From: ' . $CIDateFrom; }
    if ($CIDateTo !== '')   { $exFilters[] = 'To: ' . $CIDateTo; }
    if ($CISearch !== '')   { $exFilters[] = 'Search: ' . $CISearch; }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Check Issues — <?php echo ci_h($exLabel); ?></title>
<style>
    @page { size: landscape; margin: 12mm; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 16px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    .meta { color: #555; margin-bottom: 12px; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
    th { background: #eee; font-size: 11px; text-transform: uppercase; }
    td.r, th.r { text-align: right; }
    tfoot td { font-weight: bold; background: #f5f5f5; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .no-print { margin-bottom: 12px; }
    .toolbar { display: flex; gap: 8px; }
    .btn {
        display: inline-block; padding: 6px 14px; font-size: 12px; font-weight: 600;
        font-family: Arial, Helvetica, sans-serif; color: #111; background: #fff;
        border: 1px solid #999; border-radius: 6px; text-decoration: none; cursor: pointer;
    }
    .btn:hover { background: #f0f0f0; }
    @media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="no-print toolbar">
        <a class="btn" href="<?php echo ci_qs(['print' => null]); ?>">&larr; Back</a>
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>
    <h1>Check Issues — <?php echo ci_h($exLabel); ?></h1>
    <div class="meta">
        Printed <?php echo ci_h(date('Y-m-d H:i')); ?>
        &middot; <?php echo count($exRows); ?> record(s)
        <?php if ($exFilters): ?>&middot; <?php echo ci_h(implode(' | ', $exFilters)); ?><?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th><th>Department</th><th>Supplier</th><th>Reference No.</th>
                <th>Check Date</th><th>Check #</th><th>Bank</th><th class="r">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($exRows): foreach ($exRows as $i => $r): ?>
            <tr>
                <td><?php echo $i + 1; ?></td>
                <td><?php echo ci_h($r['Department']); ?></td>
                <td><?php echo ci_h($r['SupplierName']); ?></td>
                <td><?php echo ci_h($r['ReferenceNo']); ?></td>
                <td><?php echo ci_h($r['CheckDateFmt']); ?></td>
                <td><?php echo ci_h($r['CheckNo']); ?></td>
                <td><?php echo ci_h($r['BankIssue']); ?></td>
                <td class="r"><?php echo number_format((float)($r['Amount'] ?? 0), 2); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="8" style="text-align:center;padding:20px;">No <?php echo ci_h(strtolower($exLabel)); ?> check issues found.</td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="7" class="r">Total (<?php echo ci_h($exLabel); ?>)</td><td class="r">&#8369;<?php echo number_format($exTotal, 2); ?></td></tr>
        </tfoot>
    </table>
    <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
<?php
    exit;
}
$Page         = min($Page, $CITotalPages);
$CIOffset     = ($Page - 1) * $PageSize;

// --- Paged data (full view columns — used for both the row and the View modal) ---
$ciDataSql = "
    SELECT ID, DID, Department, ReferenceNo,
           CONVERT(varchar(10), DocDate, 23) AS DocDateFmt,
           Remarks, AccountName,
           CONVERT(varchar(10), CheckDate, 23) AS CheckDateFmt,
           CheckNo, Amount, Words, UserID,
           CONVERT(varchar(16), DateTimeInput, 120) AS DateTimeInputFmt,
           BankIssue, Paid,
           SupplierName, Description, AccountNo, DepositSlip, Address, ContactNo, ContactPerson, IDSupplier
    FROM dbo.View_CheckIssuesList
    $ciListWhereSql
    ORDER BY CheckDate DESC, ID DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$ciStmt = $pdo->prepare($ciDataSql);
$paramIndex = 1;
foreach ($ciParams as $p) {
    $ciStmt->bindValue($paramIndex++, $p);
}
$ciStmt->bindValue($paramIndex++, $CIOffset, PDO::PARAM_INT);
$ciStmt->bindValue($paramIndex++, $PageSize, PDO::PARAM_INT);
$ciStmt->execute();
$ciRows = $ciStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/logo.png') ?>">
    <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title>Check Issues</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: #f4f5f7;
            color: #1a1d23;
            font-size: 14px;
            line-height: 1.5;
        }

        .chk-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

        .chk-page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px;
            border-bottom: 2px solid #e2e5ea;
        }
        .chk-dept-label {
            font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px;
        }
        .chk-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
        .chk-page-title span { color: #2563eb; }

        .chk-flash {
            border-radius: 10px; padding: 12px 16px; font-size: 13px;
            margin-bottom: 18px; border: 1.5px solid;
        }
        .chk-flash--success { background: #ecfdf3; border-color: #86efac; color: #15803d; }
        .chk-flash--error   { background: #fdecec; border-color: #f3b8b8; color: #8a1f1f; }

        .chk-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .chk-stat-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 16px 22px; min-width: 180px; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .chk-stat-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px;
        }
        .chk-stat-value { font-size: 22px; font-weight: 700; color: #111827; }
        .chk-stat-card.accent .chk-stat-value { color: #2563eb; }
        .chk-stat-card.warn .chk-stat-value { color: #b45309; }
        .chk-stat-card.ok .chk-stat-value { color: #15803d; }
        .chk-stat-sub { font-size: 12px; color: #6b7280; margin-top: 2px; font-family: 'IBM Plex Mono', monospace; }

        .chk-search-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 20px 24px; margin-bottom: 20px; display: flex; align-items: end;
            gap: 14px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .chk-field { display: flex; flex-direction: column; gap: 6px; }
        .chk-field label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #6b7280;
        }
        .chk-field input, .chk-field select {
            padding: 0 14px; height: 42px; border: 1.5px solid #d1d5db; border-radius: 9px;
            font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
            background: #f9fafb; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .chk-field input:focus, .chk-field select:focus {
            border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        .chk-btn {
            height: 42px; padding: 0 20px; border: none; border-radius: 9px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; white-space: nowrap; transition: background .15s, transform .1s;
        }
        .chk-btn:active { transform: scale(.98); }
        .chk-btn:disabled { opacity: .6; cursor: not-allowed; }
        .chk-btn--primary { background: #2563eb; color: #fff; }
        .chk-btn--primary:hover { background: #1d4ed8; }
        .chk-btn--ghost { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
        .chk-btn--ghost:hover { background: #f3f4f6; border-color: #9ca3af; }
        .chk-btn--sm { height: 32px; padding: 0 12px; font-size: 12px; }

        .chk-section {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .chk-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .chk-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .chk-table thead th {
            background: #f8f9fb; color: #4b5563; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 12px;
            border-bottom: 1.5px solid #e2e5ea; white-space: nowrap; text-align: left;
        }
        .chk-table thead th.r { text-align: right; }
        .chk-table tbody td {
            padding: 9px 12px; border-bottom: 1px solid #f1f3f7; color: #374151; vertical-align: middle;
        }
        .chk-table tbody td.r { text-align: right; font-family: 'IBM Plex Mono', monospace; }
        .chk-table tbody tr:last-child td { border-bottom: none; }
        .chk-table tbody tr:hover td { background: #f8f9fb; }
        .chk-actions { display: flex; gap: 6px; }

        .chk-badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .chk-badge--paid { background: #ecfdf3; color: #15803d; border: 1px solid #86efac; }
        .chk-badge--unpaid { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }

        .chk-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }

        .chk-tabs { display: flex; gap: 6px; margin-bottom: 16px; border-bottom: 2px solid #e2e5ea; }
        .chk-tab {
            display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
            font-size: 13px; font-weight: 600; color: #6b7280; text-decoration: none;
            border-bottom: 3px solid transparent; margin-bottom: -2px;
        }
        .chk-tab:hover { color: #111827; }
        .chk-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        .chk-tab-count {
            background: #f3f4f6; color: #4b5563; border-radius: 999px;
            padding: 1px 8px; font-size: 11px;
        }
        .chk-tab.active .chk-tab-count { background: #dbeafe; color: #1d4ed8; }
        .chk-btn--warn { background: #fff; color: #b45309; border: 1.5px solid #fcd34d; }
        .chk-btn--warn:hover { background: #fffbeb; border-color: #f59e0b; }
        .chk-tab-tools { display: flex; gap: 8px; align-items: center; margin-left: auto; order: 2; padding-bottom: 6px; }

        .chk-pagination { display: flex; gap: 6px; align-items: center; padding: 16px 22px; }
        .chk-pagination a, .chk-pagination span {
            padding: 6px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
            text-decoration: none; color: #374151; font-size: 12px; font-weight: 600;
        }
        .chk-pagination a:hover { background: #f3f4f6; }
        .chk-pagination .active { background: #2563eb; color: #fff; border-color: #1d4ed8; }

        /* ── Modals ─────────────────────────────── */
        .chk-modal-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(17,24,39,.5);
            z-index: 1000; align-items: center; justify-content: center; padding: 20px;
        }
        .chk-modal-backdrop.open { display: flex; }
        .chk-modal {
            background: #fff; border-radius: 14px; width: 100%; max-width: 460px;
            padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,.2);
            max-height: 90vh; overflow-y: auto;
        }
        .chk-modal h3 { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .chk-modal .chk-modal-sub { font-size: 12px; color: #6b7280; margin-bottom: 18px; }
        .chk-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 6px; }
        .chk-modal--wide { max-width: 620px; }
        .chk-detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; margin-bottom: 6px;
        }
        .chk-detail-grid .full { grid-column: 1 / -1; }
        .chk-detail-item .chk-detail-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #6b7280; margin-bottom: 3px;
        }
        .chk-detail-item .chk-detail-value { font-size: 13px; color: #111827; word-break: break-word; }
        .chk-detail-value--amount { font-size: 16px; font-weight: 600; }
        .chk-modal--pay .chk-modal-actions { margin-top: 18px; }
        @media (max-width: 560px) { .chk-detail-grid { grid-template-columns: 1fr; } }

        @media (max-width: 640px) {
            .chk-page { padding: 16px 12px 40px; }
            .chk-search-card { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="chk-page">

    <div class="chk-page-header">
        <div>
            <div class="chk-dept-label">ADMIN &nbsp;&middot; Check Issues</div>
            <h1 class="chk-page-title">Check <span>Issues</span></h1>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="chk-flash chk-flash--<?php echo ci_h($flash['type']); ?>">
        <?php echo ci_h($flash['msg']); ?>
    </div>
    <?php endif; ?>

    <div class="chk-stats">
        <div class="chk-stat-card">
            <div class="chk-stat-label">Total Records</div>
            <div class="chk-stat-value"><?php echo number_format($CITotalCount); ?></div>
        </div>
        <div class="chk-stat-card warn">
            <div class="chk-stat-label">Total Unpaid</div>
            <div class="chk-stat-value"><?php echo number_format($CITotalUnpaid); ?></div>
            <div class="chk-stat-sub">&#8369;<?php echo number_format($CIUnpaidAmount, 2); ?></div>
        </div>
        <div class="chk-stat-card ok">
            <div class="chk-stat-label">Paid Checks</div>
            <div class="chk-stat-value"><?php echo number_format($CIPaidCount); ?></div>
        </div>
        <div class="chk-stat-card ok">
            <div class="chk-stat-label">Total Paid Amount</div>
            <div class="chk-stat-value">&#8369;<?php echo number_format($CIPaidAmount, 2); ?></div>
        </div>
        <div class="chk-stat-card accent">
            <div class="chk-stat-label">Total Amount</div>
            <div class="chk-stat-value">&#8369;<?php echo number_format($CITotalAmount, 2); ?></div>
        </div>
    </div>

    <form method="get" class="chk-search-card">
        <input type="hidden" name="tab" value="<?php echo ci_h($ciTab); ?>">
        <div class="chk-field">
            <label for="CIDept">Department</label>
            <select id="CIDept" name="CIDept">
                <option value="" <?php echo $CIDept === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($ciDeptOptions as $opt): ?>
                <option value="<?php echo ci_h($opt); ?>" <?php echo $CIDept === $opt ? 'selected' : ''; ?>><?php echo ci_h($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="CIBank">Bank</label>
            <select id="CIBank" name="CIBank">
                <option value="" <?php echo $CIBank === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($ciBankOptions as $opt): ?>
                <option value="<?php echo ci_h($opt); ?>" <?php echo $CIBank === $opt ? 'selected' : ''; ?>><?php echo ci_h($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="CIDateFrom">Check Date From</label>
            <input type="date" id="CIDateFrom" name="CIDateFrom" value="<?php echo ci_h($CIDateFrom); ?>">
        </div>
        <div class="chk-field">
            <label for="CIDateTo">Check Date To</label>
            <input type="date" id="CIDateTo" name="CIDateTo" value="<?php echo ci_h($CIDateTo); ?>">
        </div>
        <div class="chk-field">
            <label for="CISearch">Search</label>
            <input type="text" id="CISearch" name="CISearch" value="<?php echo ci_h($CISearch); ?>" placeholder="Ref#, Supplier, Check#, Amount...">
        </div>
        <div class="chk-field">
            <button type="submit" class="chk-btn chk-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($CISearch || $CIBank || $CIDept || $CIDateFrom || $CIDateTo): ?>
        <div class="chk-field">
            <a href="check-issues.php?tab=<?php echo ci_h($ciTab); ?>" class="chk-btn chk-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <div class="chk-tabs">
        <div class="chk-tab-tools">
            <a class="chk-btn chk-btn--ghost chk-btn--sm" href="<?php echo ci_qs(['export' => 'csv', 'page' => null]); ?>"><i class="bi bi-download"></i> Export CSV</a>
            <a class="chk-btn chk-btn--ghost chk-btn--sm" href="<?php echo ci_qs(['print' => '1']); ?>"><i class="bi bi-printer"></i> Print</a>
        </div>
        <a class="chk-tab <?php echo $ciTab === 'unpaid' ? 'active' : ''; ?>" href="<?php echo ci_qs(['tab' => 'unpaid', 'page' => 1]); ?>">
            Unpaid <span class="chk-tab-count"><?php echo number_format($CITotalUnpaid); ?></span>
        </a>
        <a class="chk-tab <?php echo $ciTab === 'paid' ? 'active' : ''; ?>" href="<?php echo ci_qs(['tab' => 'paid', 'page' => 1]); ?>">
            Paid <span class="chk-tab-count"><?php echo number_format($CIPaidCount); ?></span>
        </a>
    </div>

    <div class="chk-section">
        <div class="chk-table-wrap">
            <table class="chk-table">
                <thead>
                    <tr>
                        <th>Actions</th>
                        <th>Department</th>
                        <th>Supplier</th>
                        <th>Reference No.</th>
                        <th>Check Date</th>
                        <th>Check #</th>
                        <th>Bank</th>
                        <th class="r">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($ciRows): foreach ($ciRows as $cr): ?>
                    <tr>
                        <td>
                            <div class="chk-actions">
                                <button type="button" class="chk-btn chk-btn--ghost chk-btn--sm ci-view-open" data-id="<?php echo (int)$cr['ID']; ?>">View</button>
                                <?php if (!$viewOnly && (int)$cr['Paid'] !== 1): ?>
                                <form method="post" action="check-issues.php<?php echo ci_qs(); ?>" class="ci-paid-form" style="display:inline;"
                                      data-id="<?php echo (int)$cr['ID']; ?>"
                                      data-supplier="<?php echo ci_h($cr['SupplierName']); ?>"
                                      data-dept="<?php echo ci_h($cr['Department']); ?>"
                                      data-check="<?php echo ci_h($cr['CheckNo']); ?>"
                                      data-bank="<?php echo ci_h($cr['BankIssue']); ?>"
                                      data-date="<?php echo ci_h($cr['CheckDateFmt']); ?>"
                                      data-amount="<?php echo ci_h('₱' . number_format((float)($cr['Amount'] ?? 0), 2)); ?>">
                                    <input type="hidden" name="form_action" value="mark_paid">
                                    <input type="hidden" name="csrf_token" value="<?php echo ci_h($csrfToken); ?>">
                                    <input type="hidden" name="CheckIssueID" value="<?php echo (int)$cr['ID']; ?>">
                                    <button type="submit" class="chk-btn chk-btn--primary chk-btn--sm">Mark Paid</button>
                                </form>
                                <?php elseif (!$viewOnly): ?>
                                <form method="post" action="check-issues.php<?php echo ci_qs(); ?>" class="ci-return-form" style="display:inline;"
                                      data-id="<?php echo (int)$cr['ID']; ?>"
                                      data-supplier="<?php echo ci_h($cr['SupplierName']); ?>"
                                      data-dept="<?php echo ci_h($cr['Department']); ?>"
                                      data-check="<?php echo ci_h($cr['CheckNo']); ?>"
                                      data-bank="<?php echo ci_h($cr['BankIssue']); ?>"
                                      data-date="<?php echo ci_h($cr['CheckDateFmt']); ?>"
                                      data-amount="<?php echo ci_h('₱' . number_format((float)($cr['Amount'] ?? 0), 2)); ?>">
                                    <input type="hidden" name="form_action" value="return_payment">
                                    <input type="hidden" name="csrf_token" value="<?php echo ci_h($csrfToken); ?>">
                                    <input type="hidden" name="CheckIssueID" value="<?php echo (int)$cr['ID']; ?>">
                                    <button type="submit" class="chk-btn chk-btn--warn chk-btn--sm">Return Payment</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo ci_h($cr['Department']); ?></td>
                        <td><?php echo ci_h($cr['SupplierName']); ?></td>
                        <td><?php echo ci_h($cr['ReferenceNo']); ?></td>
                        <td><?php echo ci_h($cr['CheckDateFmt']); ?></td>
                        <td><?php echo ci_h($cr['CheckNo']); ?></td>
                        <td><?php echo ci_h($cr['BankIssue']); ?></td>
                        <td class="r"><?php echo number_format((float)($cr['Amount'] ?? 0), 2); ?></td>
                        <td>
                            <?php if ((int)$cr['Paid'] === 1): ?>
                                <span class="chk-badge chk-badge--paid">Paid</span>
                            <?php else: ?>
                                <span class="chk-badge chk-badge--unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="9" class="chk-empty">No <?php echo $ciTab === 'paid' ? 'paid' : 'unpaid'; ?> check issues found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="chk-pagination">
            <?php if ($Page > 1): ?>
                <a href="<?php echo ci_qs(['page' => $Page - 1]); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span class="active"><?php echo $Page; ?> / <?php echo $CITotalPages; ?></span>
            <?php if ($Page < $CITotalPages): ?>
                <a href="<?php echo ci_qs(['page' => $Page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<!-- ── Check Issue View Modal ─────────────────────────────── -->
<div class="chk-modal-backdrop" id="ciViewBackdrop">
    <div class="chk-modal chk-modal--wide">
        <h3>Check Issue Details</h3>
        <p class="chk-modal-sub" id="ciViewSub">Record —</p>
        <div class="chk-detail-grid">
            <div class="chk-detail-item"><div class="chk-detail-label">Department</div><div class="chk-detail-value" id="ciD-Department"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Reference No.</div><div class="chk-detail-value" id="ciD-ReferenceNo"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Doc Date</div><div class="chk-detail-value" id="ciD-DocDateFmt"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check Date</div><div class="chk-detail-value" id="ciD-CheckDateFmt"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check #</div><div class="chk-detail-value" id="ciD-CheckNo"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Amount</div><div class="chk-detail-value" id="ciD-Amount"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Bank (Issue)</div><div class="chk-detail-value" id="ciD-BankIssue"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Status</div><div class="chk-detail-value" id="ciD-Paid"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Account Name</div><div class="chk-detail-value" id="ciD-AccountName"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Account No.</div><div class="chk-detail-value" id="ciD-AccountNo"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Supplier</div><div class="chk-detail-value" id="ciD-SupplierName"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Contact Person</div><div class="chk-detail-value" id="ciD-ContactPerson"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Contact No.</div><div class="chk-detail-value" id="ciD-ContactNo"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Deposit Slip</div><div class="chk-detail-value" id="ciD-DepositSlip"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Address</div><div class="chk-detail-value" id="ciD-Address"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Description</div><div class="chk-detail-value" id="ciD-Description"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Remarks</div><div class="chk-detail-value" id="ciD-Remarks"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Amount in Words</div><div class="chk-detail-value" id="ciD-Words"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Recorded By</div><div class="chk-detail-value" id="ciD-UserID"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Recorded At</div><div class="chk-detail-value" id="ciD-DateTimeInputFmt"></div></div>
        </div>
        <div class="chk-modal-actions">
            <button type="button" class="chk-btn chk-btn--ghost" id="ciViewClose">Close</button>
        </div>
    </div>
</div>

<?php if (!$viewOnly): ?>
<!-- ── Mark Paid Confirmation Modal ───────────────────────── -->
<div class="chk-modal-backdrop" id="ciPayBackdrop">
    <div class="chk-modal chk-modal--pay">
        <h3>Mark this check as paid?</h3>
        <p class="chk-modal-sub">Please confirm the details below. You can return the payment later from the Paid tab.</p>
        <div class="chk-detail-grid">
            <div class="chk-detail-item full"><div class="chk-detail-label">Supplier</div><div class="chk-detail-value" id="ciPay-supplier"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Department</div><div class="chk-detail-value" id="ciPay-dept"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check #</div><div class="chk-detail-value" id="ciPay-check"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Bank</div><div class="chk-detail-value" id="ciPay-bank"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check Date</div><div class="chk-detail-value" id="ciPay-date"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Amount</div><div class="chk-detail-value chk-detail-value--amount" id="ciPay-amount"></div></div>
        </div>
        <div class="chk-modal-actions">
            <button type="button" class="chk-btn chk-btn--ghost" id="ciPayCancel">Cancel</button>
            <button type="button" class="chk-btn chk-btn--primary" id="ciPayConfirm">Yes, Mark as Paid</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$viewOnly): ?>
<!-- ── Return Payment Confirmation Modal ──────────────────── -->
<div class="chk-modal-backdrop" id="ciReturnBackdrop">
    <div class="chk-modal chk-modal--pay">
        <h3>Return this payment?</h3>
        <p class="chk-modal-sub">This will set the check back to Unpaid and move it to the Unpaid tab.</p>
        <div class="chk-detail-grid">
            <div class="chk-detail-item full"><div class="chk-detail-label">Supplier</div><div class="chk-detail-value" id="ciReturn-supplier"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Department</div><div class="chk-detail-value" id="ciReturn-dept"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check #</div><div class="chk-detail-value" id="ciReturn-check"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Bank</div><div class="chk-detail-value" id="ciReturn-bank"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check Date</div><div class="chk-detail-value" id="ciReturn-date"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Amount</div><div class="chk-detail-value chk-detail-value--amount" id="ciReturn-amount"></div></div>
        </div>
        <div class="chk-modal-actions">
            <button type="button" class="chk-btn chk-btn--ghost" id="ciReturnCancel">Cancel</button>
            <button type="button" class="chk-btn chk-btn--warn" id="ciReturnConfirm">Yes, Return Payment</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script id="ciData" type="application/json"><?php echo json_encode($ciRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]'; ?></script>
<script>
(function () {
    // ── Record details for the View modal (embedded JSON, no extra request) ──
    var dataEl = document.getElementById('ciData');
    var rows = [];
    try { rows = JSON.parse((dataEl && dataEl.textContent) || '[]'); } catch (e) { rows = []; }
    var byId = {};
    rows.forEach(function (r) { byId[String(r.ID)] = r; });

    // ── View modal ──
    var viewBackdrop = document.getElementById('ciViewBackdrop');
    var viewSub      = document.getElementById('ciViewSub');
    var viewFields   = ['Department','ReferenceNo','DocDateFmt','CheckDateFmt','CheckNo','Amount','BankIssue','Paid',
                        'AccountName','AccountNo','SupplierName','ContactPerson','ContactNo','DepositSlip',
                        'Address','Description','Remarks','Words','UserID','DateTimeInputFmt'];

    document.querySelectorAll('.ci-view-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rec = byId[btn.getAttribute('data-id')];
            if (!rec) return;
            viewSub.textContent = 'Check #' + (rec.CheckNo || '') + ' — ' + (rec.SupplierName || rec.AccountName || '');
            viewFields.forEach(function (f) {
                var el = document.getElementById('ciD-' + f);
                if (!el) return;
                if (f === 'Amount') {
                    el.textContent = (rec.Amount !== null && rec.Amount !== undefined)
                        ? '₱' + Number(rec.Amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : '';
                } else if (f === 'Paid') {
                    el.textContent = (Number(rec.Paid) === 1) ? 'Paid' : 'Unpaid';
                } else {
                    el.textContent = rec[f] || '—';
                }
            });
            viewBackdrop.classList.add('open');
        });
    });

    function closeView() { viewBackdrop.classList.remove('open'); }
    document.getElementById('ciViewClose').addEventListener('click', closeView);
    viewBackdrop.addEventListener('click', function (e) { if (e.target === viewBackdrop) closeView(); });

    // ── Mark Paid confirmation (details come from the form's data-* attributes) ──
    var payBackdrop = document.getElementById('ciPayBackdrop'); // absent for view-only users
    var payForm     = null;

    function closePay() {
        if (!payBackdrop) return;
        payBackdrop.classList.remove('open');
        var btn = document.getElementById('ciPayConfirm');
        if (btn) btn.disabled = false;
        payForm = null;
    }

    if (payBackdrop) {
        document.querySelectorAll('.ci-paid-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var d = form.dataset;
                ['supplier', 'dept', 'check', 'bank', 'date', 'amount'].forEach(function (k) {
                    var el = document.getElementById('ciPay-' + k);
                    if (el) el.textContent = d[k] || '—';
                });
                payForm = form;
                payBackdrop.classList.add('open');
            });
        });

        document.getElementById('ciPayConfirm').addEventListener('click', function () {
            if (!payForm) return;
            this.disabled = true; // prevent double submit
            payForm.submit();     // regular POST: same handler, CSRF check and redirect
        });
        document.getElementById('ciPayCancel').addEventListener('click', closePay);
        payBackdrop.addEventListener('click', function (e) { if (e.target === payBackdrop) closePay(); });
    }

    // ── Return Payment confirmation ──
    var retBackdrop = document.getElementById('ciReturnBackdrop'); // absent for view-only users
    var retForm     = null;

    function closeReturn() {
        if (!retBackdrop) return;
        retBackdrop.classList.remove('open');
        var btn = document.getElementById('ciReturnConfirm');
        if (btn) btn.disabled = false;
        retForm = null;
    }

    if (retBackdrop) {
        document.querySelectorAll('.ci-return-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var d = form.dataset;
                ['supplier', 'dept', 'check', 'bank', 'date', 'amount'].forEach(function (k) {
                    var el = document.getElementById('ciReturn-' + k);
                    if (el) el.textContent = d[k] || '—';
                });
                retForm = form;
                retBackdrop.classList.add('open');
            });
        });

        document.getElementById('ciReturnConfirm').addEventListener('click', function () {
            if (!retForm) return;
            this.disabled = true; // prevent double submit
            retForm.submit();
        });
        document.getElementById('ciReturnCancel').addEventListener('click', closeReturn);
        retBackdrop.addEventListener('click', function (e) { if (e.target === retBackdrop) closeReturn(); });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeView();
        closePay();
        closeReturn();
    });
})();
</script>
</body>
</html>
