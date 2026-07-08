<?php
require_once 'db.php';

// ─────────────────────────────────────────────
// DEPARTMENTS — from Tbl_Department
// ─────────────────────────────────────────────
function getDepartments($activeOnly = true) {
    $conn  = getConnection();
    $where = $activeOnly ? 'WHERE Status = 1' : '';
    $sql   = "SELECT DepartmentID, DepartmentName, Color, Status, CreatedAt
              FROM [dbo].[Tbl_Department]
              $where
              ORDER BY DepartmentName";
    $stmt  = sqlsrv_query($conn, $sql);
    $rows  = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($r['CreatedAt'] instanceof DateTime) {
                $r['CreatedAt'] = $r['CreatedAt']->format('Y-m-d H:i:s');
            }
            $rows[] = $r;
        }
    }
    closeConnection($conn);
    return $rows;
}

function addDepartment($name, $color) {
    $conn  = getConnection();
    $name  = strtoupper(trim($name));
    // Check duplicate
    $check = sqlsrv_query($conn, "SELECT COUNT(*) AS cnt FROM [dbo].[Tbl_Department] WHERE DepartmentName = ?", [$name]);
    if ($check) {
        $row = sqlsrv_fetch_array($check, SQLSRV_FETCH_ASSOC);
        if ((int)$row['cnt'] > 0) {
            closeConnection($conn);
            return ['success' => false, 'message' => 'Department already exists.'];
        }
    }
    $sql  = "INSERT INTO [dbo].[Tbl_Department] (DepartmentName, Color, Status, CreatedAt) VALUES (?, ?, 1, GETDATE())";
    $stmt = sqlsrv_query($conn, $sql, [$name, $color]);
    closeConnection($conn);
    if ($stmt) return ['success' => true];
    return ['success' => false, 'message' => 'Failed to add department.'];
}

function updateDepartment($id, $name, $color, $status) {
    $conn  = getConnection();
    $name  = strtoupper(trim($name));
    $sql   = "UPDATE [dbo].[Tbl_Department] SET DepartmentName = ?, Color = ?, Status = ? WHERE DepartmentID = ?";
    $stmt  = sqlsrv_query($conn, $sql, [$name, $color, (int)$status, (int)$id]);
    closeConnection($conn);
    if ($stmt) return ['success' => true];
    return ['success' => false, 'message' => 'Failed to update department.'];
}

function deleteDepartment($id) {
    $conn = getConnection();
    // Soft delete — just set Status = 0
    $stmt = sqlsrv_query($conn, "UPDATE [dbo].[Tbl_Department] SET Status = 0 WHERE DepartmentID = ?", [(int)$id]);
    closeConnection($conn);
    if ($stmt) return ['success' => true];
    return ['success' => false, 'message' => 'Failed to deactivate department.'];
}

// ─────────────────────────────────────────────
// FUEL RECORDS — paginated + filtered
// ─────────────────────────────────────────────
function getFuelRecords($filters = [], $page = 1, $pageSize = 20, $extra = []) {
    $conn = getConnection();

    $where  = [];
    $params = [];

    if (!empty($filters['department'])) {
        $where[]  = "f.Department = ?";
        $params[] = $filters['department'];
    } elseif (!empty($filters['departments']) && is_array($filters['departments'])) {
        // Multi-department filter (used when user has assigned depts and selects "All")
        $placeholders = implode(',', array_fill(0, count($filters['departments']), '?'));
        $where[]  = "f.Department IN ($placeholders)";
        foreach ($filters['departments'] as $d) $params[] = $d;
    }
    if (!empty($filters['plate'])) {
        $where[]  = "f.PlateNumber LIKE ?";
        $params[] = '%' . $filters['plate'] . '%';
    }
    if (!empty($filters['supplier'])) {
        $where[]  = "f.Supplier = ?";   // exact match — prevents "Tradewell" matching "Tradewell Gumaca"
        $params[] = $filters['supplier'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = "CAST(f.Fueldate AS DATE) >= ?";
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = "CAST(f.Fueldate AS DATE) <= ?";
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['requested'])) {
        $where[]  = "f.Requested LIKE ?";
        $params[] = '%' . $filters['requested'] . '%';
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Count query ──────────────────────────
    $countSql  = "SELECT COUNT(*) AS total FROM [dbo].[Tbl_fuel] f $whereClause";
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    $total     = 0;
    if ($countStmt) {
        $row   = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
    }

    // ── Data query ───────────────────────────
    $offset  = ($page - 1) * $pageSize;
    // Allow callers to request ascending sort (e.g. for print)
    $sortAsc = !empty($extra['sort_asc']);
    $orderBy = $sortAsc ? 'f.Fueldate ASC, f.FuelID ASC' : 'f.FuelID DESC';

    $dataSql = "
        SELECT
            f.FuelID,
            f.DID,
            CAST(f.Fueldate AS DATE)          AS FuelDate,
            CONVERT(varchar(8), f.FuelTime, 108) AS FuelTime,
            f.Department,
            f.PlateNumber,
            f.Area,
            f.Liters,
            f.Price,
            f.Amount,
            f.Supplier,
            f.POnum,
            f.ORnumber,
            f.Requested,
            CONVERT(varchar(19), f.Inputdate, 120) AS InputDate
        FROM [dbo].[Tbl_fuel] f
        $whereClause
        ORDER BY $orderBy
        OFFSET $offset ROWS FETCH NEXT $pageSize ROWS ONLY";

    $dataStmt = sqlsrv_query($conn, $dataSql, $params);
    $records  = [];

    if ($dataStmt) {
        while ($r = sqlsrv_fetch_array($dataStmt, SQLSRV_FETCH_ASSOC)) {
            // Normalise FuelDate DateTime object
            if ($r['FuelDate'] instanceof DateTime) {
                $r['FuelDate'] = $r['FuelDate']->format('Y-m-d');
            }
            // InputDate already returned as varchar string via CONVERT
            $records[] = $r;
        }
    }

    // ── Totals ───────────────────────────────
    $totSql  = "
        SELECT
            SUM(f.Liters) AS TotalLiters,
            SUM(f.Amount) AS TotalAmount,
            COUNT(*)      AS TotalRecords
        FROM [dbo].[Tbl_fuel] f
        $whereClause";
    $totStmt  = sqlsrv_query($conn, $totSql, $params);
    $totals   = ['TotalLiters' => 0, 'TotalAmount' => 0, 'TotalRecords' => 0];
    if ($totStmt) {
        $t = sqlsrv_fetch_array($totStmt, SQLSRV_FETCH_ASSOC);
        if ($t) $totals = $t;
    }

    closeConnection($conn);

    return [
        'records'    => $records,
        'total'      => $total,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'totalPages' => $pageSize ? (int)ceil($total / $pageSize) : 1,
        'totals'     => $totals,
    ];
}

// ─────────────────────────────────────────────
// Keep all existing functions below
// ─────────────────────────────────────────────

function getDashboardStats($date = null, $dept = '') {
    $conn = getConnection();
    if (!$date) $date = date('Y-m-d');

    $deptWhere  = $dept ? "AND Department = ?" : "";
    $deptParams = $dept ? [$date, $dept] : [$date];

    $sql = "SELECT Department,COUNT(*) as TotalRefills,SUM(Liters) as TotalLiters,SUM(Amount) as TotalAmount,AVG(Price) as AvgPrice,COUNT(DISTINCT PlateNumber) as TrucksRefueled FROM [dbo].[Tbl_fuel] WHERE CAST(Fueldate AS DATE)=? $deptWhere GROUP BY Department ORDER BY Department";
    $stmt = sqlsrv_query($conn, $sql, $deptParams);
    $deptStats = [];
    if ($stmt) { while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $deptStats[] = $row; }

    $deptWhere2  = $dept ? "AND f.Department = ?" : "";
    $deptParams2 = $dept ? [$date, $dept] : [$date];

    $sql2 = "SELECT f.FuelID,f.PlateNumber,f.Department,f.Area,f.Liters,f.Price,f.Amount,f.Requested,f.Supplier,f.POnum,f.ORnumber,f.Fueldate,v.Brand,v.Model FROM [dbo].[Tbl_fuel] f LEFT JOIN [dbo].[Vehicle] v ON f.PlateNumber=v.PlateNumber WHERE CAST(f.Fueldate AS DATE)=? $deptWhere2 ORDER BY f.FuelID DESC";
    $stmt2 = sqlsrv_query($conn, $sql2, $deptParams2);
    $todayRefills = [];
    if ($stmt2) { while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) { if ($row['Fueldate'] instanceof DateTime) $row['Fueldate'] = $row['Fueldate']->format('Y-m-d'); $todayRefills[] = $row; } }

    $deptWhere3  = $dept ? "AND Department = ?" : "";
    $deptParams3 = $dept ? [$date, $dept] : [$date];

    $sql3 = "SELECT TOP 10 PlateNumber,Department,Area,SUM(Liters) as TotalLiters,SUM(Amount) as TotalCost,COUNT(*) as Refills FROM [dbo].[Tbl_fuel] WHERE CAST(Fueldate AS DATE)=? $deptWhere3 GROUP BY PlateNumber,Department,Area ORDER BY TotalLiters DESC";
    $stmt3 = sqlsrv_query($conn, $sql3, $deptParams3);
    $topConsumers = [];
    if ($stmt3) { while ($row = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC)) $topConsumers[] = $row; }

    $deptWhere4  = $dept ? "AND ts.Department = ?" : "";
    $deptParams4 = $dept ? [$date, $dept] : [$date];

    $sql4 = "SELECT ts.TruckScheduleID,ts.Department,ts.ScheduleDate,ts.PlateNumber,ts.Area,ts.Remarks FROM [dbo].[TruckSchedule] ts WHERE CAST(ts.ScheduleDate AS DATE)=? $deptWhere4 AND NOT EXISTS (SELECT 1 FROM [dbo].[Tbl_fuel] f WHERE f.DID=ts.TruckScheduleID OR ((f.DID IS NULL OR f.DID=0) AND f.PlateNumber=ts.PlateNumber AND CAST(f.Fueldate AS DATE)=CAST(ts.ScheduleDate AS DATE))) ORDER BY ts.TruckScheduleID DESC";
    $stmt4 = sqlsrv_query($conn, $sql4, $deptParams4);
    $schedules = [];
    if ($stmt4) { while ($row = sqlsrv_fetch_array($stmt4, SQLSRV_FETCH_ASSOC)) { if ($row['ScheduleDate'] instanceof DateTime) $row['ScheduleDate'] = $row['ScheduleDate']->format('Y-m-d'); $schedules[] = $row; } }

    closeConnection($conn);
    return compact('deptStats','todayRefills','topConsumers','schedules');
}

