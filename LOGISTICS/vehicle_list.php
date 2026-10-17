<?php
// vehicle_list.php — Vehicle List (RBAC key: vehicle_list)
// Thumbnail grid + detail modal + add/edit. NO delete (use the Active toggle instead).
ob_start();

// ── Include chain (same as cheques.php) ─────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'vehicle_list');
$canEdit     = !rbac_is_view_only('vehicle_list');
$topbar_page = 'vehicle_list';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['vl_csrf'])) $_SESSION['vl_csrf'] = bin2hex(random_bytes(32));

$CurrentUser = $_SESSION['EmployeeID'] ?? $_SESSION['UserID'] ?? $_SESSION['user_id'] ?? '';

// ── Helpers (vl_ prefix to avoid collisions with nav.php globals) ───────────
function vl_null($v) { $v = trim((string)$v); return $v === '' ? null : $v; }
function vl_dept_color($d) {
    $k = preg_replace('/[^a-z0-9]/', '', strtolower((string)$d));
    if ($k === '') return '#6c757d';
    $map = [
        'monde'          => '#dc3545', // red
        'nutriasia'      => '#198754', // green
        'silverswan'     => '#198754', // green
        'multilines'     => '#ffc107', // yellow
        'century'        => '#0d6efd', // blue
        'urbantradewell' => '#fd7e14', // orange
    ];
    foreach ($map as $key => $color) {
        if (strpos($k, $key) !== false) return $color;
    }
    return '#6c757d'; // any other department: gray
}
function vl_dept_fg($bg) { return $bg === '#ffc107' ? '#000' : '#fff'; }

function vl_date($v) {
    $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

// OR / CR upload (JPG/PNG/WEBP/PDF, 5 MB). Returns stored filename or null if nothing uploaded.
function vl_doc_upload($key, $plate, $tag, $allowPdf = true) {
    if (empty($_FILES[$key]['name']) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) return null;
    $file = $_FILES[$key];
    if ($file['size'] > 5 * 1024 * 1024) throw new Exception("$tag file must be 5 MB or less.");
    $mime   = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!$allowPdf) unset($extMap['application/pdf']);
    if (!isset($extMap[$mime]) || ($mime !== 'application/pdf' && !@getimagesize($file['tmp_name']))) throw new Exception("$tag file must be " . ($allowPdf ? 'JPG, PNG, WEBP or PDF' : 'JPG, PNG or WEBP') . ".");
    $dir = __DIR__ . '/../uploads/vehicle/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('Cannot create upload folder.');
    $name = (preg_replace('/[^A-Za-z0-9_-]/', '', $plate) ?: 'vehicle') . '_' . $tag . '_' . time() . '.' . $extMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) throw new Exception("$tag upload failed.");
    return $name;
}

function vl_pic($f) {
    $f = trim((string)$f);
    if ($f === '') return [null, null];
    $e = rawurlencode(basename($f));
    return ['/TWM/uploads/vehicle/' . $e, '/tradewellportal/vehicle/' . $e];
}

