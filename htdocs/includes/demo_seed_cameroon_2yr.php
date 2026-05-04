<?php
declare(strict_types=1);

/**
 * Cameroon demo dataset: extra staff + multi-scenario patients + operating expenses over ~24 months.
 * Demo rows are tagged (email / username / expense reference) so hms_demo_seed_cleanup() can remove them.
 */

const HMS_DEMO_2YR_EMAIL_SUFFIX = '@demo-2yr.hms.local';
const HMS_DEMO_2YR_STAFF_PREFIX = 'demo2.';
/** tbl_expense.reference prefix — must match cleanup DELETE pattern */
const HMS_DEMO_2YR_EXPENSE_REF_PREFIX = 'DEMO2YR-';

/**
 * PHP 8.1+ defaults mysqli to throw mysqli_sql_exception on failed queries. This seed uses many mysqli_* calls
 * without try/catch; disable strict reporting for cleanup/seed only.
 */
function hms_demo_seed_mysqli_report_off(): void
{
    if (function_exists('mysqli_report')) {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

/**
 * tbl_expense (and other modules) FK to tbl_facility.id. Session may be 0 or point at a deleted site.
 *
 * @return int A facility id that exists in tbl_facility (falls back to first active, then first row)
 */
function hms_demo_resolve_facility_id(mysqli $connection, int $facilityId): int
{
    if (!hms_db_table_exists($connection, 'tbl_facility')) {
        return max(1, $facilityId);
    }
    $fid = max(1, (int) $facilityId);
    $r = mysqli_query($connection, 'SELECT id FROM tbl_facility WHERE id = ' . $fid . ' LIMIT 1');
    if ($r) {
        $row = mysqli_fetch_assoc($r);
        mysqli_free_result($r);
        if ($row && (int) ($row['id'] ?? 0) === $fid) {
            return $fid;
        }
    }
    $hasStatus = hms_db_column_exists($connection, 'tbl_facility', 'status');
    $sql = $hasStatus
        ? 'SELECT id FROM tbl_facility WHERE status = 1 ORDER BY id ASC LIMIT 1'
        : 'SELECT id FROM tbl_facility ORDER BY id ASC LIMIT 1';
    $r2 = mysqli_query($connection, $sql);
    if ($r2) {
        $row2 = mysqli_fetch_assoc($r2);
        mysqli_free_result($r2);
        $alt = (int) ($row2['id'] ?? 0);
        if ($alt > 0) {
            return $alt;
        }
    }

    return 1;
}

/**
 * Match demo expense rows: reference LIKE DEMO2YR-* when column exists, else DEMO2YR-EXP tag in notes/description.
 *
 * @param string|null $tableAlias e.g. "e" for "e.reference" or null for bare column names
 */
function hms_demo_expense_demo_match_sql(mysqli $connection, ?string $tableAlias): string
{
    $a = ($tableAlias !== null && $tableAlias !== '') ? $tableAlias . '.' : '';
    $parts = [];
    $pfx = mysqli_real_escape_string($connection, HMS_DEMO_2YR_EXPENSE_REF_PREFIX);
    if (hms_db_column_exists($connection, 'tbl_expense', 'reference')) {
        $parts[] = "{$a}reference LIKE '{$pfx}%'";
    }
    $tag = mysqli_real_escape_string($connection, 'DEMO2YR-EXP');
    if (hms_db_column_exists($connection, 'tbl_expense', 'notes')) {
        $parts[] = "{$a}notes LIKE '%{$tag}%'";
    }
    if (hms_db_column_exists($connection, 'tbl_expense', 'description')) {
        $parts[] = "{$a}description LIKE '%{$tag}%'";
    }
    if ($parts === []) {
        return '0';
    }

    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Remove demo expenses and their GL journals for this facility (runs even when no demo patients exist).
 */
function hms_demo_seed_cleanup_expenses_for_facility(mysqli $connection, int $facilityId): void
{
    if (!hms_db_table_exists($connection, 'tbl_expense')) {
        return;
    }
    $fid = (int) $facilityId;
    $matchE = hms_demo_expense_demo_match_sql($connection, 'e');

    if (function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection)
        && hms_db_table_exists($connection, 'tbl_fin_journal_header')
        && hms_db_table_exists($connection, 'tbl_fin_journal_line')) {
        mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=0');
        mysqli_query(
            $connection,
            'DELETE jl FROM tbl_fin_journal_line jl '
            . 'INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id '
            . "WHERE h.facility_id = {$fid} AND h.source_type = 'expense' "
            . "AND EXISTS (SELECT 1 FROM tbl_expense e WHERE e.id = h.source_id AND e.facility_id = {$fid} AND {$matchE})"
        );
        mysqli_query(
            $connection,
            'DELETE h FROM tbl_fin_journal_header h '
            . "WHERE h.facility_id = {$fid} AND h.source_type = 'expense' "
            . "AND EXISTS (SELECT 1 FROM tbl_expense e WHERE e.id = h.source_id AND e.facility_id = {$fid} AND {$matchE})"
        );
        mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=1');
    }

    $matchBare = hms_demo_expense_demo_match_sql($connection, null);
    mysqli_query(
        $connection,
        "DELETE FROM tbl_expense WHERE facility_id = {$fid} AND {$matchBare}"
    );
}

/**
 * @return list<int>
 */
function hms_demo_2yr_patient_ids(mysqli $connection): array
{
    $suffixEsc = mysqli_real_escape_string($connection, HMS_DEMO_2YR_EMAIL_SUFFIX);
    $q = mysqli_query(
        $connection,
        "SELECT id FROM tbl_patient WHERE email LIKE '%" . $suffixEsc . "'"
    );
    if (!$q) {
        return [];
    }
    $ids = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    mysqli_free_result($q);

    return array_values(array_filter($ids, static fn (int $x): bool => $x > 0));
}

function hms_demo_seed_cleanup(mysqli $connection, int $facilityId): void
{
    hms_demo_seed_mysqli_report_off();

    hms_demo_seed_cleanup_expenses_for_facility($connection, $facilityId);

    $ids = hms_demo_2yr_patient_ids($connection);
    if ($ids === []) {
        goto staff_cleanup;
    }
    $in = implode(',', array_map('intval', $ids));
    $fid = (int) $facilityId;

    mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=0');

    if (hms_db_table_exists($connection, 'tbl_billing_document_line')) {
        mysqli_query(
            $connection,
            'DELETE bl FROM tbl_billing_document_line bl INNER JOIN tbl_billing_document b ON b.id = bl.document_id '
            . 'WHERE b.facility_id = ' . $fid . ' AND b.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_billing_document')) {
        mysqli_query($connection, 'DELETE FROM tbl_billing_document WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_transaction')) {
        mysqli_query($connection, 'DELETE FROM tbl_transaction WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_payment_ticket')) {
        mysqli_query($connection, 'DELETE FROM tbl_payment_ticket WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_result_shared_notice')) {
        mysqli_query($connection, 'DELETE FROM tbl_result_shared_notice WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_lab_result')) {
        mysqli_query($connection, 'DELETE FROM tbl_lab_result WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_radiology_result')) {
        mysqli_query($connection, 'DELETE FROM tbl_radiology_result WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_consult_observation')) {
        mysqli_query(
            $connection,
            'DELETE o FROM tbl_consult_observation o INNER JOIN tbl_consultation c ON c.id = o.consultation_id '
            . 'WHERE c.facility_id = ' . $fid . ' AND c.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_consultation')) {
        mysqli_query($connection, 'DELETE FROM tbl_consultation WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_opd_visit')) {
        mysqli_query($connection, 'DELETE FROM tbl_opd_visit WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_admission')) {
        mysqli_query($connection, 'DELETE FROM tbl_admission WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_facility_admission')) {
        mysqli_query($connection, 'DELETE FROM tbl_facility_admission WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_appointment')) {
        mysqli_query($connection, 'DELETE FROM tbl_appointment WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_charge')) {
        mysqli_query($connection, 'DELETE FROM tbl_charge WHERE facility_id = ' . $fid . ' AND patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_credit_followup')) {
        mysqli_query(
            $connection,
            'DELETE f FROM tbl_credit_followup f INNER JOIN tbl_credit_account ca ON ca.id = f.credit_account_id WHERE ca.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_credit_installment_plan')) {
        mysqli_query(
            $connection,
            'DELETE p FROM tbl_credit_installment_plan p INNER JOIN tbl_credit_account ca ON ca.id = p.credit_account_id WHERE ca.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_credit_payment')) {
        mysqli_query(
            $connection,
            'DELETE p FROM tbl_credit_payment p INNER JOIN tbl_credit_account ca ON ca.id = p.credit_account_id WHERE ca.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_credit_adjustment')) {
        mysqli_query(
            $connection,
            'DELETE a FROM tbl_credit_adjustment a INNER JOIN tbl_credit_account ca ON ca.id = a.credit_account_id WHERE ca.patient_id IN (' . $in . ')'
        );
    }
    if (hms_db_table_exists($connection, 'tbl_credit_account')) {
        mysqli_query($connection, 'DELETE FROM tbl_credit_account WHERE patient_id IN (' . $in . ')');
    }
    if (hms_db_table_exists($connection, 'tbl_patient_insurance')) {
        mysqli_query($connection, 'DELETE FROM tbl_patient_insurance WHERE patient_id IN (' . $in . ')');
    }
    mysqli_query($connection, 'DELETE FROM tbl_patient WHERE id IN (' . $in . ')');

    mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=1');

    staff_cleanup:
    if (!hms_db_table_exists($connection, 'tbl_employee')) {
        return;
    }
    $eq = mysqli_query(
        $connection,
        "SELECT id FROM tbl_employee WHERE username LIKE '" . mysqli_real_escape_string($connection, HMS_DEMO_2YR_STAFF_PREFIX) . "%'"
    );
    if (!$eq) {
        return;
    }
    $eids = [];
    while ($row = mysqli_fetch_assoc($eq)) {
        $eids[] = (int) ($row['id'] ?? 0);
    }
    mysqli_free_result($eq);
    if ($eids === []) {
        return;
    }
    $ein = implode(',', array_map('intval', $eids));
    mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=0');
    if (hms_db_table_exists($connection, 'tbl_user_facility')) {
        mysqli_query($connection, 'DELETE FROM tbl_user_facility WHERE employee_id IN (' . $ein . ')');
    }
    mysqli_query($connection, 'DELETE FROM tbl_employee WHERE id IN (' . $ein . ')');
    mysqli_query($connection, 'SET FOREIGN_KEY_CHECKS=1');
}

function hms_demo_rand_datetime_in_range(string $fromYmd, string $toYmd): string
{
    $a = strtotime($fromYmd . ' 08:00:00');
    $b = strtotime($toYmd . ' 18:00:00');
    if ($a === false || $b === false || $b <= $a) {
        return date('Y-m-d H:i:s');
    }
    $t = random_int($a, $b);

    return date('Y-m-d H:i:s', $t);
}

/**
 * @param array{first:string,last:string,email:string,dob:string,gender:string,type:string,addr:string,phone:string} $p
 */
function hms_demo_insert_patient(mysqli $connection, int $facilityId, array $p): int
{
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    if ($ms) {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status, facility_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        if ($st) {
            $stVal = 1;
            mysqli_stmt_bind_param(
                $st,
                'ssssssssii',
                $p['first'],
                $p['last'],
                $p['email'],
                $p['dob'],
                $p['gender'],
                $p['type'],
                $p['addr'],
                $p['phone'],
                $stVal,
                $facilityId
            );
            mysqli_stmt_execute($st);
            $id = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);

            return $id;
        }
    } else {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        if ($st) {
            $stVal = 1;
            mysqli_stmt_bind_param(
                $st,
                'ssssssssi',
                $p['first'],
                $p['last'],
                $p['email'],
                $p['dob'],
                $p['gender'],
                $p['type'],
                $p['addr'],
                $p['phone'],
                $stVal
            );
            mysqli_stmt_execute($st);
            $id = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);

            return $id;
        }
    }

    return 0;
}