/**
 * Checks whether a given PO # and/or OR # already exists on ANY OTHER
 * fuel record (different FuelID) FOR THE SAME SUPPLIER. PO/OR numbers
 * only need to be unique within a supplier — the same number can be
 * reused across different suppliers (e.g. PETRON PO "123" and SHELL PO
 * "123" can coexist), but not duplicated twice under the same supplier,
 * regardless of department.
 *
 * Pass $excludeFuelId when editing, so the record being edited is not
 * compared against itself (keeping its own PO/OR unchanged is allowed).
 *
 * Returns: [
 *   'po_duplicate' => bool, 'po_fuelid' => int|null,
 *   'or_duplicate' => bool, 'or_fuelid' => int|null,
 * ]
 */
function checkFuelPoOrDuplicate($po, $or, $excludeFuelId = null, $supplier = null) {
    $conn   = getConnection();
    $result = [
        'po_duplicate' => false, 'po_fuelid' => null,
        'or_duplicate' => false, 'or_fuelid' => null,
    ];

    $po       = trim((string)$po);
    $or       = trim((string)$or);
    $supplier = trim((string)$supplier);

    // Without a supplier we can't scope the check meaningfully — skip it
    // rather than falsely flag/allow across suppliers.
    if ($supplier === '') {
        closeConnection($conn);
        return $result;
    }

    if ($po !== '') {
        $sql    = "SELECT TOP 1 FuelID FROM [dbo].[Tbl_fuel] WHERE POnum = ? AND Supplier = ?";
        $params = [$po, $supplier];
        if (!empty($excludeFuelId)) {
            $sql      .= " AND FuelID != ?";
            $params[]  = (int)$excludeFuelId;
        }
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
            $result['po_duplicate'] = true;
            $result['po_fuelid']    = (int)$row['FuelID'];
        }
    }

    if ($or !== '') {
        $sql    = "SELECT TOP 1 FuelID FROM [dbo].[Tbl_fuel] WHERE ORnumber = ? AND Supplier = ?";
        $params = [$or, $supplier];
        if (!empty($excludeFuelId)) {
            $sql      .= " AND FuelID != ?";
            $params[]  = (int)$excludeFuelId;
        }
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
            $result['or_duplicate'] = true;
            $result['or_fuelid']    = (int)$row['FuelID'];
        }
    }

    closeConnection($conn);
    return $result;
}

function addFuelRecord($data) {
    $conn = getConnection();

    // Auto-generate DID: get the last DID for this department and add 1
    $dept    = $data['Department'];
    $didSql  = "SELECT MAX(DID) AS LastDID FROM [dbo].[Tbl_fuel] WHERE Department = ?";
    $didStmt = sqlsrv_query($conn, $didSql, [$dept]);
    $lastDID = 0;
    if ($didStmt) {
        $row     = sqlsrv_fetch_array($didStmt, SQLSRV_FETCH_ASSOC);
        $lastDID = is_null($row['LastDID']) ? 0 : (int)$row['LastDID'];
    }
    $newDID = $lastDID + 1;

    $sql    = "INSERT INTO [dbo].[Tbl_fuel] (DID,PlateNumber,Department,Fueldate,FuelTime,Liters,Price,Amount,Area,Requested,POnum,ORnumber,Inputdate,UserID,EmployeeID,Supplier,DocNo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,GETDATE(),?,?,?,?)";
    $amount = floatval($data['Liters']) * floatval($data['Price']);
    $params = [$newDID,$data['PlateNumber'],$data['Department'],$data['Fueldate'],!empty($data['FuelTime'])?$data['FuelTime']:null,$data['Liters'],$data['Price'],$amount,$data['Area']??null,$data['Requested']??null,$data['POnum']??null,$data['ORnumber']??null,$data['UserID']??null,$data['EmployeeID']??null,$data['Supplier']??null,$data['DocNo']??null];
    $stmt   = sqlsrv_query($conn, $sql, $params);
    $ok     = ($stmt !== false);
    closeConnection($conn);
    return $ok;
}

