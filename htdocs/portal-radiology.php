<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_portal($connection, 'radiology');
include 'header.php';

$fid   = hms_current_facility_id();
$ms    = hms_multi_site_enabled($connection);
$today = date('Y-m-d');
$radOk = hms_db_table_exists($connection, 'tbl_radiology_result');

// Stats
$statTotal = $statPending = $statDone = $statToday = 0;
if ($radOk) {
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_radiology_result WHERE facility_id=".(int)$fid);
    $statTotal = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_radiology_result WHERE facility_id=".(int)$fid." AND status='pending'");
    $statPending = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_radiology_result WHERE facility_id=".(int)$fid." AND status='received'");
    $statDone = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_radiology_result WHERE facility_id=".(int)$fid." AND DATE(created_at)='$today'");
    $statToday = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $qExams = mysqli_query($connection, "SELECT r.patient_id, p.first_name, p.last_name, r.exam_name, r.modality, r.status, r.created_at FROM tbl_radiology_result r LEFT JOIN tbl_patient p ON p.id=r.patient_id WHERE r.facility_id=".(int)$fid." ORDER BY r.id DESC LIMIT 15");
} else {
    $qExams = false;
}
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#0891b2 0%,#1a6bd8 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Radiology & Imaging Portal</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.85);">Welcome, <?php echo hms_h((string)($_SESSION['name'] ?? 'Radiology Technician')); ?> &mdash; <?php echo date('d F Y'); ?></p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="radiology-results.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#0891b2;"><i class="fa fa-plus mr-1"></i> New Exam</a>
                    <a href="radiology-results.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-film mr-1"></i> All Results</a>
                </div>
            </div>
        </div>

        <?php if (!$radOk) { ?>
        <div class="alert alert-warning">Radiology tables are not yet set up. Please run <code>database/migrations/016_radiology_and_nursing.sql</code>.</div>
        <?php } else { ?>

        <!-- Stats -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['Total Exams',       $statTotal,   'fa-film',         '#0891b2','#cffafe'],
                ['Pending Results',   $statPending, 'fa-clock-o',      '#f59e0b','#fef3c7'],
                ['Completed Results', $statDone,    'fa-check-circle', '#10b981','#d1fae5'],
                ['Orders Today',      $statToday,   'fa-plus-circle',  '#1a6bd8','#dbeafe'],
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
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">Radiology Tools</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['service-code-verify.php?portal=radiology', 'fa-ticket',     'Verify payment code', '#0c8b8b'],
                        ['radiology-results.php',  'fa-film',        'Radiology Results',  '#0891b2'],
                        ['radiology-results.php',  'fa-plus-circle', 'Add Exam',           '#10b981'],
                        ['patients.php',           'fa-users',       'Patient List',       '#0ea5e9'],
                        ['visits.php',             'fa-h-square',    'Visits',             '#64748b'],
                    ];
                    foreach ($actions as $hmsAr) {
                        $url = $hmsAr[0];
                        $icon = $hmsAr[1];
                        $label = $hmsAr[2];
                        $color = $hmsAr[3];
                        ?>
                    <div class="col-6 col-md-3 mb-3">
                        <a href="<?php echo $url; ?>" class="card border-0 shadow-sm text-center p-3 d-block text-decoration-none">
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

        <!-- Recent Exams -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                <span class="font-weight-bold"><i class="fa fa-film mr-1" style="color:#0891b2;"></i> Recent Radiology Orders</span>
                <a href="radiology-results.php" class="btn btn-sm btn-primary" style="background:#0891b2;border-color:#0891b2;">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Patient</th><th>Exam</th><th>Modality</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php
                        $cnt = 0;
                        $stMap = ['pending'=>['warning','Pending'],'in_progress'=>['info','In Progress'],'received'=>['success','Completed']];
                        if ($qExams) while ($row = mysqli_fetch_assoc($qExams)) {
                            $cnt++;
                            $st = $row['status'] ?? 'pending';
                            [$cls, $lbl] = $stMap[$st] ?? ['secondary', ucfirst($st)];
                            $pName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                            if (!$pName) $pName = 'Patient #'.$row['patient_id'];
                            echo '<tr><td class="font-weight-bold">'.hms_h($pName).'</td>
                                <td>'.hms_h((string)$row['exam_name']).'</td>
                                <td class="small text-muted">'.hms_h((string)$row['modality']).'</td>
                                <td><span class="badge badge-'.$cls.'">'.$lbl.'</span></td>
                                <td class="small text-muted">'.hms_h(date('d M Y', strtotime((string)$row['created_at']))).'</td>
                            </tr>';
                        }
                        if ($cnt === 0) echo '<tr><td colspan="5" class="text-center text-muted py-4">No radiology orders found.</td></tr>';
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
