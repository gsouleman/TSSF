<?php
declare(strict_types=1);

require_once __DIR__ . '/patient_insurance.php';

/**
 * Payment tickets — cashier lookup codes bundling consultation / lab / radiology / other lines.
 */

function hms_payment_ticket_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_payment_ticket');
}

function hms_payment_ticket_normalize_code(string $raw): string
{
    $s = strtoupper(preg_replace('/\s+/', '', trim($raw)));

    return $s;
}

function hms_payment_ticket_next_code(mysqli $connection, int $facilityId): string
{
    $year = (int) date('Y');
    $prefix = 'PAY-' . $year . '-';
    $like = $prefix . '%';
    $maxSeq = 0;
    $st = mysqli_prepare(
        $connection,
        'SELECT ticket_code FROM tbl_payment_ticket WHERE facility_id = ? AND ticket_code LIKE ? ORDER BY id DESC LIMIT 80'
    );
    if ($st) {
        mysqli_stmt_bind_param($st, 'is', $facilityId, $like);
        mysqli_stmt_execute($st);
        if (function_exists('mysqli_stmt_get_result')) {
            $res = mysqli_stmt_get_result($st);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $num = (string) ($row['ticket_code'] ?? '');
                    if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $num, $m)) {
                        $maxSeq = max($maxSeq, (int) $m[1]);
                    }
                }
                mysqli_free_result($res);
            }
        }
        mysqli_stmt_close($st);
    }
    $next = $maxSeq + 1;

    return $prefix . str_pad((string) $next, 8, '0', STR_PAD_LEFT);
}

/**
 * Remove pending tickets tied to a consultation (before replacing with a fresh ticket).
 */
function hms_payment_ticket_delete_pending_for_consultation(mysqli $connection, int $facilityId, int $consultationId): void
{
    if ($consultationId < 1 || !hms_payment_ticket_tables_ok($connection)) {
        return;
    }
    mysqli_query(
        $connection,
        'DELETE FROM tbl_payment_ticket WHERE facility_id = ' . (int) $facilityId
        . ' AND consultation_id = ' . (int) $consultationId . " AND status = 'pending'"
    );
}

/**
 * Apply external fulfillment (no hospital charge) and insurer covered % to line list prices.
 * Sets list_unit_price, adjusts unit_price to patient responsibility at cashier.
 *
 * @param list<array<string,mixed>> $lines
 */
function hms_payment_ticket_apply_external_and_insurance(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    array &$lines
): void {
    $cov = hms_patient_insurer_covered_percent($connection, $patientId, $facilityId);
    $autoTs = date('Y-m-d H:i:s');
    foreach ($lines as $i => &$ln) {
        if (!is_array($ln)) {
            continue;
        }
        $listUnit = (float) ($ln['unit_price'] ?? 0);
        $ln['list_unit_price'] = $listUnit;
        $ln['insurer_covered_percent'] = $cov;
        if (!empty($ln['external'])) {
            $ln['unit_price'] = 0.0;
            $ln['paid'] = true;
            $ln['paid_at'] = $autoTs;
            $ln['paid_reason'] = 'external';
            $ln['fulfillment'] = 'external';

            continue;
        }
        $pat = hms_patient_amount_after_insurance((int) round($listUnit), $cov);
        $ln['unit_price'] = (float) $pat;
        if ($cov >= 100 && $listUnit > 0) {
            $ln['paid'] = true;
            $ln['paid_at'] = $autoTs;
            $ln['paid_reason'] = 'insurance_100';
        }
    }
    unset($ln);
}

/**
 * Sum patient responsibility (unpaid lines only).
 *
 * @param list<array<string,mixed>> $lines
 */
function hms_payment_ticket_patient_due_total(array $lines): float
{
    $sum = 0.0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (hms_payment_ticket_line_paid($ln)) {
            continue;
        }
        $qty = (float) ($ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = (float) ($ln['unit_price'] ?? 0);
        $sum += $qty * $unit;
    }

    return round($sum, 2);
}

/**
 * Insert a ticket that requires no further cashier collection (all lines settled by insurance / external).
 *
 * @param list<array<string,mixed>> $lines
 */
