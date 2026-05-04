<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'radiology.write');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_db_table_exists($connection, 'tbl_radiology_result');
$id = (int) ($_GET['id'] ?? 0);
$hasTplCol = hms_db_column_exists($connection, 'tbl_radiology_result', 'result_template_json');
$hasConcCol = hms_db_column_exists($connection, 'tbl_radiology_result', 'conclusion_code');
$patientEmailSelect = hms_db_column_exists($connection, 'tbl_patient', 'email') ? ', p.email AS p_email' : '';

$st = mysqli_prepare(
    $connection,
    'SELECT r.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . '
     FROM tbl_radiology_result r INNER JOIN tbl_patient p ON p.id = r.patient_id
     WHERE r.id = ? AND r.facility_id = ? LIMIT 1'
);
$row = null;
if ($st && $tablesOk && $id > 0) {
    mysqli_stmt_bind_param($st, 'ii', $id, $fid);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
}

$flash = '';

if ($row && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $clinical = trim((string) ($_POST['clinical_indication'] ?? ''));
    $technique = trim((string) ($_POST['technique'] ?? ''));
    $comparison = trim((string) ($_POST['comparison'] ?? ''));
    $findDet = trim((string) ($_POST['findings_detail'] ?? ''));
    $impression = trim((string) ($_POST['impression'] ?? ''));
    $tplPayload = [
        'clinical_indication' => $clinical,
        'technique' => $technique,
        'comparison' => $comparison,
        'findings_detail' => $findDet,
        'impression' => $impression,
    ];
    $tplJson = json_encode($tplPayload, JSON_UNESCAPED_UNICODE) ?: '{}';
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $conc = trim((string) ($_POST['conclusion_code'] ?? ''));
    $modality = trim((string) ($_POST['modality'] ?? ($row['modality'] ?? 'Other')));
    $bodyPart = trim((string) ($_POST['body_part'] ?? ($row['body_part'] ?? '')));
    $apptDate = trim((string) ($_POST['appointment_date'] ?? ($row['appointment_date'] ?? date('Y-m-d'))));
    $findingsCombined = trim($findDet . "\n\nImpression: " . $impression);

    if (isset($_POST['save_rad_workflow'])) {
        if ($hasTplCol && $hasConcCol) {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_radiology_result SET result_template_json = ?, findings = ?, notes = ?, conclusion_code = ?, modality = ?, body_part = ?, appointment_date = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                mysqli_stmt_bind_param($up, 'sssssssii', $tplJson, $findingsCombined, $notes, $conc, $modality, $bodyPart, $apptDate, $id, $fid);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
                $flash = 'Progress saved.';
            }
        } else {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_radiology_result SET findings = ?, notes = ?, modality = ?, body_part = ?, appointment_date = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                mysqli_stmt_bind_param($up, 'sssssii', $findingsCombined, $notes, $modality, $bodyPart, $apptDate, $id, $fid);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
                $flash = 'Progress saved.';
            }
        }
        $st2 = mysqli_prepare(
            $connection,
            'SELECT r.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . ' FROM tbl_radiology_result r INNER JOIN tbl_patient p ON p.id = r.patient_id WHERE r.id = ? AND r.facility_id = ? LIMIT 1'
        );
        if ($st2) {
            mysqli_stmt_bind_param($st2, 'ii', $id, $fid);
            mysqli_stmt_execute($st2);
            $row = hms_stmt_fetch_assoc($st2);
            mysqli_stmt_close($st2);
        }
    } elseif (isset($_POST['finalize_rad_workflow'])) {
        if ($conc === '') {
            $flash = 'Select a conclusion before finalizing.';
        } else {
            $status = 'received';
            if ($hasTplCol && $hasConcCol) {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_radiology_result SET result_template_json = ?, findings = ?, notes = ?, conclusion_code = ?, status = ?, modality = ?, body_part = ?, appointment_date = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'ssssssssii', $tplJson, $findingsCombined, $notes, $conc, $status, $modality, $bodyPart, $apptDate, $id, $fid);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            } else {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_radiology_result SET findings = ?, notes = ?, status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'sssii', $findingsCombined, $notes, $status, $id, $fid);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            }
            $st2 = mysqli_prepare(
                $connection,
                'SELECT r.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . ' FROM tbl_radiology_result r INNER JOIN tbl_patient p ON p.id = r.patient_id WHERE r.id = ? AND r.facility_id = ? LIMIT 1'
            );
            if ($st2) {
                mysqli_stmt_bind_param($st2, 'ii', $id, $fid);
                mysqli_stmt_execute($st2);
                $row = hms_stmt_fetch_assoc($st2);
                mysqli_stmt_close($st2);
            }
            if ($row) {
                $summary = hms_rad_template_summary_text(
                    $hasTplCol ? (string) ($row['result_template_json'] ?? '') : '',
                    (string) ($row['findings'] ?? ''),
                    $hasConcCol ? (string) ($row['conclusion_code'] ?? $conc) : $conc
                );
                $tc = trim((string) ($row['payment_ticket_code'] ?? ''));
                $pid = (int) ($row['patient_id'] ?? 0);
                $refFromConsult = 0;
                if ($tc !== '' && hms_payment_ticket_tables_ok($connection)) {
                    $tq = mysqli_prepare($connection, 'SELECT consultation_id FROM tbl_payment_ticket WHERE ticket_code = ? AND facility_id = ? LIMIT 1');
                    if ($tq) {
                        mysqli_stmt_bind_param($tq, 'si', $tc, $fid);
                        mysqli_stmt_execute($tq);
                        $tr = hms_stmt_fetch_assoc($tq);
                        mysqli_stmt_close($tq);
                        $consId = (int) ($tr['consultation_id'] ?? 0);
                        $refFromConsult = hms_consultation_referrer_employee_id($connection, $fid, $consId);
                    }
                }
                $referrer = (int) ($row['referred_by_id'] ?? 0);
                $doctorEmp = $referrer > 0 ? $referrer : $refFromConsult;
                hms_result_workflow_publish_notices(
                    $connection,
                    $fid,
                    $pid,
                    $doctorEmp,
                    null,
                    $id,
                    $tc,
                    (string) ($row['exam_name'] ?? 'Radiology'),
                    $summary,
                    $hasConcCol ? (string) ($row['conclusion_code'] ?? $conc) : $conc
                );
                hms_audit_log($connection, 'radiology.workflow.finalize', 'radiology_result', $id);
            }
            $_SESSION['rad_registry_flash'] = 'Report finalized. Patient and referring clinician have been notified.';
            header('Location: radiology-results.php');
            exit;
        }
    }
}

