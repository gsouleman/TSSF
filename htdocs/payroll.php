<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';
require_once __DIR__ . '/includes/hms_payroll_cameroon.php';
require_once __DIR__ . '/includes/hms_tax_payroll.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$hrOk = hms_hr_tables_ok($connection) && hms_tax_payroll_tables_ok($connection);
$msg = '';
$err = '';

if (hms_hr_is_admin() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['process_payroll'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (!$hrOk) {
        $err = 'Run migrations 039 and 040.';
    } else {
        $month = max(1, min(12, (int) ($_POST['month'] ?? 1)));
        $year = max(2000, min(2100, (int) ($_POST['year'] ?? (int) date('Y'))));
        $staff = hms_hr_active_staff_for_facility($connection, $fid);
        $processed = 0;
        $skipped = 0;
        foreach ($staff as $em) {
            $eid = (int) ($em['id'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $st = mysqli_prepare(
                $connection,
                'SELECT basic_salary, housing_allowance, transport_allowance, other_allowances FROM tbl_hms_pay_profile WHERE facility_id = ? AND employee_id = ? LIMIT 1'
            );
            $b = 0.0;
            $h = 0.0;
            $t = 0.0;
            $o = 0.0;
            if ($st) {
                mysqli_stmt_bind_param($st, 'ii', $fid, $eid);
                mysqli_stmt_execute($st);
                $pr = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($st) : null;
                mysqli_stmt_close($st);
                if (is_array($pr)) {
                    $b = (float) ($pr['basic_salary'] ?? 0);
                    $h = (float) ($pr['housing_allowance'] ?? 0);
                    $t = (float) ($pr['transport_allowance'] ?? 0);
                    $o = (float) ($pr['other_allowances'] ?? 0);
                }
            }
            $gross = $b + $h + $t + $o;
            if ($gross <= 0) {
                continue;
            }
            $chk = mysqli_prepare(
                $connection,
                'SELECT id FROM tbl_hms_payroll_record WHERE facility_id = ? AND employee_id = ? AND year = ? AND month = ? LIMIT 1'
            );
            $exists = false;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'iiii', $fid, $eid, $year, $month);
                mysqli_stmt_execute($chk);
                mysqli_stmt_store_result($chk);
                $exists = mysqli_stmt_num_rows($chk) > 0;
                mysqli_stmt_close($chk);
            }
            if ($exists) {
                $skipped++;

                continue;
            }
            $tax = hms_payroll_cameroon_calculate($connection, $fid, $year, $gross);
            if ($tax === null) {
                $err = 'Configure payroll tax rates under Tax → Payroll tax settings for this site.';

                break;
            }
            $ins = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_hms_payroll_record (
                    facility_id, employee_id, year, month,
                    gross_salary, cnps_employee, cimr_employee, crtv_deduction, council_tax_deduction, development_tax_deduction, cnhc_deduction,
                    taxable_income, income_tax, net_salary,
                    basic_salary_snap, housing_allowance_snap, transport_allowance_snap, other_allowances_snap,
                    payout_status
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            if ($ins) {
                $pending = 'pending';
                $tg = (float) $tax['cnps_employee'];
                $tc = (float) $tax['cimr_employee'];
                $tx = (float) $tax['crtv_deduction'];
                $tcou = (float) $tax['council_tax_deduction'];
                $tdev = (float) $tax['development_tax_deduction'];
                $tcn = (float) $tax['cnhc_deduction'];
                $ttax = (float) $tax['taxable_income'];
                $tir = (float) $tax['income_tax'];
                $tn = (float) $tax['net_salary'];
                mysqli_stmt_bind_param(
                    $ins,
                    'iiii' . str_repeat('d', 14) . 's',
                    $fid,
                    $eid,
                    $year,
                    $month,
                    $gross,
                    $tg,
                    $tc,
                    $tx,
                    $tcou,
                    $tdev,
                    $tcn,
                    $ttax,
                    $tir,
                    $tn,
                    $b,
                    $h,
                    $t,
                    $o,
                    $pending
                );
                if (mysqli_stmt_execute($ins)) {
                    $processed++;
                }
                mysqli_stmt_close($ins);
            }
        }
        if ($err === '') {
            $msg = "Payroll run: {$processed} new record(s). Skipped (already exists): {$skipped}.";
        }
    }
}

if (hms_hr_is_admin() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_payroll_paid'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (!$hrOk || !hms_db_column_exists($connection, 'tbl_hms_payroll_record', 'payout_status')) {
        $err = 'Payroll tables or payout status column missing. Run migration 040.';
    } else {
        $rid = (int) ($_POST['payroll_record_id'] ?? 0);
        if ($rid < 1) {
            $err = 'Invalid payroll record.';
        } else {
            $paid = 'paid';
            $pend = 'pending';
            $st = mysqli_prepare(
                $connection,
                'UPDATE tbl_hms_payroll_record SET payout_status = ? WHERE id = ? AND facility_id = ? AND payout_status = ? LIMIT 1'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'siis', $paid, $rid, $fid, $pend);
                if (mysqli_stmt_execute($st) && mysqli_stmt_affected_rows($st) > 0) {
                    $msg = 'Payout status set to Paid for this line.';
                    if (function_exists('hms_audit_log')) {
                        hms_audit_log($connection, 'payroll.mark_paid', 'payroll_record', $rid, ['facility_id' => $fid]);
                    }
                } else {
                    $err = 'Could not update (record missing, wrong site, or not pending).';
                }
                mysqli_stmt_close($st);
            } else {
                $err = 'Database error.';
            }
        }
    }
}

