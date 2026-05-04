<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$suf = $ms ? ' AND facility_id = ' . (int) $fid : '';
$today = date('Y-m-d');

// Logged-in staff (avoid unused columns; `role` is reserved in MySQL 8+)
$meRow = [];
$stmtMe = mysqli_prepare(
    $connection,
    'SELECT first_name, last_name, `role` FROM tbl_employee WHERE id = ? LIMIT 1'
);
if ($stmtMe) {
    mysqli_stmt_bind_param($stmtMe, 'i', $uid);
    if (mysqli_stmt_execute($stmtMe)) {
        $meRow = function_exists('hms_stmt_fetch_assoc') ? (hms_stmt_fetch_assoc($stmtMe) ?? []) : [];
    }
    mysqli_stmt_close($stmtMe);
}
$myFirstName = trim((string) ($meRow['first_name'] ?? ''));
$myRole = (int) ($meRow['role'] ?? 0);
$roleTitle = $myRole === 8 ? 'Nursing Aid' : 'Nurse';

/** @return int */
$countC = static function (mysqli $conn, string $sql): int {
    $q = mysqli_query($conn, $sql);
    if (!$q) {
        return 0;
    }
    $row = mysqli_fetch_assoc($q);
    if (!is_array($row) || !array_key_exists('c', $row)) {
        return 0;
    }

    return (int) $row['c'];
};

$todayEsc = mysqli_real_escape_string($connection, $today);
$opdOk = function_exists('hms_opd_tables_ready') && hms_opd_tables_ready($connection);
$hasOpdCheckInAt = $opdOk && function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_opd_visit', 'check_in_at');
// Schema uses visit_date + queue_started_at; check_in_at is optional (legacy pages referenced a non-existent column → SQL errors / blank output on some hosts).
$opdTodayWhere = $hasOpdCheckInAt
    ? "DATE(COALESCE(v.check_in_at, v.visit_date))='" . $todayEsc . "'"
    : "v.visit_date='" . $todayEsc . "'";
$opdTodayCountWhere = $hasOpdCheckInAt
    ? "DATE(COALESCE(check_in_at, visit_date))='" . $todayEsc . "'"
    : "visit_date='" . $todayEsc . "'";
$opdOrderBy = $hasOpdCheckInAt
    ? 'COALESCE(v.check_in_at, v.queue_started_at) DESC'
    : 'v.queue_started_at DESC, v.id DESC';
$opdTimeSelect = $hasOpdCheckInAt ? 'COALESCE(v.check_in_at, v.queue_started_at) AS queue_time' : 'v.queue_started_at AS queue_time';

include 'header.php';

// Stats
$statPatients = $countC($connection, "SELECT COUNT(*) AS c FROM tbl_patient WHERE status=1" . $suf);

$statVisitsToday = 0;
if ($opdOk) {
    $statVisitsToday = $countC(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_opd_visit WHERE ' . $opdTodayCountWhere . ($ms ? ' AND facility_id=' . (int) $fid : '')
    );
}

$statAdmitted = 0;
if (hms_db_table_exists($connection, 'tbl_bed')) {
    $statAdmitted = $countC($connection, "SELECT COUNT(*) AS c FROM tbl_bed WHERE status='Occupied'");
}

$statPending = 0;
if ($opdOk) {
    $statPending = $countC(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_opd_visit WHERE queue_status IN ('registered','triage')"
        . ($ms ? ' AND facility_id=' . (int) $fid : '')
    );
}

