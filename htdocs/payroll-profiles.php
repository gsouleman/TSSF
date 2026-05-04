<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';
require_once __DIR__ . '/includes/hms_pay_profile_seed.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');
if (!hms_hr_is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$fid = hms_current_facility_id();
$hrOk = hms_hr_tables_ok($connection);
$msg = '';
$err = '';

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_profiles'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $staff = hms_hr_active_staff_for_facility($connection, $fid);
        $saved = 0;
        foreach ($staff as $em) {
            $eid = (int) ($em['id'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            $b = (float) ($_POST['basic'][$eid] ?? 0);
            $h = (float) ($_POST['housing'][$eid] ?? 0);
            $t = (float) ($_POST['transport'][$eid] ?? 0);
            $o = (float) ($_POST['responsibility'][$eid] ?? 0);
            $sql = 'INSERT INTO tbl_hms_pay_profile (facility_id, employee_id, basic_salary, housing_allowance, transport_allowance, other_allowances)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE basic_salary=VALUES(basic_salary), housing_allowance=VALUES(housing_allowance), transport_allowance=VALUES(transport_allowance), other_allowances=VALUES(other_allowances)';
            $st = mysqli_prepare($connection, $sql);
            if ($st) {
                mysqli_stmt_bind_param($st, 'iidddd', $fid, $eid, $b, $h, $t, $o);
                if (mysqli_stmt_execute($st)) {
                    $saved++;
                }
                mysqli_stmt_close($st);
            }
        }
        $msg = "Saved {$saved} profile row(s).";
    }
}

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['seed_pay_profiles_all']) || isset($_POST['seed_pay_profiles_empty_only']))) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $onlyEmpty = isset($_POST['seed_pay_profiles_empty_only']);
        $st = hms_pay_profile_seed_facility($connection, $fid, $onlyEmpty);
        $msg = 'Pay profile seed: ' . (int) $st['written'] . ' row(s) written to the database';
        if ((int) $st['skipped'] > 0) {
            $msg .= ', ' . (int) $st['skipped'] . ' skipped';
        }
        $msg .= $onlyEmpty ? ' (only rows with zero total gross).' : ' (all staff on this site).';
        $le = trim((string) ($st['last_error'] ?? ''));
        if ($le !== '' && (int) ($st['written'] ?? 0) === 0) {
            $err = 'Pay profile seed could not write rows: ' . $le;
            $msg = '';
        }
        if (function_exists('hms_audit_log')) {
            hms_audit_log($connection, 'pay_profile.seed', 'facility', $fid, $st);
        }
    }
}

$profiles = [];
if ($hrOk) {
    $staff = hms_hr_active_staff_for_facility($connection, $fid);
    foreach ($staff as $em) {
        $eid = (int) ($em['id'] ?? 0);
        $row = ['basic_salary' => 0.0, 'housing_allowance' => 0.0, 'transport_allowance' => 0.0, 'other_allowances' => 0.0];
        $st = mysqli_prepare(
            $connection,
            'SELECT basic_salary, housing_allowance, transport_allowance, other_allowances FROM tbl_hms_pay_profile WHERE facility_id = ? AND employee_id = ? LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $fid, $eid);
            mysqli_stmt_execute($st);
            $pr = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($st) : null;
            mysqli_stmt_close($st);
            if (is_array($pr)) {
                $row = $pr;
            }
        }
        $em['pay'] = $row;
        $profiles[] = $em;
    }
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Pay profiles', [
                'subtitle' => 'Basic salary and allowances used when running payroll for this site.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Payroll', 'payroll.php'], ['Pay profiles', null]],
                'back' => 'payroll.php',
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run <code>040_hr_payroll_leave_attendance.sql</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <h2 class="h6 font-weight-bold mb-2">Seed demo pay amounts</h2>
                    <p class="small text-muted mb-2">
                        Fills <strong>Basic</strong>, <strong>Housing</strong>, <strong>Transport</strong>, and <strong>Responsibility</strong> (stored as <code>other_allowances</code>) using role-based XAF bands.
                        Doctors vary by <code>bio</code> / <code>primary_department</code> keywords (e.g. cardiology, surgery, general medicine) and <code>joining_date</code> (experience).
                        Run payroll only after <a href="tax/settings.php">Tax → Payroll tax settings</a> exist for this site.
                    </p>
                    <form method="post" class="d-inline-block mr-2" onsubmit="return confirm('Replace pay profile numbers for every listed staff member on this site?');">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <button type="submit" name="seed_pay_profiles_all" value="1" class="btn btn-outline-primary btn-sm"><i class="fa fa-database mr-1"></i>Seed all (replace)</button>
                    </form>
                    <form method="post" class="d-inline-block" onsubmit="return confirm('Fill only profiles where Basic+Housing+Transport+Responsibility are all zero?');">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <button type="submit" name="seed_pay_profiles_empty_only" value="1" class="btn btn-outline-secondary btn-sm"><i class="fa fa-plus-circle mr-1"></i>Seed empty profiles only</button>
                    </form>
                </div>
            </div>
            <form method="post" class="card border-0 shadow-sm">
                <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                <input type="hidden" name="save_profiles" value="1">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light"><tr><th>Staff</th><th>Matricule</th><th>Staff role</th><th class="text-right">Basic</th><th class="text-right">Housing</th><th class="text-right">Transport</th><th class="text-right">Responsibility</th></tr></thead>
                        <tbody>
                        <?php foreach ($profiles as $em) {
                            $eid = (int) ($em['id'] ?? 0);
                            $p = $em['pay'] ?? [];
                            ?>
                        <tr>
                            <td><?php echo hms_h(trim((string) ($em['first_name'] ?? '') . ' ' . (string) ($em['last_name'] ?? ''))); ?></td>
                            <td><small><?php echo hms_h((string) ($em['employee_id'] ?? '')); ?></small></td>
                            <td class="align-middle"><?php echo hms_hr_staff_role_badge_html((string) ($em['role'] ?? '')); ?></td>
                            <td><input class="form-control form-control-sm text-right" name="basic[<?php echo $eid; ?>]" value="<?php echo hms_h((string) ($p['basic_salary'] ?? '0')); ?>"></td>
                            <td><input class="form-control form-control-sm text-right" name="housing[<?php echo $eid; ?>]" value="<?php echo hms_h((string) ($p['housing_allowance'] ?? '0')); ?>"></td>
                            <td><input class="form-control form-control-sm text-right" name="transport[<?php echo $eid; ?>]" value="<?php echo hms_h((string) ($p['transport_allowance'] ?? '0')); ?>"></td>
                            <td><input class="form-control form-control-sm text-right" name="responsibility[<?php echo $eid; ?>]" value="<?php echo hms_h((string) ($p['other_allowances'] ?? '0')); ?>"></td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary">Save all</button></div>
            </form>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
