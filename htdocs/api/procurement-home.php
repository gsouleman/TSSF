<?php

declare(strict_types=1);



require_once __DIR__ . '/includes/bootstrap.php';



if (empty($_SESSION['name'])) {

    header('Location: index.php');

    exit;

}



hms_procurement_require_read($connection);



$fid = hms_current_facility_id();

$ready = hms_procurement_tables_ok($connection);

$standalone = hms_procurement_standalone_slice($connection);



$procSteps = [

    [

        'num' => 1,

        'icon' => 'fa-file-text-o',

        'title' => 'Request for quotation',

        'desc' => 'Create RFQs, add lines, issue to vendors, and collect structured responses.',

        'href' => 'procurement-rfq.php',

        'btn' => 'Open RFQs',

        'btnClass' => 'btn-primary',

        'foot' => '',

    ],

    [

        'num' => 2,

        'icon' => 'fa-comments-o',

        'title' => 'Quotations received',

        'desc' => 'Record vendor quotes against an RFQ, submit when received, then accept the winner.',

        'href' => 'procurement-rfq.php',

        'btn' => 'From RFQ detail',

        'btnClass' => 'btn-outline-primary',

        'foot' => '',

    ],

    [

        'num' => 3,

        'icon' => 'fa-shopping-bag',

        'title' => 'Purchase orders',

        'desc' => 'Draft → approved → sent to vendor → received, aligned with inventory PO workflow.',

        'href' => 'inventory.php',

        'btn' => 'PO list (inventory)',

        'btnClass' => 'btn-outline-primary',

        'foot' => '',

    ],

    [

        'num' => 4,

        'icon' => 'fa-truck',

        'title' => 'Goods receipt (GRN)',

        'desc' => 'Record quantities received against each PO line after the order is sent to the vendor.',

        'href' => '',

        'btn' => '',

        'btnClass' => '',

        'foot' => 'Open from a PO in Sent to vendor status.',

    ],

    [

        'num' => 5,

        'icon' => 'fa-balance-scale',

        'title' => 'Three-way matching',

        'desc' => 'Compare PO value, goods receipt value, and vendor invoice totals in one check.',

        'href' => '',

        'btn' => '',

        'btnClass' => '',

        'foot' => 'Run from a PO after GRN and invoice exist.',

    ],

    [

        'num' => 6,

        'icon' => 'fa-credit-card',

        'title' => 'Vendor invoice & payment',

        'desc' => 'Register supplier invoices and track partial or full payments against the PO.',

        'href' => '',

        'btn' => '',

        'btnClass' => '',

        'foot' => 'Open from a purchase order.',

    ],

    [

        'num' => 7,

        'icon' => 'fa-building',

        'title' => 'Vendor master',

        'desc' => 'Maintain the supplier directory used across RFQs, quotations, POs, and invoices.',

        'href' => 'procurement-vendors.php',

        'btn' => 'Manage vendors',

        'btnClass' => 'btn-primary',

        'foot' => '',

    ],

];



include 'header.php';

?>

<div class="page-wrapper">

    <div class="content hms-module hms-procurement">

        <?php

        hms_ui_page_header('Procurement', [

            'subtitle' => $standalone

                ? 'Standalone workspace — full source-to-pay for this site.'

                : 'Integrated with HMS catalog and inventory for end-to-end buying.',

            'breadcrumbs' => [

                ['Dashboard', 'dashboard.php'],

                ['Procurement', ''],

            ],

            'secondary' => [

                ['label' => 'Inventory & stock', 'url' => 'inventory.php', 'icon' => 'fa-cubes'],

                ['label' => 'Service catalog', 'url' => 'service-catalog.php', 'icon' => 'fa-tags'],

            ],

        ]);

        ?>



        <?php if (!$ready) { ?>

        <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate mb-0">

            <strong>Setup required.</strong> Run <code>database/migrations/046_procurement_module.sql</code> to create vendors, RFQs, quotations, GRN, matching, and invoice tables.

        </div>

        <?php } else { ?>

        <div class="hms-proc-hero">

            <div class="hms-proc-hero-inner">

                <span class="hms-proc-hero-eyebrow">Source-to-pay</span>

                <h1 class="hms-proc-hero-title">Buying, simplified</h1>

                <p class="hms-proc-hero-lead">

                    <?php echo $standalone

                        ? 'One place for RFQs, quotes, purchase orders, receipts, matching, and supplier payments.'

                        : 'Connect sourcing to stock: RFQ through payment, with POs tied to your catalog and inventory.'; ?>

                </p>

                <div class="hms-proc-hero-actions">

                    <a class="btn btn-light btn-sm font-weight-bold" href="procurement-rfq.php"><i class="fa fa-plus-circle mr-1"></i> New RFQ flow</a>

                    <a class="btn btn-outline-light btn-sm font-weight-bold" href="procurement-vendors.php"><i class="fa fa-building mr-1"></i> Vendors</a>

                    <a class="btn btn-outline-light btn-sm font-weight-bold" href="inventory.php"><i class="fa fa-cubes mr-1"></i> Inventory &amp; POs</a>

                </div>

            </div>

        </div>



        <h2 class="h6 font-weight-bold text-uppercase letter-spacing mb-3" style="letter-spacing:0.08em;color:var(--hms-text-muted,#64748b);">Workflow</h2>

        <div class="hms-proc-steps mb-2">

            <?php foreach ($procSteps as $st) { ?>

            <div class="hms-proc-step">

                <span class="hms-proc-step-num">Step <?php echo (int) $st['num']; ?></span>

                <div class="hms-proc-step-icon"><i class="fa <?php echo hms_h((string) $st['icon']); ?>" aria-hidden="true"></i></div>

                <h3><?php echo hms_h((string) $st['title']); ?></h3>

                <p><?php echo hms_h((string) $st['desc']); ?></p>

                <?php if ($st['href'] !== '' && $st['btn'] !== '') { ?>

                <a class="btn <?php echo hms_h((string) $st['btnClass']); ?> btn-sm" href="<?php echo hms_h((string) $st['href']); ?>"><?php echo hms_h((string) $st['btn']); ?></a>

                <?php } ?>

                <?php if ($st['foot'] !== '') { ?>

                <div class="hms-proc-step-foot"><?php echo hms_h((string) $st['foot']); ?></div>

                <?php } ?>

            </div>

            <?php } ?>

        </div>

        <?php } ?>

    </div>

</div>

<?php include 'footer.php'; ?>

