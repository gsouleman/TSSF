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
$m = (int) ($_GET['m'] ?? (int) date('n'));
if ($y < 2000 || $y > 2100) {
    $y = (int) date('Y');
}
if ($m < 1 || $m > 12) {
    $m = (int) date('n');
}

$pm = $m - 1;
$py = $y;
if ($pm < 1) {
    $pm = 12;
    $py--;
}

$incomeAccts = [];
$expenseAccts = [];

$totIncCur = 0.0; $totIncPrev = 0.0; $totIncYtd = 0.0;
$totExpCur = 0.0; $totExpPrev = 0.0; $totExpYtd = 0.0;

$netCur = 0.0; $netPrev = 0.0; $netYtd = 0.0;
$periodFrom = '';
$periodTo = '';

if ($finOk) {
    $periodFrom = sprintf('%04d-%02d-01', $y, $m);
    $periodTo = date('Y-m-t', strtotime($periodFrom . ' 12:00:00'));
    
    $prevFrom = sprintf('%04d-%02d-01', $py, $pm);
    $prevTo = date('Y-m-t', strtotime($prevFrom . ' 12:00:00'));

    $ytdFrom = sprintf('%04d-01-01', $y);

    $rowsCur = hms_fin_account_movements_period($connection, $fid, $periodFrom, $periodTo);
    $rowsPrev = hms_fin_account_movements_period($connection, $fid, $prevFrom, $prevTo);
    $rowsYtd = hms_fin_account_movements_period($connection, $fid, $ytdFrom, $periodTo);

    $curMap = [];
    foreach ($rowsCur as $r) { $curMap[(string) $r['account_code']] = $r; }
    
    $prevMap = [];
    foreach ($rowsPrev as $r) { $prevMap[(string) $r['account_code']] = $r; }
    
    $ytdMap = [];
    foreach ($rowsYtd as $r) { $ytdMap[(string) $r['account_code']] = $r; }

    $allCodes = array_unique(array_merge(array_keys($curMap), array_keys($prevMap), array_keys($ytdMap)));
    sort($allCodes, SORT_STRING);

    foreach ($allCodes as $code) {
        if ($code === '') continue;
        $rc = $curMap[$code] ?? null;
        $rp = $prevMap[$code] ?? null;
        $ry = $ytdMap[$code] ?? null;
        
        $cl = (int) ($rc['class'] ?? $rp['class'] ?? $ry['class'] ?? 0);
        $lbl = (string) ($rc['account_label'] ?? $rp['account_label'] ?? $ry['account_label'] ?? '');
        $lbl = hms_fin_report_label_patient_context($lbl);

        $cDr = (float) ($rc['total_debit'] ?? 0);
        $cCr = (float) ($rc['total_credit'] ?? 0);
        
        $pDr = (float) ($rp['total_debit'] ?? 0);
        $pCr = (float) ($rp['total_credit'] ?? 0);

        $yDr = (float) ($ry['total_debit'] ?? 0);
        $yCr = (float) ($ry['total_credit'] ?? 0);
        
        if ($cl === 7) {
            $cBal = $cCr - $cDr;
            $pBal = $pCr - $pDr;
            $yBal = $yCr - $yDr;
            if (abs($cBal) > 0.001 || abs($pBal) > 0.001 || abs($yBal) > 0.001) {
                $incomeAccts[] = ['code' => (string) $code, 'label' => $lbl, 'cur' => $cBal, 'prev' => $pBal, 'ytd' => $yBal];
                $totIncCur += $cBal;
                $totIncPrev += $pBal;
                $totIncYtd += $yBal;
            }
        } elseif ($cl === 6) {
            $cBal = $cDr - $cCr;
            $pBal = $pDr - $pCr;
            $yBal = $yDr - $yCr;
            if (abs($cBal) > 0.001 || abs($pBal) > 0.001 || abs($yBal) > 0.001) {
                $expenseAccts[] = ['code' => (string) $code, 'label' => $lbl, 'cur' => $cBal, 'prev' => $pBal, 'ytd' => $yBal];
                $totExpCur += $cBal;
                $totExpPrev += $pBal;
                $totExpYtd += $yBal;
            }
        }
    }
    
    $netCur = $totIncCur - $totExpCur;
    $netPrev = $totIncPrev - $totExpPrev;
    $netYtd = $totIncYtd - $totExpYtd;
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Monthly Review', [
                    'subtitle' => 'Solidarity of Hearts Hospital — formal print-ready detailed statement for the selected month.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Monthly Review', '']],
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
                $hms_fin_report_document_title = 'MONTHLY REVIEW STATEMENT';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Entity' => $facName,
                    'Month / period' => sprintf('%04d-%02d', $y, $m),
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'Period' => ($periodFrom !== '' ? date('d-m-Y', strtotime($periodFrom)) : '') . ' — ' . ($periodTo !== '' ? date('d-m-Y', strtotime($periodTo)) : ''),
                    'Report date' => date('d-m-Y'),
                    'Prepared by' => '________________',
                    'Report ref.' => 'MFS-' . sprintf('%04d%02d', $y, $m),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>
                    
                    <p class="hms-fin-section-bar mb-0">Revenue summary &amp; detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:12%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">Current month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">Previous month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">YTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomeAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['cur'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['prev'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['ytd'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($incomeAccts)) { ?>
                                <tr><td colspan="5" class="text-muted font-italic">No revenue.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Revenue</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totIncCur); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totIncPrev); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totIncYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0">Expenditure summary &amp; detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:12%">Account</th>
                                    <th scope="col">Description</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">Current month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">Previous month</th>
                                    <th class="hms-ohada-num" scope="col" style="width:18%">YTD</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenseAccts as $a) { ?>
                                <tr>
                                    <td><code><?php echo hms_h($a['code']); ?></code></td>
                                    <td><?php echo hms_h($a['label']); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['cur'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['prev'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($a['ytd'], false); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (empty($expenseAccts)) { ?>
                                <tr><td colspan="5" class="text-muted font-italic">No expenditures.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Total Expenditure</td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totExpCur); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totExpPrev); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($totExpYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-fin-section-bar mb-0" style="background-color: #34495e;">Net Performance</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table mb-4">
                            <tbody>
                                <tr class="hms-fin-total-row">
                                    <td>Net Profit &amp; Loss</td>
                                    <td class="hms-ohada-num" style="width:18%"><?php echo hms_format_xaf($netCur); ?></td>
                                    <td class="hms-ohada-num" style="width:18%"><?php echo hms_format_xaf($netPrev); ?></td>
                                    <td class="hms-ohada-num" style="width:18%"><?php echo hms_format_xaf($netYtd); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="hms-fin-report-sig no-print px-3">
                        <div class="hms-fin-report-sig__grid hms-fin-report-sig__grid--3">
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Prepared by</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Reviewed by</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Approved by</div>
                            </div>
                        </div>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Management financial statement from HMS general ledger. Patient receivable wording is applied on supporting schedules.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Monthly review statement</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
