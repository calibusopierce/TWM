<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'ar_remittance');

// -- AJAX: drill-down invoice lines for a DocNo --
if (isset($_GET['ajax']) && $_GET['ajax'] === 'docno_detail') {
    header('Content-Type: application/json');
    $docno = trim($_GET['docno'] ?? '');
    if ($docno === '') { echo json_encode([]); exit; }
    $docnoSafe = str_replace("'", "''", $docno);
    $sql = "
        SELECT Remittance_InvID, InvoiceNo, Customer, DocDate, CreditAmount,
               DATEDIFF(day, CAST(DocDate AS DATE), CAST(GETDATE() AS DATE)) AS DaysOld
        FROM [dbo].[View_RemittanceCollectionSlip2]
        WHERE DocNo = '$docnoSafe' AND CreditAmount > 2 AND ARCreate = 0
        ORDER BY DocDate DESC
    ";
    $rows = runQuery($conn, $sql);
    sqlsrv_close($conn);
    echo json_encode($rows);
    exit;
}

// -- AJAX: full AR detail for Remitted/Uncollected modal --
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ar_remittance_detail') {
    header('Content-Type: application/json');
    $afcId = (int)($_GET['afc_id'] ?? 0);
    if ($afcId === 0) { echo json_encode([]); exit; }
    $sql = "
        SELECT
            InvoiceNo, DeliveryDate, CustomerName1 AS CustomerName, ARRemarks,
            InvoiceAmount, TotalAmount, Deduction,
            Bank, CheckNo, CheckDate, CheckAmount, Cash, Balance, Terms,
            DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE)) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE ARForCollectionID = $afcId
        ORDER BY CustomerName1 ASC, InvoiceNo ASC
    ";
    $stmt = sqlsrv_query($conn, $sql);
    $rows = [];
    if ($stmt === false) {
        $errs = sqlsrv_errors();
        error_log('ar_remittance_detail SQL error: ' . json_encode($errs));
        sqlsrv_close($conn);
        echo json_encode(['__error__' => $errs[0]['message'] ?? 'Unknown SQL error']);
        exit;
    }
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach ($row as $k => $v) {
                if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
            }
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    sqlsrv_close($conn);
    echo json_encode($rows);
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ar_detail') {
    header('Content-Type: application/json');
    $afcId = (int)($_GET['afc_id'] ?? 0);
    if ($afcId === 0) { echo json_encode([]); exit; }
    $sql = "
        SELECT InvoiceNo, InvoiceDate, InvoiceAmount, PaidAmount, Balance,
               DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE)) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE ARForCollectionID = $afcId
        ORDER BY InvoiceDate DESC
    ";
    $rows = runQuery($conn, $sql);
    sqlsrv_close($conn);
    echo json_encode($rows);
    exit;
}

// ── Current user context ─────────────────────────────────────
$currentUserDept   = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');
$currentUserBranch = $_SESSION['Branch']     ?? ($_SESSION['branch']     ?? '');

// ── Valid tabs ───────────────────────────────────────────────
$validTabs = ['total_credit', 'ar_created', 'ar_collection', 'remitted', 'received', 'uncollected'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : 'total_credit';

// ── Date filters ─────────────────────────────────────────────
$today     = date('Y-m-d');
$weekFrom  = date('Y-m-d', strtotime('-6 days')); // last 7 days
$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
if (!$dateActive) {
    // 🔥 CHANGE HERE: default to 7 days instead of month
    $baseFrom = $weekFrom;
    $baseTo   = $today;
} else {
    $baseFrom = $dateFrom !== '' ? $dateFrom : $weekFrom;
    $baseTo   = $dateTo   !== '' ? $dateTo   : $today;
}

// ── Other filters ────────────────────────────────────────────
$selBranch   = isset($_GET['branch'])   ? trim($_GET['branch'])   : '';
$selDept     = isset($_GET['dept'])     ? trim($_GET['dept'])     : $currentUserDept;
$selSalesman = isset($_GET['salesman']) ? trim($_GET['salesman']) : '';
$selArea     = isset($_GET['area'])     ? trim($_GET['area'])     : '';

$branchActive   = $selBranch   !== '';
$deptActive     = $selDept     !== '' && strtolower($selDept) !== 'all';
$salesmanActive = $selSalesman !== '';
$areaActive     = $selArea     !== '';

$anyFilterApplied = $dateActive || $branchActive || $salesmanActive || $areaActive;

// ── Safe values ───────────────────────────────────────────────
$_branchSafe   = str_replace("'", "''", $selBranch);
$_deptSafe     = str_replace("'", "''", $selDept);
$_salesmanSafe = str_replace("'", "''", $selSalesman);
$_areaSafe     = str_replace("'", "''", $selArea);

// ── WHERE clauses ────────────────────────────────────────────
$branchWhere   = $branchActive   ? " AND Branch = '$_branchSafe'"              : '';
$deptWhere     = $deptActive     ? " AND RTRIM(Department) = '$_deptSafe'"     : '';
$salesmanWhere = $salesmanActive ? " AND Salesman = '$_salesmanSafe'"          : '';
$areaWhere     = $areaActive     ? " AND Area = '$_areaSafe'"                  : '';
$commonWhere   = $branchWhere . $deptWhere . $salesmanWhere . $areaWhere;

// Branch->Department translation for AR views that have no Branch column.
// Resolve which Departments belong to the selected branch via the credit view.
if ($branchActive) {
    $branchWhereAR = " AND RTRIM(Department) IN (
        SELECT DISTINCT RTRIM(Department)
        FROM [dbo].[View_RemittanceCollectionSlip2]
        WHERE Branch = '$_branchSafe' AND Department IS NOT NULL AND Department <> ''
    )";
} else {
    $branchWhereAR = '';
}

// Always scope by the current user's department so data is never cross-department.
// $deptActive covers an explicit dept GET param; $currentUserDept covers the session default.
$_effectiveDeptSafe = $deptActive ? $_deptSafe : str_replace("'", "''", $currentUserDept);
$deptWhereAR = ($_effectiveDeptSafe !== '' && strtolower($_effectiveDeptSafe) !== 'all')
    ? " AND RTRIM(Department) = '$_effectiveDeptSafe'"
    : '';

$salesmanWhereAR = $salesmanActive ? " AND Salesman = '$_salesmanSafe'" : '';
$areaWhereAR     = $areaActive     ? " AND Area = '$_areaSafe'"         : '';
$commonWhereAR   = $branchWhereAR . $deptWhereAR . $salesmanWhereAR . $areaWhereAR;

// WHERE for received tab JOIN -- must prefix columns with r. to avoid ambiguity
$branchWhereR = $branchActive ? " AND r.Branch = '$_branchSafe'" : '';
$deptWhereR   = ($_effectiveDeptSafe !== '' && strtolower($_effectiveDeptSafe) !== 'all')
    ? " AND RTRIM(r.Department) = '$_effectiveDeptSafe'"
    : '';
$areaWhereR   = $areaActive   ? " AND r.Area = '$_areaSafe'"     : '';

