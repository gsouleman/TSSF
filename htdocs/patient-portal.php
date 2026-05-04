<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/patient_portal_view.php';

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

$appointments = hms_patient_portal_fetch_appointments($connection, $patient);
$resultNotices = function_exists('hms_patient_portal_result_notices')
    ? hms_patient_portal_result_notices($connection, $pid)
    : [];
$fullName = trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name']);

$walletQuery = mysqli_prepare($connection, "SELECT balance, qr_token FROM tbl_patient_wallet WHERE patient_id = ? LIMIT 1");
$wallet = null;
if ($walletQuery) {
    mysqli_stmt_bind_param($walletQuery, 'i', $pid);
    mysqli_stmt_execute($walletQuery);
    $wallet = hms_stmt_fetch_assoc($walletQuery);
    mysqli_stmt_close($walletQuery);
}

hms_patient_portal_render_head(['title' => 'My care — Patient portal', 'show_nav' => true, 'nav_active' => 'overview']);
$ppFlash = isset($_SESSION['patient_portal_flash_ok']) ? (string) $_SESSION['patient_portal_flash_ok'] : '';
unset($_SESSION['patient_portal_flash_ok']);
?>
                <?php if ($ppFlash !== '') { ?>
                <div class="alert alert-success border-0 shadow-sm"><?php echo hms_h($ppFlash); ?></div>
                <?php } ?>
                <div class="hms-page-toolbar card border-0 shadow-sm mb-4">
                    <div class="card-body py-3 d-flex flex-wrap justify-content-between align-items-center">
                        <div>
                            <h1 class="hms-page-heading mb-1">Hello, <?php echo hms_h($patient['first_name']); ?></h1>
                            <p class="text-muted small mb-0">Here are your appointments we have on file. For urgent matters, call your clinic.</p>
                        </div>
                        <a class="btn btn-success btn-sm font-weight-bold mt-2 mt-md-0" href="patient-portal-book.php"><i class="fa fa-calendar-plus-o mr-1"></i> Book a visit</a>
                    </div>
                </div>
                <div class="row">
                    <div class="col-lg-5 mb-4">
                        <div class="card border-0 shadow-sm hms-form-card mb-4">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted font-weight-bold mb-3">Your details</h2>
                                <dl class="row small mb-0">
                                    <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?php echo hms_h($fullName); ?></dd>
                                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?php echo hms_h((string) $patient['email']); ?></dd>
                                    <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?php echo hms_h((string) $patient['phone']); ?></dd>
                                    <dt class="col-sm-4">Type</dt><dd class="col-sm-8"><?php echo hms_h((string) $patient['patient_type']); ?></dd>
                                </dl>
                            </div>
                        </div>
                        <?php if ($wallet) { ?>
                        <div class="card border-0 shadow-sm hms-form-card text-center">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted font-weight-bold mb-2">My Pre-Paid Wallet <span class="badge badge-success ml-2">Active</span></h2>
                                <div class="h3 font-weight-bold text-success mb-2"><?php echo hms_h(hms_format_xaf((float)($wallet['balance']??0))); ?></div>
                                <div class="small text-muted mb-3">Top-up instantly via Hospital Cashier or GBPAY USSD</div>
                                <?php if (!empty($wallet['qr_token'])) { ?>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?php echo urlencode($wallet['qr_token']); ?>" alt="Wallet QR Code" class="border p-2 bg-white rounded shadow-sm">
                                <p class="small text-muted mt-2 mb-0">Show this QR code at the Pharmacy or Lab for touchless payment</p>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="col-lg-7 mb-4">
                        <div class="card border-0 shadow-sm hms-data-card">
                            <div class="card-body border-bottom py-3">
                                <h2 class="h6 text-uppercase text-muted font-weight-bold mb-0">Appointments</h2>
                            </div>
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead class="thead-light"><tr>
                                        <th>Reference</th><th>Department</th><th>Doctor</th><th>Date</th><th>Time</th><th>Status</th>
                                    </tr></thead>
                                    <tbody>
                                    <?php if ($appointments === []) { ?>
                                        <tr><td colspan="6" class="hms-empty-hint">No appointments are linked to your record yet.</td></tr>
                                    <?php } else { ?>
                                        <?php foreach ($appointments as $a) { ?>
                                        <tr>
                                            <td><?php echo hms_h((string) $a['appointment_id']); ?></td>
                                            <td><?php echo hms_h((string) $a['department']); ?></td>
                                            <td><?php echo hms_h((string) $a['doctor']); ?></td>
                                            <td><?php echo hms_h((string) $a['date']); ?></td>
                                            <td><?php echo hms_h((string) $a['time']); ?></td>
                                            <td>
                                                <?php echo ((int) ($a['status'] ?? 0) === 1) ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-secondary">Inactive</span>'; ?>
                                                <?php if ((int)($a['is_telemedicine'] ?? 0) === 1 && !empty($a['meeting_link'])) { ?>
                                                <a href="<?php echo hms_h($a['meeting_link']); ?>" target="_blank" class="btn btn-sm btn-info mt-1 d-block"><i class="fa fa-video-camera"></i> Join Call</a>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <p class="text-muted small mt-2 mb-0">For finalized lab and imaging reports, see the section below when available.</p>
                    </div>
                </div>
                <?php if ($resultNotices !== []) { ?>
                <div class="row">
                    <div class="col-12 mb-4">
                        <div class="card border-0 shadow-sm hms-data-card">
                            <div class="card-body border-bottom py-3">
                                <h2 class="h6 text-uppercase text-muted font-weight-bold mb-0">Test &amp; imaging results</h2>
                            </div>
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead class="thead-light"><tr>
                                        <th>Test</th><th>Conclusion</th><th>Summary</th><th>Date</th>
                                    </tr></thead>
                                    <tbody>
                                        <?php foreach ($resultNotices as $rn) { ?>
                                        <tr>
                                            <td><?php echo hms_h((string) ($rn['test_label'] ?? '')); ?></td>
                                            <td><?php echo hms_h((string) ($rn['conclusion_code'] ?? '')); ?></td>
                                            <td class="small"><?php echo nl2br(hms_h((string) ($rn['summary'] ?? ''))); ?></td>
                                            <td class="text-nowrap small"><?php echo hms_h((string) ($rn['created_at'] ?? '')); ?></td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php } ?>
<?php
hms_patient_portal_render_foot();