function hms_payment_ticket_insert_resolved_without_cash(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    ?int $consultationId,
    array $lines,
    int $createdBy,
    string $notes
): ?string {
    if (!hms_payment_ticket_tables_ok($connection) || $lines === []) {
        return null;
    }
    $code = hms_payment_ticket_next_code($connection, $facilityId);
    $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }
    $paidAt = date('Y-m-d H:i:s');
    $tot = 0.0;
    $noteTrim = trim($notes);
    $cidSql = ($consultationId !== null && $consultationId > 0) ? (string) (int) $consultationId : 'NULL';
    $escCode = mysqli_real_escape_string($connection, $code);
    $escJson = mysqli_real_escape_string($connection, $json);
    $escNote = mysqli_real_escape_string($connection, $noteTrim);
    $sql = 'INSERT INTO tbl_payment_ticket (facility_id, ticket_code, patient_id, consultation_id, status, total_amount, lines_json, created_by, paid_at, paid_by, notes) VALUES ('
        . (int) $facilityId . ", '" . $escCode . "', " . (int) $patientId . ', ' . $cidSql
        . ", 'paid', " . (float) $tot . ", '" . $escJson . "', " . (int) $createdBy
        . ", '" . mysqli_real_escape_string($connection, $paidAt) . "', " . (int) $createdBy
        . ", '" . $escNote . "')";

    return mysqli_query($connection, $sql) ? $code : null;
}

/**
 * Cashier / supervisor: cancel a pending ticket (abandoned code, duplicate, patient obtained care elsewhere).
 */
function hms_payment_ticket_cancel_pending(
    mysqli $connection,
    int $facilityId,
    int $ticketId,
    int $userId,
    string $reason
): bool {
    if ($ticketId < 1 || !hms_payment_ticket_tables_ok($connection)) {
        return false;
    }
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Cancelled';
    }
    $st = mysqli_prepare(
        $connection,
        'UPDATE tbl_payment_ticket SET status = \'cancelled\', notes = ?, paid_by = ? WHERE id = ? AND facility_id = ? AND status = \'pending\' LIMIT 1'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'siii', $reason, $userId, $ticketId, $facilityId);
    $ok = mysqli_stmt_execute($st) && mysqli_stmt_affected_rows($st) > 0;
    mysqli_stmt_close($st);

    return $ok;
}

/**
 * Build a payment ticket after consultation save (consult fee unpaid + prescribed lab/radiology).
 *
 * @param list<array<string,mixed>> $labOrd
 * @param list<array<string,mixed>> $radOrd
 */
function hms_payment_ticket_sync_from_consultation(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $consultationId,
    int $feeXaf,
    bool $feePostedAtConsult,
    bool $emergencyConsult,
    string $cashierReceiptRef,
    string $feeDescription,
    array $labOrd,
    array $radOrd,
    int $createdBy
): ?string {
    if (!hms_payment_ticket_tables_ok($connection) || $patientId < 1 || $consultationId < 1) {
        return null;
    }

    $lines = [];
    $receiptRef = trim($cashierReceiptRef);
    $omitConsultFee = $emergencyConsult || $receiptRef !== '' || $feePostedAtConsult || $feeXaf <= 0;

    if (!$omitConsultFee) {
        $lines[] = [
            'kind' => 'consultation',
            'description' => $feeDescription !== '' ? $feeDescription : 'Consultation fee',
            'unit_price' => (float) $feeXaf,
            'quantity' => 1.0,
            'consultation_id' => $consultationId,
            'paid' => false,
        ];
    }

    foreach ($labOrd as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Laboratory test';
        }
        $px = max(0, (int) round((float) ($row['price_xaf'] ?? 0)));
        if ($px <= 0) {
            continue;
        }
        $lines[] = [
            'kind' => 'laboratory',
            'description' => $name,
            'unit_price' => (float) $px,
            'quantity' => 1.0,
            'catalog_id' => (int) ($row['catalog_id'] ?? 0),
            'paid' => false,
            'external' => !empty($row['external']),
        ];
    }

    foreach ($radOrd as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = 'Radiology study';
        }
        $px = max(0, (int) round((float) ($row['price_xaf'] ?? 0)));
        if ($px <= 0) {
            continue;
        }
        $lines[] = [
            'kind' => 'radiology',
            'description' => $name,
            'unit_price' => (float) $px,
            'quantity' => 1.0,
            'catalog_id' => (int) ($row['catalog_id'] ?? 0),
            'paid' => false,
            'external' => !empty($row['external']),
        ];
    }

    if ($lines === []) {
        hms_payment_ticket_delete_pending_for_consultation($connection, $facilityId, $consultationId);

        return null;
    }

    hms_payment_ticket_apply_external_and_insurance($connection, $facilityId, $patientId, $lines);

    $total = hms_payment_ticket_patient_due_total($lines);

    hms_payment_ticket_delete_pending_for_consultation($connection, $facilityId, $consultationId);

    if ($total <= 0.009) {
        $note = 'No balance due at cashier: external fulfillment and/or insurer-covered amount (see line detail).';
        $code = hms_payment_ticket_insert_resolved_without_cash(
            $connection,
            $facilityId,
            $patientId,
            $consultationId,
            $lines,
            $createdBy,
            $note
        );

        return $code;
    }

    $code = hms_payment_ticket_next_code($connection, $facilityId);
    $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return null;
    }

    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_payment_ticket (facility_id, ticket_code, patient_id, consultation_id, status, total_amount, lines_json, created_by) VALUES (?,?,?,?,\'pending\',?,?,?)'
    );
    if (!$st) {
        return null;
    }
    $tot = round($total, 2);
    mysqli_stmt_bind_param(
        $st,
        'isiidsi',
        $facilityId,
        $code,
        $patientId,
        $consultationId,
        $tot,
        $json,
        $createdBy
    );
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    return $ok ? $code : null;
}

