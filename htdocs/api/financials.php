<?php
declare(strict_types=1);

/**
 * Accounting hub — Financials (journal helpers in includes/financials.php).
 * Journal UI sub-pages (e.g. financials-journal.php) may be added later; this page must not fatal.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$facilityName = function_exists('hms_current_facility_name')
    ? hms_current_facility_name($connection)
    : ('Site #' . $fid);
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canBilling = hms_can($connection, 'billing.read');
$canCredit = function_exists('hms_credit_can_read') && hms_credit_can_read($connection);

$finCards = [
    [
        'title' => 'Trial balance',
        'description' => 'Solidarity of Hearts Hospital — in-house trial balance by account and period.',
        'url' => 'financials-trial-balance.php',
        'icon' => 'fa-table',
    ],
    [
        'title' => 'General ledger',
        'description' => 'Posted journals by account with opening balance and running balance.',
        'url' => 'financials-general-ledger.php',
        'icon' => 'fa-book',
    ],
    [
        'title' => 'Cash flow statement',
        'description' => 'GL-based treasury movement and class-level activity (management view).',
        'url' => 'financials-cash-flow.php',
        'icon' => 'fa-random',
    ],
    [
        'title' => 'Accounts receivable',
        'description' => 'Patient credit balances with aging buckets.',
        'url' => 'financials-accounts-receivable.php',
        'icon' => 'fa-user-md',
    ],
    [
        'title' => 'Accounts payable',
        'description' => 'Expense register summarized by vendor for the selected period.',
        'url' => 'financials-accounts-payable.php',
        'icon' => 'fa-truck',
    ],
    [
        'title' => 'Bank reconciliation',
        'description' => 'Book balance for a bank GL account vs optional statement figure.',
        'url' => 'financials-bank-reconciliation.php',
        'icon' => 'fa-university',
    ],
    [
        'title' => 'Balance sheet',
        'description' => 'Statement of financial position — balances by account class (1–5).',
        'url' => 'financials-balance-sheet.php',
        'icon' => 'fa-balance-scale',
    ],
    [
        'title' => 'Financial statement (monthly)',
        'description' => 'Formal print-ready monthly income & expenditure statement.',
        'url' => 'financials-statement-monthly.php',
        'icon' => 'fa-file-text-o',
    ],
    [
        'title' => 'Financial statement (annual)',
        'description' => 'Annual performance and closing position by account class.',
        'url' => 'financials-statement-annual.php',
        'icon' => 'fa-files-o',
    ],
    [
        'title' => 'Journal loader',
        'description' => 'Import balanced journal-entry CSV into the general ledger.',
        'url' => 'financials-journal-loader.php',
        'icon' => 'fa-upload',
    ],
    [
        'title' => 'Month-end reporting',
        'description' => 'Quick month P&L-style summary (expenses and income).',
        'url' => 'financials-month-end.php',
        'icon' => 'fa-calendar',
    ],
    [
        'title' => 'Year-end reporting',
        'description' => 'Annual activity summary and year-end balance extract.',
        'url' => 'financials-year-end.php',
        'icon' => 'fa-calendar-check-o',
    ],
    [
        'title' => 'Tax (Cameroon)',
        'description' => 'VAT worksheets and tax aids — indicative DGI templates.',
        'url' => 'tax-declarations.php',
        'icon' => 'fa-file-text',
    ],
];
if (function_exists('hms_fin_can_write') && hms_fin_can_write($connection)) {
    array_splice($finCards, 6, 0, [[
        'title' => 'Sync to GL',
        'description' => 'Post historical fiscal receipts and expenses into the journal so reports match cashier and expense data.',
        'url' => 'financials-sync-gl.php',
        'icon' => 'fa-refresh',
    ]]);
}
if ($canBilling) {
    $finCards[] = [
        'title' => 'Transactions',
        'description' => 'Cashier and billing transaction history, filters, and charts.',
        'url' => 'transactions.php',
        'icon' => 'fa-exchange',
    ];
    $finCards[] = [
        'title' => 'Billing & payments',
        'description' => 'Invoices, receipts, cashier workflows, and insurance.',
        'url' => 'billing-payments.php',
        'icon' => 'fa-credit-card',
    ];
}
if ($canCredit) {
    $finCards[] = [
        'title' => 'Credit & Receivables',
        'description' => 'Patient credit accounts, balances, installments, and collections.',
        'url' => 'credit-receivables.php',
        'icon' => 'fa-folder-open',
    ];
}
$finCards[] = [
    'title' => 'Help & setup',
    'description' => 'Platform overview, migrations, and accounting-related prerequisites.',
    'url' => 'platform-overview.php',
    'icon' => 'fa-book',
];

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-financials-page">
                <?php
                hms_ui_page_header('Financials', [
                    'subtitle' => 'Solidarity of Hearts Hospital — in-house management reporting, journal readiness, this site, and shortcuts to billing and receivables.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', null]],
                    'back' => 'dashboard.php',
                ]);
                ?>
                <div class="row mb-4">
                    <div class="col-lg-8 mb-3 mb-lg-0">
                        <div class="card hms-fin-status-panel border-0 shadow-sm h-100">
                            <div class="card-body d-flex flex-wrap align-items-start">
                                <span class="hms-hub-card-icon rounded flex-shrink-0 mr-3 mb-2" aria-hidden="true"><i class="fa fa-balance-scale"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex flex-wrap align-items-center">
                                        <h2 class="h6 font-weight-bold text-dark mb-0 mr-2">General ledger / journal</h2>
                                        <?php if ($finOk) { ?>
                                        <span class="badge badge-success mt-1 mb-1">Journal ready</span>
                                        <?php } else { ?>
                                        <span class="badge badge-warning text-dark mt-1 mb-1">Setup required</span>
                                        <?php } ?>
                                    </div>
                                    <?php if (!$finOk) { ?>
                                    <div class="alert alert-warning small mb-0 mt-3">
                                        Journal tables are not installed. Run <code>hms/database/migrations/019_credit_receivables.sql</code> (and prerequisites from <a href="platform-overview.php">Help &amp; setup</a>) so accrual and collection entries can be stored.
                                    </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card hms-fin-status-panel border-0 shadow-sm h-100">
                            <div class="card-body d-flex align-items-start">
                                <span class="hms-hub-card-icon rounded flex-shrink-0 mr-3" aria-hidden="true"><i class="fa fa-building"></i></span>
                                <div class="min-w-0">
                                    <p class="hms-fin-site-label mb-1">This site</p>
                                    <p class="font-weight-bold text-dark mb-1 text-break"><?php echo hms_h($facilityName); ?></p>
                                    <p class="text-muted small mb-0">Facility ID <strong><?php echo (int) $fid; ?></strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <h2 class="hms-fin-section-title mb-3">Shortcuts</h2>
                <?php hms_ui_module_hub('', $finCards); ?>
            </div>
        </div>
<?php
include __DIR__ . '/footer.php';
