<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';
require_once __DIR__ . '/includes/financials_reports_data.php';
require_once __DIR__ . '/includes/cameroon_money.php';
require_once __DIR__ . '/includes/financials_ohada.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canPostGl = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);

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
$acct = trim((string) ($_GET['acct'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$postErr = '';
if ($finOk && $canPostGl && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['post_receipts_to_gl'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $postErr = 'Invalid security token.';
    } else {
        $pd1 = trim((string) ($_POST['d1'] ?? ''));
        $pd2 = trim((string) ($_POST['d2'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd1) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd2)) {
            $postErr = 'Invalid date range.';
        } elseif (function_exists('hms_fin_backfill_receipt_journals_for_date_range')) {
            $nr = hms_fin_backfill_receipt_journals_for_date_range($connection, $fid, $pd1, $pd2, 5000);
            $_SESSION['hms_fin_sync_journal_err'] = (string) ($nr['first_error'] ?? '');
            if ($_SESSION['hms_fin_sync_journal_err'] === '' && function_exists('hms_fin_journal_post_last_error')) {
                $_SESSION['hms_fin_sync_journal_err'] = hms_fin_journal_post_last_error();
            }
            $acctPost = trim((string) ($_POST['acct'] ?? ''));
            $q = [
                'd1' => $pd1,
                'd2' => $pd2,
                'posted' => '1',
                'proc' => (string) (int) ($nr['processed'] ?? 0),
                'ins' => (string) (int) ($nr['inserted'] ?? 0),
                'dup' => (string) (int) ($nr['duplicate'] ?? 0),
                'fail' => (string) (int) ($nr['failed'] ?? 0),
            ];
            if ($acctPost !== '') {
                $q['acct'] = $acctPost;
            }
            header('Location: financials-general-ledger.php?' . http_build_query($q));
            exit;
        }
    }
}

$opening = [];
$lines = [];
$glSqlErr = '';
if ($finOk) {
    if (function_exists('hms_fin_reports_clear_sql_error')) {
        hms_fin_reports_clear_sql_error();
    }
    $opening = hms_fin_opening_balances_before($connection, $fid, $d1);
    $lines = hms_fin_gl_lines($connection, $fid, $d1, $d2, $acct !== '' ? $acct : null);
    if (function_exists('hms_fin_reports_last_sql_error')) {
        $glSqlErr = hms_fin_reports_last_sql_error();
    }
}

$byAcct = [];
foreach ($lines as $ln) {
    $c = $ln['account_code'];
    if (!isset($byAcct[$c])) {
        $byAcct[$c] = [];
    }
    $byAcct[$c][] = $ln;
}
ksort($byAcct, SORT_STRING);

$opsRecGl = hms_fin_ops_fiscal_receipts_period($connection, $fid, $d1, $d2);
$glEmptyVsBilling = $finOk && count($lines) === 0 && ($opsRecGl['total'] ?? 0) > 0.005;
$glHealthMsg = '';
if ($finOk && $acct === '' && count($lines) === 0 && function_exists('hms_fin_journal_health_snapshot')) {
    $glHealthSnap = hms_fin_journal_health_snapshot($connection, $fid, $d1, $d2);
    $glHealthMsg = hms_fin_journal_health_hint_message($glHealthSnap, $d1, $d2);
}
$glSiteHint = '';
$glExtraHint = '';
if ($finOk && count($lines) === 0 && $glSqlErr === '' && $acct === '' && $glHealthMsg === '') {
    if (function_exists('hms_fin_gl_empty_site_hint')) {
        $glSiteHint = hms_fin_gl_empty_site_hint($connection, $fid, $d1, $d2);
    }
    if ($glSiteHint === '') {
        if (function_exists('hms_fin_gl_empty_headers_without_lines_hint')) {
            $glExtraHint = hms_fin_gl_empty_headers_without_lines_hint($connection, $fid, $d1, $d2);
        }
        if ($glExtraHint === '' && function_exists('hms_fin_gl_empty_no_journals_anywhere_hint')) {
            $glExtraHint = hms_fin_gl_empty_no_journals_anywhere_hint($connection, $d1, $d2);
        }
    }
}
$postedOk = isset($_GET['posted']) && (string) $_GET['posted'] === '1';
$postProc = isset($_GET['proc']) && is_numeric($_GET['proc']) ? (int) $_GET['proc'] : null;
$postIns = isset($_GET['ins']) && is_numeric($_GET['ins']) ? (int) $_GET['ins'] : null;
$postDup = isset($_GET['dup']) && is_numeric($_GET['dup']) ? (int) $_GET['dup'] : null;
$postFail = isset($_GET['fail']) && is_numeric($_GET['fail']) ? (int) $_GET['fail'] : null;
$syncJournalErr = '';
if (!empty($_SESSION['hms_fin_sync_journal_err'])) {
    $syncJournalErr = (string) $_SESSION['hms_fin_sync_journal_err'];
    unset($_SESSION['hms_fin_sync_journal_err']);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('General ledger', [
                    'subtitle' => 'Solidarity of Hearts Hospital — journal detail by account.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['General ledger', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable. Run <code>database/migrations/019_credit_receivables.sql</code>.</div>
                <?php } else { ?>
                <?php if ($glSqlErr !== '') { ?>
                <div class="alert alert-danger no-print">
                    Could not load general ledger data. MySQL said: <code class="small"><?php echo hms_h($glSqlErr); ?></code>
                </div>
                <?php } ?>
                <?php if ($glHealthMsg !== '') { ?>
                <div class="alert alert-secondary no-print"><strong>Troubleshooting</strong>
                    <div class="small mt-1 mb-0" style="white-space:pre-wrap"><?php echo nl2br(hms_h($glHealthMsg), false); ?></div>
                </div>
                <?php } ?>
                <?php if ($glSiteHint !== '') { ?>
                <div class="alert alert-warning no-print"><?php echo hms_h($glSiteHint); ?></div>
                <?php } ?>
                <?php if ($glExtraHint !== '') { ?>
                <div class="alert alert-info no-print"><?php echo hms_h($glExtraHint); ?></div>
                <?php } ?>
                <?php if ($postedOk && $postProc !== null && $postIns !== null && $postDup !== null && $postFail !== null) { ?>
                <?php if ($postFail > 0) { ?>
                <div class="alert alert-danger no-print">
                    Receipt sync finished with <strong><?php echo (int) $postFail; ?></strong> failure(s). New lines inserted: <?php echo (int) $postIns; ?>.
                    <?php if ($syncJournalErr !== '') { ?>
                    <br><strong>Last error:</strong> <code class="small"><?php echo hms_h($syncJournalErr); ?></code>
                    <?php } else { ?>
                    Check the server PHP error log for <code>hms_fin_journal_post</code>. Ensure <code>tbl_fin_journal_*</code> exists and MySQL allows INSERT (user privileges).
                    <?php } ?>
                </div>
                <?php } elseif ($postIns > 0) { ?>
                <div class="alert alert-success no-print">
                    <strong><?php echo (int) $postIns; ?></strong> new journal entr<?php echo $postIns === 1 ? 'y' : 'ies'; ?> posted
                    (<?php echo (int) $postProc; ?> receipt row(s) scanned; <?php echo (int) $postDup; ?> already linked).
                    Click <strong>Refresh</strong> above if lines do not show yet.
                </div>
                <?php } else { ?>
                <div class="alert alert-warning no-print">
                    No new journal lines were inserted (<?php echo (int) $postProc; ?> receipt row(s) scanned; <?php echo (int) $postDup; ?> already in the GL; <?php echo (int) $postFail; ?> failed).
                    If the ledger is still empty, try <a href="financials-sync-gl.php" class="alert-link">Sync to GL</a> or confirm receipts use the same facility as your session.
                </div>
                <?php } ?>
                <?php } ?>
                <?php if ($postErr !== '') { ?>
                <div class="alert alert-danger no-print"><?php echo hms_h($postErr); ?></div>
                <?php } ?>
                <?php if ($glEmptyVsBilling) { ?>
                <div class="alert alert-info no-print">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                        <div class="mb-2 mb-md-0 pr-md-3">
                            No journal lines in this period, but billing shows <?php echo hms_format_xaf((float) ($opsRecGl['total'] ?? 0)); ?> in fiscal receipts.
                            <?php if ($canPostGl) { ?>
                            Use the button to post <strong>only receipts dated in this From–To range</strong> (faster than the global sync when you have old history).
                            <?php } else { ?>
                            Ask a user with <strong>financials.write</strong> to run <a href="financials-sync-gl.php" class="alert-link">Sync to GL</a> or post from a role that can edit journals.
                            <?php } ?>
                        </div>
                        <?php if ($canPostGl) { ?>
                        <form method="post" class="flex-shrink-0">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="post_receipts_to_gl" value="1">
                            <input type="hidden" name="d1" value="<?php echo hms_h($d1); ?>">
                            <input type="hidden" name="d2" value="<?php echo hms_h($d2); ?>">
                            <input type="hidden" name="acct" value="<?php echo hms_h($acct); ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Post receipts for this period</button>
                        </form>
                        <?php } ?>
                    </div>
                    <?php if ($canPostGl) { ?>
                    <p class="small mb-0 mt-2"><a href="financials-sync-gl.php" class="alert-link">Full sync tool</a> — post older batches or expenses by batch size.</p>
                    <?php } ?>
                </div>
                <?php } ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body">
                        <div class="row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="d1">From</label>
                            <input type="date" class="form-control font-monospace" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="d2">To</label>
                            <input type="date" class="form-control font-monospace" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <label for="acct">Account code prefix</label>
                            <input type="text" class="form-control" id="acct" name="acct" value="<?php echo hms_h($acct); ?>" placeholder="e.g. 521 or empty = all">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                        </div>
                    </div>
                </form>

                <?php
                $hms_fin_report_document_title = 'GENERAL LEDGER REPORT';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Period' => date('d-m-Y', strtotime($d1)) . ' — ' . date('d-m-Y', strtotime($d2)),
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Account filter' => $acct !== '' ? $acct . '*' : 'All accounts',
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('d-m-Y'),
                    'Report ref.' => 'GL-' . str_replace('-', '', $d2),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <?php foreach ($byAcct as $code => $rows) {
                        $ob = (float) ($opening[$code] ?? 0.0);
                        $label = $rows[0]['account_label'] ?? '';
                        $label = hms_fin_report_label_patient_context($label);
                        $run = $ob;
                        ?>
                    <p class="hms-fin-section-bar mb-0">Account <?php echo hms_h((string) $code); ?> — <?php echo hms_h($label !== '' ? $label : '—'); ?></p>
                    <div class="px-3 py-2 bg-light small border-bottom">
                        <strong>Opening balance:</strong>
                        <?php echo hms_format_xaf($ob, false); ?>
                        (before <?php echo date('d-m-Y', strtotime($d1)); ?>)
                    </div>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-4">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Ref.</th>
                                    <th>Description</th>
                                    <th class="hms-ohada-num">Debit</th>
                                    <th class="hms-ohada-num">Credit</th>
                                    <th class="hms-ohada-num">Running balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r) {
                                    $run += (float) $r['debit'] - (float) $r['credit'];
                                    $nar = hms_fin_report_label_patient_context((string) $r['narration']);
                                    ?>
                                <tr>
                                    <td><?php echo date('d-m-Y', strtotime($r['entry_date'])); ?></td>
                                    <td><code><?php echo hms_h($r['reference']); ?></code></td>
                                    <td><?php echo hms_h($nar); ?> <span class="text-muted">(<?php echo hms_h($r['source_type']); ?>)</span></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['debit'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['credit'], false); ?></td>
                                    <td class="hms-ohada-num font-weight-bold"><?php echo hms_format_xaf($run, false); ?></td>
                                </tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="3">Closing balance</td>
                                    <td colspan="2"></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($run, false); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>

                    <?php if ($byAcct === []) { ?>
                    <p class="text-muted px-3">No journal lines in this period<?php echo $acct !== '' ? ' for accounts starting with ' . hms_h($acct) : ''; ?>.</p>
                    <?php } ?>

                    <p class="hms-ohada-disclaimer mb-0">Running balance is debit − credit per line. Patient wording is applied to narrations where relevant.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>General ledger</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
