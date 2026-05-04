<?php
declare(strict_types=1);

/**
 * Queries for financial report pages (GL detail, cash flow helpers).
 */
function hms_fin_reports_clear_sql_error(): void
{
    $GLOBALS['hms_fin_reports_sql_error'] = '';
}

function hms_fin_reports_last_sql_error(): string
{
    return (string) ($GLOBALS['hms_fin_reports_sql_error'] ?? '');
}

function hms_fin_safe_query_rows(mysqli $connection, string $sql): array
{
    try {
        $q = mysqli_query($connection, $sql);
    } catch (Throwable $e) {
        $GLOBALS['hms_fin_reports_sql_error'] = $e->getMessage();
        if (function_exists('error_log')) {
            error_log('financials_reports_data: ' . $e->getMessage());
        }

        return [];
    }
    if ($q === false) {
        $GLOBALS['hms_fin_reports_sql_error'] = mysqli_error($connection);

        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $out[] = $row;
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * Sum of (debit - credit) for accounts whose code starts with prefix, as at date inclusive.
 *
 * @return float
 */
function hms_fin_prefix_balance_as_of(mysqli $connection, int $facilityId, string $asOfDate, string $prefix): float
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
        return 0.0;
    }
    $fid = (int) $facilityId;
    $d = mysqli_real_escape_string($connection, $asOfDate);
    $pfx = mysqli_real_escape_string($connection, $prefix);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS b
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id
        ' . $f['join'] . '
        WHERE h.facility_id = ' . $fid . ' AND h.entry_date <= \'' . $d . '\' AND (' . $f['code'] . ') LIKE \'' . $pfx . '%\'';
    $rows = hms_fin_safe_query_rows($connection, $sql);
    if ($rows === []) {
        return 0.0;
    }

    return round((float) ($rows[0]['b'] ?? 0), 2);
}

/**
 * Book balance for one account code as at date (cumulative).
 */
function hms_fin_account_balance_code_as_of(mysqli $connection, int $facilityId, string $asOfDate, string $accountCode): float
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
        return 0.0;
    }
    $fid = (int) $facilityId;
    $d = mysqli_real_escape_string($connection, $asOfDate);
    $code = mysqli_real_escape_string($connection, $accountCode);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS b
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id
        ' . $f['join'] . '
        WHERE h.facility_id = ' . $fid . ' AND h.entry_date <= \'' . $d . '\' AND (' . $f['code'] . ') = \'' . $code . '\'';
    $rows = hms_fin_safe_query_rows($connection, $sql);

    return round((float) ($rows[0]['b'] ?? 0), 2);
}

/**
 * Net movement (debit - credit) in period for account codes starting with prefix.
 */
function hms_fin_prefix_movement_period(mysqli $connection, int $facilityId, string $dateFrom, string $dateTo, string $prefix): float
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return 0.0;
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $pfx = mysqli_real_escape_string($connection, $prefix);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS m
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id
        ' . $f['join'] . '
        WHERE h.facility_id = ' . $fid . ' AND h.entry_date BETWEEN \'' . $d1 . '\' AND \'' . $d2 . '\' AND (' . $f['code'] . ') LIKE \'' . $pfx . '%\'';
    $rows = hms_fin_safe_query_rows($connection, $sql);

    return round((float) ($rows[0]['m'] ?? 0), 2);
}

/**
 * Opening balance per account (debit - credit) before dateFrom.
 *
 * @return array<string,float> account_code => balance
 */
function hms_fin_opening_balances_before(mysqli $connection, int $facilityId, string $beforeDate): array
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
        return [];
    }
    $fid = (int) $facilityId;
    $d = mysqli_real_escape_string($connection, $beforeDate);
    $f = hms_fin_jl_report_sql_fragments($connection);
    $sql = 'SELECT (' . $f['code'] . ') AS c, COALESCE(SUM(jl.debit - jl.credit), 0) AS b
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id
        ' . $f['join'] . '
        WHERE h.facility_id = ' . $fid . ' AND h.entry_date < \'' . $d . '\'
        GROUP BY (' . $f['code'] . ')';
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $row) {
        $code = (string) ($row['c'] ?? '');
        if ($code !== '') {
            $out[$code] = round((float) ($row['b'] ?? 0), 2);
        }
    }

    return $out;
}

