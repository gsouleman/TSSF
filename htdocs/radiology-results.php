<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'radiology.read');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$hasUserFacilityTbl = hms_db_table_exists($connection, 'tbl_user_facility');
$canWrite = hms_can($connection, 'radiology.write');
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_db_table_exists($connection, 'tbl_radiology_result');

$flash = isset($_SESSION['rad_registry_flash']) ? (string) $_SESSION['rad_registry_flash'] : '';
unset($_SESSION['rad_registry_flash']);

// --- Delete handler ---
if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_rad_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        $st = mysqli_prepare($connection, 'DELETE FROM tbl_radiology_result WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $delId, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        hms_audit_log($connection, 'radiology.delete', 'radiology_result', $delId);
        $_SESSION['rad_registry_flash'] = 'Radiology result removed.';
    }
    header('Location: radiology-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

// --- Add handler ---
if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['modal_add_rad_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $refId = (int) ($_POST['referred_by_id'] ?? 0);
    $examName = trim((string) ($_POST['exam_name'] ?? ''));
    $modality = trim((string) ($_POST['modality'] ?? 'X-Ray'));
    $bodyPart = trim((string) ($_POST['body_part'] ?? ''));
    $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'in_progress', 'received'], true)) {
        $status = 'pending';
    }
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $radCatalogPick = (int) ($_POST['rad_fee_catalog_id'] ?? 0);
    $catRow = $radCatalogPick > 0 ? hms_billing_catalog_row_by_id($connection, $fid, $radCatalogPick) : null;
    $radFee = max(0, (int) ($_POST['rad_fee_xaf'] ?? 0));
    if ($radFee <= 0 && $catRow !== null) {
        $radFee = $catRow['amount'];
    }
    $radPay = hms_billing_normalize_payment_method($_POST['rad_payment_method'] ?? 'Cash');
    $radWantInvoice = isset($_POST['rad_fiscal_document']) && (string) $_POST['rad_fiscal_document'] === 'invoice';
    $radCompanyId = (int) ($_POST['rad_billing_company_id'] ?? 0);

    if ($pid < 1 || $examName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDate)) {
        $_SESSION['rad_registry_flash'] = 'Patient, exam name, and a valid appointment date are required.';
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
            $_SESSION['rad_registry_flash'] = 'Select a valid patient for this site.';
        } else {
            $ins = false;
            $newRadId = 0;
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_radiology_result (facility_id, patient_id, referred_by_id, exam_name, modality, body_part, appointment_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                $st->bind_param('iiissssssi', $fid, $pid, $refId, $examName, $modality, $bodyPart, $apptDate, $status, $notes, $uid);
                $ins = mysqli_stmt_execute($st);
                if ($ins) {
                    $newRadId = (int) mysqli_insert_id($connection);
                }
                mysqli_stmt_close($st);
            }
            if ($ins && $newRadId > 0) {
                hms_audit_log($connection, 'radiology.create', 'radiology_result', $newRadId);
                $_SESSION['rad_registry_flash'] = 'Radiology exam added.';
                if ($radFee > 0 && hms_billing_document_tables_ok($connection) && hms_can($connection, 'billing.write')) {
                    $lineDesc = ($catRow !== null && ($catRow['label'] ?? '') !== '')
                        ? ('Radiology: ' . (string) $catRow['label'])
                        : ('Radiology: ' . $examName);
                    $docType = 'receipt';
                    $companyBind = 0;
                    if ($radWantInvoice && $radCompanyId > 0 && hms_db_table_exists($connection, 'tbl_billing_company')) {
                        $lcc = mysqli_prepare($connection, 'SELECT id FROM tbl_billing_company WHERE id = ? AND facility_id = ? AND status = 1 LIMIT 1');
                        if ($lcc) {
                            mysqli_stmt_bind_param($lcc, 'ii', $radCompanyId, $fid);
                            mysqli_stmt_execute($lcc);
                            if (hms_stmt_fetch_assoc($lcc)) {
                                $docType = 'invoice';
                                $companyBind = $radCompanyId;
                            }
                            mysqli_stmt_close($lcc);
                        }
                    }
                    $docOpts = [
                        'facility_id' => $fid,
                        'patient_id' => $pid,
                        'doc_type' => $docType,
                        'payment_method' => $radPay,
                        'source_module' => 'radiology_fee',
                        'source_pk' => $newRadId,
                        'created_by' => $uid,
                    ];
                    if ($companyBind > 0) {
                        $docOpts['company_id'] = $companyBind;
                    }
                    $docId = hms_billing_create_document(
                        $connection,
                        $docOpts,
                        [
                            [
                                'description' => $lineDesc,
                                'quantity' => 1,
                                'unit_price' => (float) $radFee,
                            ],
                        ]
                    );
                    if (is_int($docId) && $docId > 0) {
                        hms_billing_set_print_prompt($docId);
                        $_SESSION['rad_registry_flash'] .= $docType === 'invoice' ? ' Invoice issued.' : ' Receipt issued.';
                    }
                } elseif ($radFee > 0 && !hms_can($connection, 'billing.write')) {
                    $_SESSION['rad_registry_flash'] .= ' No receipt issued: billing permission is required.';
                }
            } else {
                $_SESSION['rad_registry_flash'] = 'Could not save radiology result.';
            }
        }
    }
    header('Location: radiology-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

// --- Fetch list data ---
$qRaw = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) $sort = 'newest';
$fStatus = (string) ($_GET['f'] ?? 'all');
if (!in_array($fStatus, ['all', 'pending', 'in_progress', 'received'], true)) $fStatus = 'all';
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;

