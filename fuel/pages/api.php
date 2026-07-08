<?php
require_once __DIR__ . '/../includes/twm_auth_bridge.php';
fuel_enforce_system_access(true); // JSON 403 if not approved (this is an AJAX endpoint)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/functions.php';
mb_internal_encoding('UTF-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    case 'dashboard':
        $date = $_GET['date'] ?? date('Y-m-d');
        $dept = $_GET['department'] ?? '';
        echo json_encode(getDashboardStats($date, $dept), JSON_UNESCAPED_UNICODE);
        break;

    case 'fuel_records':
        $isSuperAdmin  = !empty($_SESSION['is_superadmin']);
        $allowedDepts  = $_SESSION['allowed_depts'] ?? [];
        $requestedDept = $_GET['department'] ?? '';

        // Enforce department restriction — non-superadmins can only see their assigned depts
        if (!$isSuperAdmin && !empty($allowedDepts)) {
            if ($requestedDept && !in_array($requestedDept, $allowedDepts)) {
                // Requested a dept they're not assigned to — return empty
                echo json_encode(['records' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20, 'totalPages' => 0], JSON_UNESCAPED_UNICODE);
                break;
            }
            if (!$requestedDept) {
                // "All Departments" for this user = only their assigned depts
                $filters = [
                    'departments' => $allowedDepts,  // multi-dept filter
                    'plate'       => $_GET['plate']      ?? '',
                    'supplier'    => $_GET['supplier']   ?? '',
                    'date_from'   => $_GET['date_from']  ?? '',
                    'date_to'     => $_GET['date_to']    ?? '',
                    'requested'   => $_GET['requested']  ?? '',
                ];
                $page     = max(1, (int)($_GET['page']      ?? 1));
                $pageSize = max(1, min(9999, (int)($_GET['pageSize'] ?? 20)));
                $extra    = ['sort_asc' => !empty($_GET['sort_asc'])];
                echo json_encode(getFuelRecords($filters, $page, $pageSize, $extra), JSON_UNESCAPED_UNICODE);
                break;
            }
        }

        $filters = [
            'department' => $requestedDept,
            'plate'      => $_GET['plate']      ?? '',
            'supplier'   => $_GET['supplier']   ?? '',
            'date_from'  => $_GET['date_from']  ?? '',
            'date_to'    => $_GET['date_to']    ?? '',
            'requested'  => $_GET['requested']  ?? '',
        ];
        $page     = max(1, (int)($_GET['page']      ?? 1));
        $pageSize = max(1, min(9999, (int)($_GET['pageSize'] ?? 20)));
        $extra    = ['sort_asc' => !empty($_GET['sort_asc'])];
        echo json_encode(getFuelRecords($filters, $page, $pageSize, $extra), JSON_UNESCAPED_UNICODE);
        break;

    case 'edit_fuel':
        $isSuperAdmin = !empty($_SESSION['is_superadmin']);
        $userPerms    = getUserPermissions((int)$_SESSION['user_id']);
        if (!$isSuperAdmin && empty($userPerms['perm_edit_fuel'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $fuelId = (int)($_POST['FuelID'] ?? 0);
        $data   = $_POST;
        if (!$fuelId || empty($data['PlateNumber']) || empty($data['Fueldate']) || empty($data['Liters'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // Verify supplier access
        $supplierUsed = trim($data['Supplier'] ?? '');
        if (!userCanUseSupplier((int)$_SESSION['user_id'], $supplierUsed, $isSuperAdmin)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to the supplier "' . $supplierUsed . '".'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── Security: PO # and OR # must be unique within the same supplier ──
        $dupCheck = checkFuelPoOrDuplicate($data['POnum'] ?? '', $data['ORnumber'] ?? '', $fuelId, $supplierUsed);
        if ($dupCheck['po_duplicate'] || $dupCheck['or_duplicate']) {
            $msgParts = [];
            if ($dupCheck['po_duplicate']) $msgParts[] = 'PO # "' . trim($data['POnum']) . '" is already used on record #' . $dupCheck['po_fuelid'];
            if ($dupCheck['or_duplicate']) $msgParts[] = 'OR # "' . trim($data['ORnumber']) . '" is already used on record #' . $dupCheck['or_fuelid'];
            echo json_encode(['success' => false, 'message' => implode(' and ', $msgParts) . '.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $result = updateFuelRecord($fuelId, $data);
        echo json_encode(['success' => $result, 'message' => $result ? 'Fuel record updated.' : 'Failed to update record.'], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_fuel':
        $isSuperAdmin = !empty($_SESSION['is_superadmin']);
        $userPerms    = getUserPermissions((int)$_SESSION['user_id']);
        if (!$isSuperAdmin && empty($userPerms['perm_delete_fuel'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $fuelId = (int)($_POST['FuelID'] ?? 0);
        if (!$fuelId) {
            echo json_encode(['success' => false, 'message' => 'Invalid record ID.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $result = deleteFuelRecord($fuelId);
        echo json_encode(['success' => $result, 'message' => $result ? 'Fuel record deleted.' : 'Failed to delete record.'], JSON_UNESCAPED_UNICODE);
        break;

    case 'add_fuel':
        $data = $_POST;
        if (empty($data['PlateNumber']) || empty($data['Fueldate']) || empty($data['Liters'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── Security: verify tank supplier access ─────────────────────────────
        $supplierUsed = trim($data['Supplier'] ?? '');
        $isSuperAdmin = !empty($_SESSION['is_superadmin']);
        if (!userCanUseSupplier((int)$_SESSION['user_id'], $supplierUsed, $isSuperAdmin)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to use the supplier "' . $supplierUsed . '".'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── Security: PO # and OR # must be unique within the same supplier ──
        $dupCheck = checkFuelPoOrDuplicate($data['POnum'] ?? '', $data['ORnumber'] ?? '', null, $supplierUsed);
        if ($dupCheck['po_duplicate'] || $dupCheck['or_duplicate']) {
            $msgParts = [];
            if ($dupCheck['po_duplicate']) $msgParts[] = 'PO # "' . trim($data['POnum']) . '" is already used on record #' . $dupCheck['po_fuelid'];
            if ($dupCheck['or_duplicate']) $msgParts[] = 'OR # "' . trim($data['ORnumber']) . '" is already used on record #' . $dupCheck['or_fuelid'];
            echo json_encode(['success' => false, 'message' => implode(' and ', $msgParts) . '.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── Auto-fill UserID and EmployeeID from session ──────────────────────
        $data['UserID'] = (int)$_SESSION['user_id'];
        if (empty($data['EmployeeID'])) {
            $empConn = getConnection();
            $empStmt = sqlsrv_query($empConn, "SELECT EmployeeID FROM [dbo].[ViewUserLogIn] WHERE id = ?", [$data['UserID']]);
            if ($empStmt && $empRow = sqlsrv_fetch_array($empStmt, SQLSRV_FETCH_ASSOC)) {
                $data['EmployeeID'] = $empRow['EmployeeID'] ?? null;
            }
            closeConnection($empConn);
        }
        // ─────────────────────────────────────────────────────────────────────
        $result = addFuelRecord($data);
        echo json_encode(['success' => $result, 'message' => $result ? 'Fuel record added.' : 'Failed to add record.'], JSON_UNESCAPED_UNICODE);
        break;

    case 'check_po_or':
        // Live check used while typing in the Add/Edit Fuel forms — lets the
        // user know immediately if a PO # or OR # is already in use under
        // the same supplier elsewhere.
        $po        = $_GET['po'] ?? '';
        $or        = $_GET['or'] ?? '';
        $supplier  = $_GET['supplier'] ?? '';
        $excludeId = !empty($_GET['exclude_fuel_id']) ? (int)$_GET['exclude_fuel_id'] : null;
        echo json_encode(checkFuelPoOrDuplicate($po, $or, $excludeId, $supplier), JSON_UNESCAPED_UNICODE);
        break;

    case 'vehicles':
        $dept = $_GET['department'] ?? '';
        echo json_encode(getVehicles($dept), JSON_UNESCAPED_UNICODE);
        break;

    case 'suppliers':
        echo json_encode(getSuppliers(), JSON_UNESCAPED_UNICODE);
        break;

    case 'tank_stats':
        $supplier = trim($_GET['supplier'] ?? 'TRADEWELL');
        echo json_encode(getTankStats($supplier), JSON_UNESCAPED_UNICODE);
        break;

    case 'tank_refills':
        $page     = max(1, (int)($_GET['page']     ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        $supplier = trim($_GET['supplier'] ?? 'TRADEWELL');
        echo json_encode(getTankRefills($page, $pageSize, $supplier), JSON_UNESCAPED_UNICODE);
        break;

    case 'add_tank_refill':
        $data   = $_POST;
        if (empty($data['RefillDate']) || empty($data['LitersAdded'])) {
            echo json_encode(['success' => false, 'message' => 'Date and Liters are required.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        // ── Receipt photo/PDF upload (optional) ──────────────────────────
        $receiptPath = saveReceiptUpload($_FILES['receipt'] ?? null);
        if ($receiptPath !== null) {
            $data['ReceiptPath'] = $receiptPath;
        } elseif (!empty($_FILES['receipt']['name'])) {
            // A file was selected but failed validation (size/type)
            echo json_encode(['success' => false, 'message' => 'Receipt file must be a JPG/PNG/WEBP/PDF under 10 MB.'], JSON_UNESCAPED_UNICODE);
            break;
        }
        $result = addTankRefill($data);
        echo json_encode(['success' => $result, 'message' => $result ? 'Tank refill recorded.' : 'Failed to save.'], JSON_UNESCAPED_UNICODE);
        break;

    case 'gas_card':
        $dept     = $_GET['department'] ?? '';
        $plate    = $_GET['plate']      ?? '';
        $page     = max(1, (int)($_GET['page']     ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 20)));
        echo json_encode(getGasCard($dept, $plate, $page, $pageSize), JSON_UNESCAPED_UNICODE);
        break;

    case 'plate_month_detail':
        $plate = $_GET['plate'] ?? '';
        $year  = $_GET['year']  ?? date('Y');
        $month = $_GET['month'] ?? date('n');
        echo json_encode(getPlateMonthDetail($plate, $year, $month), JSON_UNESCAPED_UNICODE);
        break;

    case 'plate_calendar':
        $plate = $_GET['plate'] ?? '';
        $year  = $_GET['year']  ?? date('Y');
        echo json_encode(getPlateCalendar($plate, $year), JSON_UNESCAPED_UNICODE);
        break;

    case 'employees':
        $search = $_GET['search'] ?? '';
        $dept   = $_GET['department'] ?? '';
        echo json_encode(getEmployees($search, $dept), JSON_UNESCAPED_UNICODE);
        break;

    case 'get_fuel_price':
        $supplier = trim($_GET['supplier'] ?? 'TRADEWELL');
        echo json_encode(getFuelPrice($supplier), JSON_UNESCAPED_UNICODE);
        break;

    case 'set_fuel_price':
        if (empty($_SESSION['is_superadmin']) && empty($_SESSION['perm_edit_fuel_price'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Permission denied.']);
            break;
        }
        $price    = floatval($_POST['price'] ?? 0);
        $note     = trim($_POST['note'] ?? '');
        $supplier = trim($_POST['supplier'] ?? 'TRADEWELL');
        if ($price <= 0) {
            echo json_encode(['success' => false, 'message' => 'Price must be greater than 0.']);
            break;
        }
        echo json_encode(setFuelPrice($price, $note, (int)$_SESSION['user_id'], $supplier), JSON_UNESCAPED_UNICODE);
        break;

    // ── Live-update snapshot ── returns lightweight fingerprints so the
    // client can detect changes without re-fetching full datasets.
    case 'poll_snapshot':
        $dept = trim($_GET['department'] ?? '');
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        echo json_encode(getLiveSnapshot($dept, $date), JSON_UNESCAPED_UNICODE);
        break;

    case 'fuel_price_history':
        $supplier = trim($_GET['supplier'] ?? 'TRADEWELL');
        echo json_encode(getFuelPriceHistory($supplier), JSON_UNESCAPED_UNICODE);
        break;

    case 'save_user_depts':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden.']);
            break;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        $depts  = json_decode($_POST['depts'] ?? '[]', true) ?: [];
        if (!$userId) { echo json_encode(['success' => false, 'message' => 'Invalid user.']); break; }
        echo json_encode(saveUserDepts($userId, $depts), JSON_UNESCAPED_UNICODE);
        break;

    case 'admin_users':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden — Superadmin access required.']);
            break;
        }
        echo json_encode(getAdminUsers(), JSON_UNESCAPED_UNICODE);
        break;

    case 'save_permission':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden — Superadmin access required.']);
            break;
        }        $userId  = (int)($_POST['user_id']  ?? 0);
        $permKey = $_POST['perm_key'] ?? '';
        $value   = (int)($_POST['value']    ?? 0);
        $allowed = ['perm_dashboard','perm_fuel_records','perm_driver_card',
                    'perm_fuel_tank','perm_add_fuel','perm_tank_fill','perm_edit_fuel_price',
                    'perm_edit_fuel','perm_delete_fuel'];
        if (!$userId || !in_array($permKey, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            break;
        }
        echo json_encode(saveUserPermission($userId, $permKey, $value), JSON_UNESCAPED_UNICODE);
        break;

    case 'admin_tank_access':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden — Superadmin access required.']);
            break;
        }
        echo json_encode(getAdminTankAccess(), JSON_UNESCAPED_UNICODE);
        break;

    case 'set_tank_access':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden — Superadmin access required.']);
            break;
        }
        $userId       = (int)($_POST['user_id']      ?? 0);
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $isActive     = (int)($_POST['is_active']    ?? 0);
        if (!$userId || !$supplierName) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            break;
        }
        echo json_encode(setUserTankAccess($userId, $supplierName, (bool)$isActive), JSON_UNESCAPED_UNICODE);
        break;

    case 'my_tank_suppliers':
        // Returns the restricted tank suppliers the current user can access.
        // Called on page load to filter the Add Fuel supplier dropdown.
        $isSuperAdmin = !empty($_SESSION['is_superadmin']);
        if ($isSuperAdmin) {
            // Superadmins always get all restricted suppliers
            echo json_encode(['suppliers' => ['TRADEWELL', 'TRADEWELL GUMACA']], JSON_UNESCAPED_UNICODE);
        } else {
            $list = getUserTankSuppliers((int)$_SESSION['user_id']);
            echo json_encode(['suppliers' => $list], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'get_system_access_users':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden — Superadmin access required.']);
            break;
        }
        echo json_encode(getSystemAccessUsers(), JSON_UNESCAPED_UNICODE);
        break;

    case 'set_system_access':
        if (empty($_SESSION['is_superadmin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden — Superadmin access required.']);
            break;
        }
        $userId     = (int)($_POST['user_id']     ?? 0);
        $isApproved = (int)($_POST['is_approved'] ?? 1);
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
            break;
        }
        echo json_encode(setUserSystemAccess($userId, (bool)$isApproved), JSON_UNESCAPED_UNICODE);
        break;

    // ── Departments ──────────────────────────────────────────────────────────
    case 'get_departments':
        $activeOnly = ($_GET['active_only'] ?? '1') === '1';
        echo json_encode(getDepartments($activeOnly), JSON_UNESCAPED_UNICODE);
        break;

    case 'add_department':
        if (empty($_SESSION['is_superadmin'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); break; }
        $name  = trim($_POST['name']  ?? '');
        $color = trim($_POST['color'] ?? '#cccccc');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Department name is required.']); break; }
        echo json_encode(addDepartment($name, $color), JSON_UNESCAPED_UNICODE);
        break;

    case 'update_department':
        if (empty($_SESSION['is_superadmin'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); break; }
        $id     = (int)($_POST['id']     ?? 0);
        $name   = trim($_POST['name']   ?? '');
        $color  = trim($_POST['color']  ?? '#cccccc');
        $status = (int)($_POST['status'] ?? 1);
        if (!$id || !$name) { echo json_encode(['success'=>false,'message'=>'ID and name are required.']); break; }
        echo json_encode(updateDepartment($id, $name, $color, $status), JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_department':
        if (empty($_SESSION['is_superadmin'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Forbidden']); break; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); break; }
        echo json_encode(deleteDepartment($id), JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['error' => 'Invalid action'], JSON_UNESCAPED_UNICODE);
}
?>