// ── POST: save (insert / update) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['danger', 'Save failed.'];
    try {
        if (!$canEdit) throw new Exception('You have view-only access.');
        if (!hash_equals($_SESSION['vl_csrf'], (string)($_POST['csrf'] ?? ''))) throw new Exception('Invalid session token. Refresh and try again.');

        $id    = (int)($_POST['VehicleID'] ?? 0);
        $plate = trim((string)($_POST['PlateNumber'] ?? ''));
        if ($plate === '') throw new Exception('Plate number is required.');

        $f = [
            'Department'     => vl_null($_POST['Department'] ?? ''),
            'PlateNumber'    => $plate,
            'Description'    => vl_null($_POST['Description'] ?? ''),
            'Vehicletype'    => vl_null($_POST['Vehicletype'] ?? ''),
            'Capacityweight' => vl_null($_POST['Capacityweight'] ?? ''),
            'Tiresize'       => vl_null($_POST['Tiresize'] ?? ''),
            'FuelType'       => vl_null($_POST['FuelType'] ?? ''),
            'Brand'          => vl_null($_POST['Brand'] ?? ''),
            'Model'          => vl_null($_POST['Model'] ?? ''),
            'Year'           => vl_null($_POST['Year'] ?? ''),
            'Transmission'   => vl_null($_POST['Transmission'] ?? ''),
            'Wheeler'        => vl_null($_POST['Wheeler'] ?? ''),
            'Sort'           => vl_null($_POST['Sort'] ?? ''),
            'Category'       => vl_null($_POST['Category'] ?? ''),
    'OR_Path'       => vl_null($_POST['OR_Path'] ?? ''),
    'CR_Path'       => vl_null($_POST['CR_Path'] ?? ''),
            'Active'         => !empty($_POST['Active']) ? 1 : 0,
        ];

        // Optional picture upload
        $newPic = null;
        if (!empty($_FILES['Picture']['name']) && $_FILES['Picture']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['Picture']['size'] > 5 * 1024 * 1024) throw new Exception('Picture must be 5 MB or less.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['Picture']['tmp_name']);
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extMap[$mime]) || !@getimagesize($_FILES['Picture']['tmp_name'])) throw new Exception('Picture must be JPG, PNG or WEBP.');
            $dir = __DIR__ . '/../uploads/vehicle/';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('Cannot create upload folder.');
            $newPic = (preg_replace('/[^A-Za-z0-9_-]/', '', $plate) ?: 'vehicle') . '_' . time() . '.' . $extMap[$mime];
            if (!move_uploaded_file($_FILES['Picture']['tmp_name'], $dir . $newPic)) throw new Exception('Picture upload failed.');
        }

        $newOR = vl_doc_upload('OR_File', $plate, 'OR');
        $newCR = vl_doc_upload('CR_File', $plate, 'CR');
        $newPB = vl_doc_upload('PicBack_File', $plate, 'Back', false);

        if ($id > 0) {
            $sets = []; $params = [];
            foreach ($f as $col => $val) { $sets[] = "[$col] = ?"; $params[] = $val; }
            if ($newPic !== null) { $sets[] = "[Picture] = ?"; $params[] = $newPic; }
            if ($newOR !== null)  { $sets[] = "[OR_Path] = ?"; $params[] = $newOR; }
            if ($newCR !== null)  { $sets[] = "[CR_Path] = ?"; $params[] = $newCR; }
            if ($newPB !== null)  { $sets[] = "[Picture_Back] = ?"; $params[] = $newPB; }
            $params[] = $id;
            $pdo->prepare("UPDATE dbo.Vehicle SET " . implode(', ', $sets) . " WHERE VehicleID = ?")->execute($params);
            $flash = ['success', 'Vehicle updated.'];
        } else {
            $cols = array_keys($f); $params = array_values($f);
            $cols[] = 'Picture';       $params[] = $newPic;
            $cols[] = 'OR_Path';       $params[] = $newOR;
            $cols[] = 'CR_Path';       $params[] = $newCR;
            $cols[] = 'Picture_Back';  $params[] = $newPB;
            $cols[] = 'UserID';        $params[] = $CurrentUser !== '' ? $CurrentUser : null;
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $names = implode(', ', array_map(fn($c) => "[$c]", $cols));
            $pdo->prepare("INSERT INTO dbo.Vehicle ($names, [DateTimeInput]) VALUES ($ph, GETDATE())")->execute($params);
            $flash = ['success', 'Vehicle added.'];
        }
    } catch (Throwable $e) {
        $flash = ['danger', $e->getMessage()];
    }
    $_SESSION['vl_flash'] = $flash;
    ob_end_clean();
    header('Location: vehicle_list.php');
    exit;
}

$flash = $_SESSION['vl_flash'] ?? null;
unset($_SESSION['vl_flash']);

// ── Load vehicles ───────────────────────────────────────────────────────────
$vehicles = []; $loadError = '';
try {
    $sql = "SELECT VehicleID, Department, PlateNumber, Description, Vehicletype, Capacityweight, Tiresize,
                   CAST(ISNULL(Active,0) AS INT) AS Active, Picture, FuelType, Brand, Model, [Year],
                   Transmission, Wheeler, Sort, CONVERT(VARCHAR(16), DateTimeInput, 120) AS DateTimeInput,
                   UserID, Category, REPLACE(PlateNumber, ' ', '') AS PlateClean,OR_Path,CR_Path
            FROM dbo.Vehicle
            ORDER BY CAST(ISNULL(Active,0) AS INT) DESC, Department, PlateNumber";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        [$r['img1'], $r['img2']] = vl_pic($r['Picture']);
        $vehicles[] = $r;
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

// Distinct values for dropdown suggestions
$opts = [];
foreach (['Department', 'Vehicletype', 'FuelType', 'Transmission', 'Category'] as $c) {
    $opts[$c] = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v[$c]), $vehicles))));
    sort($opts[$c]);
}
$activeCount = count(array_filter($vehicles, fn($v) => (int)$v['Active'] === 1));

