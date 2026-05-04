<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canWrite = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);
$taxTableOk = hms_db_table_exists($connection, 'tbl_fin_tax_setting');

$tab = (string) ($_GET['tab'] ?? 'vat');
$tabs = ['vat', 'income', 'payroll', 'other'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'vat';
}

$msg = '';
if ($canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_tax_settings'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } elseif (!$taxTableOk) {
        $msg = 'Tax settings table missing. Run migration 029.';
    } else {
        $save = static function (mysqli $c, int $fid, string $k, string $v): void {
            hms_fin_tax_setting_set($c, $fid, $k, $v);
        };
        $save($connection, $fid, 'company_niu', trim((string) ($_POST['company_niu'] ?? '')));
        $save($connection, $fid, 'tax_centre', trim((string) ($_POST['tax_centre'] ?? '')));
        $save($connection, $fid, 'tva_rate_standard', trim((string) ($_POST['tva_rate_standard'] ?? '19.25')));
        $save($connection, $fid, 'legal_form_note', trim((string) ($_POST['legal_form_note'] ?? '')));
        $save($connection, $fid, 'cit_rate_percent', trim((string) ($_POST['cit_rate_percent'] ?? '33')));
        $save($connection, $fid, 'cit_working_notes', trim((string) ($_POST['cit_working_notes'] ?? '')));
        $save($connection, $fid, 'cnps_employee_pct', trim((string) ($_POST['cnps_employee_pct'] ?? '4')));
        $save($connection, $fid, 'cnps_employer_pct', trim((string) ($_POST['cnps_employer_pct'] ?? '8.4')));
        $save($connection, $fid, 'irpp_payroll_notes', trim((string) ($_POST['irpp_payroll_notes'] ?? '')));
        $save($connection, $fid, 'withholding_services_pct', trim((string) ($_POST['withholding_services_pct'] ?? '5.5')));
        $save($connection, $fid, 'withholding_nonresident_pct', trim((string) ($_POST['withholding_nonresident_pct'] ?? '15')));
        $save($connection, $fid, 'withholding_rent_pct', trim((string) ($_POST['withholding_rent_pct'] ?? '10')));
        $save($connection, $fid, 'patente_notes', trim((string) ($_POST['patente_notes'] ?? '')));
        $save($connection, $fid, 'municipal_local_notes', trim((string) ($_POST['municipal_local_notes'] ?? '')));
        $save($connection, $fid, 'excise_transport_notes', trim((string) ($_POST['excise_transport_notes'] ?? '')));
        $save($connection, $fid, 'other_taxes_notes', trim((string) ($_POST['other_taxes_notes'] ?? '')));
        $msg = 'Tax settings saved.';
        $rtab = trim((string) ($_POST['return_tab'] ?? 'vat'));
        if (!in_array($rtab, $tabs, true)) {
            $rtab = 'vat';
        }
        $ry = max(2000, min(2100, (int) ($_POST['return_y'] ?? 0)));
        $rm = max(1, min(12, (int) ($_POST['return_m'] ?? 0)));
        if ($ry === 0) {
            $ry = (int) date('Y');
        }
        if ($rm === 0) {
            $rm = (int) date('n');
        }
        header('Location: tax-declarations.php?tab=' . rawurlencode($rtab) . '&y=' . $ry . '&m=' . $rm . '&saved=1');
        exit;
    }
}

if (isset($_GET['saved'])) {
    $msg = 'Tax settings saved.';
}

$niu = hms_fin_tax_setting_get($connection, $fid, 'company_niu', '');
$centre = hms_fin_tax_setting_get($connection, $fid, 'tax_centre', 'Tax office — Cameroon');
$tvaRate = (float) hms_fin_tax_setting_get($connection, $fid, 'tva_rate_standard', '19.25');
if ($tvaRate <= 0 || $tvaRate > 100) {
    $tvaRate = 19.25;
}
$legalNote = hms_fin_tax_setting_get($connection, $fid, 'legal_form_note', 'Healthcare facility — in-house chart');

