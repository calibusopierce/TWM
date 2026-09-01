<?php
// TWM/BULLETIN/bulletin_list.php
date_default_timezone_set('Asia/Manila');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'bulletin'); // read-only list — no viewOnly restrictions needed, nothing here is editable

$filterFrom = trim($_GET['filter_from'] ?? '');
$filterTo   = trim($_GET['filter_to'] ?? '');

$sql    = "SELECT BulletinID, Title, StartDate, EndDate, CreatedByName, CreatedAt
            FROM TBL_Bulletin
            WHERE IsActive = 0";
$params = [];
if ($filterFrom !== '') { $sql .= " AND CAST(CreatedAt AS DATE) >= ?"; $params[] = $filterFrom; }
if ($filterTo   !== '') { $sql .= " AND CAST(CreatedAt AS DATE) <= ?"; $params[] = $filterTo; }
$sql .= " ORDER BY CreatedAt DESC";

$bulletins = [];
$error = '';
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $bulletins[] = $row;
    }
} else {
    $error = 'Database error: ' . print_r(sqlsrv_errors(), true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Past Announcements · Tradewell Admin</title>
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <style>
    :root { --blue-deep:#08173d; --blue-bright:#4380e2; --blue-light:#93c5fd; --white:#fff;
            --w10:rgba(255,255,255,.10); --w15:rgba(255,255,255,.15); --w25:rgba(255,255,255,.25);
            --w60:rgba(255,255,255,.60); --w80:rgba(255,255,255,.80); }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:linear-gradient(145deg,var(--blue-bright) 0%,var(--blue-deep) 100%);
         background-attachment:fixed;min-height:100vh;padding:2rem;color:var(--white);}
    .wrap{max-width:900px;margin:0 auto;}
    a.back{color:var(--blue-light);font-size:.85rem;font-weight:600;text-decoration:none;display:inline-block;margin-bottom:1.2rem;}
    a.back:hover{text-decoration:underline;}
    h1{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:800;margin-bottom:1.2rem;display:flex;align-items:center;gap:.5rem;}
    .card{background:rgba(4,10,28,.55);border:1px solid var(--w25);border-radius:14px;
          padding:1.3rem;margin-bottom:1.3rem;box-shadow:0 8px 24px rgba(0,0,0,.2);}
    label{display:block;font-size:.8rem;font-weight:600;margin:.8rem 0 .35rem;color:var(--white);}
    input[type=date]{padding:.65rem .8rem;border-radius:8px;border:1px solid var(--w25);
      background:rgba(255,255,255,.14);color:var(--white);font-family:inherit;font-size:.9rem;color-scheme:dark;}
    input[type=date]:focus{outline:none;border-color:var(--blue-light);background:rgba(255,255,255,.18);
      box-shadow:0 0 0 3px rgba(147,197,253,.25);}
    .filter-row{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;}
    button{padding:.65rem 1.2rem;border:none;border-radius:8px;
           background:var(--blue-bright);color:#fff;font-weight:700;font-size:.87rem;cursor:pointer;}
    button:hover{background:#5590f0;}
    .clear-filter{color:var(--blue-light);font-size:.8rem;font-weight:600;text-decoration:none;margin-left:.2rem;}
    .clear-filter:hover{text-decoration:underline;}
    .msg-err{background:rgba(239,68,68,.22);border:1px solid rgba(239,68,68,.5);padding:.65rem .9rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600;}
    table{width:100%;border-collapse:collapse;font-size:.85rem;}
    th,td{text-align:left;padding:.7rem .5rem;border-bottom:1px solid var(--w15);vertical-align:top;}
    th{color:var(--w80);font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;}
    td{color:var(--w60);}
    .badge{display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;background:var(--w15);color:var(--w80);}
  </style>
</head>
<body>
<div class="wrap">
  <a href="bulletin_manage.php" class="back">&larr; Back to Bulletin Board</a>
  <h1><i class="bi bi-archive"></i> Past Announcements</h1>

  <?php if ($error): ?><div class="msg-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <form method="get" class="filter-row">
      <div>
        <label>From</label>
        <input type="date" name="filter_from" value="<?= htmlspecialchars($filterFrom) ?>">
      </div>
      <div>
        <label>To</label>
        <input type="date" name="filter_to" value="<?= htmlspecialchars($filterTo) ?>">
      </div>
      <button type="submit"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($filterFrom !== '' || $filterTo !== ''): ?>
        <a href="bulletin_list.php" class="clear-filter">Clear</a>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr><th>Title</th><th>Viewing Window</th><th>Posted By</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($bulletins as $b): ?>
        <tr>
          <td><?= htmlspecialchars($b['Title']) ?></td>
          <td><?= htmlspecialchars($b['StartDate']->format('M j, Y')) ?> &ndash; <?= htmlspecialchars($b['EndDate']->format('M j, Y')) ?></td>
          <td><?= htmlspecialchars($b['CreatedByName'] ?? 'Unknown') ?></td>
          <td><span class="badge">Removed</span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$bulletins): ?>
        <tr><td colspan="4">No past announcements found<?= ($filterFrom !== '' || $filterTo !== '') ? ' for this date range.' : '.' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>