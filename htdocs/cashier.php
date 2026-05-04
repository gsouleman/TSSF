<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$role = (string) ($_SESSION['role'] ?? '');
$canCashier = $role === '1'
    || hms_can($connection, 'cashier.write')
    || hms_can($connection, 'billing.write');
if (!$canCashier) {
    http_response_code(403);
    exit('Access denied.');
}

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ms = hms_multi_site_enabled($connection);
$ticketOk = hms_payment_ticket_tables_ok($connection);
$docOk = hms_billing_document_tables_ok($connection);

$msg = null;
$err = null;
$preview = null;
$previewLines = [];
$lookupCode = trim((string) ($_GET['code'] ?? $_POST['ticket_code_lookup'] ?? ''));

if ($ticketOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (isset($_POST['cashier_lookup'])) {
        $lookupCode = trim((string) ($_POST['ticket_code_lookup'] ?? ''));
        if ($lookupCode === '') {
            $err = 'Enter a payment code.';
        } else {
            $preview = hms_payment_ticket_lookup_by_code($connection, $fid, $lookupCode);
            if ($preview === null) {
                $err = 'No ticket found for that code at this site.';
            } elseif (strtolower((string) ($preview['status'] ?? '')) !== 'pending') {
                $err = 'This ticket is not awaiting payment (status: ' . hms_h((string) ($preview['status'] ?? '')) . ').';
                $preview = null;
            } else {
                $pj = json_decode((string) ($preview['lines_json'] ?? ''), true);
                $previewLines = is_array($pj) ? $pj : [];
            }
        }
    } elseif (isset($_POST['cashier_collect']) && $docOk) {
        $tid = (int) ($_POST['ticket_id'] ?? 0);
        $payMethod = hms_billing_normalize_payment_method((string) ($_POST['payment_method'] ?? 'Cash'));
        $fiscal = (string) ($_POST['fiscal_document'] ?? 'receipt');
        $companyId = (int) ($_POST['billing_company_id'] ?? 0);
        $linePick = [];
        if (isset($_POST['pay_line_idx']) && is_array($_POST['pay_line_idx'])) {
            foreach ($_POST['pay_line_idx'] as $lx) {
                $linePick[] = (int) $lx;
            }
        }
        $linePick = array_values(array_unique(array_filter($linePick, static fn (int $v): bool => $v >= 0)));
        $collectMode = (string) ($_POST['cashier_collect_mode'] ?? 'selected');
        if ($collectMode === 'all') {
            $res = hms_payment_ticket_collect($connection, $fid, $tid, $payMethod, $uid, $fiscal, $companyId, null);
        } elseif ($linePick === []) {
            $res = ['ok' => false, 'error' => 'Select at least one unpaid line, or use Pay full remaining balance.'];
        } else {
            $res = hms_payment_ticket_collect($connection, $fid, $tid, $payMethod, $uid, $fiscal, $companyId, $linePick);
        }
        if (!empty($res['ok']) && isset($res['doc_id'])) {
            hms_billing_set_print_prompt((int) $res['doc_id']);
            header('Location: billing-document-print.php?id=' . (int) $res['doc_id']);
            exit;
        }
        $err = (string) ($res['error'] ?? 'Payment could not be recorded.');
    } elseif ($ticketOk && isset($_POST['cashier_cancel_ticket'])) {
        $tid = (int) ($_POST['cancel_ticket_id'] ?? 0);
        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
            $err = 'Invalid security token.';
        } elseif ($tid < 1 || $reason === '') {
            $err = 'Enter a reason to cancel this pending code.';
        } elseif (function_exists('hms_payment_ticket_cancel_pending')) {
            $okc = hms_payment_ticket_cancel_pending($connection, $fid, $tid, $uid, $reason);
            if ($okc) {
                $msg = 'Payment code cancelled.';
                $preview = null;
                $previewLines = [];
                $lookupCode = '';
            } else {
                $err = 'Could not cancel (ticket missing or not pending).';
            }
        }
    } elseif (isset($_POST['cashier_issue_consult_prepay']) && $docOk && $ticketOk) {
        $prepPid = (int) ($_POST['prepay_patient_id'] ?? 0);
        $catalogPick = (int) ($_POST['prepay_catalog_id'] ?? 0);
        $prepAmt = 0;
        $prepDesc = '';
        if ($catalogPick > 0 && function_exists('hms_billing_catalog_service_row')) {
            $catRow = hms_billing_catalog_service_row($connection, $fid, $catalogPick);
            if ($catRow !== null && strtolower(trim((string) ($catRow['category'] ?? ''))) === 'consultation') {
                $prepAmt = max(0, (int) round((float) ($catRow['price'] ?? 0)));
                $prepDesc = trim((string) ($catRow['name'] ?? ''));
            }
        }
        $payMethod = hms_billing_normalize_payment_method((string) ($_POST['prepay_payment_method'] ?? 'Cash'));
        $fiscal = (string) ($_POST['prepay_fiscal_document'] ?? 'receipt');
        $companyId = (int) ($_POST['prepay_billing_company_id'] ?? 0);
        $issuePrepErr = null;
        if ($prepPid < 1) {
            $issuePrepErr = 'Select a patient.';
        } elseif ($prepAmt < 1 || $prepDesc === '') {
            $issuePrepErr = 'Select a consultation service from the catalog.';
        }
        if ($issuePrepErr !== null) {
            $err = $issuePrepErr;
        } elseif (function_exists('hms_payment_ticket_issue_consultation_prepay')) {
            $res = hms_payment_ticket_issue_consultation_prepay(
                $connection,
                $fid,
                $prepPid,
                $prepDesc,
                $prepAmt,
                $uid,
                $payMethod,
                $fiscal,
                $companyId
            );
            if (!empty($res['ok']) && isset($res['doc_id'], $res['code'])) {
                hms_billing_set_print_prompt((int) $res['doc_id']);
                header('Location: billing-document-print.php?id=' . (int) $res['doc_id']);
                exit;
            }
            $err = (string) ($res['error'] ?? 'Could not issue prepayment.');
        }
    }
} elseif ($ticketOk && $lookupCode !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $preview = hms_payment_ticket_lookup_by_code($connection, $fid, $lookupCode);
    if ($preview !== null && strtolower((string) ($preview['status'] ?? '')) === 'pending') {
        $pj = json_decode((string) ($preview['lines_json'] ?? ''), true);
        $previewLines = is_array($pj) ? $pj : [];
    } else {
        $preview = null;
        $err = 'No pending ticket for that code.';
    }
}