function updateFuelRecord($fuelId, $data) {
    $conn   = getConnection();
    $amount = floatval($data['Liters']) * floatval($data['Price']);
    $sql    = "UPDATE [dbo].[Tbl_fuel] SET
                PlateNumber = ?, Department = ?, Fueldate = ?, FuelTime = ?,
                Liters = ?, Price = ?, Amount = ?, Area = ?, Requested = ?,
                POnum = ?, ORnumber = ?, Supplier = ?, DocNo = ?
               WHERE FuelID = ?";
    $params = [
        $data['PlateNumber'], $data['Department'], $data['Fueldate'],
        !empty($data['FuelTime']) ? $data['FuelTime'] : null,
        $data['Liters'], $data['Price'], $amount,
        $data['Area'] ?? null, $data['Requested'] ?? null,
        $data['POnum'] ?? null, $data['ORnumber'] ?? null,
        $data['Supplier'] ?? null, $data['DocNo'] ?? null,
        (int)$fuelId
    ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    $ok   = ($stmt !== false);
    closeConnection($conn);
    return $ok;
}

function deleteFuelRecord($fuelId) {
    $conn  = getConnection();
    $sql   = "DELETE FROM [dbo].[Tbl_fuel] WHERE FuelID = ?";
    $stmt  = sqlsrv_query($conn, $sql, [(int)$fuelId]);
    $ok    = ($stmt !== false);
    closeConnection($conn);
    return $ok;
}

function getVehicles($department = '') {
    $conn  = getConnection();
    $where = $department ? "WHERE Department='$department' AND Active=1" : "WHERE Active=1";
    $sql   = "SELECT VehicleID,PlateNumber,Department,Vehicletype,Brand,Model,Description FROM [dbo].[Vehicle] $where ORDER BY PlateNumber";
    $stmt  = sqlsrv_query($conn, $sql);
    $out   = [];
    if ($stmt) { while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $out[] = $row; }
    closeConnection($conn);
    return $out;
}

function getSuppliers() {
    $conn = getConnection();
    $sql  = "SELECT ID,AccountName,SupplierCode,ContactNo FROM [dbo].[TBL_Item_Supplier] WHERE Category='Fuel' ORDER BY AccountName";
    $stmt = sqlsrv_query($conn, $sql);
    $out  = [];
    if ($stmt) { while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $out[] = $row; }
    closeConnection($conn);
    return $out;
}

// ══════════════════════════════════════════════════════════
// FUEL TANK SUPPLIER ACCESS
// Restricted suppliers: TRADEWELL, TRADEWELL GUMACA
// All other suppliers remain visible to everyone.
// ══════════════════════════════════════════════════════════

/** Auto-create the Tbl_UserTankAccess table if it doesn't exist yet. */
function _ensureTankAccessTable($conn) {
    sqlsrv_query($conn, "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_UserTankAccess'
        )
        BEGIN
            CREATE TABLE [dbo].[Tbl_UserTankAccess] (
                ID            INT IDENTITY(1,1) PRIMARY KEY,
                UserID        INT           NOT NULL,
                SupplierName  NVARCHAR(100) NOT NULL,
                IsActive      BIT           NOT NULL DEFAULT 1,
                CONSTRAINT UQ_UserTankAccess UNIQUE (UserID, SupplierName)
            )
        END");
}

/**
 * Return the list of RESTRICTED tank suppliers this user has access to.
 * Restricted suppliers are only: TRADEWELL and TRADEWELL GUMACA.
 */
function getUserTankSuppliers($userId) {
    $conn = getConnection();
    _ensureTankAccessTable($conn);

    $stmt = sqlsrv_query($conn,
        "SELECT SupplierName FROM [dbo].[Tbl_UserTankAccess]
         WHERE UserID = ? AND IsActive = 1
         ORDER BY SupplierName",
        [$userId]);

    $list = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $list[] = $row['SupplierName'];
        }
    }
    closeConnection($conn);
    return $list;
}

/**
 * Return all users with their current tank-supplier assignments.
 * Used by the Administration panel.
 */
function getAdminTankAccess() {
    $conn = getConnection();
    _ensureTankAccessTable($conn);

    // Fetch all active users
    $stmt = sqlsrv_query($conn,
        "SELECT u.id, u.username, u.DisplayName, u.user_type
         FROM [dbo].[ViewUserLogIn] u
         WHERE u.Active = 1
         ORDER BY u.user_type DESC, u.DisplayName");

    $users = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $uid = (int)$row['id'];
            // Fetch assigned tank suppliers for this user
            $dStmt = sqlsrv_query($conn,
                "SELECT SupplierName FROM [dbo].[Tbl_UserTankAccess]
                 WHERE UserID = ? AND IsActive = 1 ORDER BY SupplierName",
                [$uid]);
            $row['tank_suppliers'] = [];
            if ($dStmt) {
                while ($dr = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) {
                    $row['tank_suppliers'][] = $dr['SupplierName'];
                }
            }
            $users[] = $row;
        }
    }
    closeConnection($conn);
    return ['users' => $users];
}

/**
 * Toggle a single tank-supplier access for a user.
 * $active = true  → grant access (upsert IsActive=1)
 * $active = false → revoke access (set IsActive=0)
 */
function setUserTankAccess($userId, $supplierName, $active) {
    // Only the two restricted suppliers are managed here.
    $allowed = ['TRADEWELL', 'TRADEWELL GUMACA'];
    if (!in_array($supplierName, $allowed)) {
        return ['success' => false, 'message' => 'Invalid supplier.'];
    }

    $conn = getConnection();
    _ensureTankAccessTable($conn);

    $bit = $active ? 1 : 0;
    $sql = "
        MERGE [dbo].[Tbl_UserTankAccess] AS target
        USING (SELECT ? AS UserID, ? AS SupplierName) AS src
            ON target.UserID = src.UserID AND target.SupplierName = src.SupplierName
        WHEN MATCHED THEN
            UPDATE SET IsActive = ?
        WHEN NOT MATCHED THEN
            INSERT (UserID, SupplierName, IsActive) VALUES (?, ?, ?);";

    $stmt = sqlsrv_query($conn, $sql, [$userId, $supplierName, $bit, $userId, $supplierName, $bit]);
    $ok   = ($stmt !== false);
    closeConnection($conn);
    return ['success' => $ok, 'message' => $ok ? 'Tank access updated.' : 'Failed to update.'];
}

