<?php
/**
 * cheques.php
 * TWM/FINANCE — Cheques
 * Source: dbo.Cheques (TransactionID, Department, Outlet, Salesman, Area,
 *         InvoiceNumber, InvoiceDate, Bank, Branch, CheckNumber, CheckDate,
 *         Amount, Terms, DateTime)
 * Return: dbo.ChequesReturn (CheckRID, CheckID -> Cheques.TransactionID,
 *         RDate, Type, Remarks, Action, UserID, DateTimeInput)
 *
 * View action links out to check-details.php?id=TransactionID.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'cheques');
$viewOnly = rbac_is_view_only('cheques');

date_default_timezone_set('Asia/Manila');

$Department  = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');
$CurrentUser = $_SESSION['EmployeeID'] ?? ($_SESSION['UserID'] ?? ($_SESSION['user_id'] ?? null));
$topbar_page = 'cheques';
$Tab = in_array(($_GET['tab'] ?? ''), ['returned', 'checkissues'], true) ? $_GET['tab'] : 'cheques';

if (empty($_SESSION['chk_csrf_token'])) {
    $_SESSION['chk_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['chk_csrf_token'];

// --- Handle "Record Return" submission (POST → redirect, preserves filters) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_return') {
    if ($viewOnly) {
        $_SESSION['chk_flash'] = ['type' => 'error', 'msg' => 'Your access is view-only.'];
    } elseif (!hash_equals($_SESSION['chk_csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['chk_flash'] = ['type' => 'error', 'msg' => 'Your session expired — please try again.'];
    } else {
        $checkId = (int)($_POST['CheckID'] ?? 0);
        $rDate   = trim($_POST['RDate'] ?? '');
        $type    = trim($_POST['Type'] ?? '');
        $remarks = trim($_POST['Remarks'] ?? '');
        $action  = trim($_POST['Action'] ?? '');

        if ($checkId > 0 && $rDate !== '' && $type !== '' && $action !== '' && $remarks !== '') {
            // ARRefNo is always 0 for a return recorded from this screen.
            $ins = $pdo->prepare(
                "INSERT INTO dbo.ChequesReturn (CheckID, RDate, Type, Remarks, Action, UserID, DateTimeInput, ARRefNo)
                 VALUES (?, ?, ?, ?, ?, ?, GETDATE(), 0)"
            );
            $ins->execute([$checkId, $rDate, $type, $remarks, $action, $CurrentUser]);
            $_SESSION['chk_flash'] = ['type' => 'success', 'msg' => "Return recorded for check #{$checkId}."];
        } else {
            $_SESSION['chk_flash'] = ['type' => 'error', 'msg' => 'All fields are required: Return Date, Type, Action Taken and Remarks.'];
        }
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: cheques.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Check Issues now lives in its own page/module (check-issues.php).
if ($Tab === 'checkissues') {
    header('Location: check-issues.php');
    exit;
}

$flash = $_SESSION['chk_flash'] ?? null;
unset($_SESSION['chk_flash']);

// --- Filter inputs (GET so pagination links preserve filters) ---
$Search   = trim($_GET['Search'] ?? '');
$Bank     = $_GET['Bank'] ?? '';
// Cheques tab: default to the last 1 year (by Check Date) on first load.
// Once the filter form is submitted, the date fields are used as-is
// (clearing both fields = all time).
$DateDefaulted = !isset($_GET['DateFrom']) && !isset($_GET['DateTo']);
$DateFrom = $DateDefaulted ? date('Y-m-d', strtotime('-1 year')) : ($_GET['DateFrom'] ?? '');
$DateTo   = $_GET['DateTo'] ?? '';
$Page     = max(1, (int)($_GET['page'] ?? 1));
$PageSize = 25;

$RSearch   = trim($_GET['RSearch'] ?? '');
$RType     = $_GET['RType'] ?? '';
$RDateFrom = $_GET['RDateFrom'] ?? '';
$RDateTo   = $_GET['RDateTo'] ?? '';

$CISearch   = trim($_GET['CISearch'] ?? '');
$CIBank     = $_GET['CIBank'] ?? '';
$CIDept     = $_GET['CIDept'] ?? '';
$CIDateFrom = $_GET['CIDateFrom'] ?? '';
$CIDateTo   = $_GET['CIDateTo'] ?? '';

$bankOptions = [];
$typeOptions = [];
$actionOptions = [];
$ciBankOptions = [];
$ciDeptOptions = [];
$ciRows = [];
$TotalCount = $TotalAmount = $TotalReturned = 0;
$RTotalCount = 0;
$CITotalCount = $CITotalAmount = $CITotalUnpaid = 0;
$TotalPages = $RTotalPages = $CITotalPages = 1;

// --- Existing Type/Action values from ChequesReturn (used for the Return modal's
//     datalists AND the Returned tab's Type filter) — fetched regardless of tab. ---
$typeStmt = $pdo->query("SELECT DISTINCT Type FROM dbo.ChequesReturn WHERE Type IS NOT NULL AND Type <> '' ORDER BY Type");
while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
    $typeOptions[] = $row['Type'];
}
$actionStmt = $pdo->query("SELECT DISTINCT Action FROM dbo.ChequesReturn WHERE Action IS NOT NULL AND Action <> '' ORDER BY Action");
while ($row = $actionStmt->fetch(PDO::FETCH_ASSOC)) {
    $actionOptions[] = $row['Action'];
}

if ($Tab === 'cheques') {

    // --- Build WHERE clause (parameterized) ---
    // NOTE: cheques are NOT scoped by session Department — this list is meant
    // to show all cheques across departments (finance-wide view), so
    // $_SESSION['Department'] is intentionally not used as a filter here.
    $conditions = [];
    $params     = [];

    if ($Bank !== '') {
        $conditions[] = "c.Bank = ?";
        $params[]     = $Bank;
    }
    if ($Search !== '') {
        $term = '%' . $Search . '%';
        $conditions[] = "(CAST(c.TransactionID AS VARCHAR(50)) LIKE ? OR CAST(c.CheckNumber AS VARCHAR(50)) LIKE ?
                           OR CAST(c.InvoiceNumber AS VARCHAR(50)) LIKE ? OR c.Outlet LIKE ? OR c.Salesman LIKE ?
                           OR c.Area LIKE ? OR c.Bank LIKE ? OR c.Branch LIKE ? OR CAST(c.Amount AS VARCHAR(50)) LIKE ?)";
        array_push($params, $term, $term, $term, $term, $term, $term, $term, $term, $term);
    }
    if ($DateFrom !== '') {
        $conditions[] = "c.CheckDate >= ?";
        $params[]     = $DateFrom;
    }
    if ($DateTo !== '') {
        $conditions[] = "c.CheckDate <= ?";
        $params[]     = $DateTo;
    }

    $whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    $joinSql = "LEFT JOIN (
                    SELECT CheckID, COUNT(*) AS ReturnCount
                    FROM dbo.ChequesReturn
                    GROUP BY CheckID
                ) r ON r.CheckID = c.TransactionID";

    // --- Bank dropdown options (all banks, not department-scoped) ---
    $bankSql = "SELECT DISTINCT Bank FROM dbo.Cheques
                WHERE Bank IS NOT NULL AND Bank <> ''
                ORDER BY Bank";
    $bankStmt = $pdo->prepare($bankSql);
    $bankStmt->execute();
    while ($row = $bankStmt->fetch(PDO::FETCH_ASSOC)) {
        $bankOptions[] = $row['Bank'];
    }

    // --- Stat totals (same filters) ---
    $statSql = "SELECT COUNT(*) AS TotalCount, ISNULL(SUM(c.Amount), 0) AS TotalAmount,
                       SUM(CASE WHEN r.ReturnCount > 0 THEN 1 ELSE 0 END) AS TotalReturned
                FROM dbo.Cheques c
                $joinSql
                $whereSql";
    $statStmt = $pdo->prepare($statSql);
    $statStmt->execute($params);
    if ($row = $statStmt->fetch(PDO::FETCH_ASSOC)) {
        $TotalCount    = (int)$row['TotalCount'];
        $TotalAmount   = (float)$row['TotalAmount'];
        $TotalReturned = (int)$row['TotalReturned'];
    }

    $TotalPages = max(1, (int)ceil($TotalCount / $PageSize));
    $Page       = min($Page, $TotalPages);
    $Offset     = ($Page - 1) * $PageSize;

    // --- Paged data ---
    $dataSql = "
        SELECT c.TransactionID, c.Department, c.Outlet,
               c.Bank, c.Branch, c.CheckNumber,
               CONVERT(varchar(10), c.CheckDate, 23) AS CheckDateFmt,
               c.Amount, c.Terms,
               ISNULL(r.ReturnCount, 0) AS ReturnCount
        FROM dbo.Cheques c
        $joinSql
        $whereSql
        ORDER BY c.CheckDate DESC
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
    $stmt = $pdo->prepare($dataSql);
    $paramIndex = 1;
    foreach ($params as $p) {
        $stmt->bindValue($paramIndex++, $p);
    }
    $stmt->bindValue($paramIndex++, $Offset, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $PageSize, PDO::PARAM_INT);
    $stmt->execute();

} elseif ($Tab === 'returned') {

    // --- Returned tab: dbo.ChequesReturn joined back to dbo.Cheques for context ---
    $rConditions = [];
    $rParams     = [];

    if ($RType !== '') {
        $rConditions[] = "cr.Type = ?";
        $rParams[]     = $RType;
    }
    if ($RSearch !== '') {
        $term = '%' . $RSearch . '%';
        $rConditions[] = "(CAST(cr.CheckID AS VARCHAR(50)) LIKE ? OR CAST(c.CheckNumber AS VARCHAR(50)) LIKE ?
                            OR c.Outlet LIKE ? OR c.Bank LIKE ? OR cr.Type LIKE ? OR cr.Action LIKE ? OR cr.Remarks LIKE ?)";
        array_push($rParams, $term, $term, $term, $term, $term, $term, $term);
    }
    if ($RDateFrom !== '') {
        $rConditions[] = "cr.RDate >= ?";
        $rParams[]     = $RDateFrom;
    }
    if ($RDateTo !== '') {
        $rConditions[] = "cr.RDate <= ?";
        $rParams[]     = $RDateTo;
    }

        $rWhereSql = $rConditions ? ('WHERE ' . implode(' AND ', $rConditions)) : '';

    // --- Export (CSV) / Print: full filtered set, ignores pagination ---
    $rExport = $_GET['export'] ?? '';
    $rPrint  = ($_GET['print'] ?? '') === '1';
    if ($rExport === 'csv' || $rPrint) {
        $allStmt = $pdo->prepare("
            SELECT cr.CheckRID, cr.CheckID,
                   CONVERT(varchar(10), cr.RDate, 23) AS RDateFmt,
                   cr.Type, cr.Remarks, cr.Action, cr.UserID, cr.ARRefNo,
                   CONVERT(varchar(16), cr.DateTimeInput, 120) AS DateTimeInputFmt,
                   c.Outlet, c.Bank, c.CheckNumber, c.Amount, c.Department
            FROM dbo.ChequesReturn cr
            LEFT JOIN dbo.Cheques c ON c.TransactionID = cr.CheckID
            $rWhereSql
            ORDER BY c.Outlet, cr.CheckID, cr.RDate, cr.CheckRID
        ");
        $allStmt->execute($rParams);
        $allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC);

        // Return counts, based on the rows matching the current filters
        $olKey = function ($r) {
            return trim((string)($r['Outlet'] ?? '')) !== '' ? $r['Outlet'] : '(No outlet on record)';
        };
        $checkCounts = [];
        $outletStats = [];
        foreach ($allRows as $ar) {
            $ck = $ar['CheckID'];
            $ol = $olKey($ar);
            $checkCounts[$ck] = ($checkCounts[$ck] ?? 0) + 1;
            if (!isset($outletStats[$ol])) {
                $outletStats[$ol] = ['returns' => 0, 'checks' => [], 'amount' => 0.0];
            }
            $outletStats[$ol]['returns']++;
            if (!isset($outletStats[$ol]['checks'][$ck])) {
                $outletStats[$ol]['checks'][$ck] = true;
                $outletStats[$ol]['amount'] += (float)($ar['Amount'] ?? 0);
            }
        }
        uasort($outletStats, function ($a, $b) { return $b['returns'] <=> $a['returns']; });

        // ---- CSV export ----
        if ($rExport === 'csv') {
            $csvText = function ($v) {
                $v = (string)($v ?? '');
                // Prevent spreadsheet formula injection from free-text fields
                return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
            };
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="returned_cheques_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
            fputcsv($out, [
                'Return ID', 'Ref# (Check ID)', 'Department', 'Outlet', 'Bank', 'Check #', 'Amount',
                'Return Date', 'Type', 'Action Taken', 'Remarks', 'AR Ref#', 'Recorded By', 'Recorded At',
                'Times This Check Returned', 'Total Returns for Outlet'
            ], ',', '"', '\\');
            foreach ($allRows as $ar) {
                $ckNo = (string)($ar['CheckNumber'] ?? '');
                if (preg_match('/^0\d+$/', $ckNo)) {
                    $ckNo = '="' . $ckNo . '"'; // keeps leading zeros when opened in Excel
                } else {
                    $ckNo = $csvText($ckNo);
                }
                fputcsv($out, [
                    $ar['CheckRID'],
                    $ar['CheckID'],
                    $csvText($ar['Department']),
                    $csvText($ar['Outlet']),
                    $csvText($ar['Bank']),
                    $ckNo,
                    $ar['Amount'] !== null ? number_format((float)$ar['Amount'], 2, '.', '') : '',
                    $ar['RDateFmt'],
                    $csvText($ar['Type']),
                    $csvText($ar['Action']),
                    $csvText($ar['Remarks']),
                    $ar['ARRefNo'] ?? '',
                    $csvText($ar['UserID']),
                    $ar['DateTimeInputFmt'],
                    $checkCounts[$ar['CheckID']],
                    $outletStats[$olKey($ar)]['returns'],
                ], ',', '"', '\\');
            }
            fclose($out);
            exit;
        }

        // ---- Print view (standalone page, opens the print dialog automatically) ----
        $filterBits = [];
        if ($RType !== '')     $filterBits[] = 'Type: ' . $RType;
        if ($RDateFrom !== '') $filterBits[] = 'Return Date From: ' . $RDateFrom;
        if ($RDateTo !== '')   $filterBits[] = 'Return Date To: ' . $RDateTo;
        if ($RSearch !== '')   $filterBits[] = 'Search: ' . $RSearch;
        $filterText = $filterBits ? implode('  |  ', $filterBits) : 'None (all returns)';
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Returned Cheques Report</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 24px; }
    h1 { font-size: 18px; margin: 0 0 4px; }
    h2 { font-size: 14px; margin: 20px 0 6px; }
    .meta { color: #555; margin-bottom: 6px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #eee; }
    .r { text-align: right; }
    .noprint { margin-bottom: 16px; }
    @media print {
        .noprint { display: none; }
        body { margin: 0; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    }
</style>
</head>
<body>
<div class="noprint">
    <button onclick="window.print()">Print</button>
    <button onclick="window.close()">Close</button>
</div>

<h1>Returned Cheques Report</h1>
<div class="meta">Generated: <?php echo date('M j, Y g:i A'); ?> &nbsp;|&nbsp; Filters: <?php echo htmlspecialchars($filterText); ?></div>
<div class="meta">
    <strong>Total returns:</strong> <?php echo number_format(count($allRows)); ?> &nbsp;|&nbsp;
    <strong>Distinct checks returned:</strong> <?php echo number_format(count($checkCounts)); ?> &nbsp;|&nbsp;
    <strong>Outlets:</strong> <?php echo number_format(count($outletStats)); ?>
</div>

<h2>Returns per Outlet</h2>
<table>
    <thead>
        <tr>
            <th>Outlet</th>
            <th class="r">Times Returned</th>
            <th class="r">Distinct Checks</th>
            <th class="r">Total Check Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($outletStats): foreach ($outletStats as $olName => $st): ?>
        <tr>
            <td><?php echo htmlspecialchars($olName); ?></td>
            <td class="r"><?php echo (int)$st['returns']; ?></td>
            <td class="r"><?php echo count($st['checks']); ?></td>
            <td class="r"><?php echo number_format($st['amount'], 2); ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="4">No returns found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<h2>Return Details</h2>
<table>
    <thead>
        <tr>
            <th>Ref#</th>
            <th>Outlet</th>
            <th>Bank</th>
            <th>Check#</th>
            <th class="r">Amount</th>
            <th>Return Date</th>
            <th>Type</th>
            <th>Action Taken</th>
            <th>Remarks</th>
            <th>AR Ref#</th>
            <th>Recorded By</th>
            <th class="r">Times This Check Returned</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($allRows): foreach ($allRows as $ar): ?>
        <tr>
            <td><?php echo htmlspecialchars($ar['CheckID']); ?></td>
            <td><?php echo htmlspecialchars($ar['Outlet']); ?></td>
            <td><?php echo htmlspecialchars($ar['Bank']); ?></td>
            <td><?php echo htmlspecialchars($ar['CheckNumber']); ?></td>
            <td class="r"><?php echo $ar['Amount'] !== null ? number_format($ar['Amount'], 2) : ''; ?></td>
            <td><?php echo htmlspecialchars($ar['RDateFmt']); ?></td>
            <td><?php echo htmlspecialchars($ar['Type']); ?></td>
            <td><?php echo htmlspecialchars($ar['Action']); ?></td>
            <td><?php echo htmlspecialchars($ar['Remarks']); ?></td>
            <td><?php echo htmlspecialchars($ar['ARRefNo'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($ar['UserID']); ?></td>
            <td class="r"><?php echo (int)$checkCounts[$ar['CheckID']]; ?></td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="12">No returns found.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<script>window.onload = function () { window.print(); };</script>
</body>
</html>
<?php
        exit;
    }

    // --- Stat total ---
    $rStatSql = "SELECT COUNT(*) AS TotalCount
                 FROM dbo.ChequesReturn cr
                 LEFT JOIN dbo.Cheques c ON c.TransactionID = cr.CheckID
                 $rWhereSql";
    $rStatStmt = $pdo->prepare($rStatSql);
    $rStatStmt->execute($rParams);
    if ($row = $rStatStmt->fetch(PDO::FETCH_ASSOC)) {
        $RTotalCount = (int)$row['TotalCount'];
    }

    $RTotalPages = max(1, (int)ceil($RTotalCount / $PageSize));
    $Page        = min($Page, $RTotalPages);
    $ROffset     = ($Page - 1) * $PageSize;

    // --- Paged data ---
    $rDataSql = "
        SELECT cr.CheckRID, cr.CheckID,
               CONVERT(varchar(10), cr.RDate, 23) AS RDateFmt,
               cr.Type, cr.Remarks, cr.Action, cr.UserID, cr.ARRefNo,
               CONVERT(varchar(16), cr.DateTimeInput, 120) AS DateTimeInputFmt,
               c.Outlet, c.Bank, c.CheckNumber, c.Amount, c.Department
        FROM dbo.ChequesReturn cr
        LEFT JOIN dbo.Cheques c ON c.TransactionID = cr.CheckID
        $rWhereSql
        ORDER BY cr.DateTimeInput DESC
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
    $rStmt = $pdo->prepare($rDataSql);
    $paramIndex = 1;
    foreach ($rParams as $p) {
        $rStmt->bindValue($paramIndex++, $p);
    }
    $rStmt->bindValue($paramIndex++, $ROffset, PDO::PARAM_INT);
    $rStmt->bindValue($paramIndex++, $PageSize, PDO::PARAM_INT);
    $rStmt->execute();

} else {

    // --- Check Issues List tab: dbo.View_CheckIssuesList for viewing (richer
    //     supplier/account context), updates are written to dbo.TBL_CheckIssues
    //     (the base table) keyed on ID. Only action here is marking Paid = 1. ---
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
                          SUM(CASE WHEN ISNULL(Paid, 0) = 0 THEN 1 ELSE 0 END) AS TotalUnpaid
                   FROM dbo.View_CheckIssuesList
                   $ciWhereSql";
    $ciStatStmt = $pdo->prepare($ciStatSql);
    $ciStatStmt->execute($ciParams);
    if ($row = $ciStatStmt->fetch(PDO::FETCH_ASSOC)) {
        $CITotalCount  = (int)$row['TotalCount'];
        $CITotalAmount = (float)$row['TotalAmount'];
        $CITotalUnpaid = (int)$row['TotalUnpaid'];
    }

    $CITotalPages = max(1, (int)ceil($CITotalCount / $PageSize));
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
        $ciWhereSql
        ORDER BY CheckDate DESC
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
}

// Helper to preserve filters across pagination links
function chk_qs($overrides = []) {
    $current = $_GET;
    foreach ($overrides as $k => $v) { $current[$k] = $v; }
    return htmlspecialchars('?' . http_build_query($current));
}
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
    <title>Cheques</title>
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

        .chk-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1.5px solid #e2e5ea; }
        .chk-tab {
            padding: 10px 18px; font-size: 13px; font-weight: 600; color: #6b7280;
            text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1.5px;
            transition: color .15s, border-color .15s;
        }
        .chk-tab:hover { color: #374151; }
        .chk-tab.active { color: #2563eb; border-bottom-color: #2563eb; }

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
        .chk-field input, .chk-field select, .chk-field textarea {
            padding: 0 14px; height: 42px; border: 1.5px solid #d1d5db; border-radius: 9px;
            font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
            background: #f9fafb; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .chk-field textarea { height: auto; padding: 10px 14px; font-family: 'IBM Plex Sans', sans-serif; resize: vertical; }
        .chk-field input:focus, .chk-field select:focus, .chk-field textarea:focus {
            border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        .chk-btn {
            height: 42px; padding: 0 20px; border: none; border-radius: 9px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; white-space: nowrap; transition: background .15s, transform .1s;
        }
        .chk-btn:active { transform: scale(.98); }
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
        .chk-badge--returned { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .chk-badge--paid { background: #ecfdf3; color: #15803d; border: 1px solid #86efac; }
        .chk-badge--unpaid { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }

        .chk-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }

        .chk-pagination { display: flex; gap: 6px; align-items: center; padding: 16px 22px; }
        .chk-pagination a, .chk-pagination span {
            padding: 6px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
            text-decoration: none; color: #374151; font-size: 12px; font-weight: 600;
        }
        .chk-pagination a:hover { background: #f3f4f6; }
        .chk-pagination .active { background: #2563eb; color: #fff; border-color: #1d4ed8; }

        /* ── Return Modal ─────────────────────────────── */
        .chk-modal-backdrop {
            display: none; position: fixed; inset: 0; background: rgba(17,24,39,.5);
            z-index: 1000; align-items: center; justify-content: center; padding: 20px;
        }
        .chk-modal-backdrop.open { display: flex; }
        .chk-modal {
            background: #fff; border-radius: 14px; width: 100%; max-width: 460px;
            padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,.2);
        }
        .chk-modal h3 { font-size: 18px; font-weight: 600; margin-bottom: 4px; }
        .chk-modal .chk-modal-sub { font-size: 12px; color: #6b7280; margin-bottom: 18px; }
        .chk-modal form { display: flex; flex-direction: column; gap: 14px; }
        .chk-modal .chk-field { width: 100%; }
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
            <div class="chk-dept-label"><?php echo htmlspecialchars($Department); ?> &nbsp;&middot; Cheques</div>
            <h1 class="chk-page-title">Cheque <span>Records</span></h1>
        </div>
    </div>

    <div class="chk-tabs">
        <a href="cheques.php" class="chk-tab <?php echo $Tab === 'cheques' ? 'active' : ''; ?>">Checks</a>
        <a href="cheques.php?tab=returned" class="chk-tab <?php echo $Tab === 'returned' ? 'active' : ''; ?>">Returned Checks</a>
    </div>

    <?php if ($flash): ?>
    <div class="chk-flash chk-flash--<?php echo htmlspecialchars($flash['type']); ?>">
        <?php echo htmlspecialchars($flash['msg']); ?>
    </div>
    <?php endif; ?>

    <?php if ($Tab === 'cheques'): ?>
    <div class="chk-stats">
        <div class="chk-stat-card">
            <div class="chk-stat-label">Total Checks</div>
            <div class="chk-stat-value"><?php echo number_format($TotalCount); ?></div>
        </div>
        <div class="chk-stat-card accent">
            <div class="chk-stat-label">Total Amount</div>
            <div class="chk-stat-value">&#8369;<?php echo number_format($TotalAmount, 2); ?></div>
        </div>
        <div class="chk-stat-card warn">
            <div class="chk-stat-label">Returned</div>
            <div class="chk-stat-value"><?php echo number_format($TotalReturned); ?></div>
        </div>
    </div>

    <form method="get" class="chk-search-card">
        <div class="chk-field">
            <label for="Bank">Bank</label>
            <select id="Bank" name="Bank">
                <option value="" <?php echo $Bank === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($bankOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $Bank === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="DateFrom">Check Date From</label>
            <input type="date" id="DateFrom" name="DateFrom" value="<?php echo htmlspecialchars($DateFrom); ?>">
        </div>
        <div class="chk-field">
            <label for="DateTo">Check Date To</label>
            <input type="date" id="DateTo" name="DateTo" value="<?php echo htmlspecialchars($DateTo); ?>">
        </div>
        <div class="chk-field">
            <label for="Search">Search</label>
            <input type="text" id="Search" name="Search" value="<?php echo htmlspecialchars($Search); ?>" placeholder="Ref#, Invoice, Outlet, Salesman, Amount...">
        </div>
        <div class="chk-field">
            <button type="submit" class="chk-btn chk-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($Search || $Bank || !$DateDefaulted): ?>
        <div class="chk-field">
            <a href="cheques.php" class="chk-btn chk-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
        <?php if ($DateDefaulted): ?>
        <div style="flex-basis:100%;font-size:12px;color:#6b7280;">
            Showing cheques dated <?php echo htmlspecialchars(date('M j, Y', strtotime($DateFrom))); ?> onward (default: last 1 year).
            Clear the date fields and press Filter to see all time.
        </div>
        <?php endif; ?>
    </form>

    <div class="chk-section">
        <div class="chk-table-wrap">
            <table class="chk-table">
                <thead>
                    <tr>
                        <th>Actions</th>
                        <th>Department</th>
                        <th>Outlet</th>
                        <th>Bank</th>
                        <th>Branch</th>
                        <th>Check #</th>
                        <th>Check Date</th>
                        <th class="r">Amount</th>
                        <th>Terms</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($TotalCount > 0): while ($r = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td>
                            <div class="chk-actions">
                                <a class="chk-btn chk-btn--ghost chk-btn--sm" href="check-details.php?id=<?php echo urlencode($r['TransactionID']); ?>" target="_blank">View</a>
                                <?php if (!$viewOnly): ?>
                                <button type="button" class="chk-btn chk-btn--primary chk-btn--sm chk-return-open"
                                        data-id="<?php echo htmlspecialchars($r['TransactionID']); ?>"
                                        data-ref="<?php echo htmlspecialchars($r['TransactionID'] . ' — ' . $r['Outlet']); ?>">
                                    Return
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($r['Department']); ?></td>
                        <td><?php echo htmlspecialchars($r['Outlet']); ?></td>
                        <td><?php echo htmlspecialchars($r['Bank']); ?></td>
                        <td><?php echo htmlspecialchars($r['Branch']); ?></td>
                        <td><?php echo htmlspecialchars($r['CheckNumber']); ?></td>
                        <td><?php echo htmlspecialchars($r['CheckDateFmt']); ?></td>
                        <td class="r"><?php echo number_format($r['Amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($r['Terms']); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="9" class="chk-empty">No cheques found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="chk-pagination">
            <?php if ($Page > 1): ?>
                <a href="<?php echo chk_qs(['page' => $Page - 1]); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span class="active"><?php echo $Page; ?> / <?php echo $TotalPages; ?></span>
            <?php if ($Page < $TotalPages): ?>
                <a href="<?php echo chk_qs(['page' => $Page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($Tab === 'returned'): ?>

    <div class="chk-stats">
        <div class="chk-stat-card warn">
            <div class="chk-stat-label">Total Returns</div>
            <div class="chk-stat-value"><?php echo number_format($RTotalCount); ?></div>
        </div>
    </div>

    <form method="get" class="chk-search-card">
        <input type="hidden" name="tab" value="returned">
        <div class="chk-field">
            <label for="RType">Type</label>
            <select id="RType" name="RType">
                <option value="" <?php echo $RType === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($typeOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $RType === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="RDateFrom">Return Date From</label>
            <input type="date" id="RDateFrom" name="RDateFrom" value="<?php echo htmlspecialchars($RDateFrom); ?>">
        </div>
        <div class="chk-field">
            <label for="RDateTo">Return Date To</label>
            <input type="date" id="RDateTo" name="RDateTo" value="<?php echo htmlspecialchars($RDateTo); ?>">
        </div>
        <div class="chk-field">
            <label for="RSearch">Search</label>
            <input type="text" id="RSearch" name="RSearch" value="<?php echo htmlspecialchars($RSearch); ?>" placeholder="Ref#, Check#, Outlet, Bank...">
        </div>
        <div class="chk-field">
            <button type="submit" class="chk-btn chk-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($RSearch || $RType || $RDateFrom || $RDateTo): ?>
        <div class="chk-field">
            <a href="cheques.php?tab=returned" class="chk-btn chk-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
        <div class="chk-field">
            <a href="cheques.php<?php echo chk_qs(['export' => 'csv', 'page' => null]); ?>" class="chk-btn chk-btn--ghost"><i class="bi bi-download"></i> Export CSV</a>
        </div>
        <div class="chk-field">
            <a href="cheques.php<?php echo chk_qs(['print' => '1', 'page' => null]); ?>" target="_blank" class="chk-btn chk-btn--ghost"><i class="bi bi-printer"></i> Print</a>
        </div>
    </form>

    <div class="chk-section">
        <div class="chk-table-wrap">
            <table class="chk-table">
                <thead>
                    <tr>
                        <th>Return ID</th>
                        <th>Ref# (Check ID)</th>
                        <th>Outlet</th>
                        <th>Bank</th>
                        <th>Check#</th>
                        <th class="r">Amount</th>
                        <th>Return Date</th>
                        <th>Type</th>
                        <th>Action Taken</th>
                        <th>Remarks</th>
                        <th>AR Ref#</th>
                        <th>Recorded By</th>
                        <th>Recorded At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($RTotalCount > 0): while ($rr = $rStmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($rr['CheckRID']); ?></td>
                        <td><a href="check-details.php?id=<?php echo urlencode($rr['CheckID']); ?>" target="_blank"><?php echo htmlspecialchars($rr['CheckID']); ?></a></td>
                        <td><?php echo htmlspecialchars($rr['Outlet']); ?></td>
                        <td><?php echo htmlspecialchars($rr['Bank']); ?></td>
                        <td><?php echo htmlspecialchars($rr['CheckNumber']); ?></td>
                        <td class="r"><?php echo $rr['Amount'] !== null ? number_format($rr['Amount'], 2) : ''; ?></td>
                        <td><?php echo htmlspecialchars($rr['RDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($rr['Type']); ?></td>
                        <td><?php echo htmlspecialchars($rr['Action']); ?></td>
                        <td><?php echo htmlspecialchars($rr['Remarks']); ?></td>
                        <td><?php echo htmlspecialchars($rr['ARRefNo'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($rr['UserID']); ?></td>
                        <td><?php echo htmlspecialchars($rr['DateTimeInputFmt']); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="13" class="chk-empty">No returned cheques found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="chk-pagination">
            <?php if ($Page > 1): ?>
                <a href="<?php echo chk_qs(['page' => $Page - 1]); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span class="active"><?php echo $Page; ?> / <?php echo $RTotalPages; ?></span>
            <?php if ($Page < $RTotalPages): ?>
                <a href="<?php echo chk_qs(['page' => $Page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>

    <div class="chk-stats">
        <div class="chk-stat-card">
            <div class="chk-stat-label">Total Records</div>
            <div class="chk-stat-value"><?php echo number_format($CITotalCount); ?></div>
        </div>
        <div class="chk-stat-card accent">
            <div class="chk-stat-label">Total Amount</div>
            <div class="chk-stat-value">&#8369;<?php echo number_format($CITotalAmount, 2); ?></div>
        </div>
        <div class="chk-stat-card warn">
            <div class="chk-stat-label">Unpaid</div>
            <div class="chk-stat-value"><?php echo number_format($CITotalUnpaid); ?></div>
        </div>
    </div>

    <form method="get" class="chk-search-card">
        <input type="hidden" name="tab" value="checkissues">
        <div class="chk-field">
            <label for="CIDept">Department</label>
            <select id="CIDept" name="CIDept">
                <option value="" <?php echo $CIDept === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($ciDeptOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $CIDept === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="CIBank">Bank</label>
            <select id="CIBank" name="CIBank">
                <option value="" <?php echo $CIBank === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($ciBankOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $CIBank === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="chk-field">
            <label for="CIDateFrom">Check Date From</label>
            <input type="date" id="CIDateFrom" name="CIDateFrom" value="<?php echo htmlspecialchars($CIDateFrom); ?>">
        </div>
        <div class="chk-field">
            <label for="CIDateTo">Check Date To</label>
            <input type="date" id="CIDateTo" name="CIDateTo" value="<?php echo htmlspecialchars($CIDateTo); ?>">
        </div>
        <div class="chk-field">
            <label for="CISearch">Search</label>
            <input type="text" id="CISearch" name="CISearch" value="<?php echo htmlspecialchars($CISearch); ?>" placeholder="Ref#, Supplier, Check#, Amount...">
        </div>
        <div class="chk-field">
            <button type="submit" class="chk-btn chk-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($CISearch || $CIBank || $CIDept || $CIDateFrom || $CIDateTo): ?>
        <div class="chk-field">
            <a href="cheques.php?tab=checkissues" class="chk-btn chk-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
    </form>

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
                                <button type="button" class="chk-btn chk-btn--ghost chk-btn--sm chk-ci-view-open" data-id="<?php echo (int)$cr['ID']; ?>">View</button>
                                <?php if (!$viewOnly && !((int)$cr['Paid'] === 1)): ?>
                                <form method="post" action="cheques.php<?php echo chk_qs(); ?>" data-id="<?php echo (int)$cr['ID']; ?>" style="display:inline;">
                                    <input type="hidden" name="form_action" value="mark_paid">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="CheckIssueID" value="<?php echo (int)$cr['ID']; ?>">
                                    <button type="submit" class="chk-btn chk-btn--primary chk-btn--sm">Mark Paid</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($cr['Department']); ?></td>
                        <td><?php echo htmlspecialchars($cr['SupplierName']); ?></td>
                        <td><?php echo htmlspecialchars($cr['ReferenceNo']); ?></td>
                        <td><?php echo htmlspecialchars($cr['CheckDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($cr['CheckNo']); ?></td>
                        <td><?php echo htmlspecialchars($cr['BankIssue']); ?></td>
                        <td class="r"><?php echo number_format($cr['Amount'], 2); ?></td>
                        <td>
                            <?php if ((int)$cr['Paid'] === 1): ?>
                                <span class="chk-badge chk-badge--paid">Paid</span>
                            <?php else: ?>
                                <span class="chk-badge chk-badge--unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="9" class="chk-empty">No check issues found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="chk-pagination">
            <?php if ($Page > 1): ?>
                <a href="<?php echo chk_qs(['page' => $Page - 1]); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span class="active"><?php echo $Page; ?> / <?php echo $CITotalPages; ?></span>
            <?php if ($Page < $CITotalPages): ?>
                <a href="<?php echo chk_qs(['page' => $Page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>


<?php if (!$viewOnly): ?>
<!-- ── Return Modal ─────────────────────────────── -->
<div class="chk-modal-backdrop" id="chkReturnBackdrop">
    <div class="chk-modal">
        <h3>Record a Return</h3>
        <p class="chk-modal-sub" id="chkReturnRef">Check —</p>
        <form method="post" action="cheques.php<?php echo chk_qs(); ?>">
            <input type="hidden" name="form_action" value="add_return">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="CheckID" id="chkReturnCheckId" value="">

            <div class="chk-field">
                <label for="chkReturnRDate">Return Date</label>
                <input type="date" id="chkReturnRDate" name="RDate" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="chk-field">
                <label for="chkReturnType">Type</label>
                <input type="text" id="chkReturnType" name="Type" list="chkReturnTypeList" placeholder="Select or type a new type..." required pattern=".*\S.*" title="This field is required" autocomplete="off">
                <datalist id="chkReturnTypeList">
                    <?php foreach ($typeOptions as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="chk-field">
                <label for="chkReturnAction">Action Taken</label>
                <input type="text" id="chkReturnAction" name="Action" list="chkReturnActionList" placeholder="Select or type a new action..." required pattern=".*\S.*" title="This field is required" autocomplete="off">
                <datalist id="chkReturnActionList">
                    <?php foreach ($actionOptions as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="chk-field">
                <label for="chkReturnRemarks">Remarks</label>
                <textarea id="chkReturnRemarks" name="Remarks" rows="3" placeholder="Enter remarks..." required></textarea>
            </div>

            <div class="chk-modal-actions">
                <button type="button" class="chk-btn chk-btn--ghost" id="chkReturnCancel">Cancel</button>
                <button type="submit" class="chk-btn chk-btn--primary">Save Return</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($Tab === 'checkissues'): ?>
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

<!-- ── Mark Paid Confirmation Modal ───────────────────────── -->
<div class="chk-modal-backdrop" id="ciPayBackdrop">
    <div class="chk-modal">
        <h3>Mark this check as paid?</h3>
        <p class="chk-modal-sub">Please confirm the details below. This screen has no undo for this action.</p>
        <div class="chk-detail-grid">
            <div class="chk-detail-item full"><div class="chk-detail-label">Supplier</div><div class="chk-detail-value" id="ciPay-SupplierName"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Department</div><div class="chk-detail-value" id="ciPay-Department"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check #</div><div class="chk-detail-value" id="ciPay-CheckNo"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Bank</div><div class="chk-detail-value" id="ciPay-BankIssue"></div></div>
            <div class="chk-detail-item"><div class="chk-detail-label">Check Date</div><div class="chk-detail-value" id="ciPay-CheckDateFmt"></div></div>
            <div class="chk-detail-item full"><div class="chk-detail-label">Amount</div><div class="chk-detail-value" id="ciPay-Amount" style="font-size:16px;font-weight:600;"></div></div>
        </div>
        <div class="chk-modal-actions" style="margin-top:18px;">
            <button type="button" class="chk-btn chk-btn--ghost" id="ciPayCancel">Cancel</button>
            <button type="button" class="chk-btn chk-btn--primary" id="ciPayConfirm">Yes, Mark as Paid</button>
        </div>
    </div>
</div>

<script id="ciData" type="application/json"><?php echo json_encode($ciRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]'; ?></script>
<script>
(function () {
    var backdrop = document.getElementById('ciViewBackdrop');
    if (!backdrop) return;

    var dataEl = document.getElementById('ciData');
    var rows = [];
    try { rows = JSON.parse(dataEl.textContent || '[]'); } catch (e) { rows = []; }

    var byId = {};
    rows.forEach(function (r) { byId[String(r.ID)] = r; });

    var sub = document.getElementById('ciViewSub');
    var fields = ['Department','ReferenceNo','DocDateFmt','CheckDateFmt','CheckNo','Amount','BankIssue','Paid',
                  'AccountName','AccountNo','SupplierName','ContactPerson','ContactNo','DepositSlip',
                  'Address','Description','Remarks','Words','UserID','DateTimeInputFmt'];

    document.querySelectorAll('.chk-ci-view-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rec = byId[btn.getAttribute('data-id')];
            if (!rec) return;
            sub.textContent = 'Check #' + (rec.CheckNo || '') + ' — ' + (rec.SupplierName || rec.AccountName || '');
            fields.forEach(function (f) {
                var el = document.getElementById('ciD-' + f);
                if (!el) return;
                if (f === 'Amount') {
                    el.textContent = rec.Amount !== null && rec.Amount !== undefined
                        ? '₱' + Number(rec.Amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : '';
                } else if (f === 'Paid') {
                    el.textContent = (Number(rec.Paid) === 1) ? 'Paid' : 'Unpaid';
                } else {
                    el.textContent = rec[f] || '—';
                }
            });
            backdrop.classList.add('open');
        });
    });

    function closeModal() { backdrop.classList.remove('open'); }
        document.getElementById('ciViewClose').addEventListener('click', closeModal);

    // ── Mark Paid confirmation (replaces the browser's native confirm box) ──
    var payBackdrop = document.getElementById('ciPayBackdrop');
    var payConfirm  = document.getElementById('ciPayConfirm');
    var payForm     = null;
    var payFields   = ['SupplierName', 'Department', 'CheckNo', 'BankIssue', 'CheckDateFmt', 'Amount'];

    function closePay() {
        payBackdrop.classList.remove('open');
        payConfirm.disabled = false;
        payForm = null;
    }

    document.querySelectorAll('.chk-ci-paid-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var id  = form.getAttribute('data-id');
            var rec = byId[id];
            if (!rec) {
                // Details unavailable: fall back to a plain confirmation
                if (!confirm('Mark check issue #' + id + ' as paid?')) e.preventDefault();
                return;
            }
            e.preventDefault();
            payForm = form;
            payFields.forEach(function (f) {
                var el = document.getElementById('ciPay-' + f);
                if (!el) return;
                if (f === 'Amount') {
                    el.textContent = rec.Amount !== null && rec.Amount !== undefined
                        ? '₱' + Number(rec.Amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : '—';
                } else {
                    el.textContent = rec[f] || '—';
                }
            });
            payBackdrop.classList.add('open');
        });
    });

    payConfirm.addEventListener('click', function () {
        if (!payForm) return;
        payConfirm.disabled = true; // prevent double submit
        payForm.submit();           // same POST as before: existing handler, CSRF and redirect
    });
    document.getElementById('ciPayCancel').addEventListener('click', closePay);
    payBackdrop.addEventListener('click', function (e) {
        if (e.target === payBackdrop) closePay();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePay();
    });
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
})();
</script>
<?php endif; ?>

<script>
(function () {
    var backdrop = document.getElementById('chkReturnBackdrop');
    if (!backdrop) return; // view-only: modal not rendered

    var refLabel  = document.getElementById('chkReturnRef');
    var idField   = document.getElementById('chkReturnCheckId');
    var cancelBtn = document.getElementById('chkReturnCancel');

    var remarksField = document.getElementById('chkReturnRemarks');
    backdrop.querySelector('form').addEventListener('submit', function (e) {
        if (remarksField.value.trim() === '') {
            e.preventDefault();
            remarksField.value = '';
            remarksField.focus();
            remarksField.reportValidity();
        }
    });

    document.querySelectorAll('.chk-return-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            idField.value = btn.getAttribute('data-id');
            refLabel.textContent = 'Check — ' + btn.getAttribute('data-ref');
            backdrop.classList.add('open');
        });
    });

    function closeModal() { backdrop.classList.remove('open'); }
    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
})();
</script>
<script>
(function () {
    // Mark Paid confirmation modal (Check Issues tab).
    // Self-contained: builds its own modal, removes the old inline confirm(),
    // and reuses the embedded #ciData JSON for the record details.
    var forms = [];
    document.querySelectorAll('form').forEach(function (f) {
        var act = f.querySelector('input[name="form_action"]');
        if (act && act.value === 'mark_paid') forms.push(f);
    });
    if (!forms.length) return;

    var rows = [];
    try { rows = JSON.parse((document.getElementById('ciData') || {}).textContent || '[]'); } catch (e) { rows = []; }
    var byId = {};
    rows.forEach(function (r) { byId[String(r.ID)] = r; });

    function item(label, key, full, extra) {
        return '<div class="chk-detail-item' + (full ? ' full' : '') + '">' +
               '<div class="chk-detail-label">' + label + '</div>' +
               '<div class="chk-detail-value" data-f="' + key + '"' + (extra ? ' style="' + extra + '"' : '') + '></div></div>';
    }

    var dlg = document.createElement('div');
    dlg.className = 'chk-modal-backdrop';
    dlg.id = 'ciPayDlg';
    dlg.innerHTML =
        '<div class="chk-modal">' +
            '<h3>Mark this check as paid?</h3>' +
            '<p class="chk-modal-sub">Please confirm the details below. This screen has no undo for this action.</p>' +
            '<div class="chk-detail-grid">' +
                item('Supplier', 'SupplierName', true) +
                item('Department', 'Department', false) +
                item('Check #', 'CheckNo', false) +
                item('Bank', 'BankIssue', false) +
                item('Check Date', 'CheckDateFmt', false) +
                item('Amount', 'Amount', true, 'font-size:16px;font-weight:600;') +
            '</div>' +
            '<div class="chk-modal-actions" style="margin-top:18px;">' +
                '<button type="button" class="chk-btn chk-btn--ghost" data-act="cancel">Cancel</button>' +
                '<button type="button" class="chk-btn chk-btn--primary" data-act="confirm">Yes, Mark as Paid</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(dlg);

    var confirmBtn = dlg.querySelector('[data-act="confirm"]');
    var payForm = null;

    function closeDlg() {
        dlg.classList.remove('open');
        confirmBtn.disabled = false;
        payForm = null;
    }

    forms.forEach(function (form) {
        form.removeAttribute('onsubmit'); // drop the old native confirm()
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var idEl = form.querySelector('input[name="CheckIssueID"]');
            var id   = idEl ? idEl.value : '';
            var rec  = byId[String(id)];
            if (!rec) {
                // Details unavailable: fall back to a plain confirmation
                if (confirm('Mark check issue #' + id + ' as paid?')) form.submit();
                return;
            }
            payForm = form;
            ['SupplierName', 'Department', 'CheckNo', 'BankIssue', 'CheckDateFmt', 'Amount'].forEach(function (f) {
                var el = dlg.querySelector('[data-f="' + f + '"]');
                if (!el) return;
                if (f === 'Amount') {
                    el.textContent = (rec.Amount !== null && rec.Amount !== undefined)
                        ? '₱' + Number(rec.Amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        : '—';
                } else {
                    el.textContent = rec[f] || '—';
                }
            });
            dlg.classList.add('open');
        });
    });

    confirmBtn.addEventListener('click', function () {
        if (!payForm) return;
        confirmBtn.disabled = true; // prevent double submit
        payForm.submit();           // same POST as before: existing handler, CSRF and redirect
    });
    dlg.querySelector('[data-act="cancel"]').addEventListener('click', closeDlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDlg(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDlg(); });
})();
</script>
</body>
</html>