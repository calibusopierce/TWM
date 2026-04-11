<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'auth_check.php';
include 'test_sqlsrv.php';
auth_check(['Admin', 'Administrator', 'HR']);
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// ── Department from query string ──────────────────────────────
$deptParam = isset($_GET['dept']) ? trim($_GET['dept']) : '';

// ── All available departments for the switcher ────────────────
$deptListSql  = "SELECT DISTINCT Department FROM TBL_HREmployeeList WHERE Active=1 AND Department IS NOT NULL ORDER BY Department";
$deptListStmt = sqlsrv_query($conn, $deptListSql);
$allDepts     = [];
while ($r = sqlsrv_fetch_array($deptListStmt, SQLSRV_FETCH_ASSOC)) {
    $allDepts[] = trim($r['Department']);
}
sqlsrv_free_stmt($deptListStmt);

// Sort departments in preferred order
$deptPreferredOrder = ['CENTURY'=>0,'MONDE'=>1,'NUTRIASIA'=>2,'SILVER SWAN'=>3,'MULTILINES'=>4,'URBAN TRADEWELL CORP.'=>5];
usort($allDepts, function($a, $b) use ($deptPreferredOrder) {
    $ga = 99; $gb = 99;
    foreach ($deptPreferredOrder as $k => $v) {
        if (stripos($a, $k) !== false) { $ga = $v; break; }
    }
    foreach ($deptPreferredOrder as $k => $v) {
        if (stripos($b, $k) !== false) { $gb = $v; break; }
    }
    return $ga !== $gb ? $ga - $gb : strcasecmp($a, $b);
});

// Default to first dept if none given
if ($deptParam === '' && !empty($allDepts)) {
    $deptParam = $allDepts[0];
}

// ── Dept theme ────────────────────────────────────────────────
function getDeptTheme($dept) {
    $d = strtoupper(trim($dept));
    if (strpos($d, 'CENTURY')   !== false) return ['primary'=>'#1565c0','header'=>'#0d47a1','cat'=>'#1976d2','box_border'=>'#42a5f5','box_bg'=>'#e3f2fd','name_color'=>'#0d47a1','label'=>'Century Pacific'];
    if (strpos($d, 'MONDE')     !== false) return ['primary'=>'#c62828','header'=>'#b71c1c','cat'=>'#e53935','box_border'=>'#ef9a9a','box_bg'=>'#ffebee','name_color'=>'#b71c1c','label'=>'Monde Nissin'];
    if (strpos($d, 'NUTRI')     !== false) return ['primary'=>'#2e7d32','header'=>'#1b5e20','cat'=>'#388e3c','box_border'=>'#81c784','box_bg'=>'#e8f5e9','name_color'=>'#1b5e20','label'=>'NutriAsia & Silver Swan'];
    if (strpos($d, 'SILVER')    !== false) return ['primary'=>'#2e7d32','header'=>'#1b5e20','cat'=>'#388e3c','box_border'=>'#81c784','box_bg'=>'#e8f5e9','name_color'=>'#1b5e20','label'=>'NutriAsia & Silver Swan'];
    if (strpos($d, 'MULTI')     !== false) return ['primary'=>'#e65100','header'=>'#bf360c','cat'=>'#f57c00','box_border'=>'#ffb74d','box_bg'=>'#fff3e0','name_color'=>'#bf360c','label'=>'Multilines'];
    if (strpos($d, 'URBAN')     !== false) return ['primary'=>'#1a237e','header'=>'#0d1b5e','cat'=>'#283593','box_border'=>'#7986cb','box_bg'=>'#e8eaf6','name_color'=>'#1a237e','label'=>'Urban Tradewell Corp.'];
    return ['primary'=>'#37474f','header'=>'#263238','cat'=>'#455a64','box_border'=>'#90a4ae','box_bg'=>'#eceff1','name_color'=>'#263238','label'=>$dept];
}

// ── Position rank ─────────────────────────────────────────────
$positionRank = [
    'owner'                    => 1,  'president'               => 1,
    'ceo'                      => 1,  'finance manager'         => 2,
    'operations manager'       => 2,  'logistics officer'       => 2,
    'delivery officer'         => 2,  'auditor'                 => 3,
    'administrator'            => 3,  'warehouse supervisor'    => 3,
    'supervisor'               => 4,  'hr specialist'           => 4,
    'hr officer'               => 4,  'it personnel'            => 4,
    'it supervisor'            => 4,  'teamleader'              => 5,
    'team leader'              => 5,  'merchandiser coordinator'=> 5,
    'warehouse custodian'      => 5,  'accounting clerk'        => 6,
    'hr assistant'             => 6,  'finance personnel'       => 6,
    'finance specialist'       => 6,  'asst. whse custodian'    => 6,
    'office clerk'             => 7,  'admin staff'             => 7,
    'admin'                    => 7,  'clerk'                   => 7,
    'salesman'                 => 7,  'merchandiser'            => 7,
    'cashier'                  => 7,  'encoder'                 => 7,
    'driver'                   => 8,  'extra driver'            => 8,
    'warehouse helper'         => 8,  'mechanic'                => 8,
    'delivery helper'          => 9,  'helper'                  => 9,
    'hr'                       => 6,
];
function getPosRank($pos) {
    global $positionRank;
    $p = strtolower(trim($pos));
    if (isset($positionRank[$p])) return $positionRank[$p];
    foreach ($positionRank as $kw => $rank) {
        if (strpos($p, $kw) !== false) return $rank;
    }
    return 50;
}

