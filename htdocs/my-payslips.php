<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_tax_payroll.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');

$fid = hms_current_facility_id();
$empId = (int) ($_SESSION['user_id'] ?? 0);
$ok = hms_tax_payroll_tables_ok($connection);

$rows = null;
if ($ok && $empId > 0) {
    $rows = mysqli_query(
        $connection,
        'SELECT id, month, year, gross_salary, net_salary FROM tbl_hms_payroll_record WHERE facility_id = ' . (int) $fid
        . ' AND employee_id = ' . (int) $empId . ' ORDER BY year DESC, month DESC LIMIT 36'
    );
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('My payslips', [
                'subtitle' => 'Payroll history for your account at this site.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['My payslips', null]],
            ]);
            ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>039</code>.</div>
            <?php } else { ?>
            <div class="table-responsive card border-0 shadow-sm">
                <table class="table table-sm mb-0">
                    <thead class="thead-light"><tr><th>Period</th><th>Gross</th><th>Net</th><th></th></tr></thead>
                    <tbody>
                    <?php
                    if ($rows && mysqli_num_rows($rows) > 0) {
                        while ($p = mysqli_fetch_assoc($rows)) {
                            $pid = (int) ($p['id'] ?? 0);
                            $m = (int) ($p['month'] ?? 1);
                            $lab = date('F Y', mktime(0, 0, 0, $m, 1, (int) ($p['year'] ?? date('Y'))));
                            echo '<tr><td>' . hms_h($lab) . '</td><td>' . hms_h(number_format((float) ($p['gross_salary'] ?? 0), 0, ',', ' ')) . ' XAF</td><td><strong>'
                                . hms_h(number_format((float) ($p['net_salary'] ?? 0), 0, ',', ' ')) . '</strong> XAF</td><td>'
                                . '<a class="btn btn-sm btn-outline-primary" href="generate-payslip.php?id=' . $pid . '" target="_blank">View</a></td></tr>';
                        }
                    } else {
                        echo '<tr><td colspan="4" class="text-muted text-center">No payslips yet.</td></tr>';
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
