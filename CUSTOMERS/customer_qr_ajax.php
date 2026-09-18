<?php
/**
 * customer_qr_ajax.php
 * Location: /TWM/customers/customer_qr_ajax.php
 * RBAC module key: customer_qr
 *
 * Returns customers from dbo.View_Customer_With_QRCODE as JSON, with QRPicture
 * converted to a base64 data URI so the map can use it directly as marker icon
 * and modal image.
 *
 * ASSUMPTIONS TO CONFIRM / ADJUST:
 *  - DB connection include (placeholder below) — swap for whatever
 *    vehicle_status.php / gps_location_map.php actually require to get $conn
 *  - Assumes sqlsrv driver ($conn from sqlsrv_connect), matching the rest of
 *    TWM. If a module uses PDO instead, swap the query block accordingly.
 *  - QRPicture confirmed PNG binary from the sample row (starts with the PNG
 *    file signature 0x89504E47...), so the data URI is built as image/png.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();

// --- RBAC gate ---
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection unavailable']);
    exit;
}
try {
    rbac_gate($pdo, 'customer_qr');
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? 'list';

if ($action !== 'list') {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

try {
    $sql = "SELECT [ID],[Code],[CustomerName],[Address],[Area],[ContactNo],
                   [ContactPerson],[CustomerType],[TIN],[QRCODE],
                   [Latitude],[Longitude],[QRPicture]
            FROM [dbo].[View_Customer_With_QRCODE]
            WHERE [Latitude] IS NOT NULL AND [Longitude] IS NOT NULL
              AND [QRPicture] IS NOT NULL
              AND TRY_CAST([Latitude] AS FLOAT) > 0
              AND TRY_CAST([Longitude] AS FLOAT) > 0";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        throw new Exception(print_r(sqlsrv_errors(), true));
    }

    $data = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $qrDataUri = null;
        if (!empty($row['QRPicture'])) {
            // QRPicture comes back as a binary stream/string from sqlsrv
            $binary = $row['QRPicture'];
            $qrDataUri = 'data:image/png;base64,' . base64_encode($binary);
        }

        $data[] = [
            'ID'               => $row['ID'],
            'Code'             => $row['Code'],
            'CustomerName'     => $row['CustomerName'],
            'Address'          => $row['Address'],
            'Area'             => $row['Area'],
            'ContactNo'        => $row['ContactNo'],
            'ContactPerson'    => $row['ContactPerson'],
            'CustomerType'     => $row['CustomerType'],
            'TIN'              => $row['TIN'],
            'QRCODE'           => $row['QRCODE'],
            'Latitude'         => $row['Latitude'],
            'Longitude'        => $row['Longitude'],
            'QRPictureDataUri' => $qrDataUri,
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
