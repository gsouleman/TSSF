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
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_lab_result_table_ok($connection);
$id = (int) ($_GET['id'] ?? 0);
$hasTplCol = hms_db_column_exists($connection, 'tbl_lab_result', 'result_template_json');
$hasConcCol = hms_db_column_exists($connection, 'tbl_lab_result', 'conclusion_code');
$patientEmailSelect = hms_db_column_exists($connection, 'tbl_patient', 'email') ? ', p.email AS p_email' : '';

$st = mysqli_prepare(
    $connection,
    'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . '
     FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id
     WHERE lr.id = ? AND lr.facility_id = ? LIMIT 1'
);
$row = null;
if ($st && $tablesOk && $id > 0) {
    mysqli_stmt_bind_param($st, 'ii', $id, $fid);
    mysqli_stmt_execute($st);
    $row = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
}

$flash = '';

if ($row && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (isset($_POST['save_lab_workflow'])) {
        $tplJson = (string) ($_POST['result_template_json'] ?? '');
        if ($tplJson === '' || json_decode($tplJson) === null) {
            $tplPayload = ['rows' => [], 'additional_notes' => trim((string) ($_POST['additional_notes'] ?? ''))];
            $labels = $_POST['tpl_label'] ?? [];
            $vals = $_POST['tpl_value'] ?? [];
            if (is_array($labels) && is_array($vals)) {
                $n = max(count($labels), count($vals));
                for ($i = 0; $i < $n; $i++) {
                    $lb = trim((string) ($labels[$i] ?? ''));
                    $vl = trim((string) ($vals[$i] ?? ''));
                    if ($lb !== '' || $vl !== '') {
                        $tplPayload['rows'][] = ['label' => $lb, 'value' => $vl];
                    }
                }
            }
            $tplJson = json_encode($tplPayload, JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $conc = trim((string) ($_POST['conclusion_code'] ?? ''));
        if ($hasTplCol && $hasConcCol) {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_lab_result SET result_template_json = ?, notes = ?, conclusion_code = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                mysqli_stmt_bind_param($up, 'sssii', $tplJson, $notes, $conc, $id, $fid);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
                $flash = 'Progress saved.';
            }
        } else {
            $up = mysqli_prepare(
                $connection,
                'UPDATE tbl_lab_result SET notes = ? WHERE id = ? AND facility_id = ? LIMIT 1'
            );
            if ($up) {
                mysqli_stmt_bind_param($up, 'sii', $notes, $id, $fid);
                mysqli_stmt_execute($up);
                mysqli_stmt_close($up);
                $flash = 'Notes saved.';
            }
        }
        $st2 = mysqli_prepare(
            $connection,
            'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . ' FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id WHERE lr.id = ? AND lr.facility_id = ? LIMIT 1'
        );
        if ($st2) {
            mysqli_stmt_bind_param($st2, 'ii', $id, $fid);
            mysqli_stmt_execute($st2);
            $row = hms_stmt_fetch_assoc($st2);
            mysqli_stmt_close($st2);
        }
    } elseif (isset($_POST['finalize_lab_workflow'])) {
        $tplJson = (string) ($_POST['result_template_json'] ?? '');
        if ($tplJson === '' || json_decode($tplJson) === null) {
            $tplPayload = ['rows' => [], 'additional_notes' => trim((string) ($_POST['additional_notes'] ?? ''))];
            $labels = $_POST['tpl_label'] ?? [];
            $vals = $_POST['tpl_value'] ?? [];
            if (is_array($labels) && is_array($vals)) {
                $n = max(count($labels), count($vals));
                for ($i = 0; $i < $n; $i++) {
                    $lb = trim((string) ($labels[$i] ?? ''));
                    $vl = trim((string) ($vals[$i] ?? ''));
                    if ($lb !== '' || $vl !== '') {
                        $tplPayload['rows'][] = ['label' => $lb, 'value' => $vl];
                    }
                }
            }
            $tplJson = json_encode($tplPayload, JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $conc = trim((string) ($_POST['conclusion_code'] ?? ''));
        if ($conc === '') {
            $flash = 'Select a conclusion before finalizing.';
        } else {
            $status = 'received';
            if ($hasTplCol && $hasConcCol) {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_lab_result SET result_template_json = ?, notes = ?, conclusion_code = ?, status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'ssssii', $tplJson, $notes, $conc, $status, $id, $fid);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            } else {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_lab_result SET notes = ?, status = ? WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'ssii', $notes, $status, $id, $fid);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }
            }
            $st2 = mysqli_prepare(
                $connection,
                'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln' . $patientEmailSelect . ' FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id WHERE lr.id = ? AND lr.facility_id = ? LIMIT 1'
            );
            if ($st2) {
                mysqli_stmt_bind_param($st2, 'ii', $id, $fid);
                mysqli_stmt_execute($st2);
                $row = hms_stmt_fetch_assoc($st2);
                mysqli_stmt_close($st2);
            }
            if ($row) {
                $summary = hms_lab_template_summary_text(
                    $hasTplCol ? (string) ($row['result_template_json'] ?? '') : $notes,
                    $hasConcCol ? (string) ($row['conclusion_code'] ?? $conc) : $conc
                );
                $tc = trim((string) ($row['payment_ticket_code'] ?? ''));
                $cid = (int) ($row['patient_id'] ?? 0);
                $refFromConsult = 0;
                if ($tc !== '' && hms_payment_ticket_tables_ok($connection)) {
                    $tq = mysqli_prepare($connection, 'SELECT consultation_id FROM tbl_payment_ticket WHERE ticket_code = ? AND facility_id = ? LIMIT 1');
                    if ($tq) {
                        mysqli_stmt_bind_param($tq, 'si', $tc, $fid);
                        mysqli_stmt_execute($tq);
                        $tr = hms_stmt_fetch_assoc($tq);
                        mysqli_stmt_close($tq);
                        $consId = (int) ($tr['consultation_id'] ?? 0);
                        $refFromConsult = hms_consultation_referrer_employee_id($connection, $fid, $consId);
                    }
                }
                $referrer = (int) ($row['referred_by_id'] ?? 0);
                $doctorEmp = $referrer > 0 ? $referrer : $refFromConsult;
                hms_result_workflow_publish_notices(
                    $connection,
                    $fid,
                    $cid,
                    $doctorEmp,
                    $id,
                    null,
                    $tc,
                    (string) ($row['test_name'] ?? 'Laboratory'),
                    $summary,
                    $hasConcCol ? (string) ($row['conclusion_code'] ?? $conc) : $conc
                );
                hms_audit_log($connection, 'lab.workflow.finalize', 'lab_result', $id);
            }
            $_SESSION['lab_registry_flash'] = 'Result finalized. Patient and referring clinician have been notified (portal and email when configured).';
            header('Location: lab-results.php');
            exit;
        }
    }
}

