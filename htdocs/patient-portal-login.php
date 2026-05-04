<?php

declare(strict_types=1);



require_once __DIR__ . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/patient_portal_view.php';



if (hms_patient_portal_patient_id() > 0) {

    header('Location: patient-portal.php');

    exit;

}



$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['patient_portal_login'])) {

    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {

        $err = 'Invalid security token. Please try again.';

    } else {

        $err = hms_patient_portal_attempt_login(

            $connection,

            (string) ($_POST['email'] ?? ''),

            (string) ($_POST['password'] ?? '')

        );

        if ($err === '') {

            header('Location: patient-portal.php');

            exit;

        }

    }

}



hms_patient_portal_render_head([

    'title' => 'Patient portal — Sign in',

    'show_nav' => false,

    'content_class' => 'container-fluid px-0',

]);

?>

        <div class="pp-login-wrap">

            <div class="pp-login-inner">

                <div class="pp-login-card">

                    <div class="text-center mb-1">

                        <div class="pp-login-icon" aria-hidden="true">

                            <i class="fa fa-heartbeat"></i>

                        </div>

                        <p class="pp-login-kicker mb-0">Secure access</p>

                        <h1 class="pp-login-title">Patient portal</h1>

                        <p class="pp-login-sub mb-0">Sign in with the email we have on file and the portal password your clinic provided.</p>

                    </div>

                    <?php if ($err !== '') { ?>

                    <div class="alert alert-danger border-0 rounded-lg shadow-sm"><?php echo hms_h($err); ?></div>

                    <?php } ?>

                    <?php if (!hms_patient_portal_ready($connection)) { ?>

                    <div class="alert alert-warning small mb-0 rounded-lg">

                        This site has not finished database setup for the patient portal. Please ask the administrator to run

                        <code>002_patient_portal.sql</code> from the project migrations folder.

                    </div>

                    <?php } else { ?>

                    <form method="post" class="pp-login-form mb-0">

                        <?php echo hms_csrf_field(); ?>

                        <div class="form-group">

                            <label class="form-label" for="email">Email address</label>

                            <input id="email" class="form-control" type="email" name="email" required autocomplete="email" autofocus placeholder="you@example.com">

                        </div>

                        <div class="form-group">

                            <label class="form-label" for="password">Portal password</label>

                            <input id="password" class="form-control" type="password" name="password" required autocomplete="current-password" placeholder="Enter your portal password">

                        </div>

                        <button type="submit" name="patient_portal_login" class="btn btn-success btn-block py-2 mt-1">Sign in</button>

                    </form>

                    <?php } ?>

                    <p class="pp-login-foot mb-0">

                        <i class="fa fa-lock" aria-hidden="true"></i>

                        For account help or a new password, contact your clinic. Do not use this portal for emergencies.

                    </p>

                </div>

            </div>

        </div>

<?php

hms_patient_portal_render_foot();

