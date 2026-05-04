<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }
hms_require_permission($connection, 'radiology.write');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_db_table_exists($connection, 'tbl_radiology_result');
$id = (int) ($_GET['id'] ?? 0);
$flash = '';

$modalities = ['X-Ray','Ultrasound','CT Scan','MRI','ECG','Echocardiography','Mammography','Fluoroscopy','DEXA','Other'];

// --- Handle POST update ---
if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_rad'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $examName = trim((string) ($_POST['exam_name'] ?? ''));
    $modality = trim((string) ($_POST['modality'] ?? 'X-Ray'));
    $bodyPart = trim((string) ($_POST['body_part'] ?? ''));
    $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'in_progress', 'received'], true)) $status = 'pending';
    $findings = trim((string) ($_POST['findings'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($id > 0 && $examName !== '') {
        $st = mysqli_prepare($connection,
            'UPDATE tbl_radiology_result SET exam_name=?, modality=?, body_part=?, appointment_date=?, status=?, findings=?, notes=? WHERE id=? AND facility_id=? LIMIT 1');
        if ($st) {
            $st->bind_param('sssssssii', $examName, $modality, $bodyPart, $apptDate, $status, $findings, $notes, $id, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'radiology.update', 'radiology_result', $id);
            $flash = 'Radiology result updated.';
        }
    }
}

// --- Fetch record ---
$row = null;
if ($tablesOk && $id > 0) {
    $st = mysqli_prepare($connection,
        'SELECT r.*, p.first_name AS p_fn, p.last_name AS p_ln, doc.first_name AS ref_fn, doc.last_name AS ref_ln
         FROM tbl_radiology_result r
         LEFT JOIN tbl_patient p ON p.id = r.patient_id
         LEFT JOIN tbl_employee doc ON doc.id = r.referred_by_id
         WHERE r.id = ? AND r.facility_id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('ii', $id, $fid);
        mysqli_stmt_execute($st);
        $row = hms_stmt_fetch_assoc($st);
        mysqli_stmt_close($st);
    }
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <?php
        hms_ui_page_header('Edit Radiology Result', [
            'subtitle' => 'Update exam details, findings, and status.',
            'breadcrumbs' => [['Radiology', 'radiology-results.php'], ['Edit', null]],
            'back' => 'radiology-results.php',
        ]);
        ?>

        <?php if (!$tablesOk) { ?>
        <div class="alert alert-warning">Run the radiology migration first.</div>
        <?php } elseif (!$row) { ?>
        <div class="alert alert-danger">Radiology result not found.</div>
        <?php } else { ?>

        <?php if ($flash !== '') { ?>
        <div class="alert alert-success border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>

        <div class="row justify-content-center">
            <div class="col-xl-9 col-lg-11">
                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="save_rad" value="1">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                    <div class="card-body">
                        <!-- Patient Info (read-only) -->
                        <div class="mb-3 p-3 rounded" style="background:#f1f5f9;">
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Patient</small>
                                    <strong><?php echo hms_h(trim(($row['p_fn'] ?? '').' '.($row['p_ln'] ?? ''))); ?></strong>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted d-block">Referred By</small>
                                    <strong><?php echo !empty($row['ref_fn']) ? hms_h('Dr. '.trim(($row['ref_fn'] ?? '').' '.($row['ref_ln'] ?? ''))) : '—'; ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="re_exam">Exam Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="re_exam" name="exam_name" required value="<?php echo hms_h((string)($row['exam_name'] ?? '')); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="re_mod">Modality</label>
                                    <select class="form-control" id="re_mod" name="modality">
                                        <?php foreach ($modalities as $mod) { ?>
                                        <option value="<?php echo hms_h($mod); ?>"<?php echo ($row['modality'] ?? '') === $mod ? ' selected' : ''; ?>><?php echo hms_h($mod); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="re_body">Body Part</label>
                                    <input type="text" class="form-control" id="re_body" name="body_part" value="<?php echo hms_h((string)($row['body_part'] ?? '')); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="re_date">Appointment Date</label>
                                    <input type="date" class="form-control" id="re_date" name="appointment_date" value="<?php echo hms_h((string)($row['appointment_date'] ?? '')); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="re_status">Status</label>
                                    <select class="form-control" id="re_status" name="status">
                                        <option value="pending"<?php echo ($row['status'] ?? '') === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                        <option value="in_progress"<?php echo ($row['status'] ?? '') === 'in_progress' ? ' selected' : ''; ?>>In Progress</option>
                                        <option value="received"<?php echo ($row['status'] ?? '') === 'received' ? ' selected' : ''; ?>>Received</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="re_findings">Findings / Report</label>
                            <textarea class="form-control" id="re_findings" name="findings" rows="5" placeholder="Enter radiologist's findings and interpretation..."><?php echo hms_h((string)($row['findings'] ?? '')); ?></textarea>
                        </div>
                        <div class="form-group mb-0">
                            <label for="re_notes">Notes</label>
                            <textarea class="form-control" id="re_notes" name="notes" rows="2" placeholder="Optional notes"><?php echo hms_h((string)($row['notes'] ?? '')); ?></textarea>
                        </div>
                    </div>
                    <div class="card-footer bg-light d-flex justify-content-end flex-wrap">
                        <a href="radiology-results.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                        <button type="submit" class="btn btn-primary mb-2 mb-sm-0" style="background:#0891b2;border-color:#0891b2;">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