/**
 * Journal lines in date range, optional account code prefix filter.
 *
 * @return list<array{entry_date:string,reference:string,narration:string,source_type:string,account_code:string,account_label:string,debit:float,credit:float,journal_id:int,line_id:int}>
 */
function hms_fin_gl_lines(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo,
    ?string $accountPrefix
): array {
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return [];
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $pfx = $accountPrefix !== null && $accountPrefix !== '' ? mysqli_real_escape_string($connection, $accountPrefix) : '';
    $f = hms_fin_jl_report_sql_fragments($connection);
    $filter = $pfx !== '' ? ' AND (' . $f['code'] . ') LIKE \'' . $pfx . '%\'' : '';
    $sql = 'SELECT h.entry_date, h.reference, h.narration, h.source_type, h.id AS journal_id,
            jl.id AS line_id, (' . $f['code'] . ') AS account_code, (' . $f['label'] . ') AS account_label, jl.debit, jl.credit
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id
        ' . $f['join'] . '
        WHERE h.facility_id = ' . $fid . ' AND h.entry_date BETWEEN \'' . $d1 . '\' AND \'' . $d2 . '\'' . $filter . '
        ORDER BY (' . $f['code'] . ') ASC, h.entry_date ASC, h.id ASC, jl.id ASC';
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $row) {
        $out[] = [
            'entry_date' => (string) ($row['entry_date'] ?? ''),
            'reference' => (string) ($row['reference'] ?? ''),
            'narration' => (string) ($row['narration'] ?? ''),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'journal_id' => (int) ($row['journal_id'] ?? 0),
            'line_id' => (int) ($row['line_id'] ?? 0),
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_label' => (string) ($row['account_label'] ?? ''),
            'debit' => round((float) ($row['debit'] ?? 0), 2),
            'credit' => round((float) ($row['credit'] ?? 0), 2),
        ];
    }

    return $out;
}

/**
 * Explain empty GL when journals exist on another facility (common after demo seed / multi-site).
 *
 * @return string Plain-language hint or empty string
 */
function hms_fin_gl_empty_site_hint(
    mysqli $connection,
    int $sessionFacilityId,
    string $dateFrom,
    string $dateTo
): string {
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return '';
    }
    $fid = (int) $sessionFacilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $q = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE facility_id = ' . $fid
        . " AND entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $mine = 0;
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $mine = (int) ($row['c'] ?? 0);
    }
    if ($q) {
        mysqli_free_result($q);
    }
    if ($mine > 0) {
        return '';
    }
    $q2 = mysqli_query(
        $connection,
        'SELECT facility_id, COUNT(*) AS n FROM tbl_fin_journal_header WHERE entry_date BETWEEN \''
        . $d1 . '\' AND \'' . $d2 . '\' GROUP BY facility_id ORDER BY facility_id ASC LIMIT 16'
    );
    $others = [];
    if ($q2) {
        while ($row = mysqli_fetch_assoc($q2)) {
            $of = (int) ($row['facility_id'] ?? 0);
            $n = (int) ($row['n'] ?? 0);
            if ($of > 0 && $of !== $fid) {
                $others[] = '#' . $of . ' (' . $n . ' header' . ($n === 1 ? '' : 's') . ')';
            }
        }
        mysqli_free_result($q2);
    }
    if ($others === []) {
        return '';
    }
    $tail = 'Use the header site menu (or visit facilities.php) and pick the same site where receipts and the demo seed were posted — often site #1 (MAIN).';
    if (defined('HMS_FIXED_FACILITY_ID') && (int) HMS_FIXED_FACILITY_ID > 0) {
        $fix = (int) HMS_FIXED_FACILITY_ID;
        $tail = 'This app is locked to site #' . $fix
            . ' via HMS_FIXED_FACILITY_ID in includes/config.php. Journals must exist for that facility id, or set HMS_FIXED_FACILITY_ID to 0 in includes/config.local.php to switch sites.';
    }

    return 'Journal data exists in this period on ' . implode(', ', $others)
        . ', but your session is on site #' . $fid . '. The General ledger only shows the active hospital site. '
        . $tail;
}

