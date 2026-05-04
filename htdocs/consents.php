<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_consent');

$flash = isset($_SESSION['consents_flash']) ? (string) $_SESSION['consents_flash'] : '';
unset($_SESSION['consents_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add']) && hms_csrf_validate($_POST['hms_csrf'] ?? null) && hms_can($connection, 'patient.write')) {
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $ctype = trim((string) ($_POST['consent_type'] ?? ''));
    $ver = (string) ($_POST['version'] ?? '1');
    $ref = (string) ($_POST['document_ref'] ?? '');
    $st = mysqli_prepare($connection, 'INSERT INTO tbl_consent (patient_id, facility_id, consent_type, version, obtained_at, document_ref) VALUES (?,?,?,?,NOW(),?)');
    if ($st && $pid > 0 && $ctype !== '') {
        mysqli_stmt_bind_param($st, 'iisss', $pid, $fid, $ctype, $ver, $ref);
        mysqli_stmt_execute($st);
        $cid = (int) mysqli_insert_id($connection);
        mysqli_stmt_close($st);
        hms_audit_log($connection, 'consent.create', 'consent', $cid, ['patient_id' => $pid]);
        $_SESSION['consents_flash'] = 'Consent recorded.';
    } else {
        $_SESSION['consents_flash'] = 'Patient and consent type are required.';
    }
    header('Location: consents.php');
    exit;
}

$suf = hms_multi_site_enabled($connection) ? ' WHERE facility_id = ' . (int) $fid : '';
include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Consents', [
                'subtitle' => 'Documented consent events for patients at this site.',
                'breadcrumbs' => [['Patients', 'patients.php'], ['Consents', '']],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for <code>tbl_consent</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card mb-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Patient</th><th>Type</th><th>Version</th><th>Document</th><th>Obtained</th></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query(
                                $connection,
                                'SELECT c.consent_type, c.version, c.obtained_at, c.document_ref, p.first_name, p.last_name FROM tbl_consent c JOIN tbl_patient p ON p.id = c.patient_id WHERE c.facility_id = ' . (int) $fid . ' ORDER BY c.id DESC LIMIT 80'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                                echo '<td>' . hms_h((string) $r['consent_type']) . '</td>';
                                echo '<td>' . hms_h((string) $r['version']) . '</td>';
                                echo '<td class="small">' . hms_h((string) $r['document_ref']) . '</td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['obtained_at']) . '</td>';
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php if (hms_can($connection, 'patient.write')) { ?>
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-header bg-white font-weight-bold">Record consent</div>
                <div class="card-body">
                    <form method="post" class="row align-items-end">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group col-md-3 mb-2 mb-md-0">
                            <label class="small text-muted">Patient</label>
                            <select name="patient_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php
                                $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name LIMIT 500');
                                while ($pq && $pr = mysqli_fetch_assoc($pq)) {
                                    echo '<option value="' . (int) $pr['id'] . '">' . hms_h($pr['first_name'] . ' ' . $pr['last_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2 mb-2 mb-md-0">
                            <label class="small text-muted">Type</label>
                            <input class="form-control" name="consent_type" placeholder="e.g. HIPAA" required>
                        </div>
                        <div class="form-group col-md-2 mb-2 mb-md-0">
                            <label class="small text-muted">Version</label>
                            <input class="form-control" name="version" value="1">
                        </div>
                        <div class="form-group col-md-3 mb-2 mb-md-0">
                            <label class="small text-muted">Document ref</label>
                            <input class="form-control" name="document_ref" placeholder="Scan / URL">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <button class="btn btn-primary btn-block" type="submit" name="add" value="1">Save</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
