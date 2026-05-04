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
                hms_ui_page_header('Billing & payment', [
                    'subtitle' => 'Financial workspace — payments, charges, receipts, invoices, and insurance coverage.',
                    'breadcrumbs' => [['Billing', null], ['Workspace', '']],
                    'primary' => null,
                ]);
                $billingCards = [
                    ['title' => 'Cashier', 'description' => 'Enter patient payment codes to load amounts, collect payment, and issue receipts or invoices (consultation, lab, radiology, and more).', 'url' => 'cashier.php', 'icon' => 'fa-money'],
                    ['title' => 'Transactions', 'description' => 'Patient payments mirrored automatically from issued receipts (consultation, lab, pharmacy, charges).', 'url' => 'transactions.php', 'icon' => 'fa-money'],
                    ['title' => 'Charges', 'description' => 'Posted CPT charge codes for services rendered.', 'url' => 'charges.php', 'icon' => 'fa-list-alt'],
                    ['title' => 'Receipts & invoices', 'description' => 'Fiscal documents issued to patients and companies.', 'url' => 'receipts-invoices.php', 'icon' => 'fa-file-text-o'],
                    ['title' => 'Insurance', 'description' => 'Carriers and patient coverage records.', 'url' => 'insurance.php', 'icon' => 'fa-shield'],
                ];
                if (function_exists('hms_credit_tables_ok') && hms_credit_tables_ok($connection) && function_exists('hms_credit_can_read') && hms_credit_can_read($connection)) {
                    $billingCards[] = [
                        'title' => 'Credit & receivables',
                        'description' => 'Patient AR for emergency and on-credit services; record payments, installment plans, and follow-up.',
                        'url' => 'credit-receivables.php',
                        'icon' => 'fa-handshake-o',
                    ];
                }
                if ((string)($_SESSION['role'] ?? '') === '1' || hms_can($connection, 'billing.write')) {
                    $billingCards[] = ['title' => 'New company invoice', 'description' => 'Create a billable invoice addressed to a company.', 'url' => 'invoice-create.php', 'icon' => 'fa-building'];
                    $billingCards[] = ['title' => 'Billing companies', 'description' => 'Manage companies and corporates for invoicing.', 'url' => 'billing-companies.php', 'icon' => 'fa-briefcase'];
                }
                hms_ui_module_hub('', $billingCards);
                ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