/**
 * When GL is empty: no tbl_fin_journal_header rows in range for any facility (nothing posted / wrong dates).
 */
function hms_fin_gl_empty_no_journals_anywhere_hint(
    mysqli $connection,
    string $dateFrom,
    string $dateTo
): string {
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return '';
    }
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $q = mysqli_query(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $n = 0;
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $n = (int) ($row['c'] ?? 0);
    }
    if ($q) {
        mysqli_free_result($q);
    }
    if ($n > 0) {
        return '';
    }

    return 'No general-ledger journals exist in the database for this date range on any site. '
        . 'Open financials-sync-gl.php (Sync to GL), use “Post receipts for this period” on this page when billing shows receipts, '
        . 'or run the Cameroon demo seed from platform-overview.php (Help) if you expect sample data.';
}

/**
 * Headers exist for this facility in range but no journal lines (broken link or old schema).
 */
function hms_fin_gl_empty_headers_without_lines_hint(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo
): string {
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return '';
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $qh = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE facility_id = ' . $fid
        . " AND entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $nh = 0;
    if ($qh && ($row = mysqli_fetch_assoc($qh))) {
        $nh = (int) ($row['c'] ?? 0);
    }
    if ($qh) {
        mysqli_free_result($qh);
    }
    if ($nh < 1) {
        return '';
    }
    $ql = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id'
        . ' WHERE h.facility_id = ' . $fid . " AND h.entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $nl = 0;
    if ($ql && ($row = mysqli_fetch_assoc($ql))) {
        $nl = (int) ($row['c'] ?? 0);
    }
    if ($ql) {
        mysqli_free_result($ql);
    }
    if ($nl > 0) {
        return '';
    }

    return 'Journal headers exist for this site in this period, but no line rows were found. '
        . 'Run database/migrations/019_credit_receivables.sql (and related financial migrations), '
        . 'or check that tbl_fin_journal_line.journal_id matches tbl_fin_journal_header.id.';
}

/**
 * MIN/MAX entry_date for a facility (for default report range when URL has no d1/d2).
 *
 * @return array{min:string,max:string}|null
 */
function hms_fin_journal_entry_date_bounds(mysqli $connection, int $facilityId): ?array
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)) {
        return null;
    }
    $fid = (int) $facilityId;
    $r = hms_fin_safe_query_rows(
        $connection,
        'SELECT MIN(entry_date) AS a, MAX(entry_date) AS b FROM tbl_fin_journal_header WHERE facility_id = ' . $fid
    );
    if ($r === []) {
        return null;
    }
    $a = trim((string) ($r[0]['a'] ?? ''));
    $b = trim((string) ($r[0]['b'] ?? ''));
    if ($a === '' || $b === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) {
        return null;
    }

    return ['min' => $a, 'max' => $b];
}

/**
 * Counts for troubleshooting empty GL / trial balance (cheap queries).
 *
 * @return array{
 *   fin_tables_ok: bool,
 *   facility_id: int,
 *   fixed_facility_id: int,
 *   facility_row_ok: bool,
 *   headers_facility_total: int,
 *   headers_facility_period: int,
 *   lines_facility_period: int,
 *   headers_any_period: int,
 *   receipt_docs_period: int,
 *   billing_ok: bool,
 *   facility_period_breakdown: string,
 *   last_sql_error: string,
 *   journal_entry_date_min: string,
 *   journal_entry_date_max: string
 * }
 */
