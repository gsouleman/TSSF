<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/billing_document_pdf.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.write');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ms = hms_multi_site_enabled($connection);
$ok = hms_billing_document_tables_ok($connection) && hms_db_table_exists($connection, 'tbl_billing_company');
$flash = '';

$companies = [];
if ($ok) {
    $cq = mysqli_query($connection, 'SELECT id, name FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . " AND status = 1 ORDER BY name LIMIT 300");
    while ($cq && $cr = mysqli_fetch_assoc($cq)) {
        $companies[] = $cr;
    }
}

if ($ok && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_invoice']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $compId = (int) ($_POST['company_id'] ?? 0);
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $tax = max(0.0, (float) ($_POST['tax_amount'] ?? 0));
    $pay = hms_billing_normalize_payment_method($_POST['payment_method'] ?? '');
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $descs = $_POST['line_desc'] ?? [];
    $amounts = $_POST['line_amount'] ?? [];
    $lines = [];
    if (is_array($descs) && is_array($amounts)) {
        $n = min(count($descs), count($amounts));
        for ($i = 0; $i < $n; $i++) {
            $d = trim((string) ($descs[$i] ?? ''));
            $a = (float) ($amounts[$i] ?? 0);
            if ($d !== '' && $a > 0) {
                $lines[] = ['description' => $d, 'quantity' => 1, 'unit_price' => $a];
            }
        }
    }
    
    $billTarget = $_POST['bill_target'] ?? 'reg_company';
    $customCompany = trim((string) ($_POST['custom_company'] ?? ''));
    
    if ($billTarget === 'reg_company' && $compId < 1) {
        $flash = 'Select a registered company.';
    } elseif ($billTarget === 'custom_company' && $customCompany === '') {
        $flash = 'Enter a company name.';
    } elseif ($billTarget === 'patient' && $pid < 1) {
        $flash = 'Select a patient.';
    } elseif ($lines === []) {
        $flash = 'Add at least one line with amount.';
    } else {
        $cok = true;
        if ($billTarget === 'reg_company') {
            $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_billing_company WHERE id = ? AND facility_id = ? LIMIT 1');
            $cok = false;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'ii', $compId, $fid);
                mysqli_stmt_execute($chk);
                $cok = (bool) hms_stmt_fetch_assoc($chk);
                mysqli_stmt_close($chk);
            }
        }
        if (!$cok) {
            $flash = 'Invalid registered company.';
        } else {
            $pok = true;
            if ($pid > 0) {
                $pq = $ms
                    ? mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1')
                    : mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
                $pok = false;
                if ($pq) {
                    if ($ms) {
                        mysqli_stmt_bind_param($pq, 'ii', $pid, $fid);
                    } else {
                        mysqli_stmt_bind_param($pq, 'i', $pid);
                    }
                    mysqli_stmt_execute($pq);
                    $pok = (bool) hms_stmt_fetch_assoc($pq);
                    mysqli_stmt_close($pq);
                }
            }
            if ($pid > 0 && !$pok) {
                $flash = 'Invalid patient for this site.';
            } else {
                $finalCompanyId = ($billTarget === 'reg_company') ? $compId : 0;
                $finalCompanySnap = ($billTarget === 'custom_company') ? $customCompany : null;

                $invOpts = [
                    'facility_id' => $fid,
                    'patient_id' => $pid,
                    'doc_type' => 'invoice',
                    'company_id' => $finalCompanyId > 0 ? $finalCompanyId : null,
                    'company_snapshot' => $finalCompanySnap,
                    'payment_method' => $pay,
                    'tax_amount' => $tax,
                    'source_module' => 'manual_invoice',
                    'source_pk' => (int) (microtime(true) * 1000) % 2000000000,
                    'created_by' => $uid,
                    'notes' => $notes !== '' ? $notes : null,
                    'skip_if_exists' => false,
                ];
                if ($pid > 0 && function_exists('hms_hospitalization_open_id_for_patient')) {
                    $hInv = hms_hospitalization_open_id_for_patient($connection, $fid, $pid);
                    if ($hInv > 0) {
                        $invOpts['hospitalization_id'] = $hInv;
                    }
                }

                $docId = hms_billing_create_document(
                    $connection,
                    $invOpts,
                    $lines
                );
                if (is_int($docId) && $docId > 0) {
                    hms_billing_set_print_prompt($docId);
                    if (hms_billing_pdf_available()) {
                        header('Location: billing-document-pdf.php?id=' . $docId);
                    } else {
                        header('Location: billing-document-print.php?id=' . $docId . '&autoprint=1');
                    }
                    exit;
                }
                $flash = 'Could not create invoice.';
            }
        }
    }
}

