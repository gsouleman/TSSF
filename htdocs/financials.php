<?php
declare(strict_types=1);

/**
 * Core accounting hub — Cameroon-oriented general ledger (OHADA-style classes, XAF),
 * statements, treasury, tax links, and hospital revenue integration.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_accounting_helpers.php';

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
$canExpenses = function_exists('hms_expenses_can_read') && hms_expenses_can_read($connection);
$navF = hms_accounting_nav_flags($connection);
$metrics = hms_accounting_hub_metrics($connection, $fid);

$sections = [];

// --- 1. General ledger & journals (OHADA / XAF) ---
$glCards = [
    [
        'title' => 'Trial balance',
        'description' => 'Accounts by period with debit/credit totals — OHADA class mapping from account codes (classes 1–7). All amounts in XAF.',
        'url' => 'financials-trial-balance.php',
        'icon' => 'fa-table',
    ],
    [
        'title' => 'General ledger',
        'description' => 'Posted journals by account with opening balance and running balance (SYSCOHADA-style presentation).',
        'url' => 'financials-general-ledger.php',
        'icon' => 'fa-book',
    ],
    [
        'title' => 'Journal diagnostics',
        'description' => 'Compare journal vs receipt counts by site and period; optional schema repair for administrators.',
        'url' => 'financials-journal-diagnostics.php',
        'icon' => 'fa-stethoscope',
    ],
    [
        'title' => 'Journal loader',
        'description' => 'Import balanced journal-entry CSV into the general ledger (multi-line vouchers).',
        'url' => 'financials-journal-loader.php',
        'icon' => 'fa-upload',
    ],
];
if (function_exists('hms_fin_can_write') && hms_fin_can_write($connection)) {
    array_splice($glCards, 4, 0, [[
        'title' => 'Sync to GL',
        'description' => 'Post fiscal receipts and approved expenses into the journal so OHADA reports align with cashier and supplier spend.',
        'url' => 'financials-sync-gl.php',
        'icon' => 'fa-refresh',
    ]]);
}
$sections[] = [
    'title' => 'General ledger & journals',
    'intro' => 'Central books for Cameroon: XAF functional currency, account classes aligned with SYSCOHADA (fixed assets, third parties, treasury, charges, income).',
    'cards' => $glCards,
];

// --- 2. Third parties & treasury ---
$sections[] = [
    'title' => 'Third parties & treasury',
    'intro' => 'Receivables, payables, bank position, and cash-flow view — typical OHADA classes 4 and 5.',
    'cards' => [
        [
            'title' => 'Accounts receivable',
            'description' => 'Patient and third-party balances with aging buckets (collections focus).',
            'url' => 'financials-accounts-receivable.php',
            'icon' => 'fa-user-md',
        ],
        [
            'title' => 'Accounts payable',
            'description' => 'Expense register summarized by vendor for the selected period (commitments and payments).',
            'url' => 'financials-accounts-payable.php',
            'icon' => 'fa-truck',
        ],
        [
            'title' => 'Bank reconciliation',
            'description' => 'Book balance for a bank GL account vs statement figure — monthly control.',
            'url' => 'financials-bank-reconciliation.php',
            'icon' => 'fa-university',
        ],
        [
            'title' => 'Cash flow statement',
            'description' => 'GL-based treasury movement and class-level activity (management view).',
            'url' => 'financials-cash-flow.php',
            'icon' => 'fa-random',
        ],
    ],
];

// --- 3. Financial statements & period close ---
$sections[] = [
    'title' => 'Financial statements & period close',
    'intro' => 'Formal position and performance: balance sheet, P&L extracts, and print-ready statements for boards and auditors.',
    'cards' => [
        [
            'title' => 'Balance sheet',
            'description' => 'Statement of financial position — balances by OHADA account class (1–5).',
            'url' => 'financials-balance-sheet.php',
            'icon' => 'fa-balance-scale',
        ],
        [
            'title' => 'Financial statement (monthly)',
            'description' => 'Print-ready monthly income and expenditure statement (Cameroon presentation).',
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
            'title' => 'Profit & loss — month end',
            'description' => 'Aggregated profit and loss by month with year-to-date tracking (classes 6–7).',
            'url' => 'financials-month-end.php',
            'icon' => 'fa-calendar',
        ],
        [
            'title' => 'Year-end reporting',
            'description' => 'Annual activity summary and year-end balance extract for statutory packs.',
            'url' => 'financials-year-end.php',
            'icon' => 'fa-calendar-check-o',
        ],
    ],
];

// --- 4. Cameroon tax & statutory (when tax module is deployed) ---
$taxCards = [];
if ($navF['tax']) {
    $taxCards[] = [
        'title' => 'Tax workspace (Cameroon)',
        'description' => 'VAT, CNPS, DGI exports, compliance calendar, and payroll tax settings — integrated tax module.',
        'url' => 'tax/tax-home.php',
        'icon' => 'fa-calculator',
    ];
}
$taxCards[] = [
    'title' => 'Tax worksheets & declarations',
    'description' => 'Indicative VAT, CIT, and payroll tax aids — supporting schedules for Cameroon rules (not a substitute for professional advice).',
    'url' => 'tax-declarations.php',
    'icon' => 'fa-file-text',
];
$sections[] = [
    'title' => 'Cameroon tax & statutory',
    'intro' => 'Align statutory outputs with DGI and social contribution practice. Use the tax module when enabled; worksheets support planning.',
    'cards' => $taxCards,
];

// --- 5. Payroll link (when HR slice is on) ---
if ($navF['payroll']) {
    $sections[] = [
        'title' => 'Payroll interface',
        'intro' => 'Pay runs feed CNPS/IRPP and tax module when both are licensed. Open payroll from here for a quick path.',
        'cards' => [
            [
                'title' => 'HR & payroll',
                'description' => 'Monthly payroll from pay profiles, leave, attendance, and payslips.',
                'url' => 'payroll.php',
                'icon' => 'fa-users',
            ],
        ],
    ];
}

// --- 6. Hospital revenue & cash (when billing is licensed) ---
$revCards = [];
if ($canBilling) {
    $revCards[] = [
        'title' => 'Transactions',
        'description' => 'Cashier and billing transaction history, filters, and charts — cash evidence for GL sync.',
        'url' => 'transactions.php',
        'icon' => 'fa-exchange',
    ];
    $revCards[] = [
        'title' => 'Billing & payments',
        'description' => 'Invoices, receipts, cashier workflows, and insurance — revenue recognition inputs.',
        'url' => 'billing-payments.php',
        'icon' => 'fa-credit-card',
    ];
}
if ($canCredit) {
    $revCards[] = [
        'title' => 'Credit & receivables',
        'description' => 'Patient credit accounts, installments, and collections — complements AR aging.',
        'url' => 'credit-receivables.php',
        'icon' => 'fa-folder-open',
    ];
}
if ($revCards !== []) {
    $sections[] = [
        'title' => 'Hospital revenue & cash',
        'intro' => 'Operational billing feeds the GL through Sync to GL. Use these screens before month-end close.',
        'cards' => $revCards,
    ];
}

// --- 7. Expenses ---
if ($canExpenses) {
    $sections[] = [
        'title' => 'Expenses & commitments',
        'intro' => 'Supplier and internal expense capture — posts to GL when configured.',
        'cards' => [
            [
                'title' => 'Expense management',
                'description' => 'Register, approve, and track facility expenses for AP and GL alignment.',
                'url' => 'expense-management.php',
                'icon' => 'fa-money',
            ],
        ],
    ];
}

// --- 8. Help ---
$sections[] = [
    'title' => 'Setup & documentation',
    'intro' => 'Migrations, prerequisites, and platform notes for finance teams.',
    'cards' => [
        [
            'title' => 'Help & setup',
            'description' => 'Platform overview, database migrations, and accounting prerequisites.',
            'url' => 'platform-overview.php',
            'icon' => 'fa-book',
        ],
    ],
];

$acctSecondary = [
    ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'fa-th-large'],
];
if ($navF['healthcare']) {
    $acctSecondary[] = ['label' => 'Billing', 'url' => 'billing-payments.php', 'icon' => 'fa-credit-card'];
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-financials-page hms-accounting">
                <?php
                hms_ui_page_header('Core accounting', [
                    'subtitle' => 'Cameroon — OHADA-oriented general ledger (XAF), statements, treasury, tax links, and hospital revenue integration for this site.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Core accounting', '']],
                    'secondary' => $acctSecondary,
                ]);
                ?>
                <div class="hms-acct-hero">
                    <div class="hms-acct-hero-inner">
                        <span class="hms-acct-hero-eyebrow">OHADA · XAF · Multi-site</span>
                        <h1 class="hms-acct-hero-title">Financial control centre</h1>
                        <p class="hms-acct-hero-lead">
                            Use the sections below for the complete accounting cycle: journals and trial balance, third parties and banks,
                            statutory statements, Cameroon tax tools, and links to billing and expenses when those modules are enabled.
                        </p>
                    </div>
                </div>

                <div class="hms-acct-kpi-row">
                    <div class="hms-acct-kpi<?php echo $finOk ? ' hms-acct-kpi--ok' : ' hms-acct-kpi--warn'; ?>">
                        <div class="hms-acct-kpi-label">General ledger</div>
                        <div class="hms-acct-kpi-value"><?php echo $finOk ? 'Ready' : 'Setup'; ?></div>
                    </div>
                    <div class="hms-acct-kpi">
                        <div class="hms-acct-kpi-label">Journals (this month)</div>
                        <div class="hms-acct-kpi-value"><?php echo (int) $metrics['headers_mtd']; ?></div>
                    </div>
                    <div class="hms-acct-kpi">
                        <div class="hms-acct-kpi-label">Lines posted (MTD)</div>
                        <div class="hms-acct-kpi-value"><?php echo (int) $metrics['lines_mtd']; ?></div>
                    </div>
                    <div class="hms-acct-kpi">
                        <div class="hms-acct-kpi-label">Currency</div>
                        <div class="hms-acct-kpi-value" style="font-size:1.1rem;">XAF</div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-8 mb-3 mb-lg-0">
                        <div class="card hms-fin-status-panel border-0 shadow-sm h-100">
                            <div class="card-body d-flex flex-wrap align-items-start">
                                <span class="hms-hub-card-icon rounded flex-shrink-0 mr-3 mb-2" aria-hidden="true"><i class="fa fa-balance-scale"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex flex-wrap align-items-center">
                                        <h2 class="h6 font-weight-bold text-dark mb-0 mr-2">Journal &amp; OHADA mapping</h2>
                                        <?php if ($finOk) { ?>
                                        <span class="badge badge-success mt-1 mb-1">Tables installed</span>
                                        <?php } else { ?>
                                        <span class="badge badge-warning text-dark mt-1 mb-1">Action required</span>
                                        <?php } ?>
                                    </div>
                                    <?php if (!$finOk) { ?>
                                    <div class="alert alert-warning small mb-0 mt-3">
                                        Install journal tables (e.g. <code>database/migrations/019_credit_receivables.sql</code>) and follow
                                        <a href="platform-overview.php">Help &amp; setup</a> so vouchers, trial balance, and Cameroon statements can run.
                                    </div>
                                    <?php } else { ?>
                                    <p class="text-muted small mb-0 mt-2">
                                        Account codes map to SYSCOHADA classes 1–7 for reporting. Amounts are stored and displayed in <strong>FCFA (XAF)</strong>.
                                    </p>
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
                                    <p class="hms-fin-site-label mb-1">Reporting entity</p>
                                    <p class="font-weight-bold text-dark mb-1 text-break"><?php echo hms_h($facilityName); ?></p>
                                    <p class="text-muted small mb-0">Facility ID <strong><?php echo (int) $fid; ?></strong></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php foreach ($sections as $sec) {
                    $st = (string) ($sec['title'] ?? '');
                    if ($st === '') {
                        continue;
                    }
                    ?>
                <div class="hms-acct-section">
                    <h2 class="hms-acct-section-title"><?php echo hms_h($st); ?></h2>
                    <?php if (!empty($sec['intro'])) { ?>
                    <p class="hms-acct-section-intro"><?php echo hms_h((string) $sec['intro']); ?></p>
                    <?php } ?>
                    <?php hms_ui_module_hub('', $sec['cards'] ?? []); ?>
                </div>
                <?php } ?>
            </div>
        </div>
<?php
include __DIR__ . '/footer.php';