function hms_fin_journal_health_snapshot(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo
): array {
    $fid = (int) $facilityId;
    $out = [
        'fin_tables_ok' => function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection),
        'facility_id' => $fid,
        'fixed_facility_id' => defined('HMS_FIXED_FACILITY_ID') ? (int) HMS_FIXED_FACILITY_ID : 0,
        'facility_row_ok' => false,
        'headers_facility_total' => 0,
        'headers_facility_period' => 0,
        'lines_facility_period' => 0,
        'headers_any_period' => 0,
        'receipt_docs_period' => 0,
        'billing_ok' => false,
        'facility_period_breakdown' => '',
        'last_sql_error' => '',
        'journal_entry_date_min' => '',
        'journal_entry_date_max' => '',
    ];
    if (!$out['fin_tables_ok'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return $out;
    }
    if (function_exists('hms_fin_reports_clear_sql_error')) {
        hms_fin_reports_clear_sql_error();
    }
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);

    if (hms_db_table_exists($connection, 'tbl_facility')) {
        $r = hms_fin_safe_query_rows($connection, 'SELECT COUNT(*) AS c FROM tbl_facility WHERE id = ' . $fid);
        $out['facility_row_ok'] = ((int) ($r[0]['c'] ?? 0)) > 0;
    } else {
        $out['facility_row_ok'] = true;
    }

    $r = hms_fin_safe_query_rows($connection, 'SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE facility_id = ' . $fid);
    $out['headers_facility_total'] = (int) ($r[0]['c'] ?? 0);

    $r = hms_fin_safe_query_rows(
        $connection,
        'SELECT MIN(entry_date) AS dmin, MAX(entry_date) AS dmax FROM tbl_fin_journal_header WHERE facility_id = ' . $fid
    );
    if ($r !== []) {
        $out['journal_entry_date_min'] = (string) ($r[0]['dmin'] ?? '');
        $out['journal_entry_date_max'] = (string) ($r[0]['dmax'] ?? '');
    }

    $r = hms_fin_safe_query_rows(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE facility_id = " . $fid
        . " AND entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $out['headers_facility_period'] = (int) ($r[0]['c'] ?? 0);

    $r = hms_fin_safe_query_rows(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id'
        . ' WHERE h.facility_id = ' . $fid . " AND h.entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $out['lines_facility_period'] = (int) ($r[0]['c'] ?? 0);

    $r = hms_fin_safe_query_rows(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_fin_journal_header WHERE entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
    );
    $out['headers_any_period'] = (int) ($r[0]['c'] ?? 0);

    $r = hms_fin_safe_query_rows(
        $connection,
        'SELECT facility_id, COUNT(*) AS n FROM tbl_fin_journal_header WHERE entry_date BETWEEN \''
        . $d1 . '\' AND \'' . $d2 . '\' GROUP BY facility_id ORDER BY facility_id ASC LIMIT 12'
    );
    $parts = [];
    foreach ($r as $row) {
        $fi = (int) ($row['facility_id'] ?? 0);
        $n = (int) ($row['n'] ?? 0);
        if ($fi > 0) {
            $parts[] = '#' . $fi . ': ' . $n . ' hdr';
        }
    }
    $out['facility_period_breakdown'] = $parts !== [] ? implode('; ', $parts) : '';

    if (function_exists('hms_billing_document_tables_ok') && hms_billing_document_tables_ok($connection)) {
        $out['billing_ok'] = true;
        $r = hms_fin_safe_query_rows(
            $connection,
            'SELECT COUNT(*) AS c FROM tbl_billing_document WHERE facility_id = ' . $fid
            . " AND doc_type = 'receipt' AND total_amount > 0.005 AND DATE(created_at) BETWEEN '" . $d1 . "' AND '" . $d2 . "'"
        );
        $out['receipt_docs_period'] = (int) ($r[0]['c'] ?? 0);
    }

    if (function_exists('hms_fin_reports_last_sql_error')) {
        $out['last_sql_error'] = hms_fin_reports_last_sql_error();
    }

    return $out;
}

/**
 * Plain-language troubleshooting block from health snapshot (no HTML).
 */
function hms_fin_journal_health_hint_message(array $snap, string $dateFrom, string $dateTo): string
{
    if (empty($snap['fin_tables_ok'])) {
        return '';
    }
    $fid = (int) ($snap['facility_id'] ?? 0);
    $fix = (int) ($snap['fixed_facility_id'] ?? 0);
    $lineCnt = (int) ($snap['lines_facility_period'] ?? 0);
    if ($lineCnt > 0) {
        return '';
    }

    $msgs = [];
    if (empty($snap['facility_row_ok'])) {
        $msgs[] = 'CRITICAL: tbl_facility has no row for id ' . $fid
            . '. Journal inserts fail the foreign key on tbl_fin_journal_header. Run database/migrations/001_multi_site_platform.sql'
            . ' (or insert MAIN site with that id), then retry Sync to GL.';
    }

    $hdrTot = (int) ($snap['headers_facility_total'] ?? 0);
    $hdrPer = (int) ($snap['headers_facility_period'] ?? 0);
    $rec = (int) ($snap['receipt_docs_period'] ?? 0);
    $any = (int) ($snap['headers_any_period'] ?? 0);
    $brk = trim((string) ($snap['facility_period_breakdown'] ?? ''));

    if ($hdrPer > 0 && $lineCnt === 0) {
        $msgs[] = 'Journal headers exist for site #' . $fid . ' in this period but journal lines are missing or not linked. '
            . 'Open financials-journal-diagnostics.php for schema/FK repair, or run migrations 019 / 031.';
    }

    if ($rec > 0 && $hdrTot === 0 && !empty($snap['facility_row_ok'])) {
        $msgs[] = 'Billing shows ' . $rec . ' fiscal receipt document(s) in ' . $dateFrom . '–' . $dateTo
            . ' for site #' . $fid . ', but zero GL headers for this site. Open financials-sync-gl.php and post receipts for that range.';
    }

    if ($any > 0 && $hdrPer === 0 && $brk !== '') {
        $msgs[] = 'In this period, journals exist only on other site(s): ' . $brk
            . '. Active site is #' . $fid . ($fix > 0 ? ' (HMS_FIXED_FACILITY_ID=' . $fix . ').' : '.');
    }

    if ($any === 0 && $hdrTot === 0 && $rec === 0) {
        $msgs[] = 'No GL headers and no fiscal receipts in this range for site #' . $fid
            . '. Widen From/To dates, create cashier activity, run the demo seed, or sync after posting.';
    }

    if ($any === 0 && $hdrTot > 0 && $hdrPer === 0) {
        $jmin = trim((string) ($snap['journal_entry_date_min'] ?? ''));
        $jmax = trim((string) ($snap['journal_entry_date_max'] ?? ''));
        if ($jmin !== '' && $jmax !== '') {
            $msgs[] = 'This site has ' . $hdrTot . ' journal header(s). Stored entry_date runs from ' . $jmin . ' through ' . $jmax
                . '. Your report range ' . $dateFrom . '–' . $dateTo . ' does not overlap that span — widen From/To to include those dates.';
        } else {
            $msgs[] = 'This site has ' . $hdrTot . ' journal header(s) in total, but none dated within ' . $dateFrom . '–' . $dateTo . '. Adjust the date range.';
        }
    }

    $err = trim((string) ($snap['last_sql_error'] ?? ''));
    if ($err !== '') {
        $msgs[] = 'Last SQL error from a report query: ' . $err;
    }

    return $msgs !== [] ? implode("\n\n", $msgs) : '';
}

/**
 * Trial balance period movements — uses safe query helper so SQL errors surface.
 *
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float}>
 */
function hms_fin_tb_movement_rows(mysqli $connection, int $facilityId, string $dateFrom, string $dateTo): array
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return [];
    }
    if (function_exists('hms_fin_reports_clear_sql_error')) {
        hms_fin_reports_clear_sql_error();
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
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $row) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
        ];
    }

    return $out;
}

