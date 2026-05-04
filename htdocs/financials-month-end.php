<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';
require_once __DIR__ . '/includes/financials_ohada.php';
require_once __DIR__ . '/includes/cameroon_money.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);

$y = (int) ($_GET['y'] ?? (int) date('Y'));
$m = (int) ($_GET['m'] ?? (int) date('n'));
if ($y < 2000 || $y > 2100) {
    $y = (int) date('Y');
}
if ($m < 1 || $m > 12) {
    $m = (int) date('n');
}

$incomeAccts = [];
$expenseAccts = [];
$totIncMonth = 0.0;
$totIncYtd = 0.0;
$totExpMonth = 0.0;
$totExpYtd = 0.0;
$netMonth = 0.0;
$netYtd = 0.0;
$periodFrom = '';
$periodTo = '';

if ($finOk) {
    $periodFrom = sprintf('%04d-%02d-01', $y, $m);
    $periodTo = date('Y-m-t', strtotime($periodFrom . ' 12:00:00'));
    $ytdFrom = sprintf('%04d-01-01', $y);

    $rowsMonth = hms_fin_account_movements_period($connection, $fid, $periodFrom, $periodTo);
    $rowsYtd = hms_fin_account_movements_period($connection, $fid, $ytdFrom, $periodTo);

    $monthMap = [];
    foreach ($rowsMonth as $r) {
        $monthMap[(string) $r['account_code']] = $r;
    }
    $ytdMap = [];
    foreach ($rowsYtd as $r) {
        $ytdMap[(string) $r['account_code']] = $r;
    }

    $allCodes = array_unique(array_merge(array_keys($monthMap), array_keys($ytdMap)));
    sort($allCodes, SORT_STRING);

    foreach ($allCodes as $code) {
        if ($code === '') {
            continue;
        }
        $rm = $monthMap[$code] ?? null;
        $ry = $ytdMap[$code] ?? null;
        $cl = (int) ($rm['class'] ?? $ry['class'] ?? 0);
        $lbl = (string) ($rm['account_label'] ?? $ry['account_label'] ?? '');
        $lbl = hms_fin_report_label_patient_context($lbl);

        $mDr = (float) ($rm['total_debit'] ?? 0);
        $mCr = (float) ($rm['total_credit'] ?? 0);
        
        $yDr = (float) ($ry['total_debit'] ?? 0);
        $yCr = (float) ($ry['total_credit'] ?? 0);
        
        if ($cl === 7) {
            $mBal = $mCr - $mDr;
            $yBal = $yCr - $yDr;
            if (abs($mBal) > 0.001 || abs($yBal) > 0.001) {
                $incomeAccts[] = ['code' => (string) $code, 'label' => $lbl, 'm' => $mBal, 'y' => $yBal];
                $totIncMonth += $mBal;
                $totIncYtd += $yBal;
            }
        } elseif ($cl === 6) {
            $mBal = $mDr - $mCr;
            $yBal = $yDr - $yCr;
            if (abs($mBal) > 0.001 || abs($yBal) > 0.001) {
                $expenseAccts[] = ['code' => (string) $code, 'label' => $lbl, 'm' => $mBal, 'y' => $yBal];
                $totExpMonth += $mBal;
                $totExpYtd += $yBal;
            }
        }
    }
    
    $netMonth = $totIncMonth - $totExpMonth;
    $netYtd = $totIncYtd - $totExpYtd;
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('PROFIT & LOSS MONTH END', [
                    'subtitle' => 'Solidarity of Hearts Hospital — comprehensive P&L analysis for the selected month.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Month End', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="m">Month</label>
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
                            <input type="number" class="form-control" id="y" name="y" value="<?php echo (int) $y; ?>" min="2000" max="2100">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'PROFIT & LOSS — MONTH END';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Month / period' => sprintf('%04d-%02d', $y, $m),
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Fiscal year' => (string) $y,
                    'Report date' => date('d-m-Y'),
                    'Facility' => '#' . (string) $fid,
                    'Branch / division' => '—',
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>
                    
                    <p class="hms-fin-section-bar mb-0">Income / Revenue (Class 7)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:15%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Current month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">YTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomeAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['m'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['y'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($incomeAccts)) { ?>
                                <tr><td colspan="4" class="text-muted font-italic">No revenue recorded for this period.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Income</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($totIncMonth); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($totIncYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Expenses / Expenditure (Class 6)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:15%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Current month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">YTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenseAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['m'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['y'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($expenseAccts)) { ?>
                                <tr><td colspan="4" class="text-muted font-italic">No expenses recorded for this period.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Expenses</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($totExpMonth); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($totExpYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0" style="background-color: #34495e;">Net Result</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table mb-0">
                            <tbody>
                                <tr class="hms-fin-total-row">
                                    <td>Net Profit &amp; Loss (Income − Expenses)</td>
                                    <td class="hms-ohada-num" style="width:20%"><?php echo hms_format_xaf($netMonth); ?></td>
                                    <td class="hms-ohada-num" style="width:20%"><?php echo hms_format_xaf($netYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-ohada-disclaimer mb-0 mt-3">Period <?php echo hms_h($periodFrom !== '' ? date('d-m-Y', strtotime($periodFrom)) : ''); ?> — <?php echo hms_h($periodTo !== '' ? date('d-m-Y', strtotime($periodTo)) : ''); ?>. YTD from 1 Jan <?php echo (int) $y; ?> through month end.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Profit &amp; loss — month end</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
