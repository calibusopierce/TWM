<?php
/* =====================================================================
   leave-qty-save.php
   File location: TWM/LEAVE/leave-qty-save.php
   RBAC module key: leave_management

   Two modes, both POST:
   1. Single add/edit  — normal form fields (ID, EmployeeID, LeaveID,
      Year, Qty). If a record already exists for that EmployeeID +
      LeaveID + Year, it's updated rather than duplicated.
   2. Bulk assign       — POST bulk=1, LeaveID, Year, Qty. Assigns that
      Qty to every ACTIVE employee for that leave type/year via a single
      MERGE statement (updates existing rows, inserts missing ones).

   ASSUMPTIONS (please verify):
   - "Active employee" is scoped by dbo.TBL_HREmployeeList.Active = 1
     (confirmed against the real table schema).
   - ControlNo for Tbl_Leave_Qty isn't specified anywhere in the tables
     you shared, so I generated a simple readable pattern:
     LQ-<Year>-<LeaveID>-<EmployeeID>. Tell me if there's an existing
     convention to match instead (e.g. a running sequence like the
     Leave Application's LV-YYYYMM-####).
   ===================================================================== */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'leave_management', 'full');
ob_end_clean();

header('Content-Type: application/json');
rbac_csrf_verify();

function respond($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

$isBulk = isset($_POST['bulk']) && $_POST['bulk'] == '1';

$leaveID = (int)($_POST['LeaveID'] ?? 0);
$year    = (int)($_POST['Year'] ?? 0);
$qty     = $_POST['Qty'] !== '' ? (float)$_POST['Qty'] : null;

if (!$leaveID)      respond(false, 'Please select a leave type.');
if (!$year)         respond(false, 'Please provide a year.');
if ($qty === null || $qty < 0) respond(false, 'Please provide a valid quantity.');

/* ==================================================================
   BULK ASSIGN — one MERGE covering every active employee
   ================================================================== */
if ($isBulk) {
    try {
        $sql = "MERGE dbo.Tbl_Leave_Qty AS target
                USING (
                    SELECT EmployeeID
                    FROM dbo.TBL_HREmployeeList
                    WHERE Active = 1
                ) AS src
                ON target.EmployeeID = src.EmployeeID
                   AND target.LeaveID = :leaveId
                   AND target.Year = :year
                WHEN MATCHED THEN
                    UPDATE SET Qty = :qtyUpdate
                WHEN NOT MATCHED THEN
                    INSERT (ControlNo, LeaveID, EmployeeID, Year, Qty)
                    VALUES (
                        CONCAT('LQ-', :yearForControl, '-', :leaveIdForControl, '-', src.EmployeeID),
                        :leaveIdInsert, src.EmployeeID, :yearInsert, :qtyInsert
                    );";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'leaveId'            => $leaveID,
            'year'               => $year,
            'qtyUpdate'          => $qty,
            'yearForControl'     => $year,
            'leaveIdForControl'  => $leaveID,
            'leaveIdInsert'      => $leaveID,
            'yearInsert'         => $year,
            'qtyInsert'          => $qty,
        ]);

        $affected = $stmt->rowCount();

        respond(true, 'Bulk assign complete.', ['affected' => $affected]);
    } catch (PDOException $e) {
        respond(false, 'Database error during bulk assign. Please verify the active-employee column matches your schema.');
    }
}

/* ==================================================================
   SINGLE ADD / EDIT
   ================================================================== */
$id         = (int)($_POST['ID'] ?? 0);
$employeeID = trim($_POST['EmployeeID'] ?? '');

if (!$employeeID) respond(false, 'Please select an employee.');

try {
    // Does a record already exist for this employee + leave type + year?
    $dupSql = "SELECT ID FROM dbo.Tbl_Leave_Qty
               WHERE EmployeeID = :empId AND LeaveID = :leaveId AND Year = :year
                 AND ID <> :id";
    $dupStmt = $pdo->prepare($dupSql);
    $dupStmt->execute(['empId' => $employeeID, 'leaveId' => $leaveID, 'year' => $year, 'id' => $id]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update the existing record instead of creating a duplicate
        $updSql = "UPDATE dbo.Tbl_Leave_Qty SET Qty = :qty WHERE ID = :id";
        $pdo->prepare($updSql)->execute(['qty' => $qty, 'id' => $existing['ID']]);
        respond(true, 'Existing credit record updated for this employee/type/year.');
    }

    if ($id > 0) {
        // Editing a record — allow changing employee/type/year/qty
        $sql = "UPDATE dbo.Tbl_Leave_Qty SET
                    EmployeeID = :empId, LeaveID = :leaveId, Year = :year, Qty = :qty
                WHERE ID = :id";
        $pdo->prepare($sql)->execute([
            'empId' => $employeeID, 'leaveId' => $leaveID, 'year' => $year, 'qty' => $qty, 'id' => $id,
        ]);
        respond(true, 'Leave credit updated successfully.');
    }

    // New record
    $controlNo = 'LQ-' . $year . '-' . $leaveID . '-' . $employeeID;
    $sql = "INSERT INTO dbo.Tbl_Leave_Qty (ControlNo, LeaveID, EmployeeID, Year, Qty)
            VALUES (:controlNo, :leaveId, :empId, :year, :qty)";
    $pdo->prepare($sql)->execute([
        'controlNo' => $controlNo, 'leaveId' => $leaveID, 'empId' => $employeeID, 'year' => $year, 'qty' => $qty,
    ]);

    respond(true, 'Leave credit added successfully.');
} catch (PDOException $e) {
    respond(false, 'Database error while saving the leave credit.');
}