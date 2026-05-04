<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');

$fid = hms_current_facility_id();
$empId = (int) ($_SESSION['user_id'] ?? 0);
$hrOk = hms_hr_tables_ok($connection);
$msg = '';
$err = '';

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif ($empId < 1) {
        $err = 'Session error.';
    } else {
        $type = trim((string) ($_POST['leave_type'] ?? 'annual'));
        $start = (string) ($_POST['start_date'] ?? '');
        $end = (string) ($_POST['end_date'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($start === '' || $end === '' || $reason === '') {
            $err = 'Please complete all fields.';
        } else {
            try {
                $d1 = new DateTimeImmutable($start);
                $d2 = new DateTimeImmutable($end);
            } catch (Exception $e) {
                $d1 = null;
                $d2 = null;
            }
            if (!$d1 || !$d2 || $d2 < $d1) {
                $err = 'Invalid date range.';
            } else {
                $days = (float) ($d1->diff($d2)->days + 1);
                $stmt = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_hms_leave_request (facility_id, employee_id, leave_type, start_date, end_date, days_requested, reason) VALUES (?,?,?,?,?,?,?)'
                );
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'iisssds', $fid, $empId, $type, $start, $end, $days, $reason);
                    if (mysqli_stmt_execute($stmt)) {
                        $msg = 'Leave request submitted.';
                    } else {
                        $err = 'Could not save request.';
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Request leave', [
                'subtitle' => 'Submit a leave request for approval.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Request leave', null]],
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040_hr_payroll_leave_attendance.sql</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <div class="form-group"><label>Leave type</label>
                            <select name="leave_type" class="form-control" required>
                                <option value="annual">Annual leave</option>
                                <option value="sick">Sick leave</option>
                                <option value="unpaid">Unpaid leave</option>
                                <option value="maternity">Maternity leave</option>
                            </select></div>
                        <div class="form-group"><label>Start date</label>
                            <input type="date" name="start_date" class="form-control" required></div>
                        <div class="form-group"><label>End date</label>
                            <input type="date" name="end_date" class="form-control" required></div>
                        <div class="form-group"><label>Reason</label>
                            <textarea name="reason" class="form-control" rows="3" required></textarea></div>
                        <button type="submit" class="btn btn-primary">Submit</button>
                    </form>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
