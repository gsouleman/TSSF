<?php
declare(strict_types=1);

/**
 * HMS — End-to-end workflow document (PDF via Dompdf).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: ../index.php');
    exit;
}

if (!hms_billing_pdf_available()) {
    http_response_code(503);
    exit('PDF unavailable: run composer install in the hms folder (dompdf).');
}

$generated = date('Y-m-d H:i T');
$phpVer = PHP_VERSION;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HMS — Workflow Document</title>
<style>
body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #0f172a; line-height: 1.45; }
h1 { font-size: 18pt; color: #0c8b8b; margin: 0 0 12px; border-bottom: 2px solid #0c8b8b; padding-bottom: 6px; }
h2 { font-size: 13pt; color: #1e293b; margin: 18px 0 8px; page-break-after: avoid; }
h3 { font-size: 11pt; color: #334155; margin: 12px 0 6px; }
p { margin: 0 0 8px; }
ul, ol { margin: 6px 0 10px 18px; padding: 0; }
li { margin-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; }
th { background: #f1f5f9; font-weight: bold; }
.meta { font-size: 9pt; color: #64748b; margin-bottom: 16px; }
.code { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; background: #f8fafc; padding: 2px 4px; }
.box { background: #f8fafc; border-left: 3px solid #0c8b8b; padding: 10px 12px; margin: 10px 0; font-size: 9.5pt; }
.flow { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; background: #f1f5f9; padding: 10px; white-space: pre-wrap; margin: 10px 0; }
.page-break { page-break-before: always; }
.small { font-size: 9pt; color: #475569; }
</style>
</head>
<body>

<h1>Hospital Management System (HMS)</h1>
<p class="meta"><strong>Workflow Document</strong><br>
Version 1.0 · Generated at 

HTML;
$html .= htmlspecialchars($generated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html .= ' · PHP ';
$html .= htmlspecialchars($phpVer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html .= <<<'HTML'

</p>

<p>This document describes typical <strong>end-to-end workflows</strong> in HMS: how patients move through registration, OPD, consultation, billing, diagnostics, pharmacy, and follow-up. Exact screens depend on which SQL migrations are applied and role permissions.</p>

<h2>1. Purpose &amp; scope</h2>
<p>The application supports <strong>multi-facility</strong> hospitals. Most steps are <strong>facility-scoped</strong> (current site from session). Workflows below assume core migrations (e.g. patients, consultations, billing, OPD) are installed.</p>

<h2>2. High-level patient journey</h2>
<div class="flow">Patient identity → Registration / MPI
       ↓
   OPD visit &amp; triage (optional vitals)
       ↓
   Consultation (clinical note, prescribed lab/radiology/pharmacy lines)
       ↓
   Cashier — payment ticket / code (full or partial pay per line)
       ↓
   Laboratory / Radiology — verify code → result entry → finalize
       ↓
   Pharmacy — dispense (optional inventory deduction)
       ↓
   Chart &amp; portal — results/notices, external documents if care outside hospital</div>

<h2>3. Registration &amp; master patient index</h2>
<ul>
<li><strong>Register or find patient:</strong> <span class="code">patients.php</span>, <span class="code">add-patient.php</span>, <span class="code">edit-patient.php</span>.</li>
<li><strong>Duplicates:</strong> optional MPI merge queue when configured (<span class="code">mpi-workqueue.php</span>).</li>
<li><strong>Identifiers &amp; consents:</strong> may be captured per local policy (consents module when present).</li>
</ul>

<div class="page-break"></div>
<h2>4. OPD queue &amp; vitals</h2>
<ul>
<li><strong>OPD visit:</strong> check-in and queue status (<span class="code">opd-queue.php</span>, <span class="code">visits.php</span>).</li>
<li><strong>Vitals:</strong> front desk or nursing (<span class="code">vitals-enter.php</span>) and/or from clinical chart; consultation can pre-fill latest vitals when migrations support recorder metadata.</li>
</ul>

<h2>5. Consultation workflow</h2>
<ul>
<li><strong>Create / open consultation:</strong> <span class="code">consultation-new.php</span> — chief complaint, status, clinician.</li>
<li><strong>Prescribed services:</strong> laboratory and radiology lines chosen from <strong>service catalog</strong>; lines may be marked <em>external</em> (patient obtains service outside — typically no in-hospital charge for that line).</li>
<li><strong>Billing exception:</strong> supervisors with <span class="code">consult.billing_override</span> may approve fee waiver/deferral per policy.</li>
<li><strong>Completion &amp; payment code:</strong> when configured, completing a consultation can generate a <strong>payment ticket</strong> bundling consultation fee plus unpaid prescribed catalog lines (<span class="code">cashier.php</span> loads by code).</li>
</ul>

<h2>6. Cashier &amp; payment ticket</h2>
<ul>
<li><strong>Payment code:</strong> patient presents code from receipt or ticket; <span class="code">cashier.php</span> loads the basket.</li>
<li><strong>Partial payment:</strong> patient may pay only selected lines now and return later with the <strong>same code</strong> for remaining lines.</li>
<li><strong>Receipts / fiscal documents:</strong> receipts and company invoices follow <span class="code">billing-document-pdf.php</span> and related tables when migration <code>011</code>+ is applied.</li>
<li><strong>Insurance split:</strong> primary policy <strong>insurer covered %</strong> on <span class="code">patient-chart.php</span> drives patient co-pay at POS on <strong>listed</strong> amounts (0–100% flexible).</li>
<li><strong>External lines:</strong> no hospital charge when marked external; upload reports later under external documents.</li>
</ul>

<div class="page-break"></div>
<h2>7. Laboratory workflow</h2>
<ol>
<li>Staff opens <span class="code">service-code-verify.php?portal=laboratory</span> (or from <span class="code">portal-laboratory.php</span>) and enters the cashier code.</li>
<li><strong>Proceed</strong> on paid (or waived) laboratory lines opens <span class="code">lab-result-workflow.php</span> with structured template.</li>
<li>Save draft → <strong>Finalize</strong> sets result status and can notify <strong>patient portal</strong> and <strong>referring doctor</strong> (when configured).</li>
<li><strong>Clinical chart</strong> and <strong>PDF result report</strong> link from chart / <span class="code">clinical-result-report.php</span>.</li>
</ol>

<h2>8. Radiology workflow</h2>
<ol>
<li>Same pattern as lab: <span class="code">service-code-verify.php?portal=radiology</span> → <span class="code">radiology-result-workflow.php</span>.</li>
<li>Finalize publishes notices and PDF similarly.</li>
</ol>

<h2>9. Pharmacy &amp; inventory</h2>
<ul>
<li><strong>Prescriptions:</strong> <span class="code">prescriptions.php</span>, <span class="code">prescription.php</span> — medication lines, dispense status.</li>
<li><strong>Dispense:</strong> pharmacy role posts dispense; may deduct <span class="code">tbl_inventory_item</span> when inventory is enabled.</li>
<li><strong>Stock:</strong> <span class="code">inventory.php</span> — receive stock, adjustments, movements, low-stock alerts, PDF reports.</li>
</ul>

<h2>10. Clinical chart</h2>
<ul>
<li><span class="code">patient-chart.php</span> aggregates allergies, medications, vitals, consultations, prescribed tests, lab/rad results, prescriptions, <strong>insurance</strong> share, and <strong>external documents</strong>.</li>
<li><strong>Station-limited roles</strong> (e.g. front desk, nursing) may see a reduced chart (vitals, consultations, insurance only) per role configuration.</li>
</ul>

<div class="page-break"></div>
<h2>11. External care &amp; documents</h2>
<ul>
<li>When tests or medicines occur outside the hospital, staff may <strong>cancel</strong> a pending cashier line with reason or rely on zero–patient-due tickets.</li>
<li><strong>Uploads:</strong> <span class="code">patient-external-docs.php</span> attaches PDF/images so clinicians see outside reports on the chart.</li>
</ul>

<h2>12. Patient portal (optional)</h2>
<ul>
<li>Separate login: <span class="code">patient-portal-login.php</span> → <span class="code">patient-portal.php</span>.</li>
<li>May show appointments and result notices after workflows finalize (per migration <code>024</code>+).</li>
</ul>

<h2>13. Finance extensions</h2>
<ul>
<li><strong>Credit &amp; receivables:</strong> <span class="code">credit-receivables.php</span>, <span class="code">credit-account.php</span> when migration <code>019</code> is applied.</li>
<li><strong>Expenses:</strong> <span class="code">expense-management.php</span> when migration <code>026</code> is applied.</li>
<li><strong>Transactions ledger:</strong> links to receipts when migration <code>017</code> is applied.</li>
</ul>

<h2>14. Audit &amp; compliance touchpoints</h2>
<ul>
<li><span class="code">audit-log.php</span> — key actions logged via <span class="code">hms_audit_log()</span>.</li>
<li>Role/ACL governs who can open cashier, clinical write, inventory write, etc.</li>
</ul>

<h2>15. Workflow summary table</h2>
<table>
<tr><th>Stage</th><th>Primary actors</th><th>Typical outcome</th></tr>
<tr><td>Registration</td><td>Front desk</td><td>Patient record ready for visit</td></tr>
<tr><td>OPD / vitals</td><td>Nursing / front desk</td><td>Visit active; vitals on chart</td></tr>
<tr><td>Consultation</td><td>Clinician</td><td>Orders + optional payment code</td></tr>
<tr><td>Cashier</td><td>Cashier</td><td>Receipt; code remains for partial pay</td></tr>
<tr><td>Lab / imaging</td><td>Tech / radiologist</td><td>Finalized result + notices</td></tr>
<tr><td>Pharmacy</td><td>Pharmacist</td><td>Dispensed; stock updated if used</td></tr>
<tr><td>Follow-up</td><td>All</td><td>Chart complete; portal optional</td></tr>
</table>

<p class="small"><strong>Note:</strong> Optional modules and migrations alter available steps. See <span class="code">platform-overview.php</span> for migration order.</p>

</body>
</html>
HTML;

$pdf = hms_billing_html_to_pdf_bytes($html);
if ($pdf === false) {
    http_response_code(500);
    exit('PDF render failed.');
}

$fname = 'HMS_Workflow_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
header('Content-Length: ' . (string) strlen($pdf));
echo $pdf;
