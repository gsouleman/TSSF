<?php
declare(strict_types=1);

/**
 * Optional Access Control UI (roles / portals). Enable when dedicated tables exist.
 */
function hms_show_access_control_nav(mysqli $connection): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return false;
    }

    return hms_db_table_exists($connection, 'tbl_access_control_policy');
}
