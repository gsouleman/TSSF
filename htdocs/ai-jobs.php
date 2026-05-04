<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'analytics.read');
$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_ai_job');

$flash = isset($_SESSION['ai_jobs_flash']) ? (string) $_SESSION['ai_jobs_flash'] : '';
unset($_SESSION['ai_jobs_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (isset($_POST['enqueue']) && hms_can($connection, 'ai.manage')) {
        $jt = (string) ($_POST['job_type'] ?? 'nlp_summarize');
        $pj = (string) ($_POST['payload_json'] ?? '{}');
        $st = mysqli_prepare($connection, 'INSERT INTO tbl_ai_job (facility_id, job_type, payload_json, status, created_at) VALUES (?,?,?,?,NOW())');
        if ($st) {
            $qs = 'queued';
            mysqli_stmt_bind_param($st, 'isss', $fid, $jt, $pj, $qs);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            hms_audit_log($connection, 'ai.job.enqueue', 'ai_job', (int) mysqli_insert_id($connection));
            $_SESSION['ai_jobs_flash'] = 'Job queued.';
        }
        header('Location: ai-jobs.php');
        exit;
    }
    if (isset($_POST['cancel_job']) && hms_can($connection, 'ai.manage')) {
        $jid = (int) ($_POST['job_id'] ?? 0);
        if ($jid > 0) {
            $st = mysqli_prepare($connection, "UPDATE tbl_ai_job SET status = 'cancelled', processed_at = NOW() WHERE id = ? AND facility_id = ? AND status = 'queued' LIMIT 1");
            if ($st) {
                mysqli_stmt_bind_param($st, 'ii', $jid, $fid);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                hms_audit_log($connection, 'ai.job.cancel', 'ai_job', $jid);
                $_SESSION['ai_jobs_flash'] = 'Job cancelled (if it was still queued).';
            }
        }
        header('Location: ai-jobs.php');
        exit;
    }
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('AI job queue', [
                'subtitle' => 'Stub queue for NLP / summarization style workloads.',
                'breadcrumbs' => [['Insights', null], ['AI jobs', '']],
                'secondary' => [
                    ['label' => 'Analytics', 'url' => 'analytics-dashboard.php', 'icon' => 'fa-bar-chart'],
                ],
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Import migration for <code>tbl_ai_job</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm hms-data-card mb-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead><tr><th>ID</th><th>Created</th><th>Type</th><th>Status</th><?php if (hms_can($connection, 'ai.manage')) { ?><th></th><?php } ?></tr></thead>
                            <tbody>
                            <?php
                            $q = mysqli_query($connection, 'SELECT id, created_at, job_type, status FROM tbl_ai_job WHERE facility_id = ' . (int) $fid . ' ORDER BY id DESC LIMIT 50');
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td>' . (int) $r['id'] . '</td>';
                                echo '<td class="text-nowrap small">' . hms_h((string) $r['created_at']) . '</td>';
                                echo '<td>' . hms_h((string) $r['job_type']) . '</td>';
                                echo '<td><span class="badge badge-secondary">' . hms_h((string) $r['status']) . '</span></td>';
                                if (hms_can($connection, 'ai.manage')) {
                                    echo '<td>';
                                    if ((string) $r['status'] === 'queued') {
                                        echo '<form method="post" class="d-inline">' . hms_csrf_field();
                                        echo '<input type="hidden" name="job_id" value="' . (int) $r['id'] . '">';
                                        echo '<button type="submit" name="cancel_job" value="1" class="btn btn-sm btn-outline-danger">Cancel</button></form>';
                                    } else {
                                        echo '<span class="text-muted small">—</span>';
                                    }
                                    echo '</td>';
                                }
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php if (hms_can($connection, 'ai.manage')) { ?>
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-header bg-white font-weight-bold">Enqueue job</div>
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <div class="form-group">
                            <label>Job type</label>
                            <input class="form-control" name="job_type" value="nlp_summarize">
                        </div>
                        <div class="form-group">
                            <label>Payload JSON</label>
                            <textarea class="form-control" name="payload_json" rows="3">{}</textarea>
                        </div>
                        <button class="btn btn-primary" type="submit" name="enqueue" value="1">Enqueue</button>
                    </form>
                </div>
            </div>
            <?php } else { ?>
            <p class="text-muted small">Users with <code>ai.manage</code> can enqueue or cancel queued jobs.</p>
            <?php } ?>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
