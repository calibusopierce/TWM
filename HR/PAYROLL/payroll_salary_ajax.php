<?php
/**
 * payroll_salary_ajax.php — Basic Salary (TBL_PayrollSalary), versioned rate history.
 *
 * GET  ?action=list&q=...            active employees + their current (SActive = 1) rate
 * GET  ?action=history&EmployeeID=.. every rate version for one employee, newest first
 * GET  ?action=compute&SMonthly=..   derived SCutoff / SDaily
 * POST action=save + rate fields     inserts a new version; the previous active row gets SActive = 0
 *
 * ASSUMPTIONS (see chat):
 *  - TBL_PayrollSalary.EmployeeID = TBL_HREmployeeList.EmployeeID (the internal nvarchar one,
 *    same as TBL_DeviceID.EmployeeID), NOT FileNo.
 *  - TBL_PayrollSalary.ID is an IDENTITY column.
 *
 * The include block below mirrors payroll_dashboard.php minus nav.php. If payroll_group_ajax.php
 * starts differently (CSRF check on POST, JSON-style RBAC failure, ...), copy that block here.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();
rbac_gate($pdo, 'payroll_dashboard');

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');

const RBAC_KEY      = 'payroll_dashboard';
const DAILY_DIVISOR = 313;   // Daily = Monthly x 12 / 313
const MAX_LIST_ROWS = 300;

/* ---------------------------------------------------------------- helpers */

function out($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function fail($message, $status = 400)
{
    out(array('success' => false, 'message' => $message), $status);
}

/** Runs a query and returns all rows, or false on error (details go to the server log only). */
function db_rows($conn, $sql, $params = array())
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        error_log('[payroll_salary_ajax] SQL error: ' . print_r(sqlsrv_errors(), true));
        return false;
    }
    $rows = array();
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

