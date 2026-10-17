<?php
// ══════════════════════════════════════════════════════════════════════════════
// cleanup_corrupt_photos.php — One-time cleanup: deletes any cached event photo
// that is not a complete JPEG (i.e. the green-block corrupted frames cached
// before the isCompleteJpeg() check existed).
//
// Usage: drop this in the same folder as index.php / face_image.php, then
// either open it in a browser once, or run `php cleanup_corrupt_photos.php`
// from the command line. Delete this file afterward — it's a one-time tool,
// not something that should stay reachable on a live server.
// ══════════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../auth_check.php';
auth_check();
require_once __DIR__ . '/HikvisionAPI.php';

$photoDir = __DIR__ . '/cache/photos';

if (!is_dir($photoDir)) {
    echo "No cache/photos directory found at: $photoDir\n";
    exit;
}

$files = glob($photoDir . '/*.jpg');
$checked = 0;
$removed = 0;

foreach ($files as $file) {
    $checked++;
    $data = file_get_contents($file);
    $check = HikvisionAPI::checkPhotoValidity($data);
    if (!$check['valid']) {
        unlink($file);
        $removed++;
        echo "Removed corrupt: " . basename($file) . " ({$check['reason']})\n";
    }
}

echo "\nChecked {$checked} cached photo(s), removed {$removed} corrupt one(s).\n";
echo "Refresh the dashboard — the removed ones will be re-fetched live from the device.\n";