$citRate = (float) hms_fin_tax_setting_get($connection, $fid, 'cit_rate_percent', '33');
if ($citRate <= 0 || $citRate > 100) {
    $citRate = 33.0;
}
$citNotes = hms_fin_tax_setting_get($connection, $fid, 'cit_working_notes', '');
$cnpsEmp = (float) hms_fin_tax_setting_get($connection, $fid, 'cnps_employee_pct', '4');
$cnpsEr = (float) hms_fin_tax_setting_get($connection, $fid, 'cnps_employer_pct', '8.4');
$irppNotes = hms_fin_tax_setting_get($connection, $fid, 'irpp_payroll_notes', '');
$whSvc = (float) hms_fin_tax_setting_get($connection, $fid, 'withholding_services_pct', '5.5');
$whNres = (float) hms_fin_tax_setting_get($connection, $fid, 'withholding_nonresident_pct', '15');
$whRent = (float) hms_fin_tax_setting_get($connection, $fid, 'withholding_rent_pct', '10');
$patenteNotes = hms_fin_tax_setting_get($connection, $fid, 'patente_notes', '');
$municipalNotes = hms_fin_tax_setting_get($connection, $fid, 'municipal_local_notes', '');
$exciseNotes = hms_fin_tax_setting_get($connection, $fid, 'excise_transport_notes', '');
$otherTaxNotes = hms_fin_tax_setting_get($connection, $fid, 'other_taxes_notes', '');

$y = (int) ($_GET['y'] ?? (int) date('Y'));
$m = (int) ($_GET['m'] ?? (int) date('n'));
if ($y < 2000 || $y > 2100) {
    $y = (int) date('Y');
}
if ($m < 1 || $m > 12) {
    $m = (int) date('n');
}

$tvaEst = ['ca_ht' => 0.0, 'tva_collectee_est' => 0.0, 'tva_deductible_est' => 0.0, 'tva_net_est' => 0.0, 'taux_pct' => $tvaRate];
$plMonth = ['charges' => 0.0, 'produits' => 0.0, 'resultat' => 0.0, 'period_from' => '', 'period_to' => ''];
$plYear = ['charges' => 0.0, 'produits' => 0.0, 'resultat' => 0.0, 'period_from' => '', 'period_to' => ''];
$citAnnual = ['accounting_result' => 0.0, 'rate_pct' => $citRate, 'cit_indicative' => 0.0, 'loss_position' => false];
$citMonth = ['accounting_result' => 0.0, 'rate_pct' => $citRate, 'cit_indicative' => 0.0, 'loss_position' => false];

if ($finOk) {
    $tvaEst = hms_fin_cameroon_tva_estimates_for_month($connection, $fid, $y, $m, $tvaRate);
    $plMonth = hms_fin_pl_for_month($connection, $fid, $y, $m);
    $plYear = hms_fin_pl_for_year($connection, $fid, $y);
    $citAnnual = hms_fin_cameroon_cit_indicative((float) ($plYear['resultat'] ?? 0), $citRate);
    $citMonth = hms_fin_cameroon_cit_indicative((float) ($plMonth['resultat'] ?? 0), $citRate);
}

$facName = function_exists('hms_current_facility_name') ? hms_current_facility_name($connection) : ('Site #' . $fid);

