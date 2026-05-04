<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/appointments_dreams.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'opd.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$ok = hms_opd_tables_ready($connection);
$canWrite = hms_can($connection, 'opd.write');
$canClinical = hms_can($connection, 'clinical.read');
$canPatient = hms_can($connection, 'patient.read');
$canConsult = hms_can($connection, 'consult.write') && hms_can($connection, 'patient.read');
$uid = (int) ($_SESSION['user_id'] ?? 0);

$hasDocCol = $ok && hms_visit_registry_has_doctor_column($connection);
$hasTreatCol = $ok && hms_visit_registry_has_treatment_column($connection);
$hasPayCol = $ok && hms_visit_registry_has_payment_column($connection);
$hasUserFacilityTbl = hms_db_table_exists($connection, 'tbl_user_facility');
$hasEmployeeDeptCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');

$defaultTo = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-90 days'));

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = $defaultFrom;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = $defaultTo;
}
if (strtotime($dateFrom) > strtotime($dateTo)) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'completed', 'cancelled'], true)) {
    $statusFilter = 'all';
}

$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}

$qRaw = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;
$today = date('Y-m-d');

$visitFlash = isset($_SESSION['visits_flash']) ? (string) $_SESSION['visits_flash'] : '';
unset($_SESSION['visits_flash']);

