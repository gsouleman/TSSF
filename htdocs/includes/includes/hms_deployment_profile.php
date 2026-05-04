<?php
declare(strict_types=1);

/**
 * Named deployment profiles (tbl_hms_deployment_profile) + active pointer on tbl_app_settings.
 */

function hms_deployment_profile_table_ready(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_hms_deployment_profile')
        && hms_db_column_exists($connection, 'tbl_app_settings', 'active_deployment_profile_id');
}

/** @return array<string,string> nav key => label */
function hms_nav_module_labels(): array
{
    return [
        'portals' => 'Portals (admin)',
        'healthcare' => 'Healthcare (clinical, billing in sidebar group)',
        'accounting' => 'Accounting (GL, expenses)',
        'tax' => 'Tax (Cameroon)',
        'manage_catalog' => 'Manage — Service catalog',
        'manage_inventory' => 'Manage — Inventory',
        'manage_procurement' => 'Manage — Purchase orders',
        'manage_schedule' => 'Manage — Doctor schedule',
        'manage_departments' => 'Manage — Departments',
        'manage_staff' => 'Manage — Staff directory',
        'manage_payroll' => 'Manage — Payroll',
        'manage_leave_admin' => 'Manage — Leave & attendance (admin)',
        'manage_holidays' => 'Manage — Holidays',
        'manage_self' => 'Manage — Self-service (leave / payslips / attendance)',
        'access_control' => 'Manage — Access control',
    ];
}

/**
 * @param array<string,mixed> $decoded
 * @return array<string,bool>
 */
function hms_nav_normalize_mask(array $decoded): array
{
    $out = hms_nav_mask_blank();
    foreach (hms_nav_module_key_list() as $k) {
        if (array_key_exists($k, $decoded)) {
            $out[$k] = (bool) $decoded[$k];
        }
    }

    return $out;
}

/**
 * @return array<string,bool>|null
 */
function hms_deployment_profile_sidebar_mask(mysqli $connection, array $profileRow): ?array
{
    $mods = trim((string) ($profileRow['modules_json'] ?? ''));
    if ($mods !== '' && $mods !== 'null') {
        $decoded = json_decode($mods, true);
        if (is_array($decoded) && $decoded !== [] && function_exists('hms_profile_modules_extract_nav_array')) {
            $navPart = hms_profile_modules_extract_nav_array($decoded);
            if ($navPart !== null) {
                return hms_nav_normalize_mask($navPart);
            }
        }
    }
    $slices = hms_deployment_profile_parse_slices_json((string) ($profileRow['slices_json'] ?? '[]'));
    if ($slices === []) {
        return null;
    }

    return function_exists('hms_nav_mask_from_slices') ? hms_nav_mask_from_slices($slices) : hms_nav_mask_blank();
}

/**
 * @return list<string>
 */
function hms_deployment_profile_parse_slices_json(string $raw): array
{
    $modes = hms_app_product_modes();
    $j = json_decode(trim($raw), true);
    if (!is_array($j)) {
        return [];
    }
    $out = [];
    foreach ($j as $v) {
        $x = strtolower(trim((string) $v));
        if (in_array($x, $modes, true)) {
            $out[] = $x;
        }
    }

    return array_values(array_unique($out));
}

/** @return ?array{id:int,name:string,slices_json:string,modules_json:?string} */
function hms_deployment_profile_fetch(mysqli $connection, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id, name, slices_json, modules_json FROM tbl_hms_deployment_profile WHERE id = ? LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $row = function_exists('hms_stmt_fetch_assoc') ? hms_stmt_fetch_assoc($st) : null;
    mysqli_stmt_close($st);

    return is_array($row) ? $row : null;
}

/**
 * Active deployment profile row when set and table exists.
 *
 * @return ?array{id:int,name:string,slices_json:string,modules_json:?string}
 */
function hms_active_deployment_profile_row(mysqli $connection): ?array
{
    if (!hms_deployment_profile_table_ready($connection) || !hms_app_settings_table_ready($connection)) {
        return null;
    }
    $q = mysqli_query($connection, 'SELECT active_deployment_profile_id FROM tbl_app_settings WHERE id = 1 LIMIT 1');
    $rw = $q ? mysqli_fetch_assoc($q) : null;
    $pid = (int) ($rw['active_deployment_profile_id'] ?? 0);
    if ($pid < 1) {
        return null;
    }

    return hms_deployment_profile_fetch($connection, $pid);
}

/**
 * @return list<string>
 */
function hms_active_deployment_profile_slices(mysqli $connection): ?array
{
    $row = hms_active_deployment_profile_row($connection);
    if ($row === null) {
        return null;
    }

    return hms_deployment_profile_parse_slices_json((string) ($row['slices_json'] ?? '[]'));
}
