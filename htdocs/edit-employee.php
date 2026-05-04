<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/employee_department.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: employees.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_employee WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: employees.php');
    exit;
}

$hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));

$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$deptPick = hms_employee_department_names_for_facility($connection, $fid, $ms);
$storedDept = $hasEmployeeDeptCol ? trim((string) ($row['primary_department'] ?? '')) : '';
$bioText = (string) ($row['bio'] ?? '');
$deptGuessFromBio = ($hasEmployeeDeptCol && $storedDept === '') ? hms_employee_guess_department_from_bio($connection, $fid, $ms, $bioText) : '';
$deptSelectValue = $storedDept !== '' ? $storedDept : $deptGuessFromBio;
$deptSuggestedFromBio = $hasEmployeeDeptCol && $storedDept === '' && $deptGuessFromBio !== '';

if (isset($_REQUEST['save-emp'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $first_name = (string) ($_REQUEST['first_name'] ?? '');
    $last_name = (string) ($_REQUEST['last_name'] ?? '');
    $username = (string) ($_REQUEST['username'] ?? '');
    $emailid = (string) ($_REQUEST['emailid'] ?? '');
    $pwdInput = trim((string) ($_REQUEST['pwd'] ?? ''));
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

    $passToStore = $pwdInput !== '' ? hms_hash_password($pwdInput) : (string) $row['password'];

    $upd = null;
    $curRole = (int) ($row['role'] ?? 0);
    if ($role === 99 && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
        $msg = 'Only the Super Admin account may assign role Super Admin.';
    } elseif ($curRole === 99 && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
        $msg = 'Only the Super Admin account may edit this user.';
    } elseif ($hasEmployeeDeptCol) {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_employee SET first_name=?, last_name=?, username=?, emailid=?, password=?, dob=?, employee_id=?, joining_date=?, gender=?, address=?, phone=?, bio=?, primary_department=?, role=?, status=? WHERE id=?'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'sssssssssssssiii',
                $first_name,
                $last_name,
                $username,
                $emailid,
                $passToStore,
                $dob,
                $employee_id,
                $joining_date,
                $gender,
                $address,
                $phone,
                $bio,
                $primary_department,
                $role,
                $status,
                $id
            );
        }
    } elseif (!$hasEmployeeDeptCol) {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_employee SET first_name=?, last_name=?, username=?, emailid=?, password=?, dob=?, employee_id=?, joining_date=?, gender=?, address=?, phone=?, bio=?, role=?, status=? WHERE id=?'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'ssssssssssssiii',
                $first_name,
                $last_name,
                $username,
                $emailid,
                $passToStore,
                $dob,
                $employee_id,
                $joining_date,
                $gender,
                $address,
                $phone,
                $bio,
                $role,
                $status,
                $id
            );
        }
    }
    if ($upd) {
        if (mysqli_stmt_execute($upd)) {
            $msg = 'Employee updated successfully';
            $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_employee WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt2, 'i', $id);
            mysqli_stmt_execute($stmt2);
            $fetched = hms_stmt_fetch_assoc($stmt2);
            if ($fetched !== null) {
                $row = $fetched;
                $hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));
                $storedDept = $hasEmployeeDeptCol ? trim((string) ($row['primary_department'] ?? '')) : '';
                $bioText = (string) ($row['bio'] ?? '');
                $deptGuessFromBio = ($hasEmployeeDeptCol && $storedDept === '') ? hms_employee_guess_department_from_bio($connection, $fid, $ms, $bioText) : '';
                $deptSelectValue = $storedDept !== '' ? $storedDept : $deptGuessFromBio;
                $deptSuggestedFromBio = $hasEmployeeDeptCol && $storedDept === '' && $deptGuessFromBio !== '';
            }
            mysqli_stmt_close($stmt2);
        } else {
            $msg = 'Error!';
        }
        mysqli_stmt_close($upd);
    } else {
        $msg = 'Error!';
    }
    }
}

