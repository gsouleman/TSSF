<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (function_exists('hms_staff_portal_nav_is_limited') && hms_staff_portal_nav_is_limited((string) ($_SESSION['role'] ?? ''))) {
    $dest = function_exists('hms_login_redirect_after_auth')
        ? hms_login_redirect_after_auth($connection, (int) ($_SESSION['user_id'] ?? 0), (string) ($_SESSION['role'] ?? ''))
        : 'dashboard.php';
    if ($dest !== '' && $dest !== 'dashboard.php') {
        header('Location: ' . $dest);
        exit;
    }
}

$hmsDashInventoryHub = function_exists('hms_dashboard_inventory_hub') && hms_dashboard_inventory_hub($connection);
if (!$hmsDashInventoryHub && (!function_exists('hms_is_super_admin') || !hms_is_super_admin())
    && function_exists('hms_product_mode_login_landing')) {
    $hmsDashAlt = hms_product_mode_login_landing($connection);
    if (is_string($hmsDashAlt) && $hmsDashAlt !== '') {
        header('Location: ' . $hmsDashAlt);
        exit;
    }
}
include 'header.php';

if ($hmsDashInventoryHub) {
    hms_require_permission($connection, 'inventory.read');
    $fid = hms_current_facility_id();
    $invCtx = hms_inventory_product_context($connection);
    $tableOk = hms_db_table_exists($connection, 'tbl_inventory_item');
    $movOk = hms_inventory_movement_table_ok($connection);
    $lowStock = $tableOk ? hms_inventory_low_stock_rows($connection, $fid) : [];
    $invKpiSkus = 0;
    $invKpiUnits = 0;
    if ($tableOk) {
        $invKq = mysqli_query(
            $connection,
            'SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS u FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid
        );
        if ($invKq && $invKr = mysqli_fetch_assoc($invKq)) {
            $invKpiSkus = (int) ($invKr['c'] ?? 0);
            $invKpiUnits = (int) ($invKr['u'] ?? 0);
        }
    }
    $movements = $movOk ? hms_inventory_recent_movements($connection, $fid, 12) : [];
    $poByStatus = ['draft' => 0, 'approved' => 0, 'issued' => 0, 'received' => 0, 'cancelled' => 0, 'other' => 0];
    if ($tableOk && hms_db_table_exists($connection, 'tbl_purchase_order')) {
        $pq = mysqli_query(
            $connection,
            'SELECT status, COUNT(*) AS n FROM tbl_purchase_order WHERE facility_id = ' . (int) $fid . ' GROUP BY status'
        );
        while ($pq && $pr = mysqli_fetch_assoc($pq)) {
            $st = (string) ($pr['status'] ?? '');
            $n = (int) ($pr['n'] ?? 0);
            if (array_key_exists($st, $poByStatus)) {
                $poByStatus[$st] = $n;
            } else {
                $poByStatus['other'] += $n;
            }
        }
    }
    $hubSecondary = [
        ['label' => 'Full inventory', 'url' => 'inventory.php', 'icon' => 'fa-cubes'],
        ['label' => 'PDF — Low stock', 'url' => 'inventory-report-pdf.php?type=lowstock', 'icon' => 'fa-file-pdf-o'],
    ];
    if (!empty($invCtx['catalog_on'])) {
        $hubSecondary[] = ['label' => 'Service catalog', 'url' => 'service-catalog.php?tab=pharmacy', 'icon' => 'fa-tags'];
    }
    if (!empty($invCtx['procurement_on'])) {
        $hubSecondary[] = ['label' => 'Procurement', 'url' => 'procurement-home.php', 'icon' => 'fa-shopping-basket'];
    }
    ?>
        <div class="page-wrapper">
            <div class="content hms-module hms-inventory hms-dash-inv">
                <?php
                hms_ui_page_header('Dashboard', [
                    'subtitle' => 'Stock snapshot for this site — no clinical metrics. Open full inventory for receiving, adjustments, and purchase orders.',
                    'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Stock overview', '']],
                    'secondary' => $hubSecondary,
                ]);
                ?>
                <div class="hms-inv-hero mb-4">
                    <div class="hms-inv-hero-inner">
                        <span class="hms-inv-hero-eyebrow">Inventory mode</span>
                        <h1 class="hms-inv-hero-title">Stock overview</h1>
                        <p class="hms-inv-hero-lead">You are on a deployment without the clinical dashboard. Use the tiles below, or go to <strong>Inventory &amp; stock</strong> for the complete workspace.</p>
                    </div>
                </div>
                <div class="hms-inv-kpi-row mb-4">
                    <div class="hms-inv-kpi">
                        <div class="hms-inv-kpi-label">SKUs</div>
                        <div class="hms-inv-kpi-value"><?php echo (int) $invKpiSkus; ?></div>
                    </div>
                    <div class="hms-inv-kpi">
                        <div class="hms-inv-kpi-label">Units on hand</div>
                        <div class="hms-inv-kpi-value"><?php echo (int) $invKpiUnits; ?></div>
                    </div>
                    <div class="hms-inv-kpi<?php echo count($lowStock) > 0 ? ' hms-inv-kpi--warn' : ''; ?>">
                        <div class="hms-inv-kpi-label">Below reorder</div>
                        <div class="hms-inv-kpi-value"><?php echo count($lowStock); ?></div>
                    </div>
                    <div class="hms-inv-kpi">
                        <div class="hms-inv-kpi-label">POs (draft)</div>
                        <div class="hms-inv-kpi-value"><?php echo (int) $poByStatus['draft']; ?></div>
                    </div>
                    <div class="hms-inv-kpi">
                        <div class="hms-inv-kpi-label">POs (issued)</div>
                        <div class="hms-inv-kpi-value"><?php echo (int) $poByStatus['issued']; ?></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                                <span>Low stock</span>
                                <a class="btn btn-sm btn-outline-primary" href="inventory.php">Inventory</a>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>SKU</th><th>Item</th><th class="text-right">Qty</th><th class="text-right">Reorder</th></tr></thead>
                                        <tbody>
                                        <?php foreach (array_slice($lowStock, 0, 10) as $ls) { ?>
                                        <tr>
                                            <td class="text-monospace small"><?php echo hms_h((string) ($ls['sku'] ?? '')); ?></td>
                                            <td><?php echo hms_h((string) ($ls['name'] ?? '')); ?></td>
                                            <td class="text-right font-weight-bold"><?php echo (int) ($ls['quantity'] ?? 0); ?></td>
                                            <td class="text-right"><?php echo (int) ($ls['reorder_level'] ?? 0); ?></td>
                                        </tr>
                                        <?php } ?>
                                        <?php if ($lowStock === []) { ?>
                                        <tr><td colspan="4" class="text-center text-muted small py-4">No low-stock lines.</td></tr>
                                        <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white">Recent movements</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead><tr><th>When</th><th>Item</th><th class="text-right">Δ</th><th>Type</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($movements as $m) { ?>
                                        <tr>
                                            <td class="text-nowrap small"><?php echo hms_h((string) ($m['created_at'] ?? '')); ?></td>
                                            <td class="small"><?php echo hms_h((string) ($m['item_name'] ?? '')); ?></td>
                                            <td class="text-right font-weight-bold"><?php echo (int) ($m['qty_delta'] ?? 0); ?></td>
                                            <td><span class="badge badge-light border"><?php echo hms_h((string) ($m['movement_type'] ?? '')); ?></span></td>
                                        </tr>
                                        <?php } ?>
                                        <?php if ($movements === []) { ?>
                                        <tr><td colspan="4" class="text-center text-muted small py-4"><?php echo $movOk ? 'No movements yet.' : 'Run migration 027 for movement history.'; ?></td></tr>
                                        <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($tableOk && hms_db_table_exists($connection, 'tbl_purchase_order')) { ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">Purchase orders by status</div>
                    <div class="card-body small">
                        <div class="d-flex flex-wrap">
                            <?php
                            $poLbl = [
                                'draft' => 'Draft',
                                'approved' => 'Approved',
                                'issued' => 'Sent to vendor',
                                'received' => 'Received',
                                'cancelled' => 'Cancelled',
                                'other' => 'Other',
                            ];
                            foreach ($poByStatus as $label => $cnt) {
                                if ($label === 'other' && $cnt < 1) {
                                    continue;
                                }
                                $disp = $poLbl[$label] ?? $label;
                                ?>
                            <span class="mr-3 mb-2"><strong><?php echo hms_h($disp); ?>:</strong> <?php echo (int) $cnt; ?></span>
                            <?php } ?>
                        </div>
                        <p class="text-muted mb-0 mt-2">Open <a href="inventory.php">Inventory</a> to create auto-POs or follow individual POs.</p>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    <?php
    include 'footer.php';
    exit;
}

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$suf = $ms ? ' AND facility_id = ' . (int) $fid : '';

if ($ms) {
    $qDoc = mysqli_query(
        $connection,
        'SELECT COUNT(DISTINCT e.id) AS total FROM tbl_employee e
         INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
         WHERE e.status = 1 AND e.role = 2 AND uf.facility_id = ' . (int) $fid
    );
} else {
    $qDoc = mysqli_query($connection, 'SELECT COUNT(*) AS total FROM tbl_employee WHERE status = 1 AND role = 2');
}
$statDoctors = (int) (($qDoc ? mysqli_fetch_assoc($qDoc) : ['total' => 0])['total'] ?? 0);

$qPat = mysqli_query($connection, 'SELECT COUNT(*) AS total FROM tbl_patient WHERE status = 1' . $suf);
$statPatients = (int) (($qPat ? mysqli_fetch_assoc($qPat) : ['total' => 0])['total'] ?? 0);

$qAppt = mysqli_query($connection, 'SELECT COUNT(*) AS total FROM tbl_appointment WHERE status = 1' . $suf);
$statAppointments = (int) (($qAppt ? mysqli_fetch_assoc($qAppt) : ['total' => 0])['total'] ?? 0);

$qOut = mysqli_query(
    $connection,
    "SELECT COUNT(*) AS total FROM tbl_patient WHERE patient_type = 'OutPatient' AND status = 1" . $suf
);
$statOutpatient = (int) (($qOut ? mysqli_fetch_assoc($qOut) : ['total' => 0])['total'] ?? 0);

$qIn = mysqli_query(
    $connection,
    "SELECT COUNT(*) AS total FROM tbl_patient WHERE patient_type = 'InPatient' AND status = 1" . $suf
);
$statInpatient = (int) (($qIn ? mysqli_fetch_assoc($qIn) : ['total' => 0])['total'] ?? 0);

$chartLabels = [];
$chartValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $chartLabels[] = date('D', strtotime($day));
    $esc = mysqli_real_escape_string($connection, $day);
    $qTrend = mysqli_query(
        $connection,
        "SELECT COUNT(*) AS c FROM tbl_patient WHERE status = 1 AND DATE(created_at) = '" . $esc . "'" . $suf
    );
    $rowT = $qTrend ? mysqli_fetch_assoc($qTrend) : null;
    $chartValues[] = (int) ($rowT['c'] ?? 0);
}