function hms_demo_next_doc_number(mysqli $connection, int $facilityId, string $year): string
{
    static $seq = 0;
    $seq++;

    return 'DEMO-' . $year . '-' . $facilityId . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
}

/**
 * Insert charge + billing receipt + transaction row; sets historical created_at when columns exist.
 *
 * @return array{charge_id:int,bdoc_id:int}
 */
function hms_demo_fiscal_receipt(
    mysqli $connection,
    int $facilityId,
    int $patientId,
    int $userId,
    float $amount,
    string $paymentMethod,
    string $whenDt,
    string $description,
    string $sourceModule,
    int $sourcePk
): array {
    $out = ['charge_id' => 0, 'bdoc_id' => 0];
    if (!hms_db_table_exists($connection, 'tbl_charge') || !hms_db_table_exists($connection, 'tbl_billing_document')) {
        return $out;
    }
    $y = substr($whenDt, 0, 4);
    $docNum = hms_demo_next_doc_number($connection, $facilityId, $y);
    $amt = round($amount, 2);
    if ($amt <= 0) {
        return $out;
    }
    $posted = substr($whenDt, 0, 10) . ' ' . (strlen($whenDt) > 10 ? substr($whenDt, 11) : '12:00:00');
    $cpt = 'DEMO';
    $desc = substr($description, 0, 500);

    $hasCc = hms_db_column_exists($connection, 'tbl_charge', 'credit_account_id');
    $hasOc = hms_db_column_exists($connection, 'tbl_charge', 'on_credit');
    if ($hasCc && $hasOc) {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at, credit_account_id, on_credit) VALUES (?,?,?,?,?,?,NULL,0)'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'iissds', $facilityId, $patientId, $cpt, $desc, $amt, $posted);
            mysqli_stmt_execute($st);
            $out['charge_id'] = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);
        }
    } else {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at) VALUES (?,?,?,?,?,?)'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'iissds', $facilityId, $patientId, $cpt, $desc, $amt, $posted);
            mysqli_stmt_execute($st);
            $out['charge_id'] = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);
        }
    }

    $notes = substr('Demo seed — ' . $desc, 0, 580);
    $docNumEsc = mysqli_real_escape_string($connection, $docNum);
    $payEsc = mysqli_real_escape_string($connection, $paymentMethod);
    $modEsc = mysqli_real_escape_string($connection, $sourceModule);
    $notesEsc = mysqli_real_escape_string($connection, $notes);
    $postEsc = mysqli_real_escape_string($connection, $posted);
    $chId = (int) $out['charge_id'];
    $sqlB = 'INSERT INTO tbl_billing_document (facility_id, doc_type, doc_number, patient_id, company_id, payer_snapshot, company_snapshot, total_amount, tax_amount, payment_method, status, source_module, source_pk, charge_id, transaction_id, consultation_id, prescription_id, prescription_line_id, lab_result_id, notes, created_by, created_at) VALUES ('
        . (int) $facilityId . ", 'receipt', '" . $docNumEsc . "', " . (int) $patientId . ', NULL, \'Demo patient\', NULL, '
        . $amt . ', 0, \'' . $payEsc . "', 'issued', '" . $modEsc . "', " . (int) $sourcePk . ', '
        . $chId . ', NULL, NULL, NULL, NULL, NULL, \'' . $notesEsc . '\', ' . (int) $userId . ", '" . $postEsc . "')";
    if (!mysqli_query($connection, $sqlB)) {
        return $out;
    }
    $out['bdoc_id'] = (int) mysqli_insert_id($connection);

    if (hms_db_table_exists($connection, 'tbl_billing_document_line') && $out['bdoc_id'] > 0) {
        $ln = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_billing_document_line (document_id, sort_order, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)'
        );
        if ($ln) {
            $so = 0;
            $q = 1.0;
            mysqli_stmt_bind_param($ln, 'iisddd', $out['bdoc_id'], $so, $desc, $q, $amt, $amt);
            mysqli_stmt_execute($ln);
            mysqli_stmt_close($ln);
        }
    }

    if (hms_db_table_exists($connection, 'tbl_transaction')
        && hms_db_column_exists($connection, 'tbl_transaction', 'facility_id')
        && hms_db_column_exists($connection, 'tbl_transaction', 'created_by')) {
        $hasBdoc = hms_db_column_exists($connection, 'tbl_transaction', 'billing_document_id');
        $td = substr($whenDt, 0, 10);
        $descTxn = substr($description . ' · ' . $docNum, 0, 500);
        $pay = function_exists('hms_billing_normalize_payment_method') ? hms_billing_normalize_payment_method($paymentMethod) : $paymentMethod;
        $amtStr = number_format($amt, 2, '.', '');
        $stT = $hasBdoc
            ? mysqli_prepare(
                $connection,
                'INSERT INTO tbl_transaction (facility_id, patient_id, description, amount, payment_method, status, transaction_date, created_by, billing_document_id) VALUES (?,?,?,?,?,?,?,?,?)'
            )
            : mysqli_prepare(
                $connection,
                'INSERT INTO tbl_transaction (facility_id, patient_id, description, amount, payment_method, status, transaction_date, created_by) VALUES (?,?,?,?,?,?,?,?)'
            );
        if ($stT) {
            $stat = 'completed';
            $bid = $out['bdoc_id'];
            if ($hasBdoc) {
                mysqli_stmt_bind_param($stT, 'iisssssii', $facilityId, $patientId, $descTxn, $amtStr, $pay, $stat, $td, $userId, $bid);
            } else {
                mysqli_stmt_bind_param($stT, 'iisssssi', $facilityId, $patientId, $descTxn, $amtStr, $pay, $stat, $td, $userId);
            }
            mysqli_stmt_execute($stT);
            mysqli_stmt_close($stT);
        }
    }

    return $out;
}

