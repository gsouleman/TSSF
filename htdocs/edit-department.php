<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: departments.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_department WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: departments.php');
    exit;
}

if (isset($_REQUEST['save-department'])) {
    if (!hms_csrf_validate($_REQUEST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
    $department_name = (string) ($_REQUEST['department'] ?? '');
    $description = (string) ($_REQUEST['description'] ?? '');
    $status = (int) ($_REQUEST['status'] ?? 1);

    $upd = mysqli_prepare($connection, 'UPDATE tbl_department SET department_name=?, description=?, status=? WHERE id=?');
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'ssii', $department_name, $description, $status, $id);
        if (mysqli_stmt_execute($upd)) {
            $msg = 'Department updated successfully';
            $stmt2 = mysqli_prepare($connection, 'SELECT * FROM tbl_department WHERE id = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt2, 'i', $id);
            mysqli_stmt_execute($stmt2);
            $fetched = hms_stmt_fetch_assoc($stmt2);
            if ($fetched !== null) {
                $row = $fetched;
            }
            mysqli_stmt_close($stmt2);
        } else {
            $msg = 'Error!';
        }
        mysqli_stmt_close($upd);
    } else {
        $msg = 'Error!';
    }
    }
}

?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Edit department', [
                    'subtitle' => 'Department #' . (int) $id,
                    'breadcrumbs' => [['Departments', 'departments.php'], ['Edit', null]],
                    'back' => 'departments.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-lg-8 col-xl-7">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Details</h2>
                                    <div class="form-group">
                                        <label for="dept_name">Department name</label>
                                        <input id="dept_name" class="form-control" type="text" name="department" value="<?php echo hms_h((string) $row['department_name']); ?>" required>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="dept_desc">Description</label>
                                        <textarea id="dept_desc" rows="4" class="form-control" name="description" required><?php echo hms_h((string) $row['description']); ?></textarea>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Status</h2>
                                    <div class="form-group mb-0">
                                        <span class="d-block mb-1" style="font-size:0.8125rem;font-weight:600;color:#334155;">Department status</span>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="dept_active" value="1" <?php echo ((int) $row['status'] === 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="dept_active">Active</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="status" id="dept_inactive" value="0" <?php echo ((int) $row['status'] === 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="dept_inactive">Inactive</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-light hms-form-footer d-flex justify-content-end flex-wrap">
                                <a href="departments.php" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                                <button type="submit" class="btn btn-primary mb-2 mb-sm-0" name="save-department">Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

<?php
include 'footer.php';
hms_ui_flash_toast_script(isset($msg) ? (string) $msg : null);
?>