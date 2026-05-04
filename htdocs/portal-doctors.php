<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_portal($connection, 'doctors');
include 'header.php';

$uid  = (int)($_SESSION['user_id'] ?? 0);
$fid  = hms_current_facility_id();
$ms   = hms_multi_site_enabled($connection);
$suf  = $ms ? ' AND facility_id = '.(int)$fid : '';
$today = date('Y-m-d');

// Get logged-in doctor's name for filtering
$stmtDoc = mysqli_prepare($connection, 'SELECT first_name, last_name, bio FROM tbl_employee WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmtDoc, 'i', $uid);
mysqli_stmt_execute($stmtDoc);
$meDoc = hms_stmt_fetch_assoc($stmtDoc) ?? [];
mysqli_stmt_close($stmtDoc);
$myName = trim((string)($meDoc['first_name'] ?? '').' '.(string)($meDoc['last_name'] ?? ''));
$myNameEsc = mysqli_real_escape_string($connection, $myName);

// Doctor's stats
$qMyAppts   = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_appointment WHERE status=1 AND date='$today' AND doctor='$myNameEsc'");
$qMyConsults= mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_consultation WHERE 1=1" . ($ms ? " AND facility_id=".(int)$fid : ""));
$qMyPending = mysqli_query(
    $connection,
    "SELECT COUNT(*) AS c FROM tbl_opd_visit WHERE queue_status IN ('registered','triage')" . ($ms ? ' AND facility_id=' . (int) $fid : '')
);
$qMyPatients= mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_patient WHERE status=1" . $suf);
$statMyAppts    = $qMyAppts   ? (int)(mysqli_fetch_assoc($qMyAppts)['c']   ?? 0) : 0;
$statConsults   = $qMyConsults? (int)(mysqli_fetch_assoc($qMyConsults)['c']?? 0) : 0;
$statPending    = $qMyPending ? (int)(mysqli_fetch_assoc($qMyPending)['c'] ?? 0) : 0;
$statPatients   = $qMyPatients? (int)(mysqli_fetch_assoc($qMyPatients)['c']?? 0) : 0;

// Today's appointments for this doctor
$qAppts = mysqli_query($connection, "SELECT patient_name, time, department FROM tbl_appointment WHERE status=1 AND date='$today' AND doctor='$myNameEsc' ORDER BY time ASC LIMIT 15");

// Recent consultations
$qConsults = mysqli_query($connection, "SELECT c.*, p.first_name, p.last_name FROM tbl_consultation c LEFT JOIN tbl_patient p ON p.id=c.patient_id ORDER BY c.id DESC LIMIT 8");
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Welcome Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#1a6bd8 0%,#0c8b8b 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Welcome, Dr. <?php echo hms_h((string)($meDoc['first_name'] ?? '')); ?> 👋</h1>
                    <p class="mb-0 small opacity-75" style="color:rgba(255,255,255,.85);"><?php echo hms_h((string)($meDoc['bio'] ?? 'Physician')); ?> &mdash; <?php echo date('l, d F Y'); ?></p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="consultation-new.php" class="btn btn-light btn-sm font-weight-bold" style="color:#1a6bd8;"><i class="fa fa-plus mr-1"></i> New Consultation</a>
                </div>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['My Appts Today',     $statMyAppts,  'fa-calendar-check-o','#1a6bd8','#dbeafe'],
                ['My Consultations',   $statConsults,  'fa-stethoscope',    '#10b981','#d1fae5'],
                ['OPD in queue',       $statPending,   'fa-h-square',       '#f59e0b','#fef3c7'],
                ['Total Patients',     $statPatients,  'fa-users',          '#8b5cf6','#ede9fe'],
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
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">Clinical Tools</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['consultation-new.php',    'fa-plus-square',       'New Consultation',  '#1a6bd8'],
                        ['consultations.php',       'fa-stethoscope',       'My Consultations',  '#10b981'],
                        ['appointments.php',        'fa-calendar',          'Appointments',      '#8b5cf6'],
                        ['visits.php',              'fa-h-square',          'Visits',            '#f59e0b'],
                        ['patients.php',            'fa-users',             'Patients',          '#0ea5e9'],
                        ['patient-chart.php',       'fa-heartbeat',         'Patient Chart',     '#ef4444'],
                        ['prescriptions.php',       'fa-medkit',            'Prescriptions',     '#0c8b8b'],
                        ['lab-results.php',         'fa-flask',             'Lab Results',       '#64748b'],
                        ['radiology-results.php',   'fa-film',              'Radiology',         '#0891b2'],
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

        <div class="row">
            <!-- Today's Schedule -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                        <span class="font-weight-bold"><i class="fa fa-calendar mr-1 text-primary"></i> My Schedule Today</span>
                        <a href="appointments.php" class="btn btn-sm btn-outline-primary">All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light"><tr><th>Patient</th><th>Time</th><th>Department</th></tr></thead>
                                <tbody>
                                <?php
                                $cnt = 0;
                                if ($qAppts) while ($row = mysqli_fetch_assoc($qAppts)) {
                                    $cnt++;
                                    echo '<tr><td class="font-weight-bold">'.hms_h((string)$row['patient_name']).'</td><td class="small text-muted">'.hms_h((string)$row['time']).'</td><td class="small text-muted">'.hms_h((string)$row['department']).'</td></tr>';
                                }
                                if ($cnt === 0) echo '<tr><td colspan="3" class="text-center text-muted py-4">No appointments for today.</td></tr>';
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Consultations -->
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                        <span class="font-weight-bold"><i class="fa fa-stethoscope mr-1 text-success"></i> Recent Consultations</span>
                        <a href="consultations.php" class="btn btn-sm btn-outline-success">All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light"><tr><th>Patient</th><th>Date</th></tr></thead>
                                <tbody>
                                <?php
                                $cnt = 0;
                                if ($qConsults) while ($row = mysqli_fetch_assoc($qConsults)) {
                                    $cnt++;
                                    $pName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                                    if (!$pName) $pName = 'Patient #'.$row['patient_id'];
                                    echo '<tr><td class="font-weight-bold">'.hms_h($pName).'</td><td class="small text-muted">'.hms_h((string)($row['consultation_date'] ?? $row['created_at'] ?? '')).'</td></tr>';
                                }
                                if ($cnt === 0) echo '<tr><td colspan="2" class="text-center text-muted py-4">No recent consultations.</td></tr>';
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $docNotices = [];
        if (hms_db_table_exists($connection, 'tbl_result_shared_notice')) {
            $qdn = mysqli_query(
                $connection,
                'SELECT patient_id, lab_result_id, radiology_result_id, test_label, summary, conclusion_code, created_at FROM tbl_result_shared_notice
                 WHERE audience = \'doctor\' AND doctor_employee_id = ' . (int) $uid . '
                 ORDER BY id DESC LIMIT 20'
            );
            while ($qdn && $dn = mysqli_fetch_assoc($qdn)) {
                $docNotices[] = $dn;
            }
        }
        ?>
        <?php if ($docNotices !== []) { ?>
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">
                        <i class="fa fa-bell mr-1 text-primary"></i> Results shared with you (lab &amp; imaging)
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light"><tr><th>Test</th><th>Conclusion</th><th>Summary</th><th>Report</th><th>Date</th></tr></thead>
                                <tbody>
                                    <?php foreach ($docNotices as $dn) {
                                        $labNid = (int) ($dn['lab_result_id'] ?? 0);
                                        $radNid = (int) ($dn['radiology_result_id'] ?? 0);
                                        $pidN = (int) ($dn['patient_id'] ?? 0);
                                        ?>
                                    <tr>
                                        <td><?php echo hms_h((string) ($dn['test_label'] ?? '')); ?></td>
                                        <td><?php echo hms_h((string) ($dn['conclusion_code'] ?? '')); ?></td>
                                        <td class="small"><?php echo nl2br(hms_h((string) ($dn['summary'] ?? ''))); ?></td>
                                        <td class="text-nowrap small">
                                            <?php if ($labNid > 0) { ?>
                                            <a href="clinical-result-report.php?type=lab&amp;id=<?php echo $labNid; ?>">View</a>
                                            <span class="text-muted">·</span>
                                            <a href="clinical-result-report.php?type=lab&amp;id=<?php echo $labNid; ?>&amp;download=1">PDF</a>
                                            <?php } elseif ($radNid > 0) { ?>
                                            <a href="clinical-result-report.php?type=rad&amp;id=<?php echo $radNid; ?>">View</a>
                                            <span class="text-muted">·</span>
                                            <a href="clinical-result-report.php?type=rad&amp;id=<?php echo $radNid; ?>&amp;download=1">PDF</a>
                                            <?php } elseif ($pidN > 0) { ?>
                                            <a href="patient-chart.php?id=<?php echo $pidN; ?>">Chart</a>
                                            <?php } else { ?>
                                            —
                                            <?php } ?>
                                        </td>
                                        <td class="text-nowrap small"><?php echo hms_h((string) ($dn['created_at'] ?? '')); ?></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
