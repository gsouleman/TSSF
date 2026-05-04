<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

// Require billing write permissions to do top-ups
hms_require_permission($connection, 'billing.write');

$fid = hms_current_facility_id();

// Flash message
$flash = '';
if (!empty($_SESSION['wallet_flash'])) {
    $flash = (string) $_SESSION['wallet_flash'];
    unset($_SESSION['wallet_flash']);
}

// Handle Manual Cash Top-up submit
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'topup_wallet') {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $_SESSION['wallet_flash'] = 'Invalid security token.';
        header('Location: wallet-management.php');
        exit;
    }
    
    $walletId = (int) ($_POST['wallet_id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0.0);
    $notes = trim((string) ($_POST['notes'] ?? 'Manual Cash Deposit'));
    
    if ($walletId > 0 && $amount > 0) {
        try {
            mysqli_begin_transaction($connection);

            // 1. Get current balance and lock row (bind_result works without mysqlnd; get_result does not)
            $stmt = mysqli_prepare($connection, 'SELECT balance FROM tbl_patient_wallet WHERE id=? AND facility_id=? FOR UPDATE');
            if (!$stmt) {
                throw new RuntimeException('prepare balance select failed');
            }
            mysqli_stmt_bind_param($stmt, 'ii', $walletId, $fid);
            if (!mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                throw new RuntimeException('execute balance select failed');
            }
            $balCol = null;
            mysqli_stmt_bind_result($stmt, $balCol);
            $found = mysqli_stmt_fetch($stmt);
            mysqli_stmt_close($stmt);

            if (!$found || $balCol === null) {
                mysqli_rollback($connection);
                $_SESSION['wallet_flash'] = 'Wallet not found or access denied.';
            } else {
                $curBal = (float) $balCol;
                $newBal = $curBal + $amount;

                // 2. Update balance
                $uStmt = mysqli_prepare($connection, 'UPDATE tbl_patient_wallet SET balance=? WHERE id=?');
                if (!$uStmt) {
                    mysqli_rollback($connection);
                    $_SESSION['wallet_flash'] = 'Error updating balance.';
                } else {
                    mysqli_stmt_bind_param($uStmt, 'di', $newBal, $walletId);
                    if (!mysqli_stmt_execute($uStmt)) {
                        mysqli_stmt_close($uStmt);
                        mysqli_rollback($connection);
                        $_SESSION['wallet_flash'] = 'Error updating balance.';
                    } else {
                        mysqli_stmt_close($uStmt);

                        // 3. Log transaction
                        $tStmt = mysqli_prepare($connection, "INSERT INTO tbl_patient_wallet_txn (wallet_id, txn_type, direction, amount, balance_after, notes, created_by) VALUES (?, 'deposit_cash', 'cr', ?, ?, ?, ?)");
                        if ($tStmt) {
                            $empId = (int) ($_SESSION['user_id'] ?? 0);
                            mysqli_stmt_bind_param($tStmt, 'iddsi', $walletId, $amount, $newBal, $notes, $empId);
                            mysqli_stmt_execute($tStmt);
                            mysqli_stmt_close($tStmt);
                        }

                        mysqli_commit($connection);
                        $_SESSION['wallet_flash'] = 'Wallet successfully topped up by ' . hms_format_xaf($amount) . '.';
                    }
                }
            }
        } catch (Throwable $e) {
            if ($connection instanceof mysqli) {
                @mysqli_rollback($connection);
            }
            if (function_exists('error_log')) {
                error_log('wallet-management topup: ' . $e->getMessage());
            }
            $_SESSION['wallet_flash'] = 'Top-up failed. Please try again or check that migration 033 ran on the server.';
        }
    } else {
        $_SESSION['wallet_flash'] = 'Invalid amount or wallet.';
    }
    header('Location: wallet-management.php');
    exit;
}

// Search & List Wallets
$qRaw = trim((string) ($_GET['q'] ?? ''));
$wallets = [];
$walletListError = '';

// Determine if the migration has been run
$tableOk = true;
try {
    $c = mysqli_query($connection, 'SELECT 1 FROM tbl_patient_wallet LIMIT 1');
    if (!$c) {
        $tableOk = false;
    }
} catch (Throwable $e) {
    $tableOk = false;
}

if ($tableOk) {
    // tbl_patient has no hospital_no in this codebase; elsewhere we show #PT + padded id (see patients.php).
    $sql = "SELECT w.id AS wallet_id, w.balance, w.status, w.qr_token, w.updated_at, p.id AS patient_id, p.first_name, p.last_name, p.phone
            FROM tbl_patient_wallet w
            INNER JOIN tbl_patient p ON p.id = w.patient_id
            WHERE w.facility_id = " . (int) $fid;

    if ($qRaw !== '') {
        $esc = mysqli_real_escape_string($connection, $qRaw);
        $sql .= " AND (p.first_name LIKE '%{$esc}%' OR p.last_name LIKE '%{$esc}%' OR p.phone LIKE '%{$esc}%'
            OR CAST(p.id AS CHAR) LIKE '%{$esc}%' OR w.qr_token = '{$esc}')";
    }

    $sql .= ' ORDER BY w.updated_at DESC LIMIT 50';

    try {
        $rq = mysqli_query($connection, $sql);
        if ($rq) {
            while ($rr = mysqli_fetch_assoc($rq)) {
                $wallets[] = $rr;
            }
        } else {
            $walletListError = 'Unable to load wallets (query failed).';
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('wallet-management list: ' . $e->getMessage());
        }
        $walletListError = 'Unable to load wallets. Ensure migration 033_patient_wallet_system.sql completed; check PHP error log for details.';
    }
}

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <?php
        hms_ui_page_header('Wallet Management', [
            'subtitle' => 'Pre-paid patient virtual wallets for frictionless department payments and GBMoney topups.',
            'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Wallets', '']],
        ]);
        ?>

        <?php if ($flash !== '') { ?>
            <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
        <?php } ?>

        <?php if ($walletListError !== '') { ?>
            <div class="alert alert-danger border-0 shadow-sm"><?php echo hms_h($walletListError); ?></div>
        <?php } ?>

        <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">Please run the new migration <code>033_patient_wallet_system.sql</code> to enable patient wallets.</div>
        <?php } else { ?>
            <!-- Search Form -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form method="get" class="form-row align-items-end">
                        <div class="form-group col-md-10 mb-0">
                            <label class="small text-muted">Search Patients or QR Token</label>
                            <input type="search" name="q" class="form-control" value="<?php echo hms_h($qRaw); ?>" placeholder="Name, phone, patient ID, or QR token…" autofocus>
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-search"></i> Find</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Pre-Paid Wallet Roster -->
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-header bg-white border-bottom-0 pb-0 pt-3">
                    <h2 class="h6 text-uppercase text-muted font-weight-bold mb-0">Active Virtual Wallets</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Patient</th>
                                    <th>Status</th>
                                    <th class="text-right">Balance</th>
                                    <th>Last Activity</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wallets)) { ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No wallets found. Registration automatic for new patients.</td></tr>
                                <?php } else { 
                                    foreach ($wallets as $w) {
                                        $wid = (int) $w['wallet_id'];
                                        $b = (float) $w['balance'];
                                        $st = (string) $w['status'];
                                        $qr = (string) ($w['qr_token'] ?? '');
                                        $pidRow = (int) ($w['patient_id'] ?? 0);
                                        $ptIdLabel = '#PT' . str_pad((string) $pidRow, 4, '0', STR_PAD_LEFT);
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo hms_h(trim($w['first_name'] . ' ' . $w['last_name'])); ?></div>
                                        <div class="small text-muted"><?php echo hms_h($ptIdLabel); ?> • <?php echo hms_h((string) ($w['phone'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($st === 'active') { ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php } else { ?>
                                            <span class="badge badge-secondary"><?php echo hms_h($st); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td class="text-right font-weight-bold text-success" style="font-size:1.1em;">
                                        <?php echo hms_h(hms_format_xaf($b)); ?>
                                    </td>
                                    <td class="small text-muted"><?php echo hms_h((string)$w['updated_at']); ?></td>
                                    <td class="text-right text-nowrap">
                                        <button class="btn btn-sm btn-outline-info mr-1" onclick="showQR('<?php echo hms_h($qr); ?>', '<?php echo hms_h(trim($w['first_name'] . ' ' . $w['last_name'])); ?>')">
                                            <i class="fa fa-qrcode"></i> QR
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#topupModal" data-wid="<?php echo $wid; ?>" data-name="<?php echo hms_h(trim($w['first_name'] . ' ' . $w['last_name'])); ?>">
                                            <i class="fa fa-money"></i> Top-up (Cash)
                                        </button>
                                    </td>
                                </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

<!-- Modal Manual Topup -->
<div class="modal fade" id="topupModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post">
                <?php echo hms_csrf_field(); ?>
                <input type="hidden" name="action" value="topup_wallet">
                <input type="hidden" name="wallet_id" id="modal_wallet_id" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title">Cash Top-up: <span id="modal_patient_name"></span></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Receive physical cash from the patient to load into their virtual contactless wallet.</p>
                    <div class="form-group">
                        <label>Deposit Amount (CFA)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="1" placeholder="e.g. 10000">
                    </div>
                    <div class="form-group mb-0">
                        <label>Notes</label>
                        <textarea class="form-control" name="notes" rows="2" placeholder="Received at front cashier desk..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fa fa-upload"></i> Process Top-up</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal QR Code -->
<div class="modal fade" id="qrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content text-center">
            <div class="modal-header border-bottom-0 pb-0">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body pt-0 pb-4">
                <h6 class="font-weight-bold mb-3" id="qr_patient_name"></h6>
                <img id="qr_image" src="" alt="QR Code" class="img-fluid border p-2 bg-white shadow-sm" style="width:200px;height:200px;">
                <p class="mt-3 mb-0 small text-muted">Scan at Pharmacies & Labs for direct payment.</p>
                <div class="small text-monospace mt-2 bg-light p-1 border rounded text-truncate" id="qr_token_text"></div>
            </div>
        </div>
    </div>
</div>

<?php
// Scripts must run after jQuery (loaded in footer.php)
$extra_footer_html = <<<'HTML'
<script>
    $('#topupModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var wid = button.data('wid');
        var name = button.data('name');
        var modal = $(this);
        modal.find('#modal_wallet_id').val(wid);
        modal.find('#modal_patient_name').text(name);
    });

    function showQR(token, name) {
        document.getElementById('qr_patient_name').innerText = name;
        document.getElementById('qr_image').src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(token);
        document.getElementById('qr_token_text').innerText = token;
        $('#qrModal').modal('show');
    }
</script>
HTML;

include 'footer.php';
