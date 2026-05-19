<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

$Department = $_SESSION['Department'] ?? ($_SESSION['department'] ?? '');

$topbar_page = 'invoice_monitoring';

if (isset($_POST['Invoice']) && trim($_POST['Invoice']) !== '') {
    $InvoiceNo = trim($_POST['Invoice']);
} else {
    $InvoiceNo = null;
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
    <style>
        /* ── Reset & Base ─────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: #f4f5f7;
            color: #1a1d23;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Page Wrapper ─────────────────────────────── */
        .im-page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 20px 48px;
        }

        /* ── Page Header ──────────────────────────────── */
        .im-page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e5ea;
        }

        .im-dept-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .im-page-title {
            font-size: 26px;
            font-weight: 600;
            color: #111827;
            line-height: 1.2;
        }

        .im-page-title span {
            color: #2563eb;
        }

        /* ── Search Bar ───────────────────────────────── */
        .im-search-card {
            background: #fff;
            border: 1.5px solid #e2e5ea;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }

        .im-search-label {
            font-weight: 600;
            font-size: 13px;
            color: #374151;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .im-search-label svg {
            width: 18px; height: 18px;
            color: #2563eb;
            flex-shrink: 0;
        }

        .im-search-form {
            display: flex;
            gap: 8px;
            flex: 1;
            min-width: 240px;
        }

        .im-search-input {
            flex: 1;
            height: 42px;
            padding: 0 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 9px;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 14px;
            color: #111827;
            background: #f9fafb;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }

        .im-search-input:focus {
            border-color: #2563eb;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        .im-search-input::placeholder { color: #9ca3af; }

        .im-search-btn {
            height: 42px;
            padding: 0 20px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 9px;
            font-family: 'IBM Plex Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
            transition: background .15s, transform .1s;
            white-space: nowrap;
        }

        .im-search-btn:hover { background: #1d4ed8; }
        .im-search-btn:active { transform: scale(.98); }

        .im-search-btn svg { width: 16px; height: 16px; }

        /* Active search indicator */
        .im-search-active {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            color: #1d4ed8;
            font-family: 'IBM Plex Mono', monospace;
        }

        /* ── Section Cards ────────────────────────────── */
        .im-section {
            background: #fff;
            border: 1.5px solid #e2e5ea;
            border-radius: 14px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.04);
        }

        .im-section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            border-bottom: 1.5px solid #e2e5ea;
        }

        /* Color accents per section */
        .im-section--invoice .im-section-header { background: #eff6ff; border-left: 4px solid #2563eb; }
        .im-section--delivery .im-section-header { background: #f0fdf4; border-left: 4px solid #16a34a; }
        .im-section--ar-created .im-section-header { background: #fff7ed; border-left: 4px solid #ea580c; }
        .im-section--ar-collection .im-section-header { background: #faf5ff; border-left: 4px solid #7c3aed; }

        .im-section-icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .im-section--invoice .im-section-icon      { background: #dbeafe; color: #1d4ed8; }
        .im-section--delivery .im-section-icon     { background: #dcfce7; color: #15803d; }
        .im-section--ar-created .im-section-icon   { background: #ffedd5; color: #c2410c; }
        .im-section--ar-collection .im-section-icon{ background: #ede9fe; color: #6d28d9; }

        .im-section-icon svg { width: 18px; height: 18px; }

        .im-section-title {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
        }

        .im-section-subtitle {
            font-size: 12px;
            color: #6b7280;
            margin-left: auto;
        }

        /* Print button in section header */
        .im-print-btn {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: #fff;
            border: 1.5px solid #d1d5db;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            text-decoration: none;
            cursor: pointer;
            transition: all .15s;
        }

        .im-print-btn:hover { background: #f3f4f6; border-color: #9ca3af; }
        .im-print-btn svg { width: 14px; height: 14px; }

        /* ── Table Wrapper ────────────────────────────── */
        .im-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* ── Tables ───────────────────────────────────── */
        .im-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .im-table thead th {
            background: #f8f9fb;
            color: #4b5563;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 10px 12px;
            border-bottom: 1.5px solid #e2e5ea;
            white-space: nowrap;
            text-align: left;
        }

        .im-table thead th.r { text-align: right; }
        .im-table thead th.c { text-align: center; }

        .im-table tbody td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f3f7;
            color: #374151;
            vertical-align: middle;
        }

        .im-table tbody td.r { text-align: right; font-family: 'IBM Plex Mono', monospace; }
        .im-table tbody td.c { text-align: center; }
        .im-table tbody td.mono { font-family: 'IBM Plex Mono', monospace; }

        .im-table tbody tr:last-child td { border-bottom: none; }
        .im-table tbody tr:hover td { background: #f8f9fb; }

        /* Highlighted row (searched invoice match) */
        .im-table tbody tr.im-highlight td {
            background: #d1fae5 !important;
            font-weight: 500;
        }

        /* ── Badges ───────────────────────────────────── */
        .im-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .im-badge--remitted    { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .im-badge--unremitted  { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .im-badge--received    { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .im-badge--no          { background: #f3f4f6; color: #6b7280; border: 1px solid #d1d5db; }

        /* AR status badges */
        .im-badge--s1 { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .im-badge--s2 { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
        .im-badge--s3 { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .im-badge--s4 { background: #dcfce7; color: #166534; border: 1px solid #4ade80; }
        .im-badge--s5 { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }

        /* ── Info link (deduction / add-less links) ───── */
        .im-link {
            color: #2563eb;
            text-decoration: none;
            font-family: 'IBM Plex Mono', monospace;
            border-bottom: 1px dashed #93c5fd;
            transition: color .12s;
        }

        .im-link:hover { color: #1d4ed8; }

        /* ── Empty State ──────────────────────────────── */
        .im-empty {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }

        .im-empty-icon {
            width: 44px; height: 44px;
            margin: 0 auto 12px;
            opacity: .4;
        }

        .im-empty p {
            font-size: 14px;
            font-weight: 500;
        }

        .im-empty span {
            font-size: 12px;
            display: block;
            margin-top: 4px;
        }

        /* ── Step/Flow indicator ──────────────────────── */
        .im-flow {
            display: flex;
            align-items: center;
            gap: 0;
            padding: 14px 22px;
            border-bottom: 1.5px solid #e2e5ea;
            background: #fafafa;
            flex-wrap: wrap;
            gap: 4px;
        }

        .im-flow-step {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            border: 1.5px solid transparent;
        }

        .im-flow-step--active { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
        .im-flow-step--done   { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .im-flow-step--idle   { background: #f9fafb; border-color: #e5e7eb; color: #9ca3af; }

        .im-flow-arrow { color: #d1d5db; font-size: 12px; }

        .im-flow-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        /* ── Legend tip ───────────────────────────────── */
        .im-legend {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 22px;
            border-bottom: 1px solid #f1f3f7;
            background: #fafafa;
            flex-wrap: wrap;
        }

        .im-legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #6b7280;
        }

        .im-legend-dot {
            width: 10px; height: 10px;
            border-radius: 3px;
        }

        /* ── Help tip for oldies ──────────────────────── */
        .im-tip {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 22px;
            background: #fffbeb;
            border-bottom: 1px solid #fde68a;
            font-size: 12px;
            color: #78350f;
        }

        .im-tip svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

        /* ── Amount colors ────────────────────────────── */
        .amt-pos { color: #15803d; font-weight: 600; }
        .amt-neg { color: #dc2626; font-weight: 600; }
        .amt-zero { color: #9ca3af; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 640px) {
            .im-page { padding: 16px 12px 40px; }
            .im-search-card { flex-direction: column; align-items: stretch; }
            .im-search-form { width: 100%; }
        }
    </style>
</head>

<body>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
?>

    <div class="content">
        <div class="im-page">
        <?php if (empty($Department)): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;">
            ⚠️ DEBUG: <strong>$Department is empty.</strong> Session keys available: <?php echo implode(', ', array_keys($_SESSION)); ?>
        </div>
        <?php endif; ?>

            <!-- ── Page Header ───────────────────────────────── -->
            <div class="im-page-header">
                <div>
                    <div class="im-dept-label">
                        <?php echo htmlspecialchars($Department); ?> &nbsp;· Invoice Monitoring
                    </div>
                    <h1 class="im-page-title">
                        Invoice <span>Monitoring</span>
                    </h1>
                </div>
                <?php if ($InvoiceNo !== null): ?>
                    <div class="im-search-active">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Showing results for Invoice: <?php echo htmlspecialchars($InvoiceNo); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Search Card ───────────────────────────────── -->
            <div class="im-search-card">
                <div class="im-search-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10,9 9,9 8,9"/></svg>
                    Search by Invoice Number
                </div>
                <form method="post" action="invoice_monitoring.php" class="im-search-form">
                    <input
                        type="text"
                        class="im-search-input"
                        placeholder="Type invoice number and press Search…"
                        name="Invoice"
                        autofocus
                        value="<?php echo $InvoiceNo !== null ? htmlspecialchars($InvoiceNo) : ''; ?>"
                    >
                    <button type="submit" class="im-search-btn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Search
                    </button>
                </form>
            </div>

            <?php if ($InvoiceNo !== null):

                // Fetch all results into arrays first, so connection state doesn't affect row counts
                $sqli = "SELECT * FROM View_Total_Invoice_Amount Where InvoiceNo='" . $InvoiceNo . "' Order By DocNo";
                $stmti = sqlsrv_query($conn, $sqli);
                $rowsi = [];
                while ($r = sqlsrv_fetch_array($stmti, SQLSRV_FETCH_ASSOC)) { $rowsi[] = $r; }
                sqlsrv_free_stmt($stmti);

                $sqld1 = "SELECT * FROM View_RemittanceCollectionSlip Where InvoiceNo='" . $InvoiceNo . "'";
                $stmtd1 = sqlsrv_query($conn, $sqld1);
                $rowsd1 = [];
                while ($r = sqlsrv_fetch_array($stmtd1, SQLSRV_FETCH_ASSOC)) { $rowsd1[] = $r; }
                sqlsrv_free_stmt($stmtd1);

                $sqlARC = "SELECT * FROM View_ARInvoices Where InvoiceNo='" . $InvoiceNo . "' ORDER BY ARCollectionNo";
                $stmtARC = sqlsrv_query($conn, $sqlARC, [], ['Scrollable' => 'buffered']);
                $rowsARC = [];
                while ($r = sqlsrv_fetch_array($stmtARC, SQLSRV_FETCH_ASSOC)) { $rowsARC[] = $r; }
                sqlsrv_free_stmt($stmtARC);

                $sqlcs = "SELECT * FROM View_ARForCollectionDetails WHERE InvoiceNo ='" . $InvoiceNo . "' ORDER BY ARCollectionNo";
                $stmtcs = sqlsrv_query($conn, $sqlcs, [], ['Scrollable' => 'buffered']);
                $rowscs = [];
                while ($r = sqlsrv_fetch_array($stmtcs, SQLSRV_FETCH_ASSOC)) { $rowscs[] = $r; }
                sqlsrv_free_stmt($stmtcs);

                $ARFCNo = $rowscs[0]['ARForCollectionID'] ?? null;

            ?>

            <!-- ── HOW TO READ indicator ─────────────────────── -->
            <div class="im-section" style="margin-bottom:20px;">
                <div class="im-tip">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>
                        <strong>How to read this page:</strong> The tables below show the full process of Invoice <strong><?php echo htmlspecialchars($InvoiceNo); ?></strong> — from creation, through delivery, AR collection, and final received. <em>Green highlighted rows</em> are the exact matched invoice.
                    </span>
                </div>
                <?php
                $step1 = count($rowsi) > 0;
                $step2 = count($rowsd1) > 0;
                $step3 = count($rowsARC) > 0;
                $step4 = count($rowscs) > 0;
                function flowClass($done) { return $done ? 'im-flow-step--done' : 'im-flow-step--idle'; }
                ?>
                <div class="im-flow">
                    <div class="im-flow-step <?php echo flowClass($step1); ?>">
                        <div class="im-flow-dot"></div> 1. Invoice Created
                    </div>
                    <span class="im-flow-arrow">›</span>
                    <div class="im-flow-step <?php echo flowClass($step2); ?>">
                        <div class="im-flow-dot"></div> 2. Delivery
                    </div>
                    <span class="im-flow-arrow">›</span>
                    <div class="im-flow-step <?php echo flowClass($step3); ?>">
                        <div class="im-flow-dot"></div> 3. AR Created
                    </div>
                    <span class="im-flow-arrow">›</span>
                    <div class="im-flow-step <?php echo flowClass($step4); ?>">
                        <div class="im-flow-dot"></div> 4. AR Collection
                    </div>
                </div>
            </div>

            <?php if ($step1): $summary = $rowsi[0]; ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
                <div style="background:#fff;border:1.5px solid #e2e5ea;border-radius:12px;padding:14px 20px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px;">Customer</div>
                    <div style="font-size:15px;font-weight:600;color:#111827;"><?php echo htmlspecialchars(utf8_encode($summary['Customer'])); ?></div>
                </div>
                <div style="background:#fff;border:1.5px solid #e2e5ea;border-radius:12px;padding:14px 20px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px;">Invoice Amount</div>
                    <div style="font-size:15px;font-weight:600;color:#15803d;">₱<?php echo number_format($summary['GrossAmount'], 2); ?></div>
                </div>
                <div style="background:#fff;border:1.5px solid #e2e5ea;border-radius:12px;padding:14px 20px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px;">Department</div>
                    <div style="font-size:15px;font-weight:600;color:#111827;"><?php echo htmlspecialchars($summary['Department']); ?></div>
                </div>
                <div style="background:#fff;border:1.5px solid #e2e5ea;border-radius:12px;padding:14px 20px;flex:1;min-width:160px;box-shadow:0 1px 4px rgba(0,0,0,.04);">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:4px;">Invoice Date</div>
                    <div style="font-size:15px;font-weight:600;color:#111827;"><?php echo $summary['InvoiceDate'] instanceof DateTime ? $summary['InvoiceDate']->format('M d, Y') : '—'; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 SECTION 1 — Invoice Created Details
                 ══════════════════════════════════════════════════ -->
            <div class="im-section im-section--invoice">
                <div class="im-section-header">
                    <div class="im-section-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
                    </div>
                    <div>
                        <div class="im-section-title">Invoice Created Details</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">Basic invoice information at creation</div>
                    </div>
                </div>
                <div class="im-legend">
                    <span style="font-size:11px;color:#6b7280;font-weight:600;">Status:</span>
                    <span class="im-legend-item"><span class="im-legend-dot" style="background:#bbf7d0"></span> Remitted = payment turned in</span>
                    <span class="im-legend-item"><span class="im-legend-dot" style="background:#fca5a5"></span> Unremitted = payment not yet turned in</span>
                </div>
                <div class="im-table-wrap">
                    <table class="im-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Doc No</th>
                                <th>Doc Date</th>
                                <th>Branch</th>
                                <th>Salesman</th>
                                <th>Code</th>
                                <th>Area</th>
                                <th>Inv. No</th>
                                <th>Inv. Date</th>
                                <th>Customer</th>
                                <th class="r">Amount (₱)</th>
                                <th class="c">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasRows = false;
                            foreach ($rowsi as $rowi) {
                                $hasRows = true;
                                $isRemitted = ($rowi['RemittedID'] != 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rowi['Department']); ?></td>
                                <td class="mono"><?php echo htmlspecialchars($rowi['DocNo']); ?></td>
                                <td class="mono"><?php echo $rowi['DocDate']->format('m/d/y'); ?></td>
                                <td><?php echo htmlspecialchars($rowi['Branch']); ?></td>
                                <td><?php echo htmlspecialchars(utf8_encode($rowi['Salesman'])); ?></td>
                                <td class="mono"><?php echo htmlspecialchars(utf8_encode($rowi['SalesmanCode'])); ?></td>
                                <td><?php echo htmlspecialchars(utf8_encode($rowi['Area'])); ?></td>
                                <td class="mono"><strong><?php echo htmlspecialchars(utf8_encode($rowi['InvoiceNo'])); ?></strong></td>
                                <td class="mono"><?php echo $rowi['InvoiceDate']->format('m/d/y'); ?></td>
                                <td><?php echo htmlspecialchars(utf8_encode($rowi['Customer'])); ?></td>
                                <td class="r amt-pos"><?php echo number_format($rowi['GrossAmount'], 2); ?></td>
                                <td class="c">
                                    <?php if ($isRemitted): ?>
                                        <span class="im-badge im-badge--remitted">&#10003; Remitted</span>
                                    <?php else: ?>
                                        <span class="im-badge im-badge--unremitted">&#9679; Unremitted</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if (!$hasRows): ?>
                            <tr>
                                <td colspan="12">
                                    <div class="im-empty">
                                        <svg class="im-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                                        <p>No invoice records found</p>
                                        <span>Try searching with a different invoice number</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 2 — Delivery Details
                 ══════════════════════════════════════════════════ -->
            <div class="im-section im-section--delivery">
                <div class="im-section-header">
                    <div class="im-section-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    </div>
                    <div>
                        <div class="im-section-title">Delivery Details</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">Payment breakdown per delivery — cash, check, credit</div>
                    </div>
                </div>
                <div class="im-legend">
                    <span style="font-size:11px;color:#6b7280;font-weight:600;">Legend:</span>
                    <span class="im-legend-item"><span class="im-legend-dot" style="background:#bbf7d0;border:1px solid #4ade80;"></span> Green row = matched invoice</span>
                    <span class="im-legend-item" style="color:#6b7280;font-size:11px;">B.O./RGS = Manual deduction &nbsp;|&nbsp; +/- = Adjustment </span>
                </div>
                <div class="im-table-wrap">
                    <table class="im-table">
                        <thead>
                            <tr>
                                <th>Doc No</th>
                                <th>Inv. No</th>
                                <th>Customer</th>
                                <th class="r">Amount (₱)</th>
                                <th>Terms</th>
                                <th class="r">Credit (₱)</th>
                                <th class="r">Cash (₱)</th>
                                <th>Bank</th>
                                <th>Chk. No</th>
                                <th>Chk. Date</th>
                                <th class="r">Chk. Amount (₱)</th>
                                <th class="r">B.O./RGS (₱)</th>
                                <th class="r">+/- (₱)</th>
                                <th class="r">Total Paid (₱)</th>
                                <th>Notes</th>
                                <th class="r">Cancelled (₱)</th>
                                <th class="c">Received</th>
                                <th>Remit Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasRows2 = false;
                            foreach ($rowsd1 as $rowd1) {
                                $hasRows2 = true;
                                $isMatch = ($rowd1['InvoiceNo'] == $InvoiceNo);
                                $isReceived = ($rowd1['RRID'] != '0');
                            ?>
                            <tr class="<?php echo $isMatch ? 'im-highlight' : ''; ?>">
                                <td class="mono"><?php echo htmlspecialchars($rowd1['DocNo']); ?></td>
                                <td class="mono"><strong><?php echo htmlspecialchars($rowd1['InvoiceNo']); ?></strong></td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars(utf8_encode($rowd1['Customer'])); ?></td>
                                <td class="r amt-pos"><?php echo number_format($rowd1['NetAmount'], 2); ?></td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($rowd1['Remarks']); ?></td>
                                <td class="r"><?php echo number_format($rowd1['CreditAmount'], 2); ?></td>
                                <td class="r"><?php echo number_format($rowd1['CashAmount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($rowd1['Bank']); ?></td>
                                <td class="mono"><?php echo htmlspecialchars($rowd1['CheckNo']); ?></td>
                                <td class="mono">
                                    <?php if (isset($rowd1['CheckDate'])) echo $rowd1['CheckDate']->format('m/d/y'); ?>
                                </td>
                                <td class="r"><?php echo number_format($rowd1['CheckAmount'], 2); ?></td>
                                <td class="r"><?php echo number_format($rowd1['ManualLess'], 2); ?></td>
                                <td class="r">
                                    <a class="im-link" href="<?php echo 'report_delivery_collection_addless.php?id=' . $rowd1['InvoiceID']; ?>">
                                        <?php echo number_format($rowd1['AddLess'], 2); ?>
                                    </a>
                                </td>
                                <td class="r amt-pos"><?php echo number_format($rowd1['TotalPaid'], 2); ?></td>
                                <td><?php echo htmlspecialchars($rowd1['Note']); ?></td>
                                <td class="r">
                                    <a class="im-link" style="color:#dc2626;border-color:#fca5a5;" href="<?php echo 'report_delivery_collection_cancel_item.php?id=' . $rowd1['InvoiceID']; ?>">
                                        <?php echo number_format($rowd1['AdjustmentAmount'], 2); ?>
                                    </a>
                                </td>
                                <td class="c">
                                    <?php if ($isReceived): ?>
                                        <span class="im-badge im-badge--received">Yes</span>
                                    <?php else: ?>
                                        <span class="im-badge im-badge--no">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono">
                                    <?php echo isset($rowd1['DocDate']) ? $rowd1['DocDate']->format('m/d/y') : '—'; ?>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if (!$hasRows2): ?>
                            <tr>
                                <td colspan="17">
                                    <div class="im-empty">
                                        <svg class="im-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="15" height="13"/><polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                        <p>No delivery records found</p>
                                        <span>No delivery has been recorded for this invoice yet</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 3 — AR Created Details
                 ══════════════════════════════════════════════════ -->
            <div class="im-section im-section--ar-created">
                <div class="im-section-header">
                    <div class="im-section-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                    </div>
                    <div>
                        <div class="im-section-title">AR Created Details</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">Accounts Receivable collection slip status</div>
                    </div>
                </div>
                <div class="im-legend">
                    <span style="font-size:11px;color:#6b7280;font-weight:600;">AR Status guide:</span>
                    <span class="im-badge im-badge--s1" style="font-size:10px">For Summary</span>
                    <span class="im-badge im-badge--s2" style="font-size:10px">To Remit</span>
                    <span class="im-badge im-badge--s3" style="font-size:10px">Unreceived</span>
                    <span class="im-badge im-badge--s4" style="font-size:10px">Received</span>
                    <span class="im-badge im-badge--s5" style="font-size:10px">Uncollected</span>
                </div>
                <div class="im-table-wrap">
                    <table class="im-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>AR No</th>
                                <th>Collection Date</th>
                                <th>Salesman</th>
                                <th>Area</th>
                                <th>Inv. No</th>
                                <th>Inv. Date</th>
                                <th>Customer</th>
                                <th class="r">Amount (₱)</th>
                                <th class="c">AR Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasRows3 = false;
                            foreach ($rowsARC as $rowARC) {
                                $hasRows3 = true;
                                $arStatus = (int)$rowARC['Status'];
                                $statusMap = [
                                    1 => ['label' => 'For Summary',  'class' => 'im-badge--s1'],
                                    2 => ['label' => 'To Remit',     'class' => 'im-badge--s2'],
                                    3 => ['label' => 'Unreceived',   'class' => 'im-badge--s3'],
                                    4 => ['label' => 'Received',     'class' => 'im-badge--s4'],
                                    5 => ['label' => 'Uncollected',  'class' => 'im-badge--s5'],
                                ];
                                $st = $statusMap[$arStatus] ?? ['label' => 'Unknown', 'class' => ''];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rowARC['Department']); ?></td>
                                <td class="mono"><strong><?php echo htmlspecialchars($rowARC['ARCollectionNo']); ?></strong></td>
                                <td class="mono"><?php echo isset($rowARC['DateCollection']) && $rowARC['DateCollection'] instanceof DateTime ? $rowARC['DateCollection']->format('m/d/y') : '—'; ?></td>
                                <td><?php echo htmlspecialchars($rowARC['Salesman']); ?></td>
                                <td><?php echo htmlspecialchars(utf8_encode($rowARC['Area'])); ?></td>
                                <td class="mono"><?php echo htmlspecialchars(utf8_encode($rowARC['InvoiceNo'])); ?></td>
                                <td class="mono"><?php echo isset($rowARC['InvoiceDate']) && $rowARC['InvoiceDate'] instanceof DateTime ? $rowARC['InvoiceDate']->format('m/d/y') : '—'; ?></td>
                                <td><?php echo htmlspecialchars(utf8_encode($rowARC['CustomerName'])); ?></td>
                                <td class="r amt-pos"><?php echo number_format($rowARC['InvoiceAmount'], 2); ?></td>
                                <td class="c">
                                    <span class="im-badge <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if (!$hasRows3): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="im-empty">
                                        <svg class="im-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                                        <p>No AR records found</p>
                                        <span>No accounts receivable slip has been created for this invoice</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>

                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 SECTION 4 — AR Collection Details
                 ══════════════════════════════════════════════════ -->
            <div class="im-section im-section--ar-collection">
                <div class="im-section-header">
                    <div class="im-section-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <div class="im-section-title">AR Collection Details</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">Full payment breakdown — check, cash, deductions, and balance</div>
                    </div>
                
                </div>
                <div class="im-legend">
                    <span style="font-size:11px;color:#6b7280;font-weight:600;">Legend:</span>
                    <span class="im-legend-item"><span class="im-legend-dot" style="background:#bbf7d0;border:1px solid #4ade80;"></span> Green row = matched invoice</span>
                    
                </div>
                <div class="im-table-wrap">
                    <table class="im-table">
                        <thead>
                            <tr>
                                <th>AFC</th>
                                <th>AR No</th>
                                <th>Inv. No</th>
                                <th>Del. Date</th>
                                <th>Remarks</th>
                                <th>Customer</th>
                                <th class="r">Inv. Amount (₱)</th>
                                <th class="r">Total (₱)</th>
                                <th class="r">Deduction (₱)</th>
                                <th>Bank</th>
                                <th>Check No</th>
                                <th>Chk. Date</th>
                                <th class="r">Chk. Amount (₱)</th>
                                <th class="r">Cash (₱)</th>
                                <th class="r">Balance (₱)</th>
                                <th>Terms</th>
                                <th class="c">Received</th>
                                <th>Remit Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $hasRows4 = false;
                            foreach ($rowscs as $rowcs) {
                                $hasRows4 = true;
                                $isMatch = ($rowcs['InvoiceNo'] == $InvoiceNo);
                                $isReceived = ($rowcs['RRID'] != '0');
                                $balance = (float)$rowcs['Balance'];
                            ?>
                            <tr class="<?php echo $isMatch ? 'im-highlight' : ''; ?>">
                                <td class="mono"><?php echo htmlspecialchars($rowcs['ARForCollectionID']); ?></td>
                                <td class="mono"><strong><?php echo htmlspecialchars($rowcs['ARCollectionNo']); ?></strong></td>
                                <td class="mono"><?php echo htmlspecialchars($rowcs['InvoiceNo']); ?></td>
                                <td class="mono" style="white-space:nowrap"><?php echo isset($rowcs['DeliveryDate']) && $rowcs['DeliveryDate'] instanceof DateTime ? $rowcs['DeliveryDate']->format('m-d-y') : '—'; ?></td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($rowcs['ARRemarks']); ?></td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($rowcs['CustomerName']); ?></td>
                                <td class="r amt-pos"><?php echo number_format($rowcs['InvoiceAmount'], 2); ?></td>
                                <td class="r">
                                    <?php echo isset($rowcs['TotalAmount']) ? number_format($rowcs['TotalAmount'], 2) : '—'; ?>
                                </td>
                                <td class="r">
                                    <a class="im-link" href="<?php echo 'invoice_monitoring_ar_deduction.php?InvoiceID=' . $rowcs['DocNo']; ?>">
                                        <?php echo ($rowcs['Deduction'] == 0) ? '—' : number_format($rowcs['Deduction'], 2); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($rowcs['Bank']); ?></td>
                                <td class="mono"><?php echo htmlspecialchars($rowcs['CheckNo']); ?></td>
                                <td class="mono">
                                    <?php if (isset($rowcs['Bank']) && isset($rowcs['CheckDate'])) echo $rowcs['CheckDate']->format('m-d-y'); ?>
                                </td>
                                <td class="r">
                                    <?php echo ($rowcs['CheckAmount'] == 0) ? '<span class="amt-zero">—</span>' : number_format($rowcs['CheckAmount'], 2); ?>
                                </td>
                                <td class="r">
                                    <?php echo ($rowcs['Cash'] == 0) ? '<span class="amt-zero">—</span>' : number_format($rowcs['Cash'], 2); ?>
                                </td>
                                <td class="r <?php echo $balance > 0 ? 'amt-neg' : 'amt-zero'; ?>">
                                    <?php echo ($balance == 0) ? '—' : number_format($balance, 2); ?>
                                </td>
                                <td style="white-space:nowrap"><?php echo htmlspecialchars($rowcs['Terms']); ?></td>
                                <td class="c">
                                    <?php if ($isReceived): ?>
                                        <span class="im-badge im-badge--received">Yes</span>
                                    <?php else: ?>
                                        <span class="im-badge im-badge--no">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono">
                                    <?php 
                                    $remitVal = $rowcs['DateAndTimeInput'] ?? null;
                                    echo ($remitVal instanceof DateTime) ? $remitVal->format('m/d/y') : '—'; 
                                    ?>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if (!$hasRows4): ?>
                            <tr>
                                <td colspan="17">
                                    <div class="im-empty">
                                        <svg class="im-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                        <p>No AR collection records found</p>
                                        <span>This invoice has not been included in an AR collection slip yet</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                        </tbody>
                    </table>
                </div>
            </div>

            <?php else: ?>
            <!-- ── No search yet — Landing state ─────────────────── -->
            <div class="im-section" style="border:2px dashed #d1d5db; background:transparent; box-shadow:none;">
                <div class="im-empty" style="padding:56px 20px;">
                    <svg class="im-empty-icon" style="width:56px;height:56px;opacity:.25;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <p style="font-size:16px;font-weight:600;color:#374151;">Enter an Invoice Number to get started</p>
                    <span style="font-size:13px;color:#9ca3af;margin-top:6px;">Type the invoice number in the search box above and click <strong>Search</strong>.</span>
                    <span style="font-size:13px;color:#9ca3af;margin-top:4px;">You will see all related delivery, payment, and collection details here.</span>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.im-page -->
    </div><!-- /.content -->
</body>
</html>