if ($ok && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['modal_add_visit']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $deptName = trim((string) ($_POST['department_name'] ?? ''));
    $docId = (int) ($_POST['assigned_doctor_id'] ?? 0);
    $vdate = trim((string) ($_POST['visit_date'] ?? ''));
    $vtime = trim((string) ($_POST['visit_time'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $treatment = trim((string) ($_POST['treatment_note'] ?? ''));
    $payMode = trim((string) ($_POST['payment_mode'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vdate)) {
        $vdate = $today;
    }
    if ($vtime === '' || !preg_match('/^\d{1,2}:\d{2}/', $vtime)) {
        $vtime = date('H:i');
    }
    if (preg_match('/^\d{1,2}:\d{2}$/', $vtime)) {
        $vtime .= ':00';
    }
    $queueStarted = $vdate . ' ' . $vtime;
    $tsStart = strtotime($queueStarted);
    if ($tsStart === false) {
        $queueStarted = $vdate . ' 09:00:00';
    }

    $chk = $ms
        ? mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1')
        : mysqli_prepare($connection, 'SELECT id FROM tbl_patient WHERE id = ? LIMIT 1');
    $pok = false;
    if ($chk) {
        if ($ms) {
            mysqli_stmt_bind_param($chk, 'ii', $pid, $fid);
        } else {
            mysqli_stmt_bind_param($chk, 'i', $pid);
        }
        mysqli_stmt_execute($chk);
        $pok = (bool) hms_stmt_fetch_assoc($chk);
        mysqli_stmt_close($chk);
    }

    $docOk = true;
    if ($docId > 0 && $hasDocCol) {
        $docOk = false;
        if ($ms && $hasUserFacilityTbl) {
            $dq = mysqli_prepare(
                $connection,
                'SELECT e.id FROM tbl_employee e INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id WHERE e.id = ? AND e.role = 2 AND uf.facility_id = ? LIMIT 1'
            );
            if ($dq) {
                mysqli_stmt_bind_param($dq, 'ii', $docId, $fid);
                mysqli_stmt_execute($dq);
                $docOk = (bool) hms_stmt_fetch_assoc($dq);
                mysqli_stmt_close($dq);
            }
        } else {
            $dq = mysqli_prepare($connection, 'SELECT id FROM tbl_employee WHERE id = ? AND role = 2 LIMIT 1');
            if ($dq) {
                mysqli_stmt_bind_param($dq, 'i', $docId);
                mysqli_stmt_execute($dq);
                $docOk = (bool) hms_stmt_fetch_assoc($dq);
                mysqli_stmt_close($dq);
            }
        }
    } elseif ($docId > 0 && !$hasDocCol) {
        $docId = 0;
    }

    $deptDocMismatch = false;
    if ($hasEmployeeDeptCol && $docOk && $docId > 0 && $deptName !== '') {
        $pdSt = mysqli_prepare($connection, 'SELECT primary_department FROM tbl_employee WHERE id = ? AND role = 2 LIMIT 1');
        if ($pdSt) {
            mysqli_stmt_bind_param($pdSt, 'i', $docId);
            mysqli_stmt_execute($pdSt);
            $pdRow = hms_stmt_fetch_assoc($pdSt);
            mysqli_stmt_close($pdSt);
            $pdVal = trim((string) ($pdRow['primary_department'] ?? ''));
            if (strcasecmp($pdVal, trim($deptName)) !== 0) {
                $deptDocMismatch = true;
            }
        }
    }

    if (!$pok || $pid < 1) {
        $_SESSION['visits_flash'] = 'Select a valid patient for this site.';
    } elseif (!$docOk) {
        $_SESSION['visits_flash'] = 'Select a valid doctor for this site.';
    } elseif ($deptDocMismatch) {
        $_SESSION['visits_flash'] = 'That doctor is not assigned to the selected department. Pick another doctor or set their primary department under Doctors → Edit.';
    } else {
        $maxAttempts = 5;
        $lastError = '';
        $inserted = false;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $ticket = hms_opd_next_ticket_number($connection, $fid, $vdate);
                $qs = 'registered';
                $pri = 'normal';
                $cc = $reason;
                $dept = $deptName;

                $inserted = false;
                $tVisit13Doc = 'ii' . str_repeat('s', 7) . 'iiss';
                $tVisit12TreatPay = 'ii' . str_repeat('s', 7) . 'iss';
                $tVisit11Doc = 'ii' . str_repeat('s', 7) . 'ii';
                $tVisit10Base = 'ii' . str_repeat('s', 7) . 'i';
                if ($hasDocCol && $hasTreatCol && $hasPayCol) {
                    if ($docId > 0) {
                        $st = mysqli_prepare(
                            $connection,
                            'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by, assigned_doctor_id, treatment_note, payment_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        if ($st) {
                            mysqli_stmt_bind_param($st, $tVisit13Doc, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid, $docId, $treatment, $payMode);
                            $inserted = mysqli_stmt_execute($st);
                            mysqli_stmt_close($st);
                        }
                    } else {
                        $st = mysqli_prepare(
                            $connection,
                            'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by, treatment_note, payment_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        if ($st) {
                            mysqli_stmt_bind_param($st, $tVisit12TreatPay, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid, $treatment, $payMode);
                            $inserted = mysqli_stmt_execute($st);
                            mysqli_stmt_close($st);
                        }
                    }
                } elseif ($hasTreatCol && $hasPayCol) {
                    $st = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by, treatment_note, payment_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, $tVisit12TreatPay, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid, $treatment, $payMode);
                        $inserted = mysqli_stmt_execute($st);
                        mysqli_stmt_close($st);
                    }
                } elseif ($hasDocCol) {
                    if ($docId > 0) {
                        $st = mysqli_prepare(
                            $connection,
                            'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by, assigned_doctor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        if ($st) {
                            mysqli_stmt_bind_param($st, $tVisit11Doc, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid, $docId);
                            $inserted = mysqli_stmt_execute($st);
                            mysqli_stmt_close($st);
                        }
                    } else {
                        $st = mysqli_prepare(
                            $connection,
                            'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
                        );
                        if ($st) {
                            mysqli_stmt_bind_param($st, $tVisit10Base, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid);
                            $inserted = mysqli_stmt_execute($st);
                            mysqli_stmt_close($st);
                        }
                    }
                } else {
                    $st = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, $tVisit10Base, $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $vdate, $queueStarted, $uid);
                        $inserted = mysqli_stmt_execute($st);
                        mysqli_stmt_close($st);
                    }
                }

                if (!empty($inserted)) {
                    $newId = (int) mysqli_insert_id($connection);
                    if ($newId > 0) {
                        hms_opd_visit_attach_facility_admission_after_insert($connection, $fid, $pid, $uid, $newId, 'OPD visit registry (arrival)');
                    }
                    hms_appointment_clear_requests_for_patient($connection, $pid, $fid);
                    hms_audit_log($connection, 'opd.visit.create', 'opd_visit', $newId, ['ticket' => $ticket, 'source' => 'visits_modal']);
                    $_SESSION['visits_flash'] = 'Visit added: ' . $ticket;
                    break; // success — exit retry loop
                } else {
                    $lastError = 'Could not create visit. Run migration 008 for full fields, or retry.';
                }
            } catch (\Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('visits.php modal_add_visit (attempt ' . $attempt . '): ' . $e->getMessage());
                }
                $isDuplicate = (
                    ($e instanceof \mysqli_sql_exception && $e->getCode() === 1062)
                    || stripos($e->getMessage(), 'Duplicate') !== false
                );
                if ($isDuplicate && $attempt < $maxAttempts) {
                    // Retry with a fresh ticket — sleep briefly to avoid race conditions
                    usleep(50000);
                    continue;
                }
                $isAdmin = ((string) ($_SESSION['role'] ?? '') === '1');
                $lastError = 'Could not save the visit. '
                    . ($isAdmin ? 'Error: ' . $e->getMessage() : 'Please try again.');
                break; // non-duplicate error — stop retrying
            }
        }
        if (empty($inserted) && $lastError !== '') {
            $_SESSION['visits_flash'] = $lastError;
        }
    }
    header('Location: visits.php?' . http_build_query(array_filter([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'status' => $statusFilter,
        'sort' => $sort,
        'q' => $qRaw,
        'p' => $page,
        'nc' => (string) time(),
    ])));
    exit;
}

