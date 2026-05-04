<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';
require_once __DIR__ . '/includes/financials_reports_data.php';
require_once __DIR__ . '/includes/financials_ohada.php';
require_once __DIR__ . '/includes/cameroon_money.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

if (!isset($connection) || !($connection instanceof mysqli)) {
    http_response_code(503);
    exit('Database connection is not available.');
}

hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canSyncTb = $finOk && function_exists('hms_fin_can_write') && hms_fin_can_write($connection);

$syncTbFlash = '';
if (!empty($_SESSION['hms_tb_sync_flash'])) {
    $syncTbFlash = (string) $_SESSION['hms_tb_sync_flash'];
    unset($_SESSION['hms_tb_sync_flash']);
}

$d1 = trim((string) ($_GET['d1'] ?? ''));
$d2 = trim((string) ($_GET['d2'] ?? ''));
if ($d1 === '' && $d2 === '' && $finOk && function_exists('hms_fin_journal_entry_date_bounds')) {
    $jb = hms_fin_journal_entry_date_bounds($connection, $fid);
    if ($jb !== null) {
        $d1 = $jb['min'];
        $d2 = $jb['max'];
    }
}
if ($d1 === '') {
    $d1 = date('Y-m-01');
}
if ($d2 === '') {
    $d2 = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

if ($canSyncTb && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sync_tb_gl'])) {
    $redirD1 = trim((string) ($_POST['d1'] ?? $d1));
    $redirD2 = trim((string) ($_POST['d2'] ?? $d2));
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['hms_tb_sync_flash'] = 'Invalid security token.';
    } else {
        $p1 = $redirD1;
        $p2 = $redirD2;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p1) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p2)) {
            $_SESSION['hms_tb_sync_flash'] = 'Invalid date range.';
        } elseif (function_exists('hms_fin_backfill_receipt_journals_for_date_range')) {
            $nr = hms_fin_backfill_receipt_journals_for_date_range($connection, $fid, $p1, $p2, 5000);
            $eIns = 0;
            $eProc = 0;
            if (hms_db_table_exists($connection, 'tbl_expense') && function_exists('hms_fin_backfill_expense_journals_for_date_range')) {
                $ne = hms_fin_backfill_expense_journals_for_date_range($connection, $fid, $p1, $p2, 5000);
                $eIns = (int) ($ne['inserted'] ?? 0);
                $eProc = (int) ($ne['processed'] ?? 0);
            }
            $rIns = (int) ($nr['inserted'] ?? 0);
            $rProc = (int) ($nr['processed'] ?? 0);
            $rFail = (int) ($nr['failed'] ?? 0);
            $fe = (string) ($nr['first_error'] ?? '');
            $_SESSION['hms_tb_sync_flash'] = 'GL sync: receipts scanned ' . $rProc . ', new journals ' . $rIns
                . ($rFail > 0 ? (', failed ' . $rFail) : '')
                . ($fe !== '' ? (' — ' . $fe) : '')
                . '; expenses scanned ' . $eProc . ', new ' . $eIns . '. Refresh if lines do not appear.';
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirD1)) {
        $redirD1 = $d1;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $redirD2)) {
        $redirD2 = $d2;
    }
    header('Location: financials-trial-balance.php?' . http_build_query(['d1' => $redirD1, 'd2' => $redirD2]));
    exit;
}

$periodRows = [];
$closingRows = [];
$tbSqlErr = '';

if ($finOk) {
    if (function_exists('hms_fin_reports_clear_sql_error')) {
        hms_fin_reports_clear_sql_error();
    }
    $periodRows = hms_fin_tb_movement_rows($connection, $fid, $d1, $d2);
    if (function_exists('hms_fin_reports_last_sql_error')) {
        $tbSqlErr = hms_fin_reports_last_sql_error();
    }
    $closingRows = hms_fin_tb_balance_rows($connection, $fid, $d2);
    if ($tbSqlErr === '' && function_exists('hms_fin_reports_last_sql_error')) {
        $tbSqlErr = hms_fin_reports_last_sql_error();
    }
}

