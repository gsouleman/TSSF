<?php
declare(strict_types=1);

/**
 * HR module (pay profiles, leave, attendance) — schema checks and staff listing.
 * @see database/migrations/040_hr_payroll_leave_attendance.sql
 */
function hms_hr_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_hms_pay_profile')
        && hms_db_table_exists($connection, 'tbl_hms_leave_request')
        && hms_db_table_exists($connection, 'tbl_hms_leave_balance')
        && hms_db_table_exists($connection, 'tbl_hms_attendance')
        && hms_db_table_exists($connection, 'tbl_hms_holiday');
}

function hms_hr_is_admin(): bool
{
    $r = (string) ($_SESSION['role'] ?? '');

    return $r === '1' || $r === '99';
}

/** Badge HTML for tbl_employee.role (matches employees.php labels). */
function hms_hr_staff_role_badge_html(string $roleKey): string
{
    $roleLabels = [
        '1' => ['Admin', 'status-grey'],
        '2' => ['Doctor', 'status-red'],
        '3' => ['Front Desk', 'status-blue'],
        '4' => ['Lab Technician', 'status-purple'],
        '5' => ['Pharmacist', 'status-green'],
        '6' => ['Radiology Tech', 'status-blue'],
        '7' => ['Nurse', 'status-red'],
        '8' => ['Nursing Aid', 'status-green'],
        '99' => ['Super Admin', 'status-grey'],
    ];
    $roleKey = (string) $roleKey;
    if (isset($roleLabels[$roleKey])) {
        [$lab, $cls] = $roleLabels[$roleKey];

        return '<span class="custom-badge ' . hms_h($cls) . '">' . hms_h($lab) . '</span>';
    }

    return '<span class="text-muted small">' . hms_h($roleKey === '' ? '—' : $roleKey) . '</span>';
}

/**
 * Staff roster for the active site (same scope as employees.php): all employees
 * linked to the facility, plus any employee with no tbl_user_facility rows yet
 * so pay profiles / HR screens stay in sync with the directory.
 *
 * @return list<array{id: int, first_name: string, last_name: string, employee_id: string, role: string|int}>
 */
function hms_hr_active_staff_for_facility(mysqli $connection, int $facilityId): array
{
    $out = [];
    $ms = function_exists('hms_multi_site_enabled') && hms_multi_site_enabled($connection);
    if ($ms) {
        $sql = 'SELECT DISTINCT e.id, e.first_name, e.last_name, e.employee_id, e.`role` FROM tbl_employee e '
            . 'LEFT JOIN tbl_user_facility uf ON uf.employee_id = e.id AND uf.facility_id = ? '
            . 'WHERE uf.facility_id = ? OR NOT EXISTS (SELECT 1 FROM tbl_user_facility u0 WHERE u0.employee_id = e.id) '
            . 'ORDER BY e.last_name, e.first_name';
        $q = mysqli_prepare($connection, $sql);
        if ($q) {
            mysqli_stmt_bind_param($q, 'ii', $facilityId, $facilityId);
            mysqli_stmt_execute($q);
            $res = mysqli_stmt_get_result($q);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    $out[] = $row;
                }
            } elseif (function_exists('mysqli_stmt_store_result')) {
                // Hosts without mysqlnd: buffer and bind columns manually.
                mysqli_stmt_store_result($q);
                $meta = mysqli_stmt_result_metadata($q);
                if ($meta) {
                    $fields = mysqli_fetch_fields($meta);
                    $bind = [];
                    $row = [];
                    if (is_array($fields)) {
                        foreach ($fields as $f) {
                            $row[$f->name] = null;
                            $bind[] = &$row[$f->name];
                        }
                        call_user_func_array([$q, 'bind_result'], $bind);
                        while (mysqli_stmt_fetch($q)) {
                            $out[] = array_map(static function ($v) {
                                return $v;
                            }, $row);
                        }
                    }
                    mysqli_free_result($meta);
                }
            }
            mysqli_stmt_close($q);
        }
    } else {
        $res = mysqli_query(
            $connection,
            'SELECT id, first_name, last_name, employee_id, `role` FROM tbl_employee ORDER BY last_name, first_name'
        );
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $out[] = $row;
            }
        }
    }

    return $out;
}
