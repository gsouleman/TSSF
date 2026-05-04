<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

hms_procurement_require_read($connection);
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ready = hms_procurement_tables_ok($connection);
$rfqId = (int) ($_GET['id'] ?? 0);
$flash = isset($_SESSION['proc_flash']) ? (string) $_SESSION['proc_flash'] : '';
unset($_SESSION['proc_flash']);

if ($ready && hms_procurement_can_write($connection) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $act = (string) ($_POST['rfq_action'] ?? '');
    if ($act === 'create') {
        $title = trim((string) ($_POST['title'] ?? 'Request for quotation'));
        $num = hms_procurement_next_rfq_number($connection, $fid);
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_procurement_rfq (facility_id, rfq_number, title, status, created_by) VALUES (?,?,?,?,?)'
        );
        if ($st) {
            $stDraft = 'draft';
            mysqli_stmt_bind_param($st, 'isssi', $fid, $num, $title, $stDraft, $uid);
            mysqli_stmt_execute($st);
            $newId = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'procurement.rfq.create', 'tbl_procurement_rfq', $newId);
            header('Location: procurement-rfq.php?id=' . $newId);
            exit;
        }
    }
    $postRfq = (int) ($_POST['rfq_id'] ?? 0);
    if ($postRfq > 0) {
        $rfqId = $postRfq;
    }
    if ($act === 'add_line' && $rfqId > 0) {
        $rfq = hms_procurement_rfq_fetch($connection, $fid, $rfqId);
        if ($rfq && (string) ($rfq['status'] ?? '') === 'draft') {
            $desc = trim((string) ($_POST['line_desc'] ?? ''));
            $qty = (float) str_replace(',', '.', (string) ($_POST['line_qty'] ?? '1'));
            if ($qty <= 0) {
                $qty = 1;
            }
            $uom = trim((string) ($_POST['line_uom'] ?? 'unit')) ?: 'unit';
            if ($desc !== '') {
                mysqli_query(
                    $connection,
                    'INSERT INTO tbl_procurement_rfq_line (rfq_id, line_no, description, quantity, uom) VALUES ('
                    . (int) $rfqId . ', 0, \''
                    . mysqli_real_escape_string($connection, $desc) . '\', '
                    . (float) $qty . ', \''
                    . mysqli_real_escape_string($connection, $uom) . '\')'
                );
                $_SESSION['proc_flash'] = 'Line added.';
            }
        }
        header('Location: procurement-rfq.php?id=' . $rfqId);
        exit;
    }
    if ($act === 'issue' && $rfqId > 0) {
        $rfq = hms_procurement_rfq_fetch($connection, $fid, $rfqId);
        if ($rfq && (string) ($rfq['status'] ?? '') === 'draft') {
            $lines = hms_procurement_rfq_lines($connection, $rfqId);
            if ($lines !== []) {
                mysqli_query(
                    $connection,
                    'UPDATE tbl_procurement_rfq SET status = \'issued\', issued_at = NOW() WHERE id = '
                    . (int) $rfqId . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
                );
                $_SESSION['proc_flash'] = 'RFQ issued — vendors may respond with quotations.';
                hms_audit_log($connection, 'procurement.rfq.issue', 'tbl_procurement_rfq', $rfqId);
            } else {
                $_SESSION['proc_flash'] = 'Add at least one line before issuing.';
            }
        }
        header('Location: procurement-rfq.php?id=' . $rfqId);
        exit;
    }
    if ($act === 'close' && $rfqId > 0) {
        mysqli_query(
            $connection,
            'UPDATE tbl_procurement_rfq SET status = \'closed\', closed_at = NOW() WHERE id = '
            . (int) $rfqId . ' AND facility_id = ' . (int) $fid . " AND status IN ('draft','issued') LIMIT 1"
        );
        $_SESSION['proc_flash'] = 'RFQ closed.';
        header('Location: procurement-rfq.php?id=' . $rfqId);
        exit;
    }
}

