<?php
/**
 * logout.php
 * ─────────────────────────────────────────────────────────────────
 * The fuel module shares TWM's session now (see includes/twm_auth_bridge.php),
 * so signing out here should sign the user out of the whole TWM session,
 * not just fuel. Route through TWM's own logout.php instead of duplicating
 * session_destroy() logic here.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
redirect('home');