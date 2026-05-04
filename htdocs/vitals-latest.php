<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['name'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);

    exit;
}
if (!hms_can($connection, 'patient.read') && !hms_can($connection, 'clinical.read')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);

    exit;
}

$pid = (int) ($_GET['patient_id'] ?? 0);
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);

if ($pid < 1 || !hms_db_table_exists($connection, 'tbl_vital_sign')) {
    echo json_encode(['ok' => true, 'prefill' => [], 'bannerHtml' => '']);

    exit;
}

$row = hms_vitals_fetch_latest($connection, $pid, $fid, $ms);
if ($row === null) {
    echo json_encode(['ok' => true, 'prefill' => [], 'bannerHtml' => '']);

    exit;
}

echo json_encode(
    [
        'ok' => true,
        'prefill' => hms_vitals_row_to_consult_prefill($row),
        'bannerHtml' => hms_vitals_banner_html($row),
    ],
    JSON_UNESCAPED_UNICODE
);
