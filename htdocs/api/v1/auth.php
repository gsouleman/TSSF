<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hms_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    hms_json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($username === '' || $password === '') {
    hms_json_response(['ok' => false, 'error' => 'Username and password required'], 422);
}

global $connection;
$stmt = mysqli_prepare(
    $connection,
    'SELECT id, first_name, last_name, username, password, role FROM tbl_employee WHERE username = ? AND status = 1 LIMIT 1'
);
if (!$stmt) {
    hms_json_response(['ok' => false, 'error' => 'Server error'], 500);
}
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row || !hms_verify_password($password, (string) $row['password'])) {
    hms_json_response(['ok' => false, 'error' => 'Invalid credentials'], 401);
}

hms_upgrade_legacy_password($connection, (int) $row['id'], $password, (string) $row['password']);

$name = trim($row['first_name'] . ' ' . $row['last_name']);
$_SESSION['name'] = $name;
$_SESSION['role'] = (string) $row['role'];
$_SESSION['user_id'] = (int) $row['id'];
$_SESSION['facility_id'] = 0;
hms_facility_set_default_for_user($connection, (int) $row['id']);
hms_audit_log($connection, 'api.login', 'employee', (int) $row['id']);

$facilities = hms_user_facilities($connection, (int) $row['id']);

hms_json_response([
    'ok' => true,
    'user' => [
        'id' => (int) $row['id'],
        'name' => $name,
        'username' => $row['username'],
        'role' => (string) $row['role'],
        'facility_id' => hms_current_facility_id(),
        'facilities' => $facilities,
    ],
]);
