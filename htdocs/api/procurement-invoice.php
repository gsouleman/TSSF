<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/purchase_order_helpers.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

hms_procurement_require_read($connection);
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ready = hms_procurement_tables_ok($connection) && hms_po_tables_ok($connection);
$poId = (int) ($_GET['po'] ?? 0);
$flash = isset($_SESSION['proc_flash']) ? (string) $_SESSION['proc_flash'] : '';
unset($_SESSION['proc_flash']);

if ($ready && hms_procurement_can_write($connection) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $act = (string) ($_POST['inv_action'] ?? '');
    $poId = (int) ($_POST['po_id'] ?? $poId);
    if ($act === 'create' && $poId > 0) {
        $po = hms_po_fetch_header($connection, $fid, $poId);
        $vid = (int) ($_POST['vendor_id'] ?? 0);
        $inum = trim((string) ($_POST['invoice_number'] ?? ''));
        $amt = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $tax = (float) str_replace(',', '.', (string) ($_POST['tax_amount'] ?? '0'));
        $idate = trim((string) ($_POST['invoice_date'] ?? ''));
        if ($po && $vid > 0 && $inum !== '' && $idate !== '') {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_procurement_vendor_invoice (facility_id, vendor_id, purchase_order_id, invoice_number, invoice_date, amount, tax_amount, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'iiissddi', $fid, $vid, $poId, $inum, $idate, $amt, $tax, $uid);
                mysqli_stmt_execute($st);
                $iid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);
                if (hms_db_column_exists($connection, 'tbl_purchase_order', 'vendor_invoice_id')) {
                    mysqli_query(
                        $connection,
                        'UPDATE tbl_purchase_order SET vendor_invoice_id = ' . (int) $iid . ' WHERE id = ' . (int) $poId . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
                    );
                }
                hms_audit_log($connection, 'procurement.vendor_invoice', 'tbl_procurement_vendor_invoice', $iid);
                $_SESSION['proc_flash'] = 'Vendor invoice recorded.';
            }
        } else {
            $_SESSION['proc_flash'] = 'Fill vendor, invoice #, date, and amounts.';
        }
        header('Location: procurement-invoice.php?po=' . $poId);
        exit;
    }
    if ($act === 'pay' && $poId > 0) {
        $iid = (int) ($_POST['invoice_id'] ?? 0);
        $pay = (float) str_replace(',', '.', (string) ($_POST['pay_amount'] ?? '0'));
        if ($iid > 0 && $pay > 0) {
            $q = mysqli_query(
                $connection,
                'SELECT amount, tax_amount, amount_paid FROM tbl_procurement_vendor_invoice WHERE id = ' . (int) $iid . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
            );
            $row = $q ? mysqli_fetch_assoc($q) : null;
            if (is_array($row)) {
                $due = (float) $row['amount'] + (float) $row['tax_amount'];
                $newPaid = (float) $row['amount_paid'] + $pay;
                $ps = 'unpaid';
                if ($newPaid >= $due - 0.009) {
                    $ps = 'paid';
                } elseif ($newPaid > 0) {
                    $ps = 'partial';
                }
                $st = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_procurement_vendor_invoice SET amount_paid = ?, payment_status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'dsii', $newPaid, $ps, $iid, $fid);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                }
                $_SESSION['proc_flash'] = 'Payment applied.';
            }
        }
        header('Location: procurement-invoice.php?po=' . $poId);
        exit;
    }
}