$billingCompanies = [];
if (hms_db_table_exists($connection, 'tbl_billing_company')) {
    $bcq = mysqli_query(
        $connection,
        'SELECT id, name FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . ' AND status = 1 ORDER BY name LIMIT 300'
    );
    while ($bcq && $bcr = mysqli_fetch_assoc($bcq)) {
        $billingCompanies[] = $bcr;
    }
}
$payOpts = hms_billing_payment_method_options();

$pendingRows = [];
if ($ticketOk) {
    $pq = mysqli_query(
        $connection,
        'SELECT t.id, t.ticket_code, t.total_amount, t.created_at, t.patient_id, p.first_name, p.last_name
         FROM tbl_payment_ticket t
         INNER JOIN tbl_patient p ON p.id = t.patient_id'
        . ($ms && hms_db_column_exists($connection, 'tbl_patient', 'facility_id') ? ' AND p.facility_id = ' . (int) $fid : '')
        . ' WHERE t.facility_id = ' . (int) $fid . " AND t.status = 'pending' ORDER BY t.id DESC LIMIT 40"
    );
    while ($pq && $pr = mysqli_fetch_assoc($pq)) {
        $pendingRows[] = $pr;
    }
}

$consultCatalogRows = function_exists('hms_billing_catalog_rows_by_category')
    ? hms_billing_catalog_rows_by_category($connection, $fid, 'consultation')
    : [];
