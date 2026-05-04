<?php
/** No strict_types: shared-host compatibility (mysqli + hms_h). */

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_fin_require_mysqli($connection);
hms_fin_require($connection, 'financials.write');
if (!hms_financials_ready($connection)) {
    header('Location: financials.php');
    exit;
}
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (isset($_POST['add_cc'])) {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', hms_fin_post_string_scalar($_POST['new_code'] ?? null, '')));
        $label = trim(hms_fin_post_string_scalar($_POST['new_label'] ?? null, ''));
        if ($code === '' || strlen($code) > 24) {
            $err = 'Cost centre code is required (letters, digits, hyphen; max 24).';
        } elseif ($label === '') {
            $err = 'English label is required.';
        } else {
            $stmt = mysqli_prepare($connection, 'INSERT INTO tbl_fin_cost_center (code, label_en, sort_order) VALUES (?,?,?)');
            $sort = 100;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssi', $code, $label, $sort);
                if (mysqli_stmt_execute($stmt)) {
                    $msg = 'Cost centre added.';
                } else {
                    $err = 'Could not add (code may already exist).';
                }
                mysqli_stmt_close($stmt);
            }
        }
    } elseif (isset($_POST['save_cc'])) {
        $cid = hms_fin_post_int_scalar($_POST['cc_id'] ?? null, 0);
        $label = trim(hms_fin_post_string_scalar($_POST['label_en'] ?? null, ''));
        $sort = hms_fin_post_int_scalar($_POST['sort_order'] ?? null, 0);
        $act = isset($_POST['active']) ? 1 : 0;
        if ($cid < 1 || $label === '') {
            $err = 'Invalid data.';
        } else {
            $u = mysqli_prepare($connection, 'UPDATE tbl_fin_cost_center SET label_en = ?, sort_order = ?, active = ? WHERE id = ?');
            if ($u) {
                mysqli_stmt_bind_param($u, 'siii', $label, $sort, $act, $cid);
                mysqli_stmt_execute($u);
                mysqli_stmt_close($u);
                $msg = 'Cost centre updated.';
            }
        }
    }
}

$list = [];
$q = mysqli_query($connection, 'SELECT * FROM tbl_fin_cost_center ORDER BY sort_order ASC, code ASC');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        $list[] = $r;
    }
    mysqli_free_result($q);
}
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Cost centres', [
                    'subtitle' => 'English codes and labels for departmental allocation (OHADA cost reporting).',
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Cost centres', null]],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
                <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white font-weight-bold">Add cost centre</div>
                    <div class="card-body">
                        <form method="post" class="form-row align-items-end">
                            <?php echo hms_csrf_field(); ?>
                            <div class="col-md-3">
                                <label class="small font-weight-bold">Code</label>
                                <input name="new_code" class="form-control" maxlength="24" placeholder="e.g. CC-ICU">
                            </div>
                            <div class="col-md-6">
                                <label class="small font-weight-bold">Label (English)</label>
                                <input name="new_label" class="form-control" maxlength="180" placeholder="Intensive care unit">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="add_cc" value="1" class="btn btn-primary btn-sm font-weight-bold">Add</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <?php foreach ($list as $r) {
                            $cid = (int) ($r['id'] ?? 0); ?>
                        <form method="post" class="border-bottom pb-3 mb-3">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="cc_id" value="<?php echo $cid; ?>">
                            <div class="form-row align-items-end">
                                <div class="col-md-2">
                                    <label class="small text-muted">Code</label>
                                    <div><code class="font-weight-bold"><?php echo hms_h((string) ($r['code'] ?? '')); ?></code></div>
                                </div>
                                <div class="col-md-5">
                                    <label class="small text-muted">Label (English)</label>
                                    <input class="form-control form-control-sm" name="label_en" value="<?php echo hms_h((string) ($r['label_en'] ?? '')); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label class="small text-muted">Sort</label>
                                    <input type="number" class="form-control form-control-sm" name="sort_order" value="<?php echo (int) ($r['sort_order'] ?? 0); ?>">
                                </div>
                                <div class="col-md-2">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" name="active" value="1" id="act<?php echo $cid; ?>" <?php echo ((int) ($r['active'] ?? 0)) === 1 ? 'checked' : ''; ?>>
                                        <label class="form-check-label small" for="act<?php echo $cid; ?>">Active</label>
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <button type="submit" name="save_cc" value="1" class="btn btn-sm btn-outline-primary">Save</button>
                                </div>
                            </div>
                        </form>
                        <?php } ?>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Codes are stored upper-case. Use English for all labels to keep management reporting consistent.</p>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
