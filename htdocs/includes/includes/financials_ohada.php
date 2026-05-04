<?php
declare(strict_types=1);

/**
 * OHADA-oriented aggregates from tbl_fin_journal_* (SYSCOHADA-style class mapping by account code).
 * Amounts in XAF; class titles for printed statements.
 */

/**
 * mysqli_query wrapper — PHP 8.1+ may throw mysqli_sql_exception on SQL error.
 *
 * @return mysqli_result|false
 */
function hms_fin_safe_query(mysqli $connection, string $sql)
{
    try {
        return mysqli_query($connection, $sql);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('hms_fin_ohada SQL: ' . $e->getMessage());
        }

        return false;
    }
}

/** SYSCOHADA account class 1–7 from first digit of account code. */
function hms_fin_ohada_class_from_code(string $accountCode): int
{
    $c = trim($accountCode);
    if ($c === '') {
        return 0;
    }
    $d = $c[0];

    return ctype_digit($d) ? (int) $d : 0;
}

/** OHADA account class titles (SYSCOHADA classes 1–7). */
function hms_fin_ohada_class_title(int $class): string
{
    static $m = [
        1 => 'Long-term resources and equity (class 1)',
        2 => 'Fixed assets (class 2)',
        3 => 'Inventories (class 3)',
        4 => 'Third parties — receivables & payables (class 4)',
        5 => 'Cash and banks (class 5)',
        6 => 'Expenses (class 6)',
        7 => 'Income (class 7)',
    ];

    return $m[$class] ?? ('Class ' . $class);
}

/**
 * Profit and loss movement for an arbitrary date range (classes 6 and 7).
 *
 * @return array{charges:float,produits:float,resultat:float,period_from:string,period_to:string}
 */
function hms_fin_pl_for_date_range(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo
): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['charges' => 0.0, 'produits' => 0.0, 'resultat' => 0.0, 'period_from' => $dateFrom, 'period_to' => $dateTo];
    }
    if ($dateFrom > $dateTo) {
        return ['charges' => 0.0, 'produits' => 0.0, 'resultat' => 0.0, 'period_from' => $dateFrom, 'period_to' => $dateTo];
    }
    $rows = hms_fin_account_movements_period($connection, $facilityId, $dateFrom, $dateTo);
    $charges = 0.0;
    $produits = 0.0;
    foreach ($rows as $r) {
        $cl = (int) ($r['class'] ?? 0);
        $dr = (float) ($r['total_debit'] ?? 0);
        $cr = (float) ($r['total_credit'] ?? 0);
        if ($cl === 6) {
            $charges += ($dr - $cr);
        }
        if ($cl === 7) {
            $produits += ($cr - $dr);
        }
    }
    $charges = round($charges, 2);
    $produits = round($produits, 2);

    return [
        'charges' => $charges,
        'produits' => $produits,
        'resultat' => round($produits - $charges, 2),
        'period_from' => $dateFrom,
        'period_to' => $dateTo,
    ];
}

/**
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float,class:int}>
 */
function hms_fin_account_balances_to_date(
    mysqli $connection,
    int $facilityId,
    string $asOfDateInclusive
): array {
    if (!hms_fin_tables_ok($connection) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDateInclusive)) {
        return [];
    }
    // mysqli_query: maximises compatibility on hosts without mysqlnd / flaky stmt metadata.
    $fid = (int) $facilityId;
    $dEsc = mysqli_real_escape_string($connection, $asOfDateInclusive);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT (' . $f['code'] . ') AS c, MAX((' . $f['label'] . ')) AS lbl,
            SUM(jl.debit) AS tdr, SUM(jl.credit) AS tcr
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header j ON j.id = jl.journal_id
        ' . $f['join'] . '
        WHERE j.facility_id = ' . $fid . ' AND j.entry_date <= \'' . $dEsc . '\'
        GROUP BY (' . $f['code'] . ')
        ORDER BY (' . $f['code'] . ')';
    $q = hms_fin_safe_query($connection, $sql);
    if ($q === false) {
        if (function_exists('error_log')) {
            error_log('hms_fin_ohada: ' . mysqli_error($connection));
        }

        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
            'class' => hms_fin_ohada_class_from_code($code),
        ];
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * Period movements only (for trial balance activity columns).
 *
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float,class:int}>
 */