$cashierConsultCatalogJson = [];
foreach ($consultCatalogRows as $cr) {
    $cashierConsultCatalogJson[] = [
        'id' => (int) ($cr['id'] ?? 0),
        'name' => (string) ($cr['name'] ?? ''),
        'price' => (float) ($cr['price'] ?? 0),
    ];
}
$sufPat = $ms ? ' WHERE p.facility_id = ' . (int) $fid . ' AND p.status = 1' : ' WHERE p.status = 1';
$cashierPatientList = [];
$plq = mysqli_query(
    $connection,
    'SELECT p.id, p.first_name, p.last_name FROM tbl_patient p' . $sufPat . ' ORDER BY p.last_name, p.first_name LIMIT 2500'
);
while ($plq && $pr = mysqli_fetch_assoc($plq)) {
    $cashierPatientList[] = $pr;
}

include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                if (function_exists('hms_ui_page_header')) {
                    hms_ui_page_header('Cashier', [
                        'subtitle' => 'Collect consultation fees before the visit (issue a payment code), then settle laboratory, radiology, and other pending charges by code.',
                        'breadcrumbs' => [['Home', 'dashboard.php'], ['Cashier', null]],
                        'primary' => ['label' => 'Cashier portal', 'url' => 'portal-cashier.php', 'icon' => 'fa-th-large'],
                    ]);
                } else {
                    echo '<h1 class="h4 font-weight-bold mb-3">Cashier</h1>';
                }
                ?>

                <?php if (!$ticketOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">Run migration <code>hms/database/migrations/023_payment_ticket_cashier.sql</code> to enable payment codes.</div>
                <?php } elseif (!$docOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">Run migration <code>hms/database/migrations/011_receipt_invoice_module.sql</code> to issue receipts.</div>
                <?php } ?>

                <?php if ($err) { ?>
                <div class="alert alert-danger border-0 shadow-sm"><?php echo hms_h($err); ?></div>
                <?php } ?>
                <?php if ($msg) { ?>
                <div class="alert alert-success border-0 shadow-sm"><?php echo hms_h($msg); ?></div>
                <?php } ?>
                <?php if ($ticketOk && $docOk) { ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                        <h5 class="font-weight-bold mb-0" style="color:#1b2559">Consultation fee — pay before visit</h5>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">After registration or during arrival, collect the <strong>consultation fee</strong> here first. Choose the patient and a <strong>consultation service</strong> from the catalog; the amount is filled automatically. The system issues a <strong>payment code</strong> for the clinician.</p>
                        <?php if ($consultCatalogRows === []) { ?>
                        <div class="alert alert-warning border-0 small mb-3">Add consultation services under <a href="service-catalog.php">Service catalog</a> (category: consultation) to enable this form.</div>
                        <?php } ?>
                        <form method="post" class="mb-0" id="hmsCashierPrepayForm">
                            <?php echo hms_csrf_field(); ?>
                            <div class="form-row">
                                <div class="col-12 mb-3">
                                    <label class="small font-weight-bold" for="prepayPatientSelect">Patient name</label>
                                    <select name="prepay_patient_id" id="prepayPatientSelect" class="form-control select" required style="width:100%"<?php echo $consultCatalogRows === [] ? ' disabled' : ''; ?>>
                                        <option value="">— Select patient —</option>
                                        <?php foreach ($cashierPatientList as $pRow) {
                                            $pId = (int) ($pRow['id'] ?? 0);
                                            $pLabel = trim((string) ($pRow['first_name'] ?? '') . ' ' . (string) ($pRow['last_name'] ?? ''));
                                            ?>
                                        <option value="<?php echo $pId; ?>"><?php echo hms_h($pLabel !== '' ? $pLabel : ('#' . $pId)); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="col-md-8 mb-3">
                                    <label class="small font-weight-bold" for="prepayCatalogId">Service <span class="text-muted font-weight-normal">(from catalog — Consultations)</span></label>
                                    <select name="prepay_catalog_id" id="prepayCatalogId" class="form-control" required<?php echo $consultCatalogRows === [] ? ' disabled' : ''; ?>>
                                        <option value="">— Select consultation service —</option>
                                        <?php foreach ($consultCatalogRows as $cr) {
                                            $cid = (int) ($cr['id'] ?? 0);
                                            $nm = trim((string) ($cr['name'] ?? ''));
                                            $pr = number_format((float) ($cr['price'] ?? 0), 0, '.', ' ');
                                            ?>
                                        <option value="<?php echo $cid; ?>"><?php echo hms_h($nm . ' — ' . $pr . ' FCFA'); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold" for="prepayAmountXaf">Amount (FCFA)</label>
                                    <input type="text" class="form-control bg-light text-dark font-weight-bold" id="prepayAmountXaf" readonly value="" tabindex="-1" autocomplete="off" inputmode="numeric" aria-readonly="true" placeholder="—">
                                </div>
                            </div>
                            <script type="application/json" id="hmsCashierConsultCatalogJson"><?php echo json_encode($cashierConsultCatalogJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>
                            <div class="form-row align-items-end">
                                <div class="col-md-4 mb-2">
                                    <label class="small font-weight-bold" for="prepayPayMethod">Payment method</label>
                                    <select name="prepay_payment_method" id="prepayPayMethod" class="form-control">
                                        <?php foreach ($payOpts as $pm) { ?>
                                        <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="small font-weight-bold" for="prepayFiscal">Print as</label>
                                    <select name="prepay_fiscal_document" id="prepayFiscal" class="form-control">
                                        <option value="receipt">Receipt (patient)</option>
                                        <option value="invoice"<?php echo $billingCompanies === [] ? ' disabled' : ''; ?>>Company invoice</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2" id="prepayCompanyWrap" style="display:none">
                                    <label class="small font-weight-bold" for="prepayCompany">Billing company</label>
                                    <select name="prepay_billing_company_id" id="prepayCompany" class="form-control">
                                        <option value="0">— Select company —</option>
                                        <?php foreach ($billingCompanies as $bc) {
                                            $bcid = (int) ($bc['id'] ?? 0);
                                            ?>
                                        <option value="<?php echo $bcid; ?>"><?php echo hms_h((string) ($bc['name'] ?? '')); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <p class="small text-muted mb-2">If <code>025_insurance_coverage_external_docs.sql</code> is applied, prepay amounts respect the patient’s <strong>primary insurer %</strong> set on the <a href="patients.php">clinical chart</a> (Insurance card). The <strong>Insurance</strong> payment method labels the transaction; it does not open a separate coverage wizard here.</p>
                            <button type="submit" name="cashier_issue_consult_prepay" value="1" class="btn btn-primary font-weight-bold mt-2"<?php echo $consultCatalogRows === [] ? ' disabled' : ''; ?>>
                                <i class="fa fa-ticket mr-1"></i> Collect payment &amp; issue code
                            </button>
                        </form>
                        <script>
                        (function () {
                            var f = document.getElementById('prepayFiscal');
                            var w = document.getElementById('prepayCompanyWrap');
                            if (f && w) {
                                function t() { w.style.display = f.value === 'invoice' ? '' : 'none'; }
                                f.addEventListener('change', t);
                                t();
                            }
                            var cat = document.getElementById('prepayCatalogId');
                            var amt = document.getElementById('prepayAmountXaf');
                            var metaEl = document.getElementById('hmsCashierConsultCatalogJson');
                            var meta = [];
                            if (metaEl && metaEl.textContent) {
                                try { meta = JSON.parse(metaEl.textContent); } catch (e) { meta = []; }
                            }
                            function syncPrepayAmount() {
                                if (!amt || !cat) return;
                                var id = parseInt(String(cat.value || '0'), 10) || 0;
                                var i, r, v = '';
                                for (i = 0; i < meta.length; i++) {
                                    r = meta[i];
                                    if (r && r.id === id) {
                                        v = String(Math.max(0, Math.round(parseFloat(String(r.price)) || 0)));
                                        break;
                                    }
                                }
                                amt.value = v;
                            }
                            if (cat) {
                                cat.addEventListener('change', syncPrepayAmount);
                                syncPrepayAmount();
                            }
                        })();
                        </script>
                    </div>
                </div>
                <?php } ?>

                <div class="row">
                    <div class="col-lg-7 mb-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-3" style="border-bottom:2px solid #0c8b8b">
                                <h5 class="font-weight-bold mb-0" style="color:#1b2559">Look up payment code</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted small">Codes bundle consultation fee, laboratory, radiology (and pharmacy when added to the ticket). The patient can <strong>pay part of the balance now</strong> (select lines below) and return later with the <strong>same code</strong> for the rest. After each payment, the receipt shows the code for laboratory, radiology, and pharmacy desks.</p>
                                <form method="post" class="mb-0">
                                    <?php echo hms_csrf_field(); ?>
                                    <div class="form-row align-items-end">
                                        <div class="col-md-8 mb-2">
                                            <label class="small font-weight-bold" for="ticketCodeLookup">Payment code</label>
                                            <input type="text" name="ticket_code_lookup" id="ticketCodeLookup" class="form-control text-uppercase" placeholder="PAY-2026-…" value="<?php echo hms_h($lookupCode); ?>" autocomplete="off">
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <button type="submit" name="cashier_lookup" value="1" class="btn btn-primary btn-block font-weight-bold">Load details</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <?php if ($preview && $ticketOk) {
                            $pid = (int) ($preview['patient_id'] ?? 0);
                            $pname = trim((string) ($preview['first_name'] ?? '') . ' ' . (string) ($preview['last_name'] ?? ''));
                            $tot = (float) ($preview['total_amount'] ?? 0);
                            $tid = (int) ($preview['id'] ?? 0);
                            $insHint = function_exists('hms_patient_insurance_cashier_hint')
                                ? hms_patient_insurance_cashier_hint($connection, $pid, $fid)
                                : [
                                    'migration_ok' => false,
                                    'has_primary_policy' => false,
                                    'carrier_name' => '',
                                    'insurer_covered_percent' => 0,
                                    'patient_copay_percent' => 100,
                                ];
                            ?>
                        <div class="card border-0 shadow-sm mt-4">
                            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center" style="border-bottom:2px solid #1b2559">
                                <h5 class="font-weight-bold mb-0" style="color:#1b2559">Payment details</h5>
                                <span class="badge badge-info"><?php echo hms_h((string) ($preview['ticket_code'] ?? '')); ?></span>
                            </div>
                            <div class="card-body">
                                <p class="mb-2"><strong>Patient:</strong> <?php echo hms_h($pname); ?> <span class="text-muted">(#<?php echo $pid; ?>)</span></p>
                                <div class="alert alert-light border-primary mb-3 py-2">
                                    <div class="font-weight-bold text-primary mb-1 small"><i class="fa fa-shield mr-1"></i>Coverage (patient chart)</div>
                                    <?php if (empty($insHint['migration_ok'])) { ?>
                                    <p class="mb-0 small text-muted">Run <code>hms/database/migrations/025_insurance_coverage_external_docs.sql</code> to store <strong>insurer covered %</strong> on policies. Until then, ticket lines assume <strong>100% patient pay</strong> at listed prices (unless marked external).</p>
                                    <?php } elseif (empty($insHint['has_primary_policy'])) { ?>
                                    <p class="mb-0 small">No <strong>primary insurance</strong> on file — lines use <strong>100% patient</strong> at list prices. Add a policy and % on <a href="patient-chart.php?id=<?php echo (int) $pid; ?>">clinical chart</a> → Insurance.</p>
                                    <?php } else { ?>
                                    <p class="mb-0 small">
                                        <strong><?php echo hms_h($insHint['carrier_name'] !== '' ? $insHint['carrier_name'] : 'Primary insurer'); ?></strong> —
                                        split: <strong><?php echo (int) $insHint['insurer_covered_percent']; ?>%</strong> insurer /
                                        <strong><?php echo (int) $insHint['patient_copay_percent']; ?>%</strong> patient of each <strong>listed</strong> fee (any ratio: 70/30, 50/50, 20/80, etc.).
                                        <span class="d-block mt-1"><?php echo (int) $insHint['insurer_covered_percent'] >= 100
                                            ? 'Patient due on insured lines should be <strong>0 FCFA</strong> (unless external).'
                                            : 'The table below is the <strong>patient portion</strong> only; it does not show the insurer’s share in FCFA.'; ?></span>
                                    </p>
                                    <?php } ?>
                                </div>
                                <?php if ($preview['consultation_id'] ?? null) { ?>
                                <p class="small text-muted mb-3">Linked consultation #C<?php echo hms_h(str_pad((string) (int) $preview['consultation_id'], 5, '0', STR_PAD_LEFT)); ?></p>
                                <?php } ?>
                                <?php if ($docOk) { ?>
                                <form method="post" id="hmsCashierTicketForm">
                                    <?php echo hms_csrf_field(); ?>
                                    <input type="hidden" name="ticket_id" value="<?php echo $tid; ?>">
                                    <input type="hidden" name="cashier_collect_mode" id="hmsCashierCollectMode" value="selected">
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light"><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">List (FCFA)</th><th class="text-right">Patient due (FCFA)</th><th class="text-right">Line due</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $sum = 0.0;
                                            $lineIx = 0;
                                            foreach ($previewLines as $ln) {
                                                if (!is_array($ln)) {
                                                    continue;
                                                }
                                                $d = (string) ($ln['description'] ?? '');
                                                $q = (float) ($ln['quantity'] ?? 1);
                                                $listU = isset($ln['list_unit_price']) ? (float) $ln['list_unit_price'] : (float) ($ln['unit_price'] ?? 0);
                                                $u = (float) ($ln['unit_price'] ?? 0);
                                                $lt = $q * $u;
                                                $sum += $lt;
                                                $kind = strtoupper((string) ($ln['kind'] ?? ''));
                                                $isPaid = function_exists('hms_payment_ticket_line_paid') && hms_payment_ticket_line_paid($ln);
                                                $ext = !empty($ln['external']) || (string) ($ln['fulfillment'] ?? '') === 'external';
                                                $insP = (int) ($ln['insurer_covered_percent'] ?? 0);
                                                ?>
                                            <tr class="<?php echo $isPaid ? 'table-secondary' : ''; ?>">
                                                <td>
                                                    <?php if (!$isPaid) { ?>
                                                    <input type="checkbox" class="hms-cashier-line-cb mr-1" name="pay_line_idx[]" value="<?php echo (int) $lineIx; ?>" checked aria-label="Pay line">
                                                    <?php } ?>
                                                    <?php echo hms_h($d); ?><?php echo $kind !== '' ? ' <span class="badge badge-light border">' . hms_h($kind) . '</span>' : ''; ?>
                                                    <?php if ($isPaid) { ?><span class="badge badge-success ml-1">Settled</span><?php } ?>
                                                    <?php if ($ext) { ?><span class="badge badge-warning ml-1">External</span><?php } ?>
                                                    <?php if ($insP > 0) { ?><span class="badge badge-info ml-1" title="Insurer covered % at list"><?php echo (int) $insP; ?>% ins.</span><?php } ?>
                                                </td>
                                                <td class="text-right"><?php echo hms_h((string) $q); ?></td>
                                                <td class="text-right"><?php echo number_format($listU, 0, '.', ' '); ?></td>
                                                <td class="text-right"><?php echo number_format($u, 0, '.', ' '); ?></td>
                                                <td class="text-right font-weight-bold"><?php echo number_format($lt, 0, '.', ' '); ?></td>
                                            </tr>
                                                <?php
                                                $lineIx++;
                                            }
                                            ?>
                                        </tbody>
                                        <tfoot>
                                            <tr><th colspan="4" class="text-right">Remaining patient due</th><th class="text-right"><?php echo number_format($tot, 0, '.', ' '); ?> FCFA</th></tr>
                                        </tfoot>
                                    </table>
                                </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="small font-weight-bold" for="payMethod">Payment method</label>
                                            <select name="payment_method" id="payMethod" class="form-control">
                                                <?php foreach ($payOpts as $pm) { ?>
                                                <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="small font-weight-bold" for="fiscalDoc">Print as</label>
                                            <select name="fiscal_document" id="fiscalDoc" class="form-control">
                                                <option value="receipt">Receipt (patient)</option>
                                                <option value="invoice"<?php echo $billingCompanies === [] ? ' disabled' : ''; ?>>Company invoice</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mb-3" id="hmsCashierInsuranceMethodNote" style="display:none">
                                        <div class="alert alert-info border-0 small mb-0">
                                            <strong>Insurance</strong> (payment method) records how this collection is labelled for accounting. It does <strong>not</strong> change the FCFA in the table — those amounts are already the <strong>patient share</strong> after the insurer % on the chart (see coverage box above). Use <strong>Cash</strong> / <strong>Bank</strong> / etc. when the patient pays their co-pay at the desk.
                                        </div>
                                    </div>
                                    <div class="mb-3" id="cashierCompanyWrap" style="display:none">
                                        <label class="small font-weight-bold" for="cashierCompany">Billing company</label>
                                        <select name="billing_company_id" id="cashierCompany" class="form-control">
                                            <option value="0">— Select company —</option>
                                            <?php foreach ($billingCompanies as $bc) {
                                                $bcid = (int) ($bc['id'] ?? 0);
                                                ?>
                                            <option value="<?php echo $bcid; ?>"><?php echo hms_h((string) ($bc['name'] ?? '')); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <button type="submit" name="cashier_collect" value="1" class="btn btn-success font-weight-bold mr-2 mb-2" id="hmsCashierPaySelectedBtn">
                                        <i class="fa fa-check mr-1"></i> Record payment for selected lines
                                    </button>
                                    <button type="submit" name="cashier_collect" value="1" class="btn btn-outline-success font-weight-bold mb-2" id="hmsCashierPayAllBtn">
                                        <i class="fa fa-money mr-1"></i> Pay full remaining balance
                                    </button>
                                </form>
                                <form method="post" class="mt-3 pt-3 border-top" onsubmit="return confirm('Cancel this pending payment code? This cannot be undone.');">
                                    <?php echo hms_csrf_field(); ?>
                                    <input type="hidden" name="cancel_ticket_id" value="<?php echo (int) $tid; ?>">
                                    <p class="small text-muted mb-2">Patient obtained care elsewhere, duplicate code, or abandoned visit — cancel to remove from pending.</p>
                                    <div class="form-row align-items-end">
                                        <div class="col-md-8 mb-2">
                                            <label class="small font-weight-bold" for="cancelReason">Reason</label>
                                            <input type="text" class="form-control form-control-sm" name="cancel_reason" id="cancelReason" maxlength="500" placeholder="e.g. Labs done at external facility" required>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <button type="submit" name="cashier_cancel_ticket" value="1" class="btn btn-sm btn-outline-danger btn-block">Cancel pending code</button>
                                        </div>
                                    </div>
                                </form>
                                <script>
                                (function () {
                                    var form = document.getElementById('hmsCashierTicketForm');
                                    var mode = document.getElementById('hmsCashierCollectMode');
                                    var payAll = document.getElementById('hmsCashierPayAllBtn');
                                    var selBtn = document.getElementById('hmsCashierPaySelectedBtn');
                                    if (payAll && mode && form) {
                                        payAll.addEventListener('click', function () { mode.value = 'all'; });
                                    }
                                    if (selBtn && mode && form) {
                                        selBtn.addEventListener('click', function () { mode.value = 'selected'; });
                                    }
                                    if (form && mode) {
                                        form.addEventListener('submit', function (e) {
                                            if (mode.value === 'selected') {
                                                var cbs = form.querySelectorAll('input.hms-cashier-line-cb:checked');
                                                if (!cbs.length) {
                                                    e.preventDefault();
                                                    alert('Select at least one unpaid line, or use Pay full remaining balance.');
                                                }
                                            }
                                        });
                                    }
                                })();
                                </script>
                                <script>
                                (function () {
                                    var f = document.getElementById('fiscalDoc');
                                    var w = document.getElementById('cashierCompanyWrap');
                                    if (f && w) {
                                        function t() { w.style.display = f.value === 'invoice' ? '' : 'none'; }
                                        f.addEventListener('change', t);
                                        t();
                                    }
                                    var pm = document.getElementById('payMethod');
                                    var insNote = document.getElementById('hmsCashierInsuranceMethodNote');
                                    function syncInsNote() {
                                        if (!pm || !insNote) return;
                                        var v = (pm.value || '').toLowerCase();
                                        insNote.style.display = v === 'insurance' ? '' : 'none';
                                    }
                                    if (pm) {
                                        pm.addEventListener('change', syncInsNote);
                                        syncInsNote();
                                    }
                                })();
                                </script>
                                <?php } else { ?>
                                <div class="alert alert-warning border-0 small mb-3">Run migration <code>011_receipt_invoice_module.sql</code> to issue receipts and record payments.</div>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead class="thead-light"><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit (FCFA)</th><th class="text-right">Line</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $lineIx2 = 0;
                                            foreach ($previewLines as $ln) {
                                                if (!is_array($ln)) {
                                                    continue;
                                                }
                                                $d = (string) ($ln['description'] ?? '');
                                                $q = (float) ($ln['quantity'] ?? 1);
                                                $u = (float) ($ln['unit_price'] ?? 0);
                                                $lt = $q * $u;
                                                $kind = strtoupper((string) ($ln['kind'] ?? ''));
                                                $isPaid = function_exists('hms_payment_ticket_line_paid') && hms_payment_ticket_line_paid($ln);
                                                ?>
                                            <tr class="<?php echo $isPaid ? 'table-secondary' : ''; ?>">
                                                <td><?php echo hms_h($d); ?><?php echo $kind !== '' ? ' <span class="badge badge-light border">' . hms_h($kind) . '</span>' : ''; ?><?php if ($isPaid) { ?><span class="badge badge-success ml-1">Paid</span><?php } ?></td>
                                                <td class="text-right"><?php echo hms_h((string) $q); ?></td>
                                                <td class="text-right"><?php echo number_format($u, 0, '.', ' '); ?></td>
                                                <td class="text-right font-weight-bold"><?php echo number_format($lt, 0, '.', ' '); ?></td>
                                            </tr>
                                                <?php
                                                $lineIx2++;
                                            }
                                            ?>
                                        </tbody>
                                        <tfoot>
                                            <tr><th colspan="3" class="text-right">Remaining due</th><th class="text-right"><?php echo number_format($tot, 0, '.', ' '); ?> FCFA</th></tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="col-lg-5 mb-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                                <h5 class="font-weight-bold mb-0" style="color:#1b2559">Pending payment codes</h5>
                            </div>
                            <div class="card-body p-0">
                                <?php if ($pendingRows === []) { ?>
                                <p class="text-muted small mb-0 p-3">No pending tickets.</p>
                                <?php } else { ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0">
                                        <thead class="thead-light"><tr><th>Code</th><th>Patient</th><th class="text-right">Amount</th><th></th></tr></thead>
                                        <tbody>
                                            <?php foreach ($pendingRows as $pr) {
                                                $code = (string) ($pr['ticket_code'] ?? '');
                                                $pn = trim((string) ($pr['first_name'] ?? '') . ' ' . (string) ($pr['last_name'] ?? ''));
                                                $am = (float) ($pr['total_amount'] ?? 0);
                                                ?>
                                            <tr>
                                                <td class="font-weight-bold text-nowrap"><?php echo hms_h($code); ?></td>
                                                <td class="small"><?php echo hms_h($pn); ?></td>
                                                <td class="text-right"><?php echo number_format($am, 0, '.', ' '); ?></td>
                                                <td class="text-right">
                                                    <a class="small" href="cashier.php?code=<?php echo rawurlencode($code); ?>">Open</a>
                                                </td>
                                            </tr>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
