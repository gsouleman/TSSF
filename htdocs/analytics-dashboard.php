<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'analytics.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_analytics_daily');

$flash = isset($_SESSION['analytics_flash']) ? (string) $_SESSION['analytics_flash'] : '';
unset($_SESSION['analytics_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['refresh_today']) && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $today = date('Y-m-d');
    mysqli_query($connection, 'DELETE FROM tbl_analytics_daily WHERE facility_id = ' . (int) $fid . " AND metric_date = '" . mysqli_real_escape_string($connection, $today) . "'");
    $pc = mysqli_fetch_row(mysqli_query($connection, 'SELECT COUNT(*) FROM tbl_patient WHERE status = 1' . (hms_multi_site_enabled($connection) ? ' AND facility_id = ' . (int) $fid : '')));
    $c = (int) ($pc[0] ?? 0);
    mysqli_query(
        $connection,
        "INSERT INTO tbl_analytics_daily (facility_id, metric_date, metric_code, metric_value) VALUES (" . (int) $fid . ", '" . mysqli_real_escape_string($connection, $today) . "', 'active_patients', " . $c . ')'
    );
    $_SESSION['analytics_flash'] = 'Today\'s snapshot refreshed.';
    header('Location: analytics-dashboard.php');
    exit;
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Analytics', [
                'subtitle' => 'Daily metric snapshots for reporting (stub pipeline).',
                'breadcrumbs' => [['Insights', null], ['Analytics', '']],
                'secondary' => [
                    ['label' => 'AI jobs', 'url' => 'ai-jobs.php', 'icon' => 'fa-cogs'],
                ],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for <code>tbl_analytics_daily</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-form-card mb-4">
                <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
                    <p class="text-muted small mb-2 mb-md-0">Recomputes the <strong>active_patients</strong> count for today at this site.</p>
                    <form method="post" class="mb-0">
                        <?php echo hms_csrf_field(); ?>
                        <button class="btn btn-outline-primary btn-sm" type="submit" name="refresh_today" value="1"><i class="fa fa-refresh mr-1"></i> Refresh today's snapshot</button>
                    </form>
                </div>
            </div>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>Date</th><th>Metric</th><th class="text-right">Value</th></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query($connection, 'SELECT metric_date, metric_code, metric_value FROM tbl_analytics_daily WHERE facility_id = ' . (int) $fid . ' ORDER BY metric_date DESC, metric_code LIMIT 100');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr><td class="text-nowrap">' . hms_h((string) $r['metric_date']) . '</td><td>' . hms_h((string) $r['metric_code']) . '</td><td class="text-right">' . hms_h((string) $r['metric_value']) . '</td></tr>';
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
