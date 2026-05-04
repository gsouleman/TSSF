<?php
declare(strict_types=1);

/**
 * Lightweight OHADA-oriented journal (tbl_fin_journal_*).
 * Accrual: patient services on credit. Collection: cash/bank vs receivable.
 */

function hms_fin_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_fin_journal_header')
        && hms_db_table_exists($connection, 'tbl_fin_journal_line');
}

/**
 * SQL fragments for journal line reports when tbl_fin_account / account_id exist alongside account_code.
 *
 * @return array{join:string,code:string,label:string}
 */
function hms_fin_jl_report_sql_fragments(mysqli $connection): array
{
    $join = '';
    $hasJlCode = hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_code');
    $hasJlLabel = hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_label');
    $hasJlAcctId = hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_id');
    $hasFa = hms_db_table_exists($connection, 'tbl_fin_account');
    $faCode = null;
    $faLabel = null;
    if ($hasFa) {
        foreach (['code', 'account_code'] as $c) {
            if (hms_db_column_exists($connection, 'tbl_fin_account', $c)) {
                $faCode = preg_replace('/[^a-zA-Z0-9_]/', '', $c);
                break;
            }
        }
        foreach (['label', 'name', 'account_label', 'title'] as $c) {
            if (hms_db_column_exists($connection, 'tbl_fin_account', $c)) {
                $faLabel = preg_replace('/[^a-zA-Z0-9_]/', '', $c);
                break;
            }
        }
    }
    if ($hasFa && $hasJlAcctId && $faCode !== null && $faCode !== '') {
        $join = ' LEFT JOIN tbl_fin_account fa ON fa.id = jl.account_id ';
    }
    if ($hasJlCode && $join !== '') {
        $code = "COALESCE(NULLIF(TRIM(jl.account_code), ''), fa." . $faCode . ", '')";
    } elseif ($hasJlCode) {
        $code = 'jl.account_code';
    } elseif ($join !== '' && $faCode !== null && $faCode !== '') {
        $code = 'COALESCE(fa.' . $faCode . ", CAST(jl.account_id AS CHAR), '')";
    } else {
        $code = "''";
    }
    if ($hasJlLabel && $join !== '' && $faLabel !== null && $faLabel !== '') {
        $label = "COALESCE(NULLIF(TRIM(jl.account_label), ''), fa." . $faLabel . ", '')";
    } elseif ($hasJlLabel) {
        $label = 'jl.account_label';
    } elseif ($join !== '' && $faLabel !== null && $faLabel !== '') {
        $label = 'COALESCE(fa.' . $faLabel . ", '')";
    } else {
        $label = "''";
    }

    return ['join' => $join, 'code' => $code, 'label' => $label];
}

/**
 * Ensure tbl_fin_journal_line has account_code / account_label (migration 019).
 * If an older stub table existed, CREATE TABLE IF NOT EXISTS would not add these columns.
 */
function hms_fin_journal_line_schema_ensure(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_fin_journal_line')) {
        return false;
    }
    $needCode = !hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_code');
    $needLabel = !hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_label');
    if (!$needCode && !$needLabel) {
        return true;
    }
    if ($needCode) {
        $q = mysqli_query(
            $connection,
            "ALTER TABLE tbl_fin_journal_line ADD COLUMN account_code VARCHAR(32) NOT NULL DEFAULT ''"
        );
        if (!$q && !hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_code')) {
            hms_fin_journal_post_set_last_error('schema: add account_code failed: ' . mysqli_error($connection));

            return false;
        }
    }
    if ($needLabel) {
        $q = mysqli_query(
            $connection,
            "ALTER TABLE tbl_fin_journal_line ADD COLUMN account_label VARCHAR(160) NOT NULL DEFAULT ''"
        );
        if (!$q && !hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_label')) {
            hms_fin_journal_post_set_last_error('schema: add account_label failed: ' . mysqli_error($connection));

            return false;
        }
    }

    return hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_code')
        && hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_label');
}

/**
 * Ensure tbl_fin_journal_line.journal_id references tbl_fin_journal_header (migration 019).
 * Some databases had lines pointing at tbl_fin_journal while the app posts headers to tbl_fin_journal_header.
 */
