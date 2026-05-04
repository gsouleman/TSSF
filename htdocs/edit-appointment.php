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
    header('Location: appointments.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_appointment WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$apt = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$apt) {
    header('Location: appointments.php');
    exit;
}

if (isset($_REQUEST['save-appointment'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
        $appointment_id = (string) ($_REQUEST['appointment_id'] ?? '');
        $rawPatient = (string) ($_REQUEST['patient_name'] ?? '');
        $patient_name = $rawPatient;
        if (strpos($rawPatient, '|') !== false) {
            $parts = explode('|', $rawPatient, 2);
            $patient_name = $parts[1] ?? $rawPatient;
        }
        $department = (string) ($_REQUEST['department'] ?? '');
        $doctor = (string) ($_REQUEST['doctor'] ?? '');
        $date = (string) ($_REQUEST['date'] ?? '');
        $time = (string) ($_REQUEST['time'] ?? '');
        $message = (string) ($_REQUEST['message'] ?? '');
        $status = (int) ($_REQUEST['status'] ?? 1);

        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_appointment SET appointment_id=?, patient_name=?, department=?, doctor=?, date=?, time=?, message=?, status=? WHERE id=?'
        );
        if ($upd) {
            mysqli_stmt_bind_param($upd, 'sssssssii', $appointment_id, $patient_name, $department, $doctor, $date, $time, $message, $status, $id);
            if (mysqli_stmt_execute($upd)) {
                $msg = 'Appointment updated successfully';
                $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_appointment WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($stmt2, 'i', $id);
                mysqli_stmt_execute($stmt2);
                $fetched = hms_stmt_fetch_assoc($stmt2);
                if ($fetched !== null) {
                    $apt = $fetched;
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

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$psuf = $ms ? ' WHERE facility_id = ' . (int) $fid : '';
$dsuf = $ms ? ' WHERE facility_id = ' . (int) $fid : '';
$storedPatient = (string) $apt['patient_name'];
$storedNamePart = trim((string) (explode(',', $storedPatient, 2)[0] ?? ''));

?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Edit appointment', [
                    'subtitle' => hms_h((string) $apt['appointment_id']),
                    'breadcrumbs' => [['Appointments', 'appointments.php'], ['Edit', null]],
                    'back' => 'appointments.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-9 col-lg-11">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Visit</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="apt_id">Appointment ID <span class="hms-required">*</span></label>
                                                <input id="apt_id" class="form-control" type="text" name="appointment_id" value="<?php echo hms_h((string) $apt['appointment_id']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="apt_pat">Patient <span class="hms-required">*</span></label>
                                                <select id="apt_pat" class="select" name="patient_name" required>
                                                    <option value="">Select</option>
                                                    <?php
                                                    $fetch_query = mysqli_query(
                                                        $connection,
                                                        'SELECT id, CONCAT(first_name,\' \',last_name) AS name, dob FROM tbl_patient' . $psuf
                                                    );
                                                    while ($fetch_query && $prow = mysqli_fetch_array($fetch_query)) {
                                                        $val = (int) $prow['id'] . '|' . $prow['name'] . ',' . $prow['dob'];
                                                        $nm = trim((string) $prow['name']);
                                                        $sel = ($nm === $storedNamePart || strpos($storedPatient, $nm) === 0) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($val) . '"' . $sel . '>' . hms_h((string) $prow['name']) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Care team</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="apt_dept">Department</label>
                                                <select id="apt_dept" class="select" name="department">
                                                    <option value="">Select</option>
                                                    <?php
                                                    $fetch_query = mysqli_query($connection, 'SELECT department_name FROM tbl_department' . $dsuf);
                                                    $curDept = (string) $apt['department'];
                                                    while ($fetch_query && $drow = mysqli_fetch_array($fetch_query)) {
                                                        $dn = (string) $drow['department_name'];
                                                        $sel = ($dn === $curDept) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($dn) . '"' . $sel . '>' . hms_h($dn) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="apt_doc">Doctor</label>
                                                <select id="apt_doc" class="select" name="doctor">
                                                    <option value="">Select</option>
                                                    <?php
                                                    $curDoc = (string) $apt['doctor'];
                                                    if ($ms) {
                                                        $fetch_query = mysqli_query(
                                                            $connection,
                                                            'SELECT CONCAT(e.first_name,\' \',e.last_name) AS name FROM tbl_employee e
                                                             INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                                                             WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ' . (int) $fid
                                                        );
                                                    } else {
                                                        $fetch_query = mysqli_query($connection, "SELECT CONCAT(first_name,' ',last_name) AS name FROM tbl_employee WHERE role = 2 AND status = 1");
                                                    }
                                                    while ($fetch_query && $drow = mysqli_fetch_array($fetch_query)) {
                                                        $nm = (string) $drow['name'];
                                                        $sel = ($nm === $curDoc) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($nm) . '"' . $sel . '>' . hms_h($nm) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">When</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="apt_date">Date</label>
                                                <div class="cal-icon">
                                                    <input id="apt_date" type="text" class="form-control datetimepicker" name="date" value="<?php echo hms_h((string) $apt['date']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="datetimepicker3">Time</label>
                                                <div class="time-icon">
                                                    <input type="text" class="form-control" id="datetimepicker3" name="time" value="<?php echo hms_h((string) $apt['time']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="apt_msg">Notes</label>
                                                <textarea id="apt_msg" rows="4" class="form-control" name="message" required><?php echo hms_h((string) $apt['message']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Appointment status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="apt_active" value="1" <?php echo ((int) $apt['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="apt_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="apt_inactive" value="0" <?php echo ((int) $apt['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="apt_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="appointments.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" name="save-appointment" class="btn btn-primary mb-2 mb-sm-0">Save changes</button>
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
