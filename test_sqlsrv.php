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

?> 