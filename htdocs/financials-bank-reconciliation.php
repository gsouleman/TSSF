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

$bankCode = preg_replace('/[^0-9]/', '', (string) ($_GET['bank'] ?? '521000'));
if ($bankCode === '') {
    $bankCode = '521000';
}
$asOf = trim((string) ($_GET['asof'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}
$stmtBal = trim((string) ($_GET['stmt'] ?? ''));
$stmtAmount = null;
if ($stmtBal !== '' && is_numeric($stmtBal)) {
    $stmtAmount = (float) $stmtBal;
}

$book = 0.0;
if ($finOk) {
    $book = hms_fin_account_balance_code_as_of($connection, $fid, $asOf, $bankCode);
}
$diff = $stmtAmount !== null ? ($stmtAmount - $book) : null;

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Bank reconciliation', [
                    'subtitle' => 'Solidarity of Hearts Hospital — GL bank balance vs statement.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Bank reconciliation', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="bank">Bank account (OHADA code)</label>
                            <input type="text" class="form-control" id="bank" name="bank" value="<?php echo hms_h($bankCode); ?>" maxlength="20" pattern="[0-9]+" inputmode="numeric">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="asof">Statement date</label>
                            <input type="date" class="form-control" id="asof" name="asof" value="<?php echo hms_h($asOf); ?>">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="stmt">Statement balance (optional)</label>
                            <input type="text" class="form-control" id="stmt" name="stmt" value="<?php echo hms_h($stmtBal); ?>" placeholder="e.g. 1250000">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'BANK RECONCILIATION';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Bank account (GL)' => $bankCode,
                    'As at' => $asOf,
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('Y-m-d'),
                    'Report ref.' => 'BR-' . str_replace('-', '', $asOf) . '-' . $bankCode,
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <tbody>
                                <tr>
                                    <td>Balance per general ledger (account <?php echo hms_h($bankCode); ?>)</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($book, false); ?></td>
                                </tr>
                                <?php if ($stmtAmount !== null) { ?>
                                <tr>
                                    <td>Balance per bank statement (entered)</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($stmtAmount); ?></td>
                                </tr>
                                <tr class="hms-fin-total-row">
                                    <td>Difference (statement − book)</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $diff); ?></td>
                                </tr>
                                <?php } else { ?>
                                <tr><td colspan="2" class="text-muted small">Enter the statement closing balance above to compute the difference vs the GL.</td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Outstanding items (manual checklist)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr><th>Description</th><th class="hms-ohada-num">Amount</th></tr>
                            </thead>
                            <tbody>
                                <tr><td class="text-muted">Cheques not yet cleared</td><td class="hms-ohada-num">—</td></tr>
                                <tr><td class="text-muted">Deposits in transit</td><td class="hms-ohada-num">—</td></tr>
                                <tr><td class="text-muted">Bank charges / interest not in GL</td><td class="hms-ohada-num">—</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-ohada-disclaimer mb-0">Reconcile by matching journal postings to the bank statement. Use journal vouchers for adjustments after approval.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Bank reconciliation</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
