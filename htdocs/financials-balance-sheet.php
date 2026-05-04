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

$__hms_bs_get = static function (string $key, string $default): string {
    if (!isset($_GET[$key])) {
        return $default;
    }
    $v = $_GET[$key];
    if (is_string($v)) {
        return trim($v);
    }
    if (is_int($v) || is_float($v)) {
        return trim((string) $v);
    }

    return $default;
};
$asof = $__hms_bs_get('asof', date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof)) {
    $asof = date('Y-m-d');
}

$byClass = [];
$asofPrior = date('Y-m-d', strtotime('-1 year', strtotime($asof . ' 12:00:00')));
$compactBs = $__hms_bs_get('compact', '') === '1';
unset($__hms_bs_get);
if ($finOk) {
    $rowsRaw = hms_fin_account_balances_to_date($connection, $fid, $asof);
    $rowsPriorRaw = hms_fin_account_balances_to_date($connection, $fid, $asofPrior);
    $hms_fin_balance_sheet_build_rows = static function (array $rowsCurrent, array $rowsPrior, bool $includeZeroBothPeriods): array {
        $skeleton = [
            ['code' => '101000', 'label' => 'Share capital'],
            ['code' => '111000', 'label' => 'Share premium'],
            ['code' => '121000', 'label' => 'Legal reserves'],
            ['code' => '129000', 'label' => 'Retained earnings / carried forward'],
            ['code' => '131000', 'label' => 'Investment grants (if any)'],
            ['code' => '139000', 'label' => 'Subsidies recognized to P&L'],
            ['code' => '211000', 'label' => 'Land'],
            ['code' => '213000', 'label' => 'Buildings'],
            ['code' => '215000', 'label' => 'Medical & technical equipment'],
            ['code' => '218000', 'label' => 'Other tangible fixed assets'],
            ['code' => '244000', 'label' => 'Transport equipment'],
            ['code' => '281200', 'label' => 'Accumulated depreciation — buildings'],
            ['code' => '281300', 'label' => 'Accumulated depreciation — equipment'],
            ['code' => '311000', 'label' => 'Pharmaceutical & medical supplies inventory'],
            ['code' => '371000', 'label' => 'Merchandise inventory'],
            ['code' => '401000', 'label' => 'Suppliers — trade payables'],
            ['code' => '408000', 'label' => 'Suppliers — invoices not yet received'],
            ['code' => '411000', 'label' => 'Trade receivables — patients'],
            ['code' => '421000', 'label' => 'Personnel — wages payable'],
            ['code' => '431000', 'label' => 'Social security & payroll taxes payable'],
            ['code' => '441000', 'label' => 'State — taxes and duties payable'],
            ['code' => '444000', 'label' => 'State — VAT (net position)'],
            ['code' => '462000', 'label' => 'Receivables from staff / other debtors'],
            ['code' => '467000', 'label' => 'Other creditors / accruals'],
            ['code' => '511000', 'label' => 'Internal transfers / clearing'],
            ['code' => '521000', 'label' => 'Banks — patient collection'],
            ['code' => '522000', 'label' => 'Banks — operating'],
            ['code' => '531000', 'label' => 'Short-term investments'],
            ['code' => '571000', 'label' => 'Cash — patient collection'],
            ['code' => '581000', 'label' => 'Accrued interest / bank in transit'],
        ];
        $skLabel = [];
        foreach ($skeleton as $p) {
            $code = (string) ($p['code'] ?? '');
            if ($code !== '') {
                $skLabel[$code] = (string) ($p['label'] ?? '');
            }
        }
        $cur = [];
        foreach ($rowsCurrent as $r) {
            $code = trim((string) ($r['account_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $cl = (int) ($r['class'] ?? hms_fin_ohada_class_from_code($code));
            if ($cl < 1 || $cl > 5) {
                continue;
            }
            $cur[$code] = $r;
        }
        $pri = [];
        foreach ($rowsPrior as $r) {
            $code = trim((string) ($r['account_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $cl = (int) ($r['class'] ?? hms_fin_ohada_class_from_code($code));
            if ($cl < 1 || $cl > 5) {
                continue;
            }
            $pri[$code] = $r;
        }
        $orderedCodes = [];
        for ($cl = 1; $cl <= 5; $cl++) {
            $seen = [];
            $bucket = [];
            foreach ($skeleton as $p) {
                $code = (string) ($p['code'] ?? '');
                if ($code === '' || hms_fin_ohada_class_from_code((string) $code) !== $cl) {
                    continue;
                }
                $bucket[] = $code;
                $seen[$code] = true;
            }
            $extra = [];
            foreach (array_merge(array_keys($cur), array_keys($pri)) as $code) {
                if (isset($seen[$code])) {
                    continue;
                }
                if (hms_fin_ohada_class_from_code((string) $code) !== $cl) {
                    continue;
                }
                $extra[$code] = true;
            }
            $extraCodes = array_keys($extra);
            sort($extraCodes, SORT_STRING);
            foreach (array_merge($bucket, $extraCodes) as $code) {
                $orderedCodes[] = $code;
            }
        }
        $out = [];
        foreach ($orderedCodes as $code) {
            $cRow = $cur[$code] ?? null;
            $pRow = $pri[$code] ?? null;
            $tdr = $cRow !== null ? round((float) ($cRow['total_debit'] ?? 0), 2) : 0.0;
            $tcr = $cRow !== null ? round((float) ($cRow['total_credit'] ?? 0), 2) : 0.0;
            $bal = $cRow !== null ? round((float) ($cRow['balance'] ?? 0), 2) : 0.0;
            $tdrP = $pRow !== null ? round((float) ($pRow['total_debit'] ?? 0), 2) : 0.0;
            $tcrP = $pRow !== null ? round((float) ($pRow['total_credit'] ?? 0), 2) : 0.0;
            $balP = $pRow !== null ? round((float) ($pRow['balance'] ?? 0), 2) : 0.0;
            if (!$includeZeroBothPeriods
                && abs($bal) < 0.00001 && abs($balP) < 0.00001
                && abs($tdr) < 0.00001 && abs($tcr) < 0.00001
                && abs($tdrP) < 0.00001 && abs($tcrP) < 0.00001) {
                continue;
            }
            $labC = $cRow !== null ? trim((string) ($cRow['account_label'] ?? '')) : '';
            $labP = $pRow !== null ? trim((string) ($pRow['account_label'] ?? '')) : '';
            $label = $labC !== '' ? $labC : ($labP !== '' ? $labP : ($skLabel[$code] ?? $code));

            $out[] = [
                'account_code' => $code,
                'account_label' => $label,
                'total_debit' => $tdr,
                'total_credit' => $tcr,
                'balance' => $bal,
                'class' => hms_fin_ohada_class_from_code((string) $code),
                'total_debit_prior' => $tdrP,
                'total_credit_prior' => $tcrP,
                'balance_prior' => $balP,
            ];
        }

        return $out;
    };
    $merged = $hms_fin_balance_sheet_build_rows($rowsRaw, $rowsPriorRaw, !$compactBs);
    unset($hms_fin_balance_sheet_build_rows);
    foreach ($merged as $r) {
        $cl = (int) ($r['class'] ?? 0);
        if ($cl < 1 || $cl > 5) {
            continue;
        }
        if (!isset($byClass[$cl])) {
            $byClass[$cl] = [];
        }
        $byClass[$cl][] = $r;
    }
    ksort($byClass, SORT_NUMERIC);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Balance sheet', [
                    'subtitle' => 'Solidarity of Hearts Hospital - detailed statement of financial position (OHADA classes 1-5, cumulative debits/credits to each as-of date).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Balance sheet', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable. Run <code>database/migrations/019_credit_receivables.sql</code>.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-4 mb-0">
                            <label for="asof">As of</label>
                            <input type="date" class="form-control" id="asof" name="asof" value="<?php echo hms_h($asof); ?>">
                        </div>
                        <div class="form-group col-md-5 mb-0">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="compact" value="1" id="compactBs" <?php echo $compactBs ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="compactBs">Hide rows with no cumulative activity in either period</label>
                            </div>
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'BALANCE SHEET';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'As at' => $asof,
                    'Prior period' => $asofPrior,
                    'Currency' => hms_currency_label(),
                ];
                $hms_fin_report_meta_secondary = [
                    'Prepared by' => '________________',
                    'Facility' => '#' . (string) $fid,
                    'Report ref.' => 'BS-' . str_replace('-', '', $asof),
                    'Report date' => date('Y-m-d'),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <?php
                    $grandNet = 0.0;
                    $grandNetP = 0.0;
                    $grandDr = 0.0;
                    $grandCr = 0.0;
                    for ($cl = 1; $cl <= 5; $cl++) {
                        $list = $byClass[$cl] ?? [];
                        ?>
                    <p class="hms-fin-section-bar mb-0"><?php echo hms_h(hms_fin_ohada_class_title($cl)); ?></p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr>
                                    <th scope="col">Account</th>
                                    <th scope="col">Item / account name</th>
                                    <th class="hms-ohada-num" scope="col">Debit (cum.)</th>
                                    <th class="hms-ohada-num" scope="col">Credit (cum.)</th>
                                    <th class="hms-ohada-num" scope="col">Net — current</th>
                                    <th class="hms-ohada-num" scope="col">Net — prior</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $st = 0.0;
                                $stP = 0.0;
                                $stDr = 0.0;
                                $stCr = 0.0;
                        if ($list === []) {
                            ?>
                                <tr>
                                    <td colspan="6" class="text-muted">No accounts in this class for the current filters.</td>
                                </tr>
                            <?php
                        }
                        foreach ($list as $r) {
                            $b = (float) ($r['balance'] ?? 0);
                            $codeRaw = $r['account_code'] ?? '';
                            $code = is_scalar($codeRaw) ? trim((string) $codeRaw) : '';
                            $pb = (float) ($r['balance_prior'] ?? 0);
                            $dr = (float) ($r['total_debit'] ?? 0);
                            $cr = (float) ($r['total_credit'] ?? 0);
                            $st += $b;
                            $stP += $pb;
                            $stDr += $dr;
                            $stCr += $cr;
                            $labRaw = $r['account_label'] ?? '';
                            $labStr = is_scalar($labRaw) ? trim((string) $labRaw) : '';
                            $lab = hms_fin_report_label_patient_context($labStr);
                            ?>
                                <tr>
                                    <td><code><?php echo hms_h($code); ?></code></td>
                                    <td><?php echo hms_h($lab); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($dr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($cr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($b, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($pb, false); ?></td>
                                </tr>
                            <?php
                        }
                        $grandNet += $st;
                        $grandNetP += $stP;
                        $grandDr += $stDr;
                        $grandCr += $stCr;
                        ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">Subtotal — class <?php echo (int) $cl; ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($stDr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($stCr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($st, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($stP, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                        <?php
                    }
                    ?>
                    <p class="hms-fin-section-bar mb-0">Grand totals — classes 1–5 (memo)</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr>
                                    <th scope="col" colspan="2">Line</th>
                                    <th class="hms-ohada-num" scope="col">Debit (cum.)</th>
                                    <th class="hms-ohada-num" scope="col">Credit (cum.)</th>
                                    <th class="hms-ohada-num" scope="col">Net — current</th>
                                    <th class="hms-ohada-num" scope="col">Net — prior</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="hms-fin-total-row">
                                    <td colspan="2">All balance-sheet accounts shown above</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($grandDr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($grandCr, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($grandNet, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($grandNetP, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="hms-ohada-disclaimer mb-0">Each section lists OHADA classes 1-5 with a standard skeleton chart (dormant accounts show 0). Debit/Credit are cumulative through each as-of date. Prior net column is as at <?php echo hms_h($asofPrior); ?> (same calendar date one year earlier). Patient-related wording is applied where relevant. Statutory filings may require additional disclosures and sign conventions.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Balance sheet</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
