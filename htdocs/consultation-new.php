<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/appointments_dreams.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'consult.write');
hms_require_permission($connection, 'patient.read');
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$ms = hms_multi_site_enabled($connection);
$defs = hms_consult_param_defs($connection, $fid);
if ($defs === [] && $fid !== 1) {
    $defs = hms_consult_param_defs($connection, 1);
}
$ok = hms_workflow_table_ok($connection, 'tbl_consultation');

/* ---------- Load existing consultation if editing ---------- */
$editId = (int) ($_GET['id'] ?? 0);
$editRow = null;
$editPatient = null;
$editObservations = [];

if ($editId > 0 && $ok) {
    $photoSel = hms_db_column_exists($connection, 'tbl_patient', 'photo') ? ', p.photo' : '';
    $eq = mysqli_prepare(
        $connection,
        'SELECT c.*, p.first_name, p.last_name, p.dob, p.gender, p.phone' . $photoSel . '
         FROM tbl_consultation c JOIN tbl_patient p ON p.id = c.patient_id
         WHERE c.id = ? AND c.facility_id = ? LIMIT 1'
    );
    if ($eq) {
        mysqli_stmt_bind_param($eq, 'ii', $editId, $fid);
        if (mysqli_stmt_execute($eq)) {
            $editRow = hms_stmt_fetch_assoc($eq);
        }
        mysqli_stmt_close($eq);
    }
    if ($editRow) {
        $editPatient = [
            'id' => (int) $editRow['patient_id'],
            'first_name' => (string) $editRow['first_name'],
            'last_name' => (string) $editRow['last_name'],
            'dob' => (string) ($editRow['dob'] ?? ''),
            'gender' => (string) ($editRow['gender'] ?? ''),
            'phone' => (string) ($editRow['phone'] ?? ''),
        ];
        $oq = mysqli_query($connection, 'SELECT param_code, value_text FROM tbl_consult_observation WHERE consultation_id = ' . (int) $editId);
        while ($oq && $or = mysqli_fetch_assoc($oq)) {
            $editObservations[(string) $or['param_code']] = (string) $or['value_text'];
        }
    }
}

