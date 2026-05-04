<?php
/**
 * Access Control — Patient portal accounts (enable/disable, password reset).
 * No declare(strict_types): shared-host compatibility.
 */
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

$fid = function_exists('hms_current_facility_id') ? (int) hms_current_facility_id() : 1;
$ms = function_exists('hms_multi_site_enabled') ? hms_multi_site_enabled($connection) : false;
$portalReady = function_exists('hms_patient_portal_ready') && hms_patient_portal_ready($connection);

$msg = '';
$err = '';

if ($portalReady && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (isset($_POST['portal_disable'])) {
        $pid = (int) ($_POST['patient_id'] ?? 0);
        if ($pid < 1) {
            $err = 'Invalid patient.';
        } elseif (hms_patient_portal_access_control_disable($connection, $pid, $fid, $ms)) {
            $msg = 'Portal access disabled for patient #' . $pid . '.';
        } else {
            $err = 'Could not disable portal (patient not found or portal not configured).';
        }
    } elseif (isset($_POST['portal_reset_password'])) {
        $pid = (int) ($_POST['patient_id'] ?? 0);
        $p1 = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
        $p2 = isset($_POST['new_password2']) && is_string($_POST['new_password2']) ? $_POST['new_password2'] : '';
        if ($pid < 1) {
            $err = 'Invalid patient.';
        } elseif ($p1 !== $p2) {
            $err = 'Password confirmation does not match.';
        } else {
            $res = hms_patient_portal_access_control_set_password($connection, $pid, $fid, $ms, $p1);
            if (!empty($res['ok'])) {
                $msg = 'Portal password updated for patient #' . $pid . '. They can sign in with their email and the new password.';
            } else {
                $err = (string) ($res['error'] ?? 'Could not set password.');
            }
        }
    }
}

$searchRaw = $_GET['q'] ?? '';
$search = is_string($searchRaw) ? trim($searchRaw) : '';

$rows = [];
if ($portalReady) {
    $sql = 'SELECT id, first_name, last_name, email, portal_enabled, portal_password_hash, status FROM tbl_patient WHERE 1=1';
    if ($ms && function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_patient', 'facility_id')) {
        $sql .= ' AND facility_id = ' . (int) $fid;
    }
    if ($search !== '') {
        $esc = mysqli_real_escape_string($connection, $search);
        $sql .= " AND (CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE '%{$esc}%'
            OR COALESCE(email,'') LIKE '%{$esc}%'";
        $idOnly = preg_replace('/\D+/', '', $search);
        if ($idOnly !== '' && ctype_digit($idOnly)) {
            $sql .= ' OR id = ' . (int) $idOnly;
        }
        $sql .= ')';
    }
    $sql .= ' ORDER BY last_name ASC, first_name ASC, id ASC LIMIT 150';
    $q = mysqli_query($connection, $sql);
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $rows[] = $r;
        }
        mysqli_free_result($q);
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Patient portal management', [
                    'subtitle' => 'Reset portal passwords and enable or disable online sign-in for patients (same credentials as patient-portal-login).',
                    'breadcrumbs' => [['Access Control', 'access-control.php'], ['Patient portal', null]],
                    'back' => 'access-control.php',
                ]);
                ?>
                <?php if ($msg !== '') { ?>
                <div class="alert alert-success"><?php echo hms_h($msg); ?></div>
                <?php } ?>
                <?php if ($err !== '') { ?>
                <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
                <?php } ?>

                <?php if (!$portalReady) { ?>
                <div class="alert alert-warning">
                    Patient portal database columns are missing. Run <code>002_patient_portal.sql</code> on this database, then return here.
                </div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3">
                    <div class="card-body row align-items-end">
                        <div class="col-md-6">
                            <label class="small font-weight-bold text-muted">Search by name, email, or patient ID</label>
                            <input type="text" name="q" class="form-control" value="<?php echo hms_h($search); ?>" placeholder="e.g. Dupont or @gmail.com or 42">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-outline-primary btn-sm font-weight-bold">Search</button>
                            <?php if ($search !== '') { ?>
                            <a href="access-control-patient-portal.php" class="btn btn-link btn-sm">Clear</a>
                            <?php } ?>
                        </div>
                    </div>
                </form>

                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Patient</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Portal</th>
                                    <th style="min-width:220px">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r) {
                                    $pid = (int) ($r['id'] ?? 0);
                                    $pen = (int) ($r['portal_enabled'] ?? 0);
                                    $hasPw = !empty($r['portal_password_hash']);
                                    $st = (int) ($r['status'] ?? 0);
                                    $em = trim((string) ($r['email'] ?? ''));
                                    ?>
                                <tr>
                                    <td><?php echo $pid; ?></td>
                                    <td><?php echo hms_h(trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''))); ?></td>
                                    <td><?php echo hms_h($em !== '' ? $em : '—'); ?></td>
                                    <td><?php echo $st === 1 ? '<span class="text-success">Active</span>' : '<span class="text-muted">Inactive</span>'; ?></td>
                                    <td>
                                        <?php if ($em === '') { ?>
                                        <span class="text-warning small">No email</span>
                                        <?php } elseif ($pen === 1) { ?>
                                        <span class="text-success">On</span><?php echo $hasPw ? '' : ' <span class="text-danger small">(no password)</span>'; ?>
                                        <?php } else { ?>
                                        <span class="text-muted">Off</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if ($em !== '') { ?>
                                        <form method="post" class="mb-2">
                                            <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                                            <input type="hidden" name="patient_id" value="<?php echo $pid; ?>">
                                            <div class="form-row">
                                                <div class="col pr-1">
                                                    <input type="password" name="new_password" class="form-control form-control-sm" autocomplete="new-password" placeholder="New password (min 8)" minlength="8" required>
                                                </div>
                                                <div class="col pl-0">
                                                    <input type="password" name="new_password2" class="form-control form-control-sm" autocomplete="new-password" placeholder="Confirm" minlength="8" required>
                                                </div>
                                            </div>
                                            <button type="submit" name="portal_reset_password" value="1" class="btn btn-sm btn-primary font-weight-bold mt-1">Set / reset password</button>
                                        </form>
                                        <?php if ($pen === 1) { ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Disable patient portal sign-in for this record?');">
                                            <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                                            <input type="hidden" name="patient_id" value="<?php echo $pid; ?>">
                                            <button type="submit" name="portal_disable" value="1" class="btn btn-sm btn-outline-danger">Disable portal</button>
                                        </form>
                                        <?php } ?>
                                        <?php } else { ?>
                                        <span class="text-muted small">Add an email on the patient chart before using the portal.</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Share the patient sign-in link: <a href="patient-portal-login.php">patient-portal-login.php</a>. Communicate the new password through a secure channel.</p>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