/**
 * Check whether a user is allowed to use a given supplier when saving a fuel record.
 * TRADEWELL and TRADEWELL GUMACA are restricted; all others pass through.
 * Superadmins always pass.
 */
function userCanUseSupplier($userId, $supplierName, $isSuperAdmin = false) {
    if ($isSuperAdmin) return true;

    $restricted = ['TRADEWELL', 'TRADEWELL GUMACA'];
    $key        = strtoupper(trim($supplierName));
    if (!in_array($key, $restricted)) return true;   // non-restricted → always allowed

    $conn = getConnection();
    _ensureTankAccessTable($conn);

    $stmt = sqlsrv_query($conn,
        "SELECT COUNT(*) AS cnt FROM [dbo].[Tbl_UserTankAccess]
         WHERE UserID = ? AND SupplierName = ? AND IsActive = 1",
        [$userId, $key]);

    $ok = false;
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $ok  = ($row && (int)$row['cnt'] > 0);
    }
    closeConnection($conn);
    return $ok;
}



// ══════════════════════════════════════════════════════════
// LIVE UPDATE SNAPSHOT
// ══════════════════════════════════════════════════════════

/**
 * Returns lightweight fingerprints for every live-watched section.
 * The client compares these values against what it last saw — if anything
 * changed, it re-fetches only the affected section.
 *
 * Fingerprints are either MAX(InputDate) timestamps or row counts;
 * they are cheap queries that add minimal DB load.
 */
function getLiveSnapshot($dept = '', $date = '') {
    $conn = getConnection();
    if (!$date) $date = date('Y-m-d');

    $snap = [];

    // ── Dashboard: latest fuel record timestamp for today (optional dept filter) ──
    $deptClause = $dept ? "AND Department = ?" : "";
    $deptParam  = $dept ? [$date, $dept] : [$date];
    $sql = "SELECT
                COUNT(*)                                                AS cnt,
                ISNULL(CONVERT(varchar(19), MAX(InputDate), 120), '')   AS latest
            FROM [dbo].[Tbl_fuel]
            WHERE CAST(FuelDate AS DATE) = ? $deptClause";
    $stmt = sqlsrv_query($conn, $sql, $deptParam);
    if ($stmt) {
        $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $snap['dashboard'] = ($r['cnt'] ?? 0) . '|' . ($r['latest'] ?? '');
    }

    // ── Fuel Records: count + latest InputDate + sum of amounts (detects edits & deletes) ──
    $sql2 = $dept
        ? "SELECT COUNT(*) AS cnt, ISNULL(CONVERT(varchar(19), MAX(InputDate), 120), '') AS latest, ISNULL(SUM(Amount), 0) AS total_amt FROM [dbo].[Tbl_fuel] WHERE Department = ?"
        : "SELECT COUNT(*) AS cnt, ISNULL(CONVERT(varchar(19), MAX(InputDate), 120), '') AS latest, ISNULL(SUM(Amount), 0) AS total_amt FROM [dbo].[Tbl_fuel]";
    $stmt2 = $dept ? sqlsrv_query($conn, $sql2, [$dept]) : sqlsrv_query($conn, $sql2);
    if ($stmt2) {
        $r2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
        $snap['fuel_records'] = ($r2['cnt'] ?? 0) . '|' . ($r2['latest'] ?? '') . '|' . round(($r2['total_amt'] ?? 0), 2);
    }

    // ── Tank TRADEWELL: latest refill timestamp + row count ──
    $stmt3 = sqlsrv_query($conn,
        "SELECT COUNT(*) AS cnt, ISNULL(CONVERT(varchar(19), MAX(InputDate), 120), '') AS latest
         FROM [dbo].[Tbl_FuelTank] WHERE Supplier = 'TRADEWELL'");
    if ($stmt3) {
        $r3 = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC);
        $snap['tank_tradewell'] = ($r3['cnt'] ?? 0) . '|' . ($r3['latest'] ?? '');
    }

    // ── Tank TRADEWELL GUMACA ──
    $stmt4 = sqlsrv_query($conn,
        "SELECT COUNT(*) AS cnt, ISNULL(CONVERT(varchar(19), MAX(InputDate), 120), '') AS latest
         FROM [dbo].[Tbl_FuelTank] WHERE Supplier = 'TRADEWELL GUMACA'");
    if ($stmt4) {
        $r4 = sqlsrv_fetch_array($stmt4, SQLSRV_FETCH_ASSOC);
        $snap['tank_gumaca'] = ($r4['cnt'] ?? 0) . '|' . ($r4['latest'] ?? '');
    }

    // ── Fuel Price: latest price-change timestamp (both suppliers) ──
    $stmtP = sqlsrv_query($conn,
        "SELECT ISNULL(CONVERT(varchar(19), MAX(SetAt), 120), '') AS latest FROM [dbo].[Tbl_FuelPrice]");
    if ($stmtP) {
        $rP = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);
        $snap['fuel_price'] = $rP['latest'] ?? '';
    }

    closeConnection($conn);

    $snap['server_time'] = date('Y-m-d H:i:s');
    return $snap;
}


// ══════════════════════════════════════════════════════════
// FUEL TANK DASHBOARD
// ══════════════════════════════════════════════════════════

// ── Tank stats: fuel added, fuel used (by supplier), current level ──
function getTankStats($supplier = 'TRADEWELL') {
    $conn = getConnection();
    $TANK_MAX = (strtoupper(trim($supplier)) === 'TRADEWELL GUMACA') ? 12000 : 16000;

    // Total fuel added into tank for this supplier (from Tbl_FuelTank)
    $sql1  = "SELECT ISNULL(SUM(LitersAdded), 0) AS TotalAdded FROM [dbo].[Tbl_FuelTank] WHERE Supplier = ?";
    $stmt1 = sqlsrv_query($conn, $sql1, [$supplier]);
    $totalAdded = 0;
    if ($stmt1) {
        $r = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC);
        $totalAdded = floatval($r['TotalAdded'] ?? 0);
    }

    // Total fuel used (dispensed to trucks) — match this supplier
    $sql2  = "SELECT ISNULL(SUM(Liters), 0) AS TotalUsed FROM [dbo].[Tbl_fuel] WHERE UPPER(Supplier) = UPPER(?)";
    $stmt2 = sqlsrv_query($conn, $sql2, [$supplier]);
    $totalUsed = 0;
    if ($stmt2) {
        $r = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC);
        $totalUsed = floatval($r['TotalUsed'] ?? 0);
    }

    closeConnection($conn);

    $currentFuel = max(0, $totalAdded - $totalUsed);

    return [
        'totalAdded'         => $totalAdded,
        'totalUsed'          => $totalUsed,
        'currentFuel'        => $currentFuel,
        'tankMax'            => $TANK_MAX,
        'percentage'         => $TANK_MAX > 0 ? round(($currentFuel / $TANK_MAX) * 100, 1) : 0,
        'availableCapacity'  => max(0, $TANK_MAX - $currentFuel),
    ];
}

