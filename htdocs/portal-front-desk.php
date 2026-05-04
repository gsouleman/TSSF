<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
$_role = (string)($_SESSION['role'] ?? '');
if (!in_array($_role, ['1', '3'], true)) { header('Location: dashboard.php'); exit; }
include 'header.php';

$fid   = hms_current_facility_id();
$ms    = hms_multi_site_enabled($connection);
$suf   = $ms ? ' AND facility_id = '.(int)$fid : '';
$today    = date('Y-m-d');
$todayEsc = mysqli_real_escape_string($connection, $today);
$opdOk = hms_opd_tables_ready($connection);

// Stats
$qAppts    = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_appointment WHERE status=1 AND date='$todayEsc'" . $suf);
$qPats     = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_patient WHERE status=1" . $suf);
$statAppts = $qAppts ? (int) (mysqli_fetch_assoc($qAppts)['c'] ?? 0) : 0;
$statPats  = $qPats ? (int) (mysqli_fetch_assoc($qPats)['c'] ?? 0) : 0;
$statNew   = 0;
if (function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_patient', 'created_at')) {
    $qNewPats = mysqli_query(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_patient WHERE status=1 AND DATE(created_at)='$todayEsc'" . $suf
    );
    $statNew = $qNewPats ? (int) (mysqli_fetch_assoc($qNewPats)['c'] ?? 0) : 0;
}

// OPD visit count today (safe)
$statOPD = 0;
if ($opdOk) {
    $qOPD = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_opd_visit WHERE visit_date='$todayEsc'" . ($ms ? " AND facility_id=".(int)$fid : ""));
    if ($qOPD) $statOPD = (int)(mysqli_fetch_assoc($qOPD)['c'] ?? 0);
}

// Today's appointments table
$qTodayAppts = mysqli_query($connection, "SELECT patient_name, doctor, time, department FROM tbl_appointment WHERE status=1 AND date='$todayEsc'" . $suf . " ORDER BY time ASC LIMIT 15");
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Header Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#0c8b8b 0%,#1a6bd8 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Front Desk — <?php echo date('l, d F Y'); ?></h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.85);">Welcome, <?php echo hms_h((string)($_SESSION['name'] ?? 'Staff')); ?>. Manage daily operations from here.</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="add-patient.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#0c8b8b;"><i class="fa fa-user-plus mr-1"></i> Register Patient</a>
                    <a href="add-appointment.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-calendar-plus-o mr-1"></i> Book Appointment</a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['Appointments Today',  $statAppts, 'fa-calendar-check-o','#1a6bd8','#dbeafe'],
                ['New Patients Today',  $statNew,   'fa-user-plus',       '#10b981','#d1fae5'],
                ['OPD Visits Today',    $statOPD,   'fa-list-ol',         '#f59e0b','#fef3c7'],
                ['Total Active Patients',$statPats, 'fa-users',           '#8b5cf6','#ede9fe'],
            ];
            foreach ($stats as $hmsSr) {
                $label = $hmsSr[0];
                $val = $hmsSr[1];
                $icon = $hmsSr[2];
                $color = $hmsSr[3];
                $bg = $hmsSr[4];
                ?>
            <div class="col-6 col-md-3 mb-3">
                <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?php echo $color; ?> !important;">
                    <div class="card-body d-flex align-items-center">
                        <span class="d-flex align-items-center justify-content-center rounded-circle mr-3" style="width:48px;height:48px;background:<?php echo $bg; ?>;">
                            <i class="fa <?php echo $icon; ?> fa-lg" style="color:<?php echo $color; ?>;"></i>
                        </span>
                        <div>
                            <div style="font-size:1.8rem;font-weight:800;color:#1e293b;"><?php echo $val; ?></div>
                            <div class="text-muted small"><?php echo $label; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Quick Actions -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">Quick Actions</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['add-patient.php',           'fa-user-plus',       'Register Patient',   '#0c8b8b'],
                        ['vitals-enter.php?station=front_desk', 'fa-heartbeat', 'Record vitals', '#ec4899'],
                        ['opd-queue.php',             'fa-list-ol',         'OPD Queue',          '#1a6bd8'],
                        ['appointments-calendar.php', 'fa-calendar',        'Appt. Calendar',     '#8b5cf6'],
                        ['add-appointment.php',       'fa-calendar-plus-o', 'Book Appointment',   '#f59e0b'],
                        ['visits.php',                'fa-h-square',        'Visits',             '#10b981'],
                        ['patients.php',              'fa-users',           'Patient Register',   '#ef4444'],
                        ['consents.php',              'fa-file-text-o',     'Consents',           '#0ea5e9'],
                        ['schedule.php',              'fa-clock-o',         'Doctor Schedule',    '#64748b'],
                    ];
                    foreach ($actions as $hmsAr) {
                        $url = $hmsAr[0];
                        $icon = $hmsAr[1];
                        $label = $hmsAr[2];
                        $color = $hmsAr[3];
                        ?>
                    <div class="col-6 col-md-3 mb-3">
                        <a href="<?php echo $url; ?>" class="card border-0 shadow-sm text-center p-3 d-block text-decoration-none" style="transition:.2s;">
                            <span class="d-flex align-items-center justify-content-center rounded-circle mx-auto mb-2" style="width:50px;height:50px;background:<?php echo $color; ?>20;">
                                <i class="fa <?php echo $icon; ?> fa-lg" style="color:<?php echo $color; ?>;"></i>
                            </span>
                            <span class="small font-weight-bold" style="color:#1e293b;"><?php echo $label; ?></span>
                        </a>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <!-- Today's Appointments Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                <span class="font-weight-bold"><i class="fa fa-calendar mr-1 text-primary"></i> Today's Appointment Schedule</span>
                <a href="appointments.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Patient</th><th>Doctor</th><th>Time</th><th>Department</th></tr></thead>
                        <tbody>
                        <?php
                        $cnt = 0;
                        if ($qTodayAppts) while ($row = mysqli_fetch_assoc($qTodayAppts)) {
                            $cnt++;
                            echo '<tr>
                                <td class="font-weight-bold">'.hms_h((string)$row['patient_name']).'</td>
                                <td class="small">'.hms_h((string)$row['doctor']).'</td>
                                <td class="small text-muted">'.hms_h((string)$row['time']).'</td>
                                <td class="small text-muted">'.hms_h((string)$row['department']).'</td>
                            </tr>';
                        }
                        if ($cnt === 0) echo '<tr><td colspan="4" class="text-center text-muted py-4">No appointments scheduled today.</td></tr>';
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
