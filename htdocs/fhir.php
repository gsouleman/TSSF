<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'Unauthorized']]]);
    exit;
}
if (hms_db_table_exists($connection, 'tbl_acl_permission') && !hms_can($connection, 'interop.read')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'Missing interop.read']]]);
    exit;
}

$type = (string) ($_GET['resource'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
if (strtolower($type) !== 'patient' || $id < 1) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'Use ?resource=Patient&id=']]]);
    exit;
}

$fid = hms_current_facility_id();
if (hms_multi_site_enabled($connection)) {
    $stmt = mysqli_prepare($connection, 'SELECT id, first_name, last_name, email, dob, gender FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $fid);
} else {
    $stmt = mysqli_prepare($connection, 'SELECT id, first_name, last_name, email, dob, gender FROM tbl_patient WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $id);
}
mysqli_stmt_execute($stmt);
$p = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$p) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'Patient not found']]]);
    exit;
}

$resource = [
    'resourceType' => 'Patient',
    'id' => (string) $p['id'],
    'identifier' => [['system' => 'urn:hms:mrn', 'value' => (string) $p['id']]],
    'name' => [['family' => $p['last_name'], 'given' => [$p['first_name']]]]],
    'gender' => strtolower((string) $p['gender']) === 'female' ? 'female' : 'male',
    'birthDate' => str_replace('/', '-', (string) $p['dob']),
    'telecom' => [['system' => 'email', 'value' => $p['email']]],
];

header('Content-Type: application/fhir+json; charset=utf-8');
echo json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
