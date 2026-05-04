<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/patient_portal_view.php';

// This portal uses the PATIENT session (not staff)
$pid = hms_patient_portal_patient_id();
if ($pid < 1) {
    // Not logged in as patient → show the patient login
    header('Location: patient-portal-login.php');
    exit;
}

if (!hms_patient_portal_ready($connection)) {
    hms_patient_portal_logout();
    header('Location: patient-portal-login.php');
    exit;
}

$stmt = mysqli_prepare($connection, 'SELECT * FROM tbl_patient WHERE id = ? AND status = 1 LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $pid);
mysqli_stmt_execute($stmt);
$patient = hms_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$patient || (int)($patient['portal_enabled'] ?? 0) !== 1) {
    hms_patient_portal_logout();
    header('Location: patient-portal-login.php');
    exit;
}

$appointments = hms_patient_portal_fetch_appointments($connection, $patient);
$fullName = trim((string)$patient['first_name'].' '.(string)$patient['last_name']);
$age = hms_patient_age_years_from_dob((string)($patient['dob'] ?? ''));

// Patient's lab results
$labOk = hms_db_table_exists($connection, 'tbl_lab_result');
$qLabs = false;
if ($labOk) {
    $qLabs = mysqli_query($connection, "SELECT test_name, status, created_at FROM tbl_lab_result WHERE patient_id=".(int)$pid." ORDER BY id DESC LIMIT 8");
}

// Patient's prescriptions
$rxOk  = hms_db_table_exists($connection, 'tbl_prescription');
$qRx   = false;
if ($rxOk) {
    $qRx = mysqli_query($connection, "SELECT title, status, created_at FROM tbl_prescription WHERE patient_id=".(int)$pid." ORDER BY id DESC LIMIT 6");
}

