<?php
declare(strict_types=1);

/**
 * Department names for the current facility (tbl_department.department_name).
 *
 * @return list<string>
 */
function hms_employee_department_names_for_facility(mysqli $connection, int $facilityId, bool $multiSite): array
{
    $out = [];
    $dsuf = $multiSite ? ' WHERE facility_id = ' . (int) $facilityId . ' AND status = 1' : ' WHERE status = 1';
    $dq = mysqli_query($connection, 'SELECT department_name FROM tbl_department' . $dsuf . ' ORDER BY department_name');
    while ($dq && $dr = mysqli_fetch_assoc($dq)) {
        $out[] = (string) $dr['department_name'];
    }

    return $out;
}

/**
 * Pick the best-matching department name whose full label appears in the biography (case-insensitive).
 * Longest match wins so e.g. "Emergency / A&E" beats "Emergency".
 */
function hms_employee_guess_department_from_bio(mysqli $connection, int $facilityId, bool $multiSite, string $bio): string
{
    $bio = trim($bio);
    if ($bio === '') {
        return '';
    }
    $names = hms_employee_department_names_for_facility($connection, $facilityId, $multiSite);
    $best = '';
    $bestLen = 0;
    foreach ($names as $d) {
        $d = trim($d);
        if ($d === '') {
            continue;
        }
        if (function_exists('mb_stripos')) {
            if (mb_stripos($bio, $d, 0, 'UTF-8') !== false) {
                $len = (int) mb_strlen($d, 'UTF-8');
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $d;
                }
            }
        } elseif (stripos($bio, $d) !== false) {
            $len = strlen($d);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $d;
            }
        }
    }

    return $best;
}

/**
 * One random active department name for a facility (or any active department when not multi-site).
 */
function hms_employee_pick_random_department_name(mysqli $connection, int $facilityId, bool $multiSite): ?string
{
    $names = hms_employee_department_names_for_facility($connection, $facilityId, $multiSite);
    if ($names === []) {
        return null;
    }

    return $names[array_rand($names)];
}

/**
 * Assign random primary_department to Doctors (2), Nurses (7), and Nursing Aids (8) who have none.
 * Uses department catalog for the given facility scope (current site when run from the UI).
 *
 * @return array{updated: int, skipped: int, messages: list<string>}
 */
function hms_backfill_random_primary_departments(mysqli $connection, int $scopeFacilityId, bool $multiSite): array
{
    if (!hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
        return [
            'updated' => 0,
            'skipped' => 0,
            'messages' => ['Add column primary_department first (migration 028).'],
        ];
    }

    $updated = 0;
    $skipped = 0;
    $messages = [];

    if ($multiSite && hms_db_table_exists($connection, 'tbl_user_facility')) {
        $sql = 'SELECT e.id FROM tbl_employee e
            INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id AND uf.facility_id = ' . (int) $scopeFacilityId . '
            WHERE e.role IN (2, 7, 8) AND (e.primary_department IS NULL OR TRIM(e.primary_department) = \'\')';
    } else {
        $sql = 'SELECT id FROM tbl_employee WHERE role IN (2, 7, 8) AND (primary_department IS NULL OR TRIM(primary_department) = \'\')';
    }

    $q = mysqli_query($connection, $sql);
    $ids = [];
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $ids[] = (int) $r['id'];
    }

    $deptFid = max(1, $scopeFacilityId);

    foreach ($ids as $empId) {
        if ($empId < 1) {
            continue;
        }
        $pick = hms_employee_pick_random_department_name($connection, $deptFid, $multiSite);
        if ($pick === null || $pick === '') {
            $skipped++;
            continue;
        }
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_employee SET primary_department = ? WHERE id = ? AND role IN (2, 7, 8) AND (primary_department IS NULL OR TRIM(primary_department) = \'\')'
        );
        if (!$st) {
            $messages[] = 'Prepare failed for employee #' . $empId;
            continue;
        }
        mysqli_stmt_bind_param($st, 'si', $pick, $empId);
        if (mysqli_stmt_execute($st) && mysqli_stmt_affected_rows($st) > 0) {
            $updated++;
            if (function_exists('hms_audit_log')) {
                hms_audit_log($connection, 'employee.department.random_fill', 'employee', $empId, ['department' => $pick]);
            }
        }
        mysqli_stmt_close($st);
    }

    if ($updated === 0 && $skipped === 0 && $ids === []) {
        $messages[] = 'No doctors, nurses, or nursing aids without a department were found for this site.';
    } elseif ($skipped > 0 && $messages === []) {
        $messages[] = $skipped . ' row(s) skipped (no departments in catalog for this site — add departments under Departments).';
    }

    return ['updated' => $updated, 'skipped' => $skipped, 'messages' => $messages];
}

/**
 * @return array{pending: int}
 */
function hms_backfill_random_primary_departments_pending_count(mysqli $connection, int $scopeFacilityId, bool $multiSite): int
{
    if (!hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
        return 0;
    }
    if ($multiSite && hms_db_table_exists($connection, 'tbl_user_facility')) {
        $sql = 'SELECT COUNT(*) AS c FROM tbl_employee e
            INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id AND uf.facility_id = ' . (int) $scopeFacilityId . '
            WHERE e.role IN (2, 7, 8) AND (e.primary_department IS NULL OR TRIM(e.primary_department) = \'\')';
    } else {
        $sql = 'SELECT COUNT(*) AS c FROM tbl_employee WHERE role IN (2, 7, 8) AND (primary_department IS NULL OR TRIM(primary_department) = \'\')';
    }
    $q = mysqli_query($connection, $sql);
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return (int) ($r['c'] ?? 0);
    }

    return 0;
}
