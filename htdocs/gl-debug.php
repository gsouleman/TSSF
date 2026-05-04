<?php
// GL Debug — reveals actual DB connection details and journal counts
// DELETE after use.
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }

header('Content-Type: text/plain; charset=utf-8');

// Show which DB the app is actually connected to
$dbResult = @mysqli_query($connection, 'SELECT DATABASE() AS db, USER() AS u, @@hostname AS h');
$dbRow = $dbResult ? mysqli_fetch_assoc($dbResult) : [];
echo "=== DB Connection ===\n";
echo "Database: " . ($dbRow['db'] ?? '?') . "\n";
echo "User: " . ($dbRow['u'] ?? '?') . "\n";
echo "Host: " . ($dbRow['h'] ?? '?') . "\n";
echo "HMS_DB_HOST: " . (defined('HMS_DB_HOST') ? HMS_DB_HOST : '(not defined)') . "\n";
echo "HMS_DB_NAME: " . (defined('HMS_DB_NAME') ? HMS_DB_NAME : '(not defined)') . "\n";
echo "HMS_FIXED_FACILITY_ID: " . (defined('HMS_FIXED_FACILITY_ID') ? HMS_FIXED_FACILITY_ID : '(not defined)') . "\n";
echo "facility_id (session): " . hms_current_facility_id() . "\n";
echo "\n";

// Show all relevant table counts
$tables = [
    'tbl_facility' => 'SELECT COUNT(*) FROM tbl_facility WHERE id = 1',
    'tbl_fin_journal_header (all)' => 'SELECT COUNT(*) FROM tbl_fin_journal_header',
    'tbl_fin_journal_header (fac=1)' => 'SELECT COUNT(*) FROM tbl_fin_journal_header WHERE facility_id = 1',
    'tbl_fin_journal_line (all)' => 'SELECT COUNT(*) FROM tbl_fin_journal_line',
    'tbl_transaction' => 'SELECT COUNT(*) FROM tbl_transaction',
    'tbl_expense' => 'SELECT COUNT(*) FROM tbl_expense WHERE facility_id = 1',
    'tbl_billing_document' => 'SELECT COUNT(*) FROM tbl_billing_document',
    'tbl_fin_account' => 'SELECT COUNT(*) FROM tbl_fin_account',
];
echo "=== Table Counts ===\n";
foreach ($tables as $label => $sql) {
    $q = @mysqli_query($connection, $sql);
    if (!$q) { echo "$label: ERROR — " . mysqli_error($connection) . "\n"; continue; }
    $r = mysqli_fetch_assoc($q);
    echo "$label: " . reset($r) . "\n";
    mysqli_free_result($q);
}
echo "\n";

// Show journal distribution
echo "=== Journal Distribution by Facility ===\n";
$q = @mysqli_query($connection, 'SELECT facility_id, COUNT(*) AS n, MIN(entry_date) AS mn, MAX(entry_date) AS mx FROM tbl_fin_journal_header GROUP BY facility_id ORDER BY facility_id');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        echo "facility_id={$r['facility_id']}: {$r['n']} headers, {$r['mn']} – {$r['mx']}\n";
    }
    mysqli_free_result($q);
} else {
    echo "ERROR: " . mysqli_error($connection) . "\n";
}
echo "\n";

// Show journal line account_code distribution
echo "=== Journal Lines by account_code ===\n";
$q = @mysqli_query($connection, 'SELECT account_code, COUNT(*) AS n, SUM(debit) AS dr, SUM(credit) AS cr FROM tbl_fin_journal_line GROUP BY account_code ORDER BY account_code LIMIT 20');
if ($q) {
    while ($r = mysqli_fetch_assoc($q)) {
        echo "code={$r['account_code']}: {$r['n']} lines, DR={$r['dr']}, CR={$r['cr']}\n";
    }
    mysqli_free_result($q);
} else {
    echo "ERROR: " . mysqli_error($connection) . "\n";
}
echo "\n";

// Test the actual SQL used by the trial balance
echo "=== Trial Balance Query Test ===\n";
$fid = hms_current_facility_id();
require_once __DIR__ . '/includes/financials_reports_data.php';
$f = hms_fin_jl_report_sql_fragments($connection);
echo "SQL join: " . $f['join'] . "\n";
echo "SQL code: " . $f['code'] . "\n";
echo "SQL label: " . $f['label'] . "\n\n";

$sql = 'SELECT (' . $f['code'] . ') AS c, MAX((' . $f['label'] . ')) AS lbl,
        SUM(jl.debit) AS tdr, SUM(jl.credit) AS tcr
    FROM tbl_fin_journal_line jl
    INNER JOIN tbl_fin_journal_header j ON j.id = jl.journal_id
    ' . $f['join'] . '
    WHERE j.facility_id = ' . $fid . ' AND j.entry_date BETWEEN \'2020-01-01\' AND \'2026-12-31\'
    GROUP BY (' . $f['code'] . ')
    ORDER BY (' . $f['code'] . ')';
echo "Full SQL:\n$sql\n\n";
$q = @mysqli_query($connection, $sql);
if (!$q) {
    echo "SQL ERROR: " . mysqli_error($connection) . "\n";
} else {
    $count = 0;
    while ($r = mysqli_fetch_assoc($q)) {
        echo "code={$r['c']}, lbl={$r['lbl']}, dr={$r['tdr']}, cr={$r['tcr']}\n";
        $count++;
        if ($count >= 10) { echo "... (truncated at 10)\n"; break; }
    }
    if ($count === 0) echo "(no rows returned)\n";
    mysqli_free_result($q);
}
