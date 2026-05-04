<?php
/** No strict_types: shared-host compatibility (mysqli + hms_h). */

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_fin_require_mysqli($connection);
hms_fin_require($connection, 'financials.read');
if (!hms_financials_ready($connection)) {
    header('Location: financials.php');
    exit;
}
$fid = hms_current_facility_id();
$from = hms_fin_get_query_date('from', date('Y-m-01'));
$to = hms_fin_get_query_date('to', date('Y-m-t'));
$rows = [];
$q = mysqli_query(
    $connection,
    'SELECT j.id, j.journal_no, j.journal_date, j.description, j.reference, j.status,
            (SELECT COALESCE(SUM(debit),0) FROM tbl_fin_journal_line WHERE journal_id = j.id) AS total_dr
     FROM tbl_fin_journal j
     WHERE j.facility_id = ' . (int) $fid . " AND j.journal_date >= '" . mysqli_real_escape_string($connection, $from) . "' AND j.journal_date <= '" . mysqli_real_escape_string($connection, $to) . "'
     ORDER BY j.journal_date DESC, j.id DESC LIMIT 200"
);
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
    mysqli_free_result($q);
}
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Financials — Journal', [
                    'subtitle' => 'Posted journals for this site (XAF, double entry).',
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Journal', null]],
                    'back' => 'financials.php',
                    'primary' => hms_can($connection, 'financials.write')
                        ? ['label' => 'New journal entry', 'url' => 'financials-journal-new.php', 'icon' => 'fa-plus']
                        : null,
                ]);
                ?>
                <form method="get" class="card border-0 shadow-sm mb-3">
                    <div class="card-body row align-items-end">
                        <div class="col-md-3">
                            <label class="small font-weight-bold text-muted">From</label>
                            <input type="date" name="from" class="form-control" value="<?php echo hms_h($from); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="small font-weight-bold text-muted">To</label>
                            <input type="date" name="to" class="form-control" value="<?php echo hms_h($to); ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-outline-primary btn-sm font-weight-bold">Filter</button>
                        </div>
                    </div>
                </form>
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-light"><tr><th>No.</th><th>Date</th><th>Description</th><th class="text-right">Debits (XAF)</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td><code><?php echo hms_h((string) ($r['journal_no'] ?? '')); ?></code></td>
                                    <td><?php echo hms_h((string) ($r['journal_date'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['description'] ?? '')); ?></td>
                                    <td class="text-right"><?php echo hms_fin_format_xaf_int((int) ($r['total_dr'] ?? 0)); ?></td>
                                    <td><a class="btn btn-sm btn-outline-secondary" href="financials-journal-view.php?id=<?php echo (int) ($r['id'] ?? 0); ?>">View</a></td>
                                </tr>
                                <?php } ?>
                                <?php if ($rows === []) { ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No journals in this period.</td></tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