// ── Helper: run a query and return all rows ──────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function runQueryDebug($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        $errors = sqlsrv_errors();
        error_log('AR Remittance SQL error: ' . json_encode($errors));
        return ['__error__' => $errors[0]['message'] ?? 'Unknown SQL error'];
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) $row[$k] = $v->format('Y-m-d');
        }
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function lookupList($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $list = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Lookup dropdowns ─────────────────────────────────────────
// Branch list — hardcoded to the three valid branches only
$branchList = ['Quezon', 'Quezon Upper', 'Marinduque'];

// Salesman & Area — queried live from the active tab's view,
// scoped to the current date range and branch filter so options
// always reflect what's actually visible in the table.
$tabSalesmanAreaSource = [
    'total_credit'  => [
        'view'        => '[dbo].[View_RemittanceCollectionSlip2]',
        'salesman'    => 'Salesman',
        'area'        => 'Area',
        'date_col'    => 'DocDate',
        'branch_col'  => 'Branch',
    ],
    'ar_created'    => [
        'view'        => '[dbo].[View_ARForCollectionDetails]',
        'salesman'    => 'Salesman',
        'area'        => 'Area',
        'date_col'    => 'DeliveryDate',
        'branch_col'  => NULL,
    ],
    'ar_collection' => [
        'view'        => '[dbo].[View_ARForCollectionDetails]',
        'salesman'    => 'Salesman',
        'area'        => 'Area',
        'date_col'    => 'DeliveryDate',
        'branch_col'  => NULL,
    ],
    'remitted'      => [
        'view'        => '[dbo].[View_ARForCollectionDetails]',
        'salesman'    => 'Salesman',
        'area'        => 'Area',
        'date_col'    => 'DeliveryDate',
        'branch_col'  => NULL,
    ],
    'received'      => [
        'view'        => '[dbo].[View_Rimettance_Received]',
        'salesman'    => NULL,   // no Salesman column in this view
        'area'        => 'Area',
        'date_col'    => 'ReceivingDate',
        'branch_col'  => 'Branch',
    ],
    'uncollected'   => [
        'view'        => '[dbo].[View_ARForCollectionDetails2]',
        'salesman'    => 'Salesman',
        'area'        => 'Area',
        'date_col'    => 'DeliveryDate',
        'branch_col'  => NULL,
    ],
];
$tabSrc = $tabSalesmanAreaSource[$tab];

// Build the shared WHERE that scopes the dropdown options to the current filters
$_dropDateWhere   = " AND {$tabSrc['date_col']} BETWEEN '$baseFrom' AND '$baseTo'";
$_dropBranchWhere = ($branchActive && $tabSrc['branch_col'])
    ? " AND {$tabSrc['branch_col']} = '$_branchSafe'"
    : '';
// Scope dropdowns by the effective department so options are never cross-dept
$_dropDeptWhere = '';
if ($tabSrc['salesman'] && $_effectiveDeptSafe !== '' && strtolower($_effectiveDeptSafe) !== 'all') {
    $_dropDeptWhere = " AND RTRIM(Department) = '$_effectiveDeptSafe'";
}

if ($tabSrc['salesman']) {
    $salesmanList = lookupList($conn,
        "SELECT DISTINCT {$tabSrc['salesman']} FROM {$tabSrc['view']}
         WHERE {$tabSrc['salesman']} IS NOT NULL AND {$tabSrc['salesman']} <> ''
           {$_dropDateWhere} {$_dropBranchWhere} {$_dropDeptWhere}
         ORDER BY {$tabSrc['salesman']}"
    );
} else {
    $salesmanList = []; // received tab has no Salesman column
}

$areaList = lookupList($conn,
    "SELECT DISTINCT {$tabSrc['area']} FROM {$tabSrc['view']}
     WHERE {$tabSrc['area']} IS NOT NULL AND {$tabSrc['area']} <> ''
       {$_dropDateWhere} {$_dropBranchWhere} {$_dropDeptWhere}
     ORDER BY {$tabSrc['area']}"
);

// ── Single combined stat query (CTE — one round trip) ────────
// Uses $baseFrom/$baseTo so stats stay in sync with active filters & date range
$statSql = "
SELECT
    -- Total Credit (pending AR creation)
    SUM(CASE WHEN src = 'credit' THEN 1 ELSE 0 END)              AS TotalCreditCount,
    SUM(CASE WHEN src = 'credit' THEN amt ELSE 0 END)            AS TotalCreditAmount,

    -- AR Created (Status1 = 1)
    COUNT(DISTINCT CASE WHEN src = 'ar' AND status1 = 1 THEN refno END) AS ARCreatedCount,
    SUM(CASE WHEN src = 'ar' AND status1 = 1 THEN amt ELSE 0 END)       AS ARCreatedAmount,

    -- AR For Collection (Status1 = 2)
    COUNT(DISTINCT CASE WHEN src = 'ar' AND status1 = 2 THEN refno END) AS ARCollectionCount,
    SUM(CASE WHEN src = 'ar' AND status1 = 2 THEN amt ELSE 0 END)       AS ARCollectionAmount,

    -- Remitted (Status1 = 3)
    COUNT(DISTINCT CASE WHEN src = 'ar' AND status1 = 3 THEN refno END) AS RemittedCount,
    SUM(CASE WHEN src = 'ar' AND status1 = 3 THEN amt ELSE 0 END)       AS RemittedAmount,

    -- Received (Status1 = 4)
    COUNT(DISTINCT CASE WHEN src = 'ar' AND status1 = 4 THEN refno END) AS ReceivedCount,
    SUM(CASE WHEN src = 'ar' AND status1 = 4 THEN amt ELSE 0 END)       AS ReceivedAmount,

    -- Uncollected (Status1 = 5 or partial balance, ARCreated = 0)
    COUNT(DISTINCT CASE WHEN src = 'uc' AND (status1 = 5 OR (status1 IN (3,4) AND bal > 2)) THEN refno END) AS UncollectedCount,
    SUM(CASE WHEN src = 'uc' AND (status1 = 5 OR (status1 IN (3,4) AND bal > 2)) THEN bal ELSE 0 END)       AS UncollectedAmount

FROM (
    -- Total Credit source
    SELECT 'credit' AS src, NULL AS refno, CreditAmount AS amt, NULL AS bal, NULL AS status1
    FROM [dbo].[View_RemittanceCollectionSlip2]
    WHERE CreditAmount > 2 AND ARCreate = 0
      AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
      $branchWhere $deptWhere $salesmanWhere $areaWhere

    UNION ALL

    -- AR statuses 1-4 from original view (no ARCreated filter)
    SELECT 'ar' AS src, ARForCollectionID AS refno, InvoiceAmount AS amt, Balance AS bal, Status1 AS status1
    FROM [dbo].[View_ARForCollectionDetails]
    WHERE DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
      $commonWhereAR

    UNION ALL

    -- Uncollected from view2 (ARCreated = 0)
    SELECT 'uc' AS src, ARForCollectionID AS refno, InvoiceAmount AS amt, Balance AS bal, Status1 AS status1
    FROM [dbo].[View_ARForCollectionDetails2]
    WHERE DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
      AND ARCreated = 0
      $commonWhereAR
) combined
";

$statRow = runQuery($conn, $statSql)[0] ?? [];

$totalCreditCount  = (int)($statRow['TotalCreditCount']   ?? 0);
$totalCreditAmount = (float)($statRow['TotalCreditAmount'] ?? 0);
$arCreatedCount    = (int)($statRow['ARCreatedCount']     ?? 0);
$arCreatedAmount   = (float)($statRow['ARCreatedAmount']  ?? 0);
$arCollectionCount = (int)($statRow['ARCollectionCount']  ?? 0);
$arCollectionAmount= (float)($statRow['ARCollectionAmount']?? 0);
$remittedCount     = (int)($statRow['RemittedCount']      ?? 0);
$remittedAmount    = (float)($statRow['RemittedAmount']   ?? 0);
$receivedCount     = (int)($statRow['ReceivedCount']      ?? 0);
$receivedAmount    = (float)($statRow['ReceivedAmount']   ?? 0);
$uncollectedCount  = (int)($statRow['UncollectedCount']   ?? 0);
$uncollectedAmount = (float)($statRow['UncollectedAmount'] ?? 0);

// ── Active tab query only ────────────────────────────────────
$queryMap = [
    'total_credit' => "
        SELECT
            DocNo, Branch, Department, Salesman, Area,
            MIN(DocDate)      AS DocDate,
            MIN(RemarksSummary) AS RemarksSummary,
            SUM(CreditAmount) AS CreditAmount,
            COUNT(*)          AS InvoiceCount,
            DATEDIFF(day, CAST(MIN(DocDate) AS DATE), CAST(GETDATE() AS DATE)) AS DaysOld
        FROM [dbo].[View_RemittanceCollectionSlip2]
        WHERE CreditAmount > 2 AND ARCreate = 0
          AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
          $commonWhere
        GROUP BY DocNo, Branch, Department, Salesman, Area
        ORDER BY DaysOld DESC, DocNo
    ",
    'ar_created' => "
        SELECT
            ARForCollectionID, ARCollectionNo, MIN(CustomerName1) AS CustomerName,
            MIN(DocNo) AS DocNo, Department, Area, Salesman,
            MIN(DateCollection) AS DateCollection, MIN(DeliveryDate) AS DeliveryDate,
            SUM(InvoiceAmount) AS InvoiceAmount, SUM(PaidAmount) AS PaidAmount,
            SUM(Balance) AS Balance, COUNT(*) AS InvoiceCount,
            MAX(DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE))) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE Status1 = 1
          AND DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
          $commonWhereAR
        GROUP BY ARForCollectionID, ARCollectionNo, Department, Area, Salesman
        ORDER BY DaysOld DESC, ARForCollectionID
    ",
    'ar_collection' => "
        SELECT
            ARForCollectionID, ARCollectionNo, MIN(CustomerName1) AS CustomerName,
            MIN(DocNo) AS DocNo, Department, Area, Salesman,
            MIN(DateCollection) AS DateCollection, MIN(DeliveryDate) AS DeliveryDate,
            SUM(InvoiceAmount) AS InvoiceAmount, SUM(PaidAmount) AS PaidAmount,
            SUM(Balance) AS Balance, COUNT(*) AS InvoiceCount,
            MAX(DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE))) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE Status1 = 2
          AND DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
          $commonWhereAR
        GROUP BY ARForCollectionID, ARCollectionNo, Department, Area, Salesman
        ORDER BY DaysOld DESC, ARForCollectionID
    ",
    'remitted' => "
        SELECT
            ARForCollectionID, ARCollectionNo, MIN(CustomerName1) AS CustomerName,
            MIN(DocNo) AS DocNo, Department, Area, Salesman,
            MIN(DateCollection) AS DateCollection, MIN(DeliveryDate) AS DeliveryDate,
            SUM(InvoiceAmount) AS InvoiceAmount, SUM(PaidAmount) AS PaidAmount,
            SUM(Balance) AS Balance, COUNT(*) AS InvoiceCount,
            MAX(DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE))) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails]
        WHERE Status1 = 3
          AND DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
          $commonWhereAR
        GROUP BY ARForCollectionID, ARCollectionNo, Department, Area, Salesman
        ORDER BY DaysOld DESC, ARForCollectionID
    ",
    'received' => "
        SELECT
            r.RRDID,
            r.RRID,
            r.ARForColID                                        AS AFCNo,
            r.Branch,
            r.Department,
            r.Area,
            r.RemittedByname,
            r.ReceivingDate                                     AS ReceivedDate,
            ISNULL(r.TotalCash, 0)                             AS TotalCash,
            ISNULL(r.TotalCheck, 0)                            AS TotalCheck,
            ISNULL(r.TotalCash, 0) + ISNULL(r.TotalCheck, 0)  AS TotalRemit,
            MIN(d.DeliveryDate)                                 AS DocDate,
            MIN(d.Salesman)                                     AS Salesman,
            ISNULL(SUM(d.InvoiceAmount), 0)                    AS NetAmount,
            ISNULL(MIN(e.FirstName) + ' ' + MIN(e.LastName), '—') AS ReceivedBy,
            DATEDIFF(day, CAST(MIN(d.DeliveryDate) AS DATE), CAST(GETDATE() AS DATE)) AS DaysOld
        FROM [dbo].[View_Rimettance_Received] r
        LEFT JOIN [dbo].[View_ARForCollectionDetails] d
            ON d.ARForCollectionID = r.ARForColID
        LEFT JOIN [dbo].[TBL_HREmployeeList] e
            ON e.EmployeeID = r.LastUpdateUser
        WHERE ISNULL(r.Void, 0) = 0
          AND r.ARForColID > 0
          AND r.ReceivingDate BETWEEN '$baseFrom' AND '$baseTo'
          $branchWhereR $deptWhereR $areaWhereR
        GROUP BY
            r.RRDID, r.RRID, r.ARForColID,
            r.Branch, r.Department, r.Area,
            r.RemittedByname, r.ReceivingDate,
            r.TotalCash, r.TotalCheck
        ORDER BY DaysOld DESC, r.RRDID
    ",
    'uncollected' => "
        SELECT
            ARForCollectionID, ARCollectionNo, MIN(CustomerName) AS CustomerName,
            MIN(DocNo) AS DocNo, Department, Area, Salesman,
            MIN(DateCollection) AS DateCollection, MIN(DeliveryDate) AS DeliveryDate,
            SUM(InvoiceAmount) AS InvoiceAmount,
            SUM(InvoiceAmount) - SUM(Balance) AS PaidAmount,
            SUM(Balance) AS Balance, COUNT(*) AS InvoiceCount,
            MAX(DATEDIFF(day, CAST(DeliveryDate AS DATE), CAST(GETDATE() AS DATE))) AS DaysOld
        FROM [dbo].[View_ARForCollectionDetails2]
        WHERE (Status1 = 5 OR (Status1 IN (3, 4) AND Balance > 2)) AND ARCreated = 0
          AND DeliveryDate BETWEEN '$baseFrom' AND '$baseTo'
          $commonWhereAR
        GROUP BY ARForCollectionID, ARCollectionNo, Department, Area, Salesman
        ORDER BY DaysOld DESC, ARForCollectionID
    ",
];

// ── Fetch active tab data (one query, paginate in PHP) ───────
$sqlError = null;
$data = runQuery($conn, $queryMap[$tab]);

