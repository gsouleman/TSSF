<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/appointments_dreams.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'scheduling.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$hasPatientIdCol = hms_db_column_exists($connection, 'tbl_appointment', 'patient_id');
$canWrite = hms_can($connection, 'scheduling.write');

$y = (int) ($_GET['y'] ?? (int) date('Y'));
$m = (int) ($_GET['m'] ?? (int) date('n'));
if ($m < 1) {
    $m = 1;
}
if ($m > 12) {
    $m = 12;
}
if ($y < 2000) {
    $y = (int) date('Y');
}
if ($y > 2100) {
    $y = (int) date('Y');
}

$firstDay = sprintf('%04d-%02d-01', $y, $m);
$lastDay = date('Y-m-t', strtotime($firstDay));
$monthLabel = date('F Y', strtotime($firstDay));

$patJoin = $hasPatientIdCol
    ? 'LEFT JOIN tbl_patient p ON p.id = a.patient_id AND a.patient_id IS NOT NULL AND a.patient_id > 0'
    : 'LEFT JOIN tbl_patient p ON 1=0';
$dateNorm = "DATE(COALESCE(STR_TO_DATE(NULLIF(TRIM(a.date),''), '%d/%m/%Y'), STR_TO_DATE(NULLIF(TRIM(a.date),''), '%Y-%m-%d')))";
$w = ["{$dateNorm} BETWEEN '" . mysqli_real_escape_string($connection, $firstDay) . "' AND '" . mysqli_real_escape_string($connection, $lastDay) . "'"];
if ($ms) {
    $w[] = 'a.facility_id = ' . (int) $fid;
}
$where = implode(' AND ', $w);
$sql = "SELECT a.id, a.date, a.time, a.patient_name, a.doctor, a.status, p.first_name AS p_fn, p.last_name AS p_ln FROM tbl_appointment a {$patJoin} WHERE {$where} ORDER BY {$dateNorm} ASC, a.id ASC";
$byDay = [];
$q = mysqli_query($connection, $sql);
while ($q && $r = mysqli_fetch_assoc($q)) {
    $ymd = hms_appt_parse_date_ymd((string) ($r['date'] ?? ''));
    if ($ymd === null) {
        continue;
    }
    if (!isset($byDay[$ymd])) {
        $byDay[$ymd] = [];
    }
    if (count($byDay[$ymd]) < 6) {
        $byDay[$ymd][] = $r;
    }
}

$tsFirst = strtotime($firstDay);
$startWeekday = (int) date('w', $tsFirst);
$daysInMonth = (int) date('t', $tsFirst);
$prevM = $m - 1;
$prevY = $y;
if ($prevM < 1) {
    $prevM = 12;
    $prevY--;
}
$nextM = $m + 1;
$nextY = $y;
if ($nextM > 12) {
    $nextM = 1;
    $nextY++;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
}

include __DIR__ . '/header.php';

?>
        <div class="page-wrapper">
            <div class="content hms-module hms-appts-page hms-appts-dreams">
                <form method="get" action="appointments-calendar.php" class="hms-appts-keyword-bar card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <label class="sr-only" for="hmsCalY">Year</label>
                                <input type="number" name="y" id="hmsCalY" class="form-control" value="<?php echo (int) $y; ?>" min="2000" max="2100" title="Year">
                            </div>
                            <div style="width:140px" class="mr-2">
                                <label class="sr-only" for="hmsCalM">Month</label>
                                <select name="m" id="hmsCalM" class="form-control">
                                    <?php for ($mi = 1; $mi <= 12; $mi++) {
                                        $sel = $mi === $m ? ' selected' : '';
                                        echo '<option value="' . $mi . '"' . $sel . '>' . hms_h(date('F', mktime(0, 0, 0, $mi, 1, $y))) . '</option>';
                                    } ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary px-4 hms-appts-keyword-btn" title="Go"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Calendar</h1>
                        <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="appointments.php">Appointments</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Calendar</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center no-print">
                        <a class="btn btn-light border btn-sm font-weight-bold mr-1" href="<?php echo hms_h('appointments-calendar.php?y=' . $prevY . '&m=' . $prevM); ?>"><i class="fa fa-angle-left"></i></a>
                        <a class="btn btn-light border btn-sm font-weight-bold mr-1" href="<?php echo hms_h('appointments-calendar.php?y=' . date('Y') . '&m=' . date('n')); ?>">Today</a>
                        <a class="btn btn-light border btn-sm font-weight-bold mr-2" href="<?php echo hms_h('appointments-calendar.php?y=' . $nextY . '&m=' . $nextM); ?>"><i class="fa fa-angle-right"></i></a>
                        <a href="appointments.php" class="btn btn-outline-secondary btn-sm font-weight-bold mr-1">List view</a>
                        <?php if ($canWrite) { ?>
                        <a href="appointments.php" class="btn btn-primary btn-sm font-weight-bold px-3">+ New Appointment</a>
                        <?php } ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm hms-appt-cal-card">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
                        <span class="h5 mb-0 font-weight-bold text-uppercase text-muted" style="letter-spacing:0.08em"><?php echo hms_h($monthLabel); ?></span>
                        <div class="btn-group btn-group-sm no-print" role="group" aria-label="View">
                            <button type="button" class="btn btn-primary active" disabled>Month</button>
                            <button type="button" class="btn btn-light border" disabled title="Coming soon">Week</button>
                            <button type="button" class="btn btn-light border" disabled title="Coming soon">Day</button>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <div class="hms-appt-cal-grid">
                            <?php
                            $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($weekdays as $wd) {
                                echo '<div class="hms-appt-cal-wd">' . hms_h($wd) . '</div>';
                            }
                            $pad = $startWeekday;
                            $prevMonthDays = (int) date('t', strtotime($firstDay . ' -1 day'));
                            for ($i = 0; $i < $pad; $i++) {
                                $d = $prevMonthDays - $pad + $i + 1;
                                echo '<div class="hms-appt-cal-cell hms-appt-cal-cell--muted"><span class="hms-appt-cal-daynum">' . (int) $d . '</span></div>';
                            }
                            $todayYmd = date('Y-m-d');
                            for ($d = 1; $d <= $daysInMonth; $d++) {
                                $ymd = sprintf('%04d-%02d-%02d', $y, $m, $d);
                                $isToday = ($ymd === $todayYmd);
                                $cellClass = 'hms-appt-cal-cell' . ($isToday ? ' hms-appt-cal-cell--today' : '');
                                echo '<div class="' . $cellClass . '"><span class="hms-appt-cal-daynum">' . $d . '</span>';
                                if (!empty($byDay[$ymd])) {
                                    echo '<div class="hms-appt-cal-avatars">';
                                    foreach ($byDay[$ymd] as $ar) {
                                        $ini = hms_appt_patient_initials($ar);
                                        echo '<a class="hms-appt-cal-av" href="edit-appointment.php?id=' . (int) $ar['id'] . '" title="' . hms_h(hms_appt_patient_display_name($ar)) . '">' . hms_h($ini) . '</a>';
                                    }
                                    echo '</div>';
                                }
                                echo '</div>';
                            }
                            $totalCells = $pad + $daysInMonth;
                            $tail = (7 - ($totalCells % 7)) % 7;
                            for ($i = 1; $i <= $tail; $i++) {
                                echo '<div class="hms-appt-cal-cell hms-appt-cal-cell--muted"><span class="hms-appt-cal-daynum">' . $i . '</span></div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
