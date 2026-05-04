<?php
declare(strict_types=1);

/**
 * Cameroon payroll deductions + progressive IRPP from tbl_hms_payroll_settings.
 * (Replaces standalone calculateCameroonTax from installer scripts — uses HMS DB + facility.)
 */
function hms_payroll_default_brackets_json(): string
{
    return '[{"min":0,"max":166667,"rate":0},{"min":166668,"max":250000,"rate":11},{"min":250001,"max":416667,"rate":21.25},{"min":416668,"max":833333,"rate":31.25},{"min":833334,"max":null,"rate":38.5}]';
}

/**
 * @return list<array{min: float, max: float|null, rate: float}>
 */
function hms_payroll_parse_brackets(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || $decoded === []) {
        $decoded = json_decode(hms_payroll_default_brackets_json(), true);
    }
    if (!is_array($decoded)) {
        return [];
    }
    $brackets = [];
    foreach ($decoded as $b) {
        if (!is_array($b)) {
            continue;
        }
        $min = (float) ($b['min'] ?? 0);
        $max = array_key_exists('max', $b) && $b['max'] !== null && $b['max'] !== '' ? (float) $b['max'] : null;
        $rate = (float) ($b['rate'] ?? 0);
        $brackets[] = ['min' => $min, 'max' => $max, 'rate' => $rate];
    }
    usort($brackets, static fn ($a, $b) => $a['min'] <=> $b['min']);

    return $brackets;
}

/** Progressive IRPP on monthly taxable income (XAF). */
function hms_payroll_irpp_from_taxable(float $taxableIncome, array $brackets): float
{
    $ti = max(0.0, $taxableIncome);
    if ($ti <= 0 || $brackets === []) {
        return 0.0;
    }
    $tax = 0.0;
    foreach ($brackets as $br) {
        $lo = (float) ($br['min'] ?? 0);
        $hi = $br['max'] !== null ? (float) $br['max'] : 1e15;
        $from = max(0.0, $lo);
        $to = min($ti, $hi);
        if ($to > $from) {
            $tax += ($to - $from) * ((float) ($br['rate'] ?? 0)) / 100.0;
        }
    }

    return round($tax, 2);
}

/**
 * @return array<string, mixed>|null null if payroll settings missing for facility
 */
function hms_payroll_settings_for_year(mysqli $connection, int $facilityId, int $taxYear): ?array
{
    $stmt = mysqli_prepare(
        $connection,
        'SELECT * FROM tbl_hms_payroll_settings WHERE facility_id = ? AND tax_year <= ? ORDER BY tax_year DESC LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $facilityId, $taxYear);
    mysqli_stmt_execute($stmt);
    $row = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($stmt) : null;
    mysqli_stmt_close($stmt);
    if (!is_array($row)) {
        $stmt2 = mysqli_prepare(
            $connection,
            'SELECT * FROM tbl_hms_payroll_settings WHERE facility_id = ? ORDER BY tax_year DESC LIMIT 1'
        );
        if (!$stmt2) {
            return null;
        }
        mysqli_stmt_bind_param($stmt2, 'i', $facilityId);
        mysqli_stmt_execute($stmt2);
        $row = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($stmt2) : null;
        mysqli_stmt_close($stmt2);
    }

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, float>|null
 */
function hms_payroll_cameroon_calculate(mysqli $connection, int $facilityId, int $taxYear, float $grossSalary): ?array
{
    if (!hms_db_table_exists($connection, 'tbl_hms_payroll_settings')) {
        return null;
    }
    $set = hms_payroll_settings_for_year($connection, $facilityId, $taxYear);
    if ($set === null) {
        return null;
    }
    $gross = max(0.0, $grossSalary);
    $cnpsPct = (float) ($set['cnps_employee_rate'] ?? 2.8);
    $cimrPct = (float) ($set['cimr_employee_rate'] ?? 2.4);
    $crtvPct = (float) ($set['crtv_rate'] ?? 0.2);
    $councilPct = (float) ($set['council_tax_rate'] ?? 0.8);
    $devPct = (float) ($set['development_tax_rate'] ?? 0.5);
    $cnhcPct = (float) ($set['cnhc_rate'] ?? 0.5);

    $cnps = round($gross * $cnpsPct / 100.0, 2);
    $cimr = round($gross * $cimrPct / 100.0, 2);
    $crtv = round($gross * $crtvPct / 100.0, 2);
    $council = round($gross * $councilPct / 100.0, 2);
    $dev = round($gross * $devPct / 100.0, 2);
    $cnhc = round($gross * $cnhcPct / 100.0, 2);

    $preTax = $cnps + $cimr + $crtv + $council + $dev + $cnhc;
    $taxable = round(max(0.0, $gross - $preTax), 2);
    $brackets = hms_payroll_parse_brackets((string) ($set['tax_brackets'] ?? ''));
    $irpp = hms_payroll_irpp_from_taxable($taxable, $brackets);
    $totalDed = round($preTax + $irpp, 2);
    $net = round($gross - $totalDed, 2);

    $empPension = round($gross * 4.2 / 100.0, 2);
    $empFamily = round($gross * 7.0 / 100.0, 2);
    $empWork = round($gross * 1.75 / 100.0, 2);
    $empNef = round($gross * 1.0 / 100.0, 2);
    $empNhf = round($gross * 1.5 / 100.0, 2);
    $empTotal = round($empPension + $empFamily + $empWork + $empNef + $empNhf, 2);

    return [
        'gross' => round($gross, 2),
        'cnps_employee' => $cnps,
        'cimr_employee' => $cimr,
        'crtv_deduction' => $crtv,
        'council_tax_deduction' => $council,
        'development_tax_deduction' => $dev,
        'cnhc_deduction' => $cnhc,
        'taxable_income' => $taxable,
        'income_tax' => $irpp,
        'total_deductions' => $totalDed,
        'net_salary' => $net,
        'employer_cnps_pension' => $empPension,
        'employer_cnps_family' => $empFamily,
        'employer_cnps_work' => $empWork,
        'employer_nef' => $empNef,
        'employer_nhf' => $empNhf,
        'total_employer_cost' => $empTotal,
    ];
}
