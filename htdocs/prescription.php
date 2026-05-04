<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'prescription.read');
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$rxId = (int) ($_GET['id'] ?? 0);
if ($rxId < 1) {
    header('Location: prescriptions.php');
    exit;
}

$ok = hms_workflow_table_ok($connection, 'tbl_prescription');
$flash = isset($_SESSION['rx_flash']) ? (string) $_SESSION['rx_flash'] : '';
unset($_SESSION['rx_flash']);

$st = mysqli_prepare($connection, 'SELECT * FROM tbl_prescription WHERE id = ? AND facility_id = ? LIMIT 1');
mysqli_stmt_bind_param($st, 'ii', $rxId, $fid);
mysqli_stmt_execute($st);
$rx = hms_stmt_fetch_assoc($st);
mysqli_stmt_close($st);
if (!$rx) {
    header('Location: prescriptions.php');
    exit;
}

$pid = (int) $rx['patient_id'];

if ($ok && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (isset($_POST['add_lab_line']) && hms_can($connection, 'prescription.write')) {
        $lid = (int) ($_POST['lab_catalog_id'] ?? 0);
        if ($lid > 0) {
            $mx = mysqli_fetch_assoc(mysqli_query($connection, 'SELECT IFNULL(MAX(sort_order),0)+1 AS n FROM tbl_prescription_line WHERE prescription_id = ' . (int) $rxId));
            $so = (int) ($mx['n'] ?? 1);
            $ins = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_prescription_line (prescription_id, line_type, lab_catalog_id, sort_order) VALUES (?,\'lab\',?,?)'
            );
            mysqli_stmt_bind_param($ins, 'iii', $rxId, $lid, $so);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            hms_audit_log($connection, 'prescription.line.lab', 'prescription', $rxId);
            $flash = 'Lab line added.';
        }
    }
    if (isset($_POST['add_med_line']) && hms_can($connection, 'prescription.write')) {
        $pcid = (int) ($_POST['pharmacy_catalog_id'] ?? 0);
        $nm = trim((string) ($_POST['medication_name'] ?? ''));
        if ($nm === '' && $pcid > 0) {
            $crow = hms_billing_catalog_service_row($connection, $fid, $pcid);
            if ($crow && strtolower(trim((string) ($crow['category'] ?? ''))) === 'pharmacy') {
                $nm = trim((string) ($crow['name'] ?? ''));
            } else {
                $pcid = 0;
            }
        }
        if ($nm !== '') {
            $dose = trim((string) ($_POST['medication_dose'] ?? ''));
            $route = trim((string) ($_POST['medication_route'] ?? ''));
            $freq = trim((string) ($_POST['medication_frequency'] ?? ''));
            $dur = (int) ($_POST['duration_days'] ?? 0);
            $mx = mysqli_fetch_assoc(mysqli_query($connection, 'SELECT IFNULL(MAX(sort_order),0)+1 AS n FROM tbl_prescription_line WHERE prescription_id = ' . (int) $rxId));
            $so = (int) ($mx['n'] ?? 1);
            $hasPhCat = hms_db_column_exists($connection, 'tbl_prescription_line', 'pharmacy_catalog_id');
            if ($hasPhCat) {
                $ins = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_prescription_line (prescription_id, line_type, medication_name, medication_dose, medication_route, medication_frequency, duration_days, sort_order, pharmacy_catalog_id) VALUES (?,\'medication\',?,?,?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'isssssiii', $rxId, $nm, $dose, $route, $freq, $dur, $so, $pcid);
            } else {
                $ins = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_prescription_line (prescription_id, line_type, medication_name, medication_dose, medication_route, medication_frequency, duration_days, sort_order) VALUES (?,\'medication\',?,?,?,?,?,?)'
                );
                mysqli_stmt_bind_param($ins, 'isssssii', $rxId, $nm, $dose, $route, $freq, $dur, $so);
            }
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            hms_audit_log($connection, 'prescription.line.med', 'prescription', $rxId);
            $flash = 'Medication line added.';
        } elseif ($pcid > 0) {
            $flash = 'Choose a formulary item or enter a drug name.';
        }
    }
    if (isset($_POST['dispense_med']) && hms_can($connection, 'pharmacy.write')) {
        $lid = (int) ($_POST['line_id'] ?? 0);
        $qty = max(1, (int) ($_POST['dispense_qty'] ?? 1));
        $iid = (int) ($_POST['inventory_item_id'] ?? 0);
        $lnChk = mysqli_prepare(
            $connection,
            'SELECT pl.id FROM tbl_prescription_line pl
             INNER JOIN tbl_prescription r ON r.id = pl.prescription_id
             WHERE pl.id = ? AND pl.prescription_id = ? AND r.facility_id = ? AND pl.line_type = \'medication\' LIMIT 1'
        );
        $lineOk = false;
        if ($lnChk) {
            mysqli_stmt_bind_param($lnChk, 'iii', $lid, $rxId, $fid);
            mysqli_stmt_execute($lnChk);
            $lineOk = (bool) hms_stmt_fetch_assoc($lnChk);
            mysqli_stmt_close($lnChk);
        }
        if (!$lineOk) {
            $flash = 'That medication line is not valid for this prescription or site.';
        } elseif ($iid > 0 && !hms_workflow_table_ok($connection, 'tbl_inventory_item')) {
            $flash = 'Inventory is not enabled; choose “— Stock —” or run the inventory migration.';
        } elseif ($iid > 0 && !hms_inventory_item_in_facility($connection, $iid, $fid)) {
            $flash = 'Selected stock item is not on this site.';
        } else {
            mysqli_begin_transaction($connection);
            $txOk = true;
            if ($iid > 0) {
                $invSt = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_inventory_item SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($invSt) {
                    mysqli_stmt_bind_param($invSt, 'iii', $qty, $iid, $fid);
                    mysqli_stmt_execute($invSt);
                    if (mysqli_stmt_affected_rows($invSt) < 1) {
                        $txOk = false;
                        $flash = 'Could not deduct stock (item missing or wrong site).';
                    } else {
                        hms_inventory_log_dispense_movement($connection, $fid, $iid, $qty, $rxId, $lid, $uid);
                    }
                    mysqli_stmt_close($invSt);
                } else {
                    $txOk = false;
                    $flash = 'Could not update inventory.';
                }
            }
            if ($txOk) {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_prescription_line SET dispense_status = \'dispensed\', dispensed_qty = dispensed_qty + ?, dispensed_at = NOW(), inventory_item_id = IF(? > 0, ?, inventory_item_id) WHERE id = ? AND prescription_id = ? AND line_type = \'medication\' LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'iiiii', $qty, $iid, $iid, $lid, $rxId);
                    mysqli_stmt_execute($up);
                    if (mysqli_stmt_affected_rows($up) < 1) {
                        $txOk = false;
                        $flash = 'Could not update prescription line.';
                    }
                    mysqli_stmt_close($up);
                } else {
                    $txOk = false;
                    $flash = 'Could not update prescription line.';
                }
            }
            if ($txOk) {
                mysqli_commit($connection);
                hms_audit_log($connection, 'pharmacy.dispense', 'prescription_line', $lid);
                $flash = 'Dispense recorded.';
                $saleCatalogId = (int) ($_POST['sale_fee_catalog_id'] ?? 0);
                $saleCat = $saleCatalogId > 0 ? hms_billing_catalog_row_by_id($connection, $fid, $saleCatalogId) : null;
                $saleAmt = max(0.0, (float) ($_POST['sale_amount_xaf'] ?? 0));
                if ($saleAmt <= 0.0 && $saleCat !== null) {
                    $saleAmt = (float) $saleCat['amount'];
                }
                $salePay = hms_billing_normalize_payment_method($_POST['sale_payment_method'] ?? 'Cash');
                $saleWantInvoice = isset($_POST['sale_fiscal_document']) && (string) $_POST['sale_fiscal_document'] === 'invoice';
                $saleCompanyId = (int) ($_POST['sale_billing_company_id'] ?? 0);
                if ($saleAmt > 0 && hms_billing_document_tables_ok($connection) && hms_can($connection, 'billing.write')) {
                    $medLabel = 'Medication dispense';
                    if ($saleCat !== null && ($saleCat['label'] ?? '') !== '') {
                        $medLabel = 'Pharmacy: ' . (string) $saleCat['label'];
                    } else {
                        $nmQ = mysqli_prepare(
                            $connection,
                            'SELECT medication_name FROM tbl_prescription_line WHERE id = ? AND prescription_id = ? LIMIT 1'
                        );
                        if ($nmQ) {
                            mysqli_stmt_bind_param($nmQ, 'ii', $lid, $rxId);
                            mysqli_stmt_execute($nmQ);
                            $nmR = hms_stmt_fetch_assoc($nmQ);
                            mysqli_stmt_close($nmQ);
                            if ($nmR && trim((string) ($nmR['medication_name'] ?? '')) !== '') {
                                $medLabel = 'Pharmacy: ' . trim((string) $nmR['medication_name']);
                            }
                        }
                    }
                    $uniq = (int) (microtime(true) * 10000) % 2000000000;
                    $docType = 'receipt';
                    $companyBind = 0;
                    if ($saleWantInvoice && $saleCompanyId > 0 && hms_db_table_exists($connection, 'tbl_billing_company')) {
                        $pco = mysqli_prepare($connection, 'SELECT id FROM tbl_billing_company WHERE id = ? AND facility_id = ? AND status = 1 LIMIT 1');
                        if ($pco) {
                            mysqli_stmt_bind_param($pco, 'ii', $saleCompanyId, $fid);
                            mysqli_stmt_execute($pco);
                            if (hms_stmt_fetch_assoc($pco)) {
                                $docType = 'invoice';
                                $companyBind = $saleCompanyId;
                            }
                            mysqli_stmt_close($pco);
                        }
                    }
                    $docOpts = [
                        'facility_id' => $fid,
                        'patient_id' => $pid,
                        'doc_type' => $docType,
                        'payment_method' => $salePay,
                        'source_module' => 'pharmacy_dispense',
                        'source_pk' => $uniq,
                        'prescription_id' => $rxId,
                        'prescription_line_id' => $lid,
                        'created_by' => $uid,
                        'skip_if_exists' => false,
                    ];
                    if ($companyBind > 0) {
                        $docOpts['company_id'] = $companyBind;
                    }
                    $hospRx = function_exists('hms_hospitalization_open_id_for_patient')
                        ? hms_hospitalization_open_id_for_patient($connection, $fid, $pid)
                        : 0;
                    if ($hospRx > 0) {
                        $docOpts['hospitalization_id'] = $hospRx;
                    }
                    $docId = hms_billing_create_document(
                        $connection,
                        $docOpts,
                        [
                            ['description' => $medLabel, 'quantity' => 1, 'unit_price' => $saleAmt],
                        ]
                    );
                    if (is_int($docId) && $docId > 0) {
                        hms_billing_set_print_prompt($docId);
                        $flash .= $docType === 'invoice' ? ' Invoice issued.' : ' Receipt issued.';
                    }
                } elseif ($saleAmt > 0 && !hms_can($connection, 'billing.write')) {
                    $flash .= ' No fiscal document: billing permission required.';
                }
            } else {
                mysqli_rollback($connection);
                if ($flash === '') {
                    $flash = 'Dispense was not saved.';
                }
            }
        }
    }
    $_SESSION['rx_flash'] = $flash;
    header('Location: prescription.php?id=' . $rxId);
    exit;
}

