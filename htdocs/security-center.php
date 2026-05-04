<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if ((string) ($_SESSION['role'] ?? '') !== '1') {
    header('Location: dashboard.php');
    exit;
}
include 'header.php';
$uid = (int) ($_SESSION['user_id'] ?? 0);
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Security center', [
                'subtitle' => 'MFA and SSO placeholders aligned with the platform migration.',
                'breadcrumbs' => [['Administration', null], ['Security', '']],
                'secondary' => [
                    ['label' => 'Audit log', 'url' => 'audit-log.php', 'icon' => 'fa-list', 'class' => 'btn-outline-secondary'],
                ],
            ]);
            ?>
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold">MFA (gap 8)</div>
                        <div class="card-body">
                            <p class="small text-muted">Table <code>tbl_user_mfa</code> is provisioned by the migration. Integrate TOTP/WebAuthn and enforce step-up for high-risk actions in a future iteration.</p>
                            <?php if (hms_db_table_exists($connection, 'tbl_user_mfa')) {
                                $r = mysqli_fetch_assoc(mysqli_query($connection, 'SELECT method, enabled FROM tbl_user_mfa WHERE employee_id = ' . $uid . ' LIMIT 1'));
                                if ($r) {
                                    echo '<p class="mb-0"><strong>Method:</strong> ' . hms_h((string) $r['method']) . '<br><strong>Enabled:</strong> ' . ((int) $r['enabled'] ? 'Yes' : 'No') . '</p>';
                                } else {
                                    echo '<p class="mb-0 text-muted">No MFA row for this user yet.</p>';
                                }
                            } else { ?>
                            <p class="text-warning small mb-0">Run the platform migration to create MFA tables.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card h-100">
                        <div class="card-header bg-white font-weight-bold">SSO / OIDC (gap 8)</div>
                        <div class="card-body">
                            <p class="small text-muted mb-0">Register providers in <code>tbl_sso_provider</code> and add an OIDC callback route when you wire an identity provider.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
