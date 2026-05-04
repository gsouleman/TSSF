<?php
declare(strict_types=1);

/**
 * Laboratory / radiology / pharmacy — verify cashier payment code before service.
 * Emergency and hospital-approved billing exceptions allow service without payment.
 * Optional ?portal=laboratory|radiology shows a Proceed button for matching lines only.
 */

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$can = hms_can($connection, 'lab.read')
    || hms_can($connection, 'radiology.read')
    || hms_can($connection, 'pharmacy.read')
    || hms_can($connection, 'cashier.write')
    || hms_can($connection, 'billing.write');
if (!$can) {
    http_response_code(403);
    exit('Access denied.');
}

$fid = hms_current_facility_id();
$ticketOk = function_exists('hms_payment_ticket_tables_ok') && hms_payment_ticket_tables_ok($connection);
$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
$portalRaw = (string) ($_GET['portal'] ?? $_POST['portal'] ?? '');
$portalNorm = function_exists('hms_service_verify_portal_normalize') ? hms_service_verify_portal_normalize($portalRaw) : null;
$row = null;
$lines = [];
$flags = ['emergency' => false, 'waiver_note' => '', 'waiver' => false];
$err = null;
$flash = isset($_SESSION['svc_verify_flash']) ? (string) $_SESSION['svc_verify_flash'] : '';
unset($_SESSION['svc_verify_flash']);

if ($ticketOk && $code !== '') {
    $row = hms_payment_ticket_lookup_by_code($connection, $fid, $code);
    if ($row === null) {
        $err = 'No ticket found for that code at this site.';
    } else {
        $pj = json_decode((string) ($row['lines_json'] ?? ''), true);
        $lines = is_array($pj) ? $pj : [];
        $flags = hms_payment_ticket_consult_flags($connection, $fid, $row);
    }
}

