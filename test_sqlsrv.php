<?php
if (defined('DB_LOADED')) return;
define('DB_LOADED', true);

// ── Connection options ────────────────────────────────────────
// Comment out the one not in use

// LOCAL
$serverName = 'PIERCE';
$connectionOptions = [
    "Database"               => "TradewellDatabase",
    "CharacterSet"           => "UTF-8",
    "TrustServerCertificate" => true
];
$pdoDsn  = "sqlsrv:Server={$serverName};Database=TradewellDatabase;TrustServerCertificate=1";
$pdoUser = null;
$pdoPass = null;

// PROD (uncomment when deploying)
// $serverName = '122.52.195.3';
// $connectionOptions = [
//     "Database"     => "TradewellDatabase",
//     "UID"          => "user2",
//     "PWD"          => "12345",
//     "CharacterSet" => "UTF-8",
// ];
// $pdoDsn  = "sqlsrv:Server={$serverName};Database=TradewellDatabase";
// $pdoUser = 'user2';
// $pdoPass = '12345';

// ── Connection 1: TradewellDatabase (main) ─────────────────────
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("❌ Connection failed (TradewellDatabase): " . print_r(sqlsrv_errors(), true));
}

// ── Global PDO connection ──────────────────────────────────────
try {
    $pdo = new PDO(
        $pdoDsn, $pdoUser, $pdoPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("❌ DB connection failed: " . $e->getMessage());
}
?>