$po = $poId > 0 && $ready ? hms_po_fetch_header($connection, $fid, $poId) : null;
$invoices = ($po && $ready) ? hms_procurement_vendor_invoices_for_po($connection, $fid, $poId) : [];
$vendors = $ready ? hms_procurement_vendor_rows($connection, $fid, true) : [];

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header('Vendor invoice &amp; payment', [
            'subtitle' => 'Record supplier bills and track payment against the PO.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['Invoice', '']],
            'secondary' => $po ? [['label' => 'PO', 'url' => 'purchase-order.php?id=' . (int) $poId, 'icon' => 'fa-file-text']] : [],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready || !$po) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Need migration 046 and a valid <code>?po=</code> purchase order.</div>
        <?php } else { ?>
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
                <p class="small text-muted mb-0">PO <strong><?php echo hms_h((string) ($po['po_number'] ?? '#' . $poId)); ?></strong><?php
                $poSup = trim((string) ($po['supplier_name'] ?? ''));
                echo $poSup !== '' ? ' · ' . hms_h($poSup) : '';
                ?></p>
                <a class="btn btn-sm btn-outline-secondary font-weight-bold" href="purchase-order.php?id=<?php echo (int) $poId; ?>"><i class="fa fa-file-text-o mr-1"></i>Open PO</a>
            </div>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">Invoices on this PO</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Invoice #</th><th>Date</th><th class="text-right">Amount</th><th class="text-right">Tax</th><th class="text-right">Paid</th><th>Payment</th></tr></thead>
                        <tbody>
                        <?php foreach ($invoices as $iv) { ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo hms_h((string) ($iv['invoice_number'] ?? '')); ?></td>
                            <td class="text-muted small"><?php echo hms_h((string) ($iv['invoice_date'] ?? '')); ?></td>
                            <td class="text-right text-monospace"><?php echo hms_h(number_format((float) ($iv['amount'] ?? 0), 2, '.', '')); ?></td>
                            <td class="text-right text-monospace"><?php echo hms_h(number_format((float) ($iv['tax_amount'] ?? 0), 2, '.', '')); ?></td>
                            <td class="text-right text-monospace"><?php echo hms_h(number_format((float) ($iv['amount_paid'] ?? 0), 2, '.', '')); ?></td>
                            <td><span class="<?php echo hms_h(hms_procurement_badge_class((string) ($iv['payment_status'] ?? ''))); ?>"><?php echo hms_h((string) ($iv['payment_status'] ?? '')); ?></span></td>
                        </tr>
                        <?php } ?>
                        <?php if ($invoices === []) { ?>
                        <tr><td colspan="6" class="hms-proc-empty border-0 py-4"><i class="fa fa-file-o d-block mb-1"></i>No invoices yet.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <?php if (hms_procurement_can_write($connection)) { ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">Record invoice</div>
                <div class="card-body">
                    <form method="post" class="row">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="inv_action" value="create">
                        <input type="hidden" name="po_id" value="<?php echo (int) $poId; ?>">
                        <div class="col-md-4 mb-2">
                            <label class="small">Vendor</label>
                            <select name="vendor_id" class="form-control" required>
                                <?php foreach ($vendors as $v) { ?>
                                <option value="<?php echo (int) $v['id']; ?>"><?php echo hms_h((string) ($v['name'] ?? '')); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Invoice #</label>
                            <input type="text" name="invoice_number" class="form-control" required maxlength="128">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Invoice date</label>
                            <input type="date" name="invoice_date" class="form-control" required value="<?php echo hms_h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Amount</label>
                            <input type="text" name="amount" class="form-control" value="0">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="small">Tax</label>
                            <input type="text" name="tax_amount" class="form-control" value="0">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary font-weight-bold">Save invoice</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php if ($invoices !== []) {
                $last = $invoices[0];
                $lid = (int) ($last['id'] ?? 0);
                ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">Record payment <span class="text-muted font-weight-normal text-lowercase">(latest invoice)</span></div>
                <div class="card-body">
                    <form method="post" class="form-row align-items-end">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="inv_action" value="pay">
                        <input type="hidden" name="po_id" value="<?php echo (int) $poId; ?>">
                        <input type="hidden" name="invoice_id" value="<?php echo $lid; ?>">
                        <div class="col-auto mb-2 mb-sm-0">
                            <label class="small d-block">Amount</label>
                            <input type="text" name="pay_amount" class="form-control" style="min-width:140px;" value="0">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-success font-weight-bold"><i class="fa fa-money mr-1"></i>Apply payment</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