/**
 * Cumulative balances through as-of date (trial balance “balance” column).
 *
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float}>
 */
function hms_fin_tb_balance_rows(mysqli $connection, int $facilityId, string $asOfDateInclusive): array
{
    if (!function_exists('hms_fin_tables_ok') || !hms_fin_tables_ok($connection)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDateInclusive)) {
        return [];
    }
    if (function_exists('hms_fin_reports_clear_sql_error')) {
        hms_fin_reports_clear_sql_error();
    }
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
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $row) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
        ];
    }

    return $out;
}

/**
 * Patient AR rows with balance and aging bucket (days from oldest open charge).
 *
 * @return list<array{id:int,patient:string,balance:float,bucket:string,status:string,invoice_due_date:?string}>
 */
function hms_fin_ar_report_rows(mysqli $connection, int $facilityId, ?string $asOfDate = null): array
{
    if (!hms_db_table_exists($connection, 'tbl_credit_account') || !hms_db_table_exists($connection, 'tbl_patient')) {
        return [];
    }
    $anchor = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) ? $asOfDate : date('Y-m-d');
    $fid = (int) $facilityId;
    $sql = 'SELECT ca.id, ca.status, ca.invoice_due_date, p.first_name, p.last_name,
            (SELECT COALESCE(SUM(amount),0) FROM tbl_charge WHERE credit_account_id = ca.id AND on_credit = 1)
             - (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_payment WHERE credit_account_id = ca.id)
             - (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_adjustment WHERE credit_account_id = ca.id)
            AS balance_calc,
            (SELECT MIN(DATE(posted_at)) FROM tbl_charge WHERE credit_account_id = ca.id AND on_credit = 1) AS oldest_charge
         FROM tbl_credit_account ca
         INNER JOIN tbl_patient p ON p.id = ca.patient_id
         WHERE ca.facility_id = ' . $fid . '
         ORDER BY balance_calc DESC';
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $r) {
        $bal = round((float) ($r['balance_calc'] ?? 0), 2);
        if ($bal <= 0.02) {
            continue;
        }
        $oldest = (string) ($r['oldest_charge'] ?? '');
        $days = 0;
        if ($oldest !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldest)) {
            $days = (int) floor((strtotime($anchor . ' 12:00:00') - strtotime($oldest . ' 12:00:00')) / 86400);
            if ($days < 0) {
                $days = 0;
            }
        }
        if ($days <= 30) {
            $bucket = 'Current (0–30 days)';
        } elseif ($days <= 60) {
            $bucket = '31–60 days';
        } elseif ($days <= 90) {
            $bucket = '61–90 days';
        } elseif ($days <= 120) {
            $bucket = '91–120 days';
        } else {
            $bucket = 'Over 120 days';
        }
        $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'patient' => $name !== '' ? $name : ('Patient #' . (int) ($r['id'] ?? 0)),
            'balance' => $bal,
            'bucket' => $bucket,
            'status' => (string) ($r['status'] ?? ''),
            'invoice_due_date' => isset($r['invoice_due_date']) && $r['invoice_due_date'] !== null ? (string) $r['invoice_due_date'] : null,
        ];
    }

    return $out;
}

