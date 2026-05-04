<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['name'])) {
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

hms_require_permission($connection, 'consult.write');

$fid = hms_current_facility_id();
$pid = (int) ($_GET['patient_id'] ?? 0);
$code = (string) ($_GET['code'] ?? '');

if ($pid < 1 || trim($code) === '') {
    echo json_encode(['ok' => false, 'error' => 'Patient and code required.']);
    exit;
}

if (!function_exists('hms_payment_ticket_validate_consult_prepay') || !hms_payment_ticket_tables_ok($connection)) {
    echo json_encode(['ok' => false, 'error' => 'Payment tickets are not available.']);
    exit;
}

$row = hms_payment_ticket_validate_consult_prepay($connection, $fid, $pid, $code);
if ($row === null) {
    echo json_encode(['ok' => false, 'error' => 'No valid paid consultation prepayment for this patient and code.']);
    exit;
}

$total = (float) ($row['total_amount'] ?? 0);
$lines = json_decode((string) ($row['lines_json'] ?? ''), true);
$parts = [];
if (is_array($lines)) {
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $parts[] = trim((string) ($ln['description'] ?? ''));
    }
}

echo json_encode([
    'ok' => true,
    'ticket_code' => (string) ($row['ticket_code'] ?? ''),
    'total' => $total,
    'total_fmt' => number_format($total, 0, '.', ' '),
    'summary' => implode('; ', array_filter($parts)),
], JSON_UNESCAPED_UNICODE);