/**
 * Single expense row (escaped SQL — same pattern as expense-management-new.php). GL post when enabled.
 *
 * @return int 1 if inserted, 0 on failure
 */
function hms_demo_insert_expense_row(
    mysqli $connection,
    int $facilityId,
    int $userId,
    string $expenseDateYmd,
    string $category,
    string $description,
    int $amountXaf,
    string $paymentMethod,
    string $reference,
    string $vendor,
    string $notes
): int {
    if (!hms_db_table_exists($connection, 'tbl_expense') || $amountXaf < 1) {
        return 0;
    }
    if (!hms_db_column_exists($connection, 'tbl_expense', 'amount_xaf')) {
        return 0;
    }
    $fid = (int) $facilityId;
    $uid = (int) $userId;
    $amt = (int) $amountXaf;
    $esc = static function (string $s) use ($connection): string {
        return mysqli_real_escape_string($connection, $s);
    };

    $descFinal = $description;
    $notesFinal = $notes;
    if (!hms_db_column_exists($connection, 'tbl_expense', 'reference')) {
        $tag = 'DEMO2YR-EXP ' . substr($reference, 0, 100);
        if (hms_db_column_exists($connection, 'tbl_expense', 'notes')) {
            $notesFinal = trim($notesFinal . ' [' . $tag . ']');
        } elseif (hms_db_column_exists($connection, 'tbl_expense', 'description')) {
            $descFinal = substr(trim($descFinal) . ' [' . $tag . ']', 0, 512);
        }
    }

    $cols = [];
    $vals = [];
    $push = static function (string $col, string $sqlValue) use (&$cols, &$vals): void {
        $cols[] = '`' . str_replace('`', '``', $col) . '`';
        $vals[] = $sqlValue;
    };

    $push('facility_id', (string) $fid);
    $push('expense_date', "'" . $esc(substr($expenseDateYmd, 0, 10)) . "'");
    $push('category', "'" . $esc(substr($category, 0, 120)) . "'");
    $push('description', "'" . $esc(substr($descFinal, 0, 512)) . "'");
    $push('amount_xaf', (string) $amt);
    if (hms_db_column_exists($connection, 'tbl_expense', 'payment_method')) {
        $push('payment_method', "'" . $esc(substr($paymentMethod, 0, 64)) . "'");
    }
    if (hms_db_column_exists($connection, 'tbl_expense', 'reference')) {
        $push('reference', "'" . $esc(substr($reference, 0, 120)) . "'");
    }
    if (hms_db_column_exists($connection, 'tbl_expense', 'vendor')) {
        $push('vendor', "'" . $esc(substr($vendor, 0, 200)) . "'");
    }
    if (hms_db_column_exists($connection, 'tbl_expense', 'notes')) {
        $push('notes', "'" . $esc($notesFinal) . "'");
    }
    if (hms_db_column_exists($connection, 'tbl_expense', 'created_by')) {
        $push('created_by', (string) $uid);
    }

    $sql = 'INSERT INTO tbl_expense (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
    if (!mysqli_query($connection, $sql)) {
        return 0;
    }
    $eid = (int) mysqli_insert_id($connection);
    if ($eid < 1) {
        return 0;
    }
    if (function_exists('hms_fin_post_expense_to_gl') && function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection)) {
        hms_fin_post_expense_to_gl(
            $connection,
            $fid,
            $eid,
            substr($expenseDateYmd, 0, 10),
            $amt,
            $paymentMethod !== '' ? $paymentMethod : null,
            $category,
            $description,
            $uid
        );
    }

    return 1;
}

/**
 * Operating expenses for Expense Management + GL: rent, ENEO/Camwater, payroll, supplies, transport,
 * quarterly fees/maintenance/insurance, bi-monthly lab reagents, mid-year & year-end tax instalments.
 * Rows use reference prefix HMS_DEMO_2YR_EXPENSE_REF_PREFIX for cleanup.
 *
 * @return int Number of rows inserted
 */