function hms_fin_journal_line_fk_ensure(mysqli $connection): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    if (!hms_db_table_exists($connection, 'tbl_fin_journal_line')
        || !hms_db_table_exists($connection, 'tbl_fin_journal_header')) {
        return false;
    }
    $q = mysqli_query(
        $connection,
        'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_fin_journal_line' "
        . "AND COLUMN_NAME = 'journal_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    if (!$q) {
        hms_fin_journal_post_set_last_error('schema: could not read journal line FK: ' . mysqli_error($connection));

        return false;
    }
    $hasHeaderFk = false;
    $wrongNames = [];
    $seenWrong = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $ref = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
        $cname = (string) ($row['CONSTRAINT_NAME'] ?? '');
        if ($ref === '' || $cname === '') {
            continue;
        }
        if (strcasecmp($ref, 'tbl_fin_journal_header') === 0) {
            $hasHeaderFk = true;

            continue;
        }
        if (!isset($seenWrong[$cname])) {
            $seenWrong[$cname] = true;
            $wrongNames[] = $cname;
        }
    }
    mysqli_free_result($q);
    if ($wrongNames === []) {
        $done = true;

        return true;
    }
    foreach ($wrongNames as $raw) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $raw);
        if ($safe === '' || $safe !== $raw) {
            hms_fin_journal_post_set_last_error('schema: invalid journal line FK name: ' . $raw);

            return false;
        }
        $drop = mysqli_query($connection, 'ALTER TABLE tbl_fin_journal_line DROP FOREIGN KEY `' . $safe . '`');
        if (!$drop) {
            hms_fin_journal_post_set_last_error('schema: drop journal line FK `' . $safe . '` failed: ' . mysqli_error($connection));

            return false;
        }
    }
    if (!$hasHeaderFk) {
        $add = mysqli_query(
            $connection,
            'ALTER TABLE tbl_fin_journal_line ADD CONSTRAINT fk_fin_jl_j FOREIGN KEY (journal_id) '
            . 'REFERENCES tbl_fin_journal_header (id) ON DELETE CASCADE'
        );
        if (!$add) {
            hms_fin_journal_post_set_last_error('schema: add journal line FK to tbl_fin_journal_header failed: ' . mysqli_error($connection));

            return false;
        }
    }
    $done = true;

    return true;
}

/**
 * Journal lines in migration 019 use account_code / account_label only. Some hosts add account_id → tbl_fin_account;
 * inserts without a chart row then fail FK. Drop those FKs and make account_id nullable so OHADA-by-code posting works.
 */
function hms_fin_journal_line_account_id_ensure(mysqli $connection): bool
{
    static $done = false;
    if ($done) {
        return true;
    }
    if (!hms_db_table_exists($connection, 'tbl_fin_journal_line')) {
        return false;
    }
    if (!hms_db_column_exists($connection, 'tbl_fin_journal_line', 'account_id')) {
        $done = true;

        return true;
    }
    $q = mysqli_query(
        $connection,
        'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_fin_journal_line' "
        . "AND COLUMN_NAME = 'account_id' AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    if (!$q) {
        hms_fin_journal_post_set_last_error('schema: could not read journal line account_id FK: ' . mysqli_error($connection));

        return false;
    }
    $names = [];
    $seen = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $cname = (string) ($row['CONSTRAINT_NAME'] ?? '');
        if ($cname !== '' && !isset($seen[$cname])) {
            $seen[$cname] = true;
            $names[] = $cname;
        }
    }
    mysqli_free_result($q);
    foreach ($names as $raw) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $raw);
        if ($safe === '' || $safe !== $raw) {
            hms_fin_journal_post_set_last_error('schema: invalid account_id FK name: ' . $raw);

            return false;
        }
        $drop = mysqli_query($connection, 'ALTER TABLE tbl_fin_journal_line DROP FOREIGN KEY `' . $safe . '`');
        if (!$drop) {
            hms_fin_journal_post_set_last_error('schema: drop account_id FK `' . $safe . '` failed: ' . mysqli_error($connection));

            return false;
        }
    }
    $ctQ = mysqli_query(
        $connection,
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_fin_journal_line' AND COLUMN_NAME = 'account_id'"
    );
    $colType = 'INT';
    if ($ctQ) {
        $ctRow = mysqli_fetch_assoc($ctQ);
        mysqli_free_result($ctQ);
        $t = (string) ($ctRow['COLUMN_TYPE'] ?? '');
        if ($t !== '' && strpbrk($t, ';`') === false) {
            $colType = $t;
        }
    }
    $mod = mysqli_query(
        $connection,
        'ALTER TABLE tbl_fin_journal_line MODIFY COLUMN account_id ' . $colType . ' NULL DEFAULT NULL'
    );
    if (!$mod) {
        hms_fin_journal_post_set_last_error('schema: make account_id nullable failed: ' . mysqli_error($connection));

        return false;
    }
    $done = true;

    return true;
}