function hms_fin_account_movements_period(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo
): array {
    if (!hms_fin_tables_ok($connection) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return [];
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT (' . $f['code'] . ') AS c, MAX((' . $f['label'] . ')) AS lbl,
            SUM(jl.debit) AS tdr, SUM(jl.credit) AS tcr
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header j ON j.id = jl.journal_id
        ' . $f['join'] . '
        WHERE j.facility_id = ' . $fid . ' AND j.entry_date BETWEEN \'' . $d1 . '\' AND \'' . $d2 . '\'
        GROUP BY (' . $f['code'] . ')
        ORDER BY (' . $f['code'] . ')';
    $q = hms_fin_safe_query($connection, $sql);
    if ($q === false) {
        if (function_exists('error_log')) {
            error_log('hms_fin_ohada: ' . mysqli_error($connection));
        }

        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
            'class' => hms_fin_ohada_class_from_code($code),
        ];
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * @param list<array{class:int,balance:float,...}> $rows
 * @return array<int,float>
 */
function hms_fin_group_sum_by_class(array $rows, string $balanceKey = 'balance'): array
{
    $g = [];
    foreach ($rows as $r) {
        $c = (int) ($r['class'] ?? 0);
        if ($c < 1 || $c > 7) {
            continue;
        }
        if (!isset($g[$c])) {
            $g[$c] = 0.0;
        }
        $g[$c] = round($g[$c] + (float) ($r[$balanceKey] ?? 0), 2);
    }

    return $g;
}

/** P&L approximate: sum balances classes 6 (charges) and 7 (produits) to date. */
function hms_fin_pl_totals_to_date(
    mysqli $connection,
    int $facilityId,
    string $asOfDate
): array {
    $rows = hms_fin_account_balances_to_date($connection, $facilityId, $asOfDate);
    $charges = 0.0;
    $revenue = 0.0;
    foreach ($rows as $r) {
        $cl = (int) ($r['class'] ?? 0);
        $dr = (float) ($r['total_debit'] ?? 0);
        $cr = (float) ($r['total_credit'] ?? 0);
        if ($cl === 6) {
            $charges += ($dr - $cr);
        }
        if ($cl === 7) {
            $revenue += ($cr - $dr);
        }
    }
    $charges = round($charges, 2);
    $revenue = round($revenue, 2);
    $result = round($revenue - $charges, 2);

    return [
        'charges_net' => $charges,
        'produits_net' => $revenue,
        'resultat_approx' => $result,
    ];
}

/** Month window for P&L activity. */
function hms_fin_pl_for_month(
    mysqli $connection,
    int $facilityId,
    int $year,
    int $month
): array {
    if ($month < 1 || $month > 12) {
        return ['charges' => 0.0, 'produits' => 0.0, 'resultat' => 0.0];
    }
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to = date('Y-m-t', strtotime($from . ' 12:00:00'));
    $rows = hms_fin_account_movements_period($connection, $facilityId, $from, $to);
    $charges = 0.0;
    $produits = 0.0;
    foreach ($rows as $r) {
        $cl = (int) ($r['class'] ?? 0);
        $dr = (float) ($r['total_debit'] ?? 0);
        $cr = (float) ($r['total_credit'] ?? 0);
        if ($cl === 6) {
            $charges += ($dr - $cr);
        }
        if ($cl === 7) {
            $produits += ($cr - $dr);
        }
    }
    $charges = round($charges, 2);
    $produits = round($produits, 2);
    $resultat = round($produits - $charges, 2);

    return [
        'charges' => $charges,
        'produits' => $produits,
        'resultat' => $resultat,
        'period_from' => $from,
        'period_to' => $to,
    ];
}

/** Fiscal year P&L movement (classes 6 & 7). */
function hms_fin_pl_for_year(mysqli $connection, int $facilityId, int $year): array
{
    $from = sprintf('%04d-01-01', $year);
    $to = sprintf('%04d-12-31', $year);
    $rows = hms_fin_account_movements_period($connection, $facilityId, $from, $to);
    $charges = 0.0;
    $produits = 0.0;
    foreach ($rows as $r) {
        $cl = (int) ($r['class'] ?? 0);
        $dr = (float) ($r['total_debit'] ?? 0);
        $cr = (float) ($r['total_credit'] ?? 0);
        if ($cl === 6) {
            $charges += ($dr - $cr);
        }
        if ($cl === 7) {
            $produits += ($cr - $dr);
        }
    }
    $charges = round($charges, 2);
    $produits = round($produits, 2);

    return [
        'charges' => $charges,
        'produits' => $produits,
        'resultat' => round($produits - $charges, 2),
        'period_from' => $from,
        'period_to' => $to,
    ];
}

function hms_fin_tax_setting_get(mysqli $connection, int $facilityId, string $key, string $default = ''): string
{
    if (!hms_db_table_exists($connection, 'tbl_fin_tax_setting')) {
        return $default;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT setting_value FROM tbl_fin_tax_setting WHERE facility_id = ? AND setting_key = ? LIMIT 1'
    );
    if (!$st) {
        return $default;
    }
    mysqli_stmt_bind_param($st, 'is', $facilityId, $key);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    $v = $row !== null ? trim((string) ($row['setting_value'] ?? '')) : '';

    return $v !== '' ? $v : $default;
}

function hms_fin_tax_setting_set(mysqli $connection, int $facilityId, string $key, string $value): bool
{
    if (!hms_db_table_exists($connection, 'tbl_fin_tax_setting')) {
        return false;
    }
    $st = mysqli_prepare(
        $connection,
        'REPLACE INTO tbl_fin_tax_setting (facility_id, setting_key, setting_value) VALUES (?,?,?)'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'iss', $facilityId, $key, $value);

    return mysqli_stmt_execute($st);
}

/**
 * @return list<array{id:int,entry_date:string,reference:string,narration:string,source_type:string,line_count:int}>
 */
function hms_fin_journal_recent_headers(mysqli $connection, int $facilityId, int $limit = 80): array
{
    if (!hms_fin_tables_ok($connection) || $facilityId < 1) {
        return [];
    }
    $limit = max(5, min(200, $limit));
    $sql = 'SELECT h.id, h.entry_date, h.reference, h.narration, h.source_type,
            (SELECT COUNT(*) FROM tbl_fin_journal_line jl WHERE jl.journal_id = h.id) AS line_count
        FROM tbl_fin_journal_header h
        WHERE h.facility_id = ' . (int) $facilityId . '
        ORDER BY h.entry_date DESC, h.id DESC
        LIMIT ' . (int) $limit;
    $q = mysqli_query($connection, $sql);
    if (!$q) {
        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'entry_date' => (string) ($row['entry_date'] ?? ''),
            'reference' => (string) ($row['reference'] ?? ''),
            'narration' => (string) ($row['narration'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'line_count' => (int) ($row['line_count'] ?? 0),
        ];
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * Cameroon TVA worksheet figures (simplified — expert validation required).
 *
 * @return array{ca_ht:float,tva_collectee_est:float,tva_deductible_est:float,tva_net_est:float,taux_pct:float}
 */
function hms_fin_cameroon_tva_estimates_for_month(
    mysqli $connection,
    int $facilityId,
    int $year,
    int $month,
    float $tvaRatePercent
): array {
    $pl = hms_fin_pl_for_month($connection, $facilityId, $year, $month);
    // Heuristic: produits (class 7 movement) as HT proxy when TVA exclusive pricing is not split in GL.
    $caHt = abs((float) ($pl['produits'] ?? 0));
    if ($caHt < 0) {
        $caHt = 0;
    }
    $tvaColl = round($caHt * ($tvaRatePercent / 100), 2);
    $chargesHt = abs((float) ($pl['charges'] ?? 0));
    $tvaDed = round($chargesHt * ($tvaRatePercent / 100) * 0.85, 2);

    return [
        'ca_ht' => round($caHt, 2),
        'tva_collectee_est' => $tvaColl,
        'tva_deductible_est' => $tvaDed,
        'tva_net_est' => round($tvaColl - $tvaDed, 2),
        'taux_pct' => $tvaRatePercent,
    ];
}

/**
 * Indicative corporate income tax (CIT / IS) on a pre-tax accounting result (OHADA-style; before tax adjustments).
 *
 * @return array{accounting_result:float,rate_pct:float,cit_indicative:float,loss_position:bool}
 */
function hms_fin_cameroon_cit_indicative(float $accountingResultBeforeTax, float $standardRatePercent): array
{
    $rate = max(0.0, min(100.0, $standardRatePercent));
    $cit = 0.0;
    if ($accountingResultBeforeTax > 0) {
        $cit = round($accountingResultBeforeTax * ($rate / 100.0), 2);
    }

    return [
        'accounting_result' => round($accountingResultBeforeTax, 2),
        'rate_pct' => $rate,
        'cit_indicative' => $cit,
        'loss_position' => $accountingResultBeforeTax < 0,
    ];
}
