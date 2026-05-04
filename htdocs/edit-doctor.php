<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: doctors.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_employee WHERE id = ? AND role = 2 LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: doctors.php');
    exit;
}

$hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));

$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$deptPick = [];
$dsuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
$ddq = mysqli_query($connection, 'SELECT department_name FROM tbl_department' . $dsuf . ' ORDER BY department_name');
while ($ddq && $drow = mysqli_fetch_assoc($ddq)) {
    $deptPick[] = (string) $drow['department_name'];
}

if (isset($_REQUEST['save-doc'])) {
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
    $status = (int) ($_REQUEST['status'] ?? 1);

    $passToStore = $pwdInput !== '' ? hms_hash_password($pwdInput) : (string) $row['password'];

    if ($hasEmployeeDeptCol) {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_employee SET first_name=?, last_name=?, username=?, emailid=?, password=?, dob=?, employee_id=?, joining_date=?, gender=?, address=?, phone=?, bio=?, primary_department=?, status=? WHERE id=? AND role=2'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'sssssssssssssii',
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
                $status,
                $id
            );
        }
    } else {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_employee SET first_name=?, last_name=?, username=?, emailid=?, password=?, dob=?, employee_id=?, joining_date=?, gender=?, address=?, phone=?, bio=?, status=? WHERE id=? AND role=2'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'ssssssssssssii',
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
                $status,
                $id
            );
        }
    }
    if ($upd) {
        if (mysqli_stmt_execute($upd)) {
            $msg = 'Doctor updated successfully';
            if (hms_doctor_photo_column_exists($connection)) {
                $oldPath = trim((string) ($row['photo_path'] ?? ''));
                if (!empty($_REQUEST['remove_photo'])) {
                    hms_doctor_photo_delete_uploaded_file($oldPath);
                    mysqli_query($connection, 'UPDATE tbl_employee SET photo_path = NULL WHERE id = ' . (int) $id . ' AND role = 2 LIMIT 1');
                    $oldPath = '';
                }
                $newPhoto = hms_doctor_photo_save_upload($_FILES['doc_photo'] ?? null, $id);
                if ($newPhoto !== null) {
                    hms_doctor_photo_delete_uploaded_file($oldPath);
                    $esc = mysqli_real_escape_string($connection, $newPhoto);
                    mysqli_query($connection, 'UPDATE tbl_employee SET photo_path = \'' . $esc . '\' WHERE id = ' . (int) $id . ' AND role = 2 LIMIT 1');
                } elseif (isset($_FILES['doc_photo']) && ($_FILES['doc_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $msg = 'Doctor saved, but the new photo was not accepted (use JPEG/PNG/WebP, max 2 MB).';
                }
            }
            $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_employee WHERE id = ? AND role = 2 LIMIT 1');
            mysqli_stmt_bind_param($stmt2, 'i', $id);
            mysqli_stmt_execute($stmt2);
            $fetched = hms_stmt_fetch_assoc($stmt2);
            if ($fetched !== null) {
                $row = $fetched;
                $hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));
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
                hms_ui_page_header('Edit doctor', [
                    'subtitle' => 'User #' . (int) $id,
                    'breadcrumbs' => [['Doctors', 'doctors.php'], ['Edit', null]],
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
                                                <label>Current photo</label>
                                                <div class="mb-2">
                                                    <img src="<?php echo hms_h(hms_doctor_avatar_src($row)); ?>" alt="" class="rounded-circle border" width="96" height="96" style="object-fit:cover;">
                                                </div>
                                                <label for="doc_photo">Replace photo</label>
                                                <input id="doc_photo" class="form-control-file" type="file" name="doc_photo" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp">
                                                <small class="form-text text-muted">JPEG, PNG, or WebP — max 2 MB.</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group mt-md-4">
                                                <?php
                                                $hasUpload = isset($row['photo_path']) && trim((string) $row['photo_path']) !== ''
                                                    && strpos((string) $row['photo_path'], 'uploads/doctors/') === 0;
                                                ?>
                                                <?php if ($hasUpload) { ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="doc_remove_photo">
                                                    <label class="form-check-label" for="doc_remove_photo">Remove uploaded photo (use default avatar)</label>
                                                </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_fn">First name <span class="hms-required">*</span></label>
                                                <input id="doc_fn" class="form-control" type="text" name="first_name" value="<?php echo hms_h((string) $row['first_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_ln">Last name <span class="hms-required">*</span></label>
                                                <input id="doc_ln" class="form-control" type="text" name="last_name" value="<?php echo hms_h((string) $row['last_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_un">Username <span class="hms-required">*</span></label>
                                                <input id="doc_un" class="form-control" type="text" name="username" value="<?php echo hms_h((string) $row['username']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_em">Email <span class="hms-required">*</span></label>
                                                <input id="doc_em" class="form-control" type="email" name="emailid" value="<?php echo hms_h((string) $row['emailid']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_pw">Password</label>
                                                <input id="doc_pw" class="form-control" type="password" name="pwd" value="" placeholder="Leave blank to keep current password" autocomplete="new-password">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_dob">Date of birth</label>
                                                <div class="cal-icon">
                                                    <input id="doc_dob" type="text" class="form-control datetimepicker" name="dob" value="<?php echo hms_h((string) $row['dob']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="doc_gm" type="radio" name="gender" class="form-check-input" value="Male" <?php echo ($row['gender'] === 'Male') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="doc_gm">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="doc_gf" type="radio" name="gender" class="form-check-input" value="Female" <?php echo ($row['gender'] === 'Female') ? 'checked' : ''; ?>>
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
                                                <input id="doc_eid" class="form-control" type="text" name="employee_id" required value="<?php echo hms_h((string) $row['employee_id']); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_join">Joining date <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="doc_join" type="text" class="form-control datetimepicker" name="joining_date" required value="<?php echo hms_h((string) $row['joining_date']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_ph">Phone</label>
                                                <input id="doc_ph" class="form-control" type="text" name="phone" value="<?php echo hms_h((string) $row['phone']); ?>">
                                            </div>
                                        </div>
                                        <?php if ($hasEmployeeDeptCol) {
                                            $curPd = trim((string) ($row['primary_department'] ?? ''));
                                            ?>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="doc_primary_dept">Primary department</label>
                                                <select id="doc_primary_dept" name="primary_department" class="form-control">
                                                    <option value="">— Not set —</option>
                                                    <?php foreach ($deptPick as $dn) {
                                                        $sel = ($curPd === $dn) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($dn) . '"' . $sel . '>' . hms_h($dn) . '</option>';
                                                    } ?>
                                                </select>
                                                <small class="form-text text-muted">Used to filter this doctor when a department is chosen for visits or appointments.</small>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="doc_bio">Short biography</label>
                                                <textarea id="doc_bio" class="form-control" rows="3" name="bio"><?php echo hms_h((string) $row['bio']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Account status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="doctor_active" value="1" <?php echo ((int) $row['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="doctor_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="doctor_inactive" value="0" <?php echo ((int) $row['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="doctor_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="doctors.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="save-doc">Save changes</button>
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