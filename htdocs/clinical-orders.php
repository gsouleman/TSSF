<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'clinical.read');
$fid = hms_current_facility_id();
$filterPid = (int) ($_GET['patient_id'] ?? 0);
$filterPidOk = false;
$filterPatientName = '';
if ($filterPid > 0) {
    if (hms_multi_site_enabled($connection)) {
        $pf = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($pf) {
            mysqli_stmt_bind_param($pf, 'ii', $filterPid, $fid);
        }
    } else {
        $pf = mysqli_prepare($connection, 'SELECT id, first_name, last_name FROM tbl_patient WHERE id = ? LIMIT 1');
        if ($pf) {
            mysqli_stmt_bind_param($pf, 'i', $filterPid);
        }
    }
    if (!empty($pf)) {
        mysqli_stmt_execute($pf);
        $pfRow = hms_stmt_fetch_assoc($pf);
        mysqli_stmt_close($pf);
        if ($pfRow) {
            $filterPidOk = true;
            $filterPatientName = trim((string) ($pfRow['first_name'] ?? '') . ' ' . (string) ($pfRow['last_name'] ?? ''));
        }
    }
}
$orderPatientSql = ($filterPid > 0 && $filterPidOk) ? ' AND o.patient_id = ' . (int) $filterPid : '';
$tableOk = hms_db_table_exists($connection, 'tbl_clinical_order');

$flash = isset($_SESSION['clinical_orders_flash']) ? (string) $_SESSION['clinical_orders_flash'] : '';
unset($_SESSION['clinical_orders_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_can($connection, 'clinical.write')) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['clinical_orders_flash'] = 'Invalid security token.';
        header('Location: clinical-orders.php');
        exit;
    }
    if (isset($_POST['place_order'])) {
        $pid = (int) ($_POST['patient_id'] ?? 0);
        $otype = (string) ($_POST['order_type'] ?? 'lab');
        $code = (string) ($_POST['order_code'] ?? '');
        $desc = (string) ($_POST['order_desc'] ?? '');
        $encId = (int) ($_POST['encounter_id'] ?? 0);
        if (!in_array($otype, ['lab', 'imaging', 'medication', 'other'], true)) {
            $otype = 'other';
        }
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
        $pick = trim((string) ($_POST['lab_catalog_pick'] ?? ''));
        $labCatalogId = 0;
        if ($pick !== '' && $pick !== 'custom' && ctype_digit($pick)) {
            $labCatalogId = (int) $pick;
        }
        $hasLabCatalogCol = hms_db_column_exists($connection, 'tbl_clinical_order', 'lab_catalog_id');
        $catalogOk = hms_workflow_table_ok($connection, 'tbl_lab_catalog');
        if ($labCatalogId > 0 && $catalogOk) {
            $cst = mysqli_prepare($connection, 'SELECT code, name, category FROM tbl_lab_catalog WHERE id = ? AND active = 1 LIMIT 1');
            if ($cst) {
                mysqli_stmt_bind_param($cst, 'i', $labCatalogId);
                mysqli_stmt_execute($cst);
                $crow = hms_stmt_fetch_assoc($cst);
                mysqli_stmt_close($cst);
                if ($crow) {
                    $otype = hms_lab_order_type_for_category((string) $crow['category']);
                    $code = (string) $crow['code'];
                    $desc = (string) $crow['name'];
                } else {
                    $labCatalogId = 0;
                }
            }
        }
        if (!$okPat) {
            $_SESSION['clinical_orders_flash'] = 'Choose a patient for this order.';
        } elseif ($desc === '') {
            $_SESSION['clinical_orders_flash'] = 'Pick from the catalogue or enter a description (and code if custom).';
        } else {
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            $os = 'ordered';
            $st = null;
            if ($hasLabCatalogCol && $labCatalogId > 0) {
                if ($encId > 0) {
                    $st = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_clinical_order (facility_id, patient_id, encounter_id, order_type, code, description, status, ordered_by, ordered_at, lab_catalog_id) VALUES (?,?,?,?,?,?,?,?,NOW(),?)'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, 'iii' . 'ssss' . 'ii', $fid, $pid, $encId, $otype, $code, $desc, $os, $uid, $labCatalogId);
                    }
                } else {
                    $st = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_clinical_order (facility_id, patient_id, order_type, code, description, status, ordered_by, ordered_at, lab_catalog_id) VALUES (?,?,?,?,?,?,?,NOW(),?)'
                    );
                    if ($st) {
                        mysqli_stmt_bind_param($st, 'ii' . 'ssss' . 'ii', $fid, $pid, $otype, $code, $desc, $os, $uid, $labCatalogId);
                    }
                }
            } else {
                $encSql = $encId > 0
                    ? 'INSERT INTO tbl_clinical_order (facility_id, patient_id, encounter_id, order_type, code, description, status, ordered_by, ordered_at) VALUES (?,?,?,?,?,?,?,?,NOW())'
                    : 'INSERT INTO tbl_clinical_order (facility_id, patient_id, order_type, code, description, status, ordered_by, ordered_at) VALUES (?,?,?,?,?,?,?,NOW())';
                $st = mysqli_prepare($connection, $encSql);
                if ($st) {
                    if ($encId > 0) {
                        mysqli_stmt_bind_param($st, 'iiissssi', $fid, $pid, $encId, $otype, $code, $desc, $os, $uid);
                    } else {
                        mysqli_stmt_bind_param($st, 'iissssi', $fid, $pid, $otype, $code, $desc, $os, $uid);
                    }
                }
            }
            if ($st && mysqli_stmt_execute($st)) {
                $newId = (int) mysqli_insert_id($connection);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'order.create', 'clinical_order', $newId, ['patient_id' => $pid]);
                $_SESSION['clinical_orders_flash'] = 'Order placed.';
            } elseif ($st) {
                mysqli_stmt_close($st);
            }
        }
        $coRet = 'clinical-orders.php';
        if ($pid > 0) {
            $coRet .= '?patient_id=' . $pid;
        }
        header('Location: ' . $coRet);
        exit;
    }
    if (isset($_POST['update_status'])) {
        $oid = (int) ($_POST['order_id'] ?? 0);
        $nst = (string) ($_POST['new_status'] ?? '');
        if (!in_array($nst, ['ordered', 'completed', 'cancelled'], true)) {
            $_SESSION['clinical_orders_flash'] = 'Invalid status.';
        } elseif ($oid > 0) {
            $st = mysqli_prepare($connection, 'UPDATE tbl_clinical_order SET status = ? WHERE id = ? AND facility_id = ? LIMIT 1');
            if ($st) {
                mysqli_stmt_bind_param($st, 'sii', $nst, $oid, $fid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'order.status', 'clinical_order', $oid, ['status' => $nst]);
                $_SESSION['clinical_orders_flash'] = 'Order updated.';
            }
        }
        $upRet = 'clinical-orders.php';
        $rp = (int) ($_POST['return_patient_id'] ?? 0);
        if ($rp > 0) {
            $upRet .= '?patient_id=' . $rp;
        }
        header('Location: ' . $upRet);
        exit;
    }
}

