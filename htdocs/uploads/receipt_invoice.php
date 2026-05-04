<?php
declare(strict_types=1);

/**
 * Receipts & invoices (tbl_billing_document) — issue, lookup, numbering.
 */

function hms_billing_document_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_billing_document')
        && hms_db_table_exists($connection, 'tbl_billing_document_line');
}

function hms_billing_doc_prefix(string $docType): string
{
    return strtolower($docType) === 'invoice' ? 'INV' : 'RCP';
}

function hms_billing_next_doc_number(mysqli $connection, int $facilityId, string $docType): string
{
    $prefix = hms_billing_doc_prefix($docType);
    $year = (int) date('Y');
    $like = $prefix . '-' . $year . '-%';
    $maxSeq = 0;
    $st = mysqli_prepare(
        $connection,
        'SELECT doc_number FROM tbl_billing_document WHERE facility_id = ? AND doc_number LIKE ? ORDER BY id DESC LIMIT 50'
    );
    if ($st) {
        mysqli_stmt_bind_param($st, 'is', $facilityId, $like);
        mysqli_stmt_execute($st);
        if (function_exists('mysqli_stmt_get_result')) {
            $res = mysqli_stmt_get_result($st);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $num = (string) ($row['doc_number'] ?? '');
                    if (preg_match('/^' . preg_quote($prefix . '-' . $year . '-', '/') . '(\d+)$/', $num, $m)) {
                        $maxSeq = max($maxSeq, (int) $m[1]);
                    }
                }
                mysqli_free_result($res);
            }
        }
        mysqli_stmt_close($st);
    }
    $next = $maxSeq + 1;

    return $prefix . '-' . $year . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/**
 * Find existing document by source (for idempotency).
 */
function hms_billing_find_by_source(mysqli $connection, int $facilityId, string $sourceModule, int $sourcePk): ?array
{
    if ($sourceModule === '' || $sourcePk < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT * FROM tbl_billing_document WHERE facility_id = ? AND source_module = ? AND source_pk = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'isi', $facilityId, $sourceModule, $sourcePk);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $row;
}

/**
 * Load patient display name for snapshot.
 */
function hms_billing_patient_snapshot(mysqli $connection, int $patientId, int $facilityId, bool $multiSite): string
{
    if ($patientId < 1) {
        return '';
    }
    $sql = $multiSite
        ? 'SELECT first_name, last_name FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1'
        : 'SELECT first_name, last_name FROM tbl_patient WHERE id = ? LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if (!$st) {
        return '';
    }
    if ($multiSite) {
        mysqli_stmt_bind_param($st, 'ii', $patientId, $facilityId);
    } else {
        mysqli_stmt_bind_param($st, 'i', $patientId);
    }
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$r) {
        return '';
    }

    return trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
}

/**
 * @param list<array{description:string,quantity?:float,unit_price?:float}> $lines
 * @param array{
 *   facility_id:int,
 *   patient_id:int,
 *   doc_type?:string,
 *   payment_method?:string|null,
 *   source_module:string,
 *   source_pk:int,
 *   charge_id?:int|null,
 *   transaction_id?:int|null,
 *   consultation_id?:int|null,
 *   prescription_id?:int|null,
 *   prescription_line_id?:int|null,
 *   lab_result_id?:int|null,
 *   company_id?:int|null,
 *   company_snapshot?:string|null,
 *   tax_amount?:float,
 *   notes?:string|null,
 *   created_by:int,
 *   payer_snapshot?:string|null,
 *   skip_if_exists?:bool,
 *   opd_visit_id?:int,
 *   facility_admission_id?:int,
 *   hospitalization_id?:int
 * } $opts
 * @return int|false New document id
 */
function hms_billing_document_episode_columns_ok(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_billing_document', 'opd_visit_id')
        && hms_db_column_exists($connection, 'tbl_billing_document', 'facility_admission_id')
        && hms_db_column_exists($connection, 'tbl_billing_document', 'hospitalization_id');
}