/**
 * @return array<string,mixed>|null
 */
/**
 * Paid consultation prepayment ticket: patient matches, status paid, includes consultation line,
 * not yet linked to a consultation (consultation_id IS NULL).
 *
 * @return array<string,mixed>|null
 */
function hms_payment_ticket_validate_consult_prepay(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    string $rawCode
): ?array {
    if (!hms_payment_ticket_tables_ok($connection) || $patientId < 1) {
        return null;
    }
    $row = hms_payment_ticket_lookup_by_code($connection, $facilityId, $rawCode);
    if ($row === null) {
        return null;
    }
    if ((int) ($row['patient_id'] ?? 0) !== $patientId) {
        return null;
    }
    if (strtolower((string) ($row['status'] ?? '')) !== 'paid') {
        return null;
    }
    $lines = json_decode((string) ($row['lines_json'] ?? ''), true);
    if (!is_array($lines)) {
        return null;
    }
    $hasConsult = false;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (strtolower((string) ($ln['kind'] ?? '')) === 'consultation') {
            $hasConsult = true;
            break;
        }
    }
    if (!$hasConsult) {
        return null;
    }
    $consumed = $row['consultation_id'] ?? null;
    if ($consumed !== null && (int) $consumed > 0) {
        return null;
    }

    return $row;
}

/**
 * Cashier: patient pays consultation fee before the visit — create pending ticket, collect payment, return code + receipt.
 *
 * @return array{ok:bool, code?:string, ticket_id?:int, doc_id?:int, error?:string}
 */
