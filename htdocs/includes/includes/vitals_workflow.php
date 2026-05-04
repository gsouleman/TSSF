<?php
declare(strict_types=1);

/**
 * Shared vitals workflow: triage at front desk / nursing → visible at consultation.
 */

function hms_vitals_can_record(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'patient.write')
        || hms_can($connection, 'nursing.write')
        || hms_can($connection, 'opd.write')
        || hms_can($connection, 'clinical.write')
        || (string) ($_SESSION['role'] ?? '') === '1';
}

function hms_vitals_has_recorder_columns(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_vital_sign', 'recorded_by')
        && hms_db_column_exists($connection, 'tbl_vital_sign', 'source_station');
}

function hms_vitals_has_anthropometrics(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_vital_sign')
        && hms_db_column_exists($connection, 'tbl_vital_sign', 'weight_kg')
        && hms_db_column_exists($connection, 'tbl_vital_sign', 'height_cm')
        && hms_db_column_exists($connection, 'tbl_vital_sign', 'waist_cm');
}

/**
 * Optional numeric field from a form value; blank or invalid → null.
 *
 * @param mixed $raw
 */
function hms_vitals_optional_measurement_raw($raw): ?float
{
    if ($raw === null) {
        return null;
    }
    if (is_array($raw) || is_object($raw)) {
        return null;
    }
    $v = trim((string) $raw);
    if ($v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }

    return (float) $v;
}

/**
 * Nullable DECIMAL bind via string (works with SQL NULL for mysqli across PHP versions).
 */
function hms_vitals_decimal_bind(?float $f): ?string
{
    if ($f === null) {
        return null;
    }

    return (string) $f;
}

/**
 * Insert one vitals row (uses recorder / anthropometric columns when present in DB).
 *
 * @param array{
 *   patient_id:int,
 *   facility_id:int,
 *   bp_sys:int,
 *   bp_dia:int,
 *   heart_rate:int,
 *   temp_c:float,
 *   spo2:int,
 *   rr:int,
 *   weight_kg:?float,
 *   height_cm:?float,
 *   waist_cm:?float,
 *   recorded_by:?int,
 *   source_station:?string
 * } $v
 */