// ── Tank refill log (paginated, filtered by supplier) ──
function getTankRefills($page = 1, $pageSize = 20, $supplier = 'TRADEWELL') {
    $conn = getConnection();

    $countSql  = "SELECT COUNT(*) AS total FROM [dbo].[Tbl_FuelTank] WHERE Supplier = ?";
    $countStmt = sqlsrv_query($conn, $countSql, [$supplier]);
    $total     = 0;
    if ($countStmt) {
        $r     = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($r['total'] ?? 0);
    }

    $offset  = ($page - 1) * $pageSize;
    $sql     = "
        SELECT TankID,
               CAST(RefillDate AS DATE)                  AS RefillDate,
               CONVERT(varchar(5), ArrivalTime, 108)     AS ArrivalTime,
               LitersAdded, Attendant, Supplier, Notes, ReceiptPath,
               CONVERT(varchar(19), InputDate, 120)       AS InputDate
        FROM [dbo].[Tbl_FuelTank]
        WHERE Supplier = ?
        ORDER BY RefillDate DESC, TankID DESC
        OFFSET $offset ROWS FETCH NEXT $pageSize ROWS ONLY";

    $stmt    = sqlsrv_query($conn, $sql, [$supplier]);
    $records = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['RefillDate'] instanceof DateTime) {
                $row['RefillDate'] = $row['RefillDate']->format('Y-m-d');
            }
            $records[] = $row;
        }
    }

    closeConnection($conn);
    return [
        'records'    => $records,
        'total'      => $total,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'totalPages' => $pageSize ? (int)ceil($total / $pageSize) : 1,
    ];
}

// ── Save an uploaded receipt photo/PDF for a tank refill ──
// Returns the relative path to store in the DB, or null if no valid file
// was uploaded. Caller is responsible for validating $_FILES beforehand
// is not required — this does its own checks.
function saveReceiptUpload($file) {
    if (empty($file) || !is_array($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxBytes = 10 * 1024 * 1024; // 10 MB
    if ($file['size'] > $maxBytes) {
        return null;
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads/receipts/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $filename = 'receipt_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    // Path stored relative to the project root, used directly as an href
    return 'uploads/receipts/' . $filename;
}

// ── Add a tank refill record ──
function addTankRefill($data) {
    $conn   = getConnection();
    $sql    = "INSERT INTO [dbo].[Tbl_FuelTank]
                   (RefillDate, ArrivalTime, LitersAdded, Attendant, Supplier, Notes, ReceiptPath, UserID)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $params = [
        $data['RefillDate']  ?? null,
        !empty($data['ArrivalTime']) ? $data['ArrivalTime'] : null,
        floatval($data['LitersAdded'] ?? 0),
        $data['Attendant']   ?? null,
        $data['Supplier']    ?? null,
        $data['Notes']       ?? null,
        $data['ReceiptPath'] ?? null,
        $data['UserID']      ?? null,
    ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    $ok   = ($stmt !== false);
    closeConnection($conn);
    return $ok;
}

// ─────────────────────────────────────────────
// DRIVER GAS CARD — active vehicles list
// ─────────────────────────────────────────────
function getGasCard($department = '', $plate = '', $page = 1, $pageSize = 20) {
    $conn   = getConnection();
    $where  = ["Active = 1", "(Description IS NULL OR Description NOT LIKE '%house%')"];
    $params = [];

    if (!empty($department)) {
        $where[]  = "Department = ?";
        $params[] = $department;
    }
    if (!empty($plate)) {
        $where[]  = "PlateNumber LIKE ?";
        $params[] = '%' . $plate . '%';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // Count
    $countSql  = "SELECT COUNT(*) AS total FROM [TradewellDatabase].[dbo].[Vehicle] $whereClause";
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    $total     = 0;
    if ($countStmt) {
        $row   = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
    }

    // Data with pagination
    $offset = ($page - 1) * $pageSize;
    $sql    = "SELECT VehicleID, PlateNumber, Department, Vehicletype,
                      Brand, Model, FuelType, Year
               FROM [TradewellDatabase].[dbo].[Vehicle]
               $whereClause
               ORDER BY Department, PlateNumber
               OFFSET $offset ROWS FETCH NEXT $pageSize ROWS ONLY";

    $stmt = sqlsrv_query($conn, $sql, $params);
    $records = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $records[] = $row;
        }
    }

    closeConnection($conn);
    return [
        'records'    => $records,
        'total'      => $total,
        'page'       => $page,
        'pageSize'   => $pageSize,
        'totalPages' => $pageSize ? (int)ceil($total / $pageSize) : 1,
    ];
}


// ─────────────────────────────────────────────
// PLATE CALENDAR — monthly fuel stats for one plate
// ─────────────────────────────────────────────
function getPlateCalendar($plate, $year) {
    $conn = getConnection();

    $sql = "
        SELECT
            MONTH(Fueldate)      AS MonthNum,
            SUM(Liters)          AS TotalLiters,
            SUM(Amount)          AS TotalAmount,
            COUNT(*)             AS TotalRefills
        FROM [dbo].[Tbl_fuel]
        WHERE PlateNumber = ?
          AND YEAR(Fueldate)  = ?
        GROUP BY MONTH(Fueldate)
        ORDER BY MONTH(Fueldate)";

    $stmt = sqlsrv_query($conn, $sql, [$plate, (int)$year]);
    $byMonth = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $byMonth[(int)$row['MonthNum']] = $row;
        }
    }
    closeConnection($conn);

    // Always return all 12 months, zeroed if no data
    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $out[] = [
            'Month'        => $m,
            'TotalLiters'  => isset($byMonth[$m]) ? floatval($byMonth[$m]['TotalLiters'])  : 0,
            'TotalAmount'  => isset($byMonth[$m]) ? floatval($byMonth[$m]['TotalAmount'])  : 0,
            'TotalRefills' => isset($byMonth[$m]) ? intval($byMonth[$m]['TotalRefills'])   : 0,
        ];
    }
    return $out;
}


