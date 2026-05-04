<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';
require_once __DIR__ . '/includes/financials_reports_data.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$asOf = trim((string) ($_GET['asof'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}

$arOk = hms_db_table_exists($connection, 'tbl_credit_account') && hms_db_table_exists($connection, 'tbl_patient');
$arRows = $arOk ? hms_fin_ar_report_rows($connection, $fid, $asOf) : [];

$byBucket = [];
$sumTotal = 0.0;
foreach ($arRows as $r) {
    $b = (string) ($r['bucket'] ?? 'Other');
    $bal = (float) ($r['balance'] ?? 0);
    if (!isset($byBucket[$b])) {
        $byBucket[$b] = 0.0;
    }
    $byBucket[$b] += $bal;
    $sumTotal += $bal;
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Accounts receivable', [
                    'subtitle' => 'Solidarity of Hearts Hospital — patient credit balances (aging).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Accounts receivable', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="asof">As at</label>
                            <input type="date" class="form-control" id="asof" name="asof" value="<?php echo hms_h($asOf); ?>">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php if (!$arOk) { ?>
                <div class="alert alert-warning">Credit / patient receivable tables are not available. Install credit billing (see Help &amp; setup) to use this report.</div>
                <?php } else { ?>
                <?php
                $hms_fin_report_document_title = 'ACCOUNTS RECEIVABLE AGING';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'As at' => $asOf,
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('Y-m-d'),
                    'Report ref.' => 'AR-' . str_replace('-', '', $asOf),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">Summary by aging bucket</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr><th>Bucket</th><th class="hms-ohada-num">Amount</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($byBucket as $bk => $amt) { ?>
                                <tr>
                                    <td><?php echo hms_h($bk); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($amt); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (count($byBucket) === 0) { ?>
                                <tr><td colspan="2" class="text-muted">No outstanding balances.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td><strong>Total</strong></td>
                                    <td class="hms-ohada-num"><strong><?php echo hms_format_xaf($sumTotal); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Aging</th>
                                    <th class="hms-ohada-num">Balance</th>
                                    <th>Status</th>
                                    <th>Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($arRows as $r) { ?>
                                <tr>
                                    <td><?php echo hms_h((string) ($r['patient'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['bucket'] ?? '')); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($r['balance'] ?? 0)); ?></td>
                                    <td><?php echo hms_h((string) ($r['status'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['invoice_due_date'] ?? '')); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (count($arRows) === 0) { ?>
                                <tr><td colspan="5" class="text-muted">No outstanding balances.</td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Based on credit account charges, payments, and adjustments. Verify against clinical billing.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Accounts receivable</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
