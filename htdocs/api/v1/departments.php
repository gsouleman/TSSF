<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
hms_api_require_auth();

global $connection;
$res = mysqli_query($connection, 'SELECT id, department_name, description, status, created_at FROM tbl_department ORDER BY department_name');
$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
}
hms_json_response(['ok' => true, 'data' => $rows]);
