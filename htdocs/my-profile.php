<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid < 1) {
    header('Location: index.php');
    exit;
}

$stmt = mysqli_prepare(
    $connection,
    'SELECT id, first_name, last_name, username, emailid, phone, password FROM tbl_employee WHERE id = ? LIMIT 1'
);
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$row = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row) {
    header('Location: index.php');
    exit;
}

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_profile'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $first = trim((string) ($_POST['first_name'] ?? ''));
        $last = trim((string) ($_POST['last_name'] ?? ''));
        $email = trim((string) ($_POST['emailid'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $pwdInput = trim((string) ($_POST['pwd'] ?? ''));

        if ($first === '' || $last === '' || $email === '') {
            $err = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Please enter a valid email address.';
        } else {
            $passToStore = (string) ($row['password'] ?? '');
            if ($pwdInput !== '') {
                $passToStore = hms_hash_password($pwdInput);
            }
            $upd = mysqli_prepare(
                $connection,
                'UPDATE tbl_employee SET first_name=?, last_name=?, emailid=?, phone=?, password=? WHERE id=?'
            );
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'sssssi', $first, $last, $email, $phone, $passToStore, $uid);
                if (mysqli_stmt_execute($upd)) {
                    $_SESSION['name'] = trim($first . ' ' . $last);
                    $msg = 'Profile updated.';
                    $row['first_name'] = $first;
                    $row['last_name'] = $last;
                    $row['emailid'] = $email;
                    $row['phone'] = $phone;
                    hms_audit_log($connection, 'profile.update', 'employee', $uid, null);
                } else {
                    $err = 'Could not save profile.';
                }
                mysqli_stmt_close($upd);
            } else {
                $err = 'Could not save profile.';
            }
        }
    }
}

include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('My profile', [
                    'subtitle' => 'Update your contact details and password. Username is set by an administrator.',
                    'breadcrumbs' => [['My profile', null]],
                ]);
                ?>
                <?php if ($err !== '') { ?>
                <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
                <?php } elseif ($msg !== '') { ?>
                <div class="alert alert-success"><?php echo hms_h($msg); ?></div>
                <?php } ?>

                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <form method="post" class="card border-0 shadow-sm hms-form-card">
                            <?php echo hms_csrf_field(); ?>
                            <div class="card-body">
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Account</h2>
                                    <div class="form-group">
                                        <label>Username</label>
                                        <input class="form-control" type="text" value="<?php echo hms_h((string) ($row['username'] ?? '')); ?>" disabled>
                                        <small class="text-muted">Contact Access Control if you need your username changed.</small>
                                    </div>
                                </div>
                                <div class="hms-form-section">
                                    <h2 class="hms-form-section-title">Profile</h2>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="mp_fn">First name <span class="hms-required">*</span></label>
                                                <input id="mp_fn" class="form-control" type="text" name="first_name" required value="<?php echo hms_h((string) ($row['first_name'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="mp_ln">Last name <span class="hms-required">*</span></label>
                                                <input id="mp_ln" class="form-control" type="text" name="last_name" required value="<?php echo hms_h((string) ($row['last_name'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="mp_em">Email <span class="hms-required">*</span></label>
                                                <input id="mp_em" class="form-control" type="email" name="emailid" required value="<?php echo hms_h((string) ($row['emailid'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="mp_ph">Phone</label>
                                                <input id="mp_ph" class="form-control" type="text" name="phone" value="<?php echo hms_h((string) ($row['phone'] ?? '')); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="mp_pw">New password</label>
                                                <input id="mp_pw" class="form-control" type="password" name="pwd" value="" placeholder="Leave blank to keep current" autocomplete="new-password">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="submit" name="save_profile" value="1" class="btn btn-primary font-weight-bold">Save changes</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
<?php include 'footer.php'; ?>
