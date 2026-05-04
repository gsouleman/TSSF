<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('mysqli_connect')) {
    http_response_code(503);
    exit('PHP mysqli extension is not enabled. In InfinityFree: Control Panel → PHP Configuration → enable mysqli, or pick a PHP version that includes it.');
}

$connection = mysqli_connect(HMS_DB_HOST, HMS_DB_USER, HMS_DB_PASS, HMS_DB_NAME);
if (!$connection) {
    http_response_code(503);
    exit('Database connection failed. Copy includes/config.sample.php to includes/config.local.php, uncomment the define() lines, and set the MySQL host, user, password, and database from your host (InfinityFree: not localhost — use the hostname from MySQL Databases).');
}

mysqli_set_charset($connection, 'utf8mb4');
