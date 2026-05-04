<?php
/** Post past patient receipts into the GL (idempotent). No strict_types for shared-host compatibility. */

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
$msg = '';
$err = '';
$done = 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sync_billing_batch'])) {
    if (!hms_can($connection, 'financials.write')) {
        $err = 'You need Financials — write permission to run the sync.';
    } elseif (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $sql = 'SELECT d.id, d.facility_id, d.source_module, d.total_amount, d.payment_method, d.doc_number, d.created_by,
            (SELECT bl.description FROM tbl_billing_document_line bl WHERE bl.document_id = d.id ORDER BY bl.sort_order ASC, bl.id ASC LIMIT 1) AS first_line
            FROM tbl_billing_document d
            WHERE d.doc_type = \'receipt\' AND d.status = \'issued\' AND d.patient_id IS NOT NULL AND d.total_amount > 0
            AND d.source_module <> \'transaction\'
            AND d.facility_id = ' . (int) $fid;
        if (hms_fin_journal_has_billing_link($connection)) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM tbl_fin_journal j WHERE j.facility_id = d.facility_id AND j.billing_document_id = d.id)';
        } else {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM tbl_fin_journal j WHERE j.facility_id = d.facility_id AND j.reference = CONCAT(\'BDOC:\', d.id))';
        }
        $sql .= ' ORDER BY d.id ASC LIMIT 40';
        $q = mysqli_query($connection, $sql);
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $bid = (int) ($row['id'] ?? 0);
                $src = (string) ($row['source_module'] ?? '');
                $tot = (float) ($row['total_amount'] ?? 0);
                $pm = isset($row['payment_method']) ? (string) $row['payment_method'] : null;
                $cb = (int) ($row['created_by'] ?? 0);
                $dn = (string) ($row['doc_number'] ?? '');
                $fl = (string) ($row['first_line'] ?? 'Payment');
                hms_fin_sync_journal_from_receipt(
                    $connection,
                    (int) ($row['facility_id'] ?? $fid),
                    $bid,
                    $src,
                    $tot,
                    $pm,
                    $cb,
                    $dn,
                    $fl
                );
                ++$done;
            }
            mysqli_free_result($q);
        }
        if ($done > 0) {
            $msg = 'Processed ' . $done . ' receipt(s). Run again if more remain.';
        } else {
            $msg = 'Nothing to sync (all matching receipts already have GL journals).';
        }
    }
}

$sqlCnt = 'SELECT COUNT(*) AS c FROM tbl_billing_document d
    WHERE d.doc_type = \'receipt\' AND d.status = \'issued\' AND d.patient_id IS NOT NULL AND d.total_amount > 0
    AND d.source_module <> \'transaction\' AND d.facility_id = ' . (int) $fid;
if (hms_fin_journal_has_billing_link($connection)) {
    $sqlCnt .= ' AND NOT EXISTS (SELECT 1 FROM tbl_fin_journal j WHERE j.facility_id = d.facility_id AND j.billing_document_id = d.id)';
} else {
    $sqlCnt .= ' AND NOT EXISTS (SELECT 1 FROM tbl_fin_journal j WHERE j.facility_id = d.facility_id AND j.reference = CONCAT(\'BDOC:\', d.id))';
}
$pending = 0;
$cq = mysqli_query($connection, $sqlCnt);
if ($cq) {
    $cr = mysqli_fetch_assoc($cq);
    $pending = (int) ($cr['c'] ?? 0);
    mysqli_free_result($cq);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Sync billing to ledger', [
                    'subtitle' => 'Post patient receipts (consultations, lab, pharmacy, etc.) as posted journals: DR cash/bank, CR revenue by source.',
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Sync billing', null]],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if ($msg !== '') { ?>
                <div class="alert alert-success"><?php echo hms_h($msg); ?></div>
                <?php } ?>
                <?php if ($err !== '') { ?>
                <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
                <?php } ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <p class="mb-2">Receipts waiting for GL posting for this site: <strong><?php echo (int) $pending; ?></strong></p>
                        <p class="text-muted small mb-3">New receipts are posted automatically when issued. Use this tool for data created before auto-posting was enabled, or if a journal was missing.</p>
                        <?php if (hms_can($connection, 'financials.write')) { ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                            <button type="submit" name="sync_billing_batch" value="1" class="btn btn-primary btn-sm font-weight-bold">Sync next batch (up to 40)</button>
                        </form>
                        <?php } else { ?>
                        <p class="text-muted small mb-0">Requires <strong>Financials — write</strong> to run.</p>
                        <?php } ?>
                    </div>
                </div>
                <p class="text-muted small">Revenue account mapping uses <code>source_module</code> (e.g. lab → 702000, pharmacy → 704000, default hospital care 701000). Cash vs bank uses payment method.</p>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
