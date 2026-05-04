<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
include 'header.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_patient']) && hms_can($connection, 'patient.write')) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        if (hms_multi_site_enabled($connection)) {
            $fid = hms_current_facility_id();
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_patient WHERE id = ? AND facility_id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $delId, $fid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_patient WHERE id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $delId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        hms_audit_log($connection, 'patient.delete', 'patient', $delId);
    }
    header('Location: patients.php');
    exit;
}

/**
 * @param array<int, string> $lastVisitByPatient mysql datetime keyed by patient id
 */
function hms_patients_card_last_visit(array $lastVisitByPatient, int $patientId, ?string $fallbackCreated): string
{
    $raw = $lastVisitByPatient[$patientId] ?? '';
    if ($raw === '' || $raw === null) {
        $raw = (string) $fallbackCreated;
    }
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '—';
    }

    return date('j M Y', $ts);
}

function hms_patients_card_location(string $address): string
{
    $t = trim($address);
    if ($t === '') {
        return '—';
    }
    if (preg_match('/,\s*([^,]+)\s*$/', $t, $m)) {
        return trim($m[1]);
    }
    if (strlen($t) > 32) {
        return substr($t, 0, 29) . '…';
    }

    return $t;
}

function hms_patients_type_label(string $patientType): string
{
    if ($patientType === 'InPatient') {
        return 'In Patient';
    }
    if ($patientType === 'OutPatient') {
        return 'Out Patient';
    }

    return $patientType;
}

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$countSql = $ms
    ? 'SELECT COUNT(*) AS c FROM tbl_patient WHERE facility_id = ' . (int) $fid
    : 'SELECT COUNT(*) AS c FROM tbl_patient';
$countRes = mysqli_query($connection, $countSql);
$totalPatients = (int) (($countRes ? mysqli_fetch_assoc($countRes) : ['c' => 0])['c'] ?? 0);

$lastVisitByPatient = [];
if (hms_db_table_exists($connection, 'tbl_appointment') && hms_db_column_exists($connection, 'tbl_appointment', 'patient_id')) {
    $lvWhere = ' WHERE a.status = 1 AND a.patient_id IS NOT NULL AND a.patient_id > 0';
    if ($ms && hms_db_column_exists($connection, 'tbl_appointment', 'facility_id')) {
        $lvWhere .= ' AND a.facility_id = ' . (int) $fid;
    }
    $lvSql = 'SELECT a.patient_id AS pid, MAX(a.created_at) AS mx FROM tbl_appointment a' . $lvWhere . ' GROUP BY a.patient_id';
    $lvQ = mysqli_query($connection, $lvSql);
    while ($lvQ && $lvRow = mysqli_fetch_assoc($lvQ)) {
        $lastVisitByPatient[(int) $lvRow['pid']] = (string) $lvRow['mx'];
    }
}

$sql = $ms
    ? 'SELECT * FROM tbl_patient WHERE facility_id = ' . (int) $fid . ' ORDER BY last_name ASC, first_name ASC'
    : 'SELECT * FROM tbl_patient ORDER BY last_name ASC, first_name ASC';
$fetch_query = mysqli_query($connection, $sql);
$patientRows = [];
while ($fetch_query && $row = mysqli_fetch_assoc($fetch_query)) {
    $patientRows[] = $row;
}