/* ---------- POST: Save consultation ---------- */
if ($ok && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['create_consultation'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['consult_flash'] = 'Invalid security token.';
        header('Location: consultation-new.php');
        exit;
    }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $ctype = (string) ($_POST['consultation_type'] ?? 'general');
    if (!in_array($ctype, ['general', 'specialist'], true)) {
        $ctype = 'general';
    }
    $chief = trim((string) ($_POST['chief_complaint'] ?? ''));
    $dept = trim((string) ($_POST['department'] ?? ''));
    $catalogPick = (int) ($_POST['consult_fee_catalog_id'] ?? 0);
    $resolvedFee = hms_billing_resolve_consultation_catalog($connection, $fid, $ctype, $catalogPick, $dept);
    $feePosted = max(0, (int) ($_POST['consult_fee_xaf'] ?? 0));
    if ($feePosted <= 0 && $resolvedFee !== null) {
        $feePosted = $resolvedFee['amount'];
    }
    if ($feePosted <= 0) {
        $feePosted = hms_billing_default_consult_fee_xaf($ctype);
    }
    $fee = $feePosted;
    $cptForCharge = ($resolvedFee !== null && ($resolvedFee['cpt'] ?? '') !== '') ? (string) $resolvedFee['cpt'] : 'CONSULT';
    $feeDescLabel = ($resolvedFee !== null && ($resolvedFee['label'] ?? '') !== '')
        ? (string) $resolvedFee['label']
        : ('Consultation fee (' . $ctype . ')');
    $advice = trim((string) ($_POST['advice'] ?? ''));
    $nextConsult = trim((string) ($_POST['next_consultation'] ?? ''));
    $emptyStomach = trim((string) ($_POST['empty_stomach'] ?? ''));
    $investigations = trim((string) ($_POST['investigations'] ?? ''));

    /* Validate patient */
    $chk = null;
    if ($ms) {
        $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
    } else {
        $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 'i', $pid);
    }
    mysqli_stmt_execute($chk);
    $pok = (bool) hms_stmt_fetch_assoc($chk);
    mysqli_stmt_close($chk);

    if (!$pok || $pid < 1) {
        $_SESSION['consult_flash'] = 'Select a valid patient.';
        header('Location: consultation-new.php');
        exit;
    }

    $emergencyConsult = isset($_POST['consult_emergency']);
    $cashierReceiptRef = trim((string) ($_POST['cashier_receipt_ref'] ?? ''));
    $billingExcNote = trim((string) ($_POST['billing_exception_note'] ?? ''));
    $hasBillingWrite = hms_can($connection, 'billing.write');
    $canBillingOverride = hms_can($connection, 'consult.billing_override');

    $prepayTicketRow = null;
    if ($cashierReceiptRef !== '' && function_exists('hms_payment_ticket_validate_consult_prepay') && hms_payment_ticket_tables_ok($connection)) {
        $prepayTicketRow = hms_payment_ticket_validate_consult_prepay($connection, $fid, $pid, $cashierReceiptRef);
    }

    $gateOk = false;
    if ($fee <= 0) {
        $gateOk = true;
    } elseif ($emergencyConsult) {
        $gateOk = true;
    } elseif ($billingExcNote !== '' && $canBillingOverride) {
        $gateOk = true;
    } elseif ($prepayTicketRow !== null) {
        $gateOk = true;
    } elseif (!hms_payment_ticket_tables_ok($connection)) {
        /* Legacy before migration 023 */
        $gateOk = $cashierReceiptRef !== '' || $emergencyConsult || ($billingExcNote !== '' && $canBillingOverride);
        if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
            $gateOk = $fee <= 0 || $emergencyConsult || $cashierReceiptRef !== '' || $billingExcNote !== '';
        }
    }
    if (!$gateOk) {
        $_SESSION['consult_flash'] = 'Consultation cannot proceed until the patient has paid the consultation fee at the cashier: enter the payment code issued by the cashier, or mark Emergency, or a supervisor enters a hospital-approved waiver.';
        header('Location: consultation-new.php' . ($pid > 0 ? ('?patient_id=' . (int) $pid) : ''));
        exit;
    }

    $status = 'triaged';
    $feeChargeId = 0;
    $feeChargeLabel = '';

    mysqli_begin_transaction($connection);
    try {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_consultation (facility_id, patient_id, consultation_type, status, chief_complaint, consult_fee_xaf, created_by) VALUES (?,?,?,?,?,?,?)'
        );
        mysqli_stmt_bind_param($st, 'iisssii', $fid, $pid, $ctype, $status, $chief, $fee, $uid);
        mysqli_stmt_execute($st);
        $cid = (int) mysqli_insert_id($connection);
        mysqli_stmt_close($st);

        hms_appointment_clear_requests_for_patient($connection, $pid, $fid);

        /* Store vitals as observations */
        $vitalCodes = ['temperature', 'pulse', 'respiratory_rate', 'spo2', 'height', 'weight', 'bmi', 'waist', 'bsa'];
        foreach ($vitalCodes as $vc) {
            $val = trim((string) ($_POST['vital_' . $vc] ?? ''));
            if ($val === '') continue;
            $insO = mysqli_prepare($connection, 'INSERT INTO tbl_consult_observation (consultation_id, param_code, value_text) VALUES (?,?,?)');
            mysqli_stmt_bind_param($insO, 'iss', $cid, $vc, $val);
            mysqli_stmt_execute($insO);
            mysqli_stmt_close($insO);
        }

        /* Store dynamic param observations */
        foreach ($defs as $d) {
            $code = (string) $d['param_code'];
            $val = trim((string) ($_POST['cparam_' . $code] ?? ''));
            if ($val === '') continue;
            $insO = mysqli_prepare($connection, 'INSERT INTO tbl_consult_observation (consultation_id, param_code, value_text) VALUES (?,?,?)');
            mysqli_stmt_bind_param($insO, 'iss', $cid, $code, $val);
            mysqli_stmt_execute($insO);
            mysqli_stmt_close($insO);
        }

        /* Store medications as JSON observation */
        $medNames = $_POST['med_name'] ?? [];
        $medDosages = $_POST['med_dosage'] ?? [];
        $medDurations = $_POST['med_duration'] ?? [];
        $medFrequencies = $_POST['med_frequency'] ?? [];
        $medTimings = $_POST['med_timing'] ?? [];
        $medInstructions = $_POST['med_instructions'] ?? [];
        $medExternal = $_POST['med_external'] ?? [];
        if (!is_array($medExternal)) {
            $medExternal = [];
        }
        $medications = [];
        if (is_array($medNames)) {
            for ($mi = 0; $mi < count($medNames); $mi++) {
                $mName = trim((string) ($medNames[$mi] ?? ''));
                if ($mName === '') {
                    continue;
                }
                $medications[] = [
                    'name' => $mName,
                    'dosage' => trim((string) ($medDosages[$mi] ?? '')),
                    'duration' => trim((string) ($medDurations[$mi] ?? '')),
                    'frequency' => trim((string) ($medFrequencies[$mi] ?? '')),
                    'timing' => trim((string) ($medTimings[$mi] ?? '')),
                    'instructions' => trim((string) ($medInstructions[$mi] ?? '')),
                    'purchase_external' => !empty($medExternal[$mi]) && (string) $medExternal[$mi] === '1',
                ];
            }
        }
        if ($medications !== []) {
            $medsJson = json_encode($medications, JSON_UNESCAPED_UNICODE);
            $medCode = 'medications_json';
            $insM = mysqli_prepare($connection, 'INSERT INTO tbl_consult_observation (consultation_id, param_code, value_text) VALUES (?,?,?)');
            mysqli_stmt_bind_param($insM, 'iss', $cid, $medCode, $medsJson);
            mysqli_stmt_execute($insM);
            mysqli_stmt_close($insM);
        }

        $labIdsPost = $_POST['lab_catalog_id'] ?? [];
        $radIdsPost = $_POST['rad_catalog_id'] ?? [];
        $labExtPost = $_POST['lab_external'] ?? [];
        $radExtPost = $_POST['rad_external'] ?? [];
        if (!is_array($labIdsPost)) {
            $labIdsPost = [];
        }
        if (!is_array($radIdsPost)) {
            $radIdsPost = [];
        }
        if (!is_array($labExtPost)) {
            $labExtPost = [];
        }
        if (!is_array($radExtPost)) {
            $radExtPost = [];
        }
        $labOrd = [];
        $radOrd = [];
        if (function_exists('hms_billing_catalog_service_row')) {
            foreach ($labIdsPost as $i => $rawLab) {
                $lid = (int) $rawLab;
                if ($lid < 1) {
                    continue;
                }
                $row = hms_billing_catalog_service_row($connection, $fid, $lid);
                if ($row === null || strtolower(trim((string) ($row['category'] ?? ''))) !== 'laboratory') {
                    continue;
                }
                $ext = !empty($labExtPost[$i]) && (string) $labExtPost[$i] === '1';
                $labOrd[] = [
                    'catalog_id' => $lid,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'cpt_code' => trim((string) ($row['cpt_code'] ?? '')),
                    'subcategory' => trim((string) ($row['subcategory'] ?? '')),
                    'price_xaf' => max(0, (int) round((float) ($row['price'] ?? 0))),
                    'payment_note' => 'Pay at cashier and present the receipt to the Laboratory unit before sampling (emergency / hospital-approved exceptions apply).',
                    'external' => $ext,
                ];
            }
            foreach ($radIdsPost as $i => $rawRad) {
                $rid = (int) $rawRad;
                if ($rid < 1) {
                    continue;
                }
                $row = hms_billing_catalog_service_row($connection, $fid, $rid);
                if ($row === null || strtolower(trim((string) ($row['category'] ?? ''))) !== 'radiology') {
                    continue;
                }
                $ext = !empty($radExtPost[$i]) && (string) $radExtPost[$i] === '1';
                $radOrd[] = [
                    'catalog_id' => $rid,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'cpt_code' => trim((string) ($row['cpt_code'] ?? '')),
                    'subcategory' => trim((string) ($row['subcategory'] ?? '')),
                    'price_xaf' => max(0, (int) round((float) ($row['price'] ?? 0))),
                    'payment_note' => 'Pay at cashier and present the receipt to the Radiology unit before the exam (emergency / hospital-approved exceptions apply).',
                    'external' => $ext,
                ];
            }
        }
        foreach (
            [
                'prescribed_lab_json' => $labOrd !== [] ? json_encode($labOrd, JSON_UNESCAPED_UNICODE) : '',
                'prescribed_radiology_json' => $radOrd !== [] ? json_encode($radOrd, JSON_UNESCAPED_UNICODE) : '',
                'consult_emergency' => $emergencyConsult ? '1' : '',
                'consult_cashier_receipt' => $cashierReceiptRef,
                'consult_billing_exception' => ($billingExcNote !== '' && $canBillingOverride) ? $billingExcNote : '',
            ] as $pcode => $pval
        ) {
            if ($pval === '') {
                continue;
            }
            $insX = mysqli_prepare($connection, 'INSERT INTO tbl_consult_observation (consultation_id, param_code, value_text) VALUES (?,?,?)');
            mysqli_stmt_bind_param($insX, 'iss', $cid, $pcode, $pval);
            mysqli_stmt_execute($insX);
            mysqli_stmt_close($insX);
        }

        /* Store investigations, advice, follow-up */
        foreach ([
            'investigations' => $investigations,
            'advice' => $advice,
            'next_consultation' => $nextConsult,
            'empty_stomach' => $emptyStomach,
        ] as $pcode => $pval) {
            if ($pval === '') continue;
            $insE = mysqli_prepare($connection, 'INSERT INTO tbl_consult_observation (consultation_id, param_code, value_text) VALUES (?,?,?)');
            mysqli_stmt_bind_param($insE, 'iss', $cid, $pcode, $pval);
            mysqli_stmt_execute($insE);
            mysqli_stmt_close($insE);
        }

        /* Link cashier consultation prepayment ticket (fee paid before visit) */
        if ($prepayTicketRow !== null) {
            $ptid = (int) ($prepayTicketRow['id'] ?? 0);
            $pCharge = (int) ($prepayTicketRow['charge_id'] ?? 0);
            $paidAtT = trim((string) ($prepayTicketRow['paid_at'] ?? ''));
            if ($paidAtT === '') {
                $paidAtT = date('Y-m-d H:i:s');
            }
            if ($ptid > 0) {
                $upt = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_payment_ticket SET consultation_id = ? WHERE id = ? AND facility_id = ? AND patient_id = ? AND status = \'paid\' AND consultation_id IS NULL LIMIT 1'
                );
                if ($upt) {
                    mysqli_stmt_bind_param($upt, 'iiii', $cid, $ptid, $fid, $pid);
                    mysqli_stmt_execute($upt);
                    $linked = mysqli_stmt_affected_rows($upt);
                    mysqli_stmt_close($upt);
                    if ($linked < 1) {
                        throw new RuntimeException('This cashier payment code was already used or is invalid.');
                    }
                }
            }
            if ($pCharge > 0 && hms_workflow_table_ok($connection, 'tbl_consultation')) {
                $stFee = 'fee_paid';
                $upc = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_consultation SET fee_charge_id = ?, fee_paid_at = ?, status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($upc) {
                    mysqli_stmt_bind_param($upc, 'issii', $pCharge, $paidAtT, $stFee, $cid, $fid);
                    mysqli_stmt_execute($upc);
                    mysqli_stmt_close($upc);
                }
                $feeChargeId = $pCharge;
            }
        }

        mysqli_commit($connection);
        $cashierPayCode = null;
        if (function_exists('hms_payment_ticket_sync_from_consultation') && hms_payment_ticket_tables_ok($connection)) {
            $cashierPayCode = hms_payment_ticket_sync_from_consultation(
                $connection,
                $fid,
                $pid,
                $cid,
                $fee,
                $feeChargeId > 0,
                $emergencyConsult,
                $cashierReceiptRef,
                $feeDescLabel,
                $labOrd,
                $radOrd,
                $uid
            );
        }
        hms_audit_log($connection, 'consultation.create', 'consultation', $cid);
        $flashMsg = 'Consultation #C' . str_pad((string) $cid, 5, '0', STR_PAD_LEFT) . ' saved.';
        if ($cashierPayCode !== null && $cashierPayCode !== '') {
            $flashMsg .= ' Payment / reference code: ' . $cashierPayCode . ' — pay any patient balance at cashier before in-hospital lab/radiology; external items are marked settled in the ticket.';
        }
        $_SESSION['consult_flash'] = $flashMsg;
        header('Location: consultations.php');
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($connection);
        $_SESSION['consult_flash'] = 'Could not save consultation: ' . $e->getMessage();
        header('Location: consultation-new.php');
        exit;
    }
}

