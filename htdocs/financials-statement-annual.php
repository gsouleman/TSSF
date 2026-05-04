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
$facName = function_exists('hms_current_facility_name') ? hms_current_facility_name($connection) : ('Facility #' . $fid);
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);

$y = (int) ($_GET['y'] ?? (int) date('Y'));
if ($y < 2000 || $y > 2100) {
    $y = (int) date('Y');
}

$py = $y - 1;

$incomeAccts = [];
$expenseAccts = [];

$totIncCur = 0.0; $totIncPrev = 0.0;
$totExpCur = 0.0; $totExpPrev = 0.0;
$netCur = 0.0; $netPrev = 0.0;

$bsAcctsByClass = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];

if ($finOk) {
    // 1. Profit & Loss detailed mapping
    $yFrom = sprintf('%04d-01-01', $y);
    $yTo = sprintf('%04d-12-31', $y);
    
    $pyFrom = sprintf('%04d-01-01', $py);
    $pyTo = sprintf('%04d-12-31', $py);

    $rowsCur = hms_fin_account_movements_period($connection, $fid, $yFrom, $yTo);
    $rowsPrev = hms_fin_account_movements_period($connection, $fid, $pyFrom, $pyTo);

    $curMap = [];
    foreach ($rowsCur as $r) { $curMap[(string) $r['account_code']] = $r; }
    $prevMap = [];
    foreach ($rowsPrev as $r) { $prevMap[(string) $r['account_code']] = $r; }

    $allCodes = array_unique(array_merge(array_keys($curMap), array_keys($prevMap)));
    sort($allCodes, SORT_STRING);

    foreach ($allCodes as $code) {
        if ($code === '') continue;
        $rc = $curMap[$code] ?? null;
        $rp = $prevMap[$code] ?? null;
        
        $cl = (int) ($rc['class'] ?? $rp['class'] ?? 0);
        $lbl = (string) ($rc['account_label'] ?? $rp['account_label'] ?? '');
        $lbl = hms_fin_report_label_patient_context($lbl);

        $cDr = (float) ($rc['total_debit'] ?? 0);
        $cCr = (float) ($rc['total_credit'] ?? 0);
        
        $pDr = (float) ($rp['total_debit'] ?? 0);
        $pCr = (float) ($rp['total_credit'] ?? 0);

        if ($cl === 7) {
            $cBal = $cCr - $cDr;
            $pBal = $pCr - $pDr;
            if (abs($cBal) > 0.001 || abs($pBal) > 0.001) {
                $incomeAccts[] = ['code' => (string) $code, 'label' => $lbl, 'cur' => $cBal, 'prev' => $pBal];
                $totIncCur += $cBal;
                $totIncPrev += $pBal;
            }
        } elseif ($cl === 6) {
            $cBal = $cDr - $cCr;
            $pBal = $pDr - $pCr;
            if (abs($cBal) > 0.001 || abs($pBal) > 0.001) {
                $expenseAccts[] = ['code' => (string) $code, 'label' => $lbl, 'cur' => $cBal, 'prev' => $pBal];
                $totExpCur += $cBal;
                $totExpPrev += $pBal;
            }
        }
    }
    
    $netCur = $totIncCur - $totExpCur;
    $netPrev = $totIncPrev - $totExpPrev;

    // 2. Balance Sheet Detailed Mapping (Classes 1-5)
    $asofCur = sprintf('%04d-12-31', $y);
    $asofPrior = sprintf('%04d-12-31', $y - 1);
    
    $bsCur = hms_fin_account_balances_to_date($connection, $fid, $asofCur);
    $bsPrior = hms_fin_account_balances_to_date($connection, $fid, $asofPrior);
    
    $bsCurMap = [];
    foreach ($bsCur as $r) { $bsCurMap[(string) $r['account_code']] = $r; }
    $bsPriorMap = [];
    foreach ($bsPrior as $r) { $bsPriorMap[(string) $r['account_code']] = $r; }
    
    $allBsCodes = array_unique(array_merge(array_keys($bsCurMap), array_keys($bsPriorMap)));
    sort($allBsCodes, SORT_STRING);
    
    foreach ($allBsCodes as $code) {
        if ($code === '') continue;
        $rc = $bsCurMap[$code] ?? null;
        $rp = $bsPriorMap[$code] ?? null;
        
        $lbl = (string) ($rc['account_label'] ?? $rp['account_label'] ?? '');
        $lbl = hms_fin_report_label_patient_context($lbl);
        
        $cl = hms_fin_ohada_class_from_code((string) $code); // requires strictly typed string
        
        $cBal = (float) ($rc['balance'] ?? 0);
        $pBal = (float) ($rp['balance'] ?? 0);
        
        if ($cl >= 1 && $cl <= 5) {
            if (abs($cBal) > 0.001 || abs($pBal) > 0.001) {
                $bsAcctsByClass[$cl][] = ['code' => (string) $code, 'label' => $lbl, 'cur' => $cBal, 'prev' => $pBal];
            }
        }
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Annual Review', [
                    'subtitle' => 'Solidarity of Hearts Hospital — formal print-ready detailed statement for the calendar year.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Annual Review', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-3 mb-0">
                            <label for="y">Fiscal year</label>
                            <input type="number" class="form-control" id="y" name="y" value="<?php echo (int) $y; ?>" min="2000" max="2100">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'ANNUAL REVIEW STATEMENT';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Entity' => $facName,
                    'Fiscal year' => (string) $y,
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'Reg. number' => '—',
                    'Report date' => date('d-m-Y'),
                    'Prepared by' => '________________',
                    'Report ref.' => 'AFS-' . (string) $y,
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">Revenue summary &amp; detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:15%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Current year</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Prior year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomeAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['cur'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['prev'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($incomeAccts)) { ?>
                                <tr><td colspan="4" class="text-muted font-italic">No revenue.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Revenue</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totIncCur); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totIncPrev); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Expenditure summary &amp; detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:15%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Current year</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Prior year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenseAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['cur'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['prev'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($expenseAccts)) { ?>
                                <tr><td colspan="4" class="text-muted font-italic">No expenditures.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Expenditure</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totExpCur); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totExpPrev); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0" style="background-color: #34495e;">Net Performance</p>
                    <div class="table-responsive hms-fin-table-wrap mb-4">
                        <table class="table hms-fin-table mb-0">
                            <tbody>
                                <tr class="hms-fin-total-row">
                                    <td>Net Profit &amp; Loss</td>
                                    <td class="hms-ohada-num" style="width:20%"><?php echo hms_format_xaf($netCur); ?></td>
                                    <td class="hms-ohada-num" style="width:20%"><?php echo hms_format_xaf($netPrev); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Balance sheet snapshot (Granular)</p>
                    <p class="small text-muted px-3 mb-2">Detailed net balances as at <?php echo hms_h(sprintf('%04d-12-31', $y)); ?> vs <?php echo hms_h(sprintf('%04d-12-31', $y - 1)); ?>.</p>
                    
                    <?php for ($cl = 1; $cl <= 5; $cl++) { 
                        $title = hms_fin_ohada_class_title($cl);
                        $accts = $bsAcctsByClass[$cl];
                        if (empty($accts)) continue;
                    ?>
                    <h5 class="hms-fin-table-subhead px-3 mt-3 mb-1" style="color: #2c3e50; font-weight: 700;"><?php echo hms_h($title); ?></h5>
                    <div class="table-responsive hms-fin-table-wrap mb-3">
                        <table class="table hms-fin-table hms-fin-table--striped mb-0">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:15%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Current balance</th>
                                    <th class="hms-ohada-num" scope="col" style="width:20%">Prior balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sC = 0.0; $sP = 0.0;
                                foreach ($accts as $a) { 
                                    $sC += $a['cur']; $sP += $a['prev'];
                                ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['cur'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['prev'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row" style="background-color: #ecf0f1;">
                                    <td colspan="2">Net Class <?php echo $cl; ?></td>
                                    <td class="hms-ohada-num font-weight-bold" style="color:#2c3e50;"><?php echo hms_format_xaf($sC, false); ?></td>
                                    <td class="hms-ohada-num font-weight-bold" style="color:#2c3e50;"><?php echo hms_format_xaf($sP, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>

                    <div class="hms-fin-report-sig no-print px-3 mt-5">
                        <div class="hms-fin-report-sig__grid hms-fin-report-sig__grid--3">
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Chief financial officer</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Chief executive / delegate</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Chair / board</div>
                            </div>
                        </div>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Management summary from HMS. External audit may adjust figures.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Annual review statement</span>
                        <span>Page __</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
