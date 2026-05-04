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
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);

$d1 = trim((string) ($_GET['d1'] ?? date('Y-m-01')));
$d2 = trim((string) ($_GET['d2'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$d0 = date('Y-m-d', strtotime('-1 day', strtotime($d1 . ' 12:00:00')));
$pl = ['resultat' => 0.0];
$cashOpen = 0.0;
$cashClose = 0.0;
$m5 = 0.0;
$m2 = 0.0;
$m1 = 0.0;
$opsRec = ['total' => 0.0, 'count' => 0];
$opsTxn = ['total' => 0.0, 'count' => 0];
if ($finOk) {
    $pl = hms_fin_pl_for_date_range($connection, $fid, $d1, $d2);
    $cashOpen = hms_fin_prefix_balance_as_of($connection, $fid, $d0, '5');
    $cashClose = hms_fin_prefix_balance_as_of($connection, $fid, $d2, '5');
    $m5 = hms_fin_prefix_movement_period($connection, $fid, $d1, $d2, '5');
    $m2 = hms_fin_prefix_movement_period($connection, $fid, $d1, $d2, '2');
    $m1 = hms_fin_prefix_movement_period($connection, $fid, $d1, $d2, '1');
}
$opsRec = hms_fin_ops_fiscal_receipts_period($connection, $fid, $d1, $d2);
$opsTxn = hms_fin_ops_transactions_period($connection, $fid, $d1, $d2);
$canSyncGl = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);
$glMismatchOps = $finOk && ($opsRec['total'] ?? 0) > 0.005 && (abs($m5) < 0.02 && abs($cashClose) < 0.02);

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Cash flow statement', [
                    'subtitle' => 'Solidarity of Hearts Hospital — GL-based cash and activity summary.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Cash flow', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } else { ?>
                <?php if ($glMismatchOps && $canSyncGl) { ?>
                <div class="alert alert-info no-print">
                    Fiscal receipts exist in billing for this period (<?php echo hms_format_xaf((float) ($opsRec['total'] ?? 0)); ?> on <?php echo (int) ($opsRec['count'] ?? 0); ?> receipt(s)),
                    but the GL shows no class 5 movement — likely history was recorded before automatic posting.
                    <a href="financials-sync-gl.php" class="alert-link">Sync receipts to the general ledger</a>, then refresh this report.
                </div>
                <?php } elseif ($glMismatchOps && !$canSyncGl) { ?>
                <div class="alert alert-secondary no-print">
                    Billing shows <?php echo hms_format_xaf((float) ($opsRec['total'] ?? 0)); ?> in fiscal receipts this period, but the GL cash position is still empty — ask a user with <strong>financials.write</strong> to run <a href="financials-sync-gl.php" class="alert-link">Sync to GL</a>.
                </div>
                <?php } ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="d1">From</label>
                            <input type="date" class="form-control" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="d2">To</label>
                            <input type="date" class="form-control" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'CASH FLOW STATEMENT';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Period' => $d1 . ' — ' . $d2,
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('Y-m-d'),
                    'Report ref.' => 'CF-' . str_replace('-', '', $d2),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">A. Operating activities (indicative)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr><th>Item</th><th class="hms-ohada-num">Amount</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Net result (income − expenses, classes 6–7)</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($pl['resultat'] ?? 0)); ?></td>
                                </tr>
                                <tr>
                                    <td>Net movement — cash &amp; banks (class 5 accounts)</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($m5, false); ?></td>
                                </tr>
                                <tr class="hms-fin-total-row">
                                    <td>Interpretation</td>
                                    <td class="hms-ohada-num small">Class 5 movement approximates treasury cash effect for the period.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">B. Investing activities (fixed assets — class 2)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <tbody>
                                <tr>
                                    <td>Net movement — class 2</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($m2, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">C. Financing activities (equity / long-term — class 1)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <tbody>
                                <tr>
                                    <td>Net movement — class 1</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($m1, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Cash position (class 5 — GL)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <tbody>
                                <tr><td>Opening cash &amp; banks (as at <?php echo hms_h($d0); ?>)</td><td class="hms-ohada-num"><?php echo hms_format_xaf($cashOpen, false); ?></td></tr>
                                <tr><td>Closing cash &amp; banks (as at <?php echo hms_h($d2); ?>)</td><td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($cashClose, false); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Cross-check — billing &amp; transactions (same period)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr><th>Source</th><th class="hms-ohada-num">Count</th><th class="hms-ohada-num">Amount</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Issued fiscal receipts (<a href="receipts-invoices.php" class="no-print">Receipts</a>)</td>
                                    <td class="hms-ohada-num"><?php echo (int) ($opsRec['count'] ?? 0); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($opsRec['total'] ?? 0)); ?></td>
                                </tr>
                                <tr>
                                    <td>Transactions workspace (<a href="transactions.php" class="no-print">Transactions</a>)</td>
                                    <td class="hms-ohada-num"><?php echo (int) ($opsTxn['count'] ?? 0); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($opsTxn['total'] ?? 0)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-3">Receipt totals should align with posted journals after <a href="financials-sync-gl.php" class="no-print">sync</a>; transactions often mirror receipts but can differ (e.g. manual entries).</p>

                    <p class="hms-ohada-disclaimer mb-0">Simplified statement from posted journals. A full indirect-method cash flow requires working-capital schedules (patient receivables, payables, inventory).</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Cash flow</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
