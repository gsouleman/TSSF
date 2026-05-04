<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/insurance_catalog.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$canOpen = (string) ($_SESSION['role'] ?? '') === '1' || hms_can($connection, 'billing.read');
if (!$canOpen) {
    http_response_code(403);
    exit('Forbidden.');
}

$fid = hms_current_facility_id();

// Handle ACH / X12 Export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_x12'])) {
    $x12Payload = "ISA*00*          *00*          *ZZ*SUBMITTER      *ZZ*RECEIVER       *260418*1005*^*00501*000000001*0*T*:~
GS*HC*SUBMITTER*RECEIVER*20260418*1005*1*X*005010X222A1~
ST*837*0001*005010X222A1~
BHT*0019*00*0123*20260418*1005*CH~
NM1*41*2*SOLIDARITY OF HEARTS*****46*123456789~
PER*IC*JOHN DOE*TE*5551234567~
NM1*40*2*PAYER A*****46*987654321~
HL*1**20*1~
PRV*BI*PXC*203BF0100Y~
NM1*85*2*SOLIDARITY HOSPITAL*****XX*1987654321~
N3*123 MAIN ST~
N4*CITY*CA*90210~
REF*EI*123456789~
HL*2*1*22*0~
SBR*P*18*******11~
NM1*IL*1*SMITH*JOHN****MI*12345678901~
N3*456 ELM ST~
N4*OTHERCITY*CA*90211~
DMG*D8*19800101*M~
CLM*0123*200.50***11:B:1*Y*A*Y*I~
HI*BK:J0190~
LX*1~
SV1*HC:99213*200.50*UN*1***1~
DTP*472*D8*20260418~
SE*24*0001~
GE*1*1~
IEA*1*000000001~";

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="claims_batch_' . date('Ymd_His') . '.x12"');
    echo $x12Payload;
    exit;
}

// Generate Mock Data for Scrubber
$mockClaims = [
    ['id' => 'CLM-001', 'patient' => 'Alice Ndongo', 'insurer' => 'Sunu Assurances', 'diagnosis' => 'J01.90 - Acute sinusitis', 'amount' => 45000, 'status' => 'Clean', 'errors' => []],
    ['id' => 'CLM-002', 'patient' => 'Jean Baptiste', 'insurer' => 'Ascoma', 'diagnosis' => 'None', 'amount' => 12500, 'status' => 'Rejected', 'errors' => ['Missing ICD-10 Code', 'Policy expired']],
    ['id' => 'CLM-003', 'patient' => 'Sarah Eyenga', 'insurer' => 'Zenithe Insurance', 'diagnosis' => 'M54.5 - Low back pain', 'amount' => 110000, 'status' => 'Clean', 'errors' => []],
    ['id' => 'CLM-004', 'patient' => 'Paul Biya (Demo)', 'insurer' => 'Garantie Mutuelle', 'diagnosis' => 'I10 - Essential hypertension', 'amount' => 17000, 'status' => 'Warning', 'errors' => ['Service code mismatch for Age group']],
];

