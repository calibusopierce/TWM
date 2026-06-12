<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'delivery_remittance');

// ── Current user department/branch context ───────────────────
$currentUserDept   = $_SESSION['Department']  ?? ($_SESSION['department']  ?? '');
$currentUserBranch = $_SESSION['Branch']      ?? ($_SESSION['branch']      ?? '');

// ── Valid tabs ───────────────────────────────────────────────
$validTabs = ['summary', 'pending', 'unserved', 'delivered', 'unremitted', 'remitted', 'received', 'by_salesman', 'shorts', 'by_leadman'];
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $validTabs) ? $_GET['tab'] : 'summary';

// ── Date filters ─────────────────────────────────────────────
$today     = date('Y-m-d');
$monthFrom = date('Y-m-01');
$monthTo   = date('Y-m-t');

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim($_GET['date_from']) : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? trim($_GET['date_to'])   : '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');
if (!$dateActive) {
    $baseFrom = $monthFrom;
    $baseTo   = $monthTo;
} else {
    $baseFrom = $dateFrom !== '' ? $dateFrom : $monthFrom;
    $baseTo   = $dateTo   !== '' ? $dateTo   : $today;
}

// ── Other filters ────────────────────────────────────────────
$selBranch     = isset($_GET['branch'])      ? trim($_GET['branch'])      : '';
$selArea       = isset($_GET['area'])        ? trim($_GET['area'])        : '';
$selSalesman   = isset($_GET['salesman'])    ? trim($_GET['salesman'])    : '';
$selRemittedBy = isset($_GET['remitted_by']) ? trim($_GET['remitted_by']) : '';
$selStatus     = isset($_GET['status'])      ? trim($_GET['status'])      : '';
$selDept       = isset($_GET['dept'])        ? trim($_GET['dept'])        : $currentUserDept;
$selSummaryView = isset($_GET['summary_view']) ? trim($_GET['summary_view']) : '';

$branchActive     = $selBranch     !== '';
$areaActive       = $selArea       !== '';
$salesmanActive   = $selSalesman   !== '';
$remittedByActive = $selRemittedBy !== '';
$statusActive     = $selStatus     !== '';
$deptActive       = $selDept       !== '';

$anyFilterApplied = $dateActive || $branchActive || $areaActive || $salesmanActive || $statusActive || $deptActive || $remittedByActive;

// ── Safe values ───────────────────────────────────────────────
$_branchSafe     = str_replace("'", "''", $selBranch);
$_areaSafe       = str_replace("'", "''", $selArea);
$_salesmanSafe   = str_replace("'", "''", $selSalesman);
$_remittedBySafe = str_replace("'", "''", $selRemittedBy);
$_statusSafe     = str_replace("'", "''", $selStatus);
$_deptSafe       = str_replace("'", "''", $selDept);

// ── WHERE clauses (unaliased — for single-table queries) ──────
$branchWhere   = $branchActive   ? " AND Branch = '$_branchSafe'"         : '';
$areaWhere     = $areaActive     ? " AND Area = '$_areaSafe'"             : '';
$salesmanWhere = $salesmanActive ? " AND SalesmanCode = '$_salesmanSafe'" : '';
$statusWhere   = $statusActive   ? " AND Status = '$_statusSafe'"         : '';
$deptWhere     = $deptActive     ? " AND RTRIM(Department) = '$_deptSafe'"       : '';
$commonWhere   = $branchWhere . $areaWhere . $salesmanWhere . $statusWhere . $deptWhere;

// ── WHERE clauses (aliased s. — for JOIN queries) ─────────────
$branchWhereS   = $branchActive   ? " AND s.Branch = '$_branchSafe'"         : '';
$areaWhereS     = $areaActive     ? " AND s.Area = '$_areaSafe'"             : '';
$salesmanWhereS = $salesmanActive ? " AND s.SalesmanCode = '$_salesmanSafe'" : '';
$statusWhereS   = $statusActive   ? " AND s.Status = '$_statusSafe'"         : '';
$deptWhereS     = $deptActive     ? " AND RTRIM(s.Department) = '$_deptSafe'"       : '';
$commonWhereS   = $branchWhereS . $areaWhereS . $salesmanWhereS . $statusWhereS . $deptWhereS;

// ── Remitted-by WHERE (for View_DeliverySummaryRemitted queries only) ─
$remittedByWhere  = $remittedByActive ? " AND RemittedByName = '$_remittedBySafe'" : '';

// ── WHERE clauses (aliased v. — for received JOIN query) ──────
$branchWhereV     = $branchActive   ? " AND v.Branch = '$_branchSafe'"               : '';
$areaWhereV       = $areaActive     ? " AND v.Area = '$_areaSafe'"                   : '';
$salesmanWhereV   = $salesmanActive ? " AND v.SalesmanCode = '$_salesmanSafe'"       : '';
$statusWhereV     = $statusActive   ? " AND v.Status = '$_statusSafe'"               : '';
$deptWhereV       = $deptActive     ? " AND RTRIM(v.Department) = '$_deptSafe'"      : '';
$commonWhereV     = $branchWhereV . $areaWhereV . $salesmanWhereV . $statusWhereV . $deptWhereV;
$remittedByWhereV = $remittedByActive ? " AND v.RemittedByName = '$_remittedBySafe'" : '';

// ── Helper: run a query and return all rows ──────────────────
function runQuery($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

// ── Helper: lookup list ──────────────────────────────────────
function lookupList($conn, string $sql): array {
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) return [];
    $list = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $list[] = array_values($row)[0];
    sqlsrv_free_stmt($stmt);
    return $list;
}

// ── Lookup dropdowns (respect dept + branch context) ─────────
$dropdownWhere  = '';
$dropdownWhere .= $deptActive   ? " AND Department = '$_deptSafe'"   : '';
$dropdownWhere .= $branchActive ? " AND Branch = '$_branchSafe'"     : '';

$areaList       = lookupList($conn, "SELECT DISTINCT Area FROM [dbo].[View_SummaryTotal] WHERE Area IS NOT NULL AND Area <> '' $dropdownWhere ORDER BY Area");
$salesmanList   = lookupList($conn, "SELECT DISTINCT SalesmanCode FROM [dbo].[View_SummaryTotal] WHERE SalesmanCode IS NOT NULL AND SalesmanCode <> '' $dropdownWhere ORDER BY SalesmanCode");
$remittedByList = lookupList($conn, "SELECT DISTINCT RemittedByName FROM [dbo].[View_DeliverySummaryRemitted] WHERE RemittedByName IS NOT NULL AND RemittedByName <> '' $dropdownWhere ORDER BY RemittedByName");

// ── Single combined stat query ────────────────────────────────
$statSql = "
WITH RemitAgg AS (
    SELECT
        DocNo,
        MAX(CASE WHEN RRID IS NOT NULL AND RRID != 0 THEN 1 ELSE 0 END) AS IsReceived,
        ROUND(SUM(CAST(TotalRemit AS FLOAT)), 2)                         AS TotalRemit
    FROM [dbo].[View_DeliverySummaryRemitted]
    GROUP BY DocNo
)
SELECT
    COUNT(DISTINCT s.DocNo)                                            AS TotalDeliveries,
    COUNT(DISTINCT s.SalesmanID)                                       AS TotalSalesmen,
    ROUND(SUM(CAST(s.TotalNetAmount AS FLOAT)), 2)                     AS TotalNetAmount,

    SUM(CASE WHEN (s.DeliveredID = 0 OR s.DeliveredID IS NULL)
             AND r.DocNo IS NULL
             THEN 1 ELSE 0 END)                                        AS PendingCount,
    SUM(CASE WHEN (s.DeliveredID IS NOT NULL AND s.DeliveredID != 0)
             OR r.DocNo IS NOT NULL
             THEN 1 ELSE 0 END)                                        AS DeliveredCount,

    -- Unremitted: delivered but no entry in remittance at all
    SUM(CASE WHEN s.DeliveredID IS NOT NULL AND s.DeliveredID != 0
             AND r.DocNo IS NULL
             THEN 1 ELSE 0 END)                                        AS UnremittedCount,

    -- Remit pending: has remittance row but not yet received by finance
    SUM(CASE WHEN r.DocNo IS NOT NULL AND r.IsReceived = 0
             THEN 1 ELSE 0 END)                                        AS RemittedPendingCount,

    -- Received: finance confirmed
    SUM(CASE WHEN r.IsReceived = 1
             THEN 1 ELSE 0 END)                                        AS ReceivedCount,

    ROUND(SUM(CASE WHEN r.IsReceived = 1
                   THEN r.TotalRemit ELSE 0 END), 2)                   AS TotalRemitted,
    ROUND(SUM(ISNULL(r.TotalRemit, 0)), 2)                             AS TotalRemitAll,

    -- Created count (Status = 'CREATED')
    SUM(CASE WHEN UPPER(RTRIM(LTRIM(s.Status))) = 'CREATED'
             THEN 1 ELSE 0 END)                                        AS CreatedCount

FROM [dbo].[View_SummaryTotal] s
LEFT JOIN RemitAgg r ON r.DocNo = s.DocNo
WHERE s.DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhereS
";

$statRow = runQuery($conn, $statSql)[0] ?? [];

$unservedSql = "
SELECT COUNT(*) AS UnservedCount
FROM [dbo].[View_SummaryUnserve]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
";
$unservedCount = runQuery($conn, $unservedSql)[0]['UnservedCount'] ?? 0;

$totalDeliveries      = $statRow['TotalDeliveries']      ?? 0;
$totalSalesmen        = $statRow['TotalSalesmen']        ?? 0;
$totalNetAmount       = $statRow['TotalNetAmount']       ?? 0;
$pendingCount         = $statRow['PendingCount']         ?? 0;
$deliveredCount       = $statRow['DeliveredCount']       ?? 0;
$unremittedCount      = $statRow['UnremittedCount']      ?? 0;
$remittedPendingCount = $statRow['RemittedPendingCount'] ?? 0;
$receivedCount        = $statRow['ReceivedCount']        ?? 0;
$totalRemitted        = $statRow['TotalRemitted']        ?? 0;
$totalRemitAll        = $statRow['TotalRemitAll']        ?? 0;
$createdCount         = $statRow['CreatedCount']         ?? 0;

// ── Shorts stat (only UNSETTLED: PaymentID IS NULL or <= 0) ─
$shortsSql = "
SELECT
    COUNT(*)                                          AS ShortsCount,
    ROUND(SUM(CAST(TotalDifference AS FLOAT)),2) AS TotalShorts
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE TotalDifference > 2
  AND ISNULL(PaymentID, 0) = 0
  AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
";
$shortsRow   = runQuery($conn, $shortsSql)[0] ?? [];
$shortsCount = $shortsRow['ShortsCount'] ?? 0;
$totalShorts = $shortsRow['TotalShorts'] ?? 0;

// ── Settled shorts count (for stat card sub-label) ────────
$settledShortsSql = "
SELECT COUNT(*) AS Cnt
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE TotalDifference > 2
  AND ISNULL(PaymentID, 0) > 0
  AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
";
$settledShortsCount = runQuery($conn, $settledShortsSql)[0]['Cnt'] ?? 0;

// ── By Leadman stat ───────────────────────────────────────
$leadmanStatSql = "
SELECT
    COUNT(DISTINCT RemittedByName)                          AS TotalLeadmen,
    ROUND(SUM(CAST(TotalRemit AS FLOAT)),2)                 AS TotalLeadmanRemit,
    ROUND(SUM(CAST(TotalCancel AS FLOAT)),2)                AS TotalLeadmanCancel,
    SUM(CASE WHEN RRID IS NOT NULL AND RRID != 0 THEN 1 ELSE 0 END) AS LeadmanReceivedCount,
    SUM(CASE WHEN RRID IS NULL OR RRID = 0 THEN 1 ELSE 0 END)       AS LeadmanPendingCount
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
";
$leadmanStatRow        = runQuery($conn, $leadmanStatSql)[0] ?? [];
$totalLeadmen          = $leadmanStatRow['TotalLeadmen']          ?? 0;
$totalLeadmanRemit     = $leadmanStatRow['TotalLeadmanRemit']     ?? 0;
$totalLeadmanCancel    = $leadmanStatRow['TotalLeadmanCancel']    ?? 0;
$leadmanReceivedCount  = $leadmanStatRow['LeadmanReceivedCount']  ?? 0;
$leadmanPendingCount   = $leadmanStatRow['LeadmanPendingCount']   ?? 0;

// ── Tab data queries ─────────────────────────────────────────

// Summary view filter (CREATED / DELIVERED toggle)
$summaryViewWhere = '';
if ($selSummaryView === 'CREATED') {
    $summaryViewWhere = " AND UPPER(RTRIM(LTRIM(Status))) = 'CREATED'";
} elseif ($selSummaryView === 'DELIVERED') {
    $summaryViewWhere = " AND UPPER(RTRIM(LTRIM(Status))) = 'DELIVERED'";
} elseif ($selSummaryView === 'REMITTED') {
    $summaryViewWhere = " AND UPPER(RTRIM(LTRIM(Status))) = 'REMITTED'";
}

$q_summary = "
SELECT
    DocNo, Branch, Department, Type, DocDate, SalesmanID, Salesman, SalesmanCode, Area,
    DeliveryNo, TotalCases, TotalBag, TotalBundle, TotalPcs, TotalCalls,
    TotalGrossAmount, TotalManualLess, TotalManualAdd, TotalNetAmount,
    Status, Remarks, DID, DeliveredID, RemittedID, CollectedID, CancelledID, FinalID,
    RRID, FirstName, InputName, DateTimeInput,
    CASE WHEN UPPER(RTRIM(LTRIM(Status))) = 'CREATED' 
         THEN DATEDIFF(day, DocDate, GETDATE()) 
         ELSE 0 
    END AS DaysOld