function hms_payment_ticket_issue_consultation_prepay(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    string $feeDescription,
    int $amountXaf,
    int $userId,
    string $paymentMethod,
    string $fiscalDocument,
    int $companyIdInv
): array {
    if (!hms_payment_ticket_tables_ok($connection) || $patientId < 1 || $amountXaf < 1) {
        return ['ok' => false, 'error' => 'Invalid patient or amount.'];
    }
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    $hasPf = hms_db_column_exists($connection, 'tbl_patient', 'facility_id');
    $pq = $ms && $hasPf
        ? mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1')
        : mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
    if ($pq) {
        if ($ms && $hasPf) {
            mysqli_stmt_bind_param($pq, 'ii', $patientId, $facilityId);
        } else {
            mysqli_stmt_bind_param($pq, 'i', $patientId);
        }
        mysqli_stmt_execute($pq);
        $okPat = (bool) hms_stmt_fetch_assoc($pq);
        mysqli_stmt_close($pq);
        if (!$okPat) {
            return ['ok' => false, 'error' => 'Patient not found at this site.'];
        }
    }
    $cov = hms_patient_insurer_covered_percent($connection, $patientId, $facilityId);
    $patientDue = hms_patient_amount_after_insurance($amountXaf, $cov);
    if ($patientDue < 1) {
        $lines = [
            [
                'kind' => 'consultation',
                'description' => $feeDescription !== '' ? $feeDescription : 'Consultation fee (prepaid at cashier)',
                'unit_price' => 0.0,
                'quantity' => 1.0,
                'list_unit_price' => (float) $amountXaf,
                'paid' => true,
                'paid_at' => date('Y-m-d H:i:s'),
                'paid_reason' => 'insurance_100',
                'insurer_covered_percent' => $cov,
            ],
        ];
        $code = hms_payment_ticket_insert_resolved_without_cash(
            $connection,
            $facilityId,
            $patientId,
            null,
            $lines,
            $userId,
            'Consultation prepayment: insurer covers full listed fee; present code to clinician.'
        );
        if ($code === null) {
            return ['ok' => false, 'error' => 'Could not record insurance-only prepayment.'];
        }
        $tid = (int) mysqli_insert_id($connection);

        return [
            'ok' => true,
            'code' => $code,
            'ticket_id' => $tid,
            'doc_id' => 0,
        ];
    }
    $code = hms_payment_ticket_next_code($connection, $facilityId);
    $lines = [
        [
            'kind' => 'consultation',
            'description' => $feeDescription !== '' ? $feeDescription : 'Consultation fee (prepaid at cashier)',
            'unit_price' => (float) $patientDue,
            'quantity' => 1.0,
            'list_unit_price' => (float) $amountXaf,
            'insurer_covered_percent' => $cov,
            'paid' => false,
        ],
    ];
    $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Could not build ticket.'];
    }
    $tot = round((float) $patientDue, 2);
    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_payment_ticket (facility_id, ticket_code, patient_id, consultation_id, status, total_amount, lines_json, created_by) VALUES (?,?,?,NULL,\'pending\',?,?,?)'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not create ticket.'];
    }
    mysqli_stmt_bind_param($st, 'isidsi', $facilityId, $code, $patientId, $tot, $json, $userId);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'error' => 'Could not save ticket.'];
    }
    $tid = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($st);

    $col = hms_payment_ticket_collect($connection, $facilityId, $tid, $paymentMethod, $userId, $fiscalDocument, $companyIdInv);
    if (empty($col['ok'])) {
        return ['ok' => false, 'error' => (string) ($col['error'] ?? 'Payment collection failed.')];
    }

    return [
        'ok' => true,
        'code' => $code,
        'ticket_id' => $tid,
        'doc_id' => (int) ($col['doc_id'] ?? 0),
    ];
}

/**
 * Whether a ticket line has been settled at the cashier (full line amount).
 */
function hms_payment_ticket_line_paid(array $ln): bool
{
    if (!empty($ln['paid'])) {
        return true;
    }
    $at = trim((string) ($ln['paid_at'] ?? ''));

    return $at !== '';
}

/**
 * Sum of amounts not yet paid for this ticket's line items.
 *
 * @param list<array<string,mixed>> $lines
 */
function hms_payment_ticket_unpaid_total(array $lines): float
{
    $sum = 0.0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (hms_payment_ticket_line_paid($ln)) {
            continue;
        }
        $qty = (float) ($ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = (float) ($ln['unit_price'] ?? 0);
        $sum += $qty * $unit;
    }

    return round($sum, 2);
}

/**
 * Consultation billing flags for a payment ticket (emergency / hospital waiver).
 *
 * @return array{emergency:bool, waiver_note:string, waiver:bool}
 */
function hms_payment_ticket_consult_flags(mysqli $connection, int $facilityId, ?array $ticketRow): array
{
    $out = ['emergency' => false, 'waiver_note' => '', 'waiver' => false];
    if ($ticketRow === null || !hms_workflow_table_ok($connection, 'tbl_consultation')) {
        return $out;
    }
    $cid = (int) ($ticketRow['consultation_id'] ?? 0);
    if ($cid < 1) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT param_code, value_text FROM tbl_consult_observation WHERE consultation_id = ' . (int) $cid
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $pc = (string) ($r['param_code'] ?? '');
        $val = trim((string) ($r['value_text'] ?? ''));
        if ($pc === 'consult_emergency' && ($val === '1' || strtolower($val) === 'true')) {
            $out['emergency'] = true;
        }
        if ($pc === 'consult_billing_exception' && $val !== '') {
            $out['waiver_note'] = $val;
            $out['waiver'] = true;
        }
    }

    return $out;
}

