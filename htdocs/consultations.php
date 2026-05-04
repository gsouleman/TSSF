<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'consult.read');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$ok = hms_workflow_table_ok($connection, 'tbl_consultation');
$canWrite = hms_can($connection, 'consult.write');
$canClinical = hms_can($connection, 'clinical.read');

$consultFlash = isset($_SESSION['consult_flash']) ? (string) $_SESSION['consult_flash'] : '';
unset($_SESSION['consult_flash']);

$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'triaged', 'fee_paid', 'appointment_booked', 'in_progress', 'completed'], true)) {
    $statusFilter = 'all';
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;
$total = 0;
$rows = [];
$totalPages = 1;

if ($ok) {
    $w = ['c.facility_id = ' . (int) $fid];
    if ($statusFilter !== 'all') {
        $w[] = "c.status = '" . mysqli_real_escape_string($connection, $statusFilter) . "'";
    }
    $whereSql = implode(' AND ', $w);

    $cntQ = mysqli_query($connection, 'SELECT COUNT(*) AS cnt FROM tbl_consultation c WHERE ' . $whereSql);
    if ($cntQ) {
        $cr = mysqli_fetch_assoc($cntQ);
        $total = (int) ($cr['cnt'] ?? 0);
    }
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $orderSql = $sort === 'oldest' ? 'c.id ASC' : 'c.id DESC';

    $hasDeptCol = hms_db_column_exists($connection, 'tbl_consultation', 'department');
    $hasDoctorCol = hms_db_column_exists($connection, 'tbl_consultation', 'doctor_name');
    $deptSel = $hasDeptCol ? ', c.department' : ", '' AS department";
    $doctorSel = $hasDoctorCol ? ', c.doctor_name' : ", '' AS doctor_name";

    $dataQ = mysqli_query(
        $connection,
        "SELECT c.id, c.patient_id, c.consultation_type, c.status, c.consult_fee_xaf, c.appointment_id, c.created_at, p.first_name, p.last_name{$deptSel}{$doctorSel} FROM tbl_consultation c JOIN tbl_patient p ON p.id = c.patient_id WHERE {$whereSql} ORDER BY {$orderSql} LIMIT {$offset}, {$perPage}"
    );
    while ($dataQ && $r = mysqli_fetch_assoc($dataQ)) {
        $rows[] = $r;
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
}

$consultPrintDoc = hms_billing_take_print_prompt();
include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-consult-page">
                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Consultations</h1>
                        <nav aria-label="breadcrumb" class="mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="appointments.php">Appointments</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Consultations</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center no-print">
                        <a href="appointments.php" class="btn btn-light border btn-sm mr-2"><i class="fa fa-arrow-left mr-1"></i> Back to Appointments</a>
                        <?php if ($canWrite) { ?>
                        <a href="consultation-new.php" class="btn btn-primary btn-sm font-weight-bold px-3"><i class="fa fa-plus mr-1"></i> New Consultation</a>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($consultFlash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($consultFlash); ?></div>
                <?php } ?>
                <?php if ($consultPrintDoc > 0) { ?>
                <div class="alert alert-success border-0 shadow-sm no-print">
                    Consultation fee document is ready (PDF download).
                    <a class="alert-link font-weight-bold" target="_blank" href="billing-document-pdf.php?id=<?php echo (int) $consultPrintDoc; ?>">Download PDF</a>
                    <span class="small">(</span><a class="alert-link small" target="_blank" href="billing-document-print.php?id=<?php echo (int) $consultPrintDoc; ?>">HTML / print</a><span class="small">)</span>
                </div>
                <iframe title="Receipt PDF" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0)" src="billing-document-pdf.php?id=<?php echo (int) $consultPrintDoc; ?>"></iframe>
                <?php } ?>

                <?php if (!$ok) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Run migration <code>003_clinical_workflow.sql</code> from <a href="platform-overview.php">Help &amp; setup</a>.
                </div>
                <?php } else { ?>

                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">All Consultations</h2>
                            <span class="badge badge-primary" style="font-size:.75rem"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="consultations.php">
                            <input type="hidden" name="p" value="1">
                            <div class="d-flex flex-wrap align-items-center">
                                <label class="small text-muted mr-2 mb-0">Sort By:</label>
                                <select name="sort" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                                    <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                                </select>
                                <label class="small text-muted mr-2 mb-0">Status:</label>
                                <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <option value="all"<?php echo $statusFilter === 'all' ? ' selected' : ''; ?>>All</option>
                                    <option value="triaged"<?php echo $statusFilter === 'triaged' ? ' selected' : ''; ?>>Triaged</option>
                                    <option value="fee_paid"<?php echo $statusFilter === 'fee_paid' ? ' selected' : ''; ?>>Fee Paid</option>
                                    <option value="appointment_booked"<?php echo $statusFilter === 'appointment_booked' ? ' selected' : ''; ?>>Appt Booked</option>
                                    <option value="in_progress"<?php echo $statusFilter === 'in_progress' ? ' selected' : ''; ?>>In Progress</option>
                                    <option value="completed"<?php echo $statusFilter === 'completed' ? ' selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr class="text-uppercase small text-muted" style="background:#f8f9fd">
                                        <th>ID</th>
                                        <th>Patient</th>
                                        <th>Type</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Fee (XAF)</th>
                                        <th>Date</th>
                                        <th class="text-right no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($rows === []) { ?>
                                    <tr><td colspan="8" class="text-center text-muted py-5">No consultations found.</td></tr>
                                <?php } ?>
                                <?php foreach ($rows as $r) {
                                    $cid = (int) $r['id'];
                                    $cidLabel = '#C' . str_pad((string) $cid, 5, '0', STR_PAD_LEFT);
                                    $pname = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
                                    $pinit = strtoupper(substr((string) $r['first_name'], 0, 1) . substr((string) $r['last_name'], 0, 1));
                                    $ctype = ucfirst((string) ($r['consultation_type'] ?? 'general'));
                                    $dept = (string) ($r['department'] ?? '');
                                    if ($dept === '') $dept = '—';
                                    $status = (string) ($r['status'] ?? 'triaged');
                                    $statusLabel = str_replace('_', ' ', ucfirst($status));
                                    $statusPill = 'hms-visit-pill--pending';
                                    if ($status === 'completed') $statusPill = 'hms-visit-pill--completed';
                                    elseif ($status === 'fee_paid' || $status === 'in_progress') $statusPill = 'hms-visit-pill--inprogress';
                                    elseif ($status === 'cancelled') $statusPill = 'hms-visit-pill--cancelled';
                                    $fee = hms_format_xaf((float) ($r['consult_fee_xaf'] ?? 0));
                                    $dateShow = (string) ($r['created_at'] ?? '');
                                    if ($dateShow !== '' && strtotime($dateShow) !== false) {
                                        $dateShow = date('d M Y, h:i A', strtotime($dateShow));
                                    }
                                    $cpid = (int) $r['patient_id'];
                                    ?>
                                    <tr>
                                        <td class="align-middle font-weight-bold text-nowrap"><?php echo hms_h($cidLabel); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--patient hms-visit-avatar--sm mr-2"><?php echo hms_h($pinit); ?></span>
                                                <span class="font-weight-bold text-dark"><?php echo hms_h($pname); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle small"><?php echo hms_h($ctype); ?></td>
                                        <td class="align-middle small text-muted"><?php echo hms_h($dept); ?></td>
                                        <td class="align-middle"><span class="hms-visit-pill <?php echo hms_h($statusPill); ?>"><?php echo hms_h($statusLabel); ?></span></td>
                                        <td class="align-middle text-nowrap"><?php echo hms_h($fee); ?></td>
                                        <td class="align-middle text-muted small text-nowrap"><?php echo hms_h($dateShow); ?></td>
                                        <td class="align-middle text-right no-print">
                                            <div class="dropdown">
                                                <button class="btn btn-link text-muted p-0" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></button>
                                                <div class="dropdown-menu dropdown-menu-right shadow border-0">
                                                    <a class="dropdown-item" href="consultation-new.php?id=<?php echo $cid; ?>"><i class="fa fa-eye mr-2"></i>View / Edit</a>
                                                    <?php if ($canClinical) { ?>
                                                    <a class="dropdown-item" href="patient-chart.php?id=<?php echo $cpid; ?>"><i class="fa fa-folder-open mr-2"></i>Open Chart</a>
                                                    <?php } ?>
                                                    <a class="dropdown-item" href="prescription-new.php?consultation_id=<?php echo $cid; ?>"><i class="fa fa-file-text-o mr-2"></i>Prescription</a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($totalPages > 1) { ?>
                    <div class="card-footer bg-white border-top-0 py-3 no-print">
                        <nav aria-label="Consultations pagination">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php
                                $mk = static function (int $p) use ($sort, $statusFilter): string {
                                    return 'consultations.php?' . http_build_query(['sort' => $sort, 'status' => $statusFilter, 'p' => $p]);
                                };
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                ?>
                                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk($prev)); ?>">Prev</a></li>
                                <?php
                                $window = 5;
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $start + $window - 1);
                                $start = max(1, $end - $window + 1);
                                for ($pi = $start; $pi <= $end; $pi++) { ?>
                                <li class="page-item<?php echo $pi === $page ? ' active' : ''; ?>"><a class="page-link" href="<?php echo hms_h($mk($pi)); ?>"><?php echo $pi; ?></a></li>
                                <?php } ?>
                                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk($next)); ?>">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                    <?php } ?>
                </section>

                <?php } ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
