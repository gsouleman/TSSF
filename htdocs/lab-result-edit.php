<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/laboratory_dreams.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'lab.write');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$hasUserFacilityTbl = hms_db_table_exists($connection, 'tbl_user_facility');
$tablesOk = hms_lab_result_table_ok($connection);
$id = (int) ($_GET['id'] ?? 0);

if (!$tablesOk || $id < 1) {
    header('Location: lab-results.php');
    exit;
}

$st = mysqli_prepare($connection, 'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id WHERE lr.id = ? AND lr.facility_id = ? LIMIT 1');
$row = null;
if ($st) {
    mysqli_stmt_bind_param($st, 'ii', $id, $fid);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
}
if (!$row) {
    header('Location: lab-results.php');
    exit;
}

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_lab_result'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $flash = 'Invalid security token.';
    } else {
        $refId = (int) ($_POST['referred_by_id'] ?? 0);
        $testName = trim((string) ($_POST['test_name'] ?? ''));
        $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'in_progress', 'received'], true)) {
            $status = 'pending';
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($testName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDate)) {
            $flash = 'Test name and appointment date are required.';
        } else {
            $ok = false;
            if ($refId > 0) {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_lab_result SET referred_by_id = ?, test_name = ?, appointment_date = ?, status = ?, notes = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'issssii', $refId, $testName, $apptDate, $status, $notes, $id, $fid);
                    $ok = mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            } else {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_lab_result SET referred_by_id = NULL, test_name = ?, appointment_date = ?, status = ?, notes = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'sssii', $testName, $apptDate, $status, $notes, $id, $fid);
                    $ok = mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            }
            if ($ok) {
                hms_audit_log($connection, 'lab_registry.update', 'lab_result', $id);
                $_SESSION['lab_registry_flash'] = 'Lab result updated.';
                header('Location: lab-results.php');
                exit;
            }
            $flash = 'Could not update.';
        }
    }
}

$doctorRows = [];
if ($ms && $hasUserFacilityTbl) {
    $drq = mysqli_query(
        $connection,
        'SELECT e.id, e.first_name, e.last_name FROM tbl_employee e
         INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
         WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ' . (int) $fid . ' ORDER BY e.last_name, e.first_name'
    );
} else {
    $drq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_employee WHERE role = 2 AND status = 1 ORDER BY last_name, first_name');
}
while ($drq && $er = mysqli_fetch_assoc($drq)) {
    $doctorRows[] = $er;
}

include __DIR__ . '/header.php';
$curRef = (int) ($row['referred_by_id'] ?? 0);
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-appts-dreams">
                <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-2">
                    <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="lab-results.php">Lab Results</a></li>
                        <li class="breadcrumb-item active">Edit</li>
                    </ol>
                </nav>
                <h1 class="hms-appts-dreams-title mb-3">Edit <?php echo hms_h(hms_lab_test_display_id($id)); ?></h1>
                <?php if ($flash !== '') { ?><div class="alert alert-warning"><?php echo hms_h($flash); ?></div><?php } ?>
                <div class="card border-0 shadow-sm" style="max-width:720px">
                    <div class="card-body">
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="save_lab_result" value="1">
                            <p class="text-muted small">Patient: <strong><?php echo hms_h(trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''))); ?></strong></p>
                            <div class="form-group">
                                <label for="labRef">Referred by</label>
                                <select name="referred_by_id" id="labRef" class="form-control">
                                    <option value="0">— None —</option>
                                    <?php foreach ($doctorRows as $dr) {
                                        $did = (int) $dr['id'];
                                        $sel = $did === $curRef ? ' selected' : '';
                                        $dn = trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']);
                                        ?>
                                    <option value="<?php echo $did; ?>"<?php echo $sel; ?>><?php echo hms_h($dn); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="test_name">Test name</label>
                                <input type="text" class="form-control" id="test_name" name="test_name" required value="<?php echo hms_h((string) ($row['test_name'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="appointment_date">Appointment date</label>
                                <input type="date" class="form-control" id="appointment_date" name="appointment_date" required value="<?php echo hms_h((string) ($row['appointment_date'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select class="form-control" id="status" name="status">
                                    <?php foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'received' => 'Received'] as $k => $lab) {
                                        $sel = ((string) ($row['status'] ?? '') === $k) ? ' selected' : '';
                                        echo '<option value="' . hms_h($k) . '"' . $sel . '>' . hms_h($lab) . '</option>';
                                    } ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo hms_h((string) ($row['notes'] ?? '')); ?></textarea>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="lab-results.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