$rows = [];
$total = 0;
$totalPages = 1;
$patientOptions = [];
$doctorRows = [];
$radCatalogRows = [];
$radBillingCompanies = [];

if ($tablesOk) {
    $hasEmpPhoto = function_exists('hms_doctor_photo_column_exists') && hms_doctor_photo_column_exists($connection);
    $docPhotoSql = $hasEmpPhoto ? 'doc.photo_path AS doc_photo' : 'CAST(NULL AS CHAR) AS doc_photo';

    $w = ['r.facility_id = ' . (int) $fid];
    if ($fStatus !== 'all') {
        $escS = mysqli_real_escape_string($connection, $fStatus);
        $w[] = "r.status = '" . $escS . "'";
    }
    if ($qRaw !== '') {
        $like = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(r.exam_name LIKE '%{$like}%' OR r.modality LIKE '%{$like}%' OR r.notes LIKE '%{$like}%' OR p.first_name LIKE '%{$like}%' OR p.last_name LIKE '%{$like}%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%{$like}%')";
    }
    $whereSql = implode(' AND ', $w);
    $baseFrom = 'FROM tbl_radiology_result r INNER JOIN tbl_patient p ON p.id = r.patient_id LEFT JOIN tbl_employee doc ON doc.id = r.referred_by_id WHERE ' . $whereSql;

    $cq = mysqli_query($connection, 'SELECT COUNT(*) AS c ' . $baseFrom);
    if ($cq) $total = (int) (mysqli_fetch_assoc($cq)['c'] ?? 0);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $orderSql = $sort === 'oldest' ? 'r.appointment_date ASC, r.id ASC' : 'r.appointment_date DESC, r.id DESC';
    $lq = mysqli_query(
        $connection,
        'SELECT r.*, p.first_name AS p_fn, p.last_name AS p_ln, p.gender AS p_gender, doc.id AS doc_id, doc.first_name AS ref_fn, doc.last_name AS ref_ln, ' . $docPhotoSql . ' '
        . $baseFrom . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int) $offset . ', ' . (int) $perPage
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
        $empPhotoSel = $hasEmpPhoto ? ', e.photo_path' : ', CAST(NULL AS CHAR) AS photo_path';
        if ($ms && $hasUserFacilityTbl) {
            $drq = mysqli_query(
                $connection,
                'SELECT e.id, e.first_name, e.last_name' . $empPhotoSel . ' FROM tbl_employee e
                 INNER JOIN tbl_user_facility uf ON uf.employee_id = e.id
                 WHERE e.role = 2 AND e.status = 1 AND uf.facility_id = ' . (int) $fid . ' ORDER BY e.last_name, e.first_name'
            );
        } else {
            $drq = mysqli_query(
                $connection,
                'SELECT id, first_name, last_name' . str_replace('e.', '', $empPhotoSel) . ' FROM tbl_employee WHERE role = 2 AND status = 1 ORDER BY last_name, first_name'
            );
        }
        while ($drq && $er = mysqli_fetch_assoc($drq)) {
            $doctorRows[] = $er;
        }
        $radCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'radiology');
        if (hms_db_table_exists($connection, 'tbl_billing_company')) {
            $lbq = mysqli_query(
                $connection,
                'SELECT id, name FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . ' AND status = 1 ORDER BY name LIMIT 300'
            );
            while ($lbq && $lbr = mysqli_fetch_assoc($lbq)) {
                $radBillingCompanies[] = $lbr;
            }
        }
    }
}

