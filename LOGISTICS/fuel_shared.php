<?php
/**
 * fuel_shared.php
 * ─────────────────────────────────────────────────────────────
 * Shared bootstrap for ALL Fuel Dashboard pages.
 * Include this file right after auth_check().
 *
 * Provides:
 *  - Filter variables  ($dateFrom, $dateTo, $selDept …)
 *  - Date range        ($baseFrom, $baseTo)
 *  - WHERE snippets    ($deptWhere, $filterSQL …)
 *  - Stat-card data    ($totalTrucks, $totalLiters …)
 *  - Lookup lists      ($deptList, $vtypeList, $plateList)
 *  - Helper functions  (fmt, peso, deptBadge, rankBadge, …)
 *  - URL helpers       (tabUrl, fcUrl)
 * ─────────────────────────────────────────────────────────────
 */

// ============================================================
// VEHICLE CLASSIFICATION
// ============================================================
$TRUCK_TYPES = "'ELF','CANTER','FORWARD','FIGHTER'";
$CAR_TYPES   = "'CAR','MOTOR','L300','VAN','CROSS WIND'";
$ALL_TYPES   = "'ELF','CANTER','FORWARD','FIGHTER','CAR','MOTOR','L300','VAN','CROSS WIND'";

$mode      = 'all';
$modeTypes = $ALL_TYPES;
$modeVtypeWhere         = "AND v.Vehicletype IN ($modeTypes)";
$modeVtypeWhereNullable = "AND (v.Vehicletype IS NULL OR v.Vehicletype IN ($modeTypes))";

// ============================================================
// DATE FILTER
// ============================================================
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = '';

$dateActive = ($dateFrom !== '' || $dateTo !== '');

$anyFilterApplied = ($dateFrom !== '' || $dateTo !== ''
    || (isset($_GET['dept'])   && $_GET['dept']   !== '')
    || (isset($_GET['vtype'])  && $_GET['vtype']  !== '')
    || (isset($_GET['plate'])  && $_GET['plate']  !== '')
    || (isset($_GET['driver']) && $_GET['driver'] !== '')
    || (isset($_GET['area'])   && $_GET['area']   !== ''));

if ($dateActive) {
    $baseFrom = $dateFrom !== '' ? $dateFrom : '1900-01-01';
    $baseTo   = $dateTo   !== '' ? $dateTo   : date('Y-m-d');
} elseif ($anyFilterApplied) {
    $baseFrom = '1900-01-01';
    $baseTo   = date('Y-m-d');
} else {
    $baseFrom = date('Y-m-01');
    $baseTo   = date('Y-m-d');
}

// ============================================================
// DEPARTMENT FILTER
// ============================================================
$selDept    = isset($_GET['dept']) && $_GET['dept'] !== '' ? trim($_GET['dept']) : ($_SESSION['Department'] ?? '');
$deptActive = ($selDept !== '');
$_selDeptSafe = str_replace("'", "''", $selDept);
$deptWhere  = $deptActive ? "AND v.Department = '$_selDeptSafe'" : '';
$deptWhereF = $deptActive ? "AND ts.Department = '$_selDeptSafe'" : '';
$deptWhereFuel = $deptActive ? "AND (
    NULLIF(f.Department,'') = '$_selDeptSafe'
    OR (NULLIF(f.Department,'') IS NULL AND EXISTS (
        SELECT 1 FROM [dbo].[TruckSchedule] tsx
        WHERE tsx.PlateNumber = f.PlateNumber
          AND tsx.Department = '$_selDeptSafe'
    ))
)" : '';

// ============================================================
// VEHICLE TYPE FILTER
// ============================================================
$selVtype    = isset($_GET['vtype']) && $_GET['vtype'] !== '' ? trim($_GET['vtype']) : '';
$vtypeActive = ($selVtype !== '');
$_selVtypeSafe = str_replace("'", "''", $selVtype);
$vtypeWhere  = $vtypeActive ? "AND v.Vehicletype = '$_selVtypeSafe'" : '';
$vtypeWhereF = $vtypeActive ? "AND v.Vehicletype = '$_selVtypeSafe'" : '';

// ============================================================
// PLATE FILTER
// ============================================================
$selPlate    = isset($_GET['plate']) && $_GET['plate'] !== '' ? trim($_GET['plate']) : '';
$plateActive = ($selPlate !== '');
$_plateSafe  = str_replace("'", "''", $selPlate);
$plateWhereF = $plateActive ? "AND ts.PlateNumber LIKE '%$_plateSafe%'" : '';
$plateWhereR = $plateActive ? "AND f.PlateNumber LIKE '%$_plateSafe%'" : '';

// ============================================================
// DRIVER FILTER
// ============================================================
$selDriver    = isset($_GET['driver']) && $_GET['driver'] !== '' ? trim($_GET['driver']) : '';
$driverActive = ($selDriver !== '');
$_driverSafe  = str_replace("'", "''", $selDriver);
$driverWhereF = $driverActive ? "AND EXISTS (SELECT 1 FROM [dbo].[teamschedule] td2 WHERE td2.PlateNumber = ts.PlateNumber AND td2.ScheduleDate = ts.ScheduleDate AND td2.Position LIKE '%DRIVER%' AND td2.Employee_Name LIKE '%$_driverSafe%')" : '';
$driverWhereR = $driverActive ? "AND f.Requested LIKE '%$_driverSafe%'" : '';

