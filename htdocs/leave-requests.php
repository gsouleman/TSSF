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
$uid = (int) ($_SESSION['user_id'] ?? 0);
$hrOk = hms_hr_tables_ok($connection);
$msg = '';

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['leave_action'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid token.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $act = (string) ($_POST['leave_action'] ?? '');
        if ($id > 0 && ($act === 'approve' || $act === 'reject')) {
            $st = (string) ($act === 'approve' ? 'approved' : 'rejected');
            $stmt = mysqli_prepare(
                $connection,
                'UPDATE tbl_hms_leave_request SET status = ?, approved_by = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'siii', $st, $uid, $id, $fid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $msg = 'Leave request updated.';
            }
        }
    }
}

$rows = null;
if ($hrOk) {
    $rows = mysqli_query(
        $connection,
        'SELECT l.*, e.first_name, e.last_name FROM tbl_hms_leave_request l '
        . 'JOIN tbl_employee e ON e.id = l.employee_id WHERE l.facility_id = ' . (int) $fid
        . ' ORDER BY l.created_at DESC LIMIT 200'
    );
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Leave requests', [
                'subtitle' => 'Approve or reject leave for staff at this site.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Leave requests', null]],
                'secondary' => [
                    ['label' => 'Leave balances', 'url' => 'leave-balances.php', 'icon' => 'fa-pie-chart', 'class' => 'btn-outline-secondary'],
                ],
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-info"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040_hr_payroll_leave_attendance.sql</code>.</div>
            <?php } else { ?>
            <div class="table-responsive card border-0 shadow-sm">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Reason</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php
                    if ($rows) {
                        while ($l = mysqli_fetch_assoc($rows)) {
                            $lid = (int) ($l['id'] ?? 0);
                            $st = (string) ($l['status'] ?? '');
                            $badge = $st === 'approved' ? 'success' : ($st === 'pending' ? 'warning' : 'secondary');
                            echo '<tr><td>' . hms_h(trim((string) ($l['first_name'] ?? '') . ' ' . (string) ($l['last_name'] ?? ''))) . '</td><td>'
                                . hms_h(ucfirst((string) ($l['leave_type'] ?? ''))) . '</td><td>' . hms_h((string) ($l['start_date'] ?? '')) . '</td><td>'
                                . hms_h((string) ($l['end_date'] ?? '')) . '</td><td>' . hms_h((string) ($l['days_requested'] ?? '')) . '</td><td class="small">'
                                . hms_h((string) ($l['reason'] ?? '')) . '</td><td><span class="badge badge-' . hms_h($badge) . '">'
                                . hms_h(ucfirst($st)) . '</span></td><td>';
                            if ($st === 'pending') {
                                echo '<form method="post" class="d-inline">' .
                                    '<input type="hidden" name="hms_csrf" value="' . hms_h(hms_csrf_token()) . '">' .
                                    '<input type="hidden" name="id" value="' . $lid . '">' .
                                    '<button type="submit" name="leave_action" value="approve" class="btn btn-sm btn-success">Approve</button> ' .
                                    '<button type="submit" name="leave_action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>' .
                                    '</form>';
                            } else {
                                echo '—';
                            }
                            echo '</td></tr>';
                        }
                    }
                    ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
