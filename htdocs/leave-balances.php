<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';

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
$year = max(2000, min(2100, (int) ($_GET['year'] ?? (int) date('Y'))));

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_balances'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid token.';
    } else {
        $y = max(2000, min(2100, (int) ($_POST['year'] ?? $year)));
        $staff = hms_hr_active_staff_for_facility($connection, $fid);
        foreach ($staff as $em) {
            $eid = (int) ($em['id'] ?? 0);
            if ($eid < 1) {
                continue;
            }
            foreach (['annual', 'sick', 'maternity'] as $lt) {
                $key = 'bal_' . $eid . '_' . $lt;
                if (!isset($_POST[$key])) {
                    continue;
                }
                $bal = (float) ($_POST[$key]);
                $stmt = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_hms_leave_balance (facility_id, employee_id, leave_type, year, balance) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE balance = VALUES(balance)'
                );
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iisid', $fid, $eid, $lt, $y, $bal);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
        }
        $msg = 'Leave balances saved.';
        $year = $y;
    }
}

$staff = $hrOk ? hms_hr_active_staff_for_facility($connection, $fid) : [];
$balances = [];
if ($hrOk) {
    $q = mysqli_query(
        $connection,
        'SELECT employee_id, leave_type, balance FROM tbl_hms_leave_balance WHERE facility_id = ' . (int) $fid . ' AND year = ' . (int) $year
    );
    if ($q) {
        while ($b = mysqli_fetch_assoc($q)) {
            $balances[(int) ($b['employee_id'] ?? 0) . '|' . (string) ($b['leave_type'] ?? '')] = (float) ($b['balance'] ?? 0);
        }
    }
}

include 'header.php';
$cy = (int) date('Y');
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Leave balances', [
                'subtitle' => 'Set annual / sick / maternity day balances per staff member.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Leave requests', 'leave-requests.php'], ['Balances', null]],
                'back' => 'leave-requests.php',
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <form method="get" class="form-inline mb-3">
                <label class="mr-2">Year</label>
                <select name="year" class="form-control" onchange="this.form.submit()"><?php
                for ($yy = $cy - 1; $yy <= $cy + 2; $yy++) {
                    echo '<option value="' . $yy . '"' . ($yy === $year ? ' selected' : '') . '>' . $yy . '</option>';
                }
                ?></select>
            </form>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040</code>.</div>
            <?php } else { ?>
            <form method="post" class="card border-0 shadow-sm">
                <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                <input type="hidden" name="save_balances" value="1">
                <input type="hidden" name="year" value="<?php echo (int) $year; ?>">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light"><tr><th>Staff</th><th class="text-right">Annual</th><th class="text-right">Sick</th><th class="text-right">Maternity</th></tr></thead>
                        <tbody>
                        <?php foreach ($staff as $em) {
                            $eid = (int) ($em['id'] ?? 0);
                            $ba = (float) ($balances[$eid . '|annual'] ?? 0);
                            $bs = (float) ($balances[$eid . '|sick'] ?? 0);
                            $bm = (float) ($balances[$eid . '|maternity'] ?? 0);
                            ?>
                        <tr>
                            <td><?php echo hms_h(trim((string) ($em['first_name'] ?? '') . ' ' . (string) ($em['last_name'] ?? ''))); ?></td>
                            <td><input class="form-control form-control-sm text-right" name="bal_<?php echo $eid; ?>_annual" value="<?php echo hms_h((string) $ba); ?>"></td>
                            <td><input class="form-control form-control-sm text-right" name="bal_<?php echo $eid; ?>_sick" value="<?php echo hms_h((string) $bs); ?>"></td>
                            <td><input class="form-control form-control-sm text-right" name="bal_<?php echo $eid; ?>_maternity" value="<?php echo hms_h((string) $bm); ?>"></td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer"><button type="submit" class="btn btn-primary">Save balances</button></div>
            </form>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