function hms_vitals_insert_row(mysqli $connection, array $v): bool
{
    if (!hms_db_table_exists($connection, 'tbl_vital_sign')) {
        return false;
    }
    $hasRec = hms_vitals_has_recorder_columns($connection);
    $hasAnt = hms_vitals_has_anthropometrics($connection);

    $pid = (int) ($v['patient_id'] ?? 0);
    $fid = (int) ($v['facility_id'] ?? 0);
    $sys = (int) ($v['bp_sys'] ?? 0);
    $dia = (int) ($v['bp_dia'] ?? 0);
    $hr = (int) ($v['heart_rate'] ?? 0);
    $tc = (float) ($v['temp_c'] ?? 0);
    $spo = (int) ($v['spo2'] ?? 0);
    $rr = (int) ($v['rr'] ?? 0);
    $wk = array_key_exists('weight_kg', $v) ? $v['weight_kg'] : null;
    $hc = array_key_exists('height_cm', $v) ? $v['height_cm'] : null;
    $wa = array_key_exists('waist_cm', $v) ? $v['waist_cm'] : null;
    $wk = $wk === null ? null : (float) $wk;
    $hc = $hc === null ? null : (float) $hc;
    $wa = $wa === null ? null : (float) $wa;
    $wks = hms_vitals_decimal_bind($wk);
    $hcs = hms_vitals_decimal_bind($hc);
    $was = hms_vitals_decimal_bind($wa);
    $uid = isset($v['recorded_by']) ? (int) $v['recorded_by'] : 0;
    $station = isset($v['source_station']) ? (string) $v['source_station'] : '';

    if ($hasRec && $hasAnt) {
        if ($uid > 0) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr, weight_kg, height_cm, waist_cm, recorded_by, source_station) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?)'
            );
            if (!$st) {
                return false;
            }
            mysqli_stmt_bind_param(
                $st,
                'iiiiidiisssis',
                $pid,
                $fid,
                $sys,
                $dia,
                $hr,
                $tc,
                $spo,
                $rr,
                $wks,
                $hcs,
                $was,
                $uid,
                $station
            );
            $ok = mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            return $ok;
        }
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr, weight_kg, height_cm, waist_cm, recorded_by, source_station) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?,NULL,?)'
        );
        if (!$st) {
            return false;
        }
        mysqli_stmt_bind_param($st, 'iiiii' . 'd' . 'ii' . 'ssss', $pid, $fid, $sys, $dia, $hr, $tc, $spo, $rr, $wks, $hcs, $was, $station);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        return $ok;
    }

    if ($hasRec && !$hasAnt) {
        if ($uid > 0) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr, recorded_by, source_station) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?)'
            );
            if (!$st) {
                return false;
            }
            mysqli_stmt_bind_param($st, 'iiiiidiiis', $pid, $fid, $sys, $dia, $hr, $tc, $spo, $rr, $uid, $station);
            $ok = mysqli_stmt_execute($st);
            mysqli_stmt_close($st);

            return $ok;
        }
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr, recorded_by, source_station) VALUES (?,?,NOW(),?,?,?,?,?,NULL,?)'
        );
        if (!$st) {
            return false;
        }
        mysqli_stmt_bind_param($st, 'iiiiidiis', $pid, $fid, $sys, $dia, $hr, $tc, $spo, $rr, $station);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        return $ok;
    }

    if (!$hasRec && $hasAnt) {
        $st = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr, weight_kg, height_cm, waist_cm) VALUES (?,?,NOW(),?,?,?,?,?,?,?,?,?)'
        );
        if (!$st) {
            return false;
        }
        mysqli_stmt_bind_param($st, 'iiiiidiiisss', $pid, $fid, $sys, $dia, $hr, $tc, $spo, $rr, $wks, $hcs, $was);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        return $ok;
    }

    $st = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_vital_sign (patient_id, facility_id, recorded_at, bp_sys, bp_dia, heart_rate, temp_c, spo2, rr) VALUES (?,?,NOW(),?,?,?,?,?,?)'
    );
    if (!$st) {
        return false;
    }
    mysqli_stmt_bind_param($st, 'iiiiidii', $pid, $fid, $sys, $dia, $hr, $tc, $spo, $rr);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    return $ok;
}

/**
 * Latest vitals row for patient, with recorder name when columns exist.
 *
 * @return array<string,mixed>|null
 */
function hms_vitals_fetch_latest(mysqli $connection, int $patientId, int $facilityId, bool $multiSite): ?array
{
    if (!hms_db_table_exists($connection, 'tbl_vital_sign') || $patientId < 1) {
        return null;
    }
    $hasRec = hms_vitals_has_recorder_columns($connection);
    $sel = 'SELECT v.*';
    if ($hasRec) {
        $sel .= ', e.first_name AS recorder_first_name, e.last_name AS recorder_last_name';
    }
    $sel .= ' FROM tbl_vital_sign v';
    if ($hasRec) {
        $sel .= ' LEFT JOIN tbl_employee e ON e.id = v.recorded_by';
    }
    $sel .= ' WHERE v.patient_id = ?';
    if ($multiSite) {
        $sel .= ' AND v.facility_id = ?';
    }
    $sel .= ' ORDER BY v.recorded_at DESC, v.id DESC LIMIT 1';

    $st = mysqli_prepare($connection, $sel);
    if (!$st) {
        return null;
    }
    if ($multiSite) {
        mysqli_stmt_bind_param($st, 'ii', $patientId, $facilityId);
    } else {
        mysqli_stmt_bind_param($st, 'i', $patientId);
    }
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $row ?: null;
}

/**
 * Map tbl_vital_sign row → consultation observation param codes (vital_* POST names without prefix).
 *
 * @return array<string,string>
 */
