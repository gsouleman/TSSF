<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name']) || (string) $_SESSION['role'] !== '1') {
    header('Location: index.php');
    exit;
}

include 'header.php';

$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$deptPick = [];
$dsuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
$ddq = mysqli_query($connection, 'SELECT department_name FROM tbl_department' . $dsuf . ' ORDER BY department_name');
while ($ddq && $drow = mysqli_fetch_assoc($ddq)) {
    $deptPick[] = (string) $drow['department_name'];
}

if (isset($_REQUEST['add-doctor'])) {
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
    $status = (int) ($_REQUEST['status'] ?? 1);
    $role = 2;
    $pwdHash = hms_hash_password($pwd);

    if ($hasEmployeeDeptCol) {
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
            $msg = 'Doctor created successfully';
            if (hms_doctor_photo_column_exists($connection)) {
                $savedPath = hms_doctor_photo_save_upload($_FILES['doc_photo'] ?? null, $newId);
                if ($savedPath !== null) {
                    $esc = mysqli_real_escape_string($connection, $savedPath);
                    mysqli_query($connection, 'UPDATE tbl_employee SET photo_path = \'' . $esc . '\' WHERE id = ' . $newId . ' LIMIT 1');
                } elseif (isset($_FILES['doc_photo']) && ($_FILES['doc_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $msg = 'Doctor created, but the photo was not saved (check file type JPEG/PNG/WebP and max 2 MB).';
                }
            }
            hms_audit_log($connection, 'doctor.create', 'employee', $newId);
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
                hms_ui_page_header('Add doctor', [
                    'subtitle' => 'Creates a clinician account and assigns them to the current site.',
                    'breadcrumbs' => [['Doctors', 'doctors.php'], ['Add', null]],
                    'back' => 'doctors.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-9 col-lg-11">
                        <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Profile</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_photo">Profile photo</label>
                                                <input id="doc_photo" class="form-control-file" type="file" name="doc_photo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                                                <small class="form-text text-muted">Optional. JPEG, PNG, or WebP — max 2 MB. A coloured placeholder is used if you skip this.</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6 d-flex align-items-end">
                                            <p class="small text-muted mb-3">Tip: square photos (e.g. 400×400) look best on doctor cards.</p>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_fn">First name <span class="hms-required">*</span></label>
                                                <input id="doc_fn" class="form-control" type="text" name="first_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_ln">Last name <span class="hms-required">*</span></label>
                                                <input id="doc_ln" class="form-control" type="text" name="last_name" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_un">Username <span class="hms-required">*</span></label>
                                                <input id="doc_un" class="form-control" type="text" name="username" required autocomplete="username">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_em">Email <span class="hms-required">*</span></label>
                                                <input id="doc_em" class="form-control" type="email" name="emailid" required autocomplete="email">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_pw">Password <span class="hms-required">*</span></label>
                                                <input id="doc_pw" class="form-control" type="password" name="pwd" required autocomplete="new-password">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_dob">Date of birth <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="doc_dob" type="text" class="form-control datetimepicker" name="dob" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="doc_gm" type="radio" name="gender" class="form-check-input" value="Male" required>
                                                    <label class="form-check-label" for="doc_gm">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="doc_gf" type="radio" name="gender" class="form-check-input" value="Female">
                                                    <label class="form-check-label" for="doc_gf">Female</label>
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
                                                <label for="doc_eid">Employee ID <span class="hms-required">*</span></label>
                                                <input id="doc_eid" class="form-control" type="text" name="employee_id" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_join">Joining date <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="doc_join" type="text" class="form-control datetimepicker" name="joining_date" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_ph">Phone <span class="hms-required">*</span></label>
                                                <input id="doc_ph" class="form-control" type="text" name="phone" required autocomplete="tel">
                                            </div>
                                        </div>
                                        <?php if ($hasEmployeeDeptCol) { ?>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_primary_dept">Primary department</label>
                                                <select id="doc_primary_dept" name="primary_department" class="form-control">
                                                    <option value="">— Not set —</option>
                                                    <?php foreach ($deptPick as $dn) {
                                                        echo '<option value="' . hms_h($dn) . '">' . hms_h($dn) . '</option>';
                                                    } ?>
                                                </select>
                                                <small class="form-text text-muted">Used to filter this doctor when a department is chosen for visits or appointments.</small>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="doc_bio">Short biography <span class="hms-required">*</span></label>
                                                <textarea id="doc_bio" class="form-control" rows="3" name="bio" required></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Account status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="doctor_active" value="1" checked>
                                            <label class="form-check-label" for="doctor_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="doctor_inactive" value="0">
                                            <label class="form-check-label" for="doctor_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="doctors.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" name="add-doctor" class="btn btn-primary mb-2 mb-sm-0">Create doctor</button>
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