?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Edit employee', [
                    'subtitle' => 'User #' . (int) $id,
                    'breadcrumbs' => [['Employees', 'employees.php'], ['Edit', null]],
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
                                                <input id="emp_fn" class="form-control" type="text" name="first_name" value="<?php echo hms_h((string) $row['first_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_ln">Last name <span class="hms-required">*</span></label>
                                                <input id="emp_ln" class="form-control" type="text" name="last_name" value="<?php echo hms_h((string) $row['last_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_un">Username <span class="hms-required">*</span></label>
                                                <input id="emp_un" class="form-control" type="text" name="username" value="<?php echo hms_h((string) $row['username']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_em">Email <span class="hms-required">*</span></label>
                                                <input id="emp_em" class="form-control" type="email" name="emailid" value="<?php echo hms_h((string) $row['emailid']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_pw">Password</label>
                                                <input id="emp_pw" class="form-control" type="password" name="pwd" value="" placeholder="Leave blank to keep current password" autocomplete="new-password">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_dob">Date of birth <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="emp_dob" class="form-control datetimepicker" type="text" name="dob" required value="<?php echo hms_h((string) $row['dob']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="emp_gm" type="radio" name="gender" class="form-check-input" value="Male" <?php echo ($row['gender'] === 'Male') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="emp_gm">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="emp_gf" type="radio" name="gender" class="form-check-input" value="Female" <?php echo ($row['gender'] === 'Female') ? 'checked' : ''; ?>>
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
                                                <label for="emp_eid">Employee ID</label>
                                                <input id="emp_eid" class="form-control" type="text" name="employee_id" value="<?php echo hms_h((string) $row['employee_id']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_join">Joining date</label>
                                                <div class="cal-icon">
                                                    <input id="emp_join" type="text" class="form-control datetimepicker" name="joining_date" value="<?php echo hms_h((string) $row['joining_date']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_ph">Phone</label>
                                                <input id="emp_ph" class="form-control" type="text" name="phone" value="<?php echo hms_h((string) $row['phone']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emp_role">Role</label>
                                                <select id="emp_role" class="select" name="role">
                                                    <option value="">Select</option>
                                                    <?php
                                                    $fetch_query = mysqli_query($connection, 'SELECT title, role FROM tbl_role');
                                                    $curRole = (int) $row['role'];
                                                    while ($fetch_query && $role = mysqli_fetch_array($fetch_query)) {
                                                        $r = (int) $role['role'];
                                                        if ($r === 99 && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())) {
                                                            continue;
                                                        }
                                                        $sel = $r === $curRole ? ' selected' : '';
                                                        echo '<option value="' . (int) $r . '"' . $sel . '>' . hms_h((string) $role['title']) . '</option>';
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
                                                        $sel = ($deptSelectValue === $dn) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($dn) . '"' . $sel . '>' . hms_h($dn) . '</option>';
                                                    } ?>
                                                </select>
                                                <?php if ($deptSuggestedFromBio) { ?>
                                                <small class="form-text text-info">Pre-selected from your short biography (not saved until you click Save).</small>
                                                <?php } else { ?>
                                                <small class="form-text text-muted">When no value is stored yet, we pre-select a department if its name appears in the short biography below.</small>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="emp_bio">Short biography</label>
                                                <textarea id="emp_bio" class="form-control" rows="3" name="bio"><?php echo hms_h((string) $row['bio']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Account status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="employee_active" value="1" <?php echo ((int) $row['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="employee_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="employee_inactive" value="0" <?php echo ((int) $row['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="employee_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                if (function_exists('hms_show_access_control_nav') && hms_show_access_control_nav($connection)) { ?>
                                <div class="hms-form-section border-top pt-3">
                                    <h2 class="hms-form-section-title">Portal access</h2>
                                    <p class="text-muted small mb-2">Assign which staff portals this user may open (Front Desk, Doctors, Lab, and others).</p>
                                    <a class="btn btn-outline-primary btn-sm font-weight-bold" href="access-control-portals.php?employee_id=<?php echo (int) $id; ?>">Open in Access Control</a>
                                </div>
                                <?php } ?>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="employees.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="save-emp">Save changes</button>
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