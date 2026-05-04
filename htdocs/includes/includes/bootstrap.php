<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    if (PHP_VERSION_ID >= 70300) {
        ini_set('session.cookie_samesite', 'Lax');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/facility.php';
require_once __DIR__ . '/facility_admission.php';
require_once __DIR__ . '/patient_care_state.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/hms_product_mode.php';
require_once __DIR__ . '/hms_profile_modules_json.php';
require_once __DIR__ . '/hms_deployment_profile.php';
require_once __DIR__ . '/hms_sidebar_nav_items.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/receipt_invoice.php';
require_once __DIR__ . '/billing_catalog_prices.php';
require_once __DIR__ . '/transactions_ledger.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/ui_layout.php';
require_once __DIR__ . '/patient_portal.php';
require_once __DIR__ . '/cameroon_geo.php';
require_once __DIR__ . '/cameroon_money.php';
require_once __DIR__ . '/clinical_workflow.php';
require_once __DIR__ . '/payment_ticket.php';
require_once __DIR__ . '/result_workflow.php';
require_once __DIR__ . '/opd_queue.php';
require_once __DIR__ . '/vitals_workflow.php';
require_once __DIR__ . '/doctor_photo.php';
require_once __DIR__ . '/financials.php';
require_once __DIR__ . '/financials_ohada.php';
require_once __DIR__ . '/expenses.php';
require_once __DIR__ . '/ar_ap.php';
require_once __DIR__ . '/credit_receivables.php';

hms_csrf_seed();
if (isset($connection) && $connection instanceof mysqli && !empty($_SESSION['user_id'])) {
    if (defined('HMS_FIXED_FACILITY_ID') && (int) HMS_FIXED_FACILITY_ID > 0) {
        $_SESSION['facility_id'] = (int) HMS_FIXED_FACILITY_ID;
    } elseif (empty($_SESSION['facility_id'])) {
        hms_facility_set_default_for_user($connection, (int) $_SESSION['user_id']);
    }
}
