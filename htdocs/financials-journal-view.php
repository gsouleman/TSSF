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
$id = hms_fin_get_query_positive_int('id', 0);
$jq = mysqli_query(
    $connection,
    'SELECT * FROM tbl_fin_journal WHERE id = ' . (int) $id . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
);
$j = $jq ? mysqli_fetch_assoc($jq) : null;
if ($jq) {
    mysqli_free_result($jq);
}
if (!$j) {
    header('Location: financials-journal.php');
    exit;
}
$lines = [];
$lq = mysqli_query(
    $connection,
    'SELECT l.*, a.code AS acode, a.label_en AS alabel, c.code AS ccode
     FROM tbl_fin_journal_line l
     INNER JOIN tbl_fin_account a ON a.id = l.account_id
     LEFT JOIN tbl_fin_cost_center c ON c.id = l.cost_center_id
     WHERE l.journal_id = ' . (int) $id . ' ORDER BY l.id ASC'
);
if ($lq) {
    while ($r = mysqli_fetch_assoc($lq)) {
        $lines[] = $r;
    }
    mysqli_free_result($lq);
}
$flash = isset($_SESSION['fin_flash']) ? (string) $_SESSION['fin_flash'] : '';
unset($_SESSION['fin_flash']);
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Journal ' . (string) ($j['journal_no'] ?? ''), [
                    'subtitle' => (string) ($j['description'] ?? ''),
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Journal', 'financials-journal.php'], ['View', null]],
                    'back' => 'financials-journal.php',
                ]);
                ?>
                <?php if ($flash !== '') { ?><div class="alert alert-success"><?php echo hms_h($flash); ?></div><?php } ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body row small">
                        <div class="col-md-3"><strong>Date</strong><br><?php echo hms_h((string) ($j['journal_date'] ?? '')); ?></div>
                        <div class="col-md-3"><strong>Reference</strong><br><?php echo hms_h((string) ($j['reference'] ?? '')); ?></div>
                        <div class="col-md-3"><strong>Status</strong><br><?php echo hms_h((string) ($j['status'] ?? '')); ?></div>
                        <div class="col-md-3"><strong>Source</strong><br><?php echo hms_h((string) ($j['source'] ?? '')); ?></div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr><th>Account</th><th>Cost centre</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th>Memo</th></tr></thead>
                            <tbody>
                                <?php
                                $td = 0;
                                $tc = 0;
                                foreach ($lines as $ln) {
                                    $td += (int) ($ln['debit'] ?? 0);
                                    $tc += (int) ($ln['credit'] ?? 0);
                                    ?>
                                <tr>
                                    <td><code><?php echo hms_h((string) ($ln['acode'] ?? '')); ?></code> <?php echo hms_h((string) ($ln['alabel'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($ln['ccode'] ?? '—')); ?></td>
                                    <td class="text-right"><?php echo (int) ($ln['debit'] ?? 0) > 0 ? hms_fin_format_xaf_int((int) $ln['debit']) : '—'; ?></td>
                                    <td class="text-right"><?php echo (int) ($ln['credit'] ?? 0) > 0 ? hms_fin_format_xaf_int((int) $ln['credit']) : '—'; ?></td>
                                    <td><?php echo hms_h((string) ($ln['line_memo'] ?? '')); ?></td>
                                </tr>
                                <?php } ?>
                                <tr class="font-weight-bold">
                                    <td colspan="2">Totals</td>
                                    <td class="text-right"><?php echo hms_fin_format_xaf_int($td); ?></td>
                                    <td class="text-right"><?php echo hms_fin_format_xaf_int($tc); ?></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
