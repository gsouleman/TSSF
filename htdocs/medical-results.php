<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/laboratory_dreams.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'lab.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$canWrite = hms_can($connection, 'lab.write');
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_medical_result_table_ok($connection);

$flash = isset($_SESSION['lab_registry_flash']) ? (string) $_SESSION['lab_registry_flash'] : '';
unset($_SESSION['lab_registry_flash']);

if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_medical_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        $st = mysqli_prepare($connection, 'DELETE FROM tbl_medical_result WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $delId, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        hms_audit_log($connection, 'medical_registry.delete', 'medical_result', $delId);
        $_SESSION['lab_registry_flash'] = 'Medical result removed.';
    }
    header('Location: medical-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['modal_add_medical_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $recordName = trim((string) ($_POST['record_name'] ?? ''));
    $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($pid < 1 || $recordName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDate)) {
        $_SESSION['lab_registry_flash'] = 'Patient, record name, and a valid appointment date are required.';
    } else {
        $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ?' . ($ms ? ' AND facility_id = ? LIMIT 1' : ' LIMIT 1'));
        $okPat = false;
        if ($chk) {
            if ($ms) {
                mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
            } else {
                mysqli_stmt_bind_param($chk, 'i', $pid);
            }
            mysqli_stmt_execute($chk);
            $okPat = (bool) hms_stmt_fetch_assoc($chk);
            mysqli_stmt_close($chk);
        }
        if (!$okPat) {
            $_SESSION['lab_registry_flash'] = 'Select a valid patient for this site.';
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_medical_result (facility_id, patient_id, record_name, appointment_date, notes, created_by) VALUES (?,?,?,?,?,?)'
            );
            $ins = false;
            if ($st) {
                mysqli_stmt_bind_param($st, 'iisssi', $fid, $pid, $recordName, $apptDate, $notes, $uid);
                $ins = mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
            if ($ins) {
                hms_audit_log($connection, 'medical_registry.create', 'medical_result', (int) mysqli_insert_id($connection));
                $_SESSION['lab_registry_flash'] = 'Medical result added.';
            } else {
                $_SESSION['lab_registry_flash'] = 'Could not save medical result.';
            }
        }
    }
    header('Location: medical-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

$qRaw = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;

$rows = [];
$total = 0;
$totalPages = 1;
$patientOptions = [];

if ($tablesOk) {
    $w = ['mr.facility_id = ' . (int) $fid];
    if ($qRaw !== '') {
        $like = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(mr.record_name LIKE '%{$like}%' OR mr.notes LIKE '%{$like}%' OR p.first_name LIKE '%{$like}%' OR p.last_name LIKE '%{$like}%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%{$like}%')";
    }
    $whereSql = implode(' AND ', $w);
    $baseFrom = 'FROM tbl_medical_result mr INNER JOIN tbl_patient p ON p.id = mr.patient_id WHERE ' . $whereSql;
    $cq = mysqli_query($connection, 'SELECT COUNT(*) AS c ' . $baseFrom);
    if ($cq) {
        $cr = mysqli_fetch_assoc($cq);
        $total = (int) ($cr['c'] ?? 0);
    }
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $orderSql = $sort === 'oldest' ? 'mr.appointment_date ASC, mr.id ASC' : 'mr.appointment_date DESC, mr.id DESC';
    $lq = mysqli_query(
        $connection,
        'SELECT mr.*, p.first_name AS p_fn, p.last_name AS p_ln, p.gender AS p_gender ' . $baseFrom . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int) $offset . ', ' . (int) $perPage
    );
    while ($lq && $r = mysqli_fetch_assoc($lq)) {
        $rows[] = $r;
    }

    if ($canWrite) {
        $psuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
        $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $psuf . ' ORDER BY last_name, first_name LIMIT 600');
        while ($pq && $pr = mysqli_fetch_assoc($pq)) {
            $patientOptions[] = $pr;
        }
    }
}

$qsBase = ['q' => $qRaw, 'sort' => $sort];

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
}

include __DIR__ . '/header.php';

