<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/appointments_dreams.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'scheduling.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$hasUserFacilityTbl = hms_db_table_exists($connection, 'tbl_user_facility');
$hasPatientIdCol = hms_db_column_exists($connection, 'tbl_appointment', 'patient_id');
$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');
$canWrite = hms_can($connection, 'scheduling.write');

$apptFlash = isset($_SESSION['appts_flash']) ? (string) $_SESSION['appts_flash'] : '';
unset($_SESSION['appts_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_appointment']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        if ($ms) {
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_appointment WHERE id = ? AND facility_id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $delId, $fid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_appointment WHERE id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $delId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        hms_audit_log($connection, 'appointment.delete', 'appointment', $delId);
        $_SESSION['appts_flash'] = 'Appointment removed.';
    }
    header('Location: appointments.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['modal_add_appointment']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $rawPatient = (string) ($_POST['patient_name'] ?? '');
    $patient_id = 0;
    $patient_name = $rawPatient;
    if (strpos($rawPatient, '|') !== false) {
        $parts = explode('|', $rawPatient, 2);
        $patient_id = (int) $parts[0];
        $patient_name = $parts[1] ?? '';
    }
    $department = trim((string) ($_POST['department'] ?? ''));
    $doctor = trim((string) ($_POST['doctor'] ?? ''));
    $date = trim((string) ($_POST['date'] ?? ''));
    $timeStart = trim((string) ($_POST['time_start'] ?? ''));
    $timeEnd = trim((string) ($_POST['time_end'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $notes = trim((string) ($_POST['quick_notes'] ?? ''));
    $consult = trim((string) ($_POST['consultation_mode'] ?? ''));
    $payMode = trim((string) ($_POST['payment_mode'] ?? ''));
    /* New appointments are pending (Requests) until confirmed or until consultation / visit for this patient. */
    $status = 0;

    $message = $reason;
    if ($notes !== '') {
        $message .= ($message !== '' ? "\n\n" : '') . $notes;
    }
    if ($consult !== '') {
        $message = ($message !== '' ? '' : '') . '[Consultation: ' . $consult . ']' . ($message !== '' ? "\n" . $message : '');
    }
    if ($payMode !== '') {
        $message .= ($message !== '' ? "\n\n" : '') . 'Mode of payment: ' . $payMode;
    }
    if ($message === '') {
        $message = '—';
    }

    $time = $timeStart;
    if ($timeEnd !== '') {
        $time = $timeStart !== '' ? ($timeStart . ' – ' . $timeEnd) : $timeEnd;
    }

    if ($ms) {
        $nq = mysqli_query($connection, 'SELECT MAX(id) AS id FROM tbl_appointment WHERE facility_id = ' . (int) $fid);
    } else {
        $nq = mysqli_query($connection, 'SELECT MAX(id) AS id FROM tbl_appointment');
    }
    $nr = $nq ? mysqli_fetch_row($nq) : [0];
    $apt_id = (int) ($nr[0] ?? 0) === 0 ? 1 : (int) $nr[0] + 1;
    $appointment_id = 'APT-' . $apt_id;

    $doctorDeptOk = true;
    if ($hasEmployeeDeptCol && $department !== '' && $doctor !== '') {
        $doctorDeptOk = false;
        if ($ms && $hasUserFacilityTbl) {
            $chkDoc = mysqli_prepare(
                $connection,
                'SELECT e.id FROM tbl_employee e INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                 WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ?
                 AND TRIM(CONCAT(COALESCE(e.first_name,\'\'), \' \', COALESCE(e.last_name,\'\'))) = ?
                 AND LOWER(TRIM(COALESCE(e.primary_department,\'\'))) = LOWER(TRIM(?)) LIMIT 1'
            );
            if ($chkDoc) {
                mysqli_stmt_bind_param($chkDoc, 'iss', $fid, $doctor, $department);
                mysqli_stmt_execute($chkDoc);
                $doctorDeptOk = (bool) hms_stmt_fetch_assoc($chkDoc);
                mysqli_stmt_close($chkDoc);
            }
        } else {
            $chkDoc = mysqli_prepare(
                $connection,
                'SELECT id FROM tbl_employee WHERE role = 2 AND status = 1 AND TRIM(CONCAT(COALESCE(first_name,\'\'), \' \', COALESCE(last_name,\'\'))) = ?
                 AND LOWER(TRIM(COALESCE(primary_department,\'\'))) = LOWER(TRIM(?)) LIMIT 1'
            );
            if ($chkDoc) {
                mysqli_stmt_bind_param($chkDoc, 'ss', $doctor, $department);
                mysqli_stmt_execute($chkDoc);
                $doctorDeptOk = (bool) hms_stmt_fetch_assoc($chkDoc);
                mysqli_stmt_close($chkDoc);
            }
        }
    }

    $okIns = false;
    if ($department !== '' && $doctor !== '' && $date !== '' && $timeStart !== '') {
        if (!$doctorDeptOk) {
            $_SESSION['appts_flash'] = 'Selected doctor does not match that department. Choose another doctor or set the doctor\'s primary department under Doctors → Edit.';
        } else {
        if ($ms) {
            if ($hasPatientIdCol && $patient_id > 0) {
                $stmt = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, facility_id, patient_id) VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        'sssssssiii',
                        $appointment_id,
                        $patient_name,
                        $department,
                        $doctor,
                        $date,
                        $time,
                        $message,
                        $status,
                        $fid,
                        $patient_id
                    );
                    $okIns = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            } else {
                $stmt = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status, facility_id) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                if ($stmt) {
                    mysqli_stmt_bind_param(
                        $stmt,
                        'sssssssii',
                        $appointment_id,
                        $patient_name,
                        $department,
                        $doctor,
                        $date,
                        $time,
                        $message,
                        $status,
                        $fid
                    );
                    $okIns = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
            }
        } else {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_appointment (appointment_id, patient_name, department, doctor, date, time, message, status) VALUES (?,?,?,?,?,?,?,?)'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssssssi', $appointment_id, $patient_name, $department, $doctor, $date, $time, $message, $status);
                $okIns = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        if ($okIns) {
            $newApptId = (int) mysqli_insert_id($connection);
            $is_telemedicine = 0;
            $meeting_link = null;
            if (strpos(strtolower($consult), 'telehealth') !== false || strpos(strtolower($consult), 'video') !== false) {
                $is_telemedicine = 1;
                $meeting_link = 'https://meet.jit.si/hms_telehealth_' . bin2hex(random_bytes(4));
            }
            if ($is_telemedicine === 1) {
                $upd = mysqli_prepare($connection, 'UPDATE tbl_appointment SET is_telemedicine = ?, meeting_link = ? WHERE id = ?');
                if ($upd) {
                    mysqli_stmt_bind_param($upd, 'isi', $is_telemedicine, $meeting_link, $newApptId);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }
            }
            hms_audit_log($connection, 'appointment.create', 'appointment', $newApptId);
            $_SESSION['appts_flash'] = 'Appointment created: ' . $appointment_id . ' (listed under Requests until confirmed or visit/consultation).';
        } else {
            $_SESSION['appts_flash'] = 'Could not create appointment. Check required fields and try again.';
        }
        }
    } else {
        $_SESSION['appts_flash'] = 'Department, doctor, date, and start time are required.';
    }
    header('Location: appointments.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

$qRaw = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}
$bucket = (string) ($_GET['f'] ?? 'all');
if (!in_array($bucket, ['all', 'upcoming', 'inprogress', 'completed', 'inactive'], true)) {
    $bucket = 'all';
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;

$dateNorm = "DATE(COALESCE(STR_TO_DATE(NULLIF(TRIM(a.date),''), '%d/%m/%Y'), STR_TO_DATE(NULLIF(TRIM(a.date),''), '%Y-%m-%d')))";
$bucketSql = "CASE WHEN a.status <> 1 THEN 'inactive' WHEN {$dateNorm} IS NULL THEN 'upcoming' WHEN {$dateNorm} > CURDATE() THEN 'upcoming' WHEN {$dateNorm} < CURDATE() THEN 'completed' ELSE 'inprogress' END";

$w = [];
if ($ms) {
    $w[] = 'a.facility_id = ' . (int) $fid;
}
if ($qRaw !== '') {
    $like = mysqli_real_escape_string($connection, $qRaw);
    $parts = [
        "a.appointment_id LIKE '%{$like}%'",
        "a.patient_name LIKE '%{$like}%'",
        "a.doctor LIKE '%{$like}%'",
        "a.department LIKE '%{$like}%'",
        "a.message LIKE '%{$like}%'",
    ];
    if ($hasPatientIdCol) {
        $parts[] = "IFNULL(p.first_name,'') LIKE '%{$like}%'";
        $parts[] = "IFNULL(p.last_name,'') LIKE '%{$like}%'";
    }
    $w[] = '(' . implode(' OR ', $parts) . ')';
}
$whereBase = $w === [] ? '1=1' : implode(' AND ', $w);

$docJoin = 'LEFT JOIN tbl_employee doc ON doc.role = 2 AND doc.status = 1 AND TRIM(CONCAT(COALESCE(doc.first_name,\'\'),\' \',COALESCE(doc.last_name,\'\'))) = TRIM(a.doctor)';
if ($ms && $hasUserFacilityTbl) {
    $docJoin .= ' AND EXISTS (SELECT 1 FROM tbl_user_facility uf WHERE uf.employee_id = doc.id AND uf.facility_id = ' . (int) $fid . ')';
}

$patJoin = $hasPatientIdCol
    ? 'LEFT JOIN tbl_patient p ON p.id = a.patient_id AND a.patient_id IS NOT NULL AND a.patient_id > 0'
    : 'LEFT JOIN tbl_patient p ON 1=0';

$innerSelect = "SELECT a.*, p.first_name AS p_fn, p.last_name AS p_ln, doc.id AS doc_id, doc.photo_path AS doc_photo, {$bucketSql} AS appt_bucket, {$dateNorm} AS d_norm FROM tbl_appointment a {$patJoin} {$docJoin} WHERE {$whereBase}";

$countSql = "SELECT COUNT(*) AS c FROM ({$innerSelect}) x WHERE ('" . mysqli_real_escape_string($connection, $bucket) . "' = 'all' OR x.appt_bucket = '" . mysqli_real_escape_string($connection, $bucket) . "')";
$total = 0;
$cq = mysqli_query($connection, $countSql);
if ($cq) {
    $cr = mysqli_fetch_assoc($cq);
    $total = (int) ($cr['c'] ?? 0);
}
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$orderOuter = $sort === 'oldest' ? 'x.d_norm ASC, x.id ASC' : 'x.d_norm DESC, x.id DESC';
$listSql = "SELECT x.* FROM ({$innerSelect}) x WHERE ('" . mysqli_real_escape_string($connection, $bucket) . "' = 'all' OR x.appt_bucket = '" . mysqli_real_escape_string($connection, $bucket) . "') ORDER BY "
    . $orderOuter
    . ' LIMIT ' . (int) $offset . ', ' . (int) $perPage;

$rows = [];
$lq = mysqli_query($connection, $listSql);
while ($lq && $r = mysqli_fetch_assoc($lq)) {
    $rows[] = $r;
}

$patientOptions = [];
$deptOptions = [];
$doctorRows = [];
$apptDoctorJs = [];
if ($canWrite) {
    $psuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
    $pq = mysqli_query(
        $connection,
        'SELECT id, CONCAT(first_name,\' \',last_name) AS name, dob FROM tbl_patient' . $psuf . ' ORDER BY last_name, first_name LIMIT 600'
    );
    while ($pq && $pr = mysqli_fetch_assoc($pq)) {
        $patientOptions[] = $pr;
    }
    $dsuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
    $dq = mysqli_query($connection, 'SELECT department_name FROM tbl_department' . $dsuf . ' ORDER BY department_name');
    while ($dq && $dr = mysqli_fetch_assoc($dq)) {
        $deptOptions[] = (string) $dr['department_name'];
    }
    $docSelCols = $hasEmployeeDeptCol
        ? 'e.id, e.first_name, e.last_name, e.photo_path, e.primary_department'
        : 'e.id, e.first_name, e.last_name, e.photo_path';
    if ($ms && $hasUserFacilityTbl) {
        $drq = mysqli_query(
            $connection,
            'SELECT ' . $docSelCols . ' FROM tbl_employee e
             INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
             WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ' . (int) $fid . ' ORDER BY e.last_name, e.first_name'
        );
    } else {
        $docSelColsSimple = $hasEmployeeDeptCol
            ? 'id, first_name, last_name, photo_path, primary_department'
            : 'id, first_name, last_name, photo_path';
        $drq = mysqli_query($connection, 'SELECT ' . $docSelColsSimple . ' FROM tbl_employee WHERE role = 2 AND status = 1 ORDER BY last_name, first_name');
    }
    while ($drq && $er = mysqli_fetch_assoc($drq)) {
        $doctorRows[] = $er;
    }
    foreach ($doctorRows as $dr) {
        $apptDoctorJs[] = [
            'name' => trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']),
            'primary_department' => $hasEmployeeDeptCol ? trim((string) ($dr['primary_department'] ?? '')) : '',
        ];
    }
}

$qsBase = [
    'q' => $qRaw,
    'sort' => $sort,
    'f' => $bucket,
];

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
}

include __DIR__ . '/header.php';

?>
        <div class="page-wrapper hms-appts-print-root">
            <div class="content hms-module hms-appts-page hms-appts-dreams">
                <form method="get" action="appointments.php" class="hms-appts-keyword-bar card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <label class="sr-only" for="hmsApptsKeyword">Search keyword</label>
                                <input type="search" name="q" id="hmsApptsKeyword" class="form-control hms-appts-keyword-input" placeholder="Search Keyword" value="<?php echo hms_h($qRaw); ?>" autocomplete="off">
                            </div>
                            <input type="hidden" name="sort" value="<?php echo hms_h($sort); ?>">
                            <input type="hidden" name="f" value="<?php echo hms_h($bucket); ?>">
                            <button type="submit" class="btn btn-primary px-4 hms-appts-keyword-btn" title="Search"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Appointments</h1>
                        <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Appointments</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center hms-appts-dreams-toolbar no-print">
                        <a href="appointments-calendar.php" class="btn btn-light border btn-sm font-weight-bold mr-1" title="Calendar"><i class="fa fa-calendar mr-1"></i> Calendar</a>
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-1" id="hmsApptsRefresh" title="Refresh"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-2" id="hmsApptsPrint" title="Print"><i class="fa fa-print"></i></button>
                        <?php if ($canWrite) { ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold px-3" data-toggle="modal" data-target="#hmsApptAddModal"><i class="fa fa-plus mr-1"></i> New Appointment</button>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($apptFlash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($apptFlash); ?></div>
                <?php } ?>

                <section class="hms-appts-total-section card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Appointments</h2>
                            <span class="hms-appts-count-badge" title="Matches search and status filter"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="appointments.php">
                            <input type="hidden" name="q" value="<?php echo hms_h($qRaw); ?>">
                            <input type="hidden" name="p" value="1">
                            <div class="d-flex flex-wrap align-items-center">
                                <label class="small text-muted mr-2 mb-0" for="hmsApptsSort">Sort By:</label>
                                <select name="sort" id="hmsApptsSort" class="form-control form-control-sm mr-2 hms-appts-sort-select" onchange="this.form.submit()">
                                    <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                                </select>
                                <label class="small text-muted mr-2 mb-0" for="hmsApptsFilter">Status:</label>
                                <select name="f" id="hmsApptsFilter" class="form-control form-control-sm hms-appts-sort-select" onchange="this.form.submit()">
                                    <option value="all"<?php echo $bucket === 'all' ? ' selected' : ''; ?>>All</option>
                                    <option value="upcoming"<?php echo $bucket === 'upcoming' ? ' selected' : ''; ?>>Upcoming</option>
                                    <option value="inprogress"<?php echo $bucket === 'inprogress' ? ' selected' : ''; ?>>Inprogress</option>
                                    <option value="completed"<?php echo $bucket === 'completed' ? ' selected' : ''; ?>>Completed</option>
                                    <option value="inactive"<?php echo $bucket === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 hms-appts-dreams-table">
                                <thead class="hms-appts-dreams-thead">
                                    <tr>
                                        <th>Patient ID</th>
                                        <th>Patient Name</th>
                                        <th>Doctor Name</th>
                                        <th>Department</th>
                                        <th>Appointment Date</th>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rows === []) { ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5">No appointments match your filters.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($rows as $row) {
                                        $st = hms_appt_dreams_status($row);
                                        $pname = hms_appt_patient_display_name($row);
                                        $pinit = hms_appt_patient_initials($row);
                                        $pidLab = hms_appt_patient_display_id($row);
                                        $docName = trim((string) ($row['doctor'] ?? '')) !== '' ? 'Dr. ' . trim((string) $row['doctor']) : '—';
                                        $docAvatar = 'assets/img/doctors/avatar-1.svg';
                                        if (!empty($row['doc_id'])) {
                                            $docAvatar = hms_doctor_avatar_src([
                                                'id' => (int) $row['doc_id'],
                                                'photo_path' => (string) ($row['doc_photo'] ?? ''),
                                            ]);
                                        }
                                        $ymd = hms_appt_parse_date_ymd((string) ($row['date'] ?? ''));
                                        $dateShow = $ymd ? date('j M Y', strtotime($ymd . ' 12:00:00')) : hms_h((string) ($row['date'] ?? ''));
                                        $timeRange = hms_appt_format_time_range((string) ($row['time'] ?? ''));
                                        $dateLine = $dateShow . ', ' . $timeRange;
                                        ?>
                                    <tr class="hms-appts-dreams-row">
                                        <td class="align-middle font-weight-semibold text-dark"><?php echo hms_h($pidLab); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--sm hms-visit-avatar--patient mr-2" aria-hidden="true"><?php echo hms_h($pinit); ?></span>
                                                <span class="font-weight-medium"><?php echo hms_h($pname); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <img src="<?php echo hms_h($docAvatar); ?>" alt="" class="rounded-circle mr-2" width="32" height="32" style="object-fit:cover">
                                                <span><?php echo hms_h($docName); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle"><?php echo hms_h((string) ($row['department'] ?? '')); ?></td>
                                        <td class="align-middle text-muted small">
                                            <?php echo hms_h($dateLine); ?>
                                            <?php if ((int)($row['is_telemedicine'] ?? 0) === 1 && !empty($row['meeting_link'])) { ?>
                                            <br><a href="<?php echo hms_h($row['meeting_link']); ?>" target="_blank" class="badge badge-info mt-1"><i class="fa fa-video-camera"></i> Join Call</a>
                                            <?php } ?>
                                        </td>
                                        <td class="align-middle"><span class="hms-appt-pill <?php echo hms_h($st['pill']); ?>"><?php echo hms_h($st['label']); ?></span></td>
                                        <td class="align-middle text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a class="dropdown-item" href="edit-appointment.php?id=<?php echo (int) $row['id']; ?>"><i class="fa fa-pencil mr-2"></i> Edit</a>
                                                    <?php if ($canWrite) { ?>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this appointment?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_appointment" value="1">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger border-0 bg-transparent p-0 m-0 w-100 text-left"><i class="fa fa-trash-o mr-2"></i> Delete</button>
                                                    </form>
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
                        <nav aria-label="Appointments pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $mk = static function (int $p) use ($qsBase): string {
                                    return 'appointments.php?' . http_build_query(array_merge($qsBase, ['p' => $p]));
                                };
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                ?>
                                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk($prev)); ?>">Prev</a></li>
                                <li class="page-item active"><span class="page-link"><?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?></span></li>
                                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk($next)); ?>">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                    <?php } ?>
                </section>

                <?php if ($canWrite) { ?>
                <div class="modal fade" id="hmsApptAddModal" tabindex="-1" role="dialog" aria-labelledby="hmsApptAddModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <div class="modal-content hms-appt-modal-content border-0 shadow">
                            <div class="modal-header border-bottom">
                                <h5 class="modal-title font-weight-bold" id="hmsApptAddModalLabel">New Appointment</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                            <form method="post" action="appointments.php">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="modal_add_appointment" value="1">
                                <div class="modal-body">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="apptPatient">Select Patient <span class="text-danger">*</span></label>
                                            <select class="form-control select" id="apptPatient" name="patient_name" required style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($patientOptions as $po) {
                                                    $pid = (int) $po['id'];
                                                    $val = $pid . '|' . (string) $po['name'] . ',' . (string) ($po['dob'] ?? '');
                                                    $lab = trim((string) $po['name']);
                                                    ?>
                                                <option value="<?php echo hms_h($val); ?>"><?php echo hms_h($lab); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="apptPatType">Patient Type</label>
                                            <select class="form-control" id="apptPatType" name="patient_type_display">
                                                <option value="">Select</option>
                                                <option value="OutPatient">Outpatient</option>
                                                <option value="InPatient">Inpatient</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="apptDept">Select Department <span class="text-danger">*</span></label>
                                            <select class="form-control select" id="apptDept" name="department" required style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($deptOptions as $dn) { ?>
                                                <option value="<?php echo hms_h($dn); ?>"><?php echo hms_h($dn); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="apptDoc">Select Doctor <span class="text-danger">*</span></label>
                                            <select class="form-control select" id="apptDoc" name="doctor" required style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($doctorRows as $dr) {
                                                    $nm = trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']);
                                                    ?>
                                                <option value="<?php echo hms_h($nm); ?>"><?php echo hms_h($nm); ?></option>
                                                <?php } ?>
                                            </select>
                                            <p id="hmsApptDocFilterHint" class="form-text text-warning small mb-0 d-none" role="status" aria-live="polite"></p>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="apptConsult">Preferred mode of consultation <span class="text-danger">*</span></label>
                                        <select class="form-control" id="apptConsult" name="consultation_mode" required>
                                            <option value="">Select</option>
                                            <option value="In-person">In-person</option>
                                            <option value="Telehealth / video">Telehealth / video</option>
                                            <option value="Phone">Phone</option>
                                        </select>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-4">
                                            <label for="apptDate">Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="apptDate" name="date" required value="<?php echo hms_h(date('Y-m-d')); ?>">
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label for="apptTimeStart">Start time <span class="text-danger">*</span></label>
                                            <input type="time" class="form-control" id="apptTimeStart" name="time_start" required value="<?php echo hms_h(date('H:i')); ?>">
                                        </div>
                                        <div class="form-group col-md-4">
                                            <label for="apptTimeEnd">End time</label>
                                            <input type="time" class="form-control" id="apptTimeEnd" name="time_end" value="">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="apptReason">Reason</label>
                                        <input type="text" class="form-control" id="apptReason" name="reason" placeholder="Reason for visit">
                                    </div>
                                    <div class="form-group">
                                        <label for="apptNotes">Quick notes</label>
                                        <textarea class="form-control" id="apptNotes" name="quick_notes" rows="3" placeholder="Additional information"></textarea>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="apptPay">Mode of payment</label>
                                        <select class="form-control" id="apptPay" name="payment_mode">
                                            <?php foreach (hms_appt_payment_mode_options() as $pm) {
                                                if ($pm === '') {
                                                    echo '<option value="">Select</option>';
                                                } else {
                                                    echo '<option value="' . hms_h($pm) . '">' . hms_h($pm) . '</option>';
                                                }
                                            } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer border-top bg-light">
                                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary px-4">Add Appointment</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
        <script>
        (function () {
            document.getElementById('hmsApptsRefresh') && document.getElementById('hmsApptsRefresh').addEventListener('click', function () { window.location.reload(); });
            document.getElementById('hmsApptsPrint') && document.getElementById('hmsApptsPrint').addEventListener('click', function () { window.print(); });
        })();
        </script>
<?php include 'footer.php'; ?>
        <?php if ($canWrite) { ?>
        <script>window.HMS_APPT_DOCTORS=<?php echo json_encode($apptDoctorJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;window.HMS_DOCTOR_DEPT_FILTER_ACTIVE=<?php echo $hasEmployeeDeptCol ? 'true' : 'false'; ?>;</script>
        <script>
        $(function () {
            var $m = $('#hmsApptAddModal');
            function hmsDestroySelect2IfAny($el) {
                if ($el.length && $el.data('select2')) {
                    $el.select2('destroy');
                }
            }
            function hmsDeptMatchesDoctor(pd, dept, filterOn) {
                if (!filterOn || !dept) {
                    return true;
                }
                var a = String(pd || '').trim().toLowerCase();
                var b = String(dept || '').trim().toLowerCase();
                return a !== '' && a === b;
            }
            function hmsRebuildApptDoctorSelect() {
                var $doc = $('#apptDoc');
                var $dept = $('#apptDept');
                if (!$doc.length) {
                    return;
                }
                var dept = String($dept.val() || '').trim();
                var list = window.HMS_APPT_DOCTORS || [];
                var filterOn = !!window.HMS_DOCTOR_DEPT_FILTER_ACTIVE;
                var had = String($doc.val() || '');
                hmsDestroySelect2IfAny($doc);
                $doc.empty();
                $doc.append($('<option/>').attr('value', '').text('Select'));
                list.forEach(function (d) {
                    var pd = (d.primary_department || '').trim();
                    if (!hmsDeptMatchesDoctor(pd, dept, filterOn)) {
                        return;
                    }
                    $doc.append($('<option/>').attr('value', d.name).text(d.name));
                });
                var $match = $doc.find('option').filter(function () { return $(this).val() === had; });
                if (!$match.length) {
                    had = '';
                }
                $doc.val(had);
                $doc.select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                var $hint = $('#hmsApptDocFilterHint');
                if ($hint.length) {
                    if (filterOn && dept && $doc.find('option').length <= 1) {
                        $hint.removeClass('d-none').text('No doctors with this department on file. Set each doctor\'s department under Doctors → Edit, or pick another department.');
                    } else {
                        $hint.addClass('d-none').text('');
                    }
                }
            }
            if ($m.length && $.fn.select2) {
                hmsDestroySelect2IfAny($('#apptPatient'));
                hmsDestroySelect2IfAny($('#apptDept'));
                hmsDestroySelect2IfAny($('#apptDoc'));
                $('#apptPatient').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                $('#apptDept').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                hmsRebuildApptDoctorSelect();
                $('#apptDept').on('change.hmsApptDeptDoc select2:select.hmsApptDeptDoc select2:clear.hmsApptDeptDoc', function () {
                    window.setTimeout(hmsRebuildApptDoctorSelect, 0);
                });
                $m.on('shown.bs.modal', function () {
                    window.setTimeout(hmsRebuildApptDoctorSelect, 0);
                });
            }
        });
        </script>
        <?php } ?>
