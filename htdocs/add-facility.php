<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name']) || (string) $_SESSION['role'] !== '1') {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'facility.admin');

if (isset($_POST['save']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $code = preg_replace('/[^A-Z0-9_]/i', '', (string) ($_POST['code'] ?? ''));
    $name = (string) ($_POST['name'] ?? '');
    $tz = (string) ($_POST['timezone'] ?? 'UTC');
    $st = mysqli_prepare($connection, 'INSERT INTO tbl_facility (code, name, timezone, status) VALUES (?,?,?,1)');
    if ($st && $code !== '' && $name !== '') {
        mysqli_stmt_bind_param($st, 'sss', $code, $name, $tz);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        hms_audit_log($connection, 'facility.create', 'facility', (int) mysqli_insert_id($connection));
        header('Location: facilities.php');
        exit;
    }
}
include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Add facility', [
                'subtitle' => 'Register a new site code for multi-tenant operation.',
                'breadcrumbs' => [['Sites', 'facilities.php'], ['Add', '']],
                'back' => 'facilities.php',
            ]);
            ?>
            <div class="card border-0 shadow-sm hms-form-card col-lg-8 px-0">
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Code</label>
                            <input class="form-control" name="code" required pattern="[A-Za-z0-9_]+" placeholder="e.g. MAIN_CAMPUS">
                        </div>
                        <div class="form-group">
                            <label>Name</label>
                            <input class="form-control" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Timezone</label>
                            <input class="form-control" name="timezone" value="UTC" placeholder="UTC">
                        </div>
                        <button class="btn btn-primary" type="submit" name="save" value="1">Save</button>
                        <a href="facilities.php" class="btn btn-link">Cancel</a>
                    </form>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
