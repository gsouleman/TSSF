<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'prescription.read');
$fid = hms_current_facility_id();
$ok = hms_workflow_table_ok($connection, 'tbl_prescription');
include 'header.php';
$rxSecondary = [
    ['label' => 'Lab results', 'url' => 'lab-results.php', 'icon' => 'fa-flask'],
    ['label' => 'Pharmacy', 'url' => 'pharmacy.php', 'icon' => 'fa-medkit'],
];
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Prescriptions', [
                'subtitle' => 'Lab and medication lines for pharmacy and documentation.',
                'breadcrumbs' => [['Clinical', null], ['Prescriptions', '']],
                'primary' => hms_can($connection, 'prescription.write')
                    ? ['label' => 'New prescription', 'url' => 'prescription-new.php', 'icon' => 'fa-plus']
                    : null,
                'secondary' => $rxSecondary,
            ]);
            ?>
            <?php if (!$ok) { ?>
            <div class="alert alert-warning">Run migration <code>003_clinical_workflow.sql</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>ID</th><th>Patient</th><th>Title</th><th>Status</th><th>Date</th><th class="text-nowrap">Links</th><th></th></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query(
                                $connection,
                                'SELECT r.id, r.patient_id, r.title, r.status, r.created_at, p.first_name, p.last_name FROM tbl_prescription r
                                 JOIN tbl_patient p ON p.id = r.patient_id
                                 WHERE r.facility_id = ' . (int) $fid . ' ORDER BY r.id DESC LIMIT 100'
                            );
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                $ppid = (int) $r['patient_id'];
                                echo '<td>' . hms_h($r['first_name'] . ' ' . $r['last_name']) . '</td>';
                                echo '<td>' . hms_h((string) $r['title']) . '</td>';
                                echo '<td>' . hms_h((string) $r['status']) . '</td>';
                                echo '<td class="small">' . hms_h((string) $r['created_at']) . '</td>';
                                echo '<td class="small text-nowrap">';
                                if (hms_can($connection, 'clinical.read')) {
                                    echo '<a class="mr-1" href="patient-chart.php?id=' . $ppid . '">Chart</a>';
                                } else {
                                    echo '—';
                                }
                                echo '</td>';
                                echo '<td><a class="btn btn-sm btn-outline-primary" href="prescription.php?id=' . (int) $r['id'] . '">Open</a></td>';
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