/**
 * Service desk: can this line be fulfilled without cashier payment (emergency / waiver)?
 *
 * @param array<string,mixed> $flags from hms_payment_ticket_consult_flags
 */
function hms_payment_ticket_line_fulfillment_allowed(array $ln, array $flags): bool
{
    if (!empty($flags['emergency']) || !empty($flags['waiver'])) {
        return true;
    }

    return hms_payment_ticket_line_paid($ln);
}

function hms_payment_ticket_lookup_by_code(mysqli $connection, int $facilityId, string $rawCode): ?array
{
    if (!hms_payment_ticket_tables_ok($connection)) {
        return null;
    }
    $code = hms_payment_ticket_normalize_code($rawCode);
    if ($code === '') {
        return null;
    }
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    $hasPf = hms_db_column_exists($connection, 'tbl_patient', 'facility_id');
    $sql = 'SELECT t.*, p.first_name, p.last_name FROM tbl_payment_ticket t
         INNER JOIN tbl_patient p ON p.id = t.patient_id
         WHERE t.facility_id = ? AND t.ticket_code = ?';
    if ($ms && $hasPf) {
        $sql .= ' AND p.facility_id = ?';
    }
    $sql .= ' LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if (!$st) {
        return null;
    }
    if ($ms && $hasPf) {
        mysqli_stmt_bind_param($st, 'isi', $facilityId, $code, $facilityId);
    } else {
        mysqli_stmt_bind_param($st, 'is', $facilityId, $code);
    }
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * Collect payment for a pending ticket: charge + receipt + optional consultation fee update.
 * Supports partial settlement: pass $payLineIndexes as a list of 0-based line indices to pay only those unpaid lines.
 * The same ticket code remains valid until all lines are paid; remaining balance is stored in total_amount.
 *
 * @param list<int>|null $payLineIndexes
 * @return array{ok:bool, doc_id?:int, error?:string, partial?:bool}
 */
