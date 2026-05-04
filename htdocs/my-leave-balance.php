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
$year = max(2000, min(2100, (int) ($_GET['year'] ?? (int) date('Y'))));
$hrOk = hms_hr_tables_ok($connection);

$rows = null;
if ($hrOk && $empId > 0) {
    $rows = mysqli_query(
        $connection,
        'SELECT leave_type, balance FROM tbl_hms_leave_balance WHERE facility_id = ' . (int) $fid
        . ' AND employee_id = ' . (int) $empId . ' AND year = ' . (int) $year
        . ' ORDER BY leave_type'
    );
}

include 'header.php';
$cy = (int) date('Y');
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('My leave balance', [
                'subtitle' => 'Days remaining by leave type (HR must maintain balances).',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Leave balance', null]],
            ]);
            ?>
            <form method="get" class="form-inline mb-3">
                <label class="mr-2">Year</label>
                <select name="year" class="form-control" onchange="this.form.submit()"><?php
                for ($yy = $cy - 2; $yy <= $cy + 1; $yy++) {
                    echo '<option value="' . $yy . '"' . ($yy === $year ? ' selected' : '') . '>' . $yy . '</option>';
                }
                ?></select>
            </form>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040</code>.</div>
            <?php } elseif ($rows && mysqli_num_rows($rows) === 0) { ?>
            <div class="alert alert-info">No balances recorded for <?php echo (int) $year; ?>. Ask an administrator to set your leave balances.</div>
            <?php } else { ?>
            <div class="table-responsive card border-0 shadow-sm">
                <table class="table mb-0">
                    <thead class="thead-light"><tr><th>Leave type</th><th class="text-right">Balance (days)</th></tr></thead>
                    <tbody>
                    <?php
                    if ($rows) {
                        while ($b = mysqli_fetch_assoc($rows)) {
                            echo '<tr><td>' . hms_h(ucfirst((string) ($b['leave_type'] ?? ''))) . '</td><td class="text-right">'
                                . hms_h((string) ($b['balance'] ?? '0')) . '</td></tr>';
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
