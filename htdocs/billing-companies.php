<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');

$fid = hms_current_facility_id();
$ok = hms_db_table_exists($connection, 'tbl_billing_company');
$canWrite = hms_can($connection, 'billing.write');
$flash = isset($_SESSION['bc_flash']) ? (string) $_SESSION['bc_flash'] : '';
unset($_SESSION['bc_flash']);

if ($ok && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (isset($_POST['add_company'])) {
        $nm = trim((string) ($_POST['name'] ?? ''));
        if ($nm !== '') {
            $tax = trim((string) ($_POST['tax_id'] ?? ''));
            $addr = trim((string) ($_POST['billing_address'] ?? ''));
            $ph = trim((string) ($_POST['phone'] ?? ''));
            $em = trim((string) ($_POST['email'] ?? ''));
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_billing_company (facility_id, name, tax_id, billing_address, phone, email) VALUES (?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'isssss', $fid, $nm, $tax, $addr, $ph, $em);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'billing.company.create', 'billing_company', (int) mysqli_insert_id($connection));
                $_SESSION['bc_flash'] = 'Company added.';
            }
        } else {
            $_SESSION['bc_flash'] = 'Name is required.';
        }
        header('Location: billing-companies.php');
        exit;
    }
}

$companies = [];
if ($ok) {
    $cq = mysqli_query($connection, 'SELECT * FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . ' ORDER BY name LIMIT 300');
    while ($cq && $cr = mysqli_fetch_assoc($cq)) {
        $companies[] = $cr;
    }
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Billing companies', [
                'subtitle' => 'Corporate accounts for tax invoices (B2B).',
                'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Companies', '']],
                'secondary' => array_values(array_filter([
                    ['label' => 'Billing workspace', 'url' => 'billing-payments.php', 'icon' => 'fa-credit-card'],
                    $canWrite ? ['label' => 'New company invoice', 'url' => 'invoice-create.php', 'icon' => 'fa-file-text-o'] : null,
                ])),
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>011_receipt_invoice_module.sql</code>.</div>
            <?php } else { ?>
            <?php if ($canWrite) { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4 col-lg-8 px-0">
                <div class="card-header bg-white font-weight-bold">Add company</div>
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group"><label>Name</label><input class="form-control" name="name" required></div>
                        <div class="form-group"><label>Tax / registration ID</label><input class="form-control" name="tax_id" placeholder="Optional"></div>
                        <div class="form-group"><label>Billing address</label><textarea class="form-control" name="billing_address" rows="2"></textarea></div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label>Phone</label><input class="form-control" name="phone"></div>
                            <div class="form-group col-md-6"><label>Email</label><input class="form-control" name="email" type="email"></div>
                        </div>
                        <button type="submit" name="add_company" value="1" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Name</th><th>Tax ID</th><th>Phone</th><th>Email</th></tr></thead>
                            <tbody>
                            <?php foreach ($companies as $c) { ?>
                            <tr>
                                <td><?php echo hms_h((string) ($c['name'] ?? '')); ?></td>
                                <td><?php echo hms_h((string) ($c['tax_id'] ?? '')); ?></td>
                                <td><?php echo hms_h((string) ($c['phone'] ?? '')); ?></td>
                                <td><?php echo hms_h((string) ($c['email'] ?? '')); ?></td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