$tbEmpty = $finOk && $periodRows === [] && $closingRows === [];
$tbHealthMsg = '';
if ($finOk && $tbEmpty && $tbSqlErr === '' && function_exists('hms_fin_journal_health_snapshot')) {
    $tbSnap = hms_fin_journal_health_snapshot($connection, $fid, $d1, $d2);
    $tbHealthMsg = hms_fin_journal_health_hint_message($tbSnap, $d1, $d2);
}
$tbSiteHint = '';
$tbExtraHint = '';
if ($finOk && $tbEmpty && $tbSqlErr === '' && $tbHealthMsg === '') {
    if (function_exists('hms_fin_gl_empty_site_hint')) {
        $tbSiteHint = hms_fin_gl_empty_site_hint($connection, $fid, $d1, $d2);
    }
    if ($tbSiteHint === '') {
        if (function_exists('hms_fin_gl_empty_headers_without_lines_hint')) {
            $tbExtraHint = hms_fin_gl_empty_headers_without_lines_hint($connection, $fid, $d1, $d2);
        }
        if ($tbExtraHint === '' && function_exists('hms_fin_gl_empty_no_journals_anywhere_hint')) {
            $tbExtraHint = hms_fin_gl_empty_no_journals_anywhere_hint($connection, $d1, $d2);
        }
    }
}

