<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
include 'header.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: patients.php');
    exit;
}

$fid = hms_current_facility_id();
if (hms_multi_site_enabled($connection)) {
    $stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $id, $fid);
} else {
    $stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $id);
}
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: patients.php');
    exit;
}

$hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));

if (isset($_REQUEST['save-patient'])) {
    if (!hms_can($connection, 'patient.write')) {
        $msg = 'Forbidden.';
    } elseif (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $first_name = (string) ($_REQUEST['first_name'] ?? '');
    $last_name = (string) ($_REQUEST['last_name'] ?? '');
    $emailid = (string) ($_REQUEST['emailid'] ?? '');
    $dob = (string) ($_REQUEST['dob'] ?? '');
    $gender = (string) ($_REQUEST['gender'] ?? '');
    $patient_type = (string) ($_REQUEST['patient_type'] ?? '');
    $phone = (string) ($_REQUEST['phone'] ?? '');
    $address = hms_cameroon_address_from_request($_REQUEST);
    $status = (int) ($_REQUEST['status'] ?? 1);

    if (hms_multi_site_enabled($connection)) {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET first_name=?, last_name=?, email=?, dob=?, gender=?, patient_type=?, address=?, phone=?, status=? WHERE id=? AND facility_id=?'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'ssssssssiii',
                $first_name,
                $last_name,
                $emailid,
                $dob,
                $gender,
                $patient_type,
                $address,
                $phone,
                $status,
                $id,
                $fid
            );
            if (mysqli_stmt_execute($upd)) {
                $msg = 'Patient updated successfully';
                hms_audit_log($connection, 'patient.update', 'patient', $id);
                hms_patient_portal_apply_staff_settings($connection, $id, true, $fid);
                $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
                mysqli_stmt_bind_param($stmt2, 'ii', $id, $fid);
                mysqli_stmt_execute($stmt2);
                $fetched = hms_stmt_fetch_assoc($stmt2);
                if ($fetched !== null) {
                    $row = $fetched;
                    $hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));
                }
                mysqli_stmt_close($stmt2);
            } else {
                $msg = 'Error!';
            }
            mysqli_stmt_close($upd);
        } else {
            $msg = 'Error!';
        }
    } else {
        $upd = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET first_name=?, last_name=?, email=?, dob=?, gender=?, patient_type=?, address=?, phone=?, status=? WHERE id=?'
        );
        if ($upd) {
            mysqli_stmt_bind_param(
                $upd,
                'ssssssssii',
                $first_name,
                $last_name,
                $emailid,
                $dob,
                $gender,
                $patient_type,
                $address,
                $phone,
                $status,
                $id
            );
            if (mysqli_stmt_execute($upd)) {
                $msg = 'Patient updated successfully';
                hms_audit_log($connection, 'patient.update', 'patient', $id);
                hms_patient_portal_apply_staff_settings($connection, $id, false, $fid);
                $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($stmt2, 'i', $id);
                mysqli_stmt_execute($stmt2);
                $fetched = hms_stmt_fetch_assoc($stmt2);
                if ($fetched !== null) {
                    $row = $fetched;
                    $hms_cameroon_address_parts = hms_cameroon_address_parse((string) ($row['address'] ?? ''));
                }
                mysqli_stmt_close($stmt2);
            } else {
                $msg = 'Error!';
            }
            mysqli_stmt_close($upd);
        } else {
            $msg = 'Error!';
        }
    }
    }
}

