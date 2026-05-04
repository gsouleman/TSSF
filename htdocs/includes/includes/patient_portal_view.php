<?php
declare(strict_types=1);

/**
 * Minimal chrome for patient-facing pages (no staff sidebar).
 *
 * @param array{title: string, show_nav?: bool, content_class?: string, nav_active?: string} $opts
 */
function hms_patient_portal_render_head(array $opts): void
{
    $title = (string) ($opts['title'] ?? 'Patient portal');
    $showNav = (bool) ($opts['show_nav'] ?? false);
    $navActive = (string) ($opts['nav_active'] ?? 'overview');
    $contentClass = trim((string) ($opts['content_class'] ?? 'container'));
    if ($contentClass === '') {
        $contentClass = 'container';
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
    <title><?php echo hms_h($title); ?></title>
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-modern.css">
    <link rel="stylesheet" type="text/css" href="assets/css/hms-forms.css">
    <style>
        .pp-topbar { background: #0f172a; color: #e2e8f0; padding: 0.65rem 0; margin-bottom: 1.5rem; }
        .pp-topbar a { color: #86efac; font-weight: 600; }
        .pp-topbar a:hover { color: #bbf7d0; text-decoration: none; }
        .pp-brand { font-weight: 700; color: #fff; letter-spacing: -0.02em; }
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        .pp-login-wrap {
            min-height: calc(100vh - 0px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.25rem 3rem;
            position: relative;
            overflow: hidden;
            background: linear-gradient(165deg, #ecfdf5 0%, #f8fafc 42%, #f1f5f9 100%);
        }
        .pp-login-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 85% 55% at 50% -15%, rgba(13, 148, 136, 0.2), transparent 55%),
                radial-gradient(ellipse 55% 45% at 100% 40%, rgba(37, 99, 235, 0.07), transparent 50%),
                radial-gradient(ellipse 45% 40% at 0% 85%, rgba(13, 148, 136, 0.06), transparent 45%);
            pointer-events: none;
        }
        .pp-login-inner {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
        }
        .pp-login-card {
            width: 100%;
            background: #fff;
            border-radius: 20px;
            box-shadow:
                0 1px 2px rgba(15, 23, 42, 0.04),
                0 24px 48px -12px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(15, 23, 42, 0.06);
            padding: 2.25rem 1.75rem 2rem;
        }
        @media (min-width: 480px) {
            .pp-login-card { padding: 2.5rem 2.25rem 2.25rem; }
        }
        .pp-login-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: linear-gradient(145deg, #0d9488 0%, #0f766e 45%, #2563eb 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.65rem;
            margin: 0 auto 1.25rem;
            box-shadow: 0 8px 24px rgba(13, 148, 136, 0.35);
        }
        .pp-login-kicker {
            text-align: center;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #0d9488;
            margin-bottom: 0.5rem;
        }
        .pp-login-title {
            font-weight: 700;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            text-align: center;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }
        .pp-login-sub {
            text-align: center;
            color: #64748b;
            font-size: 0.9375rem;
            line-height: 1.55;
            margin-bottom: 1.75rem;
            max-width: 340px;
            margin-left: auto;
            margin-right: auto;
        }
        .pp-login-form .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.4rem;
        }
        .pp-login-form .form-control {
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            padding: 0.65rem 0.9rem;
            font-size: 0.9375rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .pp-login-form .form-control:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
        }
        .pp-login-form .btn-success {
            border-radius: 10px;
            font-weight: 600;
            padding: 0.7rem 1rem;
            font-size: 1rem;
            letter-spacing: 0.01em;
            background: linear-gradient(180deg, #14b8a6 0%, #0d9488 100%);
            border: none;
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.35);
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }
        .pp-login-form .btn-success:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.4);
        }
        .pp-login-form .btn-success:active { transform: translateY(0); }
        .pp-login-foot {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f1f5f9;
            font-size: 0.8125rem;
            color: #94a3b8;
            line-height: 1.5;
        }
        .pp-login-foot i { color: #0d9488; margin-right: 0.25rem; }
        .pp-topbar a.pp-nav-active { color: #fff !important; text-decoration: underline; font-weight: 700; }
    </style>
</head>
<body>
    <?php if ($showNav) { ?>
    <div class="pp-topbar">
        <div class="container d-flex flex-wrap align-items-center justify-content-between">
            <span class="pp-brand"><i class="fa fa-heartbeat text-success mr-2"></i> Patient portal</span>
            <div>
                <a href="patient-portal.php" class="mr-3<?php echo $navActive === 'overview' ? ' pp-nav-active' : ''; ?>"><i class="fa fa-home"></i> Overview</a>
                <a href="patient-portal-book.php" class="mr-3<?php echo $navActive === 'book' ? ' pp-nav-active' : ''; ?>"><i class="fa fa-calendar-plus-o"></i> Book visit</a>
                <a href="patient-portal-logout.php"><i class="fa fa-sign-out"></i> Sign out</a>
            </div>
        </div>
    </div>
    <?php } ?>
    <div class="<?php echo hms_h($contentClass); ?><?php echo $showNav ? ' pb-5' : ''; ?>">
    <?php
}

function hms_patient_portal_render_foot(): void
{
    ?>
    </div>
    <script src="assets/js/jquery-3.2.1.min.js"></script>
    <script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
</body>
</html>
    <?php
}

/**
 * @return list<array<string, string>>
 */
function hms_patient_portal_fetch_appointments(mysqli $connection, array $patient): array
{
    $pid = (int) ($patient['id'] ?? 0);
    $name = trim((string) ($patient['first_name'] ?? '') . ' ' . (string) ($patient['last_name'] ?? ''));
    $parts = [];
    if ($name !== '') {
        $like = mysqli_real_escape_string($connection, $name . ',%');
        $parts[] = "patient_name LIKE '" . $like . "'";
    }
    if (hms_db_column_exists($connection, 'tbl_appointment', 'patient_id')) {
        $parts[] = 'patient_id = ' . $pid;
    }
    if ($parts === []) {
        return [];
    }
    $where = '(' . implode(' OR ', $parts) . ')';
    if (hms_db_column_exists($connection, 'tbl_appointment', 'facility_id')
        && hms_db_column_exists($connection, 'tbl_patient', 'facility_id')) {
        $where .= ' AND facility_id = ' . (int) ($patient['facility_id'] ?? 1);
    }
    $sql = 'SELECT appointment_id, department, doctor, date, time, message, status FROM tbl_appointment WHERE '
        . $where . ' ORDER BY id DESC LIMIT 50';
    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}
