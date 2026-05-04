<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
hms_api_require_auth();

global $connection;
$res = mysqli_query(
    $connection,
    "SELECT id, first_name, last_name, username, emailid, dob, employee_id, joining_date, gender, address, phone, bio, status, created_at FROM tbl_employee WHERE role = 2 AND status = 1 ORDER BY first_name, last_name"
);
$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        unset($r['password']);
        $rows[] = $r;
    }
}
hms_json_response(['ok' => true, 'data' => $rows]);
