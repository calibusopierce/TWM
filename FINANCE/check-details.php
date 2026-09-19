<?php
/**
 * check-details.php
 *
 * Renders a single row from dbo.Cheques as a ledger-styled, click-to-copy
 * details card. Drop this into TWM alongside your other pages and adjust
 * the include chain below to match your existing setup.
 *
 * Usage: check-details.php?id=549358   (TransactionID)
 */

ob_start();

// --- Standard TWM include chain (matches deduction_records.php) -----------
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../test_sqlsrv.php';
auth_check();

$row = null;
$error = null;

// TransactionID is an int column, so strip anything that isn't a digit —
// this also protects against a malformed value like "?id=549694" coming
// in from a link that double-appended the query string.
$rawId = isset($_GET['id']) ? trim($_GET['id']) : null;
$transactionId = ($rawId !== null) ? preg_replace('/\D+/', '', $rawId) : null;

if ($transactionId !== null && $transactionId !== '' && isset($pdo)) {
    $sql = "SELECT TransactionID, Department, Outlet, Salesman, Area, InvoiceNumber,
                   CONVERT(varchar(10), InvoiceDate, 23) AS InvoiceDate,
                   Bank, Branch, CheckNumber,
                   CONVERT(varchar(10), CheckDate, 23) AS CheckDate,
                   Amount, Terms,
                   CONVERT(varchar(19), [DateTime], 120) AS [DateTime]
            FROM dbo.Cheques
            WHERE TransactionID = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$transactionId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        $row = $fetched ?: null;
        if (!$row) {
            $error = "No check found for TransactionID {$transactionId}.";
        }
    } catch (PDOException $e) {
        $error = 'Could not load this record — the check ID may be invalid.';
        $row = null;
    }
} elseif ($rawId !== null && $rawId !== '' && $transactionId === '') {
    $error = "\"{$rawId}\" isn't a valid check ID.";
}

// Fallback sample data so the page still renders for layout/testing
// when there's no $conn or no matching row (e.g. viewed standalone).
if (!$row) {
    $row = array(
        'TransactionID' => '549358',
        'Department'    => 'MULTI',
        'Outlet'        => 'VICTORINA',
        'Salesman'      => 'VISOR MULTI',
        'Area'          => 'LOPEZ',
        'InvoiceNumber' => '3906989',
        'InvoiceDate'   => new DateTime('2026-08-29'),
        'Bank'          => 'MBTC',
        'Branch'        => 'GUMACA BRANCH',
        'CheckNumber'   => '30530',
        'CheckDate'     => new DateTime('2026-09-17'),
        'Amount'        => 61243.00,
        'Terms'         => '20',
        'DateTime'      => new DateTime('2026-09-05 10:09:53'),
    );
}

// --- Formatting helpers -----------------------------------------------------
function ckd_fmt_date($val) {
    if ($val instanceof DateTime) return $val->format('m/d/Y');
    if (empty($val)) return '';
    $ts = strtotime($val);
    return $ts ? date('m/d/Y', $ts) : (string) $val;
}
function ckd_fmt_datetime($val) {
    if ($val instanceof DateTime) return $val->format('m/d/Y h:i:s A');
    if (empty($val)) return '';
    $ts = strtotime($val);
    return $ts ? date('m/d/Y h:i:s A', $ts) : (string) $val;
}
function ckd_fmt_amount($val) {
    return number_format((float) $val, 2);
}
function ckd_esc($val) {
    return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
}

$transactionId = ckd_esc($row['TransactionID']);
$department    = ckd_esc($row['Department']);
$outlet        = ckd_esc($row['Outlet']);
$salesman      = ckd_esc($row['Salesman']);
$area          = ckd_esc($row['Area']);
$invoiceNumber = ckd_esc($row['InvoiceNumber']);
$invoiceDate   = ckd_esc(ckd_fmt_date($row['InvoiceDate']));
$bank          = ckd_esc($row['Bank']);
$branch        = ckd_esc($row['Branch']);
$checkNumber   = ckd_esc($row['CheckNumber']);
$checkDate     = ckd_esc(ckd_fmt_date($row['CheckDate']));
$amount        = ckd_esc(ckd_fmt_amount($row['Amount']));
$terms         = ckd_esc($row['Terms']);
$dateTimeLog   = ckd_esc(ckd_fmt_datetime($row['DateTime']));

// Legacy-style labels, in the same order as the values below — used so
// "Copy all" pastes as "Label: value" lines, matching the old report page.
$rowHeadersJs = json_encode(array(
    'ID', 'Department', 'Customer Name', 'Salesman', 'Area',
    'Invoice No.', 'Invoice Date.', 'Bank', 'Branch', 'Check No.',
    'Check Date.', 'Amount', 'Terms', 'DateTime Input.',
));