$patients = [];
if ($ok) {
    $psuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
    $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $psuf . ' ORDER BY last_name, first_name LIMIT 500');
    while ($pq && $pr = mysqli_fetch_assoc($pq)) {
        $patients[] = $pr;
    }
}

// Load service catalog for invoice line picker
$catOk = hms_db_table_exists($connection, 'tbl_service_catalog');
$catalog = [];
if ($catOk) {
    $cq2 = mysqli_query(
        $connection,
        'SELECT id, category, subcategory, name, cpt_code, price FROM tbl_service_catalog
         WHERE (facility_id = ' . (int) $fid . ' OR facility_id = 0) AND status = 1
         ORDER BY category, subcategory, sort_order, name LIMIT 500'
    );
    while ($cq2 && $cr2 = mysqli_fetch_assoc($cq2)) {
        $catalog[] = $cr2;
    }
}
include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('New Invoice', [
                'subtitle' => 'Issue a tax invoice to a company or a patient directly.',
                'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Invoice', '']],
                'back' => 'billing-payments.php',
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-warning"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run <code>011_receipt_invoice_module.sql</code> and add companies on <a href="billing-companies.php">Billing companies</a>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-form-card col-lg-10 px-0">
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group">
                            <label class="d-block font-weight-bold">Bill To <span class="text-danger">*</span></label>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="billToRegCompany" name="bill_target" value="reg_company" class="custom-control-input" checked>
                                <label class="custom-control-label" for="billToRegCompany">Registered Company</label>
                            </div>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="billToCustomCompany" name="bill_target" value="custom_company" class="custom-control-input">
                                <label class="custom-control-label" for="billToCustomCompany">Custom Company</label>
                            </div>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input type="radio" id="billToPatient" name="bill_target" value="patient" class="custom-control-input">
                                <label class="custom-control-label" for="billToPatient">Patient Directly</label>
                            </div>
                        </div>

                        <div id="wrap_reg_company" class="form-group" style="background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0;">
                            <label class="font-weight-bold">Select Company <span class="text-danger">*</span></label>
                            <select name="company_id" class="form-control">
                                <option value="">— Select —</option>
                                <?php foreach ($companies as $c) {
                                    echo '<option value="' . (int) $c['id'] . '">' . hms_h((string) $c['name']) . '</option>';
                                } ?>
                            </select>
                        </div>

                        <div id="wrap_custom_company" class="form-group" style="background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #e2e8f0; display:none;">
                            <label class="font-weight-bold">Custom Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="custom_company" class="form-control" placeholder="Enter company name">
                        </div>

                        <div id="wrap_patient_ref" class="form-group">
                            <label id="lbl_patient_ref" class="font-weight-bold">Patient reference (optional)</label>
                            <select name="patient_id" class="form-control">
                                <option value="0">— None —</option>
                                <?php foreach ($patients as $p) {
                                    echo '<option value="' . (int) $p['id'] . '">' . hms_h(trim((string) $p['first_name'] . ' ' . (string) $p['last_name'])) . '</option>';
                                } ?>
                            </select>
                        </div>
                        <div class="form-row" style="display:none">
                            <div class="form-group col-md-6">
                                <label>VAT 19.25% (FCFA) Auto:</label>
                                <input type="number" name="tax_amount" id="inv_tax_input" class="form-control text-right" min="0" step="1" value="0" readonly>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label>Payment method (optional)</label>
                                <input type="text" name="payment_method" class="form-control" placeholder="e.g. Bank transfer">
                            </div>
                            <div class="form-group col-md-6">
                                <label>Internal notes</label>
                                <input type="text" name="notes" class="form-control" placeholder="Not printed">
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-2 mt-3">
                            <p class="font-weight-bold mb-0">Line Items</p>
                            <?php if (!empty($catalog)) { ?>
                            <div class="d-flex align-items-center">
                                <select id="inv_catalog_picker" class="form-control form-control-sm mr-2" style="max-width:320px;">
                                    <option value="">+ Pick from catalog…</option>
                                    <?php
                                    $lastCat2 = null;
                                    foreach ($catalog as $svc) {
                                        $catLbl2 = ['consultation'=>'Consultations','laboratory'=>'Laboratory','service'=>'Services','pharmacy'=>'Pharmacy'][$svc['category']] ?? ucfirst((string)$svc['category']);
                                        if ($svc['category'] !== $lastCat2) {
                                            if ($lastCat2 !== null) echo '</optgroup>';
                                            echo '<optgroup label="'.hms_h($catLbl2).'">';
                                            $lastCat2 = $svc['category'];
                                        }
                                        $pf = number_format((float)$svc['price'],0,'.',' ');
                                        echo '<option value=""
                                            data-name="'.hms_h((string)$svc['name']).'"'
                                            .' data-price="'.hms_h((string)$svc['price']).'"'
                                            .'>'.hms_h($svc['name']).' — '.$pf.' FCFA</option>';
                                    }
                                    if ($lastCat2 !== null) echo '</optgroup>';
                                    ?>
                                </select>
                                <button type="button" id="inv_add_row" class="btn btn-sm btn-outline-secondary" title="Add empty row">
                                    <i class="fa fa-plus"></i>
                                </button>
                            </div>
                            <?php } else { ?>
                            <button type="button" id="inv_add_row" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-plus mr-1"></i>Add row
                            </button>
                            <?php } ?>
                        </div>

                        <div id="inv_lines_wrap">
                            <div class="form-row mb-1 inv-line-header" style="font-size:.75rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">
                                <div class="col-md-7">Description</div>
                                <div class="col-md-3 text-center">Amount (FCFA)</div>
                                <div class="col-md-2"></div>
                            </div>
                            <!-- Initial rows -->
                            <?php for ($r = 0; $r < 3; $r++) { ?>
                            <div class="form-row mb-2 inv-line-row">
                                <div class="col-md-7"><input class="form-control" name="line_desc[]" placeholder="Service description"></div>
                                <div class="col-md-3"><input class="form-control inv-amount" name="line_amount[]" type="number" min="0" step="1" placeholder="0"></div>
                                <div class="col-md-2 d-flex align-items-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger inv-remove-row" title="Remove"><i class="fa fa-trash-o"></i></button>
                                </div>
                            </div>
                            <?php } ?>
                        </div>

                        <!-- Running total -->
                        <div class="d-flex justify-content-end mt-2 mb-3">
                            <div class="card border-0" style="background:#f8fafc;border-radius:10px;padding:12px 20px;min-width:230px;">
                                <div class="d-flex justify-content-between small text-muted mb-1"><span>Sub-total</span><span id="inv_subtotal">0 FCFA</span></div>
                                <div class="d-flex justify-content-between small text-muted mb-1"><span>Tax</span><span id="inv_tax_display">0 FCFA</span></div>
                                <div class="d-flex justify-content-between font-weight-bold" style="color:#1a6bd8;"><span>Total</span><span id="inv_total">0 FCFA</span></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <div class="d-flex justify-content-end">
                            <a href="billing-payments.php" class="btn btn-outline-secondary mr-2">Cancel</a>
                            <button type="submit" name="create_invoice" value="1" class="btn btn-primary"><i class="fa fa-print mr-1"></i>Issue Invoice &amp; Print</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
        </div></div>

