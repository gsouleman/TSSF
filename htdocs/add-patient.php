<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.write');
include 'header.php';

if (isset($_REQUEST['add-patient'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $first_name = (string) ($_REQUEST['first_name'] ?? '');
    $last_name = (string) ($_REQUEST['last_name'] ?? '');
    $emailid = (string) ($_REQUEST['emailid'] ?? '');
    $dob = (string) ($_REQUEST['dob'] ?? '');
    $gender = (string) ($_REQUEST['gender'] ?? '');
    $patient_type = (string) ($_REQUEST['patient_type'] ?? '');
    $phone = (string) ($_REQUEST['phone'] ?? '');
    $address = hms_cameroon_address_from_request($_REQUEST);
    $status = (int) ($_REQUEST['status'] ?? 1);
    $fid = hms_current_facility_id();

    if (hms_multi_site_enabled($connection)) {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status, facility_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssssssii', $first_name, $last_name, $emailid, $dob, $gender, $patient_type, $address, $phone, $status, $fid);
            if (mysqli_stmt_execute($stmt)) {
                $newPid = (int) mysqli_insert_id($connection);
                $msg = 'Patient created successfully';
                hms_audit_log($connection, 'patient.create', 'patient', $newPid);
                if ($newPid > 0 && !empty($_REQUEST['open_credit_line']) && function_exists('hms_credit_open_account')
                    && function_exists('hms_credit_tables_ok') && hms_credit_tables_ok($connection) && hms_credit_can_write($connection)) {
                    $em = !empty($_REQUEST['emergency_credit_pending']);
                    $cr = hms_credit_open_account($connection, $fid, $newPid, true, $em, null, null, null, null, (int) ($_SESSION['user_id'] ?? 0));
                    if ($cr['ok']) {
                        $msg .= ' Credit account #' . (int) ($cr['id'] ?? 0) . ' opened.';
                    }
                }
            } else {
                $msg = 'Error!';
            }
            mysqli_stmt_close($stmt);
        } else {
            $msg = 'Error!';
        }
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient (first_name, last_name, email, dob, gender, patient_type, address, phone, status) VALUES (?,?,?,?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssssssi', $first_name, $last_name, $emailid, $dob, $gender, $patient_type, $address, $phone, $status);
            if (mysqli_stmt_execute($stmt)) {
                $newPid = (int) mysqli_insert_id($connection);
                $msg = 'Patient created successfully';
                hms_audit_log($connection, 'patient.create', 'patient', $newPid);
                if ($newPid > 0 && !empty($_REQUEST['open_credit_line']) && function_exists('hms_credit_open_account')
                    && function_exists('hms_credit_tables_ok') && hms_credit_tables_ok($connection) && hms_credit_can_write($connection)) {
                    $em = !empty($_REQUEST['emergency_credit_pending']);
                    $cr = hms_credit_open_account($connection, $fid, $newPid, false, $em, null, null, null, null, (int) ($_SESSION['user_id'] ?? 0));
                    if ($cr['ok']) {
                        $msg .= ' Credit account #' . (int) ($cr['id'] ?? 0) . ' opened.';
                    }
                }
            } else {
                $msg = 'Error!';
            }
            mysqli_stmt_close($stmt);
        } else {
            $msg = 'Error!';
        }
    }
    }
}
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Register patient', [
                    'subtitle' => 'Capture demographics and contact details for this site.',
                    'breadcrumbs' => [['Patients', 'patients.php'], ['Register', null]],
                    'back' => 'patients.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-9 col-lg-11">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Identity</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="first_name">First name <span class="hms-required">*</span></label>
                                                <input id="first_name" class="form-control" type="text" name="first_name" required autocomplete="given-name">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="last_name">Last name <span class="hms-required">*</span></label>
                                                <input id="last_name" class="form-control" type="text" name="last_name" required autocomplete="family-name">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emailid">Email <span class="hms-required">*</span></label>
                                                <input id="emailid" class="form-control" type="email" name="emailid" required autocomplete="email">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="dob">Date of birth <span class="hms-required">*</span></label>
                                                <div class="cal-icon">
                                                    <input id="dob" type="text" class="form-control datetimepicker" name="dob" required autocomplete="bday">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="gender_m" type="radio" name="gender" class="form-check-input" value="Male" required>
                                                    <label class="form-check-label" for="gender_m">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="gender_f" type="radio" name="gender" class="form-check-input" value="Female">
                                                    <label class="form-check-label" for="gender_f">Female</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Care &amp; contact</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="patient_type">Patient type <span class="hms-required">*</span></label>
                                                <select id="patient_type" class="select" name="patient_type" required>
                                                    <option value="">Select</option>
                                                    <option value="InPatient">Inpatient</option>
                                                    <option value="OutPatient">Outpatient</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="phone">Phone <span class="hms-required">*</span></label>
                                                <input id="phone" class="form-control" type="text" name="phone" required autocomplete="tel">
                                            </div>
                                        </div>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                    </div>
                                </div>
                                <?php if (function_exists('hms_credit_tables_ok') && hms_credit_tables_ok($connection) && function_exists('hms_credit_can_write') && hms_credit_can_write($connection)) { ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Credit &amp; Receivables</h2>
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox" class="custom-control-input" id="open_credit_line" name="open_credit_line" value="1">
                                        <label class="custom-control-label" for="open_credit_line">Open a patient credit line now</label>
                                    </div>
                                    <div class="custom-control custom-checkbox mb-0">
                                        <input type="checkbox" class="custom-control-input" id="emergency_credit_pending" name="emergency_credit_pending" value="1">
                                        <label class="custom-control-label" for="emergency_credit_pending">Emergency — payment pending</label>
                                    </div>
                                </div>
                                <?php } ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Record status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="patient_active" value="1" checked>
                                            <label class="form-check-label" for="patient_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="patient_inactive" value="0">
                                            <label class="form-check-label" for="patient_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="patients.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" name="add-patient" class="btn btn-primary mb-2 mb-sm-0">Create patient</button>
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