$coLabCatalogJson = '[]';
if (hms_workflow_table_ok($connection, 'tbl_lab_catalog')) {
    $coRows = [];
    foreach (hms_lab_catalog_rows($connection) as $lc) {
        $coRows[] = [
            'id' => (int) $lc['id'],
            'code' => (string) $lc['code'],
            'name' => (string) $lc['name'],
            'kind' => hms_lab_order_type_for_category((string) $lc['category']),
        ];
    }
    $coLabCatalogJson = json_encode($coRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?: '[]';
}

include 'header.php';
$suf = hms_multi_site_enabled($connection) ? ' WHERE facility_id = ' . (int) $fid : '';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            $coSecondary = [
                ['label' => 'Patients', 'url' => 'patients.php', 'icon' => 'fa-users'],
            ];
            if ($filterPidOk) {
                array_unshift($coSecondary, ['label' => 'Patient chart', 'url' => 'patient-chart.php?id=' . $filterPid, 'icon' => 'fa-file-text-o']);
                if (hms_workflow_table_ok($connection, 'tbl_prescription')) {
                    array_unshift($coSecondary, ['label' => 'Prescriptions', 'url' => 'prescriptions.php', 'icon' => 'fa-file-text-o']);
                }
            }
            $coSubtitle = 'Facility-scoped CPOE-style list. Place orders and update status.';
            if ($filterPid > 0 && !$filterPidOk) {
                $coSubtitle .= ' Unknown or out-of-scope patient filter was ignored.';
            } elseif ($filterPidOk) {
                $coSubtitle .= ' Filter: ' . $filterPatientName . ' (#' . $filterPid . ').';
            }
            hms_ui_page_header('Clinical orders', [
                'subtitle' => $coSubtitle,
                'breadcrumbs' => [['Clinical', null], ['Orders', '']],
                'secondary' => $coSecondary,
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration to create <code>tbl_clinical_order</code>.</div>
            <?php } else { ?>
            <?php if (hms_can($connection, 'clinical.write')) { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4">
                <div class="card-header bg-white font-weight-bold">Place order</div>
                <div class="card-body">
                    <?php
                    $coCatalogUi = hms_workflow_table_ok($connection, 'tbl_lab_catalog');
                    if (!$coCatalogUi) {
                        echo '<p class="small text-muted mb-3">Run <code>003_clinical_workflow.sql</code> to enable the searchable <strong>Catalogue</strong> for Lab and Imaging.</p>';
                    }
                    ?>
                    <form method="post" class="row" id="co-place-order-form" name="co_place_order" autocomplete="off">
                        <?php echo hms_csrf_field(); ?>
                        <div class="col-lg-3 col-md-6 mb-2">
                            <label class="small text-muted">Patient</label>
                            <select name="patient_id" class="form-control" required>
                                <option value="">— Select —</option>
                                <?php
                                $pq = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_patient' . $suf . ' ORDER BY last_name, first_name LIMIT 500');
                                while ($pq && $pr = mysqli_fetch_assoc($pq)) {
                                    $sel = ($filterPidOk && (int) $pr['id'] === $filterPid) ? ' selected' : '';
                                    echo '<option value="' . (int) $pr['id'] . '"' . $sel . '>' . hms_h($pr['first_name'] . ' ' . $pr['last_name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label class="small text-muted">Type</label>
                            <select name="order_type" id="co-order-type" class="form-control">
                                <option value="lab">Lab</option>
                                <option value="imaging">Imaging</option>
                                <option value="medication">Medication</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-lg-4 col-md-12 mb-2<?php echo $coCatalogUi ? '' : ' d-none'; ?>" id="co-catalog-wrap" data-catalog-ui="<?php echo $coCatalogUi ? '1' : '0'; ?>">
                            <label class="small text-muted">Catalogue</label>
                            <select name="lab_catalog_pick" id="co-lab-catalog" class="form-control">
                                <option value="">— Search or select —</option>
                                <option value="custom">Others...</option>
                            </select>
                            <small class="text-muted d-block mt-1">A catalogue row fills <strong>Code</strong> and <strong>Description</strong>. Choose <em>Others...</em> to type your own.</small>
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label class="small text-muted">Code</label>
                            <input class="form-control" name="order_code" id="co-order-code" placeholder="LOINC / local code">
                        </div>
                        <div class="col-lg-3 col-md-6 mb-2">
                            <label class="small text-muted">Description</label>
                            <input class="form-control" name="order_desc" id="co-order-desc" required placeholder="Order details or test name">
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label class="small text-muted">Encounter ID (opt.)</label>
                            <input class="form-control" name="encounter_id" type="number" min="0" placeholder="—">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary btn-sm" type="submit" name="place_order" value="1"><i class="fa fa-plus mr-1"></i> Place order</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table datatable mb-0">
                            <thead><tr><th>ID</th><th>Patient</th><th>Type</th><th>Code</th><th>Description</th><th>Status</th><th>Ordered</th><?php if (hms_can($connection, 'clinical.write')) { ?><th class="text-right">Actions</th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query(
                                $connection,
                                'SELECT o.id, o.patient_id, o.order_type, o.code, o.description, o.status, o.ordered_at, p.first_name, p.last_name FROM tbl_clinical_order o
                                 JOIN tbl_patient p ON p.id = o.patient_id WHERE o.facility_id = ' . (int) $fid . $orderPatientSql . ' ORDER BY o.id DESC LIMIT 150'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $opid = (int) $r['patient_id'];
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']);
                                echo ' <a class="btn btn-xs btn-outline-secondary py-0 px-1 small" href="patient-chart.php?id=' . $opid . '" title="Chart">Chart</a>';
                                echo ' <a class="btn btn-xs btn-outline-secondary py-0 px-1 small" href="clinical-orders.php?patient_id=' . $opid . '" title="This patient orders">Orders</a>';
                                echo '</td>';
                                echo '<td>' . hms_h((string) $r['order_type']) . '</td>';
                                echo '<td>' . hms_h((string) $r['code']) . '</td>';
                                echo '<td>' . hms_h((string) $r['description']) . '</td>';
                                echo '<td><span class="badge badge-secondary">' . hms_h((string) $r['status']) . '</span></td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['ordered_at']) . '</td>';
                                if (hms_can($connection, 'clinical.write')) {
                                    echo '<td class="text-right">';
                                    echo '<form method="post" class="d-inline">';
                                    echo hms_csrf_field();
                                    if ($filterPidOk) {
                                        echo '<input type="hidden" name="return_patient_id" value="' . (int) $filterPid . '">';
                                    }
                                    echo '<input type="hidden" name="order_id" value="' . (int) $r['id'] . '">';
                                    echo '<select name="new_status" class="form-control form-control-sm d-inline-block" style="width:auto;max-width:120px;">';
                                    foreach (['ordered', 'completed', 'cancelled'] as $s) {
                                        $sel = ((string) $r['status'] === $s) ? ' selected' : '';
                                        echo '<option value="' . hms_h($s) . '"' . $sel . '>' . hms_h($s) . '</option>';
                                    }
                                    echo '</select> ';
                                    echo '<button class="btn btn-sm btn-outline-primary" type="submit" name="update_status" value="1">Save</button>';
                                    echo '</form></td>';
                                }
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
<?php if ($tableOk && hms_can($connection, 'clinical.write')) { ?>
<script>
(function ($) {
    var catalog = <?php echo $coLabCatalogJson; ?>;
    var $type = $('#co-order-type');
    var $pick = $('#co-lab-catalog');
    var $wrap = $('#co-catalog-wrap');
    var $code = $('#co-order-code');
    var $desc = $('#co-order-desc');

    function coRebuildOptions(orderType) {
        var prev = $pick.val();
        $pick.empty();
        $pick.append(new Option('— Search or select —', '', false, false));
        $pick.append(new Option('Others...', 'custom', false, false));
        if (orderType === 'lab' || orderType === 'imaging') {
            catalog.forEach(function (row) {
                if (row.kind === orderType) {
                    $pick.append(new Option(row.code + ' — ' + row.name, String(row.id), false, false));
                }
            });
        }
        var prevStr = String(prev || '').replace(/"/g, '');
        if (prevStr !== '' && $pick.find('option[value="' + prevStr + '"]').length) {
            $pick.val(prevStr);
        } else {
            $pick.val('');
        }
    }

    function coApplyPick() {
        var t = $type.val();
        var v = $pick.val();
        if (t !== 'lab' && t !== 'imaging') {
            $code.prop('readonly', false);
            $desc.prop('readonly', false);
            return;
        }
        if (!v || v === 'custom') {
            $code.prop('readonly', false);
            $desc.prop('readonly', false);
            return;
        }
        var row = null;
        catalog.forEach(function (r) {
            if (String(r.id) === String(v)) {
                row = r;
            }
        });
        if (row) {
            $code.val(row.code);
            $desc.val(row.name);
            $code.prop('readonly', true);
            $desc.prop('readonly', true);
        }
    }

    function coInitSelect2() {
        if (!$pick.length || !$wrap.is(':visible')) {
            return;
        }
        if ($pick.data('select2')) {
            $pick.select2('destroy');
        }
        $pick.select2({ width: '100%', placeholder: 'Search catalogue…' });
    }

    function coOnTypeChange() {
        var t = $type.val();
        var catOn = $wrap.attr('data-catalog-ui') === '1';
        if ((t === 'lab' || t === 'imaging') && catOn) {
            $wrap.removeClass('d-none').show();
            coRebuildOptions(t);
            coInitSelect2();
            coApplyPick();
        } else {
            if ($pick.data('select2')) {
                $pick.select2('destroy');
            }
            $wrap.hide();
            $pick.val('');
            $code.prop('readonly', false);
            $desc.prop('readonly', false);
        }
    }

    $type.on('change', coOnTypeChange);
    $pick.on('change', coApplyPick);
    $('#co-place-order-form').on('submit', function () {
        coApplyPick();
    });

    $(function () {
        if ($type.val() === 'lab' || $type.val() === 'imaging') {
            coOnTypeChange();
        }
    });
})(jQuery);
</script>
<?php } ?>
<?php include 'footer.php'; ?>
