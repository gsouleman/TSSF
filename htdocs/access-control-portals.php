<?php
/**
 * Portal access per employee (Access Control).
 * Intentionally no declare(strict_types=1): avoids TypeError with mysqli on some shared hosts.
 */
require_once __DIR__ . '/includes/bootstrap.php';
if (!function_exists('hms_require_access_control_manage')) {
    require_once __DIR__ . '/includes/access_control.php';
}
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!isset($connection) || !($connection instanceof mysqli)) {
    http_response_code(503);
    exit('Database connection is not available.');
}
hms_require_access_control_manage($connection);

$msg = '';
$err = '';
$portals = hms_acl_portal_rows($connection);
if ($portals === array()) {
    $err = 'Portal definitions are missing. Run migration 019_access_control.sql.';
}

$fid = function_exists('hms_current_facility_id') ? (int) hms_current_facility_id() : 1;
$ms = function_exists('hms_multi_site_enabled') ? hms_multi_site_enabled($connection) : false;

$employees = array();
if ($err === '') {
    $eq = false;
    if ($ms && function_exists('hms_db_table_exists') && hms_db_table_exists($connection, 'tbl_user_facility')) {
        $eq = @mysqli_query(
            $connection,
            'SELECT DISTINCT e.id, e.first_name, e.last_name, e.username, e.role, e.status
             FROM tbl_employee e
             INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
             WHERE uf.facility_id = ' . (int) $fid . ' ORDER BY e.last_name ASC, e.first_name ASC'
        );
    }
    if (!$eq) {
        $eq = @mysqli_query(
            $connection,
            'SELECT id, first_name, last_name, username, role, status FROM tbl_employee ORDER BY last_name ASC, first_name ASC'
        );
    }
    if ($eq) {
        while ($e = mysqli_fetch_assoc($eq)) {
            $employees[] = $e;
        }
        mysqli_free_result($eq);
    }
}

$empId = (int) (isset($_REQUEST['employee_id']) ? $_REQUEST['employee_id'] : (isset($_GET['id']) ? $_GET['id'] : 0));
if ($empId < 1 && $employees !== array()) {
    $empId = (int) (isset($employees[0]['id']) ? $employees[0]['id'] : 0);
}

$validEmp = false;
$empLabel = '';
foreach ($employees as $e) {
    if ((int) (isset($e['id']) ? $e['id'] : 0) === $empId) {
        $validEmp = true;
        $empLabel = trim(
            (isset($e['first_name']) ? (string) $e['first_name'] : '') . ' ' . (isset($e['last_name']) ? (string) $e['last_name'] : '')
        ) . ' (' . (isset($e['username']) ? (string) $e['username'] : '') . ')';
        break;
    }
}

$checkedPortalIds = array();
if ($validEmp && function_exists('hms_access_control_portals_ready') && hms_access_control_portals_ready($connection)) {
    $eidEsc = (int) $empId;
    $rq = @mysqli_query(
        $connection,
        'SELECT portal_id FROM tbl_employee_portal WHERE employee_id = ' . $eidEsc
    );
    if ($rq) {
        while ($r = mysqli_fetch_assoc($rq)) {
            $checkedPortalIds[(int) (isset($r['portal_id']) ? $r['portal_id'] : 0)] = true;
        }
        mysqli_free_result($rq);
    }
}

