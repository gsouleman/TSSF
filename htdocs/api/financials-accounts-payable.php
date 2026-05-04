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
$d1 = trim((string) ($_GET['d1'] ?? date('Y-m-01')));
$d2 = trim((string) ($_GET['d2'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$apOk = hms_db_table_exists($connection, 'tbl_expense');
$apRows = $apOk ? hms_fin_ap_vendor_summary($connection, $fid, $d1, $d2) : [];

$sumAp = 0.0;
foreach ($apRows as $r) {
    $sumAp += (float) ($r['amount'] ?? 0);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Accounts payable (expenses by vendor)', [
                    'subtitle' => 'Solidarity of Hearts Hospital — supplier / vendor spend summary.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Accounts payable', '']],
                    'back' => 'financials.php',
                ]);
                ?>
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

                <?php if (!$apOk) { ?>
                <div class="alert alert-warning">Expense data could not be loaded.</div>
                <?php } else { ?>
                <?php
                $hms_fin_report_document_title = 'ACCOUNTS PAYABLE — VENDOR SUMMARY';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Period' => $d1 . ' — ' . $d2,
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('Y-m-d'),
                    'Report ref.' => 'AP-' . str_replace('-', '', $d2),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr><th>Vendor</th><th class="hms-ohada-num">Bills</th><th class="hms-ohada-num">Amount</th><th>Last expense</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apRows as $r) { ?>
                                <tr>
                                    <td><?php echo hms_h((string) ($r['vendor'] ?? '')); ?></td>
                                    <td class="hms-ohada-num"><?php echo (int) ($r['bills'] ?? 0); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($r['amount'] ?? 0)); ?></td>
                                    <td><?php echo hms_h((string) ($r['last_date'] ?? '')); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (count($apRows) === 0) { ?>
                                <tr><td colspan="4" class="text-muted">No expenses in period.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td><strong>Total</strong></td>
                                    <td class="hms-ohada-num"></td>
                                    <td class="hms-ohada-num"><strong><?php echo hms_format_xaf($sumAp); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">This summarizes recorded expenses by vendor name. It is not a full AP subledger (open invoices / due dates) unless those modules are implemented.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Accounts payable</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
