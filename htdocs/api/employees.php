<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');
include 'header.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_employee'])
    && function_exists('hms_staff_is_deploy_admin') && hms_staff_is_deploy_admin()) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        $stChk = mysqli_prepare($connection, 'SELECT role FROM tbl_employee WHERE id = ? LIMIT 1');
        $blockDel = false;
        if ($stChk) {
            mysqli_stmt_bind_param($stChk, 'i', $delId);
            mysqli_stmt_execute($stChk);
            $rDel = hms_stmt_fetch_assoc($stChk);
            mysqli_stmt_close($stChk);
            if ($rDel && (int) ($rDel['role'] ?? 0) === 99
                && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
                $blockDel = true;
            }
        }
        if ($blockDel) {
            header('Location: employees.php');
            exit;
        }
        $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_employee WHERE id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $delId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        hms_audit_log($connection, 'employee.delete', 'employee', $delId);
    }
    header('Location: employees.php');
    exit;
}

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$hasDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                $eh = ['subtitle' => 'Staff directory for the active site.'];
                if (function_exists('hms_staff_is_deploy_admin') && hms_staff_is_deploy_admin()) {
                    $eh['primary'] = ['label' => 'Add employee', 'url' => 'add-employee.php', 'icon' => 'fa-id-badge'];
                }
                hms_ui_page_header('Employees', $eh);
                if (function_exists('hms_staff_is_deploy_admin') && hms_staff_is_deploy_admin()) { ?>
                <p class="small text-muted mb-3"><a href="backfill-employee-departments-random.php">Assign random departments</a> to doctors, nurses, and nursing aids who do not have one yet (uses this site’s department list).</p>
                <?php } ?>
                <div class="card border-0 shadow-sm hms-data-card">
                    <div class="card-body p-0">
                <div class="table-responsive">
                                    <table class="datatable table table-stripped hms-table-actions">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Mobile</th>
                                            <th>Join Date</th>
                                            <?php if ($hasDeptCol) { ?>
                                            <th>Department</th>
                                            <?php } ?>
                                            <th>Role</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $deptSel = $hasDeptCol ? ', e.primary_department' : '';
                                        if ($ms) {
                                            $fetch_query = mysqli_query(
                                                $connection,
                                                'SELECT DISTINCT e.id, e.first_name, e.last_name, e.username, e.emailid, e.phone, e.joining_date, e.role' . $deptSel . '
                                                 FROM tbl_employee e
                                                 INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                                                 WHERE uf.facility_id = ' . (int) $fid . ' ORDER BY e.id'
                                            );
                                        } else {
                                            $fetch_query = mysqli_query($connection, 'SELECT id, first_name, last_name, username, emailid, phone, joining_date, role' . ($hasDeptCol ? ', primary_department' : '') . ' FROM tbl_employee ORDER BY id');
                                        }
                                        while ($fetch_query && $row = mysqli_fetch_array($fetch_query)) {
                                        ?>
                                        <tr>
                                            <td><?php echo hms_h($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                            <td><?php echo hms_h((string) $row['username']); ?></td>
                                            <td><?php echo hms_h((string) $row['emailid']); ?></td>
                                            <td><?php echo hms_h((string) $row['phone']); ?></td>
                                            <td><?php echo hms_h((string) $row['joining_date']); ?></td>
                                            <?php if ($hasDeptCol) {
                                                $pd = trim((string) ($row['primary_department'] ?? ''));
                                                ?>
                                            <td class="small text-muted"><?php echo $pd !== '' ? hms_h($pd) : '—'; ?></td>
                                            <?php } ?>
                                            <td>
                                                 <?php
                                                 $roleLabels = [
                                                     '1' => ['Admin',          'status-grey'],
                                                     '2' => ['Doctor',         'status-red'],
                                                     '3' => ['Front Desk',     'status-blue'],
                                                     '4' => ['Lab Technician', 'status-purple'],
                                                     '5' => ['Pharmacist',     'status-green'],
                                                     '6' => ['Radiology Tech', 'status-blue'],
                                                     '7' => ['Nurse',          'status-red'],
                                                     '8' => ['Nursing Aid',    'status-green'],
                                                 ];
                                                 $roleKey = (string)$row['role'];
                                                 if (isset($roleLabels[$roleKey])) {
                                                     [$rLabel, $rClass] = $roleLabels[$roleKey];
                                                     echo '<span class="custom-badge '.$rClass.'">'.hms_h($rLabel).'</span>';
                                                 } else {
                                                     echo hms_h((string)$row['role']);
                                                 }
                                                 ?>
                                                 </td>
                                            <td class="text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a class="dropdown-item" href="edit-employee.php?id=<?php echo (int) $row['id']; ?>"><i class="fa fa-pencil m-r-5"></i> Edit</a>
                                                    <?php if ($_SESSION['role'] == 1) { ?>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this employee?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_employee" value="1">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger border-0 bg-transparent p-0 m-0 w-100 text-left"><i class="fa fa-trash-o m-r-5"></i> Delete</button>
                                                    </form>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                    </div>
                </div>

            </div>

        </div>

<?php
include 'footer.php';
?>