FROM [dbo].[View_SummaryTotal]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $summaryViewWhere
ORDER BY DaysOld DESC, DocNo
";

$q_unserved = "
SELECT
    DocNo, Branch, Department, DocDate, Salesman, SalesmanCode, Area,
    DeliveryNo, TotalCases, TotalCalls, TotalNetAmount,
    Status, Remarks, CancelledID, CancelledDate, Note,
    InvoiceNo, Customer
FROM [dbo].[View_SummaryUnserve]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
ORDER BY DocDate DESC, DocNo
";

$q_pending = "
SELECT
    DocNo, Branch, Department, Type, DocDate, SalesmanID, Salesman, SalesmanCode, Area,
    DeliveryNo, TotalCases, TotalBag, TotalBundle, TotalPcs, TotalCalls,
    TotalGrossAmount, TotalManualLess, TotalManualAdd, TotalNetAmount,
    Status, Remarks, DID, DeliveredID, RemittedID, CollectedID, CancelledID, FinalID,
    RRID, FirstName, InputName, DateTimeInput
FROM [dbo].[View_SummaryTotal]
WHERE (DeliveredID = 0 OR DeliveredID IS NULL)
  AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
ORDER BY DocDate DESC, DocNo
";

$q_delivered = "
SELECT
    DocNo, Branch, DID, Department, DocDate, SalesmanID, Salesman, SalesmanCode, Area,
    TotalCases, TotalBag, TotalBundle, TotalPcs, TotalCalls,
    TotalGrossAmount, TotalManualLess, TotalManualAdd, TotalNetAmount,
    Status, StatusID, Remarks, TruckScheduleID, ScheduleDate,
    PlateNumber, DelRemarks, RemittedID
FROM [dbo].[View_DeliverySummaryDelivered]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
ORDER BY DocDate DESC, DocNo
";

$q_unremitted = "
SELECT
    s.DocNo, s.Branch, s.Department, s.DocDate, s.SalesmanCode, s.Salesman, s.Area,
    s.TotalCases, s.TotalBag, s.TotalBundle, s.TotalPcs, s.TotalCalls,
    s.TotalGrossAmount, s.TotalManualLess, s.TotalManualAdd, s.TotalNetAmount,
    s.Status, s.Remarks,
    DATEDIFF(day, s.DocDate, GETDATE()) AS DaysOld
FROM [dbo].[View_SummaryTotal] s
LEFT JOIN [dbo].[View_DeliverySummaryRemitted] r ON r.DocNo = s.DocNo
WHERE r.DocNo IS NULL
  AND s.DeliveredID IS NOT NULL AND s.DeliveredID != 0
  AND s.DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhereS
ORDER BY s.DocDate ASC, s.DocNo
";

$q_remitted = "
SELECT
    DocNo, Branch, Department, DocDate, SalesmanCode, Area,
    TotalNetAmount, TotalCash, TotalCheck, TotalCredit, TotalCancel, TotalAdjustment, TotalRemit,
    Remarks, RRID, RemittedBy, DateRemit, DeliveryDate, RemittedByName,
    RemitanceRemarks, TotalDifference,
    DATEDIFF(day, DateRemit, GETDATE()) AS DaysOld
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE (RRID = 0 OR RRID IS NULL)
  AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
ORDER BY DocDate DESC, DocNo
";

$q_received = "
SELECT
    v.DocNo, v.Branch, v.Department, v.DocDate, v.SalesmanCode, v.Area,
    v.TotalNetAmount, v.TotalCash, v.TotalCheck, v.TotalCredit, v.TotalCancel, v.TotalAdjustment, v.TotalRemit,
    v.Remarks, v.RemittedBy, v.DateRemit, v.DeliveryDate, v.RemittedByName,
    v.RemitanceRemarks, v.TotalDifference,
    rrd.LastUpdate AS DateReceived,
    ISNULL(emp.FirstName + ' ' + emp.LastName, rrd.LastUpdateUser) AS ReceivedBy
FROM [dbo].[View_DeliverySummaryRemitted] v
LEFT JOIN [dbo].[Tbl_ReceivingRecord_Details] rrd ON rrd.RRID = v.RRID AND rrd.DocNo = v.DocNo
LEFT JOIN [dbo].[TBL_HREmployeeList] emp ON emp.EmployeeID = rrd.LastUpdateUser
WHERE v.RRID IS NOT NULL AND v.RRID <> 0
  AND v.DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhereV
  $remittedByWhereV
ORDER BY v.DateRemit DESC, v.DocNo
";

$q_by_salesman = "
SELECT
    SalesmanCode, Salesman, Area, Branch,
    COUNT(DISTINCT DocNo)                           AS TotalDeliveries,
    ROUND(SUM(CAST(TotalNetAmount AS FLOAT)),2)     AS TotalNetAmount,
    ROUND(AVG(CAST(TotalNetAmount AS FLOAT)),2)     AS AvgNetAmount,
    SUM(CAST(TotalCases AS INT))                    AS TotalCases,
    SUM(CAST(TotalCalls AS INT))                    AS TotalCalls,
    SUM(CASE WHEN DeliveredID = 0 OR DeliveredID IS NULL THEN 1 ELSE 0 END)       AS PendingCount,
    SUM(CASE WHEN DeliveredID != 0 AND DeliveredID IS NOT NULL THEN 1 ELSE 0 END) AS DeliveredCount
FROM [dbo].[View_SummaryTotal]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
GROUP BY SalesmanCode, Salesman, Area, Branch
ORDER BY TotalNetAmount DESC
";

$shortsViewFilter = isset($_GET['shorts_view']) && $_GET['shorts_view'] === 'settled'
    ? " AND ISNULL(PaymentID, 0) > 0"
    : " AND ISNULL(PaymentID, 0) = 0";

$q_shorts = "
SELECT
    DocNo, Branch, Department, DocDate, SalesmanCode, Area,
    TotalNetAmount, TotalCash, TotalCheck, TotalCredit, TotalCancel, TotalAdjustment, TotalRemit,
    TotalDifference, Remarks, RRID, RemittedBy, DateRemit, DeliveryDate, RemittedByName,
    RemitanceRemarks, PaymentID
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE TotalDifference > 2
  $shortsViewFilter
  AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
ORDER BY TotalDifference ASC, DocDate DESC
";

$q_by_leadman = "
SELECT
    RemittedByName,
    COUNT(DISTINCT DocNo)                                   AS TotalRemittances,
    ROUND(SUM(CAST(TotalNetAmount AS FLOAT)),2)             AS TotalNetAmount,
    ROUND(SUM(CAST(TotalRemit AS FLOAT)),2)                 AS TotalRemitted,
    ROUND(SUM(CAST(TotalCancel AS FLOAT)),2)                AS TotalCancelled,
    ROUND(SUM(CAST(TotalCash AS FLOAT)),2)                  AS TotalCash,
    ROUND(SUM(CAST(TotalCheck AS FLOAT)),2)                 AS TotalCheck,
    ROUND(SUM(CAST(TotalCredit AS FLOAT)),2)                AS TotalCredit,
    ROUND(SUM(CAST(TotalDifference AS FLOAT)),2)            AS TotalDifference,
    SUM(CASE WHEN RRID IS NOT NULL AND RRID != 0 THEN 1 ELSE 0 END) AS RemittedCount,
    SUM(CASE WHEN RRID IS NULL OR RRID = 0 THEN 1 ELSE 0 END)       AS PendingCount,
    SUM(CASE WHEN TotalDifference > 2 AND ISNULL(PaymentID, 0) = 0 THEN 1 ELSE 0 END) AS ShortsCount,
    ROUND(SUM(CASE WHEN TotalDifference > 2 AND ISNULL(PaymentID, 0) = 0 THEN ABS(CAST(TotalDifference AS FLOAT)) ELSE 0 END),2) AS TotalShorts
FROM [dbo].[View_DeliverySummaryRemitted]
WHERE DocDate BETWEEN '$baseFrom' AND '$baseTo'
  $commonWhere
  $remittedByWhere
GROUP BY RemittedByName
ORDER BY TotalRemitted DESC
";

$queryMap = [
    'summary'      => $q_summary,
    'pending'      => $q_pending,
    'unserved'     => $q_unserved,
    'delivered'    => $q_delivered,
    'unremitted'   => $q_unremitted,
    'remitted'     => $q_remitted,
    'received'     => $q_received,
    'by_salesman'  => $q_by_salesman,
    'shorts'       => $q_shorts,
    'by_leadman'   => $q_by_leadman,
];
$data = runQuery($conn, $queryMap[$tab]);

// ── Pagination ───────────────────────────────────────────────
$rowLimit   = 20;
$totalRows  = count($data);
$totalPages = max(1, (int)ceil($totalRows / $rowLimit));
$curPage    = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset     = ($curPage - 1) * $rowLimit;
$displayData = array_slice($data, $offset, $rowLimit);
$exportData  = $data;

// Normalize all DateTime values
foreach ($exportData as &$row) {
    foreach ($row as $key => $val) {
        if ($val instanceof DateTime) {
            $row[$key] = $val->format('Y-m-d');
        }
    }
}
unset($row);