// ─────────────────────────────────────────────
// PLATE MONTH DETAIL — every day of the month,
// refill data where it exists, null where it doesn't
// ─────────────────────────────────────────────
function getPlateMonthDetail($plate, $year, $month) {
    $conn = getConnection();

    // All refills for this plate in this month
    $sql = "
        SELECT
            CAST(Fueldate AS DATE)               AS FuelDate,
            CONVERT(varchar(8), FuelTime, 108)   AS FuelTime,
            Area,
            Liters,
            Price,
            Amount,
            Supplier,
            Requested
        FROM [dbo].[Tbl_fuel]
        WHERE PlateNumber = ?
          AND YEAR(Fueldate)  = ?
          AND MONTH(Fueldate) = ?
        ORDER BY Fueldate ASC";

    $stmt = sqlsrv_query($conn, $sql, [$plate, (int)$year, (int)$month]);
    $byDay = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['FuelDate'] instanceof DateTime) {
                $row['FuelDate'] = $row['FuelDate']->format('Y-m-d');
            }
            $day = (int)date('j', strtotime($row['FuelDate']));
            $byDay[$day] = $row;
        }
    }
    closeConnection($conn);

    // Build one entry per calendar day of the month
    $daysInMonth = (int)date('t', mktime(0,0,0,(int)$month,1,(int)$year));
    $days        = [];
    $refillCount = 0;

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if (isset($byDay[$d])) {
            $days[] = $byDay[$d];
            $refillCount++;
        } else {
            // No refill — return the date so the row can still show the day
            $days[] = [
                'FuelDate'  => $dateStr,
                'FuelTime'  => null,
                'Area'      => null,
                'Liters'    => null,
                'Price'     => null,
                'Amount'    => null,
                'Supplier'  => null,
                'Requested' => null,
            ];
        }
    }

    return [
        'days'        => $days,
        'refillCount' => $refillCount,
        'totalDays'   => $daysInMonth,
    ];
}

// ─────────────────────────────────────────────
// EMPLOYEES — searchable by name, optional dept filter
// ─────────────────────────────────────────────
function getEmployees($search = '', $department = '') {
    $conn   = getConnection();
    $where  = ["Active = 1"];
    $params = [];

    if (!empty($department)) {
        $where[]  = "Department = ?";
        $params[] = $department;
    }

    if (!empty($search)) {
        // COLLATE Latin1_General_CI_AI makes LIKE accent-insensitive so Peña matches "pena" or "peña"
        $where[]  = "(FirstName COLLATE Latin1_General_CI_AI LIKE ? OR LastName COLLATE Latin1_General_CI_AI LIKE ? OR CONCAT(FirstName, ' ', LastName) COLLATE Latin1_General_CI_AI LIKE ? OR CONCAT(LastName, ', ', FirstName) COLLATE Latin1_General_CI_AI LIKE ?)";
        $s        = '%' . $search . '%';
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
        $params[] = $s;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $sql  = "SELECT TOP 20 EmployeeID, FirstName, LastName, Department, Position_held
             FROM [TradewellDatabase].[dbo].[TBL_HREmployeeList]
             $whereClause
             ORDER BY LastName, FirstName";

    $stmt = sqlsrv_query($conn, $sql, $params);
    $out  = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['FullName'] = trim($row['LastName'] . ', ' . $row['FirstName']);
            $out[] = $row;
        }
    }
    closeConnection($conn);
    return $out;
}

// ─────────────────────────────────────────────
// Department access: get/save allowed depts per user
// ─────────────────────────────────────────────

function ensureUserDeptsTable($conn) {
    sqlsrv_query($conn, "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='Tbl_UserDepartments'
        )
        BEGIN
            CREATE TABLE [dbo].[Tbl_UserDepartments] (
                UserID     INT          NOT NULL,
                Department NVARCHAR(50) NOT NULL,
                PRIMARY KEY (UserID, Department)
            )
        END");
}

/**
 * Returns array of department names this user is allowed to see.
 * Empty array means no restriction has been set yet (will show all by default).
 */
function getUserAllowedDepts($userId) {
    $conn = getConnection();
    ensureUserDeptsTable($conn);
    $stmt = sqlsrv_query($conn, "SELECT Department FROM [dbo].[Tbl_UserDepartments] WHERE UserID = ? ORDER BY Department", [$userId]);
    $depts = [];
    if ($stmt) { while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $depts[] = $row['Department']; }
    closeConnection($conn);
    return $depts;
}

/**
 * Replace all department assignments for a user with the given list.
 */
function saveUserDepts($userId, array $depts) {
    $conn = getConnection();
    ensureUserDeptsTable($conn);
    // Delete existing
    sqlsrv_query($conn, "DELETE FROM [dbo].[Tbl_UserDepartments] WHERE UserID = ?", [$userId]);
    // Insert new
    // Pull valid department names from DB instead of hardcoded list
    $validDepts = [];
    $deptStmt   = sqlsrv_query($conn, "SELECT DepartmentName FROM [dbo].[Tbl_Department] WHERE Status = 1");
    if ($deptStmt) {
        while ($r = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC)) {
            $validDepts[] = $r['DepartmentName'];
        }
    }
    foreach ($depts as $d) {
        if (in_array($d, $validDepts)) {
            sqlsrv_query($conn, "INSERT INTO [dbo].[Tbl_UserDepartments] (UserID, Department) VALUES (?, ?)", [$userId, $d]);
        }
    }
    closeConnection($conn);
    return ['success' => true];
}

// ─────────────────────────────────────────────
// Get a single user's permissions (used on page load)
// ─────────────────────────────────────────────
function getUserPermissions($userId) {
    $conn = getConnection();
    $sql  = "
        SELECT
            ISNULL(p.perm_dashboard,   1) AS perm_dashboard,
            ISNULL(p.perm_fuel_records,1) AS perm_fuel_records,
            ISNULL(p.perm_driver_card, 1) AS perm_driver_card,
            ISNULL(p.perm_fuel_tank,   1) AS perm_fuel_tank,
            ISNULL(p.perm_add_fuel,    1) AS perm_add_fuel,
            ISNULL(p.perm_tank_fill,   1) AS perm_tank_fill,
            ISNULL(p.perm_edit_fuel_price, 1) AS perm_edit_fuel_price,
            ISNULL(p.perm_edit_fuel,   0) AS perm_edit_fuel,
            ISNULL(p.perm_delete_fuel, 0) AS perm_delete_fuel,
            ISNULL(p.perm_admin,       0) AS perm_admin
        FROM [dbo].[ViewUserLogIn] u
        LEFT JOIN [dbo].[Tbl_UserPermissions] p ON p.UserID = u.id
        WHERE u.id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$userId]);
    $perms = [
        'perm_dashboard'       => 1,
        'perm_fuel_records'    => 1,
        'perm_driver_card'     => 1,
        'perm_fuel_tank'       => 1,
        'perm_add_fuel'        => 1,
        'perm_tank_fill'       => 1,
        'perm_edit_fuel_price' => 1,
        'perm_edit_fuel'       => 0,
        'perm_delete_fuel'     => 0,
        'perm_admin'           => 0,
    ];
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        foreach ($perms as $k => $_) {
            $perms[$k] = (int)$row[$k];
        }
    }
    closeConnection($conn);
    return $perms;
}

// ─────────────────────────────────────────────
// ADMINISTRATION — user permission matrix
// ─────────────────────────────────────────────

/**
 * Get all active users with their module permissions.
 * Permissions are stored in [dbo].[Tbl_UserPermissions].
 * If the table doesn't exist yet, it is created automatically.
 */
