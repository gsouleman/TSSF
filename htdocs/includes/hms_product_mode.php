<?php
declare(strict_types=1);

/**
 * Global product deployment (tbl_app_settings) + Super Admin (role 99).
 * Supports multiple "slices" via JSON column product_slices (union of sidebars).
 */

function hms_super_admin_role(): string
{
    return '99';
}

function hms_is_super_admin(): bool
{
    return (string) ($_SESSION['role'] ?? '') === hms_super_admin_role();
}

/** Admin (1) or Super Admin (99) — deploy / staff management UI. */
function hms_staff_is_deploy_admin(): bool
{
    $r = (string) ($_SESSION['role'] ?? '');

    return $r === '1' || $r === hms_super_admin_role();
}

function hms_app_settings_table_ready(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_app_settings');
}

/** @return list<string> */
function hms_app_product_modes(): array
{
    return [
        'full',
        'hms',
        'accounting',
        'leave_attendance',
        'tax_cameroon',
        'payroll',
        'inventory',
        'procurement',
    ];
}

/** @return list<string> */
function hms_nav_module_key_list(): array
{
    return [
        'portals', 'healthcare', 'accounting', 'tax', 'manage_catalog', 'manage_inventory',
        'manage_procurement', 'manage_schedule', 'manage_departments', 'manage_staff',
        'manage_payroll', 'manage_leave_admin', 'manage_holidays', 'manage_self', 'access_control',
    ];
}

/** @return array<string,bool> */
function hms_nav_mask_blank(): array
{
    return array_fill_keys(hms_nav_module_key_list(), false);
}

/**
 * Sidebar module flags enabled by a single product slice (exclusive definition).
 *
 * @return array<string,bool>
 */
function hms_product_slice_mask(string $mode): array
{
    $keys = hms_nav_module_key_list();
    $allOn = array_fill_keys($keys, true);
    $b = hms_nav_mask_blank();

    switch ($mode) {
        case 'full':
            return $allOn;
        case 'hms':
            foreach (['portals', 'healthcare', 'manage_catalog', 'manage_schedule', 'manage_departments', 'manage_staff', 'access_control'] as $k) {
                $b[$k] = true;
            }

            return $b;
        case 'accounting':
            $b['accounting'] = true;

            return $b;
        case 'leave_attendance':
            foreach (['manage_staff', 'manage_leave_admin', 'manage_holidays', 'manage_self'] as $k) {
                $b[$k] = true;
            }

            return $b;
        case 'tax_cameroon':
            $b['tax'] = true;
            $b['manage_staff'] = true;

            return $b;
        case 'payroll':
            foreach (['manage_staff', 'manage_payroll', 'manage_leave_admin', 'manage_holidays', 'manage_self'] as $k) {
                $b[$k] = true;
            }

            return $b;
        case 'inventory':
            $b['manage_catalog'] = true;
            $b['manage_inventory'] = true;

            return $b;
        case 'procurement':
            foreach (['manage_catalog', 'manage_inventory', 'manage_procurement'] as $k) {
                $b[$k] = true;
            }

            return $b;
        default:
            return $b;
    }
}

/** @return array<string,bool> */
function hms_nav_merge_masks(array $acc, array $add): array
{
    foreach (hms_nav_module_key_list() as $k) {
        $acc[$k] = !empty($acc[$k]) || !empty($add[$k]);
    }

    return $acc;
}

/**
 * Merged sidebar mask from slice codes (union of product slices).
 *
 * @param list<string> $slices
 * @return array<string,bool>
 */
function hms_nav_mask_from_slices(array $slices): array
{
    $keys = hms_nav_module_key_list();
    $all = array_fill_keys($keys, true);
    if ($slices === [] || in_array('full', $slices, true)) {
        return $all;
    }
    $acc = hms_nav_mask_blank();
    foreach ($slices as $sl) {
        $acc = hms_nav_merge_masks($acc, hms_product_slice_mask((string) $sl));
    }

    return $acc;
}

/**
 * @return list<string> Active slice keys (deduped). ['full'] means entire app.
 */
function hms_app_product_slices(mysqli $connection): array
{
    if (function_exists('hms_active_deployment_profile_row') && function_exists('hms_deployment_profile_parse_slices_json')) {
        $pr = hms_active_deployment_profile_row($connection);
        if (is_array($pr)) {
            $parsed = hms_deployment_profile_parse_slices_json((string) ($pr['slices_json'] ?? '[]'));
            $modsRaw = trim((string) ($pr['modules_json'] ?? ''));
            $hasCustom = $modsRaw !== '' && $modsRaw !== 'null';
            if ($hasCustom) {
                $mj = json_decode($modsRaw, true);
                $hasCustom = is_array($mj) && $mj !== []
                    && (!function_exists('hms_profile_modules_json_has_customization')
                        || hms_profile_modules_json_has_customization($mj));
            }
            if ($parsed !== []) {
                if (in_array('full', $parsed, true)) {
                    return ['full'];
                }

                return $parsed;
            }
            if ($hasCustom) {
                return ['full'];
            }
        }
    }

    if (!hms_app_settings_table_ready($connection)) {
        return ['full'];
    }

    $modes = hms_app_product_modes();
    $hasSlicesCol = function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_app_settings', 'product_slices');
    $cols = $hasSlicesCol ? 'product_mode, product_slices' : 'product_mode';
    $q = mysqli_query($connection, 'SELECT ' . $cols . ' FROM tbl_app_settings WHERE id = 1 LIMIT 1');
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!is_array($row)) {
        return ['full'];
    }

    if ($hasSlicesCol && isset($row['product_slices'])) {
        $raw = trim((string) $row['product_slices']);
        if ($raw !== '' && $raw !== '[]') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                $out = [];
                foreach ($j as $v) {
                    $x = strtolower(trim((string) $v));
                    if (in_array($x, $modes, true)) {
                        $out[] = $x;
                    }
                }
                $out = array_values(array_unique($out));
                if ($out !== []) {
                    if (in_array('full', $out, true)) {
                        return ['full'];
                    }

                    return $out;
                }
            }
        }
    }

    $m = strtolower(trim((string) ($row['product_mode'] ?? 'full')));
    if (!in_array($m, $modes, true)) {
        $m = 'full';
    }

    return $m === 'full' ? ['full'] : [$m];
}

