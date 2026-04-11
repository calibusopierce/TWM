<?php
// logout.php
// ✅ FIX: Removed duplicate/conflicting redirect — only one clean redirect now

session_start();
session_unset();
session_destroy();

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
redirect('login'); // ✅ Single clean redirect using route helper