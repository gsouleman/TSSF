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
$qid = (int) ($_GET['id'] ?? 0);
$rfqIdGet = (int) ($_GET['rfq_id'] ?? 0);
$flash = isset($_SESSION['proc_flash']) ? (string) $_SESSION['proc_flash'] : '';
unset($_SESSION['proc_flash']);

if ($ready && hms_procurement_can_write($connection) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $act = (string) ($_POST['quote_action'] ?? '');
    if ($act === 'create' && $rfqIdGet < 1) {
        $rfqIdGet = (int) ($_POST['rfq_id'] ?? 0);
    }
    if ($act === 'create' && $rfqIdGet > 0) {
        $vid = (int) ($_POST['vendor_id'] ?? 0);
        $rfq = hms_procurement_rfq_fetch($connection, $fid, $rfqIdGet);
        $v = $vid > 0 ? hms_procurement_vendor_fetch($connection, $fid, $vid) : null;
        if ($rfq && $v) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_procurement_quotation (facility_id, rfq_id, vendor_id, status, created_by) VALUES (?,?,?,?,?)'
            );
            if ($st) {
                $stDraft = 'draft';
                mysqli_stmt_bind_param($st, 'iiisi', $fid, $rfqIdGet, $vid, $stDraft, $uid);
                mysqli_stmt_execute($st);
                $newQ = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'procurement.quote.create', 'tbl_procurement_quotation', $newQ);
                header('Location: procurement-quotation.php?id=' . $newQ);
                exit;
            }
        }
        $_SESSION['proc_flash'] = 'Could not create quotation (check RFQ and vendor).';
        header('Location: procurement-quotation.php?rfq_id=' . $rfqIdGet);
        exit;
    }
    $postQ = (int) ($_POST['quotation_id'] ?? 0);
    if ($postQ > 0) {
        $qid = $postQ;
    }
    if ($act === 'add_line' && $qid > 0) {
        $q = hms_procurement_quotation_fetch($connection, $fid, $qid);
        if ($q && (string) ($q['status'] ?? '') === 'draft') {
            $desc = trim((string) ($_POST['line_desc'] ?? ''));
            $qty = (float) str_replace(',', '.', (string) ($_POST['line_qty'] ?? '1'));
            $up = (float) str_replace(',', '.', (string) ($_POST['line_price'] ?? '0'));
            if ($desc !== '') {
                mysqli_query(
                    $connection,
                    'INSERT INTO tbl_procurement_quotation_line (quotation_id, description, quantity, unit_price) VALUES ('
                    . (int) $qid . ', \''
                    . mysqli_real_escape_string($connection, $desc) . '\', '
                    . (float) $qty . ', ' . (float) $up . ')'
                );
                mysqli_query(
                    $connection,
                    'UPDATE tbl_procurement_quotation SET total_amount = (
                        SELECT COALESCE(SUM(quantity * unit_price),0) FROM tbl_procurement_quotation_line WHERE quotation_id = ' . (int) $qid . '
                    ) WHERE id = ' . (int) $qid . ' LIMIT 1'
                );
                $_SESSION['proc_flash'] = 'Line added.';
            }
        }
        header('Location: procurement-quotation.php?id=' . $qid);
        exit;
    }
    if ($act === 'submit' && $qid > 0) {
        mysqli_query(
            $connection,
            'UPDATE tbl_procurement_quotation SET status = \'submitted\', submitted_at = NOW() WHERE id = '
            . (int) $qid . ' AND facility_id = ' . (int) $fid . " AND status = 'draft' LIMIT 1"
        );
        $_SESSION['proc_flash'] = 'Quotation submitted.';
        header('Location: procurement-quotation.php?id=' . $qid);
        exit;
    }
    if ($act === 'accept' && $qid > 0) {
        $q = hms_procurement_quotation_fetch($connection, $fid, $qid);
        if ($q && (string) ($q['status'] ?? '') === 'submitted') {
            $rid = (int) ($q['rfq_id'] ?? 0);
            mysqli_query($connection, 'UPDATE tbl_procurement_quotation SET status = \'rejected\' WHERE rfq_id = ' . (int) $rid . ' AND facility_id = ' . (int) $fid . ' AND id <> ' . (int) $qid);
            mysqli_query(
                $connection,
                'UPDATE tbl_procurement_quotation SET status = \'accepted\' WHERE id = ' . (int) $qid . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
            );
            $_SESSION['proc_flash'] = 'Quotation accepted. Others on this RFQ were rejected.';
        }
        header('Location: procurement-quotation.php?id=' . $qid);
        exit;
    }
}

