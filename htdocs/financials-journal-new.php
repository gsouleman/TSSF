<?php
/** No strict_types: shared-host compatibility (mysqli + hms_h). */

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_fin_require_mysqli($connection);
hms_fin_require($connection, 'financials.write');
if (!hms_financials_ready($connection)) {
    header('Location: financials.php');
    exit;
}
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$accounts = hms_fin_posting_accounts($connection);
$centers = hms_fin_cost_centers_active($connection);
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_journal'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $jdate = trim(hms_fin_post_string_scalar($_POST['journal_date'] ?? null, ''));
        $desc = trim(hms_fin_post_string_scalar($_POST['description'] ?? null, ''));
        $ref = trim(hms_fin_post_string_scalar($_POST['reference'] ?? null, ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jdate)) {
            $err = 'Invalid journal date.';
        } elseif ($desc === '') {
            $err = 'Description is required.';
        } else {
            $accA = isset($_POST['acc']) && is_array($_POST['acc']) ? $_POST['acc'] : [];
            $drA = isset($_POST['dr']) && is_array($_POST['dr']) ? $_POST['dr'] : [];
            $crA = isset($_POST['cr']) && is_array($_POST['cr']) ? $_POST['cr'] : [];
            $ccA = isset($_POST['cc']) && is_array($_POST['cc']) ? $_POST['cc'] : [];
            $memoA = isset($_POST['memo']) && is_array($_POST['memo']) ? $_POST['memo'] : [];
            $lines = [];
            for ($i = 0; $i < 12; $i++) {
                $aid = hms_fin_post_int_scalar($accA[$i] ?? null, 0);
                $dr = (int) preg_replace('/\D+/', '', hms_fin_post_string_scalar($drA[$i] ?? null, '0'));
                $cr = (int) preg_replace('/\D+/', '', hms_fin_post_string_scalar($crA[$i] ?? null, '0'));
                $ccid = hms_fin_post_int_scalar($ccA[$i] ?? null, 0);
                $memo = trim(hms_fin_post_string_scalar($memoA[$i] ?? null, ''));
                if ($aid < 1) {
                    continue;
                }
                if ($dr > 0 && $cr > 0) {
                    $err = 'Each line must be either debit or credit, not both.';
                    break;
                }
                if ($dr < 1 && $cr < 1) {
                    continue;
                }
                $lines[] = ['account_id' => $aid, 'debit' => $dr, 'credit' => $cr, 'cc' => $ccid, 'memo' => $memo];
            }
            if ($err === '') {
                $td = 0;
                $tc = 0;
                foreach ($lines as $ln) {
                    $td += $ln['debit'];
                    $tc += $ln['credit'];
                }
                if ($lines === []) {
                    $err = 'Add at least one journal line.';
                } elseif ($td !== $tc || $td < 1) {
                    $err = 'Total debits must equal total credits and be greater than zero.';
                } else {
                    $validIds = [];
                    foreach ($accounts as $a) {
                        $validIds[] = (int) $a['id'];
                    }
                    foreach ($lines as $ln) {
                        if (!in_array($ln['account_id'], $validIds, true)) {
                            $err = 'Invalid account on a line.';
                            break;
                        }
                    }
                }
            }
            if ($err === '') {
                mysqli_begin_transaction($connection);
                try {
                    $jno = hms_fin_next_journal_no($connection, $fid);
                    $st = mysqli_prepare(
                        $connection,
                        'INSERT INTO tbl_fin_journal (facility_id, journal_no, journal_date, description, reference, source, status, created_by, posted_at) VALUES (?,?,?,?,?,?,?,?,NOW())'
                    );
                    if (!$st) {
                        throw new RuntimeException('prep');
                    }
                    $src = 'manual';
                    $stat = 'posted';
                    mysqli_stmt_bind_param($st, 'issssssi', $fid, $jno, $jdate, $desc, $ref, $src, $stat, $uid);
                    mysqli_stmt_execute($st);
                    $jid = (int) mysqli_insert_id($connection);
                    mysqli_stmt_close($st);
                    if ($jid < 1) {
                        throw new RuntimeException('jid');
                    }
                    foreach ($lines as $ln) {
                        $a = (int) $ln['account_id'];
                        $d = (int) $ln['debit'];
                        $c = (int) $ln['credit'];
                        $ccSql = $ln['cc'] > 0 ? (string) (int) $ln['cc'] : 'NULL';
                        $memo = $ln['memo'] !== '' ? "'" . mysqli_real_escape_string($connection, $ln['memo']) . "'" : 'NULL';
                        $okLine = mysqli_query(
                            $connection,
                            'INSERT INTO tbl_fin_journal_line (journal_id, account_id, cost_center_id, line_memo, debit, credit) VALUES ('
                            . (int) $jid . ',' . $a . ',' . $ccSql . ',' . $memo . ',' . $d . ',' . $c . ')'
                        );
                        if (!$okLine) {
                            throw new RuntimeException('line');
                        }
                    }
                    mysqli_commit($connection);
                    hms_audit_log($connection, 'fin.journal.post', 'fin_journal', $jid, ['journal_no' => $jno]);
                    $_SESSION['fin_flash'] = 'Journal ' . $jno . ' posted.';
                    header('Location: financials-journal-view.php?id=' . $jid);
                    exit;
                } catch (Throwable $e) {
                    mysqli_rollback($connection);
                    $err = 'Could not save journal.';
                }
            }
        }
    }
}

