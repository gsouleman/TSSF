<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';
require_once __DIR__ . '/includes/hms_tax_payroll.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1 || !hms_tax_payroll_tables_ok($connection)) {
    http_response_code(404);
    exit('Not found');
}

$hasDept = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$deptSel = $hasDept ? ', e.primary_department' : '';
$stmt = mysqli_prepare(
    $connection,
    'SELECT p.*, e.first_name, e.last_name, e.employee_id, e.emailid' . $deptSel . '
     FROM tbl_hms_payroll_record p
     INNER JOIN tbl_employee e ON e.id = p.employee_id
     WHERE p.id = ? AND p.facility_id = ? LIMIT 1'
);
if (!$stmt) {
    http_response_code(500);
    exit('Error');
}
mysqli_stmt_bind_param($stmt, 'ii', $id, $fid);
mysqli_stmt_execute($stmt);
$row = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($stmt) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    http_response_code(404);
    exit('Not found');
}
$eid = (int) ($row['employee_id'] ?? 0);
if (!hms_hr_is_admin() && ($uid < 1 || $eid !== $uid)) {
    http_response_code(403);
    exit('Forbidden');
}

$b = (float) ($row['basic_salary_snap'] ?? 0);
$h = (float) ($row['housing_allowance_snap'] ?? 0);
$t = (float) ($row['transport_allowance_snap'] ?? 0);
$o = (float) ($row['other_allowances_snap'] ?? 0);
$dept = $hasDept ? trim((string) ($row['primary_department'] ?? '')) : '';
$cnpsLabel = 'Employee CNPS';
$month = (int) ($row['month'] ?? 1);
$year = (int) ($row['year'] ?? (int) date('Y'));
$period = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$ded = (float) ($row['cnps_employee'] ?? 0) + (float) ($row['cimr_employee'] ?? 0) + (float) ($row['crtv_deduction'] ?? 0)
    + (float) ($row['council_tax_deduction'] ?? 0) + (float) ($row['development_tax_deduction'] ?? 0) + (float) ($row['cnhc_deduction'] ?? 0) + (float) ($row['income_tax'] ?? 0);
$gross = (float) ($row['gross_salary'] ?? 0);
$cnpsEmployer = $gross * 5.6 / 100;
$cimrEmployer = $gross * 4.8 / 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip — <?php echo hms_h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))); ?></title>
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <style>
        body { font-family: "Segoe UI", system-ui, sans-serif; background: #f0f2f5; padding: 20px; }
        .payslip { max-width: 800px; margin: auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .ph { background: #2c3e50; color: #fff; padding: 20px; text-align: center; }
        .pt { background: #3498db; color: #fff; padding: 10px; text-align: center; font-weight: 600; }
        .pb { padding: 20px; }
        table.w { width: 100%; border-collapse: collapse; }
        table.w td, table.w th { padding: 8px; border-bottom: 1px solid #eee; }
        .ar { text-align: right; }
        .net { background: #e8f4f8; font-weight: bold; }
        .pf { text-align: center; padding: 12px; font-size: 12px; background: #ecf0f1; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="payslip">
    <div class="ph"><h3 class="mb-1">Solidarity of Hearts Hospital</h3><p class="mb-0 small">Payroll period: <?php echo hms_h($period); ?></p></div>
    <div class="pt">EMPLOYEE PAYSLIP</div>
    <div class="pb">
        <table class="w mb-3">
            <tr><td><strong>Name</strong></td><td><?php echo hms_h(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''))); ?></td>
                <td><strong>Matricule</strong></td><td><?php echo hms_h((string) ($row['employee_id'] ?? '')); ?></td></tr>
            <tr><td><strong>Department</strong></td><td><?php echo $dept !== '' ? hms_h($dept) : '—'; ?></td>
                <td><strong>Printed</strong></td><td><?php echo hms_h(date('d/m/Y')); ?></td></tr>
        </table>
        <h5 class="mb-2">Salary breakdown (XAF)</h5>
        <table class="w">
            <thead><tr><th>Earnings</th><th class="ar">Amount</th><th>Deductions</th><th class="ar">Amount</th></tr></thead>
            <tbody>
            <tr><td>Basic salary</td><td class="ar"><?php echo hms_h(number_format($b, 0, ',', ' ')); ?></td><td>CNPS (<?php echo hms_h($cnpsLabel); ?>)</td><td class="ar"><?php echo hms_h(number_format((float) ($row['cnps_employee'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td>Housing allowance</td><td class="ar"><?php echo hms_h(number_format($h, 0, ',', ' ')); ?></td><td>CIMR</td><td class="ar"><?php echo hms_h(number_format((float) ($row['cimr_employee'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td>Transport allowance</td><td class="ar"><?php echo hms_h(number_format($t, 0, ',', ' ')); ?></td><td>CRTV</td><td class="ar"><?php echo hms_h(number_format((float) ($row['crtv_deduction'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td>Other allowances</td><td class="ar"><?php echo hms_h(number_format($o, 0, ',', ' ')); ?></td><td>Council tax</td><td class="ar"><?php echo hms_h(number_format((float) ($row['council_tax_deduction'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td></td><td class="ar"></td><td>Development tax</td><td class="ar"><?php echo hms_h(number_format((float) ($row['development_tax_deduction'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td></td><td class="ar"></td><td>CNHC</td><td class="ar"><?php echo hms_h(number_format((float) ($row['cnhc_deduction'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr><td></td><td class="ar"></td><td>Income tax (IRPP)</td><td class="ar"><?php echo hms_h(number_format((float) ($row['income_tax'] ?? 0), 0, ',', ' ')); ?></td></tr>
            <tr class="net"><td><strong>Gross</strong></td><td class="ar"><strong><?php echo hms_h(number_format($gross, 0, ',', ' ')); ?></strong></td>
                <td><strong>Total deductions</strong></td><td class="ar"><strong><?php echo hms_h(number_format($ded, 0, ',', ' ')); ?></strong></td></tr>
            <tr class="net"><td colspan="3"><strong>Net pay (XAF)</strong></td><td class="ar"><strong><?php echo hms_h(number_format((float) ($row['net_salary'] ?? 0), 0, ',', ' ')); ?></strong></td></tr>
            </tbody>
        </table>
        <p class="small text-muted mt-3 mb-0">Illustrative employer shares (planning): CNPS <?php echo hms_h(number_format($cnpsEmployer, 0, ',', ' ')); ?> XAF · CIMR <?php echo hms_h(number_format($cimrEmployer, 0, ',', ' ')); ?> XAF</p>
    </div>
    <div class="pf">Computer-generated payslip. For queries, contact HR.</div>
</div>
<p class="text-center no-print mt-3">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
    <a href="payroll.php" class="btn btn-secondary">Back</a>
</p>
</body>
</html>
