<?php
/**
 * logout.php
 * Clears the session entirely and sends the person back to login.
 * Works from either the root or a subfolder link.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
redirect('home');