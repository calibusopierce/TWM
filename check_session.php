<?php
session_start();
session_write_close();
header('Content-Type: application/json');

echo json_encode([
    'loggedIn' => isset($_SESSION['UserID'])
]);
?>