$chartLabelsJson = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$chartValuesJson = json_encode($chartValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($chartLabelsJson === false) {
    $chartLabelsJson = '[]';
}
if ($chartValuesJson === false) {
    $chartValuesJson = '[]';
}

$qRecentAppt = mysqli_query(
    $connection,
    'SELECT patient_name, doctor, date, time, department FROM tbl_appointment WHERE status = 1'
        . $suf
        . ' ORDER BY id DESC LIMIT 6'
);
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-dashboard-page">
                <?php
                $dashSecondary = [
                    ['label' => 'Front office', 'url' => 'front-office.php', 'icon' => 'fa-desktop'],
                ];
                if (hms_can($connection, 'clinical.read')) {
                    $dashSecondary[] = ['label' => 'Notes & Docs', 'url' => 'clinical-documentation.php', 'icon' => 'fa-file-text-o'];
                }
                $dashSecondary = array_merge($dashSecondary, [
                    ['label' => 'Billing', 'url' => 'billing-payments.php', 'icon' => 'fa-credit-card'],
                    ['label' => 'Reports', 'url' => 'reports-analysis.php', 'icon' => 'fa-bar-chart'],
                    ['label' => 'Ward & Bed MGT', 'url' => 'adt-board.php', 'icon' => 'fa-bed'],
                ]);
                hms_ui_page_header('Dashboard', [
                    'subtitle' => 'Operational snapshot for this site — same information as before, with a cleaner EMR-style layout.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Overview', '']],
                    'secondary' => $dashSecondary,
                ]);
                ?>
                <div class="card border-0 shadow-sm hms-dash-shortcuts mb-4">
                    <div class="card-body py-3 d-flex flex-wrap align-items-center">
                        <span class="small text-muted font-weight-bold text-uppercase mr-3 mb-2 mb-md-0" style="letter-spacing: 0.06em;">Shortcuts</span>
                        <div class="d-flex flex-wrap">
                            <a class="btn btn-sm btn-outline-secondary rounded-pill mr-2 mb-2" href="front-office.php"><i class="fa fa-desktop mr-1"></i> Front office</a>
                            <?php if (hms_can($connection, 'clinical.read')) { ?>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill mr-2 mb-2" href="clinical-documentation.php"><i class="fa fa-file-text-o mr-1"></i> Notes &amp; Docs</a>
                            <?php } ?>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill mr-2 mb-2" href="billing-payments.php"><i class="fa fa-credit-card mr-1"></i> Billing</a>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill mr-2 mb-2" href="insurance.php"><i class="fa fa-shield mr-1"></i> Insurance</a>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill mr-2 mb-2" href="reports-analysis.php"><i class="fa fa-line-chart mr-1"></i> Reporting</a>
                        </div>
                    </div>
                </div>

                <div class="row hms-dash-stat-row">
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="doctors.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--primary" aria-hidden="true"><i class="fa fa-stethoscope"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Doctors</span>
                                <span class="hms-stat-card__value"><?php echo $statDoctors; ?></span>
                                <span class="hms-stat-card__hint">Active providers</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="patients.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--teal" aria-hidden="true"><i class="fa fa-users"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Patients</span>
                                <span class="hms-stat-card__value"><?php echo $statPatients; ?></span>
                                <span class="hms-stat-card__hint">Active records</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="appointments.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--violet" aria-hidden="true"><i class="fa fa-calendar-check-o"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Appointments</span>
                                <span class="hms-stat-card__value"><?php echo $statAppointments; ?></span>
                                <span class="hms-stat-card__hint">Scheduled</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="patients.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--amber" aria-hidden="true"><i class="fa fa-medkit"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Outpatients</span>
                                <span class="hms-stat-card__value"><?php echo $statOutpatient; ?></span>
                                <span class="hms-stat-card__hint">OPD cohort</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="patients.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--rose" aria-hidden="true"><i class="fa fa-hospital-o"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Inpatients</span>
                                <span class="hms-stat-card__value"><?php echo $statInpatient; ?></span>
                                <span class="hms-stat-card__hint">Admitted</span>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2 mb-3">
                        <a class="hms-stat-card" href="add-appointment.php">
                            <span class="hms-stat-card__icon hms-stat-card__icon--slate" aria-hidden="true"><i class="fa fa-calendar-plus-o"></i></span>
                            <div class="hms-stat-card__body">
                                <span class="hms-stat-card__label">Scheduling</span>
                                <span class="hms-stat-card__value hms-stat-card__value--sm">Book</span>
                                <span class="hms-stat-card__hint">New appointment</span>
                            </div>
                        </a>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-8 mb-4 mb-lg-0">
                        <div class="card hms-dash-chart-card h-100">
                            <div class="card-header d-flex flex-wrap justify-content-between align-items-center bg-white">
                                <div>
                                    <h4 class="card-title mb-0">Patient registrations</h4>
                                    <span class="small text-muted">New active patients by day (last 7 days)</span>
                                </div>
                                <span class="badge badge-light text-muted border mt-2 mt-sm-0">This site</span>
                            </div>
                            <div class="card-body pt-2">
                                <div class="hms-dash-chart-wrap">
                                    <canvas id="hmsDashPatientTrend" aria-label="Patient registration trend chart" role="img"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card hms-dash-side-card h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h4 class="card-title mb-0">Recent appointments</h4>
                                <a href="appointments.php" class="btn btn-sm btn-outline-primary">All</a>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover hms-dash-appt-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Patient</th>
                                                <th>When</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $recentApptCount = 0;
                                            if ($qRecentAppt) {
                                                while ($ap = mysqli_fetch_assoc($qRecentAppt)) {
                                                    $recentApptCount++;
                                                    ?>
                                            <tr>
                                                <td>
                                                    <div class="hms-dash-appt-name"><?php echo hms_h((string) $ap['patient_name']); ?></div>
                                                    <div class="hms-dash-appt-meta"><?php echo hms_h((string) $ap['doctor']); ?> · <?php echo hms_h((string) $ap['department']); ?></div>
                                                </td>
                                                <td class="text-nowrap small text-muted">
                                                    <?php echo hms_h((string) $ap['date']); ?><br>
                                                    <?php echo hms_h((string) $ap['time']); ?>
                                                </td>
                                            </tr>
                                                    <?php
                                                }
                                            }
                                            if ($recentApptCount === 0) {
                                                echo '<tr><td colspan="2" class="text-center text-muted py-4">No appointments yet.</td></tr>';
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 col-lg-8 mb-4 mb-lg-0">
                        <div class="card hms-dash-table-card">
                            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
                                <h4 class="card-title mb-0">New patients</h4>
                                <a href="patients.php" class="btn btn-sm btn-primary">View all</a>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table mb-0 hms-dash-patient-table">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Patient</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Type</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $fetch_query = mysqli_query(
                                                $connection,
                                                'SELECT id, first_name, last_name, email, phone, patient_type FROM tbl_patient WHERE 1 = 1' . $suf . ' ORDER BY id DESC LIMIT 5'
                                            );
                                            while ($fetch_query && $row = mysqli_fetch_array($fetch_query)) {
                                                ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <img width="36" height="36" class="rounded-circle mr-2" src="assets/img/user.jpg" alt="">
                                                        <span class="hms-dash-patient-name"><?php echo hms_h($row['first_name'] . ' ' . $row['last_name']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="text-muted small"><?php echo hms_h((string) $row['email']); ?></td>
                                                <td class="text-muted small"><?php echo hms_h((string) $row['phone']); ?></td>
                                                <td>
                                                    <?php if ($row['patient_type'] === 'InPatient') { ?>
                                                    <span class="badge badge-soft-danger"><?php echo hms_h((string) $row['patient_type']); ?></span>
                                                    <?php } else { ?>
                                                    <span class="badge badge-soft-success"><?php echo hms_h((string) $row['patient_type']); ?></span>
                                                    <?php } ?>
                                                </td>
                                            </tr>
                                                <?php
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card hms-dash-team-card h-100">
                            <div class="card-header bg-white">
                                <h4 class="card-title mb-0">Doctors</h4>
                                <span class="small text-muted">On duty at this site</span>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0 hms-dash-team-list">
                                    <?php
                                    if ($ms) {
                                        $fetch_query = mysqli_query(
                                            $connection,
                                            'SELECT e.* FROM tbl_employee e
                                             INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                                             WHERE e.status = 1 AND e.role = 2 AND uf.facility_id = ' . (int) $fid . '
                                             ORDER BY e.first_name LIMIT 5'
                                        );
                                    } else {
                                        $fetch_query = mysqli_query($connection, 'SELECT * FROM tbl_employee WHERE status = 1 AND role = 2 ORDER BY first_name LIMIT 5');
                                    }
                                    while ($fetch_query && $row = mysqli_fetch_array($fetch_query)) {
                                        ?>
                                    <li class="hms-dash-team-item">
                                        <img src="assets/img/user.jpg" alt="" class="hms-dash-team-avatar rounded-circle" width="40" height="40">
                                        <div class="hms-dash-team-text">
                                            <span class="hms-dash-team-name"><?php echo hms_h($row['first_name'] . ' ' . $row['last_name']); ?></span>
                                            <span class="hms-dash-team-role"><?php echo hms_h((string) $row['bio']); ?></span>
                                        </div>
                                        <span class="hms-dash-team-status" title="Online"></span>
                                    </li>
                                        <?php
                                    }
                                    ?>
                                </ul>
                            </div>
                            <div class="card-footer text-center bg-white border-top-0 pt-0">
                                <a href="doctors.php" class="btn btn-sm btn-link font-weight-bold">View all doctors</a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('hmsDashPatientTrend');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }
    var labels = <?php echo $chartLabelsJson; ?>;
    var values = <?php echo $chartValuesJson; ?>;
    var primary = getComputedStyle(document.documentElement).getPropertyValue('--hms-primary').trim() || '#0c8b8b';
    var ctx = canvas.getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'New patients',
                data: values,
                borderColor: primary,
                backgroundColor: 'rgba(12, 139, 139, 0.12)',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#fff',
                pointBorderColor: primary,
                lineTension: 0.35,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            tooltips: {
                mode: 'index',
                intersect: false
            },
            scales: {
                xAxes: [{
                    gridLines: { display: false, drawBorder: false },
                    ticks: { fontColor: '#64748b', fontSize: 11 }
                }],
                yAxes: [{
                    ticks: {
                        beginAtZero: true,
                        precision: 0,
                        fontColor: '#64748b',
                        fontSize: 11
                    },
                    gridLines: { color: 'rgba(148, 163, 184, 0.2)', zeroLineColor: 'rgba(148, 163, 184, 0.35)' }
                }]
            }
        }
    });
});
</script>

 <?php
 include 'footer.php';
?>
