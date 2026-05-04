<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
hms_api_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    hms_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

global $connection;
$uid = (int) ($_SESSION['user_id'] ?? 0);
$list = hms_user_facilities($connection, $uid);
hms_json_response(['ok' => true, 'facilities' => $list, 'current_facility_id' => hms_current_facility_id()]);