/** Reads a money field. Blank => null (unless required). Invalid => InvalidArgumentException. */
function money_in($src, $key, $label, $required = false)
{
    $raw = isset($src[$key]) ? trim(str_replace(',', '', (string)$src[$key])) : '';
    if ($raw === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    if (!is_numeric($raw) || (float)$raw < 0) {
        throw new InvalidArgumentException($label . ' must be a number, 0 or more.');
    }
    if ((float)$raw > 9999999.99) {
        throw new InvalidArgumentException($label . ' is too large.');
    }
    return round((float)$raw, 2);
}

/** Cutoff = Monthly / 2 (semi-monthly). Daily = Monthly x 12 / 313. */
function compute_rates($monthly)
{
    return array(
        'SCutoff' => round($monthly / 2, 2),
        'SDaily'  => round($monthly * 12 / DAILY_DIVISOR, 2),
    );
}

/* ---------------------------------------------------------- contributions */

/** EE / ER amounts from a bracket row: a fixed amount wins, otherwise percent of the base. */
function share_amounts($row, $base)
{
    $ee = ((float)$row['AEE'] > 0) ? (float)$row['AEE'] : round($base * (float)$row['PEE'] / 100, 2);
    $er = ((float)$row['AER'] > 0) ? (float)$row['AER'] : round($base * (float)$row['PER'] / 100, 2);
    return array($ee, $er);
}

/** Finds the bracket for $monthly in a Pag-IBIG / PhilHealth style table. Returns array(row, base) or null. */
function find_bracket($conn, $table, $startCol, $endCol, $monthly, $floorToMin)
{
    $cols = "AER, AEE, PER, PEE, $startCol AS RS, $endCol AS RE";
    $rows = db_rows($conn, "SELECT TOP 1 $cols FROM $table WHERE ? >= $startCol AND ? <= $endCol ORDER BY $startCol DESC",
                    array($monthly, $monthly));
    if ($rows) {
        return array($rows[0], $monthly);
    }
    // above the top bracket: cap at the ceiling
    $rows = db_rows($conn, "SELECT TOP 1 $cols FROM $table ORDER BY $endCol DESC");
    if ($rows && $monthly > (float)$rows[0]['RE']) {
        return array($rows[0], (float)$rows[0]['RE']);
    }
    // below the lowest bracket: floor (PhilHealth only; for Pag-IBIG we leave it blank rather than guess)
    if ($floorToMin) {
        $rows = db_rows($conn, "SELECT TOP 1 $cols FROM $table ORDER BY $startCol ASC");
        if ($rows && $monthly < (float)$rows[0]['RS']) {
            return array($rows[0], (float)$rows[0]['RS']);
        }
    }
    return null;
}

/** Monthly SSS / Pag-IBIG / PhilHealth shares from the TBL_Payroll_* tables. Null = not found. */
function compute_contributions($conn, $monthly)
{
    $c = array('SSSEE' => null, 'SSSER' => null, 'SPagibigEE' => null, 'SPagibigER' => null,
               'SPHEE' => null, 'SPHER' => null, 'warnings' => array());

    // SSS: active year, then the closest salary credit (ties round up).
    // Range columns are ignored on purpose: the 2025 RangeStart values overlap.
    $yr = db_rows($conn, "SELECT TOP 1 ID FROM TBL_Payroll_SSSYear WHERE Active = 1 ORDER BY SSSYear DESC");
    if ($yr) {
        $sss = db_rows($conn, "SELECT TOP 1 TotalER, TotalEE FROM TBL_Payroll_SSSTable
                               WHERE SSSYearID = ? ORDER BY ABS(TotalSalary - ?) ASC, TotalSalary DESC",
                       array($yr[0]['ID'], $monthly));
        if ($sss) {
            $c['SSSEE'] = round((float)$sss[0]['TotalEE'], 2);
            $c['SSSER'] = round((float)$sss[0]['TotalER'], 2);
        } else {
            $c['warnings'][] = 'No SSS bracket rows found for the active year.';
        }
    } else {
        $c['warnings'][] = 'No active SSS year found.';
    }

    $pg = find_bracket($conn, 'TBL_Payroll_PagibigContribution', 'PRangeS', 'PRangeE', $monthly, false);
    if ($pg) {
        list($ee, $er) = share_amounts($pg[0], $pg[1]);
        $c['SPagibigEE'] = $ee;
        $c['SPagibigER'] = $er;
    } else {
        $c['warnings'][] = 'No Pag-IBIG bracket for this salary - enter it manually.';
    }

    $ph = find_bracket($conn, 'TBL_Payroll_PHilHealthContribution', 'PHRangeS', 'PHRangeE', $monthly, true);
    if ($ph) {
        list($ee, $er) = share_amounts($ph[0], $ph[1]);
        $c['SPHEE'] = $ee;
        $c['SPHER'] = $er;
    } else {
        $c['warnings'][] = 'No PhilHealth bracket for this salary - enter it manually.';
    }

    return $c;
}

/* ---------------------------------------------------------------- actions */

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

try {
    switch ($action) {

        /* ---- list: active employees + current rate ---- */
        case 'list':
            $q          = trim(isset($_GET['q']) ? (string)$_GET['q'] : '');
            $deptFilter = trim(isset($_GET['Department']) ? (string)$_GET['Department'] : '');
            $pgFilter   = trim(isset($_GET['PayrollGroup']) ? (string)$_GET['PayrollGroup'] : '');

            $where  = 'e.Active = 1';
            $params = array();
            if ($q !== '') {
                $where .= " AND (e.EmployeeID LIKE ?
                             OR ISNULL(e.LastName, '') + ', ' + ISNULL(e.FirstName, '') LIKE ?
                             OR ISNULL(e.FirstName, '') + ' ' + ISNULL(e.LastName, '') LIKE ?)";
                $like     = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($deptFilter !== '') {
                $where   .= " AND e.Department = ?";
                $params[] = $deptFilter;
            }
            if ($pgFilter !== '') {
                // g.PayrollGroup comes from the OUTER APPLY below (employee's current tagged group)
                $where   .= " AND g.PayrollGroup = ?";
                $params[] = $pgFilter;
            }

            $sql = "
                SELECT TOP " . MAX_LIST_ROWS . "
                    e.EmployeeID,
                    LTRIM(RTRIM(ISNULL(e.LastName, ''))) + ', ' + LTRIM(RTRIM(ISNULL(e.FirstName, ''))) AS Name,
                    e.Department,
                    g.PayrollGroup,
                    s.ID AS SalaryID,
                    s.SMonthly, s.SCutoff, s.SDaily,
                    s.SSSEE, s.SSSER, s.SPagibigEE, s.SPagibigER, s.SPHEE, s.SPHER,
                    s.SWTax, s.SAllowance,
                    CONVERT(varchar(10), s.SDateActive, 23) AS SDateActive
                FROM TBL_HREmployeeList e
                OUTER APPLY (
                    SELECT TOP 1 d.PayrollGroup
                    FROM TBL_DeviceID d
                    WHERE d.EmployeeID = e.EmployeeID
                      AND d.PayrollGroup IS NOT NULL AND d.PayrollGroup <> ''
                    ORDER BY d.ID DESC
                ) g
                OUTER APPLY (
                    SELECT TOP 1 ps.*
                    FROM TBL_PayrollSalary ps
                    WHERE ps.EmployeeID = e.EmployeeID AND ps.SActive = 1
                    ORDER BY ps.SDateActive DESC, ps.ID DESC
                ) s
                WHERE $where
                ORDER BY e.LastName, e.FirstName";

            $rows = db_rows($conn, $sql, $params);
            if ($rows === false) {
                fail('Could not load employees.', 500);
            }
            out(array('success' => true, 'data' => $rows, 'truncated' => count($rows) >= MAX_LIST_ROWS));
            break;

        /* ---- filters: distinct Department / PayrollGroup values for the filter dropdowns ---- */
        case 'filters':
            $depts = db_rows($conn, "
                SELECT DISTINCT Department
                FROM TBL_HREmployeeList
                WHERE Active = 1 AND Department IS NOT NULL AND Department <> ''
                ORDER BY Department");
            $groups = db_rows($conn, "
                SELECT DISTINCT PayrollGroup
                FROM TBL_PayrollGroup
                WHERE Active = 1 AND PayrollGroup IS NOT NULL AND PayrollGroup <> ''
                ORDER BY PayrollGroup");
            if ($depts === false || $groups === false) {
                fail('Could not load filter options.', 500);
            }
            $deptList = array();
            foreach ($depts as $r) { $deptList[] = $r['Department']; }
            $groupList = array();
            foreach ($groups as $r) { $groupList[] = $r['PayrollGroup']; }
            out(array('success' => true, 'data' => array(
                'departments'   => $deptList,
                'payrollGroups' => $groupList,
            )));
            break;

        /* ---- history: every version for one employee ---- */
        case 'history':
            $empId = trim(isset($_GET['EmployeeID']) ? (string)$_GET['EmployeeID'] : '');
            if ($empId === '') {
                fail('EmployeeID is required.');
            }
            $rows = db_rows($conn, "
                SELECT ID, SMonthly, SCutoff, SDaily,
                       SSSEE, SSSER, SPagibigEE, SPagibigER, SPHEE, SPHER, SWTax, SAllowance,
                       CONVERT(varchar(10), SDateActive, 23) AS SDateActive,
                       ISNULL(CAST(SActive AS int), 0) AS SActive
                FROM TBL_PayrollSalary
                WHERE EmployeeID = ?
                ORDER BY SDateActive DESC, ID DESC", array($empId));
            if ($rows === false) {
                fail('Could not load rate history.', 500);
            }
            out(array('success' => true, 'data' => $rows));
            break;

        /* ---- compute: Cutoff / Daily from Monthly ---- */
        case 'compute':
            $monthly = money_in($_GET, 'SMonthly', 'Monthly Rate', true);
            $data                  = compute_rates($monthly);
            $data['contributions'] = compute_contributions($conn, $monthly);
            out(array('success' => true, 'data' => $data));
            break;

        /* ---- save: new version, old active row -> SActive = 0 ---- */
        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                fail('POST required.', 405);
            }
            if (rbac_is_view_only(RBAC_KEY)) {
                fail('You have view-only access to payroll.', 403);
            }

            $empId = trim(isset($_POST['EmployeeID']) ? (string)$_POST['EmployeeID'] : '');
            if ($empId === '') {
                throw new InvalidArgumentException('EmployeeID is required.');
            }

            $monthly = money_in($_POST, 'SMonthly', 'Monthly Rate', true);
            if ($monthly <= 0) {
                throw new InvalidArgumentException('Monthly Rate must be greater than 0.');
            }
            $auto   = compute_rates($monthly);
            $cutoff = money_in($_POST, 'SCutoff', 'Cutoff Rate');
            $daily  = money_in($_POST, 'SDaily', 'Daily Rate');
            if ($cutoff === null) { $cutoff = $auto['SCutoff']; }
            if ($daily === null)  { $daily  = $auto['SDaily']; }

            $sssEE  = money_in($_POST, 'SSSEE',      'SSS (Employee)');
            $sssER  = money_in($_POST, 'SSSER',      'SSS (Employer)');
            $pagEE  = money_in($_POST, 'SPagibigEE', 'Pag-IBIG (Employee)');
            $pagER  = money_in($_POST, 'SPagibigER', 'Pag-IBIG (Employer)');
            $phEE   = money_in($_POST, 'SPHEE',      'PhilHealth (Employee)');
            $phER   = money_in($_POST, 'SPHER',      'PhilHealth (Employer)');
            $wtax   = money_in($_POST, 'SWTax',      'Withholding Tax');
            $allow  = money_in($_POST, 'SAllowance', 'Allowance');
            // Blank contributions / tax / allowance are stored as 0.
            foreach (array('sssEE', 'sssER', 'pagEE', 'pagER', 'phEE', 'phER', 'wtax', 'allow') as $v) {
                if ($$v === null) { $$v = 0.0; }
            }

            $dateActive = trim(isset($_POST['SDateActive']) ? (string)$_POST['SDateActive'] : '');
            if ($dateActive === '') {
                $dateActive = date('Y-m-d');
            } else {
                $dt = DateTime::createFromFormat('Y-m-d', $dateActive);
                if (!$dt || $dt->format('Y-m-d') !== $dateActive) {
                    throw new InvalidArgumentException('Effective Date is not a valid date.');
                }
            }

            $exists = db_rows($conn,
                "SELECT COUNT(*) AS C FROM TBL_HREmployeeList WHERE EmployeeID = ? AND Active = 1",
                array($empId));
            if ($exists === false) {
                fail('Could not verify the employee.', 500);
            }
            if ((int)$exists[0]['C'] === 0) {
                throw new InvalidArgumentException('Employee not found or not active.');
            }

            if (!sqlsrv_begin_transaction($conn)) {
                error_log('[payroll_salary_ajax] begin_transaction failed: ' . print_r(sqlsrv_errors(), true));
                fail('Could not save the rate.', 500);
            }

            $ok = sqlsrv_query($conn,
                "UPDATE TBL_PayrollSalary SET SActive = 0 WHERE EmployeeID = ? AND SActive = 1",
                array($empId));

            if ($ok !== false) {
                $ok = sqlsrv_query($conn, "
                    INSERT INTO TBL_PayrollSalary
                        (EmployeeID, SMonthly, SCutoff, SDaily,
                         SSSEE, SSSER, SPagibigEE, SPagibigER, SPHEE, SPHER,
                         SWTax, SAllowance, SDateActive, SActive)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    array($empId, $monthly, $cutoff, $daily,
                          $sssEE, $sssER, $pagEE, $pagER, $phEE, $phER,
                          $wtax, $allow, $dateActive));
            }

            if ($ok === false) {
                error_log('[payroll_salary_ajax] save failed: ' . print_r(sqlsrv_errors(), true));
                sqlsrv_rollback($conn);
                fail('Could not save the rate.', 500);
            }
            sqlsrv_commit($conn);

            out(array('success' => true, 'message' => 'Rate saved.'));
            break;

        default:
            fail('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[payroll_salary_ajax] ' . $e->getMessage());
    fail('Something went wrong.', 500);
}