if (
    (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
    && isset($_POST['save_portals'])
    && $err === ''
) {
    if (!function_exists('hms_csrf_validate') || !hms_csrf_validate(isset($_POST['hms_csrf']) ? $_POST['hms_csrf'] : null)) {
        $err = 'Invalid security token.';
    } else {
        $postEmp = (int) (isset($_POST['employee_id']) ? $_POST['employee_id'] : 0);
        $ok = false;
        foreach ($employees as $e) {
            if ((int) (isset($e['id']) ? $e['id'] : 0) === $postEmp) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $err = 'Invalid employee.';
        } elseif (!hms_access_control_portals_ready($connection)) {
            $err = 'Portal tables are not available.';
        } else {
            $picked = isset($_POST['portal_id']) ? $_POST['portal_id'] : array();
            if (!is_array($picked)) {
                $picked = array();
            }
            $portalIds = array();
            foreach ($picked as $x) {
                $portalIds[] = (int) $x;
            }
            $portalIds = array_values(array_unique($portalIds));
            $portalIdsTmp = array();
            foreach ($portalIds as $pv) {
                if ((int) $pv > 0) {
                    $portalIdsTmp[] = (int) $pv;
                }
            }
            $portalIds = $portalIdsTmp;

            $allowedIds = array();
            foreach ($portals as $p) {
                $allowedIds[] = (int) (isset($p['id']) ? $p['id'] : 0);
            }
            foreach ($portalIds as $pid) {
                if (!in_array($pid, $allowedIds, true)) {
                    $err = 'Invalid portal selection.';
                    break;
                }
            }
            if ($err === '') {
                mysqli_begin_transaction($connection);
                $saveOk = false;
                try {
                    $d = mysqli_prepare($connection, 'DELETE FROM tbl_employee_portal WHERE employee_id = ?');
                    if (!$d) {
                        throw new Exception('delete');
                    }
                    mysqli_stmt_bind_param($d, 'i', $postEmp);
                    mysqli_stmt_execute($d);
                    mysqli_stmt_close($d);

                    if ($portalIds !== array()) {
                        $ins = mysqli_prepare($connection, 'INSERT INTO tbl_employee_portal (employee_id, portal_id) VALUES (?, ?)');
                        if (!$ins) {
                            throw new Exception('insert');
                        }
                        foreach ($portalIds as $pvid) {
                            mysqli_stmt_bind_param($ins, 'ii', $postEmp, $pvid);
                            mysqli_stmt_execute($ins);
                        }
                        mysqli_stmt_close($ins);
                    }
                    mysqli_commit($connection);
                    $saveOk = true;
                } catch (Exception $e) {
                    mysqli_rollback($connection);
                    $err = 'Could not save portal access.';
                }
                if ($saveOk) {
                    $msg = 'Portal access saved. Ask the user to sign in again if they are currently logged in.';
                    if (function_exists('hms_audit_log')) {
                        hms_audit_log($connection, 'acl.employee_portals', 'employee', $postEmp, array('portal_ids' => $portalIds));
                    }
                    $empId = $postEmp;
                    $checkedPortalIds = array();
                    foreach ($portalIds as $pvid) {
                        $checkedPortalIds[$pvid] = true;
                    }
                }
            }
        }
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Portal access', array(
                    'subtitle' => 'Choose which staff portals each employee may open. Leave all unchecked to use the role default.',
                    'breadcrumbs' => array(
                        array('Access Control', 'access-control.php'),
                        array('Portals', null),
                    ),
                    'back' => 'access-control.php',
                ));
                ?>
                <?php if ($err !== '') { ?>
                <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
                <?php } elseif ($msg !== '') { ?>
                <div class="alert alert-success"><?php echo hms_h($msg); ?></div>
                <?php } ?>

                <?php if ($err === '' && $employees === array()) { ?>
                <div class="alert alert-warning">No employees found for this site.</div>
                <?php } elseif ($err === '') { ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <form method="get" class="form-inline mb-0">
                            <label for="ac_emp" class="mr-2 font-weight-bold text-muted small text-uppercase">Employee</label>
                            <select name="employee_id" id="ac_emp" class="form-control form-control-sm" onchange="this.form.submit()">
                                <?php foreach ($employees as $e) {
                                    $id = (int) (isset($e['id']) ? $e['id'] : 0);
                                    $st = (int) (isset($e['status']) ? $e['status'] : 0) === 1 ? '' : ' [inactive]';
                                    $sel = $id === $empId ? ' selected' : '';
                                    $lab = trim(
                                        (isset($e['first_name']) ? (string) $e['first_name'] : '') . ' ' . (isset($e['last_name']) ? (string) $e['last_name'] : '')
                                    ) . ' — ' . (isset($e['username']) ? (string) $e['username'] : '') . $st; ?>
                                <option value="<?php echo (int) $id; ?>"<?php echo $sel; ?>><?php echo hms_h($lab); ?></option>
                                <?php } ?>
                            </select>
                        </form>
                    </div>
                </div>

                <?php if ($validEmp) { ?>
                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="employee_id" value="<?php echo (int) $empId; ?>">
                    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="font-weight-bold">Allowed portals</span>
                            <div class="text-muted small"><?php echo hms_h($empLabel); ?></div>
                        </div>
                        <button type="submit" name="save_portals" value="1" class="btn btn-primary btn-sm font-weight-bold">Save</button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($portals as $p) {
                                $pid = (int) (isset($p['id']) ? $p['id'] : 0);
                                $chk = isset($checkedPortalIds[$pid]) ? ' checked' : ''; ?>
                            <div class="col-md-6 mb-2">
                                <div class="custom-control custom-checkbox">
                                    <input class="custom-control-input" type="checkbox" name="portal_id[]" value="<?php echo (int) $pid; ?>" id="pv<?php echo (int) $pid; ?>"<?php echo $chk; ?>>
                                    <label class="custom-control-label font-weight-bold" for="pv<?php echo (int) $pid; ?>"><?php echo hms_h((string) (isset($p['label']) ? $p['label'] : '')); ?></label>
                                    <div class="text-muted small ml-4"><?php echo hms_h((string) (isset($p['entry_script']) ? $p['entry_script'] : '')); ?></div>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                        <p class="text-muted small border-top pt-3 mb-0">If <strong>no</strong> box is checked, this employee follows the <em>role</em> default portals until you assign at least one here.</p>
                    </div>
                </form>
                <?php } ?>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
