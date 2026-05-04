<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
$fid = hms_current_facility_id();
include 'header.php';
$suf = hms_multi_site_enabled($connection) ? ' AND facility_id = ' . (int) $fid : '';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('MPI — duplicate workqueue', [
                'subtitle' => 'Heuristic: same first name, last name, and date of birth. Review before any merge.',
                'breadcrumbs' => [['Patients', 'patients.php'], ['MPI duplicates', '']],
                'secondary' => [
                    ['label' => 'Patient list', 'url' => 'patients.php', 'icon' => 'fa-users'],
                ],
            ]);
            ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>First</th><th>Last</th><th>DOB</th><th>Patient IDs</th><th>Count</th></tr></thead>
                            <tbody>
                            <?php
                            $sql = 'SELECT first_name, last_name, dob, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS c FROM tbl_patient WHERE 1=1' . $suf . ' GROUP BY first_name, last_name, dob HAVING c > 1';
                            $q = mysqli_query($connection, $sql);
                            $n = 0;
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                ++$n;
                                echo '<tr>';
                                echo '<td>' . hms_h((string) $r['first_name']) . '</td>';
                                echo '<td>' . hms_h((string) $r['last_name']) . '</td>';
                                echo '<td class="text-nowrap">' . hms_h((string) $r['dob']) . '</td>';
                                echo '<td class="small text-monospace">' . hms_h((string) $r['ids']) . '</td>';
                                echo '<td>' . (int) $r['c'] . '</td>';
                                echo '</tr>';
                            }
                            if ($n === 0) {
                                echo '<tr><td colspan="5" class="text-muted">No duplicates detected for this scope.</td></tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
