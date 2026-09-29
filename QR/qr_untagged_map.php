<?php
// TWM/QR/qr_untagged_map.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'qr_untagged_map');

$view_only = rbac_is_view_only('qr_untagged_map');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['qut_csrf'])) $_SESSION['qut_csrf'] = bin2hex(random_bytes(32));

// ---- AJAX: tag a scanned QRCODE to a customer ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tag') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $qr  = trim($_POST['qrcode'] ?? '');
    $cid = (int)($_POST['customer_id'] ?? 0);
    $lat = ($_POST['latitude']  ?? '') !== '' ? $_POST['latitude']  : null;
    $lng = ($_POST['longitude'] ?? '') !== '' ? $_POST['longitude'] : null;

    if ($view_only) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'View-only access.']); exit; }
    if (!hash_equals($_SESSION['qut_csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Session expired, reload the page.']); exit; }
    if ($qr === '' || $cid <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing QR code or customer.']); exit; }

    // Atomic guard: customer still untagged, code not used by anyone else, code still in the untagged view
    // No longer requires the customer to be untagged first — this call overrides
    // whatever QRCODE/Latitude/Longitude the customer already had, if any.
    $sql = "
        UPDATE dbo.Tbl_Customer_Info
        SET QRCODE = ?, Latitude = ?, Longitude = ?
        WHERE ID = ?
          AND NOT EXISTS (SELECT 1 FROM dbo.Tbl_Customer_Info WHERE QRCODE = ? AND ID <> ?)
          AND EXISTS (SELECT 1 FROM dbo.View_QRCode_Untagged WHERE QRCODE = ?)
    ";
    $stmt = sqlsrv_query($conn, $sql, [$qr, $lat, $lng, $cid, $qr, $cid, $qr]);
    if ($stmt === false) {
        error_log('QR tag failed: ' . print_r(sqlsrv_errors(), true));
        echo json_encode(['ok' => false, 'error' => 'Update failed.']);
        exit;
    }
    if (sqlsrv_rows_affected($stmt) < 1) {
        echo json_encode(['ok' => false, 'error' => 'Not tagged. The customer or the QR code was already taken.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---- AJAX: sync an already-tagged (Status=2) pending row into Tbl_Customer_Info ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $qr  = trim($_POST['qrcode'] ?? '');
    $cid = (int)($_POST['customer_id'] ?? 0);
    $lat = ($_POST['latitude']  ?? '') !== '' ? $_POST['latitude']  : null;
    $lng = ($_POST['longitude'] ?? '') !== '' ? $_POST['longitude'] : null;

    if ($view_only) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'View-only access.']); exit; }
    if (!hash_equals($_SESSION['qut_csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Session expired, reload the page.']); exit; }
    if ($qr === '' || $cid <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing QR code or customer.']); exit; }

    sqlsrv_begin_transaction($conn);

    $upd1 = "
        UPDATE dbo.Tbl_Customer_Info
        SET QRCODE = ?, Latitude = ?, Longitude = ?
        WHERE ID = ?
          AND QRCODE IS NULL
          AND NOT EXISTS (SELECT 1 FROM dbo.Tbl_Customer_Info WHERE QRCODE = ?)
    ";
    $s1 = sqlsrv_query($conn, $upd1, [$qr, $lat, $lng, $cid, $qr]);
    if ($s1 === false || sqlsrv_rows_affected($s1) < 1) {
        sqlsrv_rollback($conn);
        if ($s1 === false) error_log('QR sync (customer) failed: ' . print_r(sqlsrv_errors(), true));
        echo json_encode(['ok' => false, 'error' => 'Not synced. Customer already has a QR code, or this code is already taken.']);
        exit;
    }

    $upd2 = "
        UPDATE dbo.TBL_QRCode_Cutomer_Tagging
        SET Status = 3
        WHERE QRCODE = ? AND CustomerID = ? AND Status = 2
    ";
    $s2 = sqlsrv_query($conn, $upd2, [$qr, $cid]);
    if ($s2 === false || sqlsrv_rows_affected($s2) < 1) {
        sqlsrv_rollback($conn);
        if ($s2 === false) error_log('QR sync (tagging status) failed: ' . print_r(sqlsrv_errors(), true));
        echo json_encode(['ok' => false, 'error' => 'Not synced. This tagging record is no longer pending (Status may have changed).']);
        exit;
    }

    sqlsrv_commit($conn);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- AJAX: hard-delete a wrong/duplicate scan row ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_scan') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $id = (int)($_POST['id'] ?? 0);

    if ($view_only) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'View-only access.']); exit; }
    if (!hash_equals($_SESSION['qut_csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Session expired, reload the page.']); exit; }
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing scan ID.']); exit; }

    $stmt = sqlsrv_query($conn, "DELETE FROM dbo.Tbl_QRCode_Scan_Log WHERE ID = ?", [$id]);
    if ($stmt === false) {
        error_log('QR scan delete failed: ' . print_r(sqlsrv_errors(), true));
        echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
        exit;
    }
    if (sqlsrv_rows_affected($stmt) < 1) {
        echo json_encode(['ok' => false, 'error' => 'Scan not found (already deleted?).']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---- AJAX: void a pending tag row (Status=2 -> Status=5), without deleting it ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void_pending') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $qr  = trim($_POST['qrcode'] ?? '');
    $cid = (int)($_POST['customer_id'] ?? 0);

    if ($view_only) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'View-only access.']); exit; }
    if (!hash_equals($_SESSION['qut_csrf'], $_POST['csrf'] ?? '')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Session expired, reload the page.']); exit; }
    if ($qr === '' || $cid <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing QR code or customer.']); exit; }

    $stmt = sqlsrv_query($conn, "
        UPDATE dbo.TBL_QRCode_Cutomer_Tagging
        SET Status = 5
        WHERE QRCODE = ? AND CustomerID = ? AND Status = 2
    ", [$qr, $cid]);
    if ($stmt === false) {
        error_log('QR pending void failed: ' . print_r(sqlsrv_errors(), true));
        echo json_encode(['ok' => false, 'error' => 'Void failed.']);
        exit;
    }
    if (sqlsrv_rows_affected($stmt) < 1) {
        echo json_encode(['ok' => false, 'error' => 'Not voided. This record may no longer be pending.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

$jf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;

// Same lat/lng-swap auto-correction used on the GPS map pages
function qut_fix_coords($lat, $lng) {
    $lat = (float)$lat; $lng = (float)$lng;
    if (abs($lat) > 90 && abs($lng) <= 90) { return [$lng, $lat]; }
    return [$lat, $lng];
}

// ---- Untagged QR scans, grouped per QRCODE (a code can be scanned more than once) ----
// Querying the base tables directly instead of View_QRCode_Untagged, since that view exposes
// Tbl_Customer_Info.ID (always NULL here by design) instead of the scan's own row ID — and we
// need the real scan ID to support per-scan deletion. Same join/filter logic as the view itself.
$qrStmt = sqlsrv_query($conn, "
    SELECT s.ID, s.QRCODE, s.FileNo, s.Latitude, s.Longitude, s.DateTimeInput,
           e.FirstName + ' ' + e.LastName AS EmployeeName
    FROM dbo.Tbl_QRCode_Scan_Log s
    INNER JOIN dbo.TBL_HREmployeeList e ON s.FileNo = e.FileNo
    LEFT OUTER JOIN dbo.Tbl_Customer_Info ci ON s.QRCODE = ci.QRCODE
    WHERE ci.ID IS NULL
    ORDER BY s.QRCODE
");
if ($qrStmt === false) {
    die('Untagged QR query failed: ' . print_r(sqlsrv_errors(), true));
}
$qrGroups = [];
while ($row = sqlsrv_fetch_array($qrStmt, SQLSRV_FETCH_ASSOC)) {
    $code = trim((string)$row['QRCODE']);
    if ($code === '') continue;
    [$lat, $lng] = qut_fix_coords($row['Latitude'], $row['Longitude']);
    $hasGeo = ($lat != 0 && $lng != 0);
    if (!isset($qrGroups[$code])) {
        $qrGroups[$code] = ['code' => $code, 'files' => [], 'lat' => null, 'lng' => null, 'scans' => []];
    }
    $g =& $qrGroups[$code];
    $fn = trim((string)$row['FileNo']);
    if ($fn !== '' && !in_array($fn, $g['files'], true)) $g['files'][] = $fn;
    if ($g['lat'] === null && $hasGeo) { $g['lat'] = $lat; $g['lng'] = $lng; }
    $scanDt = $row['DateTimeInput'];
    if ($scanDt instanceof DateTime) $scanDt = $scanDt->format('Y-m-d H:i');
    $g['scans'][] = [
        'id'       => (int)$row['ID'],
        'employee' => trim((string)$row['EmployeeName']),
        'fileno'   => $fn,
        'lat'      => $hasGeo ? $lat : null,
        'lng'      => $hasGeo ? $lng : null,
        'datetime' => (string)$scanDt,
    ];
    unset($g);
}
$qrList = array_values($qrGroups);

// ---- Customers that still have no QR code (the tag targets) ----
$custStmt = sqlsrv_query($conn, "
    SELECT ID, Code, CustomerName, Address, Barangay, Area, Province,
           ContactNo, ContactPerson, CustomerType, TIN, Status, Remarks,
           Picture, UserInputID, DateTimeInput, Latitude, Longitude
    FROM dbo.Tbl_Customer_Info
    WHERE QRCODE IS NULL
    ORDER BY CustomerName
");
if ($custStmt === false) {
    die('Customer query failed: ' . print_r(sqlsrv_errors(), true));
}
$customers = [];
while ($row = sqlsrv_fetch_array($custStmt, SQLSRV_FETCH_ASSOC)) {
    [$lat, $lng] = qut_fix_coords($row['Latitude'], $row['Longitude']);
    $hasGeo = ($lat != 0 && $lng != 0);
    $pic = '';
    if (!empty($row['Picture'])) {
        $bin = is_resource($row['Picture']) ? stream_get_contents($row['Picture']) : $row['Picture'];
        $pic = ($bin !== false && $bin !== '') ? 'data:image/jpeg;base64,' . base64_encode($bin) : '';
    }
    $dt = $row['DateTimeInput'];
    if ($dt instanceof DateTime) $dt = $dt->format('Y-m-d H:i:s');
    $customers[] = [
        'id'    => (int)$row['ID'],
        'code'  => trim((string)$row['Code']),
        'name'  => trim((string)$row['CustomerName']),
        'addr'  => implode(', ', array_filter([
            trim((string)$row['Address']), trim((string)$row['Barangay']),
            trim((string)$row['Area']), trim((string)$row['Province'])
        ])),
        'cno'   => trim((string)$row['ContactNo']),
        'cper'  => trim((string)$row['ContactPerson']),
        'ctype' => trim((string)$row['CustomerType']),
        'tin'   => trim((string)$row['TIN']),
        'status'=> trim((string)$row['Status']),
        'rmk'   => trim((string)$row['Remarks']),
        'pic'   => $pic,
        'uid'   => (int)$row['UserInputID'],
        'dt'    => (string)$dt,
        'lat'   => $hasGeo ? $lat : null,
        'lng'   => $hasGeo ? $lng : null,
    ];
}

// ---- Customers that already have a QR code (reference layer only, not tag targets) ----
$taggedStmt = sqlsrv_query($conn, "
    SELECT ID, Code, CustomerName, Address, Barangay, Area, Province,
           ContactNo, ContactPerson, CustomerType, TIN, Status, Remarks,
           Picture, UserInputID, DateTimeInput, Latitude, Longitude, QRCODE
    FROM dbo.Tbl_Customer_Info
    WHERE QRCODE IS NOT NULL
    ORDER BY CustomerName
");
if ($taggedStmt === false) {
    die('Tagged customer query failed: ' . print_r(sqlsrv_errors(), true));
}
$taggedCustomers = [];
while ($row = sqlsrv_fetch_array($taggedStmt, SQLSRV_FETCH_ASSOC)) {
    [$lat, $lng] = qut_fix_coords($row['Latitude'], $row['Longitude']);
    $hasGeo = ($lat != 0 && $lng != 0);
    $pic = '';
    if (!empty($row['Picture'])) {
        $bin = is_resource($row['Picture']) ? stream_get_contents($row['Picture']) : $row['Picture'];
        $pic = ($bin !== false && $bin !== '') ? 'data:image/jpeg;base64,' . base64_encode($bin) : '';
    }
    $dt = $row['DateTimeInput'];
    if ($dt instanceof DateTime) $dt = $dt->format('Y-m-d H:i:s');
    $taggedCustomers[] = [
        'id'    => (int)$row['ID'],
        'code'  => trim((string)$row['Code']),
        'name'  => trim((string)$row['CustomerName']),
        'addr'  => implode(', ', array_filter([
            trim((string)$row['Address']), trim((string)$row['Barangay']),
            trim((string)$row['Area']), trim((string)$row['Province'])
        ])),
        'cno'   => trim((string)$row['ContactNo']),
        'cper'  => trim((string)$row['ContactPerson']),
        'ctype' => trim((string)$row['CustomerType']),
        'tin'   => trim((string)$row['TIN']),
        'status'=> trim((string)$row['Status']),
        'rmk'   => trim((string)$row['Remarks']),
        'pic'   => $pic,
        'uid'   => (int)$row['UserInputID'],
        'dt'    => (string)$dt,
        'qr'    => trim((string)$row['QRCODE']),
        'lat'   => $hasGeo ? $lat : null,
        'lng'   => $hasGeo ? $lng : null,
    ];
}

// ---- Tagged (Status=2) but not yet written into Tbl_Customer_Info — needs sync ----
$pendStmt = sqlsrv_query($conn, "
    SELECT QRCODE, CustomerID, Department, Status, UserInput, DateTimeInput,
           Code, CustomerName, Address, Area, EmployeeName, Latitude, Longitude
    FROM dbo.View_QRCode_Tag_Pending
    WHERE Status = 2
    ORDER BY DateTimeInput ASC
");
if ($pendStmt === false) {
    die('Pending tag query failed: ' . print_r(sqlsrv_errors(), true));
}
$pendingList = [];
while ($row = sqlsrv_fetch_array($pendStmt, SQLSRV_FETCH_ASSOC)) {
    [$lat, $lng] = qut_fix_coords($row['Latitude'], $row['Longitude']);
    $dt = $row['DateTimeInput'];
    if ($dt instanceof DateTime) $dt = $dt->format('Y-m-d H:i:s');
    $pendingList[] = [
        'qr'       => trim((string)$row['QRCODE']),
        'cid'      => (int)$row['CustomerID'],
        'name'     => trim((string)$row['CustomerName']),
        'code'     => trim((string)$row['Code']),
        'addr'     => implode(', ', array_filter([trim((string)$row['Address']), trim((string)$row['Area'])])),
        'employee' => trim((string)$row['EmployeeName']),
        'dt'       => (string)$dt,
        'lat'      => ($lat != 0 && $lng != 0) ? $lat : null,
        'lng'      => ($lat != 0 && $lng != 0) ? $lng : null,
    ];
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QR Code Tagging</title>
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/admin.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/topbar.css">
<link rel="stylesheet" href="<?php echo base_url(); ?>assets/css/responsive-patch.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
  .qsl-wrap { padding: 24px; }
  .qsl-filter-card { border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:20px; background:#fff; }
  .qsl-map { height: 460px; border-radius:14px; border:1px solid #e2e8f0; margin-bottom:20px; position:relative; z-index:0; }
  .qsl-table th, .qsl-table td { vertical-align: middle; font-size: 13.5px; }
  .qsl-seq-badge { display:inline-block; min-width:26px; padding:2px 6px; border-radius:999px; background:#1d4ed8; color:#fff; font-weight:600; text-align:center; }
  .qsl-empty { text-align:center; padding:40px; color:#64748b; }

  /* ── Untagged QR map layout ── */
  .qsl-grid { display:grid; grid-template-columns:minmax(0,1fr) 400px; gap:20px; align-items:start; margin-bottom:20px; }
  @media (max-width:992px) { .qsl-grid { grid-template-columns:1fr; } }
  .qsl-grid .qsl-map { margin-bottom:0; }
  .qsl-panel { height:460px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:14px; background:#fff; padding:14px; }
  .qsl-panel-empty { color:#64748b; font-size:13.5px; padding:12px 4px; }
  .qsl-panel-title { font-size:16px; }
  .qsl-sub { color:#64748b; font-size:12.5px; }
  .qsl-cand { display:flex; align-items:center; gap:8px; padding:8px; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:6px; cursor:pointer; }
  .qsl-cand:hover { background:#f8fafc; }
  .qsl-cand.active { border-color:#1d4ed8; background:#eff6ff; }
  .qsl-cand-main { flex:1; min-width:0; }
  .qsl-cand-main strong, .qsl-cand-main .qsl-sub { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .qsl-dist { font-size:11.5px; font-weight:600; padding:2px 7px; border-radius:999px; white-space:nowrap; }
  .qsl-dist.near { background:#dcfce7; color:#15803d; }
  .qsl-dist.mid  { background:#fef3c7; color:#a16207; }
  .qsl-dist.far  { background:#fee2e2; color:#b91c1c; }
  .qsl-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px; }
  .qsl-table tbody tr { cursor:pointer; }
  #qslTabs .nav-link { cursor:pointer; border:1px solid transparent; background:none; }
  #qslTabs .nav-link.active { border-color:#dee2e6 #dee2e6 #fff; font-weight:600; }
  .qsl-tab-pane.d-none { display:none; }

  .qsl-confirm-backdrop {display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:4000; align-items:center; justify-content:center; padding:20px; }
  .qsl-confirm-backdrop.active { display:flex; }
  .qsl-confirm { background:#fff; border-radius:14px; padding:20px; max-width:440px; width:100%; box-shadow:0 10px 40px rgba(0,0,0,.35); }
  .qsl-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#0f172a; color:#fff; padding:10px 18px; border-radius:10px; font-size:14px; z-index:4100; display:none; }
  .qsl-toast.err { background:#b91c1c; }
</style>
</head>
<body>
<?php $topbar_page = 'qr_untagged_map'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="qsl-wrap">
  <div class="page-header">
    <h1 class="page-title">QR Code Tagging</h1>
  </div>

  <ul class="nav nav-tabs mb-2" id="qslTabs">
    <li class="nav-item">
      <button type="button" class="nav-link active" id="qslTabBtnUntagged" onclick="qslSwitchTab('untagged')">
        Untagged QR Codes <span class="badge bg-danger ms-1" id="qslStatQr"><?php echo count($qrList); ?></span>
      </button>
    </li>
    <li class="nav-item">
      <button type="button" class="nav-link" id="qslTabBtnPending" onclick="qslSwitchTab('pending')">
        Pending Sync <span class="badge bg-warning text-dark ms-1" id="qslStatPending"><?php echo count($pendingList); ?></span>
      </button>
    </li>
  </ul>
  <p class="text-muted mb-3">
    <span id="qslStatCust"><?php echo count($customers); ?></span> customers without a QR code &middot;
    <?php echo count($taggedCustomers); ?> already tagged
  </p>

  <div id="qslTabUntagged" class="qsl-tab-pane">
  <?php if (!$qrList): ?>
    <div class="qsl-empty">No untagged QR codes. Everything scanned has been tagged.</div>
  <?php else: ?>
    <div id="qslSoloBar" class="d-none align-items-center justify-content-between mb-2" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:8px 12px;font-size:13px;">
      <span>Showing only <strong id="qslSoloCode"></strong> on the map</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showAllQr()">Show all pins</button>
    </div>
    <div class="qsl-grid">
      <div id="qslMap" class="qsl-map"></div>
      <div id="qslPanel" class="qsl-panel"></div>
    </div>

    <div class="d-flex gap-2 mb-2" id="qslViewToggle">
      <button type="button" class="btn btn-sm btn-outline-danger active" onclick="qslSetView('untagged', this)"><span class="qsl-dot" style="background:#dc2626"></span>Untagged QR Codes</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="qslSetView('noqr', this)"><span class="qsl-dot" style="background:#94a3b8"></span>Customers without QR</button>
      <button type="button" class="btn btn-sm btn-outline-success" onclick="qslSetView('hasqr', this)"><span class="qsl-dot" style="background:#16a34a"></span>Customers with QR</button>
    </div>
    <input type="search" id="qslTableSearch" class="form-control form-control-sm mb-2" placeholder="Search QR code or FileNo" oninput="qslFilterActiveTable()">

    <div id="qslViewUntagged" class="table-responsive">
      <table class="table table-bordered table-hover qsl-table bg-white">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>QR Code</th>
            <th>FileNo</th>
            <th>Scans</th>
            <th>Latitude</th>
            <th>Longitude</th>
          </tr>
        </thead>
        <tbody id="qslQrBody">
          <?php foreach ($qrList as $i => $q): ?>
            <tr data-idx="<?php echo $i; ?>">
              <td><span class="qsl-seq-badge" style="background:#dc2626;"><?php echo $i + 1; ?></span></td>
              <td><?php echo h($q['code']); ?></td>
              <td><?php echo h(implode(', ', $q['files'])); ?></td>
              <td><?php echo count($q['scans']); ?></td>
              <?php if ($q['lat'] !== null): ?>
                <td><?php echo h($q['lat']); ?></td>
                <td><?php echo h($q['lng']); ?></td>
              <?php else: ?>
                <td colspan="2" class="text-muted">No location</td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="qslViewNoQr" class="table-responsive d-none">
      <table class="table table-bordered table-hover qsl-table bg-white">
        <thead class="table-light">
          <tr><th>#</th><th>Customer</th><th>Code</th><th>Address</th><th>Latitude</th><th>Longitude</th></tr>
        </thead>
        <tbody id="qslNoQrBody">
          <?php foreach ($customers as $i => $c): ?>
            <tr data-id="<?php echo $c['id']; ?>">
              <td><span class="qsl-seq-badge" style="background:#94a3b8;"><?php echo $i + 1; ?></span></td>
              <td><?php echo h($c['name']); ?></td>
              <td><?php echo h($c['code']); ?></td>
              <td><?php echo h($c['addr']); ?></td>
              <?php if ($c['lat'] !== null): ?>
                <td><?php echo h($c['lat']); ?></td>
                <td><?php echo h($c['lng']); ?></td>
              <?php else: ?>
                <td colspan="2" class="text-muted">No location</td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="qslViewHasQr" class="table-responsive d-none">
      <table class="table table-bordered table-hover qsl-table bg-white">
        <thead class="table-light">
          <tr><th>#</th><th>Customer</th><th>Code</th><th>QR Code</th><th>Address</th></tr>
        </thead>
        <tbody id="qslHasQrBody">
          <?php foreach ($taggedCustomers as $i => $c): ?>
            <tr data-id="<?php echo $c['id']; ?>">
              <td><span class="qsl-seq-badge" style="background:#16a34a;"><?php echo $i + 1; ?></span></td>
              <td><?php echo h($c['name']); ?></td>
              <td><?php echo h($c['code']); ?></td>
              <td><?php echo h($c['qr']); ?></td>
              <td><?php echo h($c['addr']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-2">
      <span id="qslPagerInfo" class="text-muted" style="font-size:12.5px;"></span>
      <div class="btn-group">
        <button type="button" id="qslPagerPrev" class="btn btn-sm btn-outline-secondary" onclick="qslPagerGo(-1)">&laquo; Prev</button>
        <button type="button" id="qslPagerNext" class="btn btn-sm btn-outline-secondary" onclick="qslPagerGo(1)">Next &raquo;</button>
      </div>
    </div>
  <?php endif; ?>
  </div>

  <div id="qslTabPending" class="qsl-tab-pane d-none">
    <p class="text-muted mb-2">Already tagged to a customer &mdash; waiting to be written into Tbl_Customer_Info.</p>
    <?php if (!$pendingList): ?>
      <div class="qsl-empty">Nothing pending sync right now.</div>
    <?php else: ?>
      <div class="qsl-grid">
        <div id="qslPendingMap" class="qsl-map"></div>
        <div id="qslPendingPanel" class="qsl-panel"></div>
      </div>
      <input type="search" id="qslPendingTableSearch" class="form-control form-control-sm mb-2" placeholder="Search QR code, customer, code, or employee" oninput="filterPendingTable(this.value)">
      <div class="table-responsive">
        <table class="table table-bordered table-hover qsl-table bg-white">
          <thead class="table-light">
            <tr><th>#</th><th>QR Code</th><th>Customer</th><th>Code</th><th>Tagged By</th><th>Date Tagged</th></tr>
          </thead>
          <tbody id="qslPendingBody">
            <?php foreach ($pendingList as $i => $p): ?>
              <tr data-idx="<?php echo $i; ?>">
                <td><span class="qsl-seq-badge" style="background:#d97706;"><?php echo $i + 1; ?></span></td>
                <td><?php echo h($p['qr']); ?></td>
                <td><?php echo h($p['name']); ?></td>
                <td><?php echo h($p['code']); ?></td>
                <td><?php echo h($p['employee']); ?></td>
                <td><?php echo h($p['dt']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div id="qslCustModalBackdrop" class="qsl-confirm-backdrop">
    <div class="qsl-confirm" style="max-width:480px;">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="mb-0" id="qslCustModalName">Customer</h5>
            <button type="button" class="btn-close" onclick="closeCustomerModal()" aria-label="Close"></button>
        </div>
        <div id="qslCustModalBody" style="font-size:13.5px;"></div>
        <div id="qslCustModalFooter" class="d-flex justify-content-end gap-2 mt-3"></div>
    </div>
</div>

<div id="qslQrLightboxBackdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:4200;align-items:center;justify-content:center;padding:30px;cursor:zoom-out;" onclick="this.style.display='none'">
    <div id="qslQrLightboxInner" style="background:#fff;padding:20px;border-radius:10px;"></div>
</div>

<div id="qslConfirmBackdrop" class="qsl-confirm-backdrop">
    <div class="qsl-confirm">
        <h5 class="mb-2">Confirm</h5>
        <p id="qslConfirmText" class="mb-3"></p>
        <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-light" onclick="closeConfirm()">Cancel</button>
            <button type="button" class="btn btn-primary" id="qslConfirmBtn" onclick="confirmAction()">Yes, proceed</button>
        </div>
    </div>
</div>
<div id="qslToast" class="qsl-toast"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
<?php if ($qrList): ?>
const QR      = <?php echo json_encode($qrList, $jf); ?>;
const CUST    = <?php echo json_encode($customers, $jf); ?>;
const CUST_OK = <?php echo json_encode($taggedCustomers, $jf); ?>;
const ALL_CUST = CUST.concat(CUST_OK); // suggestions now include already-tagged customers, for overriding
const CSRF    = <?php echo json_encode($_SESSION['qut_csrf']); ?>;
const VIEW_ONLY = <?php echo $view_only ? 'true' : 'false'; ?>;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function distM(a, b) {
    const R = 6371000, rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(x));
}
const fmtDist   = m => m < 1000 ? Math.round(m) + ' m' : (m / 1000).toFixed(1) + ' km';
const distClass = m => m < 100 ? 'near' : (m < 500 ? 'mid' : 'far');

const qslMap = L.map('qslMap', { preferCanvas: true });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(qslMap);

const qrLayer     = L.layerGroup().addTo(qslMap);
const custLayer   = L.layerGroup().addTo(qslMap);
const taggedLayer = L.layerGroup(); // off by default — can get crowded, toggle on as needed
const scanLocLayer = L.layerGroup().addTo(qslMap); // per-scan location dots for the currently selected QR

L.control.layers(null, {
    '<span class="qsl-dot" style="background:#dc2626"></span>Untagged QR scans': qrLayer,
    '<span class="qsl-dot" style="background:#94a3b8"></span>Customers without QR': custLayer,
    '<span class="qsl-dot" style="background:#16a34a"></span>Customers with QR': taggedLayer
}, { collapsed: false }).addTo(qslMap);

const qrMarkers = {}, custMarkers = {}, custById = {};
let selIdx = null, selLine = null, pending = null;

function codeChip(text, bg) {
    return `<div style="background:${bg};color:#fff;border-radius:8px 8px 8px 2px;padding:3px 7px;font-size:11px;font-weight:700;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(text)}</div>`;
}

// Markers sitting on (near-)identical coordinates hide each other; fan them out
// into a small circle for display only. Real lat/lng stay untouched for distance math.
function spreadOverlaps(items) {
    const groups = {};
    items.forEach((it, i) => {
        if (it.lat === null) return;
        const key = it.lat.toFixed(5) + ',' + it.lng.toFixed(5);
        (groups[key] = groups[key] || []).push(i);
    });
    Object.values(groups).forEach(idxs => {
        if (idxs.length < 2) { items[idxs[0]]._dLat = items[idxs[0]].lat; items[idxs[0]]._dLng = items[idxs[0]].lng; return; }
        const r = 0.00006; // ~6-7m — enough to separate pins, small enough to stay on the same spot
        idxs.forEach((i, k) => {
            const angle = (2 * Math.PI * k) / idxs.length;
            items[i]._dLat = items[i].lat + r * Math.cos(angle);
            items[i]._dLng = items[i].lng + r * Math.sin(angle);
        });
    });
    items.forEach(it => { if (it._dLat === undefined && it.lat !== null) { it._dLat = it.lat; it._dLng = it.lng; } });
}

spreadOverlaps(QR);
const bounds = [];
QR.forEach((q, i) => {
    if (q.lat === null) return;
    bounds.push([q._dLat, q._dLng]);
    const icon = L.divIcon({
        className: 'qsl-pin',
        html: codeChip(q.code, '#dc2626'),
        iconSize: [110, 22],
        iconAnchor: [10, 22]
    });
    qrMarkers[i] = L.marker([q._dLat, q._dLng], { icon })
        .bindPopup(`<strong>${esc(q.code)}</strong><br>${q.scans.length} scan${q.scans.length > 1 ? 's' : ''}<br>FileNo: ${esc(q.files.join(', '))}`)
        .on('click', () => selectQr(i, false))
        .addTo(qrLayer);
});

CUST.forEach(c => {
    custById[c.id] = c;
    if (c.lat === null) return;
    custMarkers[c.id] = L.circleMarker([c.lat, c.lng], { radius: 5, color: '#475569', weight: 1, fillColor: '#94a3b8', fillOpacity: .8 })
        .on('click', () => openCustomerModal(c, false))
        .addTo(custLayer);
});

// Reference-only: customers already tagged, shown as green chips with their QR code
CUST_OK.forEach(c => {
    custById[c.id] = c;
    if (c.lat === null) return;
    L.marker([c.lat, c.lng], {
        icon: L.divIcon({
            className: 'qsl-pin',
            html: codeChip(c.qr, '#16a34a'),
            iconSize: [110, 22],
            iconAnchor: [10, 22]
        })
    })
    .on('click', () => openCustomerModal(c, true))
    .addTo(taggedLayer);
});

if (bounds.length) {
    qslMap.fitBounds(bounds, { padding: [40, 40] });
} else {
    qslMap.setView([13.9, 121.9], 9); // fallback: South Luzon
}

function clearLine() {
    if (selLine) { qslMap.removeLayer(selLine); selLine = null; }
}

function selectQr(idx, fly) {
    selIdx = idx;
    clearLine();
    document.querySelectorAll('#qslQrBody tr').forEach(tr => tr.classList.toggle('table-primary', +tr.dataset.idx === idx));
    const q = QR[idx];
    showOnlyQr(idx);
    renderScanLocs(idx);
    if (fly && q.lat !== null && qrMarkers[idx]) {
        qslMap.flyTo([q.lat, q.lng], Math.max(qslMap.getZoom(), 17));
        qrMarkers[idx].openPopup();
    }
    renderPanel();
}

function showOnlyQr(idx) {
    Object.keys(qrMarkers).forEach(k => {
        if (+k === idx) {
            if (!qslMap.hasLayer(qrMarkers[k])) qrMarkers[k].addTo(qrLayer);
        } else {
            qrLayer.removeLayer(qrMarkers[k]);
        }
    });
    document.getElementById('qslSoloCode').textContent = QR[idx].code;
    document.getElementById('qslSoloBar').classList.remove('d-none');
    document.getElementById('qslSoloBar').classList.add('d-flex');
}

function showAllQr() {
    Object.values(qrMarkers).forEach(m => m.addTo(qrLayer));
    scanLocLayer.clearLayers();
    document.getElementById('qslSoloBar').classList.add('d-none');
    document.getElementById('qslSoloBar').classList.remove('d-flex');
}

function renderScanLocs(idx) {
    scanLocLayer.clearLayers();
    const q = QR[idx];
    if (q.selScanId === undefined) {
        const first = q.scans.find(s => s.lat !== null);
        q.selScanId = first ? first.id : null;
    }
    q.scans.forEach(s => {
        if (s.lat === null) return;
        const isSel = s.id === q.selScanId;
        L.circleMarker([s.lat, s.lng], {
            radius: isSel ? 8 : 5,
            color: isSel ? '#1d4ed8' : '#94a3b8',
            weight: isSel ? 3 : 1,
            fillColor: isSel ? '#3b82f6' : '#e2e8f0',
            fillOpacity: .9
        })
        .bindTooltip(`${esc(s.employee || 'Unknown')}${s.fileno ? ' &middot; ' + esc(s.fileno) : ''}${isSel ? ' (selected as true location)' : ' — click to use this location instead'}`)
        .on('click', () => selectScanLoc(idx, s.id))
        .addTo(scanLocLayer);
    });
}

function selectScanLoc(idx, scanId) {
    QR[idx].selScanId = scanId;
    renderScanLocs(idx);
    const s = QR[idx].scans.find(x => x.id === scanId);
    if (s && s.lat !== null) {
        qslMap.flyTo([s.lat, s.lng], Math.max(qslMap.getZoom(), 18));
    }
    if (selIdx === idx) {
        clearLine();
        renderPanel();
    }
}

function getQrLatLng(q) {
    const s = q.scans && q.scans.find(x => x.id === q.selScanId);
    if (s && s.lat !== null) return { lat: s.lat, lng: s.lng };
    return q.lat !== null ? { lat: q.lat, lng: q.lng } : null;
}

function renderPanel() {
    const p = document.getElementById('qslPanel');
    if (selIdx === null) {
        p.innerHTML = '<div class="qsl-panel-empty">Select a QR code on the map or in the table to find the customer it belongs to.</div>';
        return;
    }
    const q = QR[selIdx];
    if (q.selScanId === undefined) {
        const first = q.scans.find(s => s.lat !== null);
        q.selScanId = first ? first.id : null;
    }
    const locCount = new Set(q.scans.filter(s => s.lat !== null).map(s => s.lat.toFixed(5) + ',' + s.lng.toFixed(5))).size;
    const scanRows = q.scans.map(s => {
        const hasLoc = s.lat !== null;
        const radio = hasLoc
            ? `<input type="radio" name="qslLoc${selIdx}" ${q.selScanId === s.id ? 'checked' : ''} onchange="selectScanLoc(${selIdx}, ${s.id})" title="Use this scan's coordinates as the true location">`
            : `<span class="text-muted" style="font-size:10.5px;">no loc</span>`;
        return `
        <div class="d-flex align-items-center gap-2" style="padding:3px 0;border-top:1px solid #e2e8f0;font-size:12.5px;">
            ${radio}
            <span class="flex-grow-1">${esc(s.employee || 'Unknown')}${s.fileno ? ' &middot; ' + esc(s.fileno) : ''}${s.datetime ? ' &middot; <span class="text-muted">' + esc(s.datetime) + '</span>' : ''}</span>
            ${VIEW_ONLY ? '' : `<button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete this scan" onclick="askDeleteScan(${selIdx}, ${s.id})">&times;</button>`}
        </div>`;
    }).join('');
    p.innerHTML = `
        <div class="qsl-panel-title">QR <strong>${esc(q.code)}</strong></div>
        <div class="qsl-sub mb-1">${q.scans.length} scan${q.scans.length > 1 ? 's' : ''}${q.lat === null ? ' &middot; <span class="text-danger">no location</span>' : ''}</div>
        ${locCount > 1 ? '<div class="text-danger mb-1" style="font-size:12px;">This code was scanned from more than one location — pick the correct one below.</div>' : ''}
        <div class="mb-2" style="max-height:110px;overflow-y:auto;">${scanRows}</div>
        <input type="search" id="qslSearch" class="form-control form-control-sm mb-2" placeholder="Search customer name, code or address" oninput="renderCands(this.value)">
        <div id="qslCandList"></div>`;
    renderCands('');
}

function renderCands(term) {
    const q = QR[selIdx], t = term.trim().toLowerCase();
    const here = getQrLatLng(q);
    let list = ALL_CUST.map(c => ({ c, d: (here && c.lat !== null) ? distM(here, c) : null }));
    if (t) list = list.filter(x => (x.c.name + ' ' + x.c.code + ' ' + x.c.addr).toLowerCase().includes(t));
    else if (here) list = list.filter(x => x.d !== null);
    else list = [];
    list.sort((a, b) => (a.d ?? Infinity) - (b.d ?? Infinity));
    list = list.slice(0, t ? 25 : 10);

    const el = document.getElementById('qslCandList');
    if (!list.length) {
        el.innerHTML = '<div class="qsl-panel-empty">' + (t ? 'No customers match.' : (here ? 'No customers with coordinates found.' : 'This scan has no location. Search for the customer above.')) + '</div>';
        return;
    }
    el.innerHTML = list.map(({ c, d }) => `
        <div class="qsl-cand" data-id="${c.id}" onclick="previewCust(${c.id})">
            <div class="qsl-cand-main">
                <strong>${esc(c.name)}</strong>
                <span class="qsl-sub">${esc(c.code)}${c.addr ? ' &middot; ' + esc(c.addr) : ''}${c.qr ? ' &middot; already: ' + esc(c.qr) : ''}</span>
            </div>
            ${d !== null ? `<span class="qsl-dist ${distClass(d)}">${fmtDist(d)}</span>` : ''}
            ${VIEW_ONLY ? '' : `<button type="button" class="btn btn-sm ${c.qr ? 'btn-outline-danger' : 'btn-primary'}" onclick="askTag(${c.id}); event.stopPropagation();">${c.qr ? 'Override' : 'Tag'}</button>`}
        </div>`).join('');
}

function previewCust(id) {
    clearLine();
    document.querySelectorAll('.qsl-cand').forEach(r => r.classList.toggle('active', +r.dataset.id === id));
    const c = custById[id], q = QR[selIdx];
    if (!c || c.lat === null) return;
    const here = getQrLatLng(q);
    if (here) {
        selLine = L.polyline([[here.lat, here.lng], [c.lat, c.lng]], { color: '#dc2626', weight: 3, dashArray: '6,6' }).addTo(qslMap);
        qslMap.fitBounds([[here.lat, here.lng], [c.lat, c.lng]], { padding: [60, 60], maxZoom: 18 });
    } else {
        qslMap.flyTo([c.lat, c.lng], 17);
    }
    if (custMarkers[id]) custMarkers[id].openPopup();
}

function askTag(id) {
    const c = custById[id], q = QR[selIdx];
    if (!c || !q) return;
    pending = { type: 'tag', idx: selIdx, id };
    const here = getQrLatLng(q);
    let warn = '';
    if (here && c.lat !== null) {
        const d = distM(here, c);
        if (d > 500) warn = `<div class="text-danger mt-2">Heads up: this customer is ${fmtDist(d)} from the selected scan location.</div>`;
    }
    let overrideWarn = '';
    if (c.qr) {
        overrideWarn = `<div class="text-danger mt-2">This customer is currently tagged to <strong>${esc(c.qr)}</strong>. Proceeding will overwrite it with this new code and location.</div>`;
    }
    document.getElementById('qslConfirmText').innerHTML =
        `${c.qr ? 'Override' : 'Tag'} QR code <strong>${esc(q.code)}</strong> ${c.qr ? 'onto' : 'to'} <strong>${esc(c.name)}</strong> (${esc(c.code)})?${overrideWarn}${warn}`;
    document.getElementById('qslConfirmBackdrop').classList.add('active');
}

function closeConfirm() {
    pending = null;
    document.getElementById('qslConfirmBackdrop').classList.remove('active');
}

async function confirmAction() {
    if (!pending) return;
    const btn = document.getElementById('qslConfirmBtn');
    btn.disabled = true;
    try {
        if (pending.type === 'tag') {
            const { idx, id } = pending, q = QR[idx];
            const here = getQrLatLng(q);
            const res = await fetch(location.href, {
                method: 'POST',
                body: new URLSearchParams({ action: 'tag', qrcode: q.code, customer_id: id, latitude: here ? here.lat : '', longitude: here ? here.lng : '', csrf: CSRF })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Tagging failed.');
            toast(`Tagged ${q.code} — reloading...`);
            setTimeout(() => location.reload(), 700);
            return;
        } else if (pending.type === 'sync') {
            const { idx } = pending, p = PENDING[idx];
            const res = await fetch(location.href, {
                method: 'POST',
                body: new URLSearchParams({ action: 'sync', qrcode: p.qr, customer_id: p.cid, latitude: p.lat ?? '', longitude: p.lng ?? '', csrf: CSRF })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Sync failed.');
            onSynced(idx);
            toast(`Synced ${p.qr} to ${p.name}`);
        } else if (pending.type === 'delete_scan') {
            const { idx, id } = pending;
            const res = await fetch(location.href, {
                method: 'POST',
                body: new URLSearchParams({ action: 'delete_scan', id, csrf: CSRF })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Delete failed.');
            onScanDeleted(idx, id);
            toast('Scan deleted');
        } else if (pending.type === 'void_pending') {
            const { idx } = pending, p = PENDING[idx];
            const res = await fetch(location.href, {
                method: 'POST',
                body: new URLSearchParams({ action: 'void_pending', qrcode: p.qr, customer_id: p.cid, csrf: CSRF })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Void failed.');
            onSynced(idx); // removes the row/marker the same way a completed sync would
            toast(`Voided ${p.qr}`);
        }
    } catch (e) {
        toast(e.message, true);
    }
    btn.disabled = false;
    closeConfirm();
}

function updateQrStat() {
    document.getElementById('qslStatQr').textContent = QR.filter(q => !q.done && q.scans.length > 0).length;
}

function onTagged(idx, id) {
    QR[idx].done = true;
    if (qrMarkers[idx]) qrLayer.removeLayer(qrMarkers[idx]);
    const row = document.querySelector(`#qslQrBody tr[data-idx="${idx}"]`);
    if (row) row.remove();
    const ci = CUST.findIndex(c => c.id === id);
    if (ci > -1) CUST.splice(ci, 1);
    delete custById[id];
    if (custMarkers[id]) { custLayer.removeLayer(custMarkers[id]); delete custMarkers[id]; }
    clearLine();
    selIdx = null;
    renderPanel();
    updateQrStat();
    document.getElementById('qslStatCust').textContent = CUST.length;
}

function askDeleteScan(idx, id) {
    pending = { type: 'delete_scan', idx, id };
    document.getElementById('qslConfirmText').innerHTML =
        `Delete this scan record permanently? This cannot be undone.`;
    document.getElementById('qslConfirmBackdrop').classList.add('active');
}

let modalCust = null;

function openCustomerModal(c, isTagged) {
    modalCust = c;
    document.getElementById('qslCustModalName').textContent = c.name;

    const picHtml = c.pic
        ? `<div class="text-center mb-2"><img src="${c.pic}" alt="Customer photo" style="max-width:100%;max-height:160px;border-radius:8px;object-fit:cover;"></div>`
        : '';

    const rows = [
        ['Code', c.code],
        ['Address', c.addr],
        ['Contact Person', c.cper],
        ['Contact No.', c.cno],
        ['Customer Type', c.ctype],
        ['TIN', c.tin],
        ['Status', c.status],
        ['Remarks', c.rmk],
        ['Registered', c.dt],
        ['Registered By (User ID)', c.uid > 0 ? c.uid : ''],
    ].filter(([, v]) => v);

    let html = picHtml + rows.map(([label, val]) => `
        <div class="d-flex justify-content-between border-top py-1">
            <span class="text-muted">${esc(label)}</span>
            <span class="text-end" style="max-width:65%;">${esc(val)}</span>
        </div>`).join('');

    if (isTagged && c.qr) {
        html += `
        <div class="border-top pt-2 mt-2 text-center">
            <div class="text-muted mb-1" style="font-size:12px;">QR Code: ${esc(c.qr)}</div>
            <div id="qslCustQrBox" style="display:inline-block;cursor:zoom-in;" title="Click to enlarge" onclick="openQrLightbox()"></div>
        </div>`;
    } else if (!isTagged) {
        html += `<div class="border-top pt-2 mt-2 text-muted text-center" style="font-size:12px;">No QR code tagged yet</div>`;
    }

    document.getElementById('qslCustModalBody').innerHTML = html;

    const footer = document.getElementById('qslCustModalFooter');
    footer.innerHTML = '<button type="button" class="btn btn-light" onclick="closeCustomerModal()">Close</button>';

    if (isTagged && c.qr) {
        const box = document.getElementById('qslCustQrBox');
        box.innerHTML = '';
        new QRCode(box, { text: c.qr, width: 96, height: 96 });
    }

    document.getElementById('qslCustModalBackdrop').classList.add('active');
}

function closeCustomerModal() {
    document.getElementById('qslCustModalBackdrop').classList.remove('active');
}

function openQrLightbox() {
    if (!modalCust || !modalCust.qr) return;
    const inner = document.getElementById('qslQrLightboxInner');
    inner.innerHTML = '';
    new QRCode(inner, { text: modalCust.qr, width: 240, height: 240 });
    document.getElementById('qslQrLightboxBackdrop').style.display = 'flex';
}

function onScanDeleted(idx, id) {
    const q = QR[idx];
    q.scans = q.scans.filter(s => s.id !== id);
    if (q.scans.length === 0) {
        if (qrMarkers[idx]) { qrLayer.removeLayer(qrMarkers[idx]); delete qrMarkers[idx]; }
        const row = document.querySelector(`#qslQrBody tr[data-idx="${idx}"]`);
        if (row) row.remove();
        selIdx = null;
        renderPanel();
    } else {
        const cell = document.querySelector(`#qslQrBody tr[data-idx="${idx}"] td:nth-child(4)`);
        if (cell) cell.textContent = q.scans.length;
        renderPanel();
    }
    updateQrStat();
}

function toast(msg, isErr) {
    const t = document.getElementById('qslToast');
    t.textContent = msg;
    t.className = 'qsl-toast' + (isErr ? ' err' : '');
    t.style.display = 'block';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { t.style.display = 'none'; }, 3500);
}

document.querySelectorAll('#qslQrBody tr').forEach(tr => tr.addEventListener('click', () => {
    selectQr(+tr.dataset.idx, true);
    document.getElementById('qslMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
document.querySelectorAll('#qslNoQrBody tr').forEach(tr => tr.addEventListener('click', () => {
    const c = custById[+tr.dataset.id];
    if (!c) return;
    openCustomerModal(c, false);
    if (c.lat !== null) qslMap.flyTo([c.lat, c.lng], Math.max(qslMap.getZoom(), 17));
    document.getElementById('qslMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
document.querySelectorAll('#qslHasQrBody tr').forEach(tr => tr.addEventListener('click', () => {
    const c = custById[+tr.dataset.id];
    if (!c) return;
    if (!qslMap.hasLayer(taggedLayer)) qslMap.addLayer(taggedLayer);
    openCustomerModal(c, true);
    if (c.lat !== null) qslMap.flyTo([c.lat, c.lng], Math.max(qslMap.getZoom(), 17));
    document.getElementById('qslMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeConfirm(); });

renderPanel();

let qslActiveView = 'untagged';

const QSL_PAGE_SIZE = 20;
const qslPage = { untagged: 1, noqr: 1, hasqr: 1 };

function qslBodyId(view) {
    return view === 'untagged' ? 'qslQrBody' : (view === 'noqr' ? 'qslNoQrBody' : 'qslHasQrBody');
}

function qslSetView(view, btn) {
    qslActiveView = view;
    document.querySelectorAll('#qslViewToggle button').forEach(b => {
        b.classList.remove('active', 'btn-danger', 'btn-secondary', 'btn-success', 'text-white');
    });
    if (btn) {
        btn.classList.add('active');
        const solid = btn.classList.contains('btn-outline-danger') ? 'btn-danger'
                    : btn.classList.contains('btn-outline-success') ? 'btn-success' : 'btn-secondary';
        document.querySelectorAll('#qslViewToggle button').forEach(b => {
            const isThis = b === btn;
            const outlineClass = b.classList.contains('btn-outline-danger') ? 'btn-outline-danger'
                                : b.classList.contains('btn-outline-success') ? 'btn-outline-success' : 'btn-outline-secondary';
            b.classList.toggle(outlineClass, !isThis);
        });
        btn.classList.add(solid, 'text-white');
    }
    document.getElementById('qslViewUntagged').classList.toggle('d-none', view !== 'untagged');
    document.getElementById('qslViewNoQr').classList.toggle('d-none', view !== 'noqr');
    document.getElementById('qslViewHasQr').classList.toggle('d-none', view !== 'hasqr');
    if (view !== 'untagged') showAllQr();
    const search = document.getElementById('qslTableSearch');
    search.value = '';
    search.placeholder = view === 'untagged' ? 'Search QR code or FileNo' : 'Search customer name, code or address';
    qslPage[view] = 1;
    qslRenderActiveTable();
}

function qslFilterActiveTable() {
    qslPage[qslActiveView] = 1;
    qslRenderActiveTable();
}

function qslPagerGo(delta) {
    qslPage[qslActiveView] += delta;
    qslRenderActiveTable();
}

function qslRenderActiveTable() {
    const term = document.getElementById('qslTableSearch').value.trim().toLowerCase();
    const bodyId = qslBodyId(qslActiveView);
    const rows = Array.from(document.querySelectorAll(`#${bodyId} tr`));
    const matched = term ? rows.filter(tr => tr.textContent.toLowerCase().includes(term)) : rows;

    const totalPages = Math.max(1, Math.ceil(matched.length / QSL_PAGE_SIZE));
    if (qslPage[qslActiveView] > totalPages) qslPage[qslActiveView] = totalPages;
    if (qslPage[qslActiveView] < 1) qslPage[qslActiveView] = 1;
    const page = qslPage[qslActiveView];
    const start = (page - 1) * QSL_PAGE_SIZE;
    const end = start + QSL_PAGE_SIZE;
    const visible = matched.slice(start, end);

    rows.forEach(tr => tr.style.display = 'none');
    visible.forEach(tr => tr.style.display = '');

    const info = document.getElementById('qslPagerInfo');
    info.textContent = matched.length
        ? `Showing ${start + 1}\u2013${Math.min(end, matched.length)} of ${matched.length}`
        : 'No records found.';
    document.getElementById('qslPagerPrev').disabled = page <= 1;
    document.getElementById('qslPagerNext').disabled = page >= totalPages;
}

qslRenderActiveTable();
<?php endif; ?>

<?php if ($pendingList): ?>
const PENDING = <?php echo json_encode($pendingList, $jf); ?>;

const qslPendingMap = L.map('qslPendingMap');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(qslPendingMap);

const pendMarkers = {};
const pendBounds = [];
PENDING.forEach((p, i) => {
    if (p.lat === null) return;
    pendBounds.push([p.lat, p.lng]);
    pendMarkers[i] = L.marker([p.lat, p.lng], {
        icon: L.divIcon({ className: 'qsl-pin', html: codeChip(p.qr, '#d97706'), iconSize: [110, 22], iconAnchor: [10, 22] })
    })
    .bindPopup(`<strong>${esc(p.qr)}</strong><br>${esc(p.name)} (${esc(p.code)})<br>Tagged by ${esc(p.employee)} &middot; ${esc(p.dt)}`)
    .on('click', () => selectPending(i, false))
    .addTo(qslPendingMap);
});
if (pendBounds.length) qslPendingMap.fitBounds(pendBounds, { padding: [40, 40] });
else qslPendingMap.setView([13.9, 121.9], 9);

let pendSelIdx = null;

function selectPending(idx, fly) {
    pendSelIdx = idx;
    document.querySelectorAll('#qslPendingBody tr').forEach(tr => tr.classList.toggle('table-primary', +tr.dataset.idx === idx));
    const p = PENDING[idx];
    if (fly && p.lat !== null) {
        qslPendingMap.flyTo([p.lat, p.lng], Math.max(qslPendingMap.getZoom(), 17));
        pendMarkers[idx].openPopup();
    }
    renderPendingPanel();
}

function renderPendingPanel() {
    const el = document.getElementById('qslPendingPanel');
    if (pendSelIdx === null) {
        el.innerHTML = '<div class="qsl-panel-empty">Select a pending row to review and sync it into Tbl_Customer_Info.</div>';
        return;
    }
    const p = PENDING[pendSelIdx];
    el.innerHTML = `
        <div class="qsl-panel-title">QR <strong>${esc(p.qr)}</strong></div>
        <div class="qsl-sub mb-2">${esc(p.name)} (${esc(p.code)})<br>${esc(p.addr)}<br>Tagged by ${esc(p.employee)} on ${esc(p.dt)}</div>
        ${VIEW_ONLY ? '' : `
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary flex-grow-1" onclick="askSync(${pendSelIdx})">Sync to Tbl_Customer_Info</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="askVoidPending(${pendSelIdx})">Void</button>
        </div>`}`;
}

function askVoidPending(idx) {
    const p = PENDING[idx];
    pending = { type: 'void_pending', idx };
    document.getElementById('qslConfirmText').innerHTML =
        `Void this pending tag for QR <strong>${esc(p.qr)}</strong> &rarr; <strong>${esc(p.name)}</strong>? The record stays on file marked as Void (Status 5) and won't appear here again.`;
    document.getElementById('qslConfirmBackdrop').classList.add('active');
}

function askSync(idx) {
    const p = PENDING[idx];
    pending = { type: 'sync', idx };
    document.getElementById('qslConfirmText').innerHTML =
        `Write QR <strong>${esc(p.qr)}</strong> into <strong>${esc(p.name)}</strong>'s record (Tbl_Customer_Info) and mark tagging as done?`;
    document.getElementById('qslConfirmBackdrop').classList.add('active');
}

function onSynced(idx) {
    if (pendMarkers[idx]) qslPendingMap.removeLayer(pendMarkers[idx]);
    const row = document.querySelector(`#qslPendingBody tr[data-idx="${idx}"]`);
    if (row) row.remove();
    pendSelIdx = null;
    renderPendingPanel();
    document.getElementById('qslStatPending').textContent = document.querySelectorAll('#qslPendingBody tr').length;
}

document.querySelectorAll('#qslPendingBody tr').forEach(tr => tr.addEventListener('click', () => {
    selectPending(+tr.dataset.idx, true);
    document.getElementById('qslPendingMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
renderPendingPanel();

function filterPendingTable(term) {
    const t = term.trim().toLowerCase();
    document.querySelectorAll('#qslPendingBody tr').forEach(tr => {
        tr.style.display = (!t || tr.textContent.toLowerCase().includes(t)) ? '' : 'none';
    });
}
<?php endif; ?>

function qslSwitchTab(tab) {
    const isUntagged = tab === 'untagged';
    document.getElementById('qslTabUntagged').classList.toggle('d-none', !isUntagged);
    document.getElementById('qslTabPending').classList.toggle('d-none', isUntagged);
    document.getElementById('qslTabBtnUntagged').classList.toggle('active', isUntagged);
    document.getElementById('qslTabBtnPending').classList.toggle('active', !isUntagged);
    // Leaflet miscalculates size while its container is display:none, so fix it up on switch
    setTimeout(() => {
        if (isUntagged && typeof qslMap !== 'undefined') {
            qslMap.invalidateSize();
            if (typeof bounds !== 'undefined' && bounds.length) qslMap.fitBounds(bounds, { padding: [40, 40] });
        }
        if (!isUntagged && typeof qslPendingMap !== 'undefined') {
            qslPendingMap.invalidateSize();
            if (typeof pendBounds !== 'undefined' && pendBounds.length) qslPendingMap.fitBounds(pendBounds, { padding: [40, 40] });
        }
    }, 50);
}
</script>
</body>
</html>