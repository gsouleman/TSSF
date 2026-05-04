<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Endpoint for GBPAY IPN (Instant Payment Notification) Webhook
// This should be registered in the GBPAY dashboard.

header('Content-Type: application/json');

// Read JSON payload
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    exit;
}

// Security: Verify GBPAY signature (Mocked implementation)
// In production, this would hash payload with a secret and compare to X-GBPAY-SIGNATURE Header
$signature = $_SERVER['HTTP_X_GBPAY_SIGNATURE'] ?? '';
if (empty($signature) && !isset($data['test_mode'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing signature']);
    exit;
}

// GBPAY typical payload fields:
// transaction_id, status (success/failed), amount, currency, external_reference (our qr_token or wallet_id)
$gbpayTxnId = $data['transaction_id'] ?? '';
$status = $data['status'] ?? '';
$amount = (float) ($data['amount'] ?? 0);
$externalRef = $data['external_reference'] ?? ''; // Expecting qr_token

if ($status === 'success' && $amount > 0 && $externalRef !== '') {
    mysqli_begin_transaction($connection);
    
    // Find wallet by qr_token
    $stmt = mysqli_prepare($connection, "SELECT id, balance, facility_id FROM tbl_patient_wallet WHERE qr_token = ? FOR UPDATE");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $externalRef);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $walletId = (int) $row['id'];
            $curBal = (float) $row['balance'];
            
            // Basic idempotency check
            $chk = mysqli_prepare($connection, "SELECT id FROM tbl_patient_wallet_txn WHERE reference_id = ?");
            $alreadyProcessed = false;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 's', $gbpayTxnId);
                mysqli_stmt_execute($chk);
                $chkRes = mysqli_stmt_get_result($chk);
                if ($chkRes && mysqli_num_rows($chkRes) > 0) {
                    $alreadyProcessed = true;
                }
                mysqli_stmt_close($chk);
            }
            
            if (!$alreadyProcessed) {
                $newBal = $curBal + $amount;
                
                // Update balance
                $upd = mysqli_prepare($connection, "UPDATE tbl_patient_wallet SET balance = ? WHERE id = ?");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, 'di', $newBal, $walletId);
                    mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                    
                    // Insert Txn
                    $ins = mysqli_prepare($connection, "INSERT INTO tbl_patient_wallet_txn (wallet_id, txn_type, direction, amount, balance_after, reference_id, notes) VALUES (?, 'deposit_gbpay', 'cr', ?, ?, ?, ?)");
                    if ($ins) {
                        $notes = "Automated top-up from GBPAY";
                        mysqli_stmt_bind_param($ins, 'iddss', $walletId, $amount, $newBal, $gbpayTxnId, $notes);
                        mysqli_stmt_execute($ins);
                        mysqli_stmt_close($ins);
                    }
                    
                    mysqli_commit($connection);
                    echo json_encode(['status' => 'success', 'message' => 'Wallet topped up']);
                    exit;
                }
            } else {
                mysqli_rollback($connection);
                echo json_encode(['status' => 'success', 'message' => 'Transaction already processed']);
                exit;
            }
        }
        mysqli_rollback($connection);
    }
}

// Default fallback
http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid parameters or wallet not found']);
