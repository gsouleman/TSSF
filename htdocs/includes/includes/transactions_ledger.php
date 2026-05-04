<?php
declare(strict_types=1);

/**
 * Mirror patient fiscal receipts into tbl_transaction (Transactions workspace).
 * Idempotent when column billing_document_id exists (migration 017).
 */

function hms_txn_ledger_amount_string(float $amt): string
{
    if (!is_finite($amt) || $amt <= 0) {
        return '0.00';
    }

    return number_format(min($amt, 1e15), 2, '.', '');
}

/**
 * After a patient receipt (tbl_billing_document) is issued, append one ledger row.
 * Skips when the document was created from an existing manual transaction (source_module = transaction).
 */
function hms_transaction_sync_from_receipt_document(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $billingDocumentId,
    ?string $paymentMethod,
    float $grandTotal,
    int $createdBy,
    string $docNumber,
    string $firstLineDescription
): void {
    if (!hms_db_table_exists($connection, 'tbl_transaction') || $patientId < 1 || $billingDocumentId < 1 || $grandTotal <= 0) {
        return;
    }
    $hasFac = hms_db_column_exists($connection, 'tbl_transaction', 'facility_id');
    $hasCreated = hms_db_column_exists($connection, 'tbl_transaction', 'created_by');
    $hasBdoc = hms_db_column_exists($connection, 'tbl_transaction', 'billing_document_id');
    if (!$hasFac || !$hasCreated) {
        return;
    }

    if ($hasBdoc) {
        $dup = mysqli_prepare($connection, 'SELECT id FROM tbl_transaction WHERE billing_document_id = ? LIMIT 1');
        if ($dup) {
            mysqli_stmt_bind_param($dup, 'i', $billingDocumentId);
            mysqli_stmt_execute($dup);
            $exists = (bool) hms_stmt_fetch_assoc($dup);
            mysqli_stmt_close($dup);
            if ($exists) {
                return;
            }
        }
    }

    $pay = hms_billing_normalize_payment_method($paymentMethod ?? 'Cash');
    $desc = trim($firstLineDescription);
    if ($desc === '') {
        $desc = 'Payment';
    }
    $suffix = trim($docNumber) !== '' ? (' · ' . trim($docNumber)) : '';
    $desc = substr($desc . $suffix, 0, 500);
    $amtStr = hms_txn_ledger_amount_string($grandTotal);
    $status = 'completed';
    $tdate = date('Y-m-d');
    $uid = max(0, $createdBy);

    try {
        if ($hasBdoc) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_transaction (facility_id, patient_id, description, amount, payment_method, status, transaction_date, created_by, billing_document_id) VALUES (?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'iisssssii', $facilityId, $patientId, $desc, $amtStr, $pay, $status, $tdate, $uid, $billingDocumentId);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_transaction (facility_id, patient_id, description, amount, payment_method, status, transaction_date, created_by) VALUES (?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'iisssssi', $facilityId, $patientId, $desc, $amtStr, $pay, $status, $tdate, $uid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('error_log')) {
            error_log('hms_transaction_sync_from_receipt_document: ' . $e->getMessage());
        }
    }
}