/**
 * Last mysqli / validation message from hms_fin_journal_post (for admin troubleshooting on shared hosts).
 */
function hms_fin_journal_post_last_error(): string
{
    return (string) ($GLOBALS['hms_fin_journal_post_last_error'] ?? '');
}

function hms_fin_journal_post_set_last_error(string $msg): void
{
    $GLOBALS['hms_fin_journal_post_last_error'] = $msg;
}

/**
 * Whether a journal header already exists for (facility, source_type, source_id).
 * Uses plain SQL so it works when mysqli_stmt_get_result / mysqlnd is unavailable (some free hosts).
 *
 * @return int -1 = query error (do not INSERT), 0 = not found, 1 = already exists
 */
function hms_fin_journal_source_lookup(mysqli $connection, int $facilityId, string $sourceType, int $sourceId): int
{
    if ($facilityId < 1 || $sourceId < 1 || $sourceType === '') {
        return 0;
    }
    $fid = (int) $facilityId;
    $sid = (int) $sourceId;
    $st = mysqli_real_escape_string($connection, $sourceType);
    $sql = 'SELECT 1 AS x FROM tbl_fin_journal_header WHERE facility_id = ' . $fid
        . " AND source_type = '" . $st . "' AND source_id = " . $sid . ' LIMIT 1';
    $q = mysqli_query($connection, $sql);
    if (!$q) {
        hms_fin_journal_post_set_last_error('duplicate check query failed: ' . mysqli_error($connection));

        return -1;
    }
    $row = mysqli_fetch_assoc($q);
    mysqli_free_result($q);

    return $row ? 1 : 0;
}

/** Post manual journals / imports (migration 029 adds ACL financials.write). */
function hms_fin_can_write(mysqli $connection): bool
{
    if (empty($_SESSION['name'])) {
        return false;
    }
    if ((string) ($_SESSION['role'] ?? '') === '1') {
        return true;
    }
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'financials.write') || hms_can($connection, 'billing.write');
}

/**
 * Next unique source_id for manual_import journals (pairs with UNIQUE uq_fin_jrnl_src).
 */
function hms_fin_next_manual_source_id(mysqli $connection, int $facilityId): int
{
    if (!hms_fin_tables_ok($connection) || $facilityId < 1) {
        return 1;
    }
    $q = mysqli_query(
        $connection,
        'SELECT COALESCE(MAX(source_id), 0) + 1 AS n FROM tbl_fin_journal_header WHERE facility_id = '
        . (int) $facilityId . " AND source_type = 'manual_import'"
    );
    if (!$q) {
        return (int) (microtime(true) * 1000) % 2000000000;
    }
    $row = mysqli_fetch_assoc($q);
    mysqli_free_result($q);
    $n = (int) ($row['n'] ?? 1);

    return $n > 0 ? $n : 1;
}

/**
 * tbl_fin_journal_header FK requires tbl_facility. Insert a stub site if missing (some DBs skip migration 001).
 */
function hms_fin_ensure_facility_row_for_journal(mysqli $connection, int $facilityId): bool
{
    if ($facilityId < 1 || !hms_db_table_exists($connection, 'tbl_facility')) {
        return true;
    }
    $fid = (int) $facilityId;
    $chk = mysqli_query($connection, 'SELECT id FROM tbl_facility WHERE id = ' . $fid . ' LIMIT 1');
    if ($chk && mysqli_fetch_assoc($chk)) {
        mysqli_free_result($chk);

        return true;
    }
    if ($chk) {
        mysqli_free_result($chk);
    }
    $code = $fid === 1 ? 'MAIN' : ('AUTO' . $fid);
    $name = $fid === 1 ? 'TSSF Solidarity of Hearts Hospital SOA' : ('Hospital site #' . $fid);
    $codeE = mysqli_real_escape_string($connection, substr($code, 0, 32));
    $nameE = mysqli_real_escape_string($connection, substr($name, 0, 250));
    $sql = 'INSERT INTO tbl_facility (id, code, name, status) VALUES (' . $fid . ", '" . $codeE . "', '" . $nameE . "', 1)"
        . ' ON DUPLICATE KEY UPDATE name = VALUES(name)';
    $ok = mysqli_query($connection, $sql);
    if ($ok) {
        return true;
    }
    if (function_exists('error_log')) {
        error_log('hms_fin_ensure_facility_row_for_journal: ' . mysqli_error($connection));
    }

    return false;
}

