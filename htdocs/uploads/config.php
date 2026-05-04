<?php
declare(strict_types=1);

if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

if (!defined('HMS_DB_HOST')) {
    define('HMS_DB_HOST', getenv('HMS_DB_HOST') !== false ? getenv('HMS_DB_HOST') : 'localhost');
}
if (!defined('HMS_DB_USER')) {
    define('HMS_DB_USER', getenv('HMS_DB_USER') !== false ? getenv('HMS_DB_USER') : 'root');
}
if (!defined('HMS_DB_PASS')) {
    define('HMS_DB_PASS', getenv('HMS_DB_PASS') !== false ? getenv('HMS_DB_PASS') : '');
}
if (!defined('HMS_DB_NAME')) {
    define('HMS_DB_NAME', getenv('HMS_DB_NAME') !== false ? getenv('HMS_DB_NAME') : 'hms_db');
}
if (!defined('HMS_API_KEY')) {
    define('HMS_API_KEY', getenv('HMS_API_KEY') !== false ? (string) getenv('HMS_API_KEY') : '');
}
if (!defined('HMS_TIMEZONE')) {
    define('HMS_TIMEZONE', getenv('HMS_TIMEZONE') !== false ? (string) getenv('HMS_TIMEZONE') : '');
}
if (HMS_TIMEZONE !== '' && function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(HMS_TIMEZONE);
}
