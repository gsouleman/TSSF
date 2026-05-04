<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'lab.read');
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tablesOk = hms_workflow_table_ok($connection, 'tbl_clinical_order') && hms_workflow_table_ok($connection, 'tbl_order_result');

$flash = isset($_SESSION['lab_flash']) ? (string) $_SESSION['lab_flash'] : '';
unset($_SESSION['lab_flash']);

if ($tablesOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_can($connection, 'lab.write') && isset($_POST['save_result'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['lab_flash'] = 'Invalid security token.';
    } else {
        $oid = (int) ($_POST['order_id'] ?? 0);
        $txt = trim((string) ($_POST['result_text'] ?? ''));
        if ($oid < 1 || $txt === '') {
            $_SESSION['lab_flash'] = 'Order and result text are required.';
        } else {
            $chk = mysqli_prepare($connection, 'SELECT id FROM tbl_clinical_order WHERE id = ? AND facility_id = ? AND order_type IN (\'lab\',\'imaging\') LIMIT 1');
            mysqli_stmt_bind_param($chk, 'ii', $oid, $fid);
            mysqli_stmt_execute($chk);
            $ok = (bool) hms_stmt_fetch_assoc($chk);
            mysqli_stmt_close($chk);
            if (!$ok) {
                $_SESSION['lab_flash'] = 'Order not found for this site.';
            } else {
                mysqli_begin_transaction($connection);
                try {
                    $ins = mysqli_prepare($connection, 'INSERT INTO tbl_order_result (order_id, result_text, resulted_at) VALUES (?,?,NOW())');
                    mysqli_stmt_bind_param($ins, 'is', $oid, $txt);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                    $st = mysqli_prepare($connection, "UPDATE tbl_clinical_order SET status = 'completed' WHERE id = ? AND facility_id = ? LIMIT 1");
                    mysqli_stmt_bind_param($st, 'ii', $oid, $fid);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                    mysqli_commit($connection);
                    hms_audit_log($connection, 'lab.result', 'clinical_order', $oid);
                    $_SESSION['lab_flash'] = 'Result saved. Order marked completed.';
                } catch (Throwable $e) {
                    mysqli_rollback($connection);
                    $_SESSION['lab_flash'] = 'Could not save result.';
                }
            }
        }
    }
    header('Location: lab-worklist.php');
    exit;
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Laboratory worklist', [
                'subtitle' => 'Prescribed lab & imaging requests → enter structured results (workflow).',
                'breadcrumbs' => [['Clinical', null], ['Lab worklist', '']],
                'secondary' => array_values(array_filter([
                    hms_can($connection, 'clinical.read') ? ['label' => 'Clinical orders', 'url' => 'clinical-orders.php', 'icon' => 'fa-list'] : null,
                    ['label' => 'Prescriptions', 'url' => 'prescriptions.php', 'icon' => 'fa-file-text-o'],
                ])),
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tablesOk) { ?>
            <div class="alert alert-warning">Run <code>hms/database/migrations/003_clinical_workflow.sql</code> (and ensure clinical tables from 001 exist).</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card mb-4">
                <div class="card-header bg-white font-weight-bold">Catalogue (autocomplete reference)</div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:220px;overflow:auto;">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Prélèvement</th></tr></thead>
                            <tbody>
                            <?php foreach (hms_lab_catalog_rows($connection) as $lc) {
                                echo '<tr><td class="text-monospace">' . hms_h((string) $lc['code']) . '</td><td>' . hms_h((string) $lc['name']) . '</td><td>' . hms_h((string) $lc['category']) . '</td><td class="small">' . hms_h((string) ($lc['specimen_hint'] ?? '')) . '</td></tr>';
                            } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-header bg-white font-weight-bold">Pending &amp; active requests</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>ID</th><th>Patient</th><?php if (hms_can($connection, 'clinical.read')) { ?><th class="text-nowrap">Chart / orders</th><?php } ?><th>Type</th><th>Code</th><th>Test</th><th>Status</th><th>Ordered</th><th>Last result</th><?php if (hms_can($connection, 'lab.write')) { ?><th>Enter result</th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $sql = 'SELECT o.id, o.patient_id, o.order_type, o.code, o.description, o.status, o.ordered_at, o.lab_catalog_id,
                                    p.first_name, p.last_name,
                                    (SELECT result_text FROM tbl_order_result WHERE order_id = o.id ORDER BY id DESC LIMIT 1) AS last_res
                                    FROM tbl_clinical_order o
                                    JOIN tbl_patient p ON p.id = o.patient_id
                                    WHERE o.facility_id = ' . (int) $fid . " AND o.order_type IN ('lab','imaging')
                                    ORDER BY o.status = 'completed', o.id DESC LIMIT 120";
                            $q = mysqli_query($connection, $sql);
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $lpid = (int) $r['patient_id'];
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                                if (hms_can($connection, 'clinical.read')) {
                                    echo '<td class="small text-nowrap"><a href="patient-chart.php?id=' . $lpid . '">Chart</a> · <a href="clinical-orders.php?patient_id=' . $lpid . '">Orders</a></td>';
                                }
                                echo '<td>' . hms_h((string) $r['order_type']) . '</td>';
                                echo '<td class="text-monospace small">' . hms_h((string) $r['code']) . '</td>';
                                echo '<td>' . hms_h((string) $r['description']) . '</td>';
                                echo '<td><span class="badge badge-light">' . hms_h((string) $r['status']) . '</span></td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['ordered_at']) . '</td>';
                                echo '<td class="small">' . hms_h((string) ($r['last_res'] ?? '')) . '</td>';
                                if (hms_can($connection, 'lab.write')) {
                                    echo '<td>';
                                    if ((string) $r['status'] !== 'completed' && (string) $r['status'] !== 'cancelled') {
                                        echo '<form method="post" class="mb-0">';
                                        echo hms_csrf_field();
                                        echo '<input type="hidden" name="order_id" value="' . (int) $r['id'] . '">';
                                        echo '<textarea name="result_text" class="form-control form-control-sm mb-1" rows="2" placeholder="Result (values, interpretation)" required></textarea>';
                                        echo '<button type="submit" name="save_result" value="1" class="btn btn-sm btn-primary">Save result</button>';
                                        echo '</form>';
                                    } else {
                                        echo '<span class="text-muted">—</span>';
                                    }
                                    echo '</td>';
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
<?php include 'footer.php'; ?>
