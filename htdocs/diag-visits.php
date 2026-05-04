<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name']) || (string) ($_SESSION['role'] ?? '') !== '1') {
    http_response_code(403);
    exit('Admin login required.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);
$ok = hms_opd_tables_ready($connection);
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head><title>Visit Diagnostics</title></head>
<body style="font-family:monospace;padding:20px;background:#f9f9f9">
<h2>HMS Visit Diagnostics</h2>
<p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s T'); ?></p>
<hr>
<h3>Environment</h3>
<ul>
    <li>PHP version: <?php echo PHP_VERSION; ?></li>
    <li>Server time (Y-m-d): <?php echo $today; ?></li>
    <li>facility_id (session): <?php echo $fid; ?></li>
    <li>multi_site enabled: <?php echo $ms ? 'YES' : 'NO'; ?></li>
    <li>tbl_opd_visit exists: <?php echo $ok ? 'YES' : 'NO'; ?></li>
</ul>

<h3>All visits in database (no filters, facility_id=<?php echo $fid; ?>)</h3>
<?php
if ($ok) {
    $allQ = @mysqli_query($connection,
        "SELECT v.id, v.ticket_number, v.visit_date, v.queue_status, v.facility_id, v.patient_id,
                p.first_name, p.last_name
         FROM tbl_opd_visit v
         LEFT JOIN tbl_patient p ON p.id = v.patient_id
         WHERE v.facility_id = " . (int) $fid . "
         ORDER BY v.id DESC LIMIT 20"
    );
    if ($allQ && mysqli_num_rows($allQ) > 0) {
        echo '<table border="1" cellpadding="5" style="border-collapse:collapse">';
        echo '<tr><th>id</th><th>facility_id</th><th>ticket</th><th>visit_date</th><th>status</th><th>patient_id</th><th>patient_name</th><th>patient_in_join</th></tr>';
        while ($r = mysqli_fetch_assoc($allQ)) {
            $pname = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $joinOk = ($pname !== '' && $pname !== ' ') ? '<span style="color:green">YES</span>' : '<span style="color:red">NO — INNER JOIN would exclude this!</span>';
            echo '<tr>';
            echo '<td>' . $r['id'] . '</td>';
            echo '<td>' . $r['facility_id'] . '</td>';
            echo '<td>' . htmlspecialchars($r['ticket_number']) . '</td>';
            echo '<td>' . $r['visit_date'] . '</td>';
            echo '<td>' . htmlspecialchars($r['queue_status']) . '</td>';
            echo '<td>' . $r['patient_id'] . '</td>';
            echo '<td>' . htmlspecialchars($pname) . '</td>';
            echo '<td>' . $joinOk . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p style="color:red"><strong>NO VISITS FOUND for facility_id=' . $fid . '!</strong></p>';
    }

    // Also check ALL facilities
    $allFacQ = @mysqli_query($connection,
        "SELECT v.id, v.ticket_number, v.visit_date, v.facility_id FROM tbl_opd_visit v ORDER BY v.id DESC LIMIT 20"
    );
    echo '<h3>All visits across ALL facilities</h3>';
    if ($allFacQ && mysqli_num_rows($allFacQ) > 0) {
        echo '<table border="1" cellpadding="5" style="border-collapse:collapse">';
        echo '<tr><th>id</th><th>facility_id</th><th>ticket</th><th>visit_date</th></tr>';
        while ($r = mysqli_fetch_assoc($allFacQ)) {
            $highlight = ((int)$r['facility_id'] !== $fid) ? ' style="background:#ffcccc"' : '';
            echo '<tr' . $highlight . '>';
            echo '<td>' . $r['id'] . '</td>';
            echo '<td>' . $r['facility_id'] . '</td>';
            echo '<td>' . htmlspecialchars($r['ticket_number']) . '</td>';
            echo '<td>' . $r['visit_date'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p style="color:red">No visits in any facility!</p>';
    }

    // Check patient join
    echo '<h3>Patient JOIN test</h3>';
    $joinTestQ = @mysqli_query($connection,
        "SELECT v.id AS vid, v.patient_id, p.id AS pid
         FROM tbl_opd_visit v
         LEFT JOIN tbl_patient p ON p.id = v.patient_id
         WHERE v.facility_id = " . (int) $fid . "
         ORDER BY v.id DESC LIMIT 20"
    );
    $orphans = 0;
    if ($joinTestQ) {
        while ($r = mysqli_fetch_assoc($joinTestQ)) {
            if ($r['pid'] === null) {
                $orphans++;
                echo '<p style="color:red">Visit id=' . $r['vid'] . ' has patient_id=' . $r['patient_id'] . ' but NO matching row in tbl_patient!</p>';
            }
        }
    }
    if ($orphans === 0) {
        echo '<p style="color:green">All visits have valid patient JOIN matches.</p>';
    }

    // Run the exact same query as visits.php uses
    echo '<h3>Exact visits.php SELECT query test</h3>';
    $defaultFrom = date('Y-m-d', strtotime('-90 days'));
    $defaultTo = date('Y-m-d');
    $escFrom = mysqli_real_escape_string($connection, $defaultFrom);
    $escTo = mysqli_real_escape_string($connection, $defaultTo);
    $testWhere = "v.facility_id = " . (int)$fid . " AND v.visit_date >= '" . $escFrom . "' AND v.visit_date <= '" . $escTo . "'";
    $testSql = "SELECT COUNT(*) AS c FROM tbl_opd_visit v INNER JOIN tbl_patient p ON p.id = v.patient_id WHERE " . $testWhere;
    echo '<p>Query: <code>' . htmlspecialchars($testSql) . '</code></p>';
    $testQ = @mysqli_query($connection, $testSql);
    $testCount = 0;
    if ($testQ && $tr = mysqli_fetch_assoc($testQ)) {
        $testCount = (int) $tr['c'];
    }
    echo '<p><strong>Result: ' . $testCount . ' visits</strong></p>';

    // Raw count without join
    $rawSql = "SELECT COUNT(*) AS c FROM tbl_opd_visit v WHERE v.facility_id = " . (int)$fid;
    $rawQ = @mysqli_query($connection, $rawSql);
    $rawCount = 0;
    if ($rawQ && $rr = mysqli_fetch_assoc($rawQ)) {
        $rawCount = (int) $rr['c'];
    }
    echo '<p>Raw count (no JOIN, no date filter): <strong>' . $rawCount . '</strong> visits</p>';

    if ($rawCount !== $testCount) {
        echo '<p style="color:orange"><strong>COUNT mismatch! Some visits are outside the date range or have missing patient records.</strong></p>';
    }
} else {
    echo '<p style="color:red">tbl_opd_visit does not exist!</p>';
}
?>
<hr>
<p><a href="visits.php?nc=<?php echo time(); ?>">← Back to Visits (cache-busted)</a></p>
<p><small>Delete this file (diag-visits.php) after debugging.</small></p>
</body>
</html>