/**
 * Expense register by vendor (accounts payable style — cash expenses recorded).
 *
 * @return list<array{vendor:string, bills:int, amount:float, last_date:?string}>
 */
function hms_fin_ap_vendor_summary(mysqli $connection, int $facilityId, ?string $dateFrom = null, ?string $dateTo = null): array
{
    if (!hms_db_table_exists($connection, 'tbl_expense')) {
        return [];
    }
    $fid = (int) $facilityId;
    $hasVendor = hms_db_column_exists($connection, 'tbl_expense', 'vendor');
    $hasDate = hms_db_column_exists($connection, 'tbl_expense', 'expense_date');
    $dexpr = $hasDate ? 'MAX(expense_date)' : 'MAX(created_at)';
    $dateFilter = '';
    if ($hasDate && $dateFrom !== null && $dateTo !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $df = mysqli_real_escape_string($connection, $dateFrom);
        $dt = mysqli_real_escape_string($connection, $dateTo);
        $dateFilter = " AND expense_date BETWEEN '" . $df . "' AND '" . $dt . "'";
    } elseif (!$hasDate && $dateFrom !== null && $dateTo !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $df = mysqli_real_escape_string($connection, $dateFrom);
        $dt = mysqli_real_escape_string($connection, $dateTo);
        $dateFilter = " AND DATE(created_at) BETWEEN '" . $df . "' AND '" . $dt . "'";
    }
    if ($hasVendor) {
        $sql = 'SELECT COALESCE(NULLIF(TRIM(vendor), \'\'), \'— Unspecified vendor —\') AS v,
            COUNT(*) AS n,
            COALESCE(SUM(amount_xaf), 0) AS amt,
            ' . $dexpr . ' AS last_d
        FROM tbl_expense WHERE facility_id = ' . $fid . $dateFilter . '
        GROUP BY COALESCE(NULLIF(TRIM(vendor), \'\'), \'— Unspecified vendor —\')
        ORDER BY amt DESC LIMIT 200';
    } else {
        $sql = 'SELECT \'—\' AS v,
            COUNT(*) AS n,
            COALESCE(SUM(amount_xaf), 0) AS amt,
            ' . $dexpr . ' AS last_d
        FROM tbl_expense WHERE facility_id = ' . $fid . $dateFilter;
    }
    $out = [];
    foreach (hms_fin_safe_query_rows($connection, $sql) as $row) {
        $out[] = [
            'vendor' => (string) ($row['v'] ?? ''),
            'bills' => (int) ($row['n'] ?? 0),
            'amount' => round((float) ($row['amt'] ?? 0), 2),
            'last_date' => isset($row['last_d']) && $row['last_d'] !== null ? (string) $row['last_d'] : null,
        ];
    }

    return $out;
}

/**
 * Issued fiscal receipts (tbl_billing_document) in period — same source as cashier / transactions mirror.
 *
 * @return array{total:float,count:int}
 */
function hms_fin_ops_fiscal_receipts_period(mysqli $connection, int $facilityId, string $dateFrom, string $dateTo): array
{
    if (!hms_db_table_exists($connection, 'tbl_billing_document')
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['total' => 0.0, 'count' => 0];
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount), 0) AS s FROM tbl_billing_document
        WHERE facility_id = {$fid} AND doc_type = 'receipt' AND DATE(created_at) BETWEEN '{$d1}' AND '{$d2}'";
    $rows = hms_fin_safe_query_rows($connection, $sql);
    if ($rows === []) {
        return ['total' => 0.0, 'count' => 0];
    }

    return [
        'total' => round((float) ($rows[0]['s'] ?? 0), 2),
        'count' => (int) ($rows[0]['c'] ?? 0),
    ];
}

/**
 * Patient transactions workspace totals (tbl_transaction) in period — mirrors many receipts.
 *
 * @return array{total:float,count:int}
 */
function hms_fin_ops_transactions_period(mysqli $connection, int $facilityId, string $dateFrom, string $dateTo): array
{
    if (!hms_db_table_exists($connection, 'tbl_transaction')
        || !hms_db_column_exists($connection, 'tbl_transaction', 'facility_id')
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['total' => 0.0, 'count' => 0];
    }
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(CAST(amount AS DECIMAL(14,2))), 0) AS s FROM tbl_transaction
        WHERE facility_id = {$fid} AND transaction_date BETWEEN '{$d1}' AND '{$d2}'";
    $rows = hms_fin_safe_query_rows($connection, $sql);
    if ($rows === []) {
        return ['total' => 0.0, 'count' => 0];
    }

    return [
        'total' => round((float) ($rows[0]['s'] ?? 0), 2),
        'count' => (int) ($rows[0]['c'] ?? 0),
    ];
}
