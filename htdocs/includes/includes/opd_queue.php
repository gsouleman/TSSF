<?php
declare(strict_types=1);

/**
 * @return list<string>
 */
function hms_opd_queue_statuses(): array
{
    return [
        'registered',
        'triage',
        'waiting_doctor',
        'in_consultation',
        'orders_pending',
        'billing',
        'completed',
    ];
}

function hms_opd_tables_ready(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_opd_visit');
}

function hms_opd_next_ticket_number(mysqli $connection, int $facilityId, ?string $visitDateYmd = null): string
{
    $vd = $visitDateYmd ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vd)) {
        $vd = date('Y-m-d');
    }
    $day = str_replace('-', '', $vd);
    $prefix = 'OPD-' . $day . '-';
    $escPrefix = mysqli_real_escape_string($connection, $prefix);
    $fid = (int) $facilityId;

    // Search by ticket prefix only (not visit_date) — the UNIQUE KEY is on (facility_id, ticket_number)
    $q = mysqli_query(
        $connection,
        "SELECT MAX(CAST(SUBSTRING(ticket_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)) AS mx FROM tbl_opd_visit WHERE facility_id = " . $fid . " AND ticket_number LIKE '" . $escPrefix . "%'"
    );
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $n = (int) ($row['mx'] ?? 0) + 1;

    // Safety: verify the candidate ticket doesn't already exist.
    // Handles stale query-cache results on shared/free hosting that can cause
    // the MAX() above to return an outdated value.
    $candidate = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    $checkSt = mysqli_prepare(
        $connection,
        'SELECT 1 FROM tbl_opd_visit WHERE facility_id = ? AND ticket_number = ? LIMIT 1'
    );
    if ($checkSt) {
        for ($safety = 0; $safety < 50; $safety++) {
            mysqli_stmt_bind_param($checkSt, 'is', $fid, $candidate);
            mysqli_stmt_execute($checkSt);
            $exists = (bool) hms_stmt_fetch_assoc($checkSt);
            if (!$exists) {
                break;
            }
            $n++;
            $candidate = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        }
        mysqli_stmt_close($checkSt);
    }

    return $candidate;
}

function hms_opd_next_status(string $current): ?string
{
    $list = hms_opd_queue_statuses();
    $i = array_search($current, $list, true);
    if ($i === false || $i >= count($list) - 1) {
        return null;
    }

    return $list[$i + 1];
}

function hms_opd_status_label(string $code): string
{
    $map = [
        'registered' => 'Registered',
        'triage' => 'Triage',
        'waiting_doctor' => 'Waiting (doctor)',
        'in_consultation' => 'In consultation',
        'orders_pending' => 'Orders / investigations',
        'billing' => 'Billing / cashier',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    return $map[$code] ?? $code;
}

/**
 * When an OPD visit is marked completed after the patient was already admitted, set opd_visit_id on the
 * open admission row if it was still NULL (e.g. admission created without the OPD URL pre-fill).
 *
 * @return bool True if an admission row was updated
 */
function hms_opd_link_visit_to_open_admission(mysqli $connection, int $facilityId, int $patientId, int $visitId): bool
{
    if (!hms_db_table_exists($connection, 'tbl_admission')
        || !hms_db_column_exists($connection, 'tbl_admission', 'opd_visit_id')) {
        return false;
    }
    $st = mysqli_prepare(
        $connection,
        'UPDATE tbl_admission SET opd_visit_id = ? WHERE facility_id = ? AND patient_id = ? AND discharged_at IS NULL AND opd_visit_id IS NULL LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'iii', $visitId, $facilityId, $patientId);
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);

    return $n > 0;
}

/* ---- Visits registry UI (visits.php) — keep here so bootstrap always loads helpers (avoids missing-file 500 on deploy) ---- */

function hms_visit_registry_has_doctor_column(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_opd_visit', 'assigned_doctor_id');
}

function hms_visit_registry_has_treatment_column(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_opd_visit', 'treatment_note');
}

function hms_visit_registry_has_payment_column(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_opd_visit', 'payment_mode');
}

function hms_visit_display_id(int $visitId): string
{
    return '#VS' . str_pad((string) $visitId, 4, '0', STR_PAD_LEFT);
}

/**
 * @return array{label: string, pill: string}
 */
function hms_visit_dreams_status_pill(string $queueStatus): array
{
    if ($queueStatus === 'completed') {
        return ['label' => 'Completed', 'pill' => 'hms-visit-pill--completed'];
    }
    if ($queueStatus === 'cancelled') {
        return ['label' => 'Cancelled', 'pill' => 'hms-visit-pill--cancelled'];
    }
    if (in_array($queueStatus, ['registered', 'triage'], true)) {
        return ['label' => 'Pending', 'pill' => 'hms-visit-pill--pending'];
    }

    return ['label' => 'Inprogress', 'pill' => 'hms-visit-pill--inprogress'];
}

function hms_visit_patient_initials(string $first, string $last): string
{
    $a = strtoupper(substr(trim($first), 0, 1));
    $b = strtoupper(substr(trim($last), 0, 1));

    return ($a . $b) !== '' ? ($a . $b) : '?';
}

/**
 * @param array<string, mixed> $doctorRow tbl_employee row or empty
 */
function hms_visit_doctor_display_name(array $doctorRow): string
{
    $fn = trim((string) ($doctorRow['first_name'] ?? ''));
    $ln = trim((string) ($doctorRow['last_name'] ?? ''));
    if ($fn === '' && $ln === '') {
        return "\xe2\x80\x94";
    }

    return 'Dr. ' . trim($fn . ' ' . $ln);
}

function hms_visit_department_fa_icon(string $deptName): string
{
    $d = strtolower($deptName);
    if (strpos($d, 'neuro') !== false) {
        return 'fa-stethoscope';
    }
    if (strpos($d, 'dental') !== false || strpos($d, 'tooth') !== false) {
        return 'fa-smile-o';
    }
    if (strpos($d, 'cardio') !== false || strpos($d, 'heart') !== false) {
        return 'fa-heartbeat';
    }
    if (strpos($d, 'ortho') !== false) {
        return 'fa-wheelchair';
    }
    if (strpos($d, 'paed') !== false || strpos($d, 'pediat') !== false) {
        return 'fa-child';
    }

    return 'fa-hospital-o';
}

function hms_visit_format_date_dreams(string $ymd): string
{
    $ts = strtotime(str_replace('/', '-', $ymd));
    if ($ts === false) {
        return $ymd;
    }

    return date('j M Y', $ts);
}