/**
 * Post a balanced journal entry.
 *
 * @param list<array{code:string,label:string,debit:float,credit:float}> $lines
 * @return int 0 = failure, 1 = new header+lines inserted, 2 = already existed (idempotent)
 */
function hms_fin_journal_post(
    mysqli $connection,
    int $facilityId,
    string $sourceType,
    int $sourceId,
    string $reference,
    string $narration,
    int $createdBy,
    array $lines,
    ?string $entryDate = null
): int {
    hms_fin_journal_post_set_last_error('');
    if (!hms_fin_tables_ok($connection) || $facilityId < 1 || $sourceType === '' || $sourceId < 1 || $lines === []) {
        hms_fin_journal_post_set_last_error('validation: missing table, facility, source, or lines');

        return 0;
    }
    if (!hms_fin_ensure_facility_row_for_journal($connection, $facilityId)) {
        hms_fin_journal_post_set_last_error('tbl_facility row missing for facility_id ' . $facilityId . ' and auto-insert failed.');

        return 0;
    }
    if (!hms_fin_journal_line_schema_ensure($connection)) {
        return 0;
    }
    if (!hms_fin_journal_line_fk_ensure($connection)) {
        return 0;
    }
    if (!hms_fin_journal_line_account_id_ensure($connection)) {
        return 0;
    }
    $dupLookup = hms_fin_journal_source_lookup($connection, $facilityId, $sourceType, $sourceId);
    if ($dupLookup === -1) {
        return 0;
    }
    if ($dupLookup === 1) {
        return 2;
    }

    $sumDr = 0.0;
    $sumCr = 0.0;
    foreach ($lines as $ln) {
        $sumDr += round((float) ($ln['debit'] ?? 0), 2);
        $sumCr += round((float) ($ln['credit'] ?? 0), 2);
    }
    if (abs($sumDr - $sumCr) > 0.02 || $sumDr <= 0) {
        hms_fin_journal_post_set_last_error('validation: lines not balanced or zero amount (dr=' . $sumDr . ' cr=' . $sumCr . ')');

        return 0;
    }

    $ref = substr($reference, 0, 64);
    $nar = substr($narration, 0, 512);
    $ed = ($entryDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) ? $entryDate : date('Y-m-d');
    $uid = max(0, $createdBy);

    if (!mysqli_begin_transaction($connection)) {
        hms_fin_journal_post_set_last_error('BEGIN TRANSACTION failed: ' . mysqli_error($connection));

        return 0;
    }
    try {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_fin_journal_header (facility_id, entry_date, reference, narration, source_type, source_id, created_by) VALUES (?,?,?,?,?,?,?)'
        );
        if (!$st) {
            throw new RuntimeException('journal header prepare failed: ' . mysqli_error($connection));
        }
        mysqli_stmt_bind_param($st, 'issssii', $facilityId, $ed, $ref, $nar, $sourceType, $sourceId, $uid);
        if (!mysqli_stmt_execute($st)) {
            mysqli_stmt_close($st);
            throw new RuntimeException('journal header insert failed: ' . mysqli_error($connection));
        }
        $jid = (int) mysqli_insert_id($connection);
        mysqli_stmt_close($st);
        if ($jid < 1) {
            throw new RuntimeException('journal id missing');
        }

        $insL = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_fin_journal_line (journal_id, account_code, account_label, debit, credit) VALUES (?,?,?,?,?)'
        );
        if (!$insL) {
            throw new RuntimeException('journal line prepare failed: ' . mysqli_error($connection));
        }
        $lineInserts = 0;
        foreach ($lines as $ln) {
            $code = substr((string) ($ln['code'] ?? ''), 0, 32);
            $lab = substr((string) ($ln['label'] ?? ''), 0, 160);
            $dr = round((float) ($ln['debit'] ?? 0), 2);
            $cr = round((float) ($ln['credit'] ?? 0), 2);
            if ($code === '') {
                continue;
            }
            mysqli_stmt_bind_param($insL, 'issdd', $jid, $code, $lab, $dr, $cr);
            if (!mysqli_stmt_execute($insL)) {
                $err = mysqli_stmt_error($insL);
                mysqli_stmt_close($insL);
                throw new RuntimeException('journal line insert failed: ' . $err);
            }
            $lineInserts++;
        }
        mysqli_stmt_close($insL);
        if ($lineInserts < 1) {
            throw new RuntimeException('no journal lines with account codes');
        }
        mysqli_commit($connection);

        return 1;
    } catch (Throwable $e) {
        mysqli_rollback($connection);
        hms_fin_journal_post_set_last_error($e->getMessage());
        if (function_exists('error_log')) {
            error_log('hms_fin_journal_post: ' . $e->getMessage());
        }

        return 0;
    }
}

