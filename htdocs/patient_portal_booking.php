<?php
declare(strict_types=1);

/**
 * Patient portal: read doctor availability (tbl_schedule) and submit appointment requests (tbl_appointment).
 */

function hms_patient_portal_patient_name_csv(array $patient): string
{
    $name = trim((string) ($patient['first_name'] ?? '') . ' ' . (string) ($patient['last_name'] ?? ''));
    $dob = trim((string) ($patient['dob'] ?? ''));

    return trim($name . ($dob !== '' ? ',' . $dob : ''));
}

/** @return list<string> */
function hms_patient_portal_booking_doctors(mysqli $connection, int $facilityId, bool $multiSite): array
{
    $out = [];
    if ($multiSite && hms_db_table_exists($connection, 'tbl_user_facility')) {
        $sql = 'SELECT DISTINCT CONCAT(e.first_name, \' \', e.last_name) AS name FROM tbl_employee e
                INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ' . (int) $facilityId . ' ORDER BY name ASC';
    } else {
        $sql = "SELECT CONCAT(first_name, ' ', last_name) AS name FROM tbl_employee WHERE role = 2 AND status = 1 ORDER BY last_name ASC, first_name ASC";
    }
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $n = trim((string) ($r['name'] ?? ''));
        if ($n !== '') {
            $out[] = $n;
        }
    }

    return $out;
}

