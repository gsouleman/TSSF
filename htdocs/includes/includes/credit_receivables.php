<?php
declare(strict_types=1);

function hms_credit_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_credit_account')
        && hms_db_table_exists($connection, 'tbl_credit_payment')
        && hms_db_column_exists($connection, 'tbl_charge', 'on_credit');
}

function hms_credit_can_read(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'credit.read') || hms_can($connection, 'billing.read');
}

function hms_credit_can_write(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'credit.write') || hms_can($connection, 'billing.write');
}

function hms_credit_require_read(mysqli $connection): void
{
    if (!hms_credit_can_read($connection)) {
        http_response_code(403);
        exit('Forbidden: missing credit or billing view permission.');
    }
}

function hms_credit_require_write(mysqli $connection): void
{
    if (!hms_credit_can_write($connection)) {
        http_response_code(403);
        exit('Forbidden: missing credit or billing edit permission.');
    }
}

/**
 * @return array<string,mixed>|null
 */
function hms_credit_get_active_account(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): ?array
{
    if (!hms_credit_tables_ok($connection) || $patientId < 1) {
        return null;
    }
    $sql = $multiSite
        ? 'SELECT * FROM tbl_credit_account WHERE facility_id = ? AND patient_id = ? AND status = ? ORDER BY id DESC LIMIT 1'
        : 'SELECT * FROM tbl_credit_account WHERE patient_id = ? AND status = ? ORDER BY id DESC LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if (!$st) {
        return null;
    }
    $stn = 'active';
    if ($multiSite) {
        mysqli_stmt_bind_param($st, 'iis', $facilityId, $patientId, $stn);
    } else {
        mysqli_stmt_bind_param($st, 'is', $patientId, $stn);
    }
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * @return array{charges:float,payments:float,adjustments:float,balance:float,aging_days:int}|null
 */
function hms_credit_balance_snapshot(mysqli $connection, int $accountId): ?array
{
    if (!hms_credit_tables_ok($connection) || $accountId < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT
            COALESCE(SUM(CASE WHEN on_credit = 1 THEN amount ELSE 0 END), 0) AS charges,
            (SELECT COALESCE(SUM(amount), 0) FROM tbl_credit_payment WHERE credit_account_id = ?) AS payments,
            (SELECT COALESCE(SUM(amount), 0) FROM tbl_credit_adjustment WHERE credit_account_id = ?) AS adjustments,
            (SELECT MIN(posted_at) FROM tbl_charge WHERE credit_account_id = ? AND on_credit = 1) AS oldest
         FROM tbl_charge WHERE credit_account_id = ?'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'iiii', $accountId, $accountId, $accountId, $accountId);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$row) {
        return null;
    }
    $ch = (float) ($row['charges'] ?? 0);
    $pay = (float) ($row['payments'] ?? 0);
    $adj = (float) ($row['adjustments'] ?? 0);
    $bal = round($ch - $pay - $adj, 2);
    $oldest = (string) ($row['oldest'] ?? '');
    $aging = 0;
    if ($oldest !== '') {
        $aging = (int) floor((time() - strtotime($oldest . ' UTC')) / 86400);
        if ($aging < 0) {
            $aging = 0;
        }
    }

    return [
        'charges' => round($ch, 2),
        'payments' => round($pay, 2),
        'adjustments' => round($adj, 2),
        'balance' => $bal,
        'aging_days' => $aging,
    ];
}

function hms_credit_refresh_account_status(mysqli $connection, int $accountId): void
{
    $snap = hms_credit_balance_snapshot($connection, $accountId);
    if ($snap === null) {
        return;
    }
    if ($snap['balance'] <= 0.02) {
        $st = mysqli_prepare(
            $connection,
            "UPDATE tbl_credit_account SET status = 'closed', closed_at = NOW() WHERE id = ? AND status IN ('active','collections') LIMIT 1"
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'i', $accountId);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }
}

/**
 * @return array{ok:bool,message:string,id?:int}
 */
