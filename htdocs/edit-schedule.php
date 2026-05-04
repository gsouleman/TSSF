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
    header('Location: schedule.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_schedule WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: schedule.php');
    exit;
}

if (isset($_REQUEST['save-schedule'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $doctor_name = (string) ($_REQUEST['doctor_name'] ?? '');
    $days = isset($_REQUEST['days']) && is_array($_REQUEST['days']) ? implode(', ', $_REQUEST['days']) : '';
    $start_time = (string) ($_REQUEST['start_time'] ?? '');
    $end_time = (string) ($_REQUEST['end_time'] ?? '');
    $message = (string) ($_REQUEST['msg'] ?? '');
    $status = (int) ($_REQUEST['status'] ?? 1);

    $upd = mysqli_prepare(
        $connection,
        'UPDATE tbl_schedule SET doctor_name=?, available_days=?, start_time=?, end_time=?, message=?, status=? WHERE id=?'
    );
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'sssssii', $doctor_name, $days, $start_time, $end_time, $message, $status, $id);
        if (mysqli_stmt_execute($upd)) {
            $msg = 'Schedule updated successfully';
            $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_schedule WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt2, 'i', $id);
            mysqli_stmt_execute($stmt2);
            $fetched = hms_stmt_fetch_assoc($stmt2);
            if ($fetched !== null) {
                $row = $fetched;
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
                hms_ui_page_header('Edit schedule', [
                    'subtitle' => 'Slot #' . (int) $id,
                    'breadcrumbs' => [['Availability', 'schedule.php'], ['Edit', null]],
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
                                                <select id="sch_doc" class="select" name="doctor_name" required>
                                                    <option value="">Select</option>
                                                    <?php
                                                    $curDoc = (string) $row['doctor_name'];
                                                    $fetch_query = mysqli_query($connection, "SELECT CONCAT(first_name,' ',last_name) AS name FROM tbl_employee WHERE status=1 AND role=2");
                                                    while ($fetch_query && $doc = mysqli_fetch_array($fetch_query)) {
                                                        $nm = (string) $doc['name'];
                                                        $sel = ($nm === $curDoc) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($nm) . '"' . $sel . '>' . hms_h($nm) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="sch_days">Available days <span class="hms-required">*</span></label>
                                                <select id="sch_days" class="select" multiple name="days[]" required>
                                                    <?php
                                                    $days = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $row['available_days'])));
                                                    $fetch_query = mysqli_query($connection, 'SELECT name FROM tbl_week');
                                                    while ($fetch_query && $w = mysqli_fetch_array($fetch_query)) {
                                                        $wn = (string) $w['name'];
                                                        $selected = in_array($wn, $days, true) ? ' selected' : '';
                                                        echo '<option value="' . hms_h($wn) . '"' . $selected . '>' . hms_h($wn) . '</option>';
                                                    }
                                                    ?>
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
                                                    <input type="text" class="form-control" id="datetimepicker3" name="start_time" value="<?php echo hms_h((string) $row['start_time']); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="datetimepicker4">End time <span class="hms-required">*</span></label>
                                                <div class="time-icon">
                                                    <input type="text" class="form-control" id="datetimepicker4" name="end_time" value="<?php echo hms_h((string) $row['end_time']); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group mb-0">
                                                <label for="sch_msg">Message to patients <span class="hms-required">*</span></label>
                                                <textarea id="sch_msg" rows="4" class="form-control" name="msg" required><?php echo hms_h((string) $row['message']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Schedule status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="sch_active" value="1" <?php echo ((int) $row['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sch_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="sch_inactive" value="0" <?php echo ((int) $row['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="sch_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="schedule.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="save-schedule">Save changes</button>
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