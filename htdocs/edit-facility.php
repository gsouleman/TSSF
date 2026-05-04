<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name']) || (string) $_SESSION['role'] !== '1') {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'facility.admin');
$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: facilities.php');
    exit;
}

if (isset($_POST['save']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $name = (string) ($_POST['name'] ?? '');
    $tz = (string) ($_POST['timezone'] ?? 'UTC');
    $status = (int) ($_POST['status'] ?? 1);
    $st = mysqli_prepare($connection, 'UPDATE tbl_facility SET name = ?, timezone = ?, status = ? WHERE id = ?');
    if ($st) {
        mysqli_stmt_bind_param($st, 'ssii', $name, $tz, $status, $id);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        hms_audit_log($connection, 'facility.update', 'facility', $id);
        header('Location: facilities.php');
        exit;
    }
}
$row = null;
$st = mysqli_prepare($connection, 'SELECT * FROM tbl_facility WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$row = hms_stmt_fetch_assoc($st);
mysqli_stmt_close($st);
if (!$row) {
    header('Location: facilities.php');
    exit;
}
include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Edit facility — ' . (string) $row['code'], [
                'subtitle' => 'Update display name, timezone, and activation for this site.',
                'breadcrumbs' => [['Sites', 'facilities.php'], ['Edit', '']],
                'back' => 'facilities.php',
            ]);
            ?>
            <div class="card border-0 shadow-sm hms-form-card col-lg-8 px-0">
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Name</label>
                            <input class="form-control" name="name" value="<?php echo hms_h((string) $row['name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Timezone</label>
                            <input class="form-control" name="timezone" value="<?php echo hms_h((string) $row['timezone']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <option value="1"<?php echo (int) $row['status'] === 1 ? ' selected' : ''; ?>>Active</option>
                                <option value="0"<?php echo (int) $row['status'] === 0 ? ' selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit" name="save" value="1">Save</button>
                        <a href="facilities.php" class="btn btn-link">Cancel</a>
                    </form>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
