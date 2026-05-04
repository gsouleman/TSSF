<?php
declare(strict_types=1);

/**
 * Clinical chart aggregates — consultations, prescribed tests, results.
 */

/**
 * Front Desk (3), Nurse (7), Nursing Aid (8): show only insurance, consultations, and vitals on the chart.
 * Admins (1) and doctors (2) always see the full chart.
 */
function hms_patient_chart_limited_station_view(): bool
{
    $r = (string) ($_SESSION['role'] ?? '');
    if ($r === '1' || $r === '2') {
        return false;
    }

    return in_array($r, ['3', '7', '8'], true);
}

/**
 * Open patient-chart.php if the user has full clinical access, or is station staff (3/7/8) with patient.read.
 */
function hms_patient_chart_access_allowed(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return !empty($_SESSION['name']);
    }
    if (hms_can($connection, 'clinical.read')) {
        return true;
    }
    if (!hms_patient_chart_limited_station_view()) {
        return false;
    }

    return hms_can($connection, 'patient.read');
}

function hms_require_patient_chart_access(mysqli $connection): void
{
    if (!hms_patient_chart_access_allowed($connection)) {
        http_response_code(403);
        exit('Forbidden: you do not have access to the clinical chart.');
    }
}

/**
 * Edit primary insurance on the chart: full patient editors, or Front Desk / Nurse / Nursing Aid in station view.
 */
function hms_patient_chart_can_edit_insurance(mysqli $connection): bool
{
    if (hms_can($connection, 'patient.write')) {
        return true;
    }
    if (!hms_patient_chart_limited_station_view()) {
        return false;
    }
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return !empty($_SESSION['name']);
    }

    return hms_can($connection, 'patient.read');
}

/**
 * @return list<array<string,mixed>>
 */
function hms_clinical_chart_consultations(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_consultation') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND c.facility_id = ' . (int) $facilityId : '';
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT c.id, c.created_at, c.status, c.chief_complaint, c.consultation_type, c.consult_fee_xaf,
                e.first_name AS doc_first, e.last_name AS doc_last
         FROM tbl_consultation c
         LEFT JOIN tbl_employee e ON e.id = c.created_by
         WHERE c.patient_id = ' . (int) $patientId . $suf . '
         ORDER BY c.id DESC LIMIT 25'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return list<array{consultation_id:int, created_at:string, labs:list<array<string,mixed>>, rads:list<array<string,mixed>>}>
 */
function hms_clinical_chart_prescribed_from_consultations(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_consult_observation') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND c.facility_id = ' . (int) $facilityId : '';
    $q = mysqli_query(
        $connection,
        'SELECT c.id AS consultation_id, c.created_at, o.param_code, o.value_text
         FROM tbl_consultation c
         INNER JOIN tbl_consult_observation o ON o.consultation_id = c.id
         WHERE c.patient_id = ' . (int) $patientId . $suf . "
         AND o.param_code IN ('prescribed_lab_json','prescribed_radiology_json')
         ORDER BY c.id DESC"
    );
    $byC = [];
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $cid = (int) ($r['consultation_id'] ?? 0);
        if (!isset($byC[$cid])) {
            $byC[$cid] = [
                'consultation_id' => $cid,
                'created_at' => (string) ($r['created_at'] ?? ''),
                'labs' => [],
                'rads' => [],
            ];
        }
        $pc = (string) ($r['param_code'] ?? '');
        $raw = (string) ($r['value_text'] ?? '');
        $pj = json_decode($raw, true);
        if (!is_array($pj)) {
            continue;
        }
        if ($pc === 'prescribed_lab_json') {
            foreach ($pj as $item) {
                if (is_array($item)) {
                    $byC[$cid]['labs'][] = $item;
                }
            }
        } elseif ($pc === 'prescribed_radiology_json') {
            foreach ($pj as $item) {
                if (is_array($item)) {
                    $byC[$cid]['rads'][] = $item;
                }
            }
        }
    }

    return array_values($byC);
}

/**
 * Medication lines stored on consultations as medications_json observations.
 *
 * @return list<array{consultation_id:int, created_at:string, meds:list<array<string,mixed>>}>
 */
function hms_clinical_chart_medications_from_consultations(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_consult_observation') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND c.facility_id = ' . (int) $facilityId : '';
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT c.id AS consultation_id, c.created_at, o.value_text
         FROM tbl_consultation c
         INNER JOIN tbl_consult_observation o ON o.consultation_id = c.id
         WHERE c.patient_id = ' . (int) $patientId . $suf . "
         AND o.param_code = 'medications_json'
         ORDER BY c.id DESC"
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $raw = (string) ($r['value_text'] ?? '');
        $pj = json_decode($raw, true);
        $meds = [];
        if (is_array($pj)) {
            foreach ($pj as $item) {
                if (is_array($item)) {
                    $meds[] = $item;
                }
            }
        }
        $rows[] = [
            'consultation_id' => (int) ($r['consultation_id'] ?? 0),
            'created_at' => (string) ($r['created_at'] ?? ''),
            'meds' => $meds,
        ];
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hms_clinical_chart_lab_results(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_db_table_exists($connection, 'tbl_lab_result') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND lr.facility_id = ' . (int) $facilityId : '';
    $extra = '';
    if (hms_db_column_exists($connection, 'tbl_lab_result', 'result_template_json')) {
        $extra .= ', lr.result_template_json';
    }
    if (hms_db_column_exists($connection, 'tbl_lab_result', 'conclusion_code')) {
        $extra .= ', lr.conclusion_code';
    }
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT lr.id, lr.test_name, lr.status, lr.appointment_date, lr.notes, lr.created_at' . $extra . '
         FROM tbl_lab_result lr
         WHERE lr.patient_id = ' . (int) $patientId . $suf . '
         ORDER BY lr.id DESC LIMIT 40'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hms_clinical_chart_radiology_results(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_db_table_exists($connection, 'tbl_radiology_result') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND rr.facility_id = ' . (int) $facilityId : '';
    $extra = '';
    if (hms_db_column_exists($connection, 'tbl_radiology_result', 'result_template_json')) {
        $extra .= ', rr.result_template_json';
    }
    if (hms_db_column_exists($connection, 'tbl_radiology_result', 'conclusion_code')) {
        $extra .= ', rr.conclusion_code';
    }
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT rr.id, rr.exam_name, rr.modality, rr.status, rr.appointment_date, rr.findings, rr.notes, rr.created_at' . $extra . '
         FROM tbl_radiology_result rr
         WHERE rr.patient_id = ' . (int) $patientId . $suf . '
         ORDER BY rr.id DESC LIMIT 40'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hms_clinical_chart_prescriptions_summary(mysqli $connection, int $facilityId, int $patientId, bool $multiSite): array
{
    if (!hms_workflow_table_ok($connection, 'tbl_prescription') || $patientId < 1) {
        return [];
    }
    $suf = $multiSite ? ' AND r.facility_id = ' . (int) $facilityId : '';
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT r.id, r.title, r.status, r.created_at, r.consultation_id,
                e.first_name AS doc_first, e.last_name AS doc_last
         FROM tbl_prescription r
         LEFT JOIN tbl_employee e ON e.id = r.prescriber_employee_id
         WHERE r.patient_id = ' . (int) $patientId . $suf . '
         ORDER BY r.id DESC LIMIT 20'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * Fuzzy match: does a result row correspond to a prescribed catalog name?
 */
function hms_clinical_chart_result_matches_prescribed(string $resultName, string $prescribedName): bool
{
    $a = mb_strtolower(trim($resultName));
    $b = mb_strtolower(trim($prescribedName));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
        return true;
    }

    return false;
}
