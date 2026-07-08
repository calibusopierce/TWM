<?php
// SQL Server Connection Configuration
// Update these credentials to match your server
define('DB_SERVER', 'PIERCE');         // Your server name
define('DB_DATABASE', 'TradewellDatabase');
define('DB_USERNAME', '');          // Update with your SQL login
define('DB_PASSWORD', ''); // Update with your password

function getConnection() {
    $connectionInfo = [
        "Database"     => DB_DATABASE,
        "CharacterSet" => "UTF-8",   // Enables Ñ/ñ and other special chars to transmit correctly
    ];

    $conn = sqlsrv_connect(DB_SERVER, $connectionInfo);

    if (!$conn) {
        die(json_encode([
            'error'   => true,
            'message' => 'Database connection failed: ' . print_r(sqlsrv_errors(), true)
        ]));
    }

    return $conn;
}

function closeConnection($conn) {
    sqlsrv_close($conn);
}
?>
