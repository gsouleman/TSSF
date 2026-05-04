<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/patient_portal_view.php';
require_once __DIR__ . '/includes/patient_portal_booking.php';

$pid = hms_patient_portal_patient_id();
if ($pid < 1) {
    header('Location: patient-portal-login.php');
    exit;
}

if (!hms_patient_portal_ready($connection)) {
    hms_patient_portal_logout();
    header('Location: patient-portal-login.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? AND status = 1 LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $pid);
mysqli_stmt_execute($stmt);
$patient = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$patient || (int) ($patient['portal_enabled'] ?? 0) !== 1) {
    hms_patient_portal_logout();
    header('Location: patient-portal-login.php');
    exit;
}

$fid = (int) ($patient['facility_id'] ?? 1);
$ms = hms_multi_site_enabled($connection);
$doctors = hms_patient_portal_booking_doctors($connection, $fid, $ms);
$departments = hms_patient_portal_booking_departments($connection, $fid, $ms);
$schedules = hms_patient_portal_booking_schedules($connection, $fid, $ms);

$formErr = '';
$selDoctor = trim((string) ($_POST['doctor'] ?? $_GET['doctor'] ?? ''));
$selDate = trim((string) ($_POST['date'] ?? $_GET['date'] ?? ''));
$selDept = trim((string) ($_POST['department'] ?? ''));
$selTime = trim((string) ($_POST['time_slot'] ?? ''));
$selNotes = trim((string) ($_POST['notes'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pp_book_appointment'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $formErr = 'Invalid security token. Please try again.';
    } else {
        $selDoctor = trim((string) ($_POST['doctor'] ?? ''));
        $selDate = trim((string) ($_POST['date'] ?? ''));
        $selDept = trim((string) ($_POST['department'] ?? ''));
        $selTime = trim((string) ($_POST['time_slot'] ?? ''));
        $selNotes = trim((string) ($_POST['notes'] ?? ''));
        $formErr = hms_patient_portal_booking_submit(
            $connection,
            $patient,
            $selDept,
            $selDoctor,
            $selDate,
            $selTime,
            $selNotes,
            $doctors,
            $departments
        );
        if ($formErr === '') {
            $_SESSION['patient_portal_flash_ok'] = 'Your appointment request was sent. The clinic will confirm it under Requests; you will also see it on your overview when it is active.';
            header('Location: patient-portal.php');
            exit;
        }
    }
}

$slots = [];
if ($selDoctor !== '' && $selDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) {
    $slots = hms_patient_portal_booking_slots_for($connection, $selDoctor, $selDate, $fid, $ms);
}

hms_patient_portal_render_head(['title' => 'Book a visit — Patient portal', 'show_nav' => true, 'nav_active' => 'book']);
?>
                <div class="hms-page-toolbar card border-0 shadow-sm mb-4">
                    <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center">
                        <div>
                            <h1 class="h5 font-weight-bold mb-1">Book a visit</h1>
                            <p class="text-muted small mb-0">See when doctors are scheduled to see patients, then choose an open slot. Your booking is a <strong>request</strong> until staff confirm it.</p>
                        </div>
                    </div>
                </div>

                <?php if ($schedules === []) { ?>
                <div class="alert alert-info border-0 shadow-sm">
                    No doctor schedules are published yet. Please call the clinic to arrange a visit. Once staff add availability under <em>Doctor Schedule</em>, times will appear here.
                </div>
                <?php } else { ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white font-weight-bold border-bottom">Doctor availability (this site)</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Doctor</th>
                                        <th>Days</th>
                                        <th>Hours</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedules as $s) { ?>
                                    <tr>
                                        <td class="font-weight-bold"><?php echo hms_h((string) ($s['doctor_name'] ?? '')); ?></td>
                                        <td class="small"><?php echo hms_h((string) ($s['available_days'] ?? '')); ?></td>
                                        <td class="small text-nowrap"><?php echo hms_h((string) ($s['start_time'] ?? '')); ?> – <?php echo hms_h((string) ($s['end_time'] ?? '')); ?></td>
                                        <td class="small text-muted"><?php echo hms_h((string) ($s['message'] ?? '')); ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php } ?>

                <?php if ($doctors === [] || $departments === []) { ?>
                <div class="alert alert-warning border-0 shadow-sm">Online booking is not fully configured (missing doctors or departments for this site). Please contact reception.</div>
                <?php } else { ?>
                <div class="card border-0 shadow-sm hms-form-card">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted font-weight-bold mb-3">Request an appointment</h2>
                        <?php if ($formErr !== '') { ?>
                        <div class="alert alert-danger"><?php echo hms_h($formErr); ?></div>
                        <?php } ?>
                        <form method="post" action="patient-portal-book.php" class="mb-0">
                            <?php echo hms_csrf_field(); ?>
                            <div class="form-group">
                                <label for="pp_dept">Department <span class="text-danger">*</span></label>
                                <select class="form-control" id="pp_dept" name="department" required>
                                    <option value="">Select…</option>
                                    <?php foreach ($departments as $d) { ?>
                                    <option value="<?php echo hms_h($d); ?>"<?php echo $selDept === $d ? ' selected' : ''; ?>><?php echo hms_h($d); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pp_doc">Doctor <span class="text-danger">*</span></label>
                                <select class="form-control" id="pp_doc" name="doctor" required>
                                    <option value="">Select…</option>
                                    <?php foreach ($doctors as $d) { ?>
                                    <option value="<?php echo hms_h($d); ?>"<?php echo $selDoctor === $d ? ' selected' : ''; ?>><?php echo hms_h($d); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pp_date">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="pp_date" name="date" required
                                    min="<?php echo hms_h(date('Y-m-d')); ?>"
                                    max="<?php echo hms_h(date('Y-m-d', strtotime('+120 days'))); ?>"
                                    value="<?php echo hms_h($selDate); ?>">
                                <small class="form-text text-muted">Only days that appear in the doctor’s schedule above can be booked. Slots update when you pick doctor and date.</small>
                            </div>
                            <div class="form-group">
                                <label>Available times <span class="text-danger">*</span></label>
                                <?php if ($selDoctor === '' || $selDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) { ?>
                                <p class="text-muted small mb-0">Choose a doctor and a date to load open 30-minute slots.</p>
                                <?php } elseif ($slots === []) { ?>
                                <p class="text-warning small mb-0">No open slots on that date for this doctor (outside published hours, day off, or fully booked). Try another date.</p>
                                <?php } else { ?>
                                <div class="d-flex flex-wrap">
                                    <?php
                                    $slotIdx = 0;
                                    foreach ($slots as $slot) {
                                        ++$slotIdx;
                                        $disp = $slot;
                                        $ts = strtotime('1970-01-01 ' . $slot);
                                        if ($ts !== false) {
                                            $disp = date('g:i A', $ts);
                                        }
                                        $rid = 'pp_slot_' . (string) $slotIdx;
                                        ?>
                                    <div class="custom-control custom-radio mr-3 mb-2">
                                        <input class="custom-control-input" type="radio" name="time_slot" id="<?php echo hms_h($rid); ?>" value="<?php echo hms_h($slot); ?>"<?php echo $selTime === $slot ? ' checked' : ''; ?><?php echo $slotIdx === 1 ? ' required' : ''; ?>>
                                        <label class="custom-control-label" for="<?php echo hms_h($rid); ?>"><?php echo hms_h($disp); ?></label>
                                    </div>
                                    <?php } ?>
                                </div>
                                <?php } ?>
                            </div>
                            <div class="form-group">
                                <label for="pp_notes">Notes for the clinic (optional)</label>
                                <textarea class="form-control" id="pp_notes" name="notes" rows="3" maxlength="500" placeholder="Reason for visit, preferred language, etc."><?php echo hms_h($selNotes); ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap align-items-center">
                                <button type="button" class="btn btn-outline-secondary mr-2 mb-2" id="pp_load_slots">Load available times</button>
                                <button type="submit" name="pp_book_appointment" class="btn btn-success font-weight-bold mb-2"<?php echo ($selDoctor === '' || $selDate === '' || $slots === []) ? ' disabled' : ''; ?>>Submit request</button>
                            </div>
                            <p class="text-muted small mt-3 mb-0">Same as staff bookings: status starts as <strong>pending</strong> until the front desk confirms. Urgent care: phone the hospital.</p>
                        </form>
                    </div>
                </div>
                <?php } ?>
                <script>
                (function () {
                    var btn = document.getElementById('pp_load_slots');
                    if (!btn) return;
                    btn.addEventListener('click', function () {
                        var doc = document.getElementById('pp_doc');
                        var dt = document.getElementById('pp_date');
                        if (!doc || !dt || !doc.value || !dt.value) {
                            window.alert('Please select a doctor and a date first.');
                            return;
                        }
                        window.location.href = 'patient-portal-book.php?doctor=' + encodeURIComponent(doc.value) + '&date=' + encodeURIComponent(dt.value);
                    });
                })();
                </script>
<?php
hms_patient_portal_render_foot();
