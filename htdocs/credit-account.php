<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_credit_require_read($connection);

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$uid = (int) ($_SESSION['user_id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);
$flash = null;
$err = null;

if ($id < 1) {
    header('Location: credit-receivables.php');
    exit;
}

$st = mysqli_prepare(
    $connection,
    'SELECT ca.*, p.first_name, p.last_name, p.phone, p.email
     FROM tbl_credit_account ca
     INNER JOIN tbl_patient p ON p.id = ca.patient_id
     WHERE ca.id = ? AND ca.facility_id = ? LIMIT 1'
);
mysqli_stmt_bind_param($st, 'ii', $id, $fid);
mysqli_stmt_execute($st);
$acct = hms_stmt_fetch_assoc($st);
mysqli_stmt_close($st);
if (!$acct) {
    header('Location: credit-receivables.php');
    exit;
}

$pid = (int) $acct['patient_id'];
$snap = hms_credit_balance_snapshot($connection, $id);

if (isset($_POST['record_payment']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_credit_require_write($connection);
    $amt = (float) ($_POST['pay_amount'] ?? 0);
    $pm = (string) ($_POST['pay_method'] ?? 'Cash');
    $note = (string) ($_POST['pay_notes'] ?? '');
    $planId = (int) ($_POST['installment_plan_id'] ?? 0);
    $res = hms_credit_record_payment($connection, $fid, $id, $pid, $ms, $amt, $pm, $note !== '' ? $note : null, $planId > 0 ? $planId : null, $uid);
    if ($res['ok']) {
        $flash = $res['message'];
        if (!empty($res['doc_id']) && function_exists('hms_billing_set_print_prompt')) {
            hms_billing_set_print_prompt((int) $res['doc_id']);
        }
    } else {
        $err = $res['message'];
    }
    $snap = hms_credit_balance_snapshot($connection, $id);
}

if (isset($_POST['create_plan']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_credit_require_write($connection);
    $title = (string) ($_POST['plan_title'] ?? '');
    $cnt = (int) ($_POST['plan_count'] ?? 0);
    $each = (float) ($_POST['plan_each'] ?? 0);
    $due = (string) ($_POST['plan_first_due'] ?? '');
    $pn = (string) ($_POST['plan_notes'] ?? '');
    $res = hms_credit_create_installment_plan($connection, $id, $title, $cnt, $each, $due, $pn !== '' ? $pn : null, $uid);
    if ($res['ok']) {
        $flash = $res['message'];
    } else {
        $err = (string) ($res['message'] ?? 'Plan failed');
    }
}

if (isset($_POST['log_followup']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_credit_require_write($connection);
    $ch = substr((string) ($_POST['fu_channel'] ?? 'note'), 0, 32);
    $sum = trim((string) ($_POST['fu_summary'] ?? ''));
    if ($sum !== '') {
        $ins = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_credit_followup (credit_account_id, channel, summary, created_by) VALUES (?,?,?,?)'
        );
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'issi', $id, $ch, $sum, $uid);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
            $flash = 'Follow-up logged.';
        }
    }
}

if (isset($_POST['set_collections']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_credit_require_write($connection);
    $up = mysqli_prepare(
        $connection,
        "UPDATE tbl_credit_account SET status = 'collections' WHERE id = ? AND facility_id = ? AND status = 'active' LIMIT 1"
    );
    if ($up) {
        mysqli_stmt_bind_param($up, 'ii', $id, $fid);
        mysqli_stmt_execute($up);
        mysqli_stmt_close($up);
        $flash = 'Account flagged for collections.';
        $acct['status'] = 'collections';
    }
}

if (isset($_POST['record_writeoff']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_credit_require_write($connection);
    $wamt = (float) ($_POST['wo_amount'] ?? 0);
    $wn = (string) ($_POST['wo_notes'] ?? '');
    $res = hms_credit_record_writeoff($connection, $id, $wamt, $uid, $wn !== '' ? $wn : null);
    if ($res['ok']) {
        $flash = $res['message'];
    } else {
        $err = (string) ($res['message'] ?? 'Write-off failed');
    }
    $snap = hms_credit_balance_snapshot($connection, $id);
    $st2 = mysqli_prepare(
        $connection,
        'SELECT ca.*, p.first_name, p.last_name, p.phone, p.email
         FROM tbl_credit_account ca INNER JOIN tbl_patient p ON p.id = ca.patient_id
         WHERE ca.id = ? AND ca.facility_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($st2, 'ii', $id, $fid);
    mysqli_stmt_execute($st2);
    $acct = hms_stmt_fetch_assoc($st2) ?: $acct;
    mysqli_stmt_close($st2);
}

$charges = [];
$qc = mysqli_query(
    $connection,
    'SELECT id, description, amount, posted_at, on_credit FROM tbl_charge WHERE facility_id = ' . (int) $fid . ' AND patient_id = ' . (int) $pid . ' AND credit_account_id = ' . (int) $id . ' ORDER BY posted_at DESC LIMIT 100'
);
while ($qc && $c = mysqli_fetch_assoc($qc)) {
    $charges[] = $c;
}

$payments = [];
$qp = mysqli_query(
    $connection,
    'SELECT * FROM tbl_credit_payment WHERE credit_account_id = ' . (int) $id . ' ORDER BY created_at DESC LIMIT 100'
);
while ($qp && $p = mysqli_fetch_assoc($qp)) {
    $payments[] = $p;
}

$plans = [];
if (hms_db_table_exists($connection, 'tbl_credit_installment_plan')) {
    $qz = mysqli_query($connection, 'SELECT * FROM tbl_credit_installment_plan WHERE credit_account_id = ' . (int) $id . ' ORDER BY id DESC');
    while ($qz && $z = mysqli_fetch_assoc($qz)) {
        $plans[] = $z;
    }
}

$followups = [];
$qf = mysqli_query($connection, 'SELECT * FROM tbl_credit_followup WHERE credit_account_id = ' . (int) $id . ' ORDER BY created_at DESC LIMIT 50');
while ($qf && $f = mysqli_fetch_assoc($qf)) {
    $followups[] = $f;
}

include 'header.php';
$bal = $snap['balance'] ?? 0.0;
?>
<div class="page-wrapper"><div class="content hms-module">
    <?php hms_ui_page_header('Credit account #' . $id, [
        'subtitle' => trim((string) $acct['first_name'] . ' ' . (string) $acct['last_name']),
        'breadcrumbs' => [['Credit & Receivables', 'credit-receivables.php'], ['Account', '']],
        'back' => 'credit-receivables.php',
    ]); ?>

    <?php if ($flash) { ?><div class="alert alert-success"><?php echo hms_h($flash); ?></div><?php } ?>
    <?php if ($err) { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><strong>Summary</strong></div>
                <div class="card-body">
                    <p class="mb-1"><strong>Status:</strong> <?php echo hms_h((string) $acct['status']); ?></p>
                    <p class="mb-1"><strong>Outstanding:</strong> <?php echo hms_h(number_format($bal, 0, '.', ' ')); ?> <?php echo hms_h(hms_currency_label()); ?></p>
                    <p class="mb-1"><strong>Aging (days):</strong> <?php echo (int) ($snap['aging_days'] ?? 0); ?></p>
                    <?php if (!empty($acct['guarantor_name'])) { ?>
                    <p class="mb-1"><strong>Guarantor:</strong> <?php echo hms_h((string) $acct['guarantor_name']); ?>
                        <?php if (!empty($acct['guarantor_phone'])) { ?> · <?php echo hms_h((string) $acct['guarantor_phone']); ?><?php } ?>
                    </p>
                    <?php } ?>
                    <?php if (!empty($acct['notes'])) { ?>
                    <p class="small text-muted mb-0"><?php echo nl2br(hms_h((string) $acct['notes'])); ?></p>
                    <?php } ?>
                    <hr>
                    <p class="small text-muted mb-0">Suggested follow-up: reminders at <strong>7 / 14 / 30</strong> days, escalate to collections after <strong>60</strong> days, write-off only with approval (e.g. <strong>90+</strong> days).</p>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-4">
            <?php if (hms_credit_can_write($connection) && ($acct['status'] ?? '') === 'active' && $bal > 0.02) { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Record payment</strong></div>
                <div class="card-body">
                    <form method="post" class="mb-0">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="pay_amount">Amount</label>
                                <input class="form-control" id="pay_amount" name="pay_amount" type="number" step="1" min="1" max="<?php echo (int) ceil($bal); ?>" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="pay_method">Method</label>
                                <select class="form-control" id="pay_method" name="pay_method">
                                    <?php foreach (hms_billing_payment_method_options() as $pm) { ?>
                                    <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="installment_plan_id">Link to plan</label>
                                <select class="form-control" id="installment_plan_id" name="installment_plan_id">
                                    <option value="0">— None —</option>
                                    <?php foreach ($plans as $pl) { if (($pl['status'] ?? '') !== 'active') { continue; } ?>
                                    <option value="<?php echo (int) $pl['id']; ?>"><?php echo hms_h((string) $pl['title'] . ' #' . (int) $pl['id']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="pay_notes">Notes</label>
                            <input class="form-control" id="pay_notes" name="pay_notes" maxlength="600">
                        </div>
                        <button type="submit" name="record_payment" value="1" class="btn btn-primary">Save payment &amp; receipt</button>
                    </form>
                </div>
            </div>
            <?php } ?>

            <?php if (hms_credit_can_write($connection) && ($acct['status'] ?? '') === 'active' && $bal > 0.02) { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Installment plan</strong></div>
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="plan_title">Title</label>
                                <input class="form-control" id="plan_title" name="plan_title" placeholder="e.g. 3-month plan">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="plan_count"># Payments</label>
                                <input class="form-control" id="plan_count" name="plan_count" type="number" min="1" max="60" value="3">
                            </div>
                            <div class="form-group col-md-3">
                                <label for="plan_each">Each (<?php echo hms_h(hms_currency_label()); ?>)</label>
                                <input class="form-control" id="plan_each" name="plan_each" type="number" step="1" min="1">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="plan_first_due">First due date</label>
                            <input class="form-control" id="plan_first_due" name="plan_first_due" type="date" required value="<?php echo hms_h(date('Y-m-d', strtotime('+7 days'))); ?>">
                        </div>
                        <div class="form-group">
                            <label for="plan_notes">Notes</label>
                            <input class="form-control" id="plan_notes" name="plan_notes" maxlength="600">
                        </div>
                        <button type="submit" name="create_plan" value="1" class="btn btn-outline-primary">Create plan</button>
                    </form>
                </div>
            </div>
            <?php } ?>

            <?php if (hms_credit_can_write($connection) && ($acct['status'] ?? '') === 'active' && (int) ($snap['aging_days'] ?? 0) >= 60) { ?>
            <form method="post" class="mb-3"><?php echo hms_csrf_field(); ?>
                <button type="submit" name="set_collections" value="1" class="btn btn-warning text-dark">Escalate to collections</button>
            </form>
            <?php } ?>

            <?php if (hms_credit_can_write($connection) && $bal > 0.02 && in_array($acct['status'] ?? '', ['active', 'collections'], true)) { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Write-off (with approval)</strong></div>
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="wo_amount">Amount</label>
                                <input class="form-control" id="wo_amount" name="wo_amount" type="number" step="1" min="1" max="<?php echo (int) ceil($bal); ?>" required>
                            </div>
                            <div class="form-group col-md-8">
                                <label for="wo_notes">Approval note</label>
                                <input class="form-control" id="wo_notes" name="wo_notes" required maxlength="600" placeholder="Director approval ref., reason, etc.">
                            </div>
                        </div>
                        <button type="submit" name="record_writeoff" value="1" class="btn btn-outline-danger">Record write-off</button>
                    </form>
                </div>
            </div>
            <?php } ?>

            <?php if (hms_credit_can_write($connection)) { ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Follow-up log</strong></div>
                <div class="card-body">
                    <form method="post" class="mb-3">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="fu_channel">Channel</label>
                                <select class="form-control" id="fu_channel" name="fu_channel">
                                    <option value="note">Note</option>
                                    <option value="sms">SMS</option>
                                    <option value="email">Email</option>
                                    <option value="call">Call</option>
                                    <option value="escalate">Escalate</option>
                                </select>
                            </div>
                            <div class="form-group col-md-8">
                                <label for="fu_summary">Summary</label>
                                <input class="form-control" id="fu_summary" name="fu_summary" required maxlength="600" placeholder="e.g. Day-7 reminder sent">
                            </div>
                        </div>
                        <button type="submit" name="log_followup" value="1" class="btn btn-sm btn-secondary">Log entry</button>
                    </form>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($followups as $fu) { ?>
                        <li class="mb-2"><span class="text-muted"><?php echo hms_h((string) $fu['created_at']); ?></span>
                            <span class="badge badge-light border"><?php echo hms_h((string) $fu['channel']); ?></span>
                            <?php echo hms_h((string) $fu['summary']); ?></li>
                        <?php } ?>
                        <?php if ($followups === []) { ?><li class="text-muted">No entries yet.</li><?php } ?>
                    </ul>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>On-credit charges</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>Description</th><th class="text-right">Amount</th></tr></thead>
                            <tbody>
                                <?php foreach ($charges as $c) {
                                    if ((int) ($c['on_credit'] ?? 0) !== 1) {
                                        continue;
                                    } ?>
                                <tr>
                                    <td><?php echo hms_h((string) $c['posted_at']); ?></td>
                                    <td><?php echo hms_h((string) $c['description']); ?></td>
                                    <td class="text-right"><?php echo hms_h(number_format((float) $c['amount'], 0, '.', ' ')); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><strong>Payments</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>Method</th><th class="text-right">Amount</th><th>Receipt</th></tr></thead>
                            <tbody>
                                <?php foreach ($payments as $p) { ?>
                                <tr>
                                    <td><?php echo hms_h((string) $p['created_at']); ?></td>
                                    <td><?php echo hms_h((string) $p['payment_method']); ?></td>
                                    <td class="text-right"><?php echo hms_h(number_format((float) $p['amount'], 0, '.', ' ')); ?></td>
                                    <td><?php
                                    $bd = (int) ($p['billing_document_id'] ?? 0);
                                    echo $bd > 0 ? '<a href="receipts-invoices.php">#' . (int) $bd . '</a>' : '—';
                                ?></td>
                                </tr>
                                <?php } ?>
                                <?php if ($payments === []) { ?><tr><td colspan="4" class="text-muted text-center py-3">No payments yet.</td></tr><?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="mb-0"><a href="edit-patient.php?id=<?php echo (int) $pid; ?>">← Back to patient</a></p>
</div>
<?php
include 'footer.php';
if ($flash) {
    hms_ui_flash_toast_script($flash);
} elseif ($err) {
    hms_ui_flash_toast_script($err);
}
?>