if ($ok && (string) ($_GET['export'] ?? '') === 'csv' && (string) ($_SESSION['role'] ?? '') === '1') {
    $escFrom = mysqli_real_escape_string($connection, $dateFrom);
    $escTo = mysqli_real_escape_string($connection, $dateTo);
    $w = [
        'v.facility_id = ' . (int) $fid,
        "v.visit_date >= '" . $escFrom . "'",
        "v.visit_date <= '" . $escTo . "'",
    ];
    if ($statusFilter === 'active') {
        $w[] = "v.queue_status NOT IN ('completed','cancelled')";
    } elseif ($statusFilter === 'completed') {
        $w[] = "v.queue_status = 'completed'";
    } elseif ($statusFilter === 'cancelled') {
        $w[] = "v.queue_status = 'cancelled'";
    }
    if ($qRaw !== '') {
        $like = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(p.first_name LIKE '%" . $like . "%' OR p.last_name LIKE '%" . $like . "%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%" . $like . "%' OR v.ticket_number LIKE '%" . $like . "%' OR IFNULL(v.department,'') LIKE '%" . $like . "%' OR IFNULL(v.chief_complaint,'') LIKE '%" . $like . "%')";
    }
    $whereSql = implode(' AND ', $w);
    $orderSql = $sort === 'oldest' ? 'v.visit_date ASC, v.queue_started_at ASC, v.id ASC' : 'v.visit_date DESC, v.queue_started_at DESC, v.id DESC';
    $docJoin = $hasDocCol ? ' LEFT JOIN tbl_employee doc ON doc.id = v.assigned_doctor_id AND doc.role = 2 ' : ' ';
    $docSel = $hasDocCol ? ', doc.first_name AS doc_fn, doc.last_name AS doc_ln' : '';
    $extraSel = '';
    if ($hasTreatCol) {
        $extraSel .= ', v.treatment_note';
    }
    if ($hasPayCol) {
        $extraSel .= ', v.payment_mode';
    }
    $sql = 'SELECT v.id, v.ticket_number, v.visit_date, v.queue_status, v.department, v.chief_complaint, v.patient_id, p.first_name, p.last_name' . $docSel . $extraSel
        . ' FROM tbl_opd_visit v INNER JOIN tbl_patient p ON p.id = v.patient_id' . $docJoin
        . ' WHERE ' . $whereSql . ' ORDER BY ' . $orderSql;
    $eq = mysqli_query($connection, $sql);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="visits-export.csv"');
    $out = fopen('php://output', 'w');
    if ($out) {
        fwrite($out, "\xEF\xBB\xBF");
        $hdr = ['Visit ID', 'Ticket', 'Patient', 'Department', 'Doctor', 'Visit date', 'Status', 'Reason'];
        if ($hasTreatCol) {
            $hdr[] = 'Treatment';
        }
        if ($hasPayCol) {
            $hdr[] = 'Payment mode';
        }
        fputcsv($out, $hdr);
        while ($eq && $er = mysqli_fetch_assoc($eq)) {
            $pill = hms_visit_dreams_status_pill((string) $er['queue_status']);
            $dname = '—';
            if ($hasDocCol) {
                $dname = hms_visit_doctor_display_name(['first_name' => $er['doc_fn'] ?? '', 'last_name' => $er['doc_ln'] ?? '']);
            }
            $line = [
                hms_visit_display_id((int) $er['id']),
                (string) $er['ticket_number'],
                trim((string) $er['first_name'] . ' ' . (string) $er['last_name']),
                (string) ($er['department'] ?? ''),
                $dname,
                (string) $er['visit_date'],
                $pill['label'],
                (string) ($er['chief_complaint'] ?? ''),
            ];
            if ($hasTreatCol) {
                $line[] = (string) ($er['treatment_note'] ?? '');
            }
            if ($hasPayCol) {
                $line[] = (string) ($er['payment_mode'] ?? '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
    }
    exit;
}

$rows = [];
$todayRows = [];
$total = 0;
$totalPages = 1;

$patientOptions = [];
$deptOptions = [];
$doctorRows = [];
$visitDoctorJs = [];

if ($ok) {
    $hasPatientTypeCol = hms_db_column_exists($connection, 'tbl_patient', 'patient_type');
    $escFrom = mysqli_real_escape_string($connection, $dateFrom);
    $escTo = mysqli_real_escape_string($connection, $dateTo);
    $w = [
        'v.facility_id = ' . (int) $fid,
        "v.visit_date >= '" . $escFrom . "'",
        "v.visit_date <= '" . $escTo . "'",
    ];
    if ($statusFilter === 'active') {
        $w[] = "v.queue_status NOT IN ('completed','cancelled')";
    } elseif ($statusFilter === 'completed') {
        $w[] = "v.queue_status = 'completed'";
    } elseif ($statusFilter === 'cancelled') {
        $w[] = "v.queue_status = 'cancelled'";
    }
    if ($qRaw !== '') {
        $like = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(p.first_name LIKE '%" . $like . "%' OR p.last_name LIKE '%" . $like . "%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%" . $like . "%' OR v.ticket_number LIKE '%" . $like . "%' OR IFNULL(v.department,'') LIKE '%" . $like . "%' OR IFNULL(v.chief_complaint,'') LIKE '%" . $like . "%')";
    }
    $whereSql = implode(' AND ', $w);

    $cntQ = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_opd_visit v INNER JOIN tbl_patient p ON p.id = v.patient_id WHERE ' . $whereSql
    );
    if ($cntQ) {
        $cr = mysqli_fetch_assoc($cntQ);
        $total = (int) ($cr['c'] ?? 0);
    }
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $orderSql = $sort === 'oldest' ? 'v.visit_date ASC, v.queue_started_at ASC, v.id ASC' : 'v.visit_date DESC, v.queue_started_at DESC, v.id DESC';

    $docJoin = $hasDocCol ? ' LEFT JOIN tbl_employee doc ON doc.id = v.assigned_doctor_id AND doc.role = 2 ' : ' ';
    $docSel = $hasDocCol ? ', doc.id AS doc_id, doc.first_name AS doc_fn, doc.last_name AS doc_ln, doc.photo_path AS doc_photo' : '';
    $extraSel = '';
    if ($hasTreatCol) {
        $extraSel .= ', v.treatment_note';
    }
    if ($hasPayCol) {
        $extraSel .= ', v.payment_mode';
    }

    $patientTypeSel = $hasPatientTypeCol ? ', p.patient_type' : ", 'OutPatient' AS patient_type";
    $baseSelect = 'SELECT v.id, v.ticket_number, v.visit_date, v.queue_status, v.department, v.priority, v.queue_started_at, v.completed_at, v.chief_complaint, v.patient_id, p.first_name, p.last_name, p.phone' . $patientTypeSel
        . $docSel . $extraSel;

    $subPrev = '(SELECT MAX(v3.visit_date) FROM tbl_opd_visit v3 WHERE v3.patient_id = v.patient_id AND v3.facility_id = v.facility_id AND v3.visit_date < v.visit_date) AS prev_visit_date';

    $sql = $baseSelect . ', ' . $subPrev
        . ' FROM tbl_opd_visit v INNER JOIN tbl_patient p ON p.id = v.patient_id' . $docJoin
        . ' WHERE ' . $whereSql
        . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int) $offset . ', ' . (int) $perPage;

    $dataQ = mysqli_query($connection, $sql);
    while ($dataQ && $r = mysqli_fetch_assoc($dataQ)) {
        $rows[] = $r;
    }

    $tw = 'v.facility_id = ' . (int) $fid . " AND v.visit_date = '" . mysqli_real_escape_string($connection, $today) . "' AND v.queue_status <> 'cancelled'";
    $sqlToday = $baseSelect . ', ' . $subPrev
        . ' FROM tbl_opd_visit v INNER JOIN tbl_patient p ON p.id = v.patient_id' . $docJoin
        . ' WHERE ' . $tw
        . " ORDER BY v.priority = 'urgent' DESC, v.queue_started_at ASC, v.id ASC LIMIT 24";
    $tq = mysqli_query($connection, $sqlToday);
    while ($tq && $r = mysqli_fetch_assoc($tq)) {
        $todayRows[] = $r;
    }

    if ($canWrite) {
        $psuf = $ms ? ' WHERE facility_id = ' . (int) $fid . ' AND status = 1' : ' WHERE status = 1';
        $pqSel = 'SELECT id, first_name, last_name' . ($hasPatientTypeCol ? ', patient_type' : ", 'OutPatient' AS patient_type") . ' FROM tbl_patient';
        $pq = mysqli_query($connection, $pqSel . $psuf . ' ORDER BY last_name, first_name LIMIT 600');
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
                 WHERE e.role = 2 AND uf.facility_id = ' . (int) $fid . ' ORDER BY e.last_name, e.first_name'
            );
        } else {
            $docSelColsSimple = $hasEmployeeDeptCol
                ? 'id, first_name, last_name, photo_path, primary_department'
                : 'id, first_name, last_name, photo_path';
            $drq = mysqli_query($connection, 'SELECT ' . $docSelColsSimple . ' FROM tbl_employee WHERE role = 2 ORDER BY last_name, first_name');
        }
        while ($drq && $er = mysqli_fetch_assoc($drq)) {
            $doctorRows[] = $er;
        }
        foreach ($doctorRows as $dr) {
            $visitDoctorJs[] = [
                'id' => (int) $dr['id'],
                'name' => trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']),
                'primary_department' => $hasEmployeeDeptCol ? trim((string) ($dr['primary_department'] ?? '')) : '',
            ];
        }
    }
}

