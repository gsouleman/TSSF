<?php
declare(strict_types=1);

/**
 * Demo / training seed for tbl_hms_pay_profile: amounts follow role hierarchy
 * (XAF). Doctors vary by bio/department keywords and joining_date (experience).
 * Does not alter schema — only INSERT/UPDATE pay profile rows.
 */

/**
 * Round to nearest 500 XAF for cleaner figures.
 */
function hms_pay_profile_seed_round_xaf(float $amount): float
{
    return round($amount / 500.0) * 500.0;
}

/**
 * @return array{basic_salary: float, housing_allowance: float, transport_allowance: float, other_allowances: float}
 */
function hms_pay_profile_seed_compute(
    int $employeeId,
    string $roleKey,
    ?string $bio,
    ?string $joiningDate,
    ?string $primaryDepartment
): array {
    $rk = (string) $roleKey;
    $bio = $bio ?? '';
    $primaryDepartment = $primaryDepartment ?? '';
    $specText = strtolower($bio . ' ' . $primaryDepartment);

    $yearsExp = 0.0;
    $jd = trim((string) $joiningDate);
    if ($jd !== '') {
        $ts = strtotime($jd . ' UTC');
        if ($ts !== false) {
            $yearsExp = max(0.0, (time() - $ts) / (365.25 * 86400));
        }
    }

    $expMul = 1.0;
    if ($yearsExp < 2) {
        $expMul = 0.96;
    } elseif ($yearsExp < 5) {
        $expMul = 1.0;
    } elseif ($yearsExp < 12) {
        $expMul = 1.07;
    } else {
        $expMul = 1.14;
    }

    // Base monthly XAF by role (doctors adjusted below).
    $tiers = [
        '1' => ['b' => 780000.0, 'hp' => 0.20, 't' => 85000.0, 'rp' => 0.11],   // Admin
        '2' => ['b' => 0.0, 'hp' => 0.20, 't' => 90000.0, 'rp' => 0.10],       // Doctor — filled below
        '3' => ['b' => 340000.0, 'hp' => 0.16, 't' => 45000.0, 'rp' => 0.05],   // Front desk
        '4' => ['b' => 410000.0, 'hp' => 0.17, 't' => 50000.0, 'rp' => 0.055],  // Lab tech
        '5' => ['b' => 490000.0, 'hp' => 0.175, 't' => 55000.0, 'rp' => 0.06],  // Pharmacist
        '6' => ['b' => 460000.0, 'hp' => 0.17, 't' => 52000.0, 'rp' => 0.055],  // Radiology tech
        '7' => ['b' => 370000.0, 'hp' => 0.165, 't' => 48000.0, 'rp' => 0.05],   // Nurse
        '8' => ['b' => 215000.0, 'hp' => 0.14, 't' => 35000.0, 'rp' => 0.035],   // Nursing aid
    ];

    if (!isset($tiers[$rk])) {
        $rk = '3';
    }

    $tier = $tiers[$rk];
    $basic = (float) $tier['b'];

    if ($rk === '2') {
        // Generalist midpoint; specialists / cardiology / surgery add premiums.
        $basic = 665000.0;
        if (preg_match('/cardio|cardiolog|heart/i', $specText)) {
            $basic += 195000.0;
        } elseif (preg_match('/chirurg|surgery|surgeon|ortho|trauma/i', $specText)) {
            $basic += 165000.0;
        } elseif (preg_match('/pédiat|pediatr|pedia|néonat/i', $specText)) {
            $basic += 95000.0;
        } elseif (preg_match('/général|general\\s+medic|médecine\\s+générale|gynec|gyn[ée]c|internist/i', $specText)) {
            $basic += 15000.0;
        } elseif (preg_match('/sp[eé]cial|specialist|neuro|derm|urolog|ophthal|radiolog|an[ée]sth/i', $specText)) {
            $basic += 115000.0;
        } else {
            $basic += 45000.0;
        }
        // Accountant / finance in doctor role unlikely; if bio says account, nudge toward management band.
        if (preg_match('/account|compta|finance|contrôleur/i', $specText)) {
            $basic = max($basic, 520000.0);
        }
        $basic *= $expMul;
        // Stable per-employee spread (experience / grade simulation).
        $basic += ((float) ($employeeId % 13) - 6.0) * 12500.0;
        $basic = max(520000.0, min(1080000.0, $basic));
    } elseif ($rk === '3' && preg_match('/account|compta|finance|contrôleur/i', $specText)) {
        $basic = 420000.0;
    }

    if ($rk !== '2') {
        $basic += ((float) ($employeeId % 7) - 3.0) * 3500.0;
        $basic = max(180000.0, $basic);
    }

    $basic = hms_pay_profile_seed_round_xaf($basic);
    $housing = hms_pay_profile_seed_round_xaf($basic * (float) $tier['hp']);
    $transport = hms_pay_profile_seed_round_xaf((float) $tier['t'] + ((float) ($employeeId % 5) - 2.0) * 2500.0);
    $resp = hms_pay_profile_seed_round_xaf($basic * (float) $tier['rp']);

    return [
        'basic_salary' => $basic,
        'housing_allowance' => max(0.0, $housing),
        'transport_allowance' => max(0.0, $transport),
        'other_allowances' => max(0.0, $resp),
    ];
}