$rxPrintDoc = hms_billing_take_print_prompt();
include 'header.php';
$labs = hms_lab_catalog_rows($connection);
$pharmacyCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'pharmacy');
$rxBillingCompanies = [];
if (hms_db_table_exists($connection, 'tbl_billing_company')) {
    $rxbq = mysqli_query(
        $connection,
        'SELECT id, name FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . ' AND status = 1 ORDER BY name LIMIT 300'
    );
    while ($rxbq && $rxbr = mysqli_fetch_assoc($rxbq)) {
        $rxBillingCompanies[] = $rxbr;
    }
}
$inv = [];
if (hms_workflow_table_ok($connection, 'tbl_inventory_item')) {
    $invHasSvc = hms_db_column_exists($connection, 'tbl_inventory_item', 'service_catalog_id');
    if ($invHasSvc) {
        $iq = mysqli_query(
            $connection,
            'SELECT i.id, i.sku, i.name, i.quantity, i.service_catalog_id, sc.price AS unit_price
             FROM tbl_inventory_item i
             LEFT JOIN tbl_service_catalog sc ON sc.id = i.service_catalog_id
             WHERE i.facility_id = ' . (int) $fid . ' ORDER BY i.name LIMIT 500'
        );
    } else {
        $iq = mysqli_query($connection, 'SELECT id, sku, name, quantity FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid . ' ORDER BY name LIMIT 500');
    }
    while ($iq && $ir = mysqli_fetch_assoc($iq)) {
        $inv[] = $ir;
    }
}
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            $rxLinks = [];
            if (hms_can($connection, 'clinical.read')) {
                $rxLinks[] = ['label' => 'Patient chart', 'url' => 'patient-chart.php?id=' . $pid, 'icon' => 'fa-user'];
            }
            hms_ui_page_header('Prescription #' . $rxId, [
                'subtitle' => (string) $rx['title'],
                'breadcrumbs' => [['Prescriptions', 'prescriptions.php'], ['#' . $rxId, '']],
                'back' => 'prescriptions.php',
                'secondary' => $rxLinks,
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if ($rxPrintDoc > 0) { ?>
            <div class="alert alert-success">
                Fiscal document ready (PDF).
                <a class="alert-link font-weight-bold" target="_blank" href="billing-document-pdf.php?id=<?php echo (int) $rxPrintDoc; ?>">Download PDF</a>
                <span class="small">(</span><a class="alert-link small" target="_blank" href="billing-document-print.php?id=<?php echo (int) $rxPrintDoc; ?>">HTML</a><span class="small">)</span>
            </div>
            <iframe title="Receipt PDF" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0)" src="billing-document-pdf.php?id=<?php echo (int) $rxPrintDoc; ?>"></iframe>
            <?php } ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>003_clinical_workflow.sql</code>.</div>
            <?php } else { ?>
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-form-card">
                        <div class="card-header bg-white font-weight-bold">Add lab test (from catalogue)</div>
                        <div class="card-body">
                            <?php if (hms_can($connection, 'prescription.write')) { ?>
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-group">
                                    <select name="lab_catalog_id" class="form-control" required>
                                        <option value="">— Select test —</option>
                                        <?php foreach ($labs as $lc) {
                                            echo '<option value="' . (int) $lc['id'] . '">' . hms_h((string) $lc['code'] . ' — ' . (string) $lc['name']) . '</option>';
                                        } ?>
                                    </select>
                                </div>
                                <button type="submit" name="add_lab_line" value="1" class="btn btn-primary btn-sm">Add lab line</button>
                            </form>
                            <?php } else { ?><p class="text-muted small mb-0">Read-only.</p><?php } ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-form-card">
                        <div class="card-header bg-white font-weight-bold">Add medication</div>
                        <div class="card-body">
                            <?php if (hms_can($connection, 'prescription.write')) { ?>
                            <form method="post" id="rx_med_form">
                                <?php echo hms_csrf_field(); ?>
                                <?php if ($pharmacyCatalogRows !== []) { ?>
                                <div class="form-group">
                                    <label class="small font-weight-bold text-muted mb-1">Pharmacy formulary (optional)</label>
                                    <select class="form-control" id="rx_pharmacy_catalog_pick" name="pharmacy_catalog_id">
                                        <option value="0">— Type manually or pick from formulary —</option>
                                        <?php foreach ($pharmacyCatalogRows as $pc) {
                                            $pid = (int) ($pc['id'] ?? 0);
                                            $pname = trim((string) ($pc['name'] ?? ''));
                                            if ($pid < 1 || $pname === '') {
                                                continue;
                                            }
                                            echo '<option value="' . $pid . '">' . hms_h($pname) . '</option>';
                                        } ?>
                                    </select>
                                    <small class="form-text text-muted">Selecting a row fills the drug name and links pricing for dispensing.</small>
                                </div>
                                <?php } else { ?>
                                <input type="hidden" name="pharmacy_catalog_id" value="0">
                                <?php } ?>
                                <div class="form-group"><input class="form-control" name="medication_name" id="rx_med_name" placeholder="Drug name" required></div>
                                <div class="form-row">
                                    <div class="col"><input class="form-control" name="medication_dose" placeholder="Dose"></div>
                                    <div class="col"><input class="form-control" name="medication_route" placeholder="Route"></div>
                                </div>
                                <div class="form-row mt-2">
                                    <div class="col"><input class="form-control" name="medication_frequency" placeholder="Frequency"></div>
                                    <div class="col"><input class="form-control" name="duration_days" type="number" min="0" placeholder="Days"></div>
                                </div>
                                <button type="submit" name="add_med_line" value="1" class="btn btn-primary btn-sm mt-2">Add medication line</button>
                            </form>
                            <?php } else { ?><p class="text-muted small mb-0">Read-only.</p><?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm hms-data-card mb-3">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
                    <span class="font-weight-bold">Lines</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>#</th><th>Type</th><th>Detail</th><th>Dispense</th><?php if (hms_can($connection, 'pharmacy.write')) { ?><th>Pharmacy</th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $existingDrugsJs = [];
                            $q = mysqli_query(
                                $connection,
                                'SELECT pl.*, lc.code AS lab_code FROM tbl_prescription_line pl
                                 LEFT JOIN tbl_lab_catalog lc ON lc.id = pl.lab_catalog_id
                                 WHERE pl.prescription_id = ' . (int) $rxId . ' ORDER BY pl.sort_order, pl.id'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                if ($r['line_type'] === 'medication') {
                                    $existingDrugsJs[] = strtolower(trim((string)$r['medication_name']));
                                }
                                $det = $r['line_type'] === 'lab'
                                    ? hms_h((string) ($r['lab_code'] ?? ''))
                                    : hms_h((string) $r['medication_name']) . ' ' . hms_h((string) $r['medication_dose']);
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td>' . hms_h((string) $r['line_type']) . '</td>';
                                echo '<td>' . $det . '</td>';
                                echo '<td>' . hms_h((string) $r['dispense_status']) . ' / ' . (int) $r['dispensed_qty'] . '</td>';
                                if (hms_can($connection, 'pharmacy.write') && $r['line_type'] === 'medication') {
                                    echo '<td><form method="post" class="hms-pharm-disp-form">';
                                    echo hms_csrf_field();
                                    echo '<input type="hidden" name="line_id" value="' . (int) $r['id'] . '">';
                                    echo '<div class="d-flex flex-wrap align-items-center">';
                                    echo '<input type="number" name="dispense_qty" value="1" min="1" class="form-control form-control-sm mr-1 mb-1" style="width:56px" title="Qty">';
                                    echo '<select name="inventory_item_id" class="form-control form-control-sm mr-1 mb-1" style="min-width:120px;max-width:180px"><option value="0">— Stock —</option>';
                                    foreach ($inv as $it) {
                                        $iid = (int) $it['id'];
                                        $iqty = (int) $it['quantity'];
                                        $lab = hms_h((string) $it['name']) . ' (' . $iqty . ')';
                                        $up = isset($it['unit_price']) ? (float) $it['unit_price'] : 0.0;
                                        $scid = isset($it['service_catalog_id']) ? (int) $it['service_catalog_id'] : 0;
                                        $dp = $up > 0 ? hms_h((string) round($up)) : '';
                                        $dc = $scid > 0 ? (string) $scid : '';
                                        echo '<option value="' . $iid . '" data-price="' . $dp . '" data-cat="' . hms_h($dc) . '">' . $lab . '</option>';
                                    }
                                    echo '</select></div>';
                                    echo '<div class="d-flex flex-wrap align-items-center mt-1">';
                                    if ($pharmacyCatalogRows !== []) {
                                        echo '<select name="sale_fee_catalog_id" class="form-control form-control-sm mr-1 mb-1 hms-rx-pharm-cat" style="max-width:200px" title="Catalog price">';
                                        echo '<option value="0">Catalog…</option>';
                                        foreach ($pharmacyCatalogRows as $pc) {
                                            $px = (float) ($pc['price'] ?? 0);
                                            echo '<option value="' . (int) ($pc['id'] ?? 0) . '" data-price="' . hms_h((string) $px) . '">' . hms_h((string) ($pc['name'] ?? '')) . '</option>';
                                        }
                                        echo '</select>';
                                    }
                                    echo '<input type="number" name="sale_amount_xaf" min="0" step="1" class="form-control form-control-sm mr-1 mb-1 hms-rx-sale-amt" style="width:88px" placeholder="FCFA" title="Amount collected">';
                                    echo '<select name="sale_payment_method" class="form-control form-control-sm mb-1">';
                                    foreach (hms_billing_payment_method_options() as $pm) {
                                        echo '<option value="' . hms_h($pm) . '">' . hms_h($pm) . '</option>';
                                    }
                                    echo '</select></div>';
                                    echo '<div class="d-flex flex-wrap align-items-center mt-1">';
                                    echo '<select name="sale_fiscal_document" class="form-control form-control-sm mr-1 mb-1 hms-rx-fiscal">';
                                    echo '<option value="receipt">Receipt</option>';
                                    echo '<option value="invoice"' . ($rxBillingCompanies === [] ? ' disabled' : '') . '>Invoice</option>';
                                    echo '</select>';
                                    echo '<span class="hms-rx-co-wrap d-none mr-1 mb-1" style="min-width:140px;max-width:200px"><select name="sale_billing_company_id" class="form-control form-control-sm">';
                                    echo '<option value="0">Company…</option>';
                                    foreach ($rxBillingCompanies as $xbc) {
                                        echo '<option value="' . (int) ($xbc['id'] ?? 0) . '">' . hms_h((string) ($xbc['name'] ?? '')) . '</option>';
                                    }
                                    echo '</select></span>';
                                    echo '<button class="btn btn-sm btn-success mb-1" type="submit" name="dispense_med" value="1">Dispense</button></div></form></td>';
                                } elseif (hms_can($connection, 'pharmacy.write')) {
                                    echo '<td>—</td>';
                                }
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <p class="small text-muted">Workflow: add lab lines for documentation; lab processing uses <a href="lab-results.php">Lab results</a>. Medications: <strong>Dispense</strong> from stock when ready. Optional catalog price, payment method, and receipt vs company invoice follow the same fiscal rules as <a href="receipts-invoices.php">Receipts &amp; invoices</a>.</p>
            <?php } ?>
        </div></div>

        <!-- CDS Modal -->
        <div class="modal fade" id="cdsInteractionModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fa fa-warning"></i> Clinical Decision Support Alert</h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <h6 class="font-weight-bold text-danger">Severe Drug-Drug Interaction Detected!</h6>
                        <p id="cdsInteractionMsg"></p>
                        <p class="small text-muted mb-0">First Databank (FDB) API check simulated.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel Prescription</button>
                        <button type="button" class="btn btn-outline-danger" id="cdsInteractionOverrideBtn">Override &amp; Proceed</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var existingDrugs = <?php echo json_encode($existingDrugsJs ?? []); ?>;
            var medForm = document.getElementById('rx_med_form');
            var overrideBtn = document.getElementById('cdsInteractionOverrideBtn');
            var isOverridden = false;

            if (medForm) {
                medForm.addEventListener('submit', function(e) {
                    var pick = document.getElementById('rx_pharmacy_catalog_pick');
                    var nmEl = document.getElementById('rx_med_name');
                    if (nmEl && pick && pick.value && pick.value !== '0' && !nmEl.value.trim()) {
                        nmEl.value = pick.options[pick.selectedIndex].text.trim();
                    }
                    if (isOverridden) return true;
                    
                    var newDrug = document.getElementById('rx_med_name').value.trim().toLowerCase();
                    var hasAspirin = existingDrugs.some(function(d) { return d.includes('aspirin'); }) || newDrug.includes('aspirin');
                    var hasWarfarin = existingDrugs.some(function(d) { return d.includes('warfarin'); }) || newDrug.includes('warfarin');
                    
                    var interaction = null;
                    if (hasAspirin && hasWarfarin && !existingDrugs.includes(newDrug)) {
                        interaction = "Aspirin + Warfarin (Increased Risk of Bleeding)";
                    }
                    
                    if (interaction) {
                        e.preventDefault();
                        document.getElementById('cdsInteractionMsg').innerText = "Interaction: " + interaction;
                        $('#cdsInteractionModal').modal('show');
                        return false;
                    }
                });
            }

            if (overrideBtn) {
                overrideBtn.addEventListener('click', function() {
                    isOverridden = true;
                    $('#cdsInteractionModal').modal('hide');
                    if (medForm) medForm.submit();
                });
            }

            function hmsRxToggleCo(sel) {
                var f = sel.form;
                if (!f) return;
                var w = f.querySelector('.hms-rx-co-wrap');
                if (!w) return;
                if (sel.value === 'invoice') {
                    w.classList.remove('d-none');
                } else {
                    w.classList.add('d-none');
                }
            }
            document.querySelectorAll('.hms-rx-fiscal').forEach(function (s) {
                s.addEventListener('change', function () { hmsRxToggleCo(s); });
                hmsRxToggleCo(s);
            });
            document.querySelectorAll('.hms-rx-pharm-cat').forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    var p = opt ? parseFloat(String(opt.getAttribute('data-price') || '0')) : 0;
                    var f = sel.closest('form');
                    var amt = f ? f.querySelector('.hms-rx-sale-amt') : null;
                    if (amt && !isNaN(p) && p > 0) {
                        amt.value = String(Math.round(p));
                    }
                });
            });

            var catPick = document.getElementById('rx_pharmacy_catalog_pick');
            if (catPick) {
                catPick.addEventListener('change', function () {
                    var opt = catPick.options[catPick.selectedIndex];
                    var nm = document.getElementById('rx_med_name');
                    if (nm && opt && opt.value && opt.value !== '0') {
                        nm.value = opt.text.trim();
                    }
                });
            }

            document.querySelectorAll('.hms-data-card').forEach(function (card) {
                card.addEventListener('change', function (e) {
                    var t = e.target;
                    if (!t || t.name !== 'inventory_item_id') {
                        return;
                    }
                    var opt = t.options[t.selectedIndex];
                    if (!opt) {
                        return;
                    }
                    var p = parseFloat(String(opt.getAttribute('data-price') || '0'));
                    var cid = parseInt(String(opt.getAttribute('data-cat') || '0'), 10);
                    var f = t.form;
                    if (!f) {
                        return;
                    }
                    var amt = f.querySelector('.hms-rx-sale-amt');
                    var catSel = f.querySelector('.hms-rx-pharm-cat');
                    if (amt && !isNaN(p) && p > 0) {
                        amt.value = String(Math.round(p));
                    }
                    if (catSel && cid > 0) {
                        for (var i = 0; i < catSel.options.length; i++) {
                            if (String(catSel.options[i].value) === String(cid)) {
                                catSel.selectedIndex = i;
                                break;
                            }
                        }
                    }
                });
            });
        })();
        </script>
<?php include 'footer.php'; ?>