include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                if (function_exists('hms_ui_page_header')) {
                    hms_ui_page_header('Payment code verification', [
                        'subtitle' => 'Enter the cashier code from the patient receipt. Paid lines may proceed to sample collection, imaging, or dispensing unless emergency/waiver already covers the visit. Open this page from the Laboratory or Radiology portal to see Proceed for your department only.',
                        'breadcrumbs' => [['Operations', 'dashboard.php'], ['Code check', '']],
                    ]);
                }
                ?>
                <?php if ($flash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div>
                <?php } ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="get" class="form-inline flex-wrap align-items-end">
                            <input type="hidden" name="portal" value="<?php echo hms_h($portalRaw); ?>">
                            <div class="form-group mr-sm-3 mb-2 mb-sm-0">
                                <label class="small font-weight-bold mr-2" for="svcCode">Payment code</label>
                                <input type="text" name="code" id="svcCode" class="form-control text-uppercase" style="min-width:220px" placeholder="PAY-2026-…" value="<?php echo hms_h($code); ?>" autocomplete="off">
                            </div>
                            <button type="submit" class="btn btn-primary font-weight-bold mb-2">Verify</button>
                        </form>
                        <?php if ($portalNorm !== null) { ?>
                        <p class="small text-muted mb-0 mt-2">Portal context: <strong><?php echo hms_h($portalNorm); ?></strong> — Proceed appears only for this specialty.</p>
                        <?php } else { ?>
                        <p class="small text-muted mb-0 mt-2">Tip: open from <a href="portal-laboratory.php">Laboratory portal</a> or <a href="portal-radiology.php">Radiology portal</a> (or add <code>?portal=laboratory</code> / <code>?portal=radiology</code> to the URL) to start a work order.</p>
                        <?php } ?>
                        <?php if (!$ticketOk) { ?>
                        <p class="text-warning small mb-0 mt-3">Payment tickets are not installed. Run <code>hms/database/migrations/023_payment_ticket_cashier.sql</code>.</p>
                        <?php } ?>
                    </div>
                </div>

                <?php if ($err) { ?>
                <div class="alert alert-danger border-0 shadow-sm"><?php echo hms_h($err); ?></div>
                <?php } ?>

                <?php if ($row !== null && $ticketOk) {
                    $pname = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                    $pid = (int) ($row['patient_id'] ?? 0);
                    $st = strtolower((string) ($row['status'] ?? ''));
                    $tcFull = (string) ($row['ticket_code'] ?? '');
                    ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
                        <span class="font-weight-bold">Ticket <?php echo hms_h($tcFull); ?></span>
                        <span class="badge <?php echo $st === 'paid' ? 'badge-success' : 'badge-warning'; ?>"><?php echo hms_h((string) ($row['status'] ?? '')); ?></span>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Patient:</strong> <?php echo hms_h($pname); ?> <span class="text-muted">(#<?php echo $pid; ?>)</span></p>
                        <?php if (!empty($flags['emergency'])) { ?>
                        <div class="alert alert-danger py-2 small mb-3"><strong>Emergency</strong> — clinical exception: proceed per protocol even if lines are unpaid.</div>
                        <?php } elseif (!empty($flags['waiver'])) { ?>
                        <div class="alert alert-info py-2 small mb-3"><strong>Hospital billing exception</strong><?php echo $flags['waiver_note'] !== '' ? ': ' . hms_h($flags['waiver_note']) : ''; ?> — supervisor-approved waiver may allow service without cashier payment.</div>
                        <?php } ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Service line</th>
                                        <th>Kind</th>
                                        <th class="text-right">Amount (FCFA)</th>
                                        <th>Service desk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    foreach ($lines as $lineIdx => $ln) {
                                        if (!is_array($ln)) {
                                            continue;
                                        }
                                        $kind = strtolower((string) ($ln['kind'] ?? ''));
                                        $desk = '—';
                                        if ($kind === 'laboratory') {
                                            $desk = 'Laboratory';
                                        } elseif ($kind === 'radiology') {
                                            $desk = 'Radiology';
                                        } elseif ($kind === 'pharmacy') {
                                            $desk = 'Pharmacy';
                                        } elseif ($kind === 'consultation') {
                                            $desk = 'Consultation';
                                        }
                                        $qty = (float) ($ln['quantity'] ?? 1);
                                        $unit = (float) ($ln['unit_price'] ?? 0);
                                        $lineTot = $qty * $unit;
                                        $paid = hms_payment_ticket_line_paid($ln);
                                        $allow = hms_payment_ticket_line_fulfillment_allowed($ln, $flags);
                                        $showProceed = $allow
                                            && $portalNorm !== null
                                            && function_exists('hms_service_verify_show_proceed')
                                            && hms_service_verify_show_proceed($kind, $portalNorm);
                                        $proceedKind = '';
                                        if ($kind === 'laboratory' && hms_can($connection, 'lab.write')) {
                                            $proceedKind = 'laboratory';
                                        }
                                        if ($kind === 'radiology' && hms_can($connection, 'radiology.write')) {
                                            $proceedKind = 'radiology';
                                        }
                                        ?>
                                    <tr class="<?php echo $allow ? 'table-success' : ''; ?>">
                                        <td><?php echo hms_h((string) ($ln['description'] ?? '')); ?></td>
                                        <td><span class="badge badge-light border"><?php echo hms_h($kind !== '' ? $kind : '?'); ?></span></td>
                                        <td class="text-right"><?php echo number_format($lineTot, 0, '.', ' '); ?></td>
                                        <td>
                                            <?php echo hms_h($desk); ?> —
                                            <?php if ($allow) { ?>
                                            <strong class="text-success">OK to proceed</strong>
                                            <?php } else { ?>
                                            <strong class="text-danger">Awaiting cashier payment</strong>
                                            <?php } ?>
                                            <?php if ($paid) { ?><span class="badge badge-success ml-1">Paid</span><?php } ?>
                                            <?php
                                            if ($showProceed && $proceedKind !== '') {
                                                $existingId = 0;
                                                if ($proceedKind === 'laboratory' && function_exists('hms_lab_result_find_by_ticket_line')) {
                                                    $existingId = hms_lab_result_find_by_ticket_line($connection, $fid, $tcFull, (int) $lineIdx);
                                                } elseif ($proceedKind === 'radiology' && function_exists('hms_radiology_result_find_by_ticket_line')) {
                                                    $existingId = hms_radiology_result_find_by_ticket_line($connection, $fid, $tcFull, (int) $lineIdx);
                                                }
                                                $target = $proceedKind === 'laboratory'
                                                    ? ('lab-result-workflow.php?id=' . (int) $existingId)
                                                    : ('radiology-result-workflow.php?id=' . (int) $existingId);
                                                if ($existingId < 1) {
                                                    ?>
                                            <form method="post" action="service-proceed.php" class="d-inline-block ml-2">
                                                <?php echo hms_csrf_field(); ?>
                                                <input type="hidden" name="service_proceed" value="1">
                                                <input type="hidden" name="ticket_code" value="<?php echo hms_h($tcFull); ?>">
                                                <input type="hidden" name="line_idx" value="<?php echo (int) $lineIdx; ?>">
                                                <input type="hidden" name="portal" value="<?php echo hms_h((string) $portalNorm); ?>">
                                                <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Proceed</button>
                                            </form>
                                                    <?php
                                                } else {
                                                    ?>
                                            <a class="btn btn-sm btn-outline-primary font-weight-bold ml-2" href="<?php echo hms_h($target); ?>">Open workspace</a>
                                                    <?php
                                                }
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mb-0 mt-3">If the ticket status is <strong>pending</strong>, there is still an unpaid balance on this code. <strong>Proceed</strong> opens the structured lab or radiology result workspace (migration <code>024_lab_radiology_result_workflow.sql</code>).</p>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
