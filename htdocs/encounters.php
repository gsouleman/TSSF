<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'clinical.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_encounter');

$flash = isset($_SESSION['encounters_flash']) ? (string) $_SESSION['encounters_flash'] : '';
unset($_SESSION['encounters_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_can($connection, 'clinical.write')) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['encounters_flash'] = 'Invalid security token.';
        header('Location: encounters.php');
        exit;
    }
    if (isset($_POST['open_encounter'])) {
        $pid = (int) ($_POST['patient_id'] ?? 0);
        $cc = (string) ($_POST['chief_complaint'] ?? '');
        $okPat = false;
        if ($pid > 0) {
            if (hms_multi_site_enabled($connection)) {
                $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
                mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
            } else {
                $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($chk, 'i', $pid);
            }
            mysqli_stmt_execute($chk);
            $okPat = (bool) hms_stmt_fetch_assoc($chk);
            mysqli_stmt_close($chk);
        }
        if (!$okPat) {
            $_SESSION['encounters_flash'] = 'Select a valid patient.';
        } elseif ($cc === '') {
            $_SESSION['encounters_flash'] = 'Enter a chief complaint.';
        } else {
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            $etype = 'ambulatory';
            $stat = 'in_progress';
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_encounter (facility_id, patient_id, provider_employee_id, encounter_type, status, chief_complaint, started_at) VALUES (?,?,?,?,?,?,NOW())'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'iiisss', $fid, $pid, $uid, $etype, $stat, $cc);
                mysqli_stmt_execute($st);
                $eid = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'encounter.create', 'encounter', $eid);
                $_SESSION['encounters_flash'] = 'Encounter opened.';
            }
        }
        header('Location: encounters.php');
        exit;
    }
}

include 'header.php';
$suf = hms_multi_site_enabled($connection) ? ' WHERE facility_id = ' . (int) $fid : '';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Encounters', [
                'subtitle' => 'Recent visits for the active facility.',
                'breadcrumbs' => [['Clinical', null], ['Encounters', '']],
                'secondary' => [
                    ['label' => 'Orders', 'url' => 'clinical-orders.php', 'icon' => 'fa-list'],
                    ['label' => 'Patients', 'url' => 'patients.php', 'icon' => 'fa-users'],
                ],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for clinical tables.</div>
            <?php } else { ?>
            <?php if (hms_can($connection, 'clinical.write')) { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4">
                <div class="card-header bg-white font-weight-bold">Open encounter</div>
                <div class="card-body">
                    <form method="post" class="form-row align-items-end">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group col-md-4 mb-2 mb-md-0">
                            <label class="small text-muted">Patient</label>
                            <select name="patient_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php
                                $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name, first_name LIMIT 500');
                                while ($pq && $pr = mysqli_fetch_assoc($pq)) {
                                    echo '<option value="' . (int) $pr['id'] . '">' . hms_h($pr['first_name'] . ' ' . $pr['last_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group col-md-5 mb-2 mb-md-0">
                            <label class="small text-muted">Chief complaint</label>
                            <input class="form-control" name="chief_complaint" required placeholder="Reason for visit">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button class="btn btn-primary btn-block" type="submit" name="open_encounter" value="1"><i class="fa fa-plus mr-1"></i> Open</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table datatable mb-0">
                            <thead><tr><th>ID</th><th>Patient</th><th>Chart</th><th>Started</th><th>Status</th><th>Complaint</th></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query(
                                $connection,
                                'SELECT e.id, e.started_at, e.status, e.chief_complaint, e.patient_id, p.first_name, p.last_name FROM tbl_encounter e
                                 JOIN tbl_patient p ON p.id = e.patient_id WHERE e.facility_id = ' . (int) $fid . ' ORDER BY e.id DESC LIMIT 100'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $pid = (int) $r['patient_id'];
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                                echo '<td><a class="btn btn-sm btn-outline-secondary" href="patient-chart.php?id=' . $pid . '">Chart</a></td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['started_at']) . '</td>';
                                echo '<td>' . hms_h((string) $r['status']) . '</td>';
                                echo '<td>' . hms_h((string) $r['chief_complaint']) . '</td>';
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
