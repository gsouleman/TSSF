<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$file = basename((string) ($_GET['file'] ?? ''));
if ($file === '' || !preg_match('/^DGI_[A-Za-z0-9_.-]+\\.csv$/', $file)) {
    http_response_code(400);
    echo 'Invalid file';
    exit;
}
$prefix = 'DGI_DECL_' . $fid . '_';
$prefix2 = 'DGI_ANNUEL_' . $fid . '_';
if (strpos($file, $prefix) !== 0 && strpos($file, $prefix2) !== 0) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'dgi' . DIRECTORY_SEPARATOR . $file;
$real = realpath($path);
$base = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'dgi');
if ($real === false || $base === false || strncmp($real, $base, strlen($base)) !== 0 || !is_readable($real)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file) . '"');
readfile($real);
exit;
