<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <?php
    $hmsBase = function_exists('hms_html_base_href') ? hms_html_base_href() : null;
    if ($hmsBase !== null) {
        echo '<base href="' . hms_h($hmsBase) . '">' . "\n";
    }
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico">
    <title><?php echo hms_h(__hms('app.title')); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    <link rel="stylesheet" type="text/css" href="assets/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap-datetimepicker.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/style.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-theme.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-modern.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-fin-ohada.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-fin-reports.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-forms.css">
    <?php
    $hmsProcCssScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($hmsProcCssScript !== '' && (strpos($hmsProcCssScript, 'procurement-') === 0 || $hmsProcCssScript === 'purchase-order.php')) {
        echo '<link rel="stylesheet" type="text/css" href="assets/css/hms-procurement.css">' . "\n";
    }
    if ($hmsProcCssScript !== '' && in_array($hmsProcCssScript, ['inventory.php', 'purchase-order.php', 'dashboard.php'], true)) {
        echo '<link rel="stylesheet" type="text/css" href="assets/css/hms-inventory.css">' . "\n";
    }
    if ($hmsProcCssScript !== '' && (strpos($hmsProcCssScript, 'financials') === 0 || $hmsProcCssScript === 'expense-management.php')) {
        echo '<link rel="stylesheet" type="text/css" href="assets/css/hms-accounting.css">' . "\n";
    }
    ?>
</head>