// Recent visits for today
$recentVisits = [];
if ($opdOk) {
    $vq = mysqli_query(
        $connection,
        'SELECT v.id, v.queue_status, v.chief_complaint, p.first_name, p.last_name, ' . $opdTimeSelect . '
         FROM tbl_opd_visit v
         LEFT JOIN tbl_patient p ON p.id = v.patient_id
         WHERE ' . $opdTodayWhere . ($ms ? ' AND v.facility_id=' . (int) $fid : '')
        . ' ORDER BY ' . $opdOrderBy . ' LIMIT 15'
    );
    while ($vq && $vr = mysqli_fetch_assoc($vq)) {
        $recentVisits[] = $vr;
    }
}
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Welcome Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#ea580c 0%,#f43f5e 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;"><i class="fa fa-heartbeat mr-2"></i>Nurse</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.85);">Welcome, <?php echo hms_h($myFirstName ?: ($_SESSION['name'] ?? 'Nurse')); ?> (<?php echo hms_h($roleTitle); ?>) &mdash; <?php echo date('l, d F Y'); ?></p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="opd-queue.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#ea580c;"><i class="fa fa-list-alt mr-1"></i> OPD Queue</a>
                    <a href="patients.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-users mr-1"></i> Patient List</a>
                </div>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['Patients Today',   $statVisitsToday,  'fa-calendar-check-o','#ea580c','#fff7ed'],
                ['Pending Triage',   $statPending,       'fa-clock-o',         '#f59e0b','#fef3c7'],
                ['Beds Occupied',    $statAdmitted,      'fa-bed',             '#f43f5e','#ffe4e6'],
                ['Total Patients',   $statPatients,      'fa-users',           '#8b5cf6','#ede9fe'],
            ];
            foreach ($stats as $statItem) {
                $label = $statItem[0]; $val = $statItem[1]; $icon = $statItem[2]; $color = $statItem[3]; $bg = $statItem[4]; ?>
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

        <!-- Clinical Tools -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;"><i class="fa fa-heartbeat mr-1" style="color:#ea580c;"></i> Clinical Tools</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['opd-queue.php',          'fa-list-alt',        'OPD Queue',         '#ea580c'],
                        ['vitals-enter.php?station=nursing', 'fa-heartbeat', 'Record vitals', '#f59e0b'],
                        ['visits.php',             'fa-list-ol',         'OPD visits',        '#d97706'],
                        ['adt-board.php',          'fa-bed',             'Admitted Patients',  '#f43f5e'],
                        ['patients.php',           'fa-users',           'Patient List',      '#0ea5e9'],
                        ['lab-results.php',        'fa-flask',           'Lab Results',       '#8b5cf6'],
                        ['radiology-results.php',  'fa-film',            'Radiology',         '#0891b2'],
                        ['prescriptions.php',      'fa-medkit',          'Prescriptions',     '#10b981'],
                        ['appointments.php',       'fa-calendar',        'Appointments',      '#1a6bd8'],
                    ];
                    foreach ($actions as $actItem) {
                        $url = $actItem[0]; $icon = $actItem[1]; $label = $actItem[2]; $color = $actItem[3]; ?>
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

        <!-- Today's Patient Activity -->
        <div class="row">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                        <span class="font-weight-bold"><i class="fa fa-heartbeat mr-1" style="color:#ea580c;"></i> Today's Patient Activity</span>
                        <a href="visits.php" class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light"><tr><th>Patient</th><th>Status</th><th>Chief Complaint</th><th>Check-in</th></tr></thead>
                                <tbody>
                                <?php
                                if (empty($recentVisits)) {
                                    echo '<tr><td colspan="4" class="text-center text-muted py-4">No patient visits recorded today.</td></tr>';
                                }
                                $qsMap = [
                                    'registered' => ['info', 'Registered'],
                                    'triage' => ['warning', 'Triage'],
                                    'waiting_doctor' => ['primary', 'Waiting (doctor)'],
                                    'in_consultation' => ['primary', 'In consultation'],
                                    'orders_pending' => ['dark', 'Orders / investigations'],
                                    'doctor' => ['primary', 'With Doctor'],
                                    'orders' => ['dark', 'Orders'],
                                    'billing' => ['success', 'Billing'],
                                    'completed' => ['secondary', 'Completed'],
                                    'done' => ['secondary', 'Done'],
                                    'cancelled' => ['danger', 'Cancelled'],
                                ];
                                foreach ($recentVisits as $vr) {
                                    $pName = trim(($vr['first_name'] ?? '').' '.($vr['last_name'] ?? ''));
                                    if (!$pName) $pName = 'Unknown';
                                    $qs = $vr['queue_status'] ?? 'registered';
                                    $qsData = $qsMap[$qs] ?? ['secondary', ucfirst($qs)];
                                    $bCls = $qsData[0]; $bLbl = $qsData[1];
                                    $cc = trim((string)($vr['chief_complaint'] ?? '')) ?: '—';
                                    $qt = $vr['queue_time'] ?? '';
                                    $ci = $qt !== '' && $qt !== null ? date('H:i', strtotime((string) $qt)) : '—';
                                    echo '<tr>
                                        <td class="font-weight-bold">'.hms_h($pName).'</td>
                                        <td><span class="badge badge-'.$bCls.'">'.$bLbl.'</span></td>
                                        <td class="small text-muted">'.hms_h($cc).'</td>
                                        <td class="small text-muted">'.$ci.'</td>
                                    </tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