// ── Pagination (PHP-based, no extra COUNT query) ──────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

// ── Active tab badge count synced from $totalRows ────────────
// All other tab badge counts come from $statSql above (one round trip).
// Map active tab's real row count back so the card matches the table.
$tabCountSync = [
    'total_credit'  => &$totalCreditCount,
    'ar_created'    => &$arCreatedCount,
    'ar_collection' => &$arCollectionCount,
    'remitted'      => &$remittedCount,
    'received'      => &$receivedCount,
    'uncollected'   => &$uncollectedCount,
];
$tabCountSync[$tab] = $totalRows;
unset($tabCountSync);

// Sync uncollectedAmount from full dataset
if ($tab === 'uncollected') {
    $uncollectedAmount = array_sum(array_column($exportData, 'Balance'));
}

// NOTE: $conn is intentionally NOT closed here.
// topbar.php (included later on this page) calls get_employee_profile($conn),
// which runs sqlsrv_query() against the same connection. Closing $conn here
// caused: "supplied resource is not a valid ss_sqlsrv_conn resource" on line 172
// of nav.php. PHP closes the connection automatically when the script ends.
// The sqlsrv_close() calls in the AJAX blocks above (lines 34, 59, 72, 98) are
// safe because those blocks all call exit immediately after — they never reach
// the topbar include.

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

function tabUrl(string $t): string {
    $p = $_GET; $p['tab'] = $t; unset($p['page']);
    return '?' . http_build_query($p);
}

// ── Helpers ──────────────────────────────────────────────────
function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }

function fmtDate($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    if (is_string($d) && strlen($d) >= 10) return substr($d, 0, 10);
    return htmlspecialchars($d ?? '—');
}

function deptBadge(?string $d): string {
    $map = [
        'monde'      => ['rgba(239,68,68,.15)',    '#ef4444', '#fca5a5'],
        'century'    => ['rgba(59,130,246,.15)',   '#3b82f6', '#93c5fd'],
        'multilines' => ['rgba(234,179,8,.15)',    '#ca8a04', '#fde047'],
        'nutriasia'  => ['rgba(16,185,129,.15)',   '#059669', '#6ee7b7'],
        'silverswan' => ['rgba(99,102,241,.15)',   '#6366f1', '#a5b4fc'],
        'urban tradewell corp.' => ['rgba(28,61,126,.15)', '#0b2b6d', '#113472'],
        ''           => ['rgba(107,114,128,.15)',  '#6b7280', '#9ca3af'],
    ];
    $key = strtolower(trim($d ?? ''));
    [$bg,$color,$border] = $map[$key] ?? $map[''];
    $label = htmlspecialchars(trim($d ?? '') !== '' ? trim($d) : '—');
    return "<span style='background:$bg;color:$color;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>$label</span>";
}

function branchBadge(?string $b): string {
    $map = [
        'quezon'       => ['#ede9fe','#5b21b6','#a78bfa'],
        'quezon upper' => ['#dbeafe','#1e3a8a','#93c5fd'],
        'marinduque'   => ['#dcfce7','#166534','#4ade80'],
    ];
    [$bg,$text,$border] = $map[strtolower(trim($b ?? ''))] ?? ['#f3f4f6','#374151','#d1d5db'];
    return "<span style='background:$bg;color:$text;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>" . htmlspecialchars($b ?? '—') . "</span>";
}

