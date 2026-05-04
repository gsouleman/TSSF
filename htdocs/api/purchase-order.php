<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';
require_once __DIR__ . '/includes/purchase_order_helpers.php';
require_once __DIR__ . '/includes/procurement_helpers.php';
require_once __DIR__ . '/includes/cameroon_medical_suppliers.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

if (!hms_procurement_can_read($connection)) {
    http_response_code(403);
    exit('Forbidden: procurement or inventory access required.');
}

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$canWrite = hms_procurement_can_write($connection);
$poId = (int) ($_GET['id'] ?? 0);

$flash = isset($_SESSION['po_flash']) ? (string) $_SESSION['po_flash'] : '';
unset($_SESSION['po_flash']);

if ($poId < 1) {
    header('Location: inventory.php');
    exit;
}

$workflowOk = hms_po_workflow_ready($connection);

if ($canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $po = hms_po_fetch_header($connection, $fid, $poId);
    if (!$po) {
        $_SESSION['po_flash'] = 'Purchase order not found.';
        header('Location: inventory.php');
        exit;
    }
    $stNow = (string) ($po['status'] ?? 'draft');
    $action = (string) ($_POST['po_action'] ?? '');

    if ($action === 'save_supplier') {
        if ($stNow === 'draft') {
            $sn = trim((string) ($_POST['supplier_name'] ?? ''));
            $esc = mysqli_real_escape_string($connection, $sn);
            mysqli_query(
                $connection,
                'UPDATE tbl_purchase_order SET supplier_name = \'' . $esc . '\' WHERE id = ' . $poId . ' AND facility_id = ' . $fid . ' LIMIT 1'
            );
            hms_audit_log($connection, 'po.supplier', 'purchase_order', $poId, ['supplier' => $sn]);
            $_SESSION['po_flash'] = 'Supplier / vendor saved.';
        }
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if ($action === 'save_notes' && hms_db_column_exists($connection, 'tbl_purchase_order', 'po_notes')) {
        $notes = trim((string) ($_POST['po_notes'] ?? ''));
        if (strlen($notes) > 500) {
            $notes = substr($notes, 0, 500);
        }
        $nesc = mysqli_real_escape_string($connection, $notes);
        $allowed = ['draft', 'approved', 'issued', 'received'];
        if (in_array($stNow, $allowed, true)) {
            mysqli_query(
                $connection,
                'UPDATE tbl_purchase_order SET po_notes = \'' . $nesc . '\' WHERE id = ' . $poId . ' AND facility_id = ' . $fid . ' LIMIT 1'
            );
            hms_audit_log($connection, 'po.notes', 'purchase_order', $poId, []);
            $_SESSION['po_flash'] = 'Notes saved.';
        }
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if (!$workflowOk) {
        $_SESSION['po_flash'] = 'Run database migration 036_purchase_order_workflow.sql to enable Approve / Issue.';
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if ($action === 'approve' && $stNow === 'draft') {
        $sn = trim((string) ($po['supplier_name'] ?? ''));
        if ($sn === '') {
            $_SESSION['po_flash'] = 'Enter a supplier / vendor name before approving.';
            header('Location: purchase-order.php?id=' . $poId);
            exit;
        }
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_purchase_order SET status = \'approved\', approved_at = NOW(), approved_by = ? WHERE id = ? AND facility_id = ? AND status = \'draft\' LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'iii', $uid, $poId, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'po.approve', 'purchase_order', $poId, []);
            $_SESSION['po_flash'] = 'Purchase order approved internally.';
        }
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if ($action === 'issue' && $stNow === 'approved') {
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_purchase_order SET status = \'issued\', issued_at = NOW(), issued_by = ? WHERE id = ? AND facility_id = ? AND status = \'approved\' LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'iii', $uid, $poId, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            if (hms_db_column_exists($connection, 'tbl_purchase_order', 'sent_to_vendor_at')) {
                $st2 = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_purchase_order SET sent_to_vendor_at = NOW(), sent_to_vendor_by = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($st2) {
                    mysqli_stmt_bind_param($st2, 'iii', $uid, $poId, $fid);
                    mysqli_stmt_execute($st2);
                    mysqli_stmt_close($st2);
                }
            }
            hms_audit_log($connection, 'po.issue', 'purchase_order', $poId, []);
            $_SESSION['po_flash'] = 'Purchase order sent to vendor (released for fulfilment).';
        }
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if ($action === 'receive' && $stNow === 'issued') {
        mysqli_query(
            $connection,
            'UPDATE tbl_purchase_order SET status = \'received\' WHERE id = ' . $poId . ' AND facility_id = ' . $fid . ' AND status = \'issued\' LIMIT 1'
        );
        hms_audit_log($connection, 'po.receive', 'purchase_order', $poId, []);
        $_SESSION['po_flash'] = 'Marked as received (goods recorded). Use Receive stock on Inventory when stock arrives.';
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    if ($action === 'cancel' && ($stNow === 'draft' || $stNow === 'approved')) {
        mysqli_query(
            $connection,
            'UPDATE tbl_purchase_order SET status = \'cancelled\' WHERE id = ' . $poId . ' AND facility_id = ' . $fid . ' LIMIT 1'
        );
        hms_audit_log($connection, 'po.cancel', 'purchase_order', $poId, []);
        $_SESSION['po_flash'] = 'Purchase order cancelled.';
        header('Location: purchase-order.php?id=' . $poId);
        exit;
    }

    header('Location: purchase-order.php?id=' . $poId);
    exit;
}

$po = hms_po_fetch_header($connection, $fid, $poId);
if (!$po) {
    http_response_code(404);
    exit('Purchase order not found.');
}

$lines = hms_po_fetch_lines($connection, $fid, $poId);
$pnum = (string) ($po['po_number'] ?? '');
$stNow = (string) ($po['status'] ?? 'draft');
$supplier = (string) ($po['supplier_name'] ?? '');
$notesVal = hms_db_column_exists($connection, 'tbl_purchase_order', 'po_notes')
    ? (string) ($po['po_notes'] ?? '') : '';
$apprAt = (string) ($po['approved_at'] ?? '');
$issAt = (string) ($po['issued_at'] ?? '');
$sentAt = (string) ($po['sent_to_vendor_at'] ?? '');
$sentById = (int) ($po['sent_to_vendor_by'] ?? 0);
$apprBy = (int) ($po['approved_by'] ?? 0);
$issBy = (int) ($po['issued_by'] ?? 0);
$apprName = $apprBy > 0 ? hms_po_employee_label(hms_po_employee_row($connection, $apprBy)) : '';
$issName = $issBy > 0 ? hms_po_employee_label(hms_po_employee_row($connection, $issBy)) : '';
$sentName = $sentById > 0 ? hms_po_employee_label(hms_po_employee_row($connection, $sentById)) : '';
$vendorDispAt = $sentAt !== '' ? $sentAt : $issAt;
$vendorDispName = $sentName !== '' ? $sentName : $issName;

$supplierOptions = hms_po_supplier_options($connection, $fid);

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <?php
        hms_ui_page_header('Purchase order ' . $pnum, [
            'subtitle' => 'Vendor, approval, send to vendor, goods receipt, matching, and invoicing — POs stay in the database for audit and reference.',
            'breadcrumbs' => [['Operations', null], ['Inventory', 'inventory.php'], ['PO', '']],
            'secondary' => array_values(array_filter([
                hms_procurement_tables_ok($connection)
                    ? ['label' => 'Procurement hub', 'url' => 'procurement-home.php', 'icon' => 'fa-shopping-cart']
                    : null,
                ['label' => 'Back to inventory', 'url' => 'inventory.php', 'icon' => 'fa-arrow-left'],
            ])),
        ]);
        ?>

        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>

        <?php if (!hms_po_tables_ok($connection)) { ?>
            <div class="alert alert-warning">Purchase order tables are missing. Run migration 034.</div>
        <?php } elseif (!$workflowOk) { ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <strong>Workflow not enabled.</strong> Run <code>hms/database/migrations/036_purchase_order_workflow.sql</code> in phpMyAdmin to unlock
                <strong>Approve</strong>, <strong>Issue</strong>, and audit timestamps. Until then, you can still set the supplier name while the PO is in Draft.
            </div>
        <?php } ?>

        <div class="d-flex flex-wrap align-items-center mb-3">
            <span class="mr-2 text-muted small">Status:</span>
            <span class="badge badge-dark text-uppercase"><?php echo hms_h(hms_po_status_label($stNow)); ?></span>
            <span class="ml-3 small text-muted">PO stays stored for future reference at every stage.</span>
        </div>

        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white font-weight-bold">Supplier / vendor</div>
                    <div class="card-body">
                        <?php if ($canWrite && $stNow === 'draft') { ?>
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="po_action" value="save_supplier">
                            <div class="form-group mb-2">
                                <label class="small text-muted mb-0" for="hms_po_supplier_input">Vendor / supplier</label>
                                <input type="text" name="supplier_name" id="hms_po_supplier_input" class="form-control" maxlength="128" value="<?php echo hms_h($supplier); ?>" list="hms_po_supplier_datalist" autocomplete="off" placeholder="Type to search or enter a new vendor…" aria-describedby="hms_po_supplier_help">
                                <datalist id="hms_po_supplier_datalist">
                                    <?php foreach ($supplierOptions as $opt) {
                                        $o = trim((string) $opt);
                                        if ($o === '') {
                                            continue;
                                        }
                                        ?>
                                    <option value="<?php echo hms_h($o); ?>"></option>
                                    <?php } ?>
                                </datalist>
                                <p id="hms_po_supplier_help" class="form-text text-muted small mb-0">
                                    Suggestions include a <strong>Cameroon medical supply</strong> seed list plus vendors already used on POs or expenses at this site.
                                    Pick a line or <strong>type any new supplier name</strong>, then save.
                                </p>
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm">Save supplier</button>
                        </form>
                        <?php } else { ?>
                        <p class="mb-0 font-weight-bold"><?php echo $supplier !== '' ? hms_h($supplier) : '<span class="text-muted">—</span>'; ?></p>
                        <?php if ($stNow === 'draft' && !$canWrite) { ?>
                            <p class="small text-muted mb-0">You need inventory write permission to edit.</p>
                        <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white font-weight-bold">Timeline</div>
                    <div class="card-body small">
                        <p class="mb-1"><strong>Created:</strong> <?php echo hms_h((string) ($po['created_at'] ?? '')); ?></p>
                        <?php if ($workflowOk) { ?>
                        <p class="mb-1"><strong>Approved:</strong> <?php echo $apprAt !== '' ? hms_h($apprAt) : '—'; ?><?php echo $apprName !== '' ? ' · ' . hms_h($apprName) : ''; ?></p>
                        <p class="mb-0"><strong>Sent to vendor:</strong> <?php echo $vendorDispAt !== '' ? hms_h($vendorDispAt) : '—'; ?><?php echo $vendorDispName !== '' ? ' · ' . hms_h($vendorDispName) : ''; ?></p>
                        <?php } else { ?>
                        <p class="text-muted mb-0">Run migration 036 for approval and issue timestamps.</p>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (hms_db_column_exists($connection, 'tbl_purchase_order', 'po_notes')) { ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white font-weight-bold">Internal notes (reference)</div>
            <div class="card-body">
                <?php if ($canWrite && $stNow !== 'cancelled') { ?>
                <form method="post">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="po_action" value="save_notes">
                    <textarea name="po_notes" class="form-control" rows="2" maxlength="500" placeholder="Reference, contract #, delivery instructions…"><?php echo hms_h($notesVal); ?></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-secondary mt-2">Save notes</button>
                </form>
                <?php } else { ?>
                <p class="mb-0 small"><?php echo $notesVal !== '' ? nl2br(hms_h($notesVal), false) : '<span class="text-muted">No notes.</span>'; ?></p>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white font-weight-bold">Line items</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 table-sm">
                        <thead class="thead-light">
                            <tr><th>SKU</th><th>Product</th><th class="text-right">Qty</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $ln) {
                            $sku = (string) ($ln['sku'] ?? '');
                            $nm = (string) ($ln['name'] ?? '');
                            if ($sku === '' && $nm === '') {
                                $nm = 'Item #' . (int) ($ln['inventory_item_id'] ?? 0);
                            }
                            ?>
                            <tr>
                                <td class="text-monospace small"><?php echo hms_h($sku !== '' ? $sku : '—'); ?></td>
                                <td><?php echo hms_h($nm); ?></td>
                                <td class="text-right font-weight-bold"><?php echo (int) ($ln['quantity'] ?? 0); ?></td>
                            </tr>
                        <?php } ?>
                        <?php if ($lines === []) { ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No lines.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if (hms_procurement_tables_ok($connection)) { ?>
        <div class="hms-procurement hms-procurement--embed">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">Procurement — next steps</div>
                <div class="card-body small">
                    <p class="text-muted mb-3">Formal goods receipt (GRN), 3-way matching, vendor invoices, and payment tracking for this PO.</p>
                    <div class="d-flex flex-wrap">
                        <a class="btn btn-primary btn-sm mr-2 mb-2 font-weight-bold" href="procurement-grn.php?po=<?php echo (int) $poId; ?>"><i class="fa fa-truck mr-1"></i>GRN</a>
                        <a class="btn btn-outline-primary btn-sm mr-2 mb-2 font-weight-bold" href="procurement-match.php?po=<?php echo (int) $poId; ?>"><i class="fa fa-balance-scale mr-1"></i>3-way match</a>
                        <a class="btn btn-outline-primary btn-sm mr-2 mb-2 font-weight-bold" href="procurement-invoice.php?po=<?php echo (int) $poId; ?>"><i class="fa fa-credit-card mr-1"></i>Invoice &amp; pay</a>
                        <a class="btn btn-outline-secondary btn-sm mb-2 font-weight-bold" href="procurement-home.php"><i class="fa fa-th-large mr-1"></i>Hub</a>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php if ($canWrite && $workflowOk) { ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white font-weight-bold">Actions</div>
            <div class="card-body">
                <p class="small text-muted mb-3">Typical flow: <strong>Draft</strong> (set vendor) → <strong>Approve</strong> (internal sign-off) → <strong>Send to vendor</strong> → record <strong>GRN</strong> when goods arrive, then <strong>3-way match</strong> and <strong>invoice</strong> (see Procurement workflow above).</p>
                <div class="d-flex flex-wrap align-items-center">
                    <?php if ($stNow === 'draft') { ?>
                    <form method="post" class="mr-2 mb-2" onsubmit="return confirm('Approve this PO internally?');">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="po_action" value="approve">
                        <button type="submit" class="btn btn-primary"<?php echo trim($supplier) === '' ? ' disabled title="Save supplier name first"' : ''; ?>>Approve PO</button>
                    </form>
                    <form method="post" class="mr-2 mb-2" onsubmit="return confirm('Cancel this PO?');">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="po_action" value="cancel">
                        <button type="submit" class="btn btn-outline-danger">Cancel</button>
                    </form>
                    <?php } elseif ($stNow === 'approved') { ?>
                    <form method="post" class="mr-2 mb-2" onsubmit="return confirm('Send this PO to the vendor (released for fulfilment)?');">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="po_action" value="issue">
                        <button type="submit" class="btn btn-success">Send to vendor</button>
                    </form>
                    <form method="post" class="mr-2 mb-2" onsubmit="return confirm('Cancel this PO?');">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="po_action" value="cancel">
                        <button type="submit" class="btn btn-outline-danger">Cancel</button>
                    </form>
                    <?php } elseif ($stNow === 'issued') { ?>
                    <form method="post" class="mr-2 mb-2" onsubmit="return confirm('Mark PO as received (quick status)? Prefer recording a GRN under Procurement workflow.');">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="po_action" value="receive">
                        <button type="submit" class="btn btn-outline-primary">Mark received (quick)</button>
                    </form>
                    <?php } else { ?>
                    <p class="small text-muted mb-0">No actions for this status.</p>
                    <?php } ?>
                </div>
            </div>
        </div>
        <?php } elseif ($canWrite && !$workflowOk && $stNow === 'draft') { ?>
        <p class="small text-muted">After migration 036, <strong>Approve</strong> and <strong>Issue</strong> buttons will appear here.</p>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
