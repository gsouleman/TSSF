<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canRepair = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);

$d1 = trim((string) ($_GET['d1'] ?? date('Y-m-01')));
$d2 = trim((string) ($_GET['d2'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$repairMsg = '';
$repairOk = false;
if ($finOk && $canRepair && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['repair_journal_schema'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $repairMsg = 'Invalid security token.';
    } else {
        $repairOk = true;
        if (function_exists('hms_fin_journal_line_schema_ensure') && !hms_fin_journal_line_schema_ensure($connection)) {
            $repairMsg .= ' account_code/label ensure failed.';
            $repairOk = false;
        }
        if (function_exists('hms_fin_journal_line_fk_ensure') && !hms_fin_journal_line_fk_ensure($connection)) {
            $repairMsg .= ' FK to tbl_fin_journal_header failed.';
            $repairOk = false;
        }
        if (function_exists('hms_fin_journal_line_account_id_ensure') && !hms_fin_journal_line_account_id_ensure($connection)) {
            $repairMsg .= ' account_id column ensure failed.';
            $repairOk = false;
        }
        if ($repairOk && $repairMsg === '') {
            $repairMsg = 'Journal line schema and foreign keys were checked/repaired. Re-open Trial balance / General ledger.';
        }
    }
}

$snap = [];
if ($finOk && function_exists('hms_fin_journal_health_snapshot')) {
    $snap = hms_fin_journal_health_snapshot($connection, $fid, $d1, $d2);
}
$hint = '';
if ($snap !== [] && function_exists('hms_fin_journal_health_hint_message')) {
    $hint = hms_fin_journal_health_hint_message($snap, $d1, $d2);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Journal / GL diagnostics', [
                    'subtitle' => 'Solidarity of Hearts Hospital — verify facility, billing, and journal counts for the selected period.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Journal diagnostics', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">Journal tables missing. Run <code>database/migrations/019_credit_receivables.sql</code>.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-3 mb-0">
                            <label for="d1">From</label>
                            <input type="date" class="form-control" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <label for="d2">To</label>
                            <input type="date" class="form-control" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh counts</button>
                        </div>
                    </div>
                </form>

                <?php if ($repairMsg !== '') { ?>
                <div class="alert alert-<?php echo $repairOk ? 'success' : 'warning'; ?>"><?php echo hms_h($repairMsg); ?></div>
                <?php } ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Active site</h5>
                        <ul class="mb-0">
                            <li><code>hms_current_facility_id()</code> = <?php echo (int) $fid; ?></li>
                            <li><code>HMS_FIXED_FACILITY_ID</code> = <?php echo defined('HMS_FIXED_FACILITY_ID') ? (int) HMS_FIXED_FACILITY_ID : '(not defined)'; ?></li>
                        </ul>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Counts for <?php echo hms_h($d1); ?> — <?php echo hms_h($d2); ?></h5>
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><th scope="row">tbl_facility row for this id</th><td><?php echo !empty($snap['facility_row_ok']) ? 'yes' : 'no'; ?></td></tr>
                                <tr><th scope="row">Journal headers (this site, all time)</th><td><?php echo (int) ($snap['headers_facility_total'] ?? 0); ?></td></tr>
                                <tr><th scope="row">Journal entry_date span (this site, all time)</th><td><?php
                                    $jmin = trim((string) ($snap['journal_entry_date_min'] ?? ''));
                                    $jmax = trim((string) ($snap['journal_entry_date_max'] ?? ''));
                                    echo $jmin !== '' && $jmax !== '' ? hms_h($jmin . ' — ' . $jmax) : '—';
                                ?></td></tr>
                                <tr><th scope="row">Journal headers (this site, period)</th><td><?php echo (int) ($snap['headers_facility_period'] ?? 0); ?></td></tr>
                                <tr><th scope="row">Journal lines joined (this site, period)</th><td><?php echo (int) ($snap['lines_facility_period'] ?? 0); ?></td></tr>
                                <tr><th scope="row">Journal headers (any site, period)</th><td><?php echo (int) ($snap['headers_any_period'] ?? 0); ?></td></tr>
                                <tr><th scope="row">By facility (period)</th><td><?php echo hms_h((string) ($snap['facility_period_breakdown'] ?: '—')); ?></td></tr>
                                <tr><th scope="row">Fiscal receipt docs (this site, period)</th><td><?php echo !empty($snap['billing_ok']) ? (int) ($snap['receipt_docs_period'] ?? 0) : 'N/A (billing tables)'; ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($hint !== '') { ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Interpretation</h5>
                        <div class="small" style="white-space:pre-wrap"><?php echo nl2br(hms_h($hint), false); ?></div>
                    </div>
                </div>
                <?php } ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="card-title">Repair (admin)</h5>
                        <p class="text-muted small mb-2">Runs the same schema helpers used when posting journals: add missing columns, point <code>journal_id</code> at <code>tbl_fin_journal_header</code>, relax <code>account_id</code> if needed.</p>
                        <?php if (!$canRepair) { ?>
                        <p class="mb-0 text-muted">You need <strong>financials.write</strong> to run repair.</p>
                        <?php } else { ?>
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="repair_journal_schema" value="1">
                            <button type="submit" class="btn btn-outline-primary">Run journal schema repair</button>
                        </form>
                        <?php } ?>
                    </div>
                </div>

                <p class="small text-muted mb-0">Next: <a href="financials-sync-gl.php">Sync to GL</a> · <a href="financials-trial-balance.php">Trial balance</a> · <a href="financials-general-ledger.php">General ledger</a></p>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
