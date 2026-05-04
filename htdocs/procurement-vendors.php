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
$flash = isset($_SESSION['proc_flash']) ? (string) $_SESSION['proc_flash'] : '';
unset($_SESSION['proc_flash']);

if ($ready && hms_procurement_can_write($connection) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $act = (string) ($_POST['vendor_action'] ?? '');
    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = trim((string) ($_POST['vendor_code'] ?? ''));
        if ($name === '') {
            $_SESSION['proc_flash'] = 'Vendor name is required.';
        } else {
            if ($code === '') {
                $code = 'V-' . substr(preg_replace('/[^a-z0-9]+/i', '-', $name), 0, 24);
            }
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_procurement_vendor (facility_id, vendor_code, name, contact_name, phone, email, created_by)
                 VALUES (?,?,?,?,?,?,?)'
            );
            if ($st) {
                $cn = trim((string) ($_POST['contact_name'] ?? ''));
                $ph = trim((string) ($_POST['phone'] ?? ''));
                $em = trim((string) ($_POST['email'] ?? ''));
                mysqli_stmt_bind_param($st, 'isssssi', $fid, $code, $name, $cn, $ph, $em, $uid);
                if (mysqli_stmt_execute($st)) {
                    $_SESSION['proc_flash'] = 'Vendor created.';
                    hms_audit_log($connection, 'procurement.vendor.create', 'tbl_procurement_vendor', (int) mysqli_insert_id($connection));
                } else {
                    $_SESSION['proc_flash'] = 'Could not save (duplicate code?).';
                }
                mysqli_stmt_close($st);
            }
        }
        header('Location: procurement-vendors.php');
        exit;
    }
}

$rows = $ready ? hms_procurement_vendor_rows($connection, $fid, false) : [];

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module hms-procurement">
        <?php
        hms_ui_page_header('Vendors', [
            'subtitle' => 'Suppliers used for quotations, purchase orders, and invoices.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Procurement', 'procurement-home.php'], ['Vendors', '']],
            'secondary' => [['label' => 'Procurement hub', 'url' => 'procurement-home.php', 'icon' => 'fa-arrow-left']],
        ]);
        ?>
        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>
        <?php if (!$ready) { ?>
            <div class="alert alert-warning border-0 shadow-sm hms-proc-alert-migrate">Run migration 046 first.</div>
        <?php } else { ?>
        <?php if (hms_procurement_can_write($connection)) { ?>
        <div class="card border-0 shadow-sm mb-4 hms-proc-vendor-card">
            <div class="card-header bg-white">Add vendor</div>
            <div class="card-body">
                <form method="post" class="row">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="vendor_action" value="create">
                    <div class="col-md-3 mb-2">
                        <label class="small">Code</label>
                        <input type="text" name="vendor_code" class="form-control" maxlength="32" placeholder="Auto if blank">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="small">Name *</label>
                        <input type="text" name="name" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="small">Contact</label>
                        <input type="text" name="contact_name" class="form-control" maxlength="128">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="small">Phone</label>
                        <input type="text" name="phone" class="form-control" maxlength="64">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="small">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="160">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary font-weight-bold">Save vendor</button>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>
        <div class="hms-proc-table-shell">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Code</th><th>Name</th><th>Contact</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $vr) { ?>
                    <tr>
                        <td><span class="text-monospace font-weight-bold"><?php echo hms_h((string) ($vr['vendor_code'] ?? '')); ?></span></td>
                        <td class="font-weight-bold"><?php echo hms_h((string) ($vr['name'] ?? '')); ?></td>
                        <td class="small text-muted"><?php echo hms_h(trim((string) ($vr['contact_name'] ?? '') . ' ' . (string) ($vr['phone'] ?? ''))); ?></td>
                        <td><span class="hms-proc-badge <?php echo !empty($vr['is_active']) ? 'hms-proc-badge--ok' : 'hms-proc-badge--muted'; ?>"><?php echo !empty($vr['is_active']) ? 'Active' : 'Inactive'; ?></span></td>
                    </tr>
                    <?php } ?>
                    <?php if ($rows === []) { ?>
                    <tr><td colspan="4" class="hms-proc-empty border-0"><i class="fa fa-building-o" aria-hidden="true"></i>No vendors yet. Add your first supplier above.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