/**
 * @return array{code:string,label:string}
 */
function hms_fin_cash_like_account(?string $paymentMethod): array
{
    $m = strtolower((string) $paymentMethod);
    // Use strpos (PHP 7.x); str_contains() is PHP 8+ only — some shared hosts still run 7.4.
    if (strpos($m, 'bank') !== false || strpos($m, 'transfer') !== false || strpos($m, 'wire') !== false
        || strpos($m, 'card') !== false || strpos($m, 'mobile') !== false || strpos($m, 'momo') !== false) {
        return ['code' => '521000', 'label' => 'Banks — patient collection'];
    }

    return ['code' => '571000', 'label' => 'Cash — patient collection'];
}

function hms_fin_post_credit_charge_accrual(
    mysqli $connection,
    int $facilityId,
    int $chargeId,
    float $amount,
    int $createdBy,
    string $description
): void {
    if (!hms_fin_tables_ok($connection) || $chargeId < 1 || $amount <= 0) {
        return;
    }
    $amt = round($amount, 2);
    $nar = 'Patient credit accrual · ' . substr(trim($description), 0, 400);
    hms_fin_journal_post(
        $connection,
        $facilityId,
        'credit_charge',
        $chargeId,
        'CR-ACC-' . $chargeId,
        $nar,
        $createdBy,
        [
            ['code' => '411000', 'label' => 'Trade receivables — patients', 'debit' => $amt, 'credit' => 0.0],
            ['code' => '706000', 'label' => 'Healthcare services revenue', 'debit' => 0.0, 'credit' => $amt],
        ],
        null
    );
}

function hms_fin_post_credit_payment_collection(
    mysqli $connection,
    int $facilityId,
    int $creditPaymentId,
    float $amount,
    ?string $paymentMethod,
    int $createdBy,
    string $docNumber,
    string $lineDescription,
    ?string $entryDate = null
): int {
    if (!hms_fin_tables_ok($connection) || $creditPaymentId < 1 || $amount <= 0) {
        return 0;
    }
    $amt = round($amount, 2);
    $cash = hms_fin_cash_like_account($paymentMethod);
    $ref = substr(trim($docNumber) !== '' ? $docNumber : ('CR-PAY-' . $creditPaymentId), 0, 64);
    $nar = 'Patient AR collection · ' . substr(trim($lineDescription), 0, 400);

    return hms_fin_journal_post(
        $connection,
        $facilityId,
        'credit_payment',
        $creditPaymentId,
        $ref,
        $nar,
        $createdBy,
        [
            ['code' => $cash['code'], 'label' => $cash['label'], 'debit' => $amt, 'credit' => 0.0],
            ['code' => '411000', 'label' => 'Trade receivables — patients', 'debit' => 0.0, 'credit' => $amt],
        ],
        $entryDate
    );
}

/**
 * Called from receipt issuance (see receipt_invoice.php).
 * Credit payment receipts → DR cash/bank, CR receivable (entry date = receipt document date).
 * Other fiscal receipts → DR cash/bank, CR revenue.
 * If credit metadata is broken, falls back to cash/revenue so the GL still matches billing.
 *
 * @return int hms_fin_journal_post code: 0 = failure/no-op, 1 = inserted, 2 = already existed
 */
