<?php
declare(strict_types=1);

require_once __DIR__ . '/laboratory_dreams.php';

/**
 * Lab / radiology workflows from payment ticket "Proceed", templates, portal & doctor notices.
 */

/** PHP 7.x compatible substring check (str_contains is PHP 8+). */
function hms_str_contains(string $haystack, string $needle): bool
{
    return $needle !== '' && strpos($haystack, $needle) !== false;
}

/** Lowercase with UTF-8 when mbstring is available. */
function hms_result_workflow_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function hms_result_shared_notice_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_result_shared_notice');
}

function hms_lab_result_has_ticket_columns(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_lab_result', 'payment_ticket_code');
}

function hms_rad_result_has_ticket_columns(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_radiology_result', 'payment_ticket_code');
}

/**
 * Portal context for service-code-verify: laboratory | radiology | pharmacy | null
 */
function hms_service_verify_portal_normalize(?string $raw): ?string
{
    $s = strtolower(trim((string) $raw));
    if (in_array($s, ['laboratory', 'lab', 'laboratoire'], true)) {
        return 'laboratory';
    }
    if (in_array($s, ['radiology', 'rad', 'imaging'], true)) {
        return 'radiology';
    }
    if ($s === 'pharmacy') {
        return 'pharmacy';
    }

    return null;
}

function hms_service_verify_show_proceed(string $lineKind, ?string $portalNorm): bool
{
    $k = strtolower(trim($lineKind));
    if ($portalNorm === null) {
        return false;
    }
    if ($portalNorm === 'laboratory' && $k === 'laboratory') {
        return true;
    }
    if ($portalNorm === 'radiology' && $k === 'radiology') {
        return true;
    }
    if ($portalNorm === 'pharmacy' && $k === 'pharmacy') {
        return true;
    }

    return false;
}

function hms_consultation_referrer_employee_id(mysqli $connection, int $facilityId, int $consultationId): int
{
    if ($consultationId < 1 || !hms_workflow_table_ok($connection, 'tbl_consultation')) {
        return 0;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT created_by FROM tbl_consultation WHERE id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$st) {
        return 0;
    }
    mysqli_stmt_bind_param($st, 'ii', $consultationId, $facilityId);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return (int) ($r['created_by'] ?? 0);
}

function hms_lab_default_template_json(string $testName): string
{
    $t = hms_result_workflow_lower($testName);
    $rows = [];
    if (
        hms_str_contains($t, 'fbc')
        || hms_str_contains($t, 'full blood')
        || hms_str_contains($t, 'cbc')
        || hms_str_contains($t, 'numération')
    ) {
        $rows = [
            ['label' => 'Hemoglobin (g/dL)', 'value' => ''],
            ['label' => 'WBC (×10⁹/L)', 'value' => ''],
            ['label' => 'Platelets (×10⁹/L)', 'value' => ''],
            ['label' => 'Neutrophils (%)', 'value' => ''],
            ['label' => 'Lymphocytes (%)', 'value' => ''],
        ];
    } elseif (hms_str_contains($t, 'glucose') || hms_str_contains($t, 'glycémie') || hms_str_contains($t, 'glu')) {
        $rows = [
            ['label' => 'Glucose (mg/dL or mmol/L)', 'value' => ''],
            ['label' => 'Collection context (fasting / random)', 'value' => ''],
        ];
    } else {
        $rows = [
            ['label' => 'Key parameter 1', 'value' => ''],
            ['label' => 'Key parameter 2', 'value' => ''],
            ['label' => 'Key parameter 3', 'value' => ''],
        ];
    }
    $payload = [
        'rows' => $rows,
        'additional_notes' => '',
    ];
    $enc = json_encode($payload, JSON_UNESCAPED_UNICODE);

    return $enc !== false ? $enc : '{}';
}

function hms_radiology_default_template_json(): string
{
    $payload = [
        'clinical_indication' => '',
        'technique' => '',
        'comparison' => '',
        'findings_detail' => '',
        'impression' => '',
    ];
    $enc = json_encode($payload, JSON_UNESCAPED_UNICODE);

    return $enc !== false ? $enc : '{}';
}

/**
 * @return int existing lab_result id or 0
 */
function hms_lab_result_find_by_ticket_line(
    mysqli $connection,
    int $facilityId,
    string $ticketCode,
    int $lineIdx
): int {
    if (!hms_lab_result_has_ticket_columns($connection) || $ticketCode === '') {
        return 0;
    }
    $tc = mysqli_real_escape_string($connection, $ticketCode);
    $q = mysqli_query(
        $connection,
        'SELECT id FROM tbl_lab_result WHERE facility_id = ' . (int) $facilityId
        . " AND payment_ticket_code = '" . $tc . "' AND payment_ticket_line = " . (int) $lineIdx . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return (int) ($r['id'] ?? 0);
    }

    return 0;
}

