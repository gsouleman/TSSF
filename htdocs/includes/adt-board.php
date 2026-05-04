<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/adt.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'adt.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$canWrite = hms_can($connection, 'adt.write');

if ($canWrite
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && hms_adt_tables_ready($connection)
) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['adt_flash'] = 'Invalid security token.';
        header('Location: adt-board.php');
        exit;
    }

    if (isset($_POST['add_bed'])) {
        $wardSel = trim((string) ($_POST['ward_select'] ?? ''));
        $wardOther = trim((string) ($_POST['ward_name'] ?? ''));
        $ward = $wardSel === 'Other' ? $wardOther : $wardSel;
        $count = (int) ($_POST['bed_count'] ?? 1);
        if ($count < 1 || $count > 25) {
            $count = 1;
        }
        $prefix = trim((string) ($_POST['bed_prefix'] ?? ''));
        
        if ($ward === '' || $prefix === '') {
            $_SESSION['adt_flash'] = 'Ward and bed prefix are required.';
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_bed (facility_id, ward_name, bed_label, status) VALUES (?,?,?,?)'
            );
            $stAvail = 'available';
            $added = 0;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'isss', $fid, $ward, $label, $stAvail);
                for ($i = 1; $i <= $count; $i++) {
                    $label = $prefix . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    if (mysqli_stmt_execute($stmt)) {
                        $added++;
                        hms_audit_log($connection, 'adt.bed.create', 'bed', (int) mysqli_insert_id($connection));
                    }
                }
                mysqli_stmt_close($stmt);
                
                if ($added > 0) {
                    $_SESSION['adt_flash'] = "Successfully added $added bed(s).";
                } else {
                    $_SESSION['adt_flash'] = 'Could not add bed(s) (duplicates?).';
                }
            }
        }
    } elseif (isset($_POST['admit_patient'])) {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $bedId = (int) ($_POST['bed_id'] ?? 0);
        $admitDx = trim((string) ($_POST['admitting_diagnosis'] ?? ''));
        $admitFrom = trim((string) ($_POST['admitted_from'] ?? 'walk_in'));
        if (!in_array($admitFrom, ['walk_in', 'opd', 'er', 'transfer_in'], true)) {
            $admitFrom = 'walk_in';
        }
        $opdVisitId = (int) ($_POST['opd_visit_id'] ?? 0);
        $opdVisitBind = null;
        if ($opdVisitId > 0 && hms_opd_tables_ready($connection)) {
            $ov = mysqli_prepare(
                $connection,
                'SELECT patient_id FROM tbl_opd_visit WHERE id = ? AND facility_id = ? AND queue_status NOT IN (\'cancelled\',\'completed\') LIMIT 1'
            );
            if ($ov) {
                mysqli_stmt_bind_param($ov, 'ii', $opdVisitId, $fid);
                mysqli_stmt_execute($ov);
                $ovr = hms_stmt_fetch_assoc($ov);
                mysqli_stmt_close($ov);
                if ($ovr && (int) $ovr['patient_id'] === $patientId) {
                    $opdVisitBind = $opdVisitId;
                }
            }
        }
        $hasAdmDoc = hms_db_column_exists($connection, 'tbl_admission', 'admitting_diagnosis')
            && hms_db_column_exists($connection, 'tbl_admission', 'admitted_from')
            && hms_db_column_exists($connection, 'tbl_admission', 'opd_visit_id');
        if ($patientId < 1 || $bedId < 1) {
            $_SESSION['adt_flash'] = 'Select a patient and a bed.';
        } elseif (hms_adt_open_admission_count_for_patient($connection, $fid, $patientId) > 0) {
            $_SESSION['adt_flash'] = 'This patient already has an open admission.';
        } else {
            mysqli_begin_transaction($connection);
            $chk = mysqli_prepare(
                $connection,
                "SELECT b.id FROM tbl_bed b WHERE b.id = ? AND b.facility_id = ? AND b.status = 'available'
                 AND NOT EXISTS (SELECT 1 FROM tbl_admission a WHERE a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL)"
            );
            $ok = false;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'ii', $bedId, $fid);
                mysqli_stmt_execute($chk);
                $exists = hms_stmt_fetch_assoc($chk);
                mysqli_stmt_close($chk);
                $ok = $exists !== null;
            }
            if (!$ok) {
                mysqli_rollback($connection);
                $_SESSION['adt_flash'] = 'That bed is no longer available.';
            } else {
                $admStatus = 'admitted';
                $newAdmId = 0;
                if ($hasAdmDoc) {
                    $ins = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_admission (facility_id, patient_id, bed_id, admitted_at, discharged_at, admission_status, admitting_diagnosis, admitted_from, opd_visit_id) VALUES (?,?,?,NOW(),NULL,?,?,?,?)'
                    );
                    if ($ins) {
                        mysqli_stmt_bind_param($ins, 'iiisssi', $fid, $patientId, $bedId, $admStatus, $admitDx, $admitFrom, $opdVisitBind);
                        $ok = mysqli_stmt_execute($ins);
                        if ($ok) {
                            $newAdmId = (int) mysqli_insert_id($connection);
                        }
                        mysqli_stmt_close($ins);
                    } else {
                        $ok = false;
                    }
                } else {
                    $ins = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_admission (facility_id, patient_id, bed_id, admitted_at, discharged_at, admission_status) VALUES (?,?,?,NOW(),NULL,?)'
                    );
                    if ($ins) {
                        mysqli_stmt_bind_param($ins, 'iiis', $fid, $patientId, $bedId, $admStatus);
                        $ok = mysqli_stmt_execute($ins);
                        if ($ok) {
                            $newAdmId = (int) mysqli_insert_id($connection);
                        }
                        mysqli_stmt_close($ins);
                    } else {
                        $ok = false;
                    }
                }
                if ($ok) {
                    $upd = mysqli_prepare(
                        $connection,
                        "UPDATE tbl_bed SET status = 'occupied' WHERE id = ? AND facility_id = ?"
                    );
                    if ($upd) {
                        mysqli_stmt_bind_param($upd, 'ii', $bedId, $fid);
                        $ok = mysqli_stmt_execute($upd);
                        mysqli_stmt_close($upd);
                    } else {
                        $ok = false;
                    }
                }
                if ($ok) {
                    mysqli_commit($connection);
                    $_SESSION['adt_flash'] = 'Patient admitted to bed.';
                    if ($newAdmId > 0) {
                        hms_audit_log($connection, 'adt.admit', 'admission', $newAdmId);
                    }
                } else {
                    mysqli_rollback($connection);
                    $_SESSION['adt_flash'] = 'Admission failed. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['discharge_admission'])) {
        $admId = (int) ($_POST['admission_id'] ?? 0);
        $disSum = trim((string) ($_POST['discharge_summary'] ?? ''));
        if ($admId < 1) {
            $_SESSION['adt_flash'] = 'Invalid admission.';
        } else {
            $st = mysqli_prepare(
                $connection,
                'SELECT bed_id FROM tbl_admission WHERE id = ? AND facility_id = ? AND discharged_at IS NULL LIMIT 1'
            );
            $bedId = 0;
            if ($st) {
                mysqli_stmt_bind_param($st, 'ii', $admId, $fid);
                mysqli_stmt_execute($st);
                $row = hms_stmt_fetch_assoc($st);
                mysqli_stmt_close($st);
                $bedId = (int) ($row['bed_id'] ?? 0);
            }
            mysqli_begin_transaction($connection);
            $hasDisSum = hms_db_column_exists($connection, 'tbl_admission', 'discharge_summary');
            if ($hasDisSum) {
                $u1 = mysqli_prepare(
                    $connection,
                    "UPDATE tbl_admission SET discharged_at = NOW(), admission_status = 'discharged', discharge_summary = ? WHERE id = ? AND facility_id = ? AND discharged_at IS NULL"
                );
                $ok = false;
                if ($u1) {
                    mysqli_stmt_bind_param($u1, 'sii', $disSum, $admId, $fid);
                    $ok = mysqli_stmt_execute($u1) && mysqli_stmt_affected_rows($u1) > 0;
                    mysqli_stmt_close($u1);
                }
            } else {
                $u1 = mysqli_prepare(
                    $connection,
                    "UPDATE tbl_admission SET discharged_at = NOW(), admission_status = 'discharged' WHERE id = ? AND facility_id = ? AND discharged_at IS NULL"
                );
                $ok = false;
                if ($u1) {
                    mysqli_stmt_bind_param($u1, 'ii', $admId, $fid);
                    $ok = mysqli_stmt_execute($u1) && mysqli_stmt_affected_rows($u1) > 0;
                    mysqli_stmt_close($u1);
                }
            }
            if ($ok && $bedId > 0) {
                $u2 = mysqli_prepare(
                    $connection,
                    "UPDATE tbl_bed SET status = 'housekeeping' WHERE id = ? AND facility_id = ?"
                );
                if ($u2) {
                    mysqli_stmt_bind_param($u2, 'ii', $bedId, $fid);
                    mysqli_stmt_execute($u2);
                    mysqli_stmt_close($u2);
                }
            }
            if ($ok) {
                mysqli_commit($connection);
                $_SESSION['adt_flash'] = 'Patient discharged. Bed sent to housekeeping.';
                hms_audit_log($connection, 'adt.discharge', 'admission', $admId);
            } else {
                mysqli_rollback($connection);
                $_SESSION['adt_flash'] = 'Discharge could not be completed.';
            }
        }
    } elseif (isset($_POST['transfer_admission'])) {
        $admId = (int) ($_POST['admission_id'] ?? 0);
        $toBed = (int) ($_POST['to_bed_id'] ?? 0);
        if ($admId < 1 || $toBed < 1) {
            $_SESSION['adt_flash'] = 'Select a destination bed.';
        } else {
            $st = mysqli_prepare(
                $connection,
                'SELECT bed_id FROM tbl_admission WHERE id = ? AND facility_id = ? AND discharged_at IS NULL LIMIT 1'
            );
            $fromBed = 0;
            if ($st) {
                mysqli_stmt_bind_param($st, 'ii', $admId, $fid);
                mysqli_stmt_execute($st);
                $row = hms_stmt_fetch_assoc($st);
                mysqli_stmt_close($st);
                $fromBed = (int) ($row['bed_id'] ?? 0);
            }
            if ($fromBed === $toBed) {
                $_SESSION['adt_flash'] = 'Choose a different bed.';
            } else {
                mysqli_begin_transaction($connection);
                $chk = mysqli_prepare(
                    $connection,
                    "SELECT b.id FROM tbl_bed b WHERE b.id = ? AND b.facility_id = ? AND b.status = 'available'
                     AND NOT EXISTS (SELECT 1 FROM tbl_admission a WHERE a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL)"
                );
                $destOk = false;
                if ($chk) {
                    mysqli_stmt_bind_param($chk, 'ii', $toBed, $fid);
                    mysqli_stmt_execute($chk);
                    $destOk = hms_stmt_fetch_assoc($chk) !== null;
                    mysqli_stmt_close($chk);
                }
                if (!$destOk) {
                    mysqli_rollback($connection);
                    $_SESSION['adt_flash'] = 'Destination bed is not available.';
                } else {
                    $u1 = mysqli_prepare(
                        $connection,
                        'UPDATE tbl_admission SET bed_id = ? WHERE id = ? AND facility_id = ? AND discharged_at IS NULL'
                    );
                    $ok = false;
                    if ($u1) {
                        mysqli_stmt_bind_param($u1, 'iii', $toBed, $admId, $fid);
                        $ok = mysqli_stmt_execute($u1) && mysqli_stmt_affected_rows($u1) > 0;
                        mysqli_stmt_close($u1);
                    }
                    if ($ok && $fromBed > 0) {
                        $o1 = mysqli_prepare(
                            $connection,
                            "UPDATE tbl_bed SET status = 'available' WHERE id = ? AND facility_id = ?"
                        );
                        if ($o1) {
                            mysqli_stmt_bind_param($o1, 'ii', $fromBed, $fid);
                            mysqli_stmt_execute($o1);
                            mysqli_stmt_close($o1);
                        }
                        $o2 = mysqli_prepare(
                            $connection,
                            "UPDATE tbl_bed SET status = 'occupied' WHERE id = ? AND facility_id = ?"
                        );
                        if ($o2) {
                            mysqli_stmt_bind_param($o2, 'ii', $toBed, $fid);
                            mysqli_stmt_execute($o2);
                            mysqli_stmt_close($o2);
                        }
                    }
                    if ($ok) {
                        mysqli_commit($connection);
                        $_SESSION['adt_flash'] = 'Transfer completed.';
                        hms_audit_log($connection, 'adt.transfer', 'admission', $admId);
                    } else {
                        mysqli_rollback($connection);
                        $_SESSION['adt_flash'] = 'Transfer failed.';
                    }
                }
            }
        }
    } elseif (isset($_POST['bed_mark_ready'])) {
        $bedId = (int) ($_POST['bed_id'] ?? 0);
        if ($bedId > 0) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tbl_bed b SET b.status = 'available'
                 WHERE b.id = ? AND b.facility_id = ? AND b.status = 'housekeeping'
                 AND NOT EXISTS (SELECT 1 FROM tbl_admission a WHERE a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $bedId, $fid);
                mysqli_stmt_execute($stmt);
                $n = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['adt_flash'] = $n > 0 ? 'Bed marked available.' : 'Bed could not be updated (must be housekeeping with no patient).';
                if ($n > 0) {
                    hms_audit_log($connection, 'adt.bed.ready', 'bed', $bedId);
                }
            }
        }
    } elseif (isset($_POST['bed_block'])) {
        $bedId = (int) ($_POST['bed_id'] ?? 0);
        if ($bedId > 0) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tbl_bed b SET b.status = 'blocked'
                 WHERE b.id = ? AND b.facility_id = ?
                 AND NOT EXISTS (SELECT 1 FROM tbl_admission a WHERE a.bed_id = b.id AND a.facility_id = b.facility_id AND a.discharged_at IS NULL)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $bedId, $fid);
                mysqli_stmt_execute($stmt);
                $n = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['adt_flash'] = $n > 0 ? 'Bed blocked.' : 'Only empty beds can be blocked.';
                if ($n > 0) {
                    hms_audit_log($connection, 'adt.bed.block', 'bed', $bedId);
                }
            }
        }
    } elseif (isset($_POST['bed_unblock'])) {
        $bedId = (int) ($_POST['bed_id'] ?? 0);
        if ($bedId > 0) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tbl_bed SET status = 'available' WHERE id = ? AND facility_id = ? AND status = 'blocked'"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $bedId, $fid);
                mysqli_stmt_execute($stmt);
                $n = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['adt_flash'] = $n > 0 ? 'Bed unblocked.' : 'Bed was not blocked.';
            }
        }
    }

    header('Location: adt-board.php');
    exit;
}

