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
$hasUserFacilityTbl = hms_db_table_exists($connection, 'tbl_user_facility');
$canWrite = hms_can($connection, 'lab.write');
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_lab_result_table_ok($connection);

$flash = isset($_SESSION['lab_registry_flash']) ? (string) $_SESSION['lab_registry_flash'] : '';
unset($_SESSION['lab_registry_flash']);

if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_lab_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        $st = mysqli_prepare($connection, 'DELETE FROM tbl_lab_result WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'ii', $delId, $fid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        hms_audit_log($connection, 'lab_registry.delete', 'lab_result', $delId);
        $_SESSION['lab_registry_flash'] = 'Lab result removed.';
    }
    header('Location: lab-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['modal_add_lab_result']) && $canWrite) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $pid = (int) ($_POST['patient_id'] ?? 0);
    $refId = (int) ($_POST['referred_by_id'] ?? 0);
    $testName = trim((string) ($_POST['test_name'] ?? ''));
    $apptDate = trim((string) ($_POST['appointment_date'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'in_progress', 'received'], true)) {
        $status = 'pending';
    }
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $labCatalogPick = (int) ($_POST['lab_fee_catalog_id'] ?? 0);
    $catRow = $labCatalogPick > 0 ? hms_billing_catalog_row_by_id($connection, $fid, $labCatalogPick) : null;
    $labFee = max(0, (int) ($_POST['lab_fee_xaf'] ?? 0));
    if ($labFee <= 0 && $catRow !== null) {
        $labFee = $catRow['amount'];
    }
    $labPay = hms_billing_normalize_payment_method($_POST['lab_payment_method'] ?? 'Cash');
    $labWantInvoice = isset($_POST['lab_fiscal_document']) && (string) $_POST['lab_fiscal_document'] === 'invoice';
    $labCompanyId = (int) ($_POST['lab_billing_company_id'] ?? 0);

    if ($pid < 1 || $testName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apptDate)) {
        $_SESSION['lab_registry_flash'] = 'Patient, test name, and a valid appointment date are required.';
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
            $ins = false;
            $newLabId = 0;
            if ($refId > 0) {
                $st = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_lab_result (facility_id, patient_id, referred_by_id, test_name, appointment_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?)'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'iiissssi', $fid, $pid, $refId, $testName, $apptDate, $status, $notes, $uid);
                    $ins = mysqli_stmt_execute($st);
                    if ($ins) {
                        $newLabId = (int) mysqli_insert_id($connection);
                    }
                    mysqli_stmt_close($st);
                }
            } else {
                $st = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_lab_result (facility_id, patient_id, test_name, appointment_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?)'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'iissssi', $fid, $pid, $testName, $apptDate, $status, $notes, $uid);
                    $ins = mysqli_stmt_execute($st);
                    if ($ins) {
                        $newLabId = (int) mysqli_insert_id($connection);
                    }
                    mysqli_stmt_close($st);
                }
            }
            if ($ins && $newLabId > 0) {
                hms_audit_log($connection, 'lab_registry.create', 'lab_result', $newLabId);
                $_SESSION['lab_registry_flash'] = 'Lab result added.';
                if ($labFee > 0 && hms_billing_document_tables_ok($connection) && hms_can($connection, 'billing.write')) {
                    $lineDesc = ($catRow !== null && ($catRow['label'] ?? '') !== '')
                        ? ('Laboratory: ' . (string) $catRow['label'])
                        : ('Laboratory: ' . $testName);
                    $docType = 'receipt';
                    $companyBind = 0;
                    if ($labWantInvoice && $labCompanyId > 0 && hms_db_table_exists($connection, 'tbl_billing_company')) {
                        $lcc = mysqli_prepare($connection, 'SELECT id FROM tbl_billing_company WHERE id = ? AND facility_id = ? AND status = 1 LIMIT 1');
                        if ($lcc) {
                            mysqli_stmt_bind_param($lcc, 'ii', $labCompanyId, $fid);
                            mysqli_stmt_execute($lcc);
                            if (hms_stmt_fetch_assoc($lcc)) {
                                $docType = 'invoice';
                                $companyBind = $labCompanyId;
                            }
                            mysqli_stmt_close($lcc);
                        }
                    }
                    $docOpts = [
                        'facility_id' => $fid,
                        'patient_id' => $pid,
                        'doc_type' => $docType,
                        'payment_method' => $labPay,
                        'source_module' => 'lab_fee',
                        'source_pk' => $newLabId,
                        'lab_result_id' => $newLabId,
                        'created_by' => $uid,
                    ];
                    if ($companyBind > 0) {
                        $docOpts['company_id'] = $companyBind;
                    }
                    $hospLab = function_exists('hms_hospitalization_open_id_for_patient')
                        ? hms_hospitalization_open_id_for_patient($connection, $fid, $pid)
                        : 0;
                    if ($hospLab > 0) {
                        $docOpts['hospitalization_id'] = $hospLab;
                    }
                    $docId = hms_billing_create_document(
                        $connection,
                        $docOpts,
                        [
                            [
                                'description' => $lineDesc,
                                'quantity' => 1,
                                'unit_price' => (float) $labFee,
                            ],
                        ]
                    );
                    if (is_int($docId) && $docId > 0) {
                        hms_billing_set_print_prompt($docId);
                        $_SESSION['lab_registry_flash'] .= $docType === 'invoice' ? ' Invoice issued.' : ' Receipt issued.';
                    }
                } elseif ($labFee > 0 && !hms_can($connection, 'billing.write')) {
                    $_SESSION['lab_registry_flash'] .= ' No receipt issued: billing permission is required to create fiscal documents.';
                }
            } else {
                $_SESSION['lab_registry_flash'] = 'Could not save lab result.';
            }
        }
    }
    header('Location: lab-results.php?' . http_build_query(['nc' => (string) time()]));
    exit;
}

