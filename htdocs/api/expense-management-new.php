<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!function_exists('hms_expenses_can_write') || !hms_expenses_can_write($connection)) {
    http_response_code(403);
    exit('Forbidden.');
}

$fid = hms_current_facility_id();
$tableOk = function_exists('hms_expenses_ready') && hms_expenses_ready($connection);
$msg = '';
$uid = (int) ($_SESSION['user_id'] ?? 0);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_expense'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
        $ed = trim((string) ($_POST['expense_date'] ?? ''));
        $cat = trim((string) ($_POST['category'] ?? ''));
        if (strlen($cat) > 120) {
            $cat = function_exists('mb_substr') ? mb_substr($cat, 0, 120, 'UTF-8') : substr($cat, 0, 120);
            $cat = trim($cat);
        }
        $desc = trim((string) ($_POST['description'] ?? ''));
        $amtRaw = (string) ($_POST['amount_xaf'] ?? '0');
        $amt = (int) preg_replace('/[^\d]/', '', $amtRaw);
        $pay = trim((string) ($_POST['payment_method'] ?? ''));
        $ref = trim((string) ($_POST['reference'] ?? ''));
        $ven = trim((string) ($_POST['vendor'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($ed === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
            $msg = 'Please enter a valid expense date.';
        } elseif ($cat === '') {
            $msg = 'Category is required.';
        } elseif ($amt < 1) {
            $msg = 'Amount must be at least 1 FCFA.';
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_expense (facility_id, expense_date, category, description, amount_xaf, payment_method, reference, vendor, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param(
                    $st,
                    'isssissssi',
                    $fid,
                    $ed,
                    $cat,
                    $desc,
                    $amt,
                    $pay,
                    $ref,
                    $ven,
                    $notes,
                    $uid
                );
                if (mysqli_stmt_execute($st)) {
                    $newExpId = (int) mysqli_insert_id($connection);
                    hms_audit_log($connection, 'expense.create', 'expense', $newExpId);
                    if (function_exists('hms_fin_post_expense_to_gl')) {
                        hms_fin_post_expense_to_gl(
                            $connection,
                            $fid,
                            $newExpId,
                            $ed,
                            $amt,
                            $pay !== '' ? $pay : null,
                            $cat,
                            $desc,
                            $uid
                        );
                    }
                    header('Location: expense-management.php');
                    exit;
                }
                $msg = 'Could not save expense.';
                mysqli_stmt_close($st);
            } else {
                $msg = 'Database error.';
            }
        }
    }
}

$formCategory = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_expense'])) {
    $formCategory = trim((string) ($_POST['category'] ?? ''));
    if (strlen($formCategory) > 120) {
        $formCategory = function_exists('mb_substr') ? mb_substr($formCategory, 0, 120, 'UTF-8') : substr($formCategory, 0, 120);
        $formCategory = trim($formCategory);
    }
}

$categoryChoices = $tableOk ? hms_expense_category_choices($connection, $fid) : [];
$categoryInList = false;
if ($formCategory !== '') {
    foreach ($categoryChoices as $c) {
        if (strcasecmp($formCategory, $c) === 0) {
            $categoryInList = true;
            break;
        }
    }
}

