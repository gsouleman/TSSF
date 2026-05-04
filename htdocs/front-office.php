<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Front office', [
                    'subtitle' => 'Registration, queue, and scheduling — day-one operations for your site.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Front office', '']],
                ]);
                $hmsFrontOfficeCards = [
                    ['title' => 'Patient register', 'description' => 'Search, register, and open charts.', 'url' => 'patients.php', 'icon' => 'fa-users'],
                ];
                if (hms_can($connection, 'clinical.read')) {
                    $hmsFrontOfficeCards[] = ['title' => 'Notes & Docs', 'description' => 'Encounters, consultations, orders, and charting.', 'url' => 'clinical-documentation.php', 'icon' => 'fa-file-text'];
                }
                if (hms_can($connection, 'opd.read')) {
                    $hmsFrontOfficeCards[] = ['title' => 'Visits', 'description' => 'Filter visits by date, status, and patient.', 'url' => 'visits.php', 'icon' => 'fa-calendar-check-o'];
                }
                $hmsFrontOfficeSched = [
                    ['title' => 'OPD queue', 'description' => 'Today’s outpatient flow and triage.', 'url' => 'opd-queue.php', 'icon' => 'fa-list-ol'],
                ];
                if (hms_can($connection, 'scheduling.read')) {
                    $hmsFrontOfficeSched[] = ['title' => 'Requests', 'description' => 'Open appointments queue (excludes completed) — confirm pending to book.', 'url' => 'requests.php', 'icon' => 'fa-inbox'];
                }
                $hmsFrontOfficeSched[] = ['title' => 'Appointments', 'description' => 'Book and manage visits.', 'url' => 'appointments.php', 'icon' => 'fa-calendar'];
                $hmsFrontOfficeCards = array_merge($hmsFrontOfficeCards, $hmsFrontOfficeSched, [
                    ['title' => 'Consents', 'description' => 'Capture and track consent records.', 'url' => 'consents.php', 'icon' => 'fa-file-text-o'],
                    ['title' => 'Doctor schedule', 'description' => 'Availability blocks by clinician.', 'url' => 'schedule.php', 'icon' => 'fa-calendar-check-o'],
                    ['title' => 'Rooms & resources', 'description' => 'Beds, rooms, and assets for scheduling.', 'url' => 'scheduling-resources.php', 'icon' => 'fa-bed'],
                ]);
                hms_ui_module_hub('', $hmsFrontOfficeCards);
                ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
