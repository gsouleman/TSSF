<?php
declare(strict_types=1);

/**
 * One-time (or occasional) admin tool: randomly set primary_department for Doctors, Nurses,
 * and Nursing Aids who do not have one yet, using this site's department catalog.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/employee_department.php';

if (empty($_SESSION['name']) || (string) ($_SESSION['role'] ?? '') !== '1') {
    header('Location: index.php');
    exit;
}

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);

$resultMsg = '';
$runStats = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['run_backfill_departments'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $resultMsg = 'Invalid security token.';
    } else {
        $runStats = hms_backfill_random_primary_departments($connection, $fid, $ms);
        $resultMsg = 'Updated ' . (int) ($runStats['updated'] ?? 0) . ' employee(s).';
        if (!empty($runStats['messages'])) {
            $resultMsg .= ' ' . implode(' ', $runStats['messages']);
        }
    }
}

$pending = hms_backfill_random_primary_departments_pending_count($connection, $fid, $ms);
$hasCol = hms_db_column_exists($connection, 'tbl_employee', 'primary_department');

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Backfill random departments', [
                    'subtitle' => 'Doctors, nurses, and nursing aids without a department',
                    'breadcrumbs' => [['Employees', 'employees.php'], ['Backfill departments', null]],
                    'back' => 'employees.php',
                ]);
                ?>
                <div class="row justify-content-center">
                    <div class="col-xl-8 col-lg-10">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <?php if (!$hasCol) { ?>
                                <div class="alert alert-warning mb-0">The <code>primary_department</code> column is not on <code>tbl_employee</code>. Run migration <strong>028</strong> first.</div>
                                <?php } else { ?>
                                <p class="mb-3">For the <strong>current site</strong>, this assigns a <strong>random</strong> active department from <a href="departments.php">Departments</a> to each staff member who:</p>
                                <ul>
                                    <li>has role <strong>Doctor</strong>, <strong>Nurse</strong>, or <strong>Nursing Aid</strong>, and</li>
                                    <li>has no employment department (<code>primary_department</code>) yet.</li>
                                </ul>
                                <p class="text-muted small mb-4">Only rows that still have an empty department are updated. Staff who already have a department are left unchanged.</p>
                                <p class="mb-4"><strong>Eligible without a department (this site):</strong> <?php echo (int) $pending; ?></p>
                                <?php if ($resultMsg !== '') { ?>
                                <div class="alert alert-info"><?php echo hms_h($resultMsg); ?></div>
                                <?php } ?>
                                <form method="post" onsubmit="return confirm('Assign random departments to all eligible doctors, nurses, and nursing aids for this site?');">
                                    <?php echo hms_csrf_field(); ?>
                                    <button type="submit" name="run_backfill_departments" value="1" class="btn btn-primary"<?php echo $pending < 1 ? ' disabled' : ''; ?>>Run random assignment</button>
                                    <a href="employees.php" class="btn btn-outline-secondary ml-2">Back to Employees</a>
                                </form>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php
include __DIR__ . '/footer.php';
