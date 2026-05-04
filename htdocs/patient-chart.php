<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/insurance_catalog.php';
require_once __DIR__ . '/includes/clinical_chart.php';
require_once __DIR__ . '/includes/patient_insurance.php';
require_once __DIR__ . '/includes/patient_external_document.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_patient_chart_access($connection);
$chartLimited = hms_patient_chart_limited_station_view();
$canRecordVitalsHere = hms_vitals_can_record($connection) || $chartLimited;

$pid = (int) ($_GET['id'] ?? 0);
if ($pid < 1) {
    header('Location: patients.php');
    exit;
}
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
if ($ms) {
    $stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $pid, $fid);
} else {
    $stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $pid);
}
mysqli_stmt_execute($stmt);
$patient = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);
if (!$patient) {
    header('Location: patients.php');
    exit;
}

$hasAllergyTbl = hms_db_table_exists($connection, 'tbl_patient_allergy');
$hasMedTbl = hms_db_table_exists($connection, 'tbl_patient_medication');
$hasVitalTbl = hms_db_table_exists($connection, 'tbl_vital_sign');
$chartDataOk = $hasAllergyTbl || $hasMedTbl || $hasVitalTbl
    || hms_workflow_table_ok($connection, 'tbl_consultation')
    || hms_db_table_exists($connection, 'tbl_lab_result')
    || hms_db_table_exists($connection, 'tbl_radiology_result')
    || hms_workflow_table_ok($connection, 'tbl_prescription')
    || hms_patient_external_document_table_ok($connection)
    || hms_db_table_exists($connection, 'tbl_patient_insurance');
$facSql = $ms ? ' AND facility_id = ' . (int) $fid : '';

$flash = isset($_SESSION['chart_flash']) ? (string) $_SESSION['chart_flash'] : '';
unset($_SESSION['chart_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['chart_flash'] = 'Invalid security token.';
    } elseif ($chartLimited && (isset($_POST['add_allergy']) || isset($_POST['add_medication']))) {
        $_SESSION['chart_flash'] = 'That section is not available for your role.';
    } elseif (isset($_POST['update_primary_insurance']) && hms_db_table_exists($connection, 'tbl_patient_insurance') && hms_patient_chart_can_edit_insurance($connection)) {
        $policyId = (int) ($_POST['patient_insurance_id'] ?? 0);
        $carrierId = (int) ($_POST['edit_carrier_id'] ?? 0);
        $pol = trim((string) ($_POST['edit_policy_number'] ?? ''));
        $colOk = hms_db_column_exists($connection, 'tbl_patient_insurance', 'insurer_covered_percent');
        $pct = $colOk ? max(0, min(100, (int) ($_POST['insurer_covered_percent'] ?? 0))) : 0;
        if ($policyId > 0 && $carrierId > 0) {
            $chk = mysqli_query(
                $connection,
                'SELECT id FROM tbl_insurance_carrier WHERE id = ' . (int) $carrierId . ' AND facility_id = ' . (int) $fid . ' LIMIT 1'
            );
            $carrierOk = $chk && mysqli_fetch_assoc($chk);
            if ($carrierOk) {
                if ($colOk) {
                    $st = mysqli_prepare(
                        $connection,
                        'UPDATE tbl_patient_insurance SET carrier_id = ?, policy_number = ?, insurer_covered_percent = ? WHERE id = ? AND patient_id = ? AND facility_id = ? LIMIT 1'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, 'isiiii', $carrierId, $pol, $pct, $policyId, $pid, $fid);
                        if (mysqli_stmt_execute($st)) {
                            hms_audit_log($connection, 'patient_insurance.update', 'patient', $pid);
                            $pp = max(0, 100 - $pct);
                            $_SESSION['chart_flash'] = 'Primary insurance updated. Insurer ' . $pct . '%, patient ' . $pp . '% at cashier.';
                        }
                        mysqli_stmt_close($st);
                    }
                } else {
                    $st = mysqli_prepare(
                        $connection,
                        'UPDATE tbl_patient_insurance SET carrier_id = ?, policy_number = ? WHERE id = ? AND patient_id = ? AND facility_id = ? LIMIT 1'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, 'isiii', $carrierId, $pol, $policyId, $pid, $fid);
                        if (mysqli_stmt_execute($st)) {
                            hms_audit_log($connection, 'patient_insurance.update', 'patient', $pid);
                            $_SESSION['chart_flash'] = 'Primary insurance carrier and policy updated.';
                        }
                        mysqli_stmt_close($st);
                    }
                }
            }
        }
    } elseif (isset($_POST['create_primary_insurance']) && hms_patient_insurance_tables_ok($connection) && hms_patient_chart_can_edit_insurance($connection)) {
        $carrierId = (int) ($_POST['new_carrier_id'] ?? 0);
        $pol = trim((string) ($_POST['new_policy_number'] ?? ''));
        $pct = max(0, min(100, (int) ($_POST['new_insurer_covered_percent'] ?? 0)));
        $cnt = 0;
        $cq = mysqli_query(
            $connection,
            'SELECT COUNT(*) AS c FROM tbl_patient_insurance WHERE patient_id = ' . (int) $pid . ' AND facility_id = ' . (int) $fid
        );
        if ($cq && $cr = mysqli_fetch_assoc($cq)) {
            $cnt = (int) ($cr['c'] ?? 0);
        }
        if ($cnt === 0 && $carrierId > 0) {
            mysqli_query(
                $connection,
                'UPDATE tbl_patient_insurance SET is_primary = 0 WHERE patient_id = ' . (int) $pid . ' AND facility_id = ' . (int) $fid
            );
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_patient_insurance (facility_id, patient_id, carrier_id, policy_number, is_primary, insurer_covered_percent) VALUES (?,?,?,?,1,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'iiisi', $fid, $pid, $carrierId, $pol, $pct);
                if (mysqli_stmt_execute($st)) {
                    hms_audit_log($connection, 'patient_insurance.create', 'patient', $pid);
                    $pp = max(0, 100 - $pct);
                    $_SESSION['chart_flash'] = 'Primary insurance saved: insurer ' . $pct . '%, patient ' . $pp . '% of listed fees.';
                }
                mysqli_stmt_close($st);
            }
        }
    } elseif ($chartDataOk && hms_can($connection, 'clinical.write') && isset($_POST['add_allergy']) && $hasAllergyTbl) {
        $sub = (string) ($_POST['substance'] ?? '');
        $rx = (string) ($_POST['reaction'] ?? '');
        $sev = (string) ($_POST['severity'] ?? '');
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient_allergy (patient_id, facility_id, substance, reaction, severity, recorded_at) VALUES (?,?,?,?,?,NOW())'
        );
        if ($st && $sub !== '') {
            mysqli_stmt_bind_param($st, 'iisss', $pid, $fid, $sub, $rx, $sev);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'allergy.create', 'patient', $pid);
            $_SESSION['chart_flash'] = 'Allergy recorded.';
        }
    } elseif ($chartDataOk && hms_can($connection, 'clinical.write') && isset($_POST['add_medication']) && $hasMedTbl) {
        $nm = (string) ($_POST['med_name'] ?? '');
        $dose = (string) ($_POST['dose'] ?? '');
        $route = (string) ($_POST['route'] ?? '');
        $ms2 = 'active';
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_patient_medication (patient_id, facility_id, name, dose, route, status) VALUES (?,?,?,?,?,?)'
        );
        if ($st && $nm !== '') {
            mysqli_stmt_bind_param($st, 'iissss', $pid, $fid, $nm, $dose, $route, $ms2);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'medication.create', 'patient', $pid);
            $_SESSION['chart_flash'] = 'Medication added.';
        }
    } elseif ($chartDataOk && $canRecordVitalsHere && isset($_POST['add_vital']) && $hasVitalTbl) {
        $sys = (int) ($_POST['bp_sys'] ?? 0);
        $dia = (int) ($_POST['bp_dia'] ?? 0);
        $hr = (int) ($_POST['heart_rate'] ?? 0);
        $tc = (float) ($_POST['temp_c'] ?? 0);
        $spo = (int) ($_POST['spo2'] ?? 0);
        $rr = (int) ($_POST['rr'] ?? 0);
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if (
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
                'source_station' => 'chart',
            ])
        ) {
            hms_audit_log($connection, 'vital.create', 'patient', $pid);
            $_SESSION['chart_flash'] = 'Vitals saved.';
        }
    }
    header('Location: patient-chart.php?id=' . $pid);
    exit;
}