<body class="hms-ehr-shell">
    <?php
    global $connection;
    $hmsPortalLimitedNav = false;
    $hmsSidebarHomeHref = 'dashboard.php';
    if (isset($connection) && $connection instanceof mysqli && !empty($_SESSION['name']) && !empty($_SESSION['user_id'])) {
        if (function_exists('hms_staff_portal_nav_is_limited') && hms_staff_portal_nav_is_limited((string) ($_SESSION['role'] ?? ''))) {
            $hmsPortalLimitedNav = true;
            if (function_exists('hms_staff_primary_portal_url')) {
                $hmsSidebarHomeHref = hms_staff_primary_portal_url($connection, (int) $_SESSION['user_id'], (string) ($_SESSION['role'] ?? ''));
            }
        } elseif ((function_exists('hms_is_super_admin') && !hms_is_super_admin()) && function_exists('hms_product_mode_login_landing')) {
            $hmsSliceHome = hms_product_mode_login_landing($connection);
            if (is_string($hmsSliceHome) && $hmsSliceHome !== '') {
                $hmsSidebarHomeHref = $hmsSliceHome;
            }
        }
    }
    $hmsSidebarHomeLabel = ($hmsSidebarHomeHref !== 'dashboard.php') ? 'Home' : 'Dashboard';
    ?>
    <div class="main-wrapper">
        <div class="header hms-topbar">
			<div class="header-left hms-topbar-brand" style="width: 220px; padding: 0; background: transparent; display: flex; align-items: center;">
				<div style="background: #ffffff; width: 60px; height: 100%; display: flex; align-items: center; justify-content: center; box-shadow: 2px 0 5px rgba(0,0,0,0.03);">
					<a href="<?php echo hms_h($hmsSidebarHomeHref); ?>" class="logo" style="margin: 0; padding: 0;">
						<img src="assets/img/logo.png" style="width: 38px; height: 38px; object-fit: contain;" alt="Logo">
					</a>
				</div>
				<div style="padding-left: 12px; flex-grow: 1;">
					<a href="<?php echo hms_h($hmsSidebarHomeHref); ?>" style="text-decoration: none;">
						<span style="font-size: 1.25rem; font-weight: 800; color: #0c8b8b; letter-spacing: 0.5px;">TSSF HMS</span>
					</a>
				</div>
			</div>
            <a id="mobile_btn" class="mobile_btn float-left" href="#sidebar"><i class="fa fa-bars"></i></a>
            <ul class="nav user-menu float-right">
                   <?php
                   if (!empty($_SESSION['user_id']) && isset($connection) && $connection instanceof mysqli && hms_db_table_exists($connection, 'tbl_user_facility')) {
                       $facs = hms_user_facilities($connection, (int) $_SESSION['user_id']);
                       $siteFixed = defined('HMS_FIXED_FACILITY_ID') && (int) HMS_FIXED_FACILITY_ID > 0;
                       if ($siteFixed || count($facs) <= 1) {
                           $siteLabel = hms_current_facility_name($connection);
                           ?>
                   <li class="nav-item">
                        <span class="nav-link" title="Active site" style="cursor: default;">
                            <i class="fa fa-hospital-o"></i> <span class="d-none d-md-inline"><?php echo hms_h($siteLabel); ?></span>
                        </span>
                   </li>
                   <?php
                       } elseif (count($facs) > 1) {
                           $here = hms_h(basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
                           ?>
                   <li class="nav-item dropdown has-arrow">
                        <a href="#" class="dropdown-toggle nav-link" data-toggle="dropdown" title="Switch site">
                            <i class="fa fa-hospital-o"></i> <span class="d-none d-md-inline">Site</span>
                        </a>
                        <div class="dropdown-menu">
                            <?php foreach ($facs as $f) {
                                $sel = ((int) $f['id'] === hms_current_facility_id()) ? ' font-weight-bold' : '';
                                ?>
                            <form method="post" action="switch-facility.php" class="m-0">
                                <?php echo hms_csrf_field(); ?>
                                <input type="hidden" name="facility_id" value="<?php echo (int) $f['id']; ?>">
                                <input type="hidden" name="return" value="<?php echo $here; ?>">
                                <button type="submit" class="dropdown-item<?php echo $sel; ?>"><?php echo hms_h($f['name']); ?></button>
                            </form>
                            <?php } ?>
                        </div>
                   </li>
                   <?php
                       }
                   }
                   ?>
                   <li class="nav-item"><a href="platform-overview.php" class="nav-link" title="Database setup &amp; help"><i class="fa fa-life-ring"></i> <span class="d-none d-md-inline">Help</span></a></li>
                   <li class="nav-item dropdown has-arrow">
                    <a href="#" class="dropdown-toggle nav-link user-link" data-toggle="dropdown">
                        <span class="user-img">
							<img class="rounded-circle" src="assets/img/user.jpg" width="24" alt="">
							<span class="status online"></span>
						</span>
                        <?php
                        if (isset($_SESSION['role']) && (string) $_SESSION['role'] === '99') { ?>
						<span>Super Admin</span>
                    <?php } elseif (isset($_SESSION['role']) && (string) $_SESSION['role'] === '1') { ?>
						<span>Admin</span>
                    <?php } else { ?>
                        <span><?php echo hms_h((string) ($_SESSION['name'] ?? '')); ?></span>
                    <?php } ?>
                    </a>
					<div class="dropdown-menu">
						<a class="dropdown-item" href="my-profile.php">My profile</a>
						<a class="dropdown-item" href="logout.php">Logout</a>
					</div>
                </li>
            </ul>
            <div class="dropdown mobile-user-menu float-right">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="platform-overview.php">Help &amp; setup</a>
                    <a class="dropdown-item" href="my-profile.php">My profile</a>
                    <a class="dropdown-item" href="logout.php">Logout</a>
                </div>
            </div>
        </div>
        <div class="sidebar" id="sidebar">
            <div class="sidebar-inner slimscroll">
                <?php
                // RBAC-gated sidebar section flags (only the two that are actually used as conditionals)
                $hmsNavLaboratory = false;
                if (isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_can($connection, 'lab.read')) {
                    $hmsNavLaboratory = hms_sidebar_section_show(
                        'lab-results.php',
                        'lab-result-edit.php'
                    );
                }
                $hmsNavVisits = false;
                if (isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_can($connection, 'opd.read')) {
                    $hmsNavVisits = hms_sidebar_section_show('visits.php', 'opd-queue.php');
                }
                $hmsSidebarSiteLabel = 'Site';
                if (isset($connection) && $connection instanceof mysqli && function_exists('hms_current_facility_name')) {
                    try {
                        $hmsSidebarSiteLabel = hms_current_facility_name($connection);
                    } catch (Throwable $e) {
                        $hmsSidebarSiteLabel = 'Site';
                    }
                }
                $hmsSoaCur = basename($_SERVER['SCRIPT_NAME'] ?? '');
                $hmsSoaScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
                $hmsSoaOpenTax = (strpos($hmsSoaScriptPath, '/tax/') !== false)
                    || $hmsSoaCur === 'tax-declarations.php'
                    || $hmsSoaCur === 'download-dipe.php'
                    || $hmsSoaCur === 'download-dgi.php';
                $hmsSoaOpenApplications = in_array($hmsSoaCur, ['departments.php', 'add-department.php', 'edit-department.php'], true);
                $hmsSoaOpenPatients = in_array($hmsSoaCur, ['patients.php', 'add-patient.php', 'edit-patient.php', 'patient-chart.php', 'patient-external-docs.php'], true);
                $hmsSoaOpenAppointments = in_array(
                    $hmsSoaCur,
                    ['appointments.php', 'appointments-calendar.php', 'add-appointment.php', 'edit-appointment.php', 'consultations.php', 'consultation-new.php'],
                    true
                );
                $hmsSoaOpenLaboratory = in_array(
                    $hmsSoaCur,
                    ['lab-results.php', 'lab-result-edit.php', 'lab-result-workflow.php'],
                    true
                ) || ($hmsSoaCur === 'clinical-result-report.php' && isset($_GET['type']) && (string) $_GET['type'] === 'lab');
                $hmsSoaOpenRadiology = in_array(
                    $hmsSoaCur,
                    ['radiology-results.php', 'radiology-result-edit.php', 'radiology-result-workflow.php'],
                    true
                ) || ($hmsSoaCur === 'clinical-result-report.php' && isset($_GET['type']) && (string) $_GET['type'] === 'rad');
                $hmsSoaOpenPortals = in_array(
                    $hmsSoaCur,
                    [
                        'portal-front-desk.php',
                        'patient-portal-login.php',
                        'patient-portal.php',
                        'portal-patients.php',
                        'portal-doctors.php',
                        'portal-nursing.php',
                        'portal-laboratory.php',
                        'portal-pharmacy.php',
                        'portal-radiology.php',
                        'portal-accountant.php',
                        'portal-cashier.php',
                        'cashier.php',
                    ],
                    true
                );
                $hmsSoaOpenHealthcare = in_array(
                    $hmsSoaCur,
                    [
                        'patients.php',
                        'add-patient.php',
                        'edit-patient.php',
                        'patient-chart.php',
                        'patient-external-docs.php',
                        'doctors.php',
                        'add-doctor.php',
                        'edit-doctor.php',
                        'portal-nursing.php',
                        'requests.php',
                        'appointments.php',
                        'appointments-calendar.php',
                        'add-appointment.php',
                        'edit-appointment.php',
                        'consultations.php',
                        'consultation-new.php',
                        'visits.php',
                        'opd-queue.php',
                        'lab-results.php',
                        'lab-result-edit.php',
                        'lab-result-workflow.php',
                        'radiology-results.php',
                        'radiology-result-edit.php',
                        'radiology-result-workflow.php',
                        'clinical-result-report.php',
                        'pharmacy.php',
                        'prescriptions.php',
                        'billing-payments.php',
                        'insurance.php',
                        'insurance-claims.php',
                        'cashier.php',
                        'charges.php',
                        'receipts-invoices.php',
                        'transactions.php',
                        'invoice-create.php',
                        'billing-companies.php',
                        'credit-receivables.php',
                        'credit-account.php',
                        'credit-open.php',
                        'adt-board.php',
                        'wallet-management.php',
                    ],
                    true
                );
                $hmsSoaOpenAccounting = in_array(
                    $hmsSoaCur,
                    [
                        'financials.php',
                        'financials-journal.php',
                        'financials-journal-new.php',
                        'financials-journal-view.php',
                        'financials-accounts.php',
                        'financials-cost-centers.php',
                        'financials-trial-balance.php',
                        'financials-general-ledger.php',
                        'financials-cash-flow.php',
                        'financials-accounts-receivable.php',
                        'financials-accounts-payable.php',
                        'financials-bank-reconciliation.php',
                        'financials-sync-gl.php',
                        'financials-balance-sheet.php',
                        'financials-month-end.php',
                        'financials-year-end.php',
                        'financials-journal-loader.php',
                        'financials-statement-monthly.php',
                        'financials-statement-annual.php',
                        'financials-sync-billing.php',
                        'expense-management.php',
                        'expense-management-new.php',
                        'credit-receivables.php',
                        'credit-account.php',
                        'credit-open.php',
                    ],
                    true
                );
                $hmsSoaOpenManage = in_array(
                    $hmsSoaCur,
                    [
                        'service-catalog.php',
                        'inventory.php',
                        'purchase-order.php',
                        'procurement-home.php',
                        'procurement-vendors.php',
                        'procurement-rfq.php',
                        'procurement-quotation.php',
                        'procurement-grn.php',
                        'procurement-match.php',
                        'procurement-invoice.php',
                        'schedule.php',
                        'add-schedule.php',
                        'edit-schedule.php',
                        'scheduling-resources.php',
                        'departments.php',
                        'add-department.php',
                        'edit-department.php',
                        'employees.php',
                        'add-employee.php',
                        'edit-employee.php',
                        'access-control.php',
                        'access-control-roles.php',
                        'access-control-portals.php',
                        'access-control-patient-portal.php',
                        'payroll.php',
                        'payroll-profiles.php',
                        'leave-requests.php',
                        'leave-balances.php',
                        'attendance.php',
                        'holidays.php',
                        'request-leave.php',
                        'my-payslips.php',
                        'my-attendance.php',
                        'my-leave-balance.php',
                        'generate-payslip.php',
                        'super-admin.php',
                    ],
                    true
                );
                $hmsNavHr = isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_can($connection, 'employee.read');
                $hmsSn = (isset($connection) && $connection instanceof mysqli && function_exists('hms_nav_sidebar_modules'))
                    ? hms_nav_sidebar_modules($connection)
                    : array_fill_keys([
                        'portals', 'healthcare', 'accounting', 'tax', 'manage_catalog', 'manage_inventory', 'manage_procurement',
                        'manage_schedule', 'manage_departments', 'manage_staff', 'manage_payroll', 'manage_leave_admin',
                        'manage_holidays', 'manage_self', 'access_control',
                    ], true);
                $hmsNavItemShow = static function (string $itemId) use ($connection, $hmsSn): bool {
                    if (!isset($connection) || !$connection instanceof mysqli || !function_exists('hms_nav_sidebar_item_visible')) {
                        return true;
                    }

                    return hms_nav_sidebar_item_visible($connection, $hmsSn, $itemId);
                };
                $hmsIsDeployAdmin = function_exists('hms_staff_is_deploy_admin') && hms_staff_is_deploy_admin();
                ?>
                <div class="hms-sidebar-brand d-none d-md-flex flex-column align-items-stretch px-3 py-3">
                    <a href="<?php echo hms_h($hmsSidebarHomeHref); ?>" class="hms-sidebar-brand-mark text-decoration-none text-center d-block">
                        <span class="hms-sidebar-brand-line1">SOLIDARITY OF HEARTS</span>
                        <span class="hms-sidebar-brand-line2">HOSPITAL</span>
                    </a>
                </div>
                <nav id="sidebar-menu" class="hms-sidebar-nav sidebar-menu" role="navigation" aria-label="Main">
                    <ul class="list-unstyled mb-2 pb-3 hms-sidebar-list">
                        <?php if (!empty($hmsPortalLimitedNav)) { ?>
                        <li class="hms-sidebar-item px-3 py-2 mb-2">
                            <span class="text-muted small d-block text-uppercase font-weight-bold" style="letter-spacing:0.06em;">My workspace</span>
                        </li>
                        <?php
                        if (isset($connection) && $connection instanceof mysqli && !empty($_SESSION['user_id'])) {
                            $hmsPu = (int) $_SESSION['user_id'];
                            $hmsPr = (string) ($_SESSION['role'] ?? '');
                            $hmsPortalCodes = function_exists('hms_employee_portal_codes_effective') ? hms_employee_portal_codes_effective($connection, $hmsPu, $hmsPr) : [];
                            $hmsUrlMap = function_exists('hms_acl_portal_entry_urls') ? hms_acl_portal_entry_urls() : [];
                            $hmsLabelMap = [];
                            if (function_exists('hms_acl_portal_rows')) {
                                foreach (hms_acl_portal_rows($connection) as $hmsPrw) {
                                    $hmsLabelMap[$hmsPrw['code']] = $hmsPrw['label'];
                                }
                            }
                            foreach ($hmsPortalCodes as $hmsPc) {
                                if (!isset($hmsUrlMap[$hmsPc])) {
                                    continue;
                                }
                                $hmsPlab = $hmsLabelMap[$hmsPc] ?? ucfirst(str_replace('_', ' ', (string) $hmsPc));
                                $hmsPurl = $hmsUrlMap[$hmsPc];
                                $hmsPbase = basename($hmsPurl);
                                ?>
                        <li class="<?php echo hms_nav_active($hmsPbase); ?> hms-sidebar-item">
                            <a href="<?php echo hms_h($hmsPurl); ?>" title="<?php echo hms_h($hmsPlab); ?>">
                                <i class="fa fa-window-maximize" aria-hidden="true"></i><span><?php echo hms_h($hmsPlab); ?></span>
                            </a>
                        </li>
                                <?php
                            }
                        }
                        ?>
                        <li class="hms-sidebar-item mt-3 pt-3 border-top border-secondary" style="border-opacity:0.15!important;">
                            <a href="my-profile.php" title="My profile"><i class="fa fa-user-circle-o" aria-hidden="true"></i><span>My profile</span></a>
                        </li>
                        <li class="hms-sidebar-item"><a href="logout.php" title="Logout"><i class="fa fa-sign-out" aria-hidden="true"></i><span>Logout</span></a></li>
                        <?php } else { ?>
                        <li class="<?php echo hms_nav_active(basename($hmsSidebarHomeHref)); ?> hms-sidebar-item">
                            <a href="<?php echo hms_h($hmsSidebarHomeHref); ?>" title="<?php echo hms_h($hmsSidebarHomeLabel); ?>">
                                <i class="fa fa-th-large" aria-hidden="true"></i><span><?php echo hms_h($hmsSidebarHomeLabel); ?></span>
                            </a>
                        </li>
                        <?php if (function_exists('hms_is_super_admin') && hms_is_super_admin()) { ?>
                        <li class="<?php echo hms_nav_active('super-admin.php'); ?> hms-sidebar-item">
                            <a href="super-admin.php" title="Product mode &amp; deployment">
                                <i class="fa fa-cogs" aria-hidden="true"></i><span>Super Admin</span>
                            </a>
                        </li>
                        <?php } ?>

                        <!-- Portals Section — deploy admins; other roles land on their portal directly -->
                        <?php if ($hmsIsDeployAdmin && !empty($hmsSn['portals'])) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable hms-sidebar-section-group<?php echo $hmsSoaOpenPortals ? ' active' : ''; ?>">
                            <a href="#portalsSection"
                               class="hms-sidebar-parent-toggle hms-sidebar-section-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenPortals ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenPortals ? 'true' : 'false'; ?>"
                               aria-controls="portalsSection"
                               title="Portals">
                                <span class="d-flex align-items-center min-w-0"><span>Portals</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="portalsSection" class="collapse hms-sidebar-group-panel<?php echo $hmsSoaOpenPortals ? ' show' : ''; ?>">
                                <ul class="list-unstyled mb-0">
                                    <?php if ($hmsNavItemShow('portal_front_desk')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-front-desk.php'); ?> hms-sidebar-item">
                                        <a href="portal-front-desk.php" title="Front Desk Portal">
                                            <i class="fa fa-desktop" aria-hidden="true"></i><span>Front Desk</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_patient')) { ?>
                                    <li class="<?php echo hms_nav_active('patient-portal-login.php', 'patient-portal.php', 'portal-patients.php'); ?> hms-sidebar-item">
                                        <a href="patient-portal-login.php" title="Patient Portal" target="_blank">
                                            <i class="fa fa-users" aria-hidden="true"></i><span>Patient</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_doctors')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-doctors.php'); ?> hms-sidebar-item">
                                        <a href="portal-doctors.php" title="Doctor Portal">
                                            <i class="fa fa-user-md" aria-hidden="true"></i><span>Doctor</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_nursing')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-nursing.php'); ?> hms-sidebar-item">
                                        <a href="portal-nursing.php" title="Nurse">
                                            <i class="fa fa-heartbeat" aria-hidden="true"></i><span>Nurse</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_laboratory')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-laboratory.php'); ?> hms-sidebar-item">
                                        <a href="portal-laboratory.php" title="Laboratory Portal">
                                            <i class="fa fa-flask" aria-hidden="true"></i><span>Laboratory</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_pharmacy')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-pharmacy.php'); ?> hms-sidebar-item">
                                        <a href="portal-pharmacy.php" title="Pharmacy Portal">
                                            <i class="fa fa-medkit" aria-hidden="true"></i><span>Pharmacy</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_radiology')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-radiology.php'); ?> hms-sidebar-item">
                                        <a href="portal-radiology.php" title="Radiology Portal">
                                            <i class="fa fa-film" aria-hidden="true"></i><span>Radiology</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_accountant')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-accountant.php'); ?> hms-sidebar-item">
                                        <a href="portal-accountant.php" title="Accountant Portal">
                                            <i class="fa fa-calculator" aria-hidden="true"></i><span>Accountant</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('portal_cashier')) { ?>
                                    <li class="<?php echo hms_nav_active('portal-cashier.php', 'cashier.php'); ?> hms-sidebar-item">
                                        <a href="portal-cashier.php" title="Cashier Portal">
                                            <i class="fa fa-money" aria-hidden="true"></i><span>Cashier</span>
                                        </a>
                                    </li>
                                    <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>

                        <?php if (!empty($hmsSn['healthcare'])) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable hms-sidebar-section-group<?php echo $hmsSoaOpenHealthcare ? ' active' : ''; ?>">
                            <a href="#healthcareSection"
                               class="hms-sidebar-parent-toggle hms-sidebar-section-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenHealthcare ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenHealthcare ? 'true' : 'false'; ?>"
                               aria-controls="healthcareSection"
                               title="Healthcare">
                                <span class="d-flex align-items-center min-w-0"><span>Healthcare</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="healthcareSection" class="collapse hms-sidebar-group-panel<?php echo $hmsSoaOpenHealthcare ? ' show' : ''; ?>">
                                <ul class="list-unstyled mb-0">
                        <?php
                        $hmsShowPatientNavBlock = $hmsNavItemShow('hc_patient_list')
                            || (isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_can($connection, 'patient.write') && $hmsNavItemShow('hc_patient_add'));
                        if ($hmsShowPatientNavBlock) {
                            ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable<?php echo $hmsSoaOpenPatients ? ' active' : ''; ?>">
                            <a href="#patientsDropdown"
                               class="hms-sidebar-parent-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenPatients ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenPatients ? 'true' : 'false'; ?>"
                               aria-controls="patientsDropdown"
                               title="Patient">
                                <span class="d-flex align-items-center min-w-0"><i class="fa fa-users" aria-hidden="true"></i><span>Patient</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="patientsDropdown" class="collapse hms-sidebar-submenu<?php echo $hmsSoaOpenPatients ? ' show' : ''; ?>">
                                <ul class="list-unstyled hms-sidebar-submenu-list">
                                    <?php if ($hmsNavItemShow('hc_patient_list')) { ?>
                                    <li class="<?php echo hms_nav_active('patients.php', 'edit-patient.php', 'patient-chart.php', 'patient-external-docs.php'); ?>"><a href="patients.php">Patient list</a></li>
                                    <?php } ?>
                                    <?php if (isset($connection) && hms_can($connection, 'patient.write') && $hmsNavItemShow('hc_patient_add')) { ?>
                                    <li class="<?php echo hms_nav_active('add-patient.php'); ?>"><a href="add-patient.php">Add patient</a></li>
                                    <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_doctors')) { ?>
                        <li class="<?php echo hms_nav_active('doctors.php', 'add-doctor.php', 'edit-doctor.php'); ?> hms-sidebar-item">
                            <a href="doctors.php" title="Doctor">
                                <i class="fa fa-user-md" aria-hidden="true"></i><span>Doctor</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsShowNursingHealthcare = !isset($connection) || !$connection instanceof mysqli || !function_exists('hms_can')
                            || !hms_db_table_exists($connection, 'tbl_acl_permission')
                            || hms_can($connection, 'nursing.read')
                            || $hmsIsDeployAdmin;
                        if ($hmsShowNursingHealthcare && $hmsNavItemShow('hc_nursing')) {
                            ?>
                        <li class="<?php echo hms_nav_active('portal-nursing.php'); ?> hms-sidebar-item">
                            <a href="portal-nursing.php" title="Nurse">
                                <i class="fa fa-heartbeat" aria-hidden="true"></i><span>Nurse</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_requests')) { ?>
                        <li class="<?php echo hms_nav_active('requests.php'); ?> hms-sidebar-item">
                            <a href="requests.php" title="Requests">
                                <i class="fa fa-envelope" aria-hidden="true"></i><span>Requests</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsShowApptNavBlock = $hmsNavItemShow('hc_appt_calendar') || $hmsNavItemShow('hc_appt_list') || $hmsNavItemShow('hc_appt_consult');
                        if ($hmsShowApptNavBlock) {
                            ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable<?php echo $hmsSoaOpenAppointments ? ' active' : ''; ?>">
                            <a href="#appointmentsDropdown"
                               class="hms-sidebar-parent-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenAppointments ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenAppointments ? 'true' : 'false'; ?>"
                               aria-controls="appointmentsDropdown"
                               title="Appointments">
                                <span class="d-flex align-items-center min-w-0"><i class="fa fa-calendar-check-o" aria-hidden="true"></i><span>Appointments</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="appointmentsDropdown" class="collapse hms-sidebar-submenu<?php echo $hmsSoaOpenAppointments ? ' show' : ''; ?>">
                                <ul class="list-unstyled hms-sidebar-submenu-list">
                                    <?php if ($hmsNavItemShow('hc_appt_calendar')) { ?>
                                    <li class="<?php echo hms_nav_active('appointments-calendar.php'); ?>"><a href="appointments-calendar.php">Calendar</a></li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('hc_appt_list')) { ?>
                                    <li class="<?php echo hms_nav_active('appointments.php', 'add-appointment.php', 'edit-appointment.php'); ?>"><a href="appointments.php">Appointments</a></li>
                                    <?php } ?>
                                    <?php if ($hmsNavItemShow('hc_appt_consult')) { ?>
                                    <li class="<?php echo hms_nav_active('consultations.php', 'consultation-new.php'); ?>"><a href="consultations.php">Consultations</a></li>
                                    <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_visits')) { ?>
                        <li class="<?php echo hms_nav_active('visits.php', 'opd-queue.php'); ?> hms-sidebar-item">
                            <a href="visits.php" title="Visits">
                                <i class="fa fa-h-square" aria-hidden="true"></i><span>Visits</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_lab')) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable<?php echo $hmsSoaOpenLaboratory ? ' active' : ''; ?>">
                            <a href="#labDropdown"
                               class="hms-sidebar-parent-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenLaboratory ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenLaboratory ? 'true' : 'false'; ?>"
                               aria-controls="labDropdown"
                               title="Laboratory">
                                <span class="d-flex align-items-center min-w-0"><i class="fa fa-flask" aria-hidden="true"></i><span>Laboratory</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="labDropdown" class="collapse hms-sidebar-submenu<?php echo $hmsSoaOpenLaboratory ? ' show' : ''; ?>">
                                <ul class="list-unstyled hms-sidebar-submenu-list">
                                    <li class="<?php echo hms_nav_active('lab-results.php', 'lab-result-edit.php', 'lab-result-workflow.php'); ?>"><a href="lab-results.php">Lab results</a></li>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_radiology')) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable<?php echo $hmsSoaOpenRadiology ? ' active' : ''; ?>">
                            <a href="#radDropdown"
                               class="hms-sidebar-parent-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenRadiology ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenRadiology ? 'true' : 'false'; ?>"
                               aria-controls="radDropdown"
                               title="Radiology">
                                <span class="d-flex align-items-center min-w-0"><i class="fa fa-film" aria-hidden="true"></i><span>Radiology</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="radDropdown" class="collapse hms-sidebar-submenu<?php echo $hmsSoaOpenRadiology ? ' show' : ''; ?>">
                                <ul class="list-unstyled hms-sidebar-submenu-list">
                                    <li class="<?php echo hms_nav_active('radiology-results.php', 'radiology-result-edit.php', 'radiology-result-workflow.php'); ?>"><a href="radiology-results.php">Radiology results</a></li>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_pharmacy')) { ?>
                        <li class="<?php echo hms_nav_active('pharmacy.php', 'prescriptions.php'); ?> hms-sidebar-item">
                            <a href="pharmacy.php" title="Pharmacy">
                                <i class="fa fa-medkit" aria-hidden="true"></i><span>Pharmacy</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsCanCashierNav = $hmsIsDeployAdmin
                            || (isset($connection) && $connection instanceof mysqli && function_exists('hms_can')
                                && (hms_can($connection, 'cashier.write') || hms_can($connection, 'billing.write')));
                        ?>
                        <?php if (($hmsIsDeployAdmin || hms_can($connection, 'billing.read')) && $hmsNavItemShow('hc_billing')) { ?>
                        <li class="<?php echo hms_nav_active('billing-payments.php', 'charges.php', 'receipts-invoices.php', 'transactions.php', 'invoice-create.php', 'billing-companies.php'); ?> hms-sidebar-item">
                            <a href="billing-payments.php" title="Billing">
                                <i class="fa fa-credit-card" aria-hidden="true"></i><span>Billing</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsCanInsuranceSidebar = $hmsIsDeployAdmin
                            || hms_can($connection, 'patient.read')
                            || hms_can($connection, 'billing.read')
                            || hms_can($connection, 'cashier.write');
                        ?>
                        <?php if ($hmsCanInsuranceSidebar) { ?>
                        <?php if ($hmsNavItemShow('hc_insurance')) { ?>
                        <li class="<?php echo hms_nav_active('insurance.php'); ?> hms-sidebar-item">
                            <a href="insurance.php" title="Insurance carriers">
                                <i class="fa fa-shield" aria-hidden="true"></i><span>Insurance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_insurance_claims')) { ?>
                        <li class="<?php echo hms_nav_active('insurance-claims.php'); ?> hms-sidebar-item">
                            <a href="insurance-claims.php" title="Insurance Claims">
                                <i class="fa fa-file-text-o" aria-hidden="true"></i><span>Insurance Claims</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php } ?>
                        <?php if ($hmsCanCashierNav && $hmsNavItemShow('hc_cashier')) { ?>
                        <li class="<?php echo hms_nav_active('cashier.php', 'portal-cashier.php'); ?> hms-sidebar-item">
                            <a href="cashier.php" title="Cashier">
                                <i class="fa fa-money" aria-hidden="true"></i><span>Cashier</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsIsDeployAdmin || hms_can($connection, 'billing.read')) { ?>
                        <?php
                        $hmsCreditNav = isset($connection) && $connection instanceof mysqli
                            && function_exists('hms_credit_tables_ok') && function_exists('hms_credit_can_read')
                            && hms_credit_tables_ok($connection) && hms_credit_can_read($connection);
                        if ($hmsCreditNav && $hmsNavItemShow('hc_credit')) { ?>
                        <li class="<?php echo hms_nav_active('credit-receivables.php', 'credit-account.php', 'credit-open.php'); ?> hms-sidebar-item">
                            <a href="credit-receivables.php" title="Credit &amp; Receivables">
                                <i class="fa fa-handshake-o" aria-hidden="true"></i><span>Credit</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('hc_ward')) { ?>
                        <li class="<?php echo hms_nav_active('adt-board.php'); ?> hms-sidebar-item">
                            <a href="adt-board.php" title="Ward & Bed MGT">
                                <i class="fa fa-bed" aria-hidden="true"></i><span>Ward & Bed MGT</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (($hmsIsDeployAdmin
                            || (isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_can($connection, 'billing.write'))) && $hmsNavItemShow('hc_wallet')) { ?>
                        <li class="<?php echo hms_nav_active('wallet-management.php'); ?> hms-sidebar-item">
                            <a href="wallet-management.php" title="Wallet &amp; Pre-Paid Cards — cash top-up for patient wallets">
                                <i class="fa fa-credit-card-alt" aria-hidden="true"></i><span>Top-Up the Wallet</span>
                            </a>
                        </li>
                        <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>

                        <?php
                        $hmsNavFinancialsAcct = $hmsIsDeployAdmin;
                        if (!$hmsNavFinancialsAcct && isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_db_table_exists($connection, 'tbl_acl_permission')) {
                            $hmsNavFinancialsAcct = hms_can($connection, 'financials.read');
                        }
                        $hmsNavExpensesAcct = $hmsIsDeployAdmin;
                        if (!$hmsNavExpensesAcct && isset($connection) && $connection instanceof mysqli && function_exists('hms_can') && hms_db_table_exists($connection, 'tbl_acl_permission')) {
                            $hmsNavExpensesAcct = hms_can($connection, 'expenses.read') || hms_can($connection, 'billing.read');
                        }
                        $hmsExpensesTableReady = isset($connection) && $connection instanceof mysqli && function_exists('hms_expenses_ready') && hms_expenses_ready($connection);
                        $hmsShowAccountingSection = !empty($hmsSn['accounting'])
                            && ($hmsNavFinancialsAcct || ($hmsNavExpensesAcct && $hmsExpensesTableReady));
                        if ($hmsShowAccountingSection) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable hms-sidebar-section-group<?php echo $hmsSoaOpenAccounting ? ' active' : ''; ?>">
                            <a href="#accountingSection"
                               class="hms-sidebar-parent-toggle hms-sidebar-section-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenAccounting ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenAccounting ? 'true' : 'false'; ?>"
                               aria-controls="accountingSection"
                               title="Accounting">
                                <span class="d-flex align-items-center min-w-0"><span>Accounting</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="accountingSection" class="collapse hms-sidebar-group-panel<?php echo $hmsSoaOpenAccounting ? ' show' : ''; ?>">
                                <ul class="list-unstyled mb-0">
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_financials')) { ?>
                        <li class="<?php echo hms_nav_active('financials.php'); ?> hms-sidebar-item">
                            <a href="financials.php" title="Core accounting hub (Cameroon)">
                                <i class="fa fa-line-chart" aria-hidden="true"></i><span>Accounting hub</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_trial_balance')) { ?>
                        <li class="<?php echo hms_nav_active('financials-trial-balance.php'); ?> hms-sidebar-item">
                            <a href="financials-trial-balance.php" title="Trial balance">
                                <i class="fa fa-table" aria-hidden="true"></i><span>Trial balance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_general_ledger')) { ?>
                        <li class="<?php echo hms_nav_active('financials-general-ledger.php'); ?> hms-sidebar-item">
                            <a href="financials-general-ledger.php" title="General ledger">
                                <i class="fa fa-book" aria-hidden="true"></i><span>General ledger</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_cash_flow')) { ?>
                        <li class="<?php echo hms_nav_active('financials-cash-flow.php'); ?> hms-sidebar-item">
                            <a href="financials-cash-flow.php" title="Cash flow statement">
                                <i class="fa fa-random" aria-hidden="true"></i><span>Cash flow</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_ar')) { ?>
                        <li class="<?php echo hms_nav_active('financials-accounts-receivable.php'); ?> hms-sidebar-item">
                            <a href="financials-accounts-receivable.php" title="Accounts receivable">
                                <i class="fa fa-user-md" aria-hidden="true"></i><span>Accounts receivable</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_ap')) { ?>
                        <li class="<?php echo hms_nav_active('financials-accounts-payable.php'); ?> hms-sidebar-item">
                            <a href="financials-accounts-payable.php" title="Accounts payable">
                                <i class="fa fa-truck" aria-hidden="true"></i><span>Accounts payable</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_bank_rec')) { ?>
                        <li class="<?php echo hms_nav_active('financials-bank-reconciliation.php'); ?> hms-sidebar-item">
                            <a href="financials-bank-reconciliation.php" title="Bank reconciliation">
                                <i class="fa fa-university" aria-hidden="true"></i><span>Bank reconciliation</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_balance_sheet')) { ?>
                        <li class="<?php echo hms_nav_active('financials-balance-sheet.php'); ?> hms-sidebar-item">
                            <a href="financials-balance-sheet.php" title="Balance sheet">
                                <i class="fa fa-balance-scale" aria-hidden="true"></i><span>Balance sheet</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsNavSyncGl = isset($connection) && $connection instanceof mysqli
                            && function_exists('hms_fin_can_write') && hms_fin_can_write($connection);
                        if ($hmsNavSyncGl && $hmsNavItemShow('acct_sync_gl')) { ?>
                        <li class="<?php echo hms_nav_active('financials-sync-gl.php'); ?> hms-sidebar-item">
                            <a href="financials-sync-gl.php" title="Sync billing &amp; expenses to GL">
                                <i class="fa fa-refresh" aria-hidden="true"></i><span>Sync to GL</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_journal_loader')) { ?>
                        <li class="<?php echo hms_nav_active('financials-journal-loader.php'); ?> hms-sidebar-item">
                            <a href="financials-journal-loader.php" title="Journal loader">
                                <i class="fa fa-upload" aria-hidden="true"></i><span>Journal loader</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_monthly_pl')) { ?>
                        <li class="<?php echo hms_nav_active('financials-month-end.php'); ?> hms-sidebar-item">
                            <a href="financials-month-end.php" title="PROFIT & LOSS MONTH END">
                                <i class="fa fa-calendar" aria-hidden="true"></i><span>Monthly P&amp;L</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_annual_pl')) { ?>
                        <li class="<?php echo hms_nav_active('financials-year-end.php'); ?> hms-sidebar-item">
                            <a href="financials-year-end.php" title="PROFIT & LOSS YEAR END">
                                <i class="fa fa-calendar-check-o" aria-hidden="true"></i><span>Annual P&amp;L</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_monthly_review')) { ?>
                        <li class="<?php echo hms_nav_active('financials-statement-monthly.php'); ?> hms-sidebar-item">
                            <a href="financials-statement-monthly.php" title="Monthly financial statement">
                                <i class="fa fa-file-text-o" aria-hidden="true"></i><span>Monthly Review</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && $hmsNavItemShow('acct_annual_review')) { ?>
                        <li class="<?php echo hms_nav_active('financials-statement-annual.php'); ?> hms-sidebar-item">
                            <a href="financials-statement-annual.php" title="Annual financial statement">
                                <i class="fa fa-files-o" aria-hidden="true"></i><span>Annual Review</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavExpensesAcct && $hmsExpensesTableReady && $hmsNavItemShow('acct_expenses')) { ?>
                        <li class="<?php echo hms_nav_active('expense-management.php', 'expense-management-new.php'); ?> hms-sidebar-item">
                            <a href="expense-management.php" title="Expense Management">
                                <i class="fa fa-money" aria-hidden="true"></i><span>Expense Management</span>
                            </a>
                        </li>
                        <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavFinancialsAcct && !empty($hmsSn['tax'])) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable hms-sidebar-section-group<?php echo $hmsSoaOpenTax ? ' active' : ''; ?>">
                            <a href="#taxSection"
                               class="hms-sidebar-parent-toggle hms-sidebar-section-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenTax ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenTax ? 'true' : 'false'; ?>"
                               aria-controls="taxSection"
                               title="Tax">
                                <span class="d-flex align-items-center min-w-0"><span>Tax</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="taxSection" class="collapse hms-sidebar-group-panel<?php echo $hmsSoaOpenTax ? ' show' : ''; ?>">
                                <ul class="list-unstyled mb-0">
                        <?php if ($hmsNavItemShow('tax_home')) { ?>
                        <li class="<?php echo hms_nav_active('tax/tax-home.php'); ?> hms-sidebar-item">
                            <a href="tax/tax-home.php" title="Tax module">
                                <i class="fa fa-calculator" aria-hidden="true"></i><span>Tax home</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_declarations')) { ?>
                        <li class="<?php echo hms_nav_active('tax-declarations.php'); ?> hms-sidebar-item">
                            <a href="tax-declarations.php" title="VAT &amp; facility tax worksheets">
                                <i class="fa fa-file-text" aria-hidden="true"></i><span>VAT &amp; declarations</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_payroll_settings')) { ?>
                        <li class="<?php echo hms_nav_active('tax/settings.php'); ?> hms-sidebar-item">
                            <a href="tax/settings.php" title="Employer &amp; payroll rates">
                                <i class="fa fa-cog" aria-hidden="true"></i><span>Payroll tax settings</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_payroll_lines')) { ?>
                        <li class="<?php echo hms_nav_active('tax/payroll-records.php'); ?> hms-sidebar-item">
                            <a href="tax/payroll-records.php" title="Monthly payroll lines">
                                <i class="fa fa-table" aria-hidden="true"></i><span>Payroll lines</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_cnps')) { ?>
                        <li class="<?php echo hms_nav_active('tax/cnps_export.php'); ?> hms-sidebar-item">
                            <a href="tax/cnps_export.php" title="CNPS DIPE">
                                <i class="fa fa-building" aria-hidden="true"></i><span>CNPS DIPE</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_dgi')) { ?>
                        <li class="<?php echo hms_nav_active('tax/dgi_export.php'); ?> hms-sidebar-item">
                            <a href="tax/dgi_export.php" title="DGI CSV aids">
                                <i class="fa fa-download" aria-hidden="true"></i><span>DGI CSV</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_compliance')) { ?>
                        <li class="<?php echo hms_nav_active('tax/compliance.php'); ?> hms-sidebar-item">
                            <a href="tax/compliance.php" title="Compliance calendar">
                                <i class="fa fa-calendar" aria-hidden="true"></i><span>Compliance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsNavItemShow('tax_reports')) { ?>
                        <li class="<?php echo hms_nav_active('tax/reports.php'); ?> hms-sidebar-item">
                            <a href="tax/reports.php" title="Annual tax summary">
                                <i class="fa fa-bar-chart" aria-hidden="true"></i><span>Tax reports</span>
                            </a>
                        </li>
                        <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php if (function_exists('hms_sidebar_manage_any_visible') && hms_sidebar_manage_any_visible($hmsSn)) { ?>
                        <li class="hms-sidebar-item hms-sidebar-expandable hms-sidebar-section-group<?php echo $hmsSoaOpenManage ? ' active' : ''; ?>">
                            <a href="#manageSection"
                               class="hms-sidebar-parent-toggle hms-sidebar-section-toggle d-flex align-items-center w-100 text-decoration-none<?php echo $hmsSoaOpenManage ? '' : ' collapsed'; ?>"
                               data-toggle="collapse"
                               role="button"
                               aria-expanded="<?php echo $hmsSoaOpenManage ? 'true' : 'false'; ?>"
                               aria-controls="manageSection"
                               title="Manage">
                                <span class="d-flex align-items-center min-w-0"><span>Manage</span></span>
                                <i class="fa fa-angle-down hms-soa-chevron ml-2 flex-shrink-0" aria-hidden="true"></i>
                            </a>
                            <div id="manageSection" class="collapse hms-sidebar-group-panel<?php echo $hmsSoaOpenManage ? ' show' : ''; ?>">
                                <ul class="list-unstyled mb-0">
                        <?php if (!empty($hmsSn['manage_procurement'])) { ?>
                        <li class="<?php echo hms_nav_active(
                            'procurement-home.php',
                            'procurement-vendors.php',
                            'procurement-rfq.php',
                            'procurement-quotation.php',
                            'procurement-grn.php',
                            'procurement-match.php',
                            'procurement-invoice.php'
                        ); ?> hms-sidebar-item">
                            <a href="procurement-home.php" title="Procurement — RFQ through payment">
                                <i class="fa fa-shopping-basket" aria-hidden="true"></i><span>Procurement</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsNavCatalogOk = $hmsIsDeployAdmin
                            || (isset($connection) && $connection instanceof mysqli && function_exists('hms_can')
                                && (hms_can($connection, 'billing.read') || hms_can($connection, 'inventory.read')));
                        ?>
                        <?php if ((!empty($hmsSn['manage_catalog'])) && $hmsNavCatalogOk && $hmsNavItemShow('m_service_catalog')) { ?>
                        <li class="<?php echo hms_nav_active('service-catalog.php'); ?> hms-sidebar-item">
                            <a href="service-catalog.php" title="Service Catalog">
                                <i class="fa fa-tags" aria-hidden="true"></i><span>Service Catalog</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ((!empty($hmsSn['manage_inventory']) || !empty($hmsSn['manage_procurement'])) && $hmsNavItemShow('m_inventory')) { ?>
                        <li class="<?php echo hms_nav_active('inventory.php', 'purchase-order.php'); ?> hms-sidebar-item">
                            <a href="inventory.php" title="Inventory">
                                <i class="fa fa-cubes" aria-hidden="true"></i><span>Inventory</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_schedule']) && $hmsNavItemShow('m_doctor_schedule')) { ?>
                        <li class="<?php echo hms_nav_active('schedule.php', 'add-schedule.php', 'edit-schedule.php', 'scheduling-resources.php'); ?> hms-sidebar-item">
                            <a href="schedule.php" title="Doctor Schedule">
                                <i class="fa fa-calendar" aria-hidden="true"></i><span>Doctor Schedule</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_departments']) && $hmsNavItemShow('m_departments')) { ?>
                        <li class="<?php echo hms_nav_active('departments.php', 'add-department.php', 'edit-department.php'); ?> hms-sidebar-item">
                            <a href="departments.php" title="Departments">
                                <i class="fa fa-hospital-o" aria-hidden="true"></i><span>Departments</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_staff']) && $hmsNavItemShow('m_staffs')) { ?>
                        <li class="<?php echo hms_nav_active('employees.php', 'add-employee.php', 'edit-employee.php'); ?> hms-sidebar-item">
                            <a href="employees.php" title="Staffs">
                                <i class="fa fa-users" aria-hidden="true"></i><span>Staffs</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php
                        $hmsNavHrBlock = !empty($hmsNavHr)
                            && (
                                !empty($hmsSn['manage_payroll'])
                                || !empty($hmsSn['manage_leave_admin'])
                                || !empty($hmsSn['manage_holidays'])
                                || !empty($hmsSn['manage_self'])
                            );
                        if ($hmsNavHrBlock) { ?>
                        <?php if (!empty($hmsNavHr) && !empty($hmsSn['manage_payroll']) && $hmsNavItemShow('m_payroll')) { ?>
                        <li class="<?php echo hms_nav_active('payroll.php', 'payroll-profiles.php', 'generate-payslip.php'); ?> hms-sidebar-item">
                            <a href="payroll.php" title="Payroll">
                                <i class="fa fa-money" aria-hidden="true"></i><span>Payroll</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsIsDeployAdmin && !empty($hmsSn['manage_payroll']) && $hmsNavItemShow('m_pay_profiles')) { ?>
                        <li class="<?php echo hms_nav_active('payroll-profiles.php'); ?> hms-sidebar-item">
                            <a href="payroll-profiles.php" title="Pay profiles">
                                <i class="fa fa-id-card-o" aria-hidden="true"></i><span>Pay profiles</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsIsDeployAdmin && !empty($hmsSn['manage_leave_admin']) && $hmsNavItemShow('m_leave_approvals')) { ?>
                        <li class="<?php echo hms_nav_active('leave-requests.php', 'leave-balances.php'); ?> hms-sidebar-item">
                            <a href="leave-requests.php" title="Leave approvals">
                                <i class="fa fa-calendar-check-o" aria-hidden="true"></i><span>Leave approvals</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsIsDeployAdmin && !empty($hmsSn['manage_leave_admin']) && $hmsNavItemShow('m_attendance')) { ?>
                        <li class="<?php echo hms_nav_active('attendance.php'); ?> hms-sidebar-item">
                            <a href="attendance.php" title="Attendance">
                                <i class="fa fa-clock-o" aria-hidden="true"></i><span>Attendance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($hmsIsDeployAdmin && !empty($hmsSn['manage_holidays']) && $hmsNavItemShow('m_holidays')) { ?>
                        <li class="<?php echo hms_nav_active('holidays.php'); ?> hms-sidebar-item">
                            <a href="holidays.php" title="Holidays">
                                <i class="fa fa-sun-o" aria-hidden="true"></i><span>Holidays</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_self']) && $hmsNavItemShow('m_request_leave')) { ?>
                        <li class="<?php echo hms_nav_active('request-leave.php'); ?> hms-sidebar-item">
                            <a href="request-leave.php" title="Request leave">
                                <i class="fa fa-plane" aria-hidden="true"></i><span>Request leave</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_self']) && $hmsNavItemShow('m_my_payslips')) { ?>
                        <li class="<?php echo hms_nav_active('my-payslips.php'); ?> hms-sidebar-item">
                            <a href="my-payslips.php" title="My payslips">
                                <i class="fa fa-file-text-o" aria-hidden="true"></i><span>My payslips</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_self']) && $hmsNavItemShow('m_my_attendance')) { ?>
                        <li class="<?php echo hms_nav_active('my-attendance.php'); ?> hms-sidebar-item">
                            <a href="my-attendance.php" title="My attendance">
                                <i class="fa fa-list-alt" aria-hidden="true"></i><span>My attendance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if (!empty($hmsSn['manage_self']) && $hmsNavItemShow('m_leave_balance')) { ?>
                        <li class="<?php echo hms_nav_active('my-leave-balance.php'); ?> hms-sidebar-item">
                            <a href="my-leave-balance.php" title="Leave balance">
                                <i class="fa fa-pie-chart" aria-hidden="true"></i><span>Leave balance</span>
                            </a>
                        </li>
                        <?php } ?>
                        <?php } ?>
                        <?php
                        if (!empty($hmsSn['access_control'])
                            && isset($connection) && $connection instanceof mysqli && function_exists('hms_show_access_control_nav') && hms_show_access_control_nav($connection)
                            && $hmsNavItemShow('m_access_control')) { ?>
                        <li class="<?php echo hms_nav_active('access-control.php', 'access-control-roles.php', 'access-control-portals.php', 'access-control-patient-portal.php'); ?> hms-sidebar-item">
                            <a href="access-control.php" title="Access Control">
                                <i class="fa fa-shield" aria-hidden="true"></i><span>Access Control</span>
                            </a>
                        </li>
                        <?php } ?>
                                </ul>
                            </div>
                        </li>
                        <?php } ?>
                        <?php } ?>
                    </ul>
                </nav>
            </div>
        </div>
