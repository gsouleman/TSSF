<?php
declare(strict_types=1);

require_once __DIR__ . '/facility.php';

function hms_adt_tables_ready(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_bed')
        && hms_db_table_exists($connection, 'tbl_admission');
}

function hms_adt_badge_class(string $status): string
{
    switch (strtolower($status)) {
        case 'available':
            return 'badge-success';
        case 'occupied':
            return 'badge-primary';
        case 'housekeeping':
            return 'badge-warning text-dark';
        case 'blocked':
            return 'badge-secondary';
        default:
            return 'badge-light text-dark';
    }
}

/**
 * @return list<array<string, mixed>>
 */
function hms_adt_fetch_beds_with_occupancy(mysqli $connection, int $facilityId): array
{
    $sql = 'SELECT b.id AS bed_id, b.ward_name, b.bed_label, b.status AS bed_status,
        a.id AS admission_id, a.patient_id, a.admitted_at, a.admission_status,
        p.first_name, p.last_name
        FROM tbl_bed b
        LEFT JOIN tbl_admission a ON a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL
        LEFT JOIN tbl_patient p ON p.id = a.patient_id
        WHERE b.facility_id = ' . (int) $facilityId . '
        ORDER BY b.ward_name, b.bed_label';
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, list<array<string, mixed>>>
 */
function hms_adt_group_beds_by_ward(array $rows): array
{
    $g = [];
    foreach ($rows as $row) {
        $w = (string) ($row['ward_name'] ?? '');
        if (!isset($g[$w])) {
            $g[$w] = [];
        }
        $g[$w][] = $row;
    }

    return $g;
}

function hms_adt_open_admission_count_for_patient(mysqli $connection, int $facilityId, int $patientId): int
{
    $stmt = mysqli_prepare(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_admission WHERE facility_id = ? AND patient_id = ? AND discharged_at IS NULL'
    );
    if (!$stmt) {
        return 999;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $facilityId, $patientId);
    mysqli_stmt_execute($stmt);
    $row = hms_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);

    return (int) ($row['c'] ?? 0);
}

/**
 * @return list<array<string, mixed>>
 */
function hms_adt_active_admissions(mysqli $connection, int $facilityId): array
{
    // Only extend the SELECT when both columns exist (avoids SQL errors on partial migrations).
    $docCols = hms_db_column_exists($connection, 'tbl_admission', 'admitting_diagnosis')
        && hms_db_column_exists($connection, 'tbl_admission', 'admitted_from')
        ? ', a.admitting_diagnosis, a.admitted_from'
        : '';
    $sql = 'SELECT a.id AS admission_id, a.bed_id, a.admitted_at, a.admission_status, a.patient_id' . $docCols . ',
        b.ward_name, b.bed_label, p.first_name, p.last_name
        FROM tbl_admission a
        INNER JOIN tbl_patient p ON p.id = a.patient_id
        LEFT JOIN tbl_bed b ON b.id = a.bed_id
        WHERE a.facility_id = ' . (int) $facilityId . ' AND a.discharged_at IS NULL
        ORDER BY a.admitted_at DESC';
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $out[] = $row;
    }

    return $out;
}

/**
 * Patients eligible for a new inpatient bed (no open admission at this facility).
 *
 * @return list<array{id: int, first_name: string, last_name: string}>
 */
function hms_adt_patients_eligible_for_admission(mysqli $connection, int $facilityId, bool $multiSite): array
{
    if ($multiSite) {
        $sql = 'SELECT p.id, p.first_name, p.last_name FROM tbl_patient p
            WHERE p.facility_id = ' . (int) $facilityId . ' AND p.status = 1
            AND NOT EXISTS (
                SELECT 1 FROM tbl_admission a
                WHERE a.patient_id = p.id AND a.facility_id = ' . (int) $facilityId . ' AND a.discharged_at IS NULL
            )
            ORDER BY p.last_name, p.first_name';
    } else {
        $sql = 'SELECT p.id, p.first_name, p.last_name FROM tbl_patient p
            WHERE p.status = 1
            AND NOT EXISTS (
                SELECT 1 FROM tbl_admission a
                WHERE a.patient_id = p.id AND a.discharged_at IS NULL
            )
            ORDER BY p.last_name, p.first_name';
    }
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $out[] = [
            'id' => (int) $row['id'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
        ];
    }

    return $out;
}

/**
 * Beds that can receive a patient (available, no open admission on that row).
 *
 * @return list<array{bed_id: int, ward_name: string, bed_label: string}>
 */
function hms_adt_available_beds(mysqli $connection, int $facilityId): array
{
    $sql = 'SELECT b.id AS bed_id, b.ward_name, b.bed_label FROM tbl_bed b
        WHERE b.facility_id = ' . (int) $facilityId . " AND b.status = 'available'
        AND NOT EXISTS (
            SELECT 1 FROM tbl_admission a
            WHERE a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL
        )
        ORDER BY b.ward_name, b.bed_label";
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $out[] = [
            'bed_id' => (int) $row['bed_id'],
            'ward_name' => (string) $row['ward_name'],
            'bed_label' => (string) $row['bed_label'],
        ];
    }

    return $out;
}