<script>
(function() {
    var wrap = document.getElementById('inv_lines_wrap');
    if (!wrap) return;

    var btnAdd    = document.getElementById('inv_add_row');
    var picker    = document.getElementById('inv_catalog_picker');
    var taxInput  = document.getElementById('inv_tax_input');
    var dSub      = document.getElementById('inv_subtotal');
    var dTax      = document.getElementById('inv_tax_display');
    var dTot      = document.getElementById('inv_total');

    function fmtMoney(amt) {
        return Math.floor(amt).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' FCFA';
    }

    function calcTotal() {
        var sub = 0;
        wrap.querySelectorAll('.inv-amount').forEach(function(inp) {
            var a = parseFloat(inp.value);
            if (!isNaN(a) && a > 0) sub += a;
        });
        
        var tax = 0;
        var billTarget = document.querySelector('input[name="bill_target"]:checked').value;
        var isCompany = (billTarget !== 'patient');
        
        if (isCompany) {
            tax = Math.round(sub * 0.1925);
            if (taxInput) {
                taxInput.value = tax;
                taxInput.readOnly = true;
            }
        } else {
            if (taxInput) {
                taxInput.value = 0;
                taxInput.readOnly = true;
            }
        }
        
        var tot = sub + tax;
        dSub.textContent = fmtMoney(sub);
        dTax.textContent = fmtMoney(tax);
        dTot.textContent = fmtMoney(tot);
    }

    function removeRow(btn) {
        var row = btn.closest('.inv-line-row');
        if (row && wrap.querySelectorAll('.inv-line-row').length > 1) {
            row.remove();
            calcTotal();
        }
    }

    function addRow(desc, amt) {
        desc = desc || '';
        amt  = amt || '';
        var tpl = '<div class="form-row mb-2 inv-line-row">' +
                  '<div class="col-md-7"><input class="form-control" name="line_desc[]" placeholder="Service description" value="'+desc.replace(/"/g, '&quot;')+'"></div>' +
                  '<div class="col-md-3"><input class="form-control inv-amount" name="line_amount[]" type="number" min="0" step="1" placeholder="0" value="'+amt+'"></div>' +
                  '<div class="col-md-2 d-flex align-items-center"><button type="button" class="btn btn-sm btn-outline-danger inv-remove-row" title="Remove"><i class="fa fa-trash-o"></i></button></div>' +
                  '</div>';
        wrap.insertAdjacentHTML('beforeend', tpl);
        var newlyAdded = wrap.lastElementChild;
        // Bind input on new amount field
        var newAmt = newlyAdded.querySelector('.inv-amount');
        if (newAmt) {
            newAmt.addEventListener('input', calcTotal);
            newAmt.addEventListener('change', calcTotal);
        }
        // Bind click on remove
        var newRem = newlyAdded.querySelector('.inv-remove-row');
        if (newRem) {
            newRem.addEventListener('click', function() { removeRow(this); });
        }
    }

    // Bind current initial rows
    wrap.querySelectorAll('.inv-amount').forEach(function(inp) {
        inp.addEventListener('input', calcTotal);
        inp.addEventListener('change', calcTotal);
    });
    wrap.querySelectorAll('.inv-remove-row').forEach(function(btn) {
        btn.addEventListener('click', function() { removeRow(this); });
    });
    if (taxInput) {
        taxInput.addEventListener('input', calcTotal);
        taxInput.addEventListener('change', calcTotal);
    }

    // Add button
    if (btnAdd) {
        btnAdd.addEventListener('click', function(e) {
            e.preventDefault();
            addRow();
        });
    }

    // Catalog Picker behavior
    if (picker) {
        picker.addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            if (!opt.value && opt.text.indexOf('+') !== -1) return; // Prompt option
            
            var nm = opt.dataset.name || '';
            var pr = opt.dataset.price || '';
            if (nm !== '' && pr !== '') {
                // If first row is completely empty, replace it rather than adding
                var firstRow = wrap.querySelector('.inv-line-row');
                if (firstRow) {
                    var fDesc = firstRow.querySelector('input[name="line_desc[]"]');
                    var fAmt  = firstRow.querySelector('input[name="line_amount[]"]');
                    if (fDesc && fAmt && fDesc.value.trim() === '' && (fAmt.value.trim() === '' || fAmt.value === '0')) {
                        fDesc.value = nm;
                        fAmt.value = pr;
                        calcTotal();
                        picker.selectedIndex = 0; // reset
                        return;
                    }
                }
                // Otherwise append
                addRow(nm, pr);
                calcTotal();
            }
            picker.selectedIndex = 0; // reset
        });
    }

    // Expose calcTotal globally so we can trigger it from outside
    window.calcTotal = calcTotal;
    
    // Initial calc
    calcTotal();
    
    // Handle radio toggle
    const toggleRadios = document.querySelectorAll('input[name="bill_target"]');
    const wrapRegCompany = document.getElementById('wrap_reg_company');
    const wrapCustomCompany = document.getElementById('wrap_custom_company');
    const lblPatientRef = document.getElementById('lbl_patient_ref');
    
    function updateBillTargetUI() {
        const val = document.querySelector('input[name="bill_target"]:checked').value;
        if (val === 'reg_company') {
            if(wrapRegCompany) wrapRegCompany.style.display = 'block';
            if(wrapCustomCompany) wrapCustomCompany.style.display = 'none';
            if(lblPatientRef) lblPatientRef.innerHTML = 'Patient reference (optional)';
        } else if (val === 'custom_company') {
            if(wrapRegCompany) wrapRegCompany.style.display = 'none';
            if(wrapCustomCompany) wrapCustomCompany.style.display = 'block';
            if(lblPatientRef) lblPatientRef.innerHTML = 'Patient reference (optional)';
        } else if (val === 'patient') {
            if(wrapRegCompany) wrapRegCompany.style.display = 'none';
            if(wrapCustomCompany) wrapCustomCompany.style.display = 'none';
            if(lblPatientRef) lblPatientRef.innerHTML = 'Select Patient <span class="text-danger">*</span>';
        }
        if (typeof window.calcTotal === 'function') {
            window.calcTotal();
        }
    }
    
    toggleRadios.forEach(radio => {
        radio.addEventListener('change', updateBillTargetUI);
    });
    updateBillTargetUI();
})();
</script>
<?php include 'footer.php'; ?>
