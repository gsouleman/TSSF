<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_permission($connection, 'billing.write');
$fid = hms_current_facility_id();

if (isset($_POST['save']) && hms_csrf_validate($_POST['hms_csrf'] ?? null) && hms_db_table_exists($connection, 'tbl_charge')) {
    $pid  = (int)($_POST['patient_id'] ?? 0);
    $cpt  = (string)($_POST['cpt_code'] ?? '');
    $desc = (string)($_POST['description'] ?? '');
    $amt  = (float)($_POST['amount'] ?? 0);
    $payM = hms_billing_normalize_payment_method($_POST['payment_method'] ?? 'Cash');
    $opdEp = (int) ($_POST['opd_visit_id'] ?? 0);
    $hospEp = (int) ($_POST['hospitalization_id'] ?? 0);
    $onCredit = !empty($_POST['post_on_credit']) && hms_credit_tables_ok($connection) && hms_credit_can_write($connection);
    $creditAcctId = (int) ($_POST['credit_account_id'] ?? 0);
    $hasCreditCols = hms_db_column_exists($connection, 'tbl_charge', 'on_credit')
        && hms_db_column_exists($connection, 'tbl_charge', 'credit_account_id');
    if ($onCredit && $creditAcctId > 0 && $hasCreditCols) {
        $chk = mysqli_prepare(
            $connection,
            'SELECT id FROM tbl_credit_account WHERE id = ? AND patient_id = ? AND facility_id = ? AND status = ? LIMIT 1'
        );
        $okAcct = false;
        if ($chk) {
            $stActive = 'active';
            mysqli_stmt_bind_param($chk, 'iiis', $creditAcctId, $pid, $fid, $stActive);
            mysqli_stmt_execute($chk);
            $okAcct = (bool) hms_stmt_fetch_assoc($chk);
            mysqli_stmt_close($chk);
        }
        if (!$okAcct) {
            $onCredit = false;
            $creditAcctId = 0;
        }
    } else {
        $onCredit = false;
        $creditAcctId = 0;
    }

    if ($hasCreditCols) {
        if ($onCredit) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at, credit_account_id, on_credit) VALUES (?,?,?,?,?,NOW(),?,1)'
            );
            if ($st && $pid > 0) {
                mysqli_stmt_bind_param($st, 'iissdi', $fid, $pid, $cpt, $desc, $amt, $creditAcctId);
            }
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at, credit_account_id, on_credit) VALUES (?,?,?,?,?,NOW(),NULL,0)'
            );
            if ($st && $pid > 0) {
                mysqli_stmt_bind_param($st, 'iissd', $fid, $pid, $cpt, $desc, $amt);
            }
        }
    } else {
        $st = mysqli_prepare($connection, 'INSERT INTO tbl_charge (facility_id, patient_id, cpt_code, description, amount, posted_at) VALUES (?,?,?,?,?,NOW())');
        if ($st && $pid > 0) {
            mysqli_stmt_bind_param($st, 'iissd', $fid, $pid, $cpt, $desc, $amt);
        }
    }
    if ($st && $pid > 0) {
        mysqli_stmt_execute($st);
        $newCh = (int)mysqli_insert_id($connection);
        mysqli_stmt_close($st);
        hms_audit_log($connection, 'charge.create', 'charge', $newCh);
        if ($newCh > 0 && $onCredit && function_exists('hms_fin_post_credit_charge_accrual')) {
            hms_fin_post_credit_charge_accrual($connection, $fid, $newCh, $amt, (int) ($_SESSION['user_id'] ?? 0), $desc !== '' ? $desc : 'Service charge');
        }
        if ($newCh > 0 && hms_billing_document_tables_ok($connection) && !$onCredit) {
            $uid   = (int)($_SESSION['user_id'] ?? 0);
            $docOpts = [
                'facility_id' => $fid,
                'patient_id' => $pid,
                'payment_method' => $payM,
                'source_module' => 'charge',
                'source_pk' => $newCh,
                'charge_id' => $newCh,
                'created_by' => $uid,
            ];
            if ($opdEp > 0) {
                $docOpts['opd_visit_id'] = $opdEp;
            }
            if ($hospEp > 0) {
                $docOpts['hospitalization_id'] = $hospEp;
            }
            $docId = hms_billing_create_document(
                $connection,
                $docOpts,
                [['description'=>$desc !== '' ? $desc : 'Service charge','quantity'=>1,'unit_price'=>$amt]]
            );
            if (is_int($docId) && $docId > 0) hms_billing_set_print_prompt($docId);
        }
        header('Location: billing-payments.php');
        exit;
    }
}

