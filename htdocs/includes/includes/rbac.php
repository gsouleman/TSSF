<?php
declare(strict_types=1);

/**
 * Permission check (gap 8). Admin role "1" has all permissions.
 */
function hms_can(mysqli $connection, string $permissionCode): bool
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }
    $role = (string) ($_SESSION['role'] ?? '');
    if ($role === '1' || $role === '99') {
        return true;
    }
    static $cache = [];
    $key = $role . '|' . $permissionCode;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = mysqli_prepare(
        $connection,
        'SELECT 1 FROM tbl_acl_role_permission rp
         INNER JOIN tbl_acl_permission p ON p.id = rp.permission_id
         WHERE rp.role = ? AND p.code = ? LIMIT 1'
    );
    if (!$stmt) {
        $cache[$key] = false;

        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $role, $permissionCode);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $ok = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    $cache[$key] = $ok;

    return $ok;
}

function hms_require_permission(mysqli $connection, string $permissionCode): void
{
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return;
    }
    if (!hms_can($connection, $permissionCode)) {
        http_response_code(403);
        exit('Forbidden: missing permission ' . hms_h($permissionCode));
    }
}