// ============================================================
// AREA FILTER
// ============================================================
$selArea    = isset($_GET['area']) && $_GET['area'] !== '' ? trim($_GET['area']) : '';
$areaActive = ($selArea !== '');
$_areaSafe  = str_replace("'", "''", $selArea);
$areaWhereF = $areaActive ? "AND ts.Area LIKE '%$_areaSafe%'" : '';
$areaWhereR = $areaActive ? "AND f.Area LIKE '%$_areaSafe%'" : '';

$anyFilter = $dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive;

// ============================================================
// BUILD $filterSQL ONCE
// ============================================================
$filterSQL = '';
if ($dateFrom !== '' && $dateTo !== '') {
    $filterSQL .= " AND f.Fueldate BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($dateFrom !== '') {
    $filterSQL .= " AND f.Fueldate >= '$dateFrom'";
} elseif ($dateTo !== '') {
    $filterSQL .= " AND f.Fueldate <= '$dateTo'";
}
if ($selVtype !== '') $filterSQL .= " AND v.Vehicletype = '$_selVtypeSafe'";
if ($selPlate !== '') $filterSQL .= " AND f.PlateNumber LIKE '%$_plateSafe%'";
if ($selDriver !== '') $filterSQL .= " AND f.Requested LIKE '%$_driverSafe%'";
if ($selArea !== '')   $filterSQL .= " AND f.Area LIKE '%$_areaSafe%'";

// ============================================================
// MONTH / YEAR PARAMS (used by fuel_monthly)
// ============================================================
$selYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$selMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear  = max(2025, min((int)date('Y'), $selYear));
$selMonth = max(1, min(12, $selMonth));

$fcYear  = isset($_GET['fc_year'])  ? max(2020, min((int)date('Y'), (int)$_GET['fc_year']))  : (int)date('Y');
$fcMonth = isset($_GET['fc_month']) ? max(1,    min(12,              (int)$_GET['fc_month'])) : (int)date('m');

$months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