$quote = $qid > 0 ? hms_procurement_quotation_fetch($connection, $fid, $qid) : null;
$vendors = $ready ? hms_procurement_vendor_rows($connection, $fid, true) : [];
$rfqForNew = $rfqIdGet > 0 ? hms_procurement_rfq_fetch($connection, $fid, $rfqIdGet) : null;
$lines = ($quote && $ready) ? hms_procurement_quotation_lines($connection, $qid) : [];

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header($quote ? ('Quotation #' . (string) $qid) : 'New quotation', [
            'subtitle' => $quote ? ('Vendor: ' . (string) ($quote['vendor_name'] ?? '')) : 'Select vendor and add lines.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['Quotation', '']],
            'secondary' => [['label' => 'RFQs', 'url' => 'procurement-rfq.php', 'icon' => 'fa-arrow-left']],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Run migration 046 first.</div>
        <?php } elseif ($qid > 0 && !$quote) { ?>
            <p class="text-danger">Quotation not found.</p>
        <?php } elseif (!$quote && $rfqForNew) { ?>
            <div class="hms-proc-toolbar">
                <a href="procurement-rfq.php?id=<?php echo (int) $rfqIdGet; ?>" class="btn btn-sm btn-outline-secondary font-weight-bold"><i class="fa fa-arrow-left mr-1"></i>Back to RFQ</a>
            </div>
            <?php if (hms_procurement_can_write($connection)) { ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">New quotation</div>
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="quote_action" value="create">
                        <input type="hidden" name="rfq_id" value="<?php echo (int) $rfqIdGet; ?>">
                        <div class="form-group">
                            <label>Vendor</label>
                            <select name="vendor_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php foreach ($vendors as $v) { ?>
                                <option value="<?php echo (int) $v['id']; ?>"><?php echo hms_h((string) ($v['name'] ?? '')); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary font-weight-bold">Create draft quotation</button>
                    </form>
                </div>
            </div>
            <?php } ?>
        <?php } else { ?>
            <div class="hms-proc-toolbar">
                <a href="procurement-rfq.php?id=<?php echo (int) ($quote['rfq_id'] ?? 0); ?>" class="btn btn-sm btn-outline-secondary font-weight-bold"><i class="fa fa-arrow-left mr-1"></i>Back to RFQ</a>
                <span class="<?php echo hms_h(hms_procurement_badge_class((string) ($quote['status'] ?? ''))); ?>"><?php echo hms_h((string) ($quote['status'] ?? '')); ?></span>
            </div>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                    <span>Lines</span>
                    <?php if ((float) ($quote['total_amount'] ?? 0) > 0) { ?>
                    <span class="text-muted small font-weight-normal text-uppercase" style="letter-spacing:0.05em;">Total <?php echo hms_h((string) ($quote['currency'] ?? 'XAF')); ?> <strong class="text-dark"><?php echo hms_h(number_format((float) ($quote['total_amount'] ?? 0), 2, '.', '')); ?></strong></span>
                    <?php } ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Line total</th></tr></thead>
                        <tbody>
                        <?php foreach ($lines as $ln) {
                            $lt = (float) ($ln['quantity'] ?? 0) * (float) ($ln['unit_price'] ?? 0);
                            ?>
                        <tr>
                            <td><?php echo hms_h((string) ($ln['description'] ?? '')); ?></td>
                            <td><?php echo hms_h((string) ($ln['quantity'] ?? '')); ?></td>
                            <td><?php echo hms_h((string) ($ln['unit_price'] ?? '')); ?></td>
                            <td><?php echo hms_h(number_format($lt, 2, '.', '')); ?></td>
                        </tr>
                        <?php } ?>
                        <?php if ($lines === []) { ?>
                        <tr><td colspan="4" class="hms-proc-empty border-0 py-4">No lines.</td></tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php if (hms_procurement_can_write($connection) && (string) ($quote['status'] ?? '') === 'draft') { ?>
                <div class="card-footer bg-white">
                    <form method="post" class="form-row align-items-end" action="procurement-quotation.php?id=<?php echo (int) $qid; ?>">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="quote_action" value="add_line">
                        <input type="hidden" name="quotation_id" value="<?php echo (int) $qid; ?>">
                        <div class="col-md-5 mb-2">
                            <input type="text" name="line_desc" class="form-control" placeholder="Description" required maxlength="512">
                        </div>
                        <div class="col-md-2 mb-2">
                            <input type="text" name="line_qty" class="form-control" value="1">
                        </div>
                        <div class="col-md-2 mb-2">
                            <input type="text" name="line_price" class="form-control" value="0" placeholder="Price">
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary btn-block font-weight-bold">Add line</button>
                        </div>
                    </form>
                </div>
                <?php } ?>
            </div>
            <?php if (hms_procurement_can_write($connection)) { ?>
            <div class="d-flex flex-wrap">
                <?php if ((string) ($quote['status'] ?? '') === 'draft' && $lines !== []) { ?>
                <form method="post" class="mr-2 mb-2" action="procurement-quotation.php?id=<?php echo (int) $qid; ?>">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="quote_action" value="submit">
                    <input type="hidden" name="quotation_id" value="<?php echo (int) $qid; ?>">
                    <button type="submit" class="btn btn-success font-weight-bold">Mark quotation received</button>
                </form>
                <?php } ?>
                <?php if ((string) ($quote['status'] ?? '') === 'submitted') { ?>
                <form method="post" class="mb-2" action="procurement-quotation.php?id=<?php echo (int) $qid; ?>" onsubmit="return confirm('Accept this quote and reject others on the RFQ?');">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="quote_action" value="accept">
                    <input type="hidden" name="quotation_id" value="<?php echo (int) $qid; ?>">
                    <button type="submit" class="btn btn-primary font-weight-bold">Accept quotation</button>
                </form>
                <?php } ?>
            </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
