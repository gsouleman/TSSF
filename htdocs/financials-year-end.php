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

$y = (int) ($_GET['y'] ?? (int) date('Y'));
if ($y < 2000 || $y > 2100) {
    $y = (int) date('Y');
}

$pl = [];
$bs = [];
if ($finOk) {
    $pl = hms_fin_pl_for_year($connection, $fid, $y);
    $bs = hms_fin_account_balances_to_date($connection, $fid, sprintf('%04d-12-31', $y));
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('PROFIT & LOSS YEAR END', [
                    'subtitle' => 'Solidarity of Hearts Hospital — annual activity summary and balance extract as of 31 December.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Year-end', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-3 mb-0">
                            <label for="y">Fiscal year (calendar)</label>
                            <input type="number" class="form-control" id="y" name="y" value="<?php echo (int) $y; ?>" min="2000" max="2100">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'PROFIT & LOSS YEAR END';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Fiscal year' => (string) $y,
                    'Period' => date('d-m-Y', strtotime((string) ($pl['period_from'] ?? ''))) . ' → ' . date('d-m-Y', strtotime((string) ($pl['period_to'] ?? ''))),
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'Prepared by' => '________________',
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('d-m-Y'),
                    'Report ref.' => 'YE-' . (string) $y,
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>
                    <p class="hms-fin-section-bar mb-0">Annual income &amp; expenditure</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Total expenses</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf((float) ($pl['charges'] ?? 0)); ?></td>
                                </tr>
                                <tr>
                                    <td>Total income</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf((float) ($pl['produits'] ?? 0)); ?></td>
                                </tr>
                                <tr class="hms-fin-total-row">
                                    <td>Net result for the year</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($pl['resultat'] ?? 0)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Balance extract as of 31/12/<?php echo (int) $y; ?> (first accounts)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Account</th>
                                    <th scope="col">Account name</th>
                                    <th class="hms-ohada-num" scope="col">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $n = 0;
                                foreach ($bs as $r) {
                                    if ($n++ > 40) {
                                        break;
                                    }
                                    $lab = hms_fin_report_label_patient_context((string) ($r['account_label'] ?? ''));
                                    ?>
                                <tr>
                                    <td><code><?php echo hms_h($r['account_code']); ?></code></td>
                                    <td><?php echo hms_h($lab); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['balance'], false); ?></td>
                                </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Year-end closing and statutory filings require your accountant. Patient-facing receivable labels are normalized to &quot;Patient&quot; where applicable.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Profit &amp; loss year end</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
