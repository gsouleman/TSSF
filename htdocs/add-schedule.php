<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';

if (isset($_REQUEST['add-schedule'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $doctor_name = (string) ($_REQUEST['doctor'] ?? '');
    $days = isset($_REQUEST['days']) && is_array($_REQUEST['days']) ? implode(', ', $_REQUEST['days']) : '';
    $start_time = (string) ($_REQUEST['start_time'] ?? '');
    $end_time = (string) ($_REQUEST['end_time'] ?? '');
    $message = (string) ($_REQUEST['message'] ?? '');
    $status = (int) ($_REQUEST['status'] ?? 1);
    $fid = hms_current_facility_id();

    if (hms_multi_site_enabled($connection)) {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_schedule (doctor_name, available_days, start_time, end_time, message, status, facility_id) VALUES (?,?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssssii', $doctor_name, $days, $start_time, $end_time, $message, $status, $fid);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'Schedule created successfully';
                hms_audit_log($connection, 'schedule.create', 'schedule', (int) mysqli_insert_id($connection));
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
            'INSERT INTO tbl_schedule (doctor_name, available_days, start_time, end_time, message, status) VALUES (?,?,?,?,?,?)'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssssi', $doctor_name, $days, $start_time, $end_time, $message, $status);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'Schedule created successfully';
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
                hms_ui_page_header('Add schedule', [
                    'subtitle' => 'Define when a doctor is available for booking.',
                    'breadcrumbs' => [['Availability', 'schedule.php'], ['Add', null]],
                    'back' => 'schedule.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-8 col-lg-10">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Coverage</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="sch_doc">Doctor <span class="hms-required">*</span></label>
                                                <select id="sch_doc" class="select" name="doctor" required>
                                                    <option value="">Select</option>
                                                    <?php
                                                    $fetch_query = mysqli_query($connection, "SELECT CONCAT(first_name,' ',last_name) AS name FROM tbl_employee WHERE role=2 AND status=1");
                                                    while ($fetch_query && $doc = mysqli_fetch_array($fetch_query)) {
                                                        $nm = (string) $doc['name'];
                                                        echo '<option value="' . hms_h($nm) . '">' . hms_h($nm) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="sch_days">Available days <span class="hms-required">*</span></label>
                                                <select id="sch_days" class="select" multiple name="days[]" required>
                                                    <option value="Sunday">Sunday</option>
                                                    <option value="Monday">Monday</option>
                                                    <option value="Tuesday">Tuesday</option>
                                                    <option value="Wednesday">Wednesday</option>
                                                    <option value="Thursday">Thursday</option>
                                                    <option value="Friday">Friday</option>
                                                    <option value="Saturday">Saturday</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Hours</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="datetimepicker3">Start time <span class="hms-required">*</span></label>
                                                <div class="time-icon">
                                                    <input type="text" class="form-control" id="datetimepicker3" name="start_time" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="datetimepicker4">End time <span class="hms-required">*</span></label>
                                                <div class="time-icon">
                                                    <input type="text" class="form-control" id="datetimepicker4" name="end_time" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="sch_msg">Message to patients <span class="hms-required">*</span></label>
                                                <textarea id="sch_msg" rows="4" class="form-control" name="message" required></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Schedule status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="sch_active" value="1" checked>
                                            <label class="form-check-label" for="sch_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="sch_inactive" value="0">
                                            <label class="form-check-label" for="sch_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="schedule.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="add-schedule">Create schedule</button>
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