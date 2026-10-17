<?php
/* =====================================================================
   leave-validate.php
   File location: TWM/LEAVE/leave-validate.php

   Shared server-side checks for leave-application-save.php and
   leave-application-edit.php. Returns [numDays, errorMessage].
   ===================================================================== */

function leave_validate_request(PDO $pdo, $employeeID, $typeID, $saEmpID, $hrEmpID,
                                $dateStart, $dateEnd, $halfDay, $excludeId = 0)
{
    $d1 = DateTime::createFromFormat('Y-m-d', $dateStart);
    $d2 = DateTime::createFromFormat('Y-m-d', $dateEnd);
    if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $dateStart || $d2->format('Y-m-d') !== $dateEnd) {
        return [null, 'Invalid date format.'];
    }
    if ($d2 < $d1) {
        return [null, 'End date cannot be before start date.'];
    }

    // Same rule as recalcDays() in leave-application.php:
    // inclusive calendar days, minus 0.5 if half-day.
    $numDays = $d1->diff($d2)->days + 1;
    if ($halfDay) $numDays -= 0.5;

    try {
        // --- leave type must exist and be active ---
        $st = $pdo->prepare("SELECT Status, Max_Days_Per_Year FROM dbo.Tbl_Leave_Type WHERE ID = :id");
        $st->execute(['id' => $typeID]);
        $type = $st->fetch(PDO::FETCH_ASSOC);
        if (!$type || (int)$type['Status'] !== 1) {
            return [null, 'The selected leave type is not available.'];
        }

        // --- supervisor must be an active employee ---
        $st = $pdo->prepare("SELECT 1 AS Ok FROM dbo.TBL_HREmployeeList WHERE EmployeeID = :id AND Active = 1");
        $st->execute(['id' => $saEmpID]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return [null, 'The selected supervisor is not an active employee.'];
        }

        // --- HR reviewer must be an active HR ASSISTANT / HR SPECIALIST ---
        $st = $pdo->prepare("SELECT 1 AS Ok FROM dbo.TBL_HREmployeeList
                             WHERE EmployeeID = :id AND Active = 1
                               AND Position_held IN ('HR ASSISTANT', 'HR SPECIALIST')");
        $st->execute(['id' => $hrEmpID]);
        if (!$st->fetch(PDO::FETCH_ASSOC)) {
            return [null, 'The selected HR reviewer is not a valid HR approver.'];
        }

        // --- overlap with the employee's other non-rejected applications ---
        // HR is the final authority, so only HR_Status = 2 frees the dates.
        $st = $pdo->prepare("SELECT TOP 1 ControlNo FROM dbo.Tbl_Leave_Application
                             WHERE EmployeeID = :emp AND HR_Status <> 2 AND ID <> :ex
                               AND Date_Start <= :de AND Date_End >= :ds");
        $st->execute(['emp' => $employeeID, 'ex' => (int)$excludeId, 'de' => $dateEnd, 'ds' => $dateStart]);
        $overlap = $st->fetch(PDO::FETCH_ASSOC);
        if ($overlap) {
            return [null, 'These dates overlap with your existing application ' . trim($overlap['ControlNo']) . '.'];
        }

        // --- balance: credit row if one exists, else Max_Days_Per_Year if set ---
        $year = (int)$d1->format('Y');
        $st = $pdo->prepare("SELECT TOP 1 Qty FROM dbo.Tbl_Leave_Qty
                             WHERE EmployeeID = :emp AND LeaveID = :t AND Year = :y");
        $st->execute(['emp' => $employeeID, 't' => $typeID, 'y' => $year]);
        $qtyRow = $st->fetch(PDO::FETCH_ASSOC);

        $limit = null;
        if ($qtyRow) {
            $limit = (float)$qtyRow['Qty'];
        } elseif ($type['Max_Days_Per_Year'] !== null && (float)$type['Max_Days_Per_Year'] > 0) {
            $limit = (float)$type['Max_Days_Per_Year'];
        }

        if ($limit !== null) {
            // Approved + pending count against the balance (anything HR hasn't rejected).
            $st = $pdo->prepare("SELECT ISNULL(SUM(NumberOfDays), 0) AS Used
                                 FROM dbo.Tbl_Leave_Application
                                 WHERE EmployeeID = :emp AND TypeID = :t
                                   AND YEAR(Date_Start) = :y AND HR_Status <> 2 AND ID <> :ex");
            $st->execute(['emp' => $employeeID, 't' => $typeID, 'y' => $year, 'ex' => (int)$excludeId]);
            $used = (float)$st->fetch(PDO::FETCH_ASSOC)['Used'];

            if ($used + $numDays > $limit) {
                $remaining = max(0, $limit - $used);
                return [null, 'Insufficient leave balance. Remaining for ' . $year . ': '
                              . $remaining . ' day(s); this request needs ' . $numDays . '.'];
            }
        }
    } catch (PDOException $e) {
        error_log('[leave-validate] ' . $e->getMessage());
        return [null, 'Could not validate this application. Please try again.'];
    }

    return [$numDays, null];
}