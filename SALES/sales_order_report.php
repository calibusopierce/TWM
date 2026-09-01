<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
rbac_gate($pdo, 'sales_order_report');
rbac_load_permissions($pdo, $_SESSION['UserType'] ?? '');
$isViewOnly = rbac_is_view_only('sales_order_report');

/* -----------------------------------------------------------
   Department session locking (same pattern as attendance.php)
----------------------------------------------------------- */
$user_dept      = $_SESSION['Department'] ?? '';
$is_dept_locked = !in_array($_SESSION['UserType'] ?? '', rbac_superadmin_roles()) &&
                   !in_array(strtolower($_SESSION['UserType'] ?? ''), ['hr']);
$locked_dept    = $is_dept_locked ? $user_dept : null;

/* -----------------------------------------------------------
   Filters
----------------------------------------------------------- */
$dept_filter     = $is_dept_locked ? $locked_dept : ($_GET['department'] ?? '');
$branch_filter   = $_GET['branch']   ?? '';
$area_filter     = $_GET['area']     ?? '';
$salesman_filter = $_GET['salesman'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$customer_filter = $_GET['customer'] ?? '';
$default_date_from = date('Y-m-d');
$default_date_to   = date('Y-m-d');
$date_from     = !empty($_GET['date_from']) ? $_GET['date_from'] : $default_date_from;
$date_to       = !empty($_GET['date_to'])   ? $_GET['date_to']   : $default_date_to;
$search        = trim($_GET['search'] ?? '');

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

function sentinel_is_all($val) {
    return $val === null || $val === '' || strtolower($val) === 'all' || $val === '*';
}

/* -----------------------------------------------------------
   Build WHERE clause
----------------------------------------------------------- */
$where  = "WHERE CAST([DateBook] AS DATE) BETWEEN ? AND ?";
$params = [$date_from, $date_to];

if (!sentinel_is_all($dept_filter)) {
    $where .= " AND RTRIM(LTRIM([Department])) = ?";
    $params[] = $dept_filter;
}
if (!sentinel_is_all($branch_filter)) {
    $where .= " AND RTRIM(LTRIM([Branch])) = ?";
    $params[] = $branch_filter;
}
if (!sentinel_is_all($area_filter)) {
    $where .= " AND RTRIM(LTRIM([Area])) = ?";
    $params[] = $area_filter;
}
if (!sentinel_is_all($salesman_filter)) {
    $where .= " AND RTRIM(LTRIM([SalesmanCode])) = ?";
    $params[] = $salesman_filter;
}
if (!sentinel_is_all($supplier_filter)) {
    $where .= " AND RTRIM(LTRIM([SupplierCode])) = ?";
    $params[] = $supplier_filter;
}
if (!sentinel_is_all($customer_filter)) {
    $where .= " AND RTRIM(LTRIM([CustomerCode])) = ?";
    $params[] = $customer_filter;
}
if ($search !== '') {
    $where .= " AND ([CustomerCode] LIKE ? OR [CustomerName] LIKE ? OR [ProductName] LIKE ? OR [SOID] LIKE ? OR [SalesmanCode] LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

/* -----------------------------------------------------------
   CSV export — same filters, ALL matching rows, no pagination
----------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    $export_sql = "
        SELECT
            [SOID], [DateBook], [RequestDeliveryDate], [Department], [Branch], [Area],
            [CustomerCode], [CustomerName], [Terms], [SalesmanCode], [ProductCode], [ProductName], [UOM],
            [Quantity], [Unit_Price], [Sub_TotalPrice], [SupplierCode], [Status], [Remarks]
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where
        ORDER BY [DateBook] DESC, [SOID] DESC
    ";
    $export_stmt = sqlsrv_query($conn, $export_sql, $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_order_report_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['SOID', 'Date Book', 'Req. Delivery', 'Department', 'Branch', 'Area',
        'Customer Code', 'Customer Name', 'Terms', 'Salesman', 'Product Code', 'Product Name', 'UOM',
        'Quantity', 'Unit Price', 'Sub Total', 'Supplier', 'Status', 'Remarks']);

    if ($export_stmt !== false) {
        while ($r = sqlsrv_fetch_array($export_stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($out, [
                $r['SOID'], so_fmt_date($r['DateBook']), so_fmt_date($r['RequestDeliveryDate']),
                trim($r['Department'] ?? ''), trim($r['Branch'] ?? ''), trim($r['Area'] ?? ''),
                trim($r['CustomerCode'] ?? ''), trim($r['CustomerName'] ?? ''), trim($r['Terms'] ?? ''), trim($r['SalesmanCode'] ?? ''),
                trim($r['ProductCode'] ?? ''), $r['ProductName'], trim($r['UOM'] ?? ''),
                $r['Quantity'], $r['Unit_Price'], $r['Sub_TotalPrice'],
                trim($r['SupplierCode'] ?? ''), trim($r['Status'] ?? ''), $r['Remarks'],
            ]);
        }
    }
    fclose($out);
    exit;
}

/* -----------------------------------------------------------
   Print view — same filters, ALL matching rows, no pagination
----------------------------------------------------------- */
$is_print_all = ($_GET['print'] ?? '') === '1';
$is_print_customers = ($_GET['print_customers'] ?? '') === '1';
$rows = [];
if ($is_print_all) {
    $print_sql = "
        SELECT
            [SOID], [DateBook], [RequestDeliveryDate], [Department], [Branch], [Area],
            [CustomerCode], [CustomerName], [Terms], [SalesmanCode], [ProductCode], [ProductName], [UOM],
            [Quantity], [Unit_Price], [Sub_TotalPrice], [SupplierCode], [Status], [Remarks]
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where
        ORDER BY [DateBook] DESC, [SOID] DESC
    ";
    $print_stmt = sqlsrv_query($conn, $print_sql, $params);
    if ($print_stmt !== false) {
        while ($row = sqlsrv_fetch_array($print_stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    }
}

/* -----------------------------------------------------------
   Stat cards
----------------------------------------------------------- */
$stat_sql = "
    SELECT
        ISNULL(SUM([Quantity]), 0)          AS TotalQty,
        ISNULL(SUM([Sub_TotalPrice]), 0)    AS TotalSales,
        COUNT(DISTINCT [SupplierCode])      AS TotalSuppliers,
        COUNT(DISTINCT [SalesmanCode])      AS TotalSalesmen,
        COUNT(DISTINCT [CustomerCode])      AS TotalCustomers,
        COUNT(*)                            AS TotalRows
    FROM [dbo].[View_SalesOrder_FromDevice2]
    $where
";
$stat_stmt = sqlsrv_query($conn, $stat_sql, $params);
$stat_row  = ['TotalQty' => 0, 'TotalSales' => 0, 'TotalSuppliers' => 0, 'TotalSalesmen' => 0, 'TotalCustomers' => 0, 'TotalRows' => 0];
if ($stat_stmt !== false) {
    $stat_row = sqlsrv_fetch_array($stat_stmt, SQLSRV_FETCH_ASSOC) ?: $stat_row;
}

/* -----------------------------------------------------------
   Pagination count — reused from stat_row, no second scan
----------------------------------------------------------- */
$total_rows = (int)$stat_row['TotalRows'];

/* -----------------------------------------------------------
   Customer detail list for the "Unique Customers" stat card
   modal — scoped to the same filters as the stat above.
   One row per customer (most recent entry in range).
----------------------------------------------------------- */
$stat_customers_list = [];
if (!$is_print_all) {
    $customers_preview_sql = "
        SELECT
            RTRIM(LTRIM([CustomerCode]))      AS CustomerCode,
            MAX(RTRIM(LTRIM([CustomerName]))) AS CustomerName,
            MAX(RTRIM(LTRIM([Area])))         AS Area,
            MAX(RTRIM(LTRIM([Address])))      AS Address,
            MAX(RTRIM(LTRIM([EncodedBy])))    AS EncodedBy
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where AND [CustomerCode] IS NOT NULL
        GROUP BY RTRIM(LTRIM([CustomerCode]))
        ORDER BY MAX(RTRIM(LTRIM([CustomerName])))
    ";
    $customers_preview_stmt = sqlsrv_query($conn, $customers_preview_sql, $params);
    if ($customers_preview_stmt !== false) {
        while ($c = sqlsrv_fetch_array($customers_preview_stmt, SQLSRV_FETCH_ASSOC)) {
            if (trim($c['CustomerCode']) !== '') $stat_customers_list[] = $c;
        }
    } else {
        error_log('sales_order_report customers_preview_sql failed: ' . print_r(sqlsrv_errors(), true));
    }
}

/* -----------------------------------------------------------
   Supplier codes for the "Unique Suppliers" stat card hover
   preview — scoped to the same filters as the stat above.
----------------------------------------------------------- */
$stat_suppliers_list = [];
if (!$is_print_all) {
    $suppliers_preview_sql = "
        SELECT DISTINCT RTRIM(LTRIM([SupplierCode])) AS SupplierCode
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where AND [SupplierCode] IS NOT NULL
        ORDER BY SupplierCode
    ";
    $suppliers_preview_stmt = sqlsrv_query($conn, $suppliers_preview_sql, $params);
    if ($suppliers_preview_stmt !== false) {
        while ($sp = sqlsrv_fetch_array($suppliers_preview_stmt, SQLSRV_FETCH_ASSOC)) {
            if (trim($sp['SupplierCode']) !== '') $stat_suppliers_list[] = $sp['SupplierCode'];
        }
    }
}

/* -----------------------------------------------------------
   Salesman detail list for the "Unique Salesmen" stat card
   modal — scoped to the same filters as the stat above.
   One row per salesman (most recent entry in range).
----------------------------------------------------------- */
$stat_salesmen_list = [];
if (!$is_print_all) {
    $salesmen_preview_sql = "
        SELECT
            RTRIM(LTRIM([SalesmanCode]))   AS SalesmanCode,
            MAX(RTRIM(LTRIM([EncodedBy]))) AS EncodedBy,
            MAX(RTRIM(LTRIM([Job_tittle]))) AS JobTitle
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where AND [SalesmanCode] IS NOT NULL
        GROUP BY RTRIM(LTRIM([SalesmanCode]))
        ORDER BY RTRIM(LTRIM([SalesmanCode]))
    ";
    $salesmen_preview_stmt = sqlsrv_query($conn, $salesmen_preview_sql, $params);
    if ($salesmen_preview_stmt !== false) {
        while ($sm = sqlsrv_fetch_array($salesmen_preview_stmt, SQLSRV_FETCH_ASSOC)) {
            if (trim($sm['SalesmanCode']) !== '') $stat_salesmen_list[] = $sm;
        }
    } else {
        error_log('sales_order_report salesmen_preview_sql failed: ' . print_r(sqlsrv_errors(), true));
    }
}
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$offset      = ($page - 1) * $per_page;

/* -----------------------------------------------------------
   Main data query
----------------------------------------------------------- */
if (!$is_print_all) {
    $data_sql = "
        SELECT
            [SOID], [DateBook], [RequestDeliveryDate], [Department], [Branch], [Area],
            [CustomerCode], [CustomerName], [Terms], [SalesmanCode], [ProductCode], [ProductName], [UOM],
            [Quantity], [Unit_Price], [Sub_TotalPrice], [SupplierCode], [Status], [Remarks]
        FROM [dbo].[View_SalesOrder_FromDevice2]
        $where
        ORDER BY [DateBook] DESC, [SOID] DESC
        OFFSET $offset ROWS FETCH NEXT $per_page ROWS ONLY
    ";
    $data_stmt = sqlsrv_query($conn, $data_sql, $params);
    if ($data_stmt !== false) {
        while ($row = sqlsrv_fetch_array($data_stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $row;
    }
}

/* -----------------------------------------------------------
   Group the current page's rows by customer.
   NOTE: pagination above is per line-item, not per customer, so a
   customer whose orders straddle a page boundary will show a partial
   item list on each page. Fine for a quick "who ordered what" glance;
   if that matters later, pagination would need to move to a
   customer/SOID basis instead.
----------------------------------------------------------- */
$customer_groups = [];
foreach ($rows as $r) {
    $code = trim($r['CustomerCode'] ?? '');
    if ($code === '') $code = '(no code)';
    if (!isset($customer_groups[$code])) {
        $customer_groups[$code] = [
            'CustomerCode' => $code,
            'CustomerName' => trim($r['CustomerName'] ?? ''),
            'Area'         => trim($r['Area'] ?? ''),
            'SOIDs'        => [],
            'TotalQty'     => 0,
            'TotalAmount'  => 0,
            'items'        => [],
        ];
    }
    $g =& $customer_groups[$code];
    if (!in_array($r['SOID'], $g['SOIDs'], true)) $g['SOIDs'][] = $r['SOID'];
    $g['TotalQty']    += (float)$r['Quantity'];
    $g['TotalAmount'] += (float)$r['Sub_TotalPrice'];
    $g['items'][] = [
        'SOID'         => $r['SOID'],
        'DateBook'     => so_fmt_date($r['DateBook']),
        'ProductName'  => $r['ProductName'],
        'ProductCode'  => trim($r['ProductCode'] ?? ''),
        'UOM'          => trim($r['UOM'] ?? ''),
        'Quantity'     => (float)$r['Quantity'],
        'UnitPrice'    => (float)$r['Unit_Price'],
        'SubTotal'     => (float)$r['Sub_TotalPrice'],
        'Salesman'     => trim($r['SalesmanCode'] ?? ''),
        'Department'   => trim($r['Department'] ?? ''),
        'Branch'       => trim($r['Branch'] ?? ''),
        'Status'       => trim($r['Status'] ?? ''),
        'Remarks'      => $r['Remarks'],
    ];
    unset($g);
}

/* -----------------------------------------------------------
   Dropdown source lists (not needed for the print-all view)
----------------------------------------------------------- */
$dept_list = $branch_list = $area_list = $salesman_list = $supplier_list = $customer_list = [];
if (!$is_print_all) {
    $filter_cache_ttl = 300; // seconds — dropdown values rarely change day to day
    $filter_cache_key  = 'so_report_filter_lists';
    $filter_cache      = $_SESSION[$filter_cache_key] ?? null;

    if ($filter_cache && (time() - $filter_cache['ts']) < $filter_cache_ttl) {
        $dept_list     = $filter_cache['dept']     ?? [];
        $branch_list   = $filter_cache['branch']   ?? [];
        $area_list     = $filter_cache['area']     ?? [];
        $salesman_list = $filter_cache['salesman'] ?? [];
        $supplier_list = $filter_cache['supplier'] ?? [];
        $customer_list = $filter_cache['customer'] ?? [];
    } else {
        if (!$is_dept_locked) {
            $dept_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([Department])) AS Department FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [Department] IS NOT NULL ORDER BY Department");
            if ($dept_stmt !== false) {
                while ($d = sqlsrv_fetch_array($dept_stmt, SQLSRV_FETCH_ASSOC)) {
                    if (trim($d['Department']) !== '') $dept_list[] = $d['Department'];
                }
            }
        }

        $branch_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([Branch])) AS Branch FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [Branch] IS NOT NULL ORDER BY Branch");
        if ($branch_stmt !== false) {
            while ($b = sqlsrv_fetch_array($branch_stmt, SQLSRV_FETCH_ASSOC)) {
                if (trim($b['Branch']) !== '') $branch_list[] = $b['Branch'];
            }
        }

        $area_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([Area])) AS Area FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [Area] IS NOT NULL ORDER BY Area");
        if ($area_stmt !== false) {
            while ($a = sqlsrv_fetch_array($area_stmt, SQLSRV_FETCH_ASSOC)) {
                if (trim($a['Area']) !== '') $area_list[] = $a['Area'];
            }
        }

        $salesman_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([SalesmanCode])) AS SalesmanCode FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [SalesmanCode] IS NOT NULL ORDER BY SalesmanCode");
        if ($salesman_stmt !== false) {
            while ($sm = sqlsrv_fetch_array($salesman_stmt, SQLSRV_FETCH_ASSOC)) {
                if (trim($sm['SalesmanCode']) !== '') $salesman_list[] = $sm['SalesmanCode'];
            }
        }

        $supplier_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([SupplierCode])) AS SupplierCode FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [SupplierCode] IS NOT NULL ORDER BY SupplierCode");
        if ($supplier_stmt !== false) {
            while ($sp = sqlsrv_fetch_array($supplier_stmt, SQLSRV_FETCH_ASSOC)) {
                if (trim($sp['SupplierCode']) !== '') $supplier_list[] = $sp['SupplierCode'];
            }
        }

        $customer_stmt = sqlsrv_query($conn, "SELECT DISTINCT RTRIM(LTRIM([CustomerCode])) AS CustomerCode, RTRIM(LTRIM([CustomerName])) AS CustomerName FROM [dbo].[View_SalesOrder_FromDevice2] WHERE [CustomerCode] IS NOT NULL ORDER BY CustomerName");
        if ($customer_stmt !== false) {
            while ($c = sqlsrv_fetch_array($customer_stmt, SQLSRV_FETCH_ASSOC)) {
                if (trim($c['CustomerCode']) !== '') $customer_list[] = $c;
            }
        }

        $_SESSION[$filter_cache_key] = [
            'ts'       => time(),
            'dept'     => $dept_list,
            'branch'   => $branch_list,
            'area'     => $area_list,
            'salesman' => $salesman_list,
            'supplier' => $supplier_list,
            'customer' => $customer_list,
        ];
    }
}

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
}
function so_fmt_date($v) {
    if (!$v) return '';
    if ($v instanceof DateTime) return $v->format('M d, Y');
    return date('M d, Y', strtotime($v));
}
function so_fmt_money($v) { return number_format((float)$v, 2); }

