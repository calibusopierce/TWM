<?php
// /TWM/includes/profile-save.php
// Dedicated AJAX endpoint for topbar profile modal saves.
// Must live in the same /includes/ folder as topbar.php.
// Called via fetch('/TWM/includes/profile-save.php', { method:'POST', body: formData })

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

// Guarantee a clean JSON-only response — discard anything nav.php may have buffered
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=UTF-8');

// ── Auth ───────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['UserID']) && empty($_SESSION['Username'])) {
    echo json_encode(['ok' => false, 'msg' => 'Not authenticated.']);
    exit;
}

// ── Must be a POST with our action ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['_action'] ?? '') !== 'profile_save') {
    echo json_encode(['ok' => false, 'msg' => 'Invalid request.']);
    exit;
}

// ── DB + employee record ───────────────────────────────────────
$ep = get_employee_profile($conn);
if (!$ep) {
    echo json_encode(['ok' => false, 'msg' => 'Employee profile not found. Are you linked to an employee record?']);
    exit;
}
if (!$pdo) {
    echo json_encode(['ok' => false, 'msg' => 'Database connection unavailable.']);
    exit;
}

$fileNo = $ep['FileNo'] ?? null;
if (!$fileNo) {
    echo json_encode(['ok' => false, 'msg' => 'Missing FileNo — cannot update record.']);
    exit;
}

// ── Allowed editable fields ────────────────────────────────────
// Sensitive/system fields (EmployeeID, FileNo, Gov IDs, Active, Blacklisted) are excluded.
$allowed = [
    'Mobile_Number', 'Phone_Number', 'Email_Address',
    'Present_Address', 'Permanent_Address',
    'Civil_Status', 'Religion', 'Nationality', 'Relationship',
    'Contact_Person', 'Contact_Number_Emergency',
    'Job_tittle', 'Position_held', 'Department', 'Branch',
    'Category', 'Employee_Status', 'CutOff', 'System',
    'Birth_Place', 'Gender',
    'Educational_Background',
    'Notes',
];

$sets = []; $params = [];
foreach ($allowed as $col) {
    if (array_key_exists($col, $_POST)) {
        $sets[]   = "[{$col}] = ?";
        $params[] = trim($_POST[$col]) !== '' ? trim($_POST[$col]) : null;
    }
}

if (empty($sets)) {
    echo json_encode(['ok' => false, 'msg' => 'Nothing to update.']);
    exit;
}
$params[] = (int)$fileNo;

// ── Update ─────────────────────────────────────────────────────
try {
    $sql  = "UPDATE [dbo].[TBL_HREmployeeList] SET " . implode(', ', $sets) . " WHERE FileNo = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    unset($_SESSION['employee_profile_cache'], $_SESSION['employee_profile_cache_ts']);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}