hms_patient_portal_render_head(['title' => 'My Health — Patient Portal', 'show_nav' => true]);
?>
<div style="max-width:1100px;margin:0 auto;padding:24px 16px;">

    <!-- Welcome Banner -->
    <div style="background:linear-gradient(135deg,#1a6bd8 0%,#0c8b8b 100%);border-radius:16px;padding:28px 32px;color:#fff;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;margin:0 0 6px;color:#fff;">Hello, <?php echo hms_h((string)$patient['first_name']); ?> 👋</h1>
            <p style="margin:0;opacity:.85;font-size:.95rem;">Your personal health portal — <?php echo date('l, d F Y'); ?></p>
        </div>
        <div>
            <a href="patient-portal-logout.php" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;">Sign Out</a>
        </div>
    </div>

    <!-- Profile + Stats row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:28px;">
        <!-- Profile Card -->
        <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 6px rgba(0,0,0,.08);grid-column:span 1;">
            <h2 style="font-size:.7rem;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;margin-bottom:16px;">Your Details</h2>
            <div style="display:grid;gap:10px;">
                <?php
                $fields = [
                    ['Name',   $fullName],
                    ['Email',  (string)($patient['email'] ?? '')],
                    ['Phone',  (string)($patient['phone'] ?? '')],
                    ['Type',   (string)($patient['patient_type'] ?? '')],
                    ['Age',    $age !== null ? $age.' years' : '—'],
                    ['Gender', hms_patient_gender_label((string)($patient['gender'] ?? ''))],
                ];
                foreach ($fields as $hmsFv) {
                    $k = $hmsFv[0];
                    $v = $hmsFv[1];
                    echo '<div style="display:flex;flex-direction:column;"><span style="font-size:.72rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.06em;">'.hms_h($k).'</span><span style="font-weight:600;color:#1e293b;font-size:.95rem;">'.hms_h($v ?: '—').'</span></div>';
                }
                ?>
            </div>
        </div>

        <!-- Quick Info Cards -->
        <?php
        $apptCount = count($appointments);
        $labCount  = 0;
        if ($qLabs) { $rows=[]; while($r=mysqli_fetch_assoc($qLabs)) $rows[]=$r; $labCount=count($rows); mysqli_data_seek($qLabs,0); }
        $quickStats = [
            ['My Appointments',   $apptCount, 'fa-calendar-check-o','#1a6bd8','#dbeafe'],
            ['Lab Results',       $labCount,  'fa-flask',           '#8b5cf6','#ede9fe'],
        ];
        foreach ($quickStats as $hmsQs) {
            $l = $hmsQs[0];
            $v = $hmsQs[1];
            $ic = $hmsQs[2];
            $col = $hmsQs[3];
            $bg = $hmsQs[4];
            ?>
        <div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 6px rgba(0,0,0,.08);display:flex;align-items:center;gap:16px;border-left:4px solid <?php echo $col; ?>;">
            <div style="width:50px;height:50px;border-radius:50%;background:<?php echo $bg; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa <?php echo $ic; ?>" style="color:<?php echo $col; ?>;font-size:1.3rem;"></i>
            </div>
            <div>
                <div style="font-size:2rem;font-weight:800;color:#1e293b;line-height:1;"><?php echo $v; ?></div>
                <div style="font-size:.82rem;color:#64748b;"><?php echo hms_h($l); ?></div>
            </div>
        </div>
        <?php } ?>
    </div>

    <!-- Appointments -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.08);margin-bottom:24px;overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:2px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
            <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;"><i class="fa fa-calendar mr-2" style="color:#1a6bd8;"></i> My Appointments</h2>
        </div>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Ref</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Department</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Doctor</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Date</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Time</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Status</th>
            </tr></thead>
            <tbody>
            <?php if (empty($appointments)) {
                echo '<tr><td colspan="6" style="padding:32px;text-align:center;color:#94a3b8;">No appointments on record yet.</td></tr>';
            } else {
                foreach ($appointments as $a) {
                    $isActive = (int)($a['status'] ?? 0) === 1;
                    $badge = $isActive
                        ? '<span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">Active</span>'
                        : '<span style="background:#f1f5f9;color:#64748b;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">Inactive</span>';
                    echo '<tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:12px 16px;font-size:.9rem;">'.hms_h((string)$a['appointment_id']).'</td>
                        <td style="padding:12px 16px;font-size:.9rem;">'.hms_h((string)$a['department']).'</td>
                        <td style="padding:12px 16px;font-size:.9rem;">'.hms_h((string)$a['doctor']).'</td>
                        <td style="padding:12px 16px;font-size:.9rem;color:#64748b;">'.hms_h((string)$a['date']).'</td>
                        <td style="padding:12px 16px;font-size:.9rem;color:#64748b;">'.hms_h((string)$a['time']).'</td>
                        <td style="padding:12px 16px;">'.$badge.'</td>
                    </tr>';
                }
            } ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if ($labOk && $qLabs) { ?>
    <!-- Lab Results -->
    <div style="background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.08);margin-bottom:24px;overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:2px solid #f1f5f9;">
            <h2 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;"><i class="fa fa-flask mr-2" style="color:#8b5cf6;"></i> My Lab Results</h2>
        </div>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Test</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Status</th>
                <th style="padding:10px 16px;text-align:left;font-size:.75rem;text-transform:uppercase;color:#64748b;font-weight:600;">Date</th>
            </tr></thead>
            <tbody>
            <?php
            $cnt = 0;
            $stMap = ['pending'=>['#fef3c7','#92400e','Pending'],'in_progress'=>['#dbeafe','#1e40af','In Progress'],'received'=>['#d1fae5','#065f46','Completed']];
            if(is_resource($qLabs)||$qLabs) mysqli_data_seek($qLabs,0);
            while ($qLabs && $row = mysqli_fetch_assoc($qLabs)) {
                $cnt++;
                $st = $row['status'] ?? 'pending';
                [$bg,$tc,$lbl] = $stMap[$st] ?? ['#f1f5f9','#64748b',ucfirst($st)];
                echo '<tr style="border-top:1px solid #f1f5f9;">
                    <td style="padding:12px 16px;font-weight:600;">'.hms_h((string)$row['test_name']).'</td>
                    <td style="padding:12px 16px;"><span style="background:'.$bg.';color:'.$tc.';padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;">'.$lbl.'</span></td>
                    <td style="padding:12px 16px;font-size:.9rem;color:#64748b;">'.hms_h(date('d M Y',strtotime((string)$row['created_at']))).'</td>
                </tr>';
            }
            if($cnt===0) echo '<tr><td colspan="3" style="padding:24px;text-align:center;color:#94a3b8;">No lab results found.</td></tr>';
            ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php } ?>

    <!-- Help footer -->
    <p style="text-align:center;color:#94a3b8;font-size:.85rem;margin-top:16px;">
        For urgent medical matters, please contact your care team directly. &nbsp;|&nbsp;
        <a href="patient-portal-logout.php" style="color:#1a6bd8;">Sign out</a>
    </p>
</div>
<?php hms_patient_portal_render_foot(); ?>
