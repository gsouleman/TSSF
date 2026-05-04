<?php
declare(strict_types=1);

/**
 * Escape output for HTML contexts.
 */
function hms_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Absolute URL (with trailing slash) for HTML <base href>, so relative `assets/...`
 * resolves from the app directory even when SCRIPT_NAME is in a subfolder.
 * Optional: define HMS_HTML_BASE in config.local.php (e.g. https://hms.free.nf/hms/).
 */
function hms_html_base_href(): ?string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return null;
    }
    if (defined('HMS_HTML_BASE')) {
        $b = trim((string) HMS_HTML_BASE);

        return $b === '' ? null : (rtrim($b, '/') . '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) $_SERVER['HTTP_HOST'];
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $path = '/';
    } else {
        $path = rtrim($dir, '/') . '/';
    }

    return $scheme . '://' . $host . $path;
}

/**
 * Fetch one associative row after mysqli_stmt_execute(). Compatible with hosts
 * that do not have mysqlnd (mysqli_stmt_get_result unavailable).
 *
 * @param mysqli_stmt|false|null $stmt
 */
function hms_stmt_fetch_assoc($stmt): ?array
{
    if (!$stmt instanceof mysqli_stmt) {
        return null;
    }
    if (function_exists('mysqli_stmt_get_result')) {
        $res = @mysqli_stmt_get_result($stmt);
        if ($res instanceof mysqli_result) {
            $row = mysqli_fetch_assoc($res);
            mysqli_free_result($res);

            return $row ?: null;
        }

        return null;
    }

    if (!mysqli_stmt_store_result($stmt)) {
        return null;
    }
    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) {
        return null;
    }
    $fields = mysqli_fetch_fields($meta);
    if (!$fields) {
        mysqli_free_result($meta);

        return null;
    }
    $row = [];
    $bind = [];
    foreach ($fields as $field) {
        $name = $field->name;
        $row[$name] = null;
        $bind[] = &$row[$name];
    }
    call_user_func_array([$stmt, 'bind_result'], $bind);
    $ok = mysqli_stmt_fetch($stmt);
    mysqli_free_result($meta);
    if (!$ok) {
        return null;
    }
    $out = [];
    foreach ($row as $k => $v) {
        $out[$k] = $v;
    }

    return $out;
}

function hms_hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function hms_is_modern_password_hash(string $stored): bool
{
    if (strlen($stored) < 60) {
        return false;
    }
    return strncmp($stored, '$2y$', 4) === 0
        || strncmp($stored, '$argon', 6) === 0;
}

/**
 * Verify password against bcrypt/argon or legacy plaintext (upgraded on login).
 */
function hms_verify_password(string $plain, string $stored): bool
{
    if ($stored === '') {
        return false;
    }
    if (hms_is_modern_password_hash($stored)) {
        return password_verify($plain, $stored);
    }
    return hash_equals($stored, $plain);
}

function hms_upgrade_legacy_password(mysqli $connection, int $userId, string $plain, string $stored): void
{
    if (hms_is_modern_password_hash($stored)) {
        return;
    }
    if (!hash_equals($stored, $plain)) {
        return;
    }
    $hash = hms_hash_password($plain);
    $stmt = mysqli_prepare($connection, 'UPDATE tbl_employee SET password = ? WHERE id = ?');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'si', $hash, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function hms_json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * API: allow session (same-origin) or optional X-HMS-Key when HMS_API_KEY is set.
 */
function hms_api_require_auth(): void
{
    if (HMS_API_KEY !== '' && isset($_SERVER['HTTP_X_HMS_KEY'])) {
        if (hash_equals(HMS_API_KEY, (string) $_SERVER['HTTP_X_HMS_KEY'])) {
            return;
        }
    }
    if (!empty($_SESSION['name'])) {
        return;
    }
    hms_json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/** Sidebar / nav: mark <li class="active"> when current script matches any basename. */
function hms_nav_active(string ...$scripts): string
{
    $cur = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ($scripts as $s) {
        if ($cur === strtolower($s)) {
            return 'active';
        }
    }

    return '';
}

/** True when the current page basename matches any script in the group (for sidebar accordions). */
function hms_sidebar_section_show(string ...$scripts): bool
{
    $cur = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach ($scripts as $s) {
        if ($cur === strtolower($s)) {
            return true;
        }
    }

    return false;
}

/**
 * Landing URL after staff sign-in. Used by index.php and dashboard.php.
 * When portal helpers exist, limited roles may be sent to their primary portal.
 */
function hms_login_redirect_after_auth(mysqli $connection, int $userId, string $role): string
{
    if ($userId < 1) {
        return 'dashboard.php';
    }
    if (function_exists('hms_staff_portal_nav_is_limited') && function_exists('hms_staff_primary_portal_url')
        && hms_staff_portal_nav_is_limited($role)) {
        $url = hms_staff_primary_portal_url($connection, $userId, $role);
        if (is_string($url) && $url !== '') {
            return $url;
        }
    }

    return 'dashboard.php';
}

/**
 * Enforce access to role-specific portal pages (portal-doctors.php, portal-laboratory.php, portal-pharmacy.php).
 * When ACL is not installed, any signed-in user may open portals (legacy behaviour).
 */
function hms_require_portal(mysqli $connection, string $portal): void
{
    if (empty($_SESSION['name'])) {
        header('Location: index.php');
        exit;
    }
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role === '1') {
        return;
    }

    $code = strtolower(trim($portal));
    $hasAcl = function_exists('hms_db_table_exists') && hms_db_table_exists($connection, 'tbl_acl_permission');

    if (!$hasAcl) {
        return;
    }

    $ok = false;
    if (function_exists('hms_can')) {
        switch ($code) {
            case 'doctors':
                $ok = $role === '2' || hms_can($connection, 'clinical.read');
                break;
            case 'laboratory':
                $ok = hms_can($connection, 'lab.read');
                break;
            case 'radiology':
                $ok = hms_can($connection, 'radiology.read');
                break;
            case 'pharmacy':
                $ok = hms_can($connection, 'pharmacy.read') || hms_can($connection, 'prescription.read');
                break;
            case 'cashier':
                $ok = hms_can($connection, 'cashier.write') || hms_can($connection, 'billing.write');
                break;
            case 'accountant':
                $ok = hms_can($connection, 'billing.read')
                    || hms_can($connection, 'financials.read')
                    || hms_can($connection, 'expenses.read');
                break;
            default:
                $ok = false;
        }
    }

    if (!$ok) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Full years from DOB string (supports Y-m-d, d/m/Y, m/d/Y, strtotime fallback).
 * Used by consultation and patient UI; kept here so it is always available after bootstrap.
 */
function hms_patient_age_years_from_dob(?string $dob): ?int
{
    $dob = trim((string) $dob);
    if ($dob === '') {
        return null;
    }
    $parsed = null;
    foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $dob);
        if ($dt instanceof DateTimeImmutable) {
            $parsed = $dt;
            break;
        }
    }
    if ($parsed === null) {
        $ts = strtotime($dob);
        if ($ts === false) {
            return null;
        }
        $parsed = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    $today = new DateTimeImmutable('today');

    return $parsed->diff($today)->y;
}

function hms_patient_gender_label(?string $gender): string
{
    $g = strtolower(trim((string) $gender));
    if ($g === 'male' || $g === 'm') {
        return 'Male';
    }
    if ($g === 'female' || $g === 'f') {
        return 'Female';
    }
    if ($g === '') {
        return '';
    }

    return ucfirst($g);
}