$isAdmin = (string) ($_SESSION['role'] ?? '') === '1';
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-patients-page">
                <?php
                $ph = [
                    'subtitle' => 'Card view of patient records — search filters the grid. Open chart, edit, or book a visit.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Patients', '']],
                    'secondary' => [
                        ['label' => 'Front office', 'url' => 'front-office.php', 'icon' => 'fa-desktop'],
                        ['label' => 'Appointments', 'url' => 'appointments.php', 'icon' => 'fa-calendar'],
                    ],
                ];
                if (hms_can($connection, 'patient.write')) {
                    $ph['primary'] = ['label' => 'Add patient', 'url' => 'add-patient.php', 'icon' => 'fa-user-plus'];
                }
                hms_ui_page_header('Patients', $ph);
                ?>

                <div class="card border-0 shadow-sm hms-patients-shell">
                    <div class="card-header bg-white border-bottom hms-patients-card-head">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <h2 class="h5 font-weight-bold mb-1 text-dark">Patient directory</h2>
                                <p class="small text-muted mb-0">
                                    <span class="badge badge-light border font-weight-normal"><?php echo $totalPatients; ?> total</span>
                                    <span class="mx-1 text-muted">·</span>
                                    Grid layout — type the name, ID, phone, or location to filter cards.
                                </p>
                            </div>
                            <div class="hms-patients-card-toolbar">
                                <label class="sr-only" for="hmsPatientsCardSearch">Search patients</label>
                                <input type="search" id="hmsPatientsCardSearch" class="form-control form-control-sm hms-patients-card-search" placeholder="Search patients…" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="card-body hms-patients-card-body">
                        <?php if ($patientRows === []) { ?>
                        <p class="text-muted text-center py-5 mb-0">No patients registered for this site yet.</p>
                        <?php } else { ?>
                        <div class="row" id="hmsPatientsCardGrid">
                            <?php foreach ($patientRows as $row) {
                                $pid = (int) $row['id'];
                                $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                                $ptIdLabel = '#PT' . str_pad((string) $pid, 4, '0', STR_PAD_LEFT);
                                $ptype = (string) $row['patient_type'];
                                $badgeClass = $ptype === 'InPatient' ? 'hms-pcard-badge--in' : 'hms-pcard-badge--out';
                                $lastVisit = hms_patients_card_last_visit(
                                    $lastVisitByPatient,
                                    $pid,
                                    isset($row['created_at']) ? (string) $row['created_at'] : null
                                );
                                $loc = hms_patients_card_location((string) $row['address']);
                                $gender = (string) $row['gender'];
                                $searchBlob = strtolower(
                                    $fullName . ' ' . $ptIdLabel . ' ' . (string) $row['email'] . ' ' . (string) $row['phone'] . ' ' . (string) $row['address'] . ' ' . $gender . ' ' . $ptype
                                );
                                $apptHref = $isAdmin ? ('add-appointment.php?pick=' . $pid) : 'appointments.php';
                                $isActive = (int) ($row['status'] ?? 0) === 1;
                                ?>
                            <div class="col-sm-6 col-lg-4 mb-4 hms-patients-card-col" data-hms-search="<?php echo hms_h($searchBlob); ?>">
                                <div class="card hms-patient-card border-0 shadow-sm h-100<?php echo $isActive ? '' : ' hms-patient-card--inactive'; ?>">
                                    <div class="card-body d-flex flex-column p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <span class="badge hms-pcard-badge <?php echo hms_h($badgeClass); ?>"><?php echo hms_h(hms_patients_type_label($ptype)); ?></span>
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-link btn-sm text-muted p-0 hms-pcard-more" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="More actions">
                                                    <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
                                                </button>
                                                <div class="dropdown-menu dropdown-menu-right shadow-sm">
                                                    <a class="dropdown-item" href="patient-chart.php?id=<?php echo $pid; ?>"><i class="fa fa-heartbeat mr-2 text-primary"></i>Clinical chart</a>
                                                    <?php if (hms_can($connection, 'patient.write')) { ?>
                                                    <a class="dropdown-item" href="edit-patient.php?id=<?php echo $pid; ?>"><i class="fa fa-pencil mr-2"></i>Edit demographics</a>
                                                    <div class="dropdown-divider"></div>
                                                    <form method="post" class="px-3 py-1 mb-0" onsubmit="return confirm('Delete this patient? This cannot be undone.');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_patient" value="1">
                                                        <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                                        <button type="submit" class="dropdown-item text-danger"><i class="fa fa-trash-o mr-2"></i>Delete patient</button>
                                                    </form>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-center mb-3">
                                            <img class="hms-pcard-avatar rounded-circle mx-auto d-block" src="assets/img/user.jpg" width="72" height="72" alt="">
                                            <div class="hms-pcard-id text-muted small mt-2"><?php echo hms_h($ptIdLabel); ?></div>
                                            <div class="hms-pcard-name font-weight-bold mt-1"><?php echo hms_h($fullName); ?></div>
                                            <?php if (!$isActive) { ?>
                                            <span class="badge badge-secondary mt-2">Inactive</span>
                                            <?php } ?>
                                        </div>
                                        <div class="hms-pcard-stats row no-gutters text-center mb-3 flex-grow-1">
                                            <div class="col-4 hms-pcard-stat-cell">
                                                <div class="hms-pcard-stat-label">Last visit</div>
                                                <div class="hms-pcard-stat-value"><?php echo hms_h($lastVisit); ?></div>
                                            </div>
                                            <div class="col-4 hms-pcard-stat-cell">
                                                <div class="hms-pcard-stat-label">Gender</div>
                                                <div class="hms-pcard-stat-value"><?php echo hms_h($gender); ?></div>
                                            </div>
                                            <div class="col-4 hms-pcard-stat-cell">
                                                <div class="hms-pcard-stat-label">Location</div>
                                                <div class="hms-pcard-stat-value text-truncate px-1" title="<?php echo hms_h((string) $row['address']); ?>"><?php echo hms_h($loc); ?></div>
                                            </div>
                                        </div>
                                        <a class="btn hms-pcard-appt-btn btn-block mt-auto font-weight-bold" href="<?php echo hms_h($apptHref); ?>">Add appointment</a>
                                    </div>
                                </div>
                            </div>
                                <?php
                            }
                            ?>
                        </div>
                        <p id="hmsPatientsCardEmpty" class="text-center text-muted py-4 mb-0 d-none">No patients match your search.</p>
                        <?php } ?>
                    </div>
                </div>

            </div>

        </div>

<script>
(function () {
    var inp = document.getElementById('hmsPatientsCardSearch');
    var grid = document.getElementById('hmsPatientsCardGrid');
    if (!inp || !grid) return;
    function runFilter() {
        var q = (inp.value || '').toLowerCase().trim();
        var cols = grid.querySelectorAll('.hms-patients-card-col');
        var shown = 0;
        cols.forEach(function (col) {
            var hay = (col.getAttribute('data-hms-search') || '').toLowerCase();
            var ok = !q || hay.indexOf(q) !== -1;
            col.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });
        var empty = document.getElementById('hmsPatientsCardEmpty');
        if (empty) empty.classList.toggle('d-none', shown !== 0);
    }
    inp.addEventListener('input', runFilter);
    inp.addEventListener('search', runFilter);
    runFilter();
})();
</script>

<?php if (isset($_GET['hms_view']) && (string) $_GET['hms_view'] === 'search') { ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var inp = document.getElementById('hmsPatientsCardSearch');
    if (!inp) return;
    inp.focus();
    try { inp.select(); } catch (e) {}
});
</script>
<?php } ?>

<?php
include 'footer.php';
?>
