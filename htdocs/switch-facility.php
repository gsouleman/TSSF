<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: dashboard.php');
    exit;
}
if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    http_response_code(400);
    exit('Invalid security token.');
}
if (defined('HMS_FIXED_FACILITY_ID') && (int) HMS_FIXED_FACILITY_ID > 0) {
    $back = basename((string) ($_POST['return'] ?? 'dashboard.php'));
    if ($back === '' || !preg_match('/^[a-zA-Z0-9._-]+\\.php$/', $back)) {
        $back = 'dashboard.php';
    }
    header('Location: ' . $back);
    exit;
}
$fid = (int) ($_POST['facility_id'] ?? 0);
$uid = (int) $_SESSION['user_id'];
if ($fid > 0 && hms_user_can_access_facility($connection, $uid, $fid)) {
    $_SESSION['facility_id'] = $fid;
    hms_audit_log($connection, 'facility.switch', 'facility', $fid, ['user_id' => $uid]);
}
$back = basename((string) ($_POST['return'] ?? 'dashboard.php'));
if ($back === '' || !preg_match('/^[a-zA-Z0-9._-]+\\.php$/', $back)) {
    $back = 'dashboard.php';
}
header('Location: ' . $back);
exit;