if (hms_hr_is_admin() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_payroll_month_paid'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (!$hrOk || !hms_db_column_exists($connection, 'tbl_hms_payroll_record', 'payout_status')) {
        $err = 'Payroll tables or payout status column missing. Run migration 040.';
    } else {
        $mMark = max(1, min(12, (int) ($_POST['mark_month'] ?? 1)));
        $yMark = max(2000, min(2100, (int) ($_POST['mark_year'] ?? (int) date('Y'))));
        $paid = 'paid';
        $pend = 'pending';
        $st = mysqli_prepare(
            $connection,
            'UPDATE tbl_hms_payroll_record SET payout_status = ? WHERE facility_id = ? AND year = ? AND month = ? AND payout_status = ?'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'siiis', $paid, $fid, $yMark, $mMark, $pend);
            if (mysqli_stmt_execute($st)) {
                $n = (int) mysqli_stmt_affected_rows($st);
                $periodLab = date('F Y', mktime(0, 0, 0, $mMark, 1, $yMark));
                $msg = $n > 0
                    ? "Marked {$n} payroll line(s) as Paid for {$periodLab}."
                    : 'No pending lines for that period on this site.';
                if ($n > 0 && function_exists('hms_audit_log')) {
                    hms_audit_log($connection, 'payroll.mark_month_paid', 'facility', $fid, ['year' => $yMark, 'month' => $mMark, 'rows' => $n]);
                }
            } else {
                $err = 'Could not update payroll lines.';
            }
            mysqli_stmt_close($st);
        } else {
            $err = 'Database error.';
        }
    }
}

$rows = null;
if ($hrOk && hms_tax_payroll_tables_ok($connection)) {
    $q = 'SELECT p.*, e.first_name, e.last_name FROM tbl_hms_payroll_record p '
        . 'JOIN tbl_employee e ON e.id = p.employee_id WHERE p.facility_id = ' . (int) $fid;
    if (!hms_hr_is_admin()) {
        $q .= ' AND p.employee_id = ' . (int) $uid;
    }
    $q .= ' ORDER BY p.year DESC, p.month DESC, e.last_name LIMIT 500';
    $rows = mysqli_query($connection, $q);
}

