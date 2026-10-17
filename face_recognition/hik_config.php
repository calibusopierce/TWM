<?php
// ══════════════════════════════════════════════════════════════════════════════
// hik_config.php — Hikvision device credentials
// Keep this file OUT of version control and blocked via .htaccess (see below).
// ══════════════════════════════════════════════════════════════════════════════

define('HIKVISION_IP', '192.168.100.59');
define('HIKVISION_PORT', '80');
define('HIKVISION_USERNAME', 'admin');
define('HIKVISION_PASSWORD', 'twm88888');

// Shared secret for hik_receiver.php — the device appends this as ?k=<token>
// when it POSTs an event. The receiver can't use auth_check()/rbac_gate()
// because the device has no TWM session, so this plus a source-IP check is
// what gates it. Must match the URL configured under HTTP Listening.
define('HIK_PUSH_TOKEN', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');
