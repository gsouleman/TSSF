<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_schedule'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        http_response_code(400);
        exit('Invalid security token.');
    }
    $delId = (int) ($_POST['id'] ?? 0);
    if ($delId > 0) {
        if (hms_multi_site_enabled($connection)) {
            $fid = hms_current_facility_id();
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_schedule WHERE id = ? AND facility_id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $delId, $fid);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_schedule WHERE id = ?');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $delId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
        hms_audit_log($connection, 'schedule.delete', 'schedule', $delId);
    }
    header('Location: schedule.php');
    exit;
}

$suf = hms_multi_site_enabled($connection) ? ' WHERE facility_id = ' . hms_current_facility_id() : '';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Doctor availability', [
                    'subtitle' => 'Recurring slots and messages shown when booking.',
                    'primary' => ['label' => 'Add schedule', 'url' => 'add-schedule.php', 'icon' => 'fa-clock-o'],
                ]);
                ?>
                <div class="card border-0 shadow-sm hms-data-card">
                    <div class="card-body p-0">
                <div class="table-responsive">
                                    <table class="datatable table table-stripped hms-table-actions">
                                    <thead>
                                        <tr>
                                            <th>Doctor Name</th>
                                            <th>Available Days</th>
                                            <th>Start Time</th>
                                            <th>End Time</th>
                                            <th>Message</th>
                                            <th>Status</th>
                                            <th class="text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $fetch_query = mysqli_query($connection, 'SELECT * FROM tbl_schedule' . $suf);
                                        while ($fetch_query && $row = mysqli_fetch_array($fetch_query)) {
                                        ?>
                                        <tr>
                                            <td><?php echo hms_h((string) $row['doctor_name']); ?></td>
                                            <td><?php echo hms_h((string) $row['available_days']); ?></td>
                                            <td><?php echo hms_h((string) $row['start_time']); ?></td>
                                            <td><?php echo hms_h((string) $row['end_time']); ?></td>
                                            <td><?php echo hms_h((string) $row['message']); ?></td>
                                            <?php if ($row['status'] == 1) { ?>
                                            <td><span class="custom-badge status-green">Active</span></td>
                                        <?php } else { ?>
                                            <td><span class="custom-badge status-red">Inactive</span></td>
                                        <?php } ?>
                                            <td class="text-right">
                                            <div class="dropdown dropdown-action">
                                                <a href="#" class="action-icon dropdown-toggle" data-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
                                                <div class="dropdown-menu dropdown-menu-right">
                                                    <a class="dropdown-item" href="edit-schedule.php?id=<?php echo (int) $row['id']; ?>"><i class="fa fa-pencil m-r-5"></i> Edit</a>
                                                    <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this schedule?');">
                                                        <?php echo hms_csrf_field(); ?>
                                                        <input type="hidden" name="delete_schedule" value="1">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-danger border-0 bg-transparent p-0 m-0 w-100 text-left"><i class="fa fa-trash-o m-r-5"></i> Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                    </div>
                </div>

            </div>

        </div>

<?php
include 'footer.php';
?>
