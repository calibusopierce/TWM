<?php
// SQL Server Connection Configuration
// Update these credentials to match your server
define('DB_SERVER', '122.52.195.3');         // Your server name
define('DB_DATABASE', 'TradewellDatabase');
define('DB_USERNAME', 'user2');          // Update with your SQL login
define('DB_PASSWORD', '12345'); // Update with your password

function getConnection() {
  $serverName = "TRADEWELL-SERVE";
//$serverName = "PC-Intern";
$connectionInfo = array( "Database"=>"TradewellDatabase", "UID"=> "user2", "PWD"=> "12345","CharacterSet" => "UTF-8");
$conn = sqlsrv_connect( $serverName, $connectionInfo );

 

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
