<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hms_hr.php';
require_once __DIR__ . '/includes/hms_holiday_seed.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'employee.read');
if (!hms_hr_is_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$fid = hms_current_facility_id();
$hrOk = hms_hr_tables_ok($connection);
$msg = '';

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_holiday'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid token.';
    } else {
        $name = trim((string) ($_POST['holiday_name'] ?? ''));
        $date = (string) ($_POST['holiday_date'] ?? '');
        $rec = isset($_POST['is_recurring']) ? 1 : 0;
        if ($name !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_hms_holiday (facility_id, holiday_name, holiday_date, is_recurring) VALUES (?,?,?,?)'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'issi', $fid, $name, $date, $rec);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $msg = 'Holiday added.';
            }
        }
    }
}

if ($hrOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['seed_major_holidays'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid token.';
    } else {
        $seedYear = max(2000, min(2100, (int) ($_POST['seed_year'] ?? (int) date('Y'))));
        $seedStats = hms_holiday_seed_major_for_facility($connection, $fid, $seedYear);
        $msg = 'Holiday seed: ' . (int) $seedStats['inserted'] . ' added';
        if ((int) $seedStats['skipped'] > 0) {
            $msg .= ', ' . (int) $seedStats['skipped'] . ' skipped (name already exists for this site)';
        }
        $msg .= ' (' . $seedYear . ').';
        if (function_exists('hms_audit_log')) {
            hms_audit_log($connection, 'holiday.seed_major', 'facility', $fid, $seedStats + ['year' => $seedYear]);
        }
    }
}

if ($hrOk && isset($_GET['delete'])) {
    $did = (int) ($_GET['delete'] ?? 0);
    if ($did > 0) {
        $stmt = mysqli_prepare($connection, 'DELETE FROM tbl_hms_holiday WHERE id = ? AND facility_id = ? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $did, $fid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        header('Location: holidays.php');
        exit;
    }
}

$rows = null;
if ($hrOk) {
    $rows = mysqli_query(
        $connection,
        'SELECT * FROM tbl_hms_holiday WHERE facility_id = ' . (int) $fid . ' ORDER BY holiday_date'
    );
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content">
        <div class="container-fluid">
            <?php
            hms_ui_page_header('Holidays', [
                'subtitle' => 'Public or facility holidays for planning.',
                'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Holidays', null]],
            ]);
            ?>
            <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
            <?php if (!$hrOk) { ?>
            <div class="alert alert-warning">Run migration <code>040</code>.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <h2 class="h6 font-weight-bold mb-2">Seed major holidays</h2>
                    <p class="small text-muted mb-2">Adds common fixed-date observances (incl. Cameroon Youth Day, National Day) and movable Christian dates for the selected year when PHP <code>easter_date()</code> is available (Good Friday, Easter Monday, Ascension, Whit Monday). Skips any name already listed for this site.</p>
                    <form method="post" class="form-inline" onsubmit="return confirm('Add missing holidays for the selected year?');">
                        <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                        <input type="hidden" name="seed_major_holidays" value="1">
                        <label class="mr-2 small font-weight-bold">Year</label>
                        <input type="number" name="seed_year" class="form-control form-control-sm mr-2" style="width:100px" value="<?php echo (int) date('Y'); ?>" min="2000" max="2100">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fa fa-calendar-plus-o mr-1"></i>Seed major holidays</button>
                    </form>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header font-weight-bold">Add holiday</div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="hms_csrf" value="<?php echo hms_h(hms_csrf_token()); ?>">
                                <input type="hidden" name="add_holiday" value="1">
                                <div class="form-group"><input type="text" name="holiday_name" class="form-control" placeholder="Name" required></div>
                                <div class="form-group"><input type="date" name="holiday_date" class="form-control" required></div>
                                <div class="form-check mb-2"><input type="checkbox" name="is_recurring" class="form-check-input" id="rec"><label class="form-check-label" for="rec">Recurring yearly</label></div>
                                <button type="submit" class="btn btn-primary btn-sm">Add</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="table-responsive card border-0 shadow-sm">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr><th>Holiday</th><th>Date</th><th>Recurring</th><th></th></tr></thead>
                            <tbody>
                            <?php
                            if ($rows) {
                                while ($h = mysqli_fetch_assoc($rows)) {
                                    $hid = (int) ($h['id'] ?? 0);
                                    echo '<tr><td>' . hms_h((string) ($h['holiday_name'] ?? '')) . '</td><td>'
                                        . hms_h(date('d M Y', strtotime((string) ($h['holiday_date'] ?? '')))) . '</td><td>'
                                        . (((int) ($h['is_recurring'] ?? 0)) === 1 ? 'Yes' : 'No') . '</td><td>'
                                        . '<a href="?delete=' . $hid . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Delete?\');">Delete</a></td></tr>';
                                }
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
