<?php
/**
 * Cartrack Fleet API — configuration
 *
 * IMPORTANT:
 * - Do NOT commit this file to version control once filled in.
 * - Add "cartrack_config.php" to your .gitignore.
 * - Keep it outside webroot if possible, or block direct HTTP access via .htaccess.
 * - Rotate the API password immediately if it was ever pasted into chat, email, or a shared doc.
 */

return [
    // Confirm the correct region code for your account with Cartrack support / Fleetweb Admin.
    // Format: https://fleetapi-<region>.cartrack.com/rest
    'base_url' => 'https://fleetapi-ph.cartrack.com/rest',

    // API credentials (Admin Credentials page on Cartrack portal — separate from your login password)
    'api_user' => 'URBA00001',
    'api_pass' => '580785de43f784634fd81b93885e927635419358076806d60c468cb87a698119',

    // Request timeout in seconds
    'timeout' => 15,
];