include 'header.php';
$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$cy = (int) date('Y');
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Payroll', [
                'subtitle' => 'Monthly payroll from pay profiles and Cameroon tax settings.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Payroll', null]],
                'secondary' => hms_hr_is_admin()
                    ? [['label' => 'Pay profiles', 'url' => 'payroll-profiles.php', 'icon' => 'fa-user', 'class' => 'btn-outline-secondary']]
                    : [],
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run <code>039_tax_payroll_cnps_dipe.sql</code> and <code>040_hr_payroll_leave_attendance.sql</code>.</div>
            <?php } elseif (!hms_hr_is_admin()) { ?>
            <div class="alert alert-info">Only administrators can run payroll. Use <strong>My payslips</strong> to view your history.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header font-weight-bold">Process payroll (month)</div>
                <div class="card-body">
                    <form method="post" class="form-row align-items-end">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <input type="hidden" name="process_payroll" value="1">
                        <div class="form-group col-md-3">
                            <label>Month</label>
                            <select name="month" class="form-control"><?php foreach ($months as $n => $lab) {
                                echo '<option value="' . (int) $n . '">' . hms_h($lab) . '</option>';
                            } ?></select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Year</label>
                            <select name="year" class="form-control"><?php
                            for ($y = $cy - 1; $y <= $cy + 1; $y++) {
                                echo '<option value="' . $y . '"' . ($y === $cy ? ' selected' : '') . '>' . $y . '</option>';
                            }
                            ?></select>
                        </div>
                        <div class="form-group col-md-4">
                            <button type="submit" class="btn btn-primary">Calculate &amp; save (new rows only)</button>
                            <p class="small text-muted mb-0 mt-2">Uses <a href="tax/settings.php">Tax → Payroll tax settings</a> and <a href="payroll-profiles.php">pay profiles</a>. Skips staff with no pay profile or zero gross.</p>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header font-weight-bold">Mark payouts as paid</div>
                <div class="card-body">
                    <p class="small text-muted mb-3">After salaries are disbursed, mark lines so the status is no longer <strong>pending</strong>. You can update one month at a time or use <strong>Mark paid</strong> on each row below.</p>
                    <form method="post" class="form-row align-items-end" onsubmit="return confirm('Mark every pending payroll line for this site and period as Paid?');">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <input type="hidden" name="mark_payroll_month_paid" value="1">
                        <div class="form-group col-md-3">
                            <label>Month</label>
                            <select name="mark_month" class="form-control"><?php foreach ($months as $n => $lab) {
                                echo '<option value="' . (int) $n . '"' . ($n === (int) date('n') ? ' selected' : '') . '>' . hms_h($lab) . '</option>';
                            } ?></select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Year</label>
                            <select name="mark_year" class="form-control"><?php
                            for ($y = $cy - 1; $y <= $cy + 1; $y++) {
                                echo '<option value="' . $y . '"' . ($y === $cy ? ' selected' : '') . '>' . $y . '</option>';
                            }
                            ?></select>
                        </div>
                        <div class="form-group col-md-4">
                            <button type="submit" class="btn btn-success">Mark all pending in period as paid</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>

            <div class="card border-0 shadow-sm">
                <div class="card-header font-weight-bold">Recent payroll lines (this site)</div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Employee</th><th>Period</th><th>Gross</th><th>IRPP</th><th>Net</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        <?php
                        if ($rows) {
                            while ($r = mysqli_fetch_assoc($rows)) {
                                $mid = (int) ($r['month'] ?? 0);
                                $pid = (int) ($r['id'] ?? 0);
                                $eidRow = (int) ($r['employee_id'] ?? 0);
                                $canPayslip = hms_hr_is_admin() || ($uid > 0 && $eidRow === $uid);
                                $paySt = strtolower(trim((string) ($r['payout_status'] ?? 'pending')));
                                if ($paySt === 'paid') {
                                    $stBadge = '<span class="badge badge-success">Paid</span>';
                                } elseif ($paySt === 'pending') {
                                    $stBadge = '<span class="badge badge-secondary">Pending</span>';
                                } else {
                                    $stBadge = '<span class="badge badge-light text-dark">' . hms_h($paySt !== '' ? $paySt : '—') . '</span>';
                                }
                                echo '<tr><td>' . hms_h(trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''))) . '</td><td>'
                                    . hms_h(($months[$mid] ?? '') . ' ' . (string) ($r['year'] ?? '')) . '</td><td>'
                                    . hms_h(number_format((float) ($r['gross_salary'] ?? 0), 0, ',', ' ')) . '</td><td>'
                                    . hms_h(number_format((float) ($r['income_tax'] ?? 0), 0, ',', ' ')) . '</td><td><strong>'
                                    . hms_h(number_format((float) ($r['net_salary'] ?? 0), 0, ',', ' ')) . '</strong></td><td>'
                                    . $stBadge . '</td><td class="text-right text-nowrap">';
                                if ($canPayslip) {
                                    echo '<a class="btn btn-sm btn-outline-primary" href="generate-payslip.php?id=' . $pid . '" target="_blank">Payslip</a> ';
                                }
                                if (hms_hr_is_admin() && $paySt === 'pending' && $pid > 0 && $hrOk) {
                                    echo '<form method="post" class="d-inline-block" onsubmit="return confirm(\'Mark this line as paid?\');">'
                                        . '<input type="hidden" name="hms_csrf" value="' . hms_h(hms_csrf_token()) . '">'
                                        . '<input type="hidden" name="mark_payroll_paid" value="1">'
                                        . '<input type="hidden" name="payroll_record_id" value="' . $pid . '">'
                                        . '<button type="submit" class="btn btn-sm btn-outline-success">Mark paid</button></form>';
                                }
                                if (!$canPayslip && !hms_hr_is_admin()) {
                                    echo '—';
                                }
                                echo '</td></tr>';
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