include 'header.php';
$today = date('Y-m-d');
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('New expense', [
                'subtitle' => 'Record an operating expense for this site.',
                'breadcrumbs' => [['Accounting', null], ['Expenses', 'expense-management.php'], ['New', '']],
                'back' => 'expense-management.php',
            ]);
            ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">
                Run <code>hms/database/migrations/026_expense_management.sql</code> first, then return here.
            </div>
            <?php } else { ?>
            <?php if ($msg !== '') { ?><div class="alert alert-danger"><?php echo hms_h($msg); ?></div><?php } ?>
            <div class="card border-0 shadow-sm hms-form-card">
                <div class="card-body">
                    <form method="post">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="save_expense" value="1">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="expense_date">Date <span class="text-danger">*</span></label>
                                <input class="form-control" type="date" id="expense_date" name="expense_date" value="<?php echo hms_h($today); ?>" required>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="hms_exp_cat_sel">Category <span class="text-danger">*</span></label>
                                <select class="form-control" id="hms_exp_cat_sel" autocomplete="off" aria-describedby="hms_exp_cat_help">
                                    <option value="">— Select category —</option>
                                    <?php foreach ($categoryChoices as $c) {
                                        $sel = ($formCategory !== '' && strcasecmp($formCategory, $c) === 0) ? ' selected' : '';
                                        ?>
                                    <option value="<?php echo hms_h($c); ?>"<?php echo $sel; ?>><?php echo hms_h($c); ?></option>
                                    <?php } ?>
                                    <option value="__new__"<?php echo ($formCategory !== '' && !$categoryInList) ? ' selected' : ''; ?>>+ Add new category…</option>
                                </select>
                                <p class="form-text text-muted small mb-0 mt-1" id="hms_exp_cat_help">Pick an existing category or add a new one.</p>
                                <div id="hms_exp_cat_new_wrap" class="mt-2" style="display: <?php echo ($formCategory !== '' && !$categoryInList) ? 'block' : 'none'; ?>;">
                                    <label for="hms_exp_cat_new" class="small text-muted mb-1 d-block">New category name</label>
                                    <input type="text" class="form-control" id="hms_exp_cat_new" maxlength="120" placeholder="e.g. Equipment rental" value="<?php echo hms_h($formCategory !== '' && !$categoryInList ? $formCategory : ''); ?>" autocomplete="off">
                                </div>
                                <input type="hidden" name="category" id="hms_exp_cat_final" value="<?php echo hms_h($formCategory); ?>">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="amount_xaf">Amount (FCFA) <span class="text-danger">*</span></label>
                                <input class="form-control" id="amount_xaf" name="amount_xaf" inputmode="numeric" placeholder="e.g. 50000" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <input class="form-control" id="description" name="description" maxlength="512" placeholder="Short description">
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label for="payment_method">Payment method</label>
                                <select class="form-control" id="payment_method" name="payment_method">
                                    <option value="">—</option>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank transfer</option>
                                    <option value="mobile_money">Mobile money</option>
                                    <option value="card">Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="vendor">Vendor / payee</label>
                                <input class="form-control" id="vendor" name="vendor" maxlength="200">
                            </div>
                            <div class="form-group col-md-4">
                                <label for="reference">Reference / receipt #</label>
                                <input class="form-control" id="reference" name="reference" maxlength="120">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save expense</button>
                        <a class="btn btn-outline-secondary ml-2" href="expense-management.php">Cancel</a>
                    </form>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php if ($tableOk) { ?>
<script>
(function () {
    var form = document.querySelector('.hms-form-card form[method="post"]');
    var sel = document.getElementById('hms_exp_cat_sel');
    var wrap = document.getElementById('hms_exp_cat_new_wrap');
    var inp = document.getElementById('hms_exp_cat_new');
    var fin = document.getElementById('hms_exp_cat_final');
    if (!form || !sel || !fin) {
        return;
    }
    function sync() {
        if (sel.value === '__new__') {
            if (wrap) {
                wrap.style.display = 'block';
            }
            fin.value = inp ? inp.value.trim() : '';
        } else if (sel.value === '') {
            if (wrap) {
                wrap.style.display = 'none';
            }
            fin.value = '';
        } else {
            if (wrap) {
                wrap.style.display = 'none';
            }
            fin.value = sel.value;
        }
    }
    sel.addEventListener('change', sync);
    if (inp) {
        inp.addEventListener('input', function () {
            if (sel.value === '__new__') {
                fin.value = inp.value.trim();
            }
        });
    }
    form.addEventListener('submit', function (e) {
        sync();
        if (!fin.value) {
            e.preventDefault();
            alert('Please select a category or enter a new category name.');
        }
    });
    sync();
})();
</script>
<?php } ?>
<?php include 'footer.php'; ?>
