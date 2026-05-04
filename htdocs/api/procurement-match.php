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

if ($ready && hms_procurement_can_write($connection) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null) && (string) ($_POST['match_action'] ?? '') === 'run') {
    $poId = (int) ($_POST['po_id'] ?? $poId);
    $po = $poId > 0 ? hms_po_fetch_header($connection, $fid, $poId) : null;
    if ($po) {
        $lines = hms_po_fetch_lines($connection, $fid, $poId);
        $gr = hms_procurement_grn_qty_by_po_line($connection, $fid, $poId);
        $poSum = 0.0;
        $grSum = 0.0;
        $detail = [];
        foreach ($lines as $ln) {
            $lid = (int) ($ln['line_id'] ?? 0);
            $q = (float) ($ln['quantity'] ?? 0);
            $p = (float) ($ln['unit_price'] ?? 0);
            $poSum += $q * $p;
            $rq = $gr[$lid] ?? 0.0;
            $grSum += $rq * $p;
            $detail[] = ['line_id' => $lid, 'po_qty' => $q, 'received' => $rq, 'unit_price' => $p];
        }
        $invs = hms_procurement_vendor_invoices_for_po($connection, $fid, $poId);
        $invSum = 0.0;
        foreach ($invs as $iv) {
            $invSum += (float) ($iv['amount'] ?? 0);
        }
        $variance = abs($poSum - $grSum) > 0.01 || ($invSum > 0 && abs($poSum - $invSum) > 0.01);
        $status = $variance ? 'variance' : 'matched';
        $j = json_encode(
            ['po_total' => $poSum, 'grn_value' => $grSum, 'invoice_total' => $invSum, 'lines' => $detail],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        mysqli_query($connection, 'DELETE FROM tbl_procurement_three_way_match WHERE facility_id = ' . (int) $fid . ' AND purchase_order_id = ' . (int) $poId);
        $esc = mysqli_real_escape_string($connection, (string) $j);
        mysqli_query(
            $connection,
            'INSERT INTO tbl_procurement_three_way_match (facility_id, purchase_order_id, status, detail_json, checked_at, checked_by) VALUES ('
            . (int) $fid . ',' . (int) $poId . ',\''
            . ($variance ? 'variance' : 'matched') . '\',\''
            . $esc . '\',NOW(),' . (int) $uid . ')'
        );
        $_SESSION['proc_flash'] = $variance
            ? 'Variance recorded — review PO, GRN quantities, and vendor invoice totals.'
            : 'Three-way match OK — PO, receipt value, and invoice totals align.';
        header('Location: procurement-match.php?po=' . $poId);
        exit;
    }
}

$po = $poId > 0 && $ready ? hms_po_fetch_header($connection, $fid, $poId) : null;
$match = ($po && $ready) ? hms_procurement_match_fetch($connection, $fid, $poId) : null;

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header('Three-way matching', [
            'subtitle' => 'Purchase order vs goods receipt vs vendor invoice (value comparison).',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['3-way match', '']],
            'secondary' => $po ? [['label' => 'PO', 'url' => 'purchase-order.php?id=' . (int) $poId, 'icon' => 'fa-file-text']] : [],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Run migrations 034 and 046.</div>
        <?php } elseif (!$po) { ?>
            <div class="card border-0 shadow-sm"><div class="card-body hms-proc-empty"><i class="fa fa-balance-scale" aria-hidden="true"></i>Use <code>?po=</code> from a purchase order.</div></div>
        <?php } else { ?>
            <?php if ($match) {
                $dj = json_decode((string) ($match['detail_json'] ?? '{}'), true);
                $st = (string) ($match['status'] ?? '');
                ?>
            <div class="d-flex align-items-center flex-wrap mb-3">
                <span class="<?php echo hms_h(hms_procurement_badge_class($st)); ?> mr-2"><?php echo hms_h($st); ?></span>
                <span class="text-muted small">Checked <?php echo hms_h((string) ($match['checked_at'] ?? '')); ?></span>
            </div>
            <?php if (is_array($dj)) { ?>
            <div class="hms-proc-kpi-row">
                <div class="hms-proc-kpi">
                    <div class="hms-proc-kpi-label">PO value</div>
                    <div class="hms-proc-kpi-value"><?php echo hms_h(number_format((float) ($dj['po_total'] ?? 0), 2, '.', '')); ?></div>
                    <div class="small text-muted mt-1">Qty × unit price</div>
                </div>
                <div class="hms-proc-kpi">
                    <div class="hms-proc-kpi-label">GRN value</div>
                    <div class="hms-proc-kpi-value"><?php echo hms_h(number_format((float) ($dj['grn_value'] ?? 0), 2, '.', '')); ?></div>
                    <div class="small text-muted mt-1">Received × PO price</div>
                </div>
                <div class="hms-proc-kpi">
                    <div class="hms-proc-kpi-label">Invoices</div>
                    <div class="hms-proc-kpi-value"><?php echo hms_h(number_format((float) ($dj['invoice_total'] ?? 0), 2, '.', '')); ?></div>
                    <div class="small text-muted mt-1">Vendor bills total</div>
                </div>
            </div>
            <?php } ?>
            <?php } else { ?>
            <p class="text-muted small mb-3">Run a match to compare PO, goods receipt, and invoice totals for this order.</p>
            <?php } ?>
            <?php if (hms_procurement_can_write($connection)) { ?>
            <form method="post">
                <?php echo hms_csrf_field(); ?>
                <input type="hidden" name="match_action" value="run">
                <input type="hidden" name="po_id" value="<?php echo (int) $poId; ?>">
                <button type="submit" class="btn btn-primary font-weight-bold"><i class="fa fa-refresh mr-1"></i>Run / refresh match</button>
            </form>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