include 'header.php';
?>
<style>
.scrubber-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border-radius: 12px;
}
.scrubber-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.08) !important;
}
.pulse-indicator {
    display: inline-block;
    width: 10px; height: 10px;
    border-radius: 50%;
    background-color: #28a745;
    box-shadow: 0 0 0 rgba(40, 167, 69, 0.4);
    animation: pulseObj 2s infinite;
}
@-webkit-keyframes pulseObj {
  0% { -webkit-box-shadow: 0 0 0 0 rgba(40,167,69, 0.4); }
  70% { -webkit-box-shadow: 0 0 0 10px rgba(40,167,69, 0); }
  100% { -webkit-box-shadow: 0 0 0 0 rgba(40,167,69, 0); }
}
@keyframes pulseObj {
  0% { -moz-box-shadow: 0 0 0 0 rgba(40,167,69, 0.4); box-shadow: 0 0 0 0 rgba(40,167,69, 0.4); }
  70% { -moz-box-shadow: 0 0 0 10px rgba(40,167,69, 0); box-shadow: 0 0 0 10px rgba(40,167,69, 0); }
  100% { -moz-box-shadow: 0 0 0 0 rgba(40,167,69, 0); box-shadow: 0 0 0 0 rgba(40,167,69, 0); }
}
</style>
<div class="page-wrapper">
    <div class="content hms-module">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-4 pb-2 border-bottom">
            <div>
                <h1 class="h3 font-weight-bold text-dark mb-1"><i class="fa fa-file-text text-primary mr-2"></i> Advanced Insurance Claims</h1>
                <p class="text-muted small mb-0">Automated Claim Scrubber & X12 / 837 Clearing House Export</p>
            </div>
            <div class="mt-3 mt-md-0 d-flex">
                <span class="badge badge-light border d-flex align-items-center text-muted px-3 py-2 shadow-sm rounded-pill mr-3">
                    <span class="pulse-indicator mr-2"></span> Scrubber Engine Active
                </span>
                <form method="post" class="m-0">
                    <?php echo hms_csrf_field(); ?>
                    <button type="submit" name="export_x12" class="btn btn-primary shadow-sm rounded-pill px-4">
                        <i class="fa fa-cloud-download mr-2"></i> Generate ACH / X12
                    </button>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card border-0 shadow-sm scrubber-card h-100 bg-white">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-success-light text-success p-3 rounded-circle mr-3">
                                <i class="fa fa-check-circle fa-2x"></i>
                            </div>
                            <div>
                                <h6 class="text-muted font-weight-bold text-uppercase mb-0">Clean Claims</h6>
                                <h2 class="font-weight-boldtext-dark mb-0">2</h2>
                            </div>
                        </div>
                        <p class="small text-muted mb-0">Ready for automated dispatch.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card border-0 shadow-sm scrubber-card h-100 bg-white">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-warning-light text-warning p-3 rounded-circle mr-3">
                                <i class="fa fa-exclamation-triangle fa-2x"></i>
                            </div>
                            <div>
                                <h6 class="text-muted font-weight-bold text-uppercase mb-0">Warnings</h6>
                                <h2 class="font-weight-bold text-dark mb-0">1</h2>
                            </div>
                        </div>
                        <p class="small text-muted mb-0">Review required before batching.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card border-0 shadow-sm scrubber-card h-100 bg-white">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-danger-light text-danger p-3 rounded-circle mr-3">
                                <i class="fa fa-times-circle fa-2x"></i>
                            </div>
                            <div>
                                <h6 class="text-muted font-weight-bold text-uppercase mb-0">Rejected internally</h6>
                                <h2 class="font-weight-bold text-dark mb-0">1</h2>
                            </div>
                        </div>
                        <p class="small text-muted mb-0">Scrubber found fatal standard errors.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-dark"><i class="fa fa-list mr-2"></i> Current Batch Queue</h6>
                <div class="input-group input-group-sm w-25">
                    <input type="text" class="form-control" placeholder="Search claims...">
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button"><i class="fa fa-search"></i></button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Claim ID</th>
                                <th>Patient</th>
                                <th>Payer / Insurer</th>
                                <th>ICD Diagnosis</th>
                                <th class="text-right">Billed Amount</th>
                                <th>Scrubber Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mockClaims as $cl) { 
                                $badgeClass = 'badge-success';
                                if ($cl['status'] === 'Rejected') $badgeClass = 'badge-danger';
                                if ($cl['status'] === 'Warning') $badgeClass = 'badge-warning text-dark';
                            ?>
                            <tr>
                                <td class="align-middle font-weight-bold text-muted"><?php echo hms_h($cl['id']); ?></td>
                                <td class="align-middle"><?php echo hms_h($cl['patient']); ?></td>
                                <td class="align-middle"><?php echo hms_h($cl['insurer']); ?></td>
                                <td class="align-middle small"><?php echo hms_h($cl['diagnosis']); ?></td>
                                <td class="align-middle text-right font-weight-bold"><?php echo hms_h(hms_format_xaf($cl['amount'])); ?></td>
                                <td class="align-middle">
                                    <span class="badge <?php echo $badgeClass; ?> px-2 py-1"><?php echo hms_h($cl['status']); ?></span>
                                    <?php if (!empty($cl['errors'])) { ?>
                                        <div class="small text-danger mt-1">
                                            <?php foreach ($cl['errors'] as $e) echo '<div>- '.hms_h($e).'</div>'; ?>
                                        </div>
                                    <?php } ?>
                                </td>
                                <td class="align-middle">
                                    <button class="btn btn-sm btn-outline-info" title="Review Log"><i class="fa fa-eye"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary" title="Edit Claim"><i class="fa fa-pencil"></i></button>
                                </td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
<?php include 'footer.php'; ?>