include __DIR__ . '/header.php';

if (!$tablesOk || !$row) {
    ?>
        <div class="page-wrapper"><div class="content hms-module">
            <div class="alert alert-warning border-0 shadow-sm">Record not found.</div>
            <a href="radiology-results.php" class="btn btn-outline-secondary">Back</a>
        </div></div>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

$pj = [];
if ($hasTplCol && trim((string) ($row['result_template_json'] ?? '')) !== '') {
    $pj = json_decode((string) $row['result_template_json'], true);
}
if (!is_array($pj)) {
    $pj = [];
}
if ($hasTplCol && trim((string) ($pj['findings_detail'] ?? '')) === '' && trim((string) ($row['findings'] ?? '')) !== '') {
    $pj['findings_detail'] = (string) $row['findings'];
}
$modalities = ['X-Ray', 'Ultrasound', 'CT Scan', 'MRI', 'ECG', 'Echocardiography', 'Mammography', 'Fluoroscopy', 'DEXA', 'Other'];
$concCur = $hasConcCol ? (string) ($row['conclusion_code'] ?? '') : '';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#0891b2 0%,#1a6bd8 100%);color:#fff;">
                    <div class="card-body py-4">
                        <h1 class="h4 font-weight-bold mb-1" style="color:#fff;">Radiology report workspace</h1>
                        <p class="mb-0 small" style="color:rgba(255,255,255,.9);"><?php echo hms_h((string) ($row['exam_name'] ?? '')); ?></p>
                    </div>
                </div>
                <?php if ($flash !== '') { ?><div class="alert alert-success border-0 shadow-sm"><?php echo hms_h($flash); ?></div><?php } ?>

                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <div class="card-body">
                        <div class="mb-3 p-3 rounded" style="background:#f1f5f9;">
                            <small class="text-muted d-block">Patient</small>
                            <strong><?php echo hms_h(trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''))); ?></strong>
                            <?php if (trim((string) ($row['payment_ticket_code'] ?? '')) !== '') { ?>
                            <span class="badge badge-light border ml-1"><?php echo hms_h((string) $row['payment_ticket_code']); ?></span>
                            <?php } ?>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="small font-weight-bold">Modality</label>
                                    <select name="modality" class="form-control"><?php foreach ($modalities as $m) {
                                        $sel = ((string) ($row['modality'] ?? '') === $m) ? ' selected' : '';
                                        ?>
                                        <option value="<?php echo hms_h($m); ?>"<?php echo $sel; ?>><?php echo hms_h($m); ?></option>
                                    <?php } ?></select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="small font-weight-bold">Body part</label>
                                    <input type="text" name="body_part" class="form-control" value="<?php echo hms_h((string) ($row['body_part'] ?? '')); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="small font-weight-bold">Study date</label>
                                    <input type="date" name="appointment_date" class="form-control" value="<?php echo hms_h((string) ($row['appointment_date'] ?? '')); ?>">
                                </div>
                            </div>
                        </div>
                        <?php if ($hasTplCol) { ?>
                        <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3">Structured report</h2>
                        <div class="form-group">
                            <label class="small font-weight-bold">Clinical indication</label>
                            <textarea name="clinical_indication" class="form-control" rows="2"><?php echo hms_h((string) ($pj['clinical_indication'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold">Technique</label>
                            <textarea name="technique" class="form-control" rows="2"><?php echo hms_h((string) ($pj['technique'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold">Comparison</label>
                            <textarea name="comparison" class="form-control" rows="2"><?php echo hms_h((string) ($pj['comparison'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold">Findings (detail)</label>
                            <textarea name="findings_detail" class="form-control" rows="5" placeholder="Objective findings"><?php echo hms_h((string) ($pj['findings_detail'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="small font-weight-bold">Impression</label>
                            <textarea name="impression" class="form-control" rows="3"><?php echo hms_h((string) ($pj['impression'] ?? '')); ?></textarea>
                        </div>
                        <?php } else { ?>
                        <div class="form-group">
                            <label class="small font-weight-bold">Findings / report</label>
                            <textarea name="findings_detail" class="form-control" rows="8"><?php echo hms_h((string) ($row['findings'] ?? '')); ?></textarea>
                        </div>
                        <?php } ?>
                        <div class="form-group">
                            <label class="small font-weight-bold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"><?php echo hms_h((string) ($row['notes'] ?? '')); ?></textarea>
                        </div>
                        <?php if ($hasConcCol) { ?>
                        <div class="form-group">
                            <label class="small font-weight-bold" for="radConc">Conclusion</label>
                            <select class="form-control" name="conclusion_code" id="radConc">
                                <option value="">— Select —</option>
                                <?php foreach (['normal' => 'Normal / benign', 'abnormal' => 'Abnormal / pathology suspected', 'inconclusive' => 'Inconclusive', 'other' => 'Other (see report)'] as $cv => $cl) {
                                    $sel = $concCur === $cv ? ' selected' : '';
                                    ?>
                                <option value="<?php echo hms_h($cv); ?>"<?php echo $sel; ?>><?php echo hms_h($cl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="card-footer bg-light d-flex flex-wrap justify-content-between">
                        <a href="radiology-results.php" class="btn btn-outline-secondary mb-2">Cancel</a>
                        <div>
                            <button type="submit" name="save_rad_workflow" value="1" class="btn btn-outline-primary mb-2 mr-2">Save progress</button>
                            <?php if ($hasConcCol) { ?>
                            <button type="submit" name="finalize_rad_workflow" value="1" class="btn btn-success mb-2" style="background:#0891b2;border-color:#0891b2;">Finalize &amp; notify</button>
                            <?php } ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
