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

$rows = null;
if ($hrOk && $empId > 0) {
    $rows = mysqli_query(
        $connection,
        'SELECT att_date, check_in_time, check_out_time, status FROM tbl_hms_attendance WHERE facility_id = ' . (int) $fid
        . ' AND employee_id = ' . (int) $empId . ' ORDER BY att_date DESC LIMIT 60'
    );
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('My attendance', [
                'subtitle' => 'Recent attendance records for this site.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['My attendance', null]],
            ]);
            ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040</code>.</div>
            <?php } else { ?>
            <div class="table-responsive card border-0 shadow-sm">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>Date</th><th>Check in</th><th>Check out</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php
                    if ($rows && mysqli_num_rows($rows) > 0) {
                        while ($a = mysqli_fetch_assoc($rows)) {
                            echo '<tr><td>' . hms_h((string) ($a['att_date'] ?? '')) . '</td><td>' . hms_h((string) ($a['check_in_time'] ?? '')) . '</td><td>'
                                . hms_h((string) ($a['check_out_time'] ?? '')) . '</td><td>' . hms_h(ucfirst((string) ($a['status'] ?? ''))) . '</td></tr>';
                        }
                    } else {
                        echo '<tr><td colspan="4" class="text-muted text-center">No records yet.</td></tr>';
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
