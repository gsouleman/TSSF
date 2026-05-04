<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name']) || (string) $_SESSION['role'] !== '1') {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'facility.admin');
include 'header.php';

if (!hms_db_table_exists($connection, 'tbl_facility')) {
    echo '<div class="page-wrapper"><div class="content"><div class="alert alert-warning">Run database migration to create tbl_facility.</div></div></div>';
    include 'footer.php';
    exit;
}
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Sites / facilities', [
                    'subtitle' => 'Multi-site configuration (admin).',
                    'primary' => ['label' => 'Add facility', 'url' => 'add-facility.php', 'icon' => 'fa-hospital-o'],
                ]);
                ?>
                <div class="card border-0 shadow-sm hms-data-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                <table class="table datatable mb-0">
                    <thead><tr><th>Code</th><th>Name</th><th>Timezone</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php
                    $q = mysqli_query($connection, 'SELECT id, code, name, timezone, status FROM tbl_facility ORDER BY id');
                    while ($q && $r = mysqli_fetch_assoc($q)) {
                        echo '<tr><td>' . hms_h((string) $r['code']) . '</td><td>' . hms_h((string) $r['name']) . '</td><td>' . hms_h((string) $r['timezone']) . '</td><td>' . ((int) $r['status'] === 1 ? 'Active' : 'Inactive') . '</td>';
                        echo '<td><a class="btn btn-sm btn-secondary" href="edit-facility.php?id=' . (int) $r['id'] . '">Edit</a></td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