$flash = '';
if (!empty($_SESSION['adt_flash'])) {
    $flash = (string) $_SESSION['adt_flash'];
    unset($_SESSION['adt_flash']);
}

$hasAdmDocUi = hms_db_column_exists($connection, 'tbl_admission', 'admitting_diagnosis');
$hasDisSumUi = hms_db_column_exists($connection, 'tbl_admission', 'discharge_summary');

include 'header.php';

$ready = hms_adt_tables_ready($connection);
$bedRows = $ready ? hms_adt_fetch_beds_with_occupancy($connection, $fid) : [];
$byWard = hms_adt_group_beds_by_ward($bedRows);
$admissions = $ready ? hms_adt_active_admissions($connection, $fid) : [];
$eligiblePatients = ($ready && $canWrite) ? hms_adt_patients_eligible_for_admission($connection, $fid, $ms) : [];
$availableBedsList = ($ready && $canWrite) ? hms_adt_available_beds($connection, $fid) : [];

$counts = ['total' => 0, 'available' => 0, 'occupied' => 0, 'housekeeping' => 0, 'blocked' => 0];
foreach ($bedRows as $br) {
    $counts['total']++;
    $s = strtolower((string) ($br['bed_status'] ?? ''));
    if ($s === 'available') {
        $counts['available']++;
    } elseif ($s === 'occupied') {
        $counts['occupied']++;
    } elseif ($s === 'housekeeping') {
        $counts['housekeeping']++;
    } elseif ($s === 'blocked') {
        $counts['blocked']++;
    }
}