// Order matches the SQL SELECT column order, for the "Copy all" row.
$rowValuesJs = json_encode(array(
    $row['TransactionID'],
    $row['Department'],
    $row['Outlet'],
    $row['Salesman'],
    $row['Area'],
    $row['InvoiceNumber'],
    ckd_fmt_date($row['InvoiceDate']),
    $row['Bank'],
    $row['Branch'],
    $row['CheckNumber'],
    ckd_fmt_date($row['CheckDate']),
    ckd_fmt_amount($row['Amount']),
    $row['Terms'],
    ckd_fmt_datetime($row['DateTime']),
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Check Details</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #FFFFFF;
    --surface: #FFFFFF;
    --header: #005BC4;
    --header-ink: #EAF4FF;
    --accent: #2F8FEF;
    --ink: #10233F;
    --muted: #5B7391;
    --border: #D6E4F2;
    --strip-bg: #04264E;
    --strip-ink: #AFCBEA;
    --copied: #0B5BC2;
    --font-display: 'Fraunces', serif;
    --font-body: 'Inter', system-ui, sans-serif;
    --font-mono: 'IBM Plex Mono', ui-monospace, monospace;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    min-height: 100vh;
    background: var(--bg);
    color: var(--ink);
    font-family: var(--font-body);
    display: flex;
    justify-content: center;
    padding: clamp(20px, 5vw, 56px) 16px;
  }

  .page { width: 100%; max-width: 740px; }

  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
  }
    .toolbar p { margin: 0; color: var(--muted); font-size: 0.85rem; }

  .back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--header);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 14px;
  }
  .back-link:hover { text-decoration: underline; }
  .back-link svg { width: 14px; height: 14px; }

  .copy-all {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--header);
    color: var(--header-ink);
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-family: var(--font-body);
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.12s ease, opacity 0.12s ease;
  }
  .copy-all:hover { opacity: 0.92; }
  .copy-all:active { transform: scale(0.97); }
  .copy-all.done { background: var(--copied); }

  .doc {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  }

  .doc-header {
    background: var(--header);
    color: var(--header-ink);
    padding: 26px 28px 22px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
  }
  .doc-header h1 {
    font-family: var(--font-display);
    font-weight: 600;
    font-size: 1.5rem;
    margin: 0 0 4px;
    letter-spacing: 0.2px;
  }
  .doc-header .sub { font-size: 0.82rem; color: rgba(234,244,255,0.7); margin: 0; }

  .amount-box { text-align: right; flex-shrink: 0; }
  .amount-box .label { font-size: 0.72rem; color: rgb(253, 253, 253); margin: 0 0 4px; }
  .amount-box .value {
    font-family: var(--font-mono);
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: 0.3px;
  }

  .micr-strip {
    background: var(--strip-bg);
    color: var(--strip-ink);
    font-family: var(--font-mono);
    font-size: 0.78rem;
    letter-spacing: 1.5px;
    padding: 10px 28px;
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    overflow-x: auto;
  }
  .micr-strip span b { color: var(--strip-ink); font-weight: 600; letter-spacing: 1.5px; }

  .body-grid { padding: 26px 28px 30px; }

  .section { margin-bottom: 22px; }
  .section:last-child { margin-bottom: 0; }
  .section h2 {
    font-family: var(--font-body);
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--muted);
    margin: 0 0 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
    letter-spacing: 0.2px;
  }

  .fields { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px; }

  @media (max-width: 520px) {
    .fields { grid-template-columns: 1fr; }
    .doc-header { flex-direction: column; }
    .amount-box { text-align: left; }
  }

  .field { cursor: pointer; padding: 4px 6px 4px 0; border-radius: 6px; position: relative; }
  .field:hover { background: rgba(214,228,242,0.35); }
  .field .fl { font-size: 0.78rem; color: var(--muted); margin-bottom: 3px; }
  .field .fv { font-size: 0.98rem; font-weight: 500; color: var(--ink); word-break: break-word; }
  .field.mono .fv { font-family: var(--font-mono); font-size: 0.92rem; }
  .field .flag {
    position: absolute; right: 6px; top: 4px;
    font-size: 0.7rem; color: var(--copied);
    opacity: 0; transition: opacity 0.15s ease;
  }
  .field.copied .flag { opacity: 1; }

  .hint { text-align: center; color: var(--muted); font-size: 0.76rem; margin-top: 14px; }

  .error-banner {
    background: #FDECEC;
    border: 1px solid #F3B8B8;
    color: #8A1F1F;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 0.85rem;
    margin-bottom: 14px;
  }
</style>
</head>
<body>

