<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');

$fid = hms_current_facility_id();
$ok = hms_billing_document_tables_ok($connection);
$fType = (string) ($_GET['t'] ?? 'all');
if (!in_array($fType, ['all', 'receipt', 'invoice'], true)) {
    $fType = 'all';
}
$qRaw = trim((string) ($_GET['q'] ?? ''));

$rows = [];
if ($ok) {
    $w = ['d.facility_id = ' . (int) $fid];
    if ($fType !== 'all') {
        $w[] = "d.doc_type = '" . mysqli_real_escape_string($connection, $fType) . "'";
    }
    if ($qRaw !== '') {
        $esc = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(d.doc_number LIKE '%{$esc}%' OR d.payer_snapshot LIKE '%{$esc}%' OR d.company_snapshot LIKE '%{$esc}%')";
    }
    $sql = 'SELECT d.* FROM tbl_billing_document d WHERE ' . implode(' AND ', $w) . ' ORDER BY d.id DESC LIMIT 200';
    $rq = mysqli_query($connection, $sql);
    while ($rq && $rr = mysqli_fetch_assoc($rq)) {
        $rows[] = $rr;
    }
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Receipts & invoices', [
                'subtitle' => 'Fiscal documents issued from consultations, charges, transactions, pharmacy, and lab fees. Invoices are addressed to companies.',
                'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Receipts & invoices', '']],
                'primary' => hms_can($connection, 'billing.write')
                    ? ['label' => 'New company invoice', 'url' => 'invoice-create.php', 'icon' => 'fa-file-text-o']
                    : null,
                'secondary' => [
                    ['label' => 'Companies', 'url' => 'billing-companies.php', 'icon' => 'fa-building'],
                    ['label' => 'Billing workspace', 'url' => 'billing-payments.php', 'icon' => 'fa-credit-card'],
                ],
            ]);
            ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>hms/database/migrations/011_receipt_invoice_module.sql</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-2">
                    <form method="get" class="form-row align-items-end">
                        <div class="form-group col-md-4 mb-0">
                            <label class="small text-muted">Type</label>
                            <select name="t" class="form-control" onchange="this.form.submit()">
                                <option value="all"<?php echo $fType === 'all' ? ' selected' : ''; ?>>All</option>
                                <option value="receipt"<?php echo $fType === 'receipt' ? ' selected' : ''; ?>>Receipts</option>
                                <option value="invoice"<?php echo $fType === 'invoice' ? ' selected' : ''; ?>>Invoices</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6 mb-0">
                            <label class="small text-muted">Search number / name</label>
                            <input type="search" name="q" class="form-control" value="<?php echo hms_h($qRaw); ?>" placeholder="Doc # or payer / company">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <button type="submit" class="btn btn-primary btn-block">Filter</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Number</th><th>Type</th><th>Party</th><th>Source</th><th>Payment</th><th class="text-right">Total</th><th>Date</th><th></th></tr></thead>
                            <tbody>
                            <?php if ($rows === []) { ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No documents yet. They are created when payments are recorded (or from <a href="invoice-create.php">New company invoice</a>).</td></tr>
                            <?php } ?>
                            <?php foreach ($rows as $r) {
                                $did = (int) $r['id'];
                                $party = $r['doc_type'] === 'invoice'
                                    ? (string) ($r['company_snapshot'] ?? '—')
                                    : (string) ($r['payer_snapshot'] ?? '—');
                                $src = (string) ($r['source_module'] ?? '') . ' #' . (int) ($r['source_pk'] ?? 0);
                                ?>
                            <tr>
                                <td class="text-nowrap font-weight-bold"><?php echo hms_h((string) ($r['doc_number'] ?? '')); ?></td>
                                <td><?php echo hms_h((string) ($r['doc_type'] ?? '')); ?></td>
                                <td><?php echo hms_h($party); ?></td>
                                <td class="small text-muted"><?php echo hms_h($src); ?></td>
                                <td class="small text-nowrap"><?php echo hms_h(trim((string) ($r['payment_method'] ?? '')) !== '' ? (string) $r['payment_method'] : '—'); ?></td>
                                <td class="text-right text-nowrap"><?php echo hms_h(hms_format_xaf((float) ($r['total_amount'] ?? 0))); ?></td>
                                <td class="small text-nowrap"><?php echo hms_h((string) ($r['created_at'] ?? '')); ?></td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="billing-document-pdf.php?id=<?php echo $did; ?>">PDF</a>
                                    <a class="btn btn-sm btn-link" target="_blank" href="billing-document-print.php?id=<?php echo $did; ?>">HTML</a>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
