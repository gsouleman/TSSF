<?php
declare(strict_types=1);

function hms_db_table_exists(mysqli $connection, string $table): bool
{
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($t === '') {
        return false;
    }
    $r = @mysqli_query($connection, "SHOW TABLES LIKE '" . mysqli_real_escape_string($connection, $t) . "'");
    if (!$r) {
        return false;
    }
    $ok = mysqli_num_rows($r) > 0;
    mysqli_free_result($r);

    return $ok;
}

function hms_db_column_exists(mysqli $connection, string $table, string $column): bool
{
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $c = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($t === '' || $c === '') {
        return false;
    }
    $sql = "SHOW COLUMNS FROM `" . $t . "` LIKE '" . mysqli_real_escape_string($connection, $c) . "'";
    $r = @mysqli_query($connection, $sql);
    if (!$r) {
        return false;
    }
    $ok = mysqli_num_rows($r) > 0;
    mysqli_free_result($r);

    return $ok;
}

function hms_multi_site_enabled(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_facility')
        && hms_db_column_exists($connection, 'tbl_patient', 'facility_id');
}

function hms_current_facility_id(): int
{
    return (int) ($_SESSION['facility_id'] ?? 1);
}

/** Default primary site letterhead name (matches seed `tbl_facility` id=1). */
function hms_default_primary_facility_name(): string
{
    return 'TSSF Solidarity of Hearts Hospital SOA';
}

/** Display name for the current facility (for UI labels). */
function hms_current_facility_name(mysqli $connection): string
{
    $fid = hms_current_facility_id();
    if (!hms_db_table_exists($connection, 'tbl_facility')) {
        return 'Main site';
    }
    $stmt = mysqli_prepare($connection, 'SELECT name FROM tbl_facility WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return 'Site';
    }
    mysqli_stmt_bind_param($stmt, 'i', $fid);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);

        return 'Site';
    }
    $name = '';
    mysqli_stmt_bind_result($stmt, $name);
    if (!mysqli_stmt_fetch($stmt)) {
        $name = '';
    }
    mysqli_stmt_close($stmt);
    $name = trim((string) $name);

    return $name !== '' ? $name : ('Site #' . $fid);
}

/**
 * @return list<array{id:int,code:string,name:string}>
 */
function hms_user_facilities(mysqli $connection, int $employeeId): array
{
    if (!hms_db_table_exists($connection, 'tbl_user_facility')) {
        return [['id' => 1, 'code' => 'MAIN', 'name' => hms_default_primary_facility_name()]];
    }
    $sql = 'SELECT f.id, f.code, f.name FROM tbl_facility f
            INNER JOIN tbl_user_facility uf ON uf.facility_id = f.id
            WHERE uf.employee_id = ? AND f.status = 1
            ORDER BY f.name';
    $stmt = mysqli_prepare($connection, $sql);
    if (!$stmt) {
        return [['id' => 1, 'code' => 'MAIN', 'name' => hms_default_primary_facility_name()]];
    }
    mysqli_stmt_bind_param($stmt, 'i', $employeeId);
    mysqli_stmt_execute($stmt);
    $out = [];
    if (function_exists('mysqli_stmt_get_result')) {
        $res = @mysqli_stmt_get_result($stmt);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $out[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
            }
            mysqli_free_result($res);
        }
    } else {
        mysqli_stmt_store_result($stmt);
        $id = 0;
        $code = '';
        $name = '';
        mysqli_stmt_bind_result($stmt, $id, $code, $name);
        while (mysqli_stmt_fetch($stmt)) {
            $out[] = ['id' => (int) $id, 'code' => (string) $code, 'name' => (string) $name];
        }
    }
    mysqli_stmt_close($stmt);

    return $out !== [] ? $out : [['id' => 1, 'code' => 'MAIN', 'name' => hms_default_primary_facility_name()]];
}

function hms_facility_set_default_for_user(mysqli $connection, int $employeeId): void
{
    if (!hms_db_table_exists($connection, 'tbl_user_facility')) {
        $_SESSION['facility_id'] = 1;

        return;
    }
    $stmt = mysqli_prepare(
        $connection,
        'SELECT facility_id FROM tbl_user_facility WHERE employee_id = ? ORDER BY is_default DESC, facility_id ASC LIMIT 1'
    );
    if (!$stmt) {
        $_SESSION['facility_id'] = 1;

        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $employeeId);
    mysqli_stmt_execute($stmt);
    $fid = 1;
    mysqli_stmt_bind_result($stmt, $fid);
    if (mysqli_stmt_fetch($stmt)) {
        $_SESSION['facility_id'] = (int) $fid;
    } else {
        $_SESSION['facility_id'] = 1;
    }
    mysqli_stmt_close($stmt);
}

function hms_user_can_access_facility(mysqli $connection, int $employeeId, int $facilityId): bool
{
    if ($facilityId < 1) {
        return false;
    }
    if (!hms_db_table_exists($connection, 'tbl_user_facility')) {
        return $facilityId === 1;
    }
    $stmt = mysqli_prepare(
        $connection,
        'SELECT 1 FROM tbl_user_facility WHERE employee_id = ? AND facility_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ii', $employeeId, $facilityId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $ok = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    return $ok;
}

/** SQL fragment: AND facility_id = current (empty if single-site / legacy DB). */
function hms_sql_facility_clause(mysqli $connection, string $tableAlias = ''): string
{
    if (!hms_multi_site_enabled($connection)) {
        return '';
    }
    $col = ($tableAlias !== '' ? $tableAlias . '.' : '') . 'facility_id';

    return ' AND ' . $col . ' = ' . hms_current_facility_id();
}

function hms_assign_employee_to_facility(mysqli $connection, int $employeeId, int $facilityId, bool $isDefault = true): void
{
    if (!hms_db_table_exists($connection, 'tbl_user_facility')) {
        return;
    }
    if ($isDefault) {
        $u = mysqli_prepare($connection, 'UPDATE tbl_user_facility SET is_default = 0 WHERE employee_id = ?');
        if ($u) {
            mysqli_stmt_bind_param($u, 'i', $employeeId);
            mysqli_stmt_execute($u);
            mysqli_stmt_close($u);
        }
    }
    $stmt = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_user_facility (employee_id, facility_id, is_default) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_default = VALUES(is_default)'
    );
    if ($stmt) {
        $d = $isDefault ? 1 : 0;
        mysqli_stmt_bind_param($stmt, 'iii', $employeeId, $facilityId, $d);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