$primaryInsurancePolicy = null;
$patientInsuranceCount = 0;
$insuranceCarriers = [];
if (hms_db_table_exists($connection, 'tbl_patient_insurance')) {
    $cq = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_patient_insurance WHERE patient_id = ' . (int) $pid . ' AND facility_id = ' . (int) $fid
    );
    if ($cq && $cr = mysqli_fetch_assoc($cq)) {
        $patientInsuranceCount = (int) ($cr['c'] ?? 0);
    }
    if (hms_db_column_exists($connection, 'tbl_patient_insurance', 'insurer_covered_percent')) {
        $st = mysqli_prepare(
            $connection,
            'SELECT pi.id, pi.carrier_id, pi.insurer_covered_percent, pi.policy_number, ic.name AS carrier_name
             FROM tbl_patient_insurance pi
             INNER JOIN tbl_insurance_carrier ic ON ic.id = pi.carrier_id
             WHERE pi.patient_id = ? AND pi.facility_id = ? AND pi.is_primary = 1
             ORDER BY pi.id DESC LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $pid, $fid);
            mysqli_stmt_execute($st);
            $primaryInsurancePolicy = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
        }
    } else {
        $st = mysqli_prepare(
            $connection,
            'SELECT pi.id, pi.carrier_id, pi.policy_number, ic.name AS carrier_name
             FROM tbl_patient_insurance pi
             INNER JOIN tbl_insurance_carrier ic ON ic.id = pi.carrier_id
             WHERE pi.patient_id = ? AND pi.facility_id = ? AND pi.is_primary = 1
             ORDER BY pi.id DESC LIMIT 1'
        );
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $pid, $fid);
            mysqli_stmt_execute($st);
            $primaryInsurancePolicy = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
        }
        if (is_array($primaryInsurancePolicy)) {
            $primaryInsurancePolicy['insurer_covered_percent'] = null;
        }
    }
}
if (hms_db_table_exists($connection, 'tbl_insurance_carrier')) {
    hms_insurance_seed_cameroon_carriers_for_facility($connection, $fid);
    $crq = mysqli_query(
        $connection,
        'SELECT id, name FROM tbl_insurance_carrier WHERE facility_id = ' . (int) $fid . ' AND status = 1 ORDER BY name ASC'
    );
    while ($crq && $r = mysqli_fetch_assoc($crq)) {
        $insuranceCarriers[] = $r;
    }
}

