<?php
$serverName = "PIERCE";

// ── Connection 1: TradewellDatabase (main) ─────────────────────
$connectionOptions = [
    "Database"               => "TradewellDatabase",
    "CharacterSet"     => "UTF-8",   // Ensure UTF-8 encoding for string data
    "TrustServerCertificate" => true
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("❌ Connection failed (TradewellDatabase): " . print_r(sqlsrv_errors(), true));
}

// ── Connection 2: PIRS (Uniform Inventory) ────────────────────
$connectionOptions2 = [
    "Database"               => "PIRS",
    "CharacterSet"     => "UTF-8",   // Ensure UTF-8 encoding for string data
    "TrustServerCertificate" => true
];
$conn2 = sqlsrv_connect($serverName, $connectionOptions2);
if (!$conn2) {
    die("❌ Connection failed (PIRS): " . print_r(sqlsrv_errors(), true));
}
?>