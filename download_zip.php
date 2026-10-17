<?php
// TWM/download_zip.php — zips a Windows app folder and sends it. Any logged-in user.
ob_start(); // swallow any stray output from includes so headers can still be sent
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/auth_check.php';
auth_check();
session_write_close(); // don't block the session heartbeat during a big download

$allowed = ['64bit', '32bit'];
$app = $_GET['app'] ?? '';
if (!in_array($app, $allowed, true)) { http_response_code(404); exit('Not found'); }

if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZIP extension (php_zip) is not enabled on this server.'); }

$dir = realpath(__DIR__ . '/../tradewellportal/download/' . $app);
if ($dir === false || !is_dir($dir)) { http_response_code(404); exit('Folder not found'); }

// Collect files + a signature so the zip is only rebuilt when something changes
$files = [];
$sig   = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $files[] = $file->getPathname();
    $sig    .= $file->getPathname() . $file->getMTime() . $file->getSize();
}
if (!$files) { http_response_code(404); exit('No files available'); }

$tmpDir = sys_get_temp_dir();
$zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'twm_dl_' . $app . '_' . md5($sig) . '.zip';

if (!is_file($zipPath)) {
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . 'twm_dl_' . $app . '_*.zip') ?: [] as $old) { @unlink($old); }

    set_time_limit(0);
    $building = $zipPath . '.part';
    $zip = new ZipArchive();
    if ($zip->open($building, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit('Could not create ZIP');
    }
    $base = strlen($dir) + 1;
    foreach ($files as $path) {
        $zip->addFile($path, str_replace('\\', '/', substr($path, $base)));
    }
    $zip->close();
    rename($building, $zipPath);
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
$dlName = 'TWM-Windows-' . $app . '.zip';
header('Content-Disposition: attachment; filename="' . $dlName . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);
exit;