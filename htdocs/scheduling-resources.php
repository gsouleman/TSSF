<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'scheduling.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_scheduling_resource');

$flash = isset($_SESSION['sched_res_flash']) ? (string) $_SESSION['sched_res_flash'] : '';
unset($_SESSION['sched_res_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null) && hms_can($connection, 'scheduling.write')) {
    if (isset($_POST['add'])) {
        $rt = (string) ($_POST['resource_type'] ?? 'room');
        $nm = trim((string) ($_POST['name'] ?? ''));
        $st = mysqli_prepare($connection, 'INSERT INTO tbl_scheduling_resource (facility_id, resource_type, name, status) VALUES (?,?,?,1)');
        if ($st && $nm !== '') {
            mysqli_stmt_bind_param($st, 'iss', $fid, $rt, $nm);
            mysqli_stmt_execute($st);
            $rid = (int) mysqli_insert_id($connection);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'scheduling.resource.create', 'scheduling_resource', $rid);
            $_SESSION['sched_res_flash'] = 'Resource added.';
        }
        header('Location: scheduling-resources.php');
        exit;
    }
    if (isset($_POST['toggle_status'])) {
        $rid = (int) ($_POST['resource_id'] ?? 0);
        if ($rid > 0) {
            mysqli_query($connection, 'UPDATE tbl_scheduling_resource SET status = IF(status=1,0,1) WHERE id = ' . (int) $rid . ' AND facility_id = ' . (int) $fid);
            hms_audit_log($connection, 'scheduling.resource.toggle', 'scheduling_resource', $rid);
            $_SESSION['sched_res_flash'] = 'Resource status updated.';
        }
        header('Location: scheduling-resources.php');
        exit;
    }
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Scheduling resources', [
                'subtitle' => 'Rooms and equipment available for booking at this site.',
                'breadcrumbs' => [['Scheduling', null], ['Resources', '']],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for <code>tbl_scheduling_resource</code>.</div>
            <?php } else { ?>
            <?php if (hms_can($connection, 'scheduling.write')) { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4">
                <div class="card-header bg-white font-weight-bold">Add resource</div>
                <div class="card-body">
                    <form method="post" class="form-row align-items-end">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group col-md-3 mb-0">
                            <label class="small text-muted">Type</label>
                            <input class="form-control" name="resource_type" placeholder="room / equipment" value="room">
                        </div>
                        <div class="form-group col-md-5 mb-0">
                            <label class="small text-muted">Name</label>
                            <input class="form-control" name="name" placeholder="Display name" required>
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <button class="btn btn-primary btn-block" type="submit" name="add" value="1">Add</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>ID</th><th>Type</th><th>Name</th><th>Status</th><?php if (hms_can($connection, 'scheduling.write')) { ?><th></th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query($connection, 'SELECT id, resource_type, name, status FROM tbl_scheduling_resource WHERE facility_id = ' . (int) $fid . ' ORDER BY name');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $active = (int) $r['status'] === 1;
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td>' . hms_h((string) $r['resource_type']) . '</td>';
                                echo '<td>' . hms_h((string) $r['name']) . '</td>';
                                echo '<td><span class="badge badge-' . ($active ? 'success' : 'secondary') . '">' . ($active ? 'Active' : 'Inactive') . '</span></td>';
                                if (hms_can($connection, 'scheduling.write')) {
                                    echo '<td><form method="post" class="d-inline">' . hms_csrf_field();
                                    echo '<input type="hidden" name="resource_id" value="' . (int) $r['id'] . '">';
                                    echo '<button type="submit" name="toggle_status" value="1" class="btn btn-sm btn-outline-secondary">Toggle</button></form></td>';
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
