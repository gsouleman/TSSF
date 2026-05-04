<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');
include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Receipt & invoice workflow', [
                    'subtitle' => 'How catalog prices, payment methods, and fiscal documents connect across the app.',
                    'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Receipt workflow', '']],
                    'back' => 'billing-payments.php',
                    'secondary' => [
                        ['label' => 'Receipts & invoices', 'url' => 'receipts-invoices.php', 'icon' => 'fa-file-text-o'],
                        ['label' => 'Service catalog', 'url' => 'service-catalog.php', 'icon' => 'fa-book'],
                    ],
                ]);
                ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6 font-weight-bold">Shared rules</h2>
                        <ol class="mb-0 pl-3">
                            <li><strong>Service catalog</strong> holds prices by category (consultation, laboratory, pharmacy, service). Facility rows override global templates (<code>facility_id = 0</code>).</li>
                            <li>When staff confirm that <strong>payment was received</strong>, the system creates a <strong>billing document</strong> (receipt or company invoice) and triggers a <strong>PDF download</strong> (Dompdf). Install PHP dependencies in the <code>hms</code> folder with <code>composer install</code> if PDF fails; an HTML view remains available.</li>
                            <li><strong>Payment method</strong> is stored on the document (Cash, Mobile money, Card, Bank transfer, Insurance).</li>
                            <li><strong>Receipts</strong> name the patient as payer. <strong>Invoices</strong> require a row in <a href="billing-companies.php">Billing companies</a> and are stored with company snapshot.</li>
                            <li>Issuing fiscal documents from clinical flows requires <strong>billing write</strong> permission in addition to the module permission (e.g. consultation, lab, pharmacy).</li>
                        </ol>
                    </div>
                </div>
                <div class="row">
                    <div class="col-lg-6 mb-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white font-weight-bold">Consultation</div>
                            <div class="card-body small">
                                <p class="mb-2">Screen: <a href="consultation-new.php">New consultation</a>.</p>
                                <ol class="pl-3 mb-0">
                                    <li>Choose consultation type (general / specialist) and optional <strong>catalog service</strong>, or leave auto so the fee follows type plus department hint (e.g. Cardiology).</li>
                                    <li>Confirm the <strong>fee (FCFA)</strong>, <strong>payment method</strong>, and whether to print a <strong>receipt</strong> or <strong>company invoice</strong>.</li>
                                    <li>Check <strong>Fee received now</strong> to post the charge and create the document; use the print link when prompted.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white font-weight-bold">Laboratory</div>
                            <div class="card-body small">
                                <p class="mb-2">Screen: <a href="lab-results.php">Lab results</a> → New lab result.</p>
                                <ol class="pl-3 mb-0">
                                    <li>Optional: pick a <strong>catalog</strong> laboratory line to fill the fee.</li>
                                    <li>Enter fee if paid now, payment method, receipt vs invoice.</li>
                                    <li>Save; a fiscal document is created when fee &gt; 0 and billing rules pass.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white font-weight-bold">Pharmacy</div>
                            <div class="card-body small">
                                <p class="mb-2">Screen: <a href="prescriptions.php">Prescriptions</a> → open a prescription → <strong>Dispense</strong>.</p>
                                <ol class="pl-3 mb-0">
                                    <li>Optional <strong>catalog</strong> line fills the sale amount.</li>
                                    <li>Choose payment method and receipt vs invoice, then dispense.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white font-weight-bold">Other billing entry points</div>
                            <div class="card-body small">
                                <ul class="mb-0 pl-3">
                                    <li><a href="add-charge.php">Post charge</a> — manual service line, catalog picker, payment method, receipt.</li>
                                    <li><a href="invoice-create.php">New company invoice</a> — multi-line invoice without a clinical source.</li>
                                    <li><a href="transactions.php">Transactions</a> — where payment workflows may also attach documents when configured.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