// ── Table tab: dbo.View_Fuel_Truck_Records (Fueldate intentionally NOT selected) ──
// DISTINCT collapses the one-row-per-fuel-record duplication once Fueldate is dropped.
$dtFrom = vl_date($_GET['date_from'] ?? '');
$dtTo   = vl_date($_GET['date_to'] ?? '');
if ($dtFrom !== '' && $dtTo !== '' && $dtFrom > $dtTo) { [$dtFrom, $dtTo] = [$dtTo, $dtFrom]; }
$openTable = (($_GET['tab'] ?? '') === 'table') || $dtFrom !== '' || $dtTo !== '';
$fwhere = []; $fparams = [];
if ($dtFrom !== '') { $fwhere[] = 'Fueldate >= CAST(? AS DATE)'; $fparams[] = $dtFrom; }
if ($dtTo !== '')   { $fwhere[] = 'Fueldate < DATEADD(day, 1, CAST(? AS DATE))'; $fparams[] = $dtTo; }
$fwhere = $fwhere ? 'WHERE ' . implode(' AND ', $fwhere) : '';
$fuelRows = []; $fuelError = '';
try {
    $fsql = "SELECT DISTINCT PlateNumber, Vehicletype, Department, Tiresize,
                    CAST(Description AS NVARCHAR(MAX)) AS Description,
                    FuelType, Brand, Model, [Year], Transmission, Wheeler, Category,OR_Path,CR_Path
             FROM dbo.View_Fuel_Truck_Records $fwhere
             ORDER BY Department, PlateNumber";
    $fst = $pdo->prepare($fsql); $fst->execute($fparams);
    $fuelRows = $fst->fetchAll(PDO::FETCH_ASSOC);
    $fuelRows = array_map(fn($r) => array_map(fn($v) => trim((string)$v), $r), $fuelRows);
} catch (Throwable $e) {
    $fuelError = $e->getMessage();
}
$fopts = [];
foreach (['Vehicletype', 'Department', 'FuelType', 'Brand', 'Category'] as $c) {
    $fopts[$c] = array_values(array_unique(array_filter(array_column($fuelRows, $c), fn($v) => $v !== '')));
    sort($fopts[$c], SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vehicle List</title>
<link rel="icon" type="image/png" href="<?= base_url('assets/img/logo.png') ?>">
<link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link href="<?= base_url('assets/css/fuel.css') ?>" rel="stylesheet">
<link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
.vl-card{cursor:pointer;transition:transform .12s,box-shadow .12s;height:100%}
.vl-card:hover{transform:translateY(-2px);box-shadow:0 .5rem 1rem rgba(0,0,0,.12)}
.vl-thumb{aspect-ratio:4/3;background:#f1f3f5;display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:.375rem .375rem 0 0}
.vl-thumb img{width:100%;height:100%;object-fit:cover}
.vl-thumb .bi{font-size:3rem;color:#adb5bd}
.vl-inactive{opacity:.6}
.vl-detail dt{font-weight:600;color:#6c757d;font-size:.8rem;text-transform:uppercase}
.vl-detail dd{margin-bottom:.6rem}
.vl-modal-img{max-height:320px;width:100%;object-fit:contain;background:#f1f3f5;border-radius:.375rem}
.vt-table th{white-space:nowrap;font-size:.8rem;text-transform:uppercase;color:#6c757d}
.vt-table td{font-size:.9rem}
</style>
</head>
<body>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>
<div class="content">
<div class="container-fluid py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="mb-0"><i class="bi bi-truck me-2"></i>Vehicle List</h4>
      <small class="text-muted"><?= (int)$activeCount ?> active of <?= count($vehicles) ?> vehicles</small>
    </div>
    <?php if ($canEdit): ?>
      <button class="btn btn-primary" id="vlAddBtn"><i class="bi bi-plus-lg me-1"></i>Add Vehicle</button>
    <?php endif; ?>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash[0]) ?> alert-dismissible fade show">
      <?= htmlspecialchars($flash[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
  <?php if ($loadError): ?>
    <div class="alert alert-danger">Failed to load vehicles: <?= htmlspecialchars($loadError) ?></div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#vlPaneCards" type="button" role="tab"><i class="bi bi-grid-3x3-gap me-1"></i>Vehicle Cards</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vlPaneTable" type="button" role="tab"><i class="bi bi-table me-1"></i>Vehicle Table</button></li>
  </ul>
  <div class="tab-content">
  <div class="tab-pane fade show active" id="vlPaneCards" role="tabpanel">
  <div class="row g-2 mb-3">
    <div class="col-md-3"><input id="vlSearch" class="form-control" placeholder="Search plate, brand, model, department..."></div>
    <div class="col-md-2">
      <select id="vlDept" class="form-select">
        <option value="">All Departments</option>
        <option value="__none__">— No Department —</option>
        <?php foreach ($opts['Department'] as $d): ?><option><?= htmlspecialchars($d) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select id="vlType" class="form-select">
        <option value="">All Types</option>
        <?php foreach ($opts['Vehicletype'] as $o): ?><option><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select id="vlFuel" class="form-select">
        <option value="">All Fuel</option>
        <?php foreach ($opts['FuelType'] as $o): ?><option><?= htmlspecialchars($o) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select id="vlStatus" class="form-select">
        <option value="1">Active</option><option value="0">Inactive</option><option value="">All</option>
      </select>
    </div>
    <div class="col-md-1 d-flex align-items-center text-muted small" id="vlCount"></div>
  </div>

  <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-6 g-3" id="vlGrid">
    <?php foreach ($vehicles as $v):
        $title = trim(($v['Brand'] ?? '') . ' ' . ($v['Model'] ?? '') . ' ' . ($v['Year'] ?? ''));
        $hay = strtolower(implode(' ', [$v['PlateNumber'], $v['Brand'], $v['Model'], $v['Department'], $v['Vehicletype'], $v['Category'], $v['Description'], $v['OR_Path'], $v['CR_Path']]));
    ?>
      <div class="col vl-item" data-id="<?= (int)$v['VehicleID'] ?>" data-dept="<?= htmlspecialchars((string)$v['Department']) ?>" data-active="<?= (int)$v['Active'] ?>" data-type="<?= htmlspecialchars((string)$v['Vehicletype']) ?>" data-fuel="<?= htmlspecialchars((string)$v['FuelType']) ?>" data-hay="<?= htmlspecialchars($hay) ?>">
        <div class="card vl-card <?= $v['Active'] ? '' : 'vl-inactive' ?>" style="border-top:4px solid <?= vl_dept_color($v['Department']) ?>">
          <div class="vl-thumb">
            <?php if ($v['img1']): ?>
              <img src="<?= htmlspecialchars($v['img1']) ?>" data-fallback="<?= htmlspecialchars($v['img2']) ?>" alt="">
            <?php else: ?><i class="bi bi-truck"></i><?php endif; ?>
          </div>
          <div class="card-body p-2">
            <div class="fw-bold"><?= htmlspecialchars((string)$v['PlateClean']) ?></div>
            <div class="small text-muted text-truncate"><?= htmlspecialchars($title ?: '—') ?></div>
            <div class="mt-1 d-flex flex-wrap gap-1">
              <?php if ($v['Department']): ?><span class="badge" style="background:<?= vl_dept_color($v['Department']) ?>;color:<?= vl_dept_fg(vl_dept_color($v['Department'])) ?>"><?= htmlspecialchars($v['Department']) ?></span><?php endif; ?>
              <span class="badge <?= $v['Active'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= $v['Active'] ? 'Active' : 'Inactive' ?></span>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div id="vlEmpty" class="text-center text-muted py-5 d-none">No vehicles found.</div>
  </div><!-- /vlPaneCards -->

  <!-- Table tab -->
  <div class="tab-pane fade" id="vlPaneTable" role="tabpanel">
    <?php if ($fuelError): ?>
      <div class="alert alert-danger">Failed to load vehicle table: <?= htmlspecialchars($fuelError) ?></div>
    <?php endif; ?>
    <div class="row g-2 mb-3">
      <?php foreach ([['vtType','Vehicletype','All Types'],['vtDept','Department','All Departments'],['vtFuel','FuelType','All Fuel Types'],['vtBrand','Brand','All Brands'],['vtCat','Category','All Categories']] as [$sid, $scol, $slbl]): ?>
        <div class="col-6 col-md-4 col-lg-2">
          <select id="<?= $sid ?>" class="form-select">
            <option value=""><?= $slbl ?></option>
            <?php if ($sid === 'vtDept'): ?><option value="__none__">— No Department —</option><?php endif; ?>
            <?php foreach ($fopts[$scol] as $so): ?><option value="<?= htmlspecialchars($so) ?>"><?= htmlspecialchars($so) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="get" class="row g-2 align-items-end mb-3" id="vtDateForm">
      <input type="hidden" name="tab" value="table">
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0">Fuel Date From</label><input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dtFrom) ?>"></div>
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0">Fuel Date To</label><input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dtTo) ?>"></div>
      <div class="col-auto"><button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button></div>
    </form>
    <div class="table-section">
    <div class="table-header">
      <div class="table-title">📋 Vehicle Table <span class="table-count" id="vtCount"></span></div>
      <div class="table-actions">
        <input type="text" class="search-box" id="vtSearch" placeholder="🔍 Search plate, brand, model...">
        <button type="button" class="btn-print" id="vtClear"><i class="bi bi-x-circle"></i> Clear</button>
        <button type="button" class="btn-export" id="vtExport"><i class="bi bi-download"></i> CSV</button>
        <button type="button" class="btn-print" id="vtPrint"><i class="bi bi-printer"></i> Print</button>
      </div>
    </div>
    <div class="table-scroll">
      <table id="mainTable">
        <thead id="vtHead">
          <tr>
            <th>Plate Number</th><th>Department</th><th>Category</th><th>Brand</th><th>Model</th><th>Year</th>
            <th>Vehicle Type</th><th>Fuel Type</th><th>Transmission</th><th>Wheeler</th><th>Tire Size</th><th>Description</th><th>OR</th><th>CR</th>
          </tr>
        </thead>
        <tbody id="vtBody">
          <?php foreach ($fuelRows as $fr):
              $fhay = strtolower(implode(' ', [$fr['PlateNumber'], $fr['Department'], $fr['Category'], $fr['Brand'], $fr['Model'], $fr['Year'], $fr['Vehicletype'], $fr['FuelType'], $fr['Transmission'], $fr['Wheeler'], $fr['Tiresize'], $fr['Description'],$fr['OR_Path'],$fr['CR_Path']]));
              $fbg = vl_dept_color($fr['Department']);
          ?>
            <tr class="vt-row" data-type="<?= htmlspecialchars($fr['Vehicletype']) ?>" data-dept="<?= htmlspecialchars($fr['Department']) ?>" data-fuel="<?= htmlspecialchars($fr['FuelType']) ?>" data-brand="<?= htmlspecialchars($fr['Brand']) ?>" data-cat="<?= htmlspecialchars($fr['Category']) ?>" data-hay="<?= htmlspecialchars($fhay) ?>">
              <td><span class="plate"><?= htmlspecialchars($fr['PlateNumber']) ?></span></td>
              <td><?php if ($fr['Department'] !== ''): ?><span class="badge" style="background:<?= $fbg ?>;color:<?= vl_dept_fg($fbg) ?>"><?= htmlspecialchars($fr['Department']) ?></span><?php endif; ?></td>
              <?php foreach (['Category', 'Brand', 'Model', 'Year', 'Vehicletype', 'FuelType', 'Transmission', 'Wheeler', 'Tiresize', 'Description', 'OR_Path', 'CR_Path'] as $fk): ?>
              <td class="dim"><?= htmlspecialchars($fr[$fk]) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="vtEmpty" class="empty-state d-none"><span class="icon">📭</span><p>No records found. Try adjusting the filters.</p></div>
    <div class="footer">Vehicle List · Tradewell Fleet Monitoring System · Generated <?= date('Y-m-d H:i:s') ?></div>
    </div><!-- /table-section -->
  </div><!-- /vlPaneTable -->
  </div><!-- /tab-content -->
</div>
</div>

<!-- Detail modal -->
<div class="modal fade" id="vlDetailModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="vlDTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-5" id="vlDImgWrap"></div>
          <div class="col-md-7"><dl class="vl-detail row mb-0" id="vlDList"></dl></div>
        </div>
      </div>
      <div class="modal-footer">
        <?php if ($canEdit): ?><button class="btn btn-outline-primary" id="vlEditBtn"><i class="bi bi-pencil me-1"></i>Edit</button><?php endif; ?>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
<!-- Add / Edit modal -->
<div class="modal fade" id="vlFormModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post" enctype="multipart/form-data" id="vlForm">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['vl_csrf']) ?>">
      <input type="hidden" name="VehicleID" id="f_VehicleID" value="0">
      <div class="modal-header">
        <h5 class="modal-title" id="vlFTitle">Add Vehicle</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Plate Number *</label><input class="form-control" name="PlateNumber" id="f_PlateNumber" required maxlength="50"></div>
          <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" name="Department" id="f_Department" list="dl_Department"></div>
          <div class="col-md-4"><label class="form-label">Category</label><input class="form-control" name="Category" id="f_Category" list="dl_Category"></div>
          <div class="col-md-4"><label class="form-label">Brand</label><input class="form-control" name="Brand" id="f_Brand"></div>
          <div class="col-md-4"><label class="form-label">Model</label><input class="form-control" name="Model" id="f_Model"></div>
          <div class="col-md-4"><label class="form-label">Year</label><input class="form-control" name="Year" id="f_Year" maxlength="4"></div>
          <div class="col-md-4"><label class="form-label">Vehicle Type</label><input class="form-control" name="Vehicletype" id="f_Vehicletype" list="dl_Vehicletype"></div>
          <div class="col-md-4"><label class="form-label">Fuel Type</label><input class="form-control" name="FuelType" id="f_FuelType" list="dl_FuelType"></div>
          <div class="col-md-4"><label class="form-label">Transmission</label><input class="form-control" name="Transmission" id="f_Transmission" list="dl_Transmission"></div>
          <div class="col-md-3"><label class="form-label">Wheeler</label><input class="form-control" name="Wheeler" id="f_Wheeler"></div>
          <div class="col-md-3"><label class="form-label">Capacity Weight</label><input class="form-control" name="Capacityweight" id="f_Capacityweight"></div>
          <div class="col-md-3"><label class="form-label">Tire Size</label><input class="form-control" name="Tiresize" id="f_Tiresize"></div>
          <div class="col-md-3"><label class="form-label">Sort</label><input class="form-control" name="Sort" id="f_Sort"></div>
          <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="Description" id="f_Description" rows="2"></textarea></div>
          <div class="col-md-6"><label class="form-label">Picture <small class="text-muted">(JPG/PNG/WEBP, max 5 MB — leave empty to keep current)</small></label><input type="file" class="form-control" name="Picture" accept="image/jpeg,image/png,image/webp"></div>
          <div class="col-md-6"><label class="form-label">Picture (Back) <small class="text-muted">(JPG/PNG/WEBP, max 5 MB — leave empty to keep current)</small></label><input type="file" class="form-control" name="PicBack_File" accept="image/jpeg,image/png,image/webp"></div>
          <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="Active" id="f_Active" value="1" checked><label class="form-check-label" for="f_Active">Active</label></div></div>
          <div class="col-md-6"><label class="form-label">OR <small class="text-muted">(JPG/PNG/WEBP/PDF, max 5 MB — leave empty to keep current)</small></label><input type="file" class="form-control" name="OR_File" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
          <div class="col-md-6"><label class="form-label">CR <small class="text-muted">(JPG/PNG/WEBP/PDF, max 5 MB — leave empty to keep current)</small></label><input type="file" class="form-control" name="CR_File" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
        </div>
        <?php foreach (['Department', 'Vehicletype', 'FuelType', 'Transmission', 'Category'] as $c): ?>
          <datalist id="dl_<?= $c ?>"><?php foreach ($opts[$c] as $o): ?><option value="<?= htmlspecialchars($o) ?>"><?php endforeach; ?></datalist>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const VEH = <?= json_encode($vehicles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
const VBY = {}; VEH.forEach(v => VBY[v.VehicleID] = v);
const CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
let curId = null;

// ── Image fallback (no inline onerror → no attribute-quoting issues) ──
function vlBindImg(img) {
  const fail = () => {
    const fb = img.dataset.fallback;
    if (fb && !img.dataset.tried) { img.dataset.tried = '1'; img.src = fb; return; }
    const i = document.createElement('i'); i.className = 'bi bi-truck';
    img.replaceWith(i);
  };
  img.addEventListener('error', fail);
  if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) fail();
}
document.querySelectorAll('.vl-thumb img').forEach(vlBindImg);

// ── Filters ──
function vlFilter() {
  const q = document.getElementById('vlSearch').value.trim().toLowerCase();
  const d = document.getElementById('vlDept').value;
   const s = document.getElementById('vlStatus').value;
  const t = document.getElementById('vlType').value;
  const fu = document.getElementById('vlFuel').value;
  let n = 0;
  document.querySelectorAll('.vl-item').forEach(el => {
    const ok = (!q || el.dataset.hay.includes(q)) && (!d || (d === '__none__' ? el.dataset.dept.trim() === '' : el.dataset.dept === d)) && (!t || el.dataset.type === t) && (!fu || el.dataset.fuel === fu) && (s === '' || el.dataset.active === s);
    el.classList.toggle('d-none', !ok); if (ok) n++;
  });
  document.getElementById('vlCount').textContent = n + ' shown';
  document.getElementById('vlEmpty').classList.toggle('d-none', n > 0);
}
['vlSearch', 'vlDept', 'vlType', 'vlFuel', 'vlStatus'].forEach(id => document.getElementById(id).addEventListener('input', vlFilter));
vlFilter();

// ── Table tab: filters / print / export ──
const VT_DATES = <?= ($dtFrom !== '' || $dtTo !== '') ? 'true' : 'false' ?>;
const VT_RANGE = <?= json_encode(trim(($dtFrom !== '' ? 'from ' . $dtFrom : '') . ($dtTo !== '' ? ' to ' . $dtTo : ''))) ?>;
if (<?= $openTable ? 'true' : 'false' ?>) bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#vlPaneTable"]')).show();
const vtTotal = document.querySelectorAll('#vtBody .vt-row').length;
function vtVisible() { return [...document.querySelectorAll('#vtBody .vt-row:not(.d-none)')]; }
function vtFilter() {
  const g = id => document.getElementById(id).value;
  const q = g('vtSearch').trim().toLowerCase(), t = g('vtType'), d = g('vtDept'), fu = g('vtFuel'), b = g('vtBrand'), c = g('vtCat');
  let n = 0;
  document.querySelectorAll('#vtBody .vt-row').forEach(tr => {
    const s = tr.dataset;
    const ok = (!q || s.hay.includes(q)) && (!t || s.type === t) && (!d || (d === '__none__' ? s.dept === '' : s.dept === d)) && (!fu || s.fuel === fu) && (!b || s.brand === b) && (!c || s.cat === c);
    tr.classList.toggle('d-none', !ok); if (ok) n++;
  });
  document.getElementById('vtCount').textContent = n + ' of ' + vtTotal + ' shown';
  document.getElementById('vtEmpty').classList.toggle('d-none', n > 0);
  document.getElementById('vtPrint').disabled = (n === 0);
  document.getElementById('vtExport').disabled = (n === 0);
}
['vtSearch', 'vtType', 'vtDept', 'vtFuel', 'vtBrand', 'vtCat'].forEach(id => document.getElementById(id).addEventListener('input', vtFilter));
vtFilter();

document.getElementById('vtClear').addEventListener('click', () => {
  ['vtSearch', 'vtType', 'vtDept', 'vtFuel', 'vtBrand', 'vtCat'].forEach(id => document.getElementById(id).value = '');
  document.querySelectorAll('#vtDateForm input[type=date]').forEach(i => i.value = '');
  if (VT_DATES) { location.href = 'vehicle_list.php?tab=table'; return; }
  vtFilter();
});

document.getElementById('vtExport').addEventListener('click', () => {
  const rows = vtVisible(); if (!rows.length) return;
  const cell = s => {
    s = String(s == null ? '' : s).replace(/\r?\n/g, ' ').trim();
    if (/^[=+\-@]/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
  };
  const lines = [[...document.querySelectorAll('#vtHead th')].map(th => cell(th.textContent)).join(',')];
  rows.forEach(tr => lines.push([...tr.children].map(td => cell(td.textContent)).join(',')));
  const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'vehicle_list_' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
});

document.getElementById('vtPrint').addEventListener('click', () => {
  const rows = vtVisible(); if (!rows.length) return;
  const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const head = [...document.querySelectorAll('#vtHead th')].map(th => '<th>' + esc(th.textContent) + '</th>').join('');
  const body = rows.map(tr => '<tr>' + [...tr.children].map(td => '<td>' + esc(td.textContent.trim()) + '</td>').join('') + '</tr>').join('');
  const fl = [['Search', 'vtSearch'], ['Type', 'vtType'], ['Department', 'vtDept'], ['Fuel', 'vtFuel'], ['Brand', 'vtBrand'], ['Category', 'vtCat']]
    .map(([l, id]) => { let v = document.getElementById(id).value.trim(); if (v === '__none__') v = 'No Department'; return v ? l + ': ' + v : ''; }).filter(Boolean);
  const meta = 'Printed ' + new Date().toLocaleString() + ' | ' + rows.length + ' vehicle(s)' + (VT_RANGE ? ' | Fuel date ' + VT_RANGE : '') + (fl.length ? ' | ' + fl.join(', ') : '');
  const w = window.open('', '_blank');
  if (!w) { alert('Please allow pop-ups to print.'); return; }
  w.document.open();
  w.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Vehicle List</title>
<style>
@page{size:landscape;margin:10mm}
body{font-family:Arial,sans-serif;font-size:11px;color:#000}
h2{margin:0 0 2px}
.meta{color:#555;margin-bottom:8px}
table{border-collapse:collapse;width:100%}
th,td{border:1px solid #999;padding:3px 5px;text-align:left;vertical-align:top}
th{background:#eee}
thead{display:table-header-group}
tr{page-break-inside:avoid}
</style></head><body>
<h2>Vehicle List</h2>
<div class="meta">${esc(meta)}</div>
<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
</body></html>`);
  w.document.close(); w.focus();
  setTimeout(() => w.print(), 300);
});

// ── Detail modal ──
const detailModal = new bootstrap.Modal('#vlDetailModal');
const LABELS = [['Department','Department'],['Category','Category'],['Brand','Brand'],['Model','Model'],['Year','Year'],
  ['Vehicletype','Vehicle Type'],['FuelType','Fuel Type'],['Transmission','Transmission'],['Wheeler','Wheeler'],
  ['Capacityweight','Capacity Weight'],['Tiresize','Tire Size'],['Sort','Sort'],['Description','Description'],
  ['DateTimeInput','Date Added'],['UserID','Encoded By']];

function vlOpenDetail(id) {
  const v = VBY[id]; if (!v) return; curId = id;
  document.getElementById('vlDTitle').textContent = v.PlateClean + (v.Brand ? ' — ' + v.Brand + ' ' + (v.Model || '') : '');
  const wrap = document.getElementById('vlDImgWrap'); wrap.replaceChildren();
  if (v.img1) {
    const img = document.createElement('img'); img.className = 'vl-modal-img'; img.src = v.img1; img.dataset.fallback = v.img2 || '';
    wrap.appendChild(img); vlBindImg(img);
  } else {
    wrap.innerHTML = '<div class="vl-modal-img d-flex align-items-center justify-content-center py-5"><i class="bi bi-truck display-1 text-secondary"></i></div>';
  }
  const dl = document.getElementById('vlDList'); dl.replaceChildren();
  const add = (label, val, badge) => {
    const dt = document.createElement('dt'); dt.className = 'col-5'; dt.textContent = label;
    const dd = document.createElement('dd'); dd.className = 'col-7';
    if (badge) { const b = document.createElement('span'); b.className = 'badge ' + badge[0]; b.textContent = badge[1]; dd.appendChild(b); }
    else dd.textContent = (val === null || val === '') ? '—' : val;
    dl.append(dt, dd);
  };
  add('Status', null, v.Active == 1 ? ['text-bg-success', 'Active'] : ['text-bg-danger', 'Inactive']);
  LABELS.forEach(([k, l]) => add(l, v[k]));
  detailModal.show();
}
document.getElementById('vlGrid').addEventListener('click', e => {
  const it = e.target.closest('.vl-item'); if (it) vlOpenDetail(it.dataset.id);
});

// ── Add / Edit ──
if (CAN_EDIT) {
  const formModal = new bootstrap.Modal('#vlFormModal');
  const FIELDS = ['PlateNumber','Department','Category','Brand','Model','Year','Vehicletype','FuelType','Transmission','Wheeler','Capacityweight','Tiresize','Sort','Description','OR_Path','CR_Path'];
  function vlOpenForm(v) {
    document.getElementById('vlForm').reset();
    document.getElementById('f_VehicleID').value = v ? v.VehicleID : 0;
    document.getElementById('vlFTitle').textContent = v ? 'Edit Vehicle' : 'Add Vehicle';
    FIELDS.forEach(k => document.getElementById('f_' + k).value = v && v[k] != null ? v[k] : '');
    document.getElementById('f_Active').checked = v ? v.Active == 1 : true;
    formModal.show();
  }
  document.getElementById('vlAddBtn').addEventListener('click', () => vlOpenForm(null));
  document.getElementById('vlEditBtn').addEventListener('click', () => {
    detailModal.hide();
    document.getElementById('vlDetailModal').addEventListener('hidden.bs.modal', () => vlOpenForm(VBY[curId]), { once: true });
  });
}
</script>
</body>
</html>