include 'header.php';
$suf = $ms ? ' WHERE p.facility_id = ' . (int) $fid . ' AND p.status = 1' : ' WHERE p.status = 1';
$editPatientId = ($editPatient !== null && isset($editPatient['id'])) ? (int) $editPatient['id'] : 0;
$prefPid = (int) ($_GET['patient_id'] ?? $editPatientId);

/* Patient list: only patients with at least one active booked appointment (status=1), when patient_id exists on tbl_appointment */
$patientList = [];
$hasApptPat = hms_db_column_exists($connection, 'tbl_appointment', 'patient_id');
$hasPatDept = hms_db_column_exists($connection, 'tbl_patient', 'department');
$hasApptFac = hms_db_column_exists($connection, 'tbl_appointment', 'facility_id');
$deptColSql = $hasPatDept ? ', p.department AS patient_department' : '';
$apptFacClause = ($ms && $hasApptFac) ? ' AND facility_id = ' . (int) $fid : '';

if ($hasApptPat) {
    /* Latest appointment per patient supplies department hint; INNER JOIN limits dropdown to patients with an appointment row */
    $pListSql = 'SELECT p.id, p.first_name, p.last_name, p.dob, p.gender' . $deptColSql . ',
        ad.department AS last_appt_department
        FROM tbl_patient p
        INNER JOIN (
            SELECT patient_id, MAX(id) AS max_id
            FROM tbl_appointment
            WHERE status IN (0, 1) AND patient_id IS NOT NULL AND patient_id > 0' . $apptFacClause . '
            GROUP BY patient_id
        ) apx ON apx.patient_id = p.id
        LEFT JOIN tbl_appointment ad ON ad.id = apx.max_id
        ' . $suf . '
        ORDER BY p.last_name, p.first_name LIMIT 600';
} else {
    $pListSql = 'SELECT p.id, p.first_name, p.last_name, p.dob, p.gender' . $deptColSql . ',
        NULL AS last_appt_department
        FROM tbl_patient p
        ' . $suf . '
        ORDER BY p.last_name, p.first_name LIMIT 600';
}

$pq = mysqli_query($connection, $pListSql);
if (!$pq && $hasApptPat) {
    /* Fallback: booked patients without relying on MAX(id) join to tbl_appointment */
    $pListSql = 'SELECT p.id, p.first_name, p.last_name, p.dob, p.gender' . $deptColSql . ', NULL AS last_appt_department
        FROM tbl_patient p
        INNER JOIN (
            SELECT DISTINCT patient_id FROM tbl_appointment
            WHERE status IN (0, 1) AND patient_id IS NOT NULL AND patient_id > 0' . $apptFacClause . '
        ) apx ON apx.patient_id = p.id
        ' . $suf . '
        ORDER BY p.last_name, p.first_name LIMIT 600';
    $pq = mysqli_query($connection, $pListSql);
}
if (!$pq) {
    $pListSql = 'SELECT p.id, p.first_name, p.last_name, p.dob, p.gender FROM tbl_patient p' . $suf . ' ORDER BY p.last_name, p.first_name LIMIT 600';
    $pq = mysqli_query($connection, $pListSql);
    $hasPatDept = false;
}
while ($pq && $pr = mysqli_fetch_assoc($pq)) {
    $ageY = hms_patient_age_years_from_dob((string) ($pr['dob'] ?? ''));
    $deptHint = '';
    if ($hasPatDept && trim((string) ($pr['patient_department'] ?? '')) !== '') {
        $deptHint = trim((string) $pr['patient_department']);
    } elseif (trim((string) ($pr['last_appt_department'] ?? '')) !== '') {
        $deptHint = trim((string) $pr['last_appt_department']);
    }
    $pr['department_hint'] = $deptHint;
    $pr['age_years'] = $ageY;
    $pr['age_display'] = $ageY !== null ? ((string) $ageY . ' Years') : '';
    $pr['gender_label'] = hms_patient_gender_label((string) ($pr['gender'] ?? ''));
    $patientList[] = $pr;
}

/* Client-side demographics (JSON survives Select2; script runs before jQuery otherwise misses events) */
$consultPatientMeta = [];
foreach ($patientList as $pl) {
    $pidKey = (string) (int) $pl['id'];
    $consultPatientMeta[$pidKey] = [
        'age' => ($pl['age_display'] ?? '') !== '' ? (string) $pl['age_display'] : '—',
        'gender' => ($pl['gender_label'] ?? '') !== '' ? (string) $pl['gender_label'] : '—',
        'dept' => (string) ($pl['department_hint'] ?? ''),
    ];
}

/* Consultation ID label */
$consultIdLabel = $editId > 0 ? '#C' . str_pad((string) $editId, 5, '0', STR_PAD_LEFT) : '#C—new';

/* Patient info for display (when editing) */
$displayPatient = $editPatient;
if (!$displayPatient && $prefPid > 0) {
    $ppq = mysqli_query($connection, 'SELECT id, first_name, last_name, dob, gender, phone FROM tbl_patient WHERE id = ' . (int) $prefPid . ' LIMIT 1');
    if ($ppq) {
        $displayPatient = mysqli_fetch_assoc($ppq);
    }
}

$patientAge = '';
$patientGender = '';
if ($displayPatient) {
    $ageY = hms_patient_age_years_from_dob((string) ($displayPatient['dob'] ?? ''));
    $patientAge = $ageY !== null ? ((string) $ageY . ' Years') : '';
    $patientGender = hms_patient_gender_label((string) ($displayPatient['gender'] ?? ''));
}

