<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (!function_exists('hms_require_access_control_manage')) {
    require_once __DIR__ . '/includes/access_control.php';
}
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!isset($connection) || !$connection instanceof mysqli) {
    http_response_code(503);
    exit('Database connection is not available.');
}
hms_require_access_control_manage($connection);
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Access Control', [
                    'subtitle' => 'Staff accounts, RBAC permissions, and portal entry for each employee.',
                    'breadcrumbs' => [['Access Control', null]],
                ]);
                ?>
                <div class="row">
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="rounded-circle d-flex align-items-center justify-content-center mr-3" style="width:48px;height:48px;background:rgba(12,139,139,.12);">
                                        <i class="fa fa-key text-primary fa-lg"></i>
                                    </span>
                                    <h2 class="h5 mb-0 font-weight-bold">Roles &amp; permissions</h2>
                                </div>
                                <p class="text-muted small mb-3">Map each staff role to application permissions (clinical, billing, OPD, and more).</p>
                                <a href="access-control-roles.php" class="btn btn-primary btn-sm font-weight-bold">Open</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="rounded-circle d-flex align-items-center justify-content-center mr-3" style="width:48px;height:48px;background:rgba(26,107,216,.12);">
                                        <i class="fa fa-th-large text-info fa-lg"></i>
                                    </span>
                                    <h2 class="h5 mb-0 font-weight-bold">Portal access</h2>
                                </div>
                                <p class="text-muted small mb-3">Choose which portals each employee may open (Front Desk, Doctors, Lab, Pharmacy, and others).</p>
                                <a href="access-control-portals.php" class="btn btn-outline-primary btn-sm font-weight-bold">Open</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="rounded-circle d-flex align-items-center justify-content-center mr-3" style="width:48px;height:48px;background:rgba(34,139,84,.12);">
                                        <i class="fa fa-user-circle-o text-success fa-lg"></i>
                                    </span>
                                    <h2 class="h5 mb-0 font-weight-bold">Patient portal</h2>
                                </div>
                                <p class="text-muted small mb-3">Search patients, reset portal passwords, and disable online access when needed.</p>
                                <a href="access-control-patient-portal.php" class="btn btn-outline-success btn-sm font-weight-bold">Open</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="rounded-circle d-flex align-items-center justify-content-center mr-3" style="width:48px;height:48px;background:rgba(100,116,139,.12);">
                                        <i class="fa fa-users text-secondary fa-lg"></i>
                                    </span>
                                    <h2 class="h5 mb-0 font-weight-bold">Staff directory</h2>
                                </div>
                                <p class="text-muted small mb-3">Create and edit employee records, usernames, and passwords.</p>
                                <a href="employees.php" class="btn btn-outline-secondary btn-sm font-weight-bold">Employees</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h3 class="h6 font-weight-bold text-uppercase text-muted mb-2">How it works</h3>
                        <ul class="mb-0 pl-3 small text-muted">
                            <li class="mb-1"><strong>Permissions</strong> apply by <em>role</em> (same as elsewhere in the app, e.g. lab results, billing).</li>
                    <li class="mb-1"><strong>Portals</strong> can be set per <em>person</em>. If you leave someone with no portal checkboxes, their access follows the role default until you assign portals explicitly.</li>
                    <li class="mb-1"><strong>Patient portal</strong> uses each patient’s email and a separate portal password (managed here or when editing a patient).</li>
                    <li class="mb-0">After changing portal access, the user should sign in again for the new landing page to apply.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
