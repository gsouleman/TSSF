<?php
declare(strict_types=1);

/**
 * Fallback when an older functions.php is deployed without sidebar helpers.
 * Loaded from bootstrap.php only if hms_sidebar_section_show is missing.
 */
if (!function_exists('hms_nav_active')) {
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
}

if (!function_exists('hms_sidebar_section_show')) {
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
}
