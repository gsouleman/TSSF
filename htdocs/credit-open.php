<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_credit_require_write($connection);

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$pid = (int) ($_GET['patient_id'] ?? $_POST['patient_id'] ?? 0);
$msg = null;
$err = null;

if ($pid < 1) {
    header('Location: patients.php');
    exit;
}

if ($ms) {
    $st = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'ii', $pid, $fid);
} else {
    $st = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $pid);
}
mysqli_stmt_execute($st);
$pat = hms_stmt_fetch_assoc($st);
mysqli_stmt_close($st);
if (!$pat) {
    header('Location: patients.php');
    exit;
}

if (isset($_POST['open_credit']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $em = !empty($_POST['emergency_payment_pending']);
    $gName = (string) ($_POST['guarantor_name'] ?? '');
    $gPhone = (string) ($_POST['guarantor_phone'] ?? '');
    $gRel = (string) ($_POST['guarantor_relation'] ?? '');
    $notes = (string) ($_POST['notes'] ?? '');
    $res = hms_credit_open_account(
        $connection,
        $fid,
        $pid,
        $ms,
        $em,
        $gName !== '' ? $gName : null,
        $gPhone !== '' ? $gPhone : null,
        $gRel !== '' ? $gRel : null,
        $notes !== '' ? $notes : null,
        (int) ($_SESSION['user_id'] ?? 0)
    );
    if ($res['ok']) {
        header('Location: credit-account.php?id=' . (int) ($res['id'] ?? 0));
        exit;
    }
    $err = (string) ($res['message'] ?? 'Could not open account.');
}

include 'header.php';
?>
<div class="page-wrapper"><div class="content hms-module">
    <?php hms_ui_page_header('Open credit account', [
        'subtitle' => 'Patient services can accrue here until billing collects payment.',
        'breadcrumbs' => [['Credit & Receivables', 'credit-receivables.php'], ['Open account', '']],
        'back' => 'credit-receivables.php',
    ]); ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <?php if ($err) { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-body">
                    <p class="mb-4"><strong>Patient:</strong> <?php echo hms_h(trim((string) $pat['first_name'] . ' ' . (string) $pat['last_name'])); ?></p>
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="patient_id" value="<?php echo (int) $pid; ?>">
                        <div class="custom-control custom-checkbox mb-3">
                            <input type="checkbox" class="custom-control-input" id="emergency_payment_pending" name="emergency_payment_pending" value="1">
                            <label class="custom-control-label" for="emergency_payment_pending">Emergency — payment pending (front desk flag)</label>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="guarantor_name">Guarantor name</label>
                                <input class="form-control" id="guarantor_name" name="guarantor_name" maxlength="220">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="guarantor_phone">Guarantor phone</label>
                                <input class="form-control" id="guarantor_phone" name="guarantor_phone" maxlength="64">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="guarantor_relation">Relation to patient</label>
                            <input class="form-control" id="guarantor_relation" name="guarantor_relation" maxlength="120" placeholder="e.g. Spouse, employer">
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="4000" placeholder="Billing instructions, insurance to file, etc."></textarea>
                        </div>
                        <div class="d-flex justify-content-end">
                            <a href="credit-receivables.php" class="btn btn-outline-secondary mr-2">Cancel</a>
                            <button type="submit" name="open_credit" value="1" class="btn btn-primary">Create account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
