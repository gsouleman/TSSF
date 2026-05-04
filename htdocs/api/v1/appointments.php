<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
hms_api_require_auth();

global $connection;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $res = mysqli_query($connection, 'SELECT * FROM tbl_appointment ORDER BY id DESC');
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
    $appointment_id = trim((string) ($data['appointment_id'] ?? ''));
    $patient_name = trim((string) ($data['patient_name'] ?? ''));
    $department = trim((string) ($data['department'] ?? ''));
    $doctor = trim((string) ($data['doctor'] ?? ''));
    $date = (string) ($data['date'] ?? '');
    $time = (string) ($data['time'] ?? '');
    $message = (string) ($data['message'] ?? '');
    $status = isset($data['status']) ? (int) $data['status'] : 1;

    if ($appointment_id === '' || $patient_name === '') {
        hms_json_response(['ok' => false, 'error' => 'appointment_id and patient_name are required'], 422);
    }

    $stmt = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status) VALUES (?,?,?,?,?,?,?,?)'
    );
    mysqli_stmt_bind_param($stmt, 'sssssssi', $appointment_id, $patient_name, $department, $doctor, $date, $time, $message, $status);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        hms_json_response(['ok' => false, 'error' => 'Could not create appointment'], 500);
    }
    $id = mysqli_insert_id($connection);
    mysqli_stmt_close($stmt);
    hms_json_response(['ok' => true, 'id' => $id], 201);
}

hms_json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
