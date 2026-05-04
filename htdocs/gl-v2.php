<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain');

$fid = (int)(defined('HMS_FIXED_FACILITY_ID') ? HMS_FIXED_FACILITY_ID : 1);
echo "Facility ID: $fid\n";

if (!isset($connection)) {
    echo "Connection not set\n";
    exit;
}

$db_q = mysqli_query($connection, 'SELECT DATABASE()');
$db_row = mysqli_fetch_row($db_q);
echo "DB: " . ($db_row[0] ?? 'N/A') . "\n";

// Step 1: insert facility row
$sql = "INSERT INTO tbl_facility (id, code, name, status) VALUES ($fid, 'MAIN', 'TSSF Solidarity of Hearts Hospital SOA', 1) ON DUPLICATE KEY UPDATE name=VALUES(name), status=1";
$r = mysqli_query($connection, $sql);
echo "Facility insert: " . ($r ? 'OK rows=' . mysqli_affected_rows($connection) : mysqli_error($connection)) . "\n";

// Counts
foreach (['tbl_facility','tbl_fin_journal_header','tbl_fin_journal_line'] as $t) {
    $q = mysqli_query($connection, "SELECT COUNT(*) AS n FROM $t");
    $row = $q ? mysqli_fetch_assoc($q) : [];
    echo "$t count: " . ($row['n'] ?? ('ERR: '.mysqli_error($connection))) . "\n";
}

// Facility-specific count
$q = mysqli_query($connection, "SELECT COUNT(*) AS n FROM tbl_fin_journal_header WHERE facility_id = $fid");
$row = $q ? mysqli_fetch_assoc($q) : [];
echo "journal_header facility=$fid: " . ($row['n'] ?? ('ERR: '.mysqli_error($connection))) . "\n";

echo "Done.\n";