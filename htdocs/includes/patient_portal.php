<?php
declare(strict_types=1);

require_once __DIR__ . '/facility.php';

function hms_patient_portal_ready(mysqli $connection): bool
{
    return hms_db_column_exists($connection, 'tbl_patient', 'portal_enabled')
        && hms_db_column_exists($connection, 'tbl_patient', 'portal_password_hash');
}

function hms_patient_portal_patient_id(): int
{
    return (int) ($_SESSION['patient_portal_patient_id'] ?? 0);
}

function hms_patient_portal_clear_staff_session(): void
{
    unset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['name'],
        $_SESSION['facility_id']
    );
}

/**
 * @return string error message, empty on success
 */
function hms_patient_portal_attempt_login(mysqli $connection, string $email, string $password): string
{
    $emailNorm = strtolower(trim($email));
    if ($emailNorm === '' || $password === '') {
        return 'Please enter the email and password you were given at registration.';
    }
    if (!hms_patient_portal_ready($connection)) {
        return 'The patient portal is not set up on this server yet. Please contact the clinic.';
    }

    $stmt = mysqli_prepare(
        $connection,
        'SELECT id, email, portal_password_hash, portal_enabled, status FROM tbl_patient WHERE LOWER(email) = ? LIMIT 1'
    );
    if (!$stmt) {
        return 'Unable to sign in right now. Please try again later.';
    }
    mysqli_stmt_bind_param($stmt, 's', $emailNorm);
    mysqli_stmt_execute($stmt);
    $row = hms_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);

    if (!$row) {
        return 'Invalid email or password.';
    }
    if ((int) ($row['status'] ?? 0) !== 1) {
        return 'This account is inactive. Please contact the clinic.';
    }
    if ((int) ($row['portal_enabled'] ?? 0) !== 1) {
        return 'Online access is not enabled for this email. Please ask reception to turn on the patient portal for your record.';
    }
    $hash = (string) ($row['portal_password_hash'] ?? '');
    if ($hash === '' || !hms_verify_password($password, $hash)) {
        return 'Invalid email or password.';
    }

    hms_patient_portal_clear_staff_session();
    session_regenerate_id(true);
    $_SESSION['patient_portal_patient_id'] = (int) $row['id'];
    $_SESSION['patient_portal_email'] = (string) $row['email'];

    return '';
}

function hms_patient_portal_logout(): void
{
    unset($_SESSION['patient_portal_patient_id'], $_SESSION['patient_portal_email']);
}

/**
 * Apply portal checkbox + optional new password from staff edit-patient form.
 */
function hms_patient_portal_apply_staff_settings(mysqli $connection, int $patientId, bool $multiSite, int $facilityId): void
{
    if (!hms_patient_portal_ready($connection) || !hms_can($connection, 'patient.write')) {
        return;
    }

    $enabled = isset($_POST['portal_enabled']) ? 1 : 0;
    $newPass = trim((string) ($_POST['portal_new_password'] ?? ''));

    if ($enabled === 0) {
        if ($multiSite) {
            $stmt = mysqli_prepare(
                $connection,
                'UPDATE tbl_patient SET portal_enabled = 0, portal_password_hash = NULL WHERE id = ? AND facility_id = ?'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $patientId, $facilityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'UPDATE tbl_patient SET portal_enabled = 0, portal_password_hash = NULL WHERE id = ?'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $patientId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        hms_audit_log($connection, 'patient.portal.disable', 'patient', $patientId);

        return;
    }

    if ($newPass !== '') {
        $hash = hms_hash_password($newPass);
        if ($multiSite) {
            $stmt = mysqli_prepare(
                $connection,
                'UPDATE tbl_patient SET portal_enabled = 1, portal_password_hash = ? WHERE id = ? AND facility_id = ?'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sii', $hash, $patientId, $facilityId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'UPDATE tbl_patient SET portal_enabled = 1, portal_password_hash = ? WHERE id = ?'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'si', $hash, $patientId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        hms_audit_log($connection, 'patient.portal.password_set', 'patient', $patientId);

        return;
    }

    if ($multiSite) {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 1 WHERE id = ? AND facility_id = ?'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $patientId, $facilityId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 1 WHERE id = ?'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $patientId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

function hms_patient_portal_patient_in_scope(mysqli $connection, int $patientId, int $facilityId, bool $multiSite): bool
{
    if ($patientId < 1) {
        return false;
    }
    if ($multiSite) {
        $stmt = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ii', $patientId, $facilityId);
    } else {
        $stmt = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'i', $patientId);
    }
    mysqli_stmt_execute($stmt);
    $row = hms_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);

    return is_array($row) && (int) ($row['id'] ?? 0) === $patientId;
}

/** Access Control: turn off patient portal sign-in and clear stored password hash. */
function hms_patient_portal_access_control_disable(mysqli $connection, int $patientId, int $facilityId, bool $multiSite): bool
{
    if (!hms_patient_portal_ready($connection) || !hms_patient_portal_patient_in_scope($connection, $patientId, $facilityId, $multiSite)) {
        return false;
    }
    if ($multiSite) {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 0, portal_password_hash = NULL WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $patientId, $facilityId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 0, portal_password_hash = NULL WHERE id = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $patientId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    hms_audit_log($connection, 'patient.portal.admin_disable', 'patient', $patientId);

    return true;
}

/**
 * Access Control: set or reset portal password (bcrypt) and enable portal sign-in.
 *
 * @return array{ok:bool, error?:string}
 */
function hms_patient_portal_access_control_set_password(mysqli $connection, int $patientId, int $facilityId, bool $multiSite, string $plainPassword): array
{
    if (!hms_patient_portal_ready($connection)) {
        return ['ok' => false, 'error' => 'Patient portal columns are missing. Run migration 002_patient_portal.sql.'];
    }
    if (!hms_patient_portal_patient_in_scope($connection, $patientId, $facilityId, $multiSite)) {
        return ['ok' => false, 'error' => 'Patient not found for this site.'];
    }
    $plainPassword = trim($plainPassword);
    if (strlen($plainPassword) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    $hash = hms_hash_password($plainPassword);
    if ($multiSite) {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 1, portal_password_hash = ? WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not update patient.'];
        }
        mysqli_stmt_bind_param($stmt, 'sii', $hash, $patientId, $facilityId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'UPDATE tbl_patient SET portal_enabled = 1, portal_password_hash = ? WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Could not update patient.'];
        }
        mysqli_stmt_bind_param($stmt, 'si', $hash, $patientId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    hms_audit_log($connection, 'patient.portal.admin_password_reset', 'patient', $patientId);

    return ['ok' => true];
}

/**
 * Finalized lab/radiology results published to the patient portal (after migration 024).
 *
 * @return list<array<string,mixed>>
 */
function hms_patient_portal_result_notices(mysqli $connection, int $patientId): array
{
    if ($patientId < 1 || !hms_db_table_exists($connection, 'tbl_result_shared_notice')) {
        return [];
    }
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, test_label, summary, conclusion_code, created_at, lab_result_id, radiology_result_id
         FROM tbl_result_shared_notice
         WHERE audience = \'patient\' AND patient_id = ' . (int) $patientId . '
         ORDER BY id DESC LIMIT 30'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}