<div class="page">
  <a href="cheques.php" class="back-link">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
    Back to Cheques
  </a>
  <?php if ($error): ?>
  <div class="error-banner"><?= ckd_esc($error) ?> Showing sample data below.</div>
  <?php endif; ?>
  <div class="toolbar">
    <p>Click any value to copy it. Use Copy All for the full record as labeled lines.</p>
    <button class="copy-all" id="copyAllBtn">Copy all</button>
  </div>


  <div class="doc">
    <div class="doc-header">
      <div>
        <h1>Check Details</h1>
        <p class="sub">Cheques record &middot; TransactionID <?= $transactionId ?></p>
      </div>
      <div class="amount-box">
        <p class="label">Amount</p>
        <p class="value">&#8369;<?= $amount ?></p>
      </div>
    </div>

    <div class="micr-strip">
      <span>CHECK <b><?= $checkNumber ?></b></span>
      <span>BANK <b><?= $bank ?></b></span>
      <span>BRANCH <b><?= $branch ?></b></span>
      <span>DATE <b><?= $checkDate ?></b></span>
    </div>

    <div class="body-grid">

      <div class="section">
        <h2>Transaction &amp; invoice</h2>
        <div class="fields">
          <div class="field mono" data-copy="<?= $transactionId ?>">
            <div class="fl">Transaction ID</div>
            <div class="fv"><?= $transactionId ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field mono" data-copy="<?= $invoiceNumber ?>">
            <div class="fl">Invoice number</div>
            <div class="fv"><?= $invoiceNumber ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $invoiceDate ?>">
            <div class="fl">Invoice date</div>
            <div class="fv"><?= $invoiceDate ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $dateTimeLog ?>">
            <div class="fl">Date &amp; time input:</div>
            <div class="fv"><?= $dateTimeLog ?></div>
            <div class="flag">Copied</div>
          </div>
        </div>
      </div>

      <div class="section">
        <h2>Party details</h2>
        <div class="fields">
          <div class="field" data-copy="<?= $department ?>">
            <div class="fl">Department</div>
            <div class="fv"><?= $department ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $outlet ?>">
            <div class="fl">Outlet</div>
            <div class="fv"><?= $outlet ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $salesman ?>">
            <div class="fl">Salesman</div>
            <div class="fv"><?= $salesman ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $area ?>">
            <div class="fl">Area</div>
            <div class="fv"><?= $area ?></div>
            <div class="flag">Copied</div>
          </div>
        </div>
      </div>

      <div class="section">
        <h2>Check &amp; payment</h2>
        <div class="fields">
          <div class="field mono" data-copy="<?= $checkNumber ?>">
            <div class="fl">Check number</div>
            <div class="fv"><?= $checkNumber ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $checkDate ?>">
            <div class="fl">Check date</div>
            <div class="fv"><?= $checkDate ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $bank ?>">
            <div class="fl">Bank</div>
            <div class="fv"><?= $bank ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $branch ?>">
            <div class="fl">Branch</div>
            <div class="fv"><?= $branch ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field mono" data-copy="<?= $amount ?>">
            <div class="fl">Amount</div>
            <div class="fv"><?= $amount ?></div>
            <div class="flag">Copied</div>
          </div>
          <div class="field" data-copy="<?= $terms ?>">
            <div class="fl">Terms</div>
            <div class="fv"><?= $terms ?></div>
            <div class="flag">Copied</div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <p class="hint">Pass <code>?id=TransactionID</code> in the URL to pull a real row from <code>dbo.Cheques</code>.</p>
</div>

<script>
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        return true;
      } catch (e2) {
        return false;
      }
    }
  }

  document.querySelectorAll('.field').forEach(function (el) {
    el.addEventListener('click', async function () {
      const ok = await copyText(el.getAttribute('data-copy'));
      if (ok) {
        el.classList.add('copied');
        setTimeout(function () { el.classList.remove('copied'); }, 1100);
      }
    });
  });

  const rowHeaders = <?= $rowHeadersJs ?>;
  const rowValues = <?= $rowValuesJs ?>;

  const copyAllBtn = document.getElementById('copyAllBtn');
  copyAllBtn.addEventListener('click', async function () {
    const lines = rowHeaders.map(function (label, i) { return label + ': ' + rowValues[i]; });
    const ok = await copyText(lines.join('\n'));
    if (ok) {
      const original = copyAllBtn.textContent;
      copyAllBtn.textContent = 'Copied row \u2713';
      copyAllBtn.classList.add('done');
      setTimeout(function () {
        copyAllBtn.textContent = original;
        copyAllBtn.classList.remove('done');
      }, 1300);
    }
  });
</script>

</body>
</html>
<?php ob_end_flush(); ?>