function hms_payment_ticket_collect(
    mysqli $connection,
    int $facilityId,
    int $ticketId,
    string $paymentMethod,
    int $userId,
    string $fiscalDocument,
    int $companyIdInv,
    ?array $payLineIndexes = null
): array {
    if (!hms_payment_ticket_tables_ok($connection) || !hms_billing_document_tables_ok($connection)) {
        return ['ok' => false, 'error' => 'Billing tables are not available. Run migrations.'];
    }
    if ($ticketId < 1) {
        return ['ok' => false, 'error' => 'Invalid ticket.'];
    }

    $st = mysqli_prepare(
        $connection,
        'SELECT * FROM tbl_payment_ticket WHERE id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$st) {
        return ['ok' => false, 'error' => 'Could not load ticket.'];
    }
    mysqli_stmt_bind_param($st, 'ii', $ticketId, $facilityId);
    mysqli_stmt_execute($st);
    $t = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$t) {
        return ['ok' => false, 'error' => 'Ticket not found.'];
    }
    if (strtolower((string) ($t['status'] ?? '')) !== 'pending') {
        return ['ok' => false, 'error' => 'This ticket is not pending payment.'];
    }

    $lines = json_decode((string) ($t['lines_json'] ?? ''), true);
    if (!is_array($lines) || $lines === []) {
        return ['ok' => false, 'error' => 'Ticket has no line items.'];
    }

    $unpaidIdx = [];
    foreach ($lines as $i => $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if (hms_payment_ticket_line_paid($ln)) {
            continue;
        }
        $desc = trim((string) ($ln['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $unpaidIdx[] = (int) $i;
    }
    if ($unpaidIdx === []) {
        return ['ok' => false, 'error' => 'All lines on this ticket are already paid.'];
    }

    $selected = $unpaidIdx;
    if ($payLineIndexes !== null && $payLineIndexes !== []) {
        $want = [];
        foreach ($payLineIndexes as $raw) {
            $ix = (int) $raw;
            if (in_array($ix, $unpaidIdx, true)) {
                $want[] = $ix;
            }
        }
        $selected = array_values(array_unique($want));
    }
    if ($selected === []) {
        return ['ok' => false, 'error' => 'Select at least one unpaid line to pay.'];
    }

    $docLines = [];
    $batchTotal = 0.0;
    $hasConsultInBatch = false;
    foreach ($selected as $idx) {
        $ln = $lines[$idx] ?? null;
        if (!is_array($ln)) {
            continue;
        }
        $desc = trim((string) ($ln['description'] ?? ''));
        if ($desc === '') {
            continue;
        }
        $qty = (float) ($ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = (float) ($ln['unit_price'] ?? 0);
        $docLines[] = [
            'description' => $desc,
            'quantity' => $qty,
            'unit_price' => $unit,
        ];
        $batchTotal += $qty * $unit;
        if (strtolower((string) ($ln['kind'] ?? '')) === 'consultation') {
            $hasConsultInBatch = true;
        }
    }
    if ($docLines === []) {
        return ['ok' => false, 'error' => 'No billable lines selected.'];
    }
    $batchTotal = round($batchTotal, 2);
    if ($batchTotal <= 0) {
        return ['ok' => false, 'error' => 'Invalid amount for selected lines.'];
    }

    $pid = (int) ($t['patient_id'] ?? 0);
    $consultationId = (int) ($t['consultation_id'] ?? 0);

    $payMethod = hms_billing_normalize_payment_method($paymentMethod);
    $wantInvoice = strtolower($fiscalDocument) === 'invoice';
    $companyBind = 0;
    if ($wantInvoice && $companyIdInv > 0 && hms_db_table_exists($connection, 'tbl_billing_company')) {
        $cc = mysqli_prepare($connection, 'SELECT id FROM tbl_billing_company WHERE id = ? AND facility_id = ? AND status = 1 LIMIT 1');
        if ($cc) {
            mysqli_stmt_bind_param($cc, 'ii', $companyIdInv, $facilityId);
            mysqli_stmt_execute($cc);
            $okCo = (bool) hms_stmt_fetch_assoc($cc);
            mysqli_stmt_close($cc);
            if ($okCo) {
                $companyBind = $companyIdInv;
            }
        }
    }

    $walletId = null;
    $walletBal = 0.0;
    if ($payMethod === 'Wallet') {
        $wStmt = mysqli_prepare($connection, "SELECT id, balance FROM tbl_patient_wallet WHERE patient_id = ? AND status='active'");
        if ($wStmt) {
            mysqli_stmt_bind_param($wStmt, 'i', $pid);
            mysqli_stmt_execute($wStmt);
            $wres = hms_stmt_fetch_assoc($wStmt);
            mysqli_stmt_close($wStmt);
            if ($wres) {
                $walletId = (int)$wres['id'];
                $walletBal = (float)$wres['balance'];
                if ($walletBal < $batchTotal) {
                    return ['ok' => false, 'error' => 'Insufficient wallet balance (Available: ' . number_format($walletBal, 0, '.', ' ') . ', Required: ' . number_format($batchTotal, 0, '.', ' ') . ').'];
                }
            } else {
                 return ['ok' => false, 'error' => 'Patient does not have an active PRE-PAID WALLET to deduct from.'];
            }
        }
    }

    mysqli_begin_transaction($connection);
    try {
        $feeChargeId = 0;
        if (hms_workflow_table_ok($connection, 'tbl_charge')) {
            $cpt = 'PAY-TICK';
            $tc = (string) ($t['ticket_code'] ?? '');
            $desc = 'Payment ticket ' . $tc . (count($selected) < count($unpaidIdx) ? ' (partial)' : '');
            $amt = $batchTotal;
            $ch = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at) VALUES (?,?,?,?,?,NOW())'
            );
            if ($ch) {
                mysqli_stmt_bind_param($ch, 'iissd', $facilityId, $pid, $cpt, $desc, $amt);
                mysqli_stmt_execute($ch);
                $feeChargeId = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($ch);
            }
        }

        if ($walletId !== null) {
            $newBal = $walletBal - $batchTotal;
            $updW = mysqli_prepare($connection, "UPDATE tbl_patient_wallet SET balance = ? WHERE id = ?");
            if ($updW) {
                mysqli_stmt_bind_param($updW, 'di', $newBal, $walletId);
                mysqli_stmt_execute($updW);
                mysqli_stmt_close($updW);
            }

            $insTxn = mysqli_prepare($connection, "INSERT INTO tbl_patient_wallet_txn (wallet_id, txn_type, direction, amount, balance_after, reference_id, notes, created_by) VALUES (?, 'deduct_cashier', 'dr', ?, ?, ?, ?, ?)");
            if ($insTxn) {
                $wNote = "Payment ticket ".($t['ticket_code'] ?? 'manual');
                $wRef = (string)$ticketId;
                mysqli_stmt_bind_param($insTxn, 'iddssi', $walletId, $batchTotal, $newBal, $wRef, $wNote, $userId);
                mysqli_stmt_execute($insTxn);
                mysqli_stmt_close($insTxn);
            }
        }

        $docType = ($wantInvoice && $companyBind > 0) ? 'invoice' : 'receipt';
        $ticketCodeForDoc = trim((string) ($t['ticket_code'] ?? ''));
        $docOpts = [
            'facility_id' => $facilityId,
            'patient_id' => $pid,
            'doc_type' => $docType,
            'payment_method' => $payMethod,
            'source_module' => 'payment_ticket',
            'source_pk' => $ticketId,
            'charge_id' => $feeChargeId > 0 ? $feeChargeId : null,
            'consultation_id' => $consultationId > 0 ? $consultationId : null,
            'created_by' => $userId,
            'skip_if_exists' => false,
            'notes' => $ticketCodeForDoc !== ''
                ? 'Cashier payment code ' . $ticketCodeForDoc . ' — present this code at laboratory, radiology, or pharmacy until all lines are paid (emergency / hospital waiver exceptions apply).'
                : null,
        ];
        if ($companyBind > 0) {
            $docOpts['company_id'] = $companyBind;
        }
        $hospBill = function_exists('hms_hospitalization_open_id_for_patient')
            ? hms_hospitalization_open_id_for_patient($connection, $facilityId, $pid)
            : 0;
        if ($hospBill > 0) {
            $docOpts['hospitalization_id'] = $hospBill;
        }

        $docId = hms_billing_create_document($connection, $docOpts, $docLines);
        if (!is_int($docId) || $docId < 1) {
            throw new RuntimeException('Could not issue receipt or invoice.');
        }

        $paidAt = date('Y-m-d H:i:s');
        foreach ($selected as $idx) {
            if (!isset($lines[$idx]) || !is_array($lines[$idx])) {
                continue;
            }
            $lines[$idx]['paid'] = true;
            $lines[$idx]['paid_at'] = $paidAt;
            $lines[$idx]['paid_doc_id'] = $docId;
        }

        $remaining = hms_payment_ticket_unpaid_total($lines);
        $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Could not update ticket lines.');
        }

        if ($hasConsultInBatch && $consultationId > 0 && $feeChargeId > 0 && hms_workflow_table_ok($connection, 'tbl_consultation')) {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_consultation SET fee_charge_id = ?, fee_paid_at = ?, status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                $stFee = 'fee_paid';
                mysqli_stmt_bind_param($up, 'issii', $feeChargeId, $paidAt, $stFee, $consultationId, $facilityId);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
            }
        }

        $newStatus = $remaining <= 0.009 ? 'paid' : 'pending';
        $upT = mysqli_prepare(
            $connection,
            'UPDATE tbl_payment_ticket SET status = ?, total_amount = ?, lines_json = ?, charge_id = ?, billing_document_id = ?, paid_at = ?, paid_by = ? WHERE id = ? AND facility_id = ? AND status = \'pending\' LIMIT 1'
        );
        if (!$upT) {
            throw new RuntimeException('Could not finalize ticket.');
        }
        $docIdU = $docId;
        $remAmt = round($remaining, 2);
        mysqli_stmt_bind_param(
            $upT,
            'sdsiisiii',
            $newStatus,
            $remAmt,
            $json,
            $feeChargeId,
            $docIdU,
            $paidAt,
            $userId,
            $ticketId,
            $facilityId
        );
        mysqli_stmt_execute($upT);
        $changed = mysqli_stmt_affected_rows($upT);
        mysqli_stmt_close($upT);
        if ($changed < 1) {
            throw new RuntimeException('Ticket was already paid or could not be updated.');
        }

        mysqli_commit($connection);
        hms_audit_log($connection, 'cashier.ticket_paid', 'payment_ticket', $ticketId);

        return [
            'ok' => true,
            'doc_id' => $docId,
            'partial' => $newStatus === 'pending',
        ];
    } catch (Throwable $e) {
        mysqli_rollback($connection);

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