/**
 * @return int existing radiology_result id or 0
 */
function hms_radiology_result_find_by_ticket_line(
    mysqli $connection,
    int $facilityId,
    string $ticketCode,
    int $lineIdx
): int {
    if (!hms_rad_result_has_ticket_columns($connection) || $ticketCode === '') {
        return 0;
    }
    $tc = mysqli_real_escape_string($connection, $ticketCode);
    $q = mysqli_query(
        $connection,
        'SELECT id FROM tbl_radiology_result WHERE facility_id = ' . (int) $facilityId
        . " AND payment_ticket_code = '" . $tc . "' AND payment_ticket_line = " . (int) $lineIdx . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return (int) ($r['id'] ?? 0);
    }

    return 0;
}

/**
 * Build human-readable summary from lab template JSON + conclusion.
 */
function hms_lab_template_summary_text(string $json, string $conclusionCode): string
{
    $pj = json_decode($json, true);
    $lines = [];
    if (is_array($pj) && isset($pj['rows']) && is_array($pj['rows'])) {
        foreach ($pj['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lb = trim((string) ($row['label'] ?? ''));
            $vl = trim((string) ($row['value'] ?? ''));
            if ($lb !== '' && $vl !== '') {
                $lines[] = $lb . ': ' . $vl;
            }
        }
        $extra = trim((string) ($pj['additional_notes'] ?? ''));
        if ($extra !== '') {
            $lines[] = 'Notes: ' . $extra;
        }
    }
    $cc = trim($conclusionCode);
    if ($cc !== '') {
        $lines[] = 'Conclusion: ' . $cc;
    }

    return implode("\n", $lines);
}

function hms_rad_template_summary_text(string $json, string $findings, string $conclusionCode): string
{
    $pj = json_decode($json, true);
    $parts = [];
    if (is_array($pj)) {
        foreach (['clinical_indication', 'technique', 'comparison', 'findings_detail', 'impression'] as $k) {
            $v = trim((string) ($pj[$k] ?? ''));
            if ($v !== '') {
                $parts[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
            }
        }
    }
    $f = trim($findings);
    if ($f !== '') {
        $parts[] = 'Report: ' . $f;
    }
    $cc = trim($conclusionCode);
    if ($cc !== '') {
        $parts[] = 'Conclusion: ' . $cc;
    }

    return implode("\n", $parts);
}

/**
 * @param array<string,mixed>|null $ticketRow
 */
function hms_result_workflow_send_emails(
    mysqli $connection,
    int $facilityId,
    string $testLabel,
    string $body,
    ?string $patientEmail,
    ?string $doctorEmail
): void {
    $subj = 'Test result: ' . $testLabel;
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    if ($patientEmail !== null && filter_var($patientEmail, FILTER_VALIDATE_EMAIL)) {
        @mail($patientEmail, $subj, $body, $headers);
    }
    if ($doctorEmail !== null && filter_var($doctorEmail, FILTER_VALIDATE_EMAIL)) {
        @mail($doctorEmail, '[HMS] ' . $subj, $body, $headers);
    }
}

/**
 * Insert portal + doctor notices and optional emails when a result is finalized.
 *
 * @return list<int> inserted notice ids
 */
function hms_result_workflow_publish_notices(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $doctorEmployeeId,
    ?int $labId,
    ?int $radId,
    string $ticketCode,
    string $testLabel,
    string $summary,
    string $conclusionCode
): array {
    $ids = [];
    if (!hms_result_shared_notice_table_ok($connection)) {
        return $ids;
    }
    try {
        $tcEsc = mysqli_real_escape_string($connection, $ticketCode);
        $tlEsc = mysqli_real_escape_string($connection, $testLabel);
        $smEsc = mysqli_real_escape_string($connection, $summary);
        $ccEsc = mysqli_real_escape_string($connection, $conclusionCode);
        $labSql = $labId !== null ? (string) (int) $labId : 'NULL';
        $radSql = $radId !== null ? (string) (int) $radId : 'NULL';

        foreach (['patient', 'doctor'] as $aud) {
            if ($aud === 'doctor' && $doctorEmployeeId < 1) {
                continue;
            }
            $docIns = ($aud === 'doctor' && $doctorEmployeeId > 0) ? (string) (int) $doctorEmployeeId : 'NULL';
            $audEsc = mysqli_real_escape_string($connection, $aud);
            $sql = 'INSERT INTO tbl_result_shared_notice (facility_id, audience, patient_id, doctor_employee_id, lab_result_id, radiology_result_id, payment_ticket_code, test_label, summary, conclusion_code) VALUES ('
                . (int) $facilityId . ", '" . $audEsc . "', " . (int) $patientId . ', ' . $docIns . ', ' . $labSql . ', ' . $radSql . ", '" . $tcEsc . "', '" . $tlEsc . "', '" . $smEsc . "', '" . $ccEsc . "')";
            if (mysqli_query($connection, $sql)) {
                $ids[] = (int) mysqli_insert_id($connection);
            }
        }

        $pEmail = null;
        $dEmail = null;
        if (hms_db_column_exists($connection, 'tbl_patient', 'email')) {
            $pq = mysqli_prepare($connection, 'SELECT email FROM tbl_patient WHERE id = ? LIMIT 1');
            if ($pq) {
                mysqli_stmt_bind_param($pq, 'i', $patientId);
                mysqli_stmt_execute($pq);
                $pr = hms_stmt_fetch_assoc($pq);
                mysqli_stmt_close($pq);
                $pEmail = $pr ? trim((string) ($pr['email'] ?? '')) : null;
            }
        }
        if ($doctorEmployeeId > 0 && hms_db_column_exists($connection, 'tbl_employee', 'email')) {
            $dq = mysqli_prepare($connection, 'SELECT email FROM tbl_employee WHERE id = ? LIMIT 1');
            if ($dq) {
                mysqli_stmt_bind_param($dq, 'i', $doctorEmployeeId);
                mysqli_stmt_execute($dq);
                $dr = hms_stmt_fetch_assoc($dq);
                mysqli_stmt_close($dq);
                $dEmail = $dr ? trim((string) ($dr['email'] ?? '')) : null;
            }
        }
        hms_result_workflow_send_emails($connection, $facilityId, $testLabel, $summary, $pEmail ?: null, $dEmail ?: null);
    } catch (\Throwable $e) {
        if (function_exists('error_log')) {
            error_log('hms_result_workflow_publish_notices: ' . $e->getMessage());
        }
    }

    return $ids;
}

/**
 * Create or reuse lab result row for a ticket line; returns lab result id or 0.
 *
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $ticketRow
 */
function hms_service_proceed_ensure_lab(
    mysqli $connection,
    int $facilityId,
    int $userId,
    string $ticketCode,
    int $lineIdx,
    array $line,
    ?array $ticketRow
): int {
    if (!hms_lab_result_table_ok($connection)) {
        return 0;
    }
    $existing = hms_lab_result_find_by_ticket_line($connection, $facilityId, $ticketCode, $lineIdx);
    if ($existing > 0) {
        return $existing;
    }
    $pid = (int) ($ticketRow['patient_id'] ?? 0);
    if ($pid < 1) {
        return 0;
    }
    $testName = trim((string) ($line['description'] ?? 'Laboratory test'));
    if ($testName === '') {
        $testName = 'Laboratory test';
    }
    $cid = (int) ($ticketRow['consultation_id'] ?? 0);
    $refId = hms_consultation_referrer_employee_id($connection, $facilityId, $cid);
    $appt = date('Y-m-d');
    $tpl = hms_lab_default_template_json($testName);
    $notes = 'Opened from payment code ' . $ticketCode . ' (line ' . $lineIdx . ').';

    if (!hms_lab_result_has_ticket_columns($connection)) {
        if ($refId > 0) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_lab_result (facility_id, patient_id, referred_by_id, test_name, appointment_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $stIn = 'in_progress';
                mysqli_stmt_bind_param($st, 'iiissssi', $facilityId, $pid, $refId, $testName, $appt, $stIn, $notes, $userId);
                mysqli_stmt_execute($st);
                $nid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);

                return $nid;
            }
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_lab_result (facility_id, patient_id, test_name, appointment_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?)'
            );
            if ($st) {
                $stIn = 'in_progress';
                mysqli_stmt_bind_param($st, 'iissssi', $facilityId, $pid, $testName, $appt, $stIn, $notes, $userId);
                mysqli_stmt_execute($st);
                $nid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);

                return $nid;
            }
        }

        return 0;
    }

    $tcEsc = mysqli_real_escape_string($connection, $ticketCode);
    $tnEsc = mysqli_real_escape_string($connection, $testName);
    $notesEsc = mysqli_real_escape_string($connection, $notes);
    $tplEsc = mysqli_real_escape_string($connection, $tpl);
    $refPart = $refId > 0 ? (int) $refId : 'NULL';
    $sql = 'INSERT INTO tbl_lab_result (facility_id, patient_id, payment_ticket_code, payment_ticket_line, referred_by_id, test_name, appointment_date, status, notes, created_by, result_template_json) VALUES ('
        . (int) $facilityId . ',' . (int) $pid . ", '" . $tcEsc . "', " . (int) $lineIdx . ', ' . $refPart . ", '" . $tnEsc . "', '" . mysqli_real_escape_string($connection, $appt) . "', 'in_progress', '" . $notesEsc . "', " . (int) $userId . ", '" . $tplEsc . "')";
    if (mysqli_query($connection, $sql)) {
        return (int) mysqli_insert_id($connection);
    }

    return 0;
}

