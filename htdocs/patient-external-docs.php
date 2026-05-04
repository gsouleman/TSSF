<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/patient_external_document.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'clinical.read');

$pid = (int) ($_GET['id'] ?? $_GET['patient_id'] ?? 0);
if ($pid < 1) {
    header('Location: patients.php');
    exit;
}
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
if ($ms) {
    $stmt = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $pid, $fid);
} else {
    $stmt = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $pid);
}
mysqli_stmt_execute($stmt);
$patient = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);
if (!$patient) {
    header('Location: patients.php');
    exit;
}

$dl = (int) ($_GET['download'] ?? 0);
if ($dl > 0 && hms_patient_external_document_table_ok($connection)) {
    $row = hms_patient_external_document_get($connection, $fid, $pid, $dl);
    if ($row !== null && hms_patient_external_document_send_download($row)) {
        exit;
    }
    header('HTTP/1.0 404 Not Found');
    exit;
}

$flash = '';
$canWrite = hms_can($connection, 'clinical.write');
$tableOk = hms_patient_external_document_table_ok($connection);

if ($tableOk && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['upload_external_doc'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $flash = 'Invalid security token.';
    } else {
        $kind = (string) ($_POST['doc_kind'] ?? 'other');
        $title = (string) ($_POST['doc_title'] ?? '');
        $notes = (string) ($_POST['doc_notes'] ?? '');
        $cidRaw = (int) ($_POST['consultation_id'] ?? 0);
        $cid = $cidRaw > 0 ? $cidRaw : null;
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $file = isset($_FILES['doc_file']) && is_array($_FILES['doc_file']) ? $_FILES['doc_file'] : null;
        $saved = hms_patient_external_document_save_upload(
            $connection,
            $fid,
            $pid,
            $uid,
            $file,
            $kind,
            $title,
            $notes,
            $cid
        );
        if ($saved !== null) {
            hms_audit_log($connection, 'patient.external_document.create', 'patient', $pid);
            $flash = 'File uploaded and linked to this patient chart.';
        } else {
            $flash = 'Upload failed. Use PDF or JPEG/PNG/WebP under 8 MB.';
        }
    }
}

$docs = $tableOk ? hms_patient_external_documents_list($connection, $fid, $pid) : [];
$ptName = trim((string) ($patient['first_name'] ?? '') . ' ' . (string) ($patient['last_name'] ?? ''));

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('External results & documents — ' . $ptName, [
                'subtitle' => 'Upload lab, imaging, or pharmacy papers from outside facilities so clinicians can review them on the chart.',
                'breadcrumbs' => [['Patients', 'patients.php'], ['Chart', 'patient-chart.php?id=' . $pid], ['External docs', '']],
                'back' => 'patient-chart.php?id=' . $pid,
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Run migration <code>hms/database/migrations/025_insurance_coverage_external_docs.sql</code> to enable external document storage.</div>
            <?php } else { ?>
            <div class="row">
                <div class="col-lg-5 mb-4">
                    <div class="card border-0 shadow-sm hms-form-card">
                        <div class="card-header bg-white font-weight-bold">Upload</div>
                        <div class="card-body">
                            <?php if ($canWrite) { ?>
                            <form method="post" enctype="multipart/form-data">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="upload_external_doc" value="1">
                                <div class="form-group">
                                    <label for="doc_kind">Kind</label>
                                    <select class="form-control" id="doc_kind" name="doc_kind">
                                        <option value="lab">Laboratory</option>
                                        <option value="radiology">Radiology / imaging</option>
                                        <option value="pharmacy">Pharmacy / prescription proof</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="doc_title">Title</label>
                                    <input class="form-control" id="doc_title" name="doc_title" maxlength="255" placeholder="e.g. CBC — City Lab (Yaoundé)">
                                </div>
                                <div class="form-group">
                                    <label for="doc_notes">Notes (optional)</label>
                                    <textarea class="form-control" id="doc_notes" name="doc_notes" rows="2" placeholder="Context for clinicians"></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="consultation_id">Consultation ID (optional)</label>
                                    <input class="form-control" id="consultation_id" name="consultation_id" type="number" min="0" placeholder="Link to visit #C… if known">
                                </div>
                                <div class="form-group">
                                    <label for="doc_file">File (PDF or image, max 8 MB)</label>
                                    <input class="form-control-file" id="doc_file" name="doc_file" type="file" accept=".pdf,image/*" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Upload</button>
                            </form>
                            <?php } else { ?>
                            <p class="text-muted mb-0">You do not have permission to upload clinical documents.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-header bg-white font-weight-bold">On file</div>
                        <div class="card-body p-0">
                            <?php if ($docs === []) { ?>
                            <p class="text-muted p-3 mb-0">No external documents yet.</p>
                            <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="thead-light"><tr><th>Date</th><th>Kind</th><th>Title</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($docs as $d) {
                                            $did = (int) ($d['id'] ?? 0);
                                            ?>
                                        <tr>
                                            <td class="text-nowrap small"><?php echo hms_h((string) ($d['created_at'] ?? '')); ?></td>
                                            <td class="small"><?php echo hms_h((string) ($d['doc_kind'] ?? '')); ?></td>
                                            <td class="small"><?php echo hms_h((string) ($d['title'] ?? '')); ?>
                                                <?php if (trim((string) ($d['notes'] ?? '')) !== '') { ?>
                                                <span class="text-muted d-block"><?php echo hms_h((string) $d['notes']); ?></span>
                                                <?php } ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <a class="btn btn-sm btn-outline-primary" href="patient-external-docs.php?id=<?php echo $pid; ?>&amp;download=<?php echo $did; ?>">Download</a>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
