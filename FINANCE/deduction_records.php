<?php
/**
 * deduction_records.php
 * TWM/FINANCE — Deduction Records
 * Source: dbo.View_All_Deduction (DocNo, Department, Branch, Area, Salesman,
 *         InvoiceNo, InvoiceDate, Customer, DocDate, Type, Remarks, Amount, Source)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'deduction_records');
$viewOnly = rbac_is_view_only('deduction_records');

date_default_timezone_set('Asia/Manila');

$Department  = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');
$topbar_page = 'deduction_records';

// Deterministic badge color per Source value — same source always gets the
// same color, and it scales automatically as new Source values show up.
function dr_badge_color($source) {
    static $palette = [
        ['bg' => '#ede9fe', 'fg' => '#6d28d9', 'bd' => '#ddd6fe'], // violet
        ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'bd' => '#93c5fd'], // blue
        ['bg' => '#dcfce7', 'fg' => '#15803d', 'bd' => '#86efac'], // green
        ['bg' => '#fef3c7', 'fg' => '#b45309', 'bd' => '#fcd34d'], // amber
        ['bg' => '#fce7f3', 'fg' => '#be185d', 'bd' => '#f9a8d4'], // pink
        ['bg' => '#e0f2fe', 'fg' => '#0369a1', 'bd' => '#7dd3fc'], // sky
        ['bg' => '#f3e8ff', 'fg' => '#7e22ce', 'bd' => '#d8b4fe'], // purple
        ['bg' => '#ffedd5', 'fg' => '#c2410c', 'bd' => '#fdba74'], // orange
    ];
    $idx = abs(crc32((string)$source)) % count($palette);
    return $palette[$idx];
}

// --- Filter inputs (GET so pagination links preserve filters) ---
$Search   = trim($_GET['Search'] ?? '');
$Source   = $_GET['Source'] ?? '';       // '', 'Delivery', 'AR Collection'

// Only apply the 1-month default on a completely fresh page load (no query
// string at all). Any submitted filter — even with date fields left blank —
// means "all time" for dates unless the user set one explicitly.
$isFreshLoad = empty($_GET);
$DateFrom = $_GET['DateFrom'] ?? ($isFreshLoad ? date('Y-m-d', strtotime('-1 month')) : '');
$DateTo   = $_GET['DateTo'] ?? '';

$Page     = max(1, (int)($_GET['page'] ?? 1));
$PageSize = 25;

// --- Build WHERE clause (parameterized) ---
$conditions = [];
$params     = [];

// Always scoped to the current session department
if ($Department !== '') {
    $conditions[] = "Department = ?";
    $params[]     = $Department;
}
if ($Source !== '') {
    $conditions[] = "Source = ?";
    $params[]     = $Source;
}
if ($Search !== '') {
    $term = '%' . $Search . '%';
    $conditions[] = "(DocNo LIKE ? OR InvoiceNo LIKE ? OR Remarks LIKE ? OR Type LIKE ? OR Salesman LIKE ? OR Customer LIKE ?)";
    array_push($params, $term, $term, $term, $term, $term, $term);
}
if ($DateFrom !== '') {
    $conditions[] = "DocDate >= ?";
    $params[]     = $DateFrom;
}
if ($DateTo !== '') {
    $conditions[] = "DocDate <= ?";
    $params[]     = $DateTo;
}

$whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

// --- Dynamic dropdown options (scoped to Department only, independent of other filters) ---
$sourceOptions = [];
$sourceSql = "SELECT DISTINCT Source FROM dbo.View_All_Deduction
              WHERE Source IS NOT NULL AND Source <> ''" .
              ($Department !== '' ? " AND Department = ?" : "") .
              " ORDER BY Source";
$sourceStmt = $pdo->prepare($sourceSql);
$sourceStmt->execute($Department !== '' ? [$Department] : []);
while ($row = $sourceStmt->fetch(PDO::FETCH_ASSOC)) {
    $sourceOptions[] = $row['Source'];
}

// --- Export (CSV / Excel) — full filtered result set, no pagination ---
$Export = $_GET['export'] ?? '';
if ($Export === 'csv' || $Export === 'excel') {
    $exportSql = "
        SELECT DocNo, Source, Department, Branch, Area, Salesman, InvoiceNo,
               CONVERT(varchar(10), InvoiceDate, 23) AS InvoiceDateFmt,
               Customer,
               CONVERT(varchar(10), DocDate, 23) AS DocDateFmt,
               Type, Remarks, Amount
        FROM dbo.View_All_Deduction
        $whereSql
        ORDER BY DocDate DESC
    ";
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);

    $headers = ['Ref#', 'Source', 'Department', 'Branch', 'Area', 'Salesman', 'Invoice#', 'Inv.Date', 'Customer', 'Doc.Date', 'Type', 'Remarks', 'Amount'];
    $filename = 'deduction_records_' . date('Ymd_His');

    if ($Export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        while ($r = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $r['DocNo'], $r['Source'], $r['Department'], $r['Branch'], $r['Area'],
                $r['Salesman'], $r['InvoiceNo'], $r['InvoiceDateFmt'], $r['Customer'],
                $r['DocDateFmt'], $r['Type'], $r['Remarks'], number_format($r['Amount'], 2, '.', ''),
            ]);
        }
        fclose($out);
        exit;
    }

    if ($Export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo "<table border='1'><tr>";
        foreach ($headers as $h) { echo '<th>' . htmlspecialchars($h) . '</th>'; }
        echo '</tr>';
        while ($r = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            echo '<tr>';
            foreach ([$r['DocNo'], $r['Source'], $r['Department'], $r['Branch'], $r['Area'],
                      $r['Salesman'], $r['InvoiceNo'], $r['InvoiceDateFmt'], $r['Customer'],
                      $r['DocDateFmt'], $r['Type'], $r['Remarks'], number_format($r['Amount'], 2)] as $cell) {
                echo '<td>' . htmlspecialchars((string)$cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }
}

// --- Stat totals (count + amount sum), same filters ---
$statSql = "SELECT COUNT(*) AS TotalCount, ISNULL(SUM(Amount),0) AS TotalAmount
            FROM dbo.View_All_Deduction $whereSql";
$statStmt = $pdo->prepare($statSql);
$statStmt->execute($params);
$TotalCount  = 0;
$TotalAmount = 0;
if ($row = $statStmt->fetch(PDO::FETCH_ASSOC)) {
    $TotalCount  = (int)$row['TotalCount'];
    $TotalAmount = (float)$row['TotalAmount'];
}

$sourceStatSql = "SELECT Source, COUNT(*) AS Cnt
                   FROM dbo.View_All_Deduction $whereSql
                   GROUP BY Source ORDER BY Source";
$sourceStatStmt = $pdo->prepare($sourceStatSql);
$sourceStatStmt->execute($params);
$sourceStats = [];
while ($row = $sourceStatStmt->fetch(PDO::FETCH_ASSOC)) {
    $sourceStats[] = $row;
}

$TotalPages = max(1, (int)ceil($TotalCount / $PageSize));
$Page       = min($Page, $TotalPages);
$Offset     = ($Page - 1) * $PageSize;

// --- Paged data ---
$dataSql = "
    SELECT DocNo, Department, Branch, Area, Salesman, InvoiceNo,
           InvoiceDate, Customer,
           CONVERT(varchar(10), DocDate, 23) AS DocDateFmt,
           CONVERT(varchar(10), InvoiceDate, 23) AS InvoiceDateFmt,
           Type, Remarks, Amount, Source
    FROM dbo.View_All_Deduction
    $whereSql
    ORDER BY DocDate DESC
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

// --- Full filtered dataset (unpaginated) — used for printing only ---
$printSql = "
    SELECT DocNo, Department, Branch, Area, Salesman, InvoiceNo,
           InvoiceDate, Customer,
           CONVERT(varchar(10), DocDate, 23) AS DocDateFmt,
           CONVERT(varchar(10), InvoiceDate, 23) AS InvoiceDateFmt,
           Type, Remarks, Amount, Source
    FROM dbo.View_All_Deduction
    $whereSql
    ORDER BY DocDate DESC
";
$printStmt = $pdo->prepare($printSql);
$printStmt->execute($params);
$printRows = $printStmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to preserve filters across pagination links
function qs($overrides = []) {
    $current = $_GET;
    foreach ($overrides as $k => $v) { $current[$k] = $v; }
    return htmlspecialchars('?' . http_build_query($current));
}

if ($DateFrom !== '' && $DateTo !== '') {
    $dateRangeLabel = $DateFrom . ' to ' . $DateTo;
} elseif ($DateFrom !== '') {
    $dateRangeLabel = 'From ' . $DateFrom;
} elseif ($DateTo !== '') {
    $dateRangeLabel = 'Up to ' . $DateTo;
} else {
    $dateRangeLabel = 'All Time';
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
    <title>Deduction Records</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: #f4f5f7;
            color: #1a1d23;
            font-size: 14px;
            line-height: 1.5;
        }

        .dr-page { max-width: 1400px; margin: 0 auto; padding: 24px 20px 48px; }

        /* ── Page Header ──────────────────────────────── */
        .dr-page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px; margin-bottom: 28px; padding-bottom: 20px;
            border-bottom: 2px solid #e2e5ea;
        }
        .dr-dept-label {
            font-size: 12px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.08em; color: #6b7280; margin-bottom: 4px;
        }
        .dr-page-title { font-size: 26px; font-weight: 600; color: #111827; line-height: 1.2; }
        .dr-page-title span { color: #2563eb; }

        /* ── Stat Cards ───────────────────────────────── */
        .dr-stats { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .dr-stat-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 16px 22px; min-width: 180px; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .dr-stat-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #6b7280; margin-bottom: 4px;
        }
        .dr-stat-value { font-size: 22px; font-weight: 700; color: #111827; }
        .dr-stat-card.accent .dr-stat-value { color: #2563eb; }

        /* ── Filter / Search Card ─────────────────────── */
        .dr-search-card {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            padding: 20px 24px; margin-bottom: 20px; display: flex; align-items: end;
            gap: 14px; flex-wrap: wrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .dr-field { display: flex; flex-direction: column; gap: 6px; }
        .dr-field label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.05em; color: #6b7280;
        }
        .dr-field input, .dr-field select {
            height: 42px; padding: 0 14px; border: 1.5px solid #d1d5db; border-radius: 9px;
            font-family: 'IBM Plex Mono', monospace; font-size: 13px; color: #111827;
            background: #f9fafb; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .dr-field input:focus, .dr-field select:focus {
            border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }
        .dr-btn {
            height: 42px; padding: 0 20px; border: none; border-radius: 9px;
            font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; white-space: nowrap; transition: background .15s, transform .1s;
        }
        .dr-btn:active { transform: scale(.98); }
        .dr-btn--primary { background: #2563eb; color: #fff; }
        .dr-btn--primary:hover { background: #1d4ed8; }
        .dr-btn--ghost { background: #fff; color: #374151; border: 1.5px solid #d1d5db; }
        .dr-btn--ghost:hover { background: #f3f4f6; border-color: #9ca3af; }

        /* ── Export Bar ───────────────────────────────── */
        .dr-export-bar { display: flex; gap: 8px; margin-bottom: 14px; }
        .dr-export-bar .dr-btn { height: 36px; padding: 0 14px; font-size: 12px; }

        /* ── Section / Table Card ─────────────────────── */
        .dr-section {
            background: #fff; border: 1.5px solid #e2e5ea; border-radius: 14px;
            overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }
        .dr-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .dr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .dr-table thead th {
            background: #f8f9fb; color: #4b5563; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; padding: 10px 12px;
            border-bottom: 1.5px solid #e2e5ea; white-space: nowrap; text-align: left;
        }
        .dr-table thead th.r { text-align: right; }
        .dr-table tbody td {
            padding: 9px 12px; border-bottom: 1px solid #f1f3f7; color: #374151; vertical-align: middle;
        }
        .dr-table tbody td.r { text-align: right; font-family: 'IBM Plex Mono', monospace; }
        .dr-table tbody tr:last-child td { border-bottom: none; }
        .dr-table tbody tr:hover td { background: #f8f9fb; }

        /* ── Source Badges ────────────────────────────── */
        .dr-badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .dr-badge--delivery { background: #ede9fe; color: #6d28d9; border: 1px solid #ddd6fe; }
        .dr-badge--arcollection { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .dr-badge--other { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }

        /* ── Empty State ──────────────────────────────── */
        .dr-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }

        /* ── Pagination ───────────────────────────────── */
        .dr-pagination { display: flex; gap: 6px; align-items: center; padding: 16px 22px; }
        .dr-pagination a, .dr-pagination span {
            padding: 6px 12px; border: 1.5px solid #d1d5db; border-radius: 8px;
            text-decoration: none; color: #374151; font-size: 12px; font-weight: 600;
        }
        .dr-pagination a:hover { background: #f3f4f6; }
        .dr-pagination .active { background: #2563eb; color: #fff; border-color: #1d4ed8; }

        @media (max-width: 640px) {
            .dr-page { padding: 16px 12px 40px; }
            .dr-search-card { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="content">
<div class="dr-page">

    <!-- ── Page Header ───────────────────────────────── -->
    <div class="dr-page-header">
        <div>
            <div class="dr-dept-label"><?php echo htmlspecialchars($Department); ?> &nbsp;· Deduction Records</div>
            <h1 class="dr-page-title">Deduction <span>Records</span></h1>
        </div>
    </div>

    <!-- ── Stat Cards ───────────────────────────────── -->
    <div class="dr-stats">
        <div class="dr-stat-card">
            <div class="dr-stat-label">Total Records</div>
            <div class="dr-stat-value"><?php echo number_format($TotalCount); ?></div>
        </div>
        <div class="dr-stat-card accent">
            <div class="dr-stat-label">Total Amount</div>
            <div class="dr-stat-value">₱<?php echo number_format($TotalAmount, 2); ?></div>
        </div>
        <?php foreach ($sourceStats as $s): $bc = dr_badge_color($s['Source']); ?>
        <div class="dr-stat-card" style="border-left: 4px solid <?php echo $bc['fg']; ?>;">
            <div class="dr-stat-label"><?php echo htmlspecialchars($s['Source']); ?></div>
            <div class="dr-stat-value"><?php echo number_format($s['Cnt']); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Filter Card ──────────────────────────────── -->
    <form method="get" class="dr-search-card">
        <div class="dr-field">
            <label for="Source">Source</label>
            <select id="Source" name="Source">
                <option value="" <?php echo $Source === '' ? 'selected' : ''; ?>>All</option>
                <?php foreach ($sourceOptions as $opt): ?>
                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $Source === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="dr-field">
            <label for="DateFrom">Date From</label>
            <input type="date" id="DateFrom" name="DateFrom" value="<?php echo htmlspecialchars($DateFrom); ?>">
        </div>
        <div class="dr-field">
            <label for="DateTo">Date To</label>
            <input type="date" id="DateTo" name="DateTo" value="<?php echo htmlspecialchars($DateTo); ?>">
        </div>
        <div class="dr-field">
            <label for="Search">Search</label>
            <input type="text" id="Search" name="Search" value="<?php echo htmlspecialchars($Search); ?>" placeholder="Invoice, Ref#, Customer, Salesman...">
        </div>
        <div class="dr-field">
            <button type="submit" class="dr-btn dr-btn--primary"><i class="bi bi-funnel"></i> Filter</button>
        </div>
        <?php if ($Search || $Source || $DateFrom || $DateTo): ?>
        <div class="dr-field">
            <a href="deduction_records.php" class="dr-btn dr-btn--ghost">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <!-- ── Export Bar ───────────────────────────────── -->
    <div class="dr-export-bar">
        <a class="dr-btn dr-btn--ghost" href="<?php echo qs(['export' => 'csv']); ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
        <a class="dr-btn dr-btn--ghost" href="<?php echo qs(['export' => 'excel']); ?>"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
        <button type="button" class="dr-btn dr-btn--ghost" onclick="printReport()"><i class="bi bi-printer"></i> Print</button>
    </div>

    <!-- ── Table Section ────────────────────────────── -->
    <div class="dr-section">
        <div class="dr-table-wrap">
            <table class="dr-table">
                <thead>
                    <tr>
                        <th>Ref#</th>
                        <th>Source</th>
                        <th>Department</th>
                        <th>Branch</th>
                        <th>Area</th>
                        <th>Salesman</th>
                        <th>Invoice#</th>
                        <th>Inv.Date</th>
                        <th>Customer</th>
                        <th>Doc.Date</th>
                        <th>Type</th>
                        <th>Remarks</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stmt): while ($r = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['DocNo']); ?></td>
                        <?php $bc = dr_badge_color($r['Source']); ?>
                        <td>
                            <span class="dr-badge" style="background:<?php echo $bc['bg']; ?>;color:<?php echo $bc['fg']; ?>;border:1px solid <?php echo $bc['bd']; ?>;">
                                <?php echo htmlspecialchars($r['Source']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($r['Department']); ?></td>
                        <td><?php echo htmlspecialchars($r['Branch']); ?></td>
                        <td><?php echo htmlspecialchars($r['Area']); ?></td>
                        <td><?php echo htmlspecialchars($r['Salesman']); ?></td>
                        <td><?php echo htmlspecialchars($r['InvoiceNo']); ?></td>
                        <td><?php echo htmlspecialchars($r['InvoiceDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($r['Customer']); ?></td>
                        <td><?php echo htmlspecialchars($r['DocDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($r['Type']); ?></td>
                        <td><?php echo htmlspecialchars($r['Remarks']); ?></td>
                        <td class="r"><?php echo number_format($r['Amount'], 2); ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="13" class="dr-empty">No records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Hidden source content for the isolated print window (built in JS below).
             Not shown on screen and not affected by this page's CSS at all. -->
        <div id="print-report-content" style="display:none;">
            <h2>Deduction Records — <?php echo htmlspecialchars($Department); ?></h2>
            <div class="dr-print-meta">
                <span>Date Range: <?php echo htmlspecialchars($dateRangeLabel); ?></span>
                <span>Total Records: <?php echo number_format($TotalCount); ?></span>
                <span>Generated: <?php echo date('Y-m-d h:i A'); ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Ref#</th>
                        <th>Source</th>
                        <th>Department</th>
                        <th>Branch</th>
                        <th>Area</th>
                        <th>Salesman</th>
                        <th>Invoice#</th>
                        <th>Inv.Date</th>
                        <th>Customer</th>
                        <th>Doc.Date</th>
                        <th>Type</th>
                        <th>Remarks</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($printRows): foreach ($printRows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['DocNo']); ?></td>
                        <?php $bc = dr_badge_color($r['Source']); ?>
                        <td>
                            <span class="dr-badge" style="background:<?php echo $bc['bg']; ?>;color:<?php echo $bc['fg']; ?>;border:1px solid <?php echo $bc['bd']; ?>;">
                                <?php echo htmlspecialchars($r['Source']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($r['Department']); ?></td>
                        <td><?php echo htmlspecialchars($r['Branch']); ?></td>
                        <td><?php echo htmlspecialchars($r['Area']); ?></td>
                        <td><?php echo htmlspecialchars($r['Salesman']); ?></td>
                        <td><?php echo htmlspecialchars($r['InvoiceNo']); ?></td>
                        <td><?php echo htmlspecialchars($r['InvoiceDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($r['Customer']); ?></td>
                        <td><?php echo htmlspecialchars($r['DocDateFmt']); ?></td>
                        <td><?php echo htmlspecialchars($r['Type']); ?></td>
                        <td><?php echo htmlspecialchars($r['Remarks']); ?></td>
                        <td class="r"><?php echo number_format($r['Amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="13">No records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="dr-pagination">
            <?php if ($Page > 1): ?>
                <a href="<?php echo qs(['page' => $Page - 1]); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <span class="active"><?php echo $Page; ?> / <?php echo $TotalPages; ?></span>
            <?php if ($Page < $TotalPages): ?>
                <a href="<?php echo qs(['page' => $Page + 1]); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<script>
function printReport() {
    var content = document.getElementById('print-report-content').innerHTML;
    var win = window.open('', '_blank', 'width=1100,height=800');
    win.document.write(
        '<!doctype html><html><head><title>Deduction Records</title><style>' +
        '*{box-sizing:border-box;margin:0;padding:0;}' +
        'body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;padding:24px;}' +
        'h2{font-size:16px;margin-bottom:6px;}' +
        '.dr-print-meta{display:flex;gap:20px;font-size:11px;color:#333;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #000;}' +
        'table{width:100%;border-collapse:collapse;font-size:11px;}' +
        'th,td{border:1px solid #999;padding:4px 6px;text-align:left;vertical-align:middle;}' +
        'th{background:#f0f0f0;}' +
        'td.r,th.r{text-align:right;}' +
        '.dr-badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:600;}' +
        '@media print{ thead{display:table-header-group;} }' +
        '</style></head><body>' + content + '</body></html>'
    );
    win.document.close();
    win.onload = function () {
        win.focus();
        win.print();
    };
}
</script>
</body>
</html>