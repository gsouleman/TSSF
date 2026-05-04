<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_portal($connection, 'pharmacy');
include 'header.php';

$fid   = hms_current_facility_id();
$ms    = hms_multi_site_enabled($connection);
$today    = date('Y-m-d');
$todayEsc = mysqli_real_escape_string($connection, $today);
$rxOk  = hms_workflow_table_ok($connection, 'tbl_prescription');

// Stats (only when table exists)
$statTotal = $statToday = $statActive = $statDone = 0;
$qPresc = false;
if ($rxOk) {
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_prescription WHERE facility_id=".(int)$fid);
    $statTotal = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_prescription WHERE facility_id=".(int)$fid." AND DATE(created_at)='$todayEsc'");
    $statToday = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_prescription WHERE facility_id=".(int)$fid." AND status='active'");
    $statActive = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $q = mysqli_query($connection, "SELECT COUNT(*) AS c FROM tbl_prescription WHERE facility_id=".(int)$fid." AND status='dispensed'");
    $statDone = $q ? (int)(mysqli_fetch_assoc($q)['c'] ?? 0) : 0;
    $qPresc = mysqli_query($connection,
        "SELECT r.id, r.patient_id, r.title, r.status, r.created_at, p.first_name, p.last_name
         FROM tbl_prescription r LEFT JOIN tbl_patient p ON p.id=r.patient_id
         WHERE r.facility_id=".(int)$fid." ORDER BY r.id DESC LIMIT 15"
    );
}
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <!-- Banner -->
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#10b981 0%,#0c8b8b 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Pharmacy Portal</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.85);">Welcome, <?php echo hms_h((string)($_SESSION['name'] ?? 'Pharmacist')); ?> &mdash; <?php echo date('d F Y'); ?></p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="prescriptions.php" class="btn btn-light btn-sm font-weight-bold mr-2" style="color:#10b981;"><i class="fa fa-list mr-1"></i> Prescriptions Queue</a>
                    <a href="pharmacy.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-medkit mr-1"></i> Pharmacy</a>
                </div>
            </div>
        </div>

        <?php if (!$rxOk) { ?>
        <div class="alert alert-warning">Prescription tables are not yet set up. Please run <code>003_clinical_workflow.sql</code> migration.</div>
        <?php } else { ?>

        <!-- Stats -->
        <div class="row mb-4">
            <?php
            $stats = [
                ['Total Prescriptions', $statTotal,  'fa-medkit',      '#10b981','#d1fae5'],
                ['Issued Today',        $statToday,  'fa-plus-circle', '#1a6bd8','#dbeafe'],
                ['Active / Pending',    $statActive, 'fa-clock-o',     '#f59e0b','#fef3c7'],
                ['Dispensed',           $statDone,   'fa-check-circle','#8b5cf6','#ede9fe'],
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
            <div class="card-header bg-white font-weight-bold" style="border-bottom:2px solid #e2e8f0;">Pharmacy Tools</div>
            <div class="card-body">
                <div class="row">
                    <?php
                    $actions = [
                        ['service-code-verify.php?portal=pharmacy', 'fa-ticket',     'Verify payment code', '#0c8b8b'],
                        ['prescriptions.php',    'fa-list',        'All Prescriptions', '#10b981'],
                        ['prescription-new.php', 'fa-plus-circle', 'New Prescription',  '#1a6bd8'],
                        ['pharmacy.php',         'fa-medkit',      'Pharmacy & stock',  '#8b5cf6'],
                        ['patients.php',         'fa-users',       'Patient List',      '#f59e0b'],
                        ['billing-payments.php', 'fa-credit-card', 'Billing',           '#ef4444'],
                        ['lab-results.php',      'fa-flask',       'Lab Results',       '#0c8b8b'],
                        ['inventory.php',        'fa-archive',     'Inventory',         '#64748b'],
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

        <!-- Prescriptions Queue -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e2e8f0;">
                <span class="font-weight-bold"><i class="fa fa-medkit mr-1 text-success"></i> Prescriptions Queue</span>
                <a href="prescriptions.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Patient</th><th>Prescription</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php
                        $cnt = 0;
                        $stMap = ['active'=>['warning','Active'],'dispensed'=>['success','Dispensed'],'cancelled'=>['danger','Cancelled']];
                        if ($qPresc) while ($row = mysqli_fetch_assoc($qPresc)) {
                            $cnt++;
                            $st = strtolower((string)($row['status'] ?? 'active'));
                            [$cls, $lbl] = $stMap[$st] ?? ['secondary', ucfirst($st)];
                            $pName = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
                            if (!$pName) $pName = 'Patient #'.$row['patient_id'];
                            echo '<tr>
                                <td class="font-weight-bold">'.hms_h($pName).'</td>
                                <td>'.hms_h((string)$row['title']).'</td>
                                <td><span class="badge badge-'.$cls.'">'.$lbl.'</span></td>
                                <td class="small text-muted">'.hms_h(date('d M Y', strtotime((string)$row['created_at']))).'</td>
                                <td><a href="prescription.php?id='.(int)$row['id'].'" class="btn btn-sm btn-outline-primary">Open</a></td>
                            </tr>';
                        }
                        if ($cnt === 0) echo '<tr><td colspan="5" class="text-center text-muted py-4">No prescriptions found.</td></tr>';
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
