<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

function hms_doctors_experience_label(?string $joiningDate): string
{
    $raw = trim((string) $joiningDate);
    if ($raw === '') {
        return '—';
    }
    $norm = str_replace('/', '-', $raw);
    $ts = strtotime($norm);
    if ($ts === false) {
        return '—';
    }
    $years = (int) floor((time() - $ts) / (365.25 * 86400));
    if ($years < 1) {
        return 'Under 1 yr';
    }

    return (string) $years . '+ Years';
}

if ((string) ($_GET['export'] ?? '') === 'csv' && (string) ($_SESSION['role'] ?? '') === '1') {
    $fid = hms_current_facility_id();
    $ms = hms_multi_site_enabled($connection);
    if ($ms) {
        $exportQ = mysqli_query(
            $connection,
            'SELECT e.id, e.first_name, e.last_name, e.emailid, e.phone, e.bio, e.joining_date, e.username'
            . (hms_doctor_photo_column_exists($connection) ? ', e.photo_path' : '') . '
             FROM tbl_employee e
             INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
             WHERE e.role = 2 AND uf.facility_id = ' . (int) $fid . '
             ORDER BY e.last_name, e.first_name'
        );
    } else {
        $exportQ = mysqli_query(
            $connection,
            'SELECT id, first_name, last_name, emailid, phone, bio, joining_date, username'
            . (hms_doctor_photo_column_exists($connection) ? ', photo_path' : '') . '
            FROM tbl_employee WHERE role = 2 ORDER BY last_name, first_name'
        );
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="doctors-export.csv"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fwrite($out, "\xEF\xBB\xBF");
        $hdr = ['ID', 'First name', 'Last name', 'Email', 'Phone', 'Specialty / bio', 'Joining date', 'Username'];
        if (hms_doctor_photo_column_exists($connection)) {
            $hdr[] = 'Photo path';
        }
        fputcsv($out, $hdr);
        while ($exportQ && $er = mysqli_fetch_assoc($exportQ)) {
            $line = [
                (string) $er['id'],
                (string) $er['first_name'],
                (string) $er['last_name'],
                (string) $er['emailid'],
                (string) $er['phone'],
                (string) $er['bio'],
                (string) ($er['joining_date'] ?? ''),
                (string) $er['username'],
            ];
            if (hms_doctor_photo_column_exists($connection)) {
                $line[] = (string) ($er['photo_path'] ?? '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_doctor']) && (string) $_SESSION['role'] === '1') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        if (hms_doctor_photo_column_exists($connection)) {
            $st = mysqli_prepare($connection, 'SELECT photo_path FROM tbl_employee WHERE id = ? AND role = 2 LIMIT 1');
            if ($st) {
                mysqli_stmt_bind_param($st, 'i', $delId);
                mysqli_stmt_execute($st);
                $pr = hms_stmt_fetch_assoc($st);
                mysqli_stmt_close($st);
                if ($pr && !empty($pr['photo_path'])) {
                    hms_doctor_photo_delete_uploaded_file((string) $pr['photo_path']);
                }
            }
        }
        $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_employee WHERE id = ? AND role = 2');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $delId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        hms_audit_log($connection, 'doctor.delete', 'employee', $delId);
    }
    header('Location: doctors.php');
    exit;
}

include 'header.php';

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);

$doctorRows = [];
if ($ms) {
    $fetch_query = mysqli_query(
        $connection,
        'SELECT e.* FROM tbl_employee e
         INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
         WHERE e.role = 2 AND uf.facility_id = ' . (int) $fid . '
         ORDER BY e.first_name, e.last_name'
    );
} else {
    $fetch_query = mysqli_query($connection, 'SELECT * FROM tbl_employee WHERE role = 2 ORDER BY first_name, last_name');
}
while ($fetch_query && $row = mysqli_fetch_assoc($fetch_query)) {
    $doctorRows[] = $row;
}

