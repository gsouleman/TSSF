<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_charge');
$docOk = hms_billing_document_tables_ok($connection);
$chargesPrintDoc = hms_billing_take_print_prompt();
include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Charges', [
                'subtitle' => 'Posted charges for the active facility. This screen is not linked from the main menu.',
                'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Charges', '']],
                'primary' => hms_can($connection, 'billing.write')
                    ? ['label' => 'Post charge', 'url' => 'add-charge.php', 'icon' => 'fa-plus']
                    : null,
            ]);
            ?>
            <?php if ($chargesPrintDoc > 0) { ?>
            <div class="alert alert-success no-print">
                Receipt issued for the charge you posted.
                <a class="alert-link font-weight-bold" target="_blank" href="billing-document-pdf.php?id=<?php echo (int) $chargesPrintDoc; ?>">Download PDF</a>
                <span class="small">(</span><a class="alert-link small" target="_blank" href="billing-document-print.php?id=<?php echo (int) $chargesPrintDoc; ?>">HTML</a><span class="small">)</span>
            </div>
            <iframe title="Receipt PDF" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0)" src="billing-document-pdf.php?id=<?php echo (int) $chargesPrintDoc; ?>"></iframe>
            <?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for billing tables.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table datatable mb-0">
                            <thead><tr><th>Patient</th><th>CPT</th><th>Description</th><th class="text-right">Montant (FCFA)</th><th>Posted</th><?php if ($docOk) { ?><th class="no-print">Receipt</th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $selReceipt = $docOk
                                ? ', (SELECT d.id FROM tbl_billing_document d WHERE d.charge_id = c.id AND d.facility_id = c.facility_id AND d.doc_type = \'receipt\' ORDER BY d.id DESC LIMIT 1) AS receipt_doc_id'
                                : '';
                            $q = mysqli_query(
                                $connection,
                                'SELECT c.id AS charge_id, c.cpt_code, c.description, c.amount, c.posted_at, p.first_name, p.last_name' . $selReceipt . ' FROM tbl_charge c
                                 JOIN tbl_patient p ON p.id = c.patient_id WHERE c.facility_id = ' . (int) $fid . ' ORDER BY c.id DESC LIMIT 100'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                                echo '<td>' . hms_h((string) $r['cpt_code']) . '</td>';
                                echo '<td>' . hms_h((string) $r['description']) . '</td>';
                                echo '<td class="text-right text-nowrap">' . hms_h(hms_format_xaf((float) $r['amount'])) . '</td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['posted_at']) . '</td>';
                                if ($docOk) {
                                    $rd = (int) ($r['receipt_doc_id'] ?? 0);
                                    echo '<td class="no-print text-nowrap">';
                                    if ($rd > 0) {
                                        echo '<a class="btn btn-sm btn-outline-primary" target="_blank" href="billing-document-pdf.php?id=' . $rd . '">PDF</a> '
                                            . '<a class="btn btn-sm btn-link" target="_blank" href="billing-document-print.php?id=' . $rd . '">HTML</a>';
                                    } else {
                                        echo '<span class="text-muted small">—</span>';
                                    }
                                    echo '</td>';
                                }
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
