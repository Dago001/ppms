<?php
// includes/local_secrets.sample.php
// Copy this file to includes/local_secrets.php (gitignored, never commit it)
// on every deployment and generate your own random value below. Do NOT reuse
// the sample value or any value that has ever been committed to git history.
//
// Generate a fresh one with:  php -r "echo bin2hex(random_bytes(32));"

if (!defined('RECORD_TOKEN_SECRET')) {
    define('RECORD_TOKEN_SECRET', 'REPLACE_WITH_YOUR_OWN_RANDOM_64_CHAR_HEX_SECRET');
}

// Production database password (used by includes/config.php's dynamic-live-
// production fallback when no .env or custom_db_config.php is present).
if (!defined('DB_PASSWORD_OVERRIDE')) {
    define('DB_PASSWORD_OVERRIDE', '');
}

// Termii SMS API (https://termii.com) - active real-time SMS provider.
if (!defined('TERMII_API_KEY')) {
    define('TERMII_API_KEY', '');
}

// eBulkSMS API - legacy/backup SMS provider.
if (!defined('EBULKSMS_API_TOKEN')) {
    define('EBULKSMS_API_TOKEN', '');
}
