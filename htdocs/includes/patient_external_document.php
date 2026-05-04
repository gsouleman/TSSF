<?php
declare(strict_types=1);

function hms_patient_external_document_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_patient_external_document');
}

/**
 * @return list<array<string,mixed>>
 */
function hms_patient_external_documents_list(mysqli $connection, int $facilityId, int $patientId): array
{
    if (!hms_patient_external_document_table_ok($connection) || $patientId < 1) {
        return [];
    }
    $fid = (int) $facilityId;
    $pid = (int) $patientId;
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, doc_kind, title, notes, file_path, mime, original_name, file_size, created_at, created_by
         FROM tbl_patient_external_document WHERE facility_id = ' . $fid . ' AND patient_id = ' . $pid
        . ' ORDER BY id DESC LIMIT 80'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * Load one document row for this patient & site (for download).
 *
 * @return array<string,mixed>|null
 */
function hms_patient_external_document_get(mysqli $connection, int $facilityId, int $patientId, int $docId): ?array
{
    if (!hms_patient_external_document_table_ok($connection) || $patientId < 1 || $docId < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id, facility_id, patient_id, doc_kind, title, notes, file_path, mime, original_name, file_size, created_at
         FROM tbl_patient_external_document WHERE id = ? AND facility_id = ? AND patient_id = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'iii', $docId, $facilityId, $patientId);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $r ?: null;
}

function hms_patient_external_document_path_is_safe(string $relative): bool
{
    if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
        return false;
    }
    $norm = str_replace('\\', '/', $relative);

    return strpos($norm, 'uploads/patient_docs/') === 0;
}

/**
 * Send file to browser; returns false if missing or unsafe.
 *
 * @param array<string,mixed> $row
 */
function hms_patient_external_document_send_download(array $row): bool
{
    $rel = (string) ($row['file_path'] ?? '');
    if (!hms_patient_external_document_path_is_safe($rel)) {
        return false;
    }
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($full) || !is_readable($full)) {
        return false;
    }
    $mime = (string) ($row['mime'] ?? 'application/octet-stream');
    $orig = (string) ($row['original_name'] ?? '');
    if ($orig === '') {
        $orig = 'document';
    }
    $disp = 'attachment; filename="' . str_replace('"', '', $orig) . '"';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($full));
    header('Content-Disposition: ' . $disp);
    header('X-Content-Type-Options: nosniff');
    readfile($full);

    return true;
}

/**
 * Save upload; returns relative path from hms/ or null.
 *
 * @param array<string,mixed>|null $file
 */
function hms_patient_external_document_save_upload(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $userId,
    ?array $file,
    string $docKind,
    string $title,
    string $notes,
    ?int $consultationId
): ?string {
    if (!hms_patient_external_document_table_ok($connection) || $patientId < 1 || $facilityId < 1) {
        return null;
    }
    if ($file === null) {
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
    $size = (int) ($file['size'] ?? 0);
    $max = 8 * 1024 * 1024;
    if ($size < 1 || $size > $max) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) $finfo->file($tmp) : 'application/octet-stream';
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return null;
    }
    $ext = $allowed[$mime];
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'patient_docs'
        . DIRECTORY_SEPARATOR . $facilityId . DIRECTORY_SEPARATOR . $patientId;
    if (!is_dir($base) && !@mkdir($base, 0755, true)) {
        return null;
    }
    $slug = bin2hex(random_bytes(8));
    $basename = 'doc_' . $slug . '.' . $ext;
    $dest = $base . DIRECTORY_SEPARATOR . $basename;
    if (!@move_uploaded_file($tmp, $dest)) {
        return null;
    }
    @chmod($dest, 0644);
    $rel = 'uploads/patient_docs/' . $facilityId . '/' . $patientId . '/' . $basename;
    $orig = isset($file['name']) ? substr((string) $file['name'], 0, 255) : '';
    $kind = preg_replace('/[^a-z_]/i', '', $docKind) ?: 'other';
    $title = trim($title) !== '' ? trim($title) : 'External document';
    $notes = trim($notes);
    $cid = ($consultationId !== null && $consultationId > 0) ? $consultationId : null;

    $ok = false;
    if ($cid !== null) {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient_external_document (facility_id, patient_id, consultation_id, doc_kind, title, notes, file_path, mime, file_size, original_name, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        if ($st) {
            mysqli_stmt_bind_param(
                $st,
                'iiisssssisi',
                $facilityId,
                $patientId,
                $cid,
                $kind,
                $title,
                $notes,
                $rel,
                $mime,
                $size,
                $orig,
                $userId
            );
            $ok = mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    } else {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient_external_document (facility_id, patient_id, consultation_id, doc_kind, title, notes, file_path, mime, file_size, original_name, created_by) VALUES (?,?,NULL,?,?,?,?,?,?,?,?)'
        );
        if ($st) {
            mysqli_stmt_bind_param(
                $st,
                'iisssssisi',
                $facilityId,
                $patientId,
                $kind,
                $title,
                $notes,
                $rel,
                $mime,
                $size,
                $orig,
                $userId
            );
            $ok = mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
    if (!$ok) {
        @unlink($dest);

        return null;
    }

    return $rel;
}
