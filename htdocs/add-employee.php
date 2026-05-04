<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/employee_department.php';
if (empty($_SESSION['name']) || !function_exists('hms_staff_is_deploy_admin') || !hms_staff_is_deploy_admin()) {
    header('Location: index.php');
    exit;
}

$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$deptPick = hms_employee_department_names_for_facility($connection, $fid, $ms);

include 'header.php';

if (isset($_REQUEST['add-employee'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $first_name = (string) ($_REQUEST['first_name'] ?? '');
    $last_name = (string) ($_REQUEST['last_name'] ?? '');
    $username = (string) ($_REQUEST['username'] ?? '');
    $emailid = (string) ($_REQUEST['emailid'] ?? '');
    $pwd = (string) ($_REQUEST['pwd'] ?? '');
    $dob = (string) ($_REQUEST['dob'] ?? '');
    $employee_id = (string) ($_REQUEST['employee_id'] ?? '');
    $joining_date = (string) ($_REQUEST['joining_date'] ?? '');
    $gender = (string) ($_REQUEST['gender'] ?? '');
    $phone = (string) ($_REQUEST['phone'] ?? '');
    $address = hms_cameroon_address_from_request($_REQUEST);
    $bio = (string) ($_REQUEST['bio'] ?? '');
    $primary_department = trim((string) ($_REQUEST['primary_department'] ?? ''));
    $role = (int) ($_REQUEST['role'] ?? 0);
    $status = (int) ($_REQUEST['status'] ?? 1);
    $pwdHash = hms_hash_password($pwd);

    $stmt = null;
    if ($role === 99 && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
        $msg = 'Only the Super Admin account may assign role Super Admin.';
    } elseif ($hasEmployeeDeptCol) {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_employee (first_name, last_name, username, emailid, password, dob, employee_id, joining_date, gender, address, phone, bio, primary_department, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssssssssii',
                $first_name,
                $last_name,
                $username,
                $emailid,
                $pwdHash,
                $dob,
                $employee_id,
                $joining_date,
                $gender,
                $address,
                $phone,
                $bio,
                $primary_department,
                $role,
                $status
            );
        }
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_employee (first_name, last_name, username, emailid, password, dob, employee_id, joining_date, gender, address, phone, bio, role, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'ssssssssssssii',
                $first_name,
                $last_name,
                $username,
                $emailid,
                $pwdHash,
                $dob,
                $employee_id,
                $joining_date,
                $gender,
                $address,
                $phone,
                $bio,
                $role,
                $status
            );
        }
    }
    if ($stmt) {
        if (mysqli_stmt_execute($stmt)) {
            $newId = (int) mysqli_insert_id($connection);
            hms_assign_employee_to_facility($connection, $newId, $fid, true);
            $msg = 'Employee created successfully';
            hms_audit_log($connection, 'employee.create', 'employee', $newId);
        } else {
            $msg = 'Error!';
        }
        mysqli_stmt_close($stmt);
    } else {
        $msg = 'Error!';
    }
    }
}
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Add employee', [
                    'subtitle' => 'Creates a staff account and links them to the current site.',
                    'breadcrumbs' => [['Employees', 'employees.php'], ['Add', null]],
                    'back' => 'employees.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-9 col-lg-11">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Profile</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_fn">First name <span class="hms-required">*</span></label>
                                                <input id="emp_fn" class="form-control" type="text" name="first_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_ln">Last name <span class="hms-required">*</span></label>
                                                <input id="emp_ln" class="form-control" type="text" name="last_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_un">Username <span class="hms-required">*</span></label>
                                                <input id="emp_un" class="form-control" type="text" name="username" required autocomplete="username">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_em">Email <span class="hms-required">*</span></label>
                                                <input id="emp_em" class="form-control" type="email" name="emailid" required autocomplete="email">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_pw">Password <span class="hms-required">*</span></label>
                                                <input id="emp_pw" class="form-control" type="password" name="pwd" required autocomplete="new-password">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_dob">Date of birth <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="emp_dob" class="form-control datetimepicker" type="text" name="dob" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="emp_gm" type="radio" name="gender" class="form-check-input" value="Male" required>
                                                    <label class="form-check-label" for="emp_gm">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="emp_gf" type="radio" name="gender" class="form-check-input" value="Female">
                                                    <label class="form-check-label" for="emp_gf">Female</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Employment</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_eid">Employee ID <span class="hms-required">*</span></label>
                                                <input id="emp_eid" type="text" class="form-control" name="employee_id" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_join">Joining date <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="emp_join" class="form-control datetimepicker" type="text" name="joining_date" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_ph">Phone <span class="hms-required">*</span></label>
                                                <input id="emp_ph" class="form-control" type="text" name="phone" required autocomplete="tel">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_role">Role <span class="hms-required">*</span></label>
                                                <select id="emp_role" class="select" name="role" required>
                                                    <option value="">Select</option>
                                                    <?php
                                                    $fetch_query = mysqli_query($connection, 'SELECT title, role FROM tbl_role');
                                                    while ($fetch_query && $role = mysqli_fetch_array($fetch_query)) {
                                                        $rOpt = (int) $role['role'];
                                                        if ($rOpt === 99 && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
                                                            continue;
                                                        }
                                                        echo '<option value="' . hms_h((string) $role['role']) . '">' . hms_h((string) $role['title']) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <?php if ($hasEmployeeDeptCol) { ?>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_dept">Department</label>
                                                <select id="emp_dept" name="primary_department" class="form-control">
                                                    <option value="">— Not set —</option>
                                                    <?php foreach ($deptPick as $dn) {
                                                        echo '<option value="' . hms_h($dn) . '">' . hms_h($dn) . '</option>';
                                                    } ?>
                                                </select>
                                                <small class="form-text text-muted">Optional. Matches hospital departments; if you mention a department by name in the short biography below, you can align this field the same way.</small>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="emp_bio">Short biography <span class="hms-required">*</span></label>
                                                <textarea id="emp_bio" class="form-control" rows="3" name="bio" required></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Account status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="employee_active" value="1" checked>
                                            <label class="form-check-label" for="employee_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="employee_inactive" value="0">
                                            <label class="form-check-label" for="employee_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="employees.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="add-employee">Create employee</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

<?php
    include 'footer.php';
    hms_ui_flash_toast_script(isset($msg) ? (string) $msg : null);
?>