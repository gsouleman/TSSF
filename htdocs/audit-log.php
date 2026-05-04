<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'audit.read');
$ms = hms_multi_site_enabled($connection);
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_audit_log');
include 'header.php';
$where = $ms ? ' WHERE facility_id = ' . (int) $fid : '';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Audit log', [
                'subtitle' => $ms ? 'Recent actions scoped to your current facility.' : 'Recent actions across the deployment.',
                'breadcrumbs' => [['Compliance', null], ['Audit log', '']],
            ]);
            ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Run migration to create <code>tbl_audit_log</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>When</th><th>User</th><?php if ($ms) { ?><th>Facility</th><?php } ?><th>Action</th><th>Entity</th><th>Id</th></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query($connection, 'SELECT created_at, user_id, facility_id, action, entity, entity_id FROM tbl_audit_log' . $where . ' ORDER BY id DESC LIMIT 200');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['created_at']) . '</td>';
                                echo '<td>' . (int) $r['user_id'] . '</td>';
                                if ($ms) {
                                    echo '<td>' . (int) $r['facility_id'] . '</td>';
                                }
                                echo '<td>' . hms_h((string) $r['action']) . '</td>';
                                echo '<td>' . hms_h((string) $r['entity']) . '</td>';
                                echo '<td>' . (int) ($r['entity_id'] ?? 0) . '</td>';
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
