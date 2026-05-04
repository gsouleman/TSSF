<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_portal($connection, 'accountant');

include 'header.php';

$fid = hms_current_facility_id();
$todayEsc = mysqli_real_escape_string($connection, date('Y-m-d'));
$txnOk = hms_db_table_exists($connection, 'tbl_transaction');
$txnToday = 0;
if ($txnOk && hms_db_column_exists($connection, 'tbl_transaction', 'facility_id')) {
    $q = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_transaction WHERE facility_id = ' . (int) $fid
        . " AND DATE(created_at) = '" . $todayEsc . "'"
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $txnToday = (int) ($r['c'] ?? 0);
    }
} elseif ($txnOk) {
    $q = mysqli_query(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_transaction WHERE DATE(created_at) = '" . $todayEsc . "'"
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $txnToday = (int) ($r['c'] ?? 0);
    }
}

$financialCards = [];
if (hms_can($connection, 'billing.read')) {
    $financialCards[] = [
        'title' => 'Billing workspace',
        'description' => 'Hub for cashier, transactions, charges, receipts, invoices, and insurance.',
        'url' => 'billing-payments.php',
        'icon' => 'fa-credit-card',
    ];
    $financialCards[] = [
        'title' => 'Transactions',
        'description' => 'Patient payments mirrored from receipts and cashier activity.',
        'url' => 'transactions.php',
        'icon' => 'fa-exchange',
    ];
    $financialCards[] = [
        'title' => 'Receipts & invoices',
        'description' => 'Fiscal documents issued to patients and companies.',
        'url' => 'receipts-invoices.php',
        'icon' => 'fa-file-text-o',
    ];
    $financialCards[] = [
        'title' => 'Charges',
        'description' => 'Posted CPT charge codes for services rendered.',
        'url' => 'charges.php',
        'icon' => 'fa-list-alt',
    ];
    $financialCards[] = [
        'title' => 'Insurance',
        'description' => 'Carriers and payer setup for billing.',
        'url' => 'insurance.php',
        'icon' => 'fa-shield',
    ];
}
if (function_exists('hms_expenses_ready') && hms_expenses_ready($connection) && function_exists('hms_expenses_can_read') && hms_expenses_can_read($connection)) {
    $financialCards[] = [
        'title' => 'Expense management',
        'description' => 'Facility operating expenses and vendor spend.',
        'url' => 'expense-management.php',
        'icon' => 'fa-money',
    ];
}
if (hms_can($connection, 'billing.write')) {
    $financialCards[] = [
        'title' => 'New company invoice',
        'description' => 'Create an invoice billed to a company or corporate account.',
        'url' => 'invoice-create.php',
        'icon' => 'fa-building',
    ];
    $financialCards[] = [
        'title' => 'Billing companies',
        'description' => 'Manage companies used on invoices.',
        'url' => 'billing-companies.php',
        'icon' => 'fa-briefcase',
    ];
}
if (function_exists('hms_credit_tables_ok') && hms_credit_tables_ok($connection) && function_exists('hms_credit_can_read') && hms_credit_can_read($connection)) {
    $financialCards[] = [
        'title' => 'Credit & receivables',
        'description' => 'Patient AR, on-credit charges, and collections.',
        'url' => 'credit-receivables.php',
        'icon' => 'fa-handshake-o',
    ];
}
if (hms_can($connection, 'billing.read')) {
    $financialCards[] = [
        'title' => 'Cashier portal',
        'description' => 'Overview of payment codes and cashier workflow (read-only access depends on role).',
        'url' => 'portal-cashier.php',
        'icon' => 'fa-desktop',
    ];
}

?>
<div class="page-wrapper">
    <div class="content hms-module">
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#1e3a8a 0%,#0f766e 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Accountant portal</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.88);">Financial overview — billing, receipts, expenses, and related tools for this site.</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <?php if (hms_can($connection, 'billing.read')) { ?>
                    <a href="billing-payments.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#1e3a8a;"><i class="fa fa-th-large mr-1"></i> Billing workspace</a>
                    <?php } ?>
                    <a href="platform-overview.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-life-ring mr-1"></i> Help &amp; setup</a>
                </div>
            </div>
        </div>

        <?php if ($txnOk) { ?>
        <div class="row mb-4">
            <div class="col-6 col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Transactions today</div>
                        <div class="h3 font-weight-bold mb-0" style="color:#1b2559"><?php echo $txnToday; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">
                <i class="fa fa-line-chart mr-2 text-primary"></i>Financial
            </div>
            <div class="card-body">
                <?php
                if ($financialCards === []) {
                    echo '<p class="text-muted mb-0">You do not have permission to open financial modules, or no modules are available. Ask an administrator to grant <strong>billing.read</strong> (and related permissions).</p>';
                } else {
                    hms_ui_module_hub('', $financialCards);
                }
                ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="font-weight-bold mb-2" style="color:#1b2559">Typical workflow</h5>
                <ol class="mb-0 pl-3 text-muted small">
                    <li class="mb-2">Use <strong>Billing workspace</strong> to reach the cashier, transactions, charges, and receipts for day-to-day revenue.</li>
                    <li class="mb-2">Review <strong>Transactions</strong> and <strong>Receipts &amp; invoices</strong> for audit and month-end alignment.</li>
                    <li class="mb-2">When migration <code>026</code> is applied, <strong>Expense management</strong> tracks facility spend separately from patient revenue.</li>
                    <li class="mb-0">Use <strong>Credit &amp; receivables</strong> when patients or schemes pay on account.</li>
                </ol>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
