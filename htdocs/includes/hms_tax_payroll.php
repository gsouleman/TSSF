<?php
declare(strict_types=1);

/**
 * Cameroon tax payroll module (CNPS DIPE / DGI aids) — table checks.
 * @see database/migrations/039_tax_payroll_cnps_dipe.sql
 */
function hms_tax_payroll_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_hms_payroll_settings')
        && hms_db_table_exists($connection, 'tbl_hms_payroll_record')
        && hms_db_table_exists($connection, 'tbl_hms_dipe_history');
}
