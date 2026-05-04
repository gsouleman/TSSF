<?php
declare(strict_types=1);

function hms_workflow_table_ok(mysqli $connection, string $table): bool
{
    return hms_db_table_exists($connection, $table);
}

/** @return list<array<string, mixed>> */
function hms_lab_catalog_rows(mysqli $connection): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_lab_catalog')) {
        return [];
    }
    $rows = [];
    $q = mysqli_query($connection, 'SELECT id, code, name, category, specimen_hint FROM tbl_lab_catalog WHERE active = 1 ORDER BY sort_order, name');
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
    return $rows;
}

/** @return list<array<string, mixed>> */
function hms_consult_param_defs(mysqli $connection, int $facilityId): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_consult_param_def')) {
        return [];
    }
    $rows = [];
    $st = mysqli_prepare(
        $connection,
        'SELECT param_code, label, field_type, options_csv, unit FROM tbl_consult_param_def WHERE facility_id = ? AND active = 1 ORDER BY sort_order, id'
    );
    if (!$st) {
        return [];
    }
    mysqli_stmt_bind_param($st, 'i', $facilityId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($res && $r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($st);
    return $rows;
}

function hms_lab_order_type_for_category(string $category): string
{
    return (stripos($category, 'imagerie') !== false || stripos($category, 'imaging') !== false) ? 'imaging' : 'lab';
}

/**
 * True when the inventory row exists and belongs to the current facility (stock must not be decremented cross-site).
 */
function hms_inventory_item_in_facility(mysqli $connection, int $inventoryItemId, int $facilityId): bool
{
    if ($inventoryItemId < 1 || !hms_workflow_table_ok($connection, 'tbl_inventory_item')) {
        return false;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id FROM tbl_inventory_item WHERE id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'ii', $inventoryItemId, $facilityId);
    mysqli_stmt_execute($st);
    $ok = (bool) hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    return $ok;
}

function hms_next_appointment_display_id(mysqli $connection, int $facilityId, bool $multiSite): string
{
    if ($multiSite) {
        $r = mysqli_fetch_row(mysqli_query($connection, 'SELECT MAX(id) FROM tbl_appointment WHERE facility_id = ' . (int) $facilityId));
    } else {
        $r = mysqli_fetch_row(mysqli_query($connection, 'SELECT MAX(id) FROM tbl_appointment'));
    }
    $n = (int) ($r[0] ?? 0) + 1;
    return 'APT-' . $n;
}

/**
 * Department hint for UI: tbl_patient.department when present, else latest tbl_appointment.department for that patient.
 */
function hms_patient_department_hint(mysqli $connection, int $patientId, int $facilityId, bool $multiSite): string
{
    if ($patientId < 1) {
        return '';
    }
    if (hms_db_column_exists($connection, 'tbl_patient', 'department')) {
        $st = mysqli_prepare($connection, 'SELECT department AS d FROM tbl_patient WHERE id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'i', $patientId);
            mysqli_stmt_execute($st);
            $row = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
            $d = trim((string) ($row['d'] ?? ''));
            if ($d !== '') {
                return $d;
            }
        }
    }
    if (!hms_db_column_exists($connection, 'tbl_appointment', 'patient_id')) {
        return '';
    }
    $hasApptFac = hms_db_column_exists($connection, 'tbl_appointment', 'facility_id');
    if ($multiSite && $hasApptFac) {
        $st = mysqli_prepare(
            $connection,
            'SELECT department FROM tbl_appointment WHERE patient_id = ? AND facility_id = ? ORDER BY id DESC LIMIT 1'
        );
        if (!$st) {
            return '';
        }
        mysqli_stmt_bind_param($st, 'ii', $patientId, $facilityId);
    } else {
        $st = mysqli_prepare($connection, 'SELECT department FROM tbl_appointment WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
        if (!$st) {
            return '';
        }
        mysqli_stmt_bind_param($st, 'i', $patientId);
    }
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    $d = trim((string) ($row['department'] ?? ''));

    return $d;
}
