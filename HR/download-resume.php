<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
auth_check();

// ── RBAC gate ────────────────────────────────────────────────
$pdo_rbac = new PDO(
    "sqlsrv:Server=PIERCE;Database=TradewellDatabase;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
rbac_gate($pdo_rbac, 'view_applications');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit("No file specified.");
}

// Strip any directory components — only the bare filename is allowed
$file = basename($_GET['file']);

// Reject anything that still looks path-traversal-y or contains null bytes
if ($file === '' || $file === '.' || $file === '..' || strpos($file, "\0") !== false) {
    http_response_code(400);
    exit("Invalid filename.");
}

// Only allow known safe document extensions
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$allowedExtensions = ['pdf', 'doc', 'docx', 'txt', 'odt', 'rtf'];
if (!in_array($ext, $allowedExtensions, true)) {
    http_response_code(403);
    exit("File type not allowed.");
}

// Resolve the real path and verify it stays within the resumes directory
$resumesDir = realpath(__DIR__ . "/uploads/resumes");
$filePath   = realpath($resumesDir . DIRECTORY_SEPARATOR . $file);

// realpath() returns false if the file does not exist — also blocks traversal
if ($filePath === false || strpos($filePath, $resumesDir) !== 0) {
    http_response_code(404);
    exit("File not found.");
}

// Detect MIME type from the actual file bytes, not from the filename
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

if ($mimeType === false) {
    $mimeType = 'application/octet-stream';
}

// Send file as a forced download
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($file) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
ob_end_clean();
flush();
readfile($filePath);
exit;