/** Legacy single mode (first slice, or full). */
function hms_app_product_mode(mysqli $connection): string
{
    $s = hms_app_product_slices($connection);
    if ($s === [] || in_array('full', $s, true)) {
        return 'full';
    }

    return $s[0];
}

function hms_app_product_mode_label(string $mode): string
{
    $map = [
        'full' => 'Full suite (all modules)',
        'hms' => 'Hospital Management (HMS)',
        'accounting' => 'Core accounting (Cameroon)',
        'leave_attendance' => 'Leave & attendance',
        'tax_cameroon' => 'Tax (Cameroon)',
        'payroll' => 'Payroll (standalone)',
        'inventory' => 'Inventory & stock',
        'procurement' => 'Procurement',
    ];

    return $map[$mode] ?? $mode;
}

/**
 * @return array<string,bool>
 */
function hms_nav_sidebar_modules(mysqli $connection): array
{
    $keys = hms_nav_module_key_list();
    $all = array_fill_keys($keys, true);
    if (hms_is_super_admin() || !hms_app_settings_table_ready($connection)) {
        return $all;
    }

    if (function_exists('hms_active_deployment_profile_row') && function_exists('hms_deployment_profile_sidebar_mask')) {
        $pr = hms_active_deployment_profile_row($connection);
        if (is_array($pr)) {
            $mask = hms_deployment_profile_sidebar_mask($connection, $pr);
            if (is_array($mask)) {
                return $mask;
            }
        }
    }

    $slices = hms_app_product_slices($connection);
    if ($slices === [] || in_array('full', $slices, true)) {
        return $all;
    }

    return hms_nav_mask_from_slices($slices);
}

/**
 * When true, dashboard.php should render the stock-only hub (no patient / appointment queries).
 * Matches deployments where Manage → Inventory is on but Healthcare is off (e.g. inventory-only slice).
 */
function hms_dashboard_inventory_hub(mysqli $connection): bool
{
    if (function_exists('hms_is_super_admin') && hms_is_super_admin()) {
        return false;
    }
    if (!function_exists('hms_nav_sidebar_modules')) {
        return false;
    }
    $sn = hms_nav_sidebar_modules($connection);
    if (empty($sn['manage_inventory'])) {
        return false;
    }

    return empty($sn['healthcare']);
}

function hms_sidebar_manage_any_visible(array $sn): bool
{
    foreach ([
        'manage_catalog', 'manage_inventory', 'manage_procurement', 'manage_schedule', 'manage_departments',
        'manage_staff', 'manage_payroll', 'manage_leave_admin', 'manage_holidays', 'manage_self', 'access_control',
    ] as $k) {
        if (!empty($sn[$k])) {
            return true;
        }
    }

    return false;
}

function hms_require_super_admin(mysqli $connection): void
{
    if (empty($_SESSION['name'])) {
        header('Location: index.php');
        exit;
    }
    if (!hms_is_super_admin()) {
        http_response_code(403);
        exit('Forbidden: Super Admin only.');
    }
}

/**
 * Landing URL after login when a focused slice is selected (priority if several).
 */
function hms_product_mode_login_landing(mysqli $connection): ?string
{
    if (hms_is_super_admin() || !hms_app_settings_table_ready($connection)) {
        return null;
    }
    $slices = hms_app_product_slices($connection);
    if (in_array('full', $slices, true)) {
        return null;
    }

    // HMS before inventory so combined clinical + stock deploy lands on the main dashboard, not only stock.
    $priority = ['payroll', 'tax_cameroon', 'accounting', 'hms', 'leave_attendance', 'procurement', 'inventory'];
    foreach ($priority as $p) {
        if (in_array($p, $slices, true)) {
            switch ($p) {
                case 'payroll':
                    return 'payroll.php';
                case 'tax_cameroon':
                    return 'tax/tax-home.php';
                case 'accounting':
                    return 'financials.php';
                case 'inventory':
                    return 'inventory.php';
                case 'procurement':
                    return 'procurement-home.php';
                case 'leave_attendance':
                    return 'leave-requests.php';
                default:
                    return null;
            }
        }
    }

    return null;
}
