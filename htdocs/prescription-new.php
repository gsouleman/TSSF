<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'prescription.write');
hms_require_permission($connection, 'patient.read');
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ms = hms_multi_site_enabled($connection);
$consultIdGet = (int) ($_GET['consultation_id'] ?? 0);
$ok = hms_workflow_table_ok($connection, 'tbl_prescription');

if ($ok && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_rx'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['rx_flash'] = 'Invalid security token.';
        header('Location: prescription-new.php');
        exit;
    }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? 'Prescription')) ?: 'Prescription';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $cPost = (int) ($_POST['consultation_id_hidden'] ?? 0);
    $cBind = $cPost > 0 ? $cPost : ($consultIdGet > 0 ? $consultIdGet : null);

    $chk = $ms
        ? mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1')
        : mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
    if ($ms) {
        mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
    } else {
        mysqli_stmt_bind_param($chk, 'i', $pid);
    }
    mysqli_stmt_execute($chk);
    $pok = (bool) hms_stmt_fetch_assoc($chk);
    mysqli_stmt_close($chk);
    if (!$pok || $pid < 1) {
        $_SESSION['rx_flash'] = 'Invalid patient.';
        header('Location: prescription-new.php');
        exit;
    }

    $stt = 'active';
    if ($cBind !== null) {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_prescription (facility_id, patient_id, consultation_id, prescriber_employee_id, title, status, notes) VALUES (?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($st, 'iiiisss', $fid, $pid, $cBind, $uid, $title, $stt, $notes);
    } else {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_prescription (facility_id, patient_id, consultation_id, prescriber_employee_id, title, status, notes) VALUES (?,?,NULL,?,?,?,?)'
        );
        mysqli_stmt_bind_param($st, 'iiisss', $fid, $pid, $uid, $title, $stt, $notes);
    }
    if ($st && mysqli_stmt_execute($st)) {
        $rid = (int) mysqli_insert_id($connection);
        mysqli_stmt_close($st);
        hms_audit_log($connection, 'prescription.create', 'prescription', $rid);
        header('Location: prescription.php?id=' . $rid);
        exit;
    }
    $_SESSION['rx_flash'] = 'Could not create prescription.';
    header('Location: prescription-new.php');
    exit;
}

include 'header.php';
$suf = $ms ? ' WHERE facility_id = ' . (int) $fid : '';
$prefPid = (int) ($_GET['patient_id'] ?? 0);
$prefPidOk = false;
if ($prefPid > 0) {
    if ($ms) {
        $pp = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($pp) {
            mysqli_stmt_bind_param($pp, 'ii', $prefPid, $fid);
        }
    } else {
        $pp = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
        if ($pp) {
            mysqli_stmt_bind_param($pp, 'i', $prefPid);
        }
    }
    if (!empty($pp)) {
        mysqli_stmt_execute($pp);
        $prefPidOk = (bool) hms_stmt_fetch_assoc($pp);
        mysqli_stmt_close($pp);
    }
}
$rxNewSecondary = [];
if ($prefPidOk && hms_can($connection, 'clinical.read')) {
    $rxNewSecondary[] = ['label' => 'Patient chart', 'url' => 'patient-chart.php?id=' . $prefPid, 'icon' => 'fa-user'];
}
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('New prescription', [
                'subtitle' => 'Then add lab tests and medications on the next screen.',
                'breadcrumbs' => [['Prescriptions', 'prescriptions.php'], ['New', '']],
                'back' => 'prescriptions.php',
                'secondary' => $rxNewSecondary,
            ]);
            ?>
            <?php if (isset($_SESSION['rx_flash'])) {
                echo '<div class="alert alert-danger">' . hms_h((string) $_SESSION['rx_flash']) . '</div>';
                unset($_SESSION['rx_flash']);
            } ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>003_clinical_workflow.sql</code>.</div>
            <?php } else { ?>
            <form method="post" class="card border-0 shadow-sm hms-form-card">
                <?php echo hms_csrf_field(); ?>
                <input type="hidden" name="consultation_id_hidden" value="<?php echo (int) $consultIdGet; ?>">
                <div class="card-body">
                    <div class="form-group">
                        <label>Patient <span class="text-danger">*</span></label>
                        <select name="patient_id" class="form-control" required>
                            <option value="">—</option>
                            <?php
                            $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name LIMIT 500');
                            while ($pq && $pr = mysqli_fetch_assoc($pq)) {
                                $sel = ($prefPidOk && (int) $pr['id'] === $prefPid) ? ' selected' : '';
                                echo '<option value="' . (int) $pr['id'] . '"' . $sel . '>' . hms_h($pr['first_name'] . ' ' . $pr['last_name']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" value="Prescription">
                    </div>
                    <div class="form-group mb-0">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    <?php if ($consultIdGet > 0) { ?>
                    <p class="small text-muted mb-0 mt-2">Linked to consultation #<?php echo (int) $consultIdGet; ?>.</p>
                    <?php } ?>
                </div>
                <div class="card-footer bg-light">
                    <button type="submit" name="create_rx" value="1" class="btn btn-primary">Create &amp; add lines</button>
                    <a href="prescriptions.php" class="btn btn-link">Cancel</a>
                </div>
            </form>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
