<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'short_stocks_paid');

$sppId = isset($_GET['spp_id']) ? trim($_GET['spp_id']) : '';

if ($sppId === '') {
    header('Location: ' . base_url('ACCOUNTING/short_stocks_paid.php'));
    exit;
}

function esc(string $v): string {
    return str_replace("'", "''", $v);
}

$sql = "
    SELECT [SEID],[Position],[Status],[Amount],[SPPID],[AmountDue],[PaidAmount],
           [Balance],[DateGenerate],[SDID],[DID],[Department],[DateSchedule],
           [PlateNumber],[Area],[Outlet],[RefNo],[TotalAmount],[NumAccountable],
           [AmountL],[StatusofShort],[Remarks],[IDS],[EmployeeID],[EmployeeName],
           [DatePaid],[TypeShort],[Category],[Employee_Status],[Job_tittle],
           [Position_held],[PaymentID],[Source]
    FROM [dbo].[View_ShortPaymentPaidDetails]
    WHERE SPPID = '" . esc($sppId) . "'
";

$stmt = sqlsrv_query($conn, $sql);
$record = null;
if ($stmt) {
    $record = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if ($record) {
        foreach ($record as $k => $v) {
            if ($v instanceof DateTime) $record[$k] = $v->format('Y-m-d');
        }
    }
    sqlsrv_free_stmt($stmt);
} else {
    error_log('[short_stocks_paid_view] SQL failed: ' . print_r(sqlsrv_errors(), true) . "\nQuery: " . $sql);
}

$notFound = !$record;

