<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
hms_api_require_auth();

global $connection;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $fid = function_exists('hms_current_facility_id') ? hms_current_facility_id() : 0;
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    $hasFacCol = function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_patient', 'facility_id');
    $sel = 'SELECT id, first_name, last_name, email, dob, gender, patient_type, address, phone, status, created_at';
    if ($hasFacCol) {
        $sel .= ', facility_id';
    }
    $sel .= ' FROM tbl_patient';
    if ($ms && $hasFacCol && $fid > 0) {
        $sel .= ' WHERE facility_id = ' . (int) $fid;
    }
    $sel .= ' ORDER BY id DESC';
    $res = mysqli_query($connection, $sel);
    $rows = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
    }
    hms_json_response(['ok' => true, 'data' => $rows]);
}

if ($method === 'POST') {
    if (($_SESSION['role'] ?? '') !== '1') {
        hms_json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    if (!is_array($data)) {
        hms_json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    $first = trim((string) ($data['first_name'] ?? ''));
    $last = trim((string) ($data['last_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    if ($first === '' || $email === '') {
        hms_json_response(['ok' => false, 'error' => 'first_name and email are required'], 422);
    }
    $dob = (string) ($data['dob'] ?? '');
    $gender = (string) ($data['gender'] ?? '');
    $patient_type = (string) ($data['patient_type'] ?? 'OutPatient');
    $address = hms_cameroon_address_from_request($data);
    if ($address === '') {
        $address = (string) ($data['address'] ?? '');
    }
    $phone = (string) ($data['phone'] ?? '');
    $status = isset($data['status']) ? (int) $data['status'] : 1;
    $fid = function_exists('hms_current_facility_id') ? hms_current_facility_id() : 0;
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    $hasFacCol = function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_patient', 'facility_id');

    if ($ms && $hasFacCol && $fid > 0) {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status, facility_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($stmt, 'ssssssssii', $first, $last, $email, $dob, $gender, $patient_type, $address, $phone, $status, $fid);
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($stmt, 'ssssssssi', $first, $last, $email, $dob, $gender, $patient_type, $address, $phone, $status);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        hms_json_response(['ok' => false, 'error' => 'Could not create patient'], 500);
    }
    $id = mysqli_insert_id($connection);
    mysqli_stmt_close($stmt);
    hms_json_response(['ok' => true, 'id' => $id], 201);
}

hms_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
