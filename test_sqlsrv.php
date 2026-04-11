<?php
$serverName = "PIERCE";

// ── Connection 1: TradewellDatabase (main) ─────────────────────
$connectionOptions = [
    "Database"               => "TradewellDatabase",
    "TrustServerCertificate" => true
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) {
    die("❌ Connection failed (TradewellDatabase): " . print_r(sqlsrv_errors(), true));
}

// ── Connection 2: PIRS (Uniform Inventory) ────────────────────
$connectionOptions2 = [
    "Database"               => "PIRS",
    "TrustServerCertificate" => true
];
$conn2 = sqlsrv_connect($serverName, $connectionOptions2);
if (!$conn2) {
    die("❌ Connection failed (PIRS): " . print_r(sqlsrv_errors(), true));
}
?>