// ============================================================
// HELPER: RUN QUERY
// ============================================================
function runQuery($conn, $sql, $params = []) {
    $stmt = empty($params) ? sqlsrv_query($conn, $sql) : sqlsrv_query($conn, $sql, $params);
    if (!$stmt) return [];
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function fmt($n, $dec = 2) { return $n !== null ? number_format((float)$n, $dec) : '—'; }
function peso($n)          { return $n !== null ? '₱' . number_format((float)$n, 2) : '—'; }

function deptBadge($dept) {
    $map = [
        'monde'      => 'background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #fca5a5;',
        'century'    => 'background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid #93c5fd;',
        'multilines' => 'background:rgba(234,179,8,.15);color:#ca8a04;border:1px solid #fde047;',
        'nutriasia'  => 'background:rgba(16,185,129,.15);color:#059669;border:1px solid #6ee7b7;',
    ];
    $style = $map[strtolower(trim($dept ?? ''))] ?? 'background:rgba(107,114,128,.15);color:#6b7280;border:1px solid #9ca3af;';
    $label = htmlspecialchars($dept ?: '—');
    return "<span class='dept' style='$style;padding:.15rem .5rem;border-radius:20px;font-size:.7rem;font-weight:700;letter-spacing:.03em;'>$label</span>";
}

function rankBadge($r) {
    $cls = $r <= 3 ? "rank-$r" : "";
    return "<span class='rank $cls'>$r</span>";
}

function flagBadge($f) {
    switch ($f) {
        case 'CRITICAL': return "<span class='badge badge-critical'>🔴 Critical</span>";
        case 'HIGH':     return "<span class='badge badge-high'>🟠 High</span>";
        default:         return "<span class='badge badge-watch'>🟡 Watch</span>";
    }
}

function progressBar($pct) {
    $pct = (float)$pct;
    $cls = $pct < 30 ? 'crit' : ($pct < 60 ? 'low' : '');
    return "<div class='progress-wrap'>
        <div class='progress-bar'><div class='progress-fill $cls' style='width:{$pct}%'></div></div>
        <div class='progress-pct'>{$pct}%</div></div>";
}

// ============================================================
// STAT CARDS  (called from each page)
// ============================================================
function loadStatCards($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL) {
    $statRow = runQuery($conn, "
        SELECT
            COUNT(DISTINCT f.PlateNumber) AS TotalTrucks,
            ROUND(SUM(f.Liters),2)        AS TotalLiters,
            ROUND(SUM(f.Amount),2)        AS TotalAmount,
            COUNT(f.FuelID)               AS TotalRefuels
        FROM [dbo].[Tbl_fuel] f
        LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
        WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
          $modeVtypeWhere $deptWhereFuel $filterSQL");

    return [
        'trucks'  => $statRow[0]['TotalTrucks']  ?? 0,
        'liters'  => $statRow[0]['TotalLiters']  ?? 0,
        'amount'  => $statRow[0]['TotalAmount']  ?? 0,
        'refuels' => $statRow[0]['TotalRefuels'] ?? 0,
    ];
}

function loadAnomalyCount($conn, $baseFrom, $baseTo, $modeVtypeWhere, $deptWhereFuel, $filterSQL) {
    $row = runQuery($conn, "
        WITH DateRange AS (SELECT DATEDIFF(DAY,'$baseFrom','$baseTo') + 1 AS TotalDays),
        AllRecords AS (
            SELECT f.FuelID, f.PlateNumber, f.Fueldate, f.Area, ROUND(f.Liters,2) AS Liters
            FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[TruckSchedule] ts ON ts.PlateNumber = f.PlateNumber AND ts.ScheduleDate = f.Fueldate
            LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
            WHERE f.Fueldate BETWEEN '$baseFrom' AND '$baseTo'
              AND f.Area IS NOT NULL AND f.Liters IS NOT NULL
              $modeVtypeWhere $deptWhereFuel $filterSQL
        ),
        TruckBaseline AS (
            SELECT PlateNumber, Area, COUNT(FuelID) AS TotalRefuels, ROUND(AVG(Liters),2) AS TruckAvgLiters
            FROM AllRecords GROUP BY PlateNumber, Area HAVING COUNT(FuelID) >= 2
        ),
        TruckBracket AS (
            SELECT tb.*,
                CASE WHEN tb.TotalRefuels/NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0)>=15 THEN 'HIGH'
                     WHEN tb.TotalRefuels/NULLIF((SELECT CAST(TotalDays AS FLOAT)/30 FROM DateRange),0)>=4 THEN 'MID'
                     ELSE 'LOW' END AS FreqBracket
            FROM TruckBaseline tb
        ),
        BracketAreaAvg AS (
            SELECT tb.Area, tb.FreqBracket, ROUND(AVG(tb.TruckAvgLiters),2) AS BracketAreaAvg
            FROM TruckBracket tb GROUP BY tb.Area, tb.FreqBracket
        )
        SELECT COUNT(*) AS cnt
        FROM AllRecords ar
        INNER JOIN TruckBracket tb ON tb.PlateNumber = ar.PlateNumber AND tb.Area = ar.Area
        INNER JOIN BracketAreaAvg ba ON ba.Area = ar.Area AND ba.FreqBracket = tb.FreqBracket
        WHERE ((ar.Liters-tb.TruckAvgLiters)/NULLIF(tb.TruckAvgLiters,0))>0.5
           OR ((ar.Liters-ba.BracketAreaAvg)/NULLIF(ba.BracketAreaAvg,0))>0.5");
    return $row[0]['cnt'] ?? 0;
}

// ============================================================
// LOOKUP LISTS
// ============================================================
function loadLookups($conn, $selVtype, $_selVtypeSafe) {
    $deptList  = runQuery($conn, "SELECT DISTINCT Department FROM [dbo].[Vehicle] WHERE Active = 1 AND Department IS NOT NULL ORDER BY Department");
    $vtypeList = runQuery($conn, "
        SELECT DISTINCT Vehicletype FROM [dbo].[Vehicle]
        WHERE Active = 1
          AND Vehicletype IN ('CANTER','ELF','FIGHTER','FORWARD','L300','CAR','MOTOR','CROSS WIND','VAN')
        ORDER BY Vehicletype");

    if ($selVtype !== '') {
        $plateList = runQuery($conn, "
            SELECT DISTINCT f.PlateNumber FROM [dbo].[Tbl_fuel] f
            LEFT JOIN [dbo].[Vehicle] v ON v.PlateNumber = f.PlateNumber
            WHERE v.Vehicletype = '$_selVtypeSafe' AND f.PlateNumber IS NOT NULL AND f.PlateNumber <> ''
            ORDER BY f.PlateNumber");
    } else {
        $plateList = runQuery($conn, "
            SELECT DISTINCT PlateNumber FROM [dbo].[Tbl_fuel]
            WHERE PlateNumber IS NOT NULL AND PlateNumber <> ''
            ORDER BY PlateNumber");
    }
    return [$deptList, $vtypeList, $plateList];
}

// ============================================================
// URL HELPERS
// ============================================================

/**
 * Map tab names → their PHP file.
 * Summary / rank tabs live in fuel_dashboard.php (the main file).
 */
function tabFile(string $tab): string {
    $map = [
        'summary'      => 'fuel_dashboard.php',
        'rank_asc'     => 'fuel_dashboard.php',
        'rank_desc'    => 'fuel_dashboard.php',
        '30day'        => 'Fuel-30Day.php',
        'area'         => 'Fuel-Area.php',
        'truck_area'   => 'Fuel-Comparison.php',
        'anomaly'      => 'Fuel-Anomaly.php',
        'checklist'    => 'Fuel-Checklist.php',
        'fuel_monthly' => 'Fuel-Consumption.php',
        'report'       => 'Fuel-Report.php',
    ];
    return $map[$tab] ?? 'fuel_dashboard.php';
}

function tabUrl(string $tab, string $dateFrom, string $dateTo, int $selYear, int $selMonth,
                string $selDept = '', string $selVtype = '', array $extraParams = []): string {
    global $selPlate, $selDriver, $selArea;
    $params        = $_GET;
    $params['tab'] = $tab;
    unset($params['page'], $params['mode']);
    if ($dateFrom  !== '') $params['date_from'] = $dateFrom; else unset($params['date_from']);
    if ($dateTo    !== '') $params['date_to']   = $dateTo;   else unset($params['date_to']);
    if ($selDept   !== '') $params['dept']       = $selDept;  else unset($params['dept']);
    if ($selVtype  !== '') $params['vtype']      = $selVtype; else unset($params['vtype']);
    if ($selPlate  !== '') $params['plate']      = $selPlate; else unset($params['plate']);
    if ($selDriver !== '') $params['driver']     = $selDriver; else unset($params['driver']);
    if ($selArea   !== '') $params['area']       = $selArea;  else unset($params['area']);
    foreach ($extraParams as $k => $v) $params[$k] = $v;
    // Build cross-file URL
    $file = tabFile($tab);
    // Remove 'tab' param for dedicated files (they don't need it), keep for main file
    if ($file !== 'fuel_dashboard.php') unset($params['tab']);
    return $file . '?' . http_build_query($params);
}

function fcUrl(int $y, int $m, string $tab = 'fuel_monthly', array $extra = []): string {
    $p = array_merge($_GET, ['fc_year' => $y, 'fc_month' => $m]);
    unset($p['page'], $p['mode'], $p['tab']);
    foreach ($extra as $k => $v) $p[$k] = $v;
    return 'Fuel-Consumption.php?' . http_build_query($p);
}

function pageUrl(int $page): string {
    $p = $_GET; $p['page'] = $page; return '?' . http_build_query($p);
}

// ============================================================
// SHARED TAB NAV RENDERER
// ============================================================
function renderTabNav(string $activeTab, string $dateFrom, string $dateTo,
                      int $selYear, int $selMonth,
                      string $selDept, string $selVtype,
                      int $fcYear, int $fcMonth): void {
    $tabs = [
        ['id' => 'summary',      'label' => '📊 Overall Summary',    'class' => ''],
        ['id' => 'rank_asc',     'label' => '📈 Low → High',         'class' => ''],
        ['id' => 'rank_desc',    'label' => '📉 High → Low',         'class' => ''],
        ['id' => '30day',        'label' => '📅 30-Day Monitor',      'class' => ''],
        ['id' => 'area',         'label' => '📍 Area Summary',        'class' => ''],
        ['id' => 'truck_area',   'label' => '📊 Fuel Comparison',     'class' => ''],
        ['id' => 'anomaly',      'label' => '🚨 Anomaly Flags',       'class' => 'danger'],
        ['id' => 'checklist',    'label' => '✅ Monthly Checklist',   'class' => 'warning'],
        ['id' => 'fuel_monthly', 'label' => '📆 Fuel Consumption',    'class' => ''],
        ['id' => 'report',       'label' => '📋 Usage Report',        'class' => ''],
    ];
    echo '<div class="tabs-wrapper">';
    foreach ($tabs as $t) {
        $extra = ($t['id'] === 'fuel_monthly') ? ['fc_year' => $fcYear, 'fc_month' => $fcMonth] : [];
        $href  = tabUrl($t['id'], $dateFrom, $dateTo, $selYear, $selMonth, $selDept, $selVtype, $extra);
        $active = ($t['id'] === $activeTab) ? 'active' : '';
        $cls    = trim($t['class'] . ' ' . $active);
        echo "<a href=\"" . htmlspecialchars($href) . "\" class=\"tab-btn $cls\">{$t['label']}</a>\n";
    }
    echo '</div>';
}

// ============================================================
// SHARED FILTER BAR RENDERER
// ============================================================
function renderFilterBar(
    string $activeTab,
    string $dateFrom, string $dateTo,
    bool $dateActive, // ✅ FIX

    string $selDept, bool $deptActive,
    string $selVtype, bool $vtypeActive,
    string $selPlate, bool $plateActive,
    string $selDriver, bool $driverActive,
    string $selArea, bool $areaActive,

    bool $anyFilterApplied,

    array $deptList,
    array $vtypeList,
    array $plateList,

    int $fcYear,
    int $fcMonth
): void {
    // Determine action URL for the form — always POST back to current page
    $formAction = htmlspecialchars($_SERVER['PHP_SELF']);
    ?>
    <div class="filter-bar-card">
      <form method="GET" id="filterForm" action="<?= $formAction ?>">
        <?php if ($activeTab === 'fuel_monthly'): ?>
        <input type="hidden" name="fc_year"  value="<?= $fcYear ?>">
        <input type="hidden" name="fc_month" value="<?= $fcMonth ?>">
        <?php endif; ?>

        <div class="filter-section-label">
          <i class="bi bi-calendar3"></i> Date Range
        </div>
        <div class="filter-row">
          <div class="filter-group">
            <span class="filter-select-label">From</span>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="date-input">
          </div>
          <div class="filter-group">
            <span class="filter-select-label">To</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="date-input">
          </div>
          <div class="filter-divider"></div>

          <div class="filter-group">
            <span class="filter-select-label"><i class="bi bi-building"></i> Department</span>
            <select name="dept" class="dept-select" style="min-width:130px;">
              <option value="">All Departments</option>
              <?php foreach ($deptList as $d):
                  $dVal = $d['Department'];
                  $dStyle = match(strtolower($dVal)) {
                      'monde'      => 'color:#ef4444;font-weight:700;',
                      'century'    => 'color:#3b82f6;font-weight:700;',
                      'multilines' => 'color:#ca8a04;font-weight:700;',
                      'nutriasia'  => 'color:#059669;font-weight:700;',
                      default      => '',
                  };
              ?>
              <option value="<?= htmlspecialchars($dVal) ?>" <?= ($selDept === $dVal) ? 'selected' : '' ?> style="<?= $dStyle ?>">
                <?= htmlspecialchars($dVal) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-divider"></div>

          <div class="filter-group">
            <span class="filter-select-label"><i class="bi bi-truck"></i> Vehicle Type</span>
            <select name="vtype" id="vtypeSelect" class="dept-select" style="min-width:130px;" onchange="onVtypeChange(this.value)">
              <option value="">All Types</option>
              <optgroup label="🚛 Trucks">
                <?php foreach ($vtypeList as $vt):
                    if (!in_array($vt['Vehicletype'], ['ELF','CANTER','FORWARD','FIGHTER'])) continue; ?>
                <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($vt['Vehicletype']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="🚗 Cars & Motors">
                <?php foreach ($vtypeList as $vt):
                    if (!in_array($vt['Vehicletype'], ['CAR','MOTOR','L300','VAN','CROSS WIND'])) continue; ?>
                <option value="<?= htmlspecialchars($vt['Vehicletype']) ?>" <?= ($selVtype === $vt['Vehicletype']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($vt['Vehicletype']) ?>
                </option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>

          <div class="filter-group">
            <span class="filter-select-label"><i class="bi bi-tag"></i> Plate #</span>
            <div class="plate-combo-wrap">
              <input type="text" id="plateComboInput" name="plate" class="plate-combo-input"
                     autocomplete="off" placeholder="Search or type plate…"
                     value="<?= htmlspecialchars($selPlate) ?>">
              <div id="plateComboDropdown" class="plate-combo-dropdown"></div>
            </div>
          </div>
          <div class="filter-divider"></div>

          <div class="filter-group">
            <span class="filter-select-label"><i class="bi bi-person"></i> Driver</span>
            <input type="text" name="driver" class="date-input" style="width:150px;"
                   value="<?= htmlspecialchars($selDriver) ?>" placeholder="Driver name">
          </div>
          <div class="filter-group">
            <span class="filter-select-label"><i class="bi bi-geo-alt"></i> Area</span>
            <input type="text" name="area" class="date-input" style="width:140px;"
                   value="<?= htmlspecialchars($selArea) ?>" placeholder="Area name">
          </div>

          <div style="display:flex;gap:.4rem;align-items:flex-end;">
            <button type="submit" class="btn-apply"><i class="bi bi-search"></i> Apply</button>
            <?php if ($anyFilterApplied):
                $clearUrl = htmlspecialchars($_SERVER['PHP_SELF']); ?>
            <a href="<?= $clearUrl ?>" class="btn-clear"><i class="bi bi-x-lg"></i> Clear</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($dateActive || $deptActive || $vtypeActive || $plateActive || $driverActive || $areaActive): ?>
        <div class="active-filters-row">
          <span style="font-size:.68rem;color:var(--text-muted);font-weight:700;">Active filters:</span>
          <?php if ($dateActive): ?>
          <span class="filter-badge" style="background:rgba(59,130,246,.1);color:#1d4ed8;border-color:#93c5fd;">
            <i class="bi bi-calendar-check"></i> <?= htmlspecialchars($dateFrom ?: '—') ?> → <?= htmlspecialchars($dateTo ?: '—') ?>
          </span>
          <?php endif; ?>
          <?php if ($deptActive): ?>
          <span class="filter-badge" style="background:rgba(16,185,129,.1);color:#065f46;border-color:#6ee7b7;">
            <i class="bi bi-building"></i> <?= htmlspecialchars($selDept) ?>
          </span>
          <?php endif; ?>
          <?php if ($vtypeActive): ?>
          <span class="filter-badge" style="background:rgba(99,102,241,.1);color:#4338ca;border-color:#c4b5fd;">
            <i class="bi bi-truck"></i> <?= htmlspecialchars($selVtype) ?>
          </span>
          <?php endif; ?>
          <?php if ($plateActive): ?>
          <span class="filter-badge" style="background:rgba(234,179,8,.1);color:#92400e;border-color:#fde047;">
            <i class="bi bi-tag"></i> <?= htmlspecialchars($selPlate) ?>
          </span>
          <?php endif; ?>
          <?php if ($driverActive): ?>
          <span class="filter-badge" style="background:rgba(139,92,246,.1);color:#6d28d9;border-color:#c4b5fd;">
            <i class="bi bi-person"></i> <?= htmlspecialchars($selDriver) ?>
          </span>
          <?php endif; ?>
          <?php if ($areaActive): ?>
          <span class="filter-badge" style="background:rgba(239,68,68,.1);color:#991b1b;border-color:#fca5a5;">
            <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selArea) ?>
          </span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </form>
    </div>
    <?php
}

// ============================================================
// SHARED STAT CARDS RENDERER
// ============================================================
function renderStatCards(int $totalTrucks, float $totalLiters, float $totalAmount,
                         int $totalRefuels, int $anomalyCount,
                         bool $dateActive, bool $anyFilterApplied,
                         string $baseFrom, string $baseTo): void { ?>
  <div class="stats-row">
    <div class="stat-card">
      <span class="stat-icon">🚛</span>
      <div class="stat-label">Total Vehicles</div>
      <div class="stat-value"><?= $totalTrucks ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⛽</span>
      <div class="stat-label">Total Liters <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value teal"><?= number_format($totalLiters, 0) ?> L</div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">💰</span>
      <div class="stat-label">Total Amount <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value accent">₱<?= number_format($totalAmount, 0) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">🔄</span>
      <div class="stat-label">Total Refuels <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value"><?= number_format($totalRefuels) ?></div>
    </div>
    <div class="stat-card">
      <span class="stat-icon">⚠️</span>
      <div class="stat-label">Anomaly Flags <?= $dateActive ? '<span class="stat-filter-tag">Filtered</span>' : '' ?></div>
      <div class="stat-value red"><?= $anomalyCount ?></div>
    </div>
  </div>
<?php }

// ============================================================
// SHARED JS SNIPPETS (plate combobox + export utils)
// ============================================================
function renderSharedJS(array $plateList, string $selVtype, string $tab, array $allData, array $fcWeeks = [], int $fcWeekCount = 0, int $fcMonth = 0, int $fcYear = 0, string $fcMonthLabel = ''): void {
    $initPlates  = json_encode(array_column($plateList, 'PlateNumber'), JSON_UNESCAPED_UNICODE);
    $exportRows  = array_map(function ($row) {
        $out = [];
        foreach ($row as $k => $v) { $out[$k] = ($v instanceof DateTime) ? $v->format('Y-m-d') : $v; }
        return $out;
    }, $allData);
    $exportJson  = json_encode($exportRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $tabJson     = json_encode($tab);
    $selVtypeJson = json_encode($selVtype);
    ?>
<script>
// ── Plate Combobox (AJAX) ──────────────────────────────────
(function () {
    const input    = document.getElementById('plateComboInput');
    const dropdown = document.getElementById('plateComboDropdown');
    const vtypeSel = document.getElementById('vtypeSelect');
    if (!input) return;
    let allPlates = <?= $initPlates ?>;
    let loadedFor = <?= $selVtypeJson ?>;
    let activeIdx = -1;

    function renderDropdown(plates, query) {
        const q = query.toUpperCase();
        const filtered = plates.filter(p => p.toUpperCase().includes(q)).slice(0, 60);
        if (!filtered.length) { dropdown.innerHTML = '<div class="plate-combo-empty">No matching plates</div>'; }
        else {
            dropdown.innerHTML = filtered.map((p, i) => {
                const hi = q ? p.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'), '<mark>$1</mark>') : p;
                return `<div class="plate-combo-item" data-val="${p}" data-idx="${i}">${hi}</div>`;
            }).join('');
        }
        dropdown.querySelectorAll('.plate-combo-item').forEach(item => {
            item.addEventListener('mousedown', e => { e.preventDefault(); input.value = item.dataset.val; closeDropdown(); });
        });
        activeIdx = -1;
    }
    function openDropdown() { renderDropdown(allPlates, input.value); dropdown.classList.add('open'); }
    function closeDropdown() { dropdown.classList.remove('open'); activeIdx = -1; }

    async function loadPlates(vtype) {
        if (loadedFor === vtype) return;
        dropdown.innerHTML = '<div class="plate-combo-spinner">⏳ Loading…</div>';
        dropdown.classList.add('open');
        input.classList.add('loading');
        try {
            // Resolve ajax endpoint – same directory, fuel_dashboard.php handles ajax
            const url = `fuel_dashboard.php?ajax=plates${vtype ? '&vtype=' + encodeURIComponent(vtype) : ''}`;
            const res = await fetch(url);
            allPlates = await res.json();
            loadedFor = vtype;
        } catch(e) { allPlates = []; }
        input.classList.remove('loading');
        renderDropdown(allPlates, input.value);
    }

    input.addEventListener('focus', () => openDropdown());
    input.addEventListener('input', () => renderDropdown(allPlates, input.value));
    input.addEventListener('blur',  () => setTimeout(closeDropdown, 150));
    input.addEventListener('keydown', e => {
        const items = dropdown.querySelectorAll('.plate-combo-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx+1, items.length-1); items.forEach((it,i)=>it.classList.toggle('highlighted',i===activeIdx)); if(items[activeIdx]) items[activeIdx].scrollIntoView({block:'nearest'}); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); items.forEach((it,i)=>it.classList.toggle('highlighted',i===activeIdx)); }
        else if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) { input.value = items[activeIdx].dataset.val; closeDropdown(); e.preventDefault(); }
        else if (e.key === 'Escape') closeDropdown();
    });
    window.onVtypeChange = vtype => { input.value = ''; loadPlates(vtype); };
})();

// ── Export helpers ─────────────────────────────────────────
const _allData = <?= $exportJson ?>;
const _tabName = <?= $tabJson ?>;

function _getExportData() {
    if (!_allData || !_allData.length) return null;
    if (_allData.length > 5000 && !confirm(`Export ${_allData.length.toLocaleString()} rows?`)) return null;
    return _allData;
}
function _buildFilename(ext) {
    const p = new URLSearchParams(window.location.search);
    const parts = ['Fuel', _tabName.replace(/_/g,' ')];
    ['date_from','date_to','dept','vtype','plate','driver'].forEach(k => { if(p.get(k)) parts.push(p.get(k)); });
    return parts.join('_').replace(/[^a-zA-Z0-9_\-]/g,'_') + '.' + ext;
}
function filterTable(value) {
    value = value.toLowerCase();
    document.querySelectorAll('#mainTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(value) ? '' : 'none';
    });
}
let sortDir = {};
function sortTable(col) {
    const table = document.getElementById('mainTable'); if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    const ths   = table.querySelectorAll('thead th');
    sortDir[col] = !sortDir[col]; const asc = sortDir[col];
    ths.forEach((th, i) => { th.classList.toggle('sorted', i===col); const ic=th.querySelector('.sort-icon'); if(ic) ic.textContent=(i===col)?(asc?'↑':'↓'):'⇅'; });
    rows.sort((a,b) => {
        const aT=a.cells[col]?.textContent.trim()??'', bT=b.cells[col]?.textContent.trim()??'';
        const aN=parseFloat(aT.replace(/[₱,L% +]/g,'')), bN=parseFloat(bT.replace(/[₱,L% +]/g,''));
        if (!isNaN(aN)&&!isNaN(bN)) return asc?aN-bN:bN-aN;
        return asc?aT.localeCompare(bT):bT.localeCompare(aT);
    });
    rows.forEach(r => tbody.appendChild(r));
}
function exportCSV() {
    const rows = _getExportData(); if (!rows) { alert('No data.'); return; }
    const h = Object.keys(rows[0]);
    const lines = [h.map(x=>'"'+x+'"').join(',')];
    rows.forEach(r => lines.push(h.map(k=>'"'+String(r[k]??'').replace(/"/g,'""')+'"').join(',')));
    const url = URL.createObjectURL(new Blob(['\uFEFF'+lines.join('\n')],{type:'text/csv;charset=utf-8;'}));
    const a = document.createElement('a'); a.href=url; a.download=_buildFilename('csv');
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}
function exportExcel() {
    const rows = _getExportData(); if (!rows) { alert('No data.'); return; }
    const h = Object.keys(rows[0]);
    const esc = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let xml = '<?xml version="1.0"?>\n<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">\n<Worksheet ss:Name="Data"><Table>\n';
    xml += '<Row>'+h.map(hh=>`<Cell><Data ss:Type="String">${esc(hh)}</Data></Cell>`).join('')+'</Row>\n';
    rows.forEach(r => {
        xml += '<Row>'+h.map(k => {
            const raw=r[k]??''; const n=parseFloat(String(raw).replace(/[₱,\s]/g,''));
            return (!isNaN(n)&&isFinite(n)&&/^[₱\s]*[\d,]+(\.\d+)?[\sL%]*$/.test(String(raw).trim()))
                ? `<Cell><Data ss:Type="Number">${n}</Data></Cell>`
                : `<Cell><Data ss:Type="String">${esc(raw)}</Data></Cell>`;
        }).join('')+'</Row>\n';
    });
    xml += '</Table></Worksheet></Workbook>';
    const url = URL.createObjectURL(new Blob([xml],{type:'application/vnd.ms-excel;charset=utf-8;'}));
    const a = document.createElement('a'); a.href=url; a.download=_buildFilename('xls');
    document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
}
function printTable() {
    const rows = _getExportData(); if (!rows) { alert('No data.'); return; }
    const title = document.querySelector('.table-title')?.innerText?.replace(/[⇅↑↓]/g,'').trim() || 'Report';
    const p = new URLSearchParams(window.location.search);
    const filters = []; ['date_from','date_to','dept','vtype','plate','driver'].forEach(k => { if(p.get(k)) filters.push(k+': '+p.get(k)); });
    const h = Object.keys(rows[0]);
    const esc = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const headerHtml = h.map(hh=>`<th style="background:#1e40af;color:#fff;padding:5px 8px;font-size:10px;">${esc(hh)}</th>`).join('');
    const bodyHtml   = rows.map(r=>'<tr>'+h.map(k=>`<td style="padding:4px 8px;border:1px solid #e2e8f0;font-size:10px;">${esc(r[k]??'—')}</td>`).join('')+'</tr>').join('');
    const w = window.open('','_blank','width=1300,height=900');
    w.document.write(`<!DOCTYPE html><html><head><title>${esc(title)}</title><style>body{font-family:Arial,sans-serif;padding:16px;}table{border-collapse:collapse;width:100%;}tbody tr:nth-child(even)td{background:#f8fafc;}</style></head><body><h2 style="color:#1e40af;font-size:14px;">${esc(title)}</h2><p style="font-size:9px;color:#64748b;">${filters.join(' · ')} · ${rows.length.toLocaleString()} records · ${new Date().toLocaleString()}</p><table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table></body></html>`);
    w.document.close(); setTimeout(()=>w.print(),400);
}
// ── Modals ──────────────────────────────────────────────────
function showAreas(plate, areas) {
    document.getElementById('areasModalPlate').textContent = plate;
    document.getElementById('areasModalList').innerHTML = areas.split(', ').filter(a=>a.trim()).map(a=>`<span class="area-chip"><i class="bi bi-geo-alt"></i> ${a.trim()}</span>`).join('');
    document.getElementById('areasModal').style.display = 'flex';
}
function closeAreas() { document.getElementById('areasModal').style.display = 'none'; }

(function(){
    const pop = document.getElementById('refPopover');
    if (!pop) return;
    document.body.appendChild(pop);
    let currentBtn = null;
    window.showRefPop = function(btn) {
        if (currentBtn===btn && pop.style.display!=='none') { closeRefPop(); return; }
        currentBtn = btn;
        const lines = (btn.getAttribute('data-ref')||'').split('\n').filter(l=>l.trim());
        document.getElementById('refPopLines').innerHTML = lines.map(l => {
            const isSelf=l.includes('◀ this'), isSep=l.startsWith('—');
            if (isSep) return `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);padding:.25rem 0 .1rem;">${l.replace(/—/g,'').trim()}</div>`;
            const bg = isSelf?'background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.25);':'background:var(--hover);border:1px solid transparent;';
            return `<div style="padding:.3rem .5rem;border-radius:7px;font-size:.76rem;font-family:'DM Mono',monospace;${bg}${isSelf?'font-weight:700;':''}">${l.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
        }).join('');
        pop.style.display = 'block';
        const r=btn.getBoundingClientRect(), pw=pop.offsetWidth, ph=pop.offsetHeight, m=8;
        let left=r.left, top=r.top-ph-6;
        if (top<m) top=r.bottom+6; if (left+pw>window.innerWidth-m) left=r.right-pw; if (left<m) left=m;
        pop.style.left=left+'px'; pop.style.top=top+'px';
    };
    window.closeRefPop = () => { pop.style.display='none'; currentBtn=null; };
    document.addEventListener('click', e => { if(!pop.contains(e.target)&&!e.target.closest('.trig-badge')) closeRefPop(); });
    document.addEventListener('keydown', e => { if(e.key==='Escape'){closeAreas();closeRefPop();} });
})();

document.querySelectorAll('.progress-fill').forEach(el => {
    const w=el.style.width; el.style.width='0'; setTimeout(()=>{el.style.width=w;},80);
});
</script>
    <?php
}

// ============================================================
// SHARED MODALS HTML
// ============================================================
function renderSharedModals(): void { ?>
<div id="areasModal" style="display:none;position:fixed;inset:0;z-index:9999;align-items:center;justify-content:center;">
  <div style="position:absolute;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);" onclick="closeAreas()"></div>
  <div style="position:relative;background:var(--surface);border:1.5px solid var(--border);border-radius:16px;padding:1.5rem;min-width:280px;max-width:420px;width:90%;box-shadow:0 16px 48px rgba(0,0,0,.3);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
      <div>
        <div style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">All Areas</div>
        <div id="areasModalPlate" style="font-weight:800;font-size:1rem;color:var(--text-primary);"></div>
      </div>
      <button onclick="closeAreas()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.2rem;padding:.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="areasModalList" style="display:flex;flex-wrap:wrap;gap:.4rem;"></div>
  </div>
</div>
<div id="refPopover" style="display:none;position:fixed;z-index:10000;max-width:340px;width:max-content;">
  <div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.85rem 1rem;box-shadow:0 8px 32px rgba(0,0,0,.18);font-size:.78rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;">
      <span style="font-weight:700;font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Reference Baselines</span>
      <button onclick="closeRefPop()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:.1rem .3rem;line-height:1;">✕</button>
    </div>
    <div id="refPopLines" style="display:flex;flex-direction:column;gap:.45rem;color:var(--text-secondary);line-height:1.5;"></div>
  </div>
</div>
<?php }

// ============================================================
// SHARED INLINE STYLES (filter bar + combobox)
// ============================================================
function renderSharedStyles(): void { ?>
<style>
.filter-bar-card{background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:14px;padding:1rem 1.25rem;margin-bottom:1rem;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.filter-section-label{font-size:.65rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted,#64748b);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
.filter-row{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-bottom:.65rem;}
.filter-row:last-child{margin-bottom:0;}
.filter-group{display:flex;flex-direction:column;gap:.22rem;}
.filter-select-label{font-size:.68rem;font-weight:600;color:var(--text-muted,#64748b);display:flex;align-items:center;gap:.3rem;}
.filter-divider{width:1px;height:32px;background:var(--border,#e2e8f0);align-self:flex-end;margin:0 .25rem;flex-shrink:0;}
.plate-combo-wrap{position:relative;}
.plate-combo-input{width:160px;padding:.32rem .75rem;border:1.5px solid var(--border,#e2e8f0);border-radius:8px;font-size:.8rem;background:var(--surface,#fff);color:var(--text-primary,#0f172a);font-family:inherit;transition:border-color .15s,box-shadow .15s;}
.plate-combo-input:focus{outline:none;border-color:var(--primary,#3b82f6);box-shadow:0 0 0 3px rgba(59,130,246,.12);}
.plate-combo-input.loading{color:var(--text-muted,#64748b);}
.plate-combo-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;width:200px;max-height:220px;overflow-y:auto;background:var(--surface,#fff);border:1.5px solid var(--border,#e2e8f0);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:200;}
.plate-combo-dropdown.open{display:block;}
.plate-combo-item{padding:.4rem .75rem;font-size:.8rem;font-family:'DM Mono',monospace;cursor:pointer;color:var(--text-primary,#0f172a);transition:background .1s;border-bottom:1px solid var(--border,#e2e8f0);}
.plate-combo-item:last-child{border-bottom:none;}
.plate-combo-item:hover,.plate-combo-item.highlighted{background:var(--hover,#f1f5f9);}
.plate-combo-item mark{background:rgba(59,130,246,.18);color:#1d4ed8;border-radius:2px;font-style:normal;}
.plate-combo-empty,.plate-combo-spinner{padding:.6rem .75rem;font-size:.78rem;color:var(--text-muted,#64748b);text-align:center;}
.active-filters-row{display:flex;flex-wrap:wrap;gap:.35rem;align-items:center;padding-top:.6rem;border-top:1px dashed var(--border,#e2e8f0);margin-top:.6rem;}
.filter-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.7rem;font-weight:700;padding:.18rem .55rem;border-radius:20px;border:1px solid;white-space:nowrap;}
.area-chip{display:inline-block;padding:.25rem .65rem;background:var(--hover);border:1px solid var(--border);border-radius:20px;font-size:.78rem;font-weight:600;color:var(--text-secondary);}
.btn-areas{display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;background:rgba(59,130,246,.12);color:#3b82f6;border:1px solid rgba(59,130,246,.3);cursor:pointer;transition:background .15s;font-family:inherit;white-space:nowrap;}
.btn-areas:hover{background:rgba(59,130,246,.22);}
</style>
<?php }