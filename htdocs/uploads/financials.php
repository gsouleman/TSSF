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
 * @param list<array{code:string,label:string,debit:float,credit:float}> $lines
 */
function hms_fin_journal_post(
    mysqli $connection,
    int $facilityId,
    string $sourceType,
    int $sourceId,
    string $reference,
    string $narration,
    int $createdBy,
    array $lines
): bool {
    if (!hms_fin_tables_ok($connection) || $facilityId < 1 || $sourceType === '' || $sourceId < 1 || $lines === []) {
        return false;
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
            return true;
        }
    }

    $sumDr = 0.0;
    $sumCr = 0.0;
    foreach ($lines as $ln) {
        $sumDr += round((float) ($ln['debit'] ?? 0), 2);
        $sumCr += round((float) ($ln['credit'] ?? 0), 2);
    }
    if (abs($sumDr - $sumCr) > 0.02 || $sumDr <= 0) {
        return false;
    }

    $ref = substr($reference, 0, 64);
    $nar = substr($narration, 0, 512);
    $ed = date('Y-m-d');
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
            throw new RuntimeException('journal header insert failed');
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
        foreach ($lines as $ln) {
            $code = substr((string) ($ln['code'] ?? ''), 0, 32);
            $lab = substr((string) ($ln['label'] ?? ''), 0, 160);
            $dr = round((float) ($ln['debit'] ?? 0), 2);
            $cr = round((float) ($ln['credit'] ?? 0), 2);
            if ($code === '') {
                continue;
            }
            mysqli_stmt_bind_param($insL, 'issdd', $jid, $code, $lab, $dr, $cr);
            mysqli_stmt_execute($insL);
        }
        mysqli_stmt_close($insL);
        mysqli_commit($connection);

        return true;
    } catch (Throwable $e) {
        mysqli_rollback($connection);
        if (function_exists('error_log')) {
            error_log('hms_fin_journal_post: ' . $e->getMessage());
        }

        return false;
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
        return ['code' => '521000', 'label' => 'Banques — encaissement patient'];
    }

    return ['code' => '571000', 'label' => 'Caisse — encaissement patient'];
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
            ['code' => '411000', 'label' => 'Clients — créances patients', 'debit' => $amt, 'credit' => 0.0],
            ['code' => '706000', 'label' => 'Prestations de services', 'debit' => 0.0, 'credit' => $amt],
        ]
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
    string $lineDescription
): void {
    if (!hms_fin_tables_ok($connection) || $creditPaymentId < 1 || $amount <= 0) {
        return;
    }
    $amt = round($amount, 2);
    $cash = hms_fin_cash_like_account($paymentMethod);
    $ref = substr(trim($docNumber) !== '' ? $docNumber : ('CR-PAY-' . $creditPaymentId), 0, 64);
    $nar = 'Patient AR collection · ' . substr(trim($lineDescription), 0, 400);
    hms_fin_journal_post(
        $connection,
        $facilityId,
        'credit_payment',
        $creditPaymentId,
        $ref,
        $nar,
        $createdBy,
        [
            ['code' => $cash['code'], 'label' => $cash['label'], 'debit' => $amt, 'credit' => 0.0],
            ['code' => '411000', 'label' => 'Clients — créances patients', 'debit' => 0.0, 'credit' => $amt],
        ]
    );
}

/**
 * Called from receipt issuance (see receipt_invoice.php).
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
): void {
    if (!hms_fin_tables_ok($connection) || $billingDocumentId < 1 || $grandTotal <= 0) {
        return;
    }
    if ($sourceModule !== 'credit_payment') {
        return;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id, amount, payment_method FROM tbl_credit_payment WHERE id = (SELECT source_pk FROM tbl_billing_document WHERE id = ? AND facility_id = ? LIMIT 1) LIMIT 1'
    );
    if (!$st) {
        return;
    }
    mysqli_stmt_bind_param($st, 'ii', $billingDocumentId, $facilityId);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$row) {
        return;
    }
    $payId = (int) ($row['id'] ?? 0);
    $amt = (float) ($row['amount'] ?? 0);
    $pm = (string) ($row['payment_method'] ?? ($paymentMethod ?? 'Cash'));
    if ($payId < 1) {
        return;
    }
    hms_fin_post_credit_payment_collection(
        $connection,
        $facilityId,
        $payId,
        $amt > 0 ? $amt : $grandTotal,
        $pm,
        $createdBy,
        $docNumber,
        $firstLineDescription
    );
}
