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
    if (!hms_fin_tables_ok($connection) || $facilityId < 1 || $sourceType === '' || $sourceId < 1 || $lines === []) {
        return 0;
    }
    $dup = mysqli_prepare(
        $connection,
        'SELECT id FROM tbl_fin_journal_header WHERE facility_id = ? AND source_type = ? AND source_id = ? LIMIT 1'
    );
    if ($dup) {
        mysqli_stmt_bind_param($dup, 'isi', $facilityId, $sourceType, $sourceId);
        mysqli_stmt_execute($dup);
        $exists = (bool) hms_stmt_fetch_assoc($dup);
        mysqli_stmt_close($dup);
        if ($exists) {
            return 2;
        }
    }

    $sumDr = 0.0;
    $sumCr = 0.0;
    foreach ($lines as $ln) {
        $sumDr += round((float) ($ln['debit'] ?? 0), 2);
        $sumCr += round((float) ($ln['credit'] ?? 0), 2);
    }
    if (abs($sumDr - $sumCr) > 0.02 || $sumDr <= 0) {
        return 0;
    }

    $ref = substr($reference, 0, 64);
    $nar = substr($narration, 0, 512);
    $ed = ($entryDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) ? $entryDate : date('Y-m-d');
    $uid = max(0, $createdBy);

    mysqli_begin_transaction($connection);
    try {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_fin_journal_header (facility_id, entry_date, reference, narration, source_type, source_id, created_by) VALUES (?,?,?,?,?,?,?)'
        );
        if (!$st) {
            throw new RuntimeException('journal header prepare failed');
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
            throw new RuntimeException('journal line prepare failed');
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
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
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
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
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
 * Backfill GL from tbl_expense (for expenses recorded before posting was enabled).
 *
 * @return array{processed:int, inserted:int, duplicate:int, failed:int}
 */
function hms_fin_backfill_expense_journals(mysqli $connection, int $facilityId, int $limit = 500): array
{
    if (!hms_fin_tables_ok($connection) || !hms_db_table_exists($connection, 'tbl_expense')) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
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
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        return ['processed' => 0, 'inserted' => 0, 'duplicate' => 0, 'failed' => 0];
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