/**
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $ticketRow
 */
function hms_service_proceed_ensure_radiology(
    mysqli $connection,
    int $facilityId,
    int $userId,
    string $ticketCode,
    int $lineIdx,
    array $line,
    ?array $ticketRow
): int {
    if (!hms_db_table_exists($connection, 'tbl_radiology_result')) {
        return 0;
    }
    $existing = hms_radiology_result_find_by_ticket_line($connection, $facilityId, $ticketCode, $lineIdx);
    if ($existing > 0) {
        return $existing;
    }
    $pid = (int) ($ticketRow['patient_id'] ?? 0);
    if ($pid < 1) {
        return 0;
    }
    $exam = trim((string) ($line['description'] ?? 'Imaging study'));
    if ($exam === '') {
        $exam = 'Imaging study';
    }
    $cid = (int) ($ticketRow['consultation_id'] ?? 0);
    $refId = hms_consultation_referrer_employee_id($connection, $facilityId, $cid);
    $appt = date('Y-m-d');
    $modality = 'Other';
    $exLower = hms_result_workflow_lower($exam);
    foreach (['ct' => 'CT Scan', 'mri' => 'MRI', 'x-ray' => 'X-Ray', 'ultrasound' => 'Ultrasound', 'ecg' => 'ECG'] as $needle => $mod) {
        if (hms_str_contains($exLower, $needle)) {
            $modality = $mod;
            break;
        }
    }
    $notes = 'Opened from payment code ' . $ticketCode . ' (line ' . $lineIdx . ').';
    $tpl = hms_radiology_default_template_json();

    if (!hms_rad_result_has_ticket_columns($connection)) {
        $bp = '';
        $stIn = 'in_progress';
        $find = '';
        if ($refId > 0) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_radiology_result (facility_id, patient_id, referred_by_id, exam_name, modality, body_part, appointment_date, status, findings, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param(
                    $st,
                    'iiisssssssi',
                    $facilityId,
                    $pid,
                    $refId,
                    $exam,
                    $modality,
                    $bp,
                    $appt,
                    $stIn,
                    $find,
                    $notes,
                    $userId
                );
                mysqli_stmt_execute($st);
                $nid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);

                return $nid;
            }
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_radiology_result (facility_id, patient_id, exam_name, modality, body_part, appointment_date, status, findings, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param(
                    $st,
                    'iisssssssi',
                    $facilityId,
                    $pid,
                    $exam,
                    $modality,
                    $bp,
                    $appt,
                    $stIn,
                    $find,
                    $notes,
                    $userId
                );
                mysqli_stmt_execute($st);
                $nid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);

                return $nid;
            }
        }

        return 0;
    }

    $tcEsc = mysqli_real_escape_string($connection, $ticketCode);
    $exEsc = mysqli_real_escape_string($connection, $exam);
    $notesEsc = mysqli_real_escape_string($connection, $notes);
    $tplEsc = mysqli_real_escape_string($connection, $tpl);
    $refPart = $refId > 0 ? (string) (int) $refId : 'NULL';
    $modEsc = mysqli_real_escape_string($connection, $modality);
    $sql = 'INSERT INTO tbl_radiology_result (facility_id, patient_id, payment_ticket_code, payment_ticket_line, referred_by_id, exam_name, modality, body_part, appointment_date, status, findings, notes, created_by, result_template_json) VALUES ('
        . (int) $facilityId . ',' . (int) $pid . ", '" . $tcEsc . "'," . (int) $lineIdx . ',' . $refPart . ", '" . $exEsc . "', '" . $modEsc . "', '', '" . mysqli_real_escape_string($connection, $appt) . "', 'in_progress', '', '" . $notesEsc . "', " . (int) $userId . ", '" . $tplEsc . "')";
    if (mysqli_query($connection, $sql)) {
        return (int) mysqli_insert_id($connection);
    }

    return 0;
}