$prefDepartmentForSelect = '';
if ($displayPatient && !$editRow && function_exists('hms_patient_department_hint')) {
    $prefDepartmentForSelect = hms_patient_department_hint($connection, (int) $displayPatient['id'], $fid, $ms);
}

$vitalsBannerHtml = '';
if ($ok && function_exists('hms_vitals_fetch_latest') && hms_db_table_exists($connection, 'tbl_vital_sign')) {
    $pidForVitals = $prefPid;
    if ($pidForVitals < 1 && $displayPatient) {
        $pidForVitals = (int) ($displayPatient['id'] ?? 0);
    }
    if ($pidForVitals > 0) {
        $lv = hms_vitals_fetch_latest($connection, $pidForVitals, $fid, $ms);
        if ($lv !== null) {
            if (!$editRow) {
                $pf = hms_vitals_row_to_consult_prefill($lv);
                foreach ($pf as $k => $v) {
                    if (!isset($editObservations[$k]) || trim((string) ($editObservations[$k] ?? '')) === '') {
                        $editObservations[$k] = $v;
                    }
                }
            }
            $vitalsBannerHtml = hms_vitals_banner_html($lv);
        }
    }
}

$consultCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'consultation');
$consultCatalogJson = [];
foreach ($consultCatalogRows as $cr) {
    $consultCatalogJson[] = [
        'id' => (int) ($cr['id'] ?? 0),
        'name' => (string) ($cr['name'] ?? ''),
        'cpt_code' => (string) ($cr['cpt_code'] ?? ''),
        'price' => (float) ($cr['price'] ?? 0),
        'subcategory' => (string) ($cr['subcategory'] ?? ''),
    ];
}
$labCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'laboratory');
$radCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'radiology');
$canBillingWriteFlag = hms_can($connection, 'billing.write');
$canBillingOverrideFlag = hms_can($connection, 'consult.billing_override');
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-consult-form-page">
                <!-- Page Header -->
                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Consultation</h1>
                        <nav aria-label="breadcrumb" class="mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item"><a href="appointments.php">Appointments</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Consultation</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="no-print">
                        <a href="appointments.php" class="btn btn-link text-primary font-weight-bold"><i class="fa fa-arrow-left mr-1"></i> Back to Appointments</a>
                    </div>
                </div>

                <?php if (isset($_SESSION['consult_flash'])) {
                    echo '<div class="alert alert-info border-0 shadow-sm">' . hms_h((string) $_SESSION['consult_flash']) . '</div>';
                    unset($_SESSION['consult_flash']);
                } ?>

                <?php if (!$ok) { ?>
                <div class="alert alert-warning border-0 shadow-sm">Run migration <code>003_clinical_workflow.sql</code>.</div>
                <?php } else { ?>
                <?php if ($editId > 0 && !$editRow) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    No consultation found for <strong>#C<?php echo hms_h(str_pad((string) $editId, 5, '0', STR_PAD_LEFT)); ?></strong> at this site, or the record could not be loaded.
                    <a href="consultations.php" class="alert-link">Back to consultations</a>
                </div>
                <?php } ?>

                <form method="post" id="hmsConsultForm">
                    <?php echo hms_csrf_field(); ?>
                    <script type="application/json" id="hmsConsultPatientMeta"><?php echo json_encode($consultPatientMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>

                    <!-- Basic Information -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Basic Information</h5>
                            <?php if ($displayPatient) { ?>
                            <a href="patient-chart.php?id=<?php echo (int) $displayPatient['id']; ?>" class="text-primary font-weight-bold small">View Medical History <i class="fa fa-arrow-right ml-1"></i></a>
                            <?php } ?>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-4 mb-3 mb-md-0">
                                    <div class="d-flex align-items-center">
                                        <div class="hms-consult-patient-avatar mr-3" id="hmsConsultPatientAvatar" style="width:64px;height:64px;border-radius:8px;background:#e8ecf4;display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:bold;color:#1b2559;">
                                            <?php
                                            if ($displayPatient) {
                                                echo hms_h(strtoupper(substr((string) $displayPatient['first_name'], 0, 1) . substr((string) $displayPatient['last_name'], 0, 1)));
                                            } else {
                                                echo '?';
                                            }
                                            ?>
                                        </div>
                                        <div>
                                            <?php if (!$displayPatient) { ?>
                                            <select name="patient_id" class="form-control select" id="consultPatient" required style="width:220px">
                                                <option value="">Select Patient</option>
                                                <?php foreach ($patientList as $pl) {
                                                    $sel = ((int) $pl['id'] === $prefPid) ? ' selected' : '';
                                                    $ageDisp = ($pl['age_display'] ?? '') !== '' ? (string) $pl['age_display'] : '—';
                                                    $genDisp = ($pl['gender_label'] ?? '') !== '' ? (string) $pl['gender_label'] : '—';
                                                    $dptHint = (string) ($pl['department_hint'] ?? '');
                                                    echo '<option value="' . (int) $pl['id'] . '"' . $sel
                                                        . ' data-age-display="' . hms_h($ageDisp) . '"'
                                                        . ' data-gender="' . hms_h($genDisp) . '"'
                                                        . ' data-dept="' . hms_h($dptHint) . '">'
                                                        . hms_h($pl['first_name'] . ' ' . $pl['last_name']) . '</option>';
                                                } ?>
                                            </select>
                                            <?php } else { ?>
                                            <input type="hidden" name="patient_id" value="<?php echo (int) $displayPatient['id']; ?>">
                                            <span class="badge badge-info mb-1" style="font-size:.7rem">Out Patient</span>
                                            <div class="font-weight-bold text-dark" style="font-size:1.05rem"><?php echo hms_h($displayPatient['first_name'] . ' ' . $displayPatient['last_name']); ?></div>
                                            <div class="text-muted small">Consultation ID : <?php echo hms_h($consultIdLabel); ?></div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="row">
                                        <div class="col-md-4 mb-2">
                                            <div class="text-muted small font-weight-bold" style="color:#1b2559">Age / Gender</div>
                                            <div class="text-dark" id="hmsConsultAgeGender"><?php echo hms_h(($patientAge ?: '—') . ' / ' . ($patientGender ?: '—')); ?></div>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <div class="text-muted small font-weight-bold" style="color:#1b2559">Department</div>
                                            <div class="text-dark" id="hmsConsultDepartmentLabel"><?php echo hms_h($prefDepartmentForSelect !== '' ? $prefDepartmentForSelect : '—'); ?></div>
                                            <input type="hidden" name="department" id="hmsConsultDepartmentHidden" value="<?php echo hms_h($prefDepartmentForSelect); ?>">
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <div class="text-muted small font-weight-bold" style="color:#1b2559">Date</div>
                                            <div class="text-dark"><?php echo date('d M Y, h:i A'); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Consultation fee & access (payment before end of consult) -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Consultation fee &amp; access</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3 pb-3 border-bottom">The <strong>consultation fee must be paid at the cashier</strong> after registration or during the visit (cash, MoMo, Orange Money, card, insurance, etc.). The cashier issues a <strong>payment code</strong>; enter it here to confirm payment before ending the consultation. Exceptions: <strong>Emergency</strong> or a <strong>hospital-approved waiver</strong> only. If the catalog fee is zero, no payment is required. Lab and radiology charges may be settled separately at the cashier using additional codes.</p>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="custom-control custom-checkbox mt-1">
                                        <input type="checkbox" class="custom-control-input" id="consultEmergency" name="consult_emergency" value="1">
                                        <label class="custom-control-label" for="consultEmergency">Emergency (proceed; fee deferred / waived)</label>
                                    </div>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="small font-weight-bold text-dark" for="cashierReceiptRef">Cashier payment code</label>
                                    <input type="text" name="cashier_receipt_ref" id="cashierReceiptRef" class="form-control text-uppercase" placeholder="PAY-2026-… (issued after payment at cashier)" autocomplete="off" value="">
                                    <div id="hmsPrepayPeek" class="small mt-2 text-muted" style="display:none;"></div>
                                    <span class="small text-muted">Enter the code printed or given to the patient after payment at the cashier desk.</span>
                                </div>
                            </div>
                            <?php if ($canBillingOverrideFlag) { ?>
                            <div class="mb-3">
                                <label class="small font-weight-bold text-dark" for="billingExceptionNote">Hospital-approved billing exception</label>
                                <textarea name="billing_exception_note" id="billingExceptionNote" class="form-control" rows="2" placeholder="Supervisor: reason for waiving or deferring the consultation fee (requires consult.billing_override)"></textarea>
                            </div>
                            <?php } ?>
                            <?php if (!$canBillingWriteFlag) { ?>
                            <input type="hidden" name="consultation_type" value="general">
                            <input type="hidden" name="consult_fee_xaf" value="5000">
                            <p class="text-muted small mb-0">Enter the cashier payment code above (or emergency / supervisor waiver). Consultation type defaults to <strong>general</strong>.</p>
                            <?php } else { ?>
                            <p class="small text-muted mb-3">Consultation type and fee amounts follow the <a href="service-catalog.php">service catalog</a> for the record; payment is <strong>not</strong> collected here—only at the cashier before the visit.</p>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold text-dark" for="hmsConsultType">Consultation type</label>
                                    <select name="consultation_type" id="hmsConsultType" class="form-control">
                                        <option value="general">General</option>
                                        <option value="specialist">Specialist</option>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="small font-weight-bold text-dark" for="hmsConsultFeeCatalog">Catalog service <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <select name="consult_fee_catalog_id" id="hmsConsultFeeCatalog" class="form-control">
                                        <option value="0">— Auto (type + department, e.g. Cardiology) —</option>
                                        <?php foreach ($consultCatalogRows as $cr) {
                                            $cidOpt = (int) ($cr['id'] ?? 0);
                                            $nm = trim((string) ($cr['name'] ?? ''));
                                            $pr = number_format((float) ($cr['price'] ?? 0), 0, '.', ' ');
                                            ?>
                                        <option value="<?php echo $cidOpt; ?>"><?php echo hms_h($nm . ' — ' . $pr . ' FCFA'); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="small font-weight-bold text-dark" for="hmsConsultFeeXaf">Recorded fee (FCFA)</label>
                                    <input type="number" name="consult_fee_xaf" id="hmsConsultFeeXaf" class="form-control" min="0" step="1" value="" placeholder="Filled from catalog">
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <script type="application/json" id="hmsConsultCatalogJson"><?php echo json_encode($consultCatalogJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>
                    <script type="application/json" id="hmsConsultGateRules"><?php echo json_encode(['hasBillingWrite' => $canBillingWriteFlag, 'billingOverride' => $canBillingOverrideFlag], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?></script>

                    <?php if ($ok) { ?>
                    <div class="alert alert-info border-0 shadow-sm mb-3" id="hmsVitalsTriageBanner" style="<?php echo $vitalsBannerHtml === '' ? 'display:none;' : ''; ?>">
                        <?php echo $vitalsBannerHtml; ?>
                    </div>
                    <?php } ?>

                    <!-- Vitals -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Vitals</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Temperature <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.1" name="vital_temperature" class="form-control" placeholder="" value="<?php echo hms_h((string) ($editObservations['temperature'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">°F</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Pulse <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="vital_pulse" class="form-control" value="<?php echo hms_h((string) ($editObservations['pulse'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">mmHg</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Respiratory Rate <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="vital_respiratory_rate" class="form-control" value="<?php echo hms_h((string) ($editObservations['respiratory_rate'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">rpm</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#e74c3c">SPO2 <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.1" name="vital_spo2" class="form-control" value="<?php echo hms_h((string) ($editObservations['spo2'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">%</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Height <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.1" name="vital_height" class="form-control" id="vitalHeight" value="<?php echo hms_h((string) ($editObservations['height'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">cm</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Weight <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.1" name="vital_weight" class="form-control" id="vitalWeight" value="<?php echo hms_h((string) ($editObservations['weight'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">Kg</span></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#e74c3c">BMI <span class="text-muted font-weight-normal">(calculated)</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="vital_bmi" class="form-control" id="vitalBmi" value="<?php echo hms_h((string) ($editObservations['bmi'] ?? '')); ?>" readonly>
                                        <div class="input-group-append"><span class="input-group-text">kg/m²</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">Waist <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.1" name="vital_waist" class="form-control" value="<?php echo hms_h((string) ($editObservations['waist'] ?? '')); ?>">
                                        <div class="input-group-append"><span class="input-group-text">cm</span></div>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold small" style="color:#1b2559">BSA <span class="text-muted font-weight-normal">(calculated)</span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.01" name="vital_bsa" class="form-control" id="vitalBsa" value="<?php echo hms_h((string) ($editObservations['bsa'] ?? '')); ?>" readonly>
                                        <div class="input-group-append"><span class="input-group-text">m²</span></div>
                                    </div>
                                </div>
                            </div>
                            <a href="javascript:void(0)" class="text-primary font-weight-bold small" id="hmsAddVitalRow"><i class="fa fa-plus mr-1"></i> Add New</a>
                        </div>
                    </div>

                    <!-- Complaint -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Complaint</h5>
                        </div>
                        <div class="card-body">
                            <textarea name="chief_complaint" class="form-control border-0" rows="2" placeholder="Enter value separated by comma" style="background:#f8f9fd"><?php echo hms_h((string) (($editRow ?? [])['chief_complaint'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <!-- Laboratory (service catalog) -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Laboratory</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">Select tests from the <a href="service-catalog.php">service catalog</a> (category: laboratory). Tick <strong>External</strong> if the patient will use another laboratory — no hospital cashier charge for that line; upload results later on the patient chart.</p>
                            <?php if ($labCatalogRows === []) { ?>
                            <p class="text-muted small mb-0">No laboratory services in the catalog for this site. Add them under Service catalog.</p>
                            <?php } else { ?>
                            <div id="hmsLabContainer">
                                <div class="hms-lab-row mb-2 d-flex align-items-center flex-wrap">
                                    <select name="lab_catalog_id[]" class="form-control mr-2 mb-2" style="max-width:100%;min-width:220px">
                                        <option value="0">— Select lab test —</option>
                                        <?php foreach ($labCatalogRows as $lr) {
                                            $lid = (int) ($lr['id'] ?? 0);
                                            $ln = trim((string) ($lr['name'] ?? ''));
                                            $lp = number_format((float) ($lr['price'] ?? 0), 0, '.', ' ');
                                            ?>
                                        <option value="<?php echo $lid; ?>"><?php echo hms_h($ln . ' — ' . $lp . ' FCFA'); ?></option>
                                        <?php } ?>
                                    </select>
                                    <input type="hidden" name="lab_external[]" value="0" class="hms-lab-ext-hidden">
                                    <label class="small mb-2 ml-1 d-flex align-items-center"><input type="checkbox" class="hms-lab-ext-cb mr-1" title="Patient obtains this test outside the hospital"> External</label>
                                </div>
                            </div>
                            <a href="javascript:void(0)" class="text-primary font-weight-bold small" id="hmsAddLabRow"><i class="fa fa-plus mr-1"></i> Add test</a>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Radiology (service catalog) -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Radiology</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">Select imaging from the <a href="service-catalog.php">service catalog</a> (category: radiology). Tick <strong>External</strong> if the exam will be done outside this hospital.</p>
                            <?php if ($radCatalogRows === []) { ?>
                            <p class="text-muted small mb-0">No radiology services in the catalog for this site. Add them under Service catalog.</p>
                            <?php } else { ?>
                            <div id="hmsRadContainer">
                                <div class="hms-rad-row mb-2 d-flex align-items-center flex-wrap">
                                    <select name="rad_catalog_id[]" class="form-control mr-2 mb-2" style="max-width:100%;min-width:220px">
                                        <option value="0">— Select imaging / radiology —</option>
                                        <?php foreach ($radCatalogRows as $rr) {
                                            $rid = (int) ($rr['id'] ?? 0);
                                            $rn = trim((string) ($rr['name'] ?? ''));
                                            $rp = number_format((float) ($rr['price'] ?? 0), 0, '.', ' ');
                                            ?>
                                        <option value="<?php echo $rid; ?>"><?php echo hms_h($rn . ' — ' . $rp . ' FCFA'); ?></option>
                                        <?php } ?>
                                    </select>
                                    <input type="hidden" name="rad_external[]" value="0" class="hms-rad-ext-hidden">
                                    <label class="small mb-2 ml-1 d-flex align-items-center"><input type="checkbox" class="hms-rad-ext-cb mr-1" title="Patient obtains this study outside the hospital"> External</label>
                                </div>
                            </div>
                            <a href="javascript:void(0)" class="text-primary font-weight-bold small" id="hmsAddRadRow"><i class="fa fa-plus mr-1"></i> Add study</a>
                            <?php } ?>
                        </div>
                    </div>

                    <!-- Medications -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Medications</h5>
                        </div>
                        <div class="card-body" id="hmsMedContainer">
                            <div class="hms-med-row mb-3">
                                <div class="row">
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#1b2559">Medicine Name <span class="text-danger">*</span></label>
                                        <input type="text" name="med_name[]" class="form-control">
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#1b2559">Dosage <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" name="med_dosage[]" class="form-control">
                                            <div class="input-group-append"><span class="input-group-text">mg</span></div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#1b2559">Duration <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" name="med_duration[]" class="form-control">
                                            <div class="input-group-append"><span class="input-group-text">M</span></div>
                                        </div>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#e74c3c">Frequency <span class="text-danger">*</span></label>
                                        <select name="med_frequency[]" class="form-control">
                                            <option value="">Select</option>
                                            <option value="Once daily">Once daily</option>
                                            <option value="Twice daily">Twice daily</option>
                                            <option value="Three times daily">Three times daily</option>
                                            <option value="Four times daily">Four times daily</option>
                                            <option value="Every 6 hours">Every 6 hours</option>
                                            <option value="Every 8 hours">Every 8 hours</option>
                                            <option value="As needed">As needed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#e74c3c">Timing <span class="text-danger">*</span></label>
                                        <select name="med_timing[]" class="form-control">
                                            <option value="">Select</option>
                                            <option value="Before meals">Before meals</option>
                                            <option value="After meals">After meals</option>
                                            <option value="With meals">With meals</option>
                                            <option value="Morning">Morning</option>
                                            <option value="Evening">Evening</option>
                                            <option value="Bedtime">Bedtime</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="font-weight-bold small" style="color:#1b2559">Instructions <span class="text-danger">*</span></label>
                                        <input type="text" name="med_instructions[]" class="form-control">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12 mb-1">
                                        <input type="hidden" name="med_external[]" value="0" class="hms-med-ext-hidden">
                                        <label class="small mb-0 text-muted"><input type="checkbox" class="hms-med-ext-cb mr-1"> Patient will purchase this medication outside the hospital pharmacy</label>
                                    </div>
                                </div>
                            </div>
                            <a href="javascript:void(0)" class="text-primary font-weight-bold small" id="hmsAddMedRow"><i class="fa fa-plus mr-1"></i> Add New</a>
                        </div>
                    </div>

                    <!-- Investigations & Procedure -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Investigations &amp; Procedure</h5>
                        </div>
                        <div class="card-body" id="hmsInvestContainer">
                            <input type="text" name="investigations" class="form-control border-0 mb-2" placeholder="Enter investigation or procedure" style="background:#f8f9fd" value="<?php echo hms_h((string) ($editObservations['investigations'] ?? '')); ?>">
                            <a href="javascript:void(0)" class="text-primary font-weight-bold small" id="hmsAddInvestRow"><i class="fa fa-plus mr-1"></i> Add New</a>
                        </div>
                    </div>

                    <!-- Advice -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Advice</h5>
                        </div>
                        <div class="card-body">
                            <textarea name="advice" class="form-control border-0" rows="2" placeholder="Enter advice for the patient" style="background:#f8f9fd"><?php echo hms_h((string) ($editObservations['advice'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <!-- Follow Up -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3" style="border-bottom:2px solid #1b2559">
                            <h5 class="font-weight-bold mb-0" style="color:#1b2559">Follow Up</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="text-dark">Next Consultation</label>
                                    <select name="next_consultation" class="form-control">
                                        <option value="">Select</option>
                                        <option value="1 Week">1 Week</option>
                                        <option value="2 Weeks">2 Weeks</option>
                                        <option value="3 Weeks">3 Weeks</option>
                                        <option value="1 Month">1 Month</option>
                                        <option value="2 Months">2 Months</option>
                                        <option value="3 Months">3 Months</option>
                                        <option value="6 Months">6 Months</option>
                                        <option value="1 Year">1 Year</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="text-dark">Whether to Come on Empty Stomach?</label>
                                    <select name="empty_stomach" class="form-control">
                                        <option value="">Select</option>
                                        <option value="Yes">Yes</option>
                                        <option value="No">No</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Actions -->
                    <div class="d-flex justify-content-end mb-4">
                        <a href="consultations.php" class="btn btn-outline-secondary mr-2 px-4">Cancel</a>
                        <button type="submit" name="create_consultation" value="1" class="btn btn-primary px-4 font-weight-bold">End Consultation</button>
                    </div>
                </form>

                <?php } ?>
            </div>
        </div>

        <script>
        (function () {
            /* Auto-calculate BMI and BSA */
            function calcBmiBsa() {
                var h = parseFloat(document.getElementById('vitalHeight') ? document.getElementById('vitalHeight').value : 0);
                var w = parseFloat(document.getElementById('vitalWeight') ? document.getElementById('vitalWeight').value : 0);
                var bmiEl = document.getElementById('vitalBmi');
                var bsaEl = document.getElementById('vitalBsa');
                if (h > 0 && w > 0) {
                    var hm = h / 100;
                    if (bmiEl) bmiEl.value = (w / (hm * hm)).toFixed(2);
                    /* Du Bois formula */
                    if (bsaEl) bsaEl.value = (0.007184 * Math.pow(h, 0.725) * Math.pow(w, 0.425)).toFixed(2);
                } else {
                    if (bmiEl) bmiEl.value = '';
                    if (bsaEl) bsaEl.value = '';
                }
            }
            var hEl = document.getElementById('vitalHeight');
            var wEl = document.getElementById('vitalWeight');
            if (hEl) hEl.addEventListener('input', calcBmiBsa);
            if (wEl) wEl.addEventListener('input', calcBmiBsa);
            calcBmiBsa();

            /* Add medication row */
            var medContainer = document.getElementById('hmsMedContainer');
            var addMedBtn = document.getElementById('hmsAddMedRow');
            if (addMedBtn && medContainer) {
                addMedBtn.addEventListener('click', function () {
                    var firstRow = medContainer.querySelector('.hms-med-row');
                    if (!firstRow) return;
                    var clone = firstRow.cloneNode(true);
                    /* Clear values */
                    clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                    /* Add delete button */
                    var delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-link text-danger p-0 hms-med-del-btn';
                    delBtn.style.cssText = 'position:absolute;top:0;right:8px;font-size:1.2rem;z-index:2';
                    delBtn.innerHTML = '<i class="fa fa-times-circle"></i>';
                    delBtn.onclick = function () { clone.remove(); };
                    clone.style.position = 'relative';
                    clone.appendChild(delBtn);
                    medContainer.insertBefore(clone, addMedBtn);
                });
            }

            /* Lab / radiology catalog rows */
            function hmsBindCatalogRowAdd(containerId, rowClass, addBtnId) {
                var c = document.getElementById(containerId);
                var addBtn = document.getElementById(addBtnId);
                if (!c || !addBtn) return;
                addBtn.addEventListener('click', function () {
                    var first = c.querySelector('.' + rowClass);
                    if (!first) return;
                    var clone = first.cloneNode(true);
                    clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
                    var delBtn = document.createElement('button');
                    delBtn.type = 'button';
                    delBtn.className = 'btn btn-link text-danger p-0 ml-1 mb-2';
                    delBtn.innerHTML = '<i class="fa fa-times-circle"></i>';
                    delBtn.onclick = function () { clone.remove(); };
                    clone.appendChild(delBtn);
                    c.appendChild(clone);
                });
            }
            hmsBindCatalogRowAdd('hmsLabContainer', 'hms-lab-row', 'hmsAddLabRow');
            hmsBindCatalogRowAdd('hmsRadContainer', 'hms-rad-row', 'hmsAddRadRow');

            function hmsDelegateExtToggle(containerId, chkClass, hidClass) {
                var c = document.getElementById(containerId);
                if (!c) return;
                c.addEventListener('change', function (e) {
                    var t = e.target;
                    if (!t || !t.classList || !t.classList.contains(chkClass)) return;
                    var row = t.closest('.hms-lab-row, .hms-rad-row, .hms-med-row');
                    var hid = row && row.querySelector('.' + hidClass);
                    if (hid) hid.value = t.checked ? '1' : '0';
                });
            }
            hmsDelegateExtToggle('hmsLabContainer', 'hms-lab-ext-cb', 'hms-lab-ext-hidden');
            hmsDelegateExtToggle('hmsRadContainer', 'hms-rad-ext-cb', 'hms-rad-ext-hidden');
            hmsDelegateExtToggle('hmsMedContainer', 'hms-med-ext-cb', 'hms-med-ext-hidden');

            /* Add investigation row */
            var investContainer = document.getElementById('hmsInvestContainer');
            var addInvestBtn = document.getElementById('hmsAddInvestRow');
            if (addInvestBtn && investContainer) {
                addInvestBtn.addEventListener('click', function () {
                    var wrap = document.createElement('div');
                    wrap.className = 'd-flex align-items-center mb-2';
                    var inp = document.createElement('input');
                    inp.type = 'text';
                    inp.name = 'investigations_extra[]';
                    inp.className = 'form-control border-0 mr-2';
                    inp.placeholder = 'Enter investigation or procedure';
                    inp.style.background = '#f8f9fd';
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'btn btn-link text-danger p-0';
                    del.innerHTML = '<i class="fa fa-times-circle"></i>';
                    del.onclick = function () { wrap.remove(); };
                    wrap.appendChild(inp);
                    wrap.appendChild(del);
                    investContainer.insertBefore(wrap, addInvestBtn);
                });
            }

            function hmsConsultApplyPatientFromSelect() {
                var sel = document.getElementById('consultPatient');
                var ageG = document.getElementById('hmsConsultAgeGender');
                var deptLbl = document.getElementById('hmsConsultDepartmentLabel');
                var deptHid = document.getElementById('hmsConsultDepartmentHidden');
                var av = document.getElementById('hmsConsultPatientAvatar');
                if (!sel || !ageG) return;
                var opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) {
                    ageG.textContent = '— / —';
                    if (deptLbl) deptLbl.textContent = '—';
                    if (deptHid) deptHid.value = '';
                    if (av) av.textContent = '?';
                    var ban0 = document.getElementById('hmsVitalsTriageBanner');
                    if (ban0) {
                        ban0.innerHTML = '';
                        ban0.style.display = 'none';
                    }
                    return;
                }
                var vid = String(opt.value);
                var a = '—';
                var g = '—';
                var d = '';
                var metaEl = document.getElementById('hmsConsultPatientMeta');
                var meta = null;
                if (metaEl && metaEl.textContent) {
                    try {
                        meta = JSON.parse(metaEl.textContent);
                    } catch (eMeta) {
                        meta = null;
                    }
                }
                if (meta && meta[vid]) {
                    a = meta[vid].age != null && meta[vid].age !== '' ? String(meta[vid].age) : '—';
                    g = meta[vid].gender != null && meta[vid].gender !== '' ? String(meta[vid].gender) : '—';
                    d = meta[vid].dept != null ? String(meta[vid].dept) : '';
                } else {
                    a = opt.getAttribute('data-age-display') || '—';
                    g = opt.getAttribute('data-gender') || '—';
                    d = opt.getAttribute('data-dept') || '';
                }
                ageG.textContent = a + ' / ' + g;
                var dTrim = d.replace(/^\s+|\s+$/g, '');
                if (deptLbl) deptLbl.textContent = dTrim !== '' ? dTrim : '—';
                if (deptHid) deptHid.value = dTrim;
                if (av) {
                    var t = (opt.text || '').trim();
                    if (typeof jQuery !== 'undefined' && jQuery(sel).data('select2')) {
                        var $so = jQuery(sel).find('option:selected');
                        if ($so.length) t = ($so.text() || '').trim();
                    }
                    if (!t) {
                        av.textContent = '?';
                    } else {
                        var parts = t.split(/\s+/);
                        var ini = ((parts[0] || '').charAt(0) || '') + ((parts[parts.length - 1] || '').charAt(0) || '');
                        av.textContent = ini.toUpperCase();
                    }
                }
                var ban = document.getElementById('hmsVitalsTriageBanner');
                var pid = opt.value;
                if (ban) {
                    if (!pid) {
                        ban.innerHTML = '';
                        ban.style.display = 'none';
                    } else if (window.fetch) {
                        fetch('vitals-latest.php?patient_id=' + encodeURIComponent(pid), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data || !data.ok) return;
                                if (data.bannerHtml) {
                                    ban.innerHTML = data.bannerHtml;
                                    ban.style.display = '';
                                } else {
                                    ban.innerHTML = '';
                                    ban.style.display = 'none';
                                }
                                if (data.prefill) {
                                    Object.keys(data.prefill).forEach(function (k) {
                                        var el = document.querySelector('[name="vital_' + k + '"]');
                                        if (el) el.value = data.prefill[k];
                                    });
                                    /* Recalculate BMI/BSA after height/weight from triage */
                                    var hIn = document.getElementById('vitalHeight');
                                    var wIn = document.getElementById('vitalWeight');
                                    if (hIn) hIn.dispatchEvent(new Event('input', { bubbles: true }));
                                    if (wIn) wIn.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            })
                            .catch(function () {});
                    }
                }
            }

            /* After footer: jQuery + app.js init Select2 on .select — bind on window load so change/select2:select fire */
            window.addEventListener('load', function () {
                var sel = document.getElementById('consultPatient');
                if (!sel) return;
                var fn = hmsConsultApplyPatientFromSelect;
                if (typeof jQuery !== 'undefined') {
                    jQuery(sel).on('change.hmsConsultPat select2:select.hmsConsultPat', fn);
                } else {
                    sel.addEventListener('change', fn);
                }
                fn();
            });

            /* Consultation fee from catalog (mirrors server-side resolver) */
            (function () {
                var feeEl = document.getElementById('hmsConsultFeeXaf');
                var typeEl = document.getElementById('hmsConsultType');
                var catEl = document.getElementById('hmsConsultFeeCatalog');
                if (!feeEl || !typeEl || !catEl) return;
                var catJsonEl = document.getElementById('hmsConsultCatalogJson');
                var rows = [];
                if (catJsonEl && catJsonEl.textContent) {
                    try { rows = JSON.parse(catJsonEl.textContent); } catch (e) { rows = []; }
                }
                function defaultFee(ctype) {
                    return ctype === 'specialist' ? 10000 : 5000;
                }
                function resolveFee() {
                    var ctype = (typeEl.value || 'general') === 'specialist' ? 'specialist' : 'general';
                    var pick = parseInt(String(catEl.value || '0'), 10) || 0;
                    var deptH = document.getElementById('hmsConsultDepartmentHidden');
                    var dept = (deptH && deptH.value) ? String(deptH.value).toLowerCase().trim() : '';
                    var i, r, sub, nm, code, wantSpec;
                    if (pick > 0) {
                        for (i = 0; i < rows.length; i++) {
                            if (rows[i].id === pick) {
                                r = rows[i];
                                return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                            }
                        }
                    }
                    if (rows.length) {
                        if (dept) {
                            for (i = 0; i < rows.length; i++) {
                                r = rows[i];
                                sub = String(r.subcategory || '').toLowerCase().trim();
                                nm = String(r.name || '').toLowerCase().trim();
                                if (sub && (sub.indexOf(dept) >= 0 || dept.indexOf(sub) >= 0)) {
                                    return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                                }
                                if (nm && nm.indexOf(dept) >= 0) {
                                    return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                                }
                            }
                        }
                        wantSpec = ctype === 'specialist';
                        for (i = 0; i < rows.length; i++) {
                            r = rows[i];
                            code = String(r.cpt_code || '').toUpperCase().trim();
                            if (wantSpec && (code.indexOf('SPEC') >= 0 || code.indexOf('SPCL') >= 0)) {
                                return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                            }
                            if (!wantSpec && (code.indexOf('GEN') >= 0 || code === 'CONSULT' || code === 'CONSULT_GENERAL')) {
                                return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                            }
                        }
                        r = rows[0];
                        return Math.max(0, Math.round(parseFloat(String(r.price)) || 0));
                    }
                    return defaultFee(ctype);
                }
                function refreshFee() {
                    feeEl.value = String(resolveFee());
                }
                typeEl.addEventListener('change', refreshFee);
                catEl.addEventListener('change', refreshFee);
                window.addEventListener('load', function () {
                    var sel = document.getElementById('consultPatient');
                    if (sel) {
                        if (typeof jQuery !== 'undefined') {
                            jQuery(sel).on('change.hmsConsultFee select2:select.hmsConsultFee', refreshFee);
                        } else {
                            sel.addEventListener('change', refreshFee);
                        }
                    }
                    refreshFee();
                });
            })();

            /* Prepay code peek (optional) */
            (function () {
                var consultForm = document.getElementById('hmsConsultForm');
                var refEl = document.getElementById('cashierReceiptRef');
                var peekEl = document.getElementById('hmsPrepayPeek');
                if (!consultForm || !refEl || !peekEl || !window.fetch) return;
                function patientIdForPeek() {
                    var hid = consultForm.querySelector('input[name="patient_id"]');
                    if (hid && hid.value) return hid.value;
                    var sel = document.getElementById('consultPatient');
                    return sel && sel.value ? sel.value : '';
                }
                function runPeek() {
                    var pid = patientIdForPeek();
                    var code = String(refEl.value || '').replace(/^\s+|\s+$/g, '');
                    if (!pid || code.length < 6) {
                        peekEl.style.display = 'none';
                        peekEl.textContent = '';
                        return;
                    }
                    fetch('consult-prepay-peek.php?patient_id=' + encodeURIComponent(pid) + '&code=' + encodeURIComponent(code), { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d || !d.ok) {
                                peekEl.style.display = '';
                                peekEl.className = 'small mt-2 text-danger';
                                peekEl.textContent = (d && d.error) ? d.error : 'Code could not be verified.';
                                return;
                            }
                            peekEl.style.display = '';
                            peekEl.className = 'small mt-2 text-success';
                            peekEl.textContent = 'Paid at cashier: ' + (d.total_fmt || '') + ' FCFA — ' + (d.summary || '');
                        })
                        .catch(function () { peekEl.style.display = 'none'; });
                }
                refEl.addEventListener('blur', runPeek);
            })();

            /* Mirror server gate: cashier code, emergency, or supervisor exception */
            var consultForm = document.getElementById('hmsConsultForm');
            if (consultForm) {
                consultForm.addEventListener('submit', function (e) {
                    var rulesEl = document.getElementById('hmsConsultGateRules');
                    var rules = { hasBillingWrite: false, billingOverride: false };
                    if (rulesEl && rulesEl.textContent) {
                        try { rules = JSON.parse(rulesEl.textContent); } catch (eR) { rules = { hasBillingWrite: false, billingOverride: false }; }
                    }
                    function consultFeeNum() {
                        var fe = document.getElementById('hmsConsultFeeXaf');
                        if (fe) return parseInt(String(fe.value || '0'), 10) || 0;
                        var he = consultForm.querySelector('input[name="consult_fee_xaf"]');
                        return he ? (parseInt(String(he.value || '0'), 10) || 0) : 0;
                    }
                    var fee = consultFeeNum();
                    var emergEl = document.getElementById('consultEmergency');
                    var emerg = emergEl && emergEl.checked;
                    var receiptEl = document.getElementById('cashierReceiptRef');
                    var receipt = receiptEl ? String(receiptEl.value || '').replace(/^\s+|\s+$/g, '') : '';
                    var excEl = document.getElementById('billingExceptionNote');
                    var exc = excEl ? String(excEl.value || '').replace(/^\s+|\s+$/g, '') : '';
                    var gate = fee <= 0;
                    if (emerg) gate = true;
                    if (receipt !== '') gate = true;
                    if (exc !== '' && rules.billingOverride) gate = true;
                    if (!gate) {
                        e.preventDefault();
                        window.alert('Consultation cannot be completed until the patient has paid the consultation fee at the cashier: enter the payment code issued by the cashier, or mark Emergency, or (supervisor) a hospital-approved waiver.');
                        return false;
                    }
                });
            }
        })();
        </script>
<?php include 'footer.php'; ?>