function hms_demo_seed_expenses_cameroon_2yr(
    mysqli $connection,
    int $facilityId,
    int $userId,
    string $fromYmd,
    string $toYmd
): int {
    if (!hms_db_table_exists($connection, 'tbl_expense')) {
        return 0;
    }
    $uid = $userId > 0 ? $userId : 1;
    $fid = (int) $facilityId;

    $seq = 0;
    $mkRef = static function (string $ym) use (&$seq): string {
        $seq++;

        return HMS_DEMO_2YR_EXPENSE_REF_PREFIX . 'EXP-' . $ym . '-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    };

    $insert = static function (
        mysqli $connection,
        int $fid,
        int $uid,
        string $ed,
        string $cat,
        string $desc,
        int $amt,
        string $pay,
        string $ref,
        string $ven,
        string $notes
    ): int {
        return hms_demo_insert_expense_row($connection, $fid, $uid, $ed, $cat, $desc, $amt, $pay, $ref, $ven, $notes);
    };

    $n = 0;
    $monthTs = strtotime(substr($fromYmd, 0, 7) . '-01');
    $endMonth = strtotime(substr($toYmd, 0, 7) . '-01');
    if ($monthTs === false || $endMonth === false) {
        return 0;
    }

    while ($monthTs <= $endMonth) {
        $ym = date('Y-m', $monthTs);
        $yr = (int) date('Y', $monthTs);
        $mo = (int) date('n', $monthTs);
        $dim = (int) date('t', $monthTs);

        $j = ($mo * 7 + $yr % 12) % 5;

        $d = static function (int $day) use ($yr, $mo, $dim): string {
            $day = max(1, min($day, $dim));

            return sprintf('%04d-%02d-%02d', $yr, $mo, $day);
        };

        $rent = 780000 + $j * 15000;
        $eneo = 220000 + $j * 18000;
        $water = 52000 + $j * 4000;
        $salary = 4250000 + $j * 25000;
        $supply = 195000 + $j * 12000;
        $orange = 115000 + $j * 5000;
        $fuel = 165000 + $j * 9000;
        $bankFee = 18500 + $j * 1000;

        $n += $insert($connection, $fid, $uid, $d(5), 'Rent', 'Loyer des locaux — bail commercial (démo)', $rent, 'bank', $mkRef($ym), 'SCI Immo Akwa', 'Demo seed — zone Douala / Akwa');
        $n += $insert($connection, $fid, $uid, $d(10), 'Utilities', 'Électricité ENEO — site principal', $eneo, 'mobile_money', $mkRef($ym), 'ENEO Cameroun', 'Demo seed — facture mensuelle');
        $n += $insert($connection, $fid, $uid, $d(14), 'Utilities', 'Eau potable Camwater / SED', $water, 'bank', $mkRef($ym), 'Camwater', 'Demo seed');
        $n += $insert($connection, $fid, $uid, $d(25), 'Salaries & wages', 'Masse salariale net + charges (démo)', $salary, 'bank', $mkRef($ym), 'Virement paie — personnel', 'Demo seed — fin de mois');
        $n += $insert(
            $connection,
            $fid,
            $uid,
            $d(11 + ($j % 7)),
            'Supplies',
            'Consommables bloc & pansements',
            $supply,
            ($j % 2 === 0) ? 'mobile_money' : 'cash',
            $mkRef($ym),
            'Grossiste médical — Douala',
            'Demo seed CM'
        );
        $n += $insert($connection, $fid, $uid, $d(18), 'Communications', 'Fibre + forfaits équipes terrain', $orange, 'mobile_money', $mkRef($ym), 'Orange Cameroun', 'Demo seed');
        $n += $insert(
            $connection,
            $fid,
            $uid,
            $d(16 + ($j % 5)),
            'Transport',
            'Carburant & petit entretien flotte',
            $fuel,
            ($j % 3 === 0) ? 'card' : 'cash',
            $mkRef($ym),
            'TotalEnergies / station partenaire',
            'Demo seed — ambulances & logistique'
        );
        $n += $insert($connection, $fid, $uid, $d(3), 'Bank charges', 'Commissions & frais de tenue de compte', $bankFee, 'other', $mkRef($ym), 'Afriland First Bank / SGBC', 'Demo seed — relevé mensuel');

        if ($mo % 3 === 0) {
            $n += $insert($connection, $fid, $uid, $d(7), 'Professional fees', 'Honoraires expert-comptable & conseil fiscal', 650000, 'bank', $mkRef($ym), 'Cabinet Missionnaire — Yaoundé', 'Demo seed — trimestriel');
            $n += $insert($connection, $fid, $uid, $d(9), 'Maintenance', 'Contrat maintenance climatisation & groupes électrogènes', 420000, 'bank', $mkRef($ym), 'Froid & Énergie SARL', 'Demo seed — trimestriel');
            $n += $insert($connection, $fid, $uid, $d(21), 'Insurance', 'Assurance RC professionnelle & biens', 980000, 'bank', $mkRef($ym), 'Activa Assurance Cameroun', 'Demo seed — prime trimestrielle');
        }

        if ($mo % 2 === 0) {
            $n += $insert(
                $connection,
                $fid,
                $uid,
                $d(19),
                'Supplies',
                'Réactifs laboratoire — commande bi-mensuelle',
                265000 + $j * 8000,
                'bank',
                $mkRef($ym),
                'Bio-Rad / distributeur régional',
                'Demo seed — bi-mensuel pairs'
            );
        }

        if ($mo === 6 || $mo === 12) {
            $n += $insert(
                $connection,
                $fid,
                $uid,
                $d(15),
                'Taxes & duties',
                'Acompte taxes & redevances (démo OHADA)',
                1180000,
                'bank',
                $mkRef($ym),
                'Impôts — acompte déclaré',
                'Demo seed — juin & décembre'
            );
        }

        $monthTs = strtotime('+1 month', $monthTs);
    }

    return $n;
}

/**
 * @return array{ok:bool,messages:list<string>,counts:array<string,int>}
 */
function hms_demo_seed_cameroon_2yr(mysqli $connection, int $facilityId, int $userId): array
{
    hms_demo_seed_mysqli_report_off();
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    @ini_set('memory_limit', '256M');

    $messages = [];
    $counts = [
        'staff' => 0,
        'patients' => 0,
        'consultations' => 0,
        'appointments' => 0,
        'opd_visits' => 0,
        'admissions' => 0,
        'lab' => 0,
        'rad' => 0,
        'receipts' => 0,
        'credit_accounts' => 0,
        'expenses' => 0,
    ];

    /* Align with month-based reports (e.g. From = 1st of month); still ~24 months of activity. */
    $from = date('Y-m-01', strtotime('-24 months'));
    $to = date('Y-m-d');

    if (!hms_db_table_exists($connection, 'tbl_facility')) {
        return ['ok' => false, 'messages' => ['Database missing tbl_facility.'], 'counts' => $counts];
    }

    $origFacilityId = (int) $facilityId;
    $facilityId = hms_demo_resolve_facility_id($connection, $facilityId);
    if ($origFacilityId !== $facilityId) {
        $messages[] = 'Using facility_id ' . $facilityId . ' for this seed (session had ' . $origFacilityId . ' — expenses require a valid tbl_facility row).';
    }

    hms_demo_seed_cleanup($connection, $facilityId);
    $messages[] = 'Removed any previous demo-2yr seed data for this facility.';

    $regions = [
        'Douala, Akwa (Littoral)',
        'Yaoundé, Mvan (Centre)',
        'Bafoussam (Ouest)',
        'Bamenda (Nord-Ouest)',
        'Garoua (Nord)',
        'Maroua (Extrême-Nord)',
        'Bertoua (Est)',
        'Limbe (Sud-Ouest)',
        'Kribi (Sud)',
    ];

    $docFirst = ['Paul', 'Marie', 'Jean', 'Chantal', 'Brice', 'Estelle', 'Samuel', 'Yvette', 'Daniel', 'Hortense', 'Patrick', 'Adèle', 'François', 'Béatrice', 'Luc', 'Sandrine', 'Alain', 'Jacqueline', 'Roger', 'Mireille'];
    $docLast = ['Fotsing', 'Atangana', 'Nguimfack', 'Essama', 'Mvogo', 'Oumarou', 'Ndzana', 'Akum', 'Metogo', 'Mvondo', 'Owona', 'Kuété', 'Tchouassi', 'Bilogo', 'Nkodo', 'Abena', 'Kamdem', 'Mbarga', 'Djeuda', 'Fotso'];
    $nurseFirst = ['Grace', 'Solange', 'Irène', 'Prisca', 'Lydie', 'Carine', 'Stéphanie', 'Julienne', 'Mireille', 'Blandine', 'Sylvie', 'Aline'];
    $aidFirst = ['Junior', 'Serge', 'Fabrice', 'Calvin', 'Rodrigue', 'Bruno', 'Cédric', 'Landry', 'Gilles', 'Steve'];

    $pwd = 'HMS-demo2yr-2026!';
    $roleDoc = '2';
    $roleNurse = '7';
    $roleAid = '8';

    $staffDocIds = [];
    $staffNurseIds = [];

    if (hms_db_table_exists($connection, 'tbl_employee')) {
        for ($i = 0; $i < 18; $i++) {
            $fn = $docFirst[$i % count($docFirst)];
            $ln = $docLast[$i % count($docLast)];
            $un = HMS_DEMO_2YR_STAFF_PREFIX . 'doc.' . $i;
            $em = 'doc' . $i . HMS_DEMO_2YR_EMAIL_SUFFIX;
            $phone = '677' . str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT);
            $bio = 'Médecin démo — ' . $regions[$i % count($regions)];
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_employee (first_name, last_name, username, emailid, password, dob, gender, address, bio, employee_id, joining_date, phone, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $dob = '01/06/1980';
                $g = ($i % 2 === 0) ? 'Male' : 'Female';
                $addr = $regions[$i % count($regions)];
                $eid = 'CAM-DEMO-D' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $jd = '01/01/2018';
                $stat = 1;
                mysqli_stmt_bind_param($st, 'sssssssssssssi', $fn, $ln, $un, $em, $pwd, $dob, $g, $addr, $bio, $eid, $jd, $phone, $roleDoc, $stat);
                if (mysqli_stmt_execute($st)) {
                    $eidRow = (int) mysqli_insert_id($connection);
                    $staffDocIds[] = $eidRow;
                    $counts['staff']++;
                    if (hms_db_table_exists($connection, 'tbl_user_facility')) {
                        mysqli_query(
                            $connection,
                            'INSERT IGNORE INTO tbl_user_facility (employee_id, facility_id, is_default) VALUES (' . $eidRow . ',' . (int) $facilityId . ',0)'
                        );
                    }
                    if (hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
                        $dept = ['General Medicine', 'Surgery', 'Pediatrics', 'Obstetrics', 'Internal Medicine'][$i % 5];
                        $pst = mysqli_prepare($connection, 'UPDATE tbl_employee SET primary_department = ? WHERE id = ? LIMIT 1');
                        if ($pst) {
                            mysqli_stmt_bind_param($pst, 'si', $dept, $eidRow);
                            mysqli_stmt_execute($pst);
                            mysqli_stmt_close($pst);
                        }
                    }
                }
                mysqli_stmt_close($st);
            }
        }
        for ($i = 0; $i < 14; $i++) {
            $fn = $nurseFirst[$i % count($nurseFirst)];
            $ln = $docLast[($i + 3) % count($docLast)];
            $un = HMS_DEMO_2YR_STAFF_PREFIX . 'nurse.' . $i;
            $em = 'nurse' . $i . HMS_DEMO_2YR_EMAIL_SUFFIX;
            $phone = '699' . str_pad((string) (200000 + $i), 6, '0', STR_PAD_LEFT);
            $bio = 'Infirmière / infirmier démo';
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_employee (first_name, last_name, username, emailid, password, dob, gender, address, bio, employee_id, joining_date, phone, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $dob = '15/03/1992';
                $g = 'Female';
                $addr = $regions[$i % count($regions)];
                $eid = 'CAM-DEMO-N' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $jd = '01/06/2019';
                $stat = 1;
                mysqli_stmt_bind_param($st, 'sssssssssssssi', $fn, $ln, $un, $em, $pwd, $dob, $g, $addr, $bio, $eid, $jd, $phone, $roleNurse, $stat);
                if (mysqli_stmt_execute($st)) {
                    $nid = (int) mysqli_insert_id($connection);
                    $staffNurseIds[] = $nid;
                    $counts['staff']++;
                    if (hms_db_table_exists($connection, 'tbl_user_facility')) {
                        mysqli_query(
                            $connection,
                            'INSERT IGNORE INTO tbl_user_facility (employee_id, facility_id, is_default) VALUES (' . $nid . ',' . (int) $facilityId . ',0)'
                        );
                    }
                    if (hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
                        $dept = 'Nursing';
                        $pst = mysqli_prepare($connection, 'UPDATE tbl_employee SET primary_department = ? WHERE id = ? LIMIT 1');
                        if ($pst) {
                            mysqli_stmt_bind_param($pst, 'si', $dept, $nid);
                            mysqli_stmt_execute($pst);
                            mysqli_stmt_close($pst);
                        }
                    }
                }
                mysqli_stmt_close($st);
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $fn = $aidFirst[$i % count($aidFirst)];
            $ln = $docLast[($i + 7) % count($docLast)];
            $un = HMS_DEMO_2YR_STAFF_PREFIX . 'aid.' . $i;
            $em = 'aid' . $i . HMS_DEMO_2YR_EMAIL_SUFFIX;
            $phone = '683' . str_pad((string) (300000 + $i), 6, '0', STR_PAD_LEFT);
            $bio = 'Aide-soignant(e) démo';
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_employee (first_name, last_name, username, emailid, password, dob, gender, address, bio, employee_id, joining_date, phone, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $dob = '20/01/1995';
                $g = ($i % 3 === 0) ? 'Male' : 'Female';
                $addr = $regions[$i % count($regions)];
                $eid = 'CAM-DEMO-A' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $jd = '01/03/2020';
                $stat = 1;
                mysqli_stmt_bind_param($st, 'sssssssssssssi', $fn, $ln, $un, $em, $pwd, $dob, $g, $addr, $bio, $eid, $jd, $phone, $roleAid, $stat);
                if (mysqli_stmt_execute($st)) {
                    $counts['staff']++;
                    $aid = (int) mysqli_insert_id($connection);
                    if (hms_db_table_exists($connection, 'tbl_user_facility')) {
                        mysqli_query(
                            $connection,
                            'INSERT IGNORE INTO tbl_user_facility (employee_id, facility_id, is_default) VALUES (' . $aid . ',' . (int) $facilityId . ',0)'
                        );
                    }
                    if (hms_db_column_exists($connection, 'tbl_employee', 'primary_department')) {
                        $dept = 'Nursing';
                        $pst = mysqli_prepare($connection, 'UPDATE tbl_employee SET primary_department = ? WHERE id = ? LIMIT 1');
                        if ($pst) {
                            mysqli_stmt_bind_param($pst, 'si', $dept, $aid);
                            mysqli_stmt_execute($pst);
                            mysqli_stmt_close($pst);
                        }
                    }
                }
                mysqli_stmt_close($st);
            }
        }
    }

    $pickDoc = static function () use ($staffDocIds): int {
        if ($staffDocIds === []) {
            return 0;
        }

        return $staffDocIds[array_rand($staffDocIds)];
    };

    $patientSeq = 0;
    $mkPatient = static function (string $first, string $last, string $type, string $addr, string $phone, string $gender, string $dob) use (&$patientSeq, $connection, $facilityId): int {
        ++$patientSeq;
        $email = 'p' . $patientSeq . HMS_DEMO_2YR_EMAIL_SUFFIX;

        return hms_demo_insert_patient($connection, $facilityId, [
            'first' => $first,
            'last' => $last,
            'email' => $email,
            'dob' => $dob,
            'gender' => $gender,
            'type' => $type,
            'addr' => $addr,
            'phone' => $phone,
        ]);
    };

    $scenarios = [
        ['tag' => 'full_episode', 'pay' => 'Cash'],
        ['tag' => 'full_episode', 'pay' => 'Mobile Money'],
        ['tag' => 'full_episode', 'pay' => 'Orange Money'],
        ['tag' => 'full_episode', 'pay' => 'Card'],
        ['tag' => 'full_episode', 'pay' => 'Bank Transfer'],
        ['tag' => 'full_episode', 'pay' => 'Insurance'],
        ['tag' => 'emergency', 'pay' => 'Cash'],
        ['tag' => 'partial', 'pay' => 'Cash'],
        ['tag' => 'waiver', 'pay' => 'Cash'],
        ['tag' => 'ar_default', 'pay' => 'Cash'],
        ['tag' => 'ar_collections', 'pay' => 'Cash'],
        ['tag' => 'ar_writeoff', 'pay' => 'Cash'],
    ];

    $pf = ['Loïc', 'Mirabelle', 'Stéphane', 'Audrey', 'Fabrice', 'Mélanie', 'Cédric', 'Inès', 'Rodrigue', 'Vanessa', 'Gilles', 'Karen'];
    $pl = ['Talla', 'Ngué', 'Essomba', 'Kamdem', 'Mvondo', 'Abena', 'Fotso', 'Nkeng', 'Djeukam', 'Mbarga', 'Tchuisseu', 'Nana'];

    foreach ($scenarios as $si => $sc) {
        $when = hms_demo_rand_datetime_in_range($from, $to);
        $whenDate = substr($when, 0, 10);
        $pid = $mkPatient(
            $pf[$si % count($pf)],
            $pl[$si % count($pl)],
            'OutPatient',
            $regions[$si % count($regions)],
            '690' . str_pad((string) (400000 + $si), 6, '0', STR_PAD_LEFT),
            ($si % 2 === 0) ? 'Male' : 'Female',
            '15/06/1994'
        );
        if ($pid < 1) {
            continue;
        }
        $counts['patients']++;

        if (hms_db_table_exists($connection, 'tbl_facility_admission')) {
            mysqli_query(
                $connection,
                'INSERT INTO tbl_facility_admission (facility_id, patient_id, arrival_at, arrival_note, created_by) VALUES ('
                . (int) $facilityId . ',' . $pid . ",'" . mysqli_real_escape_string($connection, $when) . "','Demo arrival'," . (int) $userId . ')'
            );
        }

        $refDoc = $pickDoc();
        $apptId = 0;
        if (($sc['tag'] ?? '') !== 'emergency' && hms_db_table_exists($connection, 'tbl_appointment')) {
            $aid = 'APT-DEMO-' . $facilityId . '-' . $patientSeq;
            $dept = 'General Medicine';
            $docName = 'Dr Demo';
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, facility_id, patient_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $pname = 'Demo Patient';
                $tm = '09:30:00';
                $msg = 'Demo booking';
                $stVal = 1;
                mysqli_stmt_bind_param($st, 'sssssssiii', $aid, $pname, $dept, $docName, $whenDate, $tm, $msg, $stVal, $facilityId, $pid);
                if (mysqli_stmt_execute($st)) {
                    $apptId = (int) mysqli_insert_id($connection);
                    $counts['appointments']++;
                }
                mysqli_stmt_close($st);
            }
        }

        $opdId = 0;
        if (hms_db_table_exists($connection, 'tbl_opd_visit')) {
            $tk = 'OPD-DEMO-' . $facilityId . '-' . $patientSeq . '-' . random_int(100, 999);
            $qs = ($sc['tag'] === 'emergency') ? 'in_progress' : 'completed';
            $sqlOpd = 'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, completed_at, created_by) VALUES ('
                . (int) $facilityId . ',' . $pid . ",'" . mysqli_real_escape_string($connection, $tk) . "','" . mysqli_real_escape_string($connection, $qs) . "','"
                . mysqli_real_escape_string($connection, ($sc['tag'] === 'emergency') ? 'Urgent chest pain (demo)' : 'Follow-up (demo)') . "','General Medicine','"
                . (($sc['tag'] === 'emergency') ? 'urgent' : 'normal') . "','" . mysqli_real_escape_string($connection, $whenDate) . "','"
                . mysqli_real_escape_string($connection, $when) . "',"
                . (($qs === 'completed') ? "'" . mysqli_real_escape_string($connection, $when) . "'" : 'NULL') . ',' . (int) $userId . ')';
            if (mysqli_query($connection, $sqlOpd)) {
                $opdId = (int) mysqli_insert_id($connection);
                $counts['opd_visits']++;
            }
        }

        $consId = 0;
        if (hms_db_table_exists($connection, 'tbl_consultation')) {
            $fee = ($sc['tag'] === 'waiver') ? 0 : 5000;
            $paidAt = $when;
            $cc = ($sc['tag'] === 'emergency') ? 'Emergency demo — stabilised' : 'Routine demo consultation';
            $apptSql = $apptId > 0 ? (string) (int) $apptId : 'NULL';
            $sqlC = 'INSERT INTO tbl_consultation (facility_id, patient_id, consultation_type, status, chief_complaint, consult_fee_xaf, fee_paid_at, appointment_id, created_by, created_at, completed_at) VALUES ('
                . (int) $facilityId . ',' . (int) $pid . ",'general','completed','" . mysqli_real_escape_string($connection, $cc) . "'," . (int) $fee . ",'"
                . mysqli_real_escape_string($connection, $paidAt) . "'," . $apptSql . ',' . (int) $userId . ",'"
                . mysqli_real_escape_string($connection, $when) . "','" . mysqli_real_escape_string($connection, $when) . "')";
            if (mysqli_query($connection, $sqlC)) {
                $consId = (int) mysqli_insert_id($connection);
                $counts['consultations']++;
            }
        }

        $pay = (string) ($sc['pay'] ?? 'Cash');
        if (($sc['tag'] ?? '') === 'waiver') {
            if ($consId > 0) {
                hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 1.0, $pay, $when, 'Consultation — symbolic fiscal line for waiver (demo)', 'consultation_fee', $consId);
                $counts['receipts']++;
            }
            if (hms_db_table_exists($connection, 'tbl_credit_account')) {
                mysqli_query(
                    $connection,
                    'INSERT INTO tbl_credit_account (facility_id, patient_id, status, emergency_payment_pending, notes, opened_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ",'closed',0,'Waiver demo — approved hospital reduction', '" . mysqli_real_escape_string($connection, $when) . "')"
                );
            }
        } elseif (($sc['tag'] ?? '') !== 'ar_default' && ($sc['tag'] ?? '') !== 'ar_collections' && ($sc['tag'] ?? '') !== 'ar_writeoff') {
            $r1 = hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 5000.0, $pay, $when, 'Consultation fee (demo)', 'consultation_fee', max(1, $consId));
            if ($r1['bdoc_id'] > 0) {
                $counts['receipts']++;
            }
        }

        if (hms_db_table_exists($connection, 'tbl_lab_result')) {
            $apLab = $whenDate;
            $ref = $refDoc > 0 ? $refDoc : null;
            if ($ref === null) {
                $sqlL = 'INSERT INTO tbl_lab_result (facility_id, patient_id, test_name, appointment_date, status, notes, created_by, created_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ",'CBC (demo)','" . mysqli_real_escape_string($connection, $apLab) . "','received','Demo seed'," . (int) $userId . ",'" . mysqli_real_escape_string($connection, $when) . "')";
            } else {
                $sqlL = 'INSERT INTO tbl_lab_result (facility_id, patient_id, referred_by_id, test_name, appointment_date, status, notes, created_by, created_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ',' . (int) $ref . ",'CBC (demo)','" . mysqli_real_escape_string($connection, $apLab) . "','received','Demo seed'," . (int) $userId . ",'" . mysqli_real_escape_string($connection, $when) . "')";
            }
            if (mysqli_query($connection, $sqlL)) {
                $counts['lab']++;
                $labId = (int) mysqli_insert_id($connection);
                if (($sc['tag'] ?? '') !== 'ar_default' && ($sc['tag'] ?? '') !== 'ar_collections' && ($sc['tag'] ?? '') !== 'ar_writeoff') {
                    $rw = hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 15000.0, $pay, $when, 'Laboratory — CBC (demo)', 'lab_fee', $labId);
                    if ($rw['bdoc_id'] > 0) {
                        $counts['receipts']++;
                    }
                }
            }
        }

        if (hms_db_table_exists($connection, 'tbl_radiology_result')) {
            $apR = $whenDate;
            $ref = $refDoc > 0 ? $refDoc : null;
            if ($ref === null) {
                $sqlR = 'INSERT INTO tbl_radiology_result (facility_id, patient_id, exam_name, modality, body_part, appointment_date, status, findings, notes, created_by, created_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ",'Chest X-Ray (demo)','X-Ray','thorax','" . mysqli_real_escape_string($connection, $apR) . "','received','No acute lesion (demo).','Demo seed'," . (int) $userId . ",'" . mysqli_real_escape_string($connection, $when) . "')";
            } else {
                $sqlR = 'INSERT INTO tbl_radiology_result (facility_id, patient_id, referred_by_id, exam_name, modality, body_part, appointment_date, status, findings, notes, created_by, created_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ',' . (int) $ref . ",'Chest X-Ray (demo)','X-Ray','thorax','" . mysqli_real_escape_string($connection, $apR) . "','received','No acute lesion (demo).','Demo seed'," . (int) $userId . ",'" . mysqli_real_escape_string($connection, $when) . "')";
            }
            if (mysqli_query($connection, $sqlR)) {
                $counts['rad']++;
                $radId = (int) mysqli_insert_id($connection);
                if (($sc['tag'] ?? '') !== 'ar_default' && ($sc['tag'] ?? '') !== 'ar_collections' && ($sc['tag'] ?? '') !== 'ar_writeoff') {
                    $rw = hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 12000.0, $pay, $when, 'Radiology — chest X-ray (demo)', 'lab_fee', $radId);
                    if ($rw['bdoc_id'] > 0) {
                        $counts['receipts']++;
                    }
                }
            }
        }

        if (($sc['tag'] ?? '') === 'full_episode' && hms_db_table_exists($connection, 'tbl_bed') && hms_db_table_exists($connection, 'tbl_admission')) {
            $bq = mysqli_query(
                $connection,
                'SELECT id FROM tbl_bed WHERE facility_id = ' . (int) $facilityId . " AND status = 'available' ORDER BY id ASC LIMIT 1"
            );
            $bedId = 0;
            if ($bq) {
                $br = mysqli_fetch_assoc($bq);
                $bedId = (int) ($br['id'] ?? 0);
                mysqli_free_result($bq);
            }
            if ($bedId < 1) {
                mysqli_query(
                    $connection,
                    'INSERT INTO tbl_bed (facility_id, ward_name, bed_label, status) VALUES (' . (int) $facilityId . ",'Demo Ward','B-DEMO-01','occupied')"
                );
                $bedId = (int) mysqli_insert_id($connection);
            }
            $admIn = $when;
            $admOut = date('Y-m-d H:i:s', strtotime($when . ' +3 days'));
            $sqlA = 'INSERT INTO tbl_admission (facility_id, patient_id, bed_id, admitted_at, discharged_at, admission_status) VALUES ('
                . (int) $facilityId . ',' . $pid . ',' . (int) $bedId . ",'" . mysqli_real_escape_string($connection, $admIn) . "','"
                . mysqli_real_escape_string($connection, $admOut) . "','discharged')";
            if (mysqli_query($connection, $sqlA)) {
                $counts['admissions']++;
                $admId = (int) mysqli_insert_id($connection);
                if (($sc['tag'] ?? '') !== 'ar_default' && ($sc['tag'] ?? '') !== 'ar_collections' && ($sc['tag'] ?? '') !== 'ar_writeoff') {
                    $daily = 25000.0;
                    $rw = hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, $daily * 3, $pay, $admOut, 'Hospitalisation 3 jours (demo)', 'charge', $admId);
                    if ($rw['bdoc_id'] > 0) {
                        $counts['receipts']++;
                    }
                }
            }
        }

        if (($sc['tag'] ?? '') === 'partial') {
            $p2 = hms_demo_rand_datetime_in_range($from, $to);
            hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 20000.0, $pay, $when, 'Partial settlement #1 (demo)', 'charge', $consId);
            $counts['receipts']++;
            hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, 35000.0, 'Mobile Money', $p2, 'Partial settlement #2 — balance (demo)', 'charge', $consId);
            $counts['receipts']++;
        }

        if (($sc['tag'] ?? '') === 'ar_default' || ($sc['tag'] ?? '') === 'ar_collections') {
            if (hms_db_table_exists($connection, 'tbl_credit_account')) {
                $stn = ($sc['tag'] === 'ar_collections') ? 'collections' : 'active';
                $gEsc = mysqli_real_escape_string($connection, 'Demo Guarantor');
                $nEsc = mysqli_real_escape_string($connection, 'Treatment on credit — demo seed');
                $wEsc = mysqli_real_escape_string($connection, $when);
                if (mysqli_query(
                    $connection,
                    'INSERT INTO tbl_credit_account (facility_id, patient_id, status, emergency_payment_pending, guarantor_name, notes, opened_at) VALUES ('
                    . (int) $facilityId . ',' . $pid . ",'" . mysqli_real_escape_string($connection, $stn) . "',1,'" . $gEsc . "','" . $nEsc . "','" . $wEsc . "')"
                )) {
                    $caId = (int) mysqli_insert_id($connection);
                    $counts['credit_accounts']++;
                    if ($caId > 0 && hms_db_column_exists($connection, 'tbl_charge', 'on_credit')) {
                        mysqli_query(
                            $connection,
                            'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at, credit_account_id, on_credit) VALUES ('
                            . (int) $facilityId . ',' . $pid . ",'CREDIT','Hospitalisation & traitement (demo AR)',125000.00,'" . $wEsc . "'," . $caId . ',1)'
                        );
                        mysqli_query(
                            $connection,
                            'INSERT INTO tbl_credit_payment (credit_account_id, amount, payment_method, notes, created_by) VALUES ('
                            . $caId . ",25000.00,'Cash','Premier versement partiel (demo)'," . (int) $userId . ')'
                        );
                    }
                }
            }
        }

        if (($sc['tag'] ?? '') === 'ar_writeoff' && hms_db_table_exists($connection, 'tbl_credit_account')) {
            $gEsc = mysqli_real_escape_string($connection, 'Demo Guarantor');
            $nEsc = mysqli_real_escape_string($connection, 'Treatment on credit — patient could not repay; eventual write-off (demo)');
            $wEsc = mysqli_real_escape_string($connection, $when);
            $noteWo = mysqli_real_escape_string($connection, 'Board-approved bad debt write-off after failed collections (demo)');
            $cols = 'facility_id, patient_id, status, emergency_payment_pending, guarantor_name, notes, opened_at';
            $vals = (int) $facilityId . ',' . $pid . ",'active',1,'" . $gEsc . "','" . $nEsc . "','" . $wEsc . "'";
            if (hms_db_column_exists($connection, 'tbl_credit_account', 'closed_at')) {
                $cols .= ', closed_at, writeoff_at, writeoff_approved_by, writeoff_note';
                $vals .= ",'" . $wEsc . "','" . $wEsc . "'," . (int) $userId . ",'" . $noteWo . "'";
            }
            if (mysqli_query($connection, 'INSERT INTO tbl_credit_account (' . $cols . ') VALUES (' . $vals . ')')) {
                $caId = (int) mysqli_insert_id($connection);
                $counts['credit_accounts']++;
                if ($caId > 0 && hms_db_column_exists($connection, 'tbl_charge', 'on_credit')) {
                    mysqli_query(
                        $connection,
                        'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at, credit_account_id, on_credit) VALUES ('
                        . (int) $facilityId . ',' . $pid . ",'CREDIT','Hospitalisation & soins — impayé final (demo)',200000.00,'" . $wEsc . "'," . $caId . ',1)'
                    );
                    mysqli_query(
                        $connection,
                        'INSERT INTO tbl_credit_payment (credit_account_id, amount, payment_method, notes, created_by) VALUES ('
                        . $caId . ",30000.00,'Cash','Dernier versement partiel avant défaut (demo)'," . (int) $userId . ')'
                    );
                    if (hms_db_table_exists($connection, 'tbl_credit_followup')) {
                        mysqli_query(
                            $connection,
                            'INSERT INTO tbl_credit_followup (credit_account_id, channel, summary, created_by) VALUES ('
                            . $caId . ",'call','Relances multiples — patient injoignable (demo)'," . (int) $userId . ')'
                        );
                    }
                    if (hms_db_table_exists($connection, 'tbl_credit_adjustment')) {
                        mysqli_query(
                            $connection,
                            'INSERT INTO tbl_credit_adjustment (credit_account_id, kind, amount, approved_by, notes) VALUES ('
                            . $caId . ",'writeoff',170000.00," . (int) $userId . ",'Irrecoverable balance after partial payment (demo)')"
                        );
                    }
                }
                if ($caId > 0) {
                    mysqli_query(
                        $connection,
                        'UPDATE tbl_credit_account SET status = \'written_off\' WHERE id = ' . $caId . ' LIMIT 1'
                    );
                }
            }
        }

        if (($sc['tag'] ?? '') === 'ar_collections' && hms_db_table_exists($connection, 'tbl_credit_followup')) {
            $caq = mysqli_query($connection, 'SELECT id FROM tbl_credit_account WHERE patient_id = ' . $pid . ' ORDER BY id DESC LIMIT 1');
            if ($caq) {
                $car = mysqli_fetch_assoc($caq);
                mysqli_free_result($caq);
                $caid = (int) ($car['id'] ?? 0);
                if ($caid > 0) {
                    mysqli_query(
                        $connection,
                        'INSERT INTO tbl_credit_followup (credit_account_id, channel, summary, created_by) VALUES ('
                        . $caid . ",'call','Relance impayé — patient injoignable (demo)'," . (int) $userId . ')'
                    );
                }
            }
        }

        if ($pay === 'Insurance' && hms_db_table_exists($connection, 'tbl_patient_insurance')) {
            $cq = mysqli_query(
                $connection,
                'SELECT id FROM tbl_insurance_carrier WHERE facility_id = ' . (int) $facilityId . ' LIMIT 1'
            );
            $cid = 0;
            if ($cq) {
                $cr = mysqli_fetch_assoc($cq);
                $cid = (int) ($cr['id'] ?? 0);
                mysqli_free_result($cq);
            }
            if ($cid > 0) {
                if (hms_db_column_exists($connection, 'tbl_patient_insurance', 'insurer_covered_percent')) {
                    mysqli_query(
                        $connection,
                        'INSERT INTO tbl_patient_insurance (facility_id, patient_id, carrier_id, policy_number, is_primary, insurer_covered_percent) VALUES ('
                        . (int) $facilityId . ',' . $pid . ',' . $cid . ",'POL-DEMO-001',1,70)"
                    );
                } else {
                    mysqli_query(
                        $connection,
                        'INSERT INTO tbl_patient_insurance (facility_id, patient_id, carrier_id, policy_number, is_primary) VALUES ('
                        . (int) $facilityId . ',' . $pid . ',' . $cid . ",'POL-DEMO-001',1)"
                    );
                }
            }
        }
    }

    /* Extra volume: spread many small receipts across 24 months for accounting charts */
    for ($k = 0; $k < 40; $k++) {
        $pid = $mkPatient(
            $pf[$k % count($pf)],
            'Batch' . $k,
            'OutPatient',
            $regions[$k % count($regions)],
            '677' . str_pad((string) (500000 + $k), 6, '0', STR_PAD_LEFT),
            'Male',
            '10/10/1988'
        );
        if ($pid < 1) {
            continue;
        }
        $counts['patients']++;
        $when = hms_demo_rand_datetime_in_range($from, $to);
        $methods = ['Cash', 'Mobile Money', 'Orange Money', 'Card', 'Bank Transfer'];
        $pm = $methods[$k % count($methods)];
        $r = hms_demo_fiscal_receipt($connection, $facilityId, $pid, $userId, (float) (5000 + ($k % 8) * 2500), $pm, $when, 'Diverse outpatient services (demo batch)', 'charge', $pid);
        if ($r['bdoc_id'] > 0) {
            $counts['receipts']++;
        }
    }

    if (hms_db_table_exists($connection, 'tbl_expense')) {
        $expN = hms_demo_seed_expenses_cameroon_2yr($connection, $facilityId, $userId, $from, $to);
        $counts['expenses'] = $expN;
        if ($expN > 0) {
            $messages[] = 'Recorded ' . $expN . ' operating expenses over the rolling ~24-month window (rent, utilities, payroll, supplies, transport, quarterly fees, taxes; GL posted when journal is enabled).';
        } else {
            $err = mysqli_error($connection);
            $messages[] = 'Expense seed inserted 0 rows.'
                . ($err !== '' ? (' MySQL: ' . $err) : ' Confirm migration 026_expense_management.sql is applied and tbl_expense columns match the app.');
        }
    } else {
        $messages[] = 'Skipped operating expenses: tbl_expense not found. Run database/migrations/026_expense_management.sql, then run this seed again.';
    }

    /*
     * Fiscal receipts from this seed live in tbl_billing_document only until synced — the GL reads tbl_fin_journal_*.
     * Mirror the Financials → "Post receipts for this period" action so the General ledger is populated after seed.
     */
    if (function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection)) {
        if (function_exists('hms_fin_backfill_receipt_journals_for_date_range')) {
            $glRec = hms_fin_backfill_receipt_journals_for_date_range($connection, $facilityId, $from, $to, 5000);
            $ins = (int) ($glRec['inserted'] ?? 0);
            $proc = (int) ($glRec['processed'] ?? 0);
            $dup = (int) ($glRec['duplicate'] ?? 0);
            $fail = (int) ($glRec['failed'] ?? 0);
            $messages[] = 'General ledger: posted ' . $ins . ' new journal entr' . ($ins === 1 ? 'y' : 'ies')
                . ' from demo fiscal receipts (' . $proc . ' receipt(s) in ' . $from . '–' . $to . '; ' . $dup . ' already in GL; ' . $fail . ' failed).';
            $fe = (string) ($glRec['first_error'] ?? '');
            if ($fe !== '') {
                $messages[] = 'GL receipt sync: ' . $fe;
            }
        }
        if (hms_db_table_exists($connection, 'tbl_expense') && function_exists('hms_fin_backfill_expense_journals_for_date_range')) {
            $glEx = hms_fin_backfill_expense_journals_for_date_range($connection, $facilityId, $from, $to, 5000);
            $eIns = (int) ($glEx['inserted'] ?? 0);
            if ($eIns > 0 || (int) ($glEx['failed'] ?? 0) > 0) {
                $messages[] = 'General ledger: posted ' . $eIns . ' expense journal entr' . ($eIns === 1 ? 'y' : 'ies')
                    . ' (' . (int) ($glEx['processed'] ?? 0) . ' expense row(s); ' . (int) ($glEx['duplicate'] ?? 0) . ' already in GL; '
                    . (int) ($glEx['failed'] ?? 0) . ' failed).';
            }
        }

        /*
         * Catch-up: post journals for the real MIN/MAX dates stored in billing/expense tables so GL is filled
         * even if $from/$to drift from seeded document timestamps.
         */
        if (function_exists('hms_fin_backfill_receipt_journals_for_date_range')
            && function_exists('hms_billing_document_tables_ok') && hms_billing_document_tables_ok($connection)) {
            $br = mysqli_query(
                $connection,
                'SELECT MIN(DATE(created_at)) AS d1, MAX(DATE(created_at)) AS d2 FROM tbl_billing_document WHERE facility_id = '
                . (int) $facilityId . " AND doc_type = 'receipt' AND total_amount > 0.005"
            );
            if ($br && ($span = mysqli_fetch_assoc($br))) {
                mysqli_free_result($br);
                $s1 = (string) ($span['d1'] ?? '');
                $s2 = (string) ($span['d2'] ?? '');
                if ($s1 !== '' && $s2 !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s1) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s2)) {
                    $glSpan = hms_fin_backfill_receipt_journals_for_date_range($connection, $facilityId, $s1, $s2, 5000);
                    $xIns = (int) ($glSpan['inserted'] ?? 0);
                    if ($xIns > 0) {
                        $messages[] = 'General ledger: catch-up posted ' . $xIns . ' more receipt journal entr' . ($xIns === 1 ? 'y' : 'ies')
                            . ' (billing document dates ' . $s1 . '–' . $s2 . ').';
                    }
                    $xfe = (string) ($glSpan['first_error'] ?? '');
                    if ($xfe !== '') {
                        $messages[] = 'GL receipt catch-up: ' . $xfe;
                    }
                }
            }
        }
        if (hms_db_table_exists($connection, 'tbl_expense') && function_exists('hms_fin_backfill_expense_journals_for_date_range')) {
            $er = mysqli_query(
                $connection,
                'SELECT MIN(expense_date) AS d1, MAX(expense_date) AS d2 FROM tbl_expense WHERE facility_id = ' . (int) $facilityId
            );
            if ($er && ($espan = mysqli_fetch_assoc($er))) {
                mysqli_free_result($er);
                $e1 = (string) ($espan['d1'] ?? '');
                $e2 = (string) ($espan['d2'] ?? '');
                if ($e1 !== '' && $e2 !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e1) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e2)) {
                    $glEx2 = hms_fin_backfill_expense_journals_for_date_range($connection, $facilityId, $e1, $e2, 5000);
                    $e2Ins = (int) ($glEx2['inserted'] ?? 0);
                    if ($e2Ins > 0) {
                        $messages[] = 'General ledger: catch-up posted ' . $e2Ins . ' more expense journal entr' . ($e2Ins === 1 ? 'y' : 'ies')
                            . ' (expense_date span ' . $e1 . '–' . $e2 . ').';
                    }
                }
            }
        }
    } elseif (function_exists('hms_fin_tables_ok') && !hms_fin_tables_ok($connection)) {
        $messages[] = 'General ledger skipped: run database/migrations/019_credit_receivables.sql to create tbl_fin_journal_* — then re-seed or use Financials → Sync to GL.';
    }

    $messages[] = 'Demo seed finished. Staff password (plaintext legacy): HMS-demo2yr-2026!';
    $messages[] = 'Remove anytime with “Clean demo data” on this page.';

    return ['ok' => true, 'messages' => $messages, 'counts' => $counts];
}
