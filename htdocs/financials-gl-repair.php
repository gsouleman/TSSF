<?php
declare(strict_types=1);

/**
 * HMS GL Repair & Diagnostic Script
 * -----------------------------------
 * Fixes: Trial Balance / General Ledger showing zero data.
 * Diagnoses missing tbl_facility row, missing billing tables,
 * and seeds GL journals from tbl_transaction if needed.
 *
 * USAGE: Login as admin, then open /financials-gl-repair.php
 * DELETE THIS FILE after repair is complete.
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

// Only admin can run this
if (!isset($connection) || !($connection instanceof mysqli)) {
    die('No database connection.');
}

// ── helpers ──────────────────────────────────────────────────────────────────
function gls_q(mysqli $db, string $sql): array
{
    $q = @mysqli_query($db, $sql);
    if (!$q) {
        return ['__error' => mysqli_error($db)];
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = (array) $r;
    }
    mysqli_free_result($q);
    return $rows;
}

function gls_scalar(mysqli $db, string $sql): string
{
    $q = @mysqli_query($db, $sql);
    if (!$q) {
        return 'SQL_ERR: ' . mysqli_error($db);
    }
    $r = mysqli_fetch_assoc($q);
    mysqli_free_result($q);
    return $r ? (string) reset($r) : '0';
}

function gls_tbl(mysqli $db, string $t): bool
{
    $q = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $t) . "'");
    if (!$q) return false;
    $ok = mysqli_num_rows($q) > 0;
    mysqli_free_result($q);
    return $ok;
}

function gls_col(mysqli $db, string $t, string $c): bool
{
    $q = @mysqli_query($db, "SHOW COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $t) . "` LIKE '" . mysqli_real_escape_string($db, $c) . "'");
    if (!$q) return false;
    $ok = mysqli_num_rows($q) > 0;
    mysqli_free_result($q);
    return $ok;
}

$fid = hms_current_facility_id();
$doRun  = isset($_POST['do_repair']);
$doSeed = isset($_POST['do_seed_txn']);

$log = [];
$errors = [];

// ── STEP 0: Table inventory ───────────────────────────────────────────────────
$tables_needed = [
    'tbl_facility', 'tbl_fin_journal_header', 'tbl_fin_journal_line',
    'tbl_billing_document', 'tbl_billing_document_line',
    'tbl_transaction', 'tbl_expense',
];
$tbl_status = [];
foreach ($tables_needed as $t) {
    $tbl_status[$t] = gls_tbl($connection, $t);
}

// ── STEP 1: Facility row check ────────────────────────────────────────────────
$facility_row_ok = false;
$current_facilities = [];
if ($tbl_status['tbl_facility']) {
    $rows = gls_q($connection, 'SELECT id, code, name, status FROM tbl_facility ORDER BY id LIMIT 20');
    $current_facilities = $rows;
    foreach ($rows as $r) {
        if ((int)$r['id'] === $fid) {
            $facility_row_ok = true;
        }
    }
}

// ── STEP 2: Journal counts ────────────────────────────────────────────────────
$jh_total   = '0';
$jh_any     = '0';
$jl_total   = '0';
$jh_fac_ids = [];
if ($tbl_status['tbl_fin_journal_header']) {
    $jh_total = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_fin_journal_header WHERE facility_id = ' . $fid);
    $jh_any   = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_fin_journal_header');
    $rows = gls_q($connection, 'SELECT facility_id, COUNT(*) AS n FROM tbl_fin_journal_header GROUP BY facility_id ORDER BY facility_id LIMIT 20');
    $jh_fac_ids = $rows;
    if ($tbl_status['tbl_fin_journal_line']) {
        $jl_total = gls_scalar($connection, 'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id WHERE h.facility_id = ' . $fid);
    }
}

// ── STEP 3: Transaction / billing counts ─────────────────────────────────────
$txn_count  = '0';
$bill_count = '0';
$exp_count  = '0';
if ($tbl_status['tbl_transaction']) {
    $hasFac = gls_col($connection, 'tbl_transaction', 'facility_id');
    if ($hasFac) {
        $txn_count = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_transaction WHERE facility_id = ' . $fid . ' AND amount > 0');
    } else {
        $txn_count = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_transaction WHERE amount > 0');
    }
}
if ($tbl_status['tbl_billing_document']) {
    $bill_count = gls_scalar($connection, "SELECT COUNT(*) FROM tbl_billing_document WHERE facility_id = " . $fid . " AND doc_type = 'receipt' AND total_amount > 0.005");
}
if ($tbl_status['tbl_expense']) {
    $exp_count = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_expense WHERE facility_id = ' . $fid);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ══════════════════════════════════════════════════════════════════════════════

if ($doRun && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    // A. Ensure facility row
    if ($tbl_status['tbl_facility'] && !$facility_row_ok) {
        $code = 'MAIN';
        $name = 'TSSF Solidarity of Hearts Hospital SOA';
        $ok = @mysqli_query($connection,
            "INSERT INTO tbl_facility (id, code, name, status) VALUES ({$fid}, 'MAIN', 'TSSF Solidarity of Hearts Hospital SOA', 1)"
            . " ON DUPLICATE KEY UPDATE name = VALUES(name), status = 1"
        );
        if ($ok) {
            $log[] = "✅ Inserted/updated tbl_facility row for id={$fid}.";
            $facility_row_ok = true;
        } else {
            $errors[] = "❌ Could not insert tbl_facility row: " . mysqli_error($connection);
        }
    } elseif ($facility_row_ok) {
        $log[] = "ℹ️ tbl_facility row for id={$fid} already exists.";
    }

    // B. Create GL tables if missing
    if (!$tbl_status['tbl_fin_journal_header']) {
        $sql = "CREATE TABLE IF NOT EXISTS tbl_fin_journal_header (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            facility_id    INT NOT NULL DEFAULT 1,
            entry_date     DATE NOT NULL,
            reference      VARCHAR(64) NOT NULL DEFAULT '',
            narration      VARCHAR(512) NOT NULL DEFAULT '',
            source_type    VARCHAR(64) NOT NULL DEFAULT '',
            source_id      INT NOT NULL DEFAULT 0,
            created_by     INT NOT NULL DEFAULT 0,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fid_date (facility_id, entry_date),
            UNIQUE KEY uq_fin_jrnl_src (facility_id, source_type, source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $ok = @mysqli_query($connection, $sql);
        if ($ok) {
            $log[] = '✅ Created tbl_fin_journal_header.';
            $tbl_status['tbl_fin_journal_header'] = true;
        } else {
            $errors[] = '❌ Create tbl_fin_journal_header failed: ' . mysqli_error($connection);
        }
    } else {
        $log[] = 'ℹ️ tbl_fin_journal_header already exists.';
    }

    if (!$tbl_status['tbl_fin_journal_line']) {
        $sql = "CREATE TABLE IF NOT EXISTS tbl_fin_journal_line (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id   INT UNSIGNED NOT NULL,
            account_code VARCHAR(32) NOT NULL DEFAULT '',
            account_label VARCHAR(160) NOT NULL DEFAULT '',
            debit        DECIMAL(18,2) NOT NULL DEFAULT 0,
            credit       DECIMAL(18,2) NOT NULL DEFAULT 0,
            INDEX idx_jid (journal_id),
            INDEX idx_code (account_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $ok = @mysqli_query($connection, $sql);
        if ($ok) {
            $log[] = '✅ Created tbl_fin_journal_line.';
            $tbl_status['tbl_fin_journal_line'] = true;
        } else {
            $errors[] = '❌ Create tbl_fin_journal_line failed: ' . mysqli_error($connection);
        }
    } else {
        $log[] = 'ℹ️ tbl_fin_journal_line already exists.';
    }

    // C. Run the existing schema repair helpers
    if (function_exists('hms_fin_journal_line_schema_ensure')) {
        $ok = hms_fin_journal_line_schema_ensure($connection);
        $log[] = ($ok ? '✅' : '⚠️') . ' hms_fin_journal_line_schema_ensure: ' . ($ok ? 'ok' : hms_fin_journal_post_last_error());
    }
    if (function_exists('hms_fin_journal_line_fk_ensure')) {
        $ok = hms_fin_journal_line_fk_ensure($connection);
        $log[] = ($ok ? '✅' : '⚠️') . ' hms_fin_journal_line_fk_ensure: ' . ($ok ? 'ok' : hms_fin_journal_post_last_error());
    }
    if (function_exists('hms_fin_journal_line_account_id_ensure')) {
        $ok = hms_fin_journal_line_account_id_ensure($connection);
        $log[] = ($ok ? '✅' : '⚠️') . ' hms_fin_journal_line_account_id_ensure: ' . ($ok ? 'ok' : hms_fin_journal_post_last_error());
    }

    // D. Sync billing receipts (if billing tables exist)
    if ($tbl_status['tbl_billing_document'] && $tbl_status['tbl_billing_document_line']
        && function_exists('hms_fin_backfill_receipt_journals_for_date_range')) {
        $r = hms_fin_backfill_receipt_journals_for_date_range($connection, $fid, '2020-01-01', date('Y-m-d'), 5000);
        $log[] = "✅ Billing receipt sync: {$r['processed']} scanned, {$r['inserted']} new, {$r['duplicate']} dup, {$r['failed']} failed."
            . ($r['first_error'] !== '' ? " Error: {$r['first_error']}" : '');
    } else {
        $log[] = '⚠️ Billing document tables missing or function unavailable — receipt sync skipped.';
    }

    // E. Sync expenses
    if ($tbl_status['tbl_expense'] && function_exists('hms_fin_backfill_expense_journals_for_date_range')) {
        $e = hms_fin_backfill_expense_journals_for_date_range($connection, $fid, '2020-01-01', date('Y-m-d'), 5000);
        $log[] = "✅ Expense sync: {$e['processed']} scanned, {$e['inserted']} new, {$e['duplicate']} dup, {$e['failed']} failed.";
    } else {
        $log[] = '⚠️ tbl_expense missing — expense sync skipped.';
    }

    // Re-read counts
    if ($tbl_status['tbl_fin_journal_header']) {
        $jh_total = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_fin_journal_header WHERE facility_id = ' . $fid);
        $jh_any   = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_fin_journal_header');
        if ($tbl_status['tbl_fin_journal_line']) {
            $jl_total = gls_scalar($connection, 'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id WHERE h.facility_id = ' . $fid);
        }
    }
    $log[] = "📊 Journal headers (site #{$fid}): {$jh_total} | Lines: {$jl_total} | Total all sites: {$jh_any}";
}

// ── Seed from tbl_transaction ─────────────────────────────────────────────────
$seedDone = 0;
$seedFail = 0;
$seedDup  = 0;

if ($doSeed && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    if (!$tbl_status['tbl_fin_journal_header'] || !$tbl_status['tbl_fin_journal_line']) {
        $errors[] = '❌ GL tables not ready. Run "Run Repair" first.';
    } elseif (!$tbl_status['tbl_transaction']) {
        $errors[] = '❌ tbl_transaction does not exist — cannot seed from transactions.';
    } else {
        // Build query depending on columns available
        $hasFac = gls_col($connection, 'tbl_transaction', 'facility_id');
        $hasDate = gls_col($connection, 'tbl_transaction', 'created_at');
        $hasPm = gls_col($connection, 'tbl_transaction', 'payment_method');
        $hasDesc = gls_col($connection, 'tbl_transaction', 'description');

        $facClause = $hasFac ? ' WHERE facility_id = ' . $fid . ' AND amount > 0' : ' WHERE amount > 0';
        $pmSel = $hasPm ? 'payment_method' : "NULL AS payment_method";
        $descSel = $hasDesc ? 'description' : "NULL AS description";
        $dateSel = $hasDate ? 'DATE(created_at)' : "CURDATE()";

        $txnSql = "SELECT id, amount, {$pmSel}, {$descSel}, {$dateSel} AS txn_date FROM tbl_transaction {$facClause} ORDER BY id ASC LIMIT 5000";
        $txnRows = gls_q($connection, $txnSql);

        if (isset($txnRows[0]['__error'])) {
            $errors[] = '❌ tbl_transaction query failed: ' . $txnRows[0]['__error'];
        } else {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            foreach ($txnRows as $txn) {
                $tid = (int)($txn['id'] ?? 0);
                $amt = round((float)($txn['amount'] ?? 0), 2);
                $pm  = (string)($txn['payment_method'] ?? 'Cash');
                $desc = substr(trim((string)($txn['description'] ?? 'Patient payment')), 0, 200);
                $txnDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($txn['txn_date'] ?? '')) ? $txn['txn_date'] : date('Y-m-d');
                if ($amt <= 0 || $tid < 1) continue;

                // Cash account
                $pmLow = strtolower($pm);
                if (strpos($pmLow, 'bank') !== false || strpos($pmLow, 'transfer') !== false
                 || strpos($pmLow, 'card') !== false || strpos($pmLow, 'mobile') !== false
                 || strpos($pmLow, 'momo') !== false) {
                    $cashCode = '521000'; $cashLabel = 'Banks — patient collection';
                } else {
                    $cashCode = '571000'; $cashLabel = 'Cash — patient collection';
                }

                $r = hms_fin_journal_post(
                    $connection,
                    $fid,
                    'transaction',
                    $tid,
                    'TXN-' . $tid,
                    'Patient transaction · ' . $desc,
                    $uid,
                    [
                        ['code' => $cashCode,  'label' => $cashLabel, 'debit' => $amt, 'credit' => 0.0],
                        ['code' => '706000', 'label' => 'Healthcare services revenue', 'debit' => 0.0, 'credit' => $amt],
                    ],
                    $txnDate
                );
                if ($r === 1)      $seedDone++;
                elseif ($r === 2)  $seedDup++;
                else               $seedFail++;
            }
            $log[] = "✅ Seed from tbl_transaction: {$seedDone} new, {$seedDup} dup, {$seedFail} failed out of " . count($txnRows) . " rows.";
        }

        // Re-read counts
        $jh_total = gls_scalar($connection, 'SELECT COUNT(*) FROM tbl_fin_journal_header WHERE facility_id = ' . $fid);
        $jl_total = gls_scalar($connection, 'SELECT COUNT(*) AS c FROM tbl_fin_journal_line jl INNER JOIN tbl_fin_journal_header h ON h.id = jl.journal_id WHERE h.facility_id = ' . $fid);
        $log[] = "📊 After seed: Headers (site #{$fid}): {$jh_total} | Lines: {$jl_total}";
    }
}

include __DIR__ . '/header.php';
?>
<div class="page-wrapper">
<div class="content hms-module">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="mb-0">⚕ GL Repair &amp; Diagnostic</h2>
        <p class="text-muted small mb-0">Diagnose and fix zero Trial Balance. <strong>Delete this file after use.</strong></p>
    </div>
    <a href="financials-trial-balance.php" class="btn btn-outline-primary btn-sm">→ Trial Balance</a>
</div>

<?php if ($errors) { ?>
<div class="alert alert-danger">
    <strong>Errors:</strong><ul class="mb-0">
    <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
    </ul>
</div>
<?php } ?>
<?php if ($log) { ?>
<div class="alert alert-success">
    <strong>Action log:</strong><ul class="mb-0">
    <?php foreach ($log as $l) echo '<li>' . htmlspecialchars($l) . '</li>'; ?>
    </ul>
</div>
<?php } ?>

<!-- DIAGNOSIS CARD -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-dark text-white"><strong>1. Database Diagnosis</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0">
            <tbody>
            <tr><th>Active facility id (HMS_FIXED_FACILITY_ID)</th><td><?= (int)$fid ?></td></tr>
            <tr class="<?= $facility_row_ok ? 'table-success' : 'table-danger' ?>">
                <th>tbl_facility row for id=<?= (int)$fid ?></th>
                <td><?= $facility_row_ok ? '✅ YES' : '❌ NO — this is why journals fail foreign key' ?></td>
            </tr>
            <tr><th>All rows in tbl_facility</th><td>
                <?php foreach ($current_facilities as $f): ?>
                    id=<?= (int)$f['id'] ?> code=<?= htmlspecialchars((string)($f['code'] ?? '')) ?> name=<?= htmlspecialchars((string)($f['name'] ?? '')) ?><br>
                <?php endforeach; ?>
                <?php if (!$current_facilities) echo '(none / table missing)'; ?>
            </td></tr>
            <tr class="<?= (int)$jh_total > 0 ? 'table-success' : 'table-warning' ?>">
                <th>Journal headers (site #<?= (int)$fid ?>)</th>
                <td><?= htmlspecialchars($jh_total) ?></td>
            </tr>
            <tr class="<?= (int)$jl_total > 0 ? 'table-success' : 'table-warning' ?>">
                <th>Journal lines (site #<?= (int)$fid ?>)</th>
                <td><?= htmlspecialchars($jl_total) ?></td>
            </tr>
            <tr><th>Journal headers (ALL sites)</th><td><?= htmlspecialchars($jh_any) ?></td></tr>
            <tr><th>Journal distribution by facility_id</th><td>
                <?php foreach ($jh_fac_ids as $r): ?>
                    fac #<?= (int)$r['facility_id'] ?>: <?= (int)$r['n'] ?> headers<br>
                <?php endforeach; ?>
                <?php if (!$jh_fac_ids) echo '(none)'; ?>
            </td></tr>
            </tbody>
        </table>
        <table class="table table-sm table-bordered mb-0">
            <thead><tr><th>Table</th><th>Exists</th><th>Count (site #<?= (int)$fid ?>)</th></tr></thead>
            <tbody>
            <?php foreach ($tables_needed as $t): ?>
            <tr class="<?= $tbl_status[$t] ? '' : 'table-danger' ?>">
                <td><code><?= htmlspecialchars($t) ?></code></td>
                <td><?= $tbl_status[$t] ? '✅' : '❌ MISSING' ?></td>
                <td><?php
                    if (!$tbl_status[$t]) { echo '—'; }
                    elseif ($t === 'tbl_transaction') echo htmlspecialchars($txn_count);
                    elseif ($t === 'tbl_billing_document') echo htmlspecialchars($bill_count);
                    elseif ($t === 'tbl_expense') echo htmlspecialchars($exp_count);
                    elseif ($t === 'tbl_fin_journal_header') echo htmlspecialchars($jh_total);
                    elseif ($t === 'tbl_fin_journal_line') echo htmlspecialchars($jl_total);
                    else { $n = gls_scalar($connection, "SELECT COUNT(*) FROM `" . preg_replace('/[^a-z0-9_]/i','',$t) . "`"); echo htmlspecialchars($n); }
                ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ROOT CAUSE ANALYSIS -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-warning"><strong>2. Root Cause</strong></div>
    <div class="card-body">
        <?php
        $causes = [];
        if (!$facility_row_ok) $causes[] = '🔴 <strong>tbl_facility has no row for id='.$fid.'</strong>. Journals cannot be inserted (FK violation). Fix: run "Run Repair" below.';
        if (!$tbl_status['tbl_fin_journal_header']) $causes[] = '🔴 <strong>tbl_fin_journal_header is missing</strong>. Run "Run Repair" to create it.';
        if (!$tbl_status['tbl_fin_journal_line']) $causes[] = '🔴 <strong>tbl_fin_journal_line is missing</strong>. Run "Run Repair" to create it.';
        if (!$tbl_status['tbl_billing_document']) $causes[] = '🟡 <strong>tbl_billing_document is missing</strong> — billing receipt sync will be skipped. GL will be seeded from tbl_transaction instead.';
        if ((int)$jh_total === 0 && $facility_row_ok && $tbl_status['tbl_fin_journal_header']) $causes[] = '🟡 <strong>GL tables exist and facility row exists, but 0 journal entries for this site.</strong> Use "Seed from tbl_transaction" below.';
        if ((int)$jh_total > 0 && (int)$jl_total === 0) $causes[] = '🔴 <strong>Journal headers exist but 0 lines</strong>. Schema/FK mismatch. Run Repair.';
        if (!$causes) $causes[] = '✅ No obvious structural issues found. GL might have data — check Trial Balance with a wide date range.';
        foreach ($causes as $c) echo "<p class='mb-1'>$c</p>";
        ?>
    </div>
</div>

<!-- ACTION 1: RUN REPAIR -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-primary text-white"><strong>3. Run Repair</strong></div>
    <div class="card-body">
        <p>This will: (1) insert missing tbl_facility row, (2) create GL tables if missing, (3) run schema repair helpers, (4) sync billing receipts + expenses to the GL.</p>
        <form method="post">
            <?= hms_csrf_field() ?>
            <button name="do_repair" value="1" type="submit" class="btn btn-primary">Run Repair + Sync Billing/Expenses</button>
        </form>
    </div>
</div>

<!-- ACTION 2: SEED FROM TRANSACTIONS -->
<div class="card shadow-sm mb-3">
    <div class="card-header bg-secondary text-white"><strong>4. Seed GL from tbl_transaction</strong></div>
    <div class="card-body">
        <?php if (!$tbl_status['tbl_transaction']): ?>
        <div class="alert alert-warning mb-0">tbl_transaction not found — this option is unavailable.</div>
        <?php else: ?>
        <p>If billing document tables are missing or sync produced 0 results, use this to post <strong><?= htmlspecialchars($txn_count) ?></strong> transaction row(s) from <code>tbl_transaction</code> directly to the GL (DR cash/bank · CR revenue). Safe to re-run — duplicates are skipped.</p>
        <form method="post">
            <?= hms_csrf_field() ?>
            <button name="do_seed_txn" value="1" type="submit" class="btn btn-secondary">Seed GL from tbl_transaction (<?= htmlspecialchars($txn_count) ?> rows)</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- NEXT STEPS -->
<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>5. Next Steps</strong></div>
    <div class="card-body">
        <ol>
            <li>Click <strong>"Run Repair + Sync"</strong> above.</li>
            <li>If journal headers remain 0 after repair, click <strong>"Seed GL from tbl_transaction"</strong>.</li>
            <li>Open <a href="financials-trial-balance.php?d1=2020-01-01&d2=<?= date('Y-m-d') ?>">Trial Balance (full date range)</a>.</li>
            <li>Open <a href="financials-general-ledger.php?d1=2020-01-01&d2=<?= date('Y-m-d') ?>">General Ledger</a> to verify entries.</li>
            <li><strong>Delete this file</strong> from your server after repair.</li>
        </ol>
    </div>
</div>

</div>
</div>
<?php include __DIR__ . '/footer.php';
