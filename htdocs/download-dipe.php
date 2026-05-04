<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$fid = hms_current_facility_id();
$stmt = mysqli_prepare(
    $connection,
    'SELECT filename, file_path, facility_id FROM tbl_hms_dipe_history WHERE id = ? LIMIT 1'
);
if (!$stmt) {
    http_response_code(500);
    echo 'Database error';
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row || (int) ($row['facility_id'] ?? 0) !== $fid) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$fn = (string) ($row['filename'] ?? 'dipe.txt');
$rel = (string) ($row['file_path'] ?? '');
$path = $rel !== '' && $rel[0] !== '/' && !preg_match('#^[a-z]:#i', $rel)
    ? __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel)
    : $rel;

if ($path === '' || !is_readable($path)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fn) . '"');
readfile($path);
exit;
