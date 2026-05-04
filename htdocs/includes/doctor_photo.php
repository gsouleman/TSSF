<?php
declare(strict_types=1);

/**
 * Doctor profile photos: uploads under hms/uploads/doctors/ plus SVG fallbacks in assets.
 */

function hms_doctor_photo_column_exists(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_employee', 'photo_path');
}

function hms_doctor_photo_upload_abs_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'doctors';
}

function hms_doctor_photo_ensure_upload_dir(): bool
{
    $dir = hms_doctor_photo_upload_abs_dir();
    if (is_dir($dir)) {
        return true;
    }

    return @mkdir($dir, 0755, true);
}

/**
 * Save an uploaded image for an employee id. Returns web-relative path (from hms/) or null.
 *
 * @param array<string, mixed>|null $file $_FILES['field']
 */
function hms_doctor_photo_save_upload(?array $file, int $employeeId): ?string
{
    if ($employeeId < 1 || $file === null) {
        return null;
    }
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $max = 2 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > $max) {
        return null;
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        return null;
    }
    $mime = (string) ($info['mime'] ?? '');
    $ext = null;
    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        $ext = 'jpg';
    } elseif ($mime === 'image/png') {
        $ext = 'png';
    } elseif ($mime === 'image/webp') {
        $ext = 'webp';
    }
    if ($ext === null) {
        return null;
    }
    if (!hms_doctor_photo_ensure_upload_dir()) {
        return null;
    }
    $slug = bin2hex(random_bytes(6));
    $basename = 'dr_' . $employeeId . '_' . $slug . '.' . $ext;
    $dest = hms_doctor_photo_upload_abs_dir() . DIRECTORY_SEPARATOR . $basename;
    if (!@move_uploaded_file($tmp, $dest)) {
        return null;
    }
    @chmod($dest, 0644);

    return 'uploads/doctors/' . $basename;
}

/** @param non-empty-string $relativeFromHms e.g. uploads/doctors/dr_1_abc.jpg */
function hms_doctor_photo_delete_uploaded_file(string $relativeFromHms): void
{
    $relativeFromHms = str_replace(['\\', "\0"], ['/', ''], $relativeFromHms);
    if (strpos($relativeFromHms, 'uploads/doctors/') !== 0) {
        return;
    }
    $hmsRoot = dirname(__DIR__);
    $full = $hmsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFromHms);
    $base = realpath(hms_doctor_photo_upload_abs_dir());
    if ($base === false || $full === false || !is_file($full)) {
        return;
    }
    if (strpos($full, $base) !== 0) {
        return;
    }
    @unlink($full);
}

/**
 * Image src for doctor cards/tables: uploaded path, else rotating sample SVG.
 *
 * @param array<string, mixed> $row tbl_employee row
 */
function hms_doctor_avatar_src(array $row): string
{
    $p = trim((string) ($row['photo_path'] ?? ''));
    if ($p !== '' && strpos($p, "\0") === false) {
        return $p;
    }
    $id = (int) ($row['id'] ?? 0);
    $n = ($id % 6) + 1;

    return 'assets/img/doctors/avatar-' . $n . '.svg';
}