$rfq = $rfqId > 0 ? hms_procurement_rfq_fetch($connection, $fid, $rfqId) : null;
$list = $rfqId < 1 && $ready ? hms_procurement_rfq_list($connection, $fid) : [];
$lines = ($rfq && $ready) ? hms_procurement_rfq_lines($connection, $rfqId) : [];
$quotes = ($rfq && $ready) ? hms_procurement_quotations_for_rfq($connection, $fid, $rfqId) : [];

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header($rfq ? ('RFQ ' . (string) ($rfq['rfq_number'] ?? '')) : 'Requests for quotation', [
            'subtitle' => $rfq ? (string) ($rfq['title'] ?? '') : 'Create RFQs, issue to vendors, and attach quotations.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['RFQs', '']],
            'secondary' => [['label' => 'Procurement hub', 'url' => 'procurement-home.php', 'icon' => 'fa-arrow-left']],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Run migration 046 first.</div>
        <?php } elseif ($rfqId > 0 && !$rfq) { ?>
            <div class="alert alert-danger">RFQ not found.</div>
            <p><a href="procurement-rfq.php">Back to list</a></p>
        <?php } elseif (!$rfq) { ?>
            <?php if (hms_procurement_can_write($connection)) { ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">Start an RFQ</div>
                <div class="card-body">
                    <form method="post" class="form-row align-items-end">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="rfq_action" value="create">
                        <div class="col-md-8 mb-2 mb-md-0">
                            <label class="small d-block">Title / scope</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Surgical consumables — Q2 replenishment" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary btn-block font-weight-bold"><i class="fa fa-plus mr-1"></i>Create draft</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="hms-proc-table-shell">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Number</th><th>Title</th><th>Status</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $r) { ?>
                        <tr>
                            <td><a class="font-weight-bold" href="procurement-rfq.php?id=<?php echo (int) $r['id']; ?>"><?php echo hms_h((string) ($r['rfq_number'] ?? '')); ?></a></td>
                            <td><?php echo hms_h((string) ($r['title'] ?? '')); ?></td>
                            <td><span class="<?php echo hms_h(hms_procurement_badge_class((string) ($r['status'] ?? ''))); ?>"><?php echo hms_h((string) ($r['status'] ?? '')); ?></span></td>
                            <td class="small text-muted"><?php echo hms_h((string) ($r['created_at'] ?? '')); ?></td>
                        </tr>
                        <?php } ?>
                        <?php if ($list === []) { ?>
                        <tr><td colspan="4" class="hms-proc-empty border-0"><i class="fa fa-file-text-o" aria-hidden="true"></i>No RFQs yet.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php } else { ?>
            <div class="hms-proc-toolbar">
                <a href="procurement-rfq.php" class="btn btn-sm btn-outline-secondary font-weight-bold"><i class="fa fa-arrow-left mr-1"></i>All RFQs</a>
                <span class="<?php echo hms_h(hms_procurement_badge_class((string) ($rfq['status'] ?? ''))); ?>"><?php echo hms_h((string) ($rfq['status'] ?? '')); ?></span>
            </div>
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">Line items</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                            <table class="table mb-0">
                                <thead><tr><th>#</th><th>Description</th><th>Qty</th><th>UoM</th></tr></thead>
                                <tbody>
                                <?php $i = 0;
                                foreach ($lines as $ln) {
                                    ++$i; ?>
                                <tr>
                                    <td><?php echo (int) $i; ?></td>
                                    <td><?php echo hms_h((string) ($ln['description'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($ln['quantity'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($ln['uom'] ?? '')); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if ($lines === []) { ?>
                                <tr><td colspan="4" class="hms-proc-empty border-0 py-4">No lines yet. Add items while this RFQ is in draft.</td></tr>
                                <?php } ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                        <?php if (hms_procurement_can_write($connection) && (string) ($rfq['status'] ?? '') === 'draft') { ?>
                        <div class="card-footer bg-white">
                            <form method="post" class="form-row align-items-end" action="procurement-rfq.php?id=<?php echo (int) $rfqId; ?>">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="rfq_action" value="add_line">
                                <input type="hidden" name="rfq_id" value="<?php echo (int) $rfqId; ?>">
                                <div class="col-md-6 mb-2">
                                    <label class="small">Description</label>
                                    <input type="text" name="line_desc" class="form-control" required maxlength="512">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="small">Qty</label>
                                    <input type="text" name="line_qty" class="form-control" value="1">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="small">UoM</label>
                                    <input type="text" name="line_uom" class="form-control" value="unit" maxlength="32">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <button type="submit" class="btn btn-primary btn-block font-weight-bold">Add line</button>
                                </div>
                            </form>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <div class="col-lg-5 mb-4">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white">Workflow</div>
                        <div class="card-body small text-muted">
                            <ol class="pl-3 mb-0" style="line-height:1.65;">
                                <li class="mb-1">Draft — add lines, then <strong>Issue RFQ</strong>.</li>
                                <li class="mb-1">Issued — record <strong>quotations</strong> from vendors.</li>
                                <li class="mb-1">Raise PO from inventory when ready (link vendor on PO).</li>
                                <li class="mb-0">Close RFQ when sourcing ends.</li>
                            </ol>
                        </div>
                        <?php if (hms_procurement_can_write($connection)) { ?>
                        <div class="card-footer bg-white">
                            <?php if ((string) ($rfq['status'] ?? '') === 'draft') { ?>
                            <form method="post" class="d-inline" action="procurement-rfq.php?id=<?php echo (int) $rfqId; ?>" onsubmit="return confirm('Issue this RFQ to vendors?');">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="rfq_action" value="issue">
                                <input type="hidden" name="rfq_id" value="<?php echo (int) $rfqId; ?>">
                                <button type="submit" class="btn btn-success font-weight-bold">Issue RFQ</button>
                            </form>
                            <?php } ?>
                            <?php if (in_array((string) ($rfq['status'] ?? ''), ['draft', 'issued'], true)) { ?>
                            <form method="post" class="d-inline ml-2" action="procurement-rfq.php?id=<?php echo (int) $rfqId; ?>" onsubmit="return confirm('Close this RFQ?');">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="rfq_action" value="close">
                                <input type="hidden" name="rfq_id" value="<?php echo (int) $rfqId; ?>">
                                <button type="submit" class="btn btn-outline-secondary">Close RFQ</button>
                            </form>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">Quotations received</div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($quotes as $q) { ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                                    <span class="font-weight-bold mr-2"><?php echo hms_h((string) ($q['vendor_name'] ?? '')); ?></span>
                                    <span class="d-flex align-items-center flex-wrap">
                                        <span class="<?php echo hms_h(hms_procurement_badge_class((string) ($q['status'] ?? ''))); ?> mr-2"><?php echo hms_h((string) ($q['status'] ?? '')); ?></span>
                                        <a class="btn btn-sm btn-primary" href="procurement-quotation.php?id=<?php echo (int) $q['id']; ?>">Open</a>
                                    </span>
                                </li>
                                <?php } ?>
                                <?php if ($quotes === []) { ?>
                                <li class="list-group-item hms-proc-empty border-0 py-4"><i class="fa fa-comments-o d-block mb-1"></i>No quotations yet.</li>
                                <?php } ?>
                            </ul>
                        </div>
                        <?php if (hms_procurement_can_write($connection) && in_array((string) ($rfq['status'] ?? ''), ['draft', 'issued'], true)) { ?>
                        <div class="card-footer bg-white">
                            <a class="btn btn-primary btn-sm font-weight-bold" href="procurement-quotation.php?rfq_id=<?php echo (int) $rfqId; ?>">New quotation</a>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
