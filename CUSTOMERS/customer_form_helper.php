<?php
// TWM/CUSTOMERS/customer_form_helper.php
// Shared create/edit logic for dbo.Tbl_Customer_Info.
// Used by CUSTOMERS/customer_masterlist.php and QR/qr_untagged_map.php.

const CFH_UPLOAD_REL = 'uploads/customer_files';   // folder under TWM/ ; value stored in File_Path is relative to TWM/
const CFH_MAX_BYTES  = 5242880;                    // 5 MB per file
const CFH_DEBUG      = true;                       // true = show the SQL Server error text in the message; set to false once it works

function cfh_err_detail(): string {
    if (!CFH_DEBUG) return '';
    $e = sqlsrv_errors();
    return $e ? ' [' . trim((string)($e[0]['message'] ?? '')) . ']' : '';
}

function cfh_up(string $s): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}

function cfh_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

// UserInputID of whoever is logged in. Adjust the key list if your session uses a different name.
function cfh_user_id(): int {
    foreach (['UserInputID', 'user_id', 'UserID', 'EmployeeID', 'employee_id', 'FileNo', 'fileno'] as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 0;
}

// First 3 letters of the Area (letters only, upper-case). "MACALELON" -> "MAC"
function cfh_area_code(string $area): string {
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $area));
    $code = substr($letters, 0, 3);
    return $code !== '' ? $code : 'XXX';
}

// Dropdown / autofill data for the form
function cfh_options($conn): array {
    $areas = []; $types = []; $prov = []; $brgy = [];

    $lastId = 0;
    $st = sqlsrv_query($conn, "SELECT ISNULL(MAX(ID), 0) AS last_id FROM dbo.Tbl_Customer_Info");
    if ($st) { if ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $lastId = (int)$r['last_id']; sqlsrv_free_stmt($st); }

    $st = sqlsrv_query($conn, "SELECT DISTINCT LTRIM(RTRIM(Area)) AS v FROM dbo.Tbl_Customer_Info
                               WHERE Area IS NOT NULL AND LTRIM(RTRIM(Area)) <> '' ORDER BY v");
    if ($st) { while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $areas[] = (string)$r['v']; sqlsrv_free_stmt($st); }

    $st = sqlsrv_query($conn, "SELECT DISTINCT LTRIM(RTRIM(CustomerType)) AS v FROM dbo.Tbl_Customer_Info
                               WHERE CustomerType IS NOT NULL AND LTRIM(RTRIM(CustomerType)) <> '' ORDER BY v");
    if ($st) { while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $types[] = (string)$r['v']; sqlsrv_free_stmt($st); }

    // Most common Province per Area
    $st = sqlsrv_query($conn, "SELECT LTRIM(RTRIM(Area)) AS a, LTRIM(RTRIM(Province)) AS p, COUNT(*) AS n
                               FROM dbo.Tbl_Customer_Info
                               WHERE Area IS NOT NULL AND LTRIM(RTRIM(Area)) <> ''
                                 AND Province IS NOT NULL AND LTRIM(RTRIM(Province)) <> ''
                               GROUP BY LTRIM(RTRIM(Area)), LTRIM(RTRIM(Province))");
    if ($st) {
        $best = [];
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) {
            $a = (string)$r['a'];
            if (!isset($best[$a]) || (int)$r['n'] > $best[$a][1]) $best[$a] = [(string)$r['p'], (int)$r['n']];
        }
        sqlsrv_free_stmt($st);
        foreach ($best as $a => $pn) $prov[(string)$a] = $pn[0];
    }

    // Known barangays per Area (for the autocomplete list)
    $st = sqlsrv_query($conn, "SELECT DISTINCT LTRIM(RTRIM(Area)) AS a, LTRIM(RTRIM(Barangay)) AS b
                               FROM dbo.Tbl_Customer_Info
                               WHERE Area IS NOT NULL AND LTRIM(RTRIM(Area)) <> ''
                                 AND Barangay IS NOT NULL AND LTRIM(RTRIM(Barangay)) <> ''
                               ORDER BY a, b");
    if ($st) {
        while ($r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC)) $brgy[(string)$r['a']][] = (string)$r['b'];
        sqlsrv_free_stmt($st);
    }

    return [
        'last_id' => $lastId,
        'areas' => $areas,
        'types' => $types,
        'prov'  => (object)$prov,
        'brgy'  => (object)$brgy,
    ];
}

// Another customer with the same name in the same area?  Returns that customer's Code, or null.
function cfh_find_duplicate($conn, string $name, string $area, int $excludeId): ?string {
    $st = sqlsrv_query($conn, "SELECT TOP 1 Code FROM dbo.Tbl_Customer_Info
                               WHERE LTRIM(RTRIM(CustomerName)) = ? AND LTRIM(RTRIM(Area)) = ? AND ID <> ?",
                       [$name, $area, $excludeId]);
    if ($st === false) return null;
    $r = sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($st);
    return $r ? trim((string)$r['Code']) : null;
}

// Saves the uploaded file (if any). Returns [ok, relativePath|null, error|null, absolutePath|null]
function cfh_save_upload(string $field, string $prefix): array {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, null, null, null];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [false, null, 'File upload failed (error code ' . (int)$f['error'] . ').', null];
    }
    if ($f['size'] > CFH_MAX_BYTES) {
        return [false, null, 'The file is larger than 5 MB.', null];
    }

    $allowed = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
    ];
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return [false, null, 'Only JPG, PNG, GIF, WEBP or PDF files are allowed.', null];
    }
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, $allowed[$ext], true)) {
            return [false, null, 'The file content does not match its extension.', null];
        }
    }

    $dir = dirname(__DIR__) . '/' . CFH_UPLOAD_REL;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('Customer file upload: cannot create ' . $dir);
        return [false, null, 'Upload folder could not be created on the server.', null];
    }

    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
    $name = $safePrefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs  = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $abs)) {
        error_log('Customer file upload: move_uploaded_file failed for ' . $abs);
        return [false, null, 'The file could not be saved on the server.', null];
    }
    return [true, CFH_UPLOAD_REL . '/' . $name, null, $abs];
}