$suf       = hms_multi_site_enabled($connection) ? ' WHERE facility_id = '.(int)$fid : '';
$catOk     = hms_db_table_exists($connection, 'tbl_service_catalog');
$creditReady = hms_credit_tables_ok($connection) && hms_credit_can_write($connection);
$creditAccounts = [];
if ($creditReady) {
    $cq = mysqli_query(
        $connection,
        'SELECT ca.id, ca.patient_id, ca.emergency_payment_pending, p.first_name, p.last_name
         FROM tbl_credit_account ca
         INNER JOIN tbl_patient p ON p.id = ca.patient_id
         WHERE ca.facility_id = ' . (int) $fid . " AND ca.status = 'active'
         ORDER BY ca.id DESC LIMIT 200"
    );
    while ($cq && $cr = mysqli_fetch_assoc($cq)) {
        $creditAccounts[] = $cr;
    }
}

// Load catalog for grouping in the picker
$catalog = [];
if ($catOk) {
    $q = mysqli_query($connection,
        "SELECT id, category, subcategory, name, cpt_code, price FROM tbl_service_catalog
         WHERE (facility_id=".(int)$fid." OR facility_id=0) AND status=1
         ORDER BY category, subcategory, sort_order, name LIMIT 500");
    while ($q && $row = mysqli_fetch_assoc($q)) $catalog[] = $row;
}

include 'header.php';
?>
<div class="page-wrapper"><div class="content hms-module">
    <?php hms_ui_page_header('Post Charge', [
        'subtitle'    => 'Record a service charge for a patient. Fiscal receipts must tie to an OPD visit, a facility admission (arrival), or an active hospitalization (bed). Leave optional fields blank to use an open walk-in admission automatically.',
        'breadcrumbs' => [['Billing','billing-payments.php'],['Post Charge','']],
        'back'        => 'billing-payments.php',
    ]); ?>
    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-9">
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-body">
                    <form method="post" id="chargeForm">
                        <?php echo hms_csrf_field(); ?>

                        <!-- Patient -->
                        <div class="form-group">
                            <label for="charge_patient">Patient <span class="text-danger">*</span></label>
                            <select name="patient_id" id="charge_patient" class="form-control select2" required>
                                <option value="">— Select patient —</option>
                                <?php
                                $q = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name, first_name LIMIT 500');
                                while ($q && $r = mysqli_fetch_assoc($q)) {
                                    echo '<option value="'.(int)$r['id'].'">'.hms_h($r['first_name'].' '.$r['last_name']).'</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <?php if (!empty($catalog)) { ?>
                        <!-- Catalog Picker -->
                        <div class="form-group">
                            <label for="catalog_picker">Pick from Service Catalog <span class="text-muted small">(auto-fills fields below)</span></label>
                            <select id="catalog_picker" class="form-control select2">
                                <option value="">— Or select a service —</option>
                                <?php
                                $lastCat = null;
                                foreach ($catalog as $svc) {
                                    $catLabel = ['consultation'=>'Consultations','laboratory'=>'Laboratory','service'=>'Services','pharmacy'=>'Pharmacy'][$svc['category']] ?? $svc['category'];
                                    if ($svc['category'] !== $lastCat) {
                                        if ($lastCat !== null) echo '</optgroup>';
                                        echo '<optgroup label="'.hms_h($catLabel).'">';
                                        $lastCat = $svc['category'];
                                    }
                                    $price = number_format((float)$svc['price'],0,'.',' ');
                                    echo '<option value="'.(int)$svc['id'].'"
                                        data-name="'.hms_h((string)$svc['name']).'"
                                        data-cpt="'.hms_h((string)$svc['cpt_code']).'"
                                        data-price="'.hms_h((string)$svc['price']).'">'.hms_h($svc['name']).' — '.$price.' FCFA</option>';
                                }
                                if ($lastCat !== null) echo '</optgroup>';
                                ?>
                            </select>
                        </div>
                        <hr class="my-3">
                        <?php } ?>

                        <!-- CPT Code -->
                        <div class="form-group">
                            <label for="charge_cpt">CPT Code <span class="text-muted small">(optional)</span></label>
                            <input id="charge_cpt" class="form-control" name="cpt_code" placeholder="e.g. C001">
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <label for="charge_desc">Service Description <span class="text-danger">*</span></label>
                            <input id="charge_desc" class="form-control" name="description" required placeholder="e.g. Consultation Généraliste">
                        </div>

                        <!-- Amount -->
                        <div class="form-group">
                            <label for="charge_amount">Amount (<?php echo hms_h(hms_currency_label()); ?>) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input id="charge_amount" class="form-control" name="amount" type="number" step="1" min="0" required placeholder="e.g. 3000">
                                <div class="input-group-append"><span class="input-group-text">FCFA</span></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="charge_opd_visit">OPD visit id <span class="text-muted small">(optional)</span></label>
                                <input id="charge_opd_visit" class="form-control" name="opd_visit_id" type="number" min="0" step="1" placeholder="e.g. ticket row id from OPD queue">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="charge_hosp">Hospitalization id <span class="text-muted small">(optional)</span></label>
                                <input id="charge_hosp" class="form-control" name="hospitalization_id" type="number" min="0" step="1" placeholder="tbl_admission id if patient is in a bed">
                            </div>
                        </div>

                        <?php if ($creditReady && $creditAccounts !== []) { ?>
                        <div class="alert alert-secondary border mb-0">
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input" id="post_on_credit" name="post_on_credit" value="1">
                                <label class="custom-control-label" for="post_on_credit">Post on patient credit (no receipt now — accrues to Credit &amp; Receivables)</label>
                            </div>
                            <div class="form-group mb-0">
                                <label for="credit_account_id">Credit account <span class="text-muted small">(must match patient)</span></label>
                                <select name="credit_account_id" id="credit_account_id" class="form-control">
                                    <option value="">— Select credit line —</option>
                                    <?php foreach ($creditAccounts as $ca) {
                                        $lab = '#' . (int) $ca['id'] . ' · ' . trim((string) $ca['first_name'] . ' ' . (string) $ca['last_name']);
                                        if (!empty($ca['emergency_payment_pending'])) {
                                            $lab .= ' · Emergency / payment pending';
                                        }
                                        ?>
                                    <option value="<?php echo (int) $ca['id']; ?>" data-patient-id="<?php echo (int) $ca['patient_id']; ?>"><?php echo hms_h($lab); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <?php } elseif ($creditReady) { ?>
                        <p class="text-muted small mb-0">No open credit accounts. Open one from <a href="credit-receivables.php">Credit &amp; Receivables</a> or the patient chart.</p>
                        <?php } ?>

                        <div class="form-group">
                            <label for="charge_pay_method">Payment method <span class="text-muted small">(used when not posting on credit)</span></label>
                            <select id="charge_pay_method" class="form-control" name="payment_method">
                                <?php foreach (hms_billing_payment_method_options() as $pm) { ?>
                                <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <a href="billing-payments.php" class="btn btn-outline-secondary mr-2">Cancel</a>
                            <button class="btn btn-primary" type="submit" name="save" value="1"><i class="fa fa-check mr-1"></i>Post charge</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div></div>
<script>
(function () {
    var picker = document.getElementById('catalog_picker');
    var fldCpt  = document.getElementById('charge_cpt');
    var fldDesc = document.getElementById('charge_desc');
    var fldAmt  = document.getElementById('charge_amount');
    if (!picker) return;
    picker.addEventListener('change', function () {
        var opt = picker.options[picker.selectedIndex];
        if (!opt.value) return;
        fldCpt.value  = opt.dataset.cpt   || '';
        fldDesc.value = opt.dataset.name  || '';
        fldAmt.value  = opt.dataset.price || '';
    });
})();
(function () {
    var pat = document.getElementById('charge_patient');
    var ca = document.getElementById('credit_account_id');
    var cb = document.getElementById('post_on_credit');
    if (!pat || !ca || !cb) return;
    function syncCreditOptions() {
        var pid = pat.value || '';
        var i, o, showAny = false;
        for (i = 0; i < ca.options.length; i++) {
            o = ca.options[i];
            if (!o.value) { o.hidden = false; continue; }
            var match = (o.getAttribute('data-patient-id') || '') === pid;
            o.hidden = !match;
            if (match) showAny = true;
        }
        if (!pid || !showAny) {
            cb.checked = false;
            ca.value = '';
        }
    }
    pat.addEventListener('change', syncCreditOptions);
    syncCreditOptions();
})();
</script>
<?php include 'footer.php'; ?>