function peso_fmt($n) {
    return '₱' . number_format((float)($n ?? 0), 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Short Stocks Paid — Record Detail</title>
<link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/responsive-patch.css') ?>" rel="stylesheet">
<style>
:root {
    --pg-accent: #2563eb;
    --pg-accent-soft: #eaf0fe;
    --pg-border: #e2e8f0;
    --pg-bg: #f8fafc;
    --pg-text-muted: #64748b;
    --pg-radius: 14px;
}
body { font-family: 'IBM Plex Sans', sans-serif; background: var(--pg-bg); margin: 0; }
.pg-content { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }

.pg-back-link {
    display: inline-flex; align-items: center; gap: .35rem;
    color: var(--pg-accent); text-decoration: none; font-weight: 600; font-size: .85rem;
    margin-bottom: 1rem;
}
.pg-back-link:hover { text-decoration: underline; }

.pg-card {
    background: #fff; border: 1px solid var(--pg-border); border-radius: var(--pg-radius);
    padding: 1.5rem; margin-bottom: 1.25rem;
}

.pg-detail-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 1px solid var(--pg-border); padding-bottom: 1rem; margin-bottom: 1.25rem;
}
.pg-detail-name { font-size: 1.15rem; font-weight: 700; color: #0f172a; }
.pg-detail-sub { color: var(--pg-text-muted); font-size: .85rem; margin-top: .2rem; }

.pg-badge { display: inline-block; padding: .25rem .65rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
.pg-badge-paid { background: #dcfce7; color: #166534; }
.pg-badge-default { background: #e5e7eb; color: #374151; }

.pg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.1rem; }
.pg-field-label { font-size: .74rem; color: var(--pg-text-muted); text-transform: uppercase; letter-spacing: .02em; margin-bottom: .25rem; }
.pg-field-value { font-size: .92rem; color: #0f172a; font-weight: 600; }
.pg-field-value.mono { font-family: 'IBM Plex Mono', monospace; }
.pg-field-value.amount { color: var(--pg-accent); font-family: 'IBM Plex Mono', monospace; }

.pg-section-title {
    font-size: .8rem; font-weight: 700; color: #1e3a8a; text-transform: uppercase;
    letter-spacing: .03em; margin: 1.5rem 0 .85rem; padding-top: 1rem; border-top: 1px dashed var(--pg-border);
}
.pg-section-title:first-of-type { margin-top: 0; padding-top: 0; border-top: none; }

.pg-empty { text-align: center; padding: 3rem 1rem; color: var(--pg-text-muted); }

.pg-btn {
    border: none; border-radius: 8px; padding: .55rem 1rem; font-size: .85rem;
    font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: .4rem;
    background: var(--pg-accent); color: #fff; text-decoration: none;
}
.pg-btn:hover { background: #1d4ed8; }
</style>
</head>
<body>

<?php $topbar_page = 'short_stocks_paid'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="pg-content">

    <a href="<?= base_url('ACCOUNTING/short_stocks_paid.php') ?>" class="pg-back-link">
        <i class="bi bi-arrow-left"></i> Back to Short Stocks Paid
    </a>

    <?php if ($notFound): ?>
        <div class="pg-card">
            <div class="pg-empty">Record not found. It may have been removed or the link is invalid.</div>
        </div>
    <?php else: ?>

    <div class="pg-card">
        <div class="pg-detail-header">
            <div>
                <div class="pg-detail-name"><?= htmlspecialchars($record['EmployeeName'] ?? '-') ?></div>
                <div class="pg-detail-sub">
                    <?= htmlspecialchars($record['Position'] ?? '-') ?>
                    &middot; <?= htmlspecialchars($record['Department'] ?? '-') ?>
                </div>
            </div>
            <?php
                $statusVal = $record['StatusofShort'] ?? '';
                $badgeClass = stripos($statusVal, 'paid') !== false ? 'pg-badge-paid' : 'pg-badge-default';
            ?>
            <span class="pg-badge <?= $badgeClass ?>"><?= htmlspecialchars($statusVal ?: '-') ?></span>
        </div>

        <div class="pg-section-title">Payment Summary</div>
        <div class="pg-grid">
            <div>
                <div class="pg-field-label">Amount Due</div>
                <div class="pg-field-value amount"><?= peso_fmt($record['AmountDue']) ?></div>
            </div>
            <div>
                <div class="pg-field-label">Paid Amount</div>
                <div class="pg-field-value amount"><?= peso_fmt($record['PaidAmount']) ?></div>
            </div>
            <div>
                <div class="pg-field-label">Balance</div>
                <div class="pg-field-value amount"><?= peso_fmt($record['Balance']) ?></div>
            </div>
            <div>
                <div class="pg-field-label">Total Amount</div>
                <div class="pg-field-value amount"><?= peso_fmt($record['TotalAmount']) ?></div>
            </div>
            <div>
                <div class="pg-field-label">Date Paid</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['DatePaid'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Payment ID</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['PaymentID'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Source</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Source'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Reference No.</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['RefNo'] ?? '-') ?></div>
            </div>
        </div>

        <div class="pg-section-title">Short Stock Details</div>
        <div class="pg-grid">
            <div>
                <div class="pg-field-label">Type Short</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['TypeShort'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Category</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Category'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Num. Accountable</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['NumAccountable'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Amount (L)</div>
                <div class="pg-field-value amount"><?= peso_fmt($record['AmountL']) ?></div>
            </div>
            <div>
                <div class="pg-field-label">Date Generated</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['DateGenerate'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Date Schedule</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['DateSchedule'] ?? '-') ?></div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div class="pg-field-label">Remarks</div>
                <div class="pg-field-value"><?= nl2br(htmlspecialchars($record['Remarks'] ?? '-')) ?></div>
            </div>
        </div>

        <div class="pg-section-title">Route / Assignment</div>
        <div class="pg-grid">
            <div>
                <div class="pg-field-label">Plate Number</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['PlateNumber'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Area</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Area'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Outlet</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Outlet'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">SDID</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['SDID'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">DID</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['DID'] ?? '-') ?></div>
            </div>
        </div>

        <div class="pg-section-title">Employee Info</div>
        <div class="pg-grid">
            <div>
                <div class="pg-field-label">Employee ID</div>
                <div class="pg-field-value mono"><?= htmlspecialchars($record['EmployeeID'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Job Title</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Job_tittle'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Position Held</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Position_held'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Employee Status</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Employee_Status'] ?? '-') ?></div>
            </div>
            <div>
                <div class="pg-field-label">Record Status</div>
                <div class="pg-field-value"><?= htmlspecialchars($record['Status'] ?? '-') ?></div>
            </div>
        </div>

        <div style="margin-top: 1.5rem; display:flex; gap:.6rem;">
            <a class="pg-btn" href="<?= base_url('ACCOUNTING/short_stocks_paid.php') ?>">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <?php endif; ?>

</div>

</body>
</html>