$qRaw = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}
$fStatus = (string) ($_GET['f'] ?? 'all');
if (!in_array($fStatus, ['all', 'pending', 'in_progress', 'received'], true)) {
    $fStatus = 'all';
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;

$rows = [];
$total = 0;
$totalPages = 1;
$patientOptions = [];
$doctorRows = [];
$labCatalogRows = [];
$labBillingCompanies = [];

if ($tablesOk) {
    $docJoin = 'LEFT JOIN tbl_employee doc ON doc.id = lr.referred_by_id';
    $hasEmpPhoto = function_exists('hms_doctor_photo_column_exists') && hms_doctor_photo_column_exists($connection);
    $docPhotoSql = $hasEmpPhoto ? 'doc.photo_path AS doc_photo' : 'CAST(NULL AS CHAR) AS doc_photo';

    $w = ['lr.facility_id = ' . (int) $fid];
    if ($fStatus !== 'all') {
        $escS = mysqli_real_escape_string($connection, $fStatus);
        $w[] = "lr.status = '" . $escS . "'";
    }
    if ($qRaw !== '') {
        $like = mysqli_real_escape_string($connection, $qRaw);
        $w[] = "(lr.test_name LIKE '%{$like}%' OR lr.notes LIKE '%{$like}%' OR p.first_name LIKE '%{$like}%' OR p.last_name LIKE '%{$like}%' OR CONCAT(p.first_name,' ',p.last_name) LIKE '%{$like}%' OR doc.first_name LIKE '%{$like}%' OR doc.last_name LIKE '%{$like}%')";
    }
    $whereSql = implode(' AND ', $w);

    $baseFrom = 'FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id ' . $docJoin . ' WHERE ' . $whereSql;
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
    $orderSql = $sort === 'oldest' ? 'lr.appointment_date ASC, lr.id ASC' : 'lr.appointment_date DESC, lr.id DESC';
    $lq = mysqli_query(
        $connection,
        'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln, p.gender AS p_gender, doc.id AS doc_id, doc.first_name AS ref_fn, doc.last_name AS ref_ln, ' . $docPhotoSql . ' '
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
        $labCatalogRows = hms_billing_catalog_rows_by_category($connection, $fid, 'laboratory');
        if (hms_db_table_exists($connection, 'tbl_billing_company')) {
            $lbq = mysqli_query(
                $connection,
                'SELECT id, name FROM tbl_billing_company WHERE facility_id = ' . (int) $fid . ' AND status = 1 ORDER BY name LIMIT 300'
            );
            while ($lbq && $lbr = mysqli_fetch_assoc($lbq)) {
                $labBillingCompanies[] = $lbr;
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

?>
        <div class="page-wrapper hms-lab-print-root">
            <div class="content hms-module hms-lab-page hms-appts-dreams">
                <?php if (!$tablesOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Run migration <code>hms/database/migrations/010_laboratory_registry.sql</code> to create <code>tbl_lab_result</code> (see <a href="platform-overview.php">Help &amp; setup</a>).
                </div>
                <?php } else { ?>
                <form method="get" action="lab-results.php" class="hms-appts-keyword-bar card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap align-items-stretch">
                            <div class="flex-grow-1 mr-2 mb-0" style="min-width: 200px;">
                                <label class="sr-only" for="hmsLabKeyword">Search keyword</label>
                                <input type="search" name="q" id="hmsLabKeyword" class="form-control hms-appts-keyword-input" placeholder="Search Keyword" value="<?php echo hms_h($qRaw); ?>" autocomplete="off">
                            </div>
                            <input type="hidden" name="sort" value="<?php echo hms_h($sort); ?>">
                            <input type="hidden" name="f" value="<?php echo hms_h($fStatus); ?>">
                            <button type="submit" class="btn btn-primary px-4 hms-appts-keyword-btn" title="Search"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>

                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Lab Results</h1>
                        <nav aria-label="breadcrumb" class="hms-appts-dreams-bc mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Lab Results</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center hms-appts-dreams-toolbar no-print">
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-1" id="hmsLabRefresh" title="Refresh"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle hms-appts-icon-btn mr-2" id="hmsLabPrint" title="Print"><i class="fa fa-print"></i></button>
                        <?php if ($canWrite) { ?>
                        <button type="button" class="btn btn-primary btn-sm font-weight-bold px-3" data-toggle="modal" data-target="#hmsLabAddModal"><i class="fa fa-plus mr-1"></i> New lab result</button>
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
                <iframe title="Receipt PDF" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0)" src="billing-document-pdf.php?id=<?php echo (int) $receiptPrintDoc; ?>"></iframe>
                <?php } ?>

                <section class="hms-appts-total-section card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Lab Results</h2>
                            <span class="hms-appts-count-badge"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="lab-results.php">
                            <input type="hidden" name="q" value="<?php echo hms_h($qRaw); ?>">
                            <input type="hidden" name="p" value="1">
                            <div class="d-flex flex-wrap align-items-center">
                                <label class="small text-muted mr-2 mb-0" for="hmsLabSort">Sort By:</label>
                                <select name="sort" id="hmsLabSort" class="form-control form-control-sm mr-2 hms-appts-sort-select" onchange="this.form.submit()">
                                    <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                                </select>
                                <label class="small text-muted mr-2 mb-0" for="hmsLabF">Status:</label>
                                <select name="f" id="hmsLabF" class="form-control form-control-sm hms-appts-sort-select" onchange="this.form.submit()">
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
                            <table class="table table-hover mb-0 hms-appts-dreams-table">
                                <thead class="hms-appts-dreams-thead">
                                    <tr>
                                        <th>Test ID</th>
                                        <th>Patient Name</th>
                                        <th>Gender</th>
                                        <th>Appointment Date</th>
                                        <th>Referred By</th>
                                        <th>Test Name</th>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rows === []) { ?>
                                    <tr><td colspan="8" class="text-center text-muted py-5">No lab results match your filters.</td></tr>
                                    <?php } ?>
                                    <?php foreach ($rows as $row) {
                                        $tid = (int) $row['id'];
                                        $st = hms_lab_result_status_ui((string) ($row['status'] ?? 'pending'));
                                        $pname = trim((string) ($row['p_fn'] ?? '') . ' ' . (string) ($row['p_ln'] ?? ''));
                                        $pinit = hms_visit_patient_initials((string) ($row['p_fn'] ?? ''), (string) ($row['p_ln'] ?? ''));
                                        $gen = trim((string) ($row['p_gender'] ?? '')) ?: '—';
                                        $appt = (string) ($row['appointment_date'] ?? '');
                                        $apptShow = $appt !== '' ? date('j M Y', strtotime($appt . ' 12:00:00')) : '—';
                                        $refName = '—';
                                        $docAvatar = 'assets/img/doctors/avatar-1.svg';
                                        if (!empty($row['doc_id'])) {
                                            $refName = 'Dr. ' . trim((string) ($row['ref_fn'] ?? '') . ' ' . (string) ($row['ref_ln'] ?? ''));
                                            $docAvatar = hms_doctor_avatar_src([
                                                'id' => (int) $row['doc_id'],
                                                'photo_path' => (string) ($row['doc_photo'] ?? ''),
                                            ]);
                                        }
                                        ?>
                                    <tr class="hms-appts-dreams-row">
                                        <td class="align-middle font-weight-semibold"><?php echo hms_h(hms_lab_test_display_id($tid)); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--sm hms-visit-avatar--patient mr-2"><?php echo hms_h($pinit); ?></span>
                                                <span><?php echo hms_h(trim($pname)); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle"><?php echo hms_h($gen); ?></td>
                                        <td class="align-middle text-muted small"><?php echo hms_h($apptShow); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <?php if (!empty($row['doc_id'])) { ?>
                                                <img src="<?php echo hms_h($docAvatar); ?>" alt="" class="rounded-circle mr-2" width="32" height="32" style="object-fit:cover">
                                                <?php } ?>
                                                <span class="small"><?php echo hms_h($refName); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle"><?php echo hms_h((string) ($row['test_name'] ?? '')); ?></td>
                                        <td class="align-middle"><span class="hms-lab-pill <?php echo hms_h($st['pill']); ?>"><?php echo hms_h($st['label']); ?></span></td>
                                        <td class="align-middle text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <?php if ($canWrite) { ?>
                                                    <a class="dropdown-item" href="lab-result-edit.php?id=<?php echo $tid; ?>"><i class="fa fa-pencil mr-2"></i> Edit</a>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this lab result?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_lab_result" value="1">
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
                                    return 'lab-results.php?' . http_build_query(array_merge($qsBase, ['p' => $p]));
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
                <div class="modal fade" id="hmsLabAddModal" tabindex="-1" role="dialog" aria-labelledby="hmsLabAddModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                        <div class="modal-content hms-appt-modal-content border-0 shadow">
                            <div class="modal-header border-bottom">
                                <h5 class="modal-title font-weight-bold" id="hmsLabAddModalLabel">New lab result</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            </div>
                            <form method="post" action="lab-results.php">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="modal_add_lab_result" value="1">
                                <div class="modal-body">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="labPatient">Patient <span class="text-danger">*</span></label>
                                            <select name="patient_id" id="labPatient" class="form-control select" required style="width:100%">
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
                                            <label for="labRef">Referred by</label>
                                            <select name="referred_by_id" id="labRef" class="form-control select" style="width:100%">
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
                                    <div class="form-group">
                                        <label for="labTest">Test name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="labTest" name="test_name" required placeholder="e.g. Liver Function Test">
                                    </div>
                                    <?php if ($labCatalogRows !== []) { ?>
                                    <div class="form-group">
                                        <label for="labFeeCatalog">Match fee from catalog <span class="text-muted small">(optional)</span></label>
                                        <select class="form-control" id="labFeeCatalog" name="lab_fee_catalog_id">
                                            <option value="0">— None —</option>
                                            <?php foreach ($labCatalogRows as $lc) {
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
                                            <label for="labDate">Appointment date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control" id="labDate" name="appointment_date" required value="<?php echo hms_h(date('Y-m-d')); ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="labStatus">Status</label>
                                            <select class="form-control" id="labStatus" name="status">
                                                <option value="pending">Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="received">Received</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="labNotes">Notes</label>
                                        <textarea class="form-control" id="labNotes" name="notes" rows="2" placeholder="Optional"></textarea>
                                    </div>
                                    <hr class="my-3">
                                    <p class="small text-muted mb-2">If payment was collected, enter the fee (or pick a catalog line) to issue a receipt or company invoice.</p>
                                    <div class="form-row">
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="labFee">Fee collected (FCFA)</label>
                                            <input type="number" class="form-control" id="labFee" name="lab_fee_xaf" min="0" step="1" value="0" placeholder="0 = no document">
                                        </div>
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="labPay">Payment method</label>
                                            <select class="form-control" id="labPay" name="lab_payment_method">
                                                <?php foreach (hms_billing_payment_method_options() as $pm) { ?>
                                                <option value="<?php echo hms_h($pm); ?>"><?php echo hms_h($pm); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-4 mb-0">
                                            <label for="labFiscal">Print as</label>
                                            <select class="form-control" id="labFiscal" name="lab_fiscal_document">
                                                <option value="receipt">Receipt</option>
                                                <option value="invoice"<?php echo $labBillingCompanies === [] ? ' disabled' : ''; ?>>Company invoice</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group mb-0" id="labCompanyWrap" style="display:none">
                                        <label for="labCompany">Billing company</label>
                                        <select class="form-control" id="labCompany" name="lab_billing_company_id">
                                            <option value="0">— Select —</option>
                                            <?php foreach ($labBillingCompanies as $lbc) { ?>
                                            <option value="<?php echo (int) ($lbc['id'] ?? 0); ?>"><?php echo hms_h((string) ($lbc['name'] ?? '')); ?></option>
                                            <?php } ?>
                                        </select>
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
            document.getElementById('hmsLabRefresh') && document.getElementById('hmsLabRefresh').addEventListener('click', function () { window.location.reload(); });
            document.getElementById('hmsLabPrint') && document.getElementById('hmsLabPrint').addEventListener('click', function () { window.print(); });
        })();
        </script>
        <?php if ($tablesOk && $canWrite) { ?>
        <script>
        $(function () {
            var $m = $('#hmsLabAddModal');
            if ($m.length && $.fn.select2) {
                $('#labPatient').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select' });
                $('#labRef').select2({ dropdownParent: $m, width: '100%', placeholder: 'Select' });
            }
            var $fc = $('#labFeeCatalog');
            var $fee = $('#labFee');
            if ($fc.length && $fee.length) {
                $fc.on('change', function () {
                    var v = parseFloat(String($fc.find('option:selected').data('price') || '0'));
                    if (!isNaN(v) && v > 0) {
                        $fee.val(String(Math.round(v)));
                    }
                });
            }
            var $fiscal = $('#labFiscal');
            var $cw = $('#labCompanyWrap');
            if ($fiscal.length && $cw.length) {
                function labToggleCo() {
                    $cw.toggle($fiscal.val() === 'invoice');
                }
                $fiscal.on('change', labToggleCo);
                labToggleCo();
            }
        });
        </script>
        <?php } ?>
<?php include 'footer.php'; ?>