$qsBase = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $statusFilter,
    'sort' => $sort,
    'q' => $qRaw,
];

$todayLabel = date('l, j M Y', strtotime($today . ' 12:00:00'));

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-Accel-Expires: 0');
    header('Vary: *');
}

include __DIR__ . '/header.php';

?>
<!-- hms-render-ts: <?php echo date('Y-m-d H:i:s') . ' total=' . $total . ' todayRows=' . count($todayRows) . ' rows=' . count($rows); ?> -->
        <div class="page-wrapper hms-visits-print-root">
            <div class="content hms-module hms-visits-page hms-visits-dreams">
                <form method="get" action="visits.php" class="hms-visits-keyword-bar card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <label class="sr-only" for="hmsVisitsKeyword">Search keyword</label>
                                <input type="search" name="q" id="hmsVisitsKeyword" class="form-control hms-visits-keyword-input" placeholder="Search Keyword" value="<?php echo hms_h($qRaw); ?>" autocomplete="off">
                            </div>
                            <input type="hidden" name="date_from" value="<?php echo hms_h($dateFrom); ?>">
                            <input type="hidden" name="date_to" value="<?php echo hms_h($dateTo); ?>">
                            <input type="hidden" name="status" value="<?php echo hms_h($statusFilter); ?>">
                            <input type="hidden" name="sort" value="<?php echo hms_h($sort); ?>">
                            <button type="submit" class="btn btn-primary px-4 hms-visits-keyword-btn" title="Search"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-visits-dreams-title mb-1">Visits</h1>
                        <nav aria-label="breadcrumb" class="hms-visits-dreams-bc mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Visits</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center hms-visits-dreams-toolbar no-print">
                        <button type="button" class="btn btn-light border rounded-circle hms-visits-icon-btn mr-1" id="hmsVisitsRefresh" title="Refresh"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle hms-visits-icon-btn mr-1" id="hmsVisitsPrint" title="Print"><i class="fa fa-print"></i></button>
                        <?php if ((string) ($_SESSION['role'] ?? '') === '1') { ?>
                        <a class="btn btn-light border rounded-circle hms-visits-icon-btn mr-2" href="<?php echo hms_h('visits.php?' . http_build_query(array_merge($qsBase, ['export' => 'csv', 'p' => $page]))); ?>" title="Export CSV"><i class="fa fa-download"></i></a>
                        <?php } else { ?>
                        <span class="mr-2"></span>
                        <?php } ?>
                        <?php if ($canWrite) { ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold px-3" data-toggle="modal" data-target="#hmsVisitAddModal"><i class="fa fa-plus mr-1"></i> New Visit</button>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($visitFlash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($visitFlash); ?></div>
                <?php } ?>

                <?php if (!$ok) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    OPD visit tables are not installed yet. Run migration <code>004_opd_queue_admission.sql</code> from <a href="platform-overview.php">Help &amp; setup</a>.
                </div>
                <?php } else { ?>

                <div class="alert alert-light border small mb-3 no-print">
                    <strong>Tip:</strong> use <a href="opd-queue.php">OPD queue</a> as the main same-day workflow; this page is the visit registry (search, date range, doctor/treatment fields).
                </div>

                <?php if (!$hasDocCol) { ?>
                <div class="alert alert-light border small mb-3 no-print">
                    Optional: run <code>008_opd_visit_registry_columns.sql</code> to enable assigned doctor, treatment, and payment fields on visits (and in the Add Visit form).
                </div>
                <?php } ?>

                <section class="hms-visits-today-section card border-0 shadow-sm mb-4 no-print">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="mr-2" style="min-width:0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark">Today Visits</h2>
                            <p class="text-muted small mb-0 mt-1">Only visits on <strong><?php echo hms_h($today); ?></strong> (<?php echo hms_h($todayLabel); ?>). Other days appear in Total Visits when the date filter includes them.</p>
                        </div>
                        <div class="d-flex align-items-center">
                            <button type="button" class="hms-today-nav-btn mr-2" id="hmsTodayVisitsPrev" aria-label="Scroll left"><i class="fa fa-angle-left" aria-hidden="true"></i></button>
                            <button type="button" class="hms-today-nav-btn" id="hmsTodayVisitsNext" aria-label="Scroll right"><i class="fa fa-angle-right" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <div class="card-body pt-0 pb-3">
                        <div class="hms-today-visits-scroll" id="hmsTodayVisitsScroll">
                            <div class="hms-today-visits-grid">
                            <?php
                            if ($todayRows === []) {
                                echo '<p class="text-muted small mb-0 py-3 hms-today-visits-empty">No visits scheduled for today.</p>';
                            }
                            foreach ($todayRows as $tr) {
                                $tvid = (int) $tr['id'];
                                $tpid = (int) $tr['patient_id'];
                                $tpname = trim((string) $tr['first_name'] . ' ' . (string) $tr['last_name']);
                                $tdept = trim((string) ($tr['department'] ?? '')) ?: 'General';
                                $fa = hms_visit_department_fa_icon($tdept);
                                $prev = (string) ($tr['prev_visit_date'] ?? '');
                                $lastLabel = $prev !== '' ? hms_visit_format_date_dreams($prev) : '—';
                                $treat = '';
                                if ($hasTreatCol && trim((string) ($tr['treatment_note'] ?? '')) !== '') {
                                    $treat = trim((string) $tr['treatment_note']);
                                } else {
                                    $treat = trim((string) ($tr['chief_complaint'] ?? ''));
                                }
                                if ($treat === '') {
                                    $treat = '—';
                                }
                                $treatShow = strlen($treat) > 48 ? substr($treat, 0, 45) . '…' : $treat;
                                $docName = $hasDocCol ? hms_visit_doctor_display_name([
                                    'first_name' => $tr['doc_fn'] ?? '',
                                    'last_name' => $tr['doc_ln'] ?? '',
                                ]) : '—';
                                $docAvatar = 'assets/img/doctors/avatar-1.svg';
                                if ($hasDocCol && !empty($tr['doc_id'])) {
                                    $docAvatar = hms_doctor_avatar_src([
                                        'id' => (int) $tr['doc_id'],
                                        'photo_path' => (string) ($tr['doc_photo'] ?? ''),
                                    ]);
                                }
                                $ptInit = hms_visit_patient_initials((string) $tr['first_name'], (string) $tr['last_name']);
                                $qs = (string) $tr['queue_status'];
                                $activeToday = !in_array($qs, ['completed', 'cancelled'], true);
                                $startHref = $canClinical ? 'patient-chart.php?id=' . $tpid : ($canPatient ? 'edit-patient.php?id=' . $tpid : 'opd-queue.php#hmsOpdVisit-' . $tvid);
                                if ($canWrite && $activeToday) {
                                    $startHref = 'opd-queue.php#hmsOpdVisit-' . $tvid;
                                }
                                ?>
                            <article class="hms-today-visit-card">
                                <div class="hms-today-visit-card__hero">
                                    <div class="hms-today-visit-card__pt-photo" aria-hidden="true"><?php echo hms_h($ptInit); ?></div>
                                    <div class="hms-today-visit-card__hero-text">
                                        <div class="hms-today-visit-card__name"><?php echo hms_h($tpname); ?></div>
                                        <div class="hms-today-visit-card__last">Last Visit : <?php echo hms_h($lastLabel); ?></div>
                                    </div>
                                </div>
                                <div class="hms-today-visit-card__mid">
                                    <div class="hms-today-visit-card__col hms-today-visit-card__col--doctor">
                                        <div class="hms-today-visit-card__label">Doctor</div>
                                        <div class="hms-today-visit-card__doctor-row">
                                            <?php if ($hasDocCol && !empty($tr['doc_id'])) { ?>
                                            <img src="<?php echo hms_h($docAvatar); ?>" alt="" class="hms-today-visit-card__doc-photo" width="32" height="32">
                                            <?php } else { ?>
                                            <span class="hms-today-visit-card__doc-photo hms-today-visit-card__doc-photo--placeholder" title="No doctor assigned"><i class="fa fa-user-md" aria-hidden="true"></i></span>
                                            <?php } ?>
                                            <span class="hms-today-visit-card__doc-name"><?php echo hms_h($docName); ?></span>
                                        </div>
                                    </div>
                                    <div class="hms-today-visit-card__col hms-today-visit-card__col--treatment">
                                        <div class="hms-today-visit-card__label hms-today-visit-card__label--right">Treatment</div>
                                        <div class="hms-today-visit-card__treatment"><?php echo hms_h($treatShow); ?></div>
                                    </div>
                                </div>
                                <div class="hms-today-visit-card__footer">
                                    <span class="hms-today-visit-card__dept"><i class="fa <?php echo hms_h($fa); ?> hms-today-visit-card__dept-icon" aria-hidden="true"></i><?php echo hms_h($tdept); ?></span>
                                    <a href="<?php echo hms_h($startHref); ?>" class="hms-today-start-link">Start Visit</a>
                                </div>
                            </article>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="hms-visits-total-section card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Visits</h2>
                            <span class="hms-visits-count-badge" title="Count for the current filters (date range, status, search)"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="visits.php">
                            <input type="hidden" name="q" value="<?php echo hms_h($qRaw); ?>">
                            <input type="hidden" name="p" value="1">
                            <div class="d-flex flex-wrap align-items-center">
                                <label class="small text-muted mr-2 mb-0" for="hmsVisitsSort">Sort By:</label>
                                <select name="sort" id="hmsVisitsSort" class="form-control form-control-sm mr-2 hms-visits-sort-select" onchange="this.form.submit()">
                                    <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                                </select>
                                <input type="date" name="date_from" value="<?php echo hms_h($dateFrom); ?>" class="form-control form-control-sm mr-1" title="From">
                                <input type="date" name="date_to" value="<?php echo hms_h($dateTo); ?>" class="form-control form-control-sm mr-1" title="To">
                                <select name="status" class="form-control form-control-sm mr-1">
                                    <option value="all"<?php echo $statusFilter === 'all' ? ' selected' : ''; ?>>All statuses</option>
                                    <option value="active"<?php echo $statusFilter === 'active' ? ' selected' : ''; ?>>Active</option>
                                    <option value="completed"<?php echo $statusFilter === 'completed' ? ' selected' : ''; ?>>Completed</option>
                                    <option value="cancelled"<?php echo $statusFilter === 'cancelled' ? ' selected' : ''; ?>>Cancelled</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                            </div>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-borderless hms-visits-dreams-table mb-0">
                                <thead>
                                    <tr class="hms-visits-dreams-thead text-uppercase small text-muted">
                                        <th>Visit ID</th>
                                        <th>Patient Name</th>
                                        <th class="d-none d-md-table-cell">Department</th>
                                        <th class="d-none d-lg-table-cell">Doctor Name</th>
                                        <th>Visit Date</th>
                                        <th>Status</th>
                                        <th class="text-right no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                if ($rows === []) {
                                    echo '<tr><td colspan="7" class="text-center text-muted py-5">No visits match your filters.</td></tr>';
                                }
                                foreach ($rows as $r) {
                                    $vid = (int) $r['id'];
                                    $pid = (int) $r['patient_id'];
                                    $vdate = (string) $r['visit_date'];
                                    $qs = (string) $r['queue_status'];
                                    $pill = hms_visit_dreams_status_pill($qs);
                                    $pname = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
                                    $ptInit = hms_visit_patient_initials((string) $r['first_name'], (string) $r['last_name']);
                                    $dept = trim((string) ($r['department'] ?? '')) ?: '—';
                                    $docName = $hasDocCol ? hms_visit_doctor_display_name([
                                        'first_name' => $r['doc_fn'] ?? '',
                                        'last_name' => $r['doc_ln'] ?? '',
                                    ]) : '—';
                                    $docAvatar = 'assets/img/doctors/avatar-1.svg';
                                    if ($hasDocCol && !empty($r['doc_id'])) {
                                        $docAvatar = hms_doctor_avatar_src([
                                            'id' => (int) $r['doc_id'],
                                            'photo_path' => (string) ($r['doc_photo'] ?? ''),
                                        ]);
                                    }
                                    $isToday = ($vdate === $today);
                                    ?>
                                    <tr class="hms-visits-dreams-row">
                                        <td class="align-middle font-weight-bold text-nowrap"><?php echo hms_h(hms_visit_display_id($vid)); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--patient hms-visit-avatar--sm mr-2"><?php echo hms_h($ptInit); ?></span>
                                                <span class="font-weight-bold text-dark"><?php echo hms_h($pname); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle d-none d-md-table-cell text-muted small"><?php echo hms_h($dept); ?></td>
                                        <td class="align-middle d-none d-lg-table-cell">
                                            <div class="d-flex align-items-center">
                                                <?php if ($hasDocCol && !empty($r['doc_id'])) { ?>
                                                <img src="<?php echo hms_h($docAvatar); ?>" alt="" class="hms-visit-avatar-img rounded-circle mr-2" width="32" height="32">
                                                <?php } else { ?>
                                                <span class="hms-visit-avatar hms-visit-avatar--sm mr-2 text-muted"><i class="fa fa-user-md"></i></span>
                                                <?php } ?>
                                                <span class="small"><?php echo hms_h($docName); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle text-nowrap small text-muted"><?php echo hms_h(hms_visit_format_date_dreams($vdate)); ?></td>
                                        <td class="align-middle"><span class="hms-visit-pill <?php echo hms_h($pill['pill']); ?>"><?php echo hms_h($pill['label']); ?></span></td>
                                        <td class="align-middle text-right no-print">
                                            <div class="dropdown d-inline-block">
                                                <button class="btn btn-link text-muted p-0" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Actions"><i class="fa fa-ellipsis-v"></i></button>
                                                <div class="dropdown-menu dropdown-menu-right shadow border-0">
                                                    <?php if ($canClinical) { ?>
                                                    <a class="dropdown-item" href="patient-chart.php?id=<?php echo $pid; ?>">Open chart</a>
                                                    <?php } elseif ($canPatient) { ?>
                                                    <a class="dropdown-item" href="edit-patient.php?id=<?php echo $pid; ?>">Patient profile</a>
                                                    <?php } ?>
                                                    <?php if ($canWrite && $isToday && !in_array($qs, ['completed', 'cancelled'], true)) { ?>
                                                    <a class="dropdown-item" href="opd-queue.php#hmsOpdVisit-<?php echo $vid; ?>">Today’s queue</a>
                                                    <?php } ?>
                                                    <?php if ($canConsult) { ?>
                                                    <a class="dropdown-item" href="consultation-new.php?patient_id=<?php echo $pid; ?>">New consultation</a>
                                                    <?php } ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($totalPages > 1) { ?>
                    <div class="card-footer bg-white border-top-0 py-3 no-print">
                        <nav aria-label="Visits pagination">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php
                                $mk = static function (int $p) use ($qsBase): string {
                                    return 'visits.php?' . http_build_query(array_merge($qsBase, ['p' => $p]));
                                };
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                ?>
                                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk($prev)); ?>">Previous</a>
                                </li>
                                <?php
                                $window = 5;
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $start + $window - 1);
                                $start = max(1, $end - $window + 1);
                                for ($pi = $start; $pi <= $end; $pi++) {
                                    ?>
                                <li class="page-item<?php echo $pi === $page ? ' active' : ''; ?>"><a class="page-link" href="<?php echo hms_h($mk($pi)); ?>"><?php echo $pi; ?></a></li>
                                    <?php
                                }
                                ?>
                                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk($next)); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php } ?>
                </section>

                <?php if ($canWrite) { ?>
                <div class="modal fade" id="hmsVisitAddModal" tabindex="-1" role="dialog" aria-labelledby="hmsVisitAddModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <div class="modal-content hms-visit-modal-content border-0 shadow">
                            <div class="modal-header border-bottom">
                                <h5 class="modal-title font-weight-bold" id="hmsVisitAddModalLabel">Add New Visit</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                            <form method="post" action="visits.php">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="modal_add_visit" value="1">
                                <div class="modal-body">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="visitPatient">Select Patient</label>
                                            <select class="form-control select" id="visitPatient" name="patient_id" required style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($patientOptions as $po) {
                                                    $pid = (int) $po['id'];
                                                    $plab = hms_h(trim((string) $po['last_name'] . ', ' . (string) $po['first_name']));
                                                    $ptype = hms_h((string) ($po['patient_type'] ?? 'OutPatient'));
                                                    ?>
                                                <option value="<?php echo $pid; ?>" data-patient-type="<?php echo $ptype; ?>"><?php echo $plab; ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="visitPatientType">Patient Type</label>
                                            <select class="form-control" id="visitPatientType" name="patient_type_display">
                                                <option value="">Select</option>
                                                <option value="OutPatient">Outpatient</option>
                                                <option value="InPatient">Inpatient</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="visitDepartment">Select Department</label>
                                            <select class="form-control select" id="visitDepartment" name="department_name" style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($deptOptions as $dn) { ?>
                                                <option value="<?php echo hms_h($dn); ?>"><?php echo hms_h($dn); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="visitDoctor">Select Doctor</label>
                                            <select class="form-control select" id="visitDoctor" name="assigned_doctor_id" style="width:100%" <?php echo $hasDocCol ? '' : 'disabled'; ?>>
                                                <option value="0">— None —</option>
                                                <?php foreach ($doctorRows as $dr) {
                                                    $did = (int) $dr['id'];
                                                    $dn = hms_h(trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']));
                                                    ?>
                                                <option value="<?php echo $did; ?>"><?php echo $dn; ?></option>
                                                <?php } ?>
                                            </select>
                                            <?php if (!$hasDocCol) { ?>
                                            <small class="text-muted">Run migration 008 to save doctor on the visit.</small>
                                            <?php } ?>
                                            <?php if ($hasDocCol) { ?>
                                            <p id="hmsVisitDocFilterHint" class="form-text text-warning small mb-0 d-none" role="status" aria-live="polite"></p>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="visitDate">Date of Visit</label>
                                            <input type="date" class="form-control" id="visitDate" name="visit_date" required value="<?php echo hms_h($today); ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="visitTime">Time of Visit</label>
                                            <input type="time" class="form-control" id="visitTime" name="visit_time" value="<?php echo hms_h(date('H:i')); ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="visitReason">Reason</label>
                                        <input type="text" class="form-control" id="visitReason" name="reason" placeholder="Reason for visit">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="visitPayment">Mode of Payment</label>
                                        <select class="form-control" id="visitPayment" name="payment_mode" <?php echo $hasPayCol ? '' : 'disabled'; ?>>
                                            <option value="">Select</option>
                                            <option value="Cash">Cash</option>
                                            <option value="Card">Card</option>
                                            <option value="Insurance">Insurance</option>
                                            <option value="Corporate">Corporate</option>
                                            <option value="MTN Mobile Money (MoMo)">MTN Mobile Money (MoMo)</option>
                                            <option value="Orange Mobile Money (OM)">Orange Mobile Money (OM)</option>
                                        </select>
                                        <?php if (!$hasPayCol) { ?>
                                        <small class="text-muted">Run migration 008 to store payment mode.</small>
                                        <?php } ?>
                                    </div>
                                    <?php if ($hasTreatCol) { ?>
                                    <div class="form-group mt-3 mb-0">
                                        <label for="visitTreatment">Treatment / procedure</label>
                                        <input type="text" class="form-control" id="visitTreatment" name="treatment_note" placeholder="e.g. Electromyography">
                                    </div>
                                    <?php } ?>
                                </div>
                                <div class="modal-footer border-top bg-light">
                                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary px-4">Add Visit</button>
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
            var scrollEl = document.getElementById('hmsTodayVisitsScroll');
            document.getElementById('hmsTodayVisitsPrev') && document.getElementById('hmsTodayVisitsPrev').addEventListener('click', function () {
                if (scrollEl) scrollEl.scrollBy({ left: -280, behavior: 'smooth' });
            });
            document.getElementById('hmsTodayVisitsNext') && document.getElementById('hmsTodayVisitsNext').addEventListener('click', function () {
                if (scrollEl) scrollEl.scrollBy({ left: 280, behavior: 'smooth' });
            });
            document.getElementById('hmsVisitsRefresh') && document.getElementById('hmsVisitsRefresh').addEventListener('click', function () {
                window.location.reload();
            });
            document.getElementById('hmsVisitsPrint') && document.getElementById('hmsVisitsPrint').addEventListener('click', function () {
                window.print();
            });
            var ps = document.getElementById('visitPatient');
            var pt = document.getElementById('visitPatientType');
            if (ps && pt) {
                ps.addEventListener('change', function () {
                    var opt = ps.options[ps.selectedIndex];
                    var t = opt ? opt.getAttribute('data-patient-type') : '';
                    if (t) {
                        pt.value = t;
                    }
                });
            }
        })();
        </script>
<?php include 'footer.php'; ?>
        <?php if ($canWrite && $ok) { ?>
        <script>window.HMS_VISIT_DOCTORS=<?php echo json_encode($visitDoctorJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE); ?>;window.HMS_DOCTOR_DEPT_FILTER_ACTIVE=<?php echo $hasEmployeeDeptCol ? 'true' : 'false'; ?>;</script>
        <script>
        $(function () {
            var $m = $('#hmsVisitAddModal');
            function hmsDestroySelect2IfAny($el) {
                if ($el.length && $el.data('select2')) {
                    $el.select2('destroy');
                }
            }
            function hmsVisitDeptMatchesDoctor(pd, dept, filterOn) {
                if (!filterOn || !dept) {
                    return true;
                }
                var a = String(pd || '').trim().toLowerCase();
                var b = String(dept || '').trim().toLowerCase();
                return a !== '' && a === b;
            }
            function hmsRebuildVisitDoctorSelect() {
                var $vd = $('#visitDoctor');
                if (!$vd.length || $vd.prop('disabled')) {
                    return;
                }
                var dept = String($('#visitDepartment').val() || '').trim();
                var list = window.HMS_VISIT_DOCTORS || [];
                var filterOn = !!window.HMS_DOCTOR_DEPT_FILTER_ACTIVE;
                var had = String($vd.val() || '0');
                hmsDestroySelect2IfAny($vd);
                $vd.empty();
                $vd.append($('<option/>').attr('value', '0').text('— None —'));
                list.forEach(function (d) {
                    var pd = (d.primary_department || '').trim();
                    if (!hmsVisitDeptMatchesDoctor(pd, dept, filterOn)) {
                        return;
                    }
                    $vd.append($('<option/>').attr('value', String(d.id)).text(d.name));
                });
                $vd.val(had);
                if (String($vd.val()) !== had) {
                    had = '0';
                    $vd.val('0');
                }
                $vd.select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                var $hint = $('#hmsVisitDocFilterHint');
                if ($hint.length) {
                    if (filterOn && dept && $vd.find('option').length <= 1) {
                        $hint.removeClass('d-none').text('No doctors with this department on file. Set each doctor\'s department under Doctors → Edit, or pick another department.');
                    } else {
                        $hint.addClass('d-none').text('');
                    }
                }
            }
            if ($m.length && $.fn.select2) {
                hmsDestroySelect2IfAny($('#visitPatient'));
                hmsDestroySelect2IfAny($('#visitDepartment'));
                hmsDestroySelect2IfAny($('#visitDoctor'));
                $('#visitPatient').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                $('#visitDepartment').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select', minimumResultsForSearch: 10 });
                hmsRebuildVisitDoctorSelect();
                $('#visitDepartment').on('change.hmsVisitDeptDoc select2:select.hmsVisitDeptDoc select2:clear.hmsVisitDeptDoc', function () {
                    window.setTimeout(hmsRebuildVisitDoctorSelect, 0);
                });
                $m.on('shown.bs.modal', function () {
                    window.setTimeout(hmsRebuildVisitDoctorSelect, 0);
                });
            }
        });
        </script>
        <?php } ?>