$tbSubtitle = 'Solidarity of Hearts Hospital — in-house trial balance by account and period.';
if ($finOk && $tbEmpty) {
    $tbSubtitle .= ' No GL journal lines for site #' . (int) $fid . ' in ' . $d1 . '–' . $d2
        . ' (post billing to the GL first — Trial balance reads tbl_fin_journal_* only).';
}
$tbToolbarSecondary = [];
if ($finOk && $tbEmpty) {
    $qSync = http_build_query(['rf_d1' => $d1, 'rf_d2' => $d2, 'ex_d1' => $d1, 'ex_d2' => $d2]);
    $qDiag = http_build_query(['d1' => $d1, 'd2' => $d2]);
    $tbToolbarSecondary[] = [
        'label' => 'Sync to GL',
        'url' => 'financials-sync-gl.php?' . $qSync,
        'icon' => 'fa-refresh',
        'class' => 'btn-warning',
    ];
    $tbToolbarSecondary[] = [
        'label' => 'Journal diagnostics',
        'url' => 'financials-journal-diagnostics.php?' . $qDiag,
        'icon' => 'fa-stethoscope',
        'class' => 'btn-outline-secondary',
    ];
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Trial balance', [
                    'subtitle' => $tbSubtitle,
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Trial balance', '']],
                    'back' => 'financials.php',
                    'secondary' => $tbToolbarSecondary,
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable. Run <code>database/migrations/019_credit_receivables.sql</code>.</div>
                <?php } else { ?>
                <?php if ($tbSqlErr !== '') { ?>
                <div class="alert alert-danger no-print">
                    Trial balance query failed. MySQL said: <code class="small"><?php echo hms_h($tbSqlErr); ?></code>
                </div>
                <?php } ?>
                <?php if ($tbHealthMsg !== '') { ?>
                <div class="alert alert-secondary no-print"><strong>Troubleshooting</strong>
                    <div class="small mt-1 mb-0" style="white-space:pre-wrap"><?php echo nl2br(hms_h($tbHealthMsg), false); ?></div>
                </div>
                <?php } ?>
                <?php if ($tbSiteHint !== '') { ?>
                <div class="alert alert-warning no-print"><?php echo hms_h($tbSiteHint); ?></div>
                <?php } ?>
                <?php if ($tbExtraHint !== '') { ?>
                <div class="alert alert-info no-print"><?php echo hms_h($tbExtraHint); ?></div>
                <?php } ?>
                <?php if ($syncTbFlash !== '') { ?>
                <div class="alert alert-success no-print"><?php echo hms_h($syncTbFlash); ?></div>
                <?php } ?>
                <?php if ($tbEmpty && $canSyncTb) { ?>
                <div class="alert alert-primary no-print border-primary">
                    <strong>No journal data for this period.</strong> Cashier receipts and expenses exist in billing/expense tables until they are posted into <code>tbl_fin_journal_*</code>.
                    <form method="post" class="form-inline d-inline-block ml-md-2 mt-2 mt-md-0">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="sync_tb_gl" value="1">
                        <input type="hidden" name="d1" value="<?php echo hms_h($d1); ?>">
                        <input type="hidden" name="d2" value="<?php echo hms_h($d2); ?>">
                        <button type="submit" class="btn btn-light btn-sm font-weight-bold">Post receipts &amp; expenses to GL for this period</button>
                    </form>
                    <span class="small d-block mt-2 mb-0" style="opacity: 0.9;">Requires financials.write. Same action as Financials → Sync to GL.</span>
                </div>
                <?php } elseif ($tbEmpty && !$canSyncTb) { ?>
                <div class="alert alert-warning no-print">
                    <strong>No GL lines for this period.</strong> Ask a user with <strong>financials.write</strong> to run
                    <a href="financials-sync-gl.php" class="alert-link">Sync to GL</a> for these dates, or open <a href="financials-journal-diagnostics.php" class="alert-link">Journal diagnostics</a>.
                </div>
                <?php } ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body">
                        <div class="row align-items-end">
                        <div class="form-group col-md-3 mb-0">
                            <label for="d1">From</label>
                            <input type="date" class="form-control font-monospace" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <label for="d2">To</label>
                            <input type="date" class="form-control font-monospace" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'TRIAL BALANCE';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'As at' => date('d-m-Y', strtotime($d2)),
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Period from' => date('d-m-Y', strtotime($d1)),
                    'Period to' => date('d-m-Y', strtotime($d2)),
                    'Facility' => '#' . (string) $fid,
                    'Report ref.' => 'TB-' . str_replace('-', '', $d2),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>
                    <p class="hms-fin-section-bar mb-0">Account detail</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Acct code</th>
                                    <th scope="col">Account name</th>
                                    <th scope="col">Category</th>
                                    <th class="hms-ohada-num" scope="col">Debit (Dr)</th>
                                    <th class="hms-ohada-num" scope="col">Credit (Cr)</th>
                                    <th class="hms-ohada-num" scope="col">Period net</th>
                                    <th class="hms-ohada-num" scope="col">Balance as of <?php echo date('d-m-Y', strtotime($d2)); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $byCode = [];
                                foreach ($closingRows as $r) {
                                    $byCode[$r['account_code']] = $r;
                                }
                                $movMap = [];
                                foreach ($periodRows as $r) {
                                    $movMap[$r['account_code']] = $r;
                                }
                                $codes = array_unique(array_merge(array_keys($movMap), array_keys($byCode)));
                                sort($codes, SORT_STRING);
                                $td = 0.0;
                                $tc = 0.0;
                                foreach ($codes as $code) {
                                    $m = $movMap[$code] ?? null;
                                    $cl = $byCode[$code] ?? null;
                                    $md = $m ? (float) $m['total_debit'] : 0.0;
                                    $mc = $m ? (float) $m['total_credit'] : 0.0;
                                    $sb = $cl ? (float) $cl['balance'] : 0.0;
                                    $lbl = (is_array($m) ? (string) ($m['account_label'] ?? '') : '');
                                    if ($lbl === '' && is_array($cl)) {
                                        $lbl = (string) ($cl['account_label'] ?? '');
                                    }
                                    $lbl = hms_fin_report_label_patient_context($lbl);
                                    $sp = $m ? (float) $m['balance'] : 0.0;
                                    $td += $md;
                                    $tc += $mc;
                                    $cat = hms_fin_report_category_from_class(hms_fin_ohada_class_from_code((string) $code));
                                    ?>
                                <tr>
                                    <td><code><?php echo hms_h((string) $code); ?></code></td>
                                    <td><?php echo hms_h($lbl); ?></td>
                                    <td><?php echo hms_h($cat); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $md, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $mc, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $sp, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $sb, false); ?></td>
                                </tr>
                                    <?php
                                }
                                if ($codes === []) {
                                    ?>
                                <tr>
                                    <td colspan="7" class="text-muted py-4 bg-light">
                                        No account rows: the general ledger has no journal lines for site #<?php echo (int) $fid; ?> in <?php echo hms_h($d1); ?>–<?php echo hms_h($d2); ?>.
                                        Use <strong>Post receipts &amp; expenses to GL</strong> above (if shown), or <a href="financials-sync-gl.php">Sync to GL</a>, or the Cameroon 2-year demo seed.
                                    </td>
                                </tr>
                                    <?php
                                }
                                $diff = round($td - $tc, 2);
                                ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="3">Grand totals (movement)</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $td, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $tc, false); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="hms-fin-check-row">
                        Check: total debits = <?php echo hms_format_xaf((float) $td, false); ?> · total credits = <?php echo hms_format_xaf((float) $tc, false); ?>
                        · difference (should be 0) = <?php echo hms_format_xaf((float) $diff, false); ?>
                    </div>
                    <div class="hms-fin-report-sig hms-fin-report-sig--triple px-3">
                        <div class="hms-fin-report-sig__grid hms-fin-report-sig__grid--3">
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Prepared by — name / date</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Reviewed by — name / date</div>
                            </div>
                            <div>
                                <div class="hms-fin-report-sig__line"></div>
                                <div class="hms-fin-report-sig__label">Approved by — name / date</div>
                            </div>
                        </div>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Working paper from journal entries in HMS. Patient-related receivable labels are shown for clarity. Review rounding and open items with finance.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Trial balance</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
