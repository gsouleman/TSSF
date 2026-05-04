<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'opd.read');
$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$uid = (int) ($_SESSION['user_id'] ?? 0);
$canWrite = hms_can($connection, 'opd.write');
$ok = hms_opd_tables_ready($connection);
$flash = isset($_SESSION['opd_flash']) ? (string) $_SESSION['opd_flash'] : '';
unset($_SESSION['opd_flash']);

if ($ok && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (isset($_POST['add_visit'])) {
        $pid = (int) ($_POST['patient_id'] ?? 0);
        $cc = trim((string) ($_POST['chief_complaint'] ?? ''));
        $dept = trim((string) ($_POST['department'] ?? ''));
        $pri = (string) ($_POST['priority'] ?? 'normal');
        if (!in_array($pri, ['normal', 'urgent'], true)) {
            $pri = 'normal';
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
        if (!$pok || $pid < 1) {
            $flash = 'Select a valid patient for this site.';
        } else {
            $ticket = hms_opd_next_ticket_number($connection, $fid);
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_opd_visit (facility_id, patient_id, ticket_number, queue_status, chief_complaint, department, priority, visit_date, queue_started_at, created_by) VALUES (?,?,?,?,?,?,?,CURDATE(),NOW(),?)'
            );
            if ($st) {
                $qs = 'registered';
                mysqli_stmt_bind_param($st, 'iisssssi', $fid, $pid, $ticket, $qs, $cc, $dept, $pri, $uid);
                if (mysqli_stmt_execute($st)) {
                    $vid = (int) mysqli_insert_id($connection);
                    if ($vid > 0) {
                        hms_opd_visit_attach_facility_admission_after_insert($connection, $fid, $pid, $uid, $vid, 'OPD queue (arrival)');
                    }
                    hms_audit_log($connection, 'opd.visit.create', 'opd_visit', $vid, ['ticket' => $ticket]);
                    $flash = 'Visit added: ' . $ticket;
                } else {
                    $flash = 'Could not create visit (duplicate ticket — retry).';
                }
                mysqli_stmt_close($st);
            }
        }
    } elseif (isset($_POST['advance_visit'])) {
        $vid = (int) ($_POST['visit_id'] ?? 0);
        $st = mysqli_prepare(
            $connection,
            'SELECT queue_status, patient_id FROM tbl_opd_visit WHERE id = ? AND facility_id = ? LIMIT 1'
        );
        $cur = '';
        $visitPatientId = 0;
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $vid, $fid);
            mysqli_stmt_execute($st);
            $rw = hms_stmt_fetch_assoc($st);
            mysqli_stmt_close($st);
            $cur = (string) ($rw['queue_status'] ?? '');
            $visitPatientId = (int) ($rw['patient_id'] ?? 0);
        }
        $next = hms_opd_next_status($cur);
        if ($vid < 1 || $next === null) {
            $flash = 'Cannot advance this visit.';
        } else {
            $advOk = false;
            $admLinked = false;
            if ($next === 'completed') {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_opd_visit SET queue_status = ?, completed_at = NOW() WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'sii', $next, $vid, $fid);
                    mysqli_stmt_execute($up);
                    $advOk = mysqli_stmt_affected_rows($up) > 0;
                    mysqli_stmt_close($up);
                }
                $admLinked = false;
                if ($advOk && $visitPatientId > 0 && hms_opd_link_visit_to_open_admission($connection, $fid, $visitPatientId, $vid)) {
                    $admLinked = true;
                    hms_audit_log($connection, 'opd.admission.link', 'opd_visit', $vid, ['patient_id' => $visitPatientId]);
                }
                if ($advOk && $next === 'completed' && $visitPatientId > 0) {
                    hms_facility_admission_after_opd_terminal($connection, $fid, $vid, $visitPatientId);
                }
            } else {
                $up = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_opd_visit SET queue_status = ?, completed_at = NULL WHERE id = ? AND facility_id = ? LIMIT 1'
                );
                if ($up) {
                    mysqli_stmt_bind_param($up, 'sii', $next, $vid, $fid);
                    mysqli_stmt_execute($up);
                    $advOk = mysqli_stmt_affected_rows($up) > 0;
                    mysqli_stmt_close($up);
                }
            }
            if ($advOk) {
                hms_audit_log($connection, 'opd.visit.advance', 'opd_visit', $vid, ['status' => $next]);
                if ($next === 'completed') {
                    $flash = (!empty($admLinked))
                        ? 'Visit completed. Inpatient admission was linked to this OPD visit (ticket).'
                        : 'Visit completed.';
                } else {
                    $flash = 'Status updated.';
                }
            } else {
                $flash = 'Could not update visit (already completed or removed).';
            }
        }
    } elseif (isset($_POST['cancel_visit'])) {
        $vid = (int) ($_POST['visit_id'] ?? 0);
        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if ($vid < 1) {
            $flash = 'Invalid visit.';
        } else {
            $cancelPid = 0;
            $pr = mysqli_prepare($connection, 'SELECT patient_id FROM tbl_opd_visit WHERE id = ? AND facility_id = ? LIMIT 1');
            if ($pr) {
                mysqli_stmt_bind_param($pr, 'ii', $vid, $fid);
                mysqli_stmt_execute($pr);
                $prw = hms_stmt_fetch_assoc($pr);
                mysqli_stmt_close($pr);
                $cancelPid = (int) ($prw['patient_id'] ?? 0);
            }
            $st = mysqli_prepare(
                $connection,
                'UPDATE tbl_opd_visit SET queue_status = \'cancelled\', cancelled_reason = ?, completed_at = NOW() WHERE id = ? AND facility_id = ? AND queue_status <> \'completed\' LIMIT 1'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'sii', $reason, $vid, $fid);
                mysqli_stmt_execute($st);
                $didCancel = mysqli_stmt_affected_rows($st) > 0;
                mysqli_stmt_close($st);
                if ($didCancel) {
                    hms_audit_log($connection, 'opd.visit.cancel', 'opd_visit', $vid);
                    if ($cancelPid > 0) {
                        hms_facility_admission_after_opd_terminal($connection, $fid, $vid, $cancelPid);
                    }
                    $flash = 'Visit cancelled.';
                } else {
                    $flash = 'Could not cancel this visit (already completed or removed).';
                }
            }
        }
    }
    $_SESSION['opd_flash'] = $flash;
    header('Location: opd-queue.php');
    exit;
}

