<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!hms_vitals_can_record($connection)) {
    http_response_code(403);
    exit('Forbidden');
}
hms_require_permission($connection, 'patient.read');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ms = hms_multi_site_enabled($connection);
$station = strtolower(trim((string) ($_GET['station'] ?? $_POST['station'] ?? 'front_desk')));
if (!in_array($station, ['front_desk', 'nursing', 'chart'], true)) {
    $station = 'front_desk';
}

$hasV = hms_db_table_exists($connection, 'tbl_vital_sign');
$hasRec = hms_vitals_has_recorder_columns($connection);
$hasAnt = hms_vitals_has_anthropometrics($connection);
$msg = null;

if ($hasV && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_vitals']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $sys = (int) ($_POST['bp_sys'] ?? 0);
    $dia = (int) ($_POST['bp_dia'] ?? 0);
    $hr = (int) ($_POST['heart_rate'] ?? 0);
    $tc = (float) ($_POST['temp_c'] ?? 0);
    $spo = (int) ($_POST['spo2'] ?? 0);
    $rr = (int) ($_POST['rr'] ?? 0);

    $chk = null;
    if ($ms) {
        $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
        }
    } else {
        $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
        if ($chk) {
            mysqli_stmt_bind_param($chk, 'i', $pid);
        }
    }
    $okPat = false;
    if ($chk) {
        mysqli_stmt_execute($chk);
        $okPat = (bool) hms_stmt_fetch_assoc($chk);
        mysqli_stmt_close($chk);
    }

    if ($pid < 1 || !$okPat) {
        $msg = 'Select a valid patient for this site.';
    } elseif (
        hms_vitals_insert_row($connection, [
            'patient_id' => $pid,
            'facility_id' => $fid,
            'bp_sys' => $sys,
            'bp_dia' => $dia,
            'heart_rate' => $hr,
            'temp_c' => $tc,
            'spo2' => $spo,
            'rr' => $rr,
            'weight_kg' => hms_vitals_optional_measurement_raw($_POST['weight_kg'] ?? null),
            'height_cm' => hms_vitals_optional_measurement_raw($_POST['height_cm'] ?? null),
            'waist_cm' => hms_vitals_optional_measurement_raw($_POST['waist_cm'] ?? null),
            'recorded_by' => $uid,
            'source_station' => $station,
        ])
    ) {
        hms_audit_log($connection, 'vital.create', 'patient', $pid);
        $_SESSION['vitals_flash'] = 'Vitals saved.';
        header('Location: vitals-enter.php?station=' . rawurlencode($station) . '&patient_id=' . $pid);
        exit;
    } else {
        $msg = 'Could not save vitals.';
    }
}

$flash = isset($_SESSION['vitals_flash']) ? (string) $_SESSION['vitals_flash'] : '';
unset($_SESSION['vitals_flash']);

$suf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
$pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name, first_name LIMIT 800');
$patients = [];
while ($pq && $pr = mysqli_fetch_assoc($pq)) {
    $patients[] = $pr;
}

$pickPid = (int) ($_GET['patient_id'] ?? 0);
$stationTitle = hms_vitals_station_label($station);

include 'header.php';
?>
<div class="page-wrapper"><div class="content hms-module">
    <?php
    hms_ui_page_header('Record vitals — ' . $stationTitle, [
        'subtitle' => 'Saved vitals appear on the patient chart and prefill the doctor consultation.',
        'breadcrumbs' => [['Patients', 'patients.php'], ['Vitals', '']],
        'back' => 'dashboard.php',
    ]);
    ?>
    <?php if (!$hasV) { ?>
    <div class="alert alert-warning">Run platform migration so <code>tbl_vital_sign</code> exists.</div>
    <?php } else { ?>
    <?php if ($msg) { ?><div class="alert alert-danger"><?php echo hms_h($msg); ?></div><?php } ?>
    <?php if ($flash !== '') { ?><div class="alert alert-success"><?php echo hms_h($flash); ?></div><?php } ?>
    <?php if (!$hasRec) { ?>
    <div class="alert alert-light border small">Run <code>hms/database/migrations/020_vitals_recorder_and_station.sql</code> to record staff name and station on each entry.</div>
    <?php } ?>
    <?php if (!$hasAnt) { ?>
    <div class="alert alert-light border small">Run <code>hms/database/migrations/021_vitals_weight_height_waist.sql</code> to store optional weight, height, and waist on vitals.</div>
    <?php } ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="station" value="<?php echo hms_h($station); ?>">
                        <div class="form-group">
                            <label for="vital_patient">Patient <span class="text-danger">*</span></label>
                            <select name="patient_id" id="vital_patient" class="form-control select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($patients as $p) {
                                    $sel = ((int) $p['id'] === $pickPid) ? ' selected' : '';
                                    echo '<option value="' . (int) $p['id'] . '"' . $sel . '>' . hms_h($p['first_name'] . ' ' . $p['last_name']) . '</option>';
                                } ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>BP systolic</label>
                                <input class="form-control" name="bp_sys" type="number" min="0" placeholder="mmHg">
                            </div>
                            <div class="form-group col-md-4">
                                <label>BP diastolic</label>
                                <input class="form-control" name="bp_dia" type="number" min="0" placeholder="mmHg">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Heart rate</label>
                                <input class="form-control" name="heart_rate" type="number" min="0" placeholder="bpm">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Temp (°C)</label>
                                <input class="form-control" name="temp_c" type="number" step="0.1" min="0" placeholder="°C">
                            </div>
                            <div class="form-group col-md-4">
                                <label>SpO₂ (%)</label>
                                <input class="form-control" name="spo2" type="number" min="0" max="100" placeholder="%">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Resp. rate</label>
                                <input class="form-control" name="rr" type="number" min="0" placeholder="/min">
                            </div>
                        </div>
                        <p class="text-muted small mb-2">Optional anthropometrics (leave blank if not measured):</p>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Weight (kg)</label>
                                <input class="form-control" name="weight_kg" type="number" step="0.1" min="0" placeholder="kg">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Height (cm)</label>
                                <input class="form-control" name="height_cm" type="number" step="0.1" min="0" placeholder="cm">
                            </div>
                            <div class="form-group col-md-4">
                                <label>Waist (cm)</label>
                                <input class="form-control" name="waist_cm" type="number" step="0.1" min="0" placeholder="cm">
                            </div>
                        </div>
                        <button type="submit" name="save_vitals" value="1" class="btn btn-primary">Save vitals</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
</div>
<?php include 'footer.php'; ?>