$apptCountByDoctorName = [];
if (hms_db_table_exists($connection, 'tbl_appointment')) {
    $apW = ' WHERE status = 1 AND TRIM(doctor) <> ""';
    if ($ms && hms_db_column_exists($connection, 'tbl_appointment', 'facility_id')) {
        $apW .= ' AND facility_id = ' . (int) $fid;
    }
    $apQ = mysqli_query($connection, 'SELECT TRIM(doctor) AS doc_name, COUNT(*) AS c FROM tbl_appointment' . $apW . ' GROUP BY TRIM(doctor)');
    while ($apQ && $ar = mysqli_fetch_assoc($apQ)) {
        $key = strtolower(trim((string) $ar['doc_name']));
        if ($key !== '') {
            $apptCountByDoctorName[$key] = (int) $ar['c'];
        }
    }
}
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-doctors-page">
                <?php
                $dh = [
                    'subtitle' => 'Clinician directory — grid or list view, aligned with Dreams EMR.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Doctors', '']],
                    'secondary' => [
                        ['label' => 'Scheduling', 'url' => 'schedule.php', 'icon' => 'fa-calendar-check-o'],
                        ['label' => 'Directory', 'url' => 'departments.php', 'icon' => 'fa-address-book-o'],
                    ],
                ];
                if ((string) $_SESSION['role'] === '1') {
                    $dh['primary'] = ['label' => 'New doctor', 'url' => 'add-doctor.php', 'icon' => 'fa-plus'];
                }
                hms_ui_page_header('Doctors', $dh);
                ?>

                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 hms-doctors-toolbar-row gap-2">
                    <div class="btn-group btn-group-sm" role="group" aria-label="View mode">
                        <button type="button" class="btn btn-outline-secondary active" id="hmsDrViewGrid" title="Grid view" aria-pressed="true">
                            <i class="fa fa-th" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="hmsDrViewList" title="List view" aria-pressed="false">
                            <i class="fa fa-list" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="flex-grow-1 d-flex justify-content-center order-3 order-md-2" style="min-width: 200px; max-width: 420px;">
                        <label class="sr-only" for="hmsDoctorsSearch">Search keyword</label>
                        <input type="search" id="hmsDoctorsSearch" class="form-control form-control-sm hms-doctors-keyword-search" placeholder="Search keyword" autocomplete="off">
                    </div>
                    <div class="d-flex align-items-center order-2 order-md-3">
                        <button type="button" class="btn btn-sm btn-light border mr-1" id="hmsDrRefresh" title="Refresh"><i class="fa fa-refresh" aria-hidden="true"></i></button>
                        <button type="button" class="btn btn-sm btn-light border mr-1" id="hmsDrPrint" title="Print"><i class="fa fa-print" aria-hidden="true"></i></button>
                        <?php if ((string) $_SESSION['role'] === '1') { ?>
                        <a class="btn btn-sm btn-light border" href="doctors.php?export=csv" title="Export CSV"><i class="fa fa-download" aria-hidden="true"></i></a>
                        <?php } ?>
                    </div>
                </div>

                <div id="hmsDoctorsGridWrap" class="hms-doctors-grid-wrap">
                    <div class="row" id="hmsDoctorsCardGrid">
                        <?php foreach ($doctorRows as $row) {
                            $pid = (int) $row['id'];
                            $full = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                            $drName = 'Dr. ' . trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                            $badgeId = '#DR' . str_pad((string) $pid, 4, '0', STR_PAD_LEFT);
                            $specialty = trim((string) ($row['bio'] ?? ''));
                            if ($specialty === '') {
                                $specialty = 'Physician';
                            }
                            if (strlen($specialty) > 72) {
                                $specialty = substr($specialty, 0, 69) . '…';
                            }
                            $exp = hms_doctors_experience_label(isset($row['joining_date']) ? (string) $row['joining_date'] : '');
                            $lk = strtolower(trim($full));
                            $appts = (int) ($apptCountByDoctorName[$lk] ?? 0);
                            $active = (int) ($row['status'] ?? 0) === 1;
                            $searchBlob = strtolower(
                                $full . ' ' . $drName . ' ' . (string) $row['username'] . ' ' . (string) $row['emailid'] . ' ' . (string) $row['phone'] . ' ' . (string) $row['bio'] . ' ' . $badgeId
                            );
                            ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-4 hms-doctor-card-col" data-hms-search="<?php echo hms_h($searchBlob); ?>">
                            <div class="card hms-doctor-card border-0 shadow-sm h-100<?php echo $active ? '' : ' hms-doctor-card--inactive'; ?>">
                                <div class="card-body d-flex flex-column p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <span class="badge hms-dcard-id"><?php echo hms_h($badgeId); ?></span>
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-link btn-sm text-muted p-0 hms-dcard-more" data-toggle="dropdown" aria-label="More actions"><i class="fa fa-ellipsis-v"></i></button>
                                            <div class="dropdown-menu dropdown-menu-right shadow-sm">
                                                <?php if ((string) $_SESSION['role'] === '1') { ?>
                                                <a class="dropdown-item" href="edit-doctor.php?id=<?php echo $pid; ?>"><i class="fa fa-pencil mr-2"></i>Edit</a>
                                                <div class="dropdown-divider"></div>
                                                <form method="post" class="px-3 py-1 mb-0" onsubmit="return confirm('Delete this doctor?');">
                                                    <?php echo hms_csrf_field(); ?>
                                                    <input type="hidden" name="delete_doctor" value="1">
                                                    <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="fa fa-trash-o mr-2"></i>Delete</button>
                                                </form>
                                                <?php } else { ?>
                                                <span class="dropdown-item-text small text-muted">Administrator actions only</span>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-center mb-3">
                                        <div class="hms-dcard-avatar-wrap mx-auto">
                                            <img class="hms-dcard-avatar rounded-circle" src="<?php echo hms_h(hms_doctor_avatar_src($row)); ?>" width="80" height="80" alt="">
                                            <span class="hms-dcard-status<?php echo $active ? ' hms-dcard-status--online' : ''; ?>" title="<?php echo $active ? 'Active' : 'Inactive'; ?>"></span>
                                        </div>
                                        <div class="hms-dcard-name font-weight-bold mt-3"><?php echo hms_h($drName); ?></div>
                                        <div class="hms-dcard-specialty text-muted small mt-1"><?php echo hms_h($specialty); ?></div>
                                    </div>
                                    <div class="hms-dcard-stats row no-gutters text-center mb-3">
                                        <div class="col-6 hms-dcard-stat-cell">
                                            <div class="hms-dcard-stat-label">Experience</div>
                                            <div class="hms-dcard-stat-value"><?php echo hms_h($exp); ?></div>
                                        </div>
                                        <div class="col-6 hms-dcard-stat-cell">
                                            <div class="hms-dcard-stat-label">Appointments</div>
                                            <div class="hms-dcard-stat-value"><?php echo (int) $appts; ?></div>
                                        </div>
                                    </div>
                                    <div class="hms-dcard-contact mt-auto small">
                                        <div class="text-truncate mb-1" title="<?php echo hms_h((string) $row['emailid']); ?>">
                                            <i class="fa fa-envelope-o text-muted mr-2" aria-hidden="true"></i><?php echo hms_h((string) $row['emailid']); ?>
                                        </div>
                                        <div class="text-truncate" title="<?php echo hms_h((string) $row['phone']); ?>">
                                            <i class="fa fa-phone text-muted mr-2" aria-hidden="true"></i><?php echo hms_h((string) $row['phone']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                            <?php
                        }
                        ?>
                    </div>
                    <p id="hmsDoctorsGridEmpty" class="text-center text-muted py-5 mb-0 d-none">No doctors match your search.</p>
                    <?php if ($doctorRows === []) { ?>
                    <p class="text-center text-muted py-5 mb-0">No doctors for this site yet.</p>
                    <?php } ?>
                </div>
                <div id="hmsDoctorsListWrap" class="hms-doctors-list-wrap d-none">
                    <div class="card border-0 shadow-sm hms-data-card hms-doctors-shell">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped mb-0 hms-table-actions" id="hmsDoctorsTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>DOB</th>
                                            <th>Phone</th>
                                            <th>Bio</th>
                                            <?php if ($_SESSION['role'] == 1) { ?>
                                            <th class="text-right">Action</th>
                                            <?php } ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($doctorRows as $row) {
                                            $pid = (int) $row['id'];
                                            $full = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
                                            $searchBlob = strtolower(
                                                $full . ' ' . (string) $row['username'] . ' ' . (string) $row['emailid'] . ' ' . (string) $row['phone'] . ' ' . (string) $row['bio'] . ' ' . (string) $row['dob']
                                            );
                                            ?>
                                        <tr data-hms-search="<?php echo hms_h($searchBlob); ?>">
                                            <td><?php echo hms_h($full); ?></td>
                                            <td><?php echo hms_h((string) $row['username']); ?></td>
                                            <td><?php echo hms_h((string) $row['emailid']); ?></td>
                                            <td><?php echo hms_h((string) $row['dob']); ?></td>
                                            <td><?php echo hms_h((string) $row['phone']); ?></td>
                                            <td><?php echo hms_h((string) $row['bio']); ?></td>
                                            <?php if ($_SESSION['role'] == 1) { ?>
                                            <td class="text-right">
                                                <div class="dropdown dropdown-action">
                                                    <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></a>
                                                    <div class="dropdown-menu dropdown-menu-right">
                                                        <a class="dropdown-item" href="edit-doctor.php?id=<?php echo $pid; ?>"><i class="fa fa-pencil m-r-5"></i>Edit</a>
                                                        <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this doctor?');">
                                                            <?php echo hms_csrf_field(); ?>
                                                            <input type="hidden" name="delete_doctor" value="1">
                                                            <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                                            <button type="submit" class="dropdown-item text-danger border-0 bg-transparent p-0 m-0 w-100 text-left"><i class="fa fa-trash-o m-r-5"></i>Delete</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </td>
                                            <?php } ?>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <p id="hmsDoctorsListEmpty" class="text-center text-muted small py-3 mb-0 d-none border-top">No doctors match your search.</p>
                        </div>
                    </div>
                </div>

            </div>

        </div>

<script>
(function () {
    var gridWrap = document.getElementById('hmsDoctorsGridWrap');
    var listWrap = document.getElementById('hmsDoctorsListWrap');
    var btnGrid = document.getElementById('hmsDrViewGrid');
    var btnList = document.getElementById('hmsDrViewList');
    var inp = document.getElementById('hmsDoctorsSearch');
    var storageKey = 'hms_doctors_view';

    function setView(mode) {
        var isList = mode === 'list';
        if (gridWrap) gridWrap.classList.toggle('d-none', isList);
        if (listWrap) listWrap.classList.toggle('d-none', !isList);
        if (btnGrid) {
            btnGrid.classList.toggle('active', !isList);
            btnGrid.setAttribute('aria-pressed', isList ? 'false' : 'true');
        }
        if (btnList) {
            btnList.classList.toggle('active', isList);
            btnList.setAttribute('aria-pressed', isList ? 'true' : 'false');
        }
        try { localStorage.setItem(storageKey, mode); } catch (e) {}
        runSearch();
    }

    if (btnGrid) btnGrid.addEventListener('click', function () { setView('grid'); });
    if (btnList) btnList.addEventListener('click', function () { setView('list'); });
    try {
        var saved = localStorage.getItem(storageKey);
        if (saved === 'list') setView('list');
    } catch (e) {}

    document.getElementById('hmsDrRefresh') && document.getElementById('hmsDrRefresh').addEventListener('click', function () {
        window.location.reload();
    });
    document.getElementById('hmsDrPrint') && document.getElementById('hmsDrPrint').addEventListener('click', function () {
        window.print();
    });

    function runSearch() {
        var q = inp ? (inp.value || '').toLowerCase().trim() : '';
        var nGrid = 0;
        document.querySelectorAll('.hms-doctor-card-col').forEach(function (col) {
            var hay = (col.getAttribute('data-hms-search') || '').toLowerCase();
            var ok = !q || hay.indexOf(q) !== -1;
            col.classList.toggle('d-none', !ok);
            if (ok) nGrid++;
        });
        var gridEmpty = document.getElementById('hmsDoctorsGridEmpty');
        var grids = document.querySelectorAll('.hms-doctor-card-col');
        if (gridEmpty) {
            var showGridEmpty = q.length > 0 && nGrid === 0 && grids.length > 0;
            gridEmpty.classList.toggle('d-none', !showGridEmpty);
        }
        var tbody = document.querySelector('#hmsDoctorsTable tbody');
        var nList = 0;
        if (tbody) {
            tbody.querySelectorAll('tr').forEach(function (tr) {
                var hay = (tr.getAttribute('data-hms-search') || '').toLowerCase();
                var ok = !q || hay.indexOf(q) !== -1;
                tr.classList.toggle('d-none', !ok);
                if (ok) nList++;
            });
        }
        var listEmpty = document.getElementById('hmsDoctorsListEmpty');
        var lrows = tbody ? tbody.querySelectorAll('tr').length : 0;
        if (listEmpty) {
            var showListEmpty = q.length > 0 && nList === 0 && lrows > 0;
            listEmpty.classList.toggle('d-none', !showListEmpty);
        }
    }
    if (inp) {
        inp.addEventListener('input', runSearch);
        inp.addEventListener('search', runSearch);
    }
    runSearch();
})();
</script>

<?php if (isset($_GET['hms_view']) && (string) $_GET['hms_view'] === 'search') { ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var inp = document.getElementById('hmsDoctorsSearch');
    if (inp) { inp.focus(); try { inp.select(); } catch (e) {} }
});
</script>
<?php } ?>

<?php
include 'footer.php';
?>