function hms_fin_sync_journal_from_receipt(
    mysqli $connection,
    int $facilityId,
    int $billingDocumentId,
    string $sourceModule,
    float $grandTotal,
    ?string $paymentMethod,
    int $createdBy,
    string $docNumber,
    string $firstLineDescription
): int {
    if (!hms_fin_tables_ok($connection) || $billingDocumentId < 1 || $grandTotal <= 0) {
        return 0;
    }

    $docSt = mysqli_prepare(
        $connection,
        'SELECT DATE(created_at) AS entry_d, source_module FROM tbl_billing_document WHERE id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$docSt) {
        return 0;
    }
    mysqli_stmt_bind_param($docSt, 'ii', $billingDocumentId, $facilityId);
    mysqli_stmt_execute($docSt);
    $docRow = hms_stmt_fetch_assoc($docSt);
    mysqli_stmt_close($docSt);
    if (!$docRow) {
        return 0;
    }
    $receiptDate = (string) ($docRow['entry_d'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        $receiptDate = date('Y-m-d');
    }
    $mod = (string) ($docRow['source_module'] ?? $sourceModule);
    $isCreditDoc = ($mod === 'credit_payment' || $sourceModule === 'credit_payment');

    if ($isCreditDoc) {
        $st = mysqli_prepare(
            $connection,
            'SELECT id, amount, payment_method FROM tbl_credit_payment WHERE id = (SELECT source_pk FROM tbl_billing_document WHERE id = ? AND facility_id = ? LIMIT 1) LIMIT 1'
        );
        $cpRow = null;
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $billingDocumentId, $facilityId);
            mysqli_stmt_execute($st);
            $cpRow = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
        }
        if ($cpRow) {
            $payId = (int) ($cpRow['id'] ?? 0);
            $amt = (float) ($cpRow['amount'] ?? 0);
            $pm = (string) ($cpRow['payment_method'] ?? ($paymentMethod ?? 'Cash'));
            if ($payId >= 1) {
                return hms_fin_post_credit_payment_collection(
                    $connection,
                    $facilityId,
                    $payId,
                    $amt > 0 ? $amt : $grandTotal,
                    $pm,
                    $createdBy,
                    $docNumber,
                    $firstLineDescription,
                    $receiptDate
                );
            }
        }
    }

    $amt = round($grandTotal, 2);
    $cash = hms_fin_cash_like_account($paymentMethod);
    $ref = substr(trim($docNumber) !== '' ? $docNumber : ('RCP-' . $billingDocumentId), 0, 64);
    $nar = 'Patient receipt · ' . substr(trim($firstLineDescription), 0, 400);
    if ($isCreditDoc) {
        $nar = 'Patient receipt (cash/revenue; credit link missing or repaired) · ' . substr(trim($firstLineDescription), 0, 360);
    }

    return hms_fin_journal_post(
        $connection,
        $facilityId,
        'billing_receipt',
        $billingDocumentId,
        $ref,
        $nar,
        $createdBy,
        [
            ['code' => $cash['code'], 'label' => $cash['label'], 'debit' => $amt, 'credit' => 0.0],
            ['code' => '706000', 'label' => 'Healthcare services revenue', 'debit' => 0.0, 'credit' => $amt],
        ],
        $receiptDate
    );
}

/**
 * Post operating expense to GL: DR expense (class 6), CR cash/bank (class 5).
 * Idempotent via (facility_id, source_type=expense, source_id).
 */
function hms_fin_post_expense_to_gl(
    mysqli $connection,
    int $facilityId,
    int $expenseId,
    string $expenseDate,
    int $amountXaf,
    ?string $paymentMethod,
    string $category,
    string $description,
    int $createdBy
): int {
    if (!hms_fin_tables_ok($connection) || $facilityId < 1 || $expenseId < 1 || $amountXaf < 1) {
        return 0;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        return 0;
    }
    $amt = round((float) $amountXaf, 2);
    $cash = hms_fin_cash_like_account($paymentMethod);
    $cat = substr(trim($category) !== '' ? $category : 'General', 0, 80);
    $desc = substr(trim($description), 0, 200);
    $nar = 'Expense · ' . $cat . ($desc !== '' ? (' — ' . $desc) : '');
    $ref = 'EXP-' . $expenseId;

    return hms_fin_journal_post(
        $connection,
        $facilityId,
        'expense',
        $expenseId,
        $ref,
        substr($nar, 0, 512),
        $createdBy,
        [
            ['code' => '601000', 'label' => 'Operating expenses — ' . $cat, 'debit' => $amt, 'credit' => 0.0],
            ['code' => $cash['code'], 'label' => $cash['label'], 'debit' => 0.0, 'credit' => $amt],
        ],
        $expenseDate
    );
}

/**
 * Backfill GL from issued fiscal receipts (for history before sync was enabled). Processes up to $limit rows.
 *
 * @return array{processed:int, inserted:int, duplicate:int, failed:int}
 */
