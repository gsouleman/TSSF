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
    $poId = (int) ($_POST['po_id'] ?? $poId);
    $po = $poId > 0 ? hms_po_fetch_header($connection, $fid, $poId) : null;
    if ($po && (string) ($po['status'] ?? '') === 'issued') {
        $grn = hms_procurement_next_grn_number($connection, $fid);
        mysqli_query(
            $connection,
            'INSERT INTO tbl_procurement_goods_receipt (facility_id, purchase_order_id, grn_number, received_by) VALUES ('
            . (int) $fid . ',' . (int) $poId . ',\''
            . mysqli_real_escape_string($connection, $grn) . '\',' . (int) $uid . ')'
        );
        $gid = (int) mysqli_insert_id($connection);
        $lines = hms_po_fetch_lines($connection, $fid, $poId);
        foreach ($lines as $ln) {
            $lid = (int) ($ln['line_id'] ?? 0);
            $qty = (float) str_replace(',', '.', (string) ($_POST['recv_' . $lid] ?? '0'));
            if ($lid > 0 && $qty > 0) {
                mysqli_query(
                    $connection,
                    'INSERT INTO tbl_procurement_goods_receipt_line (goods_receipt_id, purchase_order_line_id, quantity_received) VALUES ('
                    . (int) $gid . ',' . (int) $lid . ',' . (float) $qty . ')'
                );
            }
        }
        mysqli_query(
            $connection,
            'UPDATE tbl_purchase_order SET status = \'received\' WHERE id = ' . (int) $poId . ' AND facility_id = ' . (int) $fid . " AND status = 'issued' LIMIT 1"
        );
        hms_audit_log($connection, 'procurement.grn', 'tbl_procurement_goods_receipt', $gid);
        $_SESSION['proc_flash'] = 'Goods receipt ' . $grn . ' recorded and PO marked received.';
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }
    $_SESSION['proc_flash'] = 'Could not post GRN (PO must be issued).';
    header('Location: procurement-grn.php?po=' . $poId);
    exit;
}

$po = $poId > 0 && $ready ? hms_po_fetch_header($connection, $fid, $poId) : null;
$lines = ($po && $ready) ? hms_po_fetch_lines($connection, $fid, $poId) : [];

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header('Goods receipt (GRN)', [
            'subtitle' => 'Receive stock against an issued purchase order.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['GRN', '']],
            'secondary' => $po ? [['label' => 'PO', 'url' => 'purchase-order.php?id=' . (int) $poId, 'icon' => 'fa-file-text']] : [],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Run migrations 034, 036, and 046.</div>
        <?php } elseif (!$po) { ?>
            <div class="card border-0 shadow-sm"><div class="card-body hms-proc-empty"><i class="fa fa-truck" aria-hidden="true"></i>Open this screen with <code>?po=</code> and a valid purchase order ID.</div></div>
        <?php } elseif ((string) ($po['status'] ?? '') !== 'issued') { ?>
            <div class="alert alert-warning border-0 shadow-sm">PO must be in <strong>Sent to vendor</strong> status before goods receipt.</div>
            <a href="purchase-order.php?id=<?php echo (int) $poId; ?>" class="btn btn-primary btn-sm font-weight-bold"><i class="fa fa-file-text-o mr-1"></i>Open PO</a>
        <?php } else { ?>
            <form method="post">
                <?php echo hms_csrf_field(); ?>
                <input type="hidden" name="po_id" value="<?php echo (int) $poId; ?>">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                        <span>Receipt lines</span>
                        <span class="small text-muted font-weight-normal">PO <?php echo hms_h((string) ($po['po_number'] ?? '#' . $poId)); ?></span>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Enter quantity received for each line. Posting creates a GRN and sets the PO to <strong>Received</strong>.</p>
                        <div class="hms-proc-table-shell">
                        <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Item</th><th>PO Qty</th><th>Qty received</th></tr></thead>
                            <tbody>
                            <?php foreach ($lines as $ln) {
                                $lid = (int) ($ln['line_id'] ?? 0); ?>
                            <tr>
                                <td><?php echo hms_h((string) ($ln['name'] ?? $ln['sku'] ?? '')); ?></td>
                                <td><?php echo (int) ($ln['quantity'] ?? 0); ?></td>
                                <td style="max-width:140px;">
                                    <input type="text" class="form-control" name="recv_<?php echo $lid; ?>" value="<?php echo (int) ($ln['quantity'] ?? 0); ?>">
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                        </div>
                        </div>
                        <?php if (hms_procurement_can_write($connection)) { ?>
                        <button type="submit" class="btn btn-primary font-weight-bold mt-3"><i class="fa fa-check mr-1"></i>Post goods receipt</button>
                        <?php } ?>
                    </div>
                </div>
            </form>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
