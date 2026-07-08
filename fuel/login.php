<?php
/**
 * login.php
 * ─────────────────────────────────────────────────────────────────
 * The fuel module no longer has its own standalone login — it now
 * runs on the shared TWM session/login (see includes/twm_auth_bridge.php).
 * This file is kept only so old bookmarks/links to fuel/login.php
 * don't 404; it just bounces to the real TWM login (or straight into
 * the fuel module if already logged in) and to the TWM login page.
 */

session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';

if (isset($_SESSION['UserID'])) {
    header('Location: ' . route('fuel'));
} else {
    header('Location: ' . route('login'));
}
exit;