function hms_fin_backfill_receipt_journals(mysqli $connection, int $facilityId, int $limit = 500): array
{
    if (!hms_fin_tables_ok($connection) || !function_exists('hms_billing_document_tables_ok') || !hms_billing_document_tables_ok($connection)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    $fid = (int) $facilityId;
    $lim = max(1, min(2000, $limit));
    $sql = 'SELECT d.id, d.source_module, d.total_amount, d.payment_method, d.created_by, d.doc_number,
        (SELECT l.description FROM tbl_billing_document_line l WHERE l.document_id = d.id ORDER BY l.sort_order, l.id LIMIT 1) AS first_line
        FROM tbl_billing_document d
        WHERE d.facility_id = ' . $fid . " AND d.doc_type = 'receipt' AND d.total_amount > 0.005
        ORDER BY d.id ASC
        LIMIT " . $lim;
    $rows = [];
    $q = mysqli_query($connection, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = $row;
        }
        mysqli_free_result($q);
    }
    $inserted = 0;
    $duplicate = 0;
    $failed = 0;
    $firstErr = '';
    foreach ($rows as $r) {
        $code = hms_fin_sync_journal_from_receipt(
            $connection,
            $fid,
            (int) ($r['id'] ?? 0),
            (string) ($r['source_module'] ?? ''),
            (float) ($r['total_amount'] ?? 0),
            isset($r['payment_method']) ? (string) $r['payment_method'] : null,
            (int) ($r['created_by'] ?? 0),
            (string) ($r['doc_number'] ?? ''),
            (string) ($r['first_line'] ?? 'Payment')
        );
        if ($code === 1) {
            $inserted++;
        } elseif ($code === 2) {
            $duplicate++;
        } else {
            $failed++;
            if ($firstErr === '' && function_exists('hms_fin_journal_post_last_error')) {
                $firstErr = hms_fin_journal_post_last_error();
            }
        }
    }

    return [
        'processed' => count($rows),
        'inserted' => $inserted,
        'duplicate' => $duplicate,
        'failed' => $failed,
        'first_error' => $firstErr,
    ];
}

/**
 * Backfill only fiscal receipts whose document date (created_at) falls in the range.
 * Use this when the global backfill (oldest id first) has not yet reached recent receipts.
 *
 * @return array{processed:int, inserted:int, duplicate:int, failed:int}
 */
function hms_fin_backfill_receipt_journals_for_date_range(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo,
    int $limit = 3000
): array {
    if (!hms_fin_tables_ok($connection) || !function_exists('hms_billing_document_tables_ok') || !hms_billing_document_tables_ok($connection)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    $fid = (int) $facilityId;
    $df = mysqli_real_escape_string($connection, $dateFrom);
    $dt = mysqli_real_escape_string($connection, $dateTo);
    $lim = max(1, min(5000, $limit));
    $sql = 'SELECT d.id, d.source_module, d.total_amount, d.payment_method, d.created_by, d.doc_number,
        (SELECT l.description FROM tbl_billing_document_line l WHERE l.document_id = d.id ORDER BY l.sort_order, l.id LIMIT 1) AS first_line
        FROM tbl_billing_document d
        WHERE d.facility_id = ' . $fid . " AND d.doc_type = 'receipt' AND d.total_amount > 0.005
        AND DATE(d.created_at) BETWEEN '" . $df . "' AND '" . $dt . "'
        ORDER BY d.id ASC
        LIMIT " . $lim;
    $rows = [];
    $q = mysqli_query($connection, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = $row;
        }
        mysqli_free_result($q);
    }
    $inserted = 0;
    $duplicate = 0;
    $failed = 0;
    $firstErr = '';
    foreach ($rows as $r) {
        $code = hms_fin_sync_journal_from_receipt(
            $connection,
            $fid,
            (int) ($r['id'] ?? 0),
            (string) ($r['source_module'] ?? ''),
            (float) ($r['total_amount'] ?? 0),
            isset($r['payment_method']) ? (string) $r['payment_method'] : null,
            (int) ($r['created_by'] ?? 0),
            (string) ($r['doc_number'] ?? ''),
            (string) ($r['first_line'] ?? 'Payment')
        );
        if ($code === 1) {
            $inserted++;
        } elseif ($code === 2) {
            $duplicate++;
        } else {
            $failed++;
            if ($firstErr === '' && function_exists('hms_fin_journal_post_last_error')) {
                $firstErr = hms_fin_journal_post_last_error();
            }
        }
    }

    return [
        'processed' => count($rows),
        'inserted' => $inserted,
        'duplicate' => $duplicate,
        'failed' => $failed,
        'first_error' => $firstErr,
    ];
}

