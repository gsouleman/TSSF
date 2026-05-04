<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_portal($connection, 'laboratory');
include 'header.php';

$fid   = hms_current_facility_id();
$ms    = hms_multi_site_enabled($connection);
$today = date('Y-m-d');
$labOk = hms_db_table_exists($connection, 'tbl_lab_result');

// Stats (safe when table may not exist)
$statTotal = $statPending = $statDone = $statToday = 0;
if ($labOk) {
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_lab_result WHERE 1=1");
    $statTotal = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_lab_result WHERE status='pending'");
    $statPending = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_lab_result WHERE status='received'");
    $statDone = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_lab_result WHERE DATE(created_at)='$today'");
    $statToday = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $qLabs = mysqli_query($connection, "SELECT lr.patient_id, p.first_name, p.last_name, lr.test_name, lr.status, lr.created_at FROM tbl_lab_result lr LEFT JOIN tbl_patient p ON p.id=lr.patient_id ORDER BY lr.id DESC LIMIT 15");
} else {
    $qLabs = false;
}
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#8b5cf6 0%,#1a6bd8 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Laboratory Portal</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.85);">Welcome, <?php echo hms_h((string)($_SESSION['name'] ?? 'Lab Technician')); ?> &mdash; <?php echo date('d F Y'); ?></p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="lab-result-edit.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#8b5cf6;"><i class="fa fa-plus mr-1"></i> Add Lab Result</a>
                    <a href="lab-worklist.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-list mr-1"></i> Worklist</a>
                </div>
            </div>
        </div>

        <?php if (!$labOk) { ?>
        <div class="alert alert-warning">Laboratory tables are not yet set up. Please run the database migration scripts.</div>
        <?php } else { ?>

        <!-- Stats -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['Total Lab Orders',  $statTotal,   'fa-flask',        '#8b5cf6','#ede9fe'],
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
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">Lab Tools</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['service-code-verify.php?portal=laboratory', 'fa-ticket',     'Verify payment code', '#0c8b8b'],
                        ['lab-worklist.php',       'fa-list',        'Lab Worklist',     '#8b5cf6'],
                        ['lab-results.php',        'fa-flask',       'Lab Results',      '#1a6bd8'],
                        ['lab-result-edit.php',    'fa-plus-circle', 'Add Lab Result',   '#10b981'],
                        ['medical-results.php',    'fa-heartbeat',   'Medical Results',  '#ef4444'],
                        ['medical-result-edit.php','fa-plus-square', 'Add Med Result',   '#f59e0b'],
                        ['patients.php',           'fa-users',       'Patient List',     '#0ea5e9'],
                        ['encounters.php',         'fa-file-text',   'Clinical Orders',  '#0c8b8b'],
                        ['visits.php',             'fa-h-square',    'Visits',           '#64748b'],
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

        <!-- Recent Lab Orders -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                <span class="font-weight-bold"><i class="fa fa-flask mr-1 text-purple"></i> Recent Lab Orders</span>
                <a href="lab-results.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Patient</th><th>Test</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php
                        $cnt = 0;
                        $stMap = ['pending'=>['warning','Pending'],'in_progress'=>['info','In Progress'],'received'=>['success','Completed']];
                        if ($qLabs) while ($row = mysqli_fetch_assoc($qLabs)) {
                            $cnt++;
                            $st = $row['status'] ?? 'pending';
                            [$cls, $lbl] = $stMap[$st] ?? ['secondary', ucfirst($st)];
                            $pName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                            if (!$pName) $pName = 'Patient #'.$row['patient_id'];
                            echo '<tr><td class="font-weight-bold">'.hms_h($pName).'</td>
                                <td>'.hms_h((string)$row['test_name']).'</td>
                                <td><span class="badge badge-'.$cls.'">'.$lbl.'</span></td>
                                <td class="small text-muted">'.hms_h(date('d M Y', strtotime((string)$row['created_at']))).'</td>
                                <td><a href="lab-result-edit.php" class="btn btn-xs btn-outline-primary btn-sm">View</a></td>
                            </tr>';
                        }
                        if ($cnt === 0) echo '<tr><td colspan="5" class="text-center text-muted py-4">No lab orders found.</td></tr>';
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