// ── Category classification ───────────────────────────────────
function classifyCategory($cat) {
    $c = strtolower(trim($cat));
    if ($c === 'om')       return 'OM';
    if ($c === 'logistics')return 'LOG';
    if ($c === 'dsp')      return 'DSP';
    if ($c === 'mp')       return 'DSP';  // merchandisers go under sales
    if ($c === 'op' || $c === 'office') return 'ADM';
    if ($c === 'hr')       return 'ADM';
    if ($c === 'dp')       return 'LOG';  // delivery under logistics
    if ($c === 'jo')       return 'DSP';
    return 'ADM';
}

// ── Fetch employees for selected dept ─────────────────────────
$employees = [];
if ($deptParam !== '') {
    // Use LTRIM/RTRIM in SQL to handle any whitespace stored in the DB
    $sql = "
        SELECT FileNo, EmployeeID, Department, Position_held, Category, Branch,
               FirstName, LastName, MiddleName,
               Hired_date, Birth_date, Gender, Civil_Status,
               Phone_Number, Mobile_Number, Email_Address,
               Employee_Status, Job_tittle, SortNo,
               Picture, IDPicture, Active
        FROM TBL_HREmployeeList
        WHERE Active = 1
          AND LTRIM(RTRIM(Department)) = LTRIM(RTRIM(?))
          AND Position_held IS NOT NULL
        ORDER BY SortNo, LastName, FirstName";
    $stmt = sqlsrv_query($conn, $sql, [$deptParam]);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $employees[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    // If still no results, try case-insensitive LIKE fallback
    if (empty($employees)) {
        $likeSql = "
            SELECT FileNo, EmployeeID, Department, Position_held, Category, Branch,
                   FirstName, LastName, MiddleName,
                   Hired_date, Birth_date, Gender, Civil_Status,
                   Phone_Number, Mobile_Number, Email_Address,
                   Employee_Status, Job_tittle, SortNo,
                   Picture, IDPicture, Active
            FROM TBL_HREmployeeList
            WHERE Active = 1
              AND LTRIM(RTRIM(Department)) LIKE ?
              AND Position_held IS NOT NULL
            ORDER BY SortNo, LastName, FirstName";
        $likeStmt = sqlsrv_query($conn, $likeSql, ['%' . $deptParam . '%']);
        if ($likeStmt !== false) {
            while ($row = sqlsrv_fetch_array($likeStmt, SQLSRV_FETCH_ASSOC)) {
                $employees[] = $row;
            }
            sqlsrv_free_stmt($likeStmt);
        }
    }
}

// ── Group into 3 chart columns: LOG | DSP | ADM ──────────────
$logEmps = [];
$dspEmps = [];
$admEmps = [];
$omEmps  = [];

foreach ($employees as $emp) {
    $col = classifyCategory(trim($emp['Category'] ?? ''));
    if ($col === 'OM')       $omEmps[]  = $emp;
    elseif ($col === 'LOG')  $logEmps[] = $emp;
    elseif ($col === 'DSP')  $dspEmps[] = $emp;
    else                     $admEmps[] = $emp;
}

// ── Group employees by position, sort by rank ─────────────────
function groupAndSortByPosition(array $emps): array {
    $byPos = [];
    foreach ($emps as $emp) {
        $pos = trim($emp['Position_held']);
        $byPos[$pos][] = $emp;
    }
    uksort($byPos, function($a, $b) {
        $diff = getPosRank($a) - getPosRank($b);
        return $diff !== 0 ? $diff : strcasecmp($a, $b);
    });
    return $byPos;
}

// ── Group DSP by branch for sub-column rendering ──────────────
function groupDspByBranch(array $emps): array {
    $branches = [];
    foreach ($emps as $emp) {
        $branch = trim($emp['Branch'] ?? '');
        if ($branch === '' || strtoupper($branch) === 'NULL' || strtoupper($branch) === '[SELECT]') {
            $branch = 'MAIN';
        }
        $branches[$branch][] = $emp;
    }
    // Sort branches: MAIN/QUEZON first, then others
    uksort($branches, function($a, $b) {
        $order = ['MAIN'=>0,'QUEZON'=>1,'QUEZON UPPER'=>2,'MARINDUQUE'=>3];
        $oa = isset($order[$a]) ? $order[$a] : 99;
        $ob = isset($order[$b]) ? $order[$b] : 99;
        return $oa !== $ob ? $oa - $ob : strcasecmp($a, $b);
    });
    return $branches;
}

// ── Helpers ───────────────────────────────────────────────────
function empFullName($emp) {
    return trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));
}
function empInitials($emp) {
    return strtoupper(
        substr(trim($emp['FirstName'] ?? ''), 0, 1) .
        substr(trim($emp['LastName']  ?? ''), 0, 1)
    );
}
function avatarPath($emp) {
    $pic = trim($emp['Picture'] ?? '');
    return ($pic !== '' && strtoupper($pic) !== 'NULL') ? $pic : '';
}
function empJson($emp) {
    $pic   = trim($emp['Picture']   ?? '');
    $idpic = trim($emp['IDPicture'] ?? '');
    $mid   = trim($emp['MiddleName'] ?? '');
    $hired = ($emp['Hired_date'] instanceof DateTime) ? $emp['Hired_date']->format('M d, Y') : ($emp['Hired_date'] ?? '');
    $bday  = ($emp['Birth_date'] instanceof DateTime) ? $emp['Birth_date']->format('M d, Y') : ($emp['Birth_date'] ?? '');
    return json_encode([
        'name'     => trim($emp['FirstName'].($mid?' '.$mid:'').' '.$emp['LastName']),
        'position' => trim($emp['Position_held']  ?? ''),
        'jobTitle' => trim($emp['Job_tittle']      ?? ''),
        'dept'     => trim($emp['Department']      ?? ''),
        'branch'   => trim($emp['Branch']          ?? ''),
        'category' => trim($emp['Category']        ?? ''),
        'status'   => trim($emp['Employee_Status'] ?? ''),
        'gender'   => trim($emp['Gender']          ?? ''),
        'civil'    => trim($emp['Civil_Status']    ?? ''),
        'hired'    => $hired,
        'bday'     => $bday,
        'phone'    => trim($emp['Phone_Number']    ?? ''),
        'mobile'   => trim($emp['Mobile_Number']   ?? ''),
        'email'    => trim($emp['Email_Address']   ?? ''),
        'picture'  => (strtoupper($pic)   !== 'NULL' && $pic   !== '') ? $pic   : '',
        'idpic'    => (strtoupper($idpic) !== 'NULL' && $idpic !== '') ? $idpic : '',
        'empid'    => trim($emp['EmployeeID'] ?? ''),
        'initials' => strtoupper(substr($emp['FirstName'],0,1).substr($emp['LastName'],0,1)),
    ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
}

// ── Render a position node ─────────────────────────────────────────
function renderPosNode($posTitle, $emps, $theme, $idPrefix = '') {
    $total  = count($emps);
    $nodeId = $idPrefix . 'pos-' . preg_replace('/\W+/', '-', strtolower($posTitle));
    $showCount = $total > 20;
    echo '<div class="pos-node" id="' . htmlspecialchars($nodeId) . '" style="border-color:' . $theme['box_border'] . ';">';
    echo '<div class="pos-node-title" style="background:' . $theme['primary'] . ';">';
    echo htmlspecialchars($posTitle);
    if ($showCount) {
        echo ' <span class="count-circle" style="background:' . $theme['cat'] . ';">' . $total . '</span>';
    }
    echo '</div>';
    if (!$showCount) {
        echo '<div class="pos-node-names">';
        foreach ($emps as $emp) {
            $av   = avatarPath($emp);
            $data = empJson($emp);
            echo '<div class="pos-node-name" onclick="showProfile(' . $data . ')">';
            if ($av) {
                echo '<img class="pos-node-avatar" src="' . htmlspecialchars($av) . '" alt=""'
                   . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">';
                echo '<div class="pos-node-initials" style="display:none;background:' . $theme['primary'] . ';">' . empInitials($emp) . '</div>';
            } else {
                echo '<div class="pos-node-initials" style="background:' . $theme['primary'] . ';">' . empInitials($emp) . '</div>';
            }
            echo '<span>' . htmlspecialchars(empFullName($emp)) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        // Just show count circle for large groups
        echo '<div class="pos-node-count">';
        echo '<span class="count-circle" style="background:' . $theme['cat'] . ';width:44px;height:44px;font-size:1rem;">' . $total . '</span>';
        echo '<div style="font-size:.65rem;color:#64748b;margin-top:.3rem;">employees</div>';
        echo '</div>';
    }
    echo '</div>';
    return $nodeId;
}

$theme      = getDeptTheme($deptParam);
$logByPos   = groupAndSortByPosition($logEmps);
$dspByPos   = groupAndSortByPosition($dspEmps);
$admByPos   = groupAndSortByPosition($admEmps);
$omByPos    = groupAndSortByPosition($omEmps);
$dspBranch  = groupDspByBranch($dspEmps);
$hasBranches = count($dspBranch) > 1;
$totalEmps  = count($employees);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Org Chart — <?= htmlspecialchars($deptParam) ?></title>
<link href="assets/img/logo.png" rel="icon">
<link href="assets/vendor/fonts/fonts.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/fuel.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#e8edf3;color:#1a202c;}

/* ── Top nav bar ────────────────────────────────────────── */
.chart-topbar{
    display:flex;align-items:center;justify-content:space-between;
    padding:.65rem 1.5rem;
    background:#0d1b3e;
    border-bottom:3px solid #e53935;
    gap:1rem;flex-wrap:wrap;
}
.chart-topbar-logo{display:flex;align-items:center;gap:.65rem;}
.chart-topbar-logo img{height:38px;width:auto;}
.chart-topbar-logo-text{font-family:'Sora',sans-serif;font-size:.75rem;font-weight:700;color:#fff;line-height:1.3;letter-spacing:.02em;}
.chart-topbar-logo-text span{display:block;font-size:.58rem;font-weight:400;color:#90caf9;letter-spacing:.04em;}
.chart-topbar-right{text-align:right;}
.chart-topbar-right .dept-label-small{font-family:'Sora',sans-serif;font-size:.82rem;font-weight:700;color:#90caf9;font-style:italic;}
.chart-topbar-right .dept-label-large{font-family:'Sora',sans-serif;font-size:1rem;font-weight:800;color:#fff;font-style:italic;}

/* ── Controls ───────────────────────────────────────────── */
.chart-controls{
    display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;
    padding:.6rem 1.5rem;
    background:#fff;border-bottom:1px solid #e2e8f0;
}
.dept-select-wrap{display:flex;align-items:center;gap:.5rem;}
.dept-select-label{font-size:.78rem;font-weight:600;color:#64748b;}
.dept-select{
    padding:.35rem .8rem;border:1.5px solid #e2e8f0;border-radius:8px;
    font-family:'DM Sans',sans-serif;font-size:.82rem;color:#1a202c;
    background:#f8fafc;outline:none;cursor:pointer;
}
.dept-select:focus{border-color:#3b82f6;}
.btn-ctrl{
    display:inline-flex;align-items:center;gap:.3rem;
    padding:.35rem .8rem;border-radius:8px;font-size:.78rem;font-weight:600;
    cursor:pointer;border:1.5px solid #e2e8f0;
    background:#f8fafc;color:#475569;
    font-family:'DM Sans',sans-serif;transition:all .15s;
}
.btn-ctrl:hover{background:#f1f5f9;border-color:#94a3b8;}
.emp-count-badge{
    margin-left:auto;font-size:.75rem;color:#64748b;
    background:#f8fafc;border:1px solid #e2e8f0;
    padding:.25rem .65rem;border-radius:20px;font-weight:600;
}

/* ── Chart page ─────────────────────────────────────────── */
.chart-page{
    padding:1.5rem;
    min-height:calc(100vh - 120px);
    overflow-x:auto;
}
.chart-paper{
    position:relative;
    background:linear-gradient(160deg,#dce8f5 0%,#eaf1f9 40%,#f0f6fb 100%);
    border-radius:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.1);
    padding:2rem 2rem 2.5rem;
    min-width:900px;
}

/* ── Dept title block ───────────────────────────────────── */
.dept-title-wrap{text-align:center;margin-bottom:2rem;}
.dept-title-box{
    display:inline-block;
    padding:.65rem 2.5rem;
    border-radius:10px;
    font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:900;
    color:#fff;letter-spacing:.04em;text-transform:uppercase;
    box-shadow:0 4px 16px rgba(0,0,0,.15);
}

/* ── SVG connector layer ─────────────────────────────────── */
#connectorSvg{
    position:absolute;top:0;left:0;width:100%;height:100%;
    pointer-events:none;overflow:visible;z-index:0;
}

/* ── Chart body: 3 column layout ────────────────────────── */
.chart-body{
    position:relative;z-index:1;
    display:flex;align-items:flex-start;
    gap:0;
}
.chart-col{
    flex:1;min-width:0;
    display:flex;flex-direction:column;
    align-items:center;
    gap:.6rem;
    padding:0 .5rem;
}
.chart-col-center{flex:2;}

/* ── Category header box ─────────────────────────────────── */
.cat-header-box{
    display:inline-flex;align-items:center;justify-content:center;
    padding:.45rem 1.25rem;border-radius:6px;
    font-family:'Sora',sans-serif;font-size:.78rem;font-weight:800;
    color:#fff;text-transform:uppercase;letter-spacing:.06em;
    text-align:center;line-height:1.3;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
    width:100%;max-width:220px;
}

/* ── Position node ───────────────────────────────────────── */
.pos-node{
    width:100%;max-width:220px;
    background:#fff;border-radius:8px;
    border:2px solid #ccc;
    overflow:hidden;
    box-shadow:0 2px 6px rgba(0,0,0,.07);
    transition:box-shadow .15s;
}
.pos-node:hover{box-shadow:0 4px 14px rgba(0,0,0,.13);}
.pos-node-title{
    padding:.3rem .6rem;
    font-size:.7rem;font-weight:700;text-align:center;
    color:#fff;letter-spacing:.02em;
    line-height:1.3;
}
.pos-node-names{
    padding:.3rem .5rem;
    display:flex;flex-direction:column;gap:.15rem;
}
.pos-node-name{
    display:flex;align-items:center;gap:.35rem;
    padding:.2rem .3rem;border-radius:5px;
    cursor:pointer;transition:background .1s;
    font-size:.69rem;color:#1e293b;font-weight:500;
    line-height:1.3;
}
.pos-node-name:hover{background:#eff6ff;}
.pos-node-avatar{
    width:20px;height:20px;border-radius:50%;flex-shrink:0;
    object-fit:cover;border:1px solid #e2e8f0;
}
.pos-node-initials{
    width:20px;height:20px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-family:'Sora',sans-serif;font-size:.5rem;font-weight:800;
    color:#fff;border:1px solid rgba(255,255,255,.3);
}
/* Count-only node (when >20 employees, show just the number) */
.pos-node-count{
    padding:.4rem;text-align:center;
    font-family:'Sora',sans-serif;font-size:1.1rem;font-weight:900;
}
.count-circle{
    display:inline-flex;align-items:center;justify-content:center;
    width:36px;height:36px;border-radius:50%;
    font-family:'Sora',sans-serif;font-size:.85rem;font-weight:900;color:#fff;
    box-shadow:0 2px 8px rgba(0,0,0,.2);
    vertical-align:middle;margin-left:.3rem;
}

/* ── Branch sub-columns for DSP ─────────────────────────── */
.branch-cols{
    display:flex;align-items:flex-start;
    gap:.5rem;width:100%;justify-content:center;
}
.branch-col{
    display:flex;flex-direction:column;align-items:center;
    gap:.5rem;flex:1;min-width:0;
}
.branch-label{
    padding:.3rem .7rem;border-radius:5px;
    font-family:'Sora',sans-serif;font-size:.65rem;font-weight:800;
    color:#fff;text-transform:uppercase;letter-spacing:.07em;
    text-align:center;
}

/* ── Profile Modal ───────────────────────────────────────── */
#profileModal{
    display:none;position:fixed;inset:0;z-index:9999;
    align-items:center;justify-content:center;
}
#profileModal.open{display:flex;}
.modal-bg{
    position:absolute;inset:0;
    background:rgba(0,0,0,.55);backdrop-filter:blur(4px);
}
.modal-card{
    position:relative;z-index:1;
    background:#fff;border:1.5px solid #e2e8f0;
    border-radius:20px;width:90%;max-width:460px;
    max-height:90vh;overflow-y:auto;
    box-shadow:0 24px 64px rgba(0,0,0,.25);
    animation:mIn .2s ease;
}
@keyframes mIn{from{opacity:0;transform:scale(.95) translateY(10px);}to{opacity:1;transform:none;}}
.modal-top{
    display:flex;align-items:center;gap:1rem;
    padding:1.25rem 1.25rem .9rem;
    border-bottom:1px solid #f1f5f9;
}
.m-av{width:64px;height:64px;border-radius:50%;flex-shrink:0;object-fit:cover;border:3px solid #e2e8f0;}
.m-av-init{
    width:64px;height:64px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    font-family:'Sora',sans-serif;font-size:1.35rem;font-weight:800;
    color:#fff;background:#1e40af;border:3px solid rgba(30,64,175,.2);
}
.m-name{font-family:'Sora',sans-serif;font-size:.98rem;font-weight:800;color:#0f172a;}
.m-pos{font-size:.78rem;color:#64748b;margin-top:.15rem;}
.m-dept-badge{
    display:inline-block;margin-top:.3rem;font-size:.67rem;font-weight:700;
    padding:.1rem .5rem;border-radius:20px;
    background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;
}
.m-close{
    position:absolute;top:.85rem;right:.85rem;
    background:none;border:none;cursor:pointer;
    color:#94a3b8;font-size:1rem;padding:.25rem;border-radius:6px;
    transition:background .12s;
}
.m-close:hover{background:#f1f5f9;}
.m-body{padding:.85rem 1.25rem 1.25rem;}
.m-sec{margin-bottom:.85rem;}
.m-sec:last-child{margin-bottom:0;}
.m-sec-title{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:.4rem;}
.m-grid{display:grid;grid-template-columns:1fr 1fr;gap:.4rem;}
.m-field .lbl{color:#94a3b8;font-size:.67rem;}
.m-field .val{font-weight:500;font-size:.77rem;color:#1e293b;}
.m-field .val.empty{color:#cbd5e1;font-style:italic;}

/* ── Print ───────────────────────────────────────────────── */
@media print{
    .chart-topbar,.chart-controls{display:none!important;}
    .chart-page{padding:.5rem!important;}
    .chart-paper{box-shadow:none!important;border-radius:0!important;}
}
</style>
</head>
<body>

<!-- Top nav bar -->
<div class="chart-topbar">
    <div class="chart-topbar-logo">
        <img src="assets/img/logo.png" alt="UTC Logo"
             onerror="this.style.display='none';">
        <div class="chart-topbar-logo-text">
            Urban Tradewell Corporation
            <span>Proudly Serving Since 1994 · We Serve. We Care.</span>
        </div>
    </div>
    <div class="chart-topbar-right">
        <div class="dept-label-small"><?= htmlspecialchars($theme['label']) ?> Department</div>
        <div class="dept-label-large">Organizational Chart</div>
    </div>
</div>

<!-- Controls -->
<div class="chart-controls">
    <div class="dept-select-wrap">
        <span class="dept-select-label"><i class="bi bi-building"></i> Department:</span>
        <select class="dept-select" onchange="window.location.href='orgchart-dept.php?dept='+encodeURIComponent(this.value)">
            <?php foreach ($allDepts as $d): ?>
            <option value="<?= htmlspecialchars($d) ?>" <?= ($d === $deptParam) ? 'selected' : '' ?>>
                <?= htmlspecialchars($d) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn-ctrl" onclick="zoomIn()"><i class="bi bi-zoom-in"></i> Zoom In</button>
    <button class="btn-ctrl" onclick="zoomOut()"><i class="bi bi-zoom-out"></i> Zoom Out</button>
    <button class="btn-ctrl" onclick="resetZoom()"><i class="bi bi-aspect-ratio"></i> Reset</button>
    <button class="btn-ctrl" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <a href="orgchart.php" class="btn-ctrl"><i class="bi bi-grid-3x3-gap"></i> All Depts</a>
    <span class="emp-count-badge"><strong><?= $totalEmps ?></strong> employees</span>
</div>

<!-- Chart page -->
<div class="chart-page" id="chartPage">
<div class="chart-paper" id="chartPaper">

    <!-- SVG connector overlay -->
    <svg id="connectorSvg"></svg>

    <!-- Dept title -->
    <div class="dept-title-wrap">
        <div class="dept-title-box" style="background:<?= $theme['primary'] ?>;">
            <?= htmlspecialchars(strtoupper($deptParam)) ?>
        </div>
    </div>

    <?php if (empty($employees)): ?>
    <div style="text-align:center;padding:3rem;color:#64748b;">
        <div style="font-size:2rem;margin-bottom:.75rem;">📭</div>
        <div style="font-weight:700;margin-bottom:.35rem;">No active employees found for this department.</div>
        <div style="font-size:.8rem;">Querying: <code><?= htmlspecialchars($deptParam) ?></code></div>
        <div style="font-size:.75rem;margin-top:.5rem;color:#94a3b8;">
            Available departments in DB:
            <?= htmlspecialchars(implode(', ', $allDepts)) ?>
        </div>
    </div>
    <?php else: ?>

    <?php
    // ── Operations Manager / top-level OM ────────────────────
    $topManagerId = '';
    if (!empty($omByPos)) {
        $firstPos   = array_key_first($omByPos);
        $firstEmps  = $omByPos[$firstPos];
        echo '<div style="display:flex;justify-content:center;margin-bottom:1rem;">';
        $topManagerId = renderPosNode($firstPos, $firstEmps, $theme, 'om-');
        echo '</div>';
        // Remaining OM positions (e.g. Sales Supervisor separate from Ops Manager)
        $remaining = array_slice($omByPos, 1, null, true);
    } else {
        $remaining = [];
    }
    ?>

    <!-- 3-column chart body -->
    <div class="chart-body" id="chartBody">

        <!-- LEFT: Logistics -->
        <div class="chart-col" id="colLog">
            <div class="cat-header-box" id="hdrLog" style="background:<?= $theme['cat'] ?>;">
                Logistics
            </div>
            <?php
            foreach ($logByPos as $posTitle => $posEmps) {
                renderPosNode($posTitle, $posEmps, $theme, 'log-');
            }
            ?>
        </div>

        <!-- CENTER: Sales Operation -->
        <div class="chart-col chart-col-center" id="colDsp">
            <?php
            // Render any remaining OM nodes (e.g. Sales Supervisor) above Sales header
            foreach ($remaining as $posTitle => $posEmps) {
                echo '<div style="display:flex;justify-content:center;margin-bottom:.5rem;">';
                renderPosNode($posTitle, $posEmps, $theme, 'om2-');
                echo '</div>';
            }
            ?>
            <div class="cat-header-box" id="hdrDsp" style="background:<?= $theme['cat'] ?>;">
                Sales Operation
            </div>

            <?php if ($hasBranches && count($dspBranch) > 1): ?>
            <!-- Branch sub-columns -->
            <div class="branch-cols" id="branchCols">
            <?php foreach ($dspBranch as $branch => $bEmps):
                $bByPos = groupAndSortByPosition($bEmps);
                $branchId = 'branch-' . preg_replace('/\W+/','-',strtolower($branch));
            ?>
            <div class="branch-col" id="<?= htmlspecialchars($branchId) ?>">
                <?php if ($branch !== 'MAIN'): ?>
                <div class="branch-label" style="background:<?= $theme['cat'] ?>;">
                    <?= htmlspecialchars($branch) ?>
                </div>
                <?php endif; ?>
                <?php foreach ($bByPos as $posTitle => $posEmps): ?>
                <?php renderPosNode($posTitle, $posEmps, $theme, $branchId.'-'); ?>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php else: ?>
            <!-- No branches — single column -->
            <?php foreach ($dspByPos as $posTitle => $posEmps): ?>
            <?php renderPosNode($posTitle, $posEmps, $theme, 'dsp-'); ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Admin & Accounting -->
        <div class="chart-col" id="colAdm">
            <div class="cat-header-box" id="hdrAdm" style="background:<?= $theme['cat'] ?>;">
                Admin &amp; Accounting
            </div>
            <?php
            foreach ($admByPos as $posTitle => $posEmps) {
                renderPosNode($posTitle, $posEmps, $theme, 'adm-');
            }
            ?>
        </div>

    </div><!-- /chart-body -->

    <?php endif; // employees not empty ?>

</div><!-- /chart-paper -->
</div><!-- /chart-page -->

<!-- Profile modal -->
<div id="profileModal">
    <div class="modal-bg" onclick="closeProfile()"></div>
    <div class="modal-card">
        <button class="m-close" onclick="closeProfile()"><i class="bi bi-x-lg"></i></button>
        <div class="modal-top">
            <div style="flex-shrink:0;">
                <img id="mAvImg" class="m-av" src="" alt="" style="display:none;">
                <div id="mAvInit" class="m-av-init"></div>
            </div>
            <div>
                <div class="m-name"  id="mName"></div>
                <div class="m-pos"   id="mPos"></div>
                <div class="m-dept-badge" id="mDept"></div>
            </div>
        </div>
        <div class="m-body">
            <div class="m-sec">
                <div class="m-sec-title">Employment</div>
                <div class="m-grid">
                    <div class="m-field"><div class="lbl">Employee ID</div><div class="val" id="mEmpId"></div></div>
                    <div class="m-field"><div class="lbl">Status</div><div class="val" id="mStatus"></div></div>
                    <div class="m-field"><div class="lbl">Category</div><div class="val" id="mCat"></div></div>
                    <div class="m-field"><div class="lbl">Branch</div><div class="val" id="mBranch"></div></div>
                    <div class="m-field"><div class="lbl">Date Hired</div><div class="val" id="mHired"></div></div>
                    <div class="m-field"><div class="lbl">Job Title</div><div class="val" id="mJobTitle"></div></div>
                </div>
            </div>
            <div class="m-sec">
                <div class="m-sec-title">Personal</div>
                <div class="m-grid">
                    <div class="m-field"><div class="lbl">Gender</div><div class="val" id="mGender"></div></div>
                    <div class="m-field"><div class="lbl">Civil Status</div><div class="val" id="mCivil"></div></div>
                    <div class="m-field"><div class="lbl">Birthday</div><div class="val" id="mBday"></div></div>
                </div>
            </div>
            <div class="m-sec">
                <div class="m-sec-title">Contact</div>
                <div class="m-grid">
                    <div class="m-field"><div class="lbl">Phone</div><div class="val" id="mPhone"></div></div>
                    <div class="m-field"><div class="lbl">Mobile</div><div class="val" id="mMobile"></div></div>
                    <div class="m-field" style="grid-column:1/-1;"><div class="lbl">Email</div><div class="val" id="mEmail"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Profile modal ─────────────────────────────────────────────
function showProfile(d) {
    var fill = function(id, v) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = v || '\u2014';
        el.className   = 'val' + (!v ? ' empty' : '');
    };
    var img  = document.getElementById('mAvImg');
    var init = document.getElementById('mAvInit');
    if (d.picture) {
        img.src = d.picture; img.style.display = 'block'; init.style.display = 'none';
        img.onerror = function() { img.style.display='none'; init.style.display='flex'; };
    } else {
        img.style.display = 'none'; init.style.display = 'flex';
    }
    init.textContent = d.initials;
    document.getElementById('mName').textContent  = d.name;
    document.getElementById('mPos').textContent   = d.position || d.jobTitle;
    document.getElementById('mDept').textContent  = d.dept;
    fill('mEmpId',   d.empid);   fill('mStatus',  d.status);
    fill('mCat',     d.category);fill('mBranch',  d.branch);
    fill('mHired',   d.hired);   fill('mJobTitle',d.jobTitle);
    fill('mGender',  d.gender);  fill('mCivil',   d.civil);
    fill('mBday',    d.bday);    fill('mPhone',   d.phone);
    fill('mMobile',  d.mobile);  fill('mEmail',   d.email);
    document.getElementById('profileModal').classList.add('open');
}
function closeProfile() {
    document.getElementById('profileModal').classList.remove('open');
}
document.addEventListener('keydown', function(e) { if (e.key==='Escape') closeProfile(); });

// ── Zoom ──────────────────────────────────────────────────────
var _zoom = 1;
function zoomIn()    { _zoom = Math.min(_zoom + 0.1, 2);   applyZoom(); }
function zoomOut()   { _zoom = Math.max(_zoom - 0.1, 0.4); applyZoom(); }
function resetZoom() { _zoom = 1; applyZoom(); }
function applyZoom() {
    document.getElementById('chartPaper').style.transform = 'scale(' + _zoom + ')';
    document.getElementById('chartPaper').style.transformOrigin = 'top center';
}

// ── Draw SVG connector lines ──────────────────────────────────
// Runs after layout is complete so positions are real pixel coords
function drawConnectors() {
    var svg    = document.getElementById('connectorSvg');
    var paper  = document.getElementById('chartPaper');
    var pRect  = paper.getBoundingClientRect();

    svg.innerHTML = '';
    svg.setAttribute('width',  paper.offsetWidth);
    svg.setAttribute('height', paper.offsetHeight);

    var color    = '<?= $theme['box_border'] ?>';
    var colorDim = '#cbd5e1';

    // Helper: get center-bottom of element relative to paper
    function cb(el) {
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return {
            x: r.left - pRect.left + r.width  / 2,
            y: r.top  - pRect.top  + r.height
        };
    }
    // Helper: get center-top of element relative to paper
    function ct(el) {
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return {
            x: r.left - pRect.left + r.width / 2,
            y: r.top  - pRect.top
        };
    }
    // Helper: get center-left of element relative to paper
    function cl(el) {
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return {
            x: r.left - pRect.left,
            y: r.top  - pRect.top + r.height / 2
        };
    }
    // Helper: get center-right of element relative to paper
    function cr(el) {
        if (!el) return null;
        var r = el.getBoundingClientRect();
        return {
            x: r.left - pRect.left + r.width,
            y: r.top  - pRect.top + r.height / 2
        };
    }

    function line(x1,y1,x2,y2,c,w) {
        var l = document.createElementNS('http://www.w3.org/2000/svg','line');
        l.setAttribute('x1',x1); l.setAttribute('y1',y1);
        l.setAttribute('x2',x2); l.setAttribute('y2',y2);
        l.setAttribute('stroke', c || color);
        l.setAttribute('stroke-width', w || 1.5);
        l.setAttribute('stroke-linecap','round');
        svg.appendChild(l);
    }
    function vline(x,y1,y2,c,w) { line(x,y1,x,y2,c,w); }
    function hline(y,x1,x2,c,w) { line(x1,y,x2,y,c,w); }

    // Connect top manager to 3 column headers
    var tmEl  = document.querySelector('[id^="om-pos-"]');
    var hLog  = document.getElementById('hdrLog');
    var hDsp  = document.getElementById('hdrDsp');
    var hAdm  = document.getElementById('hdrAdm');

    if (tmEl && hLog && hDsp && hAdm) {
        var fromTM = cb(tmEl);
        var toLog  = ct(hLog);
        var toDsp  = ct(hDsp);
        var toAdm  = ct(hAdm);

        // Vertical drop from top manager
        var midY = fromTM.y + 20;
        vline(fromTM.x, fromTM.y, midY, color, 2);
        // Horizontal bar spanning all 3 columns
        var leftX  = Math.min(toLog.x, toDsp.x, toAdm.x);
        var rightX = Math.max(toLog.x, toDsp.x, toAdm.x);
        hline(midY, leftX, rightX, color, 2);
        // Drop to each header
        [toLog, toDsp, toAdm].forEach(function(pt) {
            vline(pt.x, midY, pt.y, color, 2);
        });
    }

    // Connect nodes within Logistics column (top to bottom chain)
    var logNodes = document.querySelectorAll('#colLog .pos-node');
    var logHeader = document.getElementById('hdrLog');
    if (logHeader && logNodes.length > 0) {
        var prev = cb(logHeader);
        logNodes.forEach(function(node) {
            var top = ct(node);
            vline(top.x, prev.y, top.y, color, 1.5);
            prev = cb(node);
        });
    }

    // Connect nodes within Admin column
    var admNodes = document.querySelectorAll('#colAdm .pos-node');
    var admHeader = document.getElementById('hdrAdm');
    if (admHeader && admNodes.length > 0) {
        var prev2 = cb(admHeader);
        admNodes.forEach(function(node) {
            var top = ct(node);
            vline(top.x, prev2.y, top.y, color, 1.5);
            prev2 = cb(node);
        });
    }

    // Connect DSP header to branch cols or direct nodes
    var branchCols = document.querySelectorAll('#branchCols .branch-col');
    if (branchCols.length > 0) {
        var dspHeader = document.getElementById('hdrDsp');
        if (dspHeader) {
            var dspBot = cb(dspHeader);
            var midYd  = dspBot.y + 18;
            vline(dspBot.x, dspBot.y, midYd, color, 1.5);

            // Gather branch column center-tops
            var branchTops = [];
            branchCols.forEach(function(bc) {
                var firstNode = bc.querySelector('.pos-node, .branch-label');
                if (firstNode) {
                    var top = ct(firstNode);
                    branchTops.push({ x: top.x, y: top.y, col: bc });
                }
            });

            if (branchTops.length > 1) {
                var leftBX  = branchTops[0].x;
                var rightBX = branchTops[branchTops.length - 1].x;
                hline(midYd, leftBX, rightBX, color, 1.5);
                branchTops.forEach(function(bt) {
                    vline(bt.x, midYd, bt.y, color, 1.5);
                });
            } else if (branchTops.length === 1) {
                vline(branchTops[0].x, midYd, branchTops[0].y, color, 1.5);
            }

            // Within each branch column, connect nodes top to bottom
            branchCols.forEach(function(bc) {
                var nodes = bc.querySelectorAll('.pos-node');
                var prevEl = bc.querySelector('.branch-label') || dspHeader;
                var prev3 = cb(prevEl);
                nodes.forEach(function(node) {
                    var top = ct(node);
                    vline(top.x, prev3.y, top.y, color, 1.5);
                    prev3 = cb(node);
                });
            });
        }
    } else {
        // Single column DSP — connect nodes downward
        var dspNodes  = document.querySelectorAll('#colDsp .pos-node');
        var dspHeader2 = document.getElementById('hdrDsp');
        if (dspHeader2 && dspNodes.length > 0) {
            var prev4 = cb(dspHeader2);
            dspNodes.forEach(function(node) {
                var top = ct(node);
                vline(top.x, prev4.y, top.y, color, 1.5);
                prev4 = cb(node);
            });
        }
    }
}

// Draw connectors after page loads and fonts settle
window.addEventListener('load', function() {
    setTimeout(drawConnectors, 120);
});
window.addEventListener('resize', function() {
    clearTimeout(window._rto);
    window._rto = setTimeout(drawConnectors, 150);
});
</script>

</body>
</html>