/**
 * Backfill GL from tbl_expense (for expenses recorded before posting was enabled).
 *
 * @return array{processed:int, inserted:int, duplicate:int, failed:int}
 */
function hms_fin_backfill_expense_journals(mysqli $connection, int $facilityId, int $limit = 500): array
{
    if (!hms_fin_tables_ok($connection) || !hms_db_table_exists($connection, 'tbl_expense')) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    $fid = (int) $facilityId;
    $lim = max(1, min(2000, $limit));
    $sql = 'SELECT id, expense_date, amount_xaf, payment_method, category, description, created_by
        FROM tbl_expense WHERE facility_id = ' . $fid . ' ORDER BY id ASC LIMIT ' . $lim;
    $rows = [];
    $q = mysqli_query($connection, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = $row;
        }
        mysqli_free_result($q);
    }
    $inserted = 0;
    $duplicate = 0;
    $failed = 0;
    foreach ($rows as $r) {
        $code = hms_fin_post_expense_to_gl(
            $connection,
            $fid,
            (int) ($r['id'] ?? 0),
            (string) ($r['expense_date'] ?? date('Y-m-d')),
            (int) ($r['amount_xaf'] ?? 0),
            isset($r['payment_method']) ? (string) $r['payment_method'] : null,
            (string) ($r['category'] ?? ''),
            (string) ($r['description'] ?? ''),
            (int) ($r['created_by'] ?? 0)
        );
        if ($code === 1) {
            $inserted++;
        } elseif ($code === 2) {
            $duplicate++;
        } else {
            $failed++;
        }
    }

    return [
        'processed' => count($rows),
        'inserted' => $inserted,
        'duplicate' => $duplicate,
        'failed' => $failed,
    ];
}

/**
 * @return array{processed:int, inserted:int, duplicate:int, failed:int}
 */
function hms_fin_backfill_expense_journals_for_date_range(
    mysqli $connection,
    int $facilityId,
    string $dateFrom,
    string $dateTo,
    int $limit = 3000
): array {
    if (!hms_fin_tables_ok($connection) || !hms_db_table_exists($connection, 'tbl_expense')) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0, 'first_error' => ''];
    }
    $fid = (int) $facilityId;
    $df = mysqli_real_escape_string($connection, $dateFrom);
    $dt = mysqli_real_escape_string($connection, $dateTo);
    $lim = max(1, min(5000, $limit));
    $sql = 'SELECT id, expense_date, amount_xaf, payment_method, category, description, created_by
        FROM tbl_expense WHERE facility_id = ' . $fid . " AND expense_date BETWEEN '" . $df . "' AND '" . $dt . "'
        ORDER BY id ASC LIMIT " . $lim;
    $rows = [];
    $q = mysqli_query($connection, $sql);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $rows[] = $row;
        }
        mysqli_free_result($q);
    }
    $inserted = 0;
    $duplicate = 0;
    $failed = 0;
    foreach ($rows as $r) {
        $code = hms_fin_post_expense_to_gl(
            $connection,
            $fid,
            (int) ($r['id'] ?? 0),
            (string) ($r['expense_date'] ?? date('Y-m-d')),
            (int) ($r['amount_xaf'] ?? 0),
            isset($r['payment_method']) ? (string) $r['payment_method'] : null,
            (string) ($r['category'] ?? ''),
            (string) ($r['description'] ?? ''),
            (int) ($r['created_by'] ?? 0)
        );
        if ($code === 1) {
            $inserted++;
        } elseif ($code === 2) {
            $duplicate++;
        } else {
            $failed++;
        }
    }

    return [
        'processed' => count($rows),
        'inserted' => $inserted,
        'duplicate' => $duplicate,
        'failed' => $failed,
    ];
}

/**
 * Manual / imported journal (balanced lines, OHADA account codes).
 *
 * @param list<array{code:string,label:string,debit:float,credit:float}> $lines
 */
function hms_fin_journal_post_manual(
    mysqli $connection,
    int $facilityId,
    string $entryDate,
    string $reference,
    string $narration,
    int $createdBy,
    array $lines
): bool {
    if (!hms_fin_tables_ok($connection) || $lines === []) {
        return false;
    }
    $sid = hms_fin_next_manual_source_id($connection, $facilityId);

    $r = hms_fin_journal_post(
        $connection,
        $facilityId,
        'manual_import',
        $sid,
        $reference,
        $narration,
        $createdBy,
        $lines,
        $entryDate
    );

    return $r === 1 || $r === 2;
}