function pageUrl(int $p): string {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
$prevUrl = $curPage > 1           ? pageUrl($curPage - 1) : '';
$nextUrl = $curPage < $totalPages ? pageUrl($curPage + 1) : '';

// ── Unreceived modal data ─────────────────────────────────────
$_remittedNameSafe  = str_replace("'", "''", $selRemittedBy);
$remittedByWhereVDR = $remittedByActive ? " AND RemittedName = '$_remittedNameSafe'" : '';

$unreceivedModalSql = "
    SELECT
        DocNo, Branch, DocDate, DeliveryDate, SalesmanCode, Area,
        Remarks, RemitRemarks,
        TotalNetAmount, TotalCash, TotalCheck, TotalCredit, TotalCancel, TotalRemit, TotalDifference,
        RemittedName AS RemittedByName, RRID, DateRemit, PaymentID
    FROM [dbo].[View_DeliveryRemittance]
    WHERE (RRID IS NULL OR RRID = 0)
      AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
      $commonWhere
      $remittedByWhereVDR
    ORDER BY RemittedName, DocDate DESC
";
$unreceivedRows = runQuery($conn, $unreceivedModalSql);
$unreceivedGrouped = [];
foreach ($unreceivedRows as $r) {
    $name = $r['RemittedByName'] ?? '';
    foreach ($r as $k => $v) {
        if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d');
    }
    $unreceivedGrouped[$name][] = $r;
}

// ── NEW: Received modal data (RRID not null/0) ────────────────
$receivedModalSql = "
    SELECT
        DocNo, Branch, DocDate, DeliveryDate, SalesmanCode, Area,
        Remarks, RemitRemarks,
        TotalNetAmount, TotalCash, TotalCheck, TotalCredit, TotalCancel, TotalRemit, TotalDifference,
        RemittedName AS RemittedByName, RRID, DateRemit, PaymentID
    FROM [dbo].[View_DeliveryRemittance]
    WHERE RRID IS NOT NULL AND RRID != 0
      AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
      $commonWhere
      $remittedByWhereVDR
    ORDER BY RemittedName, DocDate DESC
";
$receivedRows = runQuery($conn, $receivedModalSql);
$receivedGrouped = [];
foreach ($receivedRows as $r) {
    $name = $r['RemittedByName'] ?? '';
    foreach ($r as $k => $v) {
        if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d');
    }
    $receivedGrouped[$name][] = $r;
}

// ── NEW: Shorts modal data (unsettled shorts per leadman) ─────
$shortsModalSql = "
    SELECT
        DocNo, Branch, DocDate, DeliveryDate, SalesmanCode, Area,
        Remarks, RemitRemarks,
        TotalNetAmount, TotalCash, TotalCheck, TotalCredit, TotalCancel, TotalRemit, TotalDifference,
        RemittedName AS RemittedByName, RRID, DateRemit, PaymentID
    FROM [dbo].[View_DeliveryRemittance]
    WHERE TotalDifference > 2
      AND ISNULL(PaymentID, 0) = 0
      AND DocDate BETWEEN '$baseFrom' AND '$baseTo'
      $commonWhere
      $remittedByWhereVDR
    ORDER BY RemittedName, TotalDifference DESC
";
$shortsModalRows = runQuery($conn, $shortsModalSql);
$shortsGrouped = [];
foreach ($shortsModalRows as $r) {
    $name = $r['RemittedByName'] ?? '';
    foreach ($r as $k => $v) {
        if ($v instanceof DateTime) $r[$k] = $v->format('Y-m-d');
    }
    $shortsGrouped[$name][] = $r;
}


// NOTE: $conn is intentionally NOT closed here.
// topbar.php (included later on this page) calls get_employee_profile($conn),
// which runs a sqlsrv_query against the same connection. Closing $conn here
// caused: "supplied resource is not a valid ss_sqlsrv_conn resource" on line 172
// of nav.php. PHP will close the connection automatically when the script ends.

// ── Helpers ──────────────────────────────────────────────────
function peso($v): string { return '₱ ' . number_format((float)($v ?? 0), 2); }
function fmt($v, int $dec = 2): string { return number_format((float)($v ?? 0), $dec); }

function fmtDate($d): string {
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    return htmlspecialchars($d ?? '—');
}

function statusBadge(?string $s): string {
    $map = [
        'DELIVERED' => ['#dcfce7','#166534','#4ade80'],
        'PENDING'   => ['#fef9c3','#713f12','#fde047'],
        'CANCELLED' => ['#fee2e2','#991b1b','#f87171'],
        'POSTED'    => ['#dbeafe','#1e3a8a','#60a5fa'],
    ];
    $key = strtoupper(trim($s ?? ''));
    [$bg,$text,$border] = $map[$key] ?? ['#f3f4f6','#374151','#d1d5db'];
    return "<span style='background:$bg;color:$text;border:1px solid $border;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:600;white-space:nowrap;'>" . htmlspecialchars($s ?? '—') . "</span>";
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

function tabUrl(string $t): string {
    $p = $_GET; $p['tab'] = $t; unset($p['page']); unset($p['summary_view']);
    return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery Remittance Dashboard — Tradewell</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root {
  --remit-accent : #7c3aed;
  --remit-accent2: #a78bfa;
  --remit-green  : #16a34a;
  --remit-yellow : #ca8a04;
  --remit-red    : #dc2626;
  --remit-blue   : #2563eb;
  --remit-orange : #ea580c;
}

/* ── Stat Cards ─────────────────────────────────────────── */
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
  text-decoration: none;
}
.stat-card::before {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, transparent 60%, rgba(124,58,237,.04));
  pointer-events: none;
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(124,58,237,.12);
  border-color: var(--remit-accent2);
}
.stat-card-total { border-left: 3px solid var(--remit-accent); }
.sc-icon  { font-size: 1.4rem; margin-bottom: .15rem; }
.sc-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: var(--text-dim, #6b7280); font-weight: 700; }
.sc-value { font-size: 1.5rem; font-weight: 800; line-height: 1.1; color: var(--text, #111827); font-family: 'JetBrains Mono', monospace; }
.sc-sub   { font-size: .72rem; color: var(--text-dim, #6b7280); margin-top: .1rem; }

/* ── Filter Panel ───────────────────────────────────────── */
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
  cursor: pointer;
  user-select: none;
}
.filter-panel-header-left {
  display: flex; align-items: center; gap: .6rem;
  font-size: .82rem; font-weight: 700; color: var(--text, #374151);
}
.filter-panel-header-right {
  display: flex; align-items: center; gap: .5rem;
}
.filter-active-tags {
  display: flex; flex-wrap: wrap; gap: .35rem;
}
.filter-tag {
  display: inline-flex; align-items: center; gap: .25rem;
  padding: 2px 9px; border-radius: 999px; font-size: .7rem; font-weight: 600;
  background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd;
  white-space: nowrap;
}
.filter-tag.tag-date   { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.filter-tag.tag-branch { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
.filter-tag.tag-area   { background: #fef9c3; color: #713f12; border-color: #fde047; }
.filter-tag.tag-salesman { background: #dcfce7; color: #166534; border-color: #4ade80; }
.filter-tag.tag-remby  { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
.filter-toggle-icon { font-size: .75rem; color: var(--text-dim, #9ca3af); transition: transform .2s; }
.filter-toggle-icon.open { transform: rotate(180deg); }

.filter-body {
  padding: 1rem 1.25rem 1.1rem;
  display: none;
}
.filter-body.open { display: block; }

.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem;
  margin-bottom: .875rem;
}
.filter-group {
  display: flex; flex-direction: column; gap: .3rem;
}
.filter-group label {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .05em; color: var(--text-dim, #6b7280);
}
.filter-group input[type=date],
.filter-group select {
  font-size: .82rem;
  padding: .4rem .7rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px;
  background: var(--input-bg, #f9fafb);
  color: var(--text, #111827);
  height: 36px;
  width: 100%;
  transition: border-color .15s, box-shadow .15s;
}
.filter-group input[type=date]:focus,
.filter-group select:focus {
  outline: none;
  border-color: var(--remit-accent);
  box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}
.filter-actions {
  display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}
.btn-filter {
  display: inline-flex; align-items: center; gap: .35rem;
  padding: .45rem 1.1rem; border-radius: 8px;
  font-size: .8rem; font-weight: 700; cursor: pointer;
  border: none; height: 36px; transition: all .15s;
}
.btn-filter.apply {
  background: var(--remit-accent); color: #fff;
  box-shadow: 0 1px 4px rgba(124,58,237,.3);
}
.btn-filter.apply:hover { background: #6d28d9; box-shadow: 0 3px 10px rgba(124,58,237,.4); }
.btn-filter.reset {
  background: var(--input-bg, #f3f4f6);
  color: var(--text, #374151);
  border: 1.5px solid var(--border, #d1d5db);
}
.btn-filter.reset:hover { background: #e5e7eb; }

/* ── Search Box ─────────────────────────────────────────── */
.search-wrap {
  position: relative; flex: 1; min-width: 180px; max-width: 280px;
}
.search-wrap i {
  position: absolute; left: .65rem; top: 50%; transform: translateY(-50%);
  color: var(--text-dim, #9ca3af); font-size: .85rem; pointer-events: none;
}
.search-wrap input {
  width: 100%; padding: .4rem .7rem .4rem 2rem;
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px;
  background: var(--input-bg, #f9fafb);
  font-size: .82rem;
  color: var(--text, #111827);
  height: 36px;
  transition: border-color .15s, box-shadow .15s;
}
.search-wrap input:focus {
  outline: none;
  border-color: var(--remit-accent);
  box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}

/* ── Tab Navigation ─────────────────────────────────────── */
.tab-nav {
  display: flex; flex-wrap: wrap; gap: .4rem;
  margin-bottom: 1rem;
}
.tab-nav a {
  padding: .45rem 1.1rem; border-radius: 999px;
  font-size: .81rem; font-weight: 600;
  border: 1.5px solid var(--border, #e5e7eb);
  color: var(--text-dim, #6b7280);
  text-decoration: none;
  background: var(--card-bg, #fff);
  transition: all .18s;
  display: flex; align-items: center; gap: .35rem;
}
.tab-nav a:hover { border-color: var(--remit-accent2); color: var(--remit-accent); }
.tab-nav a.active { background: var(--remit-accent); color: #fff; border-color: var(--remit-accent); }
.tab-badge {
  border-radius: 999px; padding: 0 6px;
  font-size: .68rem; font-weight: 700;
  background: rgba(255,255,255,.25);
}
.tab-nav a:not(.active) .tab-badge {
  background: var(--border, #e5e7eb);
  color: var(--text-dim, #6b7280);
}

/* ── Table overrides ────────────────────────────────────── */
.doc-no { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: .82rem; color: var(--remit-accent); }
.salesman-tag {
  display: inline-block;
  background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd;
  border-radius: 999px; padding: 1px 9px; font-size: .73rem; font-weight: 600;
}
.amount-pos { color: var(--remit-green); font-weight: 700; }
.amount-neg { color: var(--remit-red);   font-weight: 700; }
.rrid-badge {
  display: inline-block;
  background: #dcfce7; color: #166534; border: 1px solid #4ade80;
  border-radius: 999px; padding: 1px 9px; font-size: .73rem; font-weight: 600;
}

@media print {
  body * { visibility: hidden; }
  #mainTable, #mainTable * { visibility: visible; }
  #mainTable { position: absolute; left: 0; top: 0; width: 100%; font-size: 10px; }
}

/* ── Summary View Toggle ────────────────────────────────── */
.summary-view-toggle {
  display: flex; align-items: center; gap: .25rem;
  background: var(--input-bg, #f3f4f6);
  border: 1.5px solid var(--border, #d1d5db);
  border-radius: 8px;
  padding: 3px;
  height: 36px;
}
.svt-btn {
  padding: 2px 12px; border-radius: 6px;
  font-size: .78rem; font-weight: 600;
  color: var(--text-dim, #6b7280);
  text-decoration: none;
  transition: all .15s;
  white-space: nowrap;
  height: 28px; display: flex; align-items: center;
}
.svt-btn:hover { background: #e5e7eb; color: var(--text, #374151); }
.svt-active { background: #fff; color: var(--remit-accent) !important; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
.svt-active.svt-created   { background: #fef9c3; color: #713f12 !important; }
.svt-active.svt-delivered { background: #dcfce7; color: #166534 !important; }

/* ── Modal shared styles ────────────────────────────────── */
.lm-modal-overlay {
  display: none;
  position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,.45);
  backdrop-filter: blur(2px);
  align-items: center; justify-content: center;
}
.lm-modal-box {
  background: var(--card-bg, #fff);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
  width: 95%; max-width: 1100px; max-height: 88vh;
  display: flex; flex-direction: column; overflow: hidden;
}
.lm-modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--border, #e5e7eb);
  background: var(--input-bg, #f9fafb);
}
.lm-modal-header-left { display: flex; flex-direction: column; gap: 2px; }
.lm-modal-title { font-weight: 800; font-size: 1rem; color: var(--text, #111827); }
.lm-modal-sub   { font-size: .78rem; color: var(--text-dim, #6b7280); }
.lm-modal-header-right { display: flex; align-items: center; gap: .5rem; }
.lm-modal-body { overflow: auto; flex: 1; padding: 1rem 1.5rem; }

/* Modal export buttons */
.modal-export-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .3rem .85rem; border-radius: 7px;
  font-size: .76rem; font-weight: 700; cursor: pointer;
  border: 1.5px solid transparent; height: 32px;
  transition: all .15s; white-space: nowrap;
}
.modal-export-btn.csv   { background: #f0fdf4; color: #166534; border-color: #4ade80; }
.modal-export-btn.csv:hover { background: #dcfce7; }
.modal-export-btn.excel { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.modal-export-btn.excel:hover { background: #dbeafe; }
.modal-export-btn.print { background: #f5f3ff; color: #5b21b6; border-color: #c4b5fd; }
.modal-export-btn.print:hover { background: #ede9fe; }
.modal-close-btn {
  background: none; border: none; font-size: 1.4rem;
  cursor: pointer; color: var(--text-dim, #9ca3af);
  line-height: 1; padding: 0 4px;
}
.modal-close-btn:hover { color: var(--remit-red); }

/* Clickable count cells */
.clickable-count {
  cursor: pointer;
  text-decoration: underline dotted;
  transition: opacity .15s;
}
.clickable-count:hover { opacity: .75; }
</style>
</head>
<body>

<?php $topbar_page = 'delivery_remittance'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">

  <!-- ── Page Header ──────────────────────────────────────── -->
  <div class="page-header">
    <div>
      <div class="page-title">Delivery <span>Remittance</span> Dashboard</div>
      <div class="page-badge">
        📅 <?= $anyFilterApplied
            ? 'Filtered: ' . htmlspecialchars($baseFrom) . ' → ' . htmlspecialchars($baseTo)
            : 'This Month: ' . date('F Y') ?>
        · Live Data
      </div>
    </div>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────── -->
  <div class="stat-grid">
    <a href="<?= tabUrl('summary') ?>" class="stat-card stat-card-total">
      <span class="sc-icon">📋</span>
      <span class="sc-label">Period Overview</span>
      <span class="sc-value" style="font-size:1.1rem"><?= number_format($totalDeliveries) ?> docs</span>
      <span class="sc-sub">₱ <?= number_format($totalNetAmount, 2) ?> net · <?= number_format($totalSalesmen) ?> salesmen</span>
    </a>
    <a href="<?= tabUrl('pending') ?>" class="stat-card">
      <span class="sc-icon">🕐</span>
      <span class="sc-label">Created</span>
      <span class="sc-value" style="color:var(--remit-yellow)"><?= number_format($pendingCount) ?></span>
      <span class="sc-sub">Awaiting delivery</span>
    </a>
    <a href="<?= tabUrl('delivered') ?>" class="stat-card">
      <span class="sc-icon">✅</span>
      <span class="sc-label">Delivered</span>
      <span class="sc-value" style="color:var(--remit-green)"><?= number_format($deliveredCount) ?></span>
      <span class="sc-sub">Confirmed delivered (incl. remitted)</span>
    </a>
    <a href="<?= tabUrl('unremitted') ?>" class="stat-card">
      <span class="sc-icon">⚠️</span>
      <span class="sc-label">Unremitted</span>
      <span class="sc-value" style="color:var(--remit-orange)"><?= number_format($unremittedCount) ?></span>
      <span class="sc-sub">Delivered, not yet remitted</span>
    </a>
    <a href="<?= tabUrl('remitted') ?>" class="stat-card">
      <span class="sc-icon">💸</span>
      <span class="sc-label">Remit Pending</span>
      <span class="sc-value" style="color:var(--remit-red)"><?= number_format($remittedPendingCount) ?></span>
      <span class="sc-sub">Not yet received</span>
    </a>
    <a href="<?= tabUrl('received') ?>" class="stat-card">
      <span class="sc-icon">🏦</span>
      <span class="sc-label">Received</span>
      <span class="sc-value" style="color:var(--remit-blue)"><?= number_format($receivedCount) ?></span>
      <span class="sc-sub">Finance confirmed</span>
    </a>
    <a href="<?= tabUrl('unserved') ?>" class="stat-card">
      <span class="sc-icon">🚫</span>
      <span class="sc-label">Unserved</span>
      <span class="sc-value" style="color:var(--remit-red)"><?= number_format($unservedCount) ?></span>
      <span class="sc-sub">Cancelled / unserved</span>
    </a>
    <a href="<?= tabUrl('shorts') ?>" class="stat-card" style="border-left:3px solid var(--remit-red);">
      <span class="sc-icon">📉</span>
      <span class="sc-label">Unsettled Shorts</span>
      <span class="sc-value" style="color:var(--remit-red)"><?= number_format($shortsCount) ?></span>
      <span class="sc-sub" style="color:var(--remit-red);font-weight:700;">▼ <?= peso($totalShorts) ?> short</span>
      <?php if ($settledShortsCount > 0): ?>
      <span class="sc-sub" style="margin-top:.2rem;">
        <span style="background:#dcfce7;color:#166534;border:1px solid #4ade80;border-radius:999px;padding:1px 7px;font-size:.68rem;font-weight:700;">✓ <?= number_format($settledShortsCount) ?> settled</span>
      </span>
      <?php endif; ?>
    </a>
    <a href="<?= tabUrl('by_leadman') ?>" class="stat-card" style="border-left:3px solid var(--remit-blue);">
      <span class="sc-icon">👥</span>
      <span class="sc-label">By Leadman</span>
      <span class="sc-value" style="color:var(--remit-blue)"><?= number_format($totalLeadmen) ?></span>
      <span class="sc-sub">Remitted: <?= peso($totalLeadmanRemit) ?></span>
      <span class="sc-sub">Unreceived: <b><?= number_format($leadmanPendingCount) ?></b> · Received: <b><?= number_format($leadmanReceivedCount) ?></b></span>
    </a>
    <a href="<?= tabUrl('by_salesman') ?>" class="stat-card">
      <span class="sc-icon">🧑‍💼</span>
      <span class="sc-label">Active Salesmen</span>
      <span class="sc-value"><?= number_format($totalSalesmen) ?></span>
      <span class="sc-sub">Unique this period</span>
    </a>
    <a href="<?= tabUrl('summary') ?>" class="stat-card">
      <span class="sc-icon">💰</span>
      <span class="sc-label">Total Net Amount</span>
      <span class="sc-value" style="font-size:1.1rem"><?= peso($totalNetAmount) ?></span>
      <span class="sc-sub">Remitted: <?= peso($totalRemitAll) ?></span>
    </a>
  </div>

  <!-- ── Filter Panel ─────────────────────────────────────── -->
  <form method="GET" action="" id="filterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">

    <div class="filter-panel">
      <div class="filter-panel-header" onclick="toggleFilter()">
        <div class="filter-panel-header-left">
          <i class="bi bi-funnel-fill" style="color:var(--remit-accent)"></i>
          Filters
          <?php if ($anyFilterApplied): ?>
            <span style="background:var(--remit-accent);color:#fff;border-radius:999px;padding:1px 8px;font-size:.68rem;">Active</span>
          <?php endif; ?>
        </div>
        <div class="filter-panel-header-right">
          <div class="filter-active-tags" id="headerTags">
            <?php if ($dateActive): ?>
              <span class="filter-tag tag-date"><i class="bi bi-calendar3"></i> <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?></span>
            <?php endif; ?>
            <?php if ($branchActive): ?>
              <span class="filter-tag tag-branch"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selBranch) ?></span>
            <?php endif; ?>
            <?php if ($areaActive): ?>
              <span class="filter-tag tag-area"><i class="bi bi-map"></i> <?= htmlspecialchars($selArea) ?></span>
            <?php endif; ?>
            <?php if ($salesmanActive): ?>
              <span class="filter-tag tag-salesman"><i class="bi bi-person"></i> <?= htmlspecialchars($selSalesman) ?></span>
            <?php endif; ?>
            <?php if ($remittedByActive): ?>
              <span class="filter-tag tag-remby"><i class="bi bi-person-check"></i> <?= htmlspecialchars($selRemittedBy) ?></span>
            <?php endif; ?>
          </div>
          <i class="bi bi-chevron-down filter-toggle-icon" id="filterToggleIcon"></i>
        </div>
      </div>

      <div class="filter-body" id="filterBody">
        <div class="filter-grid">
          <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
          </div>
          <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
          </div>
          <div class="filter-group">
            <label><i class="bi bi-geo-alt"></i> Branch</label>
            <select name="branch">
              <option value="">All Branches</option>
              <option value="Quezon"       <?= $selBranch === 'Quezon'       ? 'selected' : '' ?>>Quezon</option>
              <option value="Quezon Upper" <?= $selBranch === 'Quezon Upper' ? 'selected' : '' ?>>Quezon Upper</option>
              <option value="Marinduque"   <?= $selBranch === 'Marinduque'   ? 'selected' : '' ?>>Marinduque</option>
            </select>
          </div>
          <div class="filter-group">
            <label><i class="bi bi-map"></i> Area</label>
            <select name="area">
              <option value="">All Areas</option>
              <?php foreach ($areaList as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $selArea === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label><i class="bi bi-person"></i> Salesman</label>
            <select name="salesman">
              <option value="">All Salesmen</option>
              <?php foreach ($salesmanList as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $selSalesman === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label><i class="bi bi-person-check"></i> Remitted By</label>
            <select name="remitted_by">
              <option value="">All</option>
              <?php foreach ($remittedByList as $rb): ?>
                <option value="<?= htmlspecialchars($rb) ?>" <?= $selRemittedBy === $rb ? 'selected' : '' ?>><?= htmlspecialchars($rb) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn-filter apply"><i class="bi bi-funnel"></i> Apply Filters</button>
          <a href="?tab=<?= htmlspecialchars($tab) ?>" class="btn-filter reset"><i class="bi bi-x-circle"></i> Reset</a>
        </div>
      </div>
    </div>
  </form>

  <!-- ── Tab Navigation ───────────────────────────────────── -->
  <div class="tab-nav">
    <a href="<?= tabUrl('summary') ?>"     class="<?= $tab === 'summary'     ? 'active' : '' ?>"><i class="bi bi-grid-3x3-gap"></i> Summary</a>
    <a href="<?= tabUrl('pending') ?>"     class="<?= $tab === 'pending'     ? 'active' : '' ?>"><i class="bi bi-hourglass-split"></i> Pending <span class="tab-badge"><?= number_format($pendingCount) ?></span></a>
    <a href="<?= tabUrl('delivered') ?>"   class="<?= $tab === 'delivered'   ? 'active' : '' ?>"><i class="bi bi-truck"></i> Delivered <span class="tab-badge"><?= number_format($deliveredCount) ?></span></a>
    <a href="<?= tabUrl('unremitted') ?>"  class="<?= $tab === 'unremitted'  ? 'active' : '' ?>"><i class="bi bi-exclamation-triangle"></i> Unremitted <span class="tab-badge"><?= number_format($unremittedCount) ?></span></a>
    <a href="<?= tabUrl('remitted') ?>"    class="<?= $tab === 'remitted'    ? 'active' : '' ?>"><i class="bi bi-send"></i> Remitted <span class="tab-badge"><?= number_format($remittedPendingCount) ?></span></a>
    <a href="<?= tabUrl('received') ?>"    class="<?= $tab === 'received'    ? 'active' : '' ?>"><i class="bi bi-bank"></i> Received <span class="tab-badge"><?= number_format($receivedCount) ?></span></a>
    <a href="<?= tabUrl('unserved') ?>"    class="<?= $tab === 'unserved'    ? 'active' : '' ?>"><i class="bi bi-x-circle"></i> Unserved <span class="tab-badge"><?= number_format($unservedCount) ?></span></a>
    <a href="<?= tabUrl('shorts') ?>"      class="<?= $tab === 'shorts'      ? 'active' : '' ?>" style="<?= $tab !== 'shorts' ? 'color:var(--remit-red);border-color:#fca5a5;' : '' ?>"><i class="bi bi-graph-down-arrow"></i> Shorts <span class="tab-badge" style="<?= $tab !== 'shorts' && $shortsCount > 0 ? 'background:#fee2e2;color:#991b1b;' : '' ?>"><?= number_format($shortsCount) ?></span></a>
    <a href="<?= tabUrl('by_leadman') ?>"  class="<?= $tab === 'by_leadman'  ? 'active' : '' ?>"><i class="bi bi-people-fill"></i> By Leadman <span class="tab-badge"><?= number_format($totalLeadmen) ?></span></a>
    <a href="<?= tabUrl('by_salesman') ?>" class="<?= $tab === 'by_salesman' ? 'active' : '' ?>"><i class="bi bi-person-lines-fill"></i> By Salesman</a>
  </div>

  <!-- ── Table Section ────────────────────────────────────── -->
  <div class="table-section">
    <div class="table-header">
      <div class="table-title">
        <?php
        $tabTitles = [
            'summary'     => '📊 Delivery Summary',
            'pending'     => '🕐 Pending Deliveries',
            'unserved'    => '🚫 Unserved / Cancelled',
            'delivered'   => '✅ Delivered',
            'unremitted'  => '⚠️ Unremitted (Delivered, Not Yet Remitted)',
            'remitted'    => '💸 Remitted (Pending Receipt)',
            'received'    => '🏦 Received by Finance',
            'by_salesman' => '🧑‍💼 Summary by Salesman',
            'shorts'       => '📉 Shorts (Under-remitted)',
            'by_leadman'   => '👥 By Leadman / Remitter',
        ];
        echo $tabTitles[$tab];
        ?>
        <span class="table-count"><?= $totalRows ?> records</span>
        <?php if ($anyFilterApplied): ?>
          <span class="table-count" style="background:#fef9c3;color:#713f12;border-color:#fde047;">Filtered</span>
        <?php endif; ?>
      </div>
      <div class="table-actions">
        <?php if ($tab === 'shorts'): ?>
        <?php
          $shortsViewCurrent = isset($_GET['shorts_view']) && $_GET['shorts_view'] === 'settled' ? 'settled' : 'unsettled';
          function shortsViewUrl(string $v): string {
              $p = $_GET; $p['shorts_view'] = $v; unset($p['page']);
              return '?' . http_build_query($p);
          }
        ?>
        <div class="summary-view-toggle">
          <a href="<?= shortsViewUrl('unsettled') ?>" class="svt-btn <?= $shortsViewCurrent === 'unsettled' ? 'svt-active' : '' ?>" style="<?= $shortsViewCurrent === 'unsettled' ? 'background:#fee2e2;color:#991b1b !important;' : '' ?>">
            ⚠ Unsettled <span style="background:<?= $shortsViewCurrent === 'unsettled' ? 'rgba(153,27,27,.15)' : '#e5e7eb' ?>;border-radius:999px;padding:0 6px;font-size:.68rem;"><?= number_format($shortsCount) ?></span>
          </a>
          <a href="<?= shortsViewUrl('settled') ?>" class="svt-btn <?= $shortsViewCurrent === 'settled' ? 'svt-active' : '' ?>" style="<?= $shortsViewCurrent === 'settled' ? 'background:#dcfce7;color:#166534 !important;' : '' ?>">
            ✓ Settled <span style="background:<?= $shortsViewCurrent === 'settled' ? 'rgba(22,101,52,.15)' : '#e5e7eb' ?>;border-radius:999px;padding:0 6px;font-size:.68rem;"><?= number_format($settledShortsCount) ?></span>
          </a>
        </div>
        <?php endif; ?>
        <?php if ($tab === 'summary'): ?>
        <div class="summary-view-toggle">
          <?php
            function summaryViewUrl(string $v): string {
                $p = $_GET; $p['summary_view'] = $v; unset($p['page']);
                return '?' . http_build_query($p);
            }
          ?>
          <a href="<?= summaryViewUrl('') ?>"          class="svt-btn <?= $selSummaryView === ''           ? 'svt-active' : '' ?>">All</a>
          <a href="<?= summaryViewUrl('CREATED') ?>"   class="svt-btn <?= $selSummaryView === 'CREATED'    ? 'svt-active svt-created' : '' ?>">Created</a>
          <a href="<?= summaryViewUrl('DELIVERED') ?>" class="svt-btn <?= $selSummaryView === 'DELIVERED'  ? 'svt-active svt-delivered' : '' ?>">Delivered</a>
          <a href="<?= summaryViewUrl('REMITTED') ?>"  class="svt-btn <?= $selSummaryView === 'REMITTED'   ? 'svt-active' : '' ?>" style="<?= $selSummaryView === 'REMITTED' ? 'background:#dbeafe;color:#1e40af !important;' : '' ?>">Remitted</a>
        </div>
        <?php endif; ?>
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input type="text" id="searchBox" placeholder="Search table..." oninput="filterTable(this.value)">
        </div>
        <button type="button" class="btn-export" onclick="exportCSV()"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-excel"  onclick="exportExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn-print"  onclick="printTable()"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>

    <div class="table-scroll">
    <table id="mainTable">

      <?php if (in_array($tab, ['summary', 'pending'])): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Calls <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cases <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th>Status</th>
        <?php if ($tab === 'summary'): ?>
        <th onclick="sortTable(11)" class="right">Days Old <span class="sort-icon">⇅</span></th>
        <?php endif; ?>
        <th>Remarks</th>
        <th>Encoded By</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="<?= $tab === 'summary' ? '13' : '12' ?>"><div class="empty-state"><span class="icon">📭</span><p>No records found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <?php
          $rowDays = (int)($row['DaysOld'] ?? 0);
          $rowStatus = strtoupper(trim($row['Status'] ?? ''));
          if ($tab === 'summary') {
              if ($rowStatus === 'CREATED') {
                  $summaryRowStyle = 'background:#fee2e2;border-left:3px solid #ef4444;';
              } elseif ($rowDays >= 6) {
                  $summaryRowStyle = 'background:#fff0f0;';
              } elseif ($rowDays >= 3) {
                  $summaryRowStyle = 'background:#fffbeb;';
              } else {
                  $summaryRowStyle = '';
              }
          } else {
              $summaryRowStyle = '';
          }
        ?>
        <tr style="<?= $summaryRowStyle ?>">
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td>
            <span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span>
            <span class="dim" style="font-size:.8rem"> <?= htmlspecialchars($row['Salesman'] ?? '') ?></span>
          </td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCalls'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCases'] ?? 0)) ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td><?= statusBadge($row['Status'] ?? null) ?></td>
          <?php if ($tab === 'summary'): ?>
          <td class="right mono" style="font-weight:700;<?=
            $rowDays >= 6 ? 'color:#dc2626;' :
            ($rowDays >= 3 ? 'color:#ea580c;' : 'color:#16a34a;')
          ?>"><?= $rowDays ?>d</td>
          <?php endif; ?>
          <td class="dim" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['Remarks'] ?? '') ?>"><?= htmlspecialchars($row['Remarks'] ?? '—') ?></td>
          <td class="dim" style="font-size:.78rem"><?= htmlspecialchars($row['InputName'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'unserved'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Calls <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cases <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th>Invoice No</th>
        <th>Customer</th>
        <th onclick="sortTable(11)">Cancelled Date <span class="sort-icon">⇅</span></th>
        <th>Note</th>
        <th>Remarks</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="14"><div class="empty-state"><span class="icon">✅</span><p>No unserved records found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td>
            <span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span>
            <span class="dim" style="font-size:.8rem"> <?= htmlspecialchars($row['Salesman'] ?? '') ?></span>
          </td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCalls'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCases'] ?? 0)) ?></td>
          <td class="right mono bold amount-neg"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($row['InvoiceNo'] ?? '—') ?></td>
          <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($row['Customer'] ?? '—') ?></td>
          <td class="mono dim"><?= fmtDate($row['CancelledDate']) ?></td>
          <td class="dim" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['Note'] ?? '') ?>"><?= htmlspecialchars($row['Note'] ?? '—') ?></td>
          <td class="dim" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['Remarks'] ?? '') ?>"><?= htmlspecialchars($row['Remarks'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'delivered'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Calls <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cases <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th>Plate No</th>
        <th onclick="sortTable(10)">Schedule Date <span class="sort-icon">⇅</span></th>
        <th>Status</th>
        <th>Del Remarks</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="13"><div class="empty-state"><span class="icon">📭</span><p>No delivered records found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td>
            <span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span>
            <span class="dim" style="font-size:.8rem"> <?= htmlspecialchars($row['Salesman'] ?? '') ?></span>
          </td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCalls'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCases'] ?? 0)) ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td><span class="plate"><?= htmlspecialchars($row['PlateNumber'] ?? '—') ?></span></td>
          <td class="mono dim"><?= fmtDate($row['ScheduleDate']) ?></td>
          <td><?= statusBadge($row['Status'] ?? null) ?></td>
          <td class="dim" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['DelRemarks'] ?? '') ?>"><?= htmlspecialchars($row['DelRemarks'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'unremitted'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Calls <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cases <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Days Old <span class="sort-icon">⇅</span></th>
        <th>Status</th>
        <th>Remarks</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="12"><div class="empty-state"><span class="icon">✅</span><p>No unremitted deliveries found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row):
        $days = (int)($row['DaysOld'] ?? 0);
        if ($days >= 3) {
            $rowStyle = 'background:#fee2e2;border-left:3px solid #ef4444;';
        } elseif ($days >= 1) {
            $rowStyle = 'background:#fef9c3;border-left:3px solid #eab308;';
        } else {
            $rowStyle = '';
        }
      ?>
        <tr style="<?= $rowStyle ?>">
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td>
            <span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span>
            <span class="dim" style="font-size:.8rem"> <?= htmlspecialchars($row['Salesman'] ?? '') ?></span>
          </td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCalls'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCases'] ?? 0)) ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="right mono <?= $days >= 6 ? 'amount-neg' : '' ?>" style="font-weight:700"><?= $days ?>d</td>
          <td><?= statusBadge($row['Status'] ?? null) ?></td>
          <td class="dim" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['Remarks'] ?? '') ?>"><?= htmlspecialchars($row['Remarks'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif (in_array($tab, ['remitted', 'received'])): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cash <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Check <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Credit <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Total Remit <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)" class="right">Difference <span class="sort-icon">⇅</span></th>
        <th>Remitted By</th>
        <th onclick="sortTable(13)">Remitted Date <span class="sort-icon">⇅</span></th>
        <?php if ($tab === 'remitted'): ?>
        <th onclick="sortTable(14)" class="right">Days Unreceived <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(15)">Remarks <span class="sort-icon">⇅</span></th>
        <?php endif; ?>
        <?php if ($tab === 'received'): ?>
        <th onclick="sortTable(14)">Received Date <span class="sort-icon">⇅</span></th>
        <th>Received By</th>
        <th onclick="sortTable(16)">Remarks <span class="sort-icon">⇅</span></th>
        <?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="<?= $tab === 'received' ? 17 : ($tab === 'remitted' ? 16 : 14) ?>">
          <div class="empty-state"><span class="icon">📭</span><p>No records found.</p></div>
        </td></tr>
      <?php else: foreach ($displayData as $row):
        $rowStyle = '';
        if ($tab === 'remitted') {
            $days = (int)($row['DaysOld'] ?? 0);
            $diff = (float)($row['TotalDifference'] ?? 0);
            if ($days >= 3) {
                $rowStyle = 'background:#fee2e2;border-left:3px solid #ef4444;';
            } elseif ($days >= 1) {
                $rowStyle = 'background:#fef9c3;border-left:3px solid #eab308;';
            } elseif ($diff < 0) {
                $rowStyle = 'background:#fee2e2;';
            }
        }
      ?>
        <tr style="<?= $rowStyle ?>">
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td><span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="right mono"><?= peso($row['TotalCash']) ?></td>
          <td class="right mono"><?= peso($row['TotalCheck']) ?></td>
          <td class="right mono"><?= peso($row['TotalCredit']) ?></td>
          <td class="right mono bold"><?= peso($row['TotalRemit']) ?></td>
          <?php
            $diff = (float)($row['TotalDifference'] ?? 0);
            $diffClass = $diff > 0 ? 'amount-pos' : ($diff < 0 ? 'amount-neg' : 'dim');
            $diffLabel = $diff > 0 ? '▲ ' : ($diff < 0 ? '▼ ' : '');
          ?>
          <td class="right mono bold <?= $diffClass ?>"><?= $diff != 0 ? $diffLabel . peso(abs($diff)) : '—' ?></td>
          <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($row['RemittedByName'] ?? '—') ?></td>
          <td class="mono dim"><?= fmtDate($row['DateRemit']) ?></td>
          <?php if ($tab === 'remitted'):
            $days = (int)($row['DaysOld'] ?? 0);
            $daysClass = $days >= 6 ? 'amount-neg' : '';
          ?>
          <td class="right mono <?= $daysClass ?>" style="font-weight:700"><?= $days ?>d</td>
          <?php endif; ?>
          <?php if ($tab === 'received'): ?>
          <td class="mono dim"><?= fmtDate($row['DateReceived'] ?? null) ?></td>
          <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($row['ReceivedBy'] ?? '—') ?></td>
          <?php endif; ?>
          <td class="dim" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($row['RemitanceRemarks'] ?? '') ?>"><?= htmlspecialchars($row['RemitanceRemarks'] ?? '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'by_salesman'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Salesman Code <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)">Salesman <span class="sort-icon">⇅</span></th>
        <th>Area</th>
        <th>Branch</th>
        <th onclick="sortTable(4)" class="right">Deliveries <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Total Calls <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Total Cases <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Total Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Avg Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Pending <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Delivered <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="11"><div class="empty-state"><span class="icon">📭</span><p>No data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row): ?>
        <tr>
          <td><span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span></td>
          <td class="bold"><?= htmlspecialchars($row['Salesman'] ?? '—') ?></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalDeliveries'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCalls'] ?? 0)) ?></td>
          <td class="right mono"><?= number_format((int)($row['TotalCases'] ?? 0)) ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="right mono"><?= peso($row['AvgNetAmount']) ?></td>
          <td class="right mono" style="color:var(--remit-yellow);font-weight:700"><?= number_format((int)($row['PendingCount'] ?? 0)) ?></td>
          <td class="right mono" style="color:var(--remit-green);font-weight:700"><?= number_format((int)($row['DeliveredCount'] ?? 0)) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'shorts'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Doc No <span class="sort-icon">⇅</span></th>
        <th>Branch</th>
        <th>Department</th>
        <th onclick="sortTable(3)">Doc Date <span class="sort-icon">⇅</span></th>
        <th>Salesman</th>
        <th>Area</th>
        <th onclick="sortTable(6)" class="right">Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Cash <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Check <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Credit <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Cancelled <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)" class="right">Total Remit <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(12)" class="right">Short (Diff) <span class="sort-icon">⇅</span></th>
        <th>Remitted By</th>
        <th onclick="sortTable(14)">Date Remit <span class="sort-icon">⇅</span></th>
        <th>Settlement</th>
        <th>Remarks</th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="17"><div class="empty-state"><span class="icon">✅</span><p><?= (isset($_GET['shorts_view']) && $_GET['shorts_view'] === 'settled') ? 'No settled shorts found.' : 'No unsettled shorts — all remittances balanced or settled.' ?></p></div></td></tr>
      <?php else: foreach ($displayData as $row):
        $diff = (float)($row['TotalDifference'] ?? 0);
      ?>
        <tr style="background:#fff5f5;">
          <td><span class="doc-no"><?= htmlspecialchars($row['DocNo'] ?? '—') ?></span></td>
          <td><?= branchBadge($row['Branch'] ?? null) ?></td>
          <td><?= deptBadge($row['Department'] ?? null) ?></td>
          <td class="mono dim"><?= fmtDate($row['DocDate']) ?></td>
          <td><span class="salesman-tag"><?= htmlspecialchars($row['SalesmanCode'] ?? '—') ?></span></td>
          <td class="dim"><?= htmlspecialchars($row['Area'] ?? '—') ?></td>
          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="right mono"><?= peso($row['TotalCash']) ?></td>
          <td class="right mono"><?= peso($row['TotalCheck']) ?></td>
          <td class="right mono"><?= peso($row['TotalCredit']) ?></td>
          <td class="right mono"><?= peso($row['TotalCancel']) ?></td>
          <td class="right mono bold"><?= peso($row['TotalRemit']) ?></td>
          <td class="right mono bold amount-neg">▼ <?= peso($diff) ?></td>
          <td class="dim" style="font-size:.8rem"><?= htmlspecialchars($row['RemittedByName'] ?? '—') ?></td>
          <td class="mono dim"><?= fmtDate($row['DateRemit']) ?></td>
          <?php $paymentId = $row['PaymentID'] ?? null; $isSettled = !empty($paymentId) && (int)$paymentId > 0; ?>
          <td>
            <?php if ($isSettled): ?>
              <span style="background:#dcfce7;color:#166534;border:1px solid #4ade80;border-radius:999px;padding:2px 9px;font-size:.73rem;font-weight:700;white-space:nowrap;">✓ Settled <span style="opacity:.7;font-size:.68rem;">#<?= htmlspecialchars($paymentId) ?></span></span>
            <?php else: ?>
              <span style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;border-radius:999px;padding:2px 9px;font-size:.73rem;font-weight:700;white-space:nowrap;">⚠ Unsettled</span>
            <?php endif; ?>
          </td>
          <?php $remarksText = htmlspecialchars($row['RemitanceRemarks'] ?? ''); ?>
          <td class="dim" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
            <?php if (!empty($row['RemitanceRemarks'])): ?>
              <span onclick="openRemarksModal(<?= htmlspecialchars(json_encode($row['RemitanceRemarks']), ENT_QUOTES) ?>)"
                    style="cursor:pointer;text-decoration:underline dotted;color:var(--remit-blue);"
                    title="Click to view full remarks"><?= $remarksText ?></span>
            <?php else: ?>
              <span class="dim">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php elseif ($tab === 'by_leadman'): ?>
      <thead><tr>
        <th onclick="sortTable(0)">Leadman / Remitter <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(1)" class="right">Remittances <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(2)" class="right">Unreceived <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(3)" class="right">Received <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(4)" class="right">Total Net Amt <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(5)" class="right">Cash <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(6)" class="right">Check <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(7)" class="right">Credit <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(8)" class="right">Cancelled <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(9)" class="right">Total Remitted <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(10)" class="right">Net Diff <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(11)" class="right">Shorts <span class="sort-icon">⇅</span></th>
        <th onclick="sortTable(12)" class="right">Total Short Amt <span class="sort-icon">⇅</span></th>
      </tr></thead>
      <tbody>
      <?php if (empty($displayData)): ?>
        <tr><td colspan="13"><div class="empty-state"><span class="icon">📭</span><p>No leadman data found.</p></div></td></tr>
      <?php else: foreach ($displayData as $row):
        $netDiff = (float)($row['TotalDifference'] ?? 0);
        $diffClass = $netDiff < 0 ? 'amount-neg' : ($netDiff > 0 ? 'amount-pos' : 'dim');
        $diffLabel = $netDiff > 0 ? '▲ ' : ($netDiff < 0 ? '▼ ' : '');
        $rowHasShort = (int)($row['ShortsCount'] ?? 0) > 0;
        $leadmanNameJs = htmlspecialchars(json_encode($row['RemittedByName'] ?? ''), ENT_QUOTES);
      ?>
        <tr style="<?= $rowHasShort ? 'border-left:3px solid #f87171;' : '' ?>">
          <td>
            <span style="font-weight:700;font-size:.9rem;"><?= htmlspecialchars($row['RemittedByName'] ?? '—') ?></span>
          </td>
          <td class="right mono bold"><?= number_format((int)($row['TotalRemittances'] ?? 0)) ?></td>

          <!-- ── Unreceived count (existing clickable) ── -->
          <td class="right mono" style="font-weight:700">
            <?php if ((int)($row['PendingCount'] ?? 0) > 0): ?>
              <span class="clickable-count" style="color:var(--remit-orange);"
                    onclick="openUnreceivedModal(<?= $leadmanNameJs ?>)"
                    title="Click to view unreceived remittances">
                <?= number_format((int)$row['PendingCount']) ?>
              </span>
            <?php else: ?>
              <span class="dim">0</span>
            <?php endif; ?>
          </td>

          <!-- ── Received count (NEW clickable) ── -->
          <td class="right mono" style="font-weight:700">
            <?php if ((int)($row['RemittedCount'] ?? 0) > 0): ?>
              <span class="clickable-count" style="color:var(--remit-green);"
                    onclick="openReceivedModal(<?= $leadmanNameJs ?>)"
                    title="Click to view received remittances">
                <?= number_format((int)$row['RemittedCount']) ?>
              </span>
            <?php else: ?>
              <span class="dim">0</span>
            <?php endif; ?>
          </td>

          <td class="right mono bold amount-pos"><?= peso($row['TotalNetAmount']) ?></td>
          <td class="right mono"><?= peso($row['TotalCash']) ?></td>
          <td class="right mono"><?= peso($row['TotalCheck']) ?></td>
          <td class="right mono"><?= peso($row['TotalCredit']) ?></td>
          <td class="right mono" style="color:var(--remit-red)"><?= peso($row['TotalCancelled']) ?></td>
          <td class="right mono bold" style="color:var(--remit-blue)"><?= peso($row['TotalRemitted']) ?></td>
          <td class="right mono bold <?= $diffClass ?>"><?= $netDiff != 0 ? $diffLabel . peso(abs($netDiff)) : '—' ?></td>

          <!-- ── Shorts count (NEW clickable) ── -->
          <td class="right mono" style="font-weight:700">
            <?php if ((int)($row['ShortsCount'] ?? 0) > 0): ?>
              <span class="clickable-count"
                    onclick="openShortsModal(<?= $leadmanNameJs ?>)"
                    title="Click to view unsettled shorts">
                <span style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;border-radius:999px;padding:1px 8px;font-size:.73rem;font-weight:700;">
                  <?= number_format((int)$row['ShortsCount']) ?>
                </span>
              </span>
            <?php else: ?>
              <span class="dim">0</span>
            <?php endif; ?>
          </td>

          <td class="right mono bold <?= (float)($row['TotalShorts'] ?? 0) > 0 ? 'amount-neg' : 'dim' ?>">
            <?= (float)($row['TotalShorts'] ?? 0) > 0 ? '▼ ' . peso($row['TotalShorts']) : '—' ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>

      <?php endif; ?>

    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <span class="pagination-info">
        Showing <strong><?= $offset + 1 ?>–<?= min($offset + $rowLimit, $totalRows) ?></strong>
        of <strong><?= $totalRows ?></strong> rows
        · Page <strong><?= $curPage ?></strong> of <strong><?= $totalPages ?></strong>
      </span>
      <div class="pagination-btns">
        <?php if ($prevUrl): ?>
          <a href="<?= htmlspecialchars($prevUrl) ?>" class="btn-page"><i class="bi bi-chevron-left"></i> Previous</a>
        <?php else: ?>
          <span class="btn-page disabled"><i class="bi bi-chevron-left"></i> Previous</span>
        <?php endif; ?>
        <?php if ($nextUrl): ?>
          <a href="<?= htmlspecialchars($nextUrl) ?>" class="btn-page">Next <i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
          <span class="btn-page disabled">Next <i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="footer">
      Delivery Remittance Dashboard · Tradewell Finance · Generated <?= date('Y-m-d H:i:s') ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL 1: Unreceived Remittances (existing, now with exports)
     ════════════════════════════════════════════════════════════ -->
<div id="unreceivedModal" class="lm-modal-overlay">
  <div class="lm-modal-box">
    <div class="lm-modal-header">
      <div class="lm-modal-header-left">
        <div class="lm-modal-title">⏳ Unreceived Remittances</div>
        <div class="lm-modal-sub" id="unreceivedModalSub"></div>
      </div>
      <div class="lm-modal-header-right">
        <button class="modal-export-btn csv"   onclick="modalExportCSV('unreceived')"><i class="bi bi-download"></i> CSV</button>
        <button class="modal-export-btn excel" onclick="modalExportExcel('unreceived')"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button class="modal-export-btn print" onclick="modalPrint('unreceived')"><i class="bi bi-printer"></i> Print</button>
        <button class="modal-close-btn" onclick="closeModal('unreceivedModal')">&times;</button>
      </div>
    </div>
    <div class="lm-modal-body" id="unreceivedModalBody">
      <div style="text-align:center;padding:2rem;color:var(--text-dim,#6b7280);">Loading...</div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL 2: Received Remittances (NEW)
     ════════════════════════════════════════════════════════════ -->
<div id="receivedModal" class="lm-modal-overlay">
  <div class="lm-modal-box">
    <div class="lm-modal-header">
      <div class="lm-modal-header-left">
        <div class="lm-modal-title">🏦 Received Remittances</div>
        <div class="lm-modal-sub" id="receivedModalSub"></div>
      </div>
      <div class="lm-modal-header-right">
        <button class="modal-export-btn csv"   onclick="modalExportCSV('received')"><i class="bi bi-download"></i> CSV</button>
        <button class="modal-export-btn excel" onclick="modalExportExcel('received')"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button class="modal-export-btn print" onclick="modalPrint('received')"><i class="bi bi-printer"></i> Print</button>
        <button class="modal-close-btn" onclick="closeModal('receivedModal')">&times;</button>
      </div>
    </div>
    <div class="lm-modal-body" id="receivedModalBody">
      <div style="text-align:center;padding:2rem;color:var(--text-dim,#6b7280);">Loading...</div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL 3: Shorts (Unsettled) (NEW)
     ════════════════════════════════════════════════════════════ -->
<div id="shortsModal" class="lm-modal-overlay">
  <div class="lm-modal-box">
    <div class="lm-modal-header">
      <div class="lm-modal-header-left">
        <div class="lm-modal-title">📉 Unsettled Shorts</div>
        <div class="lm-modal-sub" id="shortsModalSub"></div>
      </div>
      <div class="lm-modal-header-right">
        <button class="modal-export-btn csv"   onclick="modalExportCSV('shorts')"><i class="bi bi-download"></i> CSV</button>
        <button class="modal-export-btn excel" onclick="modalExportExcel('shorts')"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button class="modal-export-btn print" onclick="modalPrint('shorts')"><i class="bi bi-printer"></i> Print</button>
        <button class="modal-close-btn" onclick="closeModal('shortsModal')">&times;</button>
      </div>
    </div>
    <div class="lm-modal-body" id="shortsModalBody">
      <div style="text-align:center;padding:2rem;color:var(--text-dim,#6b7280);">Loading...</div>
    </div>
  </div>
</div>

<!-- Shorts Remarks Modal (unchanged) -->
<div id="remarksModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
  <div style="background:var(--card,#fff);border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:min(520px,92vw);padding:0;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;border-bottom:1px solid var(--border,#e5e7eb);">
      <div>
        <div style="font-weight:700;font-size:1rem;">Remarks</div>
        <div style="font-size:.75rem;color:var(--text-dim,#6b7280);margin-top:2px;">Full remittance remarks</div>
      </div>
      <button onclick="closeRemarksModal()" style="background:none;border:none;cursor:pointer;font-size:1.3rem;color:var(--text-dim,#6b7280);line-height:1;padding:4px 8px;">✕</button>
    </div>
    <div style="padding:22px;max-height:60vh;overflow-y:auto;">
      <p id="remarksModalText" style="margin:0;white-space:pre-wrap;line-height:1.7;color:var(--text,#111827);font-size:.95rem;"></p>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
<script>
// ── PHP data passed to JS ─────────────────────────────────
const ALL_DATA          = <?= json_encode($exportData) ?>;
const HEADERS           = Object.keys(ALL_DATA[0] ?? {});
const UNRECEIVED_DATA   = <?= json_encode($unreceivedGrouped) ?>;
const RECEIVED_DATA     = <?= json_encode($receivedGrouped) ?>;
const SHORTS_MODAL_DATA = <?= json_encode($shortsGrouped) ?>;

// ── Active modal tracking (used by export functions) ──────
let _activeModalName   = '';  // 'unreceived' | 'received' | 'shorts'
let _activeModalRows   = [];  // current rows being shown

// ── Shared helpers ────────────────────────────────────────
const pesoFmt = v => '₱ ' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const fmtD = v => {
    if (!v) return '—';
    if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
    return String(v).substring(0, 10);
};

const diffCellHtml = v => {
    const d = parseFloat(v || 0);
    const color = d > 0 ? '#16a34a' : d < 0 ? '#dc2626' : '#6b7280';
    const lbl   = d > 0 ? '▲ ' : d < 0 ? '▼ ' : '';
    return d !== 0
        ? `<span style="color:${color};font-weight:700;">${lbl}${pesoFmt(Math.abs(d))}</span>`
        : '—';
};

// ── Shared modal table builder ────────────────────────────
// Columns differ slightly per type; we use a config object.
function buildModalColumns(type) {
    // Returns { headers: string[], rowFn: (row) => td[] }
    const baseHeaders = [
        'Doc No', 'Branch', 'Doc Date', 'Delivery Date',
        'Salesman', 'Area', 'Remarks',
        'Total Net', 'Cash', 'Check', 'Credit', 'Cancel', 'Total Remit', 'Difference'
    ];

    if (type === 'shorts') {
        return {
            headers: [...baseHeaders, 'Date Remit', 'Settlement'],
            rowFn: r => {
                const diff = parseFloat(r.TotalDifference || 0);
                const pid  = parseInt(r.PaymentID || 0);
                const settled = pid > 0
                    ? `<span style="background:#dcfce7;color:#166534;border:1px solid #4ade80;border-radius:999px;padding:1px 7px;font-size:.72rem;font-weight:700;">✓ #${pid}</span>`
                    : `<span style="background:#fee2e2;color:#991b1b;border:1px solid #f87171;border-radius:999px;padding:1px 7px;font-size:.72rem;font-weight:700;">⚠ Unsettled</span>`;
                return [
                    `<b style="color:#7c3aed;font-family:monospace">${r.DocNo ?? '—'}</b>`,
                    r.Branch ?? '—',
                    fmtD(r.DocDate),
                    fmtD(r.DeliveryDate),
                    `<span style="background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd;border-radius:999px;padding:1px 7px;font-size:.72rem;font-weight:600;">${r.SalesmanCode ?? '—'}</span>`,
                    r.Area ?? '—',
                    `<span style="max-width:140px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(r.Remarks || r.RemitRemarks || '').replace(/"/g, '&quot;')}">${r.Remarks || r.RemitRemarks || '—'}</span>`,
                    `<span style="color:#16a34a;font-weight:700;">${pesoFmt(r.TotalNetAmount)}</span>`,
                    pesoFmt(r.TotalCash),
                    pesoFmt(r.TotalCheck),
                    pesoFmt(r.TotalCredit),
                    pesoFmt(r.TotalCancel),
                    `<b>${pesoFmt(r.TotalRemit)}</b>`,
                    `<span style="color:#dc2626;font-weight:700;">▼ ${pesoFmt(Math.abs(diff))}</span>`,
                    fmtD(r.DateRemit),
                    settled,
                ];
            }
        };
    }

    // unreceived & received share identical columns
    return {
        headers: baseHeaders,
        rowFn: r => [
            `<b style="color:#7c3aed;font-family:monospace">${r.DocNo ?? '—'}</b>`,
            r.Branch ?? '—',
            fmtD(r.DocDate),
            fmtD(r.DeliveryDate),
            `<span style="background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd;border-radius:999px;padding:1px 7px;font-size:.72rem;font-weight:600;">${r.SalesmanCode ?? '—'}</span>`,
            r.Area ?? '—',
            `<span style="max-width:140px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${(r.Remarks || r.RemitRemarks || '').replace(/"/g, '&quot;')}">${r.Remarks || r.RemitRemarks || '—'}</span>`,
            `<span style="color:#16a34a;font-weight:700;">${pesoFmt(r.TotalNetAmount)}</span>`,
            pesoFmt(r.TotalCash),
            pesoFmt(r.TotalCheck),
            pesoFmt(r.TotalCredit),
            pesoFmt(r.TotalCancel),
            `<b>${pesoFmt(r.TotalRemit)}</b>`,
            diffCellHtml(r.TotalDifference),
        ]
    };
}

function buildModalTable(type, rows) {
    if (!rows.length) {
        return '<div style="text-align:center;padding:2rem;color:#6b7280;">No records found.</div>';
    }
    const { headers, rowFn } = buildModalColumns(type);
    const rightCols = new Set([7, 8, 9, 10, 11, 12, 13]); // amount columns

    let html = `<table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <thead><tr style="background:var(--input-bg,#f3f4f6);">`;
    headers.forEach((h, i) => {
        const align = rightCols.has(i) ? 'text-align:right;' : '';
        html += `<th style="padding:6px 10px;border:1px solid var(--border,#e5e7eb);font-weight:700;${align}">${h}</th>`;
    });
    html += `</tr></thead><tbody>`;

    rows.forEach((r, i) => {
        const bg = i % 2 === 0 ? '' : 'background:var(--input-bg,#f9fafb);';
        const rowStyle = type === 'shorts' ? 'background:#fff5f5;' : bg;
        html += `<tr style="${rowStyle}">`;
        rowFn(r).forEach((cell, ci) => {
            const align = rightCols.has(ci) ? 'text-align:right;' : '';
            html += `<td style="padding:5px 10px;border:1px solid var(--border,#e5e7eb);${align}">${cell}</td>`;
        });
        html += '</tr>';
    });

    html += '</tbody></table>';
    return html;
}

// ── Modal open/close ──────────────────────────────────────
function openModal(modalId, title, subText, type, rows) {
    _activeModalName = type;
    _activeModalRows = rows;

    const modal    = document.getElementById(modalId);
    const bodyEl   = document.getElementById(modalId.replace('Modal', 'ModalBody'));
    const subEl    = document.getElementById(modalId.replace('Modal', 'ModalSub'));

    subEl.textContent = subText;
    bodyEl.innerHTML  = buildModalTable(type, rows);
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = '';
    _activeModalName = '';
    _activeModalRows = [];
}

// Close on backdrop click
['unreceivedModal', 'receivedModal', 'shortsModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['unreceivedModal', 'receivedModal', 'shortsModal'].forEach(closeModal);
        closeRemarksModal();
    }
});

// ── Specific open functions ───────────────────────────────
function openUnreceivedModal(leadmanName) {
    const rows = UNRECEIVED_DATA[leadmanName] || [];
    openModal(
        'unreceivedModal',
        '⏳ Unreceived Remittances',
        `${leadmanName} · ${rows.length} unreceived remittance(s)`,
        'unreceived',
        rows
    );
}

function openReceivedModal(leadmanName) {
    const rows = RECEIVED_DATA[leadmanName] || [];
    openModal(
        'receivedModal',
        '🏦 Received Remittances',
        `${leadmanName} · ${rows.length} received remittance(s)`,
        'received',
        rows
    );
}

function openShortsModal(leadmanName) {
    const rows = SHORTS_MODAL_DATA[leadmanName] || [];
    openModal(
        'shortsModal',
        '📉 Unsettled Shorts',
        `${leadmanName} · ${rows.length} unsettled short(s)`,
        'shorts',
        rows
    );
}

// ── Modal export helpers ──────────────────────────────────
// Convert rows to plain-text objects for XLSX/CSV
function modalRowsToPlain(type, rows) {
    return rows.map(r => {
        const base = {
            'Doc No':       r.DocNo      ?? '',
            'Branch':       r.Branch     ?? '',
            'Doc Date':     fmtD(r.DocDate),
            'Delivery Date':fmtD(r.DeliveryDate),
            'Salesman':     r.SalesmanCode ?? '',
            'Area':         r.Area       ?? '',
            'Remarks':      r.Remarks || r.RemitRemarks || '',
            'Total Net':    parseFloat(r.TotalNetAmount || 0),
            'Cash':         parseFloat(r.TotalCash      || 0),
            'Check':        parseFloat(r.TotalCheck     || 0),
            'Credit':       parseFloat(r.TotalCredit    || 0),
            'Cancel':       parseFloat(r.TotalCancel    || 0),
            'Total Remit':  parseFloat(r.TotalRemit     || 0),
            'Difference':   parseFloat(r.TotalDifference|| 0),
        };
        if (type === 'shorts') {
            base['Date Remit']  = fmtD(r.DateRemit);
            base['Settlement']  = parseInt(r.PaymentID || 0) > 0 ? `Settled #${r.PaymentID}` : 'Unsettled';
        }
        return base;
    });
}

function modalExportCSV(type) {
    if (!_activeModalRows.length) return alert('No data to export.');
    const plain   = modalRowsToPlain(type, _activeModalRows);
    const headers = Object.keys(plain[0]);
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...plain.map(row => headers.map(h => {
    let v = row[h];
    if (v && typeof v === 'object' && v.date) {
        v = v.date.substring(0, 10);
    }
    return `"${String(v ?? '').replace(/"/g, '""')}"`;
}).join(','))
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `leadman_${type}_<?= date('Ymd') ?>.csv`;
    a.click();
}

function modalExportExcel(type) {
    if (!_activeModalRows.length) return alert('No data to export.');
    const plain = modalRowsToPlain(type, _activeModalRows);
    const ws = XLSX.utils.json_to_sheet(plain);
    const wb = XLSX.utils.book_new();
    const sheetName = type === 'unreceived' ? 'Unreceived'
                    : type === 'received'   ? 'Received'
                    : 'Shorts';
    XLSX.utils.book_append_sheet(wb, ws, sheetName);
    XLSX.writeFile(wb, `leadman_${type}_<?= date('Ymd') ?>.xlsx`);
}

function modalPrint(type) {
    if (!_activeModalRows.length) return alert('No data to print.');

    const titles = {
        unreceived: '⏳ Unreceived Remittances',
        received:   '🏦 Received Remittances',
        shorts:     '📉 Unsettled Shorts',
    };

    const { headers } = buildModalColumns(type);
    const plain       = modalRowsToPlain(type, _activeModalRows);
    const rightKeys   = ['Total Net','Cash','Check','Credit','Cancel','Total Remit','Difference'];
    const subEl       = document.getElementById(
        type === 'unreceived' ? 'unreceivedModalSub'
      : type === 'received'   ? 'receivedModalSub'
      : 'shortsModalSub'
    );

    let tbody = '';
    plain.forEach((row, i) => {
        const bg = i % 2 === 0 ? '' : 'background:#f9fafb;';
        const rowBg = type === 'shorts' ? 'background:#fff5f5;' : bg;
        tbody += `<tr style="${rowBg}">`;
        headers.forEach(h => {
            const isAmt = rightKeys.includes(h);
            const align = isAmt ? 'text-align:right;' : '';
            let val = row[h] ?? '';
            if (isAmt && typeof val === 'number') {
                val = pesoFmt(val);
                if (h === 'Difference') {
                    const d = parseFloat(row['Difference'] || 0);
                    val = d > 0 ? `▲ ${pesoFmt(d)}` : d < 0 ? `▼ ${pesoFmt(Math.abs(d))}` : '—';
                }
            }
            tbody += `<td style="padding:3px 6px;border:1px solid #ccc;${align}">${val}</td>`;
        });
        tbody += '</tr>';
    });

    let thead = '<thead><tr>';
    headers.forEach(h => {
        const isAmt = rightKeys.includes(h);
        thead += `<th style="padding:4px 6px;border:1px solid #ccc;background:#f3f4f6;${isAmt ? 'text-align:right;' : ''}">${h}</th>`;
    });
    thead += '</tr></thead>';

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>${titles[type]}</title>
        <style>
          @page { size: landscape; margin: 10mm 8mm; }
          * { box-sizing: border-box; }
          body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; margin: 0; padding: 6px 8px; }
          .print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; border-bottom: 1.5px solid #333; padding-bottom: 4px; }
          .print-header h3 { margin: 0; font-size: 11px; font-weight: 800; }
          .print-header p  { margin: 0; font-size: 7.5px; color: #555; text-align: right; }
          table { width: 100%; border-collapse: collapse; table-layout: auto; }
          thead { display: table-header-group; }
          th { background: #e8e8e8; font-weight: 700; font-size: 7.5px; text-transform: uppercase; letter-spacing: .03em;
               border: 1px solid #aaa; padding: 3px 4px; text-align: left; vertical-align: bottom; white-space: nowrap; }
          td { border: 1px solid #ccc; padding: 2px 4px; vertical-align: middle; font-size: 8px; }
          tr:nth-child(even) td { background: #f7f7f7; }
          .r { text-align: right; }
          tfoot td { font-weight: 800; background: #f0f0f0; border-top: 1.5px solid #999; font-size: 8px; }
          @media print { body { padding: 0; } }
        </style>
      </head><body>
        <div class="print-header">
          <h3>${titles[type]}</h3>
          <p>${subEl ? subEl.textContent : ''}<br>Exported: <?= date('Y-m-d H:i:s') ?></p>
        </div>
        <table>${thead}<tbody>${tbody}</tbody></table>
      </body></html>`);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

// ── Filter panel toggle ───────────────────────────────────
const filterBody = document.getElementById('filterBody');
const filterIcon = document.getElementById('filterToggleIcon');
const headerTags = document.getElementById('headerTags');

function toggleFilter() {
    const isOpen = filterBody.classList.toggle('open');
    filterIcon.classList.toggle('open', isOpen);
    headerTags.style.display = isOpen ? 'none' : 'flex';
}

<?php if ($anyFilterApplied): ?>
toggleFilter();
<?php endif; ?>

// ── Main table search ─────────────────────────────────────
function filterTable(q) {
    const term = q.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
}

// ── Column sort ───────────────────────────────────────────
let _sortDir = {};
function sortTable(col) {
    const tbody = document.querySelector('#mainTable tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    _sortDir[col] = !_sortDir[col];
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const bv = b.cells[col]?.innerText.replace(/[₱,\s▲▼]/g, '') || '';
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : av.localeCompare(bv);
        return _sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── Main table CSV export ─────────────────────────────────
function exportCSV() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const headers = HEADERS;
    const csv = [
        headers.map(h => `"${h}"`).join(','),
        ...ALL_DATA.map(row =>
            headers.map(h => `"${String(row[h] ?? '').replace(/"/g, '""')}"`).join(',')
        )
    ].join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = `delivery_remittance_<?= $tab ?>_<?= date('Ymd') ?>.csv`;
    a.click();
}

// ── Main table Excel export ───────────────────────────────
function exportExcel() {
    if (!ALL_DATA.length) return alert('No data to export.');
    const cleanData = ALL_DATA.map(row => {
    const newRow = {};
    for (let key in row) {
        let v = row[key];
        if (v && typeof v === 'object' && v.date) {
            v = v.date.substring(0, 10);
        }
        newRow[key] = v;
    }
    return newRow;
});

const ws = XLSX.utils.json_to_sheet(cleanData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, '<?= ucfirst($tab) ?>');
    XLSX.writeFile(wb, `delivery_remittance_<?= $tab ?>_<?= date('Ymd') ?>.xlsx`);
}

// ── Main table print ──────────────────────────────────────
function printTable() {
    if (!ALL_DATA.length) return alert('No data to print.');
    const tabTitle = document.querySelector('.table-title')?.innerText.replace(/\s+\d+ records.*/, '').trim() || 'Delivery Remittance';

    function fmtDate(v) {
        if (!v) return '—';
        if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
        if (typeof v === 'object' && v.date) return v.date.substring(0, 10);
        return String(v).substring(0, 10);
    }
    function peso(v) {
        return '₱ ' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function badge(label, cls) {
        return `<span class="badge ${cls}">${label ?? '—'}</span>`;
    }
    function branchBadge(b) {
        return badge(b||'—', 'badge-branch');
    }
    function deptBadge(d) {
        return badge((d||'—').trim(), 'badge-dept');
    }
    function statusBadge(s) {
        const key = (s||'').toUpperCase().trim();
        const cls = key === 'DELIVERED' ? 'badge-status' : key === 'PENDING' ? 'badge-status text-amber' : key === 'CANCELLED' ? 'badge-unsettled' : 'badge-salesman';
        return badge(s||'—', cls);
    }
    function salesmanTag(code) {
        return badge(code||'—', 'badge-salesman');
    }
    function rridBadge(v) {
        return badge(`# ${v}`, 'badge-rrid');
    }
    function diffCell(v) {
        const d = parseFloat(v || 0);
        const color = d > 0 ? '#16a34a' : d < 0 ? '#dc2626' : '#6b7280';
        const label = d > 0 ? '▲ ' : d < 0 ? '▼ ' : '';
        return d !== 0 ? `<span style="color:${color};font-weight:700;">${label}${peso(Math.abs(d))}</span>` : '—';
    }

    const tab = '<?= $tab ?>';
    let thead = '', tbody = '';

    if (tab === 'summary' || tab === 'pending') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Calls</th><th class="r">Cases</th>
          <th class="r">Net Amt</th><th>Status</th>
          ${tab === 'summary' ? '<th class="r">Days Old</th>' : ''}
          <th>Remarks</th><th>Encoded By</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const days = parseInt(r.DaysOld || 0);
            const daysColor = days >= 6 ? '#dc2626' : days >= 3 ? '#ea580c' : '#16a34a';
            const rowBg = tab === 'summary' ? (days >= 6 ? 'background:#fff0f0' : days >= 3 ? 'background:#fffbeb' : '') : '';
            return `<tr style="${rowBg}">
              <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
              <td>${branchBadge(r.Branch)}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${fmtDate(r.DocDate)}</td>
              <td>${salesmanTag(r.SalesmanCode)} <span style="font-size:.8rem">${r.Salesman??''}</span></td>
              <td>${r.Area??'—'}</td>
              <td class="r">${parseInt(r.TotalCalls||0).toLocaleString()}</td>
              <td class="r">${parseInt(r.TotalCases||0).toLocaleString()}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
              <td>${statusBadge(r.Status)}</td>
              ${tab === 'summary' ? `<td class="r" style="font-weight:700;color:${daysColor}">${days}d</td>` : ''}
              <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.Remarks??'—'}</td>
              <td style="font-size:.78rem">${r.InputName??'—'}</td>
            </tr>`;
        }).join('') + '</tbody>';

    } else if (tab === 'unserved') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Calls</th><th class="r">Cases</th>
          <th class="r">Net Amt</th><th>Invoice No</th><th>Customer</th>
          <th>Cancelled Date</th><th>Note</th><th>Remarks</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
          <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
          <td>${branchBadge(r.Branch)}</td>
          <td>${deptBadge(r.Department)}</td>
          <td>${fmtDate(r.DocDate)}</td>
          <td>${salesmanTag(r.SalesmanCode)} <span style="font-size:.8rem">${r.Salesman??''}</span></td>
          <td>${r.Area??'—'}</td>
          <td class="r">${parseInt(r.TotalCalls||0).toLocaleString()}</td>
          <td class="r">${parseInt(r.TotalCases||0).toLocaleString()}</td>
          <td class="r" style="color:#dc2626;font-weight:700">${peso(r.TotalNetAmount)}</td>
          <td>${r.InvoiceNo??'—'}</td>
          <td>${r.Customer??'—'}</td>
          <td>${fmtDate(r.CancelledDate)}</td>
          <td>${r.Note??'—'}</td>
          <td>${r.Remarks??'—'}</td>
        </tr>`).join('') + '</tbody>';

    } else if (tab === 'delivered') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Calls</th><th class="r">Cases</th>
          <th class="r">Net Amt</th><th>Plate No</th><th>Schedule Date</th>
          <th>Status</th><th>Del Remarks</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
          <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
          <td>${branchBadge(r.Branch)}</td>
          <td>${deptBadge(r.Department)}</td>
          <td>${fmtDate(r.DocDate)}</td>
          <td>${salesmanTag(r.SalesmanCode)} <span style="font-size:.8rem">${r.Salesman??''}</span></td>
          <td>${r.Area??'—'}</td>
          <td class="r">${parseInt(r.TotalCalls||0).toLocaleString()}</td>
          <td class="r">${parseInt(r.TotalCases||0).toLocaleString()}</td>
          <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
          <td>${r.PlateNumber??'—'}</td>
          <td>${fmtDate(r.ScheduleDate)}</td>
          <td>${statusBadge(r.Status)}</td>
          <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.DelRemarks??'—'}</td>
        </tr>`).join('') + '</tbody>';

    } else if (tab === 'unremitted') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Calls</th><th class="r">Cases</th>
          <th class="r">Net Amt</th><th class="r">Days Old</th><th>Status</th><th>Remarks</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const days = parseInt(r.DaysOld||0);
            const rowStyle = days >= 3 ? 'background:#fee2e2;border-left:3px solid #ef4444' : days >= 1 ? 'background:#fef9c3;border-left:3px solid #eab308' : '';
            const daysColor = days >= 6 ? '#dc2626' : '#374151';
            return `<tr style="${rowStyle}">
              <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
              <td>${branchBadge(r.Branch)}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${fmtDate(r.DocDate)}</td>
              <td>${salesmanTag(r.SalesmanCode)} <span style="font-size:.8rem">${r.Salesman??''}</span></td>
              <td>${r.Area??'—'}</td>
              <td class="r">${parseInt(r.TotalCalls||0).toLocaleString()}</td>
              <td class="r">${parseInt(r.TotalCases||0).toLocaleString()}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
              <td class="r" style="color:${daysColor};font-weight:700">${days}d</td>
              <td>${statusBadge(r.Status)}</td>
              <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.Remarks??'—'}</td>
            </tr>`;
        }).join('') + '</tbody>';

    } else if (tab === 'remitted' || tab === 'received') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Net Amt</th>
          <th class="r">Cash</th><th class="r">Check</th><th class="r">Credit</th>
          <th class="r">Total Remit</th><th class="r">Difference</th>
          <th>Remitted By</th><th>Date Remit</th>
          ${tab === 'received' ? '<th>RRID</th>' : ''}
          <th>Remarks</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const diff = parseFloat(r.TotalDifference||0);
            const rowStyle = tab === 'remitted' ? (diff < 0 ? 'background:#fee2e2' : '') : '';
            return `<tr style="${rowStyle}">
              <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
              <td>${branchBadge(r.Branch)}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${fmtDate(r.DocDate)}</td>
              <td>${salesmanTag(r.SalesmanCode)}</td>
              <td>${r.Area??'—'}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
              <td class="r">${peso(r.TotalCash)}</td>
              <td class="r">${peso(r.TotalCheck)}</td>
              <td class="r">${peso(r.TotalCredit)}</td>
              <td class="r" style="font-weight:700">${peso(r.TotalRemit)}</td>
              <td class="r">${diffCell(r.TotalDifference)}</td>
              <td style="font-size:.8rem">${r.RemittedByName??'—'}</td>
              <td>${fmtDate(r.DateRemit)}</td>
              ${tab === 'received' ? `<td>${rridBadge(r.RRID)}</td>` : ''}
              <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.RemitanceRemarks??'—'}</td>
            </tr>`;
        }).join('') + '</tbody>';

    } else if (tab === 'by_salesman') {
        thead = `<thead><tr>
          <th>Salesman Code</th><th>Salesman</th><th>Area</th><th>Branch</th>
          <th class="r">Deliveries</th><th class="r">Total Calls</th><th class="r">Total Cases</th>
          <th class="r">Total Net Amt</th><th class="r">Avg Net Amt</th>
          <th class="r">Pending</th><th class="r">Delivered</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => `<tr>
          <td>${salesmanTag(r.SalesmanCode)}</td>
          <td style="font-weight:700">${r.Salesman??'—'}</td>
          <td>${r.Area??'—'}</td>
          <td>${branchBadge(r.Branch)}</td>
          <td class="r">${parseInt(r.TotalDeliveries||0).toLocaleString()}</td>
          <td class="r">${parseInt(r.TotalCalls||0).toLocaleString()}</td>
          <td class="r">${parseInt(r.TotalCases||0).toLocaleString()}</td>
          <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
          <td class="r">${peso(r.AvgNetAmount)}</td>
          <td class="r" style="color:#ca8a04;font-weight:700">${parseInt(r.PendingCount||0).toLocaleString()}</td>
          <td class="r" style="color:#16a34a;font-weight:700">${parseInt(r.DeliveredCount||0).toLocaleString()}</td>
        </tr>`).join('') + '</tbody>';

    } else if (tab === 'shorts') {
        thead = `<thead><tr>
          <th>Doc No</th><th>Branch</th><th>Department</th><th>Doc Date</th>
          <th>Salesman</th><th>Area</th><th class="r">Net Amt</th>
          <th class="r">Cash</th><th class="r">Check</th><th class="r">Credit</th>
          <th class="r">Cancelled</th><th class="r">Total Remit</th>
          <th class="r">Short (Diff)</th><th>Remitted By</th><th>Date Remit</th>
          <th>Settlement</th><th>Remarks</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const diff = parseFloat(r.TotalDifference||0);
            const pid  = parseInt(r.PaymentID||0);
            const isSettled = pid > 0;
            const settleBadge = isSettled
                ? `<span class="badge badge-settled">✓ Settled #${pid}</span>`
                : `<span class="badge badge-unsettled">⚠ Unsettled</span>`;
            return `<tr style="background:${isSettled ? '#f0fdf4' : '#fff5f5'}">
              <td><b style="color:#7c3aed">${r.DocNo??'—'}</b></td>
              <td>${branchBadge(r.Branch)}</td>
              <td>${deptBadge(r.Department)}</td>
              <td>${fmtDate(r.DocDate)}</td>
              <td>${salesmanTag(r.SalesmanCode)}</td>
              <td>${r.Area??'—'}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
              <td class="r">${peso(r.TotalCash)}</td>
              <td class="r">${peso(r.TotalCheck)}</td>
              <td class="r">${peso(r.TotalCredit)}</td>
              <td class="r">${peso(r.TotalCancel)}</td>
              <td class="r" style="font-weight:700">${peso(r.TotalRemit)}</td>
              <td class="r" style="color:#dc2626;font-weight:700">▼ ${peso(Math.abs(diff))}</td>
              <td style="font-size:.8rem">${r.RemittedByName??'—'}</td>
              <td>${fmtDate(r.DateRemit)}</td>
              <td>${settleBadge}</td>
              <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.RemitanceRemarks??'—'}</td>
            </tr>`;
        }).join('') + '</tbody>';

    } else if (tab === 'by_leadman') {
        thead = `<thead><tr>
          <th>Leadman / Remitter</th>
          <th class="r">Remittances</th><th class="r">Unreceived</th>
          <th class="r">Received</th><th class="r">Total Net Amt</th>
          <th class="r">Cash</th><th class="r">Check</th><th class="r">Credit</th>
          <th class="r">Cancelled</th><th class="r">Total Remitted</th><th class="r">Net Diff</th>
          <th class="r">Shorts</th><th class="r">Short Amt</th>
        </tr></thead>`;
        tbody = '<tbody>' + ALL_DATA.map(r => {
            const netDiff  = parseFloat(r.TotalDifference||0);
            const diffColor = netDiff < 0 ? '#dc2626' : netDiff > 0 ? '#16a34a' : '#6b7280';
            const diffLabel = netDiff > 0 ? '▲ ' : netDiff < 0 ? '▼ ' : '';
            const shorts    = parseFloat(r.TotalShorts||0);
            return `<tr>
              <td style="font-weight:700">${r.RemittedByName??'—'}</td>
              <td class="r">${parseInt(r.TotalRemittances||0).toLocaleString()}</td>
              <td class="r" style="color:#ca8a04;font-weight:700">${parseInt(r.PendingCount||0).toLocaleString()}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${parseInt(r.RemittedCount||0).toLocaleString()}</td>
              <td class="r" style="color:#16a34a;font-weight:700">${peso(r.TotalNetAmount)}</td>
              <td class="r">${peso(r.TotalCash)}</td>
              <td class="r">${peso(r.TotalCheck)}</td>
              <td class="r">${peso(r.TotalCredit)}</td>
              <td class="r" style="color:#dc2626">${peso(r.TotalCancelled)}</td>
              <td class="r" style="color:#2563eb;font-weight:700">${peso(r.TotalRemitted)}</td>
              <td class="r" style="color:${diffColor};font-weight:700">${netDiff !== 0 ? diffLabel + peso(Math.abs(netDiff)) : '—'}</td>
              <td class="r" style="color:#dc2626;font-weight:700">${parseInt(r.ShortsCount||0).toLocaleString()}</td>
              <td class="r" style="color:${shorts>0?'#dc2626':'#6b7280'};font-weight:700">${shorts>0 ? '▼ '+peso(shorts) : '—'}</td>
            </tr>`;
        }).join('') + '</tbody>';
    }

    const win = window.open('', '_blank', 'width=1200,height=800');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>${tabTitle}</title>
        <style>
          @page { size: landscape; margin: 10mm 8mm; }
          * { box-sizing: border-box; }
          body { font-family: Arial, sans-serif; font-size: 8.5px; color: #111; margin: 0; padding: 6px 8px; }
          .print-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; border-bottom: 1.5px solid #333; padding-bottom: 4px; }
          .print-header h3 { margin: 0; font-size: 11px; font-weight: 800; }
          .print-header p  { margin: 0; font-size: 7.5px; color: #555; text-align: right; }
          table { width: 100%; border-collapse: collapse; table-layout: auto; }
          thead { display: table-header-group; }
          th { background: #e8e8e8; font-weight: 700; font-size: 7.5px; text-transform: uppercase; letter-spacing: .03em;
               border: 1px solid #aaa; padding: 3px 4px; text-align: left; vertical-align: bottom; white-space: nowrap; }
          td { border: 1px solid #ccc; padding: 2px 4px; vertical-align: middle; font-size: 8px; }
          tr:nth-child(even) td { background: #f7f7f7; }
          .r { text-align: right; }
          .mono { font-family: 'Courier New', monospace; }
          .badge { font-size: 7px; padding: 0 3px; border-radius: 2px; font-weight: 700; display: inline-block; }
          .badge-branch  { background: #e8e3ff; color: #4a1799; }
          .badge-dept    { background: #e8f0fe; color: #1a56b0; }
          .badge-status  { background: #e6f4ea; color: #145523; }
          .badge-salesman{ background: #dbeafe; color: #1e3a8a; }
          .badge-rrid    { background: #dcfce7; color: #166534; }
          .badge-settled { background: #dcfce7; color: #166534; }
          .badge-unsettled { background: #fee2e2; color: #991b1b; }
          .text-green { color: #166534; font-weight: 700; }
          .text-red   { color: #991b1b; font-weight: 700; }
          .text-amber { color: #713f12; font-weight: 700; }
          .text-purple{ color: #5b21b6; font-weight: 700; }
          .text-blue  { color: #1e40af; font-weight: 700; }
          tfoot td { font-weight: 800; background: #f0f0f0; border-top: 1.5px solid #999; font-size: 8px; }
          @media print { body { padding: 0; } }
        </style>
      </head><body>
        <div class="print-header">
          <h3>${tabTitle}</h3>
          <p>Date Range: <?= htmlspecialchars($baseFrom) ?> → <?= htmlspecialchars($baseTo) ?><br>Exported: <?= date('Y-m-d H:i:s') ?> &nbsp;|&nbsp; Total: ${ALL_DATA.length} records</p>
        </div>
        <table>${thead}${tbody}</table>
      </body></html>`);
    win.document.close();
    win.focus();
    win.print();
    win.close();
}

// ── Shorts Remarks Modal ──────────────────────────────────
function openRemarksModal(text) {
    document.getElementById('remarksModalText').textContent = text || '—';
    document.getElementById('remarksModal').style.display = 'flex';
}
function closeRemarksModal() {
    document.getElementById('remarksModal').style.display = 'none';
}
document.getElementById('remarksModal').addEventListener('click', function(e) {
    if (e.target === this) closeRemarksModal();
});
</script>
</body>
</html>