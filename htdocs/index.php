<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$msg = '';
if (isset($_POST['login'])) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $pwd = (string) ($_POST['pwd'] ?? '');

    if ($username === '' || $pwd === '') {
        $msg = 'Please enter username and password.';
    } else {
        $stmt = mysqli_prepare(
            $connection,
            'SELECT id, first_name, last_name, username, password, role FROM tbl_employee WHERE username = ? AND status = 1 LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $data = hms_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);

            if ($data && hms_verify_password($pwd, (string) $data['password'])) {
                hms_upgrade_legacy_password($connection, (int) $data['id'], $pwd, (string) $data['password']);
                $name = trim($data['first_name'] . ' ' . $data['last_name']);
                $_SESSION['name'] = $name;
                $_SESSION['role'] = (string) $data['role'];
                $_SESSION['user_id'] = (int) $data['id'];
                $_SESSION['facility_id'] = 0;
                hms_facility_set_default_for_user($connection, (int) $data['id']);
                hms_audit_log($connection, 'login', 'employee', (int) $data['id']);
                $dest = function_exists('hms_login_redirect_after_auth')
                    ? hms_login_redirect_after_auth(
                        $connection,
                        (int) $data['id'],
                        (string) $data['role']
                    )
                    : 'dashboard.php';
                header('Location: ' . $dest);
                exit;
            }
        }
        $msg = 'Incorrect login details.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <?php
    $hmsBase = function_exists('hms_html_base_href') ? hms_html_base_href() : null;
    if ($hmsBase !== null) {
        echo '<base href="' . hms_h($hmsBase) . '">' . "\n";
    }
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="TSSF Solidarity of Hearts Hospital SOA — secure staff portal.">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico">
    <title>Sign in — TSSF Solidarity of Hearts Hospital SOA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-staff-login.css">
</head>
<body class="hms-staff-login">
    <div class="hms-auth-shell">
        <aside class="hms-auth-aside" aria-hidden="false">
            <div class="hms-auth-aside-inner">
                <div class="hms-auth-badge">
                    <span class="fa fa-shield" aria-hidden="true"></span> Staff workspace
                </div>
                <h1>TSSF Solidarity of Hearts Hospital SOA</h1>
                <p class="hms-auth-lead">Hospital Management System — schedules, patients, and day-to-day operations for care teams.</p>
                <ul class="hms-auth-list">
                    <li><span class="fa fa-check-circle" aria-hidden="true"></span> Role-based access aligned with your organisation</li>
                    <li><span class="fa fa-check-circle" aria-hidden="true"></span> Multi-site aware when your database is configured for it</li>
                    <li><span class="fa fa-check-circle" aria-hidden="true"></span> Patients use a separate portal link—keeps sessions apart</li>
                </ul>
            </div>
        </aside>
        <main class="hms-auth-main">
            <div class="hms-auth-card">
                <form method="post" class="mb-0" autocomplete="on">
                    <div class="hms-auth-card-header">
                        <a href="index.php" title="Home"><img src="assets/img/logo-dark.png" alt="HMS"></a>
                        <h2>Welcome back</h2>
                        <p>Sign in with your staff username and password.</p>
                    </div>
                    <?php if ($msg !== '') { ?>
                    <div class="hms-auth-alert" role="alert"><?php echo hms_h($msg); ?></div>
                    <?php } ?>
                    <div class="hms-auth-field">
                        <label for="hms_username">Username</label>
                        <input id="hms_username" class="hms-auth-input" type="text" name="username" required autocomplete="username" autofocus>
                    </div>
                    <div class="hms-auth-field">
                        <div class="hms-auth-password-row">
                            <label for="hms_pwd">Password</label>
                            <button type="button" class="hms-auth-toggle-pw" id="hms_toggle_pw" aria-pressed="false">Show</button>
                        </div>
                        <input id="hms_pwd" class="hms-auth-input" type="password" name="pwd" required autocomplete="current-password">
                    </div>
                    <button type="submit" name="login" class="hms-auth-submit">Sign in</button>
                    <div class="hms-auth-meta">
                        <a href="patient-portal-login.php">Patient portal sign-in</a>
                        <span class="hms-auth-hint">Forgot your password? Your organisation’s administrator can reset it in the employee record.</span>
                    </div>
                </form>
            </div>
        </main>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('hms_toggle_pw');
        var input = document.getElementById('hms_pwd');
        if (!btn || !input) return;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    })();
    </script>
</body>
</html>
