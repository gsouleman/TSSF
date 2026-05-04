<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_import.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canWrite = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);

$msg = '';
$err = '';

if ($finOk && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['import_csv'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $raw = (string) ($_POST['csv'] ?? '');
        $parsed = hms_fin_parse_journal_csv($raw);
        if (!$parsed['ok']) {
            $err = implode(' ', $parsed['errors']);
        } else {
            $ok = 0;
            foreach ($parsed['batches'] as $batch) {
                $lines = $batch['lines'];
                $nar = (string) ($batch['narration'] ?? '');
                $ref = (string) ($batch['reference'] ?? '');
                $d = (string) ($batch['date'] ?? '');
                if (hms_fin_journal_post_manual($connection, $fid, $d, $ref, $nar, $uid, $lines)) {
                    $ok++;
                }
            }
            $msg = $ok . ' ' . ($ok === 1 ? 'journal entry' : 'journal entries') . ' imported.';
        }
    }
}

$recent = $finOk ? hms_fin_journal_recent_headers($connection, $fid, 60) : [];

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Journal loader', [
                    'subtitle' => 'Solidarity of Hearts Hospital — import balanced journal batches into the general ledger.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Journal loader', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">General ledger unavailable.</div>
                <?php } elseif (!$canWrite) { ?>
                <div class="alert alert-danger">Permission required: <code>financials.write</code> or a billing-eligible role.</div>
                <?php } else { ?>
                <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
                <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>

                <?php
                $hms_fin_report_document_title = 'JOURNAL IMPORT';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Source' => 'manual_import',
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Report date' => date('Y-m-d'),
                    'Facility' => '#' . (string) $fid,
                    'Permission' => 'financials.write',
                    'Report ref.' => 'JNL-IMP',
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp mb-4">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>
                    <div class="px-3 pb-3">
                        <p class="mb-0 text-muted small">Paste balanced CSV lines below. Each batch must tie by date and reference; account labels may use <strong>Patient</strong> for receivables (not &quot;customer&quot;).</p>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6 font-weight-bold">CSV format (one row per journal line)</h2>
                        <p class="text-muted small mb-2"><strong>7 columns</strong> in the order below. Separator: <strong>comma</strong> or <strong>semicolon</strong> (auto-detected per line). Rows with the same <strong>date</strong> and <strong>reference</strong> form one entry; total debit must equal total credit for that group.</p>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered bg-white small mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Column</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>1</td><td><code>date</code></td><td>YYYY-MM-DD (e.g. <code>2026-04-15</code>)</td></tr>
                                    <tr><td>2</td><td><code>reference</code></td><td>Entry identifier (same on every line of the same journal entry)</td></tr>
                                    <tr><td>3</td><td><code>narration</code></td><td>Entry description (narration)</td></tr>
                                    <tr><td>4</td><td><code>account</code></td><td>Chart of accounts code (e.g. <code>601000</code>)</td></tr>
                                    <tr><td>5</td><td><code>account label</code></td><td>Account label</td></tr>
                                    <tr><td>6</td><td><code>debit</code></td><td>Amount or <code>0</code></td></tr>
                                    <tr><td>7</td><td><code>credit</code></td><td>Amount or <code>0</code> (only one of columns 6–7 non-zero per line)</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mb-2">An optional <strong>header row</strong> (column titles) on the first line is accepted and skipped when detected.</p>
                        <pre class="bg-light p-2 small rounded mb-0">2026-04-15,JNL-01,Medical supplies purchase,601000,Medical supplies,150000,0
2026-04-15,JNL-01,Medical supplies purchase,521000,Bank,0,150000</pre>
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="import_csv" value="1">
                            <div class="form-group">
                                <label for="csv">Paste CSV or text</label>
                                <textarea class="form-control font-monospace" id="csv" name="csv" rows="12" placeholder="Paste here..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Import journal entries</button>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 font-weight-bold mb-3">Recent journal entries</h2>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Ref.</th>
                                        <th>Source</th>
                                        <th class="text-right">Lines</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r) { ?>
                                    <tr>
                                        <td><?php echo hms_h($r['entry_date']); ?></td>
                                        <td><?php echo hms_h($r['reference']); ?></td>
                                        <td><code><?php echo hms_h($r['source_type']); ?></code></td>
                                        <td class="text-right"><?php echo (int) $r['line_count']; ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if ($recent === []) { ?>
                                    <tr><td colspan="4" class="text-muted">No journal entries yet.</td></tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