/**
 * Load extra employee fields for seeding (bio, dates, department).
 * Only selects columns that exist so PREPARE never fails on older schemas.
 *
 * @return array{role: string, bio: ?string, joining_date: ?string, primary_department: ?string}
 */
function hms_pay_profile_seed_employee_meta(mysqli $connection, int $employeeId): array
{
    $empty = [
        'role' => '',
        'bio' => null,
        'joining_date' => null,
        'primary_department' => null,
    ];

    $parts = ['`role`'];
    if (hms_db_column_exists($connection, 'tbl_employee', 'joining_date')) {
        $parts[] = 'joining_date';
    }
    if (hms_db_column_exists($connection, 'tbl_employee', 'bio')) {
        $parts[] = 'bio';
    }
    if (hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
        $parts[] = 'primary_department';
    }

    $sql = 'SELECT ' . implode(', ', $parts) . ' FROM tbl_employee WHERE id = ? LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if (!$st) {
        return $empty;
    }
    mysqli_stmt_bind_param($st, 'i', $employeeId);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return $empty;
    }
    $row = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($st) : null;
    mysqli_stmt_close($st);
    if (!is_array($row)) {
        return $empty;
    }

    $hasBio = hms_db_column_exists($connection, 'tbl_employee', 'bio');
    $hasDept = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
    $hasJoin = hms_db_column_exists($connection, 'tbl_employee', 'joining_date');

    return [
        'role' => isset($row['role']) ? (string) $row['role'] : '',
        'bio' => $hasBio && isset($row['bio']) ? (string) $row['bio'] : null,
        'joining_date' => $hasJoin && isset($row['joining_date']) ? (string) $row['joining_date'] : null,
        'primary_department' => $hasDept && isset($row['primary_department']) ? (string) $row['primary_department'] : null,
    ];
}

/**
 * Upsert pay profiles for all staff returned by hms_hr_active_staff_for_facility().
 *
 * @return array{written: int, skipped: int, only_empty: bool, last_error: string}
 */
function hms_pay_profile_seed_facility(mysqli $connection, int $facilityId, bool $onlyEmptyProfiles): array
{
    $written = 0;
    $skipped = 0;
    $lastError = '';
    if (!hms_db_table_exists($connection, 'tbl_hms_pay_profile')) {
        return [
            'written' => 0,
            'skipped' => 0,
            'only_empty' => $onlyEmptyProfiles,
            'last_error' => 'tbl_hms_pay_profile is missing (run migration 040).',
        ];
    }

    // Ensure each executed statement is committed (some hosts or prior code may disable autocommit).
    if (function_exists('mysqli_autocommit')) {
        mysqli_autocommit($connection, true);
    }

    require_once __DIR__ . '/hms_hr.php';
    $staff = hms_hr_active_staff_for_facility($connection, $facilityId);

    $sqlUpsert = 'INSERT INTO tbl_hms_pay_profile (facility_id, employee_id, basic_salary, housing_allowance, transport_allowance, other_allowances)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), housing_allowance=VALUES(housing_allowance),
        transport_allowance=VALUES(transport_allowance), other_allowances=VALUES(other_allowances)';

    foreach ($staff as $em) {
        $eid = (int) ($em['id'] ?? 0);
        if ($eid < 1) {
            continue;
        }

        if ($onlyEmptyProfiles) {
            $chk = mysqli_prepare(
                $connection,
                'SELECT basic_salary, housing_allowance, transport_allowance, other_allowances FROM tbl_hms_pay_profile WHERE facility_id = ? AND employee_id = ? LIMIT 1'
            );
            $grossExisting = 0.0;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'ii', $facilityId, $eid);
                mysqli_stmt_execute($chk);
                $pr = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($chk) : null;
                mysqli_stmt_close($chk);
                if (is_array($pr)) {
                    $grossExisting = (float) ($pr['basic_salary'] ?? 0) + (float) ($pr['housing_allowance'] ?? 0)
                        + (float) ($pr['transport_allowance'] ?? 0) + (float) ($pr['other_allowances'] ?? 0);
                }
            }
            if ($grossExisting > 0.00001) {
                $skipped++;

                continue;
            }
        }

        $meta = hms_pay_profile_seed_employee_meta($connection, $eid);
        $roleKey = trim((string) ($em['role'] ?? ''));
        if ($roleKey === '') {
            $roleKey = trim((string) ($meta['role'] ?? ''));
        }

        $amounts = hms_pay_profile_seed_compute(
            $eid,
            $roleKey,
            $meta['bio'],
            $meta['joining_date'],
            $meta['primary_department']
        );

        $st = mysqli_prepare($connection, $sqlUpsert);
        if (!$st) {
            $lastError = (string) mysqli_error($connection);
            $skipped++;

            continue;
        }
        $b = $amounts['basic_salary'];
        $h = $amounts['housing_allowance'];
        $t = $amounts['transport_allowance'];
        $o = $amounts['other_allowances'];
        mysqli_stmt_bind_param($st, 'iidddd', $facilityId, $eid, $b, $h, $t, $o);
        if (mysqli_stmt_execute($st)) {
            $written++;
        } else {
            $lastError = (string) mysqli_stmt_error($st);
            $skipped++;
        }
        mysqli_stmt_close($st);
    }

    return ['written' => $written, 'skipped' => $skipped, 'only_empty' => $onlyEmptyProfiles, 'last_error' => $lastError];
}
