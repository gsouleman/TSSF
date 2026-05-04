<?php
declare(strict_types=1);

/**
 * Append-only audit log (gap 8). Safe if tbl_audit_log missing (migration not applied).
 */
function hms_audit_log(mysqli $connection, string $action, string $entity, ?int $entityId, ?array $payload = null): void
{
    static $checked = false;
    static $tableOk = false;
    if (!$checked) {
        $checked = true;
        $r = @mysqli_query($connection, "SHOW TABLES LIKE 'tbl_audit_log'");
        $tableOk = $r && mysqli_num_rows($r) > 0;
        if ($r) {
            mysqli_free_result($r);
        }
    }
    if (!$tableOk) {
        return;
    }
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    $fid = isset($_SESSION['facility_id']) ? (int) $_SESSION['facility_id'] : 0;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    $json = $payload !== null ? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    // mysqli_stmt_bind_param expects string refs; null can throw TypeError on PHP 8+.
    $ipBind = $ip !== null ? $ip : '';
    $uaBind = $ua !== null ? $ua : '';

    try {
        $stmt = mysqli_prepare(
            $connection,
            'INSERT INTO tbl_audit_log (user_id, facility_id, action, entity, entity_id, ip, user_agent, payload_json) VALUES (?,?,?,?,?,?,?,?)'
        );
        if (!$stmt) {
            return;
        }
        $eid = $entityId ?? 0;
        mysqli_stmt_bind_param($stmt, 'iississs', $uid, $fid, $action, $entity, $eid, $ipBind, $uaBind, $json);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (\Throwable $auditEx) {
        if (function_exists('error_log')) {
            error_log('hms_audit_log: ' . $auditEx->getMessage());
        }
    }
}