if ($is_print_customers) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Order Report · Unique Customers · Print</title>
<style>
  body { font-family: Arial, sans-serif; margin: 20px; color:#111; }
  h1 { font-size: 1.2rem; margin-bottom: 0; }
  .sub { color:#555; font-size:.85rem; margin-bottom: 1rem; }
  table { width:100%; border-collapse:collapse; font-size:.78rem; }
  th, td { border-bottom:1px solid #ccc; padding:.4rem .5rem; text-align:left; }
  th { background:#f1f5f9; text-transform:uppercase; font-size:.68rem; }
</style>
</head>
<body onload="window.print()">
  <h1>Sales Order Report — Unique Customers</h1>
  <div class="sub">
    <?= h(date('M d, Y', strtotime($date_from))) ?> to <?= h(date('M d, Y', strtotime($date_to))) ?>
    — <?= number_format(count($stat_customers_list)) ?> customer<?= count($stat_customers_list) !== 1 ? 's' : '' ?>
  </div>
  <table>
    <thead>
      <tr>
        <th>Customer Code</th><th>Customer Name</th><th>Area</th><th>Address</th><th>Encoded By</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($stat_customers_list as $c): ?>
      <tr>
        <td><?= h($c['CustomerCode']) ?></td>
        <td><?= h($c['CustomerName']) ?></td>
        <td><?= h($c['Area'] ?: '—') ?></td>
        <td><?= h($c['Address'] ?: '—') ?></td>
        <td><?= h($c['EncodedBy'] ?: '—') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
    <?php
    exit;
}

if ($is_print_all) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Order Report · Print</title>
<style>
  body { font-family: Arial, sans-serif; margin: 20px; color:#111; }
  h1 { font-size: 1.2rem; margin-bottom: 0; }
  .sub { color:#555; font-size:.85rem; margin-bottom: 1rem; }
  table { width:100%; border-collapse:collapse; font-size:.72rem; }
  th, td { border-bottom:1px solid #ccc; padding:.3rem .4rem; text-align:left; white-space:nowrap; }
  th { background:#f1f5f9; text-transform:uppercase; font-size:.65rem; }
  .amt { text-align:right; }
</style>
</head>
<body onload="window.print()">
  <h1>Sales Order Report</h1>
  <div class="sub">
    <?= h(date('M d, Y', strtotime($date_from))) ?> to <?= h(date('M d, Y', strtotime($date_to))) ?>
    — <?= number_format(count($rows)) ?> record<?= count($rows) !== 1 ? 's' : '' ?>
  </div>
  <table>
    <thead>
      <tr>
        <th>SOID</th><th>Date Book</th><th>Req. Delivery</th><th>Department</th><th>Branch</th>
        <th>Area</th><th>Customer Name</th><th>Terms</th><th>Salesman</th><th>Product</th><th>UOM</th>
        <th class="amt">Qty</th><th class="amt">Unit Price</th><th class="amt">Sub Total</th>
        <th>Supplier</th><th>Status</th><th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['SOID']) ?></td>
        <td><?= h(so_fmt_date($r['DateBook'])) ?></td>
        <td><?= h(so_fmt_date($r['RequestDeliveryDate'])) ?></td>
        <td><?= h(trim($r['Department'] ?? '')) ?></td>
        <td><?= h(trim($r['Branch'] ?? '')) ?></td>
        <td><?= h(trim($r['Area'] ?? '')) ?></td>
        <td><?= h(trim($r['CustomerName'] ?? '')) ?></td>
        <td><?= h(trim($r['Terms'] ?? '')) ?></td>
        <td><?= h(trim($r['SalesmanCode'] ?? '')) ?></td>
        <td><?= h($r['ProductName']) ?></td>
        <td><?= h(trim($r['UOM'] ?? '')) ?></td>
        <td class="amt"><?= h(number_format((float)$r['Quantity'])) ?></td>
        <td class="amt">₱<?= so_fmt_money($r['Unit_Price']) ?></td>
        <td class="amt">₱<?= so_fmt_money($r['Sub_TotalPrice']) ?></td>
        <td><?= h(trim($r['SupplierCode'] ?? '')) ?></td>
        <td><?= h(trim($r['Status'] ?? '')) ?></td>
        <td><?= h($r['Remarks']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Order Report · Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  <style>
    .stat-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .stat-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 1rem 1.2rem;
      display: flex; align-items: center; gap: .85rem;
      box-shadow: var(--shadow-sm);
    }
    .stat-icon {
      width: 40px; height: 40px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.15rem; flex-shrink: 0;
    }
    .stat-val { font-size: 1.5rem; font-weight: 800; color: var(--text-main); line-height: 1; }
    .stat-lbl { font-size: .72rem; color: var(--text-muted); margin-top: .15rem;
                font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    .si-qty    { background:rgba(87,167,71,.12);   color:#57a747; }
    .si-sales  { background:rgba(245,158,11,.12);  color:#b45309; }
    .si-suppliers { background:rgba(99,102,241,.12); color:#6366f1; }
    .si-salesmen { background:rgba(236,72,153,.12); color:#ec4899; }
    .si-customers { background:rgba(14,165,233,.12); color:#0ea5e9; }

    .stat-card-clickable { cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .1s; }
    .stat-card-clickable:hover { border-color: var(--primary); box-shadow: 0 4px 14px rgba(0,0,0,.08); transform: translateY(-1px); }
    .stat-card-clickable:active { transform: translateY(0); }

    .stat-modal-list { max-height: 55vh; overflow-y: auto; }
    .stat-modal-chip {
      display: inline-block; background: rgba(236,72,153,.1); color:#ec4899;
      border-radius: 999px; padding: .3rem .75rem; margin: .2rem .3rem .2rem 0;
      font-weight: 600; font-size: .82rem;
    }
    .stat-modal-chip.chip-suppliers { background: rgba(99,102,241,.1); color:#6366f1; }
    .stat-modal-empty { color: var(--text-muted); font-size: .85rem; padding: 1rem 0; text-align: center; }

    .customer-modal-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .customer-modal-table thead tr { background: var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .customer-modal-table th { padding:.6rem .75rem; text-align:left; font-size:.7rem; font-weight:700;
                   text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); }
    .customer-modal-table td { padding:.6rem .75rem; border-bottom:1px solid var(--border); vertical-align:top; }
    .customer-modal-table tbody tr:hover { background: var(--surface-alt,#f8fafc); }

    .so-header {
      display: flex; flex-direction: column; gap: .85rem;
      padding: 1.1rem 1.2rem; border-bottom: 1px solid var(--border);
    }
    .so-header-top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }

    .filter-bar { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; width: 100%; }
    .filter-bar input, .filter-bar select {
      padding:.42rem .75rem; border:1px solid var(--border);
      border-radius:var(--radius); font-size:.84rem;
      background:var(--surface); color:var(--text-main);
      flex: 1 1 140px; min-width: 130px; max-width: 220px;
    }
    .filter-bar input[type="search"], .filter-bar input[type="text"] { flex: 1 1 200px; max-width: 260px; }
    .filter-bar .btn { flex: 0 0 auto; white-space: nowrap; }
    .filter-bar input:focus, .filter-bar select:focus { outline:none; border-color:var(--primary); }
    .filter-bar .dept-readonly {
      padding:.42rem .75rem; border:1px solid var(--border); border-radius:var(--radius);
      font-size:.84rem; background:var(--surface-alt,#f8fafc); color:var(--text-main);
      font-weight:600; min-width:150px;
    }

    /* === Larger, accounting-friendly table === */
    .so-table { width:100%; border-collapse:collapse; font-size:1rem; }
    .so-table thead tr { background:var(--surface-alt,#f8fafc); border-bottom:2px solid var(--border); }
    .so-table th { padding:.85rem 1rem; text-align:left; font-size:.8rem; font-weight:700;
                   text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); white-space:nowrap; }
    .so-table td { padding:.85rem 1rem; border-bottom:1px solid var(--border);
                   vertical-align:middle; color:var(--text-main); font-size:1rem; white-space:nowrap; }
    .so-table tbody tr:hover { background:var(--surface-alt,#f8fafc); }
    .so-number { font-weight:700; color:var(--primary); font-size:1rem; }
    .amount-cell { text-align:right; font-weight:700; font-size:1.02rem; }
    .qty-cell { text-align:right; font-weight:600; }

    .pill-chip {
      font-size:.8rem; background:#6366f11a; color:#4f46e5;
      padding:.2rem .65rem; border-radius:999px; font-weight:600;
    }

    .badge-status {
      display:inline-flex; align-items:center; gap:.3rem;
      padding:.25rem .7rem; border-radius:999px;
      font-size:.78rem; font-weight:700; letter-spacing:.03em;
    }
    .badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
    .bs-draft     { background:rgba(245,158,11,.12); color:#b45309; }
    .bs-draft::before     { background:#f59e0b; }
    .bs-approved  { background:rgba(16,185,129,.12); color:#065f46; }
    .bs-approved::before  { background:#10b981; }
    .bs-cancelled { background:rgba(239,68,68,.12);  color:#991b1b; }
    .bs-cancelled::before { background:#ef4444; }

    .empty-row td { text-align:center; padding:3rem 1rem; color:var(--text-muted); }

    .pagination-bar {
      display:flex; justify-content:space-between; align-items:center;
      padding:1rem 1.2rem; font-size:.85rem; color:var(--text-muted);
    }
    .pagination-bar a {
      padding:.4rem .75rem; border-radius:var(--radius); background:var(--surface-alt,#f1f5f9);
      text-decoration:none; color:var(--text-main); margin:0 .15rem; font-weight:600; font-size:.82rem;
    }
    .pagination-bar a.active { background:var(--primary); color:#fff; }

    /* === Print styles === */
    @media print {
      .topbar, .filter-bar, form, .pagination-bar, .count-chip,
      .page-header .btn, .page-header button { display:none !important; }
      .page-header { text-align:center; display:block !important; }
      .page-header > div:first-child { margin:0 auto; }
      .page-title { font-size:1.3rem; }
      .page-subtitle { font-size:.85rem; color:#555; }
      .main-wrapper { padding:0 !important; margin:0 !important; }
      .stat-row { margin-bottom:1rem; }
      .stat-card, .table-card { box-shadow:none !important; border:1px solid #ccc !important; }
      .so-table { font-size:.78rem; }
      .so-table th, .so-table td { padding:.35rem .45rem; }
      .so-table thead { display: table-header-group; }
      .so-table tr { page-break-inside: avoid; }
      body { background:#fff !important; }
    }
  </style>
</head>
<body>

<?php $topbar_page = 'sales_order_report'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="main-wrapper">


  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">Sales Order Report</div>
      <div class="page-subtitle">Accounting view of sales orders — <?= h(date('M d, Y', strtotime($date_from))) ?> to <?= h(date('M d, Y', strtotime($date_to))) ?></div>
    </div>
    <div style="display:flex;gap:.6rem;">
      <button onclick="printAllFiltered()" class="btn btn-secondary-custom">
        <i class="bi bi-printer-fill"></i> Print
      </button>
      <a href="?export=csv&<?= h(http_build_query($_GET)) ?>" class="btn btn-add">
        <i class="bi bi-download"></i> Export CSV
      </a>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="stat-row">
    <div class="stat-card stat-card-clickable" data-bs-toggle="modal" data-bs-target="#customersModal">
      <div class="stat-icon si-customers"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-val"><?= number_format((int)$stat_row['TotalCustomers']) ?></div><div class="stat-lbl">Unique Customers</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-qty"><i class="bi bi-boxes"></i></div>
      <div><div class="stat-val"><?= number_format((float)$stat_row['TotalQty']) ?></div><div class="stat-lbl">Total Quantity</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-sales"><i class="bi bi-cash-stack"></i></div>
      <div><div class="stat-val">₱<?= so_fmt_money($stat_row['TotalSales']) ?></div><div class="stat-lbl">Total Sales Value</div></div>
    </div>
    <div class="stat-card stat-card-clickable" data-bs-toggle="modal" data-bs-target="#suppliersModal">
      <div class="stat-icon si-suppliers"><i class="bi bi-truck"></i></div>
      <div><div class="stat-val"><?= number_format((int)$stat_row['TotalSuppliers']) ?></div><div class="stat-lbl">Unique Suppliers</div></div>
    </div>
    <div class="stat-card stat-card-clickable" data-bs-toggle="modal" data-bs-target="#salesmenModal">
      <div class="stat-icon si-salesmen"><i class="bi bi-person-badge"></i></div>
      <div><div class="stat-val"><?= number_format((int)$stat_row['TotalSalesmen']) ?></div><div class="stat-lbl">Unique Salesmen</div></div>
    </div>
  </div>

  <!-- Customers Modal -->
  <div class="modal fade" id="customersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-people-fill" style="color:#0ea5e9;"></i> Unique Customers <span class="count-chip"><?= number_format((int)$stat_row['TotalCustomers']) ?></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($stat_customers_list)): ?>
            <div class="stat-modal-empty">No customers found for current filters.</div>
          <?php else: ?>
            <div class="stat-modal-list">
              <table class="customer-modal-table">
                <thead>
                  <tr>
                    <th>Customer Code</th>
                    <th>Customer Name</th>
                    <th>Area</th>
                    <th>Address</th>
                    <th>Encoded By</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($stat_customers_list as $c): ?>
                  <tr>
                    <td><?= h($c['CustomerCode']) ?></td>
                    <td><?= h($c['CustomerName']) ?></td>
                    <td><?= h($c['Area'] ?: '—') ?></td>
                    <td><?= h($c['Address'] ?: '—') ?></td>
                    <td><?= h($c['EncodedBy'] ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" onclick="printCustomersModal()">
            <i class="bi bi-printer-fill"></i> Print
          </button>
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Suppliers Modal -->
  <div class="modal fade" id="suppliersModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-truck" style="color:#6366f1;"></i> Unique Suppliers <span class="count-chip"><?= number_format((int)$stat_row['TotalSuppliers']) ?></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($stat_suppliers_list)): ?>
            <div class="stat-modal-empty">No suppliers found for current filters.</div>
          <?php else: ?>
            <div class="stat-modal-list">
              <?php foreach ($stat_suppliers_list as $sp): ?>
                <span class="stat-modal-chip chip-suppliers"><?= h($sp) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Salesmen Modal -->
  <div class="modal fade" id="salesmenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-badge" style="color:#ec4899;"></i> Unique Salesmen <span class="count-chip"><?= number_format((int)$stat_row['TotalSalesmen']) ?></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($stat_salesmen_list)): ?>
            <div class="stat-modal-empty">No salesmen found for current filters.</div>
          <?php else: ?>
            <div class="stat-modal-list">
              <table class="customer-modal-table">
                <thead>
                  <tr>
                    <th>Salesman Code</th>
                    <th>Encoded By</th>
                    <th>Job Title</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($stat_salesmen_list as $sm): ?>
                  <tr>
                    <td><?= h($sm['SalesmanCode']) ?></td>
                    <td><?= h($sm['EncodedBy'] ?: '—') ?></td>
                    <td><?= h($sm['JobTitle'] ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Table Card -->
  <div class="table-card">
    <div class="so-header">
      <div class="so-header-top">
        <div class="table-card-title">
          <i class="bi bi-list-ul" style="color:var(--primary-light);"></i>
          Sales Orders
          <span class="count-chip"><?= number_format(count($customer_groups)) ?> customer<?= count($customer_groups) !== 1 ? 's' : '' ?></span>
        </div>
      </div>

      <!-- Filters -->
      <form method="GET" class="filter-bar">
        <input type="date" name="date_from" value="<?= h($date_from) ?>">
        <input type="date" name="date_to" value="<?= h($date_to) ?>">

        <?php if ($is_dept_locked): ?>
          <div class="dept-readonly"><?= h($locked_dept ?: 'N/A') ?></div>
          <input type="hidden" name="department" value="<?= h($locked_dept) ?>">
        <?php else: ?>
          <select name="department">
            <option value="">All Departments</option>
            <?php foreach ($dept_list as $d): ?>
              <option value="<?= h($d) ?>" <?= $dept_filter === $d ? 'selected' : '' ?>><?= h($d) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <select name="branch">
          <option value="">All Branches</option>
          <?php foreach ($branch_list as $b): ?>
            <option value="<?= h($b) ?>" <?= $branch_filter === $b ? 'selected' : '' ?>><?= h($b) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="area">
          <option value="">All Areas</option>
          <?php foreach ($area_list as $a): ?>
            <option value="<?= h($a) ?>" <?= $area_filter === $a ? 'selected' : '' ?>><?= h($a) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="salesman">
          <option value="">All Salesmen</option>
          <?php foreach ($salesman_list as $sm): ?>
            <option value="<?= h($sm) ?>" <?= $salesman_filter === $sm ? 'selected' : '' ?>><?= h($sm) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="supplier">
          <option value="">All Suppliers</option>
          <?php foreach ($supplier_list as $sp): ?>
            <option value="<?= h($sp) ?>" <?= $supplier_filter === $sp ? 'selected' : '' ?>><?= h($sp) ?></option>
          <?php endforeach; ?>
        </select>

        <select name="customer">
          <option value="">All Customers</option>
          <?php foreach ($customer_list as $c): ?>
            <option value="<?= h($c['CustomerCode']) ?>" <?= $customer_filter === $c['CustomerCode'] ? 'selected' : '' ?>><?= h($c['CustomerName'] ?: $c['CustomerCode']) ?></option>
          <?php endforeach; ?>
        </select>

        <input type="text" name="search" placeholder="🔍  SOID / Customer / Product / Salesman…" value="<?= h($search) ?>">

        <button type="submit" class="btn btn-add" style="padding:.42rem .9rem; font-size:.84rem;">
          <i class="bi bi-funnel-fill"></i> Filter
        </button>
        <?php if ($search || $branch_filter || $area_filter || $salesman_filter || $supplier_filter || $customer_filter || (!$is_dept_locked && $dept_filter) || $date_from !== $default_date_from || $date_to !== $default_date_to): ?>
          <a href="<?= base_url('SALES/sales_order_report.php') ?>" class="btn btn-secondary-custom" style="padding:.42rem .9rem; font-size:.84rem;">
            <i class="bi bi-x-lg"></i> Reset
          </a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-responsive">
      <table class="so-table">
        <thead>
          <tr>
            <th>Customer Name</th>
            <th>Area</th>
            <th style="text-align:right;">Orders</th>
            <th style="text-align:right;">Total Qty</th>
            <th style="text-align:right;">Total Amount (₱)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customer_groups)): ?>
            <tr class="empty-row">
              <td colspan="6">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>
                No sales orders found for the selected filters.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($customer_groups as $code => $g): ?>
            <tr class="customer-row" style="cursor:pointer;" onclick="openCustomerOrderModal('<?= h(addslashes($code)) ?>')">
              <td><?= h($g['CustomerName'] ?: $g['CustomerCode']) ?></td>
              <td><?= h($g['Area']) ?: '—' ?></td>
              <td class="qty-cell"><?= number_format(count($g['SOIDs'])) ?></td>
              <td class="qty-cell"><?= number_format($g['TotalQty']) ?></td>
              <td class="amount-cell">₱ <?= so_fmt_money($g['TotalAmount']) ?></td>
              <td style="text-align:center;color:var(--text-muted);"><i class="bi bi-chevron-right"></i></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Customer Order Items Modal (single, reused, filled via JS) -->
    <div class="modal fade" id="customerOrderModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="customerOrderModalTitle"><i class="bi bi-receipt" style="color:var(--primary-light);"></i> Order Items</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <table class="customer-modal-table">
              <thead>
                <tr>
                  <th>SOID</th>
                  <th>Date Book</th>
                  <th>Product</th>
                  <th>UOM</th>
                  <th style="text-align:right;">Qty</th>
                  <th style="text-align:right;">Unit Price</th>
                  <th style="text-align:right;">Sub Total (₱)</th>
                  <th>Salesman</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="customerOrderModalBody"></tbody>
            </table>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    // Grouped data for the current page only — matches $customer_groups above.
    const soCustomerGroups = <?= json_encode($customer_groups, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    function openCustomerOrderModal(code) {
      const g = soCustomerGroups[code];
      if (!g) return;

      document.getElementById('customerOrderModalTitle').innerHTML =
        '<i class="bi bi-receipt" style="color:var(--primary-light);"></i> ' +
        (g.CustomerName || g.CustomerCode) +
        ' <span class="count-chip">' + g.items.length + ' item' + (g.items.length !== 1 ? 's' : '') + '</span>';

      const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      })[c]);
      const money = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      const body = document.getElementById('customerOrderModalBody');
      body.innerHTML = g.items.map(it => `
        <tr>
          <td><span class="so-number">${esc(it.SOID)}</span></td>
          <td style="color:var(--text-muted);font-size:.9rem;">${esc(it.DateBook)}</td>
          <td>
            <div style="font-weight:600;">${esc(it.ProductName)}</div>
            <div style="font-size:.78rem;color:var(--text-muted);">${esc(it.ProductCode)}</div>
          </td>
          <td>${esc(it.UOM)}</td>
          <td class="qty-cell">${Number(it.Quantity || 0).toLocaleString()}</td>
          <td class="amount-cell">₱ ${money(it.UnitPrice)}</td>
          <td class="amount-cell">₱ ${money(it.SubTotal)}</td>
          <td>${esc(it.Salesman)}</td>
          <td>${esc(it.Status) || '—'}</td>
        </tr>
      `).join('');

      new bootstrap.Modal(document.getElementById('customerOrderModal')).show();
    }
    </script>

    <?php if (!empty($rows)): ?>
    <div class="pagination-bar">
      <div>Showing <?= number_format(count($customer_groups)) ?> customer<?= count($customer_groups) !== 1 ? 's' : '' ?> (<?= count($rows) ?> of <?= number_format($total_rows) ?> line items on this page)</div>
      <div>
        <?php
        $qs = $_GET;
        for ($p = 1; $p <= $total_pages; $p++) {
            $qs['page'] = $p;
            $active = $p === $page ? 'active' : '';
            echo '<a class="' . $active . '" href="?' . h(http_build_query($qs)) . '">' . $p . '</a>';
            if ($p > 8) { echo '<span style="padding:0 .3rem;">…</span>'; break; }
        }
        ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /main-wrapper -->

<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
function printAllFiltered() {
    var qs = new URLSearchParams(window.location.search);
    qs.set('print', '1');
    window.open('?' + qs.toString(), '_blank');
}
function printCustomersModal() {
    var qs = new URLSearchParams(window.location.search);
    qs.set('print_customers', '1');
    window.open('?' + qs.toString(), '_blank');
}
</script>
</body>
</html>