function daysBadgeClass(int $days): string {
    if ($days <= 7)  return 'days-ok';
    if ($days <= 30) return 'days-warn';
    return 'days-danger';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AR Remittance Dashboard — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
:root {
  --ar-accent  : #7c3aed;
  --ar-accent2 : #a78bfa;
  --ar-green   : #16a34a;
  --ar-yellow  : #ca8a04;
  --ar-red     : #dc2626;
  --ar-blue    : #2563eb;
  --ar-orange  : #ea580c;
  --ar-teal    : #0d9488;
}

/* ── Stat Cards ─────────────────────────────────────── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
  gap: .875rem;
  margin-bottom: 1.5rem;
}
.stat-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
  padding: 1rem 1.2rem 1.3rem;
  display: flex; flex-direction: column; gap: .2rem;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
  transition: transform .18s, box-shadow .18s, border-color .18s;
  position: relative; overflow: hidden;
  text-decoration: none; cursor: pointer;
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(124,58,237,.12);
  border-color: var(--ar-accent2);
}
.sc-icon  { font-size: 1.4rem; margin-bottom: .15rem; }
.sc-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: var(--text-dim, #6b7280); font-weight: 700; }
.sc-value { font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: var(--text, #111827); font-family: 'JetBrains Mono', monospace; }
.sc-sub   { font-size: .72rem; color: var(--text-dim, #6b7280); margin-top: .1rem; }

/* ── Filter Panel ───────────────────────────────────── */
.filter-panel {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
  margin-bottom: 1rem;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.filter-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: .75rem 1.25rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #f9fafb);
  cursor: pointer; user-select: none;
}
.filter-panel-header-left {
  display: flex; align-items: center; gap: .6rem;
  font-size: .82rem; font-weight: 700; color: var(--text, #374151);
}
.filter-active-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
.filter-tag {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: 2px 9px; border-radius: 999px; font-size: .7rem; font-weight: 600;
  background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; white-space: nowrap;
}
.filter-tag.tag-date   { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.filter-tag.tag-dept   { background: #fef9c3; color: #713f12; border-color: #fde047; }
.filter-tag.tag-salesman { background: #dcfce7; color: #166534; border-color: #4ade80; }
.filter-tag.tag-area   { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
.filter-toggle-icon { font-size: .75rem; color: var(--text-dim, #9ca3af); transition: transform .2s; }
.filter-toggle-icon.open { transform: rotate(180deg); }
.filter-body { padding: 1rem 1.25rem 1.1rem; display: none; }
.filter-body.open { display: block; }
.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem; margin-bottom: .875rem;
}
.filter-group { display: flex; flex-direction: column; gap: .3rem; }
.filter-group label {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .05em; color: var(--text-dim, #6b7280);
}
.filter-group input[type=date],
.filter-group select {
  font-size: .82rem; padding: .4rem .7rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; background: var(--input-bg, #f9fafb);
  color: var(--text, #111827); height: 36px; width: 100%;
  transition: border-color .15s, box-shadow .15s;
}
.filter-group input[type=date]:focus,
.filter-group select:focus {
  outline: none; border-color: var(--ar-accent);
  box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}
.filter-actions { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.btn-filter {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem 1.1rem; border-radius: 8px;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  border: none; height: 36px; transition: all .15s;
}
.btn-filter.apply { background: var(--ar-accent); color: #fff; box-shadow: 0 1px 4px rgba(124,58,237,.3); }
.btn-filter.apply:hover { background: #6d28d9; }
.btn-filter.reset { background: var(--input-bg, #f3f4f6); color: var(--text, #374151); border: 1.5px solid var(--border, #d1d5db); }
.btn-filter.reset:hover { background: #e5e7eb; }

/* ── Tab Navigation ─────────────────────────────────── */
.tab-nav { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: 1rem; }
.tab-nav a {
  padding: .45rem 1.1rem; border-radius: 999px;
  font-size: .81rem; font-weight: 600;
  border: 1.5px solid var(--border, #e5e7eb);
  color: var(--text-dim, #6b7280); text-decoration: none;
  background: var(--card-bg, #fff); transition: all .18s;
  display: flex; align-items: center; gap: .35rem;
}
.tab-nav a:hover { border-color: var(--ar-accent2); color: var(--ar-accent); }
.tab-nav a.active { background: var(--ar-accent); color: #fff; border-color: var(--ar-accent); }
.tab-badge {
  border-radius: 999px; padding: 0 6px;
  font-size: .68rem; font-weight: 700;
  background: rgba(255,255,255,.25);
}
.tab-nav a:not(.active) .tab-badge { background: var(--border, #e5e7eb); color: var(--text-dim, #6b7280); }

/* ── Table Card ─────────────────────────────────────── */
.table-card {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.05);
}
.table-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: .6rem;
  padding: .75rem 1.25rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #f9fafb);
}
.table-title {
  font-size: .9rem; font-weight: 700; color: var(--text, #111827);
  display: flex; align-items: center; gap: .5rem;
}
.table-count {
  font-size: .68rem; font-weight: 700; padding: .15rem .5rem;
  border-radius: 999px; background: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe;
}
.table-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.search-wrap { position: relative; flex: 1; min-width: 180px; max-width: 280px; }
.search-wrap i {
  position: absolute; left: .65rem; top: 50%; transform: translateY(-50%);
  color: var(--text-dim, #9ca3af); font-size: .85rem; pointer-events: none;
}
.search-wrap input {
  width: 100%; padding: .4rem .7rem .4rem 2rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; background: var(--input-bg, #f9fafb);
  font-size: .82rem; color: var(--text, #111827); height: 36px;
  transition: border-color .15s, box-shadow .15s;
}
.search-wrap input:focus { outline: none; border-color: var(--ar-accent); box-shadow: 0 0 0 3px rgba(124,58,237,.1); }
.btn-action {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .85rem; border-radius: 7px;
  font-size: .76rem; font-weight: 700; cursor: pointer;
  border: 1.5px solid transparent; height: 32px;
  transition: all .15s; white-space: nowrap;
}
.btn-csv   { background: #f0fdf4; color: #166534; border-color: #4ade80; }
.btn-csv:hover { background: #dcfce7; }
.btn-excel { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.btn-excel:hover { background: #dbeafe; }
.btn-print { background: #f5f3ff; color: #5b21b6; border-color: #c4b5fd; }
.btn-print:hover { background: #ede9fe; }

/* ── Main Table ─────────────────────────────────────── */
.table-scroll { overflow-x: auto; }
.main-table {
  width: 100%; border-collapse: collapse; font-size: .8rem;
}
.main-table thead th {
  background: var(--input-bg, #f9fafb); color: var(--text-dim, #6b7280);
  font-size: .68rem; font-weight: 700; letter-spacing: .06em;
  text-transform: uppercase; padding: .6rem .75rem;
  border-bottom: 2px solid var(--border, #e5e7eb);
  white-space: nowrap; cursor: pointer; user-select: none;
}
.main-table thead th:hover { color: var(--ar-accent); }
.main-table tbody td {
  padding: .5rem .75rem; color: var(--text, #374151);
  border-bottom: 1px solid var(--border, #f1f3f7); vertical-align: middle;
}
.main-table tbody tr:hover { background: #f5f3ff; }
.main-table tbody tr[onclick]:hover { background: #ede9fe; cursor: pointer; }
.r { text-align: right; }
.mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; }

/* Days badge */
.days-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 38px; padding: .15rem .45rem;
  border-radius: 999px; font-size: .7rem; font-weight: 700;
}
.days-ok      { background: #dcfce7; color: #15803d; }
.days-warn    { background: #fef9c3; color: #854d0e; }
.days-danger  { background: #fee2e2; color: #b91c1c; }

/* ── Pagination ─────────────────────────────────────── */
.pagination-bar {
  display: flex; align-items: center; gap: 1rem;
  justify-content: flex-end; flex-wrap: wrap;
  padding: .6rem 1.25rem;
  border-top: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #fafafa);
}
.pagination-info { font-size: .78rem; color: var(--text-dim, #6b7280); flex: 1; }
.pagination-btns { display: flex; gap: .5rem; }
.btn-page {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .85rem; height: 32px;
  font-size: .78rem; font-weight: 600;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px; cursor: pointer;
  background: var(--card-bg, #fff); color: var(--text, #374151);
  text-decoration: none; transition: all .15s;
}
.btn-page:hover:not(.disabled) { border-color: var(--ar-accent); color: var(--ar-accent); }
.btn-page.disabled { opacity: .4; pointer-events: none; }

/* ── Empty / Error states ───────────────────────────── */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text-dim, #9ca3af); }
.empty-state .icon { font-size: 2.2rem; display: block; margin-bottom: .5rem; opacity: .5; }
.ar-error {
  display: flex; align-items: flex-start; gap: .6rem;
  background: #fef2f2; border: 1px solid #fecaca;
  border-radius: 10px; padding: .75rem 1rem;
  font-size: .8rem; color: #b91c1c; margin: .5rem 1rem;
}
</style>
</head>
<body>

<?php $topbar_page = 'ar_remittance'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">AR <span>Remittance</span> Dashboard</div>
      <div class="page-badge">📅 <?= date('F Y') ?> · Live Data</div>
    </div>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <a href="<?= tabUrl('total_credit') ?>" class="stat-card" style="border-left:3px solid var(--ar-accent);">
      <span class="sc-icon">💳</span>
      <span class="sc-label">Total Credit</span>
      <span class="sc-value" style="color:var(--ar-accent)"><?= number_format($totalCreditCount) ?></span>
      <span class="sc-sub"><?= peso($totalCreditAmount) ?></span>
    </a>
    <a href="<?= tabUrl('ar_created') ?>" class="stat-card" style="border-left:3px solid var(--ar-blue);">
      <span class="sc-icon">📋</span>
      <span class="sc-label">AR Created</span>
      <span class="sc-value" style="color:var(--ar-blue)"><?= number_format($arCreatedCount) ?></span>
      <span class="sc-sub"><?= peso($arCreatedAmount) ?></span>
    </a>
    <a href="<?= tabUrl('ar_collection') ?>" class="stat-card" style="border-left:3px solid var(--ar-teal);">
      <span class="sc-icon">🗂️</span>
      <span class="sc-label">For Collection</span>
      <span class="sc-value" style="color:var(--ar-teal)"><?= number_format($arCollectionCount) ?></span>
      <span class="sc-sub"><?= peso($arCollectionAmount) ?></span>
    </a>
    <a href="<?= tabUrl('remitted') ?>" class="stat-card" style="border-left:3px solid var(--ar-orange);">
      <span class="sc-icon">📤</span>
      <span class="sc-label">Remitted</span>
      <span class="sc-value" style="color:var(--ar-orange)"><?= number_format($remittedCount) ?></span>
      <span class="sc-sub"><?= peso($remittedAmount) ?></span>
    </a>
    <a href="<?= tabUrl('received') ?>" class="stat-card" style="border-left:3px solid var(--ar-green);">
      <span class="sc-icon">🏦</span>
      <span class="sc-label">Received</span>
      <span class="sc-value" style="color:var(--ar-green)"><?= number_format($receivedCount) ?></span>
      <span class="sc-sub"><?= peso($receivedAmount) ?></span>
    </a>
    <a href="<?= tabUrl('uncollected') ?>" class="stat-card" style="border-left:3px solid var(--ar-red);">
      <span class="sc-icon">⚠️</span>
      <span class="sc-label">Uncollected</span>
      <span class="sc-value" style="color:var(--ar-red)"><?= number_format($uncollectedCount) ?></span>
      <span class="sc-sub"><?= peso($uncollectedAmount) ?></span>
    </a>
  </div>

  <!-- ── Filter Panel ─────────────────────────────────────── -->
  <div class="filter-panel">
    <div class="filter-panel-header" onclick="toggleFilter()">
      <div class="filter-panel-header-left">
        <i class="bi bi-funnel-fill" style="color:var(--ar-accent)"></i>
        Filters
        <?php if ($anyFilterApplied): ?>
          <span style="background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd;border-radius:999px;padding:1px 8px;font-size:.68rem;">Active</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:.5rem;">
        <div class="filter-active-tags" id="headerTags">
          <?php if ($dateActive): ?><span class="filter-tag tag-date"><i class="bi bi-calendar3"></i><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span><?php endif; ?>
          <?php if ($branchActive):  ?><span class="filter-tag tag-dept"><i class="bi bi-diagram-3"></i><?= htmlspecialchars($selBranch) ?></span><?php endif; ?>
          <?php if ($salesmanActive): ?><span class="filter-tag tag-salesman"><i class="bi bi-person"></i><?= htmlspecialchars($selSalesman) ?></span><?php endif; ?>
          <?php if ($areaActive):   ?><span class="filter-tag tag-area"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($selArea) ?></span><?php endif; ?>
        </div>
        <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
      </div>
    </div>
    <div class="filter-body" id="filterBody">
      <form method="GET" action="">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="filter-grid">
          <div class="filter-group">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
          </div>
          <div class="filter-group">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
          </div>
          <div class="filter-group">
            <label>Branch</label>
            <select name="branch">
              <option value="">All Branches</option>
              <?php foreach ($branchList as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= $selBranch === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label>Salesman <?php if ($tab === 'received'): ?><span style="font-size:.65rem;color:var(--text-dim,#9ca3af);font-weight:400;">(n/a for this tab)</span><?php endif; ?></label>
            <select name="salesman" <?= $tab === 'received' ? 'disabled style="opacity:.5;cursor:not-allowed;"' : '' ?>>
              <option value="">All Salesmen</option>
              <?php foreach ($salesmanList as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $selSalesman === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label>Area</label>
            <select name="area">
              <option value="">All Areas</option>
              <?php foreach ($areaList as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter apply"><i class="bi bi-funnel-fill"></i> Apply Filters</button>
          <a href="?tab=<?= $tab ?>" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- ── Tab Navigation ───────────────────────────────────── -->
  <div class="tab-nav">
    <a href="<?= tabUrl('total_credit') ?>" class="<?= $tab === 'total_credit' ? 'active' : '' ?>">
      <i class="bi bi-credit-card"></i> Total Credit
      <span class="tab-badge"><?= number_format($totalCreditCount) ?></span>
    </a>
    <a href="<?= tabUrl('ar_created') ?>" class="<?= $tab === 'ar_created' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-plus"></i> AR Created
      <span class="tab-badge"><?= number_format($arCreatedCount) ?></span>
    </a>
    <a href="<?= tabUrl('ar_collection') ?>" class="<?= $tab === 'ar_collection' ? 'active' : '' ?>">
      <i class="bi bi-folder2-open"></i> For Collection
      <span class="tab-badge"><?= number_format($arCollectionCount) ?></span>
    </a>
    <a href="<?= tabUrl('remitted') ?>" class="<?= $tab === 'remitted' ? 'active' : '' ?>">
      <i class="bi bi-send"></i> Remitted
      <span class="tab-badge"><?= number_format($remittedCount) ?></span>
    </a>
    <a href="<?= tabUrl('received') ?>" class="<?= $tab === 'received' ? 'active' : '' ?>">
      <i class="bi bi-bank"></i> Received
      <span class="tab-badge"><?= number_format($receivedCount) ?></span>
    </a>
    <a href="<?= tabUrl('uncollected') ?>" class="<?= $tab === 'uncollected' ? 'active' : '' ?>" style="<?= $tab === 'uncollected' ? '' : 'color:var(--ar-red);border-color:#fca5a5;' ?>">
      <i class="bi bi-exclamation-triangle"></i> Uncollected
      <span class="tab-badge"><?= number_format($uncollectedCount) ?></span>
    </a>
  </div>

  <!-- ── Table Card ───────────────────────────────────────── -->
  <div class="table-card">
    <div class="table-toolbar">
      <div class="table-title">
        <?php
        $tabIcons  = ['total_credit'=>'💳','ar_created'=>'📋','ar_collection'=>'🗂️','remitted'=>'📤','received'=>'🏦','uncollected'=>'⚠️'];
        $tabTitles = ['total_credit'=>'Total Credit','ar_created'=>'AR Created','ar_collection'=>'AR For Collection','remitted'=>'Remitted','received'=>'Received','uncollected'=>'Uncollected'];
        echo ($tabIcons[$tab] ?? '') . ' ' . ($tabTitles[$tab] ?? '');
        ?>
        <span class="table-count"><?= number_format($totalRows) ?> records</span>
        <span style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"><?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
      </div>
      <div class="table-actions">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" placeholder="Search table…" oninput="filterTable(this.value)">
        </div>
        <button class="btn-action btn-csv"   onclick="exportCSV()"><i class="bi bi-filetype-csv"></i> CSV</button>
        <button class="btn-action btn-excel" onclick="exportExcel()"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</button>
        <button class="btn-action btn-print" onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>

    <?php if ($sqlError): ?>
      <div class="ar-error"><span>⚠️</span><span><b>SQL Error (Received tab):</b> <?= htmlspecialchars($sqlError) ?></span></div>
    <?php elseif (empty($data)): ?>
      <div class="empty-state"><span class="icon">📭</span><p>No records found for the selected filters.</p></div>
    <?php else: ?>
      <div class="table-scroll">
        <table class="main-table" id="mainTable">
          <thead>
            <tr>
              <?php if ($tab === 'total_credit'): ?>
                <th onclick="sortTable(0)">Doc No</th>
                <th class="r" onclick="sortTable(1)"># Invoices</th>
                <th onclick="sortTable(2)">Remarks</th>
                <th onclick="sortTable(3)">Branch</th>
                <th onclick="sortTable(4)">Department</th>
                <th onclick="sortTable(5)">Salesman</th>
                <th onclick="sortTable(6)">Doc Date</th>
                <th class="r" onclick="sortTable(7)">Credit Amount</th>
                <th class="r" onclick="sortTable(8)">Days Old</th>
              <?php elseif ($tab === 'received'): ?>
                <th onclick="sortTable(0)">AFC No.</th>
                <th onclick="sortTable(1)">Branch</th>
                <th onclick="sortTable(2)">Department</th>
                <th onclick="sortTable(3)">Doc Date</th>
                <th onclick="sortTable(4)">Salesman</th>
                <th onclick="sortTable(5)">Area</th>
                <th class="r" onclick="sortTable(6)">Net Amount</th>
                <th class="r" onclick="sortTable(7)">Cash</th>
                <th class="r" onclick="sortTable(8)">Check</th>
                <th class="r" onclick="sortTable(9)">Total Remit</th>
                <th onclick="sortTable(10)">Remitted By</th>
                <th onclick="sortTable(11)">Received Date</th>
                <th onclick="sortTable(12)">Received By</th>
              <?php else: ?>
                <th onclick="sortTable(0)">AFC No.</th>
                <th onclick="sortTable(1)">AR Collection No</th>
                <th onclick="sortTable(2)">Remarks</th>
                <th onclick="sortTable(3)">Doc No</th>
                <th class="r" onclick="sortTable(4)"># Invoices</th>
                <th onclick="sortTable(5)">Department</th>
                <th onclick="sortTable(6)">Area</th>
                <th onclick="sortTable(7)">Salesman</th>
                <th onclick="sortTable(8)">Delivery Date</th>
                <th class="r" onclick="sortTable(9)">Invoice Amt</th>
                <th class="r" onclick="sortTable(10)">Paid</th>
                <th class="r" onclick="sortTable(11)">Balance</th>
                <th class="r" onclick="sortTable(12)">Days Old</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($displayData as $row):
              $days = (int)($row['DaysOld'] ?? 0);
              $daysCls = daysBadgeClass($days);
              $rowAccent = [
                'total_credit' => '#7c3aed', 'ar_created' => '#2563eb',
                'ar_collection' => '#0d9488', 'remitted' => '#ea580c',
                'received' => '#16a34a', 'uncollected' => '#dc2626',
              ][$tab] ?? '#7c3aed';
            ?>
            <?php
            $clickAttr = '';
            if ($tab === 'total_credit') {
                $jsDocNo    = addslashes($row['DocNo'] ?? '');
                $jsCustomer = addslashes($row['Customer'] ?? '');
                $jsCount    = (int)($row['InvoiceCount'] ?? 0);
                $clickAttr  = " onclick=\"openDocNoModal('{$jsDocNo}','{$jsCustomer}',{$jsCount})\" style=\"cursor:pointer;\"";
            } elseif ($tab !== 'received') {
                $jsAfcId    = (int)($row['ARForCollectionID'] ?? 0);
                $jsCustomer = addslashes($row['CustomerName'] ?? '');
                $jsCount    = (int)($row['InvoiceCount'] ?? 0);
                $jsArcNo    = addslashes($row['ARCollectionNo'] ?? '');
                $clickAttr  = " onclick=\"openARModal({$jsAfcId},'{$jsArcNo}','{$jsCustomer}',{$jsCount})\" style=\"cursor:pointer;\"";
            }
            ?>
            <tr style="border-left:3px solid <?= $rowAccent ?><?= $tab === 'uncollected' ? ';background:#fff5f5' : '' ?>"<?= $clickAttr ?>>
              <?php if ($tab === 'total_credit'): ?>
                <td><b style="color:var(--ar-accent);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></b> <span style="font-size:.65rem;color:#a78bfa;margin-left:.25rem;" title="Click to view invoice lines">&#128269; expand</span></td>
                <td class="r" style="font-weight:700"><?= (int)($row['InvoiceCount'] ?? 0) ?></td>
                <td><?= htmlspecialchars($row['RemarksSummary'] ?? '—') ?></td>
                <td><?= branchBadge($row['Branch'] ?? null) ?></td>
                <td><?= deptBadge($row['Department'] ?? null) ?></td>
                <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
                <td><?= fmtDate($row['DocDate'] ?? null) ?></td>
                <td class="r" style="color:var(--ar-accent);font-weight:700"><?= peso($row['CreditAmount'] ?? 0) ?></td>
                <td class="r"><span class="days-badge <?= $daysCls ?>"><?= $days ?>d</span></td>
              <?php elseif ($tab === 'received'): ?>
                <td><b style="font-family:'JetBrains Mono',monospace;color:var(--ar-blue)"><?= htmlspecialchars($row['AFCNo'] ?? $row['ARForColID'] ?? '—') ?></b></td>
                <td><?= branchBadge($row['Branch'] ?? null) ?></td>
                <td><?= deptBadge($row['Department'] ?? null) ?></td>
                <td><?= fmtDate($row['DocDate'] ?? null) ?></td>
                <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
                <td class="r" style="color:var(--ar-accent);font-weight:700"><?= peso($row['NetAmount'] ?? 0) ?></td>
                <td class="r"><?= peso($row['TotalCash'] ?? 0) ?></td>
                <td class="r"><?= peso($row['TotalCheck'] ?? 0) ?></td>
                <td class="r" style="color:var(--ar-green);font-weight:700"><?= peso($row['TotalRemit'] ?? 0) ?></td>
                <td><?= htmlspecialchars($row['RemittedByname'] ?? '—') ?></td>
                <td><?= fmtDate($row['ReceivedDate'] ?? null) ?></td>
                <td><?= htmlspecialchars($row['ReceivedBy'] ?? '—') ?></td>
              <?php else: ?>
                <td><b style="color:var(--ar-accent);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($row['ARForCollectionID'] ?? '—') ?></b> <span style="font-size:.65rem;color:#a78bfa;margin-left:.25rem;">&#128269; expand</span></td>
                <td><b style="font-family:'JetBrains Mono',monospace;color:var(--ar-blue)"><?= htmlspecialchars($row['ARCollectionNo'] ?? '—') ?></b></td>
                <td><?= htmlspecialchars($row['CustomerName'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['DocNo'] ?? '—') ?></td>
                <td class="r" style="font-weight:700"><?= (int)($row['InvoiceCount'] ?? 0) ?></td>
                <td><?= deptBadge($row['Department'] ?? null) ?></td>
                <td><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
                <td><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
                <td><?= fmtDate($row['DeliveryDate'] ?? null) ?></td>
                <td class="r" style="color:var(--ar-green);font-weight:700"><?= peso($row['InvoiceAmount'] ?? 0) ?></td>
                <td class="r"><?= peso($row['PaidAmount'] ?? 0) ?></td>
                <td class="r" style="<?= (float)($row['Balance'] ?? 0) > 0 ? 'color:var(--ar-red);font-weight:700' : '' ?>"><?= peso($row['Balance'] ?? 0) ?></td>
                <td class="r"><span class="days-badge <?= $daysCls ?>"><?= $days ?>d</span></td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
      <div class="pagination-bar">
        <span class="pagination-info">
          Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong>
          of <strong><?= number_format($totalRows) ?></strong> rows &nbsp;·&nbsp;
          Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
        </span>
        <div class="pagination-btns">
          <?php if ($prevUrl): ?>
            <a href="<?= $prevUrl ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Prev</a>
          <?php else: ?>
            <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Prev</span>
          <?php endif; ?>
          <?php if ($nextUrl): ?>
            <a href="<?= $nextUrl ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
          <?php else: ?>
            <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<script>
// ── Full dataset for export/print (not paginated) ────────────
const ALL_DATA = <?= json_encode(array_values($exportData), JSON_UNESCAPED_UNICODE) ?>;
const TAB      = '<?= $tab ?>';

// Sort ALL_DATA by DaysOld descending so print/export matches the table order
ALL_DATA.sort(function(a, b) { return parseInt(b.DaysOld||0) - parseInt(a.DaysOld||0); });
const TAB_TITLE = {
    total_credit:  '💳 Total Credit',
    ar_created:    '📋 AR Created',
    ar_collection: '🗂️ AR For Collection',
    remitted:      '📤 Remitted',
    received:      '🏦 Received',
    uncollected:   '⚠️ Uncollected',
}[TAB] || 'AR Remittance';

// ── Filter panel toggle ───────────────────────────────────────
function toggleFilter() {
    const body = document.getElementById('filterBody');
    const icon = document.getElementById('filterToggleIcon');
    const tags = document.getElementById('headerTags');
    const isOpen = body.classList.toggle('open');
    icon.classList.toggle('open', isOpen);
    tags.style.display = isOpen ? 'none' : 'flex';
}
<?php if ($anyFilterApplied): ?>toggleFilter();<?php endif; ?>

// ── Table search ──────────────────────────────────────────────
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

// ── Column sort ───────────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.replace(/[₱,\s▲▼d]/g, '') || '';
        const bv = b.cells[col]?.innerText.replace(/[₱,\s▲▼d]/g, '') || '';
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
        return _sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── CSV export (full dataset) ─────────────────────────────────
function exportCSV() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const headers = Object.keys(ALL_DATA[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...ALL_DATA.map(row => headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `ar_remittance_<?= $tab ?>_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Excel export (full dataset) ───────────────────────────────
function exportExcel() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const cleanData = ALL_DATA.map(row => {
        const r = {};
        for (let k in row) {
            let v = row[k];
            if (v && typeof v === 'object' && v.date) v = v.date.substring(0, 10);
            r[k] = v;
        }
        return r;
    });
    const ws = XLSX.utils.json_to_sheet(cleanData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, '<?= ucfirst(str_replace('_', ' ', $tab)) ?>');
    XLSX.writeFile(wb, `ar_remittance_<?= $tab ?>_<?= date('Ymd') ?>.xlsx`);
}

// ── Print (full dataset, proper print window) ─────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');

    function fmtDate(v) {
        if (!v) return '—';
        if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
        return String(v).substring(0, 10);
    }
    function peso(v) {
        return '₱ ' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function badge(label, cls) {
        return `<span class="badge ${cls}">${label ?? '—'}</span>`;
    }
    function deptBadge(d)   { return badge((d||'—').trim(), 'badge-dept'); }
    function branchBadge(b) { return badge(b||'—', 'badge-branch'); }
    function daysBadge(days) {
        const cls = days <= 7 ? 'days-ok' : days <= 30 ? 'days-warn' : 'days-danger';
        return `<span class="days-badge ${cls}">${days}d</span>`;
    }

    let thead = '', tbody = '';

    if (TAB === 'total_credit') {
        thead = `<thead><tr>
          <th>Doc No</th><th class="r"># Invoices</th><th>Customer</th><th>Branch</th>
          <th>Department</th><th>Salesman</th><th>Doc Date</th>
          <th class="r">Credit Amount</th><th class="r">Days Old</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
          <td class="mono">${r.DocNo??'—'}</td>
          <td class="r">${r.InvoiceCount??0}</td>
          <td>${r.Customer??'—'}</td>
          <td>${branchBadge(r.Branch)}</td>
          <td>${deptBadge(r.Department)}</td>
          <td>${r.Salesman??'—'}</td>
          <td>${fmtDate(r.DocDate)}</td>
          <td class="r text-purple">${peso(r.CreditAmount)}</td>
          <td class="r">${daysBadge(parseInt(r.DaysOld||0))}</td>
        </tr>`).join('') + '</tbody>';
    } else if (TAB === 'received') {
        thead = `<thead><tr>
          <th>AFC No.</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th>
          <th class="r">Net Amount</th><th class="r">Cash</th><th class="r">Check</th>
          <th class="r">Total Remit</th><th>Remitted By</th><th>Received Date</th><th>Received By</th>
          <th class="r">Days Old</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
          <td class="mono text-blue">${r.AFCNo??r.ARForColID??'—'}</td>
          <td>${branchBadge(r.Branch)}</td>
          <td>${deptBadge(r.Department)}</td>
          <td>${fmtDate(r.DocDate)}</td>
          <td>${r.Salesman??'—'}</td>
          <td>${r.Area??'—'}</td>
          <td class="r text-purple">${peso(r.NetAmount)}</td>
          <td class="r">${peso(r.TotalCash)}</td>
          <td class="r">${peso(r.TotalCheck)}</td>
          <td class="r text-green">${peso(r.TotalRemit)}</td>
          <td>${r.RemittedByname??'—'}</td>
          <td>${fmtDate(r.ReceivedDate)}</td>
          <td>${r.ReceivedBy??'—'}</td>
          <td class="r">${daysBadge(parseInt(r.DaysOld||0))}</td>
        </tr>`).join('') + '</tbody>';
    } else if (TAB === 'uncollected') {
        thead = `<thead><tr>
          <th>AFC No.</th><th>AR Collection No</th><th>Customer</th>
          <th>Doc No</th><th># Invoices</th><th>Department</th><th>Area</th>
          <th>Salesman</th><th>Delivery Date</th>
          <th class="r">Invoice Amt</th><th class="r">Paid</th><th class="r">Balance</th>
          <th class="r">Days Old</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const bal = parseFloat(r.Balance||0);
            return `<tr style="background:#fff5f5">
              <td class="mono text-purple">${r.ARForCollectionID??'—'}</td>
              <td class="mono">${r.ARCollectionNo??'—'}</td>
              <td>${r.CustomerName??'—'}</td>
              <td class="mono">${r.DocNo??'—'}</td>
              <td class="r">${r.InvoiceCount??0}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${r.Area??'—'}</td>
              <td>${r.Salesman??'—'}</td>
              <td>${fmtDate(r.DeliveryDate)}</td>
              <td class="r text-green">${peso(r.InvoiceAmount)}</td>
              <td class="r">${peso(r.PaidAmount)}</td>
              <td class="r${bal > 0 ? ' text-red' : ''}">${peso(bal)}</td>
              <td class="r">${daysBadge(parseInt(r.DaysOld||0))}</td>
            </tr>`;
        }).join('') + '</tbody>';
    } else {
        thead = `<thead><tr>
          <th>AFC No.</th><th>AR Collection No</th><th>Customer</th>
          <th>Doc No</th><th># Invoices</th><th>Department</th><th>Area</th>
          <th>Salesman</th><th>Delivery Date</th>
          <th class="r">Invoice Amt</th><th class="r">Paid</th><th class="r">Balance</th>
          <th class="r">Days Old</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const bal = parseFloat(r.Balance||0);
            return `<tr>
              <td class="mono text-purple">${r.ARForCollectionID??'—'}</td>
              <td class="mono">${r.ARCollectionNo??'—'}</td>
              <td>${r.CustomerName??'—'}</td>
              <td class="mono">${r.DocNo??'—'}</td>
              <td class="r">${r.InvoiceCount??0}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${r.Area??'—'}</td>
              <td>${r.Salesman??'—'}</td>
              <td>${fmtDate(r.DeliveryDate)}</td>
              <td class="r text-green">${peso(r.InvoiceAmount)}</td>
              <td class="r">${peso(r.PaidAmount)}</td>
              <td class="r${bal > 0 ? ' text-red' : ''}">${peso(bal)}</td>
              <td class="r">${daysBadge(parseInt(r.DaysOld||0))}</td>
            </tr>`;
        }).join('') + '</tbody>';
    }

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head>
      <title>${TAB_TITLE}</title>
      <style>
        @page { size: landscape; margin: 10mm 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; margin: 0; padding: 6px 8px; }
        .print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; border-bottom: 1.5px solid #333; padding-bottom: 4px; }
        .print-header h3 { margin: 0; font-size: 11px; font-weight: 800; }
        .print-header p  { margin: 0; font-size: 7.5px; color: #555; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #e8e8e8; font-weight: 700; font-size: 7.5px; text-transform: uppercase;
             letter-spacing: .03em; border: 1px solid #aaa; padding: 3px 4px; white-space: nowrap; }
        td { border: 1px solid #ccc; padding: 2px 4px; font-size: 8px; vertical-align: middle; }
        tr:nth-child(even) td { background: #f7f7f7; }
        .r { text-align: right; }
        .mono { font-family: 'Courier New', monospace; }
        .badge { font-size: 7px; padding: 0 3px; border-radius: 2px; font-weight: 700; display: inline-block; }
        .badge-branch { background: #e8e3ff; color: #4a1799; }
        .badge-dept   { background: #e8f0fe; color: #1a56b0; }
        .days-badge { font-size: 7px; padding: 0 3px; border-radius: 2px; font-weight: 700; display: inline-block; }
        .days-ok      { background: #dcfce7; color: #166534; }
        .days-warn    { background: #fef9c3; color: #713f12; }
        .days-danger  { background: #fee2e2; color: #991b1b; }
        .text-green  { color: #166534; font-weight: 700; }
        .text-red    { color: #991b1b; font-weight: 700; }
        .text-purple { color: #5b21b6; font-weight: 700; }
        .text-blue   { color: #1e40af; font-weight: 700; }
        @media print { body { padding: 0; } }
      </style>
    </head><body>
      <div class="print-header">
        <h3>${TAB_TITLE}</h3>
        <p>Date Range: <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?><br>Exported: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp; Total: ${ALL_DATA.length} records</p>
      </div>
      <table>${thead}${tbody}</table>
    </body></html>`);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

// -- DocNo Drill-Down Modal JS --
function openDocNoModal(docno, customer, invoiceCount) {
    document.getElementById('modalDocNo').textContent = docno;
    document.getElementById('modalSubtitle').textContent =
        '\u2014 ' + customer + ' (' + invoiceCount + ' invoice' + (invoiceCount === 1 ? '' : 's') + ')';
    document.getElementById('modalLoading').style.display = 'block';
    document.getElementById('modalTable').style.display   = 'none';
    document.getElementById('modalEmpty').style.display   = 'none';
    document.getElementById('modalTbody').innerHTML = '';
    document.getElementById('modalTfoot').innerHTML = '';
    var modal = document.getElementById('docnoModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    fetch('?ajax=docno_detail&docno=' + encodeURIComponent(docno))
        .then(function(res) { return res.json(); })
        .then(function(rows) {
            document.getElementById('modalLoading').style.display = 'none';
            if (!rows || !rows.length) {
                document.getElementById('modalEmpty').style.display = 'block';
                return;
            }
            rows.sort(function(a, b) { return parseInt(b.DaysOld||0) - parseInt(a.DaysOld||0); });
            var total = 0;
            var html  = '';
            rows.forEach(function(r) {
                var amt  = parseFloat(r.CreditAmount || 0);
                total   += amt;
                var days = parseInt(r.DaysOld || 0);
                var cls  = days <= 7 ? 'days-ok' : days <= 30 ? 'days-warn' : 'days-danger';
                var date = (r.DocDate || '').toString().substring(0, 10) || '\u2014';
                html += '<tr style="border-left:3px solid #7c3aed">'
                      + '<td style="font-family:\'JetBrains Mono\',monospace">' + (r.InvoiceNo || '\u2014') + '</td>'
                      + '<td>' + (r.Customer || '\u2014') + '</td>'
                      + '<td>' + date + '</td>'
                      + '<td class="r" style="color:#7c3aed;font-weight:700">&#8369; '
                      + amt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>'
                      + '<td class="r"><span class="days-badge ' + cls + '">' + days + 'd</span></td>'
                      + '</tr>';
            });
            document.getElementById('modalTbody').innerHTML = html;
            document.getElementById('modalTfoot').innerHTML =
                '<tr>'
                + '<td colspan="3" style="text-align:right;font-size:.75rem;color:var(--text-dim,#6b7280);padding:.5rem .75rem;">'
                + 'TOTAL (' + rows.length + ' line' + (rows.length === 1 ? '' : 's') + ')</td>'
                + '<td class="r" style="color:#7c3aed;font-weight:700;padding:.5rem .75rem;">'
                + '&#8369; ' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>'
                + '<td></td></tr>';
            document.getElementById('modalTable').style.display = 'table';
        })
        .catch(function() {
            document.getElementById('modalLoading').style.display = 'none';
            document.getElementById('modalEmpty').style.display   = 'block';
        });
}

function closeDocNoModal() {
    document.getElementById('docnoModal').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeDocNoModal(); closeARModal(); closeARDetailModal(); }
});
// -- AR Drill-Down Modal JS --
function openARModal(afcId, arcNo, customer, invoiceCount) {
    // Remitted and Uncollected get the full detailed modal
    if (TAB === 'remitted' || TAB === 'uncollected') {
        openARDetailModal(afcId, arcNo, customer, invoiceCount);
        return;
    }
    document.getElementById('arModalAFCID').textContent = afcId;
    document.getElementById('arModalArcNo').textContent = arcNo;
    document.getElementById('arModalSubtitle').textContent =
        '\u2014 ' + customer + ' (' + invoiceCount + ' invoice' + (invoiceCount === 1 ? '' : 's') + ')';
    document.getElementById('arModalLoading').style.display = 'block';
    document.getElementById('arModalTable').style.display   = 'none';
    document.getElementById('arModalEmpty').style.display   = 'none';
    document.getElementById('arModalTbody').innerHTML = '';
    document.getElementById('arModalTfoot').innerHTML = '';
    document.getElementById('arModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    fetch('?ajax=ar_detail&afc_id=' + encodeURIComponent(afcId))
        .then(function(res) { return res.json(); })
        .then(function(rows) {
            document.getElementById('arModalLoading').style.display = 'none';
            if (!rows || !rows.length) { document.getElementById('arModalEmpty').style.display = 'block'; return; }
            rows.sort(function(a, b) { return parseInt(b.DaysOld||0) - parseInt(a.DaysOld||0); });
            var totalAmt = 0, totalPaid = 0, totalBal = 0, html = '';
            rows.forEach(function(r) {
                var amt = parseFloat(r.InvoiceAmount||0), paid = parseFloat(r.PaidAmount||0), bal = parseFloat(r.Balance||0);
                totalAmt += amt; totalPaid += paid; totalBal += bal;
                var days = parseInt(r.DaysOld||0);
                var cls = days <= 7 ? 'days-ok' : days <= 30 ? 'days-warn' : 'days-danger';
                var f = function(v){ return '&#8369; '+v.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
                html += '<tr style="border-left:3px solid var(--ar-blue)">'
                    + '<td style="font-family:\'JetBrains Mono\',monospace">'+(r.InvoiceNo||'\u2014')+'</td>'
                    + '<td>'+((r.InvoiceDate||'').toString().substring(0,10)||'\u2014')+'</td>'
                    + '<td class="r" style="color:var(--ar-green);font-weight:700">'+f(amt)+'</td>'
                    + '<td class="r">'+f(paid)+'</td>'
                    + '<td class="r" style="'+(bal>0?'color:var(--ar-red);font-weight:700':'')+'">'+f(bal)+'</td>'
                    + '<td class="r"><span class="days-badge '+cls+'">'+days+'d</span></td>'
                    + '</tr>';
            });
            document.getElementById('arModalTbody').innerHTML = html;
            var f2 = function(v){ return '&#8369; '+v.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
            document.getElementById('arModalTfoot').innerHTML =
                '<tr><td colspan="2" style="text-align:right;font-size:.75rem;color:var(--text-dim,#6b7280);padding:.5rem .75rem;">TOTAL ('+rows.length+' line'+(rows.length===1?'':'s')+')</td>'
                +'<td class="r" style="color:var(--ar-green);font-weight:700;padding:.5rem .75rem;">'+f2(totalAmt)+'</td>'
                +'<td class="r" style="padding:.5rem .75rem;">'+f2(totalPaid)+'</td>'
                +'<td class="r" style="'+(totalBal>0?'color:var(--ar-red);':'')+'font-weight:700;padding:.5rem .75rem;">'+f2(totalBal)+'</td>'
                +'<td></td></tr>';
            document.getElementById('arModalTable').style.display = 'table';
        })
        .catch(function() {
            document.getElementById('arModalLoading').style.display = 'none';
            document.getElementById('arModalEmpty').style.display = 'block';
        });
}
function closeARModal() {
    document.getElementById('arModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('arModal').addEventListener('click', function(e) { if (e.target === this) closeARModal(); });

// -- AR Mode of Payment Modal (Remitted / Uncollected) --
function openARDetailModal(afcId, arcNo, customer, invoiceCount) {
    var modal = document.getElementById('arDetailModal');
    document.getElementById('arDetailAFCID').textContent    = afcId;
    document.getElementById('arDetailArcNo').textContent    = arcNo;
    document.getElementById('arDetailSubtitle').textContent = '\u2014 ' + customer + ' (' + invoiceCount + ' invoice' + (invoiceCount === 1 ? '' : 's') + ')';
    document.getElementById('arDetailLoading').style.display  = 'block';
    document.getElementById('arDetailContent').style.display  = 'none';
    document.getElementById('arDetailEmpty').style.display    = 'none';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    fetch('?ajax=ar_remittance_detail&afc_id=' + encodeURIComponent(afcId))
        .then(function(res) { return res.json(); })
        .then(function(rows) {
            document.getElementById('arDetailLoading').style.display = 'none';
            if (!rows || (rows.__error__)) {
                document.getElementById('arDetailEmpty').innerHTML = rows && rows.__error__
                    ? '<span style="color:#dc2626;font-size:.8rem;">SQL Error: ' + rows.__error__ + '</span>'
                    : 'No detail records found.';
                document.getElementById('arDetailEmpty').style.display = 'block';
                return;
            }
            if (!rows.length) {
                document.getElementById('arDetailEmpty').style.display = 'block';
                return;
            }
            rows.sort(function(a, b) { return parseInt(b.DaysOld||0) - parseInt(a.DaysOld||0); });

            var f    = function(v) { return '\u20B1\u00A0' + parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
            var dash = function(v) { var n = parseFloat(v||0); return n === 0 ? '\u2014' : f(n); };

            // ── Totals ───────────────────────────────────────────────
            var tInv=0, tTotal=0, tDed=0, tCheck=0, tCash=0, tBal=0;
            rows.forEach(function(r) {
                tInv   += parseFloat(r.InvoiceAmount||0);
                tTotal += parseFloat(r.TotalAmount||0);
                tDed   += parseFloat(r.Deduction||0);
                tCheck += parseFloat(r.CheckAmount||0);
                tCash  += parseFloat(r.Cash||0);
                tBal   += parseFloat(r.Balance||0);
            });

            var isUncollected = TAB === 'uncollected';
            var accentColor   = isUncollected ? 'var(--ar-red)' : 'var(--ar-orange)';

            // ── Summary strip ────────────────────────────────────────
            var first = rows[0];
            document.getElementById('arDetailSummary').innerHTML =
                '<div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;padding:.75rem 1.25rem;background:var(--input-bg,#f9fafb);border-bottom:1px solid var(--border,#e5e7eb);font-size:.78rem;">'
                + _minfo('Customer',   first.CustomerName||'\u2014')
                + _minfo('Del. Date',  (first.DeliveryDate||'\u2014').substring(0,10))
                + '</div>'
                + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0;border-bottom:2px solid var(--border,#e5e7eb);">'
                + _mstat('Invoice Total', f(tInv),   '#2563eb')
                + _mstat('Net Total',     f(tTotal),  '#0d9488')
                + _mstat('Deductions',    f(tDed),    '#ca8a04')
                + _mstat('Check',         f(tCheck),  '#7c3aed')
                + _mstat('Cash',          f(tCash),   '#16a34a')
                + _mstat('Balance',       f(tBal),    tBal > 0 ? '#dc2626' : '#16a34a')
                + '</div>'
                + (isUncollected
                    ? '<div style="padding:.4rem 1.25rem;background:#fff5f5;border-bottom:1px solid #fca5a5;display:flex;align-items:center;gap:.75rem;">'
                    +   '<span style="font-size:.75rem;color:#6b7280;">Show:</span>'
                    +   '<button id="btnShowWithBalance" onclick="_adFilter(true)"  style="padding:.25rem .8rem;border-radius:999px;border:1.5px solid #dc2626;background:#fee2e2;color:#b91c1c;font-size:.72rem;font-weight:700;cursor:pointer;">⚠️ With Balance</button>'
                    +   '<button id="btnShowAll"         onclick="_adFilter(false)" style="padding:.25rem .8rem;border-radius:999px;border:1.5px solid var(--border,#d1d5db);background:var(--card-bg,#fff);color:var(--text-dim,#6b7280);font-size:.72rem;font-weight:700;cursor:pointer;">All Invoices</button>'
                    + '</div>'
                    : '');

            window._adRows = rows;
            window._adHelpers = { f: f, dash: dash };

            _adRender(isUncollected ? rows.filter(function(r){ return parseFloat(r.Balance||0) > 0; }) : rows);
            document.getElementById('arDetailContent').style.display = 'block';
        })
        .catch(function() {
            document.getElementById('arDetailLoading').style.display = 'none';
            document.getElementById('arDetailEmpty').style.display   = 'block';
        });
}

function _minfo(label, val) {
    return '<span style="color:var(--text-dim,#6b7280);">' + label + ': <b style="color:var(--text,#111827);">' + val + '</b></span>';
}
function _mstat(label, val, color) {
    return '<div style="padding:.5rem .85rem;border-right:1px solid var(--border,#e5e7eb);border-bottom:1px solid var(--border,#e5e7eb);">'
        + '<div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim,#6b7280);font-weight:700;">' + label + '</div>'
        + '<div style="font-size:.88rem;font-weight:800;color:' + color + ';font-family:\'JetBrains Mono\',monospace;">' + val + '</div>'
        + '</div>';
}

// ── Toggle for Uncollected (balance-only vs all) ──────────────
function _adFilter(balanceOnly) {
    var btnBal = document.getElementById('btnShowWithBalance');
    var btnAll = document.getElementById('btnShowAll');
    if (balanceOnly) {
        btnBal.style.cssText += 'background:#fee2e2;border-color:#dc2626;color:#b91c1c;';
        btnAll.style.cssText += 'background:var(--card-bg,#fff);border-color:var(--border,#d1d5db);color:var(--text-dim,#6b7280);';
        _adRender(window._adRows.filter(function(r){ return parseFloat(r.Balance||0) > 0; }));
    } else {
        btnAll.style.cssText += 'background:#ede9fe;border-color:#a78bfa;color:#5b21b6;';
        btnBal.style.cssText += 'background:var(--card-bg,#fff);border-color:var(--border,#d1d5db);color:var(--text-dim,#6b7280);';
        _adRender(window._adRows);
    }
}

// ── Mode of Payment table renderer ───────────────────────────
function _adRender(rows) {
    var h    = window._adHelpers;
    var f    = h.f;
    var dash = h.dash;

    var tInv=0, tTotal=0, tDed=0, tCheck=0, tCash=0, tBal=0;
    rows.forEach(function(r) {
        tInv   += parseFloat(r.InvoiceAmount||0);
        tTotal += parseFloat(r.TotalAmount||0);
        tDed   += parseFloat(r.Deduction||0);
        tCheck += parseFloat(r.CheckAmount||0);
        tCash  += parseFloat(r.Cash||0);
        tBal   += parseFloat(r.Balance||0);
    });

    var tbody = '';
    rows.forEach(function(r) {
        var bal  = parseFloat(r.Balance||0);
        var days = parseInt(r.DaysOld||0);
        var cls  = days <= 7 ? 'days-ok' : days <= 30 ? 'days-warn' : 'days-danger';
        tbody += '<tr style="' + (bal > 0 && TAB === 'uncollected' ? 'background:#fff5f5;' : '') + '">'
            + '<td style="font-family:\'JetBrains Mono\',monospace;font-weight:700;color:var(--ar-blue);white-space:nowrap;">' + (r.InvoiceNo||'\u2014') + '</td>'
            + '<td style="white-space:nowrap;">' + (r.DeliveryDate||'\u2014').substring(0,10) + '</td>'
            + '<td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (r.CustomerName||'') + '">' + (r.CustomerName||'\u2014') + '</td>'
            + '<td style="white-space:nowrap;color:var(--text-dim,#6b7280);">' + (r.ARRemarks||'\u2014') + '</td>'
            + '<td class="r" style="font-weight:700;">'   + f(r.InvoiceAmount) + '</td>'
            + '<td class="r">'                            + dash(r.TotalAmount) + '</td>'
            + '<td class="r" style="color:' + (parseFloat(r.Deduction||0)>0 ? '#dc2626' : 'inherit') + ';">' + dash(r.Deduction) + '</td>'
            + '<td style="white-space:nowrap;font-size:.75rem;">' + (r.Bank||'\u2014') + '</td>'
            + '<td style="white-space:nowrap;font-family:\'JetBrains Mono\',monospace;font-size:.75rem;">' + (r.CheckNo||'\u2014') + '</td>'
            + '<td style="white-space:nowrap;font-size:.75rem;">' + (r.CheckDate ? (r.CheckDate).substring(0,10) : '\u2014') + '</td>'
            + '<td class="r" style="color:var(--ar-accent);">' + dash(r.CheckAmount) + '</td>'
            + '<td class="r" style="color:var(--ar-green);">'  + dash(r.Cash)  + '</td>'
            + '<td class="r" style="color:' + (bal > 0 ? '#dc2626' : 'inherit') + ';font-weight:' + (bal > 0 ? '700' : '400') + ';">' + dash(r.Balance) + '</td>'
            + '<td style="white-space:nowrap;font-size:.75rem;">' + (r.Terms||'\u2014') + '</td>'
            + '<td class="r"><span class="days-badge ' + cls + '">' + days + 'd</span></td>'
            + '</tr>';
    });

    var tfoot = '<tr style="font-weight:700;background:var(--input-bg,#f9fafb);border-top:2px solid var(--border,#e5e7eb);">'
        + '<td colspan="4" style="text-align:right;font-size:.72rem;color:var(--text-dim,#6b7280);padding:.5rem .75rem;">TOTAL (' + rows.length + ' line' + (rows.length===1?'':'s') + ')</td>'
        + '<td class="r">' + f(tInv)   + '</td>'
        + '<td class="r">' + f(tTotal) + '</td>'
        + '<td class="r" style="color:' + (tDed>0?'#dc2626':'inherit') + ';">' + (tDed>0?f(tDed):'\u2014') + '</td>'
        + '<td colspan="3"></td>'
        + '<td class="r" style="color:var(--ar-accent);">' + (tCheck>0?f(tCheck):'\u2014') + '</td>'
        + '<td class="r" style="color:var(--ar-green);">'  + (tCash>0?f(tCash):'\u2014')   + '</td>'
        + '<td class="r" style="color:' + (tBal>0?'#dc2626':'var(--ar-green)') + ';">' + f(tBal) + '</td>'
        + '<td colspan="2"></td>'
        + '</tr>';

    document.getElementById('arDetailTbody').innerHTML = tbody;
    document.getElementById('arDetailTfoot').innerHTML = tfoot;
    document.getElementById('arDetailTable').style.display = 'table';

    if (!rows.length) {
        document.getElementById('arDetailTable').style.display = 'none';
        document.getElementById('arDetailEmpty').style.display = 'block';
    }
}

// Fallback renderer (kept for ar_created / ar_collection simple modal)
function _renderARDetailSimple(rows) {
    var f = function(v) { return '\u20B1\u00A0' + parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); };
    var html = '';
    rows.forEach(function(r) {
        var days = parseInt(r.DaysOld||0);
        var cls  = days <= 7 ? 'days-ok' : days <= 30 ? 'days-warn' : 'days-danger';
        var bal  = parseFloat(r.Balance||0);
        html += '<div style="border:1px solid var(--border,#e5e7eb);border-radius:10px;margin:.75rem 1.25rem;overflow:hidden;">'
            + '<div style="padding:.6rem 1rem;display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;align-items:center;background:rgba(37,99,235,.06);border-bottom:1px solid var(--border,#e5e7eb);">'
            +   '<span style="font-family:\'JetBrains Mono\',monospace;font-weight:800;color:var(--ar-blue);">' + (r.InvoiceNo||'\u2014') + '</span>'
            +   '<span style="font-size:.72rem;color:var(--text-dim,#6b7280);">\ud83d\udcc5 ' + (r.InvoiceDate||'\u2014').substring(0,10) + '</span>'
            +   '<span style="margin-left:auto;"><span class="days-badge ' + cls + '">' + days + 'd</span></span>'
            + '</div>'
            + '</div>';
    });
    document.getElementById('arDetailRows').innerHTML = html;
    document.getElementById('arDetailContent').style.display = 'block';
}

// ── Unused legacy stubs (kept so other code referencing _pill/_amtcell doesn't break) ──
function _pill(a,b){ return ''; }
function _amtcell(a,b,c,d,e,f){ return ''; }

function closeARDetailModal() {
    document.getElementById('arDetailModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('arDetailModal').addEventListener('click', function(e) { if (e.target === this) closeARDetailModal(); });
</script>

<!-- AR Drill-Down Modal -->
<div id="arModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:16px;width:min(900px,95vw);max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border,#e5e7eb);background:var(--input-bg,#f9fafb);">
      <div style="font-weight:700;font-size:.95rem;color:var(--text,#111827);display:flex;align-items:center;gap:.5rem;">
        <span>📋</span>
        <span style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:600;">AFC</span>
        <span id="arModalAFCID" style="font-family:'JetBrains Mono',monospace;color:var(--ar-accent);"></span>
        <span style="color:var(--text-dim,#d1d5db);">·</span>
        <span id="arModalArcNo" style="font-family:'JetBrains Mono',monospace;color:var(--ar-blue);font-size:.85rem;"></span>
        <span id="arModalSubtitle" style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"></span>
      </div>
      <button onclick="closeARModal()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:var(--text-dim,#9ca3af);line-height:1;padding:.2rem .4rem;">&times;</button>
    </div>
    <div style="overflow-y:auto;flex:1;">
      <div id="arModalLoading" style="text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);"><div style="font-size:1.5rem;margin-bottom:.5rem;">⏳</div>Loading invoices...</div>
      <table id="arModalTable" class="main-table" style="display:none;">
        <thead><tr>
          <th>Invoice No</th><th>Invoice Date</th>
          <th class="r">Invoice Amt</th><th class="r">Paid</th>
          <th class="r">Balance</th><th class="r">Days Old</th>
        </tr></thead>
        <tbody id="arModalTbody"></tbody>
        <tfoot id="arModalTfoot" style="font-weight:700;background:var(--input-bg,#f9fafb);border-top:2px solid var(--border,#e5e7eb);"></tfoot>
      </table>
      <div id="arModalEmpty" style="display:none;text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);">No invoice lines found.</div>
    </div>
  </div>
</div>

<!-- DocNo Drill-Down Modal -->
<div id="docnoModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:16px;width:min(860px,95vw);max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border,#e5e7eb);background:var(--input-bg,#f9fafb);">
      <div style="font-weight:700;font-size:.95rem;color:var(--text,#111827);display:flex;align-items:center;gap:.5rem;">
        <span>&#128179;</span>
        <span id="modalDocNo" style="font-family:'JetBrains Mono',monospace;color:#7c3aed;"></span>
        <span id="modalSubtitle" style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"></span>
      </div>
      <button onclick="closeDocNoModal()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:var(--text-dim,#9ca3af);line-height:1;padding:.2rem .4rem;">&times;</button>
    </div>
    <div style="overflow-y:auto;flex:1;">
      <div id="modalLoading" style="text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);">
        <div style="font-size:1.5rem;margin-bottom:.5rem;">&#9203;</div>Loading invoices...
      </div>
      <table id="modalTable" class="main-table" style="display:none;">
        <thead><tr>
          <th>Invoice No</th><th>Customer</th><th>Doc Date</th>
          <th class="r">Credit Amount</th><th class="r">Days Old</th>
        </tr></thead>
        <tbody id="modalTbody"></tbody>
        <tfoot id="modalTfoot" style="font-weight:700;background:var(--input-bg,#f9fafb);border-top:2px solid var(--border,#e5e7eb);"></tfoot>
      </table>
      <div id="modalEmpty" style="display:none;text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);">No invoice lines found.</div>
    </div>
  </div>
</div>
<!-- AR Mode of Payment Modal (Remitted / Uncollected) -->
<div id="arDetailModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
  <div style="background:var(--card-bg,#fff);border-radius:16px;width:min(1100px,98vw);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border,#e5e7eb);background:var(--input-bg,#f9fafb);flex-shrink:0;">
      <div style="font-weight:700;font-size:.95rem;color:var(--text,#111827);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
        <span>💳</span>
        <span style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:600;">AFC</span>
        <span id="arDetailAFCID" style="font-family:'JetBrains Mono',monospace;color:var(--ar-accent);"></span>
        <span style="color:var(--text-dim,#d1d5db);">·</span>
        <span id="arDetailArcNo" style="font-family:'JetBrains Mono',monospace;color:var(--ar-blue);font-size:.85rem;"></span>
        <span id="arDetailSubtitle" style="font-size:.72rem;color:var(--text-dim,#6b7280);font-weight:400;"></span>
      </div>
      <button onclick="closeARDetailModal()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:var(--text-dim,#9ca3af);line-height:1;padding:.2rem .4rem;">&times;</button>
    </div>
    <!-- Loading -->
    <div id="arDetailLoading" style="text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);"><div style="font-size:1.5rem;margin-bottom:.5rem;">⏳</div>Loading details...</div>
    <!-- Empty -->
    <div id="arDetailEmpty" style="display:none;text-align:center;padding:2.5rem;color:var(--text-dim,#9ca3af);">No detail records found.</div>
    <!-- Content -->
    <div id="arDetailContent" style="display:none;overflow-y:auto;flex:1;flex-direction:column;">
      <!-- Summary strip + totals + filter bar injected here -->
      <div id="arDetailSummary" style="flex-shrink:0;"></div>
      <!-- Mode of Payment table -->
      <div style="overflow-x:auto;">
        <table id="arDetailTable" class="main-table" style="display:none;">
          <thead><tr>
            <th>Inv. No</th>
            <th>Del. Date</th>
            <th>Customer</th>
            <th>Remarks</th>
            <th class="r">Inv. Amount</th>
            <th class="r">Net Total</th>
            <th class="r">Deduction</th>
            <th>Bank</th>
            <th>Check No</th>
            <th>Chk. Date</th>
            <th class="r">Chk. Amt</th>
            <th class="r">Cash</th>
            <th class="r">Balance</th>
            <th>Terms</th>
            <th class="r">Days</th>
          </tr></thead>
          <tbody id="arDetailTbody"></tbody>
          <tfoot id="arDetailTfoot" style="font-weight:700;background:var(--input-bg,#f9fafb);border-top:2px solid var(--border,#e5e7eb);"></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

</body>
</html>