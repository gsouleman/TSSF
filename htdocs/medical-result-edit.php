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
$tablesOk = hms_medical_result_table_ok($connection);
$id = (int) ($_GET['id'] ?? 0);

if (!$tablesOk || $id < 1) {
    header('Location: medical-results.php');
    exit;
}

$st = mysqli_prepare($connection, 'SELECT mr.*, p.first_name AS p_fn, p.last_name AS p_ln FROM tbl_medical_result mr INNER JOIN tbl_patient p ON p.id = mr.patient_id WHERE mr.id = ? AND mr.facility_id = ? LIMIT 1');
$row = null;
if ($st) {
    mysqli_stmt_bind_param($st, 'ii', $id, $fid);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
}
if (!$row) {
    header('Location: medical-results.php');
    exit;
}

$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_medical_result'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $flash = 'Invalid security token.';
    } else {
        $recordName = trim((string) ($_POST['record_name'] ?? ''));
        $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($recordName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDate)) {
            $flash = 'Record name and appointment date are required.';
        } else {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_medical_result SET record_name = ?, appointment_date = ?, notes = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                mysqli_stmt_bind_param($up, 'sssii', $recordName, $apptDate, $notes, $id, $fid);
                if (mysqli_stmt_execute($up)) {
                    hms_audit_log($connection, 'medical_registry.update', 'medical_result', $id);
                    $_SESSION['lab_registry_flash'] = 'Medical result updated.';
                    header('Location: medical-results.php');
                    exit;
                }
                mysqli_stmt_close($up);
            }
            $flash = 'Could not update.';
        }
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-appts-dreams">
                <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-2">
                    <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                        <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="medical-results.php">Medical Results</a></li>
                        <li class="breadcrumb-item active">Edit</li>
                    </ol>
                </nav>
                <h1 class="hms-appts-dreams-title mb-3">Edit <?php echo hms_h(hms_medical_record_display_id($id)); ?></h1>
                <?php if ($flash !== '') { ?><div class="alert alert-warning"><?php echo hms_h($flash); ?></div><?php } ?>
                <div class="card border-0 shadow-sm" style="max-width:720px">
                    <div class="card-body">
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="save_medical_result" value="1">
                            <p class="text-muted small">Patient: <strong><?php echo hms_h(trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''))); ?></strong></p>
                            <div class="form-group">
                                <label for="record_name">Record</label>
                                <input type="text" class="form-control" id="record_name" name="record_name" required value="<?php echo hms_h((string) ($row['record_name'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="appointment_date">Appointment date</label>
                                <input type="date" class="form-control" id="appointment_date" name="appointment_date" required value="<?php echo hms_h((string) ($row['appointment_date'] ?? '')); ?>">
                            </div>
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo hms_h((string) ($row['notes'] ?? '')); ?></textarea>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="medical-results.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
