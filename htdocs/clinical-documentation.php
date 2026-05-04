<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'clinical.read');
include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Notes & Docs', [
                    'subtitle' => 'Consultations, prescriptions, and narrative documentation in one workspace.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Notes & Docs', '']],
                ]);
                hms_ui_module_hub('', [
                    ['title' => 'Patient chart', 'description' => 'Open a chart from the register to document care.', 'url' => 'patients.php', 'icon' => 'fa-user-md'],
                    ['title' => 'Consultations', 'description' => 'SOAP-style visits and clinical narrative.', 'url' => 'consultations.php', 'icon' => 'fa-comments'],
                    ['title' => 'Prescriptions', 'description' => 'Medications and pharmacy handoff.', 'url' => 'prescriptions.php', 'icon' => 'fa-file-text-o'],
                    ['title' => 'Lab results', 'description' => 'Laboratory registry and printing.', 'url' => 'lab-results.php', 'icon' => 'fa-eyedropper'],
                    ['title' => 'Pharmacy', 'description' => 'Dispensing queue.', 'url' => 'pharmacy.php', 'icon' => 'fa-medkit'],
                ]);
                ?>
                <div class="alert alert-light border mt-2 mb-0 small text-muted">
                    Dedicated progress-note templates and e-signatures can extend this module; today, use <strong>Consultations</strong> and <strong>Patient chart</strong> for longitudinal documentation.
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
