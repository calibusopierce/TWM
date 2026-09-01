<?php
/**
 * departments.php
 * Shared helper for populating department dropdowns from dbo.Departments.
 * Requires an already-open sqlsrv connection (see includes/config.php).
 */
function getActiveDepartments($conn) {
    $sql = "SELECT DepartmentID, DepartmentCode, Department, DepartmentName
            FROM Departments
            WHERE Status = 1
            ORDER BY DepartmentName ASC, Department ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    $departments = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $row;
    }

    return $departments;
}
