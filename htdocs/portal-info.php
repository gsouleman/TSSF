<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'patient.read');
include 'header.php';

$portalReady = hms_patient_portal_ready($connection);
$loginUrl = 'patient-portal-login.php';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$dir = rtrim($dir, '/');
$pathPrefix = ($dir === '' || $dir === '.') ? '' : $dir;
$patientLoginAbs = $scheme . '://' . $host . ($pathPrefix === '' ? '' : $pathPrefix) . '/' . $loginUrl;
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Patient portal', [
                    'subtitle' => 'Configure access for patients and share the public sign-in link. Open patient sign-in in a new tab so your staff session stays active.',
                    'secondary' => [
                        ['label' => 'Open patient sign-in', 'url' => $loginUrl, 'icon' => 'fa-external-link', 'class' => 'btn-outline-success'],
                    ],
                ]);
                ?>

                <div class="row">
                    <div class="col-lg-8 mb-4">
                        <div class="card border-0 shadow-sm hms-form-card">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted font-weight-bold mb-3">How it works</h2>
                                <ol class="pl-3 mb-0">
                                    <li class="mb-2">Run migration <code class="text-dark">hms/database/migrations/002_patient_portal.sql</code> in phpMyAdmin if you have not already (adds portal fields on <code>tbl_patient</code>).</li>
                                    <li class="mb-2">Open <strong>Patients → Edit</strong> for a person, turn on <strong>Allow online portal sign-in</strong>, and set a <strong>portal password</strong> (they will use this with their <strong>email on file</strong>).</li>
                                    <li class="mb-0">Give patients this address to sign in: <br>
                                        <code class="d-inline-block mt-2 px-2 py-1 bg-light rounded"><?php echo hms_h($patientLoginAbs); ?></code>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 mb-4">
                        <div class="card border-0 shadow-sm <?php echo $portalReady ? 'border-left-success' : ''; ?>" style="border-left: 4px solid <?php echo $portalReady ? '#22c55e' : '#f59e0b'; ?> !important;">
                            <div class="card-body">
                                <h2 class="h6 font-weight-bold mb-2">Status</h2>
                                <?php if ($portalReady) { ?>
                                <p class="small text-success mb-0"><i class="fa fa-check-circle"></i> Database columns are present. You can enable portal access on patient records.</p>
                                <?php } else { ?>
                                <p class="small text-warning mb-0"><i class="fa fa-exclamation-triangle"></i> Portal columns missing — run <code>002_patient_portal.sql</code> first.</p>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted font-weight-bold mb-2">Security notes</h2>
                        <p class="small text-muted mb-0">The patient area uses a separate session from staff. Patients only see their own demographics and appointments matched to their record. Use strong portal passwords and only enable the portal for verified patients.</p>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