$tabLabels = [
    'vat' => ['VAT (TVA)', 'fa-calculator'],
    'income' => ['Income tax (IS / CIT)', 'fa-bank'],
    'payroll' => ['Payroll & social', 'fa-users'],
    'other' => ['Other taxes', 'fa-list'],
];

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-tax-cm-page">
                <style>
                    .hms-tax-cm-page .nav-tabs .nav-link { font-weight: 600; color: #475569; }
                    .hms-tax-cm-page .nav-tabs .nav-link.active { color: #0f172a; border-bottom-color: #fff; }
                    .hms-tax-cm-page .hms-tax-pill { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 700; }
                </style>
                <?php
                hms_ui_page_header('Tax — Cameroon', [
                    'subtitle' => 'VAT, corporate income tax, payroll-related levies, and other Cameroon taxes — working papers and local parameters (not e-filing; validate with DGI / a licensed accountant).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Tax', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
                <?php if (!$taxTableOk) { ?>
                <div class="alert alert-warning">Run <code>database/migrations/029_ohada_reporting_tax.sql</code> to enable tax settings. Optional: <code>038_fin_tax_setting_value_widen.sql</code> for longer notes.</div>
                <?php } ?>

                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="m">Month (for VAT &amp; period views)</label>
                            <select class="form-control" id="m" name="m">
                                <?php for ($i = 1; $i <= 12; $i++) {
                                    $sel = $i === $m ? ' selected' : '';
                                    ?>
                                <option value="<?php echo $i; ?>"<?php echo $sel; ?>><?php echo $i; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="y">Year</label>
                            <input type="number" class="form-control" id="y" name="y" value="<?php echo (int) $y; ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <label for="tab">Section</label>
                            <select class="form-control" id="tab" name="tab">
                                <?php foreach ($tabLabels as $tk => $tl) {
                                    $sel = $tk === $tab ? ' selected' : '';
                                    ?>
                                <option value="<?php echo hms_h($tk); ?>"<?php echo $sel; ?>><?php echo hms_h($tl[0]); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group col-md-5 mb-0">
                            <button type="submit" class="btn btn-primary">Apply</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php if ($canWrite && $taxTableOk) { ?>
                <form method="post" class="card border-0 shadow-sm mb-4 no-print">
                    <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center flex-wrap">
                        <span><i class="fa fa-sliders mr-2 text-primary"></i>Facility tax parameters</span>
                        <button type="submit" class="btn btn-primary btn-sm" name="save_tax_settings" value="1">Save all parameters</button>
                    </div>
                    <div class="card-body">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="save_tax_settings" value="1">
                        <input type="hidden" name="return_tab" value="<?php echo hms_h($tab); ?>">
                        <input type="hidden" name="return_y" value="<?php echo (int) $y; ?>">
                        <input type="hidden" name="return_m" value="<?php echo (int) $m; ?>">

                        <p class="small text-muted mb-3">These values are stored per facility and drive the indicative worksheets below. They do not replace statutory filings.</p>

                        <div class="row">
                            <div class="form-group col-md-4">
                                <label for="company_niu">NIU / No. d’identification fiscale</label>
                                <input class="form-control" id="company_niu" name="company_niu" value="<?php echo hms_h($niu); ?>" placeholder="Numéro unique d’identification">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="tax_centre">Tax centre / DGI office</label>
                                <input class="form-control" id="tax_centre" name="tax_centre" value="<?php echo hms_h($centre); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="legal_form_note">Legal form &amp; activity</label>
                                <input class="form-control" id="legal_form_note" name="legal_form_note" value="<?php echo hms_h($legalNote); ?>" placeholder="e.g. SARLU — clinique">
                            </div>
                        </div>

                        <hr class="my-3">
                        <p class="hms-tax-pill mb-2">Rates used in worksheets</p>
                        <div class="row">
                            <div class="form-group col-md-2">
                                <label for="tva_rate_standard">Standard VAT (%)</label>
                                <input class="form-control" id="tva_rate_standard" name="tva_rate_standard" value="<?php echo hms_h((string) $tvaRate); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="cit_rate_percent">CIT / IS standard rate (%)</label>
                                <input class="form-control" id="cit_rate_percent" name="cit_rate_percent" value="<?php echo hms_h((string) $citRate); ?>" title="Indicative standard rate — exemptions and reduced regimes are not modeled here">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="cnps_employee_pct">CNPS employee share (%)</label>
                                <input class="form-control" id="cnps_employee_pct" name="cnps_employee_pct" value="<?php echo hms_h((string) $cnpsEmp); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="cnps_employer_pct">CNPS employer share (%)</label>
                                <input class="form-control" id="cnps_employer_pct" name="cnps_employer_pct" value="<?php echo hms_h((string) $cnpsEr); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="withholding_services_pct">W/holding services (%)</label>
                                <input class="form-control" id="withholding_services_pct" name="withholding_services_pct" value="<?php echo hms_h((string) $whSvc); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="withholding_nonresident_pct">W/holding non-resident (%)</label>
                                <input class="form-control" id="withholding_nonresident_pct" name="withholding_nonresident_pct" value="<?php echo hms_h((string) $whNres); ?>">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="withholding_rent_pct">W/holding rent / RCCM-style (%)</label>
                                <input class="form-control" id="withholding_rent_pct" name="withholding_rent_pct" value="<?php echo hms_h((string) $whRent); ?>">
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label for="cit_working_notes">Income tax — working notes (reinvestments, exemptions, loss carry-forward…)</label>
                            <textarea class="form-control" id="cit_working_notes" name="cit_working_notes" rows="2" maxlength="3900" placeholder="Short reminders for your accountant"><?php echo hms_h($citNotes); ?></textarea>
                        </div>
                        <div class="form-group mb-0">
                            <label for="irpp_payroll_notes">Payroll — IRPP brackets / special staff categories</label>
                            <textarea class="form-control" id="irpp_payroll_notes" name="irpp_payroll_notes" rows="2" maxlength="3900" placeholder="e.g. expatriate regime, housing allowance treatment"><?php echo hms_h($irppNotes); ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4 mb-0">
                                <label for="patente_notes">Business licence (patente)</label>
                                <textarea class="form-control" id="patente_notes" name="patente_notes" rows="2" maxlength="1200"><?php echo hms_h($patenteNotes); ?></textarea>
                            </div>
                            <div class="form-group col-md-4 mb-0">
                                <label for="municipal_local_notes">Municipal / local levies</label>
                                <textarea class="form-control" id="municipal_local_notes" name="municipal_local_notes" rows="2" maxlength="1200"><?php echo hms_h($municipalNotes); ?></textarea>
                            </div>
                            <div class="form-group col-md-4 mb-0">
                                <label for="excise_transport_notes">Excise / transport / sector taxes</label>
                                <textarea class="form-control" id="excise_transport_notes" name="excise_transport_notes" rows="2" maxlength="1200"><?php echo hms_h($exciseNotes); ?></textarea>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label for="other_taxes_notes">Other taxes &amp; compliance checklist</label>
                            <textarea class="form-control" id="other_taxes_notes" name="other_taxes_notes" rows="3" maxlength="3900" placeholder="Stamp duties, registration fees, sector-specific contributions…"><?php echo hms_h($otherTaxNotes); ?></textarea>
                        </div>
                    </div>
                </form>
                <?php } ?>

                <?php if ($tab === 'vat') { ?>
                <?php
                $hms_fin_report_document_title = 'MONTHLY VAT (TVA) WORKSHEET';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Entity' => $facName,
                    'Period' => sprintf('%02d / %d', $m, $y),
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'NIU on file' => $niu !== '' ? $niu : '—',
                    'Tax office' => $centre,
                    'Report date' => date('Y-m-d'),
                    'Report ref.' => 'VAT-' . sprintf('%04d%02d', $y, $m),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">1. Figures from the general ledger (estimate)</p>
                    <p class="small text-muted px-3 mb-2">Income and expense proxies come from class 7 and 6 movements. Use patient/billing invoices for definitive VAT lines.</p>
                    <div class="table-responsive hms-fin-table-wrap px-0">
                    <table class="table hms-fin-table hms-fin-table--striped mb-4">
                        <thead>
                            <tr>
                                <th scope="col">Description</th>
                                <th class="hms-ohada-num" scope="col">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Taxable turnover (ledger proxy — class 7, net credit)</td>
                                <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf((float) $tvaEst['ca_ht']); ?></td>
                            </tr>
                            <tr>
                                <td>Estimated output VAT (turnover × <?php echo hms_h((string) $tvaEst['taux_pct']); ?>%)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $tvaEst['tva_collectee_est']); ?></td>
                            </tr>
                            <tr>
                                <td>Estimated input VAT (expenses × rate, indicative factor 0.85)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $tvaEst['tva_deductible_est']); ?></td>
                            </tr>
                            <tr class="hms-fin-total-row">
                                <td>Net VAT payable (estimate)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $tvaEst['tva_net_est']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <h3 class="h6 font-weight-bold mt-4 px-3">2. Typical DGI layout (complete outside HMS if required)</h3>
                    <ul class="small">
                        <li><strong>Taxable sales excl. VAT</strong> by rate (standard / reduced / exempt) — from issued invoices.</li>
                        <li><strong>Output VAT</strong> on taxable supplies.</li>
                        <li><strong>Recoverable VAT</strong> on purchases and fixed assets (per carry-forward rules).</li>
                        <li><strong>VAT credit</strong> carried forward where applicable.</li>
                    </ul>

                    <p class="hms-ohada-disclaimer mb-0">Indicative working papers for Cameroon tax; not an official e-filing. DGI rules and a licensed accountant govern compliance.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>VAT worksheet</span>
                        <span>Page 1</span>
                    </div>
                </div>

                <?php } elseif ($tab === 'income') { ?>
                <?php
                $hms_fin_report_document_title = 'CORPORATE INCOME TAX (IS) — INDICATIVE';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Entity' => $facName,
                    'Fiscal year' => (string) $y,
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'NIU on file' => $niu !== '' ? $niu : '—',
                    'Tax office' => $centre,
                    'Report date' => date('Y-m-d'),
                    'CIT rate used' => sprintf('%.2f%%', $citRate) . ' (editable in parameters)',
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">1. Accounting result from OHADA-style classes 6 &amp; 7 (ledger)</p>
                    <p class="small text-muted px-3 mb-2">Annual view uses the full calendar year <?php echo (int) $y; ?>. Monthly column uses the month selected above (<?php echo sprintf('%02d/%d', $m, $y); ?>).</p>
                    <div class="table-responsive hms-fin-table-wrap px-0">
                    <table class="table hms-fin-table hms-fin-table--striped mb-3">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="hms-ohada-num">Year <?php echo (int) $y; ?></th>
                                <th class="hms-ohada-num">Month <?php echo sprintf('%02d/%d', $m, $y); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Revenue (class 7, net)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($plYear['produits'] ?? 0)); ?></td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($plMonth['produits'] ?? 0)); ?></td>
                            </tr>
                            <tr>
                                <td>Expenses (class 6, net)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($plYear['charges'] ?? 0)); ?></td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($plMonth['charges'] ?? 0)); ?></td>
                            </tr>
                            <tr class="hms-fin-total-row">
                                <td>Pre-tax accounting result (indicative)</td>
                                <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf((float) ($plYear['resultat'] ?? 0)); ?></td>
                                <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf((float) ($plMonth['resultat'] ?? 0)); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">2. Indicative corporate income tax (CIT / IS)</p>
                    <p class="small text-muted px-3 mb-2">Applied only on <strong>positive</strong> annual result at the standard rate you configured. Off-balance-sheet adjustments, exemptions, and instalments are not calculated here.</p>
                    <div class="table-responsive hms-fin-table-wrap px-0">
                    <table class="table hms-fin-table hms-fin-table--striped mb-3">
                        <tbody>
                            <tr>
                                <td>Taxable base (annual accounting result, if &gt; 0)</td>
                                <td class="hms-ohada-num font-weight-bold"><?php echo $citAnnual['loss_position'] ? '<span class="text-muted">0 (loss)</span>' : hms_format_xaf((float) $citAnnual['accounting_result']); ?></td>
                            </tr>
                            <tr>
                                <td>Standard CIT rate</td>
                                <td class="hms-ohada-num"><?php echo hms_h((string) $citAnnual['rate_pct']); ?>%</td>
                            </tr>
                            <tr class="hms-fin-total-row">
                                <td>Indicative CIT (annual)</td>
                                <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $citAnnual['cit_indicative']); ?></td>
                            </tr>
                            <tr>
                                <td colspan="2" class="small text-muted">Month-only indicative (not annualised): <?php echo hms_format_xaf((float) $citMonth['cit_indicative']); ?> — for management sense-check only.</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($citNotes !== '') { ?>
                    <div class="px-3 mb-3"><p class="small font-weight-bold mb-1">Your income-tax notes</p><div class="border rounded p-2 small bg-light"><?php echo nl2br(hms_h($citNotes)); ?></div></div>
                    <?php } ?>

                    <h3 class="h6 font-weight-bold mt-3 px-3">3. Filing rhythm (reference)</h3>
                    <ul class="small mb-0">
                        <li>Quarterly instalments and annual <strong>déclaration IS</strong> per DGI calendar.</li>
                        <li>Reconcile accounting profit to <strong>fiscal result</strong> (non-deductible expenses, tax credits, incentives).</li>
                        <li>Coordinate with <strong>OHADA</strong> statutory accounts and any sector-specific surtaxes.</li>
                    </ul>

                    <p class="hms-ohada-disclaimer mb-0 mt-3">Indicative only. Corporate income tax in Cameroon depends on legal form, sector, and exemptions — always confirm with the DGI and your expert-comptable.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Income tax worksheet</span>
                        <span>Page 1</span>
                    </div>
                </div>

                <?php } elseif ($tab === 'payroll') { ?>
                <div class="card border-0 shadow-sm hms-fin-report hms-ohada-report">
                    <div class="card-body">
                        <h2 class="h5 font-weight-bold text-dark mb-3"><i class="fa fa-users text-primary mr-2"></i>Payroll taxes &amp; social contributions (CNPS / IRPP)</h2>
                        <p class="small text-muted">HMS does not run a full payroll engine. This section records <strong>reference rates</strong> and notes for your payroll provider. CNPS and IRPP on salaries follow Cameroon labour and tax law; rates vary by wage band and category.</p>

                        <div class="table-responsive mb-4">
                            <table class="table table-sm table-bordered bg-white">
                                <thead class="thead-light"><tr><th>Item</th><th class="text-right">Rate on file (%)</th><th>Notes</th></tr></thead>
                                <tbody>
                                    <tr><td>CNPS — employee share (indicative)</td><td class="text-right font-weight-bold"><?php echo hms_h((string) $cnpsEmp); ?></td><td class="small text-muted">Pension / family / work injury components — confirm current barème.</td></tr>
                                    <tr><td>CNPS — employer share (indicative)</td><td class="text-right font-weight-bold"><?php echo hms_h((string) $cnpsEr); ?></td><td class="small text-muted">Total employer social before caps.</td></tr>
                                    <tr><td>IRPP on salaries</td><td class="text-right">—</td><td class="small">Progressive scale; configure allowances and credits in your payroll software. Use the notes field below for hospital-specific rules.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($irppNotes !== '') { ?>
                        <div class="mb-0"><p class="small font-weight-bold mb-1">Payroll / IRPP notes</p><div class="border rounded p-3 small bg-light"><?php echo nl2br(hms_h($irppNotes)); ?></div></div>
                        <?php } else { ?>
                        <p class="small text-muted mb-0">Add payroll and IRPP reminders in <strong>Facility tax parameters</strong> above.</p>
                        <?php } ?>

                        <hr class="my-4">
                        <h3 class="h6 font-weight-bold">Typical declarations (non-exhaustive)</h3>
                        <ul class="small mb-0">
                            <li><strong>Déclaration CNPS</strong> — monthly or per CNPS schedule.</li>
                            <li><strong>IRPP withheld</strong> on salaries — remitted per DGI rules.</li>
                            <li><strong>Training / localisation</strong> levies where applicable to headcount.</li>
                        </ul>
                    </div>
                </div>

                <?php } else { /* other */ ?>
                <div class="card border-0 shadow-sm hms-fin-report hms-ohada-report">
                    <div class="card-body">
                        <h2 class="h5 font-weight-bold text-dark mb-3"><i class="fa fa-list text-primary mr-2"></i>Other taxes &amp; withholdings</h2>
                        <p class="small text-muted mb-4">Reference rates you configured are summarised here for contracts and supplier payments. Amounts are <strong>not</strong> auto-posted to the GL from this screen.</p>

                        <div class="table-responsive mb-4">
                            <table class="table table-sm table-bordered bg-white">
                                <thead class="thead-light"><tr><th>Type</th><th class="text-right">Default rate (%)</th><th>Typical base</th></tr></thead>
                                <tbody>
                                    <tr><td>Withholding on services (RAS / similar)</td><td class="text-right font-weight-bold"><?php echo hms_h((string) $whSvc); ?></td><td class="small">Payments to certain contractors — verify current DGI texts.</td></tr>
                                    <tr><td>Withholding on non-residents</td><td class="text-right font-weight-bold"><?php echo hms_h((string) $whNres); ?></td><td class="small">Cross-border services, royalties, etc.</td></tr>
                                    <tr><td>Withholding on rent / certain leases</td><td class="text-right font-weight-bold"><?php echo hms_h((string) $whRent); ?></td><td class="small">Rents paid to landlords — regime-dependent.</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <h3 class="h6 font-weight-bold">Business licence (patente)</h3>
                                <p class="small text-muted mb-1">Annual licence linked to turnover brackets and activity.</p>
                                <div class="border rounded p-2 small bg-light min-h-100"><?php echo $patenteNotes !== '' ? nl2br(hms_h($patenteNotes)) : '<span class="text-muted">— No notes —</span>'; ?></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <h3 class="h6 font-weight-bold">Municipal &amp; local taxes</h3>
                                <p class="small text-muted mb-1">Communal surcharges, signage, waste, etc.</p>
                                <div class="border rounded p-2 small bg-light"><?php echo $municipalNotes !== '' ? nl2br(hms_h($municipalNotes)) : '<span class="text-muted">— No notes —</span>'; ?></div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <h3 class="h6 font-weight-bold">Excise, transport, sector levies</h3>
                                <div class="border rounded p-2 small bg-light"><?php echo $exciseNotes !== '' ? nl2br(hms_h($exciseNotes)) : '<span class="text-muted">— No notes —</span>'; ?></div>
                            </div>
                        </div>

                        <?php if ($otherTaxNotes !== '') { ?>
                        <div class="mb-0"><p class="h6 font-weight-bold mb-2">Compliance checklist &amp; other items</p><div class="border rounded p-3 small bg-white"><?php echo nl2br(hms_h($otherTaxNotes)); ?></div></div>
                        <?php } ?>

                        <p class="hms-ohada-disclaimer mb-0 mt-4">Rates and bases change with finance laws. Keep official DGI / MINEPAT notices and your accountant’s file as the source of truth.</p>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