function hms_vitals_row_to_consult_prefill(array $row): array
{
    $out = [];
    if (isset($row['temp_c']) && $row['temp_c'] !== null && (string) $row['temp_c'] !== '') {
        $out['temperature'] = (string) $row['temp_c'];
    }
    if (isset($row['heart_rate']) && $row['heart_rate'] !== null && (int) $row['heart_rate'] > 0) {
        $out['pulse'] = (string) (int) $row['heart_rate'];
    }
    if (isset($row['rr']) && $row['rr'] !== null && (int) $row['rr'] > 0) {
        $out['respiratory_rate'] = (string) (int) $row['rr'];
    }
    if (isset($row['spo2']) && $row['spo2'] !== null && (int) $row['spo2'] > 0) {
        $out['spo2'] = (string) (int) $row['spo2'];
    }
    $wk = hms_vitals_numeric_display($row['weight_kg'] ?? null);
    if ($wk !== null) {
        $out['weight'] = $wk;
    }
    $ht = hms_vitals_numeric_display($row['height_cm'] ?? null);
    if ($ht !== null) {
        $out['height'] = $ht;
    }
    $ws = hms_vitals_numeric_display($row['waist_cm'] ?? null);
    if ($ws !== null) {
        $out['waist'] = $ws;
    }

    return $out;
}

function hms_vitals_station_label(?string $code): string
{
    $c = strtolower(trim((string) $code));
    if ($c === 'front_desk') {
        return 'Front desk';
    }
    if ($c === 'nursing') {
        return 'Nursing station';
    }
    if ($c === 'chart') {
        return 'Clinical chart';
    }

    return $c !== '' ? ucfirst(str_replace('_', ' ', $c)) : '—';
}

/**
 * @param mixed $v Raw DB / form value
 */
function hms_vitals_numeric_display($v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string) $v);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    return rtrim(rtrim(number_format((float) $s, 2, '.', ''), '0'), '.');
}

/**
 * HTML-safe summary line for consultation banner.
 */
function hms_vitals_banner_html(array $row): string
{
    $bp = '';
    $sys = isset($row['bp_sys']) ? (int) $row['bp_sys'] : 0;
    $dia = isset($row['bp_dia']) ? (int) $row['bp_dia'] : 0;
    if ($sys > 0 || $dia > 0) {
        $bp = 'BP ' . $sys . '/' . $dia;
    }
    $hr = isset($row['heart_rate']) ? (int) $row['heart_rate'] : 0;
    $tc = isset($row['temp_c']) ? trim((string) $row['temp_c']) : '';
    $spo = isset($row['spo2']) ? (int) $row['spo2'] : 0;
    $rr = isset($row['rr']) ? (int) $row['rr'] : 0;
    $wt = hms_vitals_numeric_display($row['weight_kg'] ?? null);
    $ht = hms_vitals_numeric_display($row['height_cm'] ?? null);
    $ws = hms_vitals_numeric_display($row['waist_cm'] ?? null);
    $parts = array_filter([
        $bp !== '' ? $bp : null,
        $hr > 0 ? 'HR ' . $hr : null,
        $tc !== '' ? 'Temp ' . $tc . ' °C' : null,
        $spo > 0 ? 'SpO₂ ' . $spo . '%' : null,
        $rr > 0 ? 'RR ' . $rr : null,
        $wt !== null ? 'Wt ' . $wt . ' kg' : null,
        $ht !== null ? 'Ht ' . $ht . ' cm' : null,
        $ws !== null ? 'Waist ' . $ws . ' cm' : null,
    ]);
    $line = implode(' · ', $parts);
    if ($line === '') {
        return '';
    }

    $when = !empty($row['recorded_at']) ? date('d M Y H:i', strtotime((string) $row['recorded_at'])) : '';
    $station = hms_vitals_station_label(isset($row['source_station']) ? (string) $row['source_station'] : null);
    $by = '';
    $fn = trim((string) ($row['recorder_first_name'] ?? ''));
    $ln = trim((string) ($row['recorder_last_name'] ?? ''));
    if ($fn !== '' || $ln !== '') {
        $by = trim($fn . ' ' . $ln);
    }

    $meta = array_filter([$station !== '—' ? $station : null, $when !== '' ? $when : null]);
    $head = implode(' · ', $meta);
    $rec = $by !== '' ? 'Recorded by <strong>' . hms_h($by) . '</strong>.' : '';

    return '<div class="mb-0"><span class="font-weight-bold">Triage vitals</span>'
        . ($head !== '' ? ' <span class="text-muted">(' . hms_h($head) . ')</span>' : '')
        . '<br><span class="small">' . hms_h($line) . '</span>'
        . ($rec !== '' ? '<br><span class="small">' . $rec . '</span>' : '')
        . '</div>';
}
