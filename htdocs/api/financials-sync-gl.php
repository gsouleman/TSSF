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
$canRun = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);
$msg = '';
$receiptBatch = 500;
$expenseBatch = 500;
$rfD1 = trim((string) ($_POST['rf_d1'] ?? ''));
$rfD2 = trim((string) ($_POST['rf_d2'] ?? ''));
$exD1 = trim((string) ($_POST['ex_d1'] ?? ''));
$exD2 = trim((string) ($_POST['ex_d2'] ?? ''));

if ($rfD1 === '' && $rfD2 === '') {
    $rfD1 = date('Y-m-01');
    $rfD2 = date('Y-m-d');
}
if ($exD1 === '' && $exD2 === '') {
    $exD1 = date('Y-m-01');
    $exD2 = date('Y-m-d');
}

if ($finOk && $canRun && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
        $receiptBatch = max(50, min(2000, (int) ($_POST['receipt_batch'] ?? 500)));
        $expenseBatch = max(50, min(2000, (int) ($_POST['expense_batch'] ?? 500)));
        $rfD1 = trim((string) ($_POST['rf_d1'] ?? ''));
        $rfD2 = trim((string) ($_POST['rf_d2'] ?? ''));
        $exD1 = trim((string) ($_POST['ex_d1'] ?? ''));
        $exD2 = trim((string) ($_POST['ex_d2'] ?? ''));

        $useRfRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rfD1) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rfD2);
        $useExRange = preg_match('/^\d{4}-\d{2}-\d{2}$/', $exD1) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exD2);

        if ($useRfRange && function_exists('hms_fin_backfill_receipt_journals_for_date_range')) {
            $r = hms_fin_backfill_receipt_journals_for_date_range($connection, $fid, $rfD1, $rfD2, $receiptBatch);
        } else {
            $r = function_exists('hms_fin_backfill_receipt_journals') ? hms_fin_backfill_receipt_journals($connection, $fid, $receiptBatch) : ['processed' => 0];
        }
        if ($useExRange && function_exists('hms_fin_backfill_expense_journals_for_date_range')) {
            $e = hms_fin_backfill_expense_journals_for_date_range($connection, $fid, $exD1, $exD2, $expenseBatch);
        } else {
            $e = function_exists('hms_fin_backfill_expense_journals') ? hms_fin_backfill_expense_journals($connection, $fid, $expenseBatch) : ['processed' => 0];
        }
        $msg = 'Receipts: ' . (int) ($r['processed'] ?? 0) . ' row(s) scanned — '
            . (int) ($r['inserted'] ?? 0) . ' new journal(s), ' . (int) ($r['duplicate'] ?? 0) . ' already linked, '
            . (int) ($r['failed'] ?? 0) . ' failed. Expenses: '
            . (int) ($e['processed'] ?? 0) . ' scanned — '
            . (int) ($e['inserted'] ?? 0) . ' new, ' . (int) ($e['duplicate'] ?? 0) . ' duplicate, '
            . (int) ($e['failed'] ?? 0) . ' failed. Re-run if the batch limit cut off rows.';
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Sync operational data to general ledger', [
                    'subtitle' => 'Solidarity of Hearts Hospital — post historical fiscal receipts and expenses into the GL so reports show real figures.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Sync GL', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">Journal tables are not installed. Run the credit / GL migration first.</div>
                <?php } else { ?>
                <?php if (!$canRun) { ?>
                <div class="alert alert-warning">You need <strong>financials.write</strong> (or journal/billing write) permission to run the sync. Ask an administrator to grant access or run this for you.</div>
                <?php } ?>
                <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <p class="mb-2">New cashier and billing activity is posted automatically:</p>
                        <ul class="mb-3">
                            <li><strong>Fiscal receipts</strong> (consultation, charges, pharmacy, lab, etc.) → DR cash/bank · CR revenue (706000).</li>
                            <li><strong>Credit collections</strong> → DR cash/bank · CR receivable (unchanged).</li>
                            <li><strong>New expenses</strong> (Expense Management) → DR operating expense (601000) · CR cash/bank.</li>
                        </ul>
                        <p class="text-muted small mb-3">Receipts and expenses are filtered by <strong>date range</strong> (receipts: document date; expenses: expense date). Increase batch size if needed. Duplicates are skipped.</p>
                        <form method="post" class="mb-0">
                            <?php echo hms_csrf_field(); ?>
                            <div class="form-row">
                                <div class="form-group col-md-3 mb-2">
                                    <label for="rf_d1">Receipts from</label>
                                    <input type="date" class="form-control" name="rf_d1" id="rf_d1" value="<?php echo hms_h($rfD1); ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group col-md-3 mb-2">
                                    <label for="rf_d2">Receipts to</label>
                                    <input type="date" class="form-control" name="rf_d2" id="rf_d2" value="<?php echo hms_h($rfD2); ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group col-md-2 mb-2">
                                    <label for="receipt_batch">Receipt batch max</label>
                                    <input type="number" class="form-control" name="receipt_batch" id="receipt_batch" min="50" max="2000" value="<?php echo (int) $receiptBatch; ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-3 mb-2">
                                    <label for="ex_d1">Expenses from</label>
                                    <input type="date" class="form-control" name="ex_d1" id="ex_d1" value="<?php echo hms_h($exD1); ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group col-md-3 mb-2">
                                    <label for="ex_d2">Expenses to</label>
                                    <input type="date" class="form-control" name="ex_d2" id="ex_d2" value="<?php echo hms_h($exD2); ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group col-md-2 mb-2">
                                    <label for="expense_batch">Expense batch max</label>
                                    <input type="number" class="form-control" name="expense_batch" id="expense_batch" min="50" max="2000" value="<?php echo (int) $expenseBatch; ?>" <?php echo $canRun ? '' : 'disabled'; ?>>
                                </div>
                                <div class="form-group col-md-4 mb-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary" <?php echo $canRun ? '' : 'disabled'; ?>>Run sync</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
