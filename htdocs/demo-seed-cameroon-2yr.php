<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/demo_seed_cameroon_2yr.php';

if (empty($_SESSION['name']) || (string) $_SESSION['role'] !== '1') {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'facility.admin');

$facilityId = hms_current_facility_id();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$messages = [];
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $messages[] = 'Invalid security token. Try again.';
    } else {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('memory_limit', '256M');
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        try {
            if (isset($_POST['clean_demo_2yr'])) {
                hms_demo_seed_cleanup($connection, $facilityId);
                $messages[] = 'Removed demo data for this facility: @demo-2yr patients, receipts, credit rows, DEMO2YR-* expenses (+ GL journals), demo staff.';
            } elseif (isset($_POST['seed_cameroon_2yr'])) {
                $result = hms_demo_seed_cameroon_2yr($connection, $facilityId, $userId > 0 ? $userId : 1);
                $messages = $result['messages'] ?? [];
            }
        } catch (\Throwable $e) {
            $messages[] = 'Operation failed: ' . $e->getMessage();
            if (isset($_GET['debug']) && (string) $_GET['debug'] === '1') {
                $messages[] = $e->getFile() . ':' . (string) $e->getLine();
            }
            $result = [
                'ok' => false,
                'messages' => $messages,
                'counts' => [
                    'staff' => 0,
                    'patients' => 0,
                    'consultations' => 0,
                    'appointments' => 0,
                    'opd_visits' => 0,
                    'admissions' => 0,
                    'lab' => 0,
                    'rad' => 0,
                    'receipts' => 0,
                    'credit_accounts' => 0,
                    'expenses' => 0,
                ],
            ];
        }
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Cameroon 2-year demo seed', [
                    'subtitle' => 'Tagged demo staff, patients, and operating expenses across ~24 months (revenue + expense management + accounting reports).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Setup', 'platform-overview.php'], ['Demo seed', '']],
                ]);
                ?>
                <div class="row">
                    <div class="col-12 col-lg-8">
                        <div class="card border-0 shadow-sm hms-data-card mb-3">
                            <div class="card-body">
                                <p class="text-muted small mb-3">
                                    Data is tagged (<code><?php echo hms_h(HMS_DEMO_2YR_EMAIL_SUFFIX); ?></code> patients;
                                    <code><?php echo hms_h(HMS_DEMO_2YR_STAFF_PREFIX); ?>*</code> usernames;
                                    expense references <code><?php echo hms_h(HMS_DEMO_2YR_EXPENSE_REF_PREFIX); ?>*</code>) so it can be removed without touching real records.
                                    Requires migrations for billing, OPD, lab/radiology, admissions, credit, expenses (<code>026_expense_management.sql</code>), and optional GL (<code>019</code>) — see <a href="platform-overview.php">platform overview</a>.
                                </p>
                                <?php foreach ($messages as $m) { ?>
                                    <div class="alert alert-info"><?php echo hms_h((string) $m); ?></div>
                                <?php } ?>
                                <?php if (is_array($result) && !empty($result['counts'])) { ?>
                                    <div class="alert alert-secondary small mb-0">
                                        <strong>Counts:</strong>
                                        <?php
                                        $bits = [];
                                        foreach ($result['counts'] as $k => $v) {
                                            $bits[] = hms_h((string) $k) . ': ' . (int) $v;
                                        }
                                        echo implode(' · ', $bits);
                                        ?>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="card border-0 shadow-sm hms-form-card">
                            <div class="card-header bg-white font-weight-bold">Actions</div>
                            <div class="card-body">
                                <form method="post" class="mb-3" onsubmit="return confirm('Seed demo data for facility <?php echo (int) $facilityId; ?>?');">
                                    <?php echo hms_csrf_field(); ?>
                                    <button type="submit" name="seed_cameroon_2yr" value="1" class="btn btn-primary btn-block">Run Cameroon 2-year seed</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Remove all @demo-2yr patients and demo staff for this facility?');">
                                    <?php echo hms_csrf_field(); ?>
                                    <button type="submit" name="clean_demo_2yr" value="1" class="btn btn-outline-danger btn-block">Clean demo data</button>
                                </form>
                                <p class="small text-muted mb-0 mt-2">If the server returns HTTP 500, enable <code>?debug=1</code> on this URL after a failed POST to show the file and line (admin only).</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