function hms_billing_create_document(mysqli $connection, array $opts, array $lines)
{
    if (!hms_billing_document_tables_ok($connection)) {
        return false;
    }
    $fid = (int) ($opts['facility_id'] ?? 0);
    $pid = (int) ($opts['patient_id'] ?? 0);
    $docType = strtolower((string) ($opts['doc_type'] ?? 'receipt')) === 'invoice' ? 'invoice' : 'receipt';
    $sourceModule = (string) ($opts['source_module'] ?? '');
    $sourcePk = (int) ($opts['source_pk'] ?? 0);
    $skip = (bool) ($opts['skip_if_exists'] ?? true);
    if ($skip && $sourceModule !== '' && $sourcePk > 0) {
        $ex = hms_billing_find_by_source($connection, $fid, $sourceModule, $sourcePk);
        if ($ex) {
            return (int) $ex['id'];
        }
    }
    $ms = function_exists('hms_multi_site_enabled') ? hms_multi_site_enabled($connection) : false;
    $payerSnap = (string) ($opts['payer_snapshot'] ?? '');
    if ($payerSnap === '' && $pid > 0) {
        $payerSnap = hms_billing_patient_snapshot($connection, $pid, $fid, $ms);
    }
    $companyId = isset($opts['company_id']) ? (int) $opts['company_id'] : 0;
    $companySnap = (string) ($opts['company_snapshot'] ?? '');
    if ($docType === 'invoice' && $companyId > 0 && $companySnap === '') {
        $cq = mysqli_prepare($connection, 'SELECT name FROM tbl_billing_company WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($cq) {
            mysqli_stmt_bind_param($cq, 'ii', $companyId, $fid);
            mysqli_stmt_execute($cq);
            $cr = hms_stmt_fetch_assoc($cq);
            mysqli_stmt_close($cq);
            if ($cr) {
                $companySnap = (string) ($cr['name'] ?? '');
            }
        }
    }
    $total = 0.0;
    $normLines = [];
    $so = 0;
    foreach ($lines as $ln) {
        $desc = trim((string) ($ln['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $qty = (float) ($ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = (float) ($ln['unit_price'] ?? 0);
        $lt = round($qty * $unit, 2);
        $total += $lt;
        $normLines[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $unit, 'line_total' => $lt, 'sort_order' => $so++];
    }
    if ($companyId > 0 || $companySnap !== '') {
        $tax = round($total * 0.1925, 2);
    } else {
        $tax = 0.0;
    }
    $grand = round($total + $tax, 2);
    if ($grand <= 0) {
        return false;
    }
    $docNumber = hms_billing_next_doc_number($connection, $fid, $docType);
    $payMethod = isset($opts['payment_method']) ? (string) $opts['payment_method'] : null;
    if ($payMethod === '') {
        $payMethod = null;
    }
    $chargeId = isset($opts['charge_id']) ? (int) $opts['charge_id'] : null;
    $txnId = isset($opts['transaction_id']) ? (int) $opts['transaction_id'] : null;
    $consId = isset($opts['consultation_id']) ? (int) $opts['consultation_id'] : null;
    $rxId = isset($opts['prescription_id']) ? (int) $opts['prescription_id'] : null;
    $rxLineId = isset($opts['prescription_line_id']) ? (int) $opts['prescription_line_id'] : null;
    $labRid = isset($opts['lab_result_id']) ? (int) $opts['lab_result_id'] : null;
    $notes = isset($opts['notes']) ? (string) $opts['notes'] : null;
    if ($notes === '') {
        $notes = null;
    }
    $uid = (int) ($opts['created_by'] ?? 0);
    $patientBind = $pid > 0 ? $pid : null;
    $companyBind = $companyId > 0 ? $companyId : null;
    $chargeBind = $chargeId !== null && $chargeId > 0 ? $chargeId : null;
    $txnBind = $txnId !== null && $txnId > 0 ? $txnId : null;
    $consBind = $consId !== null && $consId > 0 ? $consId : null;
    $rxBind = $rxId !== null && $rxId > 0 ? $rxId : null;
    $rxLineBind = $rxLineId !== null && $rxLineId > 0 ? $rxLineId : null;
    $labBind = $labRid !== null && $labRid > 0 ? $labRid : null;

    $epCols = hms_billing_document_episode_columns_ok($connection);
    $opdVisitEp = (int) ($opts['opd_visit_id'] ?? 0);
    $faEp = (int) ($opts['facility_admission_id'] ?? 0);
    $hospEp = (int) ($opts['hospitalization_id'] ?? 0);
    $companyOnlyPatientless = ($docType === 'invoice' && $pid < 1);
    if ($epCols && $pid > 0 && !$companyOnlyPatientless) {
        if ($opdVisitEp < 1 && $faEp < 1 && $hospEp < 1 && function_exists('hms_facility_admission_ensure_walkin_open')) {
            $faEp = (int) hms_facility_admission_ensure_walkin_open($connection, $fid, $pid, $uid);
        }
        $needFaAnchor = function_exists('hms_facility_admission_tables_ok') && hms_facility_admission_tables_ok($connection);
        if ($needFaAnchor && $opdVisitEp < 1 && $faEp < 1 && $hospEp < 1) {
            return false;
        }
    }

    $sql = 'INSERT INTO tbl_billing_document (
        facility_id, doc_type, doc_number, patient_id, company_id, payer_snapshot, company_snapshot,
        total_amount, tax_amount, payment_method, status, source_module, source_pk,
        charge_id, transaction_id, consultation_id, prescription_id, prescription_line_id, lab_result_id,
        notes, created_by
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $st = mysqli_prepare($connection, $sql);
    if (!$st) {
        return false;
    }
    $status = 'issued';
    mysqli_stmt_bind_param(
        $st,
        'issiissddsssiiiiiiisi',
        $fid,
        $docType,
        $docNumber,
        $patientBind,
        $companyBind,
        $payerSnap,
        $companySnap,
        $grand,
        $tax,
        $payMethod,
        $status,
        $sourceModule,
        $sourcePk,
        $chargeBind,
        $txnBind,
        $consBind,
        $rxBind,
        $rxLineBind,
        $labBind,
        $notes,
        $uid
    );
    $ok = mysqli_stmt_execute($st);
    if (!$ok) {
        mysqli_stmt_close($st);

        return false;
    }
    $newId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($st);

    if ($docType === 'receipt' && $newId > 0 && function_exists('hms_ar_ap_touch_receipt_settlement')) {
        hms_ar_ap_touch_receipt_settlement($connection, $newId);
    }

    if ($epCols && $pid > 0 && !$companyOnlyPatientless && ($opdVisitEp > 0 || $faEp > 0 || $hospEp > 0)) {
        $stEp = mysqli_prepare(
            $connection,
            'UPDATE tbl_billing_document SET
                opd_visit_id = (CASE WHEN ? > 0 THEN ? ELSE NULL END),
                facility_admission_id = (CASE WHEN ? > 0 THEN ? ELSE NULL END),
                hospitalization_id = (CASE WHEN ? > 0 THEN ? ELSE NULL END)
             WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        if ($stEp) {
            mysqli_stmt_bind_param(
                $stEp,
                'iiiiiiii',
                $opdVisitEp,
                $opdVisitEp,
                $faEp,
                $faEp,
                $hospEp,
                $hospEp,
                $newId,
                $fid
            );
            mysqli_stmt_execute($stEp);
            mysqli_stmt_close($stEp);
        }
    }

    $insL = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_billing_document_line (document_id, sort_order, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)'
    );
    if ($insL) {
        foreach ($normLines as $nl) {
            $d0 = $newId;
            $so = (int) $nl['sort_order'];
            $dsc = $nl['description'];
            $q = $nl['quantity'];
            $u = $nl['unit_price'];
            $lt = $nl['line_total'];
            mysqli_stmt_bind_param($insL, 'iisddd', $d0, $so, $dsc, $q, $u, $lt);
            mysqli_stmt_execute($insL);
        }
        mysqli_stmt_close($insL);
    }
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'billing.document.' . $docType, 'billing_document', $newId);
    }

    if (
        $docType === 'receipt'
        && $pid > 0
        && $grand > 0
        && ($txnBind === null || $txnBind === 0)
        && $sourceModule !== 'transaction'
        && function_exists('hms_transaction_sync_from_receipt_document')
    ) {
        $firstLine = $normLines[0]['description'] ?? 'Payment';
        hms_transaction_sync_from_receipt_document(
            $connection,
            $fid,
            $pid,
            $newId,
            $payMethod,
            $grand,
            $uid,
            $docNumber,
            (string) $firstLine
        );
        if (function_exists('hms_fin_sync_journal_from_receipt')) {
            hms_fin_sync_journal_from_receipt(
                $connection,
                $fid,
                $newId,
                $sourceModule,
                $grand,
                $payMethod,
                $uid,
                $docNumber,
                (string) $firstLine
            );
        }
    }

    return $newId;
}

function hms_billing_set_print_prompt(int $documentId): void
{
    if ($documentId > 0) {
        $_SESSION['hms_prompt_print_doc_id'] = $documentId;
    }
}

/** Consume one-time “open receipt” prompt (returns document id or 0). */
function hms_billing_take_print_prompt(): int
{
    $id = (int) ($_SESSION['hms_prompt_print_doc_id'] ?? 0);
    if ($id > 0) {
        unset($_SESSION['hms_prompt_print_doc_id']);
    }

    return $id;
}

/**
 * @return array{doc:array<string,mixed>,lines:list<array<string,mixed>>}|null
 */
function hms_billing_get_document_with_lines(mysqli $connection, int $documentId, int $facilityId): ?array
{
    if (!hms_billing_document_tables_ok($connection) || $documentId < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT * FROM tbl_billing_document WHERE id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $documentId, $facilityId);
    mysqli_stmt_execute($st);
    $doc = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$doc) {
        return null;
    }
    $lines = [];
    $did = (int) $documentId;
    $lq2 = mysqli_query(
        $connection,
        'SELECT * FROM tbl_billing_document_line WHERE document_id = ' . $did . ' ORDER BY sort_order, id'
    );
    while ($lq2 && $lr = mysqli_fetch_assoc($lq2)) {
        $lines[] = $lr;
    }

    if (trim((string) ($doc['source_module'] ?? '')) === 'payment_ticket'
        && (int) ($doc['source_pk'] ?? 0) > 0
        && function_exists('hms_payment_ticket_tables_ok')
        && hms_payment_ticket_tables_ok($connection)) {
        $tidPk = (int) $doc['source_pk'];
        $tcq = mysqli_prepare(
            $connection,
            'SELECT ticket_code FROM tbl_payment_ticket WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        if ($tcq) {
            mysqli_stmt_bind_param($tcq, 'ii', $tidPk, $facilityId);
            mysqli_stmt_execute($tcq);
            $tcr = hms_stmt_fetch_assoc($tcq);
            mysqli_stmt_close($tcq);
            $tc = trim((string) ($tcr['ticket_code'] ?? ''));
            if ($tc !== '') {
                $doc['payment_ticket_code'] = $tc;
            }
        }
    }

    return ['doc' => $doc, 'lines' => $lines];
}