$externalDocsList = hms_patient_external_document_table_ok($connection)
    ? hms_patient_external_documents_list($connection, $fid, $pid)
    : [];

$insurerCoverageColumnOk = hms_db_table_exists($connection, 'tbl_patient_insurance')
    && hms_db_column_exists($connection, 'tbl_patient_insurance', 'insurer_covered_percent');

include 'header.php';
$ptName = (string) $patient['first_name'] . ' ' . (string) $patient['last_name'];
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Clinical chart — ' . $ptName, [
                'subtitle' => $chartLimited
                    ? 'Vitals, consultations, and primary insurance (your role).'
                    : 'Allergies, medications, vitals, consultations, prescribed tests, results, insurance share, and externally sourced documents.',
                'breadcrumbs' => [['Patients', 'patients.php'], ['Chart', '']],
                'back' => 'patients.php',
                'secondary' => array_values(array_filter([
                    !$chartLimited && hms_patient_external_document_table_ok($connection)
                        ? ['label' => 'External docs', 'url' => 'patient-external-docs.php?id=' . $pid, 'icon' => 'fa-paperclip']
                        : null,
                    !$chartLimited && hms_can($connection, 'prescription.read') && hms_workflow_table_ok($connection, 'tbl_prescription')
                        ? ['label' => 'Prescriptions', 'url' => 'prescriptions.php', 'icon' => 'fa-file-text-o']
                        : null,
                ])),
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$chartDataOk) { ?>
            <div class="alert alert-warning">Clinical chart data is not available. Import the platform migration SQL (e.g. patient allergies, medications, vitals, consultations 003, lab 013, radiology 016).</div>
            <?php } else { ?>
            <?php
            $chartConsults = hms_clinical_chart_consultations($connection, $fid, $pid, $ms);
            if ($chartLimited) {
                $chartPrescribed = [];
                $chartLabRes = [];
                $chartRadRes = [];
                $chartRx = [];
                $chartMedsShow = [];
            } else {
                $chartPrescribed = hms_clinical_chart_prescribed_from_consultations($connection, $fid, $pid, $ms);
                $chartLabRes = hms_clinical_chart_lab_results($connection, $fid, $pid, $ms);
                $chartRadRes = hms_clinical_chart_radiology_results($connection, $fid, $pid, $ms);
                $chartRx = hms_clinical_chart_prescriptions_summary($connection, $fid, $pid, $ms);
                $chartMeds = hms_clinical_chart_medications_from_consultations($connection, $fid, $pid, $ms);
                $chartMedsShow = [];
                foreach ($chartMeds as $mb) {
                    if (!empty($mb['meds']) && is_array($mb['meds'])) {
                        $chartMedsShow[] = $mb;
                    }
                }
            }
            ?>
            <div class="row">
                <?php if (!$chartLimited && $hasAllergyTbl) { ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold">Allergies</div>
                        <div class="card-body">
                            <ul class="mb-3"><?php
                            $q = mysqli_query($connection, 'SELECT substance, reaction, severity FROM tbl_patient_allergy WHERE patient_id = ' . (int) $pid . $facSql . ' ORDER BY id DESC');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<li class="mb-1">' . hms_h((string) $r['substance']) . ' — ' . hms_h((string) $r['reaction']) . ' <span class="text-muted small">(' . hms_h((string) $r['severity']) . ')</span></li>';
                            }
                            ?></ul>
                            <?php if (hms_can($connection, 'clinical.write')) { ?>
                            <form method="post" class="border-top pt-3">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-row">
                                    <div class="col-md-6 mb-2"><input class="form-control" name="substance" placeholder="Substance" required></div>
                                    <div class="col-md-6 mb-2"><input class="form-control" name="reaction" placeholder="Reaction"></div>
                                </div>
                                <input class="form-control mb-2" name="severity" placeholder="Severity">
                                <button class="btn btn-primary btn-sm" type="submit" name="add_allergy" value="1">Add allergy</button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
                <?php if (!$chartLimited && $hasMedTbl) { ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold">Medications</div>
                        <div class="card-body">
                            <ul class="mb-3"><?php
                            $q = mysqli_query($connection, 'SELECT name, dose, route, status FROM tbl_patient_medication WHERE patient_id = ' . (int) $pid . $facSql . ' ORDER BY id DESC');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<li class="mb-1">' . hms_h((string) $r['name']) . ' ' . hms_h((string) $r['dose']) . ' ' . hms_h((string) $r['route']) . ' <span class="badge badge-secondary">' . hms_h((string) $r['status']) . '</span></li>';
                            }
                            ?></ul>
                            <?php if (hms_can($connection, 'clinical.write')) { ?>
                            <form method="post" class="border-top pt-3">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-row">
                                    <div class="col-md-4 mb-2"><input class="form-control" name="med_name" placeholder="Medication" required></div>
                                    <div class="col-md-4 mb-2"><input class="form-control" name="dose" placeholder="Dose"></div>
                                    <div class="col-md-4 mb-2"><input class="form-control" name="route" placeholder="Route"></div>
                                </div>
                                <button class="btn btn-primary btn-sm" type="submit" name="add_medication" value="1">Add medication</button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
                <?php if ($hasVitalTbl) { ?>
                <div class="col-lg-6 mb-4<?php echo $chartLimited ? ' col-lg-12' : ''; ?>">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold">Vitals</div>
                        <div class="card-body">
                            <ul class="small mb-3"><?php
                            $vitalsHasRec = hms_vitals_has_recorder_columns($connection);
                            $vitalsHasAnt = hms_vitals_has_anthropometrics($connection);
                            $antSel = $vitalsHasAnt ? ', v.weight_kg, v.height_cm, v.waist_cm' : '';
                            if ($vitalsHasRec) {
                                $vq = 'SELECT v.recorded_at, v.bp_sys, v.bp_dia, v.heart_rate, v.temp_c, v.spo2, v.rr' . $antSel . ', v.source_station,
                                    e.first_name AS recorder_first_name, e.last_name AS recorder_last_name
                                    FROM tbl_vital_sign v
                                    LEFT JOIN tbl_employee e ON e.id = v.recorded_by
                                    WHERE v.patient_id = ' . (int) $pid . $facSql . ' ORDER BY v.id DESC LIMIT 8';
                            } else {
                                $baseCols = 'recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr';
                                if ($vitalsHasAnt) {
                                    $baseCols .= ', weight_kg, height_cm, waist_cm';
                                }
                                $vq = 'SELECT ' . $baseCols . ' FROM tbl_vital_sign WHERE patient_id = ' . (int) $pid . $facSql . ' ORDER BY id DESC LIMIT 8';
                            }
                            $q = mysqli_query($connection, $vq);
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $line = hms_h((string) $r['recorded_at']) . ' — BP ' . (int) $r['bp_sys'] . '/' . (int) $r['bp_dia']
                                    . ', HR ' . (int) $r['heart_rate'] . ', °C ' . hms_h((string) $r['temp_c'])
                                    . ', SpO₂ ' . (int) $r['spo2'] . ', RR ' . (int) ($r['rr'] ?? 0);
                                if ($vitalsHasAnt) {
                                    $wa = [];
                                    $wtd = hms_vitals_numeric_display($r['weight_kg'] ?? null);
                                    $htd = hms_vitals_numeric_display($r['height_cm'] ?? null);
                                    $wsd = hms_vitals_numeric_display($r['waist_cm'] ?? null);
                                    if ($wtd !== null) {
                                        $wa[] = 'Wt ' . hms_h($wtd) . ' kg';
                                    }
                                    if ($htd !== null) {
                                        $wa[] = 'Ht ' . hms_h($htd) . ' cm';
                                    }
                                    if ($wsd !== null) {
                                        $wa[] = 'Waist ' . hms_h($wsd) . ' cm';
                                    }
                                    if ($wa !== []) {
                                        $line .= ' · ' . implode(' · ', $wa);
                                    }
                                }
                                if ($vitalsHasRec) {
                                    $stn = hms_vitals_station_label(isset($r['source_station']) ? (string) $r['source_station'] : null);
                                    $rf = trim((string) ($r['recorder_first_name'] ?? ''));
                                    $rl = trim((string) ($r['recorder_last_name'] ?? ''));
                                    $who = trim($rf . ' ' . $rl);
                                    if ($who !== '') {
                                        $line .= ' · ' . hms_h($stn) . ' · ' . hms_h($who);
                                    } else {
                                        $line .= ' · ' . hms_h($stn);
                                    }
                                }
                                echo '<li class="mb-1">' . $line . '</li>';
                            }
                            ?></ul>
                            <?php if ($canRecordVitalsHere) { ?>
                            <form method="post" class="border-top pt-3">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-row">
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="bp_sys" placeholder="BP sys" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="bp_dia" placeholder="BP dia" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="heart_rate" placeholder="HR" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="temp_c" placeholder="Temp °C" step="0.1" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="spo2" placeholder="SpO₂" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="rr" placeholder="RR" type="number"></div>
                                </div>
                                <div class="form-row">
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="weight_kg" placeholder="Wt kg (opt.)" step="0.1" min="0" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="height_cm" placeholder="Ht cm (opt.)" step="0.1" min="0" type="number"></div>
                                    <div class="col-6 col-md-4 mb-2"><input class="form-control" name="waist_cm" placeholder="Waist cm (opt.)" step="0.1" min="0" type="number"></div>
                                </div>
                                <button class="btn btn-primary btn-sm mt-1" type="submit" name="add_vital" value="1">Record vitals</button>
                            </form>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>

            <?php if (hms_workflow_table_ok($connection, 'tbl_consultation')) { ?>
            <div class="row">
                <div class="col-12 mb-3">
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-header bg-white font-weight-bold">Consultations</div>
                        <div class="card-body">
                            <?php if ($chartConsults === []) { ?>
                            <p class="text-muted small mb-0">No consultations recorded.</p>
                            <?php } else { ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($chartConsults as $cc) {
                                    $cid = (int) ($cc['id'] ?? 0);
                                    $df = trim((string) ($cc['doc_first'] ?? '') . ' ' . (string) ($cc['doc_last'] ?? ''));
                                    ?>
                                <li class="mb-2 pb-2 border-bottom">
                                    <strong>#C<?php echo hms_h(str_pad((string) $cid, 5, '0', STR_PAD_LEFT)); ?></strong>
                                    <span class="text-muted"><?php echo hms_h((string) ($cc['created_at'] ?? '')); ?></span>
                                    <span class="badge badge-light border"><?php echo hms_h((string) ($cc['status'] ?? '')); ?></span>
                                    <?php if ($df !== '') { ?><span class="text-muted"> — <?php echo hms_h($df); ?></span><?php } ?>
                                    <?php if (trim((string) ($cc['chief_complaint'] ?? '')) !== '') { ?>
                                    <br><span class="text-dark"><?php echo hms_h((string) $cc['chief_complaint']); ?></span>
                                    <?php } ?>
                                    <?php if (hms_can($connection, 'consult.read')) { ?>
                                    <a class="small ml-1" href="consultations.php">List</a>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <?php if (!$chartLimited && $chartMedsShow !== []) { ?>
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-medkit mr-1 text-secondary"></i> Medications (from consultations)</div>
                        <div class="card-body small">
                            <p class="text-muted mb-2">Prescribed on the visit form; <span class="badge badge-info">External purchase</span> means the patient may obtain the product outside the hospital pharmacy.</p>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($chartMedsShow as $mb) {
                                    $mcid = (int) ($mb['consultation_id'] ?? 0);
                                    ?>
                                <li class="mb-3 pb-3 border-bottom">
                                    <strong>#C<?php echo hms_h(str_pad((string) $mcid, 5, '0', STR_PAD_LEFT)); ?></strong>
                                    <span class="text-muted"><?php echo hms_h((string) ($mb['created_at'] ?? '')); ?></span>
                                    <ul class="pl-3 mb-0 mt-1">
                                        <?php foreach ($mb['meds'] as $mx) {
                                            if (!is_array($mx)) {
                                                continue;
                                            }
                                            $mn = trim((string) ($mx['name'] ?? ''));
                                            $extRx = !empty($mx['purchase_external']);
                                            ?>
                                        <li class="mb-1">
                                            <?php echo hms_h($mn !== '' ? $mn : 'Medication'); ?>
                                            <?php if ($extRx) { ?><span class="badge badge-info">External purchase</span><?php } ?>
                                            <?php
                                            $bits = array_filter([
                                                trim((string) ($mx['dosage'] ?? '')),
                                                trim((string) ($mx['frequency'] ?? '')),
                                                trim((string) ($mx['duration'] ?? '')),
                                            ]);
                                            if ($bits !== []) {
                                                echo ' <span class="text-muted">— ' . hms_h(implode(' · ', $bits)) . '</span>';
                                            }
                                            ?>
                                        </li>
                                        <?php } ?>
                                    </ul>
                                </li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <?php if (!$chartLimited) { ?>
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-flask text-purple mr-1"></i> Laboratory</div>
                        <div class="card-body small">
                            <p class="text-muted mb-2">Prescribed by the clinician (from consultation) and entered results.</p>
                            <?php if ($chartPrescribed === [] && $chartLabRes === []) { ?>
                            <p class="text-muted mb-0">No laboratory orders or results yet.</p>
                            <?php } else { ?>
                            <?php if ($chartPrescribed !== []) { ?>
                            <div class="font-weight-bold mb-1">Prescribed</div>
                            <ul class="mb-3 pl-3">
                                <?php foreach ($chartPrescribed as $block) {
                                    foreach ($block['labs'] as $lab) {
                                        if (!is_array($lab)) {
                                            continue;
                                        }
                                        $nm = trim((string) ($lab['name'] ?? ''));
                                        $labExt = !empty($lab['external']);
                                        $match = null;
                                        foreach ($chartLabRes as $lr) {
                                            if (hms_clinical_chart_result_matches_prescribed((string) ($lr['test_name'] ?? ''), $nm)) {
                                                $match = $lr;
                                                break;
                                            }
                                        }
                                        ?>
                                <li class="mb-1">
                                    <?php echo hms_h($nm !== '' ? $nm : 'Lab test'); ?>
                                    <?php if ($labExt) { ?> <span class="badge badge-info">External</span><?php } ?>
                                    <?php if ($match !== null) {
                                        $st = (string) ($match['status'] ?? '');
                                        $isDone = strtolower($st) === 'received';
                                        $mid = (int) ($match['id'] ?? 0);
                                        ?>
                                    <span class="badge <?php echo $isDone ? 'badge-success' : 'badge-warning'; ?>"><?php echo hms_h($st); ?></span>
                                    <?php if ($mid > 0) { ?>
                                    <span class="d-block mt-1">
                                        <a class="small" href="clinical-result-report.php?type=lab&amp;id=<?php echo $mid; ?>">View template</a>
                                        <span class="text-muted">·</span>
                                        <a class="small" href="clinical-result-report.php?type=lab&amp;id=<?php echo $mid; ?>&amp;download=1">Download PDF</a>
                                    </span>
                                    <?php } ?>
                                    <?php if (trim((string) ($match['notes'] ?? '')) !== '') { ?>
                                    <span class="text-muted d-block"><?php echo hms_h((string) $match['notes']); ?></span>
                                    <?php } ?>
                                    <?php } elseif ($labExt) { ?>
                                    <span class="badge badge-light border">Awaiting external report</span>
                                    <?php if (hms_patient_external_document_table_ok($connection)) { ?>
                                    <span class="text-muted d-block small">Upload a PDF or image under <a href="patient-external-docs.php?id=<?php echo $pid; ?>">External docs</a>.</span>
                                    <?php } ?>
                                    <?php } else { ?>
                                    <span class="badge badge-secondary">Pending result</span>
                                    <?php } ?>
                                </li>
                                    <?php
                                    }
                                } ?>
                            </ul>
                            <?php } ?>
                            <?php if ($chartLabRes !== []) { ?>
                            <div class="font-weight-bold mb-1">Results on file</div>
                            <ul class="mb-0 pl-3">
                                <?php foreach ($chartLabRes as $lr) {
                                    $lid = (int) ($lr['id'] ?? 0);
                                    ?>
                                <li class="mb-1">
                                    <?php echo hms_h((string) ($lr['test_name'] ?? '')); ?>
                                    <span class="badge badge-light border"><?php echo hms_h((string) ($lr['status'] ?? '')); ?></span>
                                    <span class="text-muted"><?php echo hms_h((string) ($lr['appointment_date'] ?? '')); ?></span>
                                    <?php if ($lid > 0) { ?>
                                    <span class="d-block">
                                        <a class="small" href="clinical-result-report.php?type=lab&amp;id=<?php echo $lid; ?>">View template</a>
                                        <span class="text-muted">·</span>
                                        <a class="small" href="clinical-result-report.php?type=lab&amp;id=<?php echo $lid; ?>&amp;download=1">Download PDF</a>
                                    </span>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-film mr-1" style="color:#0891b2"></i> Radiology</div>
                        <div class="card-body small">
                            <p class="text-muted mb-2">Imaging prescribed in consultation and reported studies.</p>
                            <?php if ($chartPrescribed === [] && $chartRadRes === []) { ?>
                            <p class="text-muted mb-0">No radiology orders or results yet.</p>
                            <?php } else { ?>
                            <?php if ($chartPrescribed !== []) { ?>
                            <div class="font-weight-bold mb-1">Prescribed</div>
                            <ul class="mb-3 pl-3">
                                <?php foreach ($chartPrescribed as $block) {
                                    foreach ($block['rads'] as $rad) {
                                        if (!is_array($rad)) {
                                            continue;
                                        }
                                        $nm = trim((string) ($rad['name'] ?? ''));
                                        $radExt = !empty($rad['external']);
                                        $match = null;
                                        foreach ($chartRadRes as $rr) {
                                            if (hms_clinical_chart_result_matches_prescribed((string) ($rr['exam_name'] ?? ''), $nm)) {
                                                $match = $rr;
                                                break;
                                            }
                                        }
                                        ?>
                                <li class="mb-1">
                                    <?php echo hms_h($nm !== '' ? $nm : 'Imaging'); ?>
                                    <?php if ($radExt) { ?> <span class="badge badge-info">External</span><?php } ?>
                                    <?php if ($match !== null) {
                                        $st = (string) ($match['status'] ?? '');
                                        $isDone = strtolower($st) === 'received';
                                        $rid = (int) ($match['id'] ?? 0);
                                        ?>
                                    <span class="badge <?php echo $isDone ? 'badge-success' : 'badge-warning'; ?>"><?php echo hms_h($st); ?></span>
                                    <?php if ($rid > 0) { ?>
                                    <span class="d-block mt-1">
                                        <a class="small" href="clinical-result-report.php?type=rad&amp;id=<?php echo $rid; ?>">View template</a>
                                        <span class="text-muted">·</span>
                                        <a class="small" href="clinical-result-report.php?type=rad&amp;id=<?php echo $rid; ?>&amp;download=1">Download PDF</a>
                                    </span>
                                    <?php } ?>
                                    <?php if (trim((string) ($match['findings'] ?? '')) !== '') { ?>
                                    <span class="text-muted d-block"><?php echo hms_h((string) $match['findings']); ?></span>
                                    <?php } ?>
                                    <?php } elseif ($radExt) { ?>
                                    <span class="badge badge-light border">Awaiting external report</span>
                                    <?php if (hms_patient_external_document_table_ok($connection)) { ?>
                                    <span class="text-muted d-block small">Upload under <a href="patient-external-docs.php?id=<?php echo $pid; ?>">External docs</a>.</span>
                                    <?php } ?>
                                    <?php } else { ?>
                                    <span class="badge badge-secondary">Pending report</span>
                                    <?php } ?>
                                </li>
                                    <?php
                                    }
                                } ?>
                            </ul>
                            <?php } ?>
                            <?php if ($chartRadRes !== []) { ?>
                            <div class="font-weight-bold mb-1">Reports on file</div>
                            <ul class="mb-0 pl-3">
                                <?php foreach ($chartRadRes as $rr) {
                                    $rrid = (int) ($rr['id'] ?? 0);
                                    ?>
                                <li class="mb-1">
                                    <?php echo hms_h((string) ($rr['exam_name'] ?? '')); ?> (<?php echo hms_h((string) ($rr['modality'] ?? '')); ?>)
                                    <span class="badge badge-light border"><?php echo hms_h((string) ($rr['status'] ?? '')); ?></span>
                                    <?php if ($rrid > 0) { ?>
                                    <span class="d-block">
                                        <a class="small" href="clinical-result-report.php?type=rad&amp;id=<?php echo $rrid; ?>">View template</a>
                                        <span class="text-muted">·</span>
                                        <a class="small" href="clinical-result-report.php?type=rad&amp;id=<?php echo $rrid; ?>&amp;download=1">Download PDF</a>
                                    </span>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <?php if (!$chartLimited && hms_workflow_table_ok($connection, 'tbl_prescription')) { ?>
            <div class="row">
                <div class="col-12 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-medkit mr-1 text-success"></i> Prescriptions (pharmacy)</div>
                        <div class="card-body small">
                            <?php if ($chartRx === []) { ?>
                            <p class="text-muted mb-0">No prescriptions for this patient.</p>
                            <?php } else { ?>
                            <ul class="mb-0 pl-3">
                                <?php foreach ($chartRx as $rx) {
                                    $rid = (int) ($rx['id'] ?? 0);
                                    $df = trim((string) ($rx['doc_first'] ?? '') . ' ' . (string) ($rx['doc_last'] ?? ''));
                                    ?>
                                <li class="mb-2">
                                    <a href="prescription.php?id=<?php echo $rid; ?>">Rx #<?php echo $rid; ?></a>
                                    — <?php echo hms_h((string) ($rx['title'] ?? '')); ?>
                                    <span class="badge badge-light border"><?php echo hms_h((string) ($rx['status'] ?? '')); ?></span>
                                    <span class="text-muted"><?php echo hms_h((string) ($rx['created_at'] ?? '')); ?></span>
                                    <?php if ($df !== '') { ?><span class="text-muted"> — <?php echo hms_h($df); ?></span><?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <?php if ((!$chartLimited && (hms_db_table_exists($connection, 'tbl_patient_insurance') || hms_patient_external_document_table_ok($connection)))
                || ($chartLimited && hms_db_table_exists($connection, 'tbl_patient_insurance'))) { ?>
            <div class="row">
                <?php if (hms_db_table_exists($connection, 'tbl_patient_insurance')) { ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-shield mr-1 text-info"></i> Insurance (cashier share)</div>
                        <div class="card-body small">
                            <?php if (!$insurerCoverageColumnOk) { ?>
                            <div class="alert alert-warning py-2 mb-3">
                                <strong>Coverage % not active yet.</strong> Run <code>hms/database/migrations/025_insurance_coverage_external_docs.sql</code> in phpMyAdmin to add <code>insurer_covered_percent</code>. Then you can set insurer/patient splits here. Carriers are still managed under <a href="insurance.php">Insurance</a>.
                            </div>
                            <?php } else { ?>
                            <p class="text-muted mb-2">Set <strong>what share of the listed price</strong> the insurer pays (0–100%). Patient pays the rest at the cashier. Any split works: e.g. <strong>70</strong> → patient 30%; <strong>50</strong> → 50/50; <strong>20</strong> → patient 80%; <strong>100</strong> → no patient share on insured lines.</p>
                            <?php } ?>
                            <?php if (is_array($primaryInsurancePolicy)) {
                                $piId = (int) ($primaryInsurancePolicy['id'] ?? 0);
                                $curCarrierId = (int) ($primaryInsurancePolicy['carrier_id'] ?? 0);
                                $curPct = (int) ($primaryInsurancePolicy['insurer_covered_percent'] ?? 0);
                                $carr = (string) ($primaryInsurancePolicy['carrier_name'] ?? '');
                                $poln = trim((string) ($primaryInsurancePolicy['policy_number'] ?? ''));
                                ?>
                            <?php if (hms_patient_chart_can_edit_insurance($connection)) { ?>
                            <form method="post" class="border-top pt-3 mt-2">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="patient_insurance_id" value="<?php echo $piId; ?>">
                                <div class="form-group mb-2">
                                    <label for="edit_carrier_id">Carrier</label>
                                    <select class="form-control" id="edit_carrier_id" name="edit_carrier_id" required>
                                        <option value="">— Select —</option>
                                        <?php foreach ($insuranceCarriers as $ic) {
                                            $iid = (int) ($ic['id'] ?? 0);
                                            ?>
                                        <option value="<?php echo $iid; ?>"<?php echo $iid === $curCarrierId ? ' selected' : ''; ?>><?php echo hms_h((string) ($ic['name'] ?? '')); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-group mb-2">
                                    <label for="edit_policy_number">Policy / member ID (optional)</label>
                                    <input class="form-control" id="edit_policy_number" name="edit_policy_number" maxlength="80" autocomplete="off" value="<?php echo hms_h($poln); ?>">
                                </div>
                                <?php if ($insurerCoverageColumnOk) { ?>
                                <div class="form-group mb-2">
                                    <label for="insurer_covered_percent">Insurer share (% of listed price)</label>
                                    <input class="form-control" id="insurer_covered_percent" name="insurer_covered_percent" type="number" min="0" max="100" step="1" inputmode="numeric" title="0–100, any whole-number split" value="<?php echo $curPct; ?>" style="max-width:6rem;">
                                    <small class="form-text text-muted">Patient pays the complement at the cashier.</small>
                                </div>
                                <p class="text-muted small mb-2">Patient pays at POS: <strong><?php echo (int) hms_patient_copay_percent_at_pos($curPct); ?>%</strong> of listed amounts (before save, based on value above).</p>
                                <?php } else { ?>
                                <p class="small text-muted mb-2">Coverage % is unavailable until migration <strong>025</strong> is applied. You can still change carrier and policy number.</p>
                                <?php } ?>
                                <button type="submit" name="update_primary_insurance" value="1" class="btn btn-primary btn-sm">Save</button>
                            </form>
                            <?php } else { ?>
                            <p class="mb-2"><strong><?php echo hms_h($carr !== '' ? $carr : 'Carrier'); ?></strong>
                                <?php if ($poln !== '') { ?><span class="text-muted"> — Policy <?php echo hms_h($poln); ?></span><?php } ?>
                            </p>
                            <?php if (!$insurerCoverageColumnOk) { ?>
                            <p class="small text-muted mb-0">Primary policy on file. Run migration <strong>025</strong> to enable insurer/patient % fields.</p>
                            <?php } elseif ($insurerCoverageColumnOk) { ?>
                            <p class="mb-0">Insurer share: <strong><?php echo $curPct; ?>%</strong> — patient co-pay at POS: <strong><?php echo (int) hms_patient_copay_percent_at_pos($curPct); ?>%</strong>.</p>
                            <?php } ?>
                            <?php } ?>
                            <?php } elseif ($patientInsuranceCount > 0) { ?>
                            <p class="text-warning mb-0">This patient has insurance rows but none marked primary. Set <code>is_primary = 1</code> on one policy, or contact an administrator.</p>
                            <?php } elseif ($insuranceCarriers === []) { ?>
                            <p class="text-muted mb-0">Add an insurance carrier for this site under <a href="insurance.php">Insurance</a>, then create a primary policy here.</p>
                            <?php } elseif (hms_patient_chart_can_edit_insurance($connection) && $insurerCoverageColumnOk) { ?>
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-group">
                                    <label for="new_carrier_id">Carrier</label>
                                    <select class="form-control" id="new_carrier_id" name="new_carrier_id" required>
                                        <option value="">— Select —</option>
                                        <?php foreach ($insuranceCarriers as $ic) {
                                            $iid = (int) ($ic['id'] ?? 0);
                                            ?>
                                        <option value="<?php echo $iid; ?>"><?php echo hms_h((string) ($ic['name'] ?? '')); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="new_policy_number">Policy / member ID (optional)</label>
                                    <input class="form-control" id="new_policy_number" name="new_policy_number" maxlength="80" autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label for="new_insurer_covered_percent">Insurer share (% of listed price, 0–100)</label>
                                    <input class="form-control" id="new_insurer_covered_percent" name="new_insurer_covered_percent" type="number" min="0" max="100" step="1" inputmode="numeric" placeholder="e.g. 70" value="" style="max-width:6rem;">
                                    <small class="form-text text-muted">Leave 0 for full patient pay; enter any split (70/30, 50/50, 20/80, 100% insurer, etc.).</small>
                                </div>
                                <button type="submit" name="create_primary_insurance" value="1" class="btn btn-primary btn-sm">Save primary policy</button>
                            </form>
                            <?php } elseif (hms_patient_chart_can_edit_insurance($connection) && !$insurerCoverageColumnOk) { ?>
                            <p class="text-muted mb-0">After migration <strong>025</strong>, you can add a primary policy and coverage % on this card.</p>
                            <?php } else { ?>
                            <p class="text-muted mb-0">No primary insurance on file.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
                <?php if (!$chartLimited && hms_patient_external_document_table_ok($connection)) { ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center">
                            <span><i class="fa fa-paperclip mr-1"></i> External lab / imaging / pharmacy documents</span>
                            <a class="btn btn-sm btn-outline-primary" href="patient-external-docs.php?id=<?php echo $pid; ?>">Upload / manage</a>
                        </div>
                        <div class="card-body small">
                            <p class="text-muted">Use this when tests or medicines were obtained outside the hospital so clinicians still see reports on the chart.</p>
                            <?php if ($externalDocsList === []) { ?>
                            <p class="text-muted mb-0">No external documents uploaded yet.</p>
                            <?php } else { ?>
                            <ul class="mb-0 pl-3">
                                <?php foreach ($externalDocsList as $ed) {
                                    $eid = (int) ($ed['id'] ?? 0);
                                    ?>
                                <li class="mb-1">
                                    <span class="badge badge-light border"><?php echo hms_h((string) ($ed['doc_kind'] ?? '')); ?></span>
                                    <?php echo hms_h((string) ($ed['title'] ?? 'Document')); ?>
                                    <span class="text-muted"><?php echo hms_h((string) ($ed['created_at'] ?? '')); ?></span>
                                    <?php if ($eid > 0) { ?>
                                    — <a href="patient-external-docs.php?id=<?php echo $pid; ?>&amp;download=<?php echo $eid; ?>">Download</a>
                                    <?php } ?>
                                </li>
                                <?php } ?>
                            </ul>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php } ?>

            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
