<?php
declare(strict_types=1);

/**
 * Core accounting hub (Cameroon / OHADA / XAF) — metrics and nav flags for financials.php.
 */

/** @return array{tax:bool,payroll:bool,healthcare:bool} */
function hms_accounting_nav_flags(mysqli $connection): array
{
    if (!function_exists('hms_nav_sidebar_modules')) {
        return ['tax' => true, 'payroll' => true, 'healthcare' => true];
    }
    $sn = hms_nav_sidebar_modules($connection);

    return [
        'tax' => !empty($sn['tax']),
        'payroll' => !empty($sn['manage_payroll']),
        'healthcare' => !empty($sn['healthcare']),
    ];
}

/**
 * Lightweight KPIs for the accounting dashboard (current calendar month, this facility).
 *
 * @return array{journal_ready:bool,headers_mtd:int,lines_mtd:int}
 */
function hms_accounting_hub_metrics(mysqli $connection, int $facilityId): array
{
    $out = ['journal_ready' => false, 'headers_mtd' => 0, 'lines_mtd' => 0];
    if ($facilityId < 1 || !function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)) {
        return $out;
    }
    $out['journal_ready'] = true;
    $mf = date('Y-m-01');
    $mt = date('Y-m-t');
    $escF = (int) $facilityId;
    $escMf = mysqli_real_escape_string($connection, $mf);
    $escMt = mysqli_real_escape_string($connection, $mt);
    $hq = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE facility_id = ' . $escF
        . " AND entry_date >= '" . $escMf . "' AND entry_date <= '" . $escMt . "'"
    );
    if ($hq && $hr = mysqli_fetch_assoc($hq)) {
        $out['headers_mtd'] = (int) ($hr['c'] ?? 0);
    }
    if (function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_fin_journal_line', 'journal_id')) {
        $lq = mysqli_query(
            $connection,
            'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl
             INNER JOIN tbl_fin_journal_header jh ON jh.id = jl.journal_id AND jh.facility_id = ' . $escF
            . " WHERE jh.entry_date >= '" . $escMf . "' AND jh.entry_date <= '" . $escMt . "'"
        );
        if ($lq && $lr = mysqli_fetch_assoc($lq)) {
            $out['lines_mtd'] = (int) ($lr['c'] ?? 0);
        }
    }

    return $out;
}