function hms_credit_open_account(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    bool $multiSite,
    bool $emergencyPending,
    ?string $guarantorName,
    ?string $guarantorPhone,
    ?string $guarantorRelation,
    ?string $notes,
    int $createdBy
): array {
    if (!hms_credit_tables_ok($connection)) {
        return ['ok' => false, 'message' => 'Credit tables are not installed. Run migration 019_credit_receivables.sql.'];
    }
    if ($patientId < 1) {
        return ['ok' => false, 'message' => 'Invalid patient.'];
    }
    $ex = hms_credit_get_active_account($connection, $facilityId, $patientId, $multiSite);
    if ($ex) {
        return ['ok' => false, 'message' => 'This patient already has an active credit account.', 'id' => (int) $ex['id']];
    }
    $gName = ($guarantorName !== null && trim($guarantorName) !== '') ? substr(trim($guarantorName), 0, 220) : null;
    $gPhone = ($guarantorPhone !== null && trim($guarantorPhone) !== '') ? substr(trim($guarantorPhone), 0, 64) : null;
    $gRel = ($guarantorRelation !== null && trim($guarantorRelation) !== '') ? substr(trim($guarantorRelation), 0, 120) : null;
    $note = $notes !== null ? trim($notes) : null;
    if ($note === '') {
        $note = null;
    }
    $em = $emergencyPending ? 1 : 0;
    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_credit_account (facility_id, patient_id, status, emergency_payment_pending, guarantor_name, guarantor_phone, guarantor_relation, notes) VALUES (?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return ['ok' => false, 'message' => 'Could not prepare insert.'];
    }
    $stat = 'active';
    mysqli_stmt_bind_param(
        $st,
        'iisissss',
        $facilityId,
        $patientId,
        $stat,
        $em,
        $gName,
        $gPhone,
        $gRel,
        $note
    );
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'message' => 'Could not create credit account.'];
    }
    $newId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($st);
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'credit.account.open', 'credit_account', $newId);
    }

    return ['ok' => true, 'message' => 'Credit account opened.', 'id' => $newId];
}

/**
 * @return array{ok:bool,message:string,payment_id?:int,doc_id?:int}
 */
function hms_credit_record_payment(
    mysqli $connection,
    int $facilityId,
    int $accountId,
    int $patientId,
    bool $multiSite,
    float $amount,
    string $paymentMethod,
    ?string $notes,
    ?int $installmentPlanId,
    int $createdBy
): array {
    if (!hms_credit_tables_ok($connection) || !hms_billing_document_tables_ok($connection)) {
        return ['ok' => false, 'message' => 'Credit or billing tables are missing.'];
    }
    if ($accountId < 1 || $patientId < 1 || $amount <= 0) {
        return ['ok' => false, 'message' => 'Invalid payment.'];
    }
    $snap = hms_credit_balance_snapshot($connection, $accountId);
    if ($snap === null) {
        return ['ok' => false, 'message' => 'Account not found.'];
    }
    if ($amount - $snap['balance'] > 0.02) {
        return ['ok' => false, 'message' => 'Amount exceeds outstanding balance.'];
    }
    $payM = function_exists('hms_billing_normalize_payment_method') ? hms_billing_normalize_payment_method($paymentMethod) : $paymentMethod;
    $note = $notes !== null ? substr(trim($notes), 0, 600) : null;
    if ($note === '') {
        $note = null;
    }
    $planBind = $installmentPlanId !== null && $installmentPlanId > 0 ? $installmentPlanId : null;

    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_credit_payment (credit_account_id, amount, payment_method, notes, billing_document_id, installment_plan_id, created_by) VALUES (?,?,?,?,NULL,?,?)'
    );
    if (!$st) {
        return ['ok' => false, 'message' => 'Could not save payment row.'];
    }
    mysqli_stmt_bind_param($st, 'idssii', $accountId, $amount, $payM, $note, $planBind, $createdBy);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'message' => 'Could not save payment.'];
    }
    $payId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($st);

    $lineDesc = 'Patient credit payment · account #' . $accountId;
    $docOpts = [
        'facility_id' => $facilityId,
        'patient_id' => $patientId,
        'payment_method' => $payM,
        'source_module' => 'credit_payment',
        'source_pk' => $payId,
        'created_by' => $createdBy,
        'notes' => $note,
        'skip_if_exists' => true,
    ];
    $hospBill = function_exists('hms_hospitalization_open_id_for_patient')
        ? hms_hospitalization_open_id_for_patient($connection, $facilityId, $patientId)
        : 0;
    if ($hospBill > 0) {
        $docOpts['hospitalization_id'] = $hospBill;
    }

    $docId = hms_billing_create_document(
        $connection,
        $docOpts,
        [
            [
                'description' => $lineDesc,
                'quantity' => 1,
                'unit_price' => $amount,
            ],
        ]
    );
    if (!is_int($docId) || $docId < 1) {
        return ['ok' => false, 'message' => 'Payment saved but fiscal receipt could not be issued (episode anchor). Link payment id ' . $payId . ' in billing.', 'payment_id' => $payId];
    }

    $up = mysqli_prepare(
        $connection,
        'UPDATE tbl_credit_payment SET billing_document_id = ? WHERE id = ? LIMIT 1'
    );
    if ($up) {
        mysqli_stmt_bind_param($up, 'ii', $docId, $payId);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
    }

    hms_credit_refresh_account_status($connection, $accountId);
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'credit.payment', 'credit_payment', $payId);
    }

    return ['ok' => true, 'message' => 'Payment recorded and receipt issued.', 'payment_id' => $payId, 'doc_id' => $docId];
}