include __DIR__ . '/header.php';

if (!$tablesOk || !$row) {
    ?>
        <div class="page-wrapper"><div class="content hms-module">
            <div class="alert alert-warning border-0 shadow-sm">Record not found or lab module unavailable.</div>
            <a href="lab-results.php" class="btn btn-outline-secondary">Back</a>
        </div></div>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

$tplData = ['rows' => [['label' => '', 'value' => '']], 'additional_notes' => ''];
if ($hasTplCol && trim((string) ($row['result_template_json'] ?? '')) !== '') {
    $pj = json_decode((string) $row['result_template_json'], true);
    if (is_array($pj)) {
        $tplData = array_merge($tplData, $pj);
        if (empty($tplData['rows']) || !is_array($tplData['rows'])) {
            $tplData['rows'] = [['label' => '', 'value' => '']];
        }
    }
}
$concCur = $hasConcCol ? (string) ($row['conclusion_code'] ?? '') : '';
$statusCur = (string) ($row['status'] ?? '');
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#8b5cf6 0%,#1a6bd8 100%);color:#fff;">
                    <div class="card-body py-4">
                        <h1 class="h4 font-weight-bold mb-1" style="color:#fff;">Laboratory result workspace</h1>
                        <p class="mb-0 small" style="color:rgba(255,255,255,.9);"><?php echo hms_h(hms_lab_test_display_id($id)); ?> — <?php echo hms_h((string) ($row['test_name'] ?? '')); ?></p>
                    </div>
                </div>
                <?php if ($flash !== '') { ?><div class="alert alert-success border-0 shadow-sm"><?php echo hms_h($flash); ?></div><?php } ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted d-block">Patient</small>
                                <strong><?php echo hms_h(trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''))); ?></strong>
                                <?php if (trim((string) ($row['payment_ticket_code'] ?? '')) !== '') { ?>
                                <span class="badge badge-light border ml-1"><?php echo hms_h((string) $row['payment_ticket_code']); ?></span>
                                <?php } ?>
                            </div>
                            <div class="col-md-6 text-md-right">
                                <small class="text-muted d-block">Status</small>
                                <span class="badge badge-info"><?php echo hms_h($statusCur); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <div class="card-body">
                        <?php if ($hasTplCol) { ?>
                        <h2 class="h6 font-weight-bold text-uppercase text-muted mb-3">Result parameters</h2>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm" id="hmsLabTplTable">
                                <thead class="thead-light"><tr><th>Parameter</th><th>Value</th><th></th></tr></thead>
                                <tbody>
                                    <?php foreach ($tplData['rows'] as $idx => $r) {
                                        if (!is_array($r)) {
                                            continue;
                                        }
                                        ?>
                                    <tr>
                                        <td><input type="text" class="form-control form-control-sm" name="tpl_label[]" value="<?php echo hms_h((string) ($r['label'] ?? '')); ?>" placeholder="Label"></td>
                                        <td><input type="text" class="form-control form-control-sm" name="tpl_value[]" value="<?php echo hms_h((string) ($r['value'] ?? '')); ?>" placeholder="Value"></td>
                                        <td class="text-nowrap"></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="hmsLabAddRow">Add parameter row</button>
                        <div class="form-group">
                            <label class="small font-weight-bold">Additional notes</label>
                            <textarea class="form-control" name="additional_notes" rows="2" placeholder="Method, comments"><?php echo hms_h((string) ($tplData['additional_notes'] ?? '')); ?></textarea>
                        </div>
                        <?php } ?>
                        <div class="form-group">
                            <label class="small font-weight-bold">Laboratory notes</label>
                            <textarea class="form-control" name="notes" rows="3"><?php echo hms_h((string) ($row['notes'] ?? '')); ?></textarea>
                        </div>
                        <?php if ($hasConcCol) { ?>
                        <div class="form-group">
                            <label class="small font-weight-bold" for="labConc">Conclusion</label>
                            <select class="form-control" name="conclusion_code" id="labConc">
                                <option value="">— Select —</option>
                                <?php foreach (['negative' => 'Negative / within reference', 'positive' => 'Positive / abnormal', 'inconclusive' => 'Inconclusive', 'other' => 'Other (see notes)'] as $cv => $cl) {
                                    $sel = $concCur === $cv ? ' selected' : '';
                                    ?>
                                <option value="<?php echo hms_h($cv); ?>"<?php echo $sel; ?>><?php echo hms_h($cl); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="card-footer bg-light d-flex flex-wrap justify-content-between">
                        <a href="lab-results.php" class="btn btn-outline-secondary mb-2">Cancel</a>
                        <div>
                            <button type="submit" name="save_lab_workflow" value="1" class="btn btn-outline-primary mb-2 mr-2">Save progress</button>
                            <?php if ($hasConcCol) { ?>
                            <button type="submit" name="finalize_lab_workflow" value="1" class="btn btn-success mb-2">Finalize &amp; notify patient &amp; doctor</button>
                            <?php } ?>
                        </div>
                    </div>
                </form>
                <p class="small text-muted">Finalizing sets status to <strong>received</strong>, records portal notices for the patient and referring doctor, and sends email when addresses exist.</p>
            </div>
        </div>
        <script>
        (function () {
            var btn = document.getElementById('hmsLabAddRow');
            var tbl = document.querySelector('#hmsLabTplTable tbody');
            if (btn && tbl) {
                btn.addEventListener('click', function () {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="text" class="form-control form-control-sm" name="tpl_label[]" placeholder="Label"></td>' +
                        '<td><input type="text" class="form-control form-control-sm" name="tpl_value[]" placeholder="Value"></td><td></td>';
                    tbl.appendChild(tr);
                });
            }
        })();
        </script>
<?php include __DIR__ . '/footer.php'; ?>