function hms_adt_badge_class(string $status): string
{
    switch (strtolower($status)) {
        case 'available':
            return 'badge-success';
        case 'occupied':
            return 'badge-primary';
        case 'housekeeping':
            return 'badge-warning text-dark';
        case 'blocked':
            return 'badge-secondary';
        default:
            return 'badge-light text-dark';
    }
}
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                $toolbar = [
                    'subtitle' => 'Ward-level bed status, admissions, transfers, and discharges for this site.',
                ];
                $adtSec = [];
                if (hms_can($connection, 'opd.read') && hms_opd_tables_ready($connection)) {
                    $adtSec[] = ['label' => 'OPD queue', 'url' => 'opd-queue.php', 'icon' => 'fa-list-ol'];
                }
                if ($canWrite && $ready) {
                    $adtSec[] = ['label' => 'Add bed', 'url' => '#adt-add-bed', 'icon' => 'fa-plus', 'class' => 'btn-outline-primary'];
                }
                if ($adtSec !== []) {
                    $toolbar['secondary'] = $adtSec;
                }
                hms_ui_page_header('Admission-Discharge-Transfer and Bed Board (ADT - Bed Board)', $toolbar);
                ?>

                <?php if ($flash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
                <?php } ?>

                <?php if (!$ready) { ?>
                <div class="alert alert-warning">Run the platform migration to create <code>tbl_bed</code> and <code>tbl_admission</code>, then refresh this page.</div>
                <?php } else { ?>

                <div class="row mb-4">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                            <div class="text-muted small text-uppercase font-weight-bold">Beds</div>
                            <div class="h4 mb-0 font-weight-bold"><?php echo (int) $counts['total']; ?></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card border-0 shadow-sm h-100 border-left border-success" style="border-left-width:4px!important"><div class="card-body py-3">
                            <div class="text-muted small text-uppercase font-weight-bold">Available</div>
                            <div class="h4 mb-0 font-weight-bold text-success"><?php echo (int) $counts['available']; ?></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card border-0 shadow-sm h-100 border-left border-primary" style="border-left-width:4px!important"><div class="card-body py-3">
                            <div class="text-muted small text-uppercase font-weight-bold">Occupied</div>
                            <div class="h4 mb-0 font-weight-bold text-primary"><?php echo (int) $counts['occupied']; ?></div>
                        </div></div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card border-0 shadow-sm h-100 border-left border-warning" style="border-left-width:4px!important"><div class="card-body py-3">
                            <div class="text-muted small text-uppercase font-weight-bold">Housekeeping</div>
                            <div class="h4 mb-0 font-weight-bold text-warning"><?php echo (int) $counts['housekeeping']; ?></div>
                        </div></div>
                    </div>
                </div>

                <?php if ($canWrite) { ?>
                <div class="card border-0 shadow-sm mb-4" id="adt-add-bed">
                    <div class="card-header bg-white border-bottom-0 pb-0 pt-3">
                        <h2 class="h6 text-uppercase text-muted font-weight-bold mb-0">Add bed</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" class="form-row align-items-end">
                            <?php echo hms_csrf_field(); ?>
                            <div class="form-group col-md-3">
                                <label for="ward_select" class="small font-weight-bold text-secondary">Ward</label>
                                <select id="ward_select" class="form-control" name="ward_select" onchange="if(this.value=='Other') document.getElementById('ward_name_wrap').style.display='block'; else { document.getElementById('ward_name_wrap').style.display='none'; document.getElementById('ward_name').value=''; }" required>
                                    <option value="">Select Ward</option>
                                    <option value="ICU Ward">ICU Ward</option>
                                    <option value="Pediatrics Wards">Pediatrics Wards</option>
                                    <option value="Maternity/Labour Wards">Maternity/Labour Wards</option>
                                    <option value="Emergency Wards">Emergency Wards</option>
                                    <option value="Geriatric Wards">Geriatric Wards</option>
                                    <option value="Oncology Wards">Oncology Wards</option>
                                    <option value="Orthopedics Wards">Orthopedics Wards</option>
                                    <option value="Isolation Wards">Isolation Wards</option>
                                    <option value="Other">Other...</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3" id="ward_name_wrap" style="display:none;">
                                <label for="ward_name" class="small font-weight-bold text-secondary">Custom Ward</label>
                                <input id="ward_name" class="form-control" type="text" name="ward_name" placeholder="e.g. Medical Ward" maxlength="120">
                            </div>
                            <div class="form-group col-md-2">
                                <label for="bed_count" class="small font-weight-bold text-secondary">No of Beds</label>
                                <select id="bed_count" class="form-control" name="bed_count">
                                    <?php for($i=1; $i<=25; $i++) echo "<option value=\"$i\">$i</option>"; ?>
                                </select>
                            </div>
                            <div class="form-group col-md-2">
                                <label for="bed_prefix" class="small font-weight-bold text-secondary">Bed Prefix</label>
                                <input id="bed_prefix" class="form-control" type="text" name="bed_prefix" placeholder="e.g. A-" maxlength="20" required>
                            </div>
                            <div class="form-group col-md-2">
                                <button type="submit" name="add_bed" class="btn btn-primary btn-block">Add bed(s)</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php } ?>

                <?php foreach ($byWard as $wardName => $bedsInWard) { ?>
                <div class="mb-4">
                    <h3 class="h5 font-weight-bold mb-3"><?php echo hms_h($wardName); ?></h3>
                    <div class="row">
                        <?php foreach ($bedsInWard as $b) {
                            $bid = (int) $b['bed_id'];
                            $st = (string) $b['bed_status'];
                            $admId = (int) ($b['admission_id'] ?? 0);
                            $pt = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? ''));
                            ?>
                        <div class="col-sm-6 col-lg-4 col-xl-3 mb-3">
                            <div class="card hms-bed-tile border-0 shadow-sm h-100">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="h5 mb-0 font-weight-bold"><?php echo hms_h((string) $b['bed_label']); ?></span>
                                        <span class="badge <?php echo hms_adt_badge_class($st); ?>"><?php echo hms_h($st); ?></span>
                                    </div>
                                    <?php if ($admId > 0 && $pt !== '') { ?>
                                    <div class="small text-muted mb-1">Patient</div>
                                    <div class="font-weight-bold mb-3"><?php echo hms_h($pt); ?></div>
                                    <div class="small text-muted mb-3">Since <?php echo hms_h((string) ($b['admitted_at'] ?? '')); ?></div>
                                    <?php } else { ?>
                                    <div class="text-muted small mb-3 flex-grow-1">No patient in this bed.</div>
                                    <?php } ?>
                                    <div class="mt-auto">
                                        <?php if ($canWrite && strtolower($st) === 'available' && $admId === 0) { ?>
                                        <button type="button" class="btn btn-sm btn-success btn-block" data-toggle="modal" data-target="#adtAdmitModal" data-bed-id="<?php echo $bid; ?>" data-bed-label="<?php echo hms_h((string) $b['bed_label']); ?>">Assign patient</button>
                                        <?php } elseif ($canWrite && strtolower($st) === 'housekeeping' && $admId === 0) { ?>
                                        <form method="post" class="mb-2" onsubmit="return confirm('Mark this bed ready for patients?');">
                                            <?php echo hms_csrf_field(); ?>
                                            <input type="hidden" name="bed_id" value="<?php echo $bid; ?>">
                                            <button type="submit" name="bed_mark_ready" class="btn btn-sm btn-outline-secondary btn-block">Mark ready</button>
                                        </form>
                                        <?php } elseif ($canWrite && strtolower($st) === 'blocked') { ?>
                                        <form method="post" onsubmit="return confirm('Unblock this bed?');">
                                            <?php echo hms_csrf_field(); ?>
                                            <input type="hidden" name="bed_id" value="<?php echo $bid; ?>">
                                            <button type="submit" name="bed_unblock" class="btn btn-sm btn-outline-primary btn-block">Unblock</button>
                                        </form>
                                        <?php } elseif ($canWrite && $admId === 0 && strtolower($st) === 'available') { ?>
                                        <form method="post" class="mb-0" onsubmit="return confirm('Block this bed for maintenance?');">
                                            <?php echo hms_csrf_field(); ?>
                                            <input type="hidden" name="bed_id" value="<?php echo $bid; ?>">
                                            <button type="submit" name="bed_block" class="btn btn-sm btn-outline-danger btn-block">Block bed</button>
                                        </form>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>

                <div class="card border-0 shadow-sm hms-data-card">
                    <div class="card-body border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center">
                        <h2 class="h6 text-uppercase text-muted font-weight-bold mb-0">Inpatient census</h2>
                        <span class="small text-muted"><?php echo count($admissions); ?> active</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Location</th>
                                    <th>Admitted</th>
                                    <?php if ($hasAdmDocUi) { ?><th>Admitting diagnosis</th><?php } ?>
                                    <th>Status</th>
                                    <?php if ($canWrite) { ?><th class="text-right">Actions</th><?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $admColspan = 4 + ($hasAdmDocUi ? 1 : 0) + ($canWrite ? 1 : 0);
                            if ($admissions === []) { ?>
                                <tr><td colspan="<?php echo $admColspan; ?>" class="hms-empty-hint">No active admissions.</td></tr>
                            <?php } else { ?>
                                <?php foreach ($admissions as $a) {
                                    $loc = trim((string) ($a['ward_name'] ?? '') . ' / ' . (string) ($a['bed_label'] ?? ''), ' /');
                                    ?>
                                <tr>
                                    <td class="font-weight-bold"><?php echo hms_h(trim((string) $a['first_name'] . ' ' . (string) $a['last_name'])); ?></td>
                                    <td><?php echo hms_h($loc !== '' ? $loc : '—'); ?></td>
                                    <td><?php echo hms_h((string) ($a['admitted_at'] ?? '')); ?></td>
                                    <?php if ($hasAdmDocUi) { ?>
                                    <td class="small"><?php
                                        $dx = trim((string) ($a['admitting_diagnosis'] ?? ''));
                                        echo $dx !== '' ? hms_h(substr($dx, 0, 80)) . (strlen($dx) > 80 ? '…' : '') : '—';
                                    ?></td>
                                    <?php } ?>
                                    <td><span class="badge badge-light text-dark"><?php echo hms_h((string) ($a['admission_status'] ?? '')); ?></span></td>
                                    <?php if ($canWrite) { ?>
                                    <td class="text-right text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#adtTransferModal" data-admission-id="<?php echo (int) $a['admission_id']; ?>" data-patient-name="<?php echo hms_h(trim((string) $a['first_name'] . ' ' . (string) $a['last_name'])); ?>">Transfer</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-toggle="modal" data-target="#adtDischargeModal" data-admission-id="<?php echo (int) $a['admission_id']; ?>" data-patient-name="<?php echo hms_h(trim((string) $a['first_name'] . ' ' . (string) $a['last_name'])); ?>">Discharge</button>
                                    </td>
                                    <?php } ?>
                                </tr>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($canWrite) { ?>
                <div class="modal fade" id="adtAdmitModal" tabindex="-1" role="dialog" aria-labelledby="adtAdmitModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="bed_id" id="adt_admit_bed_id" value="">
                                <input type="hidden" name="opd_visit_id" id="adt_opd_visit_id" value="">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="adtAdmitModalLabel">Admit to bed <span id="adt_admit_bed_label"></span></h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted">Creates an open admission and marks the bed occupied. The patient must not already be admitted. Use the <strong>OPD queue</strong> “Admit” link to pre-fill OPD linkage from the URL.</p>
                                    <div class="form-group">
                                        <label for="adt_patient_select">Patient</label>
                                        <select class="form-control" name="patient_id" id="adt_patient_select" required>
                                            <option value="">Select patient</option>
                                            <?php foreach ($eligiblePatients as $p) { ?>
                                            <option value="<?php echo (int) $p['id']; ?>"><?php echo hms_h($p['last_name'] . ', ' . $p['first_name']); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <?php if ($hasAdmDocUi) { ?>
                                    <div class="form-group">
                                        <label for="adt_admitting_diagnosis">Admitting diagnosis</label>
                                        <textarea class="form-control" name="admitting_diagnosis" id="adt_admitting_diagnosis" rows="2" placeholder="Reason for admission / working diagnosis"></textarea>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="adt_admitted_from">Admitted from</label>
                                        <select class="form-control" name="admitted_from" id="adt_admitted_from">
                                            <option value="walk_in">Walk-in / direct</option>
                                            <option value="opd">OPD</option>
                                            <option value="er">Emergency</option>
                                            <option value="transfer_in">Transfer in</option>
                                        </select>
                                    </div>
                                    <?php } ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" name="admit_patient" class="btn btn-success">Admit</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="adtDischargeModal" tabindex="-1" role="dialog" aria-labelledby="adtDischargeModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="admission_id" id="adt_discharge_admission_id" value="">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="adtDischargeModalLabel">Discharge <span id="adt_discharge_patient_name"></span></h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                </div>
                                <div class="modal-body">
                                    <p class="small text-muted">Bed will go to <strong>housekeeping</strong>. You can record a short discharge summary for the file.</p>
                                    <?php if ($hasDisSumUi) { ?>
                                    <div class="form-group mb-0">
                                        <label for="adt_discharge_summary">Discharge summary (optional)</label>
                                        <textarea class="form-control" name="discharge_summary" id="adt_discharge_summary" rows="4" placeholder="Course in hospital, diagnosis, medications, follow-up…"></textarea>
                                    </div>
                                    <?php } ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" name="discharge_admission" value="1" class="btn btn-danger">Confirm discharge</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="adtTransferModal" tabindex="-1" role="dialog" aria-labelledby="adtTransferModalLabel" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="admission_id" id="adt_transfer_admission_id" value="">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="adtTransferModalLabel">Transfer <span id="adt_transfer_patient_name"></span></h5>
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label for="adt_to_bed">Destination bed</label>
                                        <select class="form-control" name="to_bed_id" id="adt_to_bed" required>
                                            <option value="">Select bed</option>
                                            <?php foreach ($availableBedsList as $ab) { ?>
                                            <option value="<?php echo (int) $ab['bed_id']; ?>"><?php echo hms_h($ab['ward_name'] . ' — ' . $ab['bed_label']); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" name="transfer_admission" class="btn btn-primary">Transfer</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php } ?>

                <?php } ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
<?php if ($ready && $canWrite) { ?>
<script>
(function () {
  $('#adtAdmitModal').on('show.bs.modal', function (e) {
    var btn = $(e.relatedTarget);
    var id = btn.data('bed-id');
    var label = btn.data('bed-label');
    $('#adt_admit_bed_id').val(id || '');
    $('#adt_admit_bed_label').text(label || '');
    var params = new URLSearchParams(window.location.search);
    $('#adt_opd_visit_id').val(params.get('opd_visit_id') || '');
    var pid = params.get('patient_id') || '';
    if (pid) {
      $('#adt_patient_select').val(pid);
    }
    var from = params.get('admitted_from') || '';
    if (from && $('#adt_admitted_from').length) {
      $('#adt_admitted_from').val(from);
    }
  });
  $('#adtTransferModal').on('show.bs.modal', function (e) {
    var btn = $(e.relatedTarget);
    $('#adt_transfer_admission_id').val(btn.data('admission-id') || '');
    $('#adt_transfer_patient_name').text(btn.data('patient-name') || '');
  });
  $('#adtDischargeModal').on('show.bs.modal', function (e) {
    var btn = $(e.relatedTarget);
    $('#adt_discharge_admission_id').val(btn.data('admission-id') || '');
    $('#adt_discharge_patient_name').text(btn.data('patient-name') || '');
    var $ds = $('#adt_discharge_summary');
    if ($ds.length) {
      $ds.val('');
    }
  });
})();
</script>
<?php } ?>
