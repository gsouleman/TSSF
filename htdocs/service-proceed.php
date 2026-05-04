<?php
declare(strict_types=1);

/**
 * POST: start lab or radiology workflow from payment ticket line (Proceed button).
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['service_proceed'])) {
    header('Location: service-code-verify.php');
    exit;
}

if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    http_response_code(400);
    exit('Invalid security token.');
}

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$code = trim((string) ($_POST['ticket_code'] ?? ''));
$lineIdx = (int) ($_POST['line_idx'] ?? -1);
$portal = hms_service_verify_portal_normalize((string) ($_POST['portal'] ?? ''));

if ($code === '' || $lineIdx < 0 || $portal === null) {
    $_SESSION['svc_verify_flash'] = 'Invalid request.';
    header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
    exit;
}

if (!hms_payment_ticket_tables_ok($connection)) {
    $_SESSION['svc_verify_flash'] = 'Payment tickets are not available.';
    header('Location: service-code-verify.php');
    exit;
}

$ticket = hms_payment_ticket_lookup_by_code($connection, $fid, $code);
if ($ticket === null) {
    $_SESSION['svc_verify_flash'] = 'Ticket not found.';
    header('Location: service-code-verify.php?portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
    exit;
}

$pj = json_decode((string) ($ticket['lines_json'] ?? ''), true);
$lines = is_array($pj) ? $pj : [];
if (!isset($lines[$lineIdx]) || !is_array($lines[$lineIdx])) {
    $_SESSION['svc_verify_flash'] = 'Invalid line index.';
    header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
    exit;
}

$line = $lines[$lineIdx];
$kind = strtolower((string) ($line['kind'] ?? ''));
$flags = hms_payment_ticket_consult_flags($connection, $fid, $ticket);
$allow = hms_payment_ticket_line_fulfillment_allowed($line, $flags);

if (!$allow) {
    $_SESSION['svc_verify_flash'] = 'This line is not cleared for service (payment or exception required).';
    header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
    exit;
}

if (!hms_service_verify_show_proceed($kind, $portal)) {
    $_SESSION['svc_verify_flash'] = 'Wrong portal for this service line.';
    header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
    exit;
}

if ($kind === 'laboratory') {
    if (!hms_can($connection, 'lab.write')) {
        http_response_code(403);
        exit('Permission denied (lab.write).');
    }
    $labId = hms_service_proceed_ensure_lab($connection, $fid, $uid, $code, $lineIdx, $line, $ticket);
    if ($labId < 1) {
        $_SESSION['svc_verify_flash'] = 'Could not open lab work order. Run migration 024 or check the lab registry.';
        header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=laboratory');
        exit;
    }
    hms_audit_log($connection, 'lab.workflow.open', 'lab_result', $labId);
    header('Location: lab-result-workflow.php?id=' . $labId);
    exit;
}

if ($kind === 'radiology') {
    if (!hms_can($connection, 'radiology.write')) {
        http_response_code(403);
        exit('Permission denied (radiology.write).');
    }
    $radId = hms_service_proceed_ensure_radiology($connection, $fid, $uid, $code, $lineIdx, $line, $ticket);
    if ($radId < 1) {
        $_SESSION['svc_verify_flash'] = 'Could not open radiology work order. Run migration 024 or check radiology tables.';
        header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=radiology');
        exit;
    }
    hms_audit_log($connection, 'radiology.workflow.open', 'radiology_result', $radId);
    header('Location: radiology-result-workflow.php?id=' . $radId);
    exit;
}

$_SESSION['svc_verify_flash'] = 'Proceed is not implemented for this line type yet.';
header('Location: service-code-verify.php?code=' . rawurlencode($code) . '&portal=' . rawurlencode((string) ($_POST['portal'] ?? '')));
exit;