/**
 * @return array{ok:bool,message:string}
 */
function hms_credit_record_writeoff(
    mysqli $connection,
    int $accountId,
    float $amount,
    int $approvedBy,
    ?string $notes
): array {
    if (!hms_credit_tables_ok($connection) || $accountId < 1 || $amount <= 0) {
        return ['ok' => false, 'message' => 'Invalid write-off.'];
    }
    $snap = hms_credit_balance_snapshot($connection, $accountId);
    if ($snap === null || $snap['balance'] <= 0) {
        return ['ok' => false, 'message' => 'Nothing to write off.'];
    }
    if ($amount - $snap['balance'] > 0.02) {
        return ['ok' => false, 'message' => 'Write-off exceeds balance.'];
    }
    $note = $notes !== null ? substr(trim($notes), 0, 600) : null;
    if ($note === '') {
        $note = null;
    }
    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_credit_adjustment (credit_account_id, kind, amount, approved_by, notes) VALUES (?,?,?,?,?)'
    );
    if (!$st) {
        return ['ok' => false, 'message' => 'Could not save adjustment.'];
    }
    $kind = 'writeoff';
    mysqli_stmt_bind_param($st, 'isdis', $accountId, $kind, $amount, $approvedBy, $note);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'message' => 'Write-off failed.'];
    }
    mysqli_stmt_close($st);

    if ($snap['balance'] - $amount <= 0.02) {
        $wo = mysqli_prepare(
            $connection,
            'UPDATE tbl_credit_account SET status = ?, writeoff_at = NOW(), writeoff_approved_by = ?, writeoff_note = ? WHERE id = ? LIMIT 1'
        );
        if ($wo) {
            $stWritten = 'written_off';
            mysqli_stmt_bind_param($wo, 'sisi', $stWritten, $approvedBy, $note, $accountId);
            mysqli_stmt_execute($wo);
            mysqli_stmt_close($wo);
        }
    }

    hms_credit_refresh_account_status($connection, $accountId);
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'credit.writeoff', 'credit_account', $accountId);
    }

    return ['ok' => true, 'message' => 'Write-off recorded.'];
}

/**
 * @return array{ok:bool,message:string,id?:int}
 */
function hms_credit_create_installment_plan(
    mysqli $connection,
    int $accountId,
    string $title,
    int $count,
    float $amountEach,
    string $firstDueYmd,
    ?string $notes,
    int $createdBy
): array {
    if (!hms_credit_tables_ok($connection) || $accountId < 1 || $count < 1 || $amountEach <= 0) {
        return ['ok' => false, 'message' => 'Invalid plan.'];
    }
    $snap = hms_credit_balance_snapshot($connection, $accountId);
    if ($snap === null || $snap['balance'] <= 0) {
        return ['ok' => false, 'message' => 'No outstanding balance for a plan.'];
    }
    $t = substr(trim($title), 0, 220);
    if ($t === '') {
        $t = 'Installment plan';
    }
    $note = $notes !== null ? substr(trim($notes), 0, 600) : null;
    if ($note === '') {
        $note = null;
    }
    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_credit_installment_plan (credit_account_id, title, installment_count, amount_each, first_due_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?)'
    );
    if (!$st) {
        return ['ok' => false, 'message' => 'Could not create plan.'];
    }
    $stat = 'active';
    mysqli_stmt_bind_param($st, 'isidsssi', $accountId, $t, $count, $amountEach, $firstDueYmd, $stat, $note, $createdBy);
    if (!mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);

        return ['ok' => false, 'message' => 'Plan insert failed.'];
    }
    $pid = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($st);
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'credit.installment_plan', 'credit_installment_plan', $pid);
    }

    return ['ok' => true, 'message' => 'Installment plan created.', 'id' => $pid];
}
