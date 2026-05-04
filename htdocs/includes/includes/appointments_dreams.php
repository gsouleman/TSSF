<?php
declare(strict_types=1);

/**
 * Dreams-style appointments UI helpers (list + calendar).
 */

/**
 * Parse appointment row date string to Y-m-d or null.
 */
function hms_appt_parse_date_ymd(string $dateRaw): ?string
{
    $d = trim($dateRaw);
    if ($d === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($d), $m)) {
        $day = (int) $m[1];
        $mon = (int) $m[2];
        $yr = (int) $m[3];
        if ($day >= 1 && $day <= 31 && $mon >= 1 && $mon <= 12) {
            return sprintf('%04d-%02d-%02d', $yr, $mon, $day);
        }
    }

    $ts = strtotime(str_replace('/', '-', $d));

    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * @return array{code: string, label: string, pill: string}
 */
function hms_appt_dreams_status(array $row): array
{
    if ((int) ($row['status'] ?? 0) !== 1) {
        return ['code' => 'inactive', 'label' => 'Inactive', 'pill' => 'hms-appt-pill--inactive'];
    }
    $ymd = hms_appt_parse_date_ymd((string) ($row['date'] ?? ''));
    if ($ymd === null) {
        return ['code' => 'upcoming', 'label' => 'Upcoming', 'pill' => 'hms-appt-pill--upcoming'];
    }
    $today = date('Y-m-d');
    if ($ymd > $today) {
        return ['code' => 'upcoming', 'label' => 'Upcoming', 'pill' => 'hms-appt-pill--upcoming'];
    }
    if ($ymd < $today) {
        return ['code' => 'completed', 'label' => 'Completed', 'pill' => 'hms-appt-pill--completed'];
    }

    return ['code' => 'inprogress', 'label' => 'Inprogress', 'pill' => 'hms-appt-pill--inprogress'];
}

function hms_appt_patient_display_name(array $row): string
{
    $fn = trim((string) ($row['p_fn'] ?? ''));
    $ln = trim((string) ($row['p_ln'] ?? ''));
    if ($fn !== '' || $ln !== '') {
        return trim($fn . ' ' . $ln);
    }
    $raw = (string) ($row['patient_name'] ?? '');
    $parts = explode(',', $raw, 2);

    return trim((string) ($parts[0] ?? $raw));
}

function hms_appt_patient_initials(array $row): string
{
    $fn = trim((string) ($row['p_fn'] ?? ''));
    $ln = trim((string) ($row['p_ln'] ?? ''));
    if ($fn === '' && $ln === '') {
        $name = hms_appt_patient_display_name($row);
        $bits = preg_split('/\s+/', $name) ?: [];
        $a = strtoupper(substr((string) ($bits[0] ?? ''), 0, 1));
        $b = strtoupper(substr((string) ($bits[1] ?? ''), 0, 1));

        return ($a . $b) !== '' ? ($a . $b) : '?';
    }

    return hms_visit_patient_initials($fn, $ln);
}

function hms_appt_patient_display_id(array $row): string
{
    $pid = (int) ($row['patient_id'] ?? 0);
    if ($pid > 0) {
        return '#PT' . str_pad((string) $pid, 4, '0', STR_PAD_LEFT);
    }

    return '#AP' . str_pad((string) ($row['id'] ?? 0), 4, '0', STR_PAD_LEFT);
}

/**
 * Format time range label (start from DB + 1h default end).
 */
function hms_appt_format_time_range(string $timeRaw): string
{
    $t = trim($timeRaw);
    if ($t === '') {
        return '—';
    }
    $ts = strtotime($t);
    if ($ts !== false) {
        $start = date('h:i A', $ts);
        $end = date('h:i A', $ts + 3600);

        return $start . ' to ' . $end;
    }

    return $t;
}

/**
 * @return list<string>
 */
function hms_appt_payment_mode_options(): array
{
    return [
        '',
        'Cash',
        'Card',
        'Insurance',
        'Corporate',
        'MTN Mobile Money (MoMo)',
        'Orange Mobile Money (OM)',
    ];
}

/**
 * Promote all pending appointment rows (status=0) for a patient so they no longer appear on Requests.
 * Used when a consultation or OPD visit is started for that patient. Rows without patient_id are unchanged.
 */
/**
 * SQL boolean expression (for WHERE): exclude rows in the same "Completed" bucket as the Appointments list
 * (status = 1 with a parsed appointment date strictly before today).
 *
 * @param string $alias Table alias for tbl_appointment (e.g. "a")
 */
function hms_appt_sql_exclude_completed_bucket(string $alias = 'a'): string
{
    $d = preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) ? $alias : 'a';
    $dateNorm = "DATE(COALESCE(STR_TO_DATE(NULLIF(TRIM({$d}.date),''), '%d/%m/%Y'), STR_TO_DATE(NULLIF(TRIM({$d}.date),''), '%Y-%m-%d')))";

    return "({$d}.status <> 1 OR ({$dateNorm}) IS NULL OR ({$dateNorm}) >= CURDATE())";
}

function hms_appointment_clear_requests_for_patient(mysqli $connection, int $patientId, int $facilityId): void
{
    if ($patientId < 1 || !hms_db_table_exists($connection, 'tbl_appointment')) {
        return;
    }
    if (!hms_db_column_exists($connection, 'tbl_appointment', 'patient_id')) {
        return;
    }
    $ms = hms_multi_site_enabled($connection);
    $hasFac = hms_db_column_exists($connection, 'tbl_appointment', 'facility_id');
    if ($ms && $hasFac) {
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_appointment SET status = 1 WHERE patient_id = ? AND facility_id = ? AND status = 0'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $patientId, $facilityId);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    } else {
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_appointment SET status = 1 WHERE patient_id = ? AND status = 0'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'i', $patientId);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
}
