<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_credit_require_read($connection);

$fid = hms_current_facility_id();
$rows = [];
if (hms_credit_tables_ok($connection)) {
    $q = mysqli_query(
        $connection,
        'SELECT ca.*, p.first_name, p.last_name,
            (SELECT COALESCE(SUM(amount),0) FROM tbl_charge WHERE credit_account_id = ca.id AND on_credit = 1)
             - (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_payment WHERE credit_account_id = ca.id)
             - (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_adjustment WHERE credit_account_id = ca.id)
            AS balance_calc
         FROM tbl_credit_account ca
         INNER JOIN tbl_patient p ON p.id = ca.patient_id
         WHERE ca.facility_id = ' . (int) $fid . "
         ORDER BY (ca.status = 'active') DESC, ca.opened_at DESC LIMIT 200"
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
}

include 'header.php';
?>
<div class="page-wrapper"><div class="content hms-module">
    <?php hms_ui_page_header('Credit & Receivables', [
        'subtitle' => 'Patient accounts for services rendered before payment. Follow up overdue balances from each account.',
        'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Credit & Receivables', '']],
        'back' => 'billing-payments.php',
        'primary' => hms_credit_can_write($connection) ? ['label' => 'Open account', 'href' => 'patients.php', 'icon' => 'fa-plus'] : null,
    ]); ?>

    <?php if (!hms_credit_tables_ok($connection)) { ?>
    <div class="alert alert-warning">Run migration <code>hms/database/migrations/019_credit_receivables.sql</code> to create credit tables.</div>
    <?php } else { ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center">
            <p class="mb-0 text-muted small">Open an account from a patient record, then use <strong>Post Charge</strong> with “on patient credit”. Payments issue a fiscal receipt and post to the financial journal (Debit cash/bank, Credit receivable).</p>
            <?php if (hms_credit_can_write($connection)) { ?>
            <a class="btn btn-outline-primary btn-sm mt-2 mt-md-0" href="patients.php"><i class="fa fa-user mr-1"></i>Pick patient to open credit</a>
            <?php } ?>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Account</th>
                            <th>Patient</th>
                            <th>Status</th>
                            <th class="text-right">Balance (<?php echo hms_h(hms_currency_label()); ?>)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []) { ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No credit accounts yet.</td></tr>
                        <?php } else { foreach ($rows as $r) {
                            $bal = round((float) ($r['balance_calc'] ?? 0), 2);
                            ?>
                        <tr>
                            <td>#<?php echo (int) $r['id']; ?></td>
                            <td><?php echo hms_h(trim((string) $r['first_name'] . ' ' . (string) $r['last_name'])); ?></td>
                            <td>
                                <span class="badge badge-<?php echo ($r['status'] ?? '') === 'active' ? 'primary' : 'secondary'; ?>"><?php echo hms_h((string) ($r['status'] ?? '')); ?></span>
                                <?php if (!empty($r['emergency_payment_pending'])) { ?><span class="badge badge-warning text-dark ml-1">Emergency pending</span><?php } ?>
                            </td>
                            <td class="text-right font-weight-semibold"><?php echo hms_h(number_format($bal, 0, '.', ' ')); ?></td>
                            <td class="text-right"><a class="btn btn-sm btn-outline-primary" href="credit-account.php?id=<?php echo (int) $r['id']; ?>">View</a></td>
                        </tr>
                        <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php } ?>
</div>
<?php include 'footer.php'; ?>