// Handles action=customer_create and action=customer_update (JSON response, then exit).
// $allowQr = true only on the QR Tagging page, where a new customer can be tagged to the scanned QR in the same save.
function cfh_handle_post($conn, bool $view_only, string $csrfKey, bool $allowQr = false): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    // When an upload exceeds post_max_size, PHP drops the whole body and $_POST is empty
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'The upload is larger than the server allows. Use a smaller file.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if (!in_array($action, ['customer_create', 'customer_update'], true)) return;

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    $fail = function (string $msg, array $extra = []) {
        echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
        exit;
    };

    if ($view_only) { http_response_code(403); $fail('View-only access.'); }
    if (!hash_equals((string)($_SESSION[$csrfKey] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        $fail('Session expired, reload the page.');
    }

    // ---- Read + validate fields ----
    $name  = cfh_up(trim((string)($_POST['name'] ?? '')));
    $area  = trim((string)($_POST['area'] ?? ''));
    $brgy  = trim((string)($_POST['barangay'] ?? ''));
    $prov  = trim((string)($_POST['province'] ?? ''));
    $addr  = trim((string)($_POST['address'] ?? ''));
    $cper  = cfh_up(trim((string)($_POST['contact_person'] ?? '')));
    $cno   = trim((string)($_POST['contact_no'] ?? ''));
    $ctype = trim((string)($_POST['customer_type'] ?? ''));
    $tin   = trim((string)($_POST['tin'] ?? ''));
    $rmk   = trim((string)($_POST['remarks'] ?? ''));
    $latS  = trim((string)($_POST['latitude'] ?? ''));
    $lngS  = trim((string)($_POST['longitude'] ?? ''));
    $force = ($_POST['force'] ?? '') === '1';

    if ($name === '') $fail('Customer name is required.');
    if ($area === '') $fail('Please select an Area.');

    // Same convention as existing records: Address holds "Barangay, Area, Province" unless typed otherwise
    if ($addr === '') $addr = implode(', ', array_filter([$brgy, $area, $prov], 'strlen'));

    $limits = [
        'Customer name'  => [$name, 200],
        'Area'           => [$area, 100],
        'Barangay'       => [$brgy, 100],
        'Province'       => [$prov, 100],
        'Address'        => [$addr, 300],
        'Contact person' => [$cper, 150],
        'Contact no.'    => [$cno, 50],
        'Customer type'  => [$ctype, 100],
        'TIN'            => [$tin, 50],
        'Remarks'        => [$rmk, 500],
    ];
    foreach ($limits as $label => [$val, $max]) {
        if (cfh_len($val) > $max) $fail("$label is too long (max $max characters).");
    }

    $lat = 0.0; $lng = 0.0;
    if ($latS !== '') {
        if (!is_numeric($latS) || abs((float)$latS) > 90)  $fail('Latitude must be a number between -90 and 90.');
        $lat = (float)$latS;
    }
    if ($lngS !== '') {
        if (!is_numeric($lngS) || abs((float)$lngS) > 180) $fail('Longitude must be a number between -180 and 180.');
        $lng = (float)$lngS;
    }

    // =====================================================================
    // CREATE
    // =====================================================================
    if ($action === 'customer_create') {
        $qr = '';
        if ($allowQr && ($_POST['tag_qr'] ?? '') === '1') $qr = trim((string)($_POST['qrcode'] ?? ''));

        if (!$force) {
            $dup = cfh_find_duplicate($conn, $name, $area, 0);
            if ($dup !== null) {
                $fail("A customer named \"$name\" already exists in $area" . ($dup !== '' ? " ($dup)" : '') . '.', ['duplicate' => true]);
            }
        }

        if (!sqlsrv_begin_transaction($conn)) {
            error_log('Customer create: begin transaction failed: ' . print_r(sqlsrv_errors(), true));
            $fail('Could not start the save. Please try again.');
        }

        $abs = null; // uploaded file path, removed again if the insert fails
        $rollback = function (string $msg) use ($conn, $fail, &$abs) {
            $msg .= cfh_err_detail();
            sqlsrv_rollback($conn);
            if ($abs && is_file($abs)) @unlink($abs);
            $fail($msg);
        };

        // The QR must still be untagged and unused
        if ($qr !== '') {
            $chk = sqlsrv_query($conn, "SELECT
                    (SELECT COUNT(*) FROM dbo.Tbl_Customer_Info WHERE QRCODE = ?) AS used_cnt,
                    (SELECT COUNT(*) FROM dbo.View_QRCode_Untagged WHERE QRCODE = ?) AS untagged_cnt", [$qr, $qr]);
            $cr = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
            if ($chk) sqlsrv_free_stmt($chk);
            if (!$cr || (int)$cr['used_cnt'] > 0 || (int)$cr['untagged_cnt'] < 1) {
                $rollback('This QR code is no longer available to tag. Reload the page and try again.');
            }
        }

        // ID is an IDENTITY column: insert first (temporary Code), read the new ID, then set the real Code
        $tmpCode = 'TMP-' . bin2hex(random_bytes(6));
        $ins = sqlsrv_query($conn, "
            SET NOCOUNT ON;
            INSERT INTO dbo.Tbl_Customer_Info
                (Code, CustomerName, Address, Area, ContactNo, ContactPerson, CustomerType, TIN,
                 Longitude, Latitude, Status, Remarks, Picture, UserInputID, DateTimeInput,
                 Barangay, Province, QRCODE, File_Path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NULL, ?, GETDATE(), ?, ?, ?, NULL);
            SELECT CAST(SCOPE_IDENTITY() AS int) AS new_id;",
            [$tmpCode, $name, $addr, $area, $cno, $cper, $ctype, $tin,
             $lng, $lat, $rmk, cfh_user_id(), $brgy, $prov, ($qr !== '' ? $qr : null)]);
        $idRow = $ins ? sqlsrv_fetch_array($ins, SQLSRV_FETCH_ASSOC) : null;
        if ($ins) sqlsrv_free_stmt($ins);
        if (!$idRow || (int)$idRow['new_id'] <= 0) {
            error_log('Customer create: insert failed: ' . print_r(sqlsrv_errors(), true));
            $rollback('Save failed. The customer was not created.');
        }
        $newId = (int)$idRow['new_id'];
        $code  = 'CUS-' . cfh_area_code($area) . '-' . $newId;

        [$okF, $rel, $errF, $abs] = cfh_save_upload('file', $code);
        if (!$okF) $rollback($errF);

        $upd = sqlsrv_query($conn, "UPDATE dbo.Tbl_Customer_Info SET Code = ?, File_Path = ? WHERE ID = ?", [$code, $rel, $newId]);
        if ($upd === false) {
            error_log('Customer create: code update failed: ' . print_r(sqlsrv_errors(), true));
            $rollback('Save failed. The customer was not created.');
        }

        if (!sqlsrv_commit($conn)) {
            error_log('Customer create: commit failed: ' . print_r(sqlsrv_errors(), true));
            $rollback('Save failed. The customer was not created.');
        }

        echo json_encode(['ok' => true, 'id' => $newId, 'code' => $code, 'tagged' => ($qr !== '')]);
        exit;
    }

    // =====================================================================
    // UPDATE  (Code, Status, QRCODE, Picture and UserInputID are never touched here)
    // =====================================================================
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) $fail('Missing customer ID.');

    $st = sqlsrv_query($conn, "SELECT Code FROM dbo.Tbl_Customer_Info WHERE ID = ?", [$id]);
    $ex = $st ? sqlsrv_fetch_array($st, SQLSRV_FETCH_ASSOC) : null;
    if ($st) sqlsrv_free_stmt($st);
    if (!$ex) $fail('Customer not found.');

    if (!$force) {
        $dup = cfh_find_duplicate($conn, $name, $area, $id);
        if ($dup !== null) {
            $fail("Another customer named \"$name\" already exists in $area" . ($dup !== '' ? " ($dup)" : '') . '.', ['duplicate' => true]);
        }
    }

    $prefix = trim((string)$ex['Code']) !== '' ? trim((string)$ex['Code']) : ('CUS-' . $id);
    [$okF, $rel, $errF, $abs] = cfh_save_upload('file', $prefix);
    if (!$okF) $fail($errF);

    $set = "CustomerName = ?, Address = ?, Area = ?, ContactNo = ?, ContactPerson = ?, CustomerType = ?, TIN = ?,
            Longitude = ?, Latitude = ?, Remarks = ?, Barangay = ?, Province = ?";
    $p   = [$name, $addr, $area, $cno, $cper, $ctype, $tin, $lng, $lat, $rmk, $brgy, $prov];
    if ($rel !== null) { $set .= ", File_Path = ?"; $p[] = $rel; }
    $p[] = $id;

    $up = sqlsrv_query($conn, "UPDATE dbo.Tbl_Customer_Info SET $set WHERE ID = ?", $p);
    if ($up === false) {
        error_log('Customer update failed: ' . print_r(sqlsrv_errors(), true));
        if ($abs && is_file($abs)) @unlink($abs);
        $fail('Save failed. The customer was not updated.' . cfh_err_detail());
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}
