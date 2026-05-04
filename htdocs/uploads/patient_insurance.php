<?php
declare(strict_types=1);

/**
 * Patient insurance coverage (primary policy).
 * Insurer share is a whole percent 0–100 of the listed price; patient pays the remainder (any split: 70/30, 50/50, 20/80, 100/0, etc.).
 */

function hms_patient_insurance_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_patient_insurance')
        && hms_db_column_exists($connection, 'tbl_patient_insurance', 'insurer_covered_percent');
}

/**
 * Insurer-covered portion of listed price (0–100). 100 = patient pays nothing at cashier for insured lines.
 */
function hms_patient_insurer_covered_percent(mysqli $connection, int $patientId, int $facilityId): int
{
    if ($patientId < 1 || !hms_patient_insurance_tables_ok($connection)) {
        return 0;
    }
    $today = date('Y-m-d');
    $st = mysqli_prepare(
        $connection,
        'SELECT insurer_covered_percent FROM tbl_patient_insurance
         WHERE patient_id = ? AND facility_id = ? AND is_primary = 1
         AND (effective_to IS NULL OR effective_to >= ?)
         AND (effective_from IS NULL OR effective_from <= ?)
         ORDER BY id DESC LIMIT 1'
    );
    if (!$st) {
        return 0;
    }
    mysqli_stmt_bind_param($st, 'iiss', $patientId, $facilityId, $today, $today);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$r) {
        return 0;
    }
    $p = (int) ($r['insurer_covered_percent'] ?? 0);

    return max(0, min(100, $p));
}

/**
 * Patient responsibility share at POS: 100 - insurer covered %.
 */
function hms_patient_copay_percent_at_pos(int $insurerCoveredPercent): int
{
    $c = max(0, min(100, $insurerCoveredPercent));

    return max(0, 100 - $c);
}

/**
 * Apply insurer coverage to a listed unit price (FCFA). Returns patient amount to collect.
 */
function hms_patient_amount_after_insurance(int $listPriceXaf, int $insurerCoveredPercent): int
{
    if ($listPriceXaf < 1) {
        return 0;
    }
    $cov = max(0, min(100, $insurerCoveredPercent));
    $patientPct = 100 - $cov;

    return (int) max(0, round($listPriceXaf * $patientPct / 100));
}

/**
 * Data for cashier UI: primary policy split (requires migration 025 column on tbl_patient_insurance).
 *
 * @return array{
 *   migration_ok: bool,
 *   has_primary_policy: bool,
 *   carrier_name: string,
 *   insurer_covered_percent: int,
 *   patient_copay_percent: int
 * }
 */
function hms_patient_insurance_cashier_hint(mysqli $connection, int $patientId, int $facilityId): array
{
    $out = [
        'migration_ok' => hms_patient_insurance_tables_ok($connection),
        'has_primary_policy' => false,
        'carrier_name' => '',
        'insurer_covered_percent' => 0,
        'patient_copay_percent' => 100,
    ];
    if ($patientId < 1 || $facilityId < 1) {
        return $out;
    }
    if (!$out['migration_ok']) {
        return $out;
    }
    $today = date('Y-m-d');
    $st = mysqli_prepare(
        $connection,
        'SELECT pi.insurer_covered_percent, ic.name AS carrier_name
         FROM tbl_patient_insurance pi
         INNER JOIN tbl_insurance_carrier ic ON ic.id = pi.carrier_id
         WHERE pi.patient_id = ? AND pi.facility_id = ? AND pi.is_primary = 1
         AND (pi.effective_to IS NULL OR pi.effective_to >= ?)
         AND (pi.effective_from IS NULL OR pi.effective_from <= ?)
         ORDER BY pi.id DESC LIMIT 1'
    );
    if (!$st) {
        return $out;
    }
    mysqli_stmt_bind_param($st, 'iiss', $patientId, $facilityId, $today, $today);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$r) {
        return $out;
    }
    $out['has_primary_policy'] = true;
    $out['insurer_covered_percent'] = max(0, min(100, (int) ($r['insurer_covered_percent'] ?? 0)));
    $out['patient_copay_percent'] = hms_patient_copay_percent_at_pos($out['insurer_covered_percent']);
    $out['carrier_name'] = trim((string) ($r['carrier_name'] ?? ''));

    return $out;
}
