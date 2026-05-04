<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_portal($connection, 'cashier');
include 'header.php';

$fid = hms_current_facility_id();
$ticketOk = hms_payment_ticket_tables_ok($connection);
$pend = 0;
$paidToday = 0;
$todayEsc = mysqli_real_escape_string($connection, date('Y-m-d'));
if ($ticketOk) {
    $q = mysqli_query($connection, 'SELECT COUNT(*) AS c FROM tbl_payment_ticket WHERE facility_id=' . (int) $fid . " AND status='pending'");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $pend = (int) ($r['c'] ?? 0);
    }
    $q2 = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c FROM tbl_payment_ticket WHERE facility_id=' . (int) $fid
        . " AND status='paid' AND DATE(paid_at)='" . $todayEsc . "'"
    );
    if ($q2 && $r2 = mysqli_fetch_assoc($q2)) {
        $paidToday = (int) ($r2['c'] ?? 0);
    }
}
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#0c8b8b 0%,#0f766e 100%);color:#fff;">
            <div class="card-body py-4 px-4 d-flex align-items-center justify-content-between flex-wrap">
                <div>
                    <h1 class="h4 mb-1 font-weight-bold" style="color:#fff;">Cashier portal</h1>
                    <p class="mb-0 small" style="color:rgba(255,255,255,.88);">Collect payments using patient payment codes and issue receipts or invoices.</p>
                </div>
                <div class="mt-2 mt-md-0">
                    <a href="cashier.php" class="btn btn-light btn-sm font-weight-bold" style="color:#0c8b8b;"><i class="fa fa-money mr-1"></i> Open cashier desk</a>
                    <a href="receipts-invoices.php" class="btn btn-outline-light btn-sm font-weight-bold"><i class="fa fa-file-text-o mr-1"></i> Receipts</a>
                </div>
            </div>
        </div>

        <?php if (!$ticketOk) { ?>
        <div class="alert alert-warning border-0 shadow-sm">Run <code>hms/database/migrations/023_payment_ticket_cashier.sql</code> to enable payment tickets.</div>
        <?php } else { ?>
        <div class="row mb-4">
            <div class="col-6 col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Pending codes</div>
                        <div class="h3 font-weight-bold mb-0" style="color:#1b2559"><?php echo $pend; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 mb-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Paid today</div>
                        <div class="h3 font-weight-bold mb-0" style="color:#1b2559"><?php echo $paidToday; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="font-weight-bold mb-2" style="color:#1b2559">Workflow</h5>
                <ol class="mb-0 pl-3 text-muted small">
                    <li class="mb-2">Collect the <strong>consultation fee</strong> at the cashier after registration or during the visit (use &ldquo;Consultation fee — pay before visit&rdquo; on <a href="cashier.php">Cashier desk</a>). A <strong>payment code</strong> is issued to the patient.</li>
                    <li class="mb-2">The clinician enters that code on the consultation screen so the visit can proceed (or use Emergency / hospital waiver when allowed).</li>
                    <li class="mb-2">For additional services (lab, radiology), use <strong>Collect payment</strong> on pending codes after the doctor prescribes them, or issue further tickets as your process requires.</li>
                    <li class="mb-0">Receipts and invoices print from the cashier workflow; the clinical record is updated when codes are validated.</li>
                </ol>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php include 'footer.php'; ?>