include __DIR__ . '/header.php';
$today = date('Y-m-d');
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('New journal entry', [
                    'subtitle' => 'Balanced double entry in XAF. OHADA accounts and English cost centres.',
                    'breadcrumbs' => [['Financials', 'financials.php'], ['Journal', 'financials-journal.php'], ['New', null]],
                    'back' => 'financials-journal.php',
                ]);
                ?>
                <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>
                <form method="post" class="card border-0 shadow-sm">
                    <?php echo hms_csrf_field(); ?>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Journal date</label>
                                    <input type="date" name="journal_date" class="form-control" required value="<?php echo hms_h($today); ?>">
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label>Description</label>
                                    <input type="text" name="description" class="form-control" required maxlength="500" placeholder="e.g. Monthly electricity accrual">
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Reference (optional)</label>
                                    <input type="text" name="reference" class="form-control" maxlength="160" placeholder="Invoice / memo number">
                                </div>
                            </div>
                        </div>
                        <h3 class="h6 font-weight-bold border-bottom pb-2 mb-3">Lines (total debits = total credits)</h3>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Account</th><th>Cost centre</th><th>Debit (XAF)</th><th>Credit (XAF)</th><th>Line memo</th></tr></thead>
                                <tbody>
                                    <?php for ($i = 0; $i < 12; $i++) { ?>
                                    <tr>
                                        <td style="min-width:220px;">
                                            <select name="acc[<?php echo $i; ?>]" class="form-control form-control-sm">
                                                <option value="0">—</option>
                                                <?php foreach ($accounts as $a) { ?>
                                                <option value="<?php echo (int) $a['id']; ?>"><?php echo hms_h($a['code'] . ' — ' . $a['label_en']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="cc[<?php echo $i; ?>]" class="form-control form-control-sm">
                                                <option value="0">— None —</option>
                                                <?php foreach ($centers as $c) { ?>
                                                <option value="<?php echo (int) $c['id']; ?>"><?php echo hms_h($c['code']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td><input type="text" name="dr[<?php echo $i; ?>]" class="form-control form-control-sm" placeholder="0"></td>
                                        <td><input type="text" name="cr[<?php echo $i; ?>]" class="form-control form-control-sm" placeholder="0"></td>
                                        <td><input type="text" name="memo[<?php echo $i; ?>]" class="form-control form-control-sm" maxlength="255"></td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" name="save_journal" value="1" class="btn btn-primary font-weight-bold">Post journal</button>
                    </div>
                </form>
            </div>
        </div>
<?php include __DIR__ . '/footer.php'; ?>
