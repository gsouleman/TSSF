<?php
declare(strict_types=1);

/**
 * Facility admission (patient on site) — anchors billing documents when migration 018 is applied.
 */

function hms_facility_admission_tables_ok(mysqli $connection): bool
{
    return function_exists('hms_db_table_exists') && hms_db_table_exists($connection, 'tbl_facility_admission');
}

/**
 * Close an open facility admission row (e.g. after inpatient discharge).
 */
function hms_facility_admission_close_by_id(mysqli $connection, int $faId): bool
{
    if ($faId < 1 || !hms_facility_admission_tables_ok($connection)) {
        return false;
    }
    $st = mysqli_prepare(
        $connection,
        'UPDATE tbl_facility_admission SET closed_at = NOW() WHERE id = ? AND closed_at IS NULL LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'i', $faId);
    $ok = mysqli_stmt_execute($st) && mysqli_stmt_affected_rows($st) > 0;
    mysqli_stmt_close($st);

    return $ok;
}

/**
 * Return facility_admission_id linked to an OPD visit, if the column exists.
 */
function hms_facility_admission_id_for_opd_visit(mysqli $connection, int $opdVisitId): int
{
    if ($opdVisitId < 1 || !hms_db_column_exists($connection, 'tbl_opd_visit', 'facility_admission_id')) {
        return 0;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT facility_admission_id FROM tbl_opd_visit WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return 0;
    }
    mysqli_stmt_bind_param($st, 'i', $opdVisitId);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return (int) ($row['facility_admission_id'] ?? 0);
}

/**
 * Find an open admission for this patient at this facility, or insert a walk-in row.
 * Used as a billing episode anchor when no OPD visit / hospitalization id is available.
 */
function hms_facility_admission_ensure_walkin_open(mysqli $connection, int $facilityId, int $patientId, int $userId): int
{
    if ($facilityId < 1 || $patientId < 1 || !hms_facility_admission_tables_ok($connection)) {
        return 0;
    }

    $st = mysqli_prepare(
        $connection,
        'SELECT id FROM tbl_facility_admission
         WHERE facility_id = ? AND patient_id = ? AND closed_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    if ($st) {
        mysqli_stmt_bind_param($st, 'ii', $facilityId, $patientId);
        mysqli_stmt_execute($st);
        $row = hms_stmt_fetch_assoc($st);
        mysqli_stmt_close($st);
        if ($row && isset($row['id'])) {
            return (int) $row['id'];
        }
    }

    if ($userId > 0) {
        $ins = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_facility_admission (facility_id, patient_id, arrival_at, created_by) VALUES (?,?,NOW(),?)'
        );
        if (!$ins) {
            return 0;
        }
        mysqli_stmt_bind_param($ins, 'iii', $facilityId, $patientId, $userId);
    } else {
        $ins = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_facility_admission (facility_id, patient_id, arrival_at, created_by) VALUES (?,?,NOW(),NULL)'
        );
        if (!$ins) {
            return 0;
        }
        mysqli_stmt_bind_param($ins, 'ii', $facilityId, $patientId);
    }
    $ok = mysqli_stmt_execute($ins);
    $newId = $ok ? (int) mysqli_insert_id($connection) : 0;
    mysqli_stmt_close($ins);

    return $newId > 0 ? $newId : 0;
}

/**
 * When an OPD visit row is created, link it to an open facility admission (reuse walk-in / cashier anchor if present).
 */
function hms_opd_visit_attach_facility_admission_after_insert(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $userId,
    int $opdVisitId,
    string $_arrivalNote
): void {
    if ($facilityId < 1 || $patientId < 1 || $opdVisitId < 1) {
        return;
    }
    if (!hms_db_column_exists($connection, 'tbl_opd_visit', 'facility_admission_id')) {
        return;
    }
    if (!function_exists('hms_facility_admission_ensure_walkin_open') || !hms_facility_admission_tables_ok($connection)) {
        return;
    }
    $faId = hms_facility_admission_ensure_walkin_open($connection, $facilityId, $patientId, $userId);
    if ($faId < 1) {
        return;
    }
    $up = mysqli_prepare(
        $connection,
        'UPDATE tbl_opd_visit SET facility_admission_id = ? WHERE id = ? AND facility_id = ?
         AND (facility_admission_id IS NULL OR facility_admission_id = 0) LIMIT 1'
    );
    if ($up) {
        mysqli_stmt_bind_param($up, 'iii', $faId, $opdVisitId, $facilityId);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }
}

/**
 * After an OPD visit is completed or cancelled, close the linked facility admission (or the patient's open walk-in).
 */
function hms_facility_admission_after_opd_terminal(mysqli $connection, int $facilityId, int $opdVisitId, int $patientId): void
{
    if ($facilityId < 1 || $opdVisitId < 1 || $patientId < 1 || !hms_facility_admission_tables_ok($connection)) {
        return;
    }
    $faId = 0;
    if (hms_db_column_exists($connection, 'tbl_opd_visit', 'facility_admission_id')) {
        $st = mysqli_prepare(
            $connection,
            'SELECT facility_admission_id FROM tbl_opd_visit WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $opdVisitId, $facilityId);
            mysqli_stmt_execute($st);
            $row = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
            $faId = (int) ($row['facility_admission_id'] ?? 0);
        }
    }
    if ($faId < 1) {
        $st = mysqli_prepare(
            $connection,
            'SELECT id FROM tbl_facility_admission
             WHERE facility_id = ? AND patient_id = ? AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $facilityId, $patientId);
            mysqli_stmt_execute($st);
            $row = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
            $faId = (int) ($row['id'] ?? 0);
        }
    }
    if ($faId > 0) {
        hms_facility_admission_close_by_id($connection, $faId);
    }
}