function getAdminUsers() {
    $conn = getConnection();

    // Auto-create permissions table if absent
    $createSql = "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_UserPermissions'
        )
        BEGIN
            CREATE TABLE [dbo].[Tbl_UserPermissions] (
                UserID                INT PRIMARY KEY,
                perm_dashboard        BIT NOT NULL DEFAULT 1,
                perm_fuel_records     BIT NOT NULL DEFAULT 1,
                perm_driver_card      BIT NOT NULL DEFAULT 1,
                perm_fuel_tank        BIT NOT NULL DEFAULT 1,
                perm_add_fuel         BIT NOT NULL DEFAULT 1,
                perm_tank_fill        BIT NOT NULL DEFAULT 1,
                perm_edit_fuel_price  BIT NOT NULL DEFAULT 1,
                perm_edit_fuel        BIT NOT NULL DEFAULT 0,
                perm_delete_fuel      BIT NOT NULL DEFAULT 0,
                perm_admin            BIT NOT NULL DEFAULT 0
            )
        END
        ELSE
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_UserPermissions'
                  AND COLUMN_NAME = 'perm_edit_fuel_price'
            )
            BEGIN
                ALTER TABLE [dbo].[Tbl_UserPermissions]
                ADD perm_edit_fuel_price BIT NOT NULL DEFAULT 1
            END
            IF NOT EXISTS (
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_UserPermissions'
                  AND COLUMN_NAME = 'perm_edit_fuel'
            )
            BEGIN
                ALTER TABLE [dbo].[Tbl_UserPermissions]
                ADD perm_edit_fuel BIT NOT NULL DEFAULT 0
            END
            IF NOT EXISTS (
                SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_UserPermissions'
                  AND COLUMN_NAME = 'perm_delete_fuel'
            )
            BEGIN
                ALTER TABLE [dbo].[Tbl_UserPermissions]
                ADD perm_delete_fuel BIT NOT NULL DEFAULT 0
            END
        END";
    sqlsrv_query($conn, $createSql);

    // Fetch all active users joined with their permissions (LEFT JOIN — defaults to 1 if no row yet)
    $sql = "
        SELECT
            u.id,
            u.username,
            u.DisplayName,
            u.user_type,
            u.Department,
            ISNULL(p.perm_dashboard,   1) AS perm_dashboard,
            ISNULL(p.perm_fuel_records,1) AS perm_fuel_records,
            ISNULL(p.perm_driver_card, 1) AS perm_driver_card,
            ISNULL(p.perm_fuel_tank,   1) AS perm_fuel_tank,
            ISNULL(p.perm_add_fuel,    1) AS perm_add_fuel,
            ISNULL(p.perm_tank_fill,   1) AS perm_tank_fill,
            ISNULL(p.perm_edit_fuel_price, 1) AS perm_edit_fuel_price,
            ISNULL(p.perm_edit_fuel,   0) AS perm_edit_fuel,
            ISNULL(p.perm_delete_fuel, 0) AS perm_delete_fuel,
            ISNULL(p.perm_admin,       0) AS perm_admin
        FROM [dbo].[ViewUserLogIn] u
        LEFT JOIN [dbo].[Tbl_UserPermissions] p ON p.UserID = u.id
        WHERE u.Active = 1
        ORDER BY u.user_type DESC, u.DisplayName";

    $stmt = sqlsrv_query($conn, $sql);
    $users = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            foreach (['perm_dashboard','perm_fuel_records','perm_driver_card',
                      'perm_fuel_tank','perm_add_fuel','perm_tank_fill','perm_edit_fuel_price',
                      'perm_edit_fuel','perm_delete_fuel','perm_admin'] as $k) {
                $row[$k] = isset($row[$k]) ? (int)$row[$k] : 0;
            }
            // Fetch allowed departments for this user
            $dStmt = sqlsrv_query($conn, "SELECT Department FROM [dbo].[Tbl_UserDepartments] WHERE UserID = ? ORDER BY Department", [$row['id']]);
            $row['allowed_depts'] = [];
            if ($dStmt) { while ($dr = sqlsrv_fetch_array($dStmt, SQLSRV_FETCH_ASSOC)) $row['allowed_depts'][] = $dr['Department']; }
            $users[] = $row;
        }
    }
    closeConnection($conn);
    return ['users' => $users];
}

/**
 * Upsert a single permission flag for a user.
 */
function saveUserPermission($userId, $permKey, $value) {
    $allowed = ['perm_dashboard','perm_fuel_records','perm_driver_card',
                'perm_fuel_tank','perm_add_fuel','perm_tank_fill','perm_edit_fuel_price',
                'perm_edit_fuel','perm_delete_fuel','perm_admin'];
    if (!in_array($permKey, $allowed)) {
        return ['success' => false, 'message' => 'Invalid permission key.'];
    }

    $conn = getConnection();
    $bit  = $value ? 1 : 0;

    // MERGE (upsert) so we don't need to know if the row exists
    $sql = "
        MERGE [dbo].[Tbl_UserPermissions] AS target
        USING (SELECT ? AS UserID) AS source ON target.UserID = source.UserID
        WHEN MATCHED THEN
            UPDATE SET [$permKey] = ?
        WHEN NOT MATCHED THEN
            INSERT (UserID, perm_dashboard, perm_fuel_records, perm_driver_card,
                    perm_fuel_tank, perm_add_fuel, perm_tank_fill, perm_edit_fuel_price,
                    perm_edit_fuel, perm_delete_fuel, perm_admin)
            VALUES (?, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0);";

    // For the INSERT we default everything to 1 (except perm_admin=0),
    // then immediately set the requested key via a second update if it differs.
    $stmt = sqlsrv_query($conn, $sql, [$userId, $bit, $userId]);

    // If the key being set is perm_admin (default 0) or any other key whose INSERT
    // default doesn't match the requested value, do a plain UPDATE to fix it.
    $fix = "UPDATE [dbo].[Tbl_UserPermissions] SET [$permKey] = ? WHERE UserID = ?";
    sqlsrv_query($conn, $fix, [$bit, $userId]);

    $ok = ($stmt !== false);
    closeConnection($conn);
    return ['success' => $ok, 'message' => $ok ? 'Permission updated.' : 'Failed to update.'];
}

// ─────────────────────────────────────────────
// FUEL PRICE — global price per liter setting
// ─────────────────────────────────────────────

/**
 * Ensure Tbl_FuelPrice has a Supplier column.
 * Called internally before any price read/write.
 */