/** @return list<string> */
function hms_patient_portal_booking_departments(mysqli $connection, int $facilityId, bool $multiSite): array
{
    $out = [];
    $sql = 'SELECT department_name FROM tbl_department';
    if ($multiSite && hms_db_column_exists($connection, 'tbl_department', 'facility_id')) {
        $sql .= ' WHERE facility_id = ' . (int) $facilityId;
    }
    $sql .= ' ORDER BY department_name ASC';
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $d = trim((string) ($r['department_name'] ?? ''));
        if ($d !== '') {
            $out[] = $d;
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function hms_patient_portal_booking_schedules(mysqli $connection, int $facilityId, bool $multiSite): array
{
    if (!hms_db_table_exists($connection, 'tbl_schedule')) {
        return [];
    }
    $sql = 'SELECT id, doctor_name, available_days, start_time, end_time, message, status FROM tbl_schedule WHERE status = 1';
    if ($multiSite && hms_db_column_exists($connection, 'tbl_schedule', 'facility_id')) {
        $sql .= ' AND facility_id = ' . (int) $facilityId;
    }
    $sql .= ' ORDER BY doctor_name ASC, id ASC';
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

function hms_patient_portal_booking_time_to_minutes(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime('1970-01-01 ' . $raw);
    if ($ts === false) {
        return null;
    }

    return (int) date('G', $ts) * 60 + (int) date('i', $ts);
}

function hms_patient_portal_booking_minutes_to_hhmm(int $m): string
{
    $m = max(0, min($m, 24 * 60 - 1));
    $h = intdiv($m, 60);
    $i = $m % 60;

    return sprintf('%02d:%02d', $h, $i);
}

function hms_patient_portal_booking_weekday_from_ymd(string $dateYmd): ?string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
    if ($dt === false) {
        return null;
    }

    return $dt->format('l');
}

/**
 * @param string $availableDays e.g. "Monday, Tuesday"
 */
function hms_patient_portal_booking_day_allowed(string $availableDays, string $weekdayName): bool
{
    $weekdayName = trim($weekdayName);
    if ($weekdayName === '') {
        return false;
    }
    $parts = array_map('trim', explode(',', $availableDays));
    foreach ($parts as $p) {
        if (strcasecmp($p, $weekdayName) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string> HH:MM slot values
 */
function hms_patient_portal_booking_slots_for(
    mysqli $connection,
    string $doctorName,
    string $dateYmd,
    int $facilityId,
    bool $multiSite,
    int $slotMinutes = 30
): array
{
    $doctorName = trim($doctorName);
    if ($doctorName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return [];
    }
    $weekday = hms_patient_portal_booking_weekday_from_ymd($dateYmd);
    if ($weekday === null) {
        return [];
    }

    $schedules = hms_patient_portal_booking_schedules($connection, $facilityId, $multiSite);
    $row = null;
    foreach ($schedules as $s) {
        if (strcasecmp(trim((string) ($s['doctor_name'] ?? '')), $doctorName) === 0) {
            $row = $s;
            break;
        }
    }
    if ($row === null) {
        return [];
    }
    $days = (string) ($row['available_days'] ?? '');
    if (!hms_patient_portal_booking_day_allowed($days, $weekday)) {
        return [];
    }
    $startM = hms_patient_portal_booking_time_to_minutes((string) ($row['start_time'] ?? ''));
    $endM = hms_patient_portal_booking_time_to_minutes((string) ($row['end_time'] ?? ''));
    if ($startM === null || $endM === null || $endM <= $startM) {
        return [];
    }

    $busy = hms_patient_portal_booking_busy_slots($connection, $doctorName, $dateYmd, $facilityId, $multiSite);
    $slots = [];
    for ($m = $startM; $m + $slotMinutes <= $endM; $m += $slotMinutes) {
        $label = hms_patient_portal_booking_minutes_to_hhmm($m);
        if (!in_array($label, $busy, true)) {
            $slots[] = $label;
        }
    }

    return $slots;
}

/**
 * @return list<string> HH:MM already taken for doctor on date (portal format Y-m-d)
 *
 * @return list<string>
 */
function hms_patient_portal_booking_busy_slots(
    mysqli $connection,
    string $doctorName,
    string $dateYmd,
    int $facilityId,
    bool $multiSite
): array {
    $doctorEsc = mysqli_real_escape_string($connection, trim($doctorName));
    $dateEsc = mysqli_real_escape_string($connection, $dateYmd);
    $sql = "SELECT time FROM tbl_appointment WHERE doctor = '" . $doctorEsc . "' AND date = '" . $dateEsc . "' AND status IN (0, 1)";
    if ($multiSite && hms_db_column_exists($connection, 'tbl_appointment', 'facility_id')) {
        $sql .= ' AND facility_id = ' . (int) $facilityId;
    }
    $busy = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $t = trim((string) ($r['time'] ?? ''));
        if ($t === '') {
            continue;
        }
        $norm = hms_patient_portal_booking_normalize_time_key($t);
        if ($norm !== '') {
            $busy[] = $norm;
        }
    }

    return array_values(array_unique($busy));
}

function hms_patient_portal_booking_normalize_time_key(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '';
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $stored)) {
        $parts = explode(':', $stored, 2);
        $h = (int) $parts[0];
        $i = (int) ($parts[1] ?? 0);

        return sprintf('%02d:%02d', max(0, min(23, $h)), max(0, min(59, $i)));
    }
    $m = hms_patient_portal_booking_time_to_minutes($stored);
    if ($m === null) {
        return '';
    }

    return hms_patient_portal_booking_minutes_to_hhmm($m);
}

/**
 * @param list<string> $allowedDoctors
 * @param list<string> $allowedDepartments
 *
 * @return string empty on success
 */
function hms_patient_portal_booking_submit(
    mysqli $connection,
    array $patient,
    string $department,
    string $doctor,
    string $dateYmd,
    string $timeHhmm,
    string $notes,
    array $allowedDoctors,
    array $allowedDepartments
): string {
    $department = trim($department);
    $doctor = trim($doctor);
    $notes = trim($notes);
    if ($department === '' || $doctor === '' || $dateYmd === '' || $timeHhmm === '') {
        return 'Please choose department, doctor, date, and time.';
    }
    if (!in_array($doctor, $allowedDoctors, true)) {
        return 'That doctor is not available for online booking at this site.';
    }
    if (!in_array($department, $allowedDepartments, true)) {
        return 'Please choose a valid department.';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
    if ($dt === false) {
        return 'Invalid date.';
    }
    $today = new DateTimeImmutable('today');
    if ($dt < $today) {
        return 'Please pick today or a future date.';
    }
    if ($dt > $today->modify('+120 days')) {
        return 'Please choose a date within the next four months.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $timeHhmm)) {
        return 'Invalid time selection.';
    }

    $fid = (int) ($patient['facility_id'] ?? 1);
    $ms = hms_multi_site_enabled($connection);
    $slots = hms_patient_portal_booking_slots_for($connection, $doctor, $dateYmd, $fid, $ms);
    if ($slots === []) {
        return 'No open slots for that doctor on the selected date. Pick another day or call the clinic.';
    }
    if (!in_array($timeHhmm, $slots, true)) {
        return 'That time is no longer available. Refresh and choose another slot.';
    }

    $patientName = hms_patient_portal_patient_name_csv($patient);
    if (trim(str_replace(',', '', $patientName)) === '') {
        return 'Your profile is missing a name. Please contact the clinic.';
    }

    $pid = (int) ($patient['id'] ?? 0);
    $hasPatientIdCol = hms_db_column_exists($connection, 'tbl_appointment', 'patient_id');

    if ($ms) {
        $q = mysqli_query($connection, 'SELECT MAX(id) AS id FROM tbl_appointment WHERE facility_id = ' . (int) $fid);
    } else {
        $q = mysqli_query($connection, 'SELECT MAX(id) AS id FROM tbl_appointment');
    }
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $next = (int) ($row['id'] ?? 0) + 1;
    if ($next < 1) {
        $next = 1;
    }
    $appointmentId = 'APT-' . $next;
    $message = '[Patient portal] ' . ($notes !== '' ? $notes : 'Appointment request submitted online.');
    $status = 0;

    if ($ms) {
        if ($hasPatientIdCol && $pid > 0) {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, facility_id, patient_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if (!$stmt) {
                return 'Could not save your request. Please try again later.';
            }
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssiii',
                $appointmentId,
                $patientName,
                $department,
                $doctor,
                $dateYmd,
                $timeHhmm,
                $message,
                $status,
                $fid,
                $pid
            );
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, facility_id) VALUES (?,?,?,?,?,?,?,?,?)'
            );
            if (!$stmt) {
                return 'Could not save your request. Please try again later.';
            }
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssii',
                $appointmentId,
                $patientName,
                $department,
                $doctor,
                $dateYmd,
                $timeHhmm,
                $message,
                $status,
                $fid
            );
        }
    } else {
        if ($hasPatientIdCol && $pid > 0) {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, patient_id) VALUES (?,?,?,?,?,?,?,?,?)'
            );
            if (!$stmt) {
                return 'Could not save your request. Please try again later.';
            }
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssii',
                $appointmentId,
                $patientName,
                $department,
                $doctor,
                $dateYmd,
                $timeHhmm,
                $message,
                $status,
                $pid
            );
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status) VALUES (?,?,?,?,?,?,?,?)'
            );
            if (!$stmt) {
                return 'Could not save your request. Please try again later.';
            }
            mysqli_stmt_bind_param(
                $stmt,
                'sssssssi',
                $appointmentId,
                $patientName,
                $department,
                $doctor,
                $dateYmd,
                $timeHhmm,
                $message,
                $status
            );
        }
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return 'Could not save your request. Please try again later.';
    }
    $newId = (int) mysqli_insert_id($connection);
    mysqli_stmt_close($stmt);
    if (function_exists('hms_audit_log')) {
        hms_audit_log($connection, 'patient_portal.appointment.request', 'appointment', $newId > 0 ? $newId : null);
    }

    return '';
}