include 'header.php';
$filter = trim((string) ($_GET['status'] ?? 'active'));
$suf = $ms ? ' WHERE facility_id = ' . (int) $fid : '';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('OPD queue', [
                'subtitle' => 'Canonical same-day queue: register → triage → doctor → orders → billing → done. Use Visits for search, date range, and extra visit fields.',
                'breadcrumbs' => [['Front desk', null], ['OPD queue', '']],
                'secondary' => [
                    ['label' => 'Patients', 'url' => 'patients.php', 'icon' => 'fa-users'],
                    ['label' => 'Ward & Bed MGT', 'url' => 'adt-board.php', 'icon' => 'fa-bed'],
                    ['label' => 'Consultations', 'url' => 'consultations.php', 'icon' => 'fa-comments'],
                ],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>004_opd_queue_admission.sql</code>.</div>
            <?php } else { ?>
            <div class="btn-group mb-3 flex-wrap">
                <a class="btn btn-sm <?php echo $filter === 'active' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="opd-queue.php?status=active">Active today</a>
                <a class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="opd-queue.php?status=all">All today</a>
                <a class="btn btn-sm <?php echo $filter === 'completed' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="opd-queue.php?status=completed">Completed</a>
                <a class="btn btn-sm <?php echo $filter === 'cancelled' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="opd-queue.php?status=cancelled">Cancelled</a>
            </div>
            <?php if ($canWrite) { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4">
                <div class="card-header bg-white font-weight-bold">Add to queue</div>
                <div class="card-body">
                    <form method="post" class="row">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group col-md-4">
                            <label>Patient</label>
                            <select name="patient_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php
                                $pq = mysqli_query(
                                    $connection,
                                    'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name, first_name LIMIT 800'
                                );
                                while ($pq && $pr = mysqli_fetch_assoc($pq)) {
                                    echo '<option value="' . (int) $pr['id'] . '">' . hms_h($pr['last_name'] . ', ' . $pr['first_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group col-md-2">
                            <label>Priority</label>
                            <select name="priority" class="form-control">
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label>Department / service</label>
                            <input class="form-control" name="department" placeholder="e.g. General OPD">
                        </div>
                        <div class="form-group col-md-3">
                            <label>Chief complaint (optional)</label>
                            <input class="form-control" name="chief_complaint" placeholder="Reason for visit">
                        </div>
                        <div class="form-group col-12 mb-0">
                            <button type="submit" name="add_visit" value="1" class="btn btn-primary">Add visit &amp; ticket</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Ticket</th><th>Patient</th><th>Status</th><th>Dept</th><th>Priority</th><th>Started</th><th></th></tr></thead>
                            <tbody>
                            <?php
                            $where = 'v.facility_id = ' . (int) $fid . " AND v.visit_date = CURDATE()";
                            if ($filter === 'active') {
                                $where .= " AND v.queue_status NOT IN ('completed','cancelled')";
                            } elseif ($filter === 'completed') {
                                $where .= " AND v.queue_status = 'completed'";
                            } elseif ($filter === 'cancelled') {
                                $where .= " AND v.queue_status = 'cancelled'";
                            }
                            $sql = 'SELECT v.id, v.ticket_number, v.queue_status, v.department, v.priority, v.queue_started_at,
                                    v.patient_id, p.first_name, p.last_name
                                    FROM tbl_opd_visit v JOIN tbl_patient p ON p.id = v.patient_id WHERE ' . $where . ' ORDER BY v.priority = \'urgent\' DESC, v.queue_started_at ASC, v.id ASC';
                            $q = mysqli_query($connection, $sql);
                            $any = false;
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $any = true;
                                $vid = (int) $r['id'];
                                $pid = (int) $r['patient_id'];
                                $next = hms_opd_next_status((string) $r['queue_status']);
                                echo '<tr id="hmsOpdVisit-' . $vid . '">';
                                echo '<td class="text-monospace font-weight-bold">' . hms_h((string) $r['ticket_number']) . '</td>';
                                echo '<td>' . hms_h(trim((string) $r['first_name'] . ' ' . (string) $r['last_name'])) . '</td>';
                                echo '<td><span class="badge badge-light text-dark">' . hms_h(hms_opd_status_label((string) $r['queue_status'])) . '</span></td>';
                                echo '<td class="small">' . hms_h((string) $r['department']) . '</td>';
                                echo '<td>' . hms_h((string) $r['priority']) . '</td>';
                                echo '<td class="small text-nowrap">' . hms_h((string) $r['queue_started_at']) . '</td>';
                                echo '<td class="text-right text-nowrap">';
                                if (hms_can($connection, 'clinical.read')) {
                                    echo '<a class="btn btn-xs btn-outline-secondary py-0" href="patient-chart.php?id=' . $pid . '">Chart</a> ';
                                }
                                if ($canWrite && !in_array((string) $r['queue_status'], ['completed', 'cancelled'], true)) {
                                    if ($next !== null) {
                                        echo '<form method="post" class="d-inline">';
                                        echo hms_csrf_field();
                                        echo '<input type="hidden" name="visit_id" value="' . $vid . '">';
                                        echo '<button type="submit" name="advance_visit" value="1" class="btn btn-xs btn-primary py-0">Next: ' . hms_h(hms_opd_status_label($next)) . '</button></form> ';
                                    }
                                    echo '<a class="btn btn-xs btn-outline-primary py-0" href="consultation-new.php?patient_id=' . $pid . '">Consult</a> ';
                                    echo '<a class="btn btn-xs btn-outline-primary py-0" href="prescription-new.php?patient_id=' . $pid . '">Rx</a> ';
                                    echo '<a class="btn btn-xs btn-outline-success py-0" href="adt-board.php?opd_visit_id=' . $vid . '&patient_id=' . $pid . '&admitted_from=opd">Admit</a> ';
                                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Cancel this visit?\');">';
                                    echo hms_csrf_field();
                                    echo '<input type="hidden" name="visit_id" value="' . $vid . '">';
                                    echo '<input type="hidden" name="cancel_reason" value="Cancelled from queue">';
                                    echo '<button type="submit" name="cancel_visit" value="1" class="btn btn-xs btn-outline-danger py-0">Cancel</button></form>';
                                }
                                echo '</td>';
                                echo '</tr>';
                            }
                            if (!$any) {
                                echo '<tr><td colspan="7" class="text-muted text-center py-4">No visits for this filter.</td></tr>';
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
