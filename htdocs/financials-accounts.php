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
$byClass = [];
$q = mysqli_query(
    $connection,
    'SELECT id, code, label_en, ohada_class, account_type, is_posting, active FROM tbl_fin_account ORDER BY ohada_class ASC, code ASC'
);
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $c = (int) ($r['ohada_class'] ?? 0);
        if (!isset($byClass[$c])) {
            $byClass[$c] = [];
        }
        $byClass[$c][] = $r;
    }
    mysqli_free_result($q);
}
$classNames = [
    1 => 'Class 1 — Equity and similar',
    2 => 'Class 2 — Fixed assets',
    3 => 'Class 3 — Inventories',
    4 => 'Class 4 — Third parties (receivables / payables)',
    5 => 'Class 5 — Financial accounts',
    6 => 'Class 6 — Expenses',
    7 => 'Class 7 — Revenue',
    8 => 'Class 8 — Other income and expenses',
];
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Chart of accounts', [
                    'subtitle' => 'OHADA-style numeric codes with English labels (Cameroon / OHADA alignment).',
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Chart of accounts', null]],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php ksort($byClass);
                foreach ($byClass as $cn => $rows) { ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white font-weight-bold"><?php echo hms_h((string) ($classNames[$cn] ?? ('Class ' . $cn))); ?></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Code</th><th>Label (English)</th><th>Type</th><th>Posting</th><th>Active</th></tr></thead>
                            <tbody>
                                <?php foreach ($rows as $r) { ?>
                                <tr>
                                    <td><code><?php echo hms_h((string) ($r['code'] ?? '')); ?></code></td>
                                    <td><?php echo hms_h((string) ($r['label_en'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['account_type'] ?? '')); ?></td>
                                    <td><?php echo ((int) ($r['is_posting'] ?? 0)) === 1 ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo ((int) ($r['active'] ?? 0)) === 1 ? 'Yes' : 'No'; ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