function _ensureFuelPriceTable($conn) {
    // Step 1: Create table if it doesn't exist at all
    sqlsrv_query($conn, "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_FuelPrice'
        )
        BEGIN
            CREATE TABLE [dbo].[Tbl_FuelPrice] (
                PriceID    INT IDENTITY(1,1) PRIMARY KEY,
                Supplier   NVARCHAR(100) NOT NULL DEFAULT 'TRADEWELL',
                Price      DECIMAL(10,2) NOT NULL,
                Note       NVARCHAR(255) NULL,
                SetByUser  INT NULL,
                SetAt      DATETIME NOT NULL DEFAULT GETDATE()
            )
        END");

    // Step 2: Seed TRADEWELL default row if missing
    sqlsrv_query($conn, "
        IF NOT EXISTS (SELECT 1 FROM [dbo].[Tbl_FuelPrice] WHERE Supplier = 'TRADEWELL')
            INSERT INTO [dbo].[Tbl_FuelPrice] (Supplier, Price, Note)
            VALUES ('TRADEWELL', 60.30, 'Default price')");

    // Step 3: Add Supplier column to existing table if it is missing
    sqlsrv_query($conn, "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'Tbl_FuelPrice' AND COLUMN_NAME = 'Supplier'
        )
        BEGIN
            ALTER TABLE [dbo].[Tbl_FuelPrice]
                ADD Supplier NVARCHAR(100) NOT NULL DEFAULT 'TRADEWELL'
        END");

    // Step 4: Seed TRADEWELL GUMACA row if missing
    sqlsrv_query($conn, "
        IF NOT EXISTS (SELECT 1 FROM [dbo].[Tbl_FuelPrice] WHERE Supplier = 'TRADEWELL GUMACA')
        BEGIN
            INSERT INTO [dbo].[Tbl_FuelPrice] (Supplier, Price, Note)
            SELECT TOP 1 'TRADEWELL GUMACA', Price, 'Initialised from TRADEWELL default'
            FROM [dbo].[Tbl_FuelPrice]
            WHERE Supplier = 'TRADEWELL'
            ORDER BY PriceID DESC
        END");
}

/**
 * Get the current active fuel price per liter for a given supplier.
 */
function getFuelPrice($supplier = 'TRADEWELL') {
    $conn = getConnection();
    _ensureFuelPriceTable($conn);

    $stmt = sqlsrv_query($conn,
        "SELECT TOP 1 Price, Note, SetAt FROM [dbo].[Tbl_FuelPrice] WHERE Supplier = ? ORDER BY PriceID DESC",
        [$supplier]);
    $row  = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    closeConnection($conn);

    $price = $row ? floatval($row['Price']) : 60.30;
    $note  = $row ? ($row['Note'] ?? '') : 'Default price';
    $setAt = $row && $row['SetAt'] instanceof DateTime ? $row['SetAt']->format('Y-m-d H:i') : '';

    return ['price' => $price, 'note' => $note, 'set_at' => $setAt, 'supplier' => $supplier];
}

/**
 * Insert a new fuel price record for a given supplier.
 */
function setFuelPrice($price, $note, $userId, $supplier = 'TRADEWELL') {
    $conn = getConnection();
    _ensureFuelPriceTable($conn);

    $stmt = sqlsrv_query($conn,
        "INSERT INTO [dbo].[Tbl_FuelPrice] (Supplier, Price, Note, SetByUser) VALUES (?, ?, ?, ?)",
        [$supplier, $price, $note ?: null, $userId ?: null]);

    $ok = ($stmt !== false);
    closeConnection($conn);
    return ['success' => $ok, 'message' => $ok ? 'Fuel price updated.' : 'Failed to update price.', 'price' => $price, 'supplier' => $supplier];
}

/**
 * Return recent price change history for a given supplier (last 20 entries).
 */
function getFuelPriceHistory($supplier = 'TRADEWELL') {
    $conn = getConnection();
    _ensureFuelPriceTable($conn);

    $stmt = sqlsrv_query($conn, "
        SELECT TOP 20
            fp.Price,
            fp.Note,
            CONVERT(varchar(19), fp.SetAt, 120) AS SetAt,
            u.DisplayName AS SetBy
        FROM [dbo].[Tbl_FuelPrice] fp
        LEFT JOIN [dbo].[ViewUserLogIn] u ON u.id = fp.SetByUser
        WHERE fp.Supplier = ?
        ORDER BY fp.PriceID DESC",
        [$supplier]);

    $history = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $history[] = $r;
        }
    }
    closeConnection($conn);
    return ['history' => $history];
}

// ─────────────────────────────────────────────
// System Access: simple control table approach
// Table: Tbl_UserSystemAccess (UserID, isApproved BIT)
// ─────────────────────────────────────────────

function ensureSystemAccessTable($conn) {
    sqlsrv_query($conn, "
        IF NOT EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='Tbl_UserSystemAccess'
        )
        BEGIN
            CREATE TABLE [dbo].[Tbl_UserSystemAccess] (
                UserID     INT  NOT NULL PRIMARY KEY,
                isApproved BIT  NOT NULL DEFAULT 1
            )
        END");
}

/**
 * Check if a user is approved to access the system.
 * Returns true if approved (or no row exists yet — default allow).
 * Superadmins always return true.
 */
function getUserSystemAccess($userId) {
    $conn = getConnection();
    ensureSystemAccessTable($conn);

    $stmt = sqlsrv_query($conn,
        "SELECT isApproved FROM [dbo].[Tbl_UserSystemAccess] WHERE UserID = ?",
        [$userId]);

    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        closeConnection($conn);
        return (bool)$row['isApproved'];
    }
    // No row = not yet managed = approved by default
    closeConnection($conn);
    return true;
}

/**
 * Set system access for a user (upsert).
 * $isApproved: true = Approved, false = Rejected
 */
function setUserSystemAccess($userId, $isApproved) {
    $conn = getConnection();
    ensureSystemAccessTable($conn);

    $bit = $isApproved ? 1 : 0;
    $sql = "
        MERGE [dbo].[Tbl_UserSystemAccess] AS t
        USING (SELECT ? AS UserID) AS s ON t.UserID = s.UserID
        WHEN MATCHED     THEN UPDATE SET t.isApproved = ?
        WHEN NOT MATCHED THEN INSERT (UserID, isApproved) VALUES (?, ?);";
    $stmt = sqlsrv_query($conn, $sql, [$userId, $bit, $userId, $bit]);
    $ok   = ($stmt !== false);
    closeConnection($conn);
    return ['success' => $ok, 'message' => $ok ? 'Access updated.' : 'Failed to update.'];
}

/**
 * Returns all users from ViewUserLogIn with their isApproved status.
 * Superadmins are always approved and locked.
 */
function getSystemAccessUsers() {
    $conn = getConnection();
    ensureSystemAccessTable($conn);

    $sql = "
        SELECT
            u.id,
            u.username,
            u.DisplayName,
            u.user_type,
            u.Department,
            u.Active,
            ISNULL(sa.isApproved, 1) AS isApproved
        FROM [dbo].[ViewUserLogIn] u
        LEFT JOIN [dbo].[Tbl_UserSystemAccess] sa ON sa.UserID = u.id
        ORDER BY
            CASE LOWER(u.user_type) WHEN 'superadmin' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END,
            u.DisplayName";

    $stmt  = sqlsrv_query($conn, $sql);
    $users = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $isSuperAdmin = strtolower($row['user_type'] ?? '') === 'superadmin';
            $row['isApproved'] = $isSuperAdmin ? 1 : (int)$row['isApproved'];
            $users[] = $row;
        }
    }
    closeConnection($conn);
    return ['users' => $users];
}
?>