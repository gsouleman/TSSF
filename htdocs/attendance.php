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
$today = (string) ($_POST['attendance_date'] ?? $_GET['date'] ?? date('Y-m-d'));

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_attendance'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid token.';
    } else {
        $date = (string) ($_POST['attendance_date'] ?? '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $msg = 'Invalid date.';
        } else {
            $statuses = $_POST['status'] ?? [];
            $ins = $_POST['check_in'] ?? [];
            $outs = $_POST['check_out'] ?? [];
            if (is_array($statuses)) {
                foreach ($statuses as $eid => $stat) {
                    $eid = (int) $eid;
                    if ($eid < 1) {
                        continue;
                    }
                    $stat = preg_replace('/[^a-z\-]/', '', strtolower((string) $stat)) ?: 'present';
                    $cin = trim((string) ($ins[$eid] ?? ''));
                    $cout = trim((string) ($outs[$eid] ?? ''));
                    $cinSql = $cin === '' ? null : $cin;
                    $coutSql = $cout === '' ? null : $cout;
                    $stmt = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_hms_attendance (facility_id, employee_id, att_date, check_in_time, check_out_time, status)
                         VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE check_in_time = VALUES(check_in_time), check_out_time = VALUES(check_out_time), status = VALUES(status)'
                    );
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, 'ii' . 'ssss', $fid, $eid, $date, $cinSql, $coutSql, $stat);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }
            }
            $msg = 'Attendance saved for ' . $date . '.';
            $today = $date;
        }
    }
}

$staff = $hrOk ? hms_hr_active_staff_for_facility($connection, $fid) : [];
$existing = [];
if ($hrOk && $today !== '') {
    $esc = mysqli_real_escape_string($connection, $today);
    $q = mysqli_query(
        $connection,
        'SELECT employee_id, check_in_time, check_out_time, status FROM tbl_hms_attendance WHERE facility_id = ' . (int) $fid . " AND att_date = '" . $esc . "'"
    );
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $existing[(int) ($r['employee_id'] ?? 0)] = $r;
        }
    }
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Attendance', [
                'subtitle' => 'Record check-in / check-out and status for each staff member.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Attendance', null]],
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040</code>.</div>
            <?php } else { ?>
            <form method="post" class="card border-0 shadow-sm">
                <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                <input type="hidden" name="save_attendance" value="1">
                <div class="card-body">
                    <div class="form-row align-items-end mb-3">
                        <div class="form-group col-md-3">
                            <label>Date</label>
                            <input type="date" name="attendance_date" class="form-control" value="<?php echo hms_h($today); ?>" required>
                        </div>
                        <div class="form-group col-md-3">
                            <button type="submit" class="btn btn-primary">Save attendance</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr><th>Employee</th><th>Check in</th><th>Check out</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($staff as $em) {
                                $eid = (int) ($em['id'] ?? 0);
                                $ex = $existing[$eid] ?? [];
                                $ci = (string) ($ex['check_in_time'] ?? '08:00:00');
                                $co = (string) ($ex['check_out_time'] ?? '17:00:00');
                                if (strlen($ci) > 5) {
                                    $ci = substr($ci, 0, 5);
                                }
                                if (strlen($co) > 5) {
                                    $co = substr($co, 0, 5);
                                }
                                $st = (string) ($ex['status'] ?? 'present');
                                ?>
                            <tr>
                                <td><?php echo hms_h(trim((string) ($em['first_name'] ?? '') . ' ' . (string) ($em['last_name'] ?? ''))); ?></td>
                                <td><input type="time" class="form-control form-control-sm" name="check_in[<?php echo $eid; ?>]" value="<?php echo hms_h($ci); ?>"></td>
                                <td><input type="time" class="form-control form-control-sm" name="check_out[<?php echo $eid; ?>]" value="<?php echo hms_h($co); ?>"></td>
                                <td>
                                    <select class="form-control form-control-sm" name="status[<?php echo $eid; ?>]">
                                        <option value="present"<?php echo $st === 'present' ? ' selected' : ''; ?>>Present</option>
                                        <option value="absent"<?php echo $st === 'absent' ? ' selected' : ''; ?>>Absent</option>
                                        <option value="late"<?php echo $st === 'late' ? ' selected' : ''; ?>>Late</option>
                                        <option value="half-day"<?php echo $st === 'half-day' ? ' selected' : ''; ?>>Half day</option>
                                    </select>
                                </td>
                            </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