$hmsPatientEpisode = [
    'open_adm' => null,
    'open_fa' => null,
    'recent_visits' => [],
    'patient_search_q' => trim((string) ($row['last_name'] ?? '') . ' ' . trim((string) ($row['first_name'] ?? ''))),
];
$hmsCreditAcctView = null;
$hmsCreditSnapView = null;
if (hms_credit_tables_ok($connection) && hms_credit_can_read($connection)) {
    $hmsCreditAcctView = hms_credit_get_active_account($connection, $fid, $id, hms_multi_site_enabled($connection));
    if ($hmsCreditAcctView) {
        $hmsCreditSnapView = hms_credit_balance_snapshot($connection, (int) $hmsCreditAcctView['id']);
    }
}
if (hms_db_table_exists($connection, 'tbl_admission')) {
    $eq = mysqli_prepare(
        $connection,
        'SELECT a.id, a.admitted_at, b.ward_name, b.bed_label
         FROM tbl_admission a
         LEFT JOIN tbl_bed b ON b.id = a.bed_id AND b.facility_id = a.facility_id
         WHERE a.facility_id = ? AND a.patient_id = ? AND a.discharged_at IS NULL
         ORDER BY a.id DESC LIMIT 1'
    );
    if ($eq) {
        mysqli_stmt_bind_param($eq, 'ii', $fid, $id);
        mysqli_stmt_execute($eq);
        $er = hms_stmt_fetch_assoc($eq);
        mysqli_stmt_close($eq);
        if ($er) {
            $hmsPatientEpisode['open_adm'] = $er;
        }
    }
}
if (function_exists('hms_facility_admission_tables_ok') && hms_facility_admission_tables_ok($connection)) {
    $fq = mysqli_prepare(
        $connection,
        'SELECT id, arrival_at, arrival_note FROM tbl_facility_admission
         WHERE facility_id = ? AND patient_id = ? AND closed_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    if ($fq) {
        mysqli_stmt_bind_param($fq, 'ii', $fid, $id);
        mysqli_stmt_execute($fq);
        $fr = hms_stmt_fetch_assoc($fq);
        mysqli_stmt_close($fq);
        if ($fr) {
            $hmsPatientEpisode['open_fa'] = $fr;
        }
    }
}
if (hms_opd_tables_ready($connection)) {
    $vq = mysqli_query(
        $connection,
        'SELECT id, ticket_number, visit_date, queue_status FROM tbl_opd_visit
         WHERE facility_id = ' . (int) $fid . ' AND patient_id = ' . (int) $id . '
         ORDER BY id DESC LIMIT 5'
    );
    while ($vq && $vrow = mysqli_fetch_assoc($vq)) {
        $hmsPatientEpisode['recent_visits'][] = $vrow;
    }
}

?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Edit patient', [
                    'subtitle' => 'Patient #' . (int) $id,
                    'breadcrumbs' => [['Patients', 'patients.php'], ['Edit', null]],
                    'back' => 'patients.php',
                ]);
                ?>
                <div class="row justify-content-center mb-3">
                    <div class="col-xl-9 col-lg-11">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h2 class="h5 font-weight-bold mb-3">Site activity (this facility)</h2>
                                <?php if ($hmsPatientEpisode['open_adm'] !== null) {
                                    $oa = $hmsPatientEpisode['open_adm'];
                                    $wn = trim((string) ($oa['ward_name'] ?? ''));
                                    $bl = trim((string) ($oa['bed_label'] ?? ''));
                                    $bedDisp = ($wn !== '' || $bl !== '') ? (trim($wn . ' / ' . $bl, ' /')) : '—';
                                    ?>
                                <p class="mb-2"><span class="badge badge-primary">In a bed</span> Admission #<?php echo (int) $oa['id']; ?> · <?php echo hms_h($bedDisp); ?> · since <?php echo hms_h((string) ($oa['admitted_at'] ?? '')); ?></p>
                                <?php if (hms_can($connection, 'adt.read')) { ?>
                                <p class="small mb-0"><a href="adt-board.php">Ward &amp; Bed board</a></p>
                                <?php } ?>
                                <?php } else { ?>
                                <p class="mb-2"><span class="badge badge-secondary">No open ward stay</span> at this site.</p>
                                <?php } ?>
                                <?php if ($hmsPatientEpisode['open_fa'] !== null) {
                                    $of = $hmsPatientEpisode['open_fa']; ?>
                                <p class="mb-2 mt-3"><span class="badge badge-info text-dark">Open arrival episode</span> Facility admission #<?php echo (int) $of['id']; ?> · <?php echo hms_h((string) ($of['arrival_at'] ?? '')); ?><?php
                                    $an = trim((string) ($of['arrival_note'] ?? ''));
                                    echo $an !== '' ? ' · ' . hms_h($an) : '';
                                ?></p>
                                <?php } ?>
                                <?php if ($hmsPatientEpisode['recent_visits'] !== []) { ?>
                                <p class="small text-muted mb-1 mt-3">Recent OPD visits</p>
                                <ul class="small mb-0 pl-3">
                                    <?php foreach ($hmsPatientEpisode['recent_visits'] as $rv) { ?>
                                    <li><?php echo hms_h((string) ($rv['ticket_number'] ?? '')); ?> · <?php echo hms_h((string) ($rv['visit_date'] ?? '')); ?> · <?php echo hms_h(hms_opd_status_label((string) ($rv['queue_status'] ?? ''))); ?></li>
                                    <?php } ?>
                                </ul>
                                <?php if (hms_can($connection, 'opd.read')) { ?>
                                <p class="small mb-0 mt-2"><a href="visits.php?q=<?php echo rawurlencode(trim((string) $hmsPatientEpisode['patient_search_q'])); ?>">Open visit registry</a></p>
                                <?php } ?>
                                <?php } elseif (hms_opd_tables_ready($connection)) { ?>
                                <p class="small text-muted mb-0 mt-2">No OPD visits on record at this site.</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row justify-content-center">
                    <div class="col-xl-9 col-lg-11">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Identity</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="first_name">First name <span class="hms-required">*</span></label>
                                                <input id="first_name" class="form-control" type="text" name="first_name" value="<?php echo hms_h((string) $row['first_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="last_name">Last name <span class="hms-required">*</span></label>
                                                <input id="last_name" class="form-control" type="text" name="last_name" value="<?php echo hms_h((string) $row['last_name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="emailid">Email <span class="hms-required">*</span></label>
                                                <input id="emailid" class="form-control" type="email" name="emailid" value="<?php echo hms_h((string) $row['email']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="dob">Date of birth</label>
                                                <div class="cal-icon">
                                                    <input id="dob" type="text" class="form-control datetimepicker" name="dob" value="<?php echo hms_h((string) $row['dob']); ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group gender-select">
                                                <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Gender</span>
                                                <div class="form-check form-check-inline">
                                                    <input id="gender_m" type="radio" name="gender" class="form-check-input" value="Male" <?php echo ($row['gender'] === 'Male') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="gender_m">Male</label>
                                                </div>
                                                <div class="form-check form-check-inline">
                                                    <input id="gender_f" type="radio" name="gender" class="form-check-input" value="Female" <?php echo ($row['gender'] === 'Female') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="gender_f">Female</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Care &amp; contact</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="patient_type">Patient type</label>
                                                <select id="patient_type" class="select" name="patient_type" required>
                                                    <option value="">Select</option>
                                                    <option value="InPatient" <?php echo ($row['patient_type'] === 'InPatient') ? 'selected' : ''; ?>>Inpatient</option>
                                                    <option value="OutPatient" <?php echo ($row['patient_type'] === 'OutPatient') ? 'selected' : ''; ?>>Outpatient</option>
                                                </select>
                                                <p class="small text-muted mb-0 mt-1">This flag is updated automatically when staff admit or discharge on Ward &amp; Bed (it follows an open bed stay at this site).</p>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="phone">Phone</label>
                                                <input id="phone" class="form-control" type="text" name="phone" value="<?php echo hms_h((string) $row['phone']); ?>">
                                            </div>
                                        </div>
                                        <?php require __DIR__ . '/includes/partials/cameroon_address_fields.php'; ?>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Record status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="patient_active" value="1" <?php echo ((int) $row['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="patient_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="patient_inactive" value="0" <?php echo ((int) $row['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="patient_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                                <?php if (hms_credit_tables_ok($connection) && hms_credit_can_read($connection)) { ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Credit &amp; Receivables</h2>
                                    <?php if (!$hmsCreditAcctView) { ?>
                                    <p class="small text-muted mb-2">Use a credit line when the patient receives care before payment (for example emergency arrival). Charges can be posted <strong>on credit</strong> from Post Charge.</p>
                                    <?php if (hms_credit_can_write($connection)) { ?>
                                    <a class="btn btn-outline-primary btn-sm" href="credit-open.php?patient_id=<?php echo (int) $id; ?>">Open credit account</a>
                                    <?php } else { ?>
                                    <span class="text-muted small">No permission to open accounts.</span>
                                    <?php } ?>
                                    <?php } else {
                                        $snap = $hmsCreditSnapView ?? ['balance' => 0, 'aging_days' => 0, 'charges' => 0, 'payments' => 0];
                                        $stLab = (string) ($hmsCreditAcctView['status'] ?? 'active');
                                        ?>
                                    <p class="mb-2">
                                        <span class="badge badge-<?php echo $stLab === 'active' ? 'primary' : 'secondary'; ?>"><?php echo hms_h($stLab); ?></span>
                                        <?php if (!empty($hmsCreditAcctView['emergency_payment_pending'])) { ?>
                                        <span class="badge badge-warning text-dark ml-1">Emergency — payment pending</span>
                                        <?php } ?>
                                    </p>
                                    <p class="mb-1"><strong>Outstanding balance:</strong> <?php echo hms_h(number_format((float) $snap['balance'], 0, '.', ' ')); ?> <?php echo hms_h(hms_currency_label()); ?></p>
                                    <p class="small text-muted mb-2">Aging (oldest on-credit charge): <?php echo (int) $snap['aging_days']; ?> day(s)</p>
                                    <a class="btn btn-primary btn-sm" href="credit-account.php?id=<?php echo (int) $hmsCreditAcctView['id']; ?>">Manage account</a>
                                    <?php } ?>
                                </div>
                                <?php } elseif (hms_credit_can_read($connection)) { ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Credit &amp; Receivables</h2>
                                    <div class="alert alert-light border mb-0 small">Run <code>hms/database/migrations/019_credit_receivables.sql</code> to enable patient credit lines and receivables tracking.</div>
                                </div>
                                <?php } ?>
                                <?php if (hms_patient_portal_ready($connection) && hms_can($connection, 'patient.write')) {
                                    $pen = (int) ($row['portal_enabled'] ?? 0);
                                    $hasHash = !empty($row['portal_password_hash']);
                                    ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Patient portal</h2>
                                    <p class="small text-muted mb-3">Patients open <code class="text-dark">patient-portal-login.php</code> and sign in with <strong>this record’s email</strong> and the password you set below.</p>
                                    <div class="form-group">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="portal_enabled" id="portal_enabled" value="1" <?php echo $pen === 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="portal_enabled">Allow online portal sign-in</label>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="portal_new_password">Portal password</label>
                                        <input id="portal_new_password" class="form-control" type="password" name="portal_new_password" autocomplete="new-password" placeholder="<?php echo $hasHash ? 'Leave blank to keep the current portal password' : 'Set a password the patient will use with their email'; ?>">
                                    </div>
                                </div>
                                <?php } elseif (!hms_patient_portal_ready($connection)) { ?>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Patient portal</h2>
                                    <div class="alert alert-light border mb-0 small">Run the SQL migration <code>hms/database/migrations/002_patient_portal.sql</code> in phpMyAdmin to add portal fields, then reload this page.</div>
                                </div>
                                <?php } ?>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap align-items-center">
                                <a href="patients.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <?php if (hms_can($connection, 'patient.write')) { ?>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="save-patient">Save changes</button>
                                <?php } else { ?>
                                <span class="text-muted small">Read-only (no patient.write permission).</span>
                                <?php } ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

<?php
include 'footer.php';
hms_ui_flash_toast_script(isset($msg) ? (string) $msg : null);
?>