$qsBase = ['q' => $qRaw, 'sort' => $sort, 'f' => $fStatus];

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
}

$receiptPrintDoc = hms_billing_take_print_prompt();
include __DIR__ . '/header.php';

$modalModalities = ['X-Ray','Ultrasound','CT Scan','MRI','ECG','Echocardiography','Mammography','Fluoroscopy','DEXA','Other'];
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-appts-dreams">
                <?php if (!$tablesOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Run migration <code>hms/database/migrations/016_radiology_and_nursing.sql</code> to create <code>tbl_radiology_result</code>.
                </div>
                <?php } else { ?>
                <form method="get" action="radiology-results.php" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <label class="sr-only" for="hmsRadKeyword">Search keyword</label>
                                <input type="search" name="q" id="hmsRadKeyword" class="form-control" placeholder="Search Keyword" value="<?php echo hms_h($qRaw); ?>" autocomplete="off">
                            </div>
                            <input type="hidden" name="sort" value="<?php echo hms_h($sort); ?>">
                            <input type="hidden" name="f" value="<?php echo hms_h($fStatus); ?>">
                            <button type="submit" class="btn btn-primary px-4" title="Search" style="background:#0891b2;border-color:#0891b2;"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Radiology Results</h1>
                        <nav aria-label="breadcrumb" class="mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Radiology Results</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center no-print">
                        <button type="button" class="btn btn-light border rounded-circle mr-1" id="hmsRadRefresh" title="Refresh" style="width:36px;height:36px;"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle mr-2" id="hmsRadPrint" title="Print" style="width:36px;height:36px;"><i class="fa fa-print"></i></button>
                        <?php if ($canWrite) { ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold px-3" data-toggle="modal" data-target="#hmsRadAddModal" style="background:#0891b2;border-color:#0891b2;"><i class="fa fa-plus mr-1"></i> New Exam</button>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($flash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
                <?php } ?>
                <?php if ($receiptPrintDoc > 0) { ?>
                <div class="alert alert-success border-0 shadow-sm no-print">
                    Fiscal document ready (PDF).
                    <a class="alert-link font-weight-bold" target="_blank" href="billing-document-pdf.php?id=<?php echo (int) $receiptPrintDoc; ?>">Download PDF</a>
                    <span class="small">(</span><a class="alert-link small" target="_blank" href="billing-document-print.php?id=<?php echo (int) $receiptPrintDoc; ?>">HTML</a><span class="small">)</span>
                </div>
                <?php } ?>

                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Radiology Results</h2>
                            <span class="badge badge-pill badge-primary" style="background:#0891b2;"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="radiology-results.php">
                            <input type="hidden" name="q" value="<?php echo hms_h($qRaw); ?>">
                            <input type="hidden" name="p" value="1">
                            <div class="d-flex flex-wrap align-items-center">
                                <label class="small text-muted mr-2 mb-0" for="hmsRadSort">Sort By:</label>
                                <select name="sort" id="hmsRadSort" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
                                    <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                                </select>
                                <label class="small text-muted mr-2 mb-0" for="hmsRadF">Status:</label>
                                <select name="f" id="hmsRadF" class="form-control form-control-sm" onchange="this.form.submit()">
                                    <option value="all"<?php echo $fStatus === 'all' ? ' selected' : ''; ?>>All</option>
                                    <option value="pending"<?php echo $fStatus === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                    <option value="in_progress"<?php echo $fStatus === 'in_progress' ? ' selected' : ''; ?>>In Progress</option>
                                    <option value="received"<?php echo $fStatus === 'received' ? ' selected' : ''; ?>>Received</option>
                                </select>
                            </div>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Exam ID</th>
                                        <th>Patient Name</th>
                                        <th>Exam Name</th>
                                        <th>Modality</th>
                                        <th>Date</th>
                                        <th>Referred By</th>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rows === []) { ?>
                                    <tr><td colspan="8" class="text-center text-muted py-5">No radiology results match your filters.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($rows as $row) {
                                        $tid = (int) $row['id'];
                                        $stMap2 = ['pending'=>['warning','Pending'],'in_progress'=>['info','In Progress'],'received'=>['success','Completed']];
                                        $st2 = $stMap2[$row['status'] ?? 'pending'] ?? ['secondary', 'Unknown'];
                                        $pname = trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''));
                                        $pinit = strtoupper(substr(trim((string)($row['p_fn'] ?? '')), 0, 1) . substr(trim((string)($row['p_ln'] ?? '')), 0, 1));
                                        $appt = (string) ($row['appointment_date'] ?? '');
                                        $apptShow = $appt !== '' ? date('j M Y', strtotime($appt . ' 12:00:00')) : '—';
                                        $refName = '—';
                                        if (!empty($row['doc_id'])) {
                                            $refName = 'Dr. ' . trim((string) ($row['ref_fn'] ?? '') . ' ' . (string) ($row['ref_ln'] ?? ''));
                                        }
                                        ?>
                                    <tr>
                                        <td class="align-middle font-weight-semibold">RAD-<?php echo str_pad((string)$tid, 4, '0', STR_PAD_LEFT); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="d-flex align-items-center justify-content-center rounded-circle mr-2 text-white font-weight-bold" style="width:32px;height:32px;background:#0891b2;font-size:0.75rem;"><?php echo hms_h($pinit); ?></span>
                                                <span><?php echo hms_h(trim($pname)); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle"><?php echo hms_h((string) ($row['exam_name'] ?? '')); ?></td>
                                        <td class="align-middle small text-muted"><?php echo hms_h((string) ($row['modality'] ?? '')); ?></td>
                                        <td class="align-middle text-muted small"><?php echo hms_h($apptShow); ?></td>
                                        <td class="align-middle small"><?php echo hms_h($refName); ?></td>
                                        <td class="align-middle"><span class="badge badge-<?php echo $st2[0]; ?>"><?php echo $st2[1]; ?></span></td>
                                        <td class="align-middle text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <?php if ($canWrite) { ?>
                                                    <a class="dropdown-item" href="radiology-result-edit.php?id=<?php echo $tid; ?>"><i class="fa fa-pencil mr-2"></i> Edit</a>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this radiology result?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_rad_result" value="1">
                                                        <input type="hidden" name="id" value="<?php echo $tid; ?>">
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
                        <nav aria-label="Pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $mk = static function (int $p) use ($qsBase): string {
                                    return 'radiology-results.php?' . http_build_query(array_merge($qsBase, ['p' => $p]));
                                };
                                ?>
                                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk(max(1, $page - 1))); ?>">Prev</a></li>
                                <li class="page-item active"><span class="page-link"><?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?></span></li>
                                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk(min($totalPages, $page + 1))); ?>">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                    <?php } ?>
                </section>

                <?php if ($canWrite) { ?>
                <div class="modal fade" id="hmsRadAddModal" tabindex="-1" role="dialog" aria-labelledby="hmsRadAddModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <div class="modal-content border-0 shadow">
                            <div class="modal-header border-bottom" style="background:linear-gradient(135deg,#0891b2 0%,#1a6bd8 100%);">
                                <h5 class="modal-title font-weight-bold text-white" id="hmsRadAddModalLabel">New Radiology Exam</h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                            <form method="post" action="radiology-results.php">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="modal_add_rad_result" value="1">
                                <div class="modal-body">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="radPatient">Patient <span class="text-danger">*</span></label>
                                            <select name="patient_id" id="radPatient" class="form-control select" required style="width:100%">
                                                <option value="">Select</option>
                                                <?php foreach ($patientOptions as $po) {
                                                    $pid = (int) $po['id'];
                                                    $nm = trim((string) $po['first_name'] . ' ' . (string) $po['last_name']);
                                                    ?>
                                                <option value="<?php echo $pid; ?>"><?php echo hms_h($nm); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="radRef">Referred by (Doctor)</label>
                                            <select name="referred_by_id" id="radRef" class="form-control select" style="width:100%">
                                                <option value="0">— None —</option>
                                                <?php foreach ($doctorRows as $dr) {
                                                    $did = (int) $dr['id'];
                                                    $dn = trim((string) $dr['first_name'] . ' ' . (string) $dr['last_name']);
                                                    ?>
                                                <option value="<?php echo $did; ?>"><?php echo hms_h($dn); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="radExam">Exam Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="radExam" name="exam_name" required placeholder="e.g. Chest X-Ray">
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label for="radModality">Modality</label>
                                            <select class="form-control" id="radModality" name="modality">
                                                <?php foreach ($modalModalities as $mod) { ?>
                                                <option value="<?php echo hms_h($mod); ?>"><?php echo hms_h($mod); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label for="radBody">Body Part</label>
                                            <input type="text" class="form-control" id="radBody" name="body_part" placeholder="e.g. Chest">
                                        </div>
                                    </div>
                                    <?php if ($radCatalogRows !== []) { ?>
                                    <div class="form-group">
                                        <label for="radFeeCatalog">Match fee from catalog <span class="text-muted small">(optional)</span></label>
                                        <select class="form-control" id="radFeeCatalog" name="rad_fee_catalog_id">
                                            <option value="0">— None —</option>
                                            <?php foreach ($radCatalogRows as $lc) {
                                                $lid = (int) ($lc['id'] ?? 0);
                                                $ln = trim((string) ($lc['name'] ?? ''));
                                                $lp = number_format((float) ($lc['price'] ?? 0), 0, '.', ' ');
                                                ?>
                                            <option value="<?php echo $lid; ?>" data-price="<?php echo hms_h((string) (float) ($lc['price'] ?? 0)); ?>"><?php echo hms_h($ln . ' — ' . $lp . ' FCFA'); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <?php } ?>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="radDate">Appointment date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="radDate" name="appointment_date" required value="<?php echo hms_h(date('Y-m-d')); ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="radStatus">Status</label>
                                            <select class="form-control" id="radStatus" name="status">
                                                <option value="pending">Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="received">Received</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="radNotes">Notes</label>
                                        <textarea class="form-control" id="radNotes" name="notes" rows="2" placeholder="Optional"></textarea>
                                    </div>
                                    <hr class="my-3">
                                    <p class="small text-muted mb-2">If payment was collected, enter the fee (or pick a catalog line) to issue a receipt or company invoice.</p>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="radFee">Fee collected (FCFA)</label>
                                            <input type="number" class="form-control" id="radFee" name="rad_fee_xaf" min="0" step="1" value="0" placeholder="0 = no document">
                                        </div>
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="radPay">Payment method</label>
                                            <select class="form-control" id="radPay" name="rad_payment_method">
                                                <?php foreach (hms_billing_payment_method_options() as $pm) { ?>
                                                <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="radFiscal">Print as</label>
                                            <select class="form-control" id="radFiscal" name="rad_fiscal_document">
                                                <option value="receipt">Receipt</option>
                                                <option value="invoice"<?php echo $radBillingCompanies === [] ? ' disabled' : ''; ?>>Company invoice</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0" id="radCompanyWrap" style="display:none">
                                        <label for="radCompany">Billing company</label>
                                        <select class="form-control" id="radCompany" name="rad_billing_company_id">
                                            <option value="0">— Select —</option>
                                            <?php foreach ($radBillingCompanies as $lbc) { ?>
                                            <option value="<?php echo (int) ($lbc['id'] ?? 0); ?>"><?php echo hms_h((string) ($lbc['name'] ?? '')); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer border-top bg-light">
                                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary" style="background:#0891b2;border-color:#0891b2;">Save Exam</button>
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
            document.getElementById('hmsRadRefresh') && document.getElementById('hmsRadRefresh').addEventListener('click', function () { window.location.reload(); });
            document.getElementById('hmsRadPrint') && document.getElementById('hmsRadPrint').addEventListener('click', function () { window.print(); });
        })();
        </script>
        <?php if ($tablesOk && $canWrite) { ?>
        <script>
        $(function () {
            var $m = $('#hmsRadAddModal');
            if ($m.length && $.fn.select2) {
                $('#radPatient').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select' });
                $('#radRef').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select' });
            }
            var $fc = $('#radFeeCatalog');
            var $fee = $('#radFee');
            if ($fc.length && $fee.length) {
                $fc.on('change', function () {
                    var v = parseFloat(String($fc.find('option:selected').data('price') || '0'));
                    if (!isNaN(v) && v > 0) {
                        $fee.val(String(Math.round(v)));
                    }
                });
            }
            var $fiscal = $('#radFiscal');
            var $cw = $('#radCompanyWrap');
            if ($fiscal.length && $cw.length) {
                function radToggleCo() {
                    $cw.toggle($fiscal.val() === 'invoice');
                }
                $fiscal.on('change', radToggleCo);
                radToggleCo();
            }
        });
        </script>
        <?php } ?>
<?php include 'footer.php'; ?>
