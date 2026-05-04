<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (!function_exists('hms_require_access_control_manage')) {
    require_once __DIR__ . '/includes/access_control.php';
}
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!isset($connection) || !$connection instanceof mysqli) {
    http_response_code(503);
    exit('Database connection is not available.');
}
hms_require_access_control_manage($connection);

$msg = '';
$err = '';

if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
    $err = 'RBAC tables are not installed. Run database migrations (001_multi_site_platform.sql) first.';
}

$roles = [];
$rq = mysqli_query($connection, 'SELECT title, role FROM tbl_role ORDER BY CAST(role AS UNSIGNED) ASC');
if ($rq) {
    while ($r = mysqli_fetch_assoc($rq)) {
        $rv = (string) ($r['role'] ?? '');
        if ($rv === '1') {
            continue;
        }
        $roles[] = ['title' => (string) ($r['title'] ?? ''), 'role' => $rv];
    }
    mysqli_free_result($rq);
}

$selectedRole = (string) ($_GET['role'] ?? ($roles[0]['role'] ?? ''));
$validRole = false;
foreach ($roles as $r) {
    if ($r['role'] === $selectedRole) {
        $validRole = true;
        break;
    }
}
if (!$validRole && $roles !== []) {
    $selectedRole = (string) $roles[0]['role'];
    $validRole = true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_role_perms']) && $err === '') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $postRole = (string) ($_POST['target_role'] ?? '');
        $okRole = false;
        foreach ($roles as $r) {
            if ($r['role'] === $postRole) {
                $okRole = true;
                break;
            }
        }
        if (!$okRole) {
            $err = 'Invalid role.';
        } else {
            $ids = $_POST['perm'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $permIds = [];
            foreach ($ids as $id) {
                $permIds[] = (int) $id;
            }
            $permIds = array_values(array_unique($permIds));
            $permIds = array_filter($permIds, static function ($i) {
                return (int) $i > 0;
            });
            $permIds = array_values(array_map('intval', $permIds));

            if ($permIds !== []) {
                $in = implode(',', $permIds);
                $cq = mysqli_query(
                    $connection,
                    "SELECT COUNT(*) AS c FROM tbl_acl_permission WHERE id IN ($in) AND code <> 'access_control.manage'"
                );
                $cnt = $cq ? (int) (mysqli_fetch_assoc($cq)['c'] ?? 0) : 0;
                if ($cnt !== count($permIds)) {
                    $err = 'Invalid permission selection.';
                }
            }

            if ($err !== '') {
                // skip DB write
            } else {
            mysqli_begin_transaction($connection);
            try {
                $stmtDel = mysqli_prepare($connection, 'DELETE FROM tbl_acl_role_permission WHERE role = ?');
                if (!$stmtDel) {
                    throw new RuntimeException('Could not prepare delete.');
                }
                mysqli_stmt_bind_param($stmtDel, 's', $postRole);
                mysqli_stmt_execute($stmtDel);
                mysqli_stmt_close($stmtDel);

                if ($permIds !== []) {
                    $stmtIns = mysqli_prepare($connection, 'INSERT IGNORE INTO tbl_acl_role_permission (role, permission_id) VALUES (?, ?)');
                    if (!$stmtIns) {
                        throw new RuntimeException('Could not prepare insert.');
                    }
                    foreach ($permIds as $pid) {
                        mysqli_stmt_bind_param($stmtIns, 'si', $postRole, $pid);
                        mysqli_stmt_execute($stmtIns);
                    }
                    mysqli_stmt_close($stmtIns);
                }
                mysqli_commit($connection);
                $msg = 'Permissions updated for this role.';
                hms_audit_log($connection, 'acl.role_permissions', 'role', (int) $postRole, ['permission_ids' => $permIds]);
                $selectedRole = $postRole;
            } catch (Throwable $e) {
                mysqli_rollback($connection);
                $err = 'Could not save permissions.';
            }
            }
        }
    }
}

$permissions = [];
if ($err === '' && hms_db_table_exists($connection, 'tbl_acl_permission')) {
    $pq = mysqli_query(
        $connection,
        "SELECT id, code, label FROM tbl_acl_permission WHERE code <> 'access_control.manage' ORDER BY label ASC"
    );
    if ($pq) {
        while ($p = mysqli_fetch_assoc($pq)) {
            $permissions[] = $p;
        }
        mysqli_free_result($pq);
    }
}

$assigned = [];
if ($selectedRole !== '' && hms_db_table_exists($connection, 'tbl_acl_role_permission')) {
    $aq = mysqli_prepare(
        $connection,
        'SELECT permission_id FROM tbl_acl_role_permission WHERE role = ?'
    );
    if ($aq) {
        mysqli_stmt_bind_param($aq, 's', $selectedRole);
        mysqli_stmt_execute($aq);
        mysqli_stmt_bind_result($aq, $pid);
        while (mysqli_stmt_fetch($aq)) {
            $assigned[(int) $pid] = true;
        }
        mysqli_stmt_close($aq);
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Roles & permissions', [
                    'subtitle' => 'Grant application capabilities by staff role (Administrator has all permissions automatically).',
                    'breadcrumbs' => [['Access Control', 'access-control.php'], ['Roles & permissions', null]],
                    'back' => 'access-control.php',
                ]);
                ?>
                <?php if ($err !== '') { ?>
                <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
                <?php } elseif ($msg !== '') { ?>
                <div class="alert alert-success"><?php echo hms_h($msg); ?></div>
                <?php } ?>

                <?php if ($err === '' && $roles === []) { ?>
                <div class="alert alert-warning">No editable roles found (only Administrator may be defined).</div>
                <?php } elseif ($err === '') { ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body py-3">
                        <form method="get" class="form-inline mb-0">
                            <label for="ac_role_pick" class="mr-2 font-weight-bold text-muted small text-uppercase">Role</label>
                            <select name="role" id="ac_role_pick" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($roles as $r) {
                                    $sel = $r['role'] === $selectedRole ? ' selected' : ''; ?>
                                <option value="<?php echo hms_h($r['role']); ?>"<?php echo $sel; ?>><?php echo hms_h($r['title'] . ' (' . $r['role'] . ')'); ?></option>
                                <?php } ?>
                            </select>
                        </form>
                    </div>
                </div>

                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="target_role" value="<?php echo hms_h($selectedRole); ?>">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap">
                        <span class="font-weight-bold">Permissions for this role</span>
                        <button type="submit" name="save_role_perms" value="1" class="btn btn-primary btn-sm font-weight-bold">Save</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 small">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width:3rem;"></th>
                                        <th>Label</th>
                                        <th>Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($permissions as $p) {
                                        $id = (int) ($p['id'] ?? 0);
                                        $chk = isset($assigned[$id]) ? ' checked' : ''; ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="perm[]" value="<?php echo $id; ?>"<?php echo $chk; ?>>
                                        </td>
                                        <td><?php echo hms_h((string) ($p['label'] ?? '')); ?></td>
                                        <td><code><?php echo hms_h((string) ($p['code'] ?? '')); ?></code></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </form>
                <p class="text-muted small mt-3 mb-0">The <strong>Access Control</strong> administration permission is only available to the built-in Administrator role and is not listed here.</p>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