?>
        <div class="page-wrapper hms-lab-print-root">
            <div class="content hms-module hms-lab-page hms-appts-dreams">
                <?php if (!$tablesOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Run migration <code>hms/database/migrations/010_laboratory_registry.sql</code> to enable this module.
                </div>
                <?php } else { ?>
                <form method="get" action="medical-results.php" class="hms-appts-keyword-bar card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <input type="search" name="q" class="form-control hms-appts-keyword-input" placeholder="Search Keyword" value="<?php echo hms_h($qRaw); ?>" autocomplete="off">
                            </div>
                            <input type="hidden" name="sort" value="<?php echo hms_h($sort); ?>">
                            <button type="submit" class="btn btn-primary px-4 hms-appts-keyword-btn"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Medical Results</h1>
                        <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active">Medical Results</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center hms-appts-dreams-toolbar no-print">
                        <a href="lab-results.php" class="btn btn-light border btn-sm font-weight-bold mr-1">Lab Results</a>
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-1" id="hmsMedRefresh"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-2" id="hmsMedPrint"><i class="fa fa-print"></i></button>
                        <?php if ($canWrite) { ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold px-3" data-toggle="modal" data-target="#hmsMedAddModal"><i class="fa fa-plus mr-1"></i> New record</button>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($flash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
                <?php } ?>

                <section class="hms-appts-total-section card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Medical Results</h2>
                            <span class="hms-appts-count-badge"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="medical-results.php">
                            <input type="hidden" name="q" value="<?php echo hms_h($qRaw); ?>">
                            <input type="hidden" name="p" value="1">
                            <label class="small text-muted mr-2 mb-0" for="hmsMedSort">Sort By:</label>
                            <select name="sort" id="hmsMedSort" class="form-control form-control-sm hms-appts-sort-select" onchange="this.form.submit()">
                                <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                            </select>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 hms-appts-dreams-table">
                                <thead class="hms-appts-dreams-thead">
                                    <tr>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Gender</th>
                                        <th>Record</th>
                                        <th>Appointment Date</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rows === []) { ?>
                                    <tr><td colspan="6" class="text-center text-muted py-5">No medical results match your filters.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($rows as $row) {
                                        $rid = (int) $row['id'];
                                        $pname = trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''));
                                        $pinit = hms_visit_patient_initials((string) ($row['p_fn'] ?? ''), (string) ($row['p_ln'] ?? ''));
                                        $gen = trim((string) ($row['p_gender'] ?? '')) ?: '—';
                                        $appt = (string) ($row['appointment_date'] ?? '');
                                        $apptShow = $appt !== '' ? date('j M Y', strtotime($appt . ' 12:00:00')) : '—';
                                        ?>
                                    <tr class="hms-appts-dreams-row">
                                        <td class="align-middle font-weight-semibold"><?php echo hms_h(hms_medical_record_display_id($rid)); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--sm hms-visit-avatar--patient mr-2"><?php echo hms_h($pinit); ?></span>
                                                <span><?php echo hms_h(trim($pname)); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle"><?php echo hms_h($gen); ?></td>
                                        <td class="align-middle"><?php echo hms_h((string) ($row['record_name'] ?? '')); ?></td>
                                        <td class="align-middle text-muted small"><?php echo hms_h($apptShow); ?></td>
                                        <td class="align-middle text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <?php if ($canWrite) { ?>
                                                    <a class="dropdown-item" href="medical-result-edit.php?id=<?php echo $rid; ?>"><i class="fa fa-pencil mr-2"></i> Edit</a>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this record?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_medical_result" value="1">
                                                        <input type="hidden" name="id" value="<?php echo $rid; ?>">
                                                        <button type="submit" class="dropdown-item text-danger border-0 bg-transparent p-0 m-0 w-100 text-left"><i class="fa fa-trash-o mr-2"></i> Delete</button>
                                                    </form>
                                                    <?php } else { ?>
                                                    <span class="dropdown-item text-muted small">View only</span>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($totalPages > 1) { ?>
                    <div class="card-footer bg-white border-top-0 d-flex justify-content-center no-print">
                        <ul class="pagination pagination-sm mb-0">
                            <?php
                            $mk = static function (int $p) use ($qsBase): string {
                                return 'medical-results.php?' . http_build_query(array_merge($qsBase, ['p' => $p]));
                            };
                            ?>
                            <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk(max(1, $page - 1))); ?>">Prev</a></li>
                            <li class="page-item active"><span class="page-link"><?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?></span></li>
                            <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk(min($totalPages, $page + 1))); ?>">Next</a></li>
                        </ul>
                    </div>
                    <?php } ?>
                </section>

                <?php if ($canWrite) { ?>
                <div class="modal fade" id="hmsMedAddModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content hms-appt-modal-content border-0 shadow">
                            <div class="modal-header border-bottom">
                                <h5 class="modal-title font-weight-bold">New medical result</h5>
                                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                            </div>
                            <form method="post" action="medical-results.php">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="modal_add_medical_result" value="1">
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>Patient <span class="text-danger">*</span></label>
                                        <select name="patient_id" id="medPatient" class="form-control select" required style="width:100%">
                                            <option value="">Select</option>
                                            <?php foreach ($patientOptions as $po) {
                                                $pid = (int) $po['id'];
                                                $nm = trim((string) $po['first_name'] . ' ' . (string) $po['last_name']);
                                                ?>
                                            <option value="<?php echo $pid; ?>"><?php echo hms_h($nm); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Record <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="record_name" required placeholder="e.g. MRI Scan">
                                    </div>
                                    <div class="form-group">
                                        <label>Appointment date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="appointment_date" required value="<?php echo hms_h(date('Y-m-d')); ?>">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label>Notes</label>
                                        <textarea class="form-control" name="notes" rows="2"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer border-top bg-light">
                                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php } ?>
                <?php } ?>
            </div>
        </div>
        <script>
        (function () {
            document.getElementById('hmsMedRefresh') && document.getElementById('hmsMedRefresh').addEventListener('click', function () { window.location.reload(); });
            document.getElementById('hmsMedPrint') && document.getElementById('hmsMedPrint').addEventListener('click', function () { window.print(); });
        })();
        </script>
        <?php if ($tablesOk && $canWrite) { ?>
        <script>
        $(function () {
            var $m = $('#hmsMedAddModal');
            if ($m.length && $.fn.select2) {
                $('#medPatient').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select' });
            }
        });
        </script>
        <?php } ?>